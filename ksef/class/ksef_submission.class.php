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
 * \file    ksef/class/ksef_submission.class.php
 * \ingroup ksef
 * \brief   KSEF submission
 */

require_once DOL_DOCUMENT_ROOT . '/core/class/commonobject.class.php';
require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';

class KsefSubmission extends CommonObject
{
    public $element = 'ksef_submission';
    public $table_element = 'ksef_submissions';

    public $rowid;
    public $fk_facture;
    public $ksef_reference;
    public $ksef_number;
    public $invoice_hash;
    public $status;
    public $environment;
    public $fa3_xml;
    public $upo_xml;
    public $api_response;
    public $date_submission;
    public $date_acceptance;
    public $date_last_check;
    public $error_message;
    public $retry_count;
    public $fk_user_submit;
    public $error_code;
    public $error_details;
    public $offline_mode;
    public $offline_deadline;
    public $offline_detected_reason;
    public $original_invoice_hash;
    public $fa3_creation_date;
    public $invoice_type;
    const STATUS_PENDING = 'PENDING';
    const STATUS_SUBMITTED = 'SUBMITTED';
    const STATUS_ACCEPTED = 'ACCEPTED';
    const STATUS_REJECTED = 'REJECTED';
    const STATUS_OFFLINE = 'OFFLINE';
    const STATUS_FAILED = 'FAILED';
    const STATUS_TIMEOUT = 'TIMEOUT';
    const MAX_RETRY_COUNT = 30;

    public function __construct($db = null)
    {
        $this->db = $db;
        $this->retry_count = 0;
    }


    /**
     * @brief Creates new submission record
     * @param $user User object
     * @param $notrigger Skip triggers flag
     * @return int Record ID or negative on error
     * @called_by KSEF::submitInvoice()
     * @calls ksefUpdateInvoiceExtrafields()
     */
    public function create($user, $notrigger = 0)
    {
        global $conf, $langs;

        $error = 0;
        $this->status = trim($this->status);
        $this->environment = trim($this->environment);
        $this->ksef_reference = trim($this->ksef_reference);
        $this->ksef_number = trim($this->ksef_number);

        if (empty($this->date_submission)) {
            $this->date_submission = dol_now();
        }

        $this->db->begin();

        $sql = "INSERT INTO " . MAIN_DB_PREFIX . $this->table_element . " (";
        $sql .= "fk_facture,";
        $sql .= "ksef_reference,";
        $sql .= "ksef_number,";
        $sql .= "invoice_hash,";
        $sql .= "status,";
        $sql .= "environment,";
        $sql .= "fa3_xml,";
        $sql .= "fa3_creation_date,";
        $sql .= "upo_xml,";
        $sql .= "api_response,";
        $sql .= "date_submission,";
        $sql .= "date_acceptance,";
        $sql .= "date_last_check,";
        $sql .= "error_message,";
        $sql .= "error_code,";
        $sql .= "error_details,";
        $sql .= "retry_count,";
        $sql .= "fk_user_submit,";
        $sql .= "offline_mode,";
        $sql .= "offline_deadline,";
        $sql .= "offline_detected_reason,";
        $sql .= "original_invoice_hash,";
        $sql .= "invoice_type";
        $sql .= ")";
        $sql .= " VALUES (";
        $sql .= " " . (int)$this->fk_facture . ",";
        $sql .= " " . ($this->ksef_reference ? "'" . $this->db->escape($this->ksef_reference) . "'" : "NULL") . ",";
        $sql .= " " . ($this->ksef_number ? "'" . $this->db->escape($this->ksef_number) . "'" : "NULL") . ",";
        $sql .= " " . ($this->invoice_hash ? "'" . $this->db->escape($this->invoice_hash) . "'" : "NULL") . ",";
        $sql .= " '" . $this->db->escape($this->status) . "',";
        $sql .= " '" . $this->db->escape($this->environment) . "',";
        $sql .= " " . ($this->fa3_xml ? "'" . $this->db->escape($this->fa3_xml) . "'" : "NULL") . ",";
        $sql .= " " . ($this->fa3_creation_date ? (int)$this->fa3_creation_date : "NULL") . ",";
        $sql .= " " . ($this->upo_xml ? "'" . $this->db->escape($this->upo_xml) . "'" : "NULL") . ",";
        $sql .= " " . ($this->api_response ? "'" . $this->db->escape($this->api_response) . "'" : "NULL") . ",";
        $sql .= " " . (int)$this->date_submission . ",";
        $sql .= " " . ($this->date_acceptance ? (int)$this->date_acceptance : "NULL") . ",";
        $sql .= " " . ($this->date_last_check ? (int)$this->date_last_check : "NULL") . ",";
        $sql .= " " . ($this->error_message ? "'" . $this->db->escape($this->error_message) . "'" : "NULL") . ",";
        $sql .= " " . ($this->error_code ? (int)$this->error_code : "NULL") . ",";
        $sql .= " " . ($this->error_details ? "'" . $this->db->escape($this->error_details) . "'" : "NULL") . ",";
        $sql .= " " . (int)$this->retry_count . ",";
        $sql .= " " . (int)$user->id . ",";
        $sql .= " " . ($this->offline_mode ? "'" . $this->db->escape($this->offline_mode) . "'" : "NULL") . ",";
        $sql .= " " . ($this->offline_deadline ? (int)$this->offline_deadline : "NULL") . ",";
        $sql .= " " . ($this->offline_detected_reason ? "'" . $this->db->escape($this->offline_detected_reason) . "'" : "NULL") . ",";
        $sql .= " " . ($this->original_invoice_hash ? "'" . $this->db->escape($this->original_invoice_hash) . "'" : "NULL") . ",";
        $sql .= " " . ($this->invoice_type ? "'" . $this->db->escape($this->invoice_type) . "'" : "NULL");
        $sql .= ")";

        dol_syslog(get_class($this) . "::create", LOG_DEBUG);
        $resql = $this->db->query($sql);

        if (!$resql) {
            $error++;
            $this->errors[] = "Error " . $this->db->lasterror();
            dol_syslog(get_class($this) . "::create " . $this->db->lasterror(), LOG_ERR);
        }

        if (!$error) {
            $this->rowid = $this->db->last_insert_id(MAIN_DB_PREFIX . $this->table_element);
            ksefUpdateInvoiceExtrafields($this->db, $this->fk_facture, $this->ksef_number, $this->status, $this->date_submission, false);

            if (!$notrigger) {
                $result = $this->call_trigger('KSEF_SUBMISSION_CREATE', $user);
                if ($result < 0) $error++;
            }
        }

        if ($error) {
            foreach ($this->errors as $errmsg) {
                dol_syslog(get_class($this) . "::create " . $errmsg, LOG_ERR);
                $this->error .= ($this->error ? ', ' . $errmsg : $errmsg);
            }
            $this->db->rollback();
            return -1 * $error;
        } else {
            $this->db->commit();
            return $this->rowid;
        }
    }

