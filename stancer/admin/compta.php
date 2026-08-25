<?php
/* Copyright (C) 2023-2025 Eric Seigne <eric.seigne@cap-rel.fr>
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
 * \file    stancer/admin/compta.php
 * \ingroup stancer
 * \brief   Stancer accounting configuration and gap regularization page.
 */

// Load Dolibarr environment
$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
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
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

global $langs, $user, $db, $conf;

// Libraries
require_once DOL_DOCUMENT_ROOT."/core/lib/admin.lib.php";
require_once DOL_DOCUMENT_ROOT.'/core/class/html.formaccounting.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.formcompany.class.php';
require_once DOL_DOCUMENT_ROOT.'/accountancy/class/accountingjournal.class.php';
require_once DOL_DOCUMENT_ROOT.'/accountancy/class/accountingaccount.class.php';
require_once DOL_DOCUMENT_ROOT.'/accountancy/class/bookkeeping.class.php';
dol_include_once('/stancer/lib/stancer.lib.php');

// Translations
$langs->loadLangs(array("admin", "stancer@stancer", "accountancy"));

// Access control
if (!$user->admin) {
	accessforbidden();
}

// Parameters
$action = GETPOST('action', 'aZ09');
$backtopage = GETPOST('backtopage', 'alpha');

$error = 0;

/*
 * Actions
 */

if ($action == 'update') {
	$journal_od = GETPOST('STANCER_COMPTA_JOURNAL_OD', 'int');
	$account_ecart = GETPOST('STANCER_COMPTA_ACCOUNT_ECART', 'alpha');
	$seuil_ecart = GETPOST('STANCER_COMPTA_SEUIL_ECART', 'alpha');
	$supplier_id = GETPOST('STANCER_COMPTA_SUPPLIER_ID', 'int');

	if ($journal_od > 0) {
		dolibarr_set_const($db, 'STANCER_COMPTA_JOURNAL_OD', $journal_od, 'chaine', 0, '', $conf->entity);
	}
	if (!empty($account_ecart)) {
		dolibarr_set_const($db, 'STANCER_COMPTA_ACCOUNT_ECART', $account_ecart, 'chaine', 0, '', $conf->entity);
	}
	if (!empty($seuil_ecart)) {
		$seuil_ecart = price2num($seuil_ecart);
		dolibarr_set_const($db, 'STANCER_COMPTA_SEUIL_ECART', $seuil_ecart, 'chaine', 0, '', $conf->entity);
	}
	if ($supplier_id > 0) {
		dolibarr_set_const($db, 'STANCER_COMPTA_SUPPLIER_ID', $supplier_id, 'chaine', 0, '', $conf->entity);
	}

	setEventMessages($langs->trans("SetupSaved"), null, 'mesgs');
}

