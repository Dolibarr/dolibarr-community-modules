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
 * \file       cloudbackup/class/cloudbackuporphans.class.php
 * \ingroup    cloudbackup
 * \brief      Documents without object, file index lines without file, documents kept by a restore
 */

require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';


/**
 * Finds the orphans with the rules of the repair tool of the core (install/repair.php, clean_orphelin_dir
 * and clean_ecm_files_table), for the current entity
 */
class CloudBackupOrphans
{
	/** @var DoliDB */
	private $db;

	/**
	 * Directories of objects: the directory of the documents in $conf, the table, the column naming the
	 * directory, the element for getEntity() and the pattern giving that name from the relative path.
	 * The same modules as repair.php; the tiers and the supplier invoices are matched on their real layout.
	 */
	const OBJECTS = array(
		'company' => array('dir' => 'societe', 'table' => 'societe', 'field' => 'rowid', 'element' => 'societe', 'pattern' => '#^(\d+)/#', 'label' => 'ThirdParty'),
		'invoice' => array('dir' => 'facture', 'table' => 'facture', 'field' => 'ref', 'element' => 'invoice', 'pattern' => '#^([^/]+)/#', 'label' => 'Invoice'),
		'invoice_supplier' => array('dir' => 'fournisseur/facture', 'table' => 'facture_fourn', 'field' => 'ref', 'element' => 'facture_fourn', 'pattern' => '#^\d+/\d+/([^/]+)/#', 'label' => 'SupplierInvoice'),
		'propal' => array('dir' => 'propal', 'table' => 'propal', 'field' => 'ref', 'element' => 'propal', 'pattern' => '#^([^/]+)/#', 'label' => 'Proposal'),
		'order' => array('dir' => 'commande', 'table' => 'commande', 'field' => 'ref', 'element' => 'commande', 'pattern' => '#^([^/]+)/#', 'label' => 'Order'),
		'order_supplier' => array('dir' => 'fournisseur/commande', 'table' => 'commande_fournisseur', 'field' => 'ref', 'element' => 'commande_fournisseur', 'pattern' => '#^([^/]+)/#', 'label' => 'SupplierOrder'),
		'contract' => array('dir' => 'contrat', 'table' => 'contrat', 'field' => 'ref', 'element' => 'contract', 'pattern' => '#^([^/]+)/#', 'label' => 'Contract'),
		'tax' => array('dir' => 'tax', 'table' => 'chargesociales', 'field' => 'rowid', 'element' => 'tax', 'pattern' => '#^(\d+)/#', 'label' => 'SocialContribution'),
	);

	/** Files repair.php leaves out of the search */
	const EXCLUDED = array('^SPECIMEN\.pdf$', '^\.', '(\.meta|_preview.*\.png)$', '^temp$', '^payments$', '^CVS$', '^thumbs$');

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
	 * Directory of the documents of a kind of object, '' when the module does not use one
	 *
	 * @param	string	$dir	Path of the object in $conf, like fournisseur/facture
	 * @return	string
	 */
	private static function outputDir($dir)
	{
		global $conf;
		$node = $conf;
		foreach (explode('/', $dir) as $segment) {
			if (!is_object($node) || !isset($node->$segment)) {
				return '';
			}
			$node = $node->$segment;
		}
		return (is_object($node) && !empty($node->dir_output)) ? (string) $node->dir_output : '';
	}

	/**
	 * Names of the directories the objects of a kind have, as dol_sanitizeFileName() writes them
	 *
	 * @param	array<string,string>	$def	Entry of OBJECTS
	 * @return	?array<string,true>				Null on error
	 * @phan-suppress PhanPluginMoreSpecificActualReturnType  A table without row gives an empty array
	 */
	private function existingNames($def)
	{
		$names = array();
		$sql = "SELECT ".$this->db->sanitize($def['field'])." as name FROM ".$this->db->prefix().$this->db->sanitize($def['table']);
		$sql .= " WHERE entity IN (".getEntity($def['element']).")";
		$resql = $this->db->query($sql);
		if (!$resql) {
			return null;
		}
		while ($obj = $this->db->fetch_object($resql)) {
			$names[(string) $obj->name] = true;
			$names[dol_sanitizeFileName((string) $obj->name)] = true;
		}
		$this->db->free($resql);
		return $names;
	}

