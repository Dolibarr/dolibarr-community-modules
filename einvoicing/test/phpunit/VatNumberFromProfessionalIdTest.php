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
 *      \file       test/phpunit/VatNumberFromProfessionalIdTest.php
 *      \ingroup    test
 *      \brief      PHPUnit test for the VAT number the module builds for a French thirdparty that has
 *                  none, BT-31 for the seller and BT-48 for the buyer.
 *
 *                  A French VAT number is FR, a two digit key, then the SIREN: the key is
 *                  [12 + 3 * (SIREN modulo 97)] modulo 97, padded below 10. The computation is
 *                  Societe::calculateVATNumberFromProperties() from v24, the compat backport on 18-23.
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
// Reached by its own path, not through dol_include_once(): the shim has to be loaded even when
// dol_buildpath() cannot resolve the module, which is how issue #565 turned a missing polyfill into a
// fatal. The module root is two levels above this directory.
require_once dirname(__DIR__, 2) . '/compat/societe.lib.php';
require_once __DIR__ . '/CommonClassTestCompat.inc.php';

/**
 * Class for PHPUnit tests
 *
 * @backupGlobals disabled
 * @backupStaticAttributes enabled
 * @remarks	backupGlobals must be disabled to have db,conf,user and lang not erased.
 */
class VatNumberFromProfessionalIdTest extends CommonClassTest
{
	/**
	 * A thirdparty, reduced to the five properties the computation reads on it.
	 *
	 * @param	string		$idprof1		SIREN held by the record, '' when it has none
	 * @param	string		$idprof2		SIRET held by the record, '' when it has none
	 * @param	string		$countryCode	Country of the thirdparty
	 * @param	string		$vatNumber		VAT number already on the record, '' when it has none
	 * @param	int|string	$vatEnabled		->tva_assuj, 0 for a thirdparty not subject to VAT
	 * @return	Societe
	 */
	private function thirdparty($idprof1, $idprof2 = '', $countryCode = 'FR', $vatNumber = '', $vatEnabled = 1)
	{
		global $db;

		$thirdparty = new Societe($db);
		$thirdparty->name = 'Thirdparty';
		$thirdparty->country_code = $countryCode;
		$thirdparty->tva_intra = $vatNumber;
		$thirdparty->tva_assuj = $vatEnabled;
		$thirdparty->idprof1 = $idprof1;
		$thirdparty->idprof2 = $idprof2;

		return $thirdparty;
	}

	/**
	 * What the module puts in BT-31 / BT-48, through its own entry point. On a core that has
	 * Societe::calculateVATNumberFromProperties() this hands over to the core; on the others it goes
	 * through the backport.
	 *
	 * @param	Societe	$thirdparty		The thirdparty to build a number for
	 * @return	string					The VAT number, '' when none can be built
	 */
	private function vatNumberOf($thirdparty)
	{
		global $db;

		$einvoicing = new EInvoicing($db);

		return $einvoicing->thirdpartyCalcVATIntra($thirdparty);
	}

	/**
	 * The same thing asked of the backport alone, so the branch taken on 18 to 23 is exercised even when
	 * the test runs on a core that answers for itself.
	 *
	 * @param	Societe	$thirdparty		The thirdparty to build a number for
	 * @return	string					The VAT number, '' when none can be built
	 */
	private function backportedVatNumberOf($thirdparty)
	{
		return calculateVATNumberFromProperties($thirdparty);
	}

	/**
	 * Assert both paths agree on the number, and return it.
	 *
	 * @param	string	$expected		The VAT number both must build
	 * @param	Societe	$thirdparty		The thirdparty to build it for
	 * @return	void
	 */
	private function assertVatNumberIs($expected, $thirdparty)
	{
		$this->assertSame($expected, $this->vatNumberOf($thirdparty), 'The module does not build the expected VAT number.');
		$this->assertSame($expected, $this->backportedVatNumberOf($thirdparty), 'The backport does not build the same VAT number as the core.');
	}

	/**
	 * A key below 10 is written on two digits, like every other key: the number is thirteen characters
	 * whatever the SIREN. 800000028 has a key of 3, and used to come out as "FR3800000028".
	 *
	 * @return void
	 */
	public function testTheKeyIsAlwaysTwoDigits()
	{
		$this->assertVatNumberIs('FR03800000028', $this->thirdparty('800000028'));
		$this->assertSame(13, strlen($this->vatNumberOf($this->thirdparty('800000028'))));

		// A key of 10 or more was already right, and stays right.
		$this->assertVatNumberIs('FR75911270304', $this->thirdparty('911270304'));

		// The separators an operator types in the field are not part of the number.
		$this->assertVatNumberIs('FR03800000028', $this->thirdparty('800 000 028'));
	}

	/**
	 * A record that holds no SIREN but a SIRET is identified by the first nine digits of the SIRET. Those
	 * nine digits are a string, not a number: a SIREN that starts with a zero keeps it, or the number
	 * names another company than the one being invoiced.
	 *
	 * @return void
	 */
	public function testTheSirenTakenFromASiretKeepsItsLeadingZero()
	{
		$number = $this->vatNumberOf($this->thirdparty('', '01234567500008'));

		$this->assertSame('FR12012345675', $number);
		$this->assertSame(13, strlen($number));
		$this->assertVatNumberIs('FR12012345675', $this->thirdparty('', '01234567500008'));

		// And a SIRET with the separators of the paper form is read the same way.
		$this->assertVatNumberIs('FR75911270304', $this->thirdparty('', '911 270 304 00015'));
	}

	/**
	 * Nothing is built when nothing can be. A thirdparty outside France, one that already carries its
	 * number, one that is not subject to VAT, and one whose professional ids are not a SIREN nor a SIRET
	 * all give an empty string: an invented number is worse than an absent one, since BT-31 and BT-48 are
	 * what the platform routes and controls the document on.
	 *
	 * @return void
	 */
	public function testNoNumberIsInventedWhenTheRecordDoesNotCarryOne()
	{
		// Not a French thirdparty: the FR algorithm says nothing about it.
		$this->assertVatNumberIs('', $this->thirdparty('800000028', '', 'BE'));

		// The record already states its number, which is the one to use.
		$this->assertVatNumberIs('', $this->thirdparty('800000028', '', 'FR', 'FR75911270304'));

		// Not subject to VAT.
		$this->assertVatNumberIs('', $this->thirdparty('800000028', '', 'FR', '', 0));

		// No professional id at all.
		$this->assertVatNumberIs('', $this->thirdparty('', ''));

		// An idprof1 that is not a SIREN, with no SIRET to fall back on. This used to build
		// "FR9012345" out of it.
		$this->assertVatNumberIs('', $this->thirdparty('12345', ''));

		// Neither of the two is valid.
		$this->assertVatNumberIs('', $this->thirdparty('12345', '12345678912345'));
	}
}
