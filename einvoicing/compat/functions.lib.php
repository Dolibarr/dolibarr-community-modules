<?php
/* Copyright (C) 2004-2023	Laurent Destailleur		<eldy@users.sourceforge.net>
 * Copyright (C) 2026		Pierre Grasswill		<da.grumpf@gmail.com>
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
 * or see https://www.gnu.org/
 */

/**
 *  \file		einvoicing/compat/functions.lib.php
 *  \ingroup	einvoicing
 *  \brief		Core helpers of functions.lib.php this module uses and that Dolibarr 17 does not ship yet
 */

// @phan-file-suppress PhanRedefineFunction

if (!function_exists('getDolGlobalFloat')) {
	/**
	 *  Return a Dolibarr global constant value, converted into float.
	 *  Provided as a polyfill for Dolibarr < 21, where this function does not exist yet.
	 *
	 *  @param  string  $key        Name of the constant
	 *  @param  float   $default    Default value if constant is not defined
	 *  @return float               Value converted into float
	 *  @since  Dolibarr V21
	 */
	function getDolGlobalFloat($key, $default = 0)
	{
		global $conf;
		return (float) (isset($conf->global->$key) ? $conf->global->$key : $default);
	}
}

if (!function_exists('GETPOSTFLOAT')) {
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

if (!function_exists('dolPrintHTML')) {
	/**
	 * Return a string ready to be output on HTML page
	 * To use text inside an attribute, use can use only dol_escape_htmltag()
	 *
	 * @param	string	$s		String to print
	 * @return	string			String ready for HTML output
	 */
	function dolPrintHTML($s)
	{
		return dol_escape_htmltag(dol_htmlwithnojs(dol_string_onlythesehtmltags(dol_htmlentitiesbr($s), 1, 1, 1)), 1, 1, 'common', 0, 1);
	}
}

if (!function_exists('dolPrintHTMLForAttribute')) {
	/**
	 * Return a string ready to be output into an HTML attribute (alt, title, data-html, ...)
	 * With dolPrintHTMLForAttribute(), the content is HTML encode, even if it is already HTML content.
	 *
	 * Copy of the function added to htdocs/core/lib/functions.lib.php in Dolibarr 19. It calls
	 * dol_escape_htmltag() with six arguments, and that function only takes five on 17, which PHP
	 * accepts: the sixth ($cleanalsojavascript) is simply not read there, and changes nothing for this
	 * use since the fifth argument is 0 and already escapes the whole string.
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

if (!function_exists('getMultidirTemp')) {
	/**
	 * Return the full path of the directory where a module (or an object of a module) stores its temporary files.
	 * Path may depends on the entity if a multicompany module is enabled.
	 *
	 * @param 	CommonObject 	$object 	Dolibarr common object
	 * @param 	string 			$module 	Override object element, for example to use 'mycompany' instead of 'societe'
	 * @param	int				$forobject	Return the more complete path for the given object instead of for the module only.
	 * @return 	string|null					The path of the relative temp directory of the module
	 */
	function getMultidirTemp($object, $module = '', $forobject = 0)
	{
		return getMultidirOutputCompat($object, $module, $forobject, 'temp');
	}
}
