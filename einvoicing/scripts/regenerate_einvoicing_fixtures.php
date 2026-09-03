#!/usr/bin/env php
<?php
/* Copyright (C) 2025       Laurent Destailleur         <eldy@users.sourceforge.net>
 * Copyright (C) 2025       Mohamed DAOUD               <mdaoud@dolicloud.com>
 * Copyright (C) 2026		MDW							<mdeweerd@users.noreply.github.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 *      \file       scripts/regenerate_einvoicing_fixtures.php
 *      \ingroup    einvoicing
 *      \brief      Regenerates sample invoices reference fixtures used by test/phpunit/EInvoicingSamplesTest.php.
 *                  To run after a change that intentionally alters the XML produced by CIIProtocol,
 *                  then commit the updated fixtures alongside it.
 */


// This script must only be run from the command line: it emulates an OAuth
// token exchange and would otherwise expose the PDP client_secret over HTTP.
if (PHP_SAPI !== 'cli') {
	echo "Error: this script must be run from the command line (CLI), not through a web server.\n";
	exit(1);
}

// Load Dolibarr environment
$res = 0;
// This module is deployed by symlinking the repository into htdocs/custom/einvoicing, in which case
// every relative path below resolves inside the repository instead of inside the Dolibarr instance
// and the include fails. DOLIBARR_HTDOCS lets the developer/CI point explicitly at the instance to
// run against, exactly like test/phpunit/EInvoicingSamplesTest.php already does.
$dolibarrHtdocs = rtrim((string) getenv('DOLIBARR_HTDOCS'), '/');
if (!$res && $dolibarrHtdocs && file_exists($dolibarrHtdocs.'/master.inc.php')) {
	$_SERVER['DOCUMENT_ROOT'] = $dolibarrHtdocs;
	chdir($dolibarrHtdocs);
	$res = @include $dolibarrHtdocs.'/master.inc.php';
}
// Try main.inc.php into web root known defined into CONTEXT_DOCUMENT_ROOT (not always defined)
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/master.inc.php";
}
// Try main.inc.php into web root detected using web root calculated from SCRIPT_FILENAME
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;  // @phan-suppress-current-line DolibarrForbiddenFunctionPlugin
$j = strlen($tmp2) - 1;  // @phan-suppress-current-line DolibarrForbiddenFunctionPlugin
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
	$i--;
	$j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1))."/master.inc.php")) {
	$res = @include substr($tmp, 0, ($i + 1))."/master.inc.php";
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1)))."/master.inc.php")) {
	$res = @include dirname(substr($tmp, 0, ($i + 1)))."/master.inc.php";
}
// Try main.inc.php using relative path
if (!$res && file_exists("../master.inc.php")) {
	$res = @include "../master.inc.php";
}
if (!$res && file_exists("../../master.inc.php")) {
	$res = @include "../../master.inc.php";
}
if (!$res && file_exists("../../../master.inc.php")) {
	$res = @include "../../../master.inc.php";
}
if (!$res && file_exists("../../../../master.inc.php")) {
	$res = @include "../../../../master.inc.php";
}
if (!$res && file_exists("../../../../../master.inc.php")) {
	$res = @include "../../../../../master.inc.php";
}
if (!$res && file_exists("../../../../../../master.inc.php")) {
	$res = @include "../../../../../../master.inc.php";
}
if (!$res) {
	die("Include of master fails. When the module is deployed as a symlink into htdocs/custom/einvoicing, set DOLIBARR_HTDOCS to the htdocs directory of the Dolibarr instance to run against.\n");
}
/**
 * The main.inc.php has been included so the following variable are now defined:
 * @var Conf $conf
 * @var DoliDB $db
 * @var HookManager $hookmanager
 * @var Translate $langs
 * @var User $user
 */

dol_include_once('einvoicing/class/einvoicing.class.php');

$conf->global->MAIN_DISABLE_ALL_MAILS = 1;

$db->begin();

$fixturesDir = __DIR__ . '/../test/phpunit/fixtures/einvoicing_samples';
dol_mkdir($fixturesDir);

$files = array(
	'deposit' => $fixturesDir . '/cii_deposit.xml',
	'standard' => $fixturesDir . '/cii_standard.xml',
	'replacement' => $fixturesDir . '/cii_replacement.xml',
	'creditnote' => $fixturesDir . '/cii_creditnote.xml',
	'situation' => $fixturesDir . '/cii_situation.xml',
);

try {
	$xmls = EInvoicing::generateSampleEInvoicesForTests();

	foreach ($files as $key => $path) {
		$previous = file_exists($path) ? file_get_contents($path) : null;
		$changed = ($previous !== $xmls[$key]);

		file_put_contents($path, $xmls[$key]);

		print(($changed ? 'UPDATED' : 'unchanged') . ' : ' . $path . PHP_EOL);
	}

	print "Done.\n";
} finally {
	$db->rollback();
}
