<?php
/* Copyright (C) 2026 InPoint Automation Sp z o.o.
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
 * \file    ksef/incoming_list.php
 * \ingroup ksef
 * \brief   List and sync incoming invoices from KSeF
 */

// CSRF check
if (!defined('CSRFCHECK_WITH_TOKEN')) {
    define('CSRFCHECK_WITH_TOKEN', '1');
}

// Prevent token renewal for actions that exit without page reload
$action_raw = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : '');
if (in_array($action_raw, array('check_fetch_status', 'init_fetch', 'process_incoming', 'download_xml', 'download_pdf', 'batch_import_preview', 'batch_import_execute', 'gus_supplier_preview'))) {
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
require_once DOL_DOCUMENT_ROOT . '/fourn/class/fournisseur.facture.class.php';
dol_include_once('/ksef/class/ksef_incoming.class.php');
dol_include_once('/ksef/class/ksef_sync_state.class.php');
dol_include_once('/ksef/class/ksef_service.class.php');
dol_include_once('/ksef/class/ksef_latarnia.class.php');
dol_include_once('/ksef/lib/ksef.lib.php');

$langs->loadLangs(array("ksef@ksef", "bills"));

if (!$user->hasRight('ksef', 'read')) accessforbidden();

// Parameters
$action = GETPOST('action', 'aZ09');
$massaction = GETPOST('massaction', 'alpha');
$confirm = GETPOST('confirm', 'alpha');
$toselect = GETPOST('toselect', 'array');
$contextpage = GETPOST('contextpage', 'aZ09') ? GETPOST('contextpage', 'aZ09') : 'ksef_incoming_list';
$optioncss = GETPOST('optioncss', 'alpha');
$show_errors = GETPOST('show_errors', 'int');

// Search filters
$search_seller_nip = GETPOST('search_seller_nip', 'alpha');
$search_seller_name = GETPOST('search_seller_name', 'alpha');
$search_invoice_number = GETPOST('search_invoice_number', 'alpha');
$search_ksef_number = GETPOST('search_ksef_number', 'alpha');
$search_import_status = GETPOST('search_import_status', 'alpha');

// Pagination
$limit = GETPOST('limit', 'int') ? GETPOST('limit', 'int') : $conf->liste_limit;
$sortfield = GETPOST('sortfield', 'aZ09comma');
$sortorder = GETPOST('sortorder', 'aZ09comma');
$page = GETPOSTISSET('pageplusone') ? (GETPOST('pageplusone') - 1) : GETPOST("page", 'int');
if (empty($page) || $page < 0 || GETPOST('button_search', 'alpha') || GETPOST('button_removefilter', 'alpha')) $page = 0;
$offset = $limit * $page;

if (!$sortfield) {
    $sortfield = "i.invoice_date";
    $sortorder = "DESC";
}

$form = new Form($db);
$incoming = new KsefIncoming($db);
$ksef = new KSEFService($db);

$hookmanager->initHooks(array('ksef_incoming_list'));

/*
 * AJAX - Check fetch status
 */

if ($action == 'check_fetch_status') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache');

    if (!$user->hasRight('ksef', 'read')) {
        echo json_encode(array('status' => 'ERROR', 'error' => 'Access denied'));
        exit;
    }

    session_write_close();
    echo json_encode($ksef->checkIncomingFetchStatus($user));
    exit;
}

if ($action == 'process_incoming') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache');

    if (!$user->hasRight('ksef', 'write')) {
        echo json_encode(array('status' => 'ERROR', 'error' => 'Access denied'));
        exit;
    }

    session_write_close();
    ignore_user_abort(true);
    set_time_limit(0);

    echo json_encode(array('status' => 'STARTED'));

    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        if (ob_get_level()) ob_end_flush();
        flush();
    }

    $ksef->processIncomingDownload($user);
    exit;
}

/*
 * AJAX - Initiate fetch
 */

if ($action == 'init_fetch') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache');

    if (!$user->hasRight('ksef', 'write')) {
        echo json_encode(array('status' => 'ERROR', 'error' => 'Access denied'));
        exit;
    }

    session_write_close();
    $result = $ksef->initIncomingFetch($user);
    if ($result === false) {
        echo json_encode(array('status' => 'ERROR', 'error' => $ksef->error));
    } else {
        echo json_encode($result);
    }
    exit;
}

if ($action == 'batch_import_preview') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache');

    if (!$user->hasRight('ksef', 'write')) {
        echo json_encode(array('status' => 'ERROR', 'error' => 'Access denied'));
        exit;
    }

    $ids = GETPOST('ids', 'array');
    if (empty($ids)) {
        echo json_encode(array('status' => 'ERROR', 'error' => 'No invoices selected'));
        exit;
    }

    session_write_close();
    require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';

    $previews = array();
    $supplierCache = array(); // NIP => socid

    foreach ($ids as $invoiceId) {
        $record = new KsefIncoming($db);
        if ($record->fetch((int)$invoiceId) <= 0) continue;
        if ($record->import_status != 'NEW' && $record->import_status != 'ERROR') continue;

        $preview = array(
            'id' => $record->id,
            'invoice_number' => $record->invoice_number,
            'seller_name' => $record->seller_name,
            'seller_nip' => $record->seller_nip,
            'seller_vat_id' => $record->seller_vat_id,
            'invoice_date' => dol_print_date($record->invoice_date, 'day'),
            'total_gross' => price($record->total_gross, 0, $langs, 1, -1, -1, $record->currency),
            'currency' => $record->currency,
            'invoice_type' => $record->invoice_type,
        );

        $nip = preg_replace('/[^0-9]/', '', $record->seller_nip);
        $cacheKey = ($record->seller_country ?: 'PL') . '_' . $nip;
        if (isset($supplierCache[$cacheKey])) {
            $preview['socid'] = $supplierCache[$cacheKey]['socid'];
            $preview['supplier_name'] = $supplierCache[$cacheKey]['name'];
            $preview['supplier_matched'] = $supplierCache[$cacheKey]['matched'];
        } else {
            $socid = $record->findMatchingThirdParty();
            if ($socid > 0) {
                $soc = new Societe($db);
                $soc->fetch($socid);
                $preview['socid'] = $socid;
                $preview['supplier_name'] = $soc->name;
                $preview['supplier_matched'] = true;
                $supplierCache[$cacheKey] = array('socid' => $socid, 'name' => $soc->name, 'matched' => true);
            } else {
                $preview['socid'] = 0;
                $preview['supplier_name'] = $record->seller_name;
                $preview['supplier_matched'] = false;
                $supplierCache[$cacheKey] = array('socid' => 0, 'name' => $record->seller_name, 'matched' => false);
            }
        }

        $lines = $record->getLineItems();
        $autoMatches = $record->autoMatchLineProducts($lines);
        $totalLines = count($lines);
        $matchedLines = count($autoMatches);
        $preview['total_lines'] = $totalLines;
        $preview['matched_lines'] = $matchedLines;
        $preview['all_products_matched'] = ($matchedLines == $totalLines);

        $lineDetails = array();
        foreach ($lines as $line) {
            $lineNum = $line['line_num'] ?? 0;
            $lineDetail = array(
                'line_num' => $lineNum,
                'description' => $line['description'] ?? '',
                'indeks' => $line['indeks'] ?? '',
                'gtin' => $line['gtin'] ?? '',
                'qty' => $line['quantity'] ?? 0,
                'unit' => $line['unit'] ?? '',
                'unit_price_net' => $line['unit_price_net'] ?? 0,
                'vat_rate' => $line['vat_rate'] ?? '',
                'net_amount' => $line['net_amount'] ?? 0,
                'product_matched' => isset($autoMatches[$lineNum]),
            );
            if (isset($autoMatches[$lineNum])) {
                $lineDetail['match_method'] = $autoMatches[$lineNum]['match_method'];
                $lineDetail['product_ref'] = $autoMatches[$lineNum]['product_ref'];
                $lineDetail['product_label'] = $autoMatches[$lineNum]['product_label'];
            }
            $lineDetails[] = $lineDetail;
        }
        $preview['lines'] = $lineDetails;

        $preview['correction_blocked'] = false;
        $preview['correction_issues'] = array();
        if (KsefIncoming::isCorrectionType($record->invoice_type)) {
            $resolvedCorrections = $record->resolveCorrectedInvoices();
            $correctionSourceFound = false;
            if (empty($resolvedCorrections)) {
                $preview['correction_issues'][] = $langs->trans('KSEF_BatchCorrectionNoReference');
                $preview['correction_blocked'] = true;
            }
            foreach ($resolvedCorrections as $rc) {
                $isSelfReference = (!empty($rc['ksef_number']) && $rc['ksef_number'] === $record->ksef_number);
                if ($isSelfReference) {
                    $preview['correction_issues'][] = $langs->trans('KSEF_BatchCorrectionSelfReference');
                    $preview['correction_blocked'] = true;
                } elseif ($rc['incoming'] && $rc['supplier_invoice']) {
                    $correctionSourceFound = true;
                } elseif ($rc['incoming'] && $rc['incoming']->fk_facture_fourn <= 0) {
                    $preview['correction_issues'][] = $langs->trans('KSEF_BatchCorrectionOriginalNotImported');
                    $preview['correction_blocked'] = true;
                } elseif (!empty($rc['ksef_number']) && !$rc['incoming']) {
                    $preview['correction_issues'][] = $langs->trans('KSEF_BatchCorrectionOriginalNotInSystem');
                    $preview['correction_blocked'] = true;
                }
            }
            if (!$correctionSourceFound && empty($preview['correction_issues'])) {
                $preview['correction_issues'][] = $langs->trans('KSEF_BatchCorrectionOriginalNotImported');
                $preview['correction_blocked'] = true;
            }
            if ($preview['correction_blocked']) {
                $preview['correction_issues'][] = $langs->trans('KSEF_BatchCorrectionUseIndividualImport');
            }
        }

        $previews[] = $preview;
    }

    echo json_encode(array('status' => 'OK', 'invoices' => $previews));
    exit;
}


/*
 * AJAX - Batch import execute
 */

