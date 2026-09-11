<?php
/* Copyright (C) 2023 Seigne Eric <eric.seigne@cap-rel.fr>
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
 * \file    stancer/class/actions_stancer.class.php
 * \ingroup stancer
 * \brief   Example hook overload.
 *
 * Put detailed description here.
 */

dol_include_once('/stancer/lib/stancer.lib.php');
require_once DOL_DOCUMENT_ROOT . '/fourn/class/paiementfourn.class.php';
require_once DOL_DOCUMENT_ROOT . '/fourn/class/fournisseur.facture.class.php';
include_once DOL_DOCUMENT_ROOT . '/societe/class/companypaymentmode.class.php';
require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';


/**
 * Class ActionsStancer
 */
class ActionsStancer
{
	/**
	 * @var DoliDB Database handler.
	 */
	public $db;

	/**
	 * @var string Error code (or message)
	 */
	public $error = '';

	/**
	 * @var array Errors
	 */
	public $errors = array();


	/**
	 * @var array Hook results. Propagated to $hookmanager->resArray for later reuse
	 */
	public $results = array();

	/**
	 * @var string String displayed by executeHook() immediately after return
	 */
	public $resprints;

	/**
	 * @var int        Priority of hook (50 is used if value is not defined).
	 *                 Set to 30 so the Stancer payment button is rendered before
	 *                 buttons from modules using the default priority (Mollie,
	 *                 Payzen, Stripe...) on Dolibarr's public/payment/newpayment.php.
	 *                 Lower number = button shown earlier on the page.
	 */
	public $priority = 30;