	/**
	 * Documents stored in the directory of an object that no longer exists
	 *
	 * @return	list<array{path:string,kind:string,object:string,size:int,date:int}>	Path relative to the documents
	 */
	public function orphanFiles()
	{
		$result = array();
		foreach (self::OBJECTS as $kind => $def) {
			$dir = self::outputDir($def['dir']);
			if ($dir === '' || !dol_is_dir($dir)) {
				continue;
			}
			$names = $this->existingNames($def);
			if ($names === null) {
				continue;
			}
			foreach (dol_dir_list($dir, 'files', 1, '', self::EXCLUDED, 'fullname', SORT_ASC, 1, 1, '', 1) as $file) {
				$relative = dol_substr($file['fullname'], dol_strlen($dir) + 1);
				if (!preg_match($def['pattern'], $relative, $reg) || isset($names[$reg[1]])) {
					continue;
				}
				$result[] = array(
					'path' => dol_substr($file['fullname'], dol_strlen(DOL_DATA_ROOT) + 1),
					'kind' => $kind,
					'object' => $reg[1],
					'size' => (int) $file['size'],
					'date' => (int) $file['date'],
				);
			}
		}
		return $result;
	}

	/**
	 * Lines of the file index whose file is not on the disk any more
	 *
	 * @return	list<array{id:int,path:string,date:int}>
	 */
	public function orphanIndexLines()
	{
		global $conf;
		$result = array();
		$sql = "SELECT rowid, filepath, filename, date_c FROM ".$this->db->prefix()."ecm_files WHERE entity = ".((int) $conf->entity)." ORDER BY filepath, filename";
		$resql = $this->db->query($sql);
		if (!$resql) {
			return $result;
		}
		while ($obj = $this->db->fetch_object($resql)) {
			$file = DOL_DATA_ROOT.'/'.$obj->filepath.'/'.$obj->filename;
			if (!dol_is_file($file) && !dol_is_file($file.'.noexe')) {
				$result[] = array('id' => (int) $obj->rowid, 'path' => $obj->filepath.'/'.$obj->filename, 'date' => (int) $this->db->jdate($obj->date_c));
			}
		}
		$this->db->free($resql);
		return $result;
	}

	/**
	 * Documents a restore kept although its backup did not have them, and that are still there
	 *
	 * @return	array{date:int,backup:string,files:list<array{path:string,size:int,date:int}>}
	 */
	public function keptFiles()
	{
		$result = array('date' => 0, 'backup' => '', 'files' => array());
		$json = dol_is_file(self::keptListFile()) ? json_decode((string) file_get_contents(self::keptListFile()), true) : null;
		if (!is_array($json) || !isset($json['files']) || !is_array($json['files'])) {
			return $result;
		}
		$result['date'] = (int) $json['date'];
		$result['backup'] = (string) $json['backup'];
		foreach ($json['files'] as $path) {
			$full = DOL_DATA_ROOT.'/'.$path;
			if (is_string($path) && strpos($path, '..') === false && dol_is_file($full)) {
				$result['files'][] = array('path' => $path, 'size' => (int) dol_filesize($full), 'date' => (int) dol_filemtime($full));
			}
		}
		return $result;
	}

	/**
	 * File where a restore lists the documents it kept, out of the backups
	 *
	 * @return	string
	 */
	public static function keptListFile()
	{
		return DOL_DATA_ROOT.'/cloudbackup/restore-kept.json';
	}

	/**
	 * Record the documents a restore kept
	 *
	 * @param	string			$backup		Backup restored
	 * @param	list<string>	$files		Paths relative to the documents
	 * @return	void
	 */
	public static function saveKeptFiles($backup, $files)
	{
		if (!count($files)) {
			dol_delete_file(self::keptListFile(), 1, 1, 1, null, false, 0);
			return;
		}
		dol_mkdir(dirname(self::keptListFile()), DOL_DATA_ROOT);
		file_put_contents(self::keptListFile(), json_encode(array('date' => dol_now(), 'backup' => $backup, 'files' => $files)));
		dolChmod(self::keptListFile());
	}
}
