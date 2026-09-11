<?php
/* Copyright (C) 2001-2005 Rodolphe Quiedeville <rodolphe@quiedeville.org>
 * Copyright (C) 2004-2015 Laurent Destailleur  <eldy@users.sourceforge.net>
 * Copyright (C) 2005-2012 Regis Houssin        <regis.houssin@inodbox.com>
 * Copyright (C) 2015      Jean-François Ferry	<jfefe@aternatik.fr>
 * Copyright (C) 2022-2023 Eric Seigne			<eric.seigne@cap-rel.fr>
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
 *	\file       stancer/stancer_thirdparty.php
 *	\ingroup    stancer
 *	\brief      Home page of stancer top menu
 */

// Load Dolibarr environment
$res = 0;
// Try main.inc.php into web root known defined into CONTEXT_DOCUMENT_ROOT (not always defined)
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
// Try main.inc.php into web root detected using web root calculated from SCRIPT_FILENAME
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = (string) realpath(__FILE__);
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
if (!$res) {
	die("Include of main fails");
}


dol_include_once('/stancer/lib/stancer.lib.php');

// Load translation files required by the page
$langs->loadLangs(array("stancer@stancer"));

$action = GETPOST('action', 'aZ09');

$max = 5;
$now = dol_now();

// Security check - Protection if external user
// Cast explicitly: GETPOST() is declared as returning string|array, so every
// downstream call expecting an int would otherwise be a type mismatch.
$socid = (int) GETPOST('socid', 'int');
if (isset($user->socid) && $user->socid > 0) {
	$socid = (int) $user->socid;
}

$societe = new Societe($db);
$socresult = $societe->fetch($socid);

if (empty($action) && empty($objid)) {
	$action = 'view';
}

// There is several ways to check permission.
// Set $enablepermissioncheck to 1 to enable a minimum low level of checks
$enablepermissioncheck = 1;
if ($enablepermissioncheck) {
	$permissiontoread = $user->hasRight('stancer', 'read');
	$permissiontoadd = $user->hasRight('stancer', 'write');
	$permissiontodelete = 0; // No delete permission defined in this module
} else {
	$permissiontoread = 1;
	$permissiontoadd = 1;
	$permissiontodelete = 0;
}

// Security check (enable the most restrictive one)
if ($user->socid > 0) {
	accessforbidden();
}
//if ($user->socid > 0) accessforbidden();
//$socid = 0; if ($user->socid > 0) $socid = $user->socid;
//$isdraft = (($object->status == $object::STATUS_DRAFT) ? 1 : 0);
//restrictedArea($user, $object->module, 0, $object->table_element, $object->element, 'fk_soc', 'rowid', $isdraft);
if (!isModEnabled("stancer")) {
	accessforbidden('Module stancer not enabled');
}
if (!$permissiontoread) {
	accessforbidden();
}

// M4: every write action (create/delete SEPA mandate, push a RIB to Stancer,
// take a payment, refresh/delete the Stancer account) must require the write
// permission. Guard them centrally so a read-only user or a forged request
// cannot elevate read -> write. Log the refusal (no silent failure).
$stancerWriteActions = array('stancertakepayment', 'add', 'addsepa', 'deletesepa', 'refreshStancerAccount', 'deleteStancerAccount');
if (in_array($action, $stancerWriteActions, true) && !$permissiontoadd) {
	dol_syslog("stancer_thirdparty: write action '" . $action . "' denied for user " . (int) $user->id . " without write permission", LOG_WARNING);
	accessforbidden();
}

/*
 * Actions
 */

if ($action == "stancertakepayment") {
	// #6: make payment of unpaid invoices for that customer (like sellyoursaas)
	// List of unpaid invoices

	//Clic on pay all remaining unpaid invoices on cb or sepa list
	// Define environment of payment modes
	$servicestatus = getDolGlobalString('STANCER_IS_PROD', '0');

	dol_include_once('/stancer/class/stancer.class.php');
	$stancer = new Stancer($db);

	include_once DOL_DOCUMENT_ROOT.'/societe/class/companypaymentmode.class.php';
	$companypaymentmode = new CompanyPaymentModeStancer($db);
	$res = $companypaymentmode->fetch((int) GETPOST('companymodeid', 'int'));     // Read into llx_societe_rib

	if ($companypaymentmode->id > 0) {
		$result = $stancer->doTakePaymentStancer(0, 0, $socid);
		if ($result > 0) {
			$error = $stancer->error . "::" . $stancer->output;
			$errors = $stancer->errors;
			setEventMessages($error, $errors, 'errors');
		} else {
			setEventMessages($langs->trans("PaymentDoneOn", $stancer->output), [], 'mesgs');
		}
	} else {
		$error = 'Failed to fetch company payment mode for id '.GETPOST('companymodeid', 'int');
		setEventMessages($error, [], 'errors');
	}
}

