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
 *      \file       test/phpunit/TransmittedLockTest.php
 *      \ingroup    test
 *      \brief      PHPUnit test for the already-transmitted guard: EInvoicing::isTransmittedLockActive()
 *                  and the two flags fetchLastknownInvoiceStatus() derives, 'transmitted' (from the
 *                  resettable syncstatus) and 'everTransmitted' (from the flow_id, which nothing clears).
 *                  Re-sending is refused as a duplicate, so only the second may gate a transmission.
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
 * Tests on the already-transmitted guard.
 *
 * Every test writes its extlink record on an element id that no invoice uses, inside the transaction
 * CommonClassTest opens for the class and rolls back afterwards, so a run leaves nothing behind.
 */
class TransmittedLockTest extends CommonClassTest
{
	/** @var int Element id used by the records written here: high enough not to collide with a real invoice */
	const TEST_ELEMENT_ID = 999999001;

	/**
	 * Set the two globals the tested code reads, and start from a clean record.
	 *
	 * @return void
	 */
	protected function setUp(): void
	{
		global $conf, $db;

		parent::setUp();

		$conf->global->EINVOICING_PDP = 'SUPERPDP';
		unset($conf->global->EINVOICING_ALLOW_RESEND_TRANSMITTED);

		$db->query("DELETE FROM " . $db->prefix() . "einvoicing_extlinks WHERE element_id = " . (int) self::TEST_ELEMENT_ID);
		$db->query("DELETE FROM " . $db->prefix() . "einvoicing_lifecycle_msg WHERE element_id = " . (int) self::TEST_ELEMENT_ID);
	}

	/**
	 * Record a lifecycle status received for the test invoice, the way the CDAR import stores it.
	 *
	 * @param 	int 	$code 		Status code
	 * @param 	string 	$reason 	Reason code
	 * @param 	string 	$roles 		RoleCodes the status was addressed to
	 * @return 	void
	 */
	private function received($code, $reason = '', $roles = 'SE')
	{
		global $db;

		$einvoicing = new EInvoicing($db);
		$this->assertGreaterThan(0, $einvoicing->storeStatusMessage(self::TEST_ELEMENT_ID, 'facture', $code, '', 'in', 'ie_' . (int) $code, '', '', null, $reason, $roles), (string) $db->lasterror());
	}

	/**
	 * Write the extlink record the way the module does, and read the status back.
	 *
	 * @param 	string 	$flowId 	Flow id assigned by the platform ('' = never submitted)
	 * @param 	int 	$syncStatus	Current sync status
	 * @return 	array<string,mixed>	What fetchLastknownInvoiceStatus() reports for that record
	 */
	private function statusFor($flowId, $syncStatus)
	{
		global $db;

		$einvoicing = new EInvoicing($db);
		$einvoicing->insertOrUpdateExtLink(self::TEST_ELEMENT_ID, 'facture', $flowId, $syncStatus, 'TEST-LOCK-0001');

		return $einvoicing->fetchLastknownInvoiceStatus(self::TEST_ELEMENT_ID, 'TEST-LOCK-0001');
	}

	/**
	 * An invoice generated but never submitted carries no flow_id: nothing to protect, auto-send may run.
	 *
	 * @return void
	 */
	public function testGeneratedButNeverSubmittedIsNotLocked()
	{
		global $db;

		$status = $this->statusFor('', EInvoicing::STATUS_GENERATED);

		$this->assertSame(0, $status['transmitted']);
		$this->assertSame(0, $status['everTransmitted']);

		$einvoicing = new EInvoicing($db);
		$this->assertFalse($einvoicing->isTransmittedLockActive(self::TEST_ELEMENT_ID, 'TEST-LOCK-0001'));
	}

	/**
	 * Right after a successful submission both flags agree: the invoice is at the platform.
	 *
	 * @return void
	 */
	public function testSubmittedInvoiceIsLocked()
	{
		global $db;

		$status = $this->statusFor('i_159705', EInvoicing::STATUS_AWAITING_VALIDATION);

		$this->assertSame('i_159705', $status['flow_id']);
		$this->assertSame(1, $status['transmitted']);
		$this->assertSame(1, $status['everTransmitted']);

		$einvoicing = new EInvoicing($db);
		$this->assertTrue($einvoicing->isTransmittedLockActive(self::TEST_ELEMENT_ID, 'TEST-LOCK-0001'));
	}

	/**
	 * The regression this guards: regenerating the e-invoice sets the status back to GENERATED, which
	 * makes 'transmitted' read 0 again on an invoice the platform already holds. The flow_id survives,
	 * so 'everTransmitted' and the lock stay on, and a re-send that would come back as a duplicate is
	 * still refused locally.
	 *
	 * @return void
	 */
	public function testRegeneratingDoesNotUnlockATransmittedInvoice()
	{
		global $db;

		$this->statusFor('i_159705', EInvoicing::STATUS_AWAITING_VALIDATION);

		// What CIIProtocol/FacturXProtocol::generateInvoice() does on every regeneration.
		$einvoicing = new EInvoicing($db);
		$einvoicing->insertOrUpdateExtLink(self::TEST_ELEMENT_ID, 'facture', '', EInvoicing::STATUS_GENERATED, 'TEST-LOCK-0001');

		$status = $einvoicing->fetchLastknownInvoiceStatus(self::TEST_ELEMENT_ID, 'TEST-LOCK-0001');

		$this->assertSame(EInvoicing::STATUS_GENERATED, $status['code']);
		$this->assertSame(0, $status['transmitted'], "regeneration resets the status, so 'transmitted' cannot gate a transmission");
		$this->assertSame('i_159705', $status['flow_id'], 'the flow_id assigned by the platform is never cleared');
		$this->assertSame(1, $status['everTransmitted']);

		$this->assertTrue($einvoicing->isTransmittedLockActive(self::TEST_ELEMENT_ID, 'TEST-LOCK-0001'));
	}