    /**
     * @brief Updates submission record
     * @param $user User object
     * @param $notrigger Skip triggers flag
     * @return int Result code
     * @called_by KSEF::checkStatus(), processPendingSubmissions()
     * @calls ksefUpdateInvoiceExtrafields()
     */
    public function update($user, $notrigger = 0)
    {
        $error = 0;

        $this->status = trim($this->status);
        $this->ksef_reference = trim($this->ksef_reference);
        $this->ksef_number = trim($this->ksef_number);

        $this->db->begin();

        $sql = "UPDATE " . MAIN_DB_PREFIX . $this->table_element . " SET";
        $sql .= " ksef_reference = " . ($this->ksef_reference ? "'" . $this->db->escape($this->ksef_reference) . "'" : "NULL") . ",";
        $sql .= " ksef_number = " . ($this->ksef_number ? "'" . $this->db->escape($this->ksef_number) . "'" : "NULL") . ",";
        $sql .= " fa3_creation_date = " . ($this->fa3_creation_date ? (int)$this->fa3_creation_date : "NULL") . ",";
        $sql .= " invoice_hash = " . ($this->invoice_hash ? "'" . $this->db->escape($this->invoice_hash) . "'" : "NULL") . ",";
        $sql .= " status = '" . $this->db->escape($this->status) . "',";
        $sql .= " api_response = " . ($this->api_response ? "'" . $this->db->escape($this->api_response) . "'" : "NULL") . ",";
        $sql .= " date_acceptance = " . ($this->date_acceptance ? (int)$this->date_acceptance : "NULL") . ",";
        $sql .= " date_last_check = " . ($this->date_last_check ? (int)$this->date_last_check : "NULL") . ",";
        $sql .= " error_message = " . ($this->error_message ? "'" . $this->db->escape($this->error_message) . "'" : "NULL") . ",";
        $sql .= " error_code = " . ($this->error_code ? (int)$this->error_code : "NULL") . ",";
        $sql .= " error_details = " . ($this->error_details ? "'" . $this->db->escape($this->error_details) . "'" : "NULL") . ",";
        $sql .= " retry_count = " . (int)$this->retry_count . ",";
        $sql .= " offline_mode = " . ($this->offline_mode ? "'" . $this->db->escape($this->offline_mode) . "'" : "NULL") . ",";
        $sql .= " offline_deadline = " . ($this->offline_deadline ? (int)$this->offline_deadline : "NULL") . ",";
        $sql .= " offline_detected_reason = " . ($this->offline_detected_reason ? "'" . $this->db->escape($this->offline_detected_reason) . "'" : "NULL") . ",";
        $sql .= " original_invoice_hash = " . ($this->original_invoice_hash ? "'" . $this->db->escape($this->original_invoice_hash) . "'" : "NULL") . ",";
        $sql .= " fa3_xml = " . ($this->fa3_xml ? "'" . $this->db->escape($this->fa3_xml) . "'" : "NULL") . ",";
        $sql .= " upo_xml = " . ($this->upo_xml ? "'" . $this->db->escape($this->upo_xml) . "'" : "NULL");
        $sql .= " WHERE rowid = " . (int)$this->rowid;

        dol_syslog(get_class($this) . "::update", LOG_DEBUG);
        $resql = $this->db->query($sql);

        if (!$resql) {
            $error++;
            $this->errors[] = "Error " . $this->db->lasterror();
            dol_syslog(get_class($this) . "::update " . $this->db->lasterror(), LOG_ERR);
        }

        if (!$error) {
            if (empty($this->fk_facture)) {
                $old_obj = new KsefSubmission($this->db);
                $old_obj->fetch($this->rowid);
                $this->fk_facture = $old_obj->fk_facture;
            }
            ksefUpdateInvoiceExtrafields($this->db, $this->fk_facture, $this->ksef_number, $this->status, $this->date_submission, false);
        }

        if (!$error && !$notrigger) {
            $result = $this->call_trigger('KSEF_SUBMISSION_UPDATE', $user);
            if ($result < 0) $error++;
        }

        if ($error) {
            foreach ($this->errors as $errmsg) {
                dol_syslog(get_class($this) . "::update " . $errmsg, LOG_ERR);
                $this->error .= ($this->error ? ', ' . $errmsg : $errmsg);
            }
            $this->db->rollback();
            return -1 * $error;
        } else {
            $this->db->commit();
            return 1;
        }
    }

