<?php
/* Copyright (C) 2026		Pierre Grasswill			<da.grumpf@gmail.com>
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
 *      \file       test/phpunit/EmbeddedXmlReaderTest.php
 *      \ingroup    test
 *      \brief      PHPUnit test for EmbeddedXmlReader, which reads the line level
 *                  AdditionalReferencedDocument entries of a received Factur-X. Everything it is
 *                  given comes from a document a third party sent, so the malformed cases matter
 *                  as much as the well formed one.
 *      \remarks    To run this script as CLI: phpunit filename.php
 *                  Needs no database, which is how the CI runs it.
 */

// The class under test uses the DOM extension and nothing else, so the instance is used when it is
// reachable and simply not required when it is not.
$dolibarrHtdocs = getenv('DOLIBARR_HTDOCS');
if (!$dolibarrHtdocs) {
	$dolibarrHtdocs = dirname(__FILE__) . '/../../htdocs';
}
if (file_exists($dolibarrHtdocs . '/master.inc.php')) {
	global $conf, $user, $langs, $db;
	require_once $dolibarrHtdocs . '/master.inc.php';
}

require_once dirname(__FILE__) . '/../../class/utils/EmbeddedXmlReader.class.php';

/**
 * Class for PHPUnit tests
 *
 * @backupGlobals disabled
 * @backupStaticAttributes enabled
 * @remarks	backupGlobals must be disabled to have db,conf,user and lang not erased.
 */
class EmbeddedXmlReaderTest extends PHPUnit\Framework\TestCase
{
	/**
	 * A CII document with two lines: line 1 carries two referenced documents, line 2 carries none.
	 *
	 * @param  string $dateOfFirst	Value written in the DateTimeString of the first entry
	 * @return string				CII XML
	 */
	private function documentWithTwoLines($dateOfFirst = '20260115')
	{
		return '<?xml version="1.0" encoding="UTF-8"?>
<rsm:CrossIndustryInvoice
	xmlns:rsm="urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100"
	xmlns:ram="urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100"
	xmlns:qdt="urn:un:unece:uncefact:data:standard:QualifiedDataType:100">
	<rsm:SupplyChainTradeTransaction>
		<ram:IncludedSupplyChainTradeLineItem>
			<ram:AssociatedDocumentLineDocument><ram:LineID>1</ram:LineID></ram:AssociatedDocumentLineDocument>
			<ram:SpecifiedLineTradeSettlement>
				<ram:AdditionalReferencedDocument>
					<ram:IssuerAssignedID>ORDER-42</ram:IssuerAssignedID>
					<ram:TypeCode>130</ram:TypeCode>
					<ram:FormattedIssueDateTime>
						<qdt:DateTimeString format="102">' . $dateOfFirst . '</qdt:DateTimeString>
					</ram:FormattedIssueDateTime>
				</ram:AdditionalReferencedDocument>
				<ram:AdditionalReferencedDocument>
					<ram:IssuerAssignedID>DESPATCH-7</ram:IssuerAssignedID>
					<ram:TypeCode>916</ram:TypeCode>
				</ram:AdditionalReferencedDocument>
			</ram:SpecifiedLineTradeSettlement>
		</ram:IncludedSupplyChainTradeLineItem>
		<ram:IncludedSupplyChainTradeLineItem>
			<ram:AssociatedDocumentLineDocument><ram:LineID>2</ram:LineID></ram:AssociatedDocumentLineDocument>
			<ram:SpecifiedLineTradeSettlement/>
		</ram:IncludedSupplyChainTradeLineItem>
	</rsm:SupplyChainTradeTransaction>
</rsm:CrossIndustryInvoice>';
	}

	/**
	 * Every entry of the asked line is returned, with its three fields.
	 *
	 * @return void
	 */
	public function testReadsEveryReferencedDocumentOfTheLine()
	{
		$reader = new EmbeddedXmlReader($this->documentWithTwoLines());
		$docs = $reader->getLineAdditionalReferencedDocuments(1);

		$this->assertCount(2, $docs);
		$this->assertSame('ORDER-42', $docs[0]['IssuerAssignedID']);
		$this->assertSame('130', $docs[0]['typeCode']);
		$this->assertSame('2026-01-15', $docs[0]['issueDate']);
		$this->assertSame('DESPATCH-7', $docs[1]['IssuerAssignedID']);
		$this->assertSame('916', $docs[1]['typeCode']);
		$this->assertNull($docs[1]['issueDate'], 'an entry without a date reports none');
	}

	/**
	 * The entries of one line never leak into another.
	 *
	 * @return void
	 */
	public function testALineWithoutReferencedDocumentReturnsNothing()
	{
		$reader = new EmbeddedXmlReader($this->documentWithTwoLines());

		$this->assertSame([], $reader->getLineAdditionalReferencedDocuments(2));
		$this->assertSame([], $reader->getLineAdditionalReferencedDocuments(99));
	}

	/**
	 * The line id is compared as the document writes it, and the reader is given it as a string by
	 * FacturXProtocol.
	 *
	 * @return void
	 */
	public function testTheLineIdIsMatchedAsAString()
	{
		$reader = new EmbeddedXmlReader($this->documentWithTwoLines());

		$this->assertCount(2, $reader->getLineAdditionalReferencedDocuments('1'));
	}

	/**
	 * A date the sender did not write in the 102 format used to be fatal: createFromFormat() returns
	 * false and format() was called on it. The entry has to come back with no date instead.
	 *
	 * @return void
	 */
	public function testADateOutsideTheExpectedFormatIsReportedAbsentAndNotFatal()
	{
		foreach (array('2026-01-15', '202601', 'not a date') as $written) {
			$reader = new EmbeddedXmlReader($this->documentWithTwoLines($written));
			$docs = $reader->getLineAdditionalReferencedDocuments(1);

			$this->assertCount(2, $docs, 'the entries are still read when a date is unusable');
			$this->assertSame('ORDER-42', $docs[0]['IssuerAssignedID']);
			$this->assertNull($docs[0]['issueDate'], 'no date is better than a fatal on ' . $written);
		}
	}

	/**
	 * Nothing was embedded in the received PDF, or what was embedded is not XML.
	 *
	 * @return void
	 */
	public function testAnUnusableDocumentReturnsNothing()
	{
		foreach (array(null, '', 'this is not xml', '<rsm:CrossIndustryInvoice>') as $content) {
			$reader = new EmbeddedXmlReader($content);

			$this->assertSame([], @$reader->getLineAdditionalReferencedDocuments(1));
		}
	}
}
