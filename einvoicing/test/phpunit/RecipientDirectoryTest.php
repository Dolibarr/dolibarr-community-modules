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
 *      \file       test/phpunit/RecipientDirectoryTest.php
 *      \ingroup    test
 *      \brief      PHPUnit test for AbstractPDPProvider::checkRecipientDirectory(), the AFNOR
 *                  Directory Service (XP Z12-013) reachability precheck: which directory lines
 *                  count as an active reception address, and the
 *                  routable/inactive/undetermined/absent/error statuses derived from them, plus the
 *                  SuperPDP legacy french_directory fallback that only sees a boolean 'is_active'.
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
dol_include_once('einvoicing/class/providers/AbstractPDPProvider.class.php');
dol_include_once('einvoicing/class/providers/SuperPDPProvider.class.php');
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
 * Provider double: exposes the AFNOR directory base so checkRecipientDirectory() runs, and answers
 * the two lookups it performs from a canned queue instead of the network. Every other abstract
 * method of the base class is stubbed out, none of them is reached by the tested code path.
 */
class FakeDirectoryPDPProvider extends AbstractPDPProvider
{
	/** @var array<int,array<string,mixed>> Canned answers, consumed in order by callApi() */
	public $cannedResponses = [];

	/** @var array<int,string> Resources requested so far, in order */
	public $calledResources = [];

	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		parent::__construct($db);

		// Both keys set: getApiUrl('afnor_directory') then answers whatever EINVOICING_LIVE is.
		$this->config['test_afnor_directory_url'] = 'https://example.invalid/afnor-directory/';
		$this->config['prod_afnor_directory_url'] = 'https://example.invalid/afnor-directory/';
	}

	/**
	 * Return the next canned answer instead of calling a platform.
	 *
	 * @param 	string 			$resource 		Resource path
	 * @param 	string 			$method 		HTTP method
	 * @param 	array|string|false $options 	Request body
	 * @param 	array 			$extraHeaders 	Extra HTTP headers
	 * @param 	string 			$callType 		Call type used for logging
	 * @return 	array{status_code:int,response:mixed}
	 */
	public function callApi($resource, $method, $options = false, $extraHeaders = [], $callType = '')
	{
		$this->calledResources[] = $resource;

		$next = array_shift($this->cannedResponses);

		return $next !== null ? $next : array('status_code' => 500, 'response' => '');
	}

	// Unused by checkRecipientDirectory(), only there to satisfy the abstract base class.

	/**
	 * @param  int $mode Mode
	 * @return int
	 */
	public function validateConfiguration($mode = 1)
	{
		return 1;
	}

	/**
	 * @return string|null
	 */
	public function getAccessToken()
	{
		return null;
	}

	/**
	 * @return string|null
	 */
	public function refreshAccessToken()
	{
		return null;
	}

	/**
	 * @return array{res:int,message:string}
	 */
	public function checkHealth()
	{
		return array('res' => 1, 'message' => '');
	}

	/**
	 * @param  int $onlymake Only build the sample
	 * @return array{res:int,message:string}
	 */
	public function sendSampleInvoice($onlymake = 0)
	{
		return array('res' => 1, 'message' => '');
	}

	/**
	 * @param  int    $idinvoice Invoice id
	 * @param  string $filePath  File to validate
	 * @return array{res:int,message:string}
	 */
	public function validateEInvoiceFile($idinvoice, $filePath)
	{
		return array('res' => 1, 'message' => '');
	}

	/**
	 * @param  int $syncFromDate Sync from
	 * @param  int $limit        Max flows
	 * @return array{res:int,messages:array<string>}
	 */
	public function syncFlows($syncFromDate = 0, $limit = 0)
	{
		return array('res' => 1, 'messages' => array());
	}

	/**
	 * @param  string  $flowId  Flow id
	 * @param  ?string $call_id Call id
	 * @return array{res:int,message:string}
	 */
	public function syncFlow($flowId, $call_id = null)
	{
		return array('res' => 1, 'message' => '');
	}

	/**
	 * @param  object $object Invoice
	 * @return int
	 */
	public function sendInvoice($object)
	{
		return 0;
	}

	/**
	 * Declared with the widest signature on purpose: a provider may take an extra optional
	 * argument for the payment details, and a child that only adds an optional parameter stays
	 * compatible with the narrower declaration too.
	 *
	 * @param  object $object      Invoice
	 * @param  int    $statusCode  Lifecycle status
	 * @param  string $reasonCode  Reason code
	 * @param  array  $paymentData Payment details carried by some statuses
	 * @return array{res:int,message:string}
	 */
	public function sendStatusMessage($object, $statusCode, $reasonCode = '', $paymentData = [])
	{
		return array('res' => 1, 'message' => '');
	}
}


