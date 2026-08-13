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
 *      \file       test/phpunit/ReceivedInvoiceStatusesTest.php
 *      \ingroup    test
 *      \brief      PHPUnit test for EInvoicing::getSendableStatusesForReceivedInvoice(): which lifecycle
 *                  statuses a user may still send by hand on an invoice received through the platform,
 *                  given what the platform already accepted for it. The rule the module got wrong is
 *                  that approving an invoice does not end the exchange - the payment, and the status
 *                  reporting it, come after the approval (issue #548).
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
 * Tests on the statuses still offered on a received invoice.
 *
 * Every test writes its lifecycle records on an element id that no invoice uses, inside the transaction
 * CommonClassTest opens for the class and rolls back afterwards, so a run leaves nothing behind.
 */
class ReceivedInvoiceStatusesTest extends CommonClassTest
{
	/** @var int Element id used by the records written here: high enough not to collide with a real invoice */
	const TEST_ELEMENT_ID = 999999002;

	/** @var string Element type of a supplier invoice, the only side that receives */
	const TEST_ELEMENT_TYPE = 'invoice_supplier';

	/**
	 * A provider has to be set for storeStatusMessage() to record anything, and start from no history.
	 *
	 * @return void
	 */
	protected function setUp(): void
	{
		global $conf, $db;

		parent::setUp();

		$conf->global->EINVOICING_PDP = 'SUPERPDP';

		$db->query("DELETE FROM " . $db->prefix() . "einvoicing_lifecycle_msg WHERE element_id = " . (int) self::TEST_ELEMENT_ID);
	}

	/**
	 * Record an outgoing status the way sendStatusMessage() does once the platform answered.
	 *
	 * @param	int		$statusCode			Lifecycle status sent
	 * @param	string	$validationStatus	What the platform answered ('Ok' = accepted)
	 * @return	void
	 */
	private function sent($statusCode, $validationStatus = 'Ok')
	{
		global $db;

		$einvoicing = new EInvoicing($db);
		$einvoicing->storeStatusMessage(self::TEST_ELEMENT_ID, self::TEST_ELEMENT_TYPE, $statusCode, '', 'Out', 'ie_999001', $validationStatus);
	}

	/**
	 * What the card would offer for the test invoice, as a list of status codes.
	 *
	 * @return	int[]	Status codes still sendable
	 */
	private function offered()
	{
		global $db;

		$einvoicing = new EInvoicing($db);

		return array_map('intval', array_keys($einvoicing->getSendableStatusesForReceivedInvoice(self::TEST_ELEMENT_ID, self::TEST_ELEMENT_TYPE)));
	}

	/**
	 * An invoice on which nothing was sent yet offers the whole sendable list.
	 *
	 * @return void
	 */
	public function testNothingSentYetOffersEverySendableStatus()
	{
		$offered = $this->offered();

		$this->assertContains(EInvoicing::STATUS_APPROVED, $offered);
		$this->assertContains(EInvoicing::STATUS_REFUSED, $offered);
		$this->assertContains(EInvoicing::STATUS_PAYMENT_SENT, $offered);
	}

	/**
	 * The regression this guards, and the point of issue #548: an approved invoice is going to be paid,
	 * so "Payment transmitted" has to stay reachable. The card used to hide the whole button group as
	 * soon as an "Approved" was accepted, which made the manual 211 unreachable in the normal order of
	 * things - approve, then pay.
	 *
	 * @return void
	 */
	public function testApprovedStillAllowsThePaymentStatus()
	{
		$this->sent(EInvoicing::STATUS_APPROVED);

		$offered = $this->offered();

		$this->assertContains(EInvoicing::STATUS_PAYMENT_SENT, $offered, 'the payment status is what follows an approval');
		$this->assertNotContains(EInvoicing::STATUS_APPROVED, $offered, 'an accepted status is not offered twice');
		$this->assertNotContains(EInvoicing::STATUS_REFUSED, $offered, 'an approved invoice cannot then be refused');
	}

	/**
	 * Refusing ends the exchange: an invoice sent back to its vendor is not going to be paid, so nothing
	 * is left to send and the button group disappears.
	 *
	 * @return void
	 */
	public function testRefusedEndsTheExchange()
	{
		$this->sent(EInvoicing::STATUS_REFUSED);

		$this->assertSame(array(), $this->offered());
	}

	/**
	 * Once approved and paid, nothing is left either.
	 *
	 * @return void
	 */
	public function testApprovedThenPaidLeavesNothingToSend()
	{
		$this->sent(EInvoicing::STATUS_APPROVED);
		$this->sent(EInvoicing::STATUS_PAYMENT_SENT);

		$this->assertSame(array(), $this->offered());
	}

	/**
	 * A status the platform refused is not a status sent: it has to stay offered, otherwise a rejected
	 * send would leave the user with no way to retry.
	 *
	 * @return void
	 */
	public function testARejectedSendRemainsRetryable()
	{
		$this->sent(EInvoicing::STATUS_APPROVED, 'Error');

		$offered = $this->offered();

		$this->assertContains(EInvoicing::STATUS_APPROVED, $offered);
		$this->assertContains(EInvoicing::STATUS_REFUSED, $offered, 'nothing was accepted, so the choice is still open');
	}
}
