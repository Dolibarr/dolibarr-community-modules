<?php
/* Copyright (C) 2025       Laurent Destailleur         <eldy@users.sourceforge.net>
 * Copyright (C) 2025       Mohamed DAOUD               <mdaoud@dolicloud.com>
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
 * \file    pdpconnectfr/class/utils/ZugferdDocumentBuilderPatcher.class.php
 * \ingroup pdpconnectfr
 * \brief   Extend ZugferdDocumentBuilder to handle specific needs of CTC-FR guideline
 */


use horstoeko\zugferd\ZugferdDocumentBuilder;

require __DIR__ . "/../../vendor/autoload.php";

class ZugferdDocumentBuilderPatcher
{
    /**
     * URN for the standard Factur-X EXTENDED profile (horstoeko default output)
     */
    private const URN_EXTENDED = 'urn:cen.eu:en16931:2017#conformant#urn:factur-x.eu:1p0:extended';

    /**
     * URN for the EXTENDED-CTC-FR profile (French e-invoicing mandate)
     */
    private const URN_EXTENDED_CTC_FR = 'urn:cen.eu:en16931:2017#conformant#urn.cpro.gouv.fr:1p0:extended-ctc-fr';

    /**
     * TypeCode identifying a deposit recovery line
     */
    private const DEPOSIT_TYPE_CODE = '386';

    /**
     * Deposit line references to inject: array of ['lineId', 'invoiceRef', 'invoiceDate']
     *
     * @var array<int, array{lineId: string|int, invoiceRef: string, invoiceDate: \DateTimeInterface}>
     */
    public $depositRefs = [];


    /**
     * @param ZugferdDocumentBuilder $builder  The horstoeko builder after all lines have been added
     */
    public function __construct(ZugferdDocumentBuilder $builder)
    {
    }

    /**
     * Register a deposit line that needs the AdditionalReferencedDocument injection.
     *
     * @param string|int            $lineId         Line identifier as set with setDocumentPositionId()
     * @param string                $invoiceRef     Deposit invoice number
     * @param \DateTimeInterface    $invoiceDate    Deposit invoice date
     */
    public function addDepositLineReference(
        $lineId,
        string $invoiceRef,
        \DateTimeInterface $invoiceDate
    ): self {
        $this->depositRefs[] = [
            'lineId'      => (string) $lineId,
            'invoiceRef'  => $invoiceRef,
            'invoiceDate' => $invoiceDate,
        ];

        return $this;
    }

    /**
     * Build the patched XML string.
     *
     * @return string Full patched XML ready to be embedded into the PDF
     */
    public function getPatchedXml(): string
    {
        $xmlpath = $this->builder->getContent();

        return self::patchXmlString($xmlpath, $this->depositRefs);
    }


    /**
     * Patch a raw Factur-X EXTENDED XML string:
     *   - Replace GuidelineID with EXTENDED-CTC-FR URN
     *   - Inject AdditionalReferencedDocument on deposit lines
     *
     * @param string $xmlpath         Path to the raw XML produced by horstoeko/zugferd
     * @param array  $depositRefs Array of deposit refs:
     *                            [['lineId' => '1', 'invoiceRef' => 'AC2602-0015', 'invoiceDate' => DateTime], ...]
     *
     * @return string Patched XML string
     */
    public static function patchXmlString(string $xmlpath, array $depositRefs = []): string
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = true;
        $dom->formatOutput       = false;

        if (!$dom->load($xmlpath)) {
            throw new \RuntimeException('ZugferdDocumentBuilderPatcher: Failed to parse XML.');
        }

        $xpath = new \DOMXPath($dom);
        self::registerNamespaces($xpath);

        // 1. Patch the GuidelineSpecifiedDocumentContextParameter ID to switch from EXTENDED to EXTENDED-CTC-FR
        self::patchGuidelineId($xpath);

        // 2. Inject AdditionalReferencedDocument on each deposit line if it is a final invoice after a deposit
        foreach ($depositRefs as $ref) {
            self::injectDepositLineRef(
                $dom,
                $xpath,
                (string) $ref['lineId'],
                $ref['invoiceRef'],
                $ref['invoiceDate']
            );
        }

        // Other potential patches for CTC-FR can be added here in the future

