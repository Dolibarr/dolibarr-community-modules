<?php
/* Copyright (C) 2023-2026 Eric Seigne <eric.seigne@cap-rel.fr>
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
 * \file    stancer/lib/stancer_customer.lib.php
 * \ingroup stancer
 * \brief   Customer management functions (SEPA, CB, IBAN)
 */

/**
 * update stancer account ref
 *
 * @param	int		$memberid		Dolibarr member id, only used when accountType is 'adherent'
 * @param	string	$accountType	'societe' or 'adherent'
 * @param	string	$customerID		Stancer customer id (cust_xxx) to store
 * @return	void
 */
function stancerUpdateCustomerStancerRef($memberid, $accountType, $customerID)
{
	global $user, $db;
	dol_syslog("stancerUpdateCustomerStancerRef : memberid=$memberid, accountType=$accountType, customerID=$customerID");
	if ($accountType == 'societe') {
		//nothing for the moment
	} elseif ($accountType == 'member') {
		$adherent = new AdherentStancer($db);
		$res = $adherent->fetch($memberid);
		print("stancerUpdateCustomerStancerRef : $res");
		if ($res) {
			$adherent->array_options['options_stancer_account'] = $customerID;
			$adherent->update($user);
		}
	}
}


/**
 * stancer limit is min = 4 max = 64
 *
 * @param  string  $str  soc name
 *
 * @return  string filtered soc name
 */
function stancerFilterSocName($str)
{
	if (strlen($str) < 4) {
		return "CLIENT " . $str;
	}
	if (strlen($str) > 64) {
		return substr($str, 0, 63);
	}
	return $str;
}

/**
 * Try to find an existing Stancer customer locally (without hitting the API):
 *  1. llx_societe_rib for the given socid with any non-empty stancer_account.
 *  2. llx_stancer_stancer_payments.customer for any past payment of this socid.
 *
 * Pass 2 covers the case where the customer paid via CB-one-shot but no
 * persistent payment mode was stored on the Dolibarr side. Without it, an
 * SEPA mandate sent later would trigger a duplicate cust_xxx server-side
 * (Stancer does NOT deduplicate by email - NITD incident, 2026-05-19).
 *
 * @param int       $socid     Dolibarr socid to look for.
 * @param DoliDB    $db        Database handle.
 * @param bool      $liveMode  Restrict pass 2 to the current live/test scope.
 * @return string              Customer id (cust_xxx) if found, '' otherwise.
 */
function stancerFindExistingCustomerLocally($socid, $db, $liveMode)
{
	$socid = (int) $socid;
	if ($socid <= 0) {
		return '';
	}

	// Pass 1: societe_rib (persistent payment modes).
	$sql1 = "SELECT stancer_account FROM " . MAIN_DB_PREFIX . "societe_rib";
	$sql1 .= " WHERE fk_soc = " . ((int) $socid);
	$sql1 .= " AND stancer_account IS NOT NULL AND stancer_account <> ''";
	$sql1 .= " ORDER BY rowid LIMIT 1";
	$res = $db->query($sql1);
	if ($res) {
		$row = $db->fetch_object($res);
		$db->free($res);
		if ($row && !empty($row->stancer_account)) {
			dol_syslog("stancerFindExistingCustomerLocally: societe_rib hit for socid=$socid -> " . $row->stancer_account, LOG_DEBUG);
			return (string) $row->stancer_account;
		}
	}

	// Pass 2: historical payments. Restrict to live_mode to avoid mixing
	// test and prod customers when both are seeded for the same socid.
	$liveValue = $liveMode ? 1 : 0;
	$sql2 = "SELECT customer, COUNT(*) AS nb FROM " . MAIN_DB_PREFIX . "stancer_stancer_payments";
	$sql2 .= " WHERE fk_soc = " . ((int) $socid);
	$sql2 .= " AND customer IS NOT NULL AND customer <> ''";
	$sql2 .= " AND live_mode = " . ((int) $liveValue);
	$sql2 .= " GROUP BY customer";
	$sql2 .= " ORDER BY nb DESC, customer ASC LIMIT 1";
	$res = $db->query($sql2);
	if ($res) {
		$row = $db->fetch_object($res);
		$db->free($res);
		if ($row && !empty($row->customer)) {
			dol_syslog("stancerFindExistingCustomerLocally: stancer_payments hit for socid=$socid -> " . $row->customer . " (" . (int) $row->nb . " past payments)", LOG_DEBUG);
			return (string) $row->customer;
		}
	}

	return '';
}

/**
 * Fallback: ask Stancer if a customer with the given email already exists.
 * Used when no local trace can be found.
 *
 * IMPORTANT - cross-customer leak fix (2026-06): the Stancer API
 * GET /v2/customers/ only supports these filters: created, external_id, email,
 * name, country, legal_id, start, limit. There is NO 'mobile' filter. Passing
 * ?mobile= is silently IGNORED by the API, which then returns the first page of
 * ALL customers; the previous code took $list[0] blindly and therefore
 * attributed one single customer (the first of the account) to every thirdparty
 * that had no local trace - linking one cust_xxx to many unrelated socids.
 * We now:
 *   1. look up by EMAIL only (the one reliable filter),
 *   2. VALIDATE that the returned customer's email actually equals the one
 *      searched, so a non-honoured filter can never make us reuse a stranger.
 *
 * @param string     $email      Customer email (the only supported lookup key).
 * @param string     $mobile     Customer mobile. Kept for signature compatibility;
 *                               NOT usable as an API filter (see above).
 * @param StancerApi $stancerApi Initialised API client.
 * @return string                Customer id if found AND email-validated, '' otherwise.
 */
function stancerFindExistingCustomerOnStancer($email, $mobile, $stancerApi)
{
	$email = trim((string) $email);
	if ($email === '') {
		dol_syslog("stancerFindExistingCustomerOnStancer: no email provided, skipping API lookup (mobile is not a supported Stancer customer filter)", LOG_DEBUG);
		return '';
	}

	$resp = $stancerApi->listCustomers(array('email' => $email, 'limit' => 10));
	if ($resp === false) {
		dol_syslog("stancerFindExistingCustomerOnStancer: API error on email lookup: "
			. $stancerApi->error, LOG_WARNING);
		return '';
	}

	// Stancer paginated payload: {customers: [...]} or {data: [...]} depending on version.
	$list = array();
	if (isset($resp['customers']) && is_array($resp['customers'])) {
		$list = $resp['customers'];
	} elseif (isset($resp['data']) && is_array($resp['data'])) {
		$list = $resp['data'];
	}

	$needle = strtolower($email);
	foreach ($list as $entry) {
		if (empty($entry['id'])) {
			continue;
		}
		// Defensive validation: only reuse a customer whose email REALLY matches
		// the one we searched. This is what makes us immune to an API that does
		// not honour the email filter and returns unrelated customers.
		$entryEmail = isset($entry['email']) ? strtolower(trim((string) $entry['email'])) : '';
		if ($entryEmail !== $needle) {
			dol_syslog("stancerFindExistingCustomerOnStancer: IGNORING cust=" . $entry['id']
				. " returned for email='$email' but whose email='" . ($entry['email'] ?? '')
				. "' does not match (API filter not honoured?)", LOG_WARNING);
			continue;
		}
		dol_syslog("stancerFindExistingCustomerOnStancer: API hit by email='$email' -> "
			. $entry['id'], LOG_DEBUG);
		return (string) $entry['id'];
	}
	return '';
}

