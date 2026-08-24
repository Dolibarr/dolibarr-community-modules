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
 * \file    stancer/lib/stancer_bank.lib.php
 * \ingroup stancer
 * \brief   Bank operations (fees, transfers, bank lines, utilities)
 */

/**
 * Return the latest closed fiscal year end date (YYYY-MM-DD) or null.
 *
 * Priority:
 *   1. STANCER_FISCAL_LOCK_DATE manual override (YYYY-MM-DD)
 *   2. MAX(date_end) of llx_accounting_fiscalyear with statut=1 (closed)
 *
 * A bank line whose dateo is <= this date is considered "in a closed fiscal
 * year" and must never be modified by the module (data already in the balance
 * sheet / tax declaration / FEC).
 *
 * @return string|null YYYY-MM-DD or null when no lock
 */
function stancerGetFiscalLockDate()
{
	global $db;

	$manual = getDolGlobalString('STANCER_FISCAL_LOCK_DATE', '');
	if (!empty($manual) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $manual)) {
		return $manual;
	}

	$sql = "SELECT MAX(date_end) AS last_closed FROM " . MAIN_DB_PREFIX . "accounting_fiscalyear WHERE statut = 1";
	$resql = $db->query($sql);
	if ($resql) {
		$obj = $db->fetch_object($resql);
		if ($obj && !empty($obj->last_closed)) {
			// date_end is a DATE column, format YYYY-MM-DD
			return $obj->last_closed;
		}
	}

	return null;
}

/**
 * Return true if the given bank line date falls in a closed fiscal period.
 *
 * @param string|int|null $dateo  Date (YYYY-MM-DD, timestamp, or DB datetime). Null => not locked.
 * @return bool
 */
function stancerIsBankLineDateLocked($dateo)
{
	if (empty($dateo)) {
		return false;
	}
	$lockDate = stancerGetFiscalLockDate();
	if (empty($lockDate)) {
		return false;
	}

	// Normalize to YYYY-MM-DD
	if (is_numeric($dateo)) {
		$dateoStr = date('Y-m-d', (int) $dateo);
	} else {
		$dateoStr = substr((string) $dateo, 0, 10);
	}

	return $dateoStr <= $lockDate;
}

/**
 * Delete rows in llx_bank_url whose url_id points to a record that no longer
 * exists in the referenced table. Protects the accounting journal from
 * generating phantom debit/credit entries when related records have been
 * removed outside of Dolibarr's object classes (e.g. via manual SQL cleanup
 * after a mis-reconciliation).
 *
 * Handles these types (type -> referenced table):
 *   payment_supplier -> paiementfourn
 *   payment          -> paiement
 *   banktransfert    -> bank
 *   company          -> societe
 *   company_supplier -> societe
 *   user             -> user
 *
 * @param int|null $fkBank optional filter - if provided, only scan this bank line
 * @return int             number of orphan rows deleted
 */
function stancerCleanupOrphanBankUrls($fkBank = null)
{
	global $db;

	$whereBank = '';
	if (!is_null($fkBank)) {
		$whereBank = " AND bu.fk_bank = " . (int) $fkBank;
	}

	$totalDeleted = 0;

	$cleanups = array(
		'payment_supplier' => 'paiementfourn',
		'payment'          => 'paiement',
		'banktransfert'    => 'bank',
		'company'          => 'societe',
		'company_supplier' => 'societe',
		'user'             => 'user',
	);

	foreach ($cleanups as $type => $refTable) {
		$sql = "DELETE bu FROM " . MAIN_DB_PREFIX . "bank_url bu";
		$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . $refTable . " t ON t.rowid = bu.url_id";
		$sql .= " WHERE bu.type = '" . $db->escape($type) . "' AND t.rowid IS NULL";
		$sql .= $whereBank;
		$resql = $db->query($sql);
		if ($resql) {
			$n = (int) $db->affected_rows($resql);
			if ($n > 0) {
				$totalDeleted += $n;
				dol_syslog("stancerCleanupOrphanBankUrls deleted $n orphan bank_url of type=$type" . ($fkBank ? " on fk_bank=$fkBank" : ""));
			}
		} else {
			dol_syslog("stancerCleanupOrphanBankUrls failed for type=$type: " . $db->lasterror(), LOG_ERR);
		}
	}

	return $totalDeleted;
}

/**
 * add a line on bank account with Stancer fees
 *
 * @param   string           $ref          référence a ajouter sur la description
 * @param   float            $totalamount  montant total du paiement
 * @param   int|string       $fk_account   id du compte bancaire
 * @param   string           $stancer_id   id stancer
 * @param   int|string|null  $date         date d'opération, dol_now() si null
 * @param   int|string|null  $datev        date de valeur, identique à $date si null
 * @return  int                            < 0 en cas d'erreur, id de l'écriture bancaire sinon
 */