if ($action == 'gus_supplier_preview') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache');

    if (!$user->hasRight('ksef', 'write')) {
        echo json_encode(array('status' => 'ERROR', 'error' => 'Access denied'));
        exit;
    }
    if (empty(getDolGlobalString('KSEF_GUS_ENABLED'))) {
        echo json_encode(array('status' => 'OK', 'overrides' => array()));
        exit;
    }

    $rawInput = file_get_contents('php://input');
    $payload = json_decode($rawInput, true);
    $ids = (is_array($payload) && !empty($payload['ids']) && is_array($payload['ids'])) ? $payload['ids'] : array();
    if (empty($ids)) {
        echo json_encode(array('status' => 'OK', 'overrides' => array()));
        exit;
    }

    dol_include_once('/ksef/class/ksef_gus_client.class.php');

    @set_time_limit(0);
    $batchMax = getDolGlobalInt('KSEF_GUS_BATCH_MAX', 25);
    if ($batchMax < 1) {
        $batchMax = 25;
    }
    $batchDelayMs = getDolGlobalInt('KSEF_GUS_BATCH_DELAY_MS', 600);

    $gusClient = new KsefGusClient($db, getDolGlobalString('KSEF_GUS_ENV', 'TEST'));
    $supplierData = array();
    $overrides = array();
    $seenNip = array();
    $looked = 0;
    $truncated = 0;

    foreach ($ids as $rawId) {
        $invoiceId = (int) $rawId;
        if ($invoiceId <= 0) {
            continue;
        }
        $record = new KsefIncoming($db);
        if ($record->fetch($invoiceId) <= 0) {
            continue;
        }
        if (($record->seller_country ?: 'PL') !== 'PL') {
            continue;
        }
        $nip = ksefCleanNIP($record->seller_nip);
        if (!ksefValidateNIP($nip) || isset($seenNip[$nip])) {
            continue;
        }
        $seenNip[$nip] = true;

        if ($looked >= $batchMax) {
            $truncated++;
            continue;
        }
        if ($looked > 0 && $batchDelayMs > 0) {
            usleep($batchDelayMs * 1000);
        }
        $looked++;

        $data = $gusClient->lookupByNip($nip);
        if ($data === false) {
            continue;
        }
        $supplierData[$nip] = $data;

        $changes = array();
        $invName = trim((string) $record->seller_name);
        if (!empty($data['name']) && $data['name'] !== $invName) {
            $changes[] = array('label' => $langs->trans('Name'), 'from' => $invName, 'to' => $data['name']);
        }
        $gusAddr = trim(trim(($data['address'] ?? '') . ', ' . ($data['zip'] ?? '') . ' ' . ($data['town'] ?? '')), ',');
        $invAddr = trim((string) $record->seller_address);
        if ($gusAddr !== '' && $gusAddr !== $invAddr) {
            $changes[] = array('label' => $langs->trans('Address'), 'from' => $invAddr, 'to' => $gusAddr);
        }
        if (!empty($changes)) {
            $overrides[] = array('nip' => $nip, 'name' => ($invName !== '' ? $invName : $nip), 'changes' => $changes);
        }
    }
    $gusClient->logout();

    ksefSessionWrite('KSEF_GUS_SUPPLIER_DATA', $supplierData);

    echo json_encode(array('status' => 'OK', 'overrides' => $overrides, 'truncated' => $truncated));
    exit;
}

if ($action == 'batch_import_execute') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache');

    if (!$user->hasRight('ksef', 'write')) {
        echo json_encode(array('status' => 'ERROR', 'error' => 'Access denied'));
        exit;
    }

    $rawInput = file_get_contents('php://input');
    $payload = json_decode($rawInput, true);
    if (empty($payload) || !is_array($payload)) {
        echo json_encode(array('status' => 'ERROR', 'error' => 'Invalid request data'));
        exit;
    }

    $decisions = $payload['decisions'] ?? array();
    if (empty($decisions) || !is_array($decisions)) {
        echo json_encode(array('status' => 'ERROR', 'error' => 'Invalid request data'));
        exit;
    }

    require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
    dolibarr_set_const($db, 'KSEF_BATCH_AUTO_CREATE_SUPPLIERS', !empty($payload['auto_create_suppliers']) ? '1' : '0', 'chaine', 0, '', $conf->entity);
    dolibarr_set_const($db, 'KSEF_BATCH_AUTO_CREATE_PRODUCTS', !empty($payload['auto_create_products']) ? '1' : '0', 'chaine', 0, '', $conf->entity);

    $gusFetchSuppliers = !empty($payload['fetch_suppliers']) && !empty(getDolGlobalString('KSEF_GUS_ENABLED'));
    dolibarr_set_const($db, 'KSEF_GUS_FETCH_SUPPLIERS', $gusFetchSuppliers ? '1' : '0', 'chaine', 0, '', $conf->entity);
    $gusSupplierData = $gusFetchSuppliers ? ksefSessionRead('KSEF_GUS_SUPPLIER_DATA', array(), false) : array();
    if (!is_array($gusSupplierData)) {
        $gusSupplierData = array();
    }

    session_write_close();
    require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
    require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';

    $results = array();
    $createdSuppliers = array(); // NIP => socid - reuse across invoices from same seller

    foreach ($decisions as $decision) {
        $invoiceId = (int)($decision['id'] ?? 0);
        if ($invoiceId <= 0) continue;

        $record = new KsefIncoming($db);
        if ($record->fetch($invoiceId) <= 0) {
            $results[] = array('id' => $invoiceId, 'status' => 'ERROR', 'error' => 'Record not found');
            continue;
        }
        if ($record->import_status != 'NEW' && $record->import_status != 'ERROR') {
            $results[] = array('id' => $invoiceId, 'status' => 'SKIPPED', 'error' => 'Already processed');
            continue;
        }

        if (KsefIncoming::isCorrectionType($record->invoice_type)) {
            $resolvedCorrections = $record->resolveCorrectedInvoices();
            $correctionSourceFound = false;
            $correctionBlocked = false;
            $blockReason = '';
            foreach ($resolvedCorrections as $rc) {
                $isSelfReference = (!empty($rc['ksef_number']) && $rc['ksef_number'] === $record->ksef_number);
                if ($isSelfReference) {
                    $correctionBlocked = true;
                    $blockReason = $langs->trans('KSEF_BatchCorrectionSelfReference');
                    break;
                } elseif ($rc['incoming'] && $rc['supplier_invoice']) {
                    $correctionSourceFound = true;
                } elseif ($rc['incoming'] && $rc['incoming']->fk_facture_fourn <= 0) {
                    $correctionBlocked = true;
                    $blockReason = $langs->trans('KSEF_BatchCorrectionOriginalNotImported');
                    break;
                } elseif (!empty($rc['ksef_number']) && !$rc['incoming']) {
                    $correctionBlocked = true;
                    $blockReason = $langs->trans('KSEF_BatchCorrectionOriginalNotInSystem');
                    break;
                }
            }
            if (!$correctionSourceFound && !$correctionBlocked) {
                $correctionBlocked = true;
                $blockReason = $langs->trans('KSEF_BatchCorrectionOriginalNotImported');
            }
            if ($correctionBlocked) {
                $results[] = array('id' => $invoiceId, 'status' => 'ERROR', 'error' => $blockReason, 'invoice_number' => $record->invoice_number);
                continue;
            }
        }

        $socid = (int)($decision['socid'] ?? 0);
        $autoCreateSupplier = !empty($decision['auto_create_supplier']);

        if ($socid <= 0 && $autoCreateSupplier) {
            $nip = preg_replace('/[^0-9]/', '', $record->seller_nip);
            $supplierCacheKey = ($record->seller_country ?: 'PL') . '_' . $nip;
            if (isset($createdSuppliers[$supplierCacheKey])) {
                $socid = $createdSuppliers[$supplierCacheKey];
            } else {
                $gusForThis = (!empty($gusSupplierData[$nip])) ? $gusSupplierData[$nip] : null;
                $socid = $record->autoCreateSupplier($user, $gusForThis);
                if ($socid > 0) {
                    $createdSuppliers[$supplierCacheKey] = $socid;
                } else {
                    $results[] = array('id' => $invoiceId, 'status' => 'ERROR', 'error' => 'Failed to create supplier: ' . $record->error);
                    continue;
                }
            }
        }

        if ($socid <= 0) {
            $results[] = array('id' => $invoiceId, 'status' => 'ERROR', 'error' => $langs->trans('KSEF_ImportErrorNoSupplier'));
            continue;
        }

        $lines = $record->getLineItems();
        $autoMatches = $record->autoMatchLineProducts($lines);
        $lineProductMap = array();
        foreach ($autoMatches as $ln => $match) {
            $lineProductMap[$ln] = $match['product_id'];
        }
        $autoCreateProducts = !empty($decision['auto_create_products']);

        if ($autoCreateProducts) {
            foreach ($lines as $line) {
                $lineNum = $line['line_num'] ?? 0;
                if (!isset($lineProductMap[$lineNum])) {
                    $productId = $record->autoCreateProduct($user, $line);
                    if ($productId > 0) {
                        $lineProductMap[$lineNum] = $productId;
                    }
                }
            }
        }

        $result = $record->importToDolibarr($user, $socid, $lineProductMap);
        if ($result > 0) {
            $results[] = array('id' => $invoiceId, 'status' => 'OK', 'facture_id' => $result, 'invoice_number' => $record->invoice_number);
        } else {
            $results[] = array('id' => $invoiceId, 'status' => 'ERROR', 'error' => $record->error, 'invoice_number' => $record->invoice_number);
        }
    }

    $successCount = 0;
    $errorCount = 0;
    foreach ($results as $r) {
        if ($r['status'] == 'OK') $successCount++;
        elseif ($r['status'] == 'ERROR') $errorCount++;
    }

    echo json_encode(array('status' => 'OK', 'results' => $results, 'success_count' => $successCount, 'error_count' => $errorCount));
    exit;
}


/*
 * Actions
 */

$parameters = array();
$reshook = $hookmanager->executeHooks('doActions', $parameters, $incoming, $action);
if ($reshook < 0) setEventMessages($hookmanager->error, $hookmanager->errors, 'errors');

