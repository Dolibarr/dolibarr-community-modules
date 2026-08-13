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
 *      \file       test/phpunit/VatPointDateCodeTest.php
 *      \ingroup    test
 *      \brief      PHPUnit test for the VAT point date code (BT-8) and the VAT on debits mention.
 *                  BR-CL-06 restricts BT-8 to 5 (invoice date), 29 (delivery date) and 72 (payment
 *                  date), and XP Z12-012 annexe A introduces it as the "champ permettant de specifier
 *                  l'option pour le paiement de la taxe d'apres les debits": G1.43 makes it mandatory
 *                  only for a seller who took that option, with the code 5, and BR-FR-MAP-03 repeats
 *                  it for the service invoices. So 5 declares an option rather than a due date, and a
 *                  seller who did not take it sends nothing on a goods invoice. 29 says the same thing
 *                  as 5 and is never derived: BR-FR-MAP-29 states the public portal only expects 5.
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
dol_include_once('einvoicing/lib/einvoicing.lib.php');
require_once __DIR__ . '/CommonClassTestCompat.inc.php';

/**
 * Class for PHPUnit tests
 *
 * @backupGlobals disabled
 * @backupStaticAttributes enabled
 * @remarks	backupGlobals must be disabled to have db,conf,user and lang not erased.
 */
class VatPointDateCodeTest extends CommonClassTest
{
	/** @var array<string,string|null> Constants this test overwrites, as they were before */
	private $savedconstants = array();

	/**
	 * CommonClassTest keeps a reference to $conf, not a copy, so the constants written here would
	 * survive the class and reach whatever runs next. Give them back their initial value.
	 *
	 * @return void
	 */
	protected function tearDown(): void
	{
		global $conf;

		foreach ($this->savedconstants as $key => $value) {
			if ($value === null) {
				unset($conf->global->$key);
			} else {
				$conf->global->$key = $value;
			}
		}
		$this->savedconstants = array();

		parent::tearDown();
	}

	/**
	 * Set the VAT mode of the Tax/VAT module setup, the way its setup page does.
	 *
	 * @param	string	$product	'invoice' or 'payment'
	 * @param	string	$service	'invoice' or 'payment'
	 * @param	string	$forced		Value of the module option, '' to leave it unset
	 * @return	void
	 */
	private function setVatMode($product, $service, $forced = '')
	{
		global $conf;

		foreach (array('TAX_MODE_SELL_PRODUCT', 'TAX_MODE_SELL_SERVICE', 'EINVOICING_VAT_POINT_DATE_CODE') as $key) {
			if (!array_key_exists($key, $this->savedconstants)) {
				$this->savedconstants[$key] = isset($conf->global->$key) ? $conf->global->$key : null;
			}
		}

		$conf->global->TAX_MODE_SELL_PRODUCT = $product;
		$conf->global->TAX_MODE_SELL_SERVICE = $service;

		if ($forced === '') {
			unset($conf->global->EINVOICING_VAT_POINT_DATE_CODE);
		} else {
			$conf->global->EINVOICING_VAT_POINT_DATE_CODE = $forced;
		}
	}

	/**
	 * TAX_MODE 0, the French default. BT-8 is what XP Z12-012 annexe A makes of it, the declaration of
	 * the option for the payment of VAT on debits, so a seller who did not take that option declares
	 * nothing on a goods invoice: it has no option to signal and its VAT falls due on a delivery the
	 * document already dates. A document carrying a service is taxed on collection and says so.
	 *
	 * @return void
	 */
	public function testStandardSchemeDeclaresCollectionOnlyWhenItApplies()
	{
		$this->setVatMode('invoice', 'payment');

		$this->assertSame('', einvoicingVatPointDateCode(true, false));
		$this->assertSame('72', einvoicingVatPointDateCode(false, true));
		$this->assertSame('72', einvoicingVatPointDateCode(true, true));
		$this->assertFalse(einvoicingVatOnDebits());

		$this->assertFalse(einvoicingVatDueOnCollection(true, false));
		$this->assertTrue(einvoicingVatDueOnCollection(false, true));
		$this->assertTrue(einvoicingVatDueOnCollection(true, true));
	}

	/**
	 * TAX_MODE 1, "TVA d'apres les debits": the option is general and prevails over every invoice
	 * issued (G1.43), so every document declares 5 whatever it carries, and carries the legal mention
	 * that goes with the option. BR-FR-MAP-03 makes that mandatory on the service invoices. The down
	 * payment is the exception the socle itself carves out.
	 *
	 * @return void
	 */
	public function testDebitsSchemeDeclaresTheInvoiceDateEverywhere()
	{
		$this->setVatMode('invoice', 'invoice');

		$this->assertSame('5', einvoicingVatPointDateCode(true, false));
		$this->assertSame('5', einvoicingVatPointDateCode(false, true));
		$this->assertSame('72', einvoicingVatPointDateCode(true, false, true));
		$this->assertTrue(einvoicingVatOnDebits());

		$this->assertFalse(einvoicingVatDueOnCollection(true, false));
		$this->assertFalse(einvoicingVatDueOnCollection(false, true));
	}

