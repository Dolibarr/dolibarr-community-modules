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
 *       \file       htdocs/pdpconnectfr/ajax/document.php
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

dol_syslog("Call ajax pdpconnectfr/ajax/document.php");
$langs->load('pdpconnectfr@pdpconnectfr');

top_httphead();
// Update the object field with the new value
if ($objectRef) {

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
	$sql = "SELECT rowid, flow_id";
	$sql .= " FROM ".MAIN_DB_PREFIX."pdpconnectfr_document";
	$sql .= " WHERE fk_element_type = '".$db->escape(Facture::class). "'";
	$sql .= " AND fk_element_id = ".((int) $invoice->id);
	$sql .= " AND flow_type = 'ManualValidationCheck'";
	$sql .= " ORDER BY rowid DESC LIMIT 1";
	$result = $db->query($sql);
	if ($result) {
		if ($db->num_rows($result) > 0) {
			$obj = $db->fetch_object($result);
			$flowId = $obj->flow_id;;
		}
	} else {
		print json_encode(['status' => 'error', 'message' => 'Error retrieving flowId for invoice ref '. $objectRef]);
		exit;
	}


	// make a call to get validation result from PDP
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
		'Check Invoice validation'
	);

	if ($response['status_code'] == 200 || $response['status_code'] == 202) {
		dol_include_once('pdpconnectfr/class/document.class.php');

		$flowData = json_decode($response['response'], true);

		// Create a document log entry for this flow retrieval
		$document = new Document($db);
		$document->date_creation        = dol_now();
		$document->fk_user_creat        = $user->id;
		$document->fk_call              = null;
		$document->flow_id              = $flowId;
		$document->tracking_idref       = $flowData['trackingId'] ?? null;
		$document->flow_type            = "ManualValidationCheck";
		$document->flow_direction       = $flowData['flowDirection'] ?? null;
		$document->flow_syntax          = $flowData['flowSyntax'] ?? null;
		$document->flow_profile         = $flowData['flowProfile'] ?? null;
		$document->ack_status           = $flowData['acknowledgement']['status'] ?? null;
		$ack_message = '';
		// Change this fields to fit with the new api response ===============================================
		$document->ack_reason_code      = $flowData['acknowledgement']['details'][0]['reasonCode'] ?? null;
		$document->ack_info             = $flowData['acknowledgement']['details'][0]['reasonMessage'] ?? null;
		// Change this fields to fit with the new api response ===============================================
		$document->document_body        = null;
		$document->fk_element_id        = $invoice->id;
		$document->fk_element_type      = Facture::class;

		if (!empty($flowData['submittedAt'])) {
			$dt = new DateTimeImmutable($flowData['submittedAt'], new DateTimeZone('UTC'));
			$document->submittedat = $db->idate($dt->getTimestamp());
		} else {
			$document->submittedat = null;
		}
		if (!empty($flowData['updatedAt'])) {
			$dt = new DateTimeImmutable($flowData['updatedAt'], new DateTimeZone('UTC'));
			$document->updatedat = $db->idate($dt->getTimestamp());
		} else {
			$document->updatedat = null;
		}
		$document->provider             = getDolGlobalString('PDPCONNECTFR_PDP') ?? null;
		$document->entity               = $conf->entity;
		$document->flow_uiid            = $flowData['uuid'] ?? null;

		// Log an event in the invoice timeline
		$statusLabel = $document->ack_status;
		$reasonDetail = $document->ack_info ? " - {$document->ack_info}" : '';

		$eventLabel = "PDPCONNECTFR - Status: {$statusLabel}";
		$eventMessage = "PDPCONNECTFR - Status: {$statusLabel}{$reasonDetail}";

		$resLogEvent = $provider->addEvent('STATUS', $eventLabel, $eventMessage, $invoice);
		if ($resLogEvent < 0) {
			dol_syslog(__METHOD__ . " Failed to log event for flowId: {$flowId}", LOG_WARNING);
		}

		$res = $document->create($user);
		if ($res < 0) {
			//print_r($document->errors);
			dol_syslog(__METHOD__ . " Failed to create document log for flowId: {$flowId}", LOG_WARNING);
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
