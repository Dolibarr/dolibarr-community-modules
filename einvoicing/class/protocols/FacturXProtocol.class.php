<?php
/* Copyright (c) 2025       Eric Seigne                 <eric.seigne@cap-rel.fr>
 * Copyright (C) 2025       Laurent Destailleur         <eldy@users.sourceforge.net>
 * Copyright (C) 2025       Mohamed DAOUD               <mdaoud@dolicloud.com>
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
 * \file    einvoicing/class/protocols/FacturXProtocol.class.php
 * \ingroup einvoicing
 * \brief   Factur-X Protocol integration class
 */

require_once DOL_DOCUMENT_ROOT . '/compta/bank/class/account.class.php';
require_once DOL_DOCUMENT_ROOT . '/expedition/class/expedition.class.php';
require_once DOL_DOCUMENT_ROOT . '/commande/class/commande.class.php';
include_once DOL_DOCUMENT_ROOT . '/core/class/translate.class.php';
include_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
include_once DOL_DOCUMENT_ROOT . '/core/class/discount.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/files.lib.php';

//use custom\facturx\Fidry\FileSystem\FS;
use horstoeko\zugferd\codelists\ZugferdCountryCodes;
use horstoeko\zugferd\codelists\ZugferdCurrencyCodes;
use horstoeko\zugferd\codelists\ZugferdElectronicAddressScheme;
use horstoeko\zugferd\codelists\ZugferdInvoiceType;
use horstoeko\zugferd\codelists\ZugferdReferenceCodeQualifiers;
use horstoeko\zugferd\codelists\ZugferdUnitCodes;
use horstoeko\zugferd\codelists\ZugferdVatCategoryCodes;
use horstoeko\zugferd\codelists\ZugferdVatTypeCodes;
use horstoeko\zugferd\ZugferdDocumentBuilder;
use horstoeko\zugferd\ZugferdProfiles;
use horstoeko\zugferd\ZugferdDocumentPdfBuilder;
use horstoeko\zugferd\ZugferdDocumentPdfBuilderAbstract;
use horstoeko\zugferd\ZugferdDocumentValidator;
use horstoeko\zugferd\ZugferdDocumentPdfReader;
use horstoeko\zugferd\ZugferdDocumentPdfReaderExt;
use horstoeko\zugferd\ZugferdDocumentPdfMerger;


require __DIR__ . "/../../vendor/autoload.php";

dol_include_once('einvoicing/class/protocols/AbstractProtocol.class.php');
dol_include_once('einvoicing/class/protocols/CommonProtocol.class.php');
dol_include_once('einvoicing/class/einvoicing.class.php');
dol_include_once('einvoicing/class/utils/XmlPatcher.class.php');
dol_include_once('einvoicing/lib/einvoicing.lib.php');


/**
 * FacturX Protocol Class
 *
 * This class handles the FacturX protocol implementation for generating
 * and managing electronic invoices according to the FacturX standard.
 * This also throw an error if data is not correct.
 *
 * This implementation is based on FacturX plugin developed by CAP REL.
 * It has been adapted and integrated into the EInvoicing module to provide
 * electronic invoicing capabilities compliant with the French Factur-X standard.
 *
 * @author  Eric Seigne <eric.seigne@cap-rel.fr>
 * 			Modified by mdaoud
 * @see     https://inligit.fr/cap-rel/dolibarr/plugin-facturx plugin repository
 */
class FacturXProtocol extends AbstractProtocol
{
	use CommonProtocol;