/**
 * Return the list of OTHER socids already linked to a given Stancer customer id,
 * excluding $excludeSocid. Looks in BOTH sources of truth used elsewhere by the
 * module: llx_societe_rib.stancer_account (persistent payment modes) and
 * llx_stancer_stancer_payments.customer (historical payments).
 *
 * Used by the preventive guard in stancerAddCustomerIfNeeded() to detect that a
 * cust_xxx is about to be shared between two distinct thirdparties.
 *
 * @param string $customerID   Stancer customer id (cust_xxx).
 * @param int    $excludeSocid socid to exclude (the current one).
 * @param DoliDB $db           Database handle.
 * @return int[]               Distinct other socids (>0), may be empty.
 */
function stancerCustomerOtherSocids($customerID, $excludeSocid, $db)
{
	$customerID   = (string) $customerID;
	$excludeSocid = (int) $excludeSocid;
	$others = array();
	if ($customerID === '') {
		return array();
	}

	$sources = array(
		"SELECT DISTINCT fk_soc FROM " . MAIN_DB_PREFIX . "societe_rib WHERE stancer_account = '" . $db->escape($customerID) . "' AND fk_soc <> " . $excludeSocid . " AND fk_soc > 0",
		"SELECT DISTINCT fk_soc FROM " . MAIN_DB_PREFIX . "stancer_stancer_payments WHERE customer = '" . $db->escape($customerID) . "' AND fk_soc <> " . $excludeSocid . " AND fk_soc > 0",
	);
	foreach ($sources as $sql) {
		$res = $db->query($sql);
		if (!$res) {
			dol_syslog("stancerCustomerOtherSocids SQL error: " . $db->lasterror(), LOG_ERR);
			continue;
		}
		while (($row = $db->fetch_object($res))) {
			$others[(int) $row->fk_soc] = (int) $row->fk_soc;
		}
		$db->free($res);
	}
	return array_values($others);
}

/**
 * Trace a shared-customer anomaly: a cust_xxx that the dedupe is about to (re)use
 * for $socid is already linked to other thirdparties ($otherSocids). ALWAYS logs
 * a WARNING; also records an ActionComm on the thirdparty (when a real user is
 * available) so the anomaly surfaces in the UI and can be cleaned up with the
 * admin repair tool.
 *
 * @param int    $socid        Current thirdparty id.
 * @param string $customerID   Stancer customer id (cust_xxx).
 * @param int[]  $otherSocids  Other thirdparties already linked to the cust.
 * @param string $resolution   'split' (a distinct cust was created) or 'kept'
 *                             (reused because history is already bound to it).
 * @param DoliDB $db           Database handle.
 * @param User   $user         Current user (may be empty in public/cron context).
 * @return void
 */
function stancerTraceSharedCustomer($socid, $customerID, $otherSocids, $resolution, $db, $user)
{
	$socid  = (int) $socid;
	$others = implode(',', array_map('intval', $otherSocids));
	dol_syslog("stancer SHARED CUSTOMER cust=$customerID candidate for socid=$socid is also linked to socid(s) $others - resolution=$resolution", LOG_WARNING);

	// ActionComm needs a real author; in NOLOGIN public flows $user has no id.
	if (empty($user->id)) {
		return;
	}

	require_once DOL_DOCUMENT_ROOT . '/comm/action/class/actioncomm.class.php';
	$ac = new ActionComm($db);
	$ac->type_code    = 'AC_OTH_AUTO';
	$ac->code         = 'AC_STANCER_SHARED_CUST';
	$ac->label        = 'Stancer : compte client partagé détecté';
	$ac->note_private = "Le compte client Stancer $customerID était sur le point d'être (ré)utilisé pour le tiers id=$socid alors qu'il est déjà lié au(x) tiers id=$others.\n"
		. ($resolution === 'split'
			? "Action : création d'un compte client Stancer distinct pour ce tiers (pas de partage)."
			: "Action : compte conservé (rattachement historique) - à nettoyer via l'outil de réparation Stancer.");
	$ac->datep        = dol_now();
	$ac->datef        = dol_now();
	$ac->percentage   = -1;
	$ac->socid        = $socid;
	$ac->authorid     = (int) $user->id;
	$ac->userownerid  = (int) $user->id;
	$ac->elementid    = $socid;
	// @phan-suppress-next-line PhanDeprecatedProperty  ActionComm::create() only reads fk_element up to Dolibarr 18, both fields must be fed
	$ac->fk_element   = $socid;
	$ac->elementtype  = 'societe';
	$ac->extraparams  = dol_trunc($customerID, 250);
	if ($ac->create($user) <= 0) {
		dol_syslog("stancerTraceSharedCustomer: failed to create ActionComm: " . $ac->error, LOG_ERR);
	}
}

/**
 * Call createCustomer, retrying once WITHOUT the mobile when Stancer answers
 * 422 about an invalid mobile and an email is available. A malformed phone
 * number (e.g. a French number with one extra digit, as stored on some
 * thirdparties) must not block the whole customer creation.
 *
 * @param  array      $customerData  name + email and/or mobile.
 * @param  StancerApi $stancerApi    API client.
 * @return array|false               API response (expected to carry 'id') or false.
 */
function stancerCreateCustomerWithFallback($customerData, $stancerApi)
{
	$result = $stancerApi->createCustomer($customerData);
	if ($result !== false && isset($result['id'])) {
		return $result;
	}
	if (
		!empty($customerData['mobile']) && !empty($customerData['email'])
		&& (int) $stancerApi->lastHttpCode === 422
		&& stripos((string) $stancerApi->error, 'mobile') !== false
	) {
		dol_syslog("stancerCreateCustomerWithFallback: mobile '" . $customerData['mobile']
			. "' rejected by Stancer (422), retrying without mobile", LOG_WARNING);
		unset($customerData['mobile']);
		$result = $stancerApi->createCustomer($customerData);
	}
	return $result;
}

/**
 * add customer on Stancer if needed
 *
 * @param	object	$object		Thirdparty or member the Stancer customer must be attached to
 * @return	string|int|null		stancerID (cust_xxx), a negative int code, or null when the API failed and no id could be recovered
 */
