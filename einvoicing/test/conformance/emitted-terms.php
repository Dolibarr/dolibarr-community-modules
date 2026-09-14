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
 * \file    einvoicing/test/conformance/emitted-terms.php
 * \ingroup einvoicing
 * \brief   What the reference documents carry and this module never writes.
 *
 * The other direction of import-corpus.php. A specimen invoice would answer the wrong question -
 * an element missing from one generated document may only mean that invoice had nothing to put
 * there - so the measure is taken on what the builder is *able* to write: the elements it
 * constructs anywhere in its source. An element the reference documents carry and no line of this
 * module ever constructs is one we cannot emit, whatever the invoice.
 *
 * Needs no Dolibarr instance and no database.
 *
 *   php emitted-terms.php <reference directory> [module directory]
 */

$args = array_slice($argv, 1);
$updateBaseline = in_array('--update-baseline', $args, true);
$args = array_values(array_filter($args, function ($a) {
	return $a !== '--update-baseline';
}));
$reference = $args[0] ?? '';
$moduleDir = $args[1] ?? dirname(__DIR__, 2);
$baselineFile = __DIR__ . '/emitted-elements.txt';
if ($reference === '' || !is_dir($reference)) {
	fwrite(STDERR, "usage: emitted-terms.php <reference directory> [module directory]\n");
	exit(2);
}
if (!is_dir($moduleDir)) {
	fwrite(STDERR, "module directory not found: " . $moduleDir . "\n");
	exit(2);
}

/**
 * Every element name the module builds, and every element name it reads.
 *
 * @param	string	$dir	Module directory
 * @return	array{written:array<string,bool>,read:array<string,bool>}	The two sets, keyed by element name
 */
function einvoicingEmittedSets($dir)
{
	$written = array();
	$read = array();

	$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
	foreach ($it as $entry) {
		$path = $entry->getPathname();
		// The library ships its own builder and its own reader, and the test files name the elements
		// they assert on: neither says anything about what the module is able to build.
		if (strpos($path, '/vendor/') !== false || strpos($path, '/test/') !== false
			|| strtolower($entry->getExtension()) !== 'php') {
			continue;
		}
		$source = (string) file_get_contents($path);

		if (preg_match_all('/createElement(?:NS)?\(\s*(?:\$[A-Za-z_]+\s*,\s*)?[\'"]([A-Za-z]+:)?([A-Za-z0-9]+)[\'"]/', $source, $m)) {
			foreach ($m[2] as $name) {
				$written[$name] = true;
			}
		}
		// A tag name is not always a literal argument of createElement(): the root is handed to
		// createDocument(), and the party builder picks its tag with a ternary. A bare qualified
		// name with no slash in it is a tag; the reading side always writes a path, which has one.
		if (preg_match_all('/[\'"](?:ram|rsm|qdt|udt):([A-Za-z0-9]+)[\'"]/', $source, $m, PREG_SET_ORDER)) {
			foreach ($m as $found) {
				if (strpos($found[0], '/') === false) {
					$written[$found[1]] = true;
				}
			}
		}

		// The reading side names its terms inside XPath strings instead.
		if (preg_match_all('/(?:ram|rsm|qdt|udt|cbc|cac):([A-Za-z0-9]+)/', $source, $m)) {
			foreach ($m[1] as $name) {
				$read[$name] = true;
			}
		}
	}

	return array('written' => $written, 'read' => $read);
}

$sets = einvoicingEmittedSets($moduleDir);

// An element the builder used to construct and constructs no more is a capability leaving the
// module. It produces no invalid document and no failing test: the invoices that needed it simply
// stop carrying it. The list below is the guard - it may grow freely, and a name may only leave it
// on purpose, by running this with --update-baseline.
$current = array_keys($sets['written']);
sort($current);
if ($updateBaseline) {
	file_put_contents($baselineFile, implode("\n", $current) . "\n");
	echo count($current) . " element name(s) written to " . basename($baselineFile) . "\n";
	exit(0);
}
$lost = array();
if (is_file($baselineFile)) {
	$baseline = array_filter(array_map('trim', (array) file($baselineFile)), 'strlen');
	$lost = array_values(array_diff($baseline, $current));
	$gained = array_values(array_diff($current, $baseline));
	if ($lost) {
		echo "The builder no longer constructs " . count($lost) . " element(s) it used to:\n";
		foreach ($lost as $name) {
			echo '    ' . $name . "\n";
		}
		echo "If that is on purpose, say so in the pull request and rerun with --update-baseline.\n\n";
	}
	if ($gained) {
		echo count($gained) . ' new element(s) constructed: ' . implode(', ', $gained) . "\n\n";
	}
}

// The reference documents, grouped by the profile family they declare: an element the EXTENDED
// documents carry says nothing about what an EN16931 document is allowed to hold.
$families = array();
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($reference, FilesystemIterator::SKIP_DOTS));
foreach ($it as $entry) {
	if (strtolower($entry->getExtension()) !== 'xml') {
		continue;
	}
	$doc = new DOMDocument();
	if (!@$doc->load($entry->getPathname()) || $doc->documentElement === null
		|| $doc->documentElement->localName !== 'CrossIndustryInvoice') {
		continue;
	}

	$xpath = new DOMXPath($doc);
	$xpath->registerNamespace('ram', 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100');
	$urn = (string) $xpath->evaluate('string(//ram:GuidelineSpecifiedDocumentContextParameter/ram:ID)');
	if (strpos($urn, 'extended') !== false) {
		$family = 'EXTENDED';
	} elseif (strpos($urn, 'basicwl') !== false) {
		$family = 'BASICWL';
	} else {
		$family = 'EN16931';
	}

	foreach ($xpath->query('//*') as $node) {
		if (!isset($families[$family][$node->localName])) {
			$families[$family][$node->localName] = array();
		}
		$parent = $node->parentNode !== null && $node->parentNode->nodeType === XML_ELEMENT_NODE
			? $node->parentNode->localName : '-';
		$families[$family][$node->localName][$parent] = true;
	}
}

$order = array('BASICWL', 'EN16931', 'EXTENDED');
$total = 0;
foreach ($order as $family) {
	if (empty($families[$family])) {
		continue;
	}
	ksort($families[$family]);

	$gap = array();
	foreach ($families[$family] as $name => $parents) {
		if (isset($sets['written'][$name])) {
			continue;
		}
		$gap[$name] = array_keys($parents);
	}

	echo "\n=== " . $family . ': ' . count($gap) . ' of ' . count($families[$family]) . " element(s) the reference documents carry and the builder never constructs\n";
	foreach ($gap as $name => $parents) {
		printf(
			"    %-42s under %-34s %s\n",
			$name,
			implode(', ', array_slice($parents, 0, 2)),
			isset($sets['read'][$name]) ? '(named on the reading side)' : ''
		);
	}
	$total += count($gap);
}

printf("\n%d element name(s) the builder never constructs, across the families\n", $total);

exit($lost ? 1 : 0);
