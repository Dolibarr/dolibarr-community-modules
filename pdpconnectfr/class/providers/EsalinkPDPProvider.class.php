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
 * \file    pdpconnectfr/class/providers/EsalinkPDPProvider.class.php
 * \ingroup pdpconnectfr
 * \brief   Esalink PDP provider integration class
 */

use Luracast\Restler\Data\Arr;

dol_include_once('pdpconnectfr/class/providers/AbstractPDPProvider.class.php');
dol_include_once('pdpconnectfr/class/protocols/ProtocolManager.class.php');
dol_include_once('pdpconnectfr/class/call.class.php');
require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';


/**
 * Class to manage Esalink PDP provider integration.
 */
class EsalinkPDPProvider extends AbstractPDPProvider
{
    /**
     * Constructor
     *
     */
    public function __construct($db) {
    	parent::__construct($db);

        $this->config = array(
            'provider_url' => 'https://ppd.hubtimize.fr',
            'prod_api_url' => 'https://ppd.hubtimize.fr/api/orchestrator/v1/', // TODO: Replace the URL once known
            'test_api_url' => 'https://ppd.hubtimize.fr/api/orchestrator/v1/',
            'username' => getDolGlobalString('PDPCONNECTFR_ESALINK_USERNAME', ''),
            'password' => getDolGlobalString('PDPCONNECTFR_ESALINK_PASSWORD', ''),
            'api_key' => getDolGlobalString('PDPCONNECTFR_ESALINK_API_KEY', ''),
            'api_secret' => getDolGlobalString('PDPCONNECTFR_ESALINK_API_SECRET', ''),
            'dol_prefix' => 'PDPCONNECTFR_ESALINK',
            'live' => getDolGlobalInt('PDPCONNECTFR_LIVE', 0)
        );

        // Retrieve and complete the OAuth token information from the database
       	$this->tokenData = $this->fetchOAuthTokenDB();

        $exchangeProtocolConf = getDolGlobalString('PDPCONNECTFR_PROTOCOL');
        $ProtocolManager = new ProtocolManager($this->db);
        $this->exchangeProtocol = $ProtocolManager->getprotocol($exchangeProtocolConf);
    }

    /**
     * Validate configuration parameters before API calls.
     *
     * @return bool True if configuration is valid.
     */
    public function validateConfiguration()
    {
        global $langs;
        $error = array();
        if (empty($this->config['username'])) {
            $error[] = $langs->trans('UsernameIsRequired');
        }
        if (empty($this->config['password'])) {
            $error[] = $langs->trans('PasswordIsRequired');
        }
        if (empty($this->config['api_key'])) {
            $error[] = $langs->trans('ApiKeyIsRequired');
        }
        if (!empty($error)) {
            $this->errors[] = $langs->trans("CheckEsalinkPdpConfiguration");
            $this->errors = array_merge($this->errors, $error);
        }
        return empty($error);
    }

    /**
     * Get access token.
     *
     * @return string|null Access token or null on failure.
     */
    public function getAccessToken() {
        global $db, $conf, $langs;

        $param = json_encode(array(
            'username' => $this->config['username'],
            'password' => $this->config['password']
        ));

        $response = $this->callApi("token", "POSTALREADYFORMATED", $param);

        $status_code = $response['status_code'];
		$body = $response['response'];

        if ($status_code == 200 && isset($body['access_token']) && isset($body['refresh_token']) && isset($body['expires_in'])) {
            $this->saveOAuthTokenDB($body['access_token'], $body['refresh_token'], $body['expires_in']);

            return $body['access_token'];
        } else {
            $this->errors[] = $langs->trans("FailedToRetrieveAccessToken");
            return null;
        }

    }

    /**
     * Refresh access token.
     *
     * @return string|null New access token or null on failure.
     */
    public function refreshAccessToken() {
        // No route to refresh token for Esalink PDP provider so we get a new one
        return $this->getAccessToken();
    }

    /**
     * Perform a health check call for Esalink PDP provider.
     *
     * @return array Contains 'status' (bool) and 'message' (string)
     */
    public function checkHealth()
    {
        global $langs;

        $response = $this->callApi("healthcheck", "GET", false, [], 'Healthcheck');

        if ($response['status_code'] === 200) {
            $returnarray['status_code'] = true;
            $returnarray['message'] = $langs->trans('EsalinkPdpApiReachable');
        } else {
            $returnarray['status_code'] = false;
        }

        return $returnarray;
    }


