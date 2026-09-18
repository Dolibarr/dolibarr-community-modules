<?php
/* Copyright (C) 2025       Laurent Destailleur         <eldy@users.sourceforge.net>
 * Copyright (C) 2025       Mohamed DAOUD               <mdaoud@dolicloud.com>
 * Copyright (C) 2026		MDW							<mdeweerd@users.noreply.github.com>
 * Copyright (C) 2026      MB Informatique      <info@mb-informatique.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    einvoicing/class/utils/EmbeddedXmlReader.class.php
 * \ingroup einvoicing
 * \brief   Read the CTC-FR specific nodes of an embedded Factur-X XML.
 */


/**
 * EmbeddedXmlReader
 *
 * This class must stay free of any composer import: CIIProtocol includes it, and the CII protocol
 * works on an instance where the php prerequisites of the module were never installed. Everything
 * here is done with the native DOM extension only.
 */
class EmbeddedXmlReader
{
	/**
	 * Embedded XML content
	 *
	 * @var string|null
	 */
	private $embeddedXml;

	/**
	 * @param string|null  	$embeddedXml	The embedded XML content use to read invoice data
	 */
	public function __construct($embeddedXml = null)
	{
		$this->embeddedXml = $embeddedXml;
	}

	/**
	 * Parse an XML string and return all AdditionalReferencedDocument entries
	 * for a given line ID, with their IssuerAssignedID, TypeCode, ReferenceTypeCode and IssueDate
	 * (if available).
	 *
	 * @param string|int $lineid        Line ID to look up
	 *
	 * @return array<array{IssuerAssignedID:?string,typeCode:?string,referenceTypeCode:?string,issueDate:?string}>
	 */
	public function getLineAdditionalReferencedDocuments($lineid): array
	{
		$additionalRefDocs = [];

		// No XML was embedded in the PDF: loadXML() takes no null since PHP 8.1, and there is
		// nothing to query anyway.
		if (empty($this->embeddedXml)) {
			return $additionalRefDocs;
		}

		$dom = new \DOMDocument();

		$dom->loadXML($this->embeddedXml);

		$xpath = new \DOMXPath($dom);
		$xpath->registerNamespace('ram', 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100');
		$xpath->registerNamespace('qdt', 'urn:un:unece:uncefact:data:standard:QualifiedDataType:100');

		$query = sprintf(
			'//ram:IncludedSupplyChainTradeLineItem[ram:AssociatedDocumentLineDocument/ram:LineID[normalize-space(.)="%s"]]/ram:SpecifiedLineTradeSettlement/ram:AdditionalReferencedDocument',
			addslashes((string) $lineid)
		);

		$refDocs = $xpath->query($query);

		if ($refDocs !== false) {
			foreach ($refDocs as $refDoc) {
				$id                = $xpath->evaluate('string(ram:IssuerAssignedID)', $refDoc);
				$typeCode          = $xpath->evaluate('string(ram:TypeCode)', $refDoc);
				$referenceTypeCode = $xpath->evaluate('string(ram:ReferenceTypeCode)', $refDoc);
				$dateStr           = $xpath->evaluate('string(ram:FormattedIssueDateTime/qdt:DateTimeString)', $refDoc);

				// A date the sender did not write in the 102 format makes createFromFormat() return
				// false, and calling format() on it was fatal on a document the module only receives.
				$issueDate = $dateStr ? \DateTime::createFromFormat('Ymd', $dateStr) : false;

				$additionalRefDocs[] = [
					'IssuerAssignedID'  => $id ?: null,
					'typeCode'          => $typeCode ?: null,
					'referenceTypeCode' => $referenceTypeCode ?: null,
					'issueDate'         => $issueDate ? $issueDate->format('Y-m-d') : null,
				];
			}
		}

		return $additionalRefDocs;
	}
}
