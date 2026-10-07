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
 * \file    cloudbackup/cloudbackupindex.php
 * \ingroup cloudbackup
 * \brief   Backups: run one, list them, restore one, history
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

require_once __DIR__.'/lib/cloudbackup.lib.php';
require_once __DIR__.'/class/cloudbackup.class.php';

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array("admin", "cloudbackup@cloudbackup"));

$action = GETPOST('action', 'aZ09');
$confirm = GETPOST('confirm', 'alpha');
$id = GETPOST('id', 'alphanohtml');
$fullHistory = (int) GETPOST('fullhistory', 'int');

if (!isModEnabled('cloudbackup') || !cloudbackupHasRight($user, 'backup', 'read')) {
	accessforbidden();
}
$canRun = cloudbackupHasRight($user, 'backup', 'write');
$canRestore = cloudbackupHasRight($user, 'restore', 'write');
$canSetup = cloudbackupCanSetup($user);

$cloudbackup = new CloudBackup($db);
$operationLog = array();
$backups = null;


/*
 * Actions
 */

if ($action == 'backup' && $canRun) {
	if ($cloudbackup->backup('manual') > 0) {
		setEventMessages($langs->trans('CloudBackupBackupDone'), null, 'mesgs');
	} else {
		setEventMessages($langs->trans('CloudBackupBackupFailed', $cloudbackup->error), null, 'errors');
	}
	$operationLog = $cloudbackup->log;
	$action = '';
}

if ($action == 'confirm_restore' && $confirm == 'yes' && $canRestore) {
	$parts = array();
	foreach (array('database', 'documents', 'custom') as $part) {
		if (GETPOST('restore_'.$part, 'alpha')) {
			$parts[] = $part;
		}
	}
	if (!count($parts)) {
		setEventMessages($langs->trans('CloudBackupNothingToRestore'), null, 'errors');
	} elseif ($cloudbackup->restore($id, $parts, (bool) GETPOST('restore_safety', 'alpha'), (bool) GETPOST('restore_delete_extra', 'alpha')) > 0) {
		setEventMessages($langs->trans('CloudBackupRestoreDone'), null, 'mesgs');
	} else {
		setEventMessages($langs->trans('CloudBackupRestoreFailed', $cloudbackup->error), null, 'errors');
	}
	$operationLog = $cloudbackup->log;
	$action = '';
}

if ($action == 'check' && $canSetup) {
	if ($cloudbackup->check((bool) GETPOST('readdata', 'int')) > 0) {
		setEventMessages($langs->trans('CloudBackupCheckOk'), null, 'mesgs');
	} else {
		setEventMessages($langs->trans('CloudBackupCheckFailed'), $cloudbackup->errors, 'errors');
	}
	$action = '';
}

if ($action == 'confirm_unlock' && $confirm == 'yes' && $canSetup) {
	$count = $cloudbackup->unlockRepository();
	if ($count >= 0) {
		setEventMessages($langs->trans('CloudBackupUnlocked', $count), null, 'mesgs');
	} else {
		setEventMessages($cloudbackup->error, null, 'errors');
	}
	$action = '';
}

// The setup right only: a download is the whole database in clear
if ($action == 'download' && $canSetup) {
	$result = $cloudbackup->download($id, GETPOST('file', 'alphanohtml'));
	if ($result > 0 || $result == -2) {
		$db->close();
		exit;
	}
	setEventMessages($langs->trans('CloudBackupDownloadFailed', $cloudbackup->error), null, 'errors');
	$action = 'list';
}

// Plain archives list at once (a manifest per backup), so the latest show without asking; a restic
// repository costs the derivation of its key, it lists on demand
$listAll = ($action == 'list' || $action == 'restore');
$totalBackups = 0;
if ($listAll || CloudBackup::format() === 'archive') {
	try {
		$backups = $cloudbackup->listBackups();
	} catch (Exception $e) {
		setEventMessages($e->getMessage(), null, 'errors');
		$backups = array();
	}
	$totalBackups = count($backups);
	if (!$listAll) {
		$backups = array_slice($backups, 0, 5);
	}
}


/*
 * View
 */

$form = new Form($db);

llxHeader('', $langs->trans("CloudBackupMenu"), '', '', 0, 0, '', '', '', 'mod-cloudbackup page-index');

print load_fiche_titre($langs->trans("CloudBackupMenu"), '', 'fa-cloud-upload-alt');

if ($canSetup) {
	$head = cloudbackupAdminPrepareHead();
	print dol_get_fiche_head($head, 'backups', '', -1);
}

