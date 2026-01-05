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

dol_include_once('custom/pdpconnectfr/class/providers/AbstractPDPProvider.class.php');
dol_include_once('custom/pdpconnectfr/class/protocols/ProtocolManager.class.php');
dol_include_once('custom/pdpconnectfr/class/call.class.php');
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
        global $conf;

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
            // Update einvoice status
            $object->array_options['options_pdpconnectfr_einvoice_status'] = 2;
            $object->insertExtraFields();
            $flowId = $response['response']['flowId'];
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
        $invoice_path = $this->exchangeProtocol->generateSampleInvoice();
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
     * Synchronize flows with EsaLink since the last synchronization date.
     *
     * @param int $limit Maximum number of flows to synchronize. 0 means no limit.
     *
     * @return bool|array{res:int, messages:array<string>} True on success, false on failure along with messages.
     */
    public function syncFlows($limit = 0)
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

        // First call to get a total count of flows to sync
        $params = array(
            'limit' => 1,
            'where' => array(
            'updatedAfter' => dol_print_date($this->getLastSyncDate(getDolGlobalInt('PDPCONNECTFR_SYNC_MARGIN_TIME_HOURS')), '%Y-%m-%dT%H:%M:%S.000Z', 'gmt')
            )
        );
        $response = $this->callApi($resource, "POST", json_encode($params));

        $totalFlows = 0;
        if ($response['status_code'] != 200) {
            $this->errors[] = "Failed to retrieve flows for synchronization.";
            $results_messages[] = "Failed to retrieve flows for synchronization.";
            return array('res' => 0, 'messages' => $results_messages);
        }

        $totalFlows = $response['response']['total'] ?? 0;
        $limit = $limit > 0 ? min($limit, $totalFlows) : $totalFlows;

        if ($totalFlows == 0) {
            dol_syslog(__METHOD__ . " No flows to synchronize.", LOG_DEBUG);
            $results_messages[] = "No flows to synchronize.";
            return array('res' => 1, 'messages' => $results_messages);
        } else {
            dol_syslog(__METHOD__ . " Total flows to synchronize: " . $totalFlows, LOG_DEBUG);
            // Make a second call to get all flows
            $params['limit'] = $limit;
            $response = $this->callApi($resource, "POST", json_encode($params), [], "Synchronization");

            if ($response['status_code'] != 200) {
                $this->errors[] = "Failed to retrieve flows for synchronization.";
                $results_messages[] = "Failed to retrieve flows for synchronization.";
                return array('res' => 0, 'messages' => $results_messages);
            }

            // Since PDP may not return flows in the order we want (by updatedAt ASC), we sort them here
            usort($response['response']['results'], function ($a, $b) {
                return strtotime($a['updatedAt']) <=> strtotime($b['updatedAt']);
            });

            // Clean aleady processed flows from the list
            $alreadyProcessedFlowIds = [];
            $flowIds = array_column($response['response']['results'], 'flowId');
            $sql = "SELECT flow_id FROM " . MAIN_DB_PREFIX . "pdpconnectfr_document WHERE flow_id IN (" . implode(',', array_map('intval', $flowIds)) . ")";
            $resql = $db->query($sql);
            if ($resql) {
                while ($obj = $db->fetch_object($resql)) {
                    $alreadyProcessedFlowIds[] = $obj->flow_id;
                }
            }

            // Already processed flows
            $alreadyProcessedFlows = array_filter(
                $response['response']['results'],
                fn($flow) => in_array($flow['flowId'], $alreadyProcessedFlowIds)
            );

            // Clean the results to process only new flows
            $response['response']['results'] = array_filter(
                $response['response']['results'],
                fn($flow) => !in_array($flow['flowId'], $alreadyProcessedFlowIds)
            );

            // Update totalFlows after filtering
            //$totalFlows = count($response['response']['results']); // TODO : VERIFY IF NEEDED
            $errors = 0;
            $alreadyExist = count($alreadyProcessedFlows);
            $syncedFlows = 0;

            // Call ID for logging purposes
            $call_id = $response['call_id'] ?? null;

            $lastsuccessfullSyncronizedFlow = null;
            foreach ($response['response']['results'] as $flow) {

                try {
                    $db->begin();
                    $res = $this->syncFlow($flow['flowId'], $call_id);

                    // If res < 0, rollback
                    if ($res['res'] < 0) {
                        $db->rollback();
                        $results_messages[] = "Failed to synchronize flow " . $flow['flowId'] . ": " . $res['message'];
                        $errors++;
                    }

                    // If res == 0, commit but count as already existed
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
                    $errors++;
                }

                if ($errors > 0) {
                    $results_messages[] = "Aborting synchronization due to errors.";
                    break;
                }
            }
        }

        $res = $errors > 0 ? -1 : 1;

        $results_messages[] = ($res == 1) ? "Synchronization completed successfully." : "Synchronization aborted, last successfull synchronized flow: {$lastsuccessfullSyncronizedFlow}";
        $results_messages[] = "Total flows to synchronize: {$totalFlows}";
        $results_messages[] = "Batch size: {$limit}";
        $results_messages[] = "Total flows synchronized: {$syncedFlows}";
        $results_messages[] = "Total flows skipped (exist or already processed): {$alreadyExist}";

        $processingResult = implode("<br>----------------------<br>", $results_messages);
        $processingResult = "Processing result:<br>" . $processingResult;

        // Save sync recap
        $sql = "UPDATE " . MAIN_DB_PREFIX . "pdpconnectfr_call
        SET totalflow = " . intval($totalFlows) . ",
            successflow = " . intval($syncedFlows) . ",
            skippedflow = " . intval($alreadyExist) . ",
            batchlimit = " . intval($limit) . ",
            processing_result = '" . $db->escape($processingResult) . "'
        WHERE call_id = '" . $db->escape($call_id) . "'";
        $db->query($sql);

        // Return result
        return array('res' => $res, 'messages' => $results_messages);
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
        dol_include_once('custom/pdpconnectfr/class/document.class.php');

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
                $document->fk_element_id = $factureObj->id;
                // 2. save received converted document
                // TODO
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
                $document->fk_element_id = $factureObj->id;

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

                dol_include_once('custom/pdpconnectfr/class/utils/CdarHandler.class.php');

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

                    $document->fk_element_id = $factureObj->id;

                    // Retrieve reference data
                    $refDoc = $cdarDocument['AcknowledgementDocument']['ReferenceReferencedDocument'];

                    // Fill CDAR information in the document
                    $document->cdar_lifecycle_code = $refDoc['ProcessConditionCode'];
                    $document->cdar_lifecycle_label = $refDoc['ProcessCondition'];
                    $document->cdar_reason_code = isset($refDoc['StatusReasonCode']) ? $refDoc['StatusReasonCode'] : '';
                    $document->cdar_reason_desc = isset($refDoc['StatusReason']) ? $refDoc['StatusReason'] : '';
                    $document->cdar_reason_detail = isset($refDoc['StatusIncludedNoteContent']) ? $refDoc['StatusIncludedNoteContent'] : '';

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
