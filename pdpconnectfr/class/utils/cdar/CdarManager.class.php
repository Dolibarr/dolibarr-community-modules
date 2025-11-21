<?php
/*require_once __DIR__ . '/dto/CdarDocument.php';
require_once __DIR__ . '/dto/ExchangedDocument.php';
require_once __DIR__ . '/dto/AcknowledgementDocument.php';
require_once __DIR__ . '/dto/ReferencedDocument.php';
require_once __DIR__ . '/dto/TradeParty.php';
require_once __DIR__ . '/enum/DateTimeFormat.php';
require_once __DIR__ . '/enum/RoleCode.php';
require_once __DIR__ . '/enum/SchemeID.php';
require_once __DIR__ . '/enum/AcknowledgementTypeCode.php';
require_once __DIR__ . '/enum/StatusCode.php';
require_once __DIR__ . '/enum/DocumentTypeCode.php';
require_once __DIR__ . '/enum/ProcessConditionCode.php';*/

dol_include_once('/pdpconnectfr/class/utils/cdar/dto/CdarDocument.php');
dol_include_once('/pdpconnectfr/class/utils/cdar/dto/ExchangedDocument.php');
dol_include_once('/pdpconnectfr/class/utils/cdar/dto/AcknowledgementDocument.php');
dol_include_once('/pdpconnectfr/class/utils/cdar/dto/ReferencedDocument.php');
dol_include_once('/pdpconnectfr/class/utils/cdar/dto/TradeParty.php');
dol_include_once('/pdpconnectfr/class/utils/cdar/enum/DateTimeFormat.php');
dol_include_once('/pdpconnectfr/class/utils/cdar/enum/RoleCode.php');
dol_include_once('/pdpconnectfr/class/utils/cdar/enum/SchemeID.php');
dol_include_once('/pdpconnectfr/class/utils/cdar/enum/AcknowledgementTypeCode.php');
dol_include_once('/pdpconnectfr/class/utils/cdar/enum/StatusCode.php');
dol_include_once('/pdpconnectfr/class/utils/cdar/enum/DocumentTypeCode.php');
dol_include_once('/pdpconnectfr/class/utils/cdar/enum/ProcessConditionCode.php');


class CdarManager
{
    private const NAMESPACES = [
        'rsm' => 'urn:un:unece:uncefact:data:standard:CrossDomainAcknowledgementAndResponse:100',
        'ram' => 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100',
        'udt' => 'urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100',
        'qdt' => 'urn:un:unece:uncefact:data:standard:QualifiedDataType:100'
    ];

    // ==================== READ METHODS ====================

    public function readFromFile(string $xmlFile): CdarDocument
    {
        if (!file_exists($xmlFile)) {
            throw new Exception("XML file does not exist: $xmlFile");
        }

        $xml = simplexml_load_file($xmlFile);
        if ($xml === false) {
            throw new Exception("Error loading XML file");
        }

        return $this->parseXml($xml);
    }

    public function readFromString(string $xmlString): CdarDocument
    {
        $xml = simplexml_load_string($xmlString);
        if ($xml === false) {
            throw new Exception("Error parsing XML string");
        }

        return $this->parseXml($xml);
    }

    private function parseXml(\SimpleXMLElement $xml): CdarDocument
    {
        foreach (self::NAMESPACES as $prefix => $uri) {
            $xml->registerXPathNamespace($prefix, $uri);
        }

        // GuidelineID
        $guidelineID = (string) $xml->xpath('//ram:GuidelineSpecifiedDocumentContextParameter/ram:ID')[0];

        // ExchangedDocument
        $exchangedDoc = $this->parseExchangedDocument($xml);

        // AcknowledgementDocument
        $ackDoc = $this->parseAcknowledgementDocument($xml);

        return new CdarDocument($guidelineID, $exchangedDoc, $ackDoc);
    }

