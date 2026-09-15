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

dol_include_once('einvoicing/class/protocols/CIIProtocol.class.php');
dol_include_once('einvoicing/class/protocols/CommonProtocol.class.php');
dol_include_once('einvoicing/class/utils/EmbeddedXmlReader.class.php');
dol_include_once('einvoicing/class/utils/PdfAttachmentExtractor.class.php');
// Neither vendor/autoload.php nor the two mergers are required here. Both mergers descend from a
// composer class, and that autoloader refuses to run below the PHP its libraries need, so loading
// them at file scope would make a received Factur-X fatal on a PHP that reads it perfectly well.
// They belong to generateInvoice(), the only place that writes a container. The same reasoning has
// always applied to FacturxTcpdfMerger, which descends from TCPDF and needs the core PDF stack.


/**
 * FacturX Protocol Class
 *
 * This class handles the FacturX protocol implementation for generating and managing electronic
 * invoices according to the FacturX standard. This also throw an error if data is not correct.
 * Based on the FacturX plugin developed by CAP REL, adapted and integrated into the EInvoicing
 * module to provide electronic invoicing capabilities compliant with the French Factur-X standard.
 *
 * @author  Eric Seigne <eric.seigne@cap-rel.fr>
 * 			Modified by mdaoud
 * @see     https://inligit.fr/cap-rel/dolibarr/plugin-facturx plugin repository
 */
class FacturXProtocol extends CIIProtocol
{
	use CommonProtocol;

	/** @const string Invoice file extension (without the dot, example 'xml') */
	const INVOICE_FILE_EXTENSION = 'pdf';

	/** @const string Generated invoice file name */
	const GENERATED_INVOICE_XML_FILE_NAME = 'factur-x.xml';

	/** @const string The profile used to generate XML */
	const BUILD_XML_PROFILE = 'EXTENDED';

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

		$filename = dol_sanitizeFileName($invoice->ref);
		$filedir = getMultidirOutputCompat($invoice, '', 1);		// Example '/mydolibarr/documents/facture/FAYYMM-XXXX'

		// Resolve the source PDF into which the Factur-X XML will be embedded, by priority:
		//   1. $sourceFilePath from the generation hook (ODT/ODS: the MAIN_ODT_AS_PDF rendition shares the basename);
		//   2. the most recent <ref>*.pdf already present in the output dir (manual generation, ODT output
		//      like <ref>_Template.pdf for which last_main_doc is not maintained), excluding our own output;
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

			// That rebuild fires afterPDFCreation, which would generate the document a second time and clean
			// up the temporary XML this call still needs (issue #658), so the hook is told to stand back.
			// try/finally: a rebuild that throws must not leave the hook muted for the rest of the request.
			$resultpdf = -1;
			EInvoicing::setEInvoiceGenerationInProgress($invoice->id, true);
			try {
				$resultpdf = $invoice->generateDocument($modelname, $langs);
			} finally {
				EInvoicing::setEInvoiceGenerationInProgress($invoice->id, false);
			}
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

