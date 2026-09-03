<?php
/* Copyright (C) 2004-2017 Laurent Destailleur  <eldy@users.sourceforge.net>
 * Copyright (C) 2023 Eric Seigne <eric.seigne@cap-rel.fr>
 * Copyright (C) 2026		MDW						<mdeweerd@users.noreply.github.com>
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
 * \file    stancer/admin/setup.php
 * \ingroup stancer
 * \brief   Stancer setup page.
 */

// Load Dolibarr environment
$res = 0;
// Try main.inc.php into web root known defined into CONTEXT_DOCUMENT_ROOT (not always defined)
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
// Try main.inc.php into web root detected using web root calculated from SCRIPT_FILENAME
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME']; $tmp2 = realpath(__FILE__); $i = strlen($tmp) - 1; $j = strlen($tmp2) - 1;  // @phan-suppress-current-line DolibarrForbiddenFunctionPlugin
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
	$i--; $j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1))."/main.inc.php")) {
	$res = @include substr($tmp, 0, ($i + 1))."/main.inc.php";
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php")) {
	$res = @include dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php";
}
// Try main.inc.php using relative path
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

global $langs, $user;

// Libraries
require_once DOL_DOCUMENT_ROOT."/core/lib/admin.lib.php";
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
dol_include_once('/stancer/lib/stancer.lib.php');

//require_once "../class/myclass.class.php";

// Translations
$langs->loadLangs(array("admin", "stancer@stancer"));

// Initialize technical object to manage hooks of page. Note that conf->hooks_modules contains array of hook context
$hookmanager->initHooks(array('stancersetup', 'globalsetup'));

// Access control
if (!$user->admin) {
	accessforbidden();
}

// Parameters
$action = GETPOST('action', 'aZ09');
$backtopage = GETPOST('backtopage', 'alpha');
$modulepart = GETPOST('modulepart', 'aZ09');	// Used by actions_setmoduleoptions.inc.php

$value = GETPOST('value', 'alpha');
$label = GETPOST('label', 'alpha');
$scandir = GETPOST('scan_dir', 'alpha');
$type = 'myobject';


$error = 0;
$setupnotempty = 0;

// Set this to 1 to use the factory to manage constants. Warning, the generated module will be compatible with version v15+ only
$useFormSetup = 1;

if (!class_exists('FormSetup')) {
	// For retrocompatibility Dolibarr < 16.0
	// if (floatval(DOL_VERSION) < 16.0 && !class_exists('FormSetup')) {
		dol_include_once('/stancer/backport/v16/core/class/html.formsetup.class.php');
	// } else {
	// require_once DOL_DOCUMENT_ROOT.'/core/class/html.formsetup.class.php';
	// }
}

$formSetup = new FormSetup($db);
$form = new Form($db);


/*
 * Actions
 */

if ($action == 'down') {
	$filename = dol_decode(GETPOST('hash'));
	if ($filename) {
		$fullfname = DOL_DATA_ROOT . '/' . $filename;
		if (file_exists($fullfname)) {
			header('Content-Type: application/octet-stream');
			header("Content-Transfer-Encoding: Binary");
			header("Content-disposition: attachment; filename=\"".basename($filename)."\"");
			readfile($fullfname);
			exit;
		}
	}
}

/*
 * View
 */

$form = new Form($db);

$help_url = '';
$page_name = "StancerSetup";

llxHeader('', $langs->trans($page_name), $help_url);

// Subheader
$linkback = '<a href="'.($backtopage ? $backtopage : DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1').'">'.$langs->trans("BackToModuleList").'</a>';

print load_fiche_titre($langs->trans($page_name), $linkback, 'title_setup');

// Configuration header
$head = stancerAdminPrepareHead();
print dol_get_fiche_head($head, 'StancerSettingsMenu', $langs->trans($page_name), 0, "stancer@stancer");

print "<h1>Logs</h1>";

print "<ul>";

$dir = opendir(DOL_DATA_ROOT);

// Loop through the files in source directory
while ($file = readdir($dir)) {
	if (strpos($file, '.log')) {
		print "<li><a href=".$_SERVER["PHP_SELF"]."?action=down&hash=".dol_encode($file).">" . $file . "</a></li>\n";
	}
}
closedir($dir);

print "</ul>";


// Page end
print dol_get_fiche_end();

llxFooter();
$db->close();
