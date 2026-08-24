<?php
/* Copyright (C) 2026 Eric Seigne <eric.seigne@cap-rel.fr>
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
 *  \file       stancer_repair.php
 *  \ingroup    stancer
 *  \brief      Stancer verification & repair tools (admin only).
 *
 *  First tool: detect Stancer customers (cust_xxx) shared between several
 *  Dolibarr thirdparties (the 2026-06 cross-customer leak) and repair them -
 *  keep the cust on its real owner, give every intruder its own distinct
 *  cust_xxx, delete the bogus societe_rib links and relabel the payments.
 *
 *  Detection is read-only. Repair is explicit (button), supports a dry-run,
 *  is atomic per thirdparty and traced via ActionComm.
 */

// The repair AJAX endpoint must NOT renew the CSRF token, otherwise a second
// call from the same page would fail. Define NOTOKENRENEWAL before main.inc.php.
$preAction = '';
if (isset($_POST['action'])) {
	$preAction = (string) $_POST['action'];
} elseif (isset($_GET['action'])) {
	$preAction = (string) $_GET['action'];
}
if ($preAction === 'repair' || $preAction === 'forcepost') {
	if (!defined('NOTOKENRENEWAL')) {
		define('NOTOKENRENEWAL', '1');
	}
}
unset($preAction);

// Load Dolibarr environment
$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"] . "/main.inc.php";
}
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
	$i--;
	$j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1)) . "/main.inc.php")) {
	$res = @include substr($tmp, 0, ($i + 1)) . "/main.inc.php";
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1))) . "/main.inc.php")) {
	$res = @include dirname(substr($tmp, 0, ($i + 1))) . "/main.inc.php";
}
if (!$res && file_exists("../main.inc.php")) {
	$res = @include "../main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';

dol_include_once('/stancer/lib/stancer.lib.php');
dol_include_once('/stancer/lib/stancer_repair.lib.php');

/**
 * @var Conf       $conf
 * @var DoliDB     $db
 * @var Translate  $langs
 * @var User       $user
 */

$langs->loadLangs(array("stancer@stancer", "compta", "bills", "main", "companies"));

// Admin only: this tool reads customer-level data across the whole base, talks
// to the Stancer API and rewrites payment links. Restrict to admins.
if (empty($user->admin)) {
	accessforbidden();
}

$action = (string) GETPOST('action', 'aZ09');

/**
 * Validate a cust_xxx id coming from the request.
 *
 * @param  string $cust Raw customer id.
 * @return bool         True if it looks like a Stancer customer id.
 */
function stancerRepairValidCustId($cust)
{
	return (bool) preg_match('/^cust_[A-Za-z0-9]+$/', (string) $cust);
}

// =========================================================================
// AJAX endpoint: POST stancer_repair.php?action=repair
//   customer=cust_xxx, owner_socid=<id>, dry_run=0|1
//   -> JSON result from stancerRepairSharedCustomer().
// =========================================================================
if ($action === 'repair') {
	header('Content-Type: application/json; charset=utf-8');

	if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
		http_response_code(405);
		print json_encode(array('success' => false, 'message' => 'POST required'));
		exit;
	}

	$customer   = (string) GETPOST('customer', 'alphanohtml');
	$ownerSocid = (int) GETPOST('owner_socid', 'int');
	$dryRun     = ((int) GETPOST('dry_run', 'int')) === 1;

	if (!stancerRepairValidCustId($customer)) {
		http_response_code(400);
		print json_encode(array('success' => false, 'message' => 'Invalid customer id'));
		exit;
	}
	if ($ownerSocid <= 0) {
		http_response_code(400);
		print json_encode(array('success' => false, 'message' => 'Missing owner_socid'));
		exit;
	}

	$stancerApi = new StancerApi();
	$repair = stancerRepairSharedCustomer($customer, $ownerSocid, $db, $user, $stancerApi, $dryRun);
	print json_encode($repair);
	exit;
}

