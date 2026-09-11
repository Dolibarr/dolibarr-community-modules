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
 * \file    stancer/lib/stancer_payment.lib.php
 * \ingroup stancer
 * \brief   Payment start functions (CB, SEPA, tags, filters)
 */

/**
 * Resolve the Stancer payment id the return page must work on.
 *
 * The session is not a reliable source here: with STANCER_AUTO_MAIL_ORDER_CB the
 * payment link is emailed to the customer, who opens it straight from the
 * mailbox without ever loading a Dolibarr page, so $_SESSION is empty on that
 * browser. The local row loaded from the return tag already carries the paym_
 * id, and it is the same id in every case, so it comes first.
 *
 * @param   Stancer_payments|null  $localPayment      Local row loaded from the return tag
 * @param   string                 $sessionPaymentId  Payment id kept in session, may be empty
 * @return  string                                    Payment id, empty string when none is known
 */
function stancerResolveReturnPaymentId($localPayment, $sessionPaymentId = '')
{
	if (is_object($localPayment) && !empty($localPayment->stancer_id)) {
		return (string) $localPayment->stancer_id;
	}

	return trim((string) $sessionPaymentId);
}

/**
 * Resolve the currency code of a payment coming back from Stancer.
 *
 * Same session-less scenario as stancerResolveReturnPaymentId(): an empty
 * currency makes the return page compare '' with the company currency, take the
 * multicurrency path and refuse to record the payment on the order, donation and
 * event branches. The currency really charged by Stancer is the reference, the
 * local row is the fallback, and the company currency closes the list so that a
 * comparison against $conf->currency can never fail on an empty value.
 *
 * @param   string                 $sessionCurrency  Currency kept in session, may be empty
 * @param   array|false            $paymentData      Payment as returned by the Stancer API, false when not fetched
 * @param   Stancer_payments|null  $localPayment     Local row loaded from the return tag
 * @param   string                 $companyCurrency  Currency of the company ($conf->currency)
 * @return  string                                   Upper case currency code
 */
function stancerResolveReturnCurrency($sessionCurrency, $paymentData, $localPayment, $companyCurrency)
{
	if (trim((string) $sessionCurrency) !== '') {
		return strtoupper(trim((string) $sessionCurrency));
	}
	if (is_array($paymentData) && !empty($paymentData['currency'])) {
		return strtoupper((string) $paymentData['currency']);
	}
	if (is_object($localPayment) && !empty($localPayment->currency)) {
		return strtoupper((string) $localPayment->currency);
	}

	dol_syslog("stancerResolveReturnCurrency no currency in session, on the Stancer payment nor on the local row, falling back to the company currency " . $companyCurrency, LOG_WARNING);

	return strtoupper((string) $companyCurrency);
}

/**
 * Resolve, on the return page, whether the payment coming back only covered a
 * deposit.
 *
 * The row written when the payment started carries the answer, so it wins: the
 * session that used to hold it does not exist when the customer opens the
 * payment link from an email, and a deposit read as a full payment gets billed
 * as a full invoice. The session is kept as a fallback for the rows created
 * before the partial_payment column existed.
 *
 * @param   Stancer_payments|null  $localPayment          Local row loaded from the return tag
 * @param   int|string|null        $sessionPartialPayment Value kept in session, null when absent
 * @return  int                                           1 for a deposit payment, 0 otherwise
 */
function stancerResolveReturnPartialPayment($localPayment, $sessionPartialPayment = null)
{
	if (is_object($localPayment) && $localPayment->partial_payment !== null && $localPayment->partial_payment !== '') {
		return ((int) $localPayment->partial_payment === 1) ? 1 : 0;
	}

	return ((int) $sessionPartialPayment === 1) ? 1 : 0;
}

/**
 * Tell whether a payment started on this object only covers its deposit.
 *
 * This is the "30% on order" case: STANCER_CB_ORDER_PARTIAL_PAY (or its proposal
 * counterpart STANCER_CB_PROPAL_PARTIAL_PAY) is set and the document carries a
 * deposit percentage. The answer is stored on the payment row so that the return
 * page still knows it when the customer opened the payment link from an email,
 * where $_SESSION["partialPayment"] does not exist and a deposit would otherwise
 * be billed as a full payment.
 *
 * @param   CommonObject|null  $object  Order or proposal being paid
 * @return  int                         1 for a deposit payment, 0 otherwise
 */
function stancerIsDepositPayment($object)
{
	if (!is_object($object) || empty($object->element)) {
		return 0;
	}
	// Same test as newpayment.php and paymentback.php: a percentage outside ]0;100[
	// is not a deposit, it is the whole document.
	if (!isset($object->deposit_percent) || $object->deposit_percent <= 0 || $object->deposit_percent >= 100) {
		return 0;
	}
	if ($object->element == 'commande' && getDolGlobalString('STANCER_CB_ORDER_PARTIAL_PAY') != '') {
		return 1;
	}
	if ($object->element == 'propal' && getDolGlobalString('STANCER_CB_PROPAL_PARTIAL_PAY') != '') {
		return 1;
	}

	return 0;
}

/**
 * common check before starting payment process
 *
 * @param   CommonObject  $object  object
 *
 * @return  int           result
 */
function stancerCommonFilterBeforePay($object)
{
	global $db, $langs;
	// Signature must stay generic (the hook layer only knows CommonObject), but the
	// concrete classes that reach this code all declare the fields used below.
	'@phan-var Facture|Commande|Propal|Adherent|Don $object';
	dol_syslog("stancerCommonFilterBeforePay", LOG_DEBUG);

	$listofHandledElements = ['facture', 'commande', 'invoice', 'order', 'don', 'member', 'propal'];

	// print json_encode($object); exit;
	if (!in_array($object->element, $listofHandledElements)) {
		dol_syslog("stancerCommonFilterBeforePay object is not an invoice or an order (element != facture | commande), current type of object is " . $object->element, LOG_DEBUG);
		$message = $langs->trans("Payment object is not an invoice or an order");
		setEventMessages($langs->trans("ErrorStancer") . " (8) " . $message, [], 'errors');
		return -1;
	}
	if ($object->element == 'facture') {
		// @phan-suppress-next-line PhanDeprecatedProperty  $paye is the column Dolibarr 15..21 fills and still writes; status==2 also covers abandoned invoices
		if ($object->paye != '0') {
			// @phan-suppress-next-line PhanDeprecatedProperty  same reason as above, kept for the log line
			dol_syslog("stancerCommonFilterBeforePay invoice status paye is not 0, paye=" . $object->paye, LOG_DEBUG);
			$message = $langs->trans("Payment object is already paid");
			setEventMessages($langs->trans("ErrorStancer") . " (9) " . $message, [], 'errors');
			return -2;
		}
		//disabled
		// $account = new Account($db);
		// if ($account->fetch($object->fk_account) > 0) {
		// 	//Only in case of automatic payments ?
		// 	// if ($account->ref != 'STANCER') {
		// 	// 	dol_syslog("stancerCommonFilterBeforePay invoice payment destination account is not STANCER", LOG_DEBUG);
		// 	// 	$message = $langs->trans("Payment bank account target for that object is not Stancer");
		// 	// 	setEventMessages($langs->trans("ErrorStancer"), $message, 'errors');
		// 	// 	return -3;
		// 	// }
		// } else {
		// 	dol_syslog("stancerCommonFilterBeforePay invoice payment destination account is not defined, please choose STANCER", LOG_DEBUG);
		// 	$message = $langs->trans("Payment bank account target for that object is not Stancer");
		// 	setEventMessages($langs->trans("ErrorStancer"), $message, 'errors');
		// 	return -3;
		// }
	} elseif ($object->element == 'commande') {
		if ($object->status != Commande::STATUS_VALIDATED) {
			dol_syslog("stancerCommonFilterBeforePay order status not validated : " . $object->status, LOG_DEBUG);
			$message = $langs->trans("Payment order is not validated / ready for pay");
			setEventMessages($langs->trans("ErrorStancer") . " (10) " . $message, [], 'errors');
			return -4;
		}
	}

	$customerID = stancerAddCustomerIfNeeded($object);
	if (empty($customerID) || (is_numeric($customerID) && $customerID <= 0)) {
		dol_syslog("stancerAddCBIfNeeded error on stancerAddCustomerIfNeeded, customerID=" . var_export($customerID, true), LOG_ERR);
		return -3;
	}

	return 0;
}

/**
 * a payment with Card
 *
 * @param   CommonObject  $object              Invoice or order to pay
 * @param   array         $parameters          Hook parameters, forwarded by the caller
 * @param   float|null    $forceAmount         Amount to charge, object remain to pay when null
 * @param   bool          $forceJsRedirect     Redirect with javascript instead of an HTTP header
 * @param   bool|null     $sendMailToCustomer  Send the payment link by mail, module setting when null
 * @return  int                                result
 */
