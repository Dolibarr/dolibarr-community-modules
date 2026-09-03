<?php
/* Copyright (C) 2026		Pierre Grasswill			<da.grumpf@gmail.com>
 * Copyright (C) 2026		MDW							<mdeweerd@users.noreply.github.com>
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
 *      \file       test/phpunit/FacturxPdfaContainerTest.php
 *      \ingroup    test
 *      \brief      PHPUnit test for the PDF/A-3 container of the Factur-X files (issue #591).
 *                  A Factur-X file is a PDF/A-3 file carrying the XML, and everything else this
 *                  project validates looks at the XML only: the container had no guard, and two
 *                  defects that made the files invalid PDF/A shipped unnoticed. Both mergers of the
 *                  module are exercised here, on the carrier PDF and the XML fixtures the repository
 *                  already ships, and what they wrote into the container is read back.
 *
 *                  What is asserted is what a PDF/A validator would reject and what PHP can see
 *                  without one: a single parsable XMP packet identifying the file as PDF/A-3B, one
 *                  extension schema bag declaring Factur-X, a metadata stream whose /Length matches
 *                  what it holds, the XML embedded as an associated file with the right relationship,
 *                  and the PDF/A output intent. Run veraPDF over the same documents for the clauses
 *                  only a validator can settle - FACTURX_PDFA_OUTDIR is there for that.
 *
 *      \remarks    To run this script as CLI: phpunit filename.php
 *                  Needs no database. It uses the Dolibarr instance when there is one - as the other
 *                  tests of this directory do, through DOLIBARR_HTDOCS or the htdocs/custom symlink -
 *                  and stands on its own when there is none, which is how the CI runs it.
 */

// Both mergers need a logger and nothing else of the core, so the Dolibarr instance is used when it
// is reachable and stood in for when it is not: this test is also the one the CI runs, from the
// repository alone, where no instance exists.
$dolibarrHtdocs = getenv('DOLIBARR_HTDOCS');
if (!$dolibarrHtdocs) {
	$dolibarrHtdocs = dirname(__FILE__) . '/../../htdocs';
}
if (file_exists($dolibarrHtdocs . '/master.inc.php')) {
	global $conf, $user, $langs, $db;
	require_once $dolibarrHtdocs . '/master.inc.php';
}
if (!function_exists('dol_syslog')) {
	/**
	 * Stand-in for the logger of Dolibarr, absent when this test runs on the repository alone.
	 *
	 * @param  string $message	Message
	 * @param  int    $level	Log level
	 * @param  int    $indent	Indentation
	 * @param  string $suffix	Log file suffix
	 * @return void
	 */
	function dol_syslog($message, $level = 0, $indent = 0, $suffix = '')
	{
	}
}

require_once dirname(__FILE__) . '/../../class/utils/CtcFrPdfMerger.class.php';

/**
 * Class for PHPUnit tests
 *
 * @backupGlobals disabled
 * @backupStaticAttributes enabled
 * @remarks	backupGlobals must be disabled to have db,conf,user and lang not erased.
 */
class FacturxPdfaContainerTest extends PHPUnit\Framework\TestCase
{
	/** @var string Directory the generated Factur-X documents are written to */
	private static $outputDir = '';

	/** @var bool Whether the directory above is ours to remove afterwards */
	private static $outputDirIsTemporary = false;

	/**
	 * Resolve where the generated documents go: a directory asked for by the caller, so a validator
	 * can be run over them afterwards, or a temporary one that is removed at the end.
	 *
	 * @return void
	 */
	public static function setUpBeforeClass(): void
	{
		$asked = (string) getenv('FACTURX_PDFA_OUTDIR');
		if ($asked === '') {
			$asked = sys_get_temp_dir() . '/facturx-pdfa-' . getmypid();
			self::$outputDirIsTemporary = true;
		}
		if (!is_dir($asked) && !mkdir($asked, 0777, true)) {
			throw new \RuntimeException('Cannot create the output directory ' . $asked);
		}

		// TCPDF::Output() hands the path to a helper that only accepts a local file, and a relative
		// path looks like an URL to it ("no suitable wrapper could be found"). Resolve it once.
		self::$outputDir = (string) realpath($asked);
	}

