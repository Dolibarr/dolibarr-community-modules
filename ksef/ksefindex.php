<?php
/* Copyright (C) 2025-2026 InPoint Automation Sp z o.o.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file       ksef/ksefindex.php
 * \ingroup    ksef
 * \brief      Home page
 */

// CSRF check
if (!defined('CSRFCHECK_WITH_TOKEN')) {
    define('CSRFCHECK_WITH_TOKEN', '1');
}

$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) $res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"] . "/main.inc.php";
if (!$res && file_exists("../main.inc.php")) $res = @include "../main.inc.php";
if (!$res && file_exists("../../main.inc.php")) $res = @include "../../main.inc.php";
if (!$res && file_exists("../../../main.inc.php")) $res = @include "../../../main.inc.php";
if (!$res) die("Include of main fails");

global $conf, $langs, $user, $db;

require_once DOL_DOCUMENT_ROOT . '/core/class/html.formfile.class.php';
dol_include_once('/ksef/class/ksef_submission.class.php');
dol_include_once('/ksef/class/ksef_incoming.class.php');
dol_include_once('/ksef/class/ksef_latarnia.class.php');
dol_include_once('/ksef/lib/ksef.lib.php');

$langs->loadLangs(array("ksef@ksef", "bills"));

$action = GETPOST('action', 'aZ09');
$socid = GETPOST('socid', 'int');
if (isset($user->socid) && $user->socid > 0) {
    $action = '';
    $socid = $user->socid;
}