	/**
	 * TAX_MODE 2: everything falls due on collection.
	 *
	 * @return void
	 */
	public function testEverythingOnPaymentDeclaresThePaymentDate()
	{
		$this->setVatMode('payment', 'payment');

		$this->assertSame('72', einvoicingVatPointDateCode(true, false));
		$this->assertSame('72', einvoicingVatPointDateCode(false, true));
		$this->assertFalse(einvoicingVatOnDebits());

		$this->assertTrue(einvoicingVatDueOnCollection(true, false));
		$this->assertTrue(einvoicingVatDueOnCollection(false, false));
	}

	/**
	 * "La TVA est exigible a l'encaissement de l'acompte pour les livraisons de biens comme pour les
	 * prestations de service, meme avec option sur les debits" (XP Z12-014 annexe A). So a down payment
	 * declares the payment date whatever the VAT mode and whatever regime the seller declared, and
	 * Dolibarr building every down payment line as a goods line is why it has to be said explicitly.
	 *
	 * @return void
	 */
	public function testDownPaymentAlwaysDeclaresTheCollection()
	{
		$this->setVatMode('invoice', 'payment');
		$this->assertSame('72', einvoicingVatPointDateCode(true, false, true));

		$this->setVatMode('payment', 'payment');
		$this->assertSame('72', einvoicingVatPointDateCode(true, false, true));

		$this->setVatMode('invoice', 'invoice');
		$this->assertSame('72', einvoicingVatPointDateCode(true, false, true));

		$this->setVatMode('invoice', 'payment', '5');
		$this->assertSame('72', einvoicingVatPointDateCode(true, false, true));

		$this->setVatMode('invoice', 'payment', '29');
		$this->assertSame('72', einvoicingVatPointDateCode(false, true, true));
	}

	/**
	 * A document whose lines are all pseudo-lines (title, subtotal, page break) leaves both flags
	 * false, and there is then no operation taxed on collection to declare.
	 *
	 * @return void
	 */
	public function testDocumentWithoutAnyTaxedLineDeclaresNothing()
	{
		$this->setVatMode('invoice', 'payment');
		$this->assertSame('', einvoicingVatPointDateCode(false, false));
		$this->assertFalse(einvoicingVatDueOnCollection(false, false));
	}

	/**
	 * An explicit regime wins over the VAT mode, whatever the document carries and whatever it is:
	 * it is a statement about the seller. 29 is only reachable this way, the automatic derivation
	 * never producing it, and it declares the debits option just like 5 does.
	 *
	 * @return void
	 */
	public function testExplicitRegimeOverridesTheVatMode()
	{
		$this->setVatMode('invoice', 'payment', '5');
		$this->assertSame('5', einvoicingVatPointDateCode(false, true));
		$this->assertSame('5', einvoicingVatPointDateCode(true, true));
		$this->assertTrue(einvoicingVatOnDebits());
		$this->assertFalse(einvoicingVatDueOnCollection(false, true));

		$this->setVatMode('invoice', 'invoice', '72');
		$this->assertSame('72', einvoicingVatPointDateCode(true, false));
		$this->assertFalse(einvoicingVatOnDebits());
		$this->assertTrue(einvoicingVatDueOnCollection(true, false));

		$this->setVatMode('invoice', 'payment', '29');
		$this->assertSame('29', einvoicingVatPointDateCode(true, false));
		$this->assertSame('29', einvoicingVatPointDateCode(false, true));
		$this->assertTrue(einvoicingVatOnDebits());
		$this->assertFalse(einvoicingVatDueOnCollection(false, true));
	}

	/**
	 * "Automatic", and any value outside the code list BR-CL-06 restricts BT-8 to, leaves the
	 * decision to the VAT mode instead of reaching the document.
	 *
	 * @return void
	 */
	public function testAutomaticAndUnknownValuesFallBackToTheVatMode()
	{
		$this->setVatMode('invoice', 'payment', 'auto');
		$this->assertSame('72', einvoicingVatPointDateCode(false, true));
		$this->assertSame('', einvoicingVatPointDateCode(true, false));

		$this->setVatMode('invoice', 'invoice', '3');		// UBL code list, not the CII one
		$this->assertSame('5', einvoicingVatPointDateCode(true, false));
		$this->assertTrue(einvoicingVatOnDebits());
	}
}
