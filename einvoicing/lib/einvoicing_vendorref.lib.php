<?php
/* Copyright (C) 2026		Pierre Grasswill			<da.grumpf@gmail.com>
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
 * \file    einvoicing/lib/einvoicing_vendorref.lib.php
 * \ingroup einvoicing
 * \brief   Functions to maintain the vendor references used to map the products of an e-invoice.
 */

require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.product.class.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';


/**
 * Find another vendor reference that would collide with the one we are about to write.
 *
 * The table has a unique key on (ref_fourn, fk_soc, quantity, entity), and update_buyprice()
 * silently deletes the line holding that key before inserting its own, so a rename has to be
 * refused rather than dropping the mapping of another product without saying so.
 *
 * @param	DoliDB	$db			Database handler
 * @param	int		$socid		Vendor id
 * @param	string	$ref		Vendor reference to write
 * @param	float	$qty		Min quantity of the line
 * @param	int		$excluderowid	Line of product_fournisseur_price to ignore (the one being edited)
 * @return	int					Row id of the conflicting line, 0 if none
 */
function einvoicingFindConflictingVendorRef($db, $socid, $ref, $qty, $excluderowid)
{
	$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."product_fournisseur_price";
	$sql .= " WHERE entity IN (".getEntity('productsupplierprice').")";
	$sql .= " AND fk_soc = ".((int) $socid);
	$sql .= " AND ref_fourn = '".$db->escape($ref)."'";
	$sql .= " AND quantity = ".((float) $qty);
	$sql .= " AND rowid <> ".((int) $excluderowid);

	$resql = $db->query($sql);
	if (!$resql || $db->num_rows($resql) == 0) {
		return 0;
	}
	$obj = $db->fetch_object($resql);

	return (int) $obj->rowid;
}

/**
 * Move a vendor reference onto another product, and/or rename it, keeping the buying conditions.
 *
 * Renaming in place is an update of the line. Moving is a delete followed by an insert, because
 * update_buyprice() only removes the previous line when the reference does not change: without the
 * explicit delete, a rename plus a move would leave the old mapping behind and the vendor reference
 * would resolve to two products.
 *
 * @param	DoliDB			$db			Database handler
 * @param	User			$user		User doing the change
 * @param	int				$rowid		Line of product_fournisseur_price to move
 * @param	int				$newprodid	Product the reference has to point to
 * @param	string			$newref		Vendor reference to write
 * @param	string			$error		Error message, filled when the return is negative
 * @return	int							Row id of the resulting line, <0 if KO
 */
function einvoicingRemapVendorRef($db, $user, $rowid, $newprodid, $newref, &$error)
{
	$error = '';

	$oldpfp = new ProductFournisseur($db);
	if ($oldpfp->fetch_product_fournisseur_price($rowid) <= 0) {
		$error = 'NotFound';
		return -1;
	}

	$supplier = new Societe($db);
	if ($supplier->fetch($oldpfp->fourn_id) <= 0) {
		$error = 'VendorNotFound';
		return -1;
	}

	$samemapping = ((int) $newprodid === (int) $oldpfp->product_id && $newref === $oldpfp->ref_supplier);

	$db->begin();

	// Free the unique key (vendor, reference, min quantity) before writing the new line
	if (!$samemapping && (int) $newprodid !== (int) $oldpfp->product_id) {
		if ($oldpfp->remove_product_fournisseur_price($rowid) < 0) {
			$error = $oldpfp->error;
			$db->rollback();
			return -2;
		}
	}

	$newpfp = new ProductFournisseur($db);
	$newpfp->id = (int) $newprodid;
	if ((int) $newprodid === (int) $oldpfp->product_id) {
		// The mapping stays on the same product: update the line, so its price history is kept
		$newpfp->product_fourn_price_id = $rowid;
	}

	$result = $newpfp->update_buyprice(
		$oldpfp->fourn_qty,
		(float) $oldpfp->fourn_price,
		$user,
		'HT',
		$supplier,
		$oldpfp->fk_availability,
		$newref,
		(float) $oldpfp->fourn_tva_tx,
		(float) $oldpfp->fourn_charges,
		(float) $oldpfp->fourn_remise_percent,
		(float) $oldpfp->fourn_remise,
		(int) $oldpfp->fourn_tva_npr,
		(int) $oldpfp->delivery_time_days,
		(string) $oldpfp->supplier_reputation,
		array(),
		(string) $oldpfp->default_vat_code,
		0,
		'HT',
		1,
		'',
		(string) $oldpfp->desc_supplier
	);

	if ($result < 0) {
		$error = $newpfp->error;
		$db->rollback();
		return -3;
	}

	$db->commit();

	return (int) $result;
}
