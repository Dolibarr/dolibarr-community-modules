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
 * \file    pdpconnectfr/class/utils/CdarHandler.class.php
 * \ingroup pdpconnectfr
 * \brief   CDAR (Cross Domain Acknowledgement and Response) Handler
 */

class CdarHandler
{
    /**
	 * @var DoliDB Database handler.
	 */
	public $db;

    // ==================== CONSTANTS ====================

    // DateTime Formats
    const FORMAT_DATETIME = '204'; // YYYYMMDDHHmmss
    const FORMAT_DATE = '102';     // YYYYMMDD

    // Acknowledgement Type Codes
    const ACK_ACKNOWLEDGEMENT = '305';
    const ACK_REJECTION = '304';
    const ACK_ACCEPTANCE = '302';

    // Document Type Codes
    const DOC_INVOICE = '380';
    const DOC_CREDIT_NOTE = '381';
    const DOC_CORRECTIVE_INVOICE = '384';
    const DOC_DEBIT_NOTE = '383';
    const DOC_PREPAYMENT_INVOICE = '386';

    // Process Condition Codes
    const PROC_DEPOSITED = '200';
    const PROC_ISSUED = '201';
    const PROC_RECEIVED = '202';
    const PROC_AVAILABLE = '203';
    const PROC_TAKEN_OVER = '204';
    const PROC_APPROVED = '205';
    const PROC_PARTIALLY_APPROVED = '206';
    const PROC_DISPUTED = '207';
    const PROC_SUSPENDED = '208';
    const PROC_COMPLETED = '209';
    const PROC_REFUSED = '210';
    const PROC_PAYMENT_TRANSMITTED = '211';
    const PROC_PAID = '212';
    const PROC_REJECTED = '213';

    // Role Codes
    const ROLE_WK = 'WK'; // Platform
    const ROLE_SE = 'SE'; // Seller
    const ROLE_BY = 'BY'; // Buyer
    const ROLE_CN = 'CN'; // Consignee
    const ROLE_DP = 'DP'; // Delivery point

    // Scheme IDs
    const SCHEME_SIREN_0225 = '0225';
    const SCHEME_SIREN_0002 = '0002';

    // Status Codes
    const STATUS_ACCEPTED = '1';
    const STATUS_REJECTED = '8';
    const STATUS_RECEIVED = '43';
    const STATUS_PAID = '47';
    const STATUS_ACKNOWLEDGED = '48';

    // XML Namespaces
    private $namespaces = [
        'rsm' => 'urn:un:unece:uncefact:data:standard:CrossDomainAcknowledgementAndResponse:100',
        'ram' => 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100',
        'udt' => 'urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100',
        'qdt' => 'urn:un:unece:uncefact:data:standard:QualifiedDataType:100'
    ];

    /**
	 * Constructor
	 *
	 * @param DoliDB $db handler
	 */
	public function __construct($db)
	{
        global $langs;
		$this->db = $db;
	}

    public function readFromFile($xmlFile)
    {
        if (!file_exists($xmlFile)) {
            throw new Exception("XML file does not exist: $xmlFile");
        }
        return $this->readFromString(file_get_contents($xmlFile));
    }

    public function readFromString($xmlString)
    {
        $xml = simplexml_load_string($xmlString);
        if ($xml === false) {
            throw new Exception("Error parsing XML string");
        }

        foreach ($this->namespaces as $prefix => $uri) {
            $xml->registerXPathNamespace($prefix, $uri);
        }

        return [
            'GuidelineID' => $this->getXpathValue($xml, '//ram:GuidelineSpecifiedDocumentContextParameter/ram:ID'),
            'ExchangedDocument' => $this->parseExchangedDocument($xml),
            'AcknowledgementDocument' => $this->parseAcknowledgementDocument($xml)
        ];
    }

    public function generate($data)
    {
        $xml = new DOMDocument('1.0', 'UTF-8');
        $xml->formatOutput = true;
        $xml->standalone = true;

        $root = $this->createRootElement($xml);
        $this->addContext($xml, $root, $data['GuidelineID']);
        $this->addExchangedDocument($xml, $root, $data['ExchangedDocument']);
        $this->addAcknowledgementDocument($xml, $root, $data['AcknowledgementDocument']);

        return $xml->saveXML();
    }

    public function saveToFile($data, $filename)
    {
        $xmlContent = $this->generate($data);
        if ($xmlContent === false) {
            return false;
        } else {
            file_put_contents($filename, $xmlContent);
        }
        return true;
    }

