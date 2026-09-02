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

$dolibarrHtdocs = getenv('DOLIBARR_HTDOCS');
if (!$dolibarrHtdocs) {
	$dolibarrHtdocs = dirname(__FILE__) . '/../../htdocs';
}
if (!file_exists($dolibarrHtdocs . '/master.inc.php')) {
	throw new \RuntimeException('Could not locate master.inc.php under "' . $dolibarrHtdocs . '/". Set the environment variable (export DOLIBARR_HTDOCS=...) to the htdocs directory of the Dolibarr instance to test against.');
}

require_once $dolibarrHtdocs . '/master.inc.php';

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
if (!isModEnabled('einvoicing')) {
	print "Error: Module einvoicing must be enabled to have significant results.\n";
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

require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';

print "dolibarrHtdocs=".$dolibarrHtdocs."\n";

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
		require_once dirname(__FILE__).'/CIITextEscapingTest.php';
		$suite->addTestSuite('CIITextEscapingTest');
		require_once dirname(__FILE__).'/CompatShimReloadTest.php';
		$suite->addTestSuite('CompatShimReloadTest');
		require_once dirname(__FILE__).'/EInvoicingSamplesTest.php';
		$suite->addTestSuite('EInvoicingSamplesTest');
		require_once dirname(__FILE__).'/HeaderChargeLineTest.php';
		$suite->addTestSuite('HeaderChargeLineTest');
		require_once dirname(__FILE__).'/InvoicingPeriodTest.php';
		$suite->addTestSuite('InvoicingPeriodTest');
		require_once dirname(__FILE__).'/LineWithoutQuantityTest.php';
		$suite->addTestSuite('LineWithoutQuantityTest');
		require_once dirname(__FILE__).'/PDPProviderManagerTest.php';
		$suite->addTestSuite('PDPProviderManagerTest');
		require_once dirname(__FILE__).'/RecipientDirectoryTest.php';
		$suite->addTestSuite('RecipientDirectoryTest');
		require_once dirname(__FILE__).'/SellerVatRegimeTest.php';
		$suite->addTestSuite('SellerVatRegimeTest');
		require_once dirname(__FILE__).'/SkipB2CPrecheckTest.php';
		$suite->addTestSuite('SkipB2CPrecheckTest');
		require_once dirname(__FILE__).'/StatusComboMarkupTest.php';
		$suite->addTestSuite('StatusComboMarkupTest');
		require_once dirname(__FILE__).'/SupplierInvoiceHelperTest.php';
		$suite->addTestSuite('SupplierInvoiceHelperTest');
		require_once dirname(__FILE__).'/TransmittedLockTest.php';
		$suite->addTestSuite('TransmittedLockTest');
		require_once dirname(__FILE__).'/VatCategoryFromVatCodeTest.php';
		$suite->addTestSuite('VatCategoryFromVatCodeTest');
		require_once dirname(__FILE__).'/VatPointDateCodeTest.php';
		$suite->addTestSuite('VatPointDateCodeTest');

		return $suite;
	}
}
