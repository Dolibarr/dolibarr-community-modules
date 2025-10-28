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

require_once DOL_DOCUMENT_ROOT . '/custom/pdpconnectfr/class/providers/AbstractPDPProvider.class.php';

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
        $this->tokenData = $this->fetchOAuthTokenDB ();
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
                "flowSyntax" => "FACTUR_X",
                "flowProfile" => "Basic",
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
                $tokenexpiresat = $this->tokenData['token_expires_at'] ?? 0;
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

}
