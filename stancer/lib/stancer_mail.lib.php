<?php
/* Copyright (C) 2023-2026 Eric Seigne <eric.seigne@cap-rel.fr>
 * Copyright (C) 2026		MDW			<mdeweerd@users.noreply.github.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    stancer/lib/stancer_mail.lib.php
 * \ingroup stancer
 * \brief   Email sending functions (notifications, templates, CSV to HTML)
 */

require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
/**
 * Return the mail metadata matching the type of a Dolibarr object
 *
 * Payments can target an invoice, an order, a proposal, a member or a donation
 * (see getObjectFromTag()), so none of these four values may be hardcoded:
 *
 *  - trackidprefix: prefix the Dolibarr email collector decodes to reattach an answer
 *    to its object ('inv' => Facture, 'ord' => Commande, 'pro' => Propal,
 *    'mem' => Adherent, see emailcollector.class.php). A wrong prefix does not fail,
 *    it reattaches the answer to a totally unrelated object carrying the same rowid,
 *    so an empty prefix (no track id at all) is the safe fallback.
 *  - elementtype: value to pass to ActionComm::getActions(). ActionComm::create()
 *    rewrites 'facture' into 'invoice' and 'commande' into 'order' before the INSERT,
 *    so reading the events back requires the rewritten value, not $object->element.
 *  - templatetype: type_template of the email templates handling this object.
 *  - diroutput: directory holding the generated PDF, used for the attachment.
 *
 * @param   CommonObject  $object  Invoice, order, proposal, member or donation
 * @return  array{trackidprefix:string,elementtype:string,templatetype:string,diroutput:string}  Mail metadata for this object type
 */
function stancerGetObjectMailContext($object)
{
	global $conf;

	$element = is_object($object) ? (string) $object->element : '';
	$modconf = null;
	switch ($element) {
		case 'facture':
			$ctx = array('trackidprefix' => 'inv', 'elementtype' => 'invoice', 'templatetype' => 'facture_send');
			$modconf = isset($conf->facture) ? $conf->facture : null;
			break;
		case 'commande':
			$ctx = array('trackidprefix' => 'ord', 'elementtype' => 'order', 'templatetype' => 'order_send');
			$modconf = isset($conf->commande) ? $conf->commande : null;
			break;
		case 'propal':
			$ctx = array('trackidprefix' => 'pro', 'elementtype' => 'propal', 'templatetype' => 'propal_send');
			$modconf = isset($conf->propal) ? $conf->propal : null;
			break;
		case 'member':
			$ctx = array('trackidprefix' => 'mem', 'elementtype' => 'member', 'templatetype' => 'member');
			$modconf = isset($conf->adherent) ? $conf->adherent : null;
			break;
		case 'don':
			// The email collector decodes no prefix for donations: emitting none is safer
			// than emitting one it would resolve to another object type.
			$ctx = array('trackidprefix' => '', 'elementtype' => 'don', 'templatetype' => '');
			$modconf = isset($conf->don) ? $conf->don : null;
			break;
		default:
			dol_syslog("stancerGetObjectMailContext unsupported element '" . $element . "': no track id, no template type and no attachment for this mail", LOG_WARNING);
			return array('trackidprefix' => '', 'elementtype' => $element, 'templatetype' => '', 'diroutput' => '');
	}

	$entity = (is_object($object) && !empty($object->entity)) ? (int) $object->entity : (int) $conf->entity;
	$ctx['diroutput'] = '';
	if (!empty($modconf->multidir_output[$entity])) {
		$ctx['diroutput'] = $modconf->multidir_output[$entity];
	} elseif (!empty($modconf->dir_output)) {
		$ctx['diroutput'] = $modconf->dir_output;
	} else {
		dol_syslog("stancerGetObjectMailContext no output directory for element '" . $element . "' (module disabled?), no document will be attached", LOG_WARNING);
	}

	return $ctx;
}

/**
 * Resolve an email template by label, trying each given template type in order
 *
 * The setup page only lists the templates of one type per setting (order_send for the
 * CB order mail, facture_send for the invoice ones), so a label configured before the
 * object type was taken into account only exists under that legacy type. The type
 * matching the object is therefore tried first, the legacy one second, which keeps
 * existing setups working while letting an integrator create a properly typed template.
 *
 * @param   FormMail   $formmail       Form handler used to query the template table
 * @param   string     $modele         Template label to look for
 * @param   Translate  $outputlangs    Language the template must be loaded in
 * @param   array      $templatetypes  type_template values to try, most specific first
 * @param   string     $caller         Calling function name, for the logs
 * @return  object|null                Template found (ModelMail), null when none matches
 */
