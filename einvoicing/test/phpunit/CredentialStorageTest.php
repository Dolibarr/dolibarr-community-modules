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
 * or see https://www.gnu.org/
 */

/**
 *      \file       test/phpunit/CredentialStorageTest.php
 *      \ingroup    test
 *      \brief      PHPUnit test for CredentialStorage: encrypting in place a credential stored in
 *                  clear must read back exactly what was there, whatever the value holds, and put
 *                  everything back untouched as soon as one of them does not.
 *      \remarks    To run this script as CLI: phpunit filename.php
 */

global $conf, $user, $langs, $db;

// See RecipientDirectoryTest.php for why DOLIBARR_HTDOCS is honoured before the relative path.
$dolibarrHtdocs = getenv('DOLIBARR_HTDOCS');
if (!$dolibarrHtdocs) {
	$dolibarrHtdocs = dirname(__FILE__) . '/../../htdocs';
}
if (!file_exists($dolibarrHtdocs . '/master.inc.php')) {
	throw new \RuntimeException('Could not locate master.inc.php under "' . $dolibarrHtdocs . '/". Set the environment variable (export DOLIBARR_HTDOCS=...) to the htdocs directory of the Dolibarr instance to test against.');
}

require_once $dolibarrHtdocs . '/master.inc.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
dol_include_once('einvoicing/class/utils/CredentialStorage.class.php');
require_once __DIR__ . '/CommonClassTestCompat.inc.php';

if (empty($user->id)) {
	print "Load permissions for admin user nb 1\n";
	$user->fetch(1);
	// User::loadRights() only exists from Dolibarr 19 on, older versions name it getrights()
	if (method_exists($user, 'loadRights')) {
		$user->loadRights();
	} else {
		$user->getrights();
	}
}
$conf->global->MAIN_DISABLE_ALL_MAILS = 1;


/**
 * A storage whose read-back is broken on demand, to exercise the restore path.
 */
class CredentialStorageWithBrokenReadBack extends CredentialStorage
{
	/** @var string Name whose read-back returns something else */
	public $breakon = '';

	/**
	 * Read back a value, or something else when the entry is the one to break on.
	 *
	 * @param	array{kind:string,name:string,column:string}	$item	Entry
	 * @param	int		$entity		Entity holding the setup
	 * @return	string				Value the module would use
	 */
	public function readBack($item, $entity)
	{
		if ($item['name'] === $this->breakon) {
			return 'not what was written';
		}

		return parent::readBack($item, $entity);
	}
}


/**
 * Tests on encrypting in place the credentials stored in clear.
 *
 * Everything written here happens inside the transaction CommonClassTest opens for the class and
 * rolls back afterwards, so a run leaves neither constant nor token row behind.
 *
 * @backupGlobals disabled
 */
class CredentialStorageTest extends CommonClassTest
{
	/** @var string Constant used as a credential in clear, under a name no installation carries */
	const CONST_NAME = 'EINVOICING_TESTPDP_CLIENT_SECRET_PROD';

	/** @var string A second one, to check a failure puts back the whole set and not only the last */
	const OTHER_NAME = 'EINVOICING_TESTPDP_API_KEY_PROD';

