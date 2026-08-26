<?php
/* Copyright (C) 2026 Eric Seigne <eric.seigne@cap-rel.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    stancer/lib/stancer_repair.lib.php
 * \ingroup stancer
 * \brief   Detection and repair of Stancer customers wrongly shared between
 *          several Dolibarr thirdparties.
 *
 * Root cause (fixed in stancer_customer.lib.php, 2026-06): the API has no
 * 'mobile' filter, so the previous dedupe by mobile returned the first
 * customer of the account and attributed one single cust_xxx to many
 * unrelated socids. This file detects the rows that anomaly left behind and
 * repairs them:
 *   1. keep the cust_xxx on its real owner (the thirdparty whose email matches
 *      the Stancer customer email),
 *   2. for every other (intruder) thirdparty: create a distinct cust_xxx,
 *      delete the bogus societe_rib link, and relabel its payments to the new
 *      cust so the dedupe never re-glues the shared one.
 *
 * Detection is read-only. Repair is explicit, atomic and traced via ActionComm.
 */

dol_include_once('/stancer/class/stancer_api.class.php');

// ActionComm code recorded on each repaired thirdparty. Kept <= 50 chars.
define('STANCER_REPAIR_AC_DETACH', 'AC_STANCER_REPAIR_DETACH');

/**
 * SQL condition appended to EVERY societe_rib DELETE issued by this file.
 *
 * A type='ban' row is a real SEPA mandate: IBAN, BIC, RUM, mandate date, and a
 * PDF the customer has signed. Dolibarr lists the bank accounts of a thirdparty
 * with Societe::get_all_rib() ("type='ban' AND fk_soc=x") and cannot build a
 * direct debit order without one, so deleting such a row silently stops every
 * future withdrawal for that customer. No automated repair may cause that loss:
 * only card rows (real cards and the cust_xxx anchor placeholders) are droppable,
 * mandates are re-anchored (see below) or kept as they are and reported.
 *
 * NULL is matched explicitly: in SQL "type <> 'ban'" is unknown (hence false)
 * for a NULL type, which would let a typeless row escape the protection.
 */
define('STANCER_REPAIR_RIB_DELETABLE_SQL', " AND (type IS NULL OR type <> 'ban')");

/**
 * Normalise an email for comparison (trim + lowercase). Returns '' for empty.
 *
 * @param  string $email Raw email.
 * @return string        Normalised email.
 */
function stancerRepairNormaliseEmail($email)
{
	return strtolower(trim((string) $email));
}

/**
 * Find every Stancer customer (cust_xxx) that is linked to MORE THAN ONE
 * Dolibarr thirdparty. A link counts whether it comes from
 * llx_societe_rib.stancer_account (persistent payment mode) or from
 * llx_stancer_stancer_payments.customer (historical payment).
 *
 * @param  DoliDB $db Database handle.
 * @return string[]   Customer ids shared by >= 2 socids (may be empty).
 *                    Returns array() on SQL error (logged).
 */
function stancerFindSharedCustomerIds($db)
{
	// societe_rib is the authority and is NOT rewritten by the refresh, so a
	// link there always counts. A payment link only counts when the thirdparty
	// is not already anchored to a DIFFERENT cust in societe_rib: once a tiers
	// has been separated (its rib points to its own cust), the residual API
	// customer left on its payments (fillDataFromApi keeps the upstream value)
	// must NOT make the shared cust look shared again.
	$sql = "SELECT customer FROM (";
	$sql .= " SELECT stancer_account AS customer, fk_soc FROM " . MAIN_DB_PREFIX . "societe_rib";
	$sql .= "  WHERE stancer_account IS NOT NULL AND stancer_account <> '' AND fk_soc > 0";
	$sql .= " UNION ALL";
	$sql .= " SELECT sp.customer AS customer, sp.fk_soc FROM " . MAIN_DB_PREFIX . "stancer_stancer_payments sp";
	$sql .= "  WHERE sp.customer IS NOT NULL AND sp.customer <> '' AND sp.fk_soc > 0";
	$sql .= "  AND NOT EXISTS (SELECT 1 FROM " . MAIN_DB_PREFIX . "societe_rib r";
	$sql .= "    WHERE r.fk_soc = sp.fk_soc AND r.stancer_account <> '' AND r.stancer_account <> sp.customer)";
	$sql .= ") u";
	$sql .= " GROUP BY customer";
	$sql .= " HAVING COUNT(DISTINCT fk_soc) > 1";
	$sql .= " ORDER BY customer";

	$res = $db->query($sql);
	if (!$res) {
		dol_syslog("stancerFindSharedCustomerIds SQL error: " . $db->lasterror(), LOG_ERR);
		return array();
	}
	$ids = array();
	while (($row = $db->fetch_object($res))) {
		$ids[] = (string) $row->customer;
	}
	$db->free($res);
	return $ids;
}

/**
 * Build the per-thirdparty detail for one shared customer: which socids are
 * linked, through which source, with payment counts/amounts.
 *
 * The @return array shape is deliberately kept on a single physical line: a
 * multi-line shape is not parsed by static analysers and degrades to plain array.
 *
 * @param  string $customerID Stancer customer id (cust_xxx).
 * @param  DoliDB $db         Database handle.
 *                            rib_rowids lists every societe_rib link; rib_ban_rowids is the subset that
 *                            carries a SEPA mandate (type='ban') and is therefore never deletable.
 * @return array{customer:string,socids:array<int,array{socid:int,name:string,email:string,in_rib:bool,rib_rowids:int[],rib_ban_rowids:int[],nb_payments:int,amount_total:float,live_mode:int,first_pay:?string,last_pay:?string}>}
 */
function stancerGetSharedCustomerDetail($customerID, $db)
{
	$customerID = (string) $customerID;
	$detail = array('customer' => $customerID, 'socids' => array());
	if ($customerID === '') {
		return $detail;
	}
	$sanitizedCustomerId = $db->escape($customerID);

	$ensureSocid = function ($socid) use (&$detail) {
		$socid = (int) $socid;
		if (!isset($detail['socids'][$socid])) {
			$detail['socids'][$socid] = array(
				'socid'          => $socid,
				'name'           => '',
				'email'          => '',
				'in_rib'         => false,
				'rib_rowids'     => array(),
				'rib_ban_rowids' => array(),
				'nb_payments'    => 0,
				'amount_total'   => 0.0,
				'live_mode'      => 0,
				'first_pay'      => null,
				'last_pay'       => null,
			);
		}
		return $socid;
	};

	// Links via societe_rib. The type is read so the caller can tell a droppable
	// card link from a SEPA mandate, which must survive any repair.
	$sqlRib = "SELECT rowid, fk_soc, type FROM " . MAIN_DB_PREFIX . "societe_rib";
	$sqlRib .= " WHERE stancer_account = '" . $sanitizedCustomerId . "' AND fk_soc > 0";
	$resRib = $db->query($sqlRib);
	if ($resRib) {
		while (($row = $db->fetch_object($resRib))) {
			$socid = $ensureSocid($row->fk_soc);
			$detail['socids'][$socid]['in_rib'] = true;
			$detail['socids'][$socid]['rib_rowids'][] = (int) $row->rowid;
			if ((string) $row->type === 'ban') {
				$detail['socids'][$socid]['rib_ban_rowids'][] = (int) $row->rowid;
			}
		}
		$db->free($resRib);
	}

	// Links via historical payments (aggregated per socid). Same hardening as
	// stancerFindSharedCustomerIds(): skip a socid already anchored to a
	// different cust in societe_rib (already separated, residual API value).
	$sqlPay = "SELECT sp.fk_soc, COUNT(*) AS nb, COALESCE(SUM(sp.amount),0) AS amt,";
	$sqlPay .= " MIN(sp.date_creation) AS first_pay, MAX(sp.date_creation) AS last_pay, MAX(sp.live_mode) AS live_mode";
	$sqlPay .= " FROM " . MAIN_DB_PREFIX . "stancer_stancer_payments sp";
	$sqlPay .= " WHERE sp.customer = '" . $sanitizedCustomerId . "' AND sp.fk_soc > 0";
	$sqlPay .= " AND NOT EXISTS (SELECT 1 FROM " . MAIN_DB_PREFIX . "societe_rib r";
	$sqlPay .= "   WHERE r.fk_soc = sp.fk_soc AND r.stancer_account <> '' AND r.stancer_account <> '" . $sanitizedCustomerId . "')";
	$sqlPay .= " GROUP BY sp.fk_soc";
	$resPay = $db->query($sqlPay);
	if ($resPay) {
		while (($row = $db->fetch_object($resPay))) {
			$socid = $ensureSocid($row->fk_soc);
			$detail['socids'][$socid]['nb_payments']  = (int) $row->nb;
			$detail['socids'][$socid]['amount_total'] = ((int) $row->amt) / 100; // cents -> main unit
			$detail['socids'][$socid]['live_mode']    = (int) $row->live_mode;
			$detail['socids'][$socid]['first_pay']    = $row->first_pay;
			$detail['socids'][$socid]['last_pay']     = $row->last_pay;
		}
		$db->free($resPay);
	}

	// Decorate with thirdparty name + email.
	if (!empty($detail['socids'])) {
		// Guarded by the !empty() above, so the IN () list is never empty.
		$sanitizedSocIds = implode(',', array_map('intval', array_keys($detail['socids'])));
		$sqlSoc = "SELECT rowid, nom, email FROM " . MAIN_DB_PREFIX . "societe WHERE rowid IN (" . $sanitizedSocIds . ")";
		$resSoc = $db->query($sqlSoc);
		if ($resSoc) {
			while (($row = $db->fetch_object($resSoc))) {
				$socid = (int) $row->rowid;
				if (isset($detail['socids'][$socid])) {
					$detail['socids'][$socid]['name']  = (string) $row->nom;
					$detail['socids'][$socid]['email'] = (string) $row->email;
				}
			}
			$db->free($resSoc);
		}
	}

	return $detail;
}