// =========================================================================
// AJAX endpoint: POST stancer_repair.php?action=forcepost
//   paym_id=paym_xxx
//   -> JSON. Supervised force-post (bypasses ONLY the customer guard).
// =========================================================================
if ($action === 'forcepost') {
	header('Content-Type: application/json; charset=utf-8');

	if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
		http_response_code(405);
		print json_encode(array('success' => false, 'message' => 'POST required'));
		exit;
	}

	$paymId = (string) GETPOST('paym_id', 'alphanohtml');
	if (!preg_match('/^paym_[A-Za-z0-9]+$/', $paymId)) {
		http_response_code(400);
		print json_encode(array('success' => false, 'message' => 'Invalid paym_id'));
		exit;
	}

	$allowOverpay = ((int) GETPOST('allow_overpay', 'int')) === 1;
	$stancerApi = new StancerApi();
	$forced = stancerForcePostPayment($paymId, $db, $user, $stancerApi, $allowOverpay);
	print json_encode($forced);
	exit;
}

$title = $langs->trans("StancerRepairTitle");
llxHeader('', $title, '', '', 0, 0, array(), array(), '', 'mod-stancer page-repair');

print load_fiche_titre($title, '', 'object_payment');
print '<p>' . $langs->trans("StancerRepairIntro") . '</p>';

// Launch button.
print '<form method="POST" action="' . $_SERVER["PHP_SELF"] . '">';
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" name="action" value="run">';
print '<div class="center"><input type="submit" class="button" name="submit" value="'
	. dol_escape_htmltag($langs->trans("StancerRepairScan")) . '"></div>';
print '</form>';

if ($action !== 'run') {
	llxFooter();
	$db->close();
	exit;
}

// =========================================================================
// Scan
// =========================================================================
print '<br><h3>' . $langs->trans("StancerRepairSharedTitle") . '</h3>';

$sharedIds = stancerFindSharedCustomerIds($db);
dol_syslog("stancer_repair scan: " . count($sharedIds) . " shared customer(s) found", LOG_NOTICE);

$stancerApi = new StancerApi();
$repairToken = currentToken();

if (empty($sharedIds)) {
	print '<div class="info">' . $langs->trans("StancerRepairNoneFound") . '</div>';
} else {
	print '<div class="warning">' . $langs->trans("StancerRepairFoundCount", count($sharedIds)) . '</div>';
}