    /**
     * Generate a CDAR file
     *
     * @param   mixed $object       Invoice object (CustomerInvoice or SupplierInvoice)
     * @param   int $statusCode     Status code to send
     * @param string $reasonCode Reason code to send (optional)
     *
     * @return  array{res:int, message:string, file:string}   Returns array with 'res' (1 on success, -1 on failure) with a 'message' and 'file' with the path.
     */
    function generateCdarFile($object, $statusCode, $reasonCode = '')
    {
        global $conf, $db, $mysoc;

        /**
         * Peut-être dans les prochaines mise à jour des PDP des endpoints vont apparaître pour simplifier l'envoyer les messages de cycle de vie sans passer par les CDAR
         * Actuellement on doit générer les CDAR manuellement
         * Le CDAR peut/doit contenir plusieurs blocs, pour certains statuts il faut ajouter des blocs informatifs
         * On doit essayer de les créer avec le minimum de blocs obligatoires
         * Les blocs seront ajoutés suivant les retours PDP
         * Peut-être faut-il importer les fichiers XSD de l’UN/CEFACT pour valider les fichiers générés
         * On commence par traiter les cas suivants :
         * - Prise en charge (204) - optionnel => Implémenté
         * - Refus (210) - obligatoire dans le cas d’un refus ( Le seul statut obligatoire pour l’instant )
         * - Paiement transmis (212) - optionnel mais recommandé
         * - Acceptation (205) - optionnel
         * On peut en ajouter d’autres suivant le besoin
         */


        // Id format: {SupplierRef}_{StatusCode}_{CreationDate}#{DocType}_{CreationDate} as defined in documentation
        // TODO: map DOC_INVOICE with $object type
        $ID = $object->ref_supplier . '_' . $statusCode . '_' . date('YmdHis', $object->date_creation) . '#' . CdarHandler::DOC_INVOICE . '_' . date('Ymd', $object->date_creation);

        // We use same as ID for Name as its not required to be different
        $Name = $ID;

        // SIREN (0002) 
        $GlobalID = $mysoc->idprof1;

        // Issuer SIREN (0002)
        $IssuerGlobalID = $object->thirdparty->idprof1;

        // Invoice reference
        $IssuerAssignedID = $object->ref_supplier;

        /**
         * MDT-88
         * TODO: Map status codes from Dolibarr to CDAR status codes
         * 45 (In Process) = Prise en charge
         * 39 (on hold) = Suspendue
         * 37 (Complete) = Complétée
         * 50 (Rejected / Refused) = Refusée (by C4)
         * 49 (Conditionally accepted) = Approuvée Partiellement
         * 47 (Paid) = Paiement Transmis ET Encaissée
         * 46 (Under Query) = En litige
         * 1 (accepted) = Approuvée
         */
        $StatusCodeCdar = '45';

        // Label for ProcessCondition (Label of status code) we get it from class pdpconnectfr
        dol_include_once('/pdpconnectfr/class/providers/PDPProviderManager.class.php');
        $pdpConnectFr = new PdpConnectFr($db);
        $ProcessCondition = $pdpConnectFr->getStatusLabel($statusCode);
        $ProcessCondition = str_replace(' ', '_', $ProcessCondition);
        $ProcessCondition = preg_replace('/[^A-Za-z0-9_]/', '', $ProcessCondition); // Clean special chars


        $data = [
            'GuidelineID' => 'urn.cpro.gouv.fr:1p0:CDV:invoice',

            'ExchangedDocument' => [
                'ID' => $ID,
                'Name' => $Name,
                'IssueDateTime' => CdarHandler::getCurrentDateTime(),

                'SenderTradeParty' => [
                    'RoleCode' => CdarHandler::ROLE_WK
                ],

                'IssuerTradeParty' => [
                    'RoleCode' => CdarHandler::ROLE_BY
                ],

                'RecipientTradeParty' => [
                    'GlobalID'     => $GlobalID,
                    'SchemeID'     => CdarHandler::SCHEME_SIREN_0002,
                    'RoleCode'     => CdarHandler::ROLE_SE,
                    'URIID'        => $GlobalID,
                    'URISchemeID'  => CdarHandler::SCHEME_SIREN_0225
                ]
            ],

            'AcknowledgementDocument' => [
                'MultipleReferencesIndicator' => false,
                'TypeCode' => '23',
                'IssueDateTime' => CdarHandler::getCurrentDateTime(),

                'ReferenceReferencedDocument' => [
                    'IssuerAssignedID' => $IssuerAssignedID,
                    'StatusCode' => $StatusCodeCdar,
                    'TypeCode' => CdarHandler::DOC_INVOICE, // TODO: map DOC_INVOICE with $object type
                    'FormattedIssueDateTime' => date('Ymd', $object->date),
                    'ProcessConditionCode' => $statusCode,
                    'ProcessCondition' => $ProcessCondition,

                    'SpecifiedDocumentStatus' => !empty($reasonCode) ? [
                        'ReasonCode' => $reasonCode,
                        //'Reason' => 'Taux de TVA erroné',
                        //'SequenceNumeric' => 1
                    ] : [],

                    'IssuerTradeParty' => [
                        'GlobalID' => $IssuerGlobalID,
                        'SchemeID' => CdarHandler::SCHEME_SIREN_0002,
                        'RoleCode' => CdarHandler::ROLE_SE
                    ]
                ]
            ]
        ];

        $tempDir = $conf->pdpconnectfr->dir_temp;
        if (!dol_is_dir($tempDir)) {
            dol_mkdir($tempDir);
        }

        $filename = $tempDir . '/cdar_' . $ProcessCondition . '.xml';

        $result = $this->saveToFile($data, $filename);
        if ($result === false) {
            return array('res' => -1, 'message' => 'Error saving CDAR file');
        }
        //echo "CDAR file generated: " . $filename;

        return array('res' => 1, 'message' => 'CDAR file generated successfully', 'file' => $filename);
    }