function stancerGetMailTemplate($formmail, $modele, $outputlangs, $templatetypes, $caller)
{
	global $db, $user;

	if (empty($modele)) {
		dol_syslog($caller . " modele is empty, getEMailTemplate skipped, mail will be sent with EMPTY subject/body", LOG_WARNING);
		return null;
	}

	$tried = array();
	foreach ($templatetypes as $templatetype) {
		if (empty($templatetype) || in_array($templatetype, $tried)) {
			continue;
		}
		$tried[] = $templatetype;

		$tpl = $formmail->getEMailTemplate($db, $templatetype, $user, $outputlangs, -2, 1, $modele);
		if (is_object($tpl) && $tpl->id > 0) {
			// Trace the EXACT template Dolibarr resolved for the requested label. If the resolved
			// row belongs to another module (eg 'doliscan'), the operator can spot it here without
			// having to query llx_c_email_templates by hand.
			dol_syslog($caller . " template resolved: rowid=" . $tpl->id . ", label='" . ($tpl->label ?? '')
				. "', module='" . ($tpl->module ?? '') . "', lang='" . ($tpl->lang ?? '') . "' (searched label='" . $modele
				. "', searchedLang='" . $outputlangs->defaultlang . "', type_template='" . $templatetype . "')", LOG_INFO);
			return $tpl;
		}

		$errCode = is_int($tpl) ? (' (getEMailTemplate returned ' . $tpl . ')') : '';
		dol_syslog($caller . " template NOT FOUND for label='" . $modele . "' (lang='" . $outputlangs->defaultlang
			. "', type_template='" . $templatetype . "')" . $errCode, LOG_WARNING);
	}

	dol_syslog($caller . " no template matching label='" . $modele . "' in types " . json_encode($tried)
		. " -- mail will be sent with EMPTY subject/body", LOG_WARNING);
	return null;
}

/**
 * Record an agenda event, linked to the given object so it shows on its events tab
 *
 * @param   object   $object              Object the event is attached to (fk_element + elementtype)
 * @param   string   $actioncode          Event code, also used as deduplication key
 * @param   string   $label               Event label
 * @param   string   $description         Event description
 * @param   array    $postactionmessages  Messages to append to the description
 * @param   string   $extraparams         Extra parameters stored on the event, truncated to 250 chars
 * @param   bool     $isEmail             Flag the event as an outgoing email
 * @return  void
 */
function stancerAddActionComm($object, $actioncode, $label, $description, $postactionmessages, $extraparams, $isEmail = false)
{
	global $db, $user;
	dol_syslog("stancer * stancerAddActionComm Record event for payment result - " . $description);
	$now = dol_now();
	// Insert record of payment (success or error)
	$actioncomm = new ActionComm($db);

	$actioncomm->type_code    = 'AC_OTH_AUTO';		// Type of event ('AC_OTH', 'AC_OTH_AUTO', 'AC_XXX'...)
	// For real email sending, use the registered core code AC_EMAIL (DOLIBARR_MAIL.md).
	// Otherwise keep a module-prefixed custom code (used for deduplication via getActions).
	$actioncomm->code         = $isEmail ? 'AC_EMAIL' : 'AC_' . $actioncode;
	$actioncomm->label        = $label;
	$actioncomm->note_private = implode(",\n", $postactionmessages);
	$actioncomm->fk_project   = $object->fk_project;
	$actioncomm->datep        = $now;
	$actioncomm->datef        = $now;
	$actioncomm->percentage   = -1;   // Not applicable
	$actioncomm->socid        = $object->socid;
	$actioncomm->contactid    = 0;
	$actioncomm->authorid     = $user->id;   // User saving action
	$actioncomm->userownerid  = $user->id;	// Owner of action
	$actioncomm->note_private = $description;
	// Fields when action is a real email (content is already into note)
	/*$actioncomm->email_msgid = $object->email_msgid;
	 $actioncomm->email_from  = $object->email_from;
	 $actioncomm->email_sender= $object->email_sender;
	 $actioncomm->email_to    = $object->email_to;
	 $actioncomm->email_tocc  = $object->email_tocc;
	 $actioncomm->email_tobcc = $object->email_tobcc;
	 $actioncomm->email_subject = $object->email_subject;
	 $actioncomm->errors_to   = $object->errors_to;*/
	$actioncomm->elementid    = (int) $object->id;
	// @phan-suppress-next-line PhanDeprecatedProperty  ActionComm::create() only reads fk_element up to Dolibarr 18, both fields must be fed
	$actioncomm->fk_element   = (int) $object->id;
	$actioncomm->elementtype  = $object->element;
	$actioncomm->extraparams  = dol_trunc($extraparams, 250);
	$actioncomm->create($user);
}

/**
 * Build an HTML link to a Dolibarr invoice/order card
 *
 * @param   CommonObject  $obj  invoice or order object
 * @return  string              HTML <a> tag with the object ref
 */
function stancerBuildInvoiceLink($obj)
{
	if ($obj->element == 'facture') {
		$url = dol_buildpath('/compta/facture/card.php', 3) . '?id=' . $obj->id;
	} elseif ($obj->element == 'commande') {
		$url = dol_buildpath('/commande/card.php', 3) . '?id=' . $obj->id;
	} else {
		// ->ref is ?string on CommonObject, htmlspecialchars() rejects null since PHP 8.1.
		return htmlspecialchars((string) $obj->ref);
	}
	return '<a href="' . $url . '" style="color:#4169E1;text-decoration:none;font-weight:600;">' . htmlspecialchars((string) $obj->ref) . '</a>';
}

