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
 * \file    ksef/class/ksef_service.class.php
 * \ingroup ksef
 * \brief   Main KSEF class
 */

require_once DOL_DOCUMENT_ROOT . '/core/class/commonobject.class.php';
require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';

class KsefService extends CommonObject
{
    public $element = 'ksef';
    public $table_element = 'ksef_submissions';
    public $failureReason = null;

    private $client;
    private $builder;

    const TIMEOUT_SYNC = 5;

    public function __construct($db)
    {
        global $conf;

        dol_include_once('/ksef/class/ksef_client.class.php');
        dol_include_once('/ksef/class/fa3_builder.class.php');
        dol_include_once('/ksef/class/ksef_submission.class.php');
        $this->db = $db;

        $this->client = new KsefClient($db, getDolGlobalString('KSEF_ENVIRONMENT', 'TEST'));
        $this->builder = new FA3Builder($db);
    }


    /**
     * @brief Submits invoice to KSeF system including offline mode
     * @param $invoice_id Invoice ID
     * @param $user User object
     * @param $mode Submission mode (SYNC/MANUAL)
     * @param $force_offline Force offline mode
     * @param $offline_reason Reason for offline mode
     * @return array Result array with status, ksef_number, etc.
     * @called_by ActionsKSEF::doActions()
     * @calls FA3Builder::buildFromInvoice(), KsefClient::submitInvoice(), getSubmissionByInvoice(), needsOfflineConfirmation()
     */
    public function submitInvoice($invoice_id, $user, $mode = 'SYNC', $force_offline = false, $offline_reason = '')
    {
        global $conf;
        dol_syslog("KSEF::submitInvoice invoice_id=$invoice_id mode=$mode force_offline=" . ($force_offline ? 'yes' : 'no'), LOG_INFO);

        $start_time = microtime(true);
        $fa3_xml = '';
        $xml_hash = '';
        $fa3_creation_date = null;

        try {
            $invoice = new Facture($this->db);
            if ($invoice->fetch($invoice_id) <= 0) {
                throw new Exception("Invoice not found: $invoice_id");
            }

            $existing = new KsefSubmission($this->db);
            if ($existing->fetchByInvoice($invoice_id) > 0) {
                if ($existing->status == 'ACCEPTED') {
                    return array(
                        'status' => 'ACCEPTED',
                        'ksef_number' => $existing->ksef_number,
                        'submission_date' => $existing->date_submission,
                        'message' => 'Invoice already submitted'
                    );
                }

                if ($existing->status == 'PENDING') {
                    return array(
                        'status' => 'PENDING',
                        'message' => 'Submission already in progress',
                        'submission_id' => $existing->rowid
                    );
                }

                if (!empty($existing->fa3_creation_date)) {
                    $fa3_creation_date = $existing->fa3_creation_date;
                    dol_syslog("KSEF::submitInvoice - Reusing original creation date: $fa3_creation_date", LOG_INFO);
                }
            }

            if (!$force_offline) {
                $backdate_info = $this->needsOfflineConfirmation($invoice);
                if ($backdate_info) {
                    return array(
                        'status' => 'NEEDS_CONFIRMATION',
                        'backdate_info' => $backdate_info,
                        'deadline' => $backdate_info['deadline'],
                        'message' => $backdate_info['reason']
                    );
                }
            }

            dol_include_once('/ksef/class/ksef_latarnia.class.php');
            $latarnia = new KsefLatarnia($this->db);
            $latarnia->refreshIfStale();

            $build_options = array();
            if ($fa3_creation_date) {
                $build_options['original_creation_date'] = $fa3_creation_date;
            }
            if ($force_offline) {
                $build_options['offline_mode'] = true;
            }

            $fa3_xml = $this->builder->buildFromInvoice($invoice_id, $build_options);
            if ($fa3_xml === false) {
                throw new Exception("Failed to build FA(3) XML: " . $this->builder->error);
            }

            $fa3_creation_date = $this->builder->getLastCreationDate();
            $xml_hash = $this->builder->getLastXmlHash();
            $invoice_type = $this->builder->getLastInvoiceType();

            if (!$this->builder->validate($fa3_xml)) {
                dol_syslog("KSEF::submitInvoice XML validation warnings: " . implode(', ', $this->builder->errors), LOG_WARNING);
            }

            $offline_mode_value = $force_offline ? 'OFFLINE' : null;
            $offline_deadline = $force_offline ? ksefCalculateOfflineDeadline($invoice->date) : null;

            $submission = new KsefSubmission($this->db);

            $attempt_number = 1;
            if (!empty($existing->rowid)) {
                $sql = "SELECT COUNT(*) as cnt FROM " . MAIN_DB_PREFIX . "ksef_submissions WHERE fk_facture = " . (int)$invoice_id;
                $resql = $this->db->query($sql);
                if ($resql) {
                    $obj = $this->db->fetch_object($resql);
                    $attempt_number = ($obj->cnt ?? 0) + 1;
                }
            }
            $submission->retry_count = $attempt_number - 1;

            $submission->fk_facture = $invoice_id;
            $submission->fa3_xml = $fa3_xml;
            $submission->fa3_creation_date = $fa3_creation_date;
            $submission->invoice_hash = $xml_hash;
            $submission->invoice_type = $invoice_type;
            $submission->status = 'PENDING';
            $submission->environment = $conf->global->KSEF_ENVIRONMENT ?? 'TEST';
            $submission->date_submission = dol_now();
            $submission->offline_mode = $offline_mode_value;
            $submission->offline_deadline = $offline_deadline;
            $submission->offline_detected_reason = $offline_reason ?: null;
            $submission->error_message = null;
            $submission->error_code = null;

            if (!empty($submission->rowid)) {
                $submission->update($user, 1);
            } else {
                $submission->create($user);
            }

            if ($mode === 'SYNC') {
                $this->client->setTimeout(self::TIMEOUT_SYNC);
            }

            $api_result = $this->client->submitInvoice($fa3_xml, array(
                'offline_mode' => $force_offline,
                'invoice_hash' => $xml_hash
            ));

            $elapsed = microtime(true) - $start_time;

            if ($api_result && $api_result['status'] === 'ACCEPTED' && !empty($api_result['ksef_number'])) {
                $submission->status = 'ACCEPTED';
                $submission->ksef_reference = $api_result['reference_number'] ?? '';
                $submission->ksef_number = $api_result['ksef_number'];
                $submission->upo_xml = $api_result['upo_xml'] ?? null;
                $submission->api_response = json_encode($api_result);
                $submission->date_acceptance = dol_now();
                $submission->update($user, 1);

                dol_syslog("KSEF::submitInvoice SUCCESS in {$elapsed}s - KSEF: " . $api_result['ksef_number'], LOG_INFO);

                ksefAutoGeneratePdf($this->db, $submission, $invoice_id);

                return array(
                    'status' => 'ACCEPTED',
                    'ksef_number' => $api_result['ksef_number'],
                    'invoice_hash' => $api_result['invoice_hash'] ?? $xml_hash,
                    'submission_date' => dol_now(),
                    'submission_id' => $submission->rowid,
                    'offline_mode' => $offline_mode_value
                );
            } else {
                $submission->status = 'FAILED';
                $submission->error_message = $api_result['error'] ?? $this->client->error ?? 'Unknown error';
                $submission->error_code = $this->client->last_error_code ?? null;
                if (!empty($this->client->last_error_details)) {
                    $submission->error_details = json_encode($this->client->last_error_details);
                }
                $submission->api_response = json_encode($api_result);
                $submission->update($user, 1);

                dol_syslog("KSEF::submitInvoice FAILED in {$elapsed}s: " . $submission->error_message, LOG_ERR);

                return array(
                    'status' => 'FAILED',
                    'error' => $submission->error_message,
                    'error_code' => $submission->error_code,
                    'submission_id' => $submission->rowid,
                    'fa3_creation_date' => $fa3_creation_date,
                    'invoice_hash' => $xml_hash,
                    'can_use_offline' => true
                );
            }

        } catch (Exception $e) {
            $this->error = $e->getMessage();
            $this->errors[] = $e->getMessage();
            dol_syslog("KSEF::submitInvoice ERROR: " . $e->getMessage(), LOG_ERR);

            if (!empty($this->client->last_error_details)) {
                dol_syslog("KSEF::submitInvoice ERROR DETAILS: " . json_encode($this->client->last_error_details), LOG_ERR);
            }

            if (isset($submission) && !empty($submission->rowid)) {
                $submission->status = 'FAILED';
                $submission->error_message = $e->getMessage();
                if (!empty($this->client->last_error_code)) {
                    $submission->error_code = $this->client->last_error_code;
                }
                if (!empty($this->client->last_error_details)) {
                    $submission->error_details = json_encode($this->client->last_error_details);
                }
                $submission->update($user, 1);
            } elseif (isset($invoice_id)) {
                $submission = new KsefSubmission($this->db);
                $submission->fk_facture = $invoice_id;
                $submission->fa3_xml = $fa3_xml;
                $submission->fa3_creation_date = $fa3_creation_date;
                $submission->invoice_hash = $xml_hash;
                $submission->invoice_type = $invoice_type;
                $submission->status = 'FAILED';
                $submission->environment = $conf->global->KSEF_ENVIRONMENT ?? 'TEST';
                $submission->date_submission = dol_now();
                $submission->error_message = $e->getMessage();
                if (!empty($this->client->last_error_code)) {
                    $submission->error_code = $this->client->last_error_code;
                }
                if (!empty($this->client->last_error_details)) {
                    $submission->error_details = json_encode($this->client->last_error_details);
                }
                $submission->create($user);
            }

            return array(
                'status' => 'FAILED',
                'error' => $e->getMessage(),
                'submission_id' => $submission->rowid ?? null,
                'fa3_creation_date' => $fa3_creation_date,
                'invoice_hash' => $xml_hash,
                'can_use_offline' => true
            );
        }
    }

