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
 *   	\file       product_mapping.php
 *		\ingroup    einvoicing
 *		\brief      Map the vendor product references of an incoming e-invoice onto existing Dolibarr products.
 *
 *		When the automatic creation of products is disabled, the synchronization of a supplier invoice stops
 *		on the first line whose product cannot be found. This page lists all the lines of the flow, shows
 *		which ones are already resolved, and lets the user link the unresolved ones to existing products.
 *		The link is stored as a vendor reference (llx_product_fournisseur_price), which is the first criteria
 *		used by the product matching, so the next synchronization will find the products by itself.
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
 * @var HookManager $hookmanager
 * @var Translate $langs
 * @var User $user
 */
include_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
include_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
include_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.product.class.php';
include_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
include_once __DIR__.'/class/providers/PDPProviderManager.class.php';
include_once __DIR__.'/class/protocols/ProtocolManager.class.php';
include_once __DIR__.'/class/document.class.php';

// Load translation files required by the page
$langs->loadLangs(array("einvoicing@einvoicing", "products", "companies", "bills", "other"));

// Get parameters
$action = GETPOST('action', 'aZ09');
$flowid = GETPOST('flowid', 'alphanohtml');
$socid = GETPOSTINT('socid');

$form = new Form($db);

$permissiontoread = $user->hasRight('einvoicing', 'read');
$permissiontoadd = $user->hasRight('einvoicing', 'write') && $user->hasRight('produit', 'creer');

if (!isModEnabled('einvoicing')) {
	accessforbidden();
}
if (!$permissiontoread) {
	accessforbidden();
}


/*
 * Load the lines of the flow
 */

$parsedLines = array();
$parsedHeader = array();
$loaderror = '';
$provider = null;
$protocol = null;

if (!empty($flowid)) {
	if (!getDolGlobalString('EINVOICING_PDP')) {
		$loaderror = $langs->trans("NoPDPDefined");
	} else {
		$providerManager = new PDPProviderManager($db);
		$provider = $providerManager->getProvider(getDolGlobalString('EINVOICING_PDP'));

		if (!is_object($provider)) {
			$loaderror = $langs->trans("FailedToGetProvider", getDolGlobalString('EINVOICING_PDP'));
		} else {
			// Get the invoice of the flow. We ask the 'Converted' document, like the synchronization does,
			// because the 'Original' one may use a syntax we are not able to parse (UBL...).
			$flowResponse = $provider->fetchFlowData($flowid, 'Converted', 'get_flow_for_product_mapping');

			if (empty($flowResponse['status_code']) || $flowResponse['status_code'] != 200) {
				$loaderror = $langs->trans("FailedToGetFlowContent", $flowid).(empty($flowResponse['errorMessage']) ? '' : ' - '.$flowResponse['errorMessage']);
			} else {
				$flowContent = $flowResponse['response'];

				$protocolManager = new ProtocolManager($db);
				$detectedProtocol = $protocolManager->detectProtocolFromContent($flowContent);
				$protocol = empty($detectedProtocol) ? null : $protocolManager->getProtocol($detectedProtocol);

				if (!is_object($protocol)) {
					$loaderror = $langs->trans("FailedToDetectProtocolOfFlow", $flowid);
				} else {
					// A Factur-X document is a PDF embedding the XML, so we may have to extract it first.
					$xmlData = method_exists($protocol, 'extractXmlFromFileContent') ? $protocol->extractXmlFromFileContent($flowContent) : $flowContent;

					// The extracted content is a pure XML (CII for a Factur-X PDF), so we may have to
					// switch to the protocol able to parse it.
					$detectedXmlProtocol = $protocolManager->detectProtocolFromContent($xmlData);
					if (!empty($detectedXmlProtocol) && $detectedXmlProtocol != $detectedProtocol) {
						$xmlProtocol = $protocolManager->getProtocol($detectedXmlProtocol);
						if (is_object($xmlProtocol)) {
							$protocol = $xmlProtocol;
						}
					}

					try {
						$parsedHeader = $protocol->parseInvoiceHeader($xmlData);
						$parsedLines = $protocol->parseInvoiceLines($xmlData);
					} catch (Exception $e) {
						$loaderror = $langs->trans("FailedToParseFlowContent", $flowid).' - '.$e->getMessage();
					}
				}
			}
		}
	}
}


/*
 * Actions
 */

