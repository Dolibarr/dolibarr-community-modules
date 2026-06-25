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
 * \file    core/triggers/interface_99_modEInvoicing_EInvoicingTriggers.class.php
 * \ingroup einvoicing
 * \brief   Triggers for EInvoicing module
 */

require_once DOL_DOCUMENT_ROOT.'/core/triggers/dolibarrtriggers.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/einvoicing/class/helpers/SupplierInvoiceHelper.class.php';


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
		$this->version = 'development';
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

			// Default product for import
			$routingProductId = GETPOST('routing_product_id', 'aZ09');
			if ($routingProductId !== '' && $routingProductId !== '-1') {
				$existing = $einvoicing->fetchDefaultRouting($socId, 'product');
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

			if ($error) {
				return -4;
			}
		}

		if ($action == 'COMPANY_MODIFY') {
			/** @var Societe $object */
			// If we modify the country of a thirdparty, we update status of invoice
			// FR->other: status must be modified from "To generate" into "To ignore"
			// Other->FR: status must be modified from "To ignore" into "To generate"
			// TODO
		}

		// INVOICES AND PAYMENT
		if ($action == 'BILL_CREATE') {
			/** @var Facture $object */
			$einvoicing = new EInvoicing($this->db);

			// When invoice is created
			$result = $einvoicing->setEInvoiceStatus($object, GETPOST('seteinvoicestatus'), '');
			if ($result < 0) {
				$this->errors[] = $einvoicing->errors;
				return -1;
			}
		}

		if ($action == 'BILL_VALIDATE') {
			/** @var Facture $object */
			$einvoicing = new EInvoicing($this->db);

			$result = $einvoicing->fetchLastknownInvoiceStatus($object->id, $object->ref);

			// If $result is $einvoicing::STATUS_IGNORE, we do nothing.

			// If einvoice was set to $einvoicing::STATUS_NOT_GENERATED or $einvoicing::STATUS_UNKNOWN, we set it to STATUS_IGNORE (if not qualified for einvoice) or STATUS_NOT_GENERATED (if qualified for einvoice)
			if ($result['code'] == $einvoicing::STATUS_NOT_GENERATED || $result['code'] == $einvoicing::STATUS_UNKNOWN) {
				// By default, we set status to ignore
				$statustouse = $einvoicing::STATUS_IGNORE;
				// Test if invoice need to be managed by EInvoice
				$needEinvoice = $einvoicing->needEInvoiceManagement($object);
				if ($needEinvoice) {
					$statustouse = $needEinvoice;
				}

				$newobject = dol_clone($object, 2);
				$newobject->ref = $object->newref;

				$result = $einvoicing->setEInvoiceStatus($newobject, $statustouse, '');
				if ($result < 0) {
					$this->errors[] = $einvoicing->errors;
					return -1;
				}
			}
		}

		if ($action == 'BILL_UNVALIDATE') {
			/** @var Facture $object */
			$einvoicing = new EInvoicing($this->db);
			$result = $einvoicing->fetchLastknownInvoiceStatus($object->id, $object->ref);

			// If einvoice has been transmitted, we must check that we don't try to modify some fields
			if (is_array($result) && !in_array($result['code'], array($einvoicing::STATUS_UNKNOWN, $einvoicing::STATUS_IGNORE, $einvoicing::STATUS_NOT_GENERATED, $einvoicing::STATUS_GENERATED))) {
				$this->errors[] = 'You try to modify the status of an invoice that is locked once the invoice has been transmitted to the Access Point';
				return -3;
			}
		}

		if ($action == 'BILL_MODIFY') {
			/** @var Facture $object */
			$einvoicing = new EInvoicing($this->db);
			$result = $einvoicing->fetchLastknownInvoiceStatus($object->id, $object->ref);

			// If einvoice has been transmitted, we must check that we don't try to modify some fields
			if (is_array($result) && !in_array($result['code'], array($einvoicing::STATUS_UNKNOWN, $einvoicing::STATUS_IGNORE, $einvoicing::STATUS_NOT_GENERATED, $einvoicing::STATUS_GENERATED))) {
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
				$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."einvoicing_extlinks WHERE element_id = ".((int) $object->id)." AND element_type = '" . $object->element . "'";
				$resql = $this->db->query($sql);
				if ($resql && $this->db->num_rows($resql) > 0) {
					// If invoice is transmitted, check if any locked field is modified.;
					foreach ($lockedFields as $field) {
						if ($object->$field != $object->oldcopy->$field) {
							$this->errors[] = 'You try to modify a property that is locked once the invoice has been transmitted to the Access Point';
							return -2;
						}
					}
					return 1; // Return >0 if OK.
				}
			}
		}

		if ($action == 'BILL_PAYED') {
			/** @var Facture $object */
			// Check if the invoice is transmitted to EInvoicing.
			$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."einvoicing_extlinks WHERE element_id = ".((int) $object->id)." AND element_type = '" . $object->element . "'";
			$resql = $this->db->query($sql);
			if ($resql && $this->db->num_rows($resql) > 0) {
				$PDPManager = new PDPProviderManager($this->db);
				$provider = $PDPManager->getProvider(getDolGlobalString('EINVOICING_PDP'));
				$result = $provider->sendStatusMessage($object, 212); // Send status message

				if ($result['res'] > 0) {
					setEventMessage('PDP Connect : '.$langs->trans('EInvStatus212Paid'), 'mesgs');
				} else {
					setEventMessage('PDP Connect : '.$result['message'], 'errors');
				}
			}
		}

		// SUPPLIER INVOICES AND PAYMENTS
		if ($action == 'BILL_SUPPLIER_VALIDATE') {
			if (getDolGlobalInt('EINVOICING_SUPPLIER_INVOICE_CHECK_CONSISTENCY_ON_VALIDATION') && SupplierInvoiceHelper::isEInvoice($object->id)) {
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
		}

		// EINVOICING DOCUMENTS
		if ($action == 'DOCUMENT_DELETE') {
			/**
			 * @var Document $object
			 */
			'@phan-var-force Document $object';
			if ($object->fk_element_type == 'invoice_supplier' && SupplierInvoiceHelper::isEInvoice($object->fk_element_id, true)) {
				$this->errors[] = $langs->trans('EinvoicingCantDeleteADocumentLinkedToAnExistingSupplierInvoice', $object->id, $object->fk_element_id);
				return -1;
			}
		}

		return 0;
	}
}
