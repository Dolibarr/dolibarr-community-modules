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
 * \file       cloudbackup/class/resticrepository.class.php
 * \ingroup    cloudbackup
 * \brief      Read and write a restic repository in pure PHP, on any CloudBackupStorage
 */

require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';
require_once __DIR__.'/resticcrypto.class.php';
require_once __DIR__.'/storage/cloudbackupstorage.class.php';


/**
 * Error raised by the repository code; the orchestrator turns it into a job error
 */
class CloudBackupException extends Exception
{
}


/**
 * A restic repository (format version 1, and version 2 when the zstd PHP extension is there for
 * compressed objects). What this class writes is readable by the restic command, and the reverse.
 *
 * Blobs are never compressed on write, so a repository written here stays readable without any
 * extension. Binary data goes through the byte functions (strlen, substr): the dol_ ones count characters.
 * See https://restic.readthedocs.io/en/stable/100_references.html#design
 */
class CloudBackupResticRepository
{
	const PACK_SIZE = 16777216;
	const CHUNK_SIZE = 1048576;
	const LOCK_STALE = 1800;
	const LOCK_REFRESH = 300;
	const INDEX_MAX_BLOBS = 50000;
	const PACK_CACHE_SIZE = 50331648;

	/** @var CloudBackupStorage */
	public $storage;

	/** @var array{encrypt:string,k:string,r:string}|null Master key */
	private $key = null;

	/** @var array<string,mixed> Decrypted config */
	public $config = array();

	/** @var string Password */
	private $password;

	/** @var array<string,array{0:string,1:int,2:int,3:string,4:int}> Blob id => (pack id, offset, length, type, uncompressed length) */
	private $blobs = array();

	/** @var bool */
	private $indexLoaded = false;

	/** @var string[] Names of the index files loaded */
	private $indexFiles = array();

	/** @var array<string,array{data:string,entries:list<array{0:string,1:string,2:int,3:int}>}> Pack being filled, per blob type: blob id, type, length, offset */
	private $packers = array();

	/** @var array<int,array{id:string,blobs:array<int,array{id:string,type:string,offset:int,length:int}>}> Packs written and not yet indexed */
	private $newPacks = array();

	/** @var string|null Lock file name held */
	private $lockName = null;

	/** @var bool */
	private $lockExclusive = false;

	/** @var int */
	private $lockTime = 0;

	/** @var array<string,string> Packs read in full, most recent last: one request serves all their blobs */
	private $packCache = array();

	/** @var int Bytes held by $packCache */
	private $packCacheSize = 0;

	/** @var array<string,int> Pack id => end of its last blob, from the index */
	private $packEnd = array();

	/** @var int Files seen, for the progress messages */
	private $ticks = 0;

	/** @var array<string,int> Counters of the last operation */
	public $stats = array();

	/** @var string Host name written in snapshots and locks */
	public $hostname = 'dolibarr';

	/** @var string User name written in snapshots and locks */
	public $username = 'dolibarr';

	/** @var string[] Non fatal problems of the last operation (names skipped...) */
	public $warnings = array();

	/** @var string[] Files a restore could not write */
	public $failures = array();

	/** @var array<string,bool> Files of the restored trees, by full path */
	public $seen = array();

	/** @var callable|null Called with a progress message */
	public $progress = null;

	/**
	 * Constructor
	 *
	 * @param	CloudBackupStorage	$storage	Storage
	 * @param	string				$password	Repository password
	 */
	public function __construct($storage, $password)
	{
		$this->storage = $storage;
		$this->password = $password;
		$this->resetStats();
	}

	/**
	 * Reset the counters
	 *
	 * @return void
	 */
	public function resetStats()
	{
		$this->stats = array('files' => 0, 'files_new' => 0, 'files_unmodified' => 0, 'dirs' => 0, 'bytes' => 0, 'bytes_added' => 0, 'blobs_added' => 0, 'packs_written' => 0, 'packs_deleted' => 0, 'snapshots_deleted' => 0);
	}

	/**
	 * Send a progress message
	 *
	 * @param	string	$message	Message
	 * @return	void
	 */
	private function progress($message)
	{
		if (is_callable($this->progress)) {
			call_user_func($this->progress, $message);
		}
	}

	/**
	 * Count a file, and report progress every 500 files (also a sign of life of the run)
	 *
	 * @return void
	 */
	private function tick()
	{
		if (++$this->ticks % 500 == 0) {
			$this->progress($this->ticks.' files');
		}
	}

	/**
	 * Tell whether a repository exists at the storage
	 *
	 * @return bool
	 * @throws CloudBackupException
	 */
	public function exists()
	{
		$keys = $this->storage->listFiles('keys');
		if ($keys === false) {
			throw new CloudBackupException($this->storage->error);
		}
		return count($keys) > 0;
	}

	// ---------------------------------------------------------------------------------------------
	// Repository creation and opening

	/**
	 * Create a new repository (format version 1)
	 *
	 * @return void
	 * @throws CloudBackupException
	 */
	public function init()
	{
		if ($this->exists() || $this->storage->get('config') !== false) {
			throw new CloudBackupException('A repository already exists at this location');
		}
		$this->key = CloudBackupResticCrypto::newRandomKey();
		$this->config = array(
			'version' => 1,
			'id' => bin2hex(random_bytes(32)),
			'chunker_polynomial' => self::randomPolynomial(),
		);
		$this->addKey($this->password);
		$config = CloudBackupResticCrypto::encrypt($this->key, json_encode($this->config));
		if (!$this->storage->put('config', $config)) {
			throw new CloudBackupException($this->storage->error);
		}
		$this->indexLoaded = true;
	}

	/**
	 * Add a key file for a password, protecting the master key of the opened repository
	 *
	 * @param	string	$password	Password
	 * @return	string				Key file id
	 * @throws CloudBackupException
	 */
	public function addKey($password)
	{
		// p = 1 keeps the derivation around 2 s in PHP; restic accepts any parameters it reads
		$salt = random_bytes(64);
		$N = 32768;
		$userKey = CloudBackupResticCrypto::kdf($password, $salt, $N, 8, 1);
		$master = json_encode(array(
			'mac' => array('k' => base64_encode($this->key['k']), 'r' => base64_encode($this->key['r'])),
			'encrypt' => base64_encode($this->key['encrypt']),
		));
		$keyfile = json_encode(array(
			'created' => self::formatTime(microtime(true)),
			'username' => $this->username,
			'hostname' => $this->hostname,
			'kdf' => 'scrypt',
			'N' => $N,
			'r' => 8,
			'p' => 1,
			'salt' => base64_encode($salt),
			'data' => base64_encode(CloudBackupResticCrypto::encrypt($userKey, $master)),
		));
		$id = hash('sha256', $keyfile);
		if (!$this->storage->put('keys/'.$id, $keyfile)) {
			throw new CloudBackupException($this->storage->error);
		}
		return $id;
	}

