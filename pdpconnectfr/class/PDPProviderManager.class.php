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
 * \file    pdpconnectfr/class/PDPProviderManager.class.php
 * \ingroup pdpconnectfr
 * \brief   Manage multiple PDP providers and provide a unified access layer.
 */


require_once DOL_DOCUMENT_ROOT . '/custom/pdpconnectfr/class/EsalinkPDPProvider.class.php';

class PDPProviderManager
{
    public $db;

    private $providersConfig;

    /** @var AbstractPDPProvider[] */
    private $providers = [];

    /**
     * Initialize available PDP providers.
     */
    public function __construct($db)
    {
        // Esalink Provider configuration
        // May be we can keep only the provider name and description in the array of available providers.
        // Rest of data could be into the XXXPDPPRovider.class.php file.
        $this->providersConfig = array (
            'ESALINK' => array(
                'provider_name' => 'ESALINK',
                'description' => 'Esalink PDP Integration',
                'provider_url' => 'https://ppd.hubtimize.fr',
                'prod_api_url' => 'https://ppd.hubtimize.fr/api/orchestrator/v1/', // TODO: Replace the URL once known
                'test_api_url' => 'https://ppd.hubtimize.fr/api/orchestrator/v1/',
                'username' => getDolGlobalString('PDPCONNECTFR_ESALINK_USERNAME', ''),
                'password' => getDolGlobalString('PDPCONNECTFR_ESALINK_PASSWORD', ''),
                'api_key' => getDolGlobalString('PDPCONNECTFR_ESALINK_API_KEY', ''),
                'api_secret' => getDolGlobalString('PDPCONNECTFR_ESALINK_API_SECRET', ''),
                'token' => getDolGlobalString('PDPCONNECTFR_ESALINK_TOKEN', ''),
                'refresh_token' => getDolGlobalString('PDPCONNECTFR_ESALINK_REFRESH_TOKEN', ''),
                'token_expires_at' => getDolGlobalString('PDPCONNECTFR_ESALINK_TOKEN_EXPIRES_AT', ''),
                'dol_prefix' => 'PDPCONNECTFR_ESALINK',
                'is_enabled' => 1
            ),
            'TESTPDP' => array(
                'provider_name' => 'TESTPDP',
                'description' => 'Another TESTPDP Integration',
                'provider_url' => 'https://www.example.com',
                'prod_api_url' => 'https://api.example.com/v1/',
                'test_api_url' => 'https://sandbox.api.example.com/v1/',
                'username' => getDolGlobalString('PDPCONNECTFR_TESTPDP_USERNAME', ''),
                'password' => getDolGlobalString('PDPCONNECTFR_TESTPDP_PASSWORD', ''),
                'api_key' => getDolGlobalString('PDPCONNECTFR_TESTPDP_API_KEY', ''),
                'api_secret' => getDolGlobalString('PDPCONNECTFR_TESTPDP_API_SECRET', ''),
                'token' => getDolGlobalString('PDPCONNECTFR_TESTPDP_TOKEN', ''),
                'refresh_token' => getDolGlobalString('PDPCONNECTFR_TESTPDP_REFRESH_TOKEN', ''),
                'token_expires_at' => getDolGlobalString('PDPCONNECTFR_TESTPDP_TOKEN_EXPIRES_AT', ''),
                'dol_prefix' => 'PDPCONNECTFR_TESTPDP',
                'is_enabled' => 0
            )
        );

        $this->providers['ESALINK'] = new EsalinkPDPProvider($this->providersConfig['ESALINK']);
        //$this->providers['TESTPDP'] = new TESTPDPProvider($providersConfig['TESTPDP']);
    }

    /**
     * Get all registered providers configuration.
     *
     * @return array
     */
    public function getAllProviders()
    {
        return $this->providersConfig;
    }

    /**
     * Get provider instance by name.
     *
     * @param string $name
     * @return AbstractPDPProvider|null
     */
    public function getProvider($name)
    {
        return $this->providers[$name] ?? null;
    }

}