/**
 * Build an HTML link to the Stancer manager payment detail page
 *
 * @param   string  $paymentId      Stancer payment ID
 * @param   string  $displayText    text to display (e.g. payment status)
 * @return  string                  HTML <a> tag
 */
function stancerBuildManagerLink($paymentId, $displayText)
{
	$url = 'https://manage.stancer.com/fr/details-de-paiement?id=' . urlencode($paymentId);
	return '<a href="' . $url . '" style="color:#4169E1;text-decoration:none;">' . htmlspecialchars($displayText) . '</a>';
}

/**
 * send light mail (notifications)
 *
 * @param   string  $to             recipient email
 * @param   string  $subject        email subject
 * @param   string  $message        email body (HTML)
 * @param   bool    $isForCustomer  true if sent to a customer (adds goodbye text)
 * @param   string  $cc             CC email address
 * @param   string  $trackid        tracking id for Dolibarr Email Collector (e.g. 'thi123', 'inv456')
 * @return  void
 */
function stancerSendMail($to, $subject, $message, $isForCustomer = false, $cc = '', $trackid = '')
{
	global $conf, $langs, $mysoc;

	$from = getDolGlobalString('MAIN_MAIL_EMAIL_FROM');
	if (empty(trim((string) $from)) || empty(trim((string) $to))) {
		dol_syslog("stancerSendMail early return, from=$from or to=$to is empty", LOG_DEBUG);
		return;
	}

	$ishtml = 1;

	$decodedSubject = html_entity_decode($subject, ENT_QUOTES | ENT_HTML5, 'UTF-8');
	$realsubject = '[' . $mysoc->name . '] ' . $decodedSubject;

	$companyName = htmlspecialchars($mysoc->name);
	$hello = $langs->trans('StancerMailHello');
	$goodbye = '';
	if (!$isForCustomer) {
		$goodbye = '<p style="margin:0;font-size:14px;color:#4a5568;">' . $langs->transnoentitiesnoconv('StancerMailGoodBye') . '</p>';
	}
	$sendBy = $langs->trans('StancerMailSendBy');
	$safeSubject = htmlspecialchars($decodedSubject, ENT_QUOTES, 'UTF-8');

	$realmessage = '<!DOCTYPE html>
<html lang="fr">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"></head>
<body style="margin:0;padding:0;background-color:#eef2f7;font-family:Arial,Helvetica,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#eef2f7;">
<tr><td align="center" style="padding:30px 10px;">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="background-color:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.08);">
  <!-- Header -->
  <tr><td style="background-color:#4169E1;padding:24px 32px;">
    <h1 style="margin:0;font-size:20px;color:#ffffff;font-weight:600;">' . $companyName . '</h1>
  </td></tr>
  <!-- Subject bar -->
  <tr><td style="background-color:#5B8DEF;padding:12px 32px;">
    <p style="margin:0;font-size:15px;color:#ffffff;font-weight:500;">' . $safeSubject . '</p>
  </td></tr>
  <!-- Body -->
  <tr><td style="padding:32px;">
    <p style="margin:0 0 16px 0;font-size:15px;color:#2d3748;">' . $hello . '</p>
    <div style="font-size:14px;color:#4a5568;line-height:1.6;">' . $message . '</div>
    ' . ($goodbye ? '<div style="margin-top:24px;">' . $goodbye . '</div>' : '') . '
  </td></tr>
  <!-- Footer -->
  <tr><td style="background-color:#eef2f7;padding:20px 32px;border-top:1px solid #d6e0f0;">
    <p style="margin:0;font-size:12px;color:#8899b0;text-align:center;">' . $sendBy . '</p>
  </td></tr>
</table>
</td></tr>
</table>
</body>
</html>';

	$moreinheader = 'X-Dolibarr-Info: stancerSendMail' . "\r\n";
	$mailfile = new CMailFile($realsubject, $to, $from, $realmessage, array(), array(), array(), $cc, '', 0, $ishtml, '', '', $trackid, $moreinheader);

	$result = $mailfile->sendfile();
	if ($result) {
		dol_syslog("stancerSendMail sent to " . $to, LOG_DEBUG);
	} else {
		dol_syslog("stancerSendMail Failed to send EMail to " . $to, LOG_ERR);
	}
}


/**
 * send mail with invoice ($object) attached
 *
 * @param   string  $modele  	mail model to use
 * @param   CommonObject  $object  	invoice
 * @param   string  $actionCode	actionComm code to use
 * @param   int  $forceMail	send mail even if actioncomm exists for that code
 * @param   bool  $wrapInLayout	wrap email content in the styled blue header layout
 * @param   string  $extraCc	additional CC email address (appended to thirdparty CC)
 *
 * @return  int|null      1 on success, -1 on error, 0 if skipped by dedup, null when from/to is empty
 */
