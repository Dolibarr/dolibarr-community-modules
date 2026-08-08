<?php
/* Copyright (C) 2025       Laurent Destailleur         <eldy@users.sourceforge.net>
 * Copyright (C) 2025       Mohamed DAOUD               <mdaoud@dolicloud.com>
 * Copyright (C) 2026		MDW							<mdeweerd@users.noreply.github.com>
 * Copyright (C) 2026		Jose Martinez				<jose.martinez@pichinov.com>
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
 * \file    einvoicing/class/providers/SuperPDPProvider.class.php
 * \ingroup einvoicing
 * \brief   SuperPDP PDP provider integration class
 */

dol_include_once('einvoicing/class/providers/AbstractPDPProvider.class.php');
dol_include_once('einvoicing/class/protocols/ProtocolManager.class.php');
dol_include_once('einvoicing/class/call.class.php');
dol_include_once('einvoicing/class/einvoicing.class.php');
dol_include_once('einvoicing/lib/einvoicing.lib.php');
require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';

/**
 * Class to manage SuperPDP PDP provider integration.
 */
class SuperPDPProvider extends AbstractPDPProvider
{
	/**
	 * @var string		Name
	 */
	public $name = 'SuperPDP';

	/**
	 * @var string		Help to get credentials and set up the provider configuration.
	 */
	public $helpToGetCredentials = '';


	/** @var string Callback url - url to come back to after remote call */
	public $callbackurl;

	/**
	 * Highest number of search batches a single synchronization walks through.
	 *
	 * A backstop, not a business rule: the loop already stops on its own as soon as a batch comes
	 * back short. This only bounds a run when the window holds far more flows than a session should
	 * chew through in one go, and it is reported rather than applied silently.
	 *
	 * @const int
	 */
	const MAX_SYNC_BATCHES = 50;

	/**
	 * Constructor
	 *
	 * Load setup properties and last token.
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		global $langs;

		parent::__construct($db);

		$this->config = array(
			'provider_url'  => 'https://superpdp.tech/',
			'prod_auth_url' => 'https://api.superpdp.tech/oauth2/',
			'test_auth_url' => 'https://api.superpdp.tech/oauth2/',
			'prod_api_url'  => 'https://api.superpdp.tech/afnor-flow/v1/',
			'test_api_url'  => 'https://api.superpdp.tech/afnor-flow/v1/',
			'ap_api_url' 	=> 'https://api.superpdp.tech/v1.beta/',
			'prod_afnor_directory_url' => 'https://api.superpdp.tech/afnor-directory/',
			'test_afnor_directory_url' => 'https://api.superpdp.tech/afnor-directory/',
			'client_id'     => getDolGlobalString('EINVOICING_SUPERPDP_CLIENT_ID'.(getDolGlobalInt('EINVOICING_LIVE') ? '_PROD' : '')),
			'client_secret' => getDolGlobalString('EINVOICING_SUPERPDP_CLIENT_SECRET'.(getDolGlobalInt('EINVOICING_LIVE') ? '_PROD' : '')),
			'dol_prefix'    => getDolGlobalString('EINVOICING_PDP') == 'SUPERPDPViaPartner' ? 'EINVOICING_SUPERPDPVIAPARTNER' : 'EINVOICING_SUPERPDP',
			'has_validator' => 1,
			'live' => getDolGlobalInt('EINVOICING_LIVE', 0)
		);

		// Default mode
		$this->helpToGetCredentials = '<div class="">' . $langs->trans("EINVOICING_SUPERPDP_HELP_CREDENTIAL1") . '</div>';
		$this->helpToGetCredentials .= '<div class="margintoponly">' . $langs->trans("EINVOICING_SUPERPDP_HELP_CREDENTIAL2", '{s1}') . '</div>';
		$this->helpToGetCredentials .= '<div class="margintoponly">' . $langs->trans("EINVOICING_SUPERPDP_HELP_CREDENTIAL3", '{s2}') . '</div>';
		$this->helpToGetCredentials .= '<div class="margintoponly">' . $langs->trans("EINVOICING_SUPERPDP_HELP_CREDENTIAL4", '{s3}', '{s4}', '{s5}', '{s6}') . '</div>';
		// Stated apart from the steps: this one setting decides whether received invoices can be read at all
		$this->helpToGetCredentials .= '<div class="margintoponly warning">' . img_picto('', 'warning') . ' ' . $langs->trans("EINVOICING_SUPERPDP_HELP_CREDENTIAL_CONVERSION") . '</div>';

		if (getDolGlobalString('EINVOICING_PDP') == 'SUPERPDPViaPartner') {
			$this->helpToGetCredentials = '<div class="">' . $langs->trans("EINVOICING_SUPERPDP_HELP_CREDENTIAL_VIA_PARTNER", '{s1}') . '</div>';
		}

		$redirect_uri = dol_buildpath('/einvoicing/admin/setup.php', 2);

		$this->callbackurl = $redirect_uri;

		// Retrieve and complete the OAuth token information from the database
		$this->tokenData = $this->fetchOAuthTokenDB();

		$exchangeProtocolConf = getDolGlobalString('EINVOICING_PROTOCOL');
		$ProtocolManager = new ProtocolManager($this->db);
		$this->exchangeProtocol = $ProtocolManager->getProtocol($exchangeProtocolConf);
	}


	/**
	 * Set the setup factory specific to the provider.
	 *
	 * @param FormSetup $formSetup 			The form setup object to initialize
	 * @param string 	$prefix 			The prefix for configuration keys
	 * @param string 	$prefixenv 			The prefix for environment variable keys
	 * @param array 	$providersConfig 	The array containing providers configuration
	 * @param array 	$TFieldProtocols 	The array of available protocols to set in the select field
	 * @param array 	$TFieldProfiles 	The array of available profiles to set in the select field
	 * @return void
	 */
	public function initFormSetup(&$formSetup, $prefix, $prefixenv, $providersConfig, $TFieldProtocols, $TFieldProfiles)
	{
		global $langs, $mysoc;

		$tokenData = $this->getTokenData();

		$langs->load("oauth");

		// Set content of the help page
		if (getDolGlobalString('EINVOICING_PDP') == 'SUPERPDPViaPartner') {
			if (getDolGlobalString("EINVOICING_SUPERPDP_VIAPARTNER") != 'proxy') {
				/*
				// Define $urlwithroot
				global $dolibarr_main_url_root;
				$urlwithouturlroot = preg_replace('/'.preg_quote(DOL_URL_ROOT, '/').'$/i', '', trim($dolibarr_main_url_root));
				//$urlwithroot = $urlwithouturlroot.DOL_URL_ROOT; // This is to use external domain name found into config file
				$urlwithroot = DOL_MAIN_URL_ROOT;				// This is to use same domain name than current

				include DOL_DOCUMENT_ROOT.'/core/lib/geturl.lib.php';
				$currentrooturl = getRootURLFromURL(DOL_MAIN_URL_ROOT);
				$externalrooturl = getRootURLFromURL($urlwithroot);
				*/

				$urltogeneratetoken = getDolGlobalString('EINVOICING_SUPERPDP_VIAPARTNER_OAUTH_URL');
				// $urltogeneratetoken .= '?proxy=superpdp&state=none&response_type=code&redirect_uri=' . urlencode(dol_buildpath('/einvoicing/admin/setup.php', 2));
				$query = [
					'state' => 'none',
					'response_type' => 'code',
					'redirect_uri' => dol_buildpath('/einvoicing/admin/setup.php', 2)
				];
				// Prefill company information: number and scheme must be paired together.
				// Use 'sandbox' scheme for non-live environment, otherwise use country-specific scheme (fr_siren for France, be_numero_entreprise for Belgium).
				if (!empty($mysoc->idprof1)) {
					$companyscheme = '';
					if (!getDolGlobalInt('EINVOICING_LIVE')) {
						$companyscheme = 'sandbox';
					} elseif ($mysoc->country_code == 'FR') {
						$companyscheme = 'fr_siren';
					} elseif ($mysoc->country_code == 'BE') {
						$companyscheme = 'be_numero_entreprise';
					}
					// Include company number (idprof1/SIREN) in request only if a valid scheme is available.
					// Invalid company numbers will cause the onboarding process to fail.
					if ($companyscheme) {
						$query += [
							'superpdp_company_number' => removeAllSpaces($mysoc->idprof1),
							'superpdp_company_number_scheme' => $companyscheme,
						];
					}
				}
				$urltogeneratetoken .= '?' . http_build_query($query);
				$urltoshow = $langs->trans("EINVOICING_LINK_CREATE_ACCOUNTVia", getDolGlobalString("EINVOICING_SUPERPDP_VIAPARTNER"));

				if (empty($tokenData['token'])) {
					$this->helpToGetCredentials = str_replace('{s1}', '<br><br><center>' . img_picto('', 'url', 'class="pictofixedwidth"') . '<a href="' . $urltogeneratetoken . '" target="_new">' . $urltoshow . '</a></center>', $this->helpToGetCredentials);
					$this->helpToGetCredentials = '<div class="formborderx info">' . $this->helpToGetCredentials . '</div>';
				} else {
					$this->helpToGetCredentials = '<div class="green greenborder">';
					$this->helpToGetCredentials .= '<center>';
					$this->helpToGetCredentials .= $langs->trans("YourSoftwareSeemsConnectedWith", strtoupper($this->name));
					$this->helpToGetCredentials .= ' <a href="'.$this->config['provider_url'].'" target="_blank">('.$this->config['provider_url'].')</a>';
					$this->helpToGetCredentials .= '<br><br>' . img_picto('', 'delete', 'class="pictofixedwidth"') . '<a href="' . $_SERVER["PHP_SELF"] . '?action=delete' . $prefix . "TOKEN&token=" . newToken() . '">' . $langs->trans("ClickHereToRemoveConnection") . '</a>';
					$this->helpToGetCredentials .= '</center>';
					$this->helpToGetCredentials .= '</div>';
				}
			} else {
				$urlforproxy =  dol_buildpath('einvoicing/public/proxy_oauthcallback.php', 3);

				$this->helpToGetCredentials = '<div class="green greenborder">';
				$this->helpToGetCredentials .= 'You are on the proxy for SuperPDP Access Point registration.<br><br>';
				$this->helpToGetCredentials .= 'URL of proxy is:<br><input type="text" class="quatrevingtpercent" id="urlproxy" value="' . $urlforproxy . '"spellcheck="false">';
				$this->helpToGetCredentials .= ajax_autoselect("urlproxy");
				$this->helpToGetCredentials .= '</div>';
			}
		} else {
			$url = $providersConfig[getDolGlobalString('EINVOICING_PDP')][$prefixenv . '_account_admin_url'];
			$urltoshow = $url;

			// Default help
			if (empty($tokenData['token'])) {
				$this->helpToGetCredentials = str_replace('{s1}', img_picto('', 'url', 'class="pictofixedwidth"') . '<a href="' . $url . '" target="_new">' . $urltoshow . '</a>', $this->helpToGetCredentials);
				$this->helpToGetCredentials = str_replace('{s2}', '<input type="text" class="width300" value="' . $this->callbackurl . '" spellcheck="false">', $this->helpToGetCredentials);
				$this->helpToGetCredentials = str_replace('{s3}', $langs->transnoentitiesnoconv("EINVOICING_CLIENT_ID"), $this->helpToGetCredentials);
				$this->helpToGetCredentials = str_replace('{s4}', $langs->transnoentitiesnoconv("EINVOICING_CLIENT_SECRET"), $this->helpToGetCredentials);
				$this->helpToGetCredentials = str_replace('{s5}', $langs->transnoentitiesnoconv("Save"), $this->helpToGetCredentials);
				$this->helpToGetCredentials = str_replace('{s6}', $langs->transnoentitiesnoconv("ConnectTo"), $this->helpToGetCredentials);

				$this->helpToGetCredentials = '<div class="formborderx info">' . $this->helpToGetCredentials . '</div>';
			} else {
				$this->helpToGetCredentials = '<div class="green greenborder">';
				$this->helpToGetCredentials .= '<center>';
				$this->helpToGetCredentials .= $langs->trans("YourSoftwareSeemsConnectedWith", strtoupper($this->name));
				$this->helpToGetCredentials .= ' <a href="'.$this->config['provider_url'].'" target="_blank">('.$this->config['provider_url'].')</a>';
				$this->helpToGetCredentials .= '<br><br>' . img_picto('', 'delete', 'class="pictofixedwidth"') . '<a href="' . $_SERVER["PHP_SELF"] . '?action=delete' . $prefix . "TOKEN&token=" . newToken() . '">' . $langs->trans("ClickHereToRemoveConnection") . '</a>';
				$this->helpToGetCredentials .= '</center>';
				$this->helpToGetCredentials .= '</div>';
			}
		}

		// E-Invoice ID
		$item = $formSetup->newItem($prefix . 'ROUTING_ID');
		$item->nameText = $langs->transnoentities('EINVOICING_ROUTING_ID');
		$item->helpText = $langs->transnoentities('EINVOICING_ROUTING_ID_HELP');
		$item->helpText .= '<br><br>'.img_picto('', 'warning').' '.$langs->trans('WarningIfYouSetAnIDItMustExistsInAnnuary');
		// @phan-suppress-next-line PhanTypeMismatchArgumentNullable
		$item->fieldAttr['placeholder'] = idprof($mysoc);
		$item->fieldParams['isMandatory'] = 0;
		$item->cssClass = 'minwidth300';

		// Setup conf to choose a protocol of exchange
		/* Moved into the tab "Options"
		$item = $formSetup->newItem('EINVOICING_PROTOCOL')->setAsSelect($TFieldProtocols);
		$item->helpText = $langs->transnoentities('EINVOICING_PROTOCOL_HELP');
		$item->defaultFieldValue = 'FACTURX';
		$item->cssClass = 'minwidth500';
		$item->fieldParams['trClass'] = 'advancedoption';
		*/

		// Setup conf to choose a profil of exchange
		// $item = $formSetup->newItem('EINVOICING_PROFILE')->setAsSelect($TFieldProfiles);
		// $item->helpText = $langs->transnoentities('EINVOICING_PROFILE_HELP');
		// $item->defaultFieldValue = 'EN16931';
		// $item->cssClass = 'minwidth500';
		// $item->fieldParams['trClass'] = 'advancedoption';

		if (getDolGlobalString('EINVOICING_PDP') != 'SUPERPDPViaPartner' || getDolGlobalString('EINVOICING_SUPERPDP_VIAPARTNER') == 'proxy') {
			// OAuth grant type: client_credentials (own account, paste credentials) or
			// authorization_code (delegated authorization / onboarding of a third party).

			/* If module is on a customer client instance not using proxy (getDolGlobalString('EINVOICING_PDP') == 'SUPERPDP'), he use the grant type client_credentials
			 * If module is on a customer client instance to use proxy (getDolGlobalString('EINVOICING_PDP') == 'SUPERPDPViaPartner'), he use the grant type authorization_code
			 * If module is the proxy instance (getDolGlobalString('EINVOICING_SUPERPDP_VIAPARTNER') =='proxy'), we use grant type client_credentials but we may use both so we add the option
			 */

			/* This option seems useless, see previous comment
			if (getDolGlobalString('EINVOICING_SUPERPDP_VIAPARTNER') == 'proxy') {
				$item = $formSetup->newItem($prefix.'GRANT_TYPE')->setAsSelect(array(
					'client_credentials' => $langs->trans('EINVOICING_SUPERPDP_GRANT_CLIENT_CREDENTIALS'),
					'authorization_code' => $langs->trans('EINVOICING_SUPERPDP_GRANT_AUTHORIZATION_CODE'),
				));

				$item->nameText = $langs->trans('EINVOICING_SUPERPDP_GRANT_TYPE');
				$item->helpText = $langs->transnoentities('EINVOICING_SUPERPDP_GRANT_TYPE_HELP');
				$item->defaultFieldValue = 'client_credentials';
				$item->cssClass = 'minwidth500';
			}
			*/

			// Username
			$item = $formSetup->newItem($prefix.'CLIENT_ID'.(getDolGlobalInt('EINVOICING_LIVE') ? '_PROD' : ''));
			$item->nameText = $langs->trans('EINVOICING_CLIENT_ID');
			$item->cssClass = 'minwidth500';

			// Password
			$item = $formSetup->newItem($prefix.'CLIENT_SECRET'.(getDolGlobalInt('EINVOICING_LIVE') ? '_PROD' : ''));
			if (method_exists('FormSetupItem', 'setAsGenericPassword')) {
				$item->setAsGenericPassword();
			} else {
				// Dolibarr 18/19 fallback: setAsGenericPassword() does not exist yet.
				// Force a masked password input so the secret is not displayed in clear text.
				$item->fieldAttr['type'] = 'password';
				$item->fieldAttr['autocomplete'] = 'new-password';
			}
			$item->nameText = $langs->trans('EINVOICING_CLIENT_SECRET');
			$item->cssClass = 'minwidth500';

			// Authorization Code specific settings
			// We suggest all these options if we are on the proxy.
			if (getDolGlobalString('EINVOICING_SUPERPDP_VIAPARTNER') == 'proxy' && preg_match('/ViaPartner/', getDolGlobalString('EINVOICING_PDP'))) {
				// Redirect URI to register in the SuperPDP interface (must match exactly)
				$item = $formSetup->newItem($prefix.'REDIRECT_URI_INFO');
				$item->nameText = $langs->trans('EINVOICING_SUPERPDP_REDIRECT_URI');
				$item->fieldOverride = '<span class="opacitymedium">'.dol_escape_htmltag($this->callbackurl).'</span>';
				$item->helpText = $langs->transnoentities('EINVOICING_SUPERPDP_REDIRECT_URI_HELP');
				$item->cssClass = 'minwidth500';

				// Directory registration UI behaviour during onboarding
				$item = $formSetup->newItem($prefix.'SEND_AND_RECEIVE')->setAsSelect(array(
					'any' => 'any', 'send' => 'send', 'receive' => 'receive',
				));
				$item->nameText = $langs->trans('EINVOICING_SUPERPDP_SEND_AND_RECEIVE');
				$item->helpText = $langs->transnoentities('EINVOICING_SUPERPDP_SEND_AND_RECEIVE_HELP');
				$item->defaultFieldValue = 'any';
				$item->cssClass = 'minwidth500';

				$item = $formSetup->newItem($prefix.'ONLY_FUTURE')->setAsYesNo();
				$item->nameText = $langs->trans('EINVOICING_SUPERPDP_ONLY_FUTURE');
				$item->helpText = $langs->transnoentities('EINVOICING_SUPERPDP_ONLY_FUTURE_HELP');
				$item->defaultFieldValue = '0';
				$item->cssClass = 'minwidth500';

				$item = $formSetup->newItem($prefix.'DIRECTORY_ENTRY_IDENTIFIER');
				$item->nameText = $langs->trans('EINVOICING_SUPERPDP_DIRECTORY_ENTRY_IDENTIFIER');
				$item->helpText = $langs->transnoentities('EINVOICING_SUPERPDP_DIRECTORY_ENTRY_IDENTIFIER_HELP');
				$item->cssClass = 'minwidth500';
			}
		}

		// API_KEY
		//$item = $formSetup->newItem($prefix . 'API_KEY'.(getDolGlobalInt('EINVOICING_LIVE') ? '_PROD' : ''));
		//$item->cssClass = 'minwidth500';

		// Token
		if (getDolGlobalString('EINVOICING_PDP') != 'SUPERPDPViaPartner' || getDolGlobalString('EINVOICING_SUPERPDP_VIAPARTNER') != 'proxy') {
			if ((getDolGlobalString('EINVOICING_PDP') == 'SUPERPDP' || getDolGlobalString('EINVOICING_PDP') == 'SUPERPDPViaPartner')) {	// When we are on a proxy server, no token need to be generated here.
				$texttoshow = '';
				$urltogeneratetoken = '';
				if (getDolGlobalString('EINVOICING_PDP') == 'SUPERPDPViaPartner' && getDolGlobalString("EINVOICING_SUPERPDP_VIAPARTNER")) {
					$texttoshow = $langs->trans('ConnectTo').' ('.$langs->trans('generateAccessToken') . ' via ' . getDolGlobalString("EINVOICING_SUPERPDP_VIAPARTNER").')';
					$urltogeneratetoken = getDolGlobalString('EINVOICING_SUPERPDP_VIAPARTNER_OAUTH_URL');
					// $urltogeneratetoken .= '?state=none&response_type=code&redirect_uri=' . urlencode(dol_buildpath('/einvoicing/admin/setup.php', 2));
					$query = [
						'state' => 'none',
						'response_type' => 'code',
						'redirect_uri' => dol_buildpath('/einvoicing/admin/setup.php', 2)
					];
					// Company prefill (number + scheme are an indissociable pair). Sandbox scheme off-live,
					// otherwise fr_siren / be_numero_entreprise by country.
					if (!empty($mysoc->idprof1)) {
						$companyscheme = '';
						if (!getDolGlobalInt('EINVOICING_LIVE')) {
							$companyscheme = 'sandbox';
						} elseif ($mysoc->country_code == 'FR') {
							$companyscheme = 'fr_siren';
						} elseif ($mysoc->country_code == 'BE') {
							$companyscheme = 'be_numero_entreprise';
						}
						if ($companyscheme) {
							$query += [
								'superpdp_company_number' => removeAllSpaces($mysoc->idprof1), // The number (idprof1) must be valid, otherwise onboarding will fail.
								'superpdp_company_number_scheme' => $companyscheme,
							];
						}
					}
					$urltogeneratetoken .= '?' . http_build_query($query);
				} elseif (getDolGlobalString($prefix . 'CLIENT_ID'.(getDolGlobalInt('EINVOICING_LIVE') ? '_PROD' : '')) && getDolGlobalString($prefix . 'CLIENT_SECRET'.(getDolGlobalInt('EINVOICING_LIVE') ? '_PROD' : ''))) {
					if (getDolGlobalString($prefix . 'GRANT_TYPE') == 'authorization_code') {
						// OAuth 2.1 Authorization Code: redirect the user to SuperPDP's authorize endpoint.
						$texttoshow = $langs->trans('ConnectTo').' ('.$langs->trans('EINVOICING_SUPERPDP_GRANT_AUTHORIZATION_CODE').')';
						$urltogeneratetoken = $this->getAuthorizationCodeUrl();
					} else {
						$texttoshow = $langs->trans('ConnectTo').' ('.$langs->trans('generateAccessToken').')';
						$urltogeneratetoken = $_SERVER["PHP_SELF"] . "?action=set" . $prefix . "TOKEN&token=" . newToken();
					}
				}

				if ($urltogeneratetoken && (getDolGlobalString('EINVOICING_PDP') != 'SUPERPDPViaPartner' || !empty($tokenData['token']))) {
					$item = $formSetup->newItem($prefix . 'TOKEN'.(getDolGlobalInt('EINVOICING_LIVE') ? '_PROD' : ''));
					$item->nameText = $langs->trans('AccessToken');
					$item->cssClass = 'maxwidth500 ';
					$item->fieldOverride = "";
					if (!empty($tokenData['token'])) {
						$item->fieldOverride = htmlspecialchars('**************' . substr($tokenData['token'], -4));

						if (!empty($tokenData['token_expires_at'])) {
							$item->fieldOverride .= ' &nbsp; <span class="opacitymedium hideonsmartphone">(' . $langs->trans("until") . ' ' . dol_print_date($tokenData['token_expires_at'], 'dayhoursec', 'tzuserrel') . ')</span>';
						}
						//var_dump($tokenData);
					}
					if (empty($tokenData['token'])) {
						$item->fieldOverride .= '<a class="reposition" href="' . $urltogeneratetoken . '">' . $texttoshow . '<i class="fa fa-key paddingleft"></i></a>';
					}
					if (!empty($tokenData['token'])) {
						$item->fieldOverride .= ' &nbsp; &nbsp; &nbsp; <a class="reposition" href="' . $urltogeneratetoken . '"><i class="fa fa-key paddingright"></i>' . $langs->trans('reGenerateAccessToken') . '</a>';
					}

					if (!empty($tokenData['token'])) {
						$item->fieldOverride .= ' &nbsp; &nbsp; <a class="reposition" href="' . $_SERVER["PHP_SELF"] . "?action=delete" . $prefix . "TOKEN&token=" . newToken() . '">' . img_picto($langs->trans("Delete"), 'delete') . '</a>';
					}
				}
			}

			if (getDolGlobalString('EINVOICING_PDP') != 'SUPERPDPViaPartner' || getDolGlobalString('EINVOICING_SUPERPDP_VIAPARTNER') != 'proxy') {	// When we are on a proxy server, no token need to be generated here.
				if (!empty($tokenData['token'])) {
					// Actions
					$item = $formSetup->newItem($prefix . 'ACTIONS');
					$item->nameText = "&nbsp;";

					$item->fieldOverride .= '<a class="reposition" href="' . $_SERVER["PHP_SELF"] . "?action=call" . $prefix . "HEALTHCHECK&token=" . newToken() . '"><i class="fa fa-heartbeat pictofixedwidth centerimp"></i>' . $langs->trans('testConnection') . ' (Healthcheck)</a><br>';
					$item->cssClass = 'minwidth500';

					if ($tokenData['token'] && getDolGlobalString('EINVOICING_PROTOCOL')) {
						if (getDolGlobalString('EINVOICING_LIVE')) {
							$item->fieldOverride .= '<span class="opacitymedium" title="'.$langs->trans("DisabledInProductionMode").'"><i class="fa fa-file pictofixedwidth centerimp"></i>' . $langs->trans('generateSendSampleInvoice') . '</span><br>';
						} else {
							if (getDolGlobalInt('EINVOICING_ALLOW_DEVTOOLS')) {
								$item->fieldOverride .= '<a class="reposition" href="' . $_SERVER["PHP_SELF"] . "?action=make" . $prefix . "sampleinvoice&token=" . newToken() . '"><i class="fa fa-file pictofixedwidth centerimp"></i>' . $langs->trans('generateSampleInvoice') . '</a><br>';
							}
							$item->fieldOverride .= '<a class="reposition" href="' . $_SERVER["PHP_SELF"] . "?action=makesend" . $prefix . "sampleinvoice&token=" . newToken() . '"><i class="fa fa-file pictofixedwidth centerimp"></i>' . $langs->trans('generateSendSampleInvoice') . '</a><br>';
						}
					}

					// Check your ID in E-Invoice Annuary
					$showannuary = 0;
					$idtocheck = '';
					if ($mysoc->country_code == 'FR') {
						$showannuary++;

						$item->fieldOverride .= '<i class="fa fa-list-alt pictofixedwidth centerimp"></i>'.$langs->trans('CheckYourIDInEInvoiceAnnuary');

						$einvoicing = new EInvoicing($this->db);
						$idtocheck = (string) $einvoicing->getSellerCommunicationURI(0);

						if (getDolGlobalString('EINVOICING_LIVE')) {
							$item->fieldOverride .= ': <a class="reposition" href="https://facturation.chorus-pro.gouv.fr/annuaire/#/" target="_blank">' . $langs->trans('FrenchGovAnnuary') . '</a>';
							$item->fieldOverride .= ' - <a class="reposition" href="https://www.superpdp.tech/outils/info-annuaire/?query='.urlencode($idtocheck).'&mode=fr&env=production" target="_blank">' . $langs->trans('SuperPDPAnnuary') . '</a>';
						} else {
							$item->fieldOverride .= ': <a class="reposition" href="https://www.superpdp.tech/outils/info-annuaire/?query='.urlencode($idtocheck).'&mode=fr&env=sandbox" target="_blank">' . $langs->trans('SuperPDPAnnuary') . '</a>';
						}
					}
					if (!getDolGlobalString('EINVOICING_LIVE')) {
						if ($showannuary) {
							$item->fieldOverride .= ' - ';
						}
						$item->fieldOverride .= '<a class="reposition" href="https://test-directory.peppol.eu/public/locale-en_US/menuitem-search?q='.urlencode($idtocheck).'&mode=fr&env=sandbox" target="_blank">' . $langs->trans('PeppolTestAnnuary') . '</a>';
					}
				}
			}
		}
	}

