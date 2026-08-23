<?php
/* Copyright (C) 2026	Joliciel	<contact@joliciel.fr>
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
 * \file    dolisecure/admin/setup.php
 * \ingroup dolisecure
 * \brief   DoliSecure setup page: cache settings and manual CVE check.
 */

// Load Dolibarr environment
$res = 0;
// Try main.inc.php into web root known defined into CONTEXT_DOCUMENT_ROOT (not always defined)
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include str_replace("..", "", $_SERVER["CONTEXT_DOCUMENT_ROOT"])."/main.inc.php";
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
require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';
require_once '../lib/dolisecure.lib.php';
require_once '../class/dolisecurechecker.class.php';

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var HookManager $hookmanager
 * @var Translate $langs
 * @var User $user
 */

// Translations
$langs->loadLangs(array("admin", "dolisecure@dolisecure"));

// Initialize a technical object to manage hooks of page. Note that conf->hooks_modules contains an array of hook context
/** @var HookManager $hookmanager */
$hookmanager->initHooks(array('dolisecuresetup', 'globalsetup'));

// Parameters
$action = GETPOST('action', 'aZ09');
$backtopage = GETPOST('backtopage', 'alpha');
$modulepart = GETPOST('modulepart', 'aZ09');	// Used by actions_setmoduleoptions.inc.php

$error = 0;

// Access control
if (!$user->admin) {
	accessforbidden();
}

// Use the factory to manage constants (compatible v15+)
$useFormSetup = 1;

if (!class_exists('FormSetup')) {
	require_once DOL_DOCUMENT_ROOT.'/core/class/html.formsetup.class.php';
}
$formSetup = new FormSetup($db);

// Cache delay (in hours) before a new check is triggered
$item = $formSetup->newItem('DOLISECURE_CACHE_DELAY_HOURS');
$item->defaultFieldValue = '24';
$item->fieldAttr['placeholder'] = '24';
$item->helpText = $langs->trans('DOLISECURE_CACHE_DELAY_HOURSTooltip');
$item->cssClass = 'maxwidth100';

// Show or hide the home page indicator when no vulnerability is found
$formSetup->newItem('DOLISECURE_SHOW_OK_BANNER')->setAsYesNo();

// Email alert to administrators when a new vulnerability is found
$formSetup->newItem('DOLISECURE_SEND_ADMIN_ALERT')->setAsYesNo();

$item = $formSetup->newItem('DOLISECURE_ALERT_EMAIL');
$item->fieldAttr['placeholder'] = 'admin@example.com';
$item->helpText = $langs->trans('DOLISECURE_ALERT_EMAILTooltip');
$item->cssClass = 'minwidth300';

$setupnotempty = count($formSetup->items);


/*
 * Actions
 */

// For retrocompatibility Dolibarr < 15.0
if (versioncompare(explode('.', DOL_VERSION), array(15)) < 0 && $action == 'update' && !empty($user->admin)) {
	$formSetup->saveConfFromPost();
}

include DOL_DOCUMENT_ROOT.'/core/actions_setmoduleoptions.inc.php';

if ($action == 'checknow') {
	$checker = new DoliSecureChecker($db);
	$result = $checker->check(true);

	if ($result['status'] === 'error') {
		setEventMessages($langs->trans('DoliSecureCheckError', $result['error']), null, 'errors');
	} elseif ($result['status'] === 'vulnerable') {
		setEventMessages($langs->trans('DoliSecureVulnerabilityFound', count($result['cves']), $result['version']), null, 'warnings');
	} else {
		setEventMessages($langs->trans('DoliSecureNoVulnerability', $result['version']), null, 'mesgs');
	}
}

$action = 'edit';


/*
 * View
 */

$form = new Form($db);

$help_url = '';
$title = "DoliSecureSetup";

llxHeader('', $langs->trans($title), $help_url, '', 0, 0, '', '', '', 'mod-dolisecure page-admin');

