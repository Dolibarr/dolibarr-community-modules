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
 * \file    stancer/lib/stancer_audit.lib.php
 * \ingroup stancer
 * \brief   Read-only audit of Stancer payment attributions.
 *
 * The audit cross-checks each local Dolibarr Paiement (llx_paiement +
 * llx_paiement_facture) against the source of truth: the Stancer API.
 * Used by stancer_audit.php to detect rows that the misattribution
 * incident (NITD / PICHINOV / BLUE HORSE GROUP) may have left in DB
 * before the defensive guards were merged.
 *
 * No write here. The audit only classifies. Remediation is a separate step.
 */

dol_include_once('/stancer/class/stancer_api.class.php');

// Classification constants. Keep stable: used as i18n keys (StancerAuditStatus<X>)
// and grepped from tests.
define('STANCER_AUDIT_OK',                          'OK');
define('STANCER_AUDIT_WRONG_INVOICE_SAME_CUSTOMER', 'wrong-invoice-same-customer');
define('STANCER_AUDIT_WRONG_CUSTOMER',              'wrong-customer');
define('STANCER_AUDIT_WRONG_CUSTOMER_UNMAPPED',     'wrong-customer-unmapped');
define('STANCER_AUDIT_WRONG_AMOUNT',                'wrong-amount');
define('STANCER_AUDIT_NO_MAPPING',                  'no-mapping');
define('STANCER_AUDIT_GROUPED',                     'grouped');
define('STANCER_AUDIT_API_UNREACHABLE',             'api-unreachable');
define('STANCER_AUDIT_API_NOT_FOUND',               'api-not-found');
define('STANCER_AUDIT_API_AUTH_ERROR',              'api-auth-error');

/**
 * Resolve a Stancer customer id to a Dolibarr socid via llx_societe_rib.
 *
 * @param string $stancerCustomerId Stancer customer id (cust_xxx).
 * @param DoliDB $db                Database handle.
 * @return int                       fk_soc, or 0 if no mapping found.
 */
function stancerAuditResolveSocidFromStancerCustomer($stancerCustomerId, $db)
{
	if (empty($stancerCustomerId)) {
		return 0;
	}
	$sql = "SELECT fk_soc FROM " . MAIN_DB_PREFIX . "societe_rib";
	$sql .= " WHERE stancer_account = '" . $db->escape($stancerCustomerId) . "'";
	$sql .= " AND stancer_account <> '' LIMIT 1";
	$res = $db->query($sql);
	if (!$res) {
		dol_syslog("stancerAuditResolveSocidFromStancerCustomer SQL error: " . $db->lasterror(), LOG_ERR);
		return 0;
	}
	$row = $db->fetch_object($res);
	$db->free($res);
	if (!$row || empty($row->fk_soc)) {
		return 0;
	}
	return (int) $row->fk_soc;
}

/**
 * Normalise a customer/company name for comparison (case-insensitive, trimmed,
 * collapsed whitespace). Used as a fallback when the cust_xxx -> socid mapping
 * is missing from societe_rib (typical for CB-only customers).
 *
 * @param string $name Raw name.
 * @return string      Normalised key. Returns '' for empty inputs.
 */
function stancerAuditNormaliseName($name)
{
	$name = (string) $name;
	if ($name === '') {
		return '';
	}
	if (function_exists('mb_strtolower')) {
		$name = mb_strtolower($name, 'UTF-8');
	} else {
		$name = strtolower($name);
	}
	$name = preg_replace('/\s+/', ' ', trim($name));
	return $name === null ? '' : $name;
}

/**
 * Audit one Dolibarr Paiement row against the Stancer API.
 *
 * @param object     $dbRow      Row with fields: paiement_id (int), stancer_paym_id (string),
 *                               datep (string), db_fk_facture (int), db_invoice_ref (string),
 *                               db_socid (int), db_client (string), db_invoice_ttc (float),
 *                               db_paid_amount (float).
 * @param StancerApi $stancerApi Initialised API client.
 * @param DoliDB     $db         Database handle.
 * @return array{status:string, api:array, mapped_socid:int, details:string, http_code:int}
 */