        return $dom->saveXML();
    }


    /**
     * Register all Factur-X / UN/CEFACT namespaces on the XPath object.
     */
    private static function registerNamespaces(\DOMXPath $xpath): void
    {
        $xpath->registerNamespace('rsm', 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100');
        $xpath->registerNamespace('ram', 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100');
        $xpath->registerNamespace('qdt', 'urn:un:unece:uncefact:data:standard:QualifiedDataType:100');
        $xpath->registerNamespace('udt', 'urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100');
    }

    /**
     * Replace the GuidelineSpecifiedDocumentContextParameter ID value.
     */
    private static function patchGuidelineId(\DOMXPath $xpath): void
    {
        $nodes = $xpath->query(
            '//rsm:ExchangedDocumentContext'
            . '/ram:GuidelineSpecifiedDocumentContextParameter'
            . '/ram:ID'
        );

        if ($nodes === false || $nodes->length === 0) {
            throw new \RuntimeException(
                'ZugferdDocumentBuilderPatcher: GuidelineSpecifiedDocumentContextParameter/ID not found in XML.'
            );
        }

        /** @var \DOMElement $node */
        $node = $nodes->item(0);

        // Accept both EXTENDED and already-patched CTC-FR values
        if (!in_array($node->nodeValue, [self::URN_EXTENDED, self::URN_EXTENDED_CTC_FR], true)) {
            throw new \RuntimeException(sprintf(
                'ZugferdDocumentBuilderPatcher: Unexpected guideline URN "%s". Only EXTENDED profile and EXTENDED-CTC-FR profile are supported.',
                $node->nodeValue
            ));
        }

        $node->nodeValue = self::URN_EXTENDED_CTC_FR;
    }

    /**
     * Find the trade line with the given LineID and append an AdditionalReferencedDocument identifying it as a deposit recovery line.
      *
     */
    private static function injectDepositLineRef(
        \DOMDocument $dom,
        \DOMXPath $xpath,
        string $lineId,
        string $invoiceRef,
        \DateTimeInterface $invoiceDate
    ): void {
        // Find the SpecifiedLineTradeSettlement for this LineID
        $query = sprintf(
            '//ram:IncludedSupplyChainTradeLineItem'
            . '[ram:AssociatedDocumentLineDocument/ram:LineID[normalize-space(.)="%s"]]'
            . '/ram:SpecifiedLineTradeSettlement',
            addslashes($lineId)
        );

        $settlements = $xpath->query($query);

        if ($settlements === false || $settlements->length === 0) {
            throw new \RuntimeException(sprintf(
                'ZugferdDocumentBuilderPatcher: SpecifiedLineTradeSettlement not found for LineID "%s".',
                $lineId
            ));
        }

        /** @var \DOMElement $settlement */
        $settlement = $settlements->item(0);

        // Check if an AdditionalReferencedDocument with TypeCode=386 already exists
        // to avoid duplicates when called multiple times
        $existing = $xpath->query(
            'ram:AdditionalReferencedDocument[ram:TypeCode="' . self::DEPOSIT_TYPE_CODE . '"]',
            $settlement
        );

        if ($existing !== false && $existing->length > 0) {
            // Already patched — skip
            return;
        }

        $ramNs = 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100';

        $indent     = "\n          ";   // enfants de AdditionalReferencedDocument (10 espaces)
        $indentDeep = "\n            "; // enfants de FormattedIssueDateTime (12 espaces)
        $indentBack = "\n        ";     // fermeture AdditionalReferencedDocument (8 espaces)

        // <ram:AdditionalReferencedDocument>
        $refDoc = $dom->createElementNS($ramNs, 'ram:AdditionalReferencedDocument');

        // <ram:IssuerAssignedID>
        $idEl = $dom->createElementNS($ramNs, 'ram:IssuerAssignedID');
        $idEl->appendChild($dom->createTextNode($invoiceRef));
        $refDoc->appendChild($dom->createTextNode($indent));
        $refDoc->appendChild($idEl);

        // <ram:TypeCode>386</ram:TypeCode>
        $typeEl = $dom->createElementNS($ramNs, 'ram:TypeCode');
        $typeEl->appendChild($dom->createTextNode(self::DEPOSIT_TYPE_CODE));
        $refDoc->appendChild($dom->createTextNode($indent));
        $refDoc->appendChild($typeEl);

        // <ram:FormattedIssueDateTime>
        $formattedDt = $dom->createElementNS($ramNs, 'ram:FormattedIssueDateTime');
        $dtString    = $dom->createElement('qdt:DateTimeString');
        $dtString->setAttribute('format', '102');
        $dtString->appendChild($dom->createTextNode($invoiceDate->format('Ymd')));
        $formattedDt->appendChild($dom->createTextNode($indentDeep));
        $formattedDt->appendChild($dtString);
        $formattedDt->appendChild($dom->createTextNode($indent));
        $refDoc->appendChild($dom->createTextNode($indent));
        $refDoc->appendChild($formattedDt);
        $refDoc->appendChild($dom->createTextNode($indentBack));

        // Append into SpecifiedLineTradeSettlement
        $settlement->appendChild($dom->createTextNode("    "));
        $settlement->appendChild($refDoc);
        $settlement->appendChild($dom->createTextNode("\n      "));
    }
}