<?php
/* Copyright (C) 2023 Eric Seigne <eric.seigne@cap-rel.fr>
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
 * \file    core/triggers/interface_99_modStancer_StancerTriggers.class.php
 * \ingroup stancer
 * \brief   Example trigger.
 *
 * Put detailed description here.
 *
 * \remarks You can create other triggers by copying this one.
 * - File name should be either:
 *      - interface_99_modStancer_MyTrigger.class.php
 *      - interface_99_all_MyTrigger.class.php
 * - The file must stay in core/triggers
 * - The class name must be InterfaceMytrigger
 */

require_once DOL_DOCUMENT_ROOT.'/core/triggers/dolibarrtriggers.class.php';
require_once DOL_DOCUMENT_ROOT.'/comm/action/class/actioncomm.class.php';
dol_include_once('/stancer/core/modules/modStancer.class.php');
dol_include_once('/stancer/lib/stancer.lib.php');


/**
 *  Class of triggers for Stancer module
 */
class InterfaceStancerTriggers extends DolibarrTriggers
{
	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		global $conf;
		$this->db = $db;

		$this->name = preg_replace('/^Interface/i', '', get_class($this));

		$this->family = "interface";
		$this->description = "Stancer triggers.";
		// 'development', 'experimental', 'dolibarr' or version
		$this->version = getDolGlobalString('STANCER_MODULE_VERSION');
		$this->picto = 'stancer@stancer';
	}

	/**
	 * Trigger name
	 *
	 * @return string Name of trigger file
	 */
	public function getName()
	{
		return $this->name;
	}

	/**
	 * Trigger description
	 *
	 * @return string Description of trigger file
	 */
	public function getDesc()
	{
		return $this->description;
	}


	/**
	 * Function called when a Dolibarrr business event is done.
	 * All functions "runTrigger" are triggered if file
	 * is inside directory core/triggers
	 *
	 * @param string 		$action 	Event action code
	 * @param CommonObject 	$object 	Object
	 * @param User 			$user 		Object user
	 * @param Translate 	$langs 		Object langs
	 * @param Conf 			$conf 		Object conf
	 * @return int              		<0 if KO, 0 if no triggered ran, >0 if OK
	 */
	public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
	{
		//  if ($action == "BILL_PAYED") {
		//     dol_syslog("stancer trigger stancer action : " . json_encode($action));
		//     dol_syslog("stancer trigger stancer object : " . json_encode($object));
		//     dol_syslog("stancer trigger stancer langs : " . json_encode($langs));
		//     dol_syslog("stancer trigger stancer user : " . json_encode($user));
		//     dol_syslog("stancer trigger stancer conf : " . json_encode($conf));
		// }
		// return 0;
		if (empty($conf->stancer) || empty($conf->stancer->enabled)) {
			dol_syslog("stancerTrigger early return, module not enabled", LOG_DEBUG);
			return 0; // If module is not enabled, we do nothing
		}

		// Put here code you want to execute when a Dolibarr business events occurs.
		// Data and type of action are stored into $object and $action

		// You can isolate code for each action in a separate method: this method should be named like the trigger in camelCase.
		// For example : COMPANY_CREATE => public function companyCreate($action, $object, User $user, Translate $langs, Conf $conf)
		// F6: dispatch ONLY to an explicit allowlist of handled actions. The former
		// dynamic camelCase + is_callable dispatch let a forced action name reach any
		// public method (getName/getDesc/...). Guard $object->id in the log too.
		$actionHandlers = array(
			'COMPANY_MODIFY' => 'companyModify',
		);
		$objId = is_object($object) ? ($object->id ?? '?') : '?';
		// Label used by the type guards below: the parent signature is untyped, so a
		// non-object must never make get_class() fatal on an error path.
		$objClass = is_object($object) ? get_class($object) : gettype($object);
		if (isset($actionHandlers[$action]) && method_exists($this, $actionHandlers[$action])) {
			dol_syslog("Trigger '".$this->name."' for action '$action' launched by ".__FILE__.". id=".$objId);
			// Every handler of the allowlist above is a COMPANY_* handler and expects a Societe.
			if (!$object instanceof Societe) {
				dol_syslog("stancerTrigger $action called with ".$objClass." instead of Societe, skip", LOG_ERR);
				return 0;
			}
			return call_user_func(array($this, $actionHandlers[$action]), $action, $object, $user, $langs, $conf);
		}

		// Or you can execute some code below in the switch/case
		switch ($action) {
			// Users
			//case 'USER_CREATE':
			//case 'USER_MODIFY':
			//case 'USER_NEW_PASSWORD':
			//case 'USER_ENABLEDISABLE':
			//case 'USER_DELETE':

			// Actions
			//case 'ACTION_MODIFY':
			//case 'ACTION_CREATE':
			//case 'ACTION_DELETE':

			// Groups
			//case 'USERGROUP_CREATE':
			//case 'USERGROUP_MODIFY':
			//case 'USERGROUP_DELETE':

			// Companies
			//case 'COMPANY_CREATE':
			//case 'COMPANY_MODIFY':
			//case 'COMPANY_DELETE':

			// Contacts
			//case 'CONTACT_CREATE':
			//case 'CONTACT_MODIFY':
			//case 'CONTACT_DELETE':
			//case 'CONTACT_ENABLEDISABLE':

			// Products
			//case 'PRODUCT_CREATE':
			//case 'PRODUCT_MODIFY':
			//case 'PRODUCT_DELETE':
			//case 'PRODUCT_PRICE_MODIFY':
			//case 'PRODUCT_SET_MULTILANGS':
			//case 'PRODUCT_DEL_MULTILANGS':

			//Stock mouvement
			//case 'STOCK_MOVEMENT':

			//MYECMDIR
			//case 'MYECMDIR_CREATE':
			//case 'MYECMDIR_MODIFY':
			//case 'MYECMDIR_DELETE':

			// Customer orders
			//case 'ORDER_CREATE':
			//case 'ORDER_MODIFY':
			//case 'ORDER_VALIDATE':
			//case 'ORDER_DELETE':
			//case 'ORDER_CANCEL':
			//case 'ORDER_SENTBYMAIL':
			//case 'ORDER_CLASSIFY_BILLED':
			//case 'ORDER_SETDRAFT':
			//case 'LINEORDER_INSERT':
			//case 'LINEORDER_UPDATE':
			//case 'LINEORDER_DELETE':

			// Supplier orders
			//case 'ORDER_SUPPLIER_CREATE':
			//case 'ORDER_SUPPLIER_MODIFY':
			//case 'ORDER_SUPPLIER_VALIDATE':
			//case 'ORDER_SUPPLIER_DELETE':
			//case 'ORDER_SUPPLIER_APPROVE':
			//case 'ORDER_SUPPLIER_REFUSE':
			//case 'ORDER_SUPPLIER_CANCEL':
			//case 'ORDER_SUPPLIER_SENTBYMAIL':
			//case 'ORDER_SUPPLIER_DISPATCH':
			//case 'LINEORDER_SUPPLIER_DISPATCH':
			//case 'LINEORDER_SUPPLIER_CREATE':
			//case 'LINEORDER_SUPPLIER_UPDATE':
			//case 'LINEORDER_SUPPLIER_DELETE':

			// Proposals
			//case 'PROPAL_CREATE':
			//case 'PROPAL_MODIFY':
			//case 'PROPAL_VALIDATE':
			//case 'PROPAL_SENTBYMAIL':
			//case 'PROPAL_CLOSE_SIGNED':
			//case 'PROPAL_CLOSE_REFUSED':
			//case 'PROPAL_DELETE':
			//case 'LINEPROPAL_INSERT':
			//case 'LINEPROPAL_UPDATE':
			//case 'LINEPROPAL_DELETE':

			// SupplierProposal
			//case 'SUPPLIER_PROPOSAL_CREATE':
			//case 'SUPPLIER_PROPOSAL_MODIFY':
			//case 'SUPPLIER_PROPOSAL_VALIDATE':
			//case 'SUPPLIER_PROPOSAL_SENTBYMAIL':
			//case 'SUPPLIER_PROPOSAL_CLOSE_SIGNED':
			//case 'SUPPLIER_PROPOSAL_CLOSE_REFUSED':
			//case 'SUPPLIER_PROPOSAL_DELETE':
			//case 'LINESUPPLIER_PROPOSAL_INSERT':
			//case 'LINESUPPLIER_PROPOSAL_UPDATE':
			//case 'LINESUPPLIER_PROPOSAL_DELETE':

			// Contracts
			//case 'CONTRACT_CREATE':
			//case 'CONTRACT_MODIFY':
			//case 'CONTRACT_ACTIVATE':
			//case 'CONTRACT_CANCEL':
			//case 'CONTRACT_CLOSE':
			//case 'CONTRACT_DELETE':
			//case 'LINECONTRACT_INSERT':
			//case 'LINECONTRACT_UPDATE':
			//case 'LINECONTRACT_DELETE':

			// Bills
			//case 'BILL_CREATE':
			//case 'BILL_MODIFY':
			case 'BILL_VALIDATE':
				if (!$object instanceof Facture) {
					dol_syslog("stancerTrigger $action called with " . $objClass . " instead of Facture, skip", LOG_ERR);
					return 0;
				}
				dol_syslog("stancerTrigger catch BILL_VALIDATE trigger, mode_reglement_code=" . $object->mode_reglement_code, LOG_DEBUG);

				// Global option: send all invoices by email at validation, regardless of payment mode
				if (getDolGlobalString('STANCER_AUTO_MAIL_ALL_INVOICES_VALIDATE', '') != '') {
					$mailtype = getDolGlobalString('STANCER_AUTO_MAIL_ALL_INVOICES_VALIDATE_MAILTYPE', '');
					if ($mailtype != '') {
						$delayHours = (int) getDolGlobalString('STANCER_AUTO_MAIL_ALL_INVOICES_VALIDATE_DELAY', '0');
						if ($delayHours > 0) {
							// Schedule the mail for later: a human can still send it manually before the cron fires
							$dueDate = dol_now() + ($delayHours * 3600);
							$pendingActionCode = 'BILL_VALIDATE_PENDING';
							$actioncommCheck = new ActionComm($this->db);
							// The module requires Dolibarr 15 minimum, where getActions() no longer takes $db as first argument.
							$existingPending = $actioncommCheck->getActions($object->socid, $object->id, "invoice", " AND code='AC_" . $pendingActionCode . "' AND percentage < 100");
							if (!empty($existingPending)) {
								dol_syslog("stancerTrigger BILL_VALIDATE: delay=" . $delayHours . "h but a pending mail already exists for invoice " . $object->ref . ", skip schedule", LOG_DEBUG);
							} else {
								$pendingEvt = new ActionComm($this->db);
								$pendingEvt->type_code   = 'AC_OTH_AUTO';
								$pendingEvt->code        = 'AC_' . $pendingActionCode;
								$pendingEvt->label       = $langs->trans('StancerBillValidatePendingTitle', $object->ref, $delayHours);
								$pendingEvt->datep       = $dueDate;
								$pendingEvt->datef       = $dueDate;
								$pendingEvt->percentage  = 0;
								$pendingEvt->socid       = $object->socid;
								$pendingEvt->authorid    = $user->id;
								$pendingEvt->userownerid = $user->id;
								$pendingEvt->note_private = $langs->trans('StancerBillValidatePendingNote', $delayHours, $mailtype);
								$pendingEvt->elementid   = (int) $object->id;
								// @phan-suppress-next-line PhanDeprecatedProperty  Dolibarr 15..18 only read fk_element in ActionComm::create()
								$pendingEvt->fk_element  = $object->id;
								$pendingEvt->elementtype = 'invoice';
								$pendingEvt->fulldayevent = 0;
								$resPending = $pendingEvt->create($user);
								if ($resPending > 0) {
									dol_syslog("stancerTrigger BILL_VALIDATE: scheduled mail for invoice " . $object->ref . " in " . $delayHours . "h (modele=$mailtype, due=" . dol_print_date($dueDate, 'dayhour') . ")", LOG_INFO);
								} else {
									dol_syslog("stancerTrigger BILL_VALIDATE: failed to create pending ActionComm for invoice " . $object->ref . ": " . $pendingEvt->error, LOG_ERR);
								}
							}
						} else {
							dol_syslog("stancerTrigger BILL_VALIDATE: global all-invoices option active, sending mail immediately (modele=$mailtype, delay=0)", LOG_DEBUG);
							stancerSendInvoiceMailModele($mailtype, $object, 'BILL_VALIDATE_SENTBYMAIL');
						}
					} else {
						dol_syslog("stancerTrigger BILL_VALIDATE: STANCER_AUTO_MAIL_ALL_INVOICES_VALIDATE active but no mail template configured, skipping", LOG_WARNING);
					}
				}
				break;
			case 'BILL_UNVALIDATE':
			case 'BILL_DELETE':
			case 'BILL_CANCEL':
				if (!$object instanceof Facture) {
					dol_syslog("stancerTrigger $action called with " . $objClass . " instead of Facture, skip", LOG_ERR);
					return 0;
				}
				// Cancel any pending auto-send mail scheduled at validation
				$actioncommCancel = new ActionComm($this->db);
				// The module requires Dolibarr 15 minimum, where getActions() no longer takes $db as first argument.
				$pendingActions = $actioncommCancel->getActions($object->socid, $object->id, "invoice", " AND code='AC_BILL_VALIDATE_PENDING' AND percentage < 100");
				if (is_array($pendingActions) && !empty($pendingActions)) {
					foreach ($pendingActions as $pa) {
						$pa->percentage = 100;
						$pa->note_private = (string) ($pa->note_private ?? '') . "\n" . dol_print_date(dol_now(), '%Y-%m-%d %H:%M:%S') . " - " . $langs->trans('StancerBillValidatePendingCancelled', $action);
						$resPaUpdate = $pa->update($user);
						if ($resPaUpdate > 0) {
							dol_syslog("stancerTrigger $action: cancelled pending auto-send mail (actioncomm id=" . $pa->id . ") for invoice " . $object->ref, LOG_INFO);
						} else {
							dol_syslog("stancerTrigger $action: failed to cancel pending auto-send mail (actioncomm id=" . $pa->id . ") for invoice " . $object->ref . ": " . $pa->error, LOG_ERR);
						}
					}
				}
				break;
			//case 'BILL_SENTBYMAIL':
			case 'BILL_PAYED':
				if (!$object instanceof Facture) {
					dol_syslog("stancerTrigger $action called with " . $objClass . " instead of Facture, skip", LOG_ERR);
					return 0;
				}
				dol_syslog("stancerTrigger catch BILL_PAYED trigger, mode_reglement_code=" . $object->mode_reglement_code . " and bank account= " . $object->fk_account . " (stancer account code is " . getDolGlobalString('STANCER_BANK_ACCOUNT_FOR_PAYMENTS') . ")", LOG_DEBUG);

				//information à envoyer par mail
				$resAC = null;

				//SEPA
				if (
					// 1. si facture en mode paiement SEPA
					($object->mode_reglement_code == 'PRE')
					// 2. le compte bancaire lié est celui qui est dans la conf du module stancer
					&& ($object->fk_account == getDolGlobalString('STANCER_BANK_ACCOUNT_FOR_PAYMENTS'))
					// 2. ET option "envoyer le mail lorsque la facture est payée" est validé
					&& (getDolGlobalString('STANCER_AUTO_MAIL_INVOICES_SEPA_PAID', '') != '' )
					// 3. ET lancé par une tache planifiée ?
					// && (empty($conf->browser))
					) {
					dol_syslog("stancerTrigger try to send mail with invoice (PRE) ... do it", LOG_DEBUG);
					if (getDolGlobalString('STANCER_AUTO_MAIL_INVOICES_SEPA_PAID', '') != '') {
						stancerSendInvoiceMailModele(getDolGlobalString('STANCER_AUTO_MAIL_INVOICES_SEPA_PAID_MAILTYPE', ''), $object, 'BILL_PAYED_SENTBYMAIL');
					}
				} elseif (
					//CB
					// 1. si facture en mode paiement CB
					($object->mode_reglement_code == 'CB')
					// 2. le compte bancaire lié est celui qui est dans la conf du module stancer
					&& ($object->fk_account == getDolGlobalString('STANCER_BANK_ACCOUNT_FOR_PAYMENTS'))
					// 2. ET option "envoyer le mail lorsque la facture est payée" est validé
					&& (getDolGlobalString('STANCER_AUTO_MAIL_INVOICES_CB', '') != '' )
					// 3. ET lancé par une tache planifiée ?
					// && (empty($conf->browser))
					// 4. ET que la gestion globale des CB est active
					&& (getDolGlobalString('STANCER_ENABLE_CB', '') != '' )
					) {
					dol_syslog("stancerTrigger try to send mail with invoice (CB) ... do it", LOG_DEBUG);
					if (getDolGlobalString('STANCER_AUTO_MAIL_INVOICES_CB_MAILTYPE', '') != '') {
						stancerSendInvoiceMailModele(getDolGlobalString('STANCER_AUTO_MAIL_INVOICES_CB_MAILTYPE', ''), $object, 'BILL_PAYED_SENTBYMAIL');
					}
				} else {
					dol_syslog("stancerTrigger else case, so no mail was sent... (maybe infor contact ?)", LOG_DEBUG);
				}

				// Global option: send all paid invoices by email, regardless of payment mode
				// Uses the same actionCode 'BILL_PAYED_SENTBYMAIL' as Stancer flows above,
				// so stancerSendInvoiceMailModele dedup will skip if already sent.
				if (getDolGlobalString('STANCER_AUTO_MAIL_ALL_INVOICES_PAYED', '') != '') {
					$mailtype = getDolGlobalString('STANCER_AUTO_MAIL_ALL_INVOICES_PAYED_MAILTYPE', '');
					if ($mailtype != '') {
						dol_syslog("stancerTrigger BILL_PAYED: global all-invoices option active, sending mail (modele=$mailtype)", LOG_DEBUG);
						stancerSendInvoiceMailModele($mailtype, $object, 'BILL_PAYED_SENTBYMAIL');
					} else {
						dol_syslog("stancerTrigger BILL_PAYED: STANCER_AUTO_MAIL_ALL_INVOICES_PAYED active but no mail template configured, skipping", LOG_WARNING);
					}
				}

				break;
			//case 'LINEBILL_INSERT':
			//case 'LINEBILL_UPDATE':
			//case 'LINEBILL_DELETE':

			//Supplier Bill
			//case 'BILL_SUPPLIER_CREATE':
			//case 'BILL_SUPPLIER_UPDATE':
			//case 'BILL_SUPPLIER_DELETE':
			//case 'BILL_SUPPLIER_PAYED':
			//case 'BILL_SUPPLIER_UNPAYED':
			//case 'BILL_SUPPLIER_VALIDATE':
			//case 'BILL_SUPPLIER_UNVALIDATE':
			//case 'LINEBILL_SUPPLIER_CREATE':
			//case 'LINEBILL_SUPPLIER_UPDATE':
			//case 'LINEBILL_SUPPLIER_DELETE':

			// Payments
			//case 'PAYMENT_CUSTOMER_CREATE':
			//case 'PAYMENT_SUPPLIER_CREATE':
			//case 'PAYMENT_ADD_TO_BANK':
			//case 'PAYMENT_DELETE':

			// Online
			//case 'PAYMENT_PAYBOX_OK':
			//case 'PAYMENT_PAYPAL_OK':
			//case 'PAYMENT_STRIPE_OK':

			// Donation
			//case 'DON_CREATE':
			//case 'DON_UPDATE':
			//case 'DON_DELETE':

			// Interventions
			//case 'FICHINTER_CREATE':
			//case 'FICHINTER_MODIFY':
			//case 'FICHINTER_VALIDATE':
			//case 'FICHINTER_DELETE':
			//case 'LINEFICHINTER_CREATE':
			//case 'LINEFICHINTER_UPDATE':
			//case 'LINEFICHINTER_DELETE':

			// Members
			//case 'MEMBER_CREATE':
			//case 'MEMBER_VALIDATE':
			//case 'MEMBER_SUBSCRIPTION':
			//case 'MEMBER_MODIFY':
			//case 'MEMBER_NEW_PASSWORD':
			//case 'MEMBER_RESILIATE':
			//case 'MEMBER_DELETE':

			// Categories
			//case 'CATEGORY_CREATE':
			//case 'CATEGORY_MODIFY':
			//case 'CATEGORY_DELETE':
			//case 'CATEGORY_SET_MULTILANGS':

			// Projects
			//case 'PROJECT_CREATE':
			//case 'PROJECT_MODIFY':
			//case 'PROJECT_DELETE':

			// Project tasks
			//case 'TASK_CREATE':
			//case 'TASK_MODIFY':
			//case 'TASK_DELETE':

			// Task time spent
			//case 'TASK_TIMESPENT_CREATE':
			//case 'TASK_TIMESPENT_MODIFY':
			//case 'TASK_TIMESPENT_DELETE':
			//case 'PROJECT_ADD_CONTACT':
			//case 'PROJECT_DELETE_CONTACT':
			//case 'PROJECT_DELETE_RESOURCE':

			// Shipping
			//case 'SHIPPING_CREATE':
			//case 'SHIPPING_MODIFY':
			//case 'SHIPPING_VALIDATE':
			//case 'SHIPPING_SENTBYMAIL':
			//case 'SHIPPING_BILLED':
			//case 'SHIPPING_CLOSED':
			//case 'SHIPPING_REOPEN':
			//case 'SHIPPING_DELETE':

			// and more...

			default:
				dol_syslog("stancer Trigger '".$this->name."' for action '$action' launched by ".__FILE__.". id=".$objId);
				break;
		}

		return 0;
	}

	/**
	 * Handle COMPANY_MODIFY trigger for thirdparty merge
	 *
	 * @param string    $action Event action code
	 * @param Societe   $object Destination thirdparty object
	 * @param User      $user   Object user
	 * @param Translate $langs  Object langs
	 * @param Conf      $conf   Object conf
	 * @return int              0 if OK, <0 if KO
	 */
	public function companyModify($action, $object, User $user, Translate $langs, Conf $conf)
	{
		if (empty($object->context['merge']) || $object->context['merge'] != 1) {
			return 0;
		}

		$mergefromid = $object->context['mergefromid'];
		$tables = array(
			'stancer_stancer_payments',
			'stancer_stancer_refunds',
		);

		foreach ($tables as $table) {
			$sql = "UPDATE ".MAIN_DB_PREFIX.$table
				." SET fk_soc = ".((int) $object->id)
				." WHERE fk_soc = ".((int) $mergefromid);
			$resql = $this->db->query($sql);
			if ($resql) {
				dol_syslog("stancer::trigger merge fk_soc updated in ".$table." from ".$mergefromid." to ".$object->id, LOG_DEBUG);
			} else {
				dol_syslog("stancer::trigger merge fk_soc update failed in ".$table." for old id ".$mergefromid.": ".$this->db->lasterror(), LOG_ERR);
				return -1;
			}
		}

		return 0;
	}
}
