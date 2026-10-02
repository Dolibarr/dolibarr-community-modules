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
 * \file    einvoicing/test/conformance/import-amounts.php
 * \brief   Imports CII documents for real and answers whether the invoice reproduces their amounts.
 * \remarks Closes the loop on the documents the CI generates: what the rules accept on the way out has
 *          to come back in and rebuild the same amounts. The confrontation is the module's own,
 *          SupplierInvoiceHelper::compareInvoiceWithDocument(), so this gate covers whatever that method
 *          covers and grows with it instead of restating it. A document referencing another is retried
 *          once the first has been imported. Reads DOLIBARR_HTDOCS. Everything is rolled back at the end.
 */

if (PHP_SAPI !== 'cli') {
	echo "Error: this script must be run from the command line.\n";
	exit(1);
}

global $conf, $db, $langs, $user, $mysoc;

$htdocs = getenv('DOLIBARR_HTDOCS');
if (!$htdocs || !file_exists($htdocs . '/master.inc.php')) {
	fwrite(STDERR, 'DOLIBARR_HTDOCS does not point at an htdocs directory (got "' . $htdocs . '")' . "\n");
	exit(2);
}

require_once $htdocs . '/master.inc.php';
require_once DOL_DOCUMENT_ROOT . '/user/class/user.class.php';
// The import stamps the invoice with AbstractPDPProvider::$EINVOICING_LAST_IMPORT_KEY, which the
// autoloader of a page would have brought in: a CLI run has to load the class itself.
dol_include_once('/einvoicing/class/providers/AbstractPDPProvider.class.php');
dol_include_once('/einvoicing/class/protocols/ProtocolManager.class.php');
dol_include_once('/einvoicing/class/utils/SupplierInvoiceHelper.class.php');

$files = array_slice($argv, 1);
$idFromName = in_array('--id-from-name', $files, true);
$files = array_values(array_diff($files, array('--id-from-name')));
if (empty($files)) {
	fwrite(STDERR, "usage: DOLIBARR_HTDOCS=... php import-amounts.php [--id-from-name] <file.xml> [file.xml ...]\n");
	exit(2);
}

// A blank instance holds neither the vendor of the document nor its products, and an import that
// stops on that confronts no amount at all. The two auto-generations are turned on for this run only,
// in memory: nothing is written to llx_const and the instance keeps the setting it had. This is the
// posture of an instance receiving its first document from a new vendor, which is the case under test.
$conf->global->EINVOICING_THIRDPARTIES_AUTO_GENERATION = 1;
$conf->global->EINVOICING_PRODUCTS_AUTO_GENERATION = 1;

// An import runs as a real user, the way the scheduled synchronization does.
$user = new User($db);
$resql = $db->query("SELECT rowid FROM " . MAIN_DB_PREFIX . "user WHERE admin = 1 AND statut = 1 ORDER BY rowid ASC");
if ($resql && $obj = $db->fetch_object($resql)) {
	$user->fetch($obj->rowid);
	$user->getrights();
} else {
	fwrite(STDERR, "no active admin user on this instance\n");
	exit(2);
}

$db->begin();

/**
 * Write the einvoicing_document row a synchronization would have written for a document just imported.
 *
 * checkDolInvoiceAndEInvoiceConsistency() is the module's comparison and it reads the document from that
 * table. Nothing here changes what it compares: the row is the link between the invoice and the XML it
 * was built from, and the transaction takes it back at the end of the run.
 *
 * @param	int		$supplierInvoiceId	Id of the invoice the import created
 * @param	string	$content			The document it was built from
 * @return	void
 */
function einvoicing_attach_document_row($supplierInvoiceId, $content)
{
	global $db, $user;

	$now = $db->idate(dol_now());
	$sql = "INSERT INTO " . MAIN_DB_PREFIX . "einvoicing_document";
	$sql .= " (date_creation, fk_user_creat, status, flow_type, flow_id, provider, submittedat, xml_data, fk_element_type, fk_element_id)";
	$sql .= " VALUES ('" . $db->escape($now) . "', " . (int) $user->id . ", 0, 'SupplierInvoice',";
	$sql .= " 'conformance-" . (int) $supplierInvoiceId . "', 'CONFORMANCE', '" . $db->escape($now) . "',";
	$sql .= " '" . $db->escape($content) . "', 'invoice_supplier', " . (int) $supplierInvoiceId . ")";

	if (!$db->query($sql)) {
		fwrite(STDERR, 'could not link the document to invoice ' . $supplierInvoiceId . ': ' . $db->lasterror() . "\n");
	}
}

/**
 * Give a specimen back the document number the other specimens refer to it by.
 *
 * generateSampleEInvoicesForTests() flattens the timestamp of every specimen, so the five of them end
 * up carrying the same BT-1 and referring to a name none of them has. Imported as they are they collide
 * with one another and an invoice is confronted with the document of another. The number is rebuilt from
 * the file name, which is the name the referring documents already use. No amount is touched.
 *
 * @param	string	$content	The document
 * @param	string	$file		Its path, whose name carries the type
 * @return	string				The document, with its BT-1 rebuilt
 */
