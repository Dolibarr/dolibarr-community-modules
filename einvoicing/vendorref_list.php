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
 *   	\file       vendorref_list.php
 *		\ingroup    einvoicing
 *		\brief      List of the vendor product references, i.e. the mappings used to import an e-invoice.
 *
 *		The mapping between a product of the vendor and a product of Dolibarr is stored as a vendor
 *		reference (llx_product_fournisseur_price), because it is the first criteria used by the matching
 *		when a supplier invoice is imported. Once saved, that link was only visible product by product,
 *		in the "Supplier prices" tab of each product. This page lists them all, and lets the user
 *		correct one without leaving the list: reassign a vendor reference to another Dolibarr product,
 *		or drop the mapping.
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
include_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';
include_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
include_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.product.class.php';
include_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
include_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
include_once __DIR__.'/lib/einvoicing_vendorref.lib.php';

// Load translation files required by the page
$langs->loadLangs(array("einvoicing@einvoicing", "products", "companies", "suppliers", "bills", "other"));

// Get parameters
$action = GETPOST('action', 'aZ09') ? GETPOST('action', 'aZ09') : 'view';
$massaction = GETPOST('massaction', 'alpha');
$confirm = GETPOST('confirm', 'alpha');
$cancel = GETPOST('cancel', 'alpha');
$toselect = GETPOST('toselect', 'array:int');
$optioncss = GETPOST('optioncss', 'aZ');
$contextpage = GETPOST('contextpage', 'aZ') ? GETPOST('contextpage', 'aZ') : 'einvoicingvendorreflist';

$rowid = GETPOSTINT('rowid');

$search_socid = GETPOSTINT('search_socid');
$search_ref_fourn = GETPOST('search_ref_fourn', 'alphanohtml');
$search_product = GETPOST('search_product', 'alphanohtml');

// Pagination
$limit = GETPOSTINT('limit') ? GETPOSTINT('limit') : $conf->liste_limit;
$sortfield = GETPOST('sortfield', 'aZ09comma');
$sortorder = GETPOST('sortorder', 'aZ09comma');
$page = GETPOSTISSET('pageplusone') ? (GETPOSTINT('pageplusone') - 1) : GETPOSTINT("page");
if (empty($page) || $page < 0 || GETPOST('button_search', 'alpha') || GETPOST('button_removefilter', 'alpha')) {
	$page = 0;
}
$offset = $limit * $page;
if (!$sortfield) {
	$sortfield = "pfp.ref_fourn";
}
if (!$sortorder) {
	$sortorder = "ASC";
}

$form = new Form($db);

$permissiontoread = $user->hasRight('einvoicing', 'read') && ($user->hasRight('produit', 'lire') || $user->hasRight('service', 'lire'));
$permissiontoadd = $user->hasRight('einvoicing', 'write') && $user->hasRight('produit', 'creer');

if (!isModEnabled('einvoicing')) {
	accessforbidden();
}
if (!$permissiontoread) {
	accessforbidden();
}


/*
 * Actions
 */

if (GETPOST('button_removefilter_x', 'alpha') || GETPOST('button_removefilter.x', 'alpha') || GETPOST('button_removefilter', 'alpha')) {
	$search_socid = 0;
	$search_ref_fourn = '';
	$search_product = '';
}

if ($cancel) {
	$action = 'view';
	$rowid = 0;
}

// Reassign a vendor reference to another product, and/or rename the reference
if ($action == 'savemapping' && $permissiontoadd && $rowid > 0) {
	$newprodid = GETPOSTINT('idprod_new');
	$newref = trim(GETPOST('new_ref_fourn', 'alphanohtml'));

	$oldpfp = new ProductFournisseur($db);
	$resfetch = $oldpfp->fetch_product_fournisseur_price($rowid);

	// A rename onto a reference already used by this vendor would silently drop the other mapping
	$conflictrowid = 0;
	if ($resfetch > 0 && $newref !== '' && $newref !== $oldpfp->ref_supplier) {
		$conflictrowid = einvoicingFindConflictingVendorRef($db, (int) $oldpfp->fourn_id, $newref, (float) $oldpfp->fourn_qty, $rowid);
	}

	if ($resfetch <= 0) {
		setEventMessages($langs->trans("ErrorRecordNotFound"), null, 'errors');
	} elseif ($newprodid <= 0) {
		setEventMessages($langs->trans("SelectAProductToMapTo"), null, 'errors');
	} elseif ($newref === '') {
		setEventMessages($langs->trans("ErrorFieldRequired", $langs->trans("VendorProductRef")), null, 'errors');
	} elseif ($conflictrowid > 0) {
		setEventMessages($langs->trans("VendorRefAlreadyMapped", $newref), null, 'errors');
	} else {
		$remaperror = '';
		if (einvoicingRemapVendorRef($db, $user, $rowid, $newprodid, $newref, $remaperror) < 0) {
			setEventMessages($remaperror == 'NotFound' || $remaperror == 'VendorNotFound' ? $langs->trans("ErrorRecordNotFound") : $remaperror, null, 'errors');
		} else {
			setEventMessages($langs->trans("VendorRefMappingUpdated", $newref), null, 'mesgs');
			$action = 'view';
			$rowid = 0;
		}
	}
	if ($action == 'savemapping') {
		// Something went wrong, stay on the line being edited
		$action = 'editmapping';
	}
}

