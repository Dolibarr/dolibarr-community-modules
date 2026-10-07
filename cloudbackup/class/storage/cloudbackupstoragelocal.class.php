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
 * \file       cloudbackup/class/storage/cloudbackupstoragelocal.class.php
 * \ingroup    cloudbackup
 * \brief      Storage in a directory of the server (a mounted disk, another account of the host...)
 */

require_once __DIR__.'/cloudbackupstorage.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';


/**
 * Local directory storage
 */
class CloudBackupStorageLocal extends CloudBackupStorage
{
	/** @var string Base directory */
	private $base;

	/**
	 * Constructor
	 *
	 * @param	string	$base	Base directory, absolute
	 * @param	string	$root	Sub directory inside the base
	 */
	public function __construct($base, $root = '')
	{
		$this->base = rtrim($base, '/');
		$this->root = trim($root, '/');
	}

	/**
	 * Check that the storage is reachable and writable
	 *
	 * @return bool
	 */
	public function test()
	{
		if ($this->base === '' || $this->base[0] !== '/') {
			$this->error = 'The local directory must be an absolute path';
			return false;
		}
		if (self::isInside($this->path(''), DOL_DATA_ROOT)) {
			// A backup inside the documents directory would back itself up and die with it
			$this->error = 'The local directory must be outside the documents directory '.DOL_DATA_ROOT;
			return false;
		}
		return parent::test();
	}

	/**
	 * Real path of a path that may not exist yet: the real path of its deepest existing parent, then
	 * the rest. A symbolic link must not bring a directory back inside another one.
	 *
	 * @param	string	$path	Absolute path
	 * @return	string
	 */
	public static function realPath($path)
	{
		$path = rtrim($path, '/');
		$rest = '';
		while ($path !== '' && $path !== '/' && @realpath($path) === false) {
			$rest = '/'.dol_basename($path).$rest;
			$path = dirname($path);
		}
		$real = @realpath($path === '' ? '/' : $path);
		return rtrim($real === false ? $path : $real, '/').$rest;
	}

	/**
	 * Tell whether a path is a directory or under it, as written or once the links are resolved
	 *
	 * @param	string	$path	Absolute path
	 * @param	string	$dir	Absolute directory
	 * @return	bool
	 */
	public static function isInside($path, $dir)
	{
		foreach (array(rtrim($path, '/'), self::realPath($path)) as $test) {
			foreach (array(rtrim($dir, '/'), self::realPath($dir)) as $parent) {
				if ($parent !== '' && strpos($test.'/', $parent.'/') === 0) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Path of an object
	 *
	 * @param	string	$name	Object name
	 * @return	string
	 */
	private function path($name)
	{
		return rtrim($this->base.'/'.$this->fullName($name), '/');
	}

	/**
	 * Write an object, replacing it if it exists
	 *
	 * @param	string	$name	Object name
	 * @param	string	$data	Content
	 * @return	bool
	 */
	public function put($name, $data)
	{
		$path = $this->path($name);
		if (!dol_is_dir(dirname($path)) && dol_mkdir(dirname($path), $this->base) < 0) {
			$this->error = 'Failed to create directory '.dirname($path);
			return false;
		}
		// Write aside then rename, so a reader never sees a partial object
		$tmp = $path.'.tmp'.getmypid();
		if (file_put_contents($tmp, $data) !== strlen($data) || !dol_move($tmp, $path, '0', 1, 0, 0)) {
			dol_delete_file($tmp, 1, 1, 1, null, false, 0);
			$this->error = 'Failed to write '.$path;
			return false;
		}
		dolChmod($path);
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
		$path = $this->path($name);
		if (!dol_is_file($path)) {
			$this->error = 'Not found: '.$name;
			return false;
		}
		$data = @file_get_contents($path);
		if ($data === false) {
			$this->error = 'Cannot read '.$path;
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
		$path = $this->path($name);
		if (!dol_is_file($path)) {
			$this->error = 'Not found: '.$name;
			return false;
		}
		$data = @file_get_contents($path, false, null, $offset, $length);
		return ($data !== false && strlen($data) == $length) ? $data : false;
	}

	/**
	 * Delete an object
	 *
	 * @param	string	$name	Object name
	 * @return	bool
	 */
	public function delete($name)
	{
		$path = $this->path($name);
		if (dol_is_file($path) && !dol_delete_file($path, 1, 1, 1, null, false, 0)) {
			$this->error = 'Cannot delete '.$path;
			return false;
		}
		// Remove the directory left empty (an archive, a data/xx of restic); fails silently if not empty
		dol_delete_dir(dirname($path), 1);
		return true;
	}

	/**
	 * List objects under a directory, recursively
	 *
	 * @param	string	$dir	Directory
	 * @return	array<string,int>|false
	 */
	public function listFiles($dir)
	{
		$path = $this->path($dir);
		$result = array();
		if (!dol_is_dir($path)) {
			return $result;
		}
		$prefix = rtrim($this->base.'/'.$this->fullName(''), '/').'/';
		foreach (dol_dir_list($path, 'files', 1, '', array('\.tmp[0-9]+$'), 'name', SORT_ASC, 1, 1) as $file) {
			$result[substr($file["fullname"], strlen($prefix))] = (int) $file['size'];
		}
		return $result;
	}
}
