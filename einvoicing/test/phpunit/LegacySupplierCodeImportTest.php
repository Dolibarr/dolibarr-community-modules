<?php
/* Copyright (C) 2026 ATM Consulting
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
 * \file    einvoicing/test/phpunit/LegacySupplierCodeImportTest.php
 * \ingroup einvoicing
 * \brief   A vendor keeping a code from a former numbering rule is still updated by the import.
 */

global $conf, $user, $langs, $db;

$dolibarrHtdocs = getenv('DOLIBARR_HTDOCS');
if (!$dolibarrHtdocs) {
	$dolibarrHtdocs = dirname(__FILE__) . '/../../htdocs';
}
if (!file_exists($dolibarrHtdocs . '/master.inc.php')) {
	throw new \RuntimeException('Could not locate master.inc.php under "' . $dolibarrHtdocs . '/". Set the environment variable (export DOLIBARR_HTDOCS=...) to the htdocs directory of the Dolibarr instance to test against.');
}

require_once $dolibarrHtdocs . '/master.inc.php';
require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
dol_include_once('einvoicing/class/protocols/CIIProtocol.class.php');
require_once __DIR__ . '/CommonClassTestCompat.inc.php';

/**
 * Class for PHPUnit tests
 * @backupGlobals disabled
 * @backupStaticAttributes enabled
 * @remarks	backupGlobals must be disabled to have db,conf,user and lang not erased.
 */
class LegacySupplierCodeImportTest extends CommonClassTest
{
	/** @var array<string,mixed> */
	private $savedConf = array();

	/**
	 * @return void
	 */
	protected function tearDown(): void
	{
		global $conf;
		foreach ($this->savedConf as $key => $value) {
			if ($value === null) {
				unset($conf->global->$key);
			} else {
				$conf->global->$key = $value;
			}
		}
		$this->savedConf = array();
		parent::tearDown();
	}

	/**
	 * A vendor whose code predates the current numbering mask can still be updated after the import loads it.
	 * @return	void
	 */
	public function testAVendorWithALegacyCodeIsUpdatedAfterTheImportLoadsIt()
	{
		global $db, $user;

		$this->setConf('SOCIETE_CODECLIENT_ADDON', 'mod_codeclient_leopard');
		$vendor = new Societe($db);
		$vendor->name = 'Legacy code vendor';
		$vendor->fournisseur = 1;
		$vendor->code_fournisseur = 'SU2508-00012';
		$this->assertGreaterThan(0, $vendor->create($user), implode(', ', $vendor->errors));

		// The entity then switched to a mask the legacy code does not match.
		$this->setConf('SOCIETE_CODECLIENT_ADDON', 'mod_codeclient_elephant');
		$this->setConf('COMPANY_ELEPHANT_MASK_SUPPLIER', 'F{0000}');

		$loaded = new Societe($db);
		$method = new ReflectionMethod('CIIProtocol', '_fetchThirdpartyForImportUpdate');
		$method->setAccessible(true);
		$this->assertGreaterThan(0, $method->invoke(new CIIProtocol($db), $loaded, $vendor->id));

		$loaded->phone = '0102030405';
		$this->assertGreaterThan(0, $loaded->update(0, $user, 1, 0, 0), implode(', ', $loaded->errors));
	}

	/**
	 * @param	string	$key	Constant name
	 * @param	string	$value	Value for the test
	 * @return	void
	 */
	private function setConf($key, $value)
	{
		global $conf;
		if (!array_key_exists($key, $this->savedConf)) {
			$this->savedConf[$key] = isset($conf->global->$key) ? $conf->global->$key : null;
		}
		$conf->global->$key = $value;
	}
}
