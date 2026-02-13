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

$PDPManager = new PDPProviderManager($db);
$providersConfig = $PDPManager->getAllProviders();

$ProtocolManager = new ProtocolManager($db);
$protocolsList = $ProtocolManager->getProtocolsList();

// PDP providers list
$TFieldProviders = array('' => '');
foreach ($providersConfig as $key => $pconfig) {
	if ($pconfig['is_enabled'] == 0) {
		continue;
	}
	$TFieldProviders[$key] = $pconfig['provider_name'];
}

// Protocols list
$TFieldProtocols = array();
foreach ($protocolsList as $key => $protocolconfig) {
	if ($protocolconfig['is_enabled'] == 0) {
		continue;
	}
	$TFieldProtocols[$key] = $protocolconfig['protocol_name'];
}

// Available Profiles
$TFieldProfiles = array('EN16931' => 'EN16931');
foreach ($TFieldProfiles as $key => $profileconfig) {
	$TFieldProfiles[$key] = $profileconfig;
}

$reg = array();
$prefix = '';

// If a PDP is selected, show parameters for this PDP
if (getDolGlobalString('PDPCONNECTFR_PDP') && getDolGlobalString('PDPCONNECTFR_PDP') === "ESALINK") {
	$provider = $PDPManager->getProvider('ESALINK');
	$prefix = $provider->getConf()['dol_prefix'].'_';
	$tokenData = $provider->getTokenData();
}


// Setup conf to choose a PDP
$item = $formSetup->newItem('PDPCONNECTFR_PDP')->setAsSelect($TFieldProviders);
$item->helpText = $langs->transnoentities('PDPCONNECTFR_PDP_HELP');
$item->cssClass = 'minwidth500';


$item = $formSetup->newItem('PDPCONNECTFR_LIVE')->setAsYesNo();
$item->fieldParams['forcereload'] = 1;

// End of selection of platform partner


$setupnotempty += count($formSetup->items);


//$dirmodels = array_merge(array('/'), (array) $conf->modules_parts['models']);
//$moduledir = 'pdpconnectfr';

$reg = array();


/*
 * Actions
 */

// Setup conf for selection of the PDP provider
if ($action == 'update' && GETPOST('PDPCONNECTFR_PDP') != getDolGlobalString('PDPCONNECTFR_PDP')) {
	dolibarr_set_const($db, 'PDPCONNECTFR_PDP', GETPOST('PDPCONNECTFR_PDP'), 'chaine', 0, '', $conf->entity);
	header("Location: ".$_SERVER["PHP_SELF"]);
	exit;
}

// Set FACTURX as the default protocol when no default value is specified
if (!getDolGlobalString('PDPCONNECTFR_PROTOCOL')) {
	dolibarr_set_const($db, 'PDPCONNECTFR_PROTOCOL', 'FACTURX', 'chaine', 0, '', $conf->entity);
	header("Location: ".$_SERVER["PHP_SELF"]);
	exit;
}

if (preg_match('/set'.$prefix.'TOKEN/i', $action, $reg)) {
	// Generate token
	$token = $provider->getAccessToken();
	if ($token) {
		setEventMessages("Token generated successfully", null, 'mesgs');
		header("Location: ".$_SERVER["PHP_SELF"].'?page_y='.GETPOSTFLOAT('page_y'));
		exit;
	} else {
		setEventMessages('', $provider->errors, 'errors');
	}
}

if (preg_match('/make'.$prefix.'sampleinvoice/i', $action, $reg)) {
	$result = $provider->sendSampleInvoice();
	if ($result) {
		setEventMessages('', $result, 'warnings');
	} else {
		setEventMessages('', $provider->errors, 'errors');
	}
}

if (preg_match('/call'.$prefix.'HEALTHCHECK/i', $action, $reg)) {
	$statusPDP = $provider->checkHealth();
	if ($statusPDP['status_code'] == 200) {
		setEventMessages($statusPDP['message'], null, 'mesgs');
	} else {
		setEventMessages($langs->trans('PdpApiNotReachable'), array(), 'errors');
	}
}

// To remove
/*if (preg_match('/makeInvoice/i', $action, $reg)) {
	$protocol = $ProtocolManager->getprotocol('FACTURX');

	$result = $protocol->generateInvoice('288');
	if ($result) {
		setEventMessages('Result : ' . $result, null, 'warnings');
	} else {
		setEventMessages('', $protocol->errors, 'errors');
	}
}*/


