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
 *      \file       test/phpunit/CompatShimReloadTest.php
 *      \ingroup    test
 *      \brief      PHPUnit test for the polyfills of compat/: they must be inert when the symbol
 *                  they backport is already there.
 *      \remarks    The module runs from Dolibarr 17 and carries copies of core symbols that only
 *                  appeared later. It is not the only module in that situation: any other module of
 *                  the instance that also has to run on both sides of the line carries the same
 *                  copies, and on a name that is already taken PHP does not warn, it fatals. That is
 *                  how activating the module on the second entity of a multicompany setup died on
 *                  "Cannot declare class CommonHookActions, because the name is already in use"
 *                  (issue #630) - the hook of the module could no longer be loaded at all.
 *
 *                  Each shim is therefore checked in a subprocess, since a redeclaration cannot be
 *                  caught in the running one: first with the symbol already declared (the shim must
 *                  do nothing), then on its own (the shim must still deliver what it backports).
 *      \remarks    To run this script as CLI: phpunit filename.php
 */

global $conf, $user, $langs, $db;

// This module is deployed by symlinking this repository into htdocs/custom/einvoicing of one or
// several Dolibarr instances. Some test runners resolve the real (non-symlinked) path of this
// file before including it, which breaks a fixed "../../htdocs/master.inc.php" relative path.
// DOLIBARR_HTDOCS let's the developer/CI point explicitly at the Dolibarr instance to test
// against; otherwise we fall back to the standard relative path (valid when this file is reached
// through the htdocs/custom/einvoicing/test/phpunit symlink without realpath resolution).
$dolibarrHtdocs = getenv('DOLIBARR_HTDOCS');
if (!$dolibarrHtdocs) {
	$dolibarrHtdocs = dirname(__FILE__) . '/../../htdocs';
}
if (!file_exists($dolibarrHtdocs . '/master.inc.php')) {
	throw new \RuntimeException('Could not locate master.inc.php under "' . $dolibarrHtdocs . '/". Set the environment variable (export DOLIBARR_HTDOCS=...) to the htdocs directory of the Dolibarr instance to test against.');
}

require_once $dolibarrHtdocs . '/master.inc.php';
require_once __DIR__ . '/CommonClassTestCompat.inc.php';

/**
 * Class for PHPUnit tests
 *
 * @backupGlobals disabled
 * @backupStaticAttributes enabled
 * @remarks	backupGlobals must be disabled to have db,conf,user and lang not erased.
 */
class CompatShimReloadTest extends CommonClassTest
{
	/**
	 * The shims, and what has to be in place for each of them to become a no-op.
	 *
	 * @return array<string,string[]>	name => shim file, prior declaration, check
	 */
	public function shimProvider()
	{
		return array(
			'commonhookactions' => array(
				'compat/commonhookactions.class.php',
				'abstract class CommonHookActions { public $resprints; public $results = array(); }',
				'class_exists("CommonHookActions")',
			),
			'profid' => array(
				'compat/profid.lib.php',
				'function isValidLuhn($str) { return true; } function isValidSiren($siren) { return true; } function isValidSiret($siret) { return true; } function isValidTinForPT($str) { return true; } function isValidTinForDZ($str) { return true; } function isValidTinForBE($str) { return true; } function isValidTinForES($str) { return 1; }',
				'function_exists("isValidLuhn") && function_exists("isValidTinForES")',
			),
			'societe' => array(
				'compat/societe.lib.php',
				'function calculateVATNumberFromProperties($thirdparty) { return ""; }',
				'function_exists("calculateVATNumberFromProperties")',
			),
			'files' => array(
				'compat/files.lib.php',
				'function dolChmod($filepath, $newmask = "") { }',
				'function_exists("dolChmod")',
			),
			'functions' => array(
				'compat/functions.lib.php',
				'function GETPOSTDATE($prefix, $hourTime = "", $gm = "auto") { return ""; } function GETPOSTFLOAT($paramname, $rounding = "") { return 0.0; } function dolPrintHTMLForAttribute($s) { return ""; }',
				'function_exists("GETPOSTDATE") && function_exists("dolPrintHTMLForAttribute")',
			),
		);
	}

	/**
	 * A shim loaded after something else already brought the symbol in must be a no-op, not a fatal.
	 *
	 * @param	string	$shim		Path of the shim, relative to the module root
	 * @param	string	$declared	What another module (or a backporting core) declared first
	 * @param	string	$check		Expression that must still hold once the shim is loaded
	 * @return	void
	 *
	 * @dataProvider shimProvider
	 */
	public function testShimIsInertWhenTheSymbolIsAlreadyDeclared($shim, $declared, $check)
	{
		$result = $this->runPhp($declared . ' require ' . var_export($this->shimPath($shim), true) . '; echo (' . $check . ') ? "OK" : "MISSING";');

		$this->assertSame('OK', $result, $shim . ' must not redeclare what is already there: ' . $result);
	}

	/**
	 * And it must still be the polyfill it claims to be when nothing declared the symbol.
	 *
	 * @param	string	$shim		Path of the shim, relative to the module root
	 * @param	string	$declared	Unused here
	 * @param	string	$check		Expression that must hold once the shim is loaded
	 * @return	void
	 *
	 * @dataProvider shimProvider
	 */
	public function testShimStillDeclaresWhatItBackports($shim, $declared, $check)
	{
		$result = $this->runPhp('require ' . var_export($this->shimPath($shim), true) . '; echo (' . $check . ') ? "OK" : "MISSING";');

		$this->assertSame('OK', $result, $shim . ' no longer provides its polyfill: ' . $result);
	}

	/**
	 * Absolute path of a file of the module.
	 *
	 * @param	string	$relative	Path relative to the module root
	 * @return	string				Absolute path
	 */
	private function shimPath($relative)
	{
		return dirname(__DIR__, 2) . '/' . $relative;
	}

	/**
	 * Run a snippet in a subprocess, because a redeclaration is fatal and cannot be caught here.
	 *
	 * @param	string	$code	PHP code to run, without the opening tag
	 * @return	string			Trimmed output, stderr included
	 */
	private function runPhp($code)
	{
		$output = array();
		$status = 0;
		exec(escapeshellarg(PHP_BINARY) . ' -d error_reporting=E_ALL -r ' . escapeshellarg($code) . ' 2>&1', $output, $status);

		$text = trim(implode("\n", $output));

		return $status === 0 ? $text : 'exit ' . $status . ' - ' . $text;
	}
}
