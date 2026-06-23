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
 * \file    einvoicing/admin/setup.php
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
 * @var Societe $mysoc
 * @var Translate $langs
 * @var User $user
 */
// Libraries
require_once DOL_DOCUMENT_ROOT."/core/lib/admin.lib.php";
require_once DOL_DOCUMENT_ROOT.'/core/class/html.formsetup.class.php';
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

/*
$value = GETPOST('value', 'alpha');
$label = GETPOST('label', 'alpha');
$scandir = GETPOST('scan_dir', 'alpha');
$type = 'myobject';
*/

$error = 0;
$setupnotempty = 0;

$formSetup = new FormSetup($db);
$formSetup2 = new FormSetup($db);


// Access control
if (!$user->admin) {
	accessforbidden();
}


$einvoicing = new EInvoicing($db);
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
$TFieldProfiles = array('EN16931' => 'EN16931', 'EXTENDED' => 'EXTENDED');
foreach ($TFieldProfiles as $key => $profileconfig) {
	$TFieldProfiles[$key] = $profileconfig;
}

$reg = array();
$prefix = '';
$provider = null;

// If Access Point is selected, show parameters for it
if (getDolGlobalString('EINVOICING_PDP')) {
	// Generate a $provider (this call the constructor that load the token with fetchOAuthTokenDB() and save it in the memory var $provider->tokenData)
	// Note: Token may have been expired
	$provider = $PDPManager->getProvider(getDolGlobalString('EINVOICING_PDP'));
	// Now we load the conf
	$providerconfig  = $provider->getConf();

	$prefix = $providerconfig['dol_prefix'].'_';
}

// Return of the OAuth 2.1 Authorization Code flow (?code&state). Handled here, BEFORE the form is
// (re)built: building the form regenerates the authorize URL and would overwrite the session state.
if (GETPOST('code') && GETPOST('state') && $provider instanceof AbstractPDPProvider && method_exists($provider, 'exchangeAuthorizationCode')) {
	if (GETPOST('state') !== (isset($_SESSION['einvoicing_superpdp_oauth_state']) ? $_SESSION['einvoicing_superpdp_oauth_state'] : '')) {
		setEventMessages($langs->trans('EINVOICING_SUPERPDP_OAUTH_STATE_MISMATCH'), null, 'errors');
	} else {
		unset($_SESSION['einvoicing_superpdp_oauth_state']);
		$token = $provider->exchangeAuthorizationCode(GETPOST('code'));
		if ($token) {
			setEventMessages("Token generated successfully", null, 'mesgs');
		} else {
			setEventMessages($provider->error, $provider->errors, 'errors');
		}
	}

	header("Location: ".$_SERVER["PHP_SELF"]);
	exit;
}

$stringwarning = pdpShowWarning($einvoicing);


// Setup conf to choose an Access Point Provider

$item = $formSetup->newItem('EINVOICING_PDP')->setAsSelect($TFieldProviders);
$item->fieldValue = getDolGlobalString('EINVOICING_PDP');
$item->defaultFieldValue = getDolGlobalString('EINVOICING_PDP');
$item->helpText = $langs->transnoentities('EINVOICING_PDP_HELP');
$item->helpText .= '<br>'.$langs->transnoentities('EINVOICING_PDP_HELP2');
$item->helpText .= '<br>'.$langs->transnoentities('EINVOICING_PDP_HELP3');
$item->cssClass = 'minwidth500';
//var_dump($item);exit;

$item = $formSetup->newItem('EINVOICING_LIVE')->setAsYesNo();
$item->fieldParams['forcereload'] = 1;

// Allow re-sending / re-editing an invoice already transmitted to the Access Point. Off by default:
// a transmitted invoice is immutable (correct it with a credit note / corrective invoice), and re-sending
// makes the PA refuse a duplicate. Turn on only to deliberately test PA retry behaviour.
$item = $formSetup->newItem('EINVOICING_ALLOW_RESEND_TRANSMITTED')->setAsYesNo();
$item->defaultFieldValue = '0';
$item->helpText = $langs->transnoentities('EINVOICING_ALLOW_RESEND_TRANSMITTED_HELP');

// Dev-only: keep the "Regenerate e-invoice" button/action available on a transmitted-locked invoice
// (rebuild the CII/Factur-X to inspect the XML). Re-sending stays locked. Off by default.
$item = $formSetup->newItem('EINVOICING_ENABLE_DEV_CII_REGEN')->setAsYesNo();
$item->defaultFieldValue = '0';
$item->helpText = $langs->transnoentities('EINVOICING_ENABLE_DEV_CII_REGEN_HELP');