if ($action == 'savemapping' && $permissiontoadd && !empty($parsedLines) && $socid > 0) {
	$supplier = new Societe($db);
	if ($supplier->fetch($socid) <= 0) {
		setEventMessages($langs->trans("ErrorRecordNotFound"), null, 'errors');
	} else {
		$nbdone = 0;
		$nberror = 0;

		foreach ($parsedLines as $idx => $parsedLine) {
			$idprod = GETPOSTINT('idprod_'.$idx);
			if ($idprod <= 0) {
				continue;
			}

			$reffourn = trim((string) ($parsedLine['prodsellerid'] ?? ''));
			if ($reffourn === '' || $reffourn === '0000') {
				// Without a vendor reference we have nothing to store the mapping on
				continue;
			}

			$productFourn = new ProductFournisseur($db);
			$productFourn->id = $idprod;
			$resultbuyprice = $productFourn->update_buyprice(
				1,															// qty min
				(float) ($parsedLine['netpriceamount'] ?? 0),				// unit price without tax
				$user,
				'HT',
				$supplier,
				0,															// availability
				$reffourn,													// vendor reference
				(float) ($parsedLine['rateApplicablePercent'] ?? 0)
			);

			if ($resultbuyprice < 0) {
				$nberror++;
				setEventMessages($langs->trans("FailedToMapProduct", $reffourn).' - '.$productFourn->error, null, 'errors');
			} else {
				$nbdone++;
			}
		}

		if ($nbdone > 0) {
			setEventMessages($langs->trans("NbOfProductsMapped", $nbdone), null, 'mesgs');
		}
		if (!$nbdone && !$nberror) {
			setEventMessages($langs->trans("NoProductMapped"), null, 'warnings');
		}

		// Reload the page to run the matching again on fresh data
		header("Location: ".$_SERVER["PHP_SELF"].'?flowid='.urlencode($flowid).'&socid='.((int) $socid));
		exit;
	}
}


/*
 * View
 */

$title = $langs->trans("MapEInvoiceProducts");
$help_url = '';

llxHeader('', $title, $help_url, '', 0, 0, '', '', '', 'mod-einvoicing page-product_mapping');

print load_fiche_titre($title, '', 'einvoicing.png@einvoicing');

print '<span class="opacitymedium">'.$langs->trans("MapEInvoiceProductsDesc").'</span><br><br>';

// Form to select the flow to work on (prefilled when we come from the synchronization result)
print '<form method="GET" action="'.$_SERVER["PHP_SELF"].'">';
print '<div class="inline-block valignmiddle paddingright">'.$langs->trans("flow_id").' ';
print '<input type="text" class="minwidth200" name="flowid" value="'.dol_escape_htmltag($flowid).'">';
print '</div>';
print '<div class="inline-block valignmiddle paddingright">'.$langs->trans("Supplier").' ';
print $form->select_company($socid, 'socid', '(s.fournisseur:=:1)', 'SelectThirdParty', 0, 0, array(), 0, 'minwidth200');
print '</div>';
print '<input type="submit" class="button small" value="'.$langs->trans("Refresh").'">';
print '</form>';

print '<br>';

if ($loaderror) {
	setEventMessages($loaderror, null, 'errors');
	print '<div class="error">'.dol_escape_htmltag($loaderror).'</div>';
}