    /**
     * Send an electronic invoice.
     *
     * This function send an invoice to PDP
     *
     * $object Invoice object
     * @return string   flowId if the invoice was successfully sent, false otherwise.
     */
    public function sendInvoice($object)
    {
        global $conf, $langs, $user, $db;

        $outputLog = array(); // Feedback to display

        $filename = dol_sanitizeFileName($object->ref);
		$filedir = $conf->invoice->multidir_output[$object->entity ?? $conf->entity].'/'.dol_sanitizeFileName($object->ref);
        $invoice_path = $filedir.'/'.$filename.'_facturx.pdf';

        if (!file_exists($invoice_path)) {
            $this->errors[] = "Electronic Invoice file not found";
            return false;
        }

        $file_info = pathinfo($invoice_path);
        $uuid = $this->generateUuidV4(); // UUID used to correlate logs between Dolibarr and PDP TODO : Store it somewhere

        // Format PDP resource Url
        $resource = 'flows';
        $urlparams = array(
            'Request-Id' => $uuid,
        );
		$resource .= '?' . http_build_query($urlparams);

        // Extra headers
        $extraHeaders = [
            'Content-Type' => 'multipart/form-data'
        ];

        // Params
        $params = [
            'flowInfo' => json_encode([
                "trackingId" => $object->ref,
                "name" => "Invoice_" . $object->ref,
                "flowSyntax" => "FACTUR-X",
                "flowProfile" => "CIUS",
                "sha256" => hash_file('sha256', $invoice_path)
            ]),
            'file' => new CURLFile($invoice_path, 'application/pdf', basename($invoice_path))
        ];



        $response = $this->callApi("flows", "POSTALREADYFORMATED", $params, $extraHeaders, 'Send Invoice');

        if ($response['status_code'] == 200 || $response['status_code'] == 202) {
            /**
             * We make an additional call to retrieve the acknowledgment information and update the status.
             * However, document validation on the PDP side may take some time.
             * Therefore, we initially set the status to "Sent".
             *
             * We then try to fetch the PDP validation result:
             * - If the validation is successful, we update the status to "Sent (awaiting acknowledgment)".
             * - If the PDP validation fails, we set the status to "Error".
             *
             * If no response is available yet, we wait for the next synchronization.
             **/


            // Update einvoice status to sent awaiting validation
            $object->array_options['options_pdpconnectfr_einvoice_status'] = 2;
            $object->insertExtraFields();
            $flowId = $response['response']['flowId'];

            // Call the API to retrieve flow details and check the validation status.
            // A short delay is applied to allow the PDP time to process the document.
            //sleep(10);
            $resource = 'flows/' . $flowId;
            $urlparams = array(
                'docType' => 'Metadata',
            );
            $resource .= '?' . http_build_query($urlparams);
            $response = $this->callApi(
                $resource,
                "GET",
                false,
                ['Accept' => 'application/octet-stream'],
                'Check Invoice validation'
            );

            if ($response['status_code'] == 200 || $response['status_code'] == 202) {
                dol_include_once('pdpconnectfr/class/document.class.php');

                $flowData = json_decode($response['response'], true);

                // Create a document log entry for this flow retrieval
                $document = new Document($db);
                $document->date_creation        = dol_now();
                $document->fk_user_creat        = $user->id;
                $document->fk_call              = null;
                $document->flow_id              = $flowId;
                $document->tracking_idref       = $flowData['trackingId'] ?? null;
                $document->flow_type            = "ManualValidationCheck";
                $document->flow_direction       = $flowData['flowDirection'] ?? null;
                $document->flow_syntax          = $flowData['flowSyntax'] ?? null;
                $document->flow_profile         = $flowData['flowProfile'] ?? null;
                $document->ack_status           = $flowData['acknowledgement']['status'] ?? null;
                $ack_message = '';
                // Change this fields to fit with the new api response ===============================================
                $document->ack_reason_code      = $flowData['acknowledgement']['details'][0]['reasonCode'] ?? null;
                $document->ack_info             = $flowData['acknowledgement']['details'][0]['reasonMessage'] ?? null;
                // Change this fields to fit with the new api response ===============================================
                $document->document_body        = null;
                $document->fk_element_id        = $object->id;
                $document->fk_element_type      = Facture::class;

                if (!empty($flowData['submittedAt'])) {
                    $dt = new DateTimeImmutable($flowData['submittedAt'], new DateTimeZone('UTC'));
                    $document->submittedat = $db->idate($dt->getTimestamp());
                } else {
                    $document->submittedat = null;
                }
                if (!empty($flowData['updatedAt'])) {
                    $dt = new DateTimeImmutable($flowData['updatedAt'], new DateTimeZone('UTC'));
                    $document->updatedat = $db->idate($dt->getTimestamp());
                } else {
                    $document->updatedat = null;
                }
                $document->provider             = getDolGlobalString('PDPCONNECTFR_PDP') ?? null;
                $document->entity               = $conf->entity;
                $document->flow_uiid            = $flowData['uuid'] ?? null;

                // Log an event in the invoice timeline
                $statusLabel = $document->ack_status;
                $reasonDetail = $document->ack_info ? " - {$document->ack_info}" : '';

                $eventLabel = "PDPCONNECTFR - Status: {$statusLabel}";
                $eventMessage = "PDPCONNECTFR - Status: {$statusLabel}{$reasonDetail}";

                $resLogEvent = $this->addEvent('STATUS', $eventLabel, $eventMessage, $object);
                if ($resLogEvent < 0) {
                    dol_syslog(__METHOD__ . " Failed to log event for flowId: {$flowId}", LOG_WARNING);
                }

                $res = $document->create($user);
                if ($res < 0) {
                    //print_r($document->errors);
                    dol_syslog(__METHOD__ . " Failed to create document log for flowId: {$flowId}", LOG_WARNING);
                }


            //     // Check acknowledgement status
            // 	$ack_status = $flowData['acknowledgement']['status'] ?? null;

            //     if ($ack_status) {
            //         switch ($ack_status) {
            //             case 'Ok':
            //                 // Update einvoice status to sent awaiting acknowledgment
            //                 $object->array_options['options_pdpconnectfr_einvoice_status'] = 3;
            //                 $object->insertExtraFields();
            //                 break;
            //             case 'Pending':
            //                 // Keep status as sent awaiting validation
            //                 break;
            //             case 'Error':
            //                 $ack_message = '';
            //                 foreach ($flowData['acknowledgement']['details'] as $detail) {
            //                     $code = $detail['reasonCode'] ?? '';
            //                     $message = $detail['reasonMessage'] ?? '';
            //                     $ack_message .= $code . ' : ';
            //                     $ack_message .= $message . '<br>';
            //                 }

            //                 // Update einvoice status to error and fill error message
            //                 $object->array_options['options_pdpconnectfr_einvoice_status'] = 4;
            //                 $object->array_options['options_pdpconnectfr_einvoice_info'] = $ack_message;
            //                 $object->insertExtraFields();
            //                 break;
            //             default:
            //                 // Unknown status, keep as sent awaiting validation and fill info
            //                 $object->array_options['options_pdpconnectfr_einvoice_info'] = 'Unknown acknowledgement status: ' . $ack_status;
            //                 break;
            //         }

            //     } else {
            //         // No acknowledgement yet, keep status as "Sent awaiting validation"
            //     }
            // } else {
            //     // Unable to retrieve flow details, keep status as "Sent awaiting validation"
            //     dol_syslog(__METHOD__ . " Unable to retrieve flow details for flowId " . $flowId . ", status code: " . $response['status_code'], LOG_WARNING);
            // }

            }

            return $flowId;
        } else {
            $this->errors[] = "Failed to send electronic invoice.";
            return 0;
        }
    }

