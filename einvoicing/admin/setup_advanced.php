<?php
/* Copyright (C) 2004-2017  Laurent Destailleur     <eldy@users.sourceforge.net>
 * Copyright (C) 2024       Frédéric France         <frederic.france@free.fr>
 * Copyright (C) 2025		SuperAdmin				<daoud.mouhamed@gmail.com>
 * Copyright (C) 2026		Alexandre Spangaro      <alexandre@inovea-conseil.com>
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
 * \file    einvoicing/admin/setup_advanced.php
 * \ingroup einvoicing
 * \brief   EInvoicing setup page.
 */

// Load Dolibarr environment
$res = false;
// Try main.inc.php into web root known defined into CONTEXT_DOCUMENT_ROOT (not always defined)
// @phpstan-ignore booleanNot.alwaysTrue (optional server variable)
if (!empty($_SERVER['CONTEXT_DOCUMENT_ROOT'])) {
	$res = @include $_SERVER['CONTEXT_DOCUMENT_ROOT'].'/main.inc.php';
}
// Try main.inc.php into web root detected using web root calculated from SCRIPT_FILENAME
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
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
if (!$res && file_exists("../main.inc.php")) {
	$res = @include "../main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res && file_exists("../../../../main.inc.php")) {
	$res = @include "../../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}
/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var HookManager $hookmanager
 * @var Translate $langs
 * @var User $user
 * @var Societe $mysoc
 */
// Libraries
require_once DOL_DOCUMENT_ROOT."/core/lib/admin.lib.php"; // @phpstan-ignore requireOnce.fileNotFound (PHPStan takes DOL_DOCUMENT_ROOT from install/inc.php of the core, where it is '..')
require_once __DIR__.'/../lib/einvoicing.lib.php';
require_once __DIR__.'/../class/providers/PDPProviderManager.class.php';
require_once __DIR__.'/../class/protocols/ProtocolManager.class.php';
require_once __DIR__.'/../class/einvoicing.class.php';


// Translations
$langs->loadLangs(array("admin", "einvoicing@einvoicing"));

// Initialize a technical object to manage hooks of page. Note that conf->hooks_modules contains an array of hook context
/** @var HookManager $hookmanager */
$hookmanager->initHooks(array('einvoicingsetup', 'globalsetup'));

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
	require_once DOL_DOCUMENT_ROOT.'/core/class/html.formsetup.class.php'; // @phpstan-ignore requireOnce.fileNotFound (PHPStan takes DOL_DOCUMENT_ROOT from install/inc.php of the core, where it is '..')
}

$formSetup = new FormSetup($db);

// Access control
if (!$user->admin) {
	accessforbidden();
}

$ProtocolManager = new ProtocolManager($db);
$protocolsList = $ProtocolManager->getProtocolsList();

// Protocols list
$TFieldProtocols = array();
foreach ($protocolsList as $key => $protocolconfig) {
	if ($protocolconfig['is_enabled'] == 0) {
		continue;
	}
	$TFieldProtocols[$key] = array('label' => $protocolconfig['protocol_name'], 'data-html' => $protocolconfig['protocol_label'] ?? $protocolconfig['protocol_name']);
	if (!empty($protocolconfig['protocol_dol_min'])) {
		$TFieldProtocols[$key]['data-html'] .= ' <span class="opacitymedium">(Dolibarr '.$protocolconfig['protocol_dol_min'].'+)</span>';
	}
	if ($protocolconfig['protocol_name'] == 'CII') {
		$TFieldProtocols[$key]['data-html'] .= ' <span class="opacitymedium">('.$langs->trans("Recommended").')</span>';
	}
	if (!empty($protocolconfig['is_greyed'])) {
		$TFieldProtocols[$key]['disabled'] = 1;
		$TFieldProtocols[$key]['data-html'] .= ' <span class="opacitymedium">('.$protocolconfig['is_greyed'].')</span>';
	}
}


// End of definition of parameters


//$dirmodels = array_merge(array('/'), (array) $conf->modules_parts['models']);
//$moduledir = 'einvoicing';