/**
 * Provider double for the SuperPDP legacy fallback: answers the french_directory lookup from a canned
 * queue, and leaves the AFNOR directory base empty so checkRecipientDirectory() finds the standardized
 * lookup unsupported and falls back to that legacy endpoint, which is what these tests exercise.
 */
class FakeLegacySuperPDPProvider extends SuperPDPProvider
{
	/** @var array<int,array<string,mixed>> Canned answers, consumed in order by callApi() */
	public $cannedResponses = [];

	/** @var array<int,string> Resources requested so far, in order */
	public $calledResources = [];

	/**
	 * Constructor. The real one loads the OAuth token from database and instantiates the protocol
	 * manager: the directory path uses neither, so it is deliberately not called.
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Return the next canned answer instead of calling a platform.
	 *
	 * @param 	string 			$resource 		Resource path
	 * @param 	string 			$method 		HTTP method
	 * @param 	array|string|false $params	 	Request body
	 * @param 	array 			$extraHeaders 	Extra HTTP headers
	 * @param 	string 			$callType 		Call type used for logging
	 * @return 	array{status_code:int,response:mixed}
	 */
	public function callApi($resource, $method, $params = false, $extraHeaders = [], $callType = '')
	{
		$this->calledResources[] = $resource;

		$next = array_shift($this->cannedResponses);

		return $next !== null ? $next : array('status_code' => 500, 'response' => '');
	}
}


/**
 * Class for PHPUnit tests
 *
 * @backupGlobals disabled
 * @backupStaticAttributes enabled
 * @remarks	backupGlobals must be disabled to have db,conf,user and lang not erased.
 */
class RecipientDirectoryTest extends CommonClassTest
{
	/**
	 * Build a provider double whose directory search answers the given lines.
	 *
	 * @param	array<int,array<string,mixed>>	$lines		Directory lines returned by the search
	 * @return	FakeDirectoryPDPProvider
	 */
	private function providerReturningLines($lines)
	{
		global $db;

		$provider = new FakeDirectoryPDPProvider($db);
		$provider->cannedResponses = array(
			array('status_code' => 200, 'response' => array('results' => $lines, 'totalNumberOfResults' => count($lines))),
		);

		return $provider;
	}

	/**
	 * A SIREN with only enabled lines is routable, and the first of them is the returned address.
	 *
	 * @return void
	 */
	public function testEnabledLinesAreRoutable()
	{
		$provider = $this->providerReturningLines(array(
			array('addressingIdentifier' => '899047773', 'directoryLineStatus' => 'Enabled', 'platformType' => 'WK', 'siren' => '899047773'),
			array('addressingIdentifier' => '899047773_89904777300036', 'directoryLineStatus' => 'Enabled', 'siren' => '899047773'),
		));

		$result = $provider->checkRecipientDirectory('899047773');

		$this->assertSame('routable', $result['status']);
		$this->assertSame(1, $result['reachable']);
		$this->assertSame(2, $result['entries']);
		$this->assertSame(2, $result['active']);
		$this->assertSame('899047773', $result['identifier']);
		// Provenance of the positive answer, displayed next to the address on the invoice card.
		$this->assertSame('Enabled', $result['linestatus']);
		$this->assertSame('WK', $result['platform']);
	}

	/**
	 * A line that is declared but not open yet ('Upcoming') must not be counted as an active
	 * reception address, and must not be handed back as the address to send to.
	 *
	 * @return void
	 */
	public function testOnlyEnabledLinesAreCountedActive()
	{
		$provider = $this->providerReturningLines(array(
			array('addressingIdentifier' => '552081317_ACHATPUB', 'directoryLineStatus' => 'Upcoming', 'siren' => '552081317'),
			array('addressingIdentifier' => '552081317', 'directoryLineStatus' => 'Enabled', 'siren' => '552081317'),
		));

		$result = $provider->checkRecipientDirectory('552081317');

		$this->assertSame('routable', $result['status']);
		$this->assertSame(2, $result['entries']);
		$this->assertSame(1, $result['active']);
		$this->assertSame('552081317', $result['identifier']);
	}

	/**
	 * A SIREN present in the annuaire but whose every line is still closed cannot receive: it is
	 * reported inactive, not routable. Only one call is made, the SIREN consultation is for the
	 * "no line at all" case.
	 *
	 * @return void
	 */
	public function testNoEnabledLineIsInactive()
	{
		$provider = $this->providerReturningLines(array(
			array('addressingIdentifier' => '552081317_ACHATPUB', 'directoryLineStatus' => 'Upcoming', 'platformType' => 'WK', 'siren' => '552081317'),
		));

		$result = $provider->checkRecipientDirectory('552081317');

		$this->assertSame('inactive', $result['status']);
		$this->assertSame(0, $result['reachable']);
		$this->assertSame(1, $result['entries']);
		$this->assertSame(0, $result['active']);
		$this->assertSame('', $result['identifier']);
		// Why it is not reachable: the line waits for its effective date, it is not a closed line.
		$this->assertSame('Upcoming', $result['linestatus']);
		$this->assertSame('WK', $result['platform']);
		$this->assertCount(1, $provider->calledResources);
	}