function stancerAuditPayment($dbRow, $stancerApi, $db)
{
	$result = array(
		'status'       => STANCER_AUDIT_OK,
		'api'          => array(),
		'mapped_socid' => 0,
		'details'      => '',
		'http_code'    => 0,
	);

	$paymentId = (string) $dbRow->stancer_paym_id;
	if (empty($paymentId) || strpos($paymentId, 'paym_') !== 0) {
		$result['status']  = STANCER_AUDIT_API_NOT_FOUND;
		$result['details'] = 'Local num_paiement is not a Stancer paym_id';
		return $result;
	}

	$apiPayment = $stancerApi->getPayment($paymentId);
	$result['http_code'] = (int) $stancerApi->lastHttpCode;

	if ($apiPayment === false) {
		if ($result['http_code'] === 401) {
			$result['status']  = STANCER_AUDIT_API_AUTH_ERROR;
			$result['details'] = 'Auth error (HTTP 401) - stop the audit and fix the API key';
			return $result;
		}
		if ($result['http_code'] === 404) {
			$result['status']  = STANCER_AUDIT_API_NOT_FOUND;
			$result['details'] = 'Stancer API returned 404 for ' . $paymentId;
			return $result;
		}
		$result['status']  = STANCER_AUDIT_API_UNREACHABLE;
		$result['details'] = 'API error: ' . $stancerApi->error;
		return $result;
	}

	// Normalise the API payload to a few flat fields we care about.
	$apiCustomerId = '';
	$apiCustomerName = '';
	if (isset($apiPayment['customer']) && is_array($apiPayment['customer'])) {
		$apiCustomerId   = isset($apiPayment['customer']['id'])   ? (string) $apiPayment['customer']['id']   : '';
		$apiCustomerName = isset($apiPayment['customer']['name']) ? (string) $apiPayment['customer']['name'] : '';
	}
	$apiOrderId  = isset($apiPayment['order_id'])  ? (string) $apiPayment['order_id']  : '';
	$apiUniqueId = isset($apiPayment['unique_id']) ? (string) $apiPayment['unique_id'] : '';
	$apiAmountCents = isset($apiPayment['amount']) ? (int) $apiPayment['amount'] : 0;
	$apiAmountEur   = round($apiAmountCents / 100, 2);

	$result['api'] = array(
		'customer_id'   => $apiCustomerId,
		'customer_name' => $apiCustomerName,
		'order_id'      => $apiOrderId,
		'unique_id'     => $apiUniqueId,
		'amount'        => $apiAmountEur,
		'status'        => isset($apiPayment['status']) ? (string) $apiPayment['status'] : '',
	);

	// Map Stancer customer to Dolibarr socid.
	$mappedSocid = stancerAuditResolveSocidFromStancerCustomer($apiCustomerId, $db);
	$result['mapped_socid'] = $mappedSocid;

	// SEPA grouped payments share one paym_id across several invoices of the
	// same customer. They cannot be audited row-by-row with the order_id rule
	// (the order_id will only match one of the N invoices). Flag them and skip
	// the order_id check; we still verify the customer mapping.
	$isGrouped = (strpos($apiUniqueId, 'GRP=') === 0);

	$dbSocid = (int) $dbRow->db_socid;
	$dbClient      = isset($dbRow->db_client) ? (string) $dbRow->db_client : '';
	$dbInvoiceRef  = (string) $dbRow->db_invoice_ref;
	$dbPaidAmount  = (float) $dbRow->db_paid_amount;

	// Name-based fallback: when societe_rib has no mapping for cust_xxx (typical
	// for CB-only customers that never had a SEPA mandate), we cannot use the
	// mapped_socid. We use the raw customer name from the API instead, which is
	// strictly weaker but enough to detect the most common misattribution:
	// "Stancer says client A, Dolibarr says client B".
	$apiNameKey = stancerAuditNormaliseName($apiCustomerName);
	$dbNameKey  = stancerAuditNormaliseName($dbClient);
	$namesMatch = ($apiNameKey !== '' && $dbNameKey !== '' && $apiNameKey === $dbNameKey);

	// 1. Customer mismatch (mapped): we can be 100% sure, the Paiement is on
	// the wrong client and must be detached.
	if ($mappedSocid > 0 && $mappedSocid !== $dbSocid) {
		$result['status']  = STANCER_AUDIT_WRONG_CUSTOMER;
		$result['details'] = "API customer '$apiCustomerId' ($apiCustomerName) maps to socid=$mappedSocid";
		$result['details'] .= " but local Paiement is on socid=$dbSocid (invoice $dbInvoiceRef).";
		return $result;
	}

	// 1bis. Customer mismatch (unmapped): no row in societe_rib for cust_xxx,
	// but the API customer name does NOT match the local invoice's customer name.
	// This is the NITD/NUMASYOUR/FOX case in the audit dump: NITD's cust_xxx is
	// not in societe_rib (no SEPA mandate), and the local Paiement is on an
	// invoice of NUMASYOUR. The classification used to fall through to
	// "wrong-invoice-same-customer" which was misleading.
	if ($mappedSocid === 0 && !empty($apiCustomerName) && !empty($dbClient) && !$namesMatch) {
		$result['status']  = STANCER_AUDIT_WRONG_CUSTOMER_UNMAPPED;
		$result['details'] = "Stancer customer '$apiCustomerId' ($apiCustomerName) has no row in";
		$result['details'] .= " llx_societe_rib (no mandate). API customer name differs from local invoice's";
		$result['details'] .= " customer ('$dbClient', socid=$dbSocid, invoice $dbInvoiceRef) - probably wrong";
		$result['details'] .= " customer. Verify manually before remediation.";
		return $result;
	}

	// 2. No mapping but names match: still flag as info but continue to check
	// the invoice ref and amount.
	if ($mappedSocid === 0 && !empty($apiCustomerId)) {
		$result['status']  = STANCER_AUDIT_NO_MAPPING;
		$result['details'] = "Stancer customer '$apiCustomerId' ($apiCustomerName) has no row";
		$result['details'] .= " in llx_societe_rib.stancer_account - customer name matches local invoice";
		$result['details'] .= " but mapping is missing.";
		// Continue: we may still detect a wrong-invoice-same-customer or wrong-amount.
	}

	// 3. Grouped SEPA: customer matched, order_id check is irrelevant.
	if ($isGrouped) {
		// Still enforce amount sanity, but on the grouped total it is normal that
		// db_paid_amount (one share) differs from api_amount (the full group).
		// So we only flag amount errors when db_paid_amount > api_amount + tolerance.
		if ($dbPaidAmount > $apiAmountEur + 0.01) {
			$result['status']  = STANCER_AUDIT_WRONG_AMOUNT;
			$result['details'] = "Grouped SEPA: local share=$dbPaidAmount EUR exceeds API total=$apiAmountEur EUR.";
			return $result;
		}
		if ($result['status'] === STANCER_AUDIT_OK) {
			$result['status']  = STANCER_AUDIT_GROUPED;
			$result['details'] = "SEPA grouped payment (unique_id starts with GRP=) - per-invoice order_id check skipped.";
		}
		return $result;
	}

	// 4. Wrong invoice (same customer): order_id from API points elsewhere.
	if (!empty($apiOrderId) && !empty($dbInvoiceRef) && $apiOrderId !== $dbInvoiceRef) {
		// The order_id may be a commande/propal ref whose invoice was created only at
		// payment return (create-order-first workflow: pay the order, build the invoice
		// on the way back). If order_id resolves - directly or through the order/propal
		// link - to the very invoice the Paiement is attached to, this is legit, NOT a
		// misattribution. Only flag when it does NOT cover that invoice.
		dol_include_once('/stancer/lib/stancer_repair.lib.php');
		if (!stancerOrderIdCoversInvoiceId($apiOrderId, (int) $dbRow->db_fk_facture, $db)) {
			$result['status']  = STANCER_AUDIT_WRONG_INVOICE_SAME_CUSTOMER;
			$result['details'] = "API order_id='$apiOrderId' but local Paiement is attached to invoice='$dbInvoiceRef'";
			$result['details'] .= " (same customer, wrong invoice).";
			return $result;
		}
		dol_syslog("stancerAuditPayment: order_id='$apiOrderId' resolves (via order/propal link) to the attached invoice '$dbInvoiceRef' (create-order-first workflow), not a misattribution", LOG_DEBUG);
	}

	// 5. Wrong amount (single-invoice payment): db_paid_amount must equal api_amount
	// within rounding (0.01 EUR).
	if (abs($apiAmountEur - $dbPaidAmount) > 0.01) {
		$result['status']  = STANCER_AUDIT_WRONG_AMOUNT;
		$result['details'] = "API amount=$apiAmountEur EUR differs from local paid=$dbPaidAmount EUR";
		$result['details'] .= " (single-invoice payment).";
		return $result;
	}

	// 6. Otherwise OK (or no-mapping but everything else matches).
	if ($result['status'] === STANCER_AUDIT_OK) {
		$result['details'] = "Customer, invoice ref and amount all match the Stancer API.";
	}
	return $result;
}

