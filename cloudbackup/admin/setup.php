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
 * \file    cloudbackup/admin/setup.php
 * \ingroup cloudbackup
 * \brief   Setup page of the CloudBackup module
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
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once __DIR__.'/../lib/cloudbackup.lib.php';
require_once __DIR__.'/../class/cloudbackup.class.php';

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var HookManager $hookmanager
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array("admin", "cron", "cloudbackup@cloudbackup"));

$action = GETPOST('action', 'aZ09');

if (!isModEnabled('cloudbackup') || !cloudbackupCanSetup($user)) {
	accessforbidden();
}

$textSettings = array(
	'CLOUDBACKUP_PATH',
	'CLOUDBACKUP_S3_ENDPOINT', 'CLOUDBACKUP_S3_REGION', 'CLOUDBACKUP_S3_BUCKET',
	'CLOUDBACKUP_FTP_HOST', 'CLOUDBACKUP_FTP_USER', 'CLOUDBACKUP_SFTP_HOST', 'CLOUDBACKUP_SFTP_USER',
);
$intSettings = array(
	'CLOUDBACKUP_FTP_PORT', 'CLOUDBACKUP_SFTP_PORT',
	'CLOUDBACKUP_KEEP_LAST', 'CLOUDBACKUP_KEEP_DAILY', 'CLOUDBACKUP_KEEP_WEEKLY', 'CLOUDBACKUP_KEEP_MONTHLY', 'CLOUDBACKUP_KEEP_YEARLY',
);
$boolSettings = array(
	'CLOUDBACKUP_S3_VIRTUAL_HOST', 'CLOUDBACKUP_FTP_SSL', 'CLOUDBACKUP_FTP_VERIFY', 'CLOUDBACKUP_ALLOW_LOCAL_URL',
	'CLOUDBACKUP_WITH_DATABASE', 'CLOUDBACKUP_WITH_DOCUMENTS', 'CLOUDBACKUP_WITH_CUSTOM', 'CLOUDBACKUP_WITH_CONF',
	'CLOUDBACKUP_RETENTION', 'CLOUDBACKUP_PRUNE',
);
$secretSettings = array('CLOUDBACKUP_S3_ACCESS_KEY', 'CLOUDBACKUP_S3_SECRET', 'CLOUDBACKUP_FTP_PASSWORD', 'CLOUDBACKUP_SFTP_PASSWORD');


/*
 * Actions
 */