function stancerAddPaimentFeeOnBank($ref, $totalamount, $fk_account, $stancer_id, $date = null, $datev = null)
{
	global $db, $conf, $user, $langs;
	$db->begin();
	$error = 0;

	if (empty($date)) {
		$date = dol_now();
	}
	if (empty($datev)) {
		$datev = dol_now();
	}

	$amount = $totalamount;
	if ($totalamount > 0) {
		$amount = 0 - $totalamount;
	}

	include_once DOL_DOCUMENT_ROOT . '/compta/bank/class/account.class.php';
	$acc = new Account($db);
	$result = $acc->fetch($fk_account);
	if ($result < 0) {
		$error++;
		return -1;
	}

	// $db->begin();
	//dolibarr way but with that exports are not translated and keep (StancerFees) in FEC file !!!!
	//$label = "(StancerFees)";
	$label = $langs->trans('StancerFees');
	if ($ref != '') {
		$label .= " " . $ref;
	} elseif (!empty($date)) {
		$label .= " " . $date;
	}

	// Look for existing bank lines for this stancer_id on this account.
	// Four cases:
	//   1. exact-amount line exists -> nothing to do (real duplicate)
	//   2. line(s) exist with a different amount AND rappro=0 AND not in closed
	//      fiscal year -> update amount in place (avoids creating a duplicate
	//      and keeps the reconciliation intact)
	//   3. line(s) exist but are reconciled (rappro=1) or in a closed fiscal
	//      year -> log a warning, never modify
	//   4. no line at all -> insert a new one (unless the target date is in a
	//      closed fiscal year)
	$sqlExisting = "SELECT rowid, amount, rappro, dateo FROM " . MAIN_DB_PREFIX . "bank";
	$sqlExisting .= " WHERE num_chq='" . $db->escape($stancer_id) . "'";
	$sqlExisting .= " AND fk_account='" . $db->escape($fk_account) . "'";
	$sqlExisting .= " AND fk_type='PRE'";
	$resqlExisting = $db->query($sqlExisting);

	$exactMatch = false;
	$linesToUpdate = array();
	$lockedMismatch = false;
	if ($resqlExisting) {
		while ($existing = $db->fetch_object($resqlExisting)) {
			if (round((float) $existing->amount, 2) == round((float) $amount, 2)) {
				$exactMatch = true;
			} elseif ((int) $existing->rappro === 0 && !stancerIsBankLineDateLocked($existing->dateo)) {
				$linesToUpdate[] = $existing->rowid;
			} else {
				$lockedMismatch = true;
			}
		}
	}

	if ($exactMatch) {
		dol_syslog("stancerAddPaimentFeeOnBank duplicate (exact amount), nothing to do for $stancer_id");
	} elseif (!empty($linesToUpdate)) {
		$sqlUpdate = "UPDATE " . MAIN_DB_PREFIX . "bank SET amount='" . price2num($amount) . "'";
		$sqlUpdate .= " WHERE rowid IN (" . implode(',', array_map('intval', $linesToUpdate)) . ")";
		if (!$db->query($sqlUpdate)) {
			dol_syslog("stancerAddPaimentFeeOnBank UPDATE failed for $stancer_id: " . $db->lasterror(), LOG_ERR);
		} else {
			dol_syslog("stancerAddPaimentFeeOnBank updated amount to $amount for $stancer_id (rows: " . implode(',', $linesToUpdate) . ")");
		}
	} elseif ($lockedMismatch) {
		// Existing line(s) reconciled or in closed fiscal year: never touch, just trace.
		dol_syslog("stancerAddPaimentFeeOnBank mismatch amount but line is rappro=1 or in closed fiscal year for $stancer_id, manual correction needed", LOG_WARNING);
	} elseif (stancerIsBankLineDateLocked($date)) {
		// Insertion refused because target date is in a closed fiscal year.
		dol_syslog("stancerAddPaimentFeeOnBank refuse to insert $stancer_id: date $date is in closed fiscal year (lock=" . stancerGetFiscalLockDate() . ")", LOG_WARNING);
	} else {
		dol_syslog("stancerAddPaimentFeeOnBank insert : $stancer_id, label=$label, amount=$amount");
		$bank_line_id = $acc->addline(
			$date,
			'PRE', // Payment mode code ('CB', 'CHQ' or 'VIR' for example). Use payment id if not defined for backward compatibility.
			$label,
			$amount, // Sign must be positive when we receive money (customer payment), negative when you give money (supplier invoice or credit note)
			$stancer_id,
			'',
			$user,
			'',
			'',
			'',
			$datev
		);
	}



	//      // Add link 'company' in bank_url between invoice and bank transaction (for each invoice concerned by payment)
	//      //if (! $error && $label != '(WithdrawalPayment)')
	//      if (!$error) {
	//          $linkaddedforthirdparty = array();
	//          foreach ($this->amounts as $key => $value) {  // We should have invoices always for same third party but we loop in case of.
	//              if ($mode == 'payment') {
	//                  $fac = new Facture($this->db);
	//                  $fac->fetch($key);
	//                  $fac->fetch_thirdparty();
	//                  if (!in_array($fac->thirdparty->id, $linkaddedforthirdparty)) { // Not yet done for this thirdparty
	//                      $result = $acc->add_url_line(
	//                          $bank_line_id,
	//                          $fac->thirdparty->id,
	//                          DOL_URL_ROOT.'/comm/card.php?socid=',
	//                          $fac->thirdparty->name,
	//                          'company'
	//                          );
	//                      if ($result <= 0) {
	//                          dol_syslog(get_class($this).'::addPaymentToBank '.$this->db->lasterror());
	//                      }
	//                      $linkaddedforthirdparty[$fac->thirdparty->id] = $fac->thirdparty->id; // Mark as done for this thirdparty
	//                  }
	//              }
	//              if ($mode == 'payment_supplier') {
	//                  $fac = new FactureFournisseur($this->db);
	//                  $fac->fetch($key);
	//                  $fac->fetch_thirdparty();
	//                  if (!in_array($fac->thirdparty->id, $linkaddedforthirdparty)) { // Not yet done for this thirdparty
	//                      $result = $acc->add_url_line(
	//                          $bank_line_id,
	//                          $fac->thirdparty->id,
	//                          DOL_URL_ROOT.'/fourn/card.php?socid=',
	//                          $fac->thirdparty->name,
	//                          'company'
	//                          );
	//                      if ($result <= 0) {
	//                          dol_syslog(get_class($this).'::addPaymentToBank '.$this->db->lasterror());
	//                      }
	//                      $linkaddedforthirdparty[$fac->thirdparty->id] = $fac->thirdparty->id; // Mark as done for this thirdparty
	//                  }
	//              }
	//          }
	//      }

	//      // Add link 'WithdrawalPayment' in bank_url
	//      if (!$error && $label == '(WithdrawalPayment)') {
	//          $result = $acc->add_url_line(
	//              $bank_line_id,
	//              $this->id_prelevement,
	//              DOL_URL_ROOT.'/compta/prelevement/card.php?id=',
	//              $this->num_payment,
	//              'withdraw'
	//              );
	//      }

	//      // Add link 'InvoiceRefused' in bank_url
	//      if (! $error && $label == '(InvoiceRefused)') {
	//          $result=$acc->add_url_line(
	//              $bank_line_id,
	//              $this->id_prelevement,
	//              DOL_URL_ROOT.'/compta/prelevement/card.php?id=',
	//              $this->num_prelevement,
	//              'withdraw'
	//              );
	//      }

	//      if (!$error && !$notrigger) {
	//          // Appel des triggers
	//          $result = $this->call_trigger('PAYMENT_ADD_TO_BANK', $user);
	//          if ($result < 0) {
	//              $error++;
	//          }
	//          // Fin appel triggers
	//      }
	//  } else {
	//      $this->error = $acc->error;
	//      $error++;
	//  }

	if (!$error) {
		$db->commit();
	} else {
		$db->rollback();
	}

	if (!$error) {
		return 1;
	} else {
		return -1;
	}
}

/**
 * check if an entry does not exists on bank for that data
 *
 * @param   string  $stancer_id  stancer id
 * @param   int  	$account_id  dolibarr account id
 * @param   double 	$amount  	 amount
 *
 * @return  bool true if found into bank entries
 */
function stancerBankLineDoesNotExists($stancer_id, $account_id, $amount)
{
	global $langs, $db, $user, $conf;
	$sql = "SELECT * FROM " . MAIN_DB_PREFIX . "bank WHERE num_chq='" . $db->escape($stancer_id) . "' AND fk_account='" . (int) $account_id . "' AND ROUND(amount,2)=" . $amount;
	$resql = $db->query($sql);
	if ($resql) {
		$num = $db->num_rows($resql);
		if ($num == 0) {
			//there is no line, return true
			return true;
		}
	}
	return false;
}

/**
 * check if an entry does not exists on bank url for that data
 *
 * @param   int  $fk_bank  bank foreign key value
 * @param   int  $url_id  url id
 * @param   string  $type  type
 *
 * @return  bool true if url not present into bank table
 */
function stancerBankLineURLDoesNotExists($fk_bank, $url_id, $type)
{
	global $langs, $db, $user, $conf;
	$sql = "SELECT * FROM " . MAIN_DB_PREFIX . "bank_url WHERE fk_bank='" . $db->escape($fk_bank) . "' AND url_id='" . $db->escape($url_id) . "' AND type='" . $db->escape($type) . "'";
	$resql = $db->query($sql);
	if ($resql) {
		$num = $db->num_rows($resql);
		if ($num == 0) {
			//there is no line, return true
			return true;
		}
	}
	return false;
}


/**
 * Ajout d'un transfert bancaire de compte à compte
 *
 * @param   Account  $accountfrom	account from
 * @param   Account  $accountto   	account to
 * @param	string   $dateo			Date operation
 * @param   string	 $datev         Date validation
 * @param   string   $label         Label
 * @param   string   $labelto       label on destination account (will be on bank pdf)
 * @param	float	 $amount		Amount
 * @param	float	 $amountto		Amount
 * @param   string   $stancer_id    stancer id
 * @param   boolean  $userMessage   send a message to user
 *
 * @return  int result value
 */
