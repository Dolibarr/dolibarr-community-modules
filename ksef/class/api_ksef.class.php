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
 * \file    ksef/class/api_ksef.class.php
 * \ingroup ksef
 * \brief   REST API for KSeF module
 *
 * Submissions:
 *   GET    /submissions                        List submissions
 *   GET    /submissions/stats                  Statistics
 *   GET    /submissions/{id}                   Submission detail
 *   GET    /submissions/invoice/{invoice_id}   Lookup by invoice
 *   POST   /submissions/{invoice_id}/submit    Submit online
 *   POST   /submissions/{invoice_id}/submit-offline  Submit offline
 *   POST   /submissions/{invoice_id}/retry     Retry submission
 *   GET    /submissions/{invoice_id}/status    Poll status
 *   GET    /submissions/{invoice_id}/upo       Download UPO
 *   GET    /submissions/{invoice_id}/xml       Get FA(3) XML
 *
 * Incoming invoices:
 *   GET    /incoming                           List incoming
 *   GET    /incoming/{id}                      Detail
 *   GET    /incoming/{id}/xml                  Raw XML
 *   GET    /incoming/{id}/pdf                  PDF (base64)
 *   POST   /incoming/{id}/link                 Link supplier invoice
 *   DELETE /incoming/{id}                      Delete record
 *   POST   /incoming/sync                      Trigger sync
 *   GET    /incoming/sync/status               Poll sync
 *   POST   /incoming/sync/reset                Reset sync
 *
 * Credit notes:
 *   GET    /creditnotes/{id}/discounts         Discount rows
 *   POST   /creditnotes/{id}/convert           Convert to discounts
 *   POST   /creditnotes/{id}/apply-line        Apply as lines
 *   POST   /creditnotes/{id}/apply-payment     Apply as payment
 *   POST   /creditnotes/{id}/refund            Cash refund
 *   GET    /creditnotes/{id}/resolution-state  State + modes
 *   PUT    /submissions/{invoice_id}/correction  Set KOR fields
 *
 * Health / configuration:
 *   GET    /status                             Health check
 *   GET    /config                             Module settings
 *   POST   /test-connection                    Connectivity test
 */

use Luracast\Restler\RestException;

/**
 * KSeF REST API
 * @access protected
 * @class DolibarrApiAccess {@requires user,external}
 */
class KsefApi extends DolibarrApi
{
    const OP_CONVERT       = 'convert';
    const OP_REFUND        = 'refund';
    const OP_APPLY_LINE    = 'apply-line';
    const OP_APPLY_PAYMENT = 'apply-payment';

    const EPSILON = 0.01;
    private $creditNoteNotFound = false;
    private $creditNoteError = '';

    public function __construct()
    {
        global $db;
        $this->db = $db;
    }

    /**
     * @brief Loads module classes
     * @return void
     * @called_by checkKsefReadPermission(), checkKsefWritePermission()
     */
    private function loadKsefClasses()
    {
        static $loaded = false;
        if ($loaded) return;
        dol_include_once('/ksef/class/ksef_service.class.php');
        dol_include_once('/ksef/class/ksef_submission.class.php');
        dol_include_once('/ksef/class/ksef_incoming.class.php');
        dol_include_once('/ksef/class/ksef_client.class.php');
        dol_include_once('/ksef/class/ksef_sync_state.class.php');
        dol_include_once('/ksef/lib/ksef.lib.php');
        $loaded = true;
    }


    /**
     * @brief List submissions
     * @param  string $status        Filter by status (e.g. PENDING, ACCEPTED, REJECTED, FAILED, OFFLINE, TIMEOUT; not enforced) {@from query}
     * @param  string $environment   Filter by environment (e.g. TEST, DEMO, PRODUCTION; not enforced) {@from query}
     * @param  string $date_from     Filter: submitted after (YYYY-MM-DD) {@from query}
     * @param  string $date_to       Filter: submitted before (YYYY-MM-DD) {@from query}
     * @param  string $sortfield     Sort field (one of t.date_submission, t.status, t.environment, t.ksef_number, t.rowid; falls back to t.date_submission) {@from query}
     * @param  string $sortorder     Sort order ASC or DESC (defaults to DESC) {@from query}
     * @param  int    $limit         Limit number of results (0 = conf default) {@from query}
     * @param  int    $page          Page number (0-based) {@from query}
     * @return array List of formatSubmission() summary objects (full=false)
     * @calls formatSubmission()
     * @url GET /submissions
     * @throws RestException 400, 403, 500
     */
    public function getSubmissions(
        $status = '',
        $environment = '',
        $date_from = '',
        $date_to = '',
        $sortfield = 't.date_submission',
        $sortorder = 'DESC',
        $limit = 0,
        $page = 0
    ) {
        $this->checkKsefReadPermission();

        if (empty($limit)) {
            $limit = getDolGlobalInt('MAIN_SIZE_LISTE_LIMIT', 25);
        }
        $offset = $limit * $page;

        $sql = "SELECT t.rowid FROM " . MAIN_DB_PREFIX . "ksef_submissions as t";
        $sql .= " INNER JOIN " . MAIN_DB_PREFIX . "facture as f ON f.rowid = t.fk_facture";
        $sql .= " AND f.entity IN (" . getEntity('invoice') . ")";
        $sql .= " WHERE 1 = 1";

        if (!empty($status)) {
            $sql .= " AND t.status = '" . $this->db->escape($status) . "'";
        }
        if (!empty($environment)) {
            $sql .= " AND t.environment = '" . $this->db->escape($environment) . "'";
        }
        if (!empty($date_from)) {
            $ts = strtotime($date_from);
            if ($ts === false) {
                throw new RestException(400, 'Invalid date_from (expected YYYY-MM-DD)');
            }
            $sql .= " AND t.date_submission >= " . (int)$ts;
        }
        if (!empty($date_to)) {
            $ts = strtotime($date_to . ' 23:59:59');
            if ($ts === false) {
                throw new RestException(400, 'Invalid date_to (expected YYYY-MM-DD)');
            }
            $sql .= " AND t.date_submission <= " . (int)$ts;
        }

        $allowedSort = array(
            't.date_submission', 't.status', 't.environment',
            't.ksef_number', 't.rowid'
        );
        if (!in_array($sortfield, $allowedSort)) {
            $sortfield = 't.date_submission';
        }
        $sortorder = strtoupper($sortorder) === 'ASC' ? 'ASC' : 'DESC';
        $sql .= " ORDER BY " . $sortfield . " " . $sortorder;
        $sql .= $this->db->plimit($limit, $offset);

        $result = array();
        $resql = $this->db->query($sql);
        if (!$resql) {
            throw new RestException(500, 'Database error: ' . $this->db->lasterror());
        }

        while ($obj = $this->db->fetch_object($resql)) {
            $sub = new KsefSubmission($this->db);
            if ($sub->fetch($obj->rowid) > 0) {
                $result[] = $this->formatSubmission($sub);
            }
        }
        $this->db->free($resql);

        return $result;
    }


    /**
     * @brief Submission statistics
     * @param  int   $days  Number of days to include (default 30) {@from query}
     * @return array Statistics (total, accepted, pending, failed, success_rate, common_errors)
     * @url GET /submissions/stats
     * @throws RestException 403
     */
    public function getSubmissionStats($days = 30)
    {
        $this->checkKsefReadPermission();

        $sub = new KsefSubmission($this->db);
        return $sub->getStatistics((int)$days);
    }


    /**
     * @brief Submission detail by ID
     * @param  int   $id  Submission row ID {@from path}
     * @return array      formatSubmission() full output (includes fa3_xml, upo_xml, api_response)
     * @url GET /submissions/{id}
     * @throws RestException 403, 404
     */
    public function getSubmission($id)
    {
        $this->checkKsefReadPermission();

        $sub = new KsefSubmission($this->db);
        if ($sub->fetch((int)$id) <= 0) {
            throw new RestException(404, 'Submission not found');
        }
        try {
            $this->checkFactureAccess((int) $sub->fk_facture);
        } catch (RestException $e) {
            throw new RestException(404, 'Submission not found');
        }

        return $this->formatSubmission($sub, true);
    }


    /**
     * @brief Submission for an invoice
     * @param  int   $invoice_id  Invoice (facture) ID {@from path}
     * @return array              formatSubmission() full output (includes fa3_xml, upo_xml, api_response)
     * @url GET /submissions/invoice/{invoice_id}
     * @throws RestException 403, 404
     */
    public function getSubmissionByInvoice($invoice_id)
    {
        $this->checkKsefReadPermission();
        $this->checkFactureAccess((int)$invoice_id);

        $sub = new KsefSubmission($this->db);
        if ($sub->fetchByInvoice((int)$invoice_id) <= 0) {
            throw new RestException(404, 'No submission found for this invoice');
        }

        return $this->formatSubmission($sub, true);
    }


    /**
     * @brief Submit invoice online
     * @param  int   $invoice_id  Invoice (facture) ID {@from path}
     * @return array              Result with status, ksef_number, etc.
     * @url POST /submissions/{invoice_id}/submit
     * @throws RestException 403, 404, 500, 502
     */
    public function submitInvoice($invoice_id)
    {
        $this->checkKsefWritePermission();
        $invoice = $this->checkFactureAccess((int) $invoice_id);
        dol_syslog("KsefApi::submitInvoice invoice_id=$invoice_id", LOG_INFO);

        $this->ensureInvoiceNbpRate($invoice);

        $ksef = new KsefService($this->db);
        $result = $ksef->submitInvoice(
            (int)$invoice_id,
            DolibarrApiAccess::$user,
            'SYNC'
        );

        if (!$result) {
            throw new RestException(500, $ksef->error ?: 'Submission failed');
        }

        return $result;
    }


