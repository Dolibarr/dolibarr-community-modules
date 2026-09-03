<?php
/* Copyright (C) 2023-2026 Eric Seigne <eric.seigne@cap-rel.fr>
 * Copyright (C) 2026		MDW			<mdeweerd@users.noreply.github.com>
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
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file       index.php
 * \ingroup    stancer
 * \brief      Dashboard / home page for Stancer module
 */

// Load Dolibarr environment
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
if (!$res && file_exists("../main.inc.php")) {
	$res = @include "../main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT . '/core/lib/functions.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/dolgraph.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';
dol_include_once('/stancer/class/stancer_payments.class.php');
dol_include_once('/stancer/class/stancer_refunds.class.php');
dol_include_once('/stancer/class/stancer_disputes.class.php');

$langs->loadLangs(array("stancer@stancer", "other", "orders"));

// There is several ways to check permission.
// Set $enablepermissioncheck to 1 to enable a minimum low level of checks
$enablepermissioncheck = 1;
if ($enablepermissioncheck) {
	$permissiontoread = $user->hasRight('stancer', 'read');
	$permissiontoadd = $user->hasRight('stancer', 'write');
	$permissiontodelete = 0; // No delete permission defined in this module
} else {
	$permissiontoread = 1;
	$permissiontoadd = 1;
	$permissiontodelete = 0;
}

// Security check (enable the most restrictive one)
if ($user->socid > 0) {
	accessforbidden();
}
//if ($user->socid > 0) accessforbidden();
//$socid = 0; if ($user->socid > 0) $socid = $user->socid;
//$isdraft = (($object->status == $object::STATUS_DRAFT) ? 1 : 0);
//restrictedArea($user, $object->module, 0, $object->table_element, $object->element, 'fk_soc', 'rowid', $isdraft);
if (!isModEnabled("stancer")) {
	accessforbidden('Module stancer not enabled');
}
if (!$permissiontoread) {
	accessforbidden();
}
$liveMode = getDolGlobalString('STANCER_IS_PROD', '0');


/*
 * Data gathering
 */

$now = dol_now();
$currentYear = (int) date('Y', $now);
$currentMonth = date('Y-m-01', $now);
$currentMonthEnd = date('Y-m-t 23:59:59', $now);
$previousMonth = date('Y-m-01', strtotime('-1 month', $now));
$previousMonthEnd = date('Y-m-t 23:59:59', strtotime('-1 month', $now));

$sqlEntityFilter = " AND entity = ".((int) $conf->entity);
$sqlLiveModeFilter = " AND live_mode = ".((int) $liveMode);
$capturedFilter = " AND status = ".Stancer_payments::STATUS_CAPTURED;

// --- KPI 1: Revenue this month (captured payments) ---
$sql = "SELECT COALESCE(SUM(amount), 0) as total, COUNT(*) as cnt";
$sql .= " FROM ".MAIN_DB_PREFIX."stancer_stancer_payments";
$sql .= " WHERE status = ".Stancer_payments::STATUS_CAPTURED;
$sql .= $sqlLiveModeFilter.$sqlEntityFilter;
$sql .= " AND date_creation >= '".$db->escape($currentMonth)."'";
$sql .= " AND date_creation <= '".$db->escape($currentMonthEnd)."'";

$revenueMonth = 0;
$paymentsMonth = 0;
$resql = $db->query($sql);
if ($resql) {
	$obj = $db->fetch_object($resql);
	$revenueMonth = (int) $obj->total;
	$paymentsMonth = (int) $obj->cnt;
}

// Revenue previous month (for variation %)
$sql = "SELECT COALESCE(SUM(amount), 0) as total, COUNT(*) as cnt";
$sql .= " FROM ".MAIN_DB_PREFIX."stancer_stancer_payments";
$sql .= " WHERE status = ".Stancer_payments::STATUS_CAPTURED;
$sql .= $sqlLiveModeFilter.$sqlEntityFilter;
$sql .= " AND date_creation >= '".$db->escape($previousMonth)."'";
$sql .= " AND date_creation <= '".$db->escape($previousMonthEnd)."'";

$revenuePrevMonth = 0;
$paymentsPrevMonth = 0;
$resql = $db->query($sql);
if ($resql) {
	$obj = $db->fetch_object($resql);
	$revenuePrevMonth = (int) $obj->total;
	$paymentsPrevMonth = (int) $obj->cnt;
}

// --- KPI 3: Pending refunds ---
$sql = "SELECT COUNT(*) as cnt, COALESCE(SUM(amount), 0) as total";
$sql .= " FROM ".MAIN_DB_PREFIX."stancer_stancer_refunds";
$sql .= " WHERE status = 0";
$sql .= $sqlLiveModeFilter.$sqlEntityFilter;

$refundsPending = 0;
$refundsPendingAmount = 0;
$resql = $db->query($sql);
if ($resql) {
	$obj = $db->fetch_object($resql);
	$refundsPending = (int) $obj->cnt;
	$refundsPendingAmount = (int) $obj->total;
}

// Total refunds this month
$sql = "SELECT COUNT(*) as cnt";
$sql .= " FROM ".MAIN_DB_PREFIX."stancer_stancer_refunds";
$sql .= " WHERE 1 = 1".$sqlLiveModeFilter.$sqlEntityFilter;
$sql .= " AND created >= '".$db->escape($currentMonth)."'";
$sql .= " AND created <= '".$db->escape($currentMonthEnd)."'";

$refundsMonth = 0;
$resql = $db->query($sql);
if ($resql) {
	$obj = $db->fetch_object($resql);
	$refundsMonth = (int) $obj->cnt;
}

// --- KPI 4: Open disputes ---
$sql = "SELECT COUNT(*) as cnt, COALESCE(SUM(amount), 0) as total";
$sql .= " FROM ".MAIN_DB_PREFIX."stancer_stancer_disputes";
$sql .= " WHERE status = 'OPEN'";
$sql .= $sqlLiveModeFilter.$sqlEntityFilter;

$disputesOpen = 0;
$disputesOpenAmount = 0;
$resql = $db->query($sql);
if ($resql) {
	$obj = $db->fetch_object($resql);
	$disputesOpen = (int) $obj->cnt;
	$disputesOpenAmount = (int) $obj->total;
}

// --- Chart 1: Yearly comparison (Jan-Dec, one series per year) ---
// Find the earliest year with data
$sql = "SELECT MIN(YEAR(date_creation)) as min_year";
$sql .= " FROM ".MAIN_DB_PREFIX."stancer_stancer_payments";
$sql .= " WHERE status = ".Stancer_payments::STATUS_CAPTURED;
$sql .= $sqlLiveModeFilter.$sqlEntityFilter;

$minYear = $currentYear;
$resql = $db->query($sql);
if ($resql) {
	$obj = $db->fetch_object($resql);
	if (!empty($obj->min_year)) {
		$minYear = max((int) $obj->min_year, $currentYear - 3); // max 4 years
	}
}

// Fetch monthly totals per year in one query
$sql = "SELECT YEAR(date_creation) as yr, MONTH(date_creation) as mn, COALESCE(SUM(amount), 0) as total";
$sql .= " FROM ".MAIN_DB_PREFIX."stancer_stancer_payments";
$sql .= " WHERE status = ".Stancer_payments::STATUS_CAPTURED;
$sql .= $sqlLiveModeFilter.$sqlEntityFilter;
$sql .= " AND YEAR(date_creation) >= ".((int) $minYear);
$sql .= " GROUP BY YEAR(date_creation), MONTH(date_creation)";
$sql .= " ORDER BY yr, mn";

$yearlyRaw = array(); // [year][month] = total_cents
$resql = $db->query($sql);
if ($resql) {
	while ($obj = $db->fetch_object($resql)) {
		$yearlyRaw[(int) $obj->yr][(int) $obj->mn] = (int) $obj->total;
	}
}

$years = array();
for ($y = $minYear; $y <= $currentYear; $y++) {
	$years[] = $y;
}

$monthNames = array();
for ($m = 1; $m <= 12; $m++) {
	$monthNames[$m] = dol_print_date(mktime(0, 0, 0, $m, 1, 2000), '%b');
}

$yearlyData = array();
$hasYearlyData = false;
for ($m = 1; $m <= 12; $m++) {
	$row = array($monthNames[$m]);
	foreach ($years as $y) {
		$val = isset($yearlyRaw[$y][$m]) ? round($yearlyRaw[$y][$m] / 100, 2) : 0;
		if ($val > 0) {
			$hasYearlyData = true;
		}
		$row[] = $val;
	}
	$yearlyData[] = $row;
}

// --- Chart 2: CB vs SEPA monthly (last 12 months, amounts) ---
$sql = "SELECT DATE_FORMAT(date_creation, '%Y-%m') as ym, method, COALESCE(SUM(amount), 0) as total";
$sql .= " FROM ".MAIN_DB_PREFIX."stancer_stancer_payments";
$sql .= " WHERE status = ".Stancer_payments::STATUS_CAPTURED;
$sql .= $sqlLiveModeFilter.$sqlEntityFilter;
$sql .= " AND date_creation >= '".$db->escape(date('Y-m-01', strtotime('-11 months', $now)))."'";
$sql .= " GROUP BY ym, method";
$sql .= " ORDER BY ym";

$methodMonthlyRaw = array(); // [ym][method] = total
$resql = $db->query($sql);
if ($resql) {
	while ($obj = $db->fetch_object($resql)) {
		$methodMonthlyRaw[$obj->ym][dol_strtolower($obj->method)] = (int) $obj->total;
	}
}

$methodMonthlyData = array();
$hasMethodMonthlyData = false;
for ($m = 11; $m >= 0; $m--) {
	$ym = date('Y-m', strtotime("-$m months", $now));
	$label = dol_print_date(strtotime($ym.'-01'), '%b %Y');
	$cbVal = isset($methodMonthlyRaw[$ym]['card']) ? round($methodMonthlyRaw[$ym]['card'] / 100, 2) : 0;
	$sepaVal = isset($methodMonthlyRaw[$ym]['sepa']) ? round($methodMonthlyRaw[$ym]['sepa'] / 100, 2) : 0;
	if ($cbVal > 0 || $sepaVal > 0) {
		$hasMethodMonthlyData = true;
	}
	$methodMonthlyData[] = array($label, $cbVal, $sepaVal);
}

// --- Pie chart: Payment method breakdown (all time) ---
$sql = "SELECT method, COUNT(*) as cnt, COALESCE(SUM(amount), 0) as total";
$sql .= " FROM ".MAIN_DB_PREFIX."stancer_stancer_payments";
$sql .= " WHERE status = ".Stancer_payments::STATUS_CAPTURED;
$sql .= $sqlLiveModeFilter.$sqlEntityFilter;
$sql .= " GROUP BY method";

$methodPieData = array();
$resql = $db->query($sql);
if ($resql) {
	while ($obj = $db->fetch_object($resql)) {
		$label = dol_strtoupper($obj->method);
		if ($label === 'CARD') {
			$label = 'CB';
		}
		$methodPieData[] = array($label.' ('.price(((int) $obj->total) / 100, 0, $langs, 1, -1, 0).' EUR)', (int) $obj->cnt);
	}
}
if (empty($methodPieData)) {
	$methodPieData[] = array($langs->trans("NoData"), 0);
}

// --- Last 10 payments ---
$sql = "SELECT t.rowid, t.stancer_id, t.amount, t.fee, t.currency, t.method, t.status, t.date_creation, t.fk_soc";
$sql .= " FROM ".MAIN_DB_PREFIX."stancer_stancer_payments as t";
$sql .= " WHERE t.status >= 0";
$sql .= " AND t.live_mode = ".((int) $liveMode);
$sql .= " AND t.entity = ".((int) $conf->entity);
$sql .= " ORDER BY t.date_creation DESC";
$sql .= " LIMIT 10";

$lastPayments = array();
$resql = $db->query($sql);
if ($resql) {
	while ($obj = $db->fetch_object($resql)) {
		$lastPayments[] = $obj;
	}
}

// --- Pending invoice validation mails (auto-send scheduled but not yet fired) ---
$pendingValidationMails = array();
if (getDolGlobalString('STANCER_AUTO_MAIL_ALL_INVOICES_VALIDATE', '') != '' && (int) getDolGlobalString('STANCER_AUTO_MAIL_ALL_INVOICES_VALIDATE_DELAY', '0') > 0) {
	$sqlPending = "SELECT a.id, a.fk_element, a.socid, a.datep, a.label";
	$sqlPending .= " FROM " . MAIN_DB_PREFIX . "actioncomm AS a";
	$sqlPending .= " WHERE a.code = 'AC_BILL_VALIDATE_PENDING'";
	$sqlPending .= " AND a.percentage < 100";
	$sqlPending .= " AND a.elementtype = 'invoice'";
	$sqlPending .= " AND a.entity IN (" . getEntity('actioncomm') . ")";
	$sqlPending .= " ORDER BY a.datep ASC";
	$sqlPending .= " LIMIT 20";
	$resPending = $db->query($sqlPending);
	if ($resPending) {
		while ($objP = $db->fetch_object($resPending)) {
			$pendingValidationMails[] = $objP;
		}
	}
}


/*
 * View
 */

llxHeader('', $langs->trans("StancerDashboard"), '', '', 0, 0, '', '', '', 'mod-stancer page-index');

print load_fiche_titre($langs->trans("StancerDashboard"), '', 'stancer@stancer');

if ($liveMode == '0') {
	print '<div class="warning">'.$langs->trans("StancerTestModeWarning").'</div><br>';
}


// ========================================================================
// KPI Cards
// ========================================================================

print '<div class="fichecenter">';
print '<div class="fichethirdleft">';

// KPI Table
print '<div class="div-table-responsive-no-min">';
print '<table class="noborder nohover centpercent">';
print '<tr class="liste_titre"><th colspan="3">'.$langs->trans("StancerDashboardKPI").'</th></tr>';

// Revenue
$variationRevenue = '';
if ($revenuePrevMonth > 0) {
	$pct = round((($revenueMonth - $revenuePrevMonth) / $revenuePrevMonth) * 100, 1);
	$color = $pct >= 0 ? 'green' : 'red';
	$sign = $pct >= 0 ? '+' : '';
	$variationRevenue = ' <span style="color: '.$color.'; font-size: 0.85em;">('.$sign.$pct.'%)</span>';
}
print '<tr class="oddeven">';
print '<td><a href="'.dol_buildpath('/stancer/stancer_payments_list.php', 1).'?search_status='.Stancer_payments::STATUS_CAPTURED.'">'.$langs->trans("StancerDashboardRevenue").'</a></td>';
print '<td class="right"><strong>'.price($revenueMonth / 100, 0, $langs, 1, -1, 2).' EUR</strong>'.$variationRevenue.'</td>';
print '<td class="right opacitymedium">'.dol_print_date(strtotime($currentMonth), '%B %Y').'</td>';
print '</tr>';

// Payments count
$variationPayments = '';
if ($paymentsPrevMonth > 0) {
	$pct = round((($paymentsMonth - $paymentsPrevMonth) / $paymentsPrevMonth) * 100, 1);
	$color = $pct >= 0 ? 'green' : 'red';
	$sign = $pct >= 0 ? '+' : '';
	$variationPayments = ' <span style="color: '.$color.'; font-size: 0.85em;">('.$sign.$pct.'%)</span>';
}
print '<tr class="oddeven">';
print '<td><a href="'.dol_buildpath('/stancer/stancer_payments_list.php', 1).'">'.$langs->trans("StancerDashboardPayments").'</a></td>';
print '<td class="right"><strong>'.$paymentsMonth.'</strong>'.$variationPayments.'</td>';
print '<td class="right opacitymedium">'.$langs->trans("StancerDashboardCaptured").'</td>';
print '</tr>';

// Refunds
print '<tr class="oddeven">';
print '<td><a href="'.dol_buildpath('/stancer/stancer_refunds_list.php', 1).'">'.$langs->trans("StancerDashboardRefunds").'</a></td>';
print '<td class="right"><strong>'.$refundsMonth.'</strong></td>';
print '<td class="right opacitymedium">'.$langs->trans("StancerDashboardThisMonth").'</td>';
print '</tr>';

// Disputes
$disputeStyle = $disputesOpen > 0 ? 'color: red; font-weight: bold;' : '';
print '<tr class="oddeven">';
print '<td><a href="'.dol_buildpath('/stancer/stancer_disputes_list.php', 1).'">'.$langs->trans("StancerDashboardDisputes").'</a></td>';
print '<td class="right"><span style="'.$disputeStyle.'">'.$disputesOpen.'</span></td>';
print '<td class="right opacitymedium">'.$langs->trans("StancerDashboardOpen").'</td>';
print '</tr>';

print '</table>';
print '</div>';

print '<br>';




// ========================================================================
// Pie chart: Payment methods (with amounts)
// ========================================================================

print '<div class="div-table-responsive-no-min">';
print '<table class="noborder nohover centpercent">';
print '<tr class="liste_titre"><th>'.$langs->trans("StancerDashboardMethods").'</th></tr>';
print '<tr class="oddeven"><td class="center">';

$pieGraph = new DolGraph();
$pieGraph->SetData($methodPieData);
$pieGraph->SetDataColor(array(array(66, 133, 244), array(52, 168, 83), array(251, 188, 4)));
$pieGraph->setShowLegend(1);
$pieGraph->setShowPercent(1);
$pieGraph->SetType(array('pie'));
$pieGraph->SetHeight(200);
$pieGraph->SetWidth(350);
$pieGraph->draw('stancer_methods_pie');
print $pieGraph->show(empty($methodPieData) || (count($methodPieData) == 1 && $methodPieData[0][1] == 0) ? 1 : 0);

print '</td></tr>';
print '</table>';
print '</div>';


// ========================================================================
// Right column
// ========================================================================

print '</div><div class="fichetwothirdright">';


// ========================================================================
// Bar chart: Yearly comparison (Jan-Dec, one series per year)
// ========================================================================

print '<div class="div-table-responsive-no-min">';
print '<table class="noborder nohover centpercent">';
print '<tr class="liste_titre"><th>'.$langs->trans("StancerDashboardYearlyComparison").' (EUR)</th></tr>';
print '<tr class="oddeven"><td class="center">';

$yearColors = array(
	array(200, 200, 200), // oldest year = light gray
	array(251, 188, 4),   // yellow
	array(52, 168, 83),   // green
	array(66, 133, 244),  // blue = current year
);
// Pick colors from the end (current year = blue)
$nbYears = count($years);
$usedColors = array_slice($yearColors, max(0, 4 - $nbYears));

$yearTypes = array_fill(0, $nbYears, 'bars');
$yearLegend = array_map('strval', $years);

$barGraph = new DolGraph();
$barGraph->SetData($yearlyData);
$barGraph->SetDataColor($usedColors);
$barGraph->SetType($yearTypes);
$barGraph->SetLegend($yearLegend);
$barGraph->setShowLegend(1);
$barGraph->setShowPointValue(1);
$barGraph->SetHeight(250);
$barGraph->SetWidth('95%');
$barGraph->SetHideXGrid(true);
$barGraph->draw('stancer_yearly_comparison');
print $barGraph->show($hasYearlyData ? 0 : 1);

print '</td></tr>';
print '</table>';
print '</div>';

print '<br>';

// ========================================================================
// Bar chart: CB vs SEPA monthly (amounts, last 12 months)
// ========================================================================

print '<div class="div-table-responsive-no-min">';
print '<table class="noborder nohover centpercent">';
print '<tr class="liste_titre"><th>'.$langs->trans("StancerDashboardMethodMonthly").' (EUR)</th></tr>';
print '<tr class="oddeven"><td class="center">';

$methodBarGraph = new DolGraph();
$methodBarGraph->SetData($methodMonthlyData);
$methodBarGraph->SetDataColor(array(array(66, 133, 244), array(52, 168, 83)));
$methodBarGraph->SetType(array('bars', 'bars'));
$methodBarGraph->SetLegend(array('CB', 'SEPA'));
$methodBarGraph->setShowLegend(1);
$methodBarGraph->setShowPointValue(1);
$methodBarGraph->SetHeight(220);
$methodBarGraph->SetWidth('95%');
$methodBarGraph->SetHideXGrid(true);
$methodBarGraph->draw('stancer_method_monthly');
print $methodBarGraph->show($hasMethodMonthlyData ? 0 : 1);

print '</td></tr>';
print '</table>';
print '</div>';

print '<br>';


// ========================================================================
// Alerts (disputes + pending refunds)
// ========================================================================

if ($disputesOpen > 0 || $refundsPending > 0) {
	print '<div class="div-table-responsive-no-min">';
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre"><th colspan="2">'.$langs->trans("StancerDashboardAlerts").'</th></tr>';

	if ($disputesOpen > 0) {
		print '<tr class="oddeven">';
		print '<td><span class="fas fa-exclamation-triangle" style="color: red;"></span> ';
		print $langs->trans("StancerDashboardAlertDisputes", $disputesOpen, price($disputesOpenAmount / 100, 0, $langs, 1, -1, 2).' EUR');
		print '</td>';
		print '<td class="right"><a href="'.dol_buildpath('/stancer/stancer_disputes_list.php', 1).'" class="butAction">'.$langs->trans("SeeAll").'</a></td>';
		print '</tr>';
	}

	if ($refundsPending > 0) {
		print '<tr class="oddeven">';
		print '<td><span class="fas fa-exclamation-triangle" style="color: orange;"></span> ';
		print $langs->trans("StancerDashboardAlertRefunds", $refundsPending, price($refundsPendingAmount / 100, 0, $langs, 1, -1, 2).' EUR');
		print '</td>';
		print '<td class="right"><a href="'.dol_buildpath('/stancer/stancer_refunds_list.php', 1).'" class="butAction">'.$langs->trans("SeeAll").'</a></td>';
		print '</tr>';
	}

	print '</table>';
	print '</div>';
	print '<br>';
}


// ========================================================================
// Pending validation mails (auto-send scheduled)
// ========================================================================

if (!empty($pendingValidationMails)) {
	$factureStatic = new Facture($db);
	$societeStatic = new Societe($db);

	print '<div class="div-table-responsive-no-min">';
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre">';
	print '<th colspan="4">' . $langs->trans('StancerDashboardPendingValidationMails') . ' (' . count($pendingValidationMails) . ')</th>';
	print '</tr>';
	print '<tr class="liste_titre">';
	print '<th>' . $langs->trans('Invoice') . '</th>';
	print '<th>' . $langs->trans('ThirdParty') . '</th>';
	print '<th class="right">' . $langs->trans('StancerDashboardPendingValidationMailsDue', '') . '</th>';
	print '<th></th>';
	print '</tr>';

	$nowTs = dol_now();
	foreach ($pendingValidationMails as $pv) {
		$resInvFetch = $factureStatic->fetch($pv->fk_element);
		if ($resInvFetch <= 0) {
			continue;
		}
		$dueTs = is_numeric($pv->datep) ? $pv->datep : strtotime((string) $pv->datep);
		$overdue = ($dueTs <= $nowTs);

		print '<tr class="oddeven">';
		print '<td class="nowraponall tdoverflowmax150">' . $factureStatic->getNomUrl(1) . '</td>';
		print '<td class="nowrap tdoverflowmax150">';
		if (!empty($pv->socid)) {
			$societeStatic->fetch($pv->socid);
			print $societeStatic->getNomUrl(1, '', 20);
		}
		print '</td>';
		$dueColor = $overdue ? 'color: red; font-weight: bold;' : '';
		print '<td class="right tddate"><span style="' . $dueColor . '">' . dol_print_date($dueTs, 'dayhour') . '</span></td>';
		print '<td class="right"><a href="' . dol_buildpath('/compta/facture/card.php', 1) . '?id=' . ((int) $factureStatic->id) . '" class="butAction">' . $langs->trans('Card') . '</a></td>';
		print '</tr>';
	}
	print '</table>';
	print '</div>';
	print '<br>';
}


// ========================================================================
// Last 10 payments
// ========================================================================

$staticPayment = new Stancer_payments($db);

print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<th colspan="2">'.$langs->trans("StancerDashboardLastPayments").'</th>';
print '<th class="right">'.$langs->trans("Amount").'</th>';
print '<th class="center">'.$langs->trans("Method").'</th>';
print '<th class="center">'.$langs->trans("Status").'</th>';
print '<th class="right">'.$langs->trans("Date").'</th>';
print '</tr>';

if (empty($lastPayments)) {
	print '<tr class="oddeven"><td colspan="6" class="opacitymedium">'.$langs->trans("NoRecordFound").'</td></tr>';
} else {
	$societe = new Societe($db);
	foreach ($lastPayments as $pay) {
		$staticPayment->id = $pay->rowid;
		$staticPayment->ref = $pay->stancer_id;
		$staticPayment->status = $pay->status;

		print '<tr class="oddeven">';

		// Payment link
		print '<td class="nowraponall tdoverflowmax150">'.$staticPayment->getNomUrl(1).'</td>';

		// Third party
		print '<td class="nowrap tdoverflowmax150">';
		if (!empty($pay->fk_soc)) {
			$societe->fetch($pay->fk_soc);
			print $societe->getNomUrl(1, '', 20);
		}
		print '</td>';

		// Amount
		print '<td class="right nowrap"><span class="amount">'.price($pay->amount / 100, 0, $langs, 1, -1, 2).' '.dol_strtoupper($pay->currency).'</span></td>';

		// Method
		$methodLabel = dol_strtoupper($pay->method);
		if ($methodLabel === 'CARD') {
			$methodLabel = 'CB';
		}
		print '<td class="center">'.$methodLabel.'</td>';

		// Status
		print '<td class="center">'.$staticPayment->getLibStatut(5).'</td>';

		// Date
		print '<td class="right tddate">'.dol_print_date($db->jdate($pay->date_creation), 'dayhour').'</td>';

		print '</tr>';
	}
}

print '<tr class="liste_total">';
print '<td colspan="6" class="right">';
print '<a href="'.dol_buildpath('/stancer/stancer_payments_list.php', 1).'">'.$langs->trans("SeeAll").' &raquo;</a>';
print '</td>';
print '</tr>';

print '</table>';
print '</div>';


print '</div>'; // fichetwothirdright
print '</div>'; // fichecenter

llxFooter();
$db->close();