// Delete one mapping
if ($action == 'confirm_delete' && $confirm == 'yes' && $permissiontoadd && $rowid > 0) {
	$pfp = new ProductFournisseur($db);
	if ($pfp->fetch_product_fournisseur_price($rowid) <= 0) {
		setEventMessages($langs->trans("ErrorRecordNotFound"), null, 'errors');
	} elseif ($pfp->remove_product_fournisseur_price($rowid) < 0) {
		setEventMessages($pfp->error, $pfp->errors, 'errors');
	} else {
		setEventMessages($langs->trans("VendorRefMappingDeleted", $pfp->ref_supplier), null, 'mesgs');
	}
	$action = 'view';
	$rowid = 0;
}

// Delete the selected mappings
if ($massaction == 'massdelete' && $confirm == 'yes' && $permissiontoadd && is_array($toselect) && count($toselect) > 0) {
	$nbdeleted = 0;
	foreach ($toselect as $torowid) {
		$pfp = new ProductFournisseur($db);
		if ($pfp->fetch_product_fournisseur_price((int) $torowid) <= 0) {
			continue;
		}
		if ($pfp->remove_product_fournisseur_price((int) $torowid) < 0) {
			setEventMessages($pfp->error, $pfp->errors, 'errors');
		} else {
			$nbdeleted++;
		}
	}
	if ($nbdeleted > 0) {
		setEventMessages($langs->trans("NbOfVendorRefMappingsDeleted", $nbdeleted), null, 'mesgs');
	}
	$massaction = '';
	$toselect = array();
}


/*
 * Load the mappings
 */

$sql = "SELECT pfp.rowid, pfp.fk_soc, pfp.fk_product, pfp.ref_fourn, pfp.desc_fourn,";
$sql .= " pfp.price, pfp.unitprice, pfp.quantity, pfp.tva_tx, pfp.datec, pfp.tms, pfp.fk_user,";
$sql .= " p.ref as product_ref, p.label as product_label, p.fk_product_type, p.tobuy, p.tosell,";
$sql .= " s.nom as socname";
$sql .= " FROM ".MAIN_DB_PREFIX."product_fournisseur_price as pfp";
$sql .= " INNER JOIN ".MAIN_DB_PREFIX."product as p ON p.rowid = pfp.fk_product";
$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."societe as s ON s.rowid = pfp.fk_soc AND s.entity IN (".getEntity('societe').")";
$sql .= " WHERE pfp.entity IN (".getEntity('productsupplierprice').")";
// A line without a vendor reference is a plain buying price, not a mapping: it can never resolve a line of an e-invoice.
$sql .= " AND pfp.ref_fourn IS NOT NULL AND pfp.ref_fourn <> ''";
if ($search_socid > 0) {
	$sql .= " AND pfp.fk_soc = ".((int) $search_socid);
}
if ($search_ref_fourn != '') {
	$sql .= natural_search('pfp.ref_fourn', $search_ref_fourn);
}
if ($search_product != '') {
	$sql .= natural_search(array('p.ref', 'p.label'), $search_product);
}

$nbtotalofrecords = '';
if (!getDolGlobalInt('MAIN_DISABLE_FULL_SCANLIST')) {
	$resql = $db->query($sql);
	$nbtotalofrecords = $resql ? $db->num_rows($resql) : 0;
	if (($page * $limit) > $nbtotalofrecords) {
		$page = 0;
		$offset = 0;
	}
}

$sql .= $db->order($sortfield, $sortorder);
$sql .= $db->plimit($limit + 1, $offset);

$resql = $db->query($sql);
if (!$resql) {
	dol_print_error($db);
	exit;
}
$num = $db->num_rows($resql);


