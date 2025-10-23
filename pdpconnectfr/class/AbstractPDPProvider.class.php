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
 * \file    pdpconnectfr/class/AbstractPDPProvider.class.php
 * \ingroup pdpconnectfr
 * \brief   Base class for all PDP provider integrations.
 */

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

    /**
     * Constructor
     *
     * @param DoliDB $db Database handler
     */
    public function __construct()
    {
        $this->config = [];
        $this->tokenData = [];
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
	 * Call the provider API.
	 *
	 * @param string 						$resource 	Resource relative URL ('Flows', 'healthcheck' or others)
     * @param string                        $method     HTTP method ('GET', 'POST', etc.)
	 * @param array<string, mixed>|false 	$options 	Options for the request
	 * @return array{status_code:int,response:null|string|array<string,mixed>}
	 */
    abstract public function callApi($resource, $method, $options = false);

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
}