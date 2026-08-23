<?php
/* Copyright (C) 2026	Joliciel	<contact@joliciel.fr>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 *	\file		dolisecure/class/actions_dolisecure.class.php
 *	\ingroup	dolisecure
 *	\brief		Hooks of module DoliSecure
 */

/**
 * Class ActionsDolisecure
 * Hooks (declared into module_parts['hooks']) are executed by HookManager, one method per hook name.
 */
class ActionsDolisecure
{
	/**
	 * @var DoliDB
	 */
	public $db;

	/**
	 * @var string Error message
	 */
	public $error = '';

	/**
	 * @var string[] Error messages
	 */
	public $errors = array();

	/**
	 * @var string HTML content returned by a hook, printed by the caller through $hookmanager->resPrint
	 */
	public $resprints;

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Hook 'printMainArea' (context 'main'), called by main_area() on every single Dolibarr page (this is the
	 * function that opens the "fiche" content wrapper on any authenticated page, not just the home page).
	 * Used to opportunistically refresh the CVE cache: DoliSecureChecker::check() is a no-op unless the cache
	 * delay (DOLISECURE_CACHE_DELAY_HOURS, default 24h) has elapsed, so this adds no visible latency on the vast
	 * majority of page loads and does not depend on the Dolibarr cron daemon actually being configured/running.
	 *
	 * @param	array<string,mixed>	$parameters		Hook parameters
	 * @param	?CommonObject			$object			Object (not used here)
	 * @param	string					$action			Current action
	 * @param	HookManager				$hookmanager	Hook manager
	 * @return	int<0,1>								0 = keep other hooks running
	 */
	public function printMainArea($parameters, &$object, &$action, $hookmanager)
	{
		global $user;

		$this->resprints = '';

		if (!isModEnabled('dolisecure') || empty($user) || empty($user->id)) {
			return 0;
		}

		dol_include_once('/dolisecure/class/dolisecurechecker.class.php');
		$checker = new DoliSecureChecker($this->db);
		$checker->check(false);

		return 0;
	}

	/**
	 * Hook 'infoadmin' (context 'index'), used by htdocs/index.php to display security warnings
	 * at the top of the Dolibarr home page. Shows a colored indicator with icon reflecting the
	 * last known CVE check result for the installed Dolibarr version.
	 * No network call is made here: the hook only reads the cache built by the setup page or the cron job.
	 *
	 * @param	array<string,mixed>	$parameters		Hook parameters
	 * @param	?CommonObject			$object			Object (not used here)
	 * @param	string					$action			Current action
	 * @param	HookManager				$hookmanager	Hook manager
	 * @return	int<0,1>								0 = keep other hooks running
	 */
	public function infoadmin($parameters, &$object, &$action, $hookmanager)
	{
		global $langs, $user;

		$this->resprints = '';

		if (!isModEnabled('dolisecure') || empty($user->admin)) {
			return 0; // Indicator is reserved to administrators
		}

		$langs->load('dolisecure@dolisecure');

		dol_include_once('/dolisecure/class/dolisecurechecker.class.php');
		$checker = new DoliSecureChecker($this->db);
		$last = $checker->getLastResult();

		// info_admin() output is stripped of <a> tags by htdocs/index.php (dol_string_onlythesehtmltags only allows div/span/b),
		// so a real link here would show as dead text: point to the setup page in plain text instead.
		$seedetails = ' '.$langs->trans('DoliSecureSeeSetupPage');

		switch ($last['status']) {
			case 'vulnerable':
				$nb = count($last['cves']);
				$text = $langs->trans('DoliSecureVulnerabilityFound', $nb, $last['version']).$seedetails;
				$this->resprints = info_admin($text, 0, 0, 'error', 'clearboth', '', 'warning');
				break;

			case 'ok':
				if (getDolGlobalInt('DOLISECURE_SHOW_OK_BANNER', 1)) {
					$text = $langs->trans('DoliSecureNoVulnerability', $last['version']).$seedetails;
					$this->resprints = info_admin($text, 0, 0, 'green', 'clearboth');
				}
				break;

			case 'error':
				$text = $langs->trans('DoliSecureCheckError', $last['error']).$seedetails;
				$this->resprints = info_admin($text, 0, 0, 'warning', 'clearboth', '', 'warning');
				break;

			default:
				$text = $langs->trans('DoliSecureNeverChecked').$seedetails;
				$this->resprints = info_admin($text, 0, 0, 'neutral', 'clearboth');
				break;
		}

		return 0;
	}
}