function stancerCardstartPayWithRedirect($object, $parameters, $forceAmount = null, $forceJsRedirect = false, $sendMailToCustomer = null)
{
	global $db, $conf, $user, $langs, $mysoc;
	// Signature must stay generic (the hook layer only knows CommonObject), but the
	// concrete classes that reach this code all declare the fields used below.
	'@phan-var Facture|Commande|Propal|Adherent|Don $object';
	dol_syslog("stancerCardstartPayWithRedirect");
	$error = 0;
	$stancerApi = new StancerApi();

	//sendMailToCustomer peut être forcé à true / false mais si null alors on applique la conf par défaut
	if (null === $sendMailToCustomer) {
		$sendMailToCustomer = getDolGlobalString('STANCER_AUTO_MAIL_ORDER_CB');
	}

	$data = array();
	$res = stancerCommonFilterBeforePay($object);
	if ($res < 0) {
		dol_syslog("stancerCardstartPayWithRedirect : stancerCommonFilterBeforePay returns $res then return too");
		return $res;
	}
	// if ($object->mode_reglement_code != 'CB') {
	// 	$message = $langs->trans("Payment mode is not CB");
	// 	setEventMessages($langs->trans("ErrorStancer"), $message, 'errors');
	// 	dol_syslog("stancerCardstartPayWithRedirect payment mode is not set to CB", LOG_DEBUG);
	// 	return -4;
	// }

	if (!empty($forceAmount)) {
		dol_syslog("stancerCardstartPayWithRedirect : use $forceAmount");
		$amountToPay = StancerApi::toCents($forceAmount);
	} else {
		//facture peut avoir été partiellement payée
		if ($object->element == "facture") {
			$totalpaid = $object->getSommePaiement();
			$totalcreditnotes = $object->getSumCreditNotesUsed();
			$totaldeposits = $object->getSumDepositsUsed();
			$amountToPay = StancerApi::toCents(price2num($object->total_ttc - $totalpaid - $totalcreditnotes - $totaldeposits, 'MT'));
			dol_syslog("stancerCardstartPayWithRedirect : object is invoice, amount = $amountToPay");
		} else {
			$amountToPay = StancerApi::toCents(price2num($object->total_ttc ?? $object->amount, 'MT'));
			//race condition for order
			if (
				$object->element == "commande"
				&& getDolGlobalString('STANCER_CB_ORDER_PARTIAL_PAY') != ''
				&& isset($object->deposit_percent) && $object->deposit_percent > 0 && $object->deposit_percent < 100
			) {
				$amountToPay = StancerApi::toCents((float) price2num($object->total_ttc ?? $object->amount, 'MT') * ((float) $object->deposit_percent / 100));
				dol_syslog("stancerCardstartPayWithRedirect : partial Amount $amountToPay");
			}
		}
	}

	if (empty($amountToPay)) {
		dol_syslog("stancerCardstartPayWithRedirect : amount is empty, use post data");
		$postAmount = GETPOST('amount', 'int');
		if (!empty($postAmount)) {
			$amountToPay = StancerApi::toCents($postAmount);
		}
	}

	if (empty($amountToPay) || $amountToPay < 50) {
		// print "<p>Amount to pay error : $amountToPay</p>";
		// print json_encode($object);
		dol_syslog("stancerCardstartPayWithRedirect, error, amount to pay is less than 50¢", LOG_ERR);
		return -10;
	}

	$customerID = stancerAddCustomerIfNeeded($object);
	if (empty($customerID) || (is_numeric($customerID) && $customerID <= 0)) {
		dol_syslog("stancerCardstartPayWithRedirect error on stancerAddCustomerIfNeeded, customerID=" . var_export($customerID, true), LOG_ERR);
		setEventMessages($langs->trans("ErrorStancerCustomerNotFound"), [], 'errors');
		return -12;
	}

	//warning si on fait 3 paiements de 50€ pour une facture le TAG sera identique et stancer refusera les paiements
	// -> ajout de .SEQ=X dans le tag dans la fonction stancerMakeTAG
	$tag = (empty($parameters['tag']) ? GETPOST("ref", 'alpha') : $parameters['tag']);
	if (empty($tag)) {
		$tag = stancerMakeTAG($object);
	} else {
		dol_syslog("stancer pay : tag comes from GETPOST : $tag");
	}

	$source = (empty($parameters['source']) ? GETPOST("source", 'alpha') : $parameters['s']);
	if (empty($source) && $object->element == "facture") {
		$source = 'invoice';
	} elseif (empty($source) && $object->element == "propal") {
		$source = 'propal';
	}
	$securekey = (empty($parameters['securekey']) ? GETPOST("securekey", 'alpha') : $parameters['securekey']);
	dol_syslog("stancer pay : " . $object->ref . ", tag is $tag", LOG_INFO);

	$public_key = stancer_get_public_key();

	$args = base64_encode('tag=' . $tag . '&source=' . $source . '&ref=' . $object->ref . '&securekey=' . $securekey);
	if (defined('DOLENTITY')) {
		$args .= '&e=' . DOLENTITY;
	}

	$urlretour = DOL_MAIN_URL_ROOT . '/custom/stancer/public/paymentback.php?s=' . $args;

	// Get customer data for email (used later for notifications)
	$customerData = $stancerApi->getCustomer($customerID);
	$customerEmail = ($customerData !== false && isset($customerData['email'])) ? $customerData['email'] : '';

	$paymentData = null;
	$sp = new Stancer_payments($db);
	// Check if a previous payment was initiated but not completed
	$resSP = $sp->fetch(0, null, null, $tag);
	$mustCreate = true;
	$mustNewUUID = false;
	if ($resSP > 0) {
		if ($sp->isInitPaid()) {
			dol_syslog("stancer error, that unique ref id is already paid ! ($tag)", LOG_ERR);
			print "<p>" . $langs->trans("ErrorStancer") . ' (11) :<br />' . $langs->trans("StancerAlreadyPaid", $mysoc->name) . "</p>";
			print "<p>" . $langs->trans("ErrorStancerPleaseContactBy") . "</p><ul>";
			print "<li>" . $langs->trans("ErrorStancerPleaseContactByPhone", $mysoc->phone) . "</li>";
			print "<li>" . $langs->trans("ErrorStancerPleaseContactByMail", $mysoc->email) . "</li>";
			print "<li>" . $langs->trans("ErrorStancerPleaseUniqueID", $sp->unique_id) . "</li>";
			print "</ul></p>";
			exit;
		} elseif ($sp->canBeReused()) {
			dol_syslog("stancer A previous Stancer process could be reused ($tag) ");
			$paymentData = $stancerApi->getPayment($sp->stancer_id);
			if (empty($sp->card)) {
				dol_syslog("stancer   Stancer no previous card");
				$mustCreate = true;
				$mustNewUUID = true;
			} else {
				$mustNewUUID = true;
				dol_syslog("stancer   Stancer previous card " . json_encode($sp->card) . " so try to reset card ...");
			}
		} else {
			$paymentData = $stancerApi->getPayment($sp->stancer_id);
			if (empty($sp->card)) {
				$mustCreate = false;
			} else {
				dol_syslog("stancer   Stancer previous card " . json_encode($sp->card) . " so try to reset card ...");
			}
		}
	} else {
		dol_syslog("stancer   Stancer no previous payment");
	}

	if ($mustCreate || $paymentData == null) {
		dol_syslog("stancer   Stancer must create");
		if ($mustNewUUID) {
			dol_syslog("stancer   Stancer must add uniq tag");
			$tag .= ".UNIQ=" . substr($sp->getNextNumRef(), -2);
		}

		// Build payment data for API
		$paymentApiData = array(
			'amount' => (int) $amountToPay,
			'currency' => strtolower($object->multicurrency_code ?? 'eur'),
			'customer' => $customerID,
			'order_id' => $object->ref,
			'unique_id' => $tag,
			'description' => substr(stancerChangeLabel($object), -64), // max size is 64
			'auth' => true // Enable 3DS
		);

		if (strpos($urlretour, 'https://') === 0) {
			$paymentApiData['return_url'] = $urlretour;
		} else {
			dol_syslog("stancer error, back url is not https : " . $urlretour, LOG_ERR);
		}

		// Create payment via API
		$paymentData = $stancerApi->createPayment($paymentApiData);
	}

	if ($paymentData !== false && isset($paymentData['id'])) {
		$paymentId = $paymentData['id'];
		dol_syslog("stancer card pay : $paymentId", LOG_DEBUG);

		// Save payment ID into session for callback
		$_SESSION["stancer_payment_id"] = $paymentId;
		$_SESSION['currencyCodeType'] = $object->multicurrency_code ?? 'EUR';

		$data = [
			'stancer_id' => $paymentId,
			'amount' => isset($paymentData['amount']) ? $paymentData['amount'] : $amountToPay,
			'currency' => isset($paymentData['currency']) ? $paymentData['currency'] : ($object->multicurrency_code ?? 'EUR'),
			'description' => isset($paymentData['description']) ? $paymentData['description'] : '',
			'order_id' => isset($paymentData['order_id']) ? $paymentData['order_id'] : $object->ref,
			'unique_id' => $tag,
			'method' => isset($paymentData['method']) ? $paymentData['method'] : '',
			'card' => isset($paymentData['card']) ? (is_array($paymentData['card']) ? $paymentData['card']['id'] : $paymentData['card']) : null,
			'sepa' => isset($paymentData['sepa']) ? (is_array($paymentData['sepa']) ? $paymentData['sepa']['id'] : $paymentData['sepa']) : null,
			'customer' => isset($paymentData['customer']) ? (is_array($paymentData['customer']) ? $paymentData['customer']['id'] : $paymentData['customer']) : $customerID,
			'refunds' => isset($paymentData['refunds']) ? $paymentData['refunds'] : null,
			'status' => isset($paymentData['status']) ? $paymentData['status'] : '',
			'response' => isset($paymentData['response']) ? $paymentData['response'] : '',
			'capture' => isset($paymentData['capture']) ? $paymentData['capture'] : null,
			'created' => isset($paymentData['created']) ? $paymentData['created'] : null,
			'return_url' => isset($paymentData['return_url']) ? $paymentData['return_url'] : $urlretour,
			'live_mode' => getDolGlobalString('STANCER_IS_PROD', '0'),
			'fk_soc' => $object->socid,
		];
		$sp->fillDataArray($data);
		// Stored on the row, not only in $_SESSION["partialPayment"]: the customer may
		// come back through the emailed payment link, with no session at all. Assigned
		// outside of $data because fillDataArray() skips falsy values and 0 means
		// "full payment" here, not "unknown".
		$sp->partial_payment = stancerIsDepositPayment($object);
		$res = $sp->create($user, true);
		if ($res) {
			// Redirect to Stancer payment page
			$url = "https://payment.stancer.com/" . $public_key . "/" . $paymentId . "?lang=fr";
			dol_syslog("stancer card Stancer_payments ok, prepare redirect to $url", LOG_DEBUG);

			$stc = new Stancer($db);
			$stc->createEvent($object, "stancer_cb_start", $langs->trans("StancerPayCB"), $langs->trans("StancerPayWithRedirectStarted", price($amountToPay/100, 1, $langs, 1, -1, -1, $conf->currency)));

			// Send confirmation email
			if ($sendMailToCustomer && !empty($customerEmail)) {
				$message = $langs->trans('StancerMailPayoutConfirmMail');
				$message .= "<br />";
				$message .= $langs->trans('StancerInCaseOfTroubleYourLinkTopay', $url);

				if (getDolGlobalString('STANCER_AUTO_MAIL_ORDER_CB_MAILTYPE', '') != '') {
					stancerSendOrderMailModele(getDolGlobalString('STANCER_AUTO_MAIL_ORDER_CB_MAILTYPE', ''), $object, '', 0, $customerEmail);
				} else {
					// The object can be an invoice, an order, a proposal, a member or a donation:
					// the track id prefix must follow it, otherwise the email collector reattaches
					// the customer answer to an unrelated object carrying the same rowid.
					$mailctx = stancerGetObjectMailContext($object);
					$mailTrackid = empty($mailctx['trackidprefix']) ? '' : $mailctx['trackidprefix'] . $object->id;
					stancerSendMail($customerEmail, $langs->trans('StancerMailSubjectConfirmLinkToPay'), $message, true, '', $mailTrackid);
				}
			}

			// Redirect
			if ($forceJsRedirect || headers_sent()) {
				dol_syslog("stancer card Stancer_payments ok redirect will be javascript", LOG_DEBUG);
				print '<script type="text/javascript" language="javascript">' . "\n";
				print "window.location = '" . $url . "'\n";
				print "</script>\n";
			} else {
				dol_syslog("stancer card Stancer_payments ok redirect with header location to $url", LOG_DEBUG);
				header("Location: " . $url);
				exit;
			}
		} else {
			dol_syslog("stancer card Stancer_payments error", LOG_ERR);
		}
	} else {
		dol_syslog("stancer pay error : " . $stancerApi->error, LOG_ERR);
		$message = "Please try with an other payment provider like Stripe";
		setEventMessages($langs->trans("ErrorStancer") . " " . $message, [], 'errors');

		$urlPayment = getOnlinePaymentUrl(0, $object->element, (string) $object->ref);
		header("Location: " . $urlPayment);
		exit;
	}
	return $error;
}