	/**
	 * Remove the generated documents, unless the caller asked for them to be kept.
	 *
	 * @return void
	 */
	public static function tearDownAfterClass(): void
	{
		if (!self::$outputDirIsTemporary || self::$outputDir === '') {
			return;
		}
		foreach ((array) glob(self::$outputDir . '/*.pdf') as $file) {
			unlink($file);
		}
		rmdir(self::$outputDir);
	}

	/**
	 * The XML fixtures the documents are built from: the three kinds of invoice the module produces.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function fixtureProvider()
	{
		$fixtures = array();
		foreach ((array) glob(dirname(__FILE__) . '/fixtures/einvoicing_samples/*.xml') as $fixture) {
			$fixtures[basename($fixture, '.xml')] = array($fixture);
		}

		return $fixtures;
	}

	/**
	 * Build one Factur-X document with the given merger and return its path.
	 *
	 * The carrier is the PDF shipped by horstoeko/zugferd: it embeds its fonts, so it is a carrier a
	 * PDF/A file can be built on - which the PDF Dolibarr produces only is when its PDF format is set
	 * to PDF/A, and that half of the problem belongs to the setup of the instance, not here.
	 *
	 * @param	string	$class		Merger class name
	 * @param	string	$prefix		Prefix given to the generated file, to tell the two mergers apart
	 * @param	string	$fixture	Full path of the XML to embed
	 * @return	string				Full path of the generated document
	 */
	private function buildDocument($class, $prefix, $fixture)
	{
		$carrier = dirname(__FILE__) . '/../../doc/00_ZugferdDocumentPdfBuilder_PrintLayout.pdf';
		$target = self::$outputDir . '/' . $prefix . '_' . basename($fixture, '.xml') . '.pdf';

		/** @var CtcFrPdfMerger|FacturxTcpdfMerger $merger */
		$merger = new $class($fixture, $carrier);
		// The metadata carried over from the source PDF is not what a container check is about, and
		// the setters of both mergers refuse null.
		$merger->setKeywordTemplate('');
		$merger->setSubjectTemplate('');
		$merger->setAuthorTemplate('');
		$merger->setAdditionalCreatorTool('');

		$merger->generateDocument();
		$merger->saveDocument($target);

		$this->assertFileExists($target, $class . ' wrote no document for ' . basename($fixture));
		$this->assertGreaterThan(0, (int) filesize($target), $class . ' wrote an empty document for ' . basename($fixture));

		return $target;
	}

