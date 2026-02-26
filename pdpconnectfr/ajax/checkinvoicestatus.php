<?php
/* Copyright (C) 2022       Laurent Destailleur     <eldy@users.sourceforge.net>
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
 *       \file       htdocs/pdpconnectfr/ajax/checkinvoicestatus.php
 *       \brief      File to return Ajax response on document list request
 */

if (!defined('NOTOKENRENEWAL')) {
	define('NOTOKENRENEWAL', 1); // Disables token renewal
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
if (!defined('NOCSRFCHECK')) {
	define('NOCSRFCHECK', '1');
}
if (!defined('NOREQUIREHTML')) {
	define('NOREQUIREHTML', '1');
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
	http_response_code(500);
	die("Include of main fails");
}
/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var HookManager $hookmanager
 * @var Translate $langs
 * @var User $user
 */

//$mode = GETPOST('mode', 'aZ09');
$objectRef = GETPOST('ref', 'aZ09');
// $field = GETPOST('field', 'aZ09');
// $value = GETPOST('value', 'aZ09');

// Security check
if (!$user->hasRight('pdpconnectfr', 'document', 'write')) {
	accessforbidden();
}

/*
 * View
 */

dol_syslog("Call ajax pdpconnectfr/ajax/checkinvoicestatus.php");
$langs->load('pdpconnectfr@pdpconnectfr');

top_httphead();
// Update the object field with the new value
if ($objectRef) {

	dol_include_once('pdpconnectfr/class/pdpconnectfr.class.php');
	$pdpconnectfr = new PdpConnectFr($db);

	// Load object
	require_once DOL_DOCUMENT_ROOT."/compta/facture/class/facture.class.php";

	$invoice = new Facture($db);
	$object = $invoice->fetch(0, $objectRef);
	if ($invoice->id <= 0) {
		print json_encode(['status' => 'error', 'message' => 'Error loading invoice with ref '. $objectRef]);
		exit;
	}

	// Get flowId from linked document log
	$flowId = '';
	$sql = "SELECT flow_id";
	$sql .= " FROM ".MAIN_DB_PREFIX."pdpconnectfr_extlinks";
	$sql .= " WHERE element_type = '".Facture::class."'";
	$sql .= " AND syncref = '".$db->escape($invoice->ref)."'";
	$resql = $db->query($sql);
	if ($resql) {
		if ($db->num_rows($resql) > 0) {
			$obj = $db->fetch_object($resql);
			$flowId = $obj->flow_id;
		}
	} else {
		print json_encode(['status' => 'error', 'message' => 'Error retrieving flowId for invoice ref '. $invoice->ref]);
		exit;
	}

	// make a call to get validation result from PDP
	// TODO: Move this code to a method in the provider class to avoid breaking the abstraction principle.
	require_once "../class/providers/PDPProviderManager.class.php";
	$PDPManager = new PDPProviderManager($db);
	$provider = $PDPManager->getProvider(getDolGlobalString('PDPCONNECTFR_PDP'));

	$resource = 'flows/' . $flowId;
	$urlparams = array(
		'docType' => 'Metadata',
	);
	$resource .= '?' . http_build_query($urlparams);
	$response = $provider->callApi(
		$resource,
		"GET",
		false,
		['Accept' => 'application/octet-stream'],
		'check_invoice_validation'
	);

	if ($response['status_code'] == 200 || $response['status_code'] == 202) {
		$flowData = json_decode($response['response'], true);

		$syncStatus = $pdpconnectfr::STATUS_UNKNOWN;
		$ack_statusLabel = $flowData['acknowledgement']['status'] ?? '';
		if ($ack_statusLabel) {
			$syncStatus = $pdpconnectfr->getDolibarrStatusCodeFromPdpLabel($ack_statusLabel);
		}
		$syncRef = $flowData['trackingId'] ?? '';
		$syncComment = $flowData['acknowledgement']['details'][0]['reasonMessage'] ?? '';
		$pdpconnectfr->insertOrUpdateExtLink($invoice->id, Facture::class, $flowId, $syncStatus, $syncRef, $syncComment);

		// Log an event in the invoice timeline
		$eventLabel = "PDPCONNECTFR - Status: " . $ack_statusLabel;
		$eventMessage = "PDPCONNECTFR - Status: " . $ack_statusLabel . (!empty($syncComment) ? " - " . $syncComment : "");

		$resLogEvent = $provider->addEvent('STATUS', $eventLabel, $eventMessage, $invoice);
		if ($resLogEvent < 0) {
			dol_syslog(__METHOD__ . " Failed to log event for flowId: {$flowId}", LOG_WARNING);
		}

		// Refresh current status info
		require_once "../class/pdpconnectfr.class.php";
		$pdpconnectfr = new PdpConnectFr($db);
		$currentStatusInfo = $pdpconnectfr->fetchLastknownInvoiceStatus($invoice->ref);

		print json_encode($currentStatusInfo);
	} else {
		print json_encode(['code' => -1, 'status' => 'N/A', 'info' => 'Error retrieving validation status from PDP for invoice ref '. $invoice->ref]);
		exit;
	}
}

$db->close();