    /**
     * @brief Submit invoice offline
     * @param  int    $invoice_id     Invoice (facture) ID {@from path}
     * @param  string $reason         Reason for offline mode {@from body}
     * @return array                  Result with status, offline_deadline, etc.
     * @url POST /submissions/{invoice_id}/submit-offline
     * @throws RestException 403, 404, 422, 500, 502
     */
    public function submitInvoiceOffline($invoice_id, $reason = 'api_request')
    {
        $this->checkKsefWritePermission();
        $invoice = $this->checkFactureAccess((int) $invoice_id);
        dol_syslog("KsefApi::submitInvoiceOffline invoice_id=$invoice_id reason=$reason", LOG_INFO);

        $this->ensureInvoiceNbpRate($invoice);

        $ksef = new KsefService($this->db);
        $result = $ksef->submitInvoiceOffline(
            (int)$invoice_id,
            DolibarrApiAccess::$user,
            $reason
        );

        if (!$result) {
            throw new RestException(500, $ksef->error ?: 'Offline submission failed');
        }
        if ($result['status'] === 'FAILED') {
            $code = !empty($result['needs_certificate']) ? 422 : 500;
            throw new RestException($code, $result['error']);
        }

        return $result;
    }


    /**
     * @brief Retry failed submission
     * @param  int   $invoice_id  Invoice (facture) ID {@from path}
     * @return array              Result with status
     * @url POST /submissions/{invoice_id}/retry
     * @throws RestException 403, 404, 500
     */
    public function retrySubmission($invoice_id)
    {
        $this->checkKsefWritePermission();
        $this->checkFactureAccess((int) $invoice_id);
        dol_syslog("KsefApi::retrySubmission invoice_id=$invoice_id", LOG_INFO);

        $ksef = new KsefService($this->db);
        $result = $ksef->retrySubmission(
            (int)$invoice_id,
            DolibarrApiAccess::$user
        );

        if (!$result) {
            throw new RestException(500, $ksef->error ?: 'Retry failed');
        }

        return $result;
    }


    /**
     * @brief Check status at KSeF
     * @param  int   $invoice_id  Invoice (facture) ID {@from path}
     * @return array              Status result
     * @url GET /submissions/{invoice_id}/status
     * @throws RestException 403 Access denied
     * @throws RestException 404 Not found
     * @throws RestException 409 No KSeF reference yet
     * @throws RestException 503 Gateway unavailable
     */
    public function checkSubmissionStatus($invoice_id)
    {
        $this->checkKsefReadPermission();
        $this->checkFactureAccess((int)$invoice_id);

        $sub = new KsefSubmission($this->db);
        if ($sub->fetchByInvoice((int)$invoice_id) <= 0) {
            throw new RestException(404, 'No submission found for invoice ' . (int)$invoice_id);
        }

        $ksef = new KsefService($this->db);
        $result = $ksef->checkStatus((int)$invoice_id, DolibarrApiAccess::$user);

        if ($result === false) {
            if ($ksef->failureReason === 'noref') {
                throw new RestException(409, $ksef->error ?: 'Submission has no KSeF reference yet');
            }
            throw new RestException(503, $ksef->error ?: 'KSeF gateway unavailable');
        }

        return $result;
    }


    /**
     * @brief Download UPO confirmation
     * @param  int   $invoice_id  Invoice (facture) ID {@from path}
     * @return array              UPO XML content and metadata
     * @url GET /submissions/{invoice_id}/upo
     * @throws RestException 403, 404
     */
    public function getSubmissionUpo($invoice_id)
    {
        $this->checkKsefReadPermission();
        $this->checkFactureAccess((int)$invoice_id);

        $sub = new KsefSubmission($this->db);
        if ($sub->fetchByInvoice((int)$invoice_id) <= 0) {
            throw new RestException(404, 'No submission found for this invoice');
        }

        if (empty($sub->upo_xml)) {
            if ($sub->status === KsefSubmission::STATUS_ACCEPTED
                && !empty($sub->ksef_number)
            ) {
                global $conf;
                $client = new KsefClient(
                    $this->db,
                    getDolGlobalString('KSEF_ENVIRONMENT', 'TEST')
                );
                try {
                    $upo = $client->downloadUPO($sub->ksef_number);
                    if ($upo) {
                        $sub->upo_xml = $upo;
                        $sub->update(DolibarrApiAccess::$user, 1);
                    }
                } catch (Exception $e) {}
            }

            if (empty($sub->upo_xml)) {
                throw new RestException(404, 'UPO not available yet');
            }
        }

        return array(
            'invoice_id'  => (int)$sub->fk_facture,
            'ksef_number' => $sub->ksef_number,
            'upo_xml'     => $sub->upo_xml,
        );
    }


    /**
     * @brief Get submission FA(3) XML
     * @param  int   $invoice_id  Invoice (facture) ID {@from path}
     * @return array              FA3 XML content and metadata
     * @url GET /submissions/{invoice_id}/xml
     * @throws RestException 403, 404
     */
    public function getSubmissionXml($invoice_id)
    {
        $this->checkKsefReadPermission();
        $this->checkFactureAccess((int)$invoice_id);

        $sub = new KsefSubmission($this->db);
        if ($sub->fetchByInvoice((int)$invoice_id) <= 0) {
            throw new RestException(404, 'No submission found for this invoice');
        }

        if (empty($sub->fa3_xml)) {
            throw new RestException(404, 'No FA(3) XML stored for this submission');
        }

        return array(
            'invoice_id'       => (int)$sub->fk_facture,
            'ksef_number'      => $sub->ksef_number,
            'status'           => $sub->status,
            'fa3_xml'          => $sub->fa3_xml,
            'fa3_creation_date' => $sub->fa3_creation_date,
            'invoice_hash'     => $sub->invoice_hash,
        );
    }


    /**
     * @brief List incoming invoices
     * @param  string $seller_nip       Filter by seller NIP {@from query}
     * @param  string $seller_name      Filter by seller name (partial) {@from query}
     * @param  string $invoice_number   Filter by invoice number (partial) {@from query}
     * @param  string $ksef_number      Filter by KSeF number (partial) {@from query}
     * @param  string $import_status    Filter by import status (NEW, IMPORTED, ERROR) {@from query}
     * @param  string $environment      Filter by environment {@from query}
     * @param  string $date_from        Filter: invoice date from (YYYY-MM-DD) {@from query}
     * @param  string $date_to          Filter: invoice date to (YYYY-MM-DD) {@from query}
     * @param  string $sortfield        Sort field {@from query}
     * @param  string $sortorder        Sort order ASC or DESC {@from query}
     * @param  int    $limit            Limit number of results {@from query}
     * @param  int    $page             Page number (0-based) {@from query}
     * @return array                    List of incoming invoice objects
     * @url GET /incoming
     * @throws RestException 400, 403, 500
     */
    public function getIncoming(
        $seller_nip = '',
        $seller_name = '',
        $invoice_number = '',
        $ksef_number = '',
        $import_status = '',
        $environment = '',
        $date_from = '',
        $date_to = '',
        $sortfield = 'i.invoice_date',
        $sortorder = 'DESC',
        $limit = 0,
        $page = 0
    ) {
        $this->checkKsefReadPermission();

        if (empty($limit)) {
            $limit = getDolGlobalInt('MAIN_SIZE_LISTE_LIMIT', 25);
        }
        $offset = $limit * $page;

        $filters = array();
        if (!empty($seller_nip)) {
            $filters['seller_nip'] = $seller_nip;
        }
        if (!empty($seller_name)) {
            $filters['seller_name'] = $seller_name;
        }
        if (!empty($invoice_number)) {
            $filters['invoice_number'] = $invoice_number;
        }
        if (!empty($ksef_number)) {
            $filters['ksef_number'] = $ksef_number;
        }
        if (!empty($import_status)) {
            $filters['import_status'] = $import_status;
        }
        if (!empty($environment)) {
            $filters['environment'] = $environment;
        }
        if (!empty($date_from)) {
            $ts = strtotime($date_from);
            if ($ts === false) {
                throw new RestException(400, 'Invalid date_from (expected YYYY-MM-DD)');
            }
            $filters['invoice_date_start'] = $ts;
        }
        if (!empty($date_to)) {
            $ts = strtotime($date_to . ' 23:59:59');
            if ($ts === false) {
                throw new RestException(400, 'Invalid date_to (expected YYYY-MM-DD)');
            }
            $filters['invoice_date_end'] = $ts;
        }

        $allowedSort = array(
            'i.invoice_date', 'i.fetch_date', 'i.seller_name',
            'i.total_gross', 'i.import_status', 'i.rowid'
        );
        if (!in_array($sortfield, $allowedSort)) {
            $sortfield = 'i.invoice_date';
        }
        $sortorder = strtoupper($sortorder) === 'ASC' ? 'ASC' : 'DESC';

        $incoming = new KsefIncoming($this->db);
        $records = $incoming->fetchAll(
            $filters, $sortfield, $sortorder, (int)$limit, (int)$offset
        );

        if ($records === -1) {
            throw new RestException(500, 'Database error: ' . $incoming->error);
        }

        $result = array();
        foreach ($records as $rec) {
            $result[] = $this->formatIncoming($rec);
        }

        return $result;
    }


    /**
     * @brief Incoming invoice detail
     * @param  int   $id  Incoming invoice row ID {@from path}
     * @return array      Incoming invoice object with line_items and vat_summary
     * @url GET /incoming/{id}
     * @throws RestException 403, 404, 500
     */
    public function getIncomingById($id)
    {
        $this->checkKsefReadPermission();

        $incoming = new KsefIncoming($this->db);
        $res = $incoming->fetch((int)$id);

        if ($res < 0) {
            throw new RestException(500, 'Database error');
        }
        if ($res == 0) {
            throw new RestException(404, 'Incoming invoice not found');
        }

        $data = $this->formatIncoming($incoming);
        $data['line_items'] = $incoming->getLineItems();
        $data['vat_summary'] = $incoming->getVatSummary();

        return $data;
    }


