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

    /**
     * Constructor
     *
     * @param DoliDB $db Database handler
     */
    public function __construct($config)
    {
        $this->config = $config;
    }

    /**
     * Validate configuration parameters before API calls.
     *
     * @return bool True if configuration is valid.
     */
    abstract public function validateConfiguration();

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
	 * Call the provider API.
	 *
	 * @param string 						$resource 	Resource relative URL ('Flows', 'healthcheck' or others)
     * @param string                        $method     HTTP method ('GET', 'POST', etc.)
	 * @param array<string, mixed>|false 	$options 	Options for the request
	 * @return array{status_code:int,response:null|string|array<string,mixed>}
	 */
    abstract public function callApi($resource, $method, $options = false);

}