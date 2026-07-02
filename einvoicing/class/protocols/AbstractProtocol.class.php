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
 * \file    einvoicing/class/protocols/AbstractProtocol.class.php
 * \ingroup einvoicing
 * \brief   Base class for all PDP provider integrations.
 */

abstract class AbstractProtocol
{
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
	 * @param  string|null 		$ReadableViewFile        			Readable view file (PDP Generated readable PDF).e only store it if available.
	 * @param  string 			$flowId                       		Flow identifier source of the invoice.
	 * @return array{res:int, message:string, action:string|null}   Returns array with 'res' (1 on success, 0 already exists, -1 on failure) with a 'message' and an optional 'action'.
	 */
	abstract public function createSupplierInvoiceFromSource($file, $ReadableViewFile = null, $flowId = '');

	/**
	 * Add lines to a supplier invoice from e-invoice parsed lines
	 * @param FactureFournisseur $supplierInvoice						The supplier invoice to add lines on
	 * @param array $parsedLines										The parsed lines data (previously extracted from e-invoice)
	 * @param string $flowId											The concerned flowId
	 * @param  array{free_lines:bool,target_fk_product:?int} $params	Params used in case of manual import
	 * @return array{res:int, message:string, actioncode:string|null, actionurl:string, action:string, actiondata:mixed}   Returns array with 'res' (1 on success, 0 already exists, -1 on failure) with a 'message' and additional data about the action.
	 */
	abstract public function createSupplierInvoiceLinesFromSource(&$supplierInvoice, $parsedLines, $flowId = '', $params = ['free_lines' => false, 'target_fk_product' => null]): array;

	/**
	 * Generate a sample invoice for testing or demonstration purposes (for Dolibarr version < 24.0)
	 *
	 * Each protocol should provide a representative sample
	 * illustrating its structure and data format.
	 *
	 * @param	EInvoicing				$einvoicing				EInvoicing
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
	 * @param	EInvoicing				$einvoicing				EInvoicing
	 * @param   Societe|null			$thirdpartySeller		Optional third party object to use for generating the sample invoice. If null, a dummy third party will be created.
	 * @param   Societe|null			$thirdpartyBuyer		Optional third party object to use for generating the sample invoice. If null, a dummy third party will be created.
	 * @param   array<string,mixed>		$options				More options
	 * @return 	-1|array<string,string>							Path or content of the generated sample invoice.
	 */
	abstract public function generateSampleInvoice($einvoicing, $thirdpartySeller = null, $thirdpartyBuyer = null, $options = array());

	/**
	 * Parse the invoice header from input content.
	 *
	 * @param  string $rawContent Raw XML content
	 * @return array<string,mixed>
	 */
	abstract public function parseInvoiceXML(string $rawContent);

	/**
	 * Parse all invoice line items from input content.
	 *
	 * @param  string $rawContent Raw content
	 * @return array<int,array<string,mixed>>
	 */
	abstract public function parseInvoiceLines(string $rawContent);

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
			dol_syslog(get_class($this) . '::checkFileSizeLimit ' . basename($filepath) . ' size ' . number_format($sizeMB, 2) . ' MB exceeds configured limit of ' . number_format($maxMB, 2) . ' MB', LOG_WARNING);
		}
	}
}