    /**
     * @brief Raw incoming FA(3) XML
     * @param  int   $id  Incoming invoice row ID {@from path}
     * @return array      XML content
     * @url GET /incoming/{id}/xml
     * @throws RestException 403, 404
     */
    public function getIncomingXml($id)
    {
        $this->checkKsefReadPermission();

        $incoming = new KsefIncoming($this->db);
        $res = $incoming->fetch((int)$id);

        if ($res <= 0) {
            throw new RestException(404, 'Incoming invoice not found');
        }
        if (empty($incoming->fa3_xml)) {
            throw new RestException(404, 'No FA(3) XML stored for this invoice');
        }

        return array(
            'id'          => (int)$incoming->rowid,
            'ksef_number' => $incoming->ksef_number,
            'fa3_xml'     => $incoming->fa3_xml,
        );
    }


    /**
     * @brief Incoming invoice PDF
     * @param  int   $id  Incoming invoice row ID {@from path}
     * @return array      PDF content (base64-encoded) and metadata
     * @url GET /incoming/{id}/pdf
     * @throws RestException 403, 404, 500
     */
    public function getIncomingPdf($id)
    {
        $this->checkKsefReadPermission();

        $incoming = new KsefIncoming($this->db);
        $res = $incoming->fetch((int)$id);

        if ($res <= 0) {
            throw new RestException(404, 'Incoming invoice not found');
        }

        $pdfContent = $incoming->generatePdfVisualization();
        if ($pdfContent === false) {
            throw new RestException(500, $incoming->error ?: 'PDF generation failed');
        }

        return array(
            'id'           => (int)$incoming->rowid,
            'ksef_number'  => $incoming->ksef_number,
            'filename'     => 'ksef_' . $incoming->ksef_number . '.pdf',
            'content_type' => 'application/pdf',
            'encoding'     => 'base64',
            'content'      => base64_encode($pdfContent),
        );
    }


    /**
     * @brief Trigger incoming sync
     * @return array  Sync initiation result with reference number
     * @url POST /incoming/sync
     * @throws RestException 403, 409, 500
     */
    public function triggerIncomingSync()
    {
        $this->checkKsefWritePermission();
        dol_syslog("KsefApi::triggerIncomingSync", LOG_INFO);

        $ksef = new KsefService($this->db);
        $result = $ksef->initIncomingFetch(DolibarrApiAccess::$user);

        if ($result === false) {
            throw new RestException(500, $ksef->error ?: 'Failed to initiate sync');
        }

        if ($result['status'] === 'ALREADY_PROCESSING') {
            throw new RestException(409, 'Sync already in progress');
        }

        return $result;
    }


    /**
     * @brief Incoming sync progress
     * @return array  Sync status with progress details
     * @url GET /incoming/sync/status
     * @throws RestException 403
     */
    public function getIncomingSyncStatus()
    {
        $this->checkKsefReadPermission();

        $ksef = new KsefService($this->db);
        $syncState = $ksef->getIncomingSyncState();

        $data = array(
            'is_fetching'    => $syncState->isFetchInProgress(),
            'is_processing'  => $syncState->isProcessingInProgress(),
            'is_rate_limited' => $syncState->isRateLimited(),
            'can_sync_now'   => $syncState->canSyncNow(),
            'fetch_reference' => $syncState->fetch_reference ?: null,
            'fetch_status'   => $syncState->fetch_status ?: null,
            'fetch_started'  => $syncState->fetch_started ?: null,
            'fetch_error'    => $syncState->fetch_error ?: null,
            'hwm_date'       => $syncState->hwm_date ?: null,
            'last_sync'      => $syncState->last_sync ?: null,
            'last_sync_new'  => (int)$syncState->last_sync_new,
            'last_sync_existing' => (int)$syncState->last_sync_existing,
            'last_sync_total' => (int)$syncState->last_sync_total,
        );

        if ($syncState->isProcessingInProgress()) {
            $data['processing_progress'] = $syncState->getProcessingProgress();
        }

        if ($syncState->isRateLimited()) {
            $data['rate_limit_until'] = $syncState->rate_limit_until;
            $data['rate_limit_seconds'] = $syncState->secondsUntilNextSync();
        }

        if ($syncState->isFetchInProgress() && !$syncState->isFetchTimedOut()) {
            $ksef2 = new KsefService($this->db);
            $pollResult = $ksef2->checkIncomingFetchStatus(DolibarrApiAccess::$user);
            if ($pollResult) {
                $data['poll_result'] = $pollResult;
            }
        }

        return $data;
    }


    /**
     * @brief Reset stuck sync state
     * @param  int   $days_back  Reset HWM to this many days ago (default: keep current) {@from body}
     * @return array             Result
     * @url POST /incoming/sync/reset
     * @throws RestException 403, 500
     */
    public function resetIncomingSync($days_back = 0)
    {
        $this->checkKsefWritePermission();
        dol_syslog("KsefApi::resetIncomingSync days_back=$days_back", LOG_INFO);

        $ksef = new KsefService($this->db);

        if ($days_back > 0) {
            $result = $ksef->resetIncomingSyncState(
                DolibarrApiAccess::$user,
                (int)$days_back
            );
        } else {
            $result = $ksef->resetIncomingFetch();
        }

        if (!$result) {
            throw new RestException(500, 'Failed to reset sync state');
        }

        return array(
            'success' => true,
            'message' => $days_back > 0
                ? "Sync state reset, HWM moved back {$days_back} days"
                : 'Fetch state cleared',
        );
    }


    /**
     * @brief Delete incoming record
     * @param  int   $id  Incoming invoice row ID {@from path}
     * @return array      Result
     * @url DELETE /incoming/{id}
     * @throws RestException 403, 404, 500
     */
    public function deleteIncoming($id)
    {
        $this->checkKsefWritePermission();
        dol_syslog("KsefApi::deleteIncoming id=$id", LOG_INFO);

        $incoming = new KsefIncoming($this->db);
        $res = $incoming->fetch((int)$id);

        if ($res <= 0) {
            throw new RestException(404, 'Incoming invoice not found');
        }

        $delResult = $incoming->delete(DolibarrApiAccess::$user);
        if ($delResult < 0) {
            throw new RestException(500, 'Delete failed: ' . $incoming->error);
        }

        return array(
            'success' => true,
            'message' => 'Incoming invoice deleted',
        );
    }

    /**
     * @brief Link incoming to supplier invoice
     * @param  int   $id                Incoming invoice row ID {@from path}
     * @param  int   $fk_facture_fourn  Existing supplier invoice ID to link {@from body}
     * @return array                    Result
     * @url POST /incoming/{id}/link
     * @throws RestException 403, 404, 422, 500
     */
    public function linkIncoming($id, $fk_facture_fourn = 0)
    {
        $this->checkKsefWritePermission();
        dol_syslog("KsefApi::linkIncoming id=$id fk_facture_fourn=$fk_facture_fourn", LOG_INFO);

        if ($fk_facture_fourn <= 0) {
            throw new RestException(
                422,
                'fk_facture_fourn is required (supplier invoice ID)'
            );
        }

        $incoming = new KsefIncoming($this->db);
        $res = $incoming->fetch((int)$id);

        if ($res <= 0) {
            throw new RestException(404, 'Incoming invoice not found');
        }

        if (!DolibarrApiAccess::$user->hasRight('fournisseur', 'facture', 'lire')) {
            throw new RestException(403, 'Not enough permissions to read supplier invoices (fournisseur/facture/lire)');
        }

        require_once DOL_DOCUMENT_ROOT . '/fourn/class/fournisseur.facture.class.php';
        $supInvoice = new FactureFournisseur($this->db);
        if ($supInvoice->fetch((int)$fk_facture_fourn) <= 0) {
            throw new RestException(
                404,
                'Supplier invoice #' . $fk_facture_fourn . ' not found'
            );
        }
        if (!DolibarrApi::_checkAccessToResource('fournisseur', $supInvoice->id, 'facture_fourn', 'facture')) {
            throw new RestException(403, 'Access not allowed for login ' . DolibarrApiAccess::$user->login);
        }

        $incoming->fk_facture_fourn = (int)$fk_facture_fourn;
        $incoming->import_status = KsefIncoming::STATUS_IMPORTED;
        $incoming->import_date = dol_now();
        $incoming->import_error = null;

        $updResult = $incoming->update(DolibarrApiAccess::$user, 1);
        if ($updResult < 0) {
            throw new RestException(500, 'Failed to update: ' . $incoming->error);
        }

        return array(
            'success'          => true,
            'incoming_id'      => (int)$incoming->rowid,
            'fk_facture_fourn' => (int)$fk_facture_fourn,
            'import_status'    => KsefIncoming::STATUS_IMPORTED,
        );
    }


