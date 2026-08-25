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
 * \file    stancer/lib/stancer_refresh.lib.php
 * \ingroup stancer
 * \brief   API sync functions (refresh payments, payouts from Stancer)
 */

/**
 * les vieux paiements brouillons de plus de 1 mois -> supprimer de la base locale
 *
 * @return  stdClass  Report object, ->error is set when a write failed
 */
function stancerRemoveOldDraftPayments()
{
	dol_syslog("stancerRemoveOldDraftPayments");
	global $langs, $db, $user, $conf;
	// M6: return a consistent object (with ->error) like the other pipeline
	// steps that chain on $res->error, and never ignore a write failure.
	$output = new stdClass();
	$output->error = '';
	$sp = new Stancer_payments($db);
	$ladate = dol_print_date((time() - (3600 * 24 * 31 * 1)), '%Y-%m-%d');
	$res = $sp->fetchAll('ASC', '', 0, 0, array('customsql' => "live_mode = '" . getDolGlobalString('STANCER_IS_PROD') . "' AND status = '" . Stancer_payments::STATUS_DRAFT . "' AND date_creation < '" . $ladate . "'"));
	if (!is_array($res)) {
		$output->error = "stancerRemoveOldDraftPayments fetchAll failed: " . $sp->error;
		dol_syslog($output->error, LOG_ERR);
		return $output;
	}
	foreach ($res as $s) {
		$s->status = Stancer_payments::STATUS_HIDDEN;
		$resUpdate = $s->update($user);
		if ($resUpdate < 0) {
			dol_syslog("stancerRemoveOldDraftPayments update failed for payment " . $s->stancer_id . ": " . $s->error, LOG_ERR);
		}
	}
	return $output;
}


/** re télécharge tous les paiements et fais le job
 *  à partir de la liste stockée dans dolibarr
 *  (mutualisation entre payments_list et cron)
 *
 * @param bool       $userMessage         show messages to user
 * @param int|null   $lastrun             timestamp of last run (cron mode)
 * @param bool       $sendNotifications   if false, all email notifications (admin + customer)
 *                                        are skipped. Useful when the user wants to refresh
 *                                        data without flooding mailboxes.
 * @param array|null $selectedIds         If not null, restrict the refresh to these local rowid
 *                                        values and enable "audit mode": ignore the
 *                                        "status <> CAPTURED" and date filters, and bypass the
 *                                        "invoice already paid" short circuits to always call the
 *                                        Stancer API and reconcile divergences.
 * @return stdClass                       Report object, ->error is set on failure, ->output holds the CSV report
 */
