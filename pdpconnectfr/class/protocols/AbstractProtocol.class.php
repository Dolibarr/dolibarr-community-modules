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
 * \file    pdpconnectfr/class/protocols/AbstractProtocol.class.php
 * \ingroup pdpconnectfr
 * \brief   Base class for all PDP provider integrations.
 */

abstract class AbstractProtocol
{
    /**
     * Invoice object
     * @var Facture
     */
    public $sourceinvoice;

    /** @var array Error messages */
    public $errors = [];

    /**
     * Generate the XML content for a given invoice.
     *
     * Each protocol must implement this method to convert
     * the invoice data into an XML structure compliant
     * with its own e-invoicing format.
     *
     * @param object $invoice Invoice object containing all necessary data.
     * @return string XML representation of the invoice.
     */
    abstract public function generateXML($invoice);

    /**
     * Create a supplier invoice in Dolibarr from Factur-X content.
     *
     * This function parses the provided Factur-X XML content
     * and generates a corresponding supplier invoice within Dolibarr.
     *
     * @param array $file                       Factur-X file.
     * @param string|null $ReadableViewFile     Readable view file. (PDP Generated readable PDF)
     * @param string $flowId                    Flow identifier source of the invoice.
     *
     * @return array{res:int, message:string}   Returns array with 'res' (1 on success, -1 on failure) and 'message' if error
     */
    abstract public function createSupplierInvoiceFromFacturX($file, $ReadableViewFile = null, $flowId = '');

    /**
     * Generate a complete Factur-X invoice file by embedding the XML
     * into a PDF.
     *
     * This function combines the invoice data with its corresponding XML
     * to produce a final hybrid document ready for exchange or archiving.
     *
     * @param object $invoice_id    Invoice ID to be processed.
     * @return string               -1 if ko, path if ok.
     */
    public function generateInvoice($invoice_id)
    {
        // Default implementation: not required for all protocols
        return null;
    }

    /**
     * Generate a sample invoice for testing or demonstration purposes.
     *
     * Each protocol should provide a representative sample
     * illustrating its structure and data format.
     *
     * @return mixed Content of the generated sample invoice.
     */
    abstract public function generateSampleInvoice();
}