if ($action == 'update') {
	$error = 0;
	$db->begin();
	$format = GETPOST('CLOUDBACKUP_FORMAT', 'aZ09') === 'archive' ? 'archive' : 'restic';
	$storage = GETPOST('CLOUDBACKUP_STORAGE', 'aZ09');
	if (!in_array($storage, array('s3', 'ftp', 'sftp', 'local'))) {
		$storage = 's3';
	}
	$error += (dolibarr_set_const($db, 'CLOUDBACKUP_FORMAT', $format, 'chaine', 0, '', 0) < 0);
	$error += (dolibarr_set_const($db, 'CLOUDBACKUP_STORAGE', $storage, 'chaine', 0, '', 0) < 0);
	foreach ($textSettings as $name) {
		$error += (dolibarr_set_const($db, $name, trim(GETPOST($name, 'restricthtml')), 'chaine', 0, '', 0) < 0);
	}
	foreach ($intSettings as $name) {
		$error += (dolibarr_set_const($db, $name, (string) ((int) GETPOST($name, 'int')), 'chaine', 0, '', 0) < 0);
	}
	foreach ($boolSettings as $name) {
		$error += (dolibarr_set_const($db, $name, GETPOST($name, 'alpha') ? '1' : '0', 'chaine', 0, '', 0) < 0);
	}
	// The local directory is checked and created now, not at the first backup hours later
	$localPath = rtrim(trim(GETPOST('CLOUDBACKUP_LOCAL_PATH', 'restricthtml')), '/');
	if ($storage === 'local') {
		$problem = CloudBackup::localPathProblem($localPath);
		if ($problem !== '') {
			setEventMessages($problem, null, 'errors');
			$error++;
		} elseif (!dol_is_dir($localPath) && dol_mkdir($localPath) < 0) {
			setEventMessages($langs->trans('CloudBackupErrorLocalPathCreate', $localPath), null, 'errors');
			$error++;
		} elseif (!is_writable($localPath)) {
			setEventMessages($langs->trans('CloudBackupErrorLocalPathWritable', $localPath), null, 'errors');
			$error++;
		} elseif (CloudBackup::isPublicPath($localPath)) {
			setEventMessages($langs->trans('CloudBackupWarningLocalPathPublic'), null, 'warnings');
		}
	}
	$error += (dolibarr_set_const($db, 'CLOUDBACKUP_LOCAL_PATH', $localPath, 'chaine', 0, '', 0) < 0);
	$error += (dolibarr_set_const($db, 'CLOUDBACKUP_EXCLUDE', GETPOST('CLOUDBACKUP_EXCLUDE', 'restricthtml'), 'chaine', 0, '', 0) < 0);
	// A secret left empty keeps its stored value: it is never printed back in the form
	foreach ($secretSettings as $name) {
		$value = GETPOST($name, 'none');
		if ($value !== '') {
			$error += (CloudBackup::setSecret($db, $name, $value) < 0);
		}
	}
	$password = GETPOST('CLOUDBACKUP_RESTIC_PASSWORD', 'none');
	if ($password !== '') {
		if ($password !== GETPOST('CLOUDBACKUP_RESTIC_PASSWORD2', 'none')) {
			setEventMessages($langs->trans('CloudBackupErrorPasswordMismatch'), null, 'errors');
			$error++;
		} elseif (strlen($password) < 12) {
			setEventMessages($langs->trans('CloudBackupErrorPasswordShort'), null, 'errors');
			$error++;
		} else {
			$error += (CloudBackup::setSecret($db, 'CLOUDBACKUP_RESTIC_PASSWORD', $password) < 0);
		}
	}
	if (!$error) {
		$db->commit();
		setEventMessages($langs->trans("SetupSaved"), null, 'mesgs');
	} else {
		$db->rollback();
		setEventMessages($langs->trans("Error"), null, 'errors');
	}
	header("Location: ".$_SERVER["PHP_SELF"]);
	exit;
}

if ($action == 'test') {
	$storage = CloudBackup::storage();
	if ($storage === null) {
		setEventMessages($langs->trans('CloudBackupErrorNoStorage'), null, 'errors');
	} elseif ($storage->missingExtension() !== '') {
		setEventMessages($langs->trans('CloudBackupErrorMissingPhp', $storage->missingExtension()), null, 'errors');
	} elseif ($storage->test()) {
		setEventMessages($langs->trans('CloudBackupTestOk'), null, 'mesgs');
	} else {
		setEventMessages($langs->trans('CloudBackupTestFailed', $storage->error), null, 'errors');
	}
	if ($storage !== null) {
		$storage->close();
	}
	header("Location: ".$_SERVER["PHP_SELF"]);
	exit;
}


/*
 * View
 */

$form = new Form($db);

llxHeader('', $langs->trans("CloudBackupSetup"), '', '', 0, 0, '', '', '', 'mod-cloudbackup page-admin-setup');

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans("BackToModuleList").'</a>';
print load_fiche_titre($langs->trans("CloudBackupSetup"), $linkback, 'title_setup');

$head = cloudbackupAdminPrepareHead();
print dol_get_fiche_head($head, 'settings', $langs->trans("CloudBackupMenu"), -1, 'fa-cloud-upload-alt');

print '<span class="opacitymedium">'.$langs->trans("CloudBackupSetupPage").'</span><br><br>';

