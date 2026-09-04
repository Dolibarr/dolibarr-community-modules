<?php
/* Copyright (C) 2026       Laurent Destailleur         <eldy@users.sourceforge.net>
 * Copyright (C) 2026       Mohamed DAOUD               <mdaoud@dolicloud.com>
 * Copyright (C) 2026		MDW							<mdeweerd@users.noreply.github.com>
 * Copyright (C) 2026		Jose Martinez				<jose.martinez@pichinov.com>
 *
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
 * \file    einvoicing/class/protocols/CIIProtocol.class.php
 * \ingroup einvoicing
 * \brief   CII Protocol integration class
 */

require_once DOL_DOCUMENT_ROOT . '/compta/bank/class/account.class.php';
require_once DOL_DOCUMENT_ROOT . '/expedition/class/expedition.class.php';
require_once DOL_DOCUMENT_ROOT . '/commande/class/commande.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/class/translate.class.php';
require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/class/discount.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/files.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/date.lib.php';

// dolChmod() only exists from Dolibarr 18, and both writers call it on the XML they just produced.
if ((float) DOL_VERSION < 18) {
	dol_include_once('/einvoicing/compat/files.lib.php');
}

dol_include_once('einvoicing/class/protocols/AbstractProtocol.class.php');
dol_include_once('einvoicing/class/protocols/CommonProtocol.class.php');
dol_include_once('einvoicing/class/einvoicing.class.php');
dol_include_once('einvoicing/class/utils/XmlPatcher.class.php');
dol_include_once('einvoicing/class/utils/SupplierInvoiceHelper.class.php');
dol_include_once('einvoicing/lib/einvoicing.lib.php');


/**
 * CII Protocol Class
 *
 * This class handles the CII protocol implementation for generating
 * and managing electronic invoices according to the CII standard.
 */
class CIIProtocol extends AbstractProtocol
{
	use CommonProtocol;

	/** @const string Invoice file extension (without the dot, example 'xml') */
	const INVOICE_FILE_EXTENSION = 'xml';

	/** @const string Generated invoice file name */
	const GENERATED_INVOICE_XML_FILE_NAME = 'einvoice.xml';

	/** @const string Default profile used to generate XML, overridable with EINVOICING_XML_PROFILE */
	const BUILD_XML_PROFILE = 'EN16931';

	/** @var string[] Profiles buildXML() knows how to emit, and accepted values of EINVOICING_XML_PROFILE */
	const SUPPORTED_XML_PROFILES = ['MINIMUM', 'BASICWL', 'BASIC', 'EN16931', 'EXTENDED', 'EXTENDEDFR'];

	/**
	 * Maximum number of decimals allowed for the unit prices: Item net price (BT-146),
	 * Item price discount (BT-147) and Item gross price (BT-148).
	 *
	 * EN 16931 sets no limit on these (BT-146 only carries BR-26 and BR-27), but the French
	 * CTC layer does: rule BR-FR-DEC-03 of the FNFE-MPE CTC-FR Schematron rejects, with a
	 * "fatal" flag, any value matching more than 6 decimals ('^[-]?\d{1,19}(\.\d{1,6})?$').
	 * Note this is NOT the 2 decimals limit of the amounts (BR-DEC-* / BR-FR-DEC-01): a unit
	 * price is not an amount, and no rule checks that the line net amount (BT-131) derives
	 * arithmetically from the unit price.
	 *
	 * @const int
	 */
	const MAX_DECIMALS_UNIT_PRICE = 6;

	/**
	 * Maximum number of decimals allowed for the quantities: Invoiced quantity (BT-129) and
	 * Item price base quantity (BT-149), per rule BR-FR-DEC-02 of the CTC-FR Schematron ("fatal").
	 *
	 * @const int
	 */
	const MAX_DECIMALS_QUANTITY = 4;

	/**
	 * @var array<string,string>
	 */
	protected $invoiceTemplate;
	/**
	 * @var array<string,null|false|int|string|array>
	 */
	protected $lineTemplate;
	/**
	 * Number of transactions opened by doCreateSupplierInvoiceFromSource() and not closed by it yet.
	 * Drained (committed or rolled back) by createSupplierInvoiceFromSource(), the public wrapper.
	 *
	 * We count what we open instead of reading $db->transaction_opened: that property belongs to the
	 * database handler and is not readable when $db is a wrapper around it (it then stays null, and
	 * the transaction was silently left open - see issue #524).
	 *
	 * @var int
	 */
	protected $openedTransactions = 0;
	/**
	 * Return the number of decimals to use for a unit price (BT-146, BT-147, BT-148).
	 *
	 * Follows the Dolibarr unit price accuracy, so a price is transmitted as accurately as it is
	 * stored, but never above what the norm accepts (see self::MAX_DECIMALS_UNIT_PRICE): an
	 * instance configured with a higher accuracy would otherwise produce invoices rejected by the
	 * Access Point. Never goes below 2, which is the accuracy of an amount.
	 *
	 * @return	int							Number of decimals
	 */
	protected static function getUnitPriceDecimals()
	{
		$decimals = getDolGlobalInt('MAIN_MAX_DECIMALS_UNIT', 5);

		return max(2, min($decimals, self::MAX_DECIMALS_UNIT_PRICE));
	}

	/**
	 * Initialize available protocols.
	 *
	 * @param	DoliDB		$db		DB handler
	 */
	public function __construct($db)
	{
		$this->db = $db;

		$this->invoiceTemplate = [

			// ── Document ────────────────────────────────────────────────────────
			'documentno' => '/rsm:CrossIndustryInvoice/rsm:ExchangedDocument/ram:ID',
			'documenttypecode' => '/rsm:CrossIndustryInvoice/rsm:ExchangedDocument/ram:TypeCode',
			'documentdate' => '/rsm:CrossIndustryInvoice/rsm:ExchangedDocument/ram:IssueDateTime/udt:DateTimeString',
			'invoiceCurrency' => '/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeSettlement/ram:InvoiceCurrencyCode',
			'taxCurrency' => '/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeSettlement/ram:TaxCurrencyCode',
			'documentname' => '/rsm:CrossIndustryInvoice/rsm:ExchangedDocument/ram:Name',
			'documentlanguage' => '/rsm:CrossIndustryInvoice/rsm:ExchangedDocument/ram:LanguageID',
			'effectiveSpecifiedPeriod' => 'NA',

			'documentDeliveryDate' => '/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeDelivery/ram:ActualDeliverySupplyChainEvent/ram:OccurrenceDateTime/udt:DateTimeString',

			'invoicingPeriodStart' => '/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeSettlement/ram:BillingSpecifiedPeriod/ram:StartDateTime/udt:DateTimeString',
			'invoicingPeriodEnd' => '/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeSettlement/ram:BillingSpecifiedPeriod/ram:EndDateTime/udt:DateTimeString',

			'businessProcessId' => '/rsm:CrossIndustryInvoice/rsm:ExchangedDocumentContext/ram:BusinessProcessSpecifiedDocumentContextParameter/ram:ID',
			'guidelineId' => '/rsm:CrossIndustryInvoice/rsm:ExchangedDocumentContext/ram:GuidelineSpecifiedDocumentContextParameter/ram:ID',
			'isTestDocument' => 'NA',

			// ── Notes ────────────────────────────────────────────────────────────
			'documentNotePublic' => '/rsm:CrossIndustryInvoice/rsm:ExchangedDocument/ram:IncludedNote[1]/ram:Content',
			// Notes by SubjectCode
			'documentNotePMT' => '/rsm:CrossIndustryInvoice/rsm:ExchangedDocument/ram:IncludedNote[ram:SubjectCode="PMT"]/ram:Content',
			'documentNotePMD' => '/rsm:CrossIndustryInvoice/rsm:ExchangedDocument/ram:IncludedNote[ram:SubjectCode="PMD"]/ram:Content',
			'documentNoteAAB' => '/rsm:CrossIndustryInvoice/rsm:ExchangedDocument/ram:IncludedNote[ram:SubjectCode="AAB"]/ram:Content',
			// All notes (multi-value: returns array of ['content'=>…,'subjectCode'=>…])
			'documentNotes' => '__MULTI__/rsm:CrossIndustryInvoice/rsm:ExchangedDocument/ram:IncludedNote',

			// ── Seller ───────────────────────────────────────────────────────────
			'sellername' => '/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeAgreement/ram:SellerTradeParty/ram:Name',
			'sellerids' => '/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeAgreement/ram:SellerTradeParty/ram:ID',

			'sellerlineone' => '/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeAgreement/ram:SellerTradeParty/ram:PostalTradeAddress/ram:LineOne',
			'sellerlinetwo' => '/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeAgreement/ram:SellerTradeParty/ram:PostalTradeAddress/ram:LineTwo',
			'sellerlinethree' => '/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeAgreement/ram:SellerTradeParty/ram:PostalTradeAddress/ram:LineThree',
			'sellerpostcode' => '/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeAgreement/ram:SellerTradeParty/ram:PostalTradeAddress/ram:PostcodeCode',
			'sellercity' => '/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeAgreement/ram:SellerTradeParty/ram:PostalTradeAddress/ram:CityName',
			'sellercountry' => '/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeAgreement/ram:SellerTradeParty/ram:PostalTradeAddress/ram:CountryID',
			'sellersubdivision' => '/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeAgreement/ram:SellerTradeParty/ram:PostalTradeAddress/ram:CountrySubDivisionName',

			'sellercontactpersonname' => '/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeAgreement/ram:SellerTradeParty/ram:DefinedTradeContact/ram:PersonName',
			'sellercontactdepartmentname' => '/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeAgreement/ram:SellerTradeParty/ram:DefinedTradeContact/ram:DepartmentName',
			'sellercontactphoneno' => '/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeAgreement/ram:SellerTradeParty/ram:DefinedTradeContact/ram:TelephoneUniversalCommunication/ram:CompleteNumber',
			'sellercontactemailaddr' => '/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeAgreement/ram:SellerTradeParty/ram:DefinedTradeContact/ram:EmailURIUniversalCommunication/ram:URIID',

			'sellerCommunicationUriScheme' => '/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeAgreement/ram:SellerTradeParty/ram:URIUniversalCommunication/ram:URIID/@schemeID',
			'sellerCommunicationUri' => '/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeAgreement/ram:SellerTradeParty/ram:URIUniversalCommunication/ram:URIID',
			// ─────────────────────────────────────────────────────────────────────

			// Returns array ['schemeID' => id, 'value' => globalId]
			'sellerGlobalIds' => '__ATTRPAIRS__/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeAgreement/ram:SellerTradeParty/ram:GlobalID',
			// Returns array ['type' => VA/FC/..., 'value' => id]
			'sellerTaxRegistations' => '__ATTRPAIRS__/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeAgreement/ram:SellerTradeParty/ram:SpecifiedTaxRegistration/ram:ID',
			'sellervatnumber' => '/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeAgreement/ram:SellerTradeParty/ram:SpecifiedTaxRegistration[ram:ID/@schemeID="VA"]/ram:ID',

			'sellerLegalOrgId' => '/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeAgreement/ram:SellerTradeParty/ram:SpecifiedLegalOrganization/ram:ID',
			'sellerLegalOrgScheme' => '/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeAgreement/ram:SellerTradeParty/ram:SpecifiedLegalOrganization/ram:ID/@schemeID',
			'sellerTradingName' => '/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeAgreement/ram:SellerTradeParty/ram:SpecifiedLegalOrganization/ram:TradingBusinessName',

			// ── Buyer ────────────────────────────────────────────────────────────
			'buyername' => '/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeAgreement/ram:BuyerTradeParty/ram:Name',
			'buyerids' => '/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeAgreement/ram:BuyerTradeParty/ram:ID',

			'buyerlineone' => '/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeAgreement/ram:BuyerTradeParty/ram:PostalTradeAddress/ram:LineOne',
			'buyerlinetwo' => '/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeAgreement/ram:BuyerTradeParty/ram:PostalTradeAddress/ram:LineTwo',
			'buyerlinethree' => '/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeAgreement/ram:BuyerTradeParty/ram:PostalTradeAddress/ram:LineThree',
			'buyerpostcode' => '/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeAgreement/ram:BuyerTradeParty/ram:PostalTradeAddress/ram:PostcodeCode',
			'buyercity' => '/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeAgreement/ram:BuyerTradeParty/ram:PostalTradeAddress/ram:CityName',
			'buyercountry' => '/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeAgreement/ram:BuyerTradeParty/ram:PostalTradeAddress/ram:CountryID',
			'buyersubdivision' => '/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeAgreement/ram:BuyerTradeParty/ram:PostalTradeAddress/ram:CountrySubDivisionName',

			'buyervatnumber' => '/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeAgreement/ram:BuyerTradeParty/ram:SpecifiedTaxRegistration[ram:ID/@schemeID="VA"]/ram:ID',
			'buyerGlobalIds' => '__ATTRPAIRS__/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeAgreement/ram:BuyerTradeParty/ram:GlobalID',

			'buyerLegalOrgId' => '/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeAgreement/ram:BuyerTradeParty/ram:SpecifiedLegalOrganization/ram:ID',
			'buyerLegalOrgScheme' => '/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeAgreement/ram:BuyerTradeParty/ram:SpecifiedLegalOrganization/ram:ID/@schemeID',
			'buyerTradingName' => '/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeAgreement/ram:BuyerTradeParty/ram:SpecifiedLegalOrganization/ram:TradingBusinessName',

			'buyerReference' => '/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeAgreement/ram:BuyerReference',

			'buyercontactpersonname' => '/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeAgreement/ram:BuyerTradeParty/ram:DefinedTradeContact/ram:PersonName',
			'buyercontactemailaddr' => '/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeAgreement/ram:BuyerTradeParty/ram:DefinedTradeContact/ram:EmailURIUniversalCommunication/ram:URIID',
			'buyercontactphoneno' => '/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeAgreement/ram:BuyerTradeParty/ram:DefinedTradeContact/ram:TelephoneUniversalCommunication/ram:CompleteNumber',

			// ── Totals ───────────────────────────────────────────────────────────
			'grandTotalAmount' => '/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeSettlement/ram:SpecifiedTradeSettlementHeaderMonetarySummation/ram:GrandTotalAmount',
			'duePayableAmount' => '/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeSettlement/ram:SpecifiedTradeSettlementHeaderMonetarySummation/ram:DuePayableAmount',
			'lineTotalAmount' => '/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeSettlement/ram:SpecifiedTradeSettlementHeaderMonetarySummation/ram:LineTotalAmount',
			'chargeTotalAmount' => '/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeSettlement/ram:SpecifiedTradeSettlementHeaderMonetarySummation/ram:ChargeTotalAmount',
			'allowanceTotalAmount' => '/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeSettlement/ram:SpecifiedTradeSettlementHeaderMonetarySummation/ram:AllowanceTotalAmount',
			'taxBasisTotalAmount' => '/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeSettlement/ram:SpecifiedTradeSettlementHeaderMonetarySummation/ram:TaxBasisTotalAmount',
			'taxTotalAmount' => '/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeSettlement/ram:SpecifiedTradeSettlementHeaderMonetarySummation/ram:TaxTotalAmount',
			'roundingAmount' => '/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeSettlement/ram:SpecifiedTradeSettlementHeaderMonetarySummation/ram:RoundingAmount',
			'totalPrepaidAmount' => '/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeSettlement/ram:SpecifiedTradeSettlementHeaderMonetarySummation/ram:TotalPrepaidAmount',

			// ── Payment ──────────────────────────────────────────────────────────
			'paymentMeansCode' => '/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeSettlement/ram:SpecifiedTradeSettlementPaymentMeans/ram:TypeCode',
			'paymentMeansText' => '/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeSettlement/ram:SpecifiedTradeSettlementPaymentMeans/ram:Information',
			'iban' => '/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeSettlement/ram:SpecifiedTradeSettlementPaymentMeans/ram:PayeePartyCreditorFinancialAccount/ram:IBANID',
			'bic' => '/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeSettlement/ram:SpecifiedTradeSettlementPaymentMeans/ram:PayeeSpecifiedCreditorFinancialInstitution/ram:BICID',
			'accountName' => '/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeSettlement/ram:SpecifiedTradeSettlementPaymentMeans/ram:PayeePartyCreditorFinancialAccount/ram:AccountName',

			'paymentDueDate' => '/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeSettlement/ram:SpecifiedTradePaymentTerms/ram:DueDateDateTime/udt:DateTimeString',
			'paymentTermsText' => '/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeSettlement/ram:SpecifiedTradePaymentTerms/ram:Description',

			// ── Header-level allowances & charges ────────────────────────────────
			'headerAllowancesCharges' => '__MULTI__/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeSettlement/ram:SpecifiedTradeAllowanceCharge',

			// ── Referenced documents ──────────────────────────────────────────────
			'invoiceRefDocs' => '__MULTI__/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeSettlement/ram:InvoiceReferencedDocument',
			'orderReference' => '/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeAgreement/ram:BuyerOrderReferencedDocument/ram:IssuerAssignedID',
			'contractReference' => '/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeAgreement/ram:ContractReferencedDocument/ram:IssuerAssignedID',
			'despatchAdviceRef' => '/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeDelivery/ram:DespatchAdviceReferencedDocument/ram:IssuerAssignedID',

			// ── Tax breakdown (multi-value) ────────────────────────────────────────
			'taxBreakdown' => '__MULTI__/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeSettlement/ram:ApplicableTradeTax',
		];

		$this->lineTemplate = [

			'lineid' => './ram:AssociatedDocumentLineDocument/ram:LineID',
			// BT-X-7 / BT-X-8, the EXTENDED status of the line. Absent from the EN 16931 profile, where
			// every line is a regular one; on EXTENDED CTC-FR the reason code tells a real invoice line
			// (DETAIL) from a line that only carries text or a subtotal, and the French rules treat the
			// two differently - see isDetailLine().
			'linestatuscode' => './ram:AssociatedDocumentLineDocument/ram:LineStatusCode',
			'linestatusreasoncode' => './ram:AssociatedDocumentLineDocument/ram:LineStatusReasonCode',
			'lineNote' => './ram:AssociatedDocumentLineDocument/ram:IncludedNote/ram:Content',

			'prodname' => './ram:SpecifiedTradeProduct/ram:Name',
			'proddesc' => './ram:SpecifiedTradeProduct/ram:Description',
			'prodsellerid' => './ram:SpecifiedTradeProduct/ram:SellerAssignedID',
			'prodbuyerid' => './ram:SpecifiedTradeProduct/ram:BuyerAssignedID',
			'prodglobalidtype' => './ram:SpecifiedTradeProduct/ram:GlobalID/@schemeID',
			'prodglobalid' => './ram:SpecifiedTradeProduct/ram:GlobalID',
			'prodmultilangs' => [],
			'prodClassificationCode' => './ram:SpecifiedTradeProduct/ram:DesignatedProductClassification/ram:ClassCode',
			'prodClassificationScheme' => './ram:SpecifiedTradeProduct/ram:DesignatedProductClassification/ram:ClassCode/@listID',
			'prodOriginCountry' => './ram:SpecifiedTradeProduct/ram:OriginTradeCountry/ram:ID',

			'grosspriceamount' => './ram:SpecifiedLineTradeAgreement/ram:GrossPriceProductTradePrice/ram:ChargeAmount',
			'grosspricebasisquantity' => './ram:SpecifiedLineTradeAgreement/ram:GrossPriceProductTradePrice/ram:BasisQuantity',
			'grosspricebasisquantityunitcode' => './ram:SpecifiedLineTradeAgreement/ram:GrossPriceProductTradePrice/ram:BasisQuantity/@unitCode',

			'netpriceamount' => './ram:SpecifiedLineTradeAgreement/ram:NetPriceProductTradePrice/ram:ChargeAmount',
			'netpricebasisquantity' => './ram:SpecifiedLineTradeAgreement/ram:NetPriceProductTradePrice/ram:BasisQuantity',
			'netpricebasisquantityunitcode' => './ram:SpecifiedLineTradeAgreement/ram:NetPriceProductTradePrice/ram:BasisQuantity/@unitCode',

			'billedquantity' => './ram:SpecifiedLineTradeDelivery/ram:BilledQuantity',
			'billedquantityunitcode' => './ram:SpecifiedLineTradeDelivery/ram:BilledQuantity/@unitCode',
			'chargeFreeQuantity' => './ram:SpecifiedLineTradeDelivery/ram:ChargeFreeQuantity',
			'chargeFreeQuantityunitcode' => './ram:SpecifiedLineTradeDelivery/ram:ChargeFreeQuantity/@unitCode',
			'packageQuantity' => './ram:SpecifiedLineTradeDelivery/ram:PackageQuantity',
			'packageQuantityunitcode' => './ram:SpecifiedLineTradeDelivery/ram:PackageQuantity/@unitCode',

			'lineTotalAmount' => './ram:SpecifiedLineTradeSettlement/ram:SpecifiedTradeSettlementLineMonetarySummation/ram:LineTotalAmount',
			'totalAllowanceChargeAmount' => './ram:SpecifiedLineTradeSettlement/ram:SpecifiedTradeSettlementLineMonetarySummation/ram:TotalAllowanceChargeAmount',

			'categoryCode' => './ram:SpecifiedLineTradeSettlement/ram:ApplicableTradeTax/ram:CategoryCode',
			'typeCode' => './ram:SpecifiedLineTradeSettlement/ram:ApplicableTradeTax/ram:TypeCode',
			'rateApplicablePercent' => './ram:SpecifiedLineTradeSettlement/ram:ApplicableTradeTax/ram:RateApplicablePercent',
			'calculatedAmount' => './ram:SpecifiedLineTradeSettlement/ram:ApplicableTradeTax/ram:CalculatedAmount',

			'ExemptionReason' => './ram:SpecifiedLineTradeSettlement/ram:ApplicableTradeTax/ram:ExemptionReason',
			'ExemptionReasonCode' => './ram:SpecifiedLineTradeSettlement/ram:ApplicableTradeTax/ram:ExemptionReasonCode',

			// ── line-level allowances & charges ────────────────────────────────
			'lineAllowances' => '__MULTI__./ram:SpecifiedLineTradeSettlement/ram:SpecifiedTradeAllowanceCharge',
			'lineGrossPriceAllowances' => '__MULTI__./ram:SpecifiedLineTradeAgreement/ram:GrossPriceProductTradePrice/ram:AppliedTradeAllowanceCharge',
			'lineremisepercent' => 'NA',

			'linePeriodStart' => './ram:SpecifiedLineTradeSettlement/ram:BillingSpecifiedPeriod/ram:StartDateTime/udt:DateTimeString',
			'linePeriodEnd' => './ram:SpecifiedLineTradeSettlement/ram:BillingSpecifiedPeriod/ram:EndDateTime/udt:DateTimeString',

			'additionalRefDocs' => '__MULTI__./ram:SpecifiedLineTradeSettlement/ram:AdditionalReferencedDocument',

			'isDepositLine' => false,
			'depositInvoiceRef' => 'NA',
			'depositInvoiceDate' => 'NA',

			'parentDocumentNo' => null,
			'is_deposit' => 0,
			'fk_remise' => null,
		];
	}