    /**
     * @brief Credit note discount rows
     * @param  int    $id     Credit note (facture) ID {@from path}
     * @param  string $state  Row state filter, one of available (unlinked), used (linked to an invoice or line) or none (all rows); default none {@from query}
     * @return array          One row per VAT rate, each with keys: id (discount rowid), tva_tx (absolute VAT rate), amount_ht, amount_tva, amount_ttc (absolute amounts), description, fk_facture (linked invoice id or null), fk_facture_line (linked line id or null), used (bool, true when linked)
     * @url GET /creditnotes/{id}/discounts
     * @throws RestException 400, 403, 404, 409, 500
     */
    public function getCreditNoteDiscounts($id, $state = 'none')
    {
        $this->checkKsefReadPermission();
        $invoice = $this->checkFactureAccess((int) $id);
        if ((int) $invoice->type !== Facture::TYPE_CREDIT_NOTE) {
            throw new RestException(409, 'Invoice ' . (int) $id . ' is not a credit note');
        }

        $sql = "SELECT rowid, tva_tx, amount_ht, amount_tva, amount_ttc, description, fk_facture, fk_facture_line";
        $sql .= " FROM " . MAIN_DB_PREFIX . "societe_remise_except";
        $sql .= " WHERE fk_facture_source = " . (int) $id;
        $sql .= " AND entity IN (" . getEntity('invoice') . ")";

        $state = strtolower((string) $state);
        if ($state === 'available') {
            $sql .= " AND fk_facture IS NULL AND fk_facture_line IS NULL";
        } elseif ($state === 'used') {
            $sql .= " AND (fk_facture IS NOT NULL OR fk_facture_line IS NOT NULL)";
        } elseif ($state !== 'none' && $state !== '') {
            throw new RestException(400, "Invalid state filter '" . $state . "' (expected available, used or none)");
        }
        $sql .= " ORDER BY tva_tx ASC, rowid ASC";

        $resql = $this->db->query($sql);
        if (!$resql) {
            throw new RestException(500, 'Error querying discounts: ' . $this->db->lasterror());
        }

        $rows = array();
        while ($obj = $this->db->fetch_object($resql)) {
            $rows[] = array(
                'id'              => (int) $obj->rowid,
                'tva_tx'          => abs((float) $obj->tva_tx),
                'amount_ht'       => abs((float) $obj->amount_ht),
                'amount_tva'      => abs((float) $obj->amount_tva),
                'amount_ttc'      => abs((float) $obj->amount_ttc),
                'description'     => $obj->description,
                'fk_facture'      => $obj->fk_facture !== null ? (int) $obj->fk_facture : null,
                'fk_facture_line' => $obj->fk_facture_line !== null ? (int) $obj->fk_facture_line : null,
                'used'            => ($obj->fk_facture !== null || $obj->fk_facture_line !== null),
            );
        }
        $this->db->free($resql);

        return $rows;
    }


    /**
     * @brief Convert credit note to discounts
     * @param  int $id  Credit note (facture) ID, must be validated {@from path}
     * @return array    Result with keys: converted (bool, always true on success), already_converted (bool, true when discount rows already existed and none were created), discount_rows (array of resulting discount rows, one per VAT rate)
     * @url POST /creditnotes/{id}/convert
     * @throws RestException 403, 404, 409, 500
     */
    public function convertCreditNote($id)
    {
        $this->checkKsefWritePermission();
        $note = $this->checkFactureAccess((int) $id);
        if (!DolibarrApiAccess::$user->hasRight('facture', 'creer')) {
            throw new RestException(403, 'Not enough permissions (facture/creer)');
        }
        if ((int) $note->type !== Facture::TYPE_CREDIT_NOTE) {
            throw new RestException(409, 'Only credit notes can be converted here; replacement money is trigger-driven');
        }
        if ((int) $note->statut <= 0) {
            throw new RestException(409, 'Credit note must be validated before conversion');
        }

        require_once DOL_DOCUMENT_ROOT . '/core/class/discount.class.php';

        $this->lockNoteForSettlement((int) $id);
        $state = $this->computeCreditNoteState((int) $id);
        if ($state === false) {
            $this->db->rollback();
            throw new RestException($this->creditNoteNotFound ? 404 : 500, $this->creditNoteError);
        }

        if ($state['converted_ttc'] > self::EPSILON) {
            $this->db->rollback();
            return array('converted' => true, 'already_converted' => true, 'discount_rows' => $state['discount_rows']);
        }
        try {
            $this->settleGuard($state, self::OP_CONVERT);
        } catch (Exception $e) {
            $this->db->rollback();
            throw $e;
        }

        $note->fetch_lines();
        $amount_ht = $amount_tva = $amount_ttc = array();
        $mc_ht = $mc_tva = $mc_ttc = array();
        foreach ($note->lines as $line) {
            if ($line->product_type >= 9
                || ((float) $line->total_ht == 0.0 && (float) $line->total_tva == 0.0 && (float) $line->total_ttc == 0.0)) {
                continue;
            }
            $r = (string) $line->tva_tx;
            if (!isset($amount_ht[$r])) {
                $amount_ht[$r] = $amount_tva[$r] = $amount_ttc[$r] = 0.0;
                $mc_ht[$r] = $mc_tva[$r] = $mc_ttc[$r] = 0.0;
            }
            $amount_ht[$r]  += (float) $line->total_ht;
            $amount_tva[$r] += (float) $line->total_tva;
            $amount_ttc[$r] += (float) $line->total_ttc;
            $mc_ht[$r]  += (float) $line->multicurrency_total_ht;
            $mc_tva[$r] += (float) $line->multicurrency_total_tva;
            $mc_ttc[$r] += (float) $line->multicurrency_total_ttc;
        }

        if (!empty($amount_ttc)) {
            $maxRate = null;
            $maxAbs = -1.0;
            foreach ($amount_ttc as $r => $v) {
                if (abs($v) > $maxAbs) {
                    $maxAbs = abs($v);
                    $maxRate = $r;
                }
            }
            $reconcile = function ($header, &$bucket) use ($maxRate) {
                $residue = (float) $header - array_sum($bucket);
                if (abs($residue) < 1.0) {
                    $bucket[$maxRate] += $residue;
                }
            };
            $reconcile($note->total_ht ?? 0, $amount_ht);
            $reconcile($note->total_tva ?? 0, $amount_tva);
            $reconcile($note->total_ttc ?? 0, $amount_ttc);
            $reconcile($note->multicurrency_total_ht ?? 0, $mc_ht);
            $reconcile($note->multicurrency_total_tva ?? 0, $mc_tva);
            $reconcile($note->multicurrency_total_ttc ?? 0, $mc_ttc);
        }

        $recheck = $this->computeCreditNoteState((int) $id);
        if ($recheck !== false && $recheck['converted_ttc'] > self::EPSILON) {
            $this->db->rollback();
            return array('converted' => true, 'already_converted' => true, 'discount_rows' => $recheck['discount_rows']);
        }

        foreach ($amount_ht as $r => $ignore) {
            $discount = new DiscountAbsolute($this->db);
            $discount->description = '(CREDIT_NOTE)';
            $discount->fk_soc = $note->socid;
            $discount->socid = $note->socid;
            $discount->fk_facture_source = $note->id;
            $discount->amount_ht = $discount->total_ht = abs($amount_ht[$r]);
            $discount->amount_tva = $discount->total_tva = abs($amount_tva[$r]);
            $discount->amount_ttc = $discount->total_ttc = abs($amount_ttc[$r]);
            $discount->multicurrency_amount_ht = $discount->multicurrency_total_ht = abs($mc_ht[$r]);
            $discount->multicurrency_amount_tva = $discount->multicurrency_total_tva = abs($mc_tva[$r]);
            $discount->multicurrency_amount_ttc = $discount->multicurrency_total_ttc = abs($mc_ttc[$r]);
            $discount->tva_tx = abs((float) $r);
            if ($discount->create(DolibarrApiAccess::$user) < 0) {
                $this->db->rollback();
                throw new RestException(500, 'Could not create discount row: ' . $discount->error);
            }
        }
        if ($note->setPaid(DolibarrApiAccess::$user) < 0) {
            $this->db->rollback();
            throw new RestException(500, 'Could not set the credit note paid: ' . $note->error);
        }
        $this->db->commit();

        $after = $this->computeCreditNoteState((int) $id);
        return array('converted' => true, 'already_converted' => false, 'discount_rows' => $after ? $after['discount_rows'] : array());
    }

    /**
     * @brief Apply credit note as lines
     * @param  int $id                Credit note (facture) ID, must be validated {@from path}
     * @param  int $target_invoice_id Target invoice ID, must be a DRAFT in the same currency; discount rows are inserted as invoice lines {@from body}
     * @return array                  Result with keys: applied (array of applied discount row ids), target (object with id, remaining_after (remain-to-pay after applying), paye (0/1 paid flag))
     * @url POST /creditnotes/{id}/apply-line
     * @throws RestException 400, 403, 404, 405, 409, 500
     */
    public function applyCreditNoteLine($id, $target_invoice_id = 0)
    {
        return $this->applyCreditNote((int) $id, (int) $target_invoice_id, 'apply-line');
    }

    /**
     * @brief Apply credit note as payment
     * @param  int $id                Credit note (facture) ID, must be validated {@from path}
     * @param  int $target_invoice_id Target invoice ID, must be a VALIDATED invoice in the same currency; the credit is linked as a payment {@from body}
     * @param  int $closeifpaid       1 to set the target paid when its remain-to-pay reaches zero, 0 to leave it open; default 1 {@from query}
     * @return array                  Result with keys: applied (array of applied discount row ids), target (object with id, remaining_after (remain-to-pay after applying), paye (0/1 paid flag))
     * @url POST /creditnotes/{id}/apply-payment
     * @throws RestException 400, 403, 404, 409, 500
     */
    public function applyCreditNotePayment($id, $target_invoice_id = 0, $closeifpaid = 1)
    {
        return $this->applyCreditNote((int) $id, (int) $target_invoice_id, 'apply-payment', (int) $closeifpaid);
    }

