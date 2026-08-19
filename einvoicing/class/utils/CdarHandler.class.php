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
	const STATUS_IN_PROCESS = '45';
	const STATUS_PAID = '47';
	const STATUS_ACKNOWLEDGED = '48';
	const STATUS_DEPOSITED = '10';

	/**
	 * Document status code (MDT-88) that goes with a lifecycle status (MDT-105), as read from the
	 * XP Z12-012 annex B reference examples. The lifecycle statuses those examples do not cover
	 * (refusal, dispute, suspension...) keep the historical "in process", which the platforms accept.
	 */
	const STATUS_CODE_PER_PROCESS_CONDITION = [
		self::PROC_DEPOSITED           => self::STATUS_DEPOSITED,
		self::PROC_RECEIVED            => self::STATUS_RECEIVED,
		self::PROC_AVAILABLE           => self::STATUS_ACKNOWLEDGED,
		self::PROC_TAKEN_OVER          => self::STATUS_IN_PROCESS,
		self::PROC_APPROVED            => self::STATUS_ACCEPTED,
		self::PROC_PAYMENT_TRANSMITTED => self::STATUS_PAID,
		self::PROC_PAID                => self::STATUS_PAID,
	];

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
	 * @param array{amount?:float,breakdown?:array<array{vatrate:float,amount:float}>}  $paymentData  Cashed amount (TTC, in the company currency) for status 212, with an optional ready-made breakdown by VAT rate
	 *
	 * @return  array{res:int<-1,1>, message:string, file?:string}   Returns array with 'res' (1 on success, -1 on failure) with a 'message' and 'file' with the path.
	 */
	public function generateCdarFile($object, $statusCode, $reasonCode = '', $paymentData = array())
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

		// 212 (Encaissee) is the only status we send on one of OUR OWN invoices: we are then the seller and
		// the CDAR is addressed to our customer. Every other status is sent on a supplier invoice, where we
		// are the buyer and the CDAR goes back to the vendor. Getting those two parties the wrong way round
		// makes the platform answer "no matching invoices found": it cannot find the invoice the status is
		// about. See the XP Z12-012 annex B examples, UC1 205/211 (issued by the buyer) versus UC1 212
		// (issued by the seller).
		$isOurOwnInvoice = ($statusCode == CdarHandler::PROC_PAID);

		// SIREN (0002)
		$mysocGlobalID = idprof($mysoc);

		// Issuer SIREN (0002) of the invoice the status is about: us when we sell, the vendor otherwise
		$InvoiceIssuerGlobalID = $isOurOwnInvoice
			? $mysocGlobalID
			: thirdpartyidprof($object);

		// Invoice reference
		$IssuerAssignedID = $isOurOwnInvoice
			? $object->ref
			: $object->ref_supplier;

		/**
		 * MDT-88
		 * TODO: the lifecycle statuses with no reference example still fall back on "in process":
		 * 39 (on hold) = Suspendue
		 * 37 (Complete) = Complétée
		 * 50 (Rejected / Refused) = Refusée (by C4)
		 * 49 (Conditionally accepted) = Approuvée Partiellement
		 * 46 (Under Query) = En litige
		 */
		// The keys of the map are numeric strings, which PHP stores as integer array keys
		$StatusCodeCdar = CdarHandler::STATUS_CODE_PER_PROCESS_CONDITION[(int) $statusCode] ?? CdarHandler::STATUS_IN_PROCESS;

		// Label for ProcessCondition (Label of status code) we get it from class einvoicing
		dol_include_once('/einvoicing/class/providers/PDPProviderManager.class.php');
		$einvoicing = new EInvoicing($this->db);
		$ProcessCondition = $einvoicing->getStatusLabel($statusCode);
		$ProcessCondition = str_replace(' ', '_', $ProcessCondition);
		$ProcessCondition = preg_replace('/[^A-Za-z0-9_]/', '', $ProcessCondition); // Clean special chars

		// Electronic address (MDT-73) of the CDAR recipient. Every status but the cash-in (212) is sent on a
		// supplier invoice: we are the buyer and the CDAR goes back to the vendor. Sending its SIREN blindly
		// only works when the platform happens to know the vendor under that very address, and gets the
		// message refused with "Electronic address (MDT-73) is invalid" otherwise.
		//
		// The status is a reply, so the address to reply to is the one the vendor exchanges under:
		//   1. a routing recorded in Dolibarr for that vendor, which is a deliberate choice of ours;
		//   2. otherwise the electronic address (BT-34) carried by the e-invoice we received, which is the
		//      vendor telling us where it exchanges from;
		//   3. otherwise the platform directory, which may list another address of the same SIREN;
		//   4. otherwise the SIREN guessed by getBuyerCommunicationURI(). It is called on the third party
		//      alone: the invoice-level routing override it also knows about is looked up among the customer
		//      invoices (element_type = 'facture'), which a supplier invoice must not read.
		$RecipientURIID = $InvoiceIssuerGlobalID;
		if ($statusCode != 212 && $object->thirdparty instanceof Societe) {
			$vendorRouting = $einvoicing->fetchDefaultRouting($object->thirdparty->id);
			$vendorURIID = ($vendorRouting > 0) ? $einvoicing->removeSpaces((string) $vendorRouting) : '';	// 0 when none is recorded, -1 on error

			if ($vendorURIID === '') {
				$vendorURIID = $einvoicing->removeSpaces($this->getVendorAddressFromReceivedInvoice($object));
				if ($vendorURIID !== '') {
					dol_syslog(__METHOD__ . ' no routing ID recorded for vendor SIREN ' . $InvoiceIssuerGlobalID . ', replying to the electronic address of the invoice it sent us: ' . $vendorURIID, LOG_NOTICE);
				}
			}

			if ($vendorURIID === '' && $InvoiceIssuerGlobalID !== '') {
				// checkRecipientDirectory() returns the first active reception address declared for that
				// SIREN, and degrades to an empty identifier on the providers that expose no directory.
				$PDPManager = new PDPProviderManager($this->db);
				$provider = $PDPManager->getProvider(getDolGlobalString('EINVOICING_PDP'));
				if (is_object($provider)) {
					$directory = $provider->checkRecipientDirectory($InvoiceIssuerGlobalID);
					if (!empty($directory['identifier'])) {
						$vendorURIID = $einvoicing->removeSpaces($directory['identifier']);
						dol_syslog(__METHOD__ . ' nothing known about how to reach vendor SIREN ' . $InvoiceIssuerGlobalID . ', using the address the directory declares for it: ' . $vendorURIID, LOG_NOTICE);
					} else {
						dol_syslog(__METHOD__ . ' nothing known about how to reach vendor SIREN ' . $InvoiceIssuerGlobalID . ' and the directory returned none (' . $directory['status'] . '), falling back on the SIREN as electronic address: the platform will refuse the status if it does not know the vendor under that address', LOG_WARNING);
					}
				}
			}

			if ($vendorURIID === '') {
				$vendorURIID = $einvoicing->getBuyerCommunicationURI($object->thirdparty);
			}

			if ($vendorURIID !== '') {	// Empty with EINVOICING_BLOCK_INVOICE_NO_ROUTING_ID and no routing: keep the SIREN, an empty MDT-73 is worse
				$RecipientURIID = $vendorURIID;
			}
		}

		// MDG-43 blocks. Rule BR-FR-CDV-14: a "Encaissee" status (212) must carry at least one block with
		// MDT-207 = MEN, and every MEN block must hold both an amount (MDT-215) and a VAT rate (MDT-224).
		// Without them the platform rejects the CDAR with a 400.
		$SpecifiedDocumentStatus = array();
		if (!empty($reasonCode)) {
			$SpecifiedDocumentStatus['ReasonCode'] = $reasonCode;
			//$SpecifiedDocumentStatus['Reason'] = 'Taux de TVA erroné';
		}
		if ($statusCode == CdarHandler::PROC_PAID) {
			$cashedAmounts = $this->getCashedAmountCharacteristics($object, $paymentData);
			if (empty($cashedAmounts)) {
				// Better to fail here than to have the platform reject the CDAR on BR-FR-CDV-14.
				return array('res' => -1, 'message' => 'Cannot compute the cashed amount (MEN) per VAT rate for invoice ' . $object->ref);
			}
			$SpecifiedDocumentStatus['SpecifiedDocumentCharacteristic'] = $cashedAmounts;
		} elseif ($statusCode == CdarHandler::PROC_PAYMENT_TRANSMITTED) {
			// "Paiement transmis" tells the vendor what was paid and when (MDG-43 block MDT-207 = MPA).
			// No rule makes it mandatory, so a status with no known amount is still sent, just bare.
			$paidAmounts = $this->getPaymentSentCharacteristics($object, $paymentData);
			if (!empty($paidAmounts)) {
				$SpecifiedDocumentStatus['SpecifiedDocumentCharacteristic'] = $paidAmounts;
			}
		}
		if (!empty($SpecifiedDocumentStatus)) {
			// Rule BR-FR-CDV-16: any status detail block must be numbered (MDT-124-2). Only one block is sent.
			$SpecifiedDocumentStatus['SequenceNumeric'] = 1;
		}

		if ($isOurOwnInvoice) {
			// We issue the status as the SELLER, and it is addressed to the buyer of the invoice
			$CdarIssuerTradeParty = [
				'GlobalID' => $mysocGlobalID,
				'SchemeID' => CdarHandler::SCHEME_SIREN_0002,
				'RoleCode' => CdarHandler::ROLE_SE
			];

			$buyerGlobalID = thirdpartyidprof($object);
			$buyerURIID = $einvoicing->getBuyerCommunicationURI($object->thirdparty, $object);
			$CdarRecipientTradeParty = [
				'GlobalID'     => $buyerGlobalID,
				'SchemeID'     => CdarHandler::SCHEME_SIREN_0002,
				'RoleCode'     => CdarHandler::ROLE_BY,
				'URIID'        => $buyerURIID !== '' ? $buyerURIID : $buyerGlobalID,
				'URISchemeID'  => CdarHandler::SCHEME_SIREN_0225
			];
		} else {
			// We issue the status as the BUYER of a supplier invoice, and it goes back to the vendor
			$CdarIssuerTradeParty = [
				'GlobalID' => $mysocGlobalID, // GlobalID of CDAR SENDER
				'RoleCode' => CdarHandler::ROLE_BY
			];

			$CdarRecipientTradeParty = [
				'GlobalID'     => $InvoiceIssuerGlobalID, // GlobalID of CDAR RECIPIENT
				'SchemeID'     => CdarHandler::SCHEME_SIREN_0002,
				'RoleCode'     => CdarHandler::ROLE_SE,
				'URIID'        => $RecipientURIID,	// The routing of the vendor, its SIREN when none is recorded
				'URISchemeID'  => CdarHandler::SCHEME_SIREN_0225
			];
		}

		$data = [
			'GuidelineID' => 'urn.cpro.gouv.fr:1p0:CDV:invoice',

			'ExchangedDocument' => [
				'ID' => $ID,
				'Name' => $Name,
				'IssueDateTime' => CdarHandler::getCurrentDateTime(),

				'SenderTradeParty' => [
					'RoleCode' => CdarHandler::ROLE_WK
				],

				'IssuerTradeParty' => $CdarIssuerTradeParty,

				'RecipientTradeParty' => $CdarRecipientTradeParty
			],

			'AcknowledgementDocument' => [
				'MultipleReferencesIndicator' => false,
				'TypeCode' => '23',
				'IssueDateTime' => CdarHandler::getCurrentDateTime(),

				'ReferenceReferencedDocument' => [
					'IssuerAssignedID' => $IssuerAssignedID,
					'StatusCode' => $StatusCodeCdar,
					'TypeCode' => CdarHandler::DOC_INVOICE, // TODO: map DOC_INVOICE with $object type
					// Every XP Z12-012 reference example dates the referenced invoice with a plain date
					'FormattedIssueDateTime' => date('Ymd', $object->date),
					'ProcessConditionCode' => $statusCode,
					'ProcessCondition' => $ProcessCondition,

					'SpecifiedDocumentStatus' => $SpecifiedDocumentStatus,

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

	/**
	 * Build the MDG-43 "paid amount" (MPA) block of a status 211 (Paiement transmis) CDAR.
	 *
	 * That status tells the vendor of a supplier invoice that its payment has been sent: the block holds
	 * how much was paid (MDT-215) and when (MDT-217), as in the XP Z12-012 annex B example. Unlike the
	 * cash-in, no rule makes it mandatory, hence an empty return when no amount is known.
	 *
	 * @param  FactureFournisseur|Facture $object      Invoice that has been paid
	 * @param  array{amount?:float,date?:int}          $paymentData Amount paid (TTC, company currency) and its date as a timestamp. Both default to the payments recorded on the invoice.
	 * @return array<array{TypeCode:string,ValueAmount:string,CurrencyID:string,ValueDateTime:string}>  MPA block, empty if no amount is known
	 */
	public function getPaymentSentCharacteristics($object, $paymentData = array())
	{
		global $conf;

		$paidAmount = isset($paymentData['amount']) ? (float) $paymentData['amount'] : 0.0;
		if (empty($paidAmount) && method_exists($object, 'getSommePaiement')) {
			$paidAmount = (float) $object->getSommePaiement();
		}
		if ($paidAmount <= 0) {
			dol_syslog(__METHOD__ . ' No paid amount found for invoice id=' . $object->id, LOG_WARNING, 0, '_einvoicing');
			return array();
		}

		$paidDate = empty($paymentData['date']) ? dol_now() : $paymentData['date'];

		return array(
			array(
				'TypeCode' => 'MPA',
				'ValueAmount' => number_format($paidAmount, 2, '.', ''),
				'CurrencyID' => $conf->currency,
				'ValueDateTime' => dol_print_date($paidDate, '%Y%m%d')
			)
		);
	}

	/**
	 * Electronic address (BT-34) the vendor put on the e-invoice we received from it.
	 *
	 * That address is the vendor saying where it exchanges from, so it is the natural place to send a
	 * status back to when no routing was recorded for it in Dolibarr. Read from the e-invoice stored with
	 * the supplier invoice, never by calling the platform back: addressing a status is no reason for a
	 * network round trip, and an invoice keyed by hand simply has none.
	 *
	 * @param  FactureFournisseur $object  Supplier invoice the status is sent on
	 * @return string                      The address, '' when there is no stored e-invoice to read it from
	 */
	private function getVendorAddressFromReceivedInvoice($object)
	{
		if (empty($object->id) || $object->element !== 'invoice_supplier') {
			return '';
		}

		dol_include_once('/einvoicing/class/utils/SupplierInvoiceHelper.class.php');

		$xmlData = '';
		try {
			// false: an invoice with no e-invoice stored is a normal case (keyed by hand), and addressing
			// a status is no reason to call the platform back.
			$xmlData = (string) SupplierInvoiceHelper::getXmlData((int) $object->id, false);
		} catch (Exception $e) {
			dol_syslog(__METHOD__ . ' no e-invoice stored for supplier invoice id ' . $object->id . ': ' . $e->getMessage(), LOG_DEBUG);
			return '';
		}
		if ($xmlData === '') {
			return '';
		}

		$xml = @simplexml_load_string($xmlData);
		if ($xml === false) {
			dol_syslog(__METHOD__ . ' the e-invoice stored for supplier invoice id ' . $object->id . ' is not parsable XML', LOG_WARNING);
			return '';
		}

		// Only ram: is needed, so the same read works on a CII and on the XML extracted from a Factur-X
		$xml->registerXPathNamespace('ram', 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100');
		$found = $xml->xpath('//ram:SellerTradeParty/ram:URIUniversalCommunication/ram:URIID');
		if (empty($found)) {
			return '';
		}

		return trim((string) $found[0]);
	}

	/**
	 * Build the MDG-43 "cashed amount" (MEN) blocks of a status 212 (Encaissee) CDAR.
	 *
	 * The reform asks the seller to declare what was actually cashed, broken down by VAT rate: one block per
	 * rate, holding the TTC amount (MDT-215) and the rate itself (MDT-224). Dolibarr only records a payment as
	 * a single TTC amount, so the amount is spread over the VAT rates of the invoice proportionally to their
	 * TTC weight: exact for a fully paid invoice, prorata otherwise (a partial payment is not attached to
	 * given lines in Dolibarr). Rounding differences are absorbed by the largest block so the blocks always
	 * sum up to the cashed amount.
	 *
	 * @param  Facture|FactureFournisseur $object       Invoice the payment belongs to
	 * @param  array{amount?:float,breakdown?:array<array{vatrate:float,amount:float}>}  $paymentData  Cashed amount (TTC, company currency) and/or a ready-made breakdown. Defaults to the sum of the payments of the invoice.
	 * @return array<array{TypeCode:string,ValueAmount:string,CurrencyID:string,ValuePercent:string}>  MEN blocks, empty if they cannot be computed
	 */
	public function getCashedAmountCharacteristics($object, $paymentData = array())
	{
		global $conf;

		$breakdown = array();

		if (!empty($paymentData['breakdown'])) {
			$breakdown = $paymentData['breakdown'];
		} else {
			$cashedAmount = isset($paymentData['amount']) ? (float) $paymentData['amount'] : 0.0;
			if (empty($cashedAmount) && method_exists($object, 'getSommePaiement')) {
				$cashedAmount = (float) $object->getSommePaiement();
			}
			if ($cashedAmount <= 0) {
				dol_syslog(__METHOD__ . ' No cashed amount found for invoice id=' . $object->id, LOG_WARNING, 0, '_einvoicing');
				return array();
			}

			if (empty($object->lines)) {
				$object->fetch_lines();
			}

			// TTC weight of each VAT rate of the invoice
			$totalPerRate = array();
			foreach ($object->lines as $line) {
				$rate = (string) price2num($line->tva_tx, 'MU');
				if (!isset($totalPerRate[$rate])) {
					$totalPerRate[$rate] = 0.0;
				}
				$totalPerRate[$rate] += (float) $line->total_ttc;
			}

			$totalTtc = array_sum($totalPerRate);
			if ($totalTtc <= 0) {
				dol_syslog(__METHOD__ . ' Cannot split the cashed amount, invoice id=' . $object->id . ' has no positive TTC total', LOG_WARNING, 0, '_einvoicing');
				return array();
			}

			$ratio = $cashedAmount / $totalTtc;
			$rounded = 0.0;
			$biggest = null;
			foreach ($totalPerRate as $rate => $amountTtc) {
				$amount = (float) price2num($amountTtc * $ratio, 'MT');
				$rounded += $amount;
				$breakdown[$rate] = array('vatrate' => (float) $rate, 'amount' => $amount);
				if (is_null($biggest) || $amount > $breakdown[$biggest]['amount']) {
					$biggest = $rate;
				}
			}

			// Keep the sum of the blocks equal to what was really cashed
			$residual = (float) price2num($cashedAmount - $rounded, 'MT');
			if (!empty($residual) && !is_null($biggest)) {
				$breakdown[$biggest]['amount'] = (float) price2num($breakdown[$biggest]['amount'] + $residual, 'MT');
			}
		}

		$characteristics = array();
		foreach ($breakdown as $entry) {
			if (empty($entry['amount'])) {	// A rate that cashed nothing brings no information
				continue;
			}
			$characteristics[] = array(
				'TypeCode' => 'MEN',
				'ValueAmount' => number_format((float) $entry['amount'], 2, '.', ''),
				'CurrencyID' => $conf->currency,
				'ValuePercent' => number_format((float) $entry['vatrate'], 2, '.', '')
			);
		}

		return $characteristics;
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
		$dateTimeStr->setAttribute('format', self::FORMAT_DATE);
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

			if (isset($doc['SpecifiedDocumentStatus']['SequenceNumeric'])) {
				$status->appendChild(
					$dom->createElement(
						'ram:SequenceNumeric',
						(string) $doc['SpecifiedDocumentStatus']['SequenceNumeric']
					)
				);
			}

			// MDG-43 blocks (cashed amount per VAT rate for status 212). Element order follows the D22B
			// DocumentCharacteristicType sequence: TypeCode, then ValueAmount, then ValuePercent.
			if (!empty($doc['SpecifiedDocumentStatus']['SpecifiedDocumentCharacteristic'])) {
				foreach ($doc['SpecifiedDocumentStatus']['SpecifiedDocumentCharacteristic'] as $characteristic) {
					$characteristicElement = $dom->createElement('ram:SpecifiedDocumentCharacteristic');
					$characteristicElement->appendChild($dom->createElement('ram:TypeCode', $characteristic['TypeCode']));

					if (isset($characteristic['ValueAmount'])) {
						$amountElement = $dom->createElement('ram:ValueAmount', (string) $characteristic['ValueAmount']);
						if (!empty($characteristic['CurrencyID'])) {
							$amountElement->setAttribute('currencyID', $characteristic['CurrencyID']);
						}
						$characteristicElement->appendChild($amountElement);
					}

					if (isset($characteristic['ValueDateTime'])) {
						$this->addDateTimeElement($dom, $characteristicElement, 'ram:ValueDateTime', $characteristic['ValueDateTime'], self::FORMAT_DATE);
					}

					if (isset($characteristic['ValuePercent'])) {
						$characteristicElement->appendChild($dom->createElement('ram:ValuePercent', (string) $characteristic['ValuePercent']));
					}

					$status->appendChild($characteristicElement);
				}
			}

			$ref->appendChild($status);
		}
	}
}