/**
 * Fetch the list of Dolibarr Paiement rows that the audit will scan.
 *
 * @param DoliDB   $db        Database handle.
 * @param int|null $dateStart Period start (unix timestamp), null for no lower bound.
 * @param int|null $dateEnd   Period end   (unix timestamp), null for no upper bound.
 * @param int      $socid     If > 0, restrict to this customer.
 * @param int      $maxRows   Hard cap on the number of returned rows.
 * @return array<int, object>|false  Array of rows, or false on SQL error.
 *
 * @phan-suppress PhanPluginMoreSpecificActualReturnType  An empty result set is a legitimate
 *   outcome (no Stancer payment in the period), so the return type cannot be a non-empty list.
 */
function stancerAuditFetchRows($db, $dateStart, $dateEnd, $socid, $maxRows)
{
	$sql = "SELECT";
	$sql .= " p.rowid          AS paiement_id,";
	$sql .= " p.num_paiement   AS stancer_paym_id,";
	$sql .= " p.datep          AS datep,";
	$sql .= " pf.fk_facture    AS db_fk_facture,";
	$sql .= " f.ref            AS db_invoice_ref,";
	$sql .= " f.fk_soc         AS db_socid,";
	$sql .= " s.nom            AS db_client,";
	$sql .= " f.total_ttc      AS db_invoice_ttc,";
	$sql .= " pf.amount        AS db_paid_amount,";
	$sql .= " f.paye           AS db_invoice_paye,";
	$sql .= " f.fk_statut      AS db_invoice_statut";
	$sql .= " FROM " . MAIN_DB_PREFIX . "paiement p";
	$sql .= " INNER JOIN " . MAIN_DB_PREFIX . "paiement_facture pf ON pf.fk_paiement = p.rowid";
	$sql .= " INNER JOIN " . MAIN_DB_PREFIX . "facture f ON f.rowid = pf.fk_facture";
	$sql .= " LEFT JOIN "  . MAIN_DB_PREFIX . "societe s ON s.rowid = f.fk_soc";
	$sql .= " WHERE p.ext_payment_site = 'stancer'";
	$sql .= " AND p.num_paiement LIKE 'paym\\_%'";

	if ($dateStart !== null) {
		$sql .= " AND p.datep >= '" . $db->idate($dateStart) . "'";
	}
	if ($dateEnd !== null) {
		$sql .= " AND p.datep <= '" . $db->idate($dateEnd) . "'";
	}
	if ($socid > 0) {
		$sql .= " AND f.fk_soc = " . (int) $socid;
	}
	$sql .= " ORDER BY p.datep DESC";
	$sql .= " LIMIT " . ((int) $maxRows + 1); // +1 to detect "too many"

	dol_syslog("stancerAuditFetchRows sql=" . $sql, LOG_DEBUG);

	$res = $db->query($sql);
	if (!$res) {
		dol_syslog("stancerAuditFetchRows SQL error: " . $db->lasterror(), LOG_ERR);
		return false;
	}
	$rows = array();
	while (($row = $db->fetch_object($res))) {
		$rows[] = $row;
	}
	$db->free($res);
	return $rows;
}

