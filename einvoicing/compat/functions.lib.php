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

if (!function_exists('GETPOSTDATE')) {
	/**
	 *  Return a timestamp built from the year, month, day (and optionally hour, minute, second) fields
	 *  posted by a Dolibarr date selector (Form::selectDate()).
	 *
	 *  Copy of the function added to htdocs/core/lib/functions.lib.php in Dolibarr 18, kept identical so
	 *  a date read on 17 is the same timestamp it would be anywhere else. GETPOSTINT() and dol_mktime(),
	 *  the two helpers it calls, are both already there on 17.
	 *
	 *  The 18 implementation is the one to copy, not a later one: it reads the 'hour', 'min' and 'sec'
	 *  fields, which are the names Form::selectDate() posts on 17. Dolibarr 19, 20 and 21 read 'minute'
	 *  and 'second' there instead, names their own selector does not post; 22 came back to 'min'/'sec'
	 *  and added a fourth argument this module does not use.
	 *
	 *  @param	string	$prefix		Prefix used to build the date selector
	 *  @param	string	$hourTime	'getpost' to read the hour, minute and second from the request,
	 *  							'HH:MM:SS' to force them, anything else for midnight
	 *  @param	string	$gm			Timezone the posted values are expressed in ('auto', 'gmt', 'tzserver', 'tzuserrel', ...)
	 *  @return	int|''				Timestamp, or '' when the selector was left empty
	 *  @since	Dolibarr V18
	 */
	function GETPOSTDATE($prefix, $hourTime = '', $gm = 'auto')
	{
		if ($hourTime === 'getpost') {
			$hour = GETPOSTINT($prefix.'hour');
			$minute = GETPOSTINT($prefix.'min');
			$second = GETPOSTINT($prefix.'sec');
		} elseif (preg_match('/^(\d\d):(\d\d):(\d\d)$/', $hourTime, $m)) {
			$hour = intval($m[1]);
			$minute = intval($m[2]);
			$second = intval($m[3]);
		} else {
			$hour = $minute = $second = 0;
		}
		// normalize out of range values
		$hour = min($hour, 23);
		$minute = min($minute, 59);
		$second = min($second, 59);

		return dol_mktime($hour, $minute, $second, GETPOSTINT($prefix.'month'), GETPOSTINT($prefix.'day'), GETPOSTINT($prefix.'year'), $gm);
	}
}

if (!function_exists('dolPrintHTMLForAttribute')) {
	/**
	 * Return a string ready to be output into an HTML attribute (alt, title, data-html, ...)
	 * With dolPrintHTMLForAttribute(), the content is HTML encode, even if it is already HTML content.
	 *
	 * Copy of the function added to htdocs/core/lib/functions.lib.php in Dolibarr 19. It calls
	 * dol_escape_htmltag() with six arguments, and that function only takes five on 17: PHP passes the
	 * extra one to a userland function without complaining, so the sixth ($cleanalsojavascript) is
	 * simply not read there. It changes nothing for this use - the fifth argument is 0, which already
	 * escapes the whole string - and the output on 17 and 18 was checked to be the one the core returns
	 * on 19 for plain text, accents, html tags, quotes, a javascript: link and an onerror attribute.
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