if (!empty($parsedLines)) {
	if (empty($socid)) {
		print '<div class="warning">'.$langs->trans("SelectTheSupplierOfThisFlow").'</div>';
	}

	// Run the matching on each line (read only, nothing is created here)
	// The matching method comes from the CommonProtocol trait, used by the protocols able to import an invoice.
	'@phan-var-force ?CIIProtocol $protocol';
	$nbtomap = 0;
	$matchresults = array();
	foreach ($parsedLines as $idx => $parsedLine) {
		$parsedLine['supplierId'] = $socid;
		$matchresults[$idx] = ($socid > 0 && is_object($protocol)) ? $protocol->findProductFromEinvoiceLine($parsedLine) : array('res' => 0, 'message' => '');
		if (empty($matchresults[$idx]['res'])) {
			$nbtomap++;
		}
	}

	print '<div class="opacitymedium">';
	if (!empty($parsedHeader['documentno'])) {
		print $langs->trans("Invoice").' : '.dol_escape_htmltag($parsedHeader['documentno']).' - ';
	}
	print $langs->trans("NbOfLines").' : '.count($parsedLines).' - '.$langs->trans("NbOfLinesToMap").' : '.$nbtomap;
	print '</div><br>';

	print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="savemapping">';
	print '<input type="hidden" name="flowid" value="'.dol_escape_htmltag($flowid).'">';
	print '<input type="hidden" name="socid" value="'.((int) $socid).'">';

	print '<div class="div-table-responsive">';
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre">';
	print '<th>'.$langs->trans("Line").'</th>';
	print '<th>'.$langs->trans("VendorProductRef").'</th>';
	print '<th>'.$langs->trans("BuyerProductRef").'</th>';
	print '<th>'.$langs->trans("ProductGlobalId").'</th>';
	print '<th>'.$langs->trans("Label").'</th>';
	print '<th class="right">'.$langs->trans("Qty").'</th>';
	print '<th class="right">'.$langs->trans("PriceUHT").'</th>';
	print '<th class="right">'.$langs->trans("VATRate").'</th>';
	print '<th>'.$langs->trans("DolibarrProduct").'</th>';
	print '</tr>';

	foreach ($parsedLines as $idx => $parsedLine) {
		$reffourn = trim((string) ($parsedLine['prodsellerid'] ?? ''));

		print '<tr class="oddeven">';
		print '<td>'.dol_escape_htmltag((string) ($parsedLine['lineid'] ?? ($idx + 1))).'</td>';
		print '<td>'.dol_escape_htmltag($reffourn).'</td>';
		print '<td>'.dol_escape_htmltag((string) ($parsedLine['prodbuyerid'] ?? '')).'</td>';
		print '<td>'.dol_escape_htmltag((string) ($parsedLine['prodglobalid'] ?? '')).'</td>';
		print '<td>'.dol_escape_htmltag(dol_trunc((string) ($parsedLine['prodname'] ?? ''), 60)).'</td>';
		print '<td class="right">'.dol_escape_htmltag((string) ($parsedLine['billedquantity'] ?? '')).' '.dol_escape_htmltag((string) ($parsedLine['billedquantityunitcode'] ?? '')).'</td>';
		print '<td class="right">'.price((float) ($parsedLine['netpriceamount'] ?? 0)).'</td>';
		print '<td class="right">'.price((float) ($parsedLine['rateApplicablePercent'] ?? 0)).'%</td>';

		print '<td>';
		if (!empty($matchresults[$idx]['res'])) {
			// Line already resolved by the automatic matching
			$producttmp = new Product($db);
			if ($producttmp->fetch($matchresults[$idx]['res']) > 0) {
				print $producttmp->getNomUrl(1);
			} else {
				print '<span class="opacitymedium">'.$langs->trans("Product").' #'.((int) $matchresults[$idx]['res']).'</span>';
			}
			print ' '.$form->textwithpicto('', $matchresults[$idx]['message'], 1, 'help');
		} elseif ($socid <= 0) {
			print '<span class="opacitymedium">'.$langs->trans("SelectTheSupplierOfThisFlow").'</span>';
		} elseif ($reffourn === '' || $reffourn === '0000') {
			// Without a vendor reference, the mapping has nothing to be stored on
			print $form->textwithpicto('<span class="opacitymedium">'.$langs->trans("NotMappable").'</span>', $langs->trans("NotMappableBecauseNoVendorRef"), 1, 'warning');
		} elseif ($permissiontoadd) {
			// Filter on the purchase status (tobuy): the product of a supplier invoice line is bought, and a
			// product created by a previous import is not on sale, so filtering on tosell hides them all.
			print $form->select_produits(0, 'idprod_'.$idx, '', 0, 0, -1, 2, '', 0, array(), 0, '1', 0, 'maxwidth300', 0, '', null, 0, 1);
		} else {
			print '<span class="opacitymedium">'.$langs->trans("NotEnoughPermissions").'</span>';
		}
		print '</td>';

		print '</tr>';
	}

	print '</table>';
	print '</div>';

	print '<div class="center paddingtop">';
	if ($permissiontoadd && $socid > 0 && $nbtomap > 0) {
		print '<input type="submit" class="button" value="'.$langs->trans("SaveMappingAndCreateVendorRefs").'">';
	}
	print '<a class="button button-cancel" href="'.dol_buildpath('/einvoicing/document_list.php', 1).'">'.$langs->trans("BackToSynchronization").'</a>';
	print '</div>';

	print '</form>';

	if ($nbtomap == 0 && $socid > 0) {
		print '<br><div class="ok">'.$langs->trans("AllLinesOfFlowAreMapped").'</div>';
	}
} elseif (empty($loaderror) && !empty($flowid)) {
	print '<div class="warning">'.$langs->trans("NoLineFoundIntoThisFlow").'</div>';
}

// End of page
llxFooter();
$db->close();