$cloudbackup = new CloudBackup($db);
foreach ($cloudbackup->checkPrerequisites() as $problem) {
	print info_admin($problem, 0, 0, 'warning');
}

/**
 * Print a text input row
 *
 * @param	string	$name		Constant
 * @param	string	$label		Label key
 * @param	string	$help		Help key, or ''
 * @param	string	$class		Class of the storage rows it belongs to
 * @param	string	$type		Input type
 * @return	void
 */
function cloudbackupRow($name, $label, $help = '', $class = '', $type = 'text')
{
	global $langs;
	print '<tr class="oddeven'.($class ? ' '.$class : '').'"><td>'.$langs->trans($label);
	if ($help) {
		print '<br><span class="opacitymedium small">'.$langs->trans($help).'</span>';
	}
	print '</td><td><input type="'.$type.'" class="minwidth300" name="'.$name.'" value="'.dol_escape_htmltag(getDolGlobalString($name)).'"></td></tr>';
}

/**
 * Print a secret input row: the stored value is never sent back to the browser
 *
 * @param	string	$name		Constant
 * @param	string	$label		Label key
 * @param	string	$class		Class of the storage rows it belongs to
 * @return	void
 */
function cloudbackupSecretRow($name, $label, $class = '')
{
	global $langs;
	$isset = getDolGlobalString($name) !== '';
	print '<tr class="oddeven'.($class ? ' '.$class : '').'"><td>'.$langs->trans($label).'</td><td>';
	print '<input type="password" autocomplete="new-password" class="minwidth300" name="'.$name.'" value="" placeholder="'.dol_escape_htmltag($isset ? $langs->trans('CloudBackupKeepValue') : '').'">';
	print '</td></tr>';
}

/**
 * Print a checkbox row
 *
 * @param	string	$name		Constant
 * @param	string	$label		Label key
 * @param	string	$help		Help key, or ''
 * @param	string	$class		Class of the storage rows it belongs to
 * @param	int		$default	Value when the constant does not exist
 * @param	string	$missing	PHP feature this option needs and the server lacks: the box is greyed
 * @return	void
 */
function cloudbackupCheckRow($name, $label, $help = '', $class = '', $default = 0, $missing = '')
{
	global $langs;
	print '<tr class="oddeven'.($class ? ' '.$class : '').'"><td>'.$langs->trans($label);
	if ($help) {
		print '<br><span class="opacitymedium small">'.$langs->trans($help).'</span>';
	}
	print '</td><td><input type="checkbox" name="'.$name.'" value="1"'.(getDolGlobalInt($name, $default) && $missing === '' ? ' checked' : '').($missing !== '' ? ' disabled' : '').'>';
	if ($missing !== '') {
		print ' <span class="opacitymedium">'.$langs->trans('CloudBackupNeeds', $missing).'</span>';
	}
	print '</td></tr>';
}

/**
 * A select whose choices the server cannot run are greyed, with what they need
 *
 * @param	string								$name		Field name
 * @param	array<string,array{0:string,1:string}>	$choices	Value => (label, missing PHP feature or '')
 * @param	string								$selected	Selected value
 * @return	string
 */
function cloudbackupSelect($name, $choices, $selected)
{
	global $langs;
	$out = '<select class="flat minwidth300" id="'.$name.'" name="'.$name.'">';
	foreach ($choices as $value => $choice) {
		$label = $choice[0].($choice[1] !== '' ? ' - '.$langs->trans('CloudBackupNeeds', $choice[1]) : '');
		$out .= '<option value="'.dol_escape_htmltag($value).'"'.($value === $selected ? ' selected' : '').($choice[1] !== '' ? ' disabled' : '').'>'.dol_escape_htmltag($label).'</option>';
	}
	return $out.'</select>';
}