	/**
	 * list of context handled by that module
	 *
	 * @var array
	 */
	public $array_of_handled_context;

	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
		$this->array_of_handled_context = ['invoicecard', 'thirdpartybancard', 'ordercard', 'subscription', 'doncard', 'membercard', 'propalcard', 'invoicesuppliercard'];
	}


	/**
	 * Execute action
	 *
	 * @param  array        $parameters Array of parameters
	 * @param  CommonObject $object     The object to process (an invoice if you are in invoice module, a propale in propale's module, etc...)
	 * @param  string       $action     'add', 'update', 'view'
	 * @return int                             <0 if KO,
	 *                                           =0 if OK but we want to process standard actions too,
	 *                                            >0 if OK and we want to replace standard actions.
	 */
	public function getNomUrl($parameters, &$object, &$action)
	{
		global $db, $langs, $conf, $user;
		$this->resprints = '';
		return 0;
	}


	/**
	 * Overloading the formConfirm function : replacing the parent's function with the one below
	 *
	 * @param   array           $parameters     Hook metadatas (context, etc...)
	 * @param   CommonObject    $object         The object to process (an invoice if you are in invoice module, a propale in propale's module, etc...)
	 * @param   string          $action         Current action (if set). Generally create or edit or null
	 * @param   HookManager     $hookmanager    Hook manager propagated to allow calling another hook
	 * @return  int                             < 0 on error, 0 on success, 1 to replace standard code
	 */
	public function formConfirm($parameters, &$object, &$action, $hookmanager)
	{
		global $conf, $langs, $db;

		// print json_encode($object);exit;

		// dol_syslog("stancer formConfirm param = " . json_encode($parameters));
		// dol_syslog("stancer formConfirm object = " . json_encode($object));
		// dol_syslog("stancer formConfirm action = " . json_encode($action));

		// The three actions handled below are only ever triggered from the invoice
		// card (buttons built by addMoreActionsButtons()), where the core always
		// hands us a Facture. Anything else means a foreign caller: log and skip.
		if (!($object instanceof Facture)) {
			if (in_array($action, array('stancerCard', 'stancerSEPA', 'stancerRefund'), true)) {
				dol_syslog("stancer formConfirm: action " . $action . " received with " . (is_object($object) ? get_class($object) : gettype($object)) . " instead of Facture, skip", LOG_ERR);
			}
			return 0;
		}

		if ($action == "stancerCard" && getDolGlobalString('STANCER_ENABLE_CB')) {
			$formconfirm = '';
			$form = new Form($object->db);

			$qrdata = stancerQRCodePayment(getOnlinePaymentUrl(0, 'invoice', (string) $object->ref));
			$message = "<img style='float:right; background: white' src='data:image/png;base64," . base64_encode($qrdata) . "' /> <br />";
			$message .= "<p>" . $langs->trans('StancerPopupCB', $object->ref) . "</p>"; //. showOnlinePaymentUrl('invoice', $object->ref);

			$urlPayment = getOnlinePaymentUrl(0, 'invoice', (string) $object->ref);
			$message .= '<div class=""><input type="text" id="onlinepaymenturl" class="quatrevingtpercentminusx" value="' . $urlPayment . '">';
			$message .= '<a class="" href="' . $urlPayment . '" target="_blank" rel="noopener noreferrer">' . img_picto('', 'globe', 'class="paddingleft"') . '</a>';
			$message .= '</div>';


			//une cb est enregistrée pour ce client on propose un paiement direct ?
			$companypaymentmode = new CompanyPaymentModeStancer($db);
			// fetch() tests each argument for truthiness, so '' and 0 behave exactly
			// like the null values used before while matching the declared types.
			$res = $companypaymentmode->fetch(0, '', 0, '', " AND type = 'card' AND label LIKE 'stancer-card%' AND stancer_object_ref <> '' AND fk_soc = " . ((int) $object->socid));
			if ($res) {
				$message .= '<p>&nbsp;</p><p>' . $langs->trans('StancerPopupCBexists') . '</p>';
				$formconfirm = $form->formconfirm($_SERVER["PHP_SELF"] . '?id=' . $object->id, $langs->trans('Stancer'), $message, 'confirm_stancertakecbpayment', '', 'no', 1, 340, 480);
			} else {
				$formconfirm = $form->formconfirm($_SERVER["PHP_SELF"] . '?id=' . $object->id, $langs->trans('Stancer'), $message, 'confirm_stancerfetch', '', 'no', 1, 340, 480);
			}
			$this->results = array('Stancer' => 999);
			$this->resprints = $formconfirm;
			return 0;                                    // or return 1 to replace standard code
		}
		if ($action == "stancerSEPA") {
			//Vérification que nous n'avons pas déjà un SEPA en cours .... si c'est le cas affichage du formulaire de confirmation
			$tag = stancerMakeTAG($object);
			$sp = new Stancer_payments($this->db);
			$resSP = $sp->fetch(0, null, null, $tag);
			$askFormConfirm = false;

			//sepa deja en cours
			if ($resSP && $sp->method == 'sepa') {
				// print(json_encode($sp));exit;
				//Try to fix #5: risque d'avoir un double prelevement
				//donc on ne demande confirmation que si le prélèvement a été fait il y a plus de ... 10 minutes par exemple
				if ((time() - $sp->tms) > 600) {
					$askFormConfirm = true;
				}
			}

			//cb en cours ou ...
			if ($resSP && $sp->method == 'card') {
				// print(json_encode($sp));exit;
				//Try to fix #5: risque d'avoir un double paiement
				//donc on ne demande confirmation que si le prélèvement a été fait il y a plus de ... 10 minutes par exemple
				if ((time() - $sp->tms) > 600) {
					$askFormConfirm = true;
				}
			}

			if ($askFormConfirm) {
				dol_syslog("stancer SEPA error (transaction already started less than 600 sec before)", LOG_DEBUG);
				$url = "<a href='" . dol_buildpath("/stancer/stancer_payments_list.php", 2) . "?search_stancer_id=" . $sp->stancer_id . "&token=" . newToken() . "'>";
				$message  = $langs->transnoentitiesnoconv("StancerSEPAalreadyInProgress", $url, '</a>');
				$message .= $langs->transnoentitiesnoconv("StancerSEPAalreadyInProgressForceClicOnYes");

				$formconfirm = '';
				$form = new Form($object->db);
				$formconfirm = $form->formconfirm($_SERVER["PHP_SELF"] . '?id=' . $object->id, $langs->trans('StancerSEPA'), $message, 'confirm_stancerSEPA', '', 'no', 1, 200);
				$this->results = array('StancerSEPA' => 999);
				$this->resprints = $formconfirm;

				// or return 1 to replace standard code
				return 0;
			}
		}

		// Refund confirmation for credit notes
		if ($action == "stancerRefund" && $object->type == Facture::TYPE_CREDIT_NOTE) {
			$formconfirm = '';
			$form = new Form($object->db);

			$amountToRefund = abs((float) $object->total_ttc);
			$message = $langs->trans('StancerRefundConfirmMessage', price($amountToRefund), $object->multicurrency_code ?: 'EUR');

			// Show original invoice info
			if (!empty($object->fk_facture_source)) {
				$originalInvoice = new Facture($this->db);
				if ($originalInvoice->fetch($object->fk_facture_source) > 0) {
					$message .= '<br><br>' . $langs->trans('StancerRefundOriginalInvoice', $originalInvoice->ref);
				}
			}

			$formconfirm = $form->formconfirm($_SERVER["PHP_SELF"] . '?id=' . $object->id, $langs->trans('StancerRefundTitle'), $message, 'confirm_stancerRefund', '', 'no', 1, 300);
			$this->results = array('StancerRefund' => 999);
			$this->resprints = $formconfirm;
			return 0;
		}

		// No confirmation form to build for this action: let the standard code run.
		return 0;
	}

	/**
	 * Overloading the formObjectOptions function: replacement of the parent's function
	 *
	 * @param	array			$parameters		Hook metadata (context, etc...)
	 * @param	CommonObject	$object			The object to process
	 * @param	string			$action			Current action (if set), replaced by the hook if needed
	 * @param	HookManager		$hookmanager	Hook manager propagated to allow calling another hook
	 * @return	int								0 on success, < 0 on error, > 0 to replace the standard code
	 */
	public function formObjectOptions($parameters, &$object, &$action, $hookmanager)
	{
		global $conf, $user, $langs;
		$error = 0; // Error counter
		// print json_encode($parameters);exit;

		if ($parameters['currentcontext'] == 'invoicecard') {
			if ($object->status != Facture::STATUS_CLOSED) {
				$sp = new Stancer_payments($this->db);
				//Vérification que nous n'avons pas déjà un paiement en cours
				$resSP = $sp->fetchAll('ASC', '', 0, 0, array('customsql' => "live_mode = '" . getDolGlobalString('STANCER_IS_PROD') . "' AND (unique_id LIKE '%INV=" . $object->id . "' OR unique_id LIKE '%INV=" . $object->id . ".%')"));
				// print json_encode($object->ref) . " == " . json_encode($resSP);
				if (!empty($resSP)) {
					$messages = array();
					//si plusieurs paiements sont liés création d'un tableau [date => valeur]
					foreach ($resSP as $key => $val) {
						$url = "https://manage.stancer.com/fr/details-de-paiement?id=" . $val->stancer_id;
						$paymentLine = "<a href='" . $url . "'>" . Stancer_payments::$tab_status[$val->status] . "</a> (" . dol_print_date($val->date_bank) . ")";

						// Check for dispute/rejection on this payment
						$sqlDispute = "SELECT dispute_id, status, response, date_bank, tms FROM " . MAIN_DB_PREFIX . "stancer_stancer_disputes";
						$sqlDispute .= " WHERE payment_id = '" . $this->db->escape($val->stancer_id) . "'";
						$sqlDispute .= " ORDER BY tms DESC LIMIT 1";
						$resDispute = $this->db->query($sqlDispute);
						if ($resDispute) {
							$objDispute = $this->db->fetch_object($resDispute);
							if ($objDispute) {
								$disputeDate = !empty($objDispute->date_bank) ? $objDispute->date_bank : $objDispute->tms;
								$disputeInfo = $objDispute->status;
								if (!empty($objDispute->response)) {
									$disputeInfo .= ' ' . $objDispute->response;
								}
								$paymentLine .= ", <b style='color:#cc0000;'>" . $disputeInfo . "</b> (" . dol_print_date($disputeDate) . ")";
							}
						}

						$messages[$val->tms] = $paymentLine;
					}
					ksort($messages);

					//Note: gérer les états spécifiques ?
					$search = $langs->transnoentitiesnoconv("AlreadyPaidNoCreditNotesNoDeposits");
					$replace = $langs->transnoentitiesnoconv("StancerPaymentInProgress", "<a href='https://doc.cap-rel.fr/projet_stancer/tache_planifiee'>", "</a>", implode(",", $messages));
					print "<script language='javascript'>";
					print '$( document ).ready(function() {';
					print '$("span").each(function() {';
					print 'var text = $(this).text();';
					print 'text2 = text.replace("' . $search . '", "<span style=\"float:left; opacity: unset; text-align: justify;\">' . $replace . '</span>");';
					print 'if(text != text2) {';
					print '$(this).html(text2);';
					print '}';
					print '});';
					print '});';
					print "</script>";
				}
			}

			// Make Stancer payment ids (paym_xxx) clickable in the core "Payments"
			// table. Dolibarr core renders the id as plain text inside a
			// td.tdoverflowmax80 (full id is in the DOM, only CSS-clipped). This runs
			// for any invoice (paid or not), independently of the in-progress block above.
			$stancerLinkTitle = dol_escape_js($langs->transnoentitiesnoconv('ShowInStancer'));
			print "<script language='javascript'>\n";
			print '$(document).ready(function() {' . "\n";
			print '  $("td.tdoverflowmax80").each(function() {' . "\n";
			print '    var $td = $(this);' . "\n";
			print '    if ($td.find("a.stancer-paym-link").length) return;' . "\n";
			print '    var m = $td.text().match(/paym_[A-Za-z0-9]+/);' . "\n";
			print '    if (!m) return;' . "\n";
			print '    var id = m[0];' . "\n";
			print '    var url = "https://manage.stancer.com/fr/details-de-paiement?id=" + id;' . "\n";
			print '    var link = "<a class=\"stancer-paym-link\" href=\"" + url + "\" target=\"_stancer\" title=\"' . $stancerLinkTitle . '\">" + id + "</a>";' . "\n";
			print '    $td.html($td.html().replace(id, link));' . "\n";
			print '  });' . "\n";
			print '});' . "\n";
			print "</script>\n";
		}
		// elseif ($parameters['currentcontext'] == 'bankline') {
		// 	print "<tr><td>Insertion hook</td></tr>";
		// }
		return $error;
	}

	/**
	 * Hook called at the bottom of societe/paymentmodes.php (since Dolibarr 18).
	 * Renders a standalone table listing the Stancer cards stored for the
	 * current third party. The core file used to display them through the
	 * "Carte de credit" block gated by isModEnabled('stripe'), which we faked
	 * via stancerFakeStripeModuleEnable() on older versions. With this hook we
	 * stop pretending Stripe is active and own the rendering.
	 *
	 * BANs (type='ban') are NOT rendered here on purpose: the core paymentmodes
	 * page already lists every llx_societe_rib row of type='ban' for the
	 * customer, regardless of any module flag - including SEPA mandates we
	 * store ourselves. Re-rendering them would create duplicates.
	 *
	 * @param  array        $parameters  Hook metadatas (context, etc...)
	 * @param  CommonObject $object      The thirdparty (Societe).
	 * @param  string       $action      Current action (unused here).
	 * @param  HookManager  $hookmanager Hook manager propagated to allow calling another hook
	 * @return int                       0 on success
	 */
	public function printNewTable($parameters, &$object, &$action, $hookmanager)
	{
		global $langs;

		if (($parameters['currentcontext'] ?? '') !== 'thirdpartybancard') {
			return 0;
		}
		if (!isModEnabled('stancer')) {
			return 0;
		}
		if (!is_object($object) || empty($object->id)) {
			return 0;
		}

		$langs->loadLangs(array('stancer@stancer', 'banks', 'companies'));

		$sql = "SELECT rowid, label, type, last_four, exp_date_month, exp_date_year,";
		$sql .= " proprio, country_code, default_rib, tms, stancer_object_ref, card_type";
		$sql .= " FROM " . MAIN_DB_PREFIX . "societe_rib";
		$sql .= " WHERE fk_soc = " . ((int) $object->id);
		$sql .= " AND type = 'card'";
		$sql .= " AND label LIKE 'stancer-card%'";
		$sql .= " ORDER BY default_rib DESC, tms DESC";

		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog("stancer printNewTable sql error: " . $this->db->lasterror(), LOG_ERR);
			return 0;
		}

		$num = $this->db->num_rows($resql);
		if ($num == 0) {
			$this->db->free($resql);
			return 0;
		}

		$out = '';
		$out .= load_fiche_titre($langs->trans('StancerSavedCardsTitle'), '', 'fa-credit-card');
		$out .= '<!-- List of Stancer cards -->' . "\n";
		$out .= '<div class="div-table-responsive-no-min">';
		$out .= '<table class="liste centpercent">' . "\n";
		$out .= '<tr class="liste_titre">';
		$out .= '<td>' . $langs->trans('Label') . '</td>';
		$out .= '<td>' . $langs->trans('ExternalSystemID') . '</td>';
		$out .= '<td>' . $langs->trans('Type') . '</td>';
		$out .= '<td>' . $langs->trans('Informations') . '</td>';
		$out .= '<td class="center">' . $langs->trans('Default') . '</td>';
		$out .= '<td>' . $langs->trans('DateModification') . '</td>';
		$out .= '</tr>' . "\n";

		$isProd = (getDolGlobalString('STANCER_IS_PROD', '0') === '1');
		while ($obj = $this->db->fetch_object($resql)) {
			$out .= '<tr class="oddeven" data-rowid="' . ((int) $obj->rowid) . '">';
			// Label
			$out .= '<td class="tdoverflowmax150" title="' . dol_escape_htmltag($obj->label) . '">';
			$out .= dol_escape_htmltag($obj->label);
			$out .= '</td>';
			// External ref + link to manage.stancer.com
			$out .= '<td class="tdoverflowmax200">';
			if (!empty($obj->stancer_object_ref)) {
				$url = 'https://manage.stancer.com/' . ($isProd ? '' : 'sandbox/');
				$url .= 'fr/details-de-carte?id=' . urlencode($obj->stancer_object_ref);
				$out .= '<a href="' . $url . '" target="_stancer" title="';
				$out .= dol_escape_htmltag($langs->trans('StancerShowInDashboard')) . '">';
				$out .= img_picto('', 'globe', 'class="paddingright"');
				$out .= '</a>';
				$out .= dol_escape_htmltag($obj->stancer_object_ref);
			}
			$out .= '</td>';
			// Type (brand)
			$out .= '<td>';
			$out .= img_credit_card(!empty($obj->card_type) ? $obj->card_type : $obj->type);
			$out .= '</td>';
			// Information: owner + last4 + expiry
			$out .= '<td class="minwidth100">';
			if (!empty($obj->proprio)) {
				$out .= '<span class="opacitymedium">' . dol_escape_htmltag($obj->proprio) . '</span><br>';
			}
			if (!empty($obj->last_four)) {
				$out .= '....' . dol_escape_htmltag($obj->last_four);
			}
			if (!empty($obj->exp_date_month) || !empty($obj->exp_date_year)) {
				$out .= ' - ' . sprintf('%02d', (int) $obj->exp_date_month) . '/' . dol_escape_htmltag((string) $obj->exp_date_year);
			}
			$out .= '</td>';
			// Default
			$out .= '<td class="center">';
			$out .= img_picto($langs->trans('Default'), empty($obj->default_rib) ? 'off' : 'on');
			$out .= '</td>';
			// Date
			$out .= '<td>';
			$out .= dol_print_date($this->db->jdate($obj->tms), 'dayhour');
			$out .= '</td>';
			$out .= '</tr>' . "\n";
		}
		$out .= '</table>';
		$out .= '</div>';

		$this->db->free($resql);

		$this->resprints = $out;
		return 0;
	}

	/**
	 * Overloading the doActions function : replacing the parent's function with the one below
	 *
	 * @param  array        $parameters  Hook metadatas (context, etc...)
	 * @param  CommonObject $object      The object to process (an invoice if you are in invoice module, a propale in propale's module, etc...)
	 * @param  string       $action      Current action (if set). Generally create or edit or null
	 * @param  HookManager  $hookmanager Hook manager propagated to allow calling another hook
	 * @return int                             < 0 on error, 0 on success, 1 to replace standard code
	 */
	public function doActions($parameters, &$object, &$action, $hookmanager)
	{
		global $conf, $user, $langs, $db;

		$error = 0; // Error counter

		// DEBUG FORCE LOG
		$this->stancerLog("doActions ENTRY: action=$action, currentcontext=" . ($parameters['currentcontext'] ?? 'NULL'), LOG_ERR);

		// DEBUG for stancerFindPaymentInvoice
		if ($action == 'stancerFindPaymentInvoice') {
			dol_syslog("stancer doActions: stancerFindPaymentInvoice action detected, currentcontext=" . ($parameters['currentcontext'] ?? 'NULL'), LOG_DEBUG);
		}
		if (in_array($parameters['currentcontext'], $this->array_of_handled_context)) {
			// Skip if the object's bank account is not the one managed by Stancer
			$stancerBankAccount = getDolGlobalString('STANCER_BANK_ACCOUNT_FOR_PAYMENTS', '');
			if (!empty($stancerBankAccount) && is_object($object) && !empty($object->fk_account) && $object->fk_account != $stancerBankAccount) {
				dol_syslog("stancer doActions: skipping, object fk_account=".$object->fk_account." != STANCER_BANK_ACCOUNT_FOR_PAYMENTS=".$stancerBankAccount, LOG_DEBUG);
				return 0;
			}

			stancerFakeStripeModuleEnable();
			if ($action == "confirm_stancerSEPA") {
				stancerSEPAstartPay($object, true, 0, 1);
			}

			if ($action == "stancerSEPA") {
				stancerSEPAstartPay($object);
			}

			//force le paiement CB
			if ($action == "confirm_stancertakecbpayment") {
				stancerCBstartPay($object, true, 0, 1);
			}

			// Stancer refund for credit notes
			if ($action == "confirm_stancerRefund" && $object instanceof Facture && $object->type == Facture::TYPE_CREDIT_NOTE) {
				dol_syslog("stancer doActions confirm_stancerRefund for credit note id=" . $object->id);

				// Find the original invoice
				if (!empty($object->fk_facture_source)) {
					$originalInvoice = new Facture($this->db);
					if ($originalInvoice->fetch($object->fk_facture_source) > 0) {
						// Find the Stancer payment
						$sp = new Stancer_payments($this->db);
						$resSP = $sp->fetchAll('DESC', 't.rowid', 1, 0, array(
							'customsql' => "live_mode = '" . getDolGlobalString('STANCER_IS_PROD', '0') . "' AND status = '" . Stancer_payments::STATUS_CAPTURED . "' AND (unique_id LIKE '%INV=" . ((int) $originalInvoice->id) . "' OR unique_id LIKE '%INV=" . ((int) $originalInvoice->id) . ".%' OR order_id LIKE '%" . $this->db->escape($originalInvoice->ref) . "%')"
						));

						if (is_array($resSP) && count($resSP) > 0) {
							$stancerPayment = reset($resSP);
							// Amount in cents (credit note amount is negative, we need positive)
							$amountCents = StancerApi::toCents(abs((float) $object->total_ttc));

							$result = stancerCreateRefund($stancerPayment->stancer_id, $amountCents);

							if (empty($result->error)) {
								setEventMessage($langs->trans('StancerRefundSuccess', $result->refund_id), 'mesgs');
							} else {
								setEventMessage($langs->trans('StancerRefundError', $result->error), 'errors');
								$error++;
							}
						} else {
							setEventMessage($langs->trans('StancerRefundNoPaymentFound'), 'errors');
							$error++;
						}
					}
				}
			}
		}


		// print "<p>" . json_encode($parameters) . "</p>";
		// $conf->stripe->enabled = false;

		// Handle supplier invoice context
		if ($parameters['currentcontext'] == 'invoicesuppliercard') {
			dol_syslog("stancer doActions: invoicesuppliercard context matched, action=$action", LOG_DEBUG);
			if ($action == 'stancerFindPaymentInvoice') {
				// stancerFindPaymentInvoiceAction() reads supplier invoice fields
				// ($date, getSommePaiement()...), so only run it on a real one.
				if ($object instanceof FactureFournisseur) {
					dol_syslog("stancer doActions: calling stancerFindPaymentInvoiceAction", LOG_DEBUG);
					$this->stancerFindPaymentInvoiceAction($object, $user, $error);
					dol_syslog("stancer doActions: stancerFindPaymentInvoiceAction completed, error=$error", LOG_DEBUG);
				} else {
					dol_syslog("stancer doActions: stancerFindPaymentInvoice ignored, object is " . (is_object($object) ? get_class($object) : gettype($object)) . " instead of FactureFournisseur", LOG_ERR);
				}
			}
		} else {
			// DEBUG: Log why we didn't enter the invoicesuppliercard block
			if ($action == 'stancerFindPaymentInvoice') {
				dol_syslog("stancer doActions: stancerFindPaymentInvoice action but context is '" . ($parameters['currentcontext'] ?? 'NULL') . "' instead of 'invoicesuppliercard'", LOG_WARNING);
			}
		}

		if (!$error) {
			$this->results = array('myreturn' => 999);
			$this->resprints = '<p>A text to show</p>';
			return 0; // or return 1 to replace standard code
		} else {
			$this->errors[] = 'Error message';
			return -1;
		}
	}

	/**
	 * Print Stancer Payment Button on screen.
	 *
	 * @param  array       $parameters  Array of parameters
	 * @param  Object      $object      Object output
	 * @param  string      $action      'add', 'update', 'view'
	 * @param  HookManager $hookmanager Hook manager propagated to allow calling another hook
	 * @return int                               < 0 on error, 0 on success, 1 to replace standard code
	 */
	public function doAddButton($parameters, &$object, &$action, $hookmanager)
	{
		global $conf, $langs;
		$langs->loadLangs(array("stancer@stancer"));

		$error = 0; // Error counter.
		// $source = GETPOST("s", 'alpha') ? GETPOST("s", 'alpha') : GETPOST("source", 'alpha');
		$tag = (empty($parameters['tag']) ? GETPOST("ref", 'alpha') : $parameters['tag']);
		$source = (empty($parameters['s']) ? GETPOST("source", 'alpha') : $parameters['s']);
		$securekey = (empty($parameters['securekey']) ? GETPOST("securekey", 'alpha') : $parameters['securekey']);
		// print json_encode($source);exit;

		$listOfHandledSources = ['invoice', 'order', 'donation', 'member', 'membersubscription', 'propal'];

		dol_syslog("stancer HOOK doAddButton tag was $tag");
		$tag = stancerMakeTAG($object);
		dol_syslog("stancer doAddButton new tag is $tag, context is " . $parameters['currentcontext']);

		if (getDolGlobalString('STANCER_ENABLE_CB', '') == '') {
			dol_syslog("stancer doAddButton stancer CB payment is not enabled");
		}

		if (in_array($parameters['currentcontext'], ['newpayment']) && getDolGlobalString('STANCER_ENABLE_CB')) {
			// stancerFakeStripeModuleEnable();

			$paymentmethod = $parameters['paymentmethod'];
			// print json_encode($parameters);exit;
			dol_syslog("stancer doAddButton paymentmethod is ($paymentmethod)");

			if ((empty($paymentmethod) || $paymentmethod == 'stancer') && ! empty($conf->stancer->enabled)) {
				dol_syslog("stancer doAddButton inject stancer payment method");
				//$sp = new Stancer_payments($db);


				//one more check : if an old stancer payment exists, decide whether to inject stancer payment button
				//SELECT * FROM `llx_stancer_stancer_payments` WHERE `unique_id` LIKE '%7327%' ORDER BY `rowid` DESC
				$sql = "SELECT count(*) as count FROM " . MAIN_DB_PREFIX . "stancer_stancer_payments WHERE unique_id='" . $this->db->escape($tag) . "'";
				if (getDolGlobalString('STANCER_CB_ALLOW_RETRY')) {
					// When retry is allowed, only block if a successful payment (captured) already exists
					$sql .= " AND status = '" . Stancer_payments::STATUS_CAPTURED . "'";
				}
				$resql = $this->db->query($sql);
				if ($resql) {
					$obj = $this->db->fetch_object($resql);
					if ($obj->count > 0) {
						dol_syslog("stancer doAddButton this one was already done on stancer (reject or anything else) do not display stancer button");
						return -1;
					}
				}



				// print json_encode($object);exit;

				// if (getDolGlobalString('STANCER_CTX_MODE','') == 'TEST' || GETPOST('forcesandbox', 'int')) { // We can force sandbox with param 'forcesandbox'.
				//     dol_htmloutput_mesg($langs->trans('STANCER_SANDBOX_MESSAGE'), '', 'warning');
				// }

				// if (empty($conf->banque->enabled) || getDolGlobalString('STANCER_DOLIBARR_BANK_ACCOUNT','') == '' || (getDolGlobalString('STANCER_DOLIBARR_BANK_ACCOUNT','') == -1)) {
				//     dol_htmloutput_mesg($langs->trans('STANCER_WARNING_BANK'), '', 'warning');
				// }


				//check if that customer exists on stancer and/or if prereq are ok (mail / phone)
				// print "<p>Debug eric: " . json_encode($object) . "</p>";
				if ($object->element == 'member') {
					$errorStancer = 0;
					if (substr($object->phone, 0, 1) != '+') {
						$errorStancer++;
					}
					if (strpos($object->email, '@') === false) {
						$errorStancer++;
					}
					if ($errorStancer == 2) {
						$error++;
						print '<div class="warning"><span class="fa fa-warning"> </span> <span class="clear"> ' . $langs->trans("StancerCompanyMailOrPhoneNewPayment") . '</span></div>';
					}
				} else {
					$societe = new Societe($this->db);
					$socresult = $societe->fetch($object->socid);
					if ($socresult) {
						// print "<p>Debug eric: " . json_encode($societe) . "</p>";
						$errorStancer = 0;
						if (substr($societe->phone, 0, 1) != '+') {
							$errorStancer++;
						}
						if (strpos($societe->email, '@') === false) {
							$errorStancer++;
						}
						if ($errorStancer == 2) {
							$error++;
							print '<div class="warning"><span class="fa fa-warning"> </span> <span class="clear"> ' . $langs->trans("StancerCompanyMailOrPhoneNewPayment") . '</span></div>';
						}
					}
				}

				if (empty($error) && in_array($source, $listOfHandledSources)) {
					$result =  '<br>';

					//cond_reglement_code
					//race condition for order with partial payment
					$btnLabel = "";
					if (
						$source == "order"
						&& getDolGlobalString('STANCER_CB_ORDER_PARTIAL_PAY') != ''
						&& isset($object->deposit_percent) && $object->deposit_percent > 0 && $object->deposit_percent < 100
					) {
						$payAmount = ($object->total_ttc * $object->deposit_percent / 100);
						$btnLabel = $langs->trans('STANCER_PAY_BUTTON_DEPOSIT', $object->deposit_percent, price($payAmount, 0, $langs, 1, -1, -1, $conf->currency));
					} else {
						$payAmount = $object->total_ttc;
						$btnLabel = $langs->trans("STANCER_PAY_BUTTON_MESSAGE");
					}
					//
					$result .= '<div class="stancerbuttonpayment butAction" id="div_dopayment_stancer" style="margin-bottom: 1em;">';
					$result .= '<span class="fa fa-credit-card"></span>';
					$result .= '<input type="hidden" name="tag" value="' . $tag . '">';
					$result .= '<input type="hidden" name="source" value="' . $source . '">';
					$result .= '<input class="stancerbuttonpayment" type="button" id="dopayment_stancer" name="dopayment_stancer" value="' . $langs->trans("STANCER_PAY_BUTTON") . '">';
					$result .= '<br>';
					$result .= '<span class="buttonpaymentsmall">' . $btnLabel . '</span>';
					$result .= '</div>';
					$result .= '<script>
                                    $( document ).ready(function() {
                                        $("#div_dopayment_stancer").click(function() {
                                            $("#dopayment_stancer").click();
                                        });
                                        $("#dopayment_stancer").click(function(e) {
											$("#dolpaymentform").attr("action", "' . DOL_MAIN_URL_ROOT . '/custom/stancer/public/newpayment.php");
                                            $("#div_dopayment_stancer").css( \'cursor\', \'wait\' );
											$("#dolpaymentform").submit();
                                            e.stopPropagation();
                                            return true;
                                        });
                                    });
                                </script>
                                ';

					dol_syslog("stancer doAddButton ready to inject html ...");
					// Use print directly: Dolibarr's "addreplace" hook contract has each
					// `return 1` overwrite the previous module's $resprints, so two PSP
					// modules cannot coexist via $this->resprints. The Mollie module and
					// other PSPs in the Dolibarr ecosystem also use print() for the same
					// reason. With print(), execution order driven by hook priority
					// dictates the visual button order on the page.
					print $result;
				}
				dol_syslog("stancer HOOK RETURN 1 ...");
				return 1;
			}
		}
		if (! $error) {
			return 0; // Or return 1 to replace standard code.
		}

		$this->errors[] = 'Error message';
		return -1;
	}


	/**
	 * Set Stancer as a valid payment.
	 *
	 * @param  array       $parameters  Array of parameters
	 * @param  Object      $object      Object output
	 * @param  string      $action      'add', 'update', 'view'
	 * @param  HookManager $hookmanager Hook manager propagated to allow calling another hook
	 * @return int                            < 0 on error, 0 on success, 1 to replace standard code
	 */
	public function doValidatePayment($parameters, &$object, &$action, $hookmanager)
	{
		global $conf, $langs;
		$langs->loadLangs(array("stancer@stancer"));

		if (in_array($parameters['currentcontext'], array('newpayment'))) { // Do something only for the context 'somecontext1' or 'somecontext2'.
			$paymentmethod = $parameters['paymentmethod'];
			if ((empty($paymentmethod) || $paymentmethod == 'stancer') && ! empty($conf->stancer->enabled)) {
				$parameters['validpaymentmethod']['stancer'] = 'valid';
			}
		}

		return 0;
	}

	/**
	 * Hook called by getValidOnlinePaymentMethods() in core/lib/payments.lib.php.
	 * Declares Stancer as a valid online payment method so the core displays the
	 * online payment link on invoice/propal/order cards without faking Stripe.
	 *
	 * @param  array       $parameters  Hook metadatas (paymentmethod, mode, validpaymentmethod, currentcontext)
	 * @param  Object      $object      Object output
	 * @param  string      $action      'add', 'update', 'view'
	 * @param  HookManager $hookmanager Hook manager propagated to allow calling another hook
	 * @return int                      < 0 on error, 0 on success, 1 to replace standard code
	 */
	public function getValidPayment($parameters, &$object, &$action, $hookmanager)
	{
		global $langs;

		$validpaymentmethod = array();

		if (array_key_exists('paymentmethod', $parameters)
			&& (empty($parameters['paymentmethod']) || $parameters['paymentmethod'] == 'stancer')
			&& isModEnabled('stancer')) {
			$langs->loadLangs(array("stancer@stancer"));
			if (!empty($parameters['mode'])) {
				$validpaymentmethod['stancer'] = array('label' => 'Stancer', 'status' => 'valid');
			} else {
				$validpaymentmethod['stancer'] = 'valid';
			}
		}

		if (!empty($validpaymentmethod)) {
			$this->results['validpaymentmethod'] = $validpaymentmethod;
		}
		return 0;
	}


	/**
	 * Add online payment link in document section for propals (unused, kept for reference)
	 *
	 * @param  array       $parameters  Array of parameters
	 * @param  Object      $object      Object output
	 * @param  string      $action      'add', 'update', 'view'
	 * @param  HookManager $hookmanager Hook manager propagated to allow calling another hook
	 * @return int                             < 0 on error, 0 on success, 1 to replace standard code
	 */
	public function formBuilddocOptions($parameters, &$object, &$action, $hookmanager)
	{
		return 0;
	}

	/**
	 * Add online payment link after linked objects block for propals
	 *
	 * @param  array       $parameters  Array of parameters
	 * @param  Object      $object      Object output
	 * @param  string      $action      'add', 'update', 'view'
	 * @param  HookManager $hookmanager Hook manager propagated to allow calling another hook
	 * @return int                             < 0 on error, 0 on success, 1 to replace standard code
	 */
	public function showLinkedObjectBlock($parameters, &$object, &$action, $hookmanager)
	{
		global $conf, $langs;
		$langs->loadLangs(array("stancer@stancer"));

		if ($parameters['currentcontext'] == 'propalcard') {
			require_once DOL_DOCUMENT_ROOT . '/comm/propal/class/propal.class.php';

			// Only for validated propals
			if ($object->statut == Propal::STATUS_VALIDATED || $object->statut == Propal::STATUS_SIGNED) {
				if (getDolGlobalString('STANCER_ENABLE_CB')) {
					if ($object->thirdparty->email) {
						$paymentUrl = stancerGetPropalPaymentUrl($object);
						print '<br><!-- Stancer payment link -->';
						print '<div class="titre inline-block">' . $langs->trans("StancerPropalPaymentLink") . '</div>';
						print '<div class="url">';
						print '<input type="text" id="stancerpaymenturl" class="quatrevingtpercentminusx" value="' . dol_escape_htmltag($paymentUrl) . '">';
						print ' <a href="' . dol_escape_htmltag($paymentUrl) . '" target="_blank" rel="noopener noreferrer">' . img_picto('', 'globe', 'class="paddingleft"') . '</a>';
						print ' <a href="javascript:void(0);" onclick="navigator.clipboard.writeText(document.getElementById(\'stancerpaymenturl\').value);">' . img_picto($langs->trans("Copy"), 'copy', 'class="paddingleft"') . '</a>';
						print '</div>';
					} else {
						print '<br><!-- Stancer payment link -->';
						print '<div class="titre inline-block">' . $langs->trans("StancerPropalPaymentLinkNeedEmail") . '</div>';
					}
				}
			}
		}

		return 0;
	}

	/**
	 * Check status of $object (invoice, order, donation...) to show a message in newpayment.php.
	 *
	 * @param  array       $parameters  Array of parameters
	 * @param  Object      $object      Object output
	 * @param  string      $action      'add', 'update', 'view'
	 * @param  HookManager $hookmanager Hook manager propagated to allow calling another hook
	 * @return int                             < 0 on error, 0 on success, 1 to replace standard code
	 */
	public function doCheckStatus($parameters, &$object, &$action, $hookmanager)
	{
		global $conf, $langs;

		$error = 0; // Error counter.
		if (in_array($parameters['currentcontext'], array('newpayment'))) { // Do something only for the context 'somecontext1' or 'somecontext2'.
			$paymentmethod = $parameters['paymentmethod'];
			$source = $parameters['source'];
			$object = $parameters['object'];

			// if ((empty($paymentmethod) || $paymentmethod == 'stancer') && ! empty($conf->stancer->enabled)) {
			//     if ($source == 'order' && $object->billed) {
			//         print '<br><br><span class="amountpaymentcomplete">' . $langs->trans("OrderBilledPending") . '</span>';
			//     }

			//     if ($source == 'invoice' && strripos($object->note_private, '##')) { // The last appearance is found.
			//         print '<br><br><span class="amountpaymentcomplete">' . $langs->trans("STANCER_PENDING_PAYMENT_DESC") . '</span>';
			//         exit;
			//     }

			//     if ($source == 'donation' && $object->paid) {
			//         print '<br><br><span class="amountpaymentcomplete">' . $langs->trans("DonationPaidPending") . '</span>';
			//     }
			// }
		}

		if (! $error) {
			return 0; // Or return 1 to replace standard code.
		}

		$this->errors[] = 'Error message';
		return -1;
	}

	/**
	 * Start payment process with Stancer.
	 *
	 * @param  array       $parameters  Array of parameters
	 * @param  Object      $object      Object output
	 * @param  string      $action      'add', 'update', 'view'
	 * @param  HookManager $hookmanager Hook manager propagated to allow calling another hook
	 * @return int                        < 0 on error, 0 on success, 1 to replace standard code
	 */
	public function doPayment($parameters, &$object, &$action, $hookmanager)
	{
		global $conf, $user, $langs;
		$error = 0;
		// print json_encode($parameters);
		// print json_encode($object);
		// exit;

		// The core calls this hook on every load of the public payment page, including
		// when no object is known yet and when another payment module is the target.
		// Anything but our own case must be left alone: returning an error here would
		// poison the whole hook result and hide the other modules embedded forms.
		if ($parameters['currentcontext'] == 'newpayment' && (isset($parameters['paymentmethod']) ? $parameters['paymentmethod'] : '') == 'stancer') {
			if (empty($object)) {
				$object = getObjectFromTag(isset($parameters['tag']) ? $parameters['tag'] : '');
			}

			if (empty($object)) {
				// getObjectFromTag() returns null on an unknown or malformed tag:
				// going further would call a method on null (fatal on a public page).
				dol_syslog("stancer doPayment: no Dolibarr object found for tag '" . (isset($parameters['tag']) ? $parameters['tag'] : '') . "', payment aborted", LOG_ERR);
				return $error;
			}

			$amount = null;
			if (isset($parameters['amount'])) {
				$amount = $parameters['amount'];
			}
			$res = stancerCardstartPayWithRedirect($object, $parameters, $amount);
			dol_syslog("stancer doPayment call stancerCardstartPayWithRedirect returns " . json_encode($res));
		}
		return $error;
	}

	/**
	 * Overloading the doMassActions function : replacing the parent's function with the one below
	 *
	 * @param  array        $parameters  Hook metadatas (context, etc...)
	 * @param  CommonObject $object      The object to process (an invoice if you are in invoice module, a propale in propale's module, etc...)
	 * @param  string       $action      Current action (if set). Generally create or edit or null
	 * @param  HookManager  $hookmanager Hook manager propagated to allow calling another hook
	 * @return int                             < 0 on error, 0 on success, 1 to replace standard code
	 */
	public function doMassActions($parameters, &$object, &$action, $hookmanager)
	{
		global $conf, $user, $langs;

		$error = 0; // Error counter

		/* print_r($parameters); print_r($object); echo "action: " . $action; */
		// if (in_array($parameters['currentcontext'], array('somecontext1', 'somecontext2'))) {        // do something only for the context 'somecontext1' or 'somecontext2'
		// 	foreach ($parameters['toselect'] as $objectid) {
		// 		// Do action on each object id
		// 	}
		// }

		if (!$error) {
			$this->results = array('myreturn' => 999);
			$this->resprints = 'A text to show';
			return 0; // or return 1 to replace standard code
		} else {
			$this->errors[] = 'Error message';
			return -1;
		}
	}


	/**
	 * Overloading the addMoreMassActions function : replacing the parent's function with the one below
	 *
	 * @param  array        $parameters  Hook metadatas (context, etc...)
	 * @param  CommonObject $object      The object to process (an invoice if you are in invoice module, a propale in propale's module, etc...)
	 * @param  string       $action      Current action (if set). Generally create or edit or null
	 * @param  HookManager  $hookmanager Hook manager propagated to allow calling another hook
	 * @return int                             < 0 on error, 0 on success, 1 to replace standard code
	 */
	public function addMoreMassActions($parameters, &$object, &$action, $hookmanager)
	{
		global $conf, $user, $langs;

		$error = 0; // Error counter
		$disabled = 1;

		/* print_r($parameters); print_r($object); echo "action: " . $action; */
		if (in_array($parameters['currentcontext'], array('somecontext1', 'somecontext2'))) {        // do something only for the context 'somecontext1' or 'somecontext2'
			$this->resprints = '<option value="0"' . ($disabled ? ' disabled="disabled"' : '') . '>' . $langs->trans("StancerMassAction") . '</option>';
		}

		if (!$error) {
			return 0; // or return 1 to replace standard code
		} else {
			$this->errors[] = 'Error message';
			return -1;
		}
	}



	/**
	 * Execute action
	 *
	 * @param  array  $parameters Array of parameters
	 * @param  Object $object     Object output on PDF
	 * @param  string $action     'add', 'update', 'view'
	 * @return int                     <0 if KO,
	 *                                  =0 if OK but we want to process standard actions too,
	 *                                  >0 if OK and we want to replace standard actions.
	 */
	public function beforePDFCreation($parameters, &$object, &$action)
	{
		global $conf, $user, $langs;
		global $hookmanager;

		$outputlangs = $langs;

		$ret = 0;
		$deltemp = array();
		dol_syslog(get_class($this) . '::executeHooks action=' . $action);

		return $ret;
	}

	/**
	 * Execute action
	 *
	 * @param  array  $parameters Array of parameters
	 * @param  Object $pdfhandler PDF builder handler
	 * @param  string $action     'add', 'update', 'view'
	 * @return int                     <0 if KO,
	 *                                  =0 if OK but we want to process standard actions too,
	 *                                  >0 if OK and we want to replace standard actions.
	 */
	public function afterPDFCreation($parameters, &$pdfhandler, &$action)
	{
		global $conf, $user, $langs;
		global $hookmanager;

		$outputlangs = $langs;

		$ret = 0;
		$deltemp = array();
		dol_syslog(get_class($this) . '::executeHooks action=' . $action);

		return $ret;
	}



	/**
	 * Overloading the loadDataForCustomReports function : returns data to complete the customreport tool
	 *
	 * @param  array       $parameters  Hook metadatas (context, etc...)
	 * @param  string      $action      Current action (if set). Generally create or edit or null
	 * @param  HookManager $hookmanager Hook manager propagated to allow calling another hook
	 * @return int                             < 0 on error, 0 on success, 1 to replace standard code
	 */
	public function loadDataForCustomReports($parameters, &$action, $hookmanager)
	{
		global $conf, $user, $langs;

		$langs->loadLangs(array("stancer@stancer"));

		$this->results = array();

		$head = array();
		$h = 0;

		if ($parameters['tabfamily'] == 'stancer') {
			$head[$h][0] = dol_buildpath('/module/index.php', 1);
			$head[$h][1] = $langs->trans("Home");
			$head[$h][2] = 'home';
			$h++;

			$this->results['title'] = $langs->trans("Stancer");
			$this->results['picto'] = 'stancer@stancer';
		}

		$head[$h][0] = 'customreports.php?objecttype=' . $parameters['objecttype'] . (empty($parameters['tabfamily']) ? '' : '&tabfamily=' . $parameters['tabfamily']);
		$head[$h][1] = $langs->trans("CustomReports");
		$head[$h][2] = 'customreports';

		$this->results['head'] = $head;

		return 1;
	}



	/**
	 * Overloading the restrictedArea function : check permission on an object
	 *
	 * @param  array       $parameters  Hook metadatas (context, etc...)
	 * @param  string      $action      Current action (if set). Generally create or edit or null
	 * @param  HookManager $hookmanager Hook manager propagated to allow calling another hook
	 * @return int                                 <0 if KO,
	 *                                          =0 if OK but we want to process standard actions too,
	 *                                          >0 if OK and we want to replace standard actions.
	 */
	public function restrictedArea($parameters, &$action, $hookmanager)
	{
		global $user;

		if ($parameters['features'] == 'myobject') {
			if ($user->hasRight('stancer', 'read')) {
				$this->results['result'] = 1;
				return 1;
			} else {
				$this->results['result'] = 0;
				return 1;
			}
		}

		return 0;
	}

	/* Add here any other hooked methods... */

	/**
	 * Overloading the printFieldListTitle function : replacing the parent's function with the one below
	 *
	 * @param  array  $parameters			  meta datas of the hook (context, etc...)
	 * @param  object $object            the object you want to process (an invoice if you are in invoice module, a propale in propale's module, etc...)
	 * @param  string $action             current action (if set). Generally create or edit or null
	 * @param  HookManager $hookmanager Hook manager propagated to allow calling another hook
	 * @return int                      0 on success (extra column is printed directly)
	 */
	public function printFieldListTitle($parameters, &$object, &$action, $hookmanager)
	{
		global $langs, $db;
		// print json_encode($parameters);
		// exit;
		$contexts = explode(':', $parameters['context']);

		// if (is_array($contexts) && in_array('greffon', $contexts) && in_array('dolmessage', $contexts)) {
		//     $this->initGreffon($contexts);

		//     if (isset($this->greffon) && !empty($this->greffon)) {
		//         foreach ($this->greffon['dolmessage'] as $k => $r) {

		//             if (method_exists(get_class($r), 'printFieldListValue')) {
		//                 $res = $r->printFieldListValue($parameters, $object, $action, $hookmanager);
		//                 //             var_dump(get_class($r),$res);
		//                 if ($res) {
		//                     $return        = 1;
		//                     $this->results = $r->results;
		//                 }
		//             }
		//         }
		//     }
		// //             print_r($this->results);
		// // var_dump($return);
		//     return $return;
		// }

		if ($parameters['currentcontext'] == 'thirdpartybancard') {
			print '<td class="center">Stancer</td>';
		}

		return 0;
	}

	/**
	 * Complete list
	 *
	 * @param	array	$parameters		Array of parameters
	 * @param	object	$object			Object
	 * @return	int						0 on success (cell content is printed directly)
	 */
	public function printFieldListValue($parameters, &$object)
	{
		global $langs;
		if ($parameters['currentcontext'] == 'thirdpartybancard' && substr($parameters['linetype'], 0, 6) == "stancer") {
			print '<td class="center">';
			//add more border lines
			$cb_complete = false;
			if ($cb_complete) {
				print "<a class='stancertakepayment' href='" . dol_buildpath("/stancer/stancer_thirdparty.php", 2) . "?socid=" . $object->id . "&action=stancertakepayment&companymodeid=" . $parameters['obj']->rowid . "'>" . $langs->trans("StancerPayBalanceCB") . "</a>";
			}
			print '</td>';
		}
		return 0;
	}


	/**
	 * Overloading the addMoreActionsButtons function : replacing the parent's function with the one below
	 *
	 * @param   array           $parameters     Hook metadatas (context, etc...)
	 * @param   CommonObject    $object         The object to process (an invoice if you are in invoice module, a propale in propale's module, etc...)
	 * @param   string          $action         Current action (if set). Generally create or edit or null
	 * @param   HookManager     $hookmanager    Hook manager propagated to allow calling another hook
	 * @return  int                             < 0 on error, 0 on success, 1 to replace standard code
	 */
	public function addMoreActionsButtons($parameters, &$object, &$action, $hookmanager)
	{
		global $conf, $user, $langs;

		$error = 0; // Error counter
		if (empty($object->id)) {
			return -1;
		}
		$currentcontext = $parameters['currentcontext'];

		if (!in_array($currentcontext, $this->array_of_handled_context)) {
			// dol_syslog("stancer bye bye addMoreActionsButtons param = " . json_encode($parameters) . ", action = $action", LOG_DEBUG);
			//nothing to do here
			return 0;
		}

		dol_syslog("stancer addMoreActionsButtons param = " . json_encode($parameters) . ", action = $action", LOG_DEBUG);

		// The blocks below read invoice fields on $object. The core always passes the
		// matching class for these two contexts, so a mismatch is a caller bug: log it.
		if (($currentcontext == 'invoicecard' && !($object instanceof Facture))
			|| ($currentcontext == 'invoicesuppliercard' && !($object instanceof FactureFournisseur))) {
			dol_syslog("stancer addMoreActionsButtons: context " . $currentcontext . " received with " . (is_object($object) ? get_class($object) : gettype($object)) . ", no Stancer button added", LOG_ERR);
			return 0;
		}

		// print json_encode($object);exit;
		if ($currentcontext == 'invoicesuppliercard' && $object instanceof FactureFournisseur) {
			// uniquement si fournisseur est stancer !
			$search = "/(ILIAD 78)|(stancer)/i";

			// Keep $statut here: FactureFournisseur::fetch() only fills $this->statut
			// on Dolibarr 15 (still supported), $this->status appears in Dolibarr 16.
			// @phan-suppress-next-line PhanDeprecatedProperty
			if ($object->statut == FactureFournisseur::STATUS_VALIDATED && preg_match($search, $object->thirdparty->name)) {
				print '<div class="inline-block divButAction"><a class="butAction" href="' . $_SERVER["PHP_SELF"] . '?id=' . $object->id . '&action=stancerFindPaymentInvoice">' . $langs->trans('stancerFindPaymentInvoice') . '</a></div>';
			}
		}

		if ($currentcontext == 'invoicecard' && $object instanceof Facture) {
			dol_syslog("stancer addMoreActionsButtons object status = " . $object->status, LOG_DEBUG);

			if ($object->status == Facture::STATUS_VALIDATED) {
				//Moyen de paiement prélèvement ?
				$showSepaButton = ($object->mode_reglement_code == 'PRE');
				//idée proposer des paiements fragmentés
				$showCardButton = ($object->mode_reglement_code == 'CB' && getDolGlobalString('STANCER_ENABLE_CB'));

				//disable si déjà en cours ?
				$btnAction = "butAction";
				$runningLabels = array();
				$runningStancerId = '';

				// Disable the buttons while a debit is engaged for this invoice, so a
				// second click cannot debit the customer twice. All the rows that link
				// a running payment to this invoice are read, not just one: order_id
				// has no unique index and a same-day grouped SEPA debit only names the
				// invoice in grouped_invoice_ids. See fetchAllRunningForInvoice().
				// The query is skipped when no Stancer button is going to be printed:
				// this hook runs on every display of every validated invoice, most of
				// which are not paid through Stancer at all.
				if ($showSepaButton || $showCardButton) {
					$sp = new Stancer_payments($this->db);
					$runningPayments = $sp->fetchAllRunningForInvoice($object->id, (string) $object->ref);
					if (!is_array($runningPayments)) {
						// fetchAllRunningForInvoice() already logged the SQL error. Leave the
						// buttons active: refusing a legitimate debit on a failed read would
						// block the user with no way out.
						dol_syslog("stancer addMoreActionsButtons cannot read the Stancer payments of invoice " . $object->ref . " (id=" . $object->id . "), buttons left active", LOG_ERR);
					} elseif (count($runningPayments) > 0) {
						// Same wording as the server side guards of stancerSEPAstartPay()
						// and stancerCBstartPay(), so what the user reads in the tooltip
						// can be grepped as is in dolibarr.log.
						$runningLabels = Stancer_payments::describeRecords($runningPayments);
						$firstRunningPayment = reset($runningPayments);
						$runningStancerId = (string) $firstRunningPayment->stancer_id;
						dol_syslog("stancer addMoreActionsButtons " . count($runningLabels) . " Stancer payment(s) still running for invoice " . $object->ref . " (id=" . $object->id . "): " . implode(', ', $runningLabels) . ", payment buttons disabled", LOG_WARNING);
						$btnAction = "butActionRefused";
					}
				}

				// Tooltip of the disabled buttons: name the payments that hold the lock,
				// so the user can find them in the list reached by the button added below.
				// trans() already returns HTML encoded text, only the dynamic part is
				// escaped here: escaping the whole string would show the entities raw.
				$alreadyInProgressTitle = $langs->trans('StancerAlreadyInProgress');
				if ($runningStancerId !== '') {
					$alreadyInProgressTitle .= ' (' . dol_escape_htmltag(implode(' / ', $runningLabels)) . ')';
				}

				if ($showSepaButton) {
					if ($btnAction == "butAction") {
						print '<div class="inline-block divButAction"><a class="' . $btnAction . '" href="' . $_SERVER["PHP_SELF"] . '?id=' . $object->id . '&action=stancerSEPA">' . $langs->trans('StancerSEPAstart') . '</a></div>';
					} else {
						print '<div class="inline-block divButAction"><a class="' . $btnAction . ' classfortooltip" href="#" title="' . $alreadyInProgressTitle . '">' . $langs->trans('StancerSEPAstart') . '</a></div>';
					}
				}
				if ($showCardButton) {
					if ($btnAction == "butAction") {
						print '<div class="inline-block divButAction"><a class="' . $btnAction . '" href="' . $_SERVER["PHP_SELF"] . '?id=' . $object->id . '&action=stancerCard">' . $langs->trans('StancerCardStart') . '</a></div>';
					} else {
						print '<div class="inline-block divButAction"><a class="' . $btnAction . ' classfortooltip" href="#" title="' . $alreadyInProgressTitle . '">' . $langs->trans('StancerCardStart') . '</a></div>';
					}
				}

				// Way out of the disabled state. A row can stay in a running status for
				// good if the synchronisation stops, so the user needs to reach it: this
				// button opens the payment list on the blocking payment, where the force
				// refresh mass action asks Stancer for its real status. Once that status
				// is no longer a running one, the buttons above become clickable again.
				if ($runningStancerId !== '') {
					$runningPaymentUrl = dol_buildpath('/stancer/stancer_payments_list.php', 1) . '?search_stancer_id=' . urlencode($runningStancerId);
					print '<div class="inline-block divButAction"><a class="butAction classfortooltip" href="' . $runningPaymentUrl . '" title="' . $alreadyInProgressTitle . '">' . $langs->trans('StancerPayments') . '</a></div>';
				}
			}
		}

		// Payment link for propal is now displayed in formBuilddocOptions hook (bottom of page)

		if ($currentcontext == 'invoicecard' && $object instanceof Facture) {
			// Button for credit notes (avoir) - Stancer refund
			if ($object->type == Facture::TYPE_CREDIT_NOTE && $object->status == Facture::STATUS_VALIDATED) {
				$canRefund = false;
				$refundBtnClass = "butActionRefused";
				$refundTooltip = $langs->trans('StancerRefundNoLinkedPayment');

				// Check if this credit note is linked to an original invoice with a Stancer payment
				if (!empty($object->fk_facture_source)) {
					$originalInvoice = new Facture($this->db);
					if ($originalInvoice->fetch($object->fk_facture_source) > 0) {
						// Search for a captured Stancer payment on the original invoice
						$sp = new Stancer_payments($this->db);
						$resSP = $sp->fetchAll('DESC', 't.rowid', 1, 0, array(
							'customsql' => "live_mode = '" . getDolGlobalString('STANCER_IS_PROD', '0') . "' AND status = '" . Stancer_payments::STATUS_CAPTURED . "' AND (unique_id LIKE '%INV=" . ((int) $originalInvoice->id) . "' OR unique_id LIKE '%INV=" . ((int) $originalInvoice->id) . ".%' OR order_id LIKE '%" . $this->db->escape($originalInvoice->ref) . "%')"
						));
						if (is_array($resSP) && count($resSP) > 0) {
							$canRefund = true;
							$refundBtnClass = "butAction";
							$refundTooltip = '';
						}
					}
				}

				// Check if refund already exists for this credit note
				if ($canRefund) {
					dol_include_once('/stancer/class/stancer_refunds.class.php');
					$existingRefund = new Stancer_refunds($this->db);
					// Check by amount matching (credit note amount in cents)
					$creditNoteAmountCents = StancerApi::toCents(abs((float) $object->total_ttc));
					$sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "stancer_stancer_refunds WHERE amount = " . ((int) $creditNoteAmountCents) . " AND fk_soc = " . ((int) $object->socid);
					$resql = $this->db->query($sql);
					if ($resql && $this->db->num_rows($resql) > 0) {
						$canRefund = false;
						$refundBtnClass = "butActionRefused";
						$refundTooltip = $langs->trans('StancerRefundAlreadyDone');
					}
				}

				if ($canRefund) {
					print '<div class="inline-block divButAction"><a class="' . $refundBtnClass . '" href="' . $_SERVER["PHP_SELF"] . '?id=' . $object->id . '&action=stancerRefund">' . $langs->trans('StancerRefundStart') . '</a></div>';
				} else {
					print '<div class="inline-block divButAction"><a class="' . $refundBtnClass . ' classfortooltip" href="#" title="' . dol_escape_htmltag($refundTooltip) . '">' . $langs->trans('StancerRefundStart') . '</a></div>';
				}
			}
		}
		return 0;
	}

	/**
	 * Find and link Stancer invoice payments based on fees calculation
	 *
	 * This method analyzes Stancer payments to calculate total fees for the invoice period
	 * and matches them with bank withdrawals to properly link the supplier invoice.
	 *
	 * @param  FactureFournisseur $object  The supplier invoice
	 * @param  User               $user    Current user
	 * @param  int                $error   Error counter (passed by reference)
	 * @return void
	 */
	private function stancerFindPaymentInvoiceAction($object, $user, &$error)
	{
		global $langs;
		$langs->load("stancer@stancer");

		dol_include_once('/stancer/class/stancer_payments.class.php');
		dol_include_once('/stancer/class/stancer_payouts.class.php');
		dol_include_once('/stancer/class/stancer_refunds.class.php');
		dol_include_once('/stancer/lib/stancer_bank.lib.php');
		require_once DOL_DOCUMENT_ROOT . '/compta/bank/class/account.class.php';
		require_once DOL_DOCUMENT_ROOT . '/fourn/class/paiementfourn.class.php';

		// Proactive cleanup: any orphan bank_url row left over from previous
		// mis-reconciliations would generate phantom entries in the bank journal.
		$nbOrphans = stancerCleanupOrphanBankUrls();
		if ($nbOrphans > 0) {
			$this->stancerLog("stancerFindPaymentInvoice: cleaned $nbOrphans orphan bank_url rows before processing", LOG_WARNING);
		}

		$debugMessages = [];
		$debugMessages[] = "=== START stancerFindPaymentInvoice ===";
		$debugMessages[] = "Invoice ID: " . $object->id . ", Ref: " . $object->ref;
		$debugMessages[] = "Invoice date: " . dol_print_date($object->date, 'day');
		$debugMessages[] = "Invoice total HT: " . $object->total_ht . " EUR";
		$debugMessages[] = "Invoice total TTC: " . $object->total_ttc . " EUR";

		// Check remaining amount to pay before proceeding
		$alreadyPaid = $object->getSommePaiement();
		$remainingToPay = $object->total_ttc - $alreadyPaid;
		$debugMessages[] = "Already paid: " . $alreadyPaid . " EUR";
		$debugMessages[] = "Remaining to pay: " . $remainingToPay . " EUR";

		// SYSLOG for debugging
		$this->stancerLog("stancerFindPaymentInvoice: Invoice ID=" . $object->id . ", total_ttc=" . $object->total_ttc . ", alreadyPaid=" . $alreadyPaid . ", remainingToPay=" . $remainingToPay, LOG_ERR);

		if ($remainingToPay <= 0) {
			$debugMessages[] = "ERROR: Invoice already fully paid or overpaid (remaining: $remainingToPay EUR)";
			$this->stancerLog("stancerFindPaymentInvoice: STOPPING - invoice already paid, remaining=" . $remainingToPay, LOG_ERR);
			$this->outputDebugMessages($debugMessages);
			setEventMessage($langs->trans('StancerInvoiceAlreadyPaid', price($remainingToPay)), 'errors');
			return;
		}

		$invoiceAmountTTC = abs((float) $object->total_ttc);
		$invoiceAmountTTCCents = (int) round($invoiceAmountTTC * 100);

		$year = dol_print_date($object->date, "%Y");
		$month = dol_print_date($object->date, "%m");

		$debugMessages[] = "Looking for fees in period: $year-$month";

		// Calculate date range for fee search
		// Stancer invoices typically cover the previous month's activity
		$periodStart = new DateTime();
		$periodStart->setDate((int) $year, (int) $month, 1);
		$periodStart->modify('-1 month');

		$periodEnd = new DateTime();
		$periodEnd->setDate((int) $year, (int) $month, 1);
		$periodEnd->modify('-1 day');

		$debugMessages[] = "Period start: " . $periodStart->format('Y-m-d');
		$debugMessages[] = "Period end: " . $periodEnd->format('Y-m-d');

		// Method 1: Calculate fees from stancer_payments table
		$totalFeesFromPayments = 0;
		$paymentCount = 0;
		$paymentDetails = [];

		$sqlPayments = "SELECT rowid, stancer_id, amount, fee, date_bank, method, status, created
			FROM " . MAIN_DB_PREFIX . "stancer_stancer_payments
			WHERE fee IS NOT NULL AND fee > 0
			AND live_mode = '" . $this->db->escape(getDolGlobalString('STANCER_IS_PROD', '0')) . "'
			AND (
				(date_bank >= '" . $periodStart->format('Y-m-d') . "' AND date_bank <= '" . $periodEnd->format('Y-m-d') . " 23:59:59')
				OR (created >= '" . $periodStart->format('Y-m-d') . "' AND created <= '" . $periodEnd->format('Y-m-d') . " 23:59:59')
			)
			ORDER BY date_bank ASC, created ASC";

		$debugMessages[] = "SQL payments: " . $sqlPayments;

		$resqlPayments = $this->db->query($sqlPayments);
		if ($resqlPayments) {
			$numPayments = $this->db->num_rows($resqlPayments);
			$debugMessages[] = "Found $numPayments payments with fees";

			while ($objp = $this->db->fetch_object($resqlPayments)) {
				$totalFeesFromPayments += (int) $objp->fee;
				$paymentCount++;
				$paymentDetails[] = [
					'id' => $objp->rowid,
					'stancer_id' => $objp->stancer_id,
					'amount' => $objp->amount,
					'fee' => $objp->fee,
					'date_bank' => $objp->date_bank,
					'method' => $objp->method,
					'status' => $objp->status
				];
				$debugMessages[] = "  Payment #{$objp->rowid} ({$objp->stancer_id}): amount=" . ($objp->amount / 100) . " EUR, fee=" . ($objp->fee / 100) . " EUR, date_bank={$objp->date_bank}, method={$objp->method}";
			}
		} else {
			$debugMessages[] = "ERROR SQL payments: " . $this->db->lasterror();
		}

		$totalFeesFromPaymentsEUR = $totalFeesFromPayments / 100;
		$debugMessages[] = "Total fees from payments: $totalFeesFromPaymentsEUR EUR ($totalFeesFromPayments cents) from $paymentCount payments";

		// Method 2: Get fees from stancer_payouts table.
		// Monthly Stancer supplier invoice is TTC: we must sum fees + fees_vat.
		$totalFeesFromPayouts = 0;
		$totalFeesVatFromPayouts = 0;
		$payoutCount = 0;
		$payoutDetails = [];

		$sqlPayouts = "SELECT rowid, payout_id, amount, fees, fees_vat, amount_net, date_bank, date_paym, status
			FROM " . MAIN_DB_PREFIX . "stancer_stancer_payouts
			WHERE fees IS NOT NULL AND fees > 0
			AND (
				(date_bank >= '" . $periodStart->format('Y-m-d') . "' AND date_bank <= '" . $periodEnd->format('Y-m-d') . " 23:59:59')
				OR (date_paym >= '" . $periodStart->format('Y-m-d') . "' AND date_paym <= '" . $periodEnd->format('Y-m-d') . " 23:59:59')
			)
			ORDER BY date_bank ASC";

		$debugMessages[] = "SQL payouts: " . $sqlPayouts;

		$resqlPayouts = $this->db->query($sqlPayouts);
		if ($resqlPayouts) {
			$numPayouts = $this->db->num_rows($resqlPayouts);
			$debugMessages[] = "Found $numPayouts payouts with fees";

			while ($objp = $this->db->fetch_object($resqlPayouts)) {
				$totalFeesFromPayouts += (int) $objp->fees;
				$totalFeesVatFromPayouts += (int) $objp->fees_vat;
				$payoutCount++;
				$payoutDetails[] = [
					'id' => $objp->rowid,
					'payout_id' => $objp->payout_id,
					'amount' => $objp->amount,
					'fees' => $objp->fees,
					'fees_vat' => $objp->fees_vat,
					'amount_net' => $objp->amount_net,
					'date_bank' => $objp->date_bank
				];
				$debugMessages[] = "  Payout #{$objp->rowid} ({$objp->payout_id}): amount=" . ($objp->amount / 100) . " EUR, fees=" . ($objp->fees / 100) . " EUR, fees_vat=" . (((int) $objp->fees_vat) / 100) . " EUR, net=" . ($objp->amount_net / 100) . " EUR, date_bank={$objp->date_bank}";
			}
		} else {
			$debugMessages[] = "ERROR SQL payouts: " . $this->db->lasterror();
		}

		$totalFeesPayoutsTTC = $totalFeesFromPayouts + $totalFeesVatFromPayouts;
		$totalFeesFromPayoutsEUR = $totalFeesFromPayouts / 100;
		$totalFeesVatFromPayoutsEUR = $totalFeesVatFromPayouts / 100;
		$totalFeesPayoutsTTCEUR = $totalFeesPayoutsTTC / 100;
		$debugMessages[] = "Total fees HT from payouts: $totalFeesFromPayoutsEUR EUR ($totalFeesFromPayouts cents)";
		$debugMessages[] = "Total fees VAT from payouts: $totalFeesVatFromPayoutsEUR EUR ($totalFeesVatFromPayouts cents)";
		$debugMessages[] = "Total fees TTC from payouts: $totalFeesPayoutsTTCEUR EUR ($totalFeesPayoutsTTC cents) from $payoutCount payouts";

		// Method 3: Check refunds that may have associated fees
		$totalRefundFees = 0;
		$sqlRefunds = "SELECT rowid, refund_id, payment_id, amount, date_bank
			FROM " . MAIN_DB_PREFIX . "stancer_stancer_refunds
			WHERE live_mode = '" . $this->db->escape(getDolGlobalString('STANCER_IS_PROD', '0')) . "'
			AND (
				date_bank >= '" . $periodStart->format('Y-m-d') . "' AND date_bank <= '" . $periodEnd->format('Y-m-d') . " 23:59:59'
			)
			ORDER BY date_bank ASC";

		$debugMessages[] = "SQL refunds: " . $sqlRefunds;

		$resqlRefunds = $this->db->query($sqlRefunds);
		if ($resqlRefunds) {
			$numRefunds = $this->db->num_rows($resqlRefunds);
			$debugMessages[] = "Found $numRefunds refunds in period";
			while ($objr = $this->db->fetch_object($resqlRefunds)) {
				// Refund fees are typically included in the payment fees, but we track them for information
				$totalRefundFees += 0; // Stancer doesn't seem to charge separate refund fees, but leaving placeholder
				$debugMessages[] = "  Refund #{$objr->rowid} ({$objr->refund_id}): amount=" . ($objr->amount / 100) . " EUR, payment_id={$objr->payment_id}";
			}
			$debugMessages[] = "Total refund fees (estimated): " . ($totalRefundFees / 100) . " EUR";
		}

		// Compare calculated fees TTC with invoice TTC.
		// Stancer monthly supplier invoice is TTC, so we compare with fees + fees_vat.
		// For payments we don't have fees_vat per-row yet, so keep HT comparison as fallback.
		$debugMessages[] = "=== COMPARISON ===";
		$debugMessages[] = "Invoice TTC: $invoiceAmountTTC EUR ($invoiceAmountTTCCents cents)";
		$debugMessages[] = "Fees TTC from payouts (fees+fees_vat): $totalFeesPayoutsTTCEUR EUR ($totalFeesPayoutsTTC cents)";
		$debugMessages[] = "Fees HT from payments (no VAT stored): $totalFeesFromPaymentsEUR EUR ($totalFeesFromPayments cents)";

		$diffPayments = abs($invoiceAmountTTCCents - $totalFeesFromPayments);
		$diffPayouts = abs($invoiceAmountTTCCents - $totalFeesPayoutsTTC);

		$debugMessages[] = "Difference with payouts fees TTC: " . ($diffPayouts / 100) . " EUR";
		$debugMessages[] = "Difference with payments fees HT: " . ($diffPayments / 100) . " EUR";

		// Determine which source to use (prefer payouts: consolidated and include VAT).
		$matchedFees = 0;
		$matchSource = '';
		$toleranceCents = 200; // 2 EUR tolerance for rounding/subscription differences

		if ($diffPayouts <= $toleranceCents && $payoutCount > 0) {
			$matchedFees = $totalFeesPayoutsTTC;
			$matchSource = 'payouts';
			$debugMessages[] = "MATCH found using payouts fees TTC (diff: " . ($diffPayouts / 100) . " EUR)";
		} elseif ($diffPayments <= $toleranceCents && $paymentCount > 0) {
			$matchedFees = $totalFeesFromPayments;
			$matchSource = 'payments';
			$debugMessages[] = "MATCH found using payments fees HT (diff: " . ($diffPayments / 100) . " EUR)";
		} else {
			$debugMessages[] = "NO MATCH found - difference too large";
			$debugMessages[] = "Consider checking:";
			$debugMessages[] = "  - Monthly subscription fee (configured: " . getDolGlobalString('STANCER_MONTHLY_SUBSCRIPTION_FEE', '18') . " EUR)";
			$debugMessages[] = "  - Date range mismatch";
			$debugMessages[] = "  - Missing payments/payouts data";
			$debugMessages[] = "  - Old payouts without fees_vat stored (re-run payout refresh to repopulate)";
		}

		$debugMessages[] = "Match result: source=$matchSource, fees=" . ($matchedFees / 100) . " EUR";

		// Now look for bank withdrawals to link
		$debugMessages[] = "=== BANK SEARCH ===";

		// Constant holds a llx_bank_account rowid: read it as an int, it is fed to
		// Account::fetch() and concatenated in the SQL below.
		$bankAccountId = getDolGlobalInt('STANCER_BANK_ACCOUNT_FOR_PAYMENTS');
		$debugMessages[] = "Bank account ID: $bankAccountId";

		if (empty($bankAccountId)) {
			$debugMessages[] = "ERROR: STANCER_BANK_ACCOUNT_FOR_PAYMENTS not configured";
			$this->errors[] = $langs->trans('StancerBankAccountNotConfigured');
			$this->outputDebugMessages($debugMessages);
			return;
		}

		// Search for bank withdrawals in the invoice month
		$prevdate = new DateTime();
		$prevdate->setDate((int) $year, (int) $month, 1);
		$prevdate->modify('-1 month');

		// Only accept bank lines whose num_chq is a real Stancer fee reference:
		// - 'paym_%' : fee booked per payment (stancerAddPaimentFeeOnBank with STANCER_ADD_FEES=PAYMENT)
		// - 'pout_%' : fee booked per payout   (stancerAddPaimentFeeOnBank with STANCER_ADD_FEES=PAYOUT)
		// Must NOT match 'RETURN_%' (SEPA rejects on customer invoices) nor any other pattern.
		$sqlBank = "SELECT rowid, amount, num_chq, dateo, label
			FROM " . MAIN_DB_PREFIX . "bank
			WHERE rappro = '0'
			AND fk_account = " . ((int) $bankAccountId) . "
			AND amount < 0
			AND dateo >= '" . $prevdate->format('Y-m-d') . "'
			AND dateo <= '" . dol_print_date($object->date, '%Y-%m-%d') . "'
			AND fk_type = 'PRE'
			AND num_chq NOT LIKE 'RETURN\\_%'
			AND (num_chq LIKE 'paym\\_%' OR num_chq LIKE 'pout\\_%')
			AND rowid NOT IN (SELECT fk_bank FROM " . MAIN_DB_PREFIX . "paiementfourn WHERE fk_bank IS NOT NULL)
			ORDER BY dateo DESC";

		$debugMessages[] = "SQL bank: " . $sqlBank;

		$listOfBankLines = [];
		$totalBankAmount = 0;

		$resqlBank = $this->db->query($sqlBank);
		if ($resqlBank) {
			$numBank = $this->db->num_rows($resqlBank);
			$debugMessages[] = "Found $numBank unreconciled bank withdrawals";

			while ($objb = $this->db->fetch_object($resqlBank)) {
				$listOfBankLines[] = [
					'rowid' => $objb->rowid,
					'amount' => $objb->amount,
					'num_chq' => $objb->num_chq,
					'dateo' => $objb->dateo,
					'label' => $objb->label
				];
				$totalBankAmount += abs($objb->amount);
				$debugMessages[] = "  Bank #{$objb->rowid}: amount=" . $objb->amount . " EUR, date={$objb->dateo}, num_chq={$objb->num_chq}, label={$objb->label}";
			}
		} else {
			$debugMessages[] = "ERROR SQL bank: " . $this->db->lasterror();
		}

		$debugMessages[] = "Total bank withdrawals: $totalBankAmount EUR";

		// Match bank lines up to the invoice REMAINING amount (not the total TTC).
		// If the invoice has already been partially paid, we only need to cover
		// what's left, otherwise the matching logic is confused and reports a
		// misleading "87.52 EUR not found" while only 5.06 EUR was actually missing.
		$matchedBankLines = [];
		$remainingAmount = $remainingToPay;
		$toleranceEUR = 0.05; // 5 cents tolerance on final total

		$debugMessages[] = "=== BANK MATCHING ===";
		$debugMessages[] = "Trying to match invoice remaining to pay: $remainingAmount EUR (invoice TTC=$invoiceAmountTTC, already paid=" . ($invoiceAmountTTC - $remainingToPay) . ", tolerance: $toleranceEUR EUR)";

		foreach ($listOfBankLines as $bankLine) {
			$bankAmount = abs($bankLine['amount']);

			if ($remainingAmount > $toleranceEUR) {
				$matchedBankLines[] = $bankLine;
				$remainingAmount -= $bankAmount;
				$debugMessages[] = "  MATCHED: Bank #{$bankLine['rowid']} = $bankAmount EUR, remaining: $remainingAmount EUR";
			}

			if ($remainingAmount <= $toleranceEUR) {
				break;
			}
		}

		$debugMessages[] = "Matched " . count($matchedBankLines) . " bank lines, remaining amount: $remainingAmount EUR";

		// Create payment links if we have matches
		$debugMessages[] = "=== CREATING LINKS ===";

		// Calculate the actual remaining amount to pay on the invoice
		$invoiceRemainingToPay = $object->total_ttc - $object->getSommePaiement();
		$debugMessages[] = "Invoice remaining to pay: $invoiceRemainingToPay EUR";

		if (count($matchedBankLines) > 0 && $invoiceRemainingToPay > 0) {
			$acc = new Account($this->db);
			$acc->fetch($bankAccountId);

			$this->db->begin();
			$linkError = 0;
			$paymentsCreated = 0;
			$totalPaid = 0;

			foreach ($matchedBankLines as $bankLine) {
				// Recalculate remaining after each payment
				$currentRemaining = $invoiceRemainingToPay - $totalPaid;
				if ($currentRemaining <= 0.01) {
					$debugMessages[] = "Invoice fully paid, stopping (remaining: $currentRemaining EUR)";
					break;
				}

				$al = new AccountLine($this->db);
				$al->fetch($bankLine['rowid']);

				// Pay the minimum between bank line amount and remaining to pay
				$bankAmount = abs($al->amount);
				$paymentAmount = (float) price2num(min($bankAmount, $currentRemaining));

				$debugMessages[] = "Bank line #{$bankLine['rowid']}: bank=$bankAmount EUR, remaining=$currentRemaining EUR, paying=$paymentAmount EUR";

				// SYSLOG before payment creation
				$this->stancerLog("stancerFindPaymentInvoice: BEFORE CREATE - bankLine=" . $bankLine['rowid'] . ", bankAmount=" . $bankAmount . ", currentRemaining=" . $currentRemaining . ", paymentAmount=" . $paymentAmount . ", invoiceId=" . $object->id, LOG_ERR);

				$pai = new PaiementFourn($this->db);
				$pai->amounts[$object->id] = $paymentAmount;
				$pai->datepaye = $object->date;
				$pai->paiementid = 3; // withdrawal
				$pai->num_payment = (string) $object->ref;

				if ($pai->create($user, 0) < 0) {
					$debugMessages[] = "  ERROR creating payment: " . implode(', ', $pai->errors);
					$this->errors[] = $langs->trans('StancerErrorCreatingPaymentLink') . ": " . implode(', ', $pai->errors);
					$linkError++;
					break;
				}

				$debugMessages[] = "  Payment created, ID: " . $pai->id;
				$totalPaid += $paymentAmount;
				$paymentsCreated++;

				$pai->update_fk_bank($al->id);
				$debugMessages[] = "  Bank link updated to line #{$al->id}";

				$url = DOL_URL_ROOT . '/fourn/paiement/card.php?id=';
				$acc->add_url_line($al->id, $pai->id, $url, '(paiement)', 'payment_supplier');

				$url = DOL_URL_ROOT . '/comm/card.php?socid=';
				$acc->add_url_line($al->id, $object->thirdparty->id, $url, $object->thirdparty->name, 'company');

				$debugMessages[] = "  URL links added";
			}

			if ($linkError > 0) {
				$this->db->rollback();
				$debugMessages[] = "ROLLBACK due to errors";
				$error++;
			} else {
				$this->db->commit();
				$debugMessages[] = "COMMIT successful - $paymentsCreated payment(s), total: $totalPaid EUR";
				setEventMessage($langs->trans('StancerPaymentLinksCreated', $paymentsCreated, price($totalPaid)), 'mesgs');

				// Create bank statement for reconciliation
				// Use invoice date to determine the statement period (previous month's activity)
				$statementRef = 'STANCER-' . dol_print_date($object->date, '%Y-%m');
				$reconciledCount = 0;

				// Reconcile matched debit lines (fees)
				foreach ($matchedBankLines as $bankLine) {
					$sqlReconcile = "UPDATE " . MAIN_DB_PREFIX . "bank SET rappro = 1, num_releve = '" . $this->db->escape($statementRef) . "' WHERE rowid = " . (int) $bankLine['rowid'];
					if ($this->db->query($sqlReconcile)) {
						$reconciledCount++;
					}
				}

				// Also reconcile credit lines (payouts) for the same month on this bank account
				$invoiceMonth = dol_print_date($object->date, '%Y-%m');
				$sqlReconcileCredits = "UPDATE " . MAIN_DB_PREFIX . "bank SET rappro = 1, num_releve = '" . $this->db->escape($statementRef) . "'
					WHERE fk_account = " . ((int) $bankAccountId) . "
					AND amount > 0
					AND rappro = 0
					AND DATE_FORMAT(dateo, '%Y-%m') = '" . $this->db->escape($invoiceMonth) . "'";
				$resCredits = $this->db->query($sqlReconcileCredits);
				$creditCount = $resCredits ? $this->db->affected_rows($resCredits) : 0;

				// Reconcile outgoing transfers (VIR) for the same period on Stancer account
				$sqlReconcileVIR = "UPDATE " . MAIN_DB_PREFIX . "bank SET rappro = 1, num_releve = '" . $this->db->escape($statementRef) . "'
					WHERE fk_account = " . ((int) $bankAccountId) . "
					AND amount < 0
					AND rappro = 0
					AND fk_type = 'VIR'
					AND DATE_FORMAT(dateo, '%Y-%m') = '" . $this->db->escape($invoiceMonth) . "'";
				$resVIR = $this->db->query($sqlReconcileVIR);
				$virCount = $resVIR ? $this->db->affected_rows($resVIR) : 0;

				$debugMessages[] = "Bank statement '$statementRef' created, $reconciledCount debit(s) + $creditCount credit(s) + $virCount transfer(s) reconciled";
			}
		} else {
			$debugMessages[] = "Cannot create links: no matched bank lines or invoice already paid";
			if ($invoiceRemainingToPay <= 0) {
				$this->errors[] = $langs->trans('StancerInvoiceAlreadyPaid', price($invoiceRemainingToPay));
			} else {
				$this->errors[] = $langs->trans('StancerNoMatchingPayments') . " (remaining: $remainingAmount EUR)";
			}
		}

		$debugMessages[] = "=== END stancerFindPaymentInvoice ===";

		// Output debug messages
		$this->outputDebugMessages($debugMessages);
	}

	/**
	 * Log a message with stancer prefix
	 *
	 * @param  string $message Message to log
	 * @param  int    $level   Log level (default LOG_DEBUG)
	 * @return void
	 */
	private function stancerLog($message, $level = LOG_DEBUG)
	{
		dol_syslog("stancer " . $message, $level);
	}

	/**
	 * Output debug messages to syslog and optionally to screen
	 *
	 * @param  array $messages Array of debug messages
	 * @return void
	 */
	private function outputDebugMessages($messages)
	{
		foreach ($messages as $msg) {
			dol_syslog("stancer stancerFindPaymentInvoice: " . $msg, LOG_DEBUG);
		}

		// Build formatted HTML lines with syntax coloring
		$htmlLines = '';
		foreach ($messages as $msg) {
			$escaped = dol_escape_htmltag($msg);

			// Section headers (=== ... ===)
			if (preg_match('/^===.*===$/', $msg)) {
				$htmlLines .= '<div style="color: #1a237e; font-weight: bold; margin-top: 8px; border-bottom: 1px solid #ccc; padding-bottom: 2px;">' . $escaped . '</div>';
				// Errors
			} elseif (preg_match('/^ERROR|ERROR:/', $msg)) {
				$htmlLines .= '<div style="color: #c62828;">' . $escaped . '</div>';
				// Match found
			} elseif (preg_match('/MATCH found|COMMIT successful/', $msg)) {
				$htmlLines .= '<div style="color: #2e7d32; font-weight: bold;">' . $escaped . '</div>';
				// No match
			} elseif (preg_match('/NO MATCH/', $msg)) {
				$htmlLines .= '<div style="color: #e65100; font-weight: bold;">' . $escaped . '</div>';
				// Indented detail lines
			} elseif (preg_match('/^  /', $msg)) {
				$htmlLines .= '<div style="color: #555; padding-left: 16px;">' . $escaped . '</div>';
				// SQL queries
			} elseif (preg_match('/^SQL /', $msg)) {
				$htmlLines .= '<div style="color: #6a1b9a; font-size: 10px; word-break: break-all;">' . $escaped . '</div>';
				// Default
			} else {
				$htmlLines .= '<div>' . $escaped . '</div>';
			}
		}

		// Unique ID for this debug dialog
		$dialogId = 'stancer_debug_' . mt_rand(100000, 999999);

		// Hidden dialog container
		print '<div id="' . $dialogId . '" style="display: none;">';
		print '<div style="font-family: monospace; font-size: 11px; line-height: 1.6;">';
		print $htmlLines;
		print '</div>';
		print '</div>';

		// JavaScript to open jQuery UI dialog on click
		print '<script type="text/javascript">';
		print 'jQuery(document).ready(function() {';
		print '  jQuery("#' . $dialogId . '").dialog({';
		print '    title: "Stancer - Debug",';
		print '    autoOpen: false,';
		print '    modal: true,';
		print '    width: Math.min(900, jQuery(window).width() - 40),';
		print '    height: Math.min(600, jQuery(window).height() - 40),';
		print '    buttons: { "Fermer": function() { jQuery(this).dialog("close"); } }';
		print '  });';
		// Auto-open
		print '  jQuery("#' . $dialogId . '").dialog("open");';
		print '});';
		print '</script>';
	}
}