    /**
     * @brief Shared apply implementation
     * @param  int    $id        Credit note ID
     * @param  int    $targetId  Target invoice ID
     * @param  string $op        Apply op
     * @param  int    $closeifpaid  Auto-close zeroed target
     * @return array Apply result
     * @throws RestException 400, 403, 404, 405, 409, 500
     */
    private function applyCreditNote($id, $targetId, $op, $closeifpaid = 1)
    {
        $this->checkKsefWritePermission();
        $note = $this->checkFactureAccess($id);
        $rightNeeded = ($op === self::OP_APPLY_LINE) ? 'creer' : 'paiement';
        if (!DolibarrApiAccess::$user->hasRight('facture', $rightNeeded)) {
            throw new RestException(403, 'Not enough permissions (facture/' . $rightNeeded . ')');
        }
        if ((int) $note->type !== Facture::TYPE_CREDIT_NOTE) {
            throw new RestException(409, 'Only credit notes can be applied here');
        }
        if ((int) $note->statut <= 0) {
            throw new RestException(409, 'Credit note must be validated to apply its credit');
        }
        if (empty($targetId)) {
            throw new RestException(400, 'target_invoice_id is mandatory');
        }
        $target = $this->checkFactureAccess($targetId);
        $baseCurrency = getDolGlobalString('MAIN_MONNAIE', '');
        $noteCurrency = $note->multicurrency_code !== '' ? $note->multicurrency_code : $baseCurrency;
        $targetCurrency = $target->multicurrency_code !== '' ? $target->multicurrency_code : $baseCurrency;
        if ((string) $noteCurrency !== (string) $targetCurrency) {
            throw new RestException(409, 'Credit note and target invoice must be in the same currency');
        }

        require_once DOL_DOCUMENT_ROOT . '/core/class/discount.class.php';

        $this->lockFacturesForSettlement(array($id, $targetId));
        if ($target->fetch((int) $targetId) <= 0) {
            $this->db->rollback();
            throw new RestException(404, 'Target invoice not found');
        }
        $state = $this->computeCreditNoteState($id);
        if ($state === false) {
            $this->db->rollback();
            throw new RestException($this->creditNoteNotFound ? 404 : 500, $this->creditNoteError);
        }
        try {
            $this->settleGuard($state, $op, $this->buildTargetInfo($target));
        } catch (Exception $e) {
            $this->db->rollback();
            throw $e;
        }

        $applied = array();
        foreach ($state['discount_rows'] as $row) {
            if ($row['used']) {
                continue;
            }
            if ($op === self::OP_APPLY_LINE) {
                if ($target->insert_discount($row['id']) < 0) {
                    $this->db->rollback();
                    throw new RestException(500, 'Could not apply discount line: ' . $target->error);
                }
            } else {
                $discount = new DiscountAbsolute($this->db);
                if ($discount->fetch($row['id']) <= 0) {
                    $this->db->rollback();
                    throw new RestException(500, 'Discount row not found: ' . $row['id']);
                }
                if ($discount->link_to_invoice(0, $target->id) < 0) {
                    $this->db->rollback();
                    throw new RestException(500, 'Could not apply credit as payment: ' . $discount->error);
                }
            }
            $applied[] = (int) $row['id'];
        }

        if ($op === self::OP_APPLY_PAYMENT && $closeifpaid) {
            $target->fetch($target->id);
            if ((float) $target->getRemainToPay() <= self::EPSILON && (int) $target->paye !== 1) {
                if ($target->setPaid(DolibarrApiAccess::$user) < 0) {
                    $this->db->rollback();
                    throw new RestException(500, 'Could not set target paid: ' . $target->error);
                }
            }
        }
        $this->db->commit();

        $target->fetch($target->id);
        return array(
            'applied' => $applied,
            'target'  => array(
                'id'              => (int) $target->id,
                'remaining_after' => (float) $target->getRemainToPay(),
                'paye'            => (int) $target->paye,
            ),
        );
    }

    /**
     * @brief Cash refund a credit note
     * @param  int    $id           Credit note (facture) ID, must be validated {@from path}
     * @param  float  $amount       Refund amount, positive; capped at the remaining refundable amount {@from body}
     * @param  int    $paymentid    Payment mode id (c_paiement.id), mandatory {@from body}
     * @param  int    $accountid    Bank account id (used when the bank module is enabled) {@from body}
     * @param  string $datepaye     Payment date (YYYY-MM-DD or a unix timestamp); defaults to now when empty {@from body}
     * @param  string $num_payment  Payment reference / transaction number {@from body}
     * @param  string $comment      Private note stored on the payment {@from body}
     * @return array                Result with keys: payment_id (created payment id) and remaining_refundable_after (refundable amount left after this refund, or null if it could not be recomputed)
     * @url POST /creditnotes/{id}/refund
     * @throws RestException 400, 403, 404, 409, 500
     */
    public function refundCreditNote($id, $amount = 0, $paymentid = 0, $accountid = 0, $datepaye = '', $num_payment = '', $comment = '')
    {
        $this->checkKsefWritePermission();
        $note = $this->checkFactureAccess((int) $id);
        if (!DolibarrApiAccess::$user->hasRight('facture', 'paiement')) {
            throw new RestException(403, 'Not enough permissions (facture/paiement)');
        }
        if ((int) $note->type !== Facture::TYPE_CREDIT_NOTE) {
            throw new RestException(409, 'Only credit notes can be refunded here; replacement money is trigger-driven');
        }
        if ((int) $note->statut <= 0) {
            throw new RestException(409, 'Credit note must be validated before it can be refunded');
        }
        $amount = (float) $amount;
        if (empty($paymentid)) {
            throw new RestException(400, 'paymentid is mandatory');
        }

        $paiementcode = (string) dol_getIdFromCode($this->db, (int) $paymentid, 'c_paiement', 'id', 'code', 1);
        if ($paiementcode === '' || $paiementcode === '-1') {
            throw new RestException(400, 'paymentid does not match a known payment mode');
        }

        if (isModEnabled('bank') && !empty($accountid)) {
            require_once DOL_DOCUMENT_ROOT . '/compta/bank/class/account.class.php';
            $bankAcc = new Account($this->db);
            if ($bankAcc->fetch((int) $accountid) <= 0) {
                throw new RestException(404, 'Bank account not found or not allowed for this entity');
            }
        }

        if ($datepaye === '' || $datepaye === null) {
            $datepaye_ts = dol_now();
        } elseif (is_numeric($datepaye)) {
            $datepaye_ts = (int) $datepaye;
        } else {
            $datepaye_ts = dol_stringtotime($datepaye);
        }
        if ($datepaye_ts <= 0) {
            throw new RestException(400, 'datepaye is not a valid date');
        }

        require_once DOL_DOCUMENT_ROOT . '/compta/paiement/class/paiement.class.php';

        $this->lockNoteForSettlement((int) $id);
        $state = $this->computeCreditNoteState((int) $id);
        if ($state === false) {
            $this->db->rollback();
            throw new RestException($this->creditNoteNotFound ? 404 : 500, $this->creditNoteError);
        }
        try {
            $this->settleGuard($state, self::OP_REFUND, null, $amount);
        } catch (Exception $e) {
            $this->db->rollback();
            throw $e;
        }

        $amount = min(abs((float) $amount), (float) $state['remaining_refundable']);
        if ((float) price2num($amount, 'MT') <= 0.0) {
            $this->db->rollback();
            throw new RestException(409, 'Nothing left to refund on this credit note');
        }

        $amounts = array((int) $id => (float) price2num(-1 * abs($amount), 'MT'));

        $recheck = $this->computeCreditNoteState((int) $id);
        if ($recheck !== false && (float) $recheck['converted_ttc'] > self::EPSILON) {
            $this->db->rollback();
            throw new RestException(409, 'Credit note was converted to credit; cannot also refund');
        }

        $payment = new Paiement($this->db);
        $payment->datepaye = $datepaye_ts;
        $payment->amounts = $amounts;
        $payment->paiementid = (int) $paymentid;
        $payment->paiementcode = $paiementcode;
        $payment->num_payment = $num_payment;
        $payment->note_private = $comment;

        $payment_id = $payment->create(DolibarrApiAccess::$user, 0);
        if ($payment_id < 0) {
            $this->db->rollback();
            throw new RestException(400, 'Payment error: ' . $payment->error);
        }
        if (isModEnabled('bank')) {
            $result = $payment->addPaymentToBank(DolibarrApiAccess::$user, 'payment', '(CustomerInvoicePaymentBack)', (int) $accountid, '', '');
            if ($result < 0) {
                $this->db->rollback();
                throw new RestException(400, 'Add payment to bank error: ' . $payment->error);
            }
        }

        $postState = $this->computeCreditNoteState((int) $id);
        $remainingAfter = ($postState !== false) ? (float) $postState['remaining_refundable'] : null;
        if ($remainingAfter !== null && $remainingAfter <= self::EPSILON) {
            if ((int) $note->paye !== 1 && $note->setPaid(DolibarrApiAccess::$user) < 0) {
                $this->db->rollback();
                throw new RestException(500, 'Could not set the credit note paid: ' . $note->error);
            }
        }
        $this->db->commit();

        return array(
            'payment_id'                 => (int) $payment_id,
            'remaining_refundable_after' => $remainingAfter,
        );
    }

    /**
     * @brief Set KOR correction fields
     * @param  int    $invoice_id      Correction invoice (facture) ID; must be a credit note or replacement with a source invoice, and not yet submitted to KSeF {@from path}
     * @param  string $reason          PrzyczynaKorekty text, non-empty and at most 256 characters {@from body}
     * @param  int    $correction_type TypKorekty, one of 1, 2 or 3 {@from body}
     * @return array                   Result with keys: invoice_id, correction_reason (stored reason) and correction_type (stored type)
     * @url PUT /submissions/{invoice_id}/correction
     * @throws RestException 400, 403, 404, 409, 500
     */
    public function setCorrection($invoice_id, $reason = '', $correction_type = 0)
    {
        $this->checkKsefWritePermission();
        $invoice = $this->checkFactureAccess((int) $invoice_id);
        if (!DolibarrApiAccess::$user->hasRight('facture', 'creer')) {
            throw new RestException(403, 'Not enough permissions (facture/creer)');
        }

        $correction_type = (int) $correction_type;
        $reason = trim((string) $reason);
        if (!in_array($correction_type, array(1, 2, 3), true)) {
            throw new RestException(400, 'correction_type must be 1, 2 or 3');
        }
        if ($reason === '' || mb_strlen($reason) > 256) {
            throw new RestException(400, 'reason must be non-empty and at most 256 characters');
        }
        if (!in_array((int) $invoice->type, array(Facture::TYPE_CREDIT_NOTE, Facture::TYPE_REPLACEMENT), true)
            || empty($invoice->fk_facture_source)) {
            throw new RestException(409, 'Invoice is not a correction (credit note or replacement) with a source invoice');
        }

        $sub = new KsefSubmission($this->db);
        if ($sub->fetchByInvoice((int) $invoice_id) > 0
            && in_array($sub->status, array('ACCEPTED', 'SUBMITTED', 'OFFLINE'), true)) {
            throw new RestException(409, 'Correction already submitted to KSeF (' . $sub->status . '); fields are locked');
        }

        $invoice->array_options['options_ksef_correction_reason'] = $reason;
        $invoice->array_options['options_ksef_correction_type'] = $correction_type;
        if ($invoice->insertExtraFields() < 0) {
            throw new RestException(500, 'Could not save correction fields: ' . $invoice->error);
        }

        return array(
            'invoice_id'        => (int) $invoice_id,
            'correction_reason' => $reason,
            'correction_type'   => $correction_type,
        );
    }