	/**
	 * Open the repository: find the key file matching the password, read the config
	 *
	 * @return void
	 * @throws CloudBackupException
	 */
	public function open()
	{
		$keys = $this->storage->listFiles('keys');
		if ($keys === false) {
			throw new CloudBackupException($this->storage->error);
		}
		if (!count($keys)) {
			throw new CloudBackupException('No restic repository at this location');
		}
		// Cheapest keys first: the module writes p = 1, restic calibrates p on a fast machine
		$candidates = array();
		foreach (array_keys($keys) as $name) {
			$raw = $this->storage->get($name);
			$keyfile = ($raw !== false) ? json_decode($raw, true) : null;
			if (!is_array($keyfile) || (isset($keyfile['kdf']) && $keyfile['kdf'] !== 'scrypt')) {
				continue;
			}
			$candidates[] = array((int) $keyfile['N'] * (int) $keyfile['r'] * (int) $keyfile['p'], $keyfile);
		}
		usort($candidates, function ($a, $b) {
			return $a[0] <=> $b[0];
		});
		foreach (array_slice($candidates, 0, 20) as $candidate) {
			$keyfile = $candidate[1];
			$userKey = CloudBackupResticCrypto::kdf($this->password, (string) base64_decode($keyfile['salt']), (int) $keyfile['N'], (int) $keyfile['r'], (int) $keyfile['p']);
			$plain = CloudBackupResticCrypto::decrypt($userKey, (string) base64_decode($keyfile['data']));
			if ($plain === false) {
				continue;
			}
			$master = json_decode($plain, true);
			if (!is_array($master) || !isset($master['encrypt'], $master['mac']['k'], $master['mac']['r'])) {
				continue;
			}
			$this->key = array(
				'encrypt' => (string) base64_decode($master['encrypt']),
				'k' => (string) base64_decode($master['mac']['k']),
				'r' => (string) base64_decode($master['mac']['r']),
			);
			break;
		}
		if ($this->key === null) {
			throw new CloudBackupException('Wrong password: no key of the repository opens with it');
		}
		$raw = $this->storage->get('config');
		if ($raw === false) {
			throw new CloudBackupException('The repository has no config file');
		}
		$plain = CloudBackupResticCrypto::decrypt($this->key, $raw);
		if ($plain === false) {
			throw new CloudBackupException('The config file of the repository is damaged');
		}
		$this->config = json_decode($this->decompressUnpacked($plain, 1), true);
		if (!is_array($this->config) || !in_array((int) $this->config['version'], array(1, 2))) {
			throw new CloudBackupException('Unsupported repository version');
		}
	}

	/**
	 * Repository format version
	 *
	 * @return int
	 */
	public function version()
	{
		return (int) $this->config['version'];
	}

	// ---------------------------------------------------------------------------------------------
	// Unpacked files: config, index, snapshots, locks

	/**
	 * Undo the compression of a JSON file of a version 2 repository
	 *
	 * @param	string	$plain		Decrypted content
	 * @param	int		$version	Repository version
	 * @return	string
	 * @throws CloudBackupException
	 */
	private function decompressUnpacked($plain, $version)
	{
		if ($version < 2 || $plain === '' || $plain[0] === '{' || $plain[0] === '[') {
			return $plain;
		}
		if ($plain[0] === "\x02") {
			return self::zstdDecompress(substr($plain, 1));
		}
		throw new CloudBackupException('Unknown encoding of a repository file');
	}

	/**
	 * Decompress zstd data, if the PHP extension is available
	 *
	 * @param	string	$data	Compressed data
	 * @return	string
	 * @throws CloudBackupException
	 */
	private static function zstdDecompress($data)
	{
		if (!function_exists('zstd_uncompress')) {
			throw new CloudBackupException('This repository holds compressed data (restic format 2): the PHP extension zstd is needed to read it');
		}
		$out = zstd_uncompress($data);
		if ($out === false) {
			throw new CloudBackupException('Failed to decompress repository data');
		}
		return $out;
	}

	/**
	 * Encrypt and store a JSON file under its hash
	 *
	 * @param	string				$type	Directory: index, snapshots, locks
	 * @param	array<mixed>|object	$data	Content
	 * @return	string						Id
	 * @throws CloudBackupException
	 */
	private function saveJson($type, $data)
	{
		$raw = CloudBackupResticCrypto::encrypt($this->key, json_encode($data, JSON_UNESCAPED_SLASHES));
		$id = hash('sha256', $raw);
		if (!$this->storage->put($type.'/'.$id, $raw)) {
			throw new CloudBackupException($this->storage->error);
		}
		return $id;
	}

	/**
	 * Load an encrypted JSON file
	 *
	 * @param	string	$name	Object name (type/id)
	 * @return	array<mixed>|null	Decoded content, null if missing
	 * @throws CloudBackupException
	 */
	private function loadJson($name)
	{
		$raw = $this->storage->get($name);
		if ($raw === false) {
			return null;
		}
		if (hash('sha256', $raw) !== dol_basename($name)) {
			throw new CloudBackupException('The file '.$name.' is damaged (hash mismatch)');
		}
		$plain = CloudBackupResticCrypto::decrypt($this->key, $raw);
		if ($plain === false) {
			throw new CloudBackupException('The file '.$name.' is damaged (MAC mismatch)');
		}
		return json_decode($this->decompressUnpacked($plain, $this->version()), true);
	}

	// ---------------------------------------------------------------------------------------------
	// Index

	/**
	 * Load all the index files of the repository
	 *
	 * @param	bool	$force	Reload even if loaded
	 * @return	void
	 * @throws CloudBackupException
	 */
	public function loadIndex($force = false)
	{
		if ($this->indexLoaded && !$force) {
			return;
		}
		$this->blobs = array();
		$this->indexFiles = array();
		$this->packEnd = array();
		$files = $this->storage->listFiles('index');
		if ($files === false) {
			throw new CloudBackupException($this->storage->error);
		}
		foreach (array_keys($files) as $name) {
			$index = $this->loadJson($name);
			if (!is_array($index) || !isset($index['packs'])) {
				continue;
			}
			$this->indexFiles[] = $name;
			foreach ($index['packs'] as $pack) {
				foreach ($pack['blobs'] as $blob) {
					$this->blobs[$blob['id']] = array($pack['id'], (int) $blob['offset'], (int) $blob['length'], $blob['type'], isset($blob['uncompressed_length']) ? (int) $blob['uncompressed_length'] : 0);
					$end = (int) $blob['offset'] + (int) $blob['length'];
					if (!isset($this->packEnd[$pack['id']]) || $end > $this->packEnd[$pack['id']]) {
						$this->packEnd[$pack['id']] = $end;
					}
				}
			}
		}
		$this->indexLoaded = true;
	}

	/**
	 * Tell whether a blob is known, in the repository or in a pack being written
	 *
	 * @param	string	$id		Blob id
	 * @return	bool
	 */
	public function hasBlob($id)
	{
		return isset($this->blobs[$id]);
	}

