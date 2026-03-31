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



/**
 * Class to declare all Access Point providers.
 */
class PDPProviderManager
{
	/**
	 * @var DoliDB db
	 */
	public $db;

	/**
	 * @var mixed provider list
	 */
	private $providersList;

	/**
	 * Initialize available PDP providers.
	 * @param DoliDB $db db
	 */
	public function __construct($db)
	{
		// Access point declaration
		// You can enter entry for a new access point here.
		global $langs;
		global $dolibarr_main_url_root;

		// Define $urlwithroot
		$urlwithouturlroot = preg_replace('/'.preg_quote(DOL_URL_ROOT, '/').'$/i', '', trim($dolibarr_main_url_root));
		$urlwithroot = $urlwithouturlroot.DOL_URL_ROOT; // This is to use external domain name found into config file
		//$urlwithroot=DOL_MAIN_URL_ROOT;					// This is to use same domain name than current


		// TODO May be we can keep only the provider name, country scope, and description in the array of available providers.
		// Rest of data could be into the XXXPDPPRovider.class.php file.
		$this->providersList = array (
			'ESALINK' => array(
				'class' => 'EsalinkPDPProvider',
				'position' => 10,
				'provider_countries' => array('FR'),
				'provider_name' => 'ESALINK - Hubtimize',
				'description' => 'Esalink PDP Integration',
				'is_enabled' => 1,
				'prod_account_admin_url' => 'https://www.esalink.com/contact/',
				'test_account_admin_url' => 'https://www.esalink.com/contact/',
			),
			'SUPERPDP' => array(
				'class' => 'SuperPDPProvider',
				'position' => getDolGlobalString('PDPCONNTECTFR_SUPERPDP_VIAPARTNER') ? 2: 20,
				'provider_countries' => array('all'),
				'provider_name' => 'SuperPDP'.(getDolGlobalString('PDPCONNTECTFR_SUPERPDP_VIAPARTNER') ? ' <span class="opacitymedium">('.$langs->trans("UsingYourOwnBillingAccount").")</span>" : ""),
				'description' => 'SuperPDP Integration',
				'note' => 'Use "client_credentials" mode',
				//'is_enabled' => getDolGlobalString('PDPCONNECTFR_TEST_SUPERPDP'),
				'is_enabled' => 1,
				'prod_account_admin_url' => 'https://www.superpdp.tech/app/users/create',
				'test_account_admin_url' => 'https://www.superpdp.tech/app/users/create',
			),
			'TESTPDP' => array(
				'class' => 'TestPDPProvider',
				'position' => 100,
				'provider_countries' => array('all'),
				'provider_name' => 'TESTPDP',
				'description' => 'Another TESTPDP Integration',
				'is_enabled' => 0,
				'prod_account_admin_url' => 'https://example.com',
				'test_account_admin_url' => 'https://example.com',
			)
		);

		// Add entry to use SuperPDP via OAuth delegation.
		if (getDolGlobalString('PDPCONNTECTFR_SUPERPDP_VIAPARTNER')) {
			$urltorenew = $urlwithroot.'/core/modules/oauth/generic_oauthcallback.php';	// This one is the one used for test when native using Oauth module.
			if (getDolGlobalString('PDPCONNTECTFR_SUPERPDP_VIAPARTNER_OAUTH_URL')) {	// This one is to use your own redirect URI knowing its ownn client id/secret
				$shortscope = 'none';
				$state = 'none';

				$redirecturl = getDolGlobalString('PDPCONNTECTFR_SUPERPDP_VIAPARTNER_OAUTH_URL');

				$urltorenew = $redirecturl;
				$urltodelete = $redirecturl;
			}

			$urltorenew = $urltorenew.'?shortscope='.urlencode($shortscope).'&state='.urlencode($state).'&backtourl='.urlencode(DOL_URL_ROOT.'/admin/oauthlogintokens.php');
			$urltodelete = $urltodelete.'?action=delete&token='.newToken().'&backtourl='.urlencode(DOL_URL_ROOT.'/admin/oauthlogintokens.php');

			$this->providersList['SUPERPDPViaPartner'] = array(
				'class' => 'SuperPDPProvider',
				'position' => 1,
				'provider_countries' => array('all'),
				'provider_name' => 'SuperPDP  <span class="opacitymedium">(Free and easy setup via '.getDolGlobalString('PDPCONNTECTFR_SUPERPDP_VIAPARTNER').' - '.$langs->trans("Recommended").')</span>',
				'description' => 'SuperPDP Integration',
				'note' => 'Use "authorization_code" mode',
				//'is_enabled' => getDolGlobalString('PDPCONNECTFR_TEST_SUPERPDP'),
				'is_enabled' => 1,
				'prod_account_admin_url' => $urltorenew,
				'test_account_admin_url' => $urltorenew,
			);
		}

		// Sort list by position
		$this->providersList = dol_sort_array($this->providersList, 'position');
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
	 * @param string $name name
	 * @return AbstractPDPProvider|null
	 */
	public function getProvider($name)
	{
		global $db;
		// Check if provider exists and is enabled in providersList
		if (!isset($this->providersList[$name]) || !$this->providersList[$name]['is_enabled']) {
			return null;
		}


		$classnametouse = $this->providersList[$name]['class'];

		$resultinclude = dol_include_once('/pdpconnectfr/class/providers/'.$classnametouse.'.class.php');

		if (!$resultinclude) {
			dol_syslog("Failed to include provider class file for provider: ".$name, LOG_ERR);
			return null;
		}
		if (!class_exists($classnametouse)) {
			dol_syslog("Include provider class was done, but class is still not found: ".$classnametouse, LOG_ERR);
			return null;
		}
		$provider = new $classnametouse($db);
		if ($provider) {
			$provider->providerName = $name;
		}

		return $provider;
	}
}