	/**
	 * Initialize available protocols.
	 *
	 * @param	DoliDB		$db		DB handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}


	/**
	 * Generate the XML content for a given invoice according to the Factur-X standard.
	 * This also make a lot of check
	 *
	 * This method converts the provided invoice data into a structured XML file
	 * compliant with the Factur-X specification (hybrid PDF + XML format).
	 *
	 * @param 	CommonInvoice	$invoice 		Invoice object containing all necessary data.
	 * @param	?Translate		$outputlangs	Output language
	 * @return 	string 							XML representation of the invoice.
	 */
	public function generateXML($invoice, $outputlangs = null)
	{
		global $conf, $user, $langs, $mysoc, $db;	// Used by the include


		// Call page to generate the invoice
		include dol_buildpath('einvoicing/lib/buildinvoicelines.inc.php');
		/**
		 * @var Facture 			$object			The $invoice object used in entry on inc file, but completed.
		 * @var array<mixed,mixed> 	$invoiceData
		 * @var array<mixed,mixed> 	$linesData
		 * @var string 				$outputlang		Value of $outputlangs->defaultlang
		 * @var Account				$account
		 * @var EInvoicing		$einvoicing
		 * @var string 				$schemdUri		Buyer scheme uri
		 * @var string 				$uri			Buyer uri
		 */

		if (!getDolGlobalInt('EINVOICING_USE_EXTERNAL_FACTURX_BUILDER')) {
			// =====================================================================
			// Use the CII protocol to generate the XML file
			// =====================================================================

			dol_include_once('einvoicing/class/protocols/ProtocolManager.class.php');
			$ProtocolManager = new ProtocolManager($db);
			$CII = $ProtocolManager->getProtocol('CII');

			// Generate the XML file
			$filename = dol_sanitizeFileName($invoice->ref);
			$filedir = getMultidirOutputCompat($invoice, '', 1, 'temp');		// Example '/mydolibarr/documents/facture/temp/FAYYMM-XXXX'
			$xmlfile = $filedir . '/' . $filename . '/factur-x.xml';	// Name of file should be factur-x.xml so it will also have this name once added into PDF

			dol_mkdir(dirname($xmlfile));
			dol_delete_file($xmlfile);

			$xmlcontent = $CII->buildXML($invoiceData, $linesData, 'EXTENDED', $outputlangs);
			file_put_contents($xmlfile, $xmlcontent);

			dolChmod($xmlfile);

			return $xmlfile;
		} else {
			// deprecated, use the native $CII->buildXML method instead !

			// =====================================================================
			// Use horstoeko lib to build the XML
			// =====================================================================

			// Initialize ZugferdDocumentBuilder (FacturX XML)
			dol_syslog(get_class($this) . '::executeHooks create new XML document based on PROFILE_EN16931 (CIUS-FR)');
			$profile = getDolGlobalString('EINVOICING_PROFILE');
			switch ($profile) {
				case 'EN16931':
					$used_profile = ZugferdProfiles::PROFILE_EXTENDED;
					$facturxpdf = ZugferdDocumentBuilder::createNew($used_profile);
				default:
					$used_profile = ZugferdProfiles::PROFILE_EXTENDED;
					$facturxpdf = ZugferdDocumentBuilder::createNew($used_profile);
			}
			dol_syslog(get_class($this) . '::executeHooks create new XML document based on ' . $used_profile);

			// Get the type of invoice in FacturX nomenclature
			$objecttype = $invoiceData['documenttypecode'];
			if ($objecttype == null) {
				throw new Exception('BADINVOICETYPE: The type for invoice id ' . $object->id . ' is not yet supported.');
			}

			//  Build XML Document Header (Seller, Buyer, Dates)
			$facturxpdf
				->setDocumentInformation(
					$invoiceData['documentno'],
					$invoiceData['documenttypecode'],
					$invoiceData['documentdate'],
					$invoiceData['invoiceCurrency'],
					$object->ref_customer,
					$outputlang
				)
				->addDocumentNote($invoiceData['documentNotePublic'])
				->addDocumentNote($invoiceData['documentNotePMT'], null, "PMT")
				->addDocumentNote($invoiceData['documentNotePMD'], null, "PMD")
				->addDocumentNote($invoiceData['documentNoteAAB'], null, "AAB")

				// ---------------- Seller ----------------
				->setDocumentSeller($invoiceData['sellername'], $invoiceData['sellerids'])
				->addDocumentSellerTaxRegistration("VA", $invoiceData['sellervatnumber'])
				->setDocumentSellerLegalOrganisation(
					$invoiceData['sellerLegalOrgId'],
					$invoiceData['sellerLegalOrgScheme'],
					$invoiceData['sellerTradingName']
				)
				->addDocumentSellerGlobalId($invoiceData['sellerGlobalIds'][0]['value'], $invoiceData['sellerGlobalIds'][0]['schemeID'])
				->setDocumentSellerCommunication(
					$invoiceData['sellerCommunicationUriScheme'],
					$invoiceData['sellerCommunicationUri']
				)
				->setDocumentSellerAddress(
					$invoiceData['sellerlineone'],
					$invoiceData['sellerlinetwo'],
					$invoiceData['sellerlinethree'],
					$invoiceData['sellerpostcode'],
					$invoiceData['sellercity'],
					$invoiceData['sellercountry']
				)

				// ---------------- Buyer ----------------
				->setDocumentBuyer(
					$invoiceData['buyername'],
					$invoiceData['buyerids']
				)
				->setDocumentBuyerAddress(
					$invoiceData['buyerlineone'],
					$invoiceData['buyerlinetwo'],
					$invoiceData['buyerlinethree'],
					$invoiceData['buyerpostcode'],
					$invoiceData['buyercity'],
					$invoiceData['buyercountry']
				)
				->addDocumentBuyerTaxRegistration("VA", $invoiceData['buyervatnumber'])
				->setDocumentBuyerLegalOrganisation(
					$invoiceData['buyerLegalOrgId'],
					$invoiceData['buyerLegalOrgScheme'],
					$invoiceData['buyerTradingName']
				)
				->addDocumentBuyerGlobalId($invoiceData['buyerGlobalIds'][0]['value'], $invoiceData['buyerGlobalIds'][0]['schemeID'])
				->setDocumentBuyerCommunication(
					$schemeUri,
					$uri
				);

			// If specimen, we set the test flag
			if ($invoiceData['isTestDocument']) {
				$facturxpdf->setIsTestDocument();
			}

			// Add delivery date for section ApplicableHeaderTradeDelivery
			$facturxpdf->setDocumentSupplyChainEvent($invoiceData['documentDeliveryDate']);

			// Add data of project if invoice is into a project
			if ($invoiceData['_project'] instanceof Project) {
				$facturxpdf->setDocumentProcuringProject($invoiceData['_project']->ref, $invoiceData['_project']->title);
			}

			// Add additional referenced documents (Order references) - Disabled for Chorus
			if (!$invoiceData['_chorus']) {
				foreach ($invoiceData['_customerOrderReferenceList'] as $customerOrderRef) {
					if ($customerOrderRef != $invoiceData['orderReference']) {
						$facturxpdf->addDocumentAdditionalReferencedDocument($customerOrderRef, "130");
					}
				}
			}

			// Set Trade Contact details (sale representative)
			$facturxpdf->setDocumentSellerContact(
				$invoiceData['sellercontactpersonname'],
				"",
				$invoiceData['sellercontactphoneno'],
				$invoiceData['sellercontactfaxno'],
				$invoiceData['sellercontactemailaddr']
			);

			// Set Buyer Reference (Service Code for Chorus)
			if (!empty($invoiceData['buyerReference'])) {
				$facturxpdf->setDocumentBuyerReference($invoiceData['buyerReference']);
			}

			// Contract reference
			if (!empty($invoiceData['contractReference'])) {
				$facturxpdf->setDocumentContractReferencedDocument($invoiceData['contractReference']);
			}

			// Commitment number / client ref
			if (!empty($invoiceData['orderReference'])) {
				$facturxpdf->setDocumentBuyerOrderReferencedDocument($invoiceData['orderReference']);
			}

			// Set Business Process ID
			$facturxpdf->setDocumentBusinessProcess($invoiceData['businessProcessId']);

			// Add reference to source invoice for credit notes (BT-25/BT-26)
			if (!empty($invoiceData['invoiceRefDocs'])) {
				foreach ($invoiceData['invoiceRefDocs'] as $refDoc) {
					$facturxpdf->setDocumentInvoiceReferencedDocument($refDoc['ref'], $refDoc['type'], $refDoc['date']);
				}
			}

			// --- Process Invoice Lines ---
			foreach ($linesData as $numligne => $lineData) {
				$facturxpdf
					->addNewPosition($lineData['lineid'])
					->setDocumentPositionProductDetails($lineData['prodname'], $lineData['proddesc'], $lineData['prodsellerid'])
					->setDocumentPositionGrossPrice($lineData['grosspriceamount'])
					->setDocumentPositionNetPrice($lineData['netpriceamount'])
					->setDocumentPositionQuantity($lineData['billedquantity'], $lineData['billedquantityunitcode'])
					->setDocumentPositionLineSummation($lineData['lineTotalAmount']);

				// Add reference to original invoice for deposit lines
				if ($lineData['isDepositLine']) {
					$facturxpdf->setDocumentInvoiceReferencedDocument($lineData['depositInvoiceRef'], ZugferdInvoiceType::PREPAYMENTINVOICE, $lineData['depositInvoiceDate']);
				}

				// Set billing period for the line
				if ($lineData['linePeriodStart'] !== null && $lineData['linePeriodEnd'] !== null) {
					$facturxpdf->setDocumentPositionBillingPeriod($lineData['linePeriodStart'], $lineData['linePeriodEnd']);
				}

				// Handle negative amount lines as a line discount
				if ($lineData['grosspriceamount'] < 0) {
					dol_syslog("EInvoicing : there is negative line, convert as a global discount", \LOG_INFO);
					$facturxpdf->addDocumentPositionGrossPriceAllowanceCharge(abs($lineData['grosspriceamount']) * $lineData['billedquantity'], false, null, null, "Discount");
				}

				// VAT information (Line Tax)
				if ($lineData['rateApplicablePercent'] > 0) {
					$facturxpdf->addDocumentPositionTax($lineData['categoryCode'], 'VAT', $lineData['rateApplicablePercent']);
				} else {
					$facturxpdf->addDocumentPositionTax($lineData['categoryCode'], 'VAT', '0.00');
				}

				// Discount percentage on a line
				if ($lineData['lineremisepercent'] !== 'NA' && $lineData['lineremisepercent'] > 0) {
					$remise_amount = $lineData['lineTotalAmount'] - $lineData['grosspriceamount'] * $lineData['billedquantity'];
					dol_syslog("EInvoicing : there is a discount on that line : " . $lineData['lineremisepercent'] . ", amount is " . $remise_amount);
					$facturxpdf->addDocumentPositionAllowanceCharge(abs($remise_amount), false, $lineData['lineremisepercent'], $lineData['grosspriceamount'] * $lineData['billedquantity'], null, "Discount");
				}
			}

			// Final Document Summation and Payment Means

			// Multi VAT (Document Tax Summary)
			foreach ($invoiceData['taxBreakdown'] as $k => $v) {
				$code = ($k == 0) ? 'K' : 'S';
				$facturxpdf->addDocumentTax($code, "VAT", $v['totalHT'], $v['totalTVA'], $k);
			}

			// Set final summation details
			$facturxpdf
				->setDocumentSummation(
					$invoiceData['grandTotalAmount'],
					$invoiceData['duePayableAmount'],
					$invoiceData['lineTotalAmount'],
					$invoiceData['chargeTotalAmount'],
					$invoiceData['allowanceTotalAmount'],
					$invoiceData['taxBasisTotalAmount'],
					$invoiceData['taxTotalAmount'],
					null,
					$invoiceData['totalPrepaidAmount']
				)
				->addDocumentPaymentTerm(
					$invoiceData['paymentTermsText'],
					$invoiceData['paymentDueDate']
				)
				->addDocumentPaymentMean(
					$invoiceData['paymentMeansCode'],
					$invoiceData['paymentMeansText'],
					null,
					null,
					null,
					null,
					$invoiceData['iban'],
					$invoiceData['accountName'],
					$einvoicing->removeSpaces($account->number),
					$invoiceData['bic']
				);

			// Validate the generated XML document
			dol_syslog(get_class($this) . '::executeHooks try to validate XML');

			$pdfCheck = new ZugferdDocumentValidator($facturxpdf);
			$res = $pdfCheck->validateDocument();
			if (count($res) > 0) {
				$allErrors = "";
				foreach ($res as $error) {
					if (is_array($error)) {
						$allErrors .= json_encode($error) . "\n";
					} else {
						$allErrors .= $error . "\n";
					}
				}
				$this->errors = $allErrors;
				dol_syslog(get_class($this) . '::executeHooks  (1) : ' . $allErrors, LOG_ERR);
			} else {
				dol_syslog(get_class($this) . '::executeHooks XML validation ok');
			}

			// Generate the XML file Factur-X
			$filename = dol_sanitizeFileName($invoice->ref);
			$filedir  = getMultidirOutputCompat($invoice, '', 1, 'temp');   // Example '/mydolibarr/documents/facture/temp/FAYYMM-XXXX'
			$xmlfile  = $filedir . '/' . $filename . '/factur-x3.xml';

			dol_mkdir(dirname($xmlfile));
			dol_delete_file($xmlfile);

			$facturxpdf->writeFile($xmlfile);

			// Patch the generated XML for better EXTENDED-CTC-FR compatibility
			if ($invoice->type == $invoice::TYPE_CREDIT_NOTE || !empty($invoiceData['_depositlines'])) {
				dol_syslog(get_class($this) . '::executeHooks Patch XML for better EXTENDED-CTC-FR compatibility');
				$patcher    = new XmlPatcher($facturxpdf);
				$patchedXml = $patcher->patchXmlString($xmlfile, $invoiceData['_depositlines']);
				file_put_contents($xmlfile, $patchedXml);
			}

			dolChmod($xmlfile);

			return $xmlfile;
		}
	}

