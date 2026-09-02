<?php
/* Copyright (C) 2026 Pierre Grasswill
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
 *      \file       test/phpunit/XmlSourceHighlightTest.php
 *      \ingroup    test
 *      \brief      PHPUnit test for the coloured XML source of the read-only viewer. Colouring means
 *                  writing markup around the file, so the two properties tested here are the ones that
 *                  make it safe: nothing of the document survives as markup, and the file reads as it is.
 *      \remarks    To run this script as CLI: phpunit filename.php
 */

global $conf, $user, $langs, $db;

// See RecipientDirectoryTest.php for why DOLIBARR_HTDOCS is honoured before the relative path.
$dolibarrHtdocs = getenv('DOLIBARR_HTDOCS');
if (!$dolibarrHtdocs) {
	$dolibarrHtdocs = dirname(__FILE__) . '/../../htdocs';
}
if (!file_exists($dolibarrHtdocs . '/master.inc.php')) {
	throw new \RuntimeException('Could not locate master.inc.php under "' . $dolibarrHtdocs . '/". Set the environment variable (export DOLIBARR_HTDOCS=...) to the htdocs directory of the Dolibarr instance to test against.');
}

require_once $dolibarrHtdocs . '/master.inc.php';
dol_include_once('einvoicing/lib/xmlhighlight.lib.php');
require_once __DIR__ . '/CommonClassTestCompat.inc.php';


/**
 * Tests on the HTML the XML viewer builds from a file.
 *
 * @backupGlobals disabled
 * @backupStaticAttributes enabled
 * @remarks	backupGlobals must be disabled to have db,conf,user and lang not erased.
 */
class XmlSourceHighlightTest extends CommonClassTest
{
	/**
	 * Sources the viewer has to survive: a normal document, the two shapes that would execute
	 * something if anything of the file reached the browser as markup, the stylesheet instruction the
	 * core refuses to serve inline, and four files that are not valid XML - the viewer is asked
	 * precisely when something looks wrong with a document, so it must show a broken one too.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function sourceProvider()
	{
		return array(
			'nominal CII' => array("<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<!-- generated -->\n<rsm:CrossIndustryInvoice xmlns:rsm=\"urn:x\" xmlns:ram=\"urn:y\">\n  <ram:ID>FA2607-0001</ram:ID>\n  <ram:Empty/>\n  <ram:Note><![CDATA[raw & <stuff>]]></ram:Note>\n</rsm:CrossIndustryInvoice>\n"),
			'script in a text value' => array('<a><script>alert(1)</script></a>'),
			'markup in an attribute value' => array('<a b="&quot;><img src=x onerror=alert(1)>"/>'),
			'xml-stylesheet instruction' => array("<?xml-stylesheet type=\"text/xsl\" href=\"anywhere.xsl\"?>\n<a/>"),
			'unbalanced tags' => array("<a>\n<b>\n</a>"),
			'truncated tag' => array("<a attr=\"v\"\n"),
			'comment over several lines' => array("<a>\n<!-- start\nstill a comment\nend -->\n</a>"),
			'entities and accents' => array("<a>&amp; &#233; &lt; Éléctricité</a>"),
			'empty file' => array(''),
			'not xml at all' => array('hello < world > & co'),
		);
	}

	/**
	 * The only tags of the output are the div and the span the viewer writes: anything the document
	 * holds is escaped, whatever it looks like.
	 *
	 * @param	string	$xml	Source to render
	 * @return	void
	 * @dataProvider sourceProvider
	 */
	public function testNothingOfTheDocumentSurvivesAsMarkup($xml)
	{
		$html = einvoicingXmlSourceToHtml($xml);

		$found = array();
		preg_match_all('/<(?!\/?(?:div|span)[\s>])[^>]*>/', $html, $found);

		$this->assertSame(array(), $found[0], 'A tag of the document reached the output as markup');
	}