    /**
     * @brief Submits invoice to KSeF in offline mode
     * @param int $invoice_id Invoice ID
     * @param object $user User object
     * @param string $offline_reason Reason for offline mode (backdated/connection_error/user_choice)
     * @return array Result array with status, ksef_number, etc.
     */
    public function submitInvoiceOffline($invoice_id, $user, $offline_reason = 'user_choice')
    {
        global $conf;
        dol_syslog("KSEF::submitInvoiceOffline invoice_id=$invoice_id reason=$offline_reason", LOG_INFO);

        $cert_check = ksefIsOfflineCertificateConfigured();
        if (!$cert_check['configured']) {
            $this->error = 'Offline certificate not configured';
            dol_syslog("KSEF::submitInvoiceOffline - " . $this->error, LOG_ERR);
            return array(
                'status' => 'FAILED',
                'error' => $this->error,
                'needs_certificate' => true,
                'missing_items' => $cert_check['missing']
            );
        }

        try {
            $invoice = new Facture($this->db);
            if ($invoice->fetch($invoice_id) <= 0) {
                throw new Exception("Invoice not found: $invoice_id");
            }

            $existing = new KsefSubmission($this->db);
            $submission = new KsefSubmission($this->db);

            if ($existing->fetchByInvoice($invoice_id) > 0) {
                if ($existing->status == 'ACCEPTED') {
                    return array(
                        'status' => 'ACCEPTED',
                        'ksef_number' => $existing->ksef_number,
                        'message' => 'Invoice already accepted in KSeF'
                    );
                }

                $submission = $existing;
            }

            $offline_deadline = ksefCalculateOfflineDeadline($invoice->date);

            if (!empty($submission->fa3_xml) && !empty($submission->invoice_hash)) {
                dol_syslog("KSEF::submitInvoiceOffline - Reusing stored XML and hash", LOG_INFO);
                $fa3_xml = $submission->fa3_xml;
                $xml_hash = $submission->invoice_hash;
                $fa3_creation_date = $submission->fa3_creation_date;
            } else {
                $fa3_xml = $this->builder->buildFromInvoice($invoice_id, array('offline_mode' => true));
                if ($fa3_xml === false) {
                    throw new Exception("Failed to build FA(3) XML: " . $this->builder->error);
                }
                $xml_hash = $this->builder->getLastXmlHash();
                $fa3_creation_date = $this->builder->getLastCreationDate();
                $submission->invoice_type = $this->builder->getLastInvoiceType();
            }

            $offline_number = 'OFFLINE-' . $invoice->ref . '-' . date('YmdHis');
            $submission->fk_facture = $invoice_id;
            $submission->fa3_xml = $fa3_xml;
            $submission->fa3_creation_date = $fa3_creation_date;
            $submission->invoice_hash = $xml_hash;
            $submission->status = 'OFFLINE';
            $submission->ksef_number = $offline_number;
            $submission->environment = $conf->global->KSEF_ENVIRONMENT ?? 'TEST';
            $submission->date_submission = dol_now();
            $submission->offline_mode = 'OFFLINE';
            $submission->offline_deadline = $offline_deadline;
            $submission->offline_detected_reason = $offline_reason;
            $submission->error_message = null;

            if (!empty($submission->rowid)) {
                $submission->update($user, 1);
            } else {
                $submission->create($user);
            }

            ksefUpdateInvoiceExtrafields(
                $this->db,
                $invoice_id,
                $offline_number,
                'OFFLINE',
                dol_now(),
                true
            );

            ksefAutoGeneratePdf($this->db, $submission, $invoice_id);

            return array(
                'status' => 'OFFLINE',
                'ksef_number' => $offline_number,
                'invoice_hash' => $xml_hash,
                'submission_date' => dol_now(),
                'submission_id' => $submission->rowid,
                'offline_mode' => 'OFFLINE',
                'offline_deadline' => $offline_deadline
            );

        } catch (Exception $e) {
            $this->error = $e->getMessage();
            dol_syslog("KSEF::submitInvoiceOffline ERROR: " . $e->getMessage(), LOG_ERR);

            return array(
                'status' => 'FAILED',
                'error' => $e->getMessage()
            );
        }
    }

    /**
     * @brief Checks if invoice is backdated
     * @param $invoice Invoice object
     * @return array|false Backdate info or false if no confirmation needed
     * @called_by submitInvoice()
     * @calls ksefDetectBackdating()
     */
    private function needsOfflineConfirmation($invoice)
    {
        $backdate_info = ksefDetectBackdating($invoice->date);

        if ($backdate_info['is_backdated']) {
            return $backdate_info;
        }

        return false;
    }


    /**
     * @brief Checks status of pending submission
     * @param $invoice_id Invoice ID
     * @param $user User object

     * @return array|false Status result array on success, false on failure
     * @called_by status.php, KsefApi::checkSubmissionStatus()
     * @calls KsefClient::checkStatus(), ksefUpdateInvoiceExtrafields()
     */
    public function checkStatus($invoice_id, $user)
    {
        $this->failureReason = null;
        $submission = new KsefSubmission($this->db);
        if ($submission->fetchByInvoice($invoice_id) <= 0) {
            $this->error = "No submission found for invoice: $invoice_id";
            $this->failureReason = 'notfound';
            return false;
        }

        if (!in_array($submission->status, array('PENDING', 'OFFLINE', 'SUBMITTED', 'TIMEOUT'))) {
            return array(
                'status' => $submission->status,
                'ksef_number' => $submission->ksef_number,
                'message' => 'Submission not in pending state'
            );
        }

        if (empty($submission->ksef_reference)) {
            $this->error = "No KSEF reference number to check";
            $this->failureReason = 'noref';
            return false;
        }

        try {
            $status_result = $this->client->checkStatus($submission->ksef_reference);
        } catch (Exception $e) {
            $this->error = $e->getMessage();
            dol_syslog("KSEF::checkStatus gateway exception: " . $e->getMessage(), LOG_ERR);
            return false;
        }

        if ($status_result === false || !is_array($status_result)
            || empty($status_result['status']) || $status_result['status'] === 'UNKNOWN') {
            $this->error = !empty($this->client->error) ? $this->client->error : 'KSeF gateway returned no usable status';
            $this->failureReason = 'gateway';
            dol_syslog("KSEF::checkStatus gateway failure for invoice $invoice_id: " . $this->error, LOG_ERR);
            return false;
        }

        $submission->date_last_check = dol_now();
        if ($status_result['status'] !== $submission->status) {
            $submission->status = $status_result['status'];
            $submission->ksef_number = $status_result['ksef_number'] ?? $submission->ksef_number;
            $submission->api_response = json_encode($status_result);
        }

        if ($submission->update($user) <= 0) {
            $this->error = $submission->error ?: 'Could not persist submission status';
            $this->failureReason = 'gateway';
            dol_syslog("KSEF::checkStatus persist failed for invoice $invoice_id: " . $this->error, LOG_ERR);
            return false;
        }

        return $status_result;
    }

