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
 * \file       cloudbackup/class/storage/cloudbackupstorage.class.php
 * \ingroup    cloudbackup
 * \brief      Base class of the places a backup can be stored in
 */

require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';


/**
 * A flat object store: names are relative paths ('data/ab/abcd...'), the driver maps them on its own layout.
 * Every driver works with PHP extensions only (no shell, no system package), so it runs on shared hosting.
 */
abstract class CloudBackupStorage
{
	/** @var string Last error */
	public $error = '';

	/** @var string Root path or key prefix on the storage, without leading nor trailing slash */
	protected $root = '';

	/**
	 * Value of a secret setting. The setup stores secrets base64 encoded, so the constant
	 * encryption of the core only ever sees ASCII (Dolibarr 23 cannot decrypt anything else).
	 *
	 * @param	string	$name	Constant name
	 * @return	string
	 */
	public static function secret($name)
	{
		$value = getDolGlobalString($name);
		if (strpos($value, 'b64:') === 0) {
			return (string) base64_decode(substr($value, 4));
		}
		return $value;
	}

	/**
	 * Build the storage configured in the module setup
	 *
	 * @param	string		$type	'local', 's3', 'ftp' or 'sftp' ; empty = the one of the setup
	 * @return	CloudBackupStorage|null
	 */
	public static function fromSetup($type = '')
	{
		if ($type === '') {
			$type = getDolGlobalString('CLOUDBACKUP_STORAGE', 'local');
		}
		$root = trim(getDolGlobalString('CLOUDBACKUP_PATH'), '/');
		switch ($type) {
			case 's3':
				require_once __DIR__.'/cloudbackupstorages3.class.php';
				return new CloudBackupStorageS3(
					getDolGlobalString('CLOUDBACKUP_S3_ENDPOINT'),
					getDolGlobalString('CLOUDBACKUP_S3_REGION'),
					getDolGlobalString('CLOUDBACKUP_S3_BUCKET'),
					self::secret('CLOUDBACKUP_S3_ACCESS_KEY'),
					self::secret('CLOUDBACKUP_S3_SECRET'),
					$root,
					getDolGlobalInt('CLOUDBACKUP_S3_VIRTUAL_HOST') ? 0 : 1,
					getDolGlobalInt('CLOUDBACKUP_ALLOW_LOCAL_URL')
				);
			case 'ftp':
				if (getDolGlobalInt('CLOUDBACKUP_FTP_SSL')) {
					require_once __DIR__.'/cloudbackupstorageftps.class.php';
					return new CloudBackupStorageFtps(
						getDolGlobalString('CLOUDBACKUP_FTP_HOST'),
						getDolGlobalInt('CLOUDBACKUP_FTP_PORT', 21),
						getDolGlobalString('CLOUDBACKUP_FTP_USER'),
						self::secret('CLOUDBACKUP_FTP_PASSWORD'),
						$root,
						getDolGlobalInt('CLOUDBACKUP_FTP_VERIFY', 1)
					);
				}
				require_once __DIR__.'/cloudbackupstorageftp.class.php';
				return new CloudBackupStorageFtp(
					getDolGlobalString('CLOUDBACKUP_FTP_HOST'),
					getDolGlobalInt('CLOUDBACKUP_FTP_PORT', 21),
					getDolGlobalString('CLOUDBACKUP_FTP_USER'),
					self::secret('CLOUDBACKUP_FTP_PASSWORD'),
					$root
				);
			case 'sftp':
				require_once __DIR__.'/cloudbackupstoragesftp.class.php';
				return new CloudBackupStorageSftp(
					getDolGlobalString('CLOUDBACKUP_SFTP_HOST'),
					getDolGlobalInt('CLOUDBACKUP_SFTP_PORT', 22),
					getDolGlobalString('CLOUDBACKUP_SFTP_USER'),
					self::secret('CLOUDBACKUP_SFTP_PASSWORD'),
					$root
				);
			case 'local':
				require_once __DIR__.'/cloudbackupstoragelocal.class.php';
				return new CloudBackupStorageLocal(getDolGlobalString('CLOUDBACKUP_LOCAL_PATH'), $root);
		}
		return null;
	}

	/**
	 * Name of the PHP extension the driver needs and that is missing
	 *
	 * @return string	Empty if all is available
	 */
	public function missingExtension()
	{
		return '';
	}

	/**
	 * Check that the storage is reachable and writable
	 *
	 * @return bool
	 */
	public function test()
	{
		$name = 'cloudbackup-test-'.bin2hex(random_bytes(4));
		$payload = 'cloudbackup '.dol_print_date(dol_now(), 'dayhourrfc', 'gmt');
		if (!$this->put($name, $payload)) {
			return false;
		}
		$back = $this->get($name);
		$this->delete($name);
		if ($back !== $payload) {
			$this->error = $this->error ? $this->error : 'The object read back differs from the object written';
			return false;
		}
		return true;
	}

	/**
	 * Write an object, replacing it if it exists
	 *
	 * @param	string	$name	Object name
	 * @param	string	$data	Content
	 * @return	bool
	 */
	abstract public function put($name, $data);

	/**
	 * Read an object
	 *
	 * @param	string	$name	Object name
	 * @return	string|false	Content, false if missing or on error
	 */
	abstract public function get($name);

	/**
	 * Read a part of an object
	 *
	 * @param	string	$name	Object name
	 * @param	int		$offset	Offset of the first byte
	 * @param	int		$length	Number of bytes
	 * @return	string|false
	 */
	abstract public function getRange($name, $offset, $length);

	/**
	 * Delete an object. Deleting a missing object is not an error.
	 *
	 * @param	string	$name	Object name
	 * @return	bool
	 */
	abstract public function delete($name);

	/**
	 * List the objects whose name starts with a directory, recursively
	 *
	 * @param	string	$dir	Directory ('keys', 'data', 'archives/2026...'), without trailing slash
	 * @return	array<string,int>|false	Object name (relative to the root) => size
	 */
	abstract public function listFiles($dir);

	/**
	 * Close the connection, if any
	 *
	 * @return void
	 */
	public function close()
	{
	}

	/**
	 * Name of an object on the storage, root included
	 *
	 * @param	string	$name	Object name
	 * @return	string
	 */
	protected function fullName($name)
	{
		$name = trim($name, '/');
		if ($name === '') {
			return $this->root;
		}
		return ($this->root !== '' ? $this->root.'/' : '').$name;
	}

	/**
	 * Object name relative to the root, from a full name
	 *
	 * @param	string	$fullname	Full name
	 * @return	string
	 */
	protected function relativeName($fullname)
	{
		$fullname = ltrim($fullname, '/');
		if ($this->root !== '' && strpos($fullname, $this->root.'/') === 0) {
			return substr($fullname, strlen($this->root) + 1);
		}
		return $fullname;
	}
}