function stancerRefreshAllPaymentsFromDolibarr($userMessage = true, $lastrun = null, $sendNotifications = true, $selectedIds = null)
{
	$forceAudit = ($selectedIds !== null);
	dol_syslog("stancerRefreshAllPaymentsFromDolibarr, lastrun=$lastrun, sendNotifications=" . ($sendNotifications ? 'true' : 'false') . ", forceAudit=" . ($forceAudit ? 'true' : 'false'));
	global $langs, $db, $user, $conf;
	$stancerApi = new StancerApi();

	$mailNotif = false;
	if ($sendNotifications && getDolGlobalString('STANCER_AUTO_MAIL_NOTIFICATIONS_PAYMENT', '') != '' && getDolGlobalString('STANCER_AUTO_MAIL_NOTIFICATIONS_PAYMENT_DETAILS', '') != '') {
		$mailNotif = true;
	}

	$output = new StdClass();
	$output->error = '';
	$output->message = '';
	$output->data = array();

	$sp = new Stancer_payments($db);

	if ($forceAudit) {
		$cleanIds = array_filter(array_map('intval', (array) $selectedIds));
		if (empty($cleanIds)) {
			dol_syslog("stancerRefreshAllPaymentsFromDolibarr forceAudit with empty selection, nothing to do");
			return $output;
		}
		// The intval() cast stays inside the implode(): the SQL guard reads the
		// concatenated line, it does not follow the value back to $cleanIds.
		$customsql = "live_mode = '" . getDolGlobalString('STANCER_IS_PROD') . "' AND rowid IN (" . implode(',', array_map('intval', $cleanIds)) . ")";
	} else {
		//Refresh sur un historique de ... 1 mois, 60 jours ... autre ?
		$nbjour = stancerGetNumberOfDaysToGet();
		$ladate = dol_print_date((time() - (3600 * 24 * $nbjour)), '%Y-%m-%d');
		//Si c'est la tache planifiée on fait la diff par rapport à la date de dernier lancement
		if (!empty($lastrun)) {
			$ladate = dol_print_date($lastrun, '%Y-%m-%d');
		}
		// Exclude HIDDEN: rows the cron explicitly marked as ineligible (linked invoice/order
		// switched away from Stancer payment mode or bank account, see
		// stancerIsObjectStillEligibleForStancer()). Without this, the merchant keeps getting
		// daily "Erreur de paiement" emails for resolved cases.
		// Normal rows: only unfinalized (not CAPTURED, not HIDDEN). Grouped SEPA payments are
		// always re-examined even when CAPTURED: FromStancer defers them here, and the grouped
		// path has a cheap "all invoices already paid" short circuit, so re-processing a settled
		// group is safe and lets stuck groups (CAPTURED locally but invoices unpaid) self-heal.
		$customsql = "live_mode = '" . getDolGlobalString('STANCER_IS_PROD') . "'";
		$customsql .= " AND ((status <> '" . Stancer_payments::STATUS_CAPTURED . "' AND status <> '" . Stancer_payments::STATUS_HIDDEN . "')";
		$customsql .= "      OR (grouped_invoice_ids IS NOT NULL AND grouped_invoice_ids <> ''))";
		$customsql .= " AND date_creation > '" . $db->escape($ladate) . "'";
	}

	$resList = $sp->fetchAll('ASC', '', 0, 0, array('customsql' => $customsql));
	foreach ($resList as $dolibarrPayment) {
		//dol_syslog(json_encode($dolibarrPayment));exit;
		$paymentId = $dolibarrPayment->stancer_id;
		if (empty($paymentId)) {
			dol_syslog("stancerRefreshAllPaymentsFromDolibarr paymentId empty, next");
			//pas la peine d'aller plus loin
			continue;
		}

		$sploc = new Stancer_payments($db);
		$res = $sploc->fetch(0, null, $paymentId);

		// Grouped SEPA payment path: this Stancer payment covers several Dolibarr invoices
		// of the same customer (see STANCER_SEPA_GROUP_SAME_DAY). The unique_id is GRP=<hash>.CUS=...
		// and the comma-separated list of invoice ids is stored in grouped_invoice_ids.
		if (!empty($dolibarrPayment->grouped_invoice_ids)) {
			dol_syslog("stancerRefreshAllPaymentsFromDolibarr $paymentId is a grouped payment, invoice_ids=" . $dolibarrPayment->grouped_invoice_ids);

			$ids = array_filter(array_map('intval', explode(',', (string) $dolibarrPayment->grouped_invoice_ids)));
			$groupedInvoices = array();
			foreach ($ids as $iid) {
				$inv = new Facture($db);
				if ($inv->fetch($iid) <= 0) {
					dol_syslog("stancerRefreshAllPaymentsFromDolibarr $paymentId grouped: cannot fetch invoice id=$iid, skip whole group", LOG_ERR);
					continue 2;
				}
				$groupedInvoices[] = $inv;
			}
			if (count($groupedInvoices) < 2) {
				dol_syslog("stancerRefreshAllPaymentsFromDolibarr $paymentId grouped: fewer than 2 invoices resolved, skip", LOG_ERR);
				continue;
			}

			// Short-circuit: if every invoice is already fully paid, mark the local Stancer_payments captured and move on.
			$allPaid = true;
			foreach ($groupedInvoices as $inv) {
				$paidLoc = $inv->getSommePaiement() ?? 0;
				// @phan-suppress-next-line PhanDeprecatedProperty  $paye is the column Dolibarr 15..21 fills and still writes; status == 2 also covers abandoned invoices
				if (!((float) price2num($paidLoc, 'MT') >= (float) price2num($inv->total_ttc, 'MT') - 0.01 || $inv->paye == 1)) {
					$allPaid = false;
					break;
				}
			}
			if ($allPaid) {
				dol_syslog("stancerRefreshAllPaymentsFromDolibarr $paymentId grouped: all invoices already paid, capture and next");
				foreach ($groupedInvoices as $inv) {
					// @phan-suppress-next-line PhanDeprecatedProperty  $paye is the column Dolibarr 15..21 fills and still writes; status == 2 also covers abandoned invoices
					if ($inv->paye == 0 && $inv->setPaid($user) < 0) {
						dol_syslog("stancerRefreshAllPaymentsFromDolibarr $paymentId grouped: setPaid failed on invoice " . $inv->ref . ": " . $inv->error, LOG_ERR);
					}
				}
				$sploc->status = Stancer_payments::STATUS_CAPTURED;
				if ($sploc->update($user) < 0) {
					dol_syslog("stancerRefreshAllPaymentsFromDolibarr $paymentId grouped: local status update failed: " . $sploc->error, LOG_ERR);
				}
				continue;
			}

			dol_syslog("stancerRefreshAllPaymentsFromDolibarr $paymentId grouped: fetch details from Stancer");
			$paymentData = $stancerApi->getPayment($paymentId);
			if ($paymentData === false) {
				if ($stancerApi->lastHttpCode == 401) {
					dol_syslog("stancerRefreshAllPaymentsFromDolibarr 401 auth error on grouped, aborting", LOG_ERR);
					$output->error = $stancerApi->error;
					setEventMessages($langs->trans('StancerApiAuthError', $stancerApi->error), null, 'errors');
					return $output;
				}
				dol_syslog("stancerRefreshAllPaymentsFromDolibarr $paymentId grouped: getPayment error " . $stancerApi->error, LOG_ERR);
				continue;
			}

			// SAFETY (cross-customer guard): verify the Stancer payment actually corresponds to
			// THIS grouped record. A corrupted local stancer_id (e.g. from the customer-id mixing
			// incident) can point to ANOTHER customer's payment; ventilating it would cross-attribute
			// funds between thirdparties. The local unique_id is the deterministic GRP=<hash>.CUS=<socid>
			// tag we generated for this group; the API must return the very same unique_id. If they
			// diverge, the stancer_id is not the one we created for this group -> abort, do NOT run
			// fillDataFromApi (which would further overwrite the row) and flag for manual review.
			$apiUniqueId = isset($paymentData['unique_id']) ? (string) $paymentData['unique_id'] : '';
			if ($apiUniqueId !== '' && $apiUniqueId !== (string) $sploc->unique_id) {
				dol_syslog("stancerRefreshAllPaymentsFromDolibarr $paymentId grouped: SAFETY ABORT unique_id mismatch (local=" . $sploc->unique_id . ", api=" . $apiUniqueId . ") - stancer_id points to a different payment, skipping ventilation, row needs manual review", LOG_ERR);
				$output->message .= $paymentId . ";grouped;SAFETY_MISMATCH;local=" . $sploc->unique_id . ";api=" . $apiUniqueId . ";\n";
				continue;
			}

			$amount = isset($paymentData['amount']) ? (int) $paymentData['amount'] : 0;
			$paymentStatus = isset($paymentData['status']) ? $paymentData['status'] : '';
			if (empty($amount) || empty($paymentStatus) || $paymentStatus == 'to_capture') {
				continue;
			}

			$resFill = $sploc->fillDataFromApi($paymentData);
			if ($resFill >= 0 && $res) {
				if ($sploc->update($user) < 0) {
					dol_syslog("stancerRefreshAllPaymentsFromDolibarr $paymentId grouped: local status update failed: " . $sploc->error, LOG_ERR);
				}
			}

			$method = isset($paymentData['method']) ? $paymentData['method'] : '';
			$paymentType = ($method == 'card') ? 'CB' : (($method == 'sepa') ? 'PRE' : '');
			$paymentTypeId = dol_getIdFromCode($db, $paymentType, 'c_paiement', 'code', 'id', 1);
			if (empty($paymentTypeId)) {
				dol_syslog("stancerRefreshAllPaymentsFromDolibarr $paymentId grouped: unknown paymentType $paymentType in dictionary", LOG_ERR);
				continue;
			}

			$datebank = null;
			if (isset($paymentData['date_bank']) && $paymentData['date_bank'] !== '') {
				$datebank = is_numeric($paymentData['date_bank']) ? new DateTime('@' . $paymentData['date_bank']) : new DateTime($paymentData['date_bank']);
			}
			if (empty($datebank) && !empty($paymentData['date_paym'])) {
				$datebank = is_numeric($paymentData['date_paym']) ? new DateTime('@' . $paymentData['date_paym']) : new DateTime($paymentData['date_paym']);
			}

			$listOfPaidStatus = array('captured', 'capture_sent');
			if ($datebank && $amount > 0 && in_array($paymentStatus, $listOfPaidStatus)) {
				$date = (string) $datebank->format('Y-m-d');
				$data = array(
					'payment_id'     => $paymentId,
					'date'           => $date,
					'FinalPaymentAmt' => ($amount / 100),
					'paymentType'    => $paymentType,
					'paymentTypeId'  => $paymentTypeId,
					'ipaddress'      => '127.0.0.1',
					'TRANSACTIONID'  => $paymentId,
					'service'        => 'stancer',
					'paymentmethod'  => 'stancer',
					'label'          => '(CustomerInvoicePayment)',
					'FinalFees'      => isset($paymentData['fee']) ? $paymentData['fee'] : 0,
					'ref'            => $sploc->order_id,
				);
				$errorMessage = '';
				$errGrouped = stancerAddPaymentOnInvoices($groupedInvoices, $data, $errorMessage);
				if ($errGrouped == 0) {
					dol_syslog("stancerRefreshAllPaymentsFromDolibarr $paymentId grouped: dispatch SUCCESS for " . count($groupedInvoices) . " invoices");
					$output->message .= $paymentId . ";grouped(" . count($groupedInvoices) . ");" . number_format($amount / 100, 2) . "EUR;SUCCESS;\n";
					if ($mailNotif) {
						foreach ($groupedInvoices as $inv) {
							$inv->fetch_thirdparty();
							$customerName = is_object($inv->thirdparty) ? $inv->thirdparty->name : '';
							$refUrl = stancerBuildInvoiceLink($inv);
							$objTrackid = 'inv' . $inv->id;
							stancerSendMail(getDolGlobalString('STANCER_AUTO_MAIL_NOTIFICATIONS_PAYMENT', ''), $langs->transnoentitiesnoconv('StancerMailSubjectPaymentConfirm', $inv->ref, price($amount / 100)), $langs->transnoentitiesnoconv('StancerMailPaymentConfirm', price($amount / 100), $refUrl, $customerName), false, '', $objTrackid);
						}
					}
				} elseif ($errGrouped == -1) {
					dol_syslog("stancerRefreshAllPaymentsFromDolibarr $paymentId grouped: duplicate, not an error");
				} else {
					dol_syslog("stancerRefreshAllPaymentsFromDolibarr $paymentId grouped: dispatch error $errGrouped: $errorMessage", LOG_ERR);
					$output->message .= $paymentId . ";grouped(" . count($groupedInvoices) . ");" . number_format($amount / 100, 2) . "EUR;ERROR " . $errorMessage . ";\n";
				}
			} else {
				// Failure statuses on grouped: call reopen ONCE (it handles the whole group internally and returns array<Facture>).
				$failureStatuses = array('disputed', 'refused', 'expired', 'failed');
				if (in_array($paymentStatus, $failureStatuses)) {
					$reopenActionCode = 'BILL_REOPEN_FAILED_' . strtoupper($paymentStatus);
					// Idempotence: skip if the action was already created on the first invoice of the group.
					$firstInv = $groupedInvoices[0];
					$actioncommReopenCheck = new ActionComm($db);
					$existingReopen = $actioncommReopenCheck->getActions($firstInv->socid, $firstInv->id, 'invoice', " AND code='AC_" . $db->escape($reopenActionCode) . "'");
					if (empty($existingReopen)) {
						$reopenRes = stancerReopenInvoiceFromPayment($paymentId, $langs->transnoentitiesnoconv('StancerPaymentFailedReopenReason', $paymentStatus, $paymentId));
						if (is_array($reopenRes)) {
							foreach ($reopenRes as $reopenedInv) {
								stancerAddActionComm($reopenedInv, $reopenActionCode, $langs->transnoentitiesnoconv('StancerPaymentFailedReopenTitle', $reopenedInv->ref), $langs->transnoentitiesnoconv('StancerPaymentFailedReopenReason', $paymentStatus, $paymentId), array(), '');
							}
						}
					}
				}
			}
			continue;
		}

		//objet à partir de unique_id, puis fallback sur order_id
		$obj = getObjectFromTag($dolibarrPayment->unique_id);
		dol_syslog("stancerRefreshAllPaymentsFromDolibarr $paymentId getObjectFromTag object=" . json_encode($obj));
		if (empty($obj)) {
			$obj = getObjectFromOrderID($dolibarrPayment->order_id);
			if (empty($obj)) {
				dol_syslog("stancerRefreshAllPaymentsFromDolibarr $paymentId nothing found from unique_id=" . $dolibarrPayment->unique_id . " nor order_id=" . $dolibarrPayment->order_id . ", skip");
				continue;
			}
			dol_syslog("stancerRefreshAllPaymentsFromDolibarr $paymentId resolved via order_id, element=" . $obj->element . " id=" . $obj->id);
		}

		$objRef = $obj->ref;
		// In audit mode (mass action on selected rows) we DO NOT short-circuit on
		// "invoice already paid": we want every payment to be re-checked against
		// the Stancer API so that divergences (failed payment marked paid locally,
		// captured payment with no Dolibarr Paiement, etc.) can be reconciled.
		if (!$forceAudit) {
			//facture dont il ne reste rien à payer -> short circuit
			if ($obj->element == 'facture') {
				$paid = $obj->getSommePaiement() ?? 0;
				// price2num() returns a numeric string that PHP coerces in this comparison; the
				// literal form is pinned by the M7 guard test (StancerAmountComparisonTest).
				// @phan-suppress-next-line PhanTypeInvalidLeftOperandOfNumericOp
				if (price2num($paid, 'MT') >= price2num($obj->total_ttc, 'MT') - 0.01) {
					dol_syslog("stancerRefreshAllPaymentsFromDolibarr $paymentId that invoice was paid, change status and do short circuit, next, ref=$objRef");
					if ($obj->setPaid($user) < 0) {
						dol_syslog("stancerRefreshAllPaymentsFromDolibarr $paymentId setPaid failed on invoice $objRef: " . $obj->error, LOG_ERR);
					}
					//stancer status could be stucked to "in progress"
					$sploc->status = Stancer_payments::STATUS_CAPTURED;
					if ($sploc->update($user) < 0) {
						dol_syslog("stancerRefreshAllPaymentsFromDolibarr $paymentId local status update failed: " . $sploc->error, LOG_ERR);
					}
					continue;
				}
				// @phan-suppress-next-line PhanDeprecatedProperty  $paye is the column Dolibarr 15..21 fills and still writes; status == 2 also covers abandoned invoices
				if ($obj->paye == 1) {
					dol_syslog("stancerRefreshAllPaymentsFromDolibarr $paymentId that invoice is marked as paid short circuit, next, ref=$objRef");
					//stancer status could be stucked to "in progress" for sure if we are in that portion of code
					$sploc->status = Stancer_payments::STATUS_CAPTURED;
					if ($sploc->update($user) < 0) {
						dol_syslog("stancerRefreshAllPaymentsFromDolibarr $paymentId local status update failed: " . $sploc->error, LOG_ERR);
					}
					continue;
				}
			}
			if ($obj->element == 'commande') {
				dol_syslog("stancerRefreshAllPaymentsFromDolibarr $paymentId is an order, fetch linked invoices");
				$obj->fetchObjectLinked($obj->id, $obj->element, null, 'facture');
				foreach ($obj->linkedObjectsIds as $objecttype => $linkedobj) {
					foreach ($linkedobj as $key => $facid) {
						$inv = new Facture($db);
						$resInv = $inv->fetch($facid);
						if ($resInv) {
							$paid = $inv->getSommePaiement() ?? 0;
							// price2num() returns a numeric string that PHP coerces in this comparison; the
							// literal form is pinned by the M7 guard test (StancerAmountComparisonTest).
							// @phan-suppress-next-line PhanTypeInvalidLeftOperandOfNumericOp
							if (price2num($paid, 'MT') >= price2num($inv->total_ttc, 'MT') - 0.01) {
								dol_syslog("stancerRefreshAllPaymentsFromDolibarr $paymentId invoice " . $inv->ref . " linked to that order was paid, change status and do short circuit, next, ref=$objRef");
								dol_syslog("stancerRefreshAllPaymentsFromDolibarr paid=$paid, total_ttc=" . $inv->total_ttc);
								// @phan-suppress-next-line PhanDeprecatedProperty  $paye is the column Dolibarr 15..21 fills and still writes; status == 2 also covers abandoned invoices
								if ($inv->paye == 0 && $inv->setPaid($user) < 0) {
									dol_syslog("stancerRefreshAllPaymentsFromDolibarr $paymentId setPaid failed on invoice " . $inv->ref . ": " . $inv->error, LOG_ERR);
								}
								if ($obj->status != Commande::STATUS_CLOSED && $obj->cloture($user, 1) < 0) {
									dol_syslog("stancerRefreshAllPaymentsFromDolibarr $paymentId cloture failed on order $objRef: " . $obj->error, LOG_ERR);
								}
								//stancer status could be stucked to "in progress"
								$sploc->status = Stancer_payments::STATUS_CAPTURED;
								if ($sploc->update($user) < 0) {
									dol_syslog("stancerRefreshAllPaymentsFromDolibarr $paymentId local status update failed: " . $sploc->error, LOG_ERR);
								}
								continue 3;
							}
						}
					}
				}
			}
			if ($obj->element == 'propal') {
				dol_syslog("stancerRefreshAllPaymentsFromDolibarr $paymentId is a propal, fetch linked invoices");
				$obj->fetchObjectLinked($obj->id, $obj->element, null, 'facture');
				foreach ($obj->linkedObjectsIds as $objecttype => $linkedobj) {
					foreach ($linkedobj as $key => $facid) {
						$inv = new Facture($db);
						$resInv = $inv->fetch($facid);
						if ($resInv) {
							$paid = $inv->getSommePaiement() ?? 0;
							// price2num() returns a numeric string that PHP coerces in this comparison; the
							// literal form is pinned by the M7 guard test (StancerAmountComparisonTest).
							// @phan-suppress-next-line PhanTypeInvalidLeftOperandOfNumericOp
							if (price2num($paid, 'MT') >= price2num($inv->total_ttc, 'MT') - 0.01) {
								dol_syslog("stancerRefreshAllPaymentsFromDolibarr $paymentId invoice " . $inv->ref . " linked to that propal was paid, change status and do short circuit, next, ref=$objRef");
								// @phan-suppress-next-line PhanDeprecatedProperty  $paye is the column Dolibarr 15..21 fills and still writes; status == 2 also covers abandoned invoices
								if ($inv->paye == 0 && $inv->setPaid($user) < 0) {
									dol_syslog("stancerRefreshAllPaymentsFromDolibarr $paymentId setPaid failed on invoice " . $inv->ref . ": " . $inv->error, LOG_ERR);
								}
								//stancer status could be stucked to "in progress"
								$sploc->status = Stancer_payments::STATUS_CAPTURED;
								if ($sploc->update($user) < 0) {
									dol_syslog("stancerRefreshAllPaymentsFromDolibarr $paymentId local status update failed: " . $sploc->error, LOG_ERR);
								}
								continue 3;
							}
						}
					}
				}
			}
		} // end if (!$forceAudit)

		dol_syslog("stancerRefreshAllPaymentsFromDolibarr try to find details for : " . $paymentId);
		$paymentData = $stancerApi->getPayment($paymentId);
		if ($paymentData === false) {
			$message = $stancerApi->error;

			if ($stancerApi->lastHttpCode == 401) {
				dol_syslog("stancerRefreshAllPaymentsFromDolibarr 401 auth error, aborting", LOG_ERR);
				$output->error = $message;
				setEventMessages($langs->trans('StancerApiAuthError', $message), null, 'errors');
				return $output;
			}

			if ($mailNotif) {
				stancerSendMail(getDolGlobalString('STANCER_AUTO_MAIL_NOTIFICATIONS_PAYOUT', ''), $langs->trans('StancerMailSubjectPayoutExeption'), $langs->trans('StancerMailPayoutExeption', $message));
			}
			dol_syslog("stancerRefreshAllPaymentsFromDolibarr error for paymentId=$paymentId message=" . $message, LOG_ERR);
			$output->error = "stancerRefreshAllPaymentsFromDolibarr error for paymentId=$paymentId message=" . $message;
			continue;
		}
		dol_syslog("stancerRefreshAllPaymentsFromDolibarr details for : " . $paymentId . " loaded");
		// Audit-friendly dump of the API response: we log the fields that drive
		// every subsequent decision (status, amount, method, response code, dates,
		// fee) so that troubleshooting a non-reconciled payment can be done from
		// the log alone, without re-curling the Stancer API. The card/sepa nested
		// objects are stripped to keep PII out of the log.
		$apiDumpFields = array(
			'status'    => isset($paymentData['status']) ? $paymentData['status'] : null,
			'amount'    => isset($paymentData['amount']) ? $paymentData['amount'] : null,
			'currency'  => isset($paymentData['currency']) ? $paymentData['currency'] : null,
			'method'    => isset($paymentData['method']) ? $paymentData['method'] : null,
			'response'  => isset($paymentData['response']) ? $paymentData['response'] : null,
			'capture'   => isset($paymentData['capture']) ? $paymentData['capture'] : null,
			'date_bank' => isset($paymentData['date_bank']) ? $paymentData['date_bank'] : null,
			'date_paym' => isset($paymentData['date_paym']) ? $paymentData['date_paym'] : null,
			'fee'       => isset($paymentData['fee']) ? $paymentData['fee'] : null,
			'unique_id' => isset($paymentData['unique_id']) ? $paymentData['unique_id'] : null,
			'order_id'  => isset($paymentData['order_id']) ? $paymentData['order_id'] : null,
		);
		dol_syslog("stancerRefreshAllPaymentsFromDolibarr API_RETURN $paymentId " . json_encode($apiDumpFields));
		//montant null & etat brouillon -> paiement qui n'a jamais été fait -> next
		$amount = isset($paymentData['amount']) ? (int) $paymentData['amount'] : 0;
		$paymentStatus = isset($paymentData['status']) ? $paymentData['status'] : '';

		// Trace the local-vs-API divergence explicitly so a grep gives the diff
		// in a single line. STATUS_TO_CAPTURE=8, STATUS_CAPTURED=2, STATUS_REFUSED=7, etc.
		dol_syslog("stancerRefreshAllPaymentsFromDolibarr DIVERGENCE $paymentId localStatus=" . (int) $sploc->status . " apiStatus=" . $paymentStatus . " obj=" . $obj->element . "#" . $obj->id . " (" . $obj->ref . ")");

		if (empty($amount) || empty($paymentStatus) || $paymentStatus == 'to_capture') {
			continue;
		}

		$resFill = $sploc->fillDataFromApi($paymentData);
		if ($resFill < 0) {
			dol_syslog("stancerRefreshAllPaymentsFromDolibarr $paymentId fillData error, next");
			continue;
		}
		//object exist in database for sure
		if ($res) {
			// print "<p>update " . $sploc->fk_soc . "</p>";
			$res2 = $sploc->update($user);
			if ($res2 < 0) {
				dol_syslog("stancerRefreshAllPaymentsFromDolibarr $paymentId local status update failed: " . $sploc->error, LOG_ERR);
			}
		}

		// Should check on the bank account that every entry is there, code probably to share with the cron job
		//erics add new line on bank account for Stancer Fees
		$method = isset($paymentData['method']) ? $paymentData['method'] : '';
		$paymentType = "";
		if ($method == "card") {
			$paymentType = "CB";
		} elseif ($method == "sepa") {
			$paymentType = "PRE";
		}
		$paymentTypeId = dol_getIdFromCode($db, $paymentType, 'c_paiement', 'code', 'id', 1);
		if (empty($paymentTypeId)) {
			dol_syslog("stancerRefreshAllPaymentsFromDolibarr $paymentId error: there is no type id for $paymentType on that dolibarr maybe user has customized its dictionnary !", LOG_ERR);
			$output->message .=  "ERROR: $paymentType is unknown in your dolibarr\n";
			continue;
		}
		$ipaddress = '127.0.0.1';
		$service = 'stancer';
		$paymentmethod = 'stancer';
		// Data already fetched via API
		$json = $paymentData;
		$datebank = null;
		if (isset($paymentData['date_bank']) && $paymentData['date_bank'] !== '') {
			$datebank = is_numeric($paymentData['date_bank']) ? new DateTime('@' . $paymentData['date_bank']) : new DateTime($paymentData['date_bank']);
		}

		// May be something else than invoices (a membership for instance)
		$label = stancerChangeBankLabel($obj);
		if (empty($datebank) && !empty($json['date_paym'])) {
			$datebank = is_numeric($json['date_paym']) ? new DateTime('@' . $json['date_paym']) : new DateTime($json['date_paym']);
		}
		//exclure les refused etc. -> isDefinitivePaid
		//UPDATE llx_stancer_stancer_payments SET stancer_id='paym_WtVIw1BeAIpxgpqSyvQn3Qvz', amount=1000, fee=14, currency='eur', description='Paiement de la commande ou facture FA2304-1134', order_id='FA2304-1134', unique_id='INV=1408.CUS=', method='sepa', card='', sepa='sepa\
		//_ge4hKWzKtYiaDdb8ATkpwkX7', customer='cust_WC1Wx88UdOv2NrY9hdNCnZ3l', refunds='[]', response='MS03', capture=true, created='2023-04-03 11:11:01', live_mode=true, fk_soc=272, date_creation='2023-04-03 11:11:01', tms='2023-05-03 08:05:01', fk_user_creat=1, fk_user_modif=1, status=4 WHERE rowid=1150
		$listOfPaidStatus = [
			'captured',
			'capture_sent'
		];

		// Single grep-friendly line that explains which branch we are about to take.
		// If a payment Stancer says is "successful" never reaches stancerAddPaymentOnObject,
		// this log shows why (typically: datebank null because the API returned no
		// date_bank / date_paym for that payment yet, or paymentStatus is something
		// like 'to_capture' / 'authorize' that does not belong to $listOfPaidStatus).
		$datebankIso = $datebank instanceof DateTime ? $datebank->format('c') : 'null';
		$willAddPayment = ($datebank && ($amount > 0) && in_array($paymentStatus, $listOfPaidStatus)) ? 'YES' : 'NO';
		dol_syslog("stancerRefreshAllPaymentsFromDolibarr DECISION $paymentId willAddPayment=$willAddPayment apiStatus=$paymentStatus amount=$amount datebank=$datebankIso method=$method paymentType=$paymentType");

		if ($datebank && ($amount > 0) && in_array($paymentStatus, $listOfPaidStatus)) {
			//warning, payment only on validated object !
			//status = 0 -- draft
			if ($obj->status == 0 && $obj->validate($user) < 0) {
				dol_syslog("stancerRefreshAllPaymentsFromDolibarr $paymentId validate failed on $objRef: " . $obj->error, LOG_ERR);
			}

			$date = (string) $datebank->format("Y-m-d");
			$apiCustomerId = null;
			if (isset($paymentData['customer'])) {
				$apiCustomerId = is_array($paymentData['customer'])
					? (isset($paymentData['customer']['id']) ? $paymentData['customer']['id'] : null)
					: $paymentData['customer'];
			}
			$data = [
				'payment_id' => $paymentId,
				'date' => $date,
				'FinalPaymentAmt' => ($amount / 100),
				'paymentType' => $paymentType,
				'paymentTypeId' => $paymentTypeId,
				'ipaddress' => $ipaddress,
				'TRANSACTIONID' => $paymentId,
				'service' => $service,
				'paymentmethod' => $paymentmethod,
				'label' => $label,
				'FinalFees' => isset($json['fee']) ? $json['fee'] : 0, // cents (divided once at consumption)
				'ref' => $obj->ref,
				// Propagated for stancerAddPaymentOnObject misattribution guards.
				'api_order_id' => isset($paymentData['order_id']) ? $paymentData['order_id'] : null,
				'api_customer_id' => $apiCustomerId,
			];

			dol_syslog("stancerRefreshAllPaymentsFromDolibarr $paymentId add payment on object");
			$errorMessage = "";

			$error = stancerAddPaymentOnObject($obj, $data, $errorMessage);
			$amountEur = number_format($amount / 100, 2);
			$urlObj = stancerObjectUrlForMail($obj);
			$message = $paymentId . ";" . $urlObj . ";" . $amountEur . "€;" . $errorMessage . ';';
			$output->data[$obj->ref] = [
				'amount' => $amount,
				'errorMessage' => $errorMessage,
				'status' => null,
				'message' => null,
			];

			if ($error == 0) {
				//erics do not update payment method on invoice, like #18
				//because as we make refresh from dolibarr list all of that
				//objects are already with stancer settings

				if ($mailNotif) {
					$obj->fetch_thirdparty();
					$customerName = is_object($obj->thirdparty) ? $obj->thirdparty->name : '';
					$refUrl = stancerBuildInvoiceLink($obj);
					$objTrackid = ($obj->element == 'facture' ? 'inv' : 'ord') . $obj->id;
					stancerSendMail(getDolGlobalString('STANCER_AUTO_MAIL_NOTIFICATIONS_PAYMENT', ''), $langs->transnoentitiesnoconv('StancerMailSubjectPaymentConfirm', (string) $obj->ref, price($amount / 100)), $langs->transnoentitiesnoconv('StancerMailPaymentConfirm', price($amount / 100), $refUrl, $customerName), false, '', $objTrackid);
				}
				$message .= "SUCCESS;";
				$output->message .=  $message . "\n";
				$output->data[$obj->ref]['status'] = $message;
			} else {
				// error -1 c'est duplicate, dans ce cas ce n'est pas une erreur
				if ($error == -1) {
					dol_syslog("stancerRefreshAllPaymentsFromDolibarr $paymentId special case, error=-1, it means try to add a duplicate payment, not an error is that case");
					$error = '';
					$message = '';
				} else {
					if ($mailNotif) {
						$obj->fetch_thirdparty();
						$customerName = is_object($obj->thirdparty) ? $obj->thirdparty->name : '';
						$refUrl = stancerBuildInvoiceLink($obj);
						$objTrackid = ($obj->element == 'facture' ? 'inv' : 'ord') . $obj->id;
						stancerSendMail(getDolGlobalString('STANCER_AUTO_MAIL_NOTIFICATIONS_PAYMENT', ''), $langs->transnoentitiesnoconv('StancerMailSubjectPaymentConfirmButError', (string) $obj->ref, price($amount / 100)), $langs->transnoentitiesnoconv('StancerMailPaymentConfirmButError', price($amount / 100), $refUrl, $customerName), false, '', $objTrackid);
					}
					$output->data[$obj->ref]['status'] = 'error';
					$message .= "ERROR;";
					$output->message .=  $message . "\n";
				}
			}
			dol_syslog("stancerRefreshAllPaymentsFromDolibarr $paymentId : " . $message);
		} else {
			// Stop nagging when the merchant has manually switched the linked invoice/order
			// away from Stancer (eg. customer asks for a bank transfer after a CB failure):
			// payment mode no longer CB/PRE OR bank account no longer the Stancer one. Mark
			// the local Stancer_payments as HIDDEN so future runs skip it entirely.
			if (!stancerIsObjectStillEligibleForStancer($obj)) {
				dol_syslog("stancerRefreshAllPaymentsFromDolibarr $paymentId linked " . $obj->element . " " . (isset($obj->ref) ? $obj->ref : '?') . " no longer eligible for Stancer, set local status to HIDDEN");
				$sploc->status = Stancer_payments::STATUS_HIDDEN;
				if ($sploc->update($user) < 0) {
					dol_syslog("stancerRefreshAllPaymentsFromDolibarr $paymentId failed to set local status HIDDEN: " . $sploc->error, LOG_ERR);
				}
				continue;
			}
			// Admin notification: only send once per payment status per object (invoice/order/propal/...)
			// to avoid daily duplicates from the scheduled refresh.
			if ($mailNotif) {
				$adminActionCode = 'ADMIN_PAYERROR_' . strtoupper($paymentStatus);
				$actioncommCheck = new ActionComm($db);
				$existingAdminNotif = $actioncommCheck->getActions($obj->socid, $obj->id, $obj->element, " AND code='AC_" . $db->escape($adminActionCode) . "'");
				if (empty($existingAdminNotif)) {
					$obj->fetch_thirdparty();
					$customerName = is_object($obj->thirdparty) ? $obj->thirdparty->name : '';
					$refUrl = stancerBuildInvoiceLink($obj);
					$statusUrl = stancerBuildManagerLink($paymentId, $paymentStatus);
					$objTrackid = ($obj->element == 'facture' ? 'inv' : 'ord') . $obj->id;
					stancerSendMail(getDolGlobalString('STANCER_AUTO_MAIL_NOTIFICATIONS_PAYMENT', ''), $langs->transnoentitiesnoconv('StancerMailSubjectPaymentError', (string) $obj->ref, price($amount / 100)), $langs->transnoentitiesnoconv('StancerMailPaymentError', price($amount / 100), $refUrl, $customerName, $statusUrl), false, '', $objTrackid);
					stancerAddActionComm($obj, $adminActionCode, $langs->transnoentitiesnoconv('StancerMailSubjectPaymentError', (string) $obj->ref, price($amount / 100)), $langs->transnoentitiesnoconv('StancerMailPaymentError', price($amount / 100), (string) $obj->ref, $customerName, $paymentStatus), array(), '');
				} else {
					dol_syslog("stancerRefreshAllPaymentsFromDolibarr $paymentId admin notification already sent for status=$paymentStatus on " . $obj->element . " " . $obj->ref . ", skip", LOG_DEBUG);
				}
			}
			// Send customer notification for payment failures detected a posteriori (dispute, refused, expired, failed)
			$failureStatuses = ['disputed', 'refused', 'expired', 'failed'];
			if (in_array($paymentStatus, $failureStatuses) && $obj->element == 'facture') {
				// Reopen the invoice if it had been marked paid (idempotent: noop if already unpaid)
				$reopenActionCode = 'BILL_REOPEN_FAILED_' . strtoupper($paymentStatus);
				$actioncommReopenCheck = new ActionComm($db);
				$existingReopen = $actioncommReopenCheck->getActions($obj->socid, $obj->id, "invoice", " AND code='AC_" . $db->escape($reopenActionCode) . "'");
				if (empty($existingReopen)) {
					$reopenRes = stancerReopenInvoiceFromPayment($paymentId, $langs->transnoentitiesnoconv('StancerPaymentFailedReopenReason', $paymentStatus, $paymentId));
					if (is_object($reopenRes)) {
						dol_syslog("stancerRefreshAllPaymentsFromDolibarr $paymentId invoice " . $obj->ref . " reopened (status=$paymentStatus)", LOG_INFO);
						stancerAddActionComm($obj, $reopenActionCode, $langs->transnoentitiesnoconv('StancerPaymentFailedReopenTitle', (string) $obj->ref), $langs->transnoentitiesnoconv('StancerPaymentFailedReopenReason', $paymentStatus, $paymentId), array(), '');
					} elseif ($reopenRes === 0) {
						dol_syslog("stancerRefreshAllPaymentsFromDolibarr $paymentId no reopen needed for invoice " . $obj->ref . " (not paid or no link found)", LOG_DEBUG);
					} else {
						dol_syslog("stancerRefreshAllPaymentsFromDolibarr $paymentId reopen failed for invoice " . $obj->ref . " (status=$paymentStatus)", LOG_ERR);
					}
				} else {
					dol_syslog("stancerRefreshAllPaymentsFromDolibarr $paymentId reopen already done for invoice " . $obj->ref . " (status=$paymentStatus), skip", LOG_DEBUG);
				}

				if ($sendNotifications && getDolGlobalString('STANCER_AUTO_MAIL_INVOICES_ERROR')) {
					$actionCodeForStatus = 'BILL_' . strtoupper($paymentStatus) . '_SENTBYMAIL';
					dol_syslog("stancerRefreshAllPaymentsFromDolibarr $paymentId sending failure notification to customer, actionCode=$actionCodeForStatus", LOG_DEBUG);
					stancerSendInvoiceMailModele(
						getDolGlobalString('STANCER_AUTO_MAIL_INVOICES_ERROR', ''),
						$obj,
						$actionCodeForStatus,
						0  // forceMail=0: one notification per status type per invoice
					);
				} elseif (!$sendNotifications) {
					dol_syslog("stancerRefreshAllPaymentsFromDolibarr $paymentId notifications disabled by caller, skip customer notification (status=$paymentStatus)", LOG_DEBUG);
				} else {
					dol_syslog("stancerRefreshAllPaymentsFromDolibarr $paymentId STANCER_AUTO_MAIL_INVOICES_ERROR not configured, skip customer notification (status=$paymentStatus)", LOG_DEBUG);
				}

				// SEPA-specific: send rejection email with human-readable reason + auto-create fee invoice
				$sepaResponseCode = isset($paymentData['response']) ? $paymentData['response'] : '';
				if ($method == 'sepa' && !empty($sepaResponseCode)) {
					$actionCodeSepaReject = 'BILL_SEPAREJECT_' . $sepaResponseCode . '_SENTBYMAIL';

					// Deduplication check via ActionComm
					$actioncommCheck = new ActionComm($db);
					$existingSepaNotif = $actioncommCheck->getActions($obj->socid, $obj->id, "invoice", " AND code='AC_" . $db->escape($actionCodeSepaReject) . "'");

					if (empty($existingSepaNotif)) {
						// Send SEPA rejection email via template (with PDF attachment + ActionComm on invoice)
						if ($sendNotifications && getDolGlobalString('STANCER_AUTO_MAIL_INVOICES_ERROR', '') != '') {
							$ccEmail = getDolGlobalString('STANCER_EMAIL_INFO_SEPA', '');
							$mailRes = stancerSendInvoiceMailModele(
								getDolGlobalString('STANCER_AUTO_MAIL_INVOICES_ERROR', ''),
								$obj,
								$actionCodeSepaReject,
								0,
								true,
								$ccEmail
							);
							if ($mailRes > 0) {
								dol_syslog("stancerRefreshAllPaymentsFromDolibarr $paymentId SEPA rejection template email sent for invoice " . $obj->ref . " (code $sepaResponseCode)", LOG_INFO);
							} elseif ($mailRes === null || $mailRes === 0) {
								// F7: null/0 is a skip (dedup or empty from/to recipient), not a failure.
								dol_syslog("stancerRefreshAllPaymentsFromDolibarr $paymentId SEPA rejection template email skipped for invoice " . $obj->ref, LOG_DEBUG);
							} else {
								dol_syslog("stancerRefreshAllPaymentsFromDolibarr $paymentId SEPA rejection template email failed for invoice " . $obj->ref . ": result=$mailRes", LOG_ERR);
							}
						} elseif (!$sendNotifications) {
							dol_syslog("stancerRefreshAllPaymentsFromDolibarr $paymentId notifications disabled by caller, skip SEPA rejection customer email", LOG_DEBUG);
						} else {
							dol_syslog("stancerRefreshAllPaymentsFromDolibarr $paymentId STANCER_AUTO_MAIL_INVOICES_ERROR not configured, skip SEPA rejection customer email", LOG_DEBUG);
						}

						// Auto-create rejection fee invoice if enabled
						if (getDolGlobalString('STANCER_SEPA_REJECTION_FEE_AUTO_APPLY')) {
							$feeResult = stancerCreateRejectionFeeInvoice($obj->socid, $sepaResponseCode, (string) $obj->ref);
							if (is_object($feeResult)) {
								dol_syslog("stancerRefreshAllPaymentsFromDolibarr $paymentId rejection fee invoice created: " . $feeResult->ref, LOG_INFO);
								stancerAddActionComm($obj, 'BILL_SEPAFEE_CREATED', $langs->transnoentitiesnoconv('StancerSepaFeeInvoiceCreated', $feeResult->ref), '', array(), '');
							} else {
								dol_syslog("stancerRefreshAllPaymentsFromDolibarr $paymentId rejection fee invoice error: " . $feeResult, LOG_ERR);
							}
						}
					} else {
						dol_syslog("stancerRefreshAllPaymentsFromDolibarr $paymentId SEPA rejection notification already sent for code $sepaResponseCode, skip", LOG_DEBUG);
					}
				}
			}
			dol_syslog("stancerRefreshAllPaymentsFromDolibarr $paymentId do not insert paiement : status=" . $paymentStatus . ", amount=$amount, date=" . json_encode($datebank), LOG_WARNING);

			// Deduplication: only report once per payment+status in summary email
			// llx_actioncomm.code is varchar(50). stancerAddActionComm prefixes with "AC_" (3 chars),
			// so the custom code must fit in 47 chars. paym_id is 28 chars on its own which would
			// blow the limit (Data too long for column 'code' -> INSERT silently fails -> dedup
			// stops working). We hash the paym_id to 8 hex chars; collision risk over a single
			// invoice's lifetime is negligible.
			$cronSummaryCode = 'CRON_PAY_REP_' . substr(md5($paymentId), 0, 8) . '_' . strtoupper($paymentStatus);
			$actioncommCheckSummary = new ActionComm($db);
			$existingSummary = $actioncommCheckSummary->getActions($obj->socid, $obj->id, $obj->element, " AND code='AC_" . $db->escape($cronSummaryCode) . "'");

			if (empty($existingSummary)) {
				$urlPayment = "<a href='https://manage.stancer.com/fr/details-de-paiement?id=" . $paymentId . "'>" . $paymentId . "</a>";
				$amountEur = price($amount / 100);
				$urlObj = stancerObjectUrlForMail($obj);

				dol_syslog("stancerRefreshAllPaymentsFromDolibarr output error (1)", LOG_WARNING);
				// $datebank can legitimately be null when the Stancer API returns no date_bank / date_paym
				// (typical for failed / refused payments). Use a placeholder rather than crashing.
				$datebankStr = ($datebank instanceof DateTime) ? $datebank->format('Y-m-d H:i:s') : '';
				$output->error .= $urlPayment . ";" . $urlObj . ";" . $amountEur . "€;" . $paymentStatus . ";" . $datebankStr . ";\n";
				$output->data[$obj->ref] = [
					'amount' => $amount,
					'errorMessage' => "do not insert paiement",
					'status' => $paymentStatus,
					'message' => null,
				];

				stancerAddActionComm($obj, $cronSummaryCode, $langs->transnoentitiesnoconv('StancerCronSummaryReported', (string) $obj->ref, $paymentStatus, price($amount / 100)), $langs->transnoentitiesnoconv('StancerCronSummaryReportedDesc', $paymentId, $paymentStatus, price($amount / 100)), array(), '');
			} else {
				dol_syslog("stancerRefreshAllPaymentsFromDolibarr $paymentId status=$paymentStatus already reported in summary email, skip", LOG_DEBUG);
			}
		}
	}
	// }
	// exit;
	return $output;
}

