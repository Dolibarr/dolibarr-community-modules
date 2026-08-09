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
 *      \file       test/phpunit/SellerVatRegimeTest.php
 *      \ingroup    test
 *      \brief      PHPUnit test for the tax identifier the seller declares, BT-31 or BT-32.
 *                  EN 16931 satisfies BR-E-02, BR-S-02, BR-Z-02 and BR-AE-02 with either the Seller
 *                  VAT identifier (BT-31, schemeID VA) or the Seller tax registration identifier
 *                  (BT-32, schemeID FC), so a seller that charges no VAT identifies itself with its
 *                  SIREN. A document carrying neither is refused on BR-E-02, which is issue #560.
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
dol_include_once('einvoicing/lib/einvoicing.lib.php');
require_once __DIR__ . '/CommonClassTestCompat.inc.php';

/**
 * Class for PHPUnit tests
 *
 * @backupGlobals disabled
 * @backupStaticAttributes enabled
 * @remarks	backupGlobals must be disabled to have db,conf,user and lang not erased.
 */
class SellerVatRegimeTest extends CommonClassTest
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
	 * Set the module override, the only constant this decision reads.
	 *
	 * @param	string	$forced		Value of the module option, '' to leave it unset
	 * @return	void
	 */
	private function setRegime($forced = '')
	{
		global $conf;

		$key = 'EINVOICING_SELLER_VAT_REGIME';
		if (!array_key_exists($key, $this->savedconstants)) {
			$this->savedconstants[$key] = isset($conf->global->$key) ? $conf->global->$key : null;
		}

		if ($forced === '') {
			unset($conf->global->$key);
		} else {
			$conf->global->$key = $forced;
		}
	}

	/**
	 * A seller, described the way Societe::setMysoc() describes $mysoc.
	 *
	 * @param	int|string	$assuj		->tva_assuj, which the core fills from FACTURE_TVAOPTION
	 * @param	string		$vatNumber	Intra-community VAT number, '' for a company that has none
	 * @param	string		$siren		Professional id 1
	 * @return	Societe
	 */
	private function seller($assuj, $vatNumber, $siren = '000000001')
	{
		global $db;

		$seller = new Societe($db);
		$seller->name = 'Seller';
		$seller->tva_assuj = $assuj;
		$seller->tva_intra = $vatNumber;
		$seller->idprof1 = $siren;

		return $seller;
	}

	/**
	 * Left alone, the module follows what the core made of the company setup: a seller subject to VAT
	 * declares its VAT number, and nothing about the existing documents changes.
	 *
	 * @return void
	 */
	public function testAutomaticFollowsTheCompanySetupWhenSubjectToVat()
	{
		$this->setRegime();
		$seller = $this->seller(1, 'FR87892304189');

		$this->assertSame('standard', einvoicingSellerVatRegime($seller));
		$this->assertSame(
			array(array('type' => 'VA', 'value' => 'FR87892304189')),
			einvoicingSellerTaxRegistrations($seller)
		);
	}

	/**
	 * "VAT is not used" is FACTURE_TVAOPTION 0, which setMysoc() turns into tva_assuj 0. The seller has
	 * no VAT identifier to declare and its SIREN becomes the tax registration identifier, which is what
	 * BR-E-02 accepts in its place.
	 *
	 * @return void
	 */
	public function testAutomaticDeclaresTheSirenWhenNotSubjectToVat()
	{
		$this->setRegime();
		$seller = $this->seller(0, '');

		$this->assertSame('franchise', einvoicingSellerVatRegime($seller));
		$this->assertSame(
			array(array('type' => 'FC', 'value' => '000000001')),
			einvoicingSellerTaxRegistrations($seller)
		);
	}

	/**
	 * setMysoc() does not hand over the same type on every supported core, and the module supports
	 * Dolibarr 17 and up. Up to 19 it assigns the constant as it is read from the database:
	 *
	 *   17 / 18 / 19    $this->tva_assuj = $conf->global->FACTURE_TVAOPTION;   ->  the string '0' / '1'
	 *   20 and above    $this->tva_assuj = getDolGlobalInt('FACTURE_TVAOPTION'); ->  the int 0 / 1
	 *
	 * Both forms are pinned here rather than left to whichever core happens to run the suite, since
	 * the answer must not depend on it.
	 *
	 * @return void
	 */
	public function testBothTypesSetMysocHandsOverAreUnderstood()
	{
		$this->setRegime();

		// Dolibarr 17 to 19.
		$this->assertSame('franchise', einvoicingSellerVatRegime($this->seller('0', '')));
		$this->assertSame('standard', einvoicingSellerVatRegime($this->seller('1', 'FR87892304189')));

		// Dolibarr 20 and above.
		$this->assertSame('franchise', einvoicingSellerVatRegime($this->seller(0, '')));
		$this->assertSame('standard', einvoicingSellerVatRegime($this->seller(1, 'FR87892304189')));

		// And the registration that follows from each, which is the point of the derivation.
		$this->assertSame(
			array(array('type' => 'FC', 'value' => '000000001')),
			einvoicingSellerTaxRegistrations($this->seller('0', ''))
		);
		$this->assertSame(
			array(array('type' => 'VA', 'value' => 'FR87892304189')),
			einvoicingSellerTaxRegistrations($this->seller('1', 'FR87892304189'))
		);
	}

	/**
	 * admin/company.php only ever writes 1 or 0, but the tva_assuj of a thirdparty also holds the
	 * literal forms and get_default_tva() reads them. This must answer the same as the core does.
	 *
	 * @return void
	 */
	public function testTheLiteralFormsAreReadTheWayTheCoreReadsThem()
	{
		$this->setRegime();

		$this->assertSame('franchise', einvoicingSellerVatRegime($this->seller('franchise', '')));
		$this->assertSame('standard', einvoicingSellerVatRegime($this->seller('reel', 'FR87892304189')));
	}

	/**
	 * An explicit value wins over the company setup, for a regime that setup cannot express - the
	 * same contract as EINVOICING_VAT_POINT_DATE_CODE for BT-8.
	 *
	 * @return void
	 */
	public function testAnExplicitRegimeOverridesTheCompanySetup()
	{
		$this->setRegime('franchise');
		$seller = $this->seller(1, 'FR87892304189');

		$this->assertSame('franchise', einvoicingSellerVatRegime($seller));
		$this->assertSame(
			array(array('type' => 'FC', 'value' => '000000001')),
			einvoicingSellerTaxRegistrations($seller)
		);

		$this->setRegime('standard');
		$this->assertSame('standard', einvoicingSellerVatRegime($this->seller(0, 'FR87892304189')));
	}

	/**
	 * A value that means nothing - 'auto', which is what the selector stores for "Automatic", or
	 * anything left over from another version - is not a regime, and the company setup decides.
	 *
	 * @return void
	 */
	public function testAnUnknownExplicitValueFallsBackOnTheCompanySetup()
	{
		$this->setRegime('auto');
		$this->assertSame('franchise', einvoicingSellerVatRegime($this->seller(0, '')));

		$this->setRegime('whatever');
		$this->assertSame('standard', einvoicingSellerVatRegime($this->seller(1, 'FR87892304189')));
	}

	/**
	 * A seller subject to VAT that simply left the field empty must keep getting the explicit
	 * BADVATNUMBER message naming what to fill in. Falling back on its SIREN would build a document
	 * that passes BR-E-02 while stating the company is not registered for VAT, and would hide the
	 * missing field instead of reporting it.
	 *
	 * @return void
	 */
	public function testASellerSubjectToVatWithNoNumberDeclaresNothing()
	{
		$this->setRegime();

		$this->assertSame(array(), einvoicingSellerTaxRegistrations($this->seller(1, '')));
	}

	/**
	 * A company with no professional id recorded has nothing to declare either; the caller must not be
	 * handed an entry with an empty value, which would write an empty ram:ID.
	 *
	 * @return void
	 */
	public function testNoRegistrationIsBuiltWithoutAnIdentifierToPutInIt()
	{
		$this->setRegime();

		$this->assertSame(array(), einvoicingSellerTaxRegistrations($this->seller(0, '', '')));
	}
}
