<?php
/* Copyright (C) 2026		Pierre Grasswill				<da.grumpf@gmail.com>
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
 *   	\file       xmlpreview.php
 *		\ingroup    einvoicing
 *		\brief      Read-only viewer of the e-invoice XML of an invoice.
 *
 *		Dolibarr excludes XML from its preview on purpose: an inline text/xml response served from the
 *		Dolibarr origin can carry an xml-stylesheet processing instruction and render arbitrary HTML
 *		there. So the file is not served inline here either - it is read on the server and printed
 *		escaped inside a <pre>. Only the XML is concerned, a Factur-X e-invoice being a PDF the core
 *		previews itself; element=supplier asks for a document received instead of one sent.
 */

// Load Dolibarr environment
$res = 0;
// Try main.inc.php into web root known defined into CONTEXT_DOCUMENT_ROOT (not always defined)
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
// Try main.inc.php into web root detected using web root calculated from SCRIPT_FILENAME
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
	$i--;
	$j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1))."/main.inc.php")) {
	$res = @include substr($tmp, 0, ($i + 1))."/main.inc.php";
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php")) {
	$res = @include dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php";
}
// Try main.inc.php using relative path
if (!$res && file_exists("../main.inc.php")) {
	$res = @include "../main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res && file_exists("../../../../main.inc.php")) {
	$res = @include "../../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}
/**
 * The main.inc.php has been included so the following variable are now defined:
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */
include_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
include_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.facture.class.php';
dol_include_once('/einvoicing/class/einvoicing.class.php');
dol_include_once('/einvoicing/lib/xmlhighlight.lib.php');

// Load translation files required by the page
$langs->loadLangs(array("einvoicing@einvoicing", "bills", "suppliers", "other"));

// Get parameters
$id = GETPOSTINT('id');
// 'raw' returns the XML alone, without the menus, so it can be embedded in the preview dialog of the
// core the same way a PDF is. Asked without it, the page is a normal page of the application.
$mode = GETPOST('mode', 'aZ09');
// 'supplier' reads the document received for a supplier invoice, otherwise the one sent to a customer
$element = GETPOST('element', 'aZ09');

$issupplier = ($element == 'supplier') ? 1 : 0;
$object = $issupplier ? new FactureFournisseur($db) : new Facture($db);
if ($id <= 0 || $object->fetch($id) <= 0) {
	accessforbidden($langs->trans("ErrorRecordNotFound"));
}

// Security check: the file belongs to the invoice, so reading it needs the right to read the invoice
if ($issupplier) {
	$result = restrictedArea($user, 'fournisseur', $object->id, 'facture_fourn', 'facture', 'fk_soc', 'rowid');
} else {
	$result = restrictedArea($user, 'facture', $object->id, '');
}


/*
 * Actions
 */

// This page is read only, it has no action.


/*
 * View
 */

$einvoicing = new EInvoicing($db);

$einvoicefile = $issupplier
	? $einvoicing->getSupplierEInvoiceXmlFilePath($object)
	: $einvoicing->getEInvoiceXmlFilePath($object->ref);
$xml = empty($einvoicefile) ? '' : (string) file_get_contents($einvoicefile);

// A document received from an access point usually arrives on a single line, which no one can read.
// It is reindented for the display in that case only: a file that already carries its own line breaks
// - what this module generates does - is shown exactly as it is on disk.
// LIBXML_NONET because the document comes from outside: the parser must not go and fetch anything.
$reformatted = 0;
if ($xml !== '' && substr_count($xml, "\n") <= 2) {
	$previous = libxml_use_internal_errors(true);
	$dom = new DOMDocument();
	$dom->preserveWhiteSpace = false;
	$dom->formatOutput = true;
	if ($dom->loadXML($xml, LIBXML_NONET)) {
		$indented = $dom->saveXML();
		if (!empty($indented)) {
			$xml = $indented;
			$reformatted = 1;
		}
	}
	libxml_clear_errors();
	libxml_use_internal_errors($previous);
}

$title = $langs->trans("EInvoiceXmlPreviewTitle");

// What the page has to say, whatever the mode it is asked in. einvoicingXmlSourceToHtml() escapes
// every token with htmlspecialchars() and writes the only tags of the result itself, so nothing of the
// document can act as markup. Not dol_escape_htmltag(): it drops tags, turns newlines into a literal
// backslash-n and decodes entities, where this page has to show the file exactly as it is on disk.
if (empty($einvoicefile)) {
	$body = '<div class="opacitymedium">'.$langs->trans($issupplier ? "EInvoiceNoReceivedFileToPreview" : "EInvoiceNoFileToPreview").'</div>';
} else {
	$body = '<div class="opacitymedium paddingbottom">'.dol_escape_htmltag(basename($einvoicefile));
	if ($reformatted) {
		$body .= ' &mdash; '.dol_escape_htmltag($langs->trans("EInvoiceXmlReindented"));
	}
	$body .= '</div>';
	$body .= '<pre class="xmlsource">'.einvoicingXmlSourceToHtml($xml).'</pre>';
	$body .= einvoicingXmlSourceCss(1, function_exists('getNonce') ? getNonce() : '');
}

if ($mode == 'raw') {
	// Embedded in the preview dialog of the core, which frames it in an <object>: no menu, no header,
	// and the scroll is the one of the frame.
	top_httphead('text/html');
	print '<!DOCTYPE html>'."\n".'<html><head><meta charset="UTF-8">';
	print '<title>'.dol_escape_htmltag($title).'</title>';
	print '<style>body { margin: 0; padding: 8px; font-family: monospace; font-size: 12px; background: #fff; }';
	print ' .opacitymedium { opacity: 0.6; padding-bottom: 8px; }</style>';
	print '</head><body>'.$body.'</body></html>';
} else {
	llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-einvoicing page-xmlpreview');

	$cardurl = $issupplier ? '/fourn/facture/card.php' : '/compta/facture/card.php';
	$linkback = '<a href="'.DOL_URL_ROOT.$cardurl.'?id='.((int) $object->id).'">';
	$linkback .= img_picto('', 'bill', 'class="pictofixedwidth"').dol_escape_htmltag($object->ref).'</a>';

	print load_fiche_titre($title, $linkback, 'bill');
	print $body;

	// End of page
	llxFooter();
}
$db->close();