	/**
	 * Generate the XML content for a given invoice according to the CII standard.
	 * This also make a lot of check
	 *
	 * This method converts the provided invoice data into a structured XML file
	 * compliant with the CII specification.
	 *
	 * @param 	CommonInvoice	$invoice 		Invoice object containing all necessary data.
	 * @param	?Translate		$outputlangs	Output language
	 * @return 	string 							XML representation of the invoice.
	 */
	public function generateXML($invoice, $outputlangs = null)
	{
		global $conf, $user, $langs, $mysoc, $db;	// Used by the include


		// Call page to generate the invoice variables ($invoiceData, ...)
		include dol_buildpath('einvoicing/lib/buildinvoicelines.inc.php');
		/**
		 * From include:
		 * @var Facture 			$object			The `$invoice` object used in entry on inc file, but completed.
		 * @var array{
		 *   documentno: string,
		 *   documenttypecode: null|string,
		 *   documentdate: DateTimeInterface,
		 *   invoiceCurrency: string|list<string>,
		 *   taxCurrency: null,
		 *   documentname: null,
		 *   documentlanguage: string,
		 *   effectiveSpecifiedPeriod: 'NA',
		 *   documentDeliveryDate: DateTimeInterface,
		 *   invoicingPeriodStart: ?DateTimeInterface,
		 *   invoicingPeriodEnd: ?DateTimeInterface,
		 *   businessProcessId: string,
		 *   isTestDocument: bool,
		 *   documentNotePublic: string,
		 *   documentNotePMT: string,
		 *   documentNotePMD: string,
		 *   documentNoteAAB: string,
		 *   documentNoteTXD: string,
		 *   vatDueDateTypeCode: string,
		 *   documentNotes: array,
		 *   sellername: string,
		 *   sellerids: string,
		 *   sellerlineone: string,
		 *   sellerlinetwo: string,
		 *   sellerlinethree: string,
		 *   sellerpostcode: string,
		 *   sellercity: string,
		 *   sellercountry: string,
		 *   sellersubdivision: null,
		 *   sellercontactpersonname: string,
		 *   sellercontactdepartmentname: null,
		 *   sellercontactphoneno: string,
		 *   sellercontactfaxno: string,
		 *   sellercontactemailaddr: string,
		 *   sellerCommunicationUriScheme: string,
		 *   sellerCommunicationUri: string,
		 *   sellerGlobalIds: list<array{schemeID: string, value: string}>,
		 *   sellerTaxRegistrations: list<array{type: string, value: string}>,
		 *   sellervatnumber: string,
		 *   sellerLegalOrgId: string,
		 *   sellerLegalOrgScheme: string,
		 *   sellerTradingName: string,
		 *   buyername: string,
		 *   buyerids: string,
		 *   buyerlineone: string,
		 *   buyerlinetwo: string,
		 *   buyerlinethree: string,
		 *   buyerpostcode: string,
		 *   buyercity: string,
		 *   buyercountry: string,
		 *   buyersubdivision: null,
		 *   buyervatnumber: string,
		 *   buyerGlobalIds: list<array{schemeID: string, value: string}>,
		 *   buyerRoutingCode: null|string,
		 *   buyerLegalOrgId: string,
		 *   buyerLegalOrgScheme: string,
		 *   buyerTradingName: string,
		 *   buyerReference: null|string,
		 *   buyerCommunicationUriScheme: string,
		 *   buyerCommunicationUri: string,
		 *   buyercontactpersonname: null,
		 *   buyercontactemailaddr: null,
		 *   buyercontactphoneno: null,
		 *   grandTotalAmount: float|int,
		 *   duePayableAmount: float|int,
		 *   lineTotalAmount: float|int,
		 *   chargeTotalAmount: float,
		 *   allowanceTotalAmount: float|int,
		 *   taxBasisTotalAmount: float|int,
		 *   taxTotalAmount: float|int,
		 *   roundingAmount: null,
		 *   totalPrepaidAmount: float|int,
		 *   iban_id: int,
		 *   iban: string,
		 *   bic: string,
		 *   accountName: string,
		 *   accountRef: string,
		 *   accountLabel: string,
		 *   paymentDueDate: DateTimeInterface,
		 *   paymentTermsText: string,
		 *   headerAllowancesCharges: array,
		 *   invoiceRefDocs: array|list<array{ref: string|int, date: \DateTimeInterface, type: string}>,
		 *   orderReference: string,
		 *   contractReference: null|string,
		 *   despatchAdviceRef: null,
		 *   taxBreakdown: array|list<array<string, array>>,
		 *   _chorus: bool,
		 *   _depositlines: array|list<array{lineId: int, invoiceRef: string, invoiceDate: DateTimeInterface}>,
		 *   _globalDiscounts: array|list<array{value: float, reason: string, taxRate: float, categoryVAT: string}>,
		 *   _customerOrderReferenceList: string[],
		 *   _project: \Project|null,
		 *   paymentMeansCode?: int,
		 *   paymentMeansText?: string,
		 *   _shipFromContactBill?: array{address: null|string, zip: null|string, town: null|string, country: string},
		 *   _shipFromContactShip?: array{name: string, address: null|string, zip: null|string, town: null|string, country: string}
		 * }	$invoiceData
		 * @var array<int, array{
		 *   lineid: int,
		 *   linestatuscode: null|string,
		 *   linestatusreasoncode: null|string,
		 *   lineNote: null,
		 *   prodname: string,
		 *   proddesc: string,
		 *   prodsellerid: string,
		 *   prodbuyerid: null|string,
		 *   prodglobalidtype: null|string,
		 *   prodglobalid: null|string,
		 *   prodmultilangs: array,
		 *   prodClassificationCode: null|string,
		 *   prodClassificationScheme: null|string,
		 *   prodOriginCountry: null|string,
		 *   netpriceamount: float,
		 *   netpricebasisquantity: null|float,
		 *   netpricebasisquantityunitcode: null|string,
		 *   billedquantity: float,
		 *   billedquantityunitcode: string,
		 *   chargeFreeQuantity: null|float,
		 *   chargeFreeQuantityunitcode: null|string,
		 *   packageQuantity: null|float,
		 *   packageQuantityunitcode: null|string,
		 *   lineTotalAmount: float|string,
		 *   totalAllowanceChargeAmount: null|float,
		 *   categoryCode: string,
		 *   typeCode: 'VAT',
		 *   rateApplicablePercent: string,
		 *   tva_tx: float|string,
		 *   vat_src_code: string,
		 *   ExemptionReason: string,
		 *   ExemptionReasonCode: string,
		 *   calculatedAmount: null|float,
		 *   lineAllowances: array,
		 *   lineGrossPriceAllowances: array,
		 *   lineremisepercent: 'NA'|float,
		 *   linePeriodStart: ?\DateTimeInterface,
		 *   linePeriodEnd: ?\DateTimeInterface,
		 *   additionalRefDocs: array,
		 *   isDepositLine: bool,
		 *   depositInvoiceRef: null|string,
		 *   depositInvoiceDate: ?DateTimeInterface,
		 *   parentDocumentNo: null|string,
		 *   is_deposit: int<0,1>,
		 *   fk_remise: null|int,
		 *   discountPercent: float,
		 *   grosspriceamount: null|float,
		 *   grosspricebasisquantity: null|float,
		 *   grosspricebasisquantityunitcode: null|string
		 * }> $linesData
		 * @var string 				$outputlang		Value of $outputlangs->defaultlang
		 * @var Account				$account
		 * @var EInvoicing			$einvoicing
		 * @var string 				$schemeUri		Buyer scheme uri
		 * @var string 				$uri			Buyer uri
		 */
		'
		@phan-var-force Facture 			$object			The $invoice object used in entry on inc file, but completed.
		@phan-var-force array{documentno:string,documenttypecode:null|string,documentdate:DateTimeInterface,invoiceCurrency:string|array<string>,taxCurrency:null,documentname:null,documentlanguage:string,effectiveSpecifiedPeriod:\'NA\',documentDeliveryDate:DateTimeInterface,invoicingPeriodStart:?DateTimeInterface,invoicingPeriodEnd:?DateTimeInterface,businessProcessId:string,isTestDocument:bool,documentNotePublic:string,documentNotePMT:string,documentNotePMD:string,documentNoteAAB:string,documentNoteTXD:string,documentNotes:array,vatDueDateTypeCode:string,sellername:string,sellerids:string,sellerlineone:string,sellerlinetwo:string,sellerlinethree:string,sellerpostcode:string,sellercity:string,sellercountry:string,sellersubdivision:null,sellercontactpersonname:string,sellercontactdepartmentname:null,sellercontactphoneno:string,sellercontactfaxno:string,sellercontactemailaddr:string,sellerCommunicationUriScheme:string,sellerCommunicationUri:string,sellerGlobalIds:array<array{schemeID:string,value:string}>,sellerTaxRegistrations:array<array{type:string,value:string}>,sellervatnumber:string,sellerLegalOrgId:string,sellerLegalOrgScheme:string,sellerTradingName:string,buyername:string,buyerids:string,buyerlineone:string,buyerlinetwo:string,buyerlinethree:string,buyerpostcode:string,buyercity:string,buyercountry:string,buyersubdivision:null,buyervatnumber:string,buyerGlobalIds:array<array{schemeID:string,value:string}>,buyerRoutingCode:null|string,buyerLegalOrgId:string,buyerLegalOrgScheme:string,buyerTradingName:string,buyerReference:null|string,buyerCommunicationUriScheme:string,buyerCommunicationUri:string,buyercontactpersonname:null,buyercontactemailaddr:null,buyercontactphoneno:null,grandTotalAmount:float|int,duePayableAmount:float|int,lineTotalAmount:float|int,chargeTotalAmount:float,allowanceTotalAmount:float|int,taxBasisTotalAmount:float|int,taxTotalAmount:float|int,roundingAmount:null,totalPrepaidAmount:float|int,iban_id:int,iban:string,bic:string,accountName:string,accountRef:string,accountLabel:string,paymentDueDate:DateTimeInterface,paymentTermsText:string,headerAllowancesCharges:array,invoiceRefDocs:array|array<array{ref:string|int,date:DateTimeInterface,type:string}>,orderReference:string,contractReference:null|string,despatchAdviceRef:null,taxBreakdown:array|array<array<string,array>>,_chorus:bool,_depositlines:array|array<array{lineId:int,invoiceRef:string,invoiceDate:DateTimeInterface}>,_globalDiscounts:array|array<array{value:float,reason:string,taxRate:float,categoryVAT:string}>,_customerOrderReferenceList:string[],_project:Project|null,paymentMeansCode?:int,paymentMeansText?:string,_shipFromContactBill?:array{address:null|string,zip:null|string,town:null|string,country:string},_shipFromContactShip?:array{name:string,address:null|string,zip:null|string,town:null|string,country:string}} $invoiceData
		@phan-var-force array<int,array{lineid:int,linestatuscode:null|string,linestatusreasoncode:null|string,lineNote:null,prodname:string,proddesc:string,prodsellerid:string,prodbuyerid:null|string,prodglobalidtype:null|string,prodglobalid:null|string,prodmultilangs:array,prodClassificationCode:null|string,prodClassificationScheme:null|string,prodOriginCountry:null|string,netpriceamount:float,netpricebasisquantity:null|float,netpricebasisquantityunitcode:null|string,billedquantity:float,billedquantityunitcode:string,chargeFreeQuantity:null|float,chargeFreeQuantityunitcode:null|string,packageQuantity:null|float,packageQuantityunitcode:null|string,lineTotalAmount:float|string,totalAllowanceChargeAmount:null|float,categoryCode:string,typeCode:\'VAT\',rateApplicablePercent:string,tva_tx:float|string,vat_src_code:string,ExemptionReason:string,ExemptionReasonCode:string,calculatedAmount:null|float,lineAllowances:array,lineGrossPriceAllowances:array,lineremisepercent:\'NA\'|float,linePeriodStart:?DateTimeInterface,linePeriodEnd:?DateTimeInterface,additionalRefDocs:array,isDepositLine:bool,depositInvoiceRef:null|string,depositInvoiceDate:?DateTimeInterface,parentDocumentNo:null|string,is_deposit:int<0,1>,fk_remise:null|int,discountPercent:float,grosspriceamount:null|float,grosspricebasisquantity:null|float,grosspricebasisquantityunitcode:null|string}> $linesData
		@phan-var-force string 				$outputlang		Value of $outputlangs->defaultlang
		@phan-var-force Account				$account
		@phan-var-force EInvoicing			$einvoicing
		@phan-var-force string 				$schemeUri		Buyer scheme uri
		@phan-var-force string 				$uri			Buyer uri
		';

		// Generate the XML file
		$filename = dol_sanitizeFileName($invoice->ref);
		$filedir = getMultidirOutputCompat($invoice, '', 1, 'temp');    // Example '/mydolibarr/documents/facture/temp/FAYYMM-XXXX'
		$xmlfile = $filedir . '/' . $filename . '/' .  static::GENERATED_INVOICE_XML_FILE_NAME;

		dol_mkdir(dirname($xmlfile));
		dol_delete_file($xmlfile);

		$xmlcontent = $this->buildXML($invoiceData, $linesData, $this->getBuildXmlProfile(), $outputlangs);

		// Local EN 16931 business rules safety net, and check that the document claims the amount the
		// invoice claims (warnings, or abort in strict mode)
		$this->checkBusinessRules($xmlcontent, $invoice);

		file_put_contents($xmlfile, $xmlcontent);

		dolChmod($xmlfile);

		return $xmlfile;
	}


	/**
	 * Generate a complete CII invoice file.
	 * This function generates the einvoice file.
	 *
	 * @param 	int|Object 	$invoice_id    	Invoice ID or Invoice Object to be processed.
	 * @param	?Translate	$outputlangs	Output language
	 * @param	string		$sourceFilePath	Source document path (unused: CII output is a standalone XML, independent of the visual PDF). Kept for a uniform protocol signature.
	 * @return 	-1|string       			-1 if ko, path if ok.
	 */
	public function generateInvoice($invoice_id, $outputlangs = null, $sourceFilePath = '')
	{
		// Global variables declaration (typical for Dolibarr environment)
		global $langs, $db;

		dol_syslog(get_class($this) . '::generateInvoice');

		if (empty($outputlangs) || ! ($outputlangs instanceof Translate)) {
			$outputlangs = $langs;
		}

		require_once DOL_DOCUMENT_ROOT . "/compta/facture/class/facture.class.php";
		require_once DOL_DOCUMENT_ROOT . '/core/lib/pdf.lib.php';
		require_once DOL_DOCUMENT_ROOT . '/core/lib/files.lib.php';

		if ($invoice_id instanceof Facture) {
			$invoice = $invoice_id;
			$invoice_id = $invoice->id;
		} else {
			$invoice = new Facture($db);
			$invoiceResult = $invoice->fetch((int) $invoice_id);

			if ($invoiceResult < 0) {
				dol_syslog(get_class($this) . "::generateInvoice failed to load invoice id=" . $invoice_id, LOG_ERR);
				$this->error = $langs->trans("ErrorLoadingInvoice");
				$this->errors[] = $this->error;
				return -1;
			}
		}

		// Generate XML
		try {
			$xmlfile = $this->generateXML($invoice, $outputlangs);
		} catch (Exception $e) {
			dol_syslog(get_class($this) . "::generateInvoice failed to generate XML for invoice id=" . $invoice_id . ". Error " . $e->getMessage(), LOG_ERR);
			$this->error = $langs->trans("ErrorGeneratingXML") . '.<br>' . $e->getMessage();
			$this->errors[] = $this->error;
			return -1;
		}

		if (empty($xmlfile) || !file_exists($xmlfile)) {
			dol_syslog(get_class($this) . "::generateInvoice failed to generate XML for invoice id=" . $invoice_id, LOG_ERR);
			$this->error = $langs->trans("ErrorGeneratingXML");
			$this->errors[] = $this->error;
			return -1;
		}


		// Load EInvoicing specific translations
		$langs->loadLangs(array("admin", "einvoicing@einvoicing"));
		// Make a copy of the XML file into the final destination
		$filename = dol_sanitizeFileName($invoice->ref);
		$filedir = getMultidirOutputCompat($invoice, '', 1);      // Example '/mydolibarr/documents/facture/FAYYMM-XXXX'
		$einvoice_path = $filedir . '/' . $filename . '_cii.' . self::INVOICE_FILE_EXTENSION;

		if (dol_copy($xmlfile, $einvoice_path) > 0) {
			dol_syslog(get_class($this) . "::generateInvoice copied XML file to " . $einvoice_path);
		} else {
			dol_syslog(get_class($this) . "::generateInvoice failed to copy XML file to " . $einvoice_path, LOG_ERR);
			$langs->load("errors");
			$this->error = $langs->trans("ErrorFailToCopyFile", $xmlfile, $einvoice_path);
			$this->errors[] = $this->error;
			return -1;
		}


		// Clean up the temporary XML file
		if (file_exists($xmlfile) && !getDolGlobalString('EINVOICING_DEBUG_MODE')) {
			dol_delete_file($xmlfile);
			dol_syslog(get_class($this) . '::generateInvoice cleaned up temporary XML file: ' . $xmlfile);
		}

		// Add afterEinvoiceCreation hook
		global $action, $hookmanager;
		$hookmanager->initHooks(array('einvoicegeneration'));
		$parameters = array('protocol' => 'cii', 'file' => $einvoice_path, 'object' => $invoice, 'outputlangs' => $langs);
		$reshook = $hookmanager->executeHooks('afterEinvoiceCreation', $parameters, $this, $action); // Note that $action and $object may have been modified by some hooks
		if ($reshook < 0) {
			$this->error = $hookmanager->error;
			$this->errors = $hookmanager->errors;
			return -1;
		}

		// Set status of einvoice
		$einvoicing = new EInvoicing($db);
		$result = $einvoicing->fetchLastknownInvoiceStatus($invoice->id, $invoice->ref);

		if (
			isset($result['code']) &&
			(in_array($result['code'], array($einvoicing::STATUS_UNKNOWN, $einvoicing::STATUS_NOT_GENERATED))
				|| !array_key_exists($result['code'], $einvoicing::STATUS_LABEL_KEYS))
		) {
			// Set status to e-einvoice generated
			$einvoicing->setEInvoiceStatus($invoice, $einvoicing::STATUS_GENERATED, 'Invoice status set to Generated by generateInvoice()');
		}

		// Warn if the generated file exceeds the configured size limit
		$this->checkFileSizeLimit($einvoice_path);

		return $einvoice_path;		// Name of generated Einvoice
	}



	/**
	 * Generate a sample CII invoice for demonstration or testing purposes (for Dolibarr version < 24.0)
	 *
	 * This method creates a dummy invoice with representative data
	 * to illustrate the CII structure without using real business information.
	 *
	 * @param	EInvoicing			$einvoicing			EInvoicing
	 * @param   Societe|null			$thirdpartySeller		Optional third party object to use for generating the sample invoice. If null, a dummy third party will be created.
	 * @param   Societe|null			$thirdpartyBuyer		Optional third party object to use for generating the sample invoice. If null, a dummy third party will be created.
	 * @param   array<string,mixed>		$options				More options
	 * @return 	array<string,string> 							Path or content of the generated sample invoice.
	 * @throws  Exception
	 */
	public function generateSampleInvoiceOld($einvoicing, $thirdpartySeller = null, $thirdpartyBuyer = null, $options = array())
	{
		// For CII protocol, the old sample method now use the new one.
		return $this->generateSampleInvoice($einvoicing, $thirdpartySeller, $thirdpartyBuyer, $options);
	}


	/**
	 * Create a supplier invoice from the e-invoice file and attach the file (and readable file if exists) to the document.
	 * This may create the Supplier and the Product depending on setup.
	 *
	 * @param  string 			$file                       		Source string file (XML or PDF string). We use this file to get data of supplier invoice.
	 * @param  string|null 		$readableViewFile        			Readable view file (PDP Generated readable PDF). We only store it if available.
	 * @param  string 			$flowId                       		Flow identifier source of the invoice.
	 * @return array{res:int<-1,1>, message:string, actioncode?: string|null, actionurl?: string|null, action?:string|null}   Returns array with 'res' (1 on success, 0 already exists, -1 on failure) with a 'message' and an optional 'actioncode' and 'action'.
	 */
	public function createSupplierInvoiceFromSource($file, $readableViewFile = null, $flowId = '')
	{
		global $conf, $db;

		// Only the transactions opened by doCreateSupplierInvoiceFromSource() are ours to close,
		// never one owned by a caller: it counts them in $this->openedTransactions.
		$this->openedTransactions = 0;

		$tempDir = $conf->einvoicing->dir_temp;
		if (!dol_is_dir($tempDir)) {
			dol_mkdir($tempDir);
		}

		// Use a unique per-call working file so two concurrent syncs cannot overwrite each other and
		// parse the wrong invoice (#226). The fixed einvoice.[pdf|xml] file slot is only the downloadable
		// "last invoice that could not be processed" diagnostic, managed in cleanupIncomingTempFiles().
		$uid = bin2hex(random_bytes(8));
		$tempFile = $tempDir . '/in_' . $uid . '.' . static::INVOICE_FILE_EXTENSION;
		$tempFileReadableView = $tempDir . '/in_' . $uid . '_readable.pdf';

		$result = ['res' => -1, 'message' => 'Unexpected error while creating supplier invoice'];
		try {
			$result = $this->doCreateSupplierInvoiceFromSource($file, $readableViewFile, $flowId, $tempFile, $tempFileReadableView);
		} finally {
			$failed = !is_array($result) || !isset($result['res']) || $result['res'] < 0;
			$this->cleanupIncomingTempFiles($tempDir, $tempFile, $tempFileReadableView, $failed);

			// The invoice import transaction is opened by doCreateSupplierInvoiceFromSource() once the
			// vendor has been synchronized. Close it here so every early return - and any exception -
			// leaves a clean transaction state. A transaction left open would be rolled back by
			// Dolibarr at the end of the request, taking the imported invoices AND the synchronization
			// history down with it (issue #524).
			// One commit/rollback per opened level: nested levels only decrement the counter of the
			// database handler, the real COMMIT/ROLLBACK is issued on the last one.
			while ($this->openedTransactions > 0) {
				$this->openedTransactions--;
				if ($failed) {
					$db->rollback();
				} else {
					$db->commit();
				}
			}
		}

		return $result;
	}

	/**
	 * Create/insert supplier invoice lines in DB using $lines property of the supplier invoice object
	 * @param FactureFournisseur $supplierInvoice The supplier invoice to add lines
	 * @return bool  True if success
	 */
	public function createSupplierInvoiceLinesIntoDatabase(FactureFournisseur $supplierInvoice): bool
	{
		foreach ($supplierInvoice->lines as $i => $val) {
			$sql = 'INSERT INTO '.MAIN_DB_PREFIX.'facture_fourn_det (fk_facture_fourn, special_code, fk_remise_except)';
			/** @phan-suppress-next-line PhanUndeclaredProperty */
			$sql .= " VALUES (".((int) $supplierInvoice->id).", ".((int) $supplierInvoice->lines[$i]->special_code).", ".($supplierInvoice->lines[$i]->fk_remise_except > 0 ? ((int) $supplierInvoice->lines[$i]->fk_remise_except) : 'NULL').')';

			$resql_insert = $this->db->query($sql);
			if ($resql_insert) {
				$idligne = $this->db->last_insert_id(MAIN_DB_PREFIX.'facture_fourn_det');

				$res = $supplierInvoice->updateline(
					$idligne,
					/** @phan-suppress-next-line PhanDeprecatedProperty */
					$supplierInvoice->lines[$i]->desc ? $supplierInvoice->lines[$i]->desc : $supplierInvoice->lines[$i]->description,
					$supplierInvoice->lines[$i]->subprice,
					(float) ($supplierInvoice->lines[$i]->tva_tx.($supplierInvoice->lines[$i]->vat_src_code ? ' ('.$supplierInvoice->lines[$i]->vat_src_code.')' : '')),
					$supplierInvoice->lines[$i]->localtax1_tx,
					$supplierInvoice->lines[$i]->localtax2_tx,
					$supplierInvoice->lines[$i]->qty,
					$supplierInvoice->lines[$i]->fk_product,
					'HT',
					(!empty($supplierInvoice->lines[$i]->info_bits) ? $supplierInvoice->lines[$i]->info_bits : ''),
					$supplierInvoice->lines[$i]->product_type,
					$supplierInvoice->lines[$i]->remise_percent,
					0,
					/** @phan-suppress-next-line PhanUndeclaredProperty */
					$supplierInvoice->lines[$i]->date_start,
					/** @phan-suppress-next-line PhanUndeclaredProperty */
					$supplierInvoice->lines[$i]->date_end,
					$supplierInvoice->lines[$i]->array_options,
					$supplierInvoice->lines[$i]->fk_unit,
					$supplierInvoice->lines[$i]->multicurrency_subprice,
					/** @phan-suppress-next-line PhanUndeclaredProperty */
					$supplierInvoice->lines[$i]->ref_supplier
				);

				if ($res < 0) {
					return false;
				}
			} else {
				return false;
			}
		}

		return true;
	}

	/**
	 * Build the supplier invoice from a received CII document written to a per-call working file.
	 * The temp-file lifecycle is owned by createSupplierInvoiceFromSource() (the public wrapper).
	 * The vendor synchronization runs in its own transaction, opened and closed here. The invoice
	 * import transaction is opened here too, right after, but closed by that same wrapper.
	 *
	 * @param  string			$file                 Raw CII XML content
	 * @param  string|null		$readableViewFile     Optional readable view (PDP-generated readable PDF)
	 * @param  string			$flowId               Source flow identifier
	 * @param  string			$tempFile             Unique working file for the received XML
	 * @param  string			$tempFileReadableView Unique working file for the readable view
	 * @return array{res:int<-1,1>, message:string, action?:string|null}
	 */
	protected function doCreateSupplierInvoiceFromSource($file, $readableViewFile, $flowId, $tempFile, $tempFileReadableView)
	{
		global $db, $user, $langs;

		$einvoicing = new EInvoicing($db);
		$return_messages = array();

		if (file_put_contents($tempFile, $file) === false) {
			return ['res' => -1, 'message' => 'Failed to save EInvoice file to temporary location'];
		}

		if ($readableViewFile) {
			if (file_put_contents($tempFileReadableView, $readableViewFile) === false) {
				return ['res' => -1, 'message' => 'Failed to save readable view file to temporary location'];
			}
		}

		// --- Create Supplier Invoice object
		require_once DOL_DOCUMENT_ROOT . '/fourn/class/fournisseur.facture.class.php';
		$supplierInvoice = new FactureFournisseur($db);


		// Read using native parser
		$parsedHeader = $this->parseInvoiceHeader($file);
		$parsedLines = $this->parseInvoiceLines($file);

		dol_syslog(get_class($this) . '::doCreateSupplierInvoiceFromSource parsedHeader: ' . json_encode($parsedHeader), LOG_DEBUG);
		dol_syslog(get_class($this) . '::doCreateSupplierInvoiceFromSource parsedHeader: ' . json_encode($parsedHeader), LOG_DEBUG, 0, '_einvoicing');

		// Sync or create supplier based on seller info.
		// Done before the duplicate/ref-docs checks below so those checks can be scoped to this supplier
		// (ref_supplier is only unique per supplier, not globally - see issue about cross-supplier collisions).
		//
		// The vendor is reference data, not part of the invoice: it gets its own transaction, committed
		// before the import starts. A business error raised further down - a product that cannot be
		// auto-created, a referenced document missing - must not roll back the thirdparty the operator is
		// precisely being asked to complete: the "create the product" and "map the product" links returned
		// with that error carry its socid, so a rolled back vendor makes them point to a thirdparty that
		// never existed.
		$db->begin();
		$this->openedTransactions++;

		$syncSocRes = $this->_syncOrCreateThirdpartyFromEInvoiceSeller($parsedHeader, 'dolibarr', $flowId);

		$socId = $syncSocRes['res'];
		$return_messages[] = $syncSocRes['message'];
		if ($socId < 0) {
			$db->rollback();
			$this->openedTransactions--;
			return [
				'res' => -1,
				'message' => "Thirdparty sync or creation error:<br>\n" . implode("<br>\n", $return_messages),
				'actioncode' => $syncSocRes['actioncode'] ?? '',
				'actionurl' => $syncSocRes['actionurl'] ?? '',
				'action' => $syncSocRes['action'] ?? null,
				'actiondata' => $syncSocRes['actiondata'] ?? null
			];
		}

		$db->commit();
		$this->openedTransactions--;

		// From this point on, everything belongs to the invoice import (products, invoice, lines) and
		// stays atomic. This second transaction is closed (commit or rollback) by
		// createSupplierInvoiceFromSource(), the public wrapper.
		$db->begin();
		$this->openedTransactions++;

		// Load supplier (thirdparty)
		require_once DOL_DOCUMENT_ROOT . '/fourn/class/fournisseur.class.php';
		$supplier = new Fournisseur($db);
		if ($supplier->fetch($socId) < 0) {
			return ['res' => -1, 'message' => 'Failed to load supplier id ' . $socId];
		}

		// Check if this invoice has already been imported for this supplier
		$supplierInvoiceId = SupplierInvoiceHelper::findIdByRef($parsedHeader['documentno'] ?? null, (int) $socId, $parsedHeader['grandTotalAmount'] ?? 0);

		if ($supplierInvoiceId == -3) {
			$langs->load("bills");
			$action = $langs->trans('FixTheAmountOrModifySupplierRef', $langs->transnoentitiesnoconv("RefSupplierBill"), $parsedHeader['documentno'] ?? '', $langs->trans("Duplicate"));
			$action .= ' <a class="butAction small smallpaddingimp nomarginleft" href="' . DOL_URL_ROOT.'/fourn/facture/list.php?search_refsupplier='.urlencode($parsedHeader['documentno'] ?? '').'&socid=' . (int) $socId. '" target="_blank">';
			$action .= '<i class="fas fa-plus-circle"></i> ';
			$action .= $langs->trans('ModifySupplierInvoice');
			$action .= '</a>';

			return [
				'res' => -1,
				'message' => SupplierInvoiceHelper::refLookupErrorMessage($supplierInvoiceId, $parsedHeader['documentno'] ?? '', 'while checking whether it was already imported'),
				'actioncode' => 'SUPPLIER_INVOICE_FOUND_WITH_BAD_AMOUNT',
				'actionurl' => 'none',
				'actiondata' => array('supplierref' => $parsedHeader['documentno'], 'socid' => (int) $socId, 'expectedamount' => $parsedHeader['grandTotalAmount'] ?? 0),
				'action' => $action
			];
		}

		if ($supplierInvoiceId < 0) {
			return ['res' => -1, 'message' => SupplierInvoiceHelper::refLookupErrorMessage($supplierInvoiceId, $parsedHeader['documentno'] ?? '', 'while checking whether it was already imported')];
		}

		if ($supplierInvoiceId > 0) {
			$return_messages[] = 'Supplier Invoice with reference ' . $parsedHeader['documentno'] . ' already exists';

			$supplierInvoice = new FactureFournisseur($db);
			$supplierInvoice->fetch($supplierInvoiceId);

			// Supplier invoice already found but may be that documents are downloaded or were removed
			// Save documents in supplier invoice attachments if they do not exists yet.
			if ($tempFile && file_exists($tempFile)) {
				$res = $this->saveEInvoiceFileToSupplierInvoiceAttachment($supplierInvoice, $tempFile);

				if ($res['res'] < 0) {
					$return_messages[] = 'Failed to save Einvoice file as attachment: ' . $res['message'];
				} else {
					$return_messages[] = 'Einvoice file saved as attachment';
				}
			} else {
				dol_syslog("Temporary 'converted pdf file' not found for attachment", LOG_ERR);
			}

			// Save readable view file in supplier invoice attachments
			if ($readableViewFile && $tempFileReadableView && file_exists($tempFileReadableView)) {
				$readablefileext = 'pdf';	// Usually the extension of file for the readable version is PDF
				$res = $this->saveEInvoiceFileToSupplierInvoiceAttachment($supplierInvoice, $tempFileReadableView, getDolGlobalString('EINVOICING_PDP', 'PDP'), $readablefileext);

				if ($res['res'] < 0) {
					$return_messages[] = 'Failed to save readable view file as attachment: ' . $res['message'];
				} else {
					$return_messages[] = 'Readable view file saved as attachment';
				}
			} else {
				dol_syslog("Temporary 'readable pdf file' not found for attachment", LOG_ERR);
			}

			return ['res' => $supplierInvoiceId, 'message' => implode("\n", $return_messages)];
		}

		// Check if all referenced documents in the invoice exist in Dolibarr for the same supplier, if not return with error since we need them for correct linking in the invoice
		if (!empty($parsedHeader['invoiceRefDocs']) && is_array($parsedHeader['invoiceRefDocs'])) {
			foreach ($parsedHeader['invoiceRefDocs'] as $invoiceRefDoc) {
				$refDoc = $invoiceRefDoc['IssuerAssignedID'] ?? null;
				$dateDoc = $invoiceRefDoc['FormattedIssueDateTime'] ?? null;
				$typeDoc = $invoiceRefDoc['TypeCode'] ?? null;

				$refDocInvoiceId = SupplierInvoiceHelper::findIdByRef($refDoc, (int) $socId);
				if ($refDocInvoiceId < 0) {
					return ['res' => -1, 'message' => SupplierInvoiceHelper::refLookupErrorMessage($refDocInvoiceId, $refDoc, 'linked to document ' . ($parsedHeader['documentno'] ?? ''))];
				}
				if ($refDocInvoiceId == 0) {
					// The invoice references a document this Dolibarr does not hold: the final invoice of a
					// deposit, the invoice a credit note credits, the one a replacement replaces. Nothing has
					// been created at this point, so the flow is postponed rather than failed: it is retried
					// on the next synchronization, and the invoices queued behind it keep coming in. What the
					// user has to do cannot be guessed from a technical message, so it is spelled out with a
					// link to the screen where the missing invoice is created.
					$langs->load("bills");
					$action = $langs->trans('CreateTheMissingSupplierInvoiceToImport', $refDoc);
					$action .= ' <a class="butAction small smallpaddingimp nomarginleft" href="' . DOL_URL_ROOT . '/fourn/facture/card.php?action=create&socid=' . (int) $socId . '&ref_supplier=' . urlencode($refDoc) . '" target="_blank">';
					$action .= '<i class="fas fa-plus-circle"></i> ';
					$action .= $langs->trans('NewBill');
					$action .= '</a>';

					return [
						'res' => -1,
						'postponeflow' => 1,
						'message' => 'Document : ' . $refDoc . ' linked to document ' . $parsedHeader['documentno'] . ' not found in Dolibarr',
						'actioncode' => 'LINKED_INVOICE_NOT_FOUND',
						'actionurl' => 'none',
						'actiondata' => array('supplierref' => $refDoc, 'linkedref' => ($parsedHeader['documentno'] ?? ''), 'socid' => (int) $socId),
						'action' => $action,
						'businessmessage' => $langs->trans('CantFindLinkedInvoiceOfTheImportedInvoice', ($parsedHeader['documentno'] ?? ''), $refDoc)
					];
				}
			}
		}

		// Set supplier reference
		$supplierInvoice->socid = $socId;
		$supplierInvoice->ref_supplier = $parsedHeader['documentno'] ?? '';

		// Set basic invoice information (type, date)
		$supplierInvoice->type = $this->getDolibarrInvoiceType($parsedHeader['documenttypecode'] ?? null);
		if ($supplierInvoice->type === '-1') {
			return ['res' => -1, 'message' => 'Unfounded dolibarr corresponding Invoice code for document type code: ' . ($parsedHeader['documenttypecode'] ?? 'NA')];
		}
		// documentdate is already formatted into 'Y-m-d' by the parser ZugFerd and CII
		$supplierInvoice->date = !empty($parsedHeader['documentdate']) ? dol_stringtotime($parsedHeader['documentdate']) : null;

		// For credit notes and replacement invoices, link to the source invoice via fk_facture_source
		// (BT-25). A replacement invoice (BT-3 = 384) corrects the invoice it references just as a credit
		// note cancels it, and Dolibarr stores that source in the same field for both.
		if (in_array($supplierInvoice->type, array(FactureFournisseur::TYPE_CREDIT_NOTE, FactureFournisseur::TYPE_REPLACEMENT)) && !empty($parsedHeader['invoiceRefDocs']) && is_array($parsedHeader['invoiceRefDocs'])) {
			$firstRefDoc = reset($parsedHeader['invoiceRefDocs']);
			$refSourceSupplier = !empty($firstRefDoc['IssuerAssignedID']) ? (string) $firstRefDoc['IssuerAssignedID'] : '';
			if ($refSourceSupplier !== '') {
				$sourceInvoiceId = SupplierInvoiceHelper::findIdByRef($refSourceSupplier, (int) $socId);
				if ($sourceInvoiceId > 0) {
					$supplierInvoice->fk_facture_source = $sourceInvoiceId;
					dol_syslog(get_class($this) . '::doCreateSupplierInvoiceFromSource Linked to source invoice id=' . $supplierInvoice->fk_facture_source, LOG_DEBUG);
				} else {
					// Not found, ambiguous or database error: leave fk_facture_source empty rather than link the wrong invoice
					dol_syslog(get_class($this) . '::doCreateSupplierInvoiceFromSource Source invoice ref_supplier="' . $refSourceSupplier . '" not resolved (code ' . $sourceInvoiceId . ') for ' . ($parsedHeader['documentno'] ?? ''), LOG_WARNING);
				}
			}
		}


		// Set currency
		$supplierInvoice->multicurrency_code = (string) $parsedHeader['invoiceCurrency'];

		// Set import_key
		$supplierInvoice->import_key = AbstractPDPProvider::$EINVOICING_LAST_IMPORT_KEY;

		// Set payment due date, payment terms and payment method
		$paymentInfoRes = $this->_applyPaymentInfoToSupplierInvoice($supplierInvoice, $parsedHeader);
		if (!empty($paymentInfoRes['message'])) {
			dol_syslog(get_class($this) . '::doCreateSupplierInvoiceFromSource ' . $paymentInfoRes['message'], LOG_DEBUG, 0, '_einvoicing');
		}


		$remise_already_used_line_level_ids = array();
		$supplierPriceEntries = array(); // Collect product/price data to create supplier prices after invoice creation

		// Create document level discounts (allowances) as discounts in Dolibarr
		$globalDiscountIds = array();
		if (!empty($parsedHeader['headerAllowancesCharges'])) {
			$headerDiscountIds = $this->createHeaderDiscounts($parsedHeader['headerAllowancesCharges'], $socId, (string) $parsedHeader['documentno']);
			if (!empty($headerDiscountIds[-1])) {
				return ['res' => -1, 'message' => $headerDiscountIds[-1]];
			} else {
				$globalDiscountIds = $headerDiscountIds;
			}
		}

		//return ['res' => 1, 'message' => 'Not implemented yet' ];

		// Set invoice totals
		$supplierInvoice->total_ht = $parsedHeader['taxBasisTotalAmount'] ?? 0;
		$supplierInvoice->total_tva = $parsedHeader['taxTotalAmount'] ?? 0;
		$supplierInvoice->total_ttc = $parsedHeader['grandTotalAmount'] ?? 0;

		// Add a note about PDP import ( TODO: add a hook or extrafields to store import details)
		$supplierInvoice->note_private = "Imported from PDP";

		// TODO : save AAB, PMD, PMT notes (all notes are grouped into documentNotes)

		// Create the invoice
		$supplierInvoiceId = $supplierInvoice->create($user);

		if ($supplierInvoiceId < 0) {
			return ['res' => -1, 'message' => 'Invoice creation error: ' . $supplierInvoice->error];
		} else {
			// Keep the order reference the supplier declared (BT-13) whether or not it matches an
			// order of Dolibarr, so the invoice can be reconciled by hand when it does not. See issue #603.
			$this->_saveImportedBuyerOrderReference($supplierInvoice, $parsedHeader['orderReference'] ?? '');

			// Link the invoice to its purchase order (commande fournisseur) when the order reference
			// (BT-13) matches a single order for the same supplier. Non-blocking. See issue #303.
			$orderLinkMessage = $this->_linkSupplierInvoiceToPurchaseOrder($supplierInvoice, $socId, $parsedHeader['orderReference'] ?? '');
			if ($orderLinkMessage !== '') {
				$return_messages[] = $orderLinkMessage;
			}

			// --------------------------------------------------
			// Create supplier invoice lines
			// --------------------------------------------------

			$res = $this->createSupplierInvoiceLinesFromSource($supplierInvoice, $parsedLines, $remise_already_used_line_level_ids, $supplierPriceEntries, $return_messages, $flowId);
			if ($res['res'] < 0) {
				return $res;  // Return the full result array because it may contain additional information like actioncode, actionurl...
			}

			$create_deposit_line = 0;
			$fk_remise_for_deposit = 0;
			// --------------------------------------------------
			// Loop on linked documents at document level
			// --------------------------------------------------
			if (!empty($parsedHeader['invoiceRefDocs']) && is_array($parsedHeader['invoiceRefDocs'])) {
				foreach ($parsedHeader['invoiceRefDocs'] as $doc) {
					$refDoc = $doc['IssuerAssignedID'] ?? null;
					$dateDoc = $doc['FormattedIssueDateTime'] ?? null;
					$typeDoc = $doc['TypeCode'] ?? null;

					$linkedObjectId = SupplierInvoiceHelper::findIdByRef($refDoc, (int) $socId);
					if ($linkedObjectId < 0) {
						return ['res' => -1, 'message' => SupplierInvoiceHelper::refLookupErrorMessage($linkedObjectId, $refDoc, 'linked to document ' . ($parsedHeader['documentno'] ?? ''))];
					}
					if ($linkedObjectId == 0) {
						return ['res' => -1, 'message' => 'Document : ' . $refDoc . ' linked to document ' . $parsedHeader['documentno'] . ' not found in Dolibarr'];
					}

					// Fetch Object
					$linkedObject = new FactureFournisseur($db);
					$resFetchLinkedObject = $linkedObject->fetch($linkedObjectId);
					if ($resFetchLinkedObject > 0) {
						// --------------------------------------------------
						// Deposit handling
						// --------------------------------------------------
						if ($linkedObject->type == FactureFournisseur::TYPE_DEPOSIT) {
							$create_deposit_line = 1;

							$depositDiscountRes = $this->getOrCreateDepositDiscount($linkedObject);
							if ($depositDiscountRes['res'] < 0) {
								return $depositDiscountRes;
							}
							$fk_remise_for_deposit = $depositDiscountRes['fkRemise'];

							// After creating the discount for the deposit, we create a line in the invoice to link it to the deposit
							if ($create_deposit_line && !empty($fk_remise_for_deposit)) {
								if (!in_array($fk_remise_for_deposit, $remise_already_used_line_level_ids)) { // If the discount for deposit is not already used at line level we link it to the invoice, otherwise it is already linked at line level so we skip to avoid duplicates
									$currentSupplierInvoice = new FactureFournisseur($db);
									$currentSupplierInvoice->fetch($supplierInvoiceId);
									$result = $currentSupplierInvoice->insert_discount($fk_remise_for_deposit);
									if ($result < 0) {
										return ['res' => -1, 'message' => 'Failed to link discount for deposit to supplier invoice: ' . $currentSupplierInvoice->error];
									} else {
										dol_syslog('Deposit line linked to supplier invoice with line id: ' . $result);
									}
								}
							}
						}

						// Other linked document handling can be implemented here based on the type of the linked document for example credit note etc...
					} else {
						return ['res' => -1, 'message' => 'Document : ' . $refDoc . ' linked to document ' . $parsedHeader['documentno'] . ' not found in Dolibarr'];
					}
				}
			}

			// Update thirdparty as a supplier if not already the case
			if ($supplier->fournisseur != 1) {
				$supplier->fournisseur = 1;
				$supplier->code_fournisseur = 'auto';
				$supplier->update($supplier->id, $user);
			}

			// Insert global discounts (allowances) as lines in this supplier invoice
			if (!empty($globalDiscountIds)) {
				foreach ($globalDiscountIds as $fk_remise_except) {
					$currentSupplierInvoice = new FactureFournisseur($db);
					$currentSupplierInvoice->fetch($supplierInvoiceId);
					$result = $currentSupplierInvoice->insert_discount($fk_remise_except);
					if ($result < 0) {
						return ['res' => -1, 'message' => 'Failed to insert global discount into supplier invoice: ' . $currentSupplierInvoice->error];
					} else {
						dol_syslog('Global discount inserted into supplier invoice with line id: ' . $result);
					}
				}
			}

			// Insert document level charges (BG-21) as lines of this supplier invoice
			if (!empty($parsedHeader['headerAllowancesCharges'])) {
				$chargeRes = $this->createHeaderChargeLines($supplierInvoiceId, $parsedHeader['headerAllowancesCharges'], $return_messages);
				if ($chargeRes['res'] < 0) {
					return $chargeRes;
				}
			}

			// Every line of the invoice exists now, so its totals can be confronted with the ones the
			// document announces (issue #781).
			$this->alignInvoiceTotalsWithDocument($supplierInvoiceId, $parsedHeader, $return_messages);

			// Create or update supplier prices for imported products
			if (!empty($supplierPriceEntries)) {
				require_once DOL_DOCUMENT_ROOT . '/fourn/class/fournisseur.product.class.php';
				foreach ($supplierPriceEntries as $entry) {
					$productFourn = new ProductFournisseur($db);
					$productFourn->id = $entry['productId'];
					$result = $productFourn->update_buyprice(
						1,                    // qty min
						$entry['unitPrice'],  // prix unitaire HT
						$user,
						'HT',
						$supplier,
						0,                    // availability
						$entry['refFourn'],   // ref fournisseur
						$entry['tvaTx']
					);
					if ($result < 0) {
						dol_syslog(__METHOD__ . ' Failed to create supplier price for product id=' . $entry['productId'] . ': ' . $productFourn->error, LOG_WARNING);
					} else {
						dol_syslog(__METHOD__ . ' Supplier price created/updated for product id=' . $entry['productId'], LOG_DEBUG);
					}
				}
			}

			// Set import_key
			$sql = 'UPDATE ' . MAIN_DB_PREFIX . "facture_fourn SET import_key = '" . $db->escape($supplierInvoice->import_key) . "'";
			$sql .= " WHERE rowid = " . ((int) $supplierInvoiceId);
			$db->query($sql);

			// Add entry in einvoicing_extlinks table to mark that this supplier invoice is imported from PDP
			$einvoicing->insertOrUpdateExtLink($supplierInvoiceId, $supplierInvoice->element, $flowId);

			dol_syslog(__METHOD__ . ' New supplier invoice created or updated (ID: ' . $supplierInvoiceId . ')');

			$return_messages[] = 'Supplier Invoice created or updated with ID: ' . $supplierInvoiceId;


			// Save original invoice in supplier invoice attachments
			if ($tempFile && file_exists($tempFile)) {
				$res = $this->saveEInvoiceFileToSupplierInvoiceAttachment($supplierInvoice, $tempFile);

				if ($res['res'] < 0) {
					$return_messages[] = 'Failed to save Einvoice file as attachment: ' . $res['message'];
				} else {
					$return_messages[] = 'Einvoice file saved as attachment';
				}
			} else {
				dol_syslog("Temporary 'converted pdf file' not found for attachment", LOG_ERR);
			}


			// Save readable view file in supplier invoice attachments
			if ($readableViewFile && $tempFileReadableView && file_exists($tempFileReadableView)) {
				$readablefileext = 'pdf';	// Usually the extension of file for the readable version is PDF
				$res = $this->saveEInvoiceFileToSupplierInvoiceAttachment($supplierInvoice, $tempFileReadableView, getDolGlobalString('EINVOICING_PDP', 'PDP'), $readablefileext);

				if ($res['res'] < 0) {
					$return_messages[] = 'Failed to save readable view file as attachment: ' . $res['message'];
				} else {
					$return_messages[] = 'Readable view file saved as attachment';
				}
			} else {
				dol_syslog("Temporary 'readable pdf file' not found for attachment", LOG_ERR);
			}

			// TODO : Save receivedFile in supplier invoice attachments
			return ['res' => $supplierInvoiceId, 'message' => implode("\n", $return_messages), 'xml_data' => $file];
		}
	}


	/**
	 * Add lines to a supplier invoice from e-invoice parsed lines
	 *
	 * @param 	FactureFournisseur 	$supplierInvoice						The supplier invoice to add lines on
	 * @param 	array 				$parsedLines							The parsed lines data (previously extracted from e-invoice)
	 * @param 	array 				$remise_already_used_line_level_ids		The list of ids for remise already used
	 * @param 	array 				$supplierPriceEntries					The list of entries for supplier prices
	 * @param 	array 				$return_messages						The list of return messages to complete if necessary
	 * @param 	string 				$flowId									The concerned flowId
	 * @return 	array{res:int,message?:string,actioncode?:string|null,actionurl?:string|null,action?:string|null,actiondata?:string|null}	Returns array with 'res' (1 on success, 0 already exists, -1 on failure) with a 'message' and additional data about the action.
	 */
	public function createSupplierInvoiceLinesFromSource(&$supplierInvoice, $parsedLines, &$remise_already_used_line_level_ids, &$supplierPriceEntries, &$return_messages, $flowId = ''): array
	{
		global $db;

		// Add invoice lines
		foreach ($parsedLines as $parsedLine) {
			// Add supplier ID to line for later use in product sync
			$parsedLine['supplierId'] = $supplierInvoice->socid;

			$is_deposit_line = 0;
			$fk_remise = 0;
			// --------------------------------------------------
			// Loop on linked documents at line level
			// --------------------------------------------------
			if (!empty($parsedLine['additionalRefDocs']) && is_array($parsedLine['additionalRefDocs'])) {
				foreach ($parsedLine['additionalRefDocs'] as $refDoc) {
					$lineRefDocId = $refDoc['IssuerAssignedID'] ?? null;
					$lineRefDocType = $refDoc['typeCode'] ?? null;
					$lineRefDocDate = $refDoc['issueDate'] ?? null;

					$linkedObjectId = SupplierInvoiceHelper::findIdByRef($lineRefDocId, (int) $parsedLine['supplierId']);
					if ($linkedObjectId < 0) {
						return ['res' => -1, 'message' => SupplierInvoiceHelper::refLookupErrorMessage($linkedObjectId, $lineRefDocId, 'linked to line ' . $parsedLine['lineid'])];
					}
					if ($linkedObjectId == 0) {
						return [
							'res' => -1,
							'message' => 'Document "' . $lineRefDocId . '" linked to line ' . $parsedLine['lineid'] . ' was not found in Dolibarr. Please verify why this document is missing (deleted, not imported, or not provided by the supplier). To resolve this issue, you must manually create the invoice using the supplier invoice reference "' . $lineRefDocId . '".'
						];
						// TODO: Add a check before sending a final invoice after deposit to ensure that the deposit invoice has been properly sent to the PDP and successfully received.
					}

					// Load linked supplier invoice
					$linkedObject = new FactureFournisseur($db);
					$resFetchLinkedObject = $linkedObject->fetch($linkedObjectId);
					if ($resFetchLinkedObject > 0) {
						/*
						 * --------------------------------------------------
						 * Deposit handling
						 * --------------------------------------------------
						 * Deposits may be referenced:
						 *  - at document level
						 *  - at line level
						 *
						 * If the deposit is referenced at line level:
						 *   → we create the discount before creating the invoice line,
						 *     so it can be linked later.
						 *
						 * If the same deposit appears both at line and document level:
						 *    line-level handling takes priority to avoid duplicates.
						 *
						 * If the deposit exists only at document level:
						 *   → a discount line will be created later after all invoice
						 *     lines are generated.
						 */
						if ($linkedObject->type == FactureFournisseur::TYPE_DEPOSIT) {
							$is_deposit_line = 1;

							$depositDiscountRes = $this->getOrCreateDepositDiscount($linkedObject);
							if ($depositDiscountRes['res'] < 0) {
								return $depositDiscountRes;
							}
							$fk_remise = $depositDiscountRes['fkRemise'];
						}

						/*
						 * --------------------------------------------------
						 * Other linked document types
						 * --------------------------------------------------
						 * Additional logic may be added here for other
						 * document types such as credit notes, etc.
						 */
					} else {
						return ['res' => -1, 'message' => 'Document : ' . $lineRefDocId . ' linked to line ' . $parsedLine['lineid'] . ' not found in Dolibarr'];
					}
				}
			}

			$productId = 0;
			$productMatchType = '';
			if (!$is_deposit_line) {
				// Sync or create product
				$res = $this->_findOrCreateProductFromEinvoiceLine($parsedLine, $flowId);

				$return_messages[] = $res['message'];
				if ($res['res'] < 0) {
					return [
						'res' => -1,
						'message' => 'Product sync or creation error: ' . implode("<br>\n", $return_messages),
						'actioncode' => $res['actioncode'] ?? '',
						'actionurl' => $res['actionurl'] ?? '',
						'action' => $res['action'] ?? null,
						'actiondata' => $res['actiondata'] ?? ''
					];
				}
				$productId = $res['res'];
				$productMatchType = (string) ($res['matchtype'] ?? '');

				// Collect supplier price data to be created after invoice is saved.
				// Not for a default routing product: it is a catch-all, so gluing the vendor reference of
				// the line onto it would make every next invoice match it instead of the real product.
				if ($productId > 0 && $productMatchType != 'defaultrouting' && !empty($parsedLine['prodsellerid'])) {
					$supplierPriceEntries[] = [
						'productId' => $productId,
						'unitPrice' => (float) $parsedLine['netpriceamount'],
						'refFourn'  => (string) $parsedLine['prodsellerid'],
						'tvaTx'     => (float) ($parsedLine['rateApplicablePercent'] ?? 0),
					];
				}
			}


			// Add line to invoice
			$line = new SupplierInvoiceLine($db);
			// Supplier's product reference (BT-155 SellerAssignedID) -> shown as "Ref. fournisseur" on the line.
			if (!empty($parsedLine['prodsellerid'])) {
				$line->ref_supplier = trim($parsedLine['prodsellerid']);
			}
			if (!empty($productId)) {
				$line->fk_product = $productId;
				if ($productMatchType == 'defaultrouting') {
					// The default routing product is a catch-all shared by all the unresolved lines of the
					// vendor: without the wording of the XML, every line of the invoice would show the same
					// label. Keep the description of the vendor on top of the product link.
					$line->desc = trim($parsedLine['prodname'] ?? '') . (!empty($parsedLine['proddesc']) ? "\n" . trim($parsedLine['proddesc']) : '');
				} elseif (!empty($parsedLine['proddesc'])) {
					// Keep the XML line-level description even when a real product is linked:
					// it is often project-specific and not carried by the product's own label/description.
					$line->desc = trim($parsedLine['proddesc']);
				}
				// Because we reuse an already existing product without changing its ref and label, we add the label from the supplier invoice into the description
				if (!empty($parsedLine['prodname'])) {
					$line->desc = dol_concatdesc($parsedLine['prodname'], $line->desc ?? '');
				}
			} elseif (!$is_deposit_line) {
				// Free line: no product linked, description set from XML data
				$line->desc = trim($parsedLine['prodname'] ?? '') . (!empty($parsedLine['proddesc']) ? "\n" . trim($parsedLine['proddesc']) : '');
			}
			if ($is_deposit_line && !empty($fk_remise)) {
				$line->fk_remise_except = $fk_remise;
				$line->info_bits = 2;
				$line->desc = '(DEPOSIT)';
				$line->rang = -1;

				$remise_already_used_line_level_ids[] = $fk_remise;
			}
			// handle line-level discount if exists and update amounts
			if (!empty($parsedLine['lineAllowances'])) {
				$discount = $this->resolveLineDiscountPercent($parsedLine['lineAllowances'], $parsedLine['lineTotalAmount']);
				if ($discount !== false) {
					$line->remise_percent = $discount['percent'];
					if (!empty($parsedLine['billedquantity'])) {
						$line->subprice = round($discount['priceWithoutDiscount'] / $parsedLine['billedquantity'], 8);
					} else {
						// Avoid a fatal DivisionByZeroError on a zero/empty billed quantity (e.g. a free
						// sample line): keep the discount percent, let subprice fall back to netpriceamount below.
						dol_syslog(get_class($this) . '::doCreateSupplierInvoiceFromSource line ' . ($parsedLine['lineid'] ?? '?') . ' has a discount but billedquantity is zero/empty, skipping subprice adjustment', LOG_WARNING);
					}
				}
			}
			$line->qty = (float) $parsedLine['billedquantity'];
			$line->subprice = $line->subprice ?? (float) $parsedLine['netpriceamount'];
			$line->tva_tx = (float) $parsedLine['rateApplicablePercent'];
			$line->total_ht = (float) $parsedLine['lineTotalAmount'];
			$line->total_tva = $parsedLine['calculatedAmount'] ?? 0;
			$line->total_ttc = $parsedLine['lineTotalAmount'] + ($parsedLine['calculatedAmount'] ?? 0);

			// The three properties just set are not what reaches the database: the line is written by
			// createSupplierInvoiceLinesIntoDatabase(), which hands quantity, unit price and discount to
			// FactureFournisseur::updateline() and lets the core recompute the totals. So BT-131 is read
			// and then dropped, and whatever quantity times price gives is what the invoice ends up with.
			// A deposit line is left alone: its amount does not come from the document but from the
			// discount created out of the deposit invoice.
			if (!$is_deposit_line) {
				$amounts = $this->resolveLineAmounts($parsedLine, (float) $line->qty, (float) $line->subprice, (float) $line->remise_percent);
				$line->qty = $amounts['qty'];
				$line->subprice = $amounts['subprice'];
				$line->remise_percent = $amounts['remise_percent'];
				if ($amounts['warning'] !== '') {
					$return_messages[] = $amounts['warning'];
					dol_syslog(get_class($this) . '::createSupplierInvoiceLinesFromSource ' . $amounts['warning'], LOG_WARNING);
				}
			}

			// Billing period of the line (BT-134 / BT-135). The two dates were read from the document and
			// then went nowhere: createSupplierInvoiceLinesIntoDatabase() has always handed
			// $line->date_start / ->date_end to updateline(), but nothing ever set them, so a service line
			// billed over a period arrived without one (issue #576). See resolveLinePeriod().
			$linePeriod = $this->resolveLinePeriod($parsedLine);
			if ($linePeriod['start'] !== null) {
				$line->date_start = $linePeriod['start'];
			}
			if ($linePeriod['end'] !== null) {
				$line->date_end = $linePeriod['end'];
			}

			$supplierInvoice->lines[] = $line;

			// An invoice line charge (BG-28) is part of BT-131 but has no room in a Dolibarr line, which
			// holds a quantity, a unit price and a discount and nothing else. It follows its line, the way
			// it would be keyed in by hand, so the unit price stored stays the one the document gives
			// (BT-146) and the two together are worth what BT-131 announces. See issue #735.
			foreach ($this->buildLineChargeLines($parsedLine) as $chargeLine) {
				$supplierInvoice->lines[] = $chargeLine;
			}
		}

		if (!$this->createSupplierInvoiceLinesIntoDatabase($supplierInvoice)) {
			return [
				'res' => -1,
				'message' => 'Supplier invoice line creation error',
				'actioncode' => $res['actioncode'] ?? '',
				'actionurl' => $res['actionurl'] ?? '',
				'action' => $res['action'] ?? null,
				'actiondata' => $res['actiondata'] ?? ''
			];
		}

		return ['res' => 1];
	}


	/* =====================================================================================
	 XML parsing methods
	======================================================================================== */
	/**
	 * Initialise DOMDocument + DOMXPath with the three CII namespaces.
	 *
	 * @param string $xml XML string to parse
	 * @return array{0:\DOMDocument, 1:\DOMXPath}
	 */
	private function initXPath($xml)
	{
		$doc = new \DOMDocument();
		$doc->loadXML($xml);

		$xpath = new \DOMXPath($doc);
		$xpath->registerNamespace('rsm', 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100');
		$xpath->registerNamespace('ram', 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100');
		$xpath->registerNamespace('udt', 'urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100');
		// qdt is used by some date paths (e.g. ram:FormattedIssueDateTime/qdt:DateTimeString); without it those xpath queries return empty.
		$xpath->registerNamespace('qdt', 'urn:un:unece:uncefact:data:standard:QualifiedDataType:100');

		return [$doc, $xpath];
	}

	/**
	 * Extract a single scalar value from an XPath expression.
	 *
	 * Supports attribute extraction: expressions ending with /@attrName.
	 *
	 * @param \DOMXPath    $xpath 			XPath
	 * @param string       $expr         	XPath expression or 'NA'
	 * @param \DOMNode|null $contextNode	Optional context node for relative XPath queries
	 * @return string|null
	 */
	private function getXPathValue($xpath, $expr, $contextNode = null)
	{
		if ($expr === 'NA' || empty($expr))
			return null;

		$nodes = $xpath->query($expr, $contextNode);
		if (!$nodes || $nodes->length === 0)
			return null;

		$node = $nodes->item(0);
		$value = trim($node->nodeValue);
		return $value !== '' ? $value : null;
	}

	/**
	 * Extract all matching nodes as an array of their text values.
	 *
	 * @param \DOMXPath			$xpath			XPath
	 * @param string			$expr			XPath expression or 'NA'
	 * @param \DOMNode|null		$contextNode	Optional context node for relative XPath queries
	 * @return string[]
	 */
	private function getXPathValues($xpath, $expr, $contextNode = null)
	{
		if ($expr === 'NA' || empty($expr))
			return [];

		$nodes = $xpath->query($expr, $contextNode);
		$result = [];
		if ($nodes) {
			foreach ($nodes as $node) {
				$v = trim($node->nodeValue);
				if ($v !== '')
					$result[] = $v;
			}
		}
		return $result;
	}

	/**
	 * Extract attribute-keyed pairs from repeating elements.
	 *
	 * Example: ram:GlobalID[@schemeID="0225"] → ['0225' => '000000002']
	 * Example: ram:SpecifiedTaxRegistration/ram:ID → ['VA' => 'FR12345']
	 *
	 * @param \DOMXPath    $xpath 			XPath
	 * @param string       $expr         	XPath pointing to the element (not the attribute)
	 * @param string       $attrName     	Name of the attribute used as key (default: 'schemeID')
	 * @param \DOMNode|null $contextNode	Optional context node for relative XPath queries
	 * @return array<string,string>
	 */
	private function getXPathAttrPairs($xpath, $expr, $attrName = 'schemeID', $contextNode = null)
	{
		if ($expr === 'NA' || empty($expr))
			return [];

		$nodes = $xpath->query($expr, $contextNode);
		$result = [];
		if ($nodes) {
			foreach ($nodes as $node) {
				$key = $node->getAttribute($attrName);
				$value = trim($node->nodeValue);
				if ($value !== '') {
					$result[$key !== '' ? $key : count($result)] = $value;
				}
			}
		}
		return $result;
	}

	/**
	 * Normalise any CII date string to YYYY-MM-DD.
	 *
	 * Accepts:
	 *   - YYYYMMDD  	=> 2025-06-30
	 *   - YYYY-MM-DD 	=> 2025-06-30
	 *   - YYYYMMDDHHmm => 2025-06-30  (date part only)
	 *
	 * @param  string|null 	$raw	Raw date string
	 * @return string|null  YYYY-MM-DD or null if input is null/empty/unparsable
	 */
	private function normDate($raw)
	{
		if ($raw === null || trim($raw) === '')
			return null;
		$raw = trim($raw);

		// YYYY-MM-DD — already the target format
		if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $raw, $m)) {
			return $m[1] . '-' . $m[2] . '-' . $m[3];
		}

		// YYYYMMDD or YYYYMMDDHHmm — extract date part then format
		if (preg_match('/^(\d{4})(\d{2})(\d{2})/', $raw, $m)) {
			return $m[1] . '-' . $m[2] . '-' . $m[3];
		}

		return $raw; // unknown format — pass through unchanged
	}

	/**
	 * Cast a string amount to float, or null if empty / not numeric.
	 *
	 *  @param string|null $v Input string, e.g. "1234.56" or "1 234,56"
	 *  @return float|null Parsed float or null
	 */
	private function toFloat($v)
	{
		if ($v === null || $v === '')
			return null;
		$v = str_replace(',', '.', trim($v));
		return is_numeric($v) ? (float) $v : null;
	}

	/**
	 * Decide the quantity, unit price and discount a received line must carry in Dolibarr.
	 *
	 * The line is written by createSupplierInvoiceLinesIntoDatabase(), which hands those three to
	 * FactureFournisseur::updateline() and lets the core recompute the totals of the line. The net amount
	 * the document announces for the line, BT-131, is therefore never stored as such: whatever quantity
	 * times unit price gives is what the invoice ends up with. Three cases have to be told apart.
	 *
	 * 1. A line that is not a regular item - EXTENDED CTC-FR, BT-X-8 other than DETAIL: a comment, a group
	 *    header, a subtotal. BR-FREXT-CO-10 sums BT-106 over the DETAIL lines only, so such a line carries
	 *    no amount of its own and importing it as a priced line would count its amount a second time. It
	 *    becomes a text line. BR-FREXT-BR-22 is also why it may carry no quantity at all.
	 * 2. A regular item whose quantity and unit price cannot express the amount it announces at all. Three
	 *    shapes reach that state, and the repair is the same for the three: carry BT-131 as a single unit,
	 *    the way it would be keyed in by hand, so the total of the invoice stays the total of the document.
	 *    a. The invoiced quantity is zero or absent. Such a document is not necessarily wrong: BR-22 only
	 *       tests the presence of ram:BilledQuantity, so a quantity of zero satisfies it, and nothing in
	 *       EN 16931 requires BT-131 to equal BT-129 times BT-146. The document of issue #726 is exactly
	 *       that - EXTENDED CTC-FR, a line carrying <BilledQuantity unitCode="C62">0.0000</BilledQuantity>,
	 *       no BT-X-8, and a BT-131 of 12.00 that BR-FREXT-CO-10 does count into BT-106.
	 *    b. The unit price is zero or absent while the line announces an amount. A free item that is then
	 *       credited is written that way - issue #772, a vendor invoice whose "free" lines each announce a
	 *       BT-131 of -0.30 over a BT-146 of 0.00. Quantity times price rebuilds 0.00, so every one of
	 *       those credits used to be dropped and the invoice came out above the document.
	 *    c. Quantity times price rebuilds the opposite sign of what the line announces. BT-146 is forbidden
	 *       to be negative by BR-27, so a line that subtracts from the invoice carries its sign on BT-129
	 *       alone; a document that puts it on BT-131 only would otherwise be imported as a charge, moving
	 *       the invoice by twice the amount of the line.
	 * 3. Everything else: check that what the core is about to compute is what the document announces, and
	 *    say so when it is not. The tolerance is the one BR-FREXT-CO-10 applies to the totals.
	 *
	 * @param	array<string,mixed>		$parsedLine			One line as parseInvoiceLines() returns it
	 * @param	float					$qty				Quantity read from the document (BT-129)
	 * @param	float					$subprice			Unit price the caller resolved (BT-146, discount applied)
	 * @param	float					$remisePercent		Discount percent the caller resolved
	 * @return	array{qty:float,subprice:float,remise_percent:float,warning:string}	What to store, and what to report about it
	 */
	protected function resolveLineAmounts(array $parsedLine, $qty, $subprice, $remisePercent)
	{
		$lineid = (string) ($parsedLine['lineid'] ?? '?');
		$announced = round((float) ($parsedLine['lineTotalAmount'] ?? 0), 2);

		if (!$this->isDetailLine($parsedLine)) {
			return array('qty' => 0.0, 'subprice' => 0.0, 'remise_percent' => 0.0, 'warning' => '');
		}

		$rebuilt = round($qty * $subprice * (1 - ($remisePercent / 100)), 2);

		// The couple (quantity, unit price) cannot carry the announced amount: it rebuilds nothing, or it
		// rebuilds it upside down. A line announcing nothing is left alone - a free sample or a heading is
		// not an anomaly.
		if (!empty($announced) && ($rebuilt == 0.0 || (($rebuilt > 0) !== ($announced > 0)))) {
			if (empty($qty)) {
				$reason = 'its invoiced quantity (BT-129) is zero or absent, so quantity times unit price rebuilds 0.00';
			} elseif (empty($subprice)) {
				$reason = 'its unit price (BT-146) is zero or absent, so quantity times unit price rebuilds 0.00';
			} elseif ($rebuilt == 0.0) {
				$reason = 'its quantity (BT-129), unit price (BT-146) and discount rebuild 0.00';
			} else {
				$reason = 'its quantity (BT-129) and unit price (BT-146) rebuild ' . $rebuilt . ', of the opposite sign';
			}

			$warning = 'Line ' . $lineid . ' of the received document carries a net amount (BT-131) of ' . $announced
				. ' while ' . $reason . '. It was imported as a single unit at that amount, so the total of the invoice matches the document.';

			return array('qty' => 1.0, 'subprice' => $announced, 'remise_percent' => 0.0, 'warning' => $warning);
		}

		$warning = '';
		if (abs($rebuilt - $announced) > 0.01) {
			$warning = 'Line ' . $lineid . ' of the received document announces a net amount (BT-131) of ' . $announced
				. ', but its quantity and unit price rebuild ' . $rebuilt . '. The invoice carries the rebuilt amount.';
		}

		return array('qty' => $qty, 'subprice' => $subprice, 'remise_percent' => $remisePercent, 'warning' => $warning);
	}


	/**
	 * Tell whether a parsed line is a regular invoice item, the only kind that carries an amount.
	 *
	 * EN 16931 has one sort of line and every one of them is priced. The EXTENDED profile adds a subtype,
	 * BT-X-8, carried by ram:AssociatedDocumentLineDocument/ram:LineStatusReasonCode, and the French rules
	 * hang two behaviours on it:
	 *
	 * - BR-FREXT-BR-22 requires the invoiced quantity (BT-129) only when the subtype is DETAIL or absent,
	 *   so a comment or a group header may legitimately carry no quantity at all;
	 * - BR-FREXT-CO-10 sums BT-131 into BT-106 over those same lines only:
	 *   [not(ram:AssociatedDocumentLineDocument/ram:LineStatusReasonCode)
	 *    or ram:AssociatedDocumentLineDocument/ram:LineStatusReasonCode = 'DETAIL'].
	 *
	 * The predicate below is that XPath. An absent code means a regular item, which is also what every
	 * EN 16931 document gives, so nothing changes for the profiles that have no subtype.
	 *
	 * @param	array<string,mixed>		$parsedLine		One line as parseInvoiceLines() returns it
	 * @return	bool									True for a regular item, false for a line that carries no amount
	 */
	protected function isDetailLine(array $parsedLine)
	{
		$reason = trim((string) ($parsedLine['linestatusreasoncode'] ?? ''));

		return $reason === '' || strtoupper($reason) === 'DETAIL';
	}


	/**
	 * Billing period of a received line (BG-26 / BT-134 / BT-135), as the timestamps a Dolibarr line holds.
	 *
	 * parseInvoiceLines() hands the two dates as 'Y-m-d' strings, normDate() having already reduced whatever the
	 * document carried to that shape, and a supplier invoice line stores a timestamp: updateline() passes
	 * date_start / date_end to idate() on every supported core. dol_stringtotime() is the conversion the
	 * import already makes for the invoice date itself, so the line periods are read the same way rather
	 * than through a second convention (issue #576).
	 *
	 * One side alone is kept: BR-CO-20 accepts a period with a start date or an end date, "or both", and
	 * facture_fourn_det holds one without the other.
	 *
	 * A period that ends before it starts is dropped, keeping the line. Such a document breaks BR-30 and
	 * should not exist, but it comes from outside, and updateline() answers -1 on that pair
	 * (ErrorStartDateGreaterEnd) - which would fail the whole import over a period, where dropping it
	 * imports the invoice as it always did.
	 *
	 * @param	array<string,mixed>				$parsedLine		One line as parseInvoiceLines() returns it
	 * @return	array{start: ?int, end: ?int}					Timestamps to store, null for a side with nothing
	 */
	private function resolveLinePeriod(array $parsedLine)
	{
		$start = !empty($parsedLine['linePeriodStart']) ? dol_stringtotime((string) $parsedLine['linePeriodStart']) : null;
		$end = !empty($parsedLine['linePeriodEnd']) ? dol_stringtotime((string) $parsedLine['linePeriodEnd']) : null;

		// dol_stringtotime() answers false or '' on something it cannot read, and that must not reach idate().
		$start = is_int($start) && $start > 0 ? $start : null;
		$end = is_int($end) && $end > 0 ? $end : null;

		if ($start !== null && $end !== null && $start > $end) {
			dol_syslog(get_class($this) . '::resolveLinePeriod line ' . ($parsedLine['lineid'] ?? '?')
				. ' declares a period from ' . dol_print_date($start, 'day') . ' to ' . dol_print_date($end, 'day')
				. ', which BR-30 refuses; the line is imported without its period', LOG_WARNING);

			return array('start' => null, 'end' => null);
		}

		return array('start' => $start, 'end' => $end);
	}


	/**
	 * Parse the invoice header from CII XML.
	 *
	 * Special prefixes in $invoiceTemplate:
	 *   '__MULTI__<xpath>'     → returns array of child node data
	 *   '__ATTRPAIRS__<xpath>' → returns ['schemeID' => 'value', …]
	 *
	 * @param  string $rawContent Raw XML content
	 * @return array<string,float|string|array>
	 */
	public function parseInvoiceHeader(string $rawContent)
	{
		list(, $xpath) = $this->initXPath($rawContent);

		$data = [];

		foreach ($this->invoiceTemplate as $key => $expr) {
			// Skip PHP-native placeholders
			if (is_array($expr) || $expr === false || $expr === null) {
				$data[$key] = is_array($expr) ? [] : $expr;
				continue;
			}

			// Multi-value nodes
			if (strpos($expr, '__MULTI__') === 0) {
				$realExpr = substr($expr, strlen('__MULTI__'));
				$data[$key] = $this->parseMultiNodes($xpath, $realExpr, $key);
				continue;
			}

			// Attribute-keyed pairs
			if (strpos($expr, '__ATTRPAIRS__') === 0) {
				$realExpr = substr($expr, strlen('__ATTRPAIRS__'));
				$data[$key] = $this->getXPathAttrPairs($xpath, $realExpr);
				continue;
			}

			// Scalar values (including /@attr)
			$data[$key] = $this->getXPathValue($xpath, $expr);
		}

		// Type normalisation
		foreach (['documentdate', 'documentDeliveryDate', 'invoicingPeriodStart', 'invoicingPeriodEnd', 'paymentDueDate'] as $f) {
			if (isset($data[$f]))
				$data[$f] = $this->normDate($data[$f]);
		}
		foreach (['grandTotalAmount', 'duePayableAmount', 'lineTotalAmount', 'chargeTotalAmount', 'allowanceTotalAmount', 'taxBasisTotalAmount', 'taxTotalAmount', 'roundingAmount', 'totalPrepaidAmount'] as $f) {
			if (isset($data[$f]))
				$data[$f] = $this->toFloat($data[$f]);
		}

		return $data;
	}

	/**
	 * Parse all invoice line items from CII XML.
	 *
	 * @param  string $rawContent Raw file content
	 * @return array<int,array<string,null|bool|float|string|array<mixed>>>
	 */
	public function parseInvoiceLines(string $rawContent)
	{
		// $rawContent is XML content
		list(, $xpath) = $this->initXPath($rawContent);

		// Grab header documentno once so we can fill parentDocumentNo on each line
		$parentDocNo = $this->getXPathValue(
			$xpath,
			'/rsm:CrossIndustryInvoice/rsm:ExchangedDocument/ram:ID'
		);

		$lines = [];
		$nodes = $xpath->query('//ram:IncludedSupplyChainTradeLineItem');

		foreach ($nodes as $node) {
			$line = [];

			foreach ($this->lineTemplate as $key => $expr) {
				// PHP-native placeholders
				if (is_array($expr) || $expr === false) {
					$line[$key] = is_array($expr) ? [] : $expr;
					continue;
				}
				if ($key === 'parentDocumentNo') {
					$line[$key] = $parentDocNo;
					continue;
				}
				if ($key === 'is_deposit') {
					$line[$key] = 0;
					continue;
				}
				if ($key === 'fk_remise') {
					$line[$key] = null;
					continue;
				}

				// Multi-value at line level
				if (is_string($expr) && strpos($expr, '__MULTI__') === 0) {
					$realExpr = substr($expr, strlen('__MULTI__'));
					$line[$key] = $this->parseMultiNodes($xpath, $realExpr, $key, $node);
					continue;
				}

				$line[$key] = $this->getXPathValue($xpath, $expr, $node);
			}

			// Type normalisation
			foreach (['linePeriodStart', 'linePeriodEnd'] as $f) {
				if (isset($line[$f]))
					$line[$f] = $this->normDate($line[$f]);
			}
			foreach (['grosspriceamount', 'grosspricebasisquantity', 'netpriceamount', 'netpricebasisquantity', 'billedquantity', 'chargeFreeQuantity', 'packageQuantity', 'lineTotalAmount', 'totalAllowanceChargeAmount', 'rateApplicablePercent', 'calculatedAmount'] as $f) {
				if (isset($line[$f]))
					$line[$f] = $this->toFloat($line[$f]);
			}
			$line['isDepositLine'] = (bool) ($line['isDepositLine'] ?? false);

			$lines[] = $line;
		}

		return $lines;
	}

	/**
	 * Generic parser for repeated container nodes (notes, tax breakdown,
	 * allowances/charges, referenced documents, line additionalRefDocs).
	 *
	 * @param \DOMXPath     $xpath			XPath
	 * @param string        $expr       	XPath pointing to the repeated element
	 * @param string        $fieldKey   	Original template key — used to pick child fields
	 * @param \DOMNode|null $contextNode	Optional context node for relative XPath queries
	 * @return array<int,array<string,mixed>>
	 */
	private function parseMultiNodes($xpath, $expr, $fieldKey, $contextNode = null)
	{
		$nodes = $xpath->query($expr, $contextNode);
		if (!$nodes || $nodes->length === 0)
			return [];

		$result = [];

		foreach ($nodes as $n) {
			switch ($fieldKey) {
				case 'documentNotes':
					$result[] = [
						'content' => trim($this->getXPathValue($xpath, 'ram:Content', $n) ?? ''),
						'subjectCode' => trim($this->getXPathValue($xpath, 'ram:SubjectCode', $n) ?? ''),
					];
					break;

				case 'taxBreakdown':
					$result[] = [
						'typeCode' => $this->getXPathValue($xpath, 'ram:TypeCode', $n),
						'categoryCode' => $this->getXPathValue($xpath, 'ram:CategoryCode', $n),
						'rateApplicablePercent' => $this->toFloat($this->getXPathValue($xpath, 'ram:RateApplicablePercent', $n)),
						'calculatedAmount' => $this->toFloat($this->getXPathValue($xpath, 'ram:CalculatedAmount', $n)),
						'basisAmount' => $this->toFloat($this->getXPathValue($xpath, 'ram:BasisAmount', $n)),
						'ExemptionReason' => $this->getXPathValue($xpath, 'ram:ExemptionReason', $n),
						'ExemptionReasonCode' => $this->getXPathValue($xpath, 'ram:ExemptionReasonCode', $n),
					];
					break;

				case 'headerAllowancesCharges':
					$result[] = [
						'indicator' => $this->getXPathValue($xpath, 'ram:ChargeIndicator/udt:Indicator', $n),
						'reasonCode' => $this->getXPathValue($xpath, 'ram:ReasonCode', $n),
						'reason' => $this->getXPathValue($xpath, 'ram:Reason', $n),
						'calculationPercent' => $this->toFloat($this->getXPathValue($xpath, 'ram:CalculationPercent', $n)),
						'basisAmount' => $this->toFloat($this->getXPathValue($xpath, 'ram:BasisAmount', $n)),
						'actualAmount' => $this->toFloat($this->getXPathValue($xpath, 'ram:ActualAmount', $n)),
						'categoryCode' => $this->getXPathValue($xpath, 'ram:CategoryTradeTax/ram:CategoryCode', $n),
						'rateApplicablePercent' => $this->toFloat($this->getXPathValue($xpath, 'ram:CategoryTradeTax/ram:RateApplicablePercent', $n)),
					];
					break;

				case 'lineAllowances':
					$result[] = [
						'indicator'          => $this->getXPathValue($xpath, 'ram:ChargeIndicator/udt:Indicator', $n),
						'reasonCode'         => $this->getXPathValue($xpath, 'ram:ReasonCode', $n),
						'reason'             => $this->getXPathValue($xpath, 'ram:Reason', $n),
						'calculationPercent' => $this->toFloat($this->getXPathValue($xpath, 'ram:CalculationPercent', $n)),
						'basisAmount'        => $this->toFloat($this->getXPathValue($xpath, 'ram:BasisAmount', $n)),
						'actualAmount'       => $this->toFloat($this->getXPathValue($xpath, 'ram:ActualAmount', $n)),
					];
					break;

				case 'lineGrossPriceAllowances':
					$result[] = [
						'indicator'          => $this->getXPathValue($xpath, 'ram:ChargeIndicator/udt:Indicator', $n),
						'reasonCode'         => $this->getXPathValue($xpath, 'ram:ReasonCode', $n),
						'reason'             => $this->getXPathValue($xpath, 'ram:Reason', $n),
						'calculationPercent' => $this->toFloat($this->getXPathValue($xpath, 'ram:CalculationPercent', $n)),
						'basisAmount'        => $this->toFloat($this->getXPathValue($xpath, 'ram:BasisAmount', $n)),
						'actualAmount'       => $this->toFloat($this->getXPathValue($xpath, 'ram:ActualAmount', $n)),
					];
					break;

				case 'invoiceRefDocs':
					$result[] = [
						'IssuerAssignedID' => $this->getXPathValue($xpath, 'ram:IssuerAssignedID', $n),
						'issueDate' => $this->normDate($this->getXPathValue($xpath, 'ram:FormattedIssueDateTime/qdt:DateTimeString', $n)
							?? $this->getXPathValue($xpath, 'ram:IssueDateTime/udt:DateTimeString', $n)),
					];
					break;

				case 'additionalRefDocs':
					$result[] = [
						'IssuerAssignedID' => $this->getXPathValue($xpath, 'ram:IssuerAssignedID', $n),
						'typeCode' => $this->getXPathValue($xpath, 'ram:TypeCode', $n),
						'name' => $this->getXPathValue($xpath, 'ram:Name', $n),
						'referenceTypeCode' => $this->getXPathValue($xpath, 'ram:ReferenceTypeCode', $n),
						'uriid' => $this->getXPathValue($xpath, 'ram:URIID', $n),
					];
					break;

				default:
					// Generic: grab all child element text nodes
					$entry = [];
					foreach ($n->childNodes as $child) {
						if ($child->nodeType === XML_ELEMENT_NODE) {
							$localName = $child->localName;
							$entry[$localName] = trim($child->nodeValue);
						}
					}
					$result[] = $entry;
			}
		}

		return $result;
	}



	// =====================================================================
	// XML GENERATION
	// =====================================================================

	/**
	 * Profile actually used to build the XML.
	 *
	 * Defaults to the protocol's own BUILD_XML_PROFILE (EN16931 for CII, EXTENDED for Factur-X) and
	 * can be overridden with EINVOICING_XML_PROFILE, so the module can be switched to the
	 * EXTENDED-CTC-FR profile of the French mandate without editing the code. An unknown value is
	 * logged and ignored rather than aborting the generation.
	 *
	 * @return 	string 		Profile name, uppercased
	 */
	protected function getBuildXmlProfile()
	{
		$configured = getDolGlobalString('EINVOICING_XML_PROFILE');
		if ($configured === '') {
			return static::BUILD_XML_PROFILE;
		}

		$configured = strtoupper(trim($configured));
		if (!in_array($configured, self::SUPPORTED_XML_PROFILES, true)) {
			dol_syslog(get_class($this).'::getBuildXmlProfile unknown EINVOICING_XML_PROFILE "'.$configured.'", falling back to '.static::BUILD_XML_PROFILE, LOG_WARNING);
			return static::BUILD_XML_PROFILE;
		}

		return $configured;
	}

	/**
	 * Tell whether a profile allows the EXTENDED-level fields (BT-X-* extensions).
	 *
	 * EXTENDED-CTC-FR is a conformant extension of EN16931 built on top of the Factur-X EXTENDED
	 * profile, so everything EXTENDED allows is allowed there too.
	 *
	 * @param 	string 	$profile 	Profile name, uppercased
	 * @return 	bool 				True if EXTENDED-level fields may be emitted
	 */
	protected function isExtendedProfile(string $profile)
	{
		return in_array($profile, ['EXTENDED', 'EXTENDEDFR'], true);
	}

	/**
	 * Tell whether a profile is one of the header-only Factur-X profiles.
	 *
	 * MINIMUM and BASIC WL ("WL" being "without lines") do not merely make some groups optional:
	 * the elements are absent from their XSD altogether, so emitting one makes the whole document
	 * fail schema validation. Checked against the schemas shipped by FNFE-MPE (France_RFE,
	 * Factur-X/<profile>/1xsd/), the ones this module builds and that BASIC and above declare are:
	 *   - ram:IncludedSupplyChainTradeLineItem 				(BG-25, the invoice lines)
	 *   - ram:DefinedTradeContact 								(BG-6 / BG-9, the party contacts)
	 *   - ram:Information 									(BT-82, payment means label)
	 *   - ram:AccountName 									(BT-85, payment account holder)
	 *   - ram:PayeeSpecifiedCreditorFinancialInstitution 	(BT-86, the BIC)
	 * ram:IBANID and ram:ProprietaryID (BT-84) do exist there and stay.
	 *
	 * @param 	string 	$profile 	Profile name, uppercased
	 * @return 	bool 				True if only the header-level subset may be emitted
	 */
	protected function isHeaderOnlyProfile(string $profile)
	{
		return in_array($profile, ['MINIMUM', 'BASICWL'], true);
	}

	/**
	 * Tell whether a profile carries the whole EN 16931 core.
	 *
	 * MINIMUM, BASIC WL and BASIC are subsets: a term of the core may simply not exist in their
	 * XSD, and emitting it makes the document fail schema validation. EN16931 declares the core in
	 * full, and EXTENDED / EXTENDED-CTC-FR are conformant extensions of it. Checked against the
	 * Factur-X schemas (vendor/horstoeko/zugferd/src/schema/FACTUR-X_<profile>*.xsd): for instance
	 * ram:SpecifiedProcuringProject (BT-11) appears from EN16931 on and nowhere below.
	 *
	 * @param 	string 	$profile 	Profile name, uppercased
	 * @return 	bool 				True if the full EN 16931 core may be emitted
	 */
	protected function isEn16931Profile(string $profile)
	{
		return in_array($profile, ['EN16931', 'EXTENDED', 'EXTENDEDFR'], true);
	}

	/**
	 * Tell whether a profile is MINIMUM, whose schema declares almost nothing.
	 *
	 * MINIMUM is not a reduced EN 16931: it is a different, far smaller document, and every type it
	 * declares is cut down. Emitting anything else fails schema validation. What it allows, in full
	 * (FACTUR-X_MINIMUM_*ReusableAggregateBusinessInformationEntity_100.xsd):
	 *   ExchangedDocument                   ID, TypeCode, IssueDateTime          (no ram:IncludedNote)
	 *   HeaderTradeAgreement                BuyerReference, Seller, Buyer, BuyerOrderReferencedDocument
	 *   TradeParty                          Name, SpecifiedLegalOrganization, PostalTradeAddress,
	 *                                       SpecifiedTaxRegistration            (no ID/GlobalID, no URI)
	 *   LegalOrganization                   ID                                  (no TradingBusinessName)
	 *   TradeAddress                        CountryID                           (nothing else)
	 *   HeaderTradeDelivery                 nothing at all, the type is empty
	 *   HeaderTradeSettlement               InvoiceCurrencyCode, SpecifiedTradeSettlementHeaderMonetarySummation
	 *                                       (no payment means, no ApplicableTradeTax, no payment terms)
	 *   MonetarySummation                   TaxBasisTotalAmount, TaxTotalAmount, GrandTotalAmount,
	 *                                       DuePayableAmount                    (no line/charge/allowance/prepaid)
	 *
	 * @param 	string 	$profile 	Profile name, uppercased
	 * @return 	bool 				True if only the MINIMUM subset may be emitted
	 */
	protected function isMinimumProfile(string $profile)
	{
		return $profile === 'MINIMUM';
	}

	/**
	 * Build CII XML from invoice data.
	 *
	 * Every value taken from the invoice or the company data is passed through htmlspecialchars()
	 * before it is handed to DOMDocument::createElement(): that method parses its second argument,
	 * so a value holding an ampersand ("A & B", a very ordinary company name) raises an
	 * "unterminated entity reference" warning and produces an EMPTY element, silently losing the
	 * business term - issue #695. Only literals of this file and values coming out of
	 * number_format() or DateTime::format() may be written unescaped.
	 *
	 * @param array 		$invoiceData 	Header-level invoice data (generated by the buildinvoicelines.inc.php)
	 * @param array 		$linesData 		Array of line-level data arrays (generated by the buildinvoicelines.inc.php)
	 * @param ''|'MINIMUM'|'BASICWL'|'BASIC'|'EN16931'|'EXTENDED' 	$profile	Profile ('MINIMUM', 'BASICWL', 'BASIC', 'EN16931', 'EXTENDED')
	 *
	 * @return string Generated XML content
	 */
	public function buildXML(array $invoiceData, array $linesData, $profile = '')
	{
		global $langs;

		$doc = new \DOMDocument('1.0', 'UTF-8');
		$doc->preserveWhiteSpace = true; // Keep spaces and line feed
		$doc->formatOutput = true;

		// Root
		$root = $doc->createElementNS(
			'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100',
			'rsm:CrossIndustryInvoice'
		);
		$root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:qdt', 'urn:un:unece:uncefact:data:standard:QualifiedDataType:100');
		$root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:ram', 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100');
		$root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xs', 'http://www.w3.org/2001/XMLSchema');
		$root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:udt', 'urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100');
		$root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
		$doc->appendChild($root);

		// Add comment
		// getHashUniqueIdOfRegistration() lives in the blockedlog library, which only the paths going through
		// the PDF builder happen to include (pdf.lib.php). Load it here as well, otherwise the very same invoice
		// is stamped with the instance hash or not depending on the button that triggered the generation.
		// The library ships with every supported version, the function itself only from Dolibarr 23, so the
		// function_exists() below still decides: nothing is stamped under 23, exactly as before.
		if (!function_exists('getHashUniqueIdOfRegistration')) {
			include_once DOL_DOCUMENT_ROOT.'/blockedlog/lib/blockedlog.lib.php';
		}
		$hash_unique_id = '';
		if (function_exists('getHashUniqueIdOfRegistration')) {
			$algo = 'sha256';
			$hash_unique_id = getHashUniqueIdOfRegistration($algo);
		}

		$textforcomment = 'Einvoice XML generated by';
		$textforcomment .= ' Dolibarr ' . DOL_VERSION;
		$textforcomment .= ' Einvoicing ' . einvoicingModuleStamp();
		$textforcomment .= ' - ' . dol_print_date(dol_now('gmt'), 'dayhourrfc', 'gmt');
		$textforcomment .= ($hash_unique_id ? ' - Instance public hash '.$hash_unique_id : '');
		$comment = $doc->createComment($textforcomment);
		$root->appendChild($comment);

		// Context
		$ctx = $doc->createElement('rsm:ExchangedDocumentContext');
		$root->appendChild($ctx);

		// BusinessProcessId
		if (!empty($invoiceData['businessProcessId'])) {
			$bp = $doc->createElement('ram:BusinessProcessSpecifiedDocumentContextParameter');
			$ctx->appendChild($bp);
			$bp->appendChild($doc->createElement('ram:ID', htmlspecialchars((string) $invoiceData['businessProcessId'])));
		}

		$profile = !empty($profile) ? strtoupper($profile) : 'EXTENDED';

		$guideline = $doc->createElement('ram:GuidelineSpecifiedDocumentContextParameter');
		$ctx->appendChild($guideline);

		// XML Format = Profile
		$profileGuidelines = [
			'MINIMUM'  => 'urn:factur-x.eu:1p0:minimum', 	// Factur-X profile
			'BASICWL'  => 'urn:factur-x.eu:1p0:basicwl', 	// Factur-X profile
			'BASIC'    => 'urn:factur-x.eu:1p0:basic', 		// Factur-X profile (flowProfile = BASIC)
			'EN16931'=> 'urn:cen.eu:en16931:2017', 		// CII Profile (PDP flowProfile => CIUS)
			'EXTENDED' => 'urn:cen.eu:en16931:2017#conformant#urn:factur-x.eu:1p0:extended', // Factur-X profile (flowProfile = Extended-CTC-FR)
			'EXTENDEDFR' => 'urn:cen.eu:en16931:2017#conformant#urn.cpro.gouv.fr:1p0:extended-ctc-fr', 			// Factur-X and CII profile (Only France) (flowProfile = Extended-CTC-FR)
		];

		if (!isset($profileGuidelines[$profile])) {
			throw new \InvalidArgumentException("Profil inconnu : $profile");
		}

		$guideline->appendChild(
			$doc->createElement('ram:ID', $profileGuidelines[$profile])
		);

		// Document
		$exDoc = $doc->createElement('rsm:ExchangedDocument');
		$root->appendChild($exDoc);

		$exDoc->appendChild($doc->createElement('ram:ID', htmlspecialchars((string) $invoiceData['documentno'])));
		$exDoc->appendChild($doc->createElement('ram:TypeCode', htmlspecialchars((string) $invoiceData['documenttypecode'])));

		// Date
		$issueDT = $doc->createElement('ram:IssueDateTime');
		$exDoc->appendChild($issueDT);

		$dt = $doc->createElement(
			'udt:DateTimeString',
			$invoiceData['documentdate']->format('Ymd')
		);
		$dt->setAttribute('format', '102');
		$issueDT->appendChild($dt);

		// ram:IncludedNote is not declared by the MINIMUM schema: its ExchangedDocumentType allows
		// only ram:ID, ram:TypeCode and ram:IssueDateTime.
		if (!$this->isMinimumProfile($profile)) {
			// Notes
			if (!empty($invoiceData['documentNotePublic'])) {
				$note = $doc->createElement('ram:IncludedNote');
				$exDoc->appendChild($note);
				$note->appendChild($doc->createElement('ram:Content', htmlspecialchars($invoiceData['documentNotePublic'])));
			}
			if (!empty($invoiceData['documentNotePMT'])) {
				$note = $doc->createElement('ram:IncludedNote');
				$exDoc->appendChild($note);
				$note->appendChild($doc->createElement('ram:Content', htmlspecialchars($invoiceData['documentNotePMT'])));
				$note->appendChild($doc->createElement('ram:SubjectCode', 'PMT'));
			}
			if (!empty($invoiceData['documentNotePMD'])) {
				$note = $doc->createElement('ram:IncludedNote');
				$exDoc->appendChild($note);
				$note->appendChild($doc->createElement('ram:Content', htmlspecialchars($invoiceData['documentNotePMD'])));
				$note->appendChild($doc->createElement('ram:SubjectCode', 'PMD'));
			}
			if (!empty($invoiceData['documentNoteAAB'])) {
				$note = $doc->createElement('ram:IncludedNote');
				$exDoc->appendChild($note);
				$note->appendChild($doc->createElement('ram:Content', htmlspecialchars($invoiceData['documentNoteAAB'])));
				$note->appendChild($doc->createElement('ram:SubjectCode', 'AAB'));
			}
			if (!empty($invoiceData['documentNoteTXD'])) {
				$note = $doc->createElement('ram:IncludedNote');
				$exDoc->appendChild($note);
				$note->appendChild($doc->createElement('ram:Content', htmlspecialchars($invoiceData['documentNoteTXD'])));
				$note->appendChild($doc->createElement('ram:SubjectCode', 'TXD'));
			}
		}

		//$root->appendChild($doc->createTextNode("\n "));


		// Transaction
		$sctt = $doc->createElement('rsm:SupplyChainTradeTransaction');
		$root->appendChild($sctt);


		// LINES
		// Skipped entirely on the header-only profiles, see isHeaderOnlyProfile(). The header totals
		// (BT-106 and the BG-23 breakdown) are built from $invoiceData, not from the line nodes, so
		// dropping the nodes changes nothing else in the document.
		if (!$this->isHeaderOnlyProfile($profile)) {
			foreach ($linesData as $line) {
				// Add a XML comment to help debug
				$comment = $doc->createComment('Line '.$line['lineid']);
				$sctt->appendChild($comment);

				$sctt->appendChild($this->buildLineItem($doc, $line, $profile));
			}
			// Add a XML comment to help debug
			$comment = $doc->createComment('End of lines');
			$sctt->appendChild($comment);
		} else {
			$comment = $doc->createComment('No line: profile '.$profile.' is header-only');
			$sctt->appendChild($comment);
		}


		// SELLER / BUYER
		$agreement = $doc->createElement('ram:ApplicableHeaderTradeAgreement');
		$sctt->appendChild($agreement);

		// Buyer reference (BT-10): what the buyer needs to route the invoice inside its own
		// organisation (business unit, service reference, internal mailbox...), and what Annexe A of
		// XP Z12-012 documents as the "Service Executant" of a public buyer. Every profile declares
		// it, MINIMUM included, and it opens the HeaderTradeAgreement sequence, so it is appended
		// before the parties. It is not what BR-FR-CPRO-11 and BR-FR-CPRO-13 read, though: those two
		// look the service code up on BT-46 under scheme 0224, emitted by buildParty() (issue #678).
		if (!empty($invoiceData['buyerReference'])) {
			$comment = $doc->createComment('Buyer reference (BT-10)');
			$agreement->appendChild($comment);

			$agreement->appendChild($doc->createElement('ram:BuyerReference', htmlspecialchars($invoiceData['buyerReference'])));
		}

		// Seller
		$comment = $doc->createComment('Seller');
		$agreement->appendChild($comment);

		$this->buildParty($doc, $agreement, $invoiceData, 'seller', true, false, $profile);

		// Add comment
		$comment = $doc->createComment('Buyer');
		$agreement->appendChild($comment);

		$this->buildParty($doc, $agreement, $invoiceData, 'buyer', true, false, $profile);

		// Buyer order reference (BT-13): customer purchase order number (Dolibarr "Réf. client").
		// Emitted only when non-empty to avoid empty tags / cardinality issues. See issue #302.
		// Position follows the CII schema sequence (after BuyerTradeParty).
		if (!empty($invoiceData['orderReference'])) {
			$comment = $doc->createComment('Buyer order reference (BT-13)');
			$agreement->appendChild($comment);

			$buyerOrderRef = $doc->createElement('ram:BuyerOrderReferencedDocument');
			$agreement->appendChild($buyerOrderRef);
			$buyerOrderRef->appendChild($doc->createElement('ram:IssuerAssignedID', htmlspecialchars($invoiceData['orderReference'])));
		}

		// Contract reference (BT-12): the contract the invoice is issued under. ram:ContractReferencedDocument
		// is absent from the MINIMUM schema, so emitting it there would fail validation; every other
		// profile declares it. Sequence position: right after BuyerOrderReferencedDocument.
		if (!empty($invoiceData['contractReference']) && $profile !== 'MINIMUM') {
			$comment = $doc->createComment('Contract reference (BT-12)');
			$agreement->appendChild($comment);

			$contractRef = $doc->createElement('ram:ContractReferencedDocument');
			$agreement->appendChild($contractRef);
			$contractRef->appendChild($doc->createElement('ram:IssuerAssignedID', htmlspecialchars($invoiceData['contractReference'])));
		}

		// Additional order references: when an invoice covers several purchase orders, the first is
		// emitted as BT-13 (BuyerOrderReferencedDocument above) and the others are listed here as
		// AdditionalReferencedDocument/TypeCode=130 — the same approach Factur-X uses. Restricted to
		// profiles that carry AdditionalReferencedDocument in the agreement section (not MINIMUM), and
		// skipped for Chorus (which does not accept these extra nodes). Sequence position: after
		// ContractReferencedDocument, before SpecifiedProcuringProject (CII schema order).
		if (!$invoiceData['_chorus'] && $profile !== 'MINIMUM' && !empty($invoiceData['_customerOrderReferenceList'])) {
			foreach ($invoiceData['_customerOrderReferenceList'] as $additionalOrderRef) {
				if ($additionalOrderRef === $invoiceData['orderReference']) {
					continue;
				}
				$addRef = $doc->createElement('ram:AdditionalReferencedDocument');
				$addRef->appendChild($doc->createElement('ram:IssuerAssignedID', htmlspecialchars($additionalOrderRef)));
				$addRef->appendChild($doc->createElement('ram:TypeCode', '130'));
				$agreement->appendChild($addRef);
			}
		}

		// Project reference (BT-11): the project the invoice belongs to. ram:SpecifiedProcuringProject
		// only exists from EN16931 up, and ProcuringProjectType makes BOTH ram:ID and ram:Name
		// mandatory, so a project with no title falls back on its reference rather than emit an empty
		// element. Sequence position: last element of the HeaderTradeAgreement.
		$project = isset($invoiceData['_project']) ? $invoiceData['_project'] : null;
		if ($this->isEn16931Profile($profile) && $project instanceof Project && !empty($project->ref)) {
			$comment = $doc->createComment('Project reference (BT-11)');
			$agreement->appendChild($comment);

			$procuringProject = $doc->createElement('ram:SpecifiedProcuringProject');
			$agreement->appendChild($procuringProject);
			$procuringProject->appendChild($doc->createElement('ram:ID', htmlspecialchars((string) $project->ref)));
			$projectName = trim((string) $project->title);
			$procuringProject->appendChild($doc->createElement('ram:Name', htmlspecialchars($projectName !== '' ? $projectName : (string) $project->ref)));
		}


		// DELIVERY

		// Add comment
		$comment = $doc->createComment('Delivery');
		$sctt->appendChild($comment);

		$delivery = $doc->createElement('ram:ApplicableHeaderTradeDelivery');
		$sctt->appendChild($delivery);

		// HeaderTradeDeliveryType is declared EMPTY by the MINIMUM schema, so the element must stay
		// childless there - even a comment or a line feed inside it fails validation.
		if (!$this->isMinimumProfile($profile)) {
			// Add the ship to trade party (mandatory when using intracommunity delivery)
			// ShipToTradeParty is itself a TradePartyType — populate it directly without
			// wrapping it in another BuyerTradeParty (which would break XSD validation).
			// When an external SHIPPING contact with a distinct address is attached to the invoice
			// (keys filled by buildinvoicelines.inc.php), emit a dedicated deliver-to party (BG-15);
			// otherwise fall back to the buyer party so the node stays present (upstream behaviour).
			$shiptotrade = null;
			if (!empty($invoiceData['_shipFromContactShip'])) {
				$shiptotrade = $this->buildShipToTradeParty(
					$doc,
					$invoiceData['_shipFromContactBill'] ?? array(),
					$invoiceData['_shipFromContactShip']
				);
			}
			if ($shiptotrade !== null) {
				$delivery->appendChild($shiptotrade);
			} else {
				$shiptotrade = $doc->createElement('ram:ShipToTradeParty');
				$delivery->appendChild($shiptotrade);
				$this->buildParty($doc, $shiptotrade, $invoiceData, 'buyer', false, true, $profile);
			}


			if (!empty($invoiceData['documentDeliveryDate'])) {
				$event = $doc->createElement('ram:ActualDeliverySupplyChainEvent');
				$delivery->appendChild($event);

				$dtNode = $doc->createElement('ram:OccurrenceDateTime');
				$event->appendChild($dtNode);

				$str = $doc->createElement(
					'udt:DateTimeString',
					$invoiceData['documentDeliveryDate']->format('Ymd')
				);
				$str->setAttribute('format', '102');
				$dtNode->appendChild($str);
			}
		}

		// Add a XML comment to help debug
		$comment = $doc->createComment('Footer');
		$sctt->appendChild($comment);

		// SETTLEMENT
		$settlement = $doc->createElement('ram:ApplicableHeaderTradeSettlement');
		$sctt->appendChild($settlement);

		// Currency
		$settlement->appendChild($doc->createElement(
			'ram:InvoiceCurrencyCode',
			$invoiceData['invoiceCurrency']
		));

		// None of these is declared by the MINIMUM schema, whose HeaderTradeSettlementType allows only
		// ram:InvoiceCurrencyCode and ram:SpecifiedTradeSettlementHeaderMonetarySummation.
		if (!$this->isMinimumProfile($profile)) {
			// Payment mode
			if (!empty($invoiceData['paymentMeansCode'])) {
				$comment = $doc->createComment('Payment mode');
				$settlement->appendChild($comment);

				// Payment means
				$pm = $doc->createElement('ram:SpecifiedTradeSettlementPaymentMeans');
				$settlement->appendChild($pm);

				$pm->appendChild($doc->createElement('ram:TypeCode', htmlspecialchars((string) $invoiceData['paymentMeansCode'])));		// A code for payment type BT-81 (BG-16)
				// BT-82 and BT-85 below are optional: emit them only when they carry something,
				// an empty element being refused by PEPPOL-EN16931-R008 (issue #695).
				if ($this->isEn16931Profile($profile) && !empty($invoiceData['paymentMeansText'])) {
					$pm->appendChild($doc->createElement('ram:Information', htmlspecialchars((string) $invoiceData['paymentMeansText'])));	// A label for payment type BT-82
				}

				$acc = $doc->createElement('ram:PayeePartyCreditorFinancialAccount');
				$pm->appendChild($acc);

				// CII XSD order for CreditorFinancialAccountType: IBANID, AccountName, ProprietaryID
				if (!empty($invoiceData['iban'])) {
					$acc->appendChild($doc->createElement('ram:IBANID', htmlspecialchars((string) $invoiceData['iban'])));					// BT-84
				} else {
					// If no IBAN provided
					if ($invoiceData['paymentMeansCode'] == 30) {	// If payment by credit transfer
						if (empty($invoiceData['iban_id'])) {
							throw new Exception($langs->trans("IBANForSellerMandatoryOnCreditTransferButNotBankAcount").'<br>'.$langs->trans("IBANForSellerMandatoryOnCreditTransferButNotBankAcount2"), 1);
						} else {
							throw new Exception($langs->trans("IBANForSellerMandatoryOnCreditTransferButNotBAN").'<br>'.$langs->trans("IBANForSellerMandatoryOnCreditTransferButNotBAN2"), 1);
						}
					}
				}
				if ($this->isEn16931Profile($profile) && !empty($invoiceData['accountName'])) {
					$acc->appendChild($doc->createElement('ram:AccountName', htmlspecialchars((string) $invoiceData['accountName'])));		// BT-85
				}
				if (empty($invoiceData['iban']) && !empty($invoiceData['accountRef'])) {	// If IBAN unknown we can fallback on the private ref.
					$acc->appendChild($doc->createElement('ram:ProprietaryID', htmlspecialchars((string) $invoiceData['accountRef'])));	// BT-84-0
				}
				if (!empty($invoiceData['bic']) && $this->isEn16931Profile($profile)) {
					$inst = $doc->createElement('ram:PayeeSpecifiedCreditorFinancialInstitution');
					$pm->appendChild($inst);
					$inst->appendChild($doc->createElement('ram:BICID', htmlspecialchars((string) $invoiceData['bic'])));					// BT-86
				}
			}

			// VAT array by rate (tax breakdown)
			foreach ($invoiceData['taxBreakdown'] as $vals) {		// $vals is one VAT breakdown group (BG-23)
				// Add comment
				$comment = $doc->createComment('VAT rate: '.$vals['tva_tx'].', VAT src code: '.$vals['vat_src_code'].', ExemptionReasonCode: '.$vals['ExemptionReasonCode']);
				$settlement->appendChild($comment);

				$settlement->appendChild(
					$this->buildTaxNode($doc, $vals, $invoiceData['invoiceCurrency'], $invoiceData['vatDueDateTypeCode'] ?? '') 	// ApplicableTradeTax
				);
			}

			// Invoicing period of the document (BG-14 / BT-73 / BT-74), derived from the periods of the
			// lines by einvoicingInvoicingPeriodFromLines(). Placed after the ApplicableTradeTax nodes
			// and before SpecifiedTradeAllowanceCharge (the global discounts below), which is where
			// HeaderTradeSettlementType declares it in every schema from BASIC WL upwards; MINIMUM does
			// not declare it at all, and the enclosing condition already excludes that profile (issue
			// #572).
			if ($invoiceData['invoicingPeriodStart'] !== null || $invoiceData['invoicingPeriodEnd'] !== null) {
				$comment = $doc->createComment('Invoicing period');
				$settlement->appendChild($comment);

				$settlement->appendChild(
					$this->buildBillingPeriodNode($doc, $invoiceData['invoicingPeriodStart'], $invoiceData['invoicingPeriodEnd'])
				);
			}

			// Discounts
			if (!empty($invoiceData['_globalDiscounts'])) {
				$comment = $doc->createComment('Global discounts');
				$settlement->appendChild($comment);
				foreach ($invoiceData['_globalDiscounts'] as $globaldiscount) {
					$discount = [
						'amount' => number_format($globaldiscount['value'], 2, '.', ''),
						'reason' => $globaldiscount['reason'],
						'code' => '95',
						'taxCategory' => $globaldiscount['categoryVAT'],
						'taxRate' => $globaldiscount['taxRate'], // The tax rate for the discount is the same as the line tax rate. This is a common practice but not mandatory.
					];
					$this->addHeaderDiscount($doc, $settlement, $discount);
				}
			}


			// Payment terms

			// Add comment
			$comment = $doc->createComment('Payment terms');
			$settlement->appendChild($comment);

			$terms = $doc->createElement('ram:SpecifiedTradePaymentTerms');
			$settlement->appendChild($terms);

			$terms->appendChild($doc->createElement('ram:Description', htmlspecialchars((string) $invoiceData['paymentTermsText'])));

			// Due date is optional (e.g. immediate payment); guard against a null date to avoid a fatal on ->format().
			if (!empty($invoiceData['paymentDueDate'])) {
				$dtNode = $doc->createElement('ram:DueDateDateTime');
				$str = $doc->createElement('udt:DateTimeString', $invoiceData['paymentDueDate']->format('Ymd'));
				$str->setAttribute('format', '102');
				$dtNode->appendChild($str);

				$terms->appendChild($dtNode);
			}
		}

		// Totals

		// Add comment
		$comment = $doc->createComment('Totals');
		$settlement->appendChild($comment);

		$sum = $doc->createElement('ram:SpecifiedTradeSettlementHeaderMonetarySummation');
		$settlement->appendChild($sum);

		// The MINIMUM schema declares only four amounts in TradeSettlementHeaderMonetarySummationType:
		// the tax basis, the tax total, the grand total and the amount due.
		if (!$this->isMinimumProfile($profile)) {
			$sum->appendChild($doc->createElement('ram:LineTotalAmount', number_format($invoiceData['lineTotalAmount'], 2, '.', '')));
			$sum->appendChild($doc->createElement('ram:ChargeTotalAmount', number_format($invoiceData['chargeTotalAmount'], 2, '.', '')));
			$sum->appendChild($doc->createElement('ram:AllowanceTotalAmount', number_format($invoiceData['allowanceTotalAmount'], 2, '.', '')));
		}
		$sum->appendChild($doc->createElement('ram:TaxBasisTotalAmount', number_format($invoiceData['taxBasisTotalAmount'], 2, '.', '')));

		$taxTotal = $doc->createElement('ram:TaxTotalAmount', number_format($invoiceData['taxTotalAmount'], 2, '.', ''));
		$taxTotal->setAttribute('currencyID', $invoiceData['invoiceCurrency']);
		$sum->appendChild($taxTotal);

		$sum->appendChild($doc->createElement('ram:GrandTotalAmount', number_format($invoiceData['grandTotalAmount'], 2, '.', '')));
		if (!$this->isMinimumProfile($profile)) {
			$sum->appendChild($doc->createElement('ram:TotalPrepaidAmount', number_format($invoiceData['totalPrepaidAmount'], 2, '.', '')));
		}
		$sum->appendChild($doc->createElement('ram:DuePayableAmount', number_format($invoiceData['duePayableAmount'], 2, '.', '')));

		// BG-3 is not declared by the MINIMUM schema: its HeaderTradeSettlementType allows only the
		// currency and the monetary summation.
		if (!$this->isMinimumProfile($profile)) {
			// Referenced documents BG-3
			if (!empty($invoiceData['invoiceRefDocs'])) {
				foreach ($invoiceData['invoiceRefDocs'] as $refDoc) {
					$refNode = $doc->createElement('ram:InvoiceReferencedDocument');

					$refNode->appendChild($doc->createElement('ram:IssuerAssignedID', htmlspecialchars((string) $refDoc['ref'])));

					// The document type (BT-X-...) is a fatal error under EN 16931, where CII-DT-018 only
					// tolerates a TypeCode on an AdditionalReferencedDocument. The EXTENDED and
					// EXTENDED-CTC-FR profiles reinstate it (CII-EXT-DT-018).
					if ($this->isExtendedProfile($profile)) {
						$refNode->appendChild($doc->createElement('ram:TypeCode', htmlspecialchars((string) $refDoc['type'])));
					}

					// The issue date of the preceding invoice (BT-26) is part of BG-3 in EN 16931, where
					// CII-DT-027 exempts InvoiceReferencedDocument from the ban on FormattedIssueDateTime.
					// The French CTC-FR rule BR-FR-CO-05 makes it mandatory, with a "fatal" flag: a credit
					// note (BT-3 = 381) whose reference carries no date is rejected. So it belongs to every
					// profile, not only to EXTENDED.
					if (!empty($refDoc['date'])) {
						$dateNode = $doc->createElement('ram:FormattedIssueDateTime');
						$str = $doc->createElement('qdt:DateTimeString', $refDoc['date']->format('Ymd'));
						$str->setAttribute('format', '102');
						$dateNode->appendChild($str);
						$refNode->appendChild($dateNode);
					}

					$settlement->appendChild($refNode);
				}
			}
		}

		$xml = $doc->saveXML();

		// XML 1.0 admits no C0 control character other than tab, line feed and carriage return -
		// neither as a character nor as a numeric reference. A description pasted from a PDF or from
		// a terminal brings them in (0x0B is the usual one), DOM writes them out as they are, and
		// what leaves is not XML: the platform answers HTTP 400 and the invoice never reaches its
		// recipient. They carry no meaning in an invoice text, so they go. A byte below 0x80 never
		// appears inside a UTF-8 sequence, so this cannot cut a character in half.
		$xml = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $xml);

		return $xml;
	}