if ($action == 'restore' && $canRestore) {
	$formquestion = array(
		array('type' => 'other', 'label' => '', 'value' => '<span class="warning">'.$langs->trans('CloudBackupRestoreWarning').'</span>'),
		array('type' => 'checkbox', 'name' => 'restore_database', 'label' => $langs->trans('CloudBackupPartDatabase'), 'value' => 1),
		array('type' => 'checkbox', 'name' => 'restore_documents', 'label' => $langs->trans('CloudBackupPartDocuments'), 'value' => 1),
		array('type' => 'other', 'label' => '', 'value' => '<span class="opacitymedium">'.$langs->trans('CloudBackupExtraFilesKept').'</span>'),
		array('type' => 'checkbox', 'name' => 'restore_delete_extra', 'label' => $langs->trans('CloudBackupDeleteExtraFiles'), 'value' => 0),
		array('type' => 'checkbox', 'name' => 'restore_custom', 'label' => $langs->trans('CloudBackupPartCustom'), 'value' => 0),
		array('type' => 'checkbox', 'name' => 'restore_safety', 'label' => $langs->trans('CloudBackupSafetyBackup'), 'value' => 1),
	);
	print $form->formconfirm($_SERVER["PHP_SELF"].'?id='.urlencode($id), $langs->trans('CloudBackupRestore'), $langs->trans('CloudBackupConfirmRestore', dol_escape_htmltag(CloudBackup::shortId($id))), 'confirm_restore', $formquestion, 'no', 0, 420, 640);
}
if ($action == 'unlock' && $canSetup) {
	print $form->formconfirm($_SERVER["PHP_SELF"], $langs->trans('CloudBackupUnlock'), $langs->trans('CloudBackupConfirmUnlock'), 'confirm_unlock', '', 'no', 0);
}

// Status
foreach ($cloudbackup->checkPrerequisites() as $problem) {
	print info_admin($problem, 0, 0, 'warning');
}
$estimate = CloudBackup::memoryEstimate($db);
if ($estimate['limit'] !== -1 && $estimate['limit'] < $estimate['needed'] && !$estimate['raisable']) {
	print info_admin($langs->trans('CloudBackupMemoryNeeded', CloudBackup::formatSize($estimate['needed']), CloudBackup::formatSize($estimate['limit'])).'. '.$langs->trans('CloudBackupMemoryTooLow'), 0, 0, 'warning');
}
$storageLabels = array('s3' => 'S3', 'ftp' => 'FTP', 'sftp' => 'SFTP', 'local' => $langs->trans('CloudBackupStorageLocal'));
$storageType = getDolGlobalString('CLOUDBACKUP_STORAGE', 's3');
print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="2">'.$langs->trans('CloudBackupStatus').'</td></tr>';
print '<tr class="oddeven"><td class="titlefield">'.$langs->trans('CloudBackupFormat').'</td><td>'.$langs->trans(CloudBackup::format() === 'restic' ? 'CloudBackupFormatRestic' : 'CloudBackupFormatArchive').'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('CloudBackupStorage').'</td><td>'.(isset($storageLabels[$storageType]) ? $storageLabels[$storageType] : $storageType);
if ($storageType === 's3') {
	print ' - '.dol_escape_htmltag(getDolGlobalString('CLOUDBACKUP_S3_BUCKET'));
} elseif ($storageType === 'local') {
	print ' - '.dol_escape_htmltag(getDolGlobalString('CLOUDBACKUP_LOCAL_PATH'));
} else {
	print ' - '.dol_escape_htmltag(getDolGlobalString($storageType === 'ftp' ? 'CLOUDBACKUP_FTP_HOST' : 'CLOUDBACKUP_SFTP_HOST'));
}
if (getDolGlobalString('CLOUDBACKUP_PATH') !== '') {
	print ' / '.dol_escape_htmltag(getDolGlobalString('CLOUDBACKUP_PATH'));
}
print '</td></tr>';
$usage = CloudBackup::usage();
print '<tr class="oddeven"><td>'.$langs->trans('CloudBackupUsage').'</td><td>';
if ($usage === null) {
	print '<span class="opacitymedium">'.$langs->trans('CloudBackupUsageNever').'</span>';
} else {
	print $langs->trans('CloudBackupUsageValue', $usage['used'] < 0 ? $langs->trans('Unknown') : CloudBackup::formatSize($usage['used']), $usage['backups']);
	// What deduplication saves: restic stores once what the backups share
	if (CloudBackup::format() === 'restic' && $usage['used'] > 0 && $usage['data'] > 0) {
		print ', '.$langs->trans('CloudBackupUsageData', CloudBackup::formatSize($usage['data']));
	}
	print ' <span class="opacitymedium small">('.$langs->trans('CloudBackupUsageMeasured', dol_print_date($usage['date'], 'dayhour', 'tzuser')).')</span>';
}
print '</td></tr>';
$sql = "SELECT MAX(date_end) as last FROM ".$db->prefix()."cloudbackup_run WHERE action = 'backup' AND status = 1";
$resql = $db->query($sql);
$obj = $resql ? $db->fetch_object($resql) : null;
$last = ($obj && $obj->last) ? $db->jdate($obj->last) : 0;
print '<tr class="oddeven"><td>'.$langs->trans('CloudBackupLastSuccess').'</td><td>';
if ($last) {
	$age = dol_now() - $last;
	print ($age > 2 * 86400 ? img_warning().' ' : '').dol_print_date($last, 'dayhour', 'tzuser');
} else {
	print img_warning().' '.$langs->trans('CloudBackupNever');
}
print '</td></tr>';
print '</table></div>';