/**
 * Decide which thirdparty is the legitimate owner of a shared customer, using
 * the email returned by the Stancer API for that customer: the owner is the
 * single linked thirdparty whose email matches it.
 *
 * @param  array  $detail   Output of stancerGetSharedCustomerDetail().
 * @param  string $apiEmail Email of the customer as returned by the Stancer API.
 * @return array{owner_socid:int, confident:bool, reason:string}
 *         owner_socid > 0 and confident=true only when exactly one linked
 *         thirdparty matches the API email. Otherwise owner_socid=0.
 */
function stancerResolveSharedCustomerOwner($detail, $apiEmail)
{
	$needle = stancerRepairNormaliseEmail($apiEmail);
	if ($needle === '') {
		return array('owner_socid' => 0, 'confident' => false,
			'reason' => 'Stancer API returned no email for this customer');
	}
	$matches = array();
	foreach ($detail['socids'] as $socid => $info) {
		if (stancerRepairNormaliseEmail($info['email']) === $needle) {
			$matches[] = (int) $socid;
		}
	}
	if (count($matches) === 1) {
		return array('owner_socid' => $matches[0], 'confident' => true,
			'reason' => 'Exactly one linked thirdparty matches the Stancer customer email');
	}
	if (count($matches) === 0) {
		return array('owner_socid' => 0, 'confident' => false,
			'reason' => 'No linked thirdparty email matches the Stancer customer email (' . $apiEmail . ')');
	}
	return array('owner_socid' => 0, 'confident' => false,
		'reason' => 'Several linked thirdparties share the Stancer customer email (' . implode(',', $matches) . ')');
}

/**
 * Create a fresh, distinct Stancer customer for a thirdparty (no dedupe: we
 * explicitly WANT a new cust_xxx so it stops sharing the old one).
 *
 * @param  Societe    $soc        Thirdparty.
 * @param  StancerApi $stancerApi API client.
 * @return array{id:string, error:string}  id set on success, error otherwise.
 */
function stancerRepairCreateDistinctCustomer($soc, $stancerApi)
{
	dol_include_once('/stancer/lib/stancer_customer.lib.php');

	$name = stancerFilterSocName((string) $soc->name);
	$data = array('name' => $name);
	if (!empty($soc->email)) {
		$data['email'] = $soc->email;
	}
	if (!empty($soc->phone) && substr($soc->phone, 0, 1) === '+') {
		$data['mobile'] = $soc->phone;
	}
	if (empty($data['email']) && empty($data['mobile'])) {
		return array('id' => '', 'error' => 'Thirdparty has neither email nor +mobile, cannot create a Stancer customer');
	}

	// Shared helper: retries without the mobile if Stancer 422s on it.
	$resp = stancerCreateCustomerWithFallback($data, $stancerApi);
	if ($resp !== false && isset($resp['id'])) {
		return array('id' => (string) $resp['id'], 'error' => '');
	}
	return array('id' => '', 'error' => 'Stancer API createCustomer failed: ' . $stancerApi->error
		. ' (HTTP ' . (int) $stancerApi->lastHttpCode . ')');
}

/**
 * Record an ActionComm on a thirdparty to trace a repair step. Returns true on
 * success. Caller is responsible for the surrounding transaction.
 *
 * @param  DoliDB $db          Database handle.
 * @param  User   $user        Current user.
 * @param  int    $socid       Thirdparty the event is attached to.
 * @param  string $label       Short event label.
 * @param  string $note        Private note (details).
 * @param  string $extraparams Free extraparams (truncated to 250).
 * @return bool                true if the ActionComm was created.
 */
function stancerRepairTraceDetach($db, $user, $socid, $label, $note, $extraparams)
{
	require_once DOL_DOCUMENT_ROOT . '/comm/action/class/actioncomm.class.php';
	$ac = new ActionComm($db);
	$ac->type_code    = 'AC_OTH_AUTO';
	$ac->code         = STANCER_REPAIR_AC_DETACH;
	$ac->label        = $label;
	$ac->note_private = $note;
	$ac->datep        = dol_now();
	$ac->datef        = dol_now();
	$ac->percentage   = -1;
	$ac->socid        = (int) $socid;
	$ac->authorid     = (int) (isset($user->id) ? $user->id : 0);
	$ac->userownerid  = (int) (isset($user->id) ? $user->id : 0);
	$ac->elementid    = (int) $socid;
	// @phan-suppress-next-line PhanDeprecatedProperty  ActionComm::create() only reads fk_element on Dolibarr 15..18, both fields must be fed
	$ac->fk_element   = (int) $socid;
	$ac->elementtype  = 'societe';
	$ac->extraparams  = dol_trunc($extraparams, 250);
	if ($ac->create($user) <= 0) {
		dol_syslog("stancerRepairTraceDetach: ActionComm create failed: " . $ac->error, LOG_ERR);
		return false;
	}
	return true;
}

/**
 * Repair one shared customer: keep it on $ownerSocid, detach every other
 * thirdparty by giving it its own distinct cust_xxx, deleting the bogus
 * societe_rib link, and relabeling its payments.
 *
 * When $dryRun is true, NOTHING is written nor sent to the API: the returned
 * plan describes exactly what would happen.
 *
 * Per intruder, the live operations are: createCustomer (API) THEN, in a single
 * DB transaction, delete societe_rib links + update stancer_payments.customer +
 * record an ActionComm. A failure on one intruder is isolated (logged, skipped)
 * and never rolls back the intruders already repaired.
 *
 * SEPA mandates (societe_rib.type='ban') are NEVER deleted, whatever the case:
 * when a distinct customer is created they are re-anchored to it (the mandate,
 * its IBAN and its RUM are untouched, only the cust_xxx changes), otherwise they
 * are left exactly as they are and reported in rib_preserved. A customer still
 * shared through a preserved mandate keeps being listed by the detection: that
 * is intended, a visible report is worth more than a destroyed mandate.
 *
 * The @return array shape is deliberately kept on a single physical line: a
 * multi-line shape is not parsed by static analysers and degrades to plain array.
 *
 * @param  string     $customerID Shared cust_xxx.
 * @param  int        $ownerSocid Thirdparty that legitimately owns the cust.
 * @param  DoliDB     $db         Database handle.
 * @param  User       $user       Current user (for ActionComm / audit trail).
 * @param  StancerApi $stancerApi API client.
 * @param  bool       $dryRun     If true, only simulate.
 * @return array{success:bool,customer:string,owner_socid:int,dry_run:bool,actions:array<int,array{socid:int,name:string,new_cust:string,rib_deleted:int,rib_preserved:int,payments_relinked:int,status:string,message:string}>,message:string}
 */