if (getDolGlobalString('STANCER_PUBLIC_IBAN_PAGE', '') != '' && getDolGlobalString('STANCER_EMAIL_DPO', '') == '') {
	setEventMessages($langs->trans("ErrorStancerDPOmissing"), [], 'warnings');
}


// None
// Set url to go back after a create successfull
$backtopage = dol_buildpath('/stancer/stancer_thirdparty.php', 1).'?socid='.$socid;

// No actions_addupdatedelete here: this page has no CRUD object of its own

/*
 * View
 */

$form = new Form($db);
$formfile = new FormFile($db);

llxHeader("", $langs->trans("StancerArea"));

$head = societe_prepare_head($societe);

//print load_fiche_titre($langs->trans("StancerArea"), '', 'stancer.png@stancer');
print dol_get_fiche_head($head, 'tabStancer', $langs->trans("ThirdParty"), -1, 'company');

print '<div class="fichecenter">';


if ($action == "add") {
	$customerIDadded = stancerAddCustomerIfNeeded($societe);
	// The function returns the cust_xxx on success, a negative code when the
	// thirdparty data is not usable, and null when the API call failed. Without
	// this feedback the user only sees the same button again and thinks the
	// click did nothing.
	if (is_string($customerIDadded) && $customerIDadded != '') {
		setEventMessages($langs->trans("StancerAccountReady", $customerIDadded), [], 'mesgs');
	} elseif ($customerIDadded === -10) {
		dol_syslog("stancer_thirdparty: cannot create Stancer customer for socid=" . ((int) $socid) . ", no email and no international phone", LOG_WARNING);
		setEventMessages($langs->trans("StancerCompanyMailOrPhone"), [], 'errors');
	} elseif ($customerIDadded === -12) {
		dol_syslog("stancer_thirdparty: cannot create Stancer customer for socid=" . ((int) $socid) . ", thirdparty name is empty", LOG_WARNING);
		setEventMessages($langs->trans("StancerCompanyNameMissing"), [], 'errors');
	} else {
		dol_syslog("stancer_thirdparty: Stancer customer creation failed for socid=" . ((int) $socid) . ", see previous stancer log lines", LOG_ERR);
		setEventMessages($langs->trans("StancerAccountCreationFailed"), [], 'errors');
	}
}

if ($action == "addsepa") {
	$data = [
		'iban' => GETPOST('iban', 'alpha'),
		'bic' => GETPOST('bic', 'alpha'),
		'mandate' => GETPOST('mandate', 'alpha'),
		'date_mandate' => GETPOST('date_mandate', 'alpha')
	];
	stancerAddSEPAIfNeeded($socid, $data);
}

if ($action == "deletesepa") {
	$stancer_object_ref = GETPOST('stancer_object_ref', 'alpha');
	if (is_array($stancer_object_ref)) {
		$stancer_object_ref = reset($stancer_object_ref);
	}
	stancerDeleteSEPA($socid, $stancer_object_ref, GETPOST('companyPaymentModeID', 'alpha'));
}

if ($action == "refreshStancerAccount") {
	//actualise les comptes sepa & cb issus de stancer
	$companypaymentmode = new CompanyPaymentModeStancer($db);
	$res = $companypaymentmode->fetch(0, '', 0, '', " AND label LIKE 'stancer-card%' AND stancer_account <> '' AND fk_soc = ".((int) $socid));
	$stancerAccountOk = false;
	// print json_encode($companypaymentmode);
	if ($res > 0) {
		$customerID = $companypaymentmode->stancer_account;
	}
}
if ($action == "deleteStancerAccount") {
	//actualise les comptes sepa & cb issus de stancer
	$companypaymentmode = new CompanyPaymentModeStancer($db);
	$res = $companypaymentmode->fetch(0, '', 0, '', " AND label LIKE 'stancer-card%' AND stancer_account <> '' AND fk_soc = ".((int) $socid));
	// print json_encode($companypaymentmode);
	if ($res > 0) {
		$companypaymentmode->delete($user);
	}
}



