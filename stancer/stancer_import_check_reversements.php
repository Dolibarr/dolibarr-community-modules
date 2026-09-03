<?php
/* Copyright (C) 2001-2005 Rodolphe Quiedeville <rodolphe@quiedeville.org>
 * Copyright (C) 2004-2015 Laurent Destailleur  <eldy@users.sourceforge.net>
 * Copyright (C) 2005-2012 Regis Houssin        <regis.houssin@inodbox.com>
 * Copyright (C) 2015      Jean-François Ferry	<jfefe@aternatik.fr>
 * Copyright (C) 2026		MDW						<mdeweerd@users.noreply.github.com>
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
 *    \file       stancer/stancerindex.php
 *    \ingroup    stancer
 *    \brief      Home page of stancer top menu
 */

// Load Dolibarr environment
$res = 0;
// Try main.inc.php into web root known defined into CONTEXT_DOCUMENT_ROOT (not always defined)
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
// Try main.inc.php into web root detected using web root calculated from SCRIPT_FILENAME
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME']; $tmp2 = realpath(__FILE__); $i = strlen($tmp) - 1; $j = strlen($tmp2) - 1;  // @phan-suppress-current-line DolibarrForbiddenFunctionPlugin
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

require_once DOL_DOCUMENT_ROOT.'/core/class/html.formfile.class.php';
dol_include_once('/stancer/lib/stancer.lib.php');

// Load translation files required by the page
$langs->loadLangs(array("stancer@stancer"));

$action = GETPOST('action', 'aZ09');

$max = 5;
$now = dol_now();

// Security check - Protection if external user
$socid = GETPOST('socid', 'int');
// if (isset($user->socid) && $user->socid > 0) {
//     $action = '';
//     $socid = $user->socid;
// }


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



/**
 * Convert an ISO date (YYYY-MM-DD) to the french display format (DD/MM/YYYY)
 *
 * @param   string  $d  Date in YYYY-MM-DD format
 * @return  string      Date in DD/MM/YYYY format
 */
function stancer_convert_date($d)
{
	$t = explode("-", $d);
	return $t[2] . "/" . $t[1] . "/" . $t[0];
}

/**
 * Look for the bank entry matching a Stancer payout line, and attach it to the bank statement
 *
 * @param   string  $id             Stancer id stored in bank.num_chq
 * @param   string  $date           Operation date of the payout line
 * @param   string  $ref            Reference shown in the report
 * @param   float   $amount         Amount of the payout line
 * @param   float   $fees           Stancer fees of the payout line
 * @param   string  $numreleve      Bank statement number to set
 * @param   int     $numrowstarget  Expected number of matching bank rows
 * @return  array{0:int,1:string}   array(status, html), status is -1 on error and 0 otherwise
 */