    /**
     * @brief Retries failed submission
     * @param $invoice_id Invoice ID
     * @param $user User object
     * @return array Result array
     * @called_by status.php mass action
     * @calls submitInvoice(), submitInvoiceOffline()
     */
    public function retrySubmission($invoice_id, $user)
    {
        try {
            $submission = new KsefSubmission($this->db);

            if ($submission->fetchByInvoice($invoice_id) <= 0) {
                throw new Exception("No submission found for invoice");
            }

            if ($submission->status == 'ACCEPTED') {
                return array(
                    'status' => 'ACCEPTED',
                    'ksef_number' => $submission->ksef_number,
                    'message' => 'Invoice already accepted'
                );
            }

            if ($submission->status == 'PENDING') {
                return array(
                    'status' => 'PENDING',
                    'message' => 'Submission already in progress, please wait'
                );
            }

            if (!in_array($submission->status, array('FAILED', 'REJECTED', 'TIMEOUT'))) {
                throw new Exception("Cannot retry submission with status: " . $submission->status);
            }

            if (!empty($submission->offline_mode)) {
                return $this->retryOfflineWithStoredXML($submission, $user);
            }

            $invoice = new Facture($this->db);
            if ($invoice->fetch($invoice_id) <= 0) {
                throw new Exception("Invoice not found");
            }

            $backdate_info = ksefDetectBackdating($invoice->date);
            if ($backdate_info['is_backdated']) {
                return array(
                    'status' => 'NEEDS_OFFLINE_CONFIRMATION',
                    'backdate_info' => $backdate_info,
                    'deadline' => $backdate_info['deadline']
                );
            }

            return $this->submitInvoice($invoice_id, $user, 'SYNC');

        } catch (Exception $e) {
            $this->error = $e->getMessage();
            return array('status' => 'ERROR', 'error' => $e->getMessage());
        }
    }


    /**
     * @brief Retries offline submission using stored XML (preserves hash)
     * @param KsefSubmission $submission Submission object with stored XML
     * @param object $user User object
     * @return array Result array
     * @called_by retrySubmission(), ksef_upload_offline action
     */
    public function retryOfflineWithStoredXML($submission, $user)
    {
        global $conf;

        $start_time = microtime(true);
        $fa3_xml = $submission->fa3_xml;
        $stored_hash = $submission->invoice_hash;

        if (empty($fa3_xml) || empty($stored_hash)) {
            $this->error = 'No stored XML or hash found in submission';
            return array(
                'status' => 'FAILED',
                'error' => $this->error
            );
        }

        try {
            $this->client->setTimeout(10);

            $api_result = $this->client->submitInvoice($fa3_xml, array(
                'invoice_hash' => $stored_hash
            ));

            $elapsed = microtime(true) - $start_time;

            if ($api_result && !empty($api_result['ksef_number']) &&
                strpos($api_result['ksef_number'], 'OFFLINE') === false) {
                $submission->status = 'ACCEPTED';
                $submission->ksef_reference = $api_result['reference_number'] ?? '';
                $submission->ksef_number = $api_result['ksef_number'];
                $submission->upo_xml = $api_result['upo_xml'] ?? null;
                $submission->api_response = json_encode($api_result);
                $submission->date_acceptance = dol_now();
                $submission->error_message = null;
                $submission->error_code = null;
                $submission->update($user, 1);

                ksefAutoGeneratePdf($this->db, $submission, (int)$submission->fk_facture);

                return array(
                    'status' => 'ACCEPTED',
                    'ksef_number' => $api_result['ksef_number'],
                    'invoice_hash' => $stored_hash,
                    'submission_date' => dol_now(),
                    'submission_id' => $submission->rowid,
                    'offline_mode' => $submission->offline_mode,
                    'reused_xml' => true
                );
            } else {
                $error_msg = $api_result['error'] ?? $this->client->error ?? 'Unknown error';
                $submission->error_message = $error_msg;
                $submission->retry_count = ($submission->retry_count ?? 0) + 1;
                if (!empty($this->client->last_error_code)) {
                    $submission->error_code = $this->client->last_error_code;
                }
                $submission->update($user, 1);

                return array(
                    'status' => 'FAILED',
                    'error' => $error_msg,
                    'error_code' => $submission->error_code,
                    'invoice_hash' => $stored_hash,
                    'submission_id' => $submission->rowid,
                    'offline_mode' => $submission->offline_mode,
                    'reused_xml' => true
                );
            }

        } catch (Exception $e) {
            $this->error = $e->getMessage();
            $this->errors[] = $e->getMessage();
            dol_syslog("KSEF::retryOfflineWithStoredXML ERROR: " . $e->getMessage(), LOG_ERR);

            $submission->error_message = $e->getMessage();
            $submission->retry_count = ($submission->retry_count ?? 0) + 1;
            $submission->update($user, 1);

            return array(
                'status' => 'FAILED',
                'error' => $e->getMessage(),
                'invoice_hash' => $stored_hash,
                'submission_id' => $submission->rowid,
                'offline_mode' => $submission->offline_mode,
                'reused_xml' => true
            );
        }
    }

    /**
     * @brief Downloads UPO (confirmation) for accepted invoice
     * @param $invoice_id Invoice ID
     * @param $user User object
     * @return string|false UPO XML or false
     * @called_by ActionsKSEF::doActions()
     * @calls KsefClient::downloadUPO()
     */
    public function downloadUPO($invoice_id, $user)
    {
        try {
            $submission = new KsefSubmission($this->db);
            if ($submission->fetchByInvoice($invoice_id) <= 0) throw new Exception("No submission found");
            if ($submission->status !== 'ACCEPTED') throw new Exception("UPO only available for accepted submissions");
            if (empty($submission->ksef_number)) throw new Exception("No KSEF number available");

            if (!empty($submission->upo_xml)) return $submission->upo_xml;

            $sessionRef = null;
            if (!empty($submission->api_response)) {
                $apiData = json_decode($submission->api_response, true);
                if (!empty($apiData['session_reference'])) {
                    $sessionRef = $apiData['session_reference'];
                }
            }
            if (empty($sessionRef) && !empty($submission->ksef_reference) && strpos($submission->ksef_reference, '-SO-') !== false) {
                $sessionRef = $submission->ksef_reference;
            }

            if (empty($sessionRef)) {
                throw new Exception("No session reference available for UPO download");
            }

            $upo_xml = $this->client->downloadUPO($submission->ksef_number, $sessionRef);

            if ($upo_xml === false) throw new Exception("Failed to download UPO: " . $this->client->error);

            $submission->upo_xml = $upo_xml;
//            $submission->upo_download_date = dol_now();
            $submission->update($user);

            return $upo_xml;

        } catch (Exception $e) {
            $this->error = $e->getMessage();
            dol_syslog("KSEF::downloadUPO ERROR: " . $e->getMessage(), LOG_ERR);
            return false;
        }
    }