/* BEGIN MODULEBUILDER DRAFT MYOBJECT */
// Draft MyObject
if (isModEnabled('stancer') && $user->hasRight('stancer', 'read')) {
	$langs->loadLangs(array("stancer@stancer"));

	print "<h2>" . $langs->trans("StancerAccount") . "</h2>";
	$companypaymentmode = new CompanyPaymentModeStancer($db);
	$res = $companypaymentmode->fetch(0, '', 0, '', " AND label LIKE 'stancer-card%' AND stancer_account <> '' AND fk_soc = ".((int) $socid));
	$stancerAccountOk = false;
	// print json_encode($companypaymentmode);
	if ($res > 0) {
		$stancerAccountOk = true;
		print "<ul>\n";
		print "  <li>" . $langs->trans("Name") . ": " . $companypaymentmode->proprio . "</li>\n";
		print "  <li>" . $langs->trans("DateCreation") . ": " . dol_print_date($companypaymentmode->datec) . "</li>\n";
		print "  <li>" . $langs->trans("StancerAccountID") . ": <a href='https://manage.stancer.com/fr/details-du-clients?id=" . trim($companypaymentmode->stancer_account, '"') . "' target='_blank'>" . trim($companypaymentmode->stancer_account, '"') . "</a></li>\n";
		print "  <li><a href='".$_SERVER["PHP_SELF"]."?socid=$socid&action=deleteStancerAccount&stancerid=" . trim($companypaymentmode->stancer_account, '"') . "&token=" . newToken()."'>" . $langs->trans("StancerAccountDeleteLink") ."</a></li>\n";
		// print "  <li><a href='".$_SERVER["PHP_SELF"]."?socid=$socid&action=refreshStancerAccount&stancerid=" . trim($companypaymentmode->stancer_account, '"') . "'>" . $langs->trans("StancerForceRefreshAccount") ."</a></li>\n";
		// print "  <li>" . $companypaymentmode-> . "</li>\n";
		// print "  <li>" . $companypaymentmode-> . "</li>\n";
		// print "  <li><a href='https://payment.stancer.com/" . stancer_get_public_key() . "/" . $pid . "15?lang=fr'>PayLink</a></li>\n";
		print "</ul>\n";
	} else {
		//Verifications de base: il faut un num de tel format +... ou une adresse mail
		$error = 0;
		$message = array();
		if (substr($societe->phone, 0, 1) != '+') {
			$error++;
		}
		if (empty($societe->email)) {
			$error++;
		}
		if ($error > 1) {
			print "<p>" . $langs->trans("StancerCompanyMailOrPhone") . "</p>";
		} else {
			print '<form name="addstancer" id="addstancer" action="'.$_SERVER["PHP_SELF"].'?socid='.$socid.'" method="POST">';
			print '<input type="hidden" name="token" value="'.newToken().'">';
			print '<input type="hidden" name="action" value="add">';
			print '<input class="button button-save" type="submit" value="' . $langs->trans("CreateAccountOnStancer") . '">';
			print '</form>';
		}
	}

	if ($stancerAccountOk) {
		$resStancer = null;
		print "<h2>" . $langs->trans("StancerSEPA") . "</h2>";
		$companypaymentmode = new CompanyPaymentModeStancer($db);
		// $res = $companypaymentmode->fetch(0, '', 0, '', " AND type = 'ban' AND label LIKE 'stancer-sepa%' AND fk_soc = ".((int) $socid));
		// if ($res) {
		//     print "<ul>\n";
		//     // print "  <li>" . $companypaymentmode->label . "</li>\n";
		//     print "  <li>" . $langs->trans("StancerSEPAID") . ": <a href=https://manage.stancer.com/fr/details-du-clients?id=" . trim($companypaymentmode->stancer_account, '"') . " target='_blank'>" . trim($companypaymentmode->stancer_account, '"') . "</a></li>\n";
		//     // print "  <li>" . $companypaymentmode-> . "</li>\n";
		//     // print "  <li>" . $companypaymentmode-> . "</li>\n";
		//     // print "  <li><a href='https://payment.stancer.com/" . stancer_get_public_key() . "/" . $pid . "15?lang=fr'>PayLink</a></li>\n";
		//     print "</ul>\n";
		// } else {
		$rib_list = $societe->get_all_rib();

		// print json_encode($rib_list); exit;
		if (is_array($rib_list)) {
			print '<div class="div-table-responsive-no-min">'; // You can use div-table-responsive-no-min if you don't need reserved height for your table
			print '<table class="liste centpercent">';

			print '<tr class="liste_titre">';
			print_liste_field_titre("LabelRIB");
			print_liste_field_titre("Bank");
			print_liste_field_titre("IBAN");
			print_liste_field_titre("BIC");
			print_liste_field_titre("StancerID");
			if (!empty($conf->prelevement->enabled)) {
				print_liste_field_titre("RUM");
				print_liste_field_titre("DateRUM");
				print_liste_field_titre("WithdrawMode");
			}
			print_liste_field_titre('', '', '', '', '', '', '', '', 'center ');
			print "</tr>\n";

			$messageAlreadyDisplayed = false;
			foreach ($rib_list as $rib) {
				$iban = $rib->iban_prefix;
				if (empty($iban) && isset($rib->iban)) {
					$iban = $rib->iban;
				}
				$companypaymentmode = new CompanyPaymentModeStancer($db);
				$resRib = $companypaymentmode->fetch(0, '', 0, '', " AND type = 'ban' AND iban_prefix = '".$iban."' ORDER BY datec DESC");

				//afficher uniquement les sepa stancer (prefixe du label)
				$stancerSEPAisPresent = false;
				if ($resRib && !empty($companypaymentmode->stancer_object_ref)) {
					$stancerSEPAisPresent = true;
				}

				//Masquer les autres rib ?
				if ($stancerSEPAisPresent && substr($rib->label, 0, 12) != 'stancer-sepa') {
					continue;
				}

				if (!$messageAlreadyDisplayed) {
					//#11 : ajouter un mandat existant ...
					print '<div class="info"><span class="fa fa-info"> </span> <span class="clear"> ' . $langs->transnoentities("StancerAddManualExistingMandate") . '</span></div>';
					$messageAlreadyDisplayed = true;
				}
				// print json_encode($rib);exit;
				$companypaymentmode = new CompanyPaymentModeStancer($db);
				$resStancer = $companypaymentmode->fetch(0, '', 0, '', " AND type = 'ban' AND label LIKE 'stancer-sepa%' AND iban_prefix = '".$iban."'");

				//petite verif, s'il manque le bic *et* que c'est un stancer on peut actualiser l'information
				if (substr($rib->label, 0, 12) == 'stancer-sepa' && empty($rib->bic) && $resStancer) {
					$stancerApi = StancerApi::getInstance();
					$sepaData = $stancerApi->getSepa($companypaymentmode->stancer_object_ref);
					if ($sepaData !== false) {
						$bic = isset($sepaData['bic']) ? $sepaData['bic'] : '';
						if (!empty($bic)) {
							$rib->bic = $bic;
							$companypaymentmode->bic = $bic;
							$companypaymentmode->update($user);
						}
					}
				}
				$link = $companypaymentmode->stancer_object_ref;
				if ($resStancer && !empty($companypaymentmode->stancer_account)) {
					//check if sepa exists on stancer
					$data = [
						// iban_prefix is the only IBAN column of llx_societe_rib, and the only IBAN
						// entry of CompanyPaymentMode::$fields, so it is the only one fetch()
						// loads from the database. $iban is a deprecated alias (@deprecated
						// @see $iban_prefix from Dolibarr 18 to 21, not even declared before 18)
						// that fetch() copies from iban_prefix, so the former "?? iban_prefix"
						// fallback could never yield another value on any supported version.
						'iban' => $companypaymentmode->iban_prefix,
						'bic' => $companypaymentmode->bic,
						'mandate' => $companypaymentmode->rum,
						'date_mandate' => $companypaymentmode->date_rum ?? dol_now(),
						'stancer_object_ref' => $companypaymentmode->stancer_object_ref,
					];
					stancerAddSEPAIfNeeded($socid, $data);

					//juste le lien vers le compte stancer
					$link = "<a href='https://manage.stancer.com/fr/details-du-clients?id=" . trim($companypaymentmode->stancer_account, '"') . "' target='_blank'>" . $companypaymentmode->stancer_object_ref . "</a>";
				}

				print '<tr class="oddeven">';

				// Label
				print '<td>' . $rib->label .'</td>';

				// Bank name
				print '<td>'.$rib->bank.'</td>';
				// IBAN
				print '<td>'.$rib->iban.'</td>';
				// BIC
				print '<td>'.$rib->bic.'</td>';
				// ID Stancer
				print '<td>'.$link.'</td>';

				if (!empty($conf->prelevement->enabled)) {
					// RUM
					//print '<td>'.$prelevement->buildRumNumber($object->code_client, $rib->datec, $rib->id).'</td>';
					print '<td>'.$rib->rum.'</td>';

					print '<td>'.dol_print_date($rib->date_rum, 'day').'</td>';

					// FRSTRECUR
					print '<td>'.$rib->frstrecur.'</td>';
				}

				//stancer
				print '<td class="center">';
				// Stancer - vérifier si un compte n'existe pas déjà
				if ($resStancer && !empty($companypaymentmode->stancer_object_ref)) {
					//delete sepa
					$stancer_object_ref = $companypaymentmode->stancer_object_ref;
					print '<form name="deletestancersepa_' . $stancer_object_ref . '" id="deletestancersepa_' . $stancer_object_ref . '" action="'.$_SERVER["PHP_SELF"].'?socid='.$socid.'" method="POST">';
					print '<input type="hidden" name="token" value="'.newToken().'">';
					print '<input type="hidden" name="action" value="deletesepa">';
					print '<input type="hidden" name="companyPaymentModeID" value="'.$companypaymentmode->id.'">';
					print '<input type="hidden" name="stancer_object_ref" value="'.$stancer_object_ref.'">';
					// Button
					$genbutton = '<input class="button" id="deletebutton" name="'.$stancer_object_ref.'_deleteebutton"';
					$genbutton .= ' type="submit" value="'.$langs->trans("DeleteSEPAOnStancer").'"';
					$genbutton .= '>';
					print $genbutton;
					print '</form>';
				} else {
					//add sepa
					print '<form name="addstancersepa" id="addstancersepa" action="'.$_SERVER["PHP_SELF"].'?socid='.$socid.'" method="POST">';
					print '<input type="hidden" name="token" value="'.newToken().'">';
					print '<input type="hidden" name="action" value="addsepa">';
					print '<input type="hidden" name="iban" value="'.$rib->iban.'">';
					print '<input type="hidden" name="bic" value="'.$rib->bic.'">';
					print '<input type="hidden" name="mandate" value="'.$rib->rum.'">';
					print '<input type="hidden" name="date_mandate" value="'.$rib->date_rum.'">';
					$forname = 'builddocrib'.$rib->id;
					// Button
					$genbutton = '<input class="button buttongen" id="'.$forname.'_generatebutton" name="'.$forname.'_generatebutton"';
					$genbutton .= ' type="submit" value="'.$langs->trans("CreateSEPAOnStancer").'"';
					$genbutton .= '>';
					print $genbutton;
					print '</form>';
				}

				print '</td>';

				// Edit/Delete - eric plus tard
				// print '<td class="right nowraponall">';
				// if ($permissiontoaddupdatepaymentinformation) {
				// 	print '<a class="editfielda" href="'.$_SERVER["PHP_SELF"].'?socid='.$object->id.'&id='.$rib->id.'&action=edit">';
				// 	print img_picto($langs->trans("Modify"), 'edit');
				// 	print '</a>';

				// 	print '<a class="marginleftonly" href="'.$_SERVER["PHP_SELF"].'?socid='.$object->id.'&id='.$rib->id.'&action=delete&token='.newToken().'">';
				// 	print img_picto($langs->trans("Delete"), 'delete');
				// 	print '</a>';
				// }
				// print '</td>';

				print '</tr>';
			}
			print '</table>';

			//aucun rib, le client peut le faire tout seul...
			if (@count($rib_list) == 0 || getDolGlobalString('STANCER_PUBLIC_IBAN_PAGE_FORCE')) {
				if (getDolGlobalString('STANCER_PUBLIC_IBAN_PAGE', '') != '') {
					print '<p>';
					if (@count($rib_list) == 0) {
						print $langs->trans("NoBANRecord") .'.<br />';
						print $langs->trans("StancerLinkFoCustomerIBAN");
					} else {
						print $langs->trans("StancerLinkFoCustomerIBANforce");
						// any unpaid invoice? issue a grouped direct debit?
						//TODO
						// $outstandingOpened = stancerGetOutstandingBills($socid, 0, "PRE");
						// $companypaymentmode = new CompanyPaymentModeStancer($db);
						// $resStancer = $companypaymentmode->fetch(0, '', 0, '', " AND type = 'ban' AND label LIKE 'stancer-sepa%' AND iban_prefix = '".$iban."'");

						// $amount = price($outstandingOpened, 1, $langs, 1, -1, -1, $conf->currency);
						// print '<p>DueAmount : ' . $amount . ", ";
						// print '<a class="stancertakepayment" href="' . $_SERVER["PHP_SELF"] . '?socid=' . $socid . '&token='.newToken().'&action=stancertakepayment&companymodeid=' . $companypaymentmode->id . '">' . $langs->trans("StancerPayBalanceAmountSEPA", $amount) . '</a>';
						// print '</p>';
					}
					print stancerShowOnlineIBANLinkForCustomer($societe->id, $societe->name);
					print '</p>';
				} else {
					print '<div class="warning"><span class="fa fa-warning"> </span> <span class="clear"> ' . $langs->transnoentities("StancerPublicIBANPageDisabled", "<a href='./admin/setup.php' target='_blank'>", "</a>") . '</span></div>';
				}

				if (getDolGlobalString('STANCER_MANDATE_AUTO_UPTOSIGN', '') != '') {
					print '<p>'.$langs->transnoentitiesnoconv("NiceUptoSignIsEnabled").'</p>';
					// uptosign is an optional third party module, loaded at runtime only when
					// installed, so its class is unknown to the static analysers.
					if (dol_include_once('/uptosign/class/uptosignCore.class.php')) {
						// @phan-suppress-next-line PhanUndeclaredClassMethod
						$uptosignCore = new uptosignCore(['db' => $db]);

						//sepamandate object does not exists, so we use "contract" as document type to find who can sign
						// @phan-suppress-next-line PhanUndeclaredClassMethod
						$list_of_potential_signers = $uptosignCore->whoCanSign($societe->id, 'contrat', 'CustomerSign');
						if (is_countable($list_of_potential_signers)) {
							if (@count($list_of_potential_signers) == 0) {
								print '<div class="warning"><span class="fa fa-warning"> </span> <span class="clear"> ' . $langs->trans("StancerUptoSignNoSigner") . '</span></div>';
							} elseif (@count($list_of_potential_signers) > 1) {
								print '<div class="warning"><span class="fa fa-warning"> </span> <span class="clear"> ' . $langs->trans("StancerUptoSignNotOnlyOneSigner") . '</span></div>';
							}
						}
					}
				} else {
					print '<p>'.
					$langs->transnoentitiesnoconv("YouCanAccelSignWithUptoSign", "<a href='https://www.dolistore.com/fr/modules/1656-uptosign---signature---lectronique-eidas.html'>", "</a>").
					'</p>';
				}
			}

			print '</div>';
			// }
		}

		print "<h2>" . $langs->trans("StancerCBAccount") . "</h2>";
		$companypaymentmode = new CompanyPaymentModeStancer($db);
		$res = $companypaymentmode->fetch(0, '', 0, '', " AND type = 'card' AND label LIKE 'stancer-card%' AND stancer_object_ref <> '' AND fk_soc = ".((int) $socid));
		if ($res) {
			$cb = $companypaymentmode;

			$outstandingOpened = stancerGetOutstandingBills($socid, 0, "CB");
			// also returns invoices whose payment mode is not a card
			// $tmp = $societe->getOutstandingBills('customer', 0);
			// $outstandingOpened = $tmp['opened'];

			print '<div class="div-table-responsive-no-min">'; // You can use div-table-responsive-no-min if you don't need reserved height for your table
			print '<table class="liste centpercent">';

			print '<tr class="liste_titre">';
			print_liste_field_titre("LabelRIB");
			print_liste_field_titre("Type");
			print_liste_field_titre("CardNumber");
			print_liste_field_titre("Owner");
			print_liste_field_titre("CardExpiry");
			print_liste_field_titre("CardType");
			print_liste_field_titre("Country");
			print_liste_field_titre("Actions");
			print_liste_field_titre('', '', '', '', '', '', '', '', 'center ');
			print "</tr>\n";

			//L'objectif est de ne pas afficher les autres rib si celui qui est specifique stancer existe
			print '<tr class="oddeven">';
			// Label
			print '<td>'.$cb->label.'</td>';
			// Bank name
			print '<td>'.$cb->bank.'</td>';

			print '<td> **** **** **** '.$cb->last_four.'</td>';

			print '<td>'.$cb->proprio.'</td>';

			print '<td>'.$cb->exp_date_month . '/' . $cb->exp_date_year .'</td>';

			print '<td>'.$cb->card_type.'</td>';

			print '<td>'.$cb->country_code.'</td>';

			//fix #6 add a link to pay all waiting invoices ?
			//Si encours > 0
			print '<td class="center">';
			$amount = price($outstandingOpened, 1, $langs, 1, -1, -1, $conf->currency);
			print '<a class="stancertakepayment" href="' . $_SERVER["PHP_SELF"] . '?socid=' . $socid . '&token='.newToken().'&action=stancertakepayment&companymodeid=' . $cb->id . '">' . $langs->trans("StancerPayBalanceAmountCB", $amount) . '</a>';
			print '</td>';

			//stancer
			print '<td>';
			// Stancer - vérifier si un compte n'existe pas déjà
			if ($resStancer && !empty($companypaymentmode->stancer_account)) {
				//juste le lien vers le compte stancer
				print "<a href=https://manage.stancer.com/fr/details-du-clients?id=" . trim($companypaymentmode->stancer_account, '"') . "&token=".newToken()." target='_blank'>" . $langs->trans("StancerAccountID") . "</a>";
			}
			print '</td>';

			// Edit/Delete - eric plus tard
			// print '<td class="right nowraponall">';
			// if ($permissiontoaddupdatepaymentinformation) {
			// 	print '<a class="editfielda" href="'.$_SERVER["PHP_SELF"].'?socid='.$socid.'&id='.$cb->id.'&action=edit">';
			// 	print img_picto($langs->trans("Modify"), 'edit');
			// 	print '</a>';

			// 	print '<a class="marginleftonly" href="'.$_SERVER["PHP_SELF"].'?socid='.$socid.'&id='.$cb->id.'&action=delete&token='.newToken().'">';
			// 	print img_picto($langs->trans("Delete"), 'delete');
			// 	print '</a>';
			// }
			// print '</td>';

			print '</tr>';
			print '</table>';

			// print json_encode($companypaymentmode);
			// print "<ul>\n";
			// print "  <li>" . $companypaymentmode->label . "</li>\n";
			// print "  <li>" . $langs->trans("StancerSEPAID") . ": <a href=https://manage.stancer.com/fr/details-du-clients?id=" . trim($companypaymentmode->stancer_account, '"') . " target='_blank'>" . trim($companypaymentmode->stancer_account, '"') . "</a></li>\n";
			// // print "  <li>" . $companypaymentmode-> . "</li>\n";
			// // print "  <li>" . $companypaymentmode-> . "</li>\n";
			// // print "  <li><a href='https://payment.stancer.com/" . stancer_get_public_key() . "/" . $pid . "15?lang=fr'>PayLink</a></li>\n";
			// print "</ul>\n";
		} else {
			// pas encore de CB connue de stancer associée à ce client
			print '<p>'.
			$langs->trans("StancerNoCBRecord") .'.<br />'.
			$langs->trans("StancerLinkFoCustomerCB") .
			'</p>';

			if (getDolGlobalString('STANCER_PUBLIC_CB_PAGE', '') != '' && getDolGlobalString('STANCER_ENABLE_CB')) {
				print "<p>" . stancerShowOnlineCBLinkForCustomer($societe->id, $societe->name) . "</p>";
			} else {
				print '<div class="warning"><span class="fa fa-warning"> </span> <span class="clear"> ' . $langs->transnoentities("StancerPublicCBpageDisabled", "<a href='./admin/setup.php' target='_blank'>", "</a>") . '</span></div>';
			}
		}
	}
	// print $sql;
	// $resql = $db->query($sql);
	// if ($resql) {
	//     $total = 0;
	//     $num = $db->num_rows($resql);

	//     print '<table class="noborder centpercent">';
	//     print '<tr class="liste_titre">';
	//     print '<th colspan="3">'.$langs->trans("StancerAccount").($num ? '<span class="badge marginleftonlyshort">'.$num.'</span>' : '').'</th></tr>';

	//     $var = true;
	//     if ($num > 0) {
	//         $i = 0;
	//         while ($i < $num) {
	//             $obj = $db->fetch_object($resql);
	//             print '<tr class="oddeven"><td class="nowrap">';
	//             print json_encode($obj);
	//             // print $obj->ref;
	//             print '</td>';
	//             print '<td class="nowrap">';
	//             print '</td>';
	//             $i++;
	//         }
	//         if ($total>0) {
	//             print '<tr class="liste_total"><td>'.$langs->trans("Total").'</td><td colspan="2" class="right">'.price($total)."</td></tr>";
	//         }
	//     } else {
	//         print '<form name="addstancer" id="addstancer" action="'.$_SERVER["PHP_SELF"].'?socid='.$socid.'" method="POST">';
	//         print '<input type="hidden" name="token" value="'.newToken().'">';
	//         print '<input type="hidden" name="action" value="add">';
	//         print '<tr class="oddeven"><td colspan="3">'.$langs->trans("NoAccount").'<br />';
	//         print '<input class="button button-save" type="submit" value="' . $langs->trans("CreateAccountOnStancer") . '">';
	//         print '</td></tr>';
	//     }
	//     print "</table><br>";

	//     $db->free($resql);
	// } else {
	//     dol_print_error($db);
	// }
}
/*END MODULEBUILDER DRAFT MYOBJECT */

