<?php
/* Copyright (C) 2023-2026 Eric Seigne <eric.seigne@cap-rel.fr>
 * Copyright (C) 2026		MDW			<mdeweerd@users.noreply.github.com>
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
 * \file    stancer/lib/stancer_dispute.lib.php
 * \ingroup stancer
 * \brief   Dispute, refund, reopen invoice, SEPA codes, fee invoices
 */

require_once DOL_DOCUMENT_ROOT . '/core/lib/functions.lib.php';

/**
 * Reopen the invoice(s) linked to a Stancer payment when a dispute is lost or a refund is confirmed.
 * Finds the invoice(s) via the local Stancer_payments row:
 *  - grouped_invoice_ids (comma-separated) for same-day grouped SEPA debits (returns array<Facture>)
 *  - unique_id (INV=<id>), order_id or llx_paiement fallback for solo payments (returns Facture)
 *
 * @param  string  $paymentStancerId  Stancer payment ID (paym_xxx)
 * @param  string  $reason            Reason for reopening (for the note)
 * @return Facture|Facture[]|int      Facture for solo, array<Facture> for grouped,
 *                                    0 if no invoice found or already unpaid, -1 on error
 *
 * The grouped path only returns the array once at least one invoice has been reopened
 * (the empty case returns 0 earlier), so the real type is narrower than Facture[].
 * Documenting a non-empty list here would be wrong for any future caller.
 * @phan-suppress PhanPluginMoreSpecificActualReturnType
 */