function stancerSendInvoiceMailModele($modele, $object, $actionCode = "", $forceMail = 0, $wrapInLayout = false, $extraCc = '')
{
	global $db, $conf, $langs, $user, $mysoc;
	// The signature stays generic (callers hold a CommonObject reference), but every caller
	// passes an invoice: getIdBillingContact() and ->socid are read below and only Facture
	// declares them.
	'@phan-var Facture $object';
	// Identify the caller so we can trace which code path triggered this send when reviewing logs.
	$bt = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
	$caller = isset($bt[1]) ? (dol_basename((string) ($bt[1]['file'] ?? '?')) . ':' . ($bt[1]['line'] ?? '?')) : '?';
	$objRef = is_object($object) ? ($object->ref ?? '?') : '?';
	$objId = is_object($object) ? ($object->id ?? '?') : '?';
	$objSocid = is_object($object) ? ($object->socid ?? '?') : '?';
	dol_syslog("stancerSendInvoiceMailModele caller=$caller, modele='$modele', actionCode=$actionCode, forceMail=$forceMail, invoice=$objRef (id=$objId), socid=$objSocid", LOG_INFO);
	$result = 0;
	$subject = $msg = "";
	// Initialised up front: both are only written inside conditional branches,
	// so a static analyser cannot tell they are always set before being read.
	$error = '';
	$postactionmessages = array();
	// Track id, ActionComm elementtype, template type and document directory all depend
	// on the type of $object: never hardcode them, see stancerGetObjectMailContext().
	$mailctx = stancerGetObjectMailContext($object);

	if ($forceMail == 0 && !empty($actionCode)) {
		// Dedup: avoid re-sending the same notification for the same invoice. Two storage
		// shapes must be matched here:
		//   1. legacy rows where code='AC_<actionCode>' (created when isEmail=false)
		//   2. new rows where code='AC_EMAIL' (the registered Dolibarr code for real emails,
		//      see stancerAddActionComm) and the module-specific actionCode is recorded in
		//      extraparams. Without the extraparams clause we would either miss the dedup
		//      (current bug, two mails per invoice) OR conflate unrelated emails on the
		//      same invoice.
		$dedupCode = 'AC_' . $actionCode;
		$dedupCodeEsc = $db->escape($dedupCode);
		$filterDedup = " AND ((code='" . $dedupCodeEsc . "') OR (code='AC_EMAIL' AND extraparams='" . $dedupCodeEsc . "'))";
		$actioncomm = new ActionComm($db);
		// The module requires Dolibarr 15 minimum, where getActions() no longer takes the
		// database handle as first argument: the legacy branch was dead code.
		$resAC = $actioncomm->getActions($object->socid, $object->id, $mailctx['elementtype'], $filterDedup);
		if (!empty($resAC)) {
			dol_syslog("stancerSendInvoiceMailModele modele=$modele already sent (dedup matched on $dedupCode for " . $mailctx['elementtype'] . " id=" . ($object->id ?? '?') . ")", LOG_INFO);
			return $result;
		}
	}

	// Set output language
	$outputlangs = new Translate('', $conf);
	$outputlangs->setDefaultLang(empty($object->thirdparty->default_lang) ? $mysoc->default_lang : $object->thirdparty->default_lang);
	$outputlangs->loadLangs(array("main", "members", "bills"));

	$from = getDolGlobalString('MAIN_MAIL_EMAIL_FROM');

	//destinataire -> contact facturation de la société et à défaut adresse mail de la société
	$facturationID = $object->getIdBillingContact();
	$to = '';
	if (!empty($facturationID)) {
		dol_syslog("stancerSendInvoiceMailModele résultat de  getIdBillingContact : " . json_encode($facturationID), LOG_DEBUG);
		foreach ($facturationID as $cfid) {
			$contactFacturation = new Contact($db);
			$contactresult = $contactFacturation->fetch($cfid);
			if ($contactresult) {
				if ($contactFacturation->email != '') {
					if ($to != '') {
						$to  .= ", ";
					}
					$to .= $contactFacturation->email;
				}
				dol_syslog("stancerSendInvoiceMailModele utilisation du contact facturation, destinataire (id = $cfid) email = $to", LOG_DEBUG);
			}
		}
	}
	if (empty($object->thirdparty)) {
		$societe = new Societe($db);
		$socresult = $societe->fetch($object->socid);
		if ($socresult) {
			$object->thirdparty = $societe;
		}
	}
	if (empty($to)) {
		$to = $object->thirdparty->email;
		dol_syslog("stancerSendInvoiceMailModele utilisation de l'adresse mail societe, destinataire = $to", LOG_DEBUG);
	}

	if (empty(trim((string) $from)) || empty(trim((string) $to))) {
		// print json_encode($object);
		dol_syslog("stancerSendInvoiceMailModele early return, from=$from or to=$to is empty", LOG_DEBUG);
		return; // returns null: an empty from/to is a skip, distinct from dedup (0)
	}

	// Get email content from templae
	$formmail = new FormMail($db);
	//getEMailTemplate($dbs, $type_template, $user, $outputlangs, $id = 0, $active = 1, $label = '', $defaultfortype = -1)
	$arraydefaultmessage = stancerGetMailTemplate($formmail, $modele, $outputlangs, array($mailctx['templatetype'], 'facture_send'), 'stancerSendInvoiceMailModele');

	if (is_object($arraydefaultmessage)) {
		$subject = $arraydefaultmessage->topic;
		$msg     = $arraydefaultmessage->content;
	}

	$substitutionarray = getCommonSubstitutionArray($outputlangs, 0, null, $object);

	// $substitutionarray['__SELLYOURSAAS_PAYMENT_ERROR_DESC__']=$stripefailurecode.' '.$stripefailuremessage;

	complete_substitutions_array($substitutionarray, $outputlangs, $object);

	$subjecttosend = make_substitutions($subject, $substitutionarray, $outputlangs);
	$texttosend = make_substitutions($msg, $substitutionarray, $outputlangs);

	dol_syslog('stancerSendInvoiceMailModele DIRECTDOWNLOAD_URL_INVOICE=' . $substitutionarray['__DIRECTDOWNLOAD_URL_INVOICE__']);
	dol_syslog('stancerSendInvoiceMailModele SUBJECT=' . $subjecttosend);
	// dol_syslog('stancerSendInvoiceMailModele MESSAGE='.$texttosend);

	// Fichier joint
	stancerRegeneratePDFifNeeded($object);
	$file = '';
	$listofpaths = array();
	$listofnames = array();
	$listofmimes = array();
	if (is_object($object) && !empty($mailctx['diroutput'])) {
		// ->ref is not typed on CommonObject: cast it before preg_quote(), which
		// rejects null since PHP 8.1.
		$objectRef = (string) ($object->ref ?? '');
		// dol_most_recent_file() returns null when the directory holds no matching file,
		// hence the is_array() guard before reading 'fullname'.
		$fileparams = dol_most_recent_file($mailctx['diroutput'] . '/' . $objectRef, preg_quote($objectRef, '/') . '.*.pdf');

		$file = (is_array($fileparams) && !empty($fileparams['fullname'])) ? $fileparams['fullname'] : '';

		if ($file) {
			$listofpaths = array($file);
			$listofnames = array(dol_basename($file));
			$listofmimes = array(dol_mimetype($file));
		} else {
			dol_syslog('stancerSendInvoiceMailModele no pdf found in ' . $mailctx['diroutput'] . '/' . $object->ref . ', mail will be sent without attachment', LOG_WARNING);
		}
	}
	dol_syslog('stancerSendInvoiceMailModele fichier(s) joint(s) : ' . json_encode($listofpaths));

	$trackid = empty($mailctx['trackidprefix']) ? '' : $mailctx['trackidprefix'] . $object->id;
	if (empty($trackid)) {
		dol_syslog('stancerSendInvoiceMailModele no track id for element ' . (is_object($object) ? $object->element : '?') . ', answers to this mail will not be reattached by the email collector', LOG_INFO);
	}
	$moreinheader = 'X-Dolibarr-Info: stancerSendInvoiceMailModele' . "\r\n";
	$addr_cc = '';
	if (!empty($object->thirdparty->array_options['options_emailccinvoice'])) {
		$addr_cc = $object->thirdparty->array_options['options_emailccinvoice'];
	}
	if (!empty($extraCc)) {
		$addr_cc = $addr_cc ? $addr_cc . ', ' . $extraCc : $extraCc;
	}

	// Wrap email content in the styled layout (blue border header)
	if ($wrapInLayout) {
		$companyName = htmlspecialchars($mysoc->name);
		$hello = $langs->trans('StancerMailHello');
		$sendBy = $langs->trans('StancerMailSendBy');
		$safeSubject = htmlspecialchars(html_entity_decode($subjecttosend, ENT_QUOTES | ENT_HTML5, 'UTF-8'), ENT_QUOTES, 'UTF-8');

		$texttosend = '<!DOCTYPE html>
<html lang="fr">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"></head>
<body style="margin:0;padding:0;background-color:#eef2f7;font-family:Arial,Helvetica,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#eef2f7;">
<tr><td align="center" style="padding:30px 10px;">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="background-color:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.08);">
  <!-- Header -->
  <tr><td style="background-color:#4169E1;padding:24px 32px;">
    <h1 style="margin:0;font-size:20px;color:#ffffff;font-weight:600;">' . $companyName . '</h1>
  </td></tr>
  <!-- Subject bar -->
  <tr><td style="background-color:#5B8DEF;padding:12px 32px;">
    <p style="margin:0;font-size:15px;color:#ffffff;font-weight:500;">' . $safeSubject . '</p>
  </td></tr>
  <!-- Body -->
  <tr><td style="padding:32px;">
    <p style="margin:0 0 16px 0;font-size:15px;color:#2d3748;">' . $hello . '</p>
    <div style="font-size:14px;color:#4a5568;line-height:1.6;">' . $texttosend . '</div>
  </td></tr>
  <!-- Footer -->
  <tr><td style="background-color:#eef2f7;padding:20px 32px;border-top:1px solid #d6e0f0;">
    <p style="margin:0;font-size:12px;color:#8899b0;text-align:center;">' . $sendBy . '</p>
  </td></tr>
</table>
</td></tr>
</table>
</body>
</html>';
	}

	// Send email (substitutionarray must be done just before this)
	$mailfile = new CMailFile($subjecttosend, $to, $from, $texttosend, $listofpaths, $listofmimes, $listofnames, $addr_cc, '', 0, -1, '', '', $trackid, $moreinheader);
	if ($mailfile->sendfile()) {
		$result = 1;
	} else {
		$error = $langs->trans("ErrorFailedToSendMail", $from, $to) . '. ' . $mailfile->error;
		dol_syslog('stancerSendInvoiceMailModele Error : ' . $mailfile->error, LOG_ERR);
		$result = -1;
	}

	if ($result < 0) {
		$errmsg = $error;
		$postactionmessages[] = $errmsg;
		$ispostactionok = -1;
	} else {
		if ($file) {
			$postactionmessages[] = 'Email sent to thirdparty (to ' . $to . ' with invoice document attached: ' . $file . ', language = ' . $outputlangs->defaultlang . ')';
		} else {
			$postactionmessages[] = 'Email sent to thirdparty (to ' . $to . ' without any attached document, language = ' . $outputlangs->defaultlang . ')';
		}

		// Pass 'AC_<actionCode>' as extraparams so the dedup filter at the top of this function
		// (and in any future call) can match this email even though the row is stored with the
		// standard 'AC_EMAIL' code.
		stancerAddActionComm($object, $actionCode, $subjecttosend, $texttosend, $postactionmessages, 'AC_' . $actionCode, true);
	}


	dol_syslog("stancerSendInvoiceMailModele ends, return $result", LOG_DEBUG);
	return $result;
}