    /**
     * Send a sample electronic invoice for testing purposes.
     *
     * This function generates a sample invoice and sends it to PDP
     *
     * @return array|string True if the invoice was successfully sent, false otherwise.
     */
    public function sendSampleInvoice()
    {
        $outputLog = array(); // Feedback to display

        // Generate sample invoice

        // TODO
        /* The template invoice must be generated using the initAsSpecimen() and then
            // Call function to create Factur-X document
            require_once __DIR__ . "/protocols/ProtocolManager.class.php";
            require_once __DIR__ . "/pdpconnectfr.class.php";

            $usedProtocols = getDolGlobalString('PDPCONNECTFR_PROTOCOL');
            $ProtocolManager = new ProtocolManager($db);
            $protocol = $ProtocolManager->getprotocol($usedProtocols);

            // Generate E-invoice by calling the method of the Protocol
            // Example by calling FactureXProcol->generateInvoice()
            $result = $protocol->generateInvoice($invoiceObject->id);
        */

        $invoice_path = $this->exchangeProtocol->generateSampleInvoice();
        // invoice_path is something like "/.../documents/pdpconnectfr/temp/02_ZugferdDocumentPdfBuilder_PrintLayout_Merged.pdf"

        if ($invoice_path) {
            $outputLog[] = "Sample invoice generated successfully.";
        }
        $file_info = pathinfo($invoice_path);
        $uuid = $this->generateUuidV4(); // UUID used to correlate logs between Dolibarr and PDP

        // Format PDP resource Url
        $resource = 'flows';
        $urlparams = array(
            'Request-Id' => $uuid,
        );
		$resource .= '?' . http_build_query($urlparams);

        // Extra headers
        $extraHeaders = [
            'Content-Type' => 'multipart/form-data'
        ];

        // Params
        $params = [
            'flowInfo' => json_encode([
                "trackingId" => "INV-2025-001",
                "name" => "Invoice_2025_001",
                "flowSyntax" => "FACTUR-X",
                "flowProfile" => "CIUS",
                "sha256" => hash_file('sha256', $invoice_path)
            ]),
            'file' => new CURLFile($invoice_path, 'application/pdf', basename($invoice_path))
        ];



        $response = $this->callApi("flows", "POSTALREADYFORMATED", $params, $extraHeaders, 'Send Sample Invoice');

        if ($response['status_code'] == 200 || $response['status_code'] == 202) {

            $flowId = $response['response']['flowId'];
            $outputLog[] = "Sample invoice sent successfully.";

            // Try to retrive flow using callback information
            $resource = 'flows/' . $flowId;
            $urlparams = array(
                'docType' => 'Original',
            );
            $resource .= '?' . http_build_query($urlparams);
            $response = $this->callApi(
                $resource,
                "GET",
                false,
                ['Accept' => 'application/octet-stream'],
                'Retrive Sample Invoice'
            );

            if ($response['status_code'] == 200 || $response['status_code'] == 202) {
            	include_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
            	$tmpobject = new Facture($this->db);
                $output_path = getMultidirTemp($tmpobject, 'pdpconnectfr').'/test_retreived_invoice.pdf';

                file_put_contents($output_path, $response['response']);

                $outputLog[] = "Sample invoice retreived successfully.";

                return $outputLog;
            } else {
                $this->errors[] = "Failed to retreive sample invoice.";
                return 0;
            }
        } else {
            $this->errors[] = "Failed to send sample invoice.";
            return 0;
        }
    }

