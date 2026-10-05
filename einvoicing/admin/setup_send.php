<?php
/* Copyright (C) 2004-2017  Laurent Destailleur     <eldy@users.sourceforge.net>
 * Copyright (C) 2024       Frédéric France         <frederic.france@free.fr>
 * Copyright (C) 2025		SuperAdmin				<daoud.mouhamed@gmail.com>
 * Copyright (C) 2026		Alexandre Spangaro      <alexandre@inovea-conseil.com>
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
 * \file    einvoicing/admin/setup_send.php
 * \ingroup einvoicing
 * \brief   EInvoicing setup page.
 */

// Load Dolibarr environment
$res = false;
// Try main.inc.php into web root known defined into CONTEXT_DOCUMENT_ROOT (not always defined)
// @phpstan-ignore booleanNot.alwaysTrue (optional server variable)
if (!empty($_SERVER['CONTEXT_DOCUMENT_ROOT'])) {
	$res = @include $_SERVER['CONTEXT_DOCUMENT_ROOT'].'/main.inc.php';
}
// Try main.inc.php into web root detected using web root calculated from SCRIPT_FILENAME
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
	$i--;
	$j--;
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
if (!$res && file_exists("../../../../main.inc.php")) {
	$res = @include "../../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}
/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var HookManager $hookmanager
 * @var Translate $langs
 * @var User $user
 * @var Societe $mysoc
 */
// Libraries
require_once DOL_DOCUMENT_ROOT."/core/lib/admin.lib.php"; // @phpstan-ignore requireOnce.fileNotFound (PHPStan takes DOL_DOCUMENT_ROOT from install/inc.php of the core, where it is '..')
require_once __DIR__.'/../lib/einvoicing.lib.php';
require_once __DIR__.'/../class/providers/PDPProviderManager.class.php';
require_once __DIR__.'/../class/protocols/ProtocolManager.class.php';
require_once __DIR__.'/../class/einvoicing.class.php';

// Translations
$langs->loadLangs(array("admin", "einvoicing@einvoicing"));

// Initialize a technical object to manage hooks of page. Note that conf->hooks_modules contains an array of hook context
/** @var HookManager $hookmanager */
$hookmanager->initHooks(array('einvoicingsetup', 'globalsetup'));

// Parameters
$action = GETPOST('action', 'aZ09');
$backtopage = GETPOST('backtopage', 'alpha');
$modulepart = GETPOST('modulepart', 'aZ09');	// Used by actions_setmoduleoptions.inc.php

$value = GETPOST('value', 'alpha');
$label = GETPOST('label', 'alpha');
$scandir = GETPOST('scan_dir', 'alpha');
$type = 'myobject';

$error = 0;
$setupnotempty = 0;


// Set this to 1 to use the factory to manage constants. Warning, the generated module will be compatible with version v15+ only
$useFormSetup = 1;

if (!class_exists('FormSetup')) {
	require_once DOL_DOCUMENT_ROOT.'/core/class/html.formsetup.class.php'; // @phpstan-ignore requireOnce.fileNotFound (PHPStan takes DOL_DOCUMENT_ROOT from install/inc.php of the core, where it is '..')
}

$formSetup = new FormSetup($db);

$excludedUntdidCodes = array('PMT', 'PMD', 'AAB');

// Access control
if (!$user->admin) {
	accessforbidden();
}

$ProtocolManager = new ProtocolManager($db);
$protocolsList = $ProtocolManager->getProtocolsList();

// Protocols list
$TFieldProtocols = array();
foreach ($protocolsList as $key => $protocolconfig) {
	if ($protocolconfig['is_enabled'] == 0) {
		continue;
	}
	$TFieldProtocols[$key] = array('label' => $protocolconfig['protocol_name'], 'data-html' => $protocolconfig['protocol_label'] ?? $protocolconfig['protocol_name']);
	if (!empty($protocolconfig['protocol_dol_min'])) {
		$TFieldProtocols[$key]['data-html'] .= ' <span class="opacitymedium">(Dolibarr '.$protocolconfig['protocol_dol_min'].'+)</span>';
	}
	if ($protocolconfig['protocol_name'] == 'CII') {
		$TFieldProtocols[$key]['data-html'] .= ' <span class="opacitymedium">('.$langs->trans("Recommended").')</span>';
	}
	if (!empty($protocolconfig['is_greyed'])) {
		$TFieldProtocols[$key]['disabled'] = 1;
		$TFieldProtocols[$key]['data-html'] .= ' <span class="opacitymedium">('.$protocolconfig['is_greyed'].')</span>';
	}
}


// End of definition of parameters


//$dirmodels = array_merge(array('/'), (array) $conf->modules_parts['models']);
//$moduledir = 'einvoicing';


/*
 * Actions
 */

// Set the default protocol when no default value is specified
if (getDolGlobalString('EINVOICING_PDP') && !getDolGlobalString('EINVOICING_PROTOCOL')) {
	dolibarr_set_const($db, 'EINVOICING_PROTOCOL', 'CII', 'chaine', 0, '', $conf->entity);
	header("Location: ".$_SERVER["PHP_SELF"]);
	exit;
}

if ($action == 'savesyncoptions') {
	$value = GETPOSTINT('EINVOICING_DISABLE_SYNC_DOLI_TO_AP') ? '0' : '1';
	dolibarr_set_const($db, "EINVOICING_DISABLE_SYNC_DOLI_TO_AP", $value, 'chaine', 0, '', $conf->entity);

	header("Location: ".$_SERVER["PHP_SELF"]);
	exit;
}

// If we use the test mode, sync supplier invoices is not available
//if (getDolGlobalString('EINVOICING_PDP') == 'TESTPDP') {
//	$conf->global->EINVOICING_DISABLE_SYNC_AP_TO_DOLI = 1;
//}

if (!getDolGlobalString('EINVOICING_DISABLE_SYNC_DOLI_TO_AP')) {
	$itemtitle = $formSetup->newItem('EINVOICING_SYNC_TO_PA');
	$itemtitle->setAsTitle();
	$itemtitle->nameText = '<b>'.$langs->trans("EINVOICING_SYNC_TO_PA").'</b>';

	$item = $formSetup->newItem('EINVOICING_PROTOCOL')->setAsSelect($TFieldProtocols);
	$item->helpText = $langs->transnoentities('EINVOICING_PROTOCOL_HELP');
	$item->defaultFieldValue = 'CII';
	$item->cssClass = 'minwidth500';
	$item->fieldParams['trClass'] = 'advancedoption';

	// Setup conf to choose use of auto generation or not of products
	$item = $formSetup->newItem('EINVOICING_EINVOICE_IN_REAL_TIME')->setAsYesNo();
	$item->helpText = $langs->transnoentities('EINVOICING_EINVOICE_IN_REAL_TIME');
	$item->defaultFieldValue = '0';
	$item->cssClass = 'minwidth500';
	$item->fieldParams['forcereload'] = 1;

	// Setup conf to enable third-party validation via government APIs (SIREN via data.gouv.fr and VAT via VIES)
	$item = $formSetup->newItem('EINVOICING_ENABLE_API_VALIDATION')->setAsYesNo();
	$item->helpText = $langs->transnoentities('EINVOICING_ENABLE_API_VALIDATION_HELP');
	$item->defaultFieldValue = '0';
	//$item->fieldParams['warningifon'] = 1;
	$item->cssClass = 'minwidth500';

	// Local EN 16931 business rules check (BR, BR-CO, BR-FR subset) on the generated XML.
	// Single option with three modes: no check, check and warn only (default), or check and
	// block the generation on any violation. The official Schematron of the Approved Platform
	// stays the reference.
	$item = $formSetup->newItem('EINVOICING_BR_CHECK')->setAsSelect(array(
		'nocheck' => $langs->transnoentities('EINVOICING_BR_CHECK_NOCHECK'),
		'warning_only' => $langs->transnoentities('EINVOICING_BR_CHECK_WARNING_ONLY'),
		'blocking' => $langs->transnoentities('EINVOICING_BR_CHECK_BLOCKING'),
	));
	$item->helpText = $langs->transnoentities('EINVOICING_BR_CHECK_HELP');
	$item->defaultFieldValue = 'warning_only';
	$item->cssClass = 'minwidth500';

	// Setup conf to precheck the e-invoice with the Access Point validation service if available.
	if (getDolGlobalString('EINVOICING_PDP') && !getDolGlobalString('EINVOICING_ONLY_GENERATE')) {
		$PDPManager = new PDPProviderManager($db);

		$provider = $PDPManager->getProvider(getDolGlobalString('EINVOICING_PDP'));

		$providerconfig  = $provider->getConf();

		$hasValidator = $providerconfig['has_validator'];
		if ($hasValidator) {
			$item = $formSetup->newItem('EINVOICING_AP_PRECHECK')->setAsSelect(array(
				'nocheck' => $langs->transnoentities('EINVOICING_AP_PRECHECK_NOCHECK'),
				'manuel' => $langs->transnoentities('EINVOICING_AP_PRECHECK_MANUEL'),
				'auto' => $langs->transnoentities('EINVOICING_AP_PRECHECK_AUTO'),
			));
			$item->helpText = $langs->transnoentities('EINVOICING_AP_PRECHECK_HELP');
			$item->defaultFieldValue = 'nocheck';
			$item->cssClass = 'minwidth500';
		}
	}


	if (getDolGlobalString('EINVOICING_EINVOICE_IN_REAL_TIME')) {
		$item = $formSetup->newItem('EINVOICING_EINVOICE_CANCEL_IF_EINVOICE_FAILS')->setAsYesNo();
		$item->helpText = $langs->transnoentities('EINVOICING_EINVOICE_CANCEL_IF_EINVOICE_FAILS').'<br>'.$langs->transnoentities('EINVOICING_EINVOICE_CANCEL_IF_EINVOICE_FAILS2');
		$item->defaultFieldValue = '0';
		$item->cssClass = 'minwidth500';
	}

	// Setup conf to choose to block generation/send of an invoice if no routing ID is found for the third party otherwise use SIREN
	/* Option no more useful due to other added option that are more accurate, but we can still use it as hidden option
	$item = $formSetup->newItem('EINVOICING_BLOCK_INVOICE_NO_ROUTING_ID')->setAsYesNo();
	$item->helpText = $langs->transnoentities('EINVOICING_BLOCK_INVOICE_NO_ROUTING_ID_HELP');
	$item->defaultFieldValue = '0';
	$item->cssClass = 'minwidth500';
	$item->fieldParams['forcereload'] = 0;
	*/

	// Setup conf to check the recipient reachability in the Approved Platforms directory (annuaire PA) before
	// sending, and surface it on the invoice card. On by default. A read-only directory lookup: it never blocks.
	// Meaningless once EINVOICING_ONLY_GENERATE is on: nothing is ever sent, so there is no recipient to reach.
	if (!getDolGlobalString('EINVOICING_ONLY_GENERATE')) {
		$item = $formSetup->newItem('EINVOICING_PRECHECK_DIRECTORY')->setAsYesNo();
		$item->helpText = $langs->transnoentities('EINVOICING_PRECHECK_DIRECTORY_HELP');
		$item->defaultFieldValue = '0';
		$item->cssClass = 'minwidth500';

		// Setup conf to REQUIRE the recipient to be routable in the directory before generating/sending. Off by
		// default (opt-in enforcement on top of the read-only pre-check above): blocks reaching a routing reject.
		// Value 2 blocks the non-conclusive directory answer too (recipient known, status of its reception
		// address not reported). Formerly a yes/no, whose two values keep their meaning here.
		$item = $formSetup->newItem('EINVOICING_REQUIRE_ROUTABLE_RECIPIENT')->setAsSelect(array(
			'0' => $langs->transnoentities('EINVOICING_REQUIRE_ROUTABLE_RECIPIENT_OFF'),
			'1' => $langs->transnoentities('EINVOICING_REQUIRE_ROUTABLE_RECIPIENT_ON'),
			'2' => $langs->transnoentities('EINVOICING_REQUIRE_ROUTABLE_RECIPIENT_STRICT'),
		));
		$item->helpText = $langs->transnoentities('EINVOICING_REQUIRE_ROUTABLE_RECIPIENT_HELP');
		$item->defaultFieldValue = '0';
		$item->cssClass = 'minwidth500';
	}

	// Setup conf to skip e-invoicing for B2C third parties (private individuals): out of the e-invoicing
	// scope (e-reporting applies instead). Off by default. Company vs individual detection is delegated to
	// Societe::isACompany() (and its own options), so there is nothing extra to configure here.
	$item = $formSetup->newItem('EINVOICING_SKIP_B2C')->setAsYesNo();
	$item->helpText = $langs->transnoentities('EINVOICING_SKIP_B2C_HELP');
	$item->defaultFieldValue = '0';
	$item->cssClass = 'minwidth500';

	// Setup list of POS modules - Do not generate einvoice
	$item = $formSetup->newItem('EINVOICING_NAME_OF_MODULESOURCE_THAT_ARE_POS');
	$item->helpText = $langs->transnoentities('EINVOICING_NAME_OF_MODULESOURCE_THAT_ARE_POS_HELP');
	$item->defaultFieldValue = getDolGlobalString('EINVOICING_NAME_OF_MODULESOURCE_THAT_ARE_POS', 'takepos');
	$item->cssClass = 'minwidth500';

	// The scheme the party identifier (BT-29, BT-46) is declared under. A list for a French company,
	// whose admissible values the specification names, and a free field for any other country, where
	// the module has no table of registers and would otherwise declare a national identifier as a DUNS.
	if ($mysoc->country_code == 'FR') {
		$item = $formSetup->newItem('EINVOICING_PARTY_IDENTIFIER_SCHEME')->setAsSelect(array(
			'0225' => $langs->transnoentities('EINVOICING_PARTY_IDENTIFIER_SCHEME_0225'),
			'0009' => $langs->transnoentities('EINVOICING_PARTY_IDENTIFIER_SCHEME_0009'),
			'none' => $langs->transnoentities('EINVOICING_PARTY_IDENTIFIER_SCHEME_NONE'),
		));
		$item->defaultFieldValue = '0225';
	} else {
		// Left empty on purpose outside France: an empty value keeps the code the module has always
		// answered for that country, and 0225 is a French scheme that would be wrong anywhere else.
		$item = $formSetup->newItem('EINVOICING_PARTY_IDENTIFIER_SCHEME');
		$item->fieldAttr['placeholder'] = $langs->transnoentities('EINVOICING_PARTY_IDENTIFIER_SCHEME_PLACEHOLDER');
	}
	$item->helpText = $langs->transnoentities('EINVOICING_PARTY_IDENTIFIER_SCHEME_HELP');
	$item->cssClass = 'minwidth500';

	// Setup conf to automatically transmit the e-invoice to the PA right after it is generated (on validation)
	if (!getDolGlobalString('EINVOICING_ONLY_GENERATE')) {
		$item = $formSetup->newItem('EINVOICING_AUTO_SEND_ON_GENERATION')->setAsYesNo();
		$item->helpText = $langs->transnoentities('EINVOICING_AUTO_SEND_ON_GENERATION_HELP');
		$item->defaultFieldValue = '0';
		$item->cssClass = 'minwidth500';
	}

	// Setup conf for maximum e-invoice file size (warning if exceeded)
	$item = $formSetup->newItem('EINVOICING_MAX_FILE_SIZE_MB');
	$item->helpText = $langs->transnoentities('EINVOICING_MAX_FILE_SIZE_MB_HELP');
	$item->cssClass = 'maxwidth100';
	$item->fieldAttr['type'] = 'number';
	$item->fieldAttr['min'] = '0';
	$item->fieldAttr['step'] = '0.1';

	// The three notices below are never sent empty: the generation falls back on the translations the
	// placeholders show here, so the page states what will be written instead of keeping a silent
	// default. Shown to a French seller only: their wording states French law, which is a promise the
	// module has no business putting in the mouth of a seller established anywhere else.
	$isfrenchseller = ($mysoc->country_code == 'FR');
	$noticedefaulthelp = $isfrenchseller ? ' '.$langs->transnoentities('EINVOICING_LEGAL_NOTICE_DEFAULT_HELP') : '';

	/*
	$vatexigibility = $langs->trans(getDolGlobalString('TAX_MODE_SELL_PRODUCT') == 'payment' ? 'OnPayment' : 'OnInvoice');
	$vatexigibility .= ' / ';
	$vatexigibility .= $langs->trans(getDolGlobalString('TAX_MODE_SELL_SERVICE') == 'payment' ? 'OnPayment' : 'OnInvoice');
	*/
	$conf->global->EINVOICING_TELL_CUSTOMER_PAYMENT_RECEIVED = $langs->trans("Mandatory");
	$item = $formSetup->newItem('EINVOICING_TELL_CUSTOMER_PAYMENT_RECEIVED');
	$item->helpText = $langs->trans('EINVOICING_TELL_CUSTOMER_PAYMENT_RECEIVED_HELP');
	$item->fieldInputOverride = $langs->trans("Mandatory");
	//$item->enabled = 0;
	$item->cssClass = 'opacitymedium';

	/*
	$itemtitle->helpText = $langs->trans('EINVOICING_VAT_EXIGIBILITY_HELP').' <b>'
			.dol_escape_htmltag($vatexigibility)
			.'</b> <a href="'.DOL_URL_ROOT.'/admin/taxes.php">'.$langs->trans('Setup').'</a>';
	*/

	// Setup conf for PMT - Mention regarding recovery fees
	$item = $formSetup->newItem('EINVOICING_PMT');
	$item->helpText = $langs->transnoentities('EINVOICING_PMT_HELP').$noticedefaulthelp;
	if ($isfrenchseller) {
		$item->fieldAttr['placeholder'] = $langs->transnoentities('RecoveryFeesMention');
	}
	$item->cssClass = 'minwidth500';

	// Setup conf for PMD - Mention regarding late payment penalties
	$item = $formSetup->newItem('EINVOICING_PMD');
	$item->helpText = $langs->transnoentities('EINVOICING_PMD_HELP').$noticedefaulthelp;
	if ($isfrenchseller) {
		$item->fieldAttr['placeholder'] = $langs->transnoentities('LatePaymentPenaltiesMention');
	}
	$item->cssClass = 'minwidth500';

	// Setup conf for AAB - Mention regarding absence of discount for early payment
	$item = $formSetup->newItem('EINVOICING_AAB');
	$item->helpText = $langs->transnoentities('EINVOICING_AAB_HELP').$noticedefaulthelp;
	if ($isfrenchseller) {
		$item->fieldAttr['placeholder'] = $langs->transnoentities('EarlyPaymentDiscountMention');
	}
	$item->cssClass = 'minwidth500';
}

if ($action == 'save_extra_untdid' && GETPOST('token', 'alpha') === newToken()) {
	$code = strtoupper(trim(GETPOST('untdid_code', 'aZ09')));
	$text = trim(GETPOST('untdid_text', 'restricthtml'));

	if (!   einvoicingIsActiveUntdid4451Code($db, $code, $conf->entity, $excludedUntdidCodes)) {
		setEventMessages($langs->trans('EINVOICING_INVALID_UNTDID_CODE'), null, 'errors');
	} elseif ($text === '') {
		setEventMessages($langs->trans('EINVOICING_EMPTY_UNTDID_TEXT'), null, 'errors');
	} else {
		$constantName = 'EINVOICING_EXTRA_'.$code;
		dolibarr_set_const($db, $constantName, $text, 'chaine', 0, '', $conf->entity);
		setEventMessages($langs->trans('EINVOICING_EXTRA_MENTION_SAVED', $code), null, 'mesgs');
	}

	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

if ($action == 'delete_extra_untdid' && GETPOST('token', 'alpha') === newToken()) {
	$code = strtoupper(trim(GETPOST('untdid_code', 'aZ09')));

	if (!einvoicingIsActiveUntdid4451Code($db, $code, $conf->entity, $excludedUntdidCodes)) {
		setEventMessages($langs->trans('EINVOICING_INVALID_UNTDID_CODE'), null, 'errors');
	} else {
		$constantName = 'EINVOICING_EXTRA_'.$code;
		dolibarr_del_const($db, $constantName, $conf->entity);
		setEventMessages($langs->trans('EINVOICING_EXTRA_MENTION_DELETED', $code), null, 'mesgs');
	}

	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

// @phpstan-ignore include.fileNotFound
include DOL_DOCUMENT_ROOT.'/core/actions_setmoduleoptions.inc.php';

//print getDolGlobalString('EINVOICING_PDP');



/*
 * View
 */

$action = 'edit';

$form = new Form($db);

$help_url = 'EN:Module_EInvoicing';
$title = "OptionsEInvoicingSend";

// @phpstan-ignore arguments.count (llxHeader is declared without parameters in the Dolibarr 18 analysis)
llxHeader('', $langs->trans($title), $help_url, '', 0, 0, '', '', '', 'mod-einvoicing page-admin-optionssend');

// Subheader
$linkback = '<a href="'.($backtopage ? $backtopage : DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1').'">'.img_picto($langs->trans("BackToModuleList"), 'back', 'class="pictofixedwidth"').'<span class="hideonsmartphone">'.$langs->trans("BackToModuleList").'</span></a>';

print load_fiche_titre($langs->trans($title), $linkback, 'title_setup');


// Configuration header
$head = einvoicingAdminPrepareHead();
print dol_get_fiche_head($head, 'send', $langs->trans($title), -1, "einvoicing.png@einvoicing");

// Setup page goes here
//print info_admin($langs->trans("EInvoicingInfo"));
//print '<span class="opacitymedium">'.$langs->trans("EInvoicingSetupPage").'</span><br>';

// Alert mysoc configuration is not complete
$einvoicing = new EInvoicing($db);

//$stringwarning = pdpShowWarning($einvoicing);
//print $stringwarning;

print '<form name="options" action="'.$_SERVER["PHP_SELF"].'" method="POST">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="savesyncoptions">';

print '<div class="neutral">';

print img_picto('', 'bill', 'class="pictofixedwidth"').$langs->trans("EnableInvoiceExport").' ';
print $form->selectyesno("EINVOICING_DISABLE_SYNC_DOLI_TO_AP", GETPOSTISSET("EINVOICING_DISABLE_SYNC_DOLI_TO_AP") ? GETPOSTINT('EINVOICING_DISABLE_SYNC_DOLI_TO_AP') : !getDolGlobalString('EINVOICING_DISABLE_SYNC_DOLI_TO_AP'), 1, false, 0, 1);
print '<br>';

print '</div>';

print '<div class="center">';
print '<input type="submit" name="save" class="button" value="'.$langs->trans("Save").'">';
print "</div>";

print '</form>';


/*
print '<br><br><br>';


if (!empty($formSetupAP2Doli->items)) {
	if ((float) DOL_VERSION < 24.0) {
		print load_fiche_titre($langs->trans("EINVOICING_AUTO_GENERATION"));
	}

	print $formSetupAP2Doli->generateOutput(true, false, $langs->trans("EINVOICING_AUTO_GENERATION"));
	print '<br><br>';
	if ((float) DOL_VERSION >= 24.0) {
		print '<br>';
	}
}

if (!empty($formSetupDoli2AP->items)) {
	if ((float) DOL_VERSION < 24.0) {
		print load_fiche_titre($langs->trans("EINVOICING_SYNC_TO_PA"));
	}

	print $formSetupDoli2AP->generateOutput(true, false, $langs->trans("EINVOICING_SYNC_TO_PA"));
	print '<br><br>';
	if ((float) DOL_VERSION >= 24.0) {
		print '<br>';
	}
}
*/

if (!empty($formSetup->items)) {
	print '<br><br>';

	print $formSetup->generateOutput(true, true);
	print '<br>';
}

$excludedUntdidCodes = array('PMT', 'PMD', 'AAB');

$extraUntdidCodes = einvoicingGetActiveUntdid4451Codes($db, $langs, $conf->entity, $excludedUntdidCodes);

print '<br>';
print load_fiche_titre($langs->trans('EINVOICING_EXTRA_MENTIONS'), '', 'title_setup');
print '<div class="opacitymedium">'.$langs->trans('EINVOICING_EXTRA_MENTIONS_HELP').'</div>';
print '<br>';

// Formulaire d'ajout ou de modification.
print '<form method="POST" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="save_extra_untdid">';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td>'.$langs->trans('EINVOICING_UNTDID_CODE').'</td>';
print '<td>'.$langs->trans('EINVOICING_UNTDID_DESCRIPTION').'</td>';
print '<td>'.$langs->trans('EINVOICING_MENTION_TEXT').'</td>';
print '<td></td>';
print '</tr>';
print '<tr class="oddeven">';

print '<td>';
print '<select name="untdid_code" id="untdid_code" class="minwidth200">';
print '<option value="">-- '.$langs->trans('Select'). ' --</option>';
foreach ($extraUntdidCodes as $code => $codeData) {
	$constantName = 'EINVOICING_EXTRA_'.$code;
	$existingText = getDolGlobalString($constantName);
	print '<option value="'.dol_escape_htmltag($code).'"';
	print ' data-description="'.dol_escape_htmltag($codeData['label']).'"';
	print ' data-existing-text="'.dol_escape_htmltag($existingText).'"';
	print '>'.dol_escape_htmltag($code.' - '.$codeData['label']).'</option>';
}
print '</select>';
print '</td>';

print '<td><span id="untdid_description" class="opacitymedium"></span></td>';
print '<td><textarea name="untdid_text" id="untdid_text" class="minwidth500" rows="4"></textarea></td>';
print '<td><input type="submit" class="button" value="'.$langs->trans('Add').'"></td>';
print '</tr>';
print '</table>';
print '</form>';

print '<br>';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td>'.$langs->trans('EINVOICING_UNTDID_CODE').'</td>';
print '<td>'.$langs->trans('EINVOICING_UNTDID_DESCRIPTION').'</td>';
print '<td>'.$langs->trans('EINVOICING_MENTION_TEXT').'</td>';
print '<td class="right">'.$langs->trans('Action').'</td>';
print '</tr>';

$hasExtraMention = false;
foreach ($extraUntdidCodes as $code => $codeData) {
	$constantName = 'EINVOICING_EXTRA_'.$code;
	$existingText = getDolGlobalString($constantName);
	if ($existingText === '') {
		continue;
	}

	$hasExtraMention = true;
	print '<tr class="oddeven">';
	print '<td><strong>'.dol_escape_htmltag($code).'</strong></td>';
	print '<td>'.dol_escape_htmltag($codeData['label']).'</td>';
	print '<td>'.dol_escape_htmltag($existingText).'</td>';
	print '<td class="right">';
	print '<a class="reposition" href="#" data-code="'.dol_escape_htmltag($code).'">'.$langs->trans('Modify').'</a> ';
	print '<a class="reposition" href="'.dol_escape_htmltag($_SERVER['PHP_SELF'].'?action=delete_extra_untdid&token='.newToken().'&untdid_code='.urlencode($code)).'" onclick="return confirm(\''.dol_escape_js($langs->trans('ConfirmDelete')).'\');">';
	print img_picto($langs->trans('Delete'), 'delete').'</a>';
	print '</td>';
	print '</tr>';
}

if (!$hasExtraMention) {
	print '<tr><td colspan="4" class="opacitymedium">'.$langs->trans('EINVOICING_NO_EXTRA_MENTION').'</td></tr>';
}
print '</table>';

print '<script>
$(document).ready(function() {
    // Mentions complémentaires UNTDID 4451
    var codeSelect = $("#untdid_code");
    var description = $("#untdid_description");
    var textArea = $("#untdid_text");

    if (codeSelect.length && description.length && textArea.length) {
        codeSelect.on("change", function() {
            var selectedOption = this.options[this.selectedIndex];

            description.text(
                selectedOption.getAttribute("data-description") || ""
            );

            textArea.val(
                selectedOption.getAttribute("data-existing-text") || ""
            );
        });

        $("[data-code]").on("click", function(event) {
            event.preventDefault();

            codeSelect.val($(this).data("code"));
            codeSelect.trigger("change");
            textArea.trigger("focus");
        });
    }

    // Recharger la page lorsque la PDP est modifiée
    var pdpSelect = $("select[name=\'EINVOICING_PDP\']");

    if (pdpSelect.length) {
        pdpSelect.on("change", function() {
            console.log("PDP changed, submit form to reload page");
            $(this).closest("form").submit();
        });
    }
});
</script>';

// Page end
print dol_get_fiche_end();

llxFooter();
$db->close();
