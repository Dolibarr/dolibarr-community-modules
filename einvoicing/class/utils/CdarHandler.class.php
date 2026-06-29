<?php
/* Copyright (C) 2025       Laurent Destailleur         <eldy@users.sourceforge.net>
 * Copyright (C) 2025       Mohamed DAOUD               <mdaoud@dolicloud.com>
 * Copyright (C) 2026       Frédéric France             <frederic.france@free.fr>
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
 * \file    einvoicing/class/utils/CdarHandler.class.php
 * \ingroup einvoicing
 * \brief   CDAR (Cross Domain Acknowledgement and Response) Handler
 */

dol_include_once('einvoicing/lib/einvoicing.lib.php');

/**
 * CdarHandler
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
		$this->db = $db;
	}

	/**
	 * readFromFile
	 *
	 * @param  string $xmlFile xml file
	 * @return array{GuidelineID:string,ExchangedDocument:array,AcknowledgementDocument:array}
	 */
	public function readFromFile($xmlFile)
	{
		if (!file_exists($xmlFile)) {
			throw new Exception("XML file does not exist: $xmlFile");
		}
		return $this->readFromString(file_get_contents($xmlFile));
	}

	/**
	 * readFromString
	 *
	 * @param  string $xmlString xml string
	 * @return array{GuidelineID:string,ExchangedDocument:array,AcknowledgementDocument:array}
	 */
	public function readFromString($xmlString)
	{
		$xml = simplexml_load_string($xmlString);
		if ($xml === false) {
			throw new Exception("Error parsing XML string");
		}

		foreach ($this->namespaces as $prefix => $uri) {
			$xml->registerXPathNamespace($prefix, $uri);
		}

		$GuidelineID = $this->getXpathValue($xml, '//ram:GuidelineSpecifiedDocumentContextParameter/ram:ID');
		$ExchangedDocument = $this->parseExchangedDocument($xml);
		$AcknowledgementDocument = $this->parseAcknowledgementDocument($xml);

		return [
			'GuidelineID' => $GuidelineID,
			'ExchangedDocument' => $ExchangedDocument,
			'AcknowledgementDocument' => $AcknowledgementDocument
		];
	}

	/**
	 * generate
	 *
	 * @param  array $data array of data
	 *
	 * @return string|false
	 */
	public function generate($data)
	{
		$dom = new DOMDocument('1.0', 'UTF-8');
		$dom->formatOutput = true;
		$dom->standalone = true;
		$dom->xmlStandalone = true;

		$root = $this->createRootElement($dom);
		$this->addContext($dom, $root, $data['GuidelineID']);
		$this->addExchangedDocument($dom, $root, $data['ExchangedDocument']);
		$this->addAcknowledgementDocument($dom, $root, $data['AcknowledgementDocument']);

		return $dom->saveXML();
	}

	/**
	 * saveToFile
	 *
	 * @param  array $data array of data
	 * @param  string $filename filename
	 * @return bool
	 */
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
	 * @param Facture|FactureFournisseur    $object       Invoice object (CustomerInvoice or SupplierInvoice)
	 * @param int                           $statusCode     Status code to send
	 * @param string                        $reasonCode Reason code to send (optional)
	 *
	 * @return  array{res:int<-1,1>, message:string, file?:string}   Returns array with 'res' (1 on success, -1 on failure) with a 'message' and 'file' with the path.
	 */
	public function generateCdarFile($object, $statusCode, $reasonCode = '')
	{
		global $conf, $mysoc;

		/**
		* Perhaps in future PDP updates, endpoints will appear to simplify sending lifecycle messages without going through CDARs.
		* Currently, CDARs must be generated manually.
		* The CDAR can/must contain several blocks; for some statuses, informational blocks must be added.
		* We should try to create them with the minimum number of mandatory blocks.
		* Blocks will be added based on PDP feedback.
		* Perhaps we need to import the UN/CEFACT XSD files to validate the generated files.
		* We start by processing the following cases:
		* - Acceptance (204) - optional => Implemented
		* - Rejection (210) - mandatory in the case of a rejection (The only mandatory status for now)
		* - Payment transmitted (212) - optional but recommended
		* - Acceptance (205) - optional
		* Others can be added as needed.
		*/

		// Id format: {SupplierRef}_{StatusCode}_{CreationDate}#{DocType}_{CreationDate} as defined in documentation
		// TODO: map DOC_INVOICE with $object type
		$ID = ($statusCode == 212 ? $object->ref : $object->ref_supplier) . '_' . $statusCode . '_' . date('YmdHis', $object->date_creation) . '#' . CdarHandler::DOC_INVOICE . '_' . date('Ymd', $object->date_creation);

		// We use same as ID for Name as its not required to be different
		$Name = $ID;

		// SIREN (0002)
		$mysocGlobalID = idprof($mysoc);

		// Issuer SIREN (0002)
		$InvoiceIssuerGlobalID = $statusCode == 212	// Customer invoices management (Only 212 for now)
			? idprof($mysoc)
			: thirdpartyidprof($object);

		// Invoice reference
		$IssuerAssignedID = $statusCode == 212	// Customer invoices management (Only 212 for now)
			? $object->ref
			: $object->ref_supplier;

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

		// Label for ProcessCondition (Label of status code) we get it from class einvoicing
		dol_include_once('/einvoicing/class/providers/PDPProviderManager.class.php');
		$einvoicing = new EInvoicing($this->db);
		$ProcessCondition = $einvoicing->getStatusLabel($statusCode);
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
					'GlobalID' => $mysocGlobalID, // GlobalID of CDAR SENDER
					'RoleCode' => CdarHandler::ROLE_BY
				],

				'RecipientTradeParty' => [
					'GlobalID'     => $InvoiceIssuerGlobalID, // GlobalID of CDAR RECIPIENT
					'SchemeID'     => CdarHandler::SCHEME_SIREN_0002,
					'RoleCode'     => CdarHandler::ROLE_SE,
					'URIID'        => $InvoiceIssuerGlobalID,
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
					'FormattedIssueDateTime' => date('YmdHis', $object->date),
					'ProcessConditionCode' => $statusCode,
					'ProcessCondition' => $ProcessCondition,

					'SpecifiedDocumentStatus' => !empty($reasonCode) ? [
						'ReasonCode' => $reasonCode,
						//'Reason' => 'Taux de TVA erroné',
						//'SequenceNumeric' => 1
					] : [],

					'IssuerTradeParty' => [
						'GlobalID' => $InvoiceIssuerGlobalID, // GlobalID of invoice sender (Supplier)
						'SchemeID' => CdarHandler::SCHEME_SIREN_0002,
						'RoleCode' => CdarHandler::ROLE_SE
					]
				]
			]
		];

		$tempDir = $conf->einvoicing->dir_temp;
		if (!dol_is_dir($tempDir)) {
			dol_mkdir($tempDir);
		}

		// Unique per-call name so two concurrent status sends of the same condition cannot collide (#226).
		$filename = $tempDir . '/cdar_' . $ProcessCondition . '_' . bin2hex(random_bytes(8)) . '.xml';

		$result = $this->saveToFile($data, $filename);
		if ($result === false) {
			return array('res' => -1, 'message' => 'Error saving CDAR file');
		}
		//echo "CDAR file generated: " . $filename;

		return array('res' => 1, 'message' => 'CDAR file generated successfully', 'file' => $filename);
	}


	// ==================== UTILITY METHODS ====================

	/**
	 * formatDateTime
	 *
	 * @param  string $dateTimeStr datetime
	 *
	 * @return string
	 */
	public static function formatDateTime($dateTimeStr)
	{
		return strlen($dateTimeStr) === 14
			? substr($dateTimeStr, 0, 4) . '-' . substr($dateTimeStr, 4, 2) . '-' .
			  substr($dateTimeStr, 6, 2) . ' ' . substr($dateTimeStr, 8, 2) . ':' .
			  substr($dateTimeStr, 10, 2) . ':' . substr($dateTimeStr, 12, 2)
			: $dateTimeStr;
	}

	/**
	 * formatDate
	 *
	 * @param  string $dateStr date
	 *
	 * @return string
	 */
	public static function formatDate($dateStr)
	{
		return strlen($dateStr) === 8
			? substr($dateStr, 0, 4) . '-' . substr($dateStr, 4, 2) . '-' . substr($dateStr, 6, 2)
			: $dateStr;
	}

	/**
	 * getCurrentDateTime
	 *
	 * @return string
	 */
	public static function getCurrentDateTime()
	{
		return date('YmdHis');
	}

	/**
	 * getCurrentDate
	 *
	 * @return string
	 */
	public static function getCurrentDate()
	{
		return date('Ymd');
	}

	// ==================== PRIVATE HELPERS ====================

	/**
	 * Register the known CDAR namespaces on the given element so that prefixed
	 * XPath queries resolve. Must be done on every element (root AND sub-nodes
	 * returned by xpath()), otherwise libxml raises "Undefined namespace prefix"
	 * and the query silently returns false.
	 *
	 * @param  SimpleXMLElement $xml xml element
	 * @return SimpleXMLElement       same element, for chaining
	 */
	private function registerNamespaces($xml)
	{
		if ($xml instanceof SimpleXMLElement) {
			foreach ($this->namespaces as $prefix => $uri) {
				$xml->registerXPathNamespace($prefix, $uri);
			}
		}
		return $xml;
	}

	/**
	 * getXpathValue
	 *
	 * @param  SimpleXmlElement $xml xml
	 * @param  string $path path
	 * @param  string $default default
	 * @return string
	 */
	private function getXpathValue($xml, $path, $default = '')
	{
		$this->registerNamespaces($xml);
		$result = $xml->xpath($path);

		return !empty($result) ? (string) $result[0] : $default;
	}

	/**
	 * getXpathAttribute
	 *
	 * @param  SimpleXmlElement $xml xml
	 * @param  string $path path
	 * @param  string $attribute attribute
	 * @param  string $default default
	 * @return string
	 */
	private function getXpathAttribute($xml, $path, $attribute, $default = '')
	{
		$this->registerNamespaces($xml);
		$result = $xml->xpath($path);

		return !empty($result) ? (string) $result[0][$attribute] : $default;
	}

	/**
	 * createRootElement
	 *
	 * @param  DOMDocument $dom dom
	 *
	 * @return DOMElement|false
	 */
	private function createRootElement($dom)
	{
		$root = $dom->createElement('rsm:CrossDomainAcknowledgementAndResponse');
		$root->setAttribute('xmlns:rsm', $this->namespaces['rsm']);
		$root->setAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
		$root->setAttribute('xmlns:qdt', $this->namespaces['qdt']);
		$root->setAttribute('xmlns:ram', $this->namespaces['ram']);
		$root->setAttribute('xmlns:udt', $this->namespaces['udt']);
		$dom->appendChild($root);

		return $root;
	}

	/**
	 * addContext
	 *
	 * @param  DOMDocument $dom dom
	 * @param  mixed $root root
	 * @param  mixed $guidelineID guideline id
	 * @return void
	 */
	private function addContext($dom, $root, $guidelineID)
	{
		$context = $dom->createElement('rsm:ExchangedDocumentContext');

		$process = $dom->createElement('ram:BusinessProcessSpecifiedDocumentContextParameter');
		$process->appendChild($dom->createElement('ram:ID', 'REGULATED'));
		$context->appendChild($process);

		$guideline = $dom->createElement('ram:GuidelineSpecifiedDocumentContextParameter');
		$guideline->appendChild($dom->createElement('ram:ID', $guidelineID));
		$context->appendChild($guideline);
		$root->appendChild($context);
	}

	/**
	 * addDateTimeElement
	 *
	 * @param  DOMDocument $dom dom
	 * @param  DOMElement $parent parent
	 * @param  string $elementName element name
	 * @param  string $value value
	 * @param  string $format format
	 * @return void
	 */
	private function addDateTimeElement($dom, $parent, $elementName, $value, $format)
	{
		$element = $dom->createElement($elementName);
		$dateTimeStr = $dom->createElement('udt:DateTimeString', $value);
		$dateTimeStr->setAttribute('format', $format);
		$element->appendChild($dateTimeStr);
		$parent->appendChild($element);
	}

	/**
	 * addTradeParty
	 *
	 * @param  DOMDocument $dom dom
	 * @param  DOMElement $parent parent
	 * @param  string $elementName element name
	 * @param  array $data data
	 * @return void
	 */
	private function addTradeParty($dom, $parent, $elementName, $data)
	{
		$party = $dom->createElement($elementName);

		if (isset($data['GlobalID'])) {
			$globalID = $dom->createElement('ram:GlobalID', $data['GlobalID']);
			if (!empty($data['SchemeID'])) {
				$globalID->setAttribute('schemeID', $data['SchemeID']);
			}
			$party->appendChild($globalID);
		}

		$party->appendChild($dom->createElement('ram:RoleCode', $data['RoleCode']));

		if (isset($data['URIID'])) {
			$uriComm = $dom->createElement('ram:URIUniversalCommunication');
			$uriID = $dom->createElement('ram:URIID', $data['URIID']);
			$uriID->setAttribute('schemeID', $data['URISchemeID']);
			$uriComm->appendChild($uriID);
			$party->appendChild($uriComm);
		}

		$parent->appendChild($party);
	}

	// ==================== PARSING ====================

	/**
	 * parseExchangedDocument
	 *
	 * @param  SimpleXmlElement $xml xml
	 * @return array<string,string|array<string,string>>
	 */
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

	/**
	 * parseAcknowledgementDocument
	 *
	 * @param  SimpleXmlElement $xml xml
	 *
	 * @return array<string,bool|string|array<string,string>>
	 */
	private function parseAcknowledgementDocument($xml)
	{
		$indicator = $this->getXpathValue($xml, '//rsm:AcknowledgementDocument/ram:MultipleReferencesIndicator/udt:Indicator');

		// Parse the referenced document
		$referenceDocument = $this->parseReferencedDocument($xml);

		return [
			'MultipleReferencesIndicator' => $indicator === 'true',
			'TypeCode' => $this->getXpathValue($xml, '//rsm:AcknowledgementDocument/ram:TypeCode'),
			'IssueDateTime' => $this->getXpathValue($xml, '//rsm:AcknowledgementDocument/ram:IssueDateTime/udt:DateTimeString'),
			'ReferenceReferencedDocument' => $referenceDocument
		];
	}

	/**
	 * parseReferencedDocument
	 *
	 * @param  SimpleXmlElement $xml xml
	 *
	 * @return array<string,int|string|array<string,string>>
	 */
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

		$statusNodes = $this->registerNamespaces($xml)->xpath('//ram:ReferenceReferencedDocument/ram:SpecifiedDocumentStatus');
		if (!empty($statusNodes)) {
			$status = $statusNodes[0];
			$result['StatusReasonCode'] = $this->getXpathValue($status, 'ram:ReasonCode');
			$result['StatusReason'] = $this->getXpathValue($status, 'ram:Reason');

			$seqResult = $this->registerNamespaces($status)->xpath('ram:SequenceNumeric');
			if (!empty($seqResult)) {
				$result['StatusSequenceNumeric'] = (int) $seqResult[0];
			}

			// Collect all note contents from all SpecifiedDocumentStatus nodes
			$allContents = [];
			foreach ($statusNodes as $statusNode) {
				$contentNodes = $this->registerNamespaces($statusNode)->xpath('ram:IncludedNote/ram:Content');
				if (!empty($contentNodes)) {
					foreach ($contentNodes as $node) {
						$content = trim((string) $node);
						if ($content !== '') {
							$allContents[] = $content;
						}
					}
				}
			}

			if (!empty($allContents)) {
				$result['StatusIncludedNoteContents'] = $allContents;               // array of all notes
				$result['StatusIncludedNoteContent'] = implode("\n", $allContents); // backward-compatible string
			}
		}

		return $result;
	}

	// ==================== GENERATION ====================

	/**
	 * addExchangedDocument
	 *
	 * @param  DOMDocument $dom dom
	 * @param  DOMElement $root root
	 * @param  array $doc doc
	 * @return void
	 */
	private function addExchangedDocument($dom, $root, $doc)
	{
		$exchanged = $dom->createElement('rsm:ExchangedDocument');
		$exchanged->appendChild($dom->createElement('ram:ID', $doc['ID']));
		$exchanged->appendChild($dom->createElement('ram:Name', $doc['Name']));

		$this->addDateTimeElement($dom, $exchanged, 'ram:IssueDateTime', $doc['IssueDateTime'], self::FORMAT_DATETIME);

		$this->addTradeParty($dom, $exchanged, 'ram:SenderTradeParty', $doc['SenderTradeParty']);
		$this->addTradeParty($dom, $exchanged, 'ram:IssuerTradeParty', $doc['IssuerTradeParty']);
		$this->addTradeParty($dom, $exchanged, 'ram:RecipientTradeParty', $doc['RecipientTradeParty']);

		$root->appendChild($exchanged);
	}

	/**
	 * addAcknowledgementDocument
	 *
	 * @param  DOMDocument $dom dom
	 * @param  DOMElement $root root
	 * @param  array $doc doc
	 * @return void
	 */
	private function addAcknowledgementDocument($dom, $root, $doc)
	{
		$ack = $dom->createElement('rsm:AcknowledgementDocument');

		$multipleRef = $dom->createElement('ram:MultipleReferencesIndicator');
		$indicator = $dom->createElement('udt:Indicator', $doc['MultipleReferencesIndicator'] ? 'true' : 'false');
		$multipleRef->appendChild($indicator);
		$ack->appendChild($multipleRef);

		$ack->appendChild($dom->createElement('ram:TypeCode', $doc['TypeCode']));
		$this->addDateTimeElement($dom, $ack, 'ram:IssueDateTime', $doc['IssueDateTime'], self::FORMAT_DATETIME);
		$this->addReferencedDocument($dom, $ack, $doc['ReferenceReferencedDocument']);

		$root->appendChild($ack);
	}

	/**
	 * addReferencedDocument
	 *
	 * @param  DOMDocument $dom dom
	 * @param  DOMElement $parent parent
	 * @param  array $doc doc
	 * @return void
	 */
	private function addReferencedDocument($dom, $parent, $doc)
	{
		$ref = $dom->createElement('ram:ReferenceReferencedDocument');
		$ref->appendChild($dom->createElement('ram:IssuerAssignedID', $doc['IssuerAssignedID']));
		$ref->appendChild($dom->createElement('ram:StatusCode', $doc['StatusCode']));
		$ref->appendChild($dom->createElement('ram:TypeCode', $doc['TypeCode']));

		$formattedDateTime = $dom->createElement('ram:FormattedIssueDateTime');
		$dateTimeStr = $dom->createElement('qdt:DateTimeString', $doc['FormattedIssueDateTime']);
		$dateTimeStr->setAttribute('format', self::FORMAT_DATETIME);
		$formattedDateTime->appendChild($dateTimeStr);
		$ref->appendChild($formattedDateTime);

		$ref->appendChild($dom->createElement('ram:ProcessConditionCode', $doc['ProcessConditionCode']));
		$ref->appendChild($dom->createElement('ram:ProcessCondition', $doc['ProcessCondition']));

		$this->addTradeParty($dom, $ref, 'ram:IssuerTradeParty', $doc['IssuerTradeParty']);
		$parent->appendChild($ref);

		if (!empty($doc['SpecifiedDocumentStatus'])) {
			$status = $dom->createElement('ram:SpecifiedDocumentStatus');

			if (!empty($doc['SpecifiedDocumentStatus']['ReasonCode'])) {
				$status->appendChild(
					$dom->createElement('ram:ReasonCode', $doc['SpecifiedDocumentStatus']['ReasonCode'])
				);
			}

			if (!empty($doc['SpecifiedDocumentStatus']['Reason'])) {
				$status->appendChild(
					$dom->createElement('ram:Reason', $doc['SpecifiedDocumentStatus']['Reason'])
				);
			}

			/*if (isset($doc['SpecifiedDocumentStatus']['SequenceNumeric'])) {
				$status->appendChild(
					$dom->createElement(
						'ram:SequenceNumeric',
						(int) $doc['SpecifiedDocumentStatus']['SequenceNumeric']
					)
				);
			}*/

			$ref->appendChild($status);
		}
	}
}
