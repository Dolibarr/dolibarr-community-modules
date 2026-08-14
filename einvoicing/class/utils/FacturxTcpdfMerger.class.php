<?php
/* Copyright (C) 2026		Pierre Grasswill			<da.grumpf@gmail.com>
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
 * \file    einvoicing/class/utils/FacturxTcpdfMerger.class.php
 * \ingroup einvoicing
 * \brief   Build a PDF/A-3 Factur-X file without the FPDF class the core reserves below v24
 */

use horstoeko\zugferd\ZugferdProfileResolver;
use horstoeko\zugferd\ZugferdProfiles;
use setasign\Fpdi\Tcpdf\Fpdi as TcpdfFpdi;

require_once __DIR__ . '/../../vendor/autoload.php';

/**
 * Factur-X merger built on TCPDF instead of FPDF.
 *
 * Why a second merger. CtcFrPdfMerger, like the whole horstoeko/zugferd writer, descends from
 * setasign/fpdi, which descends from the global class FPDF. Below Dolibarr 24, the core file
 * htdocs/includes/tcpdi/tcpdi.php declares its own "class FPDF extends TCPDF {}" shim, without a
 * class_exists() guard. The first declaration in the request wins and the other one can never be
 * loaded: a request that has already rendered a PDF (the invoice PDF produced at validation, right
 * before our hook runs) holds the core shim, and the zugferd writer then inherits from TCPDF, whose
 * internals it does not have (Call to undefined method ZugferdPdfWriter::_getpagesize()).
 *
 * The two classes cannot coexist in one PHP request, whatever the order, so the way out is not to
 * need FPDF at all. setasign/fpdi also ships a TCPDF flavour of its page importer, which is
 * namespaced and therefore immune to the shim, and TCPDF has native PDF/A-3 support. This class puts
 * the two together and produces the same structure as the v24 path: PDF/A-3 output intent, the XML
 * as an embedded file, the document level /AF array and the Factur-X XMP extension schema.
 *
 * What used to happen instead was a plain TCPDF FileAttachment annotation: the XML was carried, but
 * with no /AF and no PDF/A-3 output intent, which is not a Factur-X file - only a PDF with something
 * attached to it (issue #554).
 *
 * The public surface mirrors CtcFrPdfMerger so both branches of FacturXProtocol read the same.
 */
class FacturxTcpdfMerger extends TcpdfFpdi
{
	/**
	 * @var string Full path to the CII/Factur-X XML to embed, or the XML itself
	 */
	private $xmlDataOrFilename;

	/**
	 * @var string Full path of the file TCPDF reads the attachment from
	 */
	private $xmlFilename = '';

	/**
	 * @var string Path of the file written when the XML was handed over as a string, to delete after use
	 */
	private $temporaryXmlFile = '';

	/**
	 * @var string Full path to the source PDF whose pages are carried over
	 */
	private $pdfFilename;

	/**
	 * @var string Name the embedded file is given inside the PDF ("factur-x.xml")
	 */
	private $attachmentName = 'factur-x.xml';

	/**
	 * @var string fx:ConformanceLevel written into the XMP block
	 */
	private $xmpConformanceLevel = 'EXTENDED';

	/**
	 * @var string fx:Version written into the XMP block
	 */
	private $xmpVersion = '1.0';

	/**
	 * @var string Relationship of the attachment to the document (/AFRelationship)
	 */
	private $relationship = 'Data';

	/**
	 * @var string The rdf:li declaring the Factur-X schema, to graft into the extension schema bag TCPDF writes
	 */
	private $facturxSchemaEntry = '';

	/**
	 * @var string Keywords copied from the source PDF
	 */
	private $keywordTemplate = '';

	/**
	 * @var string Subject copied from the source PDF
	 */
	private $subjectTemplate = '';

	/**
	 * @var string Author copied from the source PDF
	 */
	private $authorTemplate = '';