function stancerReopenInvoiceFromPayment($paymentStancerId, $reason)
{
	global $db, $user, $conf, $langs;

	if (empty($paymentStancerId)) {
		dol_syslog("stancerReopenInvoiceFromPayment called with empty paymentStancerId", LOG_ERR);
		return -1;
	}

	dol_syslog("stancerReopenInvoiceFromPayment paymentStancerId=$paymentStancerId reason=$reason");

	// Find local payment
	$sp = new Stancer_payments($db);
	$resPayment = $sp->fetch(0, '', $paymentStancerId);
	if (!$resPayment) {
		dol_syslog("stancerReopenInvoiceFromPayment payment $paymentStancerId not found in local DB", LOG_WARNING);
		return 0;
	}

	// Grouped payment path: reopen every linked invoice and create ONE reverse Paiement covering all of them.
	if (!empty($sp->grouped_invoice_ids)) {
		$ids = array_filter(array_map('intval', explode(',', (string) $sp->grouped_invoice_ids)));
		dol_syslog("stancerReopenInvoiceFromPayment $paymentStancerId is a grouped payment, " . count($ids) . " invoice ids to process");

		if (empty($ids)) {
			dol_syslog("stancerReopenInvoiceFromPayment $paymentStancerId grouped_invoice_ids empty after parse", LOG_ERR);
			return 0;
		}

		// Fetch the original Paiement row (single row, multiple amounts dispatch) to retrieve paymenttype and per-invoice allocations.
		$origPaymentRowId = 0;
		$origPaymentTypeId = 0;
		$perInvoiceAlloc = array();
		$sqlOrig = "SELECT p.rowid, p.fk_paiement, pf.fk_facture, pf.amount";
		$sqlOrig .= " FROM " . MAIN_DB_PREFIX . "paiement AS p";
		$sqlOrig .= " INNER JOIN " . MAIN_DB_PREFIX . "paiement_facture AS pf ON pf.fk_paiement = p.rowid";
		$sqlOrig .= " WHERE p.num_paiement = '" . $db->escape($paymentStancerId) . "'";
		$resOrig = $db->query($sqlOrig);
		if ($resOrig) {
			while ($row = $db->fetch_object($resOrig)) {
				$origPaymentRowId = (int) $row->rowid;
				$origPaymentTypeId = (int) $row->fk_paiement;
				$perInvoiceAlloc[(int) $row->fk_facture] = (float) $row->amount;
			}
		}

		$reopened = array();
		foreach ($ids as $iid) {
			$facture = new Facture($db);
			if ($facture->fetch($iid) <= 0) {
				dol_syslog("stancerReopenInvoiceFromPayment $paymentStancerId grouped: cannot fetch invoice id=$iid, skip", LOG_ERR);
				continue;
			}
			if ($facture->status != Facture::STATUS_CLOSED) {
				dol_syslog("stancerReopenInvoiceFromPayment $paymentStancerId grouped: invoice " . $facture->ref . " is not closed (status=" . $facture->status . "), skip", LOG_DEBUG);
				continue;
			}
			$resUnpaid = $facture->setUnpaid($user);
			if ($resUnpaid < 0) {
				dol_syslog("stancerReopenInvoiceFromPayment $paymentStancerId grouped: setUnpaid failed for " . $facture->ref . ": " . $facture->error, LOG_ERR);
				continue;
			}

			// Private note per invoice
			$note = dol_now('tzserver');
			$noteContent = dol_print_date($note, 'dayhour') . ' - ' . $reason;
			$existingNote = $facture->note_private;
			$facture->note_private = (!empty($existingNote) ? $existingNote . "\n" : '') . $noteContent;
			$facture->update_note($facture->note_private, '_private');

			$reopened[] = $facture;
		}

		if (count($reopened) === 0) {
			dol_syslog("stancerReopenInvoiceFromPayment $paymentStancerId grouped: no invoice could be reopened", LOG_DEBUG);
			return 0;
		}

		// Build a single reverse Paiement covering the reopened invoices (negative amounts).
		$reverseAmounts = array();
		foreach ($reopened as $fac) {
			if (isset($perInvoiceAlloc[$fac->id]) && $perInvoiceAlloc[$fac->id] > 0) {
				$reverseAmounts[$fac->id] = -1 * (float) $perInvoiceAlloc[$fac->id];
			}
		}
		if (count($reverseAmounts) > 0 && $origPaymentTypeId > 0) {
			$reverse = new Paiement($db);
			$reverse->datepaye = dol_now();
			$reverse->amounts = $reverseAmounts;
			$reverse->paiementid = $origPaymentTypeId;
			$reverse->num_payment = 'RETURN_' . $paymentStancerId;
			$reverse->note_public = $reason;
			$reverseId = $reverse->create($user, 0);
			if ($reverseId > 0) {
				$bankAccountId = getDolGlobalInt('STANCER_BANK_ACCOUNT_FOR_PAYMENTS');
				if ($bankAccountId > 0) {
					$labelReverse = 'SEPA return ' . implode('+', array_map(function ($f) {
						return $f->ref; }, $reopened));
					$resBankAdd = $reverse->addPaymentToBank($user, 'payment', $labelReverse, $bankAccountId, '', '');
					if ($resBankAdd < 0) {
						dol_syslog("stancerReopenInvoiceFromPayment $paymentStancerId grouped: reverse addPaymentToBank failed: " . $reverse->error, LOG_ERR);
					}
				}
				dol_syslog("stancerReopenInvoiceFromPayment $paymentStancerId grouped: reverse Paiement id=$reverseId created for " . count($reverseAmounts) . " invoices", LOG_INFO);
			} else {
				dol_syslog("stancerReopenInvoiceFromPayment $paymentStancerId grouped: reverse Paiement creation failed: " . $reverse->error, LOG_ERR);
			}
		} else {
			dol_syslog("stancerReopenInvoiceFromPayment $paymentStancerId grouped: no per-invoice allocation found, no reverse Paiement created (origPaymentRowId=$origPaymentRowId)", LOG_WARNING);
		}

		return $reopened;
	}

	// Solo payment path (legacy): try to find invoice ID from unique_id (pattern: INV=<id> or INV=<id>.xxx)
	$invoiceId = 0;
	if (!empty($sp->unique_id) && preg_match('/INV=(\d+)/', $sp->unique_id, $matches)) {
		$invoiceId = (int) $matches[1];
	}

	// Fallback 1: try order_id which contains the invoice ref (e.g. FA2304-1134)
	if (empty($invoiceId) && !empty($sp->order_id)) {
		$facture = new Facture($db);
		$resFetch = $facture->fetch(0, $sp->order_id);
		if ($resFetch > 0) {
			$invoiceId = $facture->id;
		}
	}

	// Fallback 2: find via Dolibarr payment (llx_paiement.num_paiement = paym_xxx -> llx_paiement_facture)
	if (empty($invoiceId)) {
		$sqlPaiement = "SELECT pf.fk_facture FROM " . MAIN_DB_PREFIX . "paiement AS p";
		$sqlPaiement .= " INNER JOIN " . MAIN_DB_PREFIX . "paiement_facture AS pf ON pf.fk_paiement = p.rowid";
		$sqlPaiement .= " WHERE p.num_paiement = '" . $db->escape($paymentStancerId) . "'";
		$sqlPaiement .= " LIMIT 1";
		$resPaiement = $db->query($sqlPaiement);
		if ($resPaiement) {
			$objPaiement = $db->fetch_object($resPaiement);
			if ($objPaiement && !empty($objPaiement->fk_facture)) {
				$invoiceId = (int) $objPaiement->fk_facture;
				dol_syslog("stancerReopenInvoiceFromPayment found invoice via llx_paiement.num_paiement=$paymentStancerId -> fk_facture=$invoiceId");
			}
		} else {
			dol_syslog("stancerReopenInvoiceFromPayment SQL error on paiement fallback: " . $db->lasterror(), LOG_ERR);
		}
	}

	if (empty($invoiceId)) {
		dol_syslog("stancerReopenInvoiceFromPayment no invoice found for payment $paymentStancerId (unique_id=" . $sp->unique_id . ", order_id=" . $sp->order_id . ", paiement fallback also failed)", LOG_WARNING);
		return 0;
	}

	$facture = new Facture($db);
	$resFetch = $facture->fetch($invoiceId);
	if ($resFetch <= 0) {
		dol_syslog("stancerReopenInvoiceFromPayment cannot fetch invoice id=$invoiceId: " . $facture->error, LOG_ERR);
		return -1;
	}

	// Only reopen if invoice is currently paid (status = Facture::STATUS_CLOSED = 2)
	if ($facture->status != Facture::STATUS_CLOSED) {
		dol_syslog("stancerReopenInvoiceFromPayment invoice " . $facture->ref . " is not paid (status=" . $facture->status . "), skip", LOG_DEBUG);
		return 0;
	}

	// SAFETY: do NOT setUnpaid if the invoice is already fully covered by OTHER positive
	// payments (typical retry scenario: first Stancer attempt refused -> second attempt
	// captured under a different paym_id). Reopening in that case wipes a perfectly valid
	// payment and the customer ends up "owing" money on an invoice that Stancer already
	// captured.
	$sqlOtherPay = "SELECT pf.amount, p.num_paiement";
	$sqlOtherPay .= " FROM " . MAIN_DB_PREFIX . "paiement AS p";
	$sqlOtherPay .= " INNER JOIN " . MAIN_DB_PREFIX . "paiement_facture AS pf ON pf.fk_paiement = p.rowid";
	$sqlOtherPay .= " WHERE pf.fk_facture = " . ((int) $invoiceId);
	$sqlOtherPay .= " AND (p.num_paiement IS NULL OR p.num_paiement != '" . $db->escape($paymentStancerId) . "')";
	$sqlOtherPay .= " AND pf.amount > 0";
	$resOtherPay = $db->query($sqlOtherPay);
	if ($resOtherPay) {
		$otherPaid = 0.0;
		$otherRefs = array();
		while ($row = $db->fetch_object($resOtherPay)) {
			$otherPaid += (float) $row->amount;
			if (!empty($row->num_paiement)) {
				$otherRefs[] = $row->num_paiement;
			}
		}
		if ($otherPaid >= (float) $facture->total_ttc - 0.001) {
			dol_syslog("stancerReopenInvoiceFromPayment ABORT reopen of " . $facture->ref
				. " for refused paym=$paymentStancerId: invoice is already fully covered by other payment(s) totalling "
				. number_format($otherPaid, 2) . " (total_ttc=" . number_format((float) $facture->total_ttc, 2)
				. "), refs=[" . implode(',', $otherRefs) . "]. Likely a retry succeeded under a different Stancer id.", LOG_WARNING);
			return 0;
		}
	} else {
		dol_syslog("stancerReopenInvoiceFromPayment SQL error on retry-check: " . $db->lasterror(), LOG_ERR);
	}

	// F3: wrap the whole reopen sequence (setUnpaid + reverse payment + bank entry
	// + note) in one transaction so a partial failure leaves nothing half-applied.
	$db->begin();
	$result = $facture->setUnpaid($user);
	if ($result < 0) {
		dol_syslog("stancerReopenInvoiceFromPayment setUnpaid failed on invoice " . $facture->ref . ": " . $facture->error, LOG_ERR);
		$db->rollback();
		return -1;
	}

	// Create reverse payment (negative amount) to restore "reste à payer"
	$sqlOrigPayment = "SELECT p.rowid, p.fk_paiement, pf.amount as pf_amount";
	$sqlOrigPayment .= " FROM " . MAIN_DB_PREFIX . "paiement AS p";
	$sqlOrigPayment .= " INNER JOIN " . MAIN_DB_PREFIX . "paiement_facture AS pf ON pf.fk_paiement = p.rowid";
	$sqlOrigPayment .= " WHERE p.num_paiement = '" . $db->escape($paymentStancerId) . "'";
	$sqlOrigPayment .= " AND pf.fk_facture = " . ((int) $invoiceId);
	$sqlOrigPayment .= " LIMIT 1";
	$resOrigPayment = $db->query($sqlOrigPayment);
	if ($resOrigPayment) {
		$origPayment = $db->fetch_object($resOrigPayment);
		if ($origPayment && $origPayment->pf_amount > 0) {
			$reversePayment = new Paiement($db);
			$reversePayment->datepaye = dol_now();
			$reversePayment->amounts = array($invoiceId => -((float) $origPayment->pf_amount));
			$reversePayment->paiementid = $origPayment->fk_paiement;
			$reversePayment->num_payment = 'RETURN_' . $paymentStancerId;
			$reversePayment->note_public = $reason;

			$reverseId = $reversePayment->create($user, 0);
			if ($reverseId > 0) {
				$bankAccountId = getDolGlobalInt('STANCER_BANK_ACCOUNT_FOR_PAYMENTS');
				if ($bankAccountId > 0) {
					$resBankAdd = $reversePayment->addPaymentToBank($user, 'payment', 'SEPA return ' . $facture->ref, $bankAccountId, '', '');
					if ($resBankAdd < 0) {
						dol_syslog("stancerReopenInvoiceFromPayment reverse payment bank entry failed for invoice " . $facture->ref . ": " . $reversePayment->error, LOG_ERR);
						$db->rollback();
						return -1;
					}
				} else {
					dol_syslog("stancerReopenInvoiceFromPayment STANCER_BANK_ACCOUNT_FOR_PAYMENTS not configured, reverse payment not added to bank", LOG_WARNING);
				}
				dol_syslog("stancerReopenInvoiceFromPayment reverse payment created (id=$reverseId, amount=-" . $origPayment->pf_amount . ") for invoice " . $facture->ref, LOG_INFO);
			} else {
				dol_syslog("stancerReopenInvoiceFromPayment reverse payment creation failed for invoice " . $facture->ref . ": " . $reversePayment->error, LOG_ERR);
				$db->rollback();
				return -1;
			}
		} else {
			dol_syslog("stancerReopenInvoiceFromPayment no original Dolibarr payment found for num_paiement=$paymentStancerId on invoice $invoiceId", LOG_WARNING);
		}
	} else {
		dol_syslog("stancerReopenInvoiceFromPayment SQL error looking for original payment: " . $db->lasterror(), LOG_ERR);
	}

	// Add a private note for traceability
	$note = dol_now('tzserver');
	$noteContent = dol_print_date($note, 'dayhour') . ' - ' . $reason;
	$existingNote = $facture->note_private;
	$facture->note_private = (!empty($existingNote) ? $existingNote . "\n" : '') . $noteContent;
	$resNote = $facture->update_note($facture->note_private, '_private');
	if ($resNote < 0) {
		dol_syslog("stancerReopenInvoiceFromPayment update_note failed for invoice " . $facture->ref . ": " . $facture->error, LOG_ERR);
		$db->rollback();
		return -1;
	}

	$db->commit();
	dol_syslog("stancerReopenInvoiceFromPayment invoice " . $facture->ref . " set to unpaid, reason: $reason", LOG_INFO);
	return $facture;
}

