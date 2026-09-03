<?php
/* Copyright (C) 2025       Laurent Destailleur         <eldy@users.sourceforge.net>
 * Copyright (C) 2025       Mohamed DAOUD               <mdaoud@dolicloud.com>
 * Copyright (C) 2026		MDW							<mdeweerd@users.noreply.github.com>
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


require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
/**
 * \file    einvoicing/class/protocols/AbstractProtocol.class.php
 * \ingroup einvoicing
 * \brief   Base class for all PDP provider integrations.
 */

abstract class AbstractProtocol
{
	/**
	 * @var DoliDB Db
	 */
	public $db;

	/**
	 * Invoice object
	 * @var Facture
	 */
	public $sourceinvoice;

	/** @var string Error message */
	public $error;

	/** @var array Error messages */
	public $errors = [];

	/** @var array Non-blocking warning messages */
	public $warnings = [];

	/** @const string Invoice file extension (without the dot, example 'xml') */
	const INVOICE_FILE_EXTENSION = ''; // Must be overridden by subclasses

	/** @const string Generated invoice XML file name*/
	const GENERATED_INVOICE_XML_FILE_NAME = ''; // Must be overridden by subclasses

	/** @const string The profile used to generate XML */
	const BUILD_XML_PROFILE = ''; // Must be overridden by subclasses

	/** @const string Fixed file name of the readable view of the last invoice that could not be processed */
	const INCOMING_DIAGNOSTIC_READABLE_FILE_NAME = 'einvoice_readable.pdf';

	/**
	 * @param DoliDB $db Db
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Generate the XML content for a given invoice.
	 *
	 * Each protocol must implement this method to convert
	 * the invoice data into an XML structure compliant
	 * with its own e-invoicing format.
	 *
	 * @param	CommonInvoice	$invoice 		Invoice object containing all necessary data.
	 * @param	?Translate		$outputlangs	Output language
	 * @return 	string 							XML representation of the invoice.
	 */
	abstract public function generateXML($invoice, $outputlangs = null);

	/**
	 * Create a supplier invoice in Dolibarr from Factur-X content.
	 *
	 * This function parses the provided Factur-X XML content
	 * and generates a corresponding supplier invoice within Dolibarr.
	 *
	 * @param  string 			$file                       		Source string file. We use this file to get data of supplier invoice.
	 * @param  string|null 		$readableViewFile        			Readable view file (PDP Generated readable PDF).e only store it if available.
	 * @param  string 			$flowId                       		Flow identifier source of the invoice.
	 * @return array{res:int, message:string, action:string|null}   Returns array with 'res' (1 on success, 0 already exists, -1 on failure) with a 'message' and an optional 'action'.
	 */
	abstract public function createSupplierInvoiceFromSource($file, $readableViewFile = null, $flowId = '');

	/**
	 * Generate a sample invoice for testing or demonstration purposes (for Dolibarr version < 24.0)
	 *
	 * Each protocol should provide a representative sample
	 * illustrating its structure and data format.
	 *
	 * @param	EInvoicing			$einvoicing			EInvoicing
	 * @param   Societe|null			$thirdpartySeller		Optional third party object to use for generating the sample invoice. If null, a dummy third party will be created.
	 * @param   Societe|null			$thirdpartyBuyer		Optional third party object to use for generating the sample invoice. If null, a dummy third party will be created.
	 * @param   array<string,mixed>		$options				More options
	 * @return 	array<string,string> 							Path or content of the generated sample invoice.
	 * @throws  Exception
	 */
	abstract public function generateSampleInvoiceOld($einvoicing, $thirdpartySeller = null, $thirdpartyBuyer = null, $options = array());

	/**
	 * Generate a sample invoice for testing or demonstration purposes (for Dolibarr version >= 24.0)
	 *
	 * Each protocol should provide a representative sample
	 * illustrating its structure and data format.
	 *
	 * @param	EInvoicing			$einvoicing			EInvoicing
	 * @param   Societe|null			$thirdpartySeller		Optional third party object to use for generating the sample invoice. If null, a dummy third party will be created.
	 * @param   Societe|null			$thirdpartyBuyer		Optional third party object to use for generating the sample invoice. If null, a dummy third party will be created.
	 * @param   array<string,mixed>		$options				More options
	 * @return 	-1|array<string,string>							Path or content of the generated sample invoice.
	 */
	abstract public function generateSampleInvoice($einvoicing, $thirdpartySeller = null, $thirdpartyBuyer = null, $options = array());

	/**
	 * Parse the invoice header from raw file content.
	 *
	 * @param  string $rawContent Raw file content
	 * @return array<string,float|string|array>
	 */
	abstract public function parseInvoiceHeader(string $rawContent);