	/**
	 * Values a credential field can hold. One test each, on top of the cases below.
	 *
	 * @return array<string,array{0:string}> Value per case name
	 */
	public function credentialValues()
	{
		$values = array(
			'plain' => 'sk_live_0123456789',
			'single quote' => "it's a secret",
			'double quote' => 'say "secret"',
			'backslash' => 'back\\slash\\end',
			'backslash before quote' => "trailing\\'",
			'percent' => '100%secret%s%d',
			'ampersand' => 'a&b&amp;c',
			'lower than' => 'a<b>c</b>',
			'script' => '<script>alert(1)</script>',
			'sql' => "'; DROP TABLE llx_const; --",
			'sql comment' => '/* comment */ SELECT 1',
			'newline' => "line1\nline2",
			'carriage return' => "line1\r\nline2",
			'tab' => "a\tb",
			'form feed' => "a\x0Cb",
			'leading space' => '   leading',
			'trailing space' => 'trailing   ',
			'only spaces' => '   ',
			'one char' => 'x',
			'digits' => '0123456789',
			'zero' => '0',
			'zero string' => '000',
			'false like' => 'false',
			'null like' => 'null',
			'colon' => 'a:b:c',
			'colons many' => '::::::',
			'dolcrypt word' => 'dolcrypt',
			'dolcrypt prefix like' => 'dolcryptic value',
			'base64' => 'YWJjZGVmZ2hpamtsbW5vcHFyc3R1dnd4eXo=',
			'json' => '{"client_secret":"abc","scope":"read write"}',
			'xml' => '<secret value="abc"/>',
			'url' => 'https://user:pass@example.com/path?query=1#fragment',
			'jwt' => 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.dBjftJeZ4CVPmB92K27uhbUJU1p1r_wW1gFWFOEjXk',
			'uuid' => '01a03e72-54c0-7f23-884a-0c097276d766',
			'accents' => 'clé secrète à protéger',
			'cedilla' => 'ça, c\'est le secret',
			'german' => 'schlüssel groß',
			'greek' => 'μυστικό κλειδί',
			'cyrillic' => 'секретный ключ',
			'hebrew' => 'מפתח סודי',
			'arabic' => 'مفتاح سري',
			'chinese' => '秘密の鍵と秘钥',
			'japanese' => 'ひみつのかぎ',
			'emoji' => 'secret 🔐🙈 ok',
			'combining' => "e\xCC\x81 accent combining",
			'zero width' => "a\xE2\x80\x8Bb",
			'non breaking space' => "a\xC2\xA0b",
			'utf8 four bytes' => "\xF0\x9F\x94\x91 key",
			'quote and newline' => "it's\nover",
			'html entity' => '&lt;secret&gt;',
			'equals' => 'key=value=other',
			'hash' => '#secret#',
			'dollar' => '$secret$var',
			'brace' => '${secret}',
			'pipe' => 'a|b|c',
			'semicolon' => 'a;b;c',
			'comma' => 'a,b,c',
			'long 512' => str_repeat('abcdefgh', 64),
			'long 4000' => str_repeat('x', 4000),
			'repeated quote' => str_repeat("'", 50),
			'mixed hostile' => "'\"\\%&<>\n\t ç€🔐",
		);

		$cases = array();
		foreach ($values as $name => $value) {
			$cases[$name] = array($value);
		}

		return $cases;
	}

	/**
	 * Tell whether this instance can encrypt at all.
	 *
	 * @return bool True when dolEncrypt() really encrypts
	 */
	private function instanceHasAnEncryptionKey()
	{
		global $conf;

		return !empty($conf->file->instance_unique_id);
	}

	/**
	 * An inventory entry standing for a constant, without going through a provider.
	 *
	 * @param	string	$name	Name of the constant
	 * @return	array{kind:string,name:string,column:string,label:string,istoken:bool,raw:string,clear:string,encrypted:bool}	Entry
	 */
	private function constEntry($name)
	{
		global $conf, $db;

		$storage = new CredentialStorage($db);
		$item = array('kind' => CredentialStorage::KIND_CONST, 'name' => $name, 'column' => '', 'label' => $name, 'istoken' => false);
		$item['raw'] = $storage->readRaw($item, (int) $conf->entity);
		$item['encrypted'] = (strpos($item['raw'], 'dolcrypt:') === 0);
		$item['clear'] = $item['encrypted'] ? dolDecrypt($item['raw']) : $item['raw'];

		return $item;
	}

	/**
	 * Store a value in clear under a name, whatever the core would do with that name.
	 *
	 * @param	string	$name	Name of the constant
	 * @param	string	$value	Value to store as it is
	 * @return	void
	 */
	private function plantInClear($name, $value)
	{
		global $conf, $db;

		dolibarr_set_const($db, $name, 'placeholder', 'chaine', 0, '', $conf->entity);

		$sql = "UPDATE " . MAIN_DB_PREFIX . "const SET value = " . $db->encrypt($value);
		$sql .= " WHERE name = " . $db->encrypt($name) . " AND entity = " . ((int) $conf->entity);
		if (!$db->query($sql)) {
			// A four byte character needs a utf8mb4 column, which llx_const does not always have. No
			// credential can hold such a value on this instance, so there is nothing to test here.
			$this->markTestSkipped('The database refuses that value in llx_const.value: ' . $db->lasterror());
		}
	}

