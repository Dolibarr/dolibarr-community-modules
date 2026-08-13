<?php
/* Copyright (C) 2006-2011	Laurent Destailleur		<eldy@users.sourceforge.net>
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
 *  \file		einvoicing/compat/files.lib.php
 *  \ingroup	einvoicing
 *  \brief		Core file helpers this module uses and that Dolibarr 17 does not ship yet
 */

// @phan-file-suppress PhanRedefineFunction

if (!function_exists('dolChmod')) {
	/**
	 *	Change mod of a file
	 *
	 *  Copy of the function added to htdocs/core/lib/functions.lib.php in Dolibarr 18, kept identical
	 *  so a file written on 17 gets exactly the permissions it would get anywhere else.
	 *
	 *  @param	string		$filepath		Full file path
	 *  @param	string		$newmask		Force new mask. For example '0644'
	 *	@return void
	 *  @since	Dolibarr V18
	 */
	function dolChmod($filepath, $newmask = '')
	{
		global $conf;

		if (!empty($newmask)) {
			@chmod($filepath, octdec($newmask));
		} elseif (getDolGlobalString('MAIN_UMASK')) {
			@chmod($filepath, octdec(getDolGlobalString('MAIN_UMASK')));
		}
	}
}
