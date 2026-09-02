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
 *      \file       test/phpunit/VatCategoryFromVatCodeTest.php
 *      \ingroup    test
 *      \brief      PHPUnit test for the VAT category of a line, BT-151.
 *                  A rate of 0 covers Z, E, AE, G and K alike, so the category is read from the VAT
 *                  code the line carries and never deduced from the rate: the code is the only place
 *                  the regime is stated. What the dictionary does not state, the module refuses to
 *                  invent - it names the code it could not translate instead.
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
dol_include_once('einvoicing/class/protocols/CIIProtocol.class.php');
require_once __DIR__ . '/CommonClassTestCompat.inc.php';

/**
 * Class for PHPUnit tests
 *
 * @backupGlobals disabled
 * @backupStaticAttributes enabled
 * @remarks	backupGlobals must be disabled to have db,conf,user and lang not erased.
 */
class VatCategoryFromVatCodeTest extends CommonClassTest
{
	/**
	 * An invoice line, reduced to what getCategoryRate() reads on it.
	 *
	 * @param	float|string	$rate			->tva_tx
	 * @param	string			$vatCode		->vat_src_code, the code of the VAT dictionary line used
	 * @return	stdClass
	 */
	private function line($rate, $vatCode)
	{
		$line = new stdClass();
		$line->id = 310;
		$line->tva_tx = $rate;
		$line->vat_src_code = $vatCode;
		$line->info_bits = 0;

		return $line;
	}

	/**
	 * A seller, described the way Societe::setMysoc() describes $mysoc.
	 *
	 * @param	string	$vatNumber	Intra-community VAT number, '' for a company that has none
	 * @param	string	$siren		Professional id 1
	 * @return	Societe
	 */
	private function seller($vatNumber = 'FR75911270304', $siren = '911270304')
	{
		global $db;

		$seller = new Societe($db);
		$seller->name = 'Seller';
		$seller->tva_assuj = 1;
		$seller->tva_intra = $vatNumber;
		$seller->idprof1 = $siren;
		$seller->country_code = 'FR';

		return $seller;
	}

	/**
	 * The invoice the line belongs to, whose ->thirdparty is the buyer: that is what the single caller
	 * of getCategoryRate() hands over.
	 *
	 * @param	string	$vatNumber	Intra-community VAT number of the customer
	 * @param	string	$siren		Professional id 1 of the customer
	 * @return	stdClass
	 */
	private function invoiceOf($vatNumber = 'FR16384322020', $siren = '384322020')
	{
		global $db;

		$buyer = new Societe($db);
		$buyer->name = 'Customer';
		$buyer->tva_intra = $vatNumber;
		$buyer->idprof1 = $siren;
		$buyer->country_code = 'FR';

		$invoice = new stdClass();
		$invoice->thirdparty = $buyer;

		return $invoice;
	}

	/**
	 * Read the category the code declares, on its own.
	 *
	 * @param	string	$vatCode	Code of a VAT dictionary line
	 * @return	string
	 */
	private function categoryOfCode($vatCode)
	{
		global $db;

		$method = new ReflectionMethod(CIIProtocol::class, '_getVatCategoryFromVatCode');
		$method->setAccessible(true);

		return $method->invoke(new CIIProtocol($db), $vatCode);
	}

	/**
	 * The segment before the first dash is the category, which is why a dictionary can grow a second
	 * reverse charge regime - 'AE-IC' for the intra-community one - without a line of code being
	 * written for it. Case and spacing of the dictionary are not the operator's problem.
	 *
	 * @return void
	 */
	public function testTheSegmentBeforeTheDashIsTheCategory()
	{
		$this->assertSame('AE', $this->categoryOfCode('AE'));
		$this->assertSame('AE', $this->categoryOfCode('AE-IC'));
		$this->assertSame('AE', $this->categoryOfCode(' ae '));
		$this->assertSame('G', $this->categoryOfCode('G'));
		$this->assertSame('E', $this->categoryOfCode('E-CGI261-4'));
		$this->assertSame('K', $this->categoryOfCode('K'));
		$this->assertSame('Z', $this->categoryOfCode('Z'));
	}

	/**
	 * Nothing else is read as a category. A line with no code says nothing about its regime, and the
	 * codes an existing installation already holds - a VATEX identifier written in the code column, the
	 * way the module documents it - must keep going through the rules that read them as such, or the
	 * category of those documents would silently become 'VATEX'.
	 *
	 * @return void
	 */
	public function testNothingElseIsReadAsACategory()
	{
		$this->assertSame('', $this->categoryOfCode(''));
		$this->assertSame('', $this->categoryOfCode('VATEX-FR-CGI261-4'));
		$this->assertSame('', $this->categoryOfCode('VATEX-EU-AE'));
		$this->assertSame('', $this->categoryOfCode('NPR'));

		// Outside the scope of VAT, and the two Spanish territories: each answers to rules the rest of
		// the document does not honour yet, so the code is not taken at its word.
		$this->assertSame('', $this->categoryOfCode('O'));
		$this->assertSame('', $this->categoryOfCode('L'));
		$this->assertSame('', $this->categoryOfCode('M'));
	}