	/**
	 * directoryLineStatus cannot be requested (the search only accepts addressingIdentifier, siren,
	 * siret and addressingSuffix in 'fields'), so a platform may answer without it. Such a line proves
	 * nothing: an enabled address and one that only takes effect later are indistinguishable, so the
	 * check stays non-conclusive instead of reporting the recipient as reachable.
	 *
	 * @return void
	 */
	public function testLineWithoutStatusIsUndetermined()
	{
		$provider = $this->providerReturningLines(array(
			array('addressingIdentifier' => '393078647', 'siren' => '393078647'),
			array('addressingIdentifier' => '393078647_1', 'directoryLineStatus' => null, 'siren' => '393078647'),
		));

		$result = $provider->checkRecipientDirectory('393078647');

		$this->assertSame('undetermined', $result['status']);
		$this->assertSame(-1, $result['reachable']);
		$this->assertSame(0, $result['active']);
		$this->assertSame(2, $result['unknown']);
		$this->assertSame('393078647', $result['identifier']);
	}

	/**
	 * A line without status alongside an enabled one does not hold the answer back: the enabled line
	 * is enough to conclude, and it is the address handed back.
	 *
	 * @return void
	 */
	public function testEnabledLineWinsOverLineWithoutStatus()
	{
		$provider = $this->providerReturningLines(array(
			array('addressingIdentifier' => '393078647_1', 'siren' => '393078647'),
			array('addressingIdentifier' => '393078647', 'directoryLineStatus' => 'Enabled', 'siren' => '393078647'),
		));

		$result = $provider->checkRecipientDirectory('393078647');

		$this->assertSame('routable', $result['status']);
		$this->assertSame(1, $result['active']);
		$this->assertSame(1, $result['unknown']);
		$this->assertSame('393078647', $result['identifier']);
	}

	/**
	 * No directory line but a SIREN known to the annuaire: the legal unit exists and simply cannot
	 * receive yet.
	 *
	 * @return void
	 */
	public function testNoLineButKnownSirenIsInactive()
	{
		global $db;

		$provider = new FakeDirectoryPDPProvider($db);
		$provider->cannedResponses = array(
			array('status_code' => 200, 'response' => array('results' => array(), 'totalNumberOfResults' => 0)),
			array('status_code' => 200, 'response' => array('results' => array(array('siren' => '552081317', 'businessName' => 'RENAULT')), 'totalNumberOfResults' => 1)),
		);

		$result = $provider->checkRecipientDirectory('552081317');

		$this->assertSame('inactive', $result['status']);
		$this->assertSame(0, $result['reachable']);
		$this->assertSame(0, $result['entries']);
		// The consultation goes through the search form, the only one every platform serves.
		$this->assertSame('afnor-directory/v1/siren/search', $provider->calledResources[1]);
	}

	/**
	 * No directory line and a SIREN unknown to the annuaire, on a platform that says so with a 404.
	 *
	 * @return void
	 */
	public function testNoLineAndUnknownSirenIsAbsent()
	{
		global $db;

		$provider = new FakeDirectoryPDPProvider($db);
		$provider->cannedResponses = array(
			array('status_code' => 200, 'response' => array('results' => array(), 'totalNumberOfResults' => 0)),
			array('status_code' => 404, 'response' => ''),
		);

		$result = $provider->checkRecipientDirectory('123456789');

		$this->assertSame('absent', $result['status']);
		$this->assertSame(0, $result['reachable']);
	}

	/**
	 * Same, on a platform that answers the consultation with an empty result set instead of a 404.
	 *
	 * @return void
	 */
	public function testNoLineAndEmptyConsultationIsAbsent()
	{
		global $db;

		$provider = new FakeDirectoryPDPProvider($db);
		$provider->cannedResponses = array(
			array('status_code' => 200, 'response' => array('results' => array(), 'total_number_results' => 0)),
			array('status_code' => 200, 'response' => array('results' => array(), 'total_number_results' => 0)),
		);

		$result = $provider->checkRecipientDirectory('123456789');

		$this->assertSame('absent', $result['status']);
		$this->assertSame(0, $result['reachable']);
	}

