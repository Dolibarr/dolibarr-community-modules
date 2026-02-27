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
 * @var Societe $mysoc
 * @var Translate $langs
 * @var User $user
 */
// Libraries
require_once DOL_DOCUMENT_ROOT."/core/lib/admin.lib.php";
require_once DOL_DOCUMENT_ROOT.'/core/class/html.formsetup.class.php';
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

$formSetup = new FormSetup($db);
$formSetup2 = new FormSetup($db);

// Access control
if (!$user->admin) {
	accessforbidden();
}


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

// If Access Point is selected, show parameters for if
if (getDolGlobalString('PDPCONNECTFR_PDP')) {
	$provider = $PDPManager->getProvider(getDolGlobalString('PDPCONNECTFR_PDP'));
	$providerconfig  = $provider->getConf();
	$prefix = $providerconfig['dol_prefix'].'_';
}


// Setup conf to choose an Access Point Provider

$item = $formSetup->newItem('PDPCONNECTFR_PDP')->setAsSelect($TFieldProviders);
$item->fieldValue = getDolGlobalString('PDPCONNECTFR_PDP');
$item->defaultFieldValue = getDolGlobalString('PDPCONNECTFR_PDP');
$item->helpText = $langs->transnoentities('PDPCONNECTFR_PDP_HELP');
$item->cssClass = 'minwidth500';
//var_dump($item);exit;

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
if ($action == 'update' && GETPOSTISSET('PDPCONNECTFR_PDP') && GETPOST('PDPCONNECTFR_PDP') != getDolGlobalString('PDPCONNECTFR_PDP')) {
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

if (preg_match('/call'.$prefix.'HEALTHCHECK/i', $action, $reg)) {
	$statusPDP = $provider->checkHealth();
	if ($statusPDP['status_code'] == 200) {
		setEventMessages($statusPDP['message'], null, 'mesgs');
	} else {
		setEventMessages($langs->trans('APApiNotReachable', $PDPManager->getProvider(getDolGlobalString('PDPCONNECTFR_PDP'))), array(), 'errors');
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

if (preg_match('/delete'.$prefix.'TOKEN/i', $action, $reg)) {
	// Delete token
	$result = $provider->deleteAccessToken();

	if ($result) {
		setEventMessages("Token deleted successfully", null, 'mesgs');
		header("Location: ".$_SERVER["PHP_SELF"].'?page_y='.GETPOSTFLOAT('page_y'));
		exit;
	} else {
		setEventMessages('', $provider->errors, 'errors');
	}
}

if (getDolGlobalString('PDPCONNECTFR_PDP')) {
	// Link to get the Credentials
	$prefixenv = getDolGlobalString('PDPCONNECTFR_LIVE') ? 'prod' : 'test';

	$provider->initFormSetup($formSetup2, $prefix, $prefixenv, $providersConfig, $TFieldProtocols, $TFieldProfiles);
}


$valueofapikeybefore = getDolGlobalString($prefix . 'API_KEY');

if ($action == 'update' && !empty($formSetup) && is_object($formSetup) && !empty($user->admin) && GETPOSTISSET('PDPCONNECTFR_PDP')) {
	$formSetup->saveConfFromPost();
}
if ($action == 'update' && !empty($formSetup) && is_object($formSetup) && !empty($user->admin) && !GETPOSTISSET('PDPCONNECTFR_PDP')) {
	$formSetup2->saveConfFromPost();
}
// The actions_setmoduleoptions.inc.php is not able to manage 2 formSetup so we do not use it.
//include DOL_DOCUMENT_ROOT.'/core/actions_setmoduleoptions.inc.php';
//var_dump($formSetup->items['PDPCONNECTFR_PDP']->fieldValue);exit; // For debug, to remove

$valueofapikeyafter = getDolGlobalString($prefix . 'API_KEY');

if ($action == 'update' && $prefix && $valueofapikeyafter != $valueofapikeybefore) {
	// If API key has changed, we make a redirect to reload page.
	header("Location: ".$_SERVER["PHP_SELF"].'?page_y='.GETPOSTINT('page_y'));
	exit;
}





/*
 * View
 */

$action = 'edit';

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

//print '<span class="opacitymedium">'.$langs->trans("PDPConnectFRSetupPage").'</span><br><br>';

// Alert mysoc configuration is not complete
$pdpconnectfr = new PdpConnectFr($db);
$mysocCheck = $pdpconnectfr->validateMyCompanyConfiguration();
if ($mysocCheck['res'] < 0) {
	print '<div class="warning">';
	print '<strong>' . $langs->trans("MyCompanyConfigurationWarning") . ':</strong><br>';
	print $mysocCheck['message'];
	print '<br><br>';
	print '<a class="button" href="' . DOL_URL_ROOT . '/admin/company.php">';
	print $langs->trans("ModifyCompanyInformation") . ' <i class="fas fa-tools"></i>';
	print '</a>';
	print '</div>';
}

print '<br>';

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
	print $formSetup->generateOutput(3, false, $langs->transnoentitiesnoconv('PlatformPartner'), 'titlefieldmiddle');
	print '<br>';
}

if (!empty($provider) && !empty($formSetup2->items)) {
    print '<div class="formborder opacitylow">';
	print $provider->helpToGetCredentials;
	print '</div>';
	print '<br>';
}

if (!empty($formSetup2->items)) {
	print $formSetup2->generateOutput(true, false, $langs->transnoentitiesnoconv('PDPConnectionSetup'), 'titlefieldmiddle');
	print '<br>';
}



// If we change the Access point, we reload page to show specific configuration of the selected Access Point
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
