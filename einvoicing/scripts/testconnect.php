#!/usr/bin/env php
<?php
/* Copyright (C) 2007-2017  Laurent Destailleur     <eldy@users.sourceforge.net>
 * Copyright (C) 2024       Frédéric France         <frederic.france@free.fr>
 * Copyright (C) 2025		SuperAdmin					<daoud.mouhamed@gmail.com>
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
 *   	\file       testconnect.php
 *		\ingroup    einvoicing
 *		\brief      Test scripts
 */

// This script must only be run from the command line: it emulates an OAuth
// token exchange and would otherwise expose the PDP client_secret over HTTP.
if (PHP_SAPI !== 'cli') {
	echo "Error: this script must be run from the command line (CLI), not through a web server.\n";
	exit(1);
}

// Load Dolibarr environment
$res = 0;
// Try main.inc.php into web root known defined into CONTEXT_DOCUMENT_ROOT (not always defined)
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/master.inc.php";
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
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1))."/master.inc.php")) {
	$res = @include substr($tmp, 0, ($i + 1))."/master.inc.php";
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1)))."/master.inc.php")) {
	$res = @include dirname(substr($tmp, 0, ($i + 1)))."/master.inc.php";
}
// Try main.inc.php using relative path
if (!$res && file_exists("../master.inc.php")) {
	$res = @include "../master.inc.php";
}
if (!$res && file_exists("../../master.inc.php")) {
	$res = @include "../../master.inc.php";
}
if (!$res && file_exists("../../../master.inc.php")) {
	$res = @include "../../../master.inc.php";
}
if (!$res && file_exists("../../../../master.inc.php")) {
	$res = @include "../../../../master.inc.php";
}
if (!$res) {
	die("Include of master fails");
}
/**
 * The main.inc.php has been included so the following variable are now defined:
 * @var Conf $conf
 * @var DoliDB $db
 * @var HookManager $hookmanager
 * @var Translate $langs
 * @var User $user
 */
include_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';
include_once DOL_DOCUMENT_ROOT.'/core/lib/company.lib.php';
include_once __DIR__.'/../class/providers/PDPProviderManager.class.php';

$PDPManager = new PDPProviderManager($db);
$provider = $PDPManager->getProvider(getDolGlobalString('EINVOICING_PDP'));


// Emulate the getAccessToken

$providerconfig  = $provider->getConf();
$param = array(
	'grant_type' => "client_credentials",
	'client_id' => $providerconfig['client_id'],
	'client_secret' => $providerconfig['client_secret']
);

$paramstring = http_build_query($param);

// $user is the empty system user (id=0) created by master.inc.php. Nothing on this code path
// (getConf(), callApi(), saveOAuthTokenDB()) checks hasRight()/isModEnabled(), so it does not
// need to be a real, named user — only logCall() reads its id, to attribute the logged API call.

$extraHeaders = array(
	'Content-Type' => 'application/x-www-form-urlencoded'
);


$response = $provider->callApi("oauth2/token", "POST", $paramstring, $extraHeaders, 'get_access_token');

$status_code = $response['status_code'];
$body = json_decode($response['response'], true);

if ($status_code == 200 && isset($body['access_token']) && isset($body['refresh_token']) && isset($body['expires_in'])) {
	$provider->saveOAuthTokenDB($body['access_token'], $body['refresh_token'], $body['expires_in']);

	return $body['access_token'];
} else {
	$provider->errors[] = $langs->trans("FailedToRetrieveAccessToken");
	return null;
}