/*
 * View
 */

$title = $langs->trans("MappedVendorRefs");
$help_url = '';

llxHeader('', $title, $help_url, '', 0, 0, '', '', '', 'mod-einvoicing page-vendorref_list');

$param = '';
if ($limit > 0 && $limit != $conf->liste_limit) {
	$param .= '&limit='.((int) $limit);
}
if ($search_socid > 0) {
	$param .= '&search_socid='.((int) $search_socid);
}
if ($search_ref_fourn != '') {
	$param .= '&search_ref_fourn='.urlencode($search_ref_fourn);
}
if ($search_product != '') {
	$param .= '&search_product='.urlencode($search_product);
}

$newcardbutton = '';
if (getDolGlobalString('EINVOICING_SHOW_MAPPING_TOOL_ON_VENDOR_PRICE_LIST')) {	// Hidden option because editing mapping outside of an import process is discouraged.
	$newcardbutton = dolGetButtonTitle($langs->trans("MapEInvoiceProducts"), '', 'fa fa-link', dol_buildpath('/einvoicing/product_mapping.php', 1), '', ($permissiontoadd ? 1 : 0));
}

// Confirmations are printed before the search form: a form cannot be nested into another one
if ($action == 'delete' && $permissiontoadd && $rowid > 0) {
	print $form->formconfirm($_SERVER["PHP_SELF"].'?rowid='.((int) $rowid).$param, $langs->trans("DeleteVendorRefMapping"), $langs->trans("ConfirmDeleteVendorRefMapping"), 'confirm_delete', '', 0, 1);
}
if ($massaction == 'massdelete' && $permissiontoadd && count($toselect) > 0) {
	// Same as formconfirm, but it has to carry the ids selected into the list
	print '<form method="POST" action="'.$_SERVER["PHP_SELF"].$param.'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="massaction" value="massdelete">';
	print '<input type="hidden" name="confirm" value="yes">';
	foreach ($toselect as $selected) {
		print '<input type="hidden" name="toselect[]" value="'.((int) $selected).'">';
	}
	print '<div class="warning">';
	print $langs->trans("ConfirmDeleteSelectedVendorRefMappings", count($toselect)).'<br>';
	print '<input type="submit" class="button small" value="'.$langs->trans("Yes").'">';
	print ' <a class="button button-cancel small" href="'.$_SERVER["PHP_SELF"].'?'.ltrim($param, '&').'">'.$langs->trans("No").'</a>';
	print '</div>';
	print '</form>';
	print '<br>';
}

print '<form method="POST" id="searchFormList" action="'.$_SERVER["PHP_SELF"].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="contextpage" value="'.dol_escape_htmltag($contextpage).'">';
print '<input type="hidden" name="sortfield" value="'.dol_escape_htmltag($sortfield).'">';
print '<input type="hidden" name="sortorder" value="'.dol_escape_htmltag($sortorder).'">';

print_barre_liste($title, $page, $_SERVER["PHP_SELF"], $param, $sortfield, $sortorder, '', $num, $nbtotalofrecords, 'einvoicing.png@einvoicing', 0, $newcardbutton, '', $limit);

print '<span class="opacitymedium">'.$langs->trans("MappedVendorRefsDesc");
print ' '.$langs->trans("MapEInvoiceProductsDesc2");
print '.</span>';
//print ' '.$form->textwithpicto('', $langs->trans("OnlyToBuyProductsAreListedHelp"), 1, 'help');


print '<br><br>';

print '<div class="div-table-responsive">';
print '<table class="tagtable nobottomiftotal liste">';

// Filter line
print '<tr class="liste_titre_filter">';
print '<td class="liste_titre">';
print $form->select_company($search_socid, 'search_socid', '(s.fournisseur:=:1)', '1', 0, 0, array(), 0, 'maxwidth200');
print '</td>';
print '<td class="liste_titre"><input type="text" class="flat maxwidth100" name="search_ref_fourn" value="'.dol_escape_htmltag($search_ref_fourn).'"></td>';
print '<td class="liste_titre"></td>';
print '<td class="liste_titre"><input type="text" class="flat maxwidth100" name="search_product" value="'.dol_escape_htmltag($search_product).'"></td>';
print '<td class="liste_titre"></td>';
print '<td class="liste_titre"></td>';
print '<td class="liste_titre"></td>';
print '<td class="liste_titre center">';
print '<div class="nowraponall">';
print $form->showFilterButtons();
print '</div>';
print '</td>';
print '</tr>';

