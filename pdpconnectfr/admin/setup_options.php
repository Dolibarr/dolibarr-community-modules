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
 * \file    pdpconnectfr/admin/setup.php
 * \ingroup pdpconnectfr
 * \brief   PDPConnectFR setup page.
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
require_once '../lib/pdpconnectfr.lib.php';
require_once "../class/providers/PDPProviderManager.class.php";
require_once "../class/protocols/ProtocolManager.class.php";
require_once "../class/pdpconnectfr.class.php";


// Translations
$langs->loadLangs(array("admin", "pdpconnectfr@pdpconnectfr"));

// Initialize a technical object to manage hooks of page. Note that conf->hooks_modules contains an array of hook context
/** @var HookManager $hookmanager */
$hookmanager->initHooks(array('pdpconnectfrsetup', 'globalsetup'));

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

// Access control
if (!$user->admin) {
	accessforbidden();
}


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


// Enter here all parameters in your setup page
/*
// Setup conf for selection of an URL
$item = $formSetup->newItem('PDPCONNECTFR_MYPARAM1');
$item->fieldParams['isMandatory'] = 1;
$item->fieldAttr['placeholder'] = (empty($_SERVER['HTTPS']) ? 'http://' : 'https://') . $_SERVER['HTTP_HOST'];
$item->cssClass = 'minwidth500';

// Setup conf for selection of a simple string input
$item = $formSetup->newItem('PDPCONNECTFR_MYPARAM2');
$item->defaultFieldValue = 'default value';
$item->fieldAttr['placeholder'] = 'A placeholder here';
$item->helpText = 'Tooltip text';

// Setup conf for selection of a simple textarea input but we replace the text of field title
$item = $formSetup->newItem('PDPCONNECTFR_MYPARAM3');
$item->nameText = $item->getNameText().' more html text ';

// Setup conf for a selection of a Thirdparty
$item = $formSetup->newItem('PDPCONNECTFR_MYPARAM4');
$item->setAsThirdpartyType();

// Setup conf for a selection of a boolean
$formSetup->newItem('PDPCONNECTFR_MYPARAM5')->setAsYesNo();

// Setup conf for a selection of an Email template of type thirdparty
$formSetup->newItem('PDPCONNECTFR_MYPARAM6')->setAsEmailTemplate('thirdparty');

// Setup conf for a selection of a secured key
//$formSetup->newItem('PDPCONNECTFR_MYPARAM7')->setAsSecureKey();

// Setup conf for a selection of a Product
$formSetup->newItem('PDPCONNECTFR_MYPARAM8')->setAsProduct();

// Add a title for a new section
$formSetup->newItem('NewSection')->setAsTitle();

$TField = array(
	'test01' => $langs->trans('test01'),
	'test02' => $langs->trans('test02'),
	'test03' => $langs->trans('test03'),
	'test04' => $langs->trans('test04'),
	'test05' => $langs->trans('test05'),
	'test06' => $langs->trans('test06'),
);

// Setup conf for a simple combo list
$formSetup->newItem('PDPCONNECTFR_MYPARAM9')->setAsSelect($TField);

// Setup conf for a multiselect combo list
$item = $formSetup->newItem('PDPCONNECTFR_MYPARAM10');
$item->setAsMultiSelect($TField);
$item->helpText = $langs->transnoentities('PDPCONNECTFR_MYPARAM10');

// Setup conf for a category selection
$formSetup->newItem('PDPCONNECTFR_CATEGORY_ID_XXX')->setAsCategory('product');

// Setup conf PDPCONNECTFR_MYPARAM10
$item = $formSetup->newItem('PDPCONNECTFR_MYPARAM10');
$item->setAsColor();
$item->defaultFieldValue = '#FF0000';
//$item->fieldValue = '';
//$item->fieldAttr = array() ; // fields attribute only for compatible fields like input text
//$item->fieldOverride = false; // set this var to override field output will override $fieldInputOverride and $fieldOutputOverride too
//$item->fieldInputOverride = false; // set this var to override field input
//$item->fieldOutputOverride = false; // set this var to override field output

$item = $formSetup->newItem('PDPCONNECTFR_MYPARAM11')->setAsHtml();
$item->nameText = $item->getNameText().' more html text ';
$item->fieldInputOverride = '';
$item->helpText = $langs->transnoentities('HelpMessage');
$item->cssClass = 'minwidth500';

$item = $formSetup->newItem('PDPCONNECTFR_MYPARAM12');
$item->fieldOverride = "Value forced, can't be modified";
$item->cssClass = 'minwidth500';

//$item = $formSetup->newItem('PDPCONNECTFR_MYPARAM13')->setAsDate();	// Not yet implemented
*/