function einvoicing_document_number_from_name($content, $file)
{
	$type = strtoupper(preg_replace('/^cii_|\.xml$/', '', basename($file)));

	return preg_replace(
		'|(<rsm:ExchangedDocument>.*?<ram:ID>)[^<]*(</ram:ID>)|s',
		'${1}FA0000-SPECIMEN-' . $type . '${2}',
		$content,
		1
	);
}

/**
 * Import one document and confront the invoice it builds with what the document announces.
 *
 * @param	string					$file		Path of the CII document
 * @param	array<int,string>		$report		Lines to print, completed here
 * @return	string								'ok', 'differs' when the amounts are not rebuilt, 'failed' when nothing was imported
 */
function einvoicing_import_reproduces($file, array &$report)
{
	global $db, $idFromName;

	if (!is_readable($file)) {
		$report[] = sprintf('%-40s UNREADABLE', basename($file));
		return 'failed';
	}

	$content = (string) file_get_contents($file);
	if ($idFromName) {
		$content = einvoicing_document_number_from_name($content, $file);
	}
	$detected = ProtocolManager::getProtocolFromContent($content);
	if (empty($detected['protocol_object'])) {
		$report[] = sprintf('%-40s NO PROTOCOL    the document was accepted by the rules but is not recognised here', basename($file));
		return 'failed';
	}

	$res = $detected['protocol_object']->createSupplierInvoiceFromSource($content);
	$res = is_array($res) ? $res : array('res' => (int) $res, 'message' => '');
	$supplierInvoiceId = (int) ($res['res'] ?? 0);

	if ($supplierInvoiceId <= 0) {
		$report[] = sprintf('%-40s IMPORT FAILED  %s', basename($file), substr(strip_tags((string) ($res['message'] ?? '')), 0, 150));
		return 'failed';
	}

	$invoice = new FactureFournisseur($db);
	$invoice->fetch($supplierInvoiceId);
	// checkDolInvoiceAndEInvoiceConsistency() reads the document back from einvoicing_document, where only
	// a synchronization puts it. The row a synchronization would have written is written here instead, so
	// what confronts the invoice is the module's own comparison, unchanged and on its usual footing. It
	// goes back with everything else when the transaction is rolled back.
	einvoicing_attach_document_row($supplierInvoiceId, $content);
	$comparison = SupplierInvoiceHelper::checkDolInvoiceAndEInvoiceConsistency($invoice);
	if ($comparison === false) {
		$report[] = sprintf('%-40s NOT COMPARED   the document could not be read back from einvoicing_document', basename($file));
		return 'failed';
	}

	// BT-113 moves neither BT-110 nor BT-112, so every total the comparison above reads agrees whether or
	// not the deduction was attached: an invoice short of what the vendor says he already holds rebuilds
	// the document perfectly. The import has already ruled on it - whether that amount is a deposit to
	// attach at all is BT-23's business, and reading it a second time here would be a second rule to keep
	// in step - so what is read back is the mark it left, and what it says is still missing.
	$mark = SupplierInvoiceHelper::totalsMismatch($supplierInvoiceId);
	if (isset($mark['prepaid'])) {
		$deducted = SupplierInvoiceHelper::linkedDepositAmount($supplierInvoiceId);
		if (abs($deducted - abs((float) $mark['prepaid'])) >= 0.005) {
			$comparison['identical'] = false;
			$comparison['errors'][] = sprintf(
				'The document announces %s including VAT as already paid and the invoice carries %s of deduction.',
				price2num(abs((float) $mark['prepaid']), 'MT'),
				price2num($deducted, 'MT')
			);
		}
	}

	if (!empty($comparison['identical'])) {
		$report[] = sprintf('%-40s ok             %s incl. VAT rebuilt', basename($file), price2num($invoice->total_ttc, 'MT'));
		return 'ok';
	}

	$report[] = sprintf('%-40s DIFFERS', basename($file));
	foreach ((array) $comparison['errors'] as $error) {
		$report[] = '    ' . strip_tags((string) $error);
	}

	return 'differs';
}

// A document may reference another - a credit note names the invoice it corrects - and that one has to
// be in Dolibarr first. Rather than ordering the corpus by hand, whatever failed is tried again once
// everything else is in: a reference that was missing on the first pass is there on the second.
$outcome = array();
$verdict = array();
$pending = $files;
// Only a document that imported nothing is worth a second pass: one whose amounts differ has an
// invoice already, and importing it again would collide with it.
for ($pass = 1; $pass <= 2 && !empty($pending); $pass++) {
	$stillPending = array();
	foreach ($pending as $file) {
		$lines = array();
		$verdict[$file] = einvoicing_import_reproduces($file, $lines);
		$outcome[$file] = $lines;
		if ($verdict[$file] === 'failed') {
			$stillPending[] = $file;
		}
	}
	$pending = $stillPending;
}

$db->rollback();

foreach ($files as $file) {
	echo implode("\n", $outcome[$file] ?? array(sprintf('%-40s NOT RUN', basename($file)))) . "\n";
}
$rebuilt = count(array_filter($verdict, function ($v) {
	return $v === 'ok';
}));
echo sprintf("\n%d document(s), %d rebuilt the amounts they announce, %d did not.\n",
	count($files), $rebuilt, count($files) - $rebuilt);

$failed = count($files) - $rebuilt;

exit($failed > 0 ? 1 : 0);
