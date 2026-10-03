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
 * along with this program. If not, see https://www.gnu.org/licenses/
 */

/**
 *      \file       test/phpunit/EInvoicingSyncPendingTest.php
 *      \ingroup    test
 *      \brief      PHPUnit test for the queue of the flows waiting on something missing in Dolibarr:
 *                  the row now has two writers (the manual-action queue and the postponed flows), so
 *                  what one of them does not bring must not erase what the other computed.
 *      \remarks    To run this script as CLI: phpunit filename.php
 */

global $conf, $user, $langs, $db;

// See RecipientDirectoryTest for why DOLIBARR_HTDOCS is honoured here.
$dolibarrHtdocs = getenv('DOLIBARR_HTDOCS');
if (!$dolibarrHtdocs) {
	$dolibarrHtdocs = dirname(__FILE__) . '/../../htdocs';
}
if (!file_exists($dolibarrHtdocs . '/master.inc.php')) {
	throw new \RuntimeException('Could not locate master.inc.php under "' . $dolibarrHtdocs . '/". Set the environment variable (export DOLIBARR_HTDOCS=...) to the htdocs directory of the Dolibarr instance to test against.');
}

require_once $dolibarrHtdocs . '/master.inc.php';
dol_include_once('einvoicing/class/einvoicingsyncpending.class.php');
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
 * Tests on EInvoicingSyncPending.
 *
 * The rows are written under a provider key of their own, inside the transaction CommonClassTest opens
 * for the class and rolls back afterwards, so a run leaves nothing behind.
 */
class EInvoicingSyncPendingTest extends CommonClassTest
{
	/** @var string Provider key used by the rows written here, so they cannot mix with the real queue */
	const TEST_PROVIDER = 'PHPUNITPDP';

	/**
	 * Start from an empty queue for this provider.
	 *
	 * @return void
	 */
	protected function setUp(): void
	{
		global $db;

		parent::setUp();

		$db->query("DELETE FROM " . $db->prefix() . "einvoicing_sync_pending WHERE provider = '" . $db->escape(self::TEST_PROVIDER) . "'");
	}

	/**
	 * Queue a flow, then read its row back as the database holds it.
	 *
	 * @param	string					$flowId		Flow id
	 * @param	string					$reason		Business reason code
	 * @param	array<int,mixed>		$data		Normalized manual actions
	 * @param	string					$actionHtml	Ready to display action block
	 * @param	array<string,mixed>		$matchData	Issuer identifiers
	 * @return	stdClass							The row of that flow
	 */
	private function queueAndRead($flowId, $reason, $data = array(), $actionHtml = '', $matchData = array())
	{
		global $db, $user;

		$queue = new EInvoicingSyncPending($db);
		$flow = array('flowId' => $flowId, 'flowDirection' => 'In', 'flowType' => 'SupplierInvoice', 'trackingId' => 'PHPUNIT-0001', 'updatedAt' => '2026-09-22T10:00:00.626638Z');
		$this->assertGreaterThan(0, $queue->queueFromFlow($flow, self::TEST_PROVIDER, $reason, 'message of ' . $reason, $data, $user, $actionHtml, $matchData), 'The flow must be queued');

		$resql = $db->query("SELECT * FROM " . $db->prefix() . "einvoicing_sync_pending WHERE provider = '" . $db->escape(self::TEST_PROVIDER) . "' AND flow_id = '" . $db->escape($flowId) . "'");
		$this->assertNotFalse($resql);
		$row = $db->fetch_object($resql);
		$this->assertNotEmpty($row, 'The queued flow must have a row');

		return $row;
	}

	/**
	 * The manual actions a result carries are normalized into the shape the queue stores, whichever of
	 * the two writers hands them over.
	 *
	 * @return void
	 */
	public function testManualActionsAreNormalizedFromTheResult()
	{
		$all = EInvoicingSyncPending::manualActionsFromResult(array(
			'allactiondata' => array(
				'create' => array('url' => '/product/card.php?action=create', 'label' => 'Create'),
				'setdefault' => array('url' => '/einvoicing/routing_card.php', 'label' => ''),
				'nothing' => array('label' => 'no url here'),
			)
		), 'PRODUCT_NOT_FOUND');

		$this->assertCount(2, $all, 'An entry without url is not an action to offer');
		$this->assertSame(array('key' => 'create', 'url' => '/product/card.php?action=create', 'label' => 'Create'), $all[0]);
		$this->assertSame('setdefault', $all[1]['key']);
		$this->assertSame('', $all[1]['label'], 'A missing label is stored empty, not missing');

		// A result with a single url and no detail: the key says what the button does.
		$one = EInvoicingSyncPending::manualActionsFromResult(array('actionurl' => '/societe/card.php?action=create'), 'THIRDPARTY_NOT_FOUND');
		$this->assertSame(array(array('key' => 'createthirdparty', 'url' => '/societe/card.php?action=create', 'label' => '')), $one);

		$one = EInvoicingSyncPending::manualActionsFromResult(array('actionurl' => '/product/card.php?action=create'), 'PRODUCT_NOT_FOUND');
		$this->assertSame('create', $one[0]['key']);

		$this->assertSame(array(), EInvoicingSyncPending::manualActionsFromResult(array('res' => -1), 'LINKED_INVOICE_NOT_FOUND'), 'A result offering nothing gives no action');
		// The postponed results of the protocols say 'none' rather than leaving the key out.
		$this->assertSame(array(), EInvoicingSyncPending::manualActionsFromResult(array('actionurl' => 'none'), 'LINKED_INVOICE_NOT_FOUND'), 'An action url of "none" is not a link to offer');
	}

