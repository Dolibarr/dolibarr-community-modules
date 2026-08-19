<?php
/* Copyright (C) 2026       Pierre Grasswill            <da.grumpf@gmail.com>
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
 * \file    einvoicing/class/providers/TestPDPProvider.class.php
 * \ingroup einvoicing
 * \brief   Reference implementation of a PDP provider. Sends nothing, calls nothing.
 */

dol_include_once('einvoicing/class/providers/AbstractPDPProvider.class.php');
dol_include_once('einvoicing/class/protocols/ProtocolManager.class.php');
dol_include_once('einvoicing/class/einvoicing.class.php');
// Needed by AbstractPDPProvider::logCall(), which records the API calls.
dol_include_once('einvoicing/class/call.class.php');


/**
 * Reference implementation of a PDP provider.
 *
 * This class is the skeleton to copy to integrate a new platform, either here or from an external
 * module (see einvoicing/doc/ADD-A-PDP-PROVIDER.md). It implements every method the rest of the
 * module calls, with the smallest body that is still honest: nothing here reaches the network, and
 * the methods that would need a platform to answer return an explicit error instead of pretending.
 *
 * What it does exercise for real, because it needs no platform: the configuration form of the setup
 * page, the storage of a token, the generation of a sample invoice by the exchange protocol, and the
 * logging of the API calls. That is enough to check that a new provider is correctly declared before
 * writing a single line of HTTP.
 */
class TestPDPProvider extends AbstractPDPProvider
{
	/**
	 * @var string		Name
	 */
	public $name = 'TestPDP';

	/**
	 * @var string		Help to get credentials and set up the provider configuration.
	 */
	public $helpToGetCredentials = '';


