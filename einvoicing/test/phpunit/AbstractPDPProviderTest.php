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
 *      \file       test/phpunit/AbstractPDPProviderTest.php
 *      \ingroup    test
 *      \brief      PHPUnit test for AbstractPDPProvider::makeStorableDebugPayload(): the payloads written
 *                  in the trace of an API call stay inside their column. A response bigger than the column
 *                  had its INSERT refused whole, so the call left no trace at all (issue #995).
 *                  Also on replayPostponedFlows(): the flows waiting on something missing here are taken
 *                  again by identifier, so leaving the synchronization window no longer loses them.
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
dol_include_once('einvoicing/class/providers/AbstractPDPProvider.class.php');
// AbstractPDPProvider is abstract: its reference implementation is the one instantiated here.
dol_include_once('einvoicing/class/providers/TestPDPProvider.class.php');
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
 * Provider whose syncFlow() answers what a test scripted for each flow, and which exposes the replay.
 * Nothing else of the provider is needed: the replay decides on the answer, not on the platform.
 */
class ReplayScriptedProvider extends TestPDPProvider
{
	/** @var array<string,array<string,mixed>> Answer to give for each flow id */
	public $scripted = array();
	/** @var string[] Flow ids syncFlow() was called on, in order */
	public $asked = array();

	/**
	 * Answer the scripted result instead of calling the access point.
	 *
	 * @param	string	$flowId		Flow id to synchronize
	 * @param	?int	$call_id	Id of the call row of the run
	 * @return	array<string,mixed>	The scripted result
	 */
	public function syncFlow($flowId, $call_id = null)
	{
		$this->asked[] = $flowId;

		return isset($this->scripted[$flowId]) ? $this->scripted[$flowId] : array('res' => -1, 'message' => 'nothing scripted for ' . $flowId);
	}

	/**
	 * Reach the protected replay the synchronization runs at its head.
	 *
	 * @param	string[]							$messages	Run messages, appended to
	 * @param	array<string,array<string,mixed>>	$actions	Manual actions to do, appended to
	 * @return	array{imported:int,waiting:int,handled:string[]}	What the replay did
	 */
	public function replay(&$messages, &$actions)
	{
		return $this->replayPostponedFlows($messages, $actions);
	}
}


/**
 * Class for PHPUnit tests
 *
 * @backupGlobals disabled
 * @backupStaticAttributes enabled
 * @remarks	backupGlobals must be disabled to have db,conf,user and lang not erased.
 */
class AbstractPDPProviderTest extends CommonClassTest
{
	/**
	 * Call the protected makeStorableDebugPayload() of AbstractPDPProvider.
	 *
	 * @param	string|null	$payload	Payload as the provider received it
	 * @return	string					What would be written in the trace
	 */
	private function makeStorable($payload)
	{
		// Without the constructor: the method under test reads no property, and no setup is loaded
		$provider = (new ReflectionClass('TestPDPProvider'))->newInstanceWithoutConstructor();
		$method = new ReflectionMethod('AbstractPDPProvider', 'makeStorableDebugPayload');
		$method->setAccessible(true);

		return $method->invoke($provider, $payload);
	}

	/**
	 * A payload that fits its column is stored as it is.
	 *
	 * @return	void
	 */
	public function testPayloadThatFitsIsUntouched()
	{
		$xml = '<?xml version="1.0" encoding="UTF-8"?><Invoice>' . str_repeat('<Line>Caf&#233; 1,00</Line>', 100) . '</Invoice>';

		$this->assertSame($xml, $this->makeStorable($xml), 'A payload under the limit must be stored unchanged');
		$this->assertSame('', $this->makeStorable(''), 'An empty payload stays empty');
		$this->assertSame('', $this->makeStorable(null), 'A null payload is stored as an empty string');
	}

	/**
	 * A payload over the limit is stored truncated and says so, instead of losing the whole trace.
	 *
	 * @return	void
	 */
	public function testOversizedPayloadIsTruncatedAndMarked()
	{
		$max = AbstractPDPProvider::LOGCALL_MAX_PAYLOAD_SIZE;
		$xml = str_repeat('a', $max + 5000);

		$stored = $this->makeStorable($xml);

		$this->assertLessThanOrEqual($max, strlen($stored), 'A stored payload never exceeds the size of its column');
		$this->assertSame(str_repeat('a', 1000), substr($stored, 0, 1000), 'The beginning of the payload is the one received');

		// What the marker announces is really what was dropped
		$reg = array();
		$this->assertSame(1, preg_match('/\n\[truncated: (\d+) more bytes\]$/', $stored, $reg), 'A truncated payload says how much it left out');
		$kept = strlen($stored) - strlen($reg[0]);
		$this->assertSame(strlen($xml), $kept + (int) $reg[1], 'Kept bytes plus dropped bytes make the payload back');
	}

	/**
	 * The cut never leaves a multi-byte character in half: half a character makes the column invalid
	 * UTF-8, which is the SQL error 1366 the storable payload exists to avoid.
	 *
	 * @return	void
	 */
	public function testTruncationCutsOnACharacterBoundary()
	{
		$max = AbstractPDPProvider::LOGCALL_MAX_PAYLOAD_SIZE;

		// Four sizes so the cut falls on each byte of the "é" (2 bytes) and of the "€" (3 bytes)
		foreach (array(0, 1, 2, 3) as $shift) {
			$payload = str_repeat('a', $max - 64 - $shift) . str_repeat('é€', 100);

			$stored = $this->makeStorable($payload);

			$this->assertSame(1, preg_match('//u', $stored), 'A truncated payload stays valid UTF-8 (shift ' . $shift . ')');
			$this->assertLessThanOrEqual($max, strlen($stored), 'A stored payload never exceeds the size of its column');
		}
	}

	/**
	 * A binary payload is base64-encoded, and what is stored of it decodes: the trace is cut on a
	 * 4-character boundary, not in the middle of an encoded group.
	 *
	 * @return	void
	 */
	public function testBinaryPayloadStaysDecodable()
	{
		$binary = str_repeat("\x00\xff\xfe", 100);	// not valid UTF-8

		$stored = $this->makeStorable($binary);
		$this->assertStringStartsWith('[base64] ', $stored, 'A binary payload is stored base64-encoded');
		$this->assertSame($binary, base64_decode(substr($stored, strlen('[base64] '))), 'A short binary payload is stored whole');

		$max = AbstractPDPProvider::LOGCALL_MAX_PAYLOAD_SIZE;
		$big = str_repeat("\x00\xff\xfe", $max);

		$stored = $this->makeStorable($big);
		$this->assertLessThanOrEqual($max, strlen($stored), 'A stored payload never exceeds the size of its column');
		$this->assertSame(1, preg_match('/\n\[truncated: \d+ more bytes\]$/', $stored), 'A truncated payload says how much it left out');

		$encoded = substr($stored, strlen('[base64] '), strpos($stored, "\n[truncated:") - strlen('[base64] '));
		$decoded = base64_decode($encoded, true);
		$this->assertNotSame(false, $decoded, 'What is stored of a binary payload can still be decoded');
		$this->assertSame(substr($big, 0, strlen($decoded)), $decoded, 'It decodes to the beginning of the payload');
	}

	/** @var string Provider key of the rows written by the replay tests, so they cannot mix with a real queue */
	const REPLAY_PROVIDER = 'TESTPDP';

	/**
	 * A provider whose queue holds exactly the flows given, and whose syncFlow() answers what is scripted.
	 *
	 * @param	array<string,array<string,mixed>>	$rows		Flow id => what to queue (reason, action, actiondata)
	 * @param	array<string,array<string,mixed>>	$scripted	Flow id => what syncFlow() answers
	 * @return	ReplayScriptedProvider							The provider, its queue filled
	 */
	private function providerWithQueue($rows, $scripted)
	{
		global $db, $user;

		$db->query("DELETE FROM " . $db->prefix() . "einvoicing_sync_pending WHERE provider = '" . $db->escape(self::REPLAY_PROVIDER) . "'");

		$queue = new EInvoicingSyncPending($db);
		foreach ($rows as $flowId => $row) {
			$flow = array('flowId' => $flowId, 'flowDirection' => 'In', 'flowType' => 'SupplierInvoice', 'trackingId' => 'TEST-' . $flowId);
			$queue->queueFromFlow(
				$flow,
				self::REPLAY_PROVIDER,
				(string) $row['reason'],
				'queued for the test',
				isset($row['actiondata']) ? $row['actiondata'] : array(),
				$user,
				isset($row['action']) ? (string) $row['action'] : '',
				isset($row['matchdata']) ? $row['matchdata'] : array()
			);
		}

		$provider = new ReplayScriptedProvider($db);
		// The short key of the access point is what the queue is read on: a partner suffix is not part of it.
		$provider->providerName = self::REPLAY_PROVIDER . 'ViaPartner';
		$provider->scripted = $scripted;

		return $provider;
	}

	/**
	 * Read the row of one flow as the database holds it.
	 *
	 * @param	string	$flowId	Flow id
	 * @return	?stdClass		Its row, or null when the flow has none
	 */
	private function queueRow($flowId)
	{
		global $db;

		$resql = $db->query("SELECT * FROM " . $db->prefix() . "einvoicing_sync_pending WHERE provider = '" . $db->escape(self::REPLAY_PROVIDER) . "' AND flow_id = '" . $db->escape($flowId) . "'");
		if (!$resql) {
			return null;
		}
		$row = $db->fetch_object($resql);

		return empty($row) ? null : $row;
	}

	/**
	 * With the option unset - the delivery default - the replay does nothing at all: no flow is asked for,
	 * and the queue is left exactly as it was.
	 *
	 * @return void
	 */
	public function testTheReplayDoesNothingWithoutTheOption()
	{
		global $conf;

		unset($conf->global->EINVOICING_ENABLE_POSTPONE_FLOWS);

		$provider = $this->providerWithQueue(
			array('i_off_1' => array('reason' => 'LINKED_INVOICE_NOT_FOUND')),
			array('i_off_1' => array('res' => 1, 'message' => 'imported'))
		);

		$messages = array();
		$actions = array();
		$replay = $provider->replay($messages, $actions);

		$this->assertSame(array('imported' => 0, 'waiting' => 0, 'handled' => array()), $replay, 'An unset option means no replay at all');
		$this->assertSame(array(), $provider->asked, 'No flow is asked for when the option is unset');
		$this->assertSame(array(), $messages);
		$this->assertSame(array(), $actions);

		$row = $this->queueRow('i_off_1');
		$this->assertSame(1, (int) $row->nb_attempts, 'The waiting row is not even touched');
		$this->assertSame((int) EInvoicingSyncPending::STATUS_PENDING, (int) $row->status);
	}

	/**
	 * A waiting flow is asked for by identifier and, when it goes through, its row leaves the queue. This
	 * is what makes "retried on the next synchronization" true once the window has moved past the flow.
	 *
	 * @return void
	 */
	public function testAWaitingFlowIsTakenAgainByIdentifierAndResolved()
	{
		global $conf;

		$conf->global->EINVOICING_ENABLE_POSTPONE_FLOWS = 1;

		$provider = $this->providerWithQueue(
			array('i_ok_1' => array('reason' => 'LINKED_INVOICE_NOT_FOUND')),
			array('i_ok_1' => array('res' => 1, 'message' => 'supplier invoice created'))
		);

		$messages = array();
		$actions = array();
		$replay = $provider->replay($messages, $actions);

		$this->assertSame(array('i_ok_1'), $provider->asked, 'The waiting flow is asked for by its identifier');
		$this->assertSame(1, $replay['imported']);
		$this->assertSame(0, $replay['waiting']);
		$this->assertSame(array('i_ok_1'), $replay['handled'], 'The window loop is told which flows it can skip');
		$this->assertCount(1, $messages);
		$this->assertStringContainsString('supplier invoice created', $messages[0], 'The run says what became of the flow');
		$this->assertSame(array(), $actions, 'A flow that went through asks for no manual action');

		$row = $this->queueRow('i_ok_1');
		$this->assertSame((int) EInvoicingSyncPending::STATUS_RESOLVED, (int) $row->status, 'Its row never outlives its cause');
		$this->assertSame('invoice_supplier', $row->fk_element_type);

		// A flow that now already exists (res 0) is resolved just the same: nothing is left waiting for it.
		$provider = $this->providerWithQueue(
			array('i_ok_2' => array('reason' => 'CONVERSION_FORMAT_NOT_SUPPORTED')),
			array('i_ok_2' => array('res' => 0, 'message' => 'already imported'))
		);
		$messages = array();
		$actions = array();
		$replay = $provider->replay($messages, $actions);
		$this->assertSame(1, $replay['imported']);
		$this->assertSame((int) EInvoicingSyncPending::STATUS_RESOLVED, (int) $this->queueRow('i_ok_2')->status);
	}

	/**
	 * A flow that still cannot be synchronized stays in the queue with its actions, is reported, and is
	 * never counted as an error: one flow nobody can unblock must not abort every run from here on.
	 *
	 * @return void
	 */
	public function testAFlowThatStillWaitsKeepsItsRowAndItsActions()
	{
		global $conf;

		$conf->global->EINVOICING_ENABLE_POSTPONE_FLOWS = 1;

		$provider = $this->providerWithQueue(
			array('i_wait_1' => array(
				'reason' => 'LINKED_INVOICE_NOT_FOUND',
				'action' => '<a class="butAction">Create the missing invoice</a>',
				'actiondata' => array(array('key' => 'create', 'url' => '/fourn/facture/card.php?action=create', 'label' => '')),
				'matchdata' => array('socid' => 42, 'supplierref' => 'TEST-AC-0001'),
			)),
			// The second attempt knows nothing but that it failed: the row must not lose what it holds.
			array('i_wait_1' => array('res' => -1, 'postponeflow' => 1, 'message' => 'still not found in Dolibarr'))
		);
		$before = $this->queueRow('i_wait_1');

		$messages = array();
		$actions = array();
		$replay = $provider->replay($messages, $actions);

		$this->assertSame(0, $replay['imported']);
		$this->assertSame(1, $replay['waiting'], 'It is counted as waiting, not as an error');
		$this->assertSame(array('i_wait_1'), $replay['handled']);
		$this->assertCount(1, $messages);
		$this->assertStringContainsString('still cannot be synchronized', $messages[0]);

		// The operator is told what to do, with what the row holds when the new attempt brings nothing.
		$this->assertArrayHasKey('LINKED_INVOICE_NOT_FOUND', $actions);
		$this->assertStringContainsString('Create the missing invoice', $actions['LINKED_INVOICE_NOT_FOUND']['action']);
		$this->assertSame('queued for the test', $actions['LINKED_INVOICE_NOT_FOUND']['businessmessage']);

		$after = $this->queueRow('i_wait_1');
		$this->assertSame((int) EInvoicingSyncPending::STATUS_PENDING, (int) $after->status, 'It stays waiting');
		$this->assertSame(2, (int) $after->nb_attempts, 'And says how many runs met it');
		$this->assertSame($before->date_creation, $after->date_creation, 'The date it was first seen never moves');
		$this->assertSame($before->action_data, $after->action_data, 'Its manual actions survive the attempt');
		$this->assertSame($before->action_html, $after->action_html);
		$this->assertSame($before->match_data, $after->match_data);
	}

	/**
	 * The rows the manual-action queue wrote are taken again the same way: once the product or the third
	 * party exists, the flow lands on the next run instead of waiting for somebody to press retry. Their
	 * actions are refreshed when the new attempt computes some.
	 *
	 * @return void
	 */
	public function testTheRowsOfTheManualActionQueueAreTakenAgainToo()
	{
		global $conf;

		$conf->global->EINVOICING_ENABLE_POSTPONE_FLOWS = 1;
		unset($conf->global->EINVOICING_ENABLE_MANUAL_ACTION_QUEUE);

		$provider = $this->providerWithQueue(
			array(
				'i_mq_1' => array('reason' => 'PRODUCT_NOT_FOUND', 'action' => '<a>Create the product</a>'),
				'i_mq_2' => array('reason' => 'THIRDPARTY_NOT_FOUND', 'action' => '<a>Create the thirdparty</a>'),
			),
			array(
				'i_mq_1' => array('res' => 1, 'message' => 'imported, the product exists now'),
				'i_mq_2' => array('res' => -1, 'message' => 'no thirdparty yet', 'actioncode' => 'THIRDPARTY_NOT_FOUND',
					'allactiondata' => array('createthirdparty' => array('url' => '/societe/card.php?action=create', 'label' => 'Create'))),
			)
		);

		$messages = array();
		$actions = array();
		$replay = $provider->replay($messages, $actions);

		$this->assertSame(array('i_mq_1', 'i_mq_2'), $provider->asked, 'No reason code is left out of the replay');
		$this->assertSame(1, $replay['imported']);
		$this->assertSame(1, $replay['waiting']);
		$this->assertSame((int) EInvoicingSyncPending::STATUS_RESOLVED, (int) $this->queueRow('i_mq_1')->status, 'The manual action was done: its row is resolved');

		$stillwaiting = $this->queueRow('i_mq_2');
		$this->assertSame((int) EInvoicingSyncPending::STATUS_PENDING, (int) $stillwaiting->status);
		$data = json_decode((string) $stillwaiting->action_data, true);
		$this->assertSame('createthirdparty', $data[0]['key'], 'What the new attempt offers is what the queue page shows');
		$this->assertSame('/societe/card.php?action=create', $data[0]['url']);
	}

	/**
	 * The replay costs one call per waiting row, so how many it takes per run is bounded.
	 *
	 * @return void
	 */
	public function testTheReplayIsBoundedBySize()
	{
		global $conf, $db;

		$conf->global->EINVOICING_ENABLE_POSTPONE_FLOWS = 1;
		$conf->global->EINVOICING_FLOWS_SYNC_REPLAY_SIZE = 1;

		$provider = $this->providerWithQueue(
			array(
				'i_cap_old' => array('reason' => 'LINKED_INVOICE_NOT_FOUND'),
				'i_cap_new' => array('reason' => 'LINKED_INVOICE_NOT_FOUND'),
			),
			array('i_cap_old' => array('res' => 1, 'message' => 'imported'), 'i_cap_new' => array('res' => 1, 'message' => 'imported'))
		);
		// Both rows are written in the same second: the order under test needs distinct dates.
		$db->query("UPDATE " . $db->prefix() . "einvoicing_sync_pending SET date_creation = '2026-09-01 08:00:00' WHERE provider = '" . $db->escape(self::REPLAY_PROVIDER) . "' AND flow_id = 'i_cap_old'");
		$db->query("UPDATE " . $db->prefix() . "einvoicing_sync_pending SET date_creation = '2026-09-02 08:00:00' WHERE provider = '" . $db->escape(self::REPLAY_PROVIDER) . "' AND flow_id = 'i_cap_new'");

		$messages = array();
		$actions = array();
		$replay = $provider->replay($messages, $actions);

		$this->assertSame(array('i_cap_old'), $provider->asked, 'The cap is honoured, and what waited longest goes first');
		$this->assertSame(1, $replay['imported']);
		$this->assertSame((int) EInvoicingSyncPending::STATUS_PENDING, (int) $this->queueRow('i_cap_new')->status, 'What did not fit stays waiting for the next run');

		unset($conf->global->EINVOICING_FLOWS_SYNC_REPLAY_SIZE);
	}
}
