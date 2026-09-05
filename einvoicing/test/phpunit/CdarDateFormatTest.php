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
 *      \file       test/phpunit/CdarDateFormatTest.php
 *      \ingroup    test
 *      \brief      PHPUnit test for the CDAR date formatting.
 *                  The CDAR is a document sent to the platform, and its dates are of two kinds
 *                  that must not be given the same timezone.
 *
 *                  A date read from the invoice is a calendar date: Facture::fetch() builds the
 *                  timestamp with DoliDB::jdate(), whose $gm defaults to 'tzserver' on the seven
 *                  supported cores, so it is the stored date expressed in the server timezone.
 *                  Those are written with 'tzserver', which reproduces the raw date() the class
 *                  used before to the byte: it sets $to_gmt = false and both offsets to 0, then
 *                  formats through a DateTime put in date_default_timezone_get(), which is
 *                  exactly what date() reads. 'gmt' there would report a day early east of UTC.
 *
 *                  The issuance stamps are instants, not calendar dates, so they are written in
 *                  UTC with 'gmt'.
 *
 *                  Neither is left to the 'auto' default: it resolves to $conf->tzuserinputkey
 *                  (functions.lib.php, identical on 18 and 24), which the MAIN_TZUSERINPUTKEY
 *                  constant can set to 'tzuserrel', and the emitted date would then follow the
 *                  timezone of whoever triggers the send.
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
dol_include_once('einvoicing/class/utils/CdarHandler.class.php');
require_once __DIR__ . '/CommonClassTestCompat.inc.php';

/**
 * Class for PHPUnit tests
 *
 * @backupGlobals disabled
 * @backupStaticAttributes enabled
 * @remarks	backupGlobals must be disabled to have db,conf,user and lang not erased.
 */
class CdarDateFormatTest extends CommonClassTest
{
	/** @var string	PHP default timezone saved at setUp() */
	private $savtz;

	/** @var string	$conf->tzuserinputkey saved at setUp() */
	private $savtzuserinputkey;

	/** @var bool	Whether $_SESSION['dol_tz_string'] existed at setUp() */
	private $savsessiontzset = false;

	/** @var string	$_SESSION['dol_tz_string'] saved at setUp() */
	private $savsessiontz = '';

	/**
	 * The timestamps the comparison runs on: a 1st of January and a 31st of December (the year
	 * boundary a timezone shift moves), and 00:30 and 23:30 (the day boundary it moves).
	 *
	 * @return int[]	Timestamps, UTC based so that they do not depend on the runner timezone
	 */
	private function timestamps()
	{
		return array(
			gmmktime(0, 0, 0, 1, 1, 2025),		// 2025-01-01 00:00:00 UTC, midnight on new year
			gmmktime(0, 30, 0, 1, 1, 2025),		// 2025-01-01 00:30:00 UTC, west of UTC this is still 2024
			gmmktime(23, 30, 0, 12, 31, 2025),	// 2025-12-31 23:30:00 UTC, east of UTC this is already 2026
			gmmktime(23, 30, 0, 6, 30, 2025),	// 2025-06-30 23:30:00 UTC, same but in the northern DST period
			gmmktime(12, 0, 0, 3, 30, 2025),	// inside the European DST switch day
			gmmktime(12, 0, 0, 10, 26, 2025),	// inside the European DST switch back day
			0,									// epoch, the value an unset date_creation falls back to
			1,									// one second after epoch
		);
	}

	/**
	 * Timezones the comparison runs in: UTC, one west of it, one east of it with a DST rule, and
	 * one with a fractional offset that no whole-hour arithmetic would get right.
	 *
	 * @return string[]	Timezone identifiers
	 */
	private function timezones()
	{
		return array('UTC', 'Europe/Paris', 'America/New_York', 'Asia/Kolkata', 'Pacific/Kiritimati');
	}

	/**
	 * Save what the tests move.
	 *
	 * @return void
	 */
	protected function setUp(): void
	{
		global $conf;

		parent::setUp();

		$this->savtz = date_default_timezone_get();
		$this->savtzuserinputkey = isset($conf->tzuserinputkey) ? $conf->tzuserinputkey : 'tzserver';
		$this->savsessiontzset = isset($_SESSION['dol_tz_string']);
		$this->savsessiontz = $this->savsessiontzset ? $_SESSION['dol_tz_string'] : '';
	}

	/**
	 * Put back what the tests moved.
	 *
	 * @return void
	 */
	protected function tearDown(): void
	{
		global $conf;

		date_default_timezone_set($this->savtz);
		$conf->tzuserinputkey = $this->savtzuserinputkey;
		if ($this->savsessiontzset) {
			$_SESSION['dol_tz_string'] = $this->savsessiontz;
		} else {
			unset($_SESSION['dol_tz_string']);
		}

		parent::tearDown();
	}

	/**
	 * The datetime stamp of the CDAR id and of IssueDateTime: dol_print_date() must give back
	 * exactly what date('YmdHis') gave.
	 *
	 * @return void
	 */
	public function testDateTimeStampIsUnchanged()
	{
		foreach ($this->timezones() as $tz) {
			date_default_timezone_set($tz);

			foreach ($this->timestamps() as $ts) {
				$this->assertSame(
					date('YmdHis', $ts),
					dol_print_date($ts, '%Y%m%d%H%M%S', 'tzserver'),
					'Datetime stamp changed for timestamp ' . $ts . ' in ' . $tz
				);
			}
		}
	}

