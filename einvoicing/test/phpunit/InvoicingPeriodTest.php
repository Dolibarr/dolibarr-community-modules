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
 *      \file       test/phpunit/InvoicingPeriodTest.php
 *      \ingroup    test
 *      \brief      PHPUnit test for the invoicing period of the document (BG-14 / BT-73 / BT-74).
 *                  Dolibarr holds a period on the line and not on the invoice, so the header period
 *                  is derived from the lines: earliest start, latest end (issue #572). What the
 *                  generated XML then looks like is pinned in CIIProfileShapeTest.
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
class InvoicingPeriodTest extends CommonClassTest
{
	/**
	 * The accumulator buildinvoicelines.inc.php fills, in the shape it fills it: one entry per line
	 * that has the date, keyed by the line number, and the key itself absent when no line has any.
	 *
	 * @param	array<int,?string>	$starts		date_start of each line, null when it has none
	 * @param	array<int,?string>	$ends		date_end of each line, null when it has none
	 * @return	array<string,array<int,int>>
	 */
	private function billingPeriod($starts, $ends)
	{
		$collected = array();
		foreach (array('start' => $starts, 'end' => $ends) as $side => $dates) {
			foreach ($dates as $numligne => $date) {
				if ($date !== null) {
					$collected[$side][$numligne] = (int) (new DateTime($date))->getTimestamp();
				}
			}
		}

		return $collected;
	}

	/**
	 * The period of the document is the one covering its lines: the earliest start and the latest end.
	 *
	 * @return void
	 */
	public function testThePeriodCoversEveryLine()
	{
		$period = einvoicingInvoicingPeriodFromLines($this->billingPeriod(
			array(1 => '2026-06-01', 2 => '2026-05-15', 3 => '2026-07-01'),
			array(1 => '2026-06-30', 2 => '2026-05-31', 3 => '2026-07-31')
		));

		$this->assertSame('2026-05-15', dol_print_date($period['start'], '%Y-%m-%d'));
		$this->assertSame('2026-07-31', dol_print_date($period['end'], '%Y-%m-%d'));
	}

	/**
	 * A single line with a period gives the document that period, unchanged.
	 *
	 * @return void
	 */
	public function testASingleLinePeriodBecomesTheDocumentPeriod()
	{
		$period = einvoicingInvoicingPeriodFromLines($this->billingPeriod(
			array(1 => '2026-06-01'),
			array(1 => '2026-06-30')
		));

		$this->assertSame('2026-06-01', dol_print_date($period['start'], '%Y-%m-%d'));
		$this->assertSame('2026-06-30', dol_print_date($period['end'], '%Y-%m-%d'));
	}

	/**
	 * One side alone is a period the norm accepts, so the other side stays empty rather than being
	 * invented from the invoice date or from the side that is known.
	 *
	 * @return void
	 */
	public function testOneSideAloneIsKeptAsItIs()
	{
		$startOnly = einvoicingInvoicingPeriodFromLines($this->billingPeriod(array(1 => '2026-06-01'), array()));
		$this->assertSame('2026-06-01', dol_print_date($startOnly['start'], '%Y-%m-%d'));
		$this->assertNull($startOnly['end']);

		$endOnly = einvoicingInvoicingPeriodFromLines($this->billingPeriod(array(), array(1 => '2026-06-30')));
		$this->assertNull($endOnly['start']);
		$this->assertSame('2026-06-30', dol_print_date($endOnly['end'], '%Y-%m-%d'));
	}

	/**
	 * An invoice whose lines carry no period at all declares none, which is what the documents
	 * generated before this existed did.
	 *
	 * @return void
	 */
	public function testNothingIsDerivedFromLinesWithoutDates()
	{
		$this->assertSame(array('start' => null, 'end' => null), einvoicingInvoicingPeriodFromLines(array()));
		$this->assertSame(array('start' => null, 'end' => null), einvoicingInvoicingPeriodFromLines(array('start' => array(), 'end' => array())));
	}

	/**
	 * A period that starts after it ends is refused rather than emitted: BR-29 wants the end date on
	 * or after the start date, and a document refused whole because of a header nobody filled in would
	 * be worse than carrying the periods on the lines only. Reachable with one line billed from March
	 * with no end, next to another billed until January with no start.
	 *
	 * @return void
	 */
	public function testAContradictoryPeriodIsNotDerived()
	{
		$period = einvoicingInvoicingPeriodFromLines($this->billingPeriod(
			array(1 => '2026-03-01'),
			array(2 => '2026-01-31')
		));

		$this->assertSame(array('start' => null, 'end' => null), $period);
	}

	/**
	 * The two dates being equal is a one-day period, not a contradiction.
	 *
	 * @return void
	 */
	public function testAOneDayPeriodIsKept()
	{
		$period = einvoicingInvoicingPeriodFromLines($this->billingPeriod(
			array(1 => '2026-06-15'),
			array(1 => '2026-06-15')
		));

		$this->assertSame('2026-06-15', dol_print_date($period['start'], '%Y-%m-%d'));
		$this->assertSame('2026-06-15', dol_print_date($period['end'], '%Y-%m-%d'));
	}
}