/**
 * Get human-readable message for a SEPA response code (ISO 20022)
 *
 * @param  string $code  SEPA response code (e.g. 'AC01', 'AM04')
 * @return string        Human-readable message in current language, or the code itself if unknown
 */
function stancerGetSepaResponseMessage($code)
{
	global $langs;
	$langs->load("stancer@stancer");

	$codeMap = array(
		'AC01' => 'StancerSepaAC01',
		'AC04' => 'StancerSepaAC04',
		'AC06' => 'StancerSepaAC06',
		'AC13' => 'StancerSepaAC13',
		'AG01' => 'StancerSepaAG01',
		'AG02' => 'StancerSepaAG02',
		'AM04' => 'StancerSepaAM04',
		'AM05' => 'StancerSepaAM05',
		'BE05' => 'StancerSepaBE05',
		'CNOR' => 'StancerSepaCNOR',
		'DNOR' => 'StancerSepaDNOR',
		'ED05' => 'StancerSepaED05',
		'FF01' => 'StancerSepaFF01',
		'MD01' => 'StancerSepaMD01',
		'MD02' => 'StancerSepaMD02',
		'MD06' => 'StancerSepaMD06',
		'MD07' => 'StancerSepaMD07',
		'MS02' => 'StancerSepaMS02',
		'MS03' => 'StancerSepaMS03',
		'RC01' => 'StancerSepaRC01',
		'RR01' => 'StancerSepaRR01',
		'RR02' => 'StancerSepaRR02',
		'RR03' => 'StancerSepaRR03',
		'RR04' => 'StancerSepaRR04',
		'SL01' => 'StancerSepaSL01',
	);

	if (empty($code)) {
		return '';
	}

	$upperCode = dol_strtoupper(trim($code));
	if (isset($codeMap[$upperCode])) {
		return $langs->transnoentitiesnoconv($codeMap[$upperCode]);
	}

	dol_syslog("stancerGetSepaResponseMessage unknown SEPA code: $code", LOG_WARNING);
	return $code;
}

/**
 * Create a rejection fee invoice for a thirdparty after a SEPA rejection
 *
 * @param  int     $socid        Thirdparty ID
 * @param  string  $responseCode SEPA response code (e.g. 'AC01') for the invoice note
 * @param  string  $invoiceRef   Original invoice ref for context, can be empty
 * @return Facture|string         Invoice object on success, error message string on failure
 */
function stancerCreateRejectionFeeInvoice($socid, $responseCode = '', $invoiceRef = '')
{
	global $db, $user, $langs, $conf;
	$langs->load("stancer@stancer");

	dol_syslog("stancerCreateRejectionFeeInvoice socid=$socid responseCode=$responseCode invoiceRef=$invoiceRef");

	$productId = getDolGlobalInt('STANCER_SEPA_REJECTION_FEE_PRODUCT_ID', 0);
	if (empty($productId)) {
		dol_syslog("stancerCreateRejectionFeeInvoice STANCER_SEPA_REJECTION_FEE_PRODUCT_ID not configured", LOG_ERR);
		return "STANCER_SEPA_REJECTION_FEE_PRODUCT_ID not configured";
	}

	require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
	$product = new Product($db);
	$resProd = $product->fetch($productId);
	if ($resProd <= 0) {
		dol_syslog("stancerCreateRejectionFeeInvoice cannot fetch product id=$productId: " . $product->error, LOG_ERR);
		return "Cannot fetch product id=$productId";
	}

	$customerAmount = (float) $product->price;
	if ($customerAmount <= 0) {
		dol_syslog("stancerCreateRejectionFeeInvoice product id=$productId has no price (price=" . $product->price . ")", LOG_ERR);
		return "Product id=$productId has no price configured";
	}

	// Build line description
	$desc = $langs->transnoentitiesnoconv('StancerSepaRejectionFeeDesc');
	if (!empty($responseCode)) {
		$readableReason = stancerGetSepaResponseMessage($responseCode);
		$desc .= ' - ' . $responseCode . ' : ' . $readableReason;
	}
	if (!empty($invoiceRef)) {
		$desc .= ' (' . $langs->transnoentitiesnoconv('StancerSepaRejectionFeeOrigInvoice', $invoiceRef) . ')';
	}

	$db->begin();

	$facture = new Facture($db);
	$facture->socid = (int) $socid;
	$facture->type = Facture::TYPE_STANDARD;
	$facture->date = dol_now();
	$facture->note_private = $langs->transnoentitiesnoconv('StancerSepaRejectionFeeDesc') . ' - ' . $responseCode;

	// Do NOT set any payment mode: this invoice must not be auto-collected by SEPA
	// to avoid an infinite loop (rejection -> fee invoice -> SEPA collection -> rejection -> fee ...)
	// The accountant will handle payment manually.
	$facture->mode_reglement_id = 0;
	$facture->fk_account = 0;

	$factureId = $facture->create($user);
	if ($factureId <= 0) {
		$db->rollback();
		dol_syslog("stancerCreateRejectionFeeInvoice create failed: " . implode(', ', $facture->errors), LOG_ERR);
		return "Invoice create failed: " . implode(', ', $facture->errors);
	}

	$tvaTx = !empty($product->tva_tx) ? $product->tva_tx : 0;
	$resultLine = $facture->addline(
		$desc,
		$customerAmount,
		1,
		$tvaTx,
		0,
		0,
		$product->id,
		0,
		'',
		'',
		0,
		0,
		0,
		'HT'
	);
	if ($resultLine < 0) {
		$db->rollback();
		dol_syslog("stancerCreateRejectionFeeInvoice addline failed: " . implode(', ', $facture->errors), LOG_ERR);
		return "Invoice addline failed: " . implode(', ', $facture->errors);
	}

	$resultValidate = $facture->validate($user);
	if (is_numeric($resultValidate) && $resultValidate <= 0) {
		dol_syslog("stancerCreateRejectionFeeInvoice validate failed, retry without trigger: " . implode(', ', $facture->errors), LOG_WARNING);
		$resultValidate = $facture->validate($user, '', 0, 1, 0);
	}
	if (is_numeric($resultValidate) && $resultValidate <= 0) {
		$db->rollback();
		dol_syslog("stancerCreateRejectionFeeInvoice validate failed: " . implode(', ', $facture->errors), LOG_ERR);
		return "Invoice validate failed: " . implode(', ', $facture->errors);
	}

	$db->commit();
	dol_syslog("stancerCreateRejectionFeeInvoice created and validated invoice " . $facture->ref . " for socid=$socid", LOG_INFO);
	return $facture;
}

/**
 * télécharge une dispute et fais le job
 *
 * @param   string    $id  Stancer dispute id (dspt_xxx)
 * @return  stdClass       Report object with 'error' and 'message' properties
 */
function stancerRefreshOneDispute($id)
{
	global $langs, $db, $user, $conf;
	//pour le cas d'un lancement recursif
	global $accountStancer;
	global $accountMainBank;

	dol_syslog("stancerRefreshOneDispute");
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
			dol_syslog("stancerRefreshOneDispute STANCER_BANK_ACCOUNT_FOR_PAYMENTS is not defined", LOG_WARNING);
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
			dol_syslog("stancerRefreshOneDispute STANCER_BANK_MAIN_ACCOUNT_FOR_PAYOUTS is not defined", LOG_WARNING);
			return $output;
		}
	}

	//Refresh sur un historique de ... 1 mois, 60 jours ... autre ?
	$nbjour = stancerGetNumberOfDaysToGet();
	$dateFiltre = time() - (3600 * 24 * $nbjour);

	// Fetch dispute data from API
	$dispute = $stancerApi->getDispute($id);
	if ($dispute === false) {
		dol_syslog("stancerRefreshOneDispute cannot fetch dispute $id: " . $stancerApi->error, LOG_ERR);
		$output->error = "stancerRefreshOneDispute cannot fetch dispute $id: " . $stancerApi->error;
		return $output;
	}

	$label = "(BankTransfer)";

	// Get amount from API response
	$amount = 0;
	if (isset($dispute['amount']) && $dispute['amount'] > 0) {
		$amount = $dispute['amount'] / 100;
	}

	// Get dates from API response
	$dateo = isset($dispute['created']) ? (string) $dispute['created'] : null;
	$datev = isset($dispute['date_bank']) ? (string) $dispute['date_bank'] : null;

	// Get label
	$orderId = isset($dispute['order_id']) ? $dispute['order_id'] : '';
	$response = isset($dispute['response']) ? $dispute['response'] : '';
	$labelTo = $orderId . "/" . $response;

	dol_syslog("stancerRefreshOneDispute dispute date is dateo=$dateo / datev=$datev and amount=$amount");

	// If no bank date, transfer is not yet completed
	if (!empty($dateo) && isset($amount)) {
		dol_syslog("stancerRefreshOneDispute add stancerAddTransfertFromAccountToAccount $dateo");
		stancerAddTransfertFromAccountToAccount($accountMainBank, $accountStancer, $dateo, $datev, $label, $labelTo, $amount, $amount, $id, false);
	} else {
		dol_syslog("stancerRefreshOneDispute date or amount error", LOG_WARNING);
		$output->error = "stancerRefreshOneDispute :: error on date=" . json_encode($dateo) . " / " . json_encode($datev) . " or amount=" . json_encode($amount);
	}

	return $output;
}