    /**
     * @brief Submits technical correction for rejected offline invoice
     * @param $invoice_id Invoice ID
     * @param $original_submission_id Original submission ID (rejected)
     * @param $user User object
     * @return array Submission result
     * @called_by ActionsKSEF::doActions()
     */
    public function submitTechnicalCorrection($invoice_id, $original_submission_id, $user)
    {
        global $conf;

        try {
            $original = new KsefSubmission($this->db);
            if ($original->fetch($original_submission_id) <= 0) {
                throw new Exception("Original submission not found");
            }

            if (!in_array($original->status, ['REJECTED', 'FAILED'])) {
                throw new Exception("Technical correction only for rejected/failed submissions");
            }

            if (empty($original->invoice_hash)) {
                throw new Exception("Original invoice hash not found - cannot create technical correction");
            }

            if (empty($original->offline_mode)) {
                throw new Exception("Technical correction only available for offline submissions");
            }

            $new_xml = $this->builder->buildFromInvoice($invoice_id);
            if (!$new_xml) {
                throw new Exception("Failed to regenerate XML: " . $this->builder->error);
            }

            $new_hash = base64_encode(hash('sha256', $new_xml, true));

            $api_result = $this->client->submitInvoice($new_xml, array(
                'offline_mode' => true,
                'invoice_hash' => $new_hash,
                'corrected_hash' => $original->invoice_hash
            ));

            if ($api_result === false) {
                throw new Exception($this->client->error ?: 'Technical correction submission failed');
            }

            $submission = new KsefSubmission($this->db);
            $submission->fk_facture = $invoice_id;
            $submission->fa3_xml = $new_xml;
            $submission->invoice_hash = $new_hash;
            $submission->invoice_type = $this->builder->getLastInvoiceType();
            $submission->original_invoice_hash = $original->invoice_hash;
            $submission->offline_mode = $original->offline_mode;
            $submission->offline_deadline = $original->offline_deadline;
            $submission->offline_detected_reason = 'Technical correction of submission #' . $original_submission_id;
            $submission->status = $api_result['status'] ?? 'PENDING';
            $submission->environment = $conf->global->KSEF_ENVIRONMENT ?? 'TEST';
            $submission->date_submission = dol_now();
            $submission->ksef_reference = $api_result['reference_number'] ?? '';
            $submission->ksef_number = $api_result['ksef_number'] ?? null;
            $submission->api_response = json_encode($api_result);

            $submission->create($user);

            return array(
                'status' => $api_result['status'] ?? 'PENDING',
                'ksef_number' => $api_result['ksef_number'] ?? null,
                'original_submission_id' => $original_submission_id,
                'new_submission_id' => $submission->rowid
            );

        } catch (Exception $e) {
            $this->error = $e->getMessage();
            dol_syslog("KSEF::submitTechnicalCorrection ERROR: " . $e->getMessage(), LOG_ERR);
            return array('status' => 'ERROR', 'error' => $e->getMessage());
        }
    }


    /**
     * @brief Initiate fetch of incoming invoices
     * @param User $user Current user
     * @return array|false Result with status/reference or false
     */
    public function initIncomingFetch($user)
    {
        dol_include_once('/ksef/class/ksef_sync_state.class.php');
        dol_include_once('/ksef/class/ksef_client.class.php');

        dol_include_once('/ksef/class/ksef_latarnia.class.php');
        $latarnia = new KsefLatarnia($this->db);
        $latarnia->refreshIfStale();

        $environment = getDolGlobalString('KSEF_ENVIRONMENT', 'TEST');

        $syncState = new KsefSyncState($this->db);
        $syncState->load('incoming');

        if ($syncState->isFetchInProgress()) {
            if ($syncState->isFetchTimedOut()) {
                dol_syslog("KSeF: Previous fetch timed out, clearing state", LOG_WARNING);
                $syncState->clearFetchState();
            } else {
                return array(
                    'status' => 'ALREADY_PROCESSING',
                    'reference' => $syncState->fetch_reference,
                    'started' => $syncState->fetch_started
                );
            }
        }

        $client = new KsefClient($this->db, $environment);

        $encryptionData = $client->generateEncryptionData();
        if (!$encryptionData) {
            $this->error = $client->error;
            return false;
        }

        // HWM date or default to 2026-01-31 (day before KSeF start date)
        $fromDate = !empty($syncState->hwm_date) ? $syncState->hwm_date : '2026-01-31T00:00:00+01:00';

        $maxChunkDays = 60;
        $fromTs = strtotime($fromDate);
        $toDate = null;

        if ($fromTs !== false) {
            $chunkEndTs = strtotime("+{$maxChunkDays} days", $fromTs);
            if ($chunkEndTs < time()) {
                $toDate = date('c', $chunkEndTs);
                dol_syslog("KSeF: Date range exceeds {$maxChunkDays} days, chunking to: " . $toDate, LOG_INFO);
            }
        }

        dol_syslog("KSeF: Initiating incoming fetch from: " . $fromDate . ($toDate ? " to: " . $toDate : ''), LOG_INFO);

        $initResult = $client->initHwmExport($fromDate, 'subject2', $encryptionData['encryption_info'], $toDate);
        if (!$initResult) {
            $this->error = $client->error;
            return false;
        }

        $syncState->fetch_reference = $initResult['referenceNumber'];
        $syncState->fetch_status = KsefSyncState::FETCH_STATUS_PROCESSING;
        $syncState->fetch_started = dol_now();
        $syncState->fetch_key = dol_encode(base64_encode($encryptionData['aes_key']));
        $syncState->fetch_iv = dol_encode(base64_encode($encryptionData['iv']));
        $syncState->fetch_error = '';
        $syncState->save();

        dol_syslog("KSeF: Initiated fetch, reference: " . $initResult['referenceNumber'], LOG_INFO);

        return array(
            'status' => 'INITIATED',
            'reference' => $initResult['referenceNumber']
        );
    }