	/**
	 * The date stamp of the CDAR id and of FormattedIssueDateTime, same comparison.
	 *
	 * @return void
	 */
	public function testDateStampIsUnchanged()
	{
		foreach ($this->timezones() as $tz) {
			date_default_timezone_set($tz);

			foreach ($this->timestamps() as $ts) {
				$this->assertSame(
					date('Ymd', $ts),
					dol_print_date($ts, '%Y%m%d', 'tzserver'),
					'Date stamp changed for timestamp ' . $ts . ' in ' . $tz
				);
			}
		}
	}

	/**
	 * Why 'tzserver' is written explicitly instead of letting the 'auto' default decide: on an
	 * install where MAIN_TZUSERINPUTKEY is 'tzuserrel', 'auto' shifts the emitted date by the
	 * timezone of the session, while 'tzserver' still reproduces date().
	 *
	 * @return void
	 */
	public function testTzServerIsImmuneToTheUserInputKey()
	{
		global $conf;

		date_default_timezone_set('Europe/Paris');
		$conf->tzuserinputkey = 'tzuserrel';
		$_SESSION['dol_tz_string'] = 'Pacific/Kiritimati';	// UTC+14, a day ahead of Paris at 23:30

		$ts = gmmktime(23, 30, 0, 12, 31, 2025);

		$this->assertSame(date('YmdHis', $ts), dol_print_date($ts, '%Y%m%d%H%M%S', 'tzserver'));
		$this->assertNotSame(date('YmdHis', $ts), dol_print_date($ts, '%Y%m%d%H%M%S'));
	}

	/**
	 * getCurrentDateTime() and getCurrentDate() stamp an instant, not a calendar date: it is the
	 * moment the CDAR is issued, and it travels between platforms, so it is written in UTC
	 * whatever the timezone of the server that emits it.
	 *
	 * Note the limit this test cannot check, because it is not in the value: format 204 carries
	 * no offset, so writing UTC does not declare UTC to the recipient. Only code 2379 would, and
	 * the library does not implement it.
	 *
	 * The reference gmdate() is read on both sides of the helper so that a second (or a day)
	 * elapsing in between cannot make the test flap.
	 *
	 * @return void
	 */
	public function testCurrentStampsAreUtcWhateverTheServerTimezone()
	{
		foreach ($this->timezones() as $tz) {
			date_default_timezone_set($tz);

			$before = gmdate('YmdHis');
			$got = CdarHandler::getCurrentDateTime();
			$after = gmdate('YmdHis');
			$this->assertContains($got, array($before, $after), 'getCurrentDateTime() is not the UTC instant in ' . $tz);

			$beforeday = gmdate('Ymd');
			$gotday = CdarHandler::getCurrentDate();
			$afterday = gmdate('Ymd');
			$this->assertContains($gotday, array($beforeday, $afterday), 'getCurrentDate() is not the UTC instant in ' . $tz);
		}
	}

	/**
	 * The counterpart, and the reason the dates read from the invoice are NOT written in UTC: a
	 * timestamp coming from the object does not hold an instant. Facture::fetch() reads it with
	 * DoliDB::jdate(), whose $gm parameter defaults to 'tzserver' on the seven supported cores,
	 * so the timestamp is the stored calendar date expressed in the server timezone.
	 *
	 * An invoice dated 2026-01-01 must therefore be stamped 20260101, and 'gmt' would report it a
	 * day early in every timezone east of UTC. This pins the measurement on Europe/Paris.
	 *
	 * @return void
	 */
	public function testInvoiceDateKeepsItsCalendarDay()
	{
		global $db;

		date_default_timezone_set('Europe/Paris');

		$ts = $db->jdate('2026-01-01 00:00:00');

		$this->assertSame('20260101', dol_print_date((int) $ts, '%Y%m%d', 'tzserver'));
		$this->assertSame('20251231', dol_print_date((int) $ts, '%Y%m%d', 'gmt'), 'Europe/Paris no longer shows the one day regression that gmt would cause here');
	}

	/**
	 * formatDateTime() and formatDate() are left on substr() on purpose: they are pass-through
	 * formatters, and dol_stringtotime() cannot give that pass-through back (it strips every
	 * non-digit then pads the result with '000000'). This pins the behaviour that must not move.
	 *
	 * @return void
	 */
	public function testFormattersSplitWellFormedInput()
	{
		$this->assertSame('2025-12-31 23:30:00', CdarHandler::formatDateTime('20251231233000'));
		$this->assertSame('2025-01-01 00:30:00', CdarHandler::formatDateTime('20250101003000'));
		$this->assertSame('2025-12-31', CdarHandler::formatDate('20251231'));
		$this->assertSame('2025-01-01', CdarHandler::formatDate('20250101'));
	}

	/**
	 * Anything that is not exactly 14 (resp. 8) characters long comes back untouched, including
	 * the empty string and an already formatted date.
	 *
	 * @return void
	 */
	public function testFormattersPassAnythingElseThrough()
	{
		foreach (array('', '2025-12-31', '2025-12-31 23:30', '2025-12-31T23:30:00Z', '202512', 'unknown', '0') as $input) {
			$this->assertSame($input, CdarHandler::formatDateTime($input), 'formatDateTime() no longer passes "' . $input . '" through');
		}

		foreach (array('', '2025-12-31', '20251231233000', '202512', 'unknown', '0') as $input) {
			$this->assertSame($input, CdarHandler::formatDate($input), 'formatDate() no longer passes "' . $input . '" through');
		}
	}
}