	/**
	 * Constructor
	 *
	 * Load setup properties and last token.
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		parent::__construct($db);

		// The keys read by AbstractPDPProvider and by the setup page. 'dol_prefix' is the important one:
		// it is the prefix of every constant of this provider (EINVOICING_TESTPDP_API_KEY here), of the
		// actions of the setup page (setEINVOICING_TESTPDP_TOKEN...) and of the rows storing its token.
		$this->config = array(
			'provider_url' => 'https://example.com',
			'prod_auth_url' => 'https://example.com/api/v1/',
			'prod_api_url' => 'https://example.com/api/v1/',
			'test_auth_url' => 'https://sandbox.example.com/api/v1/',
			'test_api_url' => 'https://sandbox.example.com/api/v1/',
			'api_key' => getDolGlobalString('EINVOICING_TESTPDP_API_KEY'.(getDolGlobalInt('EINVOICING_LIVE') ? '_PROD' : '')),
			'dol_prefix' => 'EINVOICING_TESTPDP',
			'has_validator' => 0,
			'live' => getDolGlobalInt('EINVOICING_LIVE', 0),
		);

		// Retrieve and complete the OAuth token information from the database
		$this->tokenData = $this->fetchOAuthTokenDB();

		// The protocol builds the XML (CII, Factur-X, UBL...). It is chosen by the user, not by the
		// provider, so every provider loads it the same way.
		/*
		$ProtocolManager = new ProtocolManager($this->db);
		$this->exchangeProtocol = $ProtocolManager->getProtocol(getDolGlobalString('EINVOICING_PROTOCOL'));
		*/
	}


	/**
	 * Set the setup factory specific to the provider.
	 *
	 * Called by admin/setup.php to build the second block of the setup page, the one holding the
	 * parameters of the selected provider. Everything shown there is up to the provider.
	 *
	 * @param FormSetup $formSetup 			The form setup object to initialize
	 * @param string 	$prefix 			The prefix for configuration keys ('EINVOICING_TESTPDP_' here)
	 * @param string 	$prefixenv 			'prod' or 'test', depending on EINVOICING_LIVE
	 * @param array 	$providersConfig 	The array containing providers configuration
	 * @param array 	$TFieldProtocols 	The array of available protocols to set in the select field
	 * @param array 	$TFieldProfiles 	The array of available profiles to set in the select field
	 * @return void
	 */
	public function initFormSetup(&$formSetup, $prefix, $prefixenv, $providersConfig, $TFieldProtocols, $TFieldProfiles)
	{
		global $langs, $mysoc;

		$langs->load("oauth");

		$tokenData = $this->getTokenData();

		// Printed above the form by admin/setup.php.
		$url = $providersConfig[getDolGlobalString('EINVOICING_PDP')][$prefixenv.'_account_admin_url'] ?? '';
		if ($url) {
			$this->helpToGetCredentials = '<div class="formborderx info">';
			$this->helpToGetCredentials .= img_picto('', 'url', 'class="pictofixedwidth"').'<a href="'.$url.'" target="_new">'.$url.'</a>';
			$this->helpToGetCredentials .= '</div>';
		}

		// The address this company is reachable at on the platform. Left empty, the module falls back
		// on the legal identifier of the company (idprof).
		$item = $formSetup->newItem($prefix.'ROUTING_ID');
		$item->nameText = $langs->transnoentities('EINVOICING_ROUTING_ID');
		$item->helpText = $langs->transnoentities('EINVOICING_ROUTING_ID_HELP');
		$item->fieldAttr['placeholder'] = idprof($mysoc);
		$item->fieldParams['isMandatory'] = 0;
		$item->cssClass = 'minwidth300';

		// Credentials. The '_PROD' suffix keeps the production credentials apart from the test ones, so
		// switching EINVOICING_LIVE does not make the module talk to a platform with the wrong keys.
		/*
		$item = $formSetup->newItem($prefix.'API_KEY'.(getDolGlobalInt('EINVOICING_LIVE') ? '_PROD' : ''));
		$item->nameText = $langs->transnoentities('EINVOICING_API_KEY');
		$item->cssClass = 'minwidth500';
		*/

		// Token. The link triggers the action set<prefix>TOKEN read by admin/setup.php, which calls
		// getAccessToken() below.
		if (!empty($this->config['api_key'])) {
			$item = $formSetup->newItem($prefix.'TOKEN'.(getDolGlobalInt('EINVOICING_LIVE') ? '_PROD' : ''));
			$item->nameText = $langs->trans('AccessToken');
			$item->cssClass = 'maxwidth500';
			$item->fieldOverride = '';
			if (!empty($tokenData['token'])) {
				$item->fieldOverride = htmlspecialchars('**************'.substr((string) $tokenData['token'], -4));
				$item->fieldOverride .= ' &nbsp; '.img_picto('', 'delete', 'class="pictofixedwidth"');
				$item->fieldOverride .= '<a href="'.$_SERVER["PHP_SELF"].'?action=delete'.$prefix.'TOKEN&token='.newToken().'">'.$langs->trans("ClickHereToRemoveConnection").'</a>';
			} else {
				$item->fieldOverride = '<a class="reposition" href="'.$_SERVER["PHP_SELF"].'?action=set'.$prefix.'TOKEN&token='.newToken().'">'.$langs->trans('generateAccessToken').'<i class="fa fa-key paddingleft"></i></a>';
			}
		}

		// Actions offered once connected. Each link triggers an action read by admin/setup.php, which
		// calls the matching method of this class.
		if (!empty($tokenData['token'])) {
			$item = $formSetup->newItem($prefix.'ACTIONS');
			$item->nameText = "&nbsp;";
			$item->cssClass = 'minwidth500';
			$item->fieldOverride = '<a class="reposition" href="'.$_SERVER["PHP_SELF"].'?action=call'.$prefix.'HEALTHCHECK&token='.newToken().'"><i class="fa fa-heartbeat pictofixedwidth centerimp"></i>'.$langs->trans('testConnection').' (Healthcheck)</a><br>';
			if (getDolGlobalString('EINVOICING_PROTOCOL') && getDolGlobalInt('EINVOICING_ALLOW_DEVTOOLS') && !getDolGlobalString('EINVOICING_LIVE')) {
				$item->fieldOverride .= '<a class="reposition" href="'.$_SERVER["PHP_SELF"].'?action=make'.$prefix.'sampleinvoice&token='.newToken().'"><i class="fa fa-file pictofixedwidth centerimp"></i>'.$langs->trans('generateSampleInvoice').'</a><br>';
			}
		}
	}


	/**
	 * Validate configuration parameters before API calls.
	 *
	 * @param 	int		$mode 	0 check that credentials are set, 1 check that token is set
	 * @return 	bool 			True if configuration is valid.
	 */
	public function validateConfiguration($mode = 1)
	{
		global $langs;

		if (empty($this->config['api_key'])) {
			$this->errors[] = $langs->trans('ErrorFieldRequired', $langs->transnoentities('EINVOICING_API_KEY'));
			return false;
		}

		if ($mode == 1 && empty($this->tokenData['token'])) {
			$this->errors[] = $langs->trans('FailedToRetrieveAccessToken');
			return false;
		}

		return true;
	}


	/**
	 * Get access token from the platform and save it into database.
	 *
	 * A real provider posts its credentials to the auth endpoint here. This one derives a token from
	 * the API key, so the storage and the expiration handling can be exercised without a platform.
	 *
	 * @return string|null 		Access token or null on failure.
	 */
	public function getAccessToken()
	{
		if (!$this->validateConfiguration(0)) {
			return null;
		}

		$token = 'testpdp-'.dol_hash($this->config['api_key'].'-'.dol_now(), '5');
		$expiresIn = 3600;

		if (!$this->saveOAuthTokenDB($token, null, $expiresIn)) {
			return null;
		}

		$this->tokenData = $this->fetchOAuthTokenDB();

		return $token;
	}


	/**
	 * Refresh access token.
	 *
	 * @return string|null 		New access token or null on failure.
	 */
	public function refreshAccessToken()
	{
		// No refresh token on this provider: a new token is simply asked for.
		return $this->getAccessToken();
	}


	/**
	 * Perform a health check call for the provider endpoint.
	 *
	 * @return array{status_code:int,message:string}	Result of the check
	 */
	public function checkHealth()
	{
		global $langs;

		// A real provider calls its own endpoint here, for example:
		// $response = $this->callApi('healthcheck', 'GET', false, array(), 'healthcheck');
		// and maps the HTTP code it gets to the array returned below.
		return array(
			'status_code' => 200,
			'message' => $langs->trans('APApiReachable', $this->name).' ('.$this->getApiUrl('api').')',
		);
	}


	/**
	 * Retrieve and format remote account/company information from the provider and peppol directory, if available,
	 * For display to the user.
	 *
	 * @return array{status_code:int,message:string}
	 */
	public function getRemoteInfo()
	{
		return array(
			'status_code' => -1,
			'message' => 'Not yet implemented',
		);
	}


	/**
	 * Call the provider API.
	 *
	 * This is the single place where a provider talks to its platform: every other method goes through
	 * it, which is what makes the call log of the module complete. A real implementation builds the
	 * request with getURLContent() (or curl), then calls logCall() with the response.
	 *
	 * @param string 						$resource 	    Resource relative URL
	 * @param 'POST'|'GET'|'HEAD'|'PUT'|'PUTALREADYFORMATED'|'POSTALREADYFORMATED'|'DELETE' $method	HTTP method
	 * @param string|false 					$options 	    Options for the request (JSON encoded)
	 * @param array<string, string>			$extraHeaders   Optional additional headers
	 * @param string|null					$callType       Functional type of the API call for logging purposes
	 * @return array{status_code:int,response:null|string|array<string,mixed>,call_id:null|string}
	 */
	public function callApi($resource, $method, $options = false, $extraHeaders = [], $callType = '')
	{
		$statusCode = 501;
		$response = array('error' => 'This sample provider performs no API call');

		// Record the call, so it shows up in the "API calls" list of the module. The log is written on
		// its own database connection, so it survives a rollback of the business transaction.
		$logres = $this->logCall($callType, $resource, $method, $options, $response, $statusCode);

		return array('status_code' => $statusCode, 'response' => $response, 'call_id' => $logres['call_id'] ?? null);
	}


	/**
	 * Send an electronic invoice.
	 *
	 * @param  Facture $object 	Invoice object
	 * @return string|false		Flow id given by the platform, false on failure.
	 */
	public function sendInvoice($object)
	{
		global $langs;

		// A real implementation: build the file with $this->exchangeProtocol, check the recipient with
		// $this->checkRecipientDirectory(), POST it with $this->callApi(), then return the id the
		// platform answers with. That id is stored on the document and every status message refers to it.
		$this->errors[] = $langs->trans('FeatureNotYetAvailable').' - '.$this->name.'::sendInvoice';

		return false;
	}


	/**
	 * Send status message of an invoice to the platform (lifecycle of the e-invoice).
	 *
	 * @param mixed		$object			Invoice object (CustomerInvoice or SupplierInvoice)
	 * @param int		$statusCode		Status code to send
	 * @param string	$reasonCode		Reason code to send (optional)
	 * @param array{amount?:float,breakdown?:array<array{vatrate:float,amount:float}>} $paymentData Cashed amount for status 212
	 * @return array{res:int, message:string}	'res' 1 on success, -1 on failure
	 */
	public function sendStatusMessage($object, $statusCode, $reasonCode = '', $paymentData = array())
	{
		global $langs;

		// A real implementation builds a CDAR with CdarHandler and posts it with $this->callApi().
		return array('res' => -1, 'message' => $langs->trans('FeatureNotYetAvailable').' - '.$this->name.'::sendStatusMessage');
	}


	/**
	 * Send a sample electronic invoice for testing purposes.
	 *
	 * The generation itself belongs to the exchange protocol, not to the provider, so this part is
	 * identical for every provider and is worth keeping as is when copying this class.
	 *
	 * @param 	int 			$onlymake		1=to only make the sample
	 * @return 	array|int						Array of messages, 0 on failure.
	 */
	public function sendSampleInvoice($onlymake = 0)
	{
		global $langs;

		$outputLog = array();

		$einvoicing = new EInvoicing($this->db);

		try {
			if (empty($this->exchangeProtocol)) {
				$exchangeProtocolConf = getDolGlobalString('EINVOICING_PROTOCOL');
				$ProtocolManager = new ProtocolManager($this->db);
				$this->exchangeProtocol = $ProtocolManager->getProtocol($exchangeProtocolConf);
			}

			if ((float) DOL_VERSION < 24.0) {
				$resarray = $this->exchangeProtocol->generateSampleInvoiceOld($einvoicing);
			} else {
				$resarray = $this->exchangeProtocol->generateSampleInvoice($einvoicing);
			}
			if ($resarray === -1) {
				$this->errors[] = $this->exchangeProtocol->error;
				return 0;
			}
			$invoice_path = (string) $resarray['path'];
			$ref = $resarray['ref'];
		} catch (Exception $e) {
			$this->errors[] = $e->getMessage();
			return 0;
		}

		if (empty($ref) || empty($invoice_path)) {
			$this->errors[] = 'Failed to generate the sample invoice';
			return 0;
		}

		$outputLog[] = "Sample invoice generated successfully: ".$invoice_path;

		if ($onlymake) {
			return $outputLog;
		}

		// A real implementation posts the generated file here, like sendInvoice() does.
		$this->errors[] = $langs->trans('FeatureNotYetAvailable').' - '.$this->name.'::sendSampleInvoice';

		return 0;
	}


	/**
	 * Validate an electronic invoice file using the provider's validation service.
	 *
	 * Only called when the provider declares 'has_validator' => 1 in its config.
	 *
	 * @param 	int 	$idinvoice 	ID of the invoice to check
	 * @param 	string 	$filePath 	Path to the invoice file to validate
	 * @return 	array{res:int,message:string}	Validation result
	 */
	public function validateEInvoiceFile($idinvoice, $filePath)
	{
		global $langs;

		return array('res' => -1, 'message' => $langs->trans('NoAvailableValidatorforThisAccessPoint'));
	}


	/**
	 * Synchronize flows with the platform: the incoming documents (supplier invoices, status messages)
	 * and the status of the ones already sent. Called by the cron job of the module.
	 *
	 * @param   int   $syncFromDate     Timestamp from which to start synchronization. 0 = from epoch.
	 * @param   int   $limit            Maximum number of flows to synchronize. 0 means no limit.
	 * @return 	array{res:int, messages:string[], totalFlows?:?int, alreadyExist?:int, syncedFlows?:int}
	 */
	public function syncFlows($syncFromDate = 0, $limit = 0)
	{
		// A real implementation asks the platform for the flows changed since $syncFromDate, then hands
		// each one to syncFlow() below. Call clearIncomingDiagnosticFiles() first, so the diagnostic
		// shown in the document list is the one of this run.
		return array(
			'res' => 1,
			'messages' => array('This sample provider has no platform to synchronize with'),
			'totalFlows' => 0,
			'alreadyExist' => 0,
			'syncedFlows' => 0,
		);
	}


	/**
	 * Synchronize one flow: download it, and either create the supplier invoice it carries or update
	 * the status of the invoice it refers to.
	 *
	 * @param string		$flowId		Flow id on the platform
	 * @param string|null	$call_id	Call ID for logging purposes
	 * @return array{res:int, message:string, action:string|null}	'res' 1 on success, 0 if already known, -1 on failure
	 */
	public function syncFlow($flowId, $call_id = null)
	{
		global $langs;

		return array(
			'res' => -1,
			'message' => $langs->trans('FeatureNotYetAvailable').' - '.$this->name.'::syncFlow',
			'action' => null,
		);
	}
}