	/**
	 * Generate a complete Factur-X invoice file by embedding the XML into a PDF.
	 *
	 * This function combines the invoice data with its corresponding XML
	 * to produce a final hybrid document ready for exchange or archiving.
	 *
	 * @param 	int|Object 	$invoice_id    	Invoice ID or Invoice Object to be processed.
	 * @param	?Translate	$outputlangs	Output language
	 * @param	string		$sourceFilePath	Full path of the source document produced by the doc generator (PDF, or ODT/ODS whose PDF rendition is reused). Empty = resolve from the output directory.
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


		// Load EInvoicing specific translations
		$langs->loadLangs(array("admin", "einvoicing@einvoicing"));

		$filename = dol_sanitizeFileName($invoice->ref);
		$filedir = getMultidirOutputCompat($invoice, '', 1);		// Example '/mydolibarr/documents/facture/FAYYMM-XXXX'

		// Resolve the source PDF into which the Factur-X XML will be embedded.
		// Priority:
		//   1. $sourceFilePath provided by the generation hook (afterPDFCreation / afterODTCreation).
		//      ODT/ODS models hand over the .odt path; the PDF rendition (MAIN_ODT_AS_PDF) shares the basename.
		//   2. the most recent <ref>*.pdf already present in the output dir (manual generation, ODT output
		//      like <ref>_Template.pdf for which last_main_doc is not maintained), excluding our own output.
		//   3. legacy <ref>.pdf, regenerated with the default PDF model if missing.
		$orig_pdf = '';
		$fromodt = false;
		if (!empty($sourceFilePath)) {
			if (preg_match('/\.(odt|ods)$/i', $sourceFilePath)) {
				$fromodt = true;
				$orig_pdf = preg_replace('/\.(odt|ods)$/i', '.pdf', $sourceFilePath);
			} else {
				$orig_pdf = $sourceFilePath;
			}
		} else {
			$candidates = dol_dir_list($filedir, 'files', 0, '', '', 'date', SORT_DESC);
			foreach ($candidates as $cand) {
				if (!preg_match('/\.pdf$/i', $cand['name'])) {
					continue;
				}
				if (preg_match('/_facturx\.pdf$/i', $cand['name'])) {		// skip our own Factur-X output
					continue;
				}
				if (strpos($cand['name'], $filename) !== 0) {				// must belong to this invoice ref
					continue;
				}
				$orig_pdf = $cand['fullname'];								// list is sorted by date desc: newest first
				break;
			}
		}
		if (empty($orig_pdf)) {
			$orig_pdf = $filedir . '/' . $filename . '.pdf';				// legacy default
		}

		// If the source PDF is missing, decide whether we can recover.
		if (!file_exists($orig_pdf)) {
			if ($fromodt && !getDolGlobalString('MAIN_ODT_AS_PDF')) {
				// ODT invoice template without a PDF rendition: there is no PDF carrier for Factur-X.
				dol_syslog(get_class($this) . "::generateInvoice ODT template without MAIN_ODT_AS_PDF, no PDF carrier for Factur-X, invoice id=" . $invoice_id, LOG_ERR);
				$this->error = $langs->trans("ErrorEInvoiceRequiresPdfEnableMainOdtAsPdf");
				$this->errors[] = $this->error;
				return -1;
			}
			// Source PDF deleted or never generated: regenerate it with the default PDF model before embedding.
			$modelname = getDolGlobalString('FACTURE_ADDON_PDF') ?: 'crabe';
			$resultpdf = $invoice->generateDocument($modelname, $langs);
			if ($resultpdf < 0) {
				dol_syslog(get_class($this) . "::generateInvoice failed to regenerate missing PDF for invoice id=" . $invoice_id, LOG_ERR);
				$this->error = $langs->trans("ErrorFailedToRegeneratePDF");
				$this->errors[] = $this->error;
				return -1;
			}
			$orig_pdf = $filedir . '/' . $filename . '.pdf';				// generateDocument writes <ref>.pdf
		}

		// Make a copy of the original PDF file
		$pathfacturxpdf = $filedir . '/' . $filename . '_facturx.pdf';	// The new name of the PDF including xml
		if (dol_copy($orig_pdf, $pathfacturxpdf)) {
			dol_syslog(get_class($this) . "::generateInvoice copied original PDF to " . $pathfacturxpdf);
		} else {
			dol_syslog(get_class($this) . "::generateInvoice failed to copy original PDF to " . $pathfacturxpdf, LOG_ERR);
			$this->error = $langs->trans("ErrorFailToCopyFile", $orig_pdf, $pathfacturxpdf);
			$this->errors[] = $this->error;
			return -1;
		}

		// Initial PDF File Pre-check ---
		$precheck = false;
		if (file_exists($pathfacturxpdf) && is_readable($pathfacturxpdf)) {
			$finfo = finfo_open(FILEINFO_MIME_TYPE);
			if (finfo_file($finfo, $pathfacturxpdf) == 'application/pdf') {
				$precheck = true;
			}
		}

		// Check if the source PDF is valid, log error and exit if not.
		if ($precheck == false) {
			dol_syslog(get_class($this) . "::executeHooks orig pdf file does not exists, can't create facturX");
			$this->error = 'Orig pdf file does not exists, can t create facturX';
			$this->errors[] = $this->error;
			return -1;
		}

		clearstatcache(true);


		// Embed the XML file $xmlfile into the file $pathfacturxpdf (that was copied from $orig_pdf) using FPDI and overwrite it.
		// 2 methods are provided depending on the version of Dolibarr.
		// TODO A third method can be tried using the atgp/factur-x library.

		if (class_exists('FPDF', false) && is_subclass_of('FPDF', 'TCPDF')) {
			// Generate the PDF including the XML using the TCPDF library.
			// Bugged version that include the factur-x.xml file twice in the PDF. Only Acrobat Reader show there is 2 files, other PDF reader works correctly showing one file.
			// But it works with Esalink and is the only solution when Dolibarr < 24.0 because such version have a class FPDF provided by default in Dolibarr
			// that is in conflict with the class FPDF provided by the module einvoicing and the library horstoeko/zugferd.
			$pdf = pdf_getInstance();
			$pagecount = $pdf->setSourceFile($pathfacturxpdf);

			// import all pages of the original PDF
			for ($i = 1; $i <= $pagecount; $i++) {
				$tpl = $pdf->importPage($i);
				$pdf->addPage();
				$pdf->useTemplate($tpl);
			}

			// Embed the XML file as a file attachment in the PDF
			if (file_exists($xmlfile)) {
				$pdf->Annotation(10, 10, 5, 5, 'factur-x.xml', array(
					'Subtype' => 'FileAttachment',
					'Name' => 'PushPin',
					'FS' => $xmlfile
				));
			}

			// Restore metadata from original PDF.
			if (function_exists('pdfExtractMetadata')) {	// From Dolibarr v22
				// Now we get the metadata keywords from the $sourcefile PDF (by parsing the binary PDF file)
				$keywords = pdfExtractMetadata($pathfacturxpdf, 'Keywords');
				$subject = pdfExtractMetadata($pathfacturxpdf, 'Subject');
				$author = pdfExtractMetadata($pathfacturxpdf, 'Author');
				$creator = pdfExtractMetadata($pathfacturxpdf, 'Creator');

				if (!preg_match('/^ERROR/', $keywords)) {
					$pdf->setKeywords($keywords);
				}
				if (!preg_match('/^ERROR/', $subject)) {
					$pdf->setSubject($subject);
				}
				if (!preg_match('/^ERROR/', $author)) {
					$pdf->setAuthor($author);
				}
				if (!preg_match('/^ERROR/', $creator)) {
					$pdf->setCreator($creator);
				}
			}

			// Save the final PDF with the embedded XML
			$pdf->Output($pathfacturxpdf, 'F');
		} else {
			// Generate the PDF including the XML using the horstoeko/zugferd library.
			// This can works with Dolibarr 24.0+ only, because the previous version of Dolibarr was already including a FPDF class that is
			// in conflict with the one provided by horstoeko/zugferd library.
			if (!file_exists($orig_pdf)) {
				throw new \Exception("XML and/or PDF does not exist");
			}

			// Restore metadata from original PDF.
			// horstoeko/zugferd setters require non-null strings, so default to '' for Dolibarr
			// versions that do not ship pdfExtractMetadata() (v18 / v19); v22+ overwrites these
			// with the actual values parsed from the source PDF.
			$keywords = '';
			$subject = '';
			$author = '';
			$creator = '';
			if (function_exists('pdfExtractMetadata')) {	// From Dolibarr v22
				// Now we get the metadata keywords from the $sourcefile PDF (by parsing the binary PDF file)
				$keywords = (string) pdfExtractMetadata($orig_pdf, 'Keywords');
				$subject = (string) pdfExtractMetadata($orig_pdf, 'Subject');
				$author = (string) pdfExtractMetadata($orig_pdf, 'Author');
				$creator = (string) pdfExtractMetadata($orig_pdf, 'Creator');
			}

			$merger = new ZugferdDocumentPdfMerger($xmlfile, $orig_pdf);

			$merger->setKeywordTemplate($keywords);
			$merger->setSubjectTemplate($subject);
			$merger->setAuthorTemplate($author);
			$merger->setAdditionalCreatorTool($creator);

			$merger->generateDocument();

			$merger->saveDocument($pathfacturxpdf);
		}


		// Clean up the temporary XML file
		if (file_exists($xmlfile) && !getDolGlobalString('EINVOICING_DEBUG_MODE')) {
			dol_delete_file($xmlfile);
			dol_syslog(get_class($this) . '::generateInvoice cleaned up temporary XML file: ' . $xmlfile);
		}

		// Add afterEinvoiceCreation hook
		global $action, $hookmanager;
		$hookmanager->initHooks(array('einvoicegeneration'));
		$parameters = array('protocol' => 'factur-x', 'file' => $orig_pdf, 'object' => $invoice, 'outputlangs' => $langs);
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
		$this->checkFileSizeLimit($pathfacturxpdf);

		return $pathfacturxpdf;		// Name of generated Einvoice
	}


	/**
	 * Generate a sample Factur-X invoice for demonstration or testing purposes (for Dolibarr version < 24.0)
	 *
	 * This method creates a dummy invoice with representative data
	 * to illustrate the Factur-X structure without using real business information.
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
		global $conf, $langs, $mysoc;

		dol_mkdir($conf->einvoicing->dir_temp);

		$outputlangs = $langs;		// TODO Use the target language
		$outputlangs->load("einvoicing@einvoicing");

		require __DIR__ . "/ExampleHelpers.php";

		$existingPdfFilename = __DIR__ . "/../../assets/00_ZugferdDocumentPdfBuilder_PrintLayout.pdf";
		$newPdfFilename = $conf->einvoicing->dir_temp . "/INVTEST-".dol_print_date(dol_now(), '%y%m%d-%H%M%S').".pdf";
		//$AdditionalDocument = __DIR__ . "/../../assets/00_AdditionalDocument.csv";

		// First we create a new valid document in EN16931-Profile (== COMFORT-Profile)
		// See examples/01_ZugferdDocumentBuilder_EN16931.php for detailed explanations

		$documentBuilder = ZugferdDocumentBuilder::createNew(ZugferdProfiles::PROFILE_EN16931);

		$invoicetype = ZugferdInvoiceType::INVOICE;				// Type "Invoice" (BT-3)
		if (!empty($options['invoicetype'])) {
			if ($options['invoicetype'] == Facture::TYPE_CREDIT_NOTE) {
				$invoicetype = ZugferdInvoiceType::CREDITNOTE;
			}
		}

		$documentBuilder->setDocumentInformation(
			'INVTEST',                                     	// Invoice Number (BT-1)
			$invoicetype,                        			// Type "Invoice" (BT-3)
			DateTime::createFromFormat("Ymd", "20241231"),  // Invoice Date (BT-2)
			ZugferdCurrencyCodes::EURO                      // Invoice currency is EUR (Euro) (BT-5)
		);

		$sellername = $mysoc->name ?: "MyBigCompanyTest";
		$sellervat = $mysoc->tva_intra ?: "FRVAT123456";

		$mySchemeIdProf = "0002";
		//$sellerid = $einvoicing->getSellerCommunicationURI(0);
		$sellerid = idprof($mysoc);														// May be SIREN

		$mySchemeGlobalId = "0225";
		//$sellerglobalid = $einvoicing->getSellerCommunicationURI(0);
		$sellerglobalid = idprof($mysoc);

		if ($mySchemeIdProf == "0002" && strlen($sellerid) != 9) {	// If einvoice ID is French SIREN, we check it has 9 chars.
			throw new Exception('BADPROFID (generateSampleInvoiceOld): The professional ID ' . $sellerid . ' has type SIREN but length is not 9 characters. Fix this in your company or einvoice module setup page.');
		}

		$documentBuilder->addDocumentNote($sellername . PHP_EOL . 'Lieferantenstraße 20' . PHP_EOL . '80333 München' . PHP_EOL . 'Deutschland' . PHP_EOL . 'Geschäftsführer: Hans Muster' . PHP_EOL . 'Handelsregisternummer: H A 123' . PHP_EOL . PHP_EOL, null, 'REG');


		$documentBuilder->addDocumentNote(getDolGlobalString('EINVOICING_PMT') ?: $outputlangs->trans('NoInvoiceCollectionFees'), null, "PMT");
		$documentBuilder->addDocumentNote(getDolGlobalString('EINVOICING_PMD') ?: $outputlangs->trans('NoLatePaymentFees'), null, "PMD");
		$documentBuilder->addDocumentNote(getDolGlobalString('EINVOICING_AAB') ?: $outputlangs->trans('NoEarlyPaymentDiscount'), null, "AAB");


		$documentBuilder->setDocumentBillingPeriod(DateTime::createFromFormat("Ymd", "20250101"), DateTime::createFromFormat("Ymd", "20250131"), "01.01.2025 - 31.01.2025");

		$documentBuilder->addDocumentInvoiceSupportingDocumentWithUri('FA2401-000001', 'https://publiclinktoinvoice', 'LISIBLE');
		$documentBuilder->addDocumentInvoiceSupportingDocumentWithUri('SO2401-000001', 'https://linktoorder', 'BON_COMMANDE');
		//$documentBuilder->addDocumentInvoiceSupportingDocumentWithFile('REFDOC-2024/00001-2', $AdditionalDocument, 'Herkunftsnachweis Trennblätter');

		$documentBuilder->addDocumentTenderOrLotReferenceDocument('LOS 738625');
		$documentBuilder->addDocumentInvoicedObjectReferenceDocument('125', ZugferdReferenceCodeQualifiers::SALE_PERS_NUMB); // Sales person number

		$documentBuilder->setDocumentContractReferencedDocument('CO2401-000001');			// Ref of contract

		$documentBuilder->setDocumentProcuringProject('PR2401-000001', 'Project label');	// Ref of project

		$documentBuilder->addDocumentPaymentMeanToDirectDebit("DE12500105170648489890", "INV-TEST");
		$documentBuilder->addDocumentPaymentTerm('Wird von Konto DE12500105170648489890 abgebucht', DateTime::createFromFormat("Ymd", "20250131"), 'MANDATE-2024/000001');

		$documentBuilder->setDocumentSeller($sellername, $sellerid);
		$documentBuilder->setDocumentSellerLegalOrganisation($sellerid, $mySchemeIdProf, $sellername);	// Mandatory: 0002 = SIREN
		$documentBuilder->addDocumentSellerGlobalId($sellerglobalid, $mySchemeGlobalId);				// Optional : 0225 = SIREN. Can be a more international code like DUNS

		//$documentBuilder->setSpecifiedLegalOrganization();
		$documentBuilder->addDocumentSellerTaxNumber($sellervat);
		$documentBuilder->addDocumentSellerVATRegistrationNumber($sellervat);

		$documentBuilder->setDocumentSellerAddress("Lieferantenstraße 20", "", "", "80333", "München", ZugferdCountryCodes::GERMANY);
		$documentBuilder->setDocumentSellerContact("H. Müller", "", "+49-111-2222222", "+49-111-3333333", "hm@lieferant.de");

		//$documentBuilder->setDocumentSellerCommunication(ZugferdElectronicAddressScheme::UNECE3155_EM, 'sales@lieferant.de');
		$myUri             = $einvoicing->getSellerCommunicationURI(0);			// Example "315143296_1939"
		$mySchemeUri       = $this->getIEC6523Code($mysoc->country_code, 2);		// Example "0225"
		$documentBuilder->setDocumentSellerCommunication($mySchemeUri, $myUri);

		$documentBuilder->setDocumentBuyer("Kunden AG Mitte", "GE2020211");
		$documentBuilder->setDocumentBuyerAddress("Kundenstraße 15", "", "", "69876", "Frankfurt", ZugferdCountryCodes::GERMANY);
		$documentBuilder->setDocumentBuyerContact("H. Meier", "", "+49-333-4444444", "+49-333-5555555", "hm@kunde.de");

		//$documentBuilder->setDocumentBuyerCommunication(ZugferdElectronicAddressScheme::UNECE3155_EM, 'purchase@kunde.de');
		$buyerUri             = $einvoicing->getBuyerCommunicationURI(null);		// Example "315143296_1940"
		$buyerSchemeUri       = $this->getIEC6523Code($mysoc->country_code, 2);		// Example "0225"
		if (empty($buyerUri)) {
			$buyerUri = '315143296_1940';
		}
		$documentBuilder->setDocumentBuyerCommunication($buyerSchemeUri, $buyerUri);

		$documentBuilder->setDocumentPayee('Kunden AG Zahlungsdienstleistung');
		$documentBuilder->setDocumentBuyerOrderReferencedDocument("PO-2024-0003324");
		$documentBuilder->setDocumentSellerOrderReferencedDocument('SO-2024-000993337');

		// If there is a delivery address
		$documentBuilder->setDocumentShipTo("Kunden AG Ost");
		$documentBuilder->setDocumentShipToAddress("Lieferstraße 1", "", "", "04109", "Leipzig", ZugferdCountryCodes::GERMANY);
		$documentBuilder->setDocumentSupplyChainEvent(DateTime::createFromFormat("Ymd", "20250115"));

		$documentBuilder->addNewPosition("1");
		$documentBuilder->setDocumentPositionProductDetails("Trennblätter A4", "50er Pack", "TB100A4");
		$documentBuilder->setDocumentPositionNetPrice(9.9000);
		$documentBuilder->setDocumentPositionQuantity(20, ZugferdUnitCodes::REC20_PIECE);
		$vatrate = 20;
		if (!$this->checkIfVatRateIsValid($vatrate, $mysoc->country_code)) {
			throw new Exception('BADVATRATE: The VAT rate ' . $vatrate . ' on line is not a valid string value for country ' . $mysoc->country_code . '.');
		}
		$documentBuilder->addDocumentPositionTax(ZugferdVatCategoryCodes::STAN_RATE, ZugferdVatTypeCodes::VALUE_ADDED_TAX, $vatrate);
		$documentBuilder->setDocumentPositionLineSummation(198.0);

		$documentBuilder->addNewPosition("2");
		$documentBuilder->setDocumentPositionProductDetails("Joghurt Banane", "B-Ware", "ARNR2");
		$documentBuilder->setDocumentPositionNetPrice(5.5000);
		$documentBuilder->setDocumentPositionQuantity(50, ZugferdUnitCodes::REC20_PIECE);
		$vatrate = 7;
		if (!$this->checkIfVatRateIsValid($vatrate, $mysoc->country_code)) {
			throw new Exception('BADVATRATE: The VAT rate ' . $vatrate . ' on line is not a valid string value for country ' . $mysoc->country_code . '.');
		}
		$documentBuilder->addDocumentPositionTax(ZugferdVatCategoryCodes::STAN_RATE, ZugferdVatTypeCodes::VALUE_ADDED_TAX, $vatrate);
		$documentBuilder->setDocumentPositionLineSummation(275.0);

		$documentBuilder->addNewPosition("3");
		$documentBuilder->setDocumentPositionProductDetails("Joghurt Erdbeer", "", "ARNR3");
		$documentBuilder->setDocumentPositionNetPrice(4.0000);
		$documentBuilder->setDocumentPositionQuantity(100, ZugferdUnitCodes::REC20_PIECE);
		$vatrate = 7;
		if (!$this->checkIfVatRateIsValid($vatrate, $mysoc->country_code)) {
			throw new Exception('BADVATRATE: The VAT rate ' . $vatrate . ' on line is not a valid string value for country ' . $mysoc->country_code . '.');
		}
		$documentBuilder->addDocumentPositionTax(ZugferdVatCategoryCodes::STAN_RATE, ZugferdVatTypeCodes::VALUE_ADDED_TAX, $vatrate);

		$documentBuilder->setDocumentPositionLineSummation(400.0);

		// Add total of vat per vat rate
		$documentBuilder->addDocumentTax(ZugferdVatCategoryCodes::STAN_RATE, ZugferdVatTypeCodes::VALUE_ADDED_TAX, 198.0, 39.60, 20.0);
		$documentBuilder->addDocumentTax(ZugferdVatCategoryCodes::STAN_RATE, ZugferdVatTypeCodes::VALUE_ADDED_TAX, 675.0, 47.25, 7.0);

		$documentBuilder->setDocumentSummation(959.85, 959.85, 873.00, 0.0, 0.0, 873.00, 86.85);


		// This is a test doc
		$documentBuilder->setIsTestDocument();


		// Next let's do the ZugferddocumentPdfBuilder it's job - let's attach the XML to the PDF. The attachment filename will be factur-x.xml
		// since we chose the profile EN16931 in the ZugferdDocumentBuilder (see above)
		// In the following there are multiple methods how you can build a conform PDF from an existing print layout

		// First method: Merge the generated XML from ZugferdDocumentBuilder with an existing print layout file to a new PDF file

		/*
		$zugferdDocumentPdfBuilder = ZugferdDocumentPdfBuilder::fromPdfFile($documentBuilder, $existingPdfFilename);
		$zugferdDocumentPdfBuilder->generateDocument();
		$zugferdDocumentPdfBuilder->saveDocument($newPdfFilename);
		*/

