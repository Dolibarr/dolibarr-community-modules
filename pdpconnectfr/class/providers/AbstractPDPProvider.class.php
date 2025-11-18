<?php
/* Copyright (C) 2025       Laurent Destailleur         <eldy@users.sourceforge.net>
 * Copyright (C) 2025       Mohamed DAOUD               <mdaoud@dolicloud.com>
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
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */



/**
 * \file    pdpconnectfr/class/providers/AbstractPDPProvider.class.php
 * \ingroup pdpconnectfr
 * \brief   Base class for all PDP provider integrations.
 */

dol_include_once('/pdpconnectfr/class/protocols/ProtocolManager.class.php');


abstract class AbstractPDPProvider
{
    /** @var DoliDB Database handler */
    public $db;

    /** @var array Error messages */
    public $errors = [];

    /** @var array Provider configuration parameters */
    protected $config = [];

    /** @var array OAuth token information */
    protected $tokenData = [];

    /** @var AbstractProtocol Exchange protocol */
    public $exchangeProtocol;

    /** @var string Provider name */
    public $providerName;

    /**
     * Constructor
     *
     * @param DoliDB $db Database handler
     */
    public function __construct($db)
    {
    	$this->db = $db;
        $this->config = [];
        $this->tokenData = [];
        $this->providerName = null;
    }

    /**
     * Validate configuration parameters before API calls.
     *
     * @return bool True if configuration is valid.
     */
    abstract public function validateConfiguration();

    /**
     * Get access token for the provider.
     *
     * @return string|null
     */
    abstract public function getAccessToken();

    /**
     * Perform a health check call for the provider endpoint.
     *
     * @return array Contains 'status' (bool) and 'message' (string)
     */
    abstract public function checkHealth();

    /**
     * Get the base API URL for Esalink PDP
     *
     * @return string
     */
    public function getApiUrl()
    {
        $prod = getDolGlobalString('PDPCONNECTFR_LIVE', '');
		$url = $this->config['test_api_url'];
		if ($prod != '') {
			$url = $this->config['prod_api_url'];
		}
		return $url;
    }


