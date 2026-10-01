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
 * \file       cloudbackup/class/cloudbackuparchive.class.php
 * \ingroup    cloudbackup
 * \brief      Backups as plain files (gzipped dump, zip of documents) on a storage
 */

require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
require_once __DIR__.'/storage/cloudbackupstorage.class.php';
require_once __DIR__.'/resticrepository.class.php';


/**
 * Plain archives: archives/<YYYYMMDD-HHMMSS>/ holds a manifest.json and the files, cut in parts
 * so no upload is bigger than PART_SIZE (memory of shared hosting, size limits of some servers).
 * Rebuilding a file is a plain concatenation: cat documents.zip.part* > documents.zip
 */
class CloudBackupArchive
{
	const PART_SIZE = 16777216;

	/** @var CloudBackupStorage */
	private $storage;

	/** @var callable|null */
	public $progress = null;

	/**
	 * Constructor
	 *
	 * @param	CloudBackupStorage	$storage	Storage
	 */
	public function __construct($storage)
	{
		$this->storage = $storage;
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
	 * Store files as a new archive
	 *
	 * @param	array<string,string>	$files		Name in the archive => local path
	 * @param	array<string,string>	$meta		Written in the manifest
	 * @return	string							Archive name
	 * @throws CloudBackupException
	 */
	public function store($files, $meta)
	{
		$now = dol_now();
		$name = dol_print_date($now, '%Y%m%d-%H%M%S', 'gmt');
		$manifest = array_merge($meta, array('format' => 1, 'time' => $now, 'files' => array()));
		foreach ($files as $fileName => $path) {
			$handle = @fopen($path, 'rb');
			if (!$handle) {
				throw new CloudBackupException('Cannot read '.$path);
			}
			$hash = hash_init('sha256');
			$parts = 0;
			$size = 0;
			while (!feof($handle)) {
				$data = fread($handle, self::PART_SIZE);
				while ($data !== false && strlen($data) < self::PART_SIZE && !feof($handle)) {
					$more = fread($handle, self::PART_SIZE - strlen($data));
					if ($more === false || $more === '') {
						break;
					}
					$data .= $more;
				}
				if ($data === false || ($data === '' && $parts > 0)) {
					break;
				}
				$parts++;
				$this->progress('Upload '.$fileName.' part '.$parts.' ('.strlen($data).' bytes)');
				if (!$this->storage->put('archives/'.$name.'/'.$fileName.'.part'.sprintf('%03d', $parts), $data)) {
					fclose($handle);
					throw new CloudBackupException($this->storage->error);
				}
				hash_update($hash, $data);
				$size += strlen($data);
			}
			fclose($handle);
			$manifest['files'][$fileName] = array('size' => $size, 'parts' => $parts, 'sha256' => hash_final($hash));
		}
		// The manifest goes last: an archive without manifest is an interrupted one
		if (!$this->storage->put('archives/'.$name.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))) {
			throw new CloudBackupException($this->storage->error);
		}
		return $name;
	}

	/**
	 * Archives on the storage, with their manifest
	 *
	 * @return array<string,non-empty-array<mixed>>	Archive name => manifest
	 * @throws CloudBackupException
	 */
	public function listArchives()
	{
		$files = $this->storage->listFiles('archives');
		if ($files === false) {
			throw new CloudBackupException($this->storage->error);
		}
		$archives = array();
		foreach (array_keys($files) as $file) {
			if (preg_match('#^archives/([0-9]{8}-[0-9]{6})/manifest\.json$#', $file, $m)) {
				$manifest = json_decode((string) $this->storage->get($file), true);
				if (is_array($manifest) && isset($manifest['files'])) {
					$archives[$m[1]] = $manifest;
				}
			}
		}
		if (!count($archives)) {
			return array();
		}
		ksort($archives);
		return $archives;
	}

	/**
	 * Manifest of an archive
	 *
	 * @param	string	$name	Archive name
	 * @return	non-empty-array<mixed>
	 * @throws CloudBackupException
	 */
	public function loadManifest($name)
	{
		if (!preg_match('/^[0-9]{8}-[0-9]{6}$/', $name)) {
			throw new CloudBackupException('Bad archive name');
		}
		$manifest = json_decode((string) $this->storage->get('archives/'.$name.'/manifest.json'), true);
		if (!is_array($manifest) || !isset($manifest['files'])) {
			throw new CloudBackupException('Archive '.$name.' not found');
		}
		return $manifest;
	}

