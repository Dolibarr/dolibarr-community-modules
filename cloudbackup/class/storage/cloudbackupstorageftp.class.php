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
 * \file       cloudbackup/class/storage/cloudbackupstorageftp.class.php
 * \ingroup    cloudbackup
 * \brief      Storage on a FTP server (plain FTP; FTPS is cloudbackupstorageftps.class.php)
 */

require_once __DIR__.'/cloudbackupstorage.class.php';


/**
 * FTP storage, with the PHP ftp extension. The dol_ftp_* functions of the core take SSL/SFTP from
 * the settings of the FTP module and cannot list recursively; the ftp:// stream is disabled by the core.
 */
class CloudBackupStorageFtp extends CloudBackupStorage
{
	/** @var string Host */
	private $host;
	/** @var int Port */
	private $port;
	/** @var string User */
	private $user;
	/** @var string Password */
	private $password;
	/** @var resource|\FTP\Connection|null Connection */
	private $conn = null;
	/** @var array<string,bool> Directories known to exist */
	private $knownDirs = array();

	/**
	 * Constructor
	 *
	 * @param	string	$host		Host
	 * @param	int		$port		Port
	 * @param	string	$user		User
	 * @param	string	$password	Password
	 * @param	string	$root		Directory on the server, relative to the home of the user
	 */
	public function __construct($host, $port, $user, $password, $root = '')
	{
		$this->host = trim($host);
		$this->port = $port > 0 ? $port : 21;
		$this->user = $user;
		$this->password = $password;
		$this->root = trim($root, '/');
	}

	/**
	 * Name of the PHP extension the driver needs and that is missing
	 *
	 * @return string
	 */
	public function missingExtension()
	{
		return function_exists('ftp_connect') ? '' : 'php-ftp';
	}

	/**
	 * Open the connection when needed
	 *
	 * @return bool
	 */
	private function connect()
	{
		if ($this->conn) {
			return true;
		}
		if ($this->missingExtension() !== '') {
			$this->error = 'Missing PHP extension ftp';
			return false;
		}
		$conn = @ftp_connect($this->host, $this->port, 30);
		if (!$conn) {
			$this->error = 'Failed to connect to '.$this->host.':'.$this->port;
			return false;
		}
		if (!@ftp_login($conn, $this->user, $this->password)) {
			$this->error = 'Failed to log in on '.$this->host.' as '.$this->user;
			@ftp_close($conn);
			return false;
		}
		ftp_pasv($conn, true);
		// With autoseek, a download from an offset also seeks the local stream to that offset: the
		// bytes would land after a hole in php://temp
		ftp_set_option($conn, FTP_AUTOSEEK, false);
		$this->conn = $conn;
		return true;
	}

	/**
	 * Close the connection
	 *
	 * @return void
	 */
	public function close()
	{
		if ($this->conn) {
			@ftp_close($this->conn);
		}
		$this->conn = null;
		$this->knownDirs = array();
	}

	/**
	 * Create a directory and its parents
	 *
	 * @param	string	$dir	Directory, relative to the home
	 * @return	void
	 */
	private function mkdirs($dir)
	{
		if ($dir === '' || $dir === '.' || isset($this->knownDirs[$dir])) {
			return;
		}
		$path = '';
		foreach (explode('/', $dir) as $segment) {
			$path .= ($path === '' ? '' : '/').$segment;
			if (isset($this->knownDirs[$path])) {
				continue;
			}
			// mkdir fails when the directory exists: that is fine, the upload tells if it is not
			@ftp_mkdir($this->conn, $path);
			$this->knownDirs[$path] = true;
		}
	}