/**
 * run a payment with SEPA
 *
 * @param   CommonObject  $object                invoice to pay
 * @param   bool          $userMessage           Show the result to the user with setEventMessages()
 * @param   int           $companypaymentmodeid  Mandate to debit, default mandate of the thirdparty when 0
 * @param   int           $force                 Bypass the delay set by STANCER_DELAY_SEPA
 * @return  int      code
 *          0 == ok
 *          2 == delais mail d'info SEPA envoyé
 *   le reste == erreur
 */
function stancerSEPAstartPay($object, $userMessage = true, $companypaymentmodeid = 0, $force = 0)
{
	global $db, $conf, $user, $langs;
	$returnCode = -1;
	// Signature must stay generic (the hook layer only knows CommonObject), but the
	// concrete classes that reach this code all declare the fields used below.
	'@phan-var Facture|Commande|Propal|Adherent|Don $object';
	dol_syslog("stancerSEPAstartPay");
	$stancerApi = new StancerApi();

	$res = stancerCommonFilterBeforePay($object);
	if ($res < 0) {
		return $res;
	}

	if ($force == 0) {
		if ($object->mode_reglement_code != 'PRE') {
			$message = $langs->trans("Payment mode is not SEPA");
			if ($userMessage) {
				setEventMessages($langs->trans("ErrorStancer") . " (13) " . $message, [], 'errors');
			}
			dol_syslog("stancerSEPAstartPay payment mode is not PRE", LOG_DEBUG);
			return -4;
		}
	}

	//facture peut avoir été partiellement payée
	if ($object->element == "facture") {
		$totalpaid = $object->getSommePaiement();
		$totalcreditnotes = $object->getSumCreditNotesUsed();
		$totaldeposits = $object->getSumDepositsUsed();
		$amountToPay = StancerApi::toCents(price2num($object->total_ttc - $totalpaid - $totalcreditnotes - $totaldeposits, 'MT'));
	} else {
		$amountToPay = StancerApi::toCents(price2num($object->total_ttc ?? $object->amount, 'MT'));
	}

	//limite hard de stancer = 50cents
	if ($amountToPay <= 50) {
		dol_syslog("stancer SEPA error amount less than minimal 50cents", LOG_ERR);
	}

	//Gestion de la limite du montant maximum
	if (getDolGlobalString('STANCER_SWITCH_SEPA_AMOUNT', '') != '' && getDolGlobalString('STANCER_SWITCH_SEPA_AMOUNT_MAX', '') != '') {
		if ($amountToPay > StancerApi::toCents(getDolGlobalString('STANCER_SWITCH_SEPA_AMOUNT_MAX', ''))) {
			dol_syslog("stancerSEPAstartPay amount to pay is over STANCER_SWITCH_SEPA_AMOUNT_MAX", LOG_DEBUG);
			if (getDolGlobalString('STANCER_SWITCH_SEPA_TO_OTHER_BANK', '') != '') {
				dol_syslog("stancerSEPAstartPay auto reconfigure payment account to #" . getDolGlobalString('STANCER_SWITCH_SEPA_TO_OTHER_BANK'), LOG_DEBUG);
				// Facture::$fk_account is an int: read the constant as an int, not as a string.
				$object->fk_account = getDolGlobalInt('STANCER_SWITCH_SEPA_TO_OTHER_BANK');
				$object->update($user, 1);
			}
			return -5;
		}
	}

	//Verifier le délais entre date d'information et date de mise en prélèvement ?
	//voir STANCER_DELAY_SEPA
	$now = dol_now();
	$delay = ((int) getDolGlobalString('STANCER_DELAY_SEPA') * 24 * 60 * 60);
	$datefacture = $object->date;
	$datelim = $object->date_lim_reglement;

	//TODO : update automatique de la date limite de reglement sur la facture par rapport au delais obligatoire SEPA ?
	if (getDolGlobalString('STANCER_DELAY_SEPA_UPDATE_INVOICES') && (($datefacture + $delay) > $datelim)) {
		$object->date_lim_reglement = $datefacture + $delay;
		$object->update($user, 1);
	}

	// print "<p>now = $now, delais=$delay, datefacture=$datefacture, datepaiement=$datelim</p>";
	if (($datefacture + $delay) > $now) {
		dol_syslog("stancerSEPAstartPay ErrorStancerSEPAdelay (a), datefacture=$datefacture, delay=$delay, now=$now", LOG_DEBUG);
		//il ne faut pas passer la facture en paiement, délais d'information préalable non respecté
		if ($userMessage) {
			setEventMessages($langs->trans("ErrorStancerSEPAdelayTitle") . " " . $langs->trans("ErrorStancerSEPAdelay", getDolGlobalString('STANCER_DELAY_SEPA')), [], 'errors');
		}
		if ($userMessage != true && getDolGlobalString('STANCER_AUTO_MAIL_INVOICES_SEPA_INFORMATION')) {
			// print "<p>Facture pas encore envoyée par mail </p>";
			stancerSendInvoiceMailModele(getDolGlobalString('STANCER_AUTO_MAIL_INVOICES_SEPA_INFORMATION_MAILTYPE', ''), $object, 'BILL_INFO_SENTBYMAIL');
		}
		return 2;
	} else {
		dol_syslog("stancerSEPAstartPay StancerSEPAdelay is good: datefacture=$datefacture, delay=$delay, now=$now", LOG_DEBUG);
	}
	if ($datelim > $now + (24 * 60 * 60)) {
		dol_syslog("stancerSEPAstartPay ErrorStancerInvoiceDateDue", LOG_DEBUG);
		if ($userMessage) {
			setEventMessages($langs->trans("ErrorStancerSEPAdelayTitle") . " " . $langs->trans("ErrorStancerInvoiceDateDue"), [], 'errors');
		}
		return -1;
	}
	// print json_encode($object);

	//TODO eric 04/05/23
	//verifier chez stancer si un paiement n'est pas déjà en cours pour cette facture
	//le calcul du délais ne semble pas marcher sur facture FA2305-0225
	//retour message d'erreur étrange "No such customer cust_mpGn1Xrq1RT0a5VBNQrD1KF2"

	$socid = $object->socid;
	$societe = new Societe($db);
	$socresult = $societe->fetch($socid);
	if (is_numeric($socresult) &&  $socresult <= 0) {
		dol_syslog("stancer pay by SEPA : can't fetch societe with id=$socid", LOG_ERR);
		return -7;
	}

	$companypaymentmode = new CompanyPaymentModeStancer($db);
	if ($companypaymentmodeid == 0) {
		//recherche si on a un moyen de paiement sepa pour cette societe chez stancer
		$customsql = " AND type = 'ban' AND label LIKE 'stancer-sepa%' AND fk_soc = '" . $db->escape($socid) . "' AND stancer_object_ref <> '' ORDER BY default_rib DESC";
		dol_syslog("stancer pay by SEPA : fetch payment mode with custom sql", LOG_DEBUG);
		$res = $companypaymentmode->fetch(0, '', 0, '', $customsql);
	} else {
		dol_syslog("stancer pay by SEPA : fetch payment mode with id=$companypaymentmodeid", LOG_DEBUG);
		$res = $companypaymentmode->fetch($companypaymentmodeid);
	}
	if (is_numeric($res) &&  $res <= 0) {
		dol_syslog("stancer pay SEPA error, CompanyPaymentModeStancer does not exists", LOG_ERR);
		$message = $langs->trans("StancerSEPAisNotConfiguredForThatCustomer");

		$stc = new Stancer($db);
		$stc->createEvent($object, "stancer_sepa_start", $langs->trans("StancerPaySEPA"), $message);

		if ($userMessage) {
			setEventMessages($langs->trans("ErrorStancer") . " (17) " . $message, [],  'errors');
		}
		return -8;
	}

	dol_syslog("stancer pay by SEPA : " . json_encode($object), LOG_DEBUG);
	dol_syslog("stancer pay by SEPA companypaymentmode : " . json_encode($companypaymentmode), LOG_DEBUG);
	dol_syslog("stancer pay by SEPA ref " . $object->ref, LOG_INFO);

	//warning si on fait 3 paiements de 50€ pour une facture le TAG sera identique et stancer refusera les paiements
	// -> ajout de .SEQ=X dans le tag dans la fonction stancerMakeTAG
	$tag = stancerMakeTAG($object, $force);

	// print "<p>juste ici le tag is $tag, force is=$force</p>";exit;

	$sp = new Stancer_payments($db);

	if ($force == 0) {
		//Vérification que nous n'avons pas déjà un paiement en cours ....
		$resSP = $sp->fetch(0, null, null, $tag);
		if ($resSP) {
			if ($sp->isInitPaid()) {
				dol_syslog("stancer SEPA error (transaction already in progress, status is " . $sp->getLabelStatus() . ")", LOG_DEBUG);
			} else {
				dol_syslog("stancer SEPA warning (transaction in progress but not successful : status is " . $sp->getLabelStatus() . ")", LOG_DEBUG);
			}

			$url = "<a href='" . dol_buildpath("/stancer/stancer_payments_list.php", 2) . "?search_stancer_id=" . $sp->stancer_id . "&token=" . newToken() . "'>";
			$message = $langs->transnoentitiesnoconv("StancerSEPAalreadyInProgress", $url, '</a>');
			if ($userMessage) {
				setEventMessages($langs->trans("ErrorStancer") . " (14) " . $message, [], 'errors');

				$stc = new Stancer($db);
				$stc->createEvent($object, "stancer_sepa_start", $langs->trans("StancerPaySEPA"), $message);
			}
			return -1;
		}
	}

	$numref = $sp->getNextNumRef();

	// Get customer ID from company payment mode
	$customerId = $companypaymentmode->stancer_account;
	$sepaId = $companypaymentmode->stancer_object_ref;

	// Verify SEPA exists via API
	dol_syslog("stancer Get Stancer SEPA data with $sepaId", LOG_DEBUG);
	$sepaData = $stancerApi->getSepa($sepaId);
	if ($sepaData === false) {
		dol_syslog("stancer SEPA not found: " . $stancerApi->error, LOG_ERR);
		return -6;
	}

	dol_syslog("stancer SEPA is : " . json_encode($sepaData) . " and TAG=$tag", LOG_DEBUG);

	// Build payment data for SEPA
	$paymentApiData = array(
		'amount' => (int) $amountToPay,
		'currency' => strtolower($object->multicurrency_code ?? 'eur'),
		'customer' => $customerId,
		'order_id' => $object->ref,
		'unique_id' => $tag,
		'description' => substr(stancerChangeLabel($object), -64), // max size is 64
		'sepa' => $sepaId
	);

	// Create payment via API
	$paymentData = $stancerApi->createPayment($paymentApiData);

	if ($paymentData !== false && isset($paymentData['id'])) {
		$paymentId = $paymentData['id'];
		dol_syslog("stancer PID is : " . $paymentId, LOG_DEBUG);
		dol_syslog("stancer payment is : " . json_encode($paymentData), LOG_DEBUG);

		$data = [
			'stancer_id' => $paymentId,
			'amount' => isset($paymentData['amount']) ? $paymentData['amount'] : $amountToPay,
			'currency' => isset($paymentData['currency']) ? $paymentData['currency'] : ($object->multicurrency_code ?? 'EUR'),
			'description' => isset($paymentData['description']) ? $paymentData['description'] : '',
			'order_id' => isset($paymentData['order_id']) ? $paymentData['order_id'] : $object->ref,
			'unique_id' => $tag,
			'method' => isset($paymentData['method']) ? $paymentData['method'] : 'sepa',
			'card' => isset($paymentData['card']) ? (is_array($paymentData['card']) ? $paymentData['card']['id'] : $paymentData['card']) : null,
			'sepa' => isset($paymentData['sepa']) ? (is_array($paymentData['sepa']) ? $paymentData['sepa']['id'] : $paymentData['sepa']) : $sepaId,
			'customer' => isset($paymentData['customer']) ? (is_array($paymentData['customer']) ? $paymentData['customer']['id'] : $paymentData['customer']) : $customerId,
			'refunds' => isset($paymentData['refunds']) ? $paymentData['refunds'] : null,
			'status' => isset($paymentData['status']) ? $paymentData['status'] : '',
			'response' => isset($paymentData['response']) ? $paymentData['response'] : '',
			'capture' => isset($paymentData['capture']) ? $paymentData['capture'] : null,
			'created' => isset($paymentData['created']) ? $paymentData['created'] : null,
			'return_url' => isset($paymentData['return_url']) ? $paymentData['return_url'] : '',
			'live_mode' => getDolGlobalString('STANCER_IS_PROD', '0'),
			'fk_soc' => $socid,
		];
		$sp->fillDataArray($data);
		$res = $sp->create($user, true);
		if ($res) {
			$message = $langs->trans("Nice, Stancer SEPA payment is engaged !");
			$returnCode = 0;

			$stc = new Stancer($db);
			$stc->createEvent($object, "stancer_sepa_start", $langs->trans("StancerPaySEPA"), $message);

			// Update payment method on invoice
			dol_syslog("stancer update invoice with bankAccount and paymentType", LOG_DEBUG);
			$bankaccountId = getDolGlobalInt('STANCER_BANK_ACCOUNT_FOR_PAYMENTS');
			$paymentmethodId = dol_getIdFromCode($db, 'PRE', 'c_paiement', 'code', 'id', 1);
			$object->setPaymentMethods($paymentmethodId);
			$object->setBankAccount($bankaccountId);
			$object->update($user, 1);

			dol_syslog($message, LOG_DEBUG);
			if ($userMessage) {
				setEventMessages($langs->trans("Stancer") . " " . $message, [], 'mesgs');
			}
			if ($userMessage != true && getDolGlobalString('STANCER_AUTO_MAIL_INVOICES_SEPA')) {
				stancerSendInvoiceMailModele(getDolGlobalString('STANCER_AUTO_MAIL_INVOICES_SEPA_MAILTYPE', ''), $object, 'BILL_SEPASTART_SENTBYMAIL');
			}
		} else {
			$message = $langs->trans("Error on Stancer SEPA!");

			$stc = new Stancer($db);
			$stc->createEvent($object, "stancer_sepa_start", $langs->trans("StancerPaySEPA"), $message);

			if ($userMessage) {
				setEventMessages($langs->trans("ErrorStancer") . " (15) " . $message, [],  'errors');
			}
			if ($userMessage != true && getDolGlobalString('STANCER_AUTO_MAIL_INVOICES_ERROR')) {
				stancerSendInvoiceMailModele(getDolGlobalString('STANCER_AUTO_MAIL_INVOICES_ERROR', ''), $object, 'BILL_SEPAERROR_SENTBYMAIL', 1);
			}
			dol_syslog("stancer pay SEPA error save (1)" . json_encode($res), LOG_ERR);
		}
	} else {
		dol_syslog("stancer pay SEPA error: " . $stancerApi->error, LOG_ERR);
		$message = $langs->trans("Error on Stancer SEPA payment request: " . $stancerApi->error);

		$stc = new Stancer($db);
		$stc->createEvent($object, "stancer_sepa_start", $langs->trans("StancerPaySEPA"), $message);

		if ($userMessage) {
			setEventMessages($langs->trans("ErrorStancer") . " (16) " . $message . ' TAG: ' . $tag, [], 'errors');
		}
	}

	if ($userMessage && $message != "") {
		// TODO stancerAddActionComm($object, 'BILL_', $subjecttosend, $texttosend, $postactionmessages, '');
	}
	return 	$returnCode;
}