	/**
	 * @var string Creator tool copied from the source PDF
	 */
	private $additionalCreatorTool = '';

	/**
	 * Constructor.
	 *
	 * @param string $xmlDataOrFilename Full path to the XML to embed, or the XML itself
	 * @param string $pdfFilename       Full path to the source PDF
	 */
	public function __construct($xmlDataOrFilename, $pdfFilename)
	{
		// The seventh argument is the PDF/A version: 3 is what lets TCPDF emit the sRGB output intent
		// and the pdfaid:part 3 XMP block, and what lifts its own ban on embedded files (PDF/A-1 and
		// PDF/A-2 forbid them, PDF/A-3 is the level Factur-X is built on).
		parent::__construct('P', 'mm', 'A4', true, 'UTF-8', false, 3);

		$this->xmlDataOrFilename = $xmlDataOrFilename;
		$this->pdfFilename = $pdfFilename;

		$this->setPrintHeader(false);
		$this->setPrintFooter(false);
		$this->SetAutoPageBreak(false, 0);
		$this->setPDFVersion('1.7');		// PDF/A-3 is ISO 32000-1, as the v24 path also declares

		// TCPDF::setCompression() refuses to compress anything in PDF/A mode, which would make our
		// output several times heavier than the file the v24 path produces for the same invoice.
		// PDF/A only bans LZW and encryption, not Flate, so compression is turned back on here - see
		// _out() for the one TCPDF quirk that comes with it.
		if (function_exists('gzcompress')) {
			$this->compress = true;
		}
	}

	/**
	 * Set the keywords to write into the document information dictionary.
	 *
	 * @param  string $keywordTemplate Keywords
	 * @return void
	 */
	public function setKeywordTemplate($keywordTemplate)
	{
		$this->keywordTemplate = (string) $keywordTemplate;
	}

	/**
	 * Set the subject to write into the document information dictionary.
	 *
	 * @param  string $subjectTemplate Subject
	 * @return void
	 */
	public function setSubjectTemplate($subjectTemplate)
	{
		$this->subjectTemplate = (string) $subjectTemplate;
	}

	/**
	 * Set the author to write into the document information dictionary.
	 *
	 * @param  string $authorTemplate Author
	 * @return void
	 */
	public function setAuthorTemplate($authorTemplate)
	{
		$this->authorTemplate = (string) $authorTemplate;
	}

	/**
	 * Set the creator tool to write into the document information dictionary.
	 *
	 * @param  string $additionalCreatorTool Creator tool
	 * @return void
	 */
	public function setAdditionalCreatorTool($additionalCreatorTool)
	{
		$this->additionalCreatorTool = (string) $additionalCreatorTool;
	}

	/**
	 * Carry the pages of the source PDF over, attach the XML and prepare the Factur-X metadata.
	 *
	 * @return void
	 * @throws Exception When the source PDF cannot be read or carries no page
	 */
	public function generateDocument()
	{
		$this->resolveXmlFile();
		$this->resolveProfileParameters();

		$pageCount = $this->setSourceFile($this->pdfFilename);
		if ($pageCount <= 0) {
			throw new Exception('The source PDF carries no page: ' . $this->pdfFilename);
		}

		for ($pageNumber = 1; $pageNumber <= $pageCount; $pageNumber++) {
			$templateId = $this->importPage($pageNumber, '/MediaBox');
			$size = $this->getTemplateSize($templateId);
			// The page is added at the size of the page it carries: adding it at the default A4 would
			// silently rescale an invoice printed on another format.
			$this->AddPage($size['orientation'], array($size['width'], $size['height']));
			$this->useTemplate($templateId);
		}

		$this->registerAttachment();
		$this->applyMetadata();
	}