/**
 * Re-audit recently captured payments to catch late status changes.
 *
 * Stancer has no webhook/IPN: a payment posted optimistically as "captured"
 * (status = 2) can later be refused, disputed or refunded by the bank AFTER the
 * normal polling window. Neither refresh path catches this:
 *   - stancerRefreshAllPaymentsFromDolibarr() explicitly excludes CAPTURED rows
 *     (to avoid daily "payment error" spam),
 *   - stancerRefreshAllPayments() is windowed by date_creation, so an older
 *     payment that flips to refused afterwards is out of range.
 *
 * This function selects the locally-CAPTURED rows whose date_creation falls in a
 * bounded sliding window (STANCER_AUDIT_CAPTURED_WINDOW_DAYS, default 30, 0 to
 * disable) and feeds their rowids to stancerRefreshAllPaymentsFromDolibarr() in
 * audit mode ($selectedIds). That existing path already re-checks each payment
 * against the API, reconciles the local status (e.g. captured -> refused = 7),
 * reopens the linked invoice and sends the (deduplicated) notifications, so no
 * reconciliation logic is duplicated here. The window bounds the API call volume.
 *
 * @param   int|null  $lastrun           ignored (kept for cron call symmetry)
 * @param   bool      $sendNotifications forward to the audit path
 *
 * @return  StdClass  output (error/message/data), same shape as the other refresh functions
 */