// Title line
print '<tr class="liste_titre">';
print_liste_field_titre("Supplier", $_SERVER["PHP_SELF"], "s.nom", "", $param, "", $sortfield, $sortorder);
print_liste_field_titre("VendorProductRef", $_SERVER["PHP_SELF"], "pfp.ref_fourn", "", $param, "", $sortfield, $sortorder, '', 'VendorProductRefColumnHelp');
print_liste_field_titre("VendorProductLabel", $_SERVER["PHP_SELF"], "pfp.desc_fourn", "", $param, "", $sortfield, $sortorder, '', 'VendorProductLabelColumnHelp');
print_liste_field_titre("DolibarrProduct", $_SERVER["PHP_SELF"], "p.ref", "", $param, "", $sortfield, $sortorder, '', 'DolibarrProductColumnHelp');
print_liste_field_titre("BuyingPrice", $_SERVER["PHP_SELF"], "pfp.price", "", $param, "", $sortfield, $sortorder, 'right ');
print_liste_field_titre("DateCreation", $_SERVER["PHP_SELF"], "pfp.datec", "", $param, "", $sortfield, $sortorder, 'center ');
print_liste_field_titre("DateModification", $_SERVER["PHP_SELF"], "pfp.tms", "", $param, "", $sortfield, $sortorder, 'center ');
print_liste_field_titre('', $_SERVER["PHP_SELF"], "", '', '', '', $sortfield, $sortorder, 'center maxwidthsearch ');
print '</tr>';

$productstatic = new Product($db);
$societestatic = new Societe($db);
$userstatic = new User($db);

// An instance whose catalogue is sales only has nothing to offer in the selector. Count once, to be able
// to say it, rather than showing an empty combo the user cannot make sense of.
$nbproducttobuy = -1;
if ($action == 'editmapping') {
	$resqlnb = $db->query("SELECT COUNT(*) as nb FROM ".MAIN_DB_PREFIX."product WHERE entity IN (".getEntity('product').") AND tobuy = 1");
	if ($resqlnb) {
		$objnb = $db->fetch_object($resqlnb);
		$nbproducttobuy = (int) $objnb->nb;
	}
}

