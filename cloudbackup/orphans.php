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
 * \file    cloudbackup/orphans.php
 * \ingroup cloudbackup
 * \brief   Orphans: documents without object, index lines without file, documents kept by a restore
 */

// Load Dolibarr environment
$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include str_replace("..", "", $_SERVER["CONTEXT_DOCUMENT_ROOT"])."/main.inc.php";
}
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
if (!$res && file_exists("../main.inc.php")) {
	$res = @include "../main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
require_once DOL_DOCUMENT_ROOT.'/ecm/class/ecmfiles.class.php';
require_once __DIR__.'/lib/cloudbackup.lib.php';
require_once __DIR__.'/class/cloudbackup.class.php';
require_once __DIR__.'/class/cloudbackuporphans.class.php';

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Form $form
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array("admin", "companies", "bills", "propal", "orders", "contracts", "compta", "cloudbackup@cloudbackup"));

$action = GETPOST('action', 'aZ09');
$confirm = GETPOST('confirm', 'alpha');
$massaction = GETPOST('massaction', 'alpha');
$mode = GETPOST('mode', 'aZ09');
if (!in_array($mode, array('files', 'index', 'kept'))) {
	$mode = 'files';
}
$key = GETPOST('key', 'alpha');
$toselect = GETPOST('toselect', 'array');
$limit = GETPOSTINT('limit') ? GETPOSTINT('limit') : $conf->liste_limit;
$page = GETPOSTISSET('pageplusone') ? (GETPOSTINT('pageplusone') - 1) : GETPOSTINT('page');
if ($page < 0) {
	$page = 0;
}

// The documents of every module can be read and deleted here: the permission to configure the backups
if (!isModEnabled('cloudbackup') || !cloudbackupCanSetup($user)) {
	accessforbidden();
}

$orphans = new CloudBackupOrphans($db);
$kept = array('date' => 0, 'backup' => '', 'files' => array());
if ($mode == 'files') {
	$list = $orphans->orphanFiles();
} elseif ($mode == 'index') {
	$list = $orphans->orphanIndexLines();
} else {
	$kept = $orphans->keptFiles();
	$list = $kept['files'];
}
// Entries are named by a hash: a path can hold any character, the hash goes through GETPOST unchanged
$byKey = array();
foreach ($list as $entry) {
	$byKey[$mode == 'index' ? (string) $entry['id'] : md5($entry['path'])] = $entry;
}


/*
 * Actions
 */

if (GETPOST('cancel', 'alpha') || GETPOST('confirmmassaction', 'alpha') && $massaction != 'predelete') {
	$massaction = '';
}

if ($action == 'download' && isset($byKey[$key]) && $mode != 'index') {
	// As document.php of the core: attachment unless a preview is asked for a type allowed to preview
	$path = $byKey[$key]['path'];
	$full = DOL_DATA_ROOT.'/'.$path;
	$filename = str_replace(array('"', "\r", "\n"), '', dol_basename($path));
	$type = dolIsAllowedForPreview($full) ? dol_mimetype($full) : 'application/octet-stream';
	$attachment = !(GETPOSTINT('preview') && dolIsAllowedForPreview($full));
	top_httphead($type);
	header('Content-Description: File Transfer');
	header('Content-Disposition: '.($attachment ? 'attachment' : 'inline').'; filename="'.$filename.'"');
	header('Cache-Control: Public, must-revalidate');
	header('Pragma: public');
	header('Content-Length: '.dol_filesize($full));
	$db->close();
	readfileLowMemory($full);
	exit;
}