if (empty($reshook)) {
    include DOL_DOCUMENT_ROOT . '/core/actions_changeselectedfields.inc.php';

    // Reset filters
    if (GETPOST('button_removefilter_x', 'alpha') || GETPOST('button_removefilter.x', 'alpha') || GETPOST('button_removefilter', 'alpha')) {
        $search_seller_nip = '';
        $search_seller_name = '';
        $search_invoice_number = '';
        $search_ksef_number = '';
        $search_import_status = '';
        $toselect = array();
    }

    // Initiate async sync from KSeF
    if ($action == 'sync' && $user->hasRight('ksef', 'write')) {
        $result = $ksef->initIncomingFetch($user);

        if ($result === false) {
            setEventMessages($ksef->error, $ksef->errors, 'errors');
        } elseif ($result['status'] === 'INITIATED' || $result['status'] === 'ALREADY_PROCESSING') {
            // JS should handle this, but fallback for non-JS browsers
            header('Location: ' . $_SERVER["PHP_SELF"] . '?polling=1');
            exit;
        }
    }

    // Reset stuck fetch
    if ($action == 'reset_fetch' && $user->hasRight('ksef', 'write')) {
        if ($ksef->resetIncomingFetch()) {
            setEventMessages($langs->trans('KSEF_FetchReset'), null, 'mesgs');
        } else {
            setEventMessages($ksef->error, null, 'errors');
        }
        header('Location: ' . $_SERVER["PHP_SELF"]);
        exit;
    }

    // Reset sync state (admin)
    if ($action == 'confirm_reset' && $confirm == 'yes' && $user->admin) {
        if ($ksef->resetIncomingSyncState($user)) {
            setEventMessages($langs->trans('KSEF_SyncStateReset'), null, 'mesgs');
        } else {
            setEventMessages($langs->trans('Error'), null, 'errors');
        }
    }

    // Mass delete
    if (!empty($massaction) && !empty($toselect)) {
        if ($massaction == 'delete' && GETPOST('confirmmassaction', 'alpha') && $user->hasRight('ksef', 'write')) {
            $num_deleted = 0;
            $num_errors = 0;
            $db->begin();
            foreach ($toselect as $toselectid) {
                $record = new KsefIncoming($db);
                if ($record->fetch($toselectid) > 0) {
                    if ($record->delete($user) > 0) {
                        $num_deleted++;
                    } else {
                        $num_errors++;
                    }
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

    // Clear all
    if ($action == 'confirm_clearall' && $confirm == 'yes' && $user->admin) {
        $environment = getDolGlobalString('KSEF_ENVIRONMENT', 'TEST');
        $deleted = $incoming->deleteAll($user, $environment);
        if ($deleted >= 0) {
            setEventMessages($langs->trans("KSEF_ClearedRecords", $deleted, $environment), null, 'mesgs');
        } else {
            setEventMessages($incoming->error, null, 'errors');
        }
    }

    // Download XML
    if ($action == 'download_xml' && GETPOST('id', 'int')) {
        $id = GETPOST('id', 'int');
        $record = new KsefIncoming($db);
        if ($record->fetch($id) > 0 && !empty($record->fa3_xml)) {
            $filename = 'FA3_' . ($record->ksef_number ?: 'incoming_' . $id) . '.xml';
            header('Content-Type: application/xml; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . strlen($record->fa3_xml));
            echo $record->fa3_xml;
            exit;
        }
        setEventMessages($langs->trans('KSEF_XmlNotAvailable'), null, 'warnings');
    }

    // Download PDF
    if ($action == 'download_pdf' && GETPOST('id', 'int')) {
        $id = GETPOST('id', 'int');
        $record = new KsefIncoming($db);
        if ($record->fetch($id) > 0 && !empty($record->fa3_xml)) {
            $pdfContent = $record->generatePdfVisualization();
            if ($pdfContent) {
                $filename = 'Invoice_' . ($record->invoice_number ?: $id) . '.pdf';
                header('Content-Type: application/pdf');
                header('Content-Disposition: attachment; filename="' . $filename . '"');
                header('Content-Length: ' . strlen($pdfContent));
                echo $pdfContent;
                exit;
            }
        }
        setEventMessages($langs->trans('KSEF_PdfGenerationFailed'), null, 'warnings');
    }
}


/*
 * View
 */

$title = $langs->trans('KSEF_IncomingInvoices');
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-ksef page-incoming-list');

print load_fiche_titre($title, '', 'ksef@ksef');

// Confirmation dialogs
if ($action == 'clearall') {
    $environment = getDolGlobalString('KSEF_ENVIRONMENT', 'TEST');
    print $form->formconfirm($_SERVER["PHP_SELF"], $langs->trans('KSEF_ClearAll'), $langs->trans('KSEF_ConfirmClearAll', $environment), 'confirm_clearall', '', 0, 1);
}

if ($action == 'reset') {
    print $form->formconfirm($_SERVER["PHP_SELF"], $langs->trans('KSEF_ResetSyncState'), $langs->trans('KSEF_ConfirmResetSync'), 'confirm_reset', '', 0, 1);
}

// Get state and stats
$environment = getDolGlobalString('KSEF_ENVIRONMENT', 'TEST');
$syncState = $ksef->getIncomingSyncState();
$syncState->getDisplayValues(); // Populate compatibility fields
$stats = $incoming->getStatistics(30);
$stats_all = $incoming->getStatistics(99999);

$isPolling = GETPOST('polling', 'int') || $syncState->isFetchInProgress();
$currentFetchPhase = $syncState->fetch_status;
$showFailedState = ($syncState->fetch_status === KsefSyncState::FETCH_STATUS_FAILED || $syncState->fetch_status === KsefSyncState::FETCH_STATUS_TIMEOUT);

$overlayStyle = ($isPolling || $showFailedState) ? '' : ' style="display:none;"';
$overlayClass = $showFailedState ? 'error' : 'info';

print '<div id="ksef-fetch-overlay" class="' . $overlayClass . '"' . $overlayStyle . ' style="padding: 15px; margin-bottom: 15px; position: relative;' . ($isPolling || $showFailedState ? '' : ' display:none;') . '">';

if ($showFailedState && !$isPolling) {
    // Show failed/timeout state
    print '<span class="fa fa-exclamation-triangle" style="color: #dc3545; margin-right: 10px;"></span>';
    print '<strong style="color: #dc3545;">' . $langs->trans('KSEF_FetchFailed') . '</strong>';
    if (!empty($syncState->fetch_error)) {
        print '<span class="opacitymedium" style="margin-left: 10px;">' . dol_escape_htmltag($syncState->fetch_error) . '</span>';
    }
    print '<a href="' . $_SERVER["PHP_SELF"] . '?action=reset_fetch&token=' . newToken() . '" class="butAction" style="margin-left: 15px;">';
    print $langs->trans('KSEF_ResetAndRetry');
    print '</a>';
} else {
    // Default/processing state (will be populated by JS)
    $elapsed = $syncState->isFetchInProgress() ? (dol_now() - $syncState->fetch_started) : 0;
    print '<span class="fa fa-spinner fa-spin" style="margin-right: 10px;"></span>';
    print '<strong>' . $langs->trans('KSEF_FetchInProgress') . '</strong>';
    print '<span id="ksef-elapsed" style="margin-left: 15px;" class="opacitymedium">';
    print $langs->trans('KSEF_ElapsedTime') . ': <span id="elapsed-val">' . gmdate("i:s", $elapsed) . '</span>';
    print '</span>';
    print '<a href="' . $_SERVER["PHP_SELF"] . '?action=reset_fetch&token=' . newToken() . '" class="butAction" style="position: absolute; right: 15px; top: 12px;" onclick="return confirm(\'' . dol_escape_js($langs->trans('KSEF_ConfirmCancelFetch')) . '\');">';
    print '<span class="fa fa-times"></span> ' . $langs->trans('Cancel');
    print '</a>';
}

print '</div>';

// Main polling/sync
print '<script>
(function() {
    var overlayEl = document.getElementById("ksef-fetch-overlay");
    var elapsedEl = document.getElementById("elapsed-val");
    var elapsedTimer = null;
    var startTime = ' . ($syncState->isFetchInProgress() ? (int)$syncState->fetch_started : 'null') . ';
    var isPolling = ' . ($isPolling ? 'true' : 'false') . ';
    var token = "' . newToken() . '";
    var ajaxToken = "' . currentToken() . '";
    var baseUrl = "' . $_SERVER["PHP_SELF"] . '";

    var langFetchInProgress = "' . dol_escape_js($langs->trans('KSEF_FetchInProgress')) . '";
    var langElapsedTime = "' . dol_escape_js($langs->trans('KSEF_ElapsedTime')) . '";
    var langFetchCompleted = "' . dol_escape_js($langs->trans('KSEF_FetchCompleted')) . '";
    var langNewInvoices = "' . dol_escape_js($langs->trans('KSEF_NewInvoices')) . '";
    var langExistingInvoices = "' . dol_escape_js($langs->trans('KSEF_ExistingInvoices')) . '";
    var langFetchFailed = "' . dol_escape_js($langs->trans('KSEF_FetchFailed')) . '";
    var langResetAndRetry = "' . dol_escape_js($langs->trans('KSEF_ResetAndRetry')) . '";
    var langCancel = "' . dol_escape_js($langs->trans('Cancel')) . '";
    var langConfirmCancel = "' . dol_escape_js($langs->trans('KSEF_ConfirmCancelFetch')) . '";
    var langInitiating = "' . dol_escape_js($langs->trans('KSEF_Initiating')) . '";
    var langDownloading = "' . dol_escape_js($langs->trans('KSEF_Downloading')) . '";
    var langProcessingBatches = "' . dol_escape_js($langs->trans('KSEF_ProcessingBatches')) . '";
    var langReadyToProcess = "' . dol_escape_js($langs->trans('KSEF_ReadyToProcess')) . '";
    var processIncomingFired = false;

    function formatElapsed(seconds) {
        return String(Math.floor(seconds/60)).padStart(2,"0") + ":" + String(seconds%60).padStart(2,"0");
    }

    function startElapsedTimer() {
        if (elapsedTimer) clearInterval(elapsedTimer);
        elapsedTimer = setInterval(function() {
            var elapsed = Math.floor(Date.now() / 1000) - startTime;
            if (elapsedEl) elapsedEl.textContent = formatElapsed(elapsed);
        }, 1000);
    }

    function showProcessingOverlay(phaseMsg) {
        var msg = phaseMsg || langFetchInProgress;
        overlayEl.className = "info";
        overlayEl.style.display = "block";
        overlayEl.innerHTML = \'<span class="fa fa-spinner fa-spin" style="margin-right:10px;"></span>\' +
            \'<strong id="ksef-phase-msg">\' + msg + \'</strong>\' +
            \'<span id="ksef-elapsed" style="margin-left:15px;" class="opacitymedium">\' + langElapsedTime + \': <span id="elapsed-val">00:00</span></span>\' +
            \'<a href="\' + baseUrl + \'?action=reset_fetch&token=\' + token + \'" class="butAction" style="position:absolute;right:15px;top:12px;" onclick="return confirm(\\\'\' + langConfirmCancel + \'\\\');"><span class="fa fa-times"></span> \' + langCancel + \'</a>\';
        elapsedEl = document.getElementById("elapsed-val");
    }

    function updatePhaseMessage(msg) {
        var el = document.getElementById("ksef-phase-msg");
        if (el) el.textContent = msg;
    }

    function showCompletedOverlay(data) {
        if (elapsedTimer) clearInterval(elapsedTimer);
        overlayEl.className = "info";
        overlayEl.innerHTML = \'<span class="fa fa-check-circle" style="color:#28a745;margin-right:10px;"></span>\' +
            \'<strong style="color:#28a745;">\' + langFetchCompleted + \'</strong> - \' +
            langNewInvoices + \': \' + (data.new||0) + \', \' + langExistingInvoices + \': \' + (data.existing||0);
    }

    function showErrorOverlay(error) {
        if (elapsedTimer) clearInterval(elapsedTimer);
        overlayEl.className = "error";
        overlayEl.innerHTML = \'<span class="fa fa-exclamation-triangle" style="margin-right:10px;"></span>\' +
            \'<strong>\' + langFetchFailed + \'</strong>\' + (error ? \': \' + error : \'\') +
            \' <a href="\' + baseUrl + \'?action=reset_fetch&token=\' + token + \'" class="butAction" style="margin-left:15px;">\' + langResetAndRetry + \'</a>\';
    }

    function fireProcessIncoming() {
        if (processIncomingFired) return;
        processIncomingFired = true;
        fetch(baseUrl + "?action=process_incoming&token=" + ajaxToken).catch(function() {});
    }

    function poll() {
        fetch(baseUrl + "?action=check_fetch_status&token=" + ajaxToken)
        .then(function(r) { return r.json(); })
        .then(function(data) {
            console.log("KSeF poll:", data);
            if (data.status === "COMPLETED") {
                showCompletedOverlay(data);
                setTimeout(function() { location.href = baseUrl; }, 2000);
            } else if (data.status === "PROCESSING") {
                updatePhaseMessage(langFetchInProgress);
                setTimeout(poll, 3000);
            } else if (data.status === "READY_TO_PROCESS") {
                updatePhaseMessage(langReadyToProcess);
                fireProcessIncoming();
                setTimeout(poll, 500);
            } else if (data.status === "DOWNLOADING") {
                var dlMsg = langDownloading;
                if (data.parts_done && data.parts_total) {
                    dlMsg += " (" + data.parts_done + "/" + data.parts_total + ")";
                }
                updatePhaseMessage(dlMsg);
                setTimeout(poll, 500);
            } else if (data.status === "PROCESSING_BATCHES") {
                var msg = langProcessingBatches;
                if (data.total) {
                    msg += " (" + (data.processed||0) + "/" + data.total + ")";
                }
                if (data["new"] || data.existing) {
                    msg += " \u2014 +" + (data["new"]||0) + " " + langNewInvoices.toLowerCase() + ", " + (data.existing||0) + " " + langExistingInvoices.toLowerCase();
                }
                updatePhaseMessage(msg);
                setTimeout(poll, 500);
            } else if (data.status === "RATE_LIMITED") {
                setTimeout(poll, (data.retry_after || 10) * 1000);
            } else if (data.status === "FAILED" || data.status === "TIMEOUT" || data.status === "DOWNLOAD_FAILED" || data.status === "CHECK_FAILED") {
                showErrorOverlay(data.error || "");
            } else if (data.status === "NO_PENDING_FETCH") {
                location.href = baseUrl;
            } else {
                setTimeout(poll, 3000);
            }
        })
        .catch(function(e) { console.error("Poll error:", e); setTimeout(poll, 6000); });
    }

    // Initiate sync via AJAX
    window.ksefInitSync = function(e) {
        if (e) e.preventDefault();

        // Show overlay immediately
        startTime = Math.floor(Date.now() / 1000);
        showProcessingOverlay();
        startElapsedTimer();

        // Disable the sync button
        var btn = document.getElementById("ksef-sync-btn");
        if (btn) {
            btn.className = "butActionRefused";
            btn.onclick = function() { return false; };
        }

        // Call init_fetch AJAX endpoint
        fetch(baseUrl + "?action=init_fetch&token=" + ajaxToken)
        .then(function(r) { return r.json(); })
        .then(function(data) {
            console.log("KSeF init:", data);
            if (data.status === "INITIATED" || data.status === "ALREADY_PROCESSING") {
                setTimeout(poll, 1000);
            } else if (data.status === "ERROR") {
                showErrorOverlay(data.error || "Init failed");
            } else {
                setTimeout(poll, 1000);
            }
        })
        .catch(function(e) {
            console.error("Init error:", e);
            showErrorOverlay("Connection error");
        });

        return false;
    };

    if (isPolling && startTime) {
        startElapsedTimer();
        setTimeout(poll, 1000);
    }
})();
</script>';

/*
 * Sync Panel
 */

print '<div class="fichecenter">';
print '<div class="fichethirdleft">';

// Sync Panel
print '<div class="div-table-responsive-no-min">';
print '<table class="noborder nohover centpercent">';
print '<tr class="liste_titre"><th colspan="2">' . $langs->trans("KSEF_SyncFromKSeF") . '</th></tr>';

// KSeF System Status
$latarnia_cached = KsefLatarnia::getCachedStatus();
print '<tr class="oddeven"><td class="titlefield">' . $langs->trans("KSEF_SystemStatus") . '</td>';
print '<td>' . ksefGetLatarniaStatusBadge($latarnia_cached['status']) . '</td></tr>';

// Environment
print '<tr class="oddeven"><td class="titlefield">' . $langs->trans("KSEF_ENVIRONMENT") . '</td>';
print '<td>' . ksefGetEnvironmentBadge($environment) . '</td></tr>';

// Sync state info
if ($syncState) {
    print '<tr class="oddeven"><td>' . $langs->trans("KSEF_SyncContinuationPoint") . '</td>';
    print '<td>' . ($syncState->continuation_date ? dol_print_date($syncState->continuation_date, 'dayhour') : '<span class="opacitymedium">' . $langs->trans("NotSet") . '</span>') . '</td></tr>';

    print '<tr class="oddeven"><td>' . $langs->trans("KSEF_LastSync") . '</td>';
    print '<td>' . ($syncState->last_sync_date ? dol_print_date($syncState->last_sync_date, 'dayhour') : '<span class="opacitymedium">' . $langs->trans("Never") . '</span>') . '</td></tr>';

    if ($syncState->last_sync_date) {
        print '<tr class="oddeven"><td>' . $langs->trans("KSEF_LastSyncResult") . '</td>';
        print '<td>' . $langs->trans("KSEF_SyncResultShort", $syncState->last_sync_new, $syncState->last_sync_existing, $syncState->last_sync_total) . '</td></tr>';
    }

    if ($syncState->isRateLimited()) {
        $retryTime = $syncState->getRateLimitExpiryFormatted();
        print '<tr class="oddeven"><td colspan="2"><span class="warning">';
        print '<span class="fa fa-clock"></span> ' . $langs->trans("KSEF_RateLimitActive", $retryTime);
        print '</span></td></tr>';
    }

    if ($syncState->isFetchInProgress() && !empty($currentFetchPhase)) {
        $phaseLabel = '';
        switch ($currentFetchPhase) {
            case KsefSyncState::FETCH_STATUS_PROCESSING:
                $phaseLabel = $langs->trans("KSEF_PhaseWaitingKSeF");
                break;
            case KsefSyncState::FETCH_STATUS_READY_TO_PROCESS:
                $phaseLabel = $langs->trans("KSEF_PhaseReadyToProcess");
                break;
            case KsefSyncState::FETCH_STATUS_DOWNLOADING:
                $phaseLabel = $langs->trans("KSEF_PhaseDownloading");
                break;
            case KsefSyncState::FETCH_STATUS_PROCESSING_BATCHES:
                $phaseLabel = $langs->trans("KSEF_PhaseProcessingBatches", $syncState->process_offset, $syncState->process_total);
                break;
        }
        if ($phaseLabel) {
            print '<tr class="oddeven"><td>' . $langs->trans("KSEF_CurrentStatus") . '</td>';
            print '<td><span class="fa fa-spinner fa-spin" style="margin-right:5px;"></span>' . $phaseLabel . '</td></tr>';
        }
    }
} else {
    print '<tr class="oddeven"><td colspan="2"><span class="opacitymedium">';
    print $langs->trans("KSEF_SyncNotInitialized");
    print '</span></td></tr>';
}

print '</table>';
print '</div>';

// Sync button
print '<div class="center" style="margin-top: 15px;">';
if ($user->hasRight('ksef', 'write')) {
    $canSync = $syncState && $syncState->canSyncNow();
    $btnClass = $canSync ? 'butAction' : 'butActionRefused';
    $btnTitle = '';
    if (!$canSync) {
        if ($syncState->isFetchInProgress()) {
            $btnTitle = ' title="' . $langs->trans('KSEF_FetchAlreadyInProgress') . '"';
        } elseif ($syncState->isRateLimited()) {
            $btnTitle = ' title="' . $langs->trans('KSEF_RateLimitActive', $syncState->getRateLimitExpiryFormatted()) . '"';
        }
    }

    $onclick = $canSync ? ' onclick="return ksefInitSync(event);"' : ' onclick="return false;"';
    print '<a id="ksef-sync-btn" class="' . $btnClass . '"' . $btnTitle . $onclick . ' href="' . $_SERVER["PHP_SELF"] . '?action=sync&token=' . newToken() . '">';
    print '<span class="fa fa-sync"></span> ' . $langs->trans("KSEF_SyncNow");
    print '</a>';
}
print '</div>';

print '</div>';

// Statistics Panel
print '<div class="fichetwothirdright">';

print '<div class="div-table-responsive-no-min">';
print '<table class="noborder nohover centpercent">';
print '<tr class="liste_titre"><th colspan="2">' . $langs->trans("Statistics") . '</th></tr>';

print '<tr class="oddeven"><td class="titlefield">' . $langs->trans("Total") . '</td>';
print '<td class="right"><strong>' . (int)($stats_all['total'] ?? 0) . '</strong></td></tr>';

print '<tr class="oddeven"><td>' . $langs->trans("KSEF_ImportStatusNEW") . ' <span class="opacitymedium small">(' . $langs->trans("KSEF_Last30Days") . ')</span></td>';
print '<td class="right"><span class="badge badge-status1">' . (int)($stats['new'] ?? 0) . '</span></td></tr>';

print '<tr class="oddeven"><td>' . $langs->trans("KSEF_ImportStatusIMPORTED") . ' <span class="opacitymedium small">(' . $langs->trans("KSEF_Last30Days") . ')</span></td>';
print '<td class="right"><span class="badge badge-status4">' . (int)($stats['imported'] ?? 0) . '</span></td></tr>';

if (!empty($stats['error'])) {
    print '<tr class="oddeven"><td>' . $langs->trans("KSEF_ImportStatusERROR") . ' <span class="opacitymedium small">(' . $langs->trans("KSEF_Last30Days") . ')</span></td>';
    print '<td class="right"><span class="badge badge-status8">' . (int)$stats['error'] . '</span></td></tr>';
}

$ksefStartDate = strtotime('2026-02-01');
$ksefPreStartDate = strtotime('2026-01-31');
$today = dol_now();
$hwmTimestamp = $syncState ? $syncState->getContinuationDate() : null;
if ($hwmTimestamp && $hwmTimestamp < $ksefPreStartDate) $hwmTimestamp = $ksefPreStartDate;
if ($hwmTimestamp && $hwmTimestamp > $today) $hwmTimestamp = $today;
$totalSpan = max($today - $ksefStartDate, 1);
$hwmPercent = $hwmTimestamp ? round((($hwmTimestamp - $ksefStartDate) / $totalSpan) * 100, 1) : 0;
if ($hwmPercent < 0) $hwmPercent = 0;
if ($hwmPercent > 100) $hwmPercent = 100;
$startLabel = dol_print_date($ksefStartDate, 'day');
$hwmLabel = $hwmTimestamp ? dol_print_date($hwmTimestamp, 'day') : '';
$todayLabel = dol_print_date($today, 'day');

$timelineDividers = array();
$spanMonths = (int) round(($today - $ksefStartDate) / (30.44 * 86400));
$divStep = ($spanMonths <= 12) ? 'month' : '6months';
$divDate = strtotime('+1 month', strtotime(date('Y-m-01', $ksefStartDate)));
while ($divDate < $today) {
    $divPercent = round((($divDate - $ksefStartDate) / $totalSpan) * 100, 1);
    if ($divPercent > 2 && $divPercent < 98) {
        $timelineDividers[] = array('percent' => $divPercent, 'label' => dol_print_date($divDate, '%m/%Y'));
    }
    $divDate = strtotime(($divStep === 'month' ? '+1 month' : '+6 months'), $divDate);
}

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

print '<tr class="oddeven"><td colspan="2" style="padding: 8px;">';
print '<div class="liste_titre" style="margin-bottom: 0; min-height: 20px; padding-bottom: 0;">' . $langs->trans("KSEF_SyncProgress") . '</div>';

print '<div style="position: relative; height: 18px; font-size: 0.7em; color: #888; font-weight: 600;">';
foreach ($timelineDividers as $div) {
    if ($div['percent'] < 5 || $div['percent'] > 95) continue;
    print '<div style="position: absolute; left: ' . $div['percent'] . '%; transform: translateX(-50%); bottom: 0; text-align: center; white-space: nowrap;">' . $div['label'] . '</div>';
}
print '</div>';

print '<div style="position: relative; height: 28px; background: #d0d0d0; border-radius: 3px; overflow: visible; border-left: 3px solid #666; border-right: 3px solid #666;">';
if ($hwmPercent > 0) {
    print '<div style="position: absolute; top: 0; left: 0; height: 100%; width: ' . $hwmPercent . '%; background: ' . $barColor . '; transition: width 0.5s;"></div>';
}
if ($hwmTimestamp && $hwmPercent > 0 && $hwmPercent < 100) {
    print '<div style="position: absolute; top: -4px; left: ' . $hwmPercent . '%; width: 3px; height: calc(100% + 8px); background: #555; z-index: 2; transform: translateX(-50%);"></div>';
}
foreach ($timelineDividers as $div) {
    if ($div['percent'] < 5 || $div['percent'] > 95) continue;
    print '<div style="position: absolute; top: 0; left: ' . $div['percent'] . '%; width: 0; height: 100%; border-left: 1px dashed rgba(0,0,0,0.25); z-index: 1;"></div>';
}
print '</div>';

print '<div style="position: relative; height: 36px; margin-top: 2px; font-size: 0.72em; font-weight: 600; overflow: visible;">';
print '<div style="position: absolute; left: 0; top: 2px; text-align: left; white-space: nowrap; color: #333;">' . $startLabel . '</div>';
if ($hwmTimestamp && $hwmPercent > 8 && $hwmPercent < 88) {
    print '<div style="position: absolute; left: ' . $hwmPercent . '%; text-align: center; transform: translateX(-50%); top: 2px; white-space: nowrap;">';
    print '<span style="color: ' . $barColor . ';">SYNC</span><br><span style="color: ' . $barColor . ';">' . $hwmLabel . '</span>';
    print '</div>';
}
print '<div style="position: absolute; right: 0; top: 2px; text-align: right; white-space: nowrap; color: #333;">' . $langs->trans("Today") . '<br>' . $todayLabel . '</div>';
print '</div>';

print '<div style="text-align: center; margin-top: 0; font-size: 0.8em;">';
print '<span style="color: ' . $barColor . '; font-weight: 600;">' . $barStatus . '</span>';
if ($hwmTimestamp && $hwmPercent < 100) {
    print ' <span style="color: #888;">(' . $hwmPercent . '%)</span>';
}
print '</div>';

print '</td></tr>';

print '</table>';
print '</div>';

// Admin actions
if ($user->admin) {
    print '<div class="center" style="margin-top: 15px;">';

    print '<a class="butAction" href="' . $_SERVER["PHP_SELF"] . '?action=reset&token=' . newToken() . '">';
    print '<span class="fa fa-undo"></span> ' . $langs->trans("KSEF_ResetSyncState");
    print '</a> ';

    print '<a class="butActionDelete" href="' . $_SERVER["PHP_SELF"] . '?action=clearall&token=' . newToken() . '">';
    print '<span class="fa fa-trash"></span> ' . $langs->trans("KSEF_ClearAll");
    print '</a>';

    print '</div>';
}

print '</div>';
print '</div>';

print '<div class="clearboth"></div>';
print '<br>';

$lastSyncErrors = array();
if ($syncState && !empty($syncState->last_sync_errors)) {
    $lastSyncErrors = json_decode($syncState->last_sync_errors, true);
    if (!is_array($lastSyncErrors)) $lastSyncErrors = array();
}
if (!empty($lastSyncErrors)) {
    $errCount = count($lastSyncErrors);
    print '<div class="warning" style="padding: 8px 12px; margin-bottom: 10px;">';
    print '<span class="fa fa-exclamation-triangle" style="margin-right: 6px;"></span> ';
    print $langs->trans('KSEF_SyncErrorsExist', $errCount);
    if (empty($show_errors)) {
        print ' <a class="butAction small" href="' . $_SERVER["PHP_SELF"] . '?show_errors=1&token=' . newToken() . '">';
        print '<span class="fa fa-eye" style="margin-right: 4px;"></span>' . $langs->trans('KSEF_ViewErrors') . '</a>';
    } else {
        print ' <a class="butAction small" href="' . $_SERVER["PHP_SELF"] . '?token=' . newToken() . '">';
        print '<span class="fa fa-eye-slash" style="margin-right: 4px;"></span>' . $langs->trans('KSEF_HideErrors') . '</a>';
    }
    print '</div>';

    if (!empty($show_errors)) {
        print '<div class="div-table-responsive-no-min">';
        print '<table class="noborder centpercent">';
        print '<tr class="liste_titre">';
        print '<th>' . $langs->trans('KSEF_ErrorKsefNumber') . '</th>';
        print '<th>' . $langs->trans('KSEF_ErrorMessage') . '</th>';
        print '</tr>';

        foreach ($lastSyncErrors as $errLine) {
            $parts = explode(': ', $errLine, 2);
            $errKsef = $parts[0] ?? '';
            $errMsg = $parts[1] ?? $errLine;
            print '<tr class="oddeven">';
            print '<td class="tdoverflowmax200">' . dol_escape_htmltag($errKsef) . '</td>';
            print '<td class="small">' . dol_escape_htmltag($errMsg) . '</td>';
            print '</tr>';
        }

        print '</table>';
        print '</div>';
        print '<br>';
    }
}

$filters = array();
if (!empty($search_seller_nip)) $filters['seller_nip'] = $search_seller_nip;
if (!empty($search_seller_name)) $filters['seller_name'] = $search_seller_name;
if (!empty($search_invoice_number)) $filters['invoice_number'] = $search_invoice_number;
if (!empty($search_ksef_number)) $filters['ksef_number'] = $search_ksef_number;
if (!empty($search_import_status) && $search_import_status != '-1') $filters['import_status'] = $search_import_status;
$filters['environment'] = $environment;

$nbtotalofrecords = $incoming->countAll($filters);

if (($page * $limit) > $nbtotalofrecords) {
    $page = 0;
    $offset = 0;
}

$param = '';
if (!empty($contextpage) && $contextpage != $_SERVER["PHP_SELF"]) $param .= '&contextpage=' . urlencode($contextpage);
if ($limit > 0 && $limit != $conf->liste_limit) $param .= '&limit=' . ((int)$limit);
if (!empty($search_seller_nip)) $param .= '&search_seller_nip=' . urlencode($search_seller_nip);
if (!empty($search_seller_name)) $param .= '&search_seller_name=' . urlencode($search_seller_name);
if (!empty($search_invoice_number)) $param .= '&search_invoice_number=' . urlencode($search_invoice_number);
if (!empty($search_ksef_number)) $param .= '&search_ksef_number=' . urlencode($search_ksef_number);
if (!empty($search_import_status)) $param .= '&search_import_status=' . urlencode($search_import_status);
if ($optioncss != '') $param .= '&optioncss=' . urlencode($optioncss);

$arrayofmassactions = array();
if ($user->hasRight('ksef', 'write')) {
    $arrayofmassactions['batch_import'] = img_picto('', 'download', 'class="pictofixedwidth"') . $langs->trans("KSEF_BatchImport");
}
$arrayofmassactions['predelete'] = img_picto('', 'delete', 'class="pictofixedwidth"') . $langs->trans("Delete");
$massactionbutton = $form->selectMassAction('', $arrayofmassactions);

$arrayofselected = is_array($toselect) ? $toselect : array();

$selectedfields = '';
if (count($arrayofmassactions)) {
    $selectedfields .= $form->showCheckAddButtons('checkforselect', 1);
}

print '<form method="POST" id="searchFormList" action="' . $_SERVER["PHP_SELF"] . '" name="searchFormList">';
if ($optioncss != '') print '<input type="hidden" name="optioncss" value="' . $optioncss . '">';
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" name="formfilteraction" id="formfilteraction" value="list">';
print '<input type="hidden" name="action" value="list">';
print '<input type="hidden" name="sortfield" value="' . $sortfield . '">';
print '<input type="hidden" name="sortorder" value="' . $sortorder . '">';
print '<input type="hidden" name="page" value="' . $page . '">';
print '<input type="hidden" name="contextpage" value="' . $contextpage . '">';

print_barre_liste($langs->trans("List"), $page, $_SERVER["PHP_SELF"], $param, $sortfield, $sortorder, $massactionbutton, $nbtotalofrecords, $nbtotalofrecords, '', 0, '', '', $limit, 0, 0, 1);

$topicmail = '';
$modelmail = '';
$objecttmp = new KsefIncoming($db);
$trackid = 'ksefi';
include DOL_DOCUMENT_ROOT.'/core/tpl/massactions_pre.tpl.php';

print '<div class="div-table-responsive">';
print '<table class="tagtable nobottomiftotal liste">' . "\n";

// Filter row
print '<tr class="liste_titre_filter">';
if (getDolGlobalString('MAIN_CHECKBOX_LEFT_COLUMN')) {
    print '<td class="liste_titre center maxwidthsearch">' . $form->showFilterButtons('left') . '</td>';
}
print '<td class="liste_titre left"><input class="flat maxwidth100" type="text" name="search_invoice_number" value="' . dol_escape_htmltag($search_invoice_number) . '"></td>';
print '<td class="liste_titre center"></td>';
print '<td class="liste_titre center"></td>';
print '<td class="liste_titre left"><input class="flat maxwidth150" type="text" name="search_seller_name" value="' . dol_escape_htmltag($search_seller_name) . '"></td>';
print '<td class="liste_titre left"><input class="flat maxwidth100" type="text" name="search_seller_nip" value="' . dol_escape_htmltag($search_seller_nip) . '"></td>';
print '<td class="liste_titre center">';
print $form->selectarray('search_import_status', array(
    'NEW' => $langs->trans('KSEF_ImportStatusNEW'),
    'IMPORTED' => $langs->trans('KSEF_ImportStatusIMPORTED'),
    'ERROR' => $langs->trans('KSEF_ImportStatusERROR'),
), $search_import_status, 1, 0, 0, '', 0, 0, 0, '', 'maxwidth100');
print '</td>';
print '<td class="liste_titre center"></td>';
print '<td class="liste_titre center"></td>';
print '<td class="liste_titre right"></td>';
print '<td class="liste_titre center"></td>';
if (!getDolGlobalString('MAIN_CHECKBOX_LEFT_COLUMN')) {
    print '<td class="liste_titre center maxwidthsearch">' . $form->showFilterButtons() . '</td>';
}
print '</tr>' . "\n";

// Header row
print '<tr class="liste_titre">';
if (getDolGlobalString('MAIN_CHECKBOX_LEFT_COLUMN')) print getTitleFieldOfList($selectedfields, 0, $_SERVER["PHP_SELF"], '', '', '', '', $sortfield, $sortorder, 'center maxwidthsearch ') . "\n";
print_liste_field_titre("Invoice", $_SERVER["PHP_SELF"], "i.invoice_number", "", $param, "", $sortfield, $sortorder);
print_liste_field_titre("", $_SERVER["PHP_SELF"], "", "", $param, '', '', '', 'center ');
print_liste_field_titre("Type", $_SERVER["PHP_SELF"], "i.invoice_type", "", $param, '', $sortfield, $sortorder, 'center ');
print_liste_field_titre("KSEF_Seller", $_SERVER["PHP_SELF"], "i.seller_name", "", $param, "", $sortfield, $sortorder);
print_liste_field_titre("NIP", $_SERVER["PHP_SELF"], "i.seller_nip", "", $param, "", $sortfield, $sortorder);
print_liste_field_titre("Status", $_SERVER["PHP_SELF"], "i.import_status", "", $param, '', $sortfield, $sortorder, 'center ');
print_liste_field_titre("KSEF_LinkedInvoice", $_SERVER["PHP_SELF"], "", "", $param, '', '', '', '');
print_liste_field_titre("Date", $_SERVER["PHP_SELF"], "i.invoice_date", "", $param, '', $sortfield, $sortorder, 'center ');
print_liste_field_titre("Amount", $_SERVER["PHP_SELF"], "i.total_gross", "", $param, '', $sortfield, $sortorder, 'right ');
print_liste_field_titre("Actions", $_SERVER["PHP_SELF"], "", "", $param, '', '', '', 'center maxwidthsearch');
if (!getDolGlobalString('MAIN_CHECKBOX_LEFT_COLUMN')) print getTitleFieldOfList($selectedfields, 0, $_SERVER["PHP_SELF"], '', '', '', '', $sortfield, $sortorder, 'center maxwidthsearch ') . "\n";
print '</tr>' . "\n";

$records = $incoming->fetchAll($filters, $sortfield, $sortorder, $limit, $offset);

if (is_array($records) && count($records) > 0) {
    foreach ($records as $obj) {
        print '<tr class="oddeven">';

        if (getDolGlobalString('MAIN_CHECKBOX_LEFT_COLUMN')) {
            print '<td class="nowrap center">';
            if ($massactionbutton || $massaction) print '<input id="cb' . $obj->rowid . '" class="flat checkforselect" type="checkbox" name="toselect[]" value="' . $obj->rowid . '"' . (in_array($obj->rowid, $arrayofselected) ? ' checked="checked"' : '') . '>';
            print '</td>';
        }

        print '<td class="tdoverflowmax200">';
        print '<a href="' . dol_buildpath('/ksef/incoming_card.php', 1) . '?id=' . $obj->rowid . '">';
        print img_object($langs->trans("Show"), 'bill', 'class="pictofixedwidth"');
        print dol_escape_htmltag($obj->invoice_number);
        print '</a>';
        print '</td>';

        // Import button
        print '<td class="center nowraponall">';
        if (($obj->import_status == 'NEW' || $obj->import_status == 'ERROR') && $user->hasRight('ksef', 'write')) {
            print '<a class="button reposition smallpaddingimp" href="' . dol_buildpath('/ksef/incoming_import.php', 1) . '?id=' . $obj->rowid . '&action=quickimport&token=' . newToken() . '">';
            print '<span class="fa fa-download paddingright"></span>' . $langs->trans("KSEF_Import");
            print '</a>';
        }
        print '</td>';

        print '<td class="center nowraponall">' . ksefGetInvoiceTypeBadge($obj->invoice_type) . '</td>';

        print '<td class="tdoverflowmax200">' . dol_escape_htmltag($obj->seller_name) . '</td>';
        print '<td class="nowraponall">' . ksefFormatNIP($obj->seller_nip) . '</td>';
        print '<td class="center">' . ksefGetIncomingStatusBadge($obj->import_status) . '</td>';

        // Linked Dolibarr invoice
        print '<td class="tdoverflowmax200">';
        if ($obj->fk_facture_fourn > 0) {
            $linkedInv = new FactureFournisseur($db);
            if ($linkedInv->fetch($obj->fk_facture_fourn) > 0) {
                print $linkedInv->getNomUrl(1);
            }
        }
        print '</td>';

        print '<td class="center">' . dol_print_date($obj->invoice_date, 'day') . '</td>';
        print '<td class="right nowraponall">' . price($obj->total_gross) . ' ' . dol_escape_htmltag($obj->currency) . '</td>';

        print '<td class="center nowraponall">';
        if (!empty($obj->fa3_xml)) {
            print '<a class="butAction classfortooltip marginleftonly" href="' . $_SERVER["PHP_SELF"] . '?action=download_xml&id=' . $obj->rowid . '&token=' . newToken() . '" title="' . $langs->trans("KSEF_DownloadXml") . '"><span class="fa fa-file-code"></span></a>';
        }
        if (!empty($obj->ksef_number) && !empty($obj->fa3_xml)) {
            $objEnv = !empty($obj->environment) ? $obj->environment : $environment;
            $verifyUrl = ksefGetVerificationUrlFromXml($obj->ksef_number, $obj->fa3_xml, $objEnv);
            print '<a class="butAction classfortooltip marginleftonly" href="' . $verifyUrl . '" target="_blank" title="' . $langs->trans("KSEF_ViewOnPortal") . '"><span class="fa fa-external-link-alt"></span></a>';
        }
        if (!empty($obj->fa3_xml)) {
            print '<a class="butAction classfortooltip marginleftonly" href="' . $_SERVER["PHP_SELF"] . '?action=download_pdf&id=' . $obj->rowid . '&token=' . newToken() . '" title="' . $langs->trans("KSEF_DownloadPdf") . '"><span class="fa fa-file-pdf"></span></a>';
        }
        print '</td>';

        if (!getDolGlobalString('MAIN_CHECKBOX_LEFT_COLUMN')) {
            print '<td class="nowrap center">';
            if ($massactionbutton || $massaction) print '<input id="cb' . $obj->rowid . '" class="flat checkforselect" type="checkbox" name="toselect[]" value="' . $obj->rowid . '"' . (in_array($obj->rowid, $arrayofselected) ? ' checked="checked"' : '') . '>';
            print '</td>';
        }

        print '</tr>' . "\n";
    }
} else {
    $colspan = 11;
    print '<tr><td colspan="' . $colspan . '"><span class="opacitymedium">' . $langs->trans("NoRecordFound") . '</span></td></tr>';
}

print '</table>';
print '</div>';
print '</form>';


// Batch Import Styles
print '<style>
.batch-row-inactive td { opacity: 0.45; }
.batch-row-inactive td:first-child { opacity: 1; }
.batch-row-inactive .batch-action-badge { display: none; }
.batch-row-inactive .warning { opacity: 1; }
.batch-blocked .batch-invoice-cell { opacity: 1 !important; }
.batch-blocked .batch-supplier-cell { opacity: 1 !important; }
</style>';

// Batch Import
print '<div id="batch-import-modal" style="display:none;" title="' . dol_escape_htmltag($langs->trans("KSEF_BatchImportTitle")) . '">';
print '<div id="batch-import-loading" class="center" style="padding:20px;"><span class="fa fa-spinner fa-spin fa-2x"></span></div>';
print '<div id="batch-import-content" style="display:none;">';
print '<p id="batch-import-summary"></p>';
print '<div class="div-table-responsive">';
print '<table class="noborder centpercent" id="batch-import-table">';
print '<thead><tr class="liste_titre">';
print '<th class="center" style="width:30px"><input type="checkbox" id="batch-select-all" checked></th>';
print '<th>' . $langs->trans("Invoice") . '</th>';
print '<th>' . $langs->trans("Amount") . '</th>';
print '<th>' . $langs->trans("Supplier") . '</th>';
print '<th>' . $langs->trans("Products") . '</th>';
print '</tr></thead>';
print '<tbody></tbody>';
print '</table>';
print '</div>';
print '<div style="margin-top:10px;">';
print '<label><input type="checkbox" id="batch-auto-create-suppliers"' . (getDolGlobalString('KSEF_BATCH_AUTO_CREATE_SUPPLIERS', '1') ? ' checked' : '') . '> ' . $langs->trans("KSEF_BatchAutoCreateSuppliers") . '</label>';
print ' <span class="fa fa-info-circle classfortooltip" style="opacity: 0.5;" title="' . dol_escape_htmltag($langs->trans('KSEF_BatchAutoCreateSuppliers_Help')) . '"></span><br>';
print '<label><input type="checkbox" id="batch-auto-create-products"' . (getDolGlobalString('KSEF_BATCH_AUTO_CREATE_PRODUCTS', '1') ? ' checked' : '') . '> ' . $langs->trans("KSEF_BatchAutoCreateProducts") . '</label>';
print ' <span class="fa fa-info-circle classfortooltip" style="opacity: 0.5;" title="' . dol_escape_htmltag($langs->trans('KSEF_BatchAutoCreateProducts_Help')) . '"></span>';
if (!empty(getDolGlobalString('KSEF_GUS_ENABLED'))) {
    print '<br><label><input type="checkbox" id="batch-gus-fetch-suppliers"' . (getDolGlobalString('KSEF_GUS_FETCH_SUPPLIERS') ? ' checked' : '') . '> ' . $langs->trans("KSEF_GusFetchSuppliers") . '</label>';
    print ' <span class="fa fa-info-circle classfortooltip" style="opacity: 0.5;" title="' . dol_escape_htmltag($langs->trans('KSEF_GusFetchSuppliers_Help')) . '"></span>';
}
print '</div>';
if (isModEnabled('stock')) {
    $stockEnabled = getDolGlobalString('STOCK_CALCULATE_ON_SUPPLIER_BILL');
    $stockShort = $stockEnabled ? $langs->trans('KSEF_StockWillIncrease') : $langs->trans('KSEF_StockWillNotIncrease');
    $stockVerbose = $stockEnabled ? $langs->trans('KSEF_StockAutoIncreaseEnabled') : $langs->trans('KSEF_StockAutoIncreaseDisabled');

    print '<div style="margin-top:10px; padding:8px; background:#f0f4f8; border-radius:4px;">';
    print $stockShort;
    print ' <span class="fa fa-info-circle classfortooltip" style="opacity: 0.5;" title="' . dol_escape_htmltag($stockVerbose) . '"></span>';
    print '</div>';
}
print '</div>';
print '<div id="batch-import-results" style="display:none;"></div>';
print '</div>';

// Batch Import JavaScript
print '<script type="text/javascript">
$(document).ready(function() {
    var ajaxToken = ' . json_encode(currentToken()) . ';
    var baseUrl = ' . json_encode($_SERVER["PHP_SELF"]) . ';
    var batchData = null;

    var langSupplierMatched = ' . json_encode($langs->trans("KSEF_BatchSupplierMatched")) . ';
    var langSupplierWillCreate = ' . json_encode($langs->trans("KSEF_BatchSupplierWillCreate")) . ';
    var langProductsMatched = ' . json_encode($langs->trans("KSEF_BatchProductsMatched")) . ';
    var langConfirmImport = ' . json_encode($langs->trans("KSEF_BatchImportConfirm")) . ';
    var langClose = ' . json_encode($langs->trans("Close")) . ';
    var langImporting = ' . json_encode($langs->trans("KSEF_BatchImporting")) . ';
    var langBatchResults = ' . json_encode($langs->trans("KSEF_BatchImportResults")) . ';
    var langSuccess = ' . json_encode($langs->trans("KSEF_BatchImportSuccessLine")) . ';
    var langError = ' . json_encode($langs->trans("Error")) . ';
    var langGusOverrideTitle = ' . json_encode($langs->trans("KSEF_GusOverrideTitle")) . ';
    var langGusOverrideIntro = ' . json_encode($langs->trans("KSEF_GusOverrideIntro")) . ';
    var langGusOverrideProceed = ' . json_encode($langs->trans("KSEF_GusOverrideProceed")) . ';
    var langGusPreviewFailed = ' . json_encode($langs->trans("KSEF_GusPreviewFailed")) . ';
    var langGusPreviewTruncated = ' . json_encode($langs->trans("KSEF_GusPreviewTruncated")) . ';
    var langGusCancel = ' . json_encode($langs->trans("Cancel")) . ';
    var langNoImportable = ' . json_encode($langs->trans("KSEF_BatchNoImportable")) . ';
    var langMatchedByRef = ' . json_encode($langs->trans("KSEF_MatchedByRef")) . ';
    var langMatchedBySupplierRef = ' . json_encode($langs->trans("KSEF_MatchedBySupplierRef")) . ';
    var langMatchedByBarcode = ' . json_encode($langs->trans("KSEF_MatchedByBarcode")) . ';
    var langWillCreate = ' . json_encode($langs->trans("KSEF_BatchProductWillCreate")) . ';
    var langCorrectionBlocked = ' . json_encode($langs->trans("KSEF_BatchCorrectionBlocked")) . ';
    var langSupplierNotMatched = ' . json_encode($langs->trans("KSEF_BatchSupplierNotMatched")) . ';
    var langSupplierNoMatch = ' . json_encode($langs->trans("KSEF_BatchSupplierNoMatch")) . ';
    var langProductFreeText = ' . json_encode($langs->trans("KSEF_BatchProductFreeText")) . ';
    var langProductsFreeTextWarning = ' . json_encode($langs->trans("KSEF_BatchProductsFreeTextWarning")) . ';
    var langDescription = ' . json_encode($langs->trans("Description")) . ';
    var langRef = ' . json_encode($langs->trans("Ref")) . ';
    var langQty = ' . json_encode($langs->trans("Qty")) . ';
    var langUnitPriceShort = ' . json_encode($langs->trans("PriceUHT")) . ';
    var langProduct = ' . json_encode($langs->trans("Product")) . ';
    var langMatchMethod = ' . json_encode($langs->trans("KSEF_MatchMethod")) . ';
    var langVAT = ' . json_encode($langs->trans("VAT")) . ';
    var langTotalHT = ' . json_encode($langs->trans("TotalHT")) . ';
    var langVATExempt = ' . json_encode($langs->trans("KSEF_VATExempt")) . ';

    var submitSource = null;
    $("form#searchFormList").on("click", "button, input[type=submit]", function() {
        submitSource = this.name || null;
    });

    $("form#searchFormList").on("submit", function(e) {
        if (submitSource === "button_search_x" || submitSource === "button_search"
            || submitSource === "button_removefilter_x" || submitSource === "button_removefilter") {
            submitSource = null;
            return true;
        }
        submitSource = null;

        var massactionVal = $("select[name=massaction]").val();
        if (massactionVal !== "batch_import") return true;

        e.preventDefault();

        var selectedIds = [];
        $("input.checkforselect:checked").each(function() {
            selectedIds.push($(this).val());
        });

        if (selectedIds.length === 0) {
            alert(' . json_encode($langs->trans("NoRecordSelected")) . ');
            return false;
        }

        $("#batch-import-loading").show();
        $("#batch-import-content").hide();
        $("#batch-import-results").hide();

        var $dialog = $("#batch-import-modal").dialog({
            modal: true,
            width: Math.min(1170, $(window).width() - 40),
            maxHeight: $(window).height() - 100,
            closeOnEscape: true,
            buttons: [
                {
                    text: langConfirmImport,
                    id: "batch-import-btn-confirm",
                    class: "butAction",
                    click: function() { executeBatchImport(); }
                },
                {
                    text: langClose,
                    click: function() { $(this).dialog("close"); }
                }
            ]
        });

        $.ajax({
            url: baseUrl + "?action=batch_import_preview&token=" + ajaxToken,
            method: "POST",
            data: { ids: selectedIds },
            dataType: "json",
            success: function(data) {
                if (data.status !== "OK" || !data.invoices || data.invoices.length === 0) {
                    $("#batch-import-loading").hide();
                    $("#batch-import-results").html("<p class=\"warning\">" + langNoImportable + "</p>").show();
                    $("#batch-import-btn-confirm").hide();
                    return;
                }
                batchData = data.invoices;
                renderBatchPreview(data.invoices);
            },
            error: function() {
                $("#batch-import-loading").hide();
                $("#batch-import-results").html("<p class=\"error\">Connection error</p>").show();
            }
        });

        return false;
    });

    function getMatchMethodLabel(method) {
        if (method === "product_ref") return langMatchedByRef;
        if (method === "supplier_ref") return langMatchedBySupplierRef;
        if (method === "barcode") return langMatchedByBarcode;
        return method || "";
    }

    function isSupplierBlocked(inv) {
        if (inv.supplier_matched) return false;
        return !$("#batch-auto-create-suppliers").is(":checked");
    }

    function getRowBlockedState(inv) {
        if (inv.correction_blocked) return { blocked: true, reason: "correction" };
        if (isSupplierBlocked(inv)) return { blocked: true, reason: "supplier" };
        return { blocked: false, reason: null };
    }

    function buildSupplierHtml(inv, blockState) {
        if (blockState.blocked && blockState.reason === "correction") {
            return \'<span class="opacitymedium">\' + escapeHtml(inv.supplier_matched ? inv.supplier_name : inv.seller_name) + \'</span>\';
        }
        if (inv.supplier_matched) {
            return \'<span class="badge badge-status4">\' + langSupplierMatched + \'</span><br>\' + escapeHtml(inv.supplier_name);
        }
        if (blockState.blocked && blockState.reason === "supplier") {
            var html = \'<span class="badge badge-status8">\' + langSupplierNoMatch + \'</span><br>\' + escapeHtml(inv.seller_name);
            if (inv.seller_nip) html += \'<br><span class="opacitymedium">NIP: \' + escapeHtml(inv.seller_nip) + \'</span>\';
            if (inv.seller_vat_id) html += \'<br><span class="opacitymedium">VAT: \' + escapeHtml(inv.seller_vat_id) + \'</span>\';
            return html;
        }
        var html = \'<span class="badge badge-status1 batch-action-badge">\' + langSupplierWillCreate + \'</span><br>\' + escapeHtml(inv.seller_name);
        if (inv.seller_nip) html += \'<br><span class="opacitymedium">NIP: \' + escapeHtml(inv.seller_nip) + \'</span>\';
        if (inv.seller_vat_id) html += \'<br><span class="opacitymedium">VAT: \' + escapeHtml(inv.seller_vat_id) + \'</span>\';
        return html;
    }

    function buildSupplierWarningHtml(blockState) {
        if (blockState.blocked && blockState.reason === "supplier") {
            return \'<br><span class="warning"><span class="fa fa-exclamation-triangle"></span> \' + escapeHtml(langSupplierNotMatched) + \'</span>\';
        }
        return "";
    }

    function isAutoCreateProducts() {
        return $("#batch-auto-create-products").is(":checked");
    }

    function buildProductsHtml(inv, blockState, idx) {
        if (blockState.blocked) {
            return \'<span class="opacitymedium">\' + inv.total_lines + \' lines</span>\';
        }
        var productsHtml = inv.matched_lines + "/" + inv.total_lines + " " + langProductsMatched;
        if (inv.all_products_matched) {
            productsHtml = \'<span class="badge badge-status4">\' + productsHtml + \'</span>\';
        } else if (isAutoCreateProducts()) {
            productsHtml = \'<span class="badge badge-status1">\' + productsHtml + \'</span>\';
        } else {
            productsHtml = \'<span class="badge badge-status1">\' + productsHtml + \'</span>\';
            var freeTextCount = inv.total_lines - inv.matched_lines;
            if (freeTextCount > 0) {
                productsHtml += \' <span class="badge badge-status0">\' + freeTextCount + "/" + inv.total_lines + " " + langProductFreeText + \'</span>\';
            }
            productsHtml += \'<br><span class="opacitymedium small">\' + escapeHtml(langProductsFreeTextWarning) + \'</span>\';
        }
        productsHtml += \' <a href="#" class="batch-toggle-lines" data-inv-idx="\' + idx + \'"><span class="fa fa-chevron-down"></span></a>\';
        return productsHtml;
    }

    function buildUnmatchedLineProductCell(line) {
        if (isAutoCreateProducts()) {
            var willCreateInfo = \'<span class="badge badge-status1">\' + langWillCreate + \'</span>\';
            if (line.description) {
                willCreateInfo += \'<br><span class="opacitymedium">\' + escapeHtml(line.description);
                if (line.indeks) willCreateInfo += \' [\' + escapeHtml(line.indeks) + \']\';
                willCreateInfo += \'</span>\';
            }
            return willCreateInfo;
        } else {
            var freeTextInfo = \'<span class="badge badge-status0">\' + langProductFreeText + \'</span>\';
            if (line.description) {
                freeTextInfo += \'<br><span class="opacitymedium">\' + escapeHtml(line.description);
                if (line.indeks) freeTextInfo += \' [\' + escapeHtml(line.indeks) + \']\';
                freeTextInfo += \'</span>\';
            }
            return freeTextInfo;
        }
    }

    function updateRowBlockedState($row, inv, idx) {
        var blockState = getRowBlockedState(inv);
        var $checkbox = $row.find(".batch-row-check");

        $row.find(".batch-supplier-cell").html(buildSupplierHtml(inv, blockState));
        $row.find(".batch-products-cell").html(buildProductsHtml(inv, blockState, idx));
        $row.find(".batch-invoice-cell .batch-supplier-warning").remove();
        var supplierWarning = buildSupplierWarningHtml(blockState);
        if (supplierWarning) {
            $row.find(".batch-invoice-cell").append(\'<span class="batch-supplier-warning">\' + supplierWarning + \'</span>\');
        }

        var $detailRow = $(".batch-detail-row[data-inv-idx=\'" + idx + "\']");

        if (blockState.blocked) {
            $checkbox.prop("checked", false).prop("disabled", true);
            if (blockState.reason === "correction") {
                $checkbox.attr("title", langCorrectionBlocked);
            } else {
                $checkbox.attr("title", langSupplierNotMatched);
            }
            $row.addClass("batch-blocked batch-row-inactive");
            $detailRow.hide();
            $row.find(".batch-toggle-lines span").removeClass("fa-chevron-up").addClass("fa-chevron-down");
        } else {
            if ($row.hasClass("batch-blocked")) {
                $checkbox.prop("disabled", false).prop("checked", true).removeAttr("title");
                $row.removeClass("batch-blocked batch-row-inactive");
            }
        }
    }

    function renderBatchPreview(invoices) {
        var tbody = $("#batch-import-table tbody");
        tbody.empty();

        $.each(invoices, function(i, inv) {
            var blockState = getRowBlockedState(inv);
            var isBlocked = blockState.blocked;

            var supplierHtml = buildSupplierHtml(inv, blockState);
            var productsHtml = buildProductsHtml(inv, blockState, i);

            var typeHtml = (inv.invoice_type === "KOR" || inv.invoice_type === "KOR_ZAL" || inv.invoice_type === "KOR_ROZ") ? \' <span class="badge badge-status1">\' + escapeHtml(inv.invoice_type) + \'</span>\' : "";

            var warningsHtml = "";
            if (inv.correction_blocked && inv.correction_issues && inv.correction_issues.length > 0) {
                warningsHtml = \'<br>\';
                for (var ci = 0; ci < inv.correction_issues.length; ci++) {
                    warningsHtml += \'<span class="warning"><span class="fa fa-exclamation-triangle"></span> \' + escapeHtml(inv.correction_issues[ci]) + \'</span><br>\';
                }
            }
            var swHtml = buildSupplierWarningHtml(blockState);
            if (swHtml) warningsHtml += \'<span class="batch-supplier-warning">\' + swHtml + \'</span>\';

            var blockTitle = "";
            if (blockState.reason === "correction") blockTitle = langCorrectionBlocked;
            else if (blockState.reason === "supplier") blockTitle = langSupplierNotMatched;

            var checkboxHtml = isBlocked
                ? \'<input type="checkbox" class="batch-row-check" disabled title="\' + escapeHtml(blockTitle) + \'">\'
                : \'<input type="checkbox" class="batch-row-check" checked>\';

            var rowClass = isBlocked ? \' class="batch-blocked batch-row-inactive"\' : \'\';

            var tr = \'<tr data-id="\' + inv.id + \'" data-inv-idx="\' + i + \'" data-socid="\' + inv.socid + \'" data-supplier-matched="\' + (inv.supplier_matched ? "1" : "0") + \'"\' + rowClass + \'>\' +
                \'<td class="center">\' + checkboxHtml + \'</td>\' +
                \'<td class="batch-invoice-cell">\' + escapeHtml(inv.invoice_number) + typeHtml + \'<br><span class="opacitymedium">\' + inv.invoice_date + \'</span>\' + warningsHtml + \'</td>\' +
                \'<td class="right nowraponall">\' + inv.total_gross + \'</td>\' +
                \'<td class="batch-supplier-cell">\' + supplierHtml + \'</td>\' +
                \'<td class="batch-products-cell">\' + productsHtml + \'</td>\' +
                \'</tr>\';
            tbody.append(tr);

            if (inv.lines && inv.lines.length > 0) {
                var detailHtml = \'<tr class="batch-detail-row" data-inv-idx="\' + i + \'" style="display:none;">\';
                detailHtml += \'<td></td><td colspan="4">\';
                detailHtml += \'<table class="noborder centpercent" style="background:#f8f8f8;">\';
                detailHtml += \'<tr class="liste_titre"><th>#</th><th>\' + langDescription + \'</th><th>\' + langRef + \'</th><th class="right">\' + langQty + \'</th><th class="right">\' + langUnitPriceShort + \'</th><th class="right">\' + langVAT + \'</th><th class="right">\' + langTotalHT + \'</th><th>\' + langProduct + \'</th></tr>\';

                $.each(inv.lines, function(j, line) {
                    var vatDisplay = line.vat_rate === "zw" ? langVATExempt : (line.vat_rate ? parseInt(line.vat_rate) + "%" : "");
                    var refDisplay = escapeHtml(line.indeks);
                    if (line.gtin) {
                        refDisplay += (refDisplay ? "<br>" : "") + \'<span class="opacitymedium">\' + escapeHtml(line.gtin) + \'</span>\';
                    }

                    detailHtml += \'<tr class="oddeven">\';
                    detailHtml += \'<td>\' + line.line_num + \'</td>\';
                    detailHtml += \'<td>\' + escapeHtml(line.description) + \'</td>\';
                    detailHtml += \'<td>\' + (refDisplay || "–") + \'</td>\';
                    detailHtml += \'<td class="right">\' + line.qty + \'</td>\';
                    detailHtml += \'<td class="right">\' + line.unit_price_net + \'</td>\';
                    detailHtml += \'<td class="right">\' + vatDisplay + \'</td>\';
                    detailHtml += \'<td class="right">\' + line.net_amount + \'</td>\';
                    if (line.product_matched) {
                        detailHtml += \'<td><span class="badge badge-status4">\' + escapeHtml(line.product_ref || "") + \'</span> \' + escapeHtml(line.product_label || "") + \'<br><span class="opacitymedium">\' + getMatchMethodLabel(line.match_method) + \'</span></td>\';
                    } else {
                        detailHtml += \'<td class="batch-unmatched-product" data-inv-idx="\' + i + \'" data-line-idx="\' + j + \'">\' + buildUnmatchedLineProductCell(line) + \'</td>\';
                    }
                    detailHtml += \'</tr>\';
                });

                detailHtml += \'</table></td></tr>\';
                tbody.append(detailHtml);
            }
        });

        $("#batch-import-loading").hide();
        $("#batch-import-content").show();
    }

    $(document).on("click", ".batch-toggle-lines", function(e) {
        e.preventDefault();
        var idx = $(this).data("inv-idx");
        var $detailRow = $(".batch-detail-row[data-inv-idx=\'" + idx + "\']");
        $detailRow.toggle();
        $(this).find("span").toggleClass("fa-chevron-down fa-chevron-up");
    });

    $("#batch-select-all").on("change", function() {
        var checked = $(this).is(":checked");
        $(".batch-row-check:not(:disabled)").prop("checked", checked).trigger("change");
    });

    $("#batch-auto-create-suppliers").on("change", function() {
        if (!batchData) return;
        $("#batch-import-table tbody tr[data-id]").each(function() {
            var $row = $(this);
            var idx = parseInt($row.data("inv-idx"));
            if (isNaN(idx) || !batchData[idx]) return;
            var inv = batchData[idx];
            if (inv.correction_blocked) return;
            updateRowBlockedState($row, inv, idx);
        });
    });

    $("#batch-auto-create-products").on("change", function() {
        if (!batchData) return;
        $(".batch-unmatched-product").each(function() {
            var $cell = $(this);
            var invIdx = parseInt($cell.data("inv-idx"));
            var lineIdx = parseInt($cell.data("line-idx"));
            if (isNaN(invIdx) || !batchData[invIdx]) return;
            var line = batchData[invIdx].lines[lineIdx];
            if (line && !line.product_matched) {
                $cell.html(buildUnmatchedLineProductCell(line));
            }
        });
        $("#batch-import-table tbody tr[data-id]").each(function() {
            var $row = $(this);
            var idx = parseInt($row.data("inv-idx"));
            if (isNaN(idx) || !batchData[idx]) return;
            var inv = batchData[idx];
            var blockState = getRowBlockedState(inv);
            $row.find(".batch-products-cell").html(buildProductsHtml(inv, blockState, idx));
        });
    });

    $(document).on("change", ".batch-row-check", function() {
        var $row = $(this).closest("tr");
        var idx = $row.data("inv-idx");
        var $detailRow = $(".batch-detail-row[data-inv-idx=\'" + idx + "\']");
        if ($(this).is(":checked")) {
            $row.removeClass("batch-row-inactive");
            $detailRow.removeClass("batch-row-inactive");
        } else {
            $row.addClass("batch-row-inactive");
            $detailRow.addClass("batch-row-inactive");
        }
    });

    function executeBatchImport() {
        if (!batchData) return;

        var decisions = [];
        var autoCreateSuppliers = $("#batch-auto-create-suppliers").is(":checked");
        var autoCreateProducts = $("#batch-auto-create-products").is(":checked");
        var gusFetch = $("#batch-gus-fetch-suppliers").is(":checked");

        $("#batch-import-table tbody tr").each(function() {
            var $row = $(this);
            if (!$row.find(".batch-row-check").is(":checked")) return;

            decisions.push({
                id: parseInt($row.data("id")),
                socid: parseInt($row.data("socid")) || 0,
                auto_create_supplier: autoCreateSuppliers && $row.data("supplier-matched") !== "1" && parseInt($row.data("socid")) <= 0,
                auto_create_products: autoCreateProducts
            });
        });

        if (decisions.length === 0) return;

        var payload = {
            decisions: decisions,
            auto_create_suppliers: autoCreateSuppliers,
            auto_create_products: autoCreateProducts,
            fetch_suppliers: gusFetch
        };

        var supplierIds = [];
        if (gusFetch) {
            $.each(decisions, function(i, d) { if (d.auto_create_supplier) supplierIds.push(d.id); });
        }

        if (gusFetch && supplierIds.length > 0) {
            $("#batch-import-btn-confirm").prop("disabled", true);
            $.ajax({
                url: baseUrl + "?action=gus_supplier_preview&token=" + ajaxToken,
                method: "POST",
                contentType: "application/json",
                data: JSON.stringify({ ids: supplierIds }),
                dataType: "json",
                success: function(resp) {
                    $("#batch-import-btn-confirm").prop("disabled", false);
                    var trunc = (resp && resp.truncated) ? resp.truncated : 0;
                    if (resp && resp.status === "OK" && resp.overrides && resp.overrides.length > 0) {
                        showGusOverrideConfirm(resp.overrides, payload, trunc);
                    } else if (trunc > 0) {
                        if (confirm(langGusPreviewTruncated.replace("%s", trunc))) {
                            doExecuteBatchImport(payload);
                        }
                    } else {
                        doExecuteBatchImport(payload);
                    }
                },
                error: function() {
                    $("#batch-import-btn-confirm").prop("disabled", false);
                    if (confirm(langGusPreviewFailed)) {
                        doExecuteBatchImport(payload);
                    }
                }
            });
            return;
        }

        doExecuteBatchImport(payload);
    }

    function showGusOverrideConfirm(overrides, payload, truncated) {
        var h = "<div style=\"max-height:300px; overflow:auto;\"><p>" + escapeHtml(langGusOverrideIntro) + "</p>";
        if (truncated && truncated > 0) {
            h += "<p class=\"warning\">" + escapeHtml(langGusPreviewTruncated.replace("%s", truncated)) + "</p>";
        }
        $.each(overrides, function(i, o) {
            h += "<div style=\"margin-bottom:8px;\"><strong>" + escapeHtml(o.name) + "</strong> (NIP " + escapeHtml(o.nip) + ")<ul>";
            $.each(o.changes, function(j, c) {
                h += "<li>" + escapeHtml(c.label) + ": <span class=\"opacitymedium\">" + escapeHtml(c.from) + "</span> &rarr; " + escapeHtml(c.to) + "</li>";
            });
            h += "</ul></div>";
        });
        h += "</div>";

        $("#ksef-gus-override-dialog").remove();
        var $dlg = $("<div></div>").attr("id", "ksef-gus-override-dialog").attr("title", langGusOverrideTitle).html(h);
        $("body").append($dlg);
        $dlg.dialog({
            modal: true,
            width: 520,
            buttons: [
                { text: langGusOverrideProceed, click: function() { $(this).dialog("close"); doExecuteBatchImport(payload); } },
                { text: langGusCancel, click: function() { $(this).dialog("close"); } }
            ]
        });
    }

    function doExecuteBatchImport(payload) {
        $("#batch-import-btn-confirm").prop("disabled", true).text(langImporting);
        $("#batch-import-content").hide();
        $("#batch-import-loading").show();

        $.ajax({
            url: baseUrl + "?action=batch_import_execute&token=" + ajaxToken,
            method: "POST",
            contentType: "application/json",
            data: JSON.stringify(payload),
            dataType: "json",
            success: function(data) {
                $("#batch-import-loading").hide();
                $("#batch-import-btn-confirm").hide();

                var html = "<h3>" + langBatchResults + "</h3><ul>";
                if (data.results) {
                    $.each(data.results, function(i, r) {
                        if (r.status === "OK") {
                            html += \'<li><span class="badge badge-status4">OK</span> \' + escapeHtml(r.invoice_number || "#" + r.id) + \'</li>\';
                        } else {
                            html += \'<li><span class="badge badge-status8">\' + langError + \'</span> \' + escapeHtml(r.invoice_number || "#" + r.id) + \': \' + escapeHtml(r.error || "") + \'</li>\';
                        }
                    });
                }
                html += "</ul>";
                if (data.success_count > 0) {
                    html += \'<p><strong>\' + data.success_count + \' imported, \' + data.error_count + \' errors</strong></p>\';
                }

                $("#batch-import-results").html(html).show();

                if (data.success_count > 0) {
                    setTimeout(function() { location.reload(); }, 3000);
                }
            },
            error: function() {
                $("#batch-import-loading").hide();
                $("#batch-import-results").html("<p class=\"error\">Connection error</p>").show();
            }
        });
    }

    function escapeHtml(str) {
        if (!str) return "";
        var div = document.createElement("div");
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }
});
</script>';


llxFooter();
$db->close();