    /**
     * @brief Check fetch status and process if ready
     * @param User $user Current user
     * @return array Result with status and details
     */
    public function checkIncomingFetchStatus($user)
    {
        dol_include_once('/ksef/class/ksef_sync_state.class.php');
        dol_include_once('/ksef/class/ksef_client.class.php');
        dol_include_once('/ksef/class/ksef_incoming.class.php');

        $environment = getDolGlobalString('KSEF_ENVIRONMENT', 'TEST');

        $syncState = new KsefSyncState($this->db);
        $syncState->load('incoming');

        if ($syncState->isProcessingInProgress()) {
            return $this->continueProcessingBatch($syncState, $user, $environment);
        }

        if (!$syncState->isFetchInProgress()) {
            return array('status' => 'NO_PENDING_FETCH');
        }

        if ($syncState->fetch_status === KsefSyncState::FETCH_STATUS_READY_TO_PROCESS) {
            return array(
                'status' => 'READY_TO_PROCESS',
                'reference' => $syncState->fetch_reference,
                'invoice_count' => $syncState->process_total,
            );
        }

        if ($syncState->fetch_status === KsefSyncState::FETCH_STATUS_DOWNLOADING) {
            return array(
                'status' => 'DOWNLOADING',
                'reference' => $syncState->fetch_reference,
                'parts_total' => $syncState->process_total,
                'parts_done' => $syncState->process_offset,
            );
        }

        if ($syncState->fetch_status === KsefSyncState::FETCH_STATUS_PROCESSING_BATCHES) {
            return array(
                'status' => 'PROCESSING_BATCHES',
                'reference' => $syncState->fetch_reference,
                'total' => $syncState->process_total,
                'processed' => $syncState->process_offset,
                'new' => $syncState->process_new,
                'existing' => $syncState->process_existing,
            );
        }

        if ($syncState->isFetchTimedOut()) {
            $syncState->fetch_status = KsefSyncState::FETCH_STATUS_TIMEOUT;
            $syncState->fetch_error = 'Fetch request timed out';
            $syncState->save();
            return array('status' => 'TIMEOUT', 'reference' => $syncState->fetch_reference);
        }

        $client = new KsefClient($this->db, $environment);
        $status = $client->getExportStatus($syncState->fetch_reference);

        if (!$status) {
            if (!empty($client->retry_after_seconds)) {
                return array('status' => 'RATE_LIMITED', 'retry_after' => $client->retry_after_seconds);
            }
            return array('status' => 'CHECK_FAILED', 'error' => $client->error);
        }

        if ($status['status'] === 'PROCESSING') {
            return array(
                'status' => 'PROCESSING',
                'reference' => $syncState->fetch_reference,
                'elapsed_seconds' => dol_now() - $syncState->fetch_started
            );
        }

        if ($status['status'] === 'FAILED') {
            $errorMsg = $status['raw']['status']['description'] ?? 'Unknown error';
            $syncState->fetch_status = KsefSyncState::FETCH_STATUS_FAILED;
            $syncState->fetch_error = $errorMsg;
            $syncState->save();
            return array('status' => 'FAILED', 'error' => $errorMsg);
        }

        if ($status['status'] === 'COMPLETED') {
            $invoiceCount = $status['raw']['package']['invoiceCount'] ?? 0;
            $partsCount = count($status['parts'] ?? []);

            if ($invoiceCount == 0 || $partsCount == 0) {
                dol_syslog("KSeF: Export completed but no invoices to download (invoiceCount: {$invoiceCount}, parts: {$partsCount})", LOG_INFO);

                if (!empty($status['permanentStorageHwmDate'])) {
                    $syncState->hwm_date = date('c', $status['permanentStorageHwmDate']);
                    dol_syslog("KSeF: Updated HWM to: " . $syncState->hwm_date, LOG_INFO);
                }

                $syncState->fetch_reference = '';
                $syncState->fetch_status = '';
                $syncState->fetch_started = 0;
                $syncState->fetch_key = '';
                $syncState->fetch_iv = '';
                $syncState->fetch_error = '';
                $syncState->fetch_parts = '';
                $syncState->fetch_hwm_data = '';
                $syncState->last_sync = dol_now();
                $syncState->last_sync_new = 0;
                $syncState->last_sync_existing = 0;
                $syncState->last_sync_total = 0;
                $syncState->last_sync_errors = '';
                $syncState->save();

                return array(
                    'status' => 'COMPLETED',
                    'new' => 0,
                    'existing' => 0,
                    'total' => 0,
                    'errors' => 0,
                    'message' => 'No invoices to sync'
                );
            }

            dol_syslog("KSeF: Export completed, ({$invoiceCount} invoices, {$partsCount} parts)", LOG_INFO);

            $hwmData = array(
                'isTruncated' => !empty($status['isTruncated']),
                'lastPermanentStorageDate' => $status['lastPermanentStorageDate'] ?? null,
                'permanentStorageHwmDate' => $status['permanentStorageHwmDate'] ?? null,
                'completedDate' => $status['completedDate'] ?? null,
                'invoiceCount' => $invoiceCount,
            );

            $syncState->fetch_parts = json_encode($status['parts']);
            $syncState->fetch_hwm_data = json_encode($hwmData);
            $syncState->fetch_status = KsefSyncState::FETCH_STATUS_READY_TO_PROCESS;
            $syncState->process_total = $invoiceCount;
            $syncState->process_offset = 0;
            $syncState->process_new = 0;
            $syncState->process_existing = 0;
            $syncState->save();

            return array(
                'status' => 'READY_TO_PROCESS',
                'reference' => $syncState->fetch_reference,
                'invoice_count' => $invoiceCount,
                'parts_count' => $partsCount,
            );
        }

        dol_syslog("KSeF: Unknown export status: " . $status['status'], LOG_WARNING);
        return array('status' => $status['status']);
    }