	/**
	 * Build a single line item node.
	 *
	 * @param DOMDocument 		$doc		Document to create nodes in
	 * @param array 			$line 		Line data
	 * @param string 			$profile 	Profile (used to conditionally include certain nodes)
	 *
	 * @return DOMElement
	 */
	private function buildLineItem(DOMDocument $doc, array $line, string $profile)
	{
		$el = $doc->createElement('ram:IncludedSupplyChainTradeLineItem');

		// ID
		$docLine = $doc->createElement('ram:AssociatedDocumentLineDocument');
		$el->appendChild($docLine);
		$docLine->appendChild($doc->createElement('ram:LineID', htmlspecialchars((string) $line['lineid'])));

		// Product
		$prod = $doc->createElement('ram:SpecifiedTradeProduct');
		$el->appendChild($prod);
		if (!empty($line['prodsellerid'])) {
			$prod->appendChild(
				$doc->createElement('ram:SellerAssignedID', htmlspecialchars((string) $line['prodsellerid']))
			);
		}
		$prod->appendChild($doc->createElement('ram:Name', htmlspecialchars($line['prodname'])));
		if (!empty($line['proddesc'])) {
			$prod->appendChild($doc->createElement('ram:Description', htmlspecialchars($line['proddesc'])));
		}

		// Price
		$price = $doc->createElement('ram:SpecifiedLineTradeAgreement');
		$el->appendChild($price);

		// This section seems not required.
		// It can be used if the price base is including tax (TTC) and without discount (= Catalog public unit price for individual customers)
		if (isset($line['grosspriceamount'])) {
			$gross = $doc->createElement('ram:GrossPriceProductTradePrice');
			$price->appendChild($gross);
			$gross->appendChild($doc->createElement('ram:ChargeAmount', number_format($line['grosspriceamount'], self::getUnitPriceDecimals(), '.', '')));
		}

		// Mandatory by Factur-X, EN 16931
		// This is the unit price excluding tax. If it does not contains the discount, the discount must be declared into AllowanceCharge.
		$net = $doc->createElement('ram:NetPriceProductTradePrice');
		$price->appendChild($net);
		$net->appendChild($doc->createElement('ram:ChargeAmount', number_format($line['netpriceamount'], self::getUnitPriceDecimals(), '.', '')));


		// Quantity
		$deliv = $doc->createElement('ram:SpecifiedLineTradeDelivery');
		$el->appendChild($deliv);

		$qty = $doc->createElement('ram:BilledQuantity', number_format($line['billedquantity'], self::MAX_DECIMALS_QUANTITY, '.', ''));
		$qty->setAttribute('unitCode', $line['billedquantityunitcode']);
		$deliv->appendChild($qty);

		// VAT
		$sett = $doc->createElement('ram:SpecifiedLineTradeSettlement');
		$el->appendChild($sett);


		// Add the VAT block for the line
		$tax = $doc->createElement('ram:ApplicableTradeTax');
		$sett->appendChild($tax);

		// Add a XML comment to help debug
		$comment = $doc->createComment('VAT rate: '.$line['tva_tx'].', VAT src code: '.$line['vat_src_code']);
		$tax->appendChild($comment);


		$tax->appendChild($doc->createElement('ram:TypeCode', 'VAT'));

		// The exemption reason is repeated on the line, not only in the VAT breakdown it also feeds.
		// BR-FXEXT-E-08 reconciles the taxable amount of an exempt breakdown (BT-116) with the sum of
		// the net amounts of the lines it covers, and it only counts a line whose own reason code and
		// reason text equal those of the breakdown. Writing them on the breakdown alone made it count
		// zero lines, so an exempt invoice was reported as unbalanced - "basisAmount : 100, SumBT131 :
		// 0, NBlines : 0" - and refused by the platform validator. The reader of this class already
		// expects both at line level, so this closes the loop rather than opening a new one.
		// Order follows the CII D22B sequence of TradeTaxType, which is not the order of the getters.
		$lineExemptionReason = (string) ($line['ExemptionReason'] ?? '');
		$lineExemptionReasonCode = (string) ($line['ExemptionReasonCode'] ?? '');
		if ($lineExemptionReason !== '') {
			$tax->appendChild($doc->createElement('ram:ExemptionReason', htmlspecialchars($lineExemptionReason)));
		}
		$tax->appendChild($doc->createElement('ram:CategoryCode', htmlspecialchars((string) $line['categoryCode'])));
		if ($lineExemptionReasonCode !== '') {
			$tax->appendChild($doc->createElement('ram:ExemptionReasonCode', htmlspecialchars((string) $lineExemptionReasonCode)));
		}
		$tax->appendChild($doc->createElement('ram:RateApplicablePercent', htmlspecialchars((string) $line['rateApplicablePercent'])));

		// Billing period for the line (BG-26 / BT-134 / BT-135). Must be placed after ApplicableTradeTax
		// and before SpecifiedTradeAllowanceCharge (discount below) per the CII D22B schema sequence.
		if ($line['linePeriodStart'] !== null || $line['linePeriodEnd'] !== null) {
			$sett->appendChild($this->buildBillingPeriodNode($doc, $line['linePeriodStart'], $line['linePeriodEnd']));
		}

		if ($line['discountPercent']) {
			$discount = [
				'basis' => $line['netpriceamount'] * $line['billedquantity'],	// The base amount for the discount is the line net price * quantity.
				'amount' => $line['netpriceamount'] * $line['billedquantity'] * $line['discountPercent'] / 100,
				'percent' => $line['discountPercent'],
				'taxCategory' => $line['categoryCode'],
				'taxRate' => $line['rateApplicablePercent'], // The tax rate for the discount is the same as the line tax rate.
				'code' => '95', // '95' is the code for "Promotion discount" can be replaced by a reason.
			];
			$this->addLineDiscount($doc, $sett, $discount);
		}


		// Total line
		$sum = $doc->createElement('ram:SpecifiedTradeSettlementLineMonetarySummation');
		$sett->appendChild($sum);
		$sum->appendChild($doc->createElement('ram:LineTotalAmount', number_format($line['lineTotalAmount'], 2, '.', '')));

		// Ref doc for deposit line
		if (!empty($line['isDepositLine'])) {
			$refNode = $doc->createElement('ram:AdditionalReferencedDocument');

			$refNode->appendChild($doc->createElement('ram:IssuerAssignedID', htmlspecialchars((string) $line['depositInvoiceRef'])));
			$refNode->appendChild($doc->createElement('ram:TypeCode', '130'));

			if (!empty($line['depositInvoiceDate']) && $profile === 'EXTENDED') {
				$dateNode = $doc->createElement('ram:FormattedIssueDateTime');
				$str = $doc->createElement('qdt:DateTimeString', $line['depositInvoiceDate']->format('Ymd'));
				$str->setAttribute('format', '102');
				$dateNode->appendChild($str);
				$refNode->appendChild($dateNode);
			}

			$sett->appendChild($refNode);
		}

		return $el;
	}