if ($action == 'regularize') {
	$supplier_id = getDolGlobalInt('STANCER_COMPTA_SUPPLIER_ID');
	$seuil = getDolGlobalString('STANCER_COMPTA_SEUIL_ECART', '0.05');
	$journal_id = getDolGlobalInt('STANCER_COMPTA_JOURNAL_OD');
	$account_ecart = getDolGlobalString('STANCER_COMPTA_ACCOUNT_ECART');

	if (empty($journal_id) || empty($account_ecart) || empty($supplier_id)) {
		setEventMessages($langs->trans("StancerComptaConfigIncomplete"), null, 'errors');
	} else {
		// Get supplier accounting code and name
		$sql_supplier = "SELECT code_compta_fournisseur, nom FROM ".MAIN_DB_PREFIX."societe WHERE rowid = ".((int) $supplier_id);
		$res_supplier = $db->query($sql_supplier);
		$supplier_account = '';
		$supplier_name = '';
		if ($res_supplier && $obj_supplier = $db->fetch_object($res_supplier)) {
			$supplier_account = $obj_supplier->code_compta_fournisseur;
			$supplier_name = $obj_supplier->nom;
		}

		if (empty($supplier_account)) {
			setEventMessages($langs->trans("StancerComptaSupplierNoAccount"), null, 'errors');
		} else {
			// Get general account (401) from auxiliary account (401ILIAD)
			// The general account is the first 3 digits
			$supplier_general_account = substr($supplier_account, 0, 3);

			// Get supplier account label
			$sql_label = "SELECT label FROM ".MAIN_DB_PREFIX."accounting_account WHERE account_number = '".$db->escape($supplier_general_account)."' AND entity = ".((int) $conf->entity);
			$res_label = $db->query($sql_label);
			$supplier_account_label = 'Fournisseurs';
			if ($res_label && $obj_label = $db->fetch_object($res_label)) {
				$supplier_account_label = $obj_label->label;
			}

			// Get gap account label
			$sql_label2 = "SELECT label FROM ".MAIN_DB_PREFIX."accounting_account WHERE account_number = '".$db->escape($account_ecart)."' AND entity = ".((int) $conf->entity);
			$res_label2 = $db->query($sql_label2);
			$gap_account_label = 'Charges diverses';
			if ($res_label2 && $obj_label2 = $db->fetch_object($res_label2)) {
				$gap_account_label = $obj_label2->label;
			}

			// Get journal code
			$journal = new AccountingJournal($db);
			$journal->fetch($journal_id);
			$journal_code = $journal->code;

			// Find supplier invoices with gap (excluding already regularized)
			// Use UNION to link accounting entries via proper foreign keys instead of LIKE on doc_ref
			$sql = "SELECT ff.ref, ff.rowid, ff.datef,
					SUM(ab.debit) as total_debit, SUM(ab.credit) as total_credit,
					(SUM(ab.debit) - SUM(ab.credit)) as ecart
				FROM ".MAIN_DB_PREFIX."facture_fourn ff
				INNER JOIN (
					-- Direct supplier invoice entries (fk_doc = facture_fourn.rowid)
					SELECT fk_doc as fk_facture, debit, credit
					FROM ".MAIN_DB_PREFIX."accounting_bookkeeping
					WHERE doc_type = 'supplier_invoice'
					AND subledger_account = '".$db->escape($supplier_account)."'
					UNION ALL
					-- Bank entries linked via payment chain
					SELECT pff.fk_facturefourn as fk_facture, ab.debit, ab.credit
					FROM ".MAIN_DB_PREFIX."accounting_bookkeeping ab
					INNER JOIN ".MAIN_DB_PREFIX."bank b ON b.rowid = ab.fk_docdet
					INNER JOIN ".MAIN_DB_PREFIX."paiementfourn p ON p.fk_bank = b.rowid
					INNER JOIN ".MAIN_DB_PREFIX."paiementfourn_facturefourn pff ON pff.fk_paiementfourn = p.rowid
					WHERE ab.doc_type = 'bank'
					AND ab.subledger_account = '".$db->escape($supplier_account)."'
				) ab ON ab.fk_facture = ff.rowid
				WHERE ff.fk_soc = ".((int) $supplier_id)."
				AND NOT EXISTS (
					SELECT 1 FROM ".MAIN_DB_PREFIX."accounting_bookkeeping od
					WHERE od.doc_ref = CONCAT('OD-STANCER-', ff.ref)
				)
				GROUP BY ff.ref, ff.rowid, ff.datef
				HAVING ABS(SUM(ab.debit) - SUM(ab.credit)) > 0
				AND ABS(SUM(ab.debit) - SUM(ab.credit)) <= ".((float) $seuil);

			$resql = $db->query($sql);
			$nb_regularized = 0;

			if ($resql) {
				while ($obj = $db->fetch_object($resql)) {
					$ecart = $obj->ecart;

					// Create OD entry
					$bookkeeping = new BookKeeping($db);
					$now = dol_now();
					$invoice_date = $obj->datef;

					// Determine debit/credit based on gap sign
					// If ecart > 0 (debits > credits), we need to add credit to balance
					// If ecart < 0 (credits > debits), we need to add debit to balance
					$amount = abs($ecart);
					$is_credit_supplier = ($ecart > 0);

					// Line 1: Supplier account (401 with subledger 401ILIAD)
					$bookkeeping->doc_date = $invoice_date;
					$bookkeeping->date_lim_reglement = $invoice_date;
					$bookkeeping->doc_ref = 'OD-STANCER-'.$obj->ref;
					$bookkeeping->date_creation = $now;
					$bookkeeping->doc_type = 'OD';
					$bookkeeping->fk_doc = $obj->rowid;
					$bookkeeping->fk_docdet = 0;
					$bookkeeping->thirdparty_code = '';
					$bookkeeping->subledger_account = $supplier_account;
					$bookkeeping->subledger_label = $supplier_name;
					$bookkeeping->numero_compte = $supplier_general_account;
					$bookkeeping->label_compte = $supplier_account_label;
					$bookkeeping->label_operation = $langs->trans("StancerComptaRegulEcart").' '.$obj->ref;
					$bookkeeping->debit = $is_credit_supplier ? 0 : $amount;
					$bookkeeping->credit = $is_credit_supplier ? $amount : 0;
					// BookKeeping::create() still writes the llx_accounting_bookkeeping.montant
					// column from $this->montant on Dolibarr 15 to 21, and the core journals
					// (bankjournal, sellsjournal, purchasesjournal) still fill it the same way.
					// It carries the unsigned amount, the direction being held by $sens.
					// @phan-suppress-next-line PhanDeprecatedProperty  no replacement: debit/credit/sens carry a different information
					$bookkeeping->montant = $amount;
					$bookkeeping->sens = $is_credit_supplier ? 'C' : 'D';
					$bookkeeping->fk_user_author = $user->id;
					$bookkeeping->entity = $conf->entity;
					$bookkeeping->code_journal = $journal_code;
					$bookkeeping->journal_label = $journal->label;
					$bookkeeping->piece_num = 0;

					$result1 = $bookkeeping->create($user);

					// Line 2: Gap account (658)
					$bookkeeping2 = new BookKeeping($db);
					$bookkeeping2->doc_date = $invoice_date;
					$bookkeeping2->date_lim_reglement = $invoice_date;
					$bookkeeping2->doc_ref = 'OD-STANCER-'.$obj->ref;
					$bookkeeping2->date_creation = $now;
					$bookkeeping2->doc_type = 'OD';
					$bookkeeping2->fk_doc = $obj->rowid;
					$bookkeeping2->fk_docdet = 0;
					$bookkeeping2->thirdparty_code = '';
					$bookkeeping2->subledger_account = '';
					$bookkeeping2->subledger_label = '';
					$bookkeeping2->numero_compte = $account_ecart;
					$bookkeeping2->label_compte = $gap_account_label;
					$bookkeeping2->label_operation = $langs->trans("StancerComptaRegulEcart").' '.$obj->ref;
					$bookkeeping2->debit = $is_credit_supplier ? $amount : 0;
					$bookkeeping2->credit = $is_credit_supplier ? 0 : $amount;
					// Same as above: unsigned amount, direction held by $sens, still written
					// by BookKeeping::create() on the whole supported Dolibarr range.
					// @phan-suppress-next-line PhanDeprecatedProperty  no replacement: debit/credit/sens carry a different information
					$bookkeeping2->montant = $amount;
					$bookkeeping2->sens = $is_credit_supplier ? 'D' : 'C';
					$bookkeeping2->fk_user_author = $user->id;
					$bookkeeping2->entity = $conf->entity;
					$bookkeeping2->code_journal = $journal_code;
					$bookkeeping2->journal_label = $journal->label;
					$bookkeeping2->piece_num = $bookkeeping->piece_num;

					$result2 = $bookkeeping2->create($user);

					if ($result1 >= 0 && $result2 >= 0) {
						$nb_regularized++;
					} else {
						$errors1 = is_array($bookkeeping->errors) ? implode(', ', $bookkeeping->errors) : '';
						$errors2 = is_array($bookkeeping2->errors) ? implode(', ', $bookkeeping2->errors) : '';
						dol_syslog("StancerCompta: Error creating OD for ".$obj->ref." - result1=".$result1." result2=".$result2." error1=".$bookkeeping->error." errors1=".$errors1." error2=".$bookkeeping2->error." errors2=".$errors2, LOG_ERR);
						setEventMessages("Erreur création OD pour ".$obj->ref.": ".$bookkeeping->error." ".$errors1." / ".$bookkeeping2->error." ".$errors2, null, 'errors');
					}
				}

				if ($nb_regularized > 0) {
					setEventMessages($langs->trans("StancerComptaRegularized", $nb_regularized), null, 'mesgs');
				} elseif ($db->num_rows($resql) == 0) {
					setEventMessages($langs->trans("StancerComptaNoGapFound"), null, 'warnings');
				}
			}
		}
	}
}

