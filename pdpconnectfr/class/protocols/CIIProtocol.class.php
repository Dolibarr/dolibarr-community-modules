<?php
/* Copyright (C) 2026       Laurent Destailleur         <eldy@users.sourceforge.net>
 * Copyright (C) 2026       Mohamed DAOUD               <mdaoud@dolicloud.com>
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
 * \file    pdpconnectfr/class/protocols/CIIProtocol.class.php
 * \ingroup pdpconnectfr
 * \brief   CII Protocol integration class
 */

require_once DOL_DOCUMENT_ROOT . '/compta/bank/class/account.class.php';
require_once DOL_DOCUMENT_ROOT . '/expedition/class/expedition.class.php';
require_once DOL_DOCUMENT_ROOT . '/commande/class/commande.class.php';
include_once DOL_DOCUMENT_ROOT . '/core/class/translate.class.php';
include_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
include_once DOL_DOCUMENT_ROOT . '/core/class/discount.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/files.lib.php';

dol_include_once('pdpconnectfr/class/protocols/AbstractProtocol.class.php');
dol_include_once('pdpconnectfr/class/pdpconnectfr.class.php');
dol_include_once('pdpconnectfr/class/utils/XmlPatcher.class.php');
dol_include_once('pdpconnectfr/lib/pdpconnectfr.lib.php');


/**
 * CII Protocol Class
 *
 * This class handles the CII protocol implementation for generating
 * and managing electronic invoices according to the CII standard.
 */
class CIIProtocol extends AbstractProtocol
{

	protected $invoiceTemplate;
	protected $lineTemplate;
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
			'invoiceRefDocs' => '__MULTI__/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeAgreement/ram:InvoiceReferencedDocument',
			'orderReference' => '/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeAgreement/ram:BuyerOrderReferencedDocument/ram:IssuerAssignedID',
			'contractReference' => '/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeAgreement/ram:ContractReferencedDocument/ram:IssuerAssignedID',
			'despatchAdviceRef' => '/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeDelivery/ram:DespatchAdviceReferencedDocument/ram:IssuerAssignedID',