    private function parseExchangedDocument(\SimpleXMLElement $xml): ExchangedDocument
    {
        $id = (string) $xml->xpath('//rsm:ExchangedDocument/ram:ID')[0];
        $name = (string) $xml->xpath('//rsm:ExchangedDocument/ram:Name')[0];
        $issueDateTime = (string) $xml->xpath('//rsm:ExchangedDocument/ram:IssueDateTime/udt:DateTimeString')[0];

        // SenderTradeParty
        $senderRole = (string) $xml->xpath('//rsm:ExchangedDocument/ram:SenderTradeParty/ram:RoleCode')[0];
        $sender = new TradeParty(RoleCode: RoleCode::from($senderRole));

        // IssuerTradeParty
        $issuerRole = (string) $xml->xpath('//rsm:ExchangedDocument/ram:IssuerTradeParty/ram:RoleCode')[0];
        $issuer = new TradeParty(RoleCode: RoleCode::from($issuerRole));

        // RecipientTradeParty
        $recipientGlobalID = (string) $xml->xpath('//rsm:ExchangedDocument/ram:RecipientTradeParty/ram:GlobalID')[0];
        $recipientSchemeID = (string) $xml->xpath('//rsm:ExchangedDocument/ram:RecipientTradeParty/ram:GlobalID/@schemeID')[0];
        $recipientRole = (string) $xml->xpath('//rsm:ExchangedDocument/ram:RecipientTradeParty/ram:RoleCode')[0];
        $recipientURI = (string) $xml->xpath('//rsm:ExchangedDocument/ram:RecipientTradeParty/ram:URIUniversalCommunication/ram:URIID')[0];
        $recipientURIScheme = (string) $xml->xpath('//rsm:ExchangedDocument/ram:RecipientTradeParty/ram:URIUniversalCommunication/ram:URIID/@schemeID')[0];

        $recipient = new TradeParty(
            GlobalID: $recipientGlobalID,
            SchemeID: SchemeID::from($recipientSchemeID),
            RoleCode: RoleCode::from($recipientRole),
            URIID: $recipientURI,
            URISchemeID: SchemeID::from($recipientURIScheme)
        );

        return new ExchangedDocument($id, $name, $issueDateTime, $sender, $issuer, $recipient);
    }

    private function parseAcknowledgementDocument(\SimpleXMLElement $xml): AcknowledgementDocument
    {
        $multipleRef = (string) $xml->xpath('//rsm:AcknowledgementDocument/ram:MultipleReferencesIndicator/udt:Indicator')[0];
        $typeCode = (string) $xml->xpath('//rsm:AcknowledgementDocument/ram:TypeCode')[0];
        $issueDateTime = (string) $xml->xpath('//rsm:AcknowledgementDocument/ram:IssueDateTime/udt:DateTimeString')[0];

        // ReferenceReferencedDocument
        $refDoc = $this->parseReferencedDocument($xml);

        return new AcknowledgementDocument(
            MultipleReferencesIndicator: $multipleRef === 'true',
            TypeCode: AcknowledgementTypeCode::from($typeCode),
            IssueDateTime: $issueDateTime,
            ReferenceReferencedDocument: $refDoc
        );
    }

    private function parseReferencedDocument(\SimpleXMLElement $xml): ReferencedDocument
    {
        $issuerAssignedID = (string) $xml->xpath('//ram:ReferenceReferencedDocument/ram:IssuerAssignedID')[0];
        $statusCode = (string) $xml->xpath('//ram:ReferenceReferencedDocument/ram:StatusCode')[0];
        $typeCode = (string) $xml->xpath('//ram:ReferenceReferencedDocument/ram:TypeCode')[0];
        $formattedIssueDateTime = (string) $xml->xpath('//ram:ReferenceReferencedDocument/ram:FormattedIssueDateTime/qdt:DateTimeString')[0];
        $processConditionCode = (string) $xml->xpath('//ram:ReferenceReferencedDocument/ram:ProcessConditionCode')[0];
        $processCondition = (string) $xml->xpath('//ram:ReferenceReferencedDocument/ram:ProcessCondition')[0];

        // IssuerTradeParty
        $issuerGlobalID = (string) $xml->xpath('//ram:ReferenceReferencedDocument/ram:IssuerTradeParty/ram:GlobalID')[0];
        $issuerSchemeID = (string) $xml->xpath('//ram:ReferenceReferencedDocument/ram:IssuerTradeParty/ram:GlobalID/@schemeID')[0];
        $issuerRole = (string) $xml->xpath('//ram:ReferenceReferencedDocument/ram:IssuerTradeParty/ram:RoleCode')[0];
        $statusNodes = $xml->xpath('//ram:ReferenceReferencedDocument/ram:SpecifiedDocumentStatus');

        $statusReasonCode = null;
        $statusReason = null;
        $statusSequenceNumeric = null;
        $statusIncludedNoteContent = null;
        if (!empty($statusNodes)) {
            $reasonCodeNode = $statusNodes[0]->xpath('ram:ReasonCode');
            $reasonNode = $statusNodes[0]->xpath('ram:Reason');
            $sequenceNumericNode = $statusNodes[0]->xpath('ram:SequenceNumeric');
            $includedNoteContentNode = $statusNodes[0]->xpath('ram:IncludedNote/ram:Content');

            $statusReasonCode = !empty($reasonCodeNode) ? (string)$reasonCodeNode[0] : null;
            $statusReason = !empty($reasonNode) ? (string)$reasonNode[0] : null;
            $statusSequenceNumeric = !empty($sequenceNumericNode) ? (int)$sequenceNumericNode[0] : null;
            $statusIncludedNoteContent = !empty($includedNoteContentNode) ? (string)$includedNoteContentNode[0] : null;
        }

        $issuer = new TradeParty(
            GlobalID: $issuerGlobalID,
            SchemeID: SchemeID::from($issuerSchemeID),
            RoleCode: RoleCode::from($issuerRole)
        );

        return new ReferencedDocument(
            IssuerAssignedID: $issuerAssignedID,
            StatusCode: StatusCode::from($statusCode),
            TypeCode: DocumentTypeCode::from($typeCode),
            FormattedIssueDateTime: $formattedIssueDateTime,
            ProcessConditionCode: ProcessConditionCode::from($processConditionCode),
            ProcessCondition: $processCondition,
            IssuerTradeParty: $issuer,
            StatusReasonCode: $statusReasonCode,
            StatusReason: $statusReason,
            StatusSequenceNumeric: $statusSequenceNumeric,
            StatusIncludedNoteContent: $statusIncludedNoteContent
        );
    }

