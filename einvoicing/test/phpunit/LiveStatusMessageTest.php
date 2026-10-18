<?php
/* Copyright (C) 2026 ATM Consulting
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
 *      \file       test/phpunit/LiveStatusMessageTest.php
 *      \ingroup    test
 *      \brief      PHPUnit test for EInvoicing::hasLiveStatusMessage() and hasSentStatusMessage().
 *      \remarks    To run this script as CLI: phpunit filename.php
 */

global $conf, $user, $langs, $db;

// See RecipientDirectoryTest.php for why DOLIBARR_HTDOCS is honoured before the relative path.
$dolibarrHtdocs = getenv('DOLIBARR_HTDOCS');
if (!$dolibarrHtdocs) {
	$dolibarrHtdocs = dirname(__FILE__) . '/../../htdocs';
}
if (!file_exists($dolibarrHtdocs . '/master.inc.php')) {
	throw new \RuntimeException('Could not locate master.inc.php under "' . $dolibarrHtdocs . '/". Set the environment variable (export DOLIBARR_HTDOCS=...) to the htdocs directory of the Dolibarr instance to test against.');
}

require_once $dolibarrHtdocs . '/master.inc.php';
dol_include_once('einvoicing/class/einvoicing.class.php');
require_once __DIR__ . '/CommonClassTestCompat.inc.php';

if (empty($user->id)) {
	print "Load permissions for admin user nb 1\n";
	$user->fetch(1);
	// User::loadRights() only exists from Dolibarr 19 on, older versions name it getrights()
	if (method_exists($user, 'loadRights')) {
		$user->loadRights();
	} else {
		$user->getrights();
	}
}
$conf->global->MAIN_DISABLE_ALL_MAILS = 1;


/**
 * Tests on which sent lifecycle statuses block a second send.
 *
 * Rows are written on an element id no invoice uses, inside the transaction CommonClassTest rolls back.
 */
class LiveStatusMessageTest extends CommonClassTest
{
	/** @var int Element id used by the rows written here: high enough not to collide with a real invoice */
	const TEST_ELEMENT_ID = 999999042;

	/** @var int Lifecycle status used by the rows written here (Approved) */
	const STATUS = 205;

	/**
	 * Start every test without any lifecycle message on the test element.
	 *
	 * @return void
	 */
	protected function setUp(): void
	{
		global $db;

		parent::setUp();

		$db->query("DELETE FROM " . $db->prefix() . "einvoicing_lifecycle_msg WHERE element_id = " . (int) self::TEST_ELEMENT_ID);
	}

	/**
	 * Insert a lifecycle message row the way storeStatusMessage() records it.
	 *
	 * @param	string	$validationStatus	What the platform answered ('', 'Pending', 'Ok', 'Error')
	 * @param	string	$direction			'OUT' for a status we sent, 'IN' for one we received
	 * @return	void
	 */
	private function insertMessage($validationStatus, $direction = 'OUT')
	{
		global $db, $user;

		$sql = "INSERT INTO " . $db->prefix() . "einvoicing_lifecycle_msg";
		$sql .= " (element_id, element_type, provider, direction, lc_status, lc_status_message, lc_validation_status, lc_validation_message, lc_reason_code, date_creation, fk_user_creat)";
		$sql .= " VALUES (" . (int) self::TEST_ELEMENT_ID . ", 'invoice_supplier', 'TEST', '" . $db->escape($direction) . "', " . (int) self::STATUS;
		$sql .= ", 'Test fixture', '" . $db->escape($validationStatus) . "', '', '', '" . $db->idate(dol_now()) . "', " . (int) $user->id . ")";

		$this->assertNotFalse($db->query($sql), (string) $db->lasterror());
	}

	/**
	 * @return bool What hasLiveStatusMessage() answers for the test element
	 */
	private function isLive()
	{
		global $db;

		$einvoicing = new EInvoicing($db);

		return $einvoicing->hasLiveStatusMessage(self::TEST_ELEMENT_ID, 'invoice_supplier', self::STATUS);
	}

	/**
	 * @return void
	 */
	public function testNothingSentIsNotLive()
	{
		$this->assertFalse($this->isLive());
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function notRejectedProvider()
	{
		return array(
			'accepted' => array('Ok'),
			'awaiting the platform answer' => array('Pending'),
			'not answered yet' => array(''),
		);
	}

	/**
	 * @dataProvider notRejectedProvider
	 *
	 * @param	string	$validationStatus	What the platform answered
	 * @return	void
	 */
	public function testAStatusThePlatformDidNotRejectIsLive($validationStatus)
	{
		$this->insertMessage($validationStatus);

		$this->assertTrue($this->isLive());
	}

	/**
	 * @return void
	 */
	public function testARejectedStatusMayBeSentAgain()
	{
		global $db;

		$this->insertMessage('Error');

		$this->assertFalse($this->isLive());

		$einvoicing = new EInvoicing($db);
		$this->assertTrue($einvoicing->hasSentStatusMessage(self::TEST_ELEMENT_ID, 'invoice_supplier', self::STATUS), 'a rejected send still counts as an attempt');
		$this->assertFalse($einvoicing->hasSentStatusMessage(self::TEST_ELEMENT_ID, 'invoice_supplier', self::STATUS, 1));
	}

	/**
	 * @return void
	 */
	public function testAReceivedStatusIsNotOneWeSent()
	{
		$this->insertMessage('Ok', 'IN');

		$this->assertFalse($this->isLive());
	}
}