    /**
     * @brief Fetches submission record
     * @param $id Submission ID
     * @param $fk_facture Invoice ID
     * @return int Result code
     * @called_by Various pages
     */
    public function fetch($id = 0, $fk_facture = 0)
    {
        $sql = "SELECT";
        $sql .= " t.rowid,";
        $sql .= " t.fk_facture,";
        $sql .= " t.ksef_reference,";
        $sql .= " t.ksef_number,";
        $sql .= " t.invoice_hash,";
        $sql .= " t.status,";
        $sql .= " t.environment,";
        $sql .= " t.fa3_xml,";
        $sql .= " t.fa3_creation_date,";
        $sql .= " t.upo_xml,";
        $sql .= " t.api_response,";
        $sql .= " t.date_submission,";
        $sql .= " t.date_acceptance,";
        $sql .= " t.date_last_check,";
        $sql .= " t.error_message,";
        $sql .= " t.error_code,";
        $sql .= " t.error_details,";
        $sql .= " t.retry_count,";
        $sql .= " t.fk_user_submit,";
        $sql .= " t.offline_mode,";
        $sql .= " t.offline_deadline,";
        $sql .= " t.offline_detected_reason,";
        $sql .= " t.original_invoice_hash,";
        $sql .= " t.invoice_type";
        $sql .= " FROM " . MAIN_DB_PREFIX . $this->table_element . " as t";

        if ($id > 0) {
            $sql .= " WHERE t.rowid = " . (int)$id;
        } elseif ($fk_facture > 0) {
            $sql .= " WHERE t.fk_facture = " . (int)$fk_facture;
            $sql .= " ORDER BY t.date_submission DESC LIMIT 1";
        } else {
            $this->error = 'ErrorBadParameters';
            return -1;
        }

        dol_syslog(get_class($this) . "::fetch", LOG_DEBUG);
        $resql = $this->db->query($sql);

        if ($resql) {
            $num = $this->db->num_rows($resql);
            if ($num) {
                $obj = $this->db->fetch_object($resql);

                $this->rowid = $obj->rowid;
                $this->fk_facture = $obj->fk_facture;
                $this->ksef_reference = $obj->ksef_reference;
                $this->ksef_number = $obj->ksef_number;
                $this->invoice_hash = $obj->invoice_hash;
                $this->status = $obj->status;
                $this->environment = $obj->environment;
                $this->fa3_xml = $obj->fa3_xml;
                $this->fa3_creation_date = $obj->fa3_creation_date;
                $this->upo_xml = $obj->upo_xml;
                $this->api_response = $obj->api_response;
                $this->date_submission = $obj->date_submission;
                $this->date_acceptance = $obj->date_acceptance;
                $this->date_last_check = $obj->date_last_check;
                $this->error_message = $obj->error_message;
                $this->error_code = $obj->error_code;
                $this->error_details = $obj->error_details;
                $this->retry_count = $obj->retry_count;
                $this->fk_user_submit = $obj->fk_user_submit;
                $this->offline_mode = $obj->offline_mode;
                $this->offline_deadline = $obj->offline_deadline;
                $this->offline_detected_reason = $obj->offline_detected_reason;
                $this->original_invoice_hash = $obj->original_invoice_hash;
                $this->invoice_type = $obj->invoice_type;
            }
            $this->db->free($resql);
            return $num ? 1 : 0;
        } else {
            $this->error = "Error " . $this->db->lasterror();
            dol_syslog(get_class($this) . "::fetch " . $this->db->lasterror(), LOG_ERR);
            return -1;
        }
    }