	/**
	 * Write an object: upload aside then rename, so a reader never sees a partial object
	 *
	 * @param	string	$name	Object name
	 * @param	string	$data	Content
	 * @return	bool
	 */
	public function put($name, $data)
	{
		if (!$this->connect()) {
			return false;
		}
		$full = $this->fullName($name);
		$this->mkdirs(dirname($full));
		$stream = fopen('php://temp', 'w+b');
		fwrite($stream, $data);
		rewind($stream);
		$tmp = $full.'.tmp'.getmypid();
		$ok = @ftp_fput($this->conn, $tmp, $stream, FTP_BINARY);
		fclose($stream);
		if ($ok) {
			@ftp_delete($this->conn, $full);
			$ok = @ftp_rename($this->conn, $tmp, $full);
		}
		if (!$ok) {
			@ftp_delete($this->conn, $tmp);
			$this->error = 'Failed to upload '.$full;
		}
		return $ok;
	}

	/**
	 * Read an object
	 *
	 * @param	string	$name	Object name
	 * @return	string|false
	 */
	public function get($name)
	{
		return $this->download($name, 0);
	}

	/**
	 * Read a part of an object. FTP can start at an offset but not stop early: the rest is discarded.
	 *
	 * @param	string	$name	Object name
	 * @param	int		$offset	Offset
	 * @param	int		$length	Length
	 * @return	string|false
	 */
	public function getRange($name, $offset, $length)
	{
		$data = $this->download($name, $offset);
		if ($data === false || strlen($data) < $length) {
			return false;
		}
		return substr($data, 0, $length);
	}

	/**
	 * Download an object from an offset
	 *
	 * @param	string	$name	Object name
	 * @param	int		$offset	Offset
	 * @return	string|false
	 */
	private function download($name, $offset)
	{
		if (!$this->connect()) {
			return false;
		}
		$stream = fopen('php://temp', 'w+b');
		if (!@ftp_fget($this->conn, $stream, $this->fullName($name), FTP_BINARY, $offset)) {
			fclose($stream);
			$this->error = 'Failed to download '.$this->fullName($name);
			return false;
		}
		rewind($stream);
		$data = stream_get_contents($stream);
		fclose($stream);
		return $data;
	}

	/**
	 * Delete an object. Deleting a missing object is not an error.
	 *
	 * @param	string	$name	Object name
	 * @return	bool
	 */
	public function delete($name)
	{
		if (!$this->connect()) {
			return false;
		}
		$full = $this->fullName($name);
		if (@ftp_delete($this->conn, $full)) {
			return true;
		}
		return @ftp_size($this->conn, $full) < 0;
	}

	/**
	 * List objects under a directory, recursively
	 *
	 * @param	string	$dir	Directory
	 * @return	array<string,int>|false
	 */
	public function listFiles($dir)
	{
		if (!$this->connect()) {
			return false;
		}
		$result = array();
		$this->walk($this->fullName(trim($dir, '/')), $result);
		return $result;
	}

	/**
	 * Recursive listing, with MLSD when the server has it, else NLST and SIZE
	 *
	 * @param	string				$full		Directory, full name
	 * @param	array<string,int>	$result		Result, filled
	 * @return	void
	 */
	private function walk($full, &$result)
	{
		$entries = function_exists('ftp_mlsd') ? @ftp_mlsd($this->conn, $full) : false;
		if (is_array($entries)) {
			foreach ($entries as $entry) {
				if ($entry['name'] === '.' || $entry['name'] === '..' || $entry['type'] === 'cdir' || $entry['type'] === 'pdir') {
					continue;
				}
				$child = $full.'/'.$entry['name'];
				if ($entry['type'] === 'dir') {
					$this->walk($child, $result);
				} elseif (!preg_match('/\.tmp[0-9]+$/', $entry['name'])) {
					$result[$this->relativeName($child)] = (int) (isset($entry['size']) ? $entry['size'] : @ftp_size($this->conn, $child));
				}
			}
			return;
		}
		$names = @ftp_nlist($this->conn, $full);
		if (!is_array($names)) {
			return;
		}
		foreach ($names as $child) {
			$base = dol_basename($child);
			if ($base === '.' || $base === '..') {
				continue;
			}
			$child = $full.'/'.$base;
			$size = @ftp_size($this->conn, $child);
			if ($size < 0) {
				$this->walk($child, $result);
			} elseif (!preg_match('/\.tmp[0-9]+$/', $base)) {
				$result[$this->relativeName($child)] = (int) $size;
			}
		}
	}
}
