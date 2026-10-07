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
 * \file       cloudbackup/class/cloudbackup.class.php
 * \ingroup    cloudbackup
 * \brief      Backup and restore of a Dolibarr instance: database dump and documents, to a restic repository or plain archives
 */

require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/utils.class.php';
require_once __DIR__.'/storage/cloudbackupstorage.class.php';
require_once __DIR__.'/storage/cloudbackupstoragelocal.class.php';
require_once __DIR__.'/resticrepository.class.php';
require_once __DIR__.'/cloudbackuparchive.class.php';
require_once __DIR__.'/cloudbackupsqlimport.class.php';
require_once __DIR__.'/cloudbackuporphans.class.php';


/**
 * Backup orchestrator, also the target of the scheduled job
 */
class CloudBackup
{
	/** A run with no sign of life for this many seconds died without saying so (SIGKILL, OOM killer) */
	const RUN_STALE = 3600;

	/** @var DoliDB */
	public $db;

	/** @var string */
	public $error = '';

	/** @var string[] */
	public $errors = array();

	/** @var string Summary of the last operation, shown in the scheduled job list */
	public $output = '';

	/** @var int Entity of the scheduled job */
	public $entity = 1;

	/** @var string[] Progress and warnings of the last operation */
	public $log = array();

	/** @var int Row of llx_cloudbackup_run of the current operation */
	private $runId = 0;

	/** @var int Last time the run row was marked alive */
	private $aliveAt = 0;

	/** @var CloudBackupResticRepository|null Repository in use, unlocked if the process dies */
	private $repo = null;

	/** @var bool */
	private $shutdownRegistered = false;

	/** @var int Bytes sent to the browser by the current download, -1 before its headers */
	private $sent = -1;

	/**
	 * Constructor
	 *
	 * @param	DoliDB	$db		Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	// ---------------------------------------------------------------------------------------------
	// Configuration

	/**
	 * Value of a secret setting
	 *
	 * @param	string	$name	Constant name
	 * @return	string
	 */
	public static function getSecret($name)
	{
		return CloudBackupStorage::secret($name);
	}

	/**
	 * Store a secret setting
	 *
	 * @param	DoliDB	$db		Database handler
	 * @param	string	$name	Constant name, ending with _PASSWORD, _SECRET or _KEY so the core encrypts it
	 * @param	string	$value	Clear value
	 * @return	int				>0 if OK
	 */
	public static function setSecret($db, $name, $value)
	{
		require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
		return dolibarr_set_const($db, $name, ($value === '' ? '' : 'b64:'.base64_encode($value)), 'chaine', 0, '', 0);
	}

	/**
	 * Format of the backups: 'restic' or 'archive'
	 *
	 * @return string
	 */
	public static function format()
	{
		return getDolGlobalString('CLOUDBACKUP_FORMAT', 'restic') === 'archive' ? 'archive' : 'restic';
	}

	/**
	 * Storage configured in the setup
	 *
	 * @return CloudBackupStorage|null
	 */
	public static function storage()
	{
		return CloudBackupStorage::fromSetup();
	}

	/**
	 * Name of this instance in the snapshots: the host of its URL
	 *
	 * @return string
	 */
	public static function instanceName()
	{
		global $dolibarr_main_url_root;
		$host = (string) parse_url((string) $dolibarr_main_url_root, PHP_URL_HOST);
		return $host !== '' ? $host : 'dolibarr';
	}

	/**
	 * What prevents a backup from running on this server, if anything
	 *
	 * @return string[]	Problems, empty if none
	 */
	public function checkPrerequisites()
	{
		global $langs;
		$problems = array();
		if (!in_array($this->db->type, array('mysql', 'mysqli'))) {
			$problems[] = $langs->trans('CloudBackupErrorDatabaseType', $this->db->type);
		}
		$missing = self::formatMissing(self::format());
		if ($missing !== '') {
			$problems[] = $langs->trans('CloudBackupErrorMissingPhp', $missing);
		}
		if (self::format() === 'restic' && self::getSecret('CLOUDBACKUP_RESTIC_PASSWORD') === '') {
			$problems[] = $langs->trans('CloudBackupErrorNoPassword');
		}
		if (getDolGlobalString('CLOUDBACKUP_STORAGE') === 'local' && self::localPathProblem(getDolGlobalString('CLOUDBACKUP_LOCAL_PATH')) !== '') {
			$problems[] = self::localPathProblem(getDolGlobalString('CLOUDBACKUP_LOCAL_PATH'));
		}
		$storage = self::storage();
		if ($storage === null) {
			$problems[] = $langs->trans('CloudBackupErrorNoStorage');
		} elseif ($storage->missingExtension() !== '') {
			$problems[] = $langs->trans('CloudBackupErrorMissingPhp', $storage->missingExtension());
		}
		return $problems;
	}

	/**
	 * What forbids a directory as local storage, if anything: it must be absolute and outside the
	 * documents, or the backup would back itself up and die with the data it saves
	 *
	 * @param	string	$path	Directory
	 * @return	string			Problem, empty if none
	 */
	public static function localPathProblem($path)
	{
		global $langs;
		$path = rtrim($path, '/');
		if ($path === '' || $path[0] !== '/' || strpos($path.'/', '/../') !== false || CloudBackupStorageLocal::isInside($path, DOL_DATA_ROOT)) {
			return $langs->trans('CloudBackupErrorLocalPath', DOL_DATA_ROOT);
		}
		return '';
	}