function stancerAuditRecentCapturedPayments($lastrun = null, $sendNotifications = true)
{
	global $db;

	$output = new StdClass();
	$output->error = '';
	$output->message = '';
	$output->data = array();

	$windowDays = stancerGetAuditCapturedWindowDays();
	if ($windowDays <= 0) {
		dol_syslog("stancerAuditRecentCapturedPayments disabled (STANCER_AUDIT_CAPTURED_WINDOW_DAYS=0), skip");
		return $output;
	}

	$sinceDate = dol_print_date((time() - (3600 * 24 * $windowDays)), '%Y-%m-%d');
	dol_syslog("stancerAuditRecentCapturedPayments windowDays=$windowDays sinceDate=$sinceDate");

	$sp = new Stancer_payments($db);
	$customsql = "live_mode = '" . getDolGlobalString('STANCER_IS_PROD') . "' AND status = '" . Stancer_payments::STATUS_CAPTURED . "' AND date_creation > '" . $db->escape($sinceDate) . "'";
	$resList = $sp->fetchAll('ASC', '', 0, 0, array('customsql' => $customsql));
	if (!is_array($resList)) {
		dol_syslog("stancerAuditRecentCapturedPayments fetchAll error: " . $sp->error, LOG_ERR);
		$output->error = $sp->error;
		return $output;
	}

	$ids = array();
	foreach ($resList as $row) {
		if (!empty($row->id)) {
			$ids[] = (int) $row->id;
		}
	}
	if (empty($ids)) {
		dol_syslog("stancerAuditRecentCapturedPayments no captured payment in window, nothing to do");
		return $output;
	}

	dol_syslog("stancerAuditRecentCapturedPayments auditing " . count($ids) . " captured payment(s)");
	// Reuse the existing audit path (forceAudit): it bypasses both the status
	// filter and the "already paid" short-circuit, re-checks each payment against
	// the API and reconciles the local status / reopens invoices / notifies.
	return stancerRefreshAllPaymentsFromDolibarr(false, null, $sendNotifications, $ids);
}

