<?php
/* Copyright (C) 2026 Pierre Grasswill
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
 * \file    .github/scripts/activate-einvoicing.php
 * \brief   Activates the einvoicing module of a CI instance, the way Home - Setup - Modules does.
 * \remarks activateModule() is what plays the sql/ files of the module, so this is also what gives
 *          the instance the tables the module works on. Reads DOLIBARR_HTDOCS. Run it twice and it
 *          has to stay green: those files are replayed at every activation.
 */

if (PHP_SAPI !== 'cli') {
	echo "Error: this script must be run from the command line.\n";
	exit(1);
}

global $conf, $db, $langs, $user, $mysoc;

$htdocs = getenv('DOLIBARR_HTDOCS');
if (!$htdocs || !file_exists($htdocs . '/master.inc.php')) {
	fwrite(STDERR, 'DOLIBARR_HTDOCS does not point at an htdocs directory (got "' . $htdocs . '")' . "\n");
	exit(2);
}

require_once $htdocs . '/master.inc.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT . '/user/class/user.class.php';

$user = new User($db);
$user->fetch(1);

$result = activateModule('modEInvoicing');

if (!empty($result['errors'])) {
	fwrite(STDERR, 'Activation failed: ' . implode(' | ', $result['errors']) . "\n");
	exit(1);
}

echo 'Module activated (' . (int) $result['nbmodules'] . ' module(s), ' . (int) $result['nbperms'] . " permission(s))\n";
