<?php
/* Copyright (C) 2004-2017 Laurent Destailleur  <eldy@users.sourceforge.net>
 * Copyright (C) 2023 Eric Seigne <eric.seigne@cap-rel.fr>
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
 * \file    stancer/admin/asso.php
 * \ingroup stancer
 * \brief   Configuration tab for associations
 */

// Load Dolibarr environment
$res = 0;
// Try main.inc.php into web root known defined into CONTEXT_DOCUMENT_ROOT (not always defined)
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
// Try main.inc.php into web root detected using web root calculated from SCRIPT_FILENAME
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME']; $tmp2 = realpath(__FILE__); $i = strlen($tmp) - 1; $j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
	$i--;
	$j--;
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

// Libraries
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/functions2.lib.php';
dol_include_once('/stancer/lib/stancer.lib.php');


// Translations
$langs->loadLangs(array("errors", "admin", "stancer@stancer"));

// Access control
if (!$user->admin) {
	accessforbidden();
}

// Parameters
$action = GETPOST('action', 'aZ09');
$backtopage = GETPOST('backtopage', 'alpha');
$modulepart = GETPOST('modulepart', 'aZ09');	// Used by actions_setmoduleoptions.inc.php

$error = 0;
// $setupnotempty = 0;

$useFormSetup = 1;

if (!class_exists('FormSetup')) {
	// For retrocompatibility Dolibarr < 16.0
	if (floatval(DOL_VERSION) < 16.0 && !class_exists('FormSetup')) {
		dol_include_once('/stancer/backport/v16/core/class/html.formsetup.class.php');
	} else {
		require_once DOL_DOCUMENT_ROOT.'/core/class/html.formsetup.class.php';
	}
}

$formSetup = new FormSetup($db);
$form = new Form($db);

$formSetup->newItem('STANCER_ASSO_ACTIVE')->setAsYesNo();

/*
 * Actions
 */
if ( versioncompare(explode('.', DOL_VERSION), array(15)) < 0 && $action == 'update' && !empty($user->admin)) {
	$formSetup->saveConfFromPost();
}
include DOL_DOCUMENT_ROOT.'/core/actions_setmoduleoptions.inc.php';

if (getDolGlobalString('STANCER_ASSO_ACTIVE', '') != '') {
	include_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';
	$extrafields = new ExtraFields($db);
	$result1=$extrafields->addExtraField('stancer_sepa_ref', "StancerSEPAstart", 'varchar', 1,  32, 'adherent',   0, 0, '', '', 1, '', 0, 0, '', '', 'stancer@stancer', '$conf->stancer->enabled');
	$result2=$extrafields->addExtraField('stancer_cb_ref', "StancerCardStart", 'varchar', 2, 32, 'adherent',      0, 0, '', '', 1, '', 0, 0, '', '', 'stancer@stancer', '$conf->stancer->enabled');
	$result3=$extrafields->addExtraField('stancer_account', "StancerAccount", 'varchar', 3, 32, 'adherent', 1, 0, '', '', 1, '', 0, 0, '', '', 'stancer@stancer', '$conf->stancer->enabled');
}

/*
 * View
 */

$help_url = '';
$page_name = "StancerSetupSepa";

llxHeader('', $langs->trans($page_name), $help_url);

// Subheader
$linkback = '<a href="'.($backtopage ? $backtopage : DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1').'">'.$langs->trans("BackToModuleList").'</a>';

print load_fiche_titre($langs->trans($page_name), $linkback, 'title_setup');

// Configuration header
$head = stancerAdminPrepareHead();
print dol_get_fiche_head($head, 'StancerAssoMenu', $langs->trans($page_name), 0, 'stancer@stancer');

dol_include_once('/stancer/core/modules/modStancer.class.php');
$tmpmodule = new modStancer($db);

print "<h1>" . $langs->trans("StancerAssoConfig") . "</h1>";

// $stancer->setDebug(true);

print "<p>" . $langs->transnoentitiesnoconv("StancerAssoPresentation") . "</p>";

if (getDolGlobalString('STANCER_ASSO_ACTIVE', '') == '' || $action == 'edit') {
	print $formSetup->generateOutput(true);
} else {
	print $formSetup->generateOutput();
	print '<div class="tabsAction">';
	print '<a class="butAction" href="'.$_SERVER["PHP_SELF"].'?action=edit&token='.newToken().'">'.$langs->trans("Modify").'</a>';
	print '</div>';
}


// Page end
print dol_get_fiche_end();
llxFooter();
$db->close();