/*
 * Actions
 */

// Set the default protocol when no default value is specified
if (getDolGlobalString('EINVOICING_PDP') && !getDolGlobalString('EINVOICING_PROTOCOL')) {
	dolibarr_set_const($db, 'EINVOICING_PROTOCOL', 'CII', 'chaine', 0, '', $conf->entity);
	header("Location: ".$_SERVER["PHP_SELF"]);
	exit;
}

$itemtitle = $formSetup->newItem('EINVOICING_DEBUG')->setAsTitle();
$itemtitle->nameText = '<b>'.$langs->trans("Other").'</b>';

// Setup conf to to define the number of flows to synchronize per one synchronization call
$item = $formSetup->newItem('EINVOICING_FLOWS_SYNC_CALL_SIZE');
$item->helpText = $langs->transnoentities('EINVOICING_FLOWS_SYNC_CALL_SIZE_HELP');
$item->defaultFieldValue = '100';
$item->cssClass = 'maxwidth100';

// Setup conf to define a time margin in hours to go back from the current date of the last synchronization
$item = $formSetup->newItem('EINVOICING_SYNC_MARGIN_TIME_HOURS');
$item->helpText = $langs->transnoentities('EINVOICING_SYNC_MARGIN_TIME_HOURS_HELP');
$item->fieldAttr['placeholder'] = $langs->transnoentities('Hours');
$item->cssClass = 'maxwidth100';

// Setup conf to choose to use Chorus or not
$item = $formSetup->newItem('EINVOICING_USE_CHORUS')->setAsYesNo();
$item->nameText = $langs->trans("EINVOICING_USE_CHORUS").' <span class="opacitymedium">('.$langs->trans("FeatureNotFullyYetSupported").')</span>';
$item->helpText = $langs->transnoentities('EINVOICING_USE_CHORUS_HELP');
$item->cssClass = 'minwidth500';

// Setup conf to enable or not debug mode
$item = $formSetup->newItem('EINVOICING_DEBUG_MODE')->setAsYesNo();
$item->helpText = $langs->transnoentities('EINVOICING_DEBUG_MODE_HELP');
$item->defaultFieldValue = '0';
$item->cssClass = 'minwidth500';
$item->fieldParams['warningifon'] = 1;

// @phpstan-ignore include.fileNotFound
include DOL_DOCUMENT_ROOT.'/core/actions_setmoduleoptions.inc.php';

//print getDolGlobalString('EINVOICING_PDP');



/*
 * View
 */

$action = 'edit';

$form = new Form($db);

$help_url = 'EN:Module_EInvoicing';
$title = "OptionsEInvoicingAdvanced";

// @phpstan-ignore arguments.count (llxHeader is declared without parameters in the Dolibarr 18 analysis)
llxHeader('', $langs->trans($title), $help_url, '', 0, 0, '', '', '', 'mod-einvoicing page-admin-optionsadvanced');

// Subheader
$linkback = '<a href="'.($backtopage ? $backtopage : DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1').'">'.img_picto($langs->trans("BackToModuleList"), 'back', 'class="pictofixedwidth"').'<span class="hideonsmartphone">'.$langs->trans("BackToModuleList").'</span></a>';

print load_fiche_titre($langs->trans($title), $linkback, 'title_setup');


// Configuration header
$head = einvoicingAdminPrepareHead();
print dol_get_fiche_head($head, 'advanced', $langs->trans($title), -1, "einvoicing.png@einvoicing");

// Setup page goes here
//print info_admin($langs->trans("EInvoicingInfo"));
//print '<span class="opacitymedium">'.$langs->trans("EInvoicingSetupPage").'</span><br>';

// Alert mysoc configuration is not complete
$einvoicing = new EInvoicing($db);

//$stringwarning = pdpShowWarning($einvoicing);
//print $stringwarning;

if (!empty($formSetup->items)) {
	print '<br><br>';

	print $formSetup->generateOutput(true, true);
	print '<br>';
}

// Page end
print dol_get_fiche_end();

llxFooter();
$db->close();