// What this server can do: PHP features and the memory the dump of the biggest table needs
$estimate = CloudBackup::memoryEstimate($db);
$memoryOk = ($estimate['limit'] === -1 || $estimate['limit'] >= $estimate['needed'] || $estimate['raisable']);
print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
print '<tr class="liste_titre"><td class="titlefieldcreate">'.$langs->trans("CloudBackupServerCheck").'</td><td></td></tr>';
$features = array(
	'CloudBackupFormatRestic' => CloudBackup::formatMissing('restic'),
	'CloudBackupFormatArchive' => CloudBackup::formatMissing('archive'),
	'S3' => CloudBackup::storageMissing('s3'),
	'FTP' => CloudBackup::storageMissing('ftp'),
	'FTPS' => CloudBackup::storageMissing('ftps'),
	'SFTP' => CloudBackup::storageMissing('sftp'),
);
print '<tr class="oddeven"><td>PHP '.PHP_VERSION.'</td><td>';
foreach ($features as $label => $missing) {
	print '<span class="marginrightonly nowraponall">'.($missing === '' ? img_picto('', 'tick') : img_warning($langs->trans('CloudBackupNeeds', $missing))).' '.$langs->trans($label).'</span> ';
}
print '</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans("CloudBackupBiggestTable").'</td><td>'.dol_escape_htmltag($estimate['table']).' ('.CloudBackup::formatSize($estimate['table_size']).')</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans("CloudBackupMemory").'</td><td>';
print ($memoryOk ? img_picto('', 'tick') : img_warning()).' '.$langs->trans('CloudBackupMemoryNeeded', CloudBackup::formatSize($estimate['needed']), $estimate['limit'] === -1 ? $langs->trans('Unlimited') : CloudBackup::formatSize($estimate['limit']));
if ($estimate['limit'] !== -1 && $estimate['limit'] < $estimate['needed']) {
	print '<br><span class="'.($estimate['raisable'] ? 'opacitymedium' : 'warning').'">'.$langs->trans($estimate['raisable'] ? 'CloudBackupMemoryRaised' : 'CloudBackupMemoryTooLow').'</span>';
}
print '</td></tr>';
print '<tr class="oddeven"><td>max_execution_time</td><td>'.((int) ini_get('max_execution_time') ? (int) ini_get('max_execution_time').' s - '.$langs->trans('CloudBackupTimeHelp') : $langs->trans('Unlimited')).'</td></tr>';
print '</table></div><br>';

print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="update">';

// Where
print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
print '<tr class="liste_titre"><td class="titlefieldcreate">'.$langs->trans("CloudBackupWhere").'</td><td></td></tr>';

$format = CloudBackup::format();
print '<tr class="oddeven"><td>'.$langs->trans("CloudBackupFormat").'<br><span class="opacitymedium small">'.$langs->trans("CloudBackupFormatHelp").'</span></td><td>';
print cloudbackupSelect('CLOUDBACKUP_FORMAT', array(
	'restic' => array($langs->trans('CloudBackupFormatRestic'), CloudBackup::formatMissing('restic')),
	'archive' => array($langs->trans('CloudBackupFormatArchive'), CloudBackup::formatMissing('archive')),
), $format);
print '</td></tr>';

