<?php
/* Copyright (C) 2025		SuperAdmin					<daoud.mouhamed@gmail.com>
 * Copyright (C) 2025-2026  Frédéric France             <frederic.france@free.fr>
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
 * \file    lib/pdpconnectfr_call.lib.php
 * \ingroup pdpconnectfr
 * \brief   Library files with common functions for Call
 */

/**
 * Prepare array of tabs for Call
 *
 * @param	Call	$object					Call
 * @return 	array<array{string,string,string}>	Array of tabs
 */
function callPrepareHead($object)
{
	global $db, $langs, $conf;

	require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';

	$langs->load("pdpconnectfr@pdpconnectfr");

	$h = 0;
	$head = array();

	$head[$h][0] = dol_buildpath("/pdpconnectfr/call_card.php", 1) . '?id=' . $object->id;
	$head[$h][1] = $langs->trans("pdpFeedback");
	$head[$h][2] = 'card';
	$h++;


	// Show more tabs from modules
	// Entries must be declared in modules descriptor with line
	//$this->tabs = array(
	//	'entity:+tabname:Title:@pdpconnectfr:/pdpconnectfr/mypage.php?id=__ID__'
	//); // to add new tab
	//$this->tabs = array(
	//	'entity:-tabname:Title:@pdpconnectfr:/pdpconnectfr/mypage.php?id=__ID__'
	//); // to remove a tab
	complete_head_from_modules($conf, $langs, $object, $head, $h, 'call@pdpconnectfr');

	complete_head_from_modules($conf, $langs, $object, $head, $h, 'call@pdpconnectfr', 'remove');

	return $head;
}
