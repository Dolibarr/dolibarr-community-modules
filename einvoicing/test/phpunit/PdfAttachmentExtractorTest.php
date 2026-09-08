<?php
/* Copyright (C) 2026		Pierre Grasswill			<da.grumpf@gmail.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 * or see https://www.gnu.org/
 */

/**
 *      \file       test/phpunit/PdfAttachmentExtractorTest.php
 *      \ingroup    test
 *      \brief      PHPUnit test for PdfAttachmentExtractor, which reads the invoice attached to a
 *                  received Factur-X PDF with the PDF parser of the core. The point of the class is
 *                  that the reception path no longer loads composer at all, so that is asserted here
 *                  too: this file must never make a horstoeko class exist.
 *      \remarks    To run this script as CLI: phpunit filename.php
 */

global $conf, $user, $langs, $db;

$dolibarrHtdocs = getenv('DOLIBARR_HTDOCS');
if (!$dolibarrHtdocs) {
	$dolibarrHtdocs = dirname(__FILE__) . '/../../htdocs';
}
if (!file_exists($dolibarrHtdocs . '/master.inc.php')) {
	throw new \RuntimeException('Could not locate master.inc.php under "' . $dolibarrHtdocs . '/". Set the environment variable (export DOLIBARR_HTDOCS=...) to the htdocs directory of the Dolibarr instance to test against.');
}

require_once $dolibarrHtdocs . '/master.inc.php';
dol_include_once('einvoicing/class/utils/PdfAttachmentExtractor.class.php');
require_once __DIR__ . '/CommonClassTestCompat.inc.php';

/**
 * Class for PHPUnit tests
 *
 * @backupGlobals disabled
 * @backupStaticAttributes enabled
 * @remarks	backupGlobals must be disabled to have db,conf,user and lang not erased.
 */
class PdfAttachmentExtractorTest extends CommonClassTest
{
	/**
	 * A Factur-X container shipped by this repository, which a received document looks like.
	 *
	 * @return	string				Full path of the sample
	 */
	private function facturxSample()
	{
		return dirname(__FILE__) . '/../samples/INVTEST-dol_ok.facturx.pdf';
	}

	/**
	 * A PDF that carries no attachment at all: the carrier the mergers build on.
	 *
	 * @return	string				Full path of the sample
	 */
	private function plainPdfSample()
	{
		return dirname(__FILE__) . '/../../doc/00_ZugferdDocumentPdfBuilder_PrintLayout.pdf';
	}

	/**
	 * The invoice attached to a Factur-X container is read back, and it is the CII the module parses.
	 *
	 * @return	void
	 */
	public function testTheAttachedInvoiceIsRead()
	{
		$sample = $this->facturxSample();
		$this->assertFileExists($sample, 'The Factur-X sample of the repository is missing');

		$xml = PdfAttachmentExtractor::getInvoiceXmlFromFile($sample);

		$this->assertNotEmpty($xml, 'Nothing was extracted from the Factur-X sample');
		$dom = new DOMDocument();
		$this->assertTrue($dom->loadXML($xml), 'What was extracted is not parsable XML');
		$this->assertSame('CrossIndustryInvoice', $dom->documentElement->localName, 'What was extracted is not a CII invoice');
	}

	/**
	 * Reading the container from its content and from its path give the very same bytes.
	 *
	 * @return	void
	 */
	public function testReadingFromContentAndFromFileAgree()
	{
		$sample = $this->facturxSample();

		$fromFile = PdfAttachmentExtractor::getInvoiceXmlFromFile($sample);
		$fromContent = PdfAttachmentExtractor::getInvoiceXmlFromContent((string) file_get_contents($sample));

		$this->assertSame($fromFile, $fromContent, 'The same container read two ways gave two different invoices');
	}

	/**
	 * The name under which the invoice is attached is decoded, whatever form the container used.
	 *
	 * @return	void
	 */
	public function testTheAttachmentIsNamedAsFacturxRequires()
	{
		$parser = new PdfAttachmentExtractor((string) file_get_contents($this->facturxSample()), 'test');
		$attachments = $parser->getEmbeddedFiles();
		$parser->cleanUp();

		$this->assertArrayHasKey('factur-x.xml', $attachments, 'The attachment was not found under the name Factur-X gives it, but as: ' . implode(', ', array_keys($attachments)));
	}

	/**
	 * A PDF carrying no invoice is refused, rather than answered with something empty that the rest
	 * of the reception would take for a document.
	 *
	 * @return	void
	 */
	public function testAPdfWithoutAnyAttachmentIsRefused()
	{
		$sample = $this->plainPdfSample();
		$this->assertFileExists($sample, 'The plain PDF sample of the repository is missing');

		$caught = null;
		try {
			PdfAttachmentExtractor::getInvoiceXmlFromFile($sample);
		} catch (Exception $e) {
			$caught = $e;
		}

		$this->assertNotNull($caught, 'A PDF with no attachment was read as if it carried an invoice');
	}

	/**
	 * A file that is not a PDF is refused too, and by an exception the reception already catches.
	 *
	 * @return	void
	 */
	public function testSomethingThatIsNotAPdfIsRefused()
	{
		$caught = null;
		try {
			PdfAttachmentExtractor::getInvoiceXmlFromContent('<?xml version="1.0"?><a/>');
		} catch (Exception $e) {
			$caught = $e;
		}

		$this->assertNotNull($caught, 'A file that is not a PDF was read as if it carried an invoice');
	}

	/**
	 * What all of the above is for: reading a received Factur-X pulls no composer library, so it
	 * cannot be stopped by the PHP those libraries ask for.
	 *
	 * @return	void
	 * @depends testTheAttachedInvoiceIsRead
	 */
	public function testReadingAContainerLoadsNoComposerLibrary()
	{
		$this->assertFalse(
			class_exists('horstoeko\zugferd\ZugferdDocumentPdfReaderExt', false),
			'Reading a Factur-X container brought a composer class in, which is what puts a floor under the PHP version of the reception'
		);
	}
}
