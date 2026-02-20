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

require_once __DIR__ . '/../protocols/ProtocolManager.class.php';


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

    public static $PDPCONNECTFR_LAST_IMPORT_KEY;

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
	 * @param string 						$resource 	    Resource relative URL ('Flows', 'healthcheck' or others)
     * @param string                        $method         HTTP method ('GET', 'POST', etc.)
	 * @param array<string, mixed>|false 	$options 	    Options for the request
     * @param array<string, string>         $extraHeaders   Optional additional headers
     * @param string|null                   $callType       Functional type of the API call for logging purposes (e.g., 'sync_flows', 'send_invoice')
     *
	 * @return array{status_code:int,response:null|string|array<string,mixed>,call_id:null|string}
	 */
    abstract public function callApi($resource, $method, $options = false, $extraHeaders = [], $callType = '');

    /**
     * Synchronize flows with EsaLink.
     * @param   int   $syncFromDate     Timestamp from which to start synchronization. If 0, begins from epoch (1970-01-01).
     * @param   int   $limit            Maximum number of flows to synchronize. 0 means no limit.
     *
     * @return 	bool|array{res:int, messages:array<string>, details:array<string>, actions:array<string>} 	True on success, false on failure along with messages, details for debugging, and suggested optional actions.
     */
    abstract public function syncFlows($syncFromDate = 0, $limit = 0);

    /**
     * sync flow data.
     *
     * @param string $flowId        FlowId
     * @param string|null $call_id  Call ID for logging purposes
     *
     * @return array{res:int, message:string, action:string|null} Returns array with 'res' (1 on success, 0 if exists or already processed, -1 on failure) with a 'message' and an optional 'action'.
     */
    abstract public function syncFlow($flowId, $call_id = null);

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

        // For backward compatibility with Dolibarr versions < 23.0.0
        if (version_compare(DOL_VERSION, '23.0.0', '<')) {

            dolibarr_set_const($db, $serviceName.'_TOKEN', $accessToken, 'chaine', 0, '', $conf->entity);

            if ($refreshToken !== null) {
                dolibarr_set_const($db, $serviceName.'_REFRESH', $refreshToken, 'chaine', 0, '', $conf->entity);
            }

            if ($expire_at !== null) {
                dolibarr_set_const($db, $serviceName.'_EXPIRE', $expire_at, 'chaine', 0, '', $conf->entity);
            }

        } else {

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

        // For backward compatibility with Dolibarr versions < 23.0.0
        if (version_compare(DOL_VERSION, '23.0.0', '<')) {

            $token = $conf->global->{$serviceName.'_TOKEN'} ?? '';
            $refresh = $conf->global->{$serviceName.'_REFRESH'} ?? '';
            $expire = $conf->global->{$serviceName.'_EXPIRE'} ?? '';

            if (empty($token)) {
                return false;
            }

            return [
                'token' => $token,
                'refresh_token' => $refresh,
                'token_expires_at' => $expire
            ];
        }

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
     * Retrieves the timestamp of the most recent successful flow synchronization
     * for this provider. If no sync has occurred yet, returns 0.
     * Optionally applies a margin in hours to the returned timestamp.
     *
     * @param 	int 		$marginHours 	Optional time margin in hours to go back from the current date of the last synchronization
     * @return 	int			 				Timestamp of the last synchronization date
     */
    public function getLastSyncDate($marginHours = 0) {
        global $db;

        $LastSyncDate = null;

        // Retrieve the last synchronization timestamp from the database
        // Note: The PDP API does not support per-document synchronization yet.
        // We perform a global sync for all flows and track the last modification
        // timestamp (tms) from the pdpconnectfr_document table to determine
        // which flows need to be synchronized since the last successful sync.
        //
        // Future enhancement: Individual document sync may be possible when
        // the PDP provider API supports it.

        $LastSyncDateSql = "SELECT MAX(t.updatedat) as last_sync_date
        FROM ".MAIN_DB_PREFIX."pdpconnectfr_document  as t
        WHERE t.provider = '".$db->escape($this->providerName)."'";

        $resql = $db->query($LastSyncDateSql);

        if ($resql) {
            $obj = $db->fetch_object($resql);
            $LastSyncDate = $obj->last_sync_date  ? strtotime($obj->last_sync_date) : null;
        } else {
            dol_syslog(__METHOD__ . " SQL warning: Failed to get last sync date: we try to sync all flows from today", LOG_WARNING);
        }

        if ($LastSyncDate === null) {
            $LastSyncDate = 0;
        }

        // Apply margin in hours
        if ($marginHours !== 0) {
            $LastSyncDate -= ($marginHours * 3600);
        }

        return $LastSyncDate;
    }

    /**
     * Add an event/action record to track changes or activities related to an object
     *
     * @param   string      $eventType The type of event
     * @param   string      $eventMesg The message/label describing the event
     * @param   object      $objet The object (Invoice / Supplier invoice) that the event is associated with.
     *
     * @return  int         Id of created event, < 0 if KO
     */
    public function addEvent($eventType, $eventLabel, $eventMesg, $objet)
    {
        global $db, $user;
        require_once DOL_DOCUMENT_ROOT.'/comm/action/class/actioncomm.class.php';

        $actioncomm = new ActionComm($db);

        $actioncomm->type_code = 'AC_OTH_AUTO';
        $actioncomm->code = 'AC_PDPCONNECTFR_'.$eventType;

        $actioncomm->socid = $objet->thirdparty->id;
        $actioncomm->label = $eventLabel;
        $actioncomm->note_private = $eventMesg;
        $actioncomm->fk_project = $objet->fk_project;
        $actioncomm->datep = dol_now();
        $actioncomm->datef = dol_now();
        $actioncomm->percentage = -1;
        $actioncomm->authorid = $user->id;
        $actioncomm->userownerid = $user->id;
        $actioncomm->elementid = $objet->id;
        $actioncomm->elementtype = $objet->element;

        $res = $actioncomm->create($user);

        if ($res < 0) {
            dol_syslog(__METHOD__ . " Error adding event: " . $actioncomm->error, LOG_ERR);
            return -1;
        }

        return $res;
    }

    /**
     * Send an electronic invoice.
     *
     * This function send an invoice to PDP
     *
     * $object Invoice object
     * @return string   flowId if the invoice was successfully sent, false otherwise.
     */
    abstract public function sendInvoice($object);

    /**
     * Send status message of an invoice to PDP/PA
     *
     * @param mixed $object Invoice object (CustomerInvoice or SupplierInvoice)
     * @param int $statusCode   Status code to send (see class constants for available codes)
     * @param string $reasonCode Reason code to send (optional)
     *
     * @return array{res:int, message:string}       Returns array with 'res' (1 on success, -1 on failure) with a 'message'.
     */
    abstract public function sendStatusMessage($object, $statusCode, $reasonCode = '');
}