/*
 * View
 */

$form = new Form($db);
$formaccounting = new FormAccounting($db);
$formcompany = new FormCompany($db);

$help_url = '';
$page_name = "StancerComptaSetup";

llxHeader('', $langs->trans($page_name), $help_url);

// Subheader
$linkback = '<a href="'.($backtopage ? $backtopage : DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1').'">'.$langs->trans("BackToModuleList").'</a>';

print load_fiche_titre($langs->trans($page_name), $linkback, 'title_setup');

// Configuration header
$head = stancerAdminPrepareHead();
print dol_get_fiche_head($head, 'StancerComptaMenu', $langs->trans($page_name), 0, "stancer@stancer");

// Setup page
print '<span class="opacitymedium">'.$langs->trans("StancerComptaSetupDesc").'</span><br><br>';

// Configuration form
print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="update">';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td>'.$langs->trans("Parameter").'</td>';
print '<td>'.$langs->trans("Value").'</td>';
print '</tr>';

// Journal OD
print '<tr class="oddeven">';
print '<td>'.$langs->trans("StancerComptaJournalOD").'</td>';
print '<td>';
$sql = "SELECT rowid, code, label FROM ".MAIN_DB_PREFIX."accounting_journal WHERE entity = ".((int) $conf->entity)." AND active = 1 ORDER BY code";
$resql = $db->query($sql);
$journals = array();
if ($resql) {
	while ($obj = $db->fetch_object($resql)) {
		$journals[$obj->rowid] = $obj->code.' - '.$obj->label;
	}
}
print $form->selectarray('STANCER_COMPTA_JOURNAL_OD', $journals, getDolGlobalInt('STANCER_COMPTA_JOURNAL_OD'), 1, 0, 0, '', 0, 0, 0, '', 'minwidth300');
print '</td></tr>';

// Account for gaps
print '<tr class="oddeven">';
print '<td>'.$langs->trans("StancerComptaAccountEcart").' '.$form->textwithpicto('', $langs->trans("StancerComptaAccountEcartTooltip")).'</td>';
print '<td>';
print $formaccounting->select_account(getDolGlobalString('STANCER_COMPTA_ACCOUNT_ECART'), 'STANCER_COMPTA_ACCOUNT_ECART', 1, array(), 1, 1, 'minwidth300');
print '</td></tr>';

// Threshold
print '<tr class="oddeven">';
print '<td>'.$langs->trans("StancerComptaSeuilEcart").'</td>';
print '<td>';
print '<input type="text" name="STANCER_COMPTA_SEUIL_ECART" value="'.getDolGlobalString('STANCER_COMPTA_SEUIL_ECART', '0.05').'" class="minwidth100">';
print ' '.$langs->getCurrencySymbol($conf->currency);
print '</td></tr>';

// Supplier
print '<tr class="oddeven">';
print '<td>'.$langs->trans("StancerComptaSupplier").'</td>';
print '<td>';
$sql = "SELECT rowid, nom FROM ".MAIN_DB_PREFIX."societe WHERE fournisseur = 1 AND entity = ".((int) $conf->entity)." ORDER BY nom";
$resql = $db->query($sql);
$suppliers = array();
if ($resql) {
	while ($obj = $db->fetch_object($resql)) {
		$suppliers[$obj->rowid] = $obj->nom;
	}
}
print $form->selectarray('STANCER_COMPTA_SUPPLIER_ID', $suppliers, getDolGlobalInt('STANCER_COMPTA_SUPPLIER_ID'), 1, 0, 0, '', 0, 0, 0, '', 'minwidth300');
print '</td></tr>';

print '</table>';

print '<div class="center"><input type="submit" class="button" value="'.$langs->trans("Save").'"></div>';
print '</form>';

print '<br><br>';

// Display gaps found
$supplier_id = getDolGlobalInt('STANCER_COMPTA_SUPPLIER_ID');
$seuil = getDolGlobalString('STANCER_COMPTA_SEUIL_ECART', '0.05');

if ($supplier_id > 0) {
	// Get supplier accounting code
	$sql_supplier = "SELECT code_compta_fournisseur, nom FROM ".MAIN_DB_PREFIX."societe WHERE rowid = ".((int) $supplier_id);
	$res_supplier = $db->query($sql_supplier);
	$supplier_account = '';
	$supplier_name = '';
	if ($res_supplier && $obj_supplier = $db->fetch_object($res_supplier)) {
		$supplier_account = $obj_supplier->code_compta_fournisseur;
		$supplier_name = $obj_supplier->nom;
	}

	if (!empty($supplier_account)) {
		print load_fiche_titre($langs->trans("StancerComptaGapsFound", $supplier_name), '', '');

		// Find supplier invoices with gap (excluding already regularized)
		// Use UNION to link accounting entries via proper foreign keys instead of LIKE on doc_ref
		$sql = "SELECT ff.ref, ff.rowid, ff.datef,
				SUM(ab.debit) as total_debit, SUM(ab.credit) as total_credit,
				(SUM(ab.debit) - SUM(ab.credit)) as ecart
			FROM ".MAIN_DB_PREFIX."facture_fourn ff
			INNER JOIN (
				-- Direct supplier invoice entries (fk_doc = facture_fourn.rowid)
				SELECT fk_doc as fk_facture, debit, credit
				FROM ".MAIN_DB_PREFIX."accounting_bookkeeping
				WHERE doc_type = 'supplier_invoice'
				AND subledger_account = '".$db->escape($supplier_account)."'
				UNION ALL
				-- Bank entries linked via payment chain
				SELECT pff.fk_facturefourn as fk_facture, ab.debit, ab.credit
				FROM ".MAIN_DB_PREFIX."accounting_bookkeeping ab
				INNER JOIN ".MAIN_DB_PREFIX."bank b ON b.rowid = ab.fk_docdet
				INNER JOIN ".MAIN_DB_PREFIX."paiementfourn p ON p.fk_bank = b.rowid
				INNER JOIN ".MAIN_DB_PREFIX."paiementfourn_facturefourn pff ON pff.fk_paiementfourn = p.rowid
				WHERE ab.doc_type = 'bank'
				AND ab.subledger_account = '".$db->escape($supplier_account)."'
			) ab ON ab.fk_facture = ff.rowid
			WHERE ff.fk_soc = ".((int) $supplier_id)."
			AND NOT EXISTS (
				SELECT 1 FROM ".MAIN_DB_PREFIX."accounting_bookkeeping od
				WHERE od.doc_ref = CONCAT('OD-STANCER-', ff.ref)
			)
			GROUP BY ff.ref, ff.rowid, ff.datef
			HAVING ABS(SUM(ab.debit) - SUM(ab.credit)) > 0
			AND ABS(SUM(ab.debit) - SUM(ab.credit)) <= ".((float) $seuil)."
			ORDER BY ff.datef DESC";

		$resql = $db->query($sql);

		if ($resql) {
			$num = $db->num_rows($resql);

			print '<table class="noborder centpercent">';
			print '<tr class="liste_titre">';
			print '<td>'.$langs->trans("Ref").'</td>';
			print '<td>'.$langs->trans("Date").'</td>';
			print '<td class="right">'.$langs->trans("Debit").'</td>';
			print '<td class="right">'.$langs->trans("Credit").'</td>';
			print '<td class="right">'.$langs->trans("StancerComptaEcart").'</td>';
			print '</tr>';

			if ($num > 0) {
				while ($obj = $db->fetch_object($resql)) {
					print '<tr class="oddeven">';
					print '<td>'.$obj->ref.'</td>';
					print '<td>'.dol_print_date($db->jdate($obj->datef), 'day').'</td>';
					print '<td class="right">'.price($obj->total_debit).'</td>';
					print '<td class="right">'.price($obj->total_credit).'</td>';
					print '<td class="right">'.price($obj->ecart).'</td>';
					print '</tr>';
				}
			} else {
				print '<tr class="oddeven"><td colspan="5" class="opacitymedium">'.$langs->trans("StancerComptaNoGapFound").'</td></tr>';
			}

			print '</table>';

			if ($num > 0) {
				print '<br>';
				print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'">';
				print '<input type="hidden" name="token" value="'.newToken().'">';
				print '<input type="hidden" name="action" value="regularize">';
				print '<div class="center"><input type="submit" class="button" value="'.$langs->trans("StancerComptaRegularize", $num).'"></div>';
				print '</form>';
			}
		} else {
			dol_print_error($db);
		}
	}
}

// Page end
print dol_get_fiche_end();

llxFooter();
$db->close();