	/**
	 * Parse all invoice line items from raw file content.
	 *
	 * @param  string $rawContent Raw file content
	 * @return array<int,array<string,mixed>>
	 */
	abstract public function parseInvoiceLines(string $rawContent);

	/**
	 * Extract XML from an input file content (PDF, XML...) and return it
	 * - if input is already an XML (CII, UBL), can directly return file content (no extraction needed : default behaviour)
	 * - if input is a FactorX PDF, need to extract XML part and return only XML (override required)
	 *
	 * @param  string $fileContent Raw file content
	 * @return string The extracted XML content
	 */
	public function extractXmlFromFileContent(string $fileContent)
	{
		return $fileContent;
	}

	/**
	 * Remove attachment nodes to get a smaller XML
	 * @param string $xmlData The XML data to process
	 * @return string Cleaned XML
	 */
	abstract public static function removeAttachmentFromXml(string $xmlData): string;

	/**
	 * Check if the generated e-invoice file exceeds the configured size limit.
	 * Adds a non-blocking warning to $this->warnings[] if the limit is exceeded.
	 *
	 * @param	string	$filepath	Path to the generated e-invoice file
	 * @return	void
	 */
	protected function checkFileSizeLimit($filepath)
	{
		global $langs;

		$maxMB = (float) getDolGlobalString('EINVOICING_MAX_FILE_SIZE_MB');
		if ($maxMB <= 0 || !file_exists($filepath)) {
			return;
		}

		$sizeMB = filesize($filepath) / (1024 * 1024);
		if ($sizeMB > $maxMB) {
			$langs->load('einvoicing@einvoicing');
			$this->warnings[] = $langs->trans('EInvoiceFileSizeExceedsLimit', number_format($sizeMB, 2), number_format($maxMB, 2));
			dol_syslog(get_class($this) . '::checkFileSizeLimit ' . dol_basename($filepath) . ' size ' . number_format($sizeMB, 2) . ' MB exceeds configured limit of ' . number_format($maxMB, 2) . ' MB', LOG_WARNING);
		}
	}

	/**
	 * Check the generated CII XML against a subset of the EN 16931 business rules (BR, BR-CO, BR-FR).
	 *
	 * Local safety net run at generation time, before the file is stored: it catches arithmetic
	 * inconsistencies (totals, VAT breakdown, prepaid/due) with an explicit message quoting the
	 * official rule id, without a network call and for any PDP provider. The official Schematron
	 * applied by the Approved Platform remains the reference.
	 *
	 * Controlled by EINVOICING_BR_CHECK, a three-value option: "nocheck" skips the check,
	 * "warning_only" (default) reports violations as non-blocking warnings, and "blocking" aborts
	 * the generation (the exception is caught by generateInvoice() which returns -1 with the messages).
	 *
	 * The check also covers what no rule of the standard can see: that the document claims the amount
	 * the invoice claims. A document can be perfectly conformant and still state another total than the
	 * invoice it stands for, and the platform accepts it without a word.
	 *
	 * @param	string			$xmlcontent		Generated CII XML content
	 * @param	?CommonInvoice	$invoice		Invoice the document was built from, to compare the amounts
	 * @return	void
	 * @throws	Exception				When violations are found and mode is "blocking"
	 */
	protected function checkBusinessRules($xmlcontent, $invoice = null)
	{
		global $langs;

		$brMode = getDolGlobalString('EINVOICING_BR_CHECK', 'warning_only');
		if ($brMode === 'nocheck') {
			return;
		}

		dol_include_once('einvoicing/class/utils/En16931Validator.class.php');
		$validator = new En16931Validator();
		$violations = $validator->validate($xmlcontent);
		$violations = array_merge($violations, $this->checkDocumentClaimsTheInvoiceAmount($xmlcontent, $invoice));
		if (empty($violations)) {
			return;
		}

		dol_syslog(get_class($this) . '::checkBusinessRules ' . count($violations) . ' violation(s): ' . implode(' | ', $violations), LOG_WARNING, 0, '_einvoicing');

		if ($brMode === 'blocking') {
			$this->errors = array_merge((array) $this->errors, $violations);
			$langs->load('einvoicing@einvoicing');
			throw new Exception($langs->trans('EInvoiceBusinessRulesViolations', (string) count($violations)) . ' - ' . implode(' | ', $violations));
		}

		$this->warnings = array_merge((array) $this->warnings, $violations);
	}