function stancerAddTransfertFromAccountToAccount(Account $accountfrom, Account $accountto, $dateo, $datev, $label, $labelto, $amount, $amountto, $stancer_id, $userMessage = true)
{
	global $langs, $db, $user, $conf;
	$langs->load("errors");
	$errorMessage = "";
	$error = 0;
	$result = 0;

	if (empty($dateo) && !empty($datev)) {
		$dateo = $datev;
	}

	if (empty($datev) && !empty($dateo)) {
		$datev = $dateo;
	}

	$mailNotif = false;
	if (getDolGlobalString('STANCER_AUTO_MAIL_NOTIFICATIONS_PAYOUT', '') != '' && getDolGlobalString('STANCER_AUTO_MAIL_NOTIFICATIONS_PAYOUT_DETAILS', '') != '') {
		$mailNotif = true;
	}

	dol_syslog(("Stancer stancerAddTransfertFromAccountToAccount add transfert dateo=$dateo, datev=$datev label=$label, labelto=$labelto amount=$amount, amountto=$amountto, id stancer=$stancer_id"));

	if (!$label) {
		$error++;
		$errorMessage .= $langs->trans("ErrorFieldRequired", $langs->transnoentities("Description"));
	}
	if (!$amount) {
		$error++;
		$errorMessage .= $langs->trans("ErrorFieldRequired", $langs->transnoentities("Amount"));
	}
	if (!$accountfrom) {
		$error++;
		$errorMessage .= $langs->trans("ErrorFieldRequired", $langs->transnoentities("TransferFrom"));
	}
	if (!$accountto) {
		$error++;
		$errorMessage .= $langs->trans("ErrorFieldRequired", $langs->transnoentities("TransferTo"));
	}
	if (!$error) {
		if ($accountto->currency_code == $accountfrom->currency_code) {
			$amountto = $amount;
		} else {
			if (!$amountto) {
				$error++;
				$errorMessage .= $langs->trans("ErrorFieldRequired", $langs->transnoentities("AmountTo"));
			}
		}
		if ($amountto < 0) {
			$error++;
			$errorMessage .= $langs->trans("AmountMustBePositive");
		}

		if ($accountto->id == $accountfrom->id) {
			$error++;
			$errorMessage .= $langs->trans("ErrorFromToAccountsMustDiffers");
		}

		//duplicates ?
		$sql = "SELECT * FROM " . MAIN_DB_PREFIX . "bank WHERE num_chq='" . $db->escape($stancer_id) . "' AND fk_type='VIR'";
		$resql = $db->query($sql);
		if ($resql) {
			$num = $db->num_rows($resql);
			if ($num > 0) {
				dol_syslog("stancerAddTransfertFromAccountToAccount duplicate entries : num=$num, double check in progress");
				// $errorMessage .= "Entry already exists (duplicates not allowed)";
				// not an real error - maybe check if amount are right ?
				$numi = 0;
				while ($numi < $num) {
					$obj = $db->fetch_object($resql);
					if ($obj->rappro == '0' && $obj->fk_type == 'VIR' && abs($obj->amount) != abs($amount)) {
						if (stancerIsBankLineDateLocked($obj->dateo)) {
							dol_syslog("stancerAddTransfertFromAccountToAccount bank line " . $obj->rowid . " (stancer_id=$stancer_id, dateo=" . $obj->dateo . ") is in closed fiscal year (lock=" . stancerGetFiscalLockDate() . "), skip amount fix", LOG_WARNING);
						} else {
							if ($obj->amount < 0) {
								$realAmount = (float) price2num(-1 * $amount);
							} else {
								$realAmount = (float) price2num($amount);
							}
							$sqlUpdate = "UPDATE " . MAIN_DB_PREFIX . "bank SET amount='" . $realAmount . "' WHERE rowid='" . $obj->rowid . "'";
							$resqlUpdate = $db->query($sqlUpdate);
							dol_syslog("stancerAddTransfertFromAccountToAccount duplicate but old entries are not right against fees, fix is done");
						}
					}
					$numi++;
				}

				//TODO 20240321 : line is ok on stancer bank on dolibarr but not on destination account ! then please be more precise on duplicate tests
				//in normal situation there is 3 lines : 1. bank from, 2. bank target and 3. fees
				if ($num == 3) {
					//short return
					dol_syslog("stancerAddTransfertFromAccountToAccount duplicate entries short return");
					return;
				}
			}
		}

		if (empty($error)) {
			// Refuse any insertion whose business date is in a closed fiscal year.
			if (stancerIsBankLineDateLocked($dateo) || stancerIsBankLineDateLocked($datev)) {
				dol_syslog("stancerAddTransfertFromAccountToAccount refuse to add transfer $stancer_id: dateo=$dateo datev=$datev in closed fiscal year (lock=" . stancerGetFiscalLockDate() . ")", LOG_WARNING);
				return;
			}

			$db->begin();

			$bank_line_id_from = 0;
			$bank_line_id_to = 0;

			// By default, electronic transfert from bank to bank
			$typefrom = 'VIR';
			$typeto = 'VIR';

			if (!$error) {
				//check duplicate on each line
				if (stancerBankLineDoesNotExists($stancer_id, $accountfrom->id, (float) price2num(-1 * $amount))) {
					dol_syslog("stancerAddTransfertFromAccountToAccount no duplicate entries for account out=" . $accountfrom->id);
					$bank_line_id_from = $accountfrom->addline($dateo, $typefrom, $label, (float) price2num(-1 * $amount), $stancer_id, '', $user);
					if (!($bank_line_id_from > 0)) {
						$error++;
					}
				} else {
					dol_syslog("stancerAddTransfertFromAccountToAccount duplicate entries for account out=" . $accountfrom->id . " found, do not add line");
				}
			}
			if (!$error) {
				//utilisation de datev comme date opé car sur le compte de destination la dateo n'a rien à voir
				if (stancerBankLineDoesNotExists($stancer_id, $accountto->id, $amountto)) {
					dol_syslog("stancerAddTransfertFromAccountToAccount no duplicate entries for account in=" . $accountto->id);
					$bank_line_id_to = $accountto->addline($datev, $typeto, $labelto, $amountto, $stancer_id, '', $user);
					if (!($bank_line_id_to > 0)) {
						$error++;
					}
				} else {
					dol_syslog("stancerAddTransfertFromAccountToAccount duplicate entries for account in=" . $accountto->id . " found, do not add line");
				}
			}

			if (!$error) {
				if (stancerBankLineURLDoesNotExists($bank_line_id_from, $bank_line_id_to, 'banktransfert')) {
					$result = $accountfrom->add_url_line($bank_line_id_from, $bank_line_id_to, DOL_URL_ROOT . '/compta/bank/line.php?rowid=', '(banktransfert)', 'banktransfert');
					if (!($result > 0)) {
						$error++;
					}
				}
			}

			if (!$error) {
				if (stancerBankLineURLDoesNotExists($bank_line_id_to, $bank_line_id_from, 'banktransfert')) {
					$result = $accountto->add_url_line($bank_line_id_to, $bank_line_id_from, DOL_URL_ROOT . '/compta/bank/line.php?rowid=', '(banktransfert)', 'banktransfert');
					if (!($result > 0)) {
						$error++;
					}
				}
			}

			// print json_encode($accountfrom);

			if (!$error) {
				$mesgs = $langs->trans("StancerTransferFromToDone", html_entity_decode($accountfrom->label), html_entity_decode($accountto->label), $amount, $langs->transnoentitiesnoconv("Currency" . $conf->currency));
				$mesg2 = str_replace('{s1}', '<a href="bankentries_list.php?id=' . $accountfrom->id . '&token=' . newToken() . '&sortfield=b.datev,b.dateo,b.rowid&sortorder=desc">' . $accountfrom->label . '</a>', $mesgs);
				$mesgs = str_replace('{s2}', '<a href="bankentries_list.php?id=' . $accountto->id . '&token=' . newToken() . '">' . $accountto->label . '</a>', $mesg2);
				if ($userMessage) {
					setEventMessages($mesgs, [], 'mesgs');
				}

				if ($mailNotif) {
					// Deduplicate: only send the "payout done" mail once per stancer_id
					$payoutNotifyCode = 'AC_STANCER_PAYOUT_' . strtoupper((string) $stancer_id);
					$sqlDedup = "SELECT id FROM " . MAIN_DB_PREFIX . "actioncomm";
					$sqlDedup .= " WHERE code='" . $db->escape($payoutNotifyCode) . "'";
					$sqlDedup .= " LIMIT 1";
					$resDedup = $db->query($sqlDedup);
					$alreadyNotified = false;
					if ($resDedup) {
						$alreadyNotified = ($db->num_rows($resDedup) > 0);
						$db->free($resDedup);
					} else {
						dol_syslog("stancerAddTransfertFromAccountToAccount payout dedup SQL error: " . $db->lasterror(), LOG_ERR);
					}

					if ($alreadyNotified) {
						dol_syslog("stancerAddTransfertFromAccountToAccount payout $stancer_id mail already sent (code=$payoutNotifyCode), skip", LOG_DEBUG);
					} else {
						stancerSendMail(getDolGlobalString('STANCER_AUTO_MAIL_NOTIFICATIONS_PAYOUT', ''), $langs->transnoentitiesnoconv('StancerMailSubjectPayoutDone', $amount), $langs->transnoentitiesnoconv('StancerMailPayoutDone', $amount, $mesgs));
						$flagAccount = (object) array(
							'id' => $accountto->id,
							'element' => 'bank_account',
							'socid' => 0,
							'fk_project' => 0,
						);
						stancerAddActionComm($flagAccount, 'STANCER_PAYOUT_' . strtoupper((string) $stancer_id), 'Stancer payout ' . $stancer_id . ' notified by mail', 'amount=' . $amount, array(), '');
						dol_syslog("stancerAddTransfertFromAccountToAccount payout $stancer_id mail sent and dedup flag created (code=$payoutNotifyCode)", LOG_INFO);
					}
				}

				$db->commit();
			} else {
				if ($userMessage) {
					$message = $accountfrom->error . ' ' . $accountto->error;
					if (trim($message) != "") {
						setEventMessages($message, [], 'errors');
						dol_syslog("stancerAddTransfertFromAccountToAccount return error on setEventMessage=" . $accountfrom->error, LOG_WARNING);
					}
				}
				$db->rollback();
			}
		}
	}
	if ($errorMessage != '') {
		if ($mailNotif) {
			// Deduplicate: only one error mail per stancer_id+errorMessage combination
			$payoutErrCode = 'AC_STANCER_PAYOUT_ERR_' . strtoupper((string) $stancer_id) . '_' . substr(md5($errorMessage), 0, 8);
			$sqlErrDedup = "SELECT id FROM " . MAIN_DB_PREFIX . "actioncomm";
			$sqlErrDedup .= " WHERE code='" . $db->escape($payoutErrCode) . "'";
			$sqlErrDedup .= " LIMIT 1";
			$resErrDedup = $db->query($sqlErrDedup);
			$errAlreadyNotified = false;
			if ($resErrDedup) {
				$errAlreadyNotified = ($db->num_rows($resErrDedup) > 0);
				$db->free($resErrDedup);
			} else {
				dol_syslog("stancerAddTransfertFromAccountToAccount payout error dedup SQL error: " . $db->lasterror(), LOG_ERR);
			}

			if ($errAlreadyNotified) {
				dol_syslog("stancerAddTransfertFromAccountToAccount payout $stancer_id error mail already sent (code=$payoutErrCode), skip", LOG_DEBUG);
			} else {
				stancerSendMail(getDolGlobalString('STANCER_AUTO_MAIL_NOTIFICATIONS_PAYOUT', ''), $langs->trans('StancerMailSubjectPayoutError'), $langs->trans('StancerMailPayoutError', $errorMessage));
				$flagErrAccount = (object) array(
					'id' => is_object($accountto) ? $accountto->id : 0,
					'element' => 'bank_account',
					'socid' => 0,
					'fk_project' => 0,
				);
				stancerAddActionComm($flagErrAccount, 'STANCER_PAYOUT_ERR_' . strtoupper((string) $stancer_id) . '_' . substr(md5($errorMessage), 0, 8), 'Stancer payout ' . $stancer_id . ' error notified by mail', $errorMessage, array(), '');
				dol_syslog("stancerAddTransfertFromAccountToAccount payout $stancer_id error mail sent (code=$payoutErrCode)", LOG_INFO);
			}
		}
		if ($userMessage) {
			setEventMessages($errorMessage, [], 'errors');
		}
	}
	return $result;
}

