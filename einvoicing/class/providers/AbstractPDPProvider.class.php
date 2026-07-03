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
 * \file    einvoicing/class/providers/AbstractPDPProvider.class.php
 * \ingroup einvoicing
 * \brief   Base class for all PDP provider integrations.
 */

require_once __DIR__ . '/../protocols/ProtocolManager.class.php';


/**
 * AbstractPDPProvider
 */
abstract class AbstractPDPProvider
{
	/** @var DoliDB Database handler */
	public $db;

	/** @var array Error messages */
	public $errors = [];

	/** @var array Provider configuration parameters */
	protected $config = [];

	/** @var array<string,null|int|string> OAuth token information */
	protected $tokenData = [];

	/** @var AbstractProtocol Exchange protocol */
	public $exchangeProtocol;

	/** @var string Provider name */
	public $providerName;

	/** @var string Short provider code, defined by each concrete provider (e.g. 'SuperPDP', 'Esalink') */
	public $name;

	/** @var string Help message to guide users in obtaining credentials for this provider */
	public $helpToGetCredentials;

	public static $EINVOICING_LAST_IMPORT_KEY;


	/**
	 * Constructor
	 *
	 * Load setup properties and last token.
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
	 * @param 	int		$mode 	0 check that user/pass is set, 1 check that token is set
	 * @return 	bool 			True if configuration is valid.
	 */
	abstract public function validateConfiguration($mode = 1);


	/**
	 * Get access token from OAUth server and save it into database.
	 * This erase old token.
	 *
	 * @return string|null 		Access token or null on failure.
	 * @see getTokenData() to get current token in memory (loaded by fetchOAuthTokenDB in constructor)
	 */
	abstract public function getAccessToken();

	/**
	 * Get current token in memory (loaded by fetchOAuthTokenDB in constructor)
	 *
	 * @return array<string,null|int|string>	Token
	 */
	public function getTokenData()
	{
		return $this->tokenData;
	}

	/**
	 * Return of a token is expired
	 *
	 * @return boolean	yes or no
	 */
	public function isTokenExpired()
	{
		if (!empty($this->tokenData['token_expires_at'])) {
			try {
				// Check date
				$expiryDate = $this->tokenData['token_expires_at'];
				$now = dol_now();
				$expired = ($now >= ($expiryDate - 60));
				//var_dump($this->tokenData, dol_print_date($expiryDate, 'standard', 'gmt').' UTC', $expiryDate, $now, $expired);exit;

				return $expired;	// We report token as expired 60 seconds before real end.
			} catch (Exception $e) {
				return true;
			}
		}

		return true;
	}

	/**
	 * Refresh access token.
	 *
	 * @return string|null 		New access token or null on failure.
	 */
	abstract public function refreshAccessToken();


	/**
	 * Perform a health check call for the provider endpoint.
	 *
	 * @return array Contains 'status' (bool) and 'message' (string)
	 */
	abstract public function checkHealth();

	/**
	 * Get the base API URL for provider depending on the mode (authentication or regular API calls).
	 *
	 * @param string 	$mode 		'auth', 'api' or 'ap_api'
	 * @return string				URL of the endpoint to call depending on the mode (authentication or regular API calls)
	 */
	public function getApiUrl($mode = 'api')
	{
		// Use getDolGlobalInt so that EINVOICING_LIVE = "0" is correctly treated as test mode.
		// A previous "$prod != ''" comparison was incorrect because '0' != '' is true in PHP,
		// which made the module hit the production endpoint as soon as the user had ever toggled
		// the "real mode" switch (the const gets stored as "0" instead of being deleted).
		$prod = getDolGlobalInt('EINVOICING_LIVE');

		$url = '';
		if ($mode === 'auth') {
			$url = $this->config['test_auth_url'];
			if (!empty($prod)) {
				$url = $this->config['prod_auth_url'];
			}
			return $url;
		} elseif ($mode === 'api') {
			$url = $this->config['test_api_url'];
			if (!empty($prod)) {
				$url = $this->config['prod_api_url'];
			}
		} elseif ($mode === 'ap_api') {
			$url = $this->config['ap_api_url'];
			if (!empty($prod)) {
				$url = $this->config['ap_api_url'];
			}
		}

		return $url;
	}

