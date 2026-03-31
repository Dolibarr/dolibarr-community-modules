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
 * \file    pdpconnectfr/admin/setup_devtools.php
 * \ingroup pdpconnectfr
 * \brief   PDPConnectFR setup page to provide some tools for dev or test.
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

$pdpconnectfr = new PdpConnectFr($db);
$PDPManager = new PDPProviderManager($db);

// If Access Point is selected, show parameters for it
if (getDolGlobalString('PDPCONNECTFR_PDP')) {
	// Generate a $provider (this call the constructor that load the token with fetchOAuthTokenDB() and save it in the memory var $provider->tokenData)
	// Note: Token may have been expired
	$provider = $PDPManager->getProvider(getDolGlobalString('PDPCONNECTFR_PDP'));
	// Now we load the conf
	$providerconfig  = $provider->getConf();

	$prefix = $providerconfig['dol_prefix'].'_';
}



/*
 * Actions
 */

// None



/*
 * View
 */

$action = 'edit';

$help_url = '';
$title = "PDPConnectFRSetup";

llxHeader('', $langs->trans($title), $help_url, '', 0, 0, '', '', '', 'mod-pdpconnectfr page-admin-devtools');

// Subheader
$linkback = '<a href="'.($backtopage ? $backtopage : DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1').'">'.img_picto($langs->trans("BackToModuleList"), 'back', 'class="pictofixedwidth"').'<span class="hideonsmartphone">'.$langs->trans("BackToModuleList").'</span></a>';

print load_fiche_titre($langs->trans($title), $linkback, 'title_setup');


// Configuration header
$head = pdpconnectfrAdminPrepareHead();
print dol_get_fiche_head($head, 'devtools', $langs->trans($title), -1, "pdpconnectfr.png@pdpconnectfr");

// Setup page goes here
//print info_admin($langs->trans("PDPConnectInfo"));
//print '<span class="opacitymedium">'.$langs->trans("PDPConnectFRSetupPage").'</span><br>';

// Alert mysoc configuration is not complete
$pdpconnectfr = new PdpConnectFr($db);

$stringwarning = pdpShowWarning($pdpconnectfr);
print $stringwarning;


print 'Link to test a PDF E-invoice from SuperPDP<br>';
print img_picto('', 'url', 'class="pictofixedwidth"');
print '<a href="https://www.superpdp.tech/outils/validateur-facture-electronique" target="_blank">here</a>';

print '<br><br>';

print 'Check annuary<br>';
print img_picto('', 'url', 'class="pictofixedwidth"');
print '<a href="https://www.superpdp.tech/outils/info-annuaire" target="_blank">here</a>';

print '<br><br>';

if (getDolGlobalString('PDPCONNECTFR_PDP')) {
	$provider = $PDPManager->getProvider(getDolGlobalString('PDPCONNECTFR_PDP'));

	if (getDolGlobalString('PDPCONNECTFR_PDP') == 'SUPERPDP') {
		// Generate a $provider (this call the constructor that load the token with fetchOAuthTokenDB() and save it in the memory var $provider->tokenData)
		// Note: Token may have been expired
		print 'Current token (can be used for '.getDolGlobalString('PDPCONNECTFR_PDP').' API as HTTP "Bearer: token"):<br>';
		$tokendata = $provider->getTokenData();
		$token = $tokendata['token'] ?? '';
		//print '<input id="bearertoken" type="text" class="width500 text-security" value="'.$token.'" spellcheck="false" readonly>';
		if ($token)	{
			print showValueWithClipboardCPButton($token, 0, dol_trunc($token, 10));
		} else {
			print 'Not yet generated or error when generating token.';
		}
	}
}


// Page end
print dol_get_fiche_end();

llxFooter();
$db->close();
