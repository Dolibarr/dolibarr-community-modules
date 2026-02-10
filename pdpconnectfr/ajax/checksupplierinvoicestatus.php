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
 *       \file       htdocs/pdpconnectfr/ajax/checksupplierinvoicestatus.php
 *       \brief      File to return Ajax response on supplier invoice status
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
$objectID = GETPOSTINT('id');
// $field = GETPOST('field', 'aZ09');
// $value = GETPOST('value', 'aZ09');

// Security check
if (!$user->hasRight('pdpconnectfr', 'document', 'write')) {
	accessforbidden();
}

/*
 * View
 */

dol_syslog("Call ajax pdpconnectfr/ajax/checksupplierinvoicestatus.php");
$langs->load('pdpconnectfr@pdpconnectfr');

top_httphead();
// Update the object field with the new value
if ($objectID) {

	dol_include_once('pdpconnectfr/class/pdpconnectfr.class.php');
	$pdpconnectfr = new PdpConnectFr($db);

	// Load object
	require_once DOL_DOCUMENT_ROOT . '/fourn/class/fournisseur.facture.class.php';

	$invoice = new FactureFournisseur($db);
	$object = $invoice->fetch($objectID);
	if ($invoice->id <= 0) {
		print json_encode(['status' => 'error', 'message' => 'Error loading supplier invoice with id '. $objectID]);
		exit;
	}

	// Get flowId from linked document log
	$flowId = '';
	$sql = "SELECT rowid, flow_id, lc_status, lc_reason_code FROM ".MAIN_DB_PREFIX."pdpconnectfr_lifecycle_msg";
	$sql .= " WHERE element_type = '".$invoice->element."'";
	$sql .= " AND element_id = ".(int) $invoice->id;
	$sql .= " ORDER BY rowid DESC LIMIT 1";

	$resql = $db->query($sql);
	if ($resql) {
		if ($db->num_rows($resql) > 0) {
			$obj = $db->fetch_object($resql);
			$lcId = $obj->rowid;
			$flowId = $obj->flow_id;
			$lcStatus = $obj->lc_status;
			$lcReasonCode = $obj->lc_reason_code;
		}
	} else {
		print json_encode(['status' => 'error', 'message' => 'Error retrieving flowId for supplier invoice ref '. $invoice->ref]);
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
		'check_sentstatus_validation'
	);

	if ($response['status_code'] == 200 || $response['status_code'] == 202) {
		$flowData = json_decode($response['response'], true);

		$statusvalidationlabel = $flowData['acknowledgement']['status'] ?? '';
		$statusvalidationinfo = $flowData['acknowledgement']['details'][0]['reasonMessage'] ?? '';

		$pdpconnectfr->updateStatusMessageValidation($lcId, '', $statusvalidationlabel, $statusvalidationinfo );

		// Log an event in the invoice timeline
		$CurrentLCStatusLabel = $pdpconnectfr->getStatusLabel($obj->lc_status);
		$currentLCReasonLabel = $pdpconnectfr->getRaisonsByStatut($obj->lc_status)[$obj->lc_reason_code]['label'] ?? $obj->lc_reason_code;
		$eventLabel = "PDPCONNECTFR - Send status " . $CurrentLCStatusLabel . " : " . $statusvalidationlabel;
		$eventMessage = "PDPCONNECTFR - Send status " . $CurrentLCStatusLabel . " : " . $statusvalidationlabel . (!empty($statusvalidationinfo) ? " - " . $statusvalidationinfo : "") . (!empty($lcReasonCode) ? " - Reason: " . $currentLCReasonLabel : "");
		$resLogEvent = $provider->addEvent('STATUS', $eventLabel, $eventMessage, $invoice);
		if ($resLogEvent < 0) {
			dol_syslog(__METHOD__ . " Failed to log event for flowId: {$flowId}", LOG_WARNING);
		}

		// Prepare validation status to be displayed in the supplier invoice card
		if ($statusvalidationlabel === 'Pending') {
			$picto = img_picto('', 'timespent');
		} elseif ($statusvalidationlabel === 'Error') {
			$picto = img_picto('', 'error');
		}
		$htmlstatusvalidationLabel = ' : ' . $pdpconnectfr->getStatusLabel($pdpconnectfr->getDolibarrStatusCodeFromPdpLabel($statusvalidationlabel)) . ' ' .$picto;

		// Return current status of the supplier invoice to update it in the invoice card
		print json_encode([
			'statuslabel' => $CurrentLCStatusLabel,
			'statusreasonlabel' => $currentLCReasonLabel,
			'statusvalidationlabel' => $statusvalidationlabel,
			'htmlstatusvalidationLabel' => $htmlstatusvalidationLabel,
			'statusvalidationinfo' => $statusvalidationinfo,
		]);
	} else {
		print json_encode(['statusvalidationlabel' => 'N/A', 'statusvalidationinfo' => 'Error retrieving validation of sent status']);
		exit;
	}
}

$db->close();