    // ==================== UTILITY METHODS ====================

    public static function formatDateTime($dateTimeStr)
    {
        return strlen($dateTimeStr) === 14
            ? substr($dateTimeStr, 0, 4) . '-' . substr($dateTimeStr, 4, 2) . '-' . 
              substr($dateTimeStr, 6, 2) . ' ' . substr($dateTimeStr, 8, 2) . ':' . 
              substr($dateTimeStr, 10, 2) . ':' . substr($dateTimeStr, 12, 2)
            : $dateTimeStr;
    }

    public static function formatDate($dateStr)
    {
        return strlen($dateStr) === 8 
            ? substr($dateStr, 0, 4) . '-' . substr($dateStr, 4, 2) . '-' . substr($dateStr, 6, 2)
            : $dateStr;
    }

    public static function getCurrentDateTime()
    {
        return date('YmdHis');
    }

    public static function getCurrentDate()
    {
        return date('Ymd');
    }

    // ==================== PRIVATE HELPERS ====================

    private function getXpathValue($xml, $path, $default = '')
    {
        $result = $xml->xpath($path);
        return !empty($result) ? (string) $result[0] : $default;
    }

    private function getXpathAttribute($xml, $path, $attribute, $default = '')
    {
        $result = $xml->xpath($path);
        return !empty($result) ? (string) $result[0][$attribute] : $default;
    }

    private function createRootElement($xml)
    {
        $root = $xml->createElement('rsm:CrossDomainAcknowledgementAndResponse');
        $root->setAttribute('xmlns:rsm', $this->namespaces['rsm']);
        $root->setAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $root->setAttribute('xmlns:qdt', $this->namespaces['qdt']);
        $root->setAttribute('xmlns:ram', $this->namespaces['ram']);
        $root->setAttribute('xmlns:udt', $this->namespaces['udt']);
        $xml->appendChild($root);
        return $root;
    }

    private function addContext($xml, $root, $guidelineID)
    {
        $context = $xml->createElement('rsm:ExchangedDocumentContext');

        $process = $xml->createElement('ram:BusinessProcessSpecifiedDocumentContextParameter');
        $process->appendChild($xml->createElement('ram:ID', 'REGULATED'));
        $context->appendChild($process);

        $guideline = $xml->createElement('ram:GuidelineSpecifiedDocumentContextParameter');
        $guideline->appendChild($xml->createElement('ram:ID', $guidelineID));
        $context->appendChild($guideline);
        $root->appendChild($context);
    }

    private function addDateTimeElement($xml, $parent, $elementName, $value, $format)
    {
        $element = $xml->createElement($elementName);
        $dateTimeStr = $xml->createElement('udt:DateTimeString', $value);
        $dateTimeStr->setAttribute('format', $format);
        $element->appendChild($dateTimeStr);
        $parent->appendChild($element);
    }

