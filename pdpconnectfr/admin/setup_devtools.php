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
 * 			This page is visible in the setup menu only if the constant PDPCONNECTFR_ALLOW_DEVTOOLS is set to 1.
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

$invoice_path = '';


/*
 * Actions
 */

if ($provider && $action == 'buildsamplesupplierinvoice') {
	$sellerId = GETPOST('seller_id', 'alpha');
	$buyerId = GETPOST('buyer_id', 'alpha');

	if ((float) DOL_VERSION < 24.0) {
		$resarray = $provider->exchangeProtocol->generateSampleInvoiceOld($pdpconnectfr);
		$invoice_path = $resarray['path'];
		$ref = $resarray['ref'];
	} else {
		if ($sellerId > 0) {
			$thirdpartySeller = new Societe($db);
			$thirdpartySeller->fetch($sellerId);
		} else {
			$thirdpartySeller = null;
		}
		if ($buyerId > 0) {
			$thirdpartyBuyer = new Societe($db);
			$thirdpartyBuyer->fetch($buyerId);
		} else {
			$thirdpartyBuyer = $mysoc;
		}

		$resarray = $provider->exchangeProtocol->generateSampleInvoice($pdpconnectfr, $thirdpartySeller, $thirdpartyBuyer);
	}

	if (is_numeric($resarray) && $resarray < 0) {
		setEventMessages($provider->exchangeProtocol->error, $provider->exchangeProtocol->errors, 'errors');

		$resarray = array();
	} else {
		$invoice_path = $resarray['path'];
		$ref = $resarray['ref'];

		setEventMessages('Sample invoice generated with ref '.$ref, 'mesgs');
	}
}


/*
 * View
 */

$form = new Form($db);

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

print '<div class="neutral">';
print 'Link to test a PDF E-invoice from SuperPDP<br>';
print img_picto('', 'url', 'class="pictofixedwidth"');
print '<a href="https://www.superpdp.tech/outils/validateur-facture-electronique" target="_blank">here</a>';
print '</div>';

print '<br>';

print '<div class="neutral">';
print 'Check annuary<br>';
print img_picto('', 'url', 'class="pictofixedwidth"');
print '<a href="https://www.superpdp.tech/outils/info-annuaire" target="_blank">here</a>';
print '</div>';

print '<br>';

