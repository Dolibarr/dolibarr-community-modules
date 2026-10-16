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
 * \file       cloudbackup/class/cloudbackupsqlimport.class.php
 * \ingroup    cloudbackup
 * \brief      Replay a SQL dump through the database handler of Dolibarr, without the mysql command
 */


/**
 * Replays a dump made by the backup tool of Dolibarr (php or mysqldump flavour), plain or gzipped.
 * The core has no importer: its restore page only prints the mysql command to type.
 */
class CloudBackupSqlImport
{
	/** @var DoliDB */
	private $db;

	/** @var string */
	public $error = '';

	/** @var int Statements executed */
	public $count = 0;

	/** @var string[] Tables the replay leaves as they are */
	public $keepTables = array();

	/** @var array<string,string> Collations of the dump this server does not have, and the one used instead */
	public $collations = array();

	/** Name of the table used to try the tables of the dump */
	const TRIAL_TABLE = 'cloudbackup_trial_table';

	/**
	 * Constructor
	 *
	 * @param	DoliDB	$db		Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Tell whether a dump file is complete: the backup tool ends every dump with a completion line
	 *
	 * @param	string	$file	Dump file, .sql or .sql.gz
	 * @return	bool
	 */
	public static function isComplete($file)
	{
		$handle = self::open($file);
		if (!$handle) {
			return false;
		}
		$tail = '';
		$gz = self::isGzip($file);
		while (!($gz ? gzeof($handle) : feof($handle))) {
			$chunk = $gz ? gzread($handle, 1048576) : fread($handle, 1048576);
			if ($chunk === false || $chunk === '') {
				break;
			}
			$tail = substr($tail.$chunk, -4096);
		}
		$gz ? gzclose($handle) : fclose($handle);
		return strpos($tail, '-- Dump completed') !== false;
	}

	/**
	 * Tell whether a file is gzipped
	 *
	 * @param	string	$file	File
	 * @return	bool
	 */
	private static function isGzip($file)
	{
		$handle = @fopen($file, 'rb');
		if (!$handle) {
			return false;
		}
		$magic = fread($handle, 2);
		fclose($handle);
		return $magic === "\x1f\x8b";
	}

	/**
	 * Open a dump, gzipped or not
	 *
	 * @param	string	$file	File
	 * @return	resource|false
	 */
	private static function open($file)
	{
		return self::isGzip($file) ? @gzopen($file, 'rb') : @fopen($file, 'rb');
	}

	/**
	 * Execute all the statements of a dump
	 *
	 * @param	string	$file	Dump file
	 * @return	int				Number of statements executed, < 0 on error ($this->error set)
	 */
	public function run($file)
	{
		$gz = self::isGzip($file);
		if ($this->prepare($file, $gz) < 0) {
			return -1;
		}
		$handle = self::open($file);
		if (!$handle) {
			$this->error = 'Cannot read '.$file;
			return -1;
		}
		$this->count = 0;
		$buffer = '';
		$lineNumber = 0;
		$error = 0;
		while (($line = ($gz ? gzgets($handle) : fgets($handle))) !== false) {
			$lineNumber++;
			if ($buffer === '') {
				$trimmed = ltrim($line);
				if ($trimmed === '' || strpos($trimmed, '--') === 0) {
					continue;
				}
				// Fast path: the dumps write one INSERT per line and never a raw new line inside a value
				if (strncmp($line, 'INSERT ', 7) === 0 && substr(rtrim($line, "\r\n"), -2) === ');') {
					if (!$this->execute(rtrim($line, "\r\n;"), $lineNumber)) {
						$error++;
						break;
					}
					continue;
				}
			}
			$buffer .= $line;
			if (substr(rtrim($buffer), -1) !== ';' || self::insideQuote($buffer)) {
				continue;
			}
			$statement = rtrim(rtrim($buffer), ';');
			$buffer = '';
			if (preg_match('/^\s*CREATE TABLE\s+`?([A-Za-z0-9_]+)`?/i', $statement, $m)) {
				$statement = $this->translate($statement);
				// Old dumps have no DROP before CREATE: make the restore replace the table in any case
				if (!$this->execute('DROP TABLE IF EXISTS `'.$m[1].'`', $lineNumber)) {
					$error++;
					break;
				}
			}
			if (!$this->execute($statement, $lineNumber)) {
				$error++;
				break;
			}
		}
		$gz ? gzclose($handle) : fclose($handle);
		if (!$error && trim($buffer) !== '' && !self::isOnlyComments($buffer)) {
			$this->error = 'The dump ends in the middle of a statement';
			return -1;
		}
		return $error ? -1 : $this->count;
	}