    /**
     * @brief Download and process incoming invoices
     * @param User $user Current user
     * @return array Result with status and counts
     */
    public function processIncomingDownload($user)
    {
        dol_include_once('/ksef/class/ksef_sync_state.class.php');
        dol_include_once('/ksef/class/ksef_client.class.php');
        dol_include_once('/ksef/class/ksef_incoming.class.php');

        $environment = getDolGlobalString('KSEF_ENVIRONMENT', 'TEST');

        $syncState = new KsefSyncState($this->db);
        $syncState->load('incoming');

        if ($syncState->fetch_status !== KsefSyncState::FETCH_STATUS_READY_TO_PROCESS) {
            dol_syslog("KSeF: processIncomingDownload called but status is " . $syncState->fetch_status, LOG_WARNING);
            return array('status' => 'SKIPPED', 'reason' => 'Not in READY_TO_PROCESS state');
        }

        $parts = json_decode($syncState->fetch_parts, true);
        $hwmData = json_decode($syncState->fetch_hwm_data, true);

        if (empty($parts)) {
            dol_syslog("KSeF: processIncomingDownload no parts found", LOG_ERR);
            $syncState->fetch_status = KsefSyncState::FETCH_STATUS_FAILED;
            $syncState->fetch_error = 'No download parts found in state';
            $syncState->save();
            return array('status' => 'FAILED', 'error' => 'No download parts');
        }

        $aesKey = base64_decode(dol_decode($syncState->fetch_key));
        $iv = base64_decode(dol_decode($syncState->fetch_iv));

        if (empty($aesKey) || empty($iv)) {
            dol_syslog("KSeF: Key decryption failed", LOG_ERR);
            $syncState->fetch_status = KsefSyncState::FETCH_STATUS_FAILED;
            $syncState->fetch_error = 'Key decryption failed';
            $syncState->save();
            return array('status' => 'FAILED', 'error' => 'Key decryption failed');
        }

        $partsTotal = count($parts);
        $syncState->fetch_status = KsefSyncState::FETCH_STATUS_DOWNLOADING;
        $syncState->process_total = $partsTotal;
        $syncState->process_offset = 0;
        $syncState->save();

        dol_syslog("KSeF: Starting async download ({$partsTotal} parts)", LOG_INFO);

        $client = new KsefClient($this->db, $environment);
        $tempZipFile = null;
        try {
            $tempFile = tempnam(sys_get_temp_dir(), 'ksef_dl_');
            if ($tempFile === false) {
                throw new Exception("Failed to create temp file");
            }

            $fp = fopen($tempFile, 'wb');
            if ($fp === false) {
                throw new Exception("Failed to open temp file for writing");
            }

            foreach ($parts as $index => $part) {
                $encrypted = $client->downloadPackagePart($part);
                if ($encrypted === false) {
                    fclose($fp);
                    @unlink($tempFile);
                    throw new Exception("Failed to download part $index");
                }

                $decrypted = $client->decryptPackageData($encrypted, $aesKey, $iv);
                if ($decrypted === false) {
                    fclose($fp);
                    @unlink($tempFile);
                    throw new Exception("Failed to decrypt part $index");
                }

                fwrite($fp, $decrypted);
                unset($encrypted, $decrypted);

                $syncState->process_offset = $index + 1;
                $syncState->save();
            }

            fclose($fp);
            $tempZipFile = $tempFile;
            dol_syslog("KSeF: Package downloaded to temp file: " . $tempZipFile . " (" . filesize($tempZipFile) . " bytes)", LOG_INFO);
        } catch (Exception $e) {
            dol_syslog("KSeF: Download exception: " . $e->getMessage(), LOG_ERR);
            $syncState->fetch_status = KsefSyncState::FETCH_STATUS_FAILED;
            $syncState->fetch_error = 'Download exception: ' . $e->getMessage();
            $syncState->save();
            return array('status' => 'DOWNLOAD_FAILED', 'error' => $e->getMessage());
        }

        $isTruncated = !empty($hwmData['isTruncated']);

        if ($isTruncated && !empty($hwmData['lastPermanentStorageDate'])) {
            $syncState->hwm_date = date('c', $hwmData['lastPermanentStorageDate']);
            dol_syslog("KSeF: Updated HWM to: " . $syncState->hwm_date . " (from lastPermanentStorageDate - batch was truncated)", LOG_INFO);
        } elseif (!empty($hwmData['permanentStorageHwmDate'])) {
            $syncState->hwm_date = date('c', $hwmData['permanentStorageHwmDate']);
            dol_syslog("KSeF: Updated HWM to: " . $syncState->hwm_date . " (from permanentStorageHwmDate - batch complete)", LOG_INFO);
        } elseif (!empty($hwmData['lastPermanentStorageDate'])) {
            $syncState->hwm_date = date('c', $hwmData['lastPermanentStorageDate']);
            dol_syslog("KSeF: Updated HWM to: " . $syncState->hwm_date . " (fallback from lastPermanentStorageDate)", LOG_INFO);
        } elseif (!empty($hwmData['completedDate'])) {
            $ts = strtotime($hwmData['completedDate']);
            if ($ts !== false) {
                $syncState->hwm_date = date('c', $ts);
                dol_syslog("KSeF: Updated HWM to: " . $syncState->hwm_date . " (last resort fallback from completedDate)", LOG_INFO);
            }
        }

        $syncState->fetch_status = KsefSyncState::FETCH_STATUS_PROCESSING_BATCHES;
        $syncState->process_total = $hwmData['invoiceCount'] ?? 0;
        $syncState->process_offset = 0;
        $syncState->process_new = 0;
        $syncState->process_existing = 0;
        $syncState->process_file = $tempZipFile;
        $syncState->save();

        dol_syslog("KSeF: Starting batch processing", LOG_INFO);

        $batchSize = KsefSyncState::PROCESS_BATCH_SIZE;
        $totalNew = 0;
        $totalExisting = 0;
        $totalErrors = 0;
        $allErrorDetails = array();
        $ksef = $this;

        try {
            $summary = $client->processPackageInBatches($tempZipFile, $batchSize, function($batch, $batchNum, $totalFiles) use ($ksef, $user, $environment, &$totalNew, &$totalExisting, &$totalErrors, &$allErrorDetails, $syncState) {
                dol_syslog("KSeF: Starting callback for batch {$batchNum} with " . count($batch) . " invoices", LOG_INFO);

                $batchData = array('invoices' => $batch, 'metadata' => array());

                try {
                    $result = $ksef->processIncomingPackage($batchData, $user, $environment);
                    $totalNew += $result['new'];
                    $totalExisting += $result['existing'];
                    $totalErrors += $result['errors'] ?? 0;
                    if (!empty($result['error_details'])) {
                        $allErrorDetails = array_merge($allErrorDetails, $result['error_details']);
                    }
                    dol_syslog("KSeF: Batch {$batchNum} complete - new: {$result['new']}, existing: {$result['existing']}, errors: {$result['errors']}", LOG_INFO);
                } catch (Exception $e) {
                    dol_syslog("KSeF: Batch {$batchNum} EXCEPTION: " . $e->getMessage(), LOG_ERR);
                    $totalErrors++;
                    throw $e;
                } catch (Error $e) {
                    dol_syslog("KSeF: Batch {$batchNum} FATAL ERROR: " . $e->getMessage(), LOG_ERR);
                    $totalErrors++;
                    throw $e;
                }

                $syncState->process_offset += count($batch);
                $syncState->process_new = $totalNew;
                $syncState->process_existing = $totalExisting;
                $syncState->save();

                unset($batchData);
                unset($batch);
                gc_collect_cycles();
            });
        } catch (Exception $e) {
            dol_syslog("KSeF: Batch processing exception: " . $e->getMessage(), LOG_ERR);
            @unlink($tempZipFile);
            $syncState->last_sync = dol_now();
            $syncState->last_sync_new = $totalNew;
            $syncState->last_sync_existing = $totalExisting;
            $syncState->last_sync_total = $totalNew + $totalExisting;
            $syncState->last_sync_errors = json_encode(array_slice($allErrorDetails, 0, 100));
            $syncState->fetch_status = KsefSyncState::FETCH_STATUS_FAILED;
            $syncState->fetch_error = 'Processing interrupted: ' . $e->getMessage();
            $syncState->fetch_parts = '';
            $syncState->fetch_hwm_data = '';
            $syncState->process_file = '';
            $syncState->save();
            return array(
                'status' => 'FAILED',
                'new' => $totalNew,
                'existing' => $totalExisting,
                'total' => $totalNew + $totalExisting,
                'errors' => 1,
                'error_details' => array('Processing interrupted: ' . $e->getMessage()),
                'truncated' => $isTruncated
            );
        }

        @unlink($tempZipFile);

        $finalTotal = $summary ? $summary['total'] : ($totalNew + $totalExisting);
        $syncState->last_sync = dol_now();
        $syncState->last_sync_new = $totalNew;
        $syncState->last_sync_existing = $totalExisting;
        $syncState->last_sync_total = $finalTotal;
        $syncState->last_sync_errors = !empty($allErrorDetails) ? json_encode(array_slice($allErrorDetails, 0, 100)) : '';
        $syncState->fetch_reference = '';
        $syncState->fetch_status = '';
        $syncState->fetch_started = 0;
        $syncState->fetch_key = '';
        $syncState->fetch_iv = '';
        $syncState->fetch_error = '';
        $syncState->fetch_parts = '';
        $syncState->fetch_hwm_data = '';
        $syncState->process_file = '';
        $syncState->process_total = 0;
        $syncState->process_offset = 0;
        $syncState->process_new = 0;
        $syncState->process_existing = 0;
        $syncState->save();

        dol_syslog("KSeF: All batches complete - new: {$totalNew}, existing: {$totalExisting}, total: {$finalTotal}", LOG_INFO);

        return array(
            'status' => 'COMPLETED',
            'new' => $totalNew,
            'existing' => $totalExisting,
            'total' => $finalTotal,
            'errors' => $totalErrors,
            'truncated' => $isTruncated
        );
    }


    /**
     * @brief Process downloaded invoice package
     * @param array $packageData From downloadAndExtractPackage()
     * @param User $user Current user
     * @param string $environment KSeF environment
     * @return array Result with new/existing/errors counts
     */
    public function processIncomingPackage($packageData, $user, $environment)
    {
        dol_include_once('/ksef/class/fa3_parser.class.php');

        $result = array('new' => 0, 'existing' => 0, 'errors' => 0, 'total' => 0, 'error_details' => array());

        if (empty($packageData['invoices'])) {
            dol_syslog("KSeF: No invoices in package", LOG_INFO);
            return $result;
        }

        $invoiceCount = count($packageData['invoices']);
        dol_syslog("KSeF: processIncomingPackage starting with {$invoiceCount} invoices", LOG_INFO);

        $incoming = new KsefIncoming($this->db);
        $parser = new FA3Parser($this->db);

        $existingNumbers = $incoming->getExistingKsefNumbers(array_keys($packageData['invoices']), $environment);
        $existingSet = array_flip($existingNumbers);

        foreach ($packageData['invoices'] as $ksefNumber => $xmlContent) {
            $result['total']++;

            if (isset($existingSet[$ksefNumber])) {
                $result['existing']++;
                unset($packageData['invoices'][$ksefNumber]);
                continue;
            }

            $this->db->begin();
            try {
                $parsed = $parser->parse($xmlContent);
                if (!$parsed) {
                    throw new Exception('Failed to parse XML: ' . ($parser->error ?: 'unknown error'));
                }

                $newIncoming = new KsefIncoming($this->db);
                if ($newIncoming->createFromParsed($parsed, $xmlContent, $ksefNumber, $environment, $user, true) > 0) {
                    $this->db->commit();
                    $result['new']++;
                } else {
                    throw new Exception($newIncoming->error ?: 'Create failed');
                }
            } catch (Exception $e) {
                $this->db->rollback();
                $result['errors']++;
                $result['error_details'][] = "{$ksefNumber}: " . $e->getMessage();
                dol_syslog("KSeF: Error processing {$ksefNumber}: " . $e->getMessage(), LOG_ERR);
            }

            unset($packageData['invoices'][$ksefNumber]);
        }

        return $result;
    }


