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

        $response = $this->callApi("healthcheck", "GET");

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



        $response = $this->callApi("flows", "POSTALREADYFORMATED", $params, $extraHeaders);

        if ($response['status_code'] == 200 || $response['status_code'] == 202) {
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

        $exchangeProtocolConf = getDolGlobalString('PDPCONNECTFR_PROTOCOL');
        $ProtocolManager = new ProtocolManager($this->db);
        $this->exchangeProtocol = $ProtocolManager->getprotocol($exchangeProtocolConf);

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
                "flowSyntax" => "FACTUR_X",
                "flowProfile" => "CIUS",
                "sha256" => hash_file('sha256', $invoice_path)
            ]),
            'file' => new CURLFile($invoice_path, 'application/pdf', basename($invoice_path))
        ];



        $response = $this->callApi("flows", "POSTALREADYFORMATED", $params, $extraHeaders);

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
                ['Accept' => 'application/octet-stream']
            );

            if ($response['status_code'] == 200 || $response['status_code'] == 202) {
                $output_path = __DIR__ . '/../../assets/retrived_invoice.pdf';
                file_put_contents($output_path, $response['response']);

                $outputLog[] = "Sample invoice retrived successfully.";

                return $outputLog;
            } else {
                $this->errors[] = "Failed to retrive sample invoice.";
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
	 * @return array{status_code:int,response:null|string|array<string,mixed>}
	 */
	public function callApi($resource, $method, $params = false, $extraHeaders = [])
	{
        // Validate configuration
        if (!$this->validateConfiguration()) {
            return array('status_code' => 400, 'response' => $this->errors);
        }

        require_once DOL_DOCUMENT_ROOT . '/core/lib/geturl.lib.php';
        //$otherCurlOptions = [];

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

		return $returnarray;
	}

    /**
     * Synchronize flows with EsaLink since the last synchronization date.
     *
     * @return bool|array{res:int, messages:array<string>} True on success, false on failure along with messages.
     */
    public function syncFlows()
    {
        $results_messages = array();
        $uuid = $this->generateUuidV4(); // UUID used to correlate logs between Dolibarr and PDP TODO : Store it somewhere

        $resource = 'flows/search';
        $urlparams = array(
            'Request-Id' => $uuid,
        );
            $resource .= '?' . http_build_query($urlparams);

        // First call to get a total count of flows to sync
        $params = array(
            'limit' => 1,
            'where' => array(
            'updatedAfter' => dol_print_date($this->getLastSyncDate(), '%Y-%m-%dT%H:%M:%S.000Z', 'gmt')
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

        if ($totalFlows == 0) {
            dol_syslog(__METHOD__ . " No flows to synchronize.", LOG_DEBUG);
            $results_messages[] = "No flows to synchronize.";
            return array('res' => 1, 'messages' => $results_messages);
        } else {
            dol_syslog(__METHOD__ . " Total flows to synchronize: " . $totalFlows, LOG_DEBUG);
            // Make a second call to get all flows
            $params['limit'] = $totalFlows;
            $response = $this->callApi($resource, "POST", json_encode($params));

            if ($response['status_code'] != 200) {
                $this->errors[] = "Failed to retrieve flows for synchronization.";
                $results_messages[] = "Failed to retrieve flows for synchronization.";
                return array('res' => 0, 'messages' => $results_messages);
            }

            $documents = array();
            $cdars = array();
            foreach ($response['response']['results'] as $flow) {
                switch ($flow["flowSyntax"]) {
                    case "CDAR":
                        $cdars[] = $flow;
                        break;
                    case "FACTUR-X":
                        $documents[] = $flow;
                        break;
                    default:
                        $documents[] = $flow;
                        break;
                }
            }

            // Process documents first
            foreach ($documents as $flow) {
                $res = $this->syncFlow($flow['flowId']);
                if ($res['res'] != '1') {
                    $results_messages[] = "Failed to synchronize flow " . $flow['flowId'] . ": " . $res['message'];
                }
            }

            // Then process CDARs
            foreach ($cdars as $flow) {
                $res = $this->syncFlow($flow['flowId']);
                if ($res['res'] != '1') {
                    $results_messages[] = "Failed to synchronize flow " . $flow['flowId'] . ": " . $res['message'];
                }
            }
        }

        $results_messages[] = "Total flows to synchronize: " . $totalFlows;
        return array('res' => 1, 'messages' => $results_messages);
    }

    /**
     * sync flow data.
     *
     * @param string $flowId        FlowId
     * @return array{res:int, message:string} Returns array with 'res' (1 on success, -1 on failure) and 'message' if error
     */
    public function syncFlow($flowId)
    {
        global $conf, $user;
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
        $document->flow_type            = $flowData['type'] ?? null;
        $document->flow_direction       = $flowData['direction'] ?? null;
        $document->flow_syntax          = $flowData['syntax'] ?? null;
        $document->flow_profile         = $flowData['profile'] ?? null;
        $document->ack_status           = $flowData['acknowledgement']['status'] ?? null;
        $document->ack_reason_code      = $flowData['acknowledgement']['reasonCode'] ?? null;
        $document->ack_info             = $flowData['acknowledgement']['additionalInformation'] ?? null;
        $document->document_body        = null;
        $document->fk_element_id        = null;
        $document->fk_element_type      = null;
        $document->submittedat          = $flowData['createDate'] ?? null;
        $document->updatedat            = $flowData['updateDate'] ?? null;
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
                // 2. save received document
                // TODO
                break;

            // SupplierInvoice
            case "SupplierInvoice":
                // 2. link flow document to supplier invoice
                // 3. save received documents (original and xml)
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

                dol_include_once('custom/pdpconnectfr/class/utils/cdar/CdarManager.class.php');
                $cdarManager = new CdarManager();
                $cdarDocument = $cdarManager->readFromString($cdarXml);
                if ($cdarDocument === null) {
                    return array('res' => '-1', 'message' => "Failed to parse CDAR document for flowId: " . $flowId);
                }

                require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
                $document->fk_element_type = Facture::class;
                $factureObj = new Facture($this->db);
                $res = $factureObj->fetch(0, $cdarDocument->AcknowledgementDocument->ReferenceReferencedDocument->IssuerAssignedID);
                if ($res < 0) {
                    return array('res' => '-1', 'message' => "Failed to fetch customer invoice for flowId: " . $flowId . " using CDAR tracking ID: " . $cdarDocument->AcknowledgementDocument->ReferenceReferencedDocument->IssuerAssignedID);
                }
                $document->fk_element_id = $factureObj->id;

                // Fill CDAR information in document
                $document->cdar_lifecycle_code = $cdarDocument->AcknowledgementDocument->ReferenceReferencedDocument->ProcessConditionCode->value;
                $document->cdar_lifecycle_label = $cdarDocument->AcknowledgementDocument->ReferenceReferencedDocument->ProcessCondition;
                $document->cdar_reason_code = $cdarDocument->AcknowledgementDocument->ReferenceReferencedDocument->StatusReasonCode;
                $document->cdar_reason_desc = $cdarDocument->AcknowledgementDocument->ReferenceReferencedDocument->StatusReason;
                $document->cdar_reason_detail = $cdarDocument->AcknowledgementDocument->ReferenceReferencedDocument->StatusIncludedNoteContent;

                // Update customer invoice status based on CDAR lifecycle code
                // TODO: Map lifecycle codes to Dolibarr invoice statuses

                break;

            // Supplier Invoice LC (life cycle)
            case "SupplierInvoiceLC":
                $document->document_type = 'INVOICE';
                break;
        }

        $res = $document->create($user);
        if ($res < 0) {
            return array('res' => '-1', 'message' => "Failed to store flow data for flowId: " . $flowId);
        }

        return array('res' => '1', 'message' => '');
    }


}