/**
 * re télécharge tous les paiements et fais le job
 * à partir de la liste communiquée par stancer
 * (mutualisation entre payments_list et cron)
 *
 * @param   bool      $userMessage        show messages to user
 * @param   int|null  $lastrun            timestamp of last run (cron mode), null for a full window
 * @param   bool      $sendNotifications  if false, all email notifications are skipped
 * @return  stdClass                      Report object, ->error is set on failure, ->message holds the CSV report
 */
function stancerRefreshAllPayments($userMessage = true, $lastrun = null, $sendNotifications = true)
{
	dol_syslog("stancerRefreshAllPayments, lastrun=$lastrun, sendNotifications=" . ($sendNotifications ? 'true' : 'false'));
	global $langs, $db, $user, $conf;
	$obj = null;
	$stancerApi = new StancerApi();

	$mailNotif = false;
	if ($sendNotifications && getDolGlobalString('STANCER_AUTO_MAIL_NOTIFICATIONS_PAYMENT', '') != '' && getDolGlobalString('STANCER_AUTO_MAIL_NOTIFICATIONS_PAYMENT_DETAILS', '') != '') {
		$mailNotif = true;
	}

	$output = new StdClass();
	$output->error = '';
	$output->message = '';
	$output->data = array();

	$sp = new Stancer_payments($db);
	//Refresh sur un historique de ... 1 mois, 60 jours ... autre ?
	$nbjour = stancerGetNumberOfDaysToGet();
	$dateFiltre = time() - (3600 * 24 * $nbjour);

	//Si c'est la tache planifiée on fait la diff par rapport à la date de dernier lancement
	if (!empty($lastrun)) {
		$dateFiltre = $lastrun;
	}

	$limit = stancerGetNumberOfItemToGet();
	$start = 0;
	$maxPages = 50; // Safety guard: max 50 pages (5000 items with limit=100)
	$page = 0;

	dol_syslog("stancerRefreshAllPayments dateFiltre=$dateFiltre, limit=$limit");

	do {
		$page++;
		dol_syslog("stancerRefreshAllPayments page=$page, start=$start, limit=$limit");

		// Use new API client
		$listResult = $stancerApi->listPayments(['created' => $dateFiltre, 'start' => $start, 'limit' => $limit]);

		if ($listResult === false) {
			dol_syslog("stancerRefreshAllPayments API error on page $page: " . $stancerApi->error, LOG_ERR);
			$output->error = $stancerApi->error;
			if ($stancerApi->lastHttpCode == 401) {
				setEventMessages($langs->trans('StancerApiAuthError', $stancerApi->error), null, 'errors');
			}
			return $output;
		}

		$payments = isset($listResult['payments']) ? $listResult['payments'] : (is_array($listResult) ? $listResult : []);
		$nbResults = count($payments);
		dol_syslog("stancerRefreshAllPayments page=$page got $nbResults results");

		foreach ($payments as $paymentData) {
			// Get payment details (may need to fetch full data)
			$paymentId = isset($paymentData['id']) ? $paymentData['id'] : '';
			if (empty($paymentId)) {
				continue;
			}

			// Fetch full payment data from API
			$payment = $stancerApi->getPayment($paymentId);
			if ($payment === false) {
				dol_syslog("stancerRefreshAllPayments cannot fetch payment $paymentId: " . $stancerApi->error);
				if ($stancerApi->lastHttpCode == 401) {
					$output->error = $stancerApi->error;
					setEventMessages($langs->trans('StancerApiAuthError', $stancerApi->error), null, 'errors');
					return $output;
				}
				continue;
			}

			$amount = isset($payment['amount']) ? (int) $payment['amount'] : 0;
			$paymentStatus = isset($payment['status']) ? $payment['status'] : '';
			if (empty($amount) || empty($paymentStatus) || $paymentStatus == 'to_capture') {
				dol_syslog("stancerRefreshAllPayments next loop due to amount=$amount, paymentStatus=$paymentStatus");
				continue;
			}

			$res = $sp->fetch(0, null, $paymentId);

			// Skip payments the cron previously flagged as ineligible (linked invoice/order
			// switched away from Stancer payment mode or bank account). The local row is in
			// STATUS_HIDDEN: do not nag with another error email.
			if ($res > 0 && (int) $sp->status === Stancer_payments::STATUS_HIDDEN) {
				dol_syslog("stancerRefreshAllPayments $paymentId local status is HIDDEN, skip");
				continue;
			}

			// Grouped SEPA payments are handled exclusively by stancerRefreshAllPaymentsFromDolibarr,
			// which dispatches the amount across every grouped invoice. Processing them here would
			// mis-resolve to a single invoice via order_id and prematurely mark the row CAPTURED,
			// which would then exclude it from FromDolibarr's filter and leave invoices unpaid.
			if ($res > 0 && !empty($sp->grouped_invoice_ids)) {
				dol_syslog("stancerRefreshAllPayments $paymentId is grouped (invoice_ids=" . $sp->grouped_invoice_ids . "), defer to FromDolibarr, skip");
				continue;
			}

			// Get object from unique_id, fallback to order_id
			$uniqueId = isset($payment['unique_id']) ? $payment['unique_id'] : '';
			$obj = getObjectFromTag($uniqueId);
			dol_syslog("stancerRefreshAllPayments $paymentId getObjectFromTag object=" . json_encode($obj));
			if (empty($obj)) {
				$obj = getObjectFromOrderID($sp->order_id);
				if (empty($obj)) {
					dol_syslog("stancerRefreshAllPayments $paymentId nothing found from unique_id=$uniqueId nor order_id=" . $sp->order_id . ", skip");
					continue;
				}
				dol_syslog("stancerRefreshAllPayments $paymentId resolved via order_id, element=" . $obj->element . " id=" . $obj->id);
			}

			$objRef = $obj->ref;
			// Invoice already fully paid -> short circuit
			if ($obj->element == 'facture') {
				$paid = $obj->getSommePaiement() ?? 0;
				// price2num() returns a numeric string that PHP coerces in this comparison; the
				// literal form is pinned by the M7 guard test (StancerAmountComparisonTest).
				// @phan-suppress-next-line PhanTypeInvalidLeftOperandOfNumericOp
				if (price2num($paid, 'MT') >= price2num($obj->total_ttc, 'MT') - 0.01) {
					dol_syslog("stancerRefreshAllPayments $paymentId that invoice was paid, change status and do short circuit, next, ref=$objRef");

					$sp->status = Stancer_payments::STATUS_CAPTURED;
					if ($sp->update($user) < 0) {
						dol_syslog("stancerRefreshAllPayments $paymentId local status update failed: " . $sp->error, LOG_ERR);
					}

					if ($obj->setPaid($user) < 0) {
						dol_syslog("stancerRefreshAllPayments $paymentId setPaid failed on invoice $objRef: " . $obj->error, LOG_ERR);
					}
					continue;
				}
				// @phan-suppress-next-line PhanDeprecatedProperty  $paye is the column Dolibarr 15..21 fills and still writes; status == 2 also covers abandoned invoices
				if ($obj->paye == 1) {
					dol_syslog("stancerRefreshAllPayments $paymentId that invoice is marked as paid short circuit, next, ref=$objRef");
					continue;
				}
			}
			if ($obj->element == 'commande') {
				$obj->fetchObjectLinked($obj->id, $obj->element, null, 'facture');
				foreach ($obj->linkedObjectsIds as $objecttype => $linkedobj) {
					foreach ($linkedobj as $key => $facid) {
						dol_syslog("stancerRefreshAllPayments $paymentId fetch linked invoice " . json_encode($facid));
						$inv = new Facture($db);
						$resInv = $inv->fetch($facid);
						if ($resInv) {
							$paid = $inv->getSommePaiement() ?? 0;
							// price2num() returns a numeric string that PHP coerces in this comparison; the
							// literal form is pinned by the M7 guard test (StancerAmountComparisonTest).
							// @phan-suppress-next-line PhanTypeInvalidLeftOperandOfNumericOp
							if (price2num($paid, 'MT') >= price2num($inv->total_ttc, 'MT') - 0.01) {
								dol_syslog("stancerRefreshAllPayments $paymentId invoice linked to that order was paid, change status and do short circuit, next, ref=$objRef");

								$sp->status = Stancer_payments::STATUS_CAPTURED;
								if ($sp->update($user) < 0) {
									dol_syslog("stancerRefreshAllPayments $paymentId local status update failed: " . $sp->error, LOG_ERR);
								}

								// @phan-suppress-next-line PhanDeprecatedProperty  $paye is the column Dolibarr 15..21 fills and still writes; status == 2 also covers abandoned invoices
								if ($inv->paye == 0 && $inv->setPaid($user) < 0) {
									dol_syslog("stancerRefreshAllPayments $paymentId setPaid failed on invoice " . $inv->ref . ": " . $inv->error, LOG_ERR);
								}
								if ($obj->status != Commande::STATUS_CLOSED && $obj->cloture($user, 1) < 0) {
									dol_syslog("stancerRefreshAllPayments $paymentId cloture failed on order $objRef: " . $obj->error, LOG_ERR);
								}
								continue 3;
							}
						}
					}
				}
			}
			if ($obj->element == 'propal') {
				$obj->fetchObjectLinked($obj->id, $obj->element, null, 'facture');
				foreach ($obj->linkedObjectsIds as $objecttype => $linkedobj) {
					foreach ($linkedobj as $key => $facid) {
						dol_syslog("stancerRefreshAllPayments $paymentId fetch linked invoice (from propal) " . json_encode($facid));
						$inv = new Facture($db);
						$resInv = $inv->fetch($facid);
						if ($resInv) {
							$paid = $inv->getSommePaiement() ?? 0;
							// price2num() returns a numeric string that PHP coerces in this comparison; the
							// literal form is pinned by the M7 guard test (StancerAmountComparisonTest).
							// @phan-suppress-next-line PhanTypeInvalidLeftOperandOfNumericOp
							if (price2num($paid, 'MT') >= price2num($inv->total_ttc, 'MT') - 0.01) {
								dol_syslog("stancerRefreshAllPayments $paymentId invoice linked to that propal was paid, change status and do short circuit, next, ref=$objRef");

								$sp->status = Stancer_payments::STATUS_CAPTURED;
								if ($sp->update($user) < 0) {
									dol_syslog("stancerRefreshAllPayments $paymentId local status update failed: " . $sp->error, LOG_ERR);
								}

								// @phan-suppress-next-line PhanDeprecatedProperty  $paye is the column Dolibarr 15..21 fills and still writes; status == 2 also covers abandoned invoices
								if ($inv->paye == 0 && $inv->setPaid($user) < 0) {
									dol_syslog("stancerRefreshAllPayments $paymentId setPaid failed on invoice " . $inv->ref . ": " . $inv->error, LOG_ERR);
								}
								continue 3;
							}
						}
					}
				}
			}

			$resFill = $sp->fillDataFromApi($payment);
			if ($resFill < 0) {
				dol_syslog("stancerRefreshAllPayments $paymentId next loop due to fillDataFromApi < 0: $resFill");
				continue;
			}
			// Object exists in database?
			if ($res) {
				dol_syslog("stancerRefreshAllPayments $paymentId update sp");
				$res2 = $sp->update($user);
			} else {
				dol_syslog("stancerRefreshAllPayments $paymentId create sp");
				$res2 = $sp->create($user);
			}
			if ($res2 < 0) {
				dol_syslog("stancerRefreshAllPayments $paymentId failed to persist local payment: " . $sp->error, LOG_ERR);
			}

			// Determine payment type
			$method = isset($payment['method']) ? $payment['method'] : '';
			$paymentType = "";
			if ($method == "card") {
				$paymentType = "CB";
			} elseif ($method == "sepa") {
				$paymentType = "PRE";
			}
			$paymentTypeId = dol_getIdFromCode($db, $paymentType, 'c_paiement', 'code', 'id', 1);
			$ipaddress = '127.0.0.1';
			$service = 'stancer';
			$paymentmethod = 'stancer';

			// Get fee and date_bank from API response
			$fee = isset($payment['fee']) ? $payment['fee'] : 0;
			$dateBankTimestamp = isset($payment['date_bank']) ? $payment['date_bank'] : (isset($payment['date_paym']) ? $payment['date_paym'] : null);
			$datebank = null;
			if ($dateBankTimestamp) {
				$datebank = is_numeric($dateBankTimestamp) ? new DateTime('@' . $dateBankTimestamp) : new DateTime($dateBankTimestamp);
			}

			$label = stancerChangeBankLabel($obj);

			$listOfPaidStatus = [
				'captured',
				'capture_sent'
			];

			if ($datebank && ($amount > 0) && in_array($paymentStatus, $listOfPaidStatus)) {
				$date = (string) $datebank->format("Y-m-d");
				$apiCustomerId = null;
				if (isset($payment['customer'])) {
					$apiCustomerId = is_array($payment['customer'])
						? (isset($payment['customer']['id']) ? $payment['customer']['id'] : null)
						: $payment['customer'];
				}
				$data = [
					'payment_id' => $paymentId,
					'date' => $date,
					'FinalPaymentAmt' => ($amount / 100),
					'paymentType' => $paymentType,
					'paymentTypeId' => $paymentTypeId,
					'ipaddress' => $ipaddress,
					'TRANSACTIONID' => $paymentId,
					'service' => $service,
					'paymentmethod' => $paymentmethod,
					'label' => $label,
					'FinalFees' => $fee, // cents (divided once at consumption)
					'ref' => $obj->ref,
					// Propagated for stancerAddPaymentOnObject misattribution guards.
					'api_order_id' => isset($payment['order_id']) ? $payment['order_id'] : null,
					'api_customer_id' => $apiCustomerId,
				];

					dol_syslog("stancerRefreshAllPayments $paymentId add payment on object, paymentStatus is $paymentStatus");
					$errorMessage = "";
					$error = stancerAddPaymentOnObject($obj, $data, $errorMessage);
					$message = $obj->ref . ";" . price($amount / 100) . ";" . $errorMessage . ';';
					$output->data[$obj->ref] = [
						'amount' => $amount,
						'errorMessage' => $errorMessage,
						'status' => null,
						'message' => null,
					];
					if ($error == 0) {
						//erics update payment method on invoice, like #18
						//note we make refresh from stancer list
						dol_syslog("stancerRefreshAllPayments $paymentId update invoice with bankAccount and paymentType", LOG_DEBUG);
						$bankaccountId = getDolGlobalInt('STANCER_BANK_ACCOUNT_FOR_PAYMENTS');
						if ($obj->setPaymentMethods($paymentTypeId) < 0) {
							dol_syslog("stancerRefreshAllPayments $paymentId setPaymentMethods failed on $objRef: " . $obj->error, LOG_ERR);
						}
						if ($obj->setBankAccount($bankaccountId) < 0) {
							dol_syslog("stancerRefreshAllPayments $paymentId setBankAccount failed on $objRef: " . $obj->error, LOG_ERR);
						}
						if ($obj->update($user, 1) < 0) {
							dol_syslog("stancerRefreshAllPayments $paymentId update failed on $objRef: " . $obj->error, LOG_ERR);
						}

						if ($mailNotif) {
							$obj->fetch_thirdparty();
							$customerName = is_object($obj->thirdparty) ? $obj->thirdparty->name : '';
							$refUrl = stancerBuildInvoiceLink($obj);
							$objTrackid = ($obj->element == 'facture' ? 'inv' : 'ord') . $obj->id;
							stancerSendMail(getDolGlobalString('STANCER_AUTO_MAIL_NOTIFICATIONS_PAYMENT', ''), $langs->transnoentitiesnoconv('StancerMailSubjectPaymentConfirm', (string) $obj->ref, price($amount / 100)), $langs->transnoentitiesnoconv('StancerMailPaymentConfirm', price($amount / 100), $refUrl, $customerName), false, '', $objTrackid);
						}
						$message .= "SUCCESS;";
						$output->message .=  $message . "\n";
						$output->data[$obj->ref]['status'] = $message;
					} else {
						// error -1 c'est duplicate, dans ce cas ce n'est pas une erreur
						if ($error == -1) {
							dol_syslog("stancerRefreshAllPayments $paymentId special case, error=-1, it means try to add a duplicate payment, not an error is that case");
							$error = '';
							$message = '';
						} else {
							if ($mailNotif) {
								$obj->fetch_thirdparty();
								$customerName = is_object($obj->thirdparty) ? $obj->thirdparty->name : '';
								$refUrl = stancerBuildInvoiceLink($obj);
								$objTrackid = ($obj->element == 'facture' ? 'inv' : 'ord') . $obj->id;
								stancerSendMail(getDolGlobalString('STANCER_AUTO_MAIL_NOTIFICATIONS_PAYMENT', ''), $langs->transnoentitiesnoconv('StancerMailSubjectPaymentConfirmButError', (string) $obj->ref, price($amount / 100)), $langs->transnoentitiesnoconv('StancerMailPaymentConfirmButError', price($amount / 100), $refUrl, $customerName), false, '', $objTrackid);
							}
							$output->data[$obj->ref]['status'] = 'error';
							$message .= "ERROR;";
							$output->message .=  $message . "\n";
						}
					}
					dol_syslog("stancerRefreshAllPayments $paymentId : " . $message);
			} else {
				// Stop nagging when the merchant has manually switched the linked invoice/order
				// away from Stancer (eg. customer asks for a bank transfer after a CB failure):
				// payment mode no longer CB/PRE OR bank account no longer the Stancer one. Mark
				// the local Stancer_payments as HIDDEN so future runs skip it entirely.
				if (!stancerIsObjectStillEligibleForStancer($obj)) {
					dol_syslog("stancerRefreshAllPayments $paymentId linked " . $obj->element . " " . (isset($obj->ref) ? $obj->ref : '?') . " no longer eligible for Stancer, set local status to HIDDEN");
					$sp->status = Stancer_payments::STATUS_HIDDEN;
					if ($sp->update($user) < 0) {
						dol_syslog("stancerRefreshAllPayments $paymentId failed to set local status HIDDEN: " . $sp->error, LOG_ERR);
					}
					continue;
				}
				// Deduplication: only report once per payment+status in summary email
				// llx_actioncomm.code is varchar(50). stancerAddActionComm prefixes with "AC_" (3 chars),
				// so the custom code must fit in 47 chars. paym_id is 28 chars on its own which would
				// blow the limit (Data too long for column 'code' -> INSERT silently fails -> dedup
				// stops working). We hash the paym_id to 8 hex chars; collision risk over a single
				// invoice's lifetime is negligible.
				$cronSummaryCode = 'CRON_PAY_REP_' . substr(md5($paymentId), 0, 8) . '_' . strtoupper($paymentStatus);
				$actioncommCheck = new ActionComm($db);
				$existingSummary = $actioncommCheck->getActions($obj->socid, $obj->id, $obj->element, " AND code='AC_" . $db->escape($cronSummaryCode) . "'");

				if (empty($existingSummary)) {
					if ($mailNotif) {
						$obj->fetch_thirdparty();
						$customerName = is_object($obj->thirdparty) ? $obj->thirdparty->name : '';
						$refUrl = stancerBuildInvoiceLink($obj);
						$statusUrl = stancerBuildManagerLink($paymentId, $paymentStatus);
						$objTrackid = ($obj->element == 'facture' ? 'inv' : 'ord') . $obj->id;
						stancerSendMail(getDolGlobalString('STANCER_AUTO_MAIL_NOTIFICATIONS_PAYMENT', ''), $langs->transnoentitiesnoconv('StancerMailSubjectPaymentError', (string) $obj->ref, price($amount / 100)), $langs->transnoentitiesnoconv('StancerMailPaymentError', price($amount / 100), $refUrl, $customerName, $statusUrl), false, '', $objTrackid);
					}
					dol_syslog("stancerRefreshAllPayments do not insert paiement : status=" . $paymentStatus . ", amount=$amount, date=" . json_encode($datebank), LOG_WARNING);
					$urlPayment = "<a href='https://manage.stancer.com/fr/details-de-paiement?id=" . $paymentId . "'>" . $paymentId . "</a>";
					$amountEur = price($amount / 100);
					$urlObj = stancerObjectUrlForMail($obj);
					$output->error .= $urlPayment . ";" . $urlObj . ";" . $amountEur . ";" . $paymentStatus . ";" . ($datebank ? $datebank->format('Y-m-d H:i:s') : '') . ";\n";

					stancerAddActionComm($obj, $cronSummaryCode, $langs->transnoentitiesnoconv('StancerCronSummaryReported', (string) $obj->ref, $paymentStatus, price($amount / 100)), $langs->transnoentitiesnoconv('StancerCronSummaryReportedDesc', $paymentId, $paymentStatus, price($amount / 100)), array(), '');
				} else {
					dol_syslog("stancerRefreshAllPayments $paymentId status=$paymentStatus already reported in summary email, skip", LOG_DEBUG);
				}
			}
		}

		$start += $limit;
	} while ($nbResults >= $limit && $page < $maxPages);

	if ($page >= $maxPages) {
		dol_syslog("stancerRefreshAllPayments reached max pages ($maxPages), some payments may not have been synced", LOG_WARNING);
	}

	dol_syslog("stancerRefreshAllPayments done in $page pages");

	return $output;
}