$storageType = getDolGlobalString('CLOUDBACKUP_STORAGE', 's3');
print '<tr class="oddeven"><td>'.$langs->trans("CloudBackupStorage").'</td><td>';
print cloudbackupSelect('CLOUDBACKUP_STORAGE', array(
	's3' => array('S3 (AWS, Scaleway, OVH, Wasabi, Backblaze B2, MinIO...)', CloudBackup::storageMissing('s3')),
	'ftp' => array('FTP / FTPS', CloudBackup::storageMissing('ftp')),
	'sftp' => array('SFTP', CloudBackup::storageMissing('sftp')),
	'local' => array($langs->trans('CloudBackupStorageLocal'), ''),
), $storageType);
print '</td></tr>';
cloudbackupRow('CLOUDBACKUP_S3_ENDPOINT', 'CloudBackupS3Endpoint', 'CloudBackupS3EndpointHelp', 'cb-s3');
cloudbackupRow('CLOUDBACKUP_S3_REGION', 'CloudBackupS3Region', '', 'cb-s3');
cloudbackupRow('CLOUDBACKUP_S3_BUCKET', 'CloudBackupS3Bucket', '', 'cb-s3');
cloudbackupSecretRow('CLOUDBACKUP_S3_ACCESS_KEY', 'CloudBackupS3AccessKey', 'cb-s3');
cloudbackupSecretRow('CLOUDBACKUP_S3_SECRET', 'CloudBackupS3SecretKey', 'cb-s3');
cloudbackupCheckRow('CLOUDBACKUP_S3_VIRTUAL_HOST', 'CloudBackupS3VirtualHost', 'CloudBackupS3VirtualHostHelp', 'cb-s3');
cloudbackupCheckRow('CLOUDBACKUP_ALLOW_LOCAL_URL', 'CloudBackupAllowLocalUrl', 'CloudBackupAllowLocalUrlHelp', 'cb-s3');

cloudbackupRow('CLOUDBACKUP_FTP_HOST', 'CloudBackupHost', '', 'cb-ftp');
cloudbackupRow('CLOUDBACKUP_FTP_PORT', 'CloudBackupPort', '', 'cb-ftp', 'number');
cloudbackupRow('CLOUDBACKUP_FTP_USER', 'CloudBackupUser', '', 'cb-ftp');
cloudbackupSecretRow('CLOUDBACKUP_FTP_PASSWORD', 'CloudBackupPassword', 'cb-ftp');
cloudbackupCheckRow('CLOUDBACKUP_FTP_SSL', 'CloudBackupFtpSsl', 'CloudBackupFtpSslHelp', 'cb-ftp', 0, CloudBackup::storageMissing('ftps'));
cloudbackupCheckRow('CLOUDBACKUP_FTP_VERIFY', 'CloudBackupFtpVerify', 'CloudBackupFtpVerifyHelp', 'cb-ftp', 1);

cloudbackupRow('CLOUDBACKUP_SFTP_HOST', 'CloudBackupHost', '', 'cb-sftp');
cloudbackupRow('CLOUDBACKUP_SFTP_PORT', 'CloudBackupPort', '', 'cb-sftp', 'number');
cloudbackupRow('CLOUDBACKUP_SFTP_USER', 'CloudBackupUser', '', 'cb-sftp');
cloudbackupSecretRow('CLOUDBACKUP_SFTP_PASSWORD', 'CloudBackupPassword', 'cb-sftp');

// The absolute path is unknown on shared hosting: show where the documents are and suggest a directory
$localPath = getDolGlobalString('CLOUDBACKUP_LOCAL_PATH');
$suggested = CloudBackup::suggestedLocalPath();
print '<tr class="oddeven cb-local"><td>'.$langs->trans('CloudBackupLocalPath').'<br><span class="opacitymedium small">'.$langs->trans('CloudBackupLocalPathHelp', DOL_DATA_ROOT).'</span></td><td>';
print '<input type="text" class="minwidth300" id="CLOUDBACKUP_LOCAL_PATH" name="CLOUDBACKUP_LOCAL_PATH" value="'.dol_escape_htmltag($localPath).'"'.($suggested !== '' ? ' placeholder="'.dol_escape_htmltag($suggested).'"' : '').'>';
if ($suggested !== '' && $suggested !== rtrim($localPath, '/')) {
	print '<br><span class="opacitymedium small">'.$langs->trans('CloudBackupLocalPathSuggested', '<code>'.dol_escape_htmltag($suggested).'</code>').'</span>';
	print ' <a href="#" class="small cb-use-suggested" data-path="'.dol_escape_htmltag($suggested).'">'.$langs->trans('CloudBackupUseIt').'</a>';
}
if ($localPath !== '' && getDolGlobalString('CLOUDBACKUP_STORAGE') === 'local' && CloudBackup::isPublicPath($localPath)) {
	print '<br><span class="warning small">'.img_warning().' '.$langs->trans('CloudBackupWarningLocalPathPublic').'</span>';
}
print '</td></tr>';