$i = 0;
$totalarray = array();
while ($i < min($num, $limit)) {
	$obj = $db->fetch_object($resql);
	if (empty($obj)) {
		break;
	}

	$iseditedline = ($action == 'editmapping' && $rowid == $obj->rowid && $permissiontoadd);
	// A mapping onto a product that is no longer flagged "to buy" is an inconsistent state: the product
	// cannot be picked in the selector anymore, so the line has to say so instead of looking normal.
	$productnottobuy = empty($obj->tobuy);

	$productstatic->id = $obj->fk_product;
	$productstatic->ref = $obj->product_ref;
	$productstatic->label = $obj->product_label;
	$productstatic->type = $obj->fk_product_type;
	$productstatic->status = $obj->tosell;
	$productstatic->status_buy = $obj->tobuy;

	print '<tr class="oddeven">';

	// Supplier
	print '<td class="tdoverflowmax150">';
	if ($obj->fk_soc > 0 && $obj->socname !== null) {
		$societestatic->id = $obj->fk_soc;
		$societestatic->name = $obj->socname;
		print $societestatic->getNomUrl(1, 'supplier');
	} else {
		// The vendor was deleted but its buying prices were kept
		print $form->textwithpicto('<span class="opacitymedium">'.$langs->trans("VendorDeleted").'</span>', $langs->trans("VendorDeletedHelp"), 1, 'warning');
	}
	print '</td>';

	if ($iseditedline) {
		// The line is being edited: the reference and the target product become editable
		print '<td>';
		print '<input type="hidden" name="rowid" value="'.((int) $obj->rowid).'">';
		print '<input type="text" class="flat maxwidth150" name="new_ref_fourn" value="'.dol_escape_htmltag($obj->ref_fourn).'">';
		print '</td>';
		print '<td class="tdoverflowmax200">'.dol_escape_htmltag(dol_trunc((string) $obj->desc_fourn, 40)).'</td>';
		print '<td>';
		if ($productnottobuy) {
			// The mapped product is filtered out of the selector, so name it here and ask for a decision,
			// instead of letting the user wonder why the combo does not show what the line displays.
			print '<div class="opacitymedium">'.$langs->trans("CurrentlyMappedTo").' '.$productstatic->getNomUrl(1).' '.$productstatic->getLibStatut(5, 1).'</div>';
			print '<div class="warning">'.$langs->trans("MappedProductNoLongerToBuyHelp").'</div>';
		}
		if ($nbproducttobuy === 0) {
			print '<div class="warning">'.$langs->trans("NoProductToBuyAtAllHelp").'</div>';
		}
		// A vendor reference is bought, not sold: filter on the purchase status (tobuy), and accept a
		// product that is not on sale, which is exactly what a product created by an import looks like.
		// A product that is not "to buy" is not offered, hence the notice above when the mapped one is not.
		print $form->select_produits(($productnottobuy ? 0 : $obj->fk_product), 'idprod_new', '', 0, 0, -1, 2, '', 0, array(), 0, '1', 0, 'maxwidth300', 0, '', null, 0, 1);
		print '</td>';
	} else {
		// Vendor reference
		print '<td class="nowraponall">'.dol_escape_htmltag($obj->ref_fourn).'</td>';

		// Label used by the vendor on its own invoice
		print '<td class="tdoverflowmax200" title="'.dol_escape_htmltag((string) $obj->desc_fourn).'">'.dol_escape_htmltag(dol_trunc((string) $obj->desc_fourn, 40)).'</td>';

		// Dolibarr product
		print '<td class="tdoverflowmax200">';
		if ($productnottobuy) {
			print '<span class="opacitymedium">'.$productstatic->getNomUrl(1).'</span>';
			print ' '.$productstatic->getLibStatut(5, 1);
			print ' '.$form->textwithpicto('', $langs->trans("MappedProductNoLongerToBuy"), 1, 'warning');
		} else {
			print $productstatic->getNomUrl(1);
		}
		print ' <span class="opacitymedium">'.dol_escape_htmltag(dol_trunc((string) $obj->product_label, 24)).'</span>';
		print '</td>';
	}

	// Buying price
	print '<td class="right nowraponall">';
	print price($obj->price);
	if ($obj->quantity > 1) {
		print ' <span class="opacitymedium">/ '.price($obj->quantity).'</span>';
	}
	print '</td>';

	// Creation date
	print '<td class="center nowraponall">'.dol_print_date($db->jdate($obj->datec), 'day').'</td>';

	// Last change
	print '<td class="center nowraponall">';
	print dol_print_date($db->jdate($obj->tms), 'day');
	if ($obj->fk_user > 0) {
		$userstatic->fetch($obj->fk_user);
		print ' '.$userstatic->getNomUrl(-1);
	}
	print '</td>';

	// Actions
	print '<td class="center nowraponall">';
	if ($iseditedline) {
		// Named submit buttons, so the filter buttons of the list cannot trigger a save
		print '<button type="submit" class="button smallpaddingimp" name="action" value="savemapping">'.$langs->trans("Save").'</button>';
		print ' <button type="submit" class="button button-cancel smallpaddingimp" name="cancel" value="1">'.$langs->trans("Cancel").'</button>';
	} elseif ($permissiontoadd) {
		print '<a class="editfielda marginrightonly marginleftonly" href="'.$_SERVER["PHP_SELF"].'?action=editmapping&token='.newToken().'&rowid='.((int) $obj->rowid).$param.'" title="'.dol_escape_htmltag($langs->trans("RemapVendorRef")).'">'.img_edit().'</a>';
		print '<a class="marginrightonly marginleftonly" href="'.$_SERVER["PHP_SELF"].'?action=delete&token='.newToken().'&rowid='.((int) $obj->rowid).$param.'" title="'.dol_escape_htmltag($langs->trans("DeleteVendorRefMapping")).'">'.img_delete().'</a>';
		print '<input type="checkbox" class="flat marginrightonly marginleftonly checkforselect" name="toselect[]" value="'.((int) $obj->rowid).'"'.(in_array($obj->rowid, $toselect) ? ' checked' : '').'>';
	}
	print '</td>';

	print '</tr>';

	$i++;
}

if ($num == 0) {
	print '<tr class="oddeven"><td colspan="8"><span class="opacitymedium">';
	print $langs->trans(($search_socid > 0 || $search_ref_fourn != '' || $search_product != '') ? "NoRecordFound" : "NoVendorRefMappingYet");
	print '</span></td></tr>';
}

print '</table>';
print '</div>';

if ($permissiontoadd && $num > 0 && $action != 'editmapping') {
	print '<div class="right paddingtop">';
	print '<button type="submit" class="button small" name="massaction" value="massdelete">'.$langs->trans("DeleteSelectedVendorRefMappings").'</button>';
	print '</div>';
}

print '</form>';

$db->free($resql);

// End of page
llxFooter();
$db->close();