    /**
	 * Call the provider API.
	 *
	 * @param string 						$resource 	    Resource relative URL ('Flows', 'healthcheck' or others)
     * @param string                        $method         HTTP method ('GET', 'POST', etc.)
	 * @param array<string, mixed>|false 	$params 	    Options for the request
     * @param array<string, string>         $extraHeaders   Optional additional headers
     * @param string|null                   $callType       Functional type of the API call for logging purposes (e.g., 'sync_flows', 'send_invoice')
     *
	 * @return array{status_code:int,response:null|string|array<string,mixed>,call_id:null|string}
	 */
	public function callApi($resource, $method, $params = false, $extraHeaders = [], $callType = '')
	{
        global $conf, $user;

        // Validate configuration
        if (!$this->validateConfiguration()) {
            return array('status_code' => 400, 'response' => $this->errors);
        }

        require_once DOL_DOCUMENT_ROOT . '/core/lib/geturl.lib.php';

		$url = $this->getApiUrl() . $resource;

        $httpheader = array(
            'hubtimize-api-key: '. $this->config['api_key']
        );

        if (!isset($extraHeaders['Content-Type'])) {
            $httpheader[] = 'Content-Type: application/json';
            $httpheader[] = 'Accept: application/json';
        }

        foreach ($extraHeaders as $key => $value) {
            $httpheader[] = $key . ': ' . $value;
        }

        // check or get access token
        if ($resource != 'token') {
            if ($this->tokenData['token']) {
                $tokenexpiresat = strtotime($this->tokenData['token_expires_at'] ?? 0);
                if ($tokenexpiresat < dol_now()) {
                    $this->refreshAccessToken(); // This will fill again $this->tokenData['token']
                }
            } else {
                $this->getAccessToken(); // This will fill again $this->tokenData['token']
            }
        }

        // Add Authorization header if we have a token
        if ($this->tokenData['token'] && $resource != 'token') {
            $httpheader[] = 'Authorization: Bearer ' . $this->tokenData['token'];
        }

		/*if ($params) {
			$url .= '?' . http_build_query($params);
		}*/

		$response = getURLContent($url, $method, $params, 1, $httpheader);

		$status_code = $response['http_code'];
		$body = 'Error';

		if ($status_code == 200 || $status_code == 202) {
			$body = $response['content'];
            if (!isset($extraHeaders['Accept'])) { // Json if default format
                $body = json_decode($body, true);
            }
			$returnarray = array(
				'status_code' => $status_code,
				'response' => $body
			);
		} else {
			$returnarray = array(
				'status_code' => $status_code,
				'response' => $body
			);
			if (!empty($response['curl_error_no'])) {
				$returnarray['curl_error_no'] = $response['curl_error_no'];
			}
			if (!empty($response['curl_error_msg'])) {
				$returnarray['curl_error_msg'] = $response['curl_error_msg'];
			}
		}

        // Log the API call if we have the fonctional type
        if (!empty($callType)) { // TODO : Add a parameter in module configuration to enable/disable logging
            $call = new Call($this->db);
            $call->call_id = $call->getNextCallId();
            $call->call_type = $callType ?: '';
            $call->method = $method;
            $call->endpoint = '/' . $resource;
            $call->request_body = is_array($params) ? json_encode($params) : $params;
            $call->response = is_array($returnarray['response']) ? json_encode($returnarray['response']) : $returnarray['response'];
            $call->provider = 'Esalink';
            $call->entity = $conf->entity;
            $call->status = ($returnarray['status_code'] == 200 || $returnarray['status_code'] == 202) ? 1 : 0;

            $result = $call->create($user);
            if ($result > 0) {
                $returnarray['call_id'] = $call->call_id;
            } else {
                dol_syslog(__METHOD__ . " Failed to log API call to Esalink PDP provider", LOG_ERR);
            }
        }

		return $returnarray;
	}