/**
 * send mail with the paid object ($object) attached
 *
 * Historically written for orders, this function is called by the CB payment start with
 * whatever getObjectFromTag() returned: an invoice, an order, a proposal, a member or a
 * donation. Everything that depends on that type comes from stancerGetObjectMailContext().
 *
 * @param   string  		$modele  	mail model to use
 * @param   CommonObject	$object  	object being paid (invoice, order, proposal, member, donation)
 * @param   string  		$actionCode	actionComm code to use
 * @param   int  			$forceMail	send mail even if actioncomm exists for that code
 * @param   string  		$to			target mail addr
 * @return  int|null				1 if the mail was sent, -1 on error, 0 on dedup, null when from/to is empty
 */
function stancerSendOrderMailModele($modele, $object, $actionCode = "", $forceMail = 0, $to = '')
{
	global $db, $conf, $langs, $user, $mysoc;
	// The signature stays generic (the payment pages only hold a CommonObject reference), but
	// getObjectFromTag() can only return one of these five concrete classes, and all of them
	// declare the ->socid read below.
	'@phan-var Facture|Commande|Propal|Adherent|Don $object';
	dol_syslog("stancerSendOrderMailModele modele=$modele, actionCode=$actionCode, forceMail=$forceMail, element=" . (is_object($object) ? $object->element : '?') . ", id=" . (is_object($object) ? $object->id : '?'), LOG_DEBUG);
	$result = 0;
	$subject = $msg = "";
	// Initialised up front: both are only written inside conditional branches,
	// so a static analyser cannot tell they are always set before being read.
	$error = '';
	$postactionmessages = array();
	// Track id, ActionComm elementtype, template type and document directory all depend
	// on the type of $object: never hardcode them, see stancerGetObjectMailContext().
	$mailctx = stancerGetObjectMailContext($object);

	if ($forceMail == 0 && !empty($actionCode)) {
		// Dedup: match both legacy rows (code='AC_<actionCode>') and new rows where the email
		// is stored with the standard 'AC_EMAIL' code plus the module-specific actionCode in
		// extraparams. See the matching block in stancerSendInvoiceMailModele.
		$dedupCode = 'AC_' . $actionCode;
		$dedupCodeEsc = $db->escape($dedupCode);
		$filterDedup = " AND ((code='" . $dedupCodeEsc . "') OR (code='AC_EMAIL' AND extraparams='" . $dedupCodeEsc . "'))";
		$actioncomm = new ActionComm($db);
		// The module requires Dolibarr 15 minimum, where getActions() no longer takes the
		// database handle as first argument: the legacy branch was dead code.
		$resAC = $actioncomm->getActions($object->socid, $object->id, $mailctx['elementtype'], $filterDedup);
		if (!empty($resAC)) {
			dol_syslog("stancerSendOrderMailModele modele=$modele already sent (dedup matched on $dedupCode for " . $mailctx['elementtype'] . " id=" . ($object->id ?? '?') . ")", LOG_INFO);
			return $result;
		}
	}

	// The thirdparty drives both the output language and the fallback recipient, and it is
	// not always loaded by the caller (payment pages fetch the object with a bare fetch()).
	if (empty($object->thirdparty) && is_object($object) && method_exists($object, 'fetch_thirdparty')) {
		if ($object->fetch_thirdparty() <= 0) {
			dol_syslog("stancerSendOrderMailModele no thirdparty attached to " . $object->element . " id=" . $object->id . ", falling back on the default language", LOG_WARNING);
		}
	}

	// Set output language
	$outputlangs = new Translate('', $conf);
	$outputlangs->setDefaultLang(empty($object->thirdparty->default_lang) ? $mysoc->default_lang : $object->thirdparty->default_lang);
	$outputlangs->loadLangs(array("main", "members", "bills", "orders"));

	$from = getDolGlobalString('MAIN_MAIL_EMAIL_FROM');

	//destinataire -> contact facturation de la société et à défaut adresse mail de la société
	if (empty($to)) {
		$to = empty($object->thirdparty->email) ? '' : $object->thirdparty->email;
		dol_syslog("stancerSendOrderMailModele utilisation de l'adresse mail societe, destinataire = $to", LOG_DEBUG);
	}

	if (empty(trim((string) $from)) || empty(trim((string) $to))) {
		// print json_encode($object);
		dol_syslog("stancerSendOrderMailModele early return, from=$from or to=$to is empty", LOG_DEBUG);
		return; // returns null: an empty from/to is a skip, distinct from dedup (0)
	}

	// Get email content from templae
	$formmail = new FormMail($db);
	//getEMailTemplate($dbs, $type_template, $user, $outputlangs, $id = 0, $active = 1, $label = '', $defaultfortype = -1)
	// 'order_send' is kept as a fallback: admin/mail.php only lists templates of that type
	// for STANCER_AUTO_MAIL_ORDER_CB_MAILTYPE, so existing setups point at an order template
	// even when the paid object is an invoice.
	$arraydefaultmessage = stancerGetMailTemplate($formmail, $modele, $outputlangs, array($mailctx['templatetype'], 'order_send'), 'stancerSendOrderMailModele');

	if (is_object($arraydefaultmessage)) {
		$subject = $arraydefaultmessage->topic;
		$msg     = $arraydefaultmessage->content;
	}

	$substitutionarray = getCommonSubstitutionArray($outputlangs, 0, null, $object);

	// $substitutionarray['__SELLYOURSAAS_PAYMENT_ERROR_DESC__']=$stripefailurecode.' '.$stripefailuremessage;

	complete_substitutions_array($substitutionarray, $outputlangs, $object);

	$subjecttosend = make_substitutions($subject, $substitutionarray, $outputlangs);
	$texttosend = make_substitutions($msg, $substitutionarray, $outputlangs);

	dol_syslog('stancerSendOrderMailModele SUBJECT=' . $subjecttosend);

	// Fichier joint
	// stancerRegeneratePDFifNeeded($object);
	$file = '';
	$listofpaths = array();
	$listofnames = array();
	$listofmimes = array();
	if (is_object($object) && !empty($mailctx['diroutput'])) {
		// ->ref is not typed on CommonObject: cast it before preg_quote(), which
		// rejects null since PHP 8.1.
		$objectRef = (string) ($object->ref ?? '');
		// dol_most_recent_file() returns null when the directory holds no matching file,
		// hence the is_array() guard before reading 'fullname'.
		$fileparams = dol_most_recent_file($mailctx['diroutput'] . '/' . $objectRef, preg_quote($objectRef, '/') . '.*.pdf');

		$file = (is_array($fileparams) && !empty($fileparams['fullname'])) ? $fileparams['fullname'] : '';

		if ($file) {
			$listofpaths = array($file);
			$listofnames = array(dol_basename($file));
			$listofmimes = array(dol_mimetype($file));
		} else {
			dol_syslog('stancerSendOrderMailModele no pdf found in ' . $mailctx['diroutput'] . '/' . $object->ref . ', mail will be sent without attachment', LOG_WARNING);
		}
	}
	dol_syslog('stancerSendOrderMailModele fichier(s) joint(s) : ' . json_encode($listofpaths));

	$trackid = empty($mailctx['trackidprefix']) ? '' : $mailctx['trackidprefix'] . $object->id;
	if (empty($trackid)) {
		dol_syslog('stancerSendOrderMailModele no track id for element ' . (is_object($object) ? $object->element : '?') . ', answers to this mail will not be reattached by the email collector', LOG_INFO);
	}
	$moreinheader = 'X-Dolibarr-Info: stancerSendOrderMailModele' . "\r\n";

	// Send email (substitutionarray must be done just before this)
	$mailfile = new CMailFile($subjecttosend, $to, $from, $texttosend, $listofpaths, $listofmimes, $listofnames, '', '', 0, -1, '', '', $trackid, $moreinheader);
	if ($mailfile->sendfile()) {
		$result = 1;
	} else {
		$error = $langs->trans("ErrorFailedToSendMail", $from, $to) . '. ' . $mailfile->error;
		dol_syslog('stancerSendOrderMailModele Error : ' . $mailfile->error, LOG_ERR);
		$result = -1;
	}

	if ($result < 0) {
		$errmsg = $error;
		$postactionmessages[] = $errmsg;
		$ispostactionok = -1;
	} else {
		if ($file) {
			$postactionmessages[] = 'Email sent to thirdparty (to ' . $to . ' with ' . (is_object($object) ? $object->element : '?') . ' document attached: ' . $file . ', language = ' . $outputlangs->defaultlang . ')';
		} else {
			$postactionmessages[] = 'Email sent to thirdparty (to ' . $to . ' without any attached document, language = ' . $outputlangs->defaultlang . ')';
		}

		// extraparams='AC_<actionCode>' is the marker used by the dedup filter above
		// (rows are stored with the standard 'AC_EMAIL' code, see stancerAddActionComm).
		stancerAddActionComm($object, $actionCode, $subjecttosend, $texttosend, $postactionmessages, 'AC_' . $actionCode, true);
	}

	dol_syslog("stancerSendOrderMailModele ends, return $result", LOG_DEBUG);
	return $result;
}