function stancerAddCustomerIfNeeded($object)
{
	global $db, $conf, $user, $langs;

	dol_syslog("stancerAddCustomerIfNeeded : with object=" . json_encode($object->element) . ":" . json_encode($object->id) . " (only id: less debug message)");

	$error = 0;
	$customerID = null;
	// Set when an existing rib (or local lookup) of this socid points to a cust
	// shared with another thirdparty: the wrong link is removed before creating a
	// distinct customer (bullet-proof against cross-customer double linking).
	$sharedCustToCleanup = null;

	$email = $phone = $objname = $socid = $country_code = $memberid = $accountType = null;
	$listForSociete = ['facture', 'commande', 'invoice', 'order', 'propal'];

	//Si c'est déjà une société
	if ($object->element == 'societe') {
		$societe = $object;
		$email = $societe->email;
		// Prefer the mobile over the landline (Stancer expects a mobile). Use
		// isset()/cast so a missing property (incomplete object) never fatals.
		$phone = "";
		$phoneMobile = isset($societe->phone_mobile) ? trim((string) $societe->phone_mobile) : "";
		$phoneFixe   = isset($societe->phone) ? trim((string) $societe->phone) : "";
		if ($phoneMobile != "") {
			$phone = $phoneMobile;
		} elseif ($phoneFixe != "") {
			$phone = $phoneFixe;
		}
		$objname = $societe->name;
		$socid = $object->id;
		$country_code = $societe->country_code;
		$accountType = 'societe';
	} elseif (in_array($object->element, $listForSociete)) {
		//une facture / commande -> il faut remonter à la société
		$societe = new Societe($db);
		$socresult = $societe->fetch($object->socid);
		if ($socresult) {
			$email = $societe->email;
			$phone = $societe->phone;
			$objname = $societe->name;
			$socid = $societe->id;
			$country_code = $societe->country_code;
		}
		$accountType = 'societe';
	} elseif ($object->element  == 'member') {
		//un membre (association)
		$email = $object->email;
		$phone = $object->phone;
		$objname = $object->firstname . " " . $object->lastname;
		$memberid = $object->id;
		$country_code = $object->country_code;
		$accountType = 'member';
	}
	// print "<p>stancerAddCustomerIfNeeded : " . json_encode($object) . "</p>";
	// exit;

	if (empty($socid) && empty($memberid)) {
		dol_syslog("stancerAddCustomerIfNeeded : socid AND memberid is null, create customer account on stancer remote server is needed");
	} else {
		//societe / client
		if (!empty($socid)) {
			$companypaymentmode = new CompanyPaymentModeStancer($db);
			$res = $companypaymentmode->fetch(0, '', 0, '', " AND stancer_account <> '' AND fk_soc = '" . $db->escape($socid) . "'");
			if ($res) {
				//dans stancer_account on a le customerid stancer
				$customerID = $companypaymentmode->stancer_account;
				if (!empty($customerID)) {
					// Bullet-proof: never reuse a cust that is ALSO linked to another
					// thirdparty. If it is shared, drop this wrong link (below) and
					// create a distinct customer instead of returning the shared one.
					$otherSocids = stancerCustomerOtherSocids($customerID, $socid, $db);
					if (empty($otherSocids)) {
						dol_syslog("stancerAddCustomerIfNeeded exist (company), return id=" . $customerID, LOG_DEBUG);
						return $customerID;
					}
					stancerTraceSharedCustomer($socid, $customerID, $otherSocids, 'split', $db, $user);
					dol_syslog("stancerAddCustomerIfNeeded: rib of socid=$socid points to SHARED cust=$customerID (also on " . implode(',', $otherSocids) . "); creating a distinct customer instead", LOG_WARNING);
					$sharedCustToCleanup = $customerID;
					$customerID = null;
				}
			} else {
				dol_syslog("stancerAddCustomerIfNeeded does not exists (company)", LOG_DEBUG);
			}
		} else {
			//member / association -> extrafields sur adherent
			$adherent = new AdherentStancer($db);
			$res = $adherent->fetch($memberid);
			if ($res) {
				dol_syslog("stancerAddCustomerIfNeeded exist (adherent), return id=" . $adherent->array_options['options_stancer_account'], LOG_DEBUG);
				//dol_syslog("stancerAddCustomerIfNeeded : " . json_encode($adherent), LOG_DEBUG);
				$customerID = $adherent->array_options['options_stancer_account'];
				if (!empty($customerID)) {
					return $customerID;
				}
			}
		}
	}

	if (empty($email) && empty($phone)) {
		dol_syslog("stancerAddCustomerIfNeeded : email and phone are null, create customer account on stancer remote server is impossible");
		return -10;
	}

	if (empty($objname)) {
		dol_syslog("stancerAddCustomerIfNeeded : name is null, create customer account on stancer remote server is impossible", LOG_ERR);
		return -12;
	}

	// Build customer data for API
	$stancerApi = StancerApi::getInstance();
	$customerData = array(
		'name' => stancerFilterSocName($objname)
	);
	if (!empty($email)) {
		$customerData['email'] = $email;
	}
	if (!empty($phone) && substr($phone, 0, 1) == '+') {
		$customerData['mobile'] = $phone;
	}

	// =========================================================================
	// Dedupe (NITD incident, 2026-05-19): Stancer does NOT deduplicate customers
	// by email. Before asking the API to create a new cust_xxx we must look for
	// an existing one we know about, otherwise the same company ends up with
	// several cust_xxx (one per channel: CB one-shot, SEPA mandate, etc.) and
	// every audit run flags the historical payments as wrong-customer-unmapped.
	//
	// Lookup order:
	//   1. Local pass: societe_rib then llx_stancer_stancer_payments.fk_soc.
	//   2. Stancer API pass: GET /customers?email= and ?mobile=.
	// If a hit is found, we skip the createCustomer call and reuse the id.
	// =========================================================================
	if ($accountType === 'societe' && !empty($socid)) {
		$liveMode = (getDolGlobalString('STANCER_IS_PROD', '0') === '1');
		// Pass 1/2: local lookup (a cust that already belongs to THIS socid).
		$existing = stancerFindExistingCustomerLocally($socid, $db, $liveMode);
		$existingFromApi = false;
		if ($existing === '') {
			// Pass 3: remote lookup by email. This is where a cust of another
			// thirdparty could be picked up, so it gets the strictest guard below.
			$existing = stancerFindExistingCustomerOnStancer((string) $email, (string) $phone, $stancerApi);
			$existingFromApi = ($existing !== '');
		}

		// Preventive guard against cross-customer linking (2026-06 leak): never
		// (re)use a cust that is already attached to a DIFFERENT thirdparty.
		if ($existing !== '') {
			$otherSocids = stancerCustomerOtherSocids($existing, $socid, $db);
			if (!empty($otherSocids)) {
				if ($existingFromApi) {
					// Collision being born: a cust found by email is already owned
					// by another thirdparty. Do NOT share it - fall through to
					// createCustomer so this socid gets its own distinct cust_xxx.
					stancerTraceSharedCustomer($socid, $existing, $otherSocids, 'split', $db, $user);
					$existing = '';
				} else {
					// Pre-existing collision on a local cust (rib or residual
					// payments): NEVER reuse a cust linked to another thirdparty.
					// Drop the wrong link (below) and create a distinct one.
					stancerTraceSharedCustomer($socid, $existing, $otherSocids, 'split', $db, $user);
					$sharedCustToCleanup = $existing;
					$existing = '';
				}
			}
		}

		if ($existing !== '') {
			dol_syslog("stancerAddCustomerIfNeeded: reusing existing cust_id=$existing for socid=$socid (dedupe before createCustomer)", LOG_NOTICE);
			$customerID = $existing;
			// Persist the mapping in societe_rib so the next lookup finds it
			// instantly without re-hitting the API.
			$label = 'stancer-card-tst';
			if (getDolGlobalString('STANCER_IS_PROD', '0') === '1') {
				$label = 'stancer-card';
			}
			$data = array(
				'socid'           => $socid,
				'fk_soc'          => $socid,
				'bank'            => null,
				'label'           => $label,
				'stancer_account' => $db->escape($customerID),
				'last_four'       => 0000,
				'number'          => 0000,
				'proprio'         => $objname,
				'exp_date_month'  => "",
				'exp_date_year'   => "",
				'cvn'             => null,
				'datec'           => dol_now(),
				'default_rib'     => 0,
				'type'            => 'card',
				'entity'          => $conf->entity,
				'country_code'    => $country_code,
				'status'          => 1,
			);
			stancerAddCompanyPaymentModeifNeeded($data);
			return $customerID;
		}
	}

	// Bullet-proof cleanup: a rib of this socid was pointing to a cust shared
	// with another thirdparty. Remove that wrong link before creating and
	// anchoring a distinct customer, so the dedupe can never resolve back to the
	// shared cust (and there is no infinite re-creation loop on the next call).
	//
	// A type='ban' row is EXCLUDED from this delete: it is a signed SEPA mandate
	// (IBAN, RUM, mandate date). Dropping it removes the customer's bank account
	// from Dolibarr (Societe::get_all_rib() only reads type='ban') and silently
	// stops every future direct debit. Such a row is re-anchored to the distinct
	// customer further down instead, which breaks the sharing just as well and
	// keeps the mandate usable. NULL is matched explicitly, as "type <> 'ban'"
	// is false for a NULL type in SQL.
	if (!empty($sharedCustToCleanup) && !empty($socid)) {
		$sqlClean = "DELETE FROM " . MAIN_DB_PREFIX . "societe_rib";
		$sqlClean .= " WHERE fk_soc = " . (int) $socid;
		$sqlClean .= " AND stancer_account = '" . $db->escape($sharedCustToCleanup) . "'";
		$sqlClean .= " AND (type IS NULL OR type <> 'ban')";
		$resClean = $db->query($sqlClean);
		if ($resClean) {
			dol_syslog("stancerAddCustomerIfNeeded: removed " . (int) $db->affected_rows($resClean)
				. " wrong rib link(s) of socid=$socid pointing to shared cust=$sharedCustToCleanup (SEPA mandates excluded, they are re-anchored)", LOG_WARNING);
		} else {
			dol_syslog("stancerAddCustomerIfNeeded: failed to remove wrong rib link: " . $db->lasterror(), LOG_ERR);
		}
	}

	dol_syslog("stancer create customer : " . $email . ", " . $objname, LOG_DEBUG);
	$result = stancerCreateCustomerWithFallback($customerData, $stancerApi);

	if ($result !== false && isset($result['id'])) {
		$customerID = $result['id'];
		dol_syslog("stancer customer created : " . $customerID, LOG_DEBUG);
	} else {
		// Customer may already exist (409), try to extract ID from response
		$message = $stancerApi->error;
		dol_syslog("stancer Info (client déjà existant) : " . $email . ", " . $message, LOG_INFO);
		$customerID = null;

		// Try to extract customer ID from the raw API response (JSON structure)
		$lastResponse = $stancerApi->lastResponse;
		if (!empty($lastResponse['content'])) {
			$decoded = json_decode($lastResponse['content'], true);
			if (is_array($decoded) && isset($decoded['error']['message']['id'])) {
				$customerID = $decoded['error']['message']['id'];
			}
		}

		// Fallback: try regex on error message (legacy format with parentheses)
		if (empty($customerID)) {
			$matches = array();
			preg_match('/cust_\w+/', $message, $matches);
			if (is_array($matches) && isset($matches[0])) {
				$customerID = $matches[0];
			}
		}

		if (!empty($customerID)) {
			stancerUpdateCustomerStancerRef((int) $memberid, (string) $accountType, $customerID);
		} else {
			dol_syslog("stancer cannot extract customer ID from error response", LOG_ERR);
			setEventMessages($langs->trans("ErrorStancer") . " (1) " . $message, [], 'errors');
		}
		dol_syslog("stancer récupération de l'id du client : " . $email . ", " . $customerID, LOG_DEBUG);
	}

	$label = 'stancer-card-tst';
	if (getDolGlobalString('STANCER_IS_PROD', '0') == '1') {
		$label = 'stancer-card';
	}

	// Re-anchor the SEPA mandate(s) the delete above deliberately spared: they
	// still carry the shared cust, which would make the dedupe resolve back to it
	// and create yet another customer on the next payment. Only stancer_account
	// changes; the mandate (sepa_xxx) is not bound to a customer on the Stancer
	// side, so the IBAN, the RUM and the withdrawals keep working as they are.
	if (!empty($sharedCustToCleanup) && !empty($socid) && !empty($customerID)) {
		$sqlReanchor = "UPDATE " . MAIN_DB_PREFIX . "societe_rib";
		$sqlReanchor .= " SET stancer_account = '" . $db->escape($customerID) . "'";
		$sqlReanchor .= " WHERE fk_soc = " . (int) $socid;
		$sqlReanchor .= " AND stancer_account = '" . $db->escape($sharedCustToCleanup) . "'";
		$sqlReanchor .= " AND type = 'ban'";
		$resReanchor = $db->query($sqlReanchor);
		if ($resReanchor) {
			$nbReanchored = (int) $db->affected_rows($resReanchor);
			if ($nbReanchored > 0) {
				dol_syslog("stancerAddCustomerIfNeeded: re-anchored $nbReanchored SEPA mandate(s) of socid=$socid from shared cust=$sharedCustToCleanup to $customerID (mandate kept, not deleted)", LOG_WARNING);
			}
		} else {
			dol_syslog("stancerAddCustomerIfNeeded: failed to re-anchor SEPA mandate(s) of socid=$socid to $customerID: " . $db->lasterror(), LOG_ERR);
		}
	}

	if ($socid && $customerID) {
		$data = [
			'socid' => $socid,
			'fk_soc' => $socid,
			'bank' => null,
			'label' => $label,
			'stancer_account' => $db->escape($customerID),
			'last_four' => 0000,
			'number' => 0000,
			'proprio' => $objname,
			'exp_date_month' => "",
			'exp_date_year' => "",
			'cvn' => null,
			'datec' => dol_now(),
			'default_rib' => 0,
			'type' => 'card',
			'entity' => $conf->entity,
			'country_code' => $country_code,
			'status' => 1,
		];
		$compPayModeId = stancerAddCompanyPaymentModeifNeeded($data);
	}
	return $customerID;
}