/**
 * télécharge un remboursement et fais le job
 *
 * @param   string    $id  Stancer refund id (refd_xxx)
 * @return  stdClass       Report object with 'error' and 'message' properties
 */
function stancerRefreshOneRefund($id)
{
	global $langs, $db, $user, $conf;
	//pour le cas d'un lancement recursif
	global $accountStancer;
	global $accountMainBank;

	dol_syslog("stancer [$id] stancerRefreshOneRefund");

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
			$output->error = "[$id] error STANCER_BANK_ACCOUNT_FOR_PAYMENTS is not defined";
			return $output;
		}
	}

	if (is_object($accountMainBank)) {
	} else {
		$accountMainBank = new Account($db);
		$result = $accountMainBank->fetch(getDolGlobalInt('STANCER_BANK_MAIN_ACCOUNT_FOR_PAYOUTS'));
		if ($result < 0) {
			$error++;
			$output->error = "[$id] error STANCER_BANK_MAIN_ACCOUNT_FOR_PAYOUTS is not defined";
			return $output;
		}
	}

	//Refresh sur un historique de ... 1 mois, 60 jours ... autre ?
	$nbjour = stancerGetNumberOfDaysToGet();
	$dateFiltre = time() - (3600 * 24 * $nbjour);

	// $sp = new Stancer_payouts($db);
	try {
		$stancerApi = StancerApi::getInstance();
		// $res = $sp->fetch(null, null, $id);
		$json = $stancerApi->getRefund($id);
		if ($json === false) {
			throw new Exception($stancerApi->error);
		}
		// var_dump($json);exit;

		// $resFill = $sp->fillData($refund);
		// if ($resFill < 0) {
		// 	dol_syslog("stancerRefreshOneRefund early return due to fillData < 0: $resFill");
		// 	return -1;
		// }

		//object exist in database ?
		// if ($res) {
		// 	$res2 = $sp->update($user);
		// } else {
		// 	$res2 = $sp->create($user);
		// }

		$label = "(Refund)";
		//Ajout des virements de compte à compte ...

		// if($id == "pout_W1PpmCVl8CUfiRty3BbHRCVl") {
		// 	print $json['total'];
		// 	print "<br />";
		// 	print json_encode($json);exit;
		// }
		$amount = 0;
		if ($json['amount'] > 0) {
			$amount = $json['amount'] / 100;
		}

		//dateo est un timestamp
		$dateo = isset($json['created']) ? (string) $json['created'] : '';
		$datev = null;
		if (!empty($json['date_bank'])) {
			$datev = (string) $json['date_bank'];
		}

		$paymentID = $json['payment'];
		$payment = new Stancer_payments($db);
		$resPayment = $payment->fetch(0, '', $paymentID);
		if ($resPayment) {
			$companyobj = new Societe($db);
			$resCo = $companyobj->fetch($payment->fk_soc);
			if ($resCo) {
				$label = "Remb. " . $companyobj->name . " / " . $payment->order_id;
			} else {
				dol_syslog("stancer [$id] stancerRefreshOneRefund cannot resolve company for fk_soc=" . $payment->fk_soc, LOG_WARNING);
				$label = "Remb. " . $payment->order_id;
			}
		} else {
			dol_syslog("stancer [$id] stancerRefreshOneRefund cannot find payment $paymentID in local DB", LOG_WARNING);
			$label = "(Refund) " . $id;
		}

		dol_syslog("stancer [$id] stancerRefreshOneRefund date is dateo=$dateo / datev=$datev and amount=$amount");

		if (!empty($dateo) && isset($amount) && $amount > 0) {
			dol_syslog("stancer [$id] stancerRefreshOneRefund add bank line");
			// F4: route through the same fiscal-date lock as the fee/transfer bank
			// helpers, and dedup on the Stancer refund id ALONE (not id+amount) so a
			// re-refresh never books a second line.
			if (stancerIsBankLineDateLocked($dateo)) {
				dol_syslog("stancer [$id] stancerRefreshOneRefund date $dateo is in a locked fiscal period, bank line not added", LOG_WARNING);
			} else {
				$sqlDedup = "SELECT rowid FROM " . MAIN_DB_PREFIX . "bank WHERE num_chq = '" . $db->escape($id) . "' AND fk_account = " . ((int) $accountStancer->id);
				$resDedup = $db->query($sqlDedup);
				if ($resDedup && $db->num_rows($resDedup) > 0) {
					dol_syslog("stancer [$id] stancerRefreshOneRefund duplicate entry (by refund id) for account=" . $accountStancer->id . ", do not add line");
				} else {
					// Account::addline() expects a timestamp. Stancer sends a unix timestamp,
					// but a 'YYYY-MM-DD' string can also reach us, so convert accordingly:
					// a blind (int) cast would turn '2024-05-12' into the year 2024.
					$dateots = is_numeric($dateo) ? (int) $dateo : (int) dol_stringtotime($dateo, 1);
					$bank_line_id_from = $accountStancer->addline($dateots, '', $label, (float) price2num(-1 * $amount), $id, 0, $user);
					if (!($bank_line_id_from > 0)) {
						$error++;
						dol_syslog("stancer [$id] stancerRefreshOneRefund addline failed on account=" . $accountStancer->id . " (date=$dateo, amount=$amount): " . $accountStancer->error, LOG_ERR);
					}
				}
			}

			// erics add new line on bank account for Stancer Fees
			// if ($conf->global->STANCER_ADD_FEES == 'PAYOUT') {
			// 	$fees= $refund->getFees() / 100;
			// 	$ref = $refund->getId();
			// 	$bankaccountid = getDolGlobalString('STANCER_BANK_ACCOUNT_FOR_PAYMENTS');
			// 	dol_syslog("STANCER_ADD_FEES is on each DISPUTE in stancer_payouts_list.php ....");
			// 	$resAddPaiment = stancerAddPaimentFeeOnBank($ref, $fees, $bankaccountid, $refund->getId(), $dateo, $datev);
			// 	if ($resAddPaiment < 0) {
			// 		$output->error = "error on stancerAddPaimentFeeOnBank for " .$ref;
			// 	}
			// }
		} else {
			dol_syslog("stancer [$id] stancerRefreshOneRefund cannot add bank line: dateo=" . json_encode($dateo) . " amount=" . json_encode($amount), LOG_ERR);
			$output->error = "[$id] stancerRefreshOneRefund :: missing date or invalid amount (dateo=" . json_encode($dateo) . ", amount=" . json_encode($amount) . ")";
		}
	} catch (Exception $e) {
		$message = $e->getMessage();
		dol_syslog("stancer [$id] stancerRefreshOneRefund exception (1) occurs for id=$id message=" . $message, LOG_ERR);
		$output->error = "[$id] stancerRefreshOneRefund exception (1) occurs for id=$id message=" . $message;
	}
	return $output;
}

/**
 * Fetch all refunds from Stancer API and update local DB
 *
 * @param  bool        $userMessage  Show event messages to user
 * @param  int|null    $lastrun      Last run timestamp or null
 * @return stdClass    Object with error and message properties
 */