	/**
	 * The point of the page is to show the file as it is: once the markup of the viewer is removed,
	 * what is left has to be the file, byte for byte.
	 *
	 * @param	string	$xml	Source to render
	 * @return	void
	 * @dataProvider sourceProvider
	 */
	public function testTheSourceIsShownUnchanged($xml)
	{
		$html = einvoicingXmlSourceToHtml($xml);

		// The line number and the fold handle are what the viewer adds inside a line; every line ends
		// with its </div>
		$stripped = preg_replace('/<span class="xml(?:no|fold)">[^<]*<\/span>/', '', $html);
		$plain = html_entity_decode(strip_tags(str_replace('</div>', "\n", (string) $stripped)), ENT_QUOTES, 'UTF-8');
		$plain = substr($plain, 0, -1);

		$this->assertSame($xml, $plain, 'The rendered source is not the file');
	}

	/**
	 * A line opening an element closed further down carries the number of the line that closes it, and
	 * an element that opens and closes on one line carries nothing - that is what the folding script
	 * reads, and it has to hold on a document that does not close its tags either.
	 *
	 * @return void
	 */
	public function testFoldTargetsAreTheClosingLines()
	{
		preg_match_all('/data-foldend="(\d+)"/', einvoicingXmlSourceToHtml("<a>\n<b>\n<c/>\n</b>\n</a>"), $found);
		$this->assertSame(array('4', '3'), $found[1], 'The fold targets are not the closing lines');

		preg_match_all('/data-foldend="(\d+)"/', einvoicingXmlSourceToHtml("<a><b/></a>\n<c/>"), $found);
		$this->assertSame(array(), $found[1], 'A single line element was made foldable');

		// Unbalanced: <a> is never closed, only <b> is, and it closes nothing since it opens on that line
		preg_match_all('/data-foldend="(\d+)"/', einvoicingXmlSourceToHtml("<a>\n<b></b>"), $found);
		$this->assertSame(array(), $found[1], 'An unclosed element was given a fold target');
	}

	/**
	 * The number in the margin is the number of the line in the file, written in the page rather than
	 * built by a CSS counter - a counter does not increment on a line the folding hides, so folding
	 * would renumber everything below it.
	 *
	 * @return void
	 */
	public function testEveryLineCarriesItsOwnNumber()
	{
		$html = einvoicingXmlSourceToHtml("<a>\n<b/>\n<c/>\n</a>");

		preg_match_all('/<span class="xmlno">(\d+)<\/span>/', $html, $found);

		$this->assertSame(array('1', '2', '3', '4'), $found[1], 'The lines are not numbered as they are in the file');
	}

	/**
	 * The parts a reader looks for are told apart: the namespace prefix from the local name, the
	 * attribute names from their values, and the comments and CDATA sections from the rest.
	 *
	 * @return void
	 */
	public function testEveryKindOfTokenIsColoured()
	{
		$html = einvoicingXmlSourceToHtml("<?xml version=\"1.0\"?>\n<!-- c -->\n<ram:ID unit=\"C62\">4</ram:ID>\n<a><![CDATA[x]]></a>");

		$this->assertStringContainsString('<span class="xmlpi">', $html, 'The declaration is not coloured');
		$this->assertStringContainsString('<span class="xmlcomment">', $html, 'The comment is not coloured');
		$this->assertStringContainsString('<span class="xmlprefix">ram:</span>', $html, 'The namespace prefix is not told apart');
		$this->assertStringContainsString('<span class="xmlname">ID</span>', $html, 'The element name is not coloured');
		$this->assertStringContainsString('<span class="xmlattr">unit</span>', $html, 'The attribute name is not coloured');
		$this->assertStringContainsString('<span class="xmlvalue">&quot;C62&quot;</span>', $html, 'The attribute value is not coloured');
		$this->assertStringContainsString('<span class="xmlcdata">', $html, 'The CDATA section is not coloured');
	}

	/**
	 * The stylesheet and the folding script the page prints hold nothing but themselves, and the
	 * script is skipped when the caller does not want one.
	 *
	 * @return void
	 */
	public function testTheStylesheetCanBePrintedWithoutTheScript()
	{
		$this->assertStringNotContainsString('<script', einvoicingXmlSourceCss(0), 'A script was printed when none was asked for');

		$withscript = einvoicingXmlSourceCss(1, 'abc123');
		$this->assertStringContainsString('<script nonce="abc123"', $withscript, 'The nonce of the page is not carried');
		$this->assertStringContainsString('pre.xmlsource', $withscript, 'The stylesheet does not scope itself to the source block');
	}
}
