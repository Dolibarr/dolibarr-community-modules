<?php
/* Copyright (C) 2025 InPoint Automation Sp z o.o.
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
 * \file    ksef/status.php
 * \ingroup ksef
 * \brief   KSEF submission status
 */

// CSRF check
if (!defined('CSRFCHECK_WITH_TOKEN')) {
    define('CSRFCHECK_WITH_TOKEN', '1');
}

// Prevent token renewal for actions that exit without page reload
$action_raw = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : '');
if (in_array($action_raw, array('download_xml', 'download_upo'))) {
    if (!defined('NOTOKENRENEWAL')) {
        define('NOTOKENRENEWAL', '1');
    }
}

$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) $res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"] . "/main.inc.php";
if (!$res && file_exists("../main.inc.php")) $res = @include "../main.inc.php";
if (!$res && file_exists("../../main.inc.php")) $res = @include "../../main.inc.php";
if (!$res && file_exists("../../../main.inc.php")) $res = @include "../../../main.inc.php";
if (!$res) die("Include of main fails");

global $conf, $langs, $user, $db, $hookmanager;

require_once DOL_DOCUMENT_ROOT . '/core/class/html.formother.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/date.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/company.lib.php';
dol_include_once('/ksef/class/ksef_submission.class.php');
dol_include_once('/ksef/class/ksef_service.class.php');
dol_include_once('/ksef/class/ksef_latarnia.class.php');
dol_include_once('/ksef/lib/ksef.lib.php');
dol_include_once('/ksef/class/ksef_client.class.php');

$langs->loadLangs(array("ksef@ksef", "bills"));

$action = GETPOST('action', 'aZ09');
$massaction = GETPOST('massaction', 'alpha');
$toselect = GETPOST('toselect', 'array');
$contextpage = GETPOST('contextpage', 'aZ09') ? GETPOST('contextpage', 'aZ09') : 'kseflist';
$optioncss = GETPOST('optioncss', 'alpha');

$search_ref = GETPOST('search_ref', 'alpha');
$search_ksef_number = GETPOST('search_ksef_number', 'alpha');
$search_status = GETPOST('search_status', 'alpha');
$search_environment = GETPOST('search_environment', 'alpha');
$search_date_start = dol_mktime(0, 0, 0, GETPOST('search_date_startmonth', 'int'), GETPOST('search_date_startday', 'int'), GETPOST('search_date_startyear', 'int'));
$search_date_end = dol_mktime(23, 59, 59, GETPOST('search_date_endmonth', 'int'), GETPOST('search_date_endday', 'int'), GETPOST('search_date_endyear', 'int'));

$limit = GETPOST('limit', 'int') ? GETPOST('limit', 'int') : $conf->liste_limit;
$sortfield = GETPOST('sortfield', 'aZ09comma');
$sortorder = GETPOST('sortorder', 'aZ09comma');
$page = GETPOSTISSET('pageplusone') ? (GETPOST('pageplusone') - 1) : GETPOST("page", 'int');
if (empty($page) || $page < 0 || GETPOST('button_search', 'alpha') || GETPOST('button_removefilter', 'alpha')) $page = 0;
$offset = $limit * $page;

if (!$sortfield) {
    $sortfield = "s.date_submission";
    $sortorder = "DESC";
}

if (!$user->hasRight('ksef', 'read')) accessforbidden();

$socid = GETPOST('socid', 'int');
if (isset($user->socid) && $user->socid > 0) {
    $action = '';
    $socid = $user->socid;
}

$object = new KsefSubmission($db);
$ksef = new KSEFService($db);
$extrafields = new ExtraFields($db);

$parameters = array('socid' => $socid);
$reshook = $hookmanager->executeHooks('doActions', $parameters, $object, $action);
if ($reshook < 0) setEventMessages($hookmanager->error, $hookmanager->errors, 'errors');