function stancerRepairSharedCustomer($customerID, $ownerSocid, $db, $user, $stancerApi, $dryRun = true)
{
	require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
	require_once DOL_DOCUMENT_ROOT . '/comm/action/class/actioncomm.class.php';

	$customerID = (string) $customerID;
	$ownerSocid = (int) $ownerSocid;
	$result = array(
		'success'     => false,
		'customer'    => $customerID,
		'owner_socid' => $ownerSocid,
		'dry_run'     => (bool) $dryRun,
		'actions'     => array(),
		'message'     => '',
	);

	if ($customerID === '' || $ownerSocid <= 0) {
		$result['message'] = 'Missing customer or owner socid';
		dol_syslog("stancerRepairSharedCustomer: " . $result['message'], LOG_ERR);
		return $result;
	}

	$detail = stancerGetSharedCustomerDetail($customerID, $db);
	if (!isset($detail['socids'][$ownerSocid])) {
		$result['message'] = "Owner socid=$ownerSocid is not linked to customer $customerID";
		dol_syslog("stancerRepairSharedCustomer: " . $result['message'], LOG_ERR);
		return $result;
	}

	$intruders = array();
	foreach (array_keys($detail['socids']) as $socid) {
		if ((int) $socid !== $ownerSocid) {
			$intruders[] = (int) $socid;
		}
	}
	if (empty($intruders)) {
		$result['success'] = true;
		$result['message'] = 'Nothing to repair: customer is linked to the owner only';
		return $result;
	}

	$sanitizedCust = $db->escape($customerID);

	foreach ($intruders as $socid) {
		$action = array(
			'socid'             => $socid,
			'name'              => isset($detail['socids'][$socid]['name']) ? $detail['socids'][$socid]['name'] : '',
			'new_cust'          => '',
			'rib_deleted'       => 0,
			'rib_preserved'     => 0,
			'payments_relinked' => 0,
			'status'            => 'pending',
			'message'           => '',
		);

		// Mandates (type='ban') are out of reach of every DELETE below, so the
		// planned/reported counts must not include them.
		$nbRibBan = count($detail['socids'][$socid]['rib_ban_rowids']);
		$nbRib = count($detail['socids'][$socid]['rib_rowids']) - $nbRibBan;
		$nbPay = (int) $detail['socids'][$socid]['nb_payments'];
		// Same information twice: action messages/logs are English, ActionComm
		// notes are read by the user in French.
		$mandateNoteEn = $nbRibBan > 0
			? " " . $nbRibBan . " SEPA mandate(s) left untouched (a repair never deletes one)."
			: '';
		$mandateNoteFr = $nbRibBan > 0
			? "\n" . $nbRibBan . " mandat(s) SEPA conservé(s) : la réparation n'en supprime jamais."
			: '';

		$soc = new Societe($db);
		$socExists = ($soc->fetch($socid) > 0);
		if ($socExists) {
			$action['name'] = (string) $soc->name;
		}

		// CASE A: the intruder thirdparty no longer exists (merged/deleted -
		// Dolibarr's merge reattaches societe_rib and invoices but NOT our custom
		// stancer_stancer_payments table, leaving orphan rows with a dead fk_soc).
		// The customer (cust_xxx) belongs to the owner, so those payments belong to
		// the owner too: reattach them to $ownerSocid and drop any orphan rib link.
		// No new customer is created (there is no thirdparty to create it for).
		if (!$socExists) {
			if ($dryRun) {
				$action['status']            = 'planned';
				$action['new_cust']          = '(merged/deleted thirdparty)';
				$action['rib_deleted']       = $nbRib;
				$action['rib_preserved']     = $nbRibBan;
				$action['payments_relinked'] = $nbPay;
				$action['message']           = "Thirdparty #$socid no longer exists (merged/deleted):";
				$action['message'] .= " would reattach $nbPay payment(s) to owner #$ownerSocid and delete $nbRib orphan link(s).";
				$action['message'] .= $mandateNoteEn;
				$result['actions'][] = $action;
				continue;
			}

			$db->begin();

			$sqlDel = "DELETE FROM " . MAIN_DB_PREFIX . "societe_rib";
			$sqlDel .= " WHERE fk_soc = " . (int) $socid . " AND stancer_account = '" . $sanitizedCust . "'";
			$sqlDel .= STANCER_REPAIR_RIB_DELETABLE_SQL;
			$resDel = $db->query($sqlDel);
			if (!$resDel) {
				$db->rollback();
				$action['status']  = 'error';
				$action['message'] = 'DELETE orphan societe_rib failed: ' . $db->lasterror();
				dol_syslog("stancerRepairSharedCustomer socid=$socid (merged): " . $action['message'], LOG_ERR);
				$result['actions'][] = $action;
				continue;
			}
			$action['rib_deleted']   = (int) $db->affected_rows($resDel);
			$action['rib_preserved'] = $nbRibBan;
			if ($nbRibBan > 0) {
				dol_syslog("stancerRepairSharedCustomer socid=$socid (merged): $nbRibBan SEPA mandate(s) kept (never deleted); customer $customerID stays reported as shared through them", LOG_WARNING);
			}

			// Reattach payments to the owner. customer stays $customerID: it really
			// belongs to the owner (resolved by email), only fk_soc was stale.
			$sqlUpd = "UPDATE " . MAIN_DB_PREFIX . "stancer_stancer_payments";
			$sqlUpd .= " SET fk_soc = " . (int) $ownerSocid;
			$sqlUpd .= " WHERE fk_soc = " . (int) $socid . " AND customer = '" . $sanitizedCust . "'";
			$resUpd = $db->query($sqlUpd);
			if (!$resUpd) {
				$db->rollback();
				$action['status']  = 'error';
				$action['message'] = 'UPDATE stancer_payments (reattach to owner) failed: ' . $db->lasterror();
				dol_syslog("stancerRepairSharedCustomer socid=$socid (merged): " . $action['message'], LOG_ERR);
				$result['actions'][] = $action;
				continue;
			}
			$action['payments_relinked'] = (int) $db->affected_rows($resUpd);

			// Trace on the SURVIVING owner thirdparty.
			$ac = new ActionComm($db);
			$ac->type_code    = 'AC_OTH_AUTO';
			$ac->code         = STANCER_REPAIR_AC_DETACH;
			$ac->label        = 'Stancer : paiements de tiers fusionné rattachés';
			$ac->note_private = "Le tiers id=$socid (fusionné ou supprimé) portait des paiements Stancer orphelins";
			$ac->note_private .= " du compte client $customerID.\n";
			$ac->note_private .= $action['payments_relinked'] . " paiement(s) ré-attribué(s) à ce tiers (propriétaire du compte), ";
			$ac->note_private .= $action['rib_deleted'] . " lien(s) societe_rib orphelin(s) supprimé(s).";
			$ac->note_private .= $mandateNoteFr;
			$ac->datep        = dol_now();
			$ac->datef        = dol_now();
			$ac->percentage   = -1;
			$ac->socid        = (int) $ownerSocid;
			$ac->authorid     = (int) $user->id;
			$ac->userownerid  = (int) $user->id;
			$ac->elementid    = (int) $ownerSocid;
			// @phan-suppress-next-line PhanDeprecatedProperty  ActionComm::create() only reads fk_element on Dolibarr 15..18, both fields must be fed
			$ac->fk_element   = (int) $ownerSocid;
			$ac->elementtype  = 'societe';
			$ac->extraparams  = dol_trunc($customerID . ' merged#' . $socid, 250);
			if ($ac->create($user) <= 0) {
				$db->rollback();
				$action['status']  = 'error';
				$action['message'] = 'ActionComm create failed: ' . $ac->error;
				dol_syslog("stancerRepairSharedCustomer socid=$socid (merged): " . $action['message'], LOG_ERR);
				$result['actions'][] = $action;
				continue;
			}

			$db->commit();
			$action['status']   = 'done';
			$action['new_cust'] = '(merged into owner #' . $ownerSocid . ')';
			$action['message']  = "Merged/deleted thirdparty #$socid: " . $action['payments_relinked'];
			$action['message'] .= " payment(s) reattached to owner #$ownerSocid, " . $action['rib_deleted'] . " orphan link(s) removed.";
			$action['message'] .= $mandateNoteEn;
			dol_syslog("stancerRepairSharedCustomer merged socid=$socid -> owner=$ownerSocid ("
				. $action['payments_relinked'] . " payments, " . $action['rib_deleted'] . " rib), by user=" . (int) $user->id, LOG_NOTICE);
			$result['actions'][] = $action;
			continue;
		}

		// CASE C: the intruder exists but is a GENERIC account with no email and
		// no +mobile (typically Dolibarr's "CLIENT DIVERS"). A Stancer customer
		// cannot be created for it, and such an account never legitimately owns a
		// cust_xxx. We do NOT move the payment (its invoice really is on that
		// generic account); we just clear the wrong customer label to break the
		// share. No API call.
		$hasContact = (!empty($soc->email) || (!empty($soc->phone) && substr($soc->phone, 0, 1) === '+'));
		if (!$hasContact) {
			if ($dryRun) {
				$action['status']            = 'planned';
				$action['new_cust']          = '(generic account: customer label would be cleared)';
				$action['rib_deleted']       = $nbRib;
				$action['rib_preserved']     = $nbRibBan;
				$action['payments_relinked'] = $nbPay;
				$action['message']           = "Generic account without email/+mobile: would clear the customer";
				$action['message'] .= " label on $nbPay payment(s) and delete $nbRib bogus rib link(s) (no Stancer customer created).";
				$action['message'] .= $mandateNoteEn;
				$result['actions'][] = $action;
				continue;
			}

			$db->begin();

			$sqlDel = "DELETE FROM " . MAIN_DB_PREFIX . "societe_rib";
			$sqlDel .= " WHERE fk_soc = " . (int) $socid . " AND stancer_account = '" . $sanitizedCust . "'";
			$sqlDel .= STANCER_REPAIR_RIB_DELETABLE_SQL;
			$resDel = $db->query($sqlDel);
			if (!$resDel) {
				$db->rollback();
				$action['status']  = 'error';
				$action['message'] = 'DELETE societe_rib failed: ' . $db->lasterror();
				dol_syslog("stancerRepairSharedCustomer socid=$socid (generic): " . $action['message'], LOG_ERR);
				$result['actions'][] = $action;
				continue;
			}
			$action['rib_deleted']   = (int) $db->affected_rows($resDel);
			$action['rib_preserved'] = $nbRibBan;
			if ($nbRibBan > 0) {
				// No Stancer customer can be created for a generic account, so the
				// mandate cannot be re-anchored either: it stays as it is.
				dol_syslog("stancerRepairSharedCustomer socid=$socid (generic): $nbRibBan SEPA mandate(s) kept (never deleted, no distinct customer to re-anchor them to)", LOG_WARNING);
			}

			// Clear the wrong customer label; keep fk_soc (the invoice link).
			$sqlUpd = "UPDATE " . MAIN_DB_PREFIX . "stancer_stancer_payments";
			$sqlUpd .= " SET customer = ''";
			$sqlUpd .= " WHERE fk_soc = " . (int) $socid . " AND customer = '" . $sanitizedCust . "'";
			$resUpd = $db->query($sqlUpd);
			if (!$resUpd) {
				$db->rollback();
				$action['status']  = 'error';
				$action['message'] = 'UPDATE stancer_payments (clear customer) failed: ' . $db->lasterror();
				dol_syslog("stancerRepairSharedCustomer socid=$socid (generic): " . $action['message'], LOG_ERR);
				$result['actions'][] = $action;
				continue;
			}
			$action['payments_relinked'] = (int) $db->affected_rows($resUpd);

			$ac = new ActionComm($db);
			$ac->type_code    = 'AC_OTH_AUTO';
			$ac->code         = STANCER_REPAIR_AC_DETACH;
			$ac->label        = 'Stancer : étiquette compte client nettoyée (compte générique)';
			$ac->note_private = "Ce tiers générique (sans e-mail ni mobile) portait à tort le compte client Stancer";
			$ac->note_private .= " partagé $customerID.\n";
			$ac->note_private .= "Aucun compte Stancer ne peut lui être créé : l'étiquette a été retirée de ";
			$ac->note_private .= $action['payments_relinked'] . " paiement(s) (la facture liée reste inchangée), ";
			$ac->note_private .= $action['rib_deleted'] . " lien(s) societe_rib supprimé(s).";
			$ac->note_private .= $mandateNoteFr;
			$ac->datep        = dol_now();
			$ac->datef        = dol_now();
			$ac->percentage   = -1;
			$ac->socid        = (int) $socid;
			$ac->authorid     = (int) $user->id;
			$ac->userownerid  = (int) $user->id;
			$ac->elementid    = (int) $socid;
			// @phan-suppress-next-line PhanDeprecatedProperty  ActionComm::create() only reads fk_element on Dolibarr 15..18, both fields must be fed
			$ac->fk_element   = (int) $socid;
			$ac->elementtype  = 'societe';
			$ac->extraparams  = dol_trunc($customerID . ' cleared#' . $socid, 250);
			if ($ac->create($user) <= 0) {
				$db->rollback();
				$action['status']  = 'error';
				$action['message'] = 'ActionComm create failed: ' . $ac->error;
				dol_syslog("stancerRepairSharedCustomer socid=$socid (generic): " . $action['message'], LOG_ERR);
				$result['actions'][] = $action;
				continue;
			}

			$db->commit();
			$action['status']   = 'done';
			$action['new_cust'] = '(generic account: label cleared)';
			$action['message']  = "Generic account #$socid: customer label cleared on " . $action['payments_relinked'];
			$action['message'] .= " payment(s), " . $action['rib_deleted'] . " bogus link(s) removed (no Stancer customer created).";
			$action['message'] .= $mandateNoteEn;
			dol_syslog("stancerRepairSharedCustomer generic socid=$socid cleared customer on "
				. $action['payments_relinked'] . " payments, by user=" . (int) $user->id, LOG_NOTICE);
			$result['actions'][] = $action;
			continue;
		}

		// CASE B: the intruder thirdparty still exists and has contact info.
		// Durability: fillDataFromApi() (refresh) rewrites the payments' customer
		// from the API, so relabeling payments does NOT stick. We anchor the
		// separation in societe_rib (Pass 1 of the dedupe, untouched by the
		// refresh) so the dedupe permanently resolves this tiers to its own cust.
		if ($nbPay <= 0) {
			// Only a bogus rib link, no payment: drop it. The next real payment
			// will create a clean distinct customer on its own.
			if ($dryRun) {
				$action['status']        = 'planned';
				$action['new_cust']      = '(no customer needed)';
				$action['rib_deleted']   = $nbRib;
				$action['rib_preserved'] = $nbRibBan;
				$action['message']       = "No payment: would delete $nbRib bogus rib link(s), no customer created.";
				$action['message'] .= $mandateNoteEn;
				$result['actions'][] = $action;
				continue;
			}
			$db->begin();
			$sqlDelNoPay = "DELETE FROM " . MAIN_DB_PREFIX . "societe_rib";
			$sqlDelNoPay .= " WHERE fk_soc = " . (int) $socid . " AND stancer_account = '" . $sanitizedCust . "'";
			$sqlDelNoPay .= STANCER_REPAIR_RIB_DELETABLE_SQL;
			$resDelNoPay = $db->query($sqlDelNoPay);
			if (!$resDelNoPay) {
				$db->rollback();
				$action['status']  = 'error';
				$action['message'] = 'DELETE societe_rib failed: ' . $db->lasterror();
				dol_syslog("stancerRepairSharedCustomer socid=$socid (no-pay): " . $action['message'], LOG_ERR);
				$result['actions'][] = $action;
				continue;
			}
			$action['rib_deleted']   = (int) $db->affected_rows($resDelNoPay);
			$action['rib_preserved'] = $nbRibBan;
			if ($nbRibBan > 0) {
				// A mandate with no payment yet is a legitimate mandate waiting for
				// its first withdrawal, not a bogus link: it stays.
				dol_syslog("stancerRepairSharedCustomer socid=$socid (no-pay): $nbRibBan SEPA mandate(s) kept (never deleted, no payment to justify a distinct customer)", LOG_WARNING);
			}
			if (!stancerRepairTraceDetach($db, $user, $socid,
				'Stancer : lien compte client partagé supprimé',
				"Lien societe_rib erroné vers le compte partagé $customerID supprimé (aucun paiement, aucun compte créé)."
					. $mandateNoteFr,
				$customerID . ' unlink#' . $socid)) {
				$db->rollback();
				$action['status']  = 'error';
				$action['message'] = 'ActionComm create failed';
				$result['actions'][] = $action;
				continue;
			}
			$db->commit();
			$action['status']   = 'done';
			$action['new_cust'] = '(none)';
			$action['message']  = "No payment: " . $action['rib_deleted'] . " bogus rib link(s) removed, no customer created.";
			$action['message'] .= $mandateNoteEn;
			dol_syslog("stancerRepairSharedCustomer no-pay socid=$socid removed " . $action['rib_deleted'] . " rib, by user=" . (int) $user->id, LOG_NOTICE);
			$result['actions'][] = $action;
			continue;
		}

		// nbPay > 0: create a distinct cust and ANCHOR it in societe_rib.
		if ($dryRun) {
			$action['status']            = 'planned';
			$action['new_cust']          = '(new distinct customer would be created and anchored)';
			$action['rib_deleted']       = $nbRib;
			$action['rib_preserved']     = $nbRibBan;
			$action['payments_relinked'] = $nbPay;
			$action['message']           = "Would create a distinct customer and anchor it in societe_rib (replacing $nbRib bogus link(s)), so its $nbPay payment(s) resolve to it durably."
				. ($nbRibBan > 0
					? " $nbRibBan SEPA mandate(s) would be re-anchored to the new customer, never deleted (IBAN, RUM and mandate date untouched)."
					: '');
			$result['actions'][] = $action;
			continue;
		}

		// Create the distinct customer (API call, OUTSIDE the DB transaction).
		$created = stancerRepairCreateDistinctCustomer($soc, $stancerApi);
		if ($created['id'] === '') {
			$action['status']  = 'error';
			$action['message'] = $created['error'];
			dol_syslog("stancerRepairSharedCustomer socid=$socid: " . $created['error'], LOG_ERR);
			$result['actions'][] = $action;
			continue;
		}
		$newCust = $created['id'];
		$action['new_cust'] = $newCust;

		// DB transaction: replace the bogus rib link(s) with an anchor link to
		// the new distinct cust, then trace.
		$db->begin();

		$sqlDel = "DELETE FROM " . MAIN_DB_PREFIX . "societe_rib";
		$sqlDel .= " WHERE fk_soc = " . (int) $socid;
		$sqlDel .= " AND stancer_account = '" . $sanitizedCust . "'";
		$sqlDel .= STANCER_REPAIR_RIB_DELETABLE_SQL;
		$resDel = $db->query($sqlDel);
		if (!$resDel) {
			$db->rollback();
			$action['status']  = 'error';
			$action['message'] = 'DELETE societe_rib failed: ' . $db->lasterror();
			dol_syslog("stancerRepairSharedCustomer socid=$socid: " . $action['message'], LOG_ERR);
			$result['actions'][] = $action;
			continue;
		}
		$action['rib_deleted'] = (int) $db->affected_rows($resDel);

		// A SEPA mandate of this thirdparty pointing at the shared cust is NOT
		// deleted: it is re-anchored to the distinct cust just created. The row
		// keeps its IBAN, BIC, RUM, mandate date and signed PDF - only the Stancer
		// customer changes, which is exactly what the separation is about. The
		// mandate itself (sepa_xxx) is not bound to a customer on the Stancer side,
		// so the next withdrawal works with the new cust and the same mandate.
		$ribReanchored = 0;
		if ($nbRibBan > 0) {
			$sqlReanchor = "UPDATE " . MAIN_DB_PREFIX . "societe_rib";
			$sqlReanchor .= " SET stancer_account = '" . $db->escape($newCust) . "'";
			$sqlReanchor .= " WHERE fk_soc = " . (int) $socid;
			$sqlReanchor .= " AND stancer_account = '" . $sanitizedCust . "'";
			$sqlReanchor .= " AND type = 'ban'";
			$resReanchor = $db->query($sqlReanchor);
			if (!$resReanchor) {
				$db->rollback();
				$action['status']  = 'error';
				$action['message'] = 'UPDATE societe_rib (re-anchor SEPA mandate) failed: ' . $db->lasterror();
				dol_syslog("stancerRepairSharedCustomer socid=$socid: " . $action['message'], LOG_ERR);
				$result['actions'][] = $action;
				continue;
			}
			$ribReanchored = (int) $db->affected_rows($resReanchor);
			$action['rib_preserved'] = $ribReanchored;
			dol_syslog("stancerRepairSharedCustomer socid=$socid: $ribReanchored SEPA mandate(s) re-anchored from $customerID to $newCust (not deleted)", LOG_NOTICE);
		}

		// Anchor the new distinct cust in societe_rib (placeholder card, no card
		// ref: the next real payment fills it in). This is what makes the
		// separation durable: Pass 1 of the dedupe resolves to it, and the refresh
		// never touches societe_rib.
		// Skipped when a mandate was just re-anchored: that row already carries the
		// new cust, and a second row would only duplicate the anchor.
		// Note: llx_societe_rib has NO 'entity' column (the RIB inherits its
		// thirdparty's entity), so we must not insert one here.
		if ($ribReanchored === 0) {
			$label = (getDolGlobalString('STANCER_IS_PROD', '0') === '1') ? 'stancer-card' : 'stancer-card-tst';
			$sqlIns = "INSERT INTO " . MAIN_DB_PREFIX . "societe_rib";
			$sqlIns .= " (fk_soc, label, type, stancer_account, default_rib, status, datec)";
			$sqlIns .= " VALUES (" . (int) $socid . ", '" . $db->escape($label) . "', 'card', '" . $db->escape($newCust) . "',";
			$sqlIns .= " 0, 1, '" . $db->idate(dol_now()) . "')";
			$resIns = $db->query($sqlIns);
			if (!$resIns) {
				$db->rollback();
				$action['status']  = 'error';
				$action['message'] = 'INSERT anchor societe_rib failed: ' . $db->lasterror();
				dol_syslog("stancerRepairSharedCustomer socid=$socid: " . $action['message'], LOG_ERR);
				$result['actions'][] = $action;
				continue;
			}
		}
		$action['payments_relinked'] = $nbPay;

		// Trace on the detached thirdparty.
		if (!stancerRepairTraceDetach($db, $user, $socid,
			'Stancer : détachement compte client partagé',
			"Compte client Stancer partagé $customerID (propriétaire : tiers id=$ownerSocid) détaché de ce tiers.\nNouveau compte distinct $newCust ancré dans societe_rib ; ses $nbPay paiement(s) s'y rattachent désormais."
				. ($ribReanchored > 0
					? "\n" . $ribReanchored . " mandat(s) SEPA ré-affecté(s) au nouveau compte client : l'IBAN, le RUM et la date de mandat sont inchangés, les prélèvements continuent."
					: ''),
			$customerID . '->' . $newCust)) {
			$db->rollback();
			$action['status']  = 'error';
			$action['message'] = 'ActionComm create failed';
			$result['actions'][] = $action;
			continue;
		}

		$db->commit();
		$action['status']  = 'done';
		$action['message'] = "Detached: distinct cust $newCust anchored in societe_rib, " . $action['rib_deleted']
			. " bogus link(s) replaced; its $nbPay payment(s) now resolve to it."
			. ($ribReanchored > 0
				? " $ribReanchored SEPA mandate(s) re-anchored to it (IBAN/RUM untouched), none deleted."
				: '');
		dol_syslog("stancerRepairSharedCustomer socid=$socid -> $newCust anchored (" . $action['rib_deleted']
			. " rib replaced, $nbPay payments), by user=" . (int) $user->id, LOG_NOTICE);
		$result['actions'][] = $action;
	}

	// Success = at least one intruder fully processed and no hard error remaining.
	$ok = 0;
	$err = 0;
	foreach ($result['actions'] as $a) {
		if ($a['status'] === 'done' || $a['status'] === 'planned') {
			$ok++;
		} elseif ($a['status'] === 'error') {
			$err++;
		}
	}
	$result['success'] = ($err === 0 && $ok > 0);
	$result['message'] = $dryRun
		? "Dry-run: $ok intruder(s) would be detached."
		: "$ok intruder(s) detached, $err error(s).";
	return $result;
}