/**
 * do stancer payment on an object
 *
 * @param   CommonObject  	$object               object, invoice, order ...
 * @param   array  			$data  			      array of data
 * @param   string  		$errorMessage  	      string : detailled error message
 * @param   bool            $bypassCustomerGuard  when true, skip ONLY Guard 2 (the
 *                                                customer->socid mapping). Reserved
 *                                                for the supervised admin force-post
 *                                                tool: the customer attached to a
 *                                                payment can be wrong (cross-customer
 *                                                leak), but order_id (Guard 1) and the
 *                                                amount (Guard 3) are still enforced.
 * @param   bool            $bypassAmountGuard    when true, skip Guard 3 (amount must
 *                                                not exceed remaining-to-pay). Reserved
 *                                                for the supervised "add anyway / over-
 *                                                pay" admin action on a double payment;
 *                                                the caller must have re-opened the
 *                                                invoice first. order_id (Guard 1) stays
 *                                                enforced.
 *
 * @return  int   < 0 on error
 */
function stancerAddPaymentOnObject($object, $data, &$errorMessage, $bypassCustomerGuard = false, $bypassAmountGuard = false)
{
	global $langs, $db, $user, $conf;

	$actioncode = "";
	$error = 0;
	dol_syslog("stancerAddPaymentOnObject add payment on =" . json_encode($object->ref) . " data = " . json_encode($data));

	//if object is not an invoice, we have to search for corresponding invoice and check payment on that
	$objtype = get_class($object);
	if ($object->element == 'commande') {
		$object->fetchObjectLinked($object->id, $objtype, '', 'facture');
		foreach ($object->linkedObjectsIds as $linkedobj) {
			foreach ($linkedobj as $facid) {
				$inv = new Facture($db);
				dol_syslog("stancerAddPaymentOnObject fetch linked invoice id $facid");
				$resInv = $inv->fetch($facid);
				if ($resInv) {
					dol_syslog("stancerAddPaymentOnObject object is not an invoice but a $objtype we load the invoice thanks to fetchObjectLinked");
					$object = $inv;
					//first invoice is enought
					break 2;
				} else {
					dol_syslog("stancerAddPaymentOnObject object is not an invoice but a $objtype and can't load invoice from fetchObjectLinked", LOG_ERR);
				}
			}
		}
	}

	//avoid duplicate ?
	$sql = "SELECT count(*) as count FROM " . MAIN_DB_PREFIX . "paiement as p INNER JOIN " . MAIN_DB_PREFIX . "paiement_facture as pf ON p.rowid=pf.fk_paiement WHERE num_paiement='" . $db->escape($data['payment_id']) . "'";
	$resql = $db->query($sql);
	if ($resql) {
		$obj = $db->fetch_object($resql);
		if ($obj->count > 0) {
			$errorMessage = "stancerAddPaymentOnObject duplicate";
			dol_syslog("stancerAddPaymentOnObject duplicate : " . $obj->count);
			return -1;
		}
	}

	//no amount -> no payment -> return
	if (empty($data['FinalPaymentAmt'])) {
		$errorMessage = "stancerAddPaymentOnObject amount empty, early return";
		dol_syslog($errorMessage, LOG_WARNING);
		return -2;
	}
	if (empty($data['date'])) {
		$errorMessage = "stancerAddPaymentOnObject date error, early return";
		dol_syslog($errorMessage, LOG_WARNING);
		return -3;
	}
	if (empty($object->id)) {
		$errorMessage = "stancerAddPaymentOnObject object id is null (maybe stancer account is misconfigured)";
		dol_syslog($errorMessage, LOG_WARNING);
		return -4;
	}

	// =========================================================================
	// Misattribution guards (added after the incident where Paiement rows ended
	// up cross-attributed between customers).
	// Each guard returns a distinct negative code so callers can log precisely
	// what went wrong. All three are skip-when-data-not-provided so older
	// callers that didn't yet propagate api_* fields keep working - only the
	// amount invariant fires unconditionally because we have FinalPaymentAmt
	// in every call site.
	// =========================================================================

	// Guard 1: API order_id (e.g. 'FA2603-4940') must match the Dolibarr invoice ref.
	// Catches the case where the FULLTAG of paymentback or the local order_id resolves
	// to one invoice but the Stancer paym_id was issued against a different one.
	if (!empty($data['api_order_id']) && $object->element === 'facture' && !empty($object->ref)) {
		if ((string) $data['api_order_id'] !== (string) $object->ref) {
			$errorMessage = "stancerAddPaymentOnObject REFUSE: Stancer api_order_id='";
			$errorMessage .= $data['api_order_id'] . "' does not match invoice ref='" . $object->ref . "'.";
			$errorMessage .= " Refusing to post payment_id=" . $data['payment_id'];
			$errorMessage .= " to avoid cross-customer misattribution.";
			dol_syslog($errorMessage, LOG_ERR);
			return -10;
		}
	}

	// Guard 2: API customer.id must map (via societe_rib.stancer_account) to the
	// invoice's socid. Without this, paym_id of customer A can be posted on
	// invoice of customer B (BHG paying 12 EUR ended up on invoice).
	// Note: Dolibarr Facture exposes the customer id as $socid, not $fk_soc.
	$objectSocid = (int) (isset($object->socid) ? $object->socid : (isset($object->fk_soc) ? $object->fk_soc : 0));
	if ($bypassCustomerGuard) {
		// Supervised admin force-post: the customer attached to the payment may be
		// wrong (cross-customer leak), so we deliberately skip the customer<->socid
		// check. order_id (Guard 1) and amount (Guard 3) are still enforced.
		dol_syslog("stancerAddPaymentOnObject: customer guard BYPASSED (supervised force-post) for payment_id="
			. (isset($data['payment_id']) ? $data['payment_id'] : '?') . " on " . $object->element . " " . $object->ref, LOG_WARNING);
	}
	if (!$bypassCustomerGuard && !empty($data['api_customer_id']) && $object->element === 'facture' && $objectSocid > 0) {
		$sqlMap = "SELECT fk_soc FROM " . MAIN_DB_PREFIX . "societe_rib";
		$sqlMap .= " WHERE stancer_account = '" . $db->escape($data['api_customer_id']) . "'";
		$sqlMap .= " AND stancer_account <> '' LIMIT 1";
		$resMap = $db->query($sqlMap);
		if ($resMap) {
			$rowMap = $db->fetch_object($resMap);
			if ($rowMap && (int) $rowMap->fk_soc > 0 && (int) $rowMap->fk_soc !== $objectSocid) {
				$errorMessage = "stancerAddPaymentOnObject REFUSE: Stancer customer='";
				$errorMessage .= $data['api_customer_id'] . "' is mapped to socid=" . (int) $rowMap->fk_soc;
				$errorMessage .= " but the target invoice " . $object->ref . " has socid=" . $objectSocid . ".";
				$errorMessage .= " Refusing to post payment_id=" . $data['payment_id'];
				$errorMessage .= " to avoid cross-customer misattribution.";
				dol_syslog($errorMessage, LOG_ERR);
				return -11;
			}
		}
	}

	// Guard 3: FinalPaymentAmt must not exceed the invoice's remaining-to-pay
	// (with a 1-cent tolerance for rounding). Catches overpayment regardless
	// of whether api_* fields are propagated by the caller.
	if ($bypassAmountGuard && $object->element === 'facture') {
		dol_syslog("stancerAddPaymentOnObject: amount guard BYPASSED (supervised over-pay) for payment_id="
			. (isset($data['payment_id']) ? $data['payment_id'] : '?') . " on invoice " . $object->ref, LOG_WARNING);
	}
	if (!$bypassAmountGuard && $object->element === 'facture') {
		$paid = $object->getSommePaiement() ?? 0;
		$credit = $object->getSumCreditNotesUsed() ?? 0;
		$deposit = $object->getSumDepositsUsed() ?? 0;
		$remaining = (float) price2num($object->total_ttc - $paid - $credit - $deposit, 'MT');
		$amt = (float) price2num($data['FinalPaymentAmt'], 'MT');
		if ($amt > $remaining + 0.01) {
			$errorMessage = "stancerAddPaymentOnObject REFUSE: FinalPaymentAmt=$amt EUR";
			$errorMessage .= " exceeds remaining-to-pay=$remaining EUR on invoice " . $object->ref . ".";
			$errorMessage .= " Refusing to post payment_id=" . $data['payment_id'];
			$errorMessage .= " (likely a multi-invoice Stancer payment landing on a single invoice, or a misattributed paym_id).";
			dol_syslog($errorMessage, LOG_ERR);
			return -12;
		}
	}

	$db->begin();
	$bankaccountid = getDolGlobalString('STANCER_BANK_ACCOUNT_FOR_PAYMENTS');
	if (empty($conf->banque->enabled) || empty($bankaccountid)) {
		$postactionmessages[] = "Setup of bank account to use in module Stancer was not set.";
		$ispostactionok = -1;
		$errorMessage = "Setup of bank account to use in module Stancer was not set.";
		dol_syslog($errorMessage, LOG_WARNING);
		return -5;
	}

	// Creation of payment line : warning donation is a special case
	if ($object->element == 'don') {
		$paiement = new PaymentDonation($db);
		$paiement->datep 			= $data['date'];
		$paiement->amounts 			= array($object->id => (float) price2num($data['FinalPaymentAmt']));
		$paiement->num_payment  	= $data['payment_id'];
		$paiement->note_public  	= 'Online payment ' . $data['date'] . ' from ' . $data['ipaddress'];
		$paiement->ext_payment_id 	= $data['TRANSACTIONID'];
		$paiement->ext_payment_site = $data['service'];
		$paiement->paymenttype 		= $data['paymentTypeId'];
		$paiement->fk_donation 		= $object->id;
		$paiement->total 			= (float) price2num($data['FinalPaymentAmt']);
		$paiement->amount 			= (float) price2num($data['FinalPaymentAmt']);
		$paiement->datepaid 		= $data['date'];

		dol_syslog("stancerAddPaymentOnObject ask PaymentDonation create with " . json_encode($paiement));
		$paiement_id = $paiement->create($user, 1);
		if ($paiement_id < 0) {
			$postactionmessages[] = $paiement->error . ' ' . implode("<br>\n", array_filter($paiement->errors, 'strlen'));
			dol_syslog("stancerAddPaymentOnObject return postactionmessages " . $paiement->error, LOG_WARNING);
			$ispostactionok = -1;
			$error++;
		} else {
			$postactionmessages[] = 'Payment created';
			$ispostactionok = 1;

			//TODO amelioration pour le totalpaid ?
			$totalpaid = $data['FinalPaymentAmt'];
			if ($totalpaid >= $object->getRemainToPay()) {
				$object->setPaid($object->id);
			}
		}
		$payment_type_for_bank = 'payment_donation';
		$label = '(DonationPayment)';
	} else {
		$paiement = new Paiement($db);
		$paiement->datepaye         = stancerDateToTimeStamp($data['date']);
		$paiement->amounts          = array($object->id => (float) price2num($data['FinalPaymentAmt']));
		$paiement->paiementid       = $data['paymentTypeId'];
		$paiement->num_payment      = $data['payment_id'];
		$paiement->note_public      = 'Online payment ' . $data['date'] . ' from ' . $data['ipaddress'];
		$paiement->ext_payment_id   = $data['TRANSACTIONID'];
		$paiement->ext_payment_site = $data['service'];
		$paiement->comment          = $data['paymentmethod'];

		dol_syslog("stancerAddPaymentOnObject ask paiement create with " . json_encode($paiement));
		$paiement_id = $paiement->create($user, 1); // This include closing invoices and regenerating documents
		if ($paiement_id < 0) {
			dol_syslog("stancerAddPaymentOnObject paiement create error, result < 0", LOG_ERR);
			$postactionmessages[] = $paiement->error . ' ' . implode("<br>\n", array_filter($paiement->errors, 'strlen'));
			$ispostactionok = -1;
			$error++;
		} else {
			dol_syslog("stancerAddPaymentOnObject paiement create success");
			$postactionmessages[] = 'Payment created';
			$ispostactionok = 1;
		}
		$payment_type_for_bank = 'payment';
		$label = $data['label'];
		$ref = $data['ref'] ?? '';
		//add ref on label
		if ($ref != '' && strpos($ref, $label)) {
			$label .= " " . $ref;
		}
	}

	if ($ispostactionok) {
		dol_syslog("stancerAddPaymentOnObject call addPaymentToBank payment_type_for_bank=$payment_type_for_bank, label=$label bankaccountid=$bankaccountid");
		$result = $paiement->addPaymentToBank($user, $payment_type_for_bank, $label, $bankaccountid, '', '');

		if ($result < 0) {
			dol_syslog("stancerAddPaymentOnObject addPaymentToBank error, result < 0 is $result", LOG_ERR);
			$postactionmessages[] = $paiement->error . ' ' . implode("<br>\n", array_filter($paiement->errors, 'strlen'));
			$ispostactionok = -1;
			$actioncode = "STANCER_PAYMENT_KO";
			$error++;
		} else {
			dol_syslog("stancerAddPaymentOnObject addPaymentToBank success");
			$postactionmessages[] = 'Bank transaction of payment created';
			$ispostactionok = 1;
			$actioncode = "STANCER_PAYMENT_OK";
		}
	}
	//erics debug
	// print "<p>facture avant commit/rollback : $error</p>";
	// print json_encode($postactionmessages);
	// print json_encode($object);

	if (!$error) {
		dol_syslog("stancerAddPaymentOnObject commit then add action comm ....");
		$db->commit();
		stancerAddActionComm($object, $actioncode, $label, $paiement->note_public, $postactionmessages, '');

		//erics add new line on bank account for Stancer Fees ?
		//or in stancer listing ?
		if (getDolGlobalString('STANCER_ADD_FEES') == 'PAYMENT') {
			// FinalFees is always in cents (Stancer API unit); divide once to get euros.
			$fees = $data["FinalFees"] / 100;
			$ref = $data['date'];
			dol_syslog("STANCER_ADD_FEES is on each PAYMENT in paymentback.php ....");
			$resAddPaiment = stancerAddPaimentFeeOnBank($ref, $fees, $bankaccountid, $data['payment_id'], $data['date']);
			if ($resAddPaiment < 0) {
				dol_syslog("stancerAddPaimentFeeOnBank error for " . $object->ref, LOG_ERR);
			}
		}
	} else {
		dol_syslog("stancerAddPaymentOnObject rollback ....");
		$db->rollback();
	}
	return $error;
}