    // ==================== GENERATE METHODS ====================

    public function generate(CdarDocument $cdar): string
    {
        $xml = new \DOMDocument('1.0', 'UTF-8');
        $xml->formatOutput = true;
        $xml->standalone = true;

        // Root element
        $root = $xml->createElement('rsm:CrossDomainAcknowledgementAndResponse');
        $root->setAttribute('xmlns:rsm', self::NAMESPACES['rsm']);
        $root->setAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $root->setAttribute('xmlns:qdt', self::NAMESPACES['qdt']);
        $root->setAttribute('xmlns:ram', self::NAMESPACES['ram']);
        $root->setAttribute('xmlns:udt', self::NAMESPACES['udt']);
        $xml->appendChild($root);

        // ExchangedDocumentContext
        $context = $xml->createElement('rsm:ExchangedDocumentContext');
        $guideline = $xml->createElement('ram:GuidelineSpecifiedDocumentContextParameter');
        $guidelineID = $xml->createElement('ram:ID', $cdar->GuidelineID);
        $guideline->appendChild($guidelineID);
        $context->appendChild($guideline);
        $root->appendChild($context);

        // ExchangedDocument
        $this->addExchangedDocument($xml, $root, $cdar->ExchangedDocument);

        // AcknowledgementDocument
        $this->addAcknowledgementDocument($xml, $root, $cdar->AcknowledgementDocument);

        return $xml->saveXML();
    }

    private function addExchangedDocument(\DOMDocument $xml, \DOMElement $root, ExchangedDocument $doc): void
    {
        $exchanged = $xml->createElement('rsm:ExchangedDocument');

        $exchanged->appendChild($xml->createElement('ram:ID', $doc->ID));
        $exchanged->appendChild($xml->createElement('ram:Name', $doc->Name));

        $issueDateTime = $xml->createElement('ram:IssueDateTime');
        $dateTimeStr = $xml->createElement('udt:DateTimeString', $doc->IssueDateTime);
        $dateTimeStr->setAttribute('format', DateTimeFormat::YYYYMMDDHHMMSS->value);
        $issueDateTime->appendChild($dateTimeStr);
        $exchanged->appendChild($issueDateTime);

        // SenderTradeParty
        $sender = $xml->createElement('ram:SenderTradeParty');
        $sender->appendChild($xml->createElement('ram:RoleCode', $doc->SenderTradeParty->RoleCode->value));
        $exchanged->appendChild($sender);

        // IssuerTradeParty
        $issuer = $xml->createElement('ram:IssuerTradeParty');
        $issuer->appendChild($xml->createElement('ram:RoleCode', $doc->IssuerTradeParty->RoleCode->value));
        $exchanged->appendChild($issuer);

        // RecipientTradeParty
        $recipient = $xml->createElement('ram:RecipientTradeParty');

        $globalID = $xml->createElement('ram:GlobalID', $doc->RecipientTradeParty->GlobalID);
        $globalID->setAttribute('schemeID', $doc->RecipientTradeParty->SchemeID->value);
        $recipient->appendChild($globalID);

        $recipient->appendChild($xml->createElement('ram:RoleCode', $doc->RecipientTradeParty->RoleCode->value));

        $uriComm = $xml->createElement('ram:URIUniversalCommunication');
        $uriID = $xml->createElement('ram:URIID', $doc->RecipientTradeParty->URIID);
        $uriID->setAttribute('schemeID', $doc->RecipientTradeParty->URISchemeID->value);
        $uriComm->appendChild($uriID);
        $recipient->appendChild($uriComm);

        $exchanged->appendChild($recipient);

        $root->appendChild($exchanged);
    }