/**
 * Statuses on the Stancer side that represent money actually taken from the
 * customer. Used to detect double-charge: when 2+ payments in this set hit
 * the same api_order_id, the customer has paid the same invoice twice.
 *
 * @return string[]
 */
function stancerAuditCapturedStatuses()
{
	return array('captured', 'to_capture', 'authorized');
}

// ActionComm codes used by the audit. Kept short (<= 50 chars to fit
// llx_actioncomm.code) and grepable for SQL filtering.
define('STANCER_AUDIT_AC_IGNORE',  'AC_STANCER_AUDIT_IGNORE');
define('STANCER_AUDIT_AC_REATTACH', 'AC_STANCER_AUDIT_REATTACH');

/**
 * Return the set of Stancer paym_id that have been marked "ignore" by an
 * earlier audit run. The paym_id is stored in the ActionComm.extraparams
 * field; code is fixed (STANCER_AUDIT_AC_IGNORE).
 *
 * @param DoliDB $db Database handle.
 * @return array<string, true>  Set of paym_id (keys), value is always true.
 */
function stancerAuditFetchIgnoredPaymIds($db)
{
	$ignored = array();
	$sql = "SELECT extraparams FROM " . MAIN_DB_PREFIX . "actioncomm";
	$sql .= " WHERE code = '" . $db->escape(STANCER_AUDIT_AC_IGNORE) . "'";
	$sql .= " AND extraparams IS NOT NULL AND extraparams <> ''";
	$res = $db->query($sql);
	if (!$res) {
		dol_syslog("stancerAuditFetchIgnoredPaymIds SQL error: " . $db->lasterror(), LOG_ERR);
		return $ignored;
	}
	while (($row = $db->fetch_object($res))) {
		$paymId = trim((string) $row->extraparams);
		if ($paymId !== '') {
			$ignored[$paymId] = true;
		}
	}
	$db->free($res);
	return $ignored;
}

/**
 * Given the output of stancerAuditBuildGroupView(), return the set of paym_id
 * that are part of a "double-charge" pair (>=2 captured payments on the same
 * api_order_id). Used by the UI to warn the user before reattaching, since
 * fixing both will cause the target invoice to be over-paid.
 *
 * @param array<int, array{has_double:bool, api_order_id:string, payments:array}> $groups  Groups built by stancerAuditBuildGroupView()
 * @return array<string, string>  Map paym_id => api_order_id of the double.
 *
 * @phan-suppress PhanPluginMoreSpecificActualReturnType  No group carrying a double is the
 *   nominal case, so an empty map is a legitimate return value.
 */
function stancerAuditDetectPaymsInDoubles(array $groups)
{
	$doubles = array();
	foreach ($groups as $g) {
		if (empty($g['has_double'])) {
			continue;
		}
		foreach ($g['payments'] as $p) {
			$doubles[(string) $p['paym_id']] = (string) $g['api_order_id'];
		}
	}
	return $doubles;
}

