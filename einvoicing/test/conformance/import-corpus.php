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
 */

/**
 * \file    einvoicing/test/conformance/import-corpus.php
 * \ingroup einvoicing
 * \brief   Read every reference document of the FNFE package with the module's own parser.
 *
 * The documents are the ones the FNFE publishes as the answer to its use cases: they are
 * conformant by construction, so anything this module fails to read out of them is a gap on
 * our side and nowhere else. Usage:
 *
 *   DOLI_ROOT=/var/www/dd23/htdocs php import-corpus.php <directory or file> ...
 */

$root = getenv('DOLI_ROOT') ?: '';
if ($root === '' || !file_exists($root . '/master.inc.php')) {
	fwrite(STDERR, "DOLI_ROOT must point at the htdocs of a Dolibarr instance\n");
	exit(2);
}
require_once $root . '/master.inc.php';
dol_include_once('einvoicing/class/protocols/CIIProtocol.class.php');
dol_include_once('einvoicing/class/utils/PdfAttachmentExtractor.class.php');

global $db;

$targets = array_slice($argv, 1);
if (!$targets) {
	fwrite(STDERR, "usage: import-corpus.php <directory or file> ...\n");
	exit(2);
}

$files = array();
foreach ($targets as $target) {
	if (is_dir($target)) {
		$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($target, FilesystemIterator::SKIP_DOTS));
		foreach ($it as $entry) {
			if (in_array(strtolower($entry->getExtension()), array('xml', 'pdf'), true)) {
				$files[] = $entry->getPathname();
			}
		}
	} elseif (is_file($target)) {
		$files[] = $target;
	}
}
sort($files);

/**
 * Reduce a value to what two writings of the same thing have in common: case, spaces, the
 * separators of a date and the trailing zeros of an amount are not a difference of content.
 *
 * @param	string	$value	Raw value
 * @return	string			Comparable form
 */
function einvoicingCorpusNorm($value)
{
	$value = strtolower(trim((string) $value));
	$value = str_replace(array(' ', '-', '/', ':', 'T'), '', $value);
	if (is_numeric($value)) {
		$value = rtrim(rtrim(number_format((float) $value, 6, '.', ''), '0'), '.');
	}

	return $value;
}

/**
 * Reduce a parsed structure to a comparable string: the dates become their day, and nothing else
 * of the content is touched.
 *
 * @param	mixed	$value	Parsed header or lines
 * @return	string			Comparable form
 */
function einvoicingCorpusPrint($value)
{
	if ($value instanceof DateTimeInterface) {
		return 'D' . $value->format('Ymd');
	}
	if (is_array($value)) {
		$parts = array();
		foreach ($value as $key => $item) {
			$parts[] = $key . '=' . einvoicingCorpusPrint($item);
		}
		sort($parts);

		return '[' . implode(';', $parts) . ']';
	}
	if (is_bool($value)) {
		return $value ? '1' : '0';
	}

	return (string) $value;
}

$protocol = new CIIProtocol($db);
$rows = array();
$failed = 0;