    /**
     * @brief Gets error details as array
     * @return array|null Error details
     * @called_by status.php
     */
    public function getErrorDetailsArray()
    {
        if (empty($this->error_details)) return null;
        $details = json_decode($this->error_details, true);
        return is_array($details) ? $details : null;
    }


    /**
     * @brief Fetches pending submissions
     * @param $environment Environment filter
     * @param $max_age Max age in seconds
     * @return array|false Submission objects
     * @called_by processPendingSubmissions()
     */
    public function fetchPending($environment = null, $max_age = 86400)
    {
        $results = array();

        $sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . $this->table_element;
        $sql .= " WHERE status IN ('" . self::STATUS_PENDING . "', '" . self::STATUS_SUBMITTED . "', '" . self::STATUS_TIMEOUT . "')";

        if ($environment) $sql .= " AND environment = '" . $this->db->escape($environment) . "'";

        $sql .= " AND date_submission > " . (dol_now() - $max_age);
        $sql .= " AND retry_count < " . self::MAX_RETRY_COUNT;
        $sql .= " ORDER BY date_submission ASC";

        $resql = $this->db->query($sql);

        if ($resql) {
            $num = $this->db->num_rows($resql);
            for ($i = 0; $i < $num; $i++) {
                $obj = $this->db->fetch_object($resql);
                $submission = new KsefSubmission($this->db);
                if ($submission->fetch($obj->rowid) > 0) $results[] = $submission;
            }
            $this->db->free($resql);
            return $results;
        } else {
            $this->error = "Error " . $this->db->lasterror();
            dol_syslog(get_class($this) . "::fetchPending " . $this->db->lasterror(), LOG_ERR);
            return false;
        }
    }

