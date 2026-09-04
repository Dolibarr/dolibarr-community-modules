<?php
/* Copyright (C) 2023		Laurent Destailleur			<eldy@users.sourceforge.net>
 * Copyright (C) 2026		Mohamed DAOUD				<daoud.mouhamed@gmail.com>
 * Copyright (C) 2026		Frédéric France				<frederic.france@free.fr>
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
 * \file    core/triggers/interface_98_modEInvoicing_EInvoicingTriggers.class.php
 * \ingroup einvoicing
 * \brief   Triggers for EInvoicing module
 */

require_once DOL_DOCUMENT_ROOT.'/core/triggers/dolibarrtriggers.class.php';
dol_include_once('einvoicing/class/utils/SupplierInvoiceHelper.class.php');
// The classes below happen to be already loaded when the action comes from the module screens, but not
// when a trigger fires from a context that never went through them: cron, CLI, REST API, bank import...
dol_include_once('einvoicing/class/einvoicing.class.php');
dol_include_once('einvoicing/class/providers/PDPProviderManager.class.php');


/**
 *  Class of triggers for EInvoicing module
 */
class InterfaceEInvoicingTriggers extends DolibarrTriggers
{
	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		parent::__construct($db);
		$this->family = "einvoicing";
		$this->description = "EInvoicing triggers.";
		$this->version = 'dolibarr';
		$this->picto = 'einvoicing@einvoicing';
	}

	/**
	 * EInvoicing trigger run function
	 *
	 * @param string 		$action 	Event action code
	 * @param CommonObject 	$object 	Object
	 * @param User 			$user 		Object user
	 * @param Translate 	$langs 		Object langs
	 * @param Conf 			$conf 		Object conf
	 * @return int              		Return integer <0 if KO, 0 if no triggered ran, >0 if OK
	 */
	public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
	{
		if (!isModEnabled('einvoicing')) {
			return 0;
		}

		$error = 0;

		dol_syslog("Trigger '".$this->name."' for action '".$action."' launched by ".__FILE__.". id=".$object->id);

		// Note: Option EINVOICING_AUTO_SEND_ON_GENERATION is managed in hook afterPDFCreation(), which is
		// told by the BILL_VALIDATE case below that a validation is what triggers the coming generation.

		// THIRD PARTIES
		if ($action == 'COMPANY_CREATE' || $action == 'COMPANY_MODIFY') {
			/** @var Societe $object */
			$einvoicing = new EInvoicing($this->db);

			$socId = $object->id;

			// Thirdparty routing ID
			$routingId = GETPOST('routing_id', 'alphanohtml');
			if ($routingId !== '') {
				$existing = $einvoicing->fetchDefaultRouting($socId, 'thirdparty');
				if (empty($existing)) {
					$result = $einvoicing->addRouting($socId, $routingId, '', 'thirdparty');
				} else {
					$result = $einvoicing->setDefaultRouting($socId, $routingId, '', '', '', 'thirdparty');
				}
				if ($result < 0) {
					$error++;
					$this->errors[] = $langs->trans('FailedToSaveRoutingID').' '.$einvoicing->error;
				}
			}

			// Default product for import.
			// The combo posts '-1' when the empty entry is picked, and '' when the ajax search input is
			// cleared: both mean "no default product any more", so the routing has to be deleted. Two
			// saves must leave the current value untouched: one that does not carry the field at all
			// (thirdparty updated from the API, a mass action, an import...), and one whose field could
			// not show the current value, which routing_product_id_shown tells apart.
			if (GETPOSTISSET('routing_product_id')) {
				$routingProductId = GETPOST('routing_product_id', 'aZ09');
				if ($routingProductId === '-1' || $routingProductId === '0') {
					$routingProductId = '';
				}
				$shownProductId = GETPOST('routing_product_id_shown', 'aZ09');
				if ($shownProductId === '-1' || $shownProductId === '0') {
					$shownProductId = '';
				}
				$existing = $einvoicing->fetchDefaultRouting($socId, 'product');
				$result = 0;
				if ($routingProductId === '') {
					if ($shownProductId !== '' && !empty($existing)) {
						// setDefaultRouting() with an empty value only deletes the existing routing
						$result = $einvoicing->setDefaultRouting($socId, '', '', '', '', 'product');
						if ($result < 0) {
							$error++;
							$this->errors[] = $langs->trans('FailedToDeleteRoutingID').' '.$einvoicing->error;
						}
					}
				} else {
					if (empty($existing)) {
						$result = $einvoicing->addRouting($socId, $routingProductId, '', 'product');
					} else {
						$result = $einvoicing->setDefaultRouting($socId, $routingProductId, '', '', '', 'product');
					}
					if ($result < 0) {
						$error++;
						$this->errors[] = $langs->trans('FailedToSaveRoutingID').' '.$einvoicing->error;
					}
				}
			}

			if ($error) {
				return -4;
			}
		}

		if ($action == 'COMPANY_MODIFY') {
			/** @var Societe $object */
			// If we modify the country of a thirdparty, we can update status of its invoice
			// FR->other: status must be modified from "To generate" into "To ignore"
			// Other->FR: status must be modified from "To ignore" into "To generate"
			// TODO
		}

		// INVOICES AND PAYMENT
		if ($action == 'BILL_CREATE') {
			/** @var Facture $object */
			'@phan-var-force Facture $object';

			if (!getDolGlobalString('EINVOICING_DISABLE_SYNC_DOLI_TO_AP')) {		// If sync Dolibarr to AP is on
				$einvoicing = new EInvoicing($this->db);

				if (GETPOSTISSET('seteinvoicestatus')) {
					$statustouse = GETPOST('seteinvoicestatus');
				} else {
					$statustouse = $einvoicing->needEInvoiceManagement($object);
				}

				// When invoice is created
				$result = $einvoicing->setEInvoiceStatus($object, $statustouse, '');
				if ($result < 0) {
					$this->errors = array_merge($this->errors, $einvoicing->errors);
					return -1;
				}
			}
		}

		if ($action == 'BILL_VALIDATE') {
			/** @var Facture $object */
			'@phan-var-force Facture $object';

			// Tell the afterPDFCreation() hook that the document rebuild about to happen is the one that
			// follows a validation. Set unconditionally and before anything else: this only records a fact
			// about the request, and the hook is the one place that decides what to do with it.
			EInvoicing::setInvoiceValidatedInThisRequest($object->id);

			if (!getDolGlobalString('EINVOICING_DISABLE_SYNC_DOLI_TO_AP')) {		// If sync Dolibarr to AP is on
				$einvoicing = new EInvoicing($this->db);

				$result = $einvoicing->fetchLastknownInvoiceStatus($object->id, (string) $object->ref);

				// If $result is $einvoicing::STATUS_IGNORE or STATUS_IGNORE_2, we do nothing.

				// If einvoice was set to $einvoicing::STATUS_NOT_GENERATED or $einvoicing::STATUS_UNKNOWN, we set it to STATUS_IGNORE (if not qualified for einvoice) or STATUS_NOT_GENERATED (if qualified for einvoice)
				if ($result['code'] == $einvoicing::STATUS_NOT_GENERATED || $result['code'] == $einvoicing::STATUS_UNKNOWN) {
					$statustouse = $einvoicing::STATUS_IGNORE;	// default status to use if none of following rules match

					// Test if invoice need to be managed by EInvoice
					$needEinvoice = $einvoicing->needEInvoiceManagement($object);
					if ($needEinvoice) {
						$statustouse = $needEinvoice;
					}

					$newobject = dol_clone($object, 2);
					$newobject->ref = (string) $object->newref;

					$result = $einvoicing->setEInvoiceStatus($newobject, $statustouse, '');
					if ($result < 0) {
						$this->errors = array_merge($this->errors, $einvoicing->errors);
						return -1;
					}
				}
			}
		}

		if ($action == 'BILL_UNVALIDATE') {
			/** @var Facture $object */
			'@phan-var-force Facture $object';
			$einvoicing = new EInvoicing($this->db);

			// Lock on the REAL PA state (persistent flow_id), not the Dolibarr syncstatus which is reset to
			// GENERATED by a regenerate and would otherwise unlock a transmitted invoice. Honors the
			// EINVOICING_ALLOW_RESEND_TRANSMITTED opt-out.
			if ($einvoicing->isTransmittedLockActive($object->id, (string) $object->ref)) {
				$this->errors[] = $langs->trans('EinvoicingCantUnvalidateATransmittedInvoice');
				return -3;
			}
		}

		if ($action == 'BILL_DELETE') {
			/** @var Facture $object */
			'@phan-var-force Facture $object';
			$einvoicing = new EInvoicing($this->db);

			// Lock on the REAL PA state (persistent flow_id), see BILL_UNVALIDATE above.
			if ($einvoicing->isTransmittedLockActive($object->id, (string) $object->ref)) {
				$this->errors[] = $langs->trans('EinvoicingCantDeleteATransmittedInvoice');
				return -1;
			}
		}

		if ($action == 'BILL_MODIFY') {
			/** @var Facture $object */
			'@phan-var-force Facture $object';
			$einvoicing = new EInvoicing($this->db);

			// Lock on the REAL PA state (persistent flow_id), see BILL_UNVALIDATE above.
			if ($einvoicing->isTransmittedLockActive($object->id, (string) $object->ref)) {
				// Fields that are locked after transmission.
				$lockedFields = array(
					'ref',
					'date',
					'date_lim_reglement',
					'multicurrency_code',
					'total_ht',
					'total_tva',
					'total_ttc',
					'fk_soc',
					'cond_reglement_id',
					'mode_reglement_id'
				);

				// Check if the invoice is transmitted to EInvoicing.
				$currentStatusDetails = $einvoicing->fetchLastknownInvoiceStatus($object->id, (string) $object->ref);

				if ($currentStatusDetails['transmitted'] == 1) {	// If invoice already transmitted
					// If invoice is transmitted, check if any locked field is modified.;
					foreach ($lockedFields as $field) {
						if ($object->$field != $object->oldcopy->$field) {
							$this->errors[] = $langs->trans('EinvoicingCantModifyATransmittedInvoice');
							return -2;
						}
					}
					return 1; // Return >0 if OK.
				}
			}
		}

		// fr:212 (Encaissee) is reported per cash-in, not once when the invoice gets fully paid: the reform
		// expects the date and the amount of EVERY payment, partial ones included, so a 2-instalment invoice
		// owes 2 statuses. Hooking the payment creation (and not BILL_PAYED) also covers the invoices that
		// stay partially paid forever, and skips the write-offs (abandon / bad debt) where nothing is cashed.
		if ($action == 'PAYMENT_CUSTOMER_CREATE') {
			/** @var Paiement $object */
			'@phan-var-force Paiement $object';

			if (!getDolGlobalString('EINVOICING_DISABLE_SYNC_DOLI_TO_AP')) {		// If sync Dolibarr to AP is on
				require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';

				foreach ($object->amounts as $facid => $amount) {
					$amount = (float) $amount;
					if ($amount <= 0) {		// Payment lines with no amount, or a refund line: nothing cashed in
						continue;
					}

					$invoice = new Facture($this->db);
					if ($invoice->fetch((int) $facid) <= 0) {
						dol_syslog(__METHOD__ . ' Cannot load invoice id=' . $facid . ' of payment id=' . $object->id, LOG_ERR, 0, '_einvoicing');
						continue;
					}

					$this->sendCashedInStatus($invoice, $amount, $langs);
				}
			}
		}

		// SUPPLIER INVOICES AND PAYMENTS
		if ($action == 'BILL_SUPPLIER_VALIDATE') {
			/** @var FactureFournisseur $object */
			'@phan-var-force FactureFournisseur $object';
			$duplicate = false;
			if (getDolGlobalInt('EINVOICING_SUPPLIER_INVOICE_CHECK_CONSISTENCY_ON_VALIDATION') && SupplierInvoiceHelper::isEInvoice($object->id, false, $duplicate)) {
				if ($duplicate) {
					// Comparing against one of two conflicting e-invoicing documents would be meaningless
					$this->errors[] = $langs->trans('EinvoicingDuplicateDocumentForSupplierInvoice', $object->id);
					return -1;
				}
				// Ensure e-invoice and dol-invoice contains consistent data
				$resComparison = SupplierInvoiceHelper::checkDolInvoiceAndEInvoiceConsistency($object);
				if (!$resComparison['identical']) {
					$this->errors[] = $langs->trans('EInvoiceAndDolInvoiceComparisonFailed');
					foreach ($resComparison['errors'] as $errorMsg) {
						$this->errors[] = '- ' . $errorMsg;
					}
					return -1;
				}
			}

			// A validated replacement invoice supersedes the one it replaces, which must not stay open
			// for payment. The core does exactly that on the customer side and nothing on the supplier
			// side, so a replaced supplier invoice used to keep every action of an ordinary one
			// (issue #549). Never escalated to $this->errors: a failure here must not undo a validation
			// the operator asked for, and the log carries the reason.
			if (SupplierInvoiceHelper::closeReplacedSupplierInvoice($object, $user) > 0) {
				setEventMessage($langs->trans("ModuleEInvoicingName") . ' : ' . $langs->trans('EInvoiceReplacedSupplierInvoiceClosed', $object->ref), 'mesgs');
			}

			// fr:205 (Approved) is the answer the buyer owes its vendor on a received invoice, and
			// validating that invoice in Dolibarr is the act of accepting it: it leaves the draft state to
			// enter the accounts and become payable. So the status is sent here rather than waiting for
			// someone to remember the button on the card, which is how it was reported until now - and a
			// vendor left without an answer has no way to tell an accepted invoice from a forgotten one.
			$einvoicing = new EInvoicing($this->db);
			if (SupplierInvoiceHelper::shouldSendApprovedOnValidation($einvoicing, (int) $object->id, $object->element)) {
				$PDPManager = new PDPProviderManager($this->db);
				$provider = $PDPManager->getProvider(getDolGlobalString('EINVOICING_PDP'));

				$result = $provider->sendStatusMessage($object, EInvoicing::STATUS_APPROVED);

				if ($result['res'] > 0) {
					setEventMessage($langs->trans("ModuleEInvoicingName") . ' : ' . $langs->trans('EInvStatus205Approved'), 'mesgs');
				} else {
					// Never escalated to $this->errors / a negative return: that would roll back the
					// validation the operator asked for, and the status can still be sent by hand from the
					// card afterwards. dol_syslog is the only channel that reliably surfaces this outside
					// an interactive session (cron, API, mass validation, ...).
					dol_syslog(__METHOD__ . ' Failed to send approved status (205) to platform for supplier invoice id=' . $object->id . ' : ' . $result['message'], LOG_ERR);
					setEventMessage($langs->trans("ModuleEInvoicingName") . ' : ' . $result['message'], 'errors');
				}
			}
		}

		// fr:211 (Paiement transmis) is what we, as the buyer, tell the vendor once we have paid one of
		// its invoices. Nothing in the reform makes it mandatory and it costs a platform flow, so it is
		// sent only when EINVOICING_SEND_PAYMENT_SENT_STATUS is on, and only once per invoice: a payment
		// deleted then recorded anew makes Dolibarr classify the invoice paid a second time.
		if ($action == 'BILL_SUPPLIER_PAYED') {
			/** @var FactureFournisseur $object */
			'@phan-var-force FactureFournisseur $object';

			if (getDolGlobalInt('EINVOICING_SEND_PAYMENT_SENT_STATUS') && !getDolGlobalString('EINVOICING_DISABLE_SYNC_DOLI_TO_AP')) {
				$paidAmount = (float) $object->getSommePaiement();

				// Nothing to tell on a write-off (nothing was paid), nor on an invoice that never came
				// from the platform: the vendor would not know the status we are answering to.
				if ($paidAmount > 0 && SupplierInvoiceHelper::isEInvoice($object->id)) {
					$einvoicing = new EInvoicing($this->db);

					if (!$einvoicing->hasSentStatusMessage($object->id, $object->element, EInvoicing::STATUS_PAYMENT_SENT)) {
						$PDPManager = new PDPProviderManager($this->db);
						$provider = $PDPManager->getProvider(getDolGlobalString('EINVOICING_PDP'));

						$result = $provider->sendStatusMessage($object, EInvoicing::STATUS_PAYMENT_SENT, '', array('amount' => $paidAmount, 'date' => dol_now()));

						if ($result['res'] > 0) {
							setEventMessage($langs->trans("ModuleEInvoicingName").' : '.$langs->trans('EInvStatus211PaymentTransmitted'), 'mesgs');
						} else {
							// Never escalated to $this->errors / a negative return: that would roll back the
							// payment Dolibarr just recorded, and a platform notification failure must not
							// undo a real payment. dol_syslog is the only channel that reliably surfaces
							// this outside an interactive session (cron, API, bank import, ...).
							dol_syslog(__METHOD__ . ' Failed to send payment transmitted status (211) to platform for supplier invoice id=' . $object->id . ' : ' . $result['message'], LOG_ERR);
							setEventMessage($langs->trans("ModuleEInvoicingName").' : '.$result['message'], 'errors');
						}
					}
				}
			}
		}

		if ($action == 'BILL_SUPPLIER_DELETE') {
			/** @var FactureFournisseur $object */
			'@phan-var-force FactureFournisseur $object';
			$duplicate = false;
			if (SupplierInvoiceHelper::isEInvoice($object->id, true, $duplicate)) {
				if ($duplicate) {
					$this->errors[] = $langs->trans('EinvoicingDuplicateDocumentForSupplierInvoice', $object->id);
					return -1;
				}

				// A draft is a local booking, not the electronic invoice: it holds no accounting entry and
				// says nothing to the platform, so removing it repudiates nothing. It is also the only way
				// out of an import that landed on the wrong third party, since the vendor of an existing
				// supplier invoice cannot be changed. Read the status from the database: the object handed
				// to a trigger is not always freshly fetched.
				// The incoming flow itself is kept, detached from the invoice that is about to disappear,
				// so the document stays in the flow list and can be imported again.
				$status = -1;
				$sqlstatus = "SELECT fk_statut FROM ".MAIN_DB_PREFIX."facture_fourn WHERE rowid = ".((int) $object->id);
				$resqlstatus = $this->db->query($sqlstatus);
				if ($resqlstatus) {
					$objstatus = $this->db->fetch_object($resqlstatus);
					if ($objstatus) {
						$status = (int) $objstatus->fk_statut;
					}
					$this->db->free($resqlstatus);
				}

				if ($status !== FactureFournisseur::STATUS_DRAFT) {
					// A new key rather than the wording that was here: the old one asked to approve or refuse
					// the invoice, which never unlocked the deletion, and its translations still say so.
					$this->errors[] = $langs->trans('EinvoicingCantDeleteAValidatedSupplierInvoice');
					return -1;
				}

				if ($this->detachEInvoicingRecordsOfSupplierInvoice((int) $object->id) < 0) {
					$this->errors[] = $langs->trans('EinvoicingFailedToDetachTheFlowOfADeletedSupplierInvoice', $object->id);
					return -1;
				}
			}
		}

		// EINVOICING DOCUMENTS
		if ($action == 'DOCUMENT_DELETE') {
			/**
			 * @var Document $object
			 */
			'@phan-var-force Document $object';
			$duplicate = false;

			// A flow does not always carry a supplier invoice id: a lifecycle message (flow_type
			// 'SupplierInvoiceLC') sets fk_element_type without ever resolving an invoice, an import that
			// failed never booked one, and deleting the local invoice detaches the flow it came from
			// (see detachEInvoicingRecordsOfSupplierInvoice() below, which sets the column to 0). The
			// column is nullable, so the value read here is null in the first two cases: passing it on
			// used to raise an uncaught TypeError on the int parameter of isEInvoice() and end the mass
			// deletion on a PHP fatal. Such a flow is linked to nothing, so there is nothing to protect
			// and the deletion is allowed - as it already was for the detached (0) case.
			$linkedsupplierinvoiceid = (int) $object->fk_element_id;

			if ($object->fk_element_type == 'invoice_supplier' && $linkedsupplierinvoiceid > 0 && SupplierInvoiceHelper::isEInvoice($linkedsupplierinvoiceid, true, $duplicate)) {
				$lastid = 0;

				// Test if einvoice is the last one(in this case, we may accept to delete the document, record will be loaded at next sync
				// If not, we refuse to delete the document.
				// Restricted to the entities the deletion is made from: on a multicompany setup the highest
				// rowid of the whole table usually belongs to another entity, and the flow being deleted
				// would then be refused (or accepted) on the strength of a record its owner cannot even see.
				$sqlgetlast = "SELECT rowid FROM ".MAIN_DB_PREFIX."einvoicing_document";
				$sqlgetlast .= " WHERE entity IN (".getEntity($object->element).")";
				$sqlgetlast .= " ORDER BY rowid DESC LIMIT 1";
				$resql = $this->db->query($sqlgetlast);
				if ($resql) {
					$obj = $this->db->fetch_object($resql);
					if ($obj) {
						$lastid = $obj->rowid;
					}
				}

				if ($object->id != $lastid) {	// If not the last one,
					$this->errors[] = $duplicate
						? $langs->trans('EinvoicingDuplicateDocumentForSupplierInvoice', $object->fk_element_id)
						: $langs->trans('EinvoicingCantDeleteADocumentLinkedToAnExistingSupplierInvoice', $object->id, $object->fk_element_id);
					return -1;
				}
			}
		}

		return 0;
	}

	/**
	 * Report a cash-in (status 212 "Encaissee") of a customer invoice to the Approved Platform.
	 *
	 * Errors are never escalated to $this->errors / a negative return: that would roll back the payment
	 * Dolibarr just recorded (Paiement::create() aborts on a trigger failure) and a platform notification
	 * failure must never undo a real payment. dol_syslog is the only channel that reliably surfaces the
	 * problem outside an interactive session (cron, API, bank import, ...), since setEventMessage() only
	 * shows up on the next HTML page render.
	 *
	 * @param  Facture   $invoice Invoice that has been cashed in
	 * @param  float     $amount  Amount cashed in (TTC) by this payment, reported as the MEN blocks of the CDAR
	 * @param  Translate $langs   Translate object
	 * @return void
	 */
	private function sendCashedInStatus($invoice, $amount, Translate $langs)
	{
		$einvoicing = new EInvoicing($this->db);

		// Ask the boolean question: needEInvoiceManagement() answers with a status code whose ignore values
		// are truthy. An invoice out of the e-invoicing scope has no cash-in to report.
		if (!$einvoicing->mustManageEInvoice($invoice)) {
			return;
		}

		if (!$this->needCashedInStatus($invoice)) {
			return;
		}

		$currentStatusDetails = $einvoicing->fetchLastknownInvoiceStatus($invoice->id, (string) $invoice->ref);
		if ($currentStatusDetails['transmitted'] != 1) {	// Nothing to report a payment on if the invoice never reached the platform
			return;
		}

		// A deposit the platform refused is not a deposit. 'transmitted' is true of every status but the
		// local ones, and STATUS_ERROR is among those it lets through: it is exactly what an
		// acknowledgement "Error" leaves behind, see getDolibarrStatusCodeFromPdpLabel(). The platform
		// holds no invoice to attach a cash-in to, so the status would be refused; and reporting it as
		// sent would be worse than not sending it, since the reform expects that cash-in once the
		// invoice is deposited. The invoice has to be corrected and re-sent first, which is what the
		// "Send" button of the card offers on that very status, and the cash-in reported by hand
		// afterwards.
		if ((int) $currentStatusDetails['code'] === EInvoicing::STATUS_ERROR) {
			dol_syslog(__METHOD__ . ' Cash-in not reported for invoice id=' . $invoice->id . ': the platform refused its deposit (status ' . EInvoicing::STATUS_ERROR . '), there is nothing to report the payment on', LOG_WARNING, 0, '_einvoicing');
			setEventMessage($langs->trans("ModuleEInvoicingName") . ' : ' . $langs->trans('EInvoiceCashInNotReportedDepositRefused', $invoice->ref), 'warnings');
			return;
		}

		$PDPManager = new PDPProviderManager($this->db);
		$provider = $PDPManager->getProvider(getDolGlobalString('EINVOICING_PDP'));

		$result = $provider->sendStatusMessage($invoice, 212, '', array('amount' => $amount));

		if ($result['res'] > 0) {
			setEventMessage($langs->trans("ModuleEInvoicingName").' : '.$langs->trans('EInvStatus212Paid'), 'mesgs');
		} else {
			dol_syslog(__METHOD__ . ' Failed to send paid status (212) to platform for invoice id=' . $invoice->id . ' : ' . $result['message'], LOG_ERR);
			setEventMessage($langs->trans("ModuleEInvoicingName").' : '.$result['message'], 'errors');
		}
	}

	/**
	 * Tell whether a cash-in on this invoice has to be reported with the status 212 (Encaissee).
	 *
	 * The reform only requires the payment data for the operations whose VAT is due on collection, which is
	 * exactly what the VAT exigibility scheme of the company says. einvoicingVatDueOnCollection() answers
	 * that from the VAT mode of the Tax/VAT module setup, the one place that holds it, and the generated
	 * document answers the neighbouring question in BT-8.
	 *
	 * @param  Facture $invoice Invoice that has been cashed in
	 * @return bool             True if the status has to be sent
	 */
	private function needCashedInStatus($invoice)
	{
		// VAT on a down payment falls due when the down payment is collected, whatever the scheme: the
		// debits option is set aside by a payment received before the debit, and it may not delay the
		// exigibility anyway (CGI art. 269-2).
		if ($invoice->type == Facture::TYPE_DEPOSIT) {
			return true;
		}

		if (empty($invoice->lines)) {
			$invoice->fetch_lines();
		}

		// Product::TYPE_PRODUCT / TYPE_SERVICE, without requiring the class here. Anything else is a
		// pseudo-line carrying no VAT (title, subtotal, page break) and is not a kind of operation:
		// the document builder leaves those out of the same decision.
		$hasProductLine = false;
		$hasServiceLine = false;
		foreach ($invoice->lines as $line) {
			if ((int) $line->product_type === 1) {
				$hasServiceLine = true;
			} elseif ((int) $line->product_type === 0) {
				$hasProductLine = true;
			}
		}

		return einvoicingVatDueOnCollection($hasProductLine, $hasServiceLine);
	}

	/**
	 * Detach the e-invoicing records of a supplier invoice that is about to be deleted.
	 *
	 * The incoming flow is not the Dolibarr invoice: it belongs to the platform and keeps its
	 * lifecycle, so it is kept and only unlinked (fk_element_id emptied). The extlinks row, on the
	 * other hand, describes the local element itself and is removed with it - leaving it behind
	 * would make the flow list show a link to an invoice that no longer exists.
	 *
	 * Runs inside the transaction opened by FactureFournisseur::delete(), which rolls back when
	 * this trigger fails.
	 *
	 * @param	int		$supplierInvoiceId	Id of the supplier invoice being deleted
	 * @return	int							Return integer <0 if KO, >0 if OK
	 */
	private function detachEInvoicingRecordsOfSupplierInvoice($supplierInvoiceId)
	{
		$sql = "UPDATE ".MAIN_DB_PREFIX."einvoicing_document";
		$sql .= " SET fk_element_id = 0";
		$sql .= " WHERE fk_element_type = 'invoice_supplier'";
		$sql .= " AND fk_element_id = ".((int) $supplierInvoiceId);
		if (!$this->db->query($sql)) {
			dol_syslog(__METHOD__.' '.$this->db->lasterror(), LOG_ERR);
			return -1;
		}

		$sql = "DELETE FROM ".MAIN_DB_PREFIX."einvoicing_extlinks";
		$sql .= " WHERE element_type = 'invoice_supplier'";
		$sql .= " AND element_id = ".((int) $supplierInvoiceId);
		if (!$this->db->query($sql)) {
			dol_syslog(__METHOD__.' '.$this->db->lasterror(), LOG_ERR);
			return -1;
		}

		return 1;
	}
}