function stancerRefreshAllRefunds($userMessage = true, $lastrun = null)
{
	global $langs, $db, $user, $conf;
	dol_syslog("stancerRefreshAllRefunds, lastrun=$lastrun");
	$stancerApi = new StancerApi();

	$output = new StdClass();
	$output->error = '';
	$output->message = '';

	$mailNotif = false;
	if (getDolGlobalString('STANCER_AUTO_MAIL_NOTIFICATIONS_PAYOUT', '') != '' && getDolGlobalString('STANCER_AUTO_MAIL_NOTIFICATIONS_PAYOUT_DETAILS', '') != '') {
		$mailNotif = true;
	}

	$sr = new Stancer_refunds($db);

	$nbjour = stancerGetNumberOfDaysToGet();
	$dateFiltre = time() - (3600 * 24 * $nbjour);
	if (!empty($lastrun)) {
		$dateFiltre = $lastrun;
	}

	$limit = stancerGetNumberOfItemToGet();
	$start = 0;
	$maxPages = 50;
	$page = 0;
	$counter = 0;

	do {
		$page++;
		dol_syslog("stancerRefreshAllRefunds page=$page, start=$start, limit=$limit");

		$listResult = $stancerApi->listRefunds(['created' => $dateFiltre, 'start' => $start, 'limit' => $limit]);

		if ($listResult === false) {
			dol_syslog("stancerRefreshAllRefunds API error on page $page: " . $stancerApi->error, LOG_ERR);
			$output->error = $stancerApi->error;
			if ($stancerApi->lastHttpCode == 401) {
				setEventMessages($langs->trans('StancerApiAuthError', $stancerApi->error), null, 'errors');
			}
			return $output;
		}

		$refunds = isset($listResult['refunds']) ? $listResult['refunds'] : (is_array($listResult) ? $listResult : []);
		$nbResults = count($refunds);
		dol_syslog("stancerRefreshAllRefunds page=$page got $nbResults results");

		foreach ($refunds as $refundData) {
			$counter++;
			$refundId = isset($refundData['id']) ? $refundData['id'] : '';
			if (empty($refundId)) {
				dol_syslog("stancerRefreshAllRefunds skipping entry with no id", LOG_WARNING);
				continue;
			}

			// Fetch full refund data from API
			$refund = $stancerApi->getRefund($refundId);
			if ($refund === false) {
				dol_syslog("stancerRefreshAllRefunds cannot fetch refund $refundId (HTTP " . $stancerApi->lastHttpCode . "): " . $stancerApi->error, LOG_WARNING);
				if ($stancerApi->lastHttpCode == 401 || $stancerApi->lastHttpCode == 429 || $stancerApi->lastHttpCode >= 500) {
					$output->error = $stancerApi->error;
					dol_syslog("stancerRefreshAllRefunds aborting due to HTTP " . $stancerApi->lastHttpCode, LOG_ERR);
					if ($stancerApi->lastHttpCode == 401) {
						setEventMessages($langs->trans('StancerApiAuthError', $stancerApi->error), null, 'errors');
					} else {
						setEventMessages($langs->trans('StancerApiServerError', $stancerApi->lastHttpCode, $stancerApi->error), null, 'errors');
					}
					return $output;
				}
				continue;
			}

			$sr = new Stancer_refunds($db);
			$res = $sr->fetch(0, null, $refundId);
			$resFill = $sr->fillDataFromApi($refund);
			if ($resFill < 0) {
				dol_syslog("stancerRefreshAllRefunds next loop due to fillDataFromApi < 0: $resFill", LOG_WARNING);
				continue;
			}

			// Resolve fk_soc from related payment
			if (!empty($sr->payment_id) && empty($sr->fk_soc)) {
				$sqlSoc = "SELECT fk_soc FROM " . MAIN_DB_PREFIX . "stancer_stancer_payments";
				$sqlSoc .= " WHERE stancer_id = '" . $db->escape($sr->payment_id) . "'";
				$sqlSoc .= " AND entity = " . ((int) $conf->entity);
				$sqlSoc .= " LIMIT 1";
				$resSoc = $db->query($sqlSoc);
				if ($resSoc) {
					$objSoc = $db->fetch_object($resSoc);
					if ($objSoc && !empty($objSoc->fk_soc)) {
						$sr->fk_soc = (int) $objSoc->fk_soc;
						dol_syslog("stancerRefreshAllRefunds resolved fk_soc=" . $sr->fk_soc . " for refund " . $refundId);
					} else {
						dol_syslog("stancerRefreshAllRefunds no fk_soc found for payment_id=" . $sr->payment_id . " on refund " . $refundId, LOG_WARNING);
					}
				} else {
					dol_syslog("stancerRefreshAllRefunds SQL error resolving fk_soc: " . $db->lasterror(), LOG_ERR);
				}
			}

			if ($res) {
				$sr->update($user);
			} else {
				$sr->create($user);
			}

			// If refund is confirmed, reopen the linked invoice (or invoices for grouped payments)
			if (in_array($sr->status, [1, 2]) && !empty($sr->payment_id)) {
				$reopenRes = stancerReopenInvoiceFromPayment($sr->payment_id, $langs->transnoentitiesnoconv('StancerRefundConfirmedReopenReason', $refundId));
				if (is_array($reopenRes)) {
					$refsList = implode('+', array_map(function ($f) {
						return $f->ref; }, $reopenRes));
					dol_syslog("stancerRefreshAllRefunds " . count($reopenRes) . " invoices ($refsList) reopened for confirmed refund $refundId (grouped payment)");
					// Downstream code expects a single Facture object; pick the first as representative.
					$reopenRes = $reopenRes[0];
				} elseif (is_object($reopenRes)) {
					dol_syslog("stancerRefreshAllRefunds invoice " . $reopenRes->ref . " reopened for confirmed refund $refundId");
				}
			}

			// Send mail notification for new refunds
			if (!$res && $mailNotif && !empty($sr->payment_id)) {
				$refundAmount = price($sr->amount / 100, 0, $langs, 1, -1, -1, $sr->currency);
				$refundTrackid = (isset($reopenRes) && is_object($reopenRes) && !empty($reopenRes->id)) ? 'inv' . $reopenRes->id : (!empty($sr->fk_soc) ? 'thi' . $sr->fk_soc : '');
				stancerSendMail(getDolGlobalString('STANCER_AUTO_MAIL_NOTIFICATIONS_PAYOUT', ''), $langs->transnoentitiesnoconv('StancerMailSubjectNewRefund', $refundId, $refundAmount), $langs->transnoentitiesnoconv('StancerMailNewRefund', $refundId, $refundAmount, $sr->payment_id), false, '', $refundTrackid);
			}
		}

		$start += $limit;
	} while ($nbResults >= $limit && $page < $maxPages);

	if ($page >= $maxPages) {
		dol_syslog("stancerRefreshAllRefunds reached max pages ($maxPages), some refunds may not have been synced", LOG_WARNING);
	}

	dol_syslog("stancerRefreshAllRefunds done: $counter refunds processed in $page pages");
	if ($userMessage && $counter > 0) {
		setEventMessages($langs->trans('StancerRefreshDone', $counter), null, 'mesgs');
	}

	return $output;
}


/**
 * Fetch all disputes from Stancer API and update local DB
 *
 * @param  bool        $userMessage  Show event messages to user
 * @param  int|null    $lastrun      Last run timestamp or null
 * @return stdClass    Object with error and message properties
 */