/**
 * Delete SEPA entry on stancer account
 *
 * @param   int         $socid                  dolibarr soc id
 * @param   string      $stancersepauuid        Stancer SEPA mandate id (sepa_xxx) to delete
 * @param   int|string  $companyPaymentModeID   Dolibarr company payment mode (llx_societe_rib) row id
 * @return  bool                                False in case of error
 */
function stancerDeleteSEPA($socid, $stancersepauuid, $companyPaymentModeID)
{
	global $db, $conf;
	$stancerApi = StancerApi::getInstance();
	$user = new User($db);
	$res = $user->fetch(getDolGlobalInt('STANCER_USER_ACCOUNT_FOR_ACTIONS'));
	if ($res <= 0) {
		dol_syslog("stancerDeleteSEPA cannot fetch action user (STANCER_USER_ACCOUNT_FOR_ACTIONS): " . $user->error, LOG_ERR);
		return false;
	}
	$companypaymentmode = new CompanyPaymentModeStancer($db);
	$resStancer = $companypaymentmode->fetch($companyPaymentModeID);
	if ($resStancer <= 0) {
		dol_syslog("stancerDeleteSEPA cannot fetch company payment mode id=" . $companyPaymentModeID . ": " . $companypaymentmode->error, LOG_ERR);
		return false;
	}

	$result = $stancerApi->deleteSepa($stancersepauuid);

	if ($result !== false) {
		$label = "old-" . $companypaymentmode->label;
		$companypaymentmode->label = $label;
		$companypaymentmode->stancer_account = "";
		$companypaymentmode->stancer_object_ref = "";
		$resUpdate = $companypaymentmode->update($user);
		if ($resUpdate <= 0) {
			dol_syslog("stancerDeleteSEPA failed to update local payment mode id=" . $companyPaymentModeID
				. " after SEPA deletion: " . $companypaymentmode->error, LOG_ERR);
		}
		$res = true;
	} else {
		dol_syslog("stancerDeleteSEPA API error : " . $stancerApi->error, LOG_ERR);
		// If SEPA doesn't exist on Stancer, still clean up local data
		if (strpos($stancerApi->error, 'No such sepa') !== false || $stancerApi->lastHttpCode == 404) {
			$label = "old-" . $companypaymentmode->label;
			$companypaymentmode->label = $label;
			$companypaymentmode->stancer_account = "";
			$companypaymentmode->stancer_object_ref = "";
			$resUpdate = $companypaymentmode->update($user);
			if ($resUpdate <= 0) {
				dol_syslog("stancerDeleteSEPA failed to clean up local payment mode id=" . $companyPaymentModeID
					. " (SEPA absent on Stancer): " . $companypaymentmode->error, LOG_ERR);
			}
		}
		$res = false;
	}

	return $res;
}

// Function stub - not implemented in API v1
// function stancerGetAllSEPA($stancerCustomerID)
// {
// 	global $db, $conf, $user, $langs;
// 	// No list endpoint for SEPA in Stancer API
// }

