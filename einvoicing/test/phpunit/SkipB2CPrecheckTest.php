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
 *      \file       test/phpunit/SkipB2CPrecheckTest.php
 *      \ingroup    test
 *      \brief      PHPUnit test for the third party pre-check when EINVOICING_SKIP_B2C is on, issue #600.
 *                  A private individual has no professional id, and with that option on the invoice is
 *                  already out of the e-invoicing scope (needEInvoiceManagement() answers STATUS_IGNORE):
 *                  the pre-check must not report the missing SIREN as a blocking configuration error.
 *                  The detection of a private individual is Societe::isACompany(), the same one the
 *                  decision uses, so both ends of the chain agree on who is B2C.
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
dol_include_once('einvoicing/class/einvoicing.class.php');
require_once __DIR__ . '/CommonClassTestCompat.inc.php';

$conf->global->MAIN_DISABLE_ALL_MAILS = 1;


/**
 * Class for PHPUnit tests
 *
 * @backupGlobals disabled
 * @backupStaticAttributes enabled
 * @remarks	backupGlobals must be disabled to have db,conf,user and lang not erased.
 */
class SkipB2CPrecheckTest extends CommonClassTest
{
	/**
	 * A French third party carrying no professional id at all, described the way a customer card
	 * records a private individual: nature "Particulier" (TE_PRIVATE), no SIREN, no SIRET, no VAT
	 * number. Societe::isACompany() answers 0 on it.
	 *
	 * @return	Societe
	 */
	private function privateIndividual()
	{
		global $db;

		$thirdparty = new Societe($db);
		$thirdparty->name = 'Particulier';
		$thirdparty->typent_code = 'TE_PRIVATE';
		$thirdparty->country_code = 'FR';
		$thirdparty->address = '3 rue des Particuliers';
		$thirdparty->zip = '86000';
		$thirdparty->town = 'Poitiers';
		$thirdparty->tva_assuj = 0;

		return $thirdparty;
	}

	/**
	 * The same customer card, but for a company that has not been given its SIREN: it declares a VAT
	 * number, which is what makes isACompany() answer "company" on every supported core - up to
	 * Dolibarr 19 a third party carrying neither a VAT number nor a professional id is never one,
	 * whatever its nature says, so a fixture relying on the nature alone would not be portable.
	 *
	 * @return	Societe
	 */
	private function companyWithoutProfessionalId()
	{
		$thirdparty = $this->privateIndividual();
		$thirdparty->name = 'Company';
		$thirdparty->typent_code = '';
		$thirdparty->tva_assuj = 1;
		$thirdparty->tva_intra = 'FR11123456782';

		return $thirdparty;
	}

	/** @var array<string,string> Core options this test pins, saved as they were found */
	private $savedoptions = array();

	/**
	 * Pin the two core options Societe::isACompany() reads besides the identifiers and the nature of
	 * the third party, so the fixtures mean the same thing on every instance the suite runs against:
	 * MAIN_CUSTOMERS_ARE_COMPANIES_EVEN_IF_SET_AS_INDIVIDUAL would make a company of the private
	 * individual, and MAIN_UNKNOWN_CUSTOMERS_ARE_COMPANIES decides for a third party whose nature is
	 * left unknown.
	 *
	 * @return void
	 */
	protected function setUp(): void
	{
		global $conf;

		parent::setUp();

		foreach (array('MAIN_UNKNOWN_CUSTOMERS_ARE_COMPANIES', 'MAIN_CUSTOMERS_ARE_COMPANIES_EVEN_IF_SET_AS_INDIVIDUAL') as $option) {
			$this->savedoptions[$option] = getDolGlobalString($option);
		}
		$conf->global->MAIN_UNKNOWN_CUSTOMERS_ARE_COMPANIES = 1;
		unset($conf->global->MAIN_CUSTOMERS_ARE_COMPANIES_EVEN_IF_SET_AS_INDIVIDUAL);
	}

	/**
	 * Reset the options after each test, whatever the test did with them.
	 *
	 * @return void
	 */
	protected function tearDown(): void
	{
		global $conf;

		unset($conf->global->EINVOICING_SKIP_B2C);
		foreach ($this->savedoptions as $option => $value) {
			if ($value === '') {
				unset($conf->global->$option);
			} else {
				$conf->global->$option = $value;
			}
		}

		parent::tearDown();
	}

	/**
	 * The bug of issue #600: with the option off, nothing changes and a missing professional id stays
	 * the blocking error it has always been. This is also what the fix must keep doing for everybody
	 * who never turns the option on.
	 *
	 * @return void
	 */
	public function testTheMissingProfessionalIdStillBlocksWhenTheOptionIsOff()
	{
		global $conf, $db, $langs;

		unset($conf->global->EINVOICING_SKIP_B2C);
		$langs->load("einvoicing@einvoicing");

		$einvoicing = new EInvoicing($db);
		$check = $einvoicing->validatethirdpartyConfiguration($this->privateIndividual());

		$this->assertSame(-1, $check['res']);
		$this->assertStringContainsString($langs->trans("FxCheckErrorCustomerIDPROF1"), $check['message']);
	}

