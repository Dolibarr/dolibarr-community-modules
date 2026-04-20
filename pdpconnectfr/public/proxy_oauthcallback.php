<?php
/* Copyright (C) 2022       Laurent Destailleur     <eldy@users.sourceforge.net>
 * Copyright (C) 2015-2026  Frédéric France         <frederic.france@free.fr>
 * Copyright (C) 2024		MDW						<mdeweerd@users.noreply.github.com>
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
 *      \file       pdpconnect/public/proxy_oauthcallback.php
 *      \ingroup    pdpconnectpfr
 *      \brief      Page to proxy OAuth for PDP Connect client module
 */

if (!defined('NOLOGIN')) {
	define("NOLOGIN", 1); // This means this output page does not require to be logged.
}

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
 * The main.inc.php has been included so the following variable are now defined:
 * @var Conf $conf
 * @var DoliDB $db
 * @var HookManager $hookmanager
 * @var Translate $langs
 * @var User $user
 *
 * @var string $dolibarr_main_url_root
 */
require_once '../lib/pdpconnectfr.lib.php';
require_once "../class/providers/PDPProviderManager.class.php";
require_once "../class/protocols/ProtocolManager.class.php";
require_once "../class/pdpconnectfr.class.php";


// Define $urlwithroot
global $dolibarr_main_url_root;
$urlwithouturlroot = preg_replace('/'.preg_quote(DOL_URL_ROOT, '/').'$/i', '', trim($dolibarr_main_url_root));
$urlwithroot = $urlwithouturlroot.DOL_URL_ROOT; // This is to use external domain name found into config file
//$urlwithroot=DOL_MAIN_URL_ROOT;					// This is to use same domain name than current

$langs->load("oauth");

$action = GETPOST('action', 'aZ09');
$backtourl = GETPOST('backtourl', 'alpha');
$keyforprovider = GETPOST('keyforprovider', 'aZ09');
if (!GETPOSTISSET('keyforprovider') && !empty($_SESSION["oauthkeyforproviderbeforeoauthjump"]) && (GETPOST('code') || $action == 'delete')) {
	// If we are coming from the Oauth page
	$keyforprovider = $_SESSION["oauthkeyforproviderbeforeoauthjump"];
}


$nonce = bin2hex(random_bytes(8));
$code = GETPOST('code');
$state = GETPOST('state');
$statewithscopeonly = '';
$statewithanticsrfonly = '';

$requestedpermissionsarray = array();
if ($state) {
	// 'state' parameter is standard to store a hash value and can also be used to retrieve some parameters back
	$statewithscopeonly = preg_replace('/\-.*$/', '', $state);
	if ($statewithscopeonly != 'none') {
		$requestedpermissionsarray = explode(',', $statewithscopeonly); // Example: 'userinfo_email,userinfo_profile,openid,email,profile,cloud_print'.
		$statewithanticsrfonly = preg_replace('/^.*\-/', '', $state);
	} else {
		$statewithscopeonly = '';
	}
}

$providertouse = getDolGlobalString('PDPCONNECTFR_PDP');
if (GETPOSt('proxy') && getDolGlobalString('PDPCONNTECTFR_SUPERPDP_VIAPARTNER') == 'proxy') {	// If using a proxy is requested and we are on a server proxy
	$providertouse = strtoupper(GETPOST('proxy', 'aZ09'));
}


// Security checks

if (getDolGlobalString('PDPCONNTECTFR_SUPERPDP_VIAPARTNER') != 'proxy') {
	accessforbidden('Setup of service is not correct to use the proxy page. The option PDPCONNTECTFR_SUPERPDP_VIAPARTNER to enable the proxy was not set to "proxy".');
}

$pdpprovider = new PDPProviderManager($db);
$setupprovider = $pdpprovider->getProvider($providertouse);


$keyforparamid = 'PDPCONNECTFR_'.strtoupper($providertouse).'_CLIENT_ID';
$keyforparamsecret = 'PDPCONNECTFR_'.strtoupper($providertouse).'_CLIENT_SECRET';
if (!getDolGlobalString($keyforparamid)) {
	accessforbidden('Setup of service '.$keyforparamid.' is not complete. Customer ID is missing');
}
if (!getDolGlobalString($keyforparamsecret)) {
	accessforbidden('Setup of service '.$keyforparamid.' is not complete. Secret key is missing');
}


/*
 * Actions
 */