foreach ($sharedIds as $cust) {
	$detail = stancerGetSharedCustomerDetail($cust, $db);

	// Ask the API who really owns this customer (email/name).
	$apiCust = $stancerApi->getCustomer($cust);
	$apiEmail = ($apiCust !== false && isset($apiCust['email'])) ? (string) $apiCust['email'] : '';
	$apiName  = ($apiCust !== false && isset($apiCust['name']))  ? (string) $apiCust['name']  : '';
	$apiErr   = ($apiCust === false) ? ($stancerApi->error . ' (HTTP ' . (int) $stancerApi->lastHttpCode . ')') : '';

	$owner = stancerResolveSharedCustomerOwner($detail, $apiEmail);
	$ownerSocid = (int) $owner['owner_socid'];

	print '<div class="stancer-shared-cust" style="border:1px solid #ccc; border-radius:6px; padding:12px; margin:14px 0;">';
	print '<h4 style="margin-top:0;"><code>' . dol_escape_htmltag($cust) . '</code> ';
	print '<span class="badge badge-status8">'
		. dol_escape_htmltag($langs->trans("StancerRepairSharedBetween", count($detail['socids']))) . '</span></h4>';

	// Stancer API identity of the customer.
	print '<p class="opacitymedium" style="margin:4px 0;">';
	if ($apiErr !== '') {
		print dol_escape_htmltag($langs->trans("StancerRepairApiError", $apiErr));
	} else {
		print $langs->trans("StancerRepairApiCustomer") . ' : <strong>' . dol_escape_htmltag($apiName) . '</strong> &lt;' . dol_escape_htmltag($apiEmail) . '&gt;';
	}
	print '</p>';

	// Owner resolution.
	print '<p style="margin:4px 0;">' . $langs->trans("StancerRepairOwner") . ' : ';
	if ($owner['confident'] && $ownerSocid > 0) {
		$ownerName = isset($detail['socids'][$ownerSocid]['name']) ? $detail['socids'][$ownerSocid]['name'] : ('#' . $ownerSocid);
		print '<strong>' . dol_escape_htmltag($ownerName) . '</strong> (#' . $ownerSocid . ') <span class="badge badge-status4">' . dol_escape_htmltag($langs->trans("StancerRepairOwnerConfident")) . '</span>';
	} else {
		print '<span class="badge badge-status1">' . dol_escape_htmltag($langs->trans("StancerRepairOwnerUnknown")) . '</span> <small class="opacitymedium">' . dol_escape_htmltag($owner['reason']) . '</small>';
	}
	print '</p>';

	// Linked thirdparties table.
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre">';
	print '<th>' . $langs->trans("ThirdParty") . '</th>';
	print '<th>' . $langs->trans("Email") . '</th>';
	print '<th class="center">' . $langs->trans("StancerRepairRibLink") . '</th>';
	print '<th class="center">' . $langs->trans("StancerRepairNbPayments") . '</th>';
	print '<th class="right">' . $langs->trans("Amount") . '</th>';
	print '<th class="center">' . $langs->trans("StancerRepairRole") . '</th>';
	print '</tr>';

	foreach ($detail['socids'] as $socid => $info) {
		$socUrl = DOL_URL_ROOT . '/societe/card.php?socid=' . (int) $socid;
		$isOwner = ($ownerSocid > 0 && (int) $socid === $ownerSocid);
		print '<tr class="oddeven">';
		print '<td><a href="' . $socUrl . '">' . dol_escape_htmltag($info['name'] !== '' ? $info['name'] : ('#' . $socid)) . '</a> <small class="opacitymedium">#' . (int) $socid . '</small></td>';
		print '<td>' . dol_escape_htmltag($info['email']) . '</td>';
		print '<td class="center">' . ($info['in_rib']
			? '<span class="badge badge-status8">' . count($info['rib_rowids']) . '</span>'
			: '<span class="opacitymedium">-</span>') . '</td>';
		print '<td class="center">' . (int) $info['nb_payments'] . '</td>';
		print '<td class="right">' . price((float) $info['amount_total']) . '</td>';
		print '<td class="center">';
		if ($isOwner) {
			print '<span class="badge badge-status4">' . dol_escape_htmltag($langs->trans("StancerRepairRoleOwner")) . '</span>';
		} else {
			print '<span class="badge badge-status8">' . dol_escape_htmltag($langs->trans("StancerRepairRoleIntruder")) . '</span>';
		}
		print '</td>';
		print '</tr>';
	}
	print '</table>';

	// Repair form: choose the owner (pre-selected if resolved) and act.
	print '<div style="margin-top:10px;" class="stancer-repair-block" data-customer="' . dol_escape_htmltag($cust) . '">';
	print '<label>' . $langs->trans("StancerRepairKeepOwner") . ' : </label>';
	print '<select class="stancer-repair-owner flat">';
	foreach ($detail['socids'] as $socid => $info) {
		$sel = ($ownerSocid > 0 && (int) $socid === $ownerSocid) ? ' selected' : '';
		print '<option value="' . (int) $socid . '"' . $sel . '>'
			. dol_escape_htmltag(($info['name'] !== '' ? $info['name'] : ('#' . $socid)) . ' (#' . $socid . ')') . '</option>';
	}
	print '</select> ';
	print '<button type="button" class="button stancer-repair-sim">' . dol_escape_htmltag($langs->trans("StancerRepairSimulate")) . '</button> ';
	print '<button type="button" class="butActionDelete stancer-repair-apply">' . dol_escape_htmltag($langs->trans("StancerRepairApply")) . '</button>';
	print '<div class="stancer-repair-result" style="margin-top:8px;"></div>';
	print '</div>';

	print '</div>'; // .stancer-shared-cust
}

// =========================================================================
// Section 2: Stancer payments paid upstream but with no Dolibarr Paiement
// (typically refused by the misattribution guard because the frozen customer
// points elsewhere). Supervised force-post per row.
// =========================================================================
print '<br><h3>' . $langs->trans("StancerRepairUnpostedTitle") . '</h3>';
print '<p>' . $langs->trans("StancerRepairUnpostedIntro") . '</p>';