/**
 * Variant of stancerAddPaymentOnObject() for grouped SEPA payments: one Stancer
 * payment spread across several Dolibarr invoices of the same customer.
 * Creates a single Paiement row with amounts dispatched per invoice (Dolibarr
 * native multi-invoice payment), a single bank line, and one ActionComm per invoice.
 *
 * @param   Facture[]  $invoices       array of Facture objects (>= 2, all fetched)
 * @param   array      $data           same shape as stancerAddPaymentOnObject() ($data['FinalPaymentAmt'] is the total amount in main currency unit)
 * @param   string     $errorMessage   detailed error message (by ref)
 * @return  int                        0 on success, -1 on duplicate, < 0 on other error
 */
function stancerAddPaymentOnInvoices(array $invoices, $data, &$errorMessage)
{
	global $langs, $db, $user, $conf;

	$error = 0;
	$postactionmessages = array();
	dol_syslog("stancerAddPaymentOnInvoices: " . count($invoices) . " invoices, data=" . json_encode($data));

	if (count($invoices) < 2) {
		$errorMessage = "stancerAddPaymentOnInvoices called with less than 2 invoices, refusing";
		dol_syslog($errorMessage, LOG_ERR);
		return -10;
	}

	// Dedup: refuse to create a second Paiement for the same Stancer payment id.
	$sql = "SELECT COUNT(*) AS count FROM " . MAIN_DB_PREFIX . "paiement AS p";
	$sql .= " INNER JOIN " . MAIN_DB_PREFIX . "paiement_facture AS pf ON p.rowid = pf.fk_paiement";
	$sql .= " WHERE p.num_paiement = '" . $db->escape($data['payment_id']) . "'";
	$resql = $db->query($sql);
	if ($resql) {
		$obj = $db->fetch_object($resql);
		if ($obj && $obj->count > 0) {
			$errorMessage = "stancerAddPaymentOnInvoices duplicate";
			dol_syslog("stancerAddPaymentOnInvoices duplicate for payment_id=" . $data['payment_id'] . ": " . $obj->count . " existing lines");
			return -1;
		}
	}

	if (empty($data['FinalPaymentAmt'])) {
		$errorMessage = "stancerAddPaymentOnInvoices FinalPaymentAmt empty";
		dol_syslog($errorMessage, LOG_WARNING);
		return -2;
	}
	if (empty($data['date'])) {
		$errorMessage = "stancerAddPaymentOnInvoices date error";
		dol_syslog($errorMessage, LOG_WARNING);
		return -3;
	}

	$bankaccountid = getDolGlobalString('STANCER_BANK_ACCOUNT_FOR_PAYMENTS');
	if (empty($conf->banque->enabled) || empty($bankaccountid)) {
		$errorMessage = "Setup of bank account to use in module Stancer was not set.";
		dol_syslog($errorMessage, LOG_WARNING);
		return -5;
	}

	// Compute per-invoice dispatch amounts = remaining-to-pay for each invoice.
	$amounts = array();
	$refs = array();
	$totalDispatched = 0.0;
	foreach ($invoices as $inv) {
		if ($inv->element !== 'facture' || empty($inv->id)) {
			$errorMessage = "stancerAddPaymentOnInvoices got non-facture or invalid invoice in batch";
			dol_syslog($errorMessage, LOG_ERR);
			return -6;
		}
		$paid = $inv->getSommePaiement() ?? 0;
		$credit = $inv->getSumCreditNotesUsed() ?? 0;
		$deposit = $inv->getSumDepositsUsed() ?? 0;
		$remaining = (float) price2num($inv->total_ttc - $paid - $credit - $deposit, 'MT');
		if ($remaining <= 0) {
			dol_syslog("stancerAddPaymentOnInvoices invoice " . $inv->ref . " has no remaining amount, dispatch 0", LOG_WARNING);
			$remaining = 0;
		}
		$amounts[$inv->id] = $remaining;
		$totalDispatched += $remaining;
		$refs[] = $inv->ref;
	}

	$target = (float) price2num((float) $data['FinalPaymentAmt'], 'MT');

	// Reconcile rounding: if sum differs from Stancer-paid total, adjust the LAST non-zero invoice.
	$diff = (float) price2num($target - $totalDispatched, 'MT');
	if ($diff > 0.0001) {
		// M5: the captured amount exceeds the sum of the invoices' remaining-to-pay.
		// Do NOT force the surplus onto the last invoice (that would over-pay it):
		// each invoice already gets its exact remaining. Log the residue for manual
		// action instead of silently over-paying.
		dol_syslog("stancerAddPaymentOnInvoices: captured amount exceeds sum of remainings by "
			. $diff . " for payment_id=" . $data['payment_id']
			. "; surplus NOT imputed to avoid over-pay (manual action required)", LOG_WARNING);
	} elseif ($diff < -0.0001) {
		// Captured slightly less than the sum of remainings (rounding / partial):
		// absorb the negative diff on the last non-zero invoice.
		dol_syslog("stancerAddPaymentOnInvoices sum mismatch: target=$target dispatched=$totalDispatched diff=$diff, adjusting last invoice", LOG_WARNING);
		$lastNonZeroId = null;
		foreach ($amounts as $iid => $am) {
			if ($am > 0) {
				$lastNonZeroId = $iid;
			}
		}
		if ($lastNonZeroId !== null) {
			$amounts[$lastNonZeroId] = (float) price2num($amounts[$lastNonZeroId] + $diff, 'MT');
		} else {
			$errorMessage = "stancerAddPaymentOnInvoices no invoice has remaining amount to absorb dispatch mismatch";
			dol_syslog($errorMessage, LOG_ERR);
			return -7;
		}
	}

	$db->begin();
	$paiement = new Paiement($db);
	$paiement->datepaye         = stancerDateToTimeStamp($data['date']);
	$paiement->amounts          = $amounts;
	$paiement->paiementid       = $data['paymentTypeId'];
	$paiement->num_payment      = $data['payment_id'];
	$paiement->note_public      = 'Online payment ' . $data['date'] . ' from ' . $data['ipaddress'] . ' (grouped: ' . implode(',', $refs) . ')';
	$paiement->ext_payment_id   = $data['TRANSACTIONID'];
	$paiement->ext_payment_site = $data['service'];
	$paiement->comment          = $data['paymentmethod'];

	dol_syslog("stancerAddPaymentOnInvoices ask paiement create amounts=" . json_encode($amounts));
	$paiement_id = $paiement->create($user, 1);
	if ($paiement_id < 0) {
		dol_syslog("stancerAddPaymentOnInvoices paiement create error: " . $paiement->error, LOG_ERR);
		$postactionmessages[] = $paiement->error . ' ' . implode("<br>\n", array_filter($paiement->errors, 'strlen'));
		$errorMessage = $paiement->error;
		$db->rollback();
		return -8;
	}
	$postactionmessages[] = 'Payment created (grouped, ' . count($invoices) . ' invoices)';

	$label = '(CustomerInvoicePayment) ' . implode('+', $refs);
	dol_syslog("stancerAddPaymentOnInvoices addPaymentToBank label=$label bankaccountid=$bankaccountid");
	$resBank = $paiement->addPaymentToBank($user, 'payment', $label, $bankaccountid, '', '');
	if ($resBank < 0) {
		dol_syslog("stancerAddPaymentOnInvoices addPaymentToBank error: " . $paiement->error, LOG_ERR);
		$postactionmessages[] = $paiement->error . ' ' . implode("<br>\n", array_filter($paiement->errors, 'strlen'));
		$errorMessage = $paiement->error;
		$db->rollback();
		return -9;
	}
	$postactionmessages[] = 'Bank transaction of payment created';

	$db->commit();

	foreach ($invoices as $inv) {
		stancerAddActionComm($inv, 'STANCER_PAYMENT_OK', $label, $paiement->note_public, $postactionmessages, '');
	}

	if (getDolGlobalString('STANCER_ADD_FEES') == 'PAYMENT' && !empty($data['FinalFees'])) {
		// FinalFees is always in cents (Stancer API unit); divide once to get euros.
		$fees = $data['FinalFees'] / 100;
		$resFees = stancerAddPaimentFeeOnBank($data['date'], $fees, $bankaccountid, $data['payment_id'], $data['date']);
		if ($resFees < 0) {
			dol_syslog("stancerAddPaymentOnInvoices stancerAddPaimentFeeOnBank error for grouped payment_id=" . $data['payment_id'], LOG_ERR);
		}
	}

	return $error;
}