function stancerRefreshAllDisputes($userMessage = true, $lastrun = null)
{
	global $langs, $db, $user, $conf;
	dol_syslog("stancerRefreshAllDisputes, lastrun=$lastrun");

	dol_include_once('/stancer/class/stancer_disputes.class.php');
	$stancerApi = new StancerApi();

	$output = new StdClass();
	$output->error = '';
	$output->message = '';

	$mailNotif = false;
	if (getDolGlobalString('STANCER_AUTO_MAIL_NOTIFICATIONS_PAYOUT', '') != '' && getDolGlobalString('STANCER_AUTO_MAIL_NOTIFICATIONS_PAYOUT_DETAILS', '') != '') {
		$mailNotif = true;
	}

	$nbjour = stancerGetNumberOfDaysToGet();
	$dateFiltre = time() - (3600 * 24 * $nbjour);
	if (!empty($lastrun)) {
		$dateFiltre = $lastrun;
	}

	$limit = stancerGetNumberOfItemToGet();
	$start = 0;
	$maxPages = 50;
	$page = 0;
	$counter = 0;

	do {
		$page++;
		dol_syslog("stancerRefreshAllDisputes page=$page, start=$start, limit=$limit");

		$listResult = $stancerApi->listDisputes(['created' => $dateFiltre, 'start' => $start, 'limit' => $limit]);

		if ($listResult === false) {
			dol_syslog("stancerRefreshAllDisputes API error on page $page: " . $stancerApi->error, LOG_ERR);
			$output->error = $stancerApi->error;
			if ($stancerApi->lastHttpCode == 401) {
				setEventMessages($langs->trans('StancerApiAuthError', $stancerApi->error), null, 'errors');
			}
			return $output;
		}

		$disputes = isset($listResult['disputes']) ? $listResult['disputes'] : (is_array($listResult) ? $listResult : []);
		$nbResults = count($disputes);
		dol_syslog("stancerRefreshAllDisputes page=$page got $nbResults results");

		foreach ($disputes as $disputeData) {
			$counter++;
			$disputeId = isset($disputeData['id']) ? $disputeData['id'] : '';
			if (empty($disputeId)) {
				dol_syslog("stancerRefreshAllDisputes skipping entry with no id", LOG_WARNING);
				continue;
			}

			// Fetch full dispute data from API
			$dispute = $stancerApi->getDispute($disputeId);
			if ($dispute === false) {
				dol_syslog("stancerRefreshAllDisputes cannot fetch dispute $disputeId (HTTP " . $stancerApi->lastHttpCode . "): " . $stancerApi->error, LOG_WARNING);
				if ($stancerApi->lastHttpCode == 401 || $stancerApi->lastHttpCode == 429 || $stancerApi->lastHttpCode >= 500) {
					$output->error = $stancerApi->error;
					dol_syslog("stancerRefreshAllDisputes aborting due to HTTP " . $stancerApi->lastHttpCode, LOG_ERR);
					if ($stancerApi->lastHttpCode == 401) {
						setEventMessages($langs->trans('StancerApiAuthError', $stancerApi->error), null, 'errors');
					} else {
						setEventMessages($langs->trans('StancerApiServerError', $stancerApi->lastHttpCode, $stancerApi->error), null, 'errors');
					}
					return $output;
				}
				continue;
			}

			$sd = new Stancer_disputes($db);
			$res = $sd->fetch(0, '', $disputeId);
			$oldStatus = $res ? $sd->status : '';
			$resFill = $sd->fillDataFromApi($dispute);
			if ($resFill < 0) {
				dol_syslog("stancerRefreshAllDisputes next loop due to fillDataFromApi < 0: $resFill", LOG_WARNING);
				continue;
			}

			// Resolve fk_soc from related payment
			if (!empty($sd->payment_id) && empty($sd->fk_soc)) {
				$sqlSoc = "SELECT fk_soc FROM " . MAIN_DB_PREFIX . "stancer_stancer_payments";
				$sqlSoc .= " WHERE stancer_id = '" . $db->escape($sd->payment_id) . "'";
				$sqlSoc .= " AND entity = " . ((int) $conf->entity);
				$sqlSoc .= " LIMIT 1";
				$resSoc = $db->query($sqlSoc);
				if ($resSoc) {
					$objSoc = $db->fetch_object($resSoc);
					if ($objSoc && !empty($objSoc->fk_soc)) {
						$sd->fk_soc = (int) $objSoc->fk_soc;
						dol_syslog("stancerRefreshAllDisputes resolved fk_soc=" . $sd->fk_soc . " for dispute " . $disputeId);
					} else {
						dol_syslog("stancerRefreshAllDisputes no fk_soc found for payment_id=" . $sd->payment_id . " on dispute " . $disputeId, LOG_WARNING);
					}
				} else {
					dol_syslog("stancerRefreshAllDisputes SQL error resolving fk_soc: " . $db->lasterror(), LOG_ERR);
				}
			}

			if ($res) {
				$sd->update($user);
			} else {
				$sd->create($user);
			}

			// Statuses meaning the money is lost (dispute won by customer or not contestable)
			$lostStatuses = array('lost', 'accepted', 'out_of_time', 'not_contestable');
			$isLost = in_array($sd->status, $lostStatuses);
			$justBecameLost = $isLost && !in_array($oldStatus, $lostStatuses);

			// Reopen invoice + send notifications only once per dispute (ActionComm deduplication)
			if ($isLost && !empty($sd->payment_id)) {
				$actionCodeReopen = 'DISPUTE_REOPEN_' . $disputeId;
				$actioncommCheck = new ActionComm($db);
				// The module requires Dolibarr 15 or above, where getActions() no longer takes the database handler.
				$existingReopen = $actioncommCheck->getActions(0, 0, '', " AND code='AC_" . $db->escape($actionCodeReopen) . "'");

				if (empty($existingReopen)) {
					// Reopen invoice (returns Facture for solo, array<Facture> for grouped, 0 or -1 on failure)
					$reopenRes = stancerReopenInvoiceFromPayment($sd->payment_id, $langs->transnoentitiesnoconv('StancerDisputeLostReopenReason', $disputeId));
					if (is_array($reopenRes)) {
						$refsList = implode('+', array_map(function ($f) {
							return $f->ref; }, $reopenRes));
						dol_syslog("stancerRefreshAllDisputes " . count($reopenRes) . " invoices ($refsList) reopened for lost dispute $disputeId (status=" . $sd->status . ", grouped payment)");
					} elseif (is_object($reopenRes)) {
						dol_syslog("stancerRefreshAllDisputes invoice " . $reopenRes->ref . " reopened for lost dispute $disputeId (status=" . $sd->status . ")");
					} elseif ($reopenRes == 0) {
						dol_syslog("stancerRefreshAllDisputes dispute $disputeId: no invoice found or already unpaid for payment " . $sd->payment_id, LOG_WARNING);
					} else {
						dol_syslog("stancerRefreshAllDisputes dispute $disputeId: error reopening invoice for payment " . $sd->payment_id, LOG_ERR);
					}

					// Record ActionComm on each reopened invoice (or fallback to payment object).
					$actionTargets = array();
					if (is_array($reopenRes)) {
						$actionTargets = $reopenRes;
					} elseif (is_object($reopenRes)) {
						$actionTargets = array($reopenRes);
					}
					if (count($actionTargets) === 0) {
						$fallback = new Stancer_payments($db);
						$fallback->fetch(0, '', $sd->payment_id);
						$actionTargets[] = $fallback;
					}
					foreach ($actionTargets as $actionObject) {
						stancerAddActionComm($actionObject, $actionCodeReopen, $langs->transnoentitiesnoconv('StancerDisputeLostReopenReason', $disputeId), 'Dispute ' . $disputeId . ' status=' . $sd->status, array(), '');
					}
					// For downstream code below (mail template, fee invoice) we use a single representative
					// Facture. On grouped payments we pick the first invoice of the group: the fee invoice
					// is created once for the whole group, and the customer email lists the group refs via the template.
					if (is_array($reopenRes) && count($reopenRes) > 0) {
						$reopenRes = $reopenRes[0];
					}

					// Admin mail notification
					if ($mailNotif) {
						$disputeAmount = price($sd->amount / 100, 0, $langs, 1, -1, -1, dol_strtoupper($sd->currency));
						$clientName = '?';
						if (!empty($sd->fk_soc)) {
							$socForMail = new Societe($db);
							if ($socForMail->fetch($sd->fk_soc) > 0) {
								$clientName = $socForMail->name;
							}
						}
						$invoiceRef = '?';
						$invoiceRefWithLink = '?';
						if (is_object($reopenRes)) {
							$invoiceRef = $reopenRes->ref;
							$invoiceUrl = getDolGlobalString('MAIN_URL_ROOT', '') . '/compta/facture/card.php?facid=' . $reopenRes->id;
							$invoiceRefWithLink = '<a href="' . $invoiceUrl . '">' . $invoiceRef . '</a>';
						}
						$sepaCode = !empty($sd->response) ? $sd->response : '-';
						$disputeTrackid = is_object($reopenRes) ? 'inv' . $reopenRes->id : (!empty($sd->fk_soc) ? 'thi' . $sd->fk_soc : '');
						stancerSendMail(
							getDolGlobalString('STANCER_AUTO_MAIL_NOTIFICATIONS_PAYOUT', ''),
							$langs->transnoentitiesnoconv('StancerMailSubjectNewDispute', $sd->dispute_type, $invoiceRef, $clientName, $disputeAmount),
							// transnoentitiesnoconv() sprintf's over five parameters at most, so the
							// two remaining fields live in their own key.
							$langs->transnoentitiesnoconv('StancerMailNewDispute', $clientName, $invoiceRefWithLink, $disputeAmount, $sd->dispute_type, $sd->status)
								. $langs->transnoentitiesnoconv('StancerMailNewDisputeDetails', $sepaCode, $disputeId),
							false, '', $disputeTrackid
						);
					}

					// SEPA rejection: customer email via template + fee invoice
					if (!empty($sd->response) && !empty($sd->fk_soc)) {
						// Customer email: only if invoice was found and reopened (we need the Facture object for the template)
						if (is_object($reopenRes) && getDolGlobalString('STANCER_AUTO_MAIL_INVOICES_ERROR', '') != '') {
							$ccEmail = getDolGlobalString('STANCER_EMAIL_INFO_SEPA', '');
							$mailRes = stancerSendInvoiceMailModele(
								getDolGlobalString('STANCER_AUTO_MAIL_INVOICES_ERROR', ''),
								$reopenRes,
								'BILL_SEPADISPUTE_REJECT_SENTBYMAIL',
								0,
								true,
								$ccEmail
							);
							if ($mailRes > 0) {
								dol_syslog("stancerRefreshAllDisputes dispute $disputeId SEPA rejection template email sent for invoice " . $reopenRes->ref . " (code " . $sd->response . ")", LOG_INFO);
							} elseif ($mailRes === null || $mailRes === 0) {
								// F7: null/0 is a skip (dedup or empty from/to recipient), not a failure.
								dol_syslog("stancerRefreshAllDisputes dispute $disputeId SEPA rejection template email skipped for invoice " . $reopenRes->ref, LOG_DEBUG);
							} else {
								dol_syslog("stancerRefreshAllDisputes dispute $disputeId SEPA rejection template email failed for invoice " . $reopenRes->ref . ": result=$mailRes", LOG_ERR);
							}
						} elseif (!is_object($reopenRes)) {
							dol_syslog("stancerRefreshAllDisputes dispute $disputeId cannot send customer template email: no invoice found for payment " . $sd->payment_id, LOG_WARNING);
						} else {
							dol_syslog("stancerRefreshAllDisputes dispute $disputeId STANCER_AUTO_MAIL_INVOICES_ERROR not configured, skip customer email", LOG_DEBUG);
						}

						// Auto-create rejection fee invoice if enabled
						if (getDolGlobalString('STANCER_SEPA_REJECTION_FEE_AUTO_APPLY')) {
							$invoiceRef = is_object($reopenRes) ? $reopenRes->ref : '';
							$feeResult = stancerCreateRejectionFeeInvoice($sd->fk_soc, $sd->response, $invoiceRef);
							if (is_object($feeResult)) {
								dol_syslog("stancerRefreshAllDisputes dispute $disputeId rejection fee invoice created: " . $feeResult->ref, LOG_INFO);
							} else {
								dol_syslog("stancerRefreshAllDisputes dispute $disputeId rejection fee invoice error: " . $feeResult, LOG_ERR);
							}
						}
					}
				} else {
					dol_syslog("stancerRefreshAllDisputes dispute $disputeId already processed (ActionComm $actionCodeReopen exists), skip reopen/mail/fee", LOG_DEBUG);
				}
			}

			// Admin mail for new disputes that are NOT yet lost (informational)
			if (!$res && !$isLost && $mailNotif && !empty($sd->payment_id)) {
				$disputeAmount = price($sd->amount / 100, 0, $langs, 1, -1, -1, dol_strtoupper($sd->currency));
				$clientName = '?';
				if (!empty($sd->fk_soc)) {
					$socForMail = new Societe($db);
					if ($socForMail->fetch($sd->fk_soc) > 0) {
						$clientName = $socForMail->name;
					}
				}
				// Try to find invoice ref from Dolibarr payment
				$invoiceRef = '?';
				$invoiceRefWithLink = '?';
				$sqlInvLookup = "SELECT f.rowid, f.ref FROM " . MAIN_DB_PREFIX . "paiement AS p";
				$sqlInvLookup .= " INNER JOIN " . MAIN_DB_PREFIX . "paiement_facture AS pf ON pf.fk_paiement = p.rowid";
				$sqlInvLookup .= " INNER JOIN " . MAIN_DB_PREFIX . "facture AS f ON f.rowid = pf.fk_facture";
				$sqlInvLookup .= " WHERE p.num_paiement = '" . $db->escape($sd->payment_id) . "'";
				$sqlInvLookup .= " LIMIT 1";
				$resInvLookup = $db->query($sqlInvLookup);
				if ($resInvLookup) {
					$objInvLookup = $db->fetch_object($resInvLookup);
					if ($objInvLookup && !empty($objInvLookup->ref)) {
						$invoiceRef = $objInvLookup->ref;
						$invoiceUrl = getDolGlobalString('MAIN_URL_ROOT', '') . '/compta/facture/card.php?facid=' . $objInvLookup->rowid;
						$invoiceRefWithLink = '<a href="' . $invoiceUrl . '">' . $invoiceRef . '</a>';
					}
				}
				$sepaCode = !empty($sd->response) ? $sd->response : '-';
				$disputeTrackid = (!empty($objInvLookup) && !empty($objInvLookup->rowid)) ? 'inv' . $objInvLookup->rowid : (!empty($sd->fk_soc) ? 'thi' . $sd->fk_soc : '');
				stancerSendMail(
					getDolGlobalString('STANCER_AUTO_MAIL_NOTIFICATIONS_PAYOUT', ''),
					$langs->transnoentitiesnoconv('StancerMailSubjectNewDispute', $sd->dispute_type, $invoiceRef, $clientName, $disputeAmount),
					// transnoentitiesnoconv() sprintf's over five parameters at most, so the
					// two remaining fields live in their own key.
					$langs->transnoentitiesnoconv('StancerMailNewDispute', $clientName, $invoiceRefWithLink, $disputeAmount, $sd->dispute_type, $sd->status)
						. $langs->transnoentitiesnoconv('StancerMailNewDisputeDetails', $sepaCode, $disputeId),
					false, '', $disputeTrackid
				);
			}
		}

		$start += $limit;
	} while ($nbResults >= $limit && $page < $maxPages);

	if ($page >= $maxPages) {
		dol_syslog("stancerRefreshAllDisputes reached max pages ($maxPages), some disputes may not have been synced", LOG_WARNING);
	}

	dol_syslog("stancerRefreshAllDisputes done: $counter disputes processed in $page pages");
	if ($userMessage && $counter > 0) {
		setEventMessages($langs->trans('StancerRefreshDone', $counter), null, 'mesgs');
	}

	return $output;
}