    /**
     * Synchronize flows with EsaLink.
     * @param   int   $syncFromDate     Timestamp from which to start synchronization. If 0, begins from epoch (1970-01-01).
     * @param   int   $limit            Maximum number of flows to synchronize. 0 means no limit.
     *
     * @return 	bool|array{res:int, messages:array<string>} 	True on success, false on failure along with messages.
     */
    public function syncFlows($syncFromDate = 0, $limit = 0)
    {
        global $db, $user;

        $results_messages = array();
        $uuid = $this->generateUuidV4(); // UUID used to correlate logs between Dolibarr and PDP TODO : Store it somewhere

        //self::$PDPCONNECTFR_LAST_IMPORT_KEY = $uuid;
        self::$PDPCONNECTFR_LAST_IMPORT_KEY = dol_print_date(dol_now(), 'dayhourlog');

        $resource = 'flows/search';
        $urlparams = array(
            'Request-Id' => $uuid,
        );
        $resource .= '?' . http_build_query($urlparams);

        // Calculate dateafter
        if ($syncFromDate > 0) {
            $dateafter = $syncFromDate;
        } else {
            $dateafter = dol_mktime(0, 0, 0, 1, 1, 1970, 'gmt');
        }

        // First call to get a total count of flows to sync
        $params = array(
            'where' => array(
            'updatedAfter' => dol_print_date($dateafter, '%Y-%m-%dT%H:%M:%S.000Z', 'gmt')
            )
        );

        dol_syslog(__METHOD__ . " syncFlows start from ".dol_print_date($dateafter, 'standard')." limit ".$limit, LOG_DEBUG);
        dol_syslog(__METHOD__ . " syncFlows start from ".dol_print_date($dateafter, 'standard')." limit ".$limit, LOG_DEBUG, 0, "_pdpconnectfr");

        // If limit is 0, we first need to get the total number of flows to sync because ESALINK set a default limit of 25 if not specified
        if ($limit == 0) {
            $response = $this->callApi($resource, "POST", json_encode($params));

            $totalFlows = 0;
            if ($response['status_code'] != 200) {
                $this->errors[] = "Failed to retrieve flows for synchronization.";
                $results_messages[] = "Failed to retrieve flows for synchronization.";
                return array('res' => 0, 'messages' => $results_messages);
            }

            $totalFlows = $response['response']['total'] ?? 0;
            $limit = $totalFlows;

            if ($limit == 0) {
                dol_syslog(__METHOD__ . " No flows to synchronize.", LOG_DEBUG);
                dol_syslog(__METHOD__ . " No flows to synchronize.", LOG_DEBUG, 0, "_pdpconnectfr");

                $results_messages[] = "No flows to synchronize.";
                return array('res' => 1, 'messages' => $results_messages);
            }

            dol_syslog(__METHOD__ . " Total flows to synchronize: " . $totalFlows, LOG_DEBUG);
            dol_syslog(__METHOD__ . " Total flows to synchronize: " . $totalFlows, LOG_DEBUG, 0, "_pdpconnectfr");
        }


        // Make a call to get all flows
        if ($limit) {
        	$params['limit'] = $limit;
        }
        $response = $this->callApi($resource, "POST", json_encode($params), [], "Synchronization");	// This will also create the Call entry

        if ($response['status_code'] != 200) {
			$this->errors[] = "Failed to retrieve flows for synchronization.";
            $results_messages[] = "Failed to retrieve flows for synchronization.";

            dol_syslog(__METHOD__ . " Failed to retrieve the list of flows for synchronization.", LOG_DEBUG, 0, "_pdpconnectfr");
			return array('res' => 0, 'messages' => $results_messages);
		}

		$totalFlows = $response['response']['total'] ?? 0;
        $limit = $limit > 0 ? min($limit, $totalFlows) : $totalFlows;

        if ($totalFlows == 0) {
            dol_syslog(__METHOD__ . " No flows to synchronize.", LOG_DEBUG);
        	dol_syslog(__METHOD__ . " No flows to synchronize.", LOG_DEBUG, 0, "_pdpconnectfr");

            $results_messages[] = "No flows to synchronize.";
            return array('res' => 1, 'messages' => $results_messages);
        }

		// Since PDP may not return flows in the order we want (by updatedAt ASC), we sort them here
		dol_syslog(__METHOD__ . " Sort the flows per updatedAt", LOG_DEBUG, 0, "_pdpconnectfr");
        usort($response['response']['results'], function ($a, $b) {
			return strtotime($a['updatedAt']) <=> strtotime($b['updatedAt']);
		});

		// Clean aleady processed flows from the list
		$alreadyProcessedFlowIds = [];
		$flowIds = array_column($response['response']['results'], 'flowId');
		$sql = "SELECT flow_id FROM " . MAIN_DB_PREFIX . "pdpconnectfr_document";
		$sql .= " WHERE flow_id IN (" . implode(',', array_map('intval', $flowIds)) . ")";
        $sql .= " AND (flow_type NOT LIKE 'manual%' OR flow_type IS NULL)";        // TODO Replace with $sql .= " AND (flow_type LIKE 'xxxx')";

		$resql = $db->query($sql);
		if ($resql) {
			while ($obj = $db->fetch_object($resql)) {
				$alreadyProcessedFlowIds[$obj->flow_id] = $obj->flow_id;
			}
		} else {
			$this->errors[] = "Failed to retrieve flows already processed among the list of flows received.";
            $results_messages[] = "Failed to retrieve flows already processed among the list of flows received.";

            dol_syslog(__METHOD__ . " Failed to retrieve flows already processed among the list of flows received.", LOG_DEBUG, 0, "_pdpconnectfr");
			return array('res' => 0, 'messages' => $results_messages);
		}

		// Update totalFlows after filtering
		// $totalFlows = count($response['response']['results']); // TODO : VERIFY IF NEEDED
		$error = 0;
		$alreadyExist = 0;
		$syncedFlows = 0;

		// Call ID for logging purposes
		$call_id = $response['call_id'] ?? null;

		$lastsuccessfullSyncronizedFlow = null;
		$i = 0;
		foreach ($response['response']['results'] as $flow) {
			$i++;
			if (in_array($flow['flowId'], $alreadyProcessedFlowIds)) {
				dol_syslog(__METHOD__ . " #".$i." Flow " . $flow['flowId'] . " already processed, discard it.", LOG_DEBUG, 0, "_pdpconnectfr");
				$alreadyExist++;
				continue;
			}

			try {
				// Process flow

				dol_syslog(__METHOD__ . " #".$i." Process flow " . $flow['flowId'], LOG_DEBUG, 0, "_pdpconnectfr");

				$db->begin();

				$res = $this->syncFlow($flow['flowId'], $call_id);

				// If res < 0, rollback
				if ($res['res'] < 0) {
					$db->rollback();
					$results_messages[] = "Failed to synchronize flow " . $flow['flowId'] . ": " . $res['message'];
					$error++;
				}

				// If res == 0, commit but count it as already existed
				if ($res['res'] == 0) {
					$results_messages[] = "Skipped - Exist or already processed flow " . $flow['flowId'] . ": " . $res['message'];
					$alreadyExist++;
					$lastsuccessfullSyncronizedFlow = $flow['flowId'];
					$db->commit();
				}

				// If res == 1, commit and count as synced
				if ($res['res'] > 0) {
					$syncedFlows++;
					$lastsuccessfullSyncronizedFlow = $flow['flowId'];
					$db->commit();
				}
			} catch (Exception $e) {
				$db->rollback();
				$results_messages[] = "Exception occurred while synchronizing flow " . $flow['flowId'] . ": " . $e->getMessage();
				$error++;
			}

			if ($error > 0) {
				$results_messages[] = "Aborting synchronization due to errors.";
				break;
			}
		}


        $res = $error > 0 ? -1 : 1;

        $globalresultmessage = ($res == 1) ? "Synchronization completed successfully." : "Synchronization aborted, last successfull synchronized flow: ".((string) $lastsuccessfullSyncronizedFlow);

		dol_syslog(__METHOD__ . " syncFlows end : ".$globalresultmessage, LOG_DEBUG, 0, "_pdpconnectfr");

		$results_messages[] = $globalresultmessage;
        $results_messages[] = "Total flows to synchronize: ".$totalFlows;
        $results_messages[] = "Batch size: ".$limit;
        $results_messages[] = "Total flows skipped (exist or already processed): ".$alreadyExist;
        $results_messages[] = "Total of new flows synchronized: ".$syncedFlows;

        $processingResult = implode("<br>----------------------<br>", $results_messages);
        $processingResult = "Processing result:<br>" . $processingResult;

        // Save sync recap
        if ($call_id) {
            $sql = "UPDATE " . MAIN_DB_PREFIX . "pdpconnectfr_call";
            $sql .= " SET totalflow = " . ((int) $totalFlows) . ",
                successflow = " . ((int) $syncedFlows) . ",
                skippedflow = " . ((int) $alreadyExist) . ",
                batchlimit = " . ((int) $limit) . ",
                processing_result = '" . $db->escape($processingResult) . "',
                    fk_user_modif = " . ((int) $user->id) . "
            WHERE call_id = '" . $db->escape($call_id) . "'";
        }

        $db->query($sql);

        // Return result
        return array('res' => $res, 'messages' => $results_messages, 'alreadyExist' => $alreadyExist, 'syncedFlows' => $syncedFlows);
    }