	/**
	 * Any value stored in clear is encrypted, reads back identical, and restores byte for byte.
	 *
	 * @param	string	$value	Value the credential holds
	 * @return	void
	 *
	 * @dataProvider credentialValues
	 */
	public function testAValueInClearIsEncryptedAndReadsBackIdentical($value)
	{
		global $conf, $db;

		if (!$this->instanceHasAnEncryptionKey()) {
			$this->markTestSkipped('conf.php carries no instance key, so nothing can be encrypted on this instance');
		}

		$this->plantInClear(self::CONST_NAME, $value);

		$storage = new CredentialStorage($db);
		$item = $this->constEntry(self::CONST_NAME);

		$this->assertFalse($item['encrypted'], 'The planted value should be in clear');
		$this->assertSame($value, $item['clear']);

		if (dolDecrypt(dolEncrypt($value)) !== $value) {
			// Dolibarr 23 hands back the encrypted string instead of a decrypted value that is not plain
			// ASCII. Such a value has to stay in clear, and be named as left behind.
			$this->assertSame(0, $storage->encryptInPlace(array($item), (int) $conf->entity));
			$this->assertSame(array($item['label']), $storage->skipped);
			$this->assertSame($item['raw'], $this->constEntry(self::CONST_NAME)['raw']);
			$this->assertSame($value, dolibarr_get_const($db, self::CONST_NAME, (int) $conf->entity));

			return;
		}

		$this->assertSame(1, $storage->encryptInPlace(array($item), (int) $conf->entity));
		$this->assertSame(array(), $storage->skipped);

		$after = $this->constEntry(self::CONST_NAME);
		$this->assertTrue($after['encrypted'], 'The value was not encrypted');
		$this->assertSame(1, substr_count($after['raw'], 'dolcrypt:'), 'The value was encrypted more than once');
		$this->assertSame($value, $storage->readBack($item, (int) $conf->entity), 'The value does not read back as it was');

		// And the way back: what restore() puts there is the byte for byte value of before.
		$this->assertTrue($storage->restore(array($item), (int) $conf->entity));
		$this->assertSame($item['raw'], $this->constEntry(self::CONST_NAME)['raw']);
	}

	/**
	 * A read-back that does not match puts every value of the set back as it was.
	 *
	 * @return void
	 */
	public function testAReadBackMismatchRestoresTheWholeSet()
	{
		global $conf, $db;

		if (!$this->instanceHasAnEncryptionKey()) {
			$this->markTestSkipped('conf.php carries no instance key, so nothing can be encrypted on this instance');
		}

		$this->plantInClear(self::CONST_NAME, 'first-secret-in-clear');
		$this->plantInClear(self::OTHER_NAME, 'second-secret-in-clear');

		$first = $this->constEntry(self::CONST_NAME);
		$second = $this->constEntry(self::OTHER_NAME);

		$storage = new CredentialStorageWithBrokenReadBack($db);
		$storage->breakon = self::OTHER_NAME;

		$this->assertSame(-1, $storage->encryptInPlace(array($first, $second), (int) $conf->entity));

		// The one that was already encrypted when the second failed is back in clear, as it was.
		$this->assertSame($first['raw'], $this->constEntry(self::CONST_NAME)['raw']);
		$this->assertSame($second['raw'], $this->constEntry(self::OTHER_NAME)['raw']);
		$this->assertSame('first-secret-in-clear', dolibarr_get_const($db, self::CONST_NAME, (int) $conf->entity));
		$this->assertSame('second-secret-in-clear', dolibarr_get_const($db, self::OTHER_NAME, (int) $conf->entity));
	}

	/**
	 * A value already encrypted is not touched, and running the action again finds nothing to do.
	 *
	 * @return void
	 */
	public function testAValueAlreadyEncryptedIsLeftAlone()
	{
		global $conf, $db;

		if (!$this->instanceHasAnEncryptionKey()) {
			$this->markTestSkipped('conf.php carries no instance key, so nothing can be encrypted on this instance');
		}

		$this->plantInClear(self::CONST_NAME, 'secret-to-encrypt-once');

		$storage = new CredentialStorage($db);
		$this->assertSame(1, $storage->encryptInPlace(array($this->constEntry(self::CONST_NAME)), (int) $conf->entity));

		$once = $this->constEntry(self::CONST_NAME);
		$this->assertTrue($once['encrypted']);

		// inClear() no longer offers it, and encrypting it again would be a second layer.
		$this->assertSame(array(), $storage->inClear(array($once)));
		$this->assertSame(0, $storage->encryptInPlace($storage->inClear(array($once)), (int) $conf->entity));
		$this->assertSame($once['raw'], $this->constEntry(self::CONST_NAME)['raw']);
		$this->assertSame('secret-to-encrypt-once', dolibarr_get_const($db, self::CONST_NAME, (int) $conf->entity));
	}