// End of selection of platform partner


$setupnotempty += count($formSetup->items);


//$dirmodels = array_merge(array('/'), (array) $conf->modules_parts['models']);
//$moduledir = 'einvoicing';

$reg = array();



/*
 * Actions
 */

// Setup conf for selection of the PDP provider
if ($action == 'update' && GETPOSTISSET('EINVOICING_PDP') && GETPOST('EINVOICING_PDP') != getDolGlobalString('EINVOICING_PDP')) {
	dolibarr_set_const($db, 'EINVOICING_PDP', GETPOST('EINVOICING_PDP'), 'chaine', 0, '', $conf->entity);

	// Set the default protocol when no default value is specified
	if (getDolGlobalString('EINVOICING_PDP') && !getDolGlobalString('EINVOICING_PROTOCOL')) {
		dolibarr_set_const($db, 'EINVOICING_PROTOCOL', 'CII', 'chaine', 0, '', $conf->entity); // Default protocol is CII (can be changed by the user). This setting is mostly technical and may later be hidden or displayed only in debug mode. PA supports all protocols for customer invoices, and Dolibarr automatically detects it for supplier invoice by detectProtocolFromContent().
	}

	header("Location: ".$_SERVER["PHP_SELF"]);
	exit;
}

// Action to get/generate a token
if (preg_match('/set'.$prefix.'TOKEN/i', $action, $reg)) {
	$token = $provider->getAccessToken();	// Get access token from provider and save it into database

	if ($token) {
		setEventMessages("Token generated successfully: ".dol_trunc($token, 48), null, 'mesgs');
		header("Location: ".$_SERVER["PHP_SELF"].'?page_y='.GETPOSTFLOAT('page_y'));
		exit;
	} else {
		setEventMessages($provider->error, $provider->errors, 'errors');
	}
}

// Action healthcheck
if (preg_match('/call'.$prefix.'HEALTHCHECK/i', $action, $reg)) {
	$statusPDP = $provider->checkHealth();
	if ($statusPDP['status_code'] == 200) {
		setEventMessages($statusPDP['message'], null, 'mesgs');
	} else {
		setEventMessages($langs->trans('APApiNotReachable', getDolGlobalString('EINVOICING_PDP')), array(), 'errors');
	}
}

// Generate a sample invoice and try to send it
if (preg_match('/make'.$prefix.'sampleinvoice/i', $action, $reg)) {
	$result = $provider->sendSampleInvoice(1);
	if ($result) {
		setEventMessages('', $result, 'mesgs');
	} else {
		setEventMessages($provider->error, $provider->errors, 'errors');
	}
}
if (preg_match('/makesend'.$prefix.'sampleinvoice/i', $action, $reg)) {
	$result = $provider->sendSampleInvoice(0);
	if ($result) {
		setEventMessages('', $result, 'mesgs');
	} else {
		setEventMessages($provider->error, $provider->errors, 'errors');
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
		setEventMessages($provider->error, $provider->errors, 'errors');
	}
}

if (getDolGlobalString('EINVOICING_PDP')) {
	// Link to get the Credentials
	$prefixenv = getDolGlobalString('EINVOICING_LIVE') ? 'prod' : 'test';

	$provider->initFormSetup($formSetup2, $prefix, $prefixenv, $providersConfig, $TFieldProtocols, $TFieldProfiles);
}

$valueofapikeybefore = getDolGlobalString($prefix . 'API_KEY');

if ($action == 'update' && !empty($formSetup) && is_object($formSetup) && !empty($user->admin) && GETPOSTISSET('EINVOICING_PDP')) {
	$formSetup->saveConfFromPost();
	header("Location: ".$_SERVER["PHP_SELF"]);
	exit;
}
if ($action == 'update' && !empty($formSetup) && is_object($formSetup) && !empty($user->admin) && !GETPOSTISSET('EINVOICING_PDP')) {
	$formSetup2->saveConfFromPost();
	header("Location: ".$_SERVER["PHP_SELF"]);
	exit;
}
// The actions_setmoduleoptions.inc.php is not able to manage 2 formSetup so we do not use it.
//include DOL_DOCUMENT_ROOT.'/core/actions_setmoduleoptions.inc.php';
//var_dump($formSetup->items['EINVOICING_PDP']->fieldValue);exit; // For debug, to remove

$valueofapikeyafter = getDolGlobalString($prefix . 'API_KEY');