foreach ($files as $file) {
	$raw = file_get_contents($file);
	if ($raw === false || trim($raw) === '') {
		continue;
	}

	// A Factur-X file carries the same CII as a PDF/A-3 attachment. Reading it is the one step the
	// Factur-X protocol has of its own, and the only one the XML documents never exercise: the
	// extraction, then the parser below, is what a received PDF goes through in production
	// (FacturXProtocol::parseReceivedDocument, default path).
	$carrier = 'xml';
	if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'pdf') {
		$carrier = 'pdf';
		$xml = '';
		$extractionError = '';
		try {
			$xml = (string) PdfAttachmentExtractor::getInvoiceXmlFromFile($file);
		} catch (Throwable $e) {
			$extractionError = get_class($e) . ': ' . $e->getMessage();
		}
		if (trim($xml) === '') {
			$rows[] = array(
				'file' => basename($file), 'carrier' => 'pdf', 'profile' => '-', 'error' => $extractionError,
				'header' => 'KO', 'lines' => 'KO', 'inDoc' => 0, 'missing' => array(),
				'verdict' => 'GAP no CII extracted from the PDF' . ($extractionError !== '' ? ' - ' . $extractionError : ''),
			);
			$failed++;
			continue;
		}
	} else {
		$xml = $raw;
	}

	// The package ships the lifecycle messages (CDAR) and the UBL rendering of the same invoices
	// next to the CII ones. Neither is a CII document, and the module does not claim to read them.
	// The root element decides: a CDAR names the invoice it reports on, so its text mentions
	// CrossIndustryInvoice without being one.
	$probe = new DOMDocument();
	if (!@$probe->loadXML($xml) || $probe->documentElement === null
		|| $probe->documentElement->localName !== 'CrossIndustryInvoice') {
		continue;
	}

	$name = basename($file);
	$urn = '';
	if (preg_match('#<ram:GuidelineSpecifiedDocumentContextParameter>\s*<ram:ID>([^<]+)#', $xml, $m)) {
		$urn = trim($m[1]);
	}

	$header = null;
	$lines = null;
	$error = '';
	try {
		$header = $protocol->parseInvoiceHeader($xml);
		$lines = $protocol->parseInvoiceLines($xml);
	} catch (Throwable $e) {
		$error = get_class($e) . ': ' . $e->getMessage();
	}

	$doc = new DOMDocument();
	$doc->loadXML($xml);
	$xpath = new DOMXPath($doc);
	$xpath->registerNamespace('rsm', 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100');
	$xpath->registerNamespace('ram', 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100');
	$inDoc = (int) $xpath->evaluate('count(//ram:IncludedSupplyChainTradeLineItem)');

	$row = array(
		'file' => $name,
		'carrier' => $carrier,
		'profile' => $urn === '' ? '?' : substr($urn, strrpos($urn, ':') + 1),
		'error' => $error,
		'header' => is_array($header) ? 'ok' : var_export($header, true),
		'lines' => is_array($lines) ? count($lines) : 'KO',
		'inDoc' => $inDoc,
	);

	// What the document says, next to what the parser brought back: a term the document carries and
	// the parser leaves empty is read by nobody, whatever the rules think of the document.
	$missing = array();
	if (is_array($header)) {
		$terms = array(
			'BT-1 documentno' => array('documentno', 'string(//rsm:ExchangedDocument/ram:ID)'),
			'BT-3 typecode' => array('documenttypecode', 'string(//rsm:ExchangedDocument/ram:TypeCode)'),
			'BT-5 currency' => array('invoiceCurrency', 'string(//ram:InvoiceCurrencyCode)'),
			'BT-27 seller' => array('sellername', 'string(//ram:SellerTradeParty/ram:Name)'),
			'BT-44 buyer' => array('buyername', 'string(//ram:BuyerTradeParty/ram:Name)'),
			'BT-31 seller VAT' => array('sellervatnumber', 'string(//ram:SellerTradeParty/ram:SpecifiedTaxRegistration/ram:ID[@schemeID="VA"])'),
			'BT-109 total HT' => array('lineTotalAmount', 'string(//ram:SpecifiedTradeSettlementHeaderMonetarySummation/ram:LineTotalAmount)'),
			'BT-112 total TTC' => array('grandTotalAmount', 'string(//ram:SpecifiedTradeSettlementHeaderMonetarySummation/ram:GrandTotalAmount)'),
			'BT-113 prepaid' => array('totalPrepaidAmount', 'string(//ram:SpecifiedTradeSettlementHeaderMonetarySummation/ram:TotalPrepaidAmount)'),
			'BT-115 due' => array('duePayableAmount', 'string(//ram:SpecifiedTradeSettlementHeaderMonetarySummation/ram:DuePayableAmount)'),
			'BT-10 buyer ref' => array('buyerReference', 'string(//ram:BuyerReference)'),
			'BT-13 order ref' => array('orderReference', 'string(//ram:BuyerOrderReferencedDocument/ram:IssuerAssignedID)'),
			'BT-12 contract' => array('contractReference', 'string(//ram:ContractReferencedDocument/ram:IssuerAssignedID)'),
			'BT-23 framework' => array('businessProcessId', 'string(//ram:BusinessProcessSpecifiedDocumentContextParameter/ram:ID)'),
		);
		foreach ($terms as $label => $pair) {
			list($key, $query) = $pair;
			$inXml = trim((string) $xpath->evaluate($query));
			$parsed = isset($header[$key]) ? trim((string) (is_array($header[$key]) ? reset($header[$key]) : $header[$key])) : '';
			if ($inXml !== '' && ($parsed === '' || $parsed === 'NA')) {
				$missing[] = $label;
			}
		}
	}
	$row['missing'] = $missing;

	// The corpus ships each Factur-X PDF next to the standalone XML of the same invoice. Their
	// parses have to be the same object: anything else is the extraction losing or altering what it
	// pulls out of the PDF/A-3 attachment, which no rule and no XML document can report.
	$row['key'] = preg_replace('/(_CII_Commentee)?\.(xml|pdf)$/i', '', $name);
	$row['print'] = md5(einvoicingCorpusPrint($header) . '|' . einvoicingCorpusPrint($lines));

	// Deep mode: every leaf value the document carries, confronted with everything the parser
	// brought back. A value present in the XML and absent from the whole parsed structure is read
	// by nobody - which is how a term goes missing without any rule noticing.
	if (getenv('DEEP') && is_array($header)) {
		$known = array();
		$flatten = function ($value) use (&$flatten, &$known) {
			if (is_array($value)) {
				foreach ($value as $item) {
					$flatten($item);
				}
			} elseif ($value instanceof DateTimeInterface) {
				$known[] = $value->format('Ymd');
				$known[] = $value->format('Y-m-d');
			} elseif (is_scalar($value)) {
				$known[] = trim((string) $value);
			}
		};
		$flatten($header);
		$flatten($lines);
		$known = array_map('einvoicingCorpusNorm', $known);
		$known = array_flip(array_filter($known, 'strlen'));

		$unread = array();
		foreach ($xpath->query('//*[not(*)]') as $leaf) {
			$text = trim((string) $leaf->textContent);
			if ($text === '') {
				continue;
			}
			if (isset($known[einvoicingCorpusNorm($text)])) {
				continue;
			}
			$path = array();
			for ($node = $leaf; $node !== null && $node->nodeType === XML_ELEMENT_NODE; $node = $node->parentNode) {
				array_unshift($path, $node->nodeName);
			}
			$unread[implode('/', array_slice($path, -3)) . ' = ' . substr($text, 0, 28)] = true;
		}
		$row['unread'] = array_keys($unread);
	}

	if ($error !== '' || !is_array($header) || !is_array($lines) || (int) $row['lines'] !== $inDoc || $missing) {
		$failed++;
		$row['verdict'] = 'GAP';
	} else {
		$row['verdict'] = 'ok';
	}

	$rows[] = $row;
}

// A PDF and the standalone XML of the same invoice must come back identical.
$twins = array();
foreach ($rows as $r) {
	if (isset($r['key'])) {
		$twins[$r['key']][$r['carrier']] = $r['print'];
	}
}
$compared = 0;
foreach ($twins as $key => $pair) {
	if (!isset($pair['pdf'], $pair['xml'])) {
		continue;
	}
	$compared++;
	if ($pair['pdf'] !== $pair['xml']) {
		$failed++;
		echo 'GAP ' . $key . ": the PDF and the XML of that invoice do not parse to the same thing\n";
	}
}

printf("%-58s %-4s %-16s %-6s %-6s %-5s %s\n", 'document', 'in', 'profile', 'header', 'lines', 'doc', 'verdict');
foreach ($rows as $r) {
	if (getenv('DEEP')) {
		echo "\n### " . $r['file'] . ' (' . $r['profile'] . ') - ' . count($r['unread'] ?? array()) . " value(s) the parser does not bring back\n";
		foreach (($r['unread'] ?? array()) as $u) {
			echo '    ' . $u . "\n";
		}
		continue;
	}
	printf(
		"%-58s %-4s %-16s %-6s %-6s %-5s %s\n",
		substr($r['file'], 0, 58),
		$r['carrier'],
		substr($r['profile'], 0, 16),
		$r['header'],
		(string) $r['lines'],
		(string) $r['inDoc'],
		$r['verdict'] . ($r['error'] !== '' ? ' ' . $r['error'] : '') . ($r['missing'] ? ' missing: ' . implode(', ', $r['missing']) : '')
	);
}

printf("\n%d document(s) read, %d Factur-X PDF compared with its XML twin, %d with a gap\n", count($rows), $compared, $failed);

exit($failed > 0 ? 1 : 0);