//Liste des liens actifs

// print "<p>Montant : <input type='text' name='amount' value='50'></p>";
// print "<p>TAG : <input type='text' name='tag' value=''></p>";
// print "<p>Description : <input type='text' name='description' value=''></p>";

// $liste = ['paym_MN5qekP8UBzlEN8ohPS8MF1z','paym_twkgSkw6OgFj0xsKHSqVgYXx','paym_OVWc9bpCZY1MIP9rqELRG4su'];

// $object = new Facture($db);
// $object->fetch(1259);
// $tag = (empty($parameters['tag']) ? GETPOST("ref", 'alpha') : $parameters['tag']);
// if (empty($tag)) {
// 	$tag = stancerMakeTAG($object);
// }
// $args = base64_encode('tag='.$tag.'&source='.$source.'&ref='.$object->ref.'&securekey='.$securekey);
// $urlretour = DOL_MAIN_URL_ROOT.'/custom/stancer/public/paymentback.php?s='.$args;

// print "<p>url retour is $urlretour</p>";
// // exit;

// foreach ($liste as $l) {
// 	print "update paiement $l ...<br />";
//     $payment = new Stancer\Payment($l);
//     // $payment->setAmount(5000);
//     if ($payment->isNotSuccess()) {
//         // $payment->setDescription("Complément facture ref FA2302-1003");
//         // $payment->setOrderId("INV=1259.CUS=519");
// 		// $payment->setReturnUrl($urlretour);
//         $payment->setAuth(true);
//         // $payment->setStatus(Stancer\Payment\Status::CANCELED);
//         $res = $payment->send();
//     }
//     print json_encode($res);
// }
// print json_encode($payment);



// $customer = new Stancer\Customer('cust_tUDFhV7gBJDR6K8D2xn7E3oa');

// $payment = new Stancer\Payment();
// $payment->setAmount(5000);
// $payment->setCurrency('eur');
// $payment->setCustomer($customer);
// $payment->setDescription('Paiement partiel Facture FA2302-1003/2');
// $res = $payment->send();
// print json_encode($res);

// $url = "https://payment.stancer.com/" . stancer_get_public_key() . "/" . $res . "?lang=fr";
// print "<p>Lien de paiement : " .$url. "</p>";

// print "<h2>" . $langs->trans("Divers") . "</h2>";

print '</div><div class="fichetwothirdright">';


// $NBMAX = $conf->global->MAIN_SIZE_SHORTLIST_LIMIT;
// $max = $conf->global->MAIN_SIZE_SHORTLIST_LIMIT;



print '</div>';

// End of page
llxFooter();
$db->close();
