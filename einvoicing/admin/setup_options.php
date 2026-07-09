<?php
/* Copyright (C) 2004-2017  Laurent Destailleur     <eldy@users.sourceforge.net>
 * Copyright (C) 2024       Frédéric France         <frederic.france@free.fr>
 * Copyright (C) 2025		SuperAdmin					<daoud.mouhamed@gmail.com>
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
 * \file    einvoicing/admin/setup_options.php
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
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res && file_exists("../../../../main.inc.php")) {
	$res = @include "../../../../main.inc.php";
}
if (!$res && file_exists("../../../../../main.inc.php")) {
	$res = @include "../../../../../main.inc.php";
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
 */
// Libraries
require_once DOL_DOCUMENT_ROOT."/core/lib/admin.lib.php";
require_once '../lib/einvoicing.lib.php';
require_once "../class/providers/PDPProviderManager.class.php";
require_once "../class/protocols/ProtocolManager.class.php";
require_once "../class/einvoicing.class.php";


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
	require_once DOL_DOCUMENT_ROOT.'/core/class/html.formsetup.class.php';
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
	$TFieldProtocols[$key] = array('label' => $protocolconfig['protocol_name']);
	if (!empty($protocolconfig['protocol_dol_min'])) {
		// With Esalink, we can use the Factur-X even on version lower than v24 because it accepts duplicate factur-x.xml inside the PDF.
		if ($protocolconfig['protocol_name'] != 'FACTURX' || getDolGlobalString('EINVOICING_PDP') != 'ESALINK') {
			$TFieldProtocols[$key]['data-html'] = $protocolconfig['protocol_name'].' <span class="opacitymedium">(Dolibarr '.$protocolconfig['protocol_dol_min'].'+)</span>';
		}
	}
	if ($protocolconfig['protocol_name'] == 'CII') {
		$TFieldProtocols[$key]['data-html'] = $protocolconfig['protocol_name'].' <span class="opacitymedium">('.$langs->trans("Recommended").')</span>';
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
	if (getDolGlobalString('EINVOICING_PDP') == 'ESALINK') {
		// Default protocol for ESALINK is Factur-x. TODO Change to CII ?
		dolibarr_set_const($db, 'EINVOICING_PROTOCOL', 'FACTURX', 'chaine', 0, '', $conf->entity);
	} else {
		dolibarr_set_const($db, 'EINVOICING_PROTOCOL', 'CII', 'chaine', 0, '', $conf->entity);
	}
	header("Location: ".$_SERVER["PHP_SELF"]);
	exit;
}

if ($action == 'savesyncoptions') {
	dolibarr_set_const($db, "EINVOICING_DISABLE_SYNC_AP_TO_DOLI", (int) !GETPOSTINT("EINVOICING_DISABLE_SYNC_AP_TO_DOLI"), 'chaine', 0, '', $conf->entity);
	dolibarr_set_const($db, "EINVOICING_DISABLE_SYNC_DOLI_TO_AP", (int) !GETPOSTINT("EINVOICING_DISABLE_SYNC_DOLI_TO_AP"), 'chaine', 0, '', $conf->entity);
}


