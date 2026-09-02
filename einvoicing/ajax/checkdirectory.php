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
// NOREQUIRESOC is deliberately not defined here: this endpoint builds a PDPProviderManager, which reads
// $mysoc->country_code to decide the list of providers, so $mysoc must exist.
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
 * Localized provenance of a directory answer, when it carries one.
 *
 * Every status but the plain error one may come from somewhere else than the standardized directory
 * answer: the platform's own endpoint settling a missing line status, or that same endpoint read as a
 * fallback because the standardized call did not go through. The user compares the badge with the
 * annuaire consulted by hand, so that difference must be readable without opening the code.
 *
 * @param array 	$r 	Result from AbstractPDPProvider::checkRecipientDirectory()
 * @return string 		Escaped detail, empty when the answer carries no provenance
 */
function einvoicing_directory_provenance($r)
{
	global $langs;
	if (empty($r['message'])) {
		return '';
	}
	return dol_escape_htmltag($langs->trans($r['message'], (string) ($r['messageparam'] ?? '')));
}

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
			// Show where the green answer comes from: the address, its directory line status and the
			// platform type. A reachable badge with no provenance cannot be told from a fail-open one.
			$txt = $langs->trans("EInvoicingDirectoryRoutable");
			$details = array();
			if (!empty($r['identifier'])) {
				$details[] = dol_escape_htmltag($r['identifier']);
			}
			if (!empty($r['linestatus'])) {
				$details[] = $langs->trans("EInvoicingDirectoryLineStatus").': '.dol_escape_htmltag($r['linestatus']);
			}
			if (!empty($r['platform'])) {
				$details[] = $langs->trans("EInvoicingDirectoryPlatformType").': '.dol_escape_htmltag($r['platform']);
			}
			// Where the status was read, when it did not come from the standardized directory answer.
			if (($provenance = einvoicing_directory_provenance($r)) !== '') {
				$details[] = $provenance;
			}
			if (!empty($details)) {
				$txt .= ' <span class="opacitymedium small">('.implode(' - ', $details).')</span>';
			}
			return img_picto('', 'tick', 'class="color-green paddingright"').$txt;
		case 'unknownaddress':
			// The recipient may well be reachable at another address; this invoice is not addressed to
			// it. Saying "reachable" here, on the strength of a line the document does not carry, is
			// exactly the answer that lets a transmission leave for a rejection (fr:213). The address
			// is named so the user can compare it with the annuaire, and correct the routing record
			// rather than wonder which of the two the badge was talking about.
			return img_picto('', 'error', 'class="color-red paddingright"').$langs->trans("EInvoicingDirectoryAddressNotDeclared", (string) ($r['identifier'] ?? ''), $siren);
		case 'absent':
			return img_picto('', 'error', 'class="color-red paddingright"').$langs->trans("EInvoicingDirectoryAbsent", $siren);
		case 'inactive':
			// An address declared and not open yet is the common case: say so, with the effective date
			// when the platform gave one, instead of the flat "no active routing line". The standardized
			// search answer carries the status but no date, hence the two wordings.
			if (!empty($r['effectivedate'])) {
				$txt = $langs->trans("EInvoicingDirectoryUpcoming", $siren, dol_print_date((int) $r['effectivedate'], 'day'));
			} elseif (strtolower((string) ($r['linestatus'] ?? '')) === 'upcoming') {
				$txt = $langs->trans("EInvoicingDirectoryUpcomingNoDate", $siren);
			} else {
				$txt = $langs->trans("EInvoicingDirectoryInactive", $siren);
			}
			$details = array();
			if (!empty($r['linestatus'])) {
				$details[] = $langs->trans("EInvoicingDirectoryLineStatus").': '.dol_escape_htmltag($r['linestatus']);
			}
			if (($provenance = einvoicing_directory_provenance($r)) !== '') {
				$details[] = $provenance;
			}
			if (!empty($details)) {
				$txt .= ' <span class="opacitymedium small">('.implode(' - ', $details).')</span>';
			}
			return img_picto('', 'warning', 'class="paddingright"').$txt;
		case 'undetermined':
			// Neutral on purpose: a line exists but its status was not communicated, so the check fails
			// open without asserting anything. Green here is what let an undeliverable invoice be sent.
			// The provenance matters most here, and used to be the one thing this branch dropped: the
			// same wording is reached both when the standardized directory answered without a line
			// status and when it did not answer at all and the platform's own endpoint was read
			// instead. The first is the recipient's platform being terse, the second is a call
			// failing on this instance - two different problems, and only the message tells them
			// apart (issue #698).
			$txt = $langs->trans("EInvoicingDirectoryUndetermined", $siren);
			$details = array();
			if (!empty($r['identifier'])) {
				$details[] = dol_escape_htmltag($r['identifier']);
			}
			if (($provenance = einvoicing_directory_provenance($r)) !== '') {
				$details[] = $provenance;
			}
			if (!empty($details)) {
				$txt .= ' <span class="opacitymedium small">('.implode(' - ', $details).')</span>';
			}
			return '<span class="opacitymedium">'.img_picto('', 'info', 'class="paddingright"').$txt.'</span>';
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

// The badge must answer about the address this invoice is sent to, so it asks for it the same way the
// generation does: invoice-level override first, then the third-party default routing, then the SIREN.
// Reading only the SIREN told the user a recipient was reachable while the document went to a
// SIRET-suffixed address the check had never looked at.
require_once "../class/einvoicing.class.php";
$einvoicing = new EInvoicing($db);
$routingid = $einvoicing->getBuyerCommunicationURI($invoice->thirdparty, $invoice);

$r = $provider->checkRecipientDirectory($siren, $routingid);
$r['siren'] = $siren;
$r['routingid'] = $routingid;
$r['html'] = einvoicing_directory_html($r, $siren);

print json_encode($r);

$db->close();