		// Second method: Merge the generated XML from ZugferdDocumentBuilder with an stream (string) which contains the PDF to a new PDF file

		// Note: We simulate the PDF stream (string) by calling file_get_contents.
		/*
		$pdfContent = file_get_contents($existingPdfFilename);

		$zugferdDocumentPdfBuilder = ZugferdDocumentPdfBuilder::fromPdfString($documentBuilder, $pdfContent);
		$zugferdDocumentPdfBuilder->generateDocument();
		$zugferdDocumentPdfBuilder->saveDocument($newPdfFilename);
		*/

		// There is not only the saveDocument method of the ZugferdDocumentPdfBuilder. It is also possible to receive the merged
		// content (PDF with embedded XML) as a stream (string)

		/*
		$mergedPdfContent = $zugferdDocumentPdfBuilder->downloadString();

		$pdfContent = file_get_contents($existingPdfFilename);

		$zugferdDocumentPdfBuilder = ZugferdDocumentPdfBuilder::fromPdfString($documentBuilder, $pdfContent);
		$zugferdDocumentPdfBuilder->generateDocument();
		$zugferdDocumentPdfBuilder->saveDocument($newPdfFilename);
		*/

		// And last but not least, it is also possible to add additional attachments to the merged PDF. These can be any files that can help the invoice
		// recipient with processing. For example, a time sheet as an Excel file would be conceivable.
		// The method attachAdditionalFileByRealFile has 3 parameters:
		// - The file to attach which must exist and must be readable
		// - (Optional) A name to display in the attachments of the PDF
		// - (Optional) The type of the relationship of the attachment. Valid values are defined in the class ZugferdDocumentPdfBuilderAbstract. The constants are starting with AF_
		// If you omit the last 2 parameters the following will happen:
		// - The displayname is calculated from the filename you specified
		// - The type of the relationship of the attachment will be AF_RELATIONSHIP_SUPPLEMENT (Supplement)

