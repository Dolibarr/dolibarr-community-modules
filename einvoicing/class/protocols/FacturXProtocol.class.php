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

//use custom\facturx\Fidry\FileSystem\FS;
use horstoeko\zugferd\ZugferdDocumentPdfReader;
use horstoeko\zugferd\ZugferdDocumentPdfReaderExt;

require __DIR__ . "/../../vendor/autoload.php";

dol_include_once('einvoicing/class/protocols/CIIProtocol.class.php');
dol_include_once('einvoicing/class/protocols/CommonProtocol.class.php');
dol_include_once('einvoicing/class/utils/XmlPatcher.class.php');
dol_include_once('einvoicing/class/utils/CtcFrPdfMerger.class.php');
// FacturxTcpdfMerger is NOT included here: it descends from TCPDF, which the core only loads when a
// PDF is actually rendered. Requiring it at load time would make every page that instantiates this
// protocol die on "Class TCPDF not found". It is included where it is used, which is precisely the
// branch where the core has already loaded TCPDF.


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
class FacturXProtocol extends CIIProtocol
{
	use CommonProtocol;

	/** @const string Invoice file extension (without the dot, example 'xml') */
	protected const INVOICE_FILE_EXTENSION = 'pdf';

	/** @const string Generated invoice file name */
	protected const GENERATED_INVOICE_XML_FILE_NAME = 'factur-x.xml';