    /**
     * @brief Handle status check
     * @param KsefSyncState $syncState Current sync state
     * @param User $user Current user
     * @param string $environment KSeF environment
     * @return array Status array for AJAX response
     */
    private function continueProcessingBatch($syncState, $user, $environment)
    {
        if ($syncState->isProcessingTimedOut()) {
            dol_syslog("KSeF: Batch processing timed out - marking as failed", LOG_WARNING);
            $syncState->last_sync = dol_now();
            $syncState->last_sync_new = $syncState->process_new;
            $syncState->last_sync_existing = $syncState->process_existing;
            $syncState->last_sync_total = $syncState->process_new + $syncState->process_existing;
            $syncState->fetch_status = KsefSyncState::FETCH_STATUS_FAILED;
            $syncState->fetch_error = 'Batch processing timed out. '
                . $syncState->process_new . ' new and '
                . $syncState->process_existing . ' existing were processed before timeout. '
                . 'Use "Reset & Retry" to restart.';
            $syncState->clearProcessingState();
            $syncState->save();
            return array(
                'status' => 'FAILED',
                'error' => $syncState->fetch_error,
                'new' => $syncState->process_new,
                'existing' => $syncState->process_existing,
            );
        }

        return array(
            'status' => 'PROCESSING_BATCHES',
            'reference' => $syncState->fetch_reference,
            'total' => $syncState->process_total,
            'processed' => $syncState->process_offset,
            'new' => $syncState->process_new,
            'existing' => $syncState->process_existing,
        );
    }


    /**
     * @brief Reset failed fetch state
     * @return bool Success
     */
    public function resetIncomingFetch()
    {
        dol_include_once('/ksef/class/ksef_sync_state.class.php');

        $syncState = new KsefSyncState($this->db);
        $syncState->load('incoming');
        return $syncState->clearFetchState() > 0;
    }


    /**
     * @brief Get current fetch state for UI
     * @return KsefSyncState State
     */
    public function getIncomingSyncState()
    {
        dol_include_once('/ksef/class/ksef_sync_state.class.php');

        $syncState = new KsefSyncState($this->db);
        $syncState->load('incoming');
        return $syncState;
    }

    /**
     * @brief Reset sync state to 2026-01-31
     * @param User $user Current user
     * @return bool Success
     */
    public function resetIncomingSyncState($user)
    {
        dol_include_once('/ksef/class/ksef_sync_state.class.php');

        $syncState = new KsefSyncState($this->db);
        $syncState->load('incoming');
        $syncState->fetch_reference = '';
        $syncState->fetch_status = '';
        $syncState->fetch_started = 0;
        $syncState->fetch_key = '';
        $syncState->fetch_iv = '';
        $syncState->fetch_error = '';
        $syncState->hwm_date = '2026-01-31T00:00:00+01:00';
        $syncState->last_sync = 0;
        $syncState->last_sync_new = 0;
        $syncState->last_sync_existing = 0;
        $syncState->last_sync_total = 0;
        $syncState->rate_limit_until = 0;
        return $syncState->save() > 0;
    }

    /**
     * Check statuses of pending/submitted invoices.
     * @return int 0=OK, negative=error
     */
    public function cronCheckStatuses()
    {
        global $conf, $user;
        $this->output = '';
        $processed = 0;
        $errors = 0;

        try {
            dol_include_once('/ksef/class/ksef_submission.class.php');
            dol_include_once('/ksef/class/ksef_client.class.php');
            dol_include_once('/ksef/lib/ksef.lib.php');

            $submission = new KsefSubmission($this->db);
            $pending = $submission->fetchPending(
                $conf->global->KSEF_ENVIRONMENT ?? 'TEST'
            );

            if (!$pending || count($pending) == 0) {
                $this->output = 'No pending submissions found';
                return 0;
            }

            $ksefClient = new KsefClient(
                $this->db, $conf->global->KSEF_ENVIRONMENT ?? 'TEST'
            );

            foreach ($pending as $sub) {
                try {
                    // expired offline deadlines
                    if ($sub->status == KsefSubmission::STATUS_PENDING
                        && !empty($sub->offline_mode)
                        && !empty($sub->offline_deadline)
                        && function_exists('ksefIsDeadlinePassed')
                        && ksefIsDeadlinePassed($sub->offline_deadline)
                    ) {
                        $sub->status = KsefSubmission::STATUS_FAILED;
                        $sub->error_message =
                            'Offline deadline passed without successful submission';
                        $sub->date_last_check = dol_now();
                        $sub->update($user, 1);
                        $processed++;
                        continue;
                    }

                    // offline pending submissions
                    if ($sub->status == KsefSubmission::STATUS_PENDING
                        && !empty($sub->offline_mode)
                    ) {
                        $result = $this->retrySubmission($sub->fk_facture, $user);
                        $processed++;
                        continue;
                    }

                    // status for submitted/timeout invoices
                    if (in_array($sub->status, array(
                        KsefSubmission::STATUS_SUBMITTED,
                        KsefSubmission::STATUS_TIMEOUT
                    ))) {
                        $invoiceStatus = $ksefClient->checkInvoiceInSession(
                            $sub->ksef_reference, $sub->ksef_reference
                        );

                        if ($invoiceStatus) {
                            if ($invoiceStatus['status'] == 'ACCEPTED') {
                                $sub->status = KsefSubmission::STATUS_ACCEPTED;
                                $sub->ksef_number = $invoiceStatus['ksef_number'];
                                $sub->date_acceptance = dol_now();
                            } elseif ($invoiceStatus['status'] == 'REJECTED') {
                                $sub->status = KsefSubmission::STATUS_REJECTED;
                                $sub->error_code = $ksefClient->last_error_code;
                                $sub->error_message = $ksefClient->error;
                                $sub->error_details =
                                    json_encode($ksefClient->last_error_details);
                            }
                        }
                    }

                    $sub->date_last_check = dol_now();
                    $sub->update($user, 1);

                    if ($sub->status == KsefSubmission::STATUS_ACCEPTED) {
                        ksefAutoGeneratePdf($this->db, $sub, (int)$sub->fk_facture);
                    }

                    $processed++;

                } catch (Exception $e) {
                    $sub->error_message = $e->getMessage();
                    $sub->date_last_check = dol_now();
                    $sub->update($user, 1);
                    $errors++;
                }
            }

            $this->output = "Processed: $processed, Errors: $errors";
            return ($errors > 0 && $processed == 0) ? -1 : 0;

        } catch (Exception $e) {
            $this->error = $e->getMessage();
            $this->output = 'Fatal error: ' . $e->getMessage();
            return -1;
        }
    }