	/**
	 * Check if the provider has a validator endpoint.
	 *
	 * @return bool True if the provider has a validator endpoint, false otherwise.
	 */
	public function hasValidator(): bool
	{
		return !empty($this->config['has_validator']);
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
	public function getConf()
	{
		return $this->config;
	}


	/**
	 * Try to get a flow data from its id and doc type, using API
	 * @param $flowId 		The id of the flow
	 * @param $docType 		The type of document we want to return
	 * @param $callType		The type of call to use when calling API
	 *
	 * @return array{status_code:int,response:null|string|array<string,mixed>,errorCode?:string,errorMessage?:string,id?:int,call_id?:string}
	 */
	public function fetchFlowData($flowId, $docType, $callType = '')
	{
		if (!in_array($docType, ['Metadata', 'Original', 'Converted', 'ReadableView'])) {
			$docType = 'Converted';
		}

		// Retrieve the PDF file converted by Access Point
		$flowResource = 'flows/' . $flowId;
		$flowUrlparams = array(
			'docType' => $docType,
		);
		$flowResource .= '?' . http_build_query($flowUrlparams);
		$flowResponse = $this->callApi(
			$flowResource,
			"GET",
			false,
			['Accept' => 'application/octet-stream'],
			$callType
		);

		return $flowResponse;
	}

	/**
	 * Send a sample electronic invoice for testing purposes.
	 * This function generates a sample invoice and sends it to PDP
	 *
	 * @param 	int 			$onlymake		1=to only make the sample
	 * @return 	array|string 					True if the invoice was successfully sent, false otherwise.
	 */
	abstract public function sendSampleInvoice($onlymake = 0);


	/**
	 * Validate an electronic invoice file using the provider's validation service.
	 *
	 * @param 	int 	$idinvoice 	ID of the invoice to check
	 * @param 	string 	$filePath 	Path to the invoice file to validate
	 * @return 	array|string 		Validation result or error message.
	 */
	abstract public function validateEInvoiceFile($idinvoice, $filePath);


	/**
	 * Call the provider API.
	 *
	 * @param string 						$resource 	    Resource relative URL ('Flows', 'healthcheck' or others)
	 * @param 'POST'|'GET'|'HEAD'|'PUT'|'PUTALREADYFORMATED'|'POSTALREADYFORMATED'|'DELETE' $method         HTTP method (dolibarr's types)
	 * @param string|false 	$options 	    Options for the request (JSON encoded)
	 * @param array<string, string>         $extraHeaders   Optional additional headers
	 * @param string|null                   $callType       Functional type of the API call for logging purposes (e.g., 'sync_flows', 'send_invoice')
	 *
	 * @return array{status_code:int,response:null|string|array<string,mixed|array<string,mixed>>,call_id:null|string}
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
		if (version_compare(DOL_VERSION, '23.0.0-alpha', '<')) {
			dolibarr_set_const($db, $serviceName.'_TOKEN', $accessToken, 'chaine', 0, '', $conf->entity);

			if ($refreshToken !== null) {
				dolibarr_set_const($db, $serviceName.'_REFRESH', $refreshToken, 'chaine', 0, '', $conf->entity);
			}

			if ($expire_at !== null) {
				dolibarr_set_const($db, $serviceName.'_EXPIRE', $expire_at, 'chaine', 0, '', $conf->entity);
			}
		} else {
			// Check if a token already exists for this service
			$sql_check = "SELECT rowid FROM ".MAIN_DB_PREFIX."oauth_token";
			$sql_check .= " WHERE service = '".$db->escape($serviceName)."'";
			$sql_check .= " AND entity = ".((int) $conf->entity);

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
					$sql .= ", expire_at = '".$db->idate($expire_at, 'gmt')."'";
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
				$sql .= $expire_at !== null ? ", '".$db->idate($expire_at, 'gmt')."'" : "";
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
	 * @return array{token:string,refresh_token:string,token_expires_at:string}|false   Array with keys 'access_token', 'refresh_token', 'expire_at', or false if not found
	 */
	public function fetchOAuthTokenDB()
	{
		global $conf, $db;

		// Build service name depending on environment
		$serviceName = $this->config['dol_prefix'] . '_' . ($this->config['live'] ? 'PROD' : 'TEST');

		// For backward compatibility with Dolibarr versions < 23.0.0
		if (version_compare(DOL_VERSION, '23.0.0', '<')) {
			$token = getDolGlobalString($serviceName.'_TOKEN');
			$refresh = getDolGlobalString($serviceName.'_REFRESH');
			$expire = getDolGlobalString($serviceName.'_EXPIRE');

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
			'token' => (string) $obj->tokenstring,
			'refresh_token' => (string) $obj->tokenstring_refresh,
			'token_expires_at' => (string) $db->jdate($obj->expire_at, 'gmt')
		];
	}


	/**
	 * Insert or update OAuth token for the given PDP.
	 *
	 * @return bool                        True if success, false otherwise
	 */
	public function deleteOAuthTokenDB()
	{
		global $conf, $db;

		// Build service name depending on environment
		$serviceName = $this->config['dol_prefix'] . '_' . ($this->config['live'] ? 'PROD' : 'TEST');
		// For backward compatibility with Dolibarr versions < 23.0.0

		if (version_compare(DOL_VERSION, '23.0.0', '<')) {
			require_once DOL_DOCUMENT_ROOT."/core/lib/admin.lib.php";
			dolibarr_del_const($this->db, $serviceName.'_TOKEN', $conf->entity);
			dolibarr_del_const($this->db, $serviceName.'_REFRESH', $conf->entity);
			dolibarr_del_const($this->db, $serviceName.'_EXPIRE', $conf->entity);
			return true;
		}

		// Check if a token already exists for this service
		$sql_check = "DELETE FROM ".MAIN_DB_PREFIX."oauth_token
						WHERE service = '".$db->escape($serviceName)."'
						AND entity = ".((int) $conf->entity);

		$resql = $db->query($sql_check);
		if (!$resql) {
			$this->errors[] = __METHOD__." SQL error (check): ".$db->lasterror();
			return false;
		}

		return true;
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
	public function getLastSyncDate($marginHours = 0)
	{
		global $conf, $db;

		$LastSyncDate = null;

		// Retrieve the last synchronization timestamp from the database
		// Note: The PDP API does not support per-document synchronization yet.
		// We perform a global sync for all flows and track the last modification
		// timestamp (tms) from the einvoicing_document table to determine
		// which flows need to be synchronized since the last successful sync.
		//
		// Future enhancement: Individual document sync may be possible when
		// the PDP provider API supports it.

		$LastSyncDateSql = "SELECT MAX(t.updatedat) as last_sync_date";
		$LastSyncDateSql .= " FROM ".MAIN_DB_PREFIX."einvoicing_document as t";
		$LastSyncDateSql .= " WHERE t.provider = '".$db->escape($this->providerName)."'";
		$LastSyncDateSql .= " AND entity = ".((int) $conf->entity);		// Do not use getentity here, must always be on 1 entity.

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
	 * Log an API call into llx_einvoicing_call using a SEPARATE database connection.
	 *
	 * The call trace must survive even when the caller's main transaction is rolled
	 * back on error (see issue #291): a failed send_invoice rolls back the doActions()
	 * transaction, which would otherwise wipe the very log we need to diagnose it.
	 * Writing the log through an independent connection ($dbhistory) decouples it from
	 * the business transaction, so it is committed whether the action succeeds or fails,
	 * without ever forcing a commit on the rest. Same approach as the webhook logging.
	 *
	 * @param   string                      $callType   Functional type of the call (empty = do not log)
	 * @param   string                      $resource   API resource/endpoint (without leading slash)
	 * @param   string                      $method     HTTP method (POSTALREADYFORMATED is normalized to POST)
	 * @param   string|array<mixed>         $params     Request body
	 * @param   string|array<mixed>         $response   Response payload
	 * @param   int                         $statusCode HTTP status code of the response
	 * @return  ?array{id:int,call_id:string}           Created log identifiers, or null if not logged
	 */
	protected function logCall($callType, $resource, $method, $params, $response, $statusCode)
	{
		global $conf, $user, $dolibarr_main_db_pass, $dbhistory;

		if (empty($callType)) { // TODO : Add a parameter in module configuration to enable/disable logging
			return null;
		}

		// Reuse a process-wide independent connection so the trace is not bound to the
		// caller's transaction and persists even if that transaction is rolled back.
		if (empty($dbhistory)) {
			$dbhistory = getDoliDBInstance($conf->db->type, $conf->db->host, (string) $conf->db->user, $dolibarr_main_db_pass, (string) $conf->db->name, (int) $conf->db->port);
		}

		$dbhistory->begin();

		$call = new Call($dbhistory);
		$call->call_id = $call->getNextCallId();
		$call->call_type = $callType;
		$call->method = ($method == 'POSTALREADYFORMATED' ? 'POST' : $method);
		$call->endpoint = '/' . $resource;
		$call->request_body = is_array($params) ? json_encode($params) : $params;
		$call->response = is_array($response) ? json_encode($response) : $response;
		$call->provider = $this->name;
		$call->entity = $conf->entity;
		$call->status = ($statusCode == 200 || $statusCode == 202) ? 1 : 0;

		if ($call->create($user) > 0) {
			$dbhistory->commit();
			return array('id' => $call->id, 'call_id' => $call->call_id);
		}

		$dbhistory->rollback();
		dol_syslog(__METHOD__ . " Failed to log API call to PDP provider: " . $call->error . " - " . implode(',', $call->errors), LOG_ERR);
		return null;
	}

	/**
	 * Add an event/action record to track changes or activities related to an object
	 *
	 * @param   string      $eventType 	The type of event
	 * @param   string      $eventLabel The label of event
	 * @param   string      $eventMesg 	The message/label describing the event
	 * @param   object      $object 	The object (Invoice / Supplier invoice) that the event is associated with.
	 *
	 * @return  int         Id of created event, < 0 if KO
	 */
	public function addEvent($eventType, $eventLabel, $eventMesg, $object)
	{
		global $db, $user;
		require_once DOL_DOCUMENT_ROOT.'/comm/action/class/actioncomm.class.php';

		$actioncomm = new ActionComm($db);

		$actioncomm->type_code = 'AC_OTH_AUTO';
		$actioncomm->code = 'AC_EINVOICING_'.$eventType;

		if (!isset($object->thirdparty->id)) {
			$object->fetch_thirdparty();
		}
		// $object->thirdparty may still be null (e.g. document/flow with no resolvable socid)
		$actioncomm->socid = is_object($object->thirdparty ?? null) ? $object->thirdparty->id : 0;
		$actioncomm->label = $eventLabel;
		$actioncomm->note_private = $eventMesg;
		$actioncomm->fk_project = $object->fk_project;
		$actioncomm->datep = dol_now();
		$actioncomm->datef = dol_now();
		$actioncomm->percentage = -1;
		$actioncomm->authorid = $user->id;
		$actioncomm->userownerid = $user->id;
		$actioncomm->elementid = $object->id;
		$actioncomm->elementtype = $object->element;

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
	 * @param  Facture $object Invoice object
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

	/**
	 * Clear the fixed "last invoice that could not be processed" diagnostic files at the start of a
	 * sync run, so the diagnostic shown in the document list reflects the latest run. Each failed flow
	 * during the run re-creates its slot (see AbstractProtocol::cleanupIncomingTempFiles()). Call this
	 * from syncFlows() (the batch), not from syncFlow(), so a later flow does not erase an earlier
	 * failure within the same run.
	 *
	 * @return void
	 */
	protected function clearIncomingDiagnosticFiles()
	{
		global $conf;

		$tempDir = $conf->einvoicing->dir_temp;
		$diagFiles = array('facturx.pdf', 'facturx_readable.pdf', 'einvoice.xml', 'einvoice_readable.pdf');
		foreach ($diagFiles as $f) {
			if (file_exists($tempDir . '/' . $f)) {
				dol_delete_file($tempDir . '/' . $f);
			}
		}
	}
}