if (empty($reshook)) {
    include DOL_DOCUMENT_ROOT . '/core/actions_changeselectedfields.inc.php';

    if (GETPOST('button_removefilter_x', 'alpha') || GETPOST('button_removefilter.x', 'alpha') || GETPOST('button_removefilter', 'alpha')) {
        $search_ref = '';
        $search_ksef_number = '';
        $search_status = '';
        $search_environment = '';
        $search_date_start = '';
        $search_date_end = '';
        $toselect = array();
    }

    if (!empty($massaction) && !empty($toselect)) {
        if ($massaction == 'retry') {
            $num_processed = 0;
            $num_errors = 0;
            foreach ($toselect as $toselectid) {
                $submission = new KsefSubmission($db);
                if ($submission->fetch($toselectid) > 0) {
                    $result = $ksef->retrySubmission($submission->fk_facture, $user);
                    if ($result && $result['status'] !== 'FAILED') $num_processed++;
                    else $num_errors++;
                }
            }
            if ($num_processed > 0) setEventMessages($langs->trans("NbRecordsProcessed", $num_processed), null, 'mesgs');
            if ($num_errors > 0) setEventMessages($langs->trans("NbRecordsWithErrors", $num_errors), null, 'errors');
        }

        if ($massaction == 'delete' && GETPOST('confirmmassaction', 'alpha')) {
            if (!$user->hasRight('facture', 'supprimer')) accessforbidden();
            $num_deleted = 0;
            $num_errors = 0;
            $db->begin();
            foreach ($toselect as $toselectid) {
                if ($db->query("DELETE FROM " . MAIN_DB_PREFIX . "ksef_submissions WHERE rowid = " . (int)$toselectid)) $num_deleted++;
                else {
                    $num_errors++;
                    dol_syslog("Failed to delete submission ID $toselectid: " . $db->lasterror(), LOG_ERR);
                }
            }
            if ($num_errors == 0) {
                $db->commit();
                setEventMessages($langs->trans("RecordsDeleted", $num_deleted), null, 'mesgs');
            } else {
                $db->rollback();
                setEventMessages($langs->trans("Error") . " - " . $num_deleted . " deleted, " . $num_errors . " errors", null, 'errors');
            }
            header("Location: " . $_SERVER["PHP_SELF"]);
            exit;
        }
    }

    if ($action == 'retry' && GETPOST('id', 'int')) {
        $id = GETPOST('id', 'int');
        $submission = new KsefSubmission($db);
        if ($submission->fetch($id) > 0) {
            $result = $ksef->retrySubmission($submission->fk_facture, $user);
            if ($result && $result['status'] !== 'FAILED') setEventMessages($langs->trans("KSEF_SubmissionRetried"), null, 'mesgs');
            else setEventMessages($ksef->error, $ksef->errors, 'errors');
        }
        header("Location: " . $_SERVER["PHP_SELF"]);
        exit;
    }

    if ($action == 'delete' && GETPOST('id', 'int') && $user->hasRight('facture', 'supprimer')) {
        $id = GETPOST('id', 'int');
        $db->begin();
        if ($db->query("DELETE FROM " . MAIN_DB_PREFIX . "ksef_submissions WHERE rowid = " . (int)$id)) {
            $db->commit();
            setEventMessages($langs->trans("RecordDeleted"), null, 'mesgs');
        } else {
            $db->rollback();
            setEventMessages($langs->trans("Error") . ": " . $db->lasterror(), null, 'errors');
        }
        header("Location: " . $_SERVER["PHP_SELF"]);
        exit;
    }

    if ($action == 'view_error' && GETPOST('id', 'int')) {
        $id = GETPOST('id', 'int');
        $submission = new KsefSubmission($db);
        if ($submission->fetch($id) > 0) {
            print '<div class="error-modal" style="position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; padding: 30px; border: 1px solid #ccc; box-shadow: 0 4px 6px rgba(0,0,0,0.1); z-index: 9999; max-width: 600px; max-height: 80vh; overflow-y: auto;">';
            print '<h3>' . $langs->trans("KSEF_ErrorDetails") . '</h3>';
            if ($submission->error_code) {
                $ksefClient = new KsefClient($db);
                print '<p><strong>' . $langs->trans("KSEF_ErrorCode") . ':</strong> ' . $submission->error_code . '</p>';
                print '<p><strong>' . $langs->trans("Description") . ':</strong> ' . $ksefClient->getErrorDescription($submission->error_code) . '</p>';
            }
            if ($submission->error_message) print '<p><strong>' . $langs->trans("Message") . ':</strong><br>' . nl2br(dol_escape_htmltag($submission->error_message)) . '</p>';
            $errorDetails = $submission->getErrorDetailsArray();
            if ($errorDetails && !empty($errorDetails['details'])) {
                print '<p><strong>' . $langs->trans("KSEF_TechnicalDetails") . ':</strong></p><ul>';
                foreach ($errorDetails['details'] as $detail) print '<li>' . dol_escape_htmltag($detail) . '</li>';
                print '</ul>';
            }
            print '<br><a class="button" href="' . $_SERVER["PHP_SELF"] . '">' . $langs->trans("Close") . '</a></div>';
            print '<div style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 9998;"></div>';
        }
    }

    if ($action == 'check_status' && GETPOST('id', 'int')) {
        $id = GETPOST('id', 'int');
        $submission = new KsefSubmission($db);
        if ($submission->fetch($id) > 0 && !empty($submission->ksef_reference)) {
            $result = $ksef->checkStatus($submission->fk_facture, $user);
            if ($result && $result['status'] !== 'ERROR') setEventMessages($langs->trans("KSEF_StatusUpdated"), null, 'mesgs');
            else setEventMessages($ksef->error, $ksef->errors, 'errors');
        }
        header("Location: " . $_SERVER["PHP_SELF"]);
        exit;
    }

    if ($action == 'download_xml' && GETPOST('id', 'int')) {
        $id = GETPOST('id', 'int');
        $submission = new KsefSubmission($db);
        if ($submission->fetch($id) > 0 && !empty($submission->fa3_xml)) {
            $filename = 'FA3_' . ($submission->ksef_number ?: 'invoice_' . $submission->fk_facture) . '.xml';
            header('Content-Type: application/xml; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            echo $submission->fa3_xml;
            exit;
        }
    }

    if ($action == 'download_upo' && GETPOST('id', 'int')) {
        $id = GETPOST('id', 'int');
        $submission = new KsefSubmission($db);
        if ($submission->fetch($id) > 0) {
            // If UPO not cached, try fetching from KSeF API
            if (empty($submission->upo_xml) && !empty($submission->ksef_number) && $submission->status == 'ACCEPTED') {
                try {
                    $ksef = new KsefService($db);
                    $ksef->downloadUPO($submission->fk_facture, $user);
                    // Re-fetch to get updated upo_xml
                    $submission->fetch($id);
                } catch (Exception $e) {
                    dol_syslog("KsefStatus: UPO fetch from API failed: " . $e->getMessage(), LOG_WARNING);
                }
            }

            if (!empty($submission->upo_xml)) {
                header('Content-Type: application/xml; charset=utf-8');
                header('Content-Disposition: attachment; filename="UPO_' . $submission->ksef_number . '.xml"');
                echo $submission->upo_xml;
                exit;
            } else {
                setEventMessages($langs->trans("KSEF_UPONotAvailable"), null, 'warnings');
            }
        }
    }
}

$form = new Form($db);
$formother = new FormOther($db);

$title = $langs->trans("KSEF_Status");
$help_url = '';
llxHeader('', $title, $help_url, '', 0, 0, '', '', '', 'mod-ksef page-status');

$sql = "SELECT";
$sql .= " s.rowid,";
$sql .= " s.fk_facture,";
$sql .= " s.ksef_reference,";
$sql .= " s.ksef_number,";
$sql .= " s.invoice_hash,";
$sql .= " s.status,";
$sql .= " s.environment,";
$sql .= " s.date_submission,";
$sql .= " s.date_acceptance,";
$sql .= " s.error_message,";
$sql .= " s.error_code,";
$sql .= " s.error_details,";
$sql .= " s.retry_count,";
$sql .= " s.fa3_xml,";
$sql .= " s.offline_mode,";
$sql .= " s.offline_deadline,";
$sql .= " f.ref as invoice_ref,";
$sql .= " f.total_ttc,";
$sql .= " soc.nom as company_name,";
$sql .= " soc.rowid as socid,";
$sql .= " (SELECT COUNT(*) FROM " . MAIN_DB_PREFIX . "ksef_submissions s2 WHERE s2.fk_facture = s.fk_facture) as total_attempts";
$sql .= " FROM " . MAIN_DB_PREFIX . "ksef_submissions as s";

// Only latest submission per invoice
$sql .= " INNER JOIN (";
$sql .= "   SELECT fk_facture, MAX(date_submission) as latest_date";
$sql .= "   FROM " . MAIN_DB_PREFIX . "ksef_submissions";
$sql .= "   GROUP BY fk_facture";
$sql .= " ) latest ON s.fk_facture = latest.fk_facture AND s.date_submission = latest.latest_date";

$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "facture as f ON s.fk_facture = f.rowid";
$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "societe as soc ON f.fk_soc = soc.rowid";
$sql .= " WHERE f.entity IN (" . getEntity('invoice') . ")";

if ($search_ref) $sql .= natural_search('f.ref', $search_ref);
if ($search_ksef_number) $sql .= natural_search('s.ksef_number', $search_ksef_number);
if ($search_status && $search_status != '-1') $sql .= " AND s.status = '" . $db->escape($search_status) . "'";
if ($search_environment && $search_environment != '-1') $sql .= " AND s.environment = '" . $db->escape($search_environment) . "'";
if ($search_date_start) $sql .= " AND s.date_submission >= " . ((int)$search_date_start);
if ($search_date_end) $sql .= " AND s.date_submission <= " . ((int)$search_date_end);
if ($socid > 0) $sql .= " AND soc.rowid = " . ((int)$socid);

$nbtotalofrecords = '';
if (!getDolGlobalInt('MAIN_DISABLE_FULL_SCANLIST')) {
    $sqlforcount = "SELECT COUNT(DISTINCT s.fk_facture) as nbtotalofrecords FROM " . MAIN_DB_PREFIX . "ksef_submissions as s";
    $sqlforcount .= " LEFT JOIN " . MAIN_DB_PREFIX . "facture as f ON s.fk_facture = f.rowid";
    $sqlforcount .= " LEFT JOIN " . MAIN_DB_PREFIX . "societe as soc ON f.fk_soc = soc.rowid";
    $sqlforcount .= " WHERE f.entity IN (" . getEntity('invoice') . ")";
    if ($search_ref) $sqlforcount .= natural_search('f.ref', $search_ref);
    if ($search_ksef_number) $sqlforcount .= natural_search('s.ksef_number', $search_ksef_number);
    if ($search_status && $search_status != '-1') $sqlforcount .= " AND s.status = '" . $db->escape($search_status) . "'";
    if ($search_environment && $search_environment != '-1') $sqlforcount .= " AND s.environment = '" . $db->escape($search_environment) . "'";
    if ($search_date_start) $sqlforcount .= " AND s.date_submission >= " . ((int)$search_date_start);
    if ($search_date_end) $sqlforcount .= " AND s.date_submission <= " . ((int)$search_date_end);
    if ($socid > 0) $sqlforcount .= " AND soc.rowid = " . ((int)$socid);

    $resql = $db->query($sqlforcount);
    if ($resql) {
        $objforcount = $db->fetch_object($resql);
        $nbtotalofrecords = $objforcount->nbtotalofrecords;
    } else {
        dol_print_error($db);
    }

    if (($page * $limit) > $nbtotalofrecords) {
        $page = 0;
        $offset = 0;
    }
    $db->free($resql);
}

$sql .= $db->order($sortfield, $sortorder);
if ($limit) $sql .= $db->plimit($limit + 1, $offset);

$resql = $db->query($sql);
if (!$resql) {
    dol_print_error($db);
    exit;
}
$num = $db->num_rows($resql);

$param = '';
if (!empty($contextpage) && $contextpage != $_SERVER["PHP_SELF"]) $param .= '&contextpage=' . urlencode($contextpage);
if ($limit > 0 && $limit != $conf->liste_limit) $param .= '&limit=' . ((int)$limit);
if ($search_ref) $param .= '&search_ref=' . urlencode($search_ref);
if ($search_ksef_number) $param .= '&search_ksef_number=' . urlencode($search_ksef_number);
if ($search_status && $search_status != '-1') $param .= '&search_status=' . urlencode($search_status);
if ($search_environment && $search_environment != '-1') $param .= '&search_environment=' . urlencode($search_environment);
if ($search_date_start) {
    $param .= '&search_date_startday=' . dol_print_date($search_date_start, '%d');
    $param .= '&search_date_startmonth=' . dol_print_date($search_date_start, '%m');
    $param .= '&search_date_startyear=' . dol_print_date($search_date_start, '%Y');
}
if ($search_date_end) {
    $param .= '&search_date_endday=' . dol_print_date($search_date_end, '%d');
    $param .= '&search_date_endmonth=' . dol_print_date($search_date_end, '%m');
    $param .= '&search_date_endyear=' . dol_print_date($search_date_end, '%Y');
}
if ($optioncss != '') $param .= '&optioncss=' . urlencode($optioncss);

$arrayofmassactions = array(
    'retry' => img_picto('', 'technic', 'class="pictofixedwidth"') . $langs->trans("KSEF_RetrySubmission"),
    'predelete' => img_picto('', 'delete', 'class="pictofixedwidth"') . $langs->trans("Delete")
);
$massactionbutton = $form->selectMassAction('', $arrayofmassactions);

$arrayofselected = is_array($toselect) ? $toselect : array();

// Build the select-all checkbox for the header
$selectedfields = '';
if (count($arrayofmassactions)) {
    $selectedfields .= $form->showCheckAddButtons('checkforselect', 1);
}

print '<form method="POST" id="searchFormList" name="searchFormList" action="' . $_SERVER["PHP_SELF"] . '">';
if ($optioncss != '') print '<input type="hidden" name="optioncss" value="' . $optioncss . '">';
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" name="formfilteraction" id="formfilteraction" value="list">';
print '<input type="hidden" name="action" value="list">';
print '<input type="hidden" name="sortfield" value="' . $sortfield . '">';
print '<input type="hidden" name="sortorder" value="' . $sortorder . '">';
print '<input type="hidden" name="page" value="' . $page . '">';
print '<input type="hidden" name="contextpage" value="' . $contextpage . '">';

print_barre_liste($langs->trans("KSEF_Status"), $page, $_SERVER["PHP_SELF"], $param, $sortfield, $sortorder, $massactionbutton, $num, $nbtotalofrecords, 'object_ksef@ksef', 0, '', '', $limit, 0, 0, 1);

// Latarnia system status
$latarnia_cached = KsefLatarnia::getCachedStatus();
print '<div style="margin-bottom: 10px; padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px; background: #f8f9fa;">';
print '<strong><i class="fas fa-signal"></i> ' . $langs->trans("KSEF_SystemStatus") . ':</strong> ';
print ksefGetLatarniaStatusBadge($latarnia_cached['status']);
if ($latarnia_cached['timestamp'] > 0) {
    print ' <span style="color: #666; font-size: 0.85em;">(' . $langs->trans("KSEF_LastChecked") . ': ' . dol_print_date($latarnia_cached['timestamp'], 'dayhour') . ')</span>';
}
if ($latarnia_cached['status'] !== 'AVAILABLE' && $latarnia_cached['status'] !== 'UNKNOWN' && !empty($latarnia_cached['messages'])) {
    $lmsg = $latarnia_cached['messages'][0];
    print '<br><span style="font-size: 0.9em; margin-left: 20px;">' . dol_escape_htmltag($lmsg['title'] ?? '') . '</span>';
}
print '</div>';

$topicmail = '';
$modelmail = '';
$objecttmp = new KsefSubmission($db);
$trackid = 'ksefs';
include DOL_DOCUMENT_ROOT.'/core/tpl/massactions_pre.tpl.php';

$submission = new KsefSubmission($db);
$stats = $submission->getStatistics(30);

if (!empty($stats['common_errors'])) {
    print '<div class="info" style="margin-bottom: 15px;">';
    print '<h4>' . $langs->trans("KSEF_CommonErrors") . ' (' . $langs->trans("KSEF_Last30Days") . ')</h4>';
    print '<table class="noborder centpercent"><tr class="liste_titre"><th>' . $langs->trans("KSEF_ErrorCode") . '</th><th>' . $langs->trans("Description") . '</th><th class="right">' . $langs->trans("Count") . '</th></tr>';
    $ksefClient = new KsefClient($db);
    foreach ($stats['common_errors'] as $error) {
        print '<tr class="oddeven"><td><strong>' . $error['code'] . '</strong></td><td>' . $ksefClient->getErrorDescription($error['code']) . '</td><td class="right"><span class="badge badge-warning">' . $error['count'] . '</span></td></tr>';
    }
    print '</table></div>';
}

print '<div class="div-table-responsive"><table class="tagtable nobottomiftotal liste">' . "\n";

// Filter row: Invoice, Customer, KSeF Number, Status, Environment, OfflineMode, Date, Amount, Attempts, Actions, [Checkbox]
print '<tr class="liste_titre_filter">';
if (getDolGlobalString('MAIN_CHECKBOX_LEFT_COLUMN')) {
    print '<td class="liste_titre center maxwidthsearch">' . $form->showFilterButtons('left') . '</td>';
}
print '<td class="liste_titre left"><input class="flat" type="text" name="search_ref" value="' . dol_escape_htmltag($search_ref) . '" size="10"></td>';
print '<td class="liste_titre left"></td>'; // Customer
print '<td class="liste_titre left"><input class="flat" type="text" name="search_ksef_number" value="' . dol_escape_htmltag($search_ksef_number) . '" size="10"></td>';
print '<td class="liste_titre center">' . $form->selectarray('search_status', array('PENDING' => $langs->trans('KSEF_STATUS_PENDING'), 'SUBMITTED' => $langs->trans('KSEF_STATUS_SUBMITTED'), 'ACCEPTED' => $langs->trans('KSEF_STATUS_ACCEPTED'), 'REJECTED' => $langs->trans('KSEF_STATUS_REJECTED'), 'FAILED' => $langs->trans('KSEF_STATUS_FAILED'), 'TIMEOUT' => $langs->trans('KSEF_STATUS_TIMEOUT')), $search_status, 1, 0, 0, '', 0, 0, 0, '', 'maxwidth150') . '</td>';
print '<td class="liste_titre center">' . $form->selectarray('search_environment', array('TEST' => $langs->trans('KSEF_ENV_TEST'), 'DEMO' => $langs->trans('KSEF_ENV_DEMO'), 'PRODUCTION' => $langs->trans('KSEF_ENV_PRODUCTION')), $search_environment, 1, 0, 0, '', 0, 0, 0, '', 'maxwidth100') . '</td>';
print '<td class="liste_titre center"></td>'; // OfflineMode
print '<td class="liste_titre center"><div class="nowrap">' . $form->selectDate($search_date_start, 'search_date_start', 0, 0, 1, '', 1, 0) . '</div></td>';
print '<td class="liste_titre right"></td>'; // Amount
print '<td class="liste_titre center"></td>'; // Attempts
print '<td class="liste_titre center"></td>'; // Actions
if (!getDolGlobalString('MAIN_CHECKBOX_LEFT_COLUMN')) {
    print '<td class="liste_titre center maxwidthsearch">' . $form->showFilterButtons() . '</td>';
}
print '</tr>' . "\n";

print '<tr class="liste_titre">';
if (getDolGlobalString('MAIN_CHECKBOX_LEFT_COLUMN')) print getTitleFieldOfList($selectedfields, 0, $_SERVER["PHP_SELF"], '', '', '', '', $sortfield, $sortorder, 'center maxwidthsearch ') . "\n";
print_liste_field_titre("Invoice", $_SERVER["PHP_SELF"], "f.ref", "", $param, "", $sortfield, $sortorder);
print_liste_field_titre("Customer", $_SERVER["PHP_SELF"], "soc.nom", "", $param, "", $sortfield, $sortorder);
print_liste_field_titre("KSEF_Number", $_SERVER["PHP_SELF"], "s.ksef_number", "", $param, "", $sortfield, $sortorder);
print_liste_field_titre("Status", $_SERVER["PHP_SELF"], "s.status", "", $param, '', $sortfield, $sortorder, 'center ');
print_liste_field_titre("KSEF_ENVIRONMENT", $_SERVER["PHP_SELF"], "s.environment", "", $param, '', $sortfield, $sortorder, 'center ');
print_liste_field_titre("KSEF_OfflineMode", $_SERVER["PHP_SELF"], "s.offline_mode", "", $param, '', $sortfield, $sortorder, 'center ');
print_liste_field_titre("Date", $_SERVER["PHP_SELF"], "s.date_submission", "", $param, '', $sortfield, $sortorder, 'center ');
print_liste_field_titre("Amount", $_SERVER["PHP_SELF"], "f.total_ttc", "", $param, '', $sortfield, $sortorder, 'right ');
print_liste_field_titre("KSEF_Attempts", $_SERVER["PHP_SELF"], "", "", $param, '', $sortfield, $sortorder, 'center ');
print_liste_field_titre("Actions", $_SERVER["PHP_SELF"], "", "", $param, '', '', '', 'center maxwidthsearch');
if (!getDolGlobalString('MAIN_CHECKBOX_LEFT_COLUMN')) print getTitleFieldOfList($selectedfields, 0, $_SERVER["PHP_SELF"], '', '', '', '', $sortfield, $sortorder, 'center maxwidthsearch ') . "\n";
print '</tr>' . "\n";

$i = 0;
while ($i < min($num, $limit)) {
    $obj = $db->fetch_object($resql);
    if (empty($obj)) break;

    print '<tr class="oddeven">';
    if (getDolGlobalString('MAIN_CHECKBOX_LEFT_COLUMN')) {
        print '<td class="nowrap center">';
        if ($massactionbutton || $massaction) print '<input id="cb' . $obj->rowid . '" class="flat checkforselect" type="checkbox" name="toselect[]" value="' . $obj->rowid . '"' . (in_array($obj->rowid, $arrayofselected) ? ' checked="checked"' : '') . '>';
        print '</td>';
    }

    print '<td class="tdoverflowmax150"><a href="' . DOL_URL_ROOT . '/compta/facture/card.php?facid=' . $obj->fk_facture . '">' . img_object($langs->trans("ShowInvoice"), 'bill', 'class="pictofixedwidth"') . $obj->invoice_ref . '</a></td>';
    print '<td class="tdoverflowmax150">' . ($obj->socid > 0 ? '<a href="' . DOL_URL_ROOT . '/societe/card.php?socid=' . $obj->socid . '">' . img_object($langs->trans("ShowCompany"), 'company', 'class="pictofixedwidth"') . $obj->company_name . '</a>' : '') . '</td>';

    print '<td class="tdoverflowmax150">';
    if (!empty($obj->ksef_number)) {
        $verify_url = ksefGetVerificationURL($obj->ksef_number, $obj->invoice_hash, $obj->environment);
        print '<a href="' . $verify_url . '" target="_blank" class="classfortooltip" title="' . $langs->trans("KSEF_VerifyOnKSEFPortal") . '"><span class="badge badge-info">' . $obj->ksef_number . '</span></a>';
    } else print '<span class="opacitymedium">-</span>';
    print '</td>';

    print '<td class="center">' . ksefGetStatusBadge($obj->status);
    if (!empty($obj->error_code) && in_array($obj->status, array('REJECTED', 'FAILED'))) {
        $ksefClient = new KsefClient($db);
        $tooltipText = "Error {$obj->error_code}: " . $ksefClient->getErrorDescription($obj->error_code) . (!empty($obj->error_message) ? "\n\n" . substr($obj->error_message, 0, 200) : '');
        print ' <span class="fa fa-exclamation-triangle classfortooltip" style="color: #d9534f; cursor: help;" title="' . dol_escape_htmltag($tooltipText) . '"></span>';
    } elseif (!empty($obj->error_message) && in_array($obj->status, array('REJECTED', 'FAILED'))) {
        print ' <span class="fa fa-exclamation-triangle classfortooltip" title="' . dol_escape_htmltag(substr($obj->error_message, 0, 200)) . '"></span>';
    }
    print '</td>';

    print '<td class="center">' . ksefGetEnvironmentBadge($obj->environment) . '</td>';
    print '<td class="center">';
    if (!empty($obj->offline_mode)) {
        print ksefGetOfflineModeBadge($obj->offline_mode);
        if (!empty($obj->offline_deadline) && ($obj->status != 'ACCEPTED')) {
            print '<br><small>' . ksefFormatDeadline($obj->offline_deadline) . '</small>';
        }
    } else {
        print '<span class="opacitymedium">-</span>';
    }
    print '</td>';

    print '<td class="center">' . dol_print_date($obj->date_submission, 'dayhour') . '</td>';
    print '<td class="right">' . price($obj->total_ttc) . '</td>';
    print '<td class="center">' . ($obj->total_attempts > 1 ? '<a href="' . DOL_URL_ROOT . '/custom/ksef/tab_ksef.php?id=' . $obj->fk_facture . '" class="classfortooltip" title="' . $langs->trans("KSEF_ViewAllAttemptsOnInvoice") . '"><span class="badge badge-info">' . $obj->total_attempts . '</span></a>' : '<span class="opacitymedium">1</span>') . '</td>';

    print '<td class="center nowraponall">';
    $can_retry = in_array($obj->status, array('FAILED', 'TIMEOUT', 'REJECTED', 'OFFLINE'));
    if ($obj->status == 'PENDING' && !empty($obj->offline_mode)) {$can_retry = true;}
    if ($can_retry && $user->hasRight('ksef', 'write')) {print '<a class="butAction classfortooltip marginleftonly" href="' . $_SERVER["PHP_SELF"] . '?action=retry&id=' . $obj->rowid . '&token=' . newToken() . '" title="' . $langs->trans("KSEF_RetrySubmission") . '"><span class="fa fa-paper-plane paddingrightonly"></span></a>';}
    if (in_array($obj->status, array('SUBMITTED', 'TIMEOUT', 'PENDING')) && !empty($obj->ksef_reference) && $user->hasRight('ksef', 'write')) {print '<a class="butAction classfortooltip marginleftonly" href="' . $_SERVER["PHP_SELF"] . '?action=check_status&id=' . $obj->rowid . '&token=' . newToken() . '" title="' . $langs->trans("KSEF_CheckStatus") . '"><span class="fa fa-sync-alt paddingrightonly"></span></a>';}
    if (in_array($obj->status, array('REJECTED', 'FAILED')) && !empty($obj->offline_mode) && $user->hasRight('ksef', 'write')) {print '<a class="butAction classfortooltip marginleftonly" href="' . DOL_URL_ROOT . '/compta/facture/card.php?facid=' . $obj->fk_facture . '&action=ksef_technical_correction&submission_id=' . $obj->rowid . '&token=' . newToken() . '" title="' . $langs->trans("KSEF_TechnicalCorrection") . '"><span class="fa fa-tools paddingrightonly"></span></a>';}
    if (in_array($obj->status, array('FAILED', 'REJECTED')) && !empty($obj->error_code)) {print '<a class="butAction classfortooltip marginleftonly" href="' . $_SERVER["PHP_SELF"] . '?action=view_error&id=' . $obj->rowid . '&token=' . newToken() . '" title="' . $langs->trans("KSEF_ViewErrorDetails") . '"><span class="fa fa-exclamation-circle paddingrightonly"></span></a>';}
    if (!empty($obj->fa3_xml)) {print '<a class="butAction classfortooltip marginleftonly" href="' . $_SERVER["PHP_SELF"] . '?action=download_xml&id=' . $obj->rowid . '&token=' . newToken() . '" title="' . $langs->trans("KSEF_DownloadFA3XML") . '"><span class="fa fa-file-code paddingrightonly"></span></a>';}
    if ($obj->status == 'ACCEPTED' && !empty($obj->ksef_number)) {print '<a class="butAction classfortooltip marginleftonly" href="' . $_SERVER["PHP_SELF"] . '?action=download_upo&id=' . $obj->rowid . '&token=' . newToken() . '" title="' . $langs->trans("KSEF_DownloadUPO") . '"><span class="fa fa-certificate paddingrightonly"></span></a>';}
    if ($user->hasRight('facture', 'supprimer')) {print '<a class="butActionDelete classfortooltip marginleftonly" href="' . $_SERVER["PHP_SELF"] . '?action=delete&id=' . $obj->rowid . '&token=' . newToken() . '" title="' . $langs->trans("Delete") . '"><span class="fa fa-trash paddingrightonly"></span></a>';}
    print '</td>';

    if (!getDolGlobalString('MAIN_CHECKBOX_LEFT_COLUMN')) {
        print '<td class="nowrap center">';
        if ($massactionbutton || $massaction) print '<input id="cb' . $obj->rowid . '" class="flat checkforselect" type="checkbox" name="toselect[]" value="' . $obj->rowid . '"' . (in_array($obj->rowid, $arrayofselected) ? ' checked="checked"' : '') . '>';
        print '</td>';
    }

    print '</tr>';
    $i++;
}

if ($num == 0) print '<tr><td colspan="11"><span class="opacitymedium">' . $langs->trans("NoRecordFound") . '</span></td></tr>';

$db->free($resql);
print '</table></div></form>';

llxFooter();
$db->close();