/**
 * List Stancer payments that are paid on the Stancer side (authorized/captured/
 * capture_sent/to_capture) but have NO matching llx_paiement row in Dolibarr -
 * typically the ones the misattribution guard refused because the frozen
 * customer pointed to another socid. These are candidates for the supervised
 * force-post.
 *
 * @param  DoliDB    $db       Database handle.
 * @param  bool|null $liveMode null = both, true = live only, false = test only.
 * @param  int       $maxRows  Hard cap.
 * @return array<int, object>  Rows (rowid, stancer_id, amount, order_id, unique_id,
 *                            fk_soc, customer, date_creation, status). [] on SQL error.
 */
function stancerFindCapturedPaymentsNotPosted($db, $liveMode = null, $maxRows = 500)
{
	// Stancer_payments::STATUS_AUTHORIZED=1, CAPTURED=2, CAPTURE_SENT=3, TO_CAPTURE=8.
	$sqlPaidStatuses = '1,2,3,8';
	// Restrict to the current fiscal year: payments (hence their invoices) from a
	// previous, closed fiscal year are already booked / in the balance sheet and
	// must never be surfaced as doubles to act on. Cheap pre-filter on the payment
	// creation date; the exact per-invoice date guard is applied by the caller.
	$fiscalStart = dol_print_date(stancerGetFiscalYearStartTs(), '%Y-%m-%d');
	$sql = "SELECT sp.rowid, sp.stancer_id, sp.amount, sp.order_id, sp.unique_id, sp.grouped_invoice_ids, sp.fk_soc, sp.customer, sp.date_creation, sp.status, sp.live_mode, sp.tms FROM " . MAIN_DB_PREFIX . "stancer_stancer_payments sp WHERE sp.status IN (" . $sqlPaidStatuses . ") AND sp.stancer_id IS NOT NULL AND sp.stancer_id <> '' AND sp.date_creation >= '" . $db->escape($fiscalStart) . "'"
		// Identity-based "already posted" check: a Stancer payment is recorded as soon
		// as ANY Dolibarr paiement references its unique paym_xxx id, in num_paiement OR
		// ext_payment_id, whatever the ext_payment_site tag. This covers legacy rows
		// (empty/untagged site from the old command-path) and grouped postings, so they
		// are no longer surfaced as false "doubles".
		. " AND NOT EXISTS (SELECT 1 FROM " . MAIN_DB_PREFIX . "paiement p   WHERE p.num_paiement = sp.stancer_id OR p.ext_payment_id = sp.stancer_id)";
	if ($liveMode === true) {
		$sql .= " AND sp.live_mode = 1";
	} elseif ($liveMode === false) {
		$sql .= " AND sp.live_mode = 0";
	}
	$sql .= " ORDER BY sp.date_creation DESC LIMIT " . ((int) $maxRows);

	$res = $db->query($sql);
	if (!$res) {
		dol_syslog("stancerFindCapturedPaymentsNotPosted SQL error: " . $db->lasterror(), LOG_ERR);
		return array();
	}
	$rows = array();
	while (($row = $db->fetch_object($res))) {
		$rows[] = $row;
	}
	$db->free($res);
	return $rows;
}