/**
 * Build the description string (visible on customer's bank statement) for a grouped SEPA payment.
 * Refs joined by '+' truncated to 64 chars max. If too long, keeps as many full refs as fit
 * and appends '+N' where N is the number of remaining refs not displayed.
 *
 * @param   string[]  $refs  invoice refs in display order
 * @return  string           description (<= 64 chars)
 */
function stancerBuildGroupedDescription(array $refs)
{
	$maxLen = 64;
	if (count($refs) === 0) {
		return '';
	}
	$full = implode('+', $refs);
	if (strlen($full) <= $maxLen) {
		return $full;
	}
	// Truncate: keep first N refs that fit including "+K" suffix
	$kept = array();
	$total = count($refs);
	for ($i = 0; $i < $total; $i++) {
		$candidate = implode('+', array_merge($kept, array($refs[$i])));
		$remaining = $total - ($i + 1);
		$suffix = ($remaining > 0) ? '+' . $remaining : '';
		if (strlen($candidate . $suffix) > $maxLen) {
			break;
		}
		$kept[] = $refs[$i];
	}
	if (count($kept) === 0) {
		// First ref alone is already too long: hard truncate
		return substr($refs[0], 0, $maxLen);
	}
	$remaining = $total - count($kept);
	$result = implode('+', $kept);
	if ($remaining > 0) {
		$result .= '+' . $remaining;
	}
	return $result;
}

/**
 * Build the order_id (visible in Stancer dashboard, 36 chars max) for a grouped SEPA payment.
 *
 * @param   string[]  $refs  invoice refs
 * @return  string           order_id (<= 36 chars)
 */
function stancerBuildGroupedOrderId(array $refs)
{
	$maxLen = 36;
	if (count($refs) === 0) {
		return '';
	}
	$full = implode('+', $refs);
	if (strlen($full) <= $maxLen) {
		return $full;
	}
	$kept = array();
	$total = count($refs);
	for ($i = 0; $i < $total; $i++) {
		$candidate = implode('+', array_merge($kept, array($refs[$i])));
		$remaining = $total - ($i + 1);
		$suffix = ($remaining > 0) ? '+' . $remaining : '';
		if (strlen($candidate . $suffix) > $maxLen) {
			break;
		}
		$kept[] = $refs[$i];
	}
	if (count($kept) === 0) {
		return substr($refs[0], 0, $maxLen);
	}
	$remaining = $total - count($kept);
	$result = implode('+', $kept);
	if ($remaining > 0) {
		$result .= '+' . $remaining;
	}
	return $result;
}

/**
 * Build the unique_id (36 chars max, deterministic) for a grouped SEPA payment.
 * Pattern: GRP=<8-char-hex-hash>.CUS=<socid>
 * The hash is computed from the sorted invoice ids so that retrying the same
 * group of invoices on the same day produces the same unique_id (idempotence).
 *
 * @param   int[]  $invoiceIds  invoice ids forming the group
 * @param   int    $socid       customer id
 * @return  string              unique_id (<= 36 chars)
 */
function stancerBuildGroupedTag(array $invoiceIds, $socid)
{
	$ids = array_map('intval', $invoiceIds);
	sort($ids);
	$hash = substr(md5(implode(',', $ids) . '|' . (int) $socid), 0, 8);
	return 'GRP=' . $hash . '.CUS=' . ((int) $socid);
}

/**
 * Run a single grouped SEPA payment for several same-day invoices of the same customer.
 *
 * @param   Facture[]  $invoices              array of Facture objects (>= 2), all PRE, same socid, same datef
 * @param   int        $companypaymentmodeid  shared SEPA mandate id (llx_societe_rib.rowid)
 * @param   bool       $userMessage           emit setEventMessages (true for manual UI, false for cron)
 * @return  int        0 on success
 *                     2 if SEPA info delay not elapsed yet (whole group skipped)
 *                     < 0 on error (-1 already in progress / generic, -4 not all PRE, -5 over amount limit,
 *                         -6 SEPA not found via API, -7 fetch societe failed, -8 mandate not found,
 *                         -10 group invalid / inconsistent, -11 amount too low)
 */
