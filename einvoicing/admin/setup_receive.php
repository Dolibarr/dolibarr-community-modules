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
 * \file    einvoicing/admin/setup_receive.php
 * \ingroup einvoicing
 * \brief   EInvoicing setup page.
 */

// Load Dolibarr environment
$res = 0;
// Try main.inc.php into web root known defined into CONTEXT_DOCUMENT_ROOT (not always defined)
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
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
	$value = !GETPOST('EINVOICING_DISABLE_SYNC_AP_TO_DOLI') ? '1' : '0';
	dolibarr_set_const($db, "EINVOICING_DISABLE_SYNC_AP_TO_DOLI", $value, 'chaine', 0, '', $conf->entity);

	header("Location: ".$_SERVER["PHP_SELF"]);
	exit;
}

// If we use the test mode, sync supplier invoices is not available
//if (getDolGlobalString('EINVOICING_PDP') == 'TESTPDP') {
//	$conf->global->EINVOICING_DISABLE_SYNC_AP_TO_DOLI = 1;
//}

if (!einvoicingReceptionDisabled()) {			// If sync AP to DOLI is not disabled or we are not in a generate only mode
	// Setup conf for auto generation of objects
	$itemtitle = $formSetup->newItem('EINVOICING_AUTO_GENERATION');
	$itemtitle->setAsTitle();
	$itemtitle->nameText = '<b>'.$langs->trans("EINVOICING_AUTO_GENERATION").'</b>';

	// Setup conf to choose use of auto generation or not of products
	$item = $formSetup->newItem('EINVOICING_PRODUCTS_AUTO_GENERATION')->setAsYesNo();
	$item->helpText = $langs->transnoentities('EINVOICING_PRODUCTS_AUTO_GENERATION_HELP');
	$item->defaultFieldValue = '0';
	$item->cssClass = 'minwidth500';
	$item->fieldParams['forcereload'] = 1;
	$item->fieldParams['warningifon'] = 1;

	// Setup conf to import lines as free description lines when no product is found and no default product exist on supplier
	// This option is in conflict with EINVOICING_PRODUCTS_AUTO_GENERATION, so it is disabled if EINVOICING_PRODUCTS_AUTO_GENERATION is on
	if (!getDolGlobalString("EINVOICING_PRODUCTS_AUTO_GENERATION")) {
		$item = $formSetup->newItem('EINVOICING_IMPORT_AS_FREE_LINES')->setAsYesNo();
		$item->helpText = $langs->transnoentities('EINVOICING_IMPORT_AS_FREE_LINES_HELP');
		$item->defaultFieldValue = '0';
		$item->cssClass = 'minwidth500';
		$item->fieldParams['warningifon'] = 1;
	}

	// Setup conf to match a vendor product reference written with separators other than the recorded one.
	// Off by default: the comparison ignores separators, so it is an approximation.
	$item = $formSetup->newItem('EINVOICING_PRODUCTS_MATCH_CANONICAL_REF')->setAsYesNo();
	$item->helpText = $langs->transnoentities('EINVOICING_PRODUCTS_MATCH_CANONICAL_REF_HELP');
	$item->defaultFieldValue = '0';
	$item->cssClass = 'minwidth500';
	$item->fieldParams['warningifon'] = 1;

	// Setup conf to choose use of auto generation or not of third parties
	$item = $formSetup->newItem('EINVOICING_THIRDPARTIES_AUTO_GENERATION')->setAsYesNo();
	$item->helpText = $langs->transnoentities('EINVOICING_THIRDPARTIES_AUTO_GENERATION_HELP');
	$item->defaultFieldValue = '0';
	$item->cssClass = 'minwidth500';

	// Setup conf to enable complete third party information when receiving an invoice from from PDP
	$item = $formSetup->newItem('EINVOICING_THIRDPARTIES_COMPLETE_INFO')->setAsYesNo();
	$item->helpText = $langs->transnoentities('EINVOICING_THIRDPARTIES_COMPLETE_INFO_HELP');
	$item->defaultFieldValue = '0';
	$item->cssClass = 'minwidth500';

	// Setup conf to to enable a limit of flows to synchronize per one synchronization call
	/* This option is useless, should be always on. Disabling it is possible by editing hidden cosntant
	$item = $formSetup->newItem('EINVOICING_FLOWS_SYNC_CALL_LIMIT')->setAsYesNo();
	$item->helpText = $langs->transnoentities('EINVOICING_FLOWS_SYNC_CALL_LIMIT_HELP');
	$item->defaultFieldValue = '1';
	$item->cssClass = 'minwidth500';
	$item->fieldParams['forcereload'] = 1;
	*/

	// Setup conf to enable or not the consistency check on supplier invoice validation. Off by default:
	// it re-checks every e-invoice at validation, including the ones edited by hand afterwards, which is
	// a wider question than the one the import itself settles.
	$item = $formSetup->newItem('EINVOICING_SUPPLIER_INVOICE_CHECK_CONSISTENCY_ON_VALIDATION');
	$item->helpText = $langs->transnoentities('EINVOICING_SUPPLIER_INVOICE_CHECK_CONSISTENCY_ON_VALIDATION_HELP');
	$item->setAsYesNo();
	$item->defaultFieldValue = '0';
	$item->cssClass = 'minwidth500';

	// Tell the vendor that its invoice is approved (status 205) when the supplier invoice is validated so approved.
	// Off by default.
	$item = $formSetup->newItem('EINVOICING_SEND_APPROVED_ON_VALIDATION')->setAsYesNo();
	$item->helpText = $langs->transnoentities('EINVOICING_SEND_APPROVED_ON_VALIDATION_HELP');
	$item->defaultFieldValue = '0';
	$item->cssClass = 'minwidth500';

	// Tell the vendor of a supplier invoice that its payment has been sent (status 211), as soon as the
	// invoice is classified paid in Dolibarr. Optional status of the reform, hence off by default: it is
	// a courtesy to the vendor and it costs one platform flow per invoice.
	$item = $formSetup->newItem('EINVOICING_SEND_PAYMENT_SENT_STATUS')->setAsYesNo();
	$item->helpText = $langs->transnoentities('EINVOICING_SEND_PAYMENT_SENT_STATUS_HELP');
	$item->defaultFieldValue = '0';
	$item->cssClass = 'minwidth500';


	// Experimental options
	// EINVOICING_ENABLE_MANUAL_ACTION_QUEUE: This option log import blocked flow with the action to do so we can do it manually later.
	// Risk: unblocking action in a different order may result in undesirable side effects.

	// Activate postponeflow
	// EINVOICING_ENABLE_POSTPONE_FLOWS: This option postpone flow with the action to do so we can do it manually later.
	// Risk: very dangerous. continuing to process flows means changing the cursor, and when a new record is save, we lost
	// all postpone flow that were discarded.
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
$title = "OptionsEInvoicingReceive";

// @phpstan-ignore arguments.count (llxHeader is declared without parameters in the Dolibarr 18 analysis)
llxHeader('', $langs->trans($title), $help_url, '', 0, 0, '', '', '', 'mod-einvoicing page-admin-optionsreceive');

// Subheader
$linkback = '<a href="'.($backtopage ? $backtopage : DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1').'">'.img_picto($langs->trans("BackToModuleList"), 'back', 'class="pictofixedwidth"').'<span class="hideonsmartphone">'.$langs->trans("BackToModuleList").'</span></a>';

print load_fiche_titre($langs->trans($title), $linkback, 'title_setup');


// Configuration header
$head = einvoicingAdminPrepareHead();
print dol_get_fiche_head($head, 'receive', $langs->trans($title), -1, "einvoicing.png@einvoicing");

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

print img_picto('', 'supplier_invoice', 'class="pictofixedwidth"').$langs->trans("EnableInvoiceImport").' ';
print $form->selectyesno("EINVOICING_DISABLE_SYNC_AP_TO_DOLI", GETPOSTISSET("EINVOICING_DISABLE_SYNC_AP_TO_DOLI") ? GETPOSTINT("EINVOICNG_DISABLE_SYNC_AP_TO_DOLI") : !getDolGlobalString('EINVOICING_DISABLE_SYNC_AP_TO_DOLI'), 1, false, 0, 1);
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

// on change EINVOICING_PDP reload page to show specific configuration of selected PDP
print '<script>
$(document).ready(function() {
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