// Manual force-post: post any Stancer payment by its paym_id, even when it is not
// mirrored locally (e.g. older than the sync window, so it never appears in the
// auto-detected list below). Same endpoint/guards as the per-row button: the
// customer guard is bypassed (the "wrong customer" is the known Stancer id-mixing
// bug), while order_id, amount and fiscal-year guards are still enforced.
print '<div class="stancer-manual-forcepost" style="border:1px solid #ccc; border-radius:6px; padding:12px; margin:10px 0;">';
print '<label for="stancer-manual-paymid"><strong>' . dol_escape_htmltag($langs->trans("StancerRepairManualForcePostLabel")) . '</strong></label> ';
print '<input type="text" id="stancer-manual-paymid" class="flat" size="40" placeholder="paym_..." autocomplete="off"> ';
print '<button type="button" id="stancer-manual-forcepost-btn" class="butAction">' . dol_escape_htmltag($langs->trans("StancerRepairForcePost")) . '</button>';
print '<div id="stancer-manual-forcepost-result" style="margin-top:8px;"></div>';
print '<p class="opacitymedium" style="margin:6px 0 0;">' . dol_escape_htmltag($langs->trans("StancerRepairManualForcePostHint")) . '</p>';
print '</div>';

$liveModeScan = (getDolGlobalString('STANCER_IS_PROD', '0') === '1');
// Lower bound: first day of the current fiscal year. Invoices dated before it are
// in a closed period (already booked / in the balance sheet) and must not be shown
// nor acted upon as doubles.
$fiscalStartTs = stancerGetFiscalYearStartTs();
$candidates = stancerFindCapturedPaymentsNotPosted($db, $liveModeScan);
// The local status can be stale (refused upstream but still flagged captured):
// confirm the REAL status via the API and only keep the truly-paid ones here.
// A per-payment TTL cache (tms) avoids re-hitting the API on a page refresh.
$statusTtl = (int) getDolGlobalString('STANCER_REPAIR_STATUS_TTL', '3600');
$split = stancerSplitCapturedByApiStatus($candidates, $stancerApi, $db, $statusTtl);
$notPosted = $split['paid'];