/**
 * Mark a Stancer paiement as "ignored" by the audit: creates an ActionComm
 * with code = STANCER_AUDIT_AC_IGNORE and stores the paym_id in extraparams.
 * Subsequent audit runs will hide or grey-out this row.
 *
 * @param int        $paiementId Local llx_paiement.rowid.
 * @param DoliDB     $db         Database handle.
 * @param User       $user       Current user (for audit trail).
 * @param string     $reason     Optional free-form reason.
 * @return array{success:bool, message:string, paym_id:string, actioncomm_id:int}
 */
function stancerAuditIgnore($paiementId, $db, $user, $reason = '')
{
	$result = array(
		'success'       => false,
		'message'       => '',
		'paym_id'       => '',
		'actioncomm_id' => 0,
	);

	$paiementId = (int) $paiementId;
	if ($paiementId <= 0) {
		$result['message'] = 'Invalid paiement_id';
		return $result;
	}

	$sql = "SELECT rowid, num_paiement, fk_user_creat FROM " . MAIN_DB_PREFIX . "paiement";
	$sql .= " WHERE rowid = " . ((int) $paiementId) . " AND ext_payment_site = 'stancer'";
	$res = $db->query($sql);
	if (!$res) {
		$result['message'] = 'SQL error fetching paiement';
		return $result;
	}
	$paiement = $db->fetch_object($res);
	$db->free($res);
	if (!$paiement) {
		$result['message'] = 'Paiement not found or not a Stancer paiement';
		return $result;
	}

	$paymId = (string) $paiement->num_paiement;
	$result['paym_id'] = $paymId;

	// Idempotent: refuse to create a 2nd ignore row for the same paym_id.
	$sqlExist = "SELECT id FROM " . MAIN_DB_PREFIX . "actioncomm";
	$sqlExist .= " WHERE code = '" . $db->escape(STANCER_AUDIT_AC_IGNORE) . "'";
	$sqlExist .= " AND extraparams = '" . $db->escape($paymId) . "' LIMIT 1";
	$resExist = $db->query($sqlExist);
	if ($resExist) {
		$existing = $db->fetch_object($resExist);
		$db->free($resExist);
		if ($existing) {
			$result['success']       = true;
			$result['message']       = 'Already ignored';
			$result['actioncomm_id'] = (int) $existing->id;
			return $result;
		}
	}

	require_once DOL_DOCUMENT_ROOT . '/comm/action/class/actioncomm.class.php';
	$ac = new ActionComm($db);
	$ac->type_code    = 'AC_OTH_AUTO';
	$ac->code         = STANCER_AUDIT_AC_IGNORE;
	$ac->label        = 'Audit ignore: ' . $paymId;
	$ac->note_private = 'Marked as ignored by Stancer audit on ' . dol_print_date(dol_now(), 'dayhour');
	$ac->note_private .= ' by user ' . (int) $user->id . '.';
	$ac->note_private .= ($reason !== '' ? "\nReason: " . $reason : '');
	$ac->datep        = dol_now();
	$ac->datef        = dol_now();
	$ac->percentage   = -1;
	$ac->authorid     = (int) $user->id;
	$ac->userownerid  = (int) $user->id;
	$ac->extraparams  = $paymId;
	$ac->elementid    = $paiementId;
	// @phan-suppress-next-line PhanDeprecatedProperty  ActionComm::create() only reads fk_element up to Dolibarr 18, both fields must be fed
	$ac->fk_element   = $paiementId;
	$ac->elementtype  = 'payment';
	$acId = $ac->create($user);
	if ($acId <= 0) {
		$result['message'] = 'Failed to create ActionComm: ' . $ac->error;
		dol_syslog("stancerAuditIgnore: failed to create ActionComm for paym=$paymId: " . $ac->error, LOG_ERR);
		return $result;
	}

	dol_syslog("stancerAuditIgnore paym=$paymId actioncomm_id=$acId by user=" . (int) $user->id, LOG_NOTICE);

	$result['success']       = true;
	$result['actioncomm_id'] = (int) $acId;
	$result['message']       = 'Ignored';
	return $result;
}

/**
 * Reattach a Stancer Paiement to the correct invoice (the one indicated by
 * the API order_id). The local llx_paiement row is preserved (the money has
 * really arrived on the bank); only the llx_paiement_facture link is moved.
 *
 * Both the old and new invoices are recomputed via setPaid()/setUnpaid()
 * so that triggers fire and the paye/fk_statut flags are coherent.
 *
 * Atomic: $db->begin() / commit() with rollback on any failure.
 *
 * @param int        $paiementId Local llx_paiement.rowid.
 * @param DoliDB     $db         Database handle.
 * @param User       $user       Current user.
 * @param StancerApi $stancerApi Initialised API client.
 * @return array{success:bool, message:string, paym_id:string, old_invoice_ref:string, new_invoice_ref:string, over_paid_amount:float, actioncomm_ids:int[]}
 */
