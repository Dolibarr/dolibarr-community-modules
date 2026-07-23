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
 *      \file       einvoicing/public/proxy_oauthcallback.php
 *      \ingroup    einvoicing
 *      \brief      Page to proxy OAuth for PDP Connect client module
 */

if (!defined('NOLOGIN')) {
	define("NOLOGIN", 1); // This means this output page does not require to be logged.
}
if (!defined('NOCSRFCHECK')) {
	define("NOCSRFCHECK", 1);
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
if (!$res && file_exists("../../../../../main.inc.php")) {
	$res = @include "../../../../../main.inc.php";
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
require_once '../lib/einvoicing.lib.php';
require_once "../class/providers/PDPProviderManager.class.php";
require_once "../class/protocols/ProtocolManager.class.php";
require_once "../class/einvoicing.class.php";


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
if ($state) { // Used to stoe scope and anti-csrf value. The scope is stored in the first part of the state, before the first dash. The anti-csrf value is stored in the second part of the state, after the first dash (exemple: scope1,scope2,scope3-jetonAntiCSRF)
	// 'state' parameter is standard to store a hash value and can also be used to retrieve some parameters back
	$statewithscopeonly = preg_replace('/\-.*$/', '', $state);
	if ($statewithscopeonly != 'none') {
		$requestedpermissionsarray = explode(',', $statewithscopeonly); // Example: 'userinfo_email,userinfo_profile,openid,email,profile,cloud_print'.
		$statewithanticsrfonly = preg_replace('/^.*\-/', '', $state);
	} else {
		$statewithscopeonly = '';
	}
}

$providertouse = getDolGlobalString('EINVOICING_PDP');
if (GETPOSt('proxy') && getDolGlobalString('EINVOICING_SUPERPDP_VIAPARTNER') == 'proxy') {	// If using a proxy is requested and we are on a server proxy
	$providertouse = strtoupper(GETPOST('proxy', 'aZ09'));
}


// Security checks

if (getDolGlobalString('EINVOICING_SUPERPDP_VIAPARTNER') != 'proxy') {
	accessforbidden('Setup of service is not correct to use the proxy page. The option EINVOICING_SUPERPDP_VIAPARTNER to enable the proxy was not set to "proxy".');
}

$pdpprovider = new PDPProviderManager($db);
$setupprovider = $pdpprovider->getProvider($providertouse);


$keyforparamid = 'EINVOICING_'.strtoupper($providertouse).'_CLIENT_ID'.(getDolGlobalInt('EINVOICING_LIVE') ? '_PROD' : '');
$keyforparamsecret = 'EINVOICING_'.strtoupper($providertouse).'_CLIENT_SECRET'.(getDolGlobalInt('EINVOICING_LIVE') ? '_PROD' : '');
if (!getDolGlobalString($keyforparamid)) {
	accessforbidden('Setup of service '.$keyforparamid.' is not complete. Customer ID is missing');
}
if (!getDolGlobalString($keyforparamsecret)) {
	accessforbidden('Setup of service '.$keyforparamid.' is not complete. Secret key is missing');
}


// Server-to-server token refresh for "via partner" (grey-label) clients.
// A delegated client holds a refresh_token but NOT the client_secret, so it cannot run the
// refresh_token grant by itself. It POSTs its refresh_token here; this proxy (which holds the
// secret) performs the grant against the PA and returns the rotated tokens as JSON. This is a
// background machine-to-machine call: no browser, no session/state, no redirect.
if (GETPOST('action', 'aZ09') == 'refresh' && GETPOST('grant_type', 'aZ09') == 'refresh_token') {
	header('Content-Type: application/json; charset=UTF-8');

	$refresh_token = preg_replace('/[^A-Za-z0-9._\-]/', '', (string) GETPOST('refresh_token', 'restricthtml'));
	if (empty($refresh_token)) {
		http_response_code(400);
		echo json_encode(array('error' => 'invalid_request', 'error_description' => 'refresh_token is missing'));
		exit;
	}

	$providerconfig = $setupprovider->getConf();
	$oauthtokenurl = $providerconfig['prod_auth_url'];
	$oauthtokenurl .= (preg_match('/\/$/', $oauthtokenurl) ? '' : '/').'token';

	$params = array(
		'grant_type'    => 'refresh_token',
		'refresh_token' => $refresh_token,
		'client_id'     => getDolGlobalString($keyforparamid),
		'client_secret' => getDolGlobalString($keyforparamsecret),
	);

	require_once DOL_DOCUMENT_ROOT.'/core/lib/geturl.lib.php';
	$resultget = getURLContent($oauthtokenurl, 'POST', http_build_query($params), 1, array('Content-Type: application/x-www-form-urlencoded'));

	$httpcode = empty($resultget['http_code']) ? 0 : $resultget['http_code'];
	if (empty($resultget['curl_error_no']) && $httpcode == 200) {
		// Pass the PA response (access_token, refresh_token, expires_in, ...) straight back to the client.
		echo $resultget['content'];
	} else {
		dol_syslog("proxy_oauthcallback refresh failed http_code=".$httpcode, LOG_WARNING);
		http_response_code($httpcode ? $httpcode : 502);
		echo !empty($resultget['content']) ? $resultget['content'] : json_encode(array('error' => 'proxy_refresh_failed'));
	}
	exit;
}


/*
 * Actions
 */

// Validate state parameter and permissions during OAuth flow
// Check that state parameter is provided in URL when requesting redirect or receiving callback,
// but NOT when callback was successful and page is recalled
if ($action != 'delete' && !GETPOST('afteroauthloginreturn') && (empty($statewithscopeonly) || empty($requestedpermissionsarray)) && !preg_match('/^none/', $state)) {
	// Handle OAuth error from provider
	if (GETPOST('error') || GETPOST('error_description')) {
		setEventMessages($langs->trans("Error").' '.GETPOST('error_description'), null, 'errors');
	} else {
		// State or permissions are missing - log and redirect with error
		dol_syslog("state or statewithscopeonly and/or requestedpermissionsarray are empty");

		$backtourl = GETPOST('redirect_uri').(strpos(GETPOST('redirect_uri'), '?') !== false ? '&' : '?').'error=scopeundefined';

		// TODO Test that backtourl start with the allowed domain

		header('Location: '.$backtourl);
		exit();
	}
}



$providerconfig = $setupprovider->getConf();
$keyforurl = getDolGlobalString('EINVOICING_PDP');

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

$redirect_uri = dol_buildpath('einvoicing/public/proxy_oauthcallback.php', 3);
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

	if (GETPOSTISSET('superpdp_company_number') && GETPOSTISSET('superpdp_company_number_scheme')) {
		$url .= '&superpdp_company_number=' . GETPOST('superpdp_company_number', 'aZ09');
		$url .= '&superpdp_company_number_scheme=' . GETPOST('superpdp_company_number_scheme', 'aZ09');
	}

	// we go on oauth provider authorization page, we will then go back on this page but into the other branch of the if (!GETPOST('code'))
	header('Location: '.$url);
	exit();
} else {
	// We are coming from the return of an OAuth2 provider page.
	dol_syslog(basename(__FILE__)." We are coming from the oauth provider page keyforprovider=".$keyforprovider." code=".dol_trunc(GETPOST('code'), 5));

	// Check if the OAuth provider returned an error before we try to get the token. If so, we redirect to the origin page with error message.
	if (GETPOST('error')) {
		dol_syslog("OAuth provider returned an error: ".GETPOST('error')." - ".GETPOST('error_description'), LOG_WARNING);

		$mode = getDolGlobalInt('EINVOICING_LIVE') ? 'prod' : 'sandbox';
		print '<center>';
		print '<br>';
		print 'Error in OAuth authorize step...<br>';
		print '<br>';
		print 'Mode: <b>'.dol_escape_htmltag($mode).'</b><br>'; // useful for debugging
		print dol_escape_htmltag(GETPOST('error')).' - '.dol_escape_htmltag(GETPOST('error_description'));
		print '<br>';
		print '<br>';

		$reg = array();
		$origin_redirect_uri = '';
		if (preg_match('/^[a-z0-9]+\-(.*)/', $state, $reg)) {
			$origin_redirect_uri = urldecode($reg[1]);
		}
		if ($origin_redirect_uri) {
			// TODO Test that origin_redirect_uri start with the allowed domain
			print '<a href="'.dol_escape_htmltag($origin_redirect_uri).'">Go back to setup page...</a>';
			print '<br>';
		}

		print '</center>';
		exit;
	}

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
			dol_syslog("We received a code=".dol_trunc($code, 5)." or error=".GETPOST('error'));

			if (getDolGlobalString('EINVOICING_SUPERPDP_VIAPARTNER') == 'proxy') {
				// Ask the token

				$oauthserverurl = $providerconfig['prod_auth_url'];
				$oauthserverurl .= (preg_match('/\/$/', $oauthserverurl) ? '' : '/').'token';

				$redirect_uri = dol_buildpath('einvoicing/public/proxy_oauthcallback.php', 3);

				$params = [
					"client_id" => getDolGlobalString($keyforparamid),
					"client_secret" => getDolGlobalString($keyforparamsecret),
					"grant_type" => 'authorization_code',
					"code" => $code,
					"consumer_key" => getDolGlobalString($keyforparamid),
					"redirect_uri" => $redirect_uri
				];

				// Send as application/x-www-form-urlencoded (the OAuth 2.0 standard for the token endpoint),
				// not multipart/form-data which an array param would produce.
				$resultget = getURLContent($oauthserverurl, 'POST', http_build_query($params), 1, array('Content-Type: application/x-www-form-urlencoded'));

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

					$access_token = $content['access_token'] ?? '';
					$expires_in = $content['expires_in'] ?? '';
					$refresh_token = $content['refresh_token'] ?? '';
					$scope = $content['scope'] ?? '';

					$origin_redirect_uri .= '?accesstoken='.urlencode($access_token);
					$origin_redirect_uri .= '&expires_in='.urlencode($expires_in);
					$origin_redirect_uri .= '&refresh_token='.urlencode($refresh_token);
					$origin_redirect_uri .= '&state='.urlencode($origin_state);
					$origin_redirect_uri .= '&scope='.urlencode($scope);

					//var_dump($origin_redirect_uri);	exit;

					// Log only the destination without its query string: the query string carries
					// the freshly issued access_token/refresh_token and must never reach the logs in clear.
					dol_syslog("Redirect now on origin_redirect_uri=".strtok($origin_redirect_uri, '?'));

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
					print '<a href="'.dol_escape_htmltag($origin_redirect_uri).'">Go back to setup page...</a>';
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