    private function addAcknowledgementDocument(\DOMDocument $xml, \DOMElement $root, AcknowledgementDocument $doc): void
    {
        $ack = $xml->createElement('rsm:AcknowledgementDocument');

        $multipleRef = $xml->createElement('ram:MultipleReferencesIndicator');
        $indicator = $xml->createElement('udt:Indicator', $doc->MultipleReferencesIndicator ? 'true' : 'false');
        $multipleRef->appendChild($indicator);
        $ack->appendChild($multipleRef);

        $ack->appendChild($xml->createElement('ram:TypeCode', $doc->TypeCode->value));

        $issueDateTime = $xml->createElement('ram:IssueDateTime');
        $dateTimeStr = $xml->createElement('udt:DateTimeString', $doc->IssueDateTime);
        $dateTimeStr->setAttribute('format', DateTimeFormat::YYYYMMDDHHMMSS->value);
        $issueDateTime->appendChild($dateTimeStr);
        $ack->appendChild($issueDateTime);

        // ReferenceReferencedDocument
        $this->addReferencedDocument($xml, $ack, $doc->ReferenceReferencedDocument);

        $root->appendChild($ack);
    }

    private function addReferencedDocument(\DOMDocument $xml, \DOMElement $parent, ReferencedDocument $doc): void
    {
        $ref = $xml->createElement('ram:ReferenceReferencedDocument');

        $ref->appendChild($xml->createElement('ram:IssuerAssignedID', $doc->IssuerAssignedID));
        $ref->appendChild($xml->createElement('ram:StatusCode', $doc->StatusCode->value));
        $ref->appendChild($xml->createElement('ram:TypeCode', $doc->TypeCode->value));

        $formattedDateTime = $xml->createElement('ram:FormattedIssueDateTime');
        $dateTimeStr = $xml->createElement('qdt:DateTimeString', $doc->FormattedIssueDateTime);
        $dateTimeStr->setAttribute('format', DateTimeFormat::YYYYMMDD->value);
        $formattedDateTime->appendChild($dateTimeStr);
        $ref->appendChild($formattedDateTime);

        $ref->appendChild($xml->createElement('ram:ProcessConditionCode', $doc->ProcessConditionCode->value));
        $ref->appendChild($xml->createElement('ram:ProcessCondition', $doc->ProcessCondition));

        // IssuerTradeParty
        $issuer = $xml->createElement('ram:IssuerTradeParty');
        $globalID = $xml->createElement('ram:GlobalID', $doc->IssuerTradeParty->GlobalID);
        $globalID->setAttribute('schemeID', $doc->IssuerTradeParty->SchemeID->value);
        $issuer->appendChild($globalID);
        $issuer->appendChild($xml->createElement('ram:RoleCode', $doc->IssuerTradeParty->RoleCode->value));
        $ref->appendChild($issuer);

        $parent->appendChild($ref);
    }

    public function saveToFile(CdarDocument $cdar, string $filename): void
    {
        $xml = $this->generate($cdar);
        file_put_contents($filename, $xml);
    }

    // ==================== UTILITY METHODS ====================

    public static function formatDateTime(string $dateTimeStr): string
    {
        if (strlen($dateTimeStr) === 14) {
            return substr($dateTimeStr, 0, 4) . '-' . 
                   substr($dateTimeStr, 4, 2) . '-' . 
                   substr($dateTimeStr, 6, 2) . ' ' . 
                   substr($dateTimeStr, 8, 2) . ':' . 
                   substr($dateTimeStr, 10, 2) . ':' . 
                   substr($dateTimeStr, 12, 2);
        }
        return $dateTimeStr;
    }

    public static function formatDate(string $dateStr): string
    {
        if (strlen($dateStr) === 8) {
            return substr($dateStr, 0, 4) . '-' . 
                   substr($dateStr, 4, 2) . '-' . 
                   substr($dateStr, 6, 2);
        }
        return $dateStr;
    }

    public static function getCurrentDateTime(): string
    {
        return date('YmdHis');
    }

    public static function getCurrentDate(): string
    {
        return date('Ymd');
    }
}