/**
 * run refresh with stancer as reference then refresh with dolibarr as reference
 *
 * @param   bool      $userMessage  show messages to user
 * @param   int|null  $lastrun      timestamp of last run (cron mode), null for a full window
 * @return  stdClass                Report object, ->error is set on failure, ->message holds the CSV report
 */
function stancerRefreshAllPayouts($userMessage = true, $lastrun = null)
{
	$output = new StdClass();
	$output->error = '';
	$output->message = '';

	$rets = stancerRefreshAllPayoutsFromStancer($userMessage, $lastrun);
	if (!empty($rets->error)) {
		$output->error = $rets->error;
		$output->message = isset($rets->message) ? $rets->message : '';
		return $output;
	}

	$retd = stancerRefreshAllPayoutsFromDolibarr($userMessage, $lastrun);

	$errmessage = $rets->error . $retd->error;
	if (strlen($errmessage) > 0) {
		$output->error = $rets->error . $retd->error;
	}
	$message = (isset($rets->message) ? $rets->message : '') . (isset($retd->message) ? $retd->message : '');
	if (strlen($message) > 0) {
		$output->message = $message;
	}
	return $output;
}

/**
 * re télécharge tous les virements et fais le job
 * (mutualisation entre payouts_list et cron)
 *
 * @param   bool      $userMessage  show messages to user
 * @param   int|null  $lastrun      timestamp of last run (cron mode), null for a full window
 * @return  stdClass                Report object, ->error is set on failure, ->message holds the CSV report
 */