	/**
	 * With the option on, the very same third party is out of scope and its missing SIREN is not an
	 * error any more: nothing blocks the invoice, and nothing is reported about a professional id a
	 * private individual is not supposed to have.
	 *
	 * @return void
	 */
	public function testTheMissingProfessionalIdDoesNotBlockAB2CThirdPartyWhenTheOptionIsOn()
	{
		global $conf, $db, $langs;

		$conf->global->EINVOICING_SKIP_B2C = 1;
		$langs->load("einvoicing@einvoicing");

		$thirdparty = $this->privateIndividual();
		// Cast: isACompany() answers 0 on some cores and false on others (Dolibarr 20 to 22), and this
		// test is about who is a company, not about which of the two the core happens to return.
		$this->assertSame(0, (int) $thirdparty->isACompany(), 'the fixture must be seen as a private individual');

		$einvoicing = new EInvoicing($db);
		$check = $einvoicing->validatethirdpartyConfiguration($thirdparty);

		$this->assertNotSame(-1, $check['res']);
		$this->assertStringNotContainsString($langs->trans("FxCheckErrorCustomerIDPROF1"), $check['message']);
	}

	/**
	 * The option only excuses private individuals. A company without a professional id is still a
	 * company that cannot be e-invoiced, whatever the option says, so its error must survive.
	 *
	 * @return void
	 */
	public function testACompanyWithoutProfessionalIdStillBlocksWhenTheOptionIsOn()
	{
		global $conf, $db, $langs;

		$conf->global->EINVOICING_SKIP_B2C = 1;
		$langs->load("einvoicing@einvoicing");

		$thirdparty = $this->companyWithoutProfessionalId();
		$this->assertNotSame(0, (int) $thirdparty->isACompany(), 'the fixture must be seen as a company');

		$einvoicing = new EInvoicing($db);
		$check = $einvoicing->validatethirdpartyConfiguration($thirdparty);

		$this->assertSame(-1, $check['res']);
		$this->assertStringContainsString($langs->trans("FxCheckErrorCustomerIDPROF1"), $check['message']);
	}

	/**
	 * The routing id is the other identifier a private individual has no reason to own: with
	 * EINVOICING_BLOCK_INVOICE_NO_ROUTING_ID on, a B2C third party must not be blocked on it either,
	 * while a company still is.
	 *
	 * @return void
	 */
	public function testTheRoutingIdDoesNotBlockAB2CThirdPartyEither()
	{
		global $conf, $db, $langs;

		$conf->global->EINVOICING_SKIP_B2C = 1;
		$savblock = getDolGlobalString('EINVOICING_BLOCK_INVOICE_NO_ROUTING_ID');
		$conf->global->EINVOICING_BLOCK_INVOICE_NO_ROUTING_ID = 1;
		$langs->load("einvoicing@einvoicing");

		$einvoicing = new EInvoicing($db);
		$b2c = $einvoicing->validatethirdpartyConfiguration($this->privateIndividual());
		$company = $einvoicing->validatethirdpartyConfiguration($this->companyWithoutProfessionalId());

		if ($savblock === '') {
			unset($conf->global->EINVOICING_BLOCK_INVOICE_NO_ROUTING_ID);
		} else {
			$conf->global->EINVOICING_BLOCK_INVOICE_NO_ROUTING_ID = $savblock;
		}

		$this->assertStringNotContainsString($langs->trans("FxCheckErrorCustomerRoutingID"), $b2c['message']);
		$this->assertStringContainsString($langs->trans("FxCheckErrorCustomerRoutingID"), $company['message']);
	}

	/**
	 * The two ignore codes are what needEInvoiceManagement() answers for an invoice out of the
	 * e-invoicing scope, and both are truthy: a caller that only tests the answer for truth treats
	 * them as "to be e-invoiced". That is what let an ignored invoice reach the pre-check in the first
	 * place, and the callers now ask isIgnoredStatus() instead, which reads the STATUS_IGNORE_CODES
	 * list - so a new ignore code added there is honoured by all of them at once.
	 *
	 * @return void
	 */
	public function testTheIgnoreCodesAreTruthySoTheyMustBeComparedNotTested()
	{
		$this->assertTrue((bool) EInvoicing::STATUS_IGNORE);
		$this->assertTrue((bool) EInvoicing::STATUS_IGNORE_2);
		$this->assertNotSame(EInvoicing::STATUS_NOT_GENERATED, EInvoicing::STATUS_IGNORE);

		$this->assertSame(
			array(EInvoicing::STATUS_IGNORE, EInvoicing::STATUS_IGNORE_2),
			EInvoicing::STATUS_IGNORE_CODES,
			'every ignore code must be listed, that list is what the callers read'
		);
		foreach (EInvoicing::STATUS_IGNORE_CODES as $code) {
			$this->assertTrue(EInvoicing::isIgnoredStatus($code));
			// The stored status read from the database is a string: the helper must answer the same on it.
			$this->assertTrue(EInvoicing::isIgnoredStatus((string) $code));
		}
		$this->assertFalse(EInvoicing::isIgnoredStatus(EInvoicing::STATUS_NOT_GENERATED));
		$this->assertFalse(EInvoicing::isIgnoredStatus(EInvoicing::STATUS_GENERATED));
	}
}
