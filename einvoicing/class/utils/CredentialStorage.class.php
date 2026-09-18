<?php
/* Copyright (C) 2026 Pierre Grasswill
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
 * \file    einvoicing/class/utils/CredentialStorage.class.php
 * \ingroup einvoicing
 * \brief   Tell which credentials of an access point are stored in clear, and encrypt them in place.
 */

// @phpstan-ignore requireOnce.fileNotFound (The core indexed by PHPStan carries install/inc.php, which defines DOL_DOCUMENT_ROOT as '..', so the path cannot resolve here. The same require is baselined for every other file of the module.)
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';


/**
 * The credentials of an access point, as they sit in the database.
 *
 * dolibarr_set_const() decides whether a constant is encrypted on the END of its name, and the
 * environment marker of the production credentials hides the keyword it matches, so values written
 * by an earlier version of the module are in clear (issue #1013). Reading is name-agnostic, so they
 * keep working: encrypting them is an explicit action of the administrator, never a migration.
 */
class CredentialStorage
{
	/** Value held by a constant of llx_const. */
	const KIND_CONST = 'const';

	/** Value held by a column of llx_oauth_token, where Dolibarr 23 and above keep the token. */
	const KIND_TOKEN = 'token';

	/** @var DoliDB Database handler */
	public $db;

	/** @var string[] Errors met by the last call */
	public $errors = array();

	/** @var string[] Labels the last call left in clear, because this core would not read them back */
	public $skipped = array();

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
	 * Tell whether this instance can encrypt at all.
	 *
	 * dolEncrypt() returns the value unchanged when conf.php carries no instance key, and so does the
	 * core with its own constants: there is nothing to offer on such an instance.
	 *
	 * @return	bool	True when dolEncrypt() really encrypts
	 */
	public function canEncrypt()
	{
		global $conf;

		return !empty($conf->file->instance_unique_id) && function_exists('openssl_encrypt');
	}

	/**
	 * Where the credentials of a provider live, and what state each one is in.
	 *
	 * @param	AbstractPDPProvider		$provider	Provider in use
	 * @param	int						$entity		Entity holding the setup
	 * @return	array<int,array{kind:string,name:string,column:string,label:string,istoken:bool,raw:string,clear:string,encrypted:bool}>	One entry per credential that holds something
	 */
	public function inventory($provider, $entity)
	{
		$config = $provider->getConf();
		$prefix = empty($config['dol_prefix']) ? '' : $config['dol_prefix'];
		if ($prefix === '') {
			return array();
		}
		$env = empty($config['live']) ? '' : '_PROD';
		$service = $prefix.'_'.(empty($config['live']) ? 'TEST' : 'PROD');

		$wanted = array();
		foreach (array('CLIENT_SECRET', 'PASSWORD', 'API_KEY') as $credential) {
			$wanted[] = array('kind' => self::KIND_CONST, 'name' => $prefix.'_'.$credential.$env, 'column' => '', 'label' => $credential, 'istoken' => false);
		}
		if (version_compare(DOL_VERSION, '23.0.0', '<')) {
			$wanted[] = array('kind' => self::KIND_CONST, 'name' => $service.'_TOKEN', 'column' => '', 'label' => 'TOKEN', 'istoken' => true);
			$wanted[] = array('kind' => self::KIND_CONST, 'name' => $service.'_REFRESH', 'column' => '', 'label' => 'REFRESH', 'istoken' => true);
		} else {
			$wanted[] = array('kind' => self::KIND_TOKEN, 'name' => $service, 'column' => 'tokenstring', 'label' => 'TOKEN', 'istoken' => true);
			$wanted[] = array('kind' => self::KIND_TOKEN, 'name' => $service, 'column' => 'tokenstring_refresh', 'label' => 'REFRESH', 'istoken' => true);
		}

		$inventory = array();
		foreach ($wanted as $item) {
			$item['raw'] = $this->readRaw($item, $entity);
			if ($item['raw'] === '') {
				continue;
			}
			$item['encrypted'] = (strpos($item['raw'], 'dolcrypt:') === 0);
			$item['clear'] = $item['encrypted'] ? dolDecrypt($item['raw']) : $item['raw'];
			$inventory[] = $item;
		}

		return $inventory;
	}

	/**
	 * The entries of an inventory that are stored in clear.
	 *
	 * @param	array<int,array{kind:string,name:string,column:string,label:string,istoken:bool,raw:string,clear:string,encrypted:bool}>	$inventory	Inventory
	 * @param	?bool	$istoken	True for the token entries only, false for the others, null for all
	 * @return	array<int,array{kind:string,name:string,column:string,label:string,istoken:bool,raw:string,clear:string,encrypted:bool}>	Entries in clear
	 */
	public function inClear($inventory, $istoken = null)
	{
		$selected = array();
		foreach ($inventory as $item) {
			if (!empty($item['encrypted'])) {
				continue;
			}
			if ($istoken !== null && $item['istoken'] != $istoken) {
				continue;
			}
			$selected[] = $item;
		}

		return $selected;
	}

