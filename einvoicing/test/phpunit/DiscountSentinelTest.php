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
 *      \file       test/phpunit/DiscountSentinelTest.php
 *      \ingroup    test
 *      \brief      PHPUnit test for the four sentinels a discount line carries.
 *                  A discount built from another piece has no text of its own: the core stores
 *                  '(CREDIT_NOTE)', '(DEPOSIT)', '(EXCESS RECEIVED)' or '(EXCESS PAID)' in its
 *                  description and resolves it at print time. An e-invoice that does not resolve it
 *                  shows the sentinel to the customer, in the item name of a line (BT-153) or in the
 *                  reason of a document level allowance (BT-97).
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
dol_include_once('einvoicing/lib/einvoicing.lib.php');
require_once __DIR__ . '/CommonClassTestCompat.inc.php';

/**
 * Class for PHPUnit tests
 *
 * @backupGlobals disabled
 * @backupStaticAttributes enabled
 * @remarks	backupGlobals must be disabled to have db,conf,user and lang not erased.
 */
class DiscountSentinelTest extends CommonClassTest
{
	/**
	 * A discount, reduced to what einvoicingDiscountLabel() reads on it. Not a DiscountAbsolute: the
	 * function reads five properties and fetches nothing, which is what makes it testable without
	 * writing a discount into the database.
	 *
	 * @param	string	$customerRef	->ref_facture_source, the piece a customer side discount names
	 * @param	int		$discountType	->discount_type, 1 on the supplier side
	 * @param	string	$supplierRef	->ref_invoice_supplier_source
	 * @return	stdClass
	 */
	private function discount($customerRef = 'FA2026-0180', $discountType = 0, $supplierRef = '')
	{
		$discount = new stdClass();
		$discount->id = 24;
		$discount->discount_type = $discountType;
		$discount->ref_facture_source = $customerRef;
		$discount->ref_invoice_supplier_source = $supplierRef;
		$discount->datec = dol_mktime(0, 0, 0, 6, 30, 2026);

		return $discount;
	}

	/**
	 * A discount object that was never fetched: nothing to read on it, which is what a deleted or
	 * unreadable source piece leaves behind.
	 *
	 * @return stdClass
	 */
	private function unfetchedDiscount()
	{
		$discount = $this->discount();
		$discount->id = 0;

		return $discount;
	}

	/**
	 * The list of the sentinels is the one of the core, spelled exactly as the core spells it. It is
	 * pinned here because it is shared by the resolution and by the last look that reports a sentinel
	 * having reached a field of the document: a fifth entry, or one spelling drifting, would silently
	 * make one of the four unresolvable again.
	 *
	 * @return void
	 */
	public function testTheSentinelsAreTheOnesOfTheCore()
	{
		$this->assertSame(
			array('(CREDIT_NOTE)', '(DEPOSIT)', '(EXCESS RECEIVED)', '(EXCESS PAID)'),
			array_keys(einvoicingDiscountSentinels())
		);
	}

	/**
	 * The four sentinels are resolved, each into the text of the core for the case it stands for, and
	 * each naming the piece the amount comes from. The spelling of the four is not homogeneous -
	 * '(CREDIT_NOTE)' holds an underscore where '(EXCESS PAID)' and '(EXCESS RECEIVED)' hold a space -
	 * which is what a single pattern gets wrong.
	 *
	 * @return void
	 */
	public function testTheFourSentinelsAreResolved()
	{
		global $langs;

		foreach (array('(CREDIT_NOTE)', '(DEPOSIT)', '(EXCESS RECEIVED)') as $sentinel) {
			$label = einvoicingDiscountLabel($this->discount(), $sentinel, $langs);

			$this->assertNotSame('', $label, 'The sentinel '.$sentinel.' must be resolved.');
			$this->assertStringNotContainsString('(', $label, 'No sentinel may survive in '.$sentinel.'.');
			$this->assertStringContainsString('FA2026-0180', $label, 'The piece the amount comes from must be named.');
		}

		// The supplier side names its own piece, and reading ref_facture_source there would name nothing.
		$label = einvoicingDiscountLabel($this->discount('', 1, 'SUPPLIER-2026-77'), '(EXCESS PAID)', $langs);
		$this->assertStringContainsString('SUPPLIER-2026-77', $label);
	}

	/**
	 * Each sentinel is resolved into the text of its own case: a deposit deducted is not a credit note
	 * applied, and a document that called one the other would misdescribe what it deducts.
	 *
	 * @return void
	 */
	public function testEachSentinelGetsTheTextOfItsOwnCase()
	{
		global $langs;

		$creditNote = einvoicingDiscountLabel($this->discount(), '(CREDIT_NOTE)', $langs);
		$deposit = einvoicingDiscountLabel($this->discount(), '(DEPOSIT)', $langs);
		$excess = einvoicingDiscountLabel($this->discount(), '(EXCESS RECEIVED)', $langs);

		$this->assertNotSame($creditNote, $deposit);
		$this->assertNotSame($creditNote, $excess);
		$this->assertNotSame($deposit, $excess);
	}