/**
 * Map a Stancer API status string to the local Stancer_payments status int.
 *
 * @param  string $status API status (e.g. 'captured', 'refused').
 * @return int            Local status int, or -1 if unknown.
 */
function stancerApiStatusToInt($status)
{
	$map = array(
		'draft' => 0, 'authorized' => 1, 'captured' => 2, 'capture_sent' => 3,
		'disputed' => 4, 'expired' => 5, 'failed' => 6, 'refused' => 7,
		'to_capture' => 8, 'canceled' => 9,
	);
	return isset($map[(string) $status]) ? $map[(string) $status] : -1;
}

/**
 * Verify each candidate's REAL status against the Stancer API and split them
 * into really-paid (authorized/captured/capture_sent/to_capture) vs not-paid
 * (refused/canceled/expired/failed/... or API-unconfirmed). The LOCAL status can
 * be stale: a payment refused upstream but still flagged captured locally would
 * otherwise show up as a false "captured / double".
 *
 * Caching: a row whose tms is younger than $ttlSeconds is trusted as-is (no API
 * call), so refreshing the page within the TTL does not re-hit the API. When the
 * API IS called, the local status is re-synced (and tms touched), so a refused
 * payment drops out of the candidate set on the next scan (its local status is
 * no longer 1/2/3/8).
 *
 * Each row gets an added ->api_status. On a 401 the remaining rows are left
 * unconfirmed (not-paid).
 *
 * @param  array<int,object> $rows        Candidates from stancerFindCapturedPaymentsNotPosted().
 * @param  StancerApi        $stancerApi  API client.
 * @param  DoliDB|null       $db          DB handle (for re-sync); null disables re-sync.
 * @param  int               $ttlSeconds  Trust the local status if tms is younger than this.
 * @return array{paid: array<int,object>, notpaid: array<int,object>, auth_error: bool}
 */
function stancerSplitCapturedByApiStatus($rows, $stancerApi, $db = null, $ttlSeconds = 3600)
{
	$paid = array();
	$notpaid = array();
	$authError = false;
	$paidStatuses = array('authorized', 'captured', 'capture_sent', 'to_capture');
	$nowTs = dol_now();

	foreach ($rows as $row) {
		// Cache: if the row was synced recently, trust the local status and skip
		// the API. Candidates always have a paid local status (1/2/3/8), so a
		// fresh one is a real captured payment.
		$tmsTs = (isset($row->tms) && !empty($row->tms) && $db !== null) ? $db->jdate($row->tms) : 0;
		if ($tmsTs > 0 && $tmsTs >= ($nowTs - (int) $ttlSeconds)) {
			$row->api_status = '';
			$paid[] = $row;
			continue;
		}

		if ($authError) {
			// Stop hammering the API after a 401; treat the rest as unconfirmed.
			$row->api_status = '';
			$notpaid[] = $row;
			continue;
		}
		$api = $stancerApi->getPayment((string) $row->stancer_id);
		if ($api === false) {
			if ((int) $stancerApi->lastHttpCode === 401) {
				$authError = true;
			}
			dol_syslog("stancerSplitCapturedByApiStatus: cannot confirm status of " . $row->stancer_id
				. " (HTTP " . (int) $stancerApi->lastHttpCode . "), treating as not-confirmed", LOG_WARNING);
			$row->api_status = '';
			$notpaid[] = $row;
			continue;
		}
		$row->api_status = isset($api['status']) ? (string) $api['status'] : '';

		// Re-sync the local status (and touch tms) so a refused payment leaves the
		// candidate set next time, and so the TTL cache starts counting now.
		if ($db !== null && isset($row->rowid) && (int) $row->rowid > 0) {
			$statusInt = stancerApiStatusToInt($row->api_status);
			$set = "tms = '" . $db->idate($nowTs) . "'";
			if ($statusInt >= 0) {
				$set = "status = " . $statusInt . ", " . $set;
			} else {
				// Successful API call but empty/unknown status: the payment exists at
				// Stancer but is in no recognizable (let alone paid) state - a never
				// completed checkout. Hide it so it leaves the candidate set instead of
				// being re-trusted as a stale "captured" row by the TTL cache on the next
				// page load (which would otherwise keep flagging it as a false double).
				$set = "status = " . Stancer_payments::STATUS_HIDDEN . ", " . $set;
			}
			$db->query("UPDATE " . MAIN_DB_PREFIX . "stancer_stancer_payments SET " . $set
				. " WHERE rowid = " . (int) $row->rowid);
		}

		if (in_array($row->api_status, $paidStatuses, true)) {
			$paid[] = $row;
		} else {
			dol_syslog("stancerSplitCapturedByApiStatus: " . $row->stancer_id . " is '" . $row->api_status
				. "' on Stancer but flagged captured locally (stale local status, re-synced)", LOG_WARNING);
			$notpaid[] = $row;
		}
	}
	return array('paid' => $paid, 'notpaid' => $notpaid, 'auth_error' => $authError);
}

