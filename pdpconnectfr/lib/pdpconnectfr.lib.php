<?php
/* Copyright (C) 2025		SuperAdmin					<daoud.mouhamed@gmail.com>
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
 * \file    pdpconnectfr/lib/pdpconnectfr.lib.php
 * \ingroup pdpconnectfr
 * \brief   Library files with common functions for PDPConnectFR
 */

/**
 * Prepare admin pages header
 *
 * @return array<array{string,string,string}>
 */
function pdpconnectfrAdminPrepareHead()
{
	global $langs, $conf;

	// global $db;
	// $extrafields = new ExtraFields($db);
	// $extrafields->fetch_name_optionals_label('myobject');

	$langs->load("pdpconnectfr@pdpconnectfr");

	$h = 0;
	$head = array();

	$head[$h][0] = dol_buildpath("/pdpconnectfr/admin/setup.php", 1);
	$head[$h][1] = $langs->trans("PASettings");
	$head[$h][2] = 'settings';
	$h++;

	$head[$h][0] = dol_buildpath("/pdpconnectfr/admin/setup_options.php", 1);
	$head[$h][1] = $langs->trans("Options");
	$head[$h][2] = 'options';
	$h++;

	/*
	$head[$h][0] = dol_buildpath("/pdpconnectfr/admin/myobject_extrafields.php", 1);
	$head[$h][1] = $langs->trans("ExtraFields");
	$nbExtrafields = (isset($extrafields->attributes['myobject']['label']) && is_countable($extrafields->attributes['myobject']['label'])) ? count($extrafields->attributes['myobject']['label']) : 0;
	if ($nbExtrafields > 0) {
		$head[$h][1] .= '<span class="badge marginleftonlyshort">' . $nbExtrafields . '</span>';
	}
	$head[$h][2] = 'myobject_extrafields';
	$h++;

	$head[$h][0] = dol_buildpath("/pdpconnectfr/admin/myobjectline_extrafields.php", 1);
	$head[$h][1] = $langs->trans("ExtraFieldsLines");
	$nbExtrafields = (isset($extrafields->attributes['myobjectline']['label']) && is_countable($extrafields->attributes['myobjectline']['label'])) ? count($extrafields->attributes['myobject']['label']) : 0;
	if ($nbExtrafields > 0) {
		$head[$h][1] .= '<span class="badge marginleftonlyshort">' . $nbExtrafields . '</span>';
	}
	$head[$h][2] = 'myobject_extrafieldsline';
	$h++;
	*/

	if (getDolGlobalInt('PDPCONNECTFR_ALLOW_DEVTOOLS')) {
		$head[$h][0] = dol_buildpath("/pdpconnectfr/admin/setup_devtools.php", 1);
		$head[$h][1] = $langs->trans("DevTools");
		$head[$h][2] = 'devtools';
		$h++;
	}

	$head[$h][0] = dol_buildpath("/pdpconnectfr/admin/about.php", 1);
	$head[$h][1] = $langs->trans("About");
	$head[$h][2] = 'about';
	$h++;

	// Show more tabs from modules
	// Entries must be declared in modules descriptor with line
	//$this->tabs = array(
	//	'entity:+tabname:Title:@pdpconnectfr:/pdpconnectfr/mypage.php?id=__ID__'
	//); // to add new tab
	//$this->tabs = array(
	//	'entity:-tabname:Title:@pdpconnectfr:/pdpconnectfr/mypage.php?id=__ID__'
	//); // to remove a tab
	complete_head_from_modules($conf, $langs, null, $head, $h, 'pdpconnectfr@pdpconnectfr');

	complete_head_from_modules($conf, $langs, null, $head, $h, 'pdpconnectfr@pdpconnectfr', 'remove');

	return $head;
}

/**
 * Show a warning if setup not correct.
 *
 * @param 	PdpConnectFr $pdpconnectfr	Object PdpConnectFr
 * @return	string						Return string with warning (or '')
 */
function pdpShowWarning($pdpconnectfr)
{
	global $langs;

	$ret = '';

	$mysocCheck = $pdpconnectfr->validateMyCompanyConfiguration();
	if ($mysocCheck['res'] <= 0) {
		$ret .= '<div class="'.($mysocCheck['res'] < 0 ? 'error' : 'warning').'">';
		$ret .= $mysocCheck['message'];
		$ret .= '<br><br>';
		$ret .= $langs->trans("MyCompanyConfigurationWarning") . ': ';
		$ret .= '<a class="gotomycompanysetup" href="' . DOL_URL_ROOT . '/admin/company.php">';
		$ret .= $langs->trans("ModifyCompanyInformation") . '<i class="fas fa-tools marginleftonly"></i>';
		$ret .= '</a>';
		$ret .= '</div>';
	}

	return ($ret ? $ret.'<br>' : '');
}

/**
 * Extract prof id : it depends on country ...
 *
 * @param 	Societe 	$thirdparty		Dolibarr thirdparty
 * @return 	string 						Return siren or locale prof id
 */
function idprof($thirdparty)
{
	$retour = "";
	switch ($thirdparty->country_code) {
		case 'BE':
			$retour = $thirdparty->idprof1;
			break;
		case 'DE':
			if (!empty($thirdparty->idprof6)) {
				$retour = $thirdparty->idprof6;
				break;
			} elseif (!empty($thirdparty->idprof2) && !empty($thirdparty->idprof3)) {
				$retour = $thirdparty->idprof2 . $thirdparty->idprof3;
			} else {
				$retour = $thirdparty->idprof1;
			}
			break;
		case 'FR':
			if (!empty($thirdparty->idprof1)) {
				$retour = $thirdparty->idprof1; // SIREN
			} else {
				$retour = substr($thirdparty->idprof2, 9); // 9 first chars of SIRET
			}
			break;
		default:
			$retour = $thirdparty->idprof1 ? $thirdparty->idprof1 : $thirdparty->idprof2;
	}

	return preg_replace('/\\s+/', '', $retour);
}

/**
 * Buyer prof id depends on country
 *
 * @param 	CommonObject $object	Object invoice, ...
 * @return 	string 					Prof id
 */
function thirdpartyidprof($object)
{
	return idprof($object->thirdparty);
}
