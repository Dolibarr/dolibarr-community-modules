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
 * \file       cloudbackup/class/storage/cloudbackupstorageftps.class.php
 * \ingroup    cloudbackup
 * \brief      Storage on a FTPS server (FTP with explicit TLS), through curl
 */

require_once __DIR__.'/cloudbackupstorage.class.php';


/**
 * FTPS storage through curl, the only FTPS client of PHP that works everywhere: the ftp extension
 * fails on TLS 1.3 servers from PHP 8.2, the core disables the ftps:// stream, and neither reuses the
 * TLS session on the data connection as vsftpd requires by default. getURLContent() has no FTP upload.
 */
class CloudBackupStorageFtps extends CloudBackupStorage
{
	/** curl errors of a missing file or directory: 9 (CWD refused), 78 (remote file not found) */
	const MISSING = array(9, 78);

	/** curl errors worth a new attempt: 7 connect, 28 timeout, 52 got nothing, 55 send, 56 receive */
	const TRANSIENT = array(7, 28, 52, 55, 56);

	/** @var string Host */
	private $host;
	/** @var int Port */
	private $port;
	/** @var string User */
	private $user;
	/** @var string Password */
	private $password;
	/** @var int 1 = check the certificate of the server */
	private $verify;

	/**
	 * Constructor
	 *
	 * @param	string	$host		Host
	 * @param	int		$port		Port
	 * @param	string	$user		User
	 * @param	string	$password	Password
	 * @param	string	$root		Directory on the server, relative to the home of the user
	 * @param	int		$verify		1 to check the certificate of the server
	 */
	public function __construct($host, $port, $user, $password, $root = '', $verify = 1)
	{
		$this->host = trim($host);
		$this->port = $port > 0 ? $port : 21;
		$this->user = $user;
		$this->password = $password;
		$this->root = trim($root, '/');
		$this->verify = $verify;
	}

	/**
	 * Name of the PHP extension the driver needs and that is missing
	 *
	 * @return string
	 */
	public function missingExtension()
	{
		if (!function_exists('curl_init')) {
			return 'php-curl';
		}
		$version = curl_version();
		return in_array('ftps', (array) $version['protocols']) ? '' : 'php-curl (with ftps)';
	}

	/**
	 * URL of an object, or of a directory with a trailing slash
	 *
	 * @param	string	$full	Full name, root included
	 * @return	string
	 */
	private function url($full)
	{
		return 'ftp://'.$this->host.':'.$this->port.'/'.implode('/', array_map('rawurlencode', explode('/', $full)));
	}

	/**
	 * Run one curl transfer, TLS required on both connections
	 *
	 * @param	string				$url		URL
	 * @param	array<int,mixed>	$options	curl options
	 * @return	array{0:string|false,1:int,2:string}	Body, curl error number, curl error message
	 */
	private function transfer($url, $options)
	{
		$ch = curl_init($url);
		curl_setopt_array($ch, array(
			CURLOPT_USERPWD => $this->user.':'.$this->password,
			CURLOPT_USE_SSL => CURLUSESSL_ALL,
			CURLOPT_SSL_VERIFYPEER => (bool) $this->verify,
			CURLOPT_SSL_VERIFYHOST => $this->verify ? 2 : 0,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => 30,
			CURLOPT_TIMEOUT => 900,
		));
		curl_setopt_array($ch, $options);
		// A network cut is often transient: try again, waiting more each time (an upload rewinds its stream)
		$body = false;
		$errno = 0;
		for ($attempt = 1; $attempt <= 5; $attempt++) {
			if (isset($options[CURLOPT_INFILE])) {
				rewind($options[CURLOPT_INFILE]);
			}
			$body = curl_exec($ch);
			$errno = (int) curl_errno($ch);
			if (!in_array($errno, self::TRANSIENT) || $attempt == 5) {
				break;
			}
			sleep(1 << ($attempt - 1));
		}
		$error = curl_error($ch);
		curl_close($ch);
		return array($errno ? false : (string) $body, $errno, $error);
	}