/**
 * get informations from order id in case of search by tag returns null for example
 *
 * @param   string  $orderid  order id
 *
 * @return  Propal|Facture|Commande|Don|AdherentStancer object from dolibarr
 */
function getObjectFromOrderID($orderid)
{
	global $langs, $db, $user, $conf;
	$object = null;
	dol_syslog("stancer getObjectFromOrderID $orderid");

	if (isModEnabled("propal")) {
		$object = new Propal($db);
		$result = $object->fetch(0, $orderid);
		if ($result > 0) {
			return $object;
		} else {
			dol_syslog("stancer   getObjectFromOrderID $orderid, can't find propal");
		}
	}

	if (stancerIsModEnabled('invoice')) {
		$object = new Facture($db);
		$result = $object->fetch(0, $orderid);
		if ($result > 0) {
			return $object;
		} else {
			dol_syslog("stancer   getObjectFromOrderID $orderid, can't find invoice");
		}
	}

	if (stancerIsModEnabled('order')) {
		$object = new Commande($db);
		$result = $object->fetch(0, $orderid);
		if ($result) {
			return $object;
		} else {
			dol_syslog("stancer   getObjectFromOrderID $orderid, can't find order");
		}
	}

	if (isModEnabled('don')) {
		$object = new Don($db);
		$result = $object->fetch(0, $orderid);
		if ($result) {
			return $object;
		} else {
			dol_syslog("stancer   getObjectFromOrderID $orderid, can't find don");
		}
	}

	if (stancerIsModEnabled('member')) {
		$object = new AdherentStancer($db);
		$result = $object->fetch(0, $orderid);
		if ($result) {
			return $object;
		} else {
			dol_syslog("stancer   getObjectFromOrderID $orderid, can't find member");
		}
	}

	return null;
}