function stancerAuditFix($paiementId, $db, $user, $stancerApi)
{
	$result = array(
		'success'          => false,
		'message'          => '',
		'paym_id'          => '',
		'old_invoice_ref'  => '',
		'new_invoice_ref'  => '',
		'over_paid_amount' => 0.0,
		'actioncomm_ids'   => array(),
	);

	$paiementId = (int) $paiementId;
	if ($paiementId <= 0) {
		$result['message'] = 'Invalid paiement_id';
		return $result;
	}

	// 1. Load the local paiement and its current paiement_facture link.
	$sqlLoad = "SELECT p.rowid AS paiement_id, p.num_paiement, pf.fk_facture AS db_fk_facture,";
	$sqlLoad .= " f.ref AS db_invoice_ref, f.total_ttc AS db_total_ttc, f.fk_soc AS db_socid";
	$sqlLoad .= " FROM " . MAIN_DB_PREFIX . "paiement p";
	$sqlLoad .= " INNER JOIN " . MAIN_DB_PREFIX . "paiement_facture pf ON pf.fk_paiement = p.rowid";
	$sqlLoad .= " INNER JOIN " . MAIN_DB_PREFIX . "facture f ON f.rowid = pf.fk_facture";
	$sqlLoad .= " WHERE p.rowid = " . ((int) $paiementId);
	$sqlLoad .= " AND p.ext_payment_site = 'stancer' LIMIT 1";
	$resLoad = $db->query($sqlLoad);
	if (!$resLoad) {
		$result['message'] = 'SQL error loading paiement: ' . $db->lasterror();
		return $result;
	}
	$row = $db->fetch_object($resLoad);
	$db->free($resLoad);
	if (!$row) {
		$result['message'] = 'Paiement not found, or no paiement_facture link, or not a Stancer paiement';
		return $result;
	}
	$paymId = (string) $row->num_paiement;
	$oldFkFacture  = (int) $row->db_fk_facture;
	$oldInvoiceRef = (string) $row->db_invoice_ref;
	$result['paym_id']         = $paymId;
	$result['old_invoice_ref'] = $oldInvoiceRef;

	// 2. Re-fetch the API truth (anti-TOCTOU: data may have changed since audit).
	$apiPayment = $stancerApi->getPayment($paymId);
	if ($apiPayment === false) {
		$result['message'] = 'Stancer API error: ' . $stancerApi->error;
		$result['message'] .= ' (HTTP ' . (int) $stancerApi->lastHttpCode . ')';
		return $result;
	}
	$apiOrderId = isset($apiPayment['order_id']) ? (string) $apiPayment['order_id'] : '';
	if ($apiOrderId === '') {
		$result['message'] = 'API returned empty order_id - cannot resolve target invoice';
		return $result;
	}
	if ($apiOrderId === $oldInvoiceRef) {
		$result['message'] = 'Paiement is already attached to the correct invoice; nothing to do';
		return $result;
	}

	// 3. Resolve target invoice by ref. Must be validated (fk_statut >= 1).
	$sqlTarget = "SELECT rowid, ref, fk_soc, fk_statut, paye, total_ttc";
	$sqlTarget .= " FROM " . MAIN_DB_PREFIX . "facture";
	$sqlTarget .= " WHERE ref = '" . $db->escape($apiOrderId) . "' AND fk_statut >= 1";
	$sqlTarget .= " ORDER BY rowid LIMIT 1";
	$resTarget = $db->query($sqlTarget);
	if (!$resTarget) {
		$result['message'] = 'SQL error resolving target invoice: ' . $db->lasterror();
		return $result;
	}
	$target = $db->fetch_object($resTarget);
	$db->free($resTarget);
	if (!$target) {
		$result['message'] = 'Target invoice not found or not validated: ' . $apiOrderId;
		return $result;
	}
	$newFkFacture  = (int) $target->rowid;
	$newInvoiceRef = (string) $target->ref;
	$result['new_invoice_ref'] = $newInvoiceRef;

	if ($newFkFacture === $oldFkFacture) {
		$result['message'] = 'Old and new invoices are the same';
		return $result;
	}

	require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
	require_once DOL_DOCUMENT_ROOT . '/comm/action/class/actioncomm.class.php';

	// 4. Transaction.
	$db->begin();

	$sqlUpdate = "UPDATE " . MAIN_DB_PREFIX . "paiement_facture";
	$sqlUpdate .= " SET fk_facture = " . ((int) $newFkFacture);
	$sqlUpdate .= " WHERE fk_paiement = " . ((int) $paiementId);
	$sqlUpdate .= " AND fk_facture = " . ((int) $oldFkFacture);
	$resUpdate = $db->query($sqlUpdate);
	if (!$resUpdate) {
		$db->rollback();
		$result['message'] = 'UPDATE paiement_facture failed: ' . $db->lasterror();
		dol_syslog("stancerAuditFix UPDATE failed for paym=$paymId: " . $db->lasterror(), LOG_ERR);
		return $result;
	}
	$affected = $db->affected_rows($resUpdate);
	if ((int) $affected !== 1) {
		$db->rollback();
		$result['message'] = 'UPDATE paiement_facture affected ' . $affected . ' rows instead of 1';
		dol_syslog("stancerAuditFix UPDATE affected $affected rows for paym=$paymId", LOG_ERR);
		return $result;
	}

	// 5. Recompute paye flags on both invoices.
	$invOld = new Facture($db);
	if ($invOld->fetch($oldFkFacture) <= 0) {
		$db->rollback();
		$result['message'] = 'Failed to fetch old invoice ' . $oldFkFacture;
		return $result;
	}
	$invNew = new Facture($db);
	if ($invNew->fetch($newFkFacture) <= 0) {
		$db->rollback();
		$result['message'] = 'Failed to fetch new invoice ' . $newFkFacture;
		return $result;
	}
	stancerAuditRecomputePayeFlag($invOld, $user);
	stancerAuditRecomputePayeFlag($invNew, $user);

	// 6. Report over-payment on the new invoice (option A: keep both paym_id,
	// the user will issue an avoir or refund separately).
	$newPaid = (float) $invNew->getSommePaiement() + (float) $invNew->getSumCreditNotesUsed() + (float) $invNew->getSumDepositsUsed();
	$over = (float) price2num($newPaid - $invNew->total_ttc, 'MT');
	if ($over > 0.01) {
		$result['over_paid_amount'] = $over;
	}

	// 7. ActionComm on both invoices for traceability.
	$noteCommon = "Stancer audit reattach: paym_id=$paymId moved from invoice $oldInvoiceRef";
	$noteCommon .= " (id=$oldFkFacture) to invoice $newInvoiceRef (id=$newFkFacture)";
	$noteCommon .= " by user " . (int) $user->id . " on " . dol_print_date(dol_now(), 'dayhour') . ".";
	if ($over > 0.01) {
		$noteCommon .= "\nWARNING: invoice $newInvoiceRef is over-paid by " . $over;
		$noteCommon .= " EUR after this move (DOUBLE PRELEVEMENT). A credit note or refund must be issued separately.";
	}

	foreach (array($invOld, $invNew) as $inv) {
		$ac = new ActionComm($db);
		$ac->type_code    = 'AC_OTH_AUTO';
		$ac->code         = STANCER_AUDIT_AC_REATTACH;
		$ac->label        = 'Audit reattach: ' . $paymId;
		$ac->note_private = $noteCommon;
		$ac->datep        = dol_now();
		$ac->datef        = dol_now();
		$ac->percentage   = -1;
		$ac->authorid     = (int) $user->id;
		$ac->userownerid  = (int) $user->id;
		$ac->extraparams  = $paymId;
		$ac->elementid    = (int) $inv->id;
		// @phan-suppress-next-line PhanDeprecatedProperty  ActionComm::create() only reads fk_element up to Dolibarr 18, both fields must be fed
		$ac->fk_element   = (int) $inv->id;
		$ac->elementtype  = 'facture';
		$ac->socid        = (int) $inv->socid;
		$acId = $ac->create($user);
		if ($acId <= 0) {
			$db->rollback();
			$result['message'] = 'Failed to create ActionComm on invoice ' . $inv->ref . ': ' . $ac->error;
			dol_syslog("stancerAuditFix ActionComm failed for paym=$paymId on inv=" . $inv->ref . ": " . $ac->error, LOG_ERR);
			return $result;
		}
		$result['actioncomm_ids'][] = (int) $acId;
	}

	$db->commit();
	dol_syslog("stancerAuditFix paym=$paymId moved from inv=$oldInvoiceRef ($oldFkFacture) to inv=$newInvoiceRef ($newFkFacture), over=$over EUR, by user=" . (int) $user->id, LOG_NOTICE);

	$result['success'] = true;
	$result['message'] = 'Reattached';
	return $result;
}