	/**
	 * Build the seller or buyer party node.
	 *
	 * @param \DOMDocument 		$doc			Document to create nodes in
	 * @param \DOMElement  		$agreement 		Parent agreement node to append to
	 * @param array       		$data      		Invoice data array
	 * @param string      		$type      		'seller' or 'buyer'
	 * @param bool        		$wrap			Whether to wrap in SellerTradeParty/BuyerTradeParty (true for main parties, false for ship to party)
	 * @param bool        		$minimal		Whether to emit only mandatory fields (used for building the ShipToTradeParty)
	 * @param string      		$profile   		Profile being built, to skip the groups it does not define
	 *
	 * @return void
	 */
	private function buildParty($doc, $agreement, $data, $type, $wrap = true, $minimal = false, $profile = '')
	{
		if ($wrap) {
			$tag = $type === 'seller' ? 'ram:SellerTradeParty' : 'ram:BuyerTradeParty';
			$node = $doc->createElement($tag);
			$agreement->appendChild($node);
		} else {
			$node = $agreement;
		}

		$prefix = $type;

		// ID / GlobalID — only one of the two may be present. If GlobalID is present, omit the ID to avoid XSD validation errors
		// The MINIMUM schema declares neither: its TradePartyType starts at ram:Name, and the party is
		// identified there by its ram:SpecifiedLegalOrganization/ram:ID instead.
		if (!$this->isMinimumProfile($profile)) {
			if (!empty($data[$prefix . 'GlobalIds'])) {
				foreach ($data[$prefix . 'GlobalIds'] as $globalId) {
					$g = $doc->createElement('ram:GlobalID', htmlspecialchars((string) $globalId['value']));
					$g->setAttribute('schemeID', $globalId['schemeID']);
					$node->appendChild($g);
				}
			} else {
				$node->appendChild($doc->createElement('ram:ID', htmlspecialchars((string) $data[$prefix . 'ids'])));
			}

			// Routing code of the buyer (BT-46 under scheme 0224), where BR-FR-CPRO-11 and
			// BR-FR-CPRO-13 read the Chorus Pro "code service exécutant". It is a second ram:GlobalID,
			// which only the EXTENDED profiles accept (FX-SCH-A-000164 caps that element at one
			// occurrence below them), and it belongs to the buyer alone: the deliver-to party is built
			// from the same data in minimal mode, and its own identifier is BT-71, a location, not a
			// routing code of the buyer. See issue #678; the caller only fills the key when the value
			// is emittable.
			if ($type === 'buyer' && !$minimal && $this->isExtendedProfile($profile) && !empty($data['buyerRoutingCode'])) {
				$routing = $doc->createElement('ram:GlobalID', htmlspecialchars($data['buyerRoutingCode']));
				$routing->setAttribute('schemeID', EInvoicing::SCHEME_FR_ROUTING_CODE);
				$node->appendChild($routing);
			}
		}

		$node->appendChild($doc->createElement('ram:Name', htmlspecialchars($data[$prefix . 'name'])));

		// Legal org
		if (!$minimal) {
			$legal = $doc->createElement('ram:SpecifiedLegalOrganization');
			$node->appendChild($legal);
			$id = $doc->createElement('ram:ID', htmlspecialchars((string) $data[$prefix . 'LegalOrgId']));
			$id->setAttribute('schemeID', $data[$prefix . 'LegalOrgScheme']);
			$legal->appendChild($id);
			// LegalOrganizationType is reduced to ram:ID by the MINIMUM schema.
			// The trading name (BT-28 for the seller, BT-45 for the buyer) is optional and the norm
			// only asks for it when it differs from the party name, so an absent one is written as
			// nothing at all: an empty element would be refused by PEPPOL-EN16931-R008 (issue #695).
			if (!$this->isMinimumProfile($profile) && !empty($data[$prefix . 'TradingName'])) {
				$legal->appendChild(
					$doc->createElement('ram:TradingBusinessName', htmlspecialchars((string) $data[$prefix . 'TradingName']))
				);
			}
		}

		// Contact
		// ram:DefinedTradeContact is the wrapper for all contact sub-fields. Only create it when at
		// least one sub-field is present, otherwise $contact stays null and appendChild() fatals
		// (e.g. specimen seller with a phone but no contact person name).
		// Skipped in minimal mode: the deliver-to party is a stripped-down copy of the buyer, and the
		// CII syntax binding forbids a contact there (CII-SR-312), as it does a legal organization.
		// The fax number is deliberately not part of this list: EN16931 has no business term for it
		// and the CII syntax binding forbids ram:FaxUniversalCommunication (CII-SR-236 / CII-SR-265),
		// so a party known only by its fax gets no contact block at all.
		if (!$minimal
			&& $this->isEn16931Profile($profile)
			&& (!empty($data[$prefix . 'contactpersonname'])
			|| !empty($data[$prefix . 'contactdepartmentname'])
			|| !empty($data[$prefix . 'contactphoneno'])
			|| !empty($data[$prefix . 'contactemailaddr']))) {
			$contact = $doc->createElement('ram:DefinedTradeContact');
			$node->appendChild($contact);

			if (!empty($data[$prefix . 'contactpersonname'])) {
				$contact->appendChild($doc->createElement('ram:PersonName', htmlspecialchars($data[$prefix . 'contactpersonname'])));
			}

			if (!empty($data[$prefix . 'contactdepartmentname'])) {
				$contact->appendChild($doc->createElement('ram:DepartmentName', htmlspecialchars($data[$prefix . 'contactdepartmentname'])));
			}

			if (!empty($data[$prefix . 'contactphoneno'])) {
				$phone = $doc->createElement('ram:TelephoneUniversalCommunication');
				$contact->appendChild($phone);
				$phone->appendChild($doc->createElement('ram:CompleteNumber', htmlspecialchars((string) $data[$prefix . 'contactphoneno'])));
			}

			// No ram:FaxUniversalCommunication here on purpose, see the comment above. The
			// 'contactfaxno' key is still filled and still read back from incoming invoices
			// (CommonProtocol::...), it is only never written out.

			if (!empty($data[$prefix . 'contactemailaddr'])) {
				$email = $doc->createElement('ram:EmailURIUniversalCommunication');
				$contact->appendChild($email);
				$email->appendChild($doc->createElement('ram:URIID', htmlspecialchars((string) $data[$prefix . 'contactemailaddr'])));
			}
		}


		// Address
		$addr = $doc->createElement('ram:PostalTradeAddress');
		$node->appendChild($addr);

		// TradeAddressType is reduced to the country code by the MINIMUM schema
		if (!$this->isMinimumProfile($profile)) {
			$addr->appendChild($doc->createElement('ram:PostcodeCode', htmlspecialchars((string) $data[$prefix . 'postcode'])));
			// The three address lines the norm has: BT-35/36/162 for the seller, BT-50/51/163 for the
			// buyer. XSD order inside TradeAddressType is PostcodeCode, LineOne, LineTwo, LineThree,
			// CityName, CountryID - the elements are written in that order and nowhere else.
			if (!empty($data[$prefix . 'lineone'])) {
				$addr->appendChild($doc->createElement('ram:LineOne', htmlspecialchars($data[$prefix . 'lineone'])));
			}
			if (!empty($data[$prefix . 'linetwo'])) {
				$addr->appendChild($doc->createElement('ram:LineTwo', htmlspecialchars($data[$prefix . 'linetwo'])));
			}
			if (!empty($data[$prefix . 'linethree'])) {
				$addr->appendChild($doc->createElement('ram:LineThree', htmlspecialchars($data[$prefix . 'linethree'])));
			}
			$addr->appendChild($doc->createElement('ram:CityName', htmlspecialchars($data[$prefix . 'city'])));
		}
		$addr->appendChild($doc->createElement('ram:CountryID', htmlspecialchars((string) $data[$prefix . 'country'])));

		// URIUniversalCommunication. Not declared by the MINIMUM schema.
		if (!$minimal && !$this->isMinimumProfile($profile) && !empty($data[$prefix . 'CommunicationUriScheme']) && !empty($data[$prefix . 'CommunicationUri'])) {
			$uri = $doc->createElement('ram:URIUniversalCommunication');
			$node->appendChild($uri);
			$uriid = $doc->createElement('ram:URIID', htmlspecialchars((string) $data[$prefix . 'CommunicationUri']));			// Example 315143296_1939
			$uriid->setAttribute('schemeID', $data[$prefix . 'CommunicationUriScheme']);			// Example 0225
			$uri->appendChild($uriid);
		}

		// VAT
		// The party declares the tax registrations built for it, which is how a seller that charges no
		// VAT declares its SIREN as BT-32 (schemeID FC) where a seller that does declares its VAT number
		// as BT-31 (schemeID VA). Writing only the latter left the party of a "Non assujetti a la TVA"
		// company with no tax registration at all, and every exempt line then tripped BR-E-02 (issue
		// #560). Parties built without that list - the buyer, which has no BT-32 in EN 16931 - keep
		// being written from their VAT number alone.
		if (!$minimal) {
			$registrations = array();
			if (!empty($data[$prefix . 'TaxRegistations']) && is_array($data[$prefix . 'TaxRegistations'])) {
				foreach ($data[$prefix . 'TaxRegistations'] as $registration) {
					if (is_array($registration) && !empty($registration['type']) && !empty($registration['value'])) {
						$registrations[] = $registration;
					}
				}
			}
			if (empty($registrations) && !empty($data[$prefix . 'vatnumber'])) {
				$registrations[] = array('type' => 'VA', 'value' => $data[$prefix . 'vatnumber']);
			}

			foreach ($registrations as $registration) {
				$tax = $doc->createElement('ram:SpecifiedTaxRegistration');
				$id = $doc->createElement('ram:ID', htmlspecialchars((string) $registration['value']));
				$id->setAttribute('schemeID', $registration['type']);
				$tax->appendChild($id);
				$node->appendChild($tax);
			}
		}
	}