/**
 * Check IBAN via Stancer SEPAMail verification service
 *
 * Launches an asynchronous IBAN check and tries to retrieve the result
 * within a few seconds. The check is informational only and never blocks
 * the SEPA mandate creation.
 *
 * @param   string   $iban      IBAN to verify
 * @param   Societe  $societe   Dolibarr company object
 * @return  array|null          Check result array or null if not available/not applicable
 *
 * The contract exposed to callers is 'an array or null': the shape of the SEPAMail
 * payload is decided by Stancer, so declaring a narrower non-empty-array here would
 * document an API guarantee this module cannot enforce.
 * @phan-suppress PhanPluginMoreSpecificActualReturnType
 */
function stancerCheckIBAN($iban, $societe)
{
	global $langs;

	if (!getDolGlobalString('STANCER_SEPA_CHECK_IBAN')) {
		return null;
	}

	$langs->load('stancer@stancer');

	// SEPAMail check only works for FR and IT banks
	$countryCode = strtoupper($societe->country_code);
	if (!in_array($countryCode, array('FR', 'IT'))) {
		dol_syslog("stancerCheckIBAN skipped: country_code=$countryCode not supported (FR/IT only)", LOG_DEBUG);
		setEventMessages($langs->trans('StancerSepaCheckSkipped'), array(), 'warnings');
		return null;
	}

	// Company check requires a legal identifier (SIREN/SIRET for FR, etc.)
	$legalId = trim($societe->idprof1);
	if (empty($legalId)) {
		dol_syslog("stancerCheckIBAN skipped: no legal identifier (idprof1) for socid=" . $societe->id, LOG_DEBUG);
		setEventMessages($langs->trans('StancerSepaCheckSkipped'), array(), 'warnings');
		return null;
	}

	$stancerApi = new StancerApi();

	$checkData = array(
		'iban' => $iban,
		'company' => array(
			'identifier' => $legalId,
			'country_code' => $countryCode,
		),
	);

	dol_syslog("stancerCheckIBAN launching check for socid=" . $societe->id . " country=$countryCode", LOG_DEBUG);

	$checkResult = $stancerApi->checkSepa($checkData);
	if ($checkResult === false) {
		dol_syslog("stancerCheckIBAN API error: " . $stancerApi->error, LOG_WARNING);
		setEventMessages($langs->trans('StancerSepaCheckError', $stancerApi->error), array(), 'warnings');
		return null;
	}

	$checkId = isset($checkResult['id']) ? $checkResult['id'] : '';
	if (empty($checkId)) {
		dol_syslog("stancerCheckIBAN no check ID returned", LOG_WARNING);
		setEventMessages($langs->trans('StancerSepaCheckError', 'no check ID returned'), array(), 'warnings');
		return null;
	}

	dol_syslog("stancerCheckIBAN check created, id=$checkId (informational, async)", LOG_DEBUG);

	// F8: the IBAN check is informational and asynchronous. Do NOT block the web
	// worker with sleep() polling (was 3 x sleep(2) = up to 6s, which exhausts
	// workers). Do a single non-blocking poll; if the result is not ready yet, a
	// "pending" message is surfaced below and the value is picked up on a later
	// refresh instead of holding the request.
	$result = null;
	$pollResult = $stancerApi->getSepaCheck($checkId);
	if ($pollResult !== false && isset($pollResult['status']) && $pollResult['status'] === 'succeeded') {
		$result = $pollResult;
	} elseif ($pollResult === false) {
		dol_syslog("stancerCheckIBAN poll failed: " . $stancerApi->error, LOG_DEBUG);
	}

	if ($result === null) {
		dol_syslog("stancerCheckIBAN result not yet available for check_id=$checkId", LOG_DEBUG);
		setEventMessages($langs->trans('StancerSepaCheckPending', $checkId), array(), 'mesgs');
		return null;
	}

	// Extract score and classification from company result
	$company = isset($result['company']) ? $result['company'] : array();
	$score = isset($company['score']) ? (int) $company['score'] : 0;
	$classification = isset($company['classification']) ? $company['classification'] : 'NO';
	$existingAccount = isset($company['existing_account']) ? $company['existing_account'] : null;

	dol_syslog("stancerCheckIBAN result: score=$score classification=$classification existing_account=" . var_export($existingAccount, true) . " check_id=$checkId", LOG_DEBUG);

	if ($existingAccount === false) {
		dol_syslog("stancerCheckIBAN BLOCKED: existing_account=false for check_id=$checkId socid=" . $societe->id, LOG_WARNING);
		setEventMessages($langs->trans('StancerSepaCheckAccountNotReachable'), array(), 'errors');
	} elseif ($score >= 70 || in_array($classification, array('HIGH', 'MEDIUM'))) {
		setEventMessages($langs->trans('StancerSepaCheckSuccess', $score, $classification), array(), 'mesgs');
	} else {
		setEventMessages($langs->trans('StancerSepaCheckWarning', $score, $classification), array(), 'warnings');
	}

	return $result;
}

/**
 * Create SEPA entry on stancer account if needed
 *
 * @param   int  $socid    dolibarr soc id
 * @param   array  $data   data to use
 *
 * @return  string|int  stancer sepaID like sepa_... or a negative int code in case of error
 */