function stancerSEPAstartPayGrouped(array $invoices, $companypaymentmodeid, $userMessage = false)
{
	global $db, $conf, $user, $langs;

	dol_syslog("stancerSEPAstartPayGrouped called with " . count($invoices) . " invoices, companypaymentmodeid=$companypaymentmodeid");

	if (count($invoices) < 2) {
		dol_syslog("stancerSEPAstartPayGrouped called with less than 2 invoices, group invalid", LOG_ERR);
		return -10;
	}

	$stancerApi = new StancerApi();

	// Validate batch coherence (same socid, same datef, same currency, all facture/PRE/not paid).
	$first = $invoices[0];
	$socid = (int) $first->socid;
	$datef = $first->date;
	$currency = strtolower($first->multicurrency_code ?? 'eur');
	$invoiceIds = array();
	$refs = array();
	$totalCents = 0;

	foreach ($invoices as $inv) {
		if ($inv->element !== 'facture') {
			dol_syslog("stancerSEPAstartPayGrouped invoice " . ($inv->ref ?? '?') . " is not a facture (element=" . $inv->element . "), abort group", LOG_ERR);
			return -10;
		}
		if ((int) $inv->socid !== $socid) {
			dol_syslog("stancerSEPAstartPayGrouped invoice " . $inv->ref . " socid mismatch (got " . $inv->socid . ", expected $socid), abort group", LOG_ERR);
			return -10;
		}
		if ($inv->date !== $datef) {
			dol_syslog("stancerSEPAstartPayGrouped invoice " . $inv->ref . " datef mismatch (got " . $inv->date . ", expected $datef), abort group", LOG_ERR);
			return -10;
		}
		$invCurrency = strtolower($inv->multicurrency_code ?? 'eur');
		if ($invCurrency !== $currency) {
			dol_syslog("stancerSEPAstartPayGrouped invoice " . $inv->ref . " currency mismatch (got $invCurrency, expected $currency), abort group", LOG_ERR);
			return -10;
		}
		if ($inv->mode_reglement_code !== 'PRE') {
			dol_syslog("stancerSEPAstartPayGrouped invoice " . $inv->ref . " mode_reglement_code is not PRE, abort group", LOG_ERR);
			return -4;
		}
		// @phan-suppress-next-line PhanDeprecatedProperty  $paye is the column Dolibarr 15..21 fills and still writes; status==2 also covers abandoned invoices
		if (!empty($inv->paye)) {
			// @phan-suppress-next-line PhanDeprecatedProperty  same reason as above, kept for the log line
			dol_syslog("stancerSEPAstartPayGrouped invoice " . $inv->ref . " is already paid (paye=" . var_export($inv->paye, true) . "), abort group", LOG_ERR);
			return -10;
		}
		$res = stancerCommonFilterBeforePay($inv);
		if ($res < 0) {
			dol_syslog("stancerSEPAstartPayGrouped commonFilter returned $res on invoice " . $inv->ref . ", abort group", LOG_ERR);
			return $res;
		}
		$paid = $inv->getSommePaiement();
		$credit = $inv->getSumCreditNotesUsed();
		$deposit = $inv->getSumDepositsUsed();
		$remaining = StancerApi::toCents(price2num($inv->total_ttc - $paid - $credit - $deposit, 'MT'));
		if ($remaining <= 0) {
			dol_syslog("stancerSEPAstartPayGrouped invoice " . $inv->ref . " has no remaining amount to pay, abort group", LOG_ERR);
			return -10;
		}
		$totalCents += $remaining;
		$invoiceIds[] = (int) $inv->id;
		$refs[] = $inv->ref;
	}

	if ($totalCents <= 50) {
		dol_syslog("stancerSEPAstartPayGrouped total amount $totalCents <= 50 cents, abort", LOG_ERR);
		return -11;
	}

	// Amount limit check using STANCER_SWITCH_SEPA_AMOUNT_MAX (compare total against the limit).
	if (getDolGlobalString('STANCER_SWITCH_SEPA_AMOUNT', '') != '' && getDolGlobalString('STANCER_SWITCH_SEPA_AMOUNT_MAX', '') != '') {
		if ($totalCents > StancerApi::toCents(getDolGlobalString('STANCER_SWITCH_SEPA_AMOUNT_MAX', ''))) {
			dol_syslog("stancerSEPAstartPayGrouped total amount $totalCents over STANCER_SWITCH_SEPA_AMOUNT_MAX, abort group (no automatic bank switch on grouped payments)", LOG_WARNING);
			return -5;
		}
	}

	// SEPA info delay: use the MOST RECENT date of the group to check (all invoices share the same datef anyway).
	$now = dol_now();
	$delay = ((int) getDolGlobalString('STANCER_DELAY_SEPA') * 24 * 60 * 60);
	if (($datef + $delay) > $now) {
		dol_syslog("stancerSEPAstartPayGrouped SEPA info delay not elapsed (datef=$datef, delay=$delay, now=$now), skip group", LOG_DEBUG);
		if ($userMessage != true && getDolGlobalString('STANCER_AUTO_MAIL_INVOICES_SEPA_INFORMATION')) {
			foreach ($invoices as $inv) {
				stancerSendInvoiceMailModele(getDolGlobalString('STANCER_AUTO_MAIL_INVOICES_SEPA_INFORMATION_MAILTYPE', ''), $inv, 'BILL_INFO_SENTBYMAIL');
			}
		}
		return 2;
	}

	// Due date check: all invoices must be past due (or due today). Use the latest date_lim of the group.
	$latestDateLim = 0;
	foreach ($invoices as $inv) {
		if ($inv->date_lim_reglement > $latestDateLim) {
			$latestDateLim = $inv->date_lim_reglement;
		}
	}
	if ($latestDateLim > $now + (24 * 60 * 60)) {
		dol_syslog("stancerSEPAstartPayGrouped latest due date in the future, skip group", LOG_DEBUG);
		return -1;
	}

	$societe = new Societe($db);
	if ($societe->fetch($socid) <= 0) {
		dol_syslog("stancerSEPAstartPayGrouped can't fetch societe id=$socid", LOG_ERR);
		return -7;
	}

	$companypaymentmode = new CompanyPaymentModeStancer($db);
	$resCpm = $companypaymentmode->fetch($companypaymentmodeid);
	if (is_numeric($resCpm) && $resCpm <= 0) {
		dol_syslog("stancerSEPAstartPayGrouped CompanyPaymentModeStancer id=$companypaymentmodeid not found", LOG_ERR);
		return -8;
	}

	$customerId = $companypaymentmode->stancer_account;
	$sepaId = $companypaymentmode->stancer_object_ref;
	if (empty($customerId) || empty($sepaId)) {
		dol_syslog("stancerSEPAstartPayGrouped mandate id=$companypaymentmodeid has empty stancer_account or stancer_object_ref, abort", LOG_ERR);
		return -8;
	}

	$tag = stancerBuildGroupedTag($invoiceIds, $socid);
	$description = stancerBuildGroupedDescription($refs);
	$orderId = stancerBuildGroupedOrderId($refs);

	// Idempotence: if a Stancer_payments with this unique_id already exists, abort BEFORE
	// hitting the Stancer API. Avoids both a useless network call and the risk of creating
	// a second remote payment if the API mandate lookup were to succeed twice.
	$sp = new Stancer_payments($db);
	$resSp = $sp->fetch(0, null, null, $tag);
	if ($resSp) {
		dol_syslog("stancerSEPAstartPayGrouped a payment already exists for tag=$tag (status=" . $sp->getLabelStatus() . "), skip", LOG_DEBUG);
		return -1;
	}

	$sepaData = $stancerApi->getSepa($sepaId);
	if ($sepaData === false) {
		dol_syslog("stancerSEPAstartPayGrouped SEPA $sepaId not found at Stancer: " . $stancerApi->error, LOG_ERR);
		return -6;
	}

	$paymentApiData = array(
		'amount'      => (int) $totalCents,
		'currency'    => $currency,
		'customer'    => $customerId,
		'order_id'    => $orderId,
		'unique_id'   => $tag,
		'description' => $description,
		'sepa'        => $sepaId,
	);

	dol_syslog("stancerSEPAstartPayGrouped create payment: " . json_encode($paymentApiData), LOG_DEBUG);
	$paymentData = $stancerApi->createPayment($paymentApiData);
	if ($paymentData === false || !isset($paymentData['id'])) {
		dol_syslog("stancerSEPAstartPayGrouped createPayment failed: " . $stancerApi->error, LOG_ERR);
		// Log per-invoice event for traceability
		$stc = new Stancer($db);
		$msg = $langs->trans("Error on Stancer SEPA payment request: " . $stancerApi->error);
		foreach ($invoices as $inv) {
			$stc->createEvent($inv, "stancer_sepa_start", $langs->trans("StancerPaySEPA"), $msg . ' (grouped ' . count($invoices) . ' invoices)');
		}
		return -1;
	}

	$paymentId = $paymentData['id'];
	dol_syslog("stancerSEPAstartPayGrouped Stancer payment created id=$paymentId for " . count($invoices) . " invoices");

	$data = array(
		'stancer_id'           => $paymentId,
		'amount'               => isset($paymentData['amount']) ? $paymentData['amount'] : $totalCents,
		'currency'             => isset($paymentData['currency']) ? $paymentData['currency'] : $currency,
		'description'          => isset($paymentData['description']) ? $paymentData['description'] : $description,
		'order_id'             => isset($paymentData['order_id']) ? $paymentData['order_id'] : $orderId,
		'unique_id'            => $tag,
		'grouped_invoice_ids'  => implode(',', $invoiceIds),
		'method'               => isset($paymentData['method']) ? $paymentData['method'] : 'sepa',
		'card'                 => null,
		'sepa'                 => isset($paymentData['sepa']) ? (is_array($paymentData['sepa']) ? $paymentData['sepa']['id'] : $paymentData['sepa']) : $sepaId,
		'customer'             => isset($paymentData['customer']) ? (is_array($paymentData['customer']) ? $paymentData['customer']['id'] : $paymentData['customer']) : $customerId,
		'refunds'              => isset($paymentData['refunds']) ? $paymentData['refunds'] : null,
		'status'               => isset($paymentData['status']) ? $paymentData['status'] : '',
		'response'             => isset($paymentData['response']) ? $paymentData['response'] : '',
		'capture'              => isset($paymentData['capture']) ? $paymentData['capture'] : null,
		'created'              => isset($paymentData['created']) ? $paymentData['created'] : null,
		'return_url'           => isset($paymentData['return_url']) ? $paymentData['return_url'] : '',
		'live_mode'            => getDolGlobalString('STANCER_IS_PROD', '0'),
		'fk_soc'               => $socid,
	);
	$sp->fillDataArray($data);
	$resCreate = $sp->create($user, true);
	if (!$resCreate) {
		dol_syslog("stancerSEPAstartPayGrouped Stancer_payments create failed", LOG_ERR);
		return -1;
	}

	// Per-invoice updates: mark mode + bank account, attach event for traceability.
	$bankaccountId = getDolGlobalInt('STANCER_BANK_ACCOUNT_FOR_PAYMENTS');
	$paymentmethodId = dol_getIdFromCode($db, 'PRE', 'c_paiement', 'code', 'id', 1);
	$stc = new Stancer($db);
	$eventMsg = $langs->trans("Stancer SEPA grouped payment engaged") . ' (' . count($invoices) . ' invoices, total=' . ($totalCents / 100) . ' ' . $currency . ', paymentId=' . $paymentId . ')';
	foreach ($invoices as $inv) {
		$inv->setPaymentMethods($paymentmethodId);
		$inv->setBankAccount($bankaccountId);
		$inv->update($user, 1);
		$stc->createEvent($inv, "stancer_sepa_start", $langs->trans("StancerPaySEPA"), $eventMsg);
		if (!$userMessage && getDolGlobalString('STANCER_AUTO_MAIL_INVOICES_SEPA')) {
			stancerSendInvoiceMailModele(getDolGlobalString('STANCER_AUTO_MAIL_INVOICES_SEPA_MAILTYPE', ''), $inv, 'BILL_SEPASTART_SENTBYMAIL');
		}
	}

	if ($userMessage) {
		setEventMessages($langs->trans("Stancer") . ' ' . $eventMsg, [], 'mesgs');
	}

	return 0;
}