	/**
	 * Read back everything a Factur-X container is made of.
	 *
	 * The two defects this guards against both lived here: an XMP packet carrying two descriptions
	 * that repeat pdfaExtension:schemas on the same subject - which the XMP specification forbids,
	 * and which stops a reader before it ever sees the PDF/A identification, while staying perfectly
	 * well-formed XML - and, once the entry is grafted into the bag the writer produces instead, a
	 * metadata stream whose declared length no longer matches what it holds.
	 *
	 * @param	string	$file	Full path of the generated document
	 * @return	void
	 */
	private function assertIsFacturxContainer($file)
	{
		$name = basename($file);
		$content = (string) file_get_contents($file);

		$dom = new DOMDocument();
		$this->assertTrue($dom->loadXML($this->extractXmpPacket($content, $name)), $name . ' does not carry a parsable XMP packet');
		$xpath = new DOMXPath($dom);
		$xpath->registerNamespace('rdf', 'http://www.w3.org/1999/02/22-rdf-syntax-ns#');
		$xpath->registerNamespace('pdfaid', 'http://www.aiim.org/pdfa/ns/id/');
		$xpath->registerNamespace('pdfaExtension', 'http://www.aiim.org/pdfa/ns/extension/');
		$xpath->registerNamespace('pdfaSchema', 'http://www.aiim.org/pdfa/ns/schema#');
		$xpath->registerNamespace('fx', 'urn:factur-x:pdfa:CrossIndustryDocument:invoice:1p0#');

		// The document identifies itself as PDF/A-3B, once.
		$part = $xpath->query('//pdfaid:part');
		$this->assertSame(1, $part->length, $name . ' does not carry exactly one PDF/A part number');
		$this->assertSame('3', trim($part->item(0)->textContent), $name . ' is not identified as PDF/A-3');
		$conformance = $xpath->query('//pdfaid:conformance');
		$this->assertSame(1, $conformance->length, $name . ' does not carry exactly one PDF/A conformance level');
		$this->assertSame('B', trim($conformance->item(0)->textContent), $name . ' is not identified as conformance level B');

		// One extension schema bag, with Factur-X declared inside it. A second bag on the same
		// subject is what used to stop the packet from being read at all.
		$bags = $xpath->query('//pdfaExtension:schemas');
		$this->assertSame(1, $bags->length, $name . ' carries ' . $bags->length . ' PDF/A extension schema declarations instead of one');
		$facturx = $xpath->query('//pdfaExtension:schemas//rdf:li[pdfaSchema:namespaceURI="urn:factur-x:pdfa:CrossIndustryDocument:invoice:1p0#"]');
		$this->assertSame(1, $facturx->length, $name . ' does not declare the Factur-X extension schema inside the extension schema bag');

		// The Factur-X properties themselves, which the declaration above is what makes legal. The
		// conformance level is not asserted to one value: both mergers read it from the guideline of
		// the XML they are handed, so it follows the fixture - it only has to be one Factur-X knows.
		$properties = array('DocumentType' => 'INVOICE', 'DocumentFileName' => 'factur-x.xml', 'Version' => '1.0');
		foreach ($properties as $property => $expected) {
			$found = $xpath->query('//fx:' . $property);
			$this->assertSame(1, $found->length, $name . ' does not carry exactly one fx:' . $property);
			$this->assertSame($expected, trim($found->item(0)->textContent), $name . ' carries an unexpected fx:' . $property);
		}
		$level = $xpath->query('//fx:ConformanceLevel');
		$this->assertSame(1, $level->length, $name . ' does not carry exactly one fx:ConformanceLevel');
		$this->assertContains(
			trim($level->item(0)->textContent),
			array('MINIMUM', 'BASIC WL', 'BASIC', 'EN 16931', 'EXTENDED', 'XRECHNUNG'),
			$name . ' carries a fx:ConformanceLevel that is not one of Factur-X'
		);

		// The metadata stream says how long it is, and anything written into it after the fact has to
		// bring that back in line, or no parser reads the packet to its end.
		$this->assertMetadataStreamLengthIsRight($content, $name);

		// The XML is carried as an associated file and the document points at it: an attachment alone
		// is not a Factur-X file, only a PDF with something attached to it (issue #554).
		$this->assertMatchesRegularExpressionCompat('#/AF\s*\[#', $content, $name . ' has no document level /AF array');
		$this->assertMatchesRegularExpressionCompat('#/AFRelationship\s*/Data#', $content, $name . ' does not relate the embedded file to the document as /Data');
		$this->assertMatchesRegularExpressionCompat('#/F\s*\(factur-x\.xml\)#', $content, $name . ' does not embed the XML under the name factur-x.xml');
		$this->assertStringContainsString('/EmbeddedFile', $content, $name . ' embeds no file at all');

		// And it is a PDF/A: the output intent is what a validator looks for first.
		$this->assertStringContainsString('GTS_PDFA1', $content, $name . ' has no PDF/A output intent');
	}

	/**
	 * Extract the XMP packet of a document.
	 *
	 * @param	string	$content	Raw content of the document
	 * @param	string	$name		Name of the document, for the messages
	 * @return	string				The packet alone, without the object that carries it
	 */
	private function extractXmpPacket($content, $name)
	{
		$start = strpos($content, '<x:xmpmeta');
		$end = strpos($content, '</x:xmpmeta>');
		$this->assertNotFalse($start, $name . ' carries no XMP packet');
		$this->assertNotFalse($end, $name . ' carries an unterminated XMP packet');

		return substr($content, $start, $end - $start + strlen('</x:xmpmeta>'));
	}