/**
 * Recompute paye/fk_statut for the given invoice by comparing total payments
 * (paiements + credit notes + deposits) with total_ttc. Uses setPaid()/
 * setUnpaid() so that triggers fire. Side effect: $invoice is re-fetched
 * after the call.
 *
 * @param Facture $invoice The invoice (already fetched, with id set).
 * @param User    $user    Current user.
 * @return void
 */
function stancerAuditRecomputePayeFlag($invoice, $user)
{
	$paid    = (float) $invoice->getSommePaiement();
	$credit  = (float) $invoice->getSumCreditNotesUsed();
	$deposit = (float) $invoice->getSumDepositsUsed();
	$total   = $paid + $credit + $deposit;
	$remaining = (float) price2num($invoice->total_ttc - $total, 'MT');

	// Tolerate 0.01 EUR rounding gap.
	// @phan-suppress-next-line PhanDeprecatedProperty  $paye is the column Dolibarr 16..21 fills and still writes; status==2 also covers abandoned invoices
	if ($remaining <= 0.01 && (int) $invoice->paye === 0) {
		$invoice->setPaid($user);
		dol_syslog("stancerAuditRecomputePayeFlag invoice " . $invoice->ref . " -> setPaid (paid=$total ttc=" . $invoice->total_ttc . ")", LOG_NOTICE);
		// @phan-suppress-next-line PhanDeprecatedProperty  $paye is the column Dolibarr 16..21 fills and still writes; status==2 also covers abandoned invoices
	} elseif ($remaining > 0.01 && (int) $invoice->paye === 1) {
		$invoice->setUnpaid($user);
		dol_syslog("stancerAuditRecomputePayeFlag invoice " . $invoice->ref . " -> setUnpaid (paid=$total ttc=" . $invoice->total_ttc . ")", LOG_NOTICE);
	}
}