    /**
     * sync flow data.
     *
     * @param string $flowId        FlowId
     * @param string|null $call_id  Call ID for logging purposes
     *
     * @return array{res:int, message:string} Returns array with 'res' (1 on success, 0 if exists or already processed, -1 on failure) and 'message'
     */
    public function syncFlow($flowId, $call_id = null)
    {
        global $db, $conf, $user;

        dol_include_once('pdpconnectfr/class/document.class.php');

        // call API to get flow details
        $flowResource = 'flows/' . $flowId;
        $flowUrlparams = array(
            'docType' => 'Metadata', // docType can be 'Metadata', 'Original', 'Converted' or 'ReadableView'
        );
        $flowResource .= '?' . http_build_query($flowUrlparams);
        $flowResponse = $this->callApi(
            $flowResource,
            "GET",
            false,
            ['Accept' => 'application/octet-stream']
        );

        if ($flowResponse['status_code'] != 200) {
            return array('res' => '-1', 'message' => "Failed to retrieve flow details for flowId: " . $flowId);
        }

        // Process flow data
        $flowData = json_decode($flowResponse['response'], true);
        $document = new Document($this->db);
        $document->date_creation        = dol_now();
        $document->fk_user_creat        = $user->id;
        $document->fk_call              = null; // TODO
        $document->flow_id              = $flowId;
        $document->tracking_idref       = $flowData['trackingId'] ?? null;
        $document->flow_type            = $flowData['flowType'] ?? null;
        $document->flow_direction       = $flowData['flowDirection'] ?? null;
        $document->flow_syntax          = $flowData['flowSyntax'] ?? null;
        $document->flow_profile         = $flowData['flowProfile'] ?? null;
        $document->ack_status           = $flowData['acknowledgement']['status'] ?? null;
        // Change this fields to fit with the new api response ===============================================
        $document->ack_reason_code      = $flowData['acknowledgement']['details'][0]['reasonCode'] ?? null;
        $document->ack_info             = $flowData['acknowledgement']['details'][0]['reasonMessage'] ?? null;
        // Change this fields to fit with the new api response ===============================================
        $document->document_body        = null;
        $document->fk_element_id        = null;
        $document->fk_element_type      = null;

        if (!empty($flowData['submittedAt'])) {
            $dt = new DateTimeImmutable($flowData['submittedAt'], new DateTimeZone('UTC'));
            $document->submittedat = $db->idate($dt->getTimestamp());
        } else {
            $document->submittedat = null;
        }
        if (!empty($flowData['updatedAt'])) {
            $dt = new DateTimeImmutable($flowData['updatedAt'], new DateTimeZone('UTC'));
            $document->updatedat = $db->idate($dt->getTimestamp());
        } else {
            $document->updatedat = null;
        }
        $document->provider             = getDolGlobalString('PDPCONNECTFR_PDP') ?? null;
        $document->entity               = $conf->entity;
        $document->flow_uiid            = $flowData['uuid'] ?? null;

        switch ($document->flow_type) {
            // CustomerInvoice
            case "CustomerInvoice":
                // 1. link flow to customer invoice
                require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
                $document->fk_element_type = Facture::class;
                $factureObj = new Facture($this->db);
                $res = $factureObj->fetch(0, $document->tracking_idref);
                if ($res < 0) {
                    return array('res' => '-1', 'message' => "Failed to fetch customer invoice for flowId: " . $flowId);
                }
                $document->fk_element_id = !empty($factureObj->id) ? $factureObj->id : 0;
                $document->tracking_idref = !empty($factureObj->ref) ? $factureObj->ref : $document->tracking_idref.' (NOTFOUND)'; // Probably the customer invoice is sent from another system that use the same PDP account

                // TODO: Consider creating a new customer invoice in this case?
                // TODO: 2. save received converted document as attachment to customer invoice
                break;

            // SupplierInvoice
            case "SupplierInvoice":
                // --- Fetch received documents (FacturX PDF)
                $flowResource = 'flows/' . $flowId;
                $flowUrlparams = array(
                    'docType' => 'Converted', // docType can be 'Metadata', 'Original', 'Converted' or 'ReadableView'
                );
                $flowResource .= '?' . http_build_query($flowUrlparams);
                $flowResponse = $this->callApi(
                    $flowResource,
                    "GET",
                    false,
                    ['Accept' => 'application/octet-stream']
                );

                if ($flowResponse['status_code'] != 200) {
                    return array('res' => -1, 'message' => "Failed to retrieve converted (Original) document for SupplierInvoice flow (flowId: $flowId)");
                }
                $receivedFile = $flowResponse['response'];

                // Retrive also PDF file generated by PDP
                $flowResource = 'flows/' . $flowId;
                $flowUrlparams = array(
                    'docType' => 'ReadableView', // docType can be 'Metadata', 'Original', 'Converted' or 'ReadableView'
                );
                $flowResource .= '?' . http_build_query($flowUrlparams);
                $flowResponse = $this->callApi(
                    $flowResource,
                    "GET",
                    false,
                    ['Accept' => 'application/octet-stream']
                );

                if ($flowResponse['status_code'] != 200) {
                    return array('res' => -1, 'message' => "Failed to retrieve ReadableView document for SupplierInvoice flow (flowId: $flowId)");
                }
                $ReadableViewFile = $flowResponse['response'];


                $res = $this->exchangeProtocol->createSupplierInvoiceFromFacturX($receivedFile, $ReadableViewFile);
                if ($res['res'] < 0) {
                    return array('res' => -1, 'message' => "Failed to create supplier invoice from FacturX document for flowId: " . $flowId . ". " . $res['message']);
                } elseif ($res['res'] == 0) {
                    return array('res' => 0, 'message' => "supplier invoice already exists for flowId: " . $flowId . ". " . $res['message']);
                }
                break;

            // Customer Invoice LC (life cycle)
            case "CustomerInvoiceLC":
                // 1. link flow document to customer invoice
                require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
                $document->fk_element_type = Facture::class;
                $factureObj = new Facture($this->db);
                $res = $factureObj->fetch(0, $document->tracking_idref);
                if ($res < 0) {
                    return array('res' => '-1', 'message' => "Failed to fetch customer invoice for flowId: " . $flowId);
                }
                //$document->fk_element_id = $factureObj->id;

                // 2. Read CDAR and update status of linked customer invoice
                $flowResource = 'flows/' . $flowId;
                $flowUrlparams = array(
                    'docType' => 'Original', // docType can be 'Metadata', 'Original', 'Converted' or 'ReadableView'
                );
                $flowResource .= '?' . http_build_query($flowUrlparams);
                $flowResponse = $this->callApi(
                    $flowResource,
                    "GET",
                    false,
                    ['Accept' => 'application/octet-stream']
                );

                if ($flowResponse['status_code'] != 200) {
                    return array('res' => '-1', 'message' => "Failed to retrieve flow details for flowId: " . $flowId);
                }
                $cdarXml = $flowResponse['response'];

                dol_include_once('pdpconnectfr/class/utils/CdarHandler.class.php');

                $cdarHandler = new CdarHandler();

                try {
                    // Parse the CDAR document (returns an array)
                    $cdarDocument = $cdarHandler->readFromString($cdarXml);

                    // Check if parsing was successful
                    if (empty($cdarDocument) || !isset($cdarDocument['AcknowledgementDocument'])) {
                        return array('res' => '-1', 'message' => "Failed to parse CDAR document for flowId: " . $flowId);
                    }

                    require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';

                    $document->fk_element_type = Facture::class;
                    $factureObj = new Facture($this->db);

                    // Get Invoice Reference from CDAR
                    $issuerAssignedID = $cdarDocument['AcknowledgementDocument']['ReferenceReferencedDocument']['IssuerAssignedID'];

                    $res = $factureObj->fetch(0, $issuerAssignedID);
                    if ($res < 0) {
                        return array(
                            'res' => '-1',
                            'message' => "Failed to fetch customer invoice for flowId: " . $flowId .
                                        " using CDAR tracking ID: " . $issuerAssignedID
                        );
                    }

                    $document->fk_element_id = !empty($factureObj->id) ? $factureObj->id : 0;
                    $document->tracking_idref = !empty($factureObj->ref) ? $factureObj->ref : $issuerAssignedID.' (NOTFOUND)'; // Probably the customer invoice is sent from another system that use the same PDP account
                    // TODO: Consider creating a new customer invoice in this case?

                    // Retrieve reference data
                    $refDoc = $cdarDocument['AcknowledgementDocument']['ReferenceReferencedDocument'];

                    // Fill CDAR information in the document
                    $document->cdar_lifecycle_code = $refDoc['ProcessConditionCode'];
                    $document->cdar_lifecycle_label = $refDoc['ProcessCondition'];
                    $document->cdar_reason_code = isset($refDoc['StatusReasonCode']) ? $refDoc['StatusReasonCode'] : '';
                    $document->cdar_reason_desc = isset($refDoc['StatusReason']) ? $refDoc['StatusReason'] : '';
                    $document->cdar_reason_detail = isset($refDoc['StatusIncludedNoteContent']) ? $refDoc['StatusIncludedNoteContent'] : '';

                    // Update linked customer invoice status based on CDAR information
                    $factureObj->array_options['options_pdpconnectfr_einvoice_status'] = $refDoc['ProcessConditionCode'];
                    if (!$refDoc['ProcessConditionCode'] && $document->ack_status == 'Error') {
                        $factureObj->array_options['options_pdpconnectfr_einvoice_status'] = 3; // Error
                        $factureObj->array_options['pdpconnectfr_einvoice_info'] = $document->ack_info;
                    }
                    $resUpdateStatus = $factureObj->insertExtraFields();
                    if ($resUpdateStatus < 0) {
                        return array(
                            'res' => '-1',
                            'message' => "Failed to update customer E-invoice status for flowId: " . $flowId
                        );
                    }

                    // Log an event in the invoice timeline
                    $statusLabel = $document->cdar_lifecycle_label;
                    $reasonDetail = $document->cdar_reason_detail ? " - {$document->cdar_reason_detail}" : '';


                    $eventLabel = "PDPCONNECTFR - Status: {$statusLabel}";
                    $eventMessage = "PDPCONNECTFR - Status: {$statusLabel}{$reasonDetail}";

                    $resLogEvent = $this->addEvent('STATUS', $eventLabel, $eventMessage, $factureObj);
                    if ($resLogEvent < 0) {
                        dol_syslog(__METHOD__ . " Failed to log event for flowId: {$flowId}", LOG_WARNING);
                    }

                    // Update customer invoice status based on CDAR lifecycle code
                    // Mapping of lifecycle codes to Dolibarr invoice statuses
                    $lifecycleCode = $refDoc['ProcessConditionCode'];

                    switch ($lifecycleCode) {
                        case CdarHandler::PROC_DEPOSITED:  // 200 - Deposited
                        case CdarHandler::PROC_ISSUED:     // 201 - Issued
                            break;

                        case CdarHandler::PROC_RECEIVED:   // 202 - Received
                        case CdarHandler::PROC_AVAILABLE:  // 203 - Available
                            break;

                        case CdarHandler::PROC_TAKEN_OVER: // 204 - Taken over
                            break;

                        case CdarHandler::PROC_APPROVED:   // 205 - Approved
                        case CdarHandler::PROC_PARTIALLY_APPROVED: // 206 - Partially approved
                            break;

                        case CdarHandler::PROC_DISPUTED:   // 207 - Disputed
                        case CdarHandler::PROC_SUSPENDED:  // 208 - Suspended
                            break;

                        case CdarHandler::PROC_COMPLETED:  // 209 - Completed
                            break;

                        case CdarHandler::PROC_REFUSED:    // 210 - Refused
                        case CdarHandler::PROC_REJECTED:   // 213 - Rejected
                            break;

                        case CdarHandler::PROC_PAYMENT_TRANSMITTED: // 211 - Payment transmitted
                            break;

                        case CdarHandler::PROC_PAID:       // 212 - Paid
                            break;

                        default:
                            // Unknown lifecycle code
                            dol_syslog("Unknown CDAR lifecycle code: " . $lifecycleCode, LOG_WARNING);
                            break;
                    }

                } catch (Exception $e) {
                    return array(
                        'res' => '-1',
                        'message' => "Error processing CDAR document for flowId: " . $flowId . " - " . $e->getMessage()
                    );
                }

                break;

            // Supplier Invoice LC (life cycle)
            case "SupplierInvoiceLC":
                $document->document_type = 'INVOICE';
                break;
            case "":
                // This is probably a processing response for an invoice we previously sent, and not a lifecycle message.
                // In this case, the trackingId cannot be used because it is null.
                // To link this response to the client invoice, we try to find the invoice using the flowId
                // stored in the document table when the invoice was sent.

                require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';

                $document->fk_element_type = Facture::class;
                $factureObj = new Facture($this->db);

                $res = $factureObj->fetch(0, $document->tracking_idref);
                if ($res < 0) {
                    // TODO : CHECK WHY tracking_idref is not filled in PDP flow metadata?
                    // Try to get tracking_idref from document table
                    $sql = "SELECT d.tracking_idref";
                    $sql .= " FROM " . MAIN_DB_PREFIX . "pdpconnectfr_document as d";
                    $sql .= " WHERE d.flow_id = '" . $db->escape($flowId) . "'";
                    $resql = $db->query($sql);
                    if ($resql) {
                        $obj = $db->fetch_object($resql);
                        if ($obj && !empty($obj->tracking_idref)) {
                            $res = $factureObj->fetch(0, $obj->tracking_idref);
                            if ($res < 0) {
                                return array('res' => '-1', 'message' => "Failed to fetch customer invoice for flowId: " . $flowId . " using tracking_idref from document table: " . $obj->tracking_idref);
                            } elseif ($res == 0) {
                                return array('res' => '0', 'message' => "Customer invoice with ref " . $obj->tracking_idref . " not found for flowId: " . $flowId); // Should not happen because we save flowid when sending invoice
                            }
                        } else {
                            //return array('res' => '0', 'message' => "No tracking_idref found in document table for flowId: " . $flowId);
                        }
                    } else {
                        return array('res' => '0', 'message' => "Failed to query document table for flowId: " . $flowId);
                    }
                }

                $document->fk_element_id = $factureObj->id;
                $document->tracking_idref = $factureObj->ref;

                if ($document->ack_status == 'Error') {
                    $factureObj->array_options['options_pdpconnectfr_einvoice_status'] = 3; // Error
                    $factureObj->array_options['pdpconnectfr_einvoice_info'] = $document->ack_info;
                    $resUpdateStatus = $factureObj->insertExtraFields();
                    if ($resUpdateStatus < 0) {
                        return array(
                            'res' => '-1',
                            'message' => "Failed to update customer E-invoice status for flowId: " . $flowId
                        );
                    }

                    // Log an event in the invoice timeline
                    $statusLabel = $document->ack_status;
                    $reasonDetail = $document->ack_info ? " - {$document->ack_info}" : '';

                    $eventLabel = "PDPCONNECTFR - Status: {$statusLabel}";
                    $eventMessage = "PDPCONNECTFR - Status: {$statusLabel}{$reasonDetail}";

                    $resLogEvent = $this->addEvent('STATUS', $eventLabel, $eventMessage, $factureObj);
                    if ($resLogEvent < 0) {
                        dol_syslog(__METHOD__ . " Failed to log event for flowId: {$flowId}", LOG_WARNING);
                    }
                }
                break;
        }

        $document->call_id = $call_id;
        $res = $document->create($user);
        if ($res < 0) {
            //print_r($document->errors);
            return array('res' => '-1', 'message' => "Failed to store flow data for flowId: " . $flowId . ". Errors: " . implode(", ", $document->errors));
        }

        return array('res' => '1', 'message' => '');
    }


}