/**
 * Create a refund on Stancer for a given payment
 *
 * @param  string  $paymentStancerId  Stancer payment ID (paym_xxx)
 * @param  int       $amount            Amount to refund in cents (null = full refund)
 * @return stdClass  Object with error and message properties, and refund_id on success
 */
function stancerCreateRefund($paymentStancerId, $amount = null)
{
	global $langs, $db, $user, $conf;

	dol_syslog("stancerCreateRefund paymentStancerId=$paymentStancerId, amount=$amount");

	$output = new StdClass();
	$output->error = '';
	$output->message = '';
	$output->refund_id = '';

	if (empty($paymentStancerId)) {
		$output->error = "stancerCreateRefund: paymentStancerId is required";
		dol_syslog($output->error, LOG_ERR);
		return $output;
	}

	try {
		$stancerApi = StancerApi::getInstance();

		// Get the payment from Stancer
		$paymentData = $stancerApi->getPayment($paymentStancerId);
		if ($paymentData === false) {
			throw new Exception($stancerApi->error);
		}

		// Check payment status - can only refund captured payments
		$status = isset($paymentData['status']) ? $paymentData['status'] : '';
		if ($status !== 'captured') {
			$output->error = "stancerCreateRefund: payment status is '$status', can only refund 'captured' payments";
			dol_syslog($output->error, LOG_ERR);
			return $output;
		}

		// Determine refund amount
		if (!empty($amount) && $amount > 0) {
			// Partial refund
			$refundAmount = $amount;
			dol_syslog("stancerCreateRefund: partial refund of $amount cents");
		} else {
			// Full refund - use payment amount
			$refundAmount = $paymentData['amount'];
			dol_syslog("stancerCreateRefund: full refund of " . $paymentData['amount'] . " cents");
		}

		// Create the refund via API
		$refundData = $stancerApi->createRefund(array(
			'payment' => $paymentStancerId,
			'amount' => $refundAmount
		));
		if ($refundData === false) {
			throw new Exception($stancerApi->error);
		}

		$refundId = $refundData['id'];
		$output->refund_id = $refundId;
		$output->message = "Refund created successfully: $refundId";
		dol_syslog($output->message);

		// Save refund in local database
		$localRefund = new Stancer_refunds($db);
		$localRefund->refund_id = $refundId;
		$localRefund->payment_id = $paymentStancerId;
		$localRefund->amount = $refundAmount;
		$localRefund->currency = isset($paymentData['currency']) ? $paymentData['currency'] : 'eur';
		$localRefund->status = Stancer_refunds::STATUS_VALIDATED;
		$localRefund->live_mode = getDolGlobalString('STANCER_IS_PROD', '0');
		$localRefund->created = dol_now();

		// Try to get fk_soc from local payment record
		$localPayment = new Stancer_payments($db);
		$resPayment = $localPayment->fetch(0, '', $paymentStancerId);
		if ($resPayment > 0 && !empty($localPayment->fk_soc)) {
			$localRefund->fk_soc = $localPayment->fk_soc;
		}

		$resCreate = $localRefund->create($user);
		if ($resCreate < 0) {
			dol_syslog("stancerCreateRefund: warning, could not save refund locally: " . $localRefund->error, LOG_WARNING);
		}
	} catch (Exception $e) {
		$message = $e->getMessage();
		dol_syslog("stancerCreateRefund exception: $message", LOG_ERR);
		$output->error = "stancerCreateRefund exception: $message";
	}

	return $output;
}