$deleteKeys = array();
if ($action == 'confirm_deletefile' && $confirm == 'yes' && isset($byKey[$key])) {
	$deleteKeys = array($key);
}
if ($action == 'delete' && $confirm == 'yes' && is_array($toselect)) {
	$deleteKeys = $toselect;
}
if (count($deleteKeys)) {
	$done = 0;
	$errors = array();
	foreach ($deleteKeys as $k) {
		if (!isset($byKey[$k])) {
			continue;
		}
		if ($mode == 'index') {
			$ecmfile = new EcmFiles($db);
			$ok = $ecmfile->fetch((int) $k) > 0 && $ecmfile->delete($user) > 0;
		} else {
			$ok = dol_delete_file(DOL_DATA_ROOT.'/'.$byKey[$k]['path'], 1, 1);
			if ($ok) {
				dol_delete_dir(dirname(DOL_DATA_ROOT.'/'.$byKey[$k]['path']), 1);
			}
		}
		if ($ok) {
			$done++;
		} else {
			$errors[] = $byKey[$k]['path'];
		}
	}
	if ($done) {
		setEventMessages($langs->trans('CloudBackupOrphansDeleted', $done), null, 'mesgs');
	}
	if (count($errors)) {
		setEventMessages($langs->trans('CloudBackupOrphansNotDeleted', count($errors)), $errors, 'errors');
	}
	header('Location: '.$_SERVER["PHP_SELF"].'?mode='.urlencode($mode));
	exit;
}


/*
 * View
 */

$form = new Form($db);

llxHeader('', $langs->trans('CloudBackupOrphans'), '', '', 0, 0, '', '', '', 'mod-cloudbackup page-orphans');

print load_fiche_titre($langs->trans("CloudBackupMenu"), '', 'fa-cloud-upload-alt');
print dol_get_fiche_head(cloudbackupAdminPrepareHead(), 'orphans', '', -1);

$head2 = array(
	array($_SERVER["PHP_SELF"].'?mode=files', $langs->trans('CloudBackupOrphanFiles'), 'files'),
	array($_SERVER["PHP_SELF"].'?mode=index', $langs->trans('CloudBackupOrphanIndexLines'), 'index'),
	array($_SERVER["PHP_SELF"].'?mode=kept', $langs->trans('CloudBackupKeptFiles'), 'kept'),
);
print dol_get_fiche_head($head2, $mode, '', -1);

$help = array('files' => 'CloudBackupOrphanFilesHelp', 'index' => 'CloudBackupOrphanIndexLinesHelp', 'kept' => 'CloudBackupKeptFilesHelp');
print '<span class="opacitymedium">'.$langs->trans($help[$mode]).'</span>';
if ($mode == 'kept' && $kept['date']) {
	print '<br><span class="opacitymedium">'.$langs->trans('CloudBackupKeptFilesFrom', dol_print_date($kept['date'], 'dayhour'), dol_escape_htmltag(CloudBackup::shortId($kept['backup']))).'</span>';
}
print '<br><br>';

if ($action == 'deletefile' && isset($byKey[$key])) {
	print $form->formconfirm($_SERVER["PHP_SELF"].'?mode='.urlencode($mode).'&key='.urlencode($key), $langs->trans('DeleteFile'), $langs->trans('ConfirmDeleteFile', dol_escape_htmltag($byKey[$key]['path'])), 'confirm_deletefile', '', '', 1);
}

$total = count($list);
$shown = array_slice($list, $page * $limit, $limit);
// As the lists of the core: one line more than the limit tells there is a next page
$num = count(array_slice($list, $page * $limit, $limit + 1));
$param = '&mode='.urlencode($mode).($limit != $conf->liste_limit ? '&limit='.((int) $limit) : '');
$arrayofmassactions = array('predelete' => img_picto('', 'delete', 'class="pictofixedwidth"').$langs->trans('Delete'));
$massactionbutton = $form->selectMassAction('', $arrayofmassactions);

print '<form method="POST" id="searchFormList" action="'.$_SERVER["PHP_SELF"].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="mode" value="'.dol_escape_htmltag($mode).'">';
print '<input type="hidden" name="page" value="'.((int) $page).'">';