	/** @const string The profile used to generate XML */
	protected const BUILD_XML_PROFILE = 'EXTENDED';

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
				$orig_pdf = preg_replace('/\.(odt|ods)$/i', '.' . self::INVOICE_FILE_EXTENSION, $sourceFilePath);
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
			$orig_pdf = $filedir . '/' . $filename . '.' . self::INVOICE_FILE_EXTENSION;				// legacy default
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
			$orig_pdf = $filedir . '/' . $filename . '.' . self::INVOICE_FILE_EXTENSION;				// generateDocument writes <ref>.pdf
		}

		// Make a copy of the original PDF file
		$pathfacturxpdf = $filedir . '/' . $filename . '_facturx.' . self::INVOICE_FILE_EXTENSION;	// The new name of the PDF including xml
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
		if (!$precheck) {
			dol_syslog(get_class($this) . "::executeHooks orig pdf file does not exists, can't create facturX");
			$this->error = 'Orig pdf file does not exists, can t create facturX';
			$this->errors[] = $this->error;
			return -1;
		}

		clearstatcache(true);


		// Embed the XML file $xmlfile into the file $pathfacturxpdf (that was copied from $orig_pdf) and overwrite it.
		// 2 mergers are provided, both producing a PDF/A-3 file carrying the XML as an associated file.
		// They differ only by the PDF engine they can use, which depends on what already holds the
		// global class FPDF in this PHP request - see FacturxTcpdfMerger for the whole story.

		// TODO A third method can be tried using the atgp/factur-x library.

		if (!file_exists($orig_pdf)) {
			throw new \Exception("XML and/or PDF does not exist");
		}

		// Restore metadata from original PDF.
		// The merger setters require non-null strings, so default to '' for Dolibarr versions that do
		// not ship pdfExtractMetadata() (v18 / v19); v22+ overwrites these with the actual values
		// parsed from the source PDF.
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

		try {
			if (class_exists('FPDF', false) && is_subclass_of('FPDF', 'TCPDF')) {
				// Below Dolibarr 24, htdocs/includes/tcpdi/tcpdi.php declares "class FPDF extends TCPDF {}"
				// as soon as any PDF is rendered in the request - the invoice PDF produced at validation,
				// right before this hook runs. The horstoeko/zugferd writer then inherits from TCPDF instead
				// of the real FPDF and dies on ZugferdPdfWriter::_getpagesize(). Merge with TCPDF itself,
				// which needs no FPDF at all and supports PDF/A-3 natively. Reaching this branch means
				// the core has loaded TCPDF, which is what the merger descends from.
				dol_include_once('einvoicing/class/utils/FacturxTcpdfMerger.class.php');
				$merger = new FacturxTcpdfMerger($xmlfile, $orig_pdf);
			} else {
				// CtcFrPdfMerger behaves exactly like ZugferdDocumentPdfMerger, except that it can still
				// supply the attachment and XMP parameters when the guideline URN is one the library does
				// not know — which is the case of EXTENDED-CTC-FR.
				$merger = new CtcFrPdfMerger($xmlfile, $orig_pdf);
			}

			$merger->setKeywordTemplate($keywords);
			$merger->setSubjectTemplate($subject);
			$merger->setAuthorTemplate($author);
			$merger->setAdditionalCreatorTool($creator);

			$merger->generateDocument();

			$merger->saveDocument($pathfacturxpdf);
		} catch (Throwable $e) {
			// A carrier PDF the merger cannot read (truncated, encrypted, not a PDF) used to let the
			// exception escape to whoever validated the invoice, and to leave behind the copy of the
			// carrier under the Factur-X name - a file that looks like the e-invoice and is not one.
			if (file_exists($pathfacturxpdf)) {
				dol_delete_file($pathfacturxpdf, 0, 1);
			}
			dol_syslog(get_class($this) . '::generateInvoice cannot embed the XML into ' . basename($orig_pdf) . ' : ' . $e->getMessage(), LOG_ERR, 0, '_einvoicing');
			$this->error = $langs->trans('ErrorEInvoiceCannotEmbedXmlIntoPdf', basename($orig_pdf), $e->getMessage());
			$this->errors[] = $this->error;
			return -1;
		}

		// Whichever merger ran, do not hand over a file that only looks like a Factur-X one.
		$this->checkFacturxStructure($pathfacturxpdf);


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
	 * Check that the produced PDF really is a Factur-X file, and not a PDF with an attachment.
	 *
	 * Nothing in the standard makes the difference visible to the eye: both carry the XML and both
	 * open normally. What a reader and a platform validator look for is the document level /AF array
	 * and the PDF/A-3 output intent, and a file that has the embedded stream but neither of those is
	 * refused - after being sent, which is the expensive moment to find out (issue #554).
	 *
	 * The check is on the produced file rather than on the code path that produced it, so it also
	 * covers a merger that silently degrades for another reason.
	 *
	 * @param	string	$pathfacturxpdf		Full path of the generated Factur-X PDF
	 * @return	void
	 */
	private function checkFacturxStructure($pathfacturxpdf)
	{
		global $langs;

		if (!file_exists($pathfacturxpdf)) {
			return;
		}

		$content = (string) file_get_contents($pathfacturxpdf);

		$missing = array();
		if (!preg_match('#/Type\s*/EmbeddedFile#', $content)) {
			$missing[] = 'embedded XML';
		}
		if (!preg_match('#/AF\s*\[#', $content)) {
			$missing[] = '/AF';
		}
		if (!preg_match('#/OutputIntent#', $content)) {
			$missing[] = 'PDF/A-3 output intent';
		}

		if (empty($missing)) {
			return;
		}

		$langs->load('einvoicing@einvoicing');
		$message = $langs->trans('EInvoiceFacturxStructureIncomplete', basename($pathfacturxpdf), implode(', ', $missing));

		dol_syslog(get_class($this) . '::checkFacturxStructure ' . basename($pathfacturxpdf) . ' is missing: ' . implode(', ', $missing), LOG_WARNING, 0, '_einvoicing');

		$this->warnings[] = $message;
	}

	/**
	 * Build the supplier invoice from a received Factur-X document written to a per-call working file.
	 * The temp-file lifecycle is owned by createSupplierInvoiceFromSource() (the public wrapper).
	 * The vendor synchronization runs in its own transaction, opened and closed here. The invoice
	 * import transaction is opened here too, right after, but closed by that same wrapper.
	 *
	 * @param  string			$file                 Raw Factur-X PDF content
	 * @param  string|null		$ReadableViewFile     Optional readable view (PDP-generated readable PDF)
	 * @param  string			$flowId               Source flow identifier
	 * @param  string			$tempFile             Unique working file for the received PDF
	 * @param  string			$tempFileReadableView Unique working file for the readable view
	 * @return array{res:int<-1,1>, message:string, action?:string|null}
	 */
	protected function doCreateSupplierInvoiceFromSource($file, $ReadableViewFile, $flowId, $tempFile, $tempFileReadableView)
	{
		global $conf, $db, $user;

		// Duplicate code with doCreateSupplierInvoiceFromSource in CIIProtocol.class.php
		// TODO Merge tis code with the one into CIIProtocol.class.php to avoid duplicate

		$einvoicing = new EInvoicing($db);
		$return_messages = array();

		if (file_put_contents($tempFile, $file) === false) {
			return ['res' => -1, 'message' => 'Failed to save EInvoice file to temporary location'];
		}

		if ($ReadableViewFile) {
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
		if (!getDolGlobalInt('EINVOICING_USE_EXTERNAL_FACTURX_READER')) { // The default is to use the same parser than the CII one.
			$parsedHeader = $this->parseInvoiceHeader($embeddedXml);
			$parsedLines  = $this->parseInvoiceLines($embeddedXml);
		} else {
			// Use a duplicate parser (for test or dev tests)
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
					$additionalRefDocs[(string) $lineid] = $patcher->getLineAdditionalReferencedDocuments((string) $lineid);

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
						'additionalRefDocs' => $additionalRefDocs[(string) $lineid] ?? null,
					);


					dol_syslog(get_class($this) . '::createSupplierInvoiceFromSource parsedLines: ' . json_encode($parsedLines), LOG_DEBUG);
				} while ($document->nextDocumentPosition());
			}
		}

		dol_syslog(get_class($this) . '::createSupplierInvoiceFromSource parsedHeader: ' . json_encode($parsedHeader), LOG_DEBUG);
		dol_syslog(get_class($this) . '::createSupplierInvoiceFromSource parsedHeader: ' . json_encode($parsedHeader), LOG_DEBUG, 0, '_einvoicing');

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
				'message' => 'Thirdparty sync or creation error: ' . implode("<br>\n", $return_messages),
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
		$supplierInvoiceId = SupplierInvoiceHelper::findIdByRef($parsedHeader['documentno'] ?? null, (int) $socId);
		if ($supplierInvoiceId < 0) {
			return ['res' => -1, 'message' => SupplierInvoiceHelper::refLookupErrorMessage($supplierInvoiceId, $parsedHeader['documentno'] ?? '', 'while checking whether it was already imported')];
		}
		if ($supplierInvoiceId > 0) {
			$einvoicing->cleanUpTemporaryFiles(); // Clean up temp files to remove retrieved Einvoice file since invoice already exists

			// FIXME supplierinvoice already found but may be that documents are not linked (this is done later but only after creating invoice,
			// may be we should also do it in this case to fix inconsistent data).

			return ['res' => $supplierInvoiceId, 'message' => 'Supplier Invoice with reference ' . $parsedHeader['documentno'] . ' already exists'];
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
					return ['res' => -1, 'message' => 'Document : ' . $refDoc . ' linked to document ' . $parsedHeader['documentno'] . ' not found in Dolibarr'];
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
				return $res;
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
			if ($ReadableViewFile && $tempFileReadableView && file_exists($tempFileReadableView)) {
				$res = $this->saveEInvoiceFileToSupplierInvoiceAttachment($supplierInvoice, $tempFileReadableView, getDolGlobalString('EINVOICING_PDP', 'PDP'));

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
	 * Extract XML from an input file content and return it
	 *
	 * @param  string $fileContent Raw file content
	 * @return string The extracted XML content
	 */
	public function extractXmlFromFileContent(string $fileContent)
	{
		$extractedXml = ZugferdDocumentPdfReaderExt::getInvoiceDocumentContentFromContent($fileContent);
		return $extractedXml;
	}
}
