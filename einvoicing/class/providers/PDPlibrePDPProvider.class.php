<?php
/* Copyright (C) 2026       Contributeurs PDPlibre
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
 * \file    einvoicing/class/providers/PDPlibrePDPProvider.class.php
 * \ingroup einvoicing
 * \brief   PDPlibre PA provider integration class (AFNOR XP_Z12-013, Bearer token RFC6750)
 */

dol_include_once('einvoicing/class/providers/AbstractPDPProvider.class.php');
dol_include_once('einvoicing/class/protocols/ProtocolManager.class.php');
dol_include_once('einvoicing/class/call.class.php');
dol_include_once('einvoicing/class/einvoicing.class.php');
dol_include_once('einvoicing/lib/einvoicing.lib.php');
require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';


/**
 * Class to manage PDPlibre PA provider integration.
 * Auth: RFC6750 Bearer token (API key sent directly as Bearer, no OAuth exchange).
 * Spec: https://git.pdplibre.org/Construction_PA/PA_Communautaire
 */
class PDPlibrePDPProvider extends AbstractPDPProvider
{
	/** @var string Name */
	public $name = 'PDPlibre';

	/** @var string Help to get credentials. */
	public $helpToGetCredentials = '';


	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		global $langs;

		parent::__construct($db);

		// Si une URL proxy est définie on l'utilise pour auth et api (dev/test local).
		$proxyUrl = getDolGlobalString('EINVOICING_PDPLIBRE_PROXY_URL');
		$isLive   = getDolGlobalInt('EINVOICING_LIVE', 0);
		$suffix   = $isLive ? '_PROD' : '';

		$defaultProdUrl = 'https://esalink.pdplibre.org/';
		$defaultTestUrl = 'https://pp.esalink.pdplibre.org/';  // Sandbox communautaire actuelle

		$resolvedUrl = $proxyUrl ?: ($isLive ? $defaultProdUrl : $defaultTestUrl);

		$this->config = array(
			'provider_url'   => $proxyUrl ?: ($isLive ? $defaultProdUrl : $defaultTestUrl),
			'prod_auth_url'  => $defaultProdUrl,
			'prod_api_url'   => $defaultProdUrl,
			'test_auth_url'  => $defaultTestUrl,
			'test_api_url'   => $defaultTestUrl,
			'proxy_url'      => $proxyUrl,
			'api_key'        => getDolGlobalString('EINVOICING_PDPLIBRE_API_KEY' . $suffix),
			'dol_prefix'     => 'EINVOICING_PDPLIBRE',
			'has_validator'  => 0,
			'live'           => $isLive,
		);

