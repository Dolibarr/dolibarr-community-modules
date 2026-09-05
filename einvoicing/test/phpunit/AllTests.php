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
	// User::loadRights() only exists from Dolibarr 20 on, older versions name it getrights()
	if (method_exists($user, 'loadRights')) {
		$user->loadRights();
	} else {
		$user->getrights();
	}
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

		// Every test file of this directory, in name order. The list this replaces was written by
		// hand and had already missed 13 of the 39 files, and each new one was a conflict between
		// the pull requests adding it.
		foreach ((array) glob(dirname(__FILE__).'/*Test.php') as $file) {
			require_once $file;
			$suite->addTestSuite(basename($file, '.php'));
		}

		return $suite;
	}
}