	/**
	 * Write the merged document.
	 *
	 * @param  string $toFilename Full path to write to
	 * @return void
	 */
	public function saveDocument($toFilename)
	{
		$this->Output($toFilename, 'F');

		if ($this->temporaryXmlFile !== '' && file_exists($this->temporaryXmlFile)) {
			unlink($this->temporaryXmlFile);
			$this->temporaryXmlFile = '';
		}
	}

	/**
	 * Make sure the XML to embed is available as a file.
	 *
	 * TCPDF reads an attachment from a path, so a caller that holds the document in memory - the
	 * sample invoice generator does - gets it written next to the other temporary files of the
	 * module, and the file is removed once the merged PDF is written.
	 *
	 * @return void
	 * @throws Exception When the XML cannot be written
	 */
	private function resolveXmlFile()
	{
		if ($this->xmlFilename !== '') {
			return;
		}

		if (@is_file($this->xmlDataOrFilename)) {
			$this->xmlFilename = $this->xmlDataOrFilename;
			return;
		}

		global $conf;

		$directory = !empty($conf->einvoicing->dir_temp) ? $conf->einvoicing->dir_temp : sys_get_temp_dir();
		dol_mkdir($directory);

		$temporary = tempnam($directory, 'facturx');
		if ($temporary === false || file_put_contents($temporary, $this->xmlDataOrFilename) === false) {
			throw new Exception('Cannot write the XML to embed into ' . $directory);
		}

		$this->temporaryXmlFile = $temporary;
		$this->xmlFilename = $temporary;
	}

	/**
	 * Resolve the three Factur-X parameters that depend on the profile of the XML.
	 *
	 * Same reasoning as CtcFrPdfMerger: horstoeko/zugferd resolves them by matching the guideline URN
	 * of the document against its own table, which has no entry for EXTENDED-CTC-FR. That profile is a
	 * conformant extension of EXTENDED and the Factur-X ConformanceLevel has no CTC-FR value of its
	 * own, so the EXTENDED parameters are the ones to fall back on.
	 *
	 * @return void
	 */
	private function resolveProfileParameters()
	{
		$definition = array();

		try {
			$xmlContent = (string) file_get_contents($this->xmlFilename);
			$definition = ZugferdProfileResolver::resolveProfileDef($xmlContent);
		} catch (Throwable $e) {
			dol_syslog(__METHOD__ . ' unknown guideline, falling back on the EXTENDED parameters: ' . $e->getMessage(), LOG_INFO, 0, '_einvoicing');
		}

		if (empty($definition)) {
			$definition = ZugferdProfiles::PROFILEDEF[ZugferdProfiles::PROFILE_EXTENDED] ?? array();
		}

		$this->attachmentName = (string) ($definition['attachmentfilename'] ?? 'factur-x.xml');
		$this->xmpConformanceLevel = strtoupper((string) ($definition['xmpname'] ?? 'EXTENDED'));
		$this->xmpVersion = strtoupper((string) ($definition['xmpversion'] ?? '1.0'));
	}

	/**
	 * Register the XML as an embedded file of the document.
	 *
	 * TCPDF only exposes attachments through Annotation(), which also drops a visible PushPin on the
	 * page - an annotation with no appearance stream, which PDF/A forbids. The bookkeeping it does is
	 * two lines, so the file is registered directly instead and the page is left untouched.
	 *
	 * @return void
	 */
	private function registerAttachment()
	{
		if (isset($this->embeddedfiles[$this->attachmentName])) {
			return;
		}

		$this->embeddedfiles[$this->attachmentName] = array(
			'f' => ++$this->n,
			'n' => ++$this->n,
			'file' => $this->xmlFilename
		);
	}