	/**
	 * Write an index file for the packs written since the last call
	 *
	 * @return void
	 * @throws CloudBackupException
	 */
	private function writeNewIndex()
	{
		$packs = array();
		$count = 0;
		foreach ($this->newPacks as $pack) {
			$packs[] = $pack;
			$count += count($pack['blobs']);
			if ($count >= self::INDEX_MAX_BLOBS) {
				$this->indexFiles[] = 'index/'.$this->saveJson('index', array('packs' => $packs));
				$packs = array();
				$count = 0;
			}
		}
		if (count($packs)) {
			$this->indexFiles[] = 'index/'.$this->saveJson('index', array('packs' => $packs));
		}
		$this->newPacks = array();
	}

	// ---------------------------------------------------------------------------------------------
	// Packs and blobs

	/**
	 * Store a blob, unless the repository already has it
	 *
	 * @param	string	$type		'data' or 'tree'
	 * @param	string	$plaintext	Content
	 * @param	bool	$force		Store even if known (repacking)
	 * @return	string				Blob id
	 * @throws CloudBackupException
	 */
	public function saveBlob($type, $plaintext, $force = false)
	{
		$id = hash('sha256', $plaintext);
		if (!$force && isset($this->blobs[$id])) {
			return $id;
		}
		if (!isset($this->packers[$type])) {
			$this->packers[$type] = array('data' => '', 'entries' => array());
		}
		$ciphertext = CloudBackupResticCrypto::encrypt($this->key, $plaintext);
		$offset = strlen($this->packers[$type]['data']);
		$this->packers[$type]['data'] .= $ciphertext;
		$this->packers[$type]['entries'][] = array($id, $type, strlen($ciphertext), $offset);
		// Known from now on, so a second occurrence in the same run is not stored twice
		$this->blobs[$id] = array('', $offset, strlen($ciphertext), $type, 0);
		$this->stats['blobs_added']++;
		$this->stats['bytes_added'] += strlen($plaintext);
		if (strlen($this->packers[$type]['data']) >= self::PACK_SIZE) {
			$this->flushPack($type);
		}
		$this->refreshLock();
		return $id;
	}

	/**
	 * Write the pack being filled for a blob type
	 *
	 * @param	string	$type	Blob type
	 * @return	void
	 * @throws CloudBackupException
	 */
	private function flushPack($type)
	{
		if (empty($this->packers[$type]['entries'])) {
			return;
		}
		$header = '';
		foreach ($this->packers[$type]['entries'] as $entry) {
			$header .= chr($entry[1] === 'tree' ? 1 : 0).pack('V', $entry[2]).hex2bin($entry[0]);
		}
		$header = CloudBackupResticCrypto::encrypt($this->key, $header);
		$data = $this->packers[$type]['data'].$header.pack('V', strlen($header));
		$packId = hash('sha256', $data);
		$this->progress('Upload pack '.substr($packId, 0, 8).' ('.strlen($data).' bytes)');
		if (!$this->storage->put('data/'.substr($packId, 0, 2).'/'.$packId, $data)) {
			throw new CloudBackupException($this->storage->error);
		}
		$blobs = array();
		foreach ($this->packers[$type]['entries'] as $entry) {
			$blobs[] = array('id' => $entry[0], 'type' => $entry[1], 'offset' => $entry[3], 'length' => $entry[2]);
			$this->blobs[$entry[0]] = array($packId, $entry[3], $entry[2], $entry[1], 0);
		}
		$this->packEnd[$packId] = strlen($this->packers[$type]['data']);
		$this->newPacks[] = array('id' => $packId, 'blobs' => $blobs);
		$this->packers[$type] = array('data' => '', 'entries' => array());
		$this->stats['packs_written']++;
	}

	/**
	 * Write the pending packs and their index
	 *
	 * @return void
	 * @throws CloudBackupException
	 */
	public function flush()
	{
		foreach (array_keys($this->packers) as $type) {
			$this->flushPack($type);
		}
		$this->writeNewIndex();
	}

	/**
	 * Read and decrypt a blob
	 *
	 * @param	string	$id		Blob id
	 * @return	string
	 * @throws CloudBackupException
	 */
	public function loadBlob($id)
	{
		$this->loadIndex();
		if (!isset($this->blobs[$id]) || $this->blobs[$id][0] === '') {
			throw new CloudBackupException('Blob '.$id.' is not in the index');
		}
		list($packId, $offset, $length, , $uncompressed) = $this->blobs[$id];
		$name = 'data/'.substr($packId, 0, 2).'/'.$packId;
		$packEnd = isset($this->packEnd[$packId]) ? $this->packEnd[$packId] : 0;
		if (isset($this->packCache[$packId]) || ($packEnd > 0 && $packEnd <= self::PACK_SIZE + 4194304)) {
			// Blobs are read in the order they were written: fetching the whole pack once replaces a
			// request per blob, and each request costs a connection (TLS, DNS check of Dolibarr)
			if (!isset($this->packCache[$packId])) {
				$data = $this->storage->get($name);
				if ($data === false) {
					throw new CloudBackupException('Failed to read pack '.$packId.': '.$this->storage->error);
				}
				while ($this->packCacheSize + strlen($data) > self::PACK_CACHE_SIZE && count($this->packCache)) {
					$this->packCacheSize -= strlen(reset($this->packCache));
					unset($this->packCache[key($this->packCache)]);
				}
				$this->packCache[$packId] = $data;
				$this->packCacheSize += strlen($data);
			} else {
				// Most recently used goes last
				$data = $this->packCache[$packId];
				unset($this->packCache[$packId]);
				$this->packCache[$packId] = $data;
			}
			$raw = (string) substr($this->packCache[$packId], $offset, $length);
		} else {
			$raw = $this->storage->getRange($name, $offset, $length);
			if ($raw === false) {
				throw new CloudBackupException('Failed to read blob '.$id.': '.$this->storage->error);
			}
		}
		$plain = CloudBackupResticCrypto::decrypt($this->key, $raw);
		if ($plain === false) {
			throw new CloudBackupException('Blob '.$id.' is damaged (MAC mismatch)');
		}
		if ($uncompressed > 0) {
			$plain = self::zstdDecompress($plain);
		}
		if (hash('sha256', $plain) !== $id) {
			throw new CloudBackupException('Blob '.$id.' is damaged (hash mismatch)');
		}
		return $plain;
	}

