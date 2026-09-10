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
 *      \file       test/phpunit/LineLinkedDocumentTest.php
 *      \ingroup    test
 *      \brief      PHPUnit test for the documents a received line references
 *                  (EN 16931 BT-128, ram:AdditionalReferencedDocument).
 *                  The reference is a convenience used to attach a deposit, not a condition for
 *                  the invoice to be valid, and the flow puts whatever it likes there - a contract
 *                  number among others. One that matches nothing in Dolibarr must be reported and
 *                  stepped over, not turn a valid invoice into a failure that stops the whole
 *                  synchronization.
 *      \remarks    To run this script as CLI: phpunit filename.php
 */

global $conf, $user, $langs, $db;

$dolibarrHtdocs = getenv('DOLIBARR_HTDOCS');
if (!$dolibarrHtdocs) {
	$dolibarrHtdocs = dirname(__FILE__) . '/../../htdocs';
}
if (!file_exists($dolibarrHtdocs . '/master.inc.php')) {
	throw new \RuntimeException('Could not locate master.inc.php under "' . $dolibarrHtdocs . '/". Set the environment variable (export DOLIBARR_HTDOCS=...) to the htdocs directory of the Dolibarr instance to test against.');
}

require_once $dolibarrHtdocs . '/master.inc.php';
dol_include_once('einvoicing/class/protocols/CIIProtocol.class.php');
require_once __DIR__ . '/CommonClassTestCompat.inc.php';

/**
 * Class for PHPUnit tests
 *
 * @backupGlobals disabled
 * @backupStaticAttributes enabled
 * @remarks	backupGlobals must be disabled to have db,conf,user and lang not erased.
 */
class LineLinkedDocumentTest extends CommonClassTest
{
	/**
	 * Call the protected CIIProtocol::resolveLineLinkedDocuments() through reflection. Only reads
	 * are involved on a reference that matches nothing, so nothing is written by these tests.
	 *
	 * @param	array	$parsedLine			Parsed line, as parseInvoiceLines() returns it
	 * @param	array	$return_messages	Messages of the import, completed by the call
	 * @return	array{res:int,message?:string,is_deposit:int,fk_remise:int}
	 */
	private function resolveLinks(array $parsedLine, array &$return_messages): array
	{
		$method = new ReflectionMethod(CIIProtocol::class, 'resolveLineLinkedDocuments');
		$method->setAccessible(true);

		return $method->invokeArgs(new CIIProtocol($GLOBALS['db']), [$parsedLine, &$return_messages]);
	}

	/**
	 * A line whose referenced document is unknown here keeps its invoice importable.
	 *
	 * @return void
	 */
	public function testAnUnknownLinkedDocumentDoesNotAbortTheImport()
	{
		$messages = [];
		$resolved = $this->resolveLinks([
			'lineid' => 1,
			'supplierId' => 1,
			'additionalRefDocs' => [['IssuerAssignedID' => 'CT-2026-0042', 'typeCode' => '916']],
		], $messages);

		$this->assertGreaterThanOrEqual(0, $resolved['res'], 'An unknown reference must not fail the line');
		$this->assertSame(0, $resolved['is_deposit'], 'Nothing was resolved, so no deposit was found');
		$this->assertSame(0, $resolved['fk_remise'], 'and no discount goes with it');
	}

	/**
	 * Stepping over a reference has to leave a trace, or the missing link is invisible.
	 *
	 * @return void
	 */
	public function testTheUnknownReferenceIsReported()
	{
		$messages = [];
		$this->resolveLinks([
			'lineid' => 7,
			'supplierId' => 1,
			'additionalRefDocs' => [['IssuerAssignedID' => 'CT-2026-0042', 'typeCode' => '916']],
		], $messages);

		$this->assertNotEmpty($messages, 'The skipped reference must be reported');
		$this->assertStringContainsString('CT-2026-0042', implode("\n", $messages), 'and it must name the reference');
	}

	/**
	 * A line referencing nothing must go through untouched, and stay silent.
	 *
	 * @return void
	 */
	public function testALineWithoutAnyReferenceResolvesToNothing()
	{
		$messages = [];
		$resolved = $this->resolveLinks(['lineid' => 1, 'supplierId' => 1, 'additionalRefDocs' => []], $messages);

		$this->assertSame(1, $resolved['res']);
		$this->assertSame(0, $resolved['is_deposit']);
		$this->assertSame([], $messages, 'Nothing to report when nothing is referenced');
	}

	/**
	 * An empty reference identifier is not a lookup, so it is stepped over without a word.
	 *
	 * @return void
	 */
	public function testAnEmptyReferenceIsIgnoredSilently()
	{
		$messages = [];
		$resolved = $this->resolveLinks([
			'lineid' => 1,
			'supplierId' => 1,
			'additionalRefDocs' => [['IssuerAssignedID' => '', 'typeCode' => '130']],
		], $messages);

		$this->assertSame(1, $resolved['res']);
		$this->assertSame([], $messages, 'An empty reference has nothing to report');
	}
}