if (!getDolGlobalString('EINVOICING_DISABLE_SYNC_DOLI_TO_AP')) {
	$itemtitle = $formSetup->newItem('EINVOICING_SYNC_TO_PA');
	$itemtitle->setAsTitle();
	$itemtitle->nameText = '<b>'.$langs->trans("EINVOICING_SYNC_TO_PA").'</b>';

	$item = $formSetup->newItem('EINVOICING_PROTOCOL')->setAsSelect($TFieldProtocols);
	$item->helpText = $langs->transnoentities('EINVOICING_PROTOCOL_HELP');
	$item->defaultFieldValue = 'FACTURX';
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


	if (getDolGlobalString('EINVOICING_EINVOICE_IN_REAL_TIME')) {
		$item = $formSetup->newItem('EINVOICING_EINVOICE_CANCEL_IF_EINVOICE_FAILS')->setAsYesNo();
		$item->helpText = $langs->transnoentities('EINVOICING_EINVOICE_CANCEL_IF_EINVOICE_FAILS').'<br>'.$langs->transnoentities('EINVOICING_EINVOICE_CANCEL_IF_EINVOICE_FAILS2');
		$item->defaultFieldValue = '0';
		$item->cssClass = 'minwidth500';
	}

	// Setup conf for PMT - Mention regarding recovery fees
	$item = $formSetup->newItem('EINVOICING_PMT');
	$item->helpText = $langs->transnoentities('EINVOICING_PMT_HELP');
	$item->cssClass = 'minwidth500';

	// Setup conf for PMD - Mention regarding late payment penalties
	$item = $formSetup->newItem('EINVOICING_PMD');
	$item->helpText = $langs->transnoentities('EINVOICING_PMD_HELP');
	$item->cssClass = 'minwidth500';

	// Setup conf for AAB - Mention regarding absence of discount for early payment
	$item = $formSetup->newItem('EINVOICING_AAB');
	$item->helpText = $langs->transnoentities('EINVOICING_AAB_HELP');
	$item->cssClass = 'minwidth500';

	// Setup conf to choose to block generation/send of an invoice if no routing ID is found for the third party otherwise use SIREN
	$item = $formSetup->newItem('EINVOICING_BLOCK_INVOICE_NO_ROUTING_ID')->setAsYesNo();
	$item->helpText = $langs->transnoentities('EINVOICING_BLOCK_INVOICE_NO_ROUTING_ID_HELP');
	$item->defaultFieldValue = '0';
	$item->cssClass = 'minwidth500';
	$item->fieldParams['forcereload'] = 0;

	// Setup conf to check the recipient reachability in the Approved Platforms directory (annuaire PA) before
	// sending, and surface it on the invoice card. On by default. A read-only directory lookup: it never blocks.
	$item = $formSetup->newItem('EINVOICING_PRECHECK_DIRECTORY')->setAsYesNo();
	$item->helpText = $langs->transnoentities('EINVOICING_PRECHECK_DIRECTORY_HELP');
	$item->defaultFieldValue = '1';
	$item->cssClass = 'minwidth500';

	// Setup conf to REQUIRE the recipient to be routable in the directory before generating/sending. Off by
	// default (opt-in enforcement on top of the read-only pre-check above): blocks reaching a routing reject.
	$item = $formSetup->newItem('EINVOICING_REQUIRE_ROUTABLE_RECIPIENT')->setAsYesNo();
	$item->helpText = $langs->transnoentities('EINVOICING_REQUIRE_ROUTABLE_RECIPIENT_HELP');
	$item->defaultFieldValue = '0';
	$item->cssClass = 'minwidth500';

	// Setup conf to skip e-invoicing for B2C third parties (private individuals): out of the e-invoicing
	// scope (e-reporting applies instead). Off by default. Company vs individual detection is delegated to
	// Societe::isACompany() (and its own options), so there is nothing extra to configure here.
	$item = $formSetup->newItem('EINVOICING_SKIP_B2C')->setAsYesNo();
	$item->helpText = $langs->transnoentities('EINVOICING_SKIP_B2C_HELP');
	$item->defaultFieldValue = '0';
	$item->cssClass = 'minwidth500';

	// Setup conf to automatically transmit the e-invoice to the PA right after it is generated (on validation)
	$item = $formSetup->newItem('EINVOICING_AUTO_SEND_ON_GENERATION')->setAsYesNo();
	$item->helpText = $langs->transnoentities('EINVOICING_AUTO_SEND_ON_GENERATION_HELP');
	$item->defaultFieldValue = '0';
	$item->cssClass = 'minwidth500';

	// Allow re-sending / re-editing an invoice already transmitted to the Access Point. Off by default:
	// a transmitted invoice is immutable (correct it with a credit note / corrective invoice), and re-sending
	// makes the PA refuse a duplicate. Turn on only to deliberately test PA retry behaviour.
	$item = $formSetup->newItem('EINVOICING_ALLOW_RESEND_TRANSMITTED')->setAsYesNo();
	$item->nameText = $langs->trans("EINVOICING_ALLOW_RESEND_TRANSMITTED").' <span class="opacitymedium">('.$langs->trans("EINVOICING_TRANSMITTED_NOT_FOR_PROD").')</span>';
	$item->defaultFieldValue = '0';
	$item->helpText = $langs->transnoentities('EINVOICING_ALLOW_RESEND_TRANSMITTED_HELP');
	$item->cssClass = 'minwidth500';

	// Dev-only: keep the "Regenerate e-invoice" button/action available on a transmitted-locked invoice
	// (rebuild the CII/Factur-X to inspect the XML). Re-sending stays locked. Off by default.
	$item = $formSetup->newItem('EINVOICING_ALLOW_REGEN_TRANSMITTED')->setAsYesNo();
	$item->nameText = $langs->trans("EINVOICING_ALLOW_REGEN_TRANSMITTED").' <span class="opacitymedium">('.$langs->trans("EINVOICING_TRANSMITTED_NOT_FOR_PROD").')</span>';
	$item->defaultFieldValue = '0';
	$item->helpText = $langs->transnoentities('EINVOICING_ALLOW_REGEN_TRANSMITTED_HELP');
	$item->cssClass = 'minwidth500';

	// Setup conf for maximum e-invoice file size (warning if exceeded)
	$item = $formSetup->newItem('EINVOICING_MAX_FILE_SIZE_MB');
	$item->helpText = $langs->transnoentities('EINVOICING_MAX_FILE_SIZE_MB_HELP');
	$item->cssClass = 'maxwidth100';
	$item->fieldAttr['type'] = 'number';
	$item->fieldAttr['min'] = '0';
	$item->fieldAttr['step'] = '0.1';
}