// Buttons
print '<div class="tabsAction">';
if ($canRun) {
	print '<a class="butAction" href="'.$_SERVER["PHP_SELF"].'?action=backup&token='.newToken().'">'.$langs->trans('CloudBackupRunNow').'</a>';
} else {
	print '<a class="butActionRefused classfortooltip" href="#" title="'.dol_escape_htmltag($langs->trans('NotEnoughPermissions')).'">'.$langs->trans('CloudBackupRunNow').'</a>';
}
print '<a class="butAction" href="'.$_SERVER["PHP_SELF"].'?action=list'.($fullHistory ? '&fullhistory=1' : '').'&token='.newToken().'">'.$langs->trans('CloudBackupList').'</a>';
if ($canSetup) {
	print '<a class="butAction" href="'.$_SERVER["PHP_SELF"].'?action=check&token='.newToken().'">'.$langs->trans('CloudBackupCheck').'</a>';
	if (CloudBackup::format() === 'restic') {
		print '<a class="butAction" href="'.$_SERVER["PHP_SELF"].'?action=check&readdata=1&token='.newToken().'">'.$langs->trans('CloudBackupCheckData').'</a>';
		print '<a class="butActionDelete" href="'.$_SERVER["PHP_SELF"].'?action=unlock&token='.newToken().'">'.$langs->trans('CloudBackupUnlock').'</a>';
	}
}
print '</div>';

// Log of the operation just run
if (count($operationLog)) {
	print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
	print '<tr class="liste_titre"><td>'.$langs->trans('CloudBackupOperationLog').'</td></tr>';
	print '<tr class="oddeven"><td><pre class="small">'.dol_escape_htmltag(implode("\n", $operationLog), 0, 1).'</pre></td></tr>';
	print '</table></div><br>';
}

// Backups on the storage
if (is_array($backups)) {
	$truncated = (!$listAll && $totalBackups > count($backups));
	print load_fiche_titre($truncated ? $langs->trans('CloudBackupAvailableLatest', count($backups)) : $langs->trans('CloudBackupAvailable'), '', '');
	print '<div class="div-table-responsive"><table class="noborder centpercent">';
	print '<tr class="liste_titre"><td>'.$langs->trans('Date').'</td><td>'.$langs->trans('CloudBackupId').'</td><td>'.$langs->trans('CloudBackupHost').'</td>';
	print '<td>'.$langs->trans('Version').'</td><td>'.$langs->trans('CloudBackupContent').'</td><td class="right">'.$langs->trans('Size').'</td>';
	print ($canSetup ? '<td>'.$langs->trans('CloudBackupDownload').'</td>' : '').'<td></td></tr>';
	if (!count($backups)) {
		print '<tr class="oddeven"><td colspan="'.($canSetup ? 8 : 7).'"><span class="opacitymedium">'.$langs->trans('None').'</span></td></tr>';
	}
	foreach ($backups as $backup) {
		print '<tr class="oddeven"><td>'.dol_print_date($backup['time'], 'dayhour', 'tzuser').'</td>';
		print '<td><span class="small">'.dol_escape_htmltag(CloudBackup::shortId($backup['id'])).'</span></td>';
		print '<td>'.dol_escape_htmltag($backup['host']).($backup['own'] ? '' : ' <span class="opacitymedium">('.$langs->trans('CloudBackupOtherInstance').')</span>').'</td>';
		$versionWarning = ($backup['dolibarr'] !== '' && $backup['dolibarr'] !== DOL_VERSION) ? ' '.img_warning($langs->trans('CloudBackupOtherVersion', DOL_VERSION)) : '';
		print '<td>'.dol_escape_htmltag($backup['dolibarr']).$versionWarning.'</td>';
		print '<td class="small">'.dol_escape_htmltag(implode(', ', $backup['paths'])).'</td>';
		print '<td class="right">'.($backup['size'] !== null ? CloudBackup::formatSize($backup['size']) : '').'</td>';
		if ($canSetup) {
			print '<td class="small">';
			foreach (CloudBackup::downloadableFiles($backup) as $file => $label) {
				print '<a class="marginrightonly nowraponall" href="'.$_SERVER["PHP_SELF"].'?action=download&id='.urlencode($backup['id']).'&file='.urlencode($file).'&token='.newToken().'">'.img_picto('', 'download', 'class="pictofixedwidth"').dol_escape_htmltag($label).'</a> ';
			}
			print '</td>';
		}
		print '<td class="right">';
		if ($canRestore) {
			print '<a class="butActionDelete smallpaddingimp" href="'.$_SERVER["PHP_SELF"].'?action=restore&id='.urlencode($backup['id']).'&token='.newToken().'">'.$langs->trans('CloudBackupRestore').'</a>';
		}
		print '</td></tr>';
	}
	print '</table></div><br>';
}