		/*
		$zugferdDocumentPdfBuilder = ZugferdDocumentPdfBuilder::fromPdfString($documentBuilder, $pdfContent);
		$zugferdDocumentPdfBuilder->attachAdditionalFileByRealFile($AdditionalDocument, "Some display Name", ZugferdDocumentPdfBuilderAbstract::AF_RELATIONSHIP_SUPPLEMENT);
		$zugferdDocumentPdfBuilder->generateDocument();
		$zugferdDocumentPdfBuilder->saveDocument($newPdfFilename);

		// You can also add an attachment to the PDF as an stream (string). The conditions are the same as above for the attachAdditionalFileByRealFile method
		// The only difference to attachAdditionalFileByRealFile is that the attachAdditionalFileByContent method accepts 4 parameters, whereby here (as with attachAdditionalFileByRealFile)
		// the last two can be omitted. You only need to specify a file name under which the file is to be embedded
		// Note: We simulate the attachment stream (string) by calling file_get_contents.

		$attachmentContent = file_get_contents($AdditionalDocument);

		$zugferdDocumentPdfBuilder = ZugferdDocumentPdfBuilder::fromPdfString($documentBuilder, $pdfContent);
		$zugferdDocumentPdfBuilder->attachAdditionalFileByContent($attachmentContent, 'additionalDocument.csv', "Some other display Name", ZugferdDocumentPdfBuilderAbstract::AF_RELATIONSHIP_SUPPLEMENT);
		$zugferdDocumentPdfBuilder->generateDocument();
		$zugferdDocumentPdfBuilder->saveDocument($newPdfFilename);
		*/

