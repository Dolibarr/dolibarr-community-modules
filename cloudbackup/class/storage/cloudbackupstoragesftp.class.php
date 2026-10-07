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
 * \file       cloudbackup/class/storage/cloudbackupstoragesftp.class.php
 * \ingroup    cloudbackup
 * \brief      Storage on a SFTP server, when the PHP ssh2 extension is available
 */

require_once __DIR__.'/cloudbackupstorage.class.php';


/**
 * SFTP storage, through the ssh2.sftp:// stream wrapper
 */
class CloudBackupStorageSftp extends CloudBackupStorage
{
	/** @var string Host */
	private $host;
	/** @var int Port */
	private $port;
	/** @var string User */
	private $user;
	/** @var string Password */
	private $password;
	/** @var resource|null SFTP subsystem */
	private $sftp = null;

	/**
	 * Constructor
	 *
	 * @param	string	$host		Host
	 * @param	int		$port		Port
	 * @param	string	$user		User
	 * @param	string	$password	Password
	 * @param	string	$root		Directory; relative to the home of the user unless it starts with /
	 */
	public function __construct($host, $port, $user, $password, $root = '')
	{
		$this->host = trim($host);
		$this->port = $port > 0 ? $port : 22;
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
		return function_exists('ssh2_connect') ? '' : 'ssh2';
	}

	/**
	 * Open the connection when needed
	 *
	 * @return bool
	 */
	private function connect()
	{
		if ($this->sftp) {
			return true;
		}
		if ($this->missingExtension() !== '') {
			$this->error = 'Missing PHP extension ssh2';
			return false;
		}
		$conn = @ssh2_connect($this->host, $this->port);
		if (!$conn || !@ssh2_auth_password($conn, $this->user, $this->password)) {
			$this->error = 'Failed to connect to '.$this->host.':'.$this->port.' as '.$this->user;
			return false;
		}
		$sftp = @ssh2_sftp($conn);
		if (!$sftp) {
			$this->error = 'Failed to open the SFTP subsystem on '.$this->host;
			return false;
		}
		$this->sftp = $sftp;
		return true;
	}

	/**
	 * URL of an object for the stream wrapper
	 *
	 * @param	string	$name	Object name
	 * @return	string
	 */
	private function url($name)
	{
		$full = $this->fullName($name);
		if ($full === '' || $full[0] !== '/') {
			$full = ssh2_sftp_realpath($this->sftp, '.').'/'.$full;
		}
		return 'ssh2.sftp://'.intval($this->sftp).rtrim($full, '/');
	}

	/**
	 * Write an object
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
		$url = $this->url($name);
		if (!is_dir(dirname($url))) {
			@mkdir(dirname($url), 0700, true);
		}
		$tmp = $url.'.tmp'.getmypid();
		if (@file_put_contents($tmp, $data) !== strlen($data)) {
			@unlink($tmp);
			$this->error = 'Failed to upload '.$name;
			return false;
		}
		@unlink($url);
		if (!@rename($tmp, $url)) {
			$this->error = 'Failed to rename '.$name;
			return false;
		}
		return true;
	}

	/**
	 * Read an object
	 *
	 * @param	string	$name	Object name
	 * @return	string|false
	 */
	public function get($name)
	{
		if (!$this->connect()) {
			return false;
		}
		$data = @file_get_contents($this->url($name));
		if ($data === false) {
			$this->error = 'Failed to download '.$name;
		}
		return $data;
	}

	/**
	 * Read a part of an object
	 *
	 * @param	string	$name	Object name
	 * @param	int		$offset	Offset
	 * @param	int		$length	Length
	 * @return	string|false
	 */
	public function getRange($name, $offset, $length)
	{
		if (!$this->connect()) {
			return false;
		}
		$handle = @fopen($this->url($name), 'rb');
		if (!$handle) {
			$this->error = 'Failed to open '.$name;
			return false;
		}
		fseek($handle, $offset);
		$data = '';
		while (strlen($data) < $length && !feof($handle)) {
			$chunk = fread($handle, $length - strlen($data));
			if ($chunk === false || $chunk === '') {
				break;
			}
			$data .= $chunk;
		}
		fclose($handle);
		return strlen($data) == $length ? $data : false;
	}

	/**
	 * Delete an object
	 *
	 * @param	string	$name	Object name
	 * @return	bool
	 */
	public function delete($name)
	{
		if (!$this->connect()) {
			return false;
		}
		$url = $this->url($name);
		return !file_exists($url) || @unlink($url);
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
		$this->walk(trim($dir, '/'), $result);
		return $result;
	}

	/**
	 * Recursive listing
	 *
	 * @param	string				$dir		Directory, relative to the root
	 * @param	array<string,int>	$result		Result, filled
	 * @return	void
	 */
	private function walk($dir, &$result)
	{
		$url = $this->url($dir);
		$handle = @opendir($url);
		if (!$handle) {
			return;
		}
		while (($entry = readdir($handle)) !== false) {
			if ($entry === '.' || $entry === '..' || preg_match('/\.tmp[0-9]+$/', $entry)) {
				continue;
			}
			$child = ($dir === '' ? '' : $dir.'/').$entry;
			if (is_dir($url.'/'.$entry)) {
				$this->walk($child, $result);
			} else {
				$result[$child] = (int) filesize($url.'/'.$entry);
			}
		}
		closedir($handle);
	}
}