/**
 * convert a csv stream to an html table with header
 *
 * @param   array  	$header   array header of table
 * @param   string  $message  csv data
 *
 * @return  string            html code
 */

/**
 * Build a clean HTML link for a Dolibarr object, safe for CSV/email injection.
 * Avoids getNomUrl() which generates HTML with semicolons and tooltips that break CSV parsing.
 *
 * @param   CommonObject  $obj  Dolibarr object (Facture, Commande, Propal, etc.)
 * @return  string              Clean HTML <a> link or escaped ref as fallback
 */
function stancerObjectUrlForMail($obj)
{
	// ->ref is ?string on CommonObject, dol_escape_htmltag() is declared to take a string.
	$ref = dol_escape_htmltag((string) $obj->ref);
	$pathMap = array(
		'facture' => '/compta/facture/card.php',
		'commande' => '/commande/card.php',
		'propal' => '/comm/propal/card.php',
		'don' => '/don/card.php',
		'member' => '/adherents/card.php',
	);
	if (isset($pathMap[$obj->element])) {
		return "<a href='" . dol_buildpath($pathMap[$obj->element], 3) . "?id=" . $obj->id . "'>" . $ref . "</a>";
	}
	return $ref;
}

/**
 * Render a CSV report as an HTML table, for the notification emails
 *
 * @param   array   $header   Column titles, no header row is emitted when this is not an array
 * @param   string  $message  Semicolon separated data lines, one row per line
 * @return  string            HTML table, duplicate rows removed
 */
