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
require_once DOL_DOCUMENT_ROOT . '/core/class/html.form.class.php';
require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT . '/user/class/user.class.php';
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
		unset($_POST['routing_product_id_shown']);
		unset($_GET['routing_product_id']);
		unset($_GET['routing_product_id_shown']);

		parent::tearDown();
	}

	/**
	 * A user that owns a row of its own, to create the objects of the test with.
	 *
	 * The global $user of the suite does not always hold one, and Dolibarr 18 declares a foreign key
	 * on the author of a product price: the creation would then fail on the database instead of
	 * telling anything about the module.
	 *
	 * @return User		Author of the objects created by the test
	 */
	private function author()
	{
		global $db;

		$resql = $db->query('SELECT rowid FROM ' . $db->prefix() . 'user ORDER BY rowid ASC LIMIT 1');
		$obj = ($resql ? $db->fetch_object($resql) : null);
		$this->assertNotEmpty($obj, 'No user in the base to create the objects of the test with');

		$author = new User($db);
		$author->fetch($obj->rowid);

		return $author;
	}

	/**
	 * A vendor holding a default product for the import of its invoices.
	 *
	 * @param	string	$productRouting	Value posted by the combo for the default product
	 * @return	int						Id of the created thirdparty
	 */
	private function createVendorWithDefaultProduct($productRouting)
	{
		global $db;

		$thirdparty = new Societe($db);
		$thirdparty->name = 'Vendor of the default product test';
		$thirdparty->country_code = 'FR';
		$thirdparty->fournisseur = 1;
		$thirdparty->code_fournisseur = 'auto';

		$socid = $thirdparty->create($this->author());
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
	 * @param	string		$shown		Value the field held when it was drawn (routing_product_id_shown)
	 * @return	string|int				Default product of the vendor once saved
	 */
	private function saveThirdparty($socid, $posted, $shown = '')
	{
		global $db, $user, $langs, $conf;

		if ($posted === null) {
			unset($_POST['routing_product_id']);
			unset($_POST['routing_product_id_shown']);
		} else {
			$_POST['routing_product_id'] = $posted;
			$_POST['routing_product_id_shown'] = $shown;
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

		$this->assertEquals(0, $this->saveThirdparty($socid, '-1', 'idprod_1234'), 'The default product survived the empty entry of the combo');
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

		$this->assertEquals(0, $this->saveThirdparty($socid, '', 'idprod_1234'), 'The default product survived the cleared search field');
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

		$this->assertEquals('idprod_5678', $this->saveThirdparty($socid, 'idprod_5678', 'idprod_1234'), 'The default product was not replaced');
	}

	/**
	 * A save whose combo could not show the current value - the default product of the vendor is not
	 * among the products the combo lists - must not be read as a removal.
	 *
	 * @return void
	 */
	public function testEmptyFieldThatShowedNothingKeepsTheDefaultProduct()
	{
		$socid = $this->createVendorWithDefaultProduct('idprod_1234');

		$this->assertEquals('idprod_1234', $this->saveThirdparty($socid, '-1', ''), 'A field that showed nothing was read as a removal');
	}

	/**
	 * The combo has to show the default product of the vendor as its selected entry. Before Dolibarr 22
	 * the core only marks an option whose value is the id of a supplier price, so a product without one
	 * ('idprod_ID') came back unmarked: the field showed nothing, and the save that follows would then
	 * read an untouched field as a removal.
	 *
	 * @return void
	 */
	public function testTheComboShowsTheDefaultProductOfTheVendor()
	{
		global $db, $conf;

		$author = $this->author();

		$conf->global->PRODUIT_USE_SEARCH_TO_SELECT = 0;		// The combo, not the ajax search field

		$thirdparty = new Societe($db);
		$thirdparty->name = 'Vendor of the combo test';
		$thirdparty->country_code = 'FR';
		$thirdparty->fournisseur = 1;
		$thirdparty->code_fournisseur = 'auto';
		$socid = $thirdparty->create($author);
		$this->assertGreaterThan(0, $socid, 'Could not create the vendor of the test: ' . $thirdparty->error . ' ' . implode(', ', $thirdparty->errors));

		// A product to buy with no supplier price of its own: this is the case the cores mishandle
		$product = new Product($db);
		$product->ref = 'EINV791' . dol_print_date(dol_now(), '%y%m%d%H%M%S');
		$product->label = 'Product of the combo test';
		$product->type = 0;
		$product->status = 0;
		$product->status_buy = 1;
		$pid = $product->create($author);
		$this->assertGreaterThan(0, $pid, 'Could not create the product of the test: ' . $product->error . ' ' . implode(', ', $product->errors));

		$method = new ReflectionMethod(EInvoicing::class, 'selectVendorProduct');
		$method->setAccessible(true);

		// Dolibarr 18 and 19 read $objp->barcode in that combo although their own query only selects it
		// when the barcode module is on. The notice belongs to the core, but PHPUnit turns it into a
		// failure: silence that one, nothing else.
		set_error_handler(
			/**
			 * @param	int		$errno	Level of the error
			 * @param	string	$errstr	Message of the error
			 * @return	bool			True to swallow the error, false to hand it back to PHP
			 */
			static function ($errno, $errstr) {
				return strpos($errstr, 'barcode') !== false;
			},
			E_WARNING | E_NOTICE
		);
		try {
			$out = $method->invoke(new EInvoicing($db), new Form($db), $socid, 'idprod_' . $pid, 'routing_product_id');
		} finally {
			restore_error_handler();
		}

		$this->assertStringContainsString('<option value="idprod_' . $pid . '" selected', $out, 'The combo does not show the default product of the vendor');
		$this->assertStringNotContainsString('<option value="-1" selected', $out, 'The combo shows its empty entry although a default product is set');
		$this->assertStringContainsString('name="routing_product_id_shown" value="idprod_' . $pid . '"', $out, 'The combo does not tell the save what it shows');
	}

	/**
	 * A vendor with no default product yet gets one on the first save, and stays without one when the
	 * empty entry is left as it is.
	 *
	 * @return void
	 */
	public function testFirstSaveOfADefaultProduct()
	{
		global $db;

		$thirdparty = new Societe($db);
		$thirdparty->name = 'Vendor without default product';
		$thirdparty->country_code = 'FR';
		$thirdparty->fournisseur = 1;
		$thirdparty->code_fournisseur = 'auto';
		$socid = $thirdparty->create($this->author());
		$this->assertGreaterThan(0, $socid, 'Could not create the vendor of the test: ' . $thirdparty->error . ' ' . implode(', ', $thirdparty->errors));

		$this->assertEquals(0, $this->saveThirdparty($socid, '-1', ''), 'A vendor without default product got one out of the empty entry');
		$this->assertEquals('idprod_1234', $this->saveThirdparty($socid, 'idprod_1234', ''), 'The default product was not stored on the first save');
	}
}
