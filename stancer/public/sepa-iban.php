<?php
/* Copyright (C) 2001-2002	Rodolphe Quiedeville	<rodolphe@quiedeville.org>
 * Copyright (C) 2006-2013	Laurent Destailleur		<eldy@users.sourceforge.net>
 * Copyright (C) 2023		Eric Seigne				<eric.seigne@cap-rel.fr>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 *     	\file       public/sepa-iban.php
 *		\ingroup    core
 *		\brief      File to show page for customer to enter his IBAN SEPA data to create a SEPA Mandate
 */

if (!defined('NOLOGIN')) {
	define("NOLOGIN", 1); // This means this output page does not require to be logged.
}
if (!defined('NOCSRFCHECK')) {
	define("NOCSRFCHECK", 1); // We accept to go on this page from external web site.
}
if (!defined('NOIPCHECK')) {
	define('NOIPCHECK', '1'); // Do not check IP defined into conf $dolibarr_main_restrict_ip
}
if (!defined('NOBROWSERNOTIF')) {
	define('NOBROWSERNOTIF', '1');
}

// For MultiCompany module.
// Do not use GETPOST here, function is not defined and define must be done before including main.inc.php
$entity = (!empty($_GET['e']) ? (int) $_GET['e'] : (!empty($_POST['e']) ? (int) $_POST['e'] : 1));
if (is_numeric($entity)) {
	define("DOLENTITY", $entity);
}
$res = 0;
// Try main.inc.php into web root known defined into CONTEXT_DOCUMENT_ROOT (not always defined)
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
// Try main.inc.php into web root detected using web root calculated from SCRIPT_FILENAME
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME']; $tmp2 = realpath(__FILE__); $i = strlen($tmp) - 1; $j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
	$i--; $j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1))."/main.inc.php")) {
	$res = @include substr($tmp, 0, ($i + 1))."/main.inc.php";
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php")) {
	$res = @include dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php";
}
// Try main.inc.php using relative path
if (!$res && file_exists("../main.inc.php")) {
	$res = @include "../main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}
dol_include_once('/stancer/lib/stancer.lib.php');
require_once DOL_DOCUMENT_ROOT.'/core/lib/company.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/payments.lib.php';

dol_include_once('/stancer/core/modules/bank/doc/pdf_sepamandate_stancer.modules.php');

global $dolibarr_main_instance_unique_id, $conf;

if (getDolGlobalString('STANCER_PUBLIC_IBAN_PAGE', '') == '') {
	dol_syslog("stancer SEPA public page is disabled by config !", LOG_ERR);
	accessforbidden($langs->trans('StancerPublicIBANPageDisabled'), 0, 0, 1);
}

$langs->loadLangs(array("main", "other", "dict", "bills", "companies", "paybox", "paypal","stancer@stancer"));

// Clean parameters
parse_str(base64_decode((string) GETPOST('s', 'alpha')), $args);
// dol_syslog("stancer sepa-iban (args) is " . json_encode($args), LOG_DEBUG);

$tst = '';
if (isset($args['socid'])) {
	$societe = new Societe($db);
	$societe->fetch($args['socid']);
	$tst = "SEPA-" . $args['socid'] . "-" . $societe->name . "-" . getDolGlobalString('PAYMENT_SECURITY_TOKEN');
	$_SESSION['sepa-iban'] = 'new';
} else {
	print "<p class=''>" . $langs->transnoentitiesnoconv("StancerThereIsNoSocID") . "</p>";
	exit;
}
// dol_syslog("stancer check securekey is from $tst");
// dol_syslog("stancer securekey is " . json_encode($args['securekey'] . " compare with " . dol_hash($tst,'1')), LOG_DEBUG);
if (dol_hash($tst, '1') != $args['securekey']) {
	dol_syslog("stancer SEPA form error, securekey no confirmed !", LOG_ERR);
	accessforbidden($langs->trans('StancerIBANCBinpoutSecurekeyError'), 0, 0, 1);
}

if (isset($_SESSION['sepa-iban']) && $_SESSION['sepa-iban'] == 'done') {
	$errmsg = 'stancer detect page reload';
	dol_syslog($errmsg);
	print "<p class=''>" . $langs->transnoentitiesnoconv("StancerPublicIBANCBPageReload", dol_print_url($mysoc->url, '_blank', 0, 1)) . "</p>";
	exit;
}

// dol_syslog("stancer s=" . base64_encode("socid=15&action=stancerSEPA&securekey=".dol_hash($tst,'1')));
// dol_syslog("stancer securekey=" . dol_hash($tst,'1'));
$ro = '';
$disabled = '';
$success = '';
$signLink = $signer = '';
$signLinkAutoRedirect = false;

//note : public page, user is not set, so we use user who create that company
$user = new User($db);
$res = $user->fetch(getDolGlobalString('STANCER_USER_ACCOUNT_FOR_ACTIONS'));
if ($res < 0) {
	//fallback on last user modif ...
	$uid = $societe->user_modification ?? $societe->user_creation;
	$user->fetch($uid);
}
$user->getrights();

$stancerCustomerNameOnIBAN = $societe->name;
// $sp = new Stancer_payments($db);
$action = (string) GETPOST('action', 'aZ09');
$iban = $bic = $stancerIBANBankName = $stancerCustomerNameOnIBAN = "";
$companypaymentmode = $error = null;

if ($action == "stancerGetCustomerIBAN") {
	$iban = (string) GETPOST('stancerIBAN', 'alpha');
	$bic = (string) GETPOST('stancerBIC', 'alpha');
	$stancerIBANBankName = (string) GETPOST('stancerIBANBankName', 'alpha');
	$stancerCustomerNameOnIBAN = (string) GETPOST('stancerCustomerNameOnIBAN', 'alpha');
	$db->begin();

	$myIban = new PHP_IBAN\IBAN($iban);
	if (!$myIban->Verify()) {
		dol_syslog("stancerSEPA IBAN is not correct", LOG_DEBUG);
		$message = $langs->trans("stancerSEPAinvalidIBAN");
		setEventMessages($langs->trans("Error") . " " . $message, [],  'errors');
	} else {
		// SEPA check: verify bank account is reachable before creating mandate
		$sepaCheckBlocked = false;
		$sepaCheckResult = stancerCheckIBAN($myIban->MachineFormat(), $societe);
		if ($sepaCheckResult !== null) {
			$sepaCheckCompany = isset($sepaCheckResult['company']) ? $sepaCheckResult['company'] : array();
			if (isset($sepaCheckCompany['existing_account']) && $sepaCheckCompany['existing_account'] === false) {
				dol_syslog("stancer sepa-iban public: BLOCKED by SEPA check, existing_account=false for socid=" . $args['socid'], LOG_WARNING);
				$sepaCheckBlocked = true;
				$db->rollback();
			}
		}

		if ($sepaCheckBlocked) {
			// Error message already set by stancerCheckIBAN()
			dol_syslog("stancer sepa-iban public: mandate creation aborted due to SEPA check failure", LOG_WARNING);
		} else {
			$myIbanParts = iban_get_parts($iban);
			// print json_encode($myIbanParts);

			//create stancer account if needed
			$stancerID = stancerAddCustomerIfNeeded($societe);

			$addr = $societe->address . ' ' . $societe->zip . ' ' . $societe->town;

			//add bank account only if needed ?
			$companypaymentmode = new CompanyPaymentModeStancer($db); //note table societe_rib

			$label = 'stancer-sepa-tst';
			if (getDolGlobalString('STANCER_IS_PROD', '0') == '1') {
				$label = 'stancer-sepa';
			}

			$data = [
			'socid' => $args['socid'],
			'fk_soc' => $args['socid'],
			'bank' => $stancerIBANBankName,
			'type' => 'ban',
			'label' => $label . '_' . date("YmdHi"),
			'code_banque' => $myIbanParts['bank'],
			'code_guichet' => $myIbanParts['branch'],
			'number' => $myIbanParts['account'],
			'cle_rib' => $myIbanParts['nationalchecksum'],
			'bic' => $bic,
			'iban_prefix' => $myIban->MachineFormat(),
			'domiciliation' => "",
			'proprio' => $stancerCustomerNameOnIBAN,
			'owner_address' => $addr,
			'frstrecur' => "RECUR",
			'rum' => "",
			'date_rum' => "",
			'default_rib' => 0,
			'datec' => dol_now(),
			'status' => 1,
			'entity' => $conf->entity,
			];
			$compPayModeId = stancerAddCompanyPaymentModeifNeeded($data);
			$companypaymentmode->fetch($compPayModeId);

			// le RUM / Mandat ?
			if (empty($companypaymentmode->rum)) {
				$prelevement = new BonPrelevement($db);
				$companypaymentmode->rum = $prelevement->buildRumNumber($societe->code_client, $companypaymentmode->datec, $companypaymentmode->id);
				$companypaymentmode->date_rum = dol_mktime(0, 0, 0, (int) date('m'), (int) date('d'), (int) date('Y'));
			}

			//force name because of create seems do not take care about that
			$companypaymentmode->proprio = $stancerCustomerNameOnIBAN;// ?? $societe->name;
			$companypaymentmode->owner_address = $addr;

			$result = $companypaymentmode->update($user); // This will set the UMR number.
			if ($result < 0) {
				dol_syslog("stancer sepa mandate companypaymentmode update error !", LOG_ERR);
				$error++;
				setEventMessages($companypaymentmode->error, $companypaymentmode->errors, 'errors');
				$action = 'create';
			}
			dol_syslog("stancer sepa public : error (0)=$error");

			if (!$error) {
				$db->commit();
				$stancer_email_info_sepa = getDolGlobalString('STANCER_EMAIL_INFO_SEPA', '');
				//rib ok, check on stancer to get BIC
				$data['iban'] = $myIban->MachineFormat();
				$data['bic'] = $bic;
				$data['mandate'] = $companypaymentmode->rum;
				$data['date_mandate'] = dol_now();
				// Also pass the id of the newly created account, so it can be updated once Stancer returns its BIC
				$data['companypaymentmode_id'] = $companypaymentmode->id;
				$stancerSEPA = stancerAddSEPAIfNeeded((int) $args['socid'], $data);
				dol_syslog("stancer sepa public : stancerSEPA=$stancerSEPA");
				if (is_numeric($stancerSEPA) &&  $stancerSEPA <= 0) {
					dol_syslog("stancer sepa public error detected : stancerSEPA=$stancerSEPA");
					$error++;
				} else {
					//reload because stancerAddSEPAIfNeededmay add BIC informations from Stancer request
					$companypaymentmode->fetch($compPayModeId);

					setEventMessages($langs->trans('Success') ." " . $langs->trans('StancerSuccessSEPAdone'), [], 'mesgs');
					$result = $companypaymentmode->call_trigger('SEPA_MANDATE_CREATED', $user);
					if ($result < 0) {
						dol_syslog("stancer sepa mandate call trigger SEPA_MANDATE_CREATED error !", LOG_ERR);
						//what to do in case of trigger error ? just log
					} else {
						dol_syslog("stancer sepa mandate call trigger SEPA_MANDATE_CREATED success");
					}

					$ro = " readonly";
					$disabled = "disabled";
					$success = 1;
					$_SESSION['sepa-iban'] = 'done'; //avoid F5
					//Creation du fichier PDF
					if (getDolGlobalString('STANCER_MANDATE_AUTO', '') != '') {
						$pdf = new pdf_sepamandate_stancer($db);
						$pdfMoreparams = array(
						'use_companybankid'=>$companypaymentmode->id,
						'force_dir_output'=>$conf->societe->multidir_output[$conf->entity].'/'.dol_sanitizeFileName($args['socid'])
						);
						if ($pdf->write_file($companypaymentmode, $langs, '', 0, 0, 0, $pdfMoreparams) > 0) {
							dol_syslog("stancer sepa mandate pdf is ready !");
							$result = $companypaymentmode->call_trigger('SEPA_MANDATE_PDF_CREATED', $user);
							if ($result < 0) {
								dol_syslog("stancer sepa mandate call trigger SEPA_MANDATE_PDF_CREATED error !", LOG_ERR);
							}
							if (getDolGlobalString('STANCER_MANDATE_AUTO_UPTOSIGN', '') != '') {
								dol_syslog("stancer sepa mandate call uptosign code to sign that mandate !", LOG_DEBUG);

								if (dol_include_once('/uptosign/class/uptosignCore.class.php')) {
									$uptosignCore = new uptosignCore(['db'=>$db]);
									//sepamandate object does not exists, so we use "contract" as document type to find who can sign
									$list_of_potential_signers = $uptosignCore->whoCanSign($societe->id, 'contrat', 'CustomerSign');

									// print "<p>Liste des signataires possibles : " . json_encode($list_of_potential_signers) . "</p>";

									//Un seul signataire possible -> bingo
									if (is_countable($list_of_potential_signers) && count($list_of_potential_signers) == 1 || get_class($list_of_potential_signers) == 'Contact') {
										$signer = $list_of_potential_signers;
										if (is_array($signer)) {
											$signer = reset($list_of_potential_signers);
										}

										$list_of_signers[] = array('id' => $signer->id,
													'firstname' => $signer->firstname,
													'lastname' => $signer->lastname,
													'company' => $signer->socname,
													'email' => $signer->email,
													'mobile' => $signer->phone_mobile,
													'signPage' => 1,
													'signPosX' => 120,
													'signPosY' => 220 );

										$uptosignCore = new uptosignCore([
														'db'=>$db,
														'src_file_name'=> $pdf->result['fullpath'],
														'object' => $companypaymentmode,
														'list_of_signers' => $list_of_signers,
														'procedure' => 'sign',
														'plugin_name' => 'Stancer 1.0',
														'seal_x' => 120,
														'seal_y' => 248,
														'seal_page' => 1,
														'title' => $langs->trans('StancerDocTitleForUptoSign', $mysoc->name, $signer->socname),
														'mail_alerts' => getDolGlobalString('STANCER_EMAIL_INFO_SEPA')
													]);
										dol_syslog("stancer sepa mandate call uptosign *********************************** user is " . json_encode($user), LOG_DEBUG);
										$resSign = $uptosignCore->run([], $user);
										dol_syslog("stancer sepa mandate call uptosign end ********************************", LOG_DEBUG);
										if ($resSign == 0) {
											dol_syslog("stancer sepa mandate call uptosign end ******************************** ressign = 0", LOG_DEBUG);
											$signLink = $uptosignCore->signLink();
											if (empty($signLink)) {
												$signLink = 'email';
											} else {
												//Si redirection automatique demandée dans la conf du module
												if (getDolGlobalString('STANCER_MANDATE_AUTO_UPTOSIGN_NOCLICK', '') != '') {
													$signLinkAutoRedirect = true;
													dol_syslog("stancer sepa mandate call uptosign end with js transparent redirect enabled", LOG_DEBUG);
												}
											}
										}
										dol_syslog("stancer sepa mandate call uptosign code to sign that mandate started, code is " .$resSign . ", and signLink = $signLink", LOG_DEBUG);
										$sepaTrackid = 'thi' . $societe->id;
										stancerSendMail($stancer_email_info_sepa, $langs->trans('StancerMailSubjectIBANandMandateReady'), $langs->trans('StancerMailIBANandMandateReady', $societe->name), false, '', $sepaTrackid);
									} else {
										dol_syslog("stancer sepa mandate can(t call uptosign code because there is no sign people", LOG_DEBUG);
										$sepaTrackid = 'thi' . $societe->id;
										stancerSendMail($stancer_email_info_sepa, $langs->trans('StancerMailSubjectIBANandMandateReady'), $langs->trans('StancerMailIBANandMandateReadyButMissingSignPeople', $societe->name), false, '', $sepaTrackid);
									}
								} else {
									dol_syslog("stancer sepa can't load uptosigncore file", LOG_ERR);
									$sepaTrackid = 'thi' . $societe->id;
									stancerSendMail($stancer_email_info_sepa, $langs->trans('StancerMailSubjectIBANandMandateReadyToSignErr'), $langs->trans('StancerMailIBANandMandateReadyToSign', $societe->name), false, '', $sepaTrackid);
								}
							} else {
								dol_syslog("stancer sepa mandate pdf ready to sign !", LOG_ERR);
								$sepaTrackid = 'thi' . $societe->id;
								stancerSendMail($stancer_email_info_sepa, $langs->trans('StancerMailSubjectIBANandMandateReadyToSign'), $langs->trans('StancerMailIBANandMandateReadyToSign', $societe->name), false, '', $sepaTrackid);
							}
						} else {
							dol_syslog("stancer sepa mandate pdf error !", LOG_ERR);
							$sepaTrackid = 'thi' . $societe->id;
							stancerSendMail($stancer_email_info_sepa, $langs->trans('StancerMailSubjectIBANandMandateErr'), $langs->trans('StancerMailIBANandMandateErr', $societe->name), false, '', $sepaTrackid);
						}
					} else {
						dol_syslog("stancer sepa iban is recorded !", LOG_ERR);
						$sepaTrackid = 'thi' . $societe->id;
						stancerSendMail($stancer_email_info_sepa, $langs->trans('StancerMailSubjectIBANsimple'), $langs->trans('StancerMailIBANsimple', $societe->name), false, '', $sepaTrackid);
					}
				}
			} else {
				$db->rollback();
			}
		} // end of !$sepaCheckBlocked else
	}
}
$reg = array();
$object = new stdClass(); // For triggers
$error = 0;

/*
 * Actions
 */



/*
 * View
 */

$now = dol_now();

$head = '';
if (getDolGlobalString('ONLINE_PAYMENT_CSS_URL', '') != '') {
	$head = '<link rel="stylesheet" type="text/css" href="' . getDolGlobalString('ONLINE_PAYMENT_CSS_URL').'?lang='.$langs->defaultlang.'">'."\n";
}

$arrayofcss =  array(
	'/stancer/public/css/tailwind.min.css',
);

$conf->dol_hide_topmenu = 1;
$conf->dol_hide_leftmenu = 1;

$replacemainarea = (empty($conf->dol_hide_leftmenu) ? '<div>' : '').'<div>';
llxHeader($head, $langs->trans("SepaMandateForStancerPageTitle"), '', '', 0, 0, '', $arrayofcss, '', 'onlinepaymentbody', $replacemainarea);


// Show message
print '<div id="dolpaymentdiv" class="center">'."\n";

$logo = $mysoc->logo;
$urllogofull = '';
// A payment page specific logo first, the company logo otherwise
if (getDolGlobalString('MAIN_IMAGE_PUBLIC_PAYMENT', '') != '') {
	$urllogofull = getDolGlobalString('MAIN_IMAGE_PUBLIC_PAYMENT');
} elseif (!empty($logo) && is_readable($conf->mycompany->dir_output.'/logos/'.$logo)) {
	$urllogofull = $dolibarr_main_url_root.'/viewimage.php?modulepart=mycompany&entity='.$conf->entity.'&file='.urlencode('logos/'.$logo);
}


if (!empty($conf->stancer->enabled)) {
	print '<form id="stancerGetIBAN" class="center" name="stancerGetIBAN" action="'.$_SERVER["PHP_SELF"].'" method="POST">'."\n";
	print '<input type="hidden" name="token" value="'.newToken().'">'."\n";
	print '<input type="hidden" name="action" value="stancerGetCustomerIBAN">'."\n";
	print '<input type="hidden" name="s" value="'.GETPOST("s", 'alpha').'">'."\n";
	print '<input type="hidden" name="e" value="'.$entity.'" />'; ?>

	<?php if ($success) { ?>
<section class="text-gray-600 body-font">
  <div class="container mx-auto flex px-5 md:flex-row flex-col items-center">
	<div class="lg:max-w-lg lg:w-full md:w-1/2 w-5/6 mb-10 md:mb-0">
		<svg class="h-64 w-64 text-green-400"  width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">  <path stroke="none" d="M0 0h24v24H0z"/>  <polyline points="9 11 12 14 20 6" />  <path d="M20 12v6a2 2 0 0 1 -2 2h-12a2 2 0 0 1 -2 -2v-12a2 2 0 0 1 2 -2h9" /></svg>
	</div>
	<div class="lg:flex-grow md:w-1/2 lg:pl-24 md:pl-16 flex flex-col md:items-start md:text-left items-center text-center">
	  <h1 class="title-font sm:text-4xl text-3xl mb-4 font-medium text-gray-900"><?php echo $langs->trans("StancerPleaseEnterYourIBANorCBTitleDone"); ?></h1>
	  <p class="mb-4"><?php echo $langs->trans("StancerPleaseEnterYourIBANDone"); ?></p>
	  <p class="mb-4"><?php echo $langs->trans("StancerYourMandateRefIs", $companypaymentmode->rum); ?></p>
		<?php if ($signLink == 'email') { ?>
	  <p class="mb-4"><?php echo $langs->transnoentitiesnoconv("StancerPleaseEnterYourIBANWillBeSentByUptoSign", $signer->email); ?></p>
	  <p class="mb-4"><?php echo $langs->transnoentitiesnoconv("StancerPaymentDoneEndMessage", "<a href='" . $mysoc->url . "'>" . $mysoc->name . " - " . $mysoc->url . "</a>") ?></p>
		<?php } elseif ($signLink != '' && $signLinkAutoRedirect) { ?>
	  <p class="mb-4"><?php echo $langs->transnoentitiesnoconv("StancerPleaseEnterYourIBANisReadyToSignAutoRedir", '<a href="' . $signLink . '">', '</a><span class="fas fa-external-link-alt" style=""></span>'); ?></p>
		<?php } elseif ($signLink != '') { ?>
	  <p class="mb-4"><?php echo $langs->transnoentitiesnoconv("StancerPleaseEnterYourIBANisReadyToSign", '<a href="' . $signLink . '">', '</a><span class="fas fa-external-link-alt" style=""></span>'); ?></p>
		<?php } else { ?>
		<p class="mb-4"><?php echo $langs->trans("StancerPleaseEnterYourIBANWillBeSent"); ?></p>
		<?php } ?>
	</div>
  </div>
</section>
		<?php if ($signLinkAutoRedirect) { ?>
<script language="javascript">
	$(document).ready(function(){
	  window.location.replace('<?php echo $signLink; ?>');
	});
</script>
		<?php } ?>

	<?php } else { ?>
<section class="text-gray-600 body-font">
  <h1 class="title-font sm:text-4xl text-3xl mb-4 font-medium text-gray-900"><?php echo $langs->trans("StancerPleaseEnterYourIBANTitle"); ?></h1>
  <p class="mb-4"><?php echo $langs->trans("StancerPleaseEnterYourIBAN"); ?></p>
  <div class="container mx-auto flex px-5 md:flex-row flex-col items-center">
	<div class="lg:max-w-lg lg:w-full md:w-1/2 mb-10 md:mb-0">
		<img class="object-cover object-center rounded" alt="<?php echo dol_escape_htmltag($mysoc->name);?>" src="<?php echo $urllogofull; ?>">
		<p class="text-sm mt-2 text-gray-500 mb-8 w-full justify"><?php echo $langs->trans('SEPALegalTextStancer2', dol_escape_htmltag($mysoc->name), dol_escape_htmltag($mysoc->name)); ?></p>
		<p class="text-sm mt-2 text-gray-500 mb-8 w-full justify"><?php echo $langs->trans('SEPALegalTextStancerDPO', getDolGlobalString('STANCER_EMAIL_DPO')); ?></p>
	</div>
	<div class="lg:flex-grow md:w-1/2 xl:pt-24 lg:pl-24 md:pl-16 flex flex-col md:items-start md:text-left items-center text-center">
	<div class="flex w-full justify-end items-end">
			<div class="xl:w-8/12 md:w-10/12 w-full text-left relative mr-4">
		  <label for="StancerCustomerName" class="leading-7 text-sm text-gray-600"><?php echo $langs->trans('StancerCustomerName'); ?></label>
		  <input type="text" id="StancerCustomerName" name="StancerCustomerName" value="<?php echo dol_escape_htmltag($societe->name); ?>" disabled class="w-full bg-gray-100 bg-opacity-50 rounded border border-gray-300 focus:ring-2 focus:ring-indigo-200 focus:bg-transparent focus:border-indigo-500 text-base outline-none text-gray-700 py-1 px-3 leading-8 transition-colors duration-200 ease-in-out">
		</div>
	  </div>
	  <div class="flex w-full justify-end items-end">
			<div class="xl:w-8/12 md:w-10/12 w-full text-left relative mr-4">
		  <label for="stancerCustomerNameOnIBAN" class="leading-7 text-sm text-gray-600"><?php echo $langs->trans('StancerCustomerNameOnIBAN'); ?></label>
		  <input type="text" id="stancerCustomerNameOnIBAN" name="stancerCustomerNameOnIBAN" value="<?php echo dol_escape_htmltag($stancerCustomerNameOnIBAN); ?>" class="w-full bg-gray-100 bg-opacity-50 rounded border border-gray-300 focus:ring-2 focus:ring-indigo-200 focus:bg-transparent focus:border-indigo-500 text-base outline-none text-gray-700 py-1 px-3 leading-8 transition-colors duration-200 ease-in-out">
		</div>
	  </div>
	  <div class="flex w-full justify-end items-end">
		<div class="xl:w-8/12 md:w-10/12 w-full text-left relative mr-4">
		  <label for="stancerIBANBankName" class="leading-7 text-sm text-gray-600"><?php echo $langs->trans('StancerBankName'); ?></label>
		  <input type="text" id="stancerIBANBankName" name="stancerIBANBankName" value="<?php echo dol_escape_htmltag($stancerIBANBankName); ?>" <?php echo $ro; ?> class="w-full bg-gray-100 bg-opacity-50 rounded border border-gray-300 focus:ring-2 focus:ring-indigo-200 focus:bg-transparent focus:border-indigo-500 text-base outline-none text-gray-700 py-1 px-3 leading-8 transition-colors duration-200 ease-in-out">
		</div>
	  </div>
	  <div class="flex w-full justify-end items-end">
		<div class="xl:w-8/12 md:w-10/12 w-full text-left relative mr-4">
		  <label for="stancerIBAN" class="leading-7 text-sm text-gray-600"><?php echo $langs->trans('StancerYourIBAN'); ?></label>
		  <input type="text" id="stancerIBAN" name="stancerIBAN" value="<?php echo dol_escape_htmltag($iban);?>" <?php echo $ro; ?> class="w-full bg-gray-100 bg-opacity-50 rounded border border-gray-300 focus:ring-2 focus:ring-indigo-200 focus:bg-transparent focus:border-indigo-500 text-base outline-none text-gray-700 py-1 px-3 leading-8 transition-colors duration-200 ease-in-out">
		</div>
	  </div>
	  <div class="flex w-full justify-end items-end flex-wrap">
			<div class="xl:w-8/12 md:w-8/12 w-full text-left relative mr-4 max-w-xs">
				<div class="mt-8">
					<div class="input_text relative w-full">
						<label for="stancerBIC" class="leading-7 text-sm text-gray-600"><?php echo $langs->trans('StancerYourBIC'); ?></label>
						<input type="text" id="stancerBIC" name="stancerBIC" value="<?php echo dol_escape_htmltag($bic);?>" <?php echo $ro; ?> class="w-full bg-gray-100 bg-opacity-50 rounded border border-gray-300 focus:ring-2 focus:ring-indigo-200 focus:bg-transparent focus:border-indigo-500 text-base outline-none text-gray-700 py-1 px-3 leading-8 transition-colors duration-200 ease-in-out">
					</div>
				</div>
			</div>
	  </div>
	  <div class="flex w-full justify-end items-end flex-wrap">
			<div class="xl:w-3/12 md:w-5/12 w-full text-right relative mt-4 mr-4 max-w-xs">
				<button  <?php echo $disabled; ?> class="inline-flex text-white bg-indigo-500 border-0 py-2 px-6 focus:outline-none hover:bg-indigo-600 rounded text-lg"><?php echo $langs->trans('Save'); ?></button>
			</div>
	  </div>
	</div>
  </div>
</section>
<script language="javascript">
$('#stancerIBAN').on('keyup', function() {
  var foo = $(this).val().split(" ").join("");
  if (foo.length > 0) {
	foo = foo.match(new RegExp('.{1,4}', 'g')).join(" ");
  }
  $(this).val(foo);
});
</script>
	<?php } ?>

	<?php
	print '</form>'."\n";
}
// If data not provided from back url, search them into the session env
if (!isset($ipaddress) || empty($ipaddress)) {
	$ipaddress       = $_SESSION['ipaddress'];
}

print "\n</div>\n";

print '<script src="'.dol_buildpath('/stancer/js/stancer_submit_once.js', 1).'"></script>'."\n";

if (((int) DOL_VERSION) < 18) {
	// @phpstan-ignore-next-line
	htmlPrintOnlinePaymentFooter($mysoc, $langs);
} else {
	// @phpstan-ignore-next-line
	htmlPrintOnlineFooter($mysoc, $langs);
}


llxFooter('', 'public');

$db->close();