	/**
	 * A recipient without a usable SIREN is an error, and costs no API call.
	 *
	 * @return void
	 */
	public function testMissingSirenIsAnError()
	{
		global $db;

		$provider = new FakeDirectoryPDPProvider($db);

		$result = $provider->checkRecipientDirectory('');

		$this->assertSame('error', $result['status']);
		$this->assertSame(-1, $result['reachable']);
		$this->assertCount(0, $provider->calledResources);
	}

	/**
	 * A failing directory call leaves the status unknown (reachable -1) so the caller keeps failing
	 * open instead of blocking a transmission on a directory outage.
	 *
	 * @return void
	 */
	public function testDirectoryCallFailureIsNotConclusive()
	{
		global $db;

		$provider = new FakeDirectoryPDPProvider($db);
		$provider->cannedResponses = array(
			array('status_code' => 503, 'response' => ''),
		);

		$result = $provider->checkRecipientDirectory('899047773');

		$this->assertSame('error', $result['status']);
		$this->assertSame(-1, $result['reachable']);
	}

	/**
	 * Build a SuperPDP double whose legacy french_directory lookup answers the given entries.
	 *
	 * @param	array<int,array<string,mixed>>	$entries	Entries returned by french_directory/entries
	 * @return	FakeLegacySuperPDPProvider
	 */
	private function legacyProviderReturningEntries($entries)
	{
		global $db;

		$provider = new FakeLegacySuperPDPProvider($db);
		$provider->cannedResponses = array(
			array('status_code' => 200, 'response' => array('data' => $entries)),
		);

		return $provider;
	}

	/**
	 * The legacy endpoint only exposes a boolean 'is_active', which the annuaire also sets on an address
	 * that merely takes effect later: it must not conclude 'routable' on that alone, or the invoice card
	 * shows a green badge for a recipient whose transmission will come back as a routing error (fr:213).
	 *
	 * @return void
	 */
	public function testLegacyActiveEntryWithoutDateIsUndetermined()
	{
		$provider = $this->legacyProviderReturningEntries(array(
			array('identifier' => '824369342', 'is_active' => true),
		));

		$result = $provider->checkRecipientDirectory('824369342');

		$this->assertSame('undetermined', $result['status']);
		$this->assertSame(-1, $result['reachable']);
		$this->assertSame(0, $result['active']);
		$this->assertSame(1, $result['unknown']);
		$this->assertSame('824369342', $result['identifier']);
		// The legacy endpoint is what was called: the standardized base is not configured on the double.
		$this->assertSame(array('french_directory/entries?number=824369342'), $provider->calledResources);
	}

	/**
	 * When the legacy payload does date the entry and that date is still ahead, the recipient cannot
	 * receive yet: that is 'inactive', with the date to display.
	 *
	 * @return void
	 */
	public function testLegacyActiveEntryWithFutureDateIsInactive()
	{
		$provider = $this->legacyProviderReturningEntries(array(
			array('identifier' => '824369342', 'is_active' => true, 'start_date' => '07-08-2216'),
		));

		$result = $provider->checkRecipientDirectory('824369342');

		$this->assertSame('inactive', $result['status']);
		$this->assertSame(0, $result['reachable']);
		$this->assertSame(0, $result['active']);
		$this->assertSame('Upcoming', $result['linestatus']);
		$this->assertGreaterThan(dol_now(), $result['effectivedate']);
	}

	/**
	 * An entry that is active and whose effective date has passed is genuinely reachable.
	 *
	 * @return void
	 */
	public function testLegacyActiveEntryWithPastDateIsRoutable()
	{
		$provider = $this->legacyProviderReturningEntries(array(
			array('identifier' => '824369342', 'is_active' => true, 'startDate' => '2024-01-15'),
		));

		$result = $provider->checkRecipientDirectory('824369342');

		$this->assertSame('routable', $result['status']);
		$this->assertSame(1, $result['reachable']);
		$this->assertSame(1, $result['active']);
		$this->assertSame('824369342', $result['identifier']);
	}

	/**
	 * Entries that are not flagged active at all stay 'inactive', as before.
	 *
	 * @return void
	 */
	public function testLegacyInactiveEntryIsInactive()
	{
		$provider = $this->legacyProviderReturningEntries(array(
			array('identifier' => '824369342', 'is_active' => false),
		));

		$result = $provider->checkRecipientDirectory('824369342');

		$this->assertSame('inactive', $result['status']);
		$this->assertSame(0, $result['reachable']);
		$this->assertSame(1, $result['entries']);
	}

	/**
	 * An empty legacy answer still means the recipient is absent from the directory.
	 *
	 * @return void
	 */
	public function testLegacyEmptyAnswerIsAbsent()
	{
		$provider = $this->legacyProviderReturningEntries(array());

		$result = $provider->checkRecipientDirectory('123456789');

		$this->assertSame('absent', $result['status']);
		$this->assertSame(0, $result['reachable']);
	}
}
