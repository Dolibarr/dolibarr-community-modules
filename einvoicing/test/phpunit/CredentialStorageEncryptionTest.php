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
 *      \file       test/phpunit/CredentialStorageEncryptionTest.php
 *      \ingroup    test
 *      \brief      PHPUnit test for the storage of the credentials: the access token, the refresh token
 *                  and the secrets of the setup page must reach the database encrypted, whatever the
 *                  core version does with the name of their constant (issue #1013).
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
dol_include_once('einvoicing/class/providers/AbstractPDPProvider.class.php');
dol_include_once('einvoicing/class/providers/TestPDPProvider.class.php');
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
 * Give the test a way to reach the protected helper the setup fields are wired to.
 *
 * TestPDPProvider talks to nothing: its constructor only reads constants, so an instance can be
 * built here without any network or credential.
 */
class CredentialStorageEncryptionProvider extends TestPDPProvider
{
	/**
	 * Attach the save callback of the module to a setup field, from outside the class.
	 *
	 * @param	FormSetupItem	$item	Setup field holding a credential
	 * @return	void
	 */
	public function exposeStoreThisFieldEncrypted($item)
	{
		$this->storeThisFieldEncrypted($item);
	}
}


/**
 * Tests on the storage of the credentials.
 *
 * Everything written here happens inside the transaction CommonClassTest opens for the class and
 * rolls back afterwards, so a run leaves neither constant nor token row behind.
 *
 * @backupGlobals disabled
 */
class CredentialStorageEncryptionTest extends CommonClassTest
{
	/** @var string Name of a constant no installation has, so the rows below are ours alone */
	const SECRET_CONST = 'EINVOICING_TESTPDP_CLIENT_SECRET_PROD';

	/**
	 * Tell whether this instance can encrypt at all.
	 *
	 * dolEncrypt() returns the value unchanged when conf.php carries no instance key, which is also
	 * what the core does with its own constants: there is nothing to assert on such an instance.
	 *
	 * @return bool True when dolEncrypt() really encrypts
	 */
	private function instanceHasAnEncryptionKey()
	{
		global $conf;

		return !empty($conf->file->instance_unique_id);
	}

	/**
	 * Read a constant as it sits in the table, without the decryption dolibarr_get_const() applies.
	 *
	 * @param	string	$name	Name of the constant
	 * @return	string			Raw value, '' when the row does not exist
	 */
	private function rawConstValue($name)
	{
		global $conf, $db;

		$sql = "SELECT " . $db->decrypt('value') . " as value FROM " . MAIN_DB_PREFIX . "const";
		$sql .= " WHERE name = " . $db->encrypt($name);
		$sql .= " AND entity = " . ((int) $conf->entity);

		$resql = $db->query($sql);
		$this->assertNotFalse($resql, 'Could not read back the constant ' . $name . ': ' . $db->lasterror());

		$obj = $db->fetch_object($resql);

		return ($obj ? (string) $obj->value : '');
	}

	/**
	 * Read the token row of a service as it sits in the table, without any decryption.
	 *
	 * @param	string	$service	Service name, the 'dol_prefix' of the provider plus the environment
	 * @return	array{tokenstring:string,tokenstring_refresh:string}		Raw columns, '' when the row does not exist
	 */
	private function rawTokenRow($service)
	{
		global $conf, $db;

		$sql = "SELECT tokenstring, tokenstring_refresh FROM " . MAIN_DB_PREFIX . "oauth_token";
		$sql .= " WHERE service = '" . $db->escape($service) . "'";
		$sql .= " AND entity = " . ((int) $conf->entity);

		$resql = $db->query($sql);
		$this->assertNotFalse($resql, 'Could not read back the token of ' . $service . ': ' . $db->lasterror());

		$obj = $db->fetch_object($resql);

		return array(
			'tokenstring' => ($obj ? (string) $obj->tokenstring : ''),
			'tokenstring_refresh' => ($obj ? (string) $obj->tokenstring_refresh : ''),
		);
	}

	/**
	 * Read back what saveOAuthTokenDB() wrote, from wherever this core version keeps it.
	 *
	 * @param	string	$service	Service name, the 'dol_prefix' of the provider plus the environment
	 * @return	array{token:string,refresh:string}	Raw stored values
	 */
	private function rawStoredToken($service)
	{
		if (version_compare(DOL_VERSION, '23.0.0-alpha', '<')) {
			return array(
				'token' => $this->rawConstValue($service . '_TOKEN'),
				'refresh' => $this->rawConstValue($service . '_REFRESH'),
			);
		}

		$row = $this->rawTokenRow($service);

		return array(
			'token' => $row['tokenstring'],
			'refresh' => $row['tokenstring_refresh'],
		);
	}

	/**
	 * The access token and the refresh token reach the database encrypted.
	 *
	 * Neither '_TOKEN' nor '_REFRESH' is in the list dolibarr_set_const() matches, and the token row
	 * of the table is written by the module itself, so nothing encrypted them before.
	 *
	 * @return void
	 */
	public function testTheTokenAndItsRefreshAreStoredEncrypted()
	{
		global $conf, $db;

		if (!$this->instanceHasAnEncryptionKey()) {
			$this->markTestSkipped('conf.php carries no instance key, so nothing can be encrypted on this instance');
		}

		$provider = new CredentialStorageEncryptionProvider($db);
		$service = 'EINVOICING_TESTPDP_' . (getDolGlobalInt('EINVOICING_LIVE') ? 'PROD' : 'TEST');

		$this->assertTrue($provider->saveOAuthTokenDB('access-1013-clear', 'refresh-1013-clear', 3600));

		$stored = $this->rawStoredToken($service);

		$this->assertStringStartsWith('dolcrypt:', $stored['token'], 'The access token reached the database in clear');
		$this->assertStringStartsWith('dolcrypt:', $stored['refresh'], 'The refresh token reached the database in clear');
		$this->assertStringNotContainsString('access-1013-clear', $stored['token']);
		$this->assertStringNotContainsString('refresh-1013-clear', $stored['refresh']);

		// And the module reads its own value back, which is the whole point of storing it.
		$read = $provider->fetchOAuthTokenDB((int) $conf->entity);
		$this->assertNotFalse($read);
		$this->assertSame('access-1013-clear', $read['token']);
		$this->assertSame('refresh-1013-clear', $read['refresh_token']);
	}

	/**
	 * A token written in clear by an earlier version of the module is still read.
	 *
	 * dolDecrypt() returns unchanged whatever has no 'dolcrypt:' prefix, so no migration is needed
	 * and an installation that upgrades keeps talking to its platform.
	 *
	 * @return void
	 */
	public function testATokenAlreadyStoredInClearIsStillRead()
	{
		global $conf, $db;

		$provider = new CredentialStorageEncryptionProvider($db);
		$service = 'EINVOICING_TESTPDP_' . (getDolGlobalInt('EINVOICING_LIVE') ? 'PROD' : 'TEST');

		// Plant what the module used to write, bypassing the code under test.
		if (version_compare(DOL_VERSION, '23.0.0-alpha', '<')) {
			dolibarr_set_const($db, $service . '_TOKEN', 'legacy-access-in-clear', 'chaine', 0, '', $conf->entity);
			dolibarr_set_const($db, $service . '_REFRESH', 'legacy-refresh-in-clear', 'chaine', 0, '', $conf->entity);
		} else {
			$sql = "DELETE FROM " . MAIN_DB_PREFIX . "oauth_token WHERE service = '" . $db->escape($service) . "'";
			$sql .= " AND entity = " . ((int) $conf->entity);
			$this->assertNotFalse($db->query($sql));

			$sql = "INSERT INTO " . MAIN_DB_PREFIX . "oauth_token (service, tokenstring, tokenstring_refresh, datec, entity)";
			$sql .= " VALUES ('" . $db->escape($service) . "', 'legacy-access-in-clear', 'legacy-refresh-in-clear', '" . $db->idate(dol_now()) . "', " . ((int) $conf->entity) . ")";
			$this->assertNotFalse($db->query($sql));
		}

		$read = $provider->fetchOAuthTokenDB((int) $conf->entity);

		$this->assertNotFalse($read);
		$this->assertSame('legacy-access-in-clear', $read['token']);
		$this->assertSame('legacy-refresh-in-clear', $read['refresh_token']);
	}

	/**
	 * The secret of a setup field reaches the database encrypted, and reads back as typed.
	 *
	 * This is the field of the report: same screen, same code path as the sandbox one, but its
	 * constant ends with the environment marker, which hides the keyword the core matches.
	 *
	 * @return void
	 */
	public function testTheSecretOfASetupFieldIsStoredEncrypted()
	{
		global $conf, $db;

		if (!$this->instanceHasAnEncryptionKey()) {
			$this->markTestSkipped('conf.php carries no instance key, so nothing can be encrypted on this instance');
		}

		// FormSetup builds a Form of its own, which the CLI bootstrap does not load.
		require_once DOL_DOCUMENT_ROOT . '/core/class/html.form.class.php';
		require_once DOL_DOCUMENT_ROOT . '/core/class/html.formsetup.class.php';

		$provider = new CredentialStorageEncryptionProvider($db);

		$formSetup = new FormSetup($db);
		$item = $formSetup->newItem(self::SECRET_CONST);
		$item->fieldValue = 'secret-1013-clear';
		$provider->exposeStoreThisFieldEncrypted($item);

		$this->assertSame(1, $item->saveConfValue());

		$this->assertStringStartsWith('dolcrypt:', $this->rawConstValue(self::SECRET_CONST), 'The secret of the setup field reached the database in clear');
		$this->assertSame('secret-1013-clear', dolibarr_get_const($db, self::SECRET_CONST, (int) $conf->entity));
	}

	/**
	 * A credential holding an accent is read back as it was, encrypted or not.
	 *
	 * Dolibarr 23 hands back the encrypted string instead of a decrypted value that is not plain ASCII,
	 * so such a value is stored the way it was before rather than made unusable.
	 *
	 * @return void
	 */
	public function testACredentialWithAnAccentIsAlwaysReadBack()
	{
		global $conf, $db;

		$secret = 'clé secrète à protéger';
		$stored = AbstractPDPProvider::encryptIfReadable($secret);

		$this->assertSame($secret, dolDecrypt($stored), 'The value does not read back as it was');
		if (dolDecrypt(dolEncrypt($secret)) !== $secret) {
			$this->assertSame($secret, $stored, 'This core cannot read that value back, so it must stay as it is');
		} else {
			$this->assertStringStartsWith('dolcrypt:', $stored);
		}

		// And through the storage the module really uses.
		$provider = new CredentialStorageEncryptionProvider($db);
		$this->assertTrue($provider->saveOAuthTokenDB($secret, $secret, 3600));

		$read = $provider->fetchOAuthTokenDB((int) $conf->entity);
		$this->assertNotFalse($read);
		$this->assertSame($secret, $read['token']);
		$this->assertSame($secret, $read['refresh_token']);
	}

	/**
	 * Encrypting a value that is already encrypted stores one layer, not two.
	 *
	 * This is what makes the fix safe on every core: Dolibarr 23.0.4 and 24 encrypt some of these
	 * names themselves, and would receive a value this module has already encrypted. A second layer
	 * would be decrypted only once on read, and the module would send a 'dolcrypt:...' string as its
	 * credential.
	 *
	 * @return void
	 */
	public function testEncryptingAnAlreadyEncryptedValueChangesNothing()
	{
		if (!$this->instanceHasAnEncryptionKey()) {
			$this->markTestSkipped('conf.php carries no instance key, so nothing can be encrypted on this instance');
		}

		$once = dolEncrypt('secret-1013-clear');

		$this->assertStringStartsWith('dolcrypt:', $once);
		$this->assertSame($once, dolEncrypt($once));
		$this->assertSame('secret-1013-clear', dolDecrypt(dolEncrypt($once)));
	}
}
