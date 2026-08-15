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
 * \file    ksef/class/ksef_latarnia.class.php
 * \ingroup ksef
 * \brief   KSeF Latarnia (Lighthouse) API Client
 */

class KsefLatarnia
{
    private $db;
    public $error = '';
    public $errors = array();
    // Testing only
//    const API_URL = 'https://api-latarnia-test.ksef.mf.gov.pl';
    const API_URL = 'https://api-latarnia.ksef.mf.gov.pl';
    const API_TIMEOUT = 10;
    const CONNECT_TIMEOUT = 5;
    const STALE_THRESHOLD = 900; // 15min

    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * @brief Fetch current KSeF status from Latarnia /status
     * @return array|false Decoded JSON ['status' => string, 'messages' => array] or false on error
     * @called_by checkAndCache()
     */
    public function fetchStatus()
    {
        $this->error = '';

        $url = self::API_URL . '/status';
        dol_syslog("KsefLatarnia::fetchStatus Fetching from: " . $url, LOG_DEBUG);

        $data = $this->makeRequest($url);
        if ($data === false) {
            return false;
        }

        if (!isset($data['status'])) {
            $this->error = 'Latarnia API: Invalid response - missing status field';
            dol_syslog("KsefLatarnia::fetchStatus ERROR: " . $this->error, LOG_ERR);
            return false;
        }

        $validStatuses = array('AVAILABLE', 'MAINTENANCE', 'FAILURE', 'TOTAL_FAILURE');
        if (!in_array($data['status'], $validStatuses)) {
            $this->error = 'Latarnia API: Unknown status: ' . $data['status'];
            dol_syslog("KsefLatarnia::fetchStatus WARNING: " . $this->error, LOG_WARNING);
        }

        if (!isset($data['messages'])) {
            $data['messages'] = array();
        }

        dol_syslog("KsefLatarnia::fetchStatus Result: " . $data['status'] . " (" . count($data['messages']) . " messages)", LOG_INFO);
        return $data;
    }

    /**
     * @brief Fetch all current messages from Latarnia /messages
     * @return array|false Array of message objects or false on error
     * @called_by External callers
     */
    public function fetchMessages()
    {
        $this->error = '';

        $url = self::API_URL . '/messages';
        dol_syslog("KsefLatarnia::fetchMessages Fetching from: " . $url, LOG_DEBUG);

        $data = $this->makeRequest($url);
        if ($data === false) {
            return false;
        }

        if (!is_array($data)) {
            $this->error = 'Latarnia API: Invalid response - expected array';
            dol_syslog("KsefLatarnia::fetchMessages ERROR: " . $this->error, LOG_ERR);
            return false;
        }

        dol_syslog("KsefLatarnia::fetchMessages Result: " . count($data) . " messages", LOG_INFO);
        return $data;
    }

    /**
     * @brief Fetch status and cache the result
     * @return array|false The status data or false on error
     * @called_by cronCheckLatarniaStatus(), refreshIfStale(), ksefindex.php, admin/setup.php
     */
    public function checkAndCache()
    {
        global $conf;

        require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';

        $data = $this->fetchStatus();

        if ($data === false) {
            dolibarr_set_const($this->db, 'KSEF_LATARNIA_ERROR', $this->error, 'chaine', 0, '', $conf->entity);
            dolibarr_set_const($this->db, 'KSEF_LATARNIA_STATUS', json_encode(array(
                'status' => 'UNREACHABLE',
                'messages' => array(),
                'timestamp' => dol_now(),
                'error' => $this->error,
            )), 'chaine', 0, '', $conf->entity);
            return false;
        }

        // Merge new messages with previously cached ones ... just in case
        $cached = self::getCachedStatus();
        $data['messages'] = self::mergeMessages($cached['messages'], $data['messages']);
        $data['timestamp'] = dol_now();

        dolibarr_set_const($this->db, 'KSEF_LATARNIA_STATUS', json_encode($data), 'chaine', 0, '', $conf->entity);
        dolibarr_set_const($this->db, 'KSEF_LATARNIA_ERROR', '', 'chaine', 0, '', $conf->entity);

        return $data;
    }