    /**
     * @brief Ensure a foreign-currency invoice carries an NBP rate + date before submission
     * @param  Facture $invoice  Access-checked invoice (from checkFactureAccess)
     * @return void
     * @throws RestException 502 NBP rate could not be resolved
     * @called_by submitInvoice(), submitInvoiceOffline()
     */
    private function ensureInvoiceNbpRate($invoice)
    {
        dol_include_once('/ksef/class/ksef_nbp_currency_rate.class.php');
        $invoice->fetch_optionals();
        $nbp = new KsefNbpCurrencyRate($this->db);
        if (!$nbp->invoiceNeedsNBPRate($invoice) || $nbp->invoiceHasNBPRate($invoice)) {
            return;
        }
        if ($nbp->fetchAndStoreForInvoice($invoice, DolibarrApiAccess::$user, true) === false) {
            throw new RestException(502, 'Could not obtain the NBP rate for this foreign-currency invoice: ' . ($nbp->error ?: 'NBP unavailable and no cached rate'));
        }
    }

    /**
     * @brief Credit note settlement state
     * @param  int $id  Credit note (facture) ID {@from path}
     * @return array    Settlement state with keys: type (facture type), socid (third-party id), validated (bool), paye (bool), total_ttc (absolute total), buckets (per-VAT-rate breakdown), discount_rows (array of discount rows), refunded_ttc (amount refunded in cash), converted_ttc (amount converted to discounts), applied_ttc (amount applied to invoices), remaining_refundable (still refundable in cash), remaining_convertible (still convertible to discount), orphaned_conversion (bool, conversion exists while the note is not validated) and allowed_modes (array of permitted settlement operations)
     * @url GET /creditnotes/{id}/resolution-state
     * @throws RestException 403, 404, 409, 500
     */
    public function getCreditNoteResolutionState($id)
    {
        $this->checkKsefReadPermission();
        $invoice = $this->checkFactureAccess((int) $id);
        if ((int) $invoice->type !== Facture::TYPE_CREDIT_NOTE) {
            throw new RestException(409, 'Invoice ' . (int) $id . ' is not a credit note');
        }

        $state = $this->computeCreditNoteState((int) $id);
        if ($state === false) {
            throw new RestException($this->creditNoteNotFound ? 404 : 500, $this->creditNoteError);
        }
        $state['allowed_modes'] = self::allowedModes($state);

        return $state;
    }

    /**
     * @brief Module health check
     * @return array  Health data with keys: module_enabled (bool), version, environment, nip_configured (bool), auth_method, auth_configured (bool), offline_certificate_configured (bool), connectivity (ok|failed|error|unknown), connectivity_error (present when not ok) and incoming_sync (last_sync, hwm_date, is_running, is_rate_limited)
     * @url GET /status
     * @throws RestException 403
     */
    public function getStatus()
    {
        $this->checkKsefReadPermission();

        global $conf;

        $environment = getDolGlobalString('KSEF_ENVIRONMENT', 'TEST');
        $nip = getDolGlobalString('KSEF_COMPANY_NIP', '');
        $authMethod = getDolGlobalString('KSEF_AUTH_METHOD_' . $environment, 'token');

        $moduleVersion = '';
        dol_include_once('/ksef/core/modules/modKSEF.class.php');
        if (class_exists('modKSEF')) {
            $modKsef = new modKSEF($this->db);
            $moduleVersion = $modKsef->version;
        }

        $data = array(
            'module_enabled'  => isModEnabled('ksef'),
            'version'         => $moduleVersion,
            'environment'     => $environment,
            'nip_configured'  => !empty($nip),
            'auth_method'     => $authMethod,
            'auth_configured' => false,
            'connectivity'    => 'unknown',
        );

        if ($authMethod === 'token') {
            $data['auth_configured'] = !empty(
            getDolGlobalString('KSEF_AUTH_TOKEN_' . $environment, '')
            );
        } elseif ($authMethod === 'certificate') {
            $certCheck = function_exists('ksefCheckAuthCertificate')
                ? ksefCheckAuthCertificate($environment)
                : array('configured' => false);
            $data['auth_configured'] = !empty($certCheck['configured']);
        }

        $data['offline_certificate_configured'] = function_exists(
            'ksefIsOfflineCertificateConfigured'
        )
            ? !empty(ksefIsOfflineCertificateConfigured($environment)['configured'])
            : false;

        try {
            $client = new KsefClient($this->db, $environment);
            $data['connectivity'] = $client->testConnection()
                ? 'ok'
                : 'failed';
            if ($data['connectivity'] === 'failed') {
                $data['connectivity_error'] = $client->error;
            }
        } catch (Exception $e) {
            $data['connectivity'] = 'error';
            $data['connectivity_error'] = $e->getMessage();
        }

        $syncState = new KsefSyncState($this->db);
        $syncState->load('incoming');
        $data['incoming_sync'] = array(
            'last_sync'      => $syncState->last_sync ?: null,
            'hwm_date'       => $syncState->hwm_date ?: null,
            'is_running'     => $syncState->isFetchInProgress(),
            'is_rate_limited' => $syncState->isRateLimited(),
        );

        return $data;
    }


    /**
     * @brief Module configuration
     * @return array  Configuration values with keys: environment, company_nip, auth_method, timeout, add_to_pdf, add_qr, qr_size, button_color, nbp_rate_mode, place_of_issue_mode, fa3_options (include_nrklienta, include_indeks, include_gtin, include_unit, include_bank_desc) and features (creditnote_discounts, creditnote_convert, creditnote_apply_line, creditnote_apply_payment, creditnote_refund, creditnote_state, correction_route)
     * @url GET /config
     * @throws RestException 403
     */
    public function getConfig()
    {
        $this->checkKsefReadPermission();

        $environment = getDolGlobalString('KSEF_ENVIRONMENT', 'TEST');

        return array(
            'environment'       => $environment,
            'company_nip'       => getDolGlobalString('KSEF_COMPANY_NIP', ''),
            'auth_method'       => getDolGlobalString('KSEF_AUTH_METHOD_' . $environment, 'token'),
            'timeout'           => getDolGlobalInt('KSEF_TIMEOUT', 30),
            'add_to_pdf'        => getDolGlobalString('KSEF_ADD_TO_PDF', '1'),
            'add_qr'            => getDolGlobalString('KSEF_ADD_QR', '1'),
            'qr_size'           => getDolGlobalString('KSEF_QR_SIZE', '25'),
            'button_color'      => getDolGlobalString('KSEF_BUTTON_COLOR', '#dc3545'),
            'nbp_rate_mode'     => getDolGlobalString('KSEF_NBP_RATE_MODE', 'keep_base'),
            'place_of_issue_mode' => getDolGlobalString(
                'KSEF_FA3_PLACE_OF_ISSUE_MODE', 'disabled'
            ),
            'fa3_options'       => array(
                'include_nrklienta' => getDolGlobalString(
                    'KSEF_FA3_INCLUDE_NRKLIENTA', '0'
                ),
                'include_indeks'    => getDolGlobalString(
                    'KSEF_FA3_INCLUDE_INDEKS', '0'
                ),
                'include_gtin'      => getDolGlobalString(
                    'KSEF_FA3_INCLUDE_GTIN', '0'
                ),
                'include_unit'      => getDolGlobalString(
                    'KSEF_FA3_INCLUDE_UNIT', '0'
                ),
                'include_bank_desc' => getDolGlobalString(
                    'KSEF_FA3_INCLUDE_BANK_DESC', '0'
                ),
            ),
            'features'          => array(
                'creditnote_discounts'     => true,
                'creditnote_convert'       => true,
                'creditnote_apply_line'    => true,
                'creditnote_apply_payment' => true,
                'creditnote_refund'        => true,
                'creditnote_state'         => true,
                'correction_route'         => true,
            ),
        );
    }


    /**
     * @brief Test KSeF connectivity
     * @return array  Test result with keys: success (bool), environment, api_url, error (null on success) and tested_at
     * @url POST /test-connection
     * @throws RestException 403
     */
    public function testConnection()
    {
        $this->checkKsefReadPermission();

        global $conf;

        $environment = getDolGlobalString('KSEF_ENVIRONMENT', 'TEST');

        try {
            $client = new KsefClient($this->db, $environment);
            $success = $client->testConnection();

            return array(
                'success'     => $success,
                'environment' => $environment,
                'api_url'     => $this->getApiUrl($environment),
                'error'       => $success ? null : $client->error,
                'tested_at'   => dol_now(),
            );
        } catch (Exception $e) {
            return array(
                'success'     => false,
                'environment' => $environment,
                'api_url'     => $this->getApiUrl($environment),
                'error'       => $e->getMessage(),
                'tested_at'   => dol_now(),
            );
        }
    }