$titles = array('files' => 'CloudBackupOrphanFiles', 'index' => 'CloudBackupOrphanIndexLines', 'kept' => 'CloudBackupKeptFiles');
print_barre_liste($langs->trans($titles[$mode]), $page, $_SERVER["PHP_SELF"], $param, '', '', $massactionbutton, $num, $total, 'file', 0, '', '', $limit, 0, 0, 1);

if ($massaction == 'predelete') {
	print $form->formconfirm($_SERVER["PHP_SELF"], $langs->trans("ConfirmMassDeletion"), $langs->trans("ConfirmMassDeletionQuestion", count((array) $toselect)), "delete", null, '', 0, 200, 500, 1);
}

$kinds = array();
foreach (CloudBackupOrphans::OBJECTS as $kind => $def) {
	$kinds[$kind] = $langs->trans($def['label']);
}

print '<div class="div-table-responsive">';
print '<table class="tagtable liste noborder centpercent">';
print '<tr class="liste_titre">';
print '<th class="center maxwidthsearch">'.$form->showCheckAddButtons('checkforselect', 1).'</th>';
if ($mode == 'files') {
	print '<th>'.$langs->trans('Type').'</th><th>'.$langs->trans('CloudBackupMissingObject').'</th>';
}
print '<th>'.$langs->trans('File').'</th>';
if ($mode != 'index') {
	print '<th class="right">'.$langs->trans('Size').'</th>';
}
print '<th class="center">'.$langs->trans('Date').'</th>';
print '<th></th>';
print '</tr>';

foreach ($shown as $entry) {
	$k = $mode == 'index' ? (string) $entry['id'] : md5($entry['path']);
	$url = $_SERVER["PHP_SELF"].'?mode='.urlencode($mode).'&key='.urlencode($k);
	print '<tr class="oddeven">';
	print '<td class="center"><input class="flat checkforselect" type="checkbox" name="toselect[]" value="'.dol_escape_htmltag($k).'"'.(in_array($k, (array) $toselect) ? ' checked' : '').'></td>';
	if ($mode == 'files') {
		print '<td class="nowraponall">'.dol_escape_htmltag($kinds[$entry['kind']]).'</td>';
		print '<td class="nowraponall">'.dol_escape_htmltag($entry['object']).'</td>';
	}
	print '<td class="tdoverflowmax500" title="'.dol_escape_htmltag($entry['path']).'">'.dol_escape_htmltag($entry['path']).'</td>';
	if ($mode != 'index') {
		print '<td class="right nowraponall">'.dol_print_size($entry['size'], 1, 1).'</td>';
	}
	print '<td class="center nowraponall">'.($entry['date'] ? dol_print_date($entry['date'], 'dayhour') : '').'</td>';
	print '<td class="right nowraponall">';
	if ($mode != 'index') {
		if (dolIsAllowedForPreview(DOL_DATA_ROOT.'/'.$entry['path'])) {
			print '<a class="paddingright" href="'.$url.'&action=download&preview=1&token='.newToken().'" target="_blank" rel="noopener noreferrer" title="'.$langs->trans('Preview').'">'.img_picto($langs->trans('Preview'), 'eye').'</a>';
		}
		print '<a class="paddingright" href="'.$url.'&action=download&token='.newToken().'" title="'.$langs->trans('Download').'">'.img_picto($langs->trans('Download'), 'download').'</a>';
	}
	print '<a href="'.$url.'&action=deletefile&token='.newToken().'" title="'.$langs->trans('Delete').'">'.img_delete().'</a>';
	print '</td>';
	print '</tr>';
}
if (!$total) {
	print '<tr class="oddeven"><td colspan="7"><span class="opacitymedium">'.$langs->trans('CloudBackupNoOrphan').'</span></td></tr>';
}
print '</table>';
print '</div>';
print '</form>';

print dol_get_fiche_end();
print dol_get_fiche_end();

llxFooter();
$db->close();