	/**
	 * Read the header of a pack: the list of its blobs
	 *
	 * @param	string	$packId		Pack id
	 * @param	int		$size		Pack size
	 * @return	array<int,array{id:string,type:string,offset:int,length:int}>
	 * @throws CloudBackupException
	 */
	public function readPackHeader($packId, $size)
	{
		$name = 'data/'.substr($packId, 0, 2).'/'.$packId;
		$tail = $this->storage->getRange($name, $size - 4, 4);
		if ($tail === false) {
			throw new CloudBackupException($this->storage->error);
		}
		$headerLength = unpack('V', $tail)[1];
		$raw = $this->storage->getRange($name, $size - 4 - $headerLength, $headerLength);
		$header = ($raw !== false) ? CloudBackupResticCrypto::decrypt($this->key, $raw) : false;
		if ($header === false) {
			throw new CloudBackupException('Header of pack '.$packId.' is damaged');
		}
		$blobs = array();
		$offset = 0;
		$pos = 0;
		while ($pos < strlen($header)) {
			$type = ord($header[$pos]);
			$length = unpack('V', substr($header, $pos + 1, 4))[1];
			$entrySize = ($type >= 2) ? 41 : 37;
			$blobs[] = array('id' => bin2hex(substr($header, $pos + $entrySize - 32, 32)), 'type' => ($type % 2) ? 'tree' : 'data', 'offset' => $offset, 'length' => $length);
			$offset += $length;
			$pos += $entrySize;
		}
		return $blobs;
	}

	// ---------------------------------------------------------------------------------------------
	// Locks

	/**
	 * Take a lock on the repository
	 *
	 * @param	bool	$exclusive	True for an exclusive lock (prune), false for a shared one (backup)
	 * @return	void
	 * @throws CloudBackupException
	 */
	public function lock($exclusive)
	{
		foreach ($this->listLocks() as $lock) {
			if ($exclusive || !empty($lock['exclusive'])) {
				throw new CloudBackupException('The repository is locked by '.$lock['hostname'].' since '.$lock['time'].($exclusive ? '' : ' (exclusive)'));
			}
		}
		$this->lockExclusive = $exclusive;
		$this->writeLock();
	}

	/**
	 * Write the lock file, replacing the previous one
	 *
	 * @return void
	 * @throws CloudBackupException
	 */
	private function writeLock()
	{
		$previous = $this->lockName;
		$this->lockName = 'locks/'.$this->saveJson('locks', array(
			'time' => self::formatTime(microtime(true)),
			'exclusive' => $this->lockExclusive,
			'hostname' => $this->hostname,
			'username' => $this->username,
			'pid' => getmypid(),
		));
		$this->lockTime = dol_now();
		if ($previous !== null) {
			$this->storage->delete($previous);
		}
	}

	/**
	 * Refresh the lock if it gets old, as restic does, so no one takes it for stale
	 *
	 * @return void
	 * @throws CloudBackupException
	 */
	public function refreshLock()
	{
		if ($this->lockName !== null && dol_now() - $this->lockTime > self::LOCK_REFRESH) {
			$this->writeLock();
		}
	}

	/**
	 * Release the lock
	 *
	 * @return void
	 */
	public function unlock()
	{
		if ($this->lockName !== null) {
			$this->storage->delete($this->lockName);
			$this->lockName = null;
		}
	}

	/**
	 * Locks held by others and not stale
	 *
	 * @return list<array<mixed>>
	 * @throws CloudBackupException
	 */
	public function listLocks()
	{
		$files = $this->storage->listFiles('locks');
		if ($files === false) {
			throw new CloudBackupException($this->storage->error);
		}
		$locks = array();
		foreach (array_keys($files) as $name) {
			if ($name === $this->lockName) {
				continue;
			}
			try {
				$lock = $this->loadJson($name);
			} catch (CloudBackupException $e) {
				continue;
			}
			if (!is_array($lock) || !isset($lock['time']) || dol_now() - self::parseTime($lock['time']) > self::LOCK_STALE) {
				continue;
			}
			$locks[] = $lock;
		}
		return array_values($locks);
	}

	/**
	 * Remove all locks, stale or not. For an operator who knows that no other process runs.
	 *
	 * @return int	Number of locks removed
	 * @throws CloudBackupException
	 */
	public function breakLocks()
	{
		$files = $this->storage->listFiles('locks');
		if ($files === false) {
			throw new CloudBackupException($this->storage->error);
		}
		foreach (array_keys($files) as $name) {
			$this->storage->delete($name);
		}
		return count($files);
	}

	// ---------------------------------------------------------------------------------------------
	// Snapshots and trees

	/**
	 * List the snapshots, oldest first
	 *
	 * @return array<string,non-empty-array<mixed>>	Snapshot id => snapshot
	 * @throws CloudBackupException
	 */
	public function listSnapshots()
	{
		$files = $this->storage->listFiles('snapshots');
		if ($files === false) {
			throw new CloudBackupException($this->storage->error);
		}
		$snapshots = array();
		foreach (array_keys($files) as $name) {
			$snapshot = $this->loadJson($name);
			if (is_array($snapshot) && isset($snapshot['tree'])) {
				$snapshots[dol_basename($name)] = $snapshot;
			}
		}
		if (!count($snapshots)) {
			return array();
		}
		uasort($snapshots, function ($a, $b) {
			return self::parseTime($a['time']) <=> self::parseTime($b['time']);
		});
		return $snapshots;
	}

	/**
	 * Load a snapshot
	 *
	 * @param	string	$id		Snapshot id, or a unique prefix of it
	 * @return	array<string,mixed>
	 * @throws CloudBackupException
	 */
	public function loadSnapshot($id)
	{
		if (!preg_match('/^[0-9a-f]{64}$/', $id)) {
			$found = array();
			foreach (array_keys($this->listSnapshots()) as $snapshotId) {
				if ($id !== '' && strpos($snapshotId, $id) === 0) {
					$found[] = $snapshotId;
				}
			}
			if (count($found) != 1) {
				throw new CloudBackupException('No single snapshot matches '.$id);
			}
			$id = $found[0];
		}
		$snapshot = $this->loadJson('snapshots/'.$id);
		if (!is_array($snapshot)) {
			throw new CloudBackupException('Snapshot '.$id.' not found');
		}
		return $snapshot;
	}

	/**
	 * Save a snapshot
	 *
	 * @param	array<string,mixed>	$snapshot	Snapshot
	 * @return	string						Snapshot id
	 * @throws CloudBackupException
	 */
	public function saveSnapshot($snapshot)
	{
		return $this->saveJson('snapshots', $snapshot);
	}

	/**
	 * Load a tree blob
	 *
	 * @param	string	$id		Tree id
	 * @return	array<int,array<string,mixed>>	Nodes
	 * @throws CloudBackupException
	 */
	public function loadTree($id)
	{
		$tree = json_decode($this->loadBlob($id), true);
		if (!is_array($tree) || !array_key_exists('nodes', $tree)) {
			throw new CloudBackupException('Tree '.$id.' is damaged');
		}
		return is_array($tree['nodes']) ? $tree['nodes'] : array();
	}