    private function addTradeParty($xml, $parent, $elementName, $data)
    {
        $party = $xml->createElement($elementName);

        if (isset($data['GlobalID'])) {
            $globalID = $xml->createElement('ram:GlobalID', $data['GlobalID']);
            $globalID->setAttribute('schemeID', $data['SchemeID']);
            $party->appendChild($globalID);
        }

        $party->appendChild($xml->createElement('ram:RoleCode', $data['RoleCode']));

        if (isset($data['URIID'])) {
            $uriComm = $xml->createElement('ram:URIUniversalCommunication');
            $uriID = $xml->createElement('ram:URIID', $data['URIID']);
            $uriID->setAttribute('schemeID', $data['URISchemeID']);
            $uriComm->appendChild($uriID);
            $party->appendChild($uriComm);
        }

        $parent->appendChild($party);
    }

    // ==================== PARSING ====================

    private function parseExchangedDocument($xml)
    {
        return [
            'ID' => $this->getXpathValue($xml, '//rsm:ExchangedDocument/ram:ID'),
            'Name' => $this->getXpathValue($xml, '//rsm:ExchangedDocument/ram:Name'),
            'IssueDateTime' => $this->getXpathValue($xml, '//rsm:ExchangedDocument/ram:IssueDateTime/udt:DateTimeString'),
            'SenderTradeParty' => [
                'RoleCode' => $this->getXpathValue($xml, '//rsm:ExchangedDocument/ram:SenderTradeParty/ram:RoleCode')
            ],
            'IssuerTradeParty' => [
                'RoleCode' => $this->getXpathValue($xml, '//rsm:ExchangedDocument/ram:IssuerTradeParty/ram:RoleCode')
            ],
            'RecipientTradeParty' => [
                'GlobalID' => $this->getXpathValue($xml, '//rsm:ExchangedDocument/ram:RecipientTradeParty/ram:GlobalID'),
                'SchemeID' => $this->getXpathAttribute($xml, '//rsm:ExchangedDocument/ram:RecipientTradeParty/ram:GlobalID', 'schemeID'),
                'RoleCode' => $this->getXpathValue($xml, '//rsm:ExchangedDocument/ram:RecipientTradeParty/ram:RoleCode'),
                'URIID' => $this->getXpathValue($xml, '//rsm:ExchangedDocument/ram:RecipientTradeParty/ram:URIUniversalCommunication/ram:URIID'),
                'URISchemeID' => $this->getXpathAttribute($xml, '//rsm:ExchangedDocument/ram:RecipientTradeParty/ram:URIUniversalCommunication/ram:URIID', 'schemeID')
            ]
        ];
    }

    private function parseAcknowledgementDocument($xml)
    {
        $indicator = $this->getXpathValue($xml, '//rsm:AcknowledgementDocument/ram:MultipleReferencesIndicator/udt:Indicator');

        return [
            'MultipleReferencesIndicator' => $indicator === 'true',
            'TypeCode' => $this->getXpathValue($xml, '//rsm:AcknowledgementDocument/ram:TypeCode'),
            'IssueDateTime' => $this->getXpathValue($xml, '//rsm:AcknowledgementDocument/ram:IssueDateTime/udt:DateTimeString'),
            'ReferenceReferencedDocument' => $this->parseReferencedDocument($xml)
        ];
    }

    private function parseReferencedDocument($xml)
    {
        $result = [
            'IssuerAssignedID' => $this->getXpathValue($xml, '//ram:ReferenceReferencedDocument/ram:IssuerAssignedID'),
            'StatusCode' => $this->getXpathValue($xml, '//ram:ReferenceReferencedDocument/ram:StatusCode'),
            'TypeCode' => $this->getXpathValue($xml, '//ram:ReferenceReferencedDocument/ram:TypeCode'),
            'FormattedIssueDateTime' => $this->getXpathValue($xml, '//ram:ReferenceReferencedDocument/ram:FormattedIssueDateTime/qdt:DateTimeString'),
            'ProcessConditionCode' => $this->getXpathValue($xml, '//ram:ReferenceReferencedDocument/ram:ProcessConditionCode'),
            'ProcessCondition' => $this->getXpathValue($xml, '//ram:ReferenceReferencedDocument/ram:ProcessCondition'),
            'IssuerTradeParty' => [
                'GlobalID' => $this->getXpathValue($xml, '//ram:ReferenceReferencedDocument/ram:IssuerTradeParty/ram:GlobalID'),
                'SchemeID' => $this->getXpathAttribute($xml, '//ram:ReferenceReferencedDocument/ram:IssuerTradeParty/ram:GlobalID', 'schemeID'),
                'RoleCode' => $this->getXpathValue($xml, '//ram:ReferenceReferencedDocument/ram:IssuerTradeParty/ram:RoleCode')
            ]
        ];

        $statusNodes = $xml->xpath('//ram:ReferenceReferencedDocument/ram:SpecifiedDocumentStatus');
        if (!empty($statusNodes)) {
            $status = $statusNodes[0];
            $result['StatusReasonCode'] = $this->getXpathValue($status, 'ram:ReasonCode');
            $result['StatusReason'] = $this->getXpathValue($status, 'ram:Reason');

            $seqResult = $status->xpath('ram:SequenceNumeric');
            if (!empty($seqResult)) {
                $result['StatusSequenceNumeric'] = (int) $seqResult[0];
            }

            $result['StatusIncludedNoteContent'] = $this->getXpathValue($status, 'ram:IncludedNote/ram:Content');
        }

        return $result;
    }