    /**
     * @brief Gets submission statistics
     * @param $days Days to include
     * @return array Statistics
     * @called_by ksefindex.php, status.php
     */
    public function getStatistics($days = 30)
    {
        $stats = array('total' => 0, 'accepted' => 0, 'pending' => 0, 'failed' => 0, 'success_rate' => 0, 'common_errors' => array());

        $sql = "SELECT t.status, COUNT(*) as count FROM " . MAIN_DB_PREFIX . $this->table_element . " as t";
        $sql .= " INNER JOIN " . MAIN_DB_PREFIX . "facture as f ON f.rowid = t.fk_facture AND f.entity IN (" . getEntity('invoice') . ")";
        $sql .= " WHERE t.date_submission > " . (dol_now() - ($days * 86400));
        $sql .= " GROUP BY t.status";

        $resql = $this->db->query($sql);
        if ($resql) {
            while ($obj = $this->db->fetch_object($resql)) {
                $stats['total'] += $obj->count;

                if ($obj->status == self::STATUS_ACCEPTED) $stats['accepted'] = $obj->count;
                elseif (in_array($obj->status, array(self::STATUS_PENDING, self::STATUS_SUBMITTED, self::STATUS_TIMEOUT))) $stats['pending'] += $obj->count;
                else $stats['failed'] += $obj->count;
            }

            if ($stats['total'] > 0) $stats['success_rate'] = round(($stats['accepted'] / $stats['total']) * 100, 2);
            $this->db->free($resql);
        }

        $sql = "SELECT t.error_code, COUNT(*) as count FROM " . MAIN_DB_PREFIX . $this->table_element . " as t";
        $sql .= " INNER JOIN " . MAIN_DB_PREFIX . "facture as f ON f.rowid = t.fk_facture AND f.entity IN (" . getEntity('invoice') . ")";
        $sql .= " WHERE t.date_submission > " . (dol_now() - ($days * 86400));
        $sql .= " AND t.error_code IS NOT NULL GROUP BY t.error_code ORDER BY count DESC LIMIT 5";

        $resql = $this->db->query($sql);
        if ($resql) {
            require_once DOL_DOCUMENT_ROOT . '/custom/ksef/class/ksef_client.class.php';
            $client = new KsefClient($this->db);

            while ($obj = $this->db->fetch_object($resql)) {
                $stats['common_errors'][] = array(
                    'code' => $obj->error_code,
                    'count' => $obj->count,
                    'description' => $client->getErrorDescription($obj->error_code)
                );
            }
            $this->db->free($resql);
        }

        return $stats;
    }

    /**
     * @brief Fetches submission by invoice ID
     * @param $fk_facture Invoice ID
     * @return int Result code
     * @called_by ActionsKSEF::formObjectOptions(), KsefQR::addQRToPDF()
     */
    public function fetchByInvoice($fk_facture)
    {
        return $this->fetch(0, $fk_facture);
    }

    /**
     * @brief Fetches offline submissions approaching deadline
     * @param int $hours_ahead Hours ahead to check (default 24)
     * @return array|false Submission objects
     */
    public function fetchCronJobs($hours_ahead = 24)
    {
        $results = array();
        $deadline_threshold = dol_now() + ($hours_ahead * 3600);

        $sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . $this->table_element;
        $sql .= " WHERE offline_mode IS NOT NULL";
        $sql .= " AND status NOT IN ('ACCEPTED', 'REJECTED')";
        $sql .= " AND offline_deadline IS NOT NULL";
        $sql .= " AND offline_deadline <= " . (int)$deadline_threshold;
        $sql .= " AND offline_deadline > " . dol_now();
        $sql .= " ORDER BY offline_deadline ASC";

        $resql = $this->db->query($sql);

        if ($resql) {
            while ($obj = $this->db->fetch_object($resql)) {
                $submission = new KsefSubmission($this->db);
                if ($submission->fetch($obj->rowid) > 0) {
                    $results[] = $submission;
                }
            }
            $this->db->free($resql);
            return $results;
        }

        $this->error = "Error " . $this->db->lasterror();
        return false;
    }

    /**
     * @brief Checks if submission can be retried
     * @return bool True if retryable
     */
    public function canRetry()
    {
        $retryable = array('PENDING', 'FAILED', 'REJECTED', 'TIMEOUT');

        if (!in_array($this->status, $retryable)) {
            return false;
        }

        if (!empty($this->offline_mode) && !empty($this->offline_deadline)) {
            if (ksefIsDeadlinePassed($this->offline_deadline)) {
                return false;
            }
        }

        if ($this->retry_count >= self::MAX_RETRY_COUNT) {
            return false;
        }

        return true;
    }

    /**
     * @brief Gets reason why retry is not allowed
     * @return string|null Reason or null if retry is allowed
     */
    public function getNoRetryReason()
    {
        global $langs;
        $langs->load("ksef@ksef");

        if ($this->status == 'ACCEPTED') {
            return $langs->trans('KSEF_AlreadyAccepted');
        }

        if (!empty($this->offline_mode) && !empty($this->offline_deadline)) {
            if (ksefIsDeadlinePassed($this->offline_deadline)) {
                return $langs->trans('KSEF_DeadlinePassed');
            }
        }

        if ($this->retry_count >= self::MAX_RETRY_COUNT) {
            return $langs->trans('KSEF_TooManyRetries');
        }

        return null;
    }
}