if (empty($notPosted)) {
	print '<div class="info">' . $langs->trans("StancerRepairUnpostedNone") . '</div>';
} else {
	// Resolve thirdparty names in one query.
	$socNames = array();
	$socIds = array();
	foreach ($notPosted as $p) {
		if ((int) $p->fk_soc > 0) {
			$socIds[(int) $p->fk_soc] = (int) $p->fk_soc;
		}
	}
	if (!empty($socIds)) {
		$sqlNames = "SELECT rowid, nom FROM " . MAIN_DB_PREFIX . "societe WHERE rowid IN (" . implode(',', $socIds) . ")";
		$resNames = $db->query($sqlNames);
		if ($resNames) {
			while (($rn = $db->fetch_object($resNames))) {
				$socNames[(int) $rn->rowid] = (string) $rn->nom;
			}
			$db->free($resNames);
		}
	}

	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre">';
	print '<th>' . $langs->trans("Date") . '</th>';
	print '<th>' . $langs->trans("StancerRepairPaymId") . '</th>';
	print '<th>' . $langs->trans("ThirdParty") . '</th>';
	print '<th>' . $langs->trans("StancerRepairOrderId") . '</th>';
	print '<th class="right">' . $langs->trans("Amount") . '</th>';
	print '<th>' . $langs->trans("StancerRepairInvoiceState") . '</th>';
	print '<th>' . $langs->trans("StancerRepairActions") . '</th>';
	print '</tr>';

	foreach ($notPosted as $p) {
		$paymId = (string) $p->stancer_id;
		$socid  = (int) $p->fk_soc;
		$socLabel = isset($socNames[$socid]) && $socNames[$socid] !== '' ? $socNames[$socid] : ('#' . $socid);

		// Resolve every invoice covered by this payment (a grouped SEPA payment covers
		// several) and aggregate their settlement state.
		$invoices = stancerResolveInvoicesForPayment($p, $db);
		$agg = stancerAggregateInvoiceState($invoices, $db);

		// Closed fiscal year: skip if the earliest covered invoice is before the
		// current fiscal year (already booked / in the balance sheet).
		if (!empty($agg['found']) && !empty($agg['earliest_date']) && (int) $agg['earliest_date'] < $fiscalStartTs) {
			continue;
		}

		print '<tr class="oddeven stancer-forcepost-row" data-paym-id="' . dol_escape_htmltag($paymId) . '">';
		print '<td class="nowrap">' . dol_print_date($db->jdate($p->date_creation), 'day') . '</td>';
		print '<td><code>' . dol_escape_htmltag($paymId) . '</code></td>';
		print '<td>';
		if ($socid > 0) {
			print '<a href="' . DOL_URL_ROOT . '/societe/card.php?socid=' . $socid . '">' . dol_escape_htmltag($socLabel) . '</a>';
		} else {
			print '<span class="opacitymedium">-</span>';
		}
		print '</td>';
		print '<td>';
		if ($agg['found']) {
			$links = array();
			foreach ($agg['invoices'] as $iv) {
				$ordUrl = dol_buildpath('/compta/facture/card.php', 1) . '?id=' . (int) $iv['id'];
				$links[] = '<a href="' . $ordUrl . '">' . dol_escape_htmltag($iv['ref'] !== '' ? $iv['ref'] : ('#' . (int) $iv['id'])) . '</a>';
			}
			print implode('+', $links);
		} else {
			print dol_escape_htmltag((string) $p->order_id);
		}
		print '</td>';
		print '<td class="right nowrap">' . price(((int) $p->amount) / 100) . '</td>';

		// Invoice settlement state (double-payment Form A detection).
		print '<td>';
		if (!$agg['found']) {
			print '<span class="opacitymedium">' . dol_escape_htmltag($langs->trans("StancerRepairInvoiceNotFound")) . '</span>';
		} elseif (!empty($agg['is_settled'])) {
			$methods = !empty($agg['methods']) ? implode(', ', $agg['methods']) : '?';
			print '<span class="badge badge-status8">' . dol_escape_htmltag($langs->trans("StancerRepairAlreadySettled")) . '</span>';
			print ' <small class="opacitymedium">' . dol_escape_htmltag($langs->trans("StancerRepairPaidVia", $methods)) . '</small>';
			if (!empty($agg['grouped'])) {
				print ' <small class="opacitymedium">(' . dol_escape_htmltag($langs->trans("StancerRepairGroupedInvoices", (int) $agg['count'])) . ')</small>';
			}
		} else {
			print dol_escape_htmltag($langs->trans("StancerRepairRemaining", price((float) $agg['remaining'])));
			if (!empty($agg['grouped'])) {
				print ' <small class="opacitymedium">(' . dol_escape_htmltag($langs->trans("StancerRepairGroupedInvoices", (int) $agg['count'])) . ')</small>';
			}
		}
		print '</td>';

		// Action: force-post only when the invoice(s) are NOT already settled
		// (forcing a settled invoice would over-pay it - and Guard 3 would refuse).
		print '<td class="nowrap stancer-forcepost-cell">';
		if ($agg['found'] && !empty($agg['is_settled'])) {
			// Already settled (double): offer to add the payment anyway (re-opens the
			// invoice + records the over-pay), beside the refund hint. Over-pay is only
			// offered for single invoices (grouped over-pay is not supported).
			print '<span class="opacitymedium">' . dol_escape_htmltag($langs->trans("StancerRepairDoubleHint")) . '</span>';
			if (empty($agg['grouped'])) {
				print '<br><button type="button" class="button stancer-forcepost-overpay-btn" data-paym-id="' . dol_escape_htmltag($paymId) . '">'
					. dol_escape_htmltag($langs->trans("StancerRepairAddAnyway")) . '</button>';
			}
		} else {
			print '<button type="button" class="butAction stancer-forcepost-btn" data-paym-id="' . dol_escape_htmltag($paymId) . '">'
				. dol_escape_htmltag($langs->trans("StancerRepairForcePost")) . '</button>';
		}
		print '<div class="stancer-forcepost-result" style="margin-top:4px;"></div>';
		print '</td>';
		print '</tr>';
	}
	print '</table>';
}