		// The mergers below take the XML as content, and treat the string as content when it is not the
		// path of an existing file. A missing XML therefore does not fail, it gets embedded: check it here
		// rather than hand over a PDF carrying its own file name. Nothing removes that file any more, but
		// the merge is the last place where the mistake is still catchable (issue #658).
		if (!file_exists($orig_pdf) || empty($xmlfile) || !file_exists($xmlfile)) {
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

		// Below Dolibarr 24 the core declares its own "class FPDF extends TCPDF {}" in
		// htdocs/includes/tcpdi/tcpdi.php, but only once its PDF stack is loaded. Load that stack now, so
		// the branch below does not depend on whether this request rendered a PDF already: without it the
		// module autoloads the real FPDF instead, and the next core PDF of the request dies on "Cannot
		// redeclare class FPDF". The instance is discarded, only what it loads and the K_* constants matter.
		if (!class_exists('FPDF', false)) {
			require_once DOL_DOCUMENT_ROOT . '/core/lib/pdf.lib.php';
			pdf_getInstance();
		}

		// From here on the container is written, which is what needs horstoeko/zugferd
		require_once __DIR__ . '/../../vendor/autoload.php';

		try {
			if (class_exists('FPDF', false) && is_subclass_of('FPDF', 'TCPDF')) {
				// Below Dolibarr 24, htdocs/includes/tcpdi/tcpdi.php declares "class FPDF extends TCPDF {}".
				// The horstoeko/zugferd writer then inherits from TCPDF instead of the real FPDF and dies on
				// ZugferdPdfWriter::_getpagesize(). Merge with TCPDF itself, which needs no FPDF at all and
				// supports PDF/A-3 natively. Reaching this branch means the core has loaded TCPDF, which is
				// what the merger descends from.
				dol_include_once('einvoicing/class/utils/FacturxTcpdfMerger.class.php');
				$merger = new FacturxTcpdfMerger($xmlfile, $orig_pdf);
			} else {
				// CtcFrPdfMerger behaves exactly like ZugferdDocumentPdfMerger, except that it can still
				// supply the attachment and XMP parameters when the guideline URN is one the library does
				// not know — which is the case of EXTENDED-CTC-FR.
				dol_include_once('einvoicing/class/utils/CtcFrPdfMerger.class.php');
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
	 * A reader and a platform validator look for the document level /AF array and the PDF/A-3 output
	 * intent, and refuse a file that has the embedded stream but neither of those - after it was sent
	 * (issue #554). The check is on the produced file, so it also covers a merger that degrades silently.
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
	 * Read a received Factur-X document into the header and lines the import works on.
	 * A Factur-X file carries its CII XML as a PDF/A-3 attachment, so the extraction is all this
	 * protocol has of its own: once the XML is out, the import itself is the one of CIIProtocol.
	 *
	 * @param  string	$file      Raw Factur-X PDF content
	 * @param  string	$tempFile  That same content, already written to the per-call working file
	 * @return array{header:array<string,mixed>,lines:array<int,array<string,mixed>>,xml:string}	Parsed header, parsed lines, and the embedded CII XML
	 */
	protected function parseReceivedDocument($file, $tempFile)
	{
		// Only the embedded CII is extracted here: the PDF/A-3 attachment is read as it stands, and the
		// profile the document declares is never looked at.
		$embeddedXml = PdfAttachmentExtractor::getInvoiceXmlFromFile($tempFile);

		if (getDolGlobalInt('EINVOICING_USE_EXTERNAL_FACTURX_READER')) {
			return $this->parseReceivedDocumentWithExternalReader($tempFile, $embeddedXml);
		}

		// The default is to use the same parser as the CII one.
		return array(
			'header' => $this->parseInvoiceHeader($embeddedXml),
			'lines' => $this->parseInvoiceLines($embeddedXml),
			'xml' => $embeddedXml,
		);
	}

	/**
	 * Read a received Factur-X document with horstoeko/zugferd instead of the parser of the module.
	 * Opt-in through EINVOICING_USE_EXTERNAL_FACTURX_READER, and meant for development and
	 * cross-checking only: it reads fewer fields than the native parser.
	 *
	 * @param  string	$tempFile     Working file holding the received PDF
	 * @param  string	$embeddedXml  CII XML already extracted from that PDF
	 * @return array{header:array<string,mixed>,lines:array<int,array<string,mixed>>,xml:string}	Parsed header, parsed lines, and the embedded CII XML
	 */
	protected function parseReceivedDocumentWithExternalReader($tempFile, $embeddedXml)
	{
		$parsedHeader = [];
		$parsedLines = [];

		// Use a duplicate parser (for test or dev tests)
		// horstoeko/zugferd resolves the profile by matching the guideline URN of the document against
		// its own table, which has no entry for EXTENDED-CTC-FR - the French profile this very module
		// emits. Instantiating that reader is therefore only done on the path that actually uses it,
		// instead of on every received Factur-X (issue #742).
		require_once __DIR__ . '/../../vendor/autoload.php';
		$document = ZugferdDocumentPdfReader::readAndGuessFromFile($tempFile);

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
				// The two-argument form is deprecated in zugferd and never read the document for the
				// second one: it set it to 0.0 and called the simple form for the first. Nothing here
				// reads that second value, so call the form that is kept.
				$document->getDocumentPositionLineSummationSimple($lineTotalAmount);
				$document->getDocumentPositionQuantity($billedquantity, $billedquantityunitcode, $chargeFreeQuantity, $chargeFreeQuantityunitcode, $packageQuantity, $packageQuantityunitcode);

				// Get AdditionalReferencedDocument at line level
				$reader = new EmbeddedXmlReader($embeddedXml);
				$additionalRefDocs[(string) $lineid] = $reader->getLineAdditionalReferencedDocuments((string) $lineid);

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
					'totalAllowanceChargeAmount' => 0.0,
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


				dol_syslog(get_class($this) . '::parseReceivedDocumentWithExternalReader parsedLines: ' . json_encode($parsedLines), LOG_DEBUG);
			} while ($document->nextDocumentPosition());
		}

		dol_syslog(get_class($this) . '::parseReceivedDocumentWithExternalReader parsedHeader: ' . json_encode($parsedHeader), LOG_DEBUG, 0, '_einvoicing');

		return array('header' => $parsedHeader, 'lines' => $parsedLines, 'xml' => $embeddedXml);
	}


	/**
	 * Extract XML from an input file content and return it
	 *
	 * @param  string $fileContent Raw file content
	 * @return string The extracted XML content
	 */
	public function extractXmlFromFileContent(string $fileContent)
	{
		$extractedXml = PdfAttachmentExtractor::getInvoiceXmlFromContent($fileContent);
		return $extractedXml;
	}
}
