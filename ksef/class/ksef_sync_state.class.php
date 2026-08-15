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
 * \file    ksef/class/ksef_sync_state.class.php
 * \ingroup ksef
 * \brief   KSeF Sync State - tracks HWM, async fetch state, and sync history
 */

require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/security.lib.php';

class KsefSyncState
{
    private $db;
    private $entity;
    private $sync_type;
    public $hwm_date;
    public $last_sync;
    public $last_sync_new;
    public $last_sync_existing;
    public $last_sync_total;
    public $last_sync_errors;
    public $rate_limit_until;
    public $fetch_reference;
    public $fetch_status;
    public $fetch_started;
    public $fetch_key;
    public $fetch_iv;
    public $fetch_error;
    public $process_file;
    public $process_total;
    public $process_offset;
    public $process_new;
    public $process_existing;
    public $fetch_parts;
    public $fetch_hwm_data;

    const FETCH_STATUS_PROCESSING = 'PROCESSING';
    const FETCH_STATUS_READY_TO_PROCESS = 'READY_TO_PROCESS';
    const FETCH_STATUS_DOWNLOADING = 'DOWNLOADING';
    const FETCH_STATUS_PROCESSING_BATCHES = 'PROCESSING_BATCHES';
    const FETCH_STATUS_FAILED = 'FAILED';
    const FETCH_STATUS_TIMEOUT = 'TIMEOUT';

    const PROCESS_BATCH_SIZE = 1000;

    const CONST_PREFIX = 'KSEF_SYNC_';
    const FETCH_TIMEOUT_SECONDS = 1200;
    const PROCESS_TIMEOUT_SECONDS = 900;


    public function __construct($db)
    {
        global $conf;
        $this->db = $db;
        $this->entity = $conf->entity;
    }


    private function getConstName($field)
    {
        return self::CONST_PREFIX . strtoupper($field) . '_' . strtoupper($this->sync_type);
    }


    /**
     * @brief Load sync state from Dolibarr constants
     * @param string $sync_type Type of sync
     * @return int 1 if OK
     */
    public function load($sync_type)
    {
        $this->sync_type = $sync_type;

        $this->hwm_date = getDolGlobalString($this->getConstName('HWM_DATE'), '');
        $this->last_sync = getDolGlobalInt($this->getConstName('LAST_SYNC'), 0);
        $this->last_sync_new = getDolGlobalInt($this->getConstName('LAST_NEW'), 0);
        $this->last_sync_existing = getDolGlobalInt($this->getConstName('LAST_EXISTING'), 0);
        $this->last_sync_total = getDolGlobalInt($this->getConstName('LAST_TOTAL'), 0);
        $this->last_sync_errors = getDolGlobalString($this->getConstName('LAST_ERRORS'), '');
        $this->rate_limit_until = getDolGlobalInt($this->getConstName('RATE_LIMIT'), 0);

        $this->fetch_reference = getDolGlobalString($this->getConstName('FETCH_REF'), '');
        $this->fetch_status = getDolGlobalString($this->getConstName('FETCH_STATUS'), '');
        $this->fetch_started = getDolGlobalInt($this->getConstName('FETCH_STARTED'), 0);
        $this->fetch_key = dol_decode(getDolGlobalString($this->getConstName('FETCH_KEY'), ''));
        $this->fetch_iv = dol_decode(getDolGlobalString($this->getConstName('FETCH_IV'), ''));
        $this->fetch_error = getDolGlobalString($this->getConstName('FETCH_ERROR'), '');
        $this->fetch_parts = getDolGlobalString($this->getConstName('FETCH_PARTS'), '');
        $this->fetch_hwm_data = getDolGlobalString($this->getConstName('FETCH_HWM'), '');

        $this->process_file = getDolGlobalString($this->getConstName('PROC_FILE'), '');
        $this->process_total = getDolGlobalInt($this->getConstName('PROC_TOTAL'), 0);
        $this->process_offset = getDolGlobalInt($this->getConstName('PROC_OFFSET'), 0);
        $this->process_new = getDolGlobalInt($this->getConstName('PROC_NEW'), 0);
        $this->process_existing = getDolGlobalInt($this->getConstName('PROC_EXISTING'), 0);

        return 1;
    }