	/**
	 * Encrypt values stored in clear, checking each one reads back as it was.
	 *
	 * A value this core would not give back is left in clear and named in $skipped. A value that does
	 * not read back once written puts the whole set back to the byte it held before.
	 *
	 * @param	array<int,array{kind:string,name:string,column:string,label:string,istoken:bool,raw:string,clear:string,encrypted:bool}>	$items	Entries to encrypt, as inClear() returns them
	 * @param	int		$entity		Entity holding the setup
	 * @return	int					Number of values encrypted, -1 when nothing was kept
	 */
	public function encryptInPlace($items, $entity)
	{
		$done = array();
		$this->skipped = array();

		foreach ($items as $item) {
			$encrypted = dolEncrypt($item['clear']);
			if ($encrypted === $item['clear'] || strpos($encrypted, 'dolcrypt:') !== 0) {
				// No instance key, or a value that already looks encrypted.
				$this->skipped[] = $item['label'];
				continue;
			}

			// Dolibarr 23 refuses to give back a decrypted value that is not plain ASCII and hands the
			// encrypted string over instead (fixed in 24). Such a value stays in clear: readable beats
			// unusable, and the caller says which ones.
			if (dolDecrypt($encrypted) !== $item['clear']) {
				$this->skipped[] = $item['label'];
				continue;
			}

			if (!$this->writeRaw($item, $encrypted, $entity)) {
				$this->restore($done, $entity);
				return -1;
			}
			$done[] = $item;

			if ($this->readBack($item, $entity) !== $item['clear']) {
				$this->errors[] = 'EInvoicingCredentialReadBackMismatch:'.$item['label'];
				$this->restore($done, $entity);
				return -1;
			}
		}

		return count($done);
	}

	/**
	 * Put back the value each entry held before it was encrypted.
	 *
	 * @param	array<int,array{kind:string,name:string,column:string,label:string,istoken:bool,raw:string,clear:string,encrypted:bool}>	$items	Entries to restore
	 * @param	int		$entity		Entity holding the setup
	 * @return	bool				True when every entry was put back
	 */
	public function restore($items, $entity)
	{
		$ok = true;
		foreach ($items as $item) {
			if (!$this->writeRaw($item, $item['raw'], $entity)) {
				$this->errors[] = 'EInvoicingCredentialRestoreFailed:'.$item['label'];
				$ok = false;
			}
		}

		return $ok;
	}

	/**
	 * Value of an entry as it sits in the table, without the decryption the readers apply.
	 *
	 * @param	array{kind:string,name:string,column:string}	$item	Entry
	 * @param	int		$entity		Entity holding the setup
	 * @return	string				Raw value, '' when the row does not exist
	 */
	public function readRaw($item, $entity)
	{
		if ($item['kind'] === self::KIND_TOKEN) {
			$sql = "SELECT ".$this->db->sanitize($item['column'])." as value FROM ".MAIN_DB_PREFIX."oauth_token";
			$sql .= " WHERE service = '".$this->db->escape($item['name'])."'";
		} else {
			$sql = "SELECT ".$this->db->decrypt('value')." as value FROM ".MAIN_DB_PREFIX."const";
			$sql .= " WHERE name = ".$this->db->encrypt($item['name']);
		}
		$sql .= " AND entity = ".((int) $entity);

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->errors[] = $this->db->lasterror();
			return '';
		}
		$obj = $this->db->fetch_object($resql);

		return ($obj && $obj->value !== null) ? (string) $obj->value : '';
	}

	/**
	 * Value of an entry as its readers see it, decryption included.
	 *
	 * @param	array{kind:string,name:string,column:string}	$item	Entry
	 * @param	int		$entity		Entity holding the setup
	 * @return	string				Value the module would use
	 */
	public function readBack($item, $entity)
	{
		if ($item['kind'] === self::KIND_TOKEN) {
			return (string) dolDecrypt($this->readRaw($item, $entity));
		}

		return (string) dolibarr_get_const($this->db, $item['name'], $entity);
	}

	/**
	 * Write a value byte for byte, without the encryption dolibarr_set_const() may add.
	 *
	 * @param	array{kind:string,name:string,column:string}	$item	Entry
	 * @param	string	$value		Value to store as it is
	 * @param	int		$entity		Entity holding the setup
	 * @return	bool				True on success
	 */
	private function writeRaw($item, $value, $entity)
	{
		if ($item['kind'] === self::KIND_TOKEN) {
			$sql = "UPDATE ".MAIN_DB_PREFIX."oauth_token SET ".$this->db->sanitize($item['column'])." = '".$this->db->escape($value)."'";
			$sql .= " WHERE service = '".$this->db->escape($item['name'])."'";
		} else {
			$sql = "UPDATE ".MAIN_DB_PREFIX."const SET value = ".$this->db->encrypt($value);
			$sql .= " WHERE name = ".$this->db->encrypt($item['name']);
		}
		$sql .= " AND entity = ".((int) $entity);

		if (!$this->db->query($sql)) {
			$this->errors[] = $this->db->lasterror();
			return false;
		}

		return true;
	}
}
