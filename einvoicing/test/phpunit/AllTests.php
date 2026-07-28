<?php
/* Copyright (C) 2010-2012  Laurent Destailleur <eldy@users.sourceforge.net>
 * Copyright (C) 2011-2012  Regis Houssin       <regis.houssin@inodbox.com>
 * Copyright (C) 2024		MDW							<mdeweerd@users.noreply.github.com>
 * Copyright (C) 2024-2026  Frédéric France         <frederic.france@free.fr>
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
 *      \file       einvoicing/test/phpunit/AllTests.php
 *      \ingroup    test
 *      \brief      This file is a test suite to run all unit tests
 *      \remarks    To run this script as CLI:  phpunit filename.php
 */

print "PHP Version: ".phpversion()."\n";
print "Memory limit: ". ini_get('memory_limit')."\n";

// Workaround for false security issue with main.inc.php on Windows in tests:
if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
	$_SERVER['PHP_SELF'] = "phpunit";
}

if (! defined('NOREQUIREUSER')) {
	define('PHPUNIT_MODE', 1);
}

global $conf,$user,$langs,$db;
//define('TEST_DB_FORCE_TYPE','mysql'); // This is to force using mysql driver
//require_once 'PHPUnit/Autoload.php';

require_once dirname(__FILE__).'/../../htdocs/master.inc.php';
print 'DOL_MAIN_URL_ROOT='.DOL_MAIN_URL_ROOT."\n";  // constant will be used by other tests

if ($langs->defaultlang != 'en_US') {
	print "Error: Default language for company to run tests must be set to en_US or auto. Current is ".$langs->defaultlang."\n";
	exit(1);
}
if (isModEnabled('debugbar')) {
	print "Error: Debugbar module should not be enabled. It generates troubles in db management.\n";
	exit(1);
}
if (!isModEnabled('member')) {
	print "Error: Module member must be enabled to have significant results.\n";
	exit(1);
}
if (isModEnabled('ldap')) {
	print "Error: LDAP module should not be enabled.\n";
	exit(1);
}
if (isModEnabled('google')) {
	print "Warning: Google module should not be enabled.\n";
}
if (empty($user->id)) {
	print "Load permissions for admin user nb 1\n";
	$user->fetch(1);
	$user->loadRights();
}
$conf->global->MAIN_DISABLE_ALL_MAILS = 1;
$conf->global->MAIN_UMASK = '666';
$now = dol_now();

require_once dirname(__FILE__).'/../../htdocs/core/lib/admin.lib.php';
dolibarr_set_const($db, 'API_ENABLE_LOGIN_API', 1);


dolibarr_set_const($db, 'MAIN_FIRST_REGISTRATION_OK_DATE', dol_print_date($now, 'dayhourlog', 'gmt'));
dolibarr_set_const($db, 'BLOCKEDLOG_REGISTRATION_NAME', 'MyBigCompanyByPHPUnit');
dolibarr_set_const($db, 'BLOCKEDLOG_REGISTRATION_EMAIL', 'mybigcompany@example.com');
dolibarr_set_const($db, 'MAIN_INFO_SIREN', 'phpunit123');
dolibarr_set_const($db, 'MAIN_INFO_SIRET', 'phpunit12312345');
$sql = "DELETE FROM ".MAIN_DB_PREFIX."const WHERE name = 'blockedlog-1.end'";
$db->query($sql);

// Test there is no webhook enabled
// TODO



/**
 * Class for the All test suite
 */
class AllTests
{
	/**
	 * Function suite to make all PHPUnit tests
	 *
	 * @return	void
	 */
	public static function suite()
	{
		$suite = new PHPUnit\Framework\TestSuite('PHPUnit Framework');

		//require_once dirname(__FILE__).'/CoreTest.php';
		//$suite->addTestSuite('CoreTest');
		require_once dirname(__FILE__).'/BillingProcessIdTest.php';
		$suite->addTestSuite('BillingProcessIdTest');
		require_once dirname(__FILE__).'/CIIProfileShapeTest.php';
		$suite->addTestSuite('CIIProfileShapeTest');
		require_once dirname(__FILE__).'/CIIProtocolTest.php';
		$suite->addTestSuite('CIIProtocolTest');
		require_once dirname(__FILE__).'/EInvoicingSamplesTest.php';
		$suite->addTestSuite('EInvoicingSamplesTest');
		require_once dirname(__FILE__).'/RecipientDirectoryTest.php';
		$suite->addTestSuite('RecipientDirectoryTest');
		require_once dirname(__FILE__).'/SupplierInvoiceHelperTest.php';
		$suite->addTestSuite('SupplierInvoiceHelperTest');

		return $suite;
	}
}