// Candidates whose REAL Stancer status is NOT a paid one (refused/canceled/
// expired/... or unconfirmed): the local status is stale. Listed apart so they
// are never mistaken for captured payments or doubles.
if (!empty($split['notpaid'])) {
	print '<div class="warning" style="margin-top:8px;">'
		. dol_escape_htmltag($langs->trans("StancerRepairStaleStatusIntro", count($split['notpaid']))) . '</div>';
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre">';
	print '<th>' . $langs->trans("StancerRepairPaymId") . '</th>';
	print '<th>' . $langs->trans("StancerRepairOrderId") . '</th>';
	print '<th class="right">' . $langs->trans("Amount") . '</th>';
	print '<th>' . $langs->trans("StancerRepairRealStatus") . '</th>';
	print '</tr>';
	foreach ($split['notpaid'] as $p) {
		$st = isset($p->api_status) && $p->api_status !== '' ? $p->api_status : $langs->trans("StancerRepairStatusUnknown");
		print '<tr class="oddeven" style="opacity:0.7;">';
		print '<td><code>' . dol_escape_htmltag((string) $p->stancer_id) . '</code></td>';
		print '<td>' . dol_escape_htmltag((string) $p->order_id) . '</td>';
		print '<td class="right nowrap">' . price(((int) $p->amount) / 100) . '</td>';
		print '<td><span class="badge badge-status0">' . dol_escape_htmltag($st) . '</span></td>';
		print '</tr>';
	}
	print '</table>';
}

// =========================================================================
// Section 3: invoices over-paid with a Stancer payment (double-payment Form B:
// the Stancer payment AND another mean were both reconciled).
// =========================================================================
print '<br><h3>' . $langs->trans("StancerRepairOverpaidTitle") . '</h3>';
print '<p>' . $langs->trans("StancerRepairOverpaidIntro") . '</p>';

$overpaid = stancerFindOverpaidWithStancer($db);
if (empty($overpaid)) {
	print '<div class="info">' . $langs->trans("StancerRepairOverpaidNone") . '</div>';
} else {
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre">';
	print '<th>' . $langs->trans("StancerRepairDbInvoice") . '</th>';
	print '<th>' . $langs->trans("ThirdParty") . '</th>';
	print '<th class="right">' . $langs->trans("AmountTTC") . '</th>';
	print '<th class="right">' . $langs->trans("StancerRepairPaid") . '</th>';
	print '<th class="right">' . $langs->trans("StancerRepairExcess") . '</th>';
	print '<th>' . $langs->trans("StancerRepairPaymentMethods") . '</th>';
	print '</tr>';
	foreach ($overpaid as $ov) {
		$invUrl = dol_buildpath('/compta/facture/card.php', 1) . '?id=' . (int) $ov['invoice_id'];
		$socName = $ov['soc_name'] !== '' ? $ov['soc_name'] : ('#' . (int) $ov['fk_soc']);
		print '<tr class="oddeven" style="background-color: rgba(220, 53, 69, 0.10);">';
		print '<td class="nowrap"><a href="' . $invUrl . '">' . dol_escape_htmltag($ov['invoice_ref']) . '</a></td>';
		print '<td><a href="' . DOL_URL_ROOT . '/societe/card.php?socid=' . (int) $ov['fk_soc'] . '">' . dol_escape_htmltag($socName) . '</a></td>';
		print '<td class="right nowrap">' . price((float) $ov['total_ttc']) . '</td>';
		print '<td class="right nowrap">' . price((float) $ov['paid']) . '</td>';
		print '<td class="right nowrap"><strong>' . price((float) $ov['excess']) . '</strong></td>';
		print '<td>' . dol_escape_htmltag(implode(', ', $ov['methods'])) . '</td>';
		print '</tr>';
	}
	print '</table>';
}