// Add a test to check that the state parameter is provided into URL when we make the first call to ask the redirect or when we receive the callback,
// but NOT when callback was ok and we recall the page
if ($action != 'delete' && !GETPOST('afteroauthloginreturn') && (empty($statewithscopeonly) || empty($requestedpermissionsarray)) && !preg_match('/^none/', $state)) {
	if (GETPOST('error') || GETPOST('error_description')) {
		setEventMessages($langs->trans("Error").' '.GETPOST('error_description'), null, 'errors');
	} else {
		dol_syslog("state or statewithscopeonly and/or requestedpermissionsarray are empty");

		$backtourl = GETPOST('redirect_uri').(strpos(GETPOST('redirect_uri'), '?') !== false ? '&' : '?').'error=scopeundefined';

		// TODO Test that backtourl start with the allowed domain

		header('Location: '.$backtourl);
		exit();
	}
}



$providerconfig = $setupprovider->getConf();
$keyforurl = getDolGlobalString('PDPCONNECTFR_PDP');

if ($keyforurl) {
	//$baseApiUriInt = new Uri(getDolGlobalString($keyforurl));
} else {
	print 'Error, failed to get value for constant '.$keyforurl;
	exit;
}

$oauthserverurl = $providerconfig['prod_auth_url'];
$oauthserverurl .= (preg_match('/\/$/', $oauthserverurl) ? '' : '/').'authorize?client_id='.urlencode(getDolGlobalString($keyforparamid)).'&response_type=code&state='.urlencode($state);

$save_redirect_uri = GETPOST('redirect_uri');
// TODO Test that redirect_uri match an allowed url/domain

$redirect_uri = dol_buildpath('/custom/pdpconnectfr/public/proxy_oauthcallback.php', 3);
$oauthserverurl .= '&redirect_uri='.urlencode($redirect_uri);