	/**
	 * Save a tree blob, nodes sorted by name as restic expects
	 *
	 * @param	array<int,array<string,mixed>>	$nodes	Nodes
	 * @return	string							Tree id
	 * @throws CloudBackupException
	 */
	public function saveTree($nodes)
	{
		usort($nodes, function ($a, $b) {
			return strcmp($a['name'], $b['name']);
		});
		$json = json_encode(array('nodes' => $nodes), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if ($json === false) {
			throw new CloudBackupException('Cannot encode a tree: '.json_last_error_msg());
		}
		return $this->saveBlob('tree', $json."\n");
	}

	/**
	 * Node of a directory
	 *
	 * @param	string	$name		Name
	 * @param	string	$subtree	Tree id of its content
	 * @param	int		$mtime		Modification time
	 * @return	array{name:string,type:string,mode:int,mtime:string,atime:string,ctime:string,uid:int,gid:int,content:null,subtree:string}
	 */
	public static function dirNode($name, $subtree, $mtime)
	{
		$time = self::formatTime($mtime);
		// Go os.FileMode: ModeDir (1 << 31) | 0755
		return array('name' => $name, 'type' => 'dir', 'mode' => 2147484141, 'mtime' => $time, 'atime' => $time, 'ctime' => $time, 'uid' => 0, 'gid' => 0, 'content' => null, 'subtree' => $subtree);
	}

	/**
	 * Node of a file
	 *
	 * @param	string		$name		Name
	 * @param	string[]	$content	Data blob ids
	 * @param	int			$size		Size
	 * @param	int			$mtime		Modification time
	 * @param	int			$inode		Inode
	 * @return	array{name:string,type:string,mode:int,mtime:string,atime:string,ctime:string,uid:int,gid:int,inode:int,links:int,size:int,content:list<string>}
	 */
	public static function fileNode($name, $content, $size, $mtime, $inode = 0)
	{
		$time = self::formatTime($mtime);
		return array('name' => $name, 'type' => 'file', 'mode' => 420, 'mtime' => $time, 'atime' => $time, 'ctime' => $time, 'uid' => 0, 'gid' => 0, 'inode' => $inode, 'links' => 1, 'size' => $size, 'content' => array_values($content));
	}

	// ---------------------------------------------------------------------------------------------
	// Backup of local files

	/**
	 * Store the content of a file as data blobs
	 *
	 * @param	string	$path		Local path
	 * @param	bool	$byLines	True to cut on line boundaries chosen by content (SQL dumps: a
	 *								changed row only changes the chunk around it), false for fixed chunks
	 * @return	array{0:string[],1:int}	Blob ids, size read
	 * @throws CloudBackupException
	 */
	public function saveFileContent($path, $byLines = false)
	{
		$handle = @fopen($path, 'rb');
		if (!$handle) {
			throw new CloudBackupException('Cannot read '.$path);
		}
		$ids = array();
		$size = 0;
		if ($byLines) {
			// About 256 KiB per chunk: a dump changes in scattered rows, smaller chunks dedup better
			$min = self::CHUNK_SIZE / 8;
			$max = 4 * self::CHUNK_SIZE;
			$chunk = '';
			while (($line = fgets($handle)) !== false) {
				$chunk .= $line;
				$len = strlen($chunk);
				if (($len >= $min && (crc32($line) & 0x1ff) == 0) || $len >= $max) {
					$ids[] = $this->saveBlob('data', $chunk);
					$size += $len;
					$chunk = '';
				}
			}
			if ($chunk !== '') {
				$ids[] = $this->saveBlob('data', $chunk);
				$size += strlen($chunk);
			}
		} else {
			while (!feof($handle)) {
				$chunk = fread($handle, self::CHUNK_SIZE);
				if ($chunk === false || $chunk === '') {
					break;
				}
				// fread on some wrappers returns less than asked: complete the chunk for stable ids
				while (strlen($chunk) < self::CHUNK_SIZE && !feof($handle)) {
					$more = fread($handle, self::CHUNK_SIZE - strlen($chunk));
					if ($more === false || $more === '') {
						break;
					}
					$chunk .= $more;
				}
				$ids[] = $this->saveBlob('data', $chunk);
				$size += strlen($chunk);
			}
		}
		fclose($handle);
		return array($ids, $size);
	}

	/**
	 * Store a local directory as a tree, reusing the content of files unchanged since the parent tree
	 *
	 * @param	string			$dir		Local directory
	 * @param	string|null		$parentTree	Tree id of the same directory in the previous snapshot
	 * @param	callable|null	$exclude	Called with the full path, returns true to skip it
	 * @return	string						Tree id
	 * @throws CloudBackupException
	 */
	public function saveDirectory($dir, $parentTree = null, $exclude = null)
	{
		$parentNodes = array();
		if ($parentTree !== null) {
			try {
				foreach ($this->loadTree($parentTree) as $node) {
					$parentNodes[$node['name']] = $node;
				}
			} catch (CloudBackupException $e) {
				$parentNodes = array();
			}
		}
		$entries = @scandir($dir);
		if ($entries === false) {
			throw new CloudBackupException('Cannot read directory '.$dir);
		}
		$nodes = array();
		foreach ($entries as $entry) {
			if ($entry === '.' || $entry === '..') {
				continue;
			}
			$path = $dir.'/'.$entry;
			if (is_link($path) || ($exclude !== null && call_user_func($exclude, $path))) {
				continue;
			}
			if (!self::isAcceptedName($entry)) {
				// Dolibarr would rename it (dol_sanitizePathName): such a file is not kept by Dolibarr either
				$this->warnings[] = 'Skipped, name refused by Dolibarr: '.$path;
				continue;
			}
			if (!preg_match('//u', $entry)) {
				// A tree is JSON: a name that is not UTF-8 cannot be stored faithfully
				$this->warnings[] = 'Skipped, name is not UTF-8: '.$dir.'/'.bin2hex($entry);
				continue;
			}
			$stat = @stat($path);
			if ($stat === false) {
				continue;
			}
			$parent = isset($parentNodes[$entry]) ? $parentNodes[$entry] : null;
			if (dol_is_dir($path)) {
				$subtree = $this->saveDirectory($path, ($parent !== null && $parent['type'] === 'dir') ? $parent['subtree'] : null, $exclude);
				$nodes[] = self::dirNode($entry, $subtree, $stat['mtime']);
				$this->stats['dirs']++;
			} elseif (dol_is_file($path)) {
				$nodes[] = $this->fileNodeFor($entry, $path, $stat, $parent);
			}
		}
		return $this->saveTree($nodes);
	}

	/**
	 * Node of a local file, its content reused from the parent node when size and time did not change
	 *
	 * @param	string						$name		Name in the tree
	 * @param	string						$path		Local path
	 * @param	array<int|string,int>		$stat		stat() of the file
	 * @param	array<string,mixed>|null	$parent		Node of the same file in the previous snapshot
	 * @param	bool						$byLines	See saveFileContent()
	 * @return	array{name:string,type:string,mode:int,mtime:string,atime:string,ctime:string,uid:int,gid:int,inode:int,links:int,size:int,content:list<string>}
	 * @throws CloudBackupException
	 */
	public function fileNodeFor($name, $path, $stat, $parent = null, $byLines = false)
	{
		$this->stats['files']++;
		$this->tick();
		$this->stats['bytes'] += $stat['size'];
		if ($parent !== null && $parent['type'] === 'file' && (int) $parent['size'] === (int) $stat['size']
			&& self::parseTime($parent['mtime']) === (int) $stat['mtime'] && $this->allBlobsKnown($parent['content'])) {
			$this->stats['files_unmodified']++;
			return self::fileNode($name, $parent['content'], (int) $stat['size'], (int) $stat['mtime'], (int) $stat['ino']);
		}
		list($ids, $size) = $this->saveFileContent($path, $byLines);
		$this->stats['files_new']++;
		return self::fileNode($name, $ids, $size, (int) $stat['mtime'], (int) $stat['ino']);
	}

	/**
	 * Tell whether all blobs of a list are in the repository
	 *
	 * @param	string[]|null	$ids	Blob ids
	 * @return	bool
	 */
	private function allBlobsKnown($ids)
	{
		if (!is_array($ids)) {
			return false;
		}
		foreach ($ids as $id) {
			if (!isset($this->blobs[$id])) {
				return false;
			}
		}
		return true;
	}

	// ---------------------------------------------------------------------------------------------
	// Restore

	/**
	 * Find a node by its path in a snapshot
	 *
	 * @param	string	$treeId		Root tree id
	 * @param	string	$path		Path like 'documents/facture' ; '' for the root
	 * @return	array<string,mixed>|null	Node, or a pseudo directory node for the root
	 * @throws CloudBackupException
	 */
	public function findNode($treeId, $path)
	{
		$node = array('name' => '', 'type' => 'dir', 'subtree' => $treeId);
		foreach (array_filter(explode('/', trim($path, '/')), 'strlen') as $segment) {
			if ($node['type'] !== 'dir') {
				return null;
			}
			$next = null;
			foreach ($this->loadTree($node['subtree']) as $child) {
				if ($child['name'] === $segment) {
					$next = $child;
					break;
				}
			}
			if ($next === null) {
				return null;
			}
			$node = $next;
		}
		return $node;
	}

	/**
	 * Write the content of a file node to a local file
	 *
	 * @param	array<string,mixed>	$node	File node
	 * @param	string				$target	Local path
	 * @return	void
	 * @throws CloudBackupException
	 */
	public function restoreFile($node, $target)
	{
		$tmp = $target.'.cloudbackup-tmp';
		$handle = @fopen($tmp, 'wb');
		if (!$handle) {
			throw new CloudBackupException('Cannot write '.$target);
		}
		foreach ((array) $node['content'] as $blobId) {
			if (fwrite($handle, $this->loadBlob($blobId)) === false) {
				fclose($handle);
				dol_delete_file($tmp, 1, 1, 1, null, false, 0);
				throw new CloudBackupException('Cannot write '.$target);
			}
		}
		fclose($handle);
		// dol_move() refuses a PDF holding JavaScript (dolCheckOnFileName): such files do exist in real
		// documents (seen on a production instance), and a restore must give back what was saved
		if (!dol_move($tmp, $target, '0', 1, 0, 0) && !@rename($tmp, $target)) {
			dol_delete_file($tmp, 1, 1, 1, null, false, 0);
			throw new CloudBackupException('Cannot write '.$target);
		}
		@touch($target, self::parseTime($node['mtime']));
	}

	/**
	 * Restore a directory node into a local directory. Existing files with the same size and time are kept.
	 *
	 * @param	string			$treeId		Tree id
	 * @param	string			$target		Local directory
	 * @param	callable|null	$exclude	Called with the local path, returns true to skip it
	 * @param	string			$root		Existing parent of the target, where dol_mkdir() starts
	 * @return	void
	 * @throws CloudBackupException
	 */
	public function restoreDirectory($treeId, $target, $exclude = null, $root = '')
	{
		if (!dol_is_dir($target) && dol_mkdir($target, $root) < 0) {
			$this->failures[] = 'Cannot create directory '.$target;
			return;
		}
		foreach ($this->loadTree($treeId) as $node) {
			$name = $node['name'];
			if ($name === '' || $name === '.' || $name === '..' || strpos($name, '/') !== false || strpos($name, "\0") !== false) {
				continue;
			}
			if (!self::isAcceptedName($name)) {
				$this->warnings[] = 'Skipped, name refused by Dolibarr: '.$target.'/'.$name;
				continue;
			}
			$path = $target.'/'.$name;
			if ($exclude !== null && call_user_func($exclude, $path)) {
				continue;
			}
			if ($node['type'] === 'dir') {
				$this->restoreDirectory($node['subtree'], $path, $exclude, $root);
				$this->stats['dirs']++;
			} elseif ($node['type'] === 'file') {
				$this->stats['files']++;
				$this->seen[$path] = true;
				$this->tick();
				$this->stats['bytes'] += (int) $node['size'];
				if (dol_is_file($path) && dol_filesize($path) == $node['size'] && dol_filemtime($path) == self::parseTime($node['mtime'])) {
					$this->stats['files_unmodified']++;
					continue;
				}
				try {
					$this->restoreFile($node, $path);
					$this->stats['files_new']++;
				} catch (CloudBackupException $e) {
					// A file that cannot be written (owner, quota) must not stop the others
					$this->failures[] = $e->getMessage();
				}
			}
		}
	}

	// ---------------------------------------------------------------------------------------------
	// Retention

	/**
	 * Delete the snapshots that a retention policy does not keep, like restic forget
	 *
	 * @param	array<string,int>	$policy		Keys last, daily, weekly, monthly, yearly: how many to keep
	 * @param	callable|null		$filter		Called with a snapshot, true if the policy applies to it
	 * @return	array{keep:list<string>,remove:list<string>}	Snapshot ids
	 * @throws CloudBackupException
	 */
	public function forget($policy, $filter = null)
	{
		$snapshots = array();
		foreach ($this->listSnapshots() as $id => $snapshot) {
			if ($filter === null || call_user_func($filter, $snapshot)) {
				$snapshots[$id] = $snapshot;
			}
		}
		$keep = self::applyPolicy($snapshots, $policy);
		$remove = array_values(array_diff(array_keys($snapshots), $keep));
		foreach ($remove as $id) {
			if (!$this->storage->delete('snapshots/'.$id)) {
				throw new CloudBackupException($this->storage->error);
			}
			$this->stats['snapshots_deleted']++;
		}
		return array('keep' => $keep, 'remove' => $remove);
	}

	/**
	 * Snapshots kept by a policy: for each rule, the newest snapshot of each of the N most recent
	 * periods; a policy that keeps nothing keeps everything (as restic refuses to forget all).
	 *
	 * @param	array<string,array<string,mixed>>	$snapshots	Snapshots, any order
	 * @param	array<string,int>					$policy		Rules
	 * @return	list<string>							Ids kept
	 */
	public static function applyPolicy($snapshots, $policy)
	{
		$rules = array('last' => 'last', 'hourly' => '%Y%m%d%H', 'daily' => '%Y%m%d', 'weekly' => 'week', 'monthly' => '%Y%m', 'yearly' => '%Y');
		$active = false;
		foreach (array_keys($rules) as $rule) {
			if (!empty($policy[$rule])) {
				$active = true;
			}
		}
		if (!$active) {
			return array_keys($snapshots);
		}
		uasort($snapshots, function ($a, $b) {
			return self::parseTime($b['time']) <=> self::parseTime($a['time']);
		});
		$keep = array();
		foreach ($rules as $rule => $format) {
			$count = empty($policy[$rule]) ? 0 : (int) $policy[$rule];
			$last = null;
			foreach ($snapshots as $id => $snapshot) {
				if ($count <= 0) {
					break;
				}
				// Periods are cut in the time zone written in the snapshot, as restic does
				$bucket = ($format === 'last') ? $id : self::period($format, self::parseTime($snapshot['time']) + self::parseOffset($snapshot['time']));
				if ($bucket !== $last) {
					$keep[$id] = true;
					$last = $bucket;
					$count--;
				}
			}
			// restic 0.17+: a rule with periods left also keeps the oldest snapshot
			if ($count > 0 && $count < (int) $policy[$rule] && $format !== 'last') {
				end($snapshots);
				$keep[key($snapshots)] = true;
			}
		}
		return array_values(array_map('strval', array_keys($keep)));
	}

	/**
	 * Remove the data no snapshot uses any more, like restic prune: packs fully unused are deleted,
	 * packs partly unused are rewritten, the index is rewritten. Needs an exclusive lock.
	 *
	 * @param	float	$maxUnused	Rewrite a partly used pack only when its unused share is above this
	 * @return	void
	 * @throws CloudBackupException
	 */
	public function prune($maxUnused = 0.2)
	{
		$this->loadIndex(true);
		$used = array();
		foreach ($this->listSnapshots() as $snapshot) {
			$this->markUsed($snapshot['tree'], $used);
		}
		$packFiles = $this->storage->listFiles('data');
		if ($packFiles === false) {
			throw new CloudBackupException($this->storage->error);
		}
		// Pack id => its blobs, from the index
		$packs = array();
		foreach ($this->blobs as $blobId => $blob) {
			$packs[$blob[0]][$blobId] = $blob;
		}
		$keepPacks = array();
		$deletePacks = array();
		$repacked = array();
		foreach ($packs as $packId => $blobs) {
			$name = 'data/'.substr($packId, 0, 2).'/'.$packId;
			if (!isset($packFiles[$name])) {
				continue;
			}
			$usedBytes = 0;
			$totalBytes = 0;
			foreach ($blobs as $blobId => $blob) {
				$totalBytes += $blob[2];
				if (isset($used[$blobId])) {
					$usedBytes += $blob[2];
				}
			}
			if ($usedBytes == 0) {
				$deletePacks[] = $name;
			} elseif ($totalBytes > 0 && ($totalBytes - $usedBytes) / $totalBytes > $maxUnused) {
				$this->progress('Repack '.substr($packId, 0, 8));
				foreach ($blobs as $blobId => $blob) {
					if (isset($used[$blobId]) && !isset($repacked[$blobId])) {
						$this->saveBlob($blob[3], $this->loadBlob($blobId), true);
						$repacked[$blobId] = true;
					}
				}
				$deletePacks[] = $name;
			} else {
				$keepPacks[$packId] = $blobs;
			}
		}
		// Packs no index knows: left over by an interrupted backup
		foreach (array_keys($packFiles) as $name) {
			if (!isset($packs[dol_basename($name)])) {
				$deletePacks[] = $name;
			}
		}
		foreach (array_keys($this->packers) as $type) {
			$this->flushPack($type);
		}
		foreach ($keepPacks as $packId => $blobs) {
			$entries = array();
			foreach ($blobs as $blobId => $blob) {
				$entry = array('id' => $blobId, 'type' => $blob[3], 'offset' => $blob[1], 'length' => $blob[2]);
				if ($blob[4] > 0) {
					$entry['uncompressed_length'] = $blob[4];
				}
				$entries[] = $entry;
			}
			$this->newPacks[] = array('id' => $packId, 'blobs' => $entries);
		}
		$oldIndex = $this->indexFiles;
		$this->indexFiles = array();
		$this->writeNewIndex();
		// Only once the new index is written, the old one and the packs go
		foreach ($oldIndex as $name) {
			$this->storage->delete($name);
		}
		foreach ($deletePacks as $name) {
			if ($this->storage->delete($name)) {
				$this->stats['packs_deleted']++;
			}
		}
		$this->loadIndex(true);
	}

	/**
	 * Mark the blobs reachable from a tree
	 *
	 * @param	string				$treeId		Tree id
	 * @param	array<string,bool>	$used		Blob ids, filled
	 * @return	void
	 * @throws CloudBackupException
	 */
	private function markUsed($treeId, &$used)
	{
		if (isset($used[$treeId])) {
			return;
		}
		$used[$treeId] = true;
		foreach ($this->loadTree($treeId) as $node) {
			if ($node['type'] === 'dir' && !empty($node['subtree'])) {
				$this->markUsed($node['subtree'], $used);
			} elseif (!empty($node['content'])) {
				foreach ($node['content'] as $blobId) {
					$used[$blobId] = true;
				}
			}
		}
	}

	/**
	 * Check the repository: every pack of the index exists with its size, every tree of every
	 * snapshot can be read and references known blobs. With $readData, every pack is read and checked.
	 *
	 * @param	bool	$readData	Read and verify all the data
	 * @return	list<string>	Errors found, empty if the repository is sound
	 * @throws CloudBackupException
	 */
	public function check($readData = false)
	{
		$errors = array();
		$this->loadIndex(true);
		$packFiles = $this->storage->listFiles('data');
		if ($packFiles === false) {
			throw new CloudBackupException($this->storage->error);
		}
		$packs = array();
		foreach ($this->blobs as $blobId => $blob) {
			$packs[$blob[0]][] = $blobId;
		}
		foreach ($packs as $packId => $blobIds) {
			$name = 'data/'.substr($packId, 0, 2).'/'.$packId;
			if (!isset($packFiles[$name])) {
				$errors[] = 'Pack '.$packId.' is in the index but missing';
				continue;
			}
			if ($readData) {
				$data = $this->storage->get($name);
				if ($data === false || hash('sha256', $data) !== $packId) {
					$errors[] = 'Pack '.$packId.' is damaged';
				}
			}
		}
		$seen = array();
		foreach ($this->listSnapshots() as $snapshotId => $snapshot) {
			try {
				$this->checkTree($snapshot['tree'], $seen, $errors);
			} catch (CloudBackupException $e) {
				$errors[] = 'Snapshot '.substr($snapshotId, 0, 8).': '.$e->getMessage();
			}
		}
		return array_values($errors);
	}

	/**
	 * Check a tree and its subtrees
	 *
	 * @param	string				$treeId		Tree id
	 * @param	array<string,bool>	$seen		Trees already checked
	 * @param	string[]			$errors		Errors, filled
	 * @return	void
	 * @throws CloudBackupException
	 */
	private function checkTree($treeId, &$seen, &$errors)
	{
		if (isset($seen[$treeId])) {
			return;
		}
		$seen[$treeId] = true;
		foreach ($this->loadTree($treeId) as $node) {
			if ($node['type'] === 'dir') {
				$this->checkTree($node['subtree'], $seen, $errors);
			} elseif ($node['type'] === 'file') {
				foreach ((array) $node['content'] as $blobId) {
					if (!isset($this->blobs[$blobId])) {
						$errors[] = 'File '.$node['name'].' uses the missing blob '.$blobId;
					}
				}
			}
		}
	}

	/**
	 * Release the lock and the connection
	 *
	 * @return void
	 */
	public function close()
	{
		$this->unlock();
		$this->packCache = array();
		$this->packCacheSize = 0;
		$this->storage->close();
	}

	// ---------------------------------------------------------------------------------------------
	// Helpers

	/**
	 * Tell whether Dolibarr keeps a file name as it is: names it would rewrite (< > ? * | " ° $ ; ` ~ .. --)
	 * are neither backed up nor restored
	 *
	 * @param	string	$name	File or directory name
	 * @return	bool
	 */
	public static function isAcceptedName($name)
	{
		return dol_sanitizePathName($name, '_', 0) === $name;
	}

	/**
	 * Period of a time, for the retention rules
	 *
	 * @param	string	$format		dol_print_date() format, or 'week'
	 * @param	int		$time		Local time as seconds (Unix time plus the offset of its zone)
	 * @return	string
	 */
	private static function period($format, $time)
	{
		if ($format !== 'week') {
			return dol_print_date($time, $format, 'gmt');
		}
		// Monday based week: days since the epoch (a Thursday), shifted by 3
		return (string) intdiv(intdiv($time, 86400) + 3, 7);
	}

	/**
	 * Time in the RFC 3339 format restic writes: local time of the server with its offset, nanoseconds
	 *
	 * @param	float|int	$time	Unix time
	 * @return	string
	 */
	public static function formatTime($time)
	{
		$seconds = (int) floor($time);
		$nanos = (int) round(($time - $seconds) * 1000000) * 1000;
		$local = dol_print_date($seconds, '%Y-%m-%d %H:%M:%S', 'tzserver');
		$offset = (int) dol_stringtotime($local, 'gmt') - $seconds;
		$sign = ($offset < 0) ? '-' : '+';
		return str_replace(' ', 'T', $local).($nanos ? '.'.rtrim(sprintf('%09d', $nanos), '0') : '').sprintf('%s%02d:%02d', $sign, intdiv(abs($offset), 3600), intdiv(abs($offset) % 3600, 60));
	}

	/**
	 * Offset of the time zone written in a RFC 3339 time, in seconds
	 *
	 * @param	string	$time	Time
	 * @return	int
	 */
	public static function parseOffset($time)
	{
		if (preg_match('/([+-])(\d{2}):?(\d{2})$/', trim((string) $time), $m)) {
			return ((int) $m[2] * 3600 + (int) $m[3] * 60) * ($m[1] === '-' ? -1 : 1);
		}
		return 0;
	}

	/**
	 * Unix time of a RFC 3339 time, with any offset (restic writes the local one)
	 *
	 * @param	string	$time	Time like 2026-09-28T22:18:12.8880906+02:00 or 2026-09-28T20:18:12Z
	 * @return	int
	 */
	public static function parseTime($time)
	{
		if (!preg_match('/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2}:\d{2})(\.\d+)?(Z|([+-])(\d{2}):?(\d{2}))?$/i', trim((string) $time), $m)) {
			return 0;
		}
		$utc = (int) dol_stringtotime($m[1].' '.$m[2], 'gmt');
		if (!empty($m[5])) {
			$offset = ((int) $m[6] * 3600 + (int) $m[7] * 60) * ($m[5] === '-' ? -1 : 1);
			$utc -= $offset;
		}
		return $utc;
	}

	/**
	 * A random irreducible polynomial of degree 53 over GF(2), as restic generates for its chunker
	 *
	 * @return string	Hexadecimal
	 */
	public static function randomPolynomial()
	{
		for ($try = 0; $try < 1000000; $try++) {
			$pol = (unpack('J', random_bytes(8))[1] & ((1 << 54) - 1)) | (1 << 53) | 1;
			if (self::polIrreducible($pol)) {
				return dechex($pol);
			}
		}
		// Not reached in practice: about one in 53 candidates is irreducible
		return '3da3358b4dc173';
	}

	/**
	 * Degree of a polynomial over GF(2)
	 *
	 * @param	int		$p	Polynomial
	 * @return	int
	 */
	private static function polDeg($p)
	{
		$deg = -1;
		while ($p > 0) {
			$p >>= 1;
			$deg++;
		}
		return $deg;
	}

	/**
	 * a mod m over GF(2)
	 *
	 * @param	int		$a	Polynomial
	 * @param	int		$m	Modulus
	 * @return	int
	 */
	private static function polMod($a, $m)
	{
		$dm = self::polDeg($m);
		while (($da = self::polDeg($a)) >= $dm) {
			$a ^= $m << ($da - $dm);
		}
		return $a;
	}

	/**
	 * a * b mod m over GF(2), degrees below 54
	 *
	 * @param	int		$a	Polynomial
	 * @param	int		$b	Polynomial
	 * @param	int		$m	Modulus
	 * @return	int
	 */
	private static function polMulMod($a, $b, $m)
	{
		$dm = self::polDeg($m);
		$a = self::polMod($a, $m);
		$result = 0;
		while ($b > 0) {
			if ($b & 1) {
				$result ^= $a;
			}
			$b >>= 1;
			$a <<= 1;
			if (($a >> $dm) & 1) {
				$a ^= $m;
			}
		}
		return $result;
	}

	/**
	 * gcd over GF(2)
	 *
	 * @param	int		$a	Polynomial
	 * @param	int		$b	Polynomial
	 * @return	int
	 */
	private static function polGcd($a, $b)
	{
		while ($b != 0) {
			$t = self::polMod($a, $b);
			$a = $b;
			$b = $t;
		}
		return $a;
	}

	/**
	 * Ben-Or irreducibility test, as the chunker of restic does
	 *
	 * @param	int		$p	Polynomial
	 * @return	bool
	 */
	private static function polIrreducible($p)
	{
		$x = 2;
		$power = $x;
		$half = intdiv(self::polDeg($p), 2);
		for ($i = 1; $i <= $half; $i++) {
			$power = self::polMulMod($power, $power, $p);
			if (self::polGcd($power ^ $x, $p) != 1) {
				return false;
			}
		}
		return true;
	}
}