function stancerRefreshAllPayoutsFromStancer($userMessage = true, $lastrun = null)
{
	global $langs, $db, $user, $conf;
	dol_syslog("stancerRefreshAllPayoutsFromStancer");
	$stancerApi = new StancerApi();

	$error = 0;

	$mailNotif = false;
	if (getDolGlobalString('STANCER_AUTO_MAIL_NOTIFICATIONS_PAYOUT', '') != '' && getDolGlobalString('STANCER_AUTO_MAIL_NOTIFICATIONS_PAYOUT_DETAILS', '') != '') {
		$mailNotif = true;
	}

	$output = new StdClass();
	$output->error = '';
	$output->message = '';

	$accountStancer = new Account($db);
	$accountMainBank = new Account($db);

	$result = $accountStancer->fetch(getDolGlobalInt('STANCER_BANK_ACCOUNT_FOR_PAYMENTS'));
	if ($result < 0) {
		$error++;
		$output->error = "error STANCER_BANK_ACCOUNT_FOR_PAYMENTS is not defined";
		return $output;
	}

	$result = $accountMainBank->fetch(getDolGlobalInt('STANCER_BANK_MAIN_ACCOUNT_FOR_PAYOUTS'));
	if ($result < 0) {
		$error++;
		$output->error = "error STANCER_BANK_MAIN_ACCOUNT_FOR_PAYOUTS is not defined";
		return $output;
	}

	//Refresh sur un historique de ... 1 mois, 60 jours ... autre ?
	$nbjour = stancerGetNumberOfDaysToGet();
	$dateFiltre = time() - (3600 * 24 * $nbjour);

	//Si c'est la tache planifiée on fait la diff par rapport à la date de dernier lancement
	if (!empty($lastrun)) {
		$dateFiltre = $lastrun;
	}

	$payoutID = null;
	$sp = new Stancer_payouts($db);

	$limit = stancerGetNumberOfItemToGet();
	$start = 0;
	$maxPages = 50; // Safety guard: max 50 pages (5000 items with limit=100)
	$page = 0;
	$counter = 0;

	do {
		$page++;
		dol_syslog("stancerRefreshAllPayoutsFromStancer page=$page created=$dateFiltre, start=$start, limit=$limit");
		$listResult = $stancerApi->listPayouts(['created' => $dateFiltre, 'start' => $start, 'limit' => $limit]);

		if ($listResult === false) {
			dol_syslog("stancerRefreshAllPayoutsFromStancer API error on page $page: " . $stancerApi->error, LOG_ERR);
			$output->error = $stancerApi->error;
			if ($stancerApi->lastHttpCode == 401) {
				setEventMessages($langs->trans('StancerApiAuthError', $stancerApi->error), null, 'errors');
			}
			return $output;
		}

		$payouts = isset($listResult['payouts']) ? $listResult['payouts'] : (is_array($listResult) ? $listResult : []);
		$nbResults = count($payouts);
		dol_syslog("stancerRefreshAllPayoutsFromStancer page=$page got $nbResults results");

		foreach ($payouts as $payoutData) {
			$counter++;
			$payoutID = isset($payoutData['id']) ? $payoutData['id'] : '';
			if (empty($payoutID)) {
				continue;
			}

			dol_syslog("stancerRefreshAllPayoutsFromStancer counter #$counter payout $payoutID");

			// Fetch full payout data from API
			$payout = $stancerApi->getPayout($payoutID);
			if ($payout === false) {
				dol_syslog("stancerRefreshAllPayoutsFromStancer cannot fetch payout $payoutID: " . $stancerApi->error);
				if ($stancerApi->lastHttpCode == 401) {
					$output->error = $stancerApi->error;
					setEventMessages($langs->trans('StancerApiAuthError', $stancerApi->error), null, 'errors');
					return $output;
				}
				continue;
			}

			$res = $sp->fetch(0, null, $payoutID);
			$resFill = $sp->fillDataFromApi($payout);
			if ($resFill < 0) {
				dol_syslog("stancerRefreshAllPayoutsFromStancer next loop due to fillDataFromApi < 0: $resFill");
				continue;
			}

			// Object exists in database?
			if ($res) {
				$res2 = $sp->update($user);
			} else {
				$res2 = $sp->create($user);
			}
			if ($res2 < 0) {
				dol_syslog("stancerRefreshAllPayoutsFromDolibarr failed to persist local payout: " . $sp->error, LOG_ERR);
			}

			$label = "(BankTransfer)";

			// Get real net amount received on bank account from API v2 response.
			// v2 uses 'amount' (already nets refunds, disputes, fees, fees_vat).
			// v1 used 'total'. Fallback keeps backwards compat.
			$netCents = 0;
			if (isset($payout['amount'])) {
				$netCents = (int) $payout['amount'];
			} elseif (isset($payout['total'])) {
				$netCents = (int) $payout['total'];
			}
			$amount = $netCents > 0 ? $netCents / 100 : 0;

			// Get dates from API response. v2 exposes 'date' (unix ts), v1 exposed 'created'.
			$dateo = null;
			if (isset($payout['created'])) {
				$dateo = (string) $payout['created'];
			} elseif (isset($payout['date'])) {
				$dateo = (string) $payout['date'];
			}
			$datev = isset($payout['date_bank']) ? (string) $payout['date_bank'] : null;

			// Get statement description
			$labelTo = isset($payout['statement_description']) ? $payout['statement_description'] : '';

			dol_syslog("stancerRefreshAllPayoutsFromStancer counter #$counter payout date is dateo=$dateo / datev=$datev and amount=$amount");

			// If no bank date, transfer is not yet completed
			if (!empty($dateo) && ($amount > 0)) {
				dol_syslog("stancerRefreshAllPayoutsFromStancer add stancerAddTransfertFromAccountToAccount $dateo");
				stancerAddTransfertFromAccountToAccount($accountStancer, $accountMainBank, $dateo, $datev, $label, $labelTo, $amount, $amount, $payoutID, $userMessage);

				// Add fees to bank account. Stancer deducts fees TTC (fees + fees_vat)
				// in a single debit on the funding account, so we must book TTC here
				// to match the monthly Stancer supplier invoice.
				if (getDolGlobalString('STANCER_ADD_FEES') == 'PAYOUT') {
					$feesCents = (isset($payout['fees']) ? (int) $payout['fees'] : 0) + (isset($payout['fees_vat']) ? (int) $payout['fees_vat'] : 0);
					$fees = $feesCents / 100;
					$ref = $payoutID;
					$bankaccountid = getDolGlobalInt('STANCER_BANK_ACCOUNT_FOR_PAYMENTS');
					dol_syslog("STANCER_ADD_FEES is on each PAYOUT (TTC=$fees) in stancer_payouts_list.php ....");
					$resAddPaiment = stancerAddPaimentFeeOnBank($ref, $fees, $bankaccountid, $payoutID, $dateo, $datev);
					if ($resAddPaiment < 0) {
						$output->error = "error on stancerAddPaimentFeeOnBank for " . $ref;
						dol_syslog("STANCER_ADD_FEES error on stancerAddPaimentFeeOnBank for $ref", LOG_WARNING);
					}
				}
			} else {
				if ($amount == 0) {
					dol_syslog("stancerRefreshAllPayoutsFromStancer amount = 0 ... to be checked");
				} else {
					dol_syslog("stancerRefreshAllPayoutsFromStancer date or amount error", LOG_WARNING);
					$output->error = "refreshall error on date=" . json_encode($dateo) . " / " . json_encode($datev) . " or amount=$amount";
				}
			}
		}

		$start += $limit;
	} while ($nbResults >= $limit && $page < $maxPages);

	if ($page >= $maxPages) {
		dol_syslog("stancerRefreshAllPayoutsFromStancer reached max pages ($maxPages), some payouts may not have been synced", LOG_WARNING);
	}

	dol_syslog("stancerRefreshAllPayoutsFromStancer done: $counter payouts processed in $page pages");

	return $output;
}


/**
 * re télécharge un virement et fais le job
 * (mutualisation entre payouts_list et cron)
 *
 * @param   string    $payoutID     Stancer payout id (pout_xxx)
 * @param   bool      $userMessage  show messages to user
 * @param   int|null  $lastrun      timestamp of last run (cron mode), null for a full window
 * @return  stdClass                Report object, ->error is set on failure, ->message holds the CSV report
 */
function stancerRefreshOnePayout($payoutID, $userMessage = true, $lastrun = null)
{
	global $langs, $db, $user, $conf;
	//pour le cas d'un lancement recursif
	global $accountStancer;
	global $accountMainBank;

	dol_syslog("stancerRefreshOnePayout");
	$stancerApi = new StancerApi();

	$error = 0;

	$mailNotif = false;
	if (getDolGlobalString('STANCER_AUTO_MAIL_NOTIFICATIONS_PAYOUT', '') != '' && getDolGlobalString('STANCER_AUTO_MAIL_NOTIFICATIONS_PAYOUT_DETAILS', '') != '') {
		$mailNotif = true;
	}

	$output = new StdClass();
	$output->error = '';
	$output->message = '';

	if (is_object($accountStancer)) {
	} else {
		$accountStancer = new Account($db);
		$result = $accountStancer->fetch(getDolGlobalInt('STANCER_BANK_ACCOUNT_FOR_PAYMENTS'));
		if ($result < 0) {
			$error++;
			$output->error = "error STANCER_BANK_ACCOUNT_FOR_PAYMENTS is not defined";
			dol_syslog("stancerRefreshOnePayout STANCER_BANK_ACCOUNT_FOR_PAYMENTS is not defined", LOG_WARNING);
			return $output;
		}
	}

	if (is_object($accountMainBank)) {
	} else {
		$accountMainBank = new Account($db);
		$result = $accountMainBank->fetch(getDolGlobalInt('STANCER_BANK_MAIN_ACCOUNT_FOR_PAYOUTS'));
		if ($result < 0) {
			$error++;
			$output->error = "error STANCER_BANK_MAIN_ACCOUNT_FOR_PAYOUTS is not defined";
			dol_syslog("stancerRefreshOnePayout STANCER_BANK_MAIN_ACCOUNT_FOR_PAYOUTS is not defined", LOG_WARNING);
			return $output;
		}
	}

	//Refresh sur un historique de ... 1 mois, 60 jours ... autre ?
	$nbjour = stancerGetNumberOfDaysToGet();
	$dateFiltre = time() - (3600 * 24 * $nbjour);

	//Si c'est la tache planifiée on fait la diff par rapport à la date de dernier lancement
	if (!empty($lastrun)) {
		$dateFiltre = $lastrun;
	}

	$sp = new Stancer_payouts($db);

	$res = $sp->fetch(0, null, $payoutID);

	// Fetch payout data from API
	$payout = $stancerApi->getPayout($payoutID);
	if ($payout === false) {
		dol_syslog("stancerRefreshOnePayout cannot fetch payout $payoutID: " . $stancerApi->error, LOG_ERR);
		$output->error = "stancerRefreshOnePayout cannot fetch payout $payoutID: " . $stancerApi->error;
		return $output;
	}

	$resFill = $sp->fillDataFromApi($payout);
	if ($resFill < 0) {
		// F7: return the same StdClass ->error contract as the other paths, so the
		// caller doesn't fatal with "Attempt to read property on int".
		$output->error = "stancerRefreshOnePayout fillDataFromApi failed for $payoutID: $resFill";
		dol_syslog($output->error, LOG_ERR);
		return $output;
	}

	// Object exists in database?
	if ($res) {
		$res2 = $sp->update($user);
	} else {
		$res2 = $sp->create($user);
	}
	if ($res2 < 0) {
		dol_syslog("stancerRefreshOnePayout failed to persist local payout: " . $sp->error, LOG_ERR);
	}

	$label = "(BankTransfer)";

	// Get real net amount received on bank account from API v2 response.
	// v2 uses 'amount' (already nets refunds, disputes, fees, fees_vat).
	// v1 used 'total'. Fallback keeps backwards compat.
	$netCents = 0;
	if (isset($payout['amount'])) {
		$netCents = (int) $payout['amount'];
	} elseif (isset($payout['total'])) {
		$netCents = (int) $payout['total'];
	}
	$amount = $netCents > 0 ? $netCents / 100 : 0;

	// Get dates from API response. v2 exposes 'date' (unix ts), v1 exposed 'created'.
	$dateo = null;
	if (isset($payout['created'])) {
		$dateo = (string) $payout['created'];
	} elseif (isset($payout['date'])) {
		$dateo = (string) $payout['date'];
	}
	$datev = isset($payout['date_bank']) ? (string) $payout['date_bank'] : null;

	// Get statement description
	$labelTo = isset($payout['statement_description']) ? $payout['statement_description'] : '';

	dol_syslog("stancerRefreshOnePayout payout date is dateo=$dateo / datev=$datev and amount=$amount");

	// If no bank date, transfer is not yet completed
	if (!empty($dateo) && isset($amount)) {
		dol_syslog("stancerRefreshOnePayout add stancerAddTransfertFromAccountToAccount $dateo");
		stancerAddTransfertFromAccountToAccount($accountStancer, $accountMainBank, $dateo, $datev, $label, $labelTo, $amount, $amount, $payoutID, $userMessage);

		// Add fees to bank account. Stancer deducts fees TTC (fees + fees_vat)
		// in a single debit on the funding account, so we must book TTC here
		// to match the monthly Stancer supplier invoice.
		if (getDolGlobalString('STANCER_ADD_FEES') == 'PAYOUT') {
			$feesCents = (isset($payout['fees']) ? (int) $payout['fees'] : 0) + (isset($payout['fees_vat']) ? (int) $payout['fees_vat'] : 0);
			$fees = $feesCents / 100;
			$ref = $payoutID;
			$bankaccountid = getDolGlobalInt('STANCER_BANK_ACCOUNT_FOR_PAYMENTS');
			dol_syslog("STANCER_ADD_FEES is on each PAYOUT (TTC=$fees) in stancer_payouts_list.php ....");
			$resAddPaiment = stancerAddPaimentFeeOnBank($ref, $fees, $bankaccountid, $payoutID, $dateo, $datev);
			if ($resAddPaiment < 0) {
				$output->error = "error on stancerAddPaimentFeeOnBank for " . $ref;
				dol_syslog("stancerRefreshOnePayout error on stancerAddPaimentFeeOnBank for $ref", LOG_WARNING);
			}
		}
	} else {
		if ($amount == 0) {
			dol_syslog("stancerRefreshOnePayout amount = 0 ... to be checked");
		} else {
			dol_syslog("stancerRefreshOnePayout date or amount error", LOG_WARNING);
			$output->error = "stancerRefreshOnePayout :: error on date=" . json_encode($dateo) . " / " . json_encode($datev) . " or amount=" . json_encode($amount);
		}
	}

	return $output;
}