	/**
	 * Nothing else is resolved. The test of the core is an exact equality on the description AND a
	 * discount behind the line, and matching the text loosely is wrong in both directions: a line of
	 * work quoting the string would be renamed, and the reason an operator typed by hand would be
	 * replaced by a text about a piece that does not exist.
	 *
	 * @return void
	 */
	public function testNothingElseIsResolved()
	{
		global $langs;

		$discount = $this->discount();

		$this->assertSame('', einvoicingDiscountLabel($discount, '', $langs));
		$this->assertSame('', einvoicingDiscountLabel($discount, 'Remise commerciale', $langs));
		$this->assertSame('', einvoicingDiscountLabel($discount, 'credit_note', $langs));
		$this->assertSame('', einvoicingDiscountLabel($discount, 'CREDIT_NOTE', $langs));
		$this->assertSame('', einvoicingDiscountLabel($discount, '(CREDIT NOTE)', $langs));
		$this->assertSame('', einvoicingDiscountLabel($discount, 'Reprise (DEPOSIT) du chantier', $langs));
	}

	/**
	 * A sentinel with no piece to name still gets a text of its own, and never goes out as it stands.
	 * The wording of the core quotes a reference, so it would be issued with a hole in the middle of the
	 * sentence; the module has its own for the case. The one thing that must not happen is the marker
	 * reaching the document: an item name that is a technical marker is what BR-25 refuses in spirit,
	 * and it is what the customer would read.
	 *
	 * @return void
	 */
	public function testASentinelWithNoPieceToNameStillGetsAText()
	{
		global $langs;

		foreach (array(null, $this->discount(''), $this->unfetchedDiscount()) as $noSource) {
			$label = einvoicingDiscountLabel($noSource, '(CREDIT_NOTE)', $langs);

			$this->assertNotSame('', $label);
			$this->assertStringNotContainsString('(CREDIT_NOTE)', $label);
		}

		// And each case keeps a wording of its own, a deposit deducted not being a credit note applied.
		$this->assertNotSame(
			einvoicingDiscountLabel(null, '(CREDIT_NOTE)', $langs),
			einvoicingDiscountLabel(null, '(DEPOSIT)', $langs)
		);
	}

	/**
	 * The invoice the deducted piece corrects is named beside the piece itself: a deduction that only
	 * says which credit note it comes from leaves the customer to find which invoice that credit note
	 * was about. It is skipped when it would name the piece already named, which is what a deposit
	 * deducted from the very invoice it was asked on would do.
	 *
	 * @return void
	 */
	public function testTheCorrectedInvoiceIsNamedBesideThePiece()
	{
		global $langs;

		$withCorrected = einvoicingDiscountLabel($this->discount(), '(CREDIT_NOTE)', $langs, 'FA2026-0100');
		$withoutCorrected = einvoicingDiscountLabel($this->discount(), '(CREDIT_NOTE)', $langs);

		$this->assertStringStartsWith($withoutCorrected, $withCorrected);
		$this->assertStringContainsString('FA2026-0100', $withCorrected);

		// The same reference on both sides is named once.
		$this->assertSame(
			einvoicingDiscountLabel($this->discount('FA2026-0180'), '(DEPOSIT)', $langs),
			einvoicingDiscountLabel($this->discount('FA2026-0180'), '(DEPOSIT)', $langs, 'FA2026-0180')
		);
	}

	/**
	 * The date of the deposit follows the option of the core, so the e-invoice reads the same way as the
	 * PDF it accompanies.
	 *
	 * @return void
	 */
	public function testTheDepositDateFollowsTheOptionOfTheCore()
	{
		global $conf, $langs;

		$savOption = getDolGlobalString('INVOICE_ADD_DEPOSIT_DATE');

		$conf->global->INVOICE_ADD_DEPOSIT_DATE = '';
		$withoutDate = einvoicingDiscountLabel($this->discount(), '(DEPOSIT)', $langs);

		$conf->global->INVOICE_ADD_DEPOSIT_DATE = '1';
		$withDate = einvoicingDiscountLabel($this->discount(), '(DEPOSIT)', $langs);

		$conf->global->INVOICE_ADD_DEPOSIT_DATE = $savOption;

		$this->assertNotSame($withoutDate, $withDate);
		$this->assertStringStartsWith($withoutDate, $withDate);
	}
}