	/**
	 * Before touching the database: try every table of the dump under a scratch name, so a dump this server
	 * cannot replay (a collation or a syntax of another server) stops with the database intact. Then drop
	 * every table the dump creates: dropped one by one during the replay, a table whose foreign keys point to
	 * a table still in its old form fails with errno 150, even with the foreign key checks off.
	 *
	 * @param	string	$file	Dump
	 * @param	bool	$gz		Gzipped
	 * @return	int				>0 if OK, <0 on error
	 */
	private function prepare($file, $gz)
	{
		$handle = self::open($file);
		if (!$handle) {
			$this->error = 'Cannot read '.$file;
			return -1;
		}
		$creates = array();
		$buffer = '';
		while (($line = ($gz ? gzgets($handle) : fgets($handle))) !== false) {
			if ($buffer === '' && strncmp($line, 'CREATE TABLE', 12) !== 0) {
				continue;
			}
			$buffer .= $line;
			if (substr(rtrim($buffer), -1) === ';' && !self::insideQuote($buffer)) {
				$creates[] = rtrim(rtrim($buffer), ';');
				$buffer = '';
			}
		}
		$gz ? gzclose($handle) : fclose($handle);
		if (!$this->mapCollations($creates)) {
			return -1;
		}
		$this->db->query('SET FOREIGN_KEY_CHECKS=0', 0, 'ddl');
		$tables = array();
		foreach ($creates as $sql) {
			if (!preg_match('/^CREATE TABLE\s+`?([A-Za-z0-9_]+)`?/', $sql, $m) || in_array($m[1], $this->keepTables)) {
				continue;
			}
			$tables[] = $m[1];
			// Foreign key names are unique in a database: the trial table goes without them
			$lines = preg_grep('/^\s*CONSTRAINT\s+`[^`]*`\s+FOREIGN KEY/i', explode("\n", $this->translate($sql)), PREG_GREP_INVERT);
			$trial = preg_replace(array('/^CREATE TABLE\s+`?[A-Za-z0-9_]+`?/', '/,(\s*\n\))/'), array('CREATE TABLE `'.self::TRIAL_TABLE.'`', '$1'), implode("\n", $lines));
			$this->db->query('DROP TABLE IF EXISTS `'.self::TRIAL_TABLE.'`', 0, 'ddl');
			$resql = $this->db->query((string) $trial, 0, 'ddl');
			$error = $resql ? '' : $this->db->lasterror();
			$this->db->query('DROP TABLE IF EXISTS `'.self::TRIAL_TABLE.'`', 0, 'ddl');
			if (!$resql) {
				$this->error = 'This server cannot create the table '.$m[1].' of the dump, the database was not touched: '.$error;
				return -1;
			}
		}
		foreach ($tables as $table) {
			if (!$this->execute('DROP TABLE IF EXISTS `'.$table.'`', 0)) {
				return -1;
			}
		}
		return 1;
	}

