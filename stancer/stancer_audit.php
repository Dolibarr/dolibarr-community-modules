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
 *  \file       stancer_audit.php
 *  \ingroup    stancer
 *  \brief      Read-only audit of Stancer payment attributions.
 *
 *  For each Dolibarr Paiement tagged 'stancer', fetches the source of truth
 *  from the Stancer API and reports whether the local attribution (invoice,
 *  customer, amount) matches. Detects rows left in DB by the misattribution
 *  incident (NITD / PICHINOV / BLUE HORSE GROUP) before the defensive
 *  guards were merged.
 *
 *  This page is READ-ONLY. It does not write a single byte to the DB.
 *  Remediation is a separate concern.
 */

// AJAX endpoints below must NOT renew the CSRF token; otherwise the second AJAX
// call (Corriger / Ignorer) would fail because the JS still has the previous one.
// NOTOKENRENEWAL must be defined BEFORE main.inc.php is included, so we cannot
// rely on GETPOST() yet (it lives inside main.inc.php). Use $_REQUEST directly.
$preAction = '';
if (isset($_POST['action'])) {
	$preAction = (string) $_POST['action'];
} elseif (isset($_GET['action'])) {
	$preAction = (string) $_GET['action'];
}
if ($preAction === 'fix' || $preAction === 'ignore') {
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

require_once DOL_DOCUMENT_ROOT . '/core/class/html.formother.class.php';
require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';

dol_include_once('/stancer/lib/stancer.lib.php');
dol_include_once('/stancer/lib/stancer_audit.lib.php');

/**
 * @var Conf       $conf
 * @var DoliDB     $db
 * @var Translate  $langs
 * @var User       $user
 * @var HookManager $hookmanager
 */

$langs->loadLangs(array("stancer@stancer", "compta", "bills", "main"));

// Admin only: this audit pulls customer-level data across the whole base and
// requires the Stancer private key to talk to the API. Restrict to admins.
if (empty($user->admin)) {
	accessforbidden();
}

$action     = (string) GETPOST('action', 'aZ09');

// =========================================================================
// AJAX endpoints (must answer JSON BEFORE any HTML output).
//
// CSRF: Dolibarr main.inc.php verifies the 'token' POST against the session
// token when MAIN_SECURITY_CSRF_WITH_TOKEN is set. We also require POST to
// avoid stray GET hits (eg. preloaders, bookmarks).
//
// POST stancer_audit.php?action=fix&paiement_id=<id>
//   -> JSON. Reattaches the Paiement to the invoice indicated by the
//      Stancer API order_id. Atomic.
// POST stancer_audit.php?action=ignore&paiement_id=<id>
//   -> JSON. Marks the paym_id "ignored". Excluded from subsequent runs.
// =========================================================================
if ($action === 'fix' || $action === 'ignore') {
	header('Content-Type: application/json; charset=utf-8');

	if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
		http_response_code(405);
		print json_encode(array('success' => false, 'message' => 'POST required'));
		exit;
	}

	$paiementIdAjax = (int) GETPOST('paiement_id', 'int');
	if ($paiementIdAjax <= 0) {
		http_response_code(400);
		print json_encode(array('success' => false, 'message' => 'Missing paiement_id'));
		exit;
	}

	if ($action === 'fix') {
		$stancerApiAjax = new StancerApi();
		$ajaxResult = stancerAuditFix($paiementIdAjax, $db, $user, $stancerApiAjax);
	} else {
		$reasonAjax = (string) GETPOST('reason', 'restricthtml');
		$ajaxResult = stancerAuditIgnore($paiementIdAjax, $db, $user, $reasonAjax);
	}

	print json_encode($ajaxResult);
	exit;
}