// Subheader
$linkback = '<a href="'.($backtopage ? $backtopage : DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1').'">'.img_picto($langs->trans("BackToModuleList"), 'back', 'class="pictofixedwidth"').'<span class="hideonsmartphone">'.$langs->trans("BackToModuleList").'</span></a>';

print load_fiche_titre($langs->trans($title), $linkback, 'title_setup');

// Configuration header
$head = dolisecureAdminPrepareHead();
print dol_get_fiche_head($head, 'settings', $langs->trans($title), -1, "dolisecure@dolisecure");

echo '<span class="opacitymedium">'.$langs->trans("DoliSecureSetupPage").'</span><br><br>';

if (!empty($formSetup->items)) {
	print $formSetup->generateOutput(true);
	print '<br>';
}


/*
 * Current status and manual check
 */
$checker = new DoliSecureChecker($db);
$last = $checker->getLastResult();

print load_fiche_titre($langs->trans('DoliSecureStatus'), '', '');

print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td>'.$langs->trans('DoliSecureInstalledVersion').'</td>';
print '<td>'.$langs->trans('DoliSecureLastCheck').'</td>';
print '<td>'.$langs->trans('Status').'</td>';
print '<td class="right">'.$langs->trans('Action').'</td>';
print '</tr>';

$trstyle = '';
if ($last['status'] === 'vulnerable') {
	$colors = DoliSecureChecker::severityColors(DoliSecureChecker::getMaxSeverity($last['cves']));
	$trstyle = ' style="background-color:'.$colors['bg'].';border-left: 4px solid '.$colors['border'].';"';
}
print '<tr class="oddeven"'.$trstyle.'>';
print '<td>'.dol_escape_htmltag($checker->getInstalledVersion()).'</td>';
print '<td>'.(!empty($last['date']) ? dol_print_date($last['date'], 'dayhour') : $langs->trans('DoliSecureNeverChecked')).'</td>';
print '<td>';
if ($last['status'] === 'vulnerable') {
	$badgecolors = DoliSecureChecker::severityColors(DoliSecureChecker::getMaxSeverity($last['cves']));
	print img_picto('', 'fa-shield-alt', 'class="pictofixedwidth valignmiddle"', false, 0, 0, '', 'text-danger');
	print ' <span class="badge badge-status" style="background-color:'.$badgecolors['border'].';color:#fff;">'.$langs->trans('DoliSecureVulnerable', count($last['cves'])).'</span>';
} elseif ($last['status'] === 'ok') {
	print img_picto('', 'fa-shield-alt', 'class="pictofixedwidth valignmiddle"', false, 0, 0, '', 'text-success');
	print ' <span class="badge badge-status4 badge-status">'.$langs->trans('DoliSecureOk').'</span>';
} elseif ($last['status'] === 'error') {
	print img_picto('', 'fa-shield-alt', 'class="pictofixedwidth valignmiddle"', false, 0, 0, '', 'text-warning');
	print ' '.$langs->trans('DoliSecureCheckError', $last['error']);
} else {
	print $langs->trans('DoliSecureNeverChecked');
}
print '</td>';
print '<td class="right">';
print '<a class="butAction" href="'.$_SERVER["PHP_SELF"].'?action=checknow&token='.newToken().'">'.$langs->trans('DoliSecureCheckNow').'</a>';
print '</td>';
print '</tr>';
print '</table>';

print '<br>';

if (!empty($last['cves'])) {
	print load_fiche_titre($langs->trans('DoliSecureCveList'), '', '');

	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre">';
	print '<td>'.$langs->trans('DoliSecureCveId').'</td>';
	print '<td>'.$langs->trans('Description').'</td>';
	print '<td class="center">'.$langs->trans('DoliSecureSeverity').'</td>';
	print '<td class="center">'.$langs->trans('DoliSecureFixedIn').'</td>';
	print '<td class="center">'.$langs->trans('DoliSecurePublished').'</td>';
	print '<td class="center">'.$langs->trans('Link').'</td>';
	print '</tr>';

	foreach ($last['cves'] as $cve) {
		print '<tr class="oddeven">';
		print '<td class="nowrap">'.dol_escape_htmltag($cve['id']).'</td>';
		print '<td>'.dol_trunc(dol_escape_htmltag($cve['description']), 200).'</td>';
		print '<td class="center">';
		if ($cve['severity'] !== '' || $cve['score'] !== null) {
			print dol_escape_htmltag(trim($cve['severity'].' '.($cve['score'] !== null ? '('.$cve['score'].')' : '')));
		}
		print '</td>';
		print '<td class="center">'.(!empty($cve['fixedversion']) ? dol_escape_htmltag($cve['fixedversion']) : '').'</td>';
		print '<td class="center">'.($cve['published'] ? dol_print_date(dol_stringtotime($cve['published']), 'day') : '').'</td>';
		print '<td class="center"><a href="'.dol_escape_htmltag($cve['url']).'" target="_blank" rel="noopener noreferrer">'.$langs->trans('DoliSecureSeeOnNvd').'</a></td>';
		print '</tr>';
	}
	print '</table>';
}

print '<br>';

// Subtle promotion of other Joliciel modules on Dolistore
$dolistoreurl = 'https://www.dolistore.com/index.php?controller=search&search_query=joliciel';
print '<div class="opacitymedium right" style="font-size:0.9em;">';
print img_picto('', 'fa-shopping-bag', 'class="pictofixedwidth"');
print $langs->trans('DoliSecureDiscoverOtherModules', '<a href="'.$dolistoreurl.'" target="_blank" rel="noopener noreferrer">DoliStore.com</a>');
print '</div>';

// Page end
print dol_get_fiche_end();

llxFooter();
$db->close();