// History
$sql = "SELECT rowid, action, origin, format, storage, status, date_start, date_end, date_alive, snapshot, nb_files, bytes_total, bytes_added, message";
$sql .= " FROM ".$db->prefix()."cloudbackup_run ORDER BY rowid DESC";
// One row more than shown tells whether there is more to show
$historySize = $fullHistory ? 500 : 5;
$sql .= $db->plimit($historySize + 1);
$resql = $db->query($sql);
$moreHistory = ($resql && $db->num_rows($resql) > $historySize);
print load_fiche_titre($moreHistory ? $langs->trans('CloudBackupHistoryLatest', $historySize) : $langs->trans('CloudBackupHistory'), '', '');
print '<div class="div-table-responsive"><table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('DateStart').'</td><td>'.$langs->trans('Duration').'</td><td>'.$langs->trans('Action').'</td>';
print '<td>'.$langs->trans('CloudBackupOrigin').'</td><td>'.$langs->trans('CloudBackupId').'</td><td class="right">'.$langs->trans('CloudBackupFiles').'</td>';
print '<td class="right">'.$langs->trans('Size').'</td><td class="right">'.$langs->trans('CloudBackupAdded').'</td><td>'.$langs->trans('Status').'</td></tr>';
if ($resql) {
	if (!$db->num_rows($resql)) {
		print '<tr class="oddeven"><td colspan="9"><span class="opacitymedium">'.$langs->trans('None').'</span></td></tr>';
	}
	$shown = 0;
	while (($obj = $db->fetch_object($resql)) && $shown++ < $historySize) {
		$start = $db->jdate($obj->date_start);
		$end = $obj->date_end ? $db->jdate($obj->date_end) : 0;
		$stale = ($obj->status == 0 && dol_now() - ($obj->date_alive ? $db->jdate($obj->date_alive) : $start) > CloudBackup::RUN_STALE);
		print '<tr class="oddeven"><td>'.dol_print_date($start, 'dayhour', 'tzuser').'</td>';
		print '<td>'.($end ? convertSecondToTime($end - $start, 'allhourminsec') : '').'</td>';
		print '<td>'.$langs->trans('CloudBackupAction'.dol_ucfirst($obj->action)).'</td>';
		$originKey = 'CloudBackupOrigin'.str_replace('-', '', dol_ucfirst((string) $obj->origin));
		print '<td>'.dol_escape_htmltag($langs->trans($originKey) !== $originKey ? $langs->trans($originKey) : $obj->origin).'</td>';
		print '<td class="small">'.dol_escape_htmltag(CloudBackup::shortId((string) $obj->snapshot)).'</td>';
		print '<td class="right">'.($obj->nb_files !== null ? (int) $obj->nb_files : '').'</td>';
		print '<td class="right">'.($obj->bytes_total !== null ? CloudBackup::formatSize($obj->bytes_total) : '').'</td>';
		print '<td class="right">'.($obj->bytes_added !== null ? CloudBackup::formatSize($obj->bytes_added) : '').'</td>';
		print '<td>';
		if ($obj->status == 1) {
			print dolGetStatus($langs->trans('CloudBackupStatusOk'), '', '', 'status4', 5);
		} elseif ($obj->status == 0 && !$stale) {
			print dolGetStatus($langs->trans('CloudBackupStatusRunning'), '', '', 'status1', 5);
		} else {
			print dolGetStatus($langs->trans($stale ? 'CloudBackupStatusInterrupted' : 'CloudBackupStatusError'), '', '', 'status8', 5);
		}
		if ((string) $obj->message !== '') {
			// The tooltip of the core drops the line feeds: they go as <br>
			print ' '.$form->textwithpicto('', '<span class="small">'.dol_nl2br(dol_escape_htmltag($obj->message, 0, 1)).'</span>', 1, 'info');
		}
		print '</td></tr>';
	}
}
print '</table></div>';
if ($moreHistory && !$fullHistory) {
	print '<div class="tabsAction"><a class="butAction" href="'.$_SERVER["PHP_SELF"].'?fullhistory=1'.($listAll ? '&action=list&token='.newToken() : '').'">'.$langs->trans('CloudBackupShowHistory').'</a></div>';
}

if ($canSetup) {
	print dol_get_fiche_end();
}

llxFooter();
$db->close();