function stancerAddSEPAIfNeeded($socid, $data)
{
	global $db, $conf, $user, $langs;

	$stancerApi = StancerApi::getInstance();

	$sepaID = null;
	$sepaData = null;

	$societe = new Societe($db);
	$socresult = $societe->fetch($socid);
	if ($socresult <= 0 || empty($data['iban'])) {
		dol_syslog("stancerAddSEPAIfNeeded impossible to fetch socid=$socid or iban data is empty:" . ($data['iban'] ?? ''), LOG_ERR);
		return -1;
	}

	$customerID = stancerAddCustomerIfNeeded($societe);
	if (empty($customerID) || (is_numeric($customerID) && $customerID <= 0)) {
		dol_syslog("stancerAddSEPAIfNeeded impossible get customer id, customerID=" . var_export($customerID, true), LOG_ERR);
		return -2;
	}

	// Check if SEPA already exists
	if (isset($data['stancer_object_ref']) && !empty($data['stancer_object_ref'])) {
		dol_syslog("stancerAddSEPAIfNeeded stancer_object_ref exists, try it before : " . $data['stancer_object_ref']);
		$existingSepa = $stancerApi->getSepa($data['stancer_object_ref']);
		if ($existingSepa !== false && isset($existingSepa['created'])) {
			dol_syslog("stancerAddSEPAIfNeeded already exists do not make a duplicate", LOG_DEBUG);
			return $data['stancer_object_ref'];
		}
	} else {
		dol_syslog("stancerAddSEPAIfNeeded stancer_object_ref is empty");
	}

	// Build SEPA data for API
	$sepaApiData = array(
		'iban' => $data['iban'],
		'name' => $societe->name,
		'mandate' => $data['mandate'],
		'date_mandate' => $data['date_mandate']
	);
	if (!empty($data['bic'])) {
		$sepaApiData['bic'] = $data['bic'];
	}

	// IBAN verification via SEPAMail (informational, never blocks creation)
	stancerCheckIBAN($data['iban'], $societe);

	// Create SEPA via API
	$sepaData = $stancerApi->createSepa($sepaApiData);

	if ($sepaData !== false && isset($sepaData['id'])) {
		$sepaID = $sepaData['id'];
		$bic = isset($sepaData['bic']) ? $sepaData['bic'] : '';
		$country = isset($sepaData['country']) ? $sepaData['country'] : '';
		dol_syslog("stancer create SEPA (id #$sepaID; bic=$bic, country=$country) is OK for : " . $societe->name, LOG_DEBUG);
	} else {
		// SEPA may already exist, try to extract ID from error message.
		// Two known Stancer message shapes carry the existing sepa id:
		//   - "... (sepa_xxx) ..."                            (parenthesized)
		//   - "Mandate RUM-... already used for sepa_xxx"     (409, no parens)
		// A single unanchored capture handles both, and stops on the first
		// non-word char (so a trailing ")" is naturally excluded).
		$message = $stancerApi->error;
		$matches = array();
		preg_match('/(sepa_\w+)/', $message, $matches);
		if (is_array($matches) && isset($matches[1])) {
			$sepaID = $matches[1];
			dol_syslog("stancerAddSEPAIfNeeded reusing existing SEPA id from API error: $sepaID (message: $message)", LOG_WARNING);
			// Fetch the existing SEPA data
			$sepaData = $stancerApi->getSepa($sepaID);
		} else {
			// Check for IBAN validation error
			if (strpos($message, 'iban') !== false || strpos($message, 'IBAN') !== false) {
				setEventMessages($langs->trans("ErrorStancer") . " (2) " . $message, [], 'errors');
				dol_syslog("stancerAddSEPAIfNeeded API returns IBAN error", LOG_ERR);
				return -3;
			}
			setEventMessages($langs->trans("ErrorStancer") . " (3) " . $message, [], 'errors');
			dol_syslog("stancer erreur de création du SEPA : " . $message, LOG_ERR);
		}
		dol_syslog("stancer récupération de l'id du sepa : " . $societe->email . ", " . $sepaID, LOG_DEBUG);
	}

	if (empty($sepaID)) {
		dol_syslog("stancer Création du SEPA error : sepa id is empty", LOG_ERR);
		return -4;
	}

	if ($sepaData === null || $sepaData === false) {
		dol_syslog("stancer Création du SEPA error : sepaData is null", LOG_ERR);
		setEventMessages($langs->trans("ErrorStancer") . " (4) " . $langs->trans("StancerReturnsJsonNull"), [], 'errors');
		return -5;
	}

	$label = 'stancer-sepa-tst';
	if (getDolGlobalString('STANCER_IS_PROD', '0') == '1') {
		$label = 'stancer-sepa';
	}

	$dataUpdate = [
		'socid' => $socid,
		'fk_soc' => $socid,
		'bank' => $data['bank'],
		'label' => $label . '_' . date("YmdHi"),
		'stancer_account' => $db->escape($customerID),
		'stancer_object_ref' => $db->escape($sepaID),
		'bic' => isset($sepaData['bic']) ? $sepaData['bic'] : '',
		'last_four' => isset($sepaData['last4']) ? $sepaData['last4'] : '',
		'rum' => isset($sepaData['mandate']) ? $sepaData['mandate'] : $data['mandate'],
		'number' => 0000,
		'proprio' => $societe->name,
		'exp_date_month' => "",
		'exp_date_year' => "",
		'cvn' => null,
		'datec' => dol_now(),
		'iban_prefix' => isset($sepaData['iban']) ? $sepaData['iban'] : $data['iban'],
		'default_rib' => 0,
		'type' => 'ban',
		'entity' => $conf->entity,
		'country_code' => isset($sepaData['country']) ? $sepaData['country'] : '',
		'status' => 1,
	];
	$compPayModeId = stancerAddCompanyPaymentModeifNeeded($dataUpdate);
	if ($compPayModeId < 0) {
		dol_syslog("stancer Création du compte client err ...", LOG_ERR);
		// print "<p>Création du compte client err</p>";
		// dol_print_error($db);
	} else {
		dol_syslog("stancer Création du compte client ok ...", LOG_DEBUG);
	}
	// print json_encode($companypaymentmode);
	return $sepaID;
}

/**
 * switch to 3ds auth
 *
 * @param   int     $socid       socid
 * @param   string  $customerID  customerID
 * @param   array   $cbData      card data array from API
 *
 * @return  array               result
 */
function stancerSwitchTo3DS($socid, $customerID, $cbData)
{
	$stancerApi = StancerApi::getInstance();
	$res = array();
	dol_syslog("stancerSwitchTo3DS for $socid");

	$cardId = isset($cbData['id']) ? $cbData['id'] : '';
	$last4 = isset($cbData['last4']) ? $cbData['last4'] : '';

	$uniq = rand(100, 999);
	$uuid = 'PREAUTH=1.CB=' . $last4 . '.CUS=' . $socid . '.UNIQ=' . $uniq;
	$orderid = 'PREAUTH=' . $last4 . '.UNIQ=' . $uniq;

	$urlretour = DOL_MAIN_URL_ROOT . '/custom/stancer/public/cb.php?s=' . $_SESSION['s'] . "&action=preauth";

	// Build payment data for pre-authorization with 3DS
	$paymentApiData = array(
		'amount' => 50,
		'currency' => 'eur',
		'card' => $cardId,
		'customer' => $customerID,
		'order_id' => $orderid,
		'unique_id' => $uuid,
		'description' => 'Pré-Autorisation CB',
		'capture' => false,
		'auth' => $urlretour,
	);

	dol_syslog("stancer   stancerSwitchTo3DS before API call with urlretour=$urlretour -> " . json_encode($paymentApiData));
	$paymentResult = $stancerApi->createPayment($paymentApiData);
	if ($paymentResult === false) {
		dol_syslog("stancer   stancerSwitchTo3DS stancer API error: " . $stancerApi->error, LOG_WARNING);
		return $res;
	}
	dol_syslog("stancer   stancerSwitchTo3DS result is " . json_encode($paymentResult));

	// Check for auth redirect URL
	if (isset($paymentResult['auth']) && is_array($paymentResult['auth'])) {
		$auth = $paymentResult['auth'];
		dol_syslog("stancer   stancerSwitchTo3DS Payment auth: " . json_encode($auth));
		$redir = isset($auth['redirect_url']) ? $auth['redirect_url'] : '';
		dol_syslog("stancer   stancerSwitchTo3DS redirect_url: " . json_encode($redir));
		dol_syslog("stancer   stancerSwitchTo3DS return_url: " . (isset($auth['return_url']) ? $auth['return_url'] : ''));
		dol_syslog("stancer   stancerSwitchTo3DS status: " . (isset($auth['status']) ? $auth['status'] : ''));
		if (!empty($redir)) {
			$res['redirect'] = $redir;
		}
	} else {
		dol_syslog("stancer   stancerSwitchTo3DS no auth data in response");
	}

	return $res;
}

/**
 * Create CB entry on stancer account if needed
 *
 * @param   int  $socid    dolibarr soc id
 * @param   array  $data   data to use
 *
 * @return  string|int|null  stancer cbID like card_..., a negative int code, or null when no card id was returned
 */