cloudbackupRow('CLOUDBACKUP_PATH', 'CloudBackupPath', 'CloudBackupPathHelp');
print '</table></div>';

// Encryption
print '<br><div class="div-table-responsive-no-min"><table class="noborder centpercent">';
print '<tr class="liste_titre"><td class="titlefieldcreate">'.$langs->trans("CloudBackupEncryption").'</td><td></td></tr>';
print '<tr class="oddeven cb-restic"><td>'.$langs->trans("CloudBackupResticPassword").'<br><span class="opacitymedium small">'.$langs->trans("CloudBackupResticPasswordHelp").'</span></td><td>';
$isset = getDolGlobalString('CLOUDBACKUP_RESTIC_PASSWORD') !== '';
print '<input type="password" autocomplete="new-password" class="minwidth300" name="CLOUDBACKUP_RESTIC_PASSWORD" value="" placeholder="'.dol_escape_htmltag($isset ? $langs->trans('CloudBackupKeepValue') : '').'"><br>';
print '<input type="password" autocomplete="new-password" class="minwidth300 margintoponly" name="CLOUDBACKUP_RESTIC_PASSWORD2" value="" placeholder="'.dol_escape_htmltag($langs->trans('CloudBackupRepeatPassword')).'">';
print '</td></tr>';
print '<tr class="oddeven cb-archive"><td colspan="2"><span class="opacitymedium">'.$langs->trans("CloudBackupArchiveNotEncrypted").'</span></td></tr>';
print '</table></div>';

// What
print '<br><div class="div-table-responsive-no-min"><table class="noborder centpercent">';
print '<tr class="liste_titre"><td class="titlefieldcreate">'.$langs->trans("CloudBackupWhat").'</td><td></td></tr>';
cloudbackupCheckRow('CLOUDBACKUP_WITH_DATABASE', 'CloudBackupWithDatabase', 'CloudBackupWithDatabaseHelp', '', 1);
cloudbackupCheckRow('CLOUDBACKUP_WITH_DOCUMENTS', 'CloudBackupWithDocuments', 'CloudBackupWithDocumentsHelp', '', 1);
cloudbackupCheckRow('CLOUDBACKUP_WITH_CUSTOM', 'CloudBackupWithCustom', 'CloudBackupWithCustomHelp');
cloudbackupCheckRow('CLOUDBACKUP_WITH_CONF', 'CloudBackupWithConf', 'CloudBackupWithConfHelp', 'cb-restic', 1);
print '<tr class="oddeven"><td>'.$langs->trans("CloudBackupExclude").'<br><span class="opacitymedium small">'.$langs->trans("CloudBackupExcludeHelp").'</span></td><td>';
print '<textarea name="CLOUDBACKUP_EXCLUDE" class="quatrevingtpercent" rows="3">'.dol_escape_htmltag(getDolGlobalString('CLOUDBACKUP_EXCLUDE')).'</textarea></td></tr>';
print '</table></div>';