		$exchangeProtocolConf = getDolGlobalString('EINVOICING_PROTOCOL');
		$ProtocolManager      = new ProtocolManager($this->db);
		$this->exchangeProtocol = $ProtocolManager->getProtocol($exchangeProtocolConf);
	}


	/**
	 * Build the effective base URL to use (proxy takes precedence over prod/test).
	 *
	 * @return string URL with trailing slash
	 */
	private function getEffectiveBaseUrl()
	{
		if (!empty($this->config['proxy_url'])) {
			return rtrim($this->config['proxy_url'], '/') . '/';
		}
		return $this->getApiUrl('api');
	}


	/**
	 * Set the setup factory specific to the provider.
	 *
	 * @param FormSetup $formSetup        The form setup object to initialize
	 * @param string    $prefix           The prefix for configuration keys
	 * @param string    $prefixenv        The prefix for environment variable keys
	 * @param array     $providersConfig  The array containing providers configuration
	 * @param array     $TFieldProtocols  The array of available protocols
	 * @param array     $TFieldProfiles   The array of available profiles
	 * @return void
	 */
	public function initFormSetup(&$formSetup, $prefix, $prefixenv, $providersConfig, $TFieldProtocols, $TFieldProfiles)
	{
		global $langs, $mysoc;

		$langs->load("oauth");

		// API Key
		$item = $formSetup->newItem($prefix . 'API_KEY' . (getDolGlobalInt('EINVOICING_LIVE') ? '_PROD' : ''));
		$item->nameText = $langs->transnoentities('EINVOICING_API_KEY');
		$item->cssClass = 'minwidth500';

		// E-Invoice routing ID
		$item = $formSetup->newItem($prefix . 'ROUTING_ID');
		$item->nameText = $langs->transnoentities('EINVOICING_ROUTING_ID');
		$item->helpText = $langs->transnoentities('EINVOICING_ROUTING_ID_HELP');
		$item->helpText .= '<br><br>' . img_picto('', 'warning') . ' ' . $langs->trans('WarningIfYouSetAnIDItMustExistsInAnnuary');
		$item->fieldAttr['placeholder'] = idprof($mysoc);
		$item->fieldParams['isMandatory'] = 0;
		$item->cssClass = 'minwidth300';

		// Proxy URL (développement/test local)
		$item = $formSetup->newItem($prefix . 'PROXY_URL');
		$item->nameText = '[DEV] URL de l\'instance PDPlibre (remplace sandbox/prod)';
		$item->helpText = 'Laissez vide pour utiliser la sandbox par défaut. Indiquez ici l\'URL de votre instance PDPlibre locale ou de test (ex : http://localhost:8000/v1/).';
		$item->cssClass = 'minwidth500';

		// Actions de test (si clé API saisie)
		if (getDolGlobalString($prefix . 'API_KEY' . (getDolGlobalInt('EINVOICING_LIVE') ? '_PROD' : ''))) {
			$item = $formSetup->newItem($prefix . 'ACTIONS');
			$item->nameText = "&nbsp;";
			$item->fieldOverride = '<a class="reposition" href="' . $_SERVER["PHP_SELF"] . '?action=call' . $prefix . 'HEALTHCHECK&token=' . newToken() . '"><i class="fa fa-heartbeat pictofixedwidth centerimp"></i>' . $langs->trans('testConnection') . ' (Healthcheck)</a><br>';

			if (getDolGlobalString('EINVOICING_PROTOCOL') && !getDolGlobalInt('EINVOICING_LIVE')) {
				if (getDolGlobalInt('EINVOICING_ALLOW_DEVTOOLS')) {
					$item->fieldOverride .= '<a class="reposition" href="' . $_SERVER["PHP_SELF"] . '?action=make' . $prefix . 'sampleinvoice&token=' . newToken() . '"><i class="fa fa-file pictofixedwidth centerimp"></i>' . $langs->trans('generateSampleInvoice') . '</a><br>';
				}
				$item->fieldOverride .= '<a class="reposition" href="' . $_SERVER["PHP_SELF"] . '?action=makesend' . $prefix . 'sampleinvoice&token=' . newToken() . '"><i class="fa fa-file pictofixedwidth centerimp"></i>' . $langs->trans('generateSendSampleInvoice') . '</a><br>';
			}

			if ($mysoc->country_code == 'FR') {
				$item->fieldOverride .= '<a class="reposition" href="https://facturation.chorus-pro.gouv.fr/annuaire/#/" target="_blank"><i class="fa fa-list-alt pictofixedwidth centerimp"></i>' . $langs->trans('CheckYourIDInFrenchEInvoiceAnnuary') . '</a>';
			}

			$item->cssClass = 'minwidth500';
		}
	}


	/**
	 * Validate configuration parameters before API calls.
	 *
	 * @param  int  $mode  0 = check user/pass, 1 = check api key
	 * @return bool        True if configuration is valid.
	 */
	public function validateConfiguration($mode = 1)
	{
		global $langs;

		$error = array();
		if (empty($this->config['api_key'])) {
			$error[] = $langs->trans('ApiKeyIsRequired');
		}

		if (!empty($error)) {
			$this->errors[] = $langs->trans("CheckPdpConfiguration");
			$this->errors = array_merge($this->errors, $error);
		}
		return empty($error);
	}


	/**
	 * PDPlibre uses a static API key as Bearer token — no OAuth exchange needed.
	 * This method is a no-op; the key is sent directly in callApi().
	 *
	 * @return string|null The API key acting as access token, or null if not set.
	 */
	public function getAccessToken()
	{
		return $this->config['api_key'] ?: null;
	}


	/**
	 * Refresh access token (no-op for static API key).
	 *
	 * @return string|null
	 */
	public function refreshAccessToken()
	{
		return $this->getAccessToken();
	}


	/**
	 * Delete access token (no-op for static API key).
	 *
	 * @return bool
	 */
	public function deleteAccessToken()
	{
		return true;
	}


	/**
	 * Perform a health check call.
	 *
	 * @return array Contains 'status_code' (bool) and 'message' (string)
	 */
	public function checkHealth()
	{
		global $langs;

		$response = $this->callApi('healthcheck', 'GET', false, [], 'healthcheck');

		if ($response['status_code'] === 200) {
			return array(
				'status_code' => true,
				'message'     => $langs->trans('APApiReachable', getDolGlobalString('EINVOICING_PDP')),
			);
		}
		return array('status_code' => false, 'message' => 'HTTP ' . $response['status_code']);
	}


	/**
	 * Validate an electronic invoice file.
	 *
	 * @param  int     $idinvoice ID of the invoice
	 * @param  string  $filePath  Path to the invoice file
	 * @return array              Validation result
	 */
	public function validateEInvoiceFile($idinvoice, $filePath)
	{
		global $langs;

		if (empty($this->config['has_validator']) || $this->config['has_validator'] != 1) {
			return array('res' => -1, 'message' => $langs->trans('NoAvailableValidatorforThisAccessPoint'));
		}
		return array('res' => 0, 'message' => $langs->trans('skipped'));
	}


	/**
	 * Send an electronic invoice to PDPlibre.
	 *
	 * @param  Facture  $object  Invoice object
	 * @return false|array|string  flowId on success, false otherwise
	 */
	public function sendInvoice($object)
	{
		global $conf, $langs, $user;

		$filename = dol_sanitizeFileName($object->ref);
		$filedir  = $conf->invoice->multidir_output[$object->entity ?? $conf->entity] . '/' . dol_sanitizeFileName($object->ref);

		switch (getDolGlobalString('EINVOICING_PROTOCOL')) {
			case 'FACTURX':
				$suffix    = '_facturx.pdf';
				$mime_type = 'application/pdf';
				$flowSyntax = 'Factur-X';
				break;
			case 'CII':
				$suffix    = '_cii.xml';
				$mime_type = 'application/xml';
				$flowSyntax = 'CII';
				break;
			default:
				$suffix    = '_facturx.pdf';
				$mime_type = 'application/pdf';
				$flowSyntax = 'Factur-X';
		}

		$invoice_path = $filedir . '/' . $filename . $suffix;
		if (!file_exists($invoice_path)) {
			$this->errors[] = 'Electronic Invoice file not found';
			return false;
		}

		$uuid     = $this->generateUuidV4();
		$resource = 'flows?' . http_build_query(array('Request-Id' => $uuid));

		$extraHeaders = array('Content-Type' => 'multipart/form-data');
		$params = array(
			'flowInfo' => json_encode(array(
				'flowProfile' => 'Extended-CTC-FR',
				'flowSyntax'  => $flowSyntax,
				'trackingId'  => $object->ref,
				'name'        => 'Invoice_' . $object->ref,
				'sha256'      => hash_file('sha256', $invoice_path),
			)),
			'file' => new CURLFile($invoice_path, $mime_type, basename($invoice_path)),
		);

		$response = $this->callApi('flows', 'POSTALREADYFORMATED', $params, $extraHeaders, 'send_invoice');

		if ($response['status_code'] == 200 || $response['status_code'] == 202) {
			$flowId  = $response['response']['flowId'];
			$callRef = $response['call_id'] ?? '';

			$einvoicing = new EInvoicing($this->db);
			$einvoicing->insertOrUpdateExtLink($object->id, $object->element, $flowId, EInvoicing::STATUS_AWAITING_VALIDATION, $object->ref);

			// Tentative de récupération immédiate du statut de validation
			$resource = 'flows/' . $flowId . '?' . http_build_query(array('docType' => 'Metadata'));
			$response = $this->callApi($resource, 'GET', false, array('Accept' => 'application/octet-stream'), 'check_invoice_validation');

			if ($response['status_code'] == 200 || $response['status_code'] == 202) {
				try {
					$flowData = json_decode($response['response'], true);
				} catch (Exception $e) {
					return array('res' => -1, 'message' => 'FlowId: ' . $flowId . ' - Failed to parse the json answer');
				}

				$syncStatus      = $einvoicing::STATUS_AWAITING_VALIDATION;
				$ack_statusLabel = $flowData['acknowledgement']['status'] ?? '';
				if ($ack_statusLabel) {
					$syncStatus = $einvoicing->getDolibarrStatusCodeFromPdpLabel($ack_statusLabel);
				}
				$syncRef     = $flowData['trackingId'] ?? '';
				$syncComment = $flowData['acknowledgement']['details'][0]['reasonMessage'] ?? '';
				$einvoicing->insertOrUpdateExtLink($object->id, $object->element, $flowId, $syncStatus, $syncRef, $syncComment);

				$eventLabel   = 'EINVOICING - Status: ' . $ack_statusLabel . ' - ' . $callRef;
				$eventMessage = 'EINVOICING - Status: ' . $ack_statusLabel . (!empty($syncComment) ? ' - ' . $syncComment : '') . "\nFlowID=" . $flowId;
				$this->addEvent('STATUS', $eventLabel, $eventMessage, $object);
			}

			return $flowId;
		}

		$errormsg = $langs->trans('ErrorSendingInvoiceToPDP') . '<br>HTTP ' . $response['status_code'];
		if (!empty($response['errorCode'])) {
			$errormsg .= ' - ' . $response['errorCode'] . (empty($response['errorMessage']) ? '' : ' - ' . $response['errorMessage']);
		}
		if (!empty($response['curl_error_no'])) {
			$errormsg .= ' - Curl error ' . $response['curl_error_no'] . (empty($response['curl_error_msg']) ? '' : ' - ' . $response['curl_error_msg']);
		}
		$this->errors[] = $errormsg;
		return false;
	}


	/**
	 * Send a sample invoice for testing.
	 *
	 * @param  int  $onlymake  1 = generate only, do not send
	 * @return array|string
	 */
	public function sendSampleInvoice($onlymake = 0)
	{
		global $langs;

		$outputLog = array();
		$einvoicing = new EInvoicing($this->db);

		try {
			if ((float) DOL_VERSION < 24.0) {
				$resarray = $this->exchangeProtocol->generateSampleInvoiceOld($einvoicing);
			} else {
				$resarray = $this->exchangeProtocol->generateSampleInvoice($einvoicing);
			}
			if ($resarray === -1) {
				$this->errors[] = $this->exchangeProtocol->error;
				return '';
			}
			$invoice_path = $resarray['path'];
			$ref          = $resarray['ref'];
		} catch (Exception $e) {
			$this->errors[] = $e->getMessage();
			return '';
		}

		if (empty($ref) || empty($invoice_path)) {
			$this->errors[] = 'Failed to generate the sample invoice';
			return '';
		}

		$outputLog[] = 'Sample invoice generated successfully.';

		if ($onlymake) {
			return $outputLog;
		}

		$file_info = pathinfo($invoice_path);
		$fileext   = $file_info['extension'] ?? '';
		$mime_type = (strtolower($fileext) == 'pdf') ? 'application/pdf' : 'text/xml';

		$extraHeaders = array('Content-Type' => 'multipart/form-data');
		$params = array(
			'flowInfo' => json_encode(array(
				'trackingId'  => $ref,
				'name'        => 'Invoice_' . $ref,
				'flowSyntax'  => 'Factur-X',
				'flowProfile' => 'CIUS',
				'sha256'      => hash_file('sha256', $invoice_path),
			)),
			'file' => new CURLFile($invoice_path, $mime_type, basename($invoice_path)),
		);

		$response = $this->callApi('flows', 'POSTALREADYFORMATED', $params, $extraHeaders, 'send_sample_invoice');

		if ($response['status_code'] == 200 || $response['status_code'] == 202) {
			$flowId = $response['response']['flowId'];
			$outputLog[] = 'Sample invoice sent successfully.';

			$resource = 'flows/' . $flowId . '?' . http_build_query(array('docType' => 'Original'));
			$response = $this->callApi($resource, 'GET', false, array('Accept' => 'application/octet-stream'), 'retrieve_sample_invoice');

			if ($response['status_code'] == 200 || $response['status_code'] == 202) {
				include_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
				$tmpobject  = new Facture($this->db);
				$output_path = getMultidirTemp($tmpobject, 'einvoicing') . '/test_retrieved_invoice.' . $fileext;
				file_put_contents($output_path, $response['response']);
				$outputLog[] = 'Sample invoice retrieved successfully.';
				return $outputLog;
			}

			$this->errors[] = 'Failed to retrieve sample invoice.';
			return '';
		}

		$errormsg = $langs->trans('ErrorSendingInvoiceToPDP') . '<br>HTTP ' . $response['status_code'];
		if (!empty($response['errorCode'])) {
			$errormsg .= ' - ' . $response['errorCode'] . (empty($response['errorMessage']) ? '' : ' - ' . $response['errorMessage']);
		}
		if (!empty($response['curl_error_no'])) {
			$errormsg .= ' - Curl error ' . $response['curl_error_no'] . (empty($response['curl_error_msg']) ? '' : ' - ' . $response['curl_error_msg']);
		}
		$this->errors[] = $errormsg;
		return $errormsg;
	}


	/**
	 * Call the PDPlibre API.
	 * Auth: RFC6750 Bearer token (API key sent directly, no OAuth exchange).
	 * If EINVOICING_PDPLIBRE_PROXY_URL is set, it overrides prod/test URL.
	 *
	 * @param string                    $resource     Resource relative URL
	 * @param string                    $method       HTTP method
	 * @param string|false|array<string,mixed> $params Request body (JSON string, false, or array for multipart)
	 * @param array<string,string>      $extraHeaders Additional headers
	 * @param string|null               $callType     Functional type for logging
	 * @return array
	 */
	public function callApi($resource, $method, $params = false, $extraHeaders = [], $callType = '')
	{
		global $conf, $user;

		if (!$this->validateConfiguration()) {
			return array('status_code' => 400, 'response' => $this->errors);
		}

		require_once DOL_DOCUMENT_ROOT . '/core/lib/geturl.lib.php';

		$url = $this->getEffectiveBaseUrl() . $resource;

		$httpheader = array(
			'Authorization: Bearer ' . $this->config['api_key'],
		);

		if (!isset($extraHeaders['Content-Type'])) {
			$httpheader[] = 'Content-Type: application/json';
			$httpheader[] = 'Accept: application/json';
		}

		foreach ($extraHeaders as $key => $value) {
			$httpheader[] = $key . ': ' . $value;
		}

		$response    = getURLContent($url, $method, $params, 1, $httpheader, array('http', 'https'), 0, -1, 0, 0, array(), '_einvoicing');
		$status_code = $response['http_code'];

		if ($status_code == 200 || $status_code == 202) {
			$body = $response['content'];
			if (!isset($extraHeaders['Accept'])) {
				$body = json_decode($body, true);
			}
			$returnarray = array('status_code' => $status_code, 'response' => $body);
		} else {
			$returnarray = array(
				'status_code' => $status_code,
				'response'    => 'Error ' . $status_code . ' - ' . (string) $response['content'],
			);
			if (!empty($response['curl_error_no'])) {
				$returnarray['curl_error_no'] = $response['curl_error_no'];
			}
			if (!empty($response['curl_error_msg'])) {
				$returnarray['curl_error_msg'] = $response['curl_error_msg'];
			}
			if ($contentarray = json_decode((string) $response['content'], true)) {
				$returnarray['errorCode']    = $contentarray['errorCode'] ?? '';
				$returnarray['errorMessage'] = $contentarray['errorMessage'] ?? '';
			}
		}

		$logged = $this->logCall($callType, $resource, $method, $params, $returnarray['response'], $returnarray['status_code']);
		if ($logged !== null) {
			$returnarray['id']      = $logged['id'];
			$returnarray['call_id'] = $logged['call_id'];
		}

		return $returnarray;
	}


	/**
	 * Synchronize flows with PDPlibre.
	 *
	 * @param  int  $syncFromDate  Timestamp from which to start synchronization
	 * @param  int  $limit         Maximum number of flows. 0 = no limit.
	 * @return bool|array
	 */
	public function syncFlows($syncFromDate = 0, $limit = 0)
	{
		// TODO: implémenter la synchronisation des flux entrants selon l'API PDPlibre.
		// La spec AFNOR XP_Z12-013 définit GET /flows avec filtres (updatedAfter, etc.).
		// S'inspirer de EsalinkPDPProvider::syncFlows() une fois l'API stabilisée.
		global $langs;
		return array('res' => 0, 'messages' => array($langs->trans('NotYetImplemented')));
	}


	/**
	 * Sync a single flow by its flowId.
	 *
	 * @param  string       $flowId   Flow identifier
	 * @param  string|null  $call_id  Call ID for logging
	 * @return array{res:int, message:string, action:string|null}
	 */
	public function syncFlow($flowId, $call_id = null)
	{
		// TODO: implémenter la synchronisation unitaire d'un flux PDPlibre.
		return array('res' => 0, 'message' => 'syncFlow not yet implemented for PDPlibre', 'action' => null);
	}


	/**
	 * Send a lifecycle status message (CDAR) for an invoice to PDPlibre.
	 *
	 * @param  mixed   $object      Invoice object
	 * @param  int     $statusCode  Status code to send
	 * @param  string  $reasonCode  Reason code (optional)
	 * @return array{res:int, message:string}
	 */
	public function sendStatusMessage($object, $statusCode, $reasonCode = '')
	{
		// TODO: implémenter l'envoi de message de cycle de vie (CDAR) vers PDPlibre.
		// S'inspirer de EsalinkPDPProvider::sendStatusMessage() une fois l'API stabilisée.
		global $langs;
		return array('res' => -1, 'message' => 'sendStatusMessage not yet implemented for PDPlibre');
	}
}