/**
 * Create a refund for a Dolibarr payment record
 *
 * @param  int       $paymentRowId  Local payment rowid in llx_stancer_stancer_payments
 * @param  int       $amount        Amount to refund in cents (null = full refund)
 * @return stdClass  Object with error and message properties
 */
function stancerCreateRefundFromPaymentId($paymentRowId, $amount = null)
{
	global $db;

	dol_syslog("stancerCreateRefundFromPaymentId paymentRowId=$paymentRowId, amount=$amount");

	$output = new StdClass();
	$output->error = '';
	$output->message = '';
	$output->refund_id = '';

	$payment = new Stancer_payments($db);
	$res = $payment->fetch($paymentRowId);

	if ($res <= 0) {
		$output->error = "stancerCreateRefundFromPaymentId: payment not found with rowid=$paymentRowId";
		dol_syslog($output->error, LOG_ERR);
		return $output;
	}

	if (empty($payment->stancer_id)) {
		$output->error = "stancerCreateRefundFromPaymentId: payment has no stancer_id";
		dol_syslog($output->error, LOG_ERR);
		return $output;
	}

	return stancerCreateRefund($payment->stancer_id, $amount);
}

/**
 * Create invoice from propal
 *
 * @param DoliDB    $db         Database handler
 * @param User      $user       User object
 * @param int       $propal_id  ID du devis source
 * @param array     $options    Options (date_facture, type, deposit_percent)
 * @return string|Facture facture dolibarr ou message d'erreur
 */
function stancerCreateInvoiceFromPropal($db, $user, $propal_id, $options = [])
{
	// Load propal
	$propal = new Propal($db);
	$result = $propal->fetch($propal_id);
	if ($result <= 0) {
		dol_syslog("stancerCreateInvoiceFromPropal : propal unknown !", LOG_WARNING);
		return 'Erreur: Devis introuvable (ID: ' . $propal_id . ')';
	}
	$propal->fetch_lines();
	if (empty($propal->lines)) {
		dol_syslog("stancerCreateInvoiceFromPropal : propal with no lines !", LOG_WARNING);
		return 'Erreur: Le devis n\'a aucune ligne';
	}

	// Créer la facture
	$facture = new Facture($db);
	$facture->socid = $propal->socid;
	$facture->type = isset($options['type']) ? $options['type'] : Facture::TYPE_STANDARD;
	$facture->date = isset($options['date_facture']) ? $options['date_facture'] : dol_now();
	$facture->cond_reglement_id = $propal->cond_reglement_id;
	$facture->mode_reglement_id = $propal->mode_reglement_id;
	$facture->fk_project = $propal->fk_project;
	// Checked on the real sources (facture.class.php 18.0.8): create() inserts $ref_customer
	// when it is set and falls back on $ref_client, which is also the property the core itself
	// fills when it builds an invoice from another document. Feeding $ref_client stays right
	// on Dolibarr 15..21. The cast keeps trim() from receiving null on a propal without ref.
	// @phan-suppress-next-line PhanDeprecatedProperty
	$facture->ref_client = (string) $propal->ref_client;
	$facture->note_private = $propal->note_private;
	$facture->note_public = $propal->note_public;
	$facture->model_pdf = $propal->model_pdf;
	$facture->fk_account = $propal->fk_account;

	// Link to the source proposal
	// $origin_type does not exist on CommonObject before Dolibarr 19, so $origin is the
	// only property that records the source element on the whole supported range.
	// @phan-suppress-next-line PhanDeprecatedProperty
	$facture->origin = 'propal';
	$facture->origin_id = $propal->id;

	// Multidevise
	if (!empty($propal->multicurrency_code)) {
		$facture->multicurrency_code = $propal->multicurrency_code;
		$facture->multicurrency_tx = $propal->multicurrency_tx;
	}

	$db->begin();

	// Créer l'entête de la facture
	$facture_id = $facture->create($user);
	if ($facture_id <= 0) {
		$db->rollback();
		dol_syslog("stancerCreateInvoiceFromPropal : error creating invoice !", LOG_WARNING);
		return 'Erreur création facture: ' . implode(', ', $facture->errors);
	}

	// Add the lines
	foreach ($propal->lines as $line) {
		// Skip lines with a null quantity
		if (empty($line->qty)) {
			dol_syslog("stancerCreateInvoiceFromPropal : line with quantity = 0, next");
			continue;
		}

		$result = $facture->addline(
			$line->desc,
			$line->subprice,
			$line->qty,
			$line->tva_tx,
			$line->localtax1_tx,
			$line->localtax2_tx,
			$line->fk_product,
			$line->remise_percent,
			$line->date_start,
			$line->date_end,
			0,                              // ventil
			$line->info_bits,
			$line->fk_remise_except,
			'HT',
			0,                              // pu_ttc
			$line->product_type,
			$line->rang,
			$line->special_code,
			'propal',                       // origin
			$line->id,                      // origin_id (ligne du devis)
			0,                              // fk_parent_line
			$line->fk_fournprice,
			$line->pa_ht,
			// Propal::fetch_lines() fills $label from the custom_label column on Dolibarr 15..21
			// and no other property carries that text.
			// @phan-suppress-next-line PhanDeprecatedProperty
			$line->label,
			$line->array_options,
			100,                            // situation_percent
			0,                              // fk_prev_id
			$line->fk_unit,
			$line->multicurrency_subprice
		);

		if ($result < 0) {
			$db->rollback();
			dol_syslog("stancerCreateInvoiceFromPropal : error adding line, rollback ! " . json_encode($facture->errors), LOG_WARNING);
			return 'Erreur ajout ligne: ' . implode(', ', $facture->errors);
		}
	}

	// Créer le lien dans element_element
	$facture->add_object_linked('propal', $propal->id);

	$db->commit();

	return $facture;
}