// Retention
print '<br><div class="div-table-responsive-no-min"><table class="noborder centpercent">';
print '<tr class="liste_titre"><td class="titlefieldcreate">'.$langs->trans("CloudBackupRetention").'</td><td></td></tr>';
cloudbackupCheckRow('CLOUDBACKUP_RETENTION', 'CloudBackupRetentionEnabled', 'CloudBackupRetentionHelp', '', 1);
$policy = CloudBackup::retentionPolicy();
foreach (array('last' => 'CLOUDBACKUP_KEEP_LAST', 'daily' => 'CLOUDBACKUP_KEEP_DAILY', 'weekly' => 'CLOUDBACKUP_KEEP_WEEKLY', 'monthly' => 'CLOUDBACKUP_KEEP_MONTHLY', 'yearly' => 'CLOUDBACKUP_KEEP_YEARLY') as $rule => $name) {
	print '<tr class="oddeven'.($rule !== 'last' ? ' cb-restic' : '').'"><td>'.$langs->trans('CloudBackupKeep'.dol_ucfirst($rule)).'</td>';
	print '<td><input type="number" min="0" class="width75" name="'.$name.'" value="'.((int) $policy[$rule]).'"></td></tr>';
}
cloudbackupCheckRow('CLOUDBACKUP_PRUNE', 'CloudBackupPrune', 'CloudBackupPruneHelp', 'cb-restic', 1);
print '</table></div>';

print '<div class="center"><input type="submit" class="button button-save" value="'.$langs->trans("Save").'"></div>';
print '</form>';

// Show only the rows of the chosen storage and format
print '<script>
$(function() {
	function cloudbackupToggle() {
		var storage = $("#CLOUDBACKUP_STORAGE").val();
		$.each(["s3", "ftp", "sftp", "local"], function(i, s) { $(".cb-" + s).toggle(s === storage); });
		var format = $("#CLOUDBACKUP_FORMAT").val();
		$(".cb-restic").toggle(format === "restic");
		$(".cb-archive").toggle(format === "archive");
	}
	$("#CLOUDBACKUP_STORAGE, #CLOUDBACKUP_FORMAT").on("change", cloudbackupToggle);
	$(".cb-use-suggested").on("click", function(e) {
		e.preventDefault();
		$("#CLOUDBACKUP_LOCAL_PATH").val($(this).data("path"));
	});
	cloudbackupToggle();
});
</script>';

print '<br><div class="tabsAction">';
print '<a class="butAction" href="'.$_SERVER["PHP_SELF"].'?action=test&token='.newToken().'">'.$langs->trans("CloudBackupTest").'</a>';
print '</div>';

// Schedule
print load_fiche_titre($langs->trans("CloudBackupSchedule"), '', '');
$sql = "SELECT rowid, status, frequency, unitfrequency, datenextrun, datelastrun, lastresult FROM ".$db->prefix()."cronjob";
$sql .= " WHERE classesname = '/cloudbackup/class/cloudbackup.class.php' AND methodename = 'runScheduledBackup'";
$resql = $db->query($sql);
$job = $resql ? $db->fetch_object($resql) : null;
if ($job) {
	print '<div class="opacitymedium">'.$langs->trans($job->status ? 'CloudBackupScheduleOn' : 'CloudBackupScheduleOff');
	if ($job->status && $job->datenextrun) {
		print ' '.$langs->trans('CloudBackupNextRun', dol_print_date($db->jdate($job->datenextrun), 'dayhour', 'tzuser'));
	}
	print '</div>';
	print '<div class="tabsAction"><a class="butAction" href="'.DOL_URL_ROOT.'/cron/card.php?id='.((int) $job->rowid).'&action=edit">'.$langs->trans('CloudBackupEditSchedule').'</a></div>';
}
print '<div class="opacitymedium">'.$langs->trans('CloudBackupScheduleHelp').'</div>';
$url = DOL_MAIN_URL_ROOT.'/public/cron/cron_run_jobs_by_url.php?securitykey=CRON_KEY&userlogin='.urlencode($user->login);
print '<div class="small"><code>'.dol_escape_htmltag($url).'</code></div>';
print '<div class="small"><code>php '.dol_escape_htmltag(dirname(DOL_DOCUMENT_ROOT).'/scripts/cron/cron_run_jobs.php').' CRON_KEY '.dol_escape_htmltag($user->login).'</code></div>';

print dol_get_fiche_end();

llxFooter();
$db->close();