/**
 * Supervised force-post: create the Dolibarr Paiement for a Stancer payment that
 * the misattribution guard refused, by trusting its order_id (which directly ties
 * the payment to an invoice) over the frozen, possibly wrong, customer.
 *
 * Only Guard 2 (customer<->socid) is bypassed; Guard 1 (order_id must equal the
 * invoice ref) and Guard 3 (amount must not exceed remaining) are STILL enforced,
 * so a payment whose order_id does not designate the resolved invoice, or that
 * overpays it, is still refused. The operation is logged.
 *
 * @param  string     $paymId     Stancer payment id (paym_xxx).
 * @param  DoliDB     $db         Database handle.
 * @param  User       $user       Current user.
 * @param  StancerApi $stancerApi API client.
 * @param  bool       $allowOverpay When true, re-open the invoice if already paid
 *                                  and bypass the amount guard, to deliberately
 *                                  record a double payment (over-pay). Default false.
 * @return array{success:bool, paym_id:string, invoice_ref:string, invoice_id:int, message:string}
 */
function stancerForcePostPayment($paymId, $db, $user, $stancerApi, $allowOverpay = false)
{
	require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
	dol_include_once('/stancer/lib/stancer_bank.lib.php');

	$result = array('success' => false, 'paym_id' => (string) $paymId, 'invoice_ref' => '', 'invoice_id' => 0, 'message' => '');
	$paymId = (string) $paymId;
	if ($paymId === '') {
		$result['message'] = 'Missing paym_id';
		return $result;
	}

	// 1. Source of truth: the Stancer API.
	$api = $stancerApi->getPayment($paymId);
	if ($api === false) {
		$result['message'] = 'Stancer API error: ' . $stancerApi->error . ' (HTTP ' . (int) $stancerApi->lastHttpCode . ')';
		dol_syslog("stancerForcePostPayment $paymId: " . $result['message'], LOG_ERR);
		return $result;
	}
	$status = isset($api['status']) ? (string) $api['status'] : '';
	if (!in_array($status, array('authorized', 'captured', 'capture_sent', 'to_capture'), true)) {
		$result['message'] = "Stancer status '$status' is not a paid status; refusing to post.";
		dol_syslog("stancerForcePostPayment $paymId: " . $result['message'], LOG_WARNING);
		return $result;
	}
	$orderId = isset($api['order_id']) ? (string) $api['order_id'] : '';
	if ($orderId === '') {
		$result['message'] = 'Payment has no order_id; cannot resolve the target invoice safely.';
		dol_syslog("stancerForcePostPayment $paymId: " . $result['message'], LOG_WARNING);
		return $result;
	}

	// 2. Resolve the target invoice(s). order_id may be an invoice ref, a
	// commande/propal ref (invoice built at payment return), or several refs joined
	// by '+' for a grouped SEPA payment. The reliable list for a group is the local
	// mirror's grouped_invoice_ids column, so fetch it to drive the resolution.
	dol_include_once('/stancer/class/stancer_payments.class.php');
	$groupedIds = '';
	$sploc = new Stancer_payments($db);
	if ($sploc->fetch(0, null, $paymId) > 0) {
		$groupedIds = (string) $sploc->grouped_invoice_ids;
	}
	$pseudoRow = (object) array(
		'grouped_invoice_ids' => $groupedIds,
		'order_id'            => $orderId,
		'rowid'               => (int) (isset($sploc->id) ? $sploc->id : 0),
	);
	$invoices = stancerResolveInvoicesForPayment($pseudoRow, $db);
	if (empty($invoices)) {
		$result['message'] = "No Dolibarr invoice found for order_id='$orderId'.";
		dol_syslog("stancerForcePostPayment $paymId: " . $result['message'], LOG_WARNING);
		return $result;
	}

	// Common guards on EVERY resolved invoice: validated + not in a closed fiscal
	// year (already booked / in the balance sheet, must not be modified).
	$fiscalStartTs = stancerGetFiscalYearStartTs();
	foreach ($invoices as $inv) {
		// Facture::fetch() fills $status alongside the deprecated $statut on every
		// supported Dolibarr release (checked on 15.0.0 up to 21).
		if ((int) $inv->status < 1) {
			$result['invoice_ref'] = (string) $inv->ref;
			$result['invoice_id']  = (int) $inv->id;
			$result['message'] = "Target invoice $inv->ref is not validated; refusing to post.";
			dol_syslog("stancerForcePostPayment $paymId: " . $result['message'], LOG_WARNING);
			return $result;
		}
		if ((int) $inv->date > 0 && (int) $inv->date < $fiscalStartTs) {
			$result['invoice_ref'] = (string) $inv->ref;
			$result['invoice_id']  = (int) $inv->id;
			$result['message'] = "Target invoice $inv->ref is dated before the current fiscal year (closed period); refusing to post.";
			dol_syslog("stancerForcePostPayment $paymId: " . $result['message'], LOG_WARNING);
			return $result;
		}
	}

	// Build the common payment data (amount/date/method), shared by both paths.
	$apiCustomerIdG = null;
	if (isset($api['customer'])) {
		$apiCustomerIdG = is_array($api['customer'])
			? (isset($api['customer']['id']) ? $api['customer']['id'] : null)
			: $api['customer'];
	}
	$amountCentsG = isset($api['amount']) ? (int) $api['amount'] : 0;
	$createdG = isset($api['created']) ? (int) $api['created'] : 0;
	$dateG = ($createdG > 0) ? dol_print_date($createdG, '%Y-%m-%d') : dol_print_date(dol_now(), '%Y-%m-%d');
	$methodG = isset($api['method']) ? (string) $api['method'] : 'card';
	$paymentTypeG = ($methodG === 'sepa') ? 'PRE' : 'CB';

	// 2b. Grouped payment: dispatch the captured amount across the invoices via the
	// dedicated grouped poster (dispatches by per-invoice remaining, enforces its own
	// dedup/bank/amount guards; the customer guard does not apply to it).
	if (count($invoices) >= 2) {
		$refsList = array();
		foreach ($invoices as $inv) {
			$refsList[] = (string) $inv->ref;
		}
		$result['invoice_ref'] = implode('+', $refsList);
		$result['invoice_id']  = (int) $invoices[0]->id;

		$dataG = array(
			'payment_id'      => $paymId,
			'date'            => $dateG,
			'FinalPaymentAmt' => $amountCentsG / 100,
			'paymentType'     => $paymentTypeG,
			'paymentTypeId'   => (int) dol_getIdFromCode($db, $paymentTypeG, 'c_paiement', 'code', 'id', 1),
			'ipaddress'       => 'forced-by-admin',
			'TRANSACTIONID'   => $paymId,
			'service'         => 'stancer',
			'paymentmethod'   => 'stancer',
			'label'           => '(CustomerInvoicePayment)',
			'FinalFees'       => isset($api['fee']) ? $api['fee'] : 0,
			'ref'             => implode('+', $refsList),
		);
		$errorMessageG = '';
		$resG = stancerAddPaymentOnInvoices($invoices, $dataG, $errorMessageG);
		if ($resG == 0) {
			$result['success'] = true;
			$result['message'] = "Payment $paymId dispatched on " . count($invoices) . " invoices (" . implode('+', $refsList) . ").";
			dol_syslog("stancerForcePostPayment: grouped dispatch of $paymId on " . implode('+', $refsList)
				. " by user=" . (int) $user->id, LOG_NOTICE);
		} else {
			$result['message'] = "Refused (code $resG): " . $errorMessageG;
			dol_syslog("stancerForcePostPayment: grouped refused $paymId on " . implode('+', $refsList) . " code=$resG err=$errorMessageG", LOG_WARNING);
		}
		return $result;
	}

	// 2c. Single invoice path.
	$fac = $invoices[0];
	$result['invoice_ref'] = (string) $fac->ref;
	$result['invoice_id']  = (int) $fac->id;

	// Over-pay path: the invoice is already settled (double payment). Re-open it
	// before posting so Dolibarr accepts the extra payment; the amount guard is
	// bypassed below. The resulting over-payment is left for the admin to settle
	// with a credit note / refund.
	// @phan-suppress-next-line PhanDeprecatedProperty  $paye is the column Dolibarr 15..21 fills and still writes; status==2 also covers abandoned invoices
	if ($allowOverpay && (int) $fac->paye === 1) {
		$resReopen = $fac->setUnpaid($user);
		if ($resReopen <= 0) {
			$result['message'] = "Failed to re-open invoice " . $fac->ref . " before over-pay: " . $fac->error;
			dol_syslog("stancerForcePostPayment $paymId: " . $result['message'], LOG_ERR);
			return $result;
		}
		dol_syslog("stancerForcePostPayment: re-opened invoice " . $fac->ref
			. " for supervised over-pay by user=" . (int) $user->id, LOG_WARNING);
		$fac->fetch($fac->id);
	}

	// 3. Build the payment data (mirror of the refresh cron), reusing the common
	// amount/date/method resolved above.
	$data = array(
		'payment_id'      => $paymId,
		'date'            => $dateG,
		'FinalPaymentAmt' => $amountCentsG / 100,
		'paymentType'     => 'CB',
		'paymentTypeId'   => (int) dol_getIdFromCode($db, 'CB', 'c_paiement', 'code', 'id', 1),
		'ipaddress'       => 'forced-by-admin',
		'TRANSACTIONID'   => $paymId,
		'service'         => 'stancer',
		'paymentmethod'   => $methodG,
		'label'           => '(CustomerInvoicePayment)',
		'FinalFees'       => isset($api['fee']) ? $api['fee'] : 0, // cents (divided once at consumption)
		'ref'             => $fac->ref,
		// Guard 1 (bank lib) checks api_order_id === invoice ref. When order_id is a
		// commande/propal ref, we already verified the order/propal -> invoice link
		// via stancerResolveInvoiceFromOrderId() (a stronger check than the string
		// equality), so we hand Guard 1 the resolved invoice ref. In the direct case
		// $fac->ref === $orderId, so this changes nothing there.
		'api_order_id'    => $fac->ref,
		'api_customer_id' => $apiCustomerIdG,
	);

	// 4. Post, bypassing the customer guard (and the amount guard when over-pay
	// was explicitly requested).
	$errorMessage = '';
	$res = stancerAddPaymentOnObject($fac, $data, $errorMessage, true, $allowOverpay);
	if ($res == 0) {
		$result['success'] = true;
		$result['message'] = ($allowOverpay ? "Payment $paymId posted (over-pay) on invoice " : "Payment $paymId posted on invoice ") . $fac->ref . ".";
		dol_syslog("stancerForcePostPayment: posted $paymId on " . $fac->ref
			. " (overpay=" . ($allowOverpay ? '1' : '0') . ") by user=" . (int) $user->id, LOG_NOTICE);
	} else {
		$result['message'] = "Refused (code $res): " . $errorMessage;
		dol_syslog("stancerForcePostPayment: refused $paymId on " . $fac->ref . " code=$res err=$errorMessage", LOG_WARNING);
	}
	return $result;
}