		// Set values for metadata-fields
		// We can change some meta information such as the title, the subject, the author and the keywords.  This library essentially provides 4 methods for this.
		// These methods use so-called templates. These methods are:

		// The 4 methods just mentioned accept a free text that can accept the following placeholders:
		// - %1$s .... contains the invoice number (is extracted from the XML data)
		// - %2$s .... contains the type of XML document, such as ‘Invoice’ (is extracted from the XML data)
		// - %3$s .... contains the name of the seller (extracted from the XML data)
		// - %4$s .... contains the invoice date (extracted from the XML data)
		// The following example generates...
		// - the author:  .... Issued by seller with name Lieferant GmbH
		// - the title    .... Lieferant GmbH : Invoice INV-TEST
		// - the subject  .... Invoice-Document, Issued by Lieferant GmbH
		// - the keywords .... INV-TEST, Invoice, Lieferant GmbH, 2024-12-31

		// This rebuild the PDF including the XML and added PDF metadata.
		$zugferdDocumentPdfBuilder = ZugferdDocumentPdfBuilder::fromPdfFile($documentBuilder, $existingPdfFilename);
		$zugferdDocumentPdfBuilder->setAuthorTemplate('Issued by %3$s');
		$zugferdDocumentPdfBuilder->setTitleTemplate('%3$s : %2$s SPECIMEN %1$s');
		$zugferdDocumentPdfBuilder->setSubjectTemplate('%2$s-Document, Issued by %3$s - Dolibarr ' . DOL_VERSION);
		$zugferdDocumentPdfBuilder->setKeywordTemplate('%1$s, %2$s, %3$s, %4$s, Dolibarr');
		// If you would like to brand the merged PDF with the name of you own solution you can call
		// the method setAdditionalCreatorTool. Before calling this method the creator of the PDF is identified as 'Factur-X library 1.x.x by HorstOeko'.
		// After calling this method you get 'MyERPSolution 1.0 / Factur-X PHP library 1.x.x by HorstOeko' as the creator
		$zugferdDocumentPdfBuilder->setAdditionalCreatorTool('Dolibarr generateSampleInvoiceOld - ' . DOL_VERSION);
		$zugferdDocumentPdfBuilder->generateDocument();
		$zugferdDocumentPdfBuilder->saveDocument($newPdfFilename);