// Form::selectDate('...', 'start', ...) emits inputs named 'startday', 'startmonth',
// 'startyear' (no underscore). Same for the 'end' prefix.
$dateStart  = dol_mktime(0, 0, 0, (int) GETPOST('startmonth', 'int'), (int) GETPOST('startday', 'int'), (int) GETPOST('startyear', 'int'));
$dateEnd    = dol_mktime(23, 59, 59, (int) GETPOST('endmonth', 'int'), (int) GETPOST('endday', 'int'), (int) GETPOST('endyear', 'int'));
$socid      = (int) GETPOST('socid', 'int');

// Default period: last 90 days when nothing is posted.
if (empty($dateStart)) {
	$dateStart = dol_now() - 90 * 86400;
}
if (empty($dateEnd)) {
	$dateEnd = dol_now();
}

$maxRows = (int) getDolGlobalString('STANCER_AUDIT_MAX_ROWS', '500');
if ($maxRows < 10) {
	$maxRows = 10;
}

$title = $langs->trans("StancerAuditTitle");
$form  = new Form($db);

llxHeader('', $title, '', '', 0, 0, array(), array(), '', 'mod-stancer page-audit');

print load_fiche_titre($title, '', 'object_payment');

print '<p>' . $langs->trans("StancerAuditIntro") . '</p>';

// =========================================================================
// Form
// =========================================================================
print '<form method="POST" action="' . $_SERVER["PHP_SELF"] . '">';
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" name="action" value="run">';

print '<table class="border centpercent">';
print '<tr><td class="titlefield">' . $langs->trans("DateStart") . '</td><td>';
print $form->selectDate($dateStart, 'start', 0, 0, 0, '', 1, 0);
print '</td></tr>';

print '<tr><td>' . $langs->trans("DateEnd") . '</td><td>';
print $form->selectDate($dateEnd, 'end', 0, 0, 0, '', 1, 0);
print '</td></tr>';

print '<tr><td>' . $langs->trans("ThirdParty") . '</td><td>';
print img_picto('', 'company', 'class="pictofixedwidth"');
print $form->select_company($socid, 'socid', '', 1, 0, 0, array(), 0, 'minwidth300');
print ' <span class="opacitymedium">' . $langs->trans("StancerAuditOptionalSocid") . '</span>';
print '</td></tr>';

print '<tr><td>' . $langs->trans("StancerAuditMaxRows") . '</td><td>';
print '<span class="opacitymedium">' . $langs->trans("StancerAuditMaxRowsHelp", $maxRows) . '</span>';
print '</td></tr>';

print '</table>';

print '<br><div class="center">';
print '<input type="submit" class="button" name="submit" value="' . dol_escape_htmltag($langs->trans("StancerAuditRun")) . '">';
print '</div>';
print '</form>';