	/**
	 * Download a file of an archive, joining its parts, and check its hash
	 *
	 * @param	string	$name		Archive name
	 * @param	string	$fileName	File in the archive
	 * @param	string	$target		Local path
	 * @return	void
	 * @throws CloudBackupException
	 */
	public function fetch($name, $fileName, $target)
	{
		$handle = @fopen($target, 'wb');
		if (!$handle) {
			throw new CloudBackupException('Cannot write '.$target);
		}
		try {
			$this->stream($name, $fileName, function ($data) use ($handle, $target) {
				if (fwrite($handle, $data) === false) {
					throw new CloudBackupException('Cannot write '.$target);
				}
			});
		} catch (CloudBackupException $e) {
			fclose($handle);
			dol_delete_file($target, 1, 1, 1, null, false, 0);
			throw $e;
		}
		fclose($handle);
	}

	/**
	 * Read a file of an archive part by part. The last part is given only once the hash is checked:
	 * a damaged file then stops short of its size, and a browser marks its download as failed.
	 *
	 * @param	string		$name		Archive name
	 * @param	string		$fileName	File in the archive
	 * @param	callable	$output		Called with each part
	 * @return	void
	 * @throws CloudBackupException
	 */
	public function stream($name, $fileName, $output)
	{
		$manifest = $this->loadManifest($name);
		if (!isset($manifest['files'][$fileName])) {
			throw new CloudBackupException('No '.$fileName.' in archive '.$name);
		}
		$info = $manifest['files'][$fileName];
		$hash = hash_init('sha256');
		$data = '';
		for ($part = 1; $part <= $info['parts']; $part++) {
			if ($part > 1) {
				call_user_func($output, $data);
			}
			$data = $this->storage->get('archives/'.$name.'/'.$fileName.'.part'.sprintf('%03d', $part));
			if ($data === false) {
				throw new CloudBackupException($this->storage->error);
			}
			hash_update($hash, $data);
		}
		if (hash_final($hash) !== $info['sha256']) {
			throw new CloudBackupException($fileName.' of archive '.$name.' is damaged (hash mismatch)');
		}
		call_user_func($output, $data);
	}

	/**
	 * Delete an archive
	 *
	 * @param	string	$name	Archive name
	 * @return	void
	 * @throws CloudBackupException
	 */
	public function delete($name)
	{
		$files = $this->storage->listFiles('archives/'.$name);
		if ($files === false) {
			throw new CloudBackupException($this->storage->error);
		}
		// Manifest first: a half deleted archive must not look complete
		$this->storage->delete('archives/'.$name.'/manifest.json');
		foreach (array_keys($files) as $file) {
			$this->storage->delete($file);
		}
	}

	/**
	 * Keep the N most recent archives of this instance
	 *
	 * @param	int		$keep	Number to keep, 0 = all
	 * @return	int				Number removed
	 * @throws CloudBackupException
	 */
	public function applyRetention($keep)
	{
		if ($keep <= 0) {
			return 0;
		}
		$own = array();
		foreach ($this->listArchives() as $name => $manifest) {
			if (isset($manifest['host']) && $manifest['host'] === CloudBackup::instanceName()) {
				$own[] = $name;
			}
		}
		$remove = array_slice($own, 0, max(0, count($own) - $keep));
		foreach ($remove as $name) {
			$this->delete($name);
		}
		return count($remove);
	}

	/**
	 * Check that every part of every archive is there with the right total size
	 *
	 * @return list<string>	Problems
	 * @throws CloudBackupException
	 */
	public function check()
	{
		$errors = array();
		$files = $this->storage->listFiles('archives');
		if ($files === false) {
			throw new CloudBackupException($this->storage->error);
		}
		foreach ($this->listArchives() as $name => $manifest) {
			foreach ($manifest['files'] as $fileName => $info) {
				$size = 0;
				for ($part = 1; $part <= $info['parts']; $part++) {
					$key = 'archives/'.$name.'/'.$fileName.'.part'.sprintf('%03d', $part);
					if (!isset($files[$key])) {
						$errors[] = $name.': part '.$part.' of '.$fileName.' is missing';
					} elseif ($files[$key] < 0 || $size < 0) {
						// A storage that cannot tell sizes (FTPS without certificate check)
						$size = -1;
					} else {
						$size += $files[$key];
					}
				}
				if ($size >= 0 && $size != $info['size']) {
					$errors[] = $name.': '.$fileName.' has '.$size.' bytes instead of '.$info['size'];
				}
			}
		}
		return array_values($errors);
	}
}