if (empty($code) && !GETPOST('error')) {
	dol_syslog("Page is called without the 'code' parameter defined");

	$origin_state = $state;

	// Generate a random state value to prevent CSRF attack. Will be stored into session just after to check it when we will receive the callback from provider.
	$state = $nonce;
	$state .= '-'.urlencode($save_redirect_uri);

	// If we enter this page without 'code' parameter, it means we click on the link from login page ($forlogin is set) or from setup page and we want to get the redirect
	// to the OAuth provider login page.
	$_SESSION["backtourlsavedbeforeoauthjump"] = $backtourl;
	$_SESSION["oauthkeyforproviderbeforeoauthjump"] = $keyforprovider;
	$_SESSION['oauthoriginstateanticsrf'] = $origin_state;
	$_SESSION['oauthstateanticsrf'] = $state;

	// Save more data into session
	// No need to save more data in sessions. We have several info into $_SESSION['datafromloginform'], saved when form is posted with a click
	// on "Login with Generic" with param actionlogin=login and beforeoauthloginredirect=generic, by the functions_genericoauth.php.

	// This may create record into oauth_state before the header redirect.
	// Creation of record with state, create record or just update column state of table llx_oauth_token (and create/update entry in llx_oauth_state) depending on the Provider used (see its constructor).
	//if ($state && $state != 'none') {
	//$url = $apiService->getAuthorizationUri(array('client_id' => getDolGlobalString($keyforparamid), 'response_type' => 'code', 'state' => $state));
	//} else {
	//	$url = $apiService->getAuthorizationUri(array('client_id' => getDolGlobalString($keyforparamid), 'response_type' => 'code')); // Parameter state will be randomly generated
	//}
	// The redirect_uri is included into this $url
	$url = $oauthserverurl;
	$url = preg_replace('/&state=(none)?/', '&state='.urlencode($state), $url);

	// Add scopes
	if ($statewithscopeonly) {
		$url .= '&scope='.str_replace(',', '+', $statewithscopeonly);
	}

	// Add more param
	$url .= '&nonce='.bin2hex(random_bytes(64 / 8));

	//var_dump($keyforurl, $url, $statewithscopeonly, $origin_state);exit;

	// we go on oauth provider authorization page, we will then go back on this page but into the other branch of the if (!GETPOST('code'))
	header('Location: '.$url);
	exit();
} else {
	// We are coming from the return of an OAuth2 provider page.
	dol_syslog(basename(__FILE__)." We are coming from the oauth provider page keyforprovider=".$keyforprovider." code=".dol_trunc(GETPOST('code'), 5));

	// We must validate that the $state is the same than the one into $_SESSION['oauthstateanticsrf'], return error if not.
	if (!isset($_SESSION['oauthstateanticsrf']) || $state != $_SESSION['oauthstateanticsrf']) {
		//var_dump($_SESSION['oauthstateanticsrf']);exit;
		print 'Value for state received in callback URL differs from value in session ($_SESSION["oauthstateanticsrf"]). So code for token creation is refused. Retry to register or to generate the token from scratch.';
		print '<br>'."\n";
		print 'State received in parameter: '.dol_escape_htmltag($state);
		unset($_SESSION['oauthstateanticsrf']);
	} else {
		// This was a callback request from service, get the token
		try {
			//var_dump($apiService);      // OAuth\OAuth2\Service\Generic
			dol_syslog("We received a code=".$code." or error=".GETPOST('error'));

			if (getDolGlobalString('PDPCONNTECTFR_SUPERPDP_VIAPARTNER') == 'proxy') {
				// Ask the token

				$oauthserverurl = $providerconfig['prod_auth_url'];
				$oauthserverurl .= (preg_match('/\/$/', $oauthserverurl) ? '' : '/').'token';

				$redirect_uri = dol_buildpath('/custom/pdpconnectfr/public/proxy_oauthcallback.php', 3);

				$params = [
					"client_id" => getDolGlobalString($keyforparamid),
					"client_secret" => getDolGlobalString($keyforparamsecret),
					"grant_type" => 'authorization_code',
					"code" => $code,
					"consumer_key" => getDolGlobalString($keyforparamid),
					"redirect_uri" => $redirect_uri
				];

				$resultget = getURLContent($oauthserverurl, 'POST', $params);

				$reg = array();
				$origin_redirect_uri = '';
				if (preg_match('/^[a-z0-9]+\-(.*)/', $state, $reg)) {
					$origin_redirect_uri = $reg[1];
				}
				$origin_redirect_uri = urldecode($origin_redirect_uri);

				if (empty($resultget['curl_error_no']) && isset($resultget['http_code']) && $resultget['http_code'] == 200) {
					dol_syslog("From state, we have origin_redirect_uri=".$origin_redirect_uri);

					$origin_state = $_SESSION['oauthoriginstateanticsrf'];
					dol_syslog("From session, we have original_state=".$origin_state);

					$content = json_decode($resultget['content'], true);

					$access_token = $content['access_token'];
					$expires_in = $content['expires_in'];
					$refresh_token = $content['refresh_token'];
					$scope = $content['scope'];

					$origin_redirect_uri .= '?accesstoken='.urlencode($access_token);
					$origin_redirect_uri .= '&expires_in='.urlencode($expires_in);
					$origin_redirect_uri .= '&refresh_token='.urlencode($refresh_token);
					$origin_redirect_uri .= '&state='.urlencode($origin_state);
					$origin_redirect_uri .= '&scope='.urlencode($scope);

					//var_dump($origin_redirect_uri);	exit;

					dol_syslog("Redirect now on origin_redirect_uri=".$origin_redirect_uri);

					header('Location: '.$origin_redirect_uri);
					exit();
				} else {
					print '<center>';
					print 'Error in OAuth proxy step...<br>';
					print '<br>';
					if (!empty($resultget['curl_error_no'])) {
						print 'getURLContent error: '.$resultget['curl_error_msg'];
					}
					if (!isset($resultget['http_code']) || $resultget['http_code'] != 200) {
						print 'getURLContent error: '.$resultget['content'];
					}

					print '<br>';
					print '<br>';
					print '<a href="'.$origin_redirect_uri.'">Go back to setup page...</a>';
					print '<br>';

					print '</center>';

					// TODO Make a redirect to setup page to show the error message

					exit;
				}
			}

			// Here we receive callback from the OAuth provider or from the proxy.

			$errorincheck = 0;

			$db->begin();

			$token = GETPOST('oauthtoken');

			// Insert or update token


			dol_syslog("requestAccessToken complete");

			// The refresh token is inside the object token if the prompt was forced only.
			//$refreshtoken = $token->getRefreshToken();
			//var_dump($refreshtoken);

			if (!$errorincheck) {
				setEventMessages("Token generated and saved", null, 'mesgs');
				$db->commit();
			} else {
				setEventMessages("Error during token retrieval", null, 'errors');
				$db->rollback();
			}

			/*
			$backtourl = $_SESSION["backtourlsavedbeforeoauthjump"];
			unset($_SESSION["backtourlsavedbeforeoauthjump"]);

			if (empty($backtourl)) {
				$backtourl = DOL_URL_ROOT.'/';
			}

			dol_syslog("Redirect now on backtourl=".$backtourl);

			header('Location: '.$backtourl);
			exit();
			*/
		} catch (Exception $e) {
			print $e->getMessage();
		}
	}
}


/*
 * View
 */

// No view at all, just actions

$db->close();