	/**
	 * Check that the generated document claims the amount the invoice claims.
	 *
	 * No rule of the standard can catch this: the rules relate the amounts of the document to each
	 * other, and a document whose totals are wrong by a cent is as consistent as a correct one. The
	 * platform therefore accepts it, and the discrepancy only surfaces later, on payment
	 * reconciliation or on the buyer's side.
	 *
	 * The known cause left is a currency accuracy for totals (MAIN_MAX_DECIMALS_TOT) other than 2: the
	 * invoice then carries a third decimal, and EN 16931 caps every amount at two, the rounding amount
	 * (BT-114) included (BR-DEC-09 to BR-DEC-23). No computation can make the two equal, so the
	 * operator is told instead of being left with a document that quietly claims something else
	 * (issue #506).
	 *
	 * @param	string			$xmlcontent		Generated CII XML content
	 * @param	?CommonInvoice	$invoice		Invoice the document was built from
	 * @return	string[]						Violation messages, empty when the two agree
	 */
	protected function checkDocumentClaimsTheInvoiceAmount($xmlcontent, $invoice)
	{
		if (!is_object($invoice) || !isset($invoice->total_ttc)) {
			return array();
		}
		if (!preg_match('#<ram:GrandTotalAmount[^>]*>([^<]*)</ram:GrandTotalAmount>#', $xmlcontent, $reg)) {
			return array();
		}

		$claimedByDocument = (float) trim($reg[1]);
		// A credit note is issued with positive amounts, where Dolibarr holds the invoice negative.
		$claimedByInvoice = ((int) $invoice->type === $invoice::TYPE_CREDIT_NOTE) ? abs((float) $invoice->total_ttc) : (float) $invoice->total_ttc;

		if (abs($claimedByInvoice - $claimedByDocument) < 0.0001) {
			return array();
		}

		$message = 'The document claims '.price2num($claimedByDocument, 'MT').' where the invoice claims '.price2num($claimedByInvoice, 'MT')
			.': the amount transmitted is not the amount invoiced';
		$decimals = getDolGlobalInt('MAIN_MAX_DECIMALS_TOT', 2);
		if ($decimals != 2) {
			$message .= '. The currency accuracy for totals (MAIN_MAX_DECIMALS_TOT) is set to '.$decimals
				.' decimals, where EN 16931 allows 2 on every amount, the rounding amount (BT-114) included';
		}

		return array($message);
	}

	/**
	 * Fixed file name of the "last invoice that could not be processed" diagnostic of this protocol,
	 * relative to the module temp directory. Derived from the protocol file extension, so a received
	 * CII document lands in einvoice.xml and a Factur-X one in einvoice.pdf. The pages that offer the
	 * diagnostic for download must ask for the name here rather than hardcode it, otherwise a protocol
	 * whose extension differs writes a slot nobody looks at (#588).
	 *
	 * @return	string		File name (no directory part)
	 */
	public static function getIncomingDiagnosticFileName()
	{
		return 'einvoice.' . static::INVOICE_FILE_EXTENSION;
	}

	/**
	 * Clean up the per-call working temp files of an inbound invoice, while preserving the
	 * "last invoice that could not be processed" diagnostic shown (and downloadable) in the
	 * document list view.
	 *
	 * Each inbound sync writes the received document to its own unique working file, so two
	 * concurrent syncs can no longer overwrite each other and parse the wrong invoice (#226).
	 * On failure, the working file is promoted to the fixed diagnostic slot (overwriting the
	 * previous one) so it stays downloadable; on success, the working files are simply removed.
	 *
	 * @param	string	$tempDir			Module temp directory
	 * @param	string	$workFile			Unique working file for the received document
	 * @param	string	$workReadable		Unique working file for the readable view (may not exist)
	 * @param	bool	$failed				True if processing failed (keep the diagnostic), false otherwise
	 * @return	void
	 */
	protected function cleanupIncomingTempFiles($tempDir, $workFile, $workReadable, $failed)
	{
		$diagName = static::getIncomingDiagnosticFileName();
		$diagReadableName = static::INCOMING_DIAGNOSTIC_READABLE_FILE_NAME;

		if ($failed) {
			// Keep the failed file(s) as the downloadable "last unprocessed invoice" diagnostic.
			if (file_exists($workFile)) {
				dol_delete_file($tempDir . '/' . $diagName);
				dol_copy($workFile, $tempDir . '/' . $diagName, '0', 1);
			}
			if (file_exists($workReadable)) {
				dol_delete_file($tempDir . '/' . $diagReadableName);
				dol_copy($workReadable, $tempDir . '/' . $diagReadableName, '0', 1);
			}
		}

		// Always drop the per-call working files.
		if (file_exists($workFile)) {
			dol_delete_file($workFile);
		}
		if (file_exists($workReadable)) {
			dol_delete_file($workReadable);
		}
	}
}