/**
 * run a payment with CB
 *
 * @param   CommonObject  $object                invoice to pay
 * @param   bool          $userMessage           Show the result to the user with setEventMessages()
 * @param   int           $companypaymentmodeid  Card to debit, default card of the thirdparty when 0
 * @param   int           $force                 Bypass the delay set by STANCER_DELAY_SEPA
 * @return  int|null code
 *          0 == ok
 *          2 == delais mail d'info SEPA envoyé
 *   le reste == erreur
 *       null == a Stancer payment already exists for that invoice, nothing was started
 */
function stancerCBstartPay($object, $userMessage = true, $companypaymentmodeid = 0, $force = 0)
{
	global $db, $conf, $user, $langs;
	$returnCode = -1;
	// Signature must stay generic (the hook layer only knows CommonObject), but the
	// concrete classes that reach this code all declare the fields used below.
	'@phan-var Facture|Commande|Propal|Adherent|Don $object';
	$langs->loadLangs(array("stancer@stancer"));
	$stancerApi = new StancerApi();

	dol_syslog("stancerCBstartPay payment called", LOG_DEBUG);

	$res = stancerCommonFilterBeforePay($object);
	if ($res < 0) {
		dol_syslog("stancerCBstartPay stancerCommonFilterBeforePay returned < 0", LOG_DEBUG);
		return $res;
	}

	if ($force == 0) {
		if ($object->mode_reglement_code != 'CB') {
			$message = $langs->trans("Payment mode is not CB");
			if ($userMessage) {
				setEventMessages($langs->trans("ErrorStancer") . " (18) " . $message, [],  'errors');
			}
			dol_syslog("stancerCBstartPay payment mode is not CB", LOG_DEBUG);
			return -4;
		}

		$now = dol_now();
		$datelim = $object->date_lim_reglement;
		if ($datelim > $now) {
			dol_syslog("stancerCBstartPay ErrorStancerInvoiceDateDue", LOG_DEBUG);
			if ($userMessage) {
				setEventMessages($langs->trans("ErrorStancerCBdueTitle") . " " . $langs->trans("ErrorStancerInvoiceDateDue"), [], 'errors');
			}
			return -1;
		}
	}

	$socid = $object->socid;
	$societe = new Societe($db);
	$socresult = $societe->fetch($socid);
	if (is_numeric($socresult) &&  $socresult <= 0) {
		dol_syslog("stancerCBstartPay can't fetch socid=$socid", LOG_ERR);
		return -6;
	}
	//recherche si on a un moyen de paiement cb pour cette societe chez stancer
	$companypaymentmode = new CompanyPaymentModeStancer($db);
	if ($companypaymentmodeid == 0) {
		//recherche si on a un moyen de paiement sepa pour cette societe chez stancer
		$customsql = " AND type = 'card' AND label LIKE 'stancer-card%' AND fk_soc = '" . $db->escape($socid) . "' AND stancer_object_ref <> '' ORDER BY default_rib DESC";
		$res = $companypaymentmode->fetch(0, '', 0, '', $customsql);
	} else {
		$res = $companypaymentmode->fetch($companypaymentmodeid);
	}

	if (is_numeric($res) &&  $res <= 0) {
		dol_syslog("stancerCBstartPay pay CB error, CompanyPaymentModeStancer does not exists", LOG_ERR);
		$message = $langs->trans("StancerCBisNotConfiguredForThatCustomer", $societe->name);
		if ($userMessage) {
			setEventMessages($langs->trans("ErrorStancer") . " (23) " . $message, [],  'errors');
		}
		return -7;
	}

	dol_syslog("stancerCBstartPay pay by CB : " . json_encode($object), LOG_DEBUG);
	// dol_syslog("stancerCBstartPay pay by CB companypaymentmode : " . json_encode($companypaymentmode), LOG_DEBUG);
	// dol_syslog("stancerCBstartPay pay by CB ref " . $object->ref, LOG_INFO);

	//warning si on fait 3 paiements de 50€ pour une facture le TAG sera identique et stancer refusera les paiements
	// -> ajout de .SEQ=X dans le tag dans la fonction stancerMakeTAG
	if ($force == 1) {
		$tag = stancerMakeTAG($object, true);
	} else {
		$tag = stancerMakeTAG($object);
	}

	$sp = new Stancer_payments($db);
	//Vérification que nous n'avons pas déjà un CB en cours ....
	$resSP = $sp->fetchAll('ASC', '', 0, 0, array('customsql' => "live_mode = '" . getDolGlobalString('STANCER_IS_PROD') . "' AND (unique_id LIKE '%INV=" . $db->escape($object->id) . "' OR unique_id LIKE '%INV=" . $db->escape($object->id) . ".%')"));
	// print json_encode($object->ref) . " == " . json_encode($resSP);exit;
	if (!empty($resSP)) {
		$lastTry = 0;
		//si plusieurs paiements sont liés création d'un tableau [date => valeur]
		foreach ($resSP as $key => $val) {
			//refresh ? get payment info from stancer
			if (!empty($val->stancer_id)) {
				dol_syslog("stancerCBstartPay refresh CB data from remote server : " . $val->stancer_id, LOG_DEBUG);
				$pmData = $stancerApi->getPayment($val->stancer_id);
				if ($pmData !== false) {
					$timeDate = isset($pmData['date_bank']) ? $pmData['date_bank'] : (isset($pmData['created']) ? $pmData['created'] : 0);
					if ($lastTry < $timeDate) {
						$lastTry = $timeDate;
					}
					//un paiement succès dans l'historique ?
					$pmStatus = isset($pmData['status']) ? $pmData['status'] : '';
					dol_syslog("stancerCBstartPay refresh CB data from remote server, pay status : " . $pmStatus, LOG_DEBUG);
					if (in_array($pmStatus, ['to_capture', 'captured'])) {
						$resUpdate = $sp->fetch(0, '', $val->stancer_id);
						if ($resUpdate) {
							if ($sp->tms < $lastTry) {
								$resFill = $sp->fillDataFromApi($pmData);
								if ($resFill < 0) {
									dol_syslog("stancerCBstartPay fillData error, do not make update", LOG_DEBUG);
								} else {
									$sp->update($user);
								}
							}
						}
						dol_syslog("stancerCBstartPay CB said that one was already paid or in progress : " . dol_print_date($lastTry), LOG_DEBUG);
						$url = "<a href='" . dol_buildpath("/stancer/stancer_payments_list.php", 2) . "?search_stancer_id=" . $val->stancer_id . "&token=" . newToken() . "'>";
						$message = $langs->transnoentitiesnoconv("StancerCBalreadyInProgress", $url, '</a>');
						if ($userMessage) {
							setEventMessages($langs->trans("ErrorStancer") . " (19) " . $message, [],  'errors');
						}
						return;
					}
				}
			}
		}

		//si délais (4jours) dépassé on force la tentative de paiement ?
		if ($lastTry < time() - (3600 * 24 * 4)) {
			dol_syslog("stancerCBstartPay CB transaction already started but outdated, force pay", LOG_DEBUG);
			$tag = stancerMakeTAG($object, true);
		} else {
			dol_syslog("stancerCBstartPay CB error (transaction already started less than 4 days)", LOG_DEBUG);
			$url = "<a href='" . dol_buildpath("/stancer/stancer_payments_list.php", 2) . "?search_stancer_id=" . $sp->stancer_id . "&token=" . newToken() . "'>";
			$message = $langs->transnoentitiesnoconv("StancerCBalreadyInProgress", $url, '</a>');
			if ($userMessage) {
				setEventMessages($langs->trans("ErrorStancer") . " (20) " . $message, [],  'errors');
			}
			return;
		}
	}

	$numref = $sp->getNextNumRef();
	//dans stancer_account on a le customerid stancer
	$customerId = $companypaymentmode->stancer_account;
	// print "<p>customer is " . $companypaymentmode->stancer_account . " </p>";

	// dans stancer_object_ref se trouve l'info de la CB créé chez stancer
	$cardId = $companypaymentmode->stancer_object_ref;
	dol_syslog("stancerCBstartPay Stancer CB data with $cardId", LOG_DEBUG);

	//facture peut avoir été partiellement payée
	if ($object->element == "facture") {
		$totalpaid = $object->getSommePaiement();
		$totalcreditnotes = $object->getSumCreditNotesUsed();
		$totaldeposits = $object->getSumDepositsUsed();
		$amountToPay = StancerApi::toCents(price2num($object->total_ttc - $totalpaid - $totalcreditnotes - $totaldeposits, 'MT'));
	} else {
		$amountToPay = StancerApi::toCents(price2num($object->total_ttc ?? $object->amount, 'MT'));
	}


	//create payment via API
	$paymentApiData = array(
		'amount' => (int) $amountToPay,
		'currency' => strtolower($object->multicurrency_code ?? 'eur'),
		'card' => $cardId,
		'customer' => $customerId,
		'order_id' => $object->ref,
		'unique_id' => $tag,
		'description' => substr(stancerChangeLabel($object), -64), //warning: max size is 64
	);

	dol_syslog("stancerCBstartPay creating payment with data: " . json_encode($paymentApiData), LOG_DEBUG);

	try {
		$paymentResult = $stancerApi->createPayment($paymentApiData);
		if ($paymentResult === false) {
			throw new Exception($stancerApi->error);
		}
		dol_syslog("stancerCBstartPay payment result: " . json_encode($paymentResult), LOG_DEBUG);
		dol_syslog("stancerCBstartPay TAG is : " . json_encode($tag), LOG_DEBUG);

		if (!stancerCheckTag($tag)) {
			dol_syslog("stancerCBstartPay tag seems not a real tag=" . json_encode($tag), LOG_WARNING);
		}

		$pid = isset($paymentResult['id']) ? $paymentResult['id'] : '';
		dol_syslog("stancerCBstartPay pay avant auth(5) : $pid", LOG_DEBUG);
		$data = [
			'stancer_id' => $pid,
			'amount' => isset($paymentResult['amount']) ? $paymentResult['amount'] : $amountToPay,
			'currency' => isset($paymentResult['currency']) ? $paymentResult['currency'] : 'eur',
			'description' => isset($paymentResult['description']) ? $paymentResult['description'] : '',
			'order_id' => isset($paymentResult['order_id']) ? $paymentResult['order_id'] : $object->ref,
			'unique_id' => $tag,
			'method' => isset($paymentResult['method']) ? $paymentResult['method'] : 'card',
			'card' => isset($paymentResult['card']) ? (is_array($paymentResult['card']) ? $paymentResult['card']['id'] : $paymentResult['card']) : $cardId,
			'cb' => isset($paymentResult['card']) ? (is_array($paymentResult['card']) ? $paymentResult['card']['id'] : $paymentResult['card']) : $cardId,
			'customer' => isset($paymentResult['customer']) ? (is_array($paymentResult['customer']) ? $paymentResult['customer']['id'] : $paymentResult['customer']) : $customerId,
			'refunds' => isset($paymentResult['refunds']) ? $paymentResult['refunds'] : null,
			'status' => isset($paymentResult['status']) ? $paymentResult['status'] : '',
			'response' => isset($paymentResult['response']) ? $paymentResult['response'] : null,
			'capture' => isset($paymentResult['capture']) ? $paymentResult['capture'] : true,
			'created' => isset($paymentResult['created']) ? $paymentResult['created'] : time(),
			// 'date_bank' => isset($paymentResult['date_bank']) ? $paymentResult['date_bank'] : null,
			'return_url' => isset($paymentResult['return_url']) ? $paymentResult['return_url'] : '',
			'live_mode' => getDolGlobalString('STANCER_IS_PROD', '0'),
			'fk_soc' => $socid,
		];
		$sp->fillDataArray($data);
		$res = $sp->create($user, true);
		if ($res) {
			$message = $langs->trans("Nice, Stancer CB payment is engaged !");

			$stc = new Stancer($db);
			$stc->createEvent($object, "stancer_cb_start", $langs->trans("StancerPayCB"), $langs->trans("StancerPayAmountStarted", $amountToPay));

			$returnCode = 0;

			//erics update payment method on invoice, like #18
			dol_syslog("stancer update invoice with bankAccount and paymentType", LOG_DEBUG);
			$bankaccountId = getDolGlobalInt('STANCER_BANK_ACCOUNT_FOR_PAYMENTS');
			$paymentmethodId = dol_getIdFromCode($db, 'CB', 'c_paiement', 'code', 'id', 1);
			$object->setPaymentMethods($paymentmethodId);
			$object->setBankAccount($bankaccountId);
			$object->update($user, 1);

			dol_syslog("stancerCBstartPay " . $message, LOG_DEBUG);
			if ($userMessage) {
				setEventMessages($langs->trans("Stancer") . " " . $message, [],  'mesgs');
			}
			//Pas interactif + config mail
			if ($userMessage != true && getDolGlobalString('STANCER_AUTO_MAIL_INVOICES_CB')) {
				dol_syslog("stancerCBstartPay pay CB engaged, send mail", LOG_DEBUG);
				stancerSendInvoiceMailModele(getDolGlobalString('STANCER_AUTO_MAIL_INVOICES_CB_MAILTYPE', ''), $object, 'BILL_CBSTART_SENTBYMAIL');
			}
		} else {
			$message = $langs->trans("Error on Stancer CB!");
			if ($userMessage) {
				setEventMessages($langs->trans("ErrorStancer") . " (21) " . $message, [],  'errors');
			}
			dol_syslog("stancerCBstartPay pay CB error save (1)" . json_encode($res), LOG_DEBUG);
			if ($userMessage != true && getDolGlobalString('STANCER_AUTO_MAIL_INVOICES_ERROR')) {
				dol_syslog("stancerCBstartPay pay CB error send mail error", LOG_DEBUG);
				stancerSendInvoiceMailModele(getDolGlobalString('STANCER_AUTO_MAIL_INVOICES_ERROR', ''), $object, 'BILL_CBERROR_SENTBYMAIL', 1);
			}
		}
	} catch (Exception $e) {
		// print "<p>Erreur : " . $e->getMessage() . "</p>";
		dol_syslog("stancerCBstartPay pay CB error(1)" . json_encode($e->getMessage()), LOG_DEBUG);
		// TODO gerer ce cas particulier:
		// Payment already exists, duplicate unique_id (paym_xxxx)
		$message = $langs->trans("Error on Stancer CB payment request :" . $e->getMessage());
		$stc = new Stancer($db);
		$stc->createEvent($object, "stancer_cb_start", $langs->trans("StancerPayCB"), $message);

		if ($userMessage) {
			setEventMessages($langs->trans("ErrorStancer") . " (22) " . $message, [],  'errors');
		}
	}
	return $returnCode;
}


