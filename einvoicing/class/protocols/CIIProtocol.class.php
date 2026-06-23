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

dol_include_once('einvoicing/class/protocols/AbstractProtocol.class.php');
dol_include_once('einvoicing/class/protocols/CommonProtocol.class.php');
dol_include_once('einvoicing/class/protocols/ShipToTradePartyBuilder.class.php');
dol_include_once('einvoicing/class/einvoicing.class.php');
dol_include_once('einvoicing/class/utils/XmlPatcher.class.php');
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
		 * @var Facture 			$object			The $invoice object used in entry on inc file, but completed.
		 * @var array<mixed,mixed> 	$invoiceData
		 * @var array<mixed,mixed> 	$linesData
		 * @var string 				$outputlang		Value of $outputlangs->defaultlang
		 * @var Societe 			$mysoc
		 * @var Account				$account
		 * @var EInvoicing		$einvoicing
		 * @var string 				$schemdUri		Buyer scheme uri
		 * @var string 				$uri			Buyer uri
		 */

		// Generate the XML file
		$filename = dol_sanitizeFileName($invoice->ref);
		$filedir = getMultidirOutputCompat($invoice, '', 1, 'temp');    // Example '/mydolibarr/documents/facture/temp/FAYYMM-XXXX'
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
	 * This function generates the einvoice file.
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
		$einvoice_path = $filedir . '/' . $filename . '_cii.xml';

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
	 * Create a supplier invoice from a CII Xml file and attach the file (and readable file if exists) to the document.
	 * This may create the Supplier and the Product depending on setup.
	 *
	 * @param  string 			$file                       		Source string file (XML string). We use this file to get data of supplier invoice.
	 * @param  string|null 		$ReadableViewFile        			Readable view file (PDP Generated readable PDF). We only store it if available.
	 * @param  string 			$flowId                       		Flow identifier source of the invoice.
	 * @return array{res:int, message:string, actioncode: string|null, actionurl: string|null, action:string|null}   Returns array with 'res' (1 on success, 0 already exists, -1 on failure) with a 'message' and an optional 'actioncode' and 'action'.
	 */
	public function createSupplierInvoiceFromSource($file, $ReadableViewFile = null, $flowId = '')
	{
		global $conf, $db, $user;

		$einvoicing = new EInvoicing($db);
		$return_messages = array();

		// Save uploaded file to temporary directory
		$tempDir = $conf->einvoicing->dir_temp;
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
				$einvoicing->cleanUpTemporaryFiles(); // Clean up temp files to remove retrieved Einvoice file since invoice already exists

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
		dol_syslog(get_class($this) . '::createSupplierInvoiceFromSource parsedHeader: ' . json_encode($parsedHeader), LOG_DEBUG, 0, '_einvoicing');

		// Sync or create supplier based on seller info
		$syncSocRes = $this->_syncOrCreateThirdpartyFromEInvoiceSeller($parsedHeader, 'dolibarr', $flowId);

		$socId = $syncSocRes['res'];
		$return_messages[] = $syncSocRes['message'];
		if ($socId < 0) {
			return [
				'res' => -1,
				'message' => 'Thirdparty sync or creation error: ' . implode("<br>\n", $return_messages),
				'actioncode' => $syncSocRes['actioncode'] ?? '',
				'actionurl' => $syncSocRes['actionurl'] ?? '',
				'action' => $syncSocRes['action'] ?? null,
				'actiondata' => $syncSocRes['actiondata'] ?? null
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
		$supplierInvoice->ref_supplier = $parsedHeader['documentno'] ?? null;

		// Set basic invoice information (type, date)
		$supplierInvoice->type = $this->_getDolibarrInvoiceType($parsedHeader['documenttypecode'] ?? null);
		if ($supplierInvoice->type === '-1') {
			return ['res' => -1, 'message' => 'Unfounded dolibarr corresponding Invoice code for document type code: ' . ($parsedHeader['documenttypecode'] ?? 'NA')];
		}
		$supplierInvoice->date = isset($parsedHeader['documentdate']) && $parsedHeader['documentdate'] instanceof DateTime ? $parsedHeader['documentdate']->format('Y-m-d') : null;


		// Set currency
		$supplierInvoice->multicurrency_code = $parsedHeader['invoiceCurrency'];

		// Set import_key
		$supplierInvoice->import_key = AbstractPDPProvider::$EINVOICING_LAST_IMPORT_KEY;


		$remise_already_used_line_level_ids = array();

		// Add invoice lines
		foreach ($parsedLines as $parsedLine) {
			// Add supplier ID to line for later use in product sync
			$parsedLine['supplierId'] = $socId;

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
			// handle line-level discount if exists and update amounts
			if (!empty($parsedLine['lineAllowances'])) {
				$discount = $this->_resolveLineDiscountPercent($parsedLine['lineAllowances'], $parsedLine['lineTotalAmount']);
				if ($discount !== false) {
					$line->remise_percent  = $discount['percent'];
					$line->subprice = round($discount['priceWithoutDiscount'] / $parsedLine['billedquantity'], 8);
				}
			}
			$line->qty = $parsedLine['billedquantity'];
			$line->subprice = $line->subprice ?? $parsedLine['netpriceamount'];
			$line->tva_tx = $parsedLine['rateApplicablePercent'];
			$line->total_ht = $parsedLine['lineTotalAmount'];
			$line->total_tva = $parsedLine['calculatedAmount'] ?? 0;
			$line->total_ttc = $parsedLine['lineTotalAmount'] + ($parsedLine['calculatedAmount'] ?? 0);

			$supplierInvoice->lines[] = $line;
		}

		// Create document level discounts (allowances) as discounts in Dolibarr
		$globalDiscountIds = array();
		if (!empty($parsedHeader['headerAllowancesCharges'])) {
			$headerDiscountIds = $this->_createHeaderDiscounts($parsedHeader['headerAllowancesCharges'], $socId, 	$parsedHeader['documentno']);
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

			// TODO : Add supplier price for products (all lines of the invoice)

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
				$res = $this->_saveEInvoiceFileToSupplierInvoiceAttachment($supplierInvoice, $tempFileReadableView, getDolGlobalString('EINVOICING_PDP', 'PDP'));

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
	 * Build CII XML from invoice data.
	 *
	 * @param array 		$invoiceData 	Header-level invoice data (generated by the buildinvoicelines.inc.php)
	 * @param array 		$linesData 		Array of line-level data arrays (generated by the buildinvoicelines.inc.php)
	 * @param string 		$profile 		Profile ('MINIMUM', 'BASICWL', 'BASIC', 'EN16931', 'EXTENDED')
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
		$comment = $doc->createComment('Einvoice XML generated by Dolibarr ' . DOL_VERSION . ' - ' . dol_print_date(dol_now('gmt'), 'dayhourrfc', 'gmt'));
		$root->appendChild($comment);

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

		// XML Format = Profile
		$profileGuidelines = [
			'MINIMUM'  => 'urn:factur-x.eu:1p0:minimum', 	// Factur-X profile
			'BASICWL'  => 'urn:factur-x.eu:1p0:basicwl', 	// Factur-X profile
			'BASIC'    => 'urn:factur-x.eu:1p0:basic', 		// Factur-X profile
			'EN16931'=> 'urn:cen.eu:en16931:2017', 		// CII Profile.
			//'EN16931'  => 'urn:cen.eu:en16931:2017#conformant#urn.cpro.gouv.fr:1p0:extended-ctc-fr',	// CII Profile.
			'EXTENDED' => 'urn:cen.eu:en16931:2017#conformant#urn:factur-x.eu:1p0:extended', 			// Factur-X profile
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


		//$root->appendChild($doc->createTextNode("\n "));


		// Transaction
		$sctt = $doc->createElement('rsm:SupplyChainTradeTransaction');
		$root->appendChild($sctt);


		// LINES
		foreach ($linesData as $line) {
			// Add a XML comment to help debug
			$comment = $doc->createComment('Line '.$line['lineid']);
			$sctt->appendChild($comment);

			$sctt->appendChild($this->buildLineItem($doc, $line, $profile));
		}
		// Add a XML comment to help debug
		$comment = $doc->createComment('End of lines');
		$sctt->appendChild($comment);


		// SELLER / BUYER
		$agreement = $doc->createElement('ram:ApplicableHeaderTradeAgreement');
		$sctt->appendChild($agreement);

		// Seller
		$comment = $doc->createComment('Seller');
		$agreement->appendChild($comment);

		$this->buildParty($doc, $agreement, $invoiceData, 'seller');

		// Add comment
		$comment = $doc->createComment('Buyer');
		$agreement->appendChild($comment);

		$this->buildParty($doc, $agreement, $invoiceData, 'buyer');


		// DELIVERY

		// Add comment
		$comment = $doc->createComment('Delivery');
		$sctt->appendChild($comment);

		$delivery = $doc->createElement('ram:ApplicableHeaderTradeDelivery');
		$sctt->appendChild($delivery);

		// Add the ship to trade party (mandatory when using intracommunity delivery)
		// ShipToTradeParty is itself a TradePartyType — populate it directly without
		// wrapping it in another BuyerTradeParty (which would break XSD validation).
		// When an external SHIPPING contact with a distinct address is attached to the invoice
		// (keys filled by buildinvoicelines.inc.php), emit a dedicated deliver-to party (BG-15);
		// otherwise fall back to the buyer party so the node stays present (upstream behaviour).
		$shiptotrade = null;
		if (!empty($invoiceData['_shipFromContactShip'])) {
			$shiptotrade = ShipToTradePartyBuilder::build(
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
			$this->buildParty($doc, $shiptotrade, $invoiceData, 'buyer', false);
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

		// Payment mode
		if (!empty($invoiceData['paymentMeansCode'])) {
			$comment = $doc->createComment('Payment mode');
			$settlement->appendChild($comment);

			// Payment means
			$pm = $doc->createElement('ram:SpecifiedTradeSettlementPaymentMeans');
			$settlement->appendChild($pm);

			$pm->appendChild($doc->createElement('ram:TypeCode', $invoiceData['paymentMeansCode']));		// A code for payment type BT-81 (BG-16)
			$pm->appendChild($doc->createElement('ram:Information', $invoiceData['paymentMeansText']));		// A label for payment type

			$acc = $doc->createElement('ram:PayeePartyCreditorFinancialAccount');
			$pm->appendChild($acc);

			// CII XSD order for CreditorFinancialAccountType: IBANID, ProprietaryID, AccountName
			if (!empty($invoiceData['iban'])) {
				$acc->appendChild($doc->createElement('ram:IBANID', $invoiceData['iban']));					// BT-84
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
			$acc->appendChild($doc->createElement('ram:AccountName', $invoiceData['accountName']));			// BT-85
			if (empty($invoiceData['iban']) && !empty($invoiceData['accountRef'])) {	// If IBAN unknown we can fallback on the private ref.
				$acc->appendChild($doc->createElement('ram:ProprietaryID', $invoiceData['accountRef']));	// BT-84-0
			}
			if (!empty($invoiceData['bic'])) {
				$inst = $doc->createElement('ram:PayeeSpecifiedCreditorFinancialInstitution');
				$pm->appendChild($inst);
				$inst->appendChild($doc->createElement('ram:BICID', $invoiceData['bic']));					// BT-86
			}
		}

		// VAT array by rate (tax breakdown)
		foreach ($invoiceData['taxBreakdown'] as $rate => $vals) {		// $rate is 0, 20.0, ..., $vals is an array
			// Add comment
			$comment = $doc->createComment('VAT rate: '.$vals['tva_tx'].', VAT src code: '.$vals['vat_src_code'].', ExemptionReasonCode: '.$vals['ExemptionReasonCode']);
			$settlement->appendChild($comment);

			$settlement->appendChild(
				$this->buildTaxNode($doc, $rate, $vals, $invoiceData['invoiceCurrency']) 	// ApplicableTradeTax
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

		$terms->appendChild($doc->createElement('ram:Description', $invoiceData['paymentTermsText']));

		// Due date is optional (e.g. immediate payment); guard against a null date to avoid a fatal on ->format().
		if (!empty($invoiceData['paymentDueDate'])) {
			$dtNode = $doc->createElement('ram:DueDateDateTime');
			$str = $doc->createElement('udt:DateTimeString', $invoiceData['paymentDueDate']->format('Ymd'));
			$str->setAttribute('format', '102');
			$dtNode->appendChild($str);

			$terms->appendChild($dtNode);
		}

		// Totals

		// Add comment
		$comment = $doc->createComment('Totals');
		$settlement->appendChild($comment);

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

		// Referenced documents BG-3
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

		// This section seems not required.
		// It can be used if the price base is including tax (TTC) and without discount (= Catalog public unit price for individual customers)
		if (isset($line['grosspriceamount'])) {
			$gross = $doc->createElement('ram:GrossPriceProductTradePrice');
			$price->appendChild($gross);
			$gross->appendChild($doc->createElement('ram:ChargeAmount', number_format($line['grosspriceamount'], 2, '.', '')));
		}

		// Mandatory by Factur-X, EN 16931
		// This is the unit price excluding tax. If it does not contains the discount, the discount must be declared into AllowanceCharge.
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


		// Add the VAT block for the line
		$tax = $doc->createElement('ram:ApplicableTradeTax');
		$sett->appendChild($tax);

		// Add a XML comment to help debug
		$comment = $doc->createComment('VAT rate: '.$line['tva_tx'].', VAT src code: '.$line['vat_src_code']);
		$tax->appendChild($comment);


		$tax->appendChild($doc->createElement('ram:TypeCode', 'VAT'));
		$tax->appendChild($doc->createElement('ram:CategoryCode', $line['categoryCode']));
		$tax->appendChild($doc->createElement('ram:RateApplicablePercent', $line['rateApplicablePercent']));

		// Note that the $line['ExemptionReasonCode'] and $line['ExemptionReasonCode'] is added into the section ApplicableHeaderTradeSettlement
		// that is a vat breakdown array and not inside each line.

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
	 * @param bool        		$wrap			Whether to wrap in SellerTradeParty/BuyerTradeParty (true for main parties, false for ship to party)
	 *
	 * @return void
	 */
	private function buildParty($doc, $agreement, $data, $type, $wrap = true)
	{
		if ($wrap) {
			$tag = $type === 'seller' ? 'ram:SellerTradeParty' : 'ram:BuyerTradeParty';
			$node = $doc->createElement($tag);
			$agreement->appendChild($node);
		} else {
			$node = $agreement;
		}

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
		// ram:DefinedTradeContact is the wrapper for all contact sub-fields. Only create it when at
		// least one sub-field is present, otherwise $contact stays null and appendChild() fatals
		// (e.g. specimen seller with a phone but no contact person name).
		if (!empty($data[$prefix . 'contactpersonname'])
			|| !empty($data[$prefix . 'contactdepartmentname'])
			|| !empty($data[$prefix . 'contactphoneno'])
			|| !empty($data[$prefix . 'contactfaxno'])
			|| !empty($data[$prefix . 'contactemailaddr'])) {
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
		}


		// Address
		$addr = $doc->createElement('ram:PostalTradeAddress');
		$node->appendChild($addr);

		$addr->appendChild($doc->createElement('ram:PostcodeCode', $data[$prefix . 'postcode']));
		if (!empty($data[$prefix . 'lineone'])) {
			$addr->appendChild($doc->createElement('ram:LineOne', htmlspecialchars($data[$prefix . 'lineone'])));
		}
		$addr->appendChild($doc->createElement('ram:CityName', htmlspecialchars($data[$prefix . 'city'])));
		$addr->appendChild($doc->createElement('ram:CountryID', $data[$prefix . 'country']));

		// URIUniversalCommunication
		if (!empty($data[$prefix . 'CommunicationUriScheme']) && !empty($data[$prefix . 'CommunicationUri'])) {
			$uri = $doc->createElement('ram:URIUniversalCommunication');
			$node->appendChild($uri);
			$uriid = $doc->createElement('ram:URIID', $data[$prefix . 'CommunicationUri']);			// Example 315143296_1939
			$uriid->setAttribute('schemeID', $data[$prefix . 'CommunicationUriScheme']);			// Example 0225
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
	 * @param \DOMDocument 		$doc 		Document to create nodes in
	 * @param float|string      $rate 		Tax rate
	 * @param array       		$vals 		Array containing tax values
	 * @param string       		$currency 	Currency code
	 * @return \DOMElement
	 */
	private function buildTaxNode($doc, $rate, $vals, $currency)
	{
		$tax = $doc->createElement('ram:ApplicableTradeTax');

		$tax->appendChild($doc->createElement('ram:CalculatedAmount', number_format($vals['totalTVA'], 2, '.', '')));

		$tax->appendChild($doc->createElement('ram:TypeCode', 'VAT'));

		if ($vals['ExemptionReason']) {
			$tax->appendChild($doc->createElement('ram:ExemptionReason', $vals['ExemptionReason']));
		}

		$tax->appendChild($doc->createElement('ram:BasisAmount', number_format($vals['totalHT'], 2, '.', '')));

		$tax->appendChild($doc->createElement('ram:CategoryCode', $vals['categoryVAT']));

		if ($vals['ExemptionReasonCode']) {
			$tax->appendChild($doc->createElement('ram:ExemptionReasonCode', $vals['ExemptionReasonCode']));
		}

		$floatrate = preg_replace('/\(.*\)/', '', (string) $rate);		// If $rate is 'x.x (CODE)', we change it into 'x.x'

		$tax->appendChild($doc->createElement('ram:RateApplicablePercent', number_format($floatrate, 2, '.', '')));

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
		$taxRate = 20.0
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
			$node->appendChild($doc->createElement('ram:ReasonCode', $reasonCode));
		}

		// Reason
		if ($reason !== null) {
			$node->appendChild($doc->createElement('ram:Reason', $reason));
		}

		// Tax (important Factur-X)
		$taxNode = $doc->createElement('ram:CategoryTradeTax');

		$taxNode->appendChild($doc->createElement('ram:TypeCode', 'VAT'));
		$taxNode->appendChild($doc->createElement('ram:CategoryCode', $taxCategory));
		$taxNode->appendChild($doc->createElement('ram:RateApplicablePercent', number_format($taxRate, 2, '.', '')));

		$node->appendChild($taxNode);

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
			$discount['taxRate'] ?? 20.0
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
	 * @param string $documenttypecode Document type code
	 * @return int|string Dolibarr invoice type or '-1' if unknown
	 */
	private function _getDolibarrInvoiceType($documenttypecode)
	{
		/**
		 * Codes UNTDID 1001 utilisés par EN16931 pour le type de facture (InvoiceTypeCode BT-3).
		 * 325 – Facture pro-forma (a ignorer, n'est pas une facture mais une commande)
		 * 211 – Demande de paiement intermédiaire (une facture de situation?)
		 * 386 – Facture d’acompte
		 * 381 – Avoir / Note de crédit sur facture standard
		 * 384 – Facture corrective / remplacement
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

		$map = [
			'325' => CommonInvoice::TYPE_PROFORMA,
			'211' => CommonInvoice::TYPE_SITUATION,

			'380' => CommonInvoice::TYPE_STANDARD,
			'384' => CommonInvoice::TYPE_REPLACEMENT,
			'381' => CommonInvoice::TYPE_CREDIT_NOTE,
			'386' => CommonInvoice::TYPE_DEPOSIT,
			'503' => CommonInvoice::TYPE_CREDIT_NOTE,
		];


		if (!isset($map[$documenttypecode])) {
			dol_syslog(get_class($this) . '::_getDolibarrInvoiceType Unknown document type code: ' . $documenttypecode, LOG_WARNING);
			return '-1';
		}

		return $map[$documenttypecode];
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
	private function _resolveLineDiscountPercent(array $lineAllowances, ?float $lineTotalAmount)
	{
		// Keep only allowances (indicator = "false"), ignore charges (indicator = "true")
		$allowances = array();
		foreach ($lineAllowances as $allowance) {
			if (($allowance['indicator'] ?? '') === 'false') {
				$allowances[] = $allowance;
			}
		}

		if (empty($allowances)) {
			return false;
		}

		$allowances = array_values($allowances);

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
			'priceWithoutDiscount' => (float) $lineTotalAmount + $totalDiscountAmount,
		];
	}


	/**
	 * Create Dolibarr global discount exceptions from CII header allowances.
	 *
	 * Only processes allowances (indicator = "false"), ignores charges (indicator = "true").
	 * Returns array of created fk_remise_except IDs.
	 *
	 * @param array  $headerAllowancesCharges  	parsed headerAllowancesCharges array
	 * @param int    $fk_soc                  	supplier ID
	 * @param string $description             	invoice number or any reference
	 * @return array							[ originalIndex => fk_remise_except_id ] or '-1' on error
	 */
	private function _createHeaderDiscounts(array $headerAllowancesCharges, int $fk_soc, string $description): array
	{
		global $db, $user;

		$result = [];

		foreach ($headerAllowancesCharges as $index => $allowanceCharge) {
			// Skip charges
			if (($allowanceCharge['indicator'] ?? '') !== 'false') {
				continue;
			}

			$actualAmount = (float) ($allowanceCharge['actualAmount'] ?? 0);
			if ($actualAmount === 0.0) {
				continue;
			}

			$remise = new DiscountAbsolute($db);
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