    /**
     * @brief Save sync state to Dolibarr constants
     * @return int 1 if OK, <0 if KO
     */
    public function save()
    {
        $error = 0;

        if (dolibarr_set_const($this->db, $this->getConstName('HWM_DATE'), $this->hwm_date ?: '', 'chaine', 0, '', $this->entity) < 0) $error++;
        if (dolibarr_set_const($this->db, $this->getConstName('LAST_SYNC'), $this->last_sync ?: 0, 'int', 0, '', $this->entity) < 0) $error++;
        if (dolibarr_set_const($this->db, $this->getConstName('LAST_NEW'), $this->last_sync_new ?: 0, 'int', 0, '', $this->entity) < 0) $error++;
        if (dolibarr_set_const($this->db, $this->getConstName('LAST_EXISTING'), $this->last_sync_existing ?: 0, 'int', 0, '', $this->entity) < 0) $error++;
        if (dolibarr_set_const($this->db, $this->getConstName('LAST_TOTAL'), $this->last_sync_total ?: 0, 'int', 0, '', $this->entity) < 0) $error++;
        if (dolibarr_set_const($this->db, $this->getConstName('LAST_ERRORS'), $this->last_sync_errors ?: '', 'chaine', 0, '', $this->entity) < 0) $error++;
        if (dolibarr_set_const($this->db, $this->getConstName('RATE_LIMIT'), $this->rate_limit_until ?: 0, 'int', 0, '', $this->entity) < 0) $error++;

        if (dolibarr_set_const($this->db, $this->getConstName('FETCH_REF'), $this->fetch_reference ?: '', 'chaine', 0, '', $this->entity) < 0) $error++;
        if (dolibarr_set_const($this->db, $this->getConstName('FETCH_STATUS'), $this->fetch_status ?: '', 'chaine', 0, '', $this->entity) < 0) $error++;
        if (dolibarr_set_const($this->db, $this->getConstName('FETCH_STARTED'), $this->fetch_started ?: 0, 'int', 0, '', $this->entity) < 0) $error++;
        if (dolibarr_set_const($this->db, $this->getConstName('FETCH_KEY'), dol_encode($this->fetch_key ?: ''), 'chaine', 0, '', $this->entity) < 0) $error++;
        if (dolibarr_set_const($this->db, $this->getConstName('FETCH_IV'), dol_encode($this->fetch_iv ?: ''), 'chaine', 0, '', $this->entity) < 0) $error++;
        if (dolibarr_set_const($this->db, $this->getConstName('FETCH_ERROR'), $this->fetch_error ?: '', 'chaine', 0, '', $this->entity) < 0) $error++;
        if (dolibarr_set_const($this->db, $this->getConstName('FETCH_PARTS'), $this->fetch_parts ?: '', 'chaine', 0, '', $this->entity) < 0) $error++;
        if (dolibarr_set_const($this->db, $this->getConstName('FETCH_HWM'), $this->fetch_hwm_data ?: '', 'chaine', 0, '', $this->entity) < 0) $error++;

        if (dolibarr_set_const($this->db, $this->getConstName('PROC_FILE'), $this->process_file ?: '', 'chaine', 0, '', $this->entity) < 0) $error++;
        if (dolibarr_set_const($this->db, $this->getConstName('PROC_TOTAL'), $this->process_total ?: 0, 'int', 0, '', $this->entity) < 0) $error++;
        if (dolibarr_set_const($this->db, $this->getConstName('PROC_OFFSET'), $this->process_offset ?: 0, 'int', 0, '', $this->entity) < 0) $error++;
        if (dolibarr_set_const($this->db, $this->getConstName('PROC_NEW'), $this->process_new ?: 0, 'int', 0, '', $this->entity) < 0) $error++;
        if (dolibarr_set_const($this->db, $this->getConstName('PROC_EXISTING'), $this->process_existing ?: 0, 'int', 0, '', $this->entity) < 0) $error++;

        return $error ? -1 : 1;
    }

