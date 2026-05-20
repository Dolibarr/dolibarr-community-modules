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

	$head[$h][0] = dol_buildpath("/pdpconnectfr/admin/about.php", 1);
	$head[$h][1] = $langs->trans("About");
	$head[$h][2] = 'about';
	$h++;

	if (getDolGlobalInt('PDPCONNECTFR_ALLOW_DEVTOOLS')) {
		$head[$h][0] = dol_buildpath("/pdpconnectfr/admin/setup_devtools.php", 1);
		$head[$h][1] = $langs->trans("DevTools");
		$head[$h][2] = 'devtools';
		$h++;
	}

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

	if (getDolGlobalString('PDPCONNECTFR_LIVE')) {
		$mysocCheck = $pdpconnectfr->validateMyCompanyConfiguration();
		if ($mysocCheck['res'] <= 0) {
			$ret .= '<div class="' . ($mysocCheck['res'] < 0 ? 'error' : 'warning') . '">';
			$ret .= $mysocCheck['message'];
			$ret .= '<br><br>';
			$ret .= $langs->trans("MyCompanyConfigurationWarning") . ': ';
			$ret .= '<a class="gotomycompanysetup" href="' . DOL_URL_ROOT . '/admin/company.php">';
			$ret .= $langs->trans("ModifyCompanyInformation") . '<i class="fas fa-tools marginleftonly"></i>';
			$ret .= '</a>';
			$ret .= '</div>';
		}
	}

	return ($ret ? $ret . '<br>' : '');
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
	$object->fetch_thirdparty();
	return idprof($object->thirdparty);
}



// Compatibility functions


if (!function_exists("GETPOSTFLOAT")) {
	/**
	 *  Return the value of a $_GET or $_POST supervariable, converted into float.
	 *  Warning: This function assumes by default that the input is a number entered by end user in user format in local language (with possible thousands separator and decimal separator).
	 *  If it is not the case, use the parameter $option = 1 instead.
	 *
	 *  @param  string          $paramname      Name of the $_GET or $_POST parameter
	 *	@param	''|'MU'|'MT'|'MS'|'CU'|'CT'|int	$rounding	Type of rounding ('', 'MU', 'MT, 'MS', 'CU', 'CT', integer) {@see price2num()}
	 * 	@param	int<0,2>		$option			Put 1 if you know that content is already universal format number (so no correction on decimal will be done)
	 * 											Put 2 if you know that number is a user input (so we know we have to fix decimal separator).
	 * 					                        Use 0 if unknown (never use this anymore, automatic detection is not reliable with some languages).
	 *  @return float                           Value converted into float
	 *  @since	Dolibarr V20
	 */
	function GETPOSTFLOAT($paramname, $rounding = '', $option = 2)
	{
		// price2num() can be used to round to an expected accuracy and/or to sanitize any valid user input (such as "1 234.5", "1 234,5", "1'234,5", "1·234,5", "1,234.5", etc.)
		return (float) price2num(GETPOST($paramname), $rounding, $option);
	}
}

if (!function_exists('dolPrintHTMLForAttribute')) {
	/**
	 * Return a string ready to be output into an HTML attribute (alt, title, data-html, ...)
	 * With dolPrintHTMLForAttribute(), the content is HTML encode, even if it is already HTML content.
	 *
	 * @param	string		$s						String to print
	 * @param	int			$escapeonlyhtmltags		1=Escape only html tags, not the special chars like accents.
	 * @param	string[]	$allowothertags			List of other tags allowed
	 * @return	string								String ready for HTML output
	 * @see dolPrintHTML(), dolPrintHTMLFortextArea()
	 */
	function dolPrintHTMLForAttribute($s, $escapeonlyhtmltags = 0, $allowothertags = array())
	{
		$allowedtags = array('br', 'b', 'font', 'hr', 'span');
		if (!empty($allowothertags) && is_array($allowothertags)) {
			$allowedtags = array_merge($allowedtags, $allowothertags);
		}
		// The dol_htmlentitiesbr will convert simple text into html, including switching accent into HTML entities
		// The dol_escape_htmltag will escape html tags.
		if ($escapeonlyhtmltags) {
			return dol_escape_htmltag(dol_string_onlythesehtmltags($s, 1, 0, 0, 0, $allowedtags), 1, -1, '', 1, 1);
		} else {
			return dol_escape_htmltag(dol_string_onlythesehtmltags(dol_htmlentitiesbr($s), 1, 0, 0, 0, $allowedtags), 1, -1, '', 0, 1);
		}
	}
}