function stancer_find_update($id, $date, $ref, $amount, $fees, $numreleve, $numrowstarget)
{
	global $db, $conf;
	$html = ""; //"<p>into stancer_find_update function for $id</p>";
	$fk_account = getDolGlobalString('STANCER_BANK_ACCOUNT_FOR_PAYMENTS');
	$sql = "SELECT * FROM ".MAIN_DB_PREFIX."bank WHERE num_chq='" . $db->escape($id) . "' AND fk_account='" . $db->escape($fk_account) ."'";// AND num_releve <> '" . $numreleve . "'";
	// $html .= "<p>$id :: $sql</p>";
	$resql = $db->query($sql);
	if ($resql) {
		$num_rows = $db->num_rows($resql);
		// $html .= "<p>Nb = $num_rows pour $id / $date / $ref ..</p>";
		if ($num_rows != $numrowstarget) {
			$html .= "<p>Nb d'enregistrements non conforme pour $id : attendu=$numrowstarget réel en base=$num_rows</p>";
			//race condition --
			if ($num_rows == 0) {
				// No bank line at all for this Stancer id. Only the payout call site below
				// reads the -1 returned here, and reacts by re-syncing the payout, the
				// refund or the dispute from the API; the two payment call sites discard
				// the return code, so for them this log is the only trace left.
				dol_syslog("stancer import_check_reversements: no bank line found for stancer id ".$id." on account ".$fk_account, LOG_WARNING);
			}
			return [-1,$html];
		}
		while ($obj = $db->fetch_object($resql)) {
			// $html .= "<p>[$id] = " . json_encode($obj) . "</p>";
			$sqlamount = abs((float) price2num($obj->amount));
			if ($numreleve != $obj->num_releve) {
				$html .= "<p>Update num releve to $numreleve :: (debug)</p>";// . json_encode($obj) . ")</p>";
				$sqlUpdate = "UPDATE ".MAIN_DB_PREFIX."bank SET num_releve='" . $db->escape($numreleve) . "' WHERE rowid='" . $db->escape($obj->rowid) . "'";
				$resqlU = $db->query($sqlUpdate);
				if (!$resqlU) {
					dol_syslog("stancer import_check_reversements: cannot set num_releve on bank line ".$obj->rowid.": ".$db->lasterror(), LOG_ERR);
				}
			}
			//verif VIR et PRE
			if ($numrowstarget == 2) {
				$outamount = (float) price2num($amount);// - $fees);
				if ($obj->fk_type == 'VIR' && (abs($sqlamount - $outamount) > 0.01)) {
					//si amount < 0 c'est un remboursement donc pas ce cas
					if ($amount > 0) {
						$html .= "<p>[$id] Montant du virement non conforme $sqlamount != $outamount</p>";
					} else {
						$html .= "<p>[$id] Vérifier le virement du compte principal vers stancer, il a l'air non conforme $sqlamount != $outamount</p>";
					}
				} elseif ($obj->fk_type == 'PRE' && abs($sqlamount - $fees) > 0.01) {
					$html .= "<p>[$id] Montant du prélèvement des frais non conforme $sqlamount != $fees</p>";
				}

				if ($obj->fk_type == 'VIR' && $obj->label == "(BankTransfer)") {
					stancerUpdateLabelOnMainAccount($id);
				}
			}
			if ($numrowstarget == 1) {
				if (abs(abs($sqlamount) - abs($amount)) > 0.01) {
					$html .= "<p>update a faire pour le montant: $sqlamount vs " . $amount . "</p>";
				}
			}
			//cas particulier des remboursements il faut 2 écritures : le montant remboursé et les frais
			if (substr($id, 0, 5) == "refd_") {
				stancerAddPaimentFeeOnBank($id, $fees, $fk_account, $id, $date, $date);
				$sqlUpdate = "UPDATE ".MAIN_DB_PREFIX."bank SET num_releve='" . $db->escape($numreleve) . "' WHERE num_chq='" . $db->escape($id) . "'";
				$resqlU = $db->query($sqlUpdate);
				if (!$resqlU) {
					dol_syslog("stancer import_check_reversements: cannot set num_releve on refund ".$id.": ".$db->lasterror(), LOG_ERR);
				}
			}
			//cas particulier des disputes il faut 2 écritures : le montant et les frais
			if (substr($id, 0, 5) == "dspt_") {
				stancerAddPaimentFeeOnBank($id, $fees, $fk_account, $id, $date, $date);
				$sqlUpdate = "UPDATE ".MAIN_DB_PREFIX."bank SET num_releve='" . $db->escape($numreleve) . "' WHERE num_chq='" . $db->escape($id) . "'";
				$resqlU = $db->query($sqlUpdate);
				if (!$resqlU) {
					dol_syslog("stancer import_check_reversements: cannot set num_releve on dispute ".$id.": ".$db->lasterror(), LOG_ERR);
				}
			}
		}
	} else {
		dol_syslog("stancer import_check_reversements: bank lookup failed for stancer id ".$id.": ".$db->lasterror(), LOG_ERR);
		$html .= "<p>Erreur de recherche pour $id / $date / $ref ..</p>";
	}
	return [0, $html];
}

/*
 * Actions
 */
