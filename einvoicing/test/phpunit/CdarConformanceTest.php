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
 *      \file       test/phpunit/CdarConformanceTest.php
 *      \ingroup    test
 *      \brief      PHPUnit test for the two fields of a lifecycle CDAR that XP Z12-012 constrains and
 *                  that no validator checks: MDT-97 (ReferenceTypeCode), which says what the message is
 *                  about, and MDT-73-1 (the EAS scheme of the recipient address).
 *      \remarks    To run this script as CLI: phpunit filename.php
 */

global $conf, $user, $langs, $db, $mysoc;

// See RecipientDirectoryTest.php for why DOLIBARR_HTDOCS is honoured before the relative path.
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
 * Tests on the CDAR built by CdarHandler::generate().
 *
 * Nothing is written and no platform is called: the generator is handed a data array and the XML it
 * returns is read back.
 */
class CdarConformanceTest extends CommonClassTest
{
	/**
	 * A minimal status CDAR, in the shape generateCdarFile() builds for a status sent as the buyer.
	 *
	 * @param	string	$uriScheme	EAS scheme (MDT-73-1) of the recipient address
	 * @return	array<string,mixed>	Data for CdarHandler::generate()
	 */
	private function statusData($uriScheme)
	{
		return array(
			'GuidelineID' => 'urn.cpro.gouv.fr:1p0:CDV:invoice',
			'ExchangedDocument' => array(
				'ID' => 'REF_205_20260901120000#380_20260901',
				'Name' => 'REF_205_20260901120000#380_20260901',
				'IssueDateTime' => '20260901120000',
				'SenderTradeParty' => array('RoleCode' => CdarHandler::ROLE_WK),
				'IssuerTradeParty' => array(
					'GlobalID' => '000000001',
					'SchemeID' => CdarHandler::SCHEME_SIREN_0002,
					'RoleCode' => CdarHandler::ROLE_BY,
				),
				'RecipientTradeParty' => array(
					'GlobalID' => '424761419',
					'SchemeID' => CdarHandler::SCHEME_SIREN_0002,
					'RoleCode' => CdarHandler::ROLE_SE,
					'URIID' => '424761419',
					'URISchemeID' => $uriScheme,
				),
			),
			'AcknowledgementDocument' => array(
				'MultipleReferencesIndicator' => false,
				'TypeCode' => '23',
				'IssueDateTime' => '20260901120000',
				'ReferenceReferencedDocument' => array(
					'IssuerAssignedID' => 'FR00000001',
					'StatusCode' => CdarHandler::STATUS_IN_PROCESS,
					'TypeCode' => CdarHandler::DOC_INVOICE,
					'ReferenceTypeCode' => CdarHandler::REFERENCE_TYPE_EINVOICE,
					'FormattedIssueDateTime' => '20260901',
					'ProcessConditionCode' => '205',
					'ProcessCondition' => 'Approuvee',
					'SpecifiedDocumentStatus' => array(),
					'IssuerTradeParty' => array(
						'GlobalID' => '424761419',
						'SchemeID' => CdarHandler::SCHEME_SIREN_0002,
						'RoleCode' => CdarHandler::ROLE_SE,
					),
				),
			),
		);
	}

	/**
	 * MDT-97 is written, with the URN rule G7.14 gives for a lifecycle message about an invoice.
	 *
	 * @return void
	 */
	public function testReferenceTypeCodeIsTheEinvoiceUrn()
	{
		global $db;

		$handler = new CdarHandler($db);
		$xml = (string) $handler->generate($this->statusData(CdarHandler::SCHEME_SIREN_0225));

		$this->assertStringContainsString(
			'<ram:ReferenceTypeCode>urn.cpro.gouv.fr:1p0:CDV:einvoicingF2</ram:ReferenceTypeCode>',
			$xml,
			'MDT-97 must carry the URN of rule G7.14 for a lifecycle message about an invoice'
		);
	}

	/**
	 * MDT-97 sits where the CDAR XSD puts it: after TypeCode, before FormattedIssueDateTime.
	 *
	 * An element in the wrong place makes the whole document invalid against the XSD, so the order is
	 * asserted rather than only the presence.
	 *
	 * @return void
	 */
	public function testReferenceTypeCodeIsInItsXsdPlace()
	{
		global $db;

		$handler = new CdarHandler($db);
		$xml = (string) $handler->generate($this->statusData(CdarHandler::SCHEME_SIREN_0225));

		$posType = strpos($xml, '<ram:TypeCode>' . CdarHandler::DOC_INVOICE . '</ram:TypeCode>');
		$posRef = strpos($xml, '<ram:ReferenceTypeCode>');
		$posDate = strpos($xml, '<ram:FormattedIssueDateTime>');

		$this->assertNotFalse($posType, 'the referenced document must carry its TypeCode');
		$this->assertNotFalse($posRef, 'the referenced document must carry MDT-97');
		$this->assertNotFalse($posDate, 'the referenced document must carry its issue date');
		$this->assertLessThan($posRef, $posType, 'MDT-97 comes after the TypeCode of the referenced document');
		$this->assertLessThan($posDate, $posRef, 'MDT-97 comes before FormattedIssueDateTime');
	}

	/**
	 * MDT-73-1 is whatever scheme the caller settled on, not a constant.
	 *
	 * @return void
	 */
	public function testRecipientAddressKeepsTheSchemeItWasGiven()
	{
		global $db;

		$handler = new CdarHandler($db);

		$xml0225 = (string) $handler->generate($this->statusData(CdarHandler::SCHEME_SIREN_0225));
		$this->assertStringContainsString('<ram:URIID schemeID="0225">424761419</ram:URIID>', $xml0225);

		$xml0002 = (string) $handler->generate($this->statusData(CdarHandler::SCHEME_SIREN_0002));
		$this->assertStringContainsString('<ram:URIID schemeID="0002">424761419</ram:URIID>', $xml0002);
	}
}
