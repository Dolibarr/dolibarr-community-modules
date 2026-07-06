<?php
/* Copyright (C) 2026		Gregory Aliot			<greg.aliot@gmail.com>
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
 *       \file       htdocs/einvoicing/ajax/checkdirectory.php
 *       \brief      Ajax endpoint: check a recipient reachability in the Approved Platforms directory
 */

if (!defined('NOTOKENRENEWAL')) {
	define('NOTOKENRENEWAL', 1); // Disables token renewal
}
if (!defined('NOREQUIREMENU')) {
	define('NOREQUIREMENU', '1');
}
if (!defined('NOREQUIREHTML')) {
	define('NOREQUIREHTML', '1');
}
if (!defined('NOREQUIREAJAX')) {
	define('NOREQUIREAJAX', '1');
}
if (!defined('NOREQUIRESOC')) {
	define('NOREQUIRESOC', '1');
}
if (!defined('NOCSRFCHECK')) {
	define('NOCSRFCHECK', '1');
}

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
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res && file_exists("../../../../main.inc.php")) {
	$res = @include "../../../../main.inc.php";
}
if (!$res && file_exists("../../../../../main.inc.php")) {
	$res = @include "../../../../../main.inc.php";
}
if (!$res) {
	http_response_code(500);
	die("Include of main fails");
}
/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

$objectRef = GETPOST('ref', 'alpha');	// 'alpha' like the invoice card (compta/facture/card.php): keeps refs with '/', '-', etc. from numbering masks. fetch() escapes it for SQL.

// Security check
if (!$user->hasRight('einvoicing', 'read')) {
	accessforbidden();
}

dol_syslog("Call ajax einvoicing/ajax/checkdirectory.php");
$langs->load('einvoicing@einvoicing');

top_httphead();

/**
 * Build a localized, ready-to-display HTML snippet from a directory result.
 *
 * @param array 	$r 		Result from AbstractPDPProvider::checkRecipientDirectory()
 * @param string 	$siren 	Recipient SIREN, for messages
 * @return string 			HTML snippet
 */
function einvoicing_directory_html($r, $siren)
{
	global $langs;
	$status = $r['status'] ?? 'error';
	switch ($status) {
		case 'routable':
			$txt = $langs->trans("EInvoicingDirectoryRoutable");
			if (!empty($r['identifier'])) {
				$txt .= ' <span class="opacitymedium small">('.dol_escape_htmltag($r['identifier']).')</span>';
			}
			return img_picto('', 'tick', 'class="color-green paddingright"').$txt;
		case 'absent':
			return img_picto('', 'error', 'class="color-red paddingright"').$langs->trans("EInvoicingDirectoryAbsent", $siren);
		case 'inactive':
			return img_picto('', 'warning', 'class="paddingright"').$langs->trans("EInvoicingDirectoryInactive", $siren);
		case 'unsupported':
			return '<span class="opacitymedium">'.img_picto('', 'info', 'class="paddingright"').$langs->trans("EInvoicingDirectoryUnsupported").'</span>';
		default:
			// Escape the provider/proxy error text: it is interpolated into an HTML snippet that the card
			// injects with .html(), so untrusted markup in an API error response must not be executable.
			$msg = dol_escape_htmltag(!empty($r['message']) ? $r['message'] : ('HTTP '.($r['httpcode'] ?? 0)));
			return img_picto('', 'error', 'class="color-red paddingright"').$langs->trans("EInvoicingDirectoryError", $msg);
	}
}

if (!$objectRef) {
	print json_encode(array('status' => 'error', 'html' => img_picto('', 'error').' '.$langs->trans("EInvoicingDirectoryError", 'no ref')));
	$db->close();
	exit;
}

require_once DOL_DOCUMENT_ROOT."/compta/facture/class/facture.class.php";
$invoice = new Facture($db);
$invoice->fetch(0, $objectRef);
if ($invoice->id <= 0) {
	print json_encode(array('status' => 'error', 'html' => img_picto('', 'error').' '.$langs->trans("EInvoicingDirectoryError", 'invoice '.$objectRef)));
	$db->close();
	exit;
}

// Authorize the fetched invoice with the standard invoice/third-party access rules: the e-invoicing
// read right alone must not expose an invoice (and its recipient data) the user cannot otherwise read.
restrictedArea($user, 'facture', $invoice->id, '', '', 'fk_soc', 'rowid');

$invoice->fetch_thirdparty();
$siren = is_object($invoice->thirdparty) ? preg_replace('/[^0-9]/', '', (string) $invoice->thirdparty->idprof1) : '';
if ($siren === '') {
	print json_encode(array(
		'status' => 'error',
		'reachable' => -1,
		'html' => img_picto('', 'warning', 'class="warning"').' '.$langs->trans("EInvoicingDirectoryNoSiren"),
	));
	$db->close();
	exit;
}

require_once "../class/providers/PDPProviderManager.class.php";
$PDPManager = new PDPProviderManager($db);
$provider = $PDPManager->getProvider(getDolGlobalString('EINVOICING_PDP'));
if (!is_object($provider)) {
	print json_encode(array('status' => 'error', 'html' => img_picto('', 'error').' '.$langs->trans("EInvoicingDirectoryError", 'no provider')));
	$db->close();
	exit;
}

$r = $provider->checkRecipientDirectory($siren);
$r['siren'] = $siren;
$r['html'] = einvoicing_directory_html($r, $siren);

print json_encode($r);

$db->close();