/**
 * extract informations from TAG then return dolibarr object (invoice / order ...)
 *
 * @param   string       $tag		dolibarr tag
 *
 * @return  Facture|Commande|Don|AdherentStancer object from dolibarr
 */
function getObjectFromTag($tag)
{
	global $langs, $db, $user, $conf;
	$object = null;
	dol_syslog("stancer getObjectFromTag $tag");

	$tmptag = dolExplodeIntoArray($tag, '.', '=');
	if (array_key_exists('INV', $tmptag) && $tmptag['INV'] > 0) {
		$object = new Facture($db);
		$result = $object->fetch($tmptag['INV']);
		if ($result) {
			return $object;
		} else {
			dol_syslog("stancer   getObjectFromTag $tag, can't find invoice");
		}
	} elseif (array_key_exists('ORD', $tmptag) && $tmptag['ORD'] > 0) {
		$object = new Commande($db);
		$result = $object->fetch($tmptag['ORD']);
		if ($result) {
			return $object;
		} else {
			dol_syslog("stancer   getObjectFromTag $tag, can't find order");
		}
	} elseif (array_key_exists('DON', $tmptag) && $tmptag['DON'] > 0) {
		$object = new Don($db);
		$result = $object->fetch($tmptag['DON']);
		if ($result) {
			return $object;
		} else {
			dol_syslog("stancer   getObjectFromTag $tag, can't find don");
		}
	} elseif (array_key_exists('MEM', $tmptag) && $tmptag['MEM'] > 0) {
		$object = new AdherentStancer($db);
		$result = $object->fetch($tmptag['MEM']);
		if ($result) {
			return $object;
		} else {
			dol_syslog("stancer   getObjectFromTag $tag, can't find member");
		}
	} elseif (array_key_exists('PRO', $tmptag) && $tmptag['PRO'] > 0) {
		// Devis: built as 'PRO=<id>' in stancer_payment.lib.php
		$object = new Propal($db);
		$result = $object->fetch($tmptag['PRO']);
		if ($result) {
			return $object;
		} else {
			dol_syslog("stancer   getObjectFromTag $tag, can't find propal");
		}
	}
	return null;
}


/**
 * Build the payment label shown to the customer, per object type
 *
 * @param   CommonObject  $object  Object being paid (invoice, donation, member, propal)
 * @return  string                 Translated label
 */
function stancerChangeLabel($object)
{
	global $mysoc, $langs;
	//les cas particuliers
	if ($object->element == 'don') {
		return $langs->transnoentitiesnoconv("StancerPaymentDonation", $mysoc->name);
	} elseif ($object->element == 'member') {
		return $langs->transnoentitiesnoconv("StancerPaymentMembership");
	} elseif ($object->element == 'propal') {
		return $langs->transnoentitiesnoconv("StancerPaymentPropal", $object->ref);
	}
	//Le cas général
	return $langs->transnoentitiesnoconv("StancerPaymentOrderRef", $object->ref);
}

/**
 * Build the translation key used for the bank entry label, per object type
 *
 * @param   CommonObject  $object  Object being paid (invoice, donation, member)
 * @return  string                 Translation key to use on the bank line
 */
function stancerChangeBankLabel($object)
{
	global $mysoc, $langs;
	//les cas particuliers
	if ($object->element == 'don') {
		return "OnlinePaymentDonation";
	} elseif ($object->element == 'member') {
		return "OnlineSubscriptionPaymentLine";
	}
	//le cal général
	return "(CustomerInvoicePayment)";
}


/**
 * Returns a qrcode of uri
 *
 * @param   string    $uri  URI to encode
 * @return  string|int      PNG image data, -1 when the QRCODE encoding is not available
 */
function stancerQRCodePayment($uri)
{
	$qrmodule = new modTcpdfbarcode();

	$tcpdfEncoding = $qrmodule->getTcpdfEncodingType('QRCODE');
	if (empty($tcpdfEncoding)) {
		return -1;
	}

	$color = array(0, 0, 0);
	$height = 3;
	$width = 3;
	require_once TCPDF_PATH . 'tcpdf_barcodes_2d.php';
	$barcodeobj = new TCPDF2DBarcode($uri, $tcpdfEncoding);
	return $barcodeobj->getBarcodePngData($width, $height, $color);
}


/**
 * Return dolibarr global constant string value
 * @param string $key key to return value, return '' if not set
 * @param string $default value to return
 * @return string
 */
function stancer_getDolGlobalString($key, $default = '')
{
	if (function_exists('getDolGlobalString')) {
		if (((int) DOL_VERSION) < 15) {
			$res = getDolGlobalString($key);
			if (empty($res)) {
				$res = $default;
			}
			return $res;
		} else {
			// @phpstan-ignore-next-line
			return getDolGlobalString($key, $default);
		}
	}
	global $conf;
	// return $conf->global->$key ?? $default;
	return (string) (empty($conf->global->$key) ? $default : $conf->global->$key);
}

/**
 * check if input is not timestamp then convert it to timestamp
 *
 * @param   int|string  $input  date to convert
 *
 * @return  int timestamp corresponding to input
 */
function stancerDateToTimeStamp($input)
{
	dol_syslog("stancerDateToTimeStamp : $input");
	$ts = 0;
	if (is_int($input)) {
		$ts = $input;
	} elseif (is_string($input)) {
		$tp = date_parse($input);
		$ts = mktime(
			$tp['hour'],
			$tp['minute'],
			$tp['second'],
			$tp['month'],
			$tp['day'],
			$tp['year']
		);
	} else {
		// ?
		$ts = dol_now();
	}
	return $ts;
}


/**
 * corrige les dates sur le compte bancaire principal pour que l'export FEC soit correct
 * (entre autre)
 * @return  [type]  [return description]
 */
function stancerUpdateAllDatesOnMainBankAccount()
{
	global $db;

	$sql = "SELECT payout_id, date_bank FROM " . MAIN_DB_PREFIX . "stancer_stancer_payouts";
	$resql = $db->query($sql);
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			$pid = $obj->payout_id;
			$datebank = $obj->date_bank;

			$sqlupdate = "UPDATE " . MAIN_DB_PREFIX . "bank SET dateo='" . $datebank . "', datev='" . $datebank . "' WHERE num_chq='" . $pid . "' AND amount > 0";
			//print $sql . "<br />";
			$resqlupdate = $db->query($sqlupdate);
		}
	}
}

/**
 * avec banking4doli
 *
 * @return  void
 */
function stancerUpdateAllDatesOnMainBankAccountFromBanking4Doli()
{
	global $db;

	$sql = "SELECT b.rowid as bid, b.datev,b.dateo,fk_bank,fk_bank_record,b4dr.record_date FROM " . MAIN_DB_PREFIX . "banking4dolibarr_bank_record_link as b4d";
	$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "bank as b ON b4d.fk_bank=b.rowid";
	$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "banking4dolibarr_bank_record as b4dr ON b4d.fk_bank_record=b4dr.rowid";
	$sql .= " WHERE datev!=record_date AND record_date>='2023-01-01'";
	// print $sql;
	$resql = $db->query($sql);
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			$datebank = $obj->record_date;
			$id = $obj->bid;

			$sqlupdate = "UPDATE " . MAIN_DB_PREFIX . "bank SET dateo='" . $datebank . "', datev='" . $datebank . "' WHERE rowid='" . $id . "'";
			// print $sqlupdate . "<br />";
			$resqlupdate = $db->query($sqlupdate);
		}
	}
}


/**
 * refunds special case
 *
 * @return  [type]  [return description]
 */