/**
 * Distinct non-empty ext_payment_site values of the payments attached to an
 * invoice (e.g. 'stancer', 'stripe', 'mollie'). Used to show by which means a
 * (possibly double-paid) invoice was settled.
 *
 * @param  int    $invoiceId Invoice rowid.
 * @param  DoliDB $db        Database handle.
 * @return string[]          Distinct payment-site codes (may be empty).
 */
function stancerInvoicePaymentMethods($invoiceId, $db)
{
	$invoiceId = (int) $invoiceId;
	$methods = array();
	if ($invoiceId <= 0) {
		return $methods;
	}
	$sql = "SELECT DISTINCT p.ext_payment_site FROM " . MAIN_DB_PREFIX . "paiement p";
	$sql .= " INNER JOIN " . MAIN_DB_PREFIX . "paiement_facture pf ON pf.fk_paiement = p.rowid";
	$sql .= " WHERE pf.fk_facture = " . ((int) $invoiceId);
	$sql .= " AND p.ext_payment_site IS NOT NULL AND p.ext_payment_site <> ''";
	$res = $db->query($sql);
	if (!$res) {
		dol_syslog("stancerInvoicePaymentMethods SQL error: " . $db->lasterror(), LOG_ERR);
		return $methods;
	}
	while (($row = $db->fetch_object($res))) {
		$methods[] = (string) $row->ext_payment_site;
	}
	$db->free($res);
	return $methods;
}

/**
 * Pick the sales invoice linked to an order or a proposal.
 *
 * Skips credit notes (we want the real sales invoice) and, when several invoices
 * are linked, prefers the one that already carries a Stancer payment (the real
 * target of the payment); otherwise returns the first non-credit-note invoice.
 *
 * @param  Commande|Propal $obj Source object (already fetched).
 * @param  DoliDB          $db  Database handle.
 * @return Facture|null         Linked sales invoice, or null when none.
 */
function stancerPickLinkedInvoice($obj, $db)
{
	require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';

	$obj->fetchObjectLinked($obj->id, $obj->element, null, 'facture');
	if (empty($obj->linkedObjectsIds['facture'])) {
		return null;
	}

	$fallback = null;
	foreach ($obj->linkedObjectsIds['facture'] as $facid) {
		$inv = new Facture($db);
		if ($inv->fetch((int) $facid) <= 0) {
			continue;
		}
		// Skip credit notes: we want the real sales invoice, not its avoir.
		if ((int) $inv->type === Facture::TYPE_CREDIT_NOTE) {
			continue;
		}
		// Prefer an invoice that already carries a Stancer payment: it is the real
		// target of the payment (exactly the "not a problem" case).
		$methods = stancerInvoicePaymentMethods((int) $inv->id, $db);
		if (in_array('stancer', $methods, true)) {
			return $inv;
		}
		if ($fallback === null) {
			$fallback = $inv;
		}
	}
	return $fallback;
}

/**
 * Resolve the Dolibarr invoice designated by a Stancer order_id.
 *
 * The order_id may not be an invoice ref: some workflows create a sales order
 * (commande) first, pay it via Stancer (order_id = the order ref), then create
 * the invoice from the order at payment return. In that case the order_id ties
 * the payment to a commande, and the real invoice is the one linked to it. A
 * proposal (propal) works the same way.
 *
 * Resolution order:
 *   1. order_id is an invoice ref -> that invoice.
 *   2. order_id is a commande ref -> the sales invoice linked to it.
 *   3. order_id is a propal ref   -> the sales invoice linked to it.
 *
 * @param  string $orderId Stancer order_id.
 * @param  DoliDB $db      Database handle.
 * @return Facture|null    Resolved invoice (fetched), or null when unresolved.
 */
function stancerResolveInvoiceFromOrderId($orderId, $db)
{
	require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';

	$orderId = (string) $orderId;
	if ($orderId === '') {
		return null;
	}

	// 1. Direct: order_id is an invoice ref.
	$fac = new Facture($db);
	if ($fac->fetch(0, $orderId) > 0) {
		return $fac;
	}

	// 2. order_id is a sales order (commande) ref -> follow the link to its invoice.
	require_once DOL_DOCUMENT_ROOT . '/commande/class/commande.class.php';
	$cmd = new Commande($db);
	if ($cmd->fetch(0, $orderId) > 0) {
		$inv = stancerPickLinkedInvoice($cmd, $db);
		if ($inv !== null) {
			dol_syslog("stancerResolveInvoiceFromOrderId: order_id='$orderId' is order #" . (int) $cmd->id
				. ", resolved to linked invoice " . $inv->ref, LOG_DEBUG);
			return $inv;
		}
	}

	// 3. order_id is a proposal (propal) ref -> follow the link to its invoice.
	require_once DOL_DOCUMENT_ROOT . '/comm/propal/class/propal.class.php';
	$prop = new Propal($db);
	if ($prop->fetch(0, $orderId) > 0) {
		$inv = stancerPickLinkedInvoice($prop, $db);
		if ($inv !== null) {
			dol_syslog("stancerResolveInvoiceFromOrderId: order_id='$orderId' is propal #" . (int) $prop->id
				. ", resolved to linked invoice " . $inv->ref, LOG_DEBUG);
			return $inv;
		}
	}

	dol_syslog("stancerResolveInvoiceFromOrderId: order_id='$orderId' resolves to no invoice (nor order/propal link)", LOG_DEBUG);
	return null;
}

/**
 * True when a given invoice id is among the invoices linked to a source object
 * (commande/propal).
 *
 * @param  Commande|Propal $obj       Source object (already fetched).
 * @param  int             $invoiceId Invoice rowid to look for.
 * @param  DoliDB          $db        Database handle.
 * @return bool
 */
function stancerLinkedInvoiceIdsContain($obj, $invoiceId, $db)
{
	$invoiceId = (int) $invoiceId;
	$obj->fetchObjectLinked($obj->id, $obj->element, null, 'facture');
	if (empty($obj->linkedObjectsIds['facture'])) {
		return false;
	}
	foreach ($obj->linkedObjectsIds['facture'] as $fid) {
		if ((int) $fid === $invoiceId) {
			return true;
		}
	}
	return false;
}

/**
 * Whether a Stancer order_id designates (directly, or through a commande/propal
 * link) a given Dolibarr invoice. Used to tell a legit create-order-first
 * workflow (order_id is a commande ref, invoice built at payment return) apart
 * from a real misattribution (order_id points to a different invoice).
 *
 * @param  string $orderId   Stancer order_id.
 * @param  int    $invoiceId  Invoice the local Paiement is attached to.
 * @param  DoliDB $db         Database handle.
 * @return bool               True when order_id covers that invoice.
 */
function stancerOrderIdCoversInvoiceId($orderId, $invoiceId, $db)
{
	require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';

	$orderId = (string) $orderId;
	$invoiceId = (int) $invoiceId;
	if ($orderId === '' || $invoiceId <= 0) {
		return false;
	}

	// Direct: order_id IS the ref of that very invoice.
	$fac = new Facture($db);
	if ($fac->fetch(0, $orderId) > 0 && (int) $fac->id === $invoiceId) {
		return true;
	}

	// order_id is a commande ref -> is the invoice among its linked invoices?
	require_once DOL_DOCUMENT_ROOT . '/commande/class/commande.class.php';
	$cmd = new Commande($db);
	if ($cmd->fetch(0, $orderId) > 0 && stancerLinkedInvoiceIdsContain($cmd, $invoiceId, $db)) {
		return true;
	}

	// order_id is a propal ref -> same check.
	require_once DOL_DOCUMENT_ROOT . '/comm/propal/class/propal.class.php';
	$prop = new Propal($db);
	if ($prop->fetch(0, $orderId) > 0 && stancerLinkedInvoiceIdsContain($prop, $invoiceId, $db)) {
		return true;
	}

	return false;
}

/**
 * Resolve the settlement state of the invoice designated by a Stancer order_id.
 * Used by the double-payment detection (Form A): a captured-but-not-posted
 * Stancer payment whose target invoice is already settled by another mean is a
 * probable double charge.
 *
 * The order_id is resolved via stancerResolveInvoiceFromOrderId(), so an order_id
 * that designates a commande/propal (invoice created afterwards) still resolves to
 * the real linked invoice.
 *
 * The @return array shape is deliberately kept on a single physical line: a
 * multi-line shape is not parsed by static analysers and degrades to plain array.
 *
 * @param  string $orderId Stancer order_id (invoice, order or propal ref).
 * @param  DoliDB $db      Database handle.
 * @return array{found:bool,invoice_id:int,invoice_ref:string,invoice_date:int,remaining:float|null,is_settled:bool,methods:string[]}
 */