// Setup conf to choose to use Chorus or not
$item = $formSetup->newItem('PDPCONNECTFR_USE_CHORUS')->setAsYesNo();
$item->helpText = $langs->transnoentities('PDPCONNECTFR_USE_CHORUS_HELP');
$item->cssClass = 'minwidth500';

// End of definition of parameters


$setupnotempty += count($formSetup->items);


//$dirmodels = array_merge(array('/'), (array) $conf->modules_parts['models']);
//$moduledir = 'pdpconnectfr';



/*
 * Actions
 */

// Set FACTURX as the default protocol when no default value is specified
if (!getDolGlobalString('PDPCONNECTFR_PROTOCOL')) {
	dolibarr_set_const($db, 'PDPCONNECTFR_PROTOCOL', 'FACTURX', 'chaine', 0, '', $conf->entity);
	header("Location: ".$_SERVER["PHP_SELF"]);
	exit;
}


// Setup conf for auto generation of objects
$formSetup->newItem('PDPCONNECTFR_SYNC_TO_PA')->setAsTitle();

// Setup conf to choose use of auto generation or not of products
$item = $formSetup->newItem('PDPCONNECTFR_EINVOICE_IN_REAL_TIME')->setAsYesNo();
$item->helpText = $langs->transnoentities('PDPCONNECTFR_EINVOICE_IN_REAL_TIME');
$item->defaultFieldValue = 0;
$item->cssClass = 'minwidth500';
$item->fieldParams['forcereload'] = 1;

if (getDolGlobalString('PDPCONNECTFR_EINVOICE_IN_REAL_TIME')) {
	$item = $formSetup->newItem('PDPCONNECTFR_EINVOICE_CANCEL_IF_EINVOICE_FAILS')->setAsYesNo();
	$item->helpText = $langs->transnoentities('PDPCONNECTFR_EINVOICE_CANCEL_IF_EINVOICE_FAILS');
	$item->defaultFieldValue = 0;
	$item->cssClass = 'minwidth500';
}

// Setup conf to choose to block generation/send of an invoice if no routing ID is found for the third party otherwise use SIREN
$item = $formSetup->newItem('PDPCONNECTFR_BLOCK_INVOICE_NO_ROUTING_ID')->setAsYesNo();
$item->helpText = $langs->transnoentities('PDPCONNECTFR_BLOCK_INVOICE_NO_ROUTING_ID_HELP');
$item->defaultFieldValue = 0;
$item->cssClass = 'minwidth500';
$item->fieldParams['forcereload'] = 0;

// Setup conf for PMT - Mention regarding recovery fees
$item = $formSetup->newItem('PDPCONNECTFR_PMT');
$item->helpText = $langs->transnoentities('PDPCONNECTFR_PMT_HELP');
$item->cssClass = 'minwidth500';

// Setup conf for PMD - Mention regarding late payment penalties
$item = $formSetup->newItem('PDPCONNECTFR_PMD');
$item->helpText = $langs->transnoentities('PDPCONNECTFR_PMD_HELP');
$item->cssClass = 'minwidth500';

// Setup conf for AAB - Mention regarding absence of discount for early payment
$item = $formSetup->newItem('PDPCONNECTFR_AAB');
$item->helpText = $langs->transnoentities('PDPCONNECTFR_AAB_HELP');
$item->cssClass = 'minwidth500';

// Setup conf for auto generation of objects
$formSetup->newItem('PDPCONNECTFR_AUTO_GENERATION')->setAsTitle();

// Setup conf to choose use of auto generation or not of products
$item = $formSetup->newItem('PDPCONNECTFR_PRODUCTS_AUTO_GENERATION')->setAsYesNo();
$item->helpText = $langs->transnoentities('PDPCONNECTFR_PRODUCTS_AUTO_GENERATION_HELP');
$item->defaultFieldValue = 0;
$item->cssClass = 'minwidth500';

// Setup conf to choose use of auto generation or not of third parties
$item = $formSetup->newItem('PDPCONNECTFR_THIRDPARTIES_AUTO_GENERATION')->setAsYesNo();
$item->helpText = $langs->transnoentities('PDPCONNECTFR_THIRDPARTIES_AUTO_GENERATION_HELP');
$item->defaultFieldValue = 0;
$item->cssClass = 'minwidth500';

// Setup conf to enable complete third party information when receiving an invoice from from PDP
$item = $formSetup->newItem('PDPCONNECTFR_THIRDPARTIES_COMPLETE_INFO')->setAsYesNo();
$item->helpText = $langs->transnoentities('PDPCONNECTFR_THIRDPARTIES_COMPLETE_INFO_HELP');
$item->defaultFieldValue = 0;
$item->cssClass = 'minwidth500';