	/**
	 * Build a <ram:ShipToTradeParty> element when the shipping address actually differs from the
	 * billing (buyer) address and carries a resolvable country code.
	 *
	 * Returns null when no distinct ship-to party must be emitted, in which case the caller is
	 * expected to fall back to the upstream behaviour (ship-to = buyer), keeping the
	 * ApplicableHeaderTradeDelivery/ShipToTradeParty node always present (intracommunity requirement).
	 *
	 * @param DOMDocument $doc   Document to create nodes in
	 * @param array       $bill  Billing address: keys address, zip, town, country (alpha-2)
	 * @param array       $ship  Shipping address: keys name, address, zip, town, country (alpha-2)
	 * @return DOMElement|null   The ShipToTradeParty node, or null to fall back to the buyer party
	 */
	private function buildShipToTradeParty(DOMDocument $doc, array $bill, array $ship)
	{
		// BR-57: a postal address present in the XML must carry a non-empty CountryID. Without a
		// resolvable country we cannot emit a valid BG-15, so we skip the contact ship-to entirely.
		if (empty($ship['country'])) {
			return null;
		}

		// Normalized comparison (case / whitespace) to avoid false positives that would emit a
		// redundant BG-15 identical to the buyer address.
		$norm = function ($s) {
			$s = preg_replace('/\s+/', ' ', trim((string) ($s ?? '')));
			return function_exists('mb_strtoupper') ? mb_strtoupper($s, 'UTF-8') : strtoupper($s);
		};
		$billKey = array($norm($bill['address'] ?? ''), $norm($bill['zip'] ?? ''), $norm($bill['town'] ?? ''), $norm($bill['country'] ?? ''));
		$shipKey = array($norm($ship['address'] ?? ''), $norm($ship['zip'] ?? ''), $norm($ship['town'] ?? ''), $norm($ship['country'] ?? ''));
		if ($billKey === $shipKey) {
			return null;
		}

		$node = $doc->createElement('ram:ShipToTradeParty');

		// BT-70 Deliver-to party name (optional).
		if (!empty($ship['name'])) {
			$node->appendChild($doc->createElement('ram:Name', htmlspecialchars($ship['name'])));
		}

		// BG-15 Deliver-to address.
		$addr = $doc->createElement('ram:PostalTradeAddress');
		$node->appendChild($addr);

		// CII XSD order for PostalTradeAddress: PostcodeCode BEFORE LineOne (counter-intuitive).
		if (!empty($ship['zip'])) {
			$addr->appendChild($doc->createElement('ram:PostcodeCode', htmlspecialchars((string) $ship['zip'])));
		}
		// BT-75/76/165: the deliver-to address has three lines too. The caller splits the free text
		// field Dolibarr stores; a single-line address keeps landing on LineOne alone.
		$shiplines = array(
			$ship['lineone'] ?? $ship['address'] ?? '',
			$ship['linetwo'] ?? '',
			$ship['linethree'] ?? '',
		);
		foreach (array('ram:LineOne', 'ram:LineTwo', 'ram:LineThree') as $rank => $element) {
			if (!empty($shiplines[$rank])) {
				$addr->appendChild($doc->createElement($element, htmlspecialchars($shiplines[$rank])));
			}
		}
		if (!empty($ship['town'])) {
			$addr->appendChild($doc->createElement('ram:CityName', htmlspecialchars($ship['town'])));
		}
		// CountryID is mandatory whenever the address block exists (BR-57). Guaranteed non-empty here.
		$addr->appendChild($doc->createElement('ram:CountryID', htmlspecialchars((string) $ship['country'])));

		return $node;
	}

