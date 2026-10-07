<?php
/* Copyright (C) 2026		Pierre Grasswill		<da.grumpf@gmail.com>
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
 * \file    cloudbackup/lib/cloudbackup.lib.php
 * \ingroup cloudbackup
 * \brief   Common functions of the CloudBackup pages
 */

/**
 * Tabs of the setup pages
 *
 * @return array<array{string,string,string}>
 */
function cloudbackupAdminPrepareHead()
{
	global $langs;

	$langs->load("cloudbackup@cloudbackup");

	$h = 0;
	$head = array();

	$head[$h][0] = dol_buildpath("/cloudbackup/admin/setup.php", 1);
	$head[$h][1] = $langs->trans("Settings");
	$head[$h][2] = 'settings';
	$h++;

	$head[$h][0] = dol_buildpath("/cloudbackup/cloudbackupindex.php", 1);
	$head[$h][1] = $langs->trans("CloudBackupBackups");
	$head[$h][2] = 'backups';
	$h++;

	$head[$h][0] = dol_buildpath("/cloudbackup/orphans.php", 1);
	$head[$h][1] = $langs->trans("CloudBackupOrphans");
	$head[$h][2] = 'orphans';
	$h++;

	$head[$h][0] = dol_buildpath("/cloudbackup/admin/about.php", 1);
	$head[$h][1] = $langs->trans("About");
	$head[$h][2] = 'about';
	$h++;

	return $head;
}

/**
 * Tell whether the user has a permission of the module. An administrator always has them, as for
 * the backup tools of the core: an admin created after the activation has no module permission.
 *
 * @param	User	$user		User
 * @param	string	$object		backup, restore, setup
 * @param	string	$action		read, write
 * @return	bool
 */
function cloudbackupHasRight($user, $object, $action)
{
	return !empty($user->admin) || $user->hasRight('cloudbackup', $object, $action);
}

/**
 * Tell whether the user may change the setup
 *
 * @param	User	$user	User
 * @return	bool
 */
function cloudbackupCanSetup($user)
{
	return cloudbackupHasRight($user, 'setup', 'write');
}
