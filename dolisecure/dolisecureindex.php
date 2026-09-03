<?php
/* Copyright (C) 2001-2005  Rodolphe Quiedeville    <rodolphe@quiedeville.org>
 * Copyright (C) 2004-2015  Laurent Destailleur     <eldy@users.sourceforge.net>
 * Copyright (C) 2005-2012  Regis Houssin           <regis.houssin@inodbox.com>
 * Copyright (C) 2015       Jean-François Ferry     <jfefe@aternatik.fr>
 * Copyright (C) 2024       Frédéric France         <frederic.france@free.fr>
 * Copyright (C) 2026		Alice Adminson				<laurent@destailleur.fr>
 * Copyright (C) 2026		MDW							<mdeweerd@users.noreply.github.com>
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
 *	\file       dolisecure/dolisecureindex.php
 *	\ingroup    dolisecure
 *	\brief      Home page of dolisecure top menu
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
$i = strlen($tmp) - 1;  // @phan-suppress-current-line DolibarrForbiddenFunctionPlugin
$j = strlen($tmp2) - 1;  // @phan-suppress-current-line DolibarrForbiddenFunctionPlugin
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
if (!$res) {
	die("Include of main fails");
}
/**
 * The main.inc.php has been included so the following variable are now defined:
 * @var Conf $conf
 * @var DoliDB $db
 * @var HookManager $hookmanager
 * @var Translate $langs
 * @var User $user
 */
require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';
require_once __DIR__.'/class/dolisecurechecker.class.php';

// Load translation files required by the page
$langs->loadLangs(array("dolisecure@dolisecure"));

$action = GETPOST('action', 'aZ09');

// Security check: this report exposes the exact Dolibarr version and its known vulnerabilities, admin only
if (!isModEnabled('dolisecure')) {
	accessforbidden('Module not enabled');
}
if (empty($user->admin)) {
	accessforbidden();
}


/*
 * Actions
 */

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


/*
 * View
 */

$form = new Form($db);
$checker = new DoliSecureChecker($db);
$last = $checker->getLastResult();

llxHeader("", $langs->trans("DoliSecureArea"), '', '', 0, 0, '', '', '', 'mod-dolisecure page-index');

print load_fiche_titre($langs->trans("DoliSecureArea"), '', 'fa-shield-alt');

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
	print img_picto('', 'fa-shield-alt', 'class="pictofixedwidth valignmiddle"', 0, 0, 0, '', 'text-danger');
	print ' '.$langs->trans('DoliSecureVulnerable', count($last['cves']));
} elseif ($last['status'] === 'ok') {
	print img_picto('', 'fa-shield-alt', 'class="pictofixedwidth valignmiddle"', 0, 0, 0, '', 'text-success');
	print ' '.$langs->trans('DoliSecureOk');
} elseif ($last['status'] === 'error') {
	print img_picto('', 'fa-shield-alt', 'class="pictofixedwidth valignmiddle"', 0, 0, 0, '', 'text-warning');
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
		print '<td>'.dol_escape_htmltag($cve['description']).'</td>';
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
} elseif ($last['status'] === 'ok') {
	print '<span class="opacitymedium">'.$langs->trans('DoliSecureNoVulnerabilityLong').'</span>';
}

// End of page
llxFooter();
$db->close();