	/**
	 * Build a ram:BillingSpecifiedPeriod node.
	 *
	 * The same type serves the two levels the norm has for a period: BT-73/BT-74 under
	 * ApplicableHeaderTradeSettlement (BG-14, the period the invoice covers) and BT-134/BT-135 under
	 * SpecifiedLineTradeSettlement (BG-26, the period a line covers). One side alone is a period the
	 * norm accepts - BR-CO-19 asks for the start date or the end date - so each date is written only
	 * if it is there.
	 *
	 * @param \DOMDocument 			$doc 	Document to create nodes in
	 * @param ?\DateTimeInterface	$start 	Start of the period, null to leave BT-73 / BT-134 out
	 * @param ?\DateTimeInterface	$end 	End of the period, null to leave BT-74 / BT-135 out
	 * @return \DOMElement
	 */
	private function buildBillingPeriodNode($doc, $start, $end)
	{
		$period = $doc->createElement('ram:BillingSpecifiedPeriod');

		if ($start !== null) {
			$startNode = $doc->createElement('ram:StartDateTime');
			$startStr = $doc->createElement('udt:DateTimeString', $start->format('Ymd'));
			$startStr->setAttribute('format', '102');
			$startNode->appendChild($startStr);
			$period->appendChild($startNode);
		}

		if ($end !== null) {
			$endNode = $doc->createElement('ram:EndDateTime');
			$endStr = $doc->createElement('udt:DateTimeString', $end->format('Ymd'));
			$endStr->setAttribute('format', '102');
			$endNode->appendChild($endStr);
			$period->appendChild($endNode);
		}

		return $period;
	}