if (getDolGlobalString('PDPCONNECTFR_PDP') && getDolGlobalString('PDPCONNECTFR_PDP') === "ESALINK") {
	// Separator
	$formSetup->newItem('PDPConnectionSetup')->setAsTitle();


	// Link to get the Credentials
	$prefixenv = getDolGlobalString('PDPCONNECTFR_LIVE') ? 'prod' : 'test';

	$item = $formSetup->newItem('PDPCONNECTFR_LINK_CREATE_ACCOUNT');
	$url = $providersConfig[getDolGlobalString('PDPCONNECTFR_PDP')][$prefixenv.'_account_admin_url'];
	$item->fieldOverride = img_picto('', 'url', 'class="pictofixedwidth"').'<a href="'.$url.'" target="_new">'.$url.'</a>';


	// Setup conf to choose a protocol of exchange
	$item = $formSetup->newItem('PDPCONNECTFR_PROTOCOL')->setAsSelect($TFieldProtocols);
	$item->helpText = $langs->transnoentities('PDPCONNECTFR_PROTOCOL_HELP');
	$item->defaultFieldValue = 'FACTURX';
	$item->cssClass = 'minwidth500';

	// Setup conf to choose a profil of exchange
	$item = $formSetup->newItem('PDPCONNECTFR_PROFILE')->setAsSelect($TFieldProfiles);
	$item->helpText = $langs->transnoentities('PDPCONNECTFR_PROFILE_HELP');
	$item->defaultFieldValue = 'EN16931';
	$item->cssClass = 'minwidth500';


	// Username
	$item = $formSetup->newItem($prefix . 'USERNAME');
	$item->cssClass = 'minwidth500';

	// Password
	$item = $formSetup->newItem($prefix . 'PASSWORD')->setAsGenericPassword();
	$item->cssClass = 'minwidth500';

	// API_KEY
	$item = $formSetup->newItem($prefix . 'API_KEY');
	$item->cssClass = 'minwidth500';


	// Token
	if (getDolGlobalString($prefix . 'API_KEY')) {
		$item = $formSetup->newItem($prefix . 'TOKEN');
		$item->cssClass = 'maxwidth500 ';
		$item->fieldOverride = "";
		if (!empty($tokenData['token'])) {
			$item->fieldOverride = "<span class='opacitymedium hideonsmartphone'>" . htmlspecialchars('**************' . substr($tokenData['token'], -4)) . "</span>";
		}
		if (!$tokenData['token']) {
			$item->fieldOverride .= '<a class="reposition" href="'.$_SERVER["PHP_SELF"]."?action=set".$prefix."TOKEN&token=".newToken().'">' . $langs->trans('generateAccessToken') . '<i class="fa fa-key paddingleft"></i></a><br>';
		}
		if ($tokenData['token']) {
			$item->fieldOverride .= ' &nbsp; &nbsp; <a class="reposition" href="'.$_SERVER["PHP_SELF"]."?action=set".$prefix."TOKEN&token=".newToken().'">' . $langs->trans('reGenerateAccessToken') . '<i class="fa fa-key paddingleft"></i></a><br>';
		}
	}

	if (!empty($tokenData['token'])) {
		// Actions
		$item = $formSetup->newItem($prefix . 'ACTIONS');

		$item->fieldOverride .= '<a class="reposition" href="'.$_SERVER["PHP_SELF"]."?action=call".$prefix."HEALTHCHECK&token=".newToken().'">' . $langs->trans('testConnection') . ' (Healthcheck)<i class="fa fa-check paddingleft"></i></a><br>';
		$item->cssClass = 'minwidth500';

		if ($tokenData['token'] && getDolGlobalString('PDPCONNECTFR_PROTOCOL') && getDolGlobalString('PDPCONNECTFR_PROTOCOL') === 'FACTURX') {
			$item->fieldOverride .= '<a class="reposition" href="'.$_SERVER["PHP_SELF"]."?action=make".$prefix."sampleinvoice&token=".newToken().'">' . $langs->trans('generateSendSampleInvoice') . '<i class="fa fa-file paddingleft"></i></a><br>';
		}
	}

	// ROUTING ID
	$item = $formSetup->newItem($prefix . 'ROUTING_ID');
	$item->helpText = $langs->transnoentities($prefix . 'ROUTING_ID_HELP');
	$item->cssClass = 'minwidth500';

	// To remove
	/*if ($tokenData['token'] && getDolGlobalString('PDPCONNECTFR_PROTOCOL') && getDolGlobalString('PDPCONNECTFR_PROTOCOL') === 'FACTURX' && getDolGlobalString('PDPCONNECTFR_PROFILE') === 'EN16931') {
		$item->fieldOverride .= "
			<a
			href='".$_SERVER["PHP_SELF"]."?action=makeInvoice&token=".newToken()."'
			> Generate Invoice <i class='fa fa-file'></i></a><br/>
		";
	}*/
}

$valueofapikeybefore = getDolGlobalString($prefix . 'API_KEY');

include DOL_DOCUMENT_ROOT.'/core/actions_setmoduleoptions.inc.php';

$valueofapikeyafter = getDolGlobalString($prefix . 'API_KEY');

if ($action == 'update' && $prefix && $valueofapikeyafter != $valueofapikeybefore) {
	header("Location: ".$_SERVER["PHP_SELF"]);
	exit;
}

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
print dol_get_fiche_head($head, 'settings', $langs->trans($title), -1, "pdpconnectfr.png@pdpconnectfr");


// Setup page goes here
print info_admin($langs->trans("PDPConnectInfo").'<br>'.$langs->trans("PDPConnectInfo2"));
print '<br>';

//print '<span class="opacitymedium">'.$langs->trans("PDPConnectFRSetupPage").'</span><br><br>';

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
	print $formSetup->generateOutput(true, false, $langs->transnoentitiesnoconv('PlatformPartner'), 'titlefieldmiddle');
	print '<br>';
}


if (getDolGlobalString('PDPCONNECTFR_PDP') && getDolGlobalString('PDPCONNECTFR_PDP') === "ESALINK") {


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
