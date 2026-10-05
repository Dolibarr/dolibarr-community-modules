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
 *      \file       test/phpunit/IncomingFlowChoicesTest.php
 *      \ingroup    test
 *      \brief      PHPUnit test for Document::listIncomingFlowsForMapping(), the list of flows the
 *                  manual product mapping (einvoicing/product_mapping.php) can be started from.
 *                  A flow is worth mapping on both sides of the import, so the list is built from the
 *                  synchronization queue and from the documents already received - and a flow held by
 *                  both is one entry, not two.
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
dol_include_once('einvoicing/class/document.class.php');
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
 * Tests on the list of flows offered by the manual product mapping.
 *
 * Every row is written inside the transaction CommonClassTest opens for the class and rolls back
 * afterwards, so a run leaves nothing behind.
 */
class IncomingFlowChoicesTest extends CommonClassTest
{
	/** @var string Flow only waiting in the synchronization queue */
	const FLOW_QUEUED = 'i_test1050_queued';
	/** @var string Flow only received as a document */
	const FLOW_RECEIVED = 'i_test1050_received';
	/** @var string Flow both queued and received */
	const FLOW_BOTH = 'i_test1050_both';
	/** @var string Flow of a document sent by us */
	const FLOW_OUTGOING = 'ie_test1050_sent';

	/**
	 * Write a row in the queue of the flows a synchronization could not import.
	 *
	 * The table is created when the module is activated, so an instance whose module was upgraded
	 * without being activated again does not have it yet. The tested method answers the received
	 * documents alone in that case, which is not what these two tests are about: they are skipped
	 * rather than turned red on a state of the database.
	 *
	 * @param	string	$flowid		Flow id assigned by the platform
	 * @param	string	$reason		Reason code that queued it
	 * @return	void
	 */
	private function queueFlow($flowid, $reason = 'PRODUCT_NOT_FOUND')
	{
		global $db, $user, $conf;

		if (!in_array(MAIN_DB_PREFIX . "einvoicing_sync_pending", (array) $db->DDLListTables($db->database_name, MAIN_DB_PREFIX . "einvoicing_sync_pending"), true)) {
			$this->markTestSkipped('This Dolibarr has no ' . MAIN_DB_PREFIX . 'einvoicing_sync_pending table: activate the module to create it.');
		}

		$sql = "INSERT INTO " . MAIN_DB_PREFIX . "einvoicing_sync_pending";
		$sql .= " (entity, provider, flow_id, flow_direction, flow_type, tracking_idref, reason_code, match_data, status, date_creation, fk_user_creat)";
		$sql .= " VALUES (" . ((int) $conf->entity) . ", 'TEST', '" . $db->escape($flowid) . "', 'In', 'SupplierInvoice', 'FA-1050-1', '" . $db->escape($reason) . "',";
		$sql .= " '" . $db->escape('{"name":"Vendor of the flow list test"}') . "', 0, '" . $db->idate(dol_now()) . "', " . ((int) $user->id) . ")";

		$this->assertNotFalse($db->query($sql), 'Could not queue the flow: ' . $db->lasterror());
	}

	/**
	 * Write a row in the documents received from the platform.
	 *
	 * @param	string	$flowid		Flow id assigned by the platform
	 * @param	string	$direction	'In' for a received document, 'Out' for one we sent
	 * @return	void
	 */
	private function receiveFlow($flowid, $direction = 'In')
	{
		global $db, $user, $conf;

		$sql = "INSERT INTO " . MAIN_DB_PREFIX . "einvoicing_document";
		$sql .= " (entity, provider, flow_id, flow_direction, flow_type, tracking_idref, status, submittedat, date_creation, fk_user_creat)";
		$sql .= " VALUES (" . ((int) $conf->entity) . ", 'TEST', '" . $db->escape($flowid) . "', '" . $db->escape($direction) . "', 'SupplierInvoice', 'FA-1050-2', 0,";
		$sql .= " '" . $db->idate(dol_now()) . "', '" . $db->idate(dol_now()) . "', " . ((int) $user->id) . ")";

		$this->assertNotFalse($db->query($sql), 'Could not record the received document: ' . $db->lasterror());
	}

	/**
	 * The flows the mapping page offers, read as a map of flow id to entry.
	 *
	 * @return	array<string,array<string,mixed>>	Entries, keyed by flow id
	 */
	private function offeredFlows()
	{
		global $db;

		$offered = array();
		foreach (Document::listIncomingFlowsForMapping($db, 200) as $flow) {
			$this->assertArrayNotHasKey($flow['flowid'], $offered, 'The flow ' . $flow['flowid'] . ' is offered twice');
			$offered[$flow['flowid']] = $flow;
		}

		return $offered;
	}

	/**
	 * A flow waiting in the queue and a document already received are both worth mapping: the queue
	 * holds the flows blocked on a missing product, and a received document is where a mapping is
	 * prepared for the next invoice of that vendor.
	 *
	 * @return void
	 */
	public function testAQueuedFlowAndAReceivedOneAreBothOffered()
	{
		$this->queueFlow(self::FLOW_QUEUED);
		$this->receiveFlow(self::FLOW_RECEIVED);

		$offered = $this->offeredFlows();

		$this->assertArrayHasKey(self::FLOW_QUEUED, $offered, 'A flow waiting in the synchronization queue is not offered');
		$this->assertArrayHasKey(self::FLOW_RECEIVED, $offered, 'A document already received is not offered');
		$this->assertEquals(1, $offered[self::FLOW_QUEUED]['pending'], 'A queued flow is not reported as pending');
		$this->assertEquals('PRODUCT_NOT_FOUND', $offered[self::FLOW_QUEUED]['reason'], 'A queued flow does not carry the reason it is waiting');
		$this->assertEquals(0, $offered[self::FLOW_RECEIVED]['pending'], 'A received document is reported as pending');
	}

	/**
	 * A flow that was received and then queued again is one flow: it is listed once, as the queued
	 * one, since that is the state the user has something to do about.
	 *
	 * @return void
	 */
	public function testAFlowHeldByBothSourcesIsOfferedOnceAsQueued()
	{
		$this->queueFlow(self::FLOW_BOTH);
		$this->receiveFlow(self::FLOW_BOTH);

		$offered = $this->offeredFlows();

		$this->assertArrayHasKey(self::FLOW_BOTH, $offered, 'The flow held by both sources is not offered');
		$this->assertEquals(1, $offered[self::FLOW_BOTH]['pending'], 'The flow held by both sources is not reported as pending');
	}

	/**
	 * A document we sent carries no vendor product reference to map: the list is about what we receive.
	 *
	 * @return void
	 */
	public function testADocumentWeSentIsNotOffered()
	{
		$this->receiveFlow(self::FLOW_OUTGOING, 'Out');

		$this->assertArrayNotHasKey(self::FLOW_OUTGOING, $this->offeredFlows(), 'A document we sent is offered to the product mapping');
	}
}