	/**
	 * Validate configuration parameters before API calls.
	 *
	 * @param 	int		$mode 	0 check that user/pass is set, 1 check that api key is set
	 * @return 	bool 			True if configuration is valid.
	 */
	public function validateConfiguration($mode = 1)
	{
		global $langs;

		$error = array();
		if ($mode == 0) {
			if (empty($this->config['client_id'])) {
				$langs->loadLangs(array("main", "oauth"));
				$error[] = $langs->trans('ErrorFieldRequired', $langs->transnoentities('EINVOICING_CLIENT_ID'));
			}
			if (empty($this->config['client_secret'])) {
				$langs->loadLangs(array("main", "oauth"));
				$error[] = $langs->trans('ErrorFieldRequired', $langs->transnoentities('EINVOICING_CLIENT_SECRET'));
			}
		} elseif ($mode == 1) {  // @phan-suppress-current-line PhanPluginEmptyStatementIf
			// Not used
		}

		if (!empty($error)) {
			$this->errors[] = $langs->trans("CheckPdpConfiguration");
			$this->errors = array_merge($this->errors, $error);
		}
		return empty($error);
	}

	/**
	 * Get access token from OAUth server and save it into database.
	 * This erase old token.
	 *
	 * @return string|null 		Access token or null on failure.
	 * @see getTokenData() to get current token in memory (loaded by fetchOAuthTokenDB in constructor)
	 */
	public function getAccessToken()
	{
		global $langs;

		$providerconfig = $this->getConf();

		$param = array(
			'grant_type' => "client_credentials",
			'client_id' => $providerconfig['client_id'],
			'client_secret' => $providerconfig['client_secret']
		);
		$paramstring = http_build_query($param);

		$extraHeaders = array(
			'Content-Type' => 'application/x-www-form-urlencoded'
		);

		$response = $this->callApi("token", "POST", $paramstring, $extraHeaders, 'get_access_token');

		$status_code = $response['status_code'];
		$body = $response['response'];

		if ($status_code == 200 && isset($body['access_token']) && isset($body['expires_in'])) {
			$this->saveOAuthTokenDB($body['access_token'], $body['refresh_token'] ?? '', $body['expires_in']);

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
	public function refreshAccessToken()
	{
		// OAuth 2.1: when a refresh_token is available (Authorization Code grant), renew the access token
		// with grant_type=refresh_token instead of re-authenticating from scratch. A full re-auth opens a
		// new session on the PA each time, whereas refreshing does not. The refresh token is rotated on each
		// use, so we must persist the new one returned by the server.
		if (!empty($this->tokenData['refresh_token'])) { // Refresh token is available only for Authorization Code grant, not for Client Credentials grant.
			$providerconfig = $this->getConf();

			// "Via partner" (grey-label) client: it holds no client_secret, so it cannot run the
			// refresh_token grant against the PA directly. Route the refresh through the operator's
			// proxy, which holds the secret and performs the grant on our behalf, then returns the
			// rotated tokens. Mirrors the delegated enrolment flow (proxy_oauthcallback.php).
			$proxyurl = getDolGlobalString('EINVOICING_SUPERPDP_VIAPARTNER_OAUTH_URL');
			if (getDolGlobalString('EINVOICING_PDP') == 'SUPERPDPViaPartner'
				&& getDolGlobalString('EINVOICING_SUPERPDP_VIAPARTNER') != 'proxy'
				&& !empty($proxyurl)) {
				require_once DOL_DOCUMENT_ROOT.'/core/lib/geturl.lib.php';

				$param = array(
					'action'        => 'refresh',
					'grant_type'    => 'refresh_token',
					'refresh_token' => $this->tokenData['refresh_token'],
				);

				// Allow HTTP and local URLs for testing only if the configuration allows it. Otherwise, only HTTPS is allowed.
				$allowedprotocols = array('https');
				$allowlocalurl = 0;
				if (!empty(getDolGlobalInt('EINVOICING_ALLOW_LOCAL_URL'))) {
					$allowlocalurl = 2;
					$allowedprotocols[] = 'http';
				}
				$resultget = getURLContent($proxyurl, 'POST', http_build_query($param), 1, array('Content-Type: application/x-www-form-urlencoded'), $allowedprotocols, $allowlocalurl);

				$httpcode = empty($resultget['http_code']) ? 0 : $resultget['http_code'];
				if (empty($resultget['curl_error_no']) && $httpcode == 200) {
					$body = json_decode($resultget['content'], true);
					if (is_array($body) && !empty($body['access_token']) && isset($body['expires_in'])) {
						$this->saveOAuthTokenDB($body['access_token'], $body['refresh_token'] ?? $this->tokenData['refresh_token'], $body['expires_in']);
						$this->tokenData = $this->fetchOAuthTokenDB();
						return $body['access_token'];
					}
				}
				// Proxy refresh failed: a via-partner client has no secret to fall back on, so we stop here.
				dol_syslog(__METHOD__." refresh via partner proxy failed http_code=".$httpcode . " error=".$resultget['curl_error_msg'], LOG_WARNING, 0, "_einvoicing");
				// Return a generic error message to avoid leaking the proxy URL in the logs.
				setEventMessages('FailedToRetrieveAccessToken', null, 'errors');
				$this->errors[] = 'FailedToRetrieveAccessToken';
				return null;
			}

			$param = array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => $this->tokenData['refresh_token'],
				'client_id'     => $providerconfig['client_id'],
				'client_secret' => $providerconfig['client_secret'],
			);
			$paramstring = http_build_query($param);
			$extraHeaders = array('Content-Type' => 'application/x-www-form-urlencoded');

			$response = $this->callApi("token", "POST", $paramstring, $extraHeaders, 'refresh_access_token');
			$status_code = $response['status_code'] ?? 0;
			$body = $response['response'] ?? null;

			if ($status_code == 200 && is_array($body) && isset($body['access_token']) && isset($body['expires_in'])) {
				// Persist the rotated refresh_token (keep the previous one if the server did not rotate it).
				$this->saveOAuthTokenDB($body['access_token'], $body['refresh_token'] ?? $this->tokenData['refresh_token'], $body['expires_in']);
				$this->tokenData = $this->fetchOAuthTokenDB();
				return $body['access_token'];
			}
			// Refresh failed (refresh token expired or already rotated away): fall through to a full re-auth.
		}

		// No refresh token (e.g. Client Credentials grant, which issues none) or refresh failed: re-authenticate.
		return $this->getAccessToken();
	}

	/**
	 * Build the OAuth 2.1 Authorization Code authorize URL (delegated authorization / onboarding).
	 *
	 * Generates and stores an anti-CSRF state in the session, then returns the SuperPDP authorize URL with
	 * the client_id, redirect_uri and optional prefill parameters (company number, login hint, directory
	 * options). No PKCE: SuperPDP's reference example uses a random state and a confidential client.
	 *
	 * @return string 	Authorize URL to redirect the user to (empty string if client_id is missing)
	 */
	public function getAuthorizationCodeUrl()
	{
		global $user, $mysoc;

		$providerconfig = $this->getConf();
		if (empty($providerconfig['client_id'])) {
			return '';
		}

		$authbase = $providerconfig['live'] ? $providerconfig['prod_auth_url'] : $providerconfig['test_auth_url'];

		// Anti-CSRF state, stored in session and verified on the callback.
		$state = bin2hex(random_bytes(16));
		$_SESSION['einvoicing_superpdp_oauth_state'] = $state;

		// SuperPDP: scopes must be left empty — the parameter is OMITTED, not sent as scope='' (which the
		// authorize endpoint rejects with invalid_request, like the reference golang.org/x/oauth2 example).
		$query = array(
			'response_type' => 'code',
			'client_id'     => $providerconfig['client_id'],
			'redirect_uri'  => $this->callbackurl,
			'state'         => $state,
		);

		if (!empty($user->email)) {
			$query['login_hint'] = $user->email;
		}

		// Company prefill (number + scheme are an indissociable pair). Sandbox scheme when not in live mode.
		$companyscheme = '';
		if (!empty($mysoc->idprof1)) {
			if (empty($providerconfig['live'])) {
				$companyscheme = 'sandbox';
			} elseif ($mysoc->country_code == 'FR') {
				$companyscheme = 'fr_siren';
			} elseif ($mysoc->country_code == 'BE') {
				$companyscheme = 'be_numero_entreprise';
			}
			if ($companyscheme) {
				$query['superpdp_company_number'] = removeAllSpaces($mysoc->idprof1); // The number (idprof1) must be valid, otherwise onboarding will fail.
				$query['superpdp_company_number_scheme'] = $companyscheme;
			}
		}

		// Optional directory options. directory_entry_identifier and only_future are fr_siren-specific
		// (per SuperPDP docs) — do not send them for the sandbox/be schemes.
		if (getDolGlobalString('EINVOICING_SUPERPDP_SEND_AND_RECEIVE')) {
			$query['superpdp_send_and_receive'] = getDolGlobalString('EINVOICING_SUPERPDP_SEND_AND_RECEIVE');
		}
		if ($companyscheme === 'fr_siren') {
			if (getDolGlobalString('EINVOICING_SUPERPDP_DIRECTORY_ENTRY_IDENTIFIER')) {
				// TODO The EINVOICING_SUPERPDP_DIRECTORY_ENTRY_IDENTIFIER should be a prefix only
				// and superpdp_directory_entry_identifier should be removeAllSpaces($mysoc->idprof1).'_'.getDolGlobalString('EINVOICING_SUPERPDP_DIRECTORY_ENTRY_IDENTIFIER');
				// or it should be a param on the end client side, not on proxy ?
				$query['superpdp_directory_entry_identifier'] = getDolGlobalString('EINVOICING_SUPERPDP_DIRECTORY_ENTRY_IDENTIFIER');
			}
			if (getDolGlobalInt('EINVOICING_SUPERPDP_ONLY_FUTURE')) {
				$query['superpdp_only_future'] = 'true';
			}
		}

		return $authbase . 'authorize?' . http_build_query($query);
	}

	/**
	 * Exchange an OAuth 2.1 authorization code for an access token + refresh token, and store them.
	 *
	 * @param 	string 			$code 	Authorization code received on the redirect callback
	 * @return 	string|null 			Access token on success, null on failure (errors filled)
	 */
	public function exchangeAuthorizationCode($code)
	{
		global $langs;

		$providerconfig = $this->getConf();

		$param = array(
			'grant_type'    => 'authorization_code',
			'code'          => $code,
			'redirect_uri'  => $this->callbackurl,
			'client_id'     => $providerconfig['client_id'],
			'client_secret' => $providerconfig['client_secret'],
		);
		$paramstring = http_build_query($param);
		$extraHeaders = array('Content-Type' => 'application/x-www-form-urlencoded');

		$response = $this->callApi("token", "POST", $paramstring, $extraHeaders, 'authorization_code');
		$status_code = $response['status_code'] ?? 0;
		$body = $response['response'] ?? null;

		if ($status_code == 200 && is_array($body) && isset($body['access_token']) && isset($body['expires_in'])) {
			$this->saveOAuthTokenDB($body['access_token'], $body['refresh_token'] ?? '', $body['expires_in']);
			$this->tokenData = $this->fetchOAuthTokenDB();
			return $body['access_token'];
		}

		$this->errors[] = $langs->trans("FailedToRetrieveAccessToken");
		return null;
	}

	/**
	 * Delete access token.
	 *
	 * @return 	bool                	       	True if success, false otherwise
	 */
	public function deleteAccessToken()
	{
		$result = $this->deleteOAuthTokenDB();
		return $result;
	}

	/**
	 * Perform a health check call for PDP provider.
	 *
	 * @return array Contains 'status' (bool) and 'message' (string)
	 */
	public function checkHealth()
	{
		global $langs;

		$response = $this->callApi("healthcheck", "GET", false, [], 'healthcheck');		// This include the refresh of token
		$returnarray = array();

		$nameOfAccessPoint = getDolGlobalString('EINVOICING_PDP');
		$nameOfAccessPoint = preg_replace('/ViaPartner/', '', $nameOfAccessPoint);

		if ($response['status_code'] === 200) {
			$returnarray['status_code'] = true;
			$returnarray['message'] = $langs->trans('APApiReachable', $nameOfAccessPoint);
		} else {
			$returnarray['status_code'] = false;
			$returnarray['message'] = $langs->trans('APApiNotReachable', $nameOfAccessPoint) . ' (HTTP ' . ($response['status_code'] ?? 'N/A') . ')' . (!empty($response['response']) ? ' - ' . $response['response'] : '');
		}

		return $returnarray;
	}

	/**
	 * Validate an electronic invoice file using the superPDP validation service.
	 *
	 * @param 	int 	$idinvoice 	ID of the invoice to validate
	 * @param 	string 	$filePath 	Path to the invoice file to validate
	 *
	 * @return 	array|string 		Validation result or error message.
	 */
	public function validateEInvoiceFile($idinvoice, $filePath)
	{
		global $langs;

		if (empty($this->config['has_validator']) || $this->config['has_validator'] != 1) {
			return array('res' => -1, 'message' => $langs->trans('NoAvailableValidatorforThisAccessPoint'));
		}

		if (empty($filePath) || !is_string($filePath)) {
			return array('res' => -1, 'message' => 'Invalid file path provided for validation');
		}

		if (!file_exists($filePath)) {
			return array('res' => -1, 'message' => "E-invoice file not found: " . $filePath);
		}

		$mimeType = mime_content_type($filePath) ?: 'application/octet-stream';

		$params = [
			'file' => new CURLFile($filePath, $mimeType, basename($filePath)),
		];

		// Extra headers
		$extraHeaders = [
			'Content-Type' => 'multipart/form-data'
		];

		$response = $this->callApi("validation_reports", "POSTALREADYFORMATED", $params, $extraHeaders, 'precheck_invoice');

		if (empty($response) || !isset($response['response']['data']['0'])) {
			return array('res' => -1, 'message' => 'Invalid response from validation service');
		}

		$report = $response['response']['data']['0'] ?? null;
		$isValid = !empty($report['is_valid']);

		$report_details = [];
		if (!empty($report['error'])) {
			$report_details['error'] = $report['error'];
		}
		if (!empty($report['subreports'])) {
			$report_details['subreports'] = $report['subreports'];
		}

		// Save the validation report and status
		$einvoicing = new EInvoicing($this->db);
		$einvoicing->insertOrUpdateExtLink($idinvoice, 'facture', '', 0, '', '', null, ($isValid ? 'passed' : 'failed'), json_encode($report_details));

		return array(
			'res'     => $isValid ? 1 : -1,
			'message' => ''
		);
	}

	/**
	 * Send an electronic invoice.
	 *
	 * This function send an invoice to PDP
	 *
	 * @param	Facture		$object 	Invoice object
	 * @return 	string|array{res:int<-1,1>,message:string}|0|false			flowId if the invoice was successfully sent, false otherwise.
	 */
	public function sendInvoice($object)
	{
		global $conf, $langs;

		$outputLog = array(); // Feedback to display

		$filename = dol_sanitizeFileName($object->ref);
		$filedir = $conf->invoice->multidir_output[$object->entity ?? $conf->entity] . '/' . dol_sanitizeFileName($object->ref);
		switch (getDolGlobalString('EINVOICING_PROTOCOL')) {
			case 'FACTURX':
				$suffix = '_facturx.pdf';
				$mime_type = 'application/pdf';
				$flowSyntax = 'Factur-X';
				break;
			case 'CII':
				$suffix = '_cii.xml';
				$mime_type = 'application/xml';
				$flowSyntax = 'CII';
				break;
			default:
				$suffix = '_facturx.pdf';
				$mime_type = 'application/pdf';
				$flowSyntax = 'Factur-X';
		}
		$invoice_path = $filedir . '/' . $filename . $suffix;

		if (!file_exists($invoice_path)) {
			$this->errors[] = "Electronic Invoice file not found";
			return false;
		}

		$file_info = pathinfo($invoice_path);

		// Format Access Point resource Url
		$uuid = $this->generateUuidV4(); // UUID used to correlate logs between Dolibarr and PDP TODO : Store it somewhere

		// Format AP resource Url
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
		// The profile is declared from what the document actually carries, never hardcoded, so the
		// declaration cannot contradict the transmitted file (issue #395). Empty means "omit it",
		// which both platforms accept and which is the only correct answer for a profile that has
		// no AFNOR flowProfile of its own.
		$flowInfo = [
			"flowSyntax" => $flowSyntax,			// CII or Factur-X
			"trackingId" => $object->ref,
			"name" => "Invoice_" . $object->ref,
			"sha256" => hash_file('sha256', $invoice_path)
		];

		$flowProfile = $this->resolveFlowProfile($invoice_path);
		if ($flowProfile !== '') {
			$flowInfo = array("flowProfile" => $flowProfile) + $flowInfo;
		}

		$params = [
			'flowInfo' => json_encode($flowInfo),
			'file' => new CURLFile($invoice_path, $mime_type, basename($invoice_path))
		];



		$response = $this->callApi("flows", "POSTALREADYFORMATED", $params, $extraHeaders, 'send_invoice');

		if ($response['status_code'] == 200 || $response['status_code'] == 202) {
			$flowId = $response['response']['flowId'] ?? '';
			$callId = $response['id'];
			$callRef = $response['call_id'];

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

			// Update einvoice status with awaiting validation
			$einvoicing = new EInvoicing($this->db);
			$einvoicing->insertOrUpdateExtLink($object->id, $object->element, $flowId, EInvoicing::STATUS_AWAITING_VALIDATION, $object->ref);

			// Call the API to retrieve flow details and check the validation status.
			// A short delay is applied to allow the PDP time to process the document.
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
				'check_invoice_validation'
			);

			if ($response['status_code'] != 200 && $response['status_code'] != 202) {
				return array('res' => -1, 'message' => "FlowId: " . $flowId . " - Failed to retrieve flow details");
			}

			// Process flow data
			$flowData = array();
			try {
				$flowData = json_decode($response['response'], true);
			} catch (Exception $e) {
				return array('res' => -1, 'message' => "FlowId: " . $flowId . " - Failed to parse the json answer");
			}

			// Update einvoice status with received validation result
			$syncStatus = $einvoicing::STATUS_AWAITING_VALIDATION;
			$ack_statusLabel = $flowData['acknowledgement']['status'] ?? '';
			if ($ack_statusLabel) {
				$syncStatus = $einvoicing->getDolibarrStatusCodeFromPdpLabel($ack_statusLabel);
			}
			$syncRef = $flowData['trackingId'] ?? '';
			$syncComment = $flowData['acknowledgement']['details'][0]['reasonMessage'] ?? '';
			$einvoicing->insertOrUpdateExtLink($object->id, $object->element, $flowId, $syncStatus, $syncRef, $syncComment);

			// Log an event in the invoice timeline
			$eventLabel = "EINVOICING - Status: " . $ack_statusLabel;
			$eventLabel .= " - " . $callRef;

			$eventMessage = "EINVOICING - Status: " . $ack_statusLabel . (!empty($syncComment) ? " - " . $syncComment : "");
			$eventMessage .= "\nFlowID=" . $flowId;
			$eventMessage .= "\nCallID " . $callRef;

			$resLogEvent = $this->addEvent('STATUS', $eventLabel, $eventMessage, $object);
			if ($resLogEvent < 0) {
				dol_syslog(__METHOD__ . " Failed to log event for flowId: {$flowId}", LOG_WARNING);
			}

			return $flowId;
		} else {
			$this->error = $langs->trans("ErrorSendingInvoiceToPDP");
			$this->error .= '<br>HTTP ' . $response['status_code'];
			if (!empty($response['errorCode'])) {
				$this->error .= ' - ' . $response['errorCode'] . (empty($response['errorMessage']) ? '' : ' - ' . $response['errorMessage']);
			}
			if (!empty($response['curl_error_no'])) {
				$this->error .= ' - Curl error ' . $response['curl_error_no'] . (empty($response['curl_error_msg']) ? '' : ' - ' . $response['curl_error_msg']);
			}
			$this->errors[] = $this->error;
			return 0;
		}
	}