    public function isFetchInProgress()
    {
        if (empty($this->fetch_reference)) {
            return false;
        }
        return in_array($this->fetch_status, array(
            self::FETCH_STATUS_PROCESSING,
            self::FETCH_STATUS_READY_TO_PROCESS,
            self::FETCH_STATUS_DOWNLOADING,
            self::FETCH_STATUS_PROCESSING_BATCHES,
        ));
    }

    public function isFetchTimedOut()
    {
        if (!$this->isFetchInProgress() || empty($this->fetch_started)) {
            return false;
        }
        return (dol_now() - $this->fetch_started) > self::FETCH_TIMEOUT_SECONDS;
    }

    public function clearFetchState()
    {
        $this->fetch_reference = '';
        $this->fetch_status = '';
        $this->fetch_started = 0;
        $this->fetch_key = '';
        $this->fetch_iv = '';
        $this->fetch_error = '';
        $this->fetch_parts = '';
        $this->fetch_hwm_data = '';
        return $this->save();
    }

    public function isProcessingInProgress()
    {
        return !empty($this->process_file) && file_exists($this->process_file);
    }

    public function isProcessingTimedOut()
    {
        if (!$this->isProcessingInProgress()) {
            return false;
        }
        $mtime = @filemtime($this->process_file);
        if ($mtime === false) {
            return true;
        }
        return (dol_now() - $mtime) > self::PROCESS_TIMEOUT_SECONDS;
    }

    public function clearProcessingState()
    {
        // Delete temp file
        if (!empty($this->process_file) && file_exists($this->process_file)) {
            @unlink($this->process_file);
        }
        $this->process_file = '';
        $this->process_total = 0;
        $this->process_offset = 0;
        $this->process_new = 0;
        $this->process_existing = 0;
        return $this->save();
    }

    public function getProcessingProgress()
    {
        if (!$this->isProcessingInProgress()) {
            return null;
        }
        return array(
            'total' => $this->process_total,
            'processed' => $this->process_offset,
            'new' => $this->process_new,
            'existing' => $this->process_existing,
            'percent' => $this->process_total > 0 ? round(($this->process_offset / $this->process_total) * 100) : 0
        );
    }

    /**
     * @brief Get continuation date for display
     * @return int|null Unix timestamp or null
     */
    public function getContinuationDate()
    {
        if (empty($this->hwm_date)) return null;
        return strtotime($this->hwm_date);
    }

    public $continuation_date;
    public $last_sync_date;

    public function getDisplayValues()
    {
        $this->continuation_date = $this->getContinuationDate();
        $this->last_sync_date = $this->last_sync ?: null;
    }

    public function isRateLimited()
    {
        return $this->rate_limit_until > dol_now();
    }

    public function secondsUntilNextSync()
    {
        if (!$this->isRateLimited()) return 0;
        return $this->rate_limit_until - dol_now();
    }

    public function getRateLimitExpiryFormatted()
    {
        if (!$this->isRateLimited()) return '';
        return dol_print_date($this->rate_limit_until, 'dayhour');
    }

    public function canSyncNow()
    {
        if ($this->isFetchInProgress()) {
            if ($this->isFetchTimedOut()) {
                $this->fetch_status = self::FETCH_STATUS_TIMEOUT;
                $this->clearFetchState();
            } else {
                return false;
            }
        }
        if ($this->isProcessingInProgress()) {
            if ($this->isProcessingTimedOut()) {
                $this->clearProcessingState();
            } else {
                return false;
            }
        }
        if ($this->isRateLimited()) return false;
        return true;
    }

    public function setRateLimit($seconds)
    {
        $this->rate_limit_until = dol_now() + $seconds;
        return $this->save();
    }
}