function stancerInvoiceStateForOrderId($orderId, $db)
{
	require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';

	$orderId = (string) $orderId;
	$state = array(
		'found'        => false,
		'invoice_id'   => 0,
		'invoice_ref'  => $orderId,
		'invoice_date' => 0,
		'remaining'    => null,
		'is_settled'   => false,
		'methods'      => array(),
	);
	if ($orderId === '') {
		return $state;
	}

	$fac = stancerResolveInvoiceFromOrderId($orderId, $db);
	if ($fac === null) {
		return $state; // order_id does not resolve to a Dolibarr invoice (nor order/propal link)
	}
	$state['found']        = true;
	$state['invoice_id']   = (int) $fac->id;
	$state['invoice_ref']  = (string) $fac->ref;
	$state['invoice_date'] = (int) $fac->date;

	$paid    = (float) $fac->getSommePaiement();
	$credit  = (float) $fac->getSumCreditNotesUsed();
	$deposit = (float) $fac->getSumDepositsUsed();
	$remaining = (float) price2num($fac->total_ttc - $paid - $credit - $deposit, 'MT');
	$state['remaining']  = $remaining;
	$state['is_settled'] = ($remaining <= 0.01);
	$state['methods']    = stancerInvoicePaymentMethods((int) $fac->id, $db);

	return $state;
}

/**
 * Resolve every Dolibarr invoice covered by a Stancer payment row.
 *
 * A payment may cover several invoices when the "group same-day invoices in one
 * SEPA debit" option is on: order_id is then "FAxxx+FAyyy" (refs joined by '+',
 * possibly truncated with a trailing "+N" count). The reliable source is the
 * grouped_invoice_ids column (comma-separated invoice ids); order_id parsing is
 * only a fallback for rows that predate that column.
 *
 * @param  object $paymentRow Row from stancerFindCapturedPaymentsNotPosted()
 *                            (needs ->grouped_invoice_ids and ->order_id).
 * @param  DoliDB $db         Database handle.
 * @return Facture[]          Resolved invoices (may be empty).
 */
function stancerResolveInvoicesForPayment($paymentRow, $db)
{
	require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';

	$invoices = array();

	// 1. Reliable path: the stored list of grouped invoice ids.
	$groupedIds = isset($paymentRow->grouped_invoice_ids) ? (string) $paymentRow->grouped_invoice_ids : '';
	if ($groupedIds !== '') {
		$ids = array_filter(array_map('intval', explode(',', $groupedIds)));
		foreach ($ids as $iid) {
			$inv = new Facture($db);
			if ($inv->fetch((int) $iid) > 0) {
				$invoices[] = $inv;
			} else {
				dol_syslog("stancerResolveInvoicesForPayment: grouped invoice id=$iid not fetchable (row rowid="
					. (isset($paymentRow->rowid) ? (int) $paymentRow->rowid : 0) . ")", LOG_WARNING);
			}
		}
		if (!empty($invoices)) {
			return $invoices;
		}
	}

	// 2. Fallback: parse order_id. A '+' separates invoice refs; a purely numeric
	// trailing token is the "+N remaining" count (see stancerBuildGroupedOrderId),
	// not a ref, so it is skipped.
	$orderId = isset($paymentRow->order_id) ? (string) $paymentRow->order_id : '';
	if ($orderId === '') {
		return $invoices;
	}
	if (strpos($orderId, '+') !== false) {
		$tokens = explode('+', $orderId);
		$seen = array();
		foreach ($tokens as $tok) {
			$tok = trim($tok);
			if ($tok === '' || ctype_digit($tok)) {
				continue; // skip empty and the trailing "+N" count token
			}
			$inv = stancerResolveInvoiceFromOrderId($tok, $db);
			if ($inv !== null && !isset($seen[(int) $inv->id])) {
				$seen[(int) $inv->id] = true;
				$invoices[] = $inv;
			}
		}
		return $invoices;
	}

	// 3. Single invoice.
	$inv = stancerResolveInvoiceFromOrderId($orderId, $db);
	if ($inv !== null) {
		$invoices[] = $inv;
	}
	return $invoices;
}

/**
 * Aggregate the settlement state of one or several invoices covered by a Stancer
 * payment. Mirrors stancerInvoiceStateForOrderId() but for a set of invoices.
 *
 * The @return array shape is deliberately kept on a single physical line: a
 * multi-line shape is not parsed by static analysers and degrades to plain array.
 *
 * @param  Facture[] $invoices Resolved invoices.
 * @param  DoliDB    $db       Database handle.
 * @return array{found:bool,grouped:bool,count:int,remaining:float,is_settled:bool,earliest_date:int,methods:string[],invoices:array<int,array{id:int,ref:string,remaining:float,is_settled:bool}>}
 */
function stancerAggregateInvoiceState(array $invoices, $db)
{
	$agg = array(
		'found'         => false,
		'grouped'       => (count($invoices) > 1),
		'count'         => count($invoices),
		'remaining'     => 0.0,
		'is_settled'    => true,
		'earliest_date' => 0,
		'methods'       => array(),
		'invoices'      => array(),
	);
	if (empty($invoices)) {
		$agg['is_settled'] = false;
		return $agg;
	}
	$agg['found'] = true;
	$methods = array();
	foreach ($invoices as $inv) {
		$paid    = (float) $inv->getSommePaiement();
		$credit  = (float) $inv->getSumCreditNotesUsed();
		$deposit = (float) $inv->getSumDepositsUsed();
		$remaining = (float) price2num($inv->total_ttc - $paid - $credit - $deposit, 'MT');
		$settled = ($remaining <= 0.01);

		$agg['remaining'] += $remaining;
		if (!$settled) {
			$agg['is_settled'] = false;
		}
		$d = (int) $inv->date;
		if ($d > 0 && ($agg['earliest_date'] === 0 || $d < $agg['earliest_date'])) {
			$agg['earliest_date'] = $d;
		}
		foreach (stancerInvoicePaymentMethods((int) $inv->id, $db) as $m) {
			$methods[$m] = $m;
		}
		$agg['invoices'][] = array(
			'id'         => (int) $inv->id,
			'ref'        => (string) $inv->ref,
			'remaining'  => $remaining,
			'is_settled' => $settled,
		);
	}
	$agg['remaining'] = (float) price2num($agg['remaining'], 'MT');
	$agg['methods']   = array_values($methods);
	return $agg;
}

/**
 * Find invoices that carry at least one Stancer Dolibarr payment AND are
 * over-paid (total settled > total_ttc), i.e. the double-payment Form B: the
 * Stancer payment and another one (Stripe/Mollie/...) were both reconciled.
 *
 * Stancer payment and another one (Stripe/Mollie/...) were both reconciled.
 *
 * SQL pre-filters on SUM(paiement) > total_ttc (the common 2-payments case),
 * then the exact remaining (incl. credit notes / deposits) is recomputed per
 * invoice to confirm the over-payment.
 *
 * The @return array shape is deliberately kept on a single physical line: a
 * multi-line shape is not parsed by static analysers and degrades to plain array.
 *
 * @param  DoliDB $db      Database handle.
 * @param  int    $maxRows Hard cap.
 * @return array<int,array{invoice_id:int,invoice_ref:string,total_ttc:float,paid:float,excess:float,fk_soc:int,soc_name:string,methods:string[]}>
 */
function stancerFindOverpaidWithStancer($db, $maxRows = 500)
{
	require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';

	// Restrict to the current fiscal year: over-paid invoices from a previous, closed
	// fiscal year are already booked / in the balance sheet and must not be surfaced.
	$fiscalStart = dol_print_date(stancerGetFiscalYearStartTs(), '%Y-%m-%d');
	$sql = "SELECT f.rowid AS invoice_id, f.ref AS invoice_ref, f.total_ttc, f.fk_soc,";
	$sql .= " s.nom AS soc_name, COALESCE(SUM(pf.amount), 0) AS paid";
	$sql .= " FROM " . MAIN_DB_PREFIX . "facture f";
	$sql .= " INNER JOIN " . MAIN_DB_PREFIX . "paiement_facture pf ON pf.fk_facture = f.rowid";
	$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "societe s ON s.rowid = f.fk_soc";
	$sql .= " WHERE f.rowid IN (";
	$sql .= "   SELECT pf2.fk_facture FROM " . MAIN_DB_PREFIX . "paiement p2";
	$sql .= "   INNER JOIN " . MAIN_DB_PREFIX . "paiement_facture pf2 ON pf2.fk_paiement = p2.rowid";
	$sql .= "   WHERE p2.ext_payment_site = 'stancer')";
	$sql .= " AND f.datef >= '" . $db->escape($fiscalStart) . "'";
	$sql .= " GROUP BY f.rowid, f.ref, f.total_ttc, f.fk_soc, s.nom";
	$sql .= " HAVING COALESCE(SUM(pf.amount), 0) > f.total_ttc + 0.01";
	$sql .= " ORDER BY f.ref LIMIT " . ((int) $maxRows);

	$res = $db->query($sql);
	if (!$res) {
		dol_syslog("stancerFindOverpaidWithStancer SQL error: " . $db->lasterror(), LOG_ERR);
		return array();
	}
	$rows = array();
	while (($row = $db->fetch_object($res))) {
		$invId = (int) $row->invoice_id;
		// Confirm with the exact remaining (credit notes / deposits included).
		$fac = new Facture($db);
		if ($fac->fetch($invId) <= 0) {
			continue;
		}
		$paid    = (float) $fac->getSommePaiement();
		$credit  = (float) $fac->getSumCreditNotesUsed();
		$deposit = (float) $fac->getSumDepositsUsed();
		$remaining = (float) price2num($fac->total_ttc - $paid - $credit - $deposit, 'MT');
		if ($remaining >= -0.01) {
			continue; // not actually over-paid once credit notes/deposits are counted
		}
		$rows[] = array(
			'invoice_id'  => $invId,
			'invoice_ref' => (string) $row->invoice_ref,
			'total_ttc'   => (float) $row->total_ttc,
			'paid'        => $paid + $credit + $deposit,
			'excess'      => -$remaining,
			'fk_soc'      => (int) $row->fk_soc,
			'soc_name'    => (string) $row->soc_name,
			'methods'     => stancerInvoicePaymentMethods($invId, $db),
		);
	}
	$db->free($res);
	return $rows;
}