	/**
	 * A line coded reverse charge is issued reverse charge. The rate says nothing here: it is 0 for Z,
	 * E, AE, G and K alike.
	 *
	 * @return void
	 */
	public function testAReverseChargeLineIsIssuedReverseCharge()
	{
		$protocol = new CIIProtocol($GLOBALS['db']);

		$result = $protocol->getCategoryRate($this->line(0, 'AE'), $this->seller(), $this->invoiceOf());

		$this->assertSame('AE', $result['categoryVAT']);
		$this->assertNotEmpty($result['ExemptionReason']);
	}

	/**
	 * A French seller quotes the French code of the reverse charge: VATEX-FR-AE names article 283 of the
	 * CGI, which is the article the mention carried by the invoice has to name, where VATEX-EU-AE says
	 * "reverse charge" and nothing else. A seller of another member state has that one.
	 *
	 * @return void
	 */
	public function testTheReverseChargeCodeFollowsTheCountryOfTheSeller()
	{
		$protocol = new CIIProtocol($GLOBALS['db']);

		$french = $protocol->getCategoryRate($this->line(0, 'AE'), $this->seller(), $this->invoiceOf());
		$this->assertSame('VATEX-FR-AE', $french['ExemptionReasonCode']);

		$seller = $this->seller('BE0123456789', '0123456789');
		$seller->country_code = 'BE';
		$belgian = $protocol->getCategoryRate($this->line(0, 'AE'), $seller, $this->invoiceOf());
		$this->assertSame('VATEX-EU-AE', $belgian['ExemptionReasonCode']);
	}

	/**
	 * BR-AE-05: reverse charge is only ever invoiced at a rate of zero. A line stating the code on a
	 * taxed rate contradicts its own dictionary entry, and both answers - honouring the code, honouring
	 * the rate - build a document the Schematron refuses. It is reported instead.
	 *
	 * @return void
	 */
	public function testAReverseChargeCodeOnATaxedRateIsRefused()
	{
		$protocol = new CIIProtocol($GLOBALS['db']);

		$this->expectException(Exception::class);
		$this->expectExceptionMessageMatches('/BR-AE-05/');

		$protocol->getCategoryRate($this->line(20, 'AE'), $this->seller(), $this->invoiceOf());
	}

	/**
	 * BR-Z-09 and BR-Z-10: a zero rated line is taxed, at zero. It carries no exemption reason at all,
	 * where an exempt line must carry one.
	 *
	 * @return void
	 */
	public function testAZeroRatedLineCarriesNoExemptionReason()
	{
		$protocol = new CIIProtocol($GLOBALS['db']);

		$result = $protocol->getCategoryRate($this->line(0, 'Z'), $this->seller(), $this->invoiceOf());

		$this->assertSame('Z', $result['categoryVAT']);
		$this->assertNull($result['ExemptionReason']);
		$this->assertNull($result['ExemptionReasonCode']);
	}

	/**
	 * BR-AE-02 and BR-AE-03: the tax of a reverse charge line is declared by the party that receives the
	 * supply, so the document names both parties. Reported here, the message names the record to
	 * complete; left to the Schematron it comes back from the platform as a rejected document.
	 *
	 * @return void
	 */
	public function testAReverseChargeLineNamesBothParties()
	{
		$protocol = new CIIProtocol($GLOBALS['db']);

		try {
			$protocol->getCategoryRate($this->line(0, 'AE'), $this->seller('', ''), $this->invoiceOf());
			$this->fail('A seller identified by neither a VAT number nor a professional id must be reported.');
		} catch (Exception $e) {
			$this->assertStringContainsString('BR-AE-02', $e->getMessage());
		}

		try {
			$protocol->getCategoryRate($this->line(0, 'AE'), $this->seller(), $this->invoiceOf('', ''));
			$this->fail('A customer identified by neither a VAT number nor a legal registration id must be reported.');
		} catch (Exception $e) {
			$this->assertStringContainsString('BR-AE-03', $e->getMessage());
		}
	}

	/**
	 * A taxed line has no exemption reason and needs no code: the rules below the category decide, and
	 * the documents of every installation that never coded a rate keep being built the way they were.
	 *
	 * @return void
	 */
	public function testATaxedLineIsStandardRatedWithoutAnyCode()
	{
		$protocol = new CIIProtocol($GLOBALS['db']);

		$result = $protocol->getCategoryRate($this->line(20, ''), $this->seller(), $this->invoiceOf());

		$this->assertSame('S', $result['categoryVAT']);
		$this->assertEmpty($result['ExemptionReasonCode']);
	}
}