    /**
     * @brief Check KSeF read permission
     * @return void
     * @throws RestException 403
     * @called_by All public GET endpoints
     * @calls loadKsefClasses()
     */
    private function checkKsefReadPermission()
    {
        $this->loadKsefClasses();
        if (!DolibarrApiAccess::$user->hasRight('ksef', 'read')) {
            throw new RestException(403, 'Not enough permissions to read KSeF data');
        }
    }

    /**
     * @brief Run settle guard, map to RestException
     * @param  array  $state   State output
     * @param  string $op      Settle op
     * @param  array|null $target  Target facts
     * @param  float  $amount  Refund amount
     * @return void
     * @throws RestException Guard HTTP code on violation
     */
    private function settleGuard($state, $op, $target = null, $amount = 0.0)
    {
        try {
            self::assertSettleable($state, $op, $target, $amount);
        } catch (RestException $e) {
            throw $e;
        } catch (Exception $e) {
            $code = $e->getCode();
            throw new RestException($code >= 400 ? $code : 409, $e->getMessage());
        }
    }

    /**
     * @brief S1: build the settlement state of a credit note
     * @param  int         $id  Credit note (facture) ID
     * @return array|false      State array, or false on failure (see $this->creditNoteError / $this->creditNoteNotFound)
     */
    private function computeCreditNoteState($id)
    {
        $id = (int) $id;
        dol_include_once('/compta/facture/class/facture.class.php');

        $this->creditNoteNotFound = false;
        $invoice = new Facture($this->db);
        if ($invoice->fetch($id) <= 0) {
            $this->creditNoteError = 'Credit note not found: ' . $id;
            $this->creditNoteNotFound = true;
            return false;
        }
        $invoice->fetch_lines();

        $total_ttc = abs((float) $invoice->total_ttc);

        // VAT buckets
        $ht = array();
        $tva = array();
        $ttc = array();
        foreach ($invoice->lines as $line) {
            if ($line->product_type >= 9
                || ((float) $line->total_ht == 0.0 && (float) $line->total_tva == 0.0 && (float) $line->total_ttc == 0.0)) {
                continue;
            }
            $rate = (string) $line->tva_tx;
            if (!isset($ht[$rate])) {
                $ht[$rate] = $tva[$rate] = $ttc[$rate] = 0.0;
            }
            $ht[$rate]  += (float) $line->total_ht;
            $tva[$rate] += (float) $line->total_tva;
            $ttc[$rate] += (float) $line->total_ttc;
        }
        $buckets = array();
        foreach ($ht as $rate => $sum) {
            $buckets[] = array(
                'tva_tx' => abs((float) $rate),
                'ht'     => abs($ht[$rate]),
                'tva'    => abs($tva[$rate]),
                'ttc'    => abs($ttc[$rate]),
            );
        }

        $discount_rows = array();
        $converted_ttc = 0.0;
        $applied_ttc   = 0.0;
        $sql = "SELECT rowid, tva_tx, amount_ht, amount_tva, amount_ttc, fk_facture, fk_facture_line";
        $sql .= " FROM " . MAIN_DB_PREFIX . "societe_remise_except";
        $sql .= " WHERE fk_facture_source = " . $id;
        $sql .= " AND entity IN (" . getEntity('invoice') . ")";
        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->creditNoteError = 'Error querying discount rows: ' . $this->db->lasterror();
            $this->creditNoteNotFound = false;
            return false;
        }
        while ($obj = $this->db->fetch_object($resql)) {
            $used = ($obj->fk_facture !== null || $obj->fk_facture_line !== null);
            $row_ttc = abs((float) $obj->amount_ttc);
            $converted_ttc += $row_ttc;
            if ($used) {
                $applied_ttc += $row_ttc;
            }
            $discount_rows[] = array(
                'id'              => (int) $obj->rowid,
                'tva_tx'          => abs((float) $obj->tva_tx),
                'amount_ht'       => abs((float) $obj->amount_ht),
                'amount_tva'      => abs((float) $obj->amount_tva),
                'amount_ttc'      => $row_ttc,
                'fk_facture'      => $obj->fk_facture !== null ? (int) $obj->fk_facture : null,
                'fk_facture_line' => $obj->fk_facture_line !== null ? (int) $obj->fk_facture_line : null,
                'used'            => $used,
            );
        }
        $this->db->free($resql);