	/**
	 * Check that the /Length of the metadata object lands exactly on the end of its stream.
	 *
	 * The two writers do not lay the dictionary out the same way - one puts /Length before /Type, the
	 * other after - so the declaration is looked for around the object, not after it.
	 *
	 * @param	string	$content	Raw content of the document
	 * @param	string	$name		Name of the document, for the messages
	 * @return	void
	 */
	private function assertMetadataStreamLengthIsRight($content, $name)
	{
		$metadata = strpos($content, '/Type /Metadata');
		if ($metadata === false) {
			$metadata = strpos($content, '/Type/Metadata');
		}
		$this->assertNotFalse($metadata, $name . ' carries no metadata object');

		$dictionary = substr($content, max(0, $metadata - 160), 400);
		$this->assertSame(1, preg_match('#/Length\s+(\d+)#', $dictionary, $reg), $name . ' does not declare the length of its metadata stream');
		$declared = (int) $reg[1];

		$start = strpos($content, 'stream', $metadata);
		$this->assertNotFalse($start, $name . ' carries a metadata object with no stream');
		$start += strlen('stream');
		if (substr($content, $start, 2) === "\r\n") {		// A stream keyword is followed by CRLF or LF, never CR alone
			$start += 2;
		} elseif (substr($content, $start, 1) === "\n") {
			$start += 1;
		}

		$this->assertMatchesRegularExpressionCompat(
			'#^\s*endstream#',
			substr($content, $start + $declared, 32),
			$name . ' declares a metadata stream length that does not match the packet it carries'
		);
	}

	/**
	 * assertMatchesRegularExpression() only exists from PHPUnit 9.1, and the instances this module is
	 * tested on do not all carry the same runner.
	 *
	 * @param	string	$pattern	Regular expression
	 * @param	string	$subject	String to match against
	 * @param	string	$message	Failure message
	 * @return	void
	 */
	private function assertMatchesRegularExpressionCompat($pattern, $subject, $message = '')
	{
		if (method_exists($this, 'assertMatchesRegularExpression')) {
			$this->assertMatchesRegularExpression($pattern, $subject, $message);
			return;
		}
		$this->assertRegExp($pattern, $subject, $message);		// @phan-suppress-current-line PhanDeprecatedFunction
	}

	/**
	 * The merger used from Dolibarr 24, where nothing has reserved the global class FPDF, must
	 * produce a Factur-X container.
	 *
	 * @param	string	$fixture	Full path of the XML to embed
	 * @return	void
	 * @dataProvider fixtureProvider
	 */
	public function testCtcFrPdfMergerBuildsAFacturxContainer($fixture)
	{
		if (class_exists('FPDF', false) && is_subclass_of('FPDF', 'TCPDF')) {
			$this->markTestSkipped('The global class FPDF is the shim of the core, which is exactly the case FacturxTcpdfMerger exists for');
		}

		$this->assertIsFacturxContainer($this->buildDocument('CtcFrPdfMerger', 'ctcfr', $fixture));
	}

	/**
	 * The merger used below Dolibarr 24, built on TCPDF because the core reserves the class FPDF
	 * there, must produce the same container.
	 *
	 * Declared after the other one on purpose: TCPDF is only loaded from here, so the merger built on
	 * FPDF is exercised first, on a process where nothing has claimed that name yet.
	 *
	 * @param	string	$fixture	Full path of the XML to embed
	 * @return	void
	 * @dataProvider fixtureProvider
	 */
	public function testFacturxTcpdfMergerBuildsAFacturxContainer($fixture)
	{
		if (!class_exists('TCPDF', false)) {
			// TCPDF is shipped by Dolibarr, not by this repository. TCPDF_PATH is the constant the
			// core defines for it; TCPDF_DIR is how the CI, which has no instance, says where the
			// plain release it downloaded lives.
			$tcpdf = defined('TCPDF_PATH') ? TCPDF_PATH . 'tcpdf.php' : rtrim((string) getenv('TCPDF_DIR'), '/') . '/tcpdf.php';
			if (!is_readable($tcpdf)) {
				$this->markTestSkipped('No TCPDF to build on: set TCPDF_DIR to the directory of a TCPDF release');
			}
			require_once $tcpdf;
		}
		require_once dirname(__FILE__) . '/../../class/utils/FacturxTcpdfMerger.class.php';

		$this->assertIsFacturxContainer($this->buildDocument('FacturxTcpdfMerger', 'tcpdf', $fixture));
	}
}
