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
 * \file    pdpconnectfr/class/providers/PDPProviderManager.class.php
 * \ingroup pdpconnectfr
 * \brief   Manage multiple PDP providers and provide a unified access layer.
 */

dol_include_once('/pdpconnectfr/class/providers/EsalinkPDPProvider.class.php');


/**
 * Class to declare all Access Point providers.
 */
class PDPProviderManager
{
    public $db;

    private $providersList;

    /**
     * Initialize available PDP providers.
     */
    public function __construct($db)
    {
        // Access point declaration
        // You can enter entry for a new access point here.

        // TODO May be we can keep only the provider name, country scope, and description in the array of available providers.
        // Rest of data could be into the XXXPDPPRovider.class.php file.
        $this->providersList = array (
            'ESALINK' => array(
            	'provider_countries' => array('FR'),
                'provider_name' => 'ESALINK - Hubtimize',
                'description' => 'Esalink PDP Integration',
                'is_enabled' => 1,
            	'prod_account_admin_url' => 'https://www.esalink.com/contact/',
            	'test_account_admin_url' => 'https://www.esalink.com/contact/',
            ),
            'TESTPDP' => array(
            	'provider_countries' => array('all'),
            	'provider_name' => 'TESTPDP',
                'description' => 'Another TESTPDP Integration',
                'is_enabled' => 0,
            	'prod_account_admin_url' => 'https://example.com',
            	'test_account_admin_url' => 'https://example.com',
            )
        );
    }

    /**
     * Get all registered providers configuration.
     *
     * @return array
     */
    public function getAllProviders()
    {
        return $this->providersList;
    }

    /**
     * Get provider instance by name.
     *
     * @param string $name
     * @return AbstractPDPProvider|null
     */
    public function getProvider($name)
    {
        global $db;
        // Check if provider exists and is enabled in providersList
        if (!isset($this->providersList[$name]) || !$this->providersList[$name]['is_enabled']) {
            return null;
        }

        // Initialize provider based on name
        switch ($name) {
            case 'ESALINK':
                $provider = new EsalinkPDPProvider($db);
                $provider->providerName = 'ESALINK';
                break;
            case 'TESTPDP':
                //$provider = new TESTPDPProvider();
                break;
            default:
                return null;
        }
        return $provider;
    }

}