        $refunded_ttc = 0.0;
        $sql = "SELECT SUM(pf.amount) as total FROM " . MAIN_DB_PREFIX . "paiement_facture as pf";
        // paiement_facture has no entity column; scope via the facture it settles (defense in depth).
        $sql .= " INNER JOIN " . MAIN_DB_PREFIX . "facture as f ON f.rowid = pf.fk_facture";
        $sql .= " WHERE pf.fk_facture = " . $id;
        $sql .= " AND f.entity IN (" . getEntity('invoice') . ")";
        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->creditNoteError = 'Error querying payments: ' . $this->db->lasterror();
            return false;
        }
        if ($obj = $this->db->fetch_object($resql)) {
            $refunded_ttc = abs((float) $obj->total);
        }
        $this->db->free($resql);

        $settled = $refunded_ttc + $converted_ttc;

        return array(
            'id'                   => $id,
            'type'                 => (int) $invoice->type,
            'socid'                => (int) $invoice->socid,
            'validated'            => ((int) $invoice->statut > 0),
            'paye'                 => ((int) $invoice->paye === 1),
            'total_ttc'            => $total_ttc,
            'buckets'              => $buckets,
            'discount_rows'        => $discount_rows,
            'refunded_ttc'         => $refunded_ttc,
            'converted_ttc'        => $converted_ttc,
            'applied_ttc'          => $applied_ttc,
            'remaining_refundable' => max(0.0, $total_ttc - $settled),
            'remaining_convertible' => ($converted_ttc > self::EPSILON) ? 0.0 : max(0.0, $total_ttc - $refunded_ttc),
            'orphaned_conversion' => (((int) $invoice->statut <= 0) && $converted_ttc > self::EPSILON),
        );
    }

    /**
     * @brief S2: assert an operation is allowed against a note's state (PURE)
     * @param  array $state   Output of computeCreditNoteState()
     * @param  string $op     One of the OP_* constants
     * @param  array|null $target  For apply-* ops: {socid, type, paye, status, remaining}
     * @param  float $amount  For OP_REFUND: the requested refund amount
     * @return true           When allowed
     */
    private static function assertSettleable($state, $op, $target = null, $amount = 0.0)
    {
        $refunded  = (float) $state['refunded_ttc'];
        $converted = (float) $state['converted_ttc'];

        if ((int) ($state['type'] ?? 0) !== 2) {
            throw new Exception('Only a credit note can be settled here', 409);
        }

        if ($op === self::OP_CONVERT) {
            if ($refunded > self::EPSILON) {
                throw new Exception('Credit note has been refunded (' . $refunded . '); cannot also convert to credit', 409);
            }
            if ($converted > self::EPSILON) {
                throw new Exception('Credit note is already converted (' . $converted . ')', 409);
            }
            return true;
        }

        if ($op === self::OP_REFUND) {
            if ($converted > self::EPSILON) {
                throw new Exception('Credit note has been converted to credit (' . $converted . '); cannot also refund', 409);
            }
            $remaining = (float) $state['remaining_refundable'];
            if ($amount <= 0.0) {
                throw new Exception('Refund amount must be greater than 0', 400);
            }
            if ($amount > $remaining + self::EPSILON) {
                throw new Exception('Refund amount ' . $amount . ' exceeds remaining refundable ' . $remaining, 409);
            }
            return true;
        }

        if ($op === self::OP_APPLY_LINE || $op === self::OP_APPLY_PAYMENT) {
            if ($converted <= self::EPSILON) {
                throw new Exception('Credit note is not converted; nothing to apply', 409);
            }
            $available = $converted - (float) $state['applied_ttc'];
            if ($available <= self::EPSILON) {
                throw new Exception('No unused converted credit remains to apply', 409);
            }
            if (!is_array($target)) {
                throw new Exception('A target invoice is required to apply credit', 400);
            }
            if ((int) $target['socid'] !== (int) $state['socid']) {
                throw new Exception('Target invoice belongs to a different customer', 409);
            }
            // Allowed target types: standard(0), replacement(1), deposit(3).
            if (!in_array((int) $target['type'], array(0, 1, 3), true)) {
                throw new Exception('Target invoice type ' . (int) $target['type'] . ' cannot receive credit', 409);
            }
            if ((int) $target['paye'] === 1) {
                throw new Exception('Target invoice is already paid', 409);
            }
            if ($op === self::OP_APPLY_LINE && (int) $target['status'] !== 0) {
                // Appending a discount line is only valid on a draft (STATUS_DRAFT = 0).
                throw new Exception('Target invoice is not a draft; cannot append a discount line', 405);
            }
            if ($op === self::OP_APPLY_PAYMENT && (int) $target['status'] !== 1) {
                throw new Exception('Target invoice must be validated to receive credit as payment', 409);
            }
            $target_remaining = isset($target['remaining']) ? (float) $target['remaining'] : null;
            if ($target_remaining !== null && $available > $target_remaining + self::EPSILON) {
                throw new Exception('Credit to apply ' . $available . ' exceeds target remaining ' . $target_remaining, 409);
            }
            return true;
        }

        throw new Exception('Unknown settlement operation: ' . $op, 400);
    }

    /**
     * @brief Which settlement operations are currently possible for a note (PURE)
     * @param  array $state  computeCreditNoteState() output
     * @return array         Subset of OP_* the note is eligible for right now
     */
    private static function allowedModes($state)
    {
        $modes = array();
        // Money settlement is avoir-only (type 2 = credit note) and needs a validated note.
        if ((int) $state['type'] !== 2 || empty($state['validated'])) {
            return $modes;
        }
        $refunded  = (float) $state['refunded_ttc'];
        $converted = (float) $state['converted_ttc'];
        $available = $converted - (float) $state['applied_ttc'];

        if ($refunded <= self::EPSILON && $converted <= self::EPSILON) {
            $modes[] = self::OP_CONVERT;
        }
        if ($converted <= self::EPSILON && (float) $state['remaining_refundable'] > self::EPSILON) {
            $modes[] = self::OP_REFUND;
        }
        if ($available > self::EPSILON) {
            $modes[] = self::OP_APPLY_LINE;
            $modes[] = self::OP_APPLY_PAYMENT;
        }
        return $modes;
    }

    /**
     * @brief Lock the credit-note facture row FOR UPDATE for a settlement transaction
     * @param  int $id  Credit-note facture row id
     * @return void
     * @throws RestException 500 Lock failed
     */
    private function lockNoteForSettlement($id)
    {
        $this->db->begin();
        if (!$this->db->query("SELECT rowid FROM " . MAIN_DB_PREFIX . "facture WHERE rowid = " . (int) $id . " FOR UPDATE")) {
            $this->db->rollback();
            throw new RestException(500, 'Could not lock the credit note: ' . $this->db->lasterror());
        }
    }

    /**
     * @brief Lock several facture rows FOR UPDATE in one transaction
     * @param array $ids Facture row ids to lock
     * @return void
     */
    private function lockFacturesForSettlement($ids)
    {
        $clean = array();
        foreach ($ids as $x) {
            $x = (int) $x;
            if ($x > 0) {
                $clean[$x] = $x;
            }
        }
        if (empty($clean)) {
            throw new RestException(500, 'No valid invoice ids to lock for settlement');
        }
        sort($clean);
        $this->db->begin();
        $sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "facture WHERE rowid IN (" . implode(',', $clean) . ") ORDER BY rowid FOR UPDATE";
        if (!$this->db->query($sql)) {
            $this->db->rollback();
            throw new RestException(500, 'Could not lock invoices for settlement: ' . $this->db->lasterror());
        }
    }

    /**
     * @brief Build target-facts array for guard
     * @param  Facture $target  Destination invoice
     * @return array            Target facts
     */
    private function buildTargetInfo($target)
    {
        return array(
            'socid'     => (int) $target->socid,
            'type'      => (int) $target->type,
            'paye'      => (int) $target->paye,
            'status'    => (int) $target->statut,
            'remaining' => (float) $target->getRemainToPay(),
        );
    }

    /**
     * @brief Fetch invoice, enforce facture/lire access
     * @param  int     $invoice_id  Invoice ID
     * @return Facture              Access-checked invoice
     * @throws RestException 403 Access denied
     * @throws RestException 404 Not found
     */
    private function checkFactureAccess($invoice_id)
    {
        dol_include_once('/compta/facture/class/facture.class.php');
        if (!DolibarrApiAccess::$user->hasRight('facture', 'lire')) {
            throw new RestException(403, 'Not enough permissions to read invoices (facture/lire)');
        }
        $invoice = new Facture($this->db);
        if ($invoice->fetch((int)$invoice_id) <= 0) {
            throw new RestException(404, 'Invoice not found');
        }
        if (!DolibarrApi::_checkAccessToResource('facture', $invoice->id)) {
            throw new RestException(403, 'Access not allowed for login ' . DolibarrApiAccess::$user->login);
        }
        return $invoice;
    }

    /**
     * @brief Check KSeF write permission
     * @return void
     * @throws RestException 403
     * @called_by All public POST/DELETE endpoints
     * @calls loadKsefClasses()
     */
    private function checkKsefWritePermission()
    {
        $this->loadKsefClasses();
        if (!DolibarrApiAccess::$user->hasRight('ksef', 'write')) {
            throw new RestException(
                403,
                'Not enough permissions to create/modify KSeF data'
            );
        }
    }

    /**
     * @brief Format submission for output
     * @param $sub Submission object
     * @param $full Include large blobs
     * @return array Formatted data
     * @called_by getSubmissions(), getSubmission(), getSubmissionByInvoice()
     */
    private function formatSubmission($sub, $full = false)
    {
        $data = array(
            'id'                    => (int)$sub->rowid,
            'fk_facture'            => (int)$sub->fk_facture,
            'ksef_reference'        => $sub->ksef_reference,
            'ksef_number'           => $sub->ksef_number,
            'invoice_hash'          => $sub->invoice_hash,
            'status'                => $sub->status,
            'environment'           => $sub->environment,
            'date_submission'       => $sub->date_submission,
            'date_acceptance'       => $sub->date_acceptance,
            'date_last_check'       => $sub->date_last_check,
            'error_message'         => $sub->error_message,
            'error_code'            => $sub->error_code,
            'retry_count'           => (int)$sub->retry_count,
            'fk_user_submit'        => (int)$sub->fk_user_submit,
            'offline_mode'          => $sub->offline_mode,
            'offline_deadline'      => $sub->offline_deadline,
            'offline_detected_reason' => $sub->offline_detected_reason,
            'fa3_creation_date'     => $sub->fa3_creation_date,
            'can_retry'             => $sub->canRetry(),
        );

        if (!$sub->canRetry()) {
            $data['no_retry_reason'] = $sub->getNoRetryReason();
        }

        if (!empty($sub->error_details)) {
            $data['error_details'] = $sub->getErrorDetailsArray();
        }

        if ($full) {
            $data['fa3_xml'] = $sub->fa3_xml;
            $data['upo_xml'] = $sub->upo_xml;
            $data['api_response'] = $sub->api_response;
        } else {
            $data['has_fa3_xml'] = !empty($sub->fa3_xml);
            $data['has_upo_xml'] = !empty($sub->upo_xml);
        }

        return $data;
    }

    /**
     * @brief Format KsefIncoming for API output
     * @param $inc Incoming invoice object
     * @return array Formatted data
     * @called_by getIncoming(), getIncomingById()
     */
    private function formatIncoming($inc)
    {
        return array(
            'id'                      => (int)$inc->rowid,
            'ksef_number'             => $inc->ksef_number,
            'seller_nip'              => $inc->seller_nip,
            'seller_name'             => $inc->seller_name,
            'seller_country'          => $inc->seller_country,
            'seller_address'          => $inc->seller_address,
            'buyer_nip'               => $inc->buyer_nip,
            'buyer_name'              => $inc->buyer_name,
            'invoice_number'          => $inc->invoice_number,
            'invoice_type'            => $inc->invoice_type,
            'invoice_date'            => $inc->invoice_date,
            'sale_date'               => $inc->sale_date,
            'currency'                => $inc->currency,
            'total_net'               => $inc->total_net,
            'total_vat'               => $inc->total_vat,
            'total_gross'             => $inc->total_gross,
            'payment_due_date'        => $inc->payment_due_date,
            'payment_method'          => $inc->payment_method,
            'bank_account'            => $inc->bank_account,
            'corrected_ksef_number'   => $inc->corrected_ksef_number,
            'corrected_invoice_number' => $inc->corrected_invoice_number,
            'corrected_invoice_date'  => $inc->corrected_invoice_date,
            'fa3_creation_date'       => $inc->fa3_creation_date,
            'fa3_system_info'         => $inc->fa3_system_info,
            'fetch_date'              => $inc->fetch_date,
            'environment'             => $inc->environment,
            'import_status'           => $inc->import_status,
            'import_date'             => $inc->import_date,
            'import_error'            => $inc->import_error,
            'fk_facture_fourn'        => $inc->fk_facture_fourn
                ? (int)$inc->fk_facture_fourn
                : null,
            'has_xml'                 => !empty($inc->fa3_xml),
        );
    }

    /**
     * @brief GUS REGON lookup by NIP
     * @param  string $nip  NIP to look up {@from path}
     * @param  string $env  Environment, TEST or PROD; defaults to KSEF_GUS_ENV (TEST) when empty {@from query}
     * @return array Company data from GUS REGON, including an env key (TEST or PROD)
     *
     * @url GET /gus/{nip}
     * @throws RestException 400, 403, 404, 503
     */
    public function gusLookup($nip, $env = '')
    {
        $this->checkKsefReadPermission();

        if (empty(getDolGlobalString('KSEF_GUS_ENABLED'))) {
            throw new RestException(403, 'GUS lookup is disabled');
        }

        dol_include_once('/ksef/class/ksef_gus_client.class.php');

        $nip = ksefCleanNIP($nip);
        if (!ksefValidateNIP($nip)) {
            throw new RestException(400, 'Invalid NIP');
        }

        $env = $env ? strtoupper($env) : getDolGlobalString('KSEF_GUS_ENV', 'TEST');
        $client = new KsefGusClient($this->db, $env);
        $data = $client->lookupByNip($nip);

        if ($data === false) {
            $err = $client->error ? $client->error : 'error';
            if ($err === 'not_found' || $err === 'unsupported_type') {
                throw new RestException(404, 'No company found for NIP ' . $nip);
            }
            if ($err === 'invalid_nip') {
                throw new RestException(400, 'Invalid NIP');
            }
            throw new RestException(503, 'GUS lookup failed: ' . $err);
        }

        $data['env'] = ($env === 'PROD') ? 'PROD' : 'TEST';

        return $data;
    }

    /**
     * @brief KSeF API URL for environment
     * @param $environment Environment name
     * @return string API URL
     * @called_by testConnection()
     */
    private function getApiUrl($environment)
    {
        switch (strtoupper($environment)) {
            case 'PRODUCTION':
                return KsefClient::API_PROD;
            case 'DEMO':
                return KsefClient::API_DEMO;
            case 'TEST':
            default:
                return KsefClient::API_TEST;
        }
    }
}

class_alias('KsefApi', 'Ksef');