// =========================================================================
// Run
// =========================================================================
if ($action === 'run') {
	print '<br><br>';
	print '<h3>' . $langs->trans("StancerAuditResults") . '</h3>';

	$startedAt = microtime(true);
	$rows = stancerAuditFetchRows($db, $dateStart, $dateEnd, $socid, $maxRows);
	if ($rows === false) {
		setEventMessages($langs->trans("StancerAuditSqlError"), null, 'errors');
		llxFooter();
		exit;
	}

	$tooMany = false;
	if (count($rows) > $maxRows) {
		$tooMany = true;
		$rows = array_slice($rows, 0, $maxRows);
	}

	dol_syslog("stancer_audit run start: " . count($rows) . " row(s) to audit, period=" .
		dol_print_date($dateStart, 'dayhour') . " -> " . dol_print_date($dateEnd, 'dayhour') .
		", socid=$socid, maxRows=$maxRows", LOG_NOTICE);

	if (empty($rows)) {
		print '<div class="info">' . $langs->trans("StancerAuditNoRows") . '</div>';
		llxFooter();
		exit;
	}

	if ($tooMany) {
		print '<div class="warning">' . $langs->trans("StancerAuditTooMany", $maxRows) . '</div>';
	}

	$stancerApi = new StancerApi();
	$ignoredPayms = stancerAuditFetchIgnoredPaymIds($db);

	// Tally per status code, used in the summary box at the top of the table.
	$counts = array(
		STANCER_AUDIT_OK                          => 0,
		STANCER_AUDIT_GROUPED                     => 0,
		STANCER_AUDIT_WRONG_INVOICE_SAME_CUSTOMER => 0,
		STANCER_AUDIT_WRONG_CUSTOMER              => 0,
		STANCER_AUDIT_WRONG_CUSTOMER_UNMAPPED     => 0,
		STANCER_AUDIT_WRONG_AMOUNT                => 0,
		STANCER_AUDIT_NO_MAPPING                  => 0,
		STANCER_AUDIT_API_UNREACHABLE             => 0,
		STANCER_AUDIT_API_NOT_FOUND               => 0,
		STANCER_AUDIT_API_AUTH_ERROR              => 0,
	);

	$results  = array();
	$authStop = false;
	$ignoredCount = 0;
	foreach ($rows as $row) {
		if ($authStop) {
			break;
		}
		$paymId = (string) $row->stancer_paym_id;
		$isIgnored = isset($ignoredPayms[$paymId]);
		$audit = stancerAuditPayment($row, $stancerApi, $db);
		if ($isIgnored) {
			$ignoredCount++;
		} else {
			if (!isset($counts[$audit['status']])) {
				$counts[$audit['status']] = 0;
			}
			$counts[$audit['status']]++;
		}
		$results[] = array('row' => $row, 'audit' => $audit, 'ignored' => $isIgnored);

		dol_syslog("stancer_audit paym=$paymId status=" . $audit['status']
			. ($isIgnored ? ' (IGNORED)' : '')
			. " details=" . $audit['details'], LOG_NOTICE);

		if ($audit['status'] === STANCER_AUDIT_API_AUTH_ERROR) {
			// 401: API key is wrong, no point continuing.
			$authStop = true;
		}
	}

	$elapsed = round(microtime(true) - $startedAt, 1);

	// Summary box
	print '<div class="info">';
	print '<strong>' . $langs->trans("StancerAuditSummary") . '</strong><br>';
	print $langs->trans("StancerAuditRowsAudited", count($results), $elapsed) . '<br>';
	foreach ($counts as $st => $cnt) {
		if ($cnt === 0) {
			continue;
		}
		$label = $langs->trans('StancerAuditStatus_' . str_replace('-', '_', $st));
		// If translation key is missing, fall back to the raw status code.
		if ($label === 'StancerAuditStatus_' . str_replace('-', '_', $st)) {
			$label = $st;
		}
		print '- ' . dol_escape_htmltag($label) . ' : ' . (int) $cnt . '<br>';
	}
	if ($ignoredCount > 0) {
		print '- ' . dol_escape_htmltag($langs->trans('StancerAuditStatusIgnored')) . ' : ' . $ignoredCount . '<br>';
	}
	print '</div>';

	if ($authStop) {
		print '<div class="error">' . $langs->trans("StancerAuditAuthStop") . '</div>';
	}

	// Build the grouped view once so we can decorate the main table with a
	// "in a double" warning and disable confusing actions on double-charge rows.
	$groups = stancerAuditBuildGroupView($results);
	$doublesMap = stancerAuditDetectPaymsInDoubles($groups);
	$auditToken = currentToken();

	// Results table
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre">';
	print '<th>' . $langs->trans("Date") . '</th>';
	print '<th>' . $langs->trans("StancerAuditPaymId") . '</th>';
	print '<th>' . $langs->trans("StancerAuditApiStatus") . '</th>';
	print '<th>' . $langs->trans("StancerAuditStatus") . '</th>';
	print '<th>' . $langs->trans("StancerAuditApiCustomer") . '</th>';
	print '<th>' . $langs->trans("StancerAuditApiOrderId") . '</th>';
	print '<th class="right">' . $langs->trans("StancerAuditApiAmount") . '</th>';
	print '<th>' . $langs->trans("StancerAuditDbClient") . '</th>';
	print '<th>' . $langs->trans("StancerAuditDbInvoice") . '</th>';
	print '<th class="right">' . $langs->trans("StancerAuditDbAmount") . '</th>';
	print '<th>' . $langs->trans("StancerAuditDetails") . '</th>';
	print '<th>' . $langs->trans("StancerAuditActions") . '</th>';
	print '</tr>';

	$statusToCss = array(
		STANCER_AUDIT_OK                          => 'badge-status4',
		STANCER_AUDIT_GROUPED                     => 'badge-status4',
		STANCER_AUDIT_WRONG_INVOICE_SAME_CUSTOMER => 'badge-status7',
		STANCER_AUDIT_WRONG_CUSTOMER              => 'badge-status8',
		STANCER_AUDIT_WRONG_CUSTOMER_UNMAPPED     => 'badge-status8',
		STANCER_AUDIT_WRONG_AMOUNT                => 'badge-status8',
		STANCER_AUDIT_NO_MAPPING                  => 'badge-status1',
		STANCER_AUDIT_API_UNREACHABLE             => 'badge-status1',
		STANCER_AUDIT_API_NOT_FOUND               => 'badge-status7',
		STANCER_AUDIT_API_AUTH_ERROR              => 'badge-status8',
	);

	$actionableStatuses = array(
		STANCER_AUDIT_WRONG_INVOICE_SAME_CUSTOMER,
		STANCER_AUDIT_WRONG_CUSTOMER,
		STANCER_AUDIT_WRONG_CUSTOMER_UNMAPPED,
	);

	foreach ($results as $item) {
		$row     = $item['row'];
		$audit   = $item['audit'];
		$isIgnored = !empty($item['ignored']);
		$paymId  = (string) $row->stancer_paym_id;
		$inDouble  = isset($doublesMap[$paymId]);

		$status   = $audit['status'];
		$cssBadge = isset($statusToCss[$status]) ? $statusToCss[$status] : 'badge-status1';
		$labelKey = 'StancerAuditStatus_' . str_replace('-', '_', $status);
		$statusLabel = $langs->trans($labelKey);
		if ($statusLabel === $labelKey) {
			$statusLabel = $status;
		}

		$apiCustomer = isset($audit['api']['customer_name']) ? $audit['api']['customer_name'] : '';
		$apiCustomerId = isset($audit['api']['customer_id']) ? $audit['api']['customer_id'] : '';
		$apiOrderId  = isset($audit['api']['order_id']) ? $audit['api']['order_id'] : '';
		$apiAmount   = isset($audit['api']['amount']) ? (float) $audit['api']['amount'] : 0.0;
		$apiStatusRaw = isset($audit['api']['status']) ? (string) $audit['api']['status'] : '';
		// Map Stancer status -> badge color. captured/to_capture = green (money in),
		// refused/canceled = grey, disputed = orange, others = blue.
		$apiStatusCss = 'badge-status1';
		if (in_array(strtolower($apiStatusRaw), array('captured', 'to_capture', 'authorized'), true)) {
			$apiStatusCss = 'badge-status4';
		} elseif (in_array(strtolower($apiStatusRaw), array('refused', 'canceled', 'expired'), true)) {
			$apiStatusCss = 'badge-status0';
		} elseif (in_array(strtolower($apiStatusRaw), array('disputed', 'refunded'), true)) {
			$apiStatusCss = 'badge-status7';
		}

		$invoiceLink = $row->db_invoice_ref;
		if (!empty($row->db_fk_facture)) {
			$invoiceUrl = DOL_URL_ROOT . '/compta/facture/card.php?id=' . (int) $row->db_fk_facture;
			$invoiceLink = '<a href="' . $invoiceUrl . '">' . dol_escape_htmltag($row->db_invoice_ref) . '</a>';
		}
		$clientLink = $row->db_client;
		if (!empty($row->db_socid)) {
			$clientUrl = DOL_URL_ROOT . '/societe/card.php?socid=' . (int) $row->db_socid;
			$clientLink = '<a href="' . $clientUrl . '">' . dol_escape_htmltag($row->db_client) . '</a>';
		}

		$rowStyle = $isIgnored ? ' style="opacity:0.45;"' : '';
		print '<tr class="oddeven stancer-audit-row" data-paiement-id="' . (int) $row->paiement_id . '"' . $rowStyle . '>';
		print '<td class="nowrap">' . dol_print_date($db->jdate($row->datep), 'day') . '</td>';
		print '<td><code>' . dol_escape_htmltag($paymId) . '</code>';
		if ($inDouble) {
			print ' <span title="' . dol_escape_htmltag($langs->trans("StancerAuditPaymInDoubleTooltip")) . '" class="badge badge-status8" style="font-size:0.7em;">DBL</span>';
		}
		print '</td>';
		print '<td>';
		if (!empty($apiStatusRaw)) {
			print '<span class="badge ' . $apiStatusCss . '">' . dol_escape_htmltag($apiStatusRaw) . '</span>';
		} else {
			print '<span class="opacitymedium">-</span>';
		}
		print '</td>';
		print '<td><span class="badge ' . $cssBadge . '">' . dol_escape_htmltag($statusLabel) . '</span>';
		if ($isIgnored) {
			print ' <span class="badge badge-status1">' . dol_escape_htmltag($langs->trans("StancerAuditStatusIgnored")) . '</span>';
		}
		print '</td>';
		print '<td>' . dol_escape_htmltag($apiCustomer);
		if (!empty($apiCustomerId)) {
			print '<br><small class="opacitymedium">' . dol_escape_htmltag($apiCustomerId) . '</small>';
		}
		print '</td>';
		print '<td>' . dol_escape_htmltag($apiOrderId) . '</td>';
		print '<td class="right nowrap">' . price($apiAmount) . '</td>';
		print '<td>' . $clientLink . '</td>';
		print '<td>' . $invoiceLink . '</td>';
		print '<td class="right nowrap">' . price($row->db_paid_amount) . '</td>';
		print '<td><small>' . dol_escape_htmltag($audit['details']) . '</small></td>';

		// Action buttons - only on actionable, non-ignored rows.
		print '<td class="nowrap stancer-audit-actions">';
		if (!$isIgnored && in_array($status, $actionableStatuses, true)) {
			// Data attributes feed the JS modal without an extra round-trip.
			$dataAttrs = 'data-paiement-id="' . (int) $row->paiement_id . '"';
			$dataAttrs .= ' data-paym-id="' . dol_escape_htmltag($paymId) . '"';
			$dataAttrs .= ' data-api-order-id="' . dol_escape_htmltag($apiOrderId) . '"';
			$dataAttrs .= ' data-api-amount="' . dol_escape_htmltag((string) $apiAmount) . '"';
			$dataAttrs .= ' data-api-customer="' . dol_escape_htmltag($apiCustomer) . '"';
			$dataAttrs .= ' data-db-invoice-ref="' . dol_escape_htmltag($row->db_invoice_ref) . '"';
			$dataAttrs .= ' data-db-client="' . dol_escape_htmltag($row->db_client) . '"';
			$dataAttrs .= ' data-db-paid-amount="' . dol_escape_htmltag((string) $row->db_paid_amount) . '"';
			$dataAttrs .= ' data-in-double="' . ($inDouble ? '1' : '0') . '"';
			print '<button type="button" class="butAction stancer-audit-fix-btn" ' . $dataAttrs . '>'
				. dol_escape_htmltag($langs->trans("StancerAuditFix")) . '</button>';
			print ' <button type="button" class="button stancer-audit-ignore-btn" ' . $dataAttrs . '>'
				. dol_escape_htmltag($langs->trans("StancerAuditIgnore")) . '</button>';
		}
		print '</td>';

		print '</tr>';
	}
	print '</table>';

	// =========================================================================
	// Section 2: payments grouped by api_order_id, to surface invoices that
	// got hit by 2+ paym_id (potential double-charge). $groups was already
	// built above to feed the per-row "in a double" badge.
	// =========================================================================
	if (!empty($groups)) {
		$doublesCount = 0;
		foreach ($groups as $g) {
			if ($g['has_double']) {
				$doublesCount++;
			}
		}

		print '<br><br>';
		print '<h3>' . $langs->trans("StancerAuditDoublesTitle") . '</h3>';
		print '<p>' . $langs->trans("StancerAuditDoublesIntro") . '</p>';
		if ($doublesCount > 0) {
			print '<div class="error">'
				. $langs->trans("StancerAuditDoublesAlert", $doublesCount)
				. '</div>';
		}

		print '<table class="noborder centpercent">';
		print '<tr class="liste_titre">';
		print '<th>' . $langs->trans("StancerAuditDoublesOrderId") . '</th>';
		print '<th>' . $langs->trans("StancerAuditApiCustomer") . '</th>';
		print '<th>' . $langs->trans("StancerAuditDoublesNbPayments") . '</th>';
		print '<th>' . $langs->trans("StancerAuditDoublesCapturedCount") . '</th>';
		print '<th>' . $langs->trans("StancerAuditDoublesFlag") . '</th>';
		print '<th>' . $langs->trans("StancerAuditDoublesDetail") . '</th>';
		print '</tr>';

		foreach ($groups as $g) {
			$rowCss = $g['has_double'] ? ' style="background-color: rgba(220, 53, 69, 0.10);"' : '';
			print '<tr class="oddeven"' . $rowCss . '>';
			print '<td class="nowrap"><strong>' . dol_escape_htmltag($g['api_order_id']) . '</strong></td>';
			print '<td>' . dol_escape_htmltag($g['api_customer_name']);
			if (!empty($g['api_customer_id'])) {
				print '<br><small class="opacitymedium">' . dol_escape_htmltag($g['api_customer_id']) . '</small>';
			}
			print '</td>';
			print '<td class="center">' . count($g['payments']) . '</td>';
			print '<td class="center">' . (int) $g['captured_count'] . '</td>';
			print '<td>';
			if ($g['has_double']) {
				print '<span class="badge badge-status8"><strong>'
					. dol_escape_htmltag($langs->trans("StancerAuditDoublesFlagDouble"))
					. '</strong></span>';
			} else {
				print '<span class="opacitymedium">' . dol_escape_htmltag($langs->trans("StancerAuditDoublesFlagOk")) . '</span>';
			}
			print '</td>';
			print '<td><small>';
			$lines = array();
			foreach ($g['payments'] as $p) {
				$line = dol_escape_htmltag($p['paym_id']);
				$line .= ' [' . dol_escape_htmltag($p['status_api']) . ']';
				$line .= ' ' . price($p['amount_api']);
				$line .= ' -> ' . dol_escape_htmltag($p['db_invoice_ref']);
				$lines[] = $line;
			}
			print implode('<br>', $lines);
			print '</small></td>';
			print '</tr>';
		}
		print '</table>';
	}

	// =========================================================================
	// Modal + JS for the "Corriger" / "Ignorer" buttons. Operations are
	// performed via AJAX so the user does not have to re-launch the 30s audit
	// after each correction.
	// =========================================================================
	$ajaxUrl = $_SERVER['PHP_SELF'];
	?>
	<div id="stancer_audit_modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999;">
		<div style="background:white; max-width:640px; margin:80px auto; padding:20px; border-radius:6px; box-shadow:0 4px 16px rgba(0,0,0,0.3);">
			<h3 id="stancer_audit_modal_title"></h3>
			<div id="stancer_audit_modal_body"></div>
			<div id="stancer_audit_modal_warning" style="display:none; background:#fff3cd; border:1px solid #ffeaa7; padding:10px; margin:10px 0; color:#856404;"></div>
			<div id="stancer_audit_modal_error"   style="display:none; background:#f8d7da; border:1px solid #f5c6cb; padding:10px; margin:10px 0; color:#721c24;"></div>
			<div style="margin-top:20px; text-align:right;">
				<button type="button" class="button" id="stancer_audit_modal_cancel"><?php echo dol_escape_htmltag($langs->trans("Cancel")); ?></button>
				<button type="button" class="butAction" id="stancer_audit_modal_confirm"></button>
			</div>
		</div>
	</div>
	<script type="text/javascript">
	(function () {
		var STANCER_AUDIT_URL = '<?php echo dol_escape_js($ajaxUrl); ?>';
		var STANCER_AUDIT_TOKEN = '<?php echo dol_escape_js($auditToken); ?>';
		var TXT = {
			fix:          <?php echo json_encode($langs->transnoentitiesnoconv("StancerAuditFix")); ?>,
			ignore:       <?php echo json_encode($langs->transnoentitiesnoconv("StancerAuditIgnore")); ?>,
			confirmFix:   <?php echo json_encode($langs->transnoentitiesnoconv("StancerAuditConfirmFix")); ?>,
			confirmIgn:   <?php echo json_encode($langs->transnoentitiesnoconv("StancerAuditConfirmIgnore")); ?>,
			titleFix:     <?php echo json_encode($langs->transnoentitiesnoconv("StancerAuditModalTitleFix")); ?>,
			titleIgn:     <?php echo json_encode($langs->transnoentitiesnoconv("StancerAuditModalTitleIgnore")); ?>,
			doubleWarn:   <?php echo json_encode($langs->transnoentitiesnoconv("StancerAuditModalDoubleWarning")); ?>,
			done:         <?php echo json_encode($langs->transnoentitiesnoconv("StancerAuditDone")); ?>,
			ignored:      <?php echo json_encode($langs->transnoentitiesnoconv("StancerAuditStatusIgnored")); ?>,
			networkError: <?php echo json_encode($langs->transnoentitiesnoconv("StancerAuditNetworkError")); ?>
		};

		function escapeHtml(s) {
			if (s === null || s === undefined) return '';
			return String(s).replace(/[&<>"']/g, function(c) {
				return ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' })[c];
			});
		}
		function showModal(mode, btn) {
			var paymId       = btn.getAttribute('data-paym-id');
			var paiementId   = btn.getAttribute('data-paiement-id');
			var apiOrderId   = btn.getAttribute('data-api-order-id');
			var apiAmount    = btn.getAttribute('data-api-amount');
			var apiCustomer  = btn.getAttribute('data-api-customer');
			var dbInvoiceRef = btn.getAttribute('data-db-invoice-ref');
			var dbClient     = btn.getAttribute('data-db-client');
			var inDouble     = btn.getAttribute('data-in-double') === '1';

			var modal   = document.getElementById('stancer_audit_modal');
			var titleEl = document.getElementById('stancer_audit_modal_title');
			var bodyEl  = document.getElementById('stancer_audit_modal_body');
			var warnEl  = document.getElementById('stancer_audit_modal_warning');
			var errEl   = document.getElementById('stancer_audit_modal_error');
			var confirmBtn = document.getElementById('stancer_audit_modal_confirm');

			errEl.style.display = 'none';
			warnEl.style.display = 'none';

			if (mode === 'fix') {
				titleEl.textContent = TXT.titleFix;
				bodyEl.innerHTML = '<table class="border centpercent">'
					+ '<tr><td>paym_id</td><td><code>' + escapeHtml(paymId) + '</code></td></tr>'
					+ '<tr><td>API client</td><td>' + escapeHtml(apiCustomer) + '</td></tr>'
					+ '<tr><td>API facture cible</td><td><strong>' + escapeHtml(apiOrderId) + '</strong></td></tr>'
					+ '<tr><td>API montant</td><td>' + escapeHtml(apiAmount) + '</td></tr>'
					+ '<tr><td>Actuel DB - client</td><td>' + escapeHtml(dbClient) + '</td></tr>'
					+ '<tr><td>Actuel DB - facture</td><td>' + escapeHtml(dbInvoiceRef) + '</td></tr>'
					+ '</table>'
					+ '<p>' + escapeHtml(TXT.confirmFix) + '</p>';
				if (inDouble) {
					warnEl.textContent = TXT.doubleWarn;
					warnEl.style.display = '';
				}
				confirmBtn.textContent = TXT.fix;
				confirmBtn.dataset.mode = 'fix';
			} else {
				titleEl.textContent = TXT.titleIgn;
				bodyEl.innerHTML = '<p><code>' + escapeHtml(paymId) + '</code></p>'
					+ '<p>' + escapeHtml(TXT.confirmIgn) + '</p>';
				confirmBtn.textContent = TXT.ignore;
				confirmBtn.dataset.mode = 'ignore';
			}
			confirmBtn.dataset.paiementId = paiementId;
			confirmBtn.dataset.paymId = paymId;
			confirmBtn.disabled = false;
			modal.style.display = 'block';
		}
		function hideModal() {
			document.getElementById('stancer_audit_modal').style.display = 'none';
		}
		function updateRowAfterAction(paiementId, mode, paymId) {
			var row = document.querySelector('tr.stancer-audit-row[data-paiement-id="' + paiementId + '"]');
			if (!row) return;
			row.style.opacity = '0.45';
			var actionsCell = row.querySelector('.stancer-audit-actions');
			if (actionsCell) {
				if (mode === 'fix') {
					actionsCell.innerHTML = '<span class="badge badge-status4">' + escapeHtml(TXT.done) + '</span>';
				} else {
					actionsCell.innerHTML = '<span class="badge badge-status1">' + escapeHtml(TXT.ignored) + '</span>';
				}
			}
		}
		function sendAction(mode, paiementId, paymId, btnEl) {
			var errEl = document.getElementById('stancer_audit_modal_error');
			errEl.style.display = 'none';
			btnEl.disabled = true;

			var fd = new FormData();
			fd.append('paiement_id', paiementId);
			fd.append('token', STANCER_AUDIT_TOKEN);

			fetch(STANCER_AUDIT_URL + '?action=' + mode, {
				method: 'POST',
				body: fd,
				credentials: 'same-origin'
			}).then(function (resp) {
				return resp.json().catch(function () {
					throw new Error('Bad JSON response (HTTP ' + resp.status + ')');
				});
			}).then(function (data) {
				if (data.success) {
					hideModal();
					updateRowAfterAction(paiementId, mode, paymId);
				} else {
					errEl.textContent = data.message || 'Unknown error';
					errEl.style.display = '';
					btnEl.disabled = false;
				}
			}).catch(function (err) {
				errEl.textContent = TXT.networkError + ' ' + err.message;
				errEl.style.display = '';
				btnEl.disabled = false;
			});
		}
		document.addEventListener('click', function (e) {
			if (e.target.classList.contains('stancer-audit-fix-btn')) {
				showModal('fix', e.target);
			} else if (e.target.classList.contains('stancer-audit-ignore-btn')) {
				showModal('ignore', e.target);
			} else if (e.target.id === 'stancer_audit_modal_cancel') {
				hideModal();
			} else if (e.target.id === 'stancer_audit_modal_confirm') {
				var mode = e.target.dataset.mode;
				var pid  = e.target.dataset.paiementId;
				var pym  = e.target.dataset.paymId;
				sendAction(mode, pid, pym, e.target);
			}
		});
	})();
	</script>
	<?php
}

llxFooter();
$db->close();