	/**
	 * Set the document information dictionary and the Factur-X part of the XMP block.
	 *
	 * TCPDF writes the dc, pdf, xmp and pdfaid descriptions itself, so only the Factur-X properties are
	 * added here. What declares them - the PDF/A extension schema, which is what makes them legal in a
	 * PDF/A file - cannot be a description of its own: see _out(). Both are read from the template
	 * shipped by horstoeko/zugferd so the two paths cannot drift apart.
	 *
	 * @return void
	 */
	private function applyMetadata()
	{
		if ($this->authorTemplate !== '') {
			$this->SetAuthor($this->authorTemplate);
		}
		if ($this->keywordTemplate !== '') {
			$this->SetKeywords($this->keywordTemplate);
		}
		if ($this->subjectTemplate !== '') {
			$this->SetSubject($this->subjectTemplate);
		}
		if ($this->additionalCreatorTool !== '') {
			$this->SetCreator($this->additionalCreatorTool);
		}

		$this->setExtraXMPRDF($this->buildFacturxXmpRdf());
	}

	/**
	 * Build the rdf:Description that makes a PDF/A-3 file a Factur-X file, and set aside its declaration.
	 *
	 * @return string	XMP fragment to insert inside rdf:RDF, empty if the template cannot be read
	 */
	private function buildFacturxXmpRdf()
	{
		$templateFile = dirname(__DIR__, 2) . '/vendor/horstoeko/zugferd/src/assets/facturx_extension_schema.xmp';
		if (!is_readable($templateFile)) {
			dol_syslog(__METHOD__ . ' the Factur-X XMP template is missing: ' . $templateFile, LOG_ERR, 0, '_einvoicing');
			return '';
		}

		$previous = libxml_use_internal_errors(true);
		$xmp = simplexml_load_file($templateFile);
		libxml_clear_errors();
		libxml_use_internal_errors($previous);

		if ($xmp === false) {
			dol_syslog(__METHOD__ . ' the Factur-X XMP template cannot be parsed: ' . $templateFile, LOG_ERR, 0, '_einvoicing');
			return '';
		}

		$descriptions = $xmp->xpath('rdf:Description');
		if (!is_array($descriptions) || count($descriptions) < 2) {
			dol_syslog(__METHOD__ . ' unexpected Factur-X XMP template layout', LOG_ERR, 0, '_einvoicing');
			return '';
		}

		// [0] carries the Factur-X properties, [1] the PDF/A extension schema that declares them.
		// The rest of the template (dc, pdf, xmp, pdfaid) is what TCPDF already writes on its own.
		$facturxProperties = $descriptions[0];
		$facturxProperties->children('fx', true)->{'ConformanceLevel'} = $this->xmpConformanceLevel;
		$facturxProperties->children('fx', true)->{'Version'} = $this->xmpVersion;
		$facturxProperties->children('fx', true)->{'DocumentFileName'} = $this->attachmentName;

		// Only the schema entry of [1] is kept: _out() grafts it into the bag TCPDF writes, because a
		// second description carrying pdfaExtension:schemas would repeat a property of the same subject.
		$schemaEntries = $descriptions[1]->children('pdfaExtension', true)->{'schemas'};
		if (isset($schemaEntries->children('rdf', true)->{'Bag'})) {
			foreach ($schemaEntries->children('rdf', true)->{'Bag'}->children('rdf', true)->{'li'} as $entry) {
				$this->facturxSchemaEntry .= $entry->asXML();
			}
		}

		return "\t\t" . $facturxProperties->asXML() . "\n";
	}