	/**
	 * Send a sample electronic invoice for testing purposes.
	 * This function generates a sample invoice and sends it to PDP
	 *
	 * @param 	int<0,1>		$onlymake		1=to only make the sample
	 * @return 	string[]|0	 					True if the invoice was successfully sent, false otherwise.
	 */
	public function sendSampleInvoice($onlymake = 0)
	{
		global $langs;

		$outputLog = array(); // Feedback to display
		$invoice_path = null;

		// Generate sample invoice
		$einvoicing = new EInvoicing($this->db);

		try {
			if ((float) DOL_VERSION < 24.0) {
				$resarray = $this->exchangeProtocol->generateSampleInvoiceOld($einvoicing);
			} else {
				$resarray = $this->exchangeProtocol->generateSampleInvoice($einvoicing);
			}
			if ($resarray === -1) {
				$this->errors[] = $this->exchangeProtocol->error;
				return 0;
			}
			$invoice_path = (string) $resarray['path'];
			$ref = $resarray['ref'];
		} catch (Exception $e) {
			$this->errors[] = $e->getMessage();
			return 0;
		}

		if (empty($ref) || empty($invoice_path)) {
			$this->errors[] = 'Failed to generate the sample invoice';
			return 0;
		}


		// invoice_path is something like "/.../documents/einvoicing/temp/..." or "/.../documents/facture/temp/..."

		$invoice_path = (string) $invoice_path;  // Phan workaround
		if ($invoice_path) {
			$outputLog[] = "Sample invoice generated successfully.";
		}


		// Stop here if we want just generation
		if ($onlymake) {
			return $outputLog;
		}


		$file_info = pathinfo($invoice_path);
		$fileext = $file_info['extension'] ?? ''; // Should be "pdf" or "xml" depending on the protocol
		if (strtolower($fileext) == 'pdf') {
			$mime_type = 'application/pdf';
		} else {
			$mime_type = 'text/xml';
		}

		// Format PDP resource Url
		/*
		$uuid = $this->generateUuidV4(); // UUID used to correlate logs between Dolibarr and PDP
		$resource = 'flows';
		$urlparams = array(
			'Request-Id' => $uuid,
		);
		$resource .= '?' . http_build_query($urlparams);
		*/

		// Extra headers
		$extraHeaders = [
			'Content-Type' => 'multipart/form-data'
		];

		// Params
		$params = [
			'flowInfo' => json_encode([
				"trackingId" => $ref,
				"name" => "Invoice_" . $ref,
				"flowSyntax" => "Factur-X",
				"flowProfile" => "CIUS",
				"sha256" => hash_file('sha256', $invoice_path)
			]),
			'file' => new CURLFile($invoice_path, $mime_type, basename($invoice_path))
		];

		$response = $this->callApi("flows", "POSTALREADYFORMATED", $params, $extraHeaders, 'send_sample_invoice');

		if ($response['status_code'] == 200 || $response['status_code'] == 202) {
			$flowId = $response['response']['flowId'];
			$outputLog[] = "Sample invoice sent successfully.";

			// Try to retrieve flow using callback information
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
				'retrieve_sample_invoice'
			);

			if ($response['status_code'] == 200 || $response['status_code'] == 202) {
				include_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
				$tmpobject = new Facture($this->db);
				$output_path = getMultidirTemp($tmpobject, 'einvoicing') . '/test_retrieved_invoice.' . $fileext;

				file_put_contents($output_path, $response['response']);

				$outputLog[] = "Sample invoice retrieved successfully.";

				return $outputLog;
			} else {
				$this->errors[] = "Failed to retrieve sample invoice.";
				return 0;
			}
		} else {
			$errormsg = $langs->trans("ErrorSendingInvoiceToPDP");
			$errormsg .= '<br>HTTP ' . $response['status_code'];
			if (!empty($response['errorCode'])) {
				$errormsg .= ' - ' . $response['errorCode'] . (empty($response['errorMessage']) ? '' : ' - ' . $response['errorMessage']);
			}
			if (!empty($response['curl_error_no'])) {
				$errormsg .= ' - Curl error ' . $response['curl_error_no'] . (empty($response['curl_error_msg']) ? '' : ' - ' . $response['curl_error_msg']);
			}
			$this->error = $errormsg;
			$this->errors[] = $errormsg;
			return 0;
		}
	}

	/**
	 * Call the provider API.
	 *
	 * @param string 						$resource 	    Resource relative URL ('token', 'healthcheck', 'Flows', or others)
	 * @param 'POST'|'GET'|'HEAD'|'PUT'|'PUTALREADYFORMATED'|'POSTALREADYFORMATED'|'DELETE' $method         HTTP method (dolibarr's types)
	 * @param string|false 	$params 	    Options for the request (JSON encoded)
	 * @param array<string, string>         $extraHeaders   Optional additional headers
	 * @param string|null                   $callType       Functional type of the API call for logging purposes (e.g., 'sync_flows', 'send_invoice')
	 *
	 * @return array{status_code:int,response:null|string|array<string,mixed>|mixed,errorCode?:string,errorMessage?:string,id?:int,call_id?:?string,curl_error_no?:int,curl_error_msg?:string}
	 */
	public function callApi($resource, $method, $params = false, $extraHeaders = [], $callType = '')
	{
		global $conf, $user;

		// Validate configuration
		if (!$this->validateConfiguration(($callType == 'get_access_token') ? 0 : 1)) {
			return array('status_code' => 400, 'response' => $this->errors);
		}

		require_once DOL_DOCUMENT_ROOT . '/core/lib/geturl.lib.php';

		// The OAuth token endpoint lives on the auth base (/oauth2/), not the Flow API base. This applies to
		// every token grant: client_credentials, authorization_code and refresh_token.
		$url = $this->getApiUrl(($resource == 'token' || $callType == 'get_access_token') ? 'auth' : 'api') . $resource;
		if ($resource == 'validation_reports' || strpos($resource, 'french_directory') === 0) {
			// validation_reports and the French directory lookup both live on the AP API base (v1.beta).
			$url = $this->getApiUrl('ap_api') . $resource;
		}
		if (strpos($resource, 'afnor-directory/') === 0) {
			// Standardized AFNOR Directory Service (XP Z12-013) lives on its own base. The 'afnor-directory/'
			// prefix is only a routing marker and is stripped before appending the real resource path.
			$url = $this->getApiUrl('afnor_directory') . substr($resource, strlen('afnor-directory/'));
		}

		$httpheader = array();
		if (!isset($extraHeaders['Content-Type'])) {
			$httpheader[] = 'Content-Type: application/json';
			$httpheader[] = 'Accept: application/json';
		}

		foreach ($extraHeaders as $key => $value) {
			$httpheader[] = $key . ': ' . $value;
		}

		// check or get access token
		if ($resource != 'token') {
			if (!empty($this->tokenData['token'])) {
				if ($this->isTokenExpired()) {
					$this->refreshAccessToken(); // This will fill again $this->tokenData['token'] and save it in database
				}
			} else {
				$this->getAccessToken(); // This will fill again $this->tokenData['token'] and save it in database
			}
		}

		// Add Authorization header if we have a token
		if (!empty($this->tokenData['token']) && $resource != 'token') {
			$httpheader[] = 'Authorization: Bearer ' . $this->tokenData['token'];
		}

		/*
		if (is_array($params)){
			$params = http_build_query($params);
		}*/

		$response = getURLContent($url, $method, $params, 1, $httpheader, array('http', 'https'), 0, -1, 0, 0, array(), '_einvoicing');

		// Neither key is guaranteed: getURLContent() sets 'content' only when the body is not empty
		// (an Access Point answering 200 with no body, as a healthcheck does, has none), and on a curl
		// failure - timeout, DNS, refused connection - it returns the error keys without 'http_code'.
		// Reading them raw turned those two ordinary situations into PHP warnings.
		$status_code = $response['http_code'] ?? 0;
		$content = $response['content'] ?? '';
		$body = 'Error';

		if ($status_code == 200 || $status_code == 202) {
			$body = $content;
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
				'response' => 'Error ' . $status_code . ' - ' . (string) $content
			);
			if (!empty($response['curl_error_no'])) {
				$returnarray['curl_error_no'] = $response['curl_error_no'];
			}
			if (!empty($response['curl_error_msg'])) {
				$returnarray['curl_error_msg'] = $response['curl_error_msg'];
			}
			// An error body is not always the {errorCode, errorMessage} pair this expects: a plain JSON
			// string decodes into a string, and indexing that is a fatal on PHP 8, not a warning.
			// Each key is set only when the body really carries it: callers tell "no message" from
			// "empty message" with isset(), and would otherwise report an empty error instead of
			// falling back on the HTTP code.
			$contentarray = json_decode((string) $content, true);
			if (is_array($contentarray)) {
				if (isset($contentarray['errorCode'])) {
					$returnarray['errorCode'] = (string) $contentarray['errorCode'];
				}
				if (isset($contentarray['errorMessage'])) {
					$returnarray['errorMessage'] = (string) $contentarray['errorMessage'];
				}
			}
		}

		// Log the API call through an independent connection so the trace survives a
		// rollback of the caller's transaction on error (see logCall(), issue #291).
		$logged = $this->logCall($callType, $resource, $method, $params, $returnarray['response'], $returnarray['status_code']);
		if ($logged !== null) {
			$returnarray['id'] = $logged['id'];
			$returnarray['call_id'] = $logged['call_id'];
		}

		return $returnarray;
	}

	/**
	 * Check whether a recipient (SIREN) is routable, preferring the standardized AFNOR Directory
	 * Service (XP Z12-013) handled by the parent, and falling back to the SuperPDP specific
	 * french_directory endpoint only when the standardized lookup is not available.
	 *
	 * @param 	string 	$idprof1 	Recipient SIREN (idprof1)
	 * @return 	array{status:string,reachable:int,entries:int,active:int,unknown:int,identifier:string,linestatus:string,platform:string,effectivedate:int,message:string,httpcode:int}
	 */
	public function checkRecipientDirectory($idprof1)
	{
		// Standardized AFNOR directory check first (works for any conformant Approved Platform).
		$result = parent::checkRecipientDirectory($idprof1);
		if (in_array($result['status'], array('routable', 'inactive', 'absent'), true)) {
			// The standardized answer carried the line status: it decides, and nothing else may
			// override it. This is what keeps the specific endpoint from re-introducing a wrong
			// positive on a line the annuaire reports as not open.
			return $result;
		}
		if ($result['status'] === 'undetermined') {
			// Lines exist for that SIREN but the platform did not report their status, and it cannot be
			// asked for it. Its own directory endpoint does carry that information: use it to settle the
			// answer instead of leaving the user with a shrug.
			return $this->settleUndeterminedDirectory($idprof1, $result);
		}

		// Standardized lookup unavailable or errored: fall back to the SuperPDP specific endpoint.
		return $this->checkRecipientDirectoryLegacy($idprof1);
	}

	/**
	 * Settle a standardized directory answer that came back without line statuses, using the SuperPDP
	 * specific french_directory endpoint as a tie-breaker.
	 *
	 * The status is missing from some standardized answers of this platform (observed on the lines
	 * addressed by the bare SIREN and not open yet), and it cannot be requested: 'directoryLineStatus'
	 * is not one of the values the search accepts in 'fields', and reading the line on its own omits it
	 * as well. The platform's own endpoint answers about the very same lines, at the same moment, and
	 * does carry the flag: on every line where both answers report it, the boolean matches the
	 * standardized status (true for 'Enabled', false for 'Upcoming'). So it is used here to conclude,
	 * and only here:
	 * - it settles a non-conclusive answer, it never overrides a status the standardized answer gave ;
	 * - it stays silent (the answer remains non-conclusive) when it knows nothing of that SIREN or
	 *   fails, since the standardized annuaire does hold lines for it.
	 *
	 * The boolean does not tell a line waiting for its effective date from a closed one, so a negative
	 * verdict says the recipient cannot receive without claiming which of the two it is.
	 *
	 * @param 	string 	$idprof1 	Recipient SIREN (idprof1)
	 * @param 	array{status:string,reachable:int,entries:int,active:int,unknown:int,identifier:string,linestatus:string,platform:string,effectivedate:int,message:string,httpcode:int} 	$result 	Non-conclusive result of the standardized check
	 * @return 	array{status:string,reachable:int,entries:int,active:int,unknown:int,identifier:string,linestatus:string,platform:string,effectivedate:int,message:string,httpcode:int}
	 */
	private function settleUndeterminedDirectory($idprof1, $result)
	{
		$legacy = $this->checkRecipientDirectoryLegacy($idprof1);

		if ($legacy['entries'] == 0 || $legacy['status'] === 'error') {
			// Nothing to settle with: the specific endpoint failed, or knows no entry for a SIREN the
			// standardized annuaire holds lines for. Keep the non-conclusive answer rather than
			// contradicting the standardized one.
			return $result;
		}

		// 'active' counts entries flagged as able to receive with an effective date already reached,
		// 'unknown' those flagged the same way with no date in the payload: both are entries this
		// platform reports as receiving, which is exactly the status the standardized answer dropped.
		if ($legacy['active'] > 0 || $legacy['unknown'] > 0) {
			$result['status'] = 'routable';
			$result['reachable'] = 1;
			if (!empty($legacy['identifier'])) {
				$result['identifier'] = $legacy['identifier'];
			}
		} else {
			$result['status'] = 'inactive';
			$result['reachable'] = 0;
			$result['linestatus'] = $legacy['linestatus'];
			$result['effectivedate'] = $legacy['effectivedate'];
		}
		// Provenance: this verdict does not come from the standardized answer displayed by the annuaire
		// consultation, so a user comparing the two must be able to tell where it comes from.
		$result['message'] = 'EInvoicingDirectoryStatusFromPlatform';

		return $result;
	}

	/**
	 * Legacy fallback: check the recipient reception address through the SuperPDP specific directory
	 * endpoint (GET french_directory/entries on the v1.beta base). Kept for platforms or environments
	 * where the standardized AFNOR Directory Service is not reachable.
	 *
	 * This endpoint only exposes a boolean 'is_active', which does not tell an open reception address
	 * from one that is merely declared with a future effective date: it cannot conclude 'routable' on
	 * its own, see below.
	 *
	 * @param 	string 	$idprof1 	Recipient SIREN (idprof1)
	 * @return 	array{status:string,reachable:int,entries:int,active:int,unknown:int,identifier:string,linestatus:string,platform:string,effectivedate:int,message:string,httpcode:int}
	 */
	private function checkRecipientDirectoryLegacy($idprof1)
	{
		$result = array('status' => 'error', 'reachable' => -1, 'entries' => 0, 'active' => 0, 'unknown' => 0, 'identifier' => '', 'linestatus' => '', 'platform' => '', 'effectivedate' => 0, 'message' => '', 'httpcode' => 0);

		$siren = preg_replace('/[^0-9]/', '', (string) $idprof1);
		if ($siren === '') {
			$result['message'] = 'EInvoicingDirectoryNoSiren';
			return $result;
		}

		$resource = 'french_directory/entries?number=' . urlencode($siren);
		$response = $this->callApi($resource, 'GET', false, array(), 'precheck_directory');
		$result['httpcode'] = (int) (isset($response['status_code']) ? $response['status_code'] : 0);

		if ($result['httpcode'] != 200) {
			$result['message'] = isset($response['errorMessage']) ? $response['errorMessage'] : ('HTTP ' . $result['httpcode']);
			return $result;
		}

		$data = array();
		if (isset($response['response']['data']) && is_array($response['response']['data'])) {
			$data = $response['response']['data'];
		}
		$result['entries'] = count($data);
		$upcoming = 0;
		$upcomingdate = 0;
		foreach ($data as $entry) {
			if (empty($entry['is_active'])) {
				continue;
			}
			// 'is_active' says the entry is declared, not that it can receive today: the annuaire also
			// holds addresses bound to a platform with a future effective date ('Upcoming' in the
			// standardized directory). When the entry carries such a date, honour it.
			$startdate = $this->getDirectoryEntryStartDate($entry);
			if (!empty($startdate) && $startdate > dol_now()) {
				$upcoming++;
				if (empty($upcomingdate) || $startdate < $upcomingdate) {
					$upcomingdate = $startdate;
				}
				continue;
			}
			if (empty($startdate)) {
				// No effective date at all in the payload: an open address and one that only opens later
				// are indistinguishable, so this entry cannot support a positive answer.
				$result['unknown']++;
				if ($result['identifier'] === '' && !empty($entry['identifier'])) {
					$result['identifier'] = (string) $entry['identifier'];
				}
				continue;
			}
			$result['active']++;
			if ($result['identifier'] === '' && !empty($entry['identifier'])) {
				$result['identifier'] = (string) $entry['identifier'];
			}
		}

		if ($result['entries'] == 0) {
			// Recipient not present in the directory at all.
			$result['status'] = 'absent';
			$result['reachable'] = 0;
		} elseif ($result['active'] > 0) {
			$result['status'] = 'routable';
			$result['reachable'] = 1;
		} elseif ($result['unknown'] > 0) {
			// Entries exist and are flagged active, but nothing in the payload dates them: stay
			// non-conclusive (the caller keeps failing open) instead of showing the recipient as
			// reachable, which is the one answer that let's a doomed transmission through.
			$result['status'] = 'undetermined';
			$result['reachable'] = -1;
			$result['message'] = 'EInvoicingDirectoryNoLineStatus';
		} elseif ($upcoming > 0) {
			// Declared, with an effective date still in the future: cannot receive yet.
			$result['status'] = 'inactive';
			$result['reachable'] = 0;
			$result['linestatus'] = 'Upcoming';
			$result['effectivedate'] = $upcomingdate;
		} else {
			// Present but no active routing line (reason NON_TRANSMISE): still cannot receive.
			$result['status'] = 'inactive';
			$result['reachable'] = 0;
		}

		return $result;
	}

	/**
	 * Read the effective date of a legacy french_directory entry, when the payload carries one.
	 *
	 * The endpoint is not covered by XP Z12-013 and its entries have been seen with only the boolean
	 * 'is_active', so the date is looked up under the names the SuperPDP payloads and the AFNOR
	 * vocabulary use for it. Nothing is guessed from the value alone: an unparsable or absent date
	 * returns 0, which the caller treats as "not datable" rather than as "open now".
	 *
	 * @param 	array<string,mixed> 	$entry 	One entry of the french_directory answer
	 * @return 	int 							Timestamp of the effective date, 0 if the entry has none
	 */
	private function getDirectoryEntryStartDate($entry)
	{
		$candidates = array('start_date', 'startDate', 'date_start', 'effective_date', 'effectiveDate', 'activation_date', 'activationDate', 'valid_from', 'validFrom');

		foreach ($candidates as $key) {
			if (empty($entry[$key]) || !is_string($entry[$key])) {
				continue;
			}
			$value = trim($entry[$key]);
			// The platform answers dates both ISO ('2026-08-07') and French ('07-08-2026', '07/08/2026'),
			// which are ambiguous for strtotime(), hence the explicit formats.
			foreach (array('Y-m-d', 'd-m-Y', 'd/m/Y') as $format) {
				$date = DateTime::createFromFormat($format . '|', $value, new DateTimeZone('UTC'));
				if ($date instanceof DateTime && $date->format($format) === $value) {
					return (int) $date->getTimestamp();
				}
			}
			// Timestamps with a time part or a timezone (ISO 8601) are left to the generic parser.
			$parsed = strtotime($value);
			if ($parsed !== false && preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}[T ]/', $value)) {
				return (int) $parsed;
			}
		}

		return 0;
	}

	/**
	 * Synchronize flows with Access Point.
	 *
	 * TODO Code very similar with syncFlows of other providers
	 *
	 * @param   int   $syncFromDate     Timestamp from which to start synchronization. If 0, begins from epoch (1970-01-01).
	 * @param   int   $limit            Maximum number of flows to synchronize. 0 means no limit.
	 * @return 	bool|array{res:int, messages:string[], totalFlows?:?int, alreadyExist?:int, syncedFlows?:int, batchlimit?:int, actions?:array<string,array{actionurl:string,actioncode:string,action:string,businessmessage:string}>, details?:string[]} 	True on success, false on failure along with messages, details for debugging, and suggested optional actions.
	 */
	public function syncFlows($syncFromDate = 0, $limit = 0)
	{
		global $db, $langs, $user;
		global $form;

		if (!is_object($form)) {
			require_once DOL_DOCUMENT_ROOT . '/core/class/html.form.class.php';
			$form = new Form($db);
		}

		// Start the run with a clean "last unprocessed invoice" diagnostic; failed flows re-create it.
		$this->clearIncomingDiagnosticFiles();

		$results_messages = array();	// result message (technical error)
		$actions = array();				// business message (manual action to do)

		$resource = 'flows/search';
		$uuid = $this->generateUuidV4(); // UUID used to correlate logs between Dolibarr and PDP TODO : Store it somewhere
		$urlparams = array(
			'Request-Id' => $uuid,
		);
		$resource .= '?' . http_build_query($urlparams);

		//self::$EINVOICING_LAST_IMPORT_KEY = $uuid;
		self::$EINVOICING_LAST_IMPORT_KEY = dol_print_date(dol_now(), 'dayhourlog');

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

		dol_syslog(__METHOD__ . " syncFlows start from " . dol_print_date($dateafter, 'standard') . " limit " . $limit, LOG_DEBUG);
		dol_syslog(__METHOD__ . " syncFlows start from " . dol_print_date($dateafter, 'standard') . " limit " . $limit, LOG_DEBUG, 0, "_einvoicing");

		// If limit is 0, we first need to get the total number of flows to sync because AP set a default limit of 25 if not specified
		/* response param "total" not supported by SuperPDP
		if ($limit == 0) {
			$jsonparams = json_encode($params);
			$response = $this->callApi($resource, "POST", $jsonparams);

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
				dol_syslog(__METHOD__ . " No flows to synchronize.", LOG_DEBUG, 0, "_einvoicing");

				$results_messages[] = "No flows to synchronize.";
				return array('res' => 1, 'messages' => $results_messages);
			}

			dol_syslog(__METHOD__ . " Total flows to synchronize: " . $totalFlows, LOG_DEBUG);
			dol_syslog(__METHOD__ . " Total flows to synchronize: " . $totalFlows, LOG_DEBUG, 0, "_einvoicing");
		}
		*/

		// This search endpoint does not paginate: it ignores offset and page, caps a batch at 100 rows
		// and reports no total. Left to itself it also applies its own default of 25, so a synchronization
		// only ever saw the 25 oldest flows of the window - and once those had all been processed it kept
		// reporting "25 skipped, 0 new" run after run while recent flows sat just behind them, out of
		// reach for good. The single cursor this API offers is updatedAfter, so the batches walk it.
		$batchlimit = $limit;						// What the operator asked for, kept for the recap
		$maxToProcess = $limit;						// Ceiling on flows actually synchronized, 0 for none
		$batchSize = getDolGlobalInt('EINVOICING_FLOWS_SYNC_CALL_SIZE', 100);
		if ($batchSize <= 0) {
			$batchSize = 100;
		}

		$totalFlows = null;							// Not part of the answer of this API
		$error = 0;
		$alreadyExist = 0;
		$syncedFlows = 0;
		$postponedFlows = 0;	// Flows left unread on purpose, retried on the next run (see 'postponeflow')
		$call_id = null;
		$i = 0;
		$flow = null;
		$batchNumber = 0;
		$cursor = dol_print_date($dateafter, '%Y-%m-%dT%H:%M:%S.000Z', 'gmt');

		while (true) {
			$batchNumber++;
			if ($batchNumber > self::MAX_SYNC_BATCHES) {
				// Said out loud rather than stopping quietly: the window is not exhausted, and the operator
				// has to run the synchronization again to walk further.
				$results_messages[] = "Stopped after " . self::MAX_SYNC_BATCHES . " batches, the window still holds flows. Run the synchronization again to continue.";
				break;
			}

			$params['where']['updatedAfter'] = $cursor;
			$params['limit'] = $batchSize;

			// Only the first call is typed as a synchronization: it is the one creating the Call row the
			// whole run is reported on, and one run must stay one line of history.
			$response = $this->callApi($resource, "POST", json_encode($params), [], ($batchNumber == 1 ? "synchronization" : ""));

			if ($response['status_code'] != 200) {
				$this->errors[] = "Failed to retrieve flows for synchronization." . ' (HTTP ' . $response['status_code'] . ')';
				$results_messages[] = "Failed to retrieve flows for synchronization." . ' (HTTP ' . $response['status_code'] . ')';

				dol_syslog(__METHOD__ . " Failed to retrieve the list of flows for synchronization.", LOG_DEBUG, 0, "_einvoicing");
				return array('res' => 0, 'messages' => $results_messages);
			}

			if ($batchNumber == 1) {
				$call_id = $response['call_id'] ?? null;
			}

			$results = $response['response']['results'] ?? array();
			if (empty($results)) {
				break;
			}

			// The batch really returned may be smaller than the one asked for, the API caps it.
			$effectiveLimit = (int) ($response['response']['limit'] ?? $batchSize);

			// Since AP may not return flows in the order they want (by updatedAt ASC), we sort them here.
			// On the sub-second key, because the cursor moves along it.
			dol_syslog(__METHOD__ . " Sort the flows per updatedAt", LOG_DEBUG, 0, "_einvoicing");
			usort($results, function ($a, $b) {
				return strcmp(self::updatedAtSortKey($a['updatedAt'] ?? ''), self::updatedAtSortKey($b['updatedAt'] ?? ''));
			});

			// Clean already processed flows from the list
			$alreadyProcessedFlowIds = [];
			$flowIds = array_column($results, 'flowId');
			$sanitizedFlowIds = array();
			foreach ($flowIds as $flowId) {
				$sanitizedFlowIds[] = "'" . $db->escape($flowId) . "'";
			}
			if (count($sanitizedFlowIds)) {
				$sql = "SELECT flow_id FROM " . MAIN_DB_PREFIX . "einvoicing_document";
				$sql .= " WHERE flow_id IN (" . implode(',', $sanitizedFlowIds) . ")";
				$resql = $db->query($sql);
				if ($resql) {
					while ($obj = $db->fetch_object($resql)) {
						$alreadyProcessedFlowIds[$obj->flow_id] = $obj->flow_id;
					}
				} else {
					$this->errors[] = "Failed to retrieve from database the list of flows already processed. ".$this->db->lasterror();
					$results_messages[] = "Failed to retrieve from database the list of flows already processed. ".$this->db->lasterror();

					dol_syslog(__METHOD__ . " Failed to retrieve flows already processed among the list of flows received. ".$this->db->lasterror(), LOG_DEBUG, 0, "_einvoicing");
					return array('res' => 0, 'messages' => $results_messages);
				}
			}

			// Loop on each flow received in list
			$i = 0;
			foreach ($response['response']['results'] ?? [] as $flow) {
				$i++;
				if (in_array($flow['flowId'], $alreadyProcessedFlowIds)) {
					dol_syslog(__METHOD__ . " #" . $i . " Flow " . $flow['flowId'] . " already processed, discard it.", LOG_DEBUG, 0, "_einvoicing");
					$alreadyExist++;
					continue;
				}

				$rescode = '';
				try {
					// Process flow

					dol_syslog(__METHOD__ . " #" . $i . " Process flow " . $flow['flowId'], LOG_DEBUG, 0, "_einvoicing");

					// Do a unitary sync of flow $flow['flowId'] instead the global transaction $call_id
					$res = $this->syncFlow($flow['flowId'], $call_id);

					// If res < 0, rollback
					if ($res['res'] < 0) {
						if (!empty($res['postponeflow'])) {
							// This flow could not be read, but nothing was stored for it: it stays pending and
							// the next synchronization will try it again, so no invoice is lost. Report it with
							// the action to do and carry on, instead of stalling this batch - and every flow
							// behind it - on a problem that has to be fixed on the access point side anyway.
							$actions[$res['actioncode']] = array(
								'actionurl' => ($res['actionurl'] ?? ''),
								'actioncode' => $res['actioncode'],
								'action' => $res['action'],
								'businessmessage' => $langs->trans("CantReadTheDocumentOfTheImportedInvoice", $flow['flowId'])
									. $form->textwithpicto('', "ERROR_SYNCFLOW - Failed to synchronize flow " . $flow['flowId'] . ": " . $res['message'], 1, 'help', '', 0, 2, 'help')
							);

							dol_syslog(__METHOD__ . " Flow " . $flow['flowId'] . " postponed: " . $res['message'], LOG_WARNING, 0, "_einvoicing");
							$results_messages[] = "Flow " . $flow['flowId'] . " postponed, it will be retried on the next synchronization: " . $res['message'];

							$postponedFlows++;
							continue;
						}

						if (isset($res['action']) && $res['action'] != '') {	// Save business errors if it is
							$rescode = $res['actioncode'] ?? '0';
							// Set the result code and label into array $actions.
							$actions[$rescode] = array(
								'actionurl' => $res['actionurl'],
								'actioncode' => ($res['actioncode'] ?? '0'),
								'action' => $res['action']
							);

							if ($rescode == 'THIRDPARTY_NOT_FOUND') {
								$infostring = '';
								foreach ($res['actiondata'] ?? [] as $datakey => $dataval) {
									if ($datakey && $dataval) {
										$infostring .= ($infostring ? ', ' : '').$datakey.': '.$dataval;
									}
								}
								$actions[$rescode]['businessmessage'] = $langs->trans("CantFindThirdpartyFromTheImportedInvoice", $infostring);
								// Add technical message in tooltip on the picto
								$actions[$rescode]['businessmessage'] .= $form->textwithpicto('', "ERROR_SYNCFLOW - Failed to synchronize flow " . $flow['flowId'] . ": " . $res['message'], 1, 'help', '', 0, 2, 'help');
							}
							if ($rescode == 'PRODUCT_NOT_FOUND') {
								$infostring = '';
								foreach ($res['actiondata'] ?? [] as $datakey => $dataval) {
									if ($datakey && $dataval) {
										$infostring .= ($infostring ? ', ' : '').$datakey.': '.$dataval;
									}
								}
								$actions[$rescode]['businessmessage'] = $langs->trans("CantFindProductFromTheImportedInvoice", $infostring);
								// Add technical message in tooltip on the picto
								$actions[$rescode]['businessmessage'] .= $form->textwithpicto('', "ERROR_SYNCFLOW - Failed to synchronize flow " . $flow['flowId'] . ": " . $res['message'], 1, 'help', '', 0, 2, 'help');
							}
						}
						dol_syslog(__METHOD__ . " Failed to synchronize flow " . $flow['flowId'] . ": " . $res['message'], LOG_DEBUG, 0, "_einvoicing");
						$results_messages[] = "ERROR_SYNCFLOW - Failed to synchronize flow " . $flow['flowId'] . ": " . $res['message'];

						$error++;
					}

					// If res == 0, commit but count it as already existed
					if ($res['res'] == 0) {
						$results_messages[] = "<span class=\"opacitylow\">Flow " . $flow['flowId'] . " skipped: " . $res['message'] . "</span>";
						$alreadyExist++;
						//$lastsuccessfullSyncronizedFlow = $flow['flowId'];
					}

					// If res == 1, commit and count as synced
					if ($res['res'] > 0) {
						$syncedFlows++;
						//$lastsuccessfullSyncronizedFlow = $flow['flowId'];
					}
				} catch (Exception $e) {
					$results_messages[] = "Exception occurred while synchronizing flow " . $flow['flowId'] . ": " . $e->getMessage();
					$error++;
				}

				if ($error > 0) {
					if (in_array($rescode, array('THIRDPARTY_NOT_FOUND','PRODUCT_NOT_FOUND'))) {
						$results_messages[] = "Aborting synchronization due to a business error. There is a manual action to do.";
					} else {
						$results_messages[] = "Aborting synchronization due to errors.";
					}
					break;
				}

				// The ceiling counts flows, not batches: a batch holds up to a hundred of them, so
				// checking it only between batches would blow straight past what was asked for.
				if ($maxToProcess > 0 && $syncedFlows >= $maxToProcess) {
					break;
				}
			}

			if ($error > 0) {
				break;
			}
			if ($maxToProcess > 0 && $syncedFlows >= $maxToProcess) {
				break;
			}
			if (count($results) < $effectiveLimit) {		// Short batch: the window is exhausted
				break;
			}

			// updatedAfter is exclusive and several flows share the very same updatedAt, so the cursor
			// stops just before the last timestamp of the batch instead of on it: those flows come back in
			// the next batch, this time with the siblings that did not fit, and the ones already handled
			// are discarded by the lookup above. Costs one overlap, never skips a flow.
			$sortKeys = array();
			foreach ($results as $resultFlow) {
				$sortKeys[] = self::updatedAtSortKey($resultFlow['updatedAt'] ?? '');
			}
			$lastKey = end($sortKeys);
			$previousCursor = '';
			foreach ($results as $resultFlow) {
				if (self::updatedAtSortKey($resultFlow['updatedAt'] ?? '') < $lastKey) {
					$previousCursor = $resultFlow['updatedAt'];
				}
			}

			if ($previousCursor !== '') {
				$cursor = $previousCursor;
			} else {
				// A full batch sharing one single timestamp cannot be stepped back from without standing
				// still. Moving onto it is the only way forward, and it is worth saying.
				dol_syslog(__METHOD__ . " A whole batch of " . count($results) . " flows shares updatedAt " . $results[0]['updatedAt'] . ", moving the cursor onto it: flows sharing that timestamp beyond the batch cannot be reached.", LOG_WARNING, 0, "_einvoicing");
				$results_messages[] = "A whole batch shares the timestamp " . $results[0]['updatedAt'] . ", raise EINVOICING_FLOWS_SYNC_CALL_SIZE if flows are missing.";
				$cursor = end($results)['updatedAt'];
			}
		}



		$globalres = ($error > 0 ? -1 : 1);

		$globalresultmessage = ($globalres == 1) ? $langs->trans("SyncCompletedSuccessfuly") . ($batchlimit > 0 ? ' <span class="opacitylow">(' . $langs->trans("maxNumberToProcess") . ': ' . $batchlimit . ")</span>" : "") : ($langs->trans("SyncAborted", $i, $limit, ($flow['flowId'] ?? 'N/A')));

		dol_syslog(__METHOD__ . " syncFlows end : " . $globalresultmessage, LOG_DEBUG, 0, "_einvoicing");


		$messages = array();
		$messages[] = $globalresultmessage;
		if ($globalres == 1) {
			if (!is_null($totalFlows)) {
				$messages[] = $langs->trans("TotalToSync") . ": <b>" . $totalFlows . "</b>";
			}
		}
		$messages[] = $langs->trans("TotalSkippedSync") . ": <b>" . $alreadyExist . "</b> - " . $langs->trans("TotalNewSync") . ": <b>" . $syncedFlows . "</b>";
		if ($postponedFlows > 0) {
			// Counted apart from the skipped ones: those flows were not stored, they come back next run
			$messages[] = $langs->trans("TotalPostponedSync") . ": <b>" . $postponedFlows . "</b>";
		}

		// Processing result that will be saved in DB
		$processingResult = '';
		if (!empty($results_messages)) {
			$processingResult .= implode("<br>----------------------<br>", $results_messages);
		}
		$processingResult .= "<br>----------------------<br>" . implode("<br>", $messages);
		$processingResult = "Processing result:<br>" . $processingResult;

		// Save sync recap (only when this sync is attached to a Call row; otherwise $sql would be undefined/stale)
		if ($call_id) {
			$sql = "UPDATE " . MAIN_DB_PREFIX . "einvoicing_call";
			$sql .= " SET totalflow = " . (is_null($totalFlows) ? "null" : ((int) $totalFlows)) . ",
                successflow = " . ((int) $syncedFlows) . ",
                skippedflow = " . ((int) $alreadyExist) . ",
                batchlimit = " . ((int) $batchlimit) . ",
                processing_result = '" . $db->escape($processingResult) . "',
                    fk_user_modif = " . ((int) $user->id) . "
            WHERE call_id = '" . $db->escape($call_id) . "'";
			$db->query($sql);
		}

		// Return result
		// 'actions' contains the action to do (in case of business error)
		// 'details' will contain all technical error (for Log)
		return [
			'res' => $globalres,
			'messages' => $messages,
			'totalFlows' => $totalFlows,
			'alreadyExist' => $alreadyExist,
			'syncedFlows' => $syncedFlows,
			'batchlimit' => $batchlimit,
			'actions' => $actions,
			'details' => $results_messages
		];
	}

	/**
	 * Comparable key for the updatedAt of a flow.
	 *
	 * The platform does not pad the fractional seconds to a fixed width - '.47288Z' and '.626638Z'
	 * both occur - so the raw strings cannot be compared to each other, and strtotime() drops the
	 * fraction entirely, which is precisely what the cursor needs. Padding it to six digits gives a
	 * key that sorts on the microsecond.
	 *
	 * @param	string	$updatedAt	Timestamp as returned by the platform
	 * @return	string				Key to sort and compare on
	 */
	private static function updatedAtSortKey($updatedAt)
	{
		if (!preg_match('/^([^.Z]+)(?:\.(\d+))?/', (string) $updatedAt, $reg)) {
			return (string) $updatedAt;
		}

		return $reg[1] . '.' . str_pad(substr(isset($reg[2]) ? $reg[2] : '', 0, 6), 6, '0');
	}

	/**
	 * Sync a given flow data.
	 * Called by syncFlows() for example.
	 *
	 * @param string 		$flowId        	FlowId
	 * @param string|null 	$call_id  		Call ID for logging purposes
	 * @return array{res:int<-1,1>, message:string, postponeflow?:int, actioncode?:string|null, actionurl?:string|null, action?:string|null} Returns array with 'res' (1 on success, 0 if exists or already processed, -1 on failure) with a 'message' and for business errors an optional 'actioncode', 'actionurl' and 'action'. 'postponeflow' marks a failure that stored nothing, so the batch may go on and the flow be retried later.
	 */
	public function syncFlow($flowId, $call_id = null)
	{
		global $db, $conf, $user, $langs;

		dol_include_once('einvoicing/class/document.class.php');
		$einvoicing = new EInvoicing($db);

		// call API to get flow details
		$flowResource = 'flows/' . $flowId;
		$flowUrlparams = array(
			'docType' => 'Metadata', 				// docType can be 'Metadata' (JSON), 'Original', 'Converted' or 'ReadableView'
		);
		$flowResource .= '?' . http_build_query($flowUrlparams);
		$response = $this->callApi(
			$flowResource,
			"GET",
			false,
			['Accept' => 'application/octet-stream'],
			''			// No call type, so won't be logged
		);

		if ($response['status_code'] != 200) {
			return array('res' => -1, 'message' => "ERROR_FLOW_METADATA Failed to retrieve flow metadata for flowId: " . $flowId);
		}

		// Process flow data
		$flowData = array();
		try {
			$flowData = json_decode($response['response'], true);
		} catch (Exception $e) {
			return array('res' => -1, 'message' => "ERROR_FLOW_METADATA Failed to parse the json answer for flowId: " . $flowId);
		}

		$document = new Document($this->db);
		$document->date_creation        = dol_now();
		$document->fk_user_creat        = $user->id;
		$document->call_id              = $call_id;		// Call id for unitary fetch
		$document->flow_id              = $flowId;
		$document->tracking_idref       = $flowData['trackingId'] ?? (getDolGlobalString('EINVOICING_PDP', 'REF').' '.$flowId);
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
			$document->submittedat = $dt->getTimestamp();	// $dt is already in GMT in received , no need to compensate with the database timezone with db->idate() to get it GMT
		} else {
			$document->submittedat = null;
		}
		if (!empty($flowData['updatedAt'])) {
			$dt = new DateTimeImmutable($flowData['updatedAt'], new DateTimeZone('UTC'));
			$document->updatedat = $dt->getTimestamp();		// $dt is already in GMT, no need to compensate with the database timezone with db->idate() to get it GMT
		} else {
			$document->updatedat = null;
		}
		$document->provider             = getDolGlobalString('EINVOICING_PDP') ?? null;
		$document->entity               = $conf->entity;
		$document->flow_uiid            = $flowData['uuid'] ?? null;

		if (getDolGlobalString('EINVOICING_DEBUG_MODE')) {
			$document->response_for_debug = $response['response'];
		}



		$returnRes = 1;
		$returnMessage = "";
		switch ($document->flow_type) {
			// CustomerInvoice
			case "CustomerInvoice":
				// 1. link flow to customer invoice
				require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
				$factureObj = new Facture($this->db);
				$document->fk_element_type = $factureObj->element;
				if (!empty($document->tracking_idref)) {
					$res = $factureObj->fetch(0, $document->tracking_idref);
					if ($res < 0) {
						return array('res' => -1, 'message' => "ERROR_FETCH_INVOICE Failed to fetch customer invoice for flowId: " . $flowId);
					} elseif ($res == 0) {
						$returnRes = 1;
						$returnMessage = 'Source invoice not found for '.$document->flow_id;
					} else {
						// TODO: save received converted document as attachment to customer invoice
						/*
						try {
							$db->begin();

							$db->commit();
						} catch(Exception $e)
						{
							$db->rollback();
						}
						*/
					}
				} else {
					$returnRes = 1;
					$returnMessage = 'Source invoice not found for '.$document->flow_id;
				}

				$document->fk_element_id = !empty($factureObj->id) ? $factureObj->id : 0;
				$document->tracking_idref = !empty($factureObj->ref) ? $factureObj->ref : $document->tracking_idref . ' (NOTFOUND)'; // Probably the customer invoice was sent from another system that use the same PDP account

				break;
				// SupplierInvoice
			case "SupplierInvoice":
				// --- Fetch received documents (Einvoice)
				$document->fk_element_type = 'invoice_supplier';

				// AFNOR XP Z12-013: a supplier invoice to book is an INCOMING flow (issued by the
				// platform to us). An outgoing/errored "SupplierInvoice" flow is NOT a received
				// invoice and must not be imported as a facture fournisseur — otherwise lifecycle
				// actions (e.g. a refusal) fail on the PDP side with "no matching invoices found".
				if ($document->flow_direction !== 'In') {
					$document->fk_element_id = 0;
					$returnRes = 1;		// mark the flow as processed, just do not create an invoice
					$returnMessage = "Skipped SupplierInvoice flow " . $flowId . " (flowDirection=" . ($document->flow_direction ?: 'null') . ", not an incoming invoice)";
					dol_syslog(__METHOD__ . " " . $returnMessage, LOG_WARNING, 0, "_einvoicing");
					break;
				}

				// Retrieve the PDF file converted by Access Point
				$receivedFile = null;
				/*
				$flowResource = 'flows/' . $flowId;
				$flowUrlparams = array(
					'docType' => 'Converted', 						// docType can be 'Metadata' (JSON), 'Original', 'Converted' or 'ReadableView'
				);
				$flowResource .= '?' . http_build_query($flowUrlparams);
				$flowResponse = $this->callApi(
					$flowResource,
					"GET",
					false,
					['Accept' => 'application/octet-stream']
				);

				if ($flowResponse['status_code'] != 200) {
					return array('res' => -1, 'message' => "ERROR_FLOW_GETCONV Failed to retrieve 'Converted' document for SupplierInvoice flow (flowId: ".$flowId.")".(empty($flowResponse['errorMessage']) ? '' : ' - '.$flowResponse['errorMessage']));
				}
				$receivedFile = $flowResponse['response'];
				*/

				// Retrieve also PDF file generated by Access Point
				$ReadableViewFile = null;
				/*
				$flowResource = 'flows/' . $flowId;
				$flowUrlparams = array(
					'docType' => 'ReadableView', 					// docType can be 'Metadata' (JSON), 'Original', 'Converted' or 'ReadableView'
				);
				$flowResource .= '?' . http_build_query($flowUrlparams);
				$flowResponse = $this->callApi(
					$flowResource,
					"GET",
					false,
					['Accept' => 'application/octet-stream']
				);
				if ($flowResponse['status_code'] != 200) {
					return array('res' => -1, 'message' => "ERROR_FLOW_GETREADABLE Failed to retrieve ReadableView document for SupplierInvoice flow (flowId: ".$flowId.")".(empty($flowResponse['errorMessage']) ? '' : ' - '.$flowResponse['errorMessage']));
				}
				if ($flowResponse['status_code'] != 200) {
					// We disable this error, getting the readable file is optional.
					//return array('res' => -1, 'message' => "ERROR_FLOW_GETREADABLE Failed to retrieve ReadableView document for SupplierInvoice flow (flowId: $flowId)");
				} else {
					$ReadableViewFile = $flowResponse['response'];	// This is a string with PDF file content.
				}
				*/

				// Retrieve the invoice document, in whichever shape this module is able to read
				$tmpProtocolManager = new ProtocolManager($this->db);
				$importable = $this->fetchImportableFlowDocument($flowId, $tmpProtocolManager);

				if (empty($importable['protocol'])) {
					// Nothing readable in this flow. Return without storing the document, so the flow stays
					// pending and a later synchronization imports it once the access point side is fixed:
					// a received invoice must never be silently dropped.
					$errorcode = ($importable['fetched'] > 0 ? 'ERROR_FLOW_NOT_SUPPORTED_PROTOCOL' : 'ERROR_FLOW_GETDOC');

					$action = $langs->trans('SetTheAccessPointConversionFormat');
					if ($importable['client_not_configured']) {
						$action = $langs->trans('AccessPointConversionFormatNotSet') . ' ' . $action;
					}

					return array(
						'res' => -1,
						'postponeflow' => 1,
						'message' => $errorcode . " No document this module can read for SupplierInvoice flow (flowId: " . $flowId . ") - " . implode(' | ', $importable['attempts']),
						'actioncode' => 'CONVERSION_FORMAT_NOT_SUPPORTED',
						'actionurl' => '',
						'action' => $action,
						'actiondata' => array()
					);
				}

				// Both are set together, the guard above is what guarantees the file is there
				$receivedFile = (string) $importable['file'];
				$exchangeProtocol = $importable['protocol'];

				$exceptionmessage = '';

				// No transaction opened here: createSupplierInvoiceFromSource() owns it. It synchronizes
				// the vendor first, out of transaction, then imports the invoice atomically - so a business
				// error on the invoice (product not found, ...) no longer rolls back the created thirdparty.
				try {
					// Try to create the supplier + product + invoice
					$res = $exchangeProtocol->createSupplierInvoiceFromSource($receivedFile, $ReadableViewFile, $flowId);

					if ($res['res'] < 0) {
						$retarray = array(
							'res' => -1,
							'message' => "Failed to create supplier invoice from E-invoice document for flowId: " . $flowId . ". " . $res['message']
						);
						$retarray['actioncode'] = $res['actioncode'] ?? null;
						$retarray['actionurl'] = $res['actionurl'] ?? null;
						$retarray['action'] = $res['action'] ?? null;
						$retarray['actiondata'] = $res['actiondata'] ?? null;

						return $retarray;
					} else {
						// Complete the document object with the created supplier invoice details
						$supplierInvoiceObj = new FactureFournisseur($this->db);
						$resFetch = $supplierInvoiceObj->fetch($res['res']);
						$document->fk_element_id = !empty($supplierInvoiceObj->id) ? $supplierInvoiceObj->id : 0;
						$document->tracking_idref = !empty($supplierInvoiceObj->ref) ? $supplierInvoiceObj->ref : 'Error'; // Should always be found here
						$cleanedXmlData = Document::cleanXmlData($res['xml_data'] ?? '');
						if (!empty($cleanedXmlData) && Document::checkXmlDataMaxSize($cleanedXmlData)) {
							$document->xml_data = $cleanedXmlData;
						}

						//return array('res' => 0, 'message' => "supplier invoice already exists for flowId: " . $flowId . ". " . $res['message']);
						$returnRes = 1;		// If invoice did already exists, we process one more line from list of flows, so we must return 1, even if nothing was done.
						$returnMessage = "Supplier invoice " . $supplierInvoiceObj->ref . " created or already existing for flowId: " . $flowId . ". " . $res['message'];
					}
				} catch (Exception $e) {
					$exceptionmessage = $e->getMessage();
				}

				if ($exceptionmessage) {
					throw new Exception($exceptionmessage);
				}

				break;

				// Customer Invoice LC (life cycle)
			case "CustomerInvoiceLC":
				// 1. link flow document to customer invoice
				require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';

				// This part seems useless:, if invoice ref not found we continue the same way if found
				/*
				$factureObj = new Facture($this->db);
				$document->fk_element_type = $factureObj->element;

				$refinvoice = $document->tracking_idref;

				$res = 0;
				if ($refinvoice) {
					$res = $factureObj->fetch(0, $refinvoice);		// tracking_idref is field trackingId into the CDAR message that contains invoice ref with AP
				} else {
					return array('res' => -1, 'message' => "FlowId: ".$flowId." - Receive flow with type CustomerInvoiceLC without any ref of invoice");
				}
				if ($factureObj->entity && $factureObj->entity != $conf->entity) {
					return array('res' => -1, 'message' => "FlowId: ".$flowId." - Failed to fetch customer invoice ref '" . $document->tracking_idref."' in entity ".$conf->entity);
				}
				if ($res < 0) {
					return array('res' => -1, 'message' => "FlowId: ".$flowId." - Failed to fetch customer invoice ref '" . $document->tracking_idref . "'");
				}
				*/

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
					return array('res' => -1, 'message' => "Failed to retrieve flow details for flowId: " . $flowId);
				}
				$cdarXml = $flowResponse['response'];

				dol_include_once('einvoicing/class/utils/CdarHandler.class.php');

				$cdarHandler = new CdarHandler($db);

				try {
					// Parse the CDAR document (returns an array)
					$cdarDocument = $cdarHandler->readFromString($cdarXml);

					//var_dump($cdarDocument); exit;

					// Check if parsing was successful
					if (empty($cdarDocument) || !isset($cdarDocument['AcknowledgementDocument'])) {
						return array('res' => -1, 'message' => "FlowId: " . $flowId . " - Failed to parse CDAR document");
					}

					$factureObj = new Facture($this->db);
					$document->fk_element_type = $factureObj->element;

					// Get Invoice Reference from CDAR
					$issuerAssignedID = $cdarDocument['AcknowledgementDocument']['ReferenceReferencedDocument']['IssuerAssignedID'];

					$res = $factureObj->fetch(0, $issuerAssignedID);
					if ($res < 0) {
						return array(
							'res' => -1,
							'message' => "FlowId " . $flowId . " - Failed to fetch customer invoice using CDAR IssuerAssignedID/ref: " . $issuerAssignedID
						);
					}
					if ($factureObj->entity && $factureObj->entity != $conf->entity) {
						return array('res' => -1, 'message' => "Processing flowId: " . $flowId . " - Failed to fetch customer invoice ref " . $document->tracking_idref . " in entity " . $conf->entity);
					}

					$document->fk_element_id = !empty($factureObj->id) ? $factureObj->id : 0;
					$document->tracking_idref = !empty($factureObj->ref) ? $factureObj->ref : $issuerAssignedID . ' (NOTFOUND)'; // Probably the customer invoice is sent from another system that use the same PDP account

					// TODO: Consider creating a new customer invoice if invoice not found even if this should not happen ?

					// Retrieve reference data
					$refDoc = $cdarDocument['AcknowledgementDocument']['ReferenceReferencedDocument'];

					// Fill CDAR information in the document
					$document->cdar_lifecycle_code = $refDoc['ProcessConditionCode'];
					$document->cdar_lifecycle_label = $refDoc['ProcessCondition'];
					$document->cdar_reason_code = isset($refDoc['StatusReasonCode']) ? $refDoc['StatusReasonCode'] : '';
					$document->cdar_reason_desc = isset($refDoc['StatusReason']) ? $refDoc['StatusReason'] : '';
					$document->cdar_reason_detail = isset($refDoc['StatusIncludedNoteContent']) ? $refDoc['StatusIncludedNoteContent'] : '';

					$exceptionmessage = '';
					$db->begin();

					try {
						// Update einvoice status with received CDAR status
						if ($factureObj->id > 0) {
							$syncStatus = $refDoc['ProcessConditionCode'];
							$syncValidationStatus = $document->ack_status;
							$syncValidationComment = $document->ack_info;
							$syncComment = $document->cdar_reason_detail ? $document->cdar_reason_detail : '';
							if (!$syncStatus && $document->ack_status == 'Error') {
								$syncStatus = $einvoicing::STATUS_ERROR;
								$syncComment = $document->ack_info;
							}
							$einvoicing->insertOrUpdateExtLink($factureObj->id, $factureObj->element, $flowId, $syncStatus, $factureObj->ref, $syncComment);

							$einvoicing->storeStatusMessage($document->fk_element_id, $document->fk_element_type, $document->cdar_lifecycle_code, $syncComment, $document->flow_direction, $flowId, $syncValidationStatus, $syncValidationComment, $document->submittedat, $document->cdar_reason_code);
						} else {
							dol_syslog(__METHOD__ . " Customer invoice not found for flowId: {$flowId}, so we save the flow into document table but we don't create an entry into einvoicing_extlinks table", LOG_WARNING); // This can happen if the invoice was sent from another system using the same PDP account
						}

						// Log an event in the invoice timeline
						$statusLabel = $document->cdar_lifecycle_label;
						$reasonDetail = $document->cdar_reason_detail ? " - {$document->cdar_reason_detail}" : '';


						$eventLabel = "EINVOICING - Status: {$statusLabel}";
						$eventMessage = "EINVOICING - Status: {$statusLabel}{$reasonDetail}";

						$resLogEvent = $this->addEvent('STATUS', $eventLabel, $eventMessage, $factureObj);
						if ($resLogEvent < 0) {
							dol_syslog(__METHOD__ . " Failed to log event for flowId: {$flowId}", LOG_WARNING);
						}

						$db->commit();
					} catch (Exception $e) {
						$exceptionmessage = $e->getMessage();

						$db->rollback();
					}

					if ($exceptionmessage) {
						throw new Exception($exceptionmessage);
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
						'res' => -1,
						'message' => "FlowId " . $flowId . " - Error processing CDAR document - " . $e->getMessage()
					);
				}

				break;

				// Supplier Invoice LC (life cycle)
			case "SupplierInvoiceLC":
				// This is a supplier invoice lifecycle message that we sent to PDP.
				// We link it to the supplier invoice in dolibarr and we check validation response.
				// Since we trigger an AJAX every X seconds to get validation response while validation of sent LC message remains in the "Pending" status after sending. That will be a double check of validation of sent LC message in case ajax call it not triggered or failed for some reason.

				require_once DOL_DOCUMENT_ROOT . '/fourn/class/fournisseur.facture.class.php';
				$document->fk_element_type = 'invoice_supplier';

				// An incoming one is a different thing entirely: it is a status the VENDOR issues about
				// one of its own invoices - "Cashed in" (212) above all, which is the answer to the
				// payment we reported with a 211. We never sent it, so it has no row in
				// einvoicing_lifecycle_msg and the flowId lookup below cannot resolve it: it used to end
				// up stored with neither its lifecycle code nor its supplier invoice, so nothing ever
				// surfaced on the invoice.
				if ($document->flow_direction == 'In') {
					$resIncoming = $this->processIncomingSupplierInvoiceStatus($flowId, $document, $einvoicing);

					$returnRes = $resIncoming['res'];
					$returnMessage = $resIncoming['message'];
					break;
				}

				// Fetch the linked supplier invoice using flowId stored in einvoicing_lifecycle_msg table when the LC message was sent
				$resFetchStatusMessages = $einvoicing->fetchStatusMessages($flowId);
				if (!is_array($resFetchStatusMessages) /* || $resFetchStatusMessages < 0 */ || empty($resFetchStatusMessages)) {
					$returnRes = 0;
					$returnMessage = "Failed to fetch status messages for flowId: " . $flowId;
				} else {
					// Fetch ref and id to link the document to supplier invoice
					$supplierInvoiceObj = new FactureFournisseur($this->db);
					$resFetch = $supplierInvoiceObj->fetch($resFetchStatusMessages['element_id']);
					if ($resFetch <= 0) {
						$returnRes = 0;
						$returnMessage = "Failed to fetch supplier invoice for flowId: " . $flowId . " using rowid from einvoicing_lifecycle_msg table: " . $resFetchStatusMessages['rowid'];
					} else {
						$document->fk_element_id = !empty($supplierInvoiceObj->id) ? $supplierInvoiceObj->id : 0;
						$document->tracking_idref = !empty($supplierInvoiceObj->ref) ? $supplierInvoiceObj->ref : '(NOTFOUND)'; // Should always be found here
					}

					// Update LC message status in einvoicing_lifecycle_msg table based on validation response
					$syncStatusComment = $document->cdar_reason_detail ? $document->cdar_reason_detail : '';
					$syncValidationStatus = $document->ack_status;
					$syncValidationComment = $document->ack_info;

					$exceptionmessage = '';
					$db->begin();

					try {
						$einvoicing->updateStatusMessageValidation($resFetchStatusMessages['rowid'], $syncStatusComment, $syncValidationStatus, $syncValidationComment);

						$db->commit();
					} catch (Exception $e) {
						$exceptionmessage = $e->getMessage();

						$db->rollback();
					}

					if ($exceptionmessage) {
						throw new Exception($exceptionmessage);
					}
				}
				break;
			case "":
				// This is likely a validation response for an invoice that was previously sent, and not a lifecycle message.
				// Since we trigger an AJAX every X seconds to get validation response while an invoice remains in the "Pending" status after sending, we should not
				// need to handle this case and to store all validation responses in document table.
				// TODO: Move all this case or condition into a function. We should also call this into the Ajax component that update the status of an einvoice sent.

				// In this case, the trackingId may be null.
				// - If trackingId is set, it is used to find the invoice as usual.
				// - If trackingId is null, we try to retrieve the linked invoice using the flowId
				//   stored in the einvoicing_extlinks table when the invoice was sent.

				$obj = null;
				$document->fk_element_type = 'facture';
				if (empty($document->tracking_idref)) {
					// Try to get tracking_idref from einvoicing_extlinks table
					$sql = "SELECT d.syncref as tracking_idref";
					$sql .= " FROM " . MAIN_DB_PREFIX . "einvoicing_extlinks as d";
					$sql .= " WHERE d.flow_id = '" . $db->escape($flowId) . "'";
					$resql = $db->query($sql);
					if ($resql) {
						$obj = $db->fetch_object($resql);
						if ($obj && !empty($obj->tracking_idref)) {
							$document->tracking_idref = $obj->tracking_idref;
						} else {
							//return array('res' => 0, 'message' => "No tracking_idref found in einvoicing_extlinks table for flowId: " . $flowId);
						}
					} else {
						// return array('res' => 0, 'message' => "Failed to query einvoicing_extlinks table for flowId: " . $flowId);
						$returnRes = 0;
					}
				}

				if (!empty($document->tracking_idref) && is_object($obj)) {
					require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
					$factureObj = new Facture($this->db);
					$res = $factureObj->fetch(0, $document->tracking_idref);
					if ($res < 0) {
						return array('res' => -1, 'message' => "Failed to fetch customer invoice for flowId: " . $flowId . " using syncref from einvoicing_extlinks table: " . $obj->tracking_idref);
					}
					$document->fk_element_id = !empty($factureObj->id) ? $factureObj->id : 0;
					$document->tracking_idref = !empty($factureObj->ref) ? $factureObj->ref : $document->tracking_idref . ' (NOTFOUND)'; // Probably the customer invoice is sent from another system that use the same PDP account

					// If ack_status is Error, and there is no entry in einvoicing_extlinks table, or there is an entry with status Awaiting Validation we log an event in the invoice and we add an entry in einvoicing_extlinks table with status Error
					// Should never happen because we make an ajax call every x seconds when an invoice is in status Pending after sending it
					// we maintain this code to handle old einvoices sent before table einvoicing_extlinks was created
					// TODO : REMOVE THIS CODE IN A FUTURE
					if ($document->ack_status == 'Error' && !empty($factureObj->id)) {
						// SQL query to check whether: there is no entry in the einvoicing_extlinks table, or there is an entry with status Awaiting Validation
						$sql = "SELECT d.syncstatus as status";
						$sql .= " FROM " . MAIN_DB_PREFIX . "einvoicing_extlinks as d";
						$sql .= " WHERE d.fk_element = " . ((int) $factureObj->id);
						$sql .= " AND d.element_type = '" . $db->escape($factureObj->element) . "'";
						$resql = $db->query($sql);
						$needToInsertExtLink = 0;
						if ($resql) {
							$obj = $db->fetch_object($resql);
							if (!$obj) {
								$needToInsertExtLink = 1;
							} elseif ($obj && $obj->status == $einvoicing::STATUS_AWAITING_VALIDATION) {
								$needToInsertExtLink = 1;
							}
						}

						if ($needToInsertExtLink) {
							$exceptionmessage = '';
							$db->begin();

							try {
								$einvoicing->insertOrUpdateExtLink($factureObj->id, $factureObj->element, $flowId, $einvoicing::STATUS_ERROR, $factureObj->ref, $document->ack_info);

								// Log an event in the invoice timeline
								$statusLabel = $document->ack_status;
								$reasonDetail = $document->ack_info ? " - {$document->ack_info}" : '';

								$eventLabel = "EINVOICING - Status: {$statusLabel}";
								$eventMessage = "EINVOICING - Status: {$statusLabel}{$reasonDetail}";

								$resLogEvent = $this->addEvent('STATUS', $eventLabel, $eventMessage, $factureObj);
								if ($resLogEvent < 0) {
									dol_syslog(__METHOD__ . " Failed to log event for flowId: {$flowId}", LOG_WARNING);
								}

								$db->commit();
							} catch (Exception $e) {
								$exceptionmessage = $e->getMessage();

								$db->rollback();
							}

							if ($exceptionmessage) {
								throw new Exception($exceptionmessage);
							}
						}
					}
				} else {
					$document->fk_element_id = 0;
					$document->tracking_idref = 'NOTFOUND'; // Probably the customer invoice is sent from another system that use the same PDP account and the PDP flow does not contain trackingId (Should not happen)
				}
				break;
		}

		$res = $document->create($user);
		if ($res < 0) {
			//print_r($document->errors);
			return array('res' => -1, 'message' => "Failed to store flow data for flowId: " . $flowId . ". Errors: " . implode(", ", $document->errors));
		}

		return array('res' => $returnRes, 'message' => $returnMessage);
	}

	/**
	 * Pick, among the documents the access point holds for a flow, the first one this module can read.
	 *
	 * A flow carries its invoice in several shapes: 'Converted' is the invoice rewritten into the
	 * syntax configured on the access point account, 'Original' is what the issuer really sent, and
	 * 'ReadableView' is the human readable copy - which, on an access point that builds it as a
	 * Factur-X PDF, carries the same data again.
	 *
	 * 'Converted' comes first because it is the one that shields the import from an issuer emitting a
	 * syntax this module does not read - UBL, in particular, belongs to the French socle but has no
	 * implementation here. But it depends on a setting that lives on the access point account, outside
	 * Dolibarr: left unset, the platform refuses to produce the document at all; set to a syntax this
	 * module does not support, it produces one that cannot be imported. Neither case says anything
	 * about the other documents of the same flow, so they are tried in turn rather than failing the
	 * flow on the first miss.
	 *
	 * @param	string			$flowId				Identifier of the flow to read
	 * @param	ProtocolManager	$protocolManager	Protocol factory used to recognize the documents
	 * @return	array{file:?string,protocol:?AbstractProtocol,protocol_name:string,doc_type:string,fetched:int,attempts:string[],client_not_configured:bool}	The importable document, or a null protocol and the reason each shape was rejected
	 */
	private function fetchImportableFlowDocument($flowId, $protocolManager)
	{
		$result = array(
			'file' => null,
			'protocol' => null,
			'protocol_name' => '',
			'doc_type' => '',
			'fetched' => 0,				// nb of documents the access point did return, whatever their syntax
			'attempts' => array(),
			'client_not_configured' => false
		);

		// EINVOICING_PREFER_ORIGINAL: fetch the issuer's Original document (its Factur-X, which carries
		// the human-readable PDF) before the Converted one, so the created supplier invoice keeps the PDF.
		$docTypeOrder = getDolGlobalString('EINVOICING_PREFER_ORIGINAL')
			? array('Original', 'Converted', 'ReadableView')
			: array('Converted', 'Original', 'ReadableView');
		foreach ($docTypeOrder as $docType) {
			$flowResponse = $this->fetchFlowData($flowId, $docType, 'get_flow_for_supplier_invoice');

			if ($flowResponse['status_code'] != 200) {
				if (isset($flowResponse['errorCode']) && $flowResponse['errorCode'] == 'CLIENT_NOT_CONFIGURED') {
					// The access point has no conversion syntax configured for this client
					$result['client_not_configured'] = true;
				}
				$result['attempts'][] = $docType . ": HTTP " . $flowResponse['status_code'] . (empty($flowResponse['errorMessage']) ? '' : ' - ' . $flowResponse['errorMessage']);
				continue;
			}

			$result['fetched']++;

			$content = (string) $flowResponse['response'];
			$protocolName = $protocolManager->detectProtocolFromContent($content);
			if (empty($protocolName)) {
				$result['attempts'][] = $docType . ": unrecognized syntax";
				continue;
			}

			$protocol = $protocolManager->getProtocol($protocolName);
			if (empty($protocol)) {
				$result['attempts'][] = $docType . ": " . $protocolName . " is not supported";
				continue;
			}

			if ($docType != 'Converted') {
				dol_syslog(__METHOD__ . " No usable 'Converted' document for flowId " . $flowId . " (" . implode(' | ', $result['attempts']) . "), reading the '" . $docType . "' one instead", LOG_WARNING, 0, "_einvoicing");
			}

			$result['file'] = $content;
			$result['protocol'] = $protocol;
			$result['protocol_name'] = $protocolName;
			$result['doc_type'] = $docType;
			break;
		}

		return $result;
	}

	/**
	 * Record a lifecycle status the vendor issued about one of its invoices, onto the supplier
	 * invoice it refers to.
	 *
	 * This is the mirror of what the CustomerInvoiceLC case does for the statuses our own customers
	 * send us: read the CDAR, resolve the invoice it points at, and store the status on it.
	 *
	 * Never returns a negative result for a status it cannot attach: a vendor may perfectly well
	 * report on an invoice this Dolibarr does not hold (the invoice was refused, or the same access
	 * point account is shared with another system), and failing the flow would stall the whole
	 * synchronization on it, run after run. The flow is stored either way, so nothing is lost.
	 *
	 * @param	string		$flowId			Flow identifier of the lifecycle message
	 * @param	Document	$document		Flow document being built, completed here with the CDAR data
	 * @param	EInvoicing	$einvoicing		E-invoicing helper of the running synchronization
	 * @return	array{res:int, message:string}	1 when the status was attached, 0 when it was only stored
	 */
	private function processIncomingSupplierInvoiceStatus($flowId, $document, $einvoicing)
	{
		global $db;

		require_once DOL_DOCUMENT_ROOT . '/fourn/class/fournisseur.facture.class.php';
		dol_include_once('einvoicing/class/utils/CdarHandler.class.php');

		$flowResource = 'flows/' . $flowId . '?' . http_build_query(array('docType' => 'Original'));
		$flowResponse = $this->callApi($flowResource, "GET", false, array('Accept' => 'application/octet-stream'));
		if ($flowResponse['status_code'] != 200) {
			return array('res' => -1, 'message' => "Failed to retrieve flow details for flowId: " . $flowId);
		}

		$cdarHandler = new CdarHandler($db);
		$cdarDocument = $cdarHandler->readFromString($flowResponse['response']);
		if (empty($cdarDocument) || empty($cdarDocument['AcknowledgementDocument']['ReferenceReferencedDocument'])) {
			return array('res' => -1, 'message' => "FlowId: " . $flowId . " - Failed to parse CDAR document");
		}

		$refDoc = $cdarDocument['AcknowledgementDocument']['ReferenceReferencedDocument'];

		$document->cdar_lifecycle_code = $refDoc['ProcessConditionCode'];
		$document->cdar_lifecycle_label = isset($refDoc['ProcessCondition']) ? $refDoc['ProcessCondition'] : '';
		$document->cdar_reason_code = isset($refDoc['StatusReasonCode']) ? $refDoc['StatusReasonCode'] : '';
		$document->cdar_reason_desc = isset($refDoc['StatusReason']) ? $refDoc['StatusReason'] : '';
		$document->cdar_reason_detail = isset($refDoc['StatusIncludedNoteContent']) ? $refDoc['StatusIncludedNoteContent'] : '';

		// The referenced document is the vendor invoice, identified the way its issuer numbered it:
		// that is our ref_supplier, and the issuing party is the vendor it belongs to.
		$vendorReference = isset($refDoc['IssuerAssignedID']) ? (string) $refDoc['IssuerAssignedID'] : '';
		$vendorLegalId = isset($refDoc['IssuerTradeParty']['GlobalID']) ? (string) $refDoc['IssuerTradeParty']['GlobalID'] : '';

		$document->tracking_idref = $vendorReference;

		if ($vendorReference === '') {
			dol_syslog(__METHOD__ . " FlowId " . $flowId . " carries no IssuerAssignedID, nothing to attach the status to", LOG_WARNING);
			return array('res' => 0, 'message' => "FlowId " . $flowId . " - Vendor lifecycle status with no invoice reference");
		}

		$supplierInvoiceId = $this->findSupplierInvoiceByVendorReference($vendorReference, $vendorLegalId);
		if ($supplierInvoiceId <= 0) {
			dol_syslog(__METHOD__ . " No supplier invoice found for vendor reference " . $vendorReference . " (vendor " . $vendorLegalId . "), flowId " . $flowId, LOG_WARNING);
			return array('res' => 0, 'message' => "FlowId " . $flowId . " - No supplier invoice matching the vendor reference " . $vendorReference);
		}

		$supplierInvoice = new FactureFournisseur($this->db);
		if ($supplierInvoice->fetch($supplierInvoiceId) <= 0) {
			return array('res' => 0, 'message' => "FlowId " . $flowId . " - Failed to load supplier invoice id " . $supplierInvoiceId);
		}

		$document->fk_element_id = $supplierInvoice->id;
		$document->tracking_idref = $supplierInvoice->ref;

		$statusComment = $document->cdar_reason_detail ? $document->cdar_reason_detail : $document->cdar_reason_desc;

		$exceptionmessage = '';
		$db->begin();

		try {
			// The flow_id of the link is left alone on purpose: on a supplier invoice it points at the
			// received invoice document, which stays the source of its XML. Only the status moves.
			$einvoicing->insertOrUpdateExtLink($supplierInvoice->id, $supplierInvoice->element, '', $document->cdar_lifecycle_code, '', $statusComment);

			$einvoicing->storeStatusMessage(
				$supplierInvoice->id,
				$supplierInvoice->element,
				$document->cdar_lifecycle_code,
				$statusComment,
				$document->flow_direction,
				$flowId,
				$document->ack_status,
				$document->ack_info,
				$document->submittedat,
				$document->cdar_reason_code
			);

			$db->commit();
		} catch (Exception $e) {
			$exceptionmessage = $e->getMessage();

			$db->rollback();
		}

		if ($exceptionmessage) {
			throw new Exception($exceptionmessage);
		}

		$statusLabel = $document->cdar_lifecycle_label ? $document->cdar_lifecycle_label : $document->cdar_lifecycle_code;
		$reasonDetail = $document->cdar_reason_detail ? " - " . $document->cdar_reason_detail : '';
		$this->addEvent('STATUS', "EINVOICING - Status: " . $statusLabel, "EINVOICING - Status: " . $statusLabel . $reasonDetail, $supplierInvoice);

		return array('res' => 1, 'message' => "FlowId " . $flowId . " - Vendor status " . $document->cdar_lifecycle_code . " recorded on supplier invoice " . $supplierInvoice->ref);
	}

	/**
	 * Find the supplier invoice a vendor lifecycle status refers to.
	 *
	 * A vendor reference is only unique per vendor, never globally, so it is only trusted alone when
	 * it matches exactly one invoice. When several vendors happen to use the same numbering, the
	 * legal identifier carried by the CDAR settles it; when it cannot, no invoice is returned rather
	 * than the wrong one.
	 *
	 * @param	string	$vendorReference	Invoice number as assigned by the vendor (BT-1 of the referenced invoice)
	 * @param	string	$vendorLegalId		Legal identifier of the issuing party, empty when the CDAR carries none
	 * @return	int							Supplier invoice id, 0 when there is no single certain match
	 */
	private function findSupplierInvoiceByVendorReference($vendorReference, $vendorLegalId)
	{
		global $db;

		$sql = "SELECT f.rowid, s.siren, s.siret, s.tva_intra";
		$sql .= " FROM " . $db->prefix() . "facture_fourn as f";
		$sql .= " INNER JOIN " . $db->prefix() . "societe as s ON s.rowid = f.fk_soc";
		$sql .= " WHERE f.ref_supplier = '" . $db->escape($vendorReference) . "'";
		$sql .= " AND f.entity IN (" . getEntity('facture_fourn') . ")";

		$resql = $db->query($sql);
		if (!$resql) {
			dol_syslog(__METHOD__ . " " . $db->lasterror(), LOG_ERR);
			return 0;
		}

		$candidates = array();
		while ($obj = $db->fetch_object($resql)) {
			$candidates[] = $obj;
		}
		$db->free($resql);

		if (count($candidates) == 1) {
			return (int) $candidates[0]->rowid;
		}
		if (empty($candidates) || $vendorLegalId === '') {
			return 0;
		}

		// Several invoices carry that number: only the one whose vendor is the issuer of the status.
		$matches = array();
		foreach ($candidates as $candidate) {
			if ($vendorLegalId === (string) $candidate->siren
				|| $vendorLegalId === (string) $candidate->siret
				|| $vendorLegalId === (string) $candidate->tva_intra) {
				$matches[] = (int) $candidate->rowid;
			}
		}

		return count($matches) == 1 ? $matches[0] : 0;
	}

	/**
	 * Send status message of an invoice to PDP/PA
	 *
	 * @param mixed $object Invoice object (CustomerInvoice or SupplierInvoice)
	 * @param int $statusCode   Status code to send (see class constants for available codes)
	 * @param string $reasonCode Reason code to send (optional)
	 * @param array{amount?:float,breakdown?:array<array{vatrate:float,amount:float}>} $paymentData Cashed amount (TTC) for status 212 (Encaissee), mandatory content of the CDAR (rule BR-FR-CDV-14)
	 *
	 * @return array{res:int, message:string}       Returns array with 'res' (1 on success, -1 on failure) with a 'message'.
	 */
	public function sendStatusMessage($object, $statusCode, $reasonCode = '', $paymentData = array())
	{
		global $langs, $db;

		$res = 1;
		$message = '';

		if (!in_array($object->element, ['facture', 'invoice_supplier'])) {
			$res = -1;
			$message = 'SendStatusMessage Not does not support this object type: ' . $object->element;
			return ['res' => $res, 'message' => $message];
		}

		//Clear reason code if status code is -1
		if ($reasonCode == '-1') {
			$reasonCode = '';
		}


		$einvoicing = new EInvoicing($db);
		$availableStatuses = $object->element === 'invoice_supplier'
			? $einvoicing->getEinvoiceStatusOptions(1, 1, 1)
			: [$einvoicing::STATUS_PAID => $einvoicing::STATUS_LABEL_KEYS[$einvoicing::STATUS_PAID]];	// Required to send the new status of customer invoices. We may need to consider a new method for obtaining these statuses or update the current method.
		if (!array_key_exists($statusCode, $availableStatuses)) {
			$res = -1;
			$message = 'SendStatusMessage Unsupported status code: ' . $statusCode;
			return ['res' => $res, 'message' => $message];
		}
		$statusLabelToSend = $einvoicing->getStatusLabel($statusCode);

		dol_include_once('/einvoicing/class/utils/CdarHandler.class.php');
		$cdarHandler = new CdarHandler($db);
		$result = $cdarHandler->generateCdarFile($object, $statusCode, $reasonCode, $paymentData);
		if ($result['res'] < 0) {
			$res = -1;
			$message = 'Failed to generate CDAR file: ' . $result['message'];
			return ['res' => $res, 'message' => $message];
		}

		$filepath = $result['file'];
		if (file_exists($filepath)) {
			dol_syslog(__METHOD__ . " Generated CDAR file path: " . $filepath, LOG_DEBUG, 0, "_einvoicing");

			// Extra headers
			$extraHeaders = [
				'Content-Type' => 'multipart/form-data'
			];

			// Params
			$params = [
				'flowInfo' => json_encode([
					"name" => "LC_" . $object->ref,
					"flowSyntax" => "CDAR"
				]),
				'file' => new CURLFile($filepath, 'application/xml', basename($filepath))
			];

			// Call API to send CDAR
			$response = $this->callApi("flows", "POSTALREADYFORMATED", $params, $extraHeaders, 'Send Status Message');

			if ($response['status_code'] == 200 || $response['status_code'] == 202) {
				/**
				 * We make an additional call to retrieve the acknowledgment information and update the status.
				 * However, document validation on the PDP side may take some time.
				 * Therefore, we initially set the status to "Sent".
				 *
				 * We then try to fetch the PDP validation result:
				 * - If the validation is successful, we update the status of the electronic invoice accordingly.
				 * - If the PDP validation fails, we set the status to "Error" and log the reason.
				 *
				 * If no response is available yet, we wait for the next synchronization.
				 **/

				$flowId = $response['response']['flowId'] ?? '';

				// Update einvoice status with awaiting validation
				$einvoicing = new EInvoicing($db);
				//$einvoicing->insertOrUpdateExtLink($object->id, $object->element, $flowId, EInvoicing::STATUS_AWAITING_VALIDATION, $object->ref);
				$resStoreStatus = $einvoicing->storeStatusMessage($object->id, $object->element, $statusCode, '', 'out', $flowId, '', '', '', $reasonCode);

				// Call the API to retrieve flow details and check the validation status.
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
					'Check Status validation'
				);

				if ($response['status_code'] == 200 || $response['status_code'] == 202) {
					//dol_include_once('einvoicing/class/document.class.php');

					// Process flow data
					$flowData = array();
					try {
						$flowData = json_decode($response['response'], true);
					} catch (Exception $e) {
						return array('res' => -1, 'message' => "Failed to parse the json answer for flowId: " . $flowId);
					}

					// Update einvoice status with received validation result
					$syncStatus = $einvoicing::STATUS_AWAITING_VALIDATION;
					$ack_statusLabel = $flowData['acknowledgement']['status'] ?? '';
					if ($ack_statusLabel === 'Ok') { // So status is sent and validated so we log sent status
						$syncStatus = $statusCode;
					} else {
						if ($ack_statusLabel) {
							$syncStatus = $einvoicing->getDolibarrStatusCodeFromPdpLabel($ack_statusLabel);
						}
					}

					$syncRef = $flowData['trackingId'] ?? '';
					$syncComment = $flowData['acknowledgement']['details'][0]['reasonMessage'] ?? '';
					//$einvoicing->insertOrUpdateExtLink($object->id, $object->element, $flowId, $syncStatus, $syncRef, $syncComment);
					$einvoicing->updateStatusMessageValidation($resStoreStatus, '', $ack_statusLabel, $syncComment);

					// Log an event in the invoice timeline
					$eventLabel = "EINVOICING - Send status " . $statusLabelToSend . " : " . $ack_statusLabel;
					$eventMessage = "EINVOICING - Send status " . $statusLabelToSend . " : " . $ack_statusLabel . (!empty($syncComment) ? " - " . $syncComment : "");

					$resLogEvent = $this->addEvent('STATUS', $eventLabel, $eventMessage, $object);
					if ($resLogEvent < 0) {
						dol_syslog(__METHOD__ . " Failed to log event for flowId: {$flowId}", LOG_WARNING);
					}
				} else {
					dol_syslog(__METHOD__ . " Unable to retrieve flow details after sending status message for flowId: {$flowId}. Status code: " . $response['status_code'], LOG_WARNING);
					$res = 1;
					$message = 'Failed to retrieve flow details after sending status message. Status code: ' . $response['status_code'];
				}
			} else {
				$res = -1;
				$message = 'Failed to send CDAR file to PDP. Status code: ' . $response['status_code'] . '. Message: ' . (!empty($response['response']['message'])
					? $response['response']['message']
					: ($response['errorMessage'] ?? 'No message'));
				return ['res' => $res, 'message' => $message];
			}
		} else {
			$res = -1;
			$message = 'CDAR file does not exist: ' . $filepath;
			return ['res' => $res, 'message' => $message];
		}

		return ['res' => $res, 'message' => $message];
	}
}