function stancerCSVtoHTML($header, $message)
{
	$dedup = [];
	$tableStyle = 'width:100%;border-collapse:collapse;font-size:13px;font-family:Arial,Helvetica,sans-serif;';
	$thStyle = 'background-color:#4169E1;color:#ffffff;padding:10px 12px;text-align:left;font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:0.5px;';
	$tdStyle = 'padding:10px 12px;border-bottom:1px solid #d6e0f0;color:#4a5568;';
	$tdStyleAlt = 'padding:10px 12px;border-bottom:1px solid #d6e0f0;color:#4a5568;background-color:#eef2f7;';

	$html = "\n<table style=\"" . $tableStyle . "\">\n";

	if (is_array($header)) {
		$html .= "  <tr>\n";
		foreach ($header as $h) {
			$html .= "    <th style=\"" . $thStyle . "\">" . $h . "</th>\n";
		}
		$html .= "  </tr>\n";
	}

	$arrayOfValues = str_getcsv($message, "\n");
	$rowIndex = 0;
	foreach ($arrayOfValues as $row) {
		// str_getcsv() may hand back a null entry for a blank line, and rejects null since PHP 8.1.
		$cols = str_getcsv((string) $row, ";");

		if (in_array($cols[0], $dedup)) {
			dol_syslog("stancerCSVtoHTML : dedup row " . $cols[0]);
			continue;
		} else {
			dol_syslog("stancerCSVtoHTML : add dedup row " . $cols[0]);
			$dedup[] = $cols[0];
		}

		$currentTdStyle = ($rowIndex % 2 === 1) ? $tdStyleAlt : $tdStyle;
		$html .= "  <tr>\n";
		foreach ($cols as $col) {
			$html .= "    <td style=\"" . $currentTdStyle . "\">" . $col . "</td>\n";
		}
		$html .= "  </tr>\n";
		$rowIndex++;
	}
	$html .= "</table>\n";
	return $html;
}