	/**
	 * Find the collations of the dump this server does not have (utf8mb4_uca1400_ai_ci of MariaDB 11 on
	 * MySQL, utf8mb4_0900_ai_ci of MySQL 8 on MariaDB 10) and use instead the default one of their charset
	 *
	 * @param	string[]	$creates	CREATE TABLE statements of the dump
	 * @return	bool					False if a charset is unknown too ($this->error set)
	 */
	private function mapCollations($creates)
	{
		$known = array();
		$default = array();
		$resql = $this->db->query('SHOW COLLATION');
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$known[strtolower($obj->Collation)] = true;
		}
		$resql = $this->db->query('SHOW CHARACTER SET');
		while ($resql && ($obj = $this->db->fetch_object($resql))) {
			$default[strtolower($obj->Charset)] = $obj->{'Default collation'};
		}
		if (empty($known)) {
			return true;
		}
		foreach ($creates as $sql) {
			preg_match_all('/\bCOLLATE\s*=?\s*`?([A-Za-z0-9_]+)/i', $sql, $matches);
			foreach ($matches[1] as $collation) {
				$collation = strtolower($collation);
				if (isset($known[$collation]) || isset($this->collations[$collation])) {
					continue;
				}
				$charset = explode('_', $collation)[0];
				if (!isset($default[$charset])) {
					$this->error = 'This server knows neither the collation '.$collation.' of the dump nor its character set, the database was not touched';
					return false;
				}
				$this->collations[$collation] = $default[$charset];
			}
		}
		return true;
	}

	/**
	 * Replace in a CREATE TABLE the collations this server does not have
	 *
	 * @param	string	$sql	Statement
	 * @return	string
	 */
	private function translate($sql)
	{
		if (empty($this->collations)) {
			return $sql;
		}
		$map = $this->collations;
		return (string) preg_replace_callback('/\b(COLLATE\s*=?\s*)`?([A-Za-z0-9_]+)`?/i', function ($m) use ($map) {
			$collation = strtolower($m[2]);
			return $m[1].(isset($map[$collation]) ? $map[$collation] : $m[2]);
		}, $sql);
	}

	/**
	 * Execute one statement
	 *
	 * @param	string	$sql			Statement
	 * @param	int		$lineNumber		Line of the dump, for the message
	 * @return	bool
	 */
	private function execute($sql, $lineNumber)
	{
		// LOCK TABLES would lock this connection out of the other tables until UNLOCK
		if (preg_match('/^\s*(UN)?LOCK TABLES/i', $sql)) {
			return true;
		}
		foreach ($this->keepTables as $table) {
			if (preg_match('/^\s*(\/\*!\d+\s*)?(DROP TABLE( IF EXISTS)?|CREATE TABLE|INSERT( DELAYED| IGNORE)* INTO|ALTER TABLE)\s+`?'.preg_quote($table, '/').'`?([\s(]|$)/i', $sql)) {
				return true;
			}
		}
		$resql = $this->db->query($sql, 0, 'ddl');
		if (!$resql) {
			$this->error = 'Line '.$lineNumber.': '.$this->db->lasterror().' - '.dol_trunc($sql, 200);
			return false;
		}
		$this->count++;
		return true;
	}

	/**
	 * Tell whether a SQL text ends inside a quoted string or a comment
	 *
	 * @param	string	$sql	SQL
	 * @return	bool
	 */
	public static function insideQuote($sql)
	{
		// Remove the complete strings, identifiers and comments, then look for a leftover opener
		$stripped = preg_replace(array(
			"/'(?:[^'\\\\]|\\\\.|'')*'/s",
			'/"(?:[^"\\\\]|\\\\.|"")*"/s',
			'/`[^`]*`/s',
			'#/\*.*?\*/#s',
			'/^\s*--[^\n]*$/m',
		), '', $sql);
		return (bool) preg_match("/['\"`]|\/\*/", (string) $stripped);
	}

	/**
	 * Tell whether a SQL text is only comments and blanks
	 *
	 * @param	string	$sql	SQL
	 * @return	bool
	 */
	private static function isOnlyComments($sql)
	{
		return trim((string) preg_replace(array('#/\*.*?\*/#s', '/^\s*--[^\n]*$/m'), '', $sql)) === '';
	}
}