	/**
	 * Build a tax node.
	 *
	 * @param \DOMDocument 		$doc 		Document to create nodes in
	 * @param array       		$vals 		One VAT breakdown group (BG-23): rate, category, exemption and totals
	 * @param string       		$currency 	Currency code
	 * @param string       		$dueDateTypeCode 	BT-8, VAT point date code ('5', '29' or '72'), empty to omit it
	 * @return \DOMElement
	 */
	private function buildTaxNode($doc, $vals, $currency, $dueDateTypeCode = '')
	{
		$tax = $doc->createElement('ram:ApplicableTradeTax');

		$tax->appendChild($doc->createElement('ram:CalculatedAmount', number_format($vals['totalTVA'], 2, '.', '')));

		$tax->appendChild($doc->createElement('ram:TypeCode', 'VAT'));

		if ($vals['ExemptionReason']) {
			$tax->appendChild($doc->createElement('ram:ExemptionReason', htmlspecialchars((string) $vals['ExemptionReason'])));
		}

		$tax->appendChild($doc->createElement('ram:BasisAmount', number_format($vals['totalHT'], 2, '.', '')));

		$tax->appendChild($doc->createElement('ram:CategoryCode', htmlspecialchars((string) $vals['categoryVAT'])));

		if ($vals['ExemptionReasonCode']) {
			$tax->appendChild($doc->createElement('ram:ExemptionReasonCode', htmlspecialchars((string) $vals['ExemptionReasonCode'])));
		}

		if (!empty($dueDateTypeCode)) {		// BT-8, placed before the rate per the CII D22B sequence
			$tax->appendChild($doc->createElement('ram:DueDateTypeCode', htmlspecialchars((string) $dueDateTypeCode)));
		}

		// BT-119 comes from the group itself, never from the key it is filed under: that key identifies
		// the group (category, rate and exemption reason together) and is not a number.
		$tax->appendChild($doc->createElement('ram:RateApplicablePercent', number_format((float) $vals['tva_tx'], 2, '.', '')));

		return $tax;
	}

	/**
	 * Build an allowance charge node (discount or charge).
	 *
	 * @param \DOMDocument 		$doc			Document to create nodes in
	 * @param float|null        $amount 		Amount of the discount/charge (final amount after calculation)
	 * @param float|null        $percent 		Percentage of the discount/charge
	 * @param float|null        $basisAmount 	Base amount for percentage calculation
	 * @param bool        		$isCharge 		Whether this is a charge (true) or a discount (false)
	 * @param string|null       $reason 		Reason for the discount/charge (optional)
	 * @param string|null       $reasonCode 	Code for the reason (optional, can be used instead of reason or together with reason)
	 * @param string            $taxCategory 	Tax category code
	 * @param float             $taxRate 		Tax rate applicable to this discount/charge
	 * @param bool              $withCategoryTradeTax	Whether to emit ram:CategoryTradeTax. Mandatory on a document-level
	 *                                                  allowance/charge (BT-95/BT-96, BT-102/BT-103), forbidden on a
	 *                                                  line-level one (CII-SR-191)
	 *
	 * @return \DOMElement
	 */
	private function buildAllowanceChargeNode(
		DOMDocument $doc,
		$amount = null,
		$percent = null,
		$basisAmount = null,
		$isCharge = false,
		$reason = null,
		$reasonCode = null,
		$taxCategory = 'S',
		$taxRate = 20.0,
		$withCategoryTradeTax = true
	) {
		$node = $doc->createElement('ram:SpecifiedTradeAllowanceCharge');

		// Indicator (remise ou charge)
		$indicator = $doc->createElement('ram:ChargeIndicator');
		$indicatorValue = $doc->createElement('udt:Indicator', $isCharge ? 'true' : 'false');
		$indicator->appendChild($indicatorValue);
		$node->appendChild($indicator);

		// Percent (optionnel)
		if ($percent !== null) {
			$node->appendChild($doc->createElement('ram:CalculationPercent', number_format($percent, 2, '.', '')));
		}

		// Basis amount (optionnel)
		if ($basisAmount !== null) {
			$node->appendChild($doc->createElement('ram:BasisAmount', number_format($basisAmount, 2, '.', '')));
		}

		// Amount (final discount/charge)
		if ($amount !== null) {
			$node->appendChild($doc->createElement('ram:ActualAmount', number_format($amount, 2, '.', '')));
		}

		// reasonCode
		if ($reasonCode !== null) {
			$node->appendChild($doc->createElement('ram:ReasonCode', htmlspecialchars((string) $reasonCode)));
		}

		// Reason
		if ($reason !== null) {
			$node->appendChild($doc->createElement('ram:Reason', htmlspecialchars((string) $reason)));
		}

		// Tax (important Factur-X). Document level only: on a line the VAT of the discount is already
		// carried by the line's own ram:ApplicableTradeTax, and CII-SR-191 forbids it here.
		if ($withCategoryTradeTax) {
			$taxNode = $doc->createElement('ram:CategoryTradeTax');

			$taxNode->appendChild($doc->createElement('ram:TypeCode', 'VAT'));
			$taxNode->appendChild($doc->createElement('ram:CategoryCode', htmlspecialchars((string) $taxCategory)));
			$taxNode->appendChild($doc->createElement('ram:RateApplicablePercent', number_format($taxRate, 2, '.', '')));

			$node->appendChild($taxNode);
		}

		return $node;
	}

	/**
	 * Add a discount line to a line trade agreement node.
	 *
	 * @param \DOMDocument 		$doc					Document to create nodes in
	 * @param \DOMElement  		$lineTradeAgreement 	Node representing the line trade agreement to append the discount to
	 * @param array 			$discount 				Array containing discount data (amount, percent, basis, reason, code, taxCategory, taxRate)
	 *
	 * @return void
	 */
	private function addLineDiscount(DOMDocument $doc, DOMElement $lineTradeAgreement, array $discount)
	{

		$node = $this->buildAllowanceChargeNode(
			$doc,
			$discount['amount'] ?? null,
			$discount['percent'] ?? null,
			$discount['basis'] ?? null,
			false,
			$discount['reason'] ?? null,
			$discount['code'] ?? null,
			$discount['taxCategory'] ?? 'S',
			$discount['taxRate'] ?? 20.0,
			false			// No ram:CategoryTradeTax on a line-level allowance (CII-SR-191)
		);

		$lineTradeAgreement->appendChild($node);
	}

	/**
	 * Add a discount or charge to the header settlement node.
	 *
	 * @param \DOMDocument 		$doc				Document to create nodes in
	 * @param \DOMElement  		$headerSettlement 	Node representing the header settlement to append the discount/charge to
	 * @param array 			$discount 			Array containing discount/charge data (amount, percent, basis, reason, code, taxCategory, taxRate)
	 *
	 * @return void
	 */
	private function addHeaderDiscount(DOMDocument $doc, DOMElement $headerSettlement, array $discount)
	{
		$node = $this->buildAllowanceChargeNode(
			$doc,
			$discount['amount'] ?? null,
			$discount['percent'] ?? null,
			$discount['basis'] ?? null,
			false,
			$discount['reason'] ?? null,
			$discount['code'] ?? null,
			$discount['taxCategory'] ?? 'S',
			$discount['taxRate'] ?? 20.0
		);

		$headerSettlement->appendChild($node);
	}

	/**
	 * Map document type code to Dolibarr invoice type
	 *
	 * @param ?string $documenttypecode Document type code
	 * @return int|'-1' Dolibarr invoice type or '-1' if unknown
	 */
	protected function getDolibarrInvoiceType($documenttypecode)
	{
		if ($documenttypecode === null) {
			return '-1';
		}

		/**
		 * @var array<string,int>
		 * Codes UNTDID 1001 utilisés par EN16931 pour le type de facture (InvoiceTypeCode BT-3).
		 * 325 – Facture pro-forma (a ignorer, n'est pas une facture mais une commande)
		 * 211 – Demande de paiement intermédiaire (une facture de situation?)
		 * 386 – Facture d’acompte
		 * 381 – Avoir / Note de crédit sur facture standard
		 * 384 – Facture corrective / replacement
		 * 380 – Facture standard
		 * 503 - Avoir / Note de crédit sur une facture d'acompte
		 *
		 * 80  – Note de débit (biens ou services) --- Not used in Dolibarr
		 * 82  – Facture de services mesurés (ex : gaz, électricité) --- Not used in Dolibarr
		 * 84  – Note de débit (ajustements financiers) --- Not used in Dolibarr
		 * 130 – Feuille de données de facturation --- Not used in Dolibarr
		 * 202 – Valorisation de paiement direct --- Not used in Dolibarr
		 * 203 – Valorisation de paiement provisoire --- Not used in Dolibarr
		 * 204 – Valorisation de paiement --- Not used in Dolibarr
		 * 218 – Demande de paiement finale après achèvement des travaux --- Not used in Dolibarr
		 * 219 – Demande de paiement pour unités terminées --- Not used in Dolibarr
		 * 295 – Facture de variation de prix --- Not used in Dolibarr
		 *
		 * 326 – Facture partielle --- Not used in Dolibarr
		 */

		/** @var array<string,int> $map */
		$map = [
			'325' => CommonInvoice::TYPE_PROFORMA,  // @phan-suppress-current-line PhanDeprecatedClassConstant
			'211' => CommonInvoice::TYPE_SITUATION,

			'380' => CommonInvoice::TYPE_STANDARD,
			'384' => CommonInvoice::TYPE_REPLACEMENT,
			'381' => CommonInvoice::TYPE_CREDIT_NOTE,
			'386' => CommonInvoice::TYPE_DEPOSIT,
			'503' => CommonInvoice::TYPE_CREDIT_NOTE,
		];


		if (!isset($map[$documenttypecode])) {
			dol_syslog(get_class($this) . '::getDolibarrInvoiceType Unknown document type code: ' . $documenttypecode, LOG_WARNING);
			return '-1';
		}

		return $map[$documenttypecode];  // @phan-suppress-current-line PhanTypeMismatchDimFetch
	}


	/**
	 * Save E-invoice file to dolibarr supplier invoice attachment.
	 *
	 * @param FactureFournisseur    $supplierInvoice 	Supplier invoice object
	 * @param string                $filePath        	Path to the E-invoice file to save
	 * @param string                $suffix          	Optional suffix for the saved file name
	 * @param string				$fileext			Force file extension
	 * @return array{res:int, message:string}   		Returns array with 'res' (1 on success, -1 on error) and info 'message'
	 */
	protected function saveEInvoiceFileToSupplierInvoiceAttachment($supplierInvoice, $filePath, $suffix = 'einvoice', $fileext = '')
	{
		global $conf;

		// Ensure upload directory exists
		// The arguments are the ones the card of the core passes (fourn/facture/card.php), and they are
		// passed in full on purpose: get_exdir(0, 0, ...) answers the same thing only since Dolibarr 20,
		// where an empty level defaults to 2 for a supplier invoice. On 18 and 19 that default does not
		// exist, the path falls back to the reference of the invoice, and the document is written into a
		// directory the card never reads - so a received e-invoice was shown nowhere.
		$folder_part = get_exdir($supplierInvoice->id, 2, 0, 0, $supplierInvoice, 'invoice_supplier');

		if ($supplierInvoice->entity > 1) {
			$relative_path = $supplierInvoice->entity.'/fournisseur/facture/' . $folder_part . dol_sanitizeFileName($supplierInvoice->ref);
			$upload_dir = DOL_DATA_ROOT.'/'.$relative_path;
		} else {
			$relative_path = 'fournisseur/facture/' . $folder_part . dol_sanitizeFileName($supplierInvoice->ref);
			$upload_dir = $conf->fournisseur->dir_output . '/facture/' . $folder_part . dol_sanitizeFileName($supplierInvoice->ref);
		}

		if (!file_exists($upload_dir)) {
			if (!dol_mkdir($upload_dir)) {
				dol_syslog(__METHOD__ . " Failed to create upload directory: $upload_dir", LOG_ERR);
				return array('res' => -1, 'message' => 'Failed to create upload directory');
			}
		}

		// Prepare destination filename with optional prefix
		if (empty($fileext)) {
			$fileext = static::INVOICE_FILE_EXTENSION;
		}
		$filename = dol_sanitizeFileName($supplierInvoice->ref_supplier . (empty($suffix) ? '' : '_' . $suffix) . '.' . $fileext);

		$dest_path = $upload_dir . '/' . $filename;

		$moreinfo = array(
			'gen_or_uploaded' => 'imported',
			'src_object_type' => $supplierInvoice->table_element,
			'src_object_id' => $supplierInvoice->id,
			'description' => 'File imported by the einvoicing module'
		);
		$result = dol_move($filePath, $dest_path, '0', 1, 0, 1, $moreinfo);
		if (!$result) {
			return array('res' => -1, 'message' => 'Error failed to move file from '.$filePath.' to '.$dest_path);
		}

		return array('res' => 1, 'message' => 'Attachment saved successfully ' . $dest_path);
	}

	/**
	 * Determines the delivery dates and the corresponding order numbers within two arrays
	 *
	 * @param 	string[]   	$customerOrderReferenceList  	array to store the corresponding order ids as strings
	 * @param 	string[]   	$deliveryDateList            	array to store the corresponding delivery dates as string in format YYYY-MM-DD
	 * @param 	Facture 	$object 						invoice object
	 * @return	void
	 */
	protected function determineDeliveryDatesAndCustomerOrderNumbers(&$customerOrderReferenceList, &$deliveryDateList, $object)
	{
		// TODO: move this function to class utils
		$object->fetchObjectLinked();
		// check for delivery notes and corresponding real delivery dates
		if (isset($object->linkedObjectsIds['shipping']) && is_array($object->linkedObjectsIds['shipping'])) {
			foreach ($object->linkedObjectsIds['shipping'] as $expeditionId) {
				$expedition = new Expedition($this->db);
				$expeditionFetchResult = $expedition->fetch($expeditionId);
				if ($expeditionFetchResult > 0) {
					if (!empty($expedition->origin) && $expedition->origin == "commande" && !empty($expedition->origin_id)) {
						$commande = new Commande($this->db);
						$commandeFetchResult = $commande->fetch($expedition->origin_id);
						if ($commandeFetchResult > 0 && !empty($commande->ref_client)) {
							$customerOrderReferenceList[] = $commande->ref_client;
						}
					}
					if (!empty($expedition->date_delivery)) {
						$deliveryDateList[] = date('Y-m-d', $expedition->date_delivery);
					}
				}
			}
		}
		// if delivery notes are linked and take the real delivery date from there. if no delivery notes are available,
		// take delivery date from order.
		if (isset($object->linkedObjectsIds['commande']) && is_array($object->linkedObjectsIds['commande'])) {
			foreach ($object->linkedObjectsIds['commande'] as $commandeId) {
				$commande = new Commande($this->db);
				$commandeFetchResult = $commande->fetch($commandeId);
				if ($commandeFetchResult > 0) {
					if (!empty($commande->ref_client)) {
						$customerOrderReferenceList[] = $commande->ref_client;
					}
					$commande->fetchObjectLinked();
					$found = 0;
					if (!empty($commande->linkedObjectsIds) && !empty($commande->linkedObjectsIds['shipping']) && \count($commande->linkedObjectsIds['shipping']) > 0) {
						foreach ($commande->linkedObjectsIds['shipping'] as $expeditionId) {
							$expedition = new Expedition($this->db);
							$expeditionFetchResult = $expedition->fetch($expeditionId);
							if ($expeditionFetchResult > 0) {
								if (!empty($expedition->date_delivery)) {
									$found++;
									$deliveryDateList[] = date('Y-m-d', $expedition->date_delivery);
								}
							}
						}
					}
					if ($found == 0) {
						if (!empty($commande->delivery_date)) {
							$deliveryDateList[] = date('Y-m-d', $commande->delivery_date);
						}
					}
				}
			}
		}
		$customerOrderReferenceList = array_unique($customerOrderReferenceList);
		sort($customerOrderReferenceList);
		$deliveryDateList = array_unique($deliveryDateList);
		rsort($deliveryDateList);
	}