/**
 * liste les payout qui ne sont pas terminés dans dolibarr et cherche les détails
 * sur le serveur stancer
 *
 * @param   bool      $userMessage  show messages to user
 * @param   int|null  $lastrun      timestamp of last run (cron mode), null for a full window
 * @return  stdClass                Report object, ->error is set on failure, ->message holds the CSV report
 */
function stancerRefreshAllPayoutsFromDolibarr($userMessage = true, $lastrun = null)
{
	global $langs, $db, $user, $conf;
	dol_syslog("stancerRefreshAllPayoutsFromDolibarr");
	$stancerApi = new StancerApi();

	$error = 0;

	$mailNotif = false;
	if (getDolGlobalString('STANCER_AUTO_MAIL_NOTIFICATIONS_PAYOUT', '') != '' && getDolGlobalString('STANCER_AUTO_MAIL_NOTIFICATIONS_PAYOUT_DETAILS', '') != '') {
		$mailNotif = true;
	}

	$output = new StdClass();
	$output->error = '';
	$output->message = '';

	$accountStancer = new Account($db);
	$accountMainBank = new Account($db);

	$result = $accountStancer->fetch(getDolGlobalInt('STANCER_BANK_ACCOUNT_FOR_PAYMENTS'));
	if ($result < 0) {
		$error++;
		$output->error = "error STANCER_BANK_ACCOUNT_FOR_PAYMENTS is not defined";
		dol_syslog("STANCER_BANK_ACCOUNT_FOR_PAYMENTS is not defined", LOG_WARNING);
		return $output;
	}

	$result = $accountMainBank->fetch(getDolGlobalInt('STANCER_BANK_MAIN_ACCOUNT_FOR_PAYOUTS'));
	if ($result < 0) {
		$error++;
		$output->error = "error STANCER_BANK_MAIN_ACCOUNT_FOR_PAYOUTS is not defined";
		dol_syslog("STANCER_BANK_MAIN_ACCOUNT_FOR_PAYOUTS is not defined", LOG_WARNING);
		return $output;
	}

	// Declared before the try because the catch block interpolates it in its log
	// and error message: an empty string reads better there than a null.
	$payoutID = '';
	$sp = new Stancer_payouts($db);

	$resList = $sp->fetchAll('ASC', '', 0, 0, array('customsql' => "status <> '" . Stancer_payouts::STATUS_PAID . "'"));

	try {
		//Refresh sur un historique de 6 mois max ?
		$counter = 0;
		foreach ($resList as $oneSp) {
			$payoutID = $oneSp->payout_id;
			dol_syslog("stancerRefreshAllPayoutsFromDolibarr pid=" . $oneSp->payout_id . ", status=" . Stancer_payouts::STATUS_PAID);
			$payoutData = $stancerApi->getPayout($oneSp->payout_id);
			$counter++;
			if ($payoutData === false) {
				dol_syslog("stancerRefreshAllPayoutsFromDolibarr error fetching payout: " . $stancerApi->error, LOG_WARNING);
				if ($stancerApi->lastHttpCode == 401) {
					$output->error = $stancerApi->error;
					setEventMessages($langs->trans('StancerApiAuthError', $stancerApi->error), null, 'errors');
					return $output;
				}
				continue;
			}
			dol_syslog("stancerRefreshAllPayoutsFromDolibarr counter #$counter payout payoutID=$payoutID :: payout=" . json_encode($payoutData));

			$payoutStatus = isset($payoutData['status']) ? $payoutData['status'] : '';
			if ($payoutStatus != 'paid') {
				dol_syslog("stancerRefreshAllPayoutsFromDolibarr status is not paid, payout->status=" . $payoutStatus);
				continue;
			}

			$res = $sp->fetch(0, null, $payoutID);
			if ($res) {
				$resFill = $sp->fillDataFromApi($payoutData);
				if ($resFill < 0) {
					dol_syslog("stancerRefreshAllPayoutsFromDolibarr next loop due to fillData < 0: $resFill");
					continue;
				}
			}

			//object exist in database ?
			$res2 = $sp->update($user);
			if ($res2) {
				$label = "(BankTransfer)";
				//Ajout des virements de compte à compte ...

				$amount = 0;
				if (isset($payoutData['total']) && $payoutData['total'] > 0) {
					$amount = $payoutData['total'] / 100;
				}

				$dateo = isset($payoutData['created']) ? (string) $payoutData['created'] : '';
				// Empty string and not null: the consumers (stancerAddTransfertFromAccountToAccount,
				// stancerAddPaimentFeeOnBank) both test empty($datev) and expect a string.
				$datev = '';
				if (!empty($payoutData['date_bank'])) {
					$datev = (string) $payoutData['date_bank'];
				}

				//intitulé qui sera sur le compte bancaire C.A.
				$labelTo = isset($payoutData['statement_description']) ? $payoutData['statement_description'] : '';

				dol_syslog("stancerRefreshAllPayoutsFromDolibarr counter #$counter payout date is dateo=$dateo / datev=$datev and amount=$amount");

				//si pas de date banque c'est que le transfert n'est pas encore terminé
				if (!empty($dateo) && ($amount  > 0)) {
					dol_syslog("stancerRefreshAllPayoutsFromDolibarr add stancerAddTransfertFromAccountToAccount $dateo");
					stancerAddTransfertFromAccountToAccount($accountStancer, $accountMainBank, $dateo, $datev, $label, $labelTo, $amount, $amount, $payoutID, $userMessage);

					// erics add new line on bank account for Stancer Fees
					if (getDolGlobalString('STANCER_ADD_FEES') == 'PAYOUT') {
						$fees = isset($payoutData['fees']) ? $payoutData['fees'] / 100 : 0;
						$ref = $payoutID;
						$bankaccountid = getDolGlobalInt('STANCER_BANK_ACCOUNT_FOR_PAYMENTS');
						dol_syslog("STANCER_ADD_FEES is on each PAYOUT in stancer_payouts_list.php ....");
						$resAddPaiment = stancerAddPaimentFeeOnBank($ref, $fees, $bankaccountid, $payoutID, $dateo, $datev);
						if ($resAddPaiment < 0) {
							$output->error = "error on stancerAddPaimentFeeOnBank for " . $ref;
							dol_syslog("stancerRefreshAllPayoutsFromDolibarr error on stancerAddPaimentFeeOnBank for $ref", LOG_WARNING);
						}
					}
				} else {
					if ($amount == 0) {
						dol_syslog("stancerRefreshAllPayoutsFromDolibarr amount = 0 ... to be checked");
					} else {
						dol_syslog("stancerRefreshAllPayoutsFromDolibarr date or amount error", LOG_WARNING);
						$output->error = "refreshall error on date=" . json_encode($dateo) . " / " . json_encode($datev) . " or amount=$amount";
					}
				}
			}
		}
	} catch (Exception $e) {
		$message = $e->getMessage();
		if ($mailNotif) {
			stancerSendMail(getDolGlobalString('STANCER_AUTO_MAIL_NOTIFICATIONS_PAYOUT', ''), $langs->trans('StancerMailSubjectPayoutExeption'), $langs->trans('StancerMailPayoutExeption', $message));
		}
		dol_syslog("stancerRefreshAllPayoutsFromDolibarr exception (1) occurs for payoutID=$payoutID message=" . $message, LOG_ERR);
		$output->error = "stancerRefreshAllPayoutsFromDolibarr exception (1) occurs for payoutID=$payoutID message=" . $message;
	}
	return $output;
}


/**
 * get number of days to get (history)
 * default is 31 (1 month)
 *
 * @return	int		Number of days read from STANCER_NB_DAYS_TO_SYNC, 31 when unset or out of the 1-3649 range
 */
function stancerGetNumberOfDaysToGet()
{
	global $conf;
	$nb = 31;
	if (getDolGlobalString('STANCER_NB_DAYS_TO_SYNC', '') != '') {
		$num = (int) getDolGlobalString('STANCER_NB_DAYS_TO_SYNC');
		if (is_int($num) && $num > 0 && $num < 3650) {
			$nb = $num;
		}
	}
	return (int) $nb;
}

/**
 * First-day-of-current-fiscal-year timestamp, used as the strict lower bound of
 * the history sync (payments + payouts), so the refresh only covers the current
 * fiscal year and never reaches into closed ones. The fiscal year start month is
 * read from Dolibarr's SOCIETE_FISCAL_MONTH_START (1-12, default 1 = January).
 *
 * @return int  Unix timestamp of the first day (00:00) of the current fiscal year.
 */
function stancerGetFiscalYearStartTs()
{
	$fiscalMonth = (int) getDolGlobalString('SOCIETE_FISCAL_MONTH_START', '1');
	if ($fiscalMonth < 1 || $fiscalMonth > 12) {
		$fiscalMonth = 1;
	}
	$tmp = dol_getdate(dol_now());
	$startYear = ($tmp['mon'] >= $fiscalMonth) ? $tmp['year'] : ($tmp['year'] - 1);
	return dol_mktime(0, 0, 0, $fiscalMonth, 1, $startYear);
}

/**
 * Number of days of recently-captured payments to re-audit for late status changes.
 * Default is 30. A value of 0 disables the audit pass entirely.
 *
 * @return  int  number of days (0 = disabled)
 */
function stancerGetAuditCapturedWindowDays()
{
	$nb = 30;
	if (getDolGlobalString('STANCER_AUDIT_CAPTURED_WINDOW_DAYS', '') != '') {
		$num = (int) getDolGlobalString('STANCER_AUDIT_CAPTURED_WINDOW_DAYS');
		if ($num >= 0 && $num < 3650) {
			$nb = $num;
		}
	}
	return (int) $nb;
}

/**
 * get number of items to retrieve (history)
 *
 * @return	int		Page size read from STANCER_NUMBER_OF_ITEMS_TO_SYNC, 10 when unset or out of the 1-100 range
 */
function stancerGetNumberOfItemToGet()
{
	global $conf;
	$nb = 10;
	if (getDolGlobalString('STANCER_NUMBER_OF_ITEMS_TO_SYNC', '') != '') {
		$num = (int) getDolGlobalString('STANCER_NUMBER_OF_ITEMS_TO_SYNC');
		if (is_int($num) && $num > 0 && $num <= 100) {
			$nb = $num;
		}
	}
	return (int) $nb;
}

/**
 * Handler for the "refreshselected" mass action used by stancer_payments_list.php.
 * Extracted into the lib so it can be unit-tested without a full HTTP round-trip
 * (the previous inline version was placed AFTER Dolibarr's mass-action reset, which
 * silently reset $massaction to '' and made the handler a no-op for any custom
 * mass action that does not go through a separate confirmmassaction step).
 *
 * The function takes the same inputs the page handler had and:
 *  - filters $toselect to positive integers
 *  - calls stancerRefreshAllPaymentsFromDolibarr in audit mode (selectedIds)
 *  - reports the result via setEventMessages
 *  - resets $massaction so the rest of the page pipeline does not try to
 *    re-handle the same action.
 *
 * @param array|null $toselect          array of local rowid coming from $_POST['toselect']
 * @param int        $notifyCustomers   0/1 flag coming from the notify checkbox
 * @param bool       $permissiontoadd   guard, mirrors the page permission check
 * @param string     $massaction        in/out: current mass action; reset to '' on success
 * @return stdClass|null result of stancerRefreshAllPaymentsFromDolibarr, or null when
 *                     the action did not match the entry conditions.
 */
function stancerHandleRefreshSelectedMassAction($toselect, $notifyCustomers, $permissiontoadd, &$massaction)
{
	global $langs;

	if ($massaction !== 'refreshselected' || empty($toselect) || !$permissiontoadd) {
		return null;
	}

	$ids = array_values(array_filter(array_map('intval', (array) $toselect)));
	if (empty($ids)) {
		dol_syslog("stancer payments_list: mass action refreshselected called with empty/invalid toselect, skip");
		$massaction = '';
		return null;
	}

	dol_syslog("stancer payments_list: mass action refreshselected on " . count($ids) . " ids, notify=" . (int) $notifyCustomers);
	$res = stancerRefreshAllPaymentsFromDolibarr(false, null, (bool) $notifyCustomers, $ids);

	if (!empty($res->error)) {
		setEventMessages($res->error, null, 'errors');
	} else {
		setEventMessages($langs->trans('StancerForceRefreshSelectedDone', count($ids)), null, 'mesgs');
	}

	$massaction = '';
	return $res;
}
