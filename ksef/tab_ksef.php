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
 * \file    ksef/tab_ksef.php
 * \ingroup ksef
 * \brief   Tab for KSEF submission history on invoice page
 */

// CSRF pcheck
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
if (!$res && file_exists("../../main.inc.php")) $res = @include("../../main.inc.php");
if (!$res && file_exists("../../../main.inc.php")) $res = @include("../../../main.inc.php");
if (!$res) die("Main.inc.php not found");

require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/invoice.lib.php';
dol_include_once('/ksef/class/ksef_submission.class.php');
dol_include_once('/ksef/class/ksef_client.class.php');
dol_include_once('/ksef/lib/ksef.lib.php');

$id = GETPOST('id', 'int');
$action = GETPOST('action', 'aZ09');

if (!$user->rights->facture->lire) accessforbidden();

$object = new Facture($db);
if ($object->fetch($id) <= 0) accessforbidden();
$object->fetch_optionals();

$langs->load("bills");
$langs->load("ksef@ksef");

if ($action == 'download_xml' && GETPOST('sub_id', 'int')) {
    $sub_id = GETPOST('sub_id', 'int');
    $submission = new KsefSubmission($db);
    if ($submission->fetch($sub_id) > 0 && !empty($submission->fa3_xml)) {
        $filename = 'FA3_' . ($submission->ksef_number ?: 'invoice_' . $submission->fk_facture) . '.xml';
        header('Content-Type: application/xml; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo $submission->fa3_xml;
        exit;
    }
}

if ($action == 'download_upo' && GETPOST('sub_id', 'int')) {
    $sub_id = GETPOST('sub_id', 'int');
    $submission = new KsefSubmission($db);
    if ($submission->fetch($sub_id) > 0) {
        // If UPO not cached, try fetching from KSeF API
        if (empty($submission->upo_xml) && !empty($submission->ksef_number) && $submission->status == 'ACCEPTED') {
            try {
                dol_include_once('/ksef/class/ksef_service.class.php');
                $ksef = new KsefService($db);
                $ksef->downloadUPO($submission->fk_facture, $user);
                $submission->fetch($sub_id);
            } catch (Exception $e) {
                dol_syslog("KsefTab: UPO fetch from API failed: " . $e->getMessage(), LOG_WARNING);
            }
        }

        if (!empty($submission->upo_xml)) {
            header('Content-Type: application/xml; charset=utf-8');
            header('Content-Disposition: attachment; filename="UPO_' . $submission->ksef_number . '.xml"');
            echo $submission->upo_xml;
            exit;
        }
    }
}


$title = $langs->trans('Invoice') . " - " . $object->ref . " - " . $langs->trans("KSEF_Tab");
llxHeader('', $title);

$head = facture_prepare_head($object);
print dol_get_fiche_head($head, 'ksef', $langs->trans("Invoice"), -1, 'bill');

$linkback = '<a href="' . DOL_URL_ROOT . '/compta/facture/list.php?restore_lastsearch_values=1">' . $langs->trans("BackToList") . '</a>';
$morehtmlref = '<div class="refidno">' . $langs->trans("Ref") . ' : ' . $object->ref . '</div>';

dol_banner_tab($object, 'ref', $linkback, 1, 'ref', 'ref', $morehtmlref);

print '<div class="fichecenter">';
print '<div class="underbanner clearboth"></div>';

print '<table class="centpercent notopnoleftnoright table-fiche-title">';
print '<tbody><tr class="toptitle">';
print '<td class="nobordernopadding valignmiddle col-title">';
print '<div class="titre inline-block">' . $langs->trans("KSEF_SubmissionHistory") . '</div>';
print '</td></tr></tbody></table>';

$sql = "SELECT rowid, ksef_reference, ksef_number, status, environment, date_submission, date_acceptance, error_code, error_message, error_details, retry_count, fa3_xml, upo_xml";
$sql .= " FROM " . MAIN_DB_PREFIX . "ksef_submissions";
$sql .= " WHERE fk_facture = " . (int)$object->id;
$sql .= " ORDER BY date_submission DESC";

$resql = $db->query($sql);
if ($resql) {
    $num = $db->num_rows($resql);

    print '<div class="div-table-responsive">';
    print '<table class="tagtable liste">';
    print '<tr class="liste_titre">';
    print '<th>' . $langs->trans("Date") . '</th>';
    print '<th>' . $langs->trans("Status") . '</th>';
    print '<th>' . $langs->trans("KSEF_ENVIRONMENT") . '</th>';
    print '<th>' . $langs->trans("KSEF_Number") . '</th>';
    print '<th class="center">#</th>';
    print '<th>' . $langs->trans("Error") . '</th>';
    print '<th class="center">' . $langs->trans("Action") . '</th>';
    print '</tr>';

    if ($num > 0) {
        $ksefClient = new KsefClient($db);

        while ($obj = $db->fetch_object($resql)) {
            print '<tr class="oddeven">';

            print '<td class="nowraponall">' . dol_print_date($obj->date_submission, 'dayhour') . '</td>';
            print '<td>' . ksefGetStatusBadge($obj->status) . '</td>';
            print '<td>' . ksefGetEnvironmentBadge($obj->environment) . '</td>';
            print '<td>' . ($obj->ksef_number ? $obj->ksef_number : '-') . '</td>';
            print '<td class="center">' . (($obj->retry_count ?? 0) + 1) . '</td>';

            print '<td>';
            if ($obj->error_code) {
                print '<strong>' . $obj->error_code . '</strong>: ' . dol_trunc($ksefClient->getErrorDescription($obj->error_code), 60);
            } elseif ($obj->error_message) {
                print dol_trunc($obj->error_message, 80);
            }

            if (!empty($obj->error_details)) {
                $errorDetails = json_decode($obj->error_details, true);
                if ($errorDetails) {
                    if (isset($errorDetails['code']) && $errorDetails['code'] == 440) {
                        $originalKsef = $errorDetails['original_ksef_number'] ?? null;
                        print '<br><span class="badge badge-warning">' . $langs->trans("KSEF_DuplicateInvoiceNumber") . '</span>';
                        if ($originalKsef) {
                            print '<br><small class="opacitymedium">' . $langs->trans("KSEF_InvoiceNumberUsedBy") . ': <strong>' . $originalKsef . '</strong></small>';
                        }
                    }
                    elseif (isset($errorDetails['raw_response']['status']['code']) && $errorDetails['raw_response']['status']['code'] == 440) {
                        $originalKsef = $errorDetails['raw_response']['status']['extensions']['originalKsefNumber'] ?? null;
                        print '<br><span class="badge badge-warning">' . $langs->trans("KSEF_DuplicateInvoiceNumber") . '</span>';
                        if ($originalKsef) {
                            print '<br><small class="opacitymedium">' . $langs->trans("KSEF_InvoiceNumberUsedBy") . ': <strong>' . $originalKsef . '</strong></small>';
                        }
                    }
                    elseif (!empty($errorDetails['raw_response']['status']['description'])) {
                        print '<br><small class="opacitymedium">' . dol_trunc($errorDetails['raw_response']['status']['description'], 80) . '</small>';
                    }
                    elseif (!empty($errorDetails['details']) && is_array($errorDetails['details'])) {
                        print '<br><small class="opacitymedium">' . dol_trunc(implode('; ', $errorDetails['details']), 80) . '</small>';
                    }
                }
            }
            print '</td>';

            print '<td class="center nowraponall">';
            if (!empty($obj->fa3_xml)) {
                print '<a href="' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=download_xml&sub_id=' . $obj->rowid . '&token=' . newToken() . '" class="butAction butActionSmall" title="' . $langs->trans("KSEF_DownloadFA3XML") . '">XML</a> ';
            }
            if ($obj->status == 'ACCEPTED' && !empty($obj->ksef_number)) {
                print '<a href="' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=download_upo&sub_id=' . $obj->rowid . '&token=' . newToken() . '" class="butAction butActionSmall" title="' . $langs->trans("KSEF_DownloadUPO") . '">UPO</a>';
            }
            print '</td>';
            print '</tr>';
        }
    } else {
        print '<tr><td colspan="7" class="opacitymedium">' . $langs->trans("NoRecordFound") . '</td></tr>';
    }
    print '</table></div>';
    $db->free($resql);
}

print '</div>';
print dol_get_fiche_end();

llxFooter();
$db->close();