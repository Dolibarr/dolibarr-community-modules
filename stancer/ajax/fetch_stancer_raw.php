<?php
/* Copyright (C) 2026 Eric Seigne <eric.seigne@cap-rel.fr>
 * Copyright (C) 2026		MDW			<mdeweerd@users.noreply.github.com>
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
 *  \file       htdocs/stancer/ajax/fetch_stancer_raw.php
 *  \brief      AJAX endpoint returning the raw Stancer API JSON response for a given object
 */

if (!defined('NOTOKENRENEWAL')) {
	define('NOTOKENRENEWAL', 1);
}
if (!defined('NOREQUIREMENU')) {
	define('NOREQUIREMENU', '1');
}
if (!defined('NOREQUIREHTML')) {
	define('NOREQUIREHTML', '1');
}
if (!defined('NOREQUIREAJAX')) {
	define('NOREQUIREAJAX', '1');
}
if (!defined('NOREQUIRESOC')) {
	define('NOREQUIRESOC', '1');
}

// Load Dolibarr environment (robust path resolution for htdocs/ or htdocs/custom/ install)
$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME']; $tmp2 = realpath(__FILE__); $i = strlen($tmp) - 1; $j = strlen($tmp2) - 1;  // @phan-suppress-current-line DolibarrForbiddenFunctionPlugin
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

dol_include_once('/stancer/class/stancer_api.class.php');

// F5: this endpoint returns the full raw Stancer API response, so restrict it
// to administrators (not just stancer/read).
if (empty($user->id) || empty($user->admin)) {
	http_response_code(403);
	top_httphead('application/json');
	echo json_encode(array('error' => 'Forbidden'));
	exit;
}

$type = GETPOST('type', 'aZ09');
$id = GETPOST('id', 'alphanohtml');

dol_syslog("stancer ajax/fetch_stancer_raw.php type=" . $type . " id=" . $id);

top_httphead('application/json');

if (empty($type) || empty($id)) {
	dol_syslog("stancer ajax/fetch_stancer_raw.php missing parameters", LOG_WARNING);
	echo json_encode(array('error' => 'Missing parameters type or id'));
	exit;
}

// Whitelist ID format to avoid injection and unexpected API calls
if (!preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $id)) {
	dol_syslog("stancer ajax/fetch_stancer_raw.php invalid id format: " . $id, LOG_WARNING);
	echo json_encode(array('error' => 'Invalid id format'));
	exit;
}

$api = new StancerApi();

$result = false;
switch ($type) {
	case 'payout':
		$result = $api->getPayout($id);
		break;
	case 'payment':
		$result = $api->getPayment($id);
		break;
	case 'refund':
		$result = $api->getRefund($id);
		break;
	case 'dispute':
		$result = $api->getDispute($id);
		break;
	case 'customer':
		$result = $api->getCustomer($id);
		break;
	case 'sepa':
		$result = $api->getSepa($id);
		break;
	case 'card':
		$result = $api->getCard($id);
		break;
	default:
		dol_syslog("stancer ajax/fetch_stancer_raw.php unknown type: " . $type, LOG_WARNING);
		echo json_encode(array('error' => 'Unknown type: ' . $type));
		exit;
}

if ($result === false) {
	dol_syslog("stancer ajax/fetch_stancer_raw.php API error http=" . $api->lastHttpCode . " err=" . $api->error, LOG_WARNING);
	echo json_encode(array(
		'error' => $api->error,
		'http_code' => $api->lastHttpCode,
	));
	exit;
}

echo json_encode(array(
	'http_code' => $api->lastHttpCode,
	'type' => $type,
	'id' => $id,
	'data' => $result,
));