// Setup conf to to enable a limit of flows to synchronize per one synchronization call
$item = $formSetup->newItem('PDPCONNECTFR_FLOWS_SYNC_CALL_LIMIT')->setAsYesNo();
$item->helpText = $langs->transnoentities('PDPCONNECTFR_FLOWS_SYNC_CALL_LIMIT_HELP');
$item->defaultFieldValue = 1;
$item->cssClass = 'minwidth500';
$item->fieldParams['forcereload'] = 1;

if (getDolGlobalString('PDPCONNECTFR_FLOWS_SYNC_CALL_LIMIT')) {
	// Setup conf to to define the number of flows to synchronize per one synchronization call
	$item = $formSetup->newItem('PDPCONNECTFR_FLOWS_SYNC_CALL_SIZE');
	$item->helpText = $langs->transnoentities('PDPCONNECTFR_FLOWS_SYNC_CALL_SIZE_HELP');
	$item->defaultFieldValue = 100;
	$item->cssClass = 'maxwidth100';
}

// Setup conf to define a time margin in hours to go back from the current date of the last synchronization
$item = $formSetup->newItem('PDPCONNECTFR_SYNC_MARGIN_TIME_HOURS');
$item->helpText = $langs->transnoentities('PDPCONNECTFR_SYNC_MARGIN_TIME_HOURS_HELP');
$item->fieldAttr['placeholder'] = $langs->transnoentities('Hours');
$item->cssClass = 'maxwidth100';

// Setup conf for debug mode
$formSetup->newItem('PDPCONNECTFR_DEBUG')->setAsTitle();

// Setup conf to enable or not debug mode
$item = $formSetup->newItem('PDPCONNECTFR_DEBUG_MODE')->setAsYesNo();
$item->helpText = $langs->transnoentities('PDPCONNECTFR_DEBUG_MODE_HELP');
$item->defaultFieldValue = 0;
$item->cssClass = 'minwidth500';
$item->fieldParams['warningifon'] = 1;


include DOL_DOCUMENT_ROOT.'/core/actions_setmoduleoptions.inc.php';
//print getDolGlobalString('PDPCONNECTFR_PDP');



/*
 * View
 */

$action = 'edit';

$form = new Form($db);

$help_url = '';
$title = "PDPConnectFRSetup";

llxHeader('', $langs->trans($title), $help_url, '', 0, 0, '', '', '', 'mod-pdpconnectfr page-admin');

// Subheader
$linkback = '<a href="'.($backtopage ? $backtopage : DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1').'">'.img_picto($langs->trans("BackToModuleList"), 'back', 'class="pictofixedwidth"').'<span class="hideonsmartphone">'.$langs->trans("BackToModuleList").'</span></a>';

print load_fiche_titre($langs->trans($title), $linkback, 'title_setup');


// Configuration header
$head = pdpconnectfrAdminPrepareHead();
print dol_get_fiche_head($head, 'options', $langs->trans($title), -1, "pdpconnectfr.png@pdpconnectfr");

// Setup page goes here
//print info_admin($langs->trans("PDPConnectInfo"));
//print '<span class="opacitymedium">'.$langs->trans("PDPConnectFRSetupPage").'</span><br>';
print '<br>';

// Alert mysoc configuration is not complete
$pdpconnectfr = new PdpConnectFr($db);
$mysocCheck = $pdpconnectfr->validateMyCompanyConfiguration();
if ($mysocCheck['res'] < 0) {
	print '<div class="error">';
	print '<strong>' . $langs->trans("MyCompanyConfigurationError") . ':</strong><br><br>';
	print $mysocCheck['message'];
	print '<br><br>';
	print '<a class="button" href="' . DOL_URL_ROOT . '/admin/company.php">';
	print $langs->trans("ModifyCompanyInformation") . ' <i class="fas fa-tools"></i>';
	print '</a>';
	print '</div>';
	print '<br>';
}


/*if ($action == 'edit') {
 print $formSetup->generateOutput(true);
 print '<br>';
 } elseif (!empty($formSetup->items)) {
 print $formSetup->generateOutput();
 print '<div class="tabsAction">';
 print '<a class="butAction" href="'.$_SERVER["PHP_SELF"].'?action=edit&token='.newToken().'">'.$langs->trans("Modify").'</a>';
 print '</div>';
 }
 */
if (!empty($formSetup->items)) {
	print $formSetup->generateOutput(true);
	print '<br>';
}

// on change PDPCONNECTFR_PDP reload page to show specific configuration of selected PDP
print '<script>
$(document).ready(function() {
	var pdpSelect = $("select[name=\'PDPCONNECTFR_PDP\']");
	if (pdpSelect.length) {
		pdpSelect.on("change", function() {
			console.log("PDP changed, submit form to reload page");
			$(this).closest("form").submit();
		});
	}
});
</script>';

if (empty($setupnotempty)) {
	print '<br>'.$langs->trans("NothingToSetup");
}

// Page end
print dol_get_fiche_end();

llxFooter();
$db->close();