$html = "";
$htmlreleve = "";
if ($action == "import") {
	$numreleve = GETPOST('num_releve', 'alphanohtml');
	$fk_account = getDolGlobalString('STANCER_BANK_ACCOUNT_FOR_PAYMENTS');

	$html .= "<h2>Vérification de l'import du relevé " . dol_escape_htmltag($numreleve) . "</h2>";

	$pout_list = $paym_list = [];
	$total = $totalfees = $totalnet = 0;

	$import_key = dol_now();
	$error = 0;
	$outputerror = '';
	$db->begin();
	$src = $_FILES['file']['tmp_name'] ?? '';
	if (empty($src) || !is_uploaded_file($src)) {
		dol_syslog("stancer import_check_reversements: missing or invalid uploaded file", LOG_WARNING);
		setEventMessages($langs->trans("ErrorFieldRequired", $langs->transnoentities("File")), null, 'errors');
		$db->rollback();
		$action = '';
	} else {
		$map = [];
		// -1 is the "column not found" sentinel, same as the ?? -1 fallbacks used
		// when the header line is parsed below. Never null: these are array indexes.
		$mf_id_reversement = $mf_date = $mf_statut = $mf_total_pay = $mf_amount_remb = $mf_total_com_ttc = $mf_total_net = $mf_id_ope = $mf_ref_commande = $mf_montant_brut = $mf_total_com_ope = $mf_montant_net_ope = $mf_com_ht = -1;
		$prevpout = '';

		if (($handle = fopen($src, "r")) !== false) {
			while (($line = fgets($handle, 1000)) !== false) {
				$data = str_getcsv($line, ';', '"');

				//1st line : labels
				if ($data[0] == "Identifiant de l'opération") {
					$nb = 0;
					foreach ($data as $field) {
						$map[(string) $field] = $nb;
						$nb++;
					}

					//list of needed fields
					$mf_id_reversement = $map["Identifiant du reversement"] ?? -1; //0
					$mf_date = $map["Date de reversement"] ?? -1; //1
					$mf_statut = $map["Statut"] ?? -1; //2
					$mf_total_pay = $map["Total des paiements"] ?? -1; //3
					$mf_amount_remb = $map["Total des remboursements"] ?? -1; //4
					// $mf_ = $map["Total des contestations"]; //5
					// $mf_ = $map["Total brut"];//6
					$mf_com_ht = $map["Commissions (HT)"] ?? -1;//7
					// $mf_ = $map["Total des TVA Commissions"];//8
					$mf_total_com_ttc = $map["Total des commissions (TTC)"] ?? -1;//9
					$mf_total_net = $map["Total net"] ?? -1;//10
					// $mf_ = $map["Devise"];//11
					$mf_id_ope = $map["Identifiant de l'opération"] ?? -1;//12
					$mf_ref_commande = $map["N° de commande"] ?? -1;//13
					$mf_montant_brut = $map["Montant brut de l'opération"] ?? -1;//14
					// $mf_ = $map["Commissions hors taxes (HT)"];//15
					// $mf_ = $map["TVA Commissions"];//16
					$mf_total_com_ope = $map["Total des commissions de l'opération (TTC)"] ?? -1;//17
					$mf_montant_net_ope = $map["Total net"] ?? -1;//18
					// $mf_ = $map["Devise de l'opération"];//19

					// print_r($map);exit;
					$htmlreleve = "<table class='table table-xs'><thead><tr><th>Identifiant reversement</th><th>Date</th><th>Id Opération</th><th>Ref dolibarr</th><th align='right'>Montant TTC<br />\n(facture client)</th><th align='right'>Total commissions<br />(Stancer)</th><th align='right'>Montant net<br />\n(Compte bq principal)</th></tr></thead><tbody>\n";
					continue;
				}

				if (count($map) < 1) {
					print "<p>Erreur MAPPING Fields : " . json_encode($map) . "</p>";
					exit;
				}

				// str_getcsv() yields null for a missing cell: normalise to string
				// right here, every consumer below expects a string.
				$pout = (string) $data[$mf_id_reversement];
				$date = (string) $data[$mf_date];
				$ladate = stancer_convert_date($date);

				$pout_amount = (float) price2num($data[$mf_total_net]);
				$rbt_amount = 0;
				if ($pout_amount < 0) {
					$rbt_amount = (float) price2num($data[$mf_amount_remb]);
				}
				$pout_fees = (float) price2num($data[$mf_total_com_ttc]);
				$paym = (string) $data[$mf_id_ope];
				$ref = (string) $data[$mf_ref_commande];
				$amount = (float) price2num($data[$mf_montant_brut]);
				$fees = (float) price2num($data[$mf_com_ht]);
				$net = (float) price2num($data[$mf_montant_net_ope]);
				$tag = substr($paym, 0, 5);
				// Skip any CSV row whose Stancer identifiers are malformed: such a row
				// is never used to build a SQL query (defense in depth on top of the
				// escaping applied at each query below).
				if (!preg_match('/^[a-z]+_[A-Za-z0-9]+$/', (string) $pout) || !preg_match('/^[a-z]+_[A-Za-z0-9]+$/', (string) $paym)) {
					dol_syslog("stancer import_check_reversements: skipping CSV row with invalid identifiers pout=" . $pout . " paym=" . $paym, LOG_WARNING);
					continue;
				}
				// print "$mf_montant_brut :: amount=$amount :: fees=$pout_fees ::" . $data[$mf_total_com_ope] . " :: " . json_encode($data);exit;
				if (in_array($tag, ["refd_","dspt_"])) {
					//print "<p>tag is dspt_ for amount=$amount</p>";
					$total -= (abs($amount));
				} else {
					$total += (abs($amount));
				}
				$totalfees += abs($fees);
				if ($pout != $prevpout) {
					$netdisplay = price($net);
					$totalnet += abs($net);
				} else {
					$netdisplay = "";
				}
				$prevpout = $pout;
				$htmlreleve .= "<tr class='hover'><td>" . dol_escape_htmltag($pout) . "</td><td>" . dol_escape_htmltag($ladate) . "</td><td>" . dol_escape_htmltag($paym) . "</td><td>" . dol_escape_htmltag($ref) . "</td><td align='right'>" . price($amount) . "</td><td align='right'>" . price($fees) . "</td><td align='right'>" . $netdisplay . "</td></tr>\n";

				//verification du payout = virement vers le compte principal
				// $html .= "<p><br /><br />ligne $pout, tag=$tag, amount=$pout_amount fees=$pout_fees amount=$amount verif=" . ($pout_amount+$pout_fees) . "</p>";
				if ($pout_amount > 0) {
					// $html .= "<p>$pout_amount > 0 call find_update for $pout</p>";
					list($resCheck,$h) = stancer_find_update($pout, $date, $ref, $pout_amount, $pout_fees, $numreleve, 2);
					$html .= $h;
					if ($resCheck == -1) {
						if ($tag == "dspt_") {
							// $html .= "<p>[$pout/$paym] handle dispute race case ... resCheck = " . json_encode($resCheck) . "</p>";
							$objret = stancerRefreshOneDispute($paym);
						} elseif ($tag == "refd_") {
							// $html .= "<p>[$pout/$paym] handle refund race case ... resCheck = " . json_encode($resCheck) . "</p>";
							//stancerAddTransfertFromAccountToAccount
							$objret = stancerRefreshOneRefund($paym);
						} else {
							//pout is a payout
							$objret = stancerRefreshOnePayout($pout, false);
							// $html .= "<p>[$pout/$paym] retour de resCheck = -1 ... objret = " . json_encode($objret) . "</p>";
							if (is_object($objret)  && $objret->error != '') {
								$html .= $objret->error;
							}
						}
						$resCheck = '';
					}
				} else {
					//pout < 0 : il faut faire un virement du compte principal vers le compte stancer pour pouvoir rembourser les clients
					$stancerApi = StancerApi::getInstance();
					$payoutData = $stancerApi->getPayout($pout);
					$label = ($payoutData !== false && isset($payoutData['statement_description'])) ? $payoutData['statement_description'] : '';
					// print "<p>virement CRA ($label) -> stancer</p>";exit;
					$accountStancer = new Account($db);
					$accountMainBank = new Account($db);

					$result = $accountStancer->fetch(getDolGlobalInt('STANCER_BANK_ACCOUNT_FOR_PAYMENTS'));
					if ($result < 0) {
						$error++;
						$outputerror = "error STANCER_BANK_ACCOUNT_FOR_PAYMENTS is not defined";
					}

					$result = $accountMainBank->fetch(getDolGlobalInt('STANCER_BANK_MAIN_ACCOUNT_FOR_PAYOUTS'));
					if ($result < 0) {
						$error++;
						$outputerror = "error STANCER_BANK_MAIN_ACCOUNT_FOR_PAYOUTS is not defined";
					}
					$html .= "<p>[$pout] Add bank transfert from main bank to stancer for ". $pout_amount . " and $label</p>";
					$html .= stancerAddTransfertFromAccountToAccount($accountMainBank, $accountStancer, $date, $date, $label, $label, abs($pout_amount), abs($pout_amount), $pout, false);
				}

				if (!in_array($pout, $pout_list)) {
					$pout_list[] = $pout;
				}

				//verification du payment ou refound
				// $html .= "<p>ligne $pout, recherche refd : " . json_encode($paym) . "</p>";

				if (in_array($tag, ["refd_", "dspt_"])) {
					list($code, $h) = stancer_find_update($paym, $date, $ref, $amount, $fees, $numreleve, 2);
					$html .= $h;
				} else {
					list($code, $h) = stancer_find_update($paym, $date, $ref, $amount, $fees, $numreleve, 1);
					$html .= $h;
				}
				$paym_list[] = $paym;
			}
			if ($error > 0) {
				$html .= "<p>Erreur !</p>";
				$html .= "<pre>";
				$html .= $outputerror;
				$html .= "</pre>";
				$db->rollback();
			} else {
				$db->commit();
				$importSuccess = true;
			}
		}

		$htmlreleve .= "</tbody>\n";

		$htmlreleve .= "  <tfoot>
    <tr>
      <th scope='row'>Totaux</th>
      <td></td>
      <td></td>
      <td></td>
      <td align='right'>" . price($total) ." </td>
      <td align='right'>" . price($totalfees) . "</td>
      <td align='right'>" . price($totalnet) . "</td>
    </tr>
  </tfoot>\n";

		$htmlreleve .= "</table>\n";

		//verification inverse: y a t il des ecritures qui ne sont pas dans la liste ...
		$sanitizedNumChqList = array_map(array($db, 'escape'), array_merge($paym_list, $pout_list));
		$sql = "SELECT rowid,num_chq FROM ".MAIN_DB_PREFIX."bank WHERE num_chq NOT IN ('" . implode("','", $sanitizedNumChqList) . "') AND fk_account='" . $db->escape($fk_account) ."' AND num_releve = '" . $db->escape($numreleve) . "'";
		$resql = $db->query($sql);
		// $html .= "<p>double verif inverse " . $sql ." </p>";
		if ($resql) {
			$obj = $db->fetch_object($resql);
			if ($obj->rowid > 0) {
				$html .= "<p>Enregistrement à vérifier : " . $obj->num_chq . " </p>";
			}
		} else {
			$html .= "<p>Erreur sql ! $sql</p>";
		}

		//verification du total
		$sql = "SELECT sum(amount) as total FROM ".MAIN_DB_PREFIX."bank WHERE  num_releve = '" . $db->escape($numreleve) . "' and amount > 0 AND fk_account='" . $db->escape($fk_account) ."'";
		//$html .= $sql;
		$resql = $db->query($sql);
		if ($resql) {
			$obj = $db->fetch_object($resql);
			$dbtotal = (float) price2num($obj->total);
			if ($dbtotal != (float) price2num($total)) {
				$html .= "<p><span style='color:red'>[ERR]</span> Total crédits dolibarr ".$dbtotal." non cohérent avec le relevé Stancer : $total</p>";
			} else {
				$html .= "<p><span style='color:green'>[OK]</span> Total crédits dolibarr ".$dbtotal." cohérent avec le relevé Stancer : $total</p>";
			}
		} else {
			$html .= "<p>Erreur sql ! $sql</p>";
		}

		//verification du total des débits
		$sql = "SELECT sum(amount) as totaldeb FROM ".MAIN_DB_PREFIX."bank WHERE  num_releve = '" . $db->escape($numreleve) . "' and amount < 0 AND fk_account='" . $db->escape($fk_account) ."' AND fk_type='PRE'";
		// print $sql;//exit;
		$resql = $db->query($sql);
		if ($resql) {
			$objdeb = $db->fetch_object($resql);
			$dbtotaldeb = abs((float) price2num($objdeb->totaldeb));
			if ($dbtotaldeb != abs((float) price2num($totalfees))) {
				$html .= "<p><span style='color:red'>[ERR]</span> Total débits dolibarr ".$dbtotaldeb." non cohérent avec le relevé Stancer : $totalfees</p>";
			} else {
				$html .= "<p><span style='color:green'>[OK]</span> Total débits dolibarr ".$dbtotaldeb." cohérent avec le relevé Stancer : $totalfees</p>";
			}
		} else {
			$html .= "<p>Erreur sql ! $sql</p>";
		}

		$html .= "<p>Importer un <a href='stancer_import_check_reversements.php'>autre relevé mensuel</a></p>";
	}
}

/*
 * View
 */

$form = new Form($db);
$formfile = new FormFile($db);

llxHeader("", $langs->trans("StancerArea"), '', '', 0, 0, [], ['https://cdn.jsdelivr.net/npm/daisyui@4.12.2/dist/full.min.css']);

print load_fiche_titre($langs->trans("StancerArea"), '', 'stancer.png@stancer');

print '<div class="fichecenter">';


if ($action != "import") {
	print '<form enctype="multipart/form-data" method="POST" action="'.$_SERVER["PHP_SELF"].'" name="form_index">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="import">';
	print '<input type="text" name="num_releve" value="" required placeholder="numéro de relevé">';
	print '<input type="file" class="flat minwidth100 maxwidthinputfileonsmartphone" name="file" id="file"><br /><br />';
	print '<input type="submit" class="button" name="submit" value="' .  $langs->trans("StartImport") . '">';
	print '</form>';
} else {
	print $html;

	print "<h3>Version imprimable du fichier CSV</h3>\n";
	print $htmlreleve;
}

print '</div>'; //</div>

// End of page
llxFooter();
$db->close();