    /**
     * Cron: Sync incoming invoices from KSeF.
     * @return int 0=OK, negative=error
     */
    public function cronSyncIncoming()
    {
        global $conf, $user;

        dol_syslog("KSEF::cronSyncIncoming START", LOG_INFO);
        $this->output = '';

        try {
            dol_include_once('/ksef/class/ksef_sync_state.class.php');

            $syncState = new KsefSyncState($this->db);
            $syncState->load('incoming');

            if (!$syncState->canSyncNow()) {
                $reason = '';
                if ($syncState->isFetchInProgress()) {
                    $reason = 'Fetch already in progress';
                } elseif ($syncState->isProcessingInProgress()) {
                    $reason = 'Processing already in progress';
                } elseif ($syncState->isRateLimited()) {
                    $reason = 'Rate limited until '
                        . $syncState->getRateLimitExpiryFormatted();
                }
                $this->output = 'Skipped: ' . $reason;
                return 0;
            }

            $initResult = $this->initIncomingFetch($user);

            if (!$initResult) {
                $this->error = $this->error ?: 'Failed to initiate fetch';
                $this->output = 'Init failed: ' . $this->error;
                return -1;
            }

            if ($initResult['status'] === 'ALREADY_PROCESSING') {
                dol_syslog("KSEF::cronSyncIncoming fetch already initiated, polling", LOG_INFO);
            }

            $maxWait = 300;
            $elapsed = 0;
            $interval = 30;

            while ($elapsed < $maxWait) {
                sleep($interval);
                $elapsed += $interval;

                $result = $this->checkIncomingFetchStatus($user);

                if (empty($result) || empty($result['status'])) {
                    $this->error = 'Empty status response';
                    $this->output = 'Polling failed: empty status';
                    return -1;
                }

                $status = $result['status'];

                if ($status === 'READY_TO_PROCESS') {
                    dol_syslog("KSEF::cronSyncIncoming READY_TO_PROCESS, starting download+process", LOG_INFO);
                    $processResult = $this->processIncomingDownload($user);
                    $new = $processResult['new'] ?? 0;
                    $existing = $processResult['existing'] ?? 0;
                    $total = $processResult['total'] ?? ($new + $existing);
                    if (in_array($processResult['status'], array('COMPLETED'))) {
                        $this->output = "Sync complete. New: $new, Existing: $existing, Total: $total";
                        return 0;
                    } else {
                        $this->error = $processResult['error'] ?? $processResult['status'];
                        $this->output = 'Processing failed: ' . $this->error;
                        return -1;
                    }
                }

                if ($status === 'COMPLETED') {
                    $new = $result['new'] ?? 0;
                    $existing = $result['existing'] ?? 0;
                    $total = $result['total'] ?? ($new + $existing);
                    $this->output = "Sync complete. New: $new, "
                        . "Existing: $existing, Total: $total";
                    return 0;
                }

                if (in_array($status, array(
                    'FAILED', 'TIMEOUT', 'DOWNLOAD_FAILED', 'CHECK_FAILED'
                ))) {
                    $this->error = $result['error'] ?? $status;
                    $this->output = 'Sync failed: ' . $this->error;
                    return -1;
                }

                if ($status === 'RATE_LIMITED') {
                    $this->output = 'Rate limited, will resume on next run';
                    return 0;
                }

                if (in_array($status, array('DOWNLOADING', 'PROCESSING_BATCHES'))) {
                    dol_syslog("KSEF::cronSyncIncoming status=$status, waiting for background process to finish", LOG_INFO);
                    sleep(5);
                }
            }

            $this->output = 'Sync timed out after ' . $maxWait
                . 's, will resume on next run';
            return 0;

        } catch (Exception $e) {
            $this->error = $e->getMessage();
            $this->output = 'Fatal error: ' . $e->getMessage();
            return -1;
        }
    }

    /**
     * Cron: Download UPO confirmations for accepted invoices that lack one.
     * @return int 0=OK, negative=error
     */
    public function cronDownloadUPOs()
    {
        global $conf, $user;

        dol_syslog("KSEF::cronDownloadUPOs START", LOG_INFO);
        $this->output = '';

        try {
            dol_include_once('/ksef/class/ksef_submission.class.php');

            $sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "ksef_submissions"
                . " WHERE status = 'ACCEPTED'"
                . " AND upo_xml IS NULL"
                . " AND ksef_number IS NOT NULL"
                . " AND environment = '"
                . $this->db->escape($conf->global->KSEF_ENVIRONMENT ?? 'TEST')
                . "'"
                . " ORDER BY date_acceptance ASC LIMIT 50";

            $resql = $this->db->query($sql);
            if (!$resql) {
                $this->error = $this->db->lasterror();
                $this->output = 'Query error: ' . $this->error;
                return -1;
            }

            $downloaded = 0;
            $failed = 0;

            while ($obj = $this->db->fetch_object($resql)) {
                $sub = new KsefSubmission($this->db);
                if ($sub->fetch($obj->rowid) > 0 && !empty($sub->ksef_number)) {
                    try {
                        $sessionRef = null;
                        if (!empty($sub->api_response)) {
                            $apiData = json_decode($sub->api_response, true);
                            if (!empty($apiData['session_reference'])) {
                                $sessionRef = $apiData['session_reference'];
                            }
                        }

                        $upo = $this->client->downloadUPO($sub->ksef_number, $sessionRef);
                        if ($upo) {
                            $sub->upo_xml = $upo;
                            $sub->update($user, 1);
                            $downloaded++;
                        } else {
                            $failed++;
                        }
                    } catch (Exception $e) {
                        dol_syslog("KSEF::cronDownloadUPOs error for "
                            . $sub->ksef_number . ": " . $e->getMessage(),
                            LOG_ERR);
                        $failed++;
                    }
                }
            }
            $this->db->free($resql);

            $this->output = "Downloaded: $downloaded, Failed: $failed";
            return 0;

        } catch (Exception $e) {
            $this->error = $e->getMessage();
            $this->output = 'Fatal error: ' . $e->getMessage();
            return -1;
        }
    }

    /**
     * Cron: Warn about approaching offline submission deadlines.
     * @return int 0=OK, negative=error
     */
    public function cronWarnDeadlines()
    {
        global $conf;

        dol_syslog("KSEF::cronWarnDeadlines START", LOG_INFO);
        $this->output = '';

        try {
            dol_include_once('/ksef/class/ksef_submission.class.php');

            $submission = new KsefSubmission($this->db);
            $approaching = $submission->fetchCronJobs(24);

            if (!$approaching || count($approaching) == 0) {
                $this->output = 'No approaching deadlines';
                return 0;
            }

            $warnings = 0;
            foreach ($approaching as $sub) {
                $hoursLeft = round(
                    ($sub->offline_deadline - dol_now()) / 3600, 1
                );
                dol_syslog(
                    "KSEF DEADLINE WARNING: Submission #"
                    . $sub->rowid . " (invoice_id=" . $sub->fk_facture
                    . ") has offline deadline in " . $hoursLeft . " hours",
                    LOG_WARNING
                );
                $warnings++;
            }

            $this->output = "Deadlines approaching: $warnings invoices";
            return 0;

        } catch (Exception $e) {
            $this->error = $e->getMessage();
            $this->output = 'Fatal error: ' . $e->getMessage();
            return -1;
        }
    }

    /**
     * Cron job: Check KSeF Latarnia status
     * @return int 0 on success, -1 on error
     */
    public function cronCheckLatarniaStatus()
    {
        dol_syslog("KSEF::cronCheckLatarniaStatus START", LOG_INFO);
        $this->output = '';

        try {
            dol_include_once('/ksef/class/ksef_latarnia.class.php');

            $latarnia = new KsefLatarnia($this->db);
            $result = $latarnia->checkAndCache();

            if ($result !== false) {
                $status = $result['status'];
                $msgCount = count($result['messages']);
                $this->output = "KSeF Latarnia status: $status ($msgCount messages)";
                dol_syslog("KSEF::cronCheckLatarniaStatus Result: $status ($msgCount messages)", LOG_INFO);
                return 0;
            }

            $this->output = 'Latarnia unreachable: ' . $latarnia->error;
            dol_syslog("KSEF::cronCheckLatarniaStatus WARNING: " . $latarnia->error, LOG_WARNING);
            return 0;

        } catch (Exception $e) {
            $this->error = $e->getMessage();
            $this->output = 'Fatal error: ' . $e->getMessage();
            dol_syslog("KSEF::cronCheckLatarniaStatus ERROR: " . $e->getMessage(), LOG_ERR);
            return -1;
        }
    }
}