	/**
	 * A submission that failed stays locked as long as no rejection at emission is received: until the
	 * platform says it refused the invoice, it may hold it, and re-sending the same reference is refused.
	 *
	 * @return void
	 */
	public function testRejectedInvoiceStaysLocked()
	{
		global $db;

		$status = $this->statusFor('i_159705', EInvoicing::STATUS_ERROR);

		$this->assertSame(1, $status['everTransmitted']);

		$einvoicing = new EInvoicing($db);
		$this->assertTrue($einvoicing->isTransmittedLockActive(self::TEST_ELEMENT_ID, 'TEST-LOCK-0001'));
	}

	/**
	 * The seller's AP rejected the invoice at emission (XP Z12-014 annex A 2.2): it was never deposited,
	 * so the platform accepts the corrected document under the same number. Regenerating it resets the
	 * status to GENERATED, and it must stay sendable then too. The invoice itself stays locked.
	 *
	 * @return void
	 */
	public function testRejectedAtEmissionIsNotLocked()
	{
		global $db;

		$this->statusFor('i_708390', EInvoicing::STATUS_REJECTED);
		$this->received(EInvoicing::STATUS_REJECTED, 'REJ_SEMAN', 'SE');

		$einvoicing = new EInvoicing($db);
		$this->assertTrue($einvoicing->isOnlyRejectedAtEmission(self::TEST_ELEMENT_ID));
		$this->assertFalse($einvoicing->isSendLocked(self::TEST_ELEMENT_ID, 'TEST-LOCK-0001'));
		$this->assertTrue($einvoicing->isTransmittedLockActive(self::TEST_ELEMENT_ID, 'TEST-LOCK-0001'), 'the invoice itself stays locked: its number was reported with the rejection');

		$einvoicing->insertOrUpdateExtLink(self::TEST_ELEMENT_ID, 'facture', '', EInvoicing::STATUS_GENERATED, 'TEST-LOCK-0001');
		$this->assertFalse($einvoicing->isSendLocked(self::TEST_ELEMENT_ID, 'TEST-LOCK-0001'), 'regenerating the rejected invoice must not lock its sending again');
	}

	/**
	 * A rejection recorded before the recipients were stored names no one: without any status proving a
	 * deposit, it is read as the seller's AP refusing the invoice at emission.
	 *
	 * @return void
	 */
	public function testRejectionWithoutRecipientsIsNotLocked()
	{
		global $db;

		$this->statusFor('i_708390', EInvoicing::STATUS_REJECTED);
		$this->received(EInvoicing::STATUS_REJECTED, 'REJ_SEMAN', '');

		$einvoicing = new EInvoicing($db);
		$this->assertFalse($einvoicing->isSendLocked(self::TEST_ELEMENT_ID, 'TEST-LOCK-0001'));
	}

	/**
	 * Every rejection the platform still holds a copy of keeps the lock, since sending the same number
	 * again only comes back as a duplicate: DOUBLON, a rejection addressed to the buyer (annex A 2.4),
	 * a rejection after the platform deposited the invoice, and a corrected invoice accepted since.
	 *
	 * @return array<string,array{0:array<int,array{0:int,1:string,2:string}>}>
	 */
	public static function rejectionsStillHeldByThePlatform()
	{
		return array(
			'duplicate' => array(array(array(EInvoicing::STATUS_REJECTED, 'DOUBLON', 'SE'))),
			'rejected by the buyer AP' => array(array(array(EInvoicing::STATUS_REJECTED, 'REJ_SEMAN', 'SE,BY'))),
			'rejected after deposit' => array(array(array(200, '', 'SE'), array(201, '', 'SE'), array(EInvoicing::STATUS_REJECTED, 'REJ_SEMAN', 'SE'))),
			'accepted once corrected' => array(array(array(EInvoicing::STATUS_REJECTED, 'REJ_SEMAN', 'SE'), array(200, '', 'SE'), array(201, '', 'SE'))),
		);
	}

	/**
	 * @dataProvider rejectionsStillHeldByThePlatform
	 *
	 * @param 	array<int,array{0:int,1:string,2:string}> 	$statuses 	Statuses received, oldest first
	 * @return 	void
	 */
	public function testRejectionStillHeldByThePlatformStaysLocked($statuses)
	{
		global $db;

		$this->statusFor('i_708390', EInvoicing::STATUS_REJECTED);
		foreach ($statuses as $status) {
			$this->received($status[0], $status[1], $status[2]);
		}

		$einvoicing = new EInvoicing($db);
		$this->assertFalse($einvoicing->isOnlyRejectedAtEmission(self::TEST_ELEMENT_ID));
		$this->assertTrue($einvoicing->isSendLocked(self::TEST_ELEMENT_ID, 'TEST-LOCK-0001'));
	}

	/**
	 * The documented opt-out lifts the lock, for an operator deliberately testing the platform retry.
	 *
	 * @return void
	 */
	public function testOptOutLiftsTheLock()
	{
		global $conf, $db;

		$this->statusFor('i_159705', EInvoicing::STATUS_AWAITING_VALIDATION);

		$conf->global->EINVOICING_ALLOW_RESEND_TRANSMITTED = 1;

		$einvoicing = new EInvoicing($db);
		$this->assertFalse($einvoicing->isTransmittedLockActive(self::TEST_ELEMENT_ID, 'TEST-LOCK-0001'));
	}
}