/**
 * remove all duplicate keys from tag
 *
 * @param   string  $tag  tag to cleanup
 *
 * @return  string clean tag
 */
function stancerCleanUpDuplicate($tag)
{
	dol_syslog("stancerCleanUpDuplicate $tag");
	$tmptag = dolExplodeIntoArray($tag, '.', '=');
	ksort($tmptag);
	dol_syslog("stancerCleanUpDuplicate tmptag is " . json_encode($tmptag));
	$t = "";
	foreach ($tmptag as $k => $v) {
		if ($t != '') {
			$t .= '.';
		}
		$t .= $k . '=' . $v;
	}
	dol_syslog("stancerCleanUpDuplicate return $t");
	return ($t);
}

/**
 * test if arg is a TAG or not
 *
 * @param   string  $str  tag to check
 *
 * @return  bool true if is tag, false else
 */
function stancerCheckTag($str)
{
	if (strpos($str, '=') === false) {
		return false;
	}
	return true;
}

/**
 * Extract the customer socid from a Stancer tag (unique_id / order_id).
 *
 * The tag carries the authoritative fk_soc as CUS=<id> (e.g. "INV=4321.CUS=2313").
 * It is set when the payment URL is generated, so it is more reliable than the
 * stancer_account -> llx_societe_rib mapping (a same cust_xxx can be linked to
 * several Dolibarr thirdparties, and fetch() returns only the first match).
 *
 * Returns the socid as int if CUS=<id> is present AND points to an existing
 * (non-deleted) thirdparty in the current entity. Returns 0 otherwise.
 *
 * @param   string  $tag  tag string
 *
 * @return  int           socid > 0 if found and valid, 0 otherwise
 */
function stancerGetCustomerSocidFromTag($tag)
{
	global $db;

	if (empty($tag) || !stancerCheckTag($tag)) {
		return 0;
	}
	$parts = dolExplodeIntoArray($tag, '.', '=');
	if (!is_array($parts) || empty($parts['CUS'])) {
		return 0;
	}
	$socid = (int) $parts['CUS'];
	if ($socid <= 0) {
		return 0;
	}

	// Validate the thirdparty exists and belongs to the current entity, to
	// avoid writing a bogus id if the tag is malformed or stale.
	$sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "societe";
	$sql .= " WHERE rowid = " . ((int) $socid);
	$sql .= " AND entity IN (" . getEntity('societe') . ")";
	$resql = $db->query($sql);
	if (!$resql) {
		dol_syslog("stancerGetCustomerSocidFromTag sql error: " . $db->lasterror(), LOG_ERR);
		return 0;
	}
	$found = ($db->num_rows($resql) > 0);
	$db->free($resql);
	if (!$found) {
		dol_syslog("stancerGetCustomerSocidFromTag tag=$tag references missing socid=$socid", LOG_WARNING);
		return 0;
	}
	return $socid;
}

/**
 * create a tag from object
 *
 * @param   CommonObject  $object     object to use
 * @param   bool|int      $addUnique  Append a random UNIQ part, so two payments of the same object differ
 * @return  string        tag
 */
function stancerMakeTAG($object, $addUnique = false)
{
	global $db;
	// Signature must stay generic (the hook layer only knows CommonObject), but the
	// concrete classes that reach this code all declare the fields used below.
	'@phan-var Facture|Commande|Propal|Adherent|Don $object';
	dol_syslog("stancerMakeTAG call for " . $object->ref . " addunique = " . ($addUnique ? '1' : '0'));

	$tag = '';
	if ($object->element == 'facture') {
		$tag .= 'INV=' . $object->id;
	} elseif ($object->element == 'commande') {
		$tag .= 'ORD=' . $object->id;
	} elseif ($object->element == 'member') {
		//membres (association)
		//		$fulltag = 'MEM='.$member->id.'.DAT='.dol_print_date(dol_now(), '%Y%m%d%H%M%S');
		$tag = 'MEM=' . $object->id . '.DAT=' . dol_print_date(dol_now(), '%Y%m%d%H%M');
	} elseif ($object->element == 'don') {
		$tag = 'DON=' . $object->ref . '.DAT=' . dol_print_date(dol_now(), '%Y%m%d%H%M');
	} elseif ($object->element == 'propal') {
		$tag .= 'PRO=' . $object->id;
	}
	//if ($source == 'organizedeventregistration') {
	//		$fulltag = 'ATT='.$attendee->id.'.DAT='.dol_print_date(dol_now(), '%Y%m%d%H%M%S');
	//if ($source == 'boothlocation')
	//		$fulltag = 'BOO='.GETPOST("booth").'.DAT='.dol_print_date(dol_now(), '%Y%m%d%H%M%S');
	// }elseif($object->element == 'contrat') {
	//	$fulltag = 'COL='.$contractline->id.'.CON='.$contract->id.'.CUS='.$contract->thirdparty->id.'.DAT='.dol_print_date(dol_now(), '%Y%m%d%H%M%S');
	// }
	//	$FULLTAG .= ($FULLTAG ? '.' : '').'PM='.$paymentmethod;
	$socid = '';
	if (!empty($object->socid)) {
		$socid = $object->socid;
	} elseif (!empty($object->thirdparty->id)) {
		$socid = $object->thirdparty->id;
	}
	if (!empty($socid)) {
		$tag .= '.CUS=' . $socid;
	} else {
		dol_syslog("stancerMakeTAG warning, there is no soc id...", LOG_WARNING);
	}

	dol_syslog("stancerMakeTAG check is more than one payment for that invoice");
	//facture peut-être payée en plusieurs fois ? pour savoir ça il suffit de demander combien il reste à payer
	if ($object->element == 'facture') {
		$numPaiement = 0;
		$totalpaye = $object->getSommePaiement();
		$totalcreditnotes = $object->getSumCreditNotesUsed();
		$totaldeposits = $object->getSumDepositsUsed();
		$resteapayer = (float) price2num($object->total_ttc - $totalpaye - $totalcreditnotes - $totaldeposits, 'MT');
		if ($resteapayer != (float) price2num($object->total_ttc)) {
			dol_syslog("stancerMakeTAG invoice non full paid, add SEQ tag");
			$list = $object->getListOfPayments();
			$numPaiement = count($list);
			// print "<p>FACTURE : $ref reste à payer $resteapayer num=$numPaiement</p>";
		}
		if ($numPaiement > 0 && (strpos($tag, 'SEQ=') === false)) {
			$tag .= '.SEQ=' . $numPaiement;
		}
	}
	// disabled, too many risks of double pay invoice
	if ($addUnique) {
		dol_syslog("stancerMakeTAG : add uniq to tag");
		$tag = stancerCleanUpDuplicate($tag . '.UNIQ=' . rand(10, 99));
	} else {
		$tag = stancerCleanUpDuplicate($tag);
	}

	if (strlen($tag) < 36) {
		dol_syslog("stancerMakeTAG return $tag");
		return $tag;
	} else {
		dol_syslog("stancerMakeTAG error : TAG generator is to long (36 chars max) return tag cut to 36 char !", LOG_ERR);
		return substr($tag, 0, 36);
	}
}


