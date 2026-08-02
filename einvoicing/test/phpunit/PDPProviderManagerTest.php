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
 * along with this program. If not, see https://www.gnu.org/licenses/
 */

/**
 *      \file       test/phpunit/PDPProviderManagerTest.php
 *      \ingroup    test
 *      \brief      PHPUnit test for PDPProviderManager::getProvider(): loading a provider whose class
 *                  lives in another module (the 'classpath' key that lets an external module bring its
 *                  own provider), the refusal of a class that does not extend AbstractPDPProvider, and
 *                  the paths that must keep returning null.
 *      \remarks    To run this script as CLI: phpunit filename.php
 */

global $conf, $user, $langs, $db;

// See RecipientDirectoryTest for why DOLIBARR_HTDOCS is honoured here.
$dolibarrHtdocs = getenv('DOLIBARR_HTDOCS');
if (!$dolibarrHtdocs) {
	$dolibarrHtdocs = dirname(__FILE__) . '/../../htdocs';
}
if (!file_exists($dolibarrHtdocs . '/master.inc.php')) {
	throw new \RuntimeException('Could not locate master.inc.php under "' . $dolibarrHtdocs . '/". Set the environment variable (export DOLIBARR_HTDOCS=...) to the htdocs directory of the Dolibarr instance to test against.');
}

require_once $dolibarrHtdocs . '/master.inc.php';
dol_include_once('einvoicing/class/providers/AbstractPDPProvider.class.php');
dol_include_once('einvoicing/class/providers/PDPProviderManager.class.php');
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
 * Class for PHPUnit tests
 *
 * @backupGlobals disabled
 * @backupStaticAttributes enabled
 * @remarks	backupGlobals must be disabled to have db,conf,user and lang not erased.
 */
class PDPProviderManagerTest extends CommonClassTest
{
	/**
	 * Declare a provider in the list of a manager, the way the hook of an external module does.
	 *
	 * The list is private, and its filling by the hook needs a third-party module installed, so the
	 * entry is written directly: what is under test is getProvider(), which only sees the entry.
	 *
	 * @param	PDPProviderManager				$manager	Manager to complete
	 * @param	string							$code		Code of the provider
	 * @param	array<string,int|string|array>	$entry		Entry of the provider
	 * @return	void
	 */
	private function declareProvider($manager, $code, $entry)
	{
		$property = new ReflectionProperty('PDPProviderManager', 'providersList');
		$property->setAccessible(true);

		$list = $property->getValue($manager);
		$list[$code] = $entry;
		$property->setValue($manager, $list);
	}

	/**
	 * A provider of the module itself declares no 'classpath': it must keep being loaded from
	 * einvoicing/class/providers/.
	 *
	 * The entry is declared here rather than taken from the list of the instance under test, whose
	 * native entries can all be disabled by the setup (EINVOICING_SUPERPDP_VIAPARTNER_ONLY).
	 *
	 * @return void
	 */
	public function testNativeProviderIsLoadedWithoutClasspath()
	{
		global $db;

		$manager = new PDPProviderManager($db);
		$this->declareProvider($manager, 'NOCLASSPATHPDP', array(
			'class' => 'TestPDPProvider',
			'is_enabled' => 1,
		));

		$provider = $manager->getProvider('NOCLASSPATHPDP');

		$this->assertInstanceOf('TestPDPProvider', $provider);
		// The code of the entry is handed back to the provider: several entries may share a class.
		$this->assertSame('NOCLASSPATHPDP', $provider->providerName);
	}

	/**
	 * A provider declared with a 'classpath' is loaded from that directory, which is what lets an
	 * external module ship its provider class in its own module directory.
	 *
	 * @return void
	 */
	public function testProviderIsLoadedFromItsDeclaredClasspath()
	{
		global $db;

		$manager = new PDPProviderManager($db);
		$this->declareProvider($manager, 'EXTERNALDIRPDP', array(
			'class' => 'ExternalDirPDPProvider',
			'classpath' => '/einvoicing/test/phpunit/fixtures/',
			'position' => 500,
			'provider_countries' => array('all'),
			'provider_name' => 'External dir PDP',
			'description' => 'Provider whose class file is outside einvoicing/class/providers/',
			'is_enabled' => 1,
			'prod_account_admin_url' => null,
			'test_account_admin_url' => null,
		));

		$provider = $manager->getProvider('EXTERNALDIRPDP');

		$this->assertInstanceOf('ExternalDirPDPProvider', $provider);
		$this->assertInstanceOf('AbstractPDPProvider', $provider);
		$this->assertSame('EXTERNALDIRPDP', $provider->providerName);
	}

	/**
	 * A trailing slash on the classpath is optional: both spellings must find the class file.
	 *
	 * @return void
	 */
	public function testClasspathWithoutTrailingSlashIsAccepted()
	{
		global $db;

		$manager = new PDPProviderManager($db);
		$this->declareProvider($manager, 'EXTERNALDIRPDP2', array(
			'class' => 'ExternalDirPDPProvider',
			'classpath' => '/einvoicing/test/phpunit/fixtures',
			'is_enabled' => 1,
		));

		$this->assertInstanceOf('ExternalDirPDPProvider', $manager->getProvider('EXTERNALDIRPDP2'));
	}

	/**
	 * The rest of the module calls the methods of AbstractPDPProvider on whatever getProvider() hands
	 * back. A class that does not extend it is refused here, instead of failing later on a missing
	 * method, far from the declaration that is at fault.
	 *
	 * @return void
	 */
	public function testClassNotExtendingAbstractProviderIsRefused()
	{
		global $db;

		$manager = new PDPProviderManager($db);
		// An existing class, loadable from an existing file, but not a provider.
		$this->declareProvider($manager, 'NOTAPROVIDER', array(
			'class' => 'EInvoicing',
			'classpath' => '/einvoicing/class/',
			'is_enabled' => 1,
		));

		$this->assertNull($manager->getProvider('NOTAPROVIDER'));
	}

	/**
	 * A class file that does not exist must be reported as an unusable provider, not fatal.
	 *
	 * @return void
	 */
	public function testMissingClassFileReturnsNull()
	{
		global $db;

		$manager = new PDPProviderManager($db);
		$this->declareProvider($manager, 'GHOSTPDP', array(
			'class' => 'GhostPDPProvider',
			'classpath' => '/einvoicing/class/providers/',
			'is_enabled' => 1,
		));

		$this->assertNull($manager->getProvider('GHOSTPDP'));
	}

	/**
	 * A disabled provider, and a provider that was never declared, are not instantiable.
	 *
	 * @return void
	 */
	public function testDisabledOrUnknownProviderReturnsNull()
	{
		global $db;

		$manager = new PDPProviderManager($db);
		$this->declareProvider($manager, 'DISABLEDPDP', array(
			'class' => 'TestPDPProvider',
			'is_enabled' => 0,
		));

		$this->assertNull($manager->getProvider('DISABLEDPDP'));
		$this->assertNull($manager->getProvider('THISPDPDOESNOTEXIST'));
	}
}
