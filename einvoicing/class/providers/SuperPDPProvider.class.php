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
	 * Constructor
	 *
	 * Load setup properties and last token.
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		global $conf, $langs;

		parent::__construct($db);

		$this->config = array(
			'provider_url'  => 'https://superpdp.tech/',
			'prod_auth_url' => 'https://api.superpdp.tech/oauth2/',
			'test_auth_url' => 'https://api.superpdp.tech/oauth2/',
			'prod_api_url'  => 'https://api.superpdp.tech/afnor-flow/v1/',
			'test_api_url'  => 'https://api.superpdp.tech/afnor-flow/v1/',
			'client_id'     => getDolGlobalString('EINVOICING_SUPERPDP_CLIENT_ID'.(getDolGlobalInt('EINVOICING_LIVE') ? '_PROD' : '')),
			'client_secret' => getDolGlobalString('EINVOICING_SUPERPDP_CLIENT_SECRET'.(getDolGlobalInt('EINVOICING_LIVE') ? '_PROD' : '')),
			'dol_prefix'    => getDolGlobalString('EINVOICING_PDP') == 'SUPERPDPViaPartner' ? 'EINVOICING_SUPERPDPVIAPARTNER' : 'EINVOICING_SUPERPDP',
			'live' => getDolGlobalInt('EINVOICING_LIVE', 0)
		);

		// Default mode
		$this->helpToGetCredentials = '<div class="">' . $langs->trans("EINVOICING_SUPERPDP_HELP_CREDENTIAL1") . '</div>';
		$this->helpToGetCredentials .= '<div class="margintoponly">' . $langs->trans("EINVOICING_SUPERPDP_HELP_CREDENTIAL2", '{s1}') . '</div>';
		$this->helpToGetCredentials .= '<div class="margintoponly">' . $langs->trans("EINVOICING_SUPERPDP_HELP_CREDENTIAL3", '{s2}') . '</div>';
		$this->helpToGetCredentials .= '<div class="margintoponly">' . $langs->trans("EINVOICING_SUPERPDP_HELP_CREDENTIAL4", '{s3}', '{s4}', '{s5}', '{s6}') . '</div>';

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
			if (getDolGlobalString('EINVOICING_SUPERPDP_VIAPARTNER') == 'proxy') {
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
								'superpdp_company_number' => removeAllSpaces($mysoc->idprof1),
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
				$resultget = getURLContent($proxyurl, 'POST', http_build_query($param), 1, array('Content-Type: application/x-www-form-urlencoded'));

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
				dol_syslog(__METHOD__." refresh via partner proxy failed http_code=".$httpcode, LOG_WARNING, 0, "_einvoicing");
				$this->errors[] = 'FailedToRefreshAccessTokenViaProxy';
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
				$query['superpdp_company_number'] = removeAllSpaces($mysoc->idprof1);
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

		if ($response['status_code'] === 200) {
			$returnarray['status_code'] = true;
			$nameOfAccessPoint = getDolGlobalString('EINVOICING_PDP');
			$nameOfAccessPoint = preg_replace('/ViaPartner/', '', $nameOfAccessPoint);

			$returnarray['message'] = $langs->trans('APApiReachable', $nameOfAccessPoint);
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
		$params = [
			'flowInfo' => json_encode([
				//"flowProfile" => "CIUS",
				"flowProfile" => "Extended-CTC-FR",
				"flowSyntax" => $flowSyntax,			// CII or Factur-X
				"trackingId" => $object->ref,
				"name" => "Invoice_" . $object->ref,
				"sha256" => hash_file('sha256', $invoice_path)
			]),
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
			$invoice_path = $resarray['path'];
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
	 * @return array{status_code:int,response:null|string|array<string,mixed>,errorCode?:string,errorMessage?:string,id?:int,call_id?:string}
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
				'response' => 'Error ' . $status_code . ' - ' . (string) $response['content']
			);
			if (!empty($response['curl_error_no'])) {
				$returnarray['curl_error_no'] = $response['curl_error_no'];
			}
			if (!empty($response['curl_error_msg'])) {
				$returnarray['curl_error_msg'] = $response['curl_error_msg'];
			}
			if ($contentarray = json_decode((string) $response['content'], true)) {
				$returnarray['errorCode'] = $contentarray['errorCode'];
				$returnarray['errorMessage'] = $contentarray['errorMessage'];
			}
		}

		// Log the API call if we have the functional type
		if (!empty($callType)) { // TODO : Add a parameter in module configuration to enable/disable logging
			$call = new Call($this->db);
			$call->call_id = $call->getNextCallId();
			$call->call_type = $callType ?: '';
			$call->method = ($method == 'POSTALREADYFORMATED' ? 'POST' : $method);
			$call->endpoint = '/' . $resource;
			$call->request_body = is_array($params) ? json_encode($params) : $params;
			$call->response = is_array($returnarray['response']) ? json_encode($returnarray['response']) : $returnarray['response'];
			$call->provider = $this->name;
			$call->entity = $conf->entity;
			$call->status = ($returnarray['status_code'] == 200 || $returnarray['status_code'] == 202) ? 1 : 0;

			$result = $call->create($user);

			if ($result > 0) {
				$returnarray['id'] = $call->id;
				$returnarray['call_id'] = $call->call_id;
			} else {
				dol_syslog(__METHOD__ . " Failed to log API call to PDP provider: " . $call->error . " - " . implode(',', $call->errors), LOG_ERR);
			}
		}

		return $returnarray;
	}

	/**
	 * Synchronize flows with Access Point.
	 *
	 * @param   int   $syncFromDate     Timestamp from which to start synchronization. If 0, begins from epoch (1970-01-01).
	 * @param   int   $limit            Maximum number of flows to synchronize. 0 means no limit.
	 * @return 	bool|array{res:int<-1,1>, messages:array<string>, details?:array<string>, actions?:array<string>} 	True on success, false on failure along with messages, details for debugging, and suggested optional actions.
	 */
	public function syncFlows($syncFromDate = 0, $limit = 0)
	{
		global $db, $langs, $user;
		global $form;

		if (!is_object($form)) {
			$form = new Form($db);
		}

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

		// Make a call to get all flows
		if ($limit) {
			$params['limit'] = $limit;
		}
		$jsonparams = json_encode($params);
		$response = $this->callApi($resource, "POST", $jsonparams, [], "synchronization");	// This will also create the Call entry

		if ($response['status_code'] != 200) {
			$this->errors[] = "Failed to retrieve flows for synchronization." . ' (HTTP ' . $response['status_code'] . ')';
			$results_messages[] = "Failed to retrieve flows for synchronization." . ' (HTTP ' . $response['status_code'] . ')';

			dol_syslog(__METHOD__ . " Failed to retrieve the list of flows for synchronization.", LOG_DEBUG, 0, "_einvoicing");
			return array('res' => 0, 'messages' => $results_messages);
		}

		// Some AP returns nb of lines into "total", others returns into "limit"
		$totalFlows = ($response['response']['total'] ?? null);		// If not defined (not into the spec), we set it to null
		$limitFlows = ($response['response']['limit'] ?? 0);

		$batchlimit = $limit; // Set batch limit for logging purposes
		$limit = (($limit > 0 && $limitFlows > 0) ? min($limit, $limitFlows) : ($limitFlows ? $limitFlows : $limit));

		if ($limit == 0) {
			dol_syslog(__METHOD__ . " No flows to synchronize.", LOG_DEBUG);
			dol_syslog(__METHOD__ . " No flows to synchronize.", LOG_DEBUG, 0, "_einvoicing");

			$results_messages[] = "No flows to synchronize.";
			return array('res' => 1, 'messages' => $results_messages);
		}

		// Since AP may not return flows in the order they want (by updatedAt ASC), we sort them here
		dol_syslog(__METHOD__ . " Sort the flows per updatedAt", LOG_DEBUG, 0, "_einvoicing");
		usort($response['response']['results'], static function ($a, $b) {
			return strtotime($a['updatedAt']) <=> strtotime($b['updatedAt']);
		});

		// Clean already processed flows from the list
		$alreadyProcessedFlowIds = [];
		$flowIds = array_column($response['response']['results'] ?? [], 'flowId');
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

		// Update totalFlows after filtering
		// $totalFlows = count($response['response']['results']); // TODO : VERIFY IF NEEDED
		$error = 0;
		$alreadyExist = 0;
		$syncedFlows = 0;

		// Call ID for logging purposes
		$call_id = $response['call_id'] ?? null;

		//$lastsuccessfullSyncronizedFlow = null;

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
	 * Sync a given flow data.
	 * Called by syncFlows() for example.
	 *
	 * @param string 		$flowId        	FlowId
	 * @param string|null 	$call_id  		Call ID for logging purposes
	 * @return array{res:int<-1,1>, message:string, actioncode?:string|null, actionurl?:string|null, action?:string|null} Returns array with 'res' (1 on success, 0 if exists or already processed, -1 on failure) with a 'message' and for business errors an optional 'actioncode', 'actionurl' and 'action'.
	 */
	public function syncFlow($flowId, $call_id = null)
	{
		global $db, $conf, $user;

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
						$returnMessage = 'Source invoice not found for '.$document->flowId;
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
					$returnMessage = 'Source invoice not found for '.$document->flowId;
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

				// Retrieve Original file
				$receivedFile = null;
				$flowResponse = $this->fetchFlowData($flowId, 'Original', 'get_flow_for_supplier_invoice');

				if ($flowResponse['status_code'] != 200) {
					return array('res' => -1, 'message' => "ERROR_FLOW_GETORIG Failed to retrieve 'Original' document for SupplierInvoice flow (flowId: " . $flowId . ")" . (empty($flowResponse['errorMessage']) ? '' : ' - ' . $flowResponse['errorMessage']));
				}
				$receivedFile = $flowResponse['response'];

				// Build the $exchangeProtocol factory for the format of supplier invoice
				$tmpProtocolManager = new ProtocolManager($this->db);
				$detectedProtocol = $tmpProtocolManager->detectProtocolFromContent($receivedFile);
				if (empty($detectedProtocol)) {
					return array('res' => -1, 'message' => "ERROR_FLOW_DETECTPROTOCOL Failed to detect protocol from received document for flowId: " . $flowId);
				}

				$exchangeProtocol = $tmpProtocolManager->getProtocol($detectedProtocol);

				$exceptionmessage = '';
				$db->begin();

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

						$db->rollback();
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

						$db->commit();
					}
				} catch (Exception $e) {
					$exceptionmessage = $e->getMessage();

					$db->rollback();
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
				// TODO: Move all this case or condition into a function. Weshould also call this int the Ajax component that update the status of an einvoice sent.

				// In this case, the trackingId may be null.
				// - If trackingId is set, it is used to find the invoice as usual.
				// - If trackingId is null, we try to retrieve the linked invoice using the flowId
				//   stored in the einvoicing_extlinks table when the invoice was sent.

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
	 * Send status message of an invoice to PDP/PA
	 *
	 * @param mixed $object Invoice object (CustomerInvoice or SupplierInvoice)
	 * @param int $statusCode   Status code to send (see class constants for available codes)
	 * @param string $reasonCode Reason code to send (optional)
	 *
	 * @return array{res:int, message:string}       Returns array with 'res' (1 on success, -1 on failure) with a 'message'.
	 */
	public function sendStatusMessage($object, $statusCode, $reasonCode = '')
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
		$result = $cdarHandler->generateCdarFile($object, $statusCode, $reasonCode);
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