function stancerAddRefundFeesToBank()
{
	global $db, $conf;
	$stancerApi = StancerApi::getInstance();
	$sp = new Stancer_payouts($db);
	$resList = $sp->fetchAll('ASC', '', 0, 0, array('customsql' => "amount < '0'"));
	try {
		$counter = 0;
		foreach ($resList as $oneSp) {
			$payoutID = $oneSp->payout_id;
			dol_syslog("stancerAddRefundFeesToBank pid=" . $oneSp->payout_id . ", status=" . Stancer_payouts::STATUS_PAID);
			$payoutData = $stancerApi->getPayout($oneSp->payout_id);
			$counter++;
			if ($payoutData === false) {
				dol_syslog("stancerAddRefundFeesToBank error fetching payout: " . $stancerApi->error, LOG_WARNING);
				continue;
			}
			dol_syslog("stancerAddRefundFeesToBank counter #$counter payout payoutID=$payoutID :: payout=" . json_encode($payoutData));

			$payoutStatus = isset($payoutData['status']) ? $payoutData['status'] : '';
			if ($payoutStatus != 'paid') {
				dol_syslog("stancerAddRefundFeesToBank status is not paid, payout->status=" . $payoutStatus);
				continue;
			}

			dol_syslog("STANCER_ADD_FEES is on each REFUNDS ....");

			// print json_encode($oneSp);
			$dateo = $oneSp->date_paym;
			$datev = $oneSp->date_bank;
			$fees = $oneSp->fees / 100;
			if (!empty($dateo) && ($fees  > 0)) {
				// erics add new line on bank account for Stancer Fees
				$ref = $oneSp->payout_id;
				$bankaccountid = getDolGlobalString('STANCER_BANK_ACCOUNT_FOR_PAYMENTS');
				dol_syslog("STANCER_ADD_FEES is on each REFUNDS ....");
				$resAddPaiment = stancerAddPaimentFeeOnBank($ref, $fees, $bankaccountid, $ref, $dateo, $datev);
			}
		}
	} catch (Exception $e) {
		$message = $e->getMessage();
		dol_syslog("stancerAddRefundFeesToBank exception (1) occurs for payoutID=$payoutID message=" . $message, LOG_ERR);
	}
}


/**
 * check lines on stancer bank account and (re)add if needed
 *
 * @return  [type]  [return description]
 */
function stancerCheckBankLines()
{
	global $db, $conf, $user;
	$error = 0;
	$stancerApi = new StancerApi();
	$bankaccountid = (int) getDolGlobalString('STANCER_BANK_ACCOUNT_FOR_PAYMENTS');


	// $dateFiltre = time() - (3600*24*400);
	// $list = Stancer\Payment::list(['created' => $dateFiltre, 'start'=>0, 'limit' => 100]);
	// $json = $list->get();
	// print json_encode($json);
	// print "<br />";
	// foreach($list as $p) {
	// 	// $json = $p->populate()->get();
	// 	print json_encode($p) . " amount=" . $p->getAmount() . ", method=" . $p->getMethod() . ", status=" . $p->getStatus();
	// 	print "<br />";
	// }
	// exit;

	// $payout = new Stancer\Payout("pout_oHTo1MF0v7n5BLYiLo2s3gnF");
	// $json = $payout->populate()->get();

	// //libelle du virement bancaire
	// $details = $payout->getStatementDescription();
	// print json_encode($details);exit;
	// print json_encode($json);exit;


	$sp = new Stancer_payments($db);
	$spu = new Stancer_payments($db);
	//Vérification que nous n'avons pas déjà un CB en cours ....
	$resSP = $sp->fetchAll('ASC', '', 0, 0, array('customsql' => "live_mode = '" . getDolGlobalString('STANCER_IS_PROD') . "' AND status='" . Stancer_payments::STATUS_CAPTURED . "'"));


	$account = new Account($db);
	$result = $account->fetch($bankaccountid);
	if ($result < 0) {
		print "<p>Error, fetching account</p>";
		return -1;
	}

	$db->begin();
	foreach ($resSP as $key => $oneSp) {
		// print "<p>Key = $key, val=";
		// print(json_encode($oneSp));
		// print "</p>";exit;
		$dateo = $oneSp->created;
		$datev = $oneSp->date_bank;
		$fees = $oneSp->fees / 100;
		$amount = $oneSp->amount / 100;
		$ref = $oneSp->stancer_id;
		$type = "";
		if ($oneSp->method == "card") {
			$type = "CB";
		} elseif ($oneSp->method == "sepa") {
			$type = "PRE";
		}

		$label = 'Stancer ' . $ref;
		if (!empty($dateo)) {
			$label .= " " . $dateo;
		}

		$remotestatusTxt = "";
		$remotestatus = "";

		$update = false;
		//double check with stancer api
		$paymentData = $stancerApi->getPayment($ref);
		if ($paymentData === false) {
			print "<p>Stancer error : " . $stancerApi->error . " for $ref</p>";
			print "<p>Local data : " . json_encode($oneSp) . "</p>";
			$update = false;
		} else {
			$remotestatusTxt = isset($paymentData['status']) ? $paymentData['status'] : '';
			$remotestatus = $sp->convert_status_code($remotestatusTxt);
			$update = true;
		}

		//mise à jour au passage
		// print "<p>Faut il mettre à jour le status : avant=".$oneSp->status.", apres=".$remotestatus." pour ".$ref."</p>";
		if ($update && $remotestatus != $oneSp->status) {
			print "<p>Mise à jour de la base locale a partir des données distantes (avant=" . $oneSp->status . ", apres=" . $remotestatus . ") pour " . $ref . "</p>";
			dol_syslog("stancer update local database from remote data (before=" . $oneSp->status . ", new=" . $remotestatus . ") for " . $ref);
			$resSpu = $spu->fetch(0, '', $ref);
			if ($resSpu) {
				if ($remotestatus == "") {
					$status = Stancer_payments::STATUS_DRAFT;
				} else {
					$status = $remotestatus;
				}
				$resupdate = $spu->setStatusCommon($user, $status, 1);
				if ($resupdate < 0) {
					print "<p>Erreur de mise à jour pour $ref</p>";
					exit;
				}
				//passe au suivant si ce n'était pas un paiement capturé
				if ($remotestatus != 2) {
					continue;
				}
			} else {
				print "<p>ERREUR fetch $ref</p>";
			}
		}

		if (stancerBankLineDoesNotExists($ref, $bankaccountid, (float) price2num($amount))) {
			dol_syslog("stancerCheckBankLines no duplicate entries found");
			$bank_line_id = $account->addline($dateo, $type, $label, (float) price2num($amount), $ref, '', $user);
			if (!($bank_line_id > 0)) {
				dol_syslog("stancerCheckBankLines add line error for " . $ref . "");
				$error++;
				$db->rollback();
				return;
			}
		} else {
			dol_syslog("stancerCheckBankLines duplicate entries for " . $ref . " found, do not add line");
		}


		if (!empty($dateo) && ($fees  > 0)) {
			// erics add new line on bank account for Stancer Fees
			dol_syslog("STANCER_ADD_FEES redo ....");
			$resAddPaiment = stancerAddPaimentFeeOnBank($ref, $fees, $bankaccountid, $ref, $dateo, $datev);
		}
	}

	if ($error > 0) {
		print "<p>Error, rollback transaction</p>";
		$db->rollback();
	} else {
		$db->commit();
	}
}

/**
 * update VIR on main account with what Stancer "put" on bank label
 * to make it easy to reconcile
 *
 * @param   string  $payoutID  Stancer payout id (pout_xxx)
 * @return  void
 */
function stancerUpdateLabelOnMainAccount($payoutID)
{
	global $db, $conf;
	$stancerApi = StancerApi::getInstance();
	$fk_account = getDolGlobalString('STANCER_BANK_MAIN_ACCOUNT_FOR_PAYOUTS');
	$payoutData = $stancerApi->getPayout($payoutID);
	if ($payoutData === false) {
		dol_syslog("stancerUpdateLabelOnMainAccount error: " . $stancerApi->error);
		return;
	}
	$labelTo = isset($payoutData['statement_description']) ? $payoutData['statement_description'] : '';
	$sql = "UPDATE " . MAIN_DB_PREFIX . "bank SET label='" . $db->escape($labelTo) . "' WHERE num_chq='" . $db->escape($payoutID) . "' AND fk_account='" . $db->escape($fk_account) . "'";
	$resql = $db->query($sql);
	if (!$resql) {
		dol_syslog("stancerUpdateLabelOnMainAccount sql error : $sql");
	}
}