if (!getDolGlobalString('EINVOICING_DISABLE_SYNC_AP_TO_DOLI')) {
	// Setup conf for auto generation of objects
	$itemtitle = $formSetup->newItem('EINVOICING_AUTO_GENERATION');
	$itemtitle->setAsTitle();
	$itemtitle->nameText = '<b>'.$langs->trans("EINVOICING_AUTO_GENERATION").'</b>';

	// Setup conf to choose use of auto generation or not of products
	$item = $formSetup->newItem('EINVOICING_PRODUCTS_AUTO_GENERATION')->setAsYesNo();
	$item->helpText = $langs->transnoentities('EINVOICING_PRODUCTS_AUTO_GENERATION_HELP');
	$item->defaultFieldValue = '0';
	$item->cssClass = 'minwidth500';

	// Setup conf to import lines as free description lines when no product is found
	$item = $formSetup->newItem('EINVOICING_IMPORT_AS_FREE_LINES')->setAsYesNo();
	$item->helpText = $langs->transnoentities('EINVOICING_IMPORT_AS_FREE_LINES_HELP');
	$item->defaultFieldValue = '0';
	$item->cssClass = 'minwidth500';

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
	$item = $formSetup->newItem('EINVOICING_FLOWS_SYNC_CALL_LIMIT')->setAsYesNo();
	$item->helpText = $langs->transnoentities('EINVOICING_FLOWS_SYNC_CALL_LIMIT_HELP');
	$item->defaultFieldValue = '1';
	$item->cssClass = 'minwidth500';
	$item->fieldParams['forcereload'] = 1;

	if (getDolGlobalString('EINVOICING_FLOWS_SYNC_CALL_LIMIT')) {
		// Setup conf to to define the number of flows to synchronize per one synchronization call
		$item = $formSetup->newItem('EINVOICING_FLOWS_SYNC_CALL_SIZE');
		$item->helpText = $langs->transnoentities('EINVOICING_FLOWS_SYNC_CALL_SIZE_HELP');
		$item->defaultFieldValue = '100';
		$item->cssClass = 'maxwidth100';
	}

	// Setup conf to define a time margin in hours to go back from the current date of the last synchronization
	$item = $formSetup->newItem('EINVOICING_SYNC_MARGIN_TIME_HOURS');
	$item->helpText = $langs->transnoentities('EINVOICING_SYNC_MARGIN_TIME_HOURS_HELP');
	$item->fieldAttr['placeholder'] = $langs->transnoentities('Hours');
	$item->cssClass = 'maxwidth100';

	/* Keep this option as hidden as it is too bugged and not really useful
	// Setup conf to enable or not the consistency check on supplier invoice validation
	$item = $formSetup->newItem('EINVOICING_SUPPLIER_INVOICE_CHECK_CONSISTENCY_ON_VALIDATION');
	$item->helpText = $langs->transnoentities('EINVOICING_SUPPLIER_INVOICE_CHECK_CONSISTENCY_ON_VALIDATION_HELP');
	$item->setAsYesNo();
	*/

	if (getDolGlobalString('EINVOICING_SUPPLIER_INVOICE_CHECK_CONSISTENCY_ON_VALIDATION')) {
		$item = $formSetup->newItem('EINVOICING_SUPPLIER_INVOICE_COMPARISON_ROUND_PRECISION');
		// $item->setAsNumber(2, 10, 1); // not in < v22
		$item->fieldAttr['type'] = 'number';
		$item->fieldAttr['min'] = 2;
		$item->fieldAttr['max'] = 10;
		$item->fieldAttr['step'] = 1;
		$item->defaultFieldValue = '3';
	}
}