			// ── Tax breakdown (multi-value) ────────────────────────────────────────
			'taxBreakdown' => '__MULTI__/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeSettlement/ram:ApplicableTradeTax',
		];

		$this->lineTemplate = [

			'lineid' => './ram:AssociatedDocumentLineDocument/ram:LineID',
			'linestatuscode' => 'NA',
			'linestatusreasoncode' => 'NA',
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

			'exemptionReason' => './ram:SpecifiedLineTradeSettlement/ram:ApplicableTradeTax/ram:ExemptionReason',
			'exemptionReasonCode' => './ram:SpecifiedLineTradeSettlement/ram:ApplicableTradeTax/ram:ExemptionReasonCode',

			'lineAllowances' => [],
			'lineGrossPriceAllowances' => [],
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


		// Call page to generate the invoice
		include dol_buildpath('pdpconnectfr/lib/buildinvoicelines.inc.php');


		// Generate the XML file
		$filename = dol_sanitizeFileName($invoice->ref);
		$filedir = getMultidirOutput($invoice, '', 1, 'temp');
		$xmlfile = $filedir . '/' . $filename . '/einvoice.xml';

		dol_mkdir(dirname($xmlfile));
		dol_delete_file($xmlfile);

		$xmlcontent = $this->buildXML($invoiceData, $linesData, 'EN16931', $outputlangs);
		file_put_contents($xmlfile, $xmlcontent);

		dolChmod($xmlfile);

		return $xmlfile;
	}


	/**
	 * Generate a complete CII invoice file.
	 *
	 * This function combines the invoice data with its corresponding XML
	 *
	 * @param 	int|Object 	$invoice_id    	Invoice ID or Invoice Object to be processed.
	 * @param	?Translate	$outputlangs	Output language
	 * @return 	-1|string       			-1 if ko, path if ok.
	 */
	public function generateInvoice($invoice_id, $outputlangs = null)
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
			$this->error = $langs->trans("ErrorGeneratingXML") . '. ' . $e->getMessage();
			$this->errors[] = $this->error;
			return -1;
		}

		if (empty($xmlfile) || !file_exists($xmlfile)) {
			dol_syslog(get_class($this) . "::generateInvoice failed to generate XML for invoice id=" . $invoice_id, LOG_ERR);
			$this->error = $langs->trans("ErrorGeneratingXML");
			$this->errors[] = $this->error;
			return -1;
		}


		// Load PDPConnectFR specific translations
		$langs->loadLangs(array("admin", "pdpconnectfr@pdpconnectfr"));

		// Make a copy of the XML file in the final destination
		$filename = dol_sanitizeFileName($invoice->ref);
		$filedir = getMultidirOutput($invoice, '', 1);
		$einvoice_path = $filedir . '/' . $filename . '_einvoice.xml';
		if (dol_copy($xmlfile, $einvoice_path)) {
			dol_syslog(get_class($this) . "::generateInvoice copied XML file to " . $einvoice_path);
		} else {
			dol_syslog(get_class($this) . "::generateInvoice failed to copy XML file to " . $einvoice_path, LOG_ERR);
			$this->error = $langs->trans("ErrorFailToCopyFile", $xmlfile, $einvoice_path);
			$this->errors[] = $this->error;
			return -1;
		}


		// Clean up the temporary XML file
		if (file_exists($xmlfile) && !getDolGlobalString('PDPCONNECTFR_DEBUG_MODE')) {
			dol_delete_file($xmlfile);
			dol_syslog(get_class($this) . '::generateInvoice cleaned up temporary XML file: ' . $xmlfile);
		}

		// Add einvoice hook
		global $action, $hookmanager;
		$hookmanager->initHooks(array('einvoicegeneration'));
		$parameters = array('file' => $einvoice_path, 'object' => $invoice, 'outputlangs' => $langs);
		$reshook = $hookmanager->executeHooks('afterEinvoiceCreation', $parameters, $this, $action); // Note that $action and $object may have been modified by some hooks
		if ($reshook < 0) {
			$this->error = $hookmanager->error;
			$this->errors = $hookmanager->errors;
			return -1;
		}

		// Set status of einvoice
		$pdpConnectFr = new PdpConnectFr($db);
		$result = $pdpConnectFr->fetchLastknownInvoiceStatus($invoice->id);

		if (
			isset($result['code']) &&
			(in_array($result['code'], array($pdpConnectFr::STATUS_UNKNOWN, $pdpConnectFr::STATUS_NOT_GENERATED))
				|| !array_key_exists($result['code'], $pdpConnectFr::STATUS_LABEL_KEYS))
		) {
			// Set status to e-einvoice generated
			$pdpConnectFr->setEInvoiceStatus($invoice, $pdpConnectFr::STATUS_GENERATED, 'Invoice status set to Generated by generateInvoice()');
		}

		return $einvoice_path;
	}


	/**
	 * Generate a sample CII invoice for demonstration or testing purposes (for Dolibarr version >= 24.0)
	 *
	 * This method creates a dummy invoice with representative data
	 * to illustrate the CII structure without using real business information.
	 *
	 * @param	PdpConnectFr			$pdpconnectfr			PDPConnectFR
	 * @param   Societe|null			$thirdpartySeller		Optional third party object to use for generating the sample invoice. If null, a dummy third party will be created.
	 * @param   Societe|null			$thirdpartyBuyer		Optional third party object to use for generating the sample invoice. If null, a dummy third party will be created.
	 * @param   array<string,mixed>		$options				More options
	 * @return 	-1|array<string,string> 							Path or content of the generated sample invoice.
	 */
	public function generateSampleInvoice($pdpconnectfr, $thirdpartySeller = null, $thirdpartyBuyer = null, $options = array())
	{
		global $conf, $langs, $mysoc;

		dol_mkdir($conf->pdpconnectfr->dir_temp);

		$outputlangs = $langs;		// TODO Use the target language

		require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
		$tmpinvoice = new Facture($this->db);
		$tmpinvoice->initAsSpecimen('nolines');

		$tmpinvoice->ref .= '-' . dol_print_date(dol_now(), '%Y%m%d-%H%M%S');
		if (!empty($options['invoicetype'])) {
			$tmpinvoice->type = $options['invoicetype'];
		}


		$line = new FactureLigne($this->db);
		$line->desc = $langs->trans("Description") . " 1";
		$line->qty = 1;
		$line->subprice = 100;
		$line->tva_tx = 20.0;
		$line->localtax1_tx = 0;
		$line->localtax2_tx = 0;
		$line->remise_percent = 0;
		$line->fk_product = 0;
		$line->qty = 1;
		$line->total_ht = 100;
		$line->total_ttc = 120;
		$line->total_tva = 20;
		$line->multicurrency_tx = 2;
		$line->multicurrency_total_ht = 200;
		$line->multicurrency_total_ttc = 240;
		$line->multicurrency_total_tva = 40;

		$tmpinvoice->lines[] = $line;

		$tmpinvoice->total_ht       += $line->total_ht;
		$tmpinvoice->total_tva      += $line->total_tva;
		$tmpinvoice->total_ttc      += $line->total_ttc;

		$tmpinvoice->multicurrency_total_ht       += $line->multicurrency_total_ht;
		$tmpinvoice->multicurrency_total_tva      += $line->multicurrency_total_tva;
		$tmpinvoice->multicurrency_total_ttc      += $line->multicurrency_total_ttc;


		require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
		if ($thirdpartyBuyer instanceof Societe) {
			$tmpthirdparty = $thirdpartyBuyer;
		} else {
			$tmpthirdparty = new Societe($this->db);
			$tmpthirdparty->initAsSpecimen();
			$tmpthirdparty->idprof1 = '000000001';
			$tmpthirdparty->idprof2 = '00000000100010';
			$tmpthirdparty->tva_intra = 'FR12000000001';
		}
		$tmpinvoice->thirdparty = $tmpthirdparty;
		$tmpinvoice->socid = $tmpthirdparty->id;			// 0 for specimen

		require_once DOL_DOCUMENT_ROOT . '/contact/class/contact.class.php';
		$tmpcontact = new Contact($this->db);
		$tmpcontact->initAsSpecimen();
		$tmpcontact->socid = $tmpthirdparty->id;			// 0 for specimen
		$tmpinvoice->contact = $tmpcontact;



		// Set $mysoc if seller is a thirdparty when we want to generate a sample invoice for a purchase.
		$keyforconst = 'PDPCONNECTFR_' . getDolGlobalString('PDPCONNECTFR_PDP') . '_ROUTING_ID';
		$savmysoc = null;
		$savPDPCONNECTFR_ROUTING_ID = null;
		if ($thirdpartySeller instanceof Societe) {
			$savmysoc = $mysoc;
			$savPDPCONNECTFR_ROUTING_ID = getDolGlobalString($keyforconst);

			$mysoc = $thirdpartySeller;
			$conf->global->PDPCONNECTFR_SUPERPDP_ROUTING_ID = idprof($thirdpartySeller);
		}
		//var_dump(($savmysoc ? $savmysoc->name : ''), $mysoc->name, $thirdpartyBuyer->name);



		// Generate the PDF
		$tmpinvoice->generateDocument($tmpinvoice->model, $outputlangs);

		// For invoice with ->specimen=1, the file is SPECIMEN.pdf so we rename it into ref
		$dir = $conf->invoice->multidir_output[$conf->entity];
		$srcfile = $dir . '/SPECIMEN.pdf';
		$destfile = $dir . '/' . dol_sanitizeFileName($tmpinvoice->ref) . '.pdf';

		dol_move($srcfile, $destfile, '0', 1);


		// Generate CII xml file
		$pathOfXml = $this->generateInvoice($tmpinvoice, $outputlangs);

		// Restore switched variables if we changed $mysoc for generation of the sample invoice
		if (!empty($savmysoc)) {
			$mysoc = $savmysoc;
			$conf->global->$keyforconst = $savPDPCONNECTFR_ROUTING_ID;

			$savmysoc = null;
			$savPDPCONNECTFR_ROUTING_ID = null;
		}

		// Restore name SPECIMEN.pdf
		dol_move($destfile, $srcfile, '0', 1);

		// Move CII xml file into the temp directory
		if (is_numeric($pathOfXml) && $pathOfXml < 0) {
			return $pathOfXml;
		} else {
			$newPathOfXml = dirname($pathOfXml) . '/temp/' . basename($pathOfXml);
			dol_move($pathOfXml, $newPathOfXml, '0', 1);

			return array('path' => $newPathOfXml, 'ref' => $tmpinvoice->ref);
		}
	}

	/**
	 * Generate a sample CII invoice for demonstration or testing purposes (for Dolibarr version < 24.0)
	 *
	 * This method creates a dummy invoice with representative data
	 * to illustrate the CII structure without using real business information.
	 *
	 * @param	PdpConnectFr			$pdpconnectfr			PDPConnectFR
	 * @param   Societe|null			$thirdpartySeller		Optional third party object to use for generating the sample invoice. If null, a dummy third party will be created.
	 * @param   Societe|null			$thirdpartyBuyer		Optional third party object to use for generating the sample invoice. If null, a dummy third party will be created.
	 * @param   array<string,mixed>		$options				More options
	 * @return 	array<string,string> 							Path or content of the generated sample invoice.
	 * @throws  Exception
	 */
	public function generateSampleInvoiceOld($pdpconnectfr, $thirdpartySeller = null, $thirdpartyBuyer = null, $options = array())
	{
		return array('path' => '', 'ref' => ''); // Not yet implemented
	}


	/**
	 * Create a supplier invoice from a CII file and attach the file (and readable file if exists) to the document.
	 * This may create the Supplier and the Product depending on setup.
	 *
	 * @param  string 			$file                       		Source string file. We use this file to get data of supplier invoice.
	 * @param  string|null 		$ReadableViewFile        			Readable view file (PDP Generated readable PDF).e only store it if available.
	 * @param  string 			$flowId                       		Flow identifier source of the invoice.
	 * @return array{res:int, message:string, actioncode: string|null, actionurl: string|null, action:string|null}   Returns array with 'res' (1 on success, 0 already exists, -1 on failure) with a 'message' and an optional 'actioncode' and 'action'.
	 */
	public function createSupplierInvoiceFromSource($file, $ReadableViewFile = null, $flowId = '')
	{
		global $conf, $db, $user;

		$pdpconnectfr = new PdpConnectFr($db);
		$return_messages = array();

		// Save uploaded file to temporary directory
		$tempDir = $conf->pdpconnectfr->dir_temp;
		if (!dol_is_dir($tempDir)) {
			dol_mkdir($tempDir);
		}

		// If tmp dir in not empty, clean it
		$files = scandir($tempDir);
		foreach ($files as $f) {
			if ($f != '.' && $f != '..') {
				dol_delete_file($tempDir . '/' . $f);
			}
		}

		$tempFile = $tempDir . '/einvoice.xml';
		if (file_put_contents($tempFile, $file) === false) {
			return ['res' => -1, 'message' => 'Failed to save CII file to temporary location'];
		}

		if ($ReadableViewFile) {
			$tempFileReadableView = $tempDir . '/einvoice_readable.pdf';
			if (file_put_contents($tempFileReadableView, $ReadableViewFile) === false) {
				return ['res' => -1, 'message' => 'Failed to save readable view file to temporary location'];
			}
		}

		// --- Create Supplier Invoice object
		require_once DOL_DOCUMENT_ROOT . '/fourn/class/fournisseur.facture.class.php';
		$supplierInvoice = new FactureFournisseur($db);


		// Read using native parser
		$parsedHeader = $this->parseInvoiceXML($file);
		$parsedLines = $this->parseInvoiceLines($file);

		// Check if this invoice has already been imported
		$sql = "SELECT rowid as id FROM " . MAIN_DB_PREFIX . "facture_fourn";
		$sql .= " WHERE ref_supplier = '" . $db->escape($parsedHeader['documentno']) . "'";
		$resql = $db->query($sql);
		if ($resql) {
			if ($db->num_rows($resql) > 0) {
				$supplierInvoiceId = $db->fetch_object($resql)->id;
				$pdpconnectfr->cleanUpTemporaryFiles(); // Clean up temp files to remove retrieved Einvoice file since invoice already exists

				// FIXME supplierinvoice already found but may be that documents are not linked (this is done later but only after creating invoice,
				// may be we should also do it in this case to fix inconsistent data).

				return ['res' => $supplierInvoiceId, 'message' => 'Supplier Invoice with reference ' . $parsedHeader['documentno'] . ' already exists'];
			}
		} else {
			return ['res' => -1, 'message' => 'Database error while checking existing supplier invoice: ' . $db->lasterror()];
		}

		// Check if all referenced documents in the invoice exist in Dolibarr, if not return with error since we need them for correct linking in the invoice
		if (!empty($parsedHeader['invoiceRefDocs']) && is_array($parsedHeader['invoiceRefDocs'])) {
			foreach ($parsedHeader['invoiceRefDocs'] as $invoiceRefDoc) {
				$refDoc = $invoiceRefDoc['IssuerAssignedID'] ?? null;
				$dateDoc = $invoiceRefDoc['FormattedIssueDateTime'] ?? null;
				$typeDoc = $invoiceRefDoc['TypeCode'] ?? null;

				$sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "facture_fourn WHERE ref_supplier = '" . $db->escape($refDoc) . "' LIMIT 1";
				$resql = $db->query($sql);
				if ($db->num_rows($resql) != 1) {
					return ['res' => -1, 'message' => 'Document : ' . $refDoc . ' linked to document ' . $parsedHeader['documentno'] . ' not found in Dolibarr'];
				}
			}
		}

		dol_syslog(get_class($this) . '::createSupplierInvoiceFromSource parsedHeader: ' . json_encode($parsedHeader), LOG_DEBUG);

		// Sync or create supplier based on seller info
		$syncSocRes = $this->_syncOrCreateThirdpartyFromEInvoiceSeller($parsedHeader, 'dolibarr', $flowId);
		$socId = $syncSocRes['res'];
		$return_messages[] = $syncSocRes['message'];
		if ($socId < 0) {
			return [
				'res' => -1,
				'message' => 'Thirdparty sync or creation error: ' . implode("\n", $return_messages),
				'actioncode' => $syncSocRes['actioncode'] ?? '',
				'actionurl' => $syncSocRes['actionurl'] ?? '',
				'action' => $syncSocRes['action'] ?? null
			];
		}

		// Load supplier (thirdparty)
		require_once DOL_DOCUMENT_ROOT . '/fourn/class/fournisseur.class.php';
		$supplier = new Fournisseur($db);
		if ($supplier->fetch($socId) < 0) {
			return ['res' => -1, 'message' => 'Failed to load supplier id ' . $socId];
		}

		// Set supplier reference
		$supplierInvoice->socid = $socId;

		// Set basic invoice information
		$supplierInvoice->ref_supplier = $parsedHeader['documentno'] ?? null;
		$supplierInvoice->type = $this->_getDolibarrInvoiceType($parsedHeader['documenttypecode'] ?? null);
		if ($supplierInvoice->type === '-1') {
			return ['res' => -1, 'message' => 'Unfounded dolibarr corresponding Invoice code for document type code: ' . ($parsedHeader['documenttypecode'] ?? 'NA')];
		}
		$supplierInvoice->date = isset($parsedHeader['documentdate']) && $parsedHeader['documentdate'] instanceof DateTime ? $parsedHeader['documentdate']->format('Y-m-d') : null;


		// Set currency
		$supplierInvoice->multicurrency_code = $parsedHeader['invoiceCurrency'];

		// Set import_key
		$supplierInvoice->import_key = AbstractPDPProvider::$PDPCONNECTFR_LAST_IMPORT_KEY;


		$remise_already_used_line_level_ids = array();

		// Add invoice lines
		foreach ($parsedLines as $parsedLine) {
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

					$sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "facture_fourn WHERE ref_supplier = '" . $db->escape($lineRefDocId) . "' LIMIT 1";
					$resql = $db->query($sql);
					if ($db->num_rows($resql) != 1) {
						return [
							'res' => -1,
							'message' => 'Document "' . $lineRefDocId . '" linked to line ' . $parsedLine['lineid'] . ' was not found in Dolibarr. Please verify why this document is missing (deleted, not imported, or not provided by the supplier). To resolve this issue, you must manually create the invoice using the supplier invoice reference "' . $lineRefDocId . '".'
						];
						// TODO: Add a check before sending a final invoice after deposit to ensure that the deposit invoice has been properly sent to the PDP and successfully received.
					}

					// Load linked supplier invoice
					$linkedObject = new FactureFournisseur($db);
					$linkedObjectId = $db->fetch_object($resql)->rowid;
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

							// Check if deposit line is already converted to a reduction otherwise we convert it
							//require_once DOL_DOCUMENT_ROOT.'/core/class/discount.class.php';
							$discountcheck = new DiscountAbsolute($db);
							$result = $discountcheck->fetch(0, 0, $linkedObject->id);
							if ($result <= 0) {
								// Loop on each vat rate
								$amount_ht = $amount_tva = $amount_ttc = array();
								$multicurrency_amount_ht = $multicurrency_amount_tva = $multicurrency_amount_ttc = array();
								$i = 0;
								foreach ($linkedObject->lines as $line) {
									if ($line->product_type < 9 && $line->total_ht != 0) { // Remove lines with product_type greater than or equal to 9 and no need to create discount if amount is null
										$keyforvatrate = $line->tva_tx . ($line->vat_src_code ? ' (' . $line->vat_src_code . ')' : '');

										$amount_ht[$keyforvatrate] += $line->total_ht;
										$amount_tva[$keyforvatrate] += $line->total_tva;
										$amount_ttc[$keyforvatrate] += $line->total_ttc;
										$multicurrency_amount_ht[$keyforvatrate] += $line->multicurrency_total_ht;
										$multicurrency_amount_tva[$keyforvatrate] += $line->multicurrency_total_tva;
										$multicurrency_amount_ttc[$keyforvatrate] += $line->multicurrency_total_ttc;
										$i++;
									}
								}

								$discount = new DiscountAbsolute($db);
								$discount->description = '(DEPOSIT)';
								$discount->discount_type = 1; // Supplier discount
								$discount->fk_soc = $linkedObject->socid;
								$discount->socid = $linkedObject->socid;
								$discount->fk_invoice_supplier_source = $linkedObject->id;
								foreach ($amount_ht as $tva_tx => $xxx) {
									$discount->amount_ht = abs((float) $amount_ht[$tva_tx]);
									$discount->amount_tva = abs((float) $amount_tva[$tva_tx]);
									$discount->amount_ttc = abs((float) $amount_ttc[$tva_tx]);
									$discount->multicurrency_amount_ht = abs((float) $multicurrency_amount_ht[$tva_tx]);
									$discount->multicurrency_amount_tva = abs((float) $multicurrency_amount_tva[$tva_tx]);
									$discount->multicurrency_amount_ttc = abs((float) $multicurrency_amount_ttc[$tva_tx]);

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
										break;
									}
									$fk_remise = $result;
								}
							} else {
								// Deposit already converted so reuse existing discount
								$is_deposit_line = 1;
								$fk_remise = $discountcheck->id;
							}
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
			if (!$is_deposit_line) {
				// Sync or create product
				$res = $this->_findOrCreateProductFromEinvoiceLine($parsedLine, $flowId);
				if ($res['res'] < 0) {
					return [
						'res' => -1,
						'message' => 'Product sync or creation error: ' . $res['message'],
						'actioncode' => $res['actioncode'] ?? '',
						'actionurl' => $res['actionurl'] ?? '',
						'action' => $res['action'] ?? null
					];
				}
				$productId = $res['res'];
			}


			// Add line to invoice
			$line = new SupplierInvoiceLine($db);
			//$line->desc = $prodname . (!empty($proddesc) ? "\n" . $proddesc : '');
			if (!empty($productId)) {
				$line->fk_product = $productId;
			}
			if ($is_deposit_line && !empty($fk_remise)) {
				$line->fk_remise_except = $fk_remise;
				$line->info_bits = 2;
				$line->desc = '(DEPOSIT)';
				$line->rang = -1;

				$remise_already_used_line_level_ids[] = $fk_remise;
			}
			$line->qty = $parsedLine['billedquantity'];
			$line->subprice = $parsedLine['netpriceamount'];
			$line->tva_tx = $parsedLine['rateApplicablePercent'];
			$line->total_ht = $parsedLine['lineTotalAmount'];
			$line->total_tva = $parsedLine['calculatedAmount'] ?? 0;
			$line->total_ttc = $parsedLine['lineTotalAmount'] + ($parsedLine['calculatedAmount'] ?? 0);

			$supplierInvoice->lines[] = $line;
		}

		//return ['res' => 1, 'message' => 'Not implemented yet' ];

		// Set invoice totals
		$supplierInvoice->total_ht = $parsedHeader['taxBasisTotalAmount'] ?? 0;
		$supplierInvoice->total_tva = $parsedHeader['taxTotalAmount'] ?? 0;
		$supplierInvoice->total_ttc = $parsedHeader['grandTotalAmount'] ?? 0;

		// Add a note about PDP import ( TODO: add a hook or extrafields to store import details)
		$supplierInvoice->note_private = "Imported from PDP";

		// TODO : save AAB, PMD, PMT notes ( all notes are grouped into documentNotes)

		// Create the invoice
		$supplierInvoiceId = $supplierInvoice->create($user);

		if ($supplierInvoiceId < 0) {
			return ['res' => -1, 'message' => 'Invoice creation error: ' . $supplierInvoice->error];
		} else {
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

					$sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "facture_fourn WHERE ref_supplier = '" . $db->escape($lineRefDocId) . "' LIMIT 1";
					$resql = $db->query($sql);
					if ($db->num_rows($resql) != 1) {
						return ['res' => -1, 'message' => 'Document : ' . $refDoc . ' linked to document ' . $parsedHeader['documentno'] . ' not found in Dolibarr'];
					}
					$linkedObjectId = $db->fetch_object($resql)->rowid;

					// Fetch Object
					$linkedObject = new FactureFournisseur($db);
					$resFetchLinkedObject = $linkedObject->fetch($linkedObjectId);
					if ($resFetchLinkedObject > 0) {
						// --------------------------------------------------
						// Deposit handling
						// --------------------------------------------------
						if ($linkedObject->type == FactureFournisseur::TYPE_DEPOSIT) {
							$create_deposit_line = 1;

							// Check if deposit line is already converted to a reduction otherwise we convert it
							//require_once DOL_DOCUMENT_ROOT.'/core/class/discount.class.php';
							$discountcheck = new DiscountAbsolute($db);
							$result = $discountcheck->fetch(0, 0, $linkedObject->id);
							if ($result <= 0) {
								// Loop on each vat rate
								$amount_ht = $amount_tva = $amount_ttc = array();
								$multicurrency_amount_ht = $multicurrency_amount_tva = $multicurrency_amount_ttc = array();
								$i = 0;
								foreach ($linkedObject->lines as $line) {
									if ($line->product_type < 9 && $line->total_ht != 0) { // Remove lines with product_type greater than or equal to 9 and no need to create discount if amount is null
										$keyforvatrate = $line->tva_tx . ($line->vat_src_code ? ' (' . $line->vat_src_code . ')' : '');

										$amount_ht[$keyforvatrate] += $line->total_ht;
										$amount_tva[$keyforvatrate] += $line->total_tva;
										$amount_ttc[$keyforvatrate] += $line->total_ttc;
										$multicurrency_amount_ht[$keyforvatrate] += $line->multicurrency_total_ht;
										$multicurrency_amount_tva[$keyforvatrate] += $line->multicurrency_total_tva;
										$multicurrency_amount_ttc[$keyforvatrate] += $line->multicurrency_total_ttc;
										$i++;
									}
								}

								$discount = new DiscountAbsolute($db);
								$discount->description = '(DEPOSIT)';
								$discount->discount_type = 1; // Supplier discount
								$discount->fk_soc = $linkedObject->socid;
								$discount->socid = $linkedObject->socid;
								$discount->fk_invoice_supplier_source = $linkedObject->id;
								foreach ($amount_ht as $tva_tx => $xxx) {
									$discount->amount_ht = abs((float) $amount_ht[$tva_tx]);
									$discount->amount_tva = abs((float) $amount_tva[$tva_tx]);
									$discount->amount_ttc = abs((float) $amount_ttc[$tva_tx]);
									$discount->multicurrency_amount_ht = abs((float) $multicurrency_amount_ht[$tva_tx]);
									$discount->multicurrency_amount_tva = abs((float) $multicurrency_amount_tva[$tva_tx]);
									$discount->multicurrency_amount_ttc = abs((float) $multicurrency_amount_ttc[$tva_tx]);

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
										break;
									}
									$fk_remise_for_deposit = $result;
								}
							} else {
								// Deposit already converted so reuse existing discount
								$create_deposit_line = 1;
								$fk_remise_for_deposit = $discountcheck->id;
							}

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

			// TODO : Add supplier price for products (all lines of the invoice)

			// Set import_key
			$sql = 'UPDATE ' . MAIN_DB_PREFIX . "facture_fourn SET import_key = '" . $db->escape($supplierInvoice->import_key) . "'";
			$sql .= " WHERE rowid = " . ((int) $supplierInvoiceId);
			$db->query($sql);

			// Add entry in pdpconnectfr_extlinks table to mark that this supplier invoice is imported from PDP
			$pdpconnectfr->insertOrUpdateExtLink($supplierInvoiceId, $supplierInvoice->element, $flowId);

			dol_syslog(__METHOD__ . ' New supplier invoice created or updated (ID: ' . $supplierInvoiceId . ')');

			$return_messages[] = 'Supplier Invoice created or updated with ID: ' . $supplierInvoiceId;


			// Save original invoice in supplier invoice attachments
			if ($tempFile && file_exists($tempFile)) {
				$res = $this->_saveEInvoiceFileToSupplierInvoiceAttachment($supplierInvoice, $tempFile);

				if ($res['res'] < 0) {
					$return_messages[] = 'Failed to save Einvoice file as attachment: ' . $res['message'];
				} else {
					$return_messages[] = 'Einvoice file saved as attachment';
				}
			} else {
				dol_syslog("Temporary 'converted pdf file' not found for attachment", LOG_ERR);
			}


			// Save readable view file in supplier invoice attachments
			if ($ReadableViewFile && $tempFileReadableView && file_exists($tempFileReadableView)) {
				$res = $this->_saveEInvoiceFileToSupplierInvoiceAttachment($supplierInvoice, $tempFileReadableView, getDolGlobalString('PDPCONNECTFR_PDP', 'PDP'));

				if ($res['res'] < 0) {
					$return_messages[] = 'Failed to save readable view file as attachment: ' . $res['message'];
				} else {
					$return_messages[] = 'Readable view file saved as attachment';
				}
			} else {
				dol_syslog("Temporary 'readable pdf file' not found for attachment", LOG_ERR);
			}

			// TODO : Save receivedFile in supplier invoice attachments
			return ['res' => $supplierInvoiceId, 'message' => implode("\n", $return_messages)];
		}
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
	 * @return string|null  YYYY-MM-DD or null if input is null/empty/unparseable
	 */
	private function normDate(?string $raw): ?string
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
	private function toFloat(?string $v): ?float
	{
		if ($v === null || $v === '')
			return null;
		$v = str_replace(',', '.', trim($v));
		return is_numeric($v) ? (float) $v : null;
	}


	/**
	 * Parse the invoice header from CII XML.
	 *
	 * Special prefixes in $invoiceTemplate:
	 *   '__MULTI__<xpath>'     → returns array of child node data
	 *   '__ATTRPAIRS__<xpath>' → returns ['schemeID' => 'value', …]
	 *
	 * @param  string $xml Raw XML content
	 * @return array<string,mixed>
	 */
	public function parseInvoiceXML($xml)
	{
		list(, $xpath) = $this->initXPath($xml);

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
	 * @param  string $xml Raw XML content
	 * @return array<int,array<string,mixed>>
	 */
	public function parseInvoiceLines($xml)
	{
		list(, $xpath) = $this->initXPath($xml);

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
						'exemptionReason' => $this->getXPathValue($xpath, 'ram:ExemptionReason', $n),
						'exemptionReasonCode' => $this->getXPathValue($xpath, 'ram:ExemptionReasonCode', $n),
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
	 * Build CII XML from invoice data.
	 *
	 * @param array 		$invoiceData 	Header-level invoice data
	 * @param array 		$linesData 		Array of line-level data arrays
	 * @param string 		$profile 		Profile ('MINIMUM', 'BASICWL', 'BASIC', 'EN16931', 'EXTENDED')
	 *
	 * @return string Generated XML content
	 */
	public function buildXML(array $invoiceData, array $linesData, $profile = '')
	{
		$doc = new \DOMDocument('1.0', 'UTF-8');
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

		// Context
		$ctx = $doc->createElement('rsm:ExchangedDocumentContext');
		$root->appendChild($ctx);

		// BusinessProcessId
		if (!empty($invoiceData['businessProcessId'])) {
			$bp = $doc->createElement('ram:BusinessProcessSpecifiedDocumentContextParameter');
			$ctx->appendChild($bp);
			$bp->appendChild($doc->createElement('ram:ID', $invoiceData['businessProcessId']));
		}

		$profile = !empty($profile) ? strtoupper($profile) : 'EXTENDED';

		$guideline = $doc->createElement('ram:GuidelineSpecifiedDocumentContextParameter');
		$ctx->appendChild($guideline);

		$profileGuidelines = [
			'MINIMUM'  => 'urn:factur-x.eu:1p0:minimum', // Factur-X profile
			'BASICWL'  => 'urn:factur-x.eu:1p0:basicwl', // Factur-X profile
			'BASIC'    => 'urn:factur-x.eu:1p0:basic', // Factur-X profile
			'EN16931'  => 'urn:cen.eu:en16931:2017', // CII Profile
			'EXTENDED' => 'urn:cen.eu:en16931:2017#conformant#urn:factur-x.eu:1p0:extended', // Factur-X profile
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

		$exDoc->appendChild($doc->createElement('ram:ID', $invoiceData['documentno']));
		$exDoc->appendChild($doc->createElement('ram:TypeCode', $invoiceData['documenttypecode']));

		// Date
		$issueDT = $doc->createElement('ram:IssueDateTime');
		$exDoc->appendChild($issueDT);

		$dt = $doc->createElement(
			'udt:DateTimeString',
			$invoiceData['documentdate']->format('Ymd')
		);
		$dt->setAttribute('format', '102');
		$issueDT->appendChild($dt);

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

		// Transaction
		$sctt = $doc->createElement('rsm:SupplyChainTradeTransaction');
		$root->appendChild($sctt);

		// LINES
		foreach ($linesData as $line) {
			$sctt->appendChild($this->buildLineItem($doc, $line, $profile));
		}

		// SELLER / BUYER
		$agreement = $doc->createElement('ram:ApplicableHeaderTradeAgreement');
		$sctt->appendChild($agreement);

		$this->buildParty($doc, $agreement, $invoiceData, 'seller');
		$this->buildParty($doc, $agreement, $invoiceData, 'buyer');

		// DELIVERY
		$delivery = $doc->createElement('ram:ApplicableHeaderTradeDelivery');
		$sctt->appendChild($delivery);

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

		// SETTLEMENT
		$settlement = $doc->createElement('ram:ApplicableHeaderTradeSettlement');
		$sctt->appendChild($settlement);

		// Currency
		$settlement->appendChild($doc->createElement(
			'ram:InvoiceCurrencyCode',
			$invoiceData['invoiceCurrency']
		));

		// Payment means
		$pm = $doc->createElement('ram:SpecifiedTradeSettlementPaymentMeans');
		$settlement->appendChild($pm);

		$pm->appendChild($doc->createElement('ram:TypeCode', $invoiceData['paymentMeansCode']));
		$pm->appendChild($doc->createElement('ram:Information', $invoiceData['paymentMeansText']));

		$acc = $doc->createElement('ram:PayeePartyCreditorFinancialAccount');
		$pm->appendChild($acc);

		$acc->appendChild($doc->createElement('ram:AccountName', $invoiceData['accountName']));

		// TVA
		foreach ($invoiceData['taxBreakdown'] as $rate => $vals) {
			$settlement->appendChild(
				$this->buildTaxNode($doc, $rate, $vals, $invoiceData['invoiceCurrency'])
			);
		}

		$terms = $doc->createElement('ram:SpecifiedTradePaymentTerms');
		$settlement->appendChild($terms);

		$terms->appendChild($doc->createElement('ram:Description', $invoiceData['paymentTermsText']));

		$dtNode = $doc->createElement('ram:DueDateDateTime');
		$str = $doc->createElement('udt:DateTimeString', $invoiceData['paymentDueDate']->format('Ymd'));
		$str->setAttribute('format', '102');
		$dtNode->appendChild($str);

		$terms->appendChild($dtNode);

		// Totals
		$sum = $doc->createElement('ram:SpecifiedTradeSettlementHeaderMonetarySummation');
		$settlement->appendChild($sum);

		$sum->appendChild($doc->createElement('ram:LineTotalAmount', number_format($invoiceData['lineTotalAmount'], 2, '.', '')));
		$sum->appendChild($doc->createElement('ram:ChargeTotalAmount', number_format($invoiceData['chargeTotalAmount'], 2, '.', '')));
		$sum->appendChild($doc->createElement('ram:AllowanceTotalAmount', number_format($invoiceData['allowanceTotalAmount'], 2, '.', '')));
		$sum->appendChild($doc->createElement('ram:TaxBasisTotalAmount', number_format($invoiceData['taxBasisTotalAmount'], 2, '.', '')));

		$taxTotal = $doc->createElement('ram:TaxTotalAmount', number_format($invoiceData['taxTotalAmount'], 2, '.', ''));
		$taxTotal->setAttribute('currencyID', $invoiceData['invoiceCurrency']);
		$sum->appendChild($taxTotal);

		$sum->appendChild($doc->createElement('ram:GrandTotalAmount', number_format($invoiceData['grandTotalAmount'], 2, '.', '')));
		$sum->appendChild($doc->createElement('ram:TotalPrepaidAmount', number_format($invoiceData['totalPrepaidAmount'], 2, '.', '')));
		$sum->appendChild($doc->createElement('ram:DuePayableAmount', number_format($invoiceData['duePayableAmount'], 2, '.', '')));

		// Referenced documents
		if (!empty($invoiceData['invoiceRefDocs'])) {
			foreach ($invoiceData['invoiceRefDocs'] as $refDoc) {
				$refNode = $doc->createElement('ram:InvoiceReferencedDocument');

				$refNode->appendChild($doc->createElement('ram:IssuerAssignedID', $refDoc['ref']));
				if ($profile === 'EXTENDED') {
					$refNode->appendChild($doc->createElement('ram:TypeCode', $refDoc['type']));
				}

				if (!empty($refDoc['date']) && $profile === 'EXTENDED') {
					$dateNode = $doc->createElement('ram:FormattedIssueDateTime');
					$str = $doc->createElement('qdt:DateTimeString', $refDoc['date']->format('Ymd'));
					$str->setAttribute('format', '102');
					$dateNode->appendChild($str);
					$refNode->appendChild($dateNode);
				}

				$settlement->appendChild($refNode);
			}
		}
		$xml = $doc->saveXML();

		return $xml;
	}

	/**
	 * Build a single line item node.
	 *
	 * @param \DOMDocument 		$doc		Document to create nodes in
	 * @param array 			$line 		Line data
	 * @param string 			$profile 	Profile (used to conditionally include certain nodes)
	 *
	 * @return \DOMElement
	 */
	private function buildLineItem(\DOMDocument $doc, array $line, string $profile)
	{
		$el = $doc->createElement('ram:IncludedSupplyChainTradeLineItem');

		// ID
		$docLine = $doc->createElement('ram:AssociatedDocumentLineDocument');
		$el->appendChild($docLine);
		$docLine->appendChild($doc->createElement('ram:LineID', $line['lineid']));

		// Product
		$prod = $doc->createElement('ram:SpecifiedTradeProduct');
		$el->appendChild($prod);
		if (!empty($line['prodsellerid'])) {
			$prod->appendChild(
				$doc->createElement('ram:SellerAssignedID', $line['prodsellerid'])
			);
		}
		$prod->appendChild($doc->createElement('ram:Name', htmlspecialchars($line['prodname'])));
		if (!empty($line['proddesc'])) {
			$prod->appendChild($doc->createElement('ram:Description', htmlspecialchars($line['proddesc'])));
		}

		// Price
		$price = $doc->createElement('ram:SpecifiedLineTradeAgreement');
		$el->appendChild($price);

		$gross = $doc->createElement('ram:GrossPriceProductTradePrice');
		$price->appendChild($gross);
		$gross->appendChild($doc->createElement('ram:ChargeAmount', number_format($line['grosspriceamount'], 2, '.', '')));

		$net = $doc->createElement('ram:NetPriceProductTradePrice');
		$price->appendChild($net);
		$net->appendChild($doc->createElement('ram:ChargeAmount', number_format($line['netpriceamount'], 2, '.', '')));

		// Quantity
		$deliv = $doc->createElement('ram:SpecifiedLineTradeDelivery');
		$el->appendChild($deliv);

		$qty = $doc->createElement('ram:BilledQuantity', number_format($line['billedquantity'], 2, '.', ''));
		$qty->setAttribute('unitCode', $line['billedquantityunitcode']);
		$deliv->appendChild($qty);

		// VAT
		$sett = $doc->createElement('ram:SpecifiedLineTradeSettlement');
		$el->appendChild($sett);

		$tax = $doc->createElement('ram:ApplicableTradeTax');
		$sett->appendChild($tax);

		$tax->appendChild($doc->createElement('ram:TypeCode', 'VAT'));
		$tax->appendChild($doc->createElement('ram:CategoryCode', $line['categoryCode']));
		$tax->appendChild($doc->createElement('ram:RateApplicablePercent', $line['rateApplicablePercent']));

		// Total line
		$sum = $doc->createElement('ram:SpecifiedTradeSettlementLineMonetarySummation');
		$sett->appendChild($sum);
		$sum->appendChild($doc->createElement('ram:LineTotalAmount', number_format($line['lineTotalAmount'], 2, '.', '')));

		// Ref doc for deposit line
		if (!empty($line['isDepositLine'])) {
			$refNode = $doc->createElement('ram:AdditionalReferencedDocument');

			$refNode->appendChild($doc->createElement('ram:IssuerAssignedID', $line['depositInvoiceRef']));
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
	 *
	 * @return void
	 */
	private function buildParty($doc, $agreement, $data, $type)
	{
		$tag = $type === 'seller' ? 'ram:SellerTradeParty' : 'ram:BuyerTradeParty';
		$node = $doc->createElement($tag);
		$agreement->appendChild($node);

		$prefix = $type;

		$node->appendChild($doc->createElement('ram:ID', $data[$prefix . 'ids']));

		// GlobalID
		if (!empty($data[$prefix . 'GlobalIds'])) {
			foreach ($data[$prefix . 'GlobalIds'] as $globalId) {
				$g = $doc->createElement('ram:GlobalID', $globalId['value']);
				$g->setAttribute('schemeID', $globalId['schemeID']);
				$node->appendChild($g);
			}
		}

		$node->appendChild($doc->createElement('ram:Name', htmlspecialchars($data[$prefix . 'name'])));

		// Legal org
		$legal = $doc->createElement('ram:SpecifiedLegalOrganization');
		$node->appendChild($legal);
		$id = $doc->createElement('ram:ID', $data[$prefix . 'LegalOrgId']);
		$id->setAttribute('schemeID', $data[$prefix . 'LegalOrgScheme']);
		$legal->appendChild($id);
		$legal->appendChild(
			$doc->createElement('ram:TradingBusinessName', $data[$prefix . 'TradingName'])
		);

		// Contact
		if (!empty($data[$prefix . 'contactpersonname'])) {
			$contact = $doc->createElement('ram:DefinedTradeContact');
			$node->appendChild($contact);
			$contact->appendChild($doc->createElement('ram:PersonName', htmlspecialchars($data[$prefix . 'contactpersonname'])));
		}

		if (!empty($data[$prefix . 'contactdepartmentname'])) {
			$contact->appendChild($doc->createElement('ram:DepartmentName', htmlspecialchars($data[$prefix . 'contactdepartmentname'])));
		}

		if (!empty($data[$prefix . 'contactphoneno'])) {
			$phone = $doc->createElement('ram:TelephoneUniversalCommunication');
			$contact->appendChild($phone);
			$phone->appendChild($doc->createElement('ram:CompleteNumber', $data[$prefix . 'contactphoneno']));
		}

		if (!empty($data[$prefix . 'contactfaxno'])) {
			$fax = $doc->createElement('ram:FaxUniversalCommunication');
			$contact->appendChild($fax);
			$fax->appendChild($doc->createElement('ram:CompleteNumber', $data[$prefix . 'contactfaxno']));
		}

		if (!empty($data[$prefix . 'contactemailaddr'])) {
			$email = $doc->createElement('ram:EmailURIUniversalCommunication');
			$contact->appendChild($email);
			$email->appendChild($doc->createElement('ram:URIID', $data[$prefix . 'contactemailaddr']));
		}


		// Address
		$addr = $doc->createElement('ram:PostalTradeAddress');
		$node->appendChild($addr);

		$addr->appendChild($doc->createElement('ram:PostcodeCode', $data[$prefix . 'postcode']));
		$addr->appendChild($doc->createElement('ram:LineOne', htmlspecialchars($data[$prefix . 'lineone'])));
		$addr->appendChild($doc->createElement('ram:CityName', htmlspecialchars($data[$prefix . 'city'])));
		$addr->appendChild($doc->createElement('ram:CountryID', $data[$prefix . 'country']));

		// URIUniversalCommunication
		if (!empty($data[$prefix . 'CommunicationUriScheme']) && !empty($data[$prefix . 'CommunicationUri'])) {
			$uri = $doc->createElement('ram:URIUniversalCommunication');
			$node->appendChild($uri);
			$uriid = $doc->createElement('ram:URIID', $data[$prefix . 'CommunicationUri']);
			$uriid->setAttribute('schemeID', $data[$prefix . 'CommunicationUriScheme']);
			$uri->appendChild($uriid);
		}

		// VAT
		if (!empty($data[$prefix . 'vatnumber'])) {
			$tax = $doc->createElement('ram:SpecifiedTaxRegistration');
			$id = $doc->createElement('ram:ID', $data[$prefix . 'vatnumber']);
			$id->setAttribute('schemeID', 'VA');
			$tax->appendChild($id);
			$node->appendChild($tax);
		}
	}

	/**
	 * Build a tax node.
	 *
	 * @param \DOMDocument $doc 		Document to create nodes in
	 * @param float        $rate 		Tax rate
	 * @param array        $vals 		Array containing tax values
	 * @param string       $currency 	Currency code
	 *
	 * @return \DOMElement
	 */
	private function buildTaxNode($doc, $rate, $vals, $currency)
	{
		$tax = $doc->createElement('ram:ApplicableTradeTax');

		$tax->appendChild($doc->createElement('ram:CalculatedAmount', number_format($vals['totalTVA'], 2, '.', '')));

		$tax->appendChild($doc->createElement('ram:TypeCode', 'VAT'));

		$tax->appendChild($doc->createElement('ram:BasisAmount', number_format($vals['totalHT'], 2, '.', '')));

		$tax->appendChild($doc->createElement('ram:CategoryCode', $rate > 0 ? 'S' : 'Z'));
		$tax->appendChild($doc->createElement('ram:RateApplicablePercent', number_format($rate, 2, '.', '')));

		return $tax;
	}


	// =====================================================================
	// Common methods with FacturX class
	// =====================================================================

	/**
	 * Synchronize or create a Dolibarr thirdparty based on E-invoice seller information.
	 *
	 * @param array     $sellerInfo Array containing seller information extracted from E-invoice
	 * @param string    $priority Fill priority ('dolibarr' or 'pdp'). If both data are available, which one to prefer
	 * @param string    $flowId Flow identifier source of the thirdparty.
	 *
	 * @return array{res:int, message:string, actioncode:string|null, actionurl:string|null, action:string|null}   Returns array with 'res' (ID of the synchronized or created thirdparty, -1 on error) with a 'message' and an optional 'actioncode', 'actionurl', and 'action'.
	 */
	private function _syncOrCreateThirdpartyFromEInvoiceSeller($sellerInfo, $priority = 'dolibarr', $flowId = '')
	{
		/**
		 * Scenario to find or create a thirdparty based on E-invoice seller information:
		 *
		 * 1. Try to find thirdparty by global IDs (SIREN, VAT number ...)
		 * 1.1 If found, update thirdparty information with provided data
		 *
		 * 2. If not found, try to find thirdparty by closest match (findNearest)
		 * 2.1 If found one match, update thirdparty information with provided data
		 * 2.2 If found multiple matches, log warning and return error
		 *
		 * 3. If still not found, create new thirdparty with provided data
		 */
		global $db, $langs, $user;
		require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';

		$thirdparty = new Societe($db);
		$pdpconnectfr = new PdpConnectFr($db);
		$thirdpartyId = -1;

		// Step 1: Try to find thirdparty by global IDs
		if (!empty($sellerInfo['sellerGlobalIds']) && is_array($sellerInfo['sellerGlobalIds'])) {
			foreach ($sellerInfo['sellerGlobalIds'] as $idScheme => $globalId) {
				if (!empty($globalId)) {
					// Map scheme to idprof field (0002 = SIREN)
					// TODO Use function idprof() ?
					$idprofField = $this->_mapGlobalIdSchemeToIdprof($idScheme);
					if (!empty($idprofField)) {
						$result = 0;
						// Fetch thirdparty by corresponding idprof field
						if ($idprofField === 'idprof1') { // SIREN
							$result = $thirdparty->fetch(0, '', '', '', $globalId);
						}
						// TODO: Add more idprof fields mapping if needed

						if ($result > 0) {
							$thirdpartyId = $thirdparty->id;
							dol_syslog(get_class($this) . '::_syncOrCreateThirdpartyFromEInvoiceSeller Found thirdparty by ' . $idScheme . ': ' . $thirdpartyId);
							break;
						}
					}
				}
			}
		}
		if ($thirdpartyId < 0) {
			// Try to find by VAT number if provided
			if (!empty($sellerInfo['sellerTaxRegistations']['VA'])) {
				$sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "societe WHERE REPLACE(tva_intra, ' ', '') = '" . $db->escape($pdpconnectfr->removeSpaces($sellerInfo['sellerTaxRegistations']['VA'])) . "'";
				$resql = $db->query($sql);
				if ($resql) {
					if ($db->num_rows($resql) > 1) {
						dol_syslog(get_class($this) . '::_syncOrCreateThirdpartyFromEInvoiceSeller Error: Multiple thirdparties found for VAT number: ' . $sellerInfo['sellerTaxRegistations']['VA'], LOG_ERR);
						return array(
							'res' => -1,
							'message' => 'Multiple thirdparties found for VAT number: ' . $sellerInfo['sellerTaxRegistations']['VA'],
							'actioncode' => 'DUPLICATE_THIRDPARTIES',
							'action' => 'Merge the 2 thirdparties'
						);
					} elseif ($db->num_rows($resql) === 1) {
						$obj = $db->fetch_object($resql);
						$result = $thirdparty->fetch($obj->rowid);
						if ($result > 0) {
							$thirdpartyId = $thirdparty->id;
							dol_syslog(get_class($this) . '::_syncOrCreateThirdpartyFromEInvoiceSeller Found thirdparty by VAT number: ' . $thirdpartyId);
						}
					}
				}
			}
		}

		// Step 2: If not found, try to find by findNearest function
		if ($thirdpartyId < 0) {
			$result = $thirdparty->findNearest(
				0,
				$sellerInfo['sellername'] ?? '',
				$sellerInfo['sellername'] ?? '',
				'',
				'',
				'',
				'',
				'',
				'',
				'',
				$sellerInfo['sellercontactemailaddr'] ?? '',
				$sellerInfo['sellername'] ?? ''
			); // TODO: we can add phone, address and vat number to improve matching
			if ($result > 0) {
				$thirdpartyId = $thirdparty->id;
				dol_syslog(get_class($this) . '::_syncOrCreateThirdpartyFromEInvoiceSeller Found thirdparty by findNearest: ' . $thirdpartyId);
			}
		}

		// Step 3: Create or update thirdparty

		//$thirdpartyId = -2; // For testing

		// if found, update information
		if ($thirdpartyId > 0) {
			// if complete info is disabled, we return directly the thirdpartyId
			if (!empty(getDolGlobalInt('PDPCONNECTFR_THIRDPARTIES_COMPLETE_INFO'))) {
				dol_syslog(get_class($this) . '::_syncOrCreateThirdpartyFromEInvoiceSeller Complete info disabled, returning existing thirdparty: ' . $thirdpartyId);
				return array('res' => $thirdpartyId, 'message' => 'Existing thirdparty used without update: ' . $thirdpartyId);
			}

			dol_syslog(get_class($this) . '::_syncOrCreateThirdpartyFromEInvoiceSeller Updating existing thirdparty: ' . $thirdpartyId);
			// TODO: MAYBE we should call PDP to retrieve more information

			$thirdparty = new Societe($db);
			$thirdparty->fetch($thirdpartyId);

			// Update thirdparty information based on priority
			if ($priority === 'pdp') { // Ecrase dolibarr data with pdp data
				$thirdparty->name = $sellerInfo['sellername'] ?? $thirdparty->name;
				$thirdparty->address = $sellerInfo['sellerlineone'] ?? $thirdparty->address;
				if (!empty($sellerInfo['sellerlinetwo'])) {
					$thirdparty->address .= "\n" . $sellerInfo['sellerlinetwo'];
				}
				if (!empty($sellerInfo['sellerlinethree'])) {
					$thirdparty->address .= "\n" . $sellerInfo['sellerlinethree'];
				}
				$thirdparty->zip = $sellerInfo['sellerpostcode'] ?? $thirdparty->zip;
				$thirdparty->town = $sellerInfo['sellercity'] ?? $thirdparty->town;
				$thirdparty->country_code = $sellerInfo['sellercountry'] ?? $thirdparty->country_code;
				$thirdparty->email = $sellerInfo['sellercontactemailaddr'] ?? $thirdparty->email;
				$thirdparty->phone = $sellerInfo['sellercontactphoneno'] ?? $thirdparty->phone;
				$thirdparty->fax = $sellerInfo['sellercontactfaxno'] ?? $thirdparty->fax;

				// Set identification numbers
				if (!empty($sellerInfo['sellerGlobalIds']) && is_array($sellerInfo['sellerGlobalIds'])) {
					foreach ($sellerInfo['sellerGlobalIds'] as $idScheme => $globalId) {
						if (!empty($globalId)) {
							$idprofField = $this->_mapGlobalIdSchemeToIdprof($idScheme);
							if (!empty($idprofField)) {
								$thirdparty->$idprofField = $pdpconnectfr->removeSpaces($globalId);
							}
						}
					}
				}
				if (!empty($sellerInfo['sellerTaxRegistations']['VA'])) {
					$thirdparty->tva_intra = $pdpconnectfr->removeSpaces($sellerInfo['sellerTaxRegistations']['VA']);
					$thirdparty->tva_assuj = 1;
				}
			} elseif ($priority === 'dolibarr') { // Fill only empty fields from pdp data
				dol_syslog(get_class($this) . '::_syncOrCreateThirdpartyFromEInvoiceSeller Keeping existing thirdparty data and fill only empty fields as priority is dolibarr: ' . $thirdpartyId);

				if (empty($thirdparty->name) && !empty($sellerInfo['sellername'])) {
					$thirdparty->name = $sellerInfo['sellername'];
				}
				if (empty($thirdparty->address) && !empty($sellerInfo['sellerlineone'])) {
					$thirdparty->address = $sellerInfo['sellerlineone'];
					if (!empty($sellerInfo['sellerlinetwo'])) {
						$thirdparty->address .= "\n" . $sellerInfo['sellerlinetwo'];
					}
					if (!empty($sellerInfo['sellerlinethree'])) {
						$thirdparty->address .= "\n" . $sellerInfo['sellerlinethree'];
					}
				}
				if (empty($thirdparty->zip) && !empty($sellerInfo['sellerpostcode'])) {
					$thirdparty->zip = $sellerInfo['sellerpostcode'];
				}
				if (empty($thirdparty->town) && !empty($sellerInfo['sellercity'])) {
					$thirdparty->town = $sellerInfo['sellercity'];
				}
				if (empty($thirdparty->country_code) && !empty($sellerInfo['sellercountry'])) {
					$thirdparty->country_code = $sellerInfo['sellercountry'];
				}
				if (empty($thirdparty->email) && !empty($sellerInfo['sellercontactemailaddr'])) {
					$thirdparty->email = $sellerInfo['sellercontactemailaddr'];
				}
				if (empty($thirdparty->phone) && !empty($sellerInfo['sellercontactphoneno'])) {
					$thirdparty->phone = $sellerInfo['sellercontactphoneno'];
				}
				if (empty($thirdparty->fax) && !empty($sellerInfo['sellercontactfaxno'])) {
					$thirdparty->fax = $sellerInfo['sellercontactfaxno'];
				}
				// Set identification numbers if empty
				if (!empty($sellerInfo['sellerGlobalIds']) && is_array($sellerInfo['sellerGlobalIds'])) {
					foreach ($sellerInfo['sellerGlobalIds'] as $idScheme => $globalId) {
						if (!empty($globalId)) {
							$idprofField = $this->_mapGlobalIdSchemeToIdprof($idScheme);
							if (!empty($idprofField) && empty($thirdparty->$idprofField)) {
								$thirdparty->$idprofField = $pdpconnectfr->removeSpaces($globalId);
							}
						}
					}
				}
				if (!empty($sellerInfo['sellerTaxRegistations']['VA']) && empty($thirdparty->tva_intra)) {
					$thirdparty->tva_intra = $pdpconnectfr->removeSpaces($sellerInfo['sellerTaxRegistations']['VA']);
					$thirdparty->tva_assuj = 1;
				}
			}
			$result = $thirdparty->update($thirdpartyId, $user);
			if ($result < 0) {
				$this->error = $thirdparty->error;
				$this->errors = $thirdparty->errors;

				dol_syslog(get_class($this) . '::_syncOrCreateThirdpartyFromEInvoiceSeller Error updating thirdparty: ' . implode(',', array_merge(array($thirdparty->error), $thirdparty->errors)), LOG_ERR);
				return array('res' => -1, 'message' => 'Thirdparty update error: ' . implode(',', array_merge(array($thirdparty->error), $thirdparty->errors)));
			} else {
				dol_syslog(get_class($this) . '::_syncOrCreateThirdpartyFromEInvoiceSeller Updated thirdparty: ' . $thirdpartyId);
				return array('res' => $thirdpartyId, 'message' => 'Thirdparty ' . $thirdparty->name . ' updated successfully');
			}
		}

		// if not found, create new thirdparty
		if ($thirdpartyId < 0 && !empty(getDolGlobalInt('PDPCONNECTFR_THIRDPARTIES_AUTO_GENERATION'))) {
			dol_syslog(get_class($this) . '::_syncOrCreateThirdpartyFromEInvoiceSeller Creating new thirdparty: ' . $sellerInfo['sellername']);

			$thirdparty = new Societe($db);

			$thirdparty->name = $sellerInfo['sellername'] ?? 'Unknown Supplier name';
			$thirdparty->address = $sellerInfo['sellerlineone'] ?? '';
			if (!empty($sellerInfo['sellerlinetwo'])) {
				$thirdparty->address .= "\n" . $sellerInfo['sellerlinetwo'];
			}
			if (!empty($sellerInfo['sellerlinethree'])) {
				$thirdparty->address .= "\n" . $sellerInfo['sellerlinethree'];
			}
			$thirdparty->zip = $sellerInfo['sellerpostcode'] ?? '';
			$thirdparty->town = $sellerInfo['sellercity'] ?? '';
			$thirdparty->country_code = $sellerInfo['sellercountry'] ?? '';
			$thirdparty->email = $sellerInfo['sellercontactemailaddr'] ?? '';
			$thirdparty->phone = $sellerInfo['sellercontactphoneno'] ?? '';
			$thirdparty->fax = $sellerInfo['sellercontactfaxno'] ?? '';

			// Set identification numbers
			if (!empty($sellerInfo['sellerGlobalIds']) && is_array($sellerInfo['sellerGlobalIds'])) {
				foreach ($sellerInfo['sellerGlobalIds'] as $idScheme => $globalId) {
					if (!empty($globalId)) {
						$idprofField = $this->_mapGlobalIdSchemeToIdprof($idScheme);
						if (!empty($idprofField)) {
							$thirdparty->$idprofField = $pdpconnectfr->removeSpaces($globalId);
						}
					}
				}
			}

			if (!empty($sellerInfo['sellerTaxRegistations']['VA'])) {
				$thirdparty->tva_intra = $pdpconnectfr->removeSpaces($sellerInfo['sellerTaxRegistations']['VA']);
				$thirdparty->tva_assuj = 1;
			}

			// Set as supplier
			$thirdparty->fournisseur = 1;
			$thirdparty->code_fournisseur = 'auto';

			$result = $thirdparty->create($user);
			if ($result > 0) {
				$thirdpartyId = $thirdparty->id;

				// Add entry in pdpconnectfr_extlinks table to mark that this thirdparty is imported from PDP
				$pdpconnectfr->insertOrUpdateExtLink($thirdpartyId, $thirdparty->element, $flowId);

				dol_syslog(get_class($this) . '::_syncOrCreateThirdpartyFromEInvoiceSeller Created new thirdparty: ' . $thirdpartyId);
				return array('res' => $thirdpartyId, 'message' => 'Thirdparty ' . $thirdparty->name . ' created successfully');
			} else {
				dol_syslog(get_class($this) . '::_syncOrCreateThirdpartyFromEInvoiceSeller Error creating thirdparty: ' . $thirdparty->error, LOG_ERR);
				return array('res' => -1, 'message' => 'Thirdparty creation error: ' . implode("\n", $thirdparty->errors));
			}
		} else {
			dol_syslog(get_class($this) . '::_syncOrCreateThirdpartyFromEInvoiceSeller Auto-creation of thirdparties is disabled', LOG_ERR);

			$sellername = trim($sellerInfo['sellername'] ?? '');
			$selleremail = trim($sellerInfo['sellercontactemailaddr'] ?? '');

			$selleridents = [];
			$createParams = [];

			if (!empty($sellername)) {
				$selleridents[] = 'Supplier: ' . $sellername;
				$createParams['name'] = $sellername;
			}
			if (!empty($selleremail)) {
				$selleridents[] = 'Email: ' . $selleremail;
				$createParams['email'] = $selleremail;
			}

			if (!empty($sellerInfo['sellerGlobalIds']) && is_array($sellerInfo['sellerGlobalIds'])) {
				foreach ($sellerInfo['sellerGlobalIds'] as $idScheme => $globalId) {
					if (!empty($globalId)) {
						$idprofField = $this->_mapGlobalIdSchemeToIdprof($idScheme);
						if (!empty($idprofField)) {
							$selleridents[] = $idScheme . ': ' . $globalId;
							$createParams[$idprofField] = $globalId;
						}
					}
				}
			}

			// Create URL to prefill thirdparty creation form
			$createUrl = DOL_URL_ROOT . '/societe/card.php?action=create&type=f';
			if (!empty($createParams)) {
				$createUrl .= '&' . http_build_query($createParams);
			}
			$createUrl .= '&backtopage=' . urlencode(dol_buildpath('/pdpconnectfr/document_list.php', 1));

			$errorDetails = [];
			if (!empty($sellername)) {
				$errorDetails[] = 'Supplier: ' . $sellername;
			}
			if (!empty($selleremail)) {
				$errorDetails[] = 'Email: ' . $selleremail;
			}
			if (!empty($selleridents)) {
				$errorDetails[] = 'ID: ' . implode(', ', $selleridents);
			}

			$detailsStr = !empty($errorDetails) ? ' [' . implode(' - ', $errorDetails) . ']' : '';

			$message = 'Unable to find supplier' . $detailsStr . '. Auto-creation of thirdparties is disabled in settings.';

			$action = $langs->trans('CreateSupplierManually');
			$action .= '<a class="butAction small" href="' . dol_escape_htmltag($createUrl) . '" target="_blank">';
			$action .= '<i class="fas fa-plus-circle"></i> ';
			$action .= $langs->trans('CreateSupplier');
			$action .= '</a>';

			return array(
				'res' => -1,
				'message' => $message,
				'actioncode' => 'THIRDPARTY_NOT_FOUND',
				'actionurl' => $createUrl,
				'action' => $action
			);
		}
	}

	/**
	 * Map CII global ID scheme to Dolibarr idprof field
	 *
	 * @param string $scheme Global ID scheme code
	 * @return string Corresponding idprof field name
	 */
	private function _mapGlobalIdSchemeToIdprof($scheme)
	{
		$map = [
			'0002' => 'idprof1', // SIREN
		];

		return $map[$scheme] ?? '';
	}


	/**
	 * Map CII document type code to Dolibarr invoice type
	 *
	 * @param string $documenttypecode CII document type code
	 * @return int|string Dolibarr invoice type or '-1' if unknown
	 */
	private function _getDolibarrInvoiceType($documenttypecode)
	{

		/**
		 * Codes UNTDID 1001 utilisés par EN16931 pour le type de facture (InvoiceTypeCode BT-3).
		 * 325 – Facture pro-forma
		 * 211 – Demande de paiement intermédiaire (une facture de situation?)
		 * 386 – Facture d’acompte
		 * 381 – Note de crédit
		 * 384 – Facture corrective
		 * 380 – Facture standard
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

		$map = [
			'380' => CommonInvoice::TYPE_STANDARD,
			'384' => CommonInvoice::TYPE_REPLACEMENT,
			'381' => CommonInvoice::TYPE_CREDIT_NOTE,
			'386' => CommonInvoice::TYPE_DEPOSIT,
			'211' => CommonInvoice::TYPE_SITUATION,
			'325' => CommonInvoice::TYPE_PROFORMA,
		];


		if (!isset($map[$documenttypecode])) {
			dol_syslog(get_class($this) . '::_getDolibarrInvoiceType Unknown document type code: ' . $documenttypecode, LOG_WARNING);
			return '-1';
		}

		return $map[$documenttypecode];
	}

	/**
	 * Find or create a Dolibarr product based on CII invoice line data
	 * @param array $lineData Array containing invoice line data extracted from CII
	 * @param string $flowId Flow identifier source of the product. Used for logging purposes.
	 *
	 * @return array{res:int, message:string, actioncode:string|null, actionurl:string|null, action:string|null}   Returns array with 'res' (ID of the found or created product, -1 on error) with a 'message' and an optional 'action'.
	 */
	private function _findOrCreateProductFromEinvoiceLine($lineData, $flowId = '')
	{
		/*
		 * PRODUCT MATCHING FOR SUPPLIER INVOICE (CII invoice line => Dolibarr product)
		 *
		 * This matching strategy attempts to find or create a product based on
		 * CII invoice line data, following a priority-based approach.
		 *
		 * 1. Search in product supplier prices table using prodsellerid
		 *    - Ok if match found
		 *    - ko, continue to step 2
		 *
		 * 2. Global ID (prodglobalid + prodglobalidtype) and prodglobalidtype = '0160' search by barcode
		 *    - ok if match found
		 *    - KO if Other schemes or no match, continue to step 3
		 *
		 * 3. if Buyer Reference (prodbuyerid) is available search prodbuyerid = internal product reference
		 *    - ok if match found
		 *    - ko, continue to step 4
		 *
		 * 4. Text Search using prodname
		 *    - ok if match found
		 *    - ko if multiple matches or no match, continue to create product
		 *
		 * 5. If no match found after all steps:
		 *    - Automatic product creation (with extrafield source=Einvoice and to be verified tag)
		 *    - Use this product for supplier invoice line (with extrafield to be verified tag)
		 *    - Add supplier price information (if not added automatically by Dolibarr)
		 */
		global $db, $user, $langs;

		$pdpconnectfr = new PdpConnectFr($db);

		// Search in product supplier prices table using prodsellerid
		$sql = "SELECT p.rowid ";
		$sql .= " FROM " . MAIN_DB_PREFIX . "product as p ";
		$sql .= " INNER JOIN " . MAIN_DB_PREFIX . "product_fournisseur_price as pfp ON pfp.fk_product = p.rowid ";
		$sql .= " WHERE pfp.product_supplier_id = '" . $db->escape($lineData['prodsellerid']) . "' ";
		$sql .= " AND pfp.fk_soc = " . intval($lineData['supplierId']) . " ";
		$sql .= " AND p.entity IN (" . getEntity('product') . ")";
		$sql .= " LIMIT 1";
		$resql = $db->query($sql);
		if ($resql && $db->num_rows($resql) > 0) {
			$obj = $db->fetch_object($resql);
			dol_syslog(get_class($this) . '::_findOrCreateProductFromEinvoiceLine Found product by prodsellerid: ' . $obj->rowid);
			return array('res' => $obj->rowid, 'message' => 'Product found by prodsellerid');
			// No match found, continue to next step
		}

		// Global ID (prodglobalid + prodglobalidtype) and prodglobalidtype = '0160' search by barcode
		// TODO

		// if Buyer Reference (prodbuyerid) is available search prodbuyerid = internal product reference
		if (!empty($lineData['prodbuyerid'])) {
			$sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "product";
			$sql .= " WHERE ref = '" . $db->escape($lineData['prodbuyerid']) . "' OR rowid = '" . $db->escape($lineData['prodbuyerid']) . "' ";
			$sql .= " AND entity IN (" . getEntity('product') . ")";
			$sql .= " LIMIT 1";
			$resql = $db->query($sql);
			if ($resql && $db->num_rows($resql) > 0) {
				$obj = $db->fetch_object($resql);
				dol_syslog(get_class($this) . '::_findOrCreateProductFromEinvoiceLine Found product by prodbuyerid: ' . $obj->rowid);
				return array('res' => $obj->rowid, 'message' => 'Product found by prodbuyerid');
			}
		}

		// Check with EI- prefix for product inmported using prodsellerid as internal reference with EI- prefix
		if (!empty($lineData['prodsellerid']) && $lineData['prodsellerid'] !== "0000") {
			$sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "product";
			$sql .= " WHERE ref = 'EI-" . $db->escape($lineData['prodsellerid']) . "'";
			$sql .= " AND entity IN (" . getEntity('product') . ")";
			$sql .= " LIMIT 1";
			$resql = $db->query($sql);
			if ($resql && $db->num_rows($resql) > 0) {
				$obj = $db->fetch_object($resql);
				dol_syslog(get_class($this) . '::_findOrCreateProductFromEinvoiceLine Found product by prodsellerid with EI- prefix: ' . $obj->rowid);
				return array('res' => $obj->rowid, 'message' => 'Product found by prodsellerid with EI- prefix');
			}
		}

		// Text Search using prodname
		$sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "product";
		$sql .= " WHERE label = '" . $db->escape($lineData['prodname']) . "'";
		$sql .= " AND entity IN (" . getEntity('product') . ")";
		$resql = $db->query($sql);
		if ($resql) {
			if ($db->num_rows($resql) === 1) {
				$obj = $db->fetch_object($resql);
				dol_syslog(get_class($this) . '::_findOrCreateProductFromEinvoiceLine Found product by text search: ' . $obj->rowid);
				return array('res' => $obj->rowid, 'message' => 'Product found by text search');
			}
		}

		// If no match found after all steps: Create new product
		if (!empty(getDolGlobalInt('PDPCONNECTFR_PRODUCTS_AUTO_GENERATION'))) {
			$product = new Product($db);
			$product->type = $this->_detectProductTypeFromEinvoiceLine($lineData);
			$product->ref = 'EI-' . dol_sanitizeFileName(!empty($lineData['prodsellerid'] && $lineData['prodsellerid'] !== "0000") ? $lineData['prodsellerid'] : uniqid());
			$product->ref_ext = trim($lineData['prodsellerid'] ?? '');
			$product->label = !empty($lineData['prodname'])
				? $lineData['prodname']
				: 'Imported product from supplier invoice (Ref: ' . $lineData['parentDocumentNo'] . ')';
			$product->description = trim($lineData['proddesc'] ?? '');
			$product->tva_tx = (float) ($lineData['rateApplicablePercent'] ?? 0);
			$product->status = 0; // Status not to sell
			$product->status_buy = 1; // Status to buy
			$product->note_private = 'Product created automatically from E-invoice import.';
			$product->import_key = AbstractPDPProvider::$PDPCONNECTFR_LAST_IMPORT_KEY; // It does not work here, so we will update it after creation
			// Set barcode if global ID is provided and is a GTIN/EAN type
			if (!empty($lineData['prodglobalid']) && !empty($lineData['prodglobalidtype']) && in_array($lineData['prodglobalidtype'], ['0160', '0011'])) {
				$product->barcode = $lineData['prodglobalid'];
				$product->barcode_type = getDolGlobalInt('PRODUIT_DEFAULT_BARCODE_TYPE', 0);
			} else {
				$product->barcode = 'auto';
			}
			// Validate before creation
			$resCheck = $product->check();
			if ($resCheck < 0) {
				dol_syslog(__METHOD__ . ' Product check failed: ' . $product->error, LOG_ERR);
				return array('res' => -1, 'message' => 'Product check failed: ' . implode("\n", $product->errors));
			}

			// Create product
			$resCreate = $product->create($user);
			if ($resCreate > 0) {
				$productId = $product->id;

				// Set import_key
				$sql = "UPDATE " . MAIN_DB_PREFIX . "product SET import_key = '" . $db->escape($product->import_key) . "'";
				$sql .= " WHERE rowid = " . ((int) $productId);
				$db->query($sql);

				// Add entry in pdpconnectfr_extlinks table to mark product as created from e-invoice
				$pdpconnectfr->insertOrUpdateExtLink($productId, $product->element, $flowId);

				dol_syslog(__METHOD__ . ' New product created (ID: ' . $productId . ')');
				return [
					'res' => $productId,
					'message' => 'Product successfully created from E-invoice import',
				];
			}

			// Error on creation
			dol_syslog(__METHOD__ . ' Product creation error: ' . $product->error, LOG_ERR);
			return [
				'res' => -1,
				'message' => 'Product creation error: ' . $product->error,
			];
		} else {
			// Suggest manual creation of product
			dol_syslog(get_class($this) . '::_findOrCreateProductFromEinvoiceLine Auto-creation of products is disabled', LOG_ERR);

			$prodRef = trim($lineData['prodsellerid'] ?? '');
			$prodName = trim($lineData['prodname'] ?? '');
			$prodDesc = trim($lineData['proddesc'] ?? '');

			$errorDetails = [];
			$createParams = [];
			if (!empty($prodRef) && $prodRef !== "0000") {
				$errorDetails[] = $prodRef;

				$createParams['ref'] = 'EI-' . dol_sanitizeFileName(!empty($lineData['prodsellerid'] && $lineData['prodsellerid'] !== "0000") ? $lineData['prodsellerid'] : uniqid());

				$createParams['ref_ext'] = $prodRef;
			}
			if (!empty($prodName)) {
				$errorDetails[] = 'Name: ' . $prodName;
				$createParams['label'] = $prodName;
			}
			if (!empty($prodDesc)) {
				//$errorDetails[] = 'Description: ' . $prodDesc;
				$createParams['desc'] = $prodDesc;
			}

			// Detect product type to prefill form
			$createParams['type'] = $this->_detectProductTypeFromEinvoiceLine($lineData);
			$createParams['tva_tx'] = (float) ($lineData['rateApplicablePercent'] ?? 0);
			$createParams['status'] = 1; // Active
			if (!empty($lineData['prodglobalid']) && !empty($lineData['prodglobalidtype']) && in_array($lineData['prodglobalidtype'], ['0160', '0011'])) {
				$createParams['barcode'] = $lineData['prodglobalid'];
				$createParams['barcode_type'] = getDolGlobalInt('PRODUIT_DEFAULT_BARCODE_TYPE', 0);
			} else {
				$createParams['barcode'] = 'auto';
			}

			// Create URL to prefill product creation form
			$createUrl = DOL_URL_ROOT . '/product/card.php?action=create';
			if (!empty($createParams)) {
				$createUrl .= '&' . http_build_query($createParams);
			}
			$createUrl .= '&backtopage=' . urlencode(dol_buildpath('/pdpconnectfr/document_list.php', 1));

			$detailsStr = !empty($errorDetails) ? ' [' . implode(' - ', $errorDetails) . ']' : '';

			$message = 'Unable to find product' . $detailsStr . '. Auto-creation of products is disabled in settings.';

			$action = $langs->trans('ManualUnfoundProductCreationFromEInvoice', $detailsStr) . ' ';
			$action .= '<a class="butAction small" href="' . dol_escape_htmltag($createUrl) . '" target="_blank">';
			$action .= '<i class="fas fa-plus-circle"></i> ';
			$action .= $langs->trans('CreateProduct');
			$action .= '</a>';

			return array(
				'res' => -1,
				'message' => $message,
				'actioncode' => 'PRODUCT_NOT_FOUND',
				'actionurl' => $createUrl,
				'action' => $action
			);
		}
	}

	/**
	 * Determine if a CII invoice line corresponds to a product (0) or a service (1)
	 *
	 * @param array $line CII invoice line data
	 * @return int 0 = product / 1 = service
	 */
	private function _detectProductTypeFromEinvoiceLine(array $line): int
	{
		$globalId = trim($line['prodglobalid'] ?? '');
		$globalIdType = trim($line['prodglobalidtype'] ?? '');
		$sellerId = trim($line['prodsellerid'] ?? '');
		$unitCode = strtoupper(trim($line['billedquantityunitcode'] ?? ''));
		$name = strtolower($line['prodname'] ?? '');
		$desc = strtolower($line['proddesc'] ?? '');

		// A. Global ID known => product
		// EAN = 0088
		$productGlobalIdTypes = ['0160', '0011', '0002', '0023', '0004', '0001', '0088']; // GTIN/UPC/EAN...
		if ($globalId !== '' && in_array($globalIdType, $productGlobalIdTypes, true)) {
			return 0;
		}

		// B. Units typical for services
		$serviceUnits = ['HUR', 'HRS', 'DAY', 'MON', 'ANN', 'MIN', 'WEE', 'E48']; // hours, days, months...
		if (in_array($unitCode, $serviceUnits, true)) {
			return 1;
		}

		// C. Piece but no seller reference => likely service
		if ($sellerId === '' || $sellerId === '0000') {
			return 1;
		}

		// D. Keywords indicating service
		$keywordsService = ['service', 'prestation', 'maintenance', 'installation', 'abonnement', 'support', 'forfait', 'consult'];
		foreach ($keywordsService as $kw) {
			if (stripos($name, $kw) !== false || stripos($desc, $kw) !== false) {
				return 1;
			}
		}

		// Fallback = service
		return 0;
	}


	/**
	 * Save E-invoice file to dolibarr supplier invoice attachment.
	 *
	 * @param FactureFournisseur    $supplierInvoice 	Supplier invoice object
	 * @param string                $filePath        	Path to the E-invoice file to save
	 * @param string                $suffix          	Optional suffix for the saved file name
	 * @return array{res:int, message:string}   		Returns array with 'res' (1 on success, -1 on error) and info 'message'
	 */
	private function _saveEInvoiceFileToSupplierInvoiceAttachment($supplierInvoice, $filePath, $suffix = 'einvoice')
	{
		global $conf;

		// Ensure upload directory exists
		$folder_part = get_exdir(0, 0, 0, 0, $supplierInvoice);
		$relative_path = 'fournisseur/facture/' . $folder_part . dol_sanitizeFileName($supplierInvoice->ref);
		$upload_dir = $conf->fournisseur->dir_output . '/facture/' . $folder_part . dol_sanitizeFileName($supplierInvoice->ref);

		if (!file_exists($upload_dir)) {
			if (!dol_mkdir($upload_dir)) {
				dol_syslog(__METHOD__ . " Failed to create upload directory: $upload_dir", LOG_ERR);
				return array('res' => -1, 'message' => 'Failed to create upload directory');
			}
		}

		// Prepare destination filename with optional prefix
		$filename = dol_sanitizeFileName($supplierInvoice->ref_supplier . (empty($suffix) ? '' : '_' . $suffix) . '.xml');

		$dest_path = $upload_dir . '/' . $filename;

		// Copy file to destination
		if (!copy($filePath, $dest_path)) {
			dol_syslog(__METHOD__ . " Failed to copy file from $filePath to $dest_path", LOG_ERR);
			return array('res' => -1, 'message' => 'Failed to save attachment file');
		}

		// Verify file was copied successfully
		if (!file_exists($dest_path) || filesize($dest_path) === 0) {
			dol_syslog(__METHOD__ . " File verification failed: $dest_path", LOG_ERR);
			return array('res' => -1, 'message' => 'File verification failed after copy');
		}

		// Set proper file permissions
		chmod($dest_path, 0660);
		dol_syslog(__METHOD__ . " File saved successfully to: $dest_path", LOG_DEBUG);

		// Register file in database index
		$res = addFileIntoDatabaseIndex(
			$dest_path,
			$filename,
			$filename,
			'generated',
			0,
			$supplierInvoice
		);

		if ($res > 0) {
			dol_syslog(__METHOD__ . " File attachment registered in database: $dest_path", LOG_DEBUG);
		} else {
			dol_syslog(__METHOD__ . " Error registering file attachment in database: $dest_path", LOG_ERR);
			// File exists but not indexed - not a critical error, continue
		}

		// Clean up temporary file
		if (file_exists($filePath)) {
			unlink($filePath);
			dol_syslog(__METHOD__ . " Temporary file deleted: $filePath", LOG_DEBUG);
		}

		return array('res' => 1, 'message' => 'Attachment saved successfully ' . $dest_path);
	}

	/**
	 * Determines the delivery dates and the corresponding order numbers within two arrays
	 *
	 * @param 	array   $customerOrderReferenceList  	array to store the corresponding order ids as strings
	 * @param 	array   $deliveryDateList            	array to store the corresponding delivery dates as string in format YYYY-MM-DD
	 * @param 	Facture $object 						invoice object
	 * @return	void
	 */
	private function _determineDeliveryDatesAndCustomerOrderNumbers(&$customerOrderReferenceList, &$deliveryDateList, $object)
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
	 * Return IEC_6523 code (https://docs.peppol.eu/poacc/billing/3.0/codelist/ICD/)
	 * This list of codes describes schemes codes for thirdparties but also products. This functions returns need for thirdparty schemes only.
	 *
	 * @param	string		$country_code		Country code
	 * @param	int			$global				Use 0 for legal ID, use 1 for a global ID, use 2 for URI.
	 * @return string code
	 */
	private function getIEC6523Code($country_code, $global = 0)
	{
		$retour = "";
		switch ($country_code) {
			case 'BE':
				if ($global == 1 || $global == 2) {
					$retour = "0208";
				} else {
					$retour = "0008";
				}
				break;
			case 'DE':
				$retour = "0000";
				break;
			case 'FR':
				if ($global == 1 || $global == 2) {
					$retour = "0225";	// SIREN or SIREN_XXX.  	Einvoice global ID, example: "000000002" or URI OD, example "315143296_1939"
				} else {
					$retour = "0002";	// SIREN.	Used for LegalOrganization, example: "315143296"
				}
				break;
			default:
				if ($global == 1 || $global == 2) {
					$retour = "0060";	// DUNS
					// $retour = "EM";	// Emails
				} else {
					$retour = "0060";	// DUNS
					// $retour = "EM";	// Emails
				}
		}
		return $retour;
	}

	/************************************************
	 *    Check line type from external module ?
	 *
	 * @param  object $line       line we work on
	 * @param  string $element    line object element (for special case like shipping)
	 * @param  string $searchName module name we look for
	 * @return boolean                        true if the line is a special one and was created by the module we ask for
	 ************************************************/
	private function _isLineFromExternalModule($line, $element, $searchName)
	{
		// TODO: move this function to class utils
		global $db;
		if ($element == 'shipping' || $element == 'delivery') {
			$fk_origin_line = $line->fk_origin_line;
			$line = new OrderLine($db);
			$line->fetch($fk_origin_line);
		}
		if ($line->product_type == 9 && $line->special_code == $this->_getModNumber($searchName)) {
			return true;
		} else {
			return false;
		}
	}

	/**
	 * Check if a given VAT rate is valid for a specific country based on the c_tva table in the database.
	 *
	 * @param 	string	$vatrate		Vat rate to check (e.g. '20' for 20%)
	 * @param 	string	$countryCode	Country code to check the VAT rate against (e.g. 'FR' for France)
	 * @return 	boolean					Returns true if the VAT rate is valid for the given country, false otherwise.
	 * TODO Move common function into an implemented CommonXProtocol.class.php if needed by other protocol handlers
	 */
	public function checkIfVatRateIsValid($vatrate, $countryCode)
	{
		if ($countryCode == 'FR') {
			// Check rule BR-FR-16 For AFNOR Einvoice - List in XP-Z12-012
			$validRatesString = ['0', '10', '13', '20', '8.5', '19.6', '2.1', '5.5', '7', '20.6', '1.05', '0.9', '1.75', '9.2', '9.6'];
			//$valtotest = price2num((float) $vatrate, '', 1);
			if (!in_array($vatrate, $validRatesString)) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Map type of invoices dolibarr <-> facturx
	 *
	 * @param 	CommonInvoice	$object 	The invoice object
	 * @return  string|null 				code of invoice type
	 */
	private function _getTypeOfInvoice($object)
	{
		$map = [
			CommonInvoice::TYPE_STANDARD        => '380',
			CommonInvoice::TYPE_REPLACEMENT     => '384',
			CommonInvoice::TYPE_CREDIT_NOTE     => '381',
			CommonInvoice::TYPE_DEPOSIT         => '386',
			CommonInvoice::TYPE_SITUATION       => '380',				// Process situation invoice as common invoice
		];
		return $map[$object->type] ?? null;
	}

	/**
	 * Determine Factur-X BillingProcessID (Cadre / Mode de facturation)
	 * according to French e-invoicing
	 *
	 * BillingProcessID allowed values:
	 *
	 * STANDARD INVOICE (initial submission)
	 * --------------------------------------
	 * B1 : Products invoice
	 * S1 : Services invoice
	 * M1 : Mixed invoice (products + services non-accessory)
	 *
	 * INVOICE (already paid)
	 * -------------------------------------------
	 * B2 : Products invoice
	 * S2 : Services invoice
	 * M2 : Mixed invoice (products + services non-accessory)
	 *
	 * FINAL INVOICE AFTER DEPOSIT
	 * ----------------------------
	 * B4 : Final products invoice (after deposit)
	 * S4 : Final services invoice (after deposit)
	 * M4 : Final mixed invoice (after deposit)
	 *
	 * SPECIFIC CASES
	 * --------------
	 * S5 : Services invoice issued by subcontractor
	 * S6 : Services invoice issued by co-contractor
	 *
	 * E-REPORTING CASE (VAT already collected)
	 * -----------------------------------------
	 * B7 : Products invoice already reported (VAT already collected)
	 * S7 : Services invoice already reported (VAT already collected)
	 *
	 * Notes:
	 * - Prefix meaning:
	 *     B = Products
	 *     S = Services
	 *     M = Mixed (products + services non-accessory)
	 *
	 * @param  Facture $invoice Dolibarr invoice object
	 * @return string  BillingProcessID
	 */
	public function getBillingProcessID($invoice)
	{
		$hasProduct  = false;
		$hasService  = false;

		// Check invoice lines to determine if invoice contains products, services or both
		if (!empty($invoice->lines)) {
			foreach ($invoice->lines as $line) {
				if ((int) $line->product_type === 0) {
					$hasProduct = true;
				}

				if ((int) $line->product_type === 1) {
					$hasService = true;
				}
			}
		}

		// Determine prefix B / S / M
		if ($hasProduct && $hasService) {
			$prefix = 'M';
		} elseif ($hasService && !$hasProduct) {
			$prefix = 'S';
		} else {
			// Default to products
			$prefix = 'B';
		}

		// Determine suffix 1 (initial invoice) or 2 (already paid invoice) according to invoice status and payment information and if the invoice contain a line a deposit (prepayment) so final invoice after deposit then suffix is 4
		if ($invoice->status == Facture::STATUS_CLOSED && empty($invoice->close_code)) {
			return $prefix . '2';
		} else {
			// Check if the invoice contains a deposit (prepayment) line
			$hasDepositLine = false;
			if (!empty($invoice->lines)) {
				foreach ($invoice->lines as $line) {
					if ($line->desc == '(DEPOSIT)') {
						$hasDepositLine = true;
						break;
					}
				}
			}
			if ($hasDepositLine) {
				return $prefix . '4';
			}
			return $prefix . '1';
		}
	}

	/************************************************
	 * Find paymentMean number
	 *
	 * @param  object 	$invoice 			object name we look for
	 * @return integer                      paymentMeanId for HorstOeko libs
	 ************************************************/
	private function _getPaymentMeanNumber($invoice)
	{
		$paymentMeanId = 97;
		//"Must be defined between trading parties" for empty values
		switch ($invoice->mode_reglement_code) {
			case 'CB':
				$paymentMeanId = 54;
				break;
			//Credit Card
			case 'CHQ':
				$paymentMeanId = 20;
				break;
			//Check
			case 'FAC':
				$paymentMeanId = 1;
				break;
			//Local payment method
			case 'LIQ':
				$paymentMeanId = 10;
				break;
			//Cash
			case 'PRE':
				$paymentMeanId = 59;
				break;
			//SEPA direct debit
			case 'TIP':
				$paymentMeanId = 45;
				break;
			//Bank Transfer with document
			case 'TRA':
				$paymentMeanId = 23;
				break;
			//Check
			case 'VAD':
				$paymentMeanId = 68;
				break;
			//Online Payment
			case 'VIR':
				$paymentMeanId = 30;
				break;
		}
		return $paymentMeanId;
	}
}