$ajaxUrl = $_SERVER['PHP_SELF'];
?>
<script type="text/javascript">
(function () {
	var URL = <?php echo json_encode($ajaxUrl); ?>;
	var TOKEN = <?php echo json_encode($repairToken); ?>;
	var INVOICE_URL = <?php echo json_encode(dol_buildpath('/compta/facture/card.php', 1)); ?>;
	var TXT = {
		confirmApply: <?php echo json_encode($langs->transnoentitiesnoconv("StancerRepairConfirmApply")); ?>,
		working:      <?php echo json_encode($langs->transnoentitiesnoconv("StancerRepairWorking")); ?>,
		networkError: <?php echo json_encode($langs->transnoentitiesnoconv("StancerRepairNetworkError")); ?>,
		planned:      <?php echo json_encode($langs->transnoentitiesnoconv("StancerRepairPlanned")); ?>,
		done:         <?php echo json_encode($langs->transnoentitiesnoconv("StancerRepairDoneLabel")); ?>,
		errorLabel:   <?php echo json_encode($langs->transnoentitiesnoconv("StancerRepairErrorLabel")); ?>,
		confirmForce: <?php echo json_encode($langs->transnoentitiesnoconv("StancerRepairConfirmForce")); ?>,
		confirmOverpay: <?php echo json_encode($langs->transnoentitiesnoconv("StancerRepairConfirmOverpay")); ?>,
		invalidPaymId: <?php echo json_encode($langs->transnoentitiesnoconv("StancerRepairInvalidPaymId")); ?>
	};

	function escapeHtml(s) {
		if (s === null || s === undefined) return '';
		return String(s).replace(/[&<>"']/g, function (c) {
			return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
		});
	}

	function renderResult(data) {
		if (!data || typeof data !== 'object') {
			return '<div class="error">' + escapeHtml(TXT.errorLabel) + '</div>';
		}
		var html = '<div class="' + (data.success ? 'ok' : 'warning') + '">' + escapeHtml(data.message || '') + '</div>';
		if (data.actions && data.actions.length) {
			html += '<ul style="margin:6px 0;">';
			data.actions.forEach(function (a) {
				var tag = a.status === 'done' ? TXT.done : (a.status === 'planned' ? TXT.planned : TXT.errorLabel);
				html += '<li><strong>' + escapeHtml(a.name) + '</strong> (#' + escapeHtml(a.socid) + ') : '
					+ escapeHtml(tag) + ' - ' + escapeHtml(a.message || '')
					+ (a.new_cust ? ' [' + escapeHtml(a.new_cust) + ']' : '') + '</li>';
			});
			html += '</ul>';
		}
		return html;
	}

	function send(block, dryRun, btn) {
		var customer = block.getAttribute('data-customer');
		var ownerSel = block.querySelector('.stancer-repair-owner');
		var resultEl = block.querySelector('.stancer-repair-result');
		var ownerSocid = ownerSel ? ownerSel.value : '';

		if (!dryRun && !window.confirm(TXT.confirmApply)) {
			return;
		}

		resultEl.innerHTML = '<div class="opacitymedium">' + escapeHtml(TXT.working) + '</div>';
		var buttons = block.querySelectorAll('button');
		buttons.forEach(function (b) { b.disabled = true; });

		var fd = new FormData();
		fd.append('customer', customer);
		fd.append('owner_socid', ownerSocid);
		fd.append('dry_run', dryRun ? '1' : '0');
		fd.append('token', TOKEN);

		fetch(URL + '?action=repair', {
			method: 'POST',
			body: fd,
			credentials: 'same-origin'
		}).then(function (resp) {
			return resp.json().catch(function () { throw new Error('HTTP ' + resp.status); });
		}).then(function (data) {
			resultEl.innerHTML = renderResult(data);
			buttons.forEach(function (b) { b.disabled = false; });
		}).catch(function (err) {
			resultEl.innerHTML = '<div class="error">' + escapeHtml(TXT.networkError) + ' ' + escapeHtml(err.message) + '</div>';
			buttons.forEach(function (b) { b.disabled = false; });
		});
	}

	function sendForce(btn, overpay) {
		var row = btn.closest ? btn.closest('.stancer-forcepost-row') : null;
		var resultEl = row ? row.querySelector('.stancer-forcepost-result') : null;
		var paymId = btn.getAttribute('data-paym-id');
		if (!window.confirm(overpay ? TXT.confirmOverpay : TXT.confirmForce)) { return; }
		if (resultEl) { resultEl.innerHTML = '<span class="opacitymedium">' + escapeHtml(TXT.working) + '</span>'; }
		btn.disabled = true;

		var fd = new FormData();
		fd.append('paym_id', paymId);
		fd.append('allow_overpay', overpay ? '1' : '0');
		fd.append('token', TOKEN);
		fetch(URL + '?action=forcepost', { method: 'POST', body: fd, credentials: 'same-origin' })
			.then(function (resp) { return resp.json().catch(function () { throw new Error('HTTP ' + resp.status); }); })
			.then(function (data) {
				var cls = data.success ? 'ok' : 'error';
				var refHtml = '';
				if (data.invoice_ref) {
					if (data.invoice_id) {
						refHtml = ' [<a href="' + INVOICE_URL + '?id=' + encodeURIComponent(data.invoice_id) + '">'
							+ escapeHtml(data.invoice_ref) + '</a>]';
					} else {
						refHtml = ' [' + escapeHtml(data.invoice_ref) + ']';
					}
				}
				if (resultEl) { resultEl.innerHTML = '<span class="' + cls + '">' + escapeHtml(data.message || '') + refHtml + '</span>'; }
				if (!data.success) { btn.disabled = false; }
			})
			.catch(function (err) {
				if (resultEl) { resultEl.innerHTML = '<span class="error">' + escapeHtml(TXT.networkError) + ' ' + escapeHtml(err.message) + '</span>'; }
				btn.disabled = false;
			});
	}

	function sendForceManual() {
		var input = document.getElementById('stancer-manual-paymid');
		var resultEl = document.getElementById('stancer-manual-forcepost-result');
		var btn = document.getElementById('stancer-manual-forcepost-btn');
		var paymId = (input && input.value ? input.value : '').trim();
		if (!/^paym_[A-Za-z0-9]+$/.test(paymId)) {
			resultEl.innerHTML = '<span class="error">' + escapeHtml(TXT.invalidPaymId) + '</span>';
			return;
		}
		if (!window.confirm(TXT.confirmForce)) { return; }
		resultEl.innerHTML = '<span class="opacitymedium">' + escapeHtml(TXT.working) + '</span>';
		btn.disabled = true;

		var fd = new FormData();
		fd.append('paym_id', paymId);
		fd.append('allow_overpay', '0');
		fd.append('token', TOKEN);
		fetch(URL + '?action=forcepost', { method: 'POST', body: fd, credentials: 'same-origin' })
			.then(function (resp) { return resp.json().catch(function () { throw new Error('HTTP ' + resp.status); }); })
			.then(function (data) {
				var cls = data.success ? 'ok' : 'error';
				var refHtml = '';
				if (data.invoice_ref) {
					if (data.invoice_id) {
						refHtml = ' [<a href="' + INVOICE_URL + '?id=' + encodeURIComponent(data.invoice_id) + '">'
							+ escapeHtml(data.invoice_ref) + '</a>]';
					} else {
						refHtml = ' [' + escapeHtml(data.invoice_ref) + ']';
					}
				}
				resultEl.innerHTML = '<span class="' + cls + '">' + escapeHtml(data.message || '') + refHtml + '</span>';
				btn.disabled = false;
			})
			.catch(function (err) {
				resultEl.innerHTML = '<span class="error">' + escapeHtml(TXT.networkError) + ' ' + escapeHtml(err.message) + '</span>';
				btn.disabled = false;
			});
	}

	document.addEventListener('click', function (e) {
		if (e.target.id === 'stancer-manual-forcepost-btn') {
			sendForceManual();
			return;
		}
		if (e.target.classList.contains('stancer-forcepost-btn')) {
			sendForce(e.target, false);
			return;
		}
		if (e.target.classList.contains('stancer-forcepost-overpay-btn')) {
			sendForce(e.target, true);
			return;
		}
		var block = e.target.closest ? e.target.closest('.stancer-repair-block') : null;
		if (!block) return;
		if (e.target.classList.contains('stancer-repair-sim')) {
			send(block, true, e.target);
		} else if (e.target.classList.contains('stancer-repair-apply')) {
			send(block, false, e.target);
		}
	});
})();
</script>
<?php

llxFooter();
$db->close();