function stancerAddCBIfNeeded($socid, $data)
{
	global $db, $conf, $user, $langs;

	$stancerApi = StancerApi::getInstance();
	dol_syslog("stancerAddCBIfNeeded::enter for socid=$socid");

	$redirect3ds = "";
	$cbID = null;
	$cbData = null;
	$error = 0;

	$societe = new Societe($db);
	$socresult = $societe->fetch($socid);
	if ($socresult <= 0 || empty($data['cbnumber'])) {
		dol_syslog("stancerAddCBIfNeeded error: no socid ($socresult) or data cbnumber empty : " . json_encode($data), LOG_ERR);
		return -1;
	}

	$customerID = stancerAddCustomerIfNeeded($societe);
	if (empty($customerID) || (is_numeric($customerID) && $customerID <= 0)) {
		dol_syslog("stancerAddCBIfNeeded error on stancerAddCustomerIfNeeded, customerID=" . var_export($customerID, true), LOG_ERR);
		return -2;
	}
	dol_syslog("stancerAddCBIfNeeded customerID id $customerID...");

	// Build card data for API. Sanitization (digits-only on number/cvc, lengths,
	// month/year ranges) is centralized in stancerSanitizeCardData() per OpenAPI
	// CardIn schema + observed real-world rules.
	dol_include_once('/stancer/lib/stancer_validators.lib.php');
	$cardApiData = stancerSanitizeCardData($data);
	if (isset($cardApiData['error'])) {
		dol_syslog("stancerAddCBIfNeeded validation error: " . $cardApiData['error'], LOG_ERR);
		return -1;
	}

	// Create card via API
	$cbData = $stancerApi->createCard($cardApiData);
	if ($cbData === false) {
		$message = $stancerApi->error;
		dol_syslog("stancerAddCBIfNeeded createCard error: " . $message, LOG_WARNING);

		// Try to extract existing card ID from error
		$matches = array();
		preg_match('/\((card_\w+)\)/', $message, $matches);
		if (is_array($matches) && isset($matches[1])) {
			$cbID = $matches[1];
			// Fetch existing card data
			$cbData = $stancerApi->getCard($cbID);
			if ($cbData === false) {
				setEventMessages($langs->trans("ErrorStancer") . " (6) " . $message, [], 'errors');
				dol_syslog("stancer erreur de création CB : " . $message, LOG_ERR);
				return -1;
			}
			dol_syslog("stancer récupération de l'id CB : " . $societe->email . ", " . $cbID, LOG_DEBUG);
		} else {
			setEventMessages($langs->trans("ErrorStancer") . " (5) " . $message, [], 'errors');
			dol_syslog("stancerAddCBIfNeeded API error: " . $message, LOG_ERR);
			return -1;
		}
	} else {
		$cbID = isset($cbData['id']) ? $cbData['id'] : null;
		//3DS ?
		$res3DS = stancerSwitchTo3DS($socid, $customerID, $cbData);
		if (isset($res3DS['redirect'])) {
			$redirect3ds = $res3DS['redirect'];
		}
		$last4 = isset($cbData['last4']) ? $cbData['last4'] : '';
		$brand = isset($cbData['brand']) ? $cbData['brand'] : '';
		dol_syslog("stancer create CB (id #$cbID; last4=$last4, brand=$brand) is OK for : " . $societe->name, LOG_DEBUG);
	}

	// Both branches that could leave $cbData at false already returned -1 above,
	// so at this point only the presence of a card id has to be checked.
	if (null !== $cbID) {
		dol_syslog("stancer resCB ok, cbID = $cbID cbData=" . json_encode($cbData), LOG_DEBUG);
		$last4 = isset($cbData['last4']) ? $cbData['last4'] : '';
		$brand = isset($cbData['brand']) ? $cbData['brand'] : '';
		$proprio = isset($cbData['name']) ? $cbData['name'] : '';
		$expiry_month = isset($cbData['exp_month']) ? $cbData['exp_month'] : '';
		$expiry_year = isset($cbData['exp_year']) ? $cbData['exp_year'] : '';
		$card_country = isset($cbData['country']) ? $cbData['country'] : '';


		$label = 'stancer-card-tst';
		if (getDolGlobalString('STANCER_IS_PROD', '0') == '1') {
			$label = 'stancer-card';
		}

		$dataUpdate = [
			'socid' => $socid,
			'fk_soc' => $socid,
			'label' => $label,
			'bank' => $db->escape($brand),
			'stancer_account' => $db->escape($customerID),
			'stancer_object_ref' => $db->escape($cbID),
			'last_four' => $db->escape($last4),
			'number' => 0000,
			'proprio' => $db->escape($proprio),
			'exp_date_month' => $db->escape($expiry_month),
			'exp_date_year' => $db->escape($expiry_year),
			'cvn' => null,
			'datec' => dol_now(),
			'card_type' => $db->escape($brand),
			'type' => 'card',
			'entity' => $conf->entity,
			'country_code' => $db->escape($card_country),
			'status' => 1,
			'default_rib' => 1, //pour qu'elle soit visible dans la liste
		];

		$compPayModeId = stancerAddCompanyPaymentModeifNeeded($dataUpdate);
		if ($compPayModeId < 0) {
			dol_syslog("stancer Création du compte client err ...", LOG_ERR);
			// print "<p>Création du compte client err</p>";
			// dol_print_error($db);
		} else {
			dol_syslog("stancer Création du compte client ok ...", LOG_DEBUG);
		}
		// print json_encode($companypaymentmode);
	}
	//In case of 3DS
	if (!empty($redirect3ds)) {
		$db->commit();
		$db->close();
		dol_syslog("stancerAddCBIfNeeded redirect to 3DS auth : $redirect3ds");
		header("Location: " . $redirect3ds);
		exit;
	}

	return $cbID;
}


/**
 * Mutualisation du code permettant de créer ou mettre à jour un CompanyPaymentModeStancer
 *
 * @param   array  $data  data
 *
 * @return  int         result
 */
function stancerAddCompanyPaymentModeifNeeded($data)
{
	global $db, $conf, $user, $langs;
	dol_syslog("stancerAddCompanyPaymentModeifNeeded::enter");

	$result = 0;
	$stc = new Stancer($db);
	$companypaymentmode = new CompanyPaymentModeStancer($db);

	$res = null;
	if ($data['type'] == "card") {
		//$res = $companypaymentmode->fetch(0, '', 0, '', " AND fk_soc = " . $db->escape($data['socid']) . " AND label LIKE 'stancer-card%' AND last_four='" . $db->escape($data['last_four']) ."'");
		$res = $companypaymentmode->fetch(0, '', 0, '', " AND fk_soc = " . $db->escape($data['socid']) . " AND label LIKE 'stancer-card%'");
	} else {
		$iban = $data['iban'] ?? $data['iban_prefix'];
		$res = $companypaymentmode->fetch(0, '', 0, '', " AND fk_soc = " . $db->escape($data['socid']) . " AND label LIKE 'stancer-sepa%' AND iban_prefix='" . $db->escape($iban) . "'");
	}
	if ($res) {
		dol_syslog("stancer Un compte existe déjà dans llx_societe_rib " . $db->escape($data['label']), LOG_DEBUG);
		$result = $companypaymentmode->id;
		// detection si un champ est modifié -> update nécessaire
		$update = false;
		foreach ($data as $key => $val) {
			if (!empty($val) && (isset($companypaymentmode->$key) && ($companypaymentmode->$key != $val))) {
				$update = true;
				$companypaymentmode->$key = $val;
			}
			if (!empty($val) && empty($companypaymentmode->$key)) {
				$update = true;
				$companypaymentmode->$key = $val;
			}
		}
		if ($update) {
			dol_syslog("stancerAddCompanyPaymentModeifNeeded update is needed ...", LOG_DEBUG);
			$res = $companypaymentmode->update($user);
			//$stc->createEvent($companypaymentmode, "stancer_update_paymentmode", $langs->trans("StancerUpdatePaymentMode"), $langs->trans("StancerUpdatePaymentModeDone", $data['label']));
		}
	} else {
		//set data
		foreach ($data as $key => $val) {
			$companypaymentmode->$key = $val;
		}
		dol_syslog("stancerAddCompanyPaymentModeifNeeded create object ...", LOG_DEBUG);

		$result = $companypaymentmode->create($user);
		//add event on thirdpart card
		if ($result > 0) {
			$stc->createEvent($companypaymentmode, "stancer_new_paymentmode", $langs->trans("StancerNewPaymentMode"), $langs->trans("StancerNewPaymentModeAdded", $data['label']));
		}
	}
	return $result;
}


/**
 * return html code to display online link to for public page where your customer cand add their
 * bank account by themselve
 *
 * @param   int  $socid     socid
 * @param   string  $name   soc name
 *
 * @return  string  output
 */