if (!getDolGlobalString('EINVOICING_DISABLE_SYNC_AP_TO_DOLI') || !getDolGlobalString('EINVOICING_DISABLE_SYNC_DOLI_TO_AP')) {
	$itemtitle = $formSetup->newItem('EINVOICING_DEBUG')->setAsTitle();
	$itemtitle->nameText = '<b>'.$langs->trans("Other").'</b>';

	// Setup conf to choose to use Chorus or not
	$item = $formSetup->newItem('EINVOICING_USE_CHORUS')->setAsYesNo();
	$item->nameText = $langs->trans("EINVOICING_USE_CHORUS").' <span class="opacitymedium">('.$langs->trans("FeatureNotYetSupported").')</span>';
	$item->helpText = $langs->transnoentities('EINVOICING_USE_CHORUS_HELP');
	$item->cssClass = 'minwidth500';

	// Setup conf to enable or not debug mode
	$item = $formSetup->newItem('EINVOICING_DEBUG_MODE')->setAsYesNo();
	$item->helpText = $langs->transnoentities('EINVOICING_DEBUG_MODE_HELP');
	$item->defaultFieldValue = '0';
	$item->cssClass = 'minwidth500';
	$item->fieldParams['warningifon'] = 1;
}

include DOL_DOCUMENT_ROOT.'/core/actions_setmoduleoptions.inc.php';

//print getDolGlobalString('EINVOICING_PDP');



/*
 * View
 */

$action = 'edit';

$form = new Form($db);

$help_url = 'EN:Module_EInvoicing';
$title = "EInvoicingSetup";

llxHeader('', $langs->trans($title), $help_url, '', 0, 0, '', '', '', 'mod-einvoicing page-admin-options');

// Subheader
$linkback = '<a href="'.($backtopage ? $backtopage : DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1').'">'.img_picto($langs->trans("BackToModuleList"), 'back', 'class="pictofixedwidth"').'<span class="hideonsmartphone">'.$langs->trans("BackToModuleList").'</span></a>';

print load_fiche_titre($langs->trans($title), $linkback, 'title_setup');


// Configuration header
$head = einvoicingAdminPrepareHead();
print dol_get_fiche_head($head, 'options', $langs->trans($title), -1, "einvoicing.png@einvoicing");

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
print $form->selectyesno("EINVOICING_DISABLE_SYNC_AP_TO_DOLI", GETPOSTISSET("EINVOICING_DISABLE_SYNC_AP_TO_DOLI") ? GETPOSTINT("EINVOICING_DISABLE_SYNC_AP_TO_DOLI") : !getDolGlobalString('EINVOICING_DISABLE_SYNC_AP_TO_DOLI'), 1, false, 0, 1);
print '<br><br>';

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