/**
 * Generate the payment URL for a propal
 *
 * @param   Propal  $object  The propal object
 * @return  string           The payment URL
 */
function stancerGetPropalPaymentUrl($object)
{
	global $conf;

	$securekey = '';
	if (getDolGlobalString('PAYMENT_SECURITY_TOKEN_UNIQUE')) {
		$securekey = dol_hash($conf->global->PAYMENT_SECURITY_TOKEN . 'propalpayment' . $object->id . $object->ref, '2');
	} else {
		$securekey = getDolGlobalString('PAYMENT_SECURITY_TOKEN');
	}

	$url = dol_buildpath("/stancer/public/newpayment_propal.php", 2);
	// $url = DOL_MAIN_URL_ROOT . '/custom/stancer/public/newpayment.php';
	$url .= '?source=propal';
	$url .= '&ref=' . urlencode((string) $object->ref);
	if (!empty($securekey)) {
		$url .= '&securekey=' . urlencode($securekey);
	}

	return $url;
}



/**
 * check if a payment is already in progress
 *
 * @param   Facture  $object  dolibarr invoice
 *
 * @return  bool           true if a payment is in progress, else return false
 */
function stancerCheckIfPaymentInProgress($object)
{
	global $db, $conf;
	// Stancer API returns status as a string ('authorized', 'captured', 'capture_sent',
	// 'to_capture'). We compare strings here on purpose: comparing with the local INT
	// constants (STATUS_AUTHORIZED=1, STATUS_CAPTURED=2, ...) would never match and the
	// "live status" branch (return true) would only fire by accident via the fallback
	// at the bottom of this function.
	$listOfStatus = array(
		'authorized',
		'captured',
		'capture_sent',
		'to_capture',
	);

	if (empty($object) || empty($object->ref) || empty($object->id)) {
		dol_syslog("stancerCheckIfPaymentInProgress error object ref or id is emptyn return true just to avoid bigger problem", LOG_WARNING);
		return true;
	}

	dol_syslog("stancerCheckIfPaymentInProgress on object=" . $object->ref . ", id=" . $object->id);
	$sp = new Stancer_payments($db);
	// SAFETY: include grouped_invoice_ids in the lookup so a same-day SEPA-grouped
	// payment (unique_id 'GRP=<hash>.CUS=<id>', per-invoice ids in a separate
	// comma-separated column) is detected for ALL invoices of the group, not just
	// the one whose ref happens to fit in the truncated order_id. Without this,
	// the cron picks up the other invoices of the group as still unpaid and
	// re-triggers a payment -> double billing (the incident on 13/05 + 14/05).
	$sanitizedObjId = (int) $object->id;
	$sanitizedObjRef = $db->escape($object->ref);
	$customSql = "live_mode = '" . getDolGlobalString('STANCER_IS_PROD') . "'";
	$customSql .= " AND (";
	$customSql .= "unique_id LIKE '%INV=" . $sanitizedObjId . "'";
	$customSql .= " OR unique_id LIKE '%INV=" . $sanitizedObjId . ".%'";
	$customSql .= " OR order_id LIKE '%" . $sanitizedObjRef . "%'";
	$customSql .= " OR grouped_invoice_ids = '" . $sanitizedObjId . "'";
	$customSql .= " OR grouped_invoice_ids LIKE '" . $sanitizedObjId . ",%'";
	$customSql .= " OR grouped_invoice_ids LIKE '%," . $sanitizedObjId . "'";
	$customSql .= " OR grouped_invoice_ids LIKE '%," . $sanitizedObjId . ",%'";
	$customSql .= ")";
	$resSP = $sp->fetchAll('ASC', '', 0, 0, array('customsql' => $customSql));
	if (is_array($resSP) && count($resSP) > 0) {
		$stancerApi = StancerApi::getInstance();
		//dans cette liste il faut vérifier s'il y en a un qui est de type payé/paiement en cours
		foreach ($resSP as $key => $val) {
			$paymentData = $stancerApi->getPayment($val->stancer_id);
			if ($paymentData === false) {
				dol_syslog("stancerCheckIfPaymentInProgress error fetching payment: " . $stancerApi->error, LOG_INFO);
				continue;
			}
			$status = isset($paymentData['status']) ? $paymentData['status'] : '';
			// $sp->getLabelStatus($status) was wrong (it takes a mode int, not a status string,
			// and triggered "Undefined array key" on PHP 8). The raw API status is informative
			// enough for the log line.
			dol_syslog("stancerCheckIfPaymentInProgress ask Stancer API to get status of " . $val->stancer_id . " -> status=$status");
			if (in_array($status, $listOfStatus, true)) {
				dol_syslog("stancerCheckIfPaymentInProgress return true (live status from API)");
				return true;
			}
		}
		return true;
	}
	dol_syslog("stancerCheckIfPaymentInProgress return false");
	return false;
}

/**
 * Tell whether a Dolibarr object linked to a Stancer payment is still eligible
 * for Stancer processing: payment mode is CB or PRE AND bank account matches
 * STANCER_BANK_ACCOUNT_FOR_PAYMENTS.
 *
 * Used by the refresh cron to stop nagging when the merchant has manually
 * switched the linked invoice/order to a non-Stancer payment method (eg.
 * customer asks for a bank transfer after a CB failure). The matching
 * Stancer_payments row is set to STATUS_HIDDEN by the caller so subsequent
 * cron runs skip it entirely.
 *
 * Conservative: returns true for element types other than facture/commande
 * (don, propal, member...) for which we have no reliable Stancer fingerprint.
 *
 * @param  CommonObject|null  $obj  Object resolved by getObjectFromTag() or
 *                                  getObjectFromOrderID() (may be null).
 * @return bool                     true if still eligible, false otherwise.
 */
function stancerIsObjectStillEligibleForStancer($obj)
{
	if (empty($obj) || empty($obj->id)) {
		return false;
	}

	if (!in_array($obj->element, array('facture', 'commande'), true)) {
		return true;
	}
	// Past this point the element is a facture or a commande, both of which declare
	// the fields read below; the signature stays generic for the callers.
	'@phan-var Facture|Commande $obj';

	$ref = isset($obj->ref) ? $obj->ref : '?';
	$modeCode = isset($obj->mode_reglement_code) ? $obj->mode_reglement_code : '';
	if (!in_array($modeCode, array('CB', 'PRE'), true)) {
		dol_syslog("stancerIsObjectStillEligibleForStancer " . $obj->element . "#" . $obj->id . " (" . $ref . ") mode_reglement_code=" . $modeCode . " not CB/PRE, not eligible");
		return false;
	}

	$stancerBank = (int) getDolGlobalString('STANCER_BANK_ACCOUNT_FOR_PAYMENTS', '');
	$objBank = (int) (isset($obj->fk_account) ? $obj->fk_account : 0);
	if ($stancerBank > 0 && $objBank !== $stancerBank) {
		dol_syslog("stancerIsObjectStillEligibleForStancer " . $obj->element . "#" . $obj->id . " (" . $ref . ") fk_account=" . $objBank . " != STANCER_BANK_ACCOUNT_FOR_PAYMENTS=" . $stancerBank . ", not eligible");
		return false;
	}

	return true;
}

/**
 * Fake "enable" Stripe module to activate Dolibarr core gated by isModEnabled('stripe').
 * Historically this covered two cases on Dolibarr <= 17:
 *  - online payment link on invoice/propal/order cards (gated by
 *    isModEnabled('stripe'|'paypal'|'paybox') in getValidOnlinePaymentMethods())
 *  - "Carte de credit" table on societe/paymentmodes.php (gated by
 *    isModEnabled('stripe') in the $showcardpaymentmode block)
 *
 * Both extension points exist as proper hooks since Dolibarr 18:
 *  - getValidPayment hook in core/lib/payments.lib.php
 *  - printNewTable hook at the bottom of societe/paymentmodes.php (the core
 *    comment in that file literally mentions "list of CB card from Stancer
 *    Plugin for example", the hook was added for us).
 *
 * So on Dolibarr >= 18 the shim is dead code and stays a no-op. On Dolibarr <
 * 18 we still need the hack. The static caches the version test once per
 * request.
 *
 * @return void
 */
function stancerFakeStripeModuleEnable()
{
	static $needed = null;
	if ($needed === null) {
		$needed = version_compare(DOL_VERSION, '18.0.0', '<');
	}
	if (!$needed) {
		return;
	}
	global $conf;
	if (!isset($conf->stripe)) {
		$conf->stripe = new StancerFakeStripe();
	}

	$conf->stripe->enabled = true;
	$conf->modules['stripe'] = $conf->stripe;
}


/**
 * get all non paid invoices for a customer and with a specific payment mode
 *
 * @param   $customerID   customerID
 * @param   $late         late
 * @param   $paymentMode  CB|PRE
 *
 * @return              float total amount
 */
function stancerGetOutstandingBills($customerID, $late = 0, $paymentMode = "CB")
{
	global $conf, $lang, $db;

	// public function liste_array($shortlist = 0, $draft = 0, $excluser = '', $socid = 0, $limit = 0, $offset = 0, $sortfield = 'f.datef,f.rowid', $sortorder = 'DESC')

	$total = 0;
	$object = new Facture($db);
	$result = $object->liste_array(1, 0, null, $customerID, 0, 0);
	if (is_array($result) && (count($result) > 0)) {
		// print json_encode($result);
		foreach ($result as $id => $ref) {
			$obj = new Facture($db);
			$res = $obj->fetch($id);
			if ($res) {
				// print json_encode($obj);
				if (
					// @phan-suppress-next-line PhanDeprecatedProperty  $paye is the column Dolibarr 15..21 fills and still writes; status==2 also covers abandoned invoices
					$obj->paye == 0
					&& $obj->status != $object::STATUS_DRAFT    	// Not a draft
					&& $obj->status != $object::STATUS_ABANDONED	// Not abandoned
					&& $obj->status != $object::STATUS_CLOSED		// Not classified as paid
					&& $obj->status != $object::STATUS_CLOSED		// Not classified as paid
					&& $obj->mode_reglement_code == $paymentMode
				) {
					$total += $obj->total_ttc;
				}
			}
		}
	}
	return $total;
}

/**
 * rebuild pdf file if there is no file linked to object (case of mail with invoice see #19)
 *
 * @param   CommonObject  $object  facture to use
 * @return  void
 */
function stancerRegeneratePDFifNeeded(CommonObject $object)
{
	global $conf, $langs;
	$langs->load('products');

	$file = '';
	if (is_object($object)) {
		$objectdiroutput = $conf->facture->dir_output;
		$fileparams = dol_most_recent_file($objectdiroutput . '/' . $object->ref, preg_quote((string) $object->ref, '/') . '.*.pdf');
		if (empty($fileparams['fullname'])) {
			$result = $object->generateDocument($object->model_pdf, $langs);
		}
	}
}