	/**
	 * Tell whether a directory is served by the web server: a dump there could be fetched by its URL
	 *
	 * @param	string	$path	Directory
	 * @return	bool
	 */
	public static function isPublicPath($path)
	{
		$roots = array(DOL_DOCUMENT_ROOT);
		if (!empty($_SERVER['DOCUMENT_ROOT'])) {
			$roots[] = $_SERVER['DOCUMENT_ROOT'];
		}
		foreach ($roots as $root) {
			if (CloudBackupStorageLocal::isInside($path, $root)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * A local directory that suits the backups, next to the documents or to the web root (on shared
	 * hosting, the home of the account), where PHP can create it
	 *
	 * @return string	Directory, empty if none found
	 */
	public static function suggestedLocalPath()
	{
		$candidates = array(dirname(rtrim(DOL_DATA_ROOT, '/')).'/cloudbackups');
		if (!empty($_SERVER['DOCUMENT_ROOT'])) {
			$candidates[] = dirname(rtrim($_SERVER['DOCUMENT_ROOT'], '/')).'/cloudbackups';
		}
		foreach ($candidates as $path) {
			$parent = $path;
			while ($parent !== '/' && !@is_dir($parent)) {
				$parent = dirname($parent);
			}
			if (self::localPathProblem($path) === '' && !self::isPublicPath($path) && @is_writable($parent)) {
				return $path;
			}
		}
		return '';
	}

	/**
	 * What a storage type needs and the server lacks
	 *
	 * @param	string	$type	s3, ftp, ftps, sftp, local
	 * @return	string			Missing PHP feature, empty if available
	 */
	public static function storageMissing($type)
	{
		switch ($type) {
			case 's3':
				return function_exists('curl_init') ? '' : 'php-curl';
			case 'ftp':
				return function_exists('ftp_connect') ? '' : 'php-ftp';
			case 'ftps':
				if (!function_exists('curl_init')) {
					return 'php-curl';
				}
				$curl = curl_version();
				return in_array('ftps', (array) $curl['protocols']) ? '' : 'php-curl (ftps)';
			case 'sftp':
				return function_exists('ssh2_connect') ? '' : 'php-ssh2';
		}
		return '';
	}

	/**
	 * What a backup format needs and the server lacks
	 *
	 * @param	string	$format		restic or archive
	 * @return	string				Missing PHP feature, empty if available
	 */
	public static function formatMissing($format)
	{
		if ($format === 'archive') {
			if (!class_exists('ZipArchive')) {
				return 'php-zip';
			}
			return function_exists('gzopen') ? '' : 'php-zlib';
		}
		$missing = CloudBackupResticCrypto::checkRuntime();
		return $missing === '' ? '' : $missing;
	}

	/**
	 * Memory the backup needs here, from the biggest table: the PHP dump of the core reads a whole
	 * table in memory (measured: about its size with PHP 8, twice with PHP 7).
	 *
	 * @param	DoliDB	$db		Database handler
	 * @return	array{needed:int,limit:int,raisable:bool,table:string,table_size:int}	Bytes, limit -1 = none
	 */
	public static function memoryEstimate($db)
	{
		$table = '';
		$size = 0;
		$resql = $db->query("SELECT table_name as name, data_length as size FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' ORDER BY data_length DESC LIMIT 1");
		if ($resql && ($obj = $db->fetch_object($resql))) {
			$table = (string) $obj->name;
			$size = (int) $obj->size;
		}
		$ratio = (PHP_VERSION_ID < 80000) ? 2.2 : 1.2;
		$base = 41943040;
		// Filling and uploading a pack of restic holds about 70 MB, the dump its biggest table
		$needed = (int) max($base + $size * $ratio, $base + 73400320);
		$current = (string) ini_get('memory_limit');
		$limit = ($current === '-1') ? -1 : self::toBytes($current);
		$raisable = (@ini_set('memory_limit', $current) !== false);
		return array('needed' => $needed, 'limit' => $limit, 'raisable' => $raisable, 'table' => $table, 'table_size' => $size);
	}

	/**
	 * Size for humans: dol_print_size() of the core stops at kilobytes
	 *
	 * @param	int|float	$bytes	Size
	 * @return	string
	 */
	public static function formatSize($bytes)
	{
		$units = array('B', 'KB', 'MB', 'GB', 'TB');
		$size = (float) $bytes;
		$unit = 0;
		while ($size >= 1024 && $unit < count($units) - 1) {
			$size /= 1024;
			$unit++;
		}
		return ($unit === 0 ? (int) $size : number_format($size, $size < 10 ? 1 : 0)).' '.$units[$unit];
	}

	/**
	 * Working directory, outside the backed up tree (excluded)
	 *
	 * @return string
	 */
	public static function workDir()
	{
		$dir = DOL_DATA_ROOT.'/cloudbackup/temp';
		dol_mkdir($dir, DOL_DATA_ROOT);
		return $dir;
	}

	/**
	 * Tell whether a path of the documents directory is left out of the backup. Same rules as the
	 * backup tool of the core (temp files, logs, previews, old backups), plus install.lock (it belongs
	 * to the installation, and is often read-only) and the patterns of the setup.
	 *
	 * @param	string	$path	Full path
	 * @return	bool
	 */
	public static function isExcluded($path)
	{
		$test = dol_is_dir($path) ? $path.'/' : $path;
		if (preg_match('/(\.back|\.old|\.log|\.pdf_preview-.*\.png)$|[\/\\\\]temp[\/\\\\]|[\/\\\\]admin[\/\\\\](documents|backup)[\/\\\\]|\.cloudbackup-tmp$|^'.preg_quote(DOL_DATA_ROOT, '/').'\/install\.lock$/i', $test)) {
			return true;
		}
		if (strpos($test, DOL_DATA_ROOT.'/cloudbackup/') === 0) {
			return true;
		}
		$extra = getDolGlobalString('CLOUDBACKUP_EXCLUDE');
		if ($extra !== '') {
			foreach (preg_split('/\r?\n/', $extra) as $pattern) {
				$pattern = trim($pattern);
				if ($pattern !== '' && fnmatch($pattern, dol_substr($test, dol_strlen(DOL_DATA_ROOT) + 1))) {
					return true;
				}
			}
		}
		return false;
	}

	// ---------------------------------------------------------------------------------------------
	// Run history

	/**
	 * Record the start of an operation
	 *
	 * @param	string	$action		backup, restore, prune, check
	 * @param	string	$origin		manual, cron
	 * @return	int					Row id, <0 on error
	 */
	private function startRun($action, $origin)
	{
		global $user;
		$this->log = array();
		$sql = "INSERT INTO ".$this->db->prefix()."cloudbackup_run (entity, action, origin, format, storage, status, date_start, date_alive, fk_user)";
		$sql .= " VALUES (".((int) $this->entity).", '".$this->db->escape($action)."', '".$this->db->escape($origin)."', '".$this->db->escape(self::format())."',";
		$sql .= " '".$this->db->escape(getDolGlobalString('CLOUDBACKUP_STORAGE', 'local'))."', 0, '".$this->db->idate(dol_now())."', '".$this->db->idate(dol_now())."', ".(is_object($user) && !empty($user->id) ? (int) $user->id : 'NULL').")";
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$this->runId = (int) $this->db->last_insert_id($this->db->prefix().'cloudbackup_run');
		$this->aliveAt = dol_now();
		if (!$this->shutdownRegistered) {
			// A fatal error, the memory or time limit: close the run and the lock, or both block for hours
			register_shutdown_function(array($this, 'onShutdown'));
			$this->shutdownRegistered = true;
		}
		return $this->runId;
	}

	/**
	 * Record the end of an operation
	 *
	 * @param	int					$status		1 OK, -1 error
	 * @param	string				$message	Message
	 * @param	array<string,mixed>	$info		snapshot, nb_files, bytes_total, bytes_added
	 * @return	void
	 */
	private function endRun($status, $message, $info = array())
	{
		if ($this->runId <= 0) {
			return;
		}
		$sql = "UPDATE ".$this->db->prefix()."cloudbackup_run SET status = ".((int) $status).", date_end = '".$this->db->idate(dol_now())."'";
		$sql .= ", message = '".$this->db->escape(dol_trunc($message, 60000))."'";
		foreach (array('snapshot' => 's', 'nb_files' => 'i', 'bytes_total' => 'i', 'bytes_added' => 'i') as $field => $kind) {
			if (isset($info[$field])) {
				$sql .= ", ".$field." = ".($kind === 'i' ? ((int) $info[$field]) : "'".$this->db->escape($info[$field])."'");
			}
		}
		$sql .= " WHERE rowid = ".((int) $this->runId);
		$this->db->query($sql);
		$this->runId = 0;
	}

	/**
	 * Called at the end of the process: an operation still open here died without saying so
	 *
	 * @return void
	 */
	public function onShutdown()
	{
		if ($this->repo !== null) {
			$this->repo->unlock();
		}
		if ($this->runId > 0) {
			$last = error_get_last();
			$this->addLog('ERROR interrupted'.($last ? ': '.$last['message'] : ''), LOG_ERR);
			$this->endRun(-1, implode("\n", $this->log));
		}
	}

	/**
	 * Tell whether an operation is running on this instance
	 *
	 * @return bool
	 */
	public function isRunning()
	{
		$sql = "SELECT COUNT(*) as nb FROM ".$this->db->prefix()."cloudbackup_run WHERE status = 0";
		$sql .= " AND date_alive > '".$this->db->idate(dol_now() - self::RUN_STALE)."'";
		$resql = $this->db->query($sql);
		$obj = $resql ? $this->db->fetch_object($resql) : null;
		return $obj && $obj->nb > 0;
	}

	/**
	 * Add a line to the operation log
	 *
	 * @param	string	$message	Message
	 * @param	int		$level		Syslog level
	 * @return	void
	 */
	public function addLog($message, $level = LOG_INFO)
	{
		$this->log[] = dol_print_date(dol_now(), '%H:%M:%S', 'tzuser').' '.$message;
		dol_syslog('CloudBackup: '.$message, $level);
		$this->markAlive();
	}

	/**
	 * Sign of life, at most once a minute, so a killed process is told from a slow one
	 *
	 * @return void
	 */
	private function markAlive()
	{
		if ($this->runId > 0 && dol_now() - $this->aliveAt >= 60) {
			$this->aliveAt = dol_now();
			$this->db->query("UPDATE ".$this->db->prefix()."cloudbackup_run SET date_alive = '".$this->db->idate(dol_now())."' WHERE rowid = ".((int) $this->runId));
		}
	}

	/**
	 * Give the process room for a long operation
	 *
	 * @return void
	 */
	private function prepareLongRun()
	{
		if (function_exists('set_time_limit')) {
			@set_time_limit(0);
		}
		@ignore_user_abort(true);
		$estimate = self::memoryEstimate($this->db);
		$wanted = max(268435456, $estimate['needed']);
		if ($estimate['limit'] !== -1 && $estimate['limit'] < $wanted) {
			if (!@ini_set('memory_limit', (string) ceil($wanted / 1048576).'M')) {
				$this->addLog('WARNING memory_limit is '.self::formatSize($estimate['limit']).' and cannot be raised, the backup may need '.self::formatSize($estimate['needed']), LOG_WARNING);
			}
		}
	}

	/**
	 * Bytes of a php.ini size
	 *
	 * @param	string	$value	Like 128M
	 * @return	int
	 */
	public static function toBytes($value)
	{
		$number = (int) $value;
		switch (dol_strtoupper(dol_substr(trim($value), -1))) {
			case 'G':
				return $number * 1073741824;
			case 'M':
				return $number * 1048576;
			case 'K':
				return $number * 1024;
		}
		return $number;
	}

	// ---------------------------------------------------------------------------------------------
	// Scheduled job

	/**
	 * Entry point of the scheduled job
	 *
	 * @return int		0 if OK, <0 if KO (as the scheduler expects)
	 */
	public function runScheduledBackup()
	{
		global $langs;
		$langs->load('cloudbackup@cloudbackup');
		$result = $this->backup('cron');
		$this->output = implode("\n", array_slice($this->log, -30));
		return $result > 0 ? 0 : -1;
	}

	// ---------------------------------------------------------------------------------------------
	// Backup

	/**
	 * Back up the database and the documents
	 *
	 * @param	string	$origin		manual, cron, pre-restore
	 * @return	int					>0 if OK, <0 if KO ($this->error set)
	 */
	public function backup($origin = 'manual')
	{
		global $langs;
		$langs->load('cloudbackup@cloudbackup');

		$problems = $this->checkPrerequisites();
		if (count($problems)) {
			$this->error = implode(', ', $problems);
			return -1;
		}
		if ($origin !== 'pre-restore' && $this->isRunning()) {
			$this->error = $langs->trans('CloudBackupErrorAlreadyRunning');
			return -1;
		}
		if ($this->startRun('backup', $origin) < 0) {
			return -1;
		}
		$this->prepareLongRun();

		$dump = '';
		try {
			$dump = $this->dumpDatabase();
			if (self::format() === 'restic') {
				$info = $this->backupRestic($dump);
			} else {
				$info = $this->backupArchive($dump);
			}
		} catch (Exception $e) {
			if ($dump !== '') {
				dol_delete_file($dump, 1, 1, 1, null, false, 0);
			}
			$this->error = $e->getMessage();
			$this->addLog('ERROR '.$this->error, LOG_ERR);
			$this->endRun(-1, implode("\n", $this->log));
			return -1;
		}
		dol_delete_file($dump, 1, 1, 1, null, false, 0);
		$this->endRun(1, implode("\n", $this->log), $info);
		return 1;
	}

	/**
	 * Dump the database with the backup tool of the core, in its PHP flavour (no mysqldump needed)
	 *
	 * @return string	Path of the dump, in the working directory
	 * @throws CloudBackupException
	 */
	private function dumpDatabase()
	{
		global $conf, $dolibarr_main_db_name;

		if (!getDolGlobalInt('CLOUDBACKUP_WITH_DATABASE', 1)) {
			return '';
		}
		$this->addLog('Dump database '.$dolibarr_main_db_name);
		$file = 'cloudbackup_'.dol_sanitizeFileName($dolibarr_main_db_name).'_'.dol_print_date(dol_now('gmt'), '%Y%m%d%H%M%S', 'gmt').'.sql';

		// The options of the core dump are read from the request: DROP TABLE before CREATE TABLE so
		// the dump replaces the tables, no LOCK TABLES and no key checks while replaying
		$saved = $_POST;
		$_POST['nobin_drop'] = '1';
		$_POST['nobin_nolocks'] = '1';
		$_POST['nobin_disable_fk'] = '1';
		$utils = new Utils($this->db);
		$result = $utils->dumpDatabase('none', 'mysqlnobin', 1, $file);
		$_POST = $saved;

		$source = $conf->admin->dir_output.'/backup/'.$file;
		if ($result < 0 || !dol_is_file($source)) {
			throw new CloudBackupException('Database dump failed: '.$utils->error);
		}
		$target = self::workDir().'/'.$file;
		if (!dol_move($source, $target, '0', 1, 0, 0)) {
			dol_delete_file($source, 1, 1, 1, null, false, 0);
			throw new CloudBackupException('Cannot move the dump to '.$target);
		}
		if (!CloudBackupSqlImport::isComplete($target)) {
			dol_delete_file($target, 1, 1, 1, null, false, 0);
			throw new CloudBackupException('The database dump is incomplete (not enough memory or time?)');
		}
		$this->addLog('Dump done, '.self::formatSize(dol_filesize($target)));
		return $target;
	}

	/**
	 * Open (and create, the first time) the restic repository of the setup
	 *
	 * @return CloudBackupResticRepository
	 * @throws CloudBackupException
	 */
	public function openRepository()
	{
		global $user;
		$storage = self::storage();
		if ($storage === null) {
			throw new CloudBackupException('No storage configured');
		}
		$repo = new CloudBackupResticRepository($storage, self::getSecret('CLOUDBACKUP_RESTIC_PASSWORD'));
		$repo->hostname = self::instanceName();
		$repo->username = (is_object($user) && !empty($user->login)) ? $user->login : 'cron';
		$self = $this;
		$repo->progress = function ($message) use ($self) {
			$self->addLog($message, LOG_DEBUG);
		};
		if (!$repo->exists()) {
			$this->addLog('No repository at this location: create it');
			$repo->init();
		} else {
			$repo->open();
		}
		$this->repo = $repo;
		return $repo;
	}

	/**
	 * Back up into the restic repository
	 *
	 * @param	string	$dump	Database dump, '' if the database is not backed up
	 * @return	array{snapshot:string,nb_files:int,bytes_total:int,bytes_added:int}	Run info
	 * @throws CloudBackupException
	 */
	private function backupRestic($dump)
	{
		global $dolibarr_main_db_name;
		$start = microtime(true);
		$info = array('snapshot' => '', 'nb_files' => 0, 'bytes_total' => 0, 'bytes_added' => 0);
		$repo = $this->openRepository();
		try {
			$repo->lock(false);
			$repo->loadIndex();

			// The previous snapshot of this instance: unchanged files are not read again
			$parent = null;
			foreach ($repo->listSnapshots() as $snapshot) {
				if (self::isOwnSnapshot($snapshot)) {
					$parent = $snapshot;
				}
			}
			$parentTree = $parent ? $parent['tree'] : null;
			$now = dol_now();
			$nodes = array();
			$paths = array();

			if ($dump !== '') {
				$previous = $parentTree ? $repo->findNode($parentTree, 'database/'.$dolibarr_main_db_name.'.sql') : null;
				$fileNode = $repo->fileNodeFor($dolibarr_main_db_name.'.sql', $dump, stat($dump), $previous, true);
				$nodes[] = CloudBackupResticRepository::dirNode('database', $repo->saveTree(array($fileNode)), $now);
				$paths[] = '/database';
				$this->addLog('Database stored');
			}
			$sources = array();
			if (getDolGlobalInt('CLOUDBACKUP_WITH_DOCUMENTS', 1)) {
				$sources['documents'] = DOL_DATA_ROOT;
			}
			if (getDolGlobalInt('CLOUDBACKUP_WITH_CUSTOM') && dol_is_dir(DOL_DOCUMENT_ROOT.'/custom')) {
				$sources['custom'] = DOL_DOCUMENT_ROOT.'/custom';
			}
			foreach ($sources as $name => $dir) {
				$this->addLog('Store '.$name.' ('.$dir.')');
				$previous = $parentTree ? $repo->findNode($parentTree, $name) : null;
				$exclude = ($name === 'documents') ? array('CloudBackup', 'isExcluded') : null;
				$subtree = $repo->saveDirectory($dir, ($previous && $previous['type'] === 'dir') ? $previous['subtree'] : null, $exclude);
				$nodes[] = CloudBackupResticRepository::dirNode($name, $subtree, $now);
				$paths[] = '/'.$name;
			}
			$conffile = DOL_DOCUMENT_ROOT.'/conf/conf.php';
			if (getDolGlobalInt('CLOUDBACKUP_WITH_CONF', 1) && is_readable($conffile)) {
				// The unique id of conf.php decrypts the secrets of the database: without it a restore on
				// a new server loses them. The repository is encrypted, so the file is safe there.
				$nodes[] = CloudBackupResticRepository::dirNode('conf', $repo->saveTree(array($repo->fileNodeFor('conf.php', $conffile, stat($conffile)))), $now);
				$paths[] = '/conf';
			}
			if (!count($nodes)) {
				throw new CloudBackupException('Nothing to back up: database and documents are both disabled');
			}
			$root = $repo->saveTree($nodes);
			$repo->flush();
			$snapshotId = $repo->saveSnapshot(array(
				'time' => CloudBackupResticRepository::formatTime(microtime(true)),
				'tree' => $root,
				'paths' => $paths,
				'hostname' => $repo->hostname,
				'username' => $repo->username,
				'tags' => array('cloudbackup', 'dolibarr-'.DOL_VERSION),
				'program_version' => 'dolibarr-cloudbackup '.self::moduleVersion(),
				'summary' => array(
					'backup_start' => CloudBackupResticRepository::formatTime($start),
					'backup_end' => CloudBackupResticRepository::formatTime(microtime(true)),
					'files_new' => $repo->stats['files_new'],
					'files_unmodified' => $repo->stats['files_unmodified'],
					'data_added' => $repo->stats['bytes_added'],
					'total_files_processed' => $repo->stats['files'],
					'total_bytes_processed' => $repo->stats['bytes'],
				),
			));
			$this->addLog('Snapshot '.substr($snapshotId, 0, 8).' saved: '.$repo->stats['files'].' files, '.self::formatSize($repo->stats['bytes']).', '.self::formatSize($repo->stats['bytes_added']).' new');
			foreach ($repo->warnings as $warning) {
				$this->addLog('WARNING '.$warning, LOG_WARNING);
			}
			$info = array('snapshot' => $snapshotId, 'nb_files' => $repo->stats['files'], 'bytes_total' => $repo->stats['bytes'], 'bytes_added' => $repo->stats['bytes_added']);
			$repo->unlock();
			$this->applyRetention($repo);
			$this->measureUsage(function () use ($repo) {
				return self::resticBackups($repo);
			});
		} finally {
			$repo->close();
		}
		return $info;
	}

	/**
	 * Number of snapshots of a repository and the size of the data they hold
	 *
	 * @param	CloudBackupResticRepository	$repo	Repository
	 * @return	array{0:int,1:int}
	 * @throws CloudBackupException
	 */
	private static function resticBackups($repo)
	{
		$bytes = 0;
		$snapshots = $repo->listSnapshots();
		foreach ($snapshots as $snapshot) {
			$bytes += isset($snapshot['summary']['total_bytes_processed']) ? (int) $snapshot['summary']['total_bytes_processed'] : 0;
		}
		return array(count($snapshots), $bytes);
	}

	/**
	 * Number of archives of a storage and the size of their files
	 *
	 * @param	CloudBackupArchive	$archive	Archives
	 * @return	array{0:int,1:int}
	 * @throws CloudBackupException
	 */
	private static function archiveBackups($archive)
	{
		$bytes = 0;
		$archives = $archive->listArchives();
		foreach ($archives as $manifest) {
			foreach ($manifest['files'] as $file) {
				$bytes += (int) $file['size'];
			}
		}
		return array(count($archives), $bytes);
	}

	/**
	 * Measure the space the backups take on the storage and keep it for the Backups page. Done after a
	 * backup or a check, never when the page shows: listing a remote storage takes time. The free space
	 * is not measured, PHP sees the disk of the server, not the quota of a shared hosting.
	 *
	 * @param	callable	$count	Gives the number of backups and the size of the data they hold
	 * @return	void
	 */
	private function measureUsage($count)
	{
		require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
		$storage = self::storage();
		if ($storage === null) {
			return;
		}
		// A measure that fails must not fail the backup it follows
		try {
			$backups = call_user_func($count);
		} catch (Exception $e) {
			$this->addLog('WARNING space used not measured: '.$e->getMessage(), LOG_WARNING);
			return;
		}
		$files = $storage->listFiles('');
		$storage->close();
		if ($files === false) {
			$this->addLog('WARNING space used not measured: '.$storage->error, LOG_WARNING);
			return;
		}
		$used = 0;
		foreach ($files as $size) {
			// A storage that cannot tell sizes (FTPS without certificate check)
			if ($size < 0) {
				$used = -1;
				break;
			}
			$used += $size;
		}
		dolibarr_set_const($this->db, 'CLOUDBACKUP_USAGE', json_encode(array('used' => $used, 'backups' => $backups[0], 'data' => $backups[1], 'date' => dol_now())), 'chaine', 0, '', 0);
		$this->addLog('Space used on the storage: '.($used < 0 ? 'unknown' : self::formatSize($used)).' for '.$backups[0].' backup(s)');
	}

	/**
	 * Space used on the storage, as last measured
	 *
	 * @return array{used:int,backups:int,data:int,date:int}|null	Null if never measured
	 */
	public static function usage()
	{
		$usage = json_decode(getDolGlobalString('CLOUDBACKUP_USAGE'), true);
		if (!is_array($usage) || !isset($usage['used'], $usage['backups'], $usage['data'], $usage['date'])) {
			return null;
		}
		return array('used' => (int) $usage['used'], 'backups' => (int) $usage['backups'], 'data' => (int) $usage['data'], 'date' => (int) $usage['date']);
	}

	/**
	 * Tell whether a snapshot was made by this module for this instance
	 *
	 * @param	array<string,mixed>	$snapshot	Snapshot
	 * @return	bool
	 */
	public static function isOwnSnapshot($snapshot)
	{
		return isset($snapshot['tags']) && is_array($snapshot['tags']) && in_array('cloudbackup', $snapshot['tags'])
			&& isset($snapshot['hostname']) && $snapshot['hostname'] === self::instanceName();
	}

	/**
	 * Retention policy of the setup
	 *
	 * @return array<string,int>
	 */
	public static function retentionPolicy()
	{
		return array(
			'last' => getDolGlobalInt('CLOUDBACKUP_KEEP_LAST', 7),
			'daily' => getDolGlobalInt('CLOUDBACKUP_KEEP_DAILY', 14),
			'weekly' => getDolGlobalInt('CLOUDBACKUP_KEEP_WEEKLY', 8),
			'monthly' => getDolGlobalInt('CLOUDBACKUP_KEEP_MONTHLY', 12),
			'yearly' => getDolGlobalInt('CLOUDBACKUP_KEEP_YEARLY', 0),
		);
	}

	/**
	 * Forget the snapshots of this instance the policy does not keep, then prune
	 *
	 * @param	CloudBackupResticRepository	$repo	Opened repository, not locked
	 * @return	void
	 * @throws CloudBackupException
	 */
	private function applyRetention($repo)
	{
		if (!getDolGlobalInt('CLOUDBACKUP_RETENTION', 1)) {
			return;
		}
		try {
			$repo->lock(true);
		} catch (CloudBackupException $e) {
			// Another client uses the repository: retention waits for the next run
			$this->addLog('Retention skipped: '.$e->getMessage(), LOG_WARNING);
			return;
		}
		$result = $repo->forget(self::retentionPolicy(), array('CloudBackup', 'isOwnSnapshot'));
		$this->addLog('Retention: '.count($result['keep']).' snapshot(s) kept, '.count($result['remove']).' removed');
		if (count($result['remove']) && getDolGlobalInt('CLOUDBACKUP_PRUNE', 1)) {
			$repo->prune();
			$this->addLog('Prune: '.$repo->stats['packs_deleted'].' pack(s) deleted');
		}
		$repo->unlock();
	}

	/**
	 * Back up as plain archives: a gzipped dump and a zip of the documents, readable without any tool
	 *
	 * @param	string	$dump	Database dump, '' if not backed up
	 * @return	array{snapshot:string,bytes_total:int,bytes_added:int}	Run info
	 * @throws CloudBackupException
	 */
	private function backupArchive($dump)
	{
		global $dolibarr_main_db_name;
		$name = '';
		$total = 0;
		$storage = self::storage();
		$archive = new CloudBackupArchive($storage);
		$self = $this;
		$archive->progress = function ($message) use ($self) {
			$self->addLog($message);
		};
		$work = self::workDir();
		$files = array();
		$temp = array();
		try {
			if ($dump !== '') {
				$gz = $work.'/database.sql.gz';
				self::gzipFile($dump, $gz);
				$files[$dolibarr_main_db_name.'.sql.gz'] = $gz;
				$temp[] = $gz;
			}
			if (getDolGlobalInt('CLOUDBACKUP_WITH_DOCUMENTS', 1)) {
				$zip = $work.'/documents.zip';
				$this->addLog('Compress documents');
				$this->zipDirectory(DOL_DATA_ROOT, $zip, true);
				$files['documents.zip'] = $zip;
				$temp[] = $zip;
			}
			if (getDolGlobalInt('CLOUDBACKUP_WITH_CUSTOM') && dol_is_dir(DOL_DOCUMENT_ROOT.'/custom')) {
				$zip = $work.'/custom.zip';
				$this->zipDirectory(DOL_DOCUMENT_ROOT.'/custom', $zip, false);
				$files['custom.zip'] = $zip;
				$temp[] = $zip;
			}
			$name = $archive->store($files, array('host' => self::instanceName(), 'dolibarr' => DOL_VERSION, 'database' => $dolibarr_main_db_name, 'module' => self::moduleVersion()));
			$total = 0;
			foreach ($files as $file) {
				$total += dol_filesize($file);
			}
			$this->addLog('Archive '.$name.' stored, '.self::formatSize($total));
			$removed = $archive->applyRetention(getDolGlobalInt('CLOUDBACKUP_KEEP_LAST', 7));
			if ($removed) {
				$this->addLog('Retention: '.$removed.' archive(s) removed');
			}
			$this->measureUsage(function () use ($archive) {
				return self::archiveBackups($archive);
			});
		} finally {
			foreach ($temp as $file) {
				dol_delete_file($file, 1, 1, 1, null, false, 0);
			}
			$storage->close();
		}
		return array('snapshot' => $name, 'bytes_total' => $total, 'bytes_added' => $total);
	}

	/**
	 * Gzip a file by blocks. dol_compress_file() of the core reads the whole file in memory, three
	 * times over: a dump of a few hundred MB would exceed the memory limit of a shared hosting.
	 *
	 * @param	string	$source		File
	 * @param	string	$target		Gzipped file
	 * @return	void
	 * @throws CloudBackupException
	 */
	private static function gzipFile($source, $target)
	{
		$in = @fopen($source, 'rb');
		$out = @gzopen($target, 'wb6');
		if (!$in || !$out) {
			throw new CloudBackupException('Cannot compress '.$source);
		}
		while (!feof($in)) {
			$data = fread($in, 1048576);
			if ($data === false || ($data !== '' && gzwrite($out, $data) === false)) {
				fclose($in);
				gzclose($out);
				throw new CloudBackupException('Cannot compress '.$source);
			}
		}
		fclose($in);
		gzclose($out);
	}

	/**
	 * Zip a directory with the zip function of the core
	 *
	 * @param	string	$dir		Directory
	 * @param	string	$zip		Zip file
	 * @param	bool	$exclude	Apply the exclusion rules of the documents
	 * @return	void
	 * @throws CloudBackupException
	 */
	private function zipDirectory($dir, $zip, $exclude)
	{
		global $errormsg;
		// dol_compress_dir takes a regex on the real path: the rules of isExcluded() in that form, in UTF-8
		// mode (without it the ° of the class matches the first byte of a non-breaking space)
		$regex = $exclude ? '/[<>?*|"°$;`~]|\.\.|--|(\.back|\.old|\.log|\.pdf_preview-.*\.png)$|[\/\\\\]temp[\/\\\\]|[\/\\\\]admin[\/\\\\](documents|backup)[\/\\\\]|^'.preg_quote(DOL_DATA_ROOT.'/cloudbackup/', '/').'|^'.preg_quote(DOL_DATA_ROOT, '/').'\/install\.lock$/iu' : '';
		$result = dol_compress_dir($dir, $zip, 'zip', $regex);
		if ($result < 0 || !dol_is_file($zip)) {
			throw new CloudBackupException('Cannot build '.dol_basename($zip).': '.$errormsg);
		}
	}

	// ---------------------------------------------------------------------------------------------
	// Listing

	/**
	 * Backups available on the storage, newest first
	 *
	 * @return list<array{id:string,time:int,host:string,dolibarr:string,own:bool,paths:string[],size:?int}>
	 * @throws CloudBackupException
	 */
	public function listBackups()
	{
		$list = array();
		if (self::format() === 'restic') {
			$repo = $this->openRepository();
			try {
				foreach ($repo->listSnapshots() as $id => $snapshot) {
					$version = '';
					foreach ((array) (isset($snapshot['tags']) ? $snapshot['tags'] : array()) as $tag) {
						if (strpos($tag, 'dolibarr-') === 0) {
							$version = substr($tag, 9);
						}
					}
					$list[] = array(
						'id' => $id,
						'time' => CloudBackupResticRepository::parseTime($snapshot['time']),
						'host' => $snapshot['hostname'],
						'dolibarr' => $version,
						'own' => self::isOwnSnapshot($snapshot),
						'paths' => $snapshot['paths'],
						'size' => isset($snapshot['summary']['total_bytes_processed']) ? $snapshot['summary']['total_bytes_processed'] : null,
					);
				}
			} finally {
				$repo->close();
			}
		} else {
			$storage = self::storage();
			$archive = new CloudBackupArchive($storage);
			foreach ($archive->listArchives() as $name => $manifest) {
				$size = 0;
				foreach ($manifest['files'] as $file) {
					$size += $file['size'];
				}
				$list[] = array(
					'id' => $name,
					'time' => (int) $manifest['time'],
					'host' => $manifest['host'],
					'dolibarr' => $manifest['dolibarr'],
					'own' => $manifest['host'] === self::instanceName(),
					'paths' => array_keys($manifest['files']),
					'size' => $size,
				);
			}
			$storage->close();
		}
		usort($list, function ($a, $b) {
			return $b['time'] <=> $a['time'];
		});
		return $list;
	}

	// ---------------------------------------------------------------------------------------------
	// Restore

	/**
	 * Restore a backup
	 *
	 * @param	string		$id				Snapshot id or archive name
	 * @param	string[]	$parts			Among 'database', 'documents', 'custom'
	 * @param	bool		$safetyBackup	Back up the current state first
	 * @param	bool		$deleteExtra	Delete the documents the backup does not have
	 * @return	int							>0 if OK, <0 if KO
	 */
	public function restore($id, $parts, $safetyBackup = true, $deleteExtra = false)
	{
		global $langs;
		$langs->load('cloudbackup@cloudbackup');

		$problems = $this->checkPrerequisites();
		if (count($problems)) {
			$this->error = implode(', ', $problems);
			return -1;
		}
		if ($this->isRunning()) {
			$this->error = $langs->trans('CloudBackupErrorAlreadyRunning');
			return -1;
		}
		if ($safetyBackup) {
			if ($this->backup('pre-restore') < 0) {
				$this->error = $langs->trans('CloudBackupErrorSafetyBackup', $this->error);
				return -1;
			}
		}
		if ($this->startRun('restore', 'manual') < 0) {
			return -1;
		}
		$this->prepareLongRun();
		$this->addLog('Restore '.$id.' ('.implode(', ', $parts).')');
		$work = self::workDir();
		$dumpFile = '';
		$seen = array();
		try {
			if (self::format() === 'restic') {
				$repo = $this->openRepository();
				try {
					$snapshot = $repo->loadSnapshot($id);
					$tree = $snapshot['tree'];
					if (in_array('database', $parts)) {
						$node = self::databaseNode($repo, $tree);
						$dumpFile = $work.'/restore-'.dol_sanitizeFileName($node['name']);
						$repo->restoreFile($node, $dumpFile);
						// The database first: a document that cannot be written must not leave it unrestored
						$this->restoreDatabase($dumpFile);
						dol_delete_file($dumpFile, 1, 1, 1, null, false, 0);
						$dumpFile = '';
					}
					$targets = array('documents' => DOL_DATA_ROOT, 'custom' => DOL_DOCUMENT_ROOT.'/custom');
					foreach ($targets as $part => $target) {
						if (!in_array($part, $parts)) {
							continue;
						}
						$node = $repo->findNode($tree, $part);
						if ($node === null || $node['type'] !== 'dir') {
							throw new CloudBackupException('This snapshot has no '.$part);
						}
						$this->addLog('Restore '.$part.' into '.$target);
						$repo->resetStats();
						$repo->seen = array();
						$repo->restoreDirectory($node['subtree'], $target, ($part === 'documents') ? array('CloudBackup', 'isExcluded') : null, ($part === 'documents') ? DOL_DATA_ROOT : DOL_DOCUMENT_ROOT);
						if ($part === 'documents') {
							$seen = $repo->seen;
						}
						$this->addLog(dol_ucfirst($part).': '.$repo->stats['files_new'].' file(s) written, '.$repo->stats['files_unmodified'].' already up to date');
					}
					foreach ($repo->warnings as $warning) {
						$this->addLog('WARNING '.$warning, LOG_WARNING);
					}
					foreach ($repo->failures as $failure) {
						$this->addLog('ERROR '.$failure, LOG_ERR);
					}
					if (count($repo->failures)) {
						throw new CloudBackupException(count($repo->failures).' file(s) could not be restored, the others were: '.$repo->failures[0]);
					}
				} finally {
					$repo->close();
				}
			} else {
				$storage = self::storage();
				$archive = new CloudBackupArchive($storage);
				$manifest = $archive->loadManifest($id);
				// The database first: a document that cannot be written must not leave it unrestored
				uksort($manifest['files'], function ($a, $b) {
					return (int) !preg_match('/\.sql(\.gz)?$/', $a) - (int) !preg_match('/\.sql(\.gz)?$/', $b);
				});
				try {
					foreach ($manifest['files'] as $name => $file) {
						$part = preg_match('/\.sql(\.gz)?$/', $name) ? 'database' : preg_replace('/\.zip$/', '', dol_basename($name));
						if (!in_array($part, $parts)) {
							continue;
						}
						$local = $work.'/restore-'.dol_sanitizeFileName($name);
						$this->addLog('Download '.$name);
						$archive->fetch($id, $name, $local);
						if ($part === 'database') {
							$this->restoreDatabase($local);
							dol_delete_file($local, 1, 1, 1, null, false, 0);
							continue;
						}
						$target = ($part === 'custom') ? DOL_DOCUMENT_ROOT.'/custom' : DOL_DATA_ROOT;
						$this->addLog('Extract '.$name.' into '.$target);
						list($failed, $skipped, $extracted) = self::extractZip($local, $target);
						if ($part === 'documents') {
							$seen = $extracted;
						}
						dol_delete_file($local, 1, 1, 1, null, false, 0);
						foreach ($skipped as $warning) {
							$this->addLog('WARNING '.$warning, LOG_WARNING);
						}
						foreach ($failed as $failure) {
							$this->addLog('ERROR '.$failure, LOG_ERR);
						}
						if (count($failed)) {
							throw new CloudBackupException(count($failed).' file(s) of '.$name.' could not be restored, the others were: '.$failed[0]);
						}
					}
				} finally {
					$storage->close();
				}
			}
		} catch (Exception $e) {
			if ($dumpFile !== '') {
				dol_delete_file($dumpFile, 1, 1, 1, null, false, 0);
			}
			$this->error = $e->getMessage();
			$this->addLog('ERROR '.$this->error, LOG_ERR);
			$this->endRun(-1, implode("\n", $this->log));
			return -1;
		}
		if (in_array('documents', $parts)) {
			$this->handleExtraFiles($id, $seen, $deleteExtra);
		}
		$this->addLog('Restore done');
		$this->endRun(1, implode("\n", $this->log));
		return 1;
	}

	/**
	 * Node of the database dump in a snapshot
	 *
	 * @param	CloudBackupResticRepository	$repo	Repository
	 * @param	string						$tree	Root tree of the snapshot
	 * @return	array<string,mixed>
	 * @throws CloudBackupException
	 */
	private static function databaseNode($repo, $tree)
	{
		$dir = $repo->findNode($tree, 'database');
		$node = null;
		if ($dir !== null && $dir['type'] === 'dir') {
			foreach ($repo->loadTree($dir['subtree']) as $child) {
				if ($child['type'] === 'file' && preg_match('/\.sql$/', $child['name'])) {
					$node = $child;
				}
			}
		}
		if ($node === null) {
			throw new CloudBackupException('This snapshot has no database dump');
		}
		return $node;
	}

	/**
	 * After a restore of the documents, find those the backup did not have. They are kept unless asked:
	 * the restored database may not know them any more, the Orphans tab lists them. Excluded paths and
	 * names Dolibarr refuses (never backed up) are never touched.
	 *
	 * @param	string				$id			Backup restored
	 * @param	array<string,bool>	$seen		Documents of the backup, by full path
	 * @param	bool				$delete		Delete them
	 * @return	void
	 */
	private function handleExtraFiles($id, $seen, $delete)
	{
		$extra = array();
		foreach (dol_dir_list(DOL_DATA_ROOT, 'files', 1, '', null, 'fullname', SORT_ASC, 0, 1, '', 1) as $file) {
			$path = $file['fullname'];
			if (isset($seen[$path]) || self::isExcluded($path)) {
				continue;
			}
			$relative = dol_substr($path, dol_strlen(DOL_DATA_ROOT) + 1);
			foreach (explode('/', $relative) as $segment) {
				if (!preg_match('//u', $segment) || !CloudBackupResticRepository::isAcceptedName($segment)) {
					continue 2;
				}
			}
			$extra[] = $relative;
		}
		$kept = $extra;
		if ($delete && count($extra)) {
			$kept = array();
			foreach ($extra as $relative) {
				if (!dol_delete_file(DOL_DATA_ROOT.'/'.$relative, 1, 1, 1)) {
					$kept[] = $relative;
					$this->addLog('WARNING Cannot delete '.$relative, LOG_WARNING);
				} else {
					// As repair.php of the core: the directory goes too when it is left empty
					dol_delete_dir(dirname(DOL_DATA_ROOT.'/'.$relative), 1);
				}
			}
			$this->addLog((count($extra) - count($kept)).' document(s) absent from the backup deleted'.self::sample(array_diff($extra, $kept)));
		} elseif (count($extra)) {
			$this->addLog('WARNING '.count($extra).' document(s) absent from the backup were kept: the restored database may not know them any more (see the Orphans tab)'.self::sample($extra), LOG_WARNING);
		}
		CloudBackupOrphans::saveKeptFiles($id, $kept);
	}

	/**
	 * First paths of a list, for the log
	 *
	 * @param	string[]	$paths	Paths
	 * @return	string
	 */
	private static function sample($paths)
	{
		$paths = array_values($paths);
		if (!count($paths)) {
			return '';
		}
		return ': '.implode(', ', array_slice($paths, 0, 10)).(count($paths) > 10 ? ', ...' : '');
	}

	/**
	 * Extract a zip entry by entry. dol_uncompress() of the core stops at the first file it cannot
	 * write (a read-only file of the documents): here the others are extracted all the same.
	 *
	 * @param	string	$zipFile	Zip
	 * @param	string	$target		Directory
	 * @return	array{0:list<string>,1:list<string>,2:array<string,bool>}	Entries that failed, entries skipped, files extracted by full path
	 * @throws CloudBackupException
	 */
	private static function extractZip($zipFile, $target)
	{
		$zip = new ZipArchive();
		if ($zip->open($zipFile) !== true) {
			throw new CloudBackupException('Cannot open '.dol_basename($zipFile));
		}
		$failed = array();
		$skipped = array();
		$seen = array();
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$entry = (string) $zip->getNameIndex($i);
			// Same guard as the core against entries escaping the target directory
			if (preg_match('/\.\./', $entry) || $entry === '' || $entry[0] === '/' || self::isExcluded($target.'/'.$entry)) {
				continue;
			}
			foreach (explode('/', rtrim($entry, '/')) as $segment) {
				if (!CloudBackupResticRepository::isAcceptedName($segment)) {
					$skipped[] = 'Skipped, name refused by Dolibarr: '.$entry;
					continue 2;
				}
			}
			if (substr($entry, -1) !== '/') {
				$seen[$target.'/'.$entry] = true;
			}
			if (!@$zip->extractTo($target.'/', array($entry))) {
				$failed[] = 'Cannot write '.$target.'/'.$entry;
			}
		}
		$zip->close();
		return array(array_values($failed), array_values($skipped), $seen);
	}

	/**
	 * Replay a dump into the database
	 *
	 * @param	string	$dumpFile	Dump
	 * @return	void
	 * @throws CloudBackupException
	 */
	private function restoreDatabase($dumpFile)
	{
		if (!CloudBackupSqlImport::isComplete($dumpFile)) {
			throw new CloudBackupException('The dump of this backup is incomplete: the database was not touched');
		}
		$this->addLog('Replay the database dump ('.self::formatSize(dol_filesize($dumpFile)).')');
		$import = new CloudBackupSqlImport($this->db);
		// The history of the backups is the one of now, not the one of the backup: it holds the
		// row of this restore, and the row of the backup itself frozen in "running"
		$import->keepTables = array($this->db->prefix().'cloudbackup_run');
		$count = $import->run($dumpFile);
		if ($count < 0) {
			throw new CloudBackupException('Database restore stopped: '.$import->error);
		}
		foreach ($import->collations as $from => $to) {
			$this->addLog('Collation '.$from.' unknown to this database server: tables created with '.$to);
		}
		$this->addLog('Database restored: '.$count.' statements');
	}

	// ---------------------------------------------------------------------------------------------
	// Download

	/**
	 * Identifier of a backup as shown: a restic id cut to 8 characters as restic does, an archive name
	 * whole (cut, the backups of the same minute look the same)
	 *
	 * @param	string	$id		Snapshot id or archive name
	 * @return	string
	 */
	public static function shortId($id)
	{
		return preg_match('/^[0-9a-f]{64}$/', $id) ? substr($id, 0, 8) : $id;
	}

	/**
	 * Files of a backup that download() gives
	 *
	 * @param	array{id:string,paths:string[]}	$backup		Backup, as listBackups() gives it
	 * @return	array<string,string>						File => label
	 */
	public static function downloadableFiles($backup)
	{
		$files = array();
		foreach ($backup['paths'] as $path) {
			if (self::format() === 'archive') {
				$files[$path] = $path;
			} elseif ($path === '/database') {
				$files['database'] = 'database.sql';
			} elseif ($path === '/documents' || $path === '/custom') {
				$files[ltrim($path, '/')] = ltrim($path, '/').'.tar';
			}
		}
		return $files;
	}

	/**
	 * Send a file of a backup to the browser, without a copy on the server: shared hosting gets its
	 * backups without an FTP access. A restic backup gives its dump as is, and its documents as a tar.
	 *
	 * @param	string	$id		Snapshot id or archive name
	 * @param	string	$file	A key of downloadableFiles()
	 * @return	int				>0 if sent, -1 if nothing was sent ($this->error set), -2 if broken midway
	 */
	public function download($id, $file)
	{
		global $langs;
		$langs->load('cloudbackup@cloudbackup');

		// Retention or a restore would delete what is being read
		if ($this->isRunning()) {
			$this->error = $langs->trans('CloudBackupErrorAlreadyRunning');
			return -1;
		}
		if ($this->startRun('download', 'manual') < 0) {
			return -1;
		}
		$this->sent = -1;
		$this->addLog('Download '.$file.' of '.$id);
		try {
			if (self::format() === 'restic') {
				$this->downloadRestic($id, $file);
			} else {
				$this->downloadArchive($id, $file);
			}
		} catch (Exception $e) {
			$this->error = $e->getMessage();
			$this->addLog('ERROR '.$this->error, LOG_ERR);
			$this->endRun(-1, implode("\n", $this->log), array('snapshot' => $id, 'bytes_total' => max(0, $this->sent)));
			return $this->sent < 0 ? -1 : -2;
		}
		$this->addLog('Sent '.self::formatSize($this->sent));
		$this->endRun(1, implode("\n", $this->log), array('snapshot' => $id, 'bytes_total' => $this->sent));
		return 1;
	}

	/**
	 * Download a file of an archive, joining its parts
	 *
	 * @param	string	$id		Archive name
	 * @param	string	$file	File in the archive
	 * @return	void
	 * @throws CloudBackupException
	 */
	private function downloadArchive($id, $file)
	{
		$storage = self::storage();
		if ($storage === null) {
			throw new CloudBackupException('No storage configured');
		}
		$archive = new CloudBackupArchive($storage);
		try {
			$manifest = $archive->loadManifest($id);
			if (!isset($manifest['files'][$file])) {
				throw new CloudBackupException('No '.$file.' in archive '.$id);
			}
			$this->sendHeaders($id.'-'.$file, (int) $manifest['files'][$file]['size']);
			$self = $this;
			$archive->stream($id, $file, function ($data) use ($self) {
				$self->send($data);
			});
		} finally {
			$storage->close();
		}
	}

	/**
	 * Download the dump of a snapshot, or one of its directories as a tar
	 *
	 * @param	string	$id		Snapshot id
	 * @param	string	$file	database, documents or custom
	 * @return	void
	 * @throws CloudBackupException
	 */
	private function downloadRestic($id, $file)
	{
		$repo = $this->openRepository();
		try {
			// A shared lock, as restic does: a prune elsewhere must not delete the packs being read
			$repo->lock(false);
			$snapshot = $repo->loadSnapshot($id);
			$short = substr($id, 0, 8);
			if ($file === 'database') {
				$node = self::databaseNode($repo, $snapshot['tree']);
				$this->sendHeaders($short.'-'.$node['name'], (int) $node['size']);
				foreach ((array) $node['content'] as $blobId) {
					$this->send($repo->loadBlob($blobId));
					$repo->refreshLock();
				}
			} elseif ($file === 'documents' || $file === 'custom') {
				$node = $repo->findNode($snapshot['tree'], $file);
				if ($node === null || $node['type'] !== 'dir') {
					throw new CloudBackupException('This snapshot has no '.$file);
				}
				$this->sendHeaders($short.'-'.$file.'.tar', -1);
				$this->send(self::tarHeader($file.'/', 0, CloudBackupResticRepository::parseTime($node['mtime']), '5'));
				$this->tarDirectory($repo, $node['subtree'], $file);
				// End of archive: two empty blocks
				$this->send(str_repeat("\0", 1024));
			} else {
				throw new CloudBackupException('No '.$file.' in a snapshot');
			}
			$repo->unlock();
		} finally {
			$repo->close();
			$this->repo = null;
		}
	}

	/**
	 * Send the content of a directory node as tar entries
	 *
	 * @param	CloudBackupResticRepository	$repo		Repository
	 * @param	string						$treeId		Tree id
	 * @param	string						$prefix		Path of the directory in the tar
	 * @return	void
	 * @throws CloudBackupException
	 */
	private function tarDirectory($repo, $treeId, $prefix)
	{
		foreach ($repo->loadTree($treeId) as $node) {
			$name = (string) $node['name'];
			if ($name === '' || $name === '.' || $name === '..' || strpos($name, '/') !== false || strpos($name, "\0") !== false) {
				continue;
			}
			$path = $prefix.'/'.$name;
			$mtime = CloudBackupResticRepository::parseTime($node['mtime']);
			if ($node['type'] === 'dir') {
				$this->send(self::tarHeader($path.'/', 0, $mtime, '5'));
				$this->tarDirectory($repo, $node['subtree'], $path);
			} elseif ($node['type'] === 'file') {
				$size = (int) $node['size'];
				$this->send(self::tarHeader($path, $size, $mtime, '0'));
				$written = 0;
				foreach ((array) $node['content'] as $blobId) {
					$data = $repo->loadBlob($blobId);
					$written += strlen($data);
					$this->send($data);
				}
				// The header announced the size: any other count shifts all the entries that follow
				if ($written !== $size) {
					throw new CloudBackupException($path.' has '.$written.' bytes instead of '.$size);
				}
				if ($size % 512) {
					$this->send(str_repeat("\0", 512 - $size % 512));
				}
				$repo->refreshLock();
			}
		}
	}

	/**
	 * Header of a tar entry, GNU flavour: a longer name than 99 bytes goes in a ././@LongLink entry
	 * before it, a size of 8 GB or more in base 256
	 *
	 * @param	string	$name	Path, ending with / for a directory
	 * @param	int		$size	Size of the content
	 * @param	int		$mtime	Modification time
	 * @param	string	$type	'0' file, '5' directory, 'L' long name
	 * @return	string
	 */
	public static function tarHeader($name, $size, $mtime, $type)
	{
		$out = '';
		if (strlen($name) > 99) {
			$out = self::tarHeader('././@LongLink', strlen($name) + 1, 0, 'L');
			$out .= str_pad($name."\0", (int) ceil((strlen($name) + 1) / 512) * 512, "\0");
			$name = substr($name, 0, 99);
		}
		$sizeField = $size < 8589934592 ? sprintf('%011o', $size)."\0" : "\x80\0\0\0".pack('J', $size);
		$header = str_pad($name, 100, "\0");
		$header .= sprintf('%07o', $type === '5' ? 0755 : 0644)."\0".sprintf('%07o', 0)."\0".sprintf('%07o', 0)."\0";
		$header .= $sizeField.sprintf('%011o', max(0, (int) $mtime))."\0";
		$header .= '        '.$type.str_repeat("\0", 100)."ustar  \0";
		$header .= str_repeat("\0", 32 + 32 + 8 + 8 + 155 + 12);
		$sum = 0;
		for ($i = 0; $i < 512; $i++) {
			$sum += ord($header[$i]);
		}
		return $out.substr_replace($header, sprintf('%06o', $sum)."\0 ", 148, 8);
	}

	/**
	 * Start the answer of a download
	 *
	 * @param	string	$fileName	Name proposed to the browser
	 * @param	int		$size		Size, -1 if unknown
	 * @return	void
	 */
	private function sendHeaders($fileName, $size)
	{
		if (function_exists('set_time_limit')) {
			@set_time_limit(0);
		}
		while (ob_get_level()) {
			ob_end_clean();
		}
		// Other pages of the user would wait for the session during the whole download
		if (session_status() === PHP_SESSION_ACTIVE) {
			session_write_close();
		}
		header('Content-Type: application/octet-stream');
		header('Content-Disposition: attachment; filename="'.dol_sanitizeFileName($fileName).'"');
		if ($size >= 0) {
			header('Content-Length: '.$size);
		}
		header('Cache-Control: no-store');
		header('X-Content-Type-Options: nosniff');
		$this->sent = 0;
	}

	/**
	 * Send data of a download
	 *
	 * @param	string	$data	Data
	 * @return	void
	 */
	public function send($data)
	{
		echo $data;
		flush();
		$this->sent += strlen($data);
		$this->markAlive();
	}

	// ---------------------------------------------------------------------------------------------
	// Maintenance

	/**
	 * Check the repository or the archives
	 *
	 * @param	bool	$readData	Read all the data (restic)
	 * @return	int					>0 if sound, <0 if problems ($this->errors set)
	 */
	public function check($readData = false)
	{
		$this->prepareLongRun();
		$this->errors = array();
		try {
			if (self::format() === 'restic') {
				$repo = $this->openRepository();
				try {
					$this->errors = $repo->check($readData);
					$this->measureUsage(function () use ($repo) {
						return self::resticBackups($repo);
					});
				} finally {
					$repo->close();
				}
			} else {
				$archive = new CloudBackupArchive(self::storage());
				$this->errors = $archive->check();
				$this->measureUsage(function () use ($archive) {
					return self::archiveBackups($archive);
				});
			}
		} catch (Exception $e) {
			$this->errors[] = $e->getMessage();
		}
		$this->error = implode(', ', $this->errors);
		return count($this->errors) ? -1 : 1;
	}

	/**
	 * Remove the locks of the repository (after a crash)
	 *
	 * @return int	Number of locks removed, <0 on error
	 */
	public function unlockRepository()
	{
		try {
			$repo = $this->openRepository();
			$count = $repo->breakLocks();
			$repo->close();
		} catch (Exception $e) {
			$this->error = $e->getMessage();
			return -1;
		}
		// A run killed by a time or memory limit stays "running": close it too
		$this->db->query("UPDATE ".$this->db->prefix()."cloudbackup_run SET status = -1, date_end = '".$this->db->idate(dol_now())."', message = CONCAT(COALESCE(message, ''), ' - interrupted') WHERE status = 0");
		return $count;
	}

	/**
	 * Version of the module
	 *
	 * @return string
	 */
	public static function moduleVersion()
	{
		require_once __DIR__.'/../core/modules/modCloudBackup.class.php';
		global $db;
		$module = new modCloudBackup($db);
		return $module->version;
	}
}