if ($action == 'update' && $prefix && $valueofapikeyafter != $valueofapikeybefore) {
	// If API key has changed, we make a redirect to reload page.
	header("Location: ".$_SERVER["PHP_SELF"].'?page_y='.GETPOSTINT('page_y'));
	exit;
}

if (GETPOST('error')) {
	setEventMessages($langs->trans('Error').' '.GETPOST('error'), null, 'errors');
}


if (GETPOST('accesstoken') && $provider instanceof AbstractPDPProvider) {
	// We are in the return of an OAUT proxy authorize+token callback

	$result = $provider->saveOAuthTokenDB(GETPOST('accesstoken'), GETPOST('refresh_token'), GETPOST('expires_in'));

	if ($result) {
		setEventMessages("Token generated successfully", null, 'mesgs');
	} else {
		setEventMessages($provider->error, $provider->errors, 'errors');
	}

	header("Location: ".$_SERVER["PHP_SELF"]);
	exit;
}


/*
 * View
 */

$action = 'edit';

$help_url = 'EN:Module_EInvoicing';
$title = "EInvoicingSetup";

llxHeader('', $langs->trans($title), $help_url, '', 0, 0, '', '', '', 'mod-einvoicing page-admin');

// Subheader
$linkback = '<a href="'.($backtopage ? $backtopage : DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1').'">'.img_picto($langs->trans("BackToModuleList"), 'back', 'class="pictofixedwidth"').'<span class="hideonsmartphone">'.$langs->trans("BackToModuleList").'</span></a>';

print load_fiche_titre($langs->trans($title), $linkback, 'title_setup');


// Configuration header
$head = einvoicingAdminPrepareHead();
print dol_get_fiche_head($head, 'settings', $langs->trans($title), -1, "einvoicing.png@einvoicing");


// Setup page goes here
print info_admin($langs->trans("EInvoicingInfo").'<br>'.$langs->trans("EInvoicingInfo2"));

//print '<span class="opacitymedium">'.$langs->trans("EInvoicingSetupPage").'</span><br><br>';


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

/**
 * Render a FormSetup with a section title and v18/v19 visual parity with v20+.
 *
 * @param FormSetup $formSetupInstance Form setup instance
 * @param string    $title             Section title to display in the first column header
 * @return string                      HTML output
 */
$pdpRenderFormSetup = function ($formSetupInstance, $title) use ($langs) {
	if ((float) DOL_VERSION >= 20) {
		// Native multi-arg signature available since Dolibarr 20.0.0
		return $formSetupInstance->generateOutput(true, false, $title, 'titlefieldmiddle');
	}

	// v18/v19 fallback: generateOutput() accepts only $editMode and always renders a Cancel button.
	// Capture the HTML and rewrite the header row + suppress the Cancel link to mimic v20+ rendering.
	$html = $formSetupInstance->generateOutput(true);

	$titleEscaped = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	$parameterLabel = $langs->trans('Parameter');
	$valueLabel = $langs->trans('Value');

	// Replace the default "Parameter / Value" header with the section title (v20+ behavior)
	$html = preg_replace(
		'#<tr class="liste_titre">\s*<td>'.preg_quote($parameterLabel, '#').'</td>\s*<td>'.preg_quote($valueLabel, '#').'</td>\s*</tr>#',
		'<tr class="liste_titre"><td class="titlefieldmiddle">'.$titleEscaped.'</td><td></td></tr>',
		$html,
		1
	);

	// Suppress the Cancel button to mimic v20+ rendering (Cancel was commented out upstream)
	$html = preg_replace(
		'#&nbsp;&nbsp;\s*<a class="button button-cancel"[^>]*>[^<]*</a>#',
		'',
		$html
	);

	return $html;
};

if (!empty($formSetup->items)) {
	print $pdpRenderFormSetup($formSetup, $langs->transnoentitiesnoconv('PlatformPartner'));
}

if (!empty($provider) && !empty($formSetup2->items)) {
	print $provider->helpToGetCredentials;
}

if ($stringwarning) {
	print $stringwarning;
}

if (!empty($formSetup2->items)) {
	print $pdpRenderFormSetup($formSetup2, $langs->transnoentitiesnoconv('EInvoicingConnectionSetup'));
	print '<br>';
}


// If we change the Access point, we reload page to show specific configuration of the selected Access Point
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

if (empty($setupnotempty)) {
	print '<br>'.$langs->trans("NothingToSetup");
}

// Page end
print dol_get_fiche_end();

llxFooter();
$db->close();