    // ==================== GENERATION ====================

    private function addExchangedDocument($xml, $root, $doc)
    {
        $exchanged = $xml->createElement('rsm:ExchangedDocument');
        $exchanged->appendChild($xml->createElement('ram:ID', $doc['ID']));
        $exchanged->appendChild($xml->createElement('ram:Name', $doc['Name']));

        $this->addDateTimeElement($xml, $exchanged, 'ram:IssueDateTime', $doc['IssueDateTime'], self::FORMAT_DATETIME);

        $this->addTradeParty($xml, $exchanged, 'ram:SenderTradeParty', $doc['SenderTradeParty']);
        $this->addTradeParty($xml, $exchanged, 'ram:IssuerTradeParty', $doc['IssuerTradeParty']);
        $this->addTradeParty($xml, $exchanged, 'ram:RecipientTradeParty', $doc['RecipientTradeParty']);

        $root->appendChild($exchanged);
    }

    private function addAcknowledgementDocument($xml, $root, $doc)
    {
        $ack = $xml->createElement('rsm:AcknowledgementDocument');

        $multipleRef = $xml->createElement('ram:MultipleReferencesIndicator');
        $indicator = $xml->createElement('udt:Indicator', $doc['MultipleReferencesIndicator'] ? 'true' : 'false');
        $multipleRef->appendChild($indicator);
        $ack->appendChild($multipleRef);

        $ack->appendChild($xml->createElement('ram:TypeCode', $doc['TypeCode']));
        $this->addDateTimeElement($xml, $ack, 'ram:IssueDateTime', $doc['IssueDateTime'], self::FORMAT_DATETIME);
        $this->addReferencedDocument($xml, $ack, $doc['ReferenceReferencedDocument']);

        $root->appendChild($ack);
    }

    private function addReferencedDocument($xml, $parent, $doc)
    {
        $ref = $xml->createElement('ram:ReferenceReferencedDocument');
        $ref->appendChild($xml->createElement('ram:IssuerAssignedID', $doc['IssuerAssignedID']));
        $ref->appendChild($xml->createElement('ram:StatusCode', $doc['StatusCode']));
        $ref->appendChild($xml->createElement('ram:TypeCode', $doc['TypeCode']));

        $formattedDateTime = $xml->createElement('ram:FormattedIssueDateTime');
        $dateTimeStr = $xml->createElement('qdt:DateTimeString', $doc['FormattedIssueDateTime']);
        $dateTimeStr->setAttribute('format', self::FORMAT_DATE);
        $formattedDateTime->appendChild($dateTimeStr);
        $ref->appendChild($formattedDateTime);

        $ref->appendChild($xml->createElement('ram:ProcessConditionCode', $doc['ProcessConditionCode']));
        $ref->appendChild($xml->createElement('ram:ProcessCondition', $doc['ProcessCondition']));

        $this->addTradeParty($xml, $ref, 'ram:IssuerTradeParty', $doc['IssuerTradeParty']);
        $parent->appendChild($ref);

        if (!empty($doc['SpecifiedDocumentStatus'])) {
            $status = $xml->createElement('ram:SpecifiedDocumentStatus');

            if (!empty($doc['SpecifiedDocumentStatus']['ReasonCode'])) {
                $status->appendChild(
                    $xml->createElement('ram:ReasonCode', $doc['SpecifiedDocumentStatus']['ReasonCode'])
                );
            }

            if (!empty($doc['SpecifiedDocumentStatus']['Reason'])) {
                $status->appendChild(
                    $xml->createElement('ram:Reason', $doc['SpecifiedDocumentStatus']['Reason'])
                );
            }

            /*if (isset($doc['SpecifiedDocumentStatus']['SequenceNumeric'])) {
                $status->appendChild(
                    $xml->createElement(
                        'ram:SequenceNumeric',
                        (int) $doc['SpecifiedDocumentStatus']['SequenceNumeric']
                    )
                );
            }*/

            $ref->appendChild($status);
        }
    }
}