if (getDolGlobalString('PDPCONNECTFR_PDP')) {
	$provider = $PDPManager->getProvider(getDolGlobalString('PDPCONNECTFR_PDP'));

	print '<div class="neutral">';
	print 'Generate an Einvoice sample in the protocol (Factur-X, ...) set for the Access Point '.getDolGlobalString('PDPCONNECTFR_PDP').' in the first setup tab<br>';
	print '<form action="'.$_SERVER["PHP_SELF"].'" method="POST">';
	print '<input type="hidden" name="action" value="buildsamplesupplierinvoice">';
	print '<input type="hidden" name="token" value="'.newToken().'">';

	print '<span class="width100 inline-block">'.$langs->trans("Seller").'</span> ';
	//print '<input type="text" name="seller_einvoiceid" value="000000002" placeholder="Seller e-invoice ID (Usually SIREN)" class="minwidth150"><br>';
	if (GETPOST("seller_einvoiceid") && $sellerId <= 0) {
		$tmpthirdparty = new Societe($db);
		$tmpthirdparty->fetch(0, '', '', '', GETPOST("seller_einvoiceid"));
		$sellerId = $tmpthirdparty->id;
	}
	print $form->select_company($sellerId ?: '', 'seller_id', '', $langs->trans("MyCompany"), 1);
	print ' &nbsp; <a href="'.$_SERVER["PHP_SELF"].'?seller_einvoiceid=me" class="reposition">Select me</a>';
	print ' - <a href="'.$_SERVER["PHP_SELF"].'?seller_einvoiceid=000000001" class="reposition">Select thirdparty with SIREN 000000001</a>';
	print '<br>';

	print '<span class="width100 inline-block">'.$langs->trans("Buyer").'</span> ';
	//print '<input type="text" name="buyer_id" value="000000001" placeholder="Supplier e-invoice ID (Usually SIREN)" class="minwidth150"><br>';
	if (GETPOST("buyer_einvoiceid") && $buyerId <= 0) {
		$tmpthirdparty = new Societe($db);
		$tmpthirdparty->fetch(0, '', '', '', GETPOST("buyer_einvoiceid"));
		$buyerId = $tmpthirdparty->id;
	}
	print $form->select_company($buyerId ?: '', 'buyer_id', '', $langs->trans("MyCompany"), 1);
	print ' &nbsp; <a href="'.$_SERVER["PHP_SELF"].'?buyer_einvoiceid=000000001" class="reposition">Select thirdparty with SIREN 000000001</a>';
	print ' - <a href="'.$_SERVER["PHP_SELF"].'?buyer_einvoiceid=me" class="reposition">Select me</a>';
	print '<br>';

	print '<input type="submit" class="button small reposition" name="Generate" value="Generate">';
	print '</form>';

	if ($invoice_path) {
		print '<br>';
		print 'Sample invoice generated into document directory into path:<br><b>'.preg_replace('/^'.preg_quote(DOL_DATA_ROOT.'/', '/').'/', '', $invoice_path).'</b>';
	}
	print '</div>';
	print '<br>';


	if (strpos(getDolGlobalString('PDPCONNECTFR_PDP'), 'SUPERPDP') !== false) {
		// Generate a $provider (this call the constructor that load the token with fetchOAuthTokenDB() and save it in the memory var $provider->tokenData)
		// Note: Token may have been expired
		print '<div class="neutral">';
		print 'Current token (can be used for '.getDolGlobalString('PDPCONNECTFR_PDP').' API as HTTP "Bearer: token")<br>';
		$tokendata = $provider->getTokenData();
		$token = $tokendata['token'] ?? '';
		//print '<input id="bearertoken" type="text" class="width500 text-security" value="'.$token.'" spellcheck="false" readonly>';
		if ($token)	{
			print showValueWithClipboardCPButton($token, 0, dol_trunc($token, 10));
		} else {
			print 'Not yet generated or error when generating token.';
		}
		print '</div>';

		print '<br>';

		global $dolibarr_main_url_root;

		$urlforproxy = $dolibarr_main_url_root.'/custom/pdpconnectfr/public/proxy_oauthcallback.php';


		if (getDolGlobalString('PDPCONNECTFR_PDP') == 'SUPERPDPViaPartner' && getDolGlobalString('PDPCONNTECTFR_SUPERPDP_VIAPARTNER') == 'proxy') {
			print '<div class="neutral">';
			print '<b>You are a proxy for SuperPDP</b> Access Point registration (PDPCONNTECTFR_SUPERPDP_VIAPARTNER = "proxy"). ';
			print 'To have customer instances using this server as proxy for SuperPDP registration:<br>';
			print '- on this instance, you must have set the Client ID and Client Secret of reseller account on the setup tab - '.((getDolGlobalString('PDPCONNECTFR_SUPERPDPVIAPARTNER_CLIENT_ID') && getDolGlobalString('PDPCONNECTFR_SUPERPDPVIAPARTNER_CLIENT_SECRET')) ? 'OK' : '<span class="error">KO</span>').'.<br>';
			print '- on the SuperPDP Access Point, for the account of your company, the callback url must also be set to "'.$urlforproxy.'"<br>';
			print '- on the instance of your customers, the variable PDPCONNTECTFR_SUPERPDP_VIAPARTNER to the name of your company, for example "'.$mysoc->name.'"<br>';
			print '- on the instance of your customers, the variable PDPCONNTECTFR_SUPERPDP_VIAPARTNER_OAUTH_URL to "'.$urlforproxy.'"<br>';
			print '</div>';
		} elseif (getDolGlobalString('PDPCONNECTFR_PDP') == 'SUPERPDPViaPartner') {
			print '<div class="neutral">';
			print '<b>You are using the proxy for SuperPDP Access Point</b> registration with property:<br>';
			print '- PDPCONNTECTFR_SUPERPDP_VIAPARTNER = '.getDolGlobalString('PDPCONNTECTFR_SUPERPDP_VIAPARTNER').'<br>';
			print '- PDPCONNTECTFR_SUPERPDP_VIAPARTNER_OAUTH_URL = '.getDolGlobalString('PDPCONNTECTFR_SUPERPDP_VIAPARTNER_OAUTH_URL');
			print '</div>';
		} else {
			print '<div class="neutral">';
			print 'This instance can be a Proxy for the SuperPDP Access Point registration for your customer if you set:<br>';
			print '- on this instance, the variable PDPCONNTECTFR_SUPERPDP_VIAPARTNER to the value "proxy"<br>';
			print '- on the instance of your customers, the variable PDPCONNTECTFR_SUPERPDP_VIAPARTNER to the name of your company, for example "'.$mysoc->name.'"<br>';
			print '- on the instance of your customers, the variable PDPCONNTECTFR_SUPERPDP_VIAPARTNER_OAUTH_URL to "'.$urlforproxy.'"<br>';
			print '- on the instance of your customers, choose the Access Point provider working through your Proxy.<br>';
			print '- on the SuperPDP Access Point, for the account of your company, the callback url must also be set to "'.$urlforproxy.'"<br>';
			print '<br>';
			print 'This instance can be a customer instance registering to SuperPDP through the OAUth proxy of a partner if you set:<br>';
			print '- on this instance, the variable PDPCONNTECTFR_SUPERPDP_VIAPARTNER to the name of the company offering the proxy, for example DoliCloud.<br>';
			print '- on this instance, the variable PDPCONNTECTFR_SUPERPDP_VIAPARTNER_OAUTH_URL to the url of the proxy (provided by the proxy)<br>';
			print '- on this instance, choose the Access Point provider working through the Proxy.<br>';
			print '- on the proxy instance, the variable PDPCONNTECTFR_SUPERPDP_VIAPARTNER to the value "proxy"<br>';
			print '</div>';
		}
	}
}


// Page end
print dol_get_fiche_end();

llxFooter();
$db->close();