	/**
	 * Graft the Factur-X schema entry into the PDF/A extension schema bag TCPDF writes.
	 *
	 * The properties added by setExtraXMPRDF() are external to PDF/A, so PDF/A-3 (ISO 19005-3, 6.6.2.3)
	 * only accepts them when an extension schema declares them. The template shipped by
	 * horstoeko/zugferd carries that declaration as a rdf:Description of its own, which is right for
	 * the v24 path - horstoeko/zugferd writes the whole XMP block and writes none of its own - and
	 * wrong here: TCPDF has already written a pdfaExtension:schemas for the pdf, xmpMM and pdfaid
	 * schemas, on the same rdf:about="" subject. Two descriptions then repeat one property of one
	 * subject, which the XMP specification forbids, and the packet stops parsing: a reader that gives
	 * up there sees no pdfaid either, so the file is no longer even identified as PDF/A (veraPDF
	 * 1.30.2 on the output of this class: clauses 6.6.2.1 and 6.6.4). One bag, one entry more.
	 *
	 * @param  string $s Body of the metadata object about to be written
	 * @return string	 The same body, with the entry grafted and the stream length brought back in line
	 */
	private function graftFacturxSchemaEntry($s)
	{
		if ($this->facturxSchemaEntry === '') {
			return $s;
		}

		$schemas = strpos($s, '</pdfaExtension:schemas>');
		if ($schemas === false) {
			return $s;
		}

		$bag = strrpos(substr($s, 0, $schemas), '</rdf:Bag>');
		if ($bag === false) {
			return $s;
		}

		$s = substr($s, 0, $bag) . "\t\t\t\t\t" . $this->facturxSchemaEntry . "\n\t\t\t\t" . substr($s, $bag);

		// The header of the object was written with the length of the packet before the graft.
		$start = strpos($s, "stream\n");
		$end = strrpos($s, "\nendstream");
		if ($start === false || $end === false) {
			return $s;
		}
		$length = $end - ($start + strlen("stream\n"));

		return preg_replace('|/Length \d+|', '/Length ' . $length, $s, 1);
	}

	/**
	 * Complete the two objects TCPDF does not write the way Factur-X needs them.
	 *
	 * This is the one place where every PDF object passes before it reaches the buffer, and the object
	 * offsets of the cross-reference table are recorded when an object starts, not when it ends, so
	 * lengthening the body here shifts nothing.
	 *
	 * - the catalog gets the document level /AF array. TCPDF writes the /Names /EmbeddedFiles tree,
	 *   which makes the file downloadable, but a Factur-X reader looks up /AF: without it the XML is
	 *   an ordinary attachment and the document is not a Factur-X file.
	 * - the file specification gets /AFRelationship /Data. TCPDF hardcodes /Source, and the v24 path
	 *   emits /Data, so this keeps the two outputs comparable.
	 * - the metadata stream gets the Factur-X entry grafted into its extension schema bag, see
	 *   graftFacturxSchemaEntry().
	 * - the embedded file stream gets its /Filter back. In PDF/A-3 mode TCPDF overwrites the filter
	 *   entry with the /Subtype of the attachment instead of adding it, so a compressed attachment is
	 *   written deflated and declared as plain: readers then hand over binary noise instead of the
	 *   XML. TCPDF never meets the case because it also refuses to compress in PDF/A mode, which we
	 *   turn back on.
	 *
	 * @param  string $s Object body about to be written
	 * @return void
	 */
	public function _out($s)		// phpcs:ignore PEAR.NamingConventions.ValidFunctionName.PublicUnderscore
	{
		if (strpos($s, '/Type /Filespec') !== false) {
			$s = str_replace('/AFRelationship /Source', '/AFRelationship /' . $this->relationship, $s);
		}

		if ($this->compress && strpos($s, '/Type /EmbeddedFile') !== false && strpos($s, '/Filter') === false) {
			$s = str_replace('/Type /EmbeddedFile', '/Type /EmbeddedFile /Filter /FlateDecode', $s);
		}

		if (strpos($s, '/Type /Metadata') !== false) {
			$s = $this->graftFacturxSchemaEntry($s);
		}

		if (strpos($s, '/Type /Catalog') !== false && strpos($s, '/AF ') === false) {
			$fileSpecObject = $this->embeddedfiles[$this->attachmentName]['f'] ?? 0;
			$closing = strrpos($s, '>>');
			if ($fileSpecObject > 0 && $closing !== false) {
				$s = substr($s, 0, $closing) . ' /AF [' . ((int) $fileSpecObject) . ' 0 R] ' . substr($s, $closing);
			}
		}

		parent::_out($s);
	}
}
