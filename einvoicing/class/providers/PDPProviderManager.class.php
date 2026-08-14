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
 * \file    einvoicing/class/providers/PDPProviderManager.class.php
 * \ingroup einvoicing
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
	 * @var array<string,array{class:string,classpath?:string,position:int,provider_countries:string[],provider_name:string,description:string,is_enabled:int<0,1>,prod_account_admin_url:?string,test_account_admin_url:?string}>	Provider list
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
		global $langs, $mysoc;
		global $dolibarr_main_url_root;

		// Define $urlwithroot
		$urlwithouturlroot = preg_replace('/'.preg_quote(DOL_URL_ROOT, '/').'$/i', '', trim($dolibarr_main_url_root));
		$urlwithroot = $urlwithouturlroot.DOL_URL_ROOT; // This is to use external domain name found into config file
		//$urlwithroot=DOL_MAIN_URL_ROOT;					// This is to use same domain name than current
		$urltorenew = null;
		$urltodelete = null;
		$state = '';
		$shortscope = '';


		// TODO May be we can keep only the provider name, country scope, and description in the array of available providers.
		// Rest of data could be into the XXXPDPPRovider.class.php file.
		$this->providersList = array(
			'ESALINK' => array(
				'class' => 'EsalinkPDPProvider',
				'position' => 10,
				'provider_countries' => array('FR'),
				'provider_name' => picto_from_langcode('FR', 'style="width: 16px"').' ESALINK <span class="opacitymedium">('.$langs->trans("NeedASubscriptionTo", "PDPLibre").')</span>',
				'description' => 'Esalink PDP Integration',
				'is_enabled' => 1,
				'prod_account_admin_url' => 'https://pdplibre.org/solutions/',
				'test_account_admin_url' => 'https://pdplibre.org/solutions/',
			),
			'SUPERPDP' => array(
				'class' => 'SuperPDPProvider',
				'position' => getDolGlobalString('EINVOICING_SUPERPDP_VIAPARTNER') ? 2 : 20,
				'provider_countries' => array('all'),
				'provider_name' => picto_from_langcode('FR', 'style="width: 16px"').' SuperPDP'.(getDolGlobalString('EINVOICING_SUPERPDP_VIAPARTNER') ? ' <span class="opacitymedium">('.$langs->trans("UsingYourOwnBillingAccount").")</span>" : ""),
				'description' => 'SuperPDP Integration',
				'note' => 'Use "client_credentials" mode',
				//'is_enabled' => getDolGlobalString('EINVOICING_TEST_SUPERPDP'),
				'is_enabled' => 1,
				'prod_account_admin_url' => 'https://www.superpdp.tech/app/users/create',
				'test_account_admin_url' => 'https://www.superpdp.tech/app/users/create',
			)
		);

		// An implementation that only generate documents (no network access). It talks to no platform. This can be used by some countries like Germany or user that push files to a platformmanually.
		if ($mysoc->country_code != 'FR' || getDolGlobalString('EINVOICING_ALLOW_DEVTOOLS')) {
			$this->providersList['TESTPDP'] = array(
				'class' => 'TestPDPProvider',
				'position' => 100,
				'provider_countries' => array('all'),
				'provider_name' => img_picto('', 'generic', 'style="width: 16px"').' None <span class="opacitymedium">(Einvoice generation only, no send/receive)</span>',
				'description' => 'EInvoice generation only',
				'is_enabled' => 1,
				'prod_account_admin_url' => '',
				'test_account_admin_url' => '',
			);
		}

		// Add entry to use SuperPDP via OAuth delegation.
		if (getDolGlobalString('EINVOICING_SUPERPDP_VIAPARTNER')) {
			$urltorenew = $urlwithroot.'/core/modules/oauth/generic_oauthcallback.php';	// This one is the one used for test when native using Oauth module.
			if (getDolGlobalString('EINVOICING_SUPERPDP_VIAPARTNER_OAUTH_URL')) {	// This one is to use your own redirect URI knowing its ownn client id/secret
				$shortscope = 'none';
				$state = 'none';

				$redirecturl = getDolGlobalString('EINVOICING_SUPERPDP_VIAPARTNER_OAUTH_URL');

				$urltorenew = $redirecturl;
				$urltodelete = $redirecturl;
			}

			$urltorenew = $urltorenew.'?shortscope='.urlencode($shortscope).'&state='.urlencode($state).'&backtourl='.urlencode(DOL_URL_ROOT.'/admin/oauthlogintokens.php');
			$urltodelete = $urltodelete.'?action=delete&token='.newToken().'&backtourl='.urlencode(DOL_URL_ROOT.'/admin/oauthlogintokens.php');

			$this->providersList['SUPERPDPViaPartner'] = array(
				'class' => 'SuperPDPProvider',
				'position' => 1,
				'provider_countries' => array('all'),
				'provider_name' => picto_from_langcode('FR').' SuperPDP  <span class="opacitymedium">('.$langs->trans("FreeAndEasySetupVia", getDolGlobalString('EINVOICING_SUPERPDP_VIAPARTNER')).' - '.$langs->trans("Recommended").')</span>',
				'description' => 'SuperPDP Integration',
				'note' => 'Use "authorization_code" mode',
				//'is_enabled' => getDolGlobalString('EINVOICING_TEST_SUPERPDP'),
				'is_enabled' => 1,
				'prod_account_admin_url' => $urltorenew,
				'test_account_admin_url' => $urltorenew,
			);
		}

		if (getDolGlobalString("EINVOICING_SUPERPDP_VIAPARTNER") == 'proxy') {
			$this->providersList['SUPERPDPViaPartner'] = array(
				'class' => 'SuperPDPProvider',
				'position' => 1,
				'provider_countries' => array('all'),
				'provider_name' => picto_from_langcode('FR').' SuperPDP OAuth proxy</span>',
				'description' => 'SuperPDP OAuth proxy',
				'note' => 'Proxy for client to use "authorization_code" mode',
				//'is_enabled' => getDolGlobalString('EINVOICING_TEST_SUPERPDP'),
				'is_enabled' => 1,
				'prod_account_admin_url' => $urltorenew,
				'test_account_admin_url' => $urltorenew,
			);
		}

		// Complete the list with the providers brought by external modules. Done before the "via partner
		// only" filter below, so that filter covers them too: it is meant to leave one single tunnel
		// offered to the client, whoever declared the other entries.
		$this->addProvidersFromHooks();

		// Grey-label "via partner only": when an operator ships the module pre-configured for delegated
		// onboarding, hide every direct provider so the client is only offered the via-partner tunnel.
		// Generic boolean flag: it points nowhere. The operator sets it (and the proxy URL) through its
		// own deployment SQL, not in the module source.
		if (getDolGlobalString('EINVOICING_SUPERPDP_VIAPARTNER_ONLY') && isset($this->providersList['SUPERPDPViaPartner'])) {
			array_walk($this->providersList, function (&$provider, $key) {
				if ($key !== 'SUPERPDPViaPartner') {
					$provider['is_enabled'] = 0;
				}
			});
		}

		// Sort list by position
		$this->providersList = dol_sort_array($this->providersList, 'position');
	}

	/**
	 * Complete the list of providers with the ones declared by external modules.
	 *
	 * An external module declares the hook context 'einvoicingproviders' in its descriptor
	 * ($this->module_parts['hooks'] = array('einvoicingproviders');) and implements a method
	 * addPDPProviders() into its /mymodule/class/actions_mymodule.class.php. The method fills
	 * $this->results with entries keyed by the provider code, each one holding at least a 'class'
	 * (a class extending AbstractPDPProvider) and a 'classpath' (directory of the class file,
	 * relative to the Dolibarr document root).
	 * See einvoicing/doc/ADD-A-PDP-PROVIDER.md for the complete contract.
	 *
	 * A hook is used rather than a scan of the module directories because it lists only the providers
	 * of the modules that are enabled, it costs nothing when no module implements it, and it let the
	 * module build its own entry (position, urls, label translated in the language of the user).
	 *
	 * @return void
	 */
	private function addProvidersFromHooks()
	{
		require_once DOL_DOCUMENT_ROOT.'/core/class/hookmanager.class.php';

		// A dedicated hook manager and not the global $hookmanager: this class is also instantiated from
		// inside hook methods (actions_einvoicing.class.php), so adding a context to the global manager
		// would change the hooks executed by the caller afterwards.
		$hookmanager = new HookManager($this->db);
		$hookmanager->initHooks(array('einvoicingproviders'));

		$parameters = array('providersList' => $this->providersList);
		$object = null;
		$action = '';
		$hookmanager->executeHooks('addPDPProviders', $parameters, $object, $action);

		if (empty($hookmanager->resArray) || !is_array($hookmanager->resArray)) {
			return;
		}

		foreach ($hookmanager->resArray as $key => $entry) {
			if (!is_string($key) || !is_array($entry)) {
				continue;
			}
			if (isset($this->providersList[$key])) {
				// A provider of the module is never redefined by an external module.
				dol_syslog("A hook tried to redefine the existing PDP provider ".$key.". Entry ignored.", LOG_WARNING);
				continue;
			}
			// Note: 'class' or 'classpath' is an array instead of a string when two modules declared the
			// same provider code, because the hook manager merges the results of the hooks recursively.
			if (empty($entry['class']) || !is_string($entry['class']) || empty($entry['classpath']) || !is_string($entry['classpath'])) {
				dol_syslog("A hook declared the PDP provider ".$key." without a valid 'class' or 'classpath'. Entry ignored.", LOG_WARNING);
				continue;
			}

			// Complete the entry with the defaults, so a provider declared with the 2 mandatory keys works.
			$this->providersList[$key] = $entry + array(
				'position' => 500,
				'provider_countries' => array('all'),
				'provider_name' => $key,
				'description' => '',
				'is_enabled' => 1,
				'prod_account_admin_url' => null,
				'test_account_admin_url' => null,
			);
		}
	}

	/**
	 * Get all registered providers configuration.
	 *
	 * @return array<string,array{class:string,classpath?:string,position:int,provider_countries:string[],provider_name:string,description:string,is_enabled:int<0,1>,prod_account_admin_url:?string,test_account_admin_url:?string}>	Provider list
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


		$classnametouse = $this->providersList[$name]['class'] ?? null;

		if (empty($classnametouse)) {
			return null;
		}

		// The class of a provider brought by an external module lives in the directory of that module.
		// The default keeps the providers of this module, declared without a 'classpath', working.
		$classpath = $this->providersList[$name]['classpath'] ?? '/einvoicing/class/providers/';

		$resultinclude = dol_include_once(rtrim($classpath, '/').'/'.$classnametouse.'.class.php');

		if (!$resultinclude) {
			dol_syslog("Failed to include provider class file for provider: ".$name, LOG_ERR);
			return null;
		}
		if (!class_exists($classnametouse)) {
			dol_syslog("Include provider class was done, but class is still not found: ".$classnametouse, LOG_ERR);
			return null;
		}
		if (!is_subclass_of($classnametouse, 'AbstractPDPProvider')) {
			// The rest of the module calls the methods of AbstractPDPProvider on this object, so a class
			// that does not extend it would break later, far from the declaration that is at fault.
			dol_syslog("Provider class ".$classnametouse." does not extend AbstractPDPProvider", LOG_ERR);
			return null;
		}

		$provider = new $classnametouse($db);

		if ($provider) {
			$provider->providerName = $name;
		}

		return $provider;
	}
}