	/**
	 * Write an object: upload aside, then rename in the same session, so a reader never sees a partial object
	 *
	 * @param	string	$name	Object name
	 * @param	string	$data	Content
	 * @return	bool
	 */
	public function put($name, $data)
	{
		$full = $this->fullName($name);
		$dir = dirname($full) === '.' ? '' : dirname($full).'/';
		$tmp = dol_basename($full).'.tmp'.getmypid();
		$stream = fopen('php://temp', 'w+b');
		fwrite($stream, $data);
		rewind($stream);
		// POSTQUOTE runs in the directory of the upload
		list(, $errno, $error) = $this->transfer($this->url($dir.$tmp), array(
			CURLOPT_UPLOAD => true,
			CURLOPT_INFILE => $stream,
			CURLOPT_INFILESIZE => strlen($data),
			CURLOPT_FTP_CREATE_MISSING_DIRS => CURLFTP_CREATE_DIR,
			CURLOPT_POSTQUOTE => array('RNFR '.$tmp, 'RNTO '.dol_basename($full)),
		));
		fclose($stream);
		if ($errno) {
			$this->error = 'Failed to upload '.$full.': '.$error;
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
		list($body, , $error) = $this->transfer($this->url($this->fullName($name)), array());
		if ($body === false) {
			$this->error = 'Failed to download '.$name.': '.$error;
		}
		return $body;
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
		list($body, , $error) = $this->transfer($this->url($this->fullName($name)), array(CURLOPT_RANGE => ((int) $offset).'-'.((int) $offset + (int) $length - 1)));
		if ($body === false || strlen($body) != $length) {
			$this->error = 'Failed to download '.$name.': '.$error;
			return false;
		}
		return $body;
	}

	/**
	 * Delete an object. Deleting a missing object is not an error.
	 *
	 * @param	string	$name	Object name
	 * @return	bool
	 */
	public function delete($name)
	{
		$full = $this->fullName($name);
		$dir = dirname($full) === '.' ? '' : dirname($full).'/';
		// PREQUOTE runs after the CWD into the directory, just before the (empty) listing
		list(, $errno, $error) = $this->transfer($this->url($dir), array(CURLOPT_NOBODY => true, CURLOPT_PREQUOTE => array('DELE '.dol_basename($full))));
		if (!$errno) {
			return true;
		}
		// A refused DELE of an object already gone is fine
		$left = $this->listFiles(dirname($name) === '.' ? '' : dirname($name));
		if (is_array($left) && !isset($left[ltrim($name, '/')])) {
			return true;
		}
		$this->error = 'Failed to delete '.$full.': '.$error;
		return false;
	}

	/**
	 * List objects under a directory, recursively
	 *
	 * @param	string	$dir	Directory
	 * @return	array<string,int>|false
	 */
	public function listFiles($dir)
	{
		$result = array();
		return $this->walk($this->fullName(trim($dir, '/')), $result) ? $result : false;
	}

	/**
	 * Recursive listing with MLSD
	 *
	 * @param	string				$full		Directory, full name
	 * @param	array<string,int>	$result		Result, filled
	 * @return	bool
	 */
	private function walk($full, &$result)
	{
		list($body, $errno, $error) = $this->transfer($this->url($full === '' ? '' : $full.'/'), array(CURLOPT_CUSTOMREQUEST => 'MLSD'));
		if ($body === false) {
			// A directory that does not exist yet is an empty one
			if (in_array($errno, self::MISSING)) {
				return true;
			}
			$this->error = 'Failed to list '.$full.': '.$error;
			return false;
		}
		foreach (preg_split('/\r?\n/', $body) as $line) {
			if (!preg_match('/^(\S+) (.+)$/', $line, $m)) {
				continue;
			}
			$facts = array();
			foreach (explode(';', dol_strtolower($m[1])) as $fact) {
				$pair = explode('=', $fact, 2);
				if (count($pair) == 2) {
					$facts[$pair[0]] = $pair[1];
				}
			}
			$entry = $m[2];
			$type = isset($facts['type']) ? $facts['type'] : '';
			if ($type === 'cdir' || $type === 'pdir' || $entry === '.' || $entry === '..') {
				continue;
			}
			$child = ($full === '' ? '' : $full.'/').$entry;
			if ($type === 'dir') {
				if (!$this->walk($child, $result)) {
					return false;
				}
			} elseif (!preg_match('/\.tmp[0-9]+$/', $entry)) {
				$result[$this->relativeName($child)] = isset($facts['size']) ? (int) $facts['size'] : 0;
			}
		}
		return true;
	}
}
