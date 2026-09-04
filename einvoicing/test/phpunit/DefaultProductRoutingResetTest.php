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
 *      \file       test/phpunit/DefaultProductRoutingResetTest.php
 *      \ingroup    test
 *      \brief      PHPUnit test for the removal of the default product of a vendor.
 *                  The combo built by Form::select_produits_fournisseurs_list() posts '-1' when its
 *                  empty entry is picked, and the ajax variant posts '' when the search input is
 *                  cleared. The trigger used to skip both values, so the default product of a vendor
 *                  could be changed but never removed once set.
 *      \remarks    To run this script as CLI: phpunit filename.php
 */

global $conf, $user, $langs, $db;

// See VatPointDateCodeTest for why DOLIBARR_HTDOCS is honoured before the relative path.
$dolibarrHtdocs = getenv('DOLIBARR_HTDOCS');
if (!$dolibarrHtdocs) {
	$dolibarrHtdocs = dirname(__FILE__) . '/../../htdocs';
}
if (!file_exists($dolibarrHtdocs . '/master.inc.php')) {
	throw new \RuntimeException('Could not locate master.inc.php under "' . $dolibarrHtdocs . '/". Set the environment variable (export DOLIBARR_HTDOCS=...) to the htdocs directory of the Dolibarr instance to test against.');
}

require_once $dolibarrHtdocs . '/master.inc.php';
require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
// Societe::create() calls getCountry() on some cores (Dolibarr 21), and master.inc.php does not
// load company.lib.php: without this the test errors out on an undefined function, not on the module.
require_once DOL_DOCUMENT_ROOT . '/core/lib/company.lib.php';
dol_include_once('einvoicing/class/einvoicing.class.php');
dol_include_once('einvoicing/core/triggers/interface_98_modEInvoicing_EInvoicingTriggers.class.php');
require_once __DIR__ . '/CommonClassTestCompat.inc.php';

$conf->global->MAIN_DISABLE_ALL_MAILS = 1;


/**
 * Class for PHPUnit tests
 *
 * @backupGlobals disabled
 * @backupStaticAttributes enabled
 * @remarks	backupGlobals must be disabled to have db,conf,user and lang not erased.
 */
class DefaultProductRoutingResetTest extends CommonClassTest
{
	/**
	 * Forget the field of the form between two tests, so a test never reads what another one posted.
	 *
	 * @return void
	 */
	protected function tearDown(): void
	{
		unset($_POST['routing_product_id']);
		unset($_GET['routing_product_id']);

		parent::tearDown();
	}

	/**
	 * A vendor holding a default product for the import of its invoices.
	 *
	 * @param	string	$productRouting	Value posted by the combo for the default product
	 * @return	int						Id of the created thirdparty
	 */
	private function createVendorWithDefaultProduct($productRouting)
	{
		global $db, $user;

		$thirdparty = new Societe($db);
		$thirdparty->name = 'Vendor of the default product test';
		$thirdparty->country_code = 'FR';
		$thirdparty->fournisseur = 1;
		$thirdparty->code_fournisseur = 'auto';

		$socid = $thirdparty->create($user);
		$this->assertGreaterThan(0, $socid, 'Could not create the vendor of the test: ' . $thirdparty->error . ' ' . implode(', ', $thirdparty->errors));

		$einvoicing = new EInvoicing($db);
		$this->assertGreaterThan(0, $einvoicing->addRouting($socid, $productRouting, '', 'product'), 'Could not set the default product of the test: ' . $einvoicing->error);
		$this->assertEquals($productRouting, $einvoicing->fetchDefaultRouting($socid, 'product'), 'The default product of the test was not stored');

		return $socid;
	}

	/**
	 * Save the thirdparty the way the card does, with the field of the form filled as given.
	 *
	 * @param	int			$socid		Id of the thirdparty
	 * @param	string|null	$posted		Value posted for routing_product_id, null to not post the field
	 * @return	string|int				Default product of the vendor once saved
	 */
	private function saveThirdparty($socid, $posted)
	{
		global $db, $user, $langs, $conf;

		if ($posted === null) {
			unset($_POST['routing_product_id']);
		} else {
			$_POST['routing_product_id'] = $posted;
		}

		$thirdparty = new Societe($db);
		$thirdparty->fetch($socid);

		$trigger = new InterfaceEInvoicingTriggers($db);
		$res = $trigger->runTrigger('COMPANY_MODIFY', $thirdparty, $user, $langs, $conf);
		$this->assertGreaterThanOrEqual(0, $res, 'The trigger refused the save: ' . implode(', ', $trigger->errors));

		$einvoicing = new EInvoicing($db);

		return $einvoicing->fetchDefaultRouting($socid, 'product');
	}

	/**
	 * Picking the empty entry of the combo removes the default product of the vendor.
	 *
	 * @return void
	 */
	public function testEmptyEntryOfTheComboRemovesTheDefaultProduct()
	{
		$socid = $this->createVendorWithDefaultProduct('idprod_1234');

		$this->assertEquals(0, $this->saveThirdparty($socid, '-1'), 'The default product survived the empty entry of the combo');
	}

	/**
	 * Clearing the ajax search field (PRODUIT_USE_SEARCH_TO_SELECT) posts an empty value, and removes
	 * the default product just as the empty entry of the combo does.
	 *
	 * @return void
	 */
	public function testClearedSearchFieldRemovesTheDefaultProduct()
	{
		$socid = $this->createVendorWithDefaultProduct('idprod_1234');

		$this->assertEquals(0, $this->saveThirdparty($socid, ''), 'The default product survived the cleared search field');
	}

	/**
	 * A save that does not carry the field at all (REST API, mass action, import...) must leave the
	 * default product of the vendor untouched.
	 *
	 * @return void
	 */
	public function testSaveWithoutTheFieldKeepsTheDefaultProduct()
	{
		$socid = $this->createVendorWithDefaultProduct('idprod_1234');

		$this->assertEquals('idprod_1234', $this->saveThirdparty($socid, null), 'A save without the field lost the default product');
	}

	/**
	 * Replacing the default product with another one still works.
	 *
	 * @return void
	 */
	public function testAnotherProductReplacesTheDefaultProduct()
	{
		$socid = $this->createVendorWithDefaultProduct('idprod_1234');

		$this->assertEquals('idprod_5678', $this->saveThirdparty($socid, 'idprod_5678'), 'The default product was not replaced');
	}

	/**
	 * A vendor with no default product yet gets one on the first save, and stays without one when the
	 * empty entry is left as it is.
	 *
	 * @return void
	 */
	public function testFirstSaveOfADefaultProduct()
	{
		global $db, $user;

		$thirdparty = new Societe($db);
		$thirdparty->name = 'Vendor without default product';
		$thirdparty->country_code = 'FR';
		$thirdparty->fournisseur = 1;
		$thirdparty->code_fournisseur = 'auto';
		$socid = $thirdparty->create($user);
		$this->assertGreaterThan(0, $socid, 'Could not create the vendor of the test: ' . $thirdparty->error . ' ' . implode(', ', $thirdparty->errors));

		$this->assertEquals(0, $this->saveThirdparty($socid, '-1'), 'A vendor without default product got one out of the empty entry');
		$this->assertEquals('idprod_1234', $this->saveThirdparty($socid, 'idprod_1234'), 'The default product was not stored on the first save');
	}
}