    /**
     * @brief Refresh cached status if it is older than STALE_THRESHOLD
     * @return array Cached or freshly fetched status data
     * @called_by KsefService::submitInvoice(), KsefService::initIncomingFetch()
     */
    public function refreshIfStale()
    {
        $cached = self::getCachedStatus();

        if ($cached['timestamp'] > 0 && (dol_now() - $cached['timestamp']) < self::STALE_THRESHOLD) {
            return $cached;
        }

        dol_syslog("KsefLatarnia::refreshIfStale Cache stale or empty, refreshing", LOG_DEBUG);
        $fresh = $this->checkAndCache();

        if ($fresh !== false) {
            return $fresh;
        }

        if ($cached['timestamp'] > 0) {
            dol_syslog("KsefLatarnia::refreshIfStale Fetch failed, returning stale cache", LOG_WARNING);
            return $cached;
        }

        return self::getCachedStatus();
    }

    /**
     * @brief Get cached status
     * @return array ['status' => string, 'messages' => array, 'timestamp' => int, 'error' => string|null]
     * @called_by ksefindex.php, admin/setup.php, actions_ksef.class.php, refreshIfStale()
     */
    public static function getCachedStatus()
    {
        $raw = getDolGlobalString('KSEF_LATARNIA_STATUS', '');
        if (empty($raw)) {
            return array(
                'status' => 'UNKNOWN',
                'messages' => array(),
                'timestamp' => 0,
                'error' => null,
            );
        }

        $data = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            return array(
                'status' => 'UNKNOWN',
                'messages' => array(),
                'timestamp' => 0,
                'error' => 'Failed to decode cached status',
            );
        }

        if (!isset($data['status'])) {
            $data['status'] = 'UNKNOWN';
        }
        if (!isset($data['messages'])) {
            $data['messages'] = array();
        }
        if (!isset($data['timestamp'])) {
            $data['timestamp'] = 0;
        }

        return $data;
    }

    /**
     * @brief Merge new messages with previously cached messages, keyed by message id
     * @param array $cached  Previously cached messages
     * @param array $fresh   Newly fetched messages from API
     * @return array Merged messages
     * @called_by checkAndCache()
     */
    private static function mergeMessages($cached, $fresh)
    {
        $byId = array();

        if (!empty($cached) && is_array($cached)) {
            foreach ($cached as $msg) {
                if (!empty($msg['id'])) {
                    $byId[$msg['id']] = $msg;
                }
            }
        }

        if (!empty($fresh) && is_array($fresh)) {
            foreach ($fresh as $msg) {
                if (!empty($msg['id'])) {
                    $byId[$msg['id']] = $msg;
                }
            }
        }

        $merged = array_values($byId);
        usort($merged, function ($a, $b) {
            $pa = !empty($a['published']) ? strtotime($a['published']) : 0;
            $pb = !empty($b['published']) ? strtotime($b['published']) : 0;
            return $pb - $pa;
        });

        return $merged;
    }

    /**
     * @brief Make a GET request to the Latarnia API
     * @param string $url Full URL to request
     * @return array|false Decoded JSON or false on error
     * @called_by fetchStatus(), fetchMessages()
     */
    private function makeRequest($url)
    {
        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::API_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_HTTPHEADER => array(
                'Accept: application/json',
                'User-Agent: Dolibarr-KSeF-Module/1.0'
            ),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => true,
        ));

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            $this->error = 'Latarnia API connection error: ' . $curlError;
            dol_syslog("KsefLatarnia::makeRequest CURL ERROR: " . $this->error, LOG_ERR);
            return false;
        }

        if ($httpCode != 200) {
            $this->error = 'Latarnia API error: HTTP ' . $httpCode;
            dol_syslog("KsefLatarnia::makeRequest HTTP ERROR: " . $this->error, LOG_ERR);
            return false;
        }

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->error = 'Latarnia API error: Invalid JSON response';
            dol_syslog("KsefLatarnia::makeRequest JSON ERROR: " . $this->error, LOG_ERR);
            return false;
        }

        return $data;
    }
}