function stancerShowOnlineIBANLinkForCustomer($socid, $name)
{
	$out = "";
	$url = stancerGetOnlineIBANLinkForCustomer($socid, $name);
	$out .= '<div class="url"><input type="text" id="onlinepaymenturl" class="quatrevingtpercentminusx" value="' . $url . '">';
	$out .= '<a class="" href="' . $url . '" target="_blank" rel="noopener noreferrer">' . img_picto('', 'globe', 'class="paddingleft"') . '</a>';
	$out .= '</div>';
	$out .= ajax_autoselect("onlinepaymenturl");
	return $out;
}

/**
 * return html code to display online link to for public page where your customer cand add their
 * bank account by themselve
 *
 * @param   int  $socid     socid
 * @param   string  $name   soc name
 *
 * @return  string  output
 */
function stancerShowOnlineIBANLinkForEntity($socid, $name)
{
	$url = stancerGetOnlineIBANLinkForCustomer($socid, $name);
	return $url;
}

/**
 * display data for customer (your IBAN is ...)
 *
 * @param   int  	$socid     socid
 * @param   string  $name   soc name
 *
 * @return  string  output
 */
function stancerShowOnlineIBANDataForCustomer($socid, $name)
{
	global $langs, $db;

	$companypaymentmode = new CompanyPaymentModeStancer($db);
	$res = $companypaymentmode->fetch(0, '', 0, '', " AND type = 'ban' AND label LIKE 'stancer-sepa%' AND stancer_object_ref <> '' AND fk_soc = " . ((int) $socid));

	$out = "";
	if ($res) {
		$out .= '<div class="cb">';
		$out .= '<ul>';
		$out .= '<li>' . $langs->trans('ibanBank') . ': ' . $companypaymentmode->bank . '</li>';
		$out .= '<li>' . $langs->trans('ibanBIC') . ': ' . $companypaymentmode->bic . '</li>';
		$out .= '<li>' . $langs->trans('ibanRUM') . ': ' . $companypaymentmode->rum . '</li>';
		$out .= '<li>' . $langs->trans('ibanDateSign') . ': ' . $companypaymentmode->date_rum . '</li>';
		$out .= '</ul>';
		$out .= '</div>';
	}
	return $out;
}


/**
 * return html code to display online link to for public page where your customer cand add their
 * credit card id by themselve
 *
 * @param   int  $socid     socid
 * @param   string  $name   soc name
 *
 * @return  string  output
 */
function stancerShowOnlineCBLinkForCustomer($socid, $name)
{
	$out = "";
	$url = stancerGetOnlineCBLinkForCustomer($socid, $name);
	$out .= '<div class="url"><input type="text" id="onlinepaymenturl" class="quatrevingtpercentminusx" value="' . $url . '">';
	$out .= '<a class="" href="' . $url . '" target="_blank" rel="noopener noreferrer">' . img_picto('', 'globe', 'class="paddingleft"') . '</a>';
	$out .= '</div>';
	$out .= ajax_autoselect("onlinepaymenturl");
	return $out;
}

/**
 * display data for customer (your cb is ...1234, end on 05/2025, clic here to add an other cb)
 *
 * @param   int  	$socid     socid
 * @param   string  $name   soc name
 *
 * @return  string  output
 */
function stancerShowOnlineCBDataForCustomer($socid, $name)
{
	global $langs, $db;

	$companypaymentmode = new CompanyPaymentModeStancer($db);
	$res = $companypaymentmode->fetch(0, '', 0, '', " AND type = 'card' AND label LIKE 'stancer-card%' AND stancer_object_ref <> '' AND fk_soc = " . ((int) $socid));
	$out = "";
	if ($res) {
		$cb = $companypaymentmode;
		$out .= '<div class="cb">';
		$out .= '<ul>';
		$out .= '<li>' . $langs->trans('cbType') . ': ' . $cb->bank . '</li>';
		$out .= '<li>' . $langs->trans('cbNumber') . ': **** **** **** ' . $cb->last_four . '</li>';
		$out .= '<li>' . $langs->trans('cbEOL') . ': ' . $cb->exp_date_month . '/' . $cb->exp_date_year . '</li>';
		$out .= '</ul>';
		$out .= '</div>';
	}
	return $out;
}


/**
 * return cb data
 *
 * @param   int  $socid     socid
 * @param   string  $name   soc name
 *
 * @return  array  output
 */
function stancerGetDataCB($socid, $name)
{
	global $langs, $db;

	$arr = [];
	$cb = new CompanyPaymentModeStancer($db);
	$res = $cb->fetch(0, '', 0, '', " AND type = 'card' AND label LIKE 'stancer-card%' AND stancer_object_ref <> '' AND fk_soc = " . ((int) $socid));
	if ($res) {
		$arr['name'] = $cb->proprio;
		$arr['type'] = $cb->bank;
		$arr['last_four'] = $cb->last_four;
		$arr['exp_date_year'] = $cb->exp_date_year;
		$arr['exp_date_month'] = $cb->exp_date_month;
	}
	return $arr;
}


/**
 * return iban data
 *
 * @param   int  $socid     socid
 * @param   string  $name   soc name
 *
 * @return  array  output
 */
function stancerGetDataIBAN($socid, $name)
{
	global $langs, $db;

	$arr = [];
	$companypaymentmode = new CompanyPaymentModeStancer($db);
	$res = $companypaymentmode->fetch(0, '', 0, '', " AND type = 'ban' AND label LIKE 'stancer-sepa%' AND stancer_object_ref <> '' AND fk_soc = " . ((int) $socid));
	if ($res) {
		$arr['bank'] = $companypaymentmode->bank;
		$arr['bic'] = $companypaymentmode->bic;
		$arr['rum'] = $companypaymentmode->rum;
		$arr['date_rum'] = $companypaymentmode->date_rum;
	}
	return $arr;
}


/**
 * Return string with full Url
 *
 * @param   int		$socid		soc id
 * @param	string	$name		name
 * @return	string				Url string
 */
function stancerGetOnlineIBANLinkForCustomer($socid, $name)
{
	global $conf, $dolibarr_main_url_root;
	$out = $entity = '';
	$tst = "SEPA-$socid-$name-" . getDolGlobalString('PAYMENT_SECURITY_TOKEN');
	$securekey = dol_hash($tst, '1');

	$out = "socid=$socid&action=stancerSEPA&securekey=" . $securekey;
	dol_syslog("stancer stat securekey (IBAN) is from $tst");
	// For multicompany
	if (!empty($out) && !empty($conf->multicompany->enabled)) {
		$out .= "&entity=" . $conf->entity; // Check the entity because we may have the same reference in several entities
		$entity .= "&e=" . $conf->entity; //entity in clear for main dolibarr code
	}
	return DOL_MAIN_URL_ROOT . '/custom/stancer/public/sepa-iban.php?s=' . base64_encode($out) . $entity;
}


/**
 * Return string with full Url
 *
 * @param	int	$socid	    soc id
 * @param   string  $name  		name
 * @return	string				Url string
 */
function stancerGetOnlineCBLinkForCustomer($socid, $name)
{
	global $conf, $dolibarr_main_url_root;
	$out = $entity = '';
	$tst = "CB-$socid-$name-" . getDolGlobalString('PAYMENT_SECURITY_TOKEN');
	$securekey = dol_hash($tst, '1');

	$out = "socid=$socid&action=stancerCB&securekey=" . $securekey;
	dol_syslog("stancer stat securekey is (CB) from $tst");
	// For multicompany
	if (!empty($out) && !empty($conf->multicompany->enabled)) {
		$out .= "&entity=" . $conf->entity; // Check the entity because we may have the same reference in several entities
		$entity .= "&e=" . $conf->entity; //entity in clear for main dolibarr code
	}
	return DOL_MAIN_URL_ROOT . '/custom/stancer/public/cb.php?s=' . base64_encode($out) . $entity;
}