	/**
	 * A second attempt that does not bring the actions and identifiers of the first one must not erase
	 * them: the queue page renders those columns, and the flow still needs the same manual action.
	 *
	 * @return void
	 */
	public function testQueueFromFlowKeepsWhatTheCallerDoesNotBring()
	{
		$flowId = 'phpunit_keep_' . dol_print_date(dol_now(), 'dayhourlog');

		$first = $this->queueAndRead(
			$flowId,
			'PRODUCT_NOT_FOUND',
			array(array('key' => 'create', 'url' => '/product/card.php?action=create', 'label' => '')),
			'<a class="butAction">Create the product</a>',
			array('socid' => 42, 'supplierref' => 'PHPUNIT-REF')
		);
		$this->assertSame(1, (int) $first->nb_attempts);
		$this->assertNotEmpty($first->action_data);
		$this->assertNotEmpty($first->action_html);
		$this->assertNotEmpty($first->match_data);
		$this->assertSame('2026-09-22 10:00:00', $first->flow_updatedat, 'The fractional seconds of the platform date are dropped, not the date');

		// Same flow, queued again by the writer that knows only the reason and the message.
		$second = $this->queueAndRead($flowId, 'PRODUCT_NOT_FOUND');

		$this->assertSame((int) $first->rowid, (int) $second->rowid, 'A flow already queued is refreshed, not duplicated');
		$this->assertSame(2, (int) $second->nb_attempts, 'The attempt counter follows the attempts');
		$this->assertSame($first->action_data, $second->action_data, 'The manual actions of the row survive an attempt that brings none');
		$this->assertSame($first->action_html, $second->action_html, 'The action block of the row survives too');
		$this->assertSame($first->match_data, $second->match_data, 'So do the identifiers of the issuer');
		$this->assertSame($first->date_creation, $second->date_creation, 'The date the flow was first seen never moves');
	}

	/**
	 * What the synchronization reads to take the waiting flows again: the pending rows of one access
	 * point, the ones waiting longest first.
	 *
	 * @return void
	 */
	public function testFetchPendingAnswersTheWaitingRowsOldestFirst()
	{
		global $db, $user;

		$queue = new EInvoicingSyncPending($db);
		$older = $this->queueAndRead('phpunit_older', 'LINKED_INVOICE_NOT_FOUND');
		$newer = $this->queueAndRead('phpunit_newer', 'PRODUCT_NOT_FOUND');
		$other = $this->queueAndRead('phpunit_other', 'THIRDPARTY_NOT_FOUND');

		// The three rows are created in the same second: the order under test needs distinct dates.
		$db->query("UPDATE " . $db->prefix() . "einvoicing_sync_pending SET date_creation = '2026-09-01 08:00:00' WHERE rowid = " . (int) $older->rowid);
		$db->query("UPDATE " . $db->prefix() . "einvoicing_sync_pending SET date_creation = '2026-09-02 08:00:00' WHERE rowid = " . (int) $newer->rowid);
		// The third one belongs to another access point, and no run of this one must see it.
		$db->query("UPDATE " . $db->prefix() . "einvoicing_sync_pending SET provider = 'OTHERPDP' WHERE rowid = " . (int) $other->rowid);

		$rows = $queue->fetchPending(0, self::TEST_PROVIDER);
		$this->assertIsArray($rows);
		$this->assertCount(2, $rows, 'Only the rows of the access point asked for');
		$this->assertSame('phpunit_older', $rows[0]->flow_id, 'What has been waiting longest comes first');
		$this->assertSame('phpunit_newer', $rows[1]->flow_id);
		$this->assertSame('LINKED_INVOICE_NOT_FOUND', $rows[0]->reason_code, 'The reason is read back, whichever writer queued the row');

		// A resolved flow is not waiting any more.
		$this->assertSame(1, $queue->resolveByFlowId('phpunit_older', self::TEST_PROVIDER, $user, 'invoice_supplier'));
		$rows = $queue->fetchPending(0, self::TEST_PROVIDER);
		$this->assertCount(1, $rows);
		$this->assertSame('phpunit_newer', $rows[0]->flow_id);

		// And the ceiling is honoured: one call per waiting row is what a run costs.
		$this->queueAndRead('phpunit_third', 'LINKED_INVOICE_NOT_FOUND');
		$this->assertCount(2, $queue->fetchPending(0, self::TEST_PROVIDER));
		$this->assertCount(1, $queue->fetchPending(1, self::TEST_PROVIDER));

		$db->query("DELETE FROM " . $db->prefix() . "einvoicing_sync_pending WHERE provider = 'OTHERPDP' AND flow_id = 'phpunit_other'");
	}
}