    /**
     * Generate a UUID used to correlate logs between Dolibarr and PDP.
     *
     * This function creates a random UUID.
     * It can be used as a Request-Id header to trace requests
     * and unify logs across distributed systems (Dolibarr and PDP).
     *
     * @return string A random UUID v4 string, e.g. "550e8400-e29b-41d4-a716-446655440000"
     */
    public function generateUuidV4(): string
    {
        // Generate 16 random bytes (128 bits)
        $data = random_bytes(16);

        // Set version to 0100 (UUID v4)
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        // Set variant to 10xxxxxx (RFC 4122)
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        // Convert to standard UUID format
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * Get the base API URL for Esalink PDP
     *
     * @return array
     */
    public function getConf() {
        return $this->config;
    }

    /** @var array OAuth token information */
    public function getTokenData() {
        return $this->tokenData;
    }

    /**
     * Send a sample electronic invoice for testing purposes.
     *
     * This function generates a sample invoice and sends it to PDP
     *
     * @return array|string True if the invoice was successfully sent, false otherwise.
     */
    abstract public function sendSampleInvoice();


    /**
	 * Call the provider API.
	 *
	 * @param string 						$resource 	Resource relative URL ('Flows', 'healthcheck' or others)
     * @param string                        $method     HTTP method ('GET', 'POST', etc.)
	 * @param array<string, mixed>|false 	$options 	Options for the request
	 * @return array{status_code:int,response:null|string|array<string,mixed>}
	 */
    abstract public function callApi($resource, $method, $options = false);

    /**
     * Synchronize flows with PDP since the last synchronization date.
     *
     * @return bool|array{res:int, messages:array<string>} True on success, false on failure along with messages.
     */
    abstract public function syncFlows();

    /**
     * Store a flow data.
     *
     * @param  string $flowId       FlowId
     * @return bool                 True on success, false on failure
     */
    abstract public function syncFlow($flowId);

    /**
     * Insert or update OAuth token for the given PDP.
     *
     * @param  string      $accessToken    Access token string
     * @param  string|null $refreshToken   refresh token string
     * @param  int|null    $expiresIn      token validity in seconds
     * @return bool                        True if success, false otherwise
     */
    public function saveOAuthTokenDB($accessToken, $refreshToken = null, $expiresIn = null)
    {
        global $conf, $db;

        $now = dol_now();

        // Calculate expiration timestamp if provided
        $expire_at = $expiresIn !== null ? $now + (int) $expiresIn : null;

        // Build service name depending on environment
        $serviceName = $this->config['dol_prefix'] . '_' . ($this->config['live'] ? 'PROD' : 'TEST');

        // Check if a token already exists for this service
        $sql_check = "SELECT rowid FROM ".MAIN_DB_PREFIX."oauth_token
                    WHERE service = '".$db->escape($serviceName)."'
                    AND entity = ".((int) $conf->entity);

        $resql = $db->query($sql_check);
        if (!$resql) {
            $this->errors[] = __METHOD__." SQL error (check): ".$db->lasterror();
            return false;
        }

        if ($db->num_rows($resql) > 0) {
            // --- Update existing token ---
            $sql  = "UPDATE ".MAIN_DB_PREFIX."oauth_token SET ";
            $sql .= "tokenstring = '".$db->escape($accessToken)."'";
            if ($refreshToken !== null) {
                $sql .= ", tokenstring_refresh = '".$db->escape($refreshToken)."'";
            }
            if ($expire_at !== null) {
                $sql .= ", expire_at = '".$db->idate($expire_at)."'";
            }
            $sql .= " WHERE service = '".$db->escape($serviceName)."'";
            $sql .= " AND entity = ".((int) $conf->entity);
        } else {
            // --- Insert new token ---
            $sql  = "INSERT INTO ".MAIN_DB_PREFIX."oauth_token (service, tokenstring";
            $sql .= $refreshToken !== null ? ", tokenstring_refresh" : "";
            $sql .= ", datec";
            $sql .= $expire_at !== null ? ", expire_at" : "";
            $sql .= ", entity) VALUES (";
            $sql .= "'".$db->escape($serviceName)."', ";
            $sql .= "'".$db->escape($accessToken)."'";
            $sql .= $refreshToken !== null ? ", '".$db->escape($refreshToken)."'" : "";
            $sql .= ", '".$db->idate($now)."'";
            $sql .= $expire_at !== null ? ", '".$db->idate($expire_at)."'" : "";
            $sql .= ", ".(int) $conf->entity.")";
        }

        // Execute SQL
        $res = $db->query($sql);
        if (!$res) {
            $this->errors[] = __METHOD__." SQL error (insert/update): ".$db->lasterror();
            return false;
        }

        // Update config array
        $this->tokenData['token'] = $accessToken;
        $this->tokenData['token_expires_at'] = $expire_at;
        $this->tokenData['refresh_token'] = $refreshToken;

        return true;
    }


    /**
     * Retrieve OAuth token for the given PDP service.
     *
     * @return array|false   Array with keys 'access_token', 'refresh_token', 'expire_at', or false if not found
     */
    public function fetchOAuthTokenDB()
    {
        global $conf, $db;

        // Build service name depending on environment
        $serviceName = $this->config['dol_prefix'] . '_' . ($this->config['live'] ? 'PROD' : 'TEST');

        // Prepare SQL
        $sql = "SELECT tokenstring, tokenstring_refresh, expire_at
                FROM ".MAIN_DB_PREFIX."oauth_token
                WHERE service = '".$db->escape($serviceName)."'
                AND entity = ".((int) $conf->entity)." LIMIT 1";

        $resql = $db->query($sql);
        if (!$resql) {
            $this->errors[] = __METHOD__." SQL error: ".$db->lasterror();
            return false;
        }

        if ($db->num_rows($resql) === 0) {
            return false; // No token found
        }

        $obj = $db->fetch_object($resql);

        return [
            'token'  => $obj->tokenstring,
            'refresh_token' => $obj->tokenstring_refresh,
            'token_expires_at'     => $obj->expire_at
        ];
    }

    /**
    * Get the last synchronization date with the PDP provider.
    *
    * Retrieves the timestamp of the most recent successful flow synchronization
    * for this provider. If no sync has occurred yet, returns Unix epoch (1970-01-01).
     *
     * @return int Timestamp of the last synchronization date
     */
    public function getLastSyncDate() {
        global $conf, $db;

        $LastSyncDate = null;

        // Get last sync date
        $LastSyncDateSql = "SELECT MAX(t.date_creation) as last_sync_date
            FROM ".MAIN_DB_PREFIX."pdpconnectfr_call as t
            WHERE t.provider = '".$this->db->escape($this->providerName)."' 
            AND t.call_type = 'sync_flow' 
            AND T.status = 'SUCCESS'";
            if ($conf->entity && $conf->entity > 1) {
                $LastSyncDateSql .= " AND t.entity = ".((int) $conf->entity);
            }
            $LastSyncDateSql .= ";";
        $resql = $db->query($LastSyncDateSql);

        if ($resql) {
            $obj = $db->fetch_object($resql);
            $LastSyncDate = $obj->last_sync_date  ? strtotime($obj->last_sync_date) : null;
        } else {
            dol_syslog(__METHOD__ . " SQL warning: Failed to get last sync date: we try to sync all flows from today", LOG_WARNING);
        }

        if ($LastSyncDate === null) {
            // If no last sync date, set to epoch start
            $LastSyncDate = strtotime('1970-01-01 00:00:00');
        }
        return $LastSyncDate;
    }
}