		return array('path' => $newPdfFilename, 'ref' => 'INV-TEST');
	}



	/**
	 * Create a supplier invoice from a Factur-X PDF file and attach the file (and readable file if exists) to the document.
	 * This may create the Supplier and the Product depending on setup.
	 *
	 * @param  string 			$file                       		Source string file (PDF string). We use this file to get data of supplier invoice.
	 * @param  string|null 		$ReadableViewFile        			Readable view file (PDP Generated readable PDF). We only store it if available.
	 * @param  string 			$flowId                       		Flow identifier source of the invoice.
	 * @return array{res:int<-1,1>, message:string, action?:string|null}   Returns array with 'res' (1 on success, 0 already exists, -1 on failure) with a 'message' and an optional 'action'.
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

		$tempFile = $tempDir . '/facturx.pdf';
		if (file_put_contents($tempFile, $file) === false) {
			return ['res' => -1, 'message' => 'Failed to save Factur-X file to temporary location'];
		}

		if ($ReadableViewFile) {
			$tempFileReadableView = $tempDir . '/facturx_readable.pdf';
			if (file_put_contents($tempFileReadableView, $ReadableViewFile) === false) {
				return ['res' => -1, 'message' => 'Failed to save readable view file to temporary location'];
			}
		}

		//return ['res' => 1, 'message' => 'bypass' ];

		// --- Create Supplier Invoice object
		require_once DOL_DOCUMENT_ROOT . '/fourn/class/fournisseur.facture.class.php';
		$supplierInvoice = new FactureFournisseur($db);


		// --- Read the Factur-X file
		$document = ZugferdDocumentPdfReader::readAndGuessFromFile($tempFile);
		$embeddedXml = ZugferdDocumentPdfReaderExt::getInvoiceDocumentContentFromFile($tempFile);

		$parsedHeader = [];
		$parsedLines = [];
		if (!getDolGlobalInt('EINVOICING_USE_EXTERNAL_FACTURX_READER')) { // Force use of internal CII reader for testing and development.
			dol_include_once('einvoicing/class/protocols/ProtocolManager.class.php');
			$ProtocolManager = new ProtocolManager($db);
			$CII = $ProtocolManager->getProtocol('CII');

			$parsedHeader = $CII->parseInvoiceHeader($embeddedXml);
			$parsedLines  = $CII->parseInvoiceLines($embeddedXml);
		} else {
			$document->getDocumentInformation($documentno, $documenttypecode, $documentdate, $invoiceCurrency, $taxCurrency, $documentname, $documentlanguage, $effectiveSpecifiedPeriod);

			$document->getDocumentSupplyChainEvent(
				$documentDeliveryDate
			);

			// Get seller information (supplier)
			$document->getDocumentSeller($sellername, $sellerids, $sellerdescription);

			// Get seller address
			$document->getDocumentSellerAddress(
				$sellerlineone,
				$sellerlinetwo,
				$sellerlinethree,
				$sellerpostcode,
				$sellercity,
				$sellercountry,
				$sellersubdivision
			);

			// Get seller contact
			$document->getDocumentSellerContact(
				$sellercontactpersonname,
				$sellercontactdepartmentname,
				$sellercontactphoneno,
				$sellercontactfaxno,
				$sellercontactemailaddr
			);

			$document->getDocumentSellerCommunication(
				$sellerCommunicationUriScheme,
				$sellerCommunicationUri
			);

			// Get document summation
			$document->getDocumentSummation($grandTotalAmount, $duePayableAmount, $lineTotalAmount, $chargeTotalAmount, $allowanceTotalAmount, $taxBasisTotalAmount, $taxTotalAmount, $roundingAmount, $totalPrepaidAmount);

			$document->getDocumentSellerGlobalId(
				$sellerGlobalIds
			);

			$document->getDocumentSellerTaxRegistration(
				$sellerTaxRegistations
			);

			// Get references to the previous invoices if any (for credit notes for example)
			$document->getDocumentInvoiceReferencedDocuments($invoiceRefDocs);

			// Debug: print all retrieved variables
			$parsedHeader = array(
				'documentno' => $documentno ?? null,
				'documenttypecode' => $documenttypecode ?? null,
				'documentdate' => isset($documentdate) && $documentdate instanceof DateTime ? $documentdate->format('Y-m-d') : ($documentdate ?? null),
				'invoiceCurrency' => $invoiceCurrency ?? null,
				'taxCurrency' => $taxCurrency ?? null,
				'documentname' => $documentname ?? null,
				'documentlanguage' => $documentlanguage ?? null,
				'effectiveSpecifiedPeriod' => $effectiveSpecifiedPeriod ?? null,
				'documentDeliveryDate' => isset($documentDeliveryDate) && $documentDeliveryDate instanceof DateTime ? $documentDeliveryDate->format('Y-m-d') : ($documentDeliveryDate ?? null),

				// Seller
				'sellername' => $sellername ?? null,
				'sellerids' => $sellerids ?? null,
				'sellerdescription' => $sellerdescription ?? null,

				// Seller Address
				'sellerlineone' => $sellerlineone ?? null,
				'sellerlinetwo' => $sellerlinetwo ?? null,
				'sellerlinethree' => $sellerlinethree ?? null,
				'sellerpostcode' => $sellerpostcode ?? null,
				'sellercity' => $sellercity ?? null,
				'sellercountry' => $sellercountry ?? null,
				'sellersubdivision' => $sellersubdivision ?? null,

				// Seller Contact
				'sellercontactpersonname' => $sellercontactpersonname ?? null,
				'sellercontactdepartmentname' => $sellercontactdepartmentname ?? null,
				'sellercontactphoneno' => $sellercontactphoneno ?? null,
				'sellercontactfaxno' => $sellercontactfaxno ?? null,
				'sellercontactemailaddr' => $sellercontactemailaddr ?? null,

				// Seller Communication (may be unset due to reader var name)
				'sellerCommunicationUriScheme' => $sellerCommunicationUriScheme ?? null,
				'sellerCommunicationUri' => $sellerCommunicationUri ?? null,

				// Summation
				'grandTotalAmount' => $grandTotalAmount ?? null,
				'duePayableAmount' => $duePayableAmount ?? null,
				'lineTotalAmount' => $lineTotalAmount ?? null,
				'chargeTotalAmount' => $chargeTotalAmount ?? null,
				'allowanceTotalAmount' => $allowanceTotalAmount ?? null,
				'taxBasisTotalAmount' => $taxBasisTotalAmount ?? null,
				'taxTotalAmount' => $taxTotalAmount ?? null,
				'roundingAmount' => $roundingAmount ?? null,
				'totalPrepaidAmount' => $totalPrepaidAmount ?? null,

				// Seller Global Ids and Tax Registrations (may be unset due to reader var name)
				'sellerGlobalIds' => $sellerGlobalIds ?? null,
				'sellerTaxRegistations' => $sellerTaxRegistations ?? null,

				// Invoice referenced documents
				'invoiceRefDocs' => $invoiceRefDocs ?? null,
			);


			// Read invoice lines
			$additionalRefDocs = [];
			if ($document->firstDocumentPosition()) {
				do {
					// Get line information
					$document->getDocumentPositionGenerals($lineid, $linestatuscode, $linestatusreasoncode);
					$document->getDocumentPositionProductDetails($prodname, $proddesc, $prodsellerid, $prodbuyerid, $prodglobalidtype, $prodglobalid);
					$document->getDocumentPositionGrossPrice($grosspriceamount, $grosspricebasisquantity, $grosspricebasisquantityunitcode);
					$document->getDocumentPositionNetPrice($netpriceamount, $netpricebasisquantity, $netpricebasisquantityunitcode);
					$document->getDocumentPositionLineSummation($lineTotalAmount, $totalAllowanceChargeAmount);
					$document->getDocumentPositionQuantity($billedquantity, $billedquantityunitcode, $chargeFreeQuantity, $chargeFreeQuantityunitcode, $packageQuantity, $packageQuantityunitcode);

					// Get AdditionalReferencedDocument at line level
					$patcher = new XmlPatcher(null, $embeddedXml);
					$additionalRefDocs[$lineid] = $patcher->getLineAdditionalReferencedDocuments($lineid);

					// Get tax information for the line
					//$vatRate = 0;
					if ($document->firstDocumentPositionTax()) {
						$document->getDocumentPositionTax($categoryCode, $typeCode, $rateApplicablePercent, $calculatedAmount, $exemptionReason, $exemptionReasonCode);
						//$vatRate = $rateApplicablePercent;
					}

					$parsedLines[] = array(
						'lineid' => $lineid ?? null,
						'linestatuscode' => $linestatuscode ?? null,
						'linestatusreasoncode' => $linestatusreasoncode ?? null,
						'prodname' => $prodname ?? null,
						'proddesc' => $proddesc ?? null,
						'prodsellerid' => $prodsellerid ?? null,
						'prodbuyerid' => $prodbuyerid ?? null,
						'prodglobalidtype' => $prodglobalidtype ?? null,
						'prodglobalid' => $prodglobalid ?? null,
						'grosspriceamount' => $grosspriceamount ?? null,
						'grosspricebasisquantity' => $grosspricebasisquantity ?? null,
						'grosspricebasisquantityunitcode' => $grosspricebasisquantityunitcode ?? null,
						'netpriceamount' => $netpriceamount ?? null,
						'netpricebasisquantity' => $netpricebasisquantity ?? null,
						'netpricebasisquantityunitcode' => $netpricebasisquantityunitcode ?? null,
						'lineTotalAmount' => $lineTotalAmount ?? null,
						'totalAllowanceChargeAmount' => $totalAllowanceChargeAmount ?? null,
						'billedquantity' => $billedquantity ?? null,
						'billedquantityunitcode' => $billedquantityunitcode ?? null,
						'chargeFreeQuantity' => $chargeFreeQuantity ?? null,
						'chargeFreeQuantityunitcode' => $chargeFreeQuantityunitcode ?? null,
						'packageQuantity' => $packageQuantity ?? null,
						'packageQuantityunitcode' => $packageQuantityunitcode ?? null,
						// Tax
						'categoryCode' => $categoryCode ?? null,
						'typeCode' => $typeCode ?? null,
						'rateApplicablePercent' => $rateApplicablePercent ?? null,
						'calculatedAmount' => $calculatedAmount ?? null,
						'ExemptionReason' => $exemptionReason ?? null,
						'ExemptionReasonCode' => $exemptionReasonCode ?? null,
						// Parent invoice ref
						'parentDocumentNo' => $parsedHeader['documentno'] ?? null,
						// Additional referenced documents at line level
						'additionalRefDocs' => $additionalRefDocs[$lineid] ?? null,
					);


					dol_syslog(get_class($this) . '::createSupplierInvoiceFromSource parsedLines: ' . json_encode($parsedLines), LOG_DEBUG);
				} while ($document->nextDocumentPosition());
			}
		}

		// Check if this invoice has already been imported
		$sql = "SELECT rowid as id FROM " . MAIN_DB_PREFIX . "facture_fourn";
		$sql .= " WHERE ref_supplier = '" . $db->escape($parsedHeader['documentno']) . "'";
		$resql = $db->query($sql);
		if ($resql) {
			if ($db->num_rows($resql) > 0) {
				$supplierInvoiceId = $db->fetch_object($resql)->id;
				$einvoicing->cleanUpTemporaryFiles(); // Clean up temp files to remove retrieved Factur-X file since invoice already exists

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
		// documentdate est déjà formaté en 'Y-m-d' par les parseurs ZugFerd et CII
		$supplierInvoice->date = !empty($parsedHeader['documentdate']) ? dol_stringtotime($parsedHeader['documentdate']) : null;


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

		// TODO : save AAB, PMD, PMT notes (all notes are grouped into documentNotes)

		// Create the invoice
		$supplierInvoiceId = $supplierInvoice->create($user);

		if ($supplierInvoiceId < 0) {
			return ['res' => -1, 'message' => 'Invoice creation error: ' . $supplierInvoice->error];
		} else {
			// Link the invoice to its purchase order (commande fournisseur) when the order reference
			// (BT-13) matches a single order for the same supplier. Non-blocking. See issue #303.
			$orderLinkMessage = $this->_linkSupplierInvoiceToPurchaseOrder($supplierInvoice, $socId, $parsedHeader['orderReference'] ?? '');
			if ($orderLinkMessage !== '') {
				$return_messages[] = $orderLinkMessage;
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

			// Add entry in einvoicing_extlinks table to mark that this supplier invoice is imported from PDP
			$einvoicing->insertOrUpdateExtLink($supplierInvoiceId, $supplierInvoice->element, $flowId);

			dol_syslog(__METHOD__ . ' New supplier invoice created or updated (ID: ' . $supplierInvoiceId . ')');

			$return_messages[] = 'Supplier Invoice created or updated with ID: ' . $supplierInvoiceId;


			// Save original invoice in supplier invoice attachments
			if ($tempFile && file_exists($tempFile)) {
				$res = $this->_saveFacturXFileToSupplierInvoiceAttachment($supplierInvoice, $tempFile);

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
				$res = $this->_saveFacturXFileToSupplierInvoiceAttachment($supplierInvoice, $tempFileReadableView, getDolGlobalString('EINVOICING_PDP', 'PDP'));

				if ($res['res'] < 0) {
					$return_messages[] = 'Failed to save readable view file as attachment: ' . $res['message'];
				} else {
					$return_messages[] = 'Readable view file saved as attachment';
				}
			} else {
				dol_syslog("Temporary 'readable pdf file' not found for attachment", LOG_ERR);
			}

			// TODO : Save receivedFile in supplier invoice attachments
			return ['res' => $supplierInvoiceId, 'message' => implode("\n", $return_messages), 'xml_data' => $embeddedXml];
		}
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

		$map = [
			ZugferdInvoiceType::PROFORMAINVOICE                 => CommonInvoice::TYPE_PROFORMA,
			ZugferdInvoiceType::INTERIMAPPLICATIONFORPAYMENT    => CommonInvoice::TYPE_SITUATION,

			ZugferdInvoiceType::INVOICE                         => CommonInvoice::TYPE_STANDARD,
			ZugferdInvoiceType::CORRECTION                      => CommonInvoice::TYPE_REPLACEMENT,
			ZugferdInvoiceType::CREDITNOTE                      => CommonInvoice::TYPE_CREDIT_NOTE,
			ZugferdInvoiceType::PREPAYMENTINVOICE               => CommonInvoice::TYPE_DEPOSIT,
			"503"                                               => CommonInvoice::TYPE_CREDIT_NOTE,
		];


		if (!isset($map[$documenttypecode])) {
			dol_syslog(get_class($this) . '::_getDolibarrInvoiceType Unknown document type code: ' . $documenttypecode, LOG_WARNING);
			return '-1';
		}

		return $map[$documenttypecode];
	}


	/**
	 * Save Factur-X file to dolibarr supplier invoice attachment.
	 *
	 * @param FactureFournisseur    $supplierInvoice 	Supplier invoice object
	 * @param string                $filePath        	Path to the Factur-X file to save
	 * @param string                $suffix          	Optional suffix for the saved file name
	 * @return array{res:int, message:string}   		Returns array with 'res' (1 on success, -1 on error) and info 'message'
	 */
	private function _saveFacturXFileToSupplierInvoiceAttachment($supplierInvoice, $filePath, $suffix = '')
	{
		global $conf;

		// Ensure upload directory exists
		$folder_part   = get_exdir(0, 0, 0, 0, $supplierInvoice);
		$relative_path = 'fournisseur/facture/' . $folder_part . dol_sanitizeFileName($supplierInvoice->ref);
		$upload_dir    = $conf->fournisseur->dir_output . '/facture/' . $folder_part . dol_sanitizeFileName($supplierInvoice->ref);

		if (!file_exists($upload_dir)) {
			if (!dol_mkdir($upload_dir)) {
				dol_syslog(__METHOD__ . " Failed to create upload directory: $upload_dir", LOG_ERR);
				return array('res' => -1, 'message' => 'Failed to create upload directory');
			}
		}

		// Prepare destination filename with optional prefix
		$filename  = dol_sanitizeFileName($supplierInvoice->ref_supplier . (empty($suffix) ? '' : '_' . $suffix) . '.pdf');

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
	 * Remove attachment nodes to get a smaller XML
	 * @param string $xmlData The XML data to process
	 * @return string Cleaned XML
	 */
	public static function removeAttachmentFromXml(string $xmlData): string
	{
		// FacturX uses CII format for its embedded XML.
		return CIIProtocol::removeAttachmentFromXml($xmlData);
	}
}