	/**
	 * A value that already carries the prefix of an encrypted one is reported as encrypted, not rewritten.
	 *
	 * Reading applies dolDecrypt() to every constant, so such a value is already read as the module
	 * will read it: encrypting it again would store something this instance could not get back.
	 *
	 * @return void
	 */
	public function testAValueLookingEncryptedIsNotEncryptedAgain()
	{
		global $conf, $db;

		$planted = 'dolcrypt:AES-256-CTR:0123456789abcdef:not-really-encrypted';
		$this->plantInClear(self::CONST_NAME, $planted);

		$storage = new CredentialStorage($db);
		$item = $this->constEntry(self::CONST_NAME);

		$this->assertTrue($item['encrypted']);
		$this->assertSame(array(), $storage->inClear(array($item)));
		$this->assertSame($planted, $this->constEntry(self::CONST_NAME)['raw']);
	}

	/**
	 * An instance with no key in conf.php is offered nothing, and nothing is written.
	 *
	 * @return void
	 */
	public function testAnInstanceWithoutKeyEncryptsNothing()
	{
		global $conf, $db;

		$this->plantInClear(self::CONST_NAME, 'secret-that-stays-in-clear');
		$before = $this->constEntry(self::CONST_NAME);

		$storage = new CredentialStorage($db);
		$savedkey = $conf->file->instance_unique_id;
		$conf->file->instance_unique_id = '';

		try {
			$this->assertFalse($storage->canEncrypt());
			$this->assertSame(0, $storage->encryptInPlace(array($before), (int) $conf->entity));
			$this->assertSame($before['raw'], $this->constEntry(self::CONST_NAME)['raw']);
		} finally {
			$conf->file->instance_unique_id = $savedkey;
		}
	}

	/**
	 * An empty credential is not part of the inventory: there is nothing to encrypt.
	 *
	 * @return void
	 */
	public function testAnEmptyCredentialIsNotOffered()
	{
		global $conf, $db;

		$this->plantInClear(self::CONST_NAME, '');

		$storage = new CredentialStorage($db);
		$item = $this->constEntry(self::CONST_NAME);

		$this->assertSame('', $item['raw']);
		$this->assertSame(array(), $storage->inClear(array()));
		$this->assertSame(0, $storage->encryptInPlace(array(), (int) $conf->entity));
	}

	/**
	 * The token of the access point is inventoried where this core version keeps it, and encrypted there.
	 *
	 * @return void
	 */
	public function testTheTokenIsEncryptedWhereItIsStored()
	{
		global $conf, $db;

		if (!$this->instanceHasAnEncryptionKey()) {
			$this->markTestSkipped('conf.php carries no instance key, so nothing can be encrypted on this instance');
		}

		$service = 'EINVOICING_TESTPDP_' . (getDolGlobalInt('EINVOICING_LIVE') ? 'PROD' : 'TEST');
		$storage = new CredentialStorage($db);

		if (version_compare(DOL_VERSION, '23.0.0', '<')) {
			$this->plantInClear($service . '_TOKEN', 'access-token-in-clear');
			$item = $this->constEntry($service . '_TOKEN');
		} else {
			$sql = "DELETE FROM " . MAIN_DB_PREFIX . "oauth_token WHERE service = '" . $db->escape($service) . "'";
			$sql .= " AND entity = " . ((int) $conf->entity);
			$this->assertNotFalse($db->query($sql));

			$sql = "INSERT INTO " . MAIN_DB_PREFIX . "oauth_token (service, tokenstring, datec, entity)";
			$sql .= " VALUES ('" . $db->escape($service) . "', 'access-token-in-clear', '" . $db->idate(dol_now()) . "', " . ((int) $conf->entity) . ")";
			$this->assertNotFalse($db->query($sql));

			$item = array('kind' => CredentialStorage::KIND_TOKEN, 'name' => $service, 'column' => 'tokenstring', 'label' => 'TOKEN', 'istoken' => true);
			$item['raw'] = $storage->readRaw($item, (int) $conf->entity);
			$item['encrypted'] = false;
			$item['clear'] = $item['raw'];
		}

		$this->assertSame('access-token-in-clear', $item['clear']);
		$this->assertSame(1, $storage->encryptInPlace(array($item), (int) $conf->entity));

		$raw = $storage->readRaw($item, (int) $conf->entity);
		$this->assertStringStartsWith('dolcrypt:', $raw);
		$this->assertSame(1, substr_count($raw, 'dolcrypt:'));
		$this->assertSame('access-token-in-clear', $storage->readBack($item, (int) $conf->entity));

		$this->assertTrue($storage->restore(array($item), (int) $conf->entity));
		$this->assertSame('access-token-in-clear', $storage->readRaw($item, (int) $conf->entity));
	}
}