// Check Latarnia status on demand
if ($action == 'checklatarnia') {
    if (!verifCond('isModEnabled("ksef")')) {
        accessforbidden();
    }
    $latarnia = new KsefLatarnia($db);
    $result = $latarnia->checkAndCache();
    if ($result !== false) {
        setEventMessages($langs->trans('KSEF_LatarniaCheckSuccess', $langs->trans('KSEF_LATARNIA_' . $result['status'])), null, 'mesgs');
    } else {
        setEventMessages($langs->trans('KSEF_LatarniaCheckError', $latarnia->error), null, 'errors');
    }
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

$max = 5;
$now = dol_now();

$form = new Form($db);
$formfile = new FormFile($db);

// Stats
$submission = new KsefSubmission($db);
$stats_outgoing = $submission->getStatistics(30);
$stats_outgoing_total = $submission->getStatistics(99999);
$stats_incoming = array('total' => 0, 'new' => 0, 'imported' => 0, 'skipped' => 0, 'error' => 0);
$sql_inc = "SELECT import_status, COUNT(*) as cnt FROM " . MAIN_DB_PREFIX . "ksef_incoming WHERE entity IN (" . getEntity('invoice') . ") GROUP BY import_status";
$resql_inc = $db->query($sql_inc);
if ($resql_inc) {
    while ($obj = $db->fetch_object($resql_inc)) {
        $stats_incoming['total'] += $obj->cnt;
        $status_key = strtolower($obj->import_status);
        if (isset($stats_incoming[$status_key])) {
            $stats_incoming[$status_key] = (int)$obj->cnt;
        }
    }
    $db->free($resql_inc);
}

dol_include_once('/ksef/class/ksef_sync_state.class.php');
$syncState = new KsefSyncState($db);
$syncState->load('incoming');

//Outgoing
$latest_join = " INNER JOIN (SELECT fk_facture, MAX(date_submission) as latest_date FROM " . MAIN_DB_PREFIX . "ksef_submissions GROUP BY fk_facture) latest";
$latest_join .= " ON s.fk_facture = latest.fk_facture AND s.date_submission = latest.latest_date";

// Offline invoices
$deadline_urgent = $now + 86400;
$sql_offline_urgent = "SELECT COUNT(DISTINCT s.fk_facture) as cnt FROM " . MAIN_DB_PREFIX . "ksef_submissions s";
$sql_offline_urgent .= $latest_join;
$sql_offline_urgent .= " LEFT JOIN " . MAIN_DB_PREFIX . "facture f ON s.fk_facture = f.rowid";
$sql_offline_urgent .= " WHERE f.entity IN (" . getEntity('invoice') . ")";
$sql_offline_urgent .= " AND s.offline_mode IS NOT NULL AND s.offline_mode != ''";
$sql_offline_urgent .= " AND s.status NOT IN ('ACCEPTED')";
$sql_offline_urgent .= " AND s.offline_deadline IS NOT NULL AND s.offline_deadline > 0 AND s.offline_deadline < " . (int)$deadline_urgent;
$cnt_offline_urgent = 0;
$resql = $db->query($sql_offline_urgent);
if ($resql && $obj = $db->fetch_object($resql)) {
    $cnt_offline_urgent = (int)$obj->cnt;
}

// Failed/Rejected
$sql_failed = "SELECT COUNT(DISTINCT s.fk_facture) as cnt FROM " . MAIN_DB_PREFIX . "ksef_submissions s";
$sql_failed .= $latest_join;
$sql_failed .= " LEFT JOIN " . MAIN_DB_PREFIX . "facture f ON s.fk_facture = f.rowid";
$sql_failed .= " WHERE f.entity IN (" . getEntity('invoice') . ")";
$sql_failed .= " AND s.status IN ('FAILED', 'REJECTED', 'TIMEOUT')";
$cnt_failed = 0;
$resql = $db->query($sql_failed);
if ($resql && $obj = $db->fetch_object($resql)) {
    $cnt_failed = (int)$obj->cnt;
}

// Pending/Submitted
$sql_pending = "SELECT COUNT(DISTINCT s.fk_facture) as cnt FROM " . MAIN_DB_PREFIX . "ksef_submissions s";
$sql_pending .= $latest_join;
$sql_pending .= " LEFT JOIN " . MAIN_DB_PREFIX . "facture f ON s.fk_facture = f.rowid";
$sql_pending .= " WHERE f.entity IN (" . getEntity('invoice') . ")";
$sql_pending .= " AND s.status IN ('PENDING', 'SUBMITTED')";
$cnt_pending = 0;
$resql = $db->query($sql_pending);
if ($resql && $obj = $db->fetch_object($resql)) {
    $cnt_pending = (int)$obj->cnt;
}

// Incoming
$cnt_incoming_new = (int)($stats_incoming['new'] ?? 0);
$cnt_incoming_error = (int)($stats_incoming['error'] ?? 0);


$environment = getDolGlobalString('KSEF_ENVIRONMENT', 'TEST');
$config_ok = !empty($conf->global->KSEF_COMPANY_NIP) && (!empty(getDolGlobalString('KSEF_AUTH_TOKEN_' . $environment)) || !empty(getDolGlobalString('KSEF_AUTH_CERTIFICATE_' . $environment)));

$cert_warning = false;
$cert_days_left = null;
$_certValidTo = getDolGlobalString('KSEF_AUTH_CERT_VALID_TO_' . $environment, '');
if (!empty($_certValidTo)) {
    $cert_expiry = strtotime($_certValidTo);
    if ($cert_expiry) {
        $cert_days_left = floor(($cert_expiry - $now) / 86400);
        if ($cert_days_left <= 30 && $cert_days_left > 0) {
            $cert_warning = true;
        }
    }
}

llxHeader("", $langs->trans("KSEF_Area"), '', '', 0, 0, '', '', '', 'mod-ksef page-index');

print load_fiche_titre($langs->trans("KSEF_Area"), '', 'ksef@ksef');

print '<div class="fichecenter">';

print '<div class="opened-dash-board-wrap"><div class="box-flex-container">';

// Outgoing
print '<div class="box-flex-item"><div class="box-flex-item-with-margin">';
print '<div class="info-box">';
print '<span class="info-box-icon bg-infobox-facture"><i class="fa fa-file-invoice"></i></span>';
print '<div class="info-box-content">';
print '<div class="info-box-title" title="' . dol_escape_htmltag($langs->trans("KSEF_OutgoingInvoices")) . '">' . $langs->trans("KSEF_OutgoingInvoices") . '</div>';
print '<div class="info-box-lines">';
print '<div class="info-box-line spanoverflow nowrap">';
print '<a href="' . dol_buildpath('/ksef/status.php', 1) . '" class="info-box-text info-box-text-a">';
print '<div class="marginrightonly inline-block valignmiddle info-box-line-text" title="' . dol_escape_htmltag($langs->trans("KSEF_Last30Days")) . '">' . $langs->trans("KSEF_Sent") . '</div>';
print '</a>';
print '<div class="inline-block nowraponall">';
print '<span class="classfortooltip badge badge-info" title="' . dol_escape_htmltag($langs->trans("KSEF_TotalSubmissions")) . '">' . $stats_outgoing['total'] . '</span>';
print '</div></div>';
print '<div class="info-box-line spanoverflow nowrap">';
print '<div class="marginrightonly inline-block valignmiddle info-box-line-text">' . $langs->trans("KSEF_Accepted") . '</div>';
print '<div class="inline-block nowraponall">';
print '<span class="classfortooltip badge badge-status4" title="' . $stats_outgoing['success_rate'] . '% ' . $langs->trans("KSEF_SuccessRate") . '">' . $stats_outgoing['accepted'] . '</span>';
print '</div></div>';
print '</div></div></div>';
print '</div></div>';

// Incoming
print '<div class="box-flex-item"><div class="box-flex-item-with-margin">';
print '<div class="info-box">';
print '<span class="info-box-icon bg-infobox-supplier_proposal"><i class="fa fa-file-download"></i></span>';
print '<div class="info-box-content">';
print '<div class="info-box-title" title="' . dol_escape_htmltag($langs->trans("KSEF_IncomingInvoices")) . '">' . $langs->trans("KSEF_IncomingInvoices") . '</div>';
print '<div class="info-box-lines">';
print '<div class="info-box-line spanoverflow nowrap">';
print '<a href="' . dol_buildpath('/ksef/incoming_list.php', 1) . '" class="info-box-text info-box-text-a">';
print '<div class="marginrightonly inline-block valignmiddle info-box-line-text">' . $langs->trans("Total") . '</div>';
print '</a>';
print '<div class="inline-block nowraponall">';
print '<span class="badge badge-info">' . $stats_incoming['total'] . '</span>';
print '</div></div>';
if ($cnt_incoming_new > 0) {
    print '<div class="info-box-line spanoverflow nowrap">';
    print '<a href="' . dol_buildpath('/ksef/incoming_list.php', 1) . '?search_import_status=NEW" class="info-box-text info-box-text-a">';
    print '<div class="marginrightonly inline-block valignmiddle info-box-line-text">' . $langs->trans("KSEF_ImportStatusNEW") . '</div>';
    print '</a>';
    print '<div class="inline-block nowraponall">';
    print '<span class="badge badge-status1">' . $cnt_incoming_new . '</span>';
    print '</div></div>';
}
print '</div></div></div>';
print '</div></div>';

// Config
print '<div class="box-flex-item"><div class="box-flex-item-with-margin">';
print '<div class="info-box">';
print '<span class="info-box-icon bg-infobox-project"><i class="fa fa-cog"></i></span>';
print '<div class="info-box-content">';
print '<div class="info-box-title" title="' . dol_escape_htmltag($langs->trans("KSEF_Configuration")) . '">' . $langs->trans("KSEF_Configuration") . '</div>';
print '<div class="info-box-lines">';
print '<div class="info-box-line spanoverflow nowrap">';
print '<div class="marginrightonly inline-block valignmiddle info-box-line-text">' . $langs->trans("KSEF_ENVIRONMENT") . '</div>';
print '<div class="inline-block nowraponall">' . ksefGetEnvironmentBadge($environment) . '</div>';
print '</div>';
print '<div class="info-box-line spanoverflow nowrap">';
print '<div class="marginrightonly inline-block valignmiddle info-box-line-text">' . $langs->trans("Status") . '</div>';
print '<div class="inline-block nowraponall">';
if ($config_ok) {
    print '<span class="badge badge-status4">' . $langs->trans("KSEF_Ready") . '</span>';
} else {
    print '<span class="badge badge-status8">' . $langs->trans("KSEF_SetupRequired") . '</span>';
}
print '</div></div>';
print '</div></div></div>';
print '</div></div>';

// KSeF System Status (Latarnia)
$latarnia_cached = KsefLatarnia::getCachedStatus();
$latarnia_status = $latarnia_cached['status'];
$latarnia_timestamp = $latarnia_cached['timestamp'];
$latarnia_messages = $latarnia_cached['messages'];

print '<div class="box-flex-item"><div class="box-flex-item-with-margin">';
print '<div class="info-box">';
print '<span class="info-box-icon bg-infobox-action"><i class="fas fa-signal"></i></span>';
print '<div class="info-box-content">';
print '<div class="info-box-title" title="' . dol_escape_htmltag($langs->trans("KSEF_SystemStatus")) . '">' . $langs->trans("KSEF_SystemStatus") . '</div>';
print '<div class="info-box-lines">';

// Status badge
print '<div class="info-box-line spanoverflow nowrap">';
print '<div class="marginrightonly inline-block valignmiddle info-box-line-text">' . $langs->trans("Status") . '</div>';
print '<div class="inline-block nowraponall">' . ksefGetLatarniaStatusBadge($latarnia_status) . '</div>';
print '</div>';

// Last checked + Check Now
print '<div class="info-box-line spanoverflow nowrap">';
print '<div class="marginrightonly inline-block valignmiddle info-box-line-text">';
if ($latarnia_timestamp > 0) {
    print dol_print_date($latarnia_timestamp, 'dayhour');
} else {
    print $langs->trans("KSEF_NeverChecked");
}
print '</div>';
print '<div class="inline-block nowraponall">';
print '<a href="' . $_SERVER['PHP_SELF'] . '?action=checklatarnia&token=' . newToken() . '" class="classfortooltip" title="' . dol_escape_htmltag($langs->trans("KSEF_CheckNow")) . '">';
print '<i class="fas fa-sync-alt paddingleft" style="font-size: 0.85em;"></i>';
print '</a>';
print '</div>';
print '</div>';

print '</div></div></div>';
print '</div></div>';

// Needs Attention card
$attention_items = array();
if ($cnt_offline_urgent > 0) {
    $attention_items[] = '<a href="' . dol_buildpath('/ksef/status.php', 1) . '?search_status=PENDING"><span class="badge badge-status8 marginrightonly">' . $cnt_offline_urgent . '</span>' . $langs->trans("KSEF_OfflineDeadlineSoon") . '</a>';
}
if ($cnt_failed > 0) {
    $attention_items[] = '<a href="' . dol_buildpath('/ksef/status.php', 1) . '?search_status=FAILED"><span class="badge badge-status8 marginrightonly">' . $cnt_failed . '</span>' . $langs->trans("KSEF_FailedCanRetry") . '</a>';
}
if ($cnt_pending > 0) {
    $attention_items[] = '<a href="' . dol_buildpath('/ksef/status.php', 1) . '?search_status=PENDING"><span class="badge badge-status1 marginrightonly">' . $cnt_pending . '</span>' . $langs->trans("KSEF_AwaitingConfirmation") . '</a>';
}
if ($cnt_incoming_new > 0) {
    $attention_items[] = '<a href="' . dol_buildpath('/ksef/incoming_list.php', 1) . '?search_import_status=NEW"><span class="badge badge-status1 marginrightonly">' . $cnt_incoming_new . '</span>' . $langs->trans("KSEF_NewToReview") . '</a>';
}
if ($cnt_incoming_error > 0) {
    $attention_items[] = '<a href="' . dol_buildpath('/ksef/incoming_list.php', 1) . '?search_import_status=ERROR"><span class="badge badge-status8 marginrightonly">' . $cnt_incoming_error . '</span>' . $langs->trans("KSEF_ImportErrors") . '</a>';
}

print '<div class="box-flex-item"><div class="box-flex-item-with-margin">';
print '<div class="info-box">';
print '<span class="info-box-icon bg-infobox-contrat"><i class="fas fa-exclamation-circle"></i></span>';
print '<div class="info-box-content">';
print '<div class="info-box-title" title="' . dol_escape_htmltag($langs->trans("KSEF_NeedsAttention")) . '">' . $langs->trans("KSEF_NeedsAttention") . '</div>';
print '<div class="info-box-lines">';
if (!empty($attention_items)) {
    foreach ($attention_items as $item) {
        print '<div class="info-box-line spanoverflow nowrap">' . $item . '</div>';
    }
} else {
    print '<div class="info-box-line"><span class="badge badge-status4">' . $langs->trans("KSEF_NothingToReport") . '</span></div>';
}
print '</div></div></div>';
print '</div></div>';

print '<div class="box-flex-item filler"></div>';
print '<div class="box-flex-item filler"></div>';

print '</div></div>';

if ($cert_warning && $cert_days_left !== null) {
    print '<div class="warning">';
    print '<span class="fa fa-exclamation-triangle"></span> ';
    print $langs->trans("KSEF_CertificateExpiresInDays", $cert_days_left);
    print '</div><br>';
}

print '<div class="clearboth"></div>';
print '<div class="fichecenterbis">';
print '<div class="twocolumns">';

// Outgoing Invoices
print '<div class="firstcolumn fichehalfleft boxhalfleft" id="boxhalfleft">';

print '<div class="box divboxtable">';
print '<table class="noborder boxtable centpercent">';
print '<tr class="liste_titre box_titre"><th colspan="2">';
print '<div class="tdoverflowmax400 maxwidth250onsmartphone float">';
print $langs->trans("KSEF_Statistics");
print '</div></th></tr>';

print '<tr class="oddeven"><td>' . $langs->trans("KSEF_TotalSubmissions") . '</td>';
print '<td class="right nowraponall"><strong>' . $stats_outgoing_total['total'] . '</strong></td></tr>';

print '<tr class="oddeven"><td>' . $langs->trans("KSEF_Accepted") . ' <span class="opacitymedium small">(' . $langs->trans("KSEF_Last30Days") . ')</span></td>';
print '<td class="right nowraponall"><span class="badge badge-status4">' . $stats_outgoing['accepted'] . '</span></td></tr>';

print '<tr class="oddeven"><td>' . $langs->trans("KSEF_Pending") . ' <span class="opacitymedium small">(' . $langs->trans("KSEF_Last30Days") . ')</span></td>';
print '<td class="right nowraponall"><span class="badge badge-status1">' . $stats_outgoing['pending'] . '</span></td></tr>';

print '<tr class="oddeven"><td>' . $langs->trans("KSEF_Failed") . ' <span class="opacitymedium small">(' . $langs->trans("KSEF_Last30Days") . ')</span></td>';
print '<td class="right nowraponall"><span class="badge badge-status8">' . $stats_outgoing['failed'] . '</span></td></tr>';

print '<tr class="liste_total"><td class="liste_total">' . $langs->trans("KSEF_SuccessRate") . ' <span class="opacitymedium small">(' . $langs->trans("KSEF_Last30Days") . ')</span></td>';
print '<td class="liste_total right nowraponall"><strong>' . $stats_outgoing['success_rate'] . '%</strong></td></tr>';

print '</table></div>';

print '<div class="box divboxtable">';
print '<table class="noborder boxtable centpercent">';
print '<tr class="liste_titre box_titre"><th colspan="4">';
print '<div class="tdoverflowmax400 maxwidth250onsmartphone float">';
print $langs->trans("KSEF_RecentSubmissions");
print '<a class="paddingleft" href="' . dol_buildpath('/ksef/status.php', 1) . '"><span class="badge">...</span></a>';
print '</div></th></tr>';

$sql = "SELECT s.rowid, s.fk_facture, s.status, s.date_submission, s.ksef_number, s.environment,";
$sql .= " f.ref as invoice_ref, soc.nom as company_name, soc.rowid as socid,";
$sql .= " (SELECT COUNT(*) FROM " . MAIN_DB_PREFIX . "ksef_submissions s2 WHERE s2.fk_facture = s.fk_facture) as total_attempts";
$sql .= " FROM " . MAIN_DB_PREFIX . "ksef_submissions as s";
$sql .= $latest_join;
$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "facture as f ON s.fk_facture = f.rowid";
$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "societe as soc ON f.fk_soc = soc.rowid";
$sql .= " WHERE f.entity IN (" . getEntity('invoice') . ")";
$sql .= " ORDER BY s.date_submission DESC LIMIT " . $max;

$resql = $db->query($sql);
if ($resql) {
    $num = $db->num_rows($resql);
    if ($num > 0) {
        while ($obj = $db->fetch_object($resql)) {
            print '<tr class="oddeven">';
            print '<td class="nowraponall tdoverflowmax150">';
            print '<a href="' . DOL_URL_ROOT . '/compta/facture/card.php?facid=' . $obj->fk_facture . '">';
            print '<span class="fas fa-file-invoice-dollar infobox-commande pictofixedwidth"></span>';
            print dol_escape_htmltag($obj->invoice_ref) . '</a>';
            if ($obj->total_attempts > 1) {
                print ' <a href="' . dol_buildpath('/ksef/tab_ksef.php', 1) . '?id=' . $obj->fk_facture . '" class="classfortooltip" title="' . $langs->trans("KSEF_ViewAllAttemptsOnInvoice") . '">';
                print '<span class="badge badge-secondary">' . $obj->total_attempts . '</span></a>';
            }
            print '</td>';
            print '<td class="tdoverflowmax150">';
            if ($obj->socid > 0) {
                print '<a href="' . DOL_URL_ROOT . '/societe/card.php?socid=' . $obj->socid . '">' . dol_escape_htmltag($obj->company_name) . '</a>';
            }
            print '</td>';
            print '<td class="center nowraponall">' . dol_print_date($obj->date_submission, 'day') . '</td>';
            print '<td class="right" width="18">';
            $status_class = 'badge-status0';
            $status_title = $obj->status;
            if ($obj->status == 'ACCEPTED') {
                $status_class = 'badge-status4';
            } elseif (in_array($obj->status, array('PENDING', 'SUBMITTED'))) {
                $status_class = 'badge-status1';
            } elseif (in_array($obj->status, array('FAILED', 'REJECTED', 'TIMEOUT'))) {
                $status_class = 'badge-status8';
            }
            print '<span class="badge badge-dot ' . $status_class . ' classfortooltip badge-status" title="' . dol_escape_htmltag($langs->trans('KSEF_STATUS_' . $obj->status)) . '"></span>';
            print '</td>';
            print '</tr>';
        }
    } else {
        print '<tr class="oddeven"><td colspan="4" class="center">';
        print '<span class="opacitymedium">' . $langs->trans("KSEF_NoRecentSubmissions") . '</span>';
        print '</td></tr>';
    }
    $db->free($resql);
} else {
    dol_print_error($db);
}

print '</table></div>';

print '<div class="center" style="margin-top: 10px;">';
print '<a class="butAction" href="' . DOL_URL_ROOT . '/compta/facture/list.php">';
print '<span class="fas fa-plus-circle pictofixedwidth"></span>' . $langs->trans("KSEF_CreateInvoice") . '</a> ';
print '<a class="butAction" href="' . dol_buildpath('/ksef/status.php', 1) . '">' . $langs->trans("KSEF_ViewAll") . '</a>';
print '</div>';

print '</div>'; // end fichehalfleft

// Incoming
print '<div class="secondcolumn fichehalfright boxhalfright" id="boxhalfright">';

print '<div class="box divboxtable">';
print '<table class="noborder boxtable centpercent">';
print '<tr class="liste_titre box_titre"><th colspan="2">';
print '<div class="tdoverflowmax400 maxwidth250onsmartphone float">';
print $langs->trans("KSEF_Statistics");
print '</div></th></tr>';

print '<tr class="oddeven"><td>' . $langs->trans("Total") . '</td>';
print '<td class="right nowraponall"><strong>' . $stats_incoming['total'] . '</strong></td></tr>';

print '<tr class="oddeven"><td>' . $langs->trans("KSEF_ImportStatusNEW") . '</td>';
print '<td class="right nowraponall"><span class="badge badge-status1">' . ($stats_incoming['new'] ?? 0) . '</span></td></tr>';

print '<tr class="oddeven"><td>' . $langs->trans("KSEF_ImportStatusIMPORTED") . '</td>';
print '<td class="right nowraponall"><span class="badge badge-status4">' . ($stats_incoming['imported'] ?? 0) . '</span></td></tr>';

if (!empty($stats_incoming['skipped'])) {
    print '<tr class="oddeven"><td>' . $langs->trans("KSEF_ImportStatusSKIPPED") . '</td>';
    print '<td class="right nowraponall"><span class="badge badge-status0">' . $stats_incoming['skipped'] . '</span></td></tr>';
}

if (!empty($stats_incoming['error'])) {
    print '<tr class="oddeven"><td>' . $langs->trans("KSEF_ImportStatusERROR") . '</td>';
    print '<td class="right nowraponall"><span class="badge badge-status8">' . $stats_incoming['error'] . '</span></td></tr>';
}

$ksefStartDate = strtotime('2026-02-01');
$ksefPreStartDate = strtotime('2026-01-31');
$todayTs = dol_now();
$hwmTimestamp = !empty($syncState->hwm_date) ? $syncState->getContinuationDate() : null;
if ($hwmTimestamp && $hwmTimestamp < $ksefPreStartDate) $hwmTimestamp = $ksefPreStartDate;
if ($hwmTimestamp && $hwmTimestamp > $todayTs) $hwmTimestamp = $todayTs;
$totalSpan = max($todayTs - $ksefStartDate, 1);
$hwmPercent = $hwmTimestamp ? round((($hwmTimestamp - $ksefStartDate) / $totalSpan) * 100, 1) : 0;
if ($hwmPercent < 0) $hwmPercent = 0;
if ($hwmPercent > 100) $hwmPercent = 100;

if ($hwmPercent >= 95) {
    $barColor = '#4CAF50';
    $barStatus = $langs->trans("KSEF_SyncProgressUpToDate");
} elseif ($hwmPercent >= 50) {
    $barColor = '#FF9800';
    $barStatus = $langs->trans("KSEF_SyncProgressPartial");
} elseif ($hwmTimestamp) {
    $barColor = '#f44336';
    $barStatus = $langs->trans("KSEF_SyncProgressBehind");
} else {
    $barColor = '#d0d0d0';
    $barStatus = $langs->trans("KSEF_SyncNotStarted");
}

print '<tr class="oddeven"><td>' . $langs->trans("KSEF_SyncProgress") . '</td>';
print '<td class="right nowraponall">';
print '<div style="display: inline-block; width: 120px; height: 14px; background: #d0d0d0; border-radius: 3px; vertical-align: middle; margin-right: 6px;">';
if ($hwmPercent > 0) {
    print '<div style="height: 100%; width: ' . $hwmPercent . '%; background: ' . $barColor . '; border-radius: 3px;"></div>';
}
print '</div>';
print '<span style="color: ' . $barColor . '; font-weight: 600; font-size: 0.85em;">' . $barStatus . '</span>';
if ($hwmTimestamp && $hwmPercent < 100) {
    print ' <span class="opacitymedium small">(' . $hwmPercent . '%)</span>';
}
print '</td></tr>';

print '</table></div>';

print '<div class="box divboxtable">';
print '<table class="noborder boxtable centpercent">';
print '<tr class="liste_titre box_titre"><th colspan="4">';
print '<div class="tdoverflowmax400 maxwidth250onsmartphone float">';
print $langs->trans("KSEF_RecentIncoming");
print '<a class="paddingleft" href="' . dol_buildpath('/ksef/incoming_list.php', 1) . '"><span class="badge">...</span></a>';
print '</div></th></tr>';

$sql_incoming = "SELECT rowid, ksef_number, seller_name, seller_nip, invoice_number, invoice_date, total_gross, currency, import_status";
$sql_incoming .= " FROM " . MAIN_DB_PREFIX . "ksef_incoming";
$sql_incoming .= " WHERE entity IN (" . getEntity('invoice') . ")";
$sql_incoming .= " ORDER BY fetch_date DESC LIMIT " . $max;

$resql = $db->query($sql_incoming);
if ($resql) {
    $num = $db->num_rows($resql);
    if ($num > 0) {
        while ($obj = $db->fetch_object($resql)) {
            print '<tr class="oddeven">';
            print '<td class="nowraponall tdoverflowmax150">';
            print '<a href="' . dol_buildpath('/ksef/incoming_card.php', 1) . '?id=' . $obj->rowid . '">';
            print '<span class="fas fa-file-invoice-dollar infobox-order_supplier pictofixedwidth"></span>';
            print dol_escape_htmltag($obj->invoice_number) . '</a>';
            print '</td>';
            print '<td class="tdoverflowmax150">' . dol_escape_htmltag($obj->seller_name) . '</td>';
            print '<td class="center nowraponall">' . dol_print_date($obj->invoice_date, 'day') . '</td>';
            print '<td class="right" width="18">';
            $status_class = 'badge-status0';
            if ($obj->import_status == 'IMPORTED') {
                $status_class = 'badge-status4';
            } elseif ($obj->import_status == 'NEW') {
                $status_class = 'badge-status1';
            } elseif ($obj->import_status == 'ERROR') {
                $status_class = 'badge-status8';
            }
            print '<span class="badge badge-dot ' . $status_class . ' classfortooltip badge-status" title="' . dol_escape_htmltag($langs->trans('KSEF_ImportStatus' . $obj->import_status)) . '"></span>';
            print '</td>';
            print '</tr>';
        }
    } else {
        print '<tr class="oddeven"><td colspan="4" class="center">';
        print '<span class="opacitymedium">' . $langs->trans("KSEF_NoIncomingInvoices") . '</span>';
        print '</td></tr>';
    }
    $db->free($resql);
} else {
    print '<tr class="oddeven"><td colspan="4" class="center">';
    print '<span class="opacitymedium">' . $langs->trans("KSEF_NoIncomingInvoices") . '</span>';
    print '</td></tr>';
}

print '</table></div>';

print '<div class="center" style="margin-top: 10px;">';
print '<a class="butAction" href="' . dol_buildpath('/ksef/incoming_list.php', 1) . '?action=sync&token=' . newToken() . '">';
print '<span class="fas fa-sync pictofixedwidth"></span>' . $langs->trans("KSEF_SyncFromKSeF") . '</a> ';
print '<a class="butAction" href="' . dol_buildpath('/ksef/incoming_list.php', 1) . '">' . $langs->trans("KSEF_ViewAll") . '</a>';
print '</div>';
print '</div>';
print '</div>';
print '</div>';
print '</div>';


// Latarnia messages (full-width box)
if (!empty($latarnia_messages)) {
    print '<div class="clearboth"></div>';
    print '<div class="div-table-responsive-no-min" style="margin-top: 10px;">';
    print '<table class="noborder centpercent">';
    print '<tr class="liste_titre">';
    print '<th>' . $langs->trans("KSEF_SystemStatus") . ' - ' . $langs->trans("KSEF_LatarniaMessages") . '</th>';
    print '<th>' . $langs->trans("Type") . '</th>';
    print '<th class="center">' . $langs->trans("KSEF_Start") . '</th>';
    print '<th class="center">' . $langs->trans("KSEF_End") . '</th>';
    print '<th class="center">' . $langs->trans("KSEF_Published") . '</th>';
    print '</tr>';
    foreach ($latarnia_messages as $lmsg) {
        print '<tr class="oddeven">';
        print '<td style="white-space: normal;">' . dol_escape_htmltag($lmsg['title'] ?? '') . '<br><small class="opacitymedium">' . dol_escape_htmltag($lmsg['text'] ?? '') . '</small></td>';
        $cat = $lmsg['category'] ?? '';
        $category_badge = ksefGetLatarniaStatusBadge($cat);
        $type_raw = $lmsg['type'] ?? '';
        $type_label = $langs->trans('KSEF_LATARNIA_TYPE_' . $type_raw);
        if ($type_label === 'KSEF_LATARNIA_TYPE_' . $type_raw) {
            $type_label = $type_raw; // fallback to raw if no translation
        }
        print '<td class="nowraponall">' . $category_badge . '<br><small class="opacitymedium">' . dol_escape_htmltag($type_label) . '</small></td>';
        print '<td class="center">' . (!empty($lmsg['start']) ? dol_print_date(strtotime($lmsg['start']), 'dayhour') : '') . '</td>';
        print '<td class="center">' . (!empty($lmsg['end']) ? dol_print_date(strtotime($lmsg['end']), 'dayhour') : '<span class="opacitymedium">-</span>') . '</td>';
        print '<td class="center">' . (!empty($lmsg['published']) ? dol_print_date(strtotime($lmsg['published']), 'dayhour') : '') . '</td>';
        print '</tr>';
    }
    print '</table>';
    print '</div>';
}

if (!$config_ok) {
    print '<div class="clearboth"></div><br>';
    print '<div class="info">';
    print '<strong>' . $langs->trans("KSEF_SetupRequired") . '</strong><br>';
    print $langs->trans("KSEF_SetupRequiredDesc");
    print '<br><br><a class="butAction" href="' . dol_buildpath('/ksef/admin/setup.php', 1) . '">' . $langs->trans("KSEF_ConfigureNow") . '</a>';
    print '</div>';
}

llxFooter();
$db->close();