	/**
	 * Turn the charges of one received line (BG-28) into the invoice lines that carry them.
	 *
	 * EN 16931 mirrors at line level what it has at document level: BG-27, an allowance, and BG-28, a
	 * charge. A Dolibarr line holds a quantity, a unit price and a discount percentage - it can express
	 * an allowance and has nowhere to put a charge - so the charge follows its line instead, the way it
	 * would be keyed in by hand. The unit price stored then stays the one the document gives (BT-146),
	 * and the two lines together are worth the BT-131 the document announces.
	 *
	 * The VAT rate is the one of the line: in CII a line level allowance or charge carries none of its
	 * own, CII-SR-191 forbidding ram:CategoryTradeTax there.
	 *
	 * @param	array<string,mixed>		$parsedLine		One line as parseInvoiceLines() returns it
	 * @return	SupplierInvoiceLine[]					One line per charge, in the order of the document
	 */
	protected function buildLineChargeLines(array $parsedLine)
	{
		global $db, $langs;

		if (empty($parsedLine['lineAllowances']) || !is_array($parsedLine['lineAllowances'])) {
			return array();
		}

		// The import runs from a cron job as well as from a page, so the language file of the module is
		// not necessarily loaded: without this the label of a charge would be its own translation key.
		$langs->load('einvoicing@einvoicing');

		$chargeLines = array();

		foreach ($parsedLine['lineAllowances'] as $allowanceCharge) {
			// Charges only. The allowances become the discount percentage of the line itself.
			if (($allowanceCharge['indicator'] ?? '') !== 'true') {
				continue;
			}

			$actualAmount = (float) ($allowanceCharge['actualAmount'] ?? 0);
			if ($actualAmount == 0.0) {
				continue;
			}

			$reason = trim((string) ($allowanceCharge['reason'] ?? ''));
			$reasonCode = trim((string) ($allowanceCharge['reasonCode'] ?? ''));
			if ($reason === '') {
				// BR-44 accepts a reason code alone, and a bare code says nothing to whoever reads the
				// invoice, so it is labelled.
				$reason = $langs->transnoentitiesnoconv('EInvoicingLineCharge');
				if ($reasonCode !== '') {
					$reason .= ' (' . $reasonCode . ')';
				}
			}
			$reason .= ' - ' . $langs->transnoentitiesnoconv('EInvoicingLineChargeOfLine', (string) ($parsedLine['lineid'] ?? '?'));

			$line = new SupplierInvoiceLine($db);
			$line->desc = $reason;
			$line->qty = 1;
			$line->subprice = $actualAmount;
			$line->tva_tx = (float) ($parsedLine['rateApplicablePercent'] ?? 0);
			$line->product_type = 1;	// A charge is a service, never a stocked good
			$line->remise_percent = 0;

			$chargeLines[] = $line;
		}

		return $chargeLines;
	}


	/**
	 * Resolve multiple line allowances into a single percentage for Dolibarr.
	 *
	 * Dolibarr only supports percentage discounts on lines, so fixed amounts
	 * are converted using basisAmount or lineTotalAmount as base.
	 * Multiple allowances are summed into one final percentage.
	 *
	 * @param array      $lineAllowances  parsed lineAllowances array
	 * @param float|null $lineTotalAmount BT-131 net line amount (base ht)
	 * @return false|array{percent: float, base: float, discountAmount: float, priceWithoutDiscount: float}
	 */
	protected function resolveLineDiscountPercent(array $lineAllowances, $lineTotalAmount)
	{
		// Allowances (indicator "false") drive the discount percentage; the charges (indicator "true") are
		// carried by their own line, but their amount has to be taken out of BT-131 here: the line total
		// the document gives already contains them, and using it as the after-discount amount would fold
		// a charge into the base the percentage is applied to (issue #735).
		$allowances = array();
		$totalChargeAmount = 0.0;
		foreach ($lineAllowances as $allowance) {
			if (($allowance['indicator'] ?? '') === 'false') {
				$allowances[] = $allowance;
			} elseif (($allowance['indicator'] ?? '') === 'true') {
				$totalChargeAmount += (float) ($allowance['actualAmount'] ?? 0);
			}
		}

		if (empty($allowances)) {
			return false;
		}

		// Base used for percent calculation — basisAmount of first entry, fallback to lineTotalAmount
		$base = $allowances[0]['basisAmount'] ?? $lineTotalAmount;

		if (!$base) {
			return false;
		}

		// Sum all actualAmounts — always populated whether the source was % or fixed
		$totalDiscountAmount = 0.0;
		foreach ($allowances as $allowance) {
			$totalDiscountAmount += (float) ($allowance['actualAmount'] ?? 0);
		}

		if ($totalDiscountAmount === 0.0) {
			return false;
		}

		return [
			'percent'              => round(($totalDiscountAmount / $base) * 100, 4),
			'base'                 => (float) $base,
			'discountAmount'       => $totalDiscountAmount,
			'priceWithoutDiscount' => (float) $lineTotalAmount - $totalChargeAmount + $totalDiscountAmount,
		];
	}


	/**
	 * Record an imported invoice at the totals its document announces, whatever the VAT calculation
	 * mode of the instance.
	 *
	 * Dolibarr computes the VAT of an invoice in one of two conventions, chosen once for the whole
	 * instance: "total of round" - round the VAT of every line, then add them up - which is what
	 * update_price() applies when nothing is set, or "round of total" - add the line amounts up, then
	 * round the VAT once - selected by MAIN_ROUNDOFTOTAL_NOT_TOTALOFROUND_SUPPLIER (the core reads the
	 * generic MAIN_ROUNDOFTOTAL_NOT_TOTALOFROUND before Dolibarr 20). The supplier invoice card offers
	 * the two as the "ReCalculate Mode1 / Mode2" link, and either is a legitimate way to bill.
	 *
	 * A received document leaves nothing to choose: BT-110 and BT-112 are given by the issuer, who
	 * rounded them the way its own system does. Importing under the convention of the instance
	 * therefore records an invoice that does not total what the document says - two cents on a real
	 * 46 line invoice (issue #781), which the module then refuses to validate, telling the operator to
	 * recalculate in the other mode. This does exactly that, on that one invoice, and leaves the
	 * setting of the instance alone.
	 *
	 * The net amounts are never concerned: the core writes a rounding difference back onto the VAT of a
	 * line, never onto its net amount, so the line net amounts (BT-131) and their sum (BT-106) are the
	 * same under both conventions. And when neither of the two reproduces the announced totals the
	 * difference is a real one rather than a rounding convention: the invoice is left exactly as the
	 * import built it and nothing is said here, checkDolInvoiceAndEInvoiceConsistency() being what
	 * reports such a gap.
	 *
	 * @param	int					$supplierInvoiceId	Id of the invoice the import has just built
	 * @param	array<string,mixed>	$parsedHeader		Header data of the received document
	 * @param	array<int,string>	$return_messages	Messages of the import, completed here
	 * @return	void
	 */
	protected function alignInvoiceTotalsWithDocument($supplierInvoiceId, array $parsedHeader, array &$return_messages)
	{
		global $db, $langs;

		if (!isset($parsedHeader['taxTotalAmount']) || !isset($parsedHeader['grandTotalAmount'])) {
			return;
		}
		$announcedTva = abs((float) $parsedHeader['taxTotalAmount']);
		$announcedTtc = abs((float) $parsedHeader['grandTotalAmount']);

		require_once DOL_DOCUMENT_ROOT . '/fourn/class/fournisseur.facture.class.php';

		$invoice = new FactureFournisseur($db);
		if ($invoice->fetch($supplierInvoiceId) <= 0) {
			return;
		}
		if (self::totalsAgreeWithDocument($invoice, $announcedTva, $announcedTtc)) {
			return;
		}

		$importedTtc = (float) $invoice->total_ttc;
		$invoice->fetch_thirdparty();

		// Mode 2 first: a document announcing a VAT rounded on its total is the case met in the field.
		// The mode of the instance has already been applied by the lines, so re-applying it costs one
		// recomputation and keeps the two conventions treated the same way.
		foreach (array('1' => 2, '0' => 1) as $roundingmode => $modenumber) {
			// Same exclspec as the import itself used (FactureFournisseur::updateline calls
			// update_price(1, 'auto')), so the rounding convention is the only thing that changes here.
			if ($invoice->update_price(1, (string) $roundingmode, 0, $invoice->thirdparty) <= 0) {
				dol_syslog(__METHOD__ . ' Failed to recalculate invoice ' . $supplierInvoiceId . ' in VAT mode ' . $modenumber . ': ' . $invoice->error, LOG_WARNING);
				break;
			}
			if ($invoice->fetch($supplierInvoiceId) <= 0) {
				return;
			}
			if (self::totalsAgreeWithDocument($invoice, $announcedTva, $announcedTtc)) {
				// The import runs from a cron job as well as from a page, so the language file of the
				// module is not necessarily loaded.
				$langs->load('einvoicing@einvoicing');
				// Translate::trans() takes four parameters and no more, its fifth argument being the
				// maximum size of the result: a fifth value would end up there and break the sprintf.
				$return_messages[] = $langs->trans(
					'EInvoiceImportVatModeRealigned',
					$modenumber,
					price2num($announcedTva, 'MT'),
					price2num($announcedTtc, 'MT'),
					price2num($importedTtc, 'MT')
				);
				dol_syslog(__METHOD__ . ' Invoice ' . $supplierInvoiceId . ' recalculated in VAT mode ' . $modenumber . ' to match the totals of the received document', LOG_DEBUG);
				return;
			}
		}

		// Neither convention gives the announced totals: leave the invoice as the import built it.
		$invoice->update_price(1, 'auto', 0, $invoice->thirdparty);
	}

	/**
	 * Tell whether an invoice totals what the received document announces.
	 *
	 * Compared on the absolute values: a credit note is stored negative by Dolibarr while BT-110 and
	 * BT-112 are always announced positive, the document type being what carries the sign (BR-CO-13
	 * applies to a credit note as it does to an invoice). The tolerance is there for the float
	 * representation, not for a difference: the document carries its totals to the cent.
	 *
	 * @param	FactureFournisseur	$invoice		The invoice, with its totals as stored
	 * @param	float				$announcedTva	BT-110 of the received document, absolute value
	 * @param	float				$announcedTtc	BT-112 of the received document, absolute value
	 * @return	bool								True when both totals are the announced ones
	 */
	private static function totalsAgreeWithDocument(FactureFournisseur $invoice, $announcedTva, $announcedTtc)
	{
		return abs(abs((float) $invoice->total_tva) - $announcedTva) < 0.005
			&& abs(abs((float) $invoice->total_ttc) - $announcedTtc) < 0.005;
	}


	/**
	 * Carry the document level charges of a received document (BG-21) as lines of the supplier invoice.
	 *
	 * EN 16931 puts two symmetric groups at document level: BG-20, an allowance, which subtracts from the
	 * total, and BG-21, a charge, which adds to it - freight, packaging, a handling fee. BR-CO-13 defines
	 * the total of the invoice as
	 * "Σ Invoice line net amount (BT-131) - Sum of allowances on document level (BT-107)
	 *  + Sum of charges on document level (BT-108)",
	 * so a charge is part of what is owed, exactly like a line is.
	 *
	 * In CII the two are the same element, ram:SpecifiedTradeAllowanceCharge, told apart only by
	 * ram:ChargeIndicator/udt:Indicator. The allowances become a DiscountAbsolute, which is the object
	 * Dolibarr has for them; the charges had nowhere to go, because a supplier invoice holds no positive
	 * amount at document level, and they were dropped - the invoice then totalled less than the document
	 * it came from, silently (issue #726).
	 *
	 * A line is what a human would key in, so that is what is written here: one line per charge, a single
	 * unit at the charge amount (BT-99), with the VAT rate the charge declares (BT-103) and the reason it
	 * gives (BT-104, or its reason code BT-105 when only that is present - BR-38 requires one of the two).
	 * They are written through createSupplierInvoiceLinesIntoDatabase(), the same path as every other line
	 * of the import, so nothing new touches the core.
	 *
	 * @param	int						$supplierInvoiceId			Id of the invoice being imported
	 * @param	array<int,array<string,mixed>>	$headerAllowancesCharges	Parsed ram:SpecifiedTradeAllowanceCharge of the header
	 * @param	array<int,string>		$return_messages			Messages of the import, completed here
	 * @return	array{res:int,message?:string}						res 1 on success, -1 on failure
	 */
	protected function createHeaderChargeLines($supplierInvoiceId, array $headerAllowancesCharges, &$return_messages): array
	{
		global $db;

		$chargeLines = $this->buildHeaderChargeLines($headerAllowancesCharges);

		if (empty($chargeLines)) {
			return ['res' => 1];
		}

		// Reuse the writer of the import rather than FactureFournisseur::addline(), whose signature moved
		// between the supported cores: the invoice is reloaded and handed only the lines to add, so the
		// ones already written are left alone.
		$carrier = new FactureFournisseur($db);
		if ($carrier->fetch((int) $supplierInvoiceId) <= 0) {
			return ['res' => -1, 'message' => 'Failed to reload the supplier invoice to add its document level charges'];
		}
		$carrier->lines = $chargeLines;

		if (!$this->createSupplierInvoiceLinesIntoDatabase($carrier)) {
			return ['res' => -1, 'message' => 'Failed to add the document level charges of the received document as invoice lines'];
		}

		$return_messages[] = count($chargeLines) . ' document level charge(s) of the received document were added as invoice lines.';

		return ['res' => 1];
	}


	/**
	 * Turn the document level charges of a received document into the invoice lines that carry them.
	 *
	 * Split out of createHeaderChargeLines() because it is the whole decision - which entries are charges,
	 * what each line is worth and how it is labelled - and it touches neither the database nor the core.
	 *
	 * @param	array<int,array<string,mixed>>	$headerAllowancesCharges	Parsed ram:SpecifiedTradeAllowanceCharge of the header
	 * @return	SupplierInvoiceLine[]										One line per charge, in the order of the document
	 */
	protected function buildHeaderChargeLines(array $headerAllowancesCharges)
	{
		global $db, $langs;

		if (empty($headerAllowancesCharges)) {
			return array();
		}

		$chargeLines = array();

		foreach ($headerAllowancesCharges as $allowanceCharge) {
			// Charges only (BG-21). The allowances are handled by createHeaderDiscounts().
			if (($allowanceCharge['indicator'] ?? '') !== 'true') {
				continue;
			}

			$actualAmount = (float) ($allowanceCharge['actualAmount'] ?? 0);
			if ($actualAmount == 0.0) {
				continue;
			}

			$reason = trim((string) ($allowanceCharge['reason'] ?? ''));
			$reasonCode = trim((string) ($allowanceCharge['reasonCode'] ?? ''));
			if ($reason === '') {
				// BR-38 accepts a reason code alone, and a bare code says nothing to whoever reads the
				// invoice, so it is labelled.
				$reason = $langs->transnoentitiesnoconv('EInvoicingDocumentLevelCharge');
				if ($reasonCode !== '') {
					$reason .= ' (' . $reasonCode . ')';
				}
			}

			$line = new SupplierInvoiceLine($db);
			$line->desc = $reason;
			$line->qty = 1;
			$line->subprice = $actualAmount;
			$line->tva_tx = (float) ($allowanceCharge['rateApplicablePercent'] ?? 0);
			$line->product_type = 1;	// A charge is a service, never a stocked good
			$line->remise_percent = 0;

			$chargeLines[] = $line;
		}

		return $chargeLines;
	}


	/**
	 * Create Dolibarr global discount exceptions from CII header allowances.
	 *
	 * Only processes allowances (indicator = "false"), ignores charges (indicator = "true").
	 * Returns array of created fk_remise_except IDs.
	 *
	 * @param array<int,array{indicator?:?string,rateApplicablePercent?:?float,reason?:?string}>  $headerAllowancesCharges  	parsed headerAllowancesCharges array
	 * @param int    $fk_soc                  	supplier ID
	 * @param string $description             	invoice number or any reference
	 * @return array{-1:string}|array<int,int>	[ originalIndex => fk_remise_except_id ] or '-1' on error
	 */
	protected function createHeaderDiscounts(array $headerAllowancesCharges, int $fk_soc, string $description): array
	{
		global $db, $user;

		$result = [];

		foreach ($headerAllowancesCharges as $index => $allowanceCharge) {
			// Allowances only (BG-20, ChargeIndicator false). A document level charge (BG-21) adds to the
			// total instead of subtracting from it, and a discount of Dolibarr can only subtract, so it is
			// carried as a line of the invoice by createHeaderChargeLines().
			if (($allowanceCharge['indicator'] ?? '') !== 'false') {
				continue;
			}

			$actualAmount = (float) ($allowanceCharge['actualAmount'] ?? 0);
			if ($actualAmount === 0.0) {
				continue;
			}

			$remise = new DiscountAbsolute($db);
			// Both properties carry the thirdparty, because the core does not read the same one everywhere:
			// DiscountAbsolute::create() inserts fk_soc up to Dolibarr 19 and socid from Dolibarr 20 on. Filling
			// only one of them writes fk_soc = 0 on the older cores, which the foreign key of
			// llx_societe_remise_except refuses - and the whole synchronization stops on that flow.
			$remise->fk_soc         = $fk_soc;
			$remise->socid          = $fk_soc;
			$remise->amount_ht       = $actualAmount;
			$remise->amount_tva      = round($actualAmount * (($allowanceCharge['rateApplicablePercent'] ?? 0) / 100), 2);
			$remise->amount_ttc      = round($remise->amount_ht + $remise->amount_tva, 2);
			$remise->total_ht 		= $remise->amount_ht;
			$remise->total_tva 		= $remise->amount_tva;
			$remise->total_ttc 		= $remise->amount_ttc;
			$remise->tva_tx          = (float) ($allowanceCharge['rateApplicablePercent'] ?? 0);
			$remise->fk_user         = $user->id;
			$remise->description     = $allowanceCharge['reason'] ?? $description;
			$remise->discount_type   = 1;

			$id = $remise->create($user);

			if ($id > 0) {
				$result[$index] = $id;
			} else {
				dol_syslog(__METHOD__ . ' Failed to create discount exception: ' . $remise->error, LOG_WARNING);
				return array(-1 => 'Failed to create discount exception: ' . $remise->error);
			}
		}

		return $result;
	}

	/**
	 * Get an existing Dolibarr discount exception linked to a deposit (acompte) supplier invoice,
	 * or create it by grouping the deposit invoice's lines by VAT rate.
	 *
	 * Shared by both the line-level and document-level deposit handling blocks of
	 * doCreateSupplierInvoiceFromSource(), in both CIIProtocol and FacturXProtocol (which inherits
	 * this method): the exact same logic was duplicated 4 times before being extracted here.
	 *
	 * @param FactureFournisseur $linkedObject The deposit (TYPE_DEPOSIT) supplier invoice referenced by the final invoice
	 * @return array{res:int<-1,1>, message?:string, fkRemise?:int} 'res' 1 on success with 'fkRemise', -1 on error with 'message'
	 */
	protected function getOrCreateDepositDiscount(FactureFournisseur $linkedObject)
	{
		global $db, $user;

		// Check if deposit line is already converted to a reduction otherwise we convert it
		$discountcheck = new DiscountAbsolute($db);
		$result = $discountcheck->fetch(0, 0, $linkedObject->id);
		if ($result > 0) {
			// Deposit already converted so reuse existing discount
			return ['res' => 1, 'fkRemise' => $discountcheck->id];
		}

		// Loop on each vat rate
		'
		@phan-var-force array<string,float> $amount_ht
		@phan-var-force array<string,float> $amount_tva
		@phan-var-force array<string,float> $amount_ttc
		@phan-var-force array<string,float> $multicurrency_amount_ht
		@phan-var-force array<string,float> $multicurrency_amount_tva
		@phan-var-force array<string,float> $multicurrency_amount_ttc
		';
		$amount_ht = $amount_tva = $amount_ttc = array();
		$multicurrency_amount_ht = $multicurrency_amount_tva = $multicurrency_amount_ttc = array();
		foreach ($linkedObject->lines as $line) {
			if ($line->product_type < 9 && $line->total_ht != 0) { // Remove lines with product_type greater than or equal to 9 and no need to create discount if amount is null
				$keyforvatrate = $line->tva_tx . ($line->vat_src_code ? ' (' . $line->vat_src_code . ')' : '');

				// Open the accumulators for a rate met for the first time. Adding to a key that does not
				// exist yet is what the core does here, and it costs six "Undefined array key" warnings
				// per deposit converted - silent on a web page, but they are real and they pile up in the
				// PHP log of every synchronization that imports an invoice linked to a deposit.
				if (!isset($amount_ht[$keyforvatrate])) {
					$amount_ht[$keyforvatrate] = 0;
					$amount_tva[$keyforvatrate] = 0;
					$amount_ttc[$keyforvatrate] = 0;
					$multicurrency_amount_ht[$keyforvatrate] = 0;
					$multicurrency_amount_tva[$keyforvatrate] = 0;
					$multicurrency_amount_ttc[$keyforvatrate] = 0;
				}

				$amount_ht[$keyforvatrate] += $line->total_ht;
				$amount_tva[$keyforvatrate] += $line->total_tva;
				$amount_ttc[$keyforvatrate] += $line->total_ttc;
				$multicurrency_amount_ht[$keyforvatrate] += $line->multicurrency_total_ht;
				$multicurrency_amount_tva[$keyforvatrate] += $line->multicurrency_total_tva;
				$multicurrency_amount_ttc[$keyforvatrate] += $line->multicurrency_total_ttc;
			}
		}

		$fkRemise = 0;
		$discount = new DiscountAbsolute($db);
		$discount->description = '(DEPOSIT)';
		$discount->discount_type = 1; // Supplier discount
		$discount->fk_soc = $linkedObject->socid;
		$discount->socid = $linkedObject->socid;
		$discount->fk_invoice_supplier_source = $linkedObject->id;
		foreach ($amount_ht as $tva_tx => $xxx) {  // @phan-suppress-current-line PhanEmptyForeach
			$discount->amount_ht = abs((float) $amount_ht[$tva_tx]);  // @phan-suppress-current-line PhanTypeInvalidDimOffset
			$discount->amount_tva = abs((float) $amount_tva[$tva_tx]);  // @phan-suppress-current-line PhanTypeInvalidDimOffset
			$discount->amount_ttc = abs((float) $amount_ttc[$tva_tx]);  // @phan-suppress-current-line PhanTypeInvalidDimOffset
			$discount->multicurrency_amount_ht = abs((float) $multicurrency_amount_ht[$tva_tx]);  // @phan-suppress-current-line PhanTypeInvalidDimOffset
			$discount->multicurrency_amount_tva = abs((float) $multicurrency_amount_tva[$tva_tx]);  // @phan-suppress-current-line PhanTypeInvalidDimOffset
			$discount->multicurrency_amount_ttc = abs((float) $multicurrency_amount_ttc[$tva_tx]);  // @phan-suppress-current-line PhanTypeInvalidDimOffset

			// Clean vat code
			$reg = array();
			$vat_src_code = '';
			if (preg_match('/\((.*)\)/', $tva_tx, $reg)) {
				$vat_src_code = $reg[1];
				$tva_tx = preg_replace('/\s*\(.*\)/', '', $tva_tx); // Remove code into vatrate.
			}

			$discount->tva_tx = abs((float) $tva_tx);
			$discount->vat_src_code = $vat_src_code;

			$result = $discount->create($user);
			if ($result < 0) {
				return ['res' => -1, 'message' => 'Failed to create discount for deposit line: ' . $discount->error];
			}
			$fkRemise = $result;
		}

		return ['res' => 1, 'fkRemise' => $fkRemise];
	}

	/**
	 * Remove attachment nodes to get a smaller XML
	 * @param string $xmlData The XML data to process
	 * @return string Cleaned XML
	 */
	public static function removeAttachmentFromXml(string $xmlData): string
	{
		$xmlDoc = new DOMDocument();
		if (!$xmlDoc->loadXML($xmlData)) {
			throw new Exception(__METHOD__ . " : failed to load XML data");
		}

		// Remove AttachedDocument nodes
		$xpath = new DOMXPath($xmlDoc);
		// Voluntary use non namespace specific path (to not have to manage different CII namespaces)
		$attachedDocumentNodes = $xpath->query('//*[local-name()="AdditionalReferencedDocument"]/*[local-name()="AttachmentBinaryObject"]');

		if (count($attachedDocumentNodes) >= 1) {
			foreach ($attachedDocumentNodes as $attachedDocumentNode) {
				// Just replace node value
				$attachedDocumentNode->nodeValue = '[Removed to get a smaller XML]';
				// Or completely remove node if you prefer :
				// if ($attachedDocumentNode && isset($attachedDocumentNode->parentNode)) {
				// 	$attachedDocumentNode->parentNode->removeChild($attachedDocumentNode);
				// }
			}
			return $xmlDoc->saveXML();
		}

		return $xmlData;
	}
}
