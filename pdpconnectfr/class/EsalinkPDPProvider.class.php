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
 * \file    pdpconnectfr/class/EsalinkPDPProvider.class.php
 * \ingroup pdpconnectfr
 * \brief   Esalink PDP provider integration class
 */

require_once DOL_DOCUMENT_ROOT . '/custom/pdpconnectfr/class/AbstractPDPProvider.class.php';

/**
 * Class to manage Esalink PDP provider integration.
 */
class EsalinkPDPProvider extends AbstractPDPProvider
{
    /**
     * Constructor
     *
     * @param array $config Configuration array
     */
    public function __construct($config = []) {
        $this->config = $config;
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
            // Save tokens in Dolibarr constants
            dolibarr_set_const($db, $this->config['dol_prefix'] . 'TOKEN', $body['access_token'], 'chaine', 0, '', $conf->entity);
            dolibarr_set_const($db, $this->config['dol_prefix'] . 'REFRESH_TOKEN', $body['refresh_token'], 'chaine', 0, '', $conf->entity);
            dolibarr_set_const($db, $this->config['dol_prefix'] . 'TOKEN_EXPIRES_AT', dol_now() + $body['expires_in'], 'chaine', 0, '', $conf->entity);

            // Update config array
            $this->config['token'] = $body['access_token'];
            $this->config['token_expires_at'] = dol_now() + $body['expires_in'];
            $this->config['refresh_token'] = $body['refresh_token'];

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
	 * Call the provider API.
	 *
	 * @param string 						$resource 	Resource relative URL ('Flows', 'healthcheck' or others)
     * @param string                        $method     HTTP method ('GET', 'POST', etc.)
	 * @param array<string, mixed>|false 	$params 	Options for the request
	 * @return array{status_code:int,response:null|string|array<string,mixed>}
	 */
	public function callApi($resource, $method, $params = false)
	{
        // Validate configuration
        if (!$this->validateConfiguration()) {
            return array('status_code' => 400, 'response' => $this->errors);
        }

        require_once DOL_DOCUMENT_ROOT . '/core/lib/geturl.lib.php';
        //$otherCurlOptions = [];

		$url = $this->getApiUrl() . $resource;

        $httpheader = array(
            'hubtimize-api-key: '. $this->config['api_key'],
            'Content-Type: application/json',
            'Accept: application/json'
        );

        // check or get access token
        if ($resource != 'token') {
            if ($this->config['token']) {
                $tokenexpiresat = $this->config['token_expires_at'] ?? 0;
                if ($tokenexpiresat < dol_now()) {
                    $this->refreshAccessToken(); // This will fill again $this->config['token']
                }
            } else {
                $this->getAccessToken(); // This will fill again $this->config['token']
            }
        }

        // Add Authorization header if we have a token
        if ($this->config['token'] && $resource != 'token') {
            $httpheader[] = 'Authorization: Bearer ' . $this->config['token'];
        }

		/*if ($params) {
			$url .= '?' . http_build_query($params);
		}*/

		$response = getURLContent($url, $method, $params, 1, $httpheader);

		$status_code = $response['http_code'];
		$body = 'Error';

		if ($status_code == 200) {
			$body = $response['content'];
			$body = json_decode($body, true);
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