/**
 * Group audit results by Stancer api_order_id (the real invoice the payment
 * was meant for). Used to surface the "same invoice paid via several paym_id"
 * pattern that hides under per-row audit lines.
 *
 * Grouped SEPA payments (unique_id starts with GRP=) are excluded: their
 * api_order_id is only one of N covered invoices, so grouping by it would
 * generate false-positives.
 *
 * Returned entries (only api_order_id with >= 2 paym_id):
 *  [
 *    'api_order_id'      => 'FA2512-4485',
 *    'api_customer_id'   => 'cust_xxx',
 *    'api_customer_name' => 'NITD',
 *    'payments'          => [
 *        [paym_id, status_api, status_audit, amount_api, db_invoice_ref, db_paid_amount],
 *        ...
 *    ],
 *    'captured_count'    => 2,
 *    'has_double'        => true,        // captured_count > 1
 *  ]
 *
 * @param array<int, array{row:object, audit:array}> $results Output of the audit loop.
 * @return array<int, array{api_order_id:string, api_customer_id:string, api_customer_name:string, payments:array, captured_count:int, has_double:bool}>
 */
function stancerAuditBuildGroupView(array $results)
{
	$captured = stancerAuditCapturedStatuses();
	$byOrderId = array();
	foreach ($results as $item) {
		$audit = $item['audit'];
		$row   = $item['row'];

		// Skip grouped SEPA: api_order_id refers to one of N invoices.
		if ($audit['status'] === STANCER_AUDIT_GROUPED) {
			continue;
		}

		$apiOrderId   = isset($audit['api']['order_id']) ? (string) $audit['api']['order_id'] : '';
		$apiStatus    = isset($audit['api']['status'])   ? (string) $audit['api']['status']   : '';
		$apiAmount    = isset($audit['api']['amount'])   ? (float) $audit['api']['amount']   : 0.0;
		$apiCustId    = isset($audit['api']['customer_id'])   ? (string) $audit['api']['customer_id']   : '';
		$apiCustName  = isset($audit['api']['customer_name']) ? (string) $audit['api']['customer_name'] : '';

		if ($apiOrderId === '') {
			continue;
		}

		if (!isset($byOrderId[$apiOrderId])) {
			$byOrderId[$apiOrderId] = array(
				'api_order_id'      => $apiOrderId,
				'api_customer_id'   => $apiCustId,
				'api_customer_name' => $apiCustName,
				'payments'          => array(),
				'captured_count'    => 0,
				'has_double'        => false,
			);
		}
		$byOrderId[$apiOrderId]['payments'][] = array(
			'paym_id'        => (string) $row->stancer_paym_id,
			'status_api'     => $apiStatus,
			'status_audit'   => (string) $audit['status'],
			'amount_api'     => $apiAmount,
			'db_invoice_ref' => isset($row->db_invoice_ref) ? (string) $row->db_invoice_ref : '',
			'db_paid_amount' => isset($row->db_paid_amount) ? (float) $row->db_paid_amount : 0.0,
		);
		if (in_array(strtolower($apiStatus), $captured, true)) {
			$byOrderId[$apiOrderId]['captured_count']++;
		}
	}

	// Keep only api_order_id with at least 2 payments and flag the doubles.
	$filtered = array();
	foreach ($byOrderId as $entry) {
		if (count($entry['payments']) < 2) {
			continue;
		}
		$entry['has_double'] = ($entry['captured_count'] > 1);
		$filtered[] = $entry;
	}
	return $filtered;
}
