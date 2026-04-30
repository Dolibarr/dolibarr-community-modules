<?php
/* Copyright (C) 2025		Mohamed Daoud				<mdaoud@dolicloud.com>
 * Copyright (C) 2025		Laurent Destailleur			<eldy@users.sourceforge.net>
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
 * \file    pdpconnectfr/class/actions_pdpconnectfr.class.php
 * \ingroup pdpconnectfr
 * \brief   Hook of module
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonhookactions.class.php';
require_once __DIR__ . "/pdpconnectfr.class.php";
dol_include_once('/pdpconnectfr/class/providers/PDPProviderManager.class.php');

/**
 * Class for hooks of module
 */
class ActionsPdpconnectfr extends CommonHookActions
{
	/**
	 * Hook called after a PDF is created
	 *
	 * @param 	array   		$parameters 	Hook parameters
	 * @param 	CommonObject 	$object 		The object related to the PDF (invoice, order, etc.)
	 * @param 	string  		$action     	Current action
	 * @param 	HookManager 	$hookmanager 	Hook manager instance
	 * @return 	int    			0 or 1
	 */
	public function afterPDFCreation($parameters, &$object, &$action, $hookmanager)
	{
		global $db, $langs;

		dol_syslog(__METHOD__ . " Hook afterPDFCreation called for object " . get_class($object));

		$outputlangs = $langs;

		// Invoice pdf path
		$pdfPath = $parameters['file'];

		$pdpConnectFr = new PdpConnectFr($db);
		$checkConfig = $pdpConnectFr->checkModulePrerequisites();
		if ($checkConfig < 0) {
			dol_syslog(__METHOD__ . "PDPCONNECTFR Module is not correctly configured.");
			return 0;
		}

		$invoiceObject = $parameters['object'];
		$invoiceObject->fetch_thirdparty();
		$thirdpartyCountryCode = $invoiceObject->thirdparty->country_code;

		// Check if it's an invoice
		if (get_class($invoiceObject) === 'Facture' && $thirdpartyCountryCode === 'FR') {
			/** @var Facture $invoiceObject */
			if ($invoiceObject->status != $invoiceObject::STATUS_DRAFT && getDolGlobalString('PDPCONNECTFR_EINVOICE_IN_REAL_TIME')) {
				// Call function to create Factur-X document
				require_once __DIR__ . '/protocols/ProtocolManager.class.php';

				$usedProtocols = getDolGlobalString('PDPCONNECTFR_PROTOCOL');
				$ProtocolManager = new ProtocolManager($db);
				$protocol = $ProtocolManager->getProtocol($usedProtocols);

				// Check configuration
				$result = $pdpConnectFr->checkRequiredinformations($invoiceObject);
				if ($result['res'] < 0) {
					$message = $langs->trans("InvoiceNotgeneratedDueToConfigurationIssues") . ': <br>' . $result['message'];

					dol_syslog(__METHOD__ . " " . $message);

					if (getDolGlobalString('PDPCONNECTFR_EINVOICE_CANCEL_IF_EINVOICE_FAILS')) {
						// TODO : Remove this conf or add more conditions like thirdparty nature to avoid blocking invoice creation for non FR companies or for thirdparties that are not subject to E-invoicing obligation
						setEventMessages($message, array(), 'errors');
						// $this->errors[] = $message;
						return -1;
					} else {
						setEventMessages($message, array(), 'warnings');
						$this->warnings[] = $message;
						return 0;
					}
				} elseif ($result['res'] == 0) {
					$message = $langs->trans("InvoiceGeneratedWithWarnings") . ': <br>' . $result['message'];
					$this->warnings[] = $message;

					dol_syslog(__METHOD__ . " " . $message);
					setEventMessages($message, array(), 'warnings');
				}

				$result = $protocol->generateInvoice($invoiceObject, $outputlangs);		// Generate E-invoice

				if ($result && (!is_numeric($result) || $result > 0)) {
					// No error;
					setEventMessages($langs->trans("EInvoiceGenerated"), array(), 'mesgs');
				} else {
					if (getDolGlobalString('PDPCONNECTFR_EINVOICE_CANCEL_IF_EINVOICE_FAILS')) {
						$this->errors = array_merge($this->errors, $protocol->errors);
						return -1;
					} else {
						return 0;
					}
				}
			}
		}

		return 0;
	}


	/**
	 * Overload the addMoreActionsButtons function : replacing the parent's function with the one below
	 *
	 * @param	array<string,mixed>	$parameters     Hook metadata (context, etc...)
	 * @param	CommonObject		$object         The object to process (an invoice if you are in invoice module, a propale in propale's module, etc...)
	 * @param	?string				$action			Current action (if set). Generally create or edit or null
	 * @param	HookManager			$hookmanager	Hook manager propagated to allow calling another hook
	 * @return	int									Return integer < 0 on error, 0 on success, 1 to replace standard code
	 */
	public function addMoreActionsButtons($parameters, &$object, &$action, $hookmanager)
	{
		global $db, $langs, $user;

		$langs->load("pdpconnectfr@pdpconnectfr");
		$pdpConnectFr = new PdpConnectFr($db);
		$checkConfig = $pdpConnectFr->checkModulePrerequisites();
		if ($checkConfig < 0) {
			dol_syslog(__METHOD__ . "PDPCONNECTFR Module is not correctly configured.");
			return 0;
		}

		// Add buttons in invoice card
		if (in_array($object->element, ['facture'])) {
			// Get current status of e-invoice
			$currentStatusDetails = $pdpConnectFr->fetchLastknownInvoiceStatus(0, $object->ref);

			$url_button = array();
			if ($object->status == Facture::STATUS_VALIDATED || $object->status == Facture::STATUS_CLOSED) {
				// if E-invoice is not generated, show button to generate e-invoice
				if ($currentStatusDetails['code'] == $pdpConnectFr::STATUS_NOT_GENERATED
					|| !array_key_exists($currentStatusDetails['code'], $pdpConnectFr::STATUS_LABEL_KEYS)) {
					$url_button[] = array(
						'lang' => 'pdpconnectfr',
						'enabled' => 1,
						'perm' => (bool) $user->hasRight("facture", "creer"),
						'label' => $langs->trans('GenerateEinvoice'),
						//'help' => $langs->trans('GenerateEinvoiceHelp'),
						'url' => '/compta/facture/card.php?id=' . $object->id . '&action=generate_einvoice&token=' . newToken()
					);
				}

				// If the e-invoice is generated but not sent, or if it was sent and a validation error was received,
				// display the button to regenerate the e-invoice and the button to send the e-invoice.
				if (in_array($currentStatusDetails['code'], [
					$pdpConnectFr::STATUS_GENERATED,
					$pdpConnectFr::STATUS_ERROR,
					$pdpConnectFr::STATUS_UNKNOWN
				])) {
					$url_button[] = array(
						'lang' => 'pdpconnectfr',
						'enabled' => 1,
						'perm' => (bool) $user->hasRight("facture", "creer"),
						'label' => $langs->trans('RegenerateEinvoice'),
						//'help' => $langs->trans('RegenerateEinvoiceHelp'),
						'url' => '/compta/facture/card.php?id=' . $object->id . '&action=generate_einvoice&token=' . newToken()
					);

					$url_button[] = array(
						'lang' => 'pdpconnectfr',
						'enabled' => 1,
						'perm' => (bool) $user->hasRight("facture", "creer"),
						'label' => $langs->trans('sendToPDP'),
						//'help' => $langs->trans('SendToPDPHelp'),
						'url' => '/compta/facture/card.php?id=' . $object->id . '&action=send_to_pdp&token=' . newToken()
					);
				}
			}

			print '<!-- Current AP: '.getDolGlobalString('PDPCONNECTFR_PDP').' -->';
			print dolGetButtonAction('', $langs->trans('einvoice'), 'default', $url_button, '', true);
		}


		// Add buttons in supplier invoice card
		if (in_array($object->element, ['invoice_supplier'])) {
			// Check if this invoice is present into pdpconnectfr_extlinks table to know if it is an imported invoice from PDP or not
			$sql = "SELECT rowid, provider FROM ".MAIN_DB_PREFIX."pdpconnectfr_extlinks";
			$sql .= " WHERE element_type = '".$db->escape($object->element)."'";
			$sql .= " AND element_id = ".(int) $object->id;
			$sql .= " LIMIT 1";

			$resql = $db->query($sql);
			if ($resql && $db->num_rows($resql) > 0) {
				// TODO : we can link validate button to approval LC message or remove the approval message since it is not mandatory.
				// TODO : if Invoice is already refused, we should not display the button to send status message
				$availableStatuses = $pdpConnectFr->getEinvoiceStatusOptions(1, 1, 1);
				$url_button = array();
				foreach ($availableStatuses as $code => $label) {
					$url_button[] = array(
						'lang' => 'pdpconnectfr',
						'enabled' => 1,
						'perm' => (bool) $user->hasRight("facture", "creer"),
						'label' => $label,
						'url' => '/fourn/facture/card.php?id=' . $object->id . '&action=sendStatusMessage&pdpstatuscode=' . $code . '&token=' . newToken()
					);
				}

				print dolGetButtonAction('', $langs->trans('einvoice'), 'default', $url_button, '', true);
			}
		}

		return 0;
	}

	/**
	 * Overload the doActions
	 *
	 * @param	array<string,mixed>	$parameters     Hook metadata (context, etc...)
	 * @param	CommonObject		$object         The object to process (an invoice if you are in invoice module, a propale in propale's module, etc...)
	 * @param	?string				$action			Current action (if set). Generally create or edit or null
	 * @param	HookManager			$hookmanager	Hook manager propagated to allow calling another hook
	 * @return	int									Return integer < 0 on error, 0 on success, 1 to replace standard code
	 */
	public function doActions($parameters, &$object, &$action, $hookmanager)
	{
		global $db, $langs, $user;

		if (empty($action)) {
			return 0;
		}

		//dol_syslog(__METHOD__ . " Hook doActions called for object " . get_class($object) . " action=" . $action);

		$pdpConnectFr = new PdpConnectFr($db);
		$checkConfig = $pdpConnectFr->checkModulePrerequisites();
		if ($checkConfig < 0) {
			dol_syslog(__METHOD__ . "PDPCONNECTFR Module is not correctly configured.");
			return 0;
		}
		$langs->load("pdpconnectfr@pdpconnectfr");
		$contexts = explode(':', $parameters['context']);

		$outputlangs = $langs;

		$error = 0;

		$db->begin();

		if (isset($object->element) && in_array($object->element, ['facture'])) {
			$permissiontoedit = $user->hasRight('facture', 'write');

			// Get current status of e-invoice
			$currentStatusDetails = $pdpConnectFr->fetchLastknownInvoiceStatus(0, $object->ref);
			// Action to set the E-invoice status manually
			if ($action == 'seteinvoicestatus' && $permissiontoedit) {
				$result = $pdpConnectFr->setEInvoiceStatus($object, GETPOSTINT('seteinvoicestatus'), '');
				if ($result < 0) {
					$error++;
					$this->errors = array_merge($this->errors, $pdpConnectFr->errors);
				}
			}

			// Action to set an invoice-level routing ID override
			if ($action == 'setoverriderouting' && $permissiontoedit) {
				$overrideRoutingId = GETPOST('override_routing_id', 'alphanohtml');
				$result = $pdpConnectFr->insertOrUpdateExtLink($object->id, $object->element, '', $currentStatusDetails['code'], $object->ref, $currentStatusDetails['info'], $overrideRoutingId);
				if ($result < 0) {
					$error++;
					$this->errors = array_merge($this->errors, $pdpConnectFr->errors);
				}
			}

			// Action to send invoice to PDP
			if ($action == 'send_to_pdp' && $permissiontoedit
				&& $currentStatusDetails['file'] == 1
				&& in_array($currentStatusDetails['code'], [
					$pdpConnectFr::STATUS_GENERATED,
					$pdpConnectFr::STATUS_ERROR,
					$pdpConnectFr::STATUS_UNKNOWN
				])
			) {
				// Validate thirdparty data before sending to PDP
				$object->fetch_thirdparty();
				$checkResult = $pdpConnectFr->checkRequiredinformations($object);
				if ($checkResult['res'] < 0) {
					$message = $langs->trans("InvoiceNotSentToPDPDueToThirdpartyIssues") . ': <br>' . $checkResult['message'];
					dol_syslog(__METHOD__ . " " . strip_tags($message));
					setEventMessages($message, array(), 'errors');
					$error++;
				} elseif ($checkResult['res'] == 0) {
					// Non-blocking warning: notify user but proceed with sending
					dol_syslog(__METHOD__ . " " . strip_tags($checkResult['message']));
					setEventMessages($checkResult['message'], array(), 'warnings');
				}

				if (!$error) {
					$PDPManager = new PDPProviderManager($db);
					$provider = $PDPManager->getProvider(getDolGlobalString('PDPCONNECTFR_PDP'));

					// Send invoice
					$result = $provider->sendInvoice($object);

					if ($result) {
						$messages = array();
						$messages[] = $langs->trans("InvoiceSuccessfullySentToPDP");
						$messages[] = $langs->trans("FlowId") . ": " . $result;
						setEventMessages('', $messages, 'mesgs');
						// TODO: Review and update the invoice workflow.
						// The "Modify" button may need to be disabled once the E-invoice has been sent and distributed by the PDP.
					} else {
						$error++;
						$this->error = $provider->error;
						$this->errors = array_merge($this->errors, $provider->errors);
					}
				}
			}

			// Action to generate the E-invoice
			if ($action == 'generate_einvoice' && $permissiontoedit) {
				$object->fetch_thirdparty();
				$invoiceObject = $object;

				// Call function to create Factur-X document
				require_once __DIR__.'/protocols/ProtocolManager.class.php';

				$usedProtocols = getDolGlobalString('PDPCONNECTFR_PROTOCOL');
				$ProtocolManager = new ProtocolManager($db);
				$protocol = $ProtocolManager->getProtocol($usedProtocols);

				// Check configuration
				$result = $pdpConnectFr->checkRequiredinformations($invoiceObject);
				if ($result['res'] < 0) {			// Blocking error, message contains at least one error and may also have warnings
					$message = $langs->trans("InvoiceNotgeneratedDueToConfigurationIssues") . ': <br>' . $result['message'];

					dol_syslog(__METHOD__ . " " . $message);
					setEventMessages($message, array(), 'errors');
					$error++;
				} elseif ($result['res'] == 0) {	// Non blocking error, warning
					$this->warnings[] = $result['message'];

					dol_syslog(__METHOD__ . " " . $result['message']);
				}

				// Generate E-invoice by calling the method of the Protocol
				if (!$error) {
					$result = $protocol->generateInvoice($invoiceObject, $outputlangs);
					if ($result && (!is_numeric($result) || $result > 0)) {
						dol_syslog(__METHOD__ . " Invoice generated successfully for invoice ID " . $invoiceObject->id);
						if (!empty($this->warnings)) {
							setEventMessages($langs->trans("InvoiceGeneratedWithWarnings"), $this->warnings, 'warnings');
						} else {
							setEventMessages($langs->trans("EInvoiceGenerated"), array(), 'mesgs');
						}
					} else {
						// If there is an error, we move warnings into error message
						// Cast to array to avoid TypeError on PHP 8 when property is null
						$this->errors = array_merge($this->errors, (array) $protocol->errors);
						$this->errors = array_merge($this->errors, (array) $this->warnings);
						$this->warnings = array();
						dol_syslog(__METHOD__ . " " . implode(',', (array) $protocol->errors));
						$error++;
					}
				}
			}
		}


		if (isset($object->element) && in_array($object->element, ['invoice_supplier'])) {
			$permissiontoedit = $user->hasRight('fournisseur', 'facture', 'creer');

			if ($action == 'confirm_sendStatusMessage' && $permissiontoedit) {
				$PDPManager = new PDPProviderManager($db);
				$provider = $PDPManager->getProvider(getDolGlobalString('PDPCONNECTFR_PDP'));
				$pdpstatuscode = GETPOSTINT('pdpstatuscode') ?: 0;
				$statusRaison = GETPOST('statusRaison', 'alpha');

				$result = $provider->sendStatusMessage($object, $pdpstatuscode, $statusRaison); // Send status message

				if ($result['res'] > 0) {
					setEventMessages($result['message'], array(), 'mesgs');
				} else {
					$error++;
					$this->errors = array_merge($this->errors, $provider->errors);
					setEventMessages($result['message'], $provider->errors, 'errors');
				}
			}
		}

		if (in_array('thirdpartycard', $contexts)) {
			$permissiontoedit = $user->hasRight('societe', 'creer');

			// $object->id may be empty at hook time if core hasn't fetched the object yet
			$socId = !empty($object->id) ? (int) $object->id : GETPOSTINT('id');

			// Save routing from create/edit thirdparty form
			if (($action == 'add' || $action == 'update') && !empty($socId) && $permissiontoedit) {
				// Thirdparty routing ID
				$routingId = GETPOST('routing_id', 'alphanohtml');
				if ($routingId !== '') {
					$existing = $pdpConnectFr->fetchDefaultRouting($socId, 'thirdparty');
					if (empty($existing)) {
						$result = $pdpConnectFr->addRouting($socId, $routingId);
					} else {
						$result = $pdpConnectFr->setDefaultRouting($socId, $routingId, '', '', '', 'thirdparty');
					}
					if ($result < 0) {
						$error++;
						setEventMessages($langs->trans('FailedToSaveRoutingID'), null, 'errors');
					}
				}
				// Default product for import
				$result = $pdpConnectFr->setDefaultRouting($socId, GETPOST('routing_product_id', 'aZ09'), '', '', '', 'product');
				if ($result < 0) {
					$error++;
					setEventMessages($langs->trans('FailedToSaveRoutingID'), null, 'errors');
				}
			}

			// Add a new routing entry
			if ($action == 'pdp_addrouting' && !empty($socId) && $permissiontoedit) {
				$newRoutingId = GETPOST('new_routing_id', 'alphanohtml');
				$newRoutingInfo = GETPOST('new_routing_info', 'alphanohtml');
				if (!empty($newRoutingId)) {
					$result = $pdpConnectFr->addRouting($socId, $newRoutingId, $newRoutingInfo);
					if ($result < 0) {
						$error++;
						setEventMessages($langs->trans('FailedToSaveRoutingID'), null, 'errors');
					}
				}
			}

			// Delete a routing entry
			if ($action == 'pdp_deleterouting' && !empty($socId) && $permissiontoedit) {
				$routingRowid = GETPOSTINT('routing_rowid');
				if ($routingRowid > 0) {
					$result = $pdpConnectFr->deleteRouting($routingRowid, $socId);
					if ($result < 0) {
						$error++;
						setEventMessages($langs->trans('FailedToDeleteRoutingID'), null, 'errors');
					}
				}
			}

			// Set a routing entry as default
			if ($action == 'pdp_setdefaultrouting' && !empty($socId) && $permissiontoedit) {
				$routingRowid = GETPOSTINT('routing_rowid');
				if ($routingRowid > 0) {
					$result = $pdpConnectFr->setRoutingAsDefault($routingRowid, $socId);
					if ($result < 0) {
						$error++;
						setEventMessages($langs->trans('FailedToSetDefaultRoutingID'), null, 'errors');
					}
				}
			}
		}

		if ($error) {
			$db->rollback();
			return -1;
		} else {
			$db->commit();
			return 0;
		}
	}

	/**
	 * formConfirm
	 *
	 * @param array			$parameters		Array of parameters
	 * @param CommonObject	$object			Object
	 * @param string		$action			Action code
	 * @param Hookmanager	$hookmanager	Hook manager
	 * @return number
	 */
	public function formConfirm($parameters, $object, &$action, $hookmanager)
	{
		global $db, $langs, $form;

		if (empty($object->element)) {
			return 0;
		}

		$pdpConnectFr = new PdpConnectFr($db);
		$checkConfig = $pdpConnectFr->checkModulePrerequisites();
		if ($checkConfig < 0) {
			dol_syslog(__METHOD__ . "PDPCONNECTFR Module is not correctly configured.");
			return 0;
		}
		$langs->load("pdpconnectfr@pdpconnectfr");

		if (in_array($object->element, ['invoice_supplier'])) {
			// Clone confirmation
			if ($action == 'sendStatusMessage') {
				$form = new Form($db);
				$pdpstatuscode = GETPOST('pdpstatuscode', 'alpha');

				$formquestion = array();
				if (in_array($pdpstatuscode, array_values($pdpConnectFr::STATUS_REQUIRING_REASONS))) {
					$formquestion = array(
						'array' => [
							'type' => 'select',
							'name' => 'statusRaison',
							'label' => $langs->trans("SelectStatusReason"),
							'value' => '',
							'values' => $pdpConnectFr->getRaisonsByStatus($pdpstatuscode, 1)
						]
					);
				}

				$formconfirm = $form->formconfirm(
					DOL_URL_ROOT . "/fourn/facture/card.php?id={$object->id}&action=confirm_sendStatusMessage&pdpstatuscode={$pdpstatuscode}",
					$langs->trans('SendStatusMessage'),
					$langs->trans('ConfirmSendStatusMessage', $object->ref, $pdpConnectFr->getStatusLabel($pdpstatuscode)),
					'confirm_sendStatusMessage',
					$formquestion,
					'yes',
					1,
					250
				);

				$this->resprints .= $formconfirm;
			}
		}
	}

	/**
	 * Hook called when displaying object card
	 *
	 * @param array<string,mixed> 	$parameters		Array of parameters
	 * @param CommonObject			$object			Object invoice
	 * @param string		 		$action			Code action
	 * @param Hookmanager			$hookmanager	Hookmanager
	 * @return int									Result
	 */
	public function formObjectOptions($parameters, &$object, &$action, $hookmanager)
	{
		global $db, $langs;

		// $object->fetch_thirdparty();
		// $thirdpartyCountryCode = $object->thirdparty->country_code;
		// if (!in_array($object->element, ['facture']) || $thirdpartyCountryCode !== 'FR') {
		//     return 0;
		// }

		$pdpConnectFr = new PdpConnectFr($db);
		$checkConfig = $pdpConnectFr->checkModulePrerequisites();
		if ($checkConfig < 0) {
			dol_syslog(__METHOD__ . "PDPCONNECTFR Module is not correctly configured.");
			return 0;
		}

		$langs->load("pdpconnectfr@pdpconnectfr");

		if (empty($parameters['tpl_context'])) {	// Do not show the new fields when we are in the public form to register a thirdparty.
			// Add block in invoice card
			if (in_array($object->element, ['facture'])) {
				$this->resprints .= $pdpConnectFr->EInvoiceCardBlock($object, $action);		// Output fields in card, including js for refreshing state
			}

			// Add block in supplier invoice card
			if (in_array($object->element, ['invoice_supplier'])) {
				$this->resprints .= $pdpConnectFr->supplierInvoiceCardBlock($object, $action);		// Output fields in card, including js for refreshing state
			}

			// Add block in product/service card
			if (in_array($object->element, ['product'])) {
				$this->resprints .= $pdpConnectFr->productServiceCardBlock($object, $action);		// Output fields in card, including js for refreshing state
			}

			// Add block in thirdparty card
			if (in_array($object->element, ['societe'])) {
				$this->resprints .= $pdpConnectFr->thirdpartyCardBlock($object, $action);		// Output fields in card
			}
		}

		return 0;
	}


	/**
	 * Complete the $arrayfields with custom fields to be able to use them in list views (like thirdparty or invoice list)
	 *
	 * @param array<string,mixed> 	$parameters		Array of parameters
	 * @param CommonObject			$object			Object invoice
	 * @param string		 		$action			Code action
	 * @param Hookmanager			$hookmanager	Hookmanager
	 * @return int									Result
	 */
	public function completeArrayFields($parameters, &$object, &$action, $hookmanager)
	{
		if (in_array('invoicelist', explode(':', $parameters['context']))) {
			// Add fields to invoice list
			$parameters['arrayfields']['einvoicegenerated'] = array(
				'label' => 'EInvoiceFile',
				'checked' => -1,
				'position' => 900,
				'enabled' => 1,
				'perms' => '1'
			);
			$parameters['arrayfields']['pdp_syncstatus'] = array(
				'label' => 'PDPSyncStatus',
				'checked' => 1,
				'position' => 901,
				'enabled' => '1',
				'perms' => '1'
			);
		}
	}


	/**
	 * Add SELECT fields
	 *
	 * @param array<string,mixed> 	$parameters		Array of parameters
	 * @param CommonObject			$object			Object invoice
	 * @param string		 		$action			Code action
	 * @param Hookmanager			$hookmanager	Hookmanager
	 * @return int									Result
	 */
	public function printFieldListSelect($parameters, &$object, &$action, $hookmanager)
	{
		// Invoice list
		if (in_array('invoicelist', explode(':', $parameters['context']))) {
			$this->resprints .= ', ext.syncstatus  AS pdp_syncstatus';
		}

		// Supplier invoice list, Product list, Soc list
		$contexts = explode(':', $parameters['context']);
		if (array_intersect($contexts, ['supplierinvoicelist', 'thirdpartylist', 'productservicelist', 'societelist'])) {
			$this->resprints .= ', ext.rowid AS pdplink_id, ext.provider AS pdp_provider';
		}

		if (array_intersect(
			$contexts,
			['thirdpartylist', 'societelist']
		)) {
			$this->resprints .= ', rt.routing_id AS routing_id';
		}

		return 0;
	}

	/**
	 * Add FROM / JOIN
	 *
	 * @param array<string,mixed> 	$parameters		Array of parameters
	 * @param CommonObject			$object			Object invoice
	 * @param string		 		$action			Code action
	 * @param Hookmanager			$hookmanager	Hookmanager
	 * @return int									Result
	 */
	public function printFieldListFrom($parameters, &$object, &$action, $hookmanager)
	{
		if (in_array('invoicelist', explode(':', $parameters['context']))) {
			$this->resprints .= " LEFT JOIN ".MAIN_DB_PREFIX."pdpconnectfr_extlinks as ext ON ext.element_id = f.rowid AND ext.element_type = 'facture'";
		}

		// Supplier invoice list, Product list, Soc list
		$contexts = explode(':', $parameters['context']);

		if (array_intersect($contexts, ['supplierinvoicelist', 'thirdpartylist', 'productservicelist', 'societelist'])) {
			if (in_array('thirdpartylist', $contexts, true)) {
				$this->resprints .= ' LEFT JOIN '.MAIN_DB_PREFIX."pdpconnectfr_extlinks as ext ON ext.element_id = s.rowid AND ext.element_type = 'societe'";
				$this->resprints .= ' LEFT JOIN '.MAIN_DB_PREFIX."pdpconnectfr_routing rt ON rt.fk_soc = s.rowid";
			}

			if (in_array('supplierinvoicelist', $contexts, true)) {
				$this->resprints .= ' LEFT JOIN '.MAIN_DB_PREFIX."pdpconnectfr_extlinks as ext ON ext.element_id = f.rowid AND ext.element_type = 'invoice_supplier'";
			}

			if (in_array('productservicelist', $contexts, true)) {
				$this->resprints .= ' LEFT JOIN '.MAIN_DB_PREFIX."pdpconnectfr_extlinks as ext ON ext.element_id = p.rowid AND ext.element_type = 'product'";
			}
		}

		return 0;
	}

	/**
	 * Add WHERE (search filters)
	 *
	 * @param array<string,mixed> 	$parameters		Array of parameters
	 * @param CommonObject			$object			Object invoice
	 * @param string		 		$action			Code action
	 * @param Hookmanager			$hookmanager	Hookmanager
	 * @return int									Result
	 */
	public function printFieldListWhere($parameters, &$object, &$action, $hookmanager)
	{
		if (in_array('invoicelist', explode(':', $parameters['context']))) {
			if (GETPOST('search_pdp_syncstatus', 'alpha') !== '' && GETPOST('search_pdp_syncstatus', 'alpha') != -2) {
				$this->resprints .= ' AND ext.syncstatus = '.((int) GETPOST('search_pdp_syncstatus'));
			}
		}

		// Supplier invoice list, Product list, Soc list
		$contexts = explode(':', $parameters['context']);
		if (array_intersect(
			$contexts,
			['supplierinvoicelist', 'thirdpartylist', 'productservicelist', 'societelist']
		)) {
			if (GETPOST('search_pdplinked', 'alpha') !== '' && GETPOST('search_pdplinked', 'alpha') == getDolGlobalString('PDPCONNECTFR_PDP')) {
				$this->resprints .= ' AND ext.provider = "'.getDolGlobalString('PDPCONNECTFR_PDP').'"';
			}

			if (GETPOST('search_routing_id', 'alpha') !== '' && GETPOST('search_routing_id', 'alpha') != "") {
				$this->resprints .= ' AND ext.routing_id = "'.GETPOST('search_routing_id', 'alpha').'"';
			}
		}

		return 0;
	}


	/**
	 * Filter options
	 *
	 * @param array<string,mixed> 	$parameters		Array of parameters
	 * @param CommonObject			$object			Object invoice
	 * @param string		 		$action			Code action
	 * @param Hookmanager			$hookmanager	Hookmanager
	 * @return int									Result
	 */
	public function printFieldListOption($parameters, &$object, &$action, $hookmanager)
	{
		global $form, $db;

		if (in_array('invoicelist', explode(':', $parameters['context']))) {
			$pdpConnectFr = new PdpConnectFr($db);
			$checkConfig = $pdpConnectFr->checkModulePrerequisites();
			if ($checkConfig < 0) {
				dol_syslog(__METHOD__ . "PDPCONNECTFR Module is not correctly configured.");
				return 0;
			}

			// Einvoice generated or not
			if (!empty($parameters['arrayfields']['einvoicegenerated']['checked'])) {
				print '<td class="liste_titre einvoicegenerated">';
				print '</td>';
			}

			// Sync status
			if (empty($parameters['arrayfields']['pdp_syncstatus']) || !empty($parameters['arrayfields']['pdp_syncstatus']['checked'])) {
				print '<td class="liste_titre pdp_syncstatus">';
				$listofoptions = $pdpConnectFr->getEinvoiceStatusOptions(0, 0, 0, 0, 1, 1);

				// Remove option related to E-invoice generation status
				//unset($listofoptions[$pdpConnectFr::STATUS_NOT_GENERATED]);
				//unset($listofoptions[$pdpConnectFr::STATUS_GENERATED]);
				unset($listofoptions[$pdpConnectFr::STATUS_UNKNOWN]);

				print $form->selectarray(
					'search_pdp_syncstatus',
					$listofoptions,
					GETPOST('search_pdp_syncstatus', 'alpha'),
					-2,
					0,
					0,
					'',
					0,
					0,
					0,
					'',
					'width100 '
				);
				print '</td>';
			}
		}

		// Supplier invoice list, Product list, Soc list
		$contexts = explode(':', $parameters['context']);
		if (array_intersect(
			$contexts,
			['supplierinvoicelist', 'thirdpartylist', 'productservicelist', 'societelist']
		)) {
			$listofoptions = array(
				getDolGlobalString('PDPCONNECTFR_PDP') => getDolGlobalString('PDPCONNECTFR_PDP'),
			);
			print '<td class="liste_titre">';
			print $form->selectarray(
				'search_pdplinked',
				$listofoptions,
				GETPOST('search_pdplinked', 'alpha'),
				-2,
				0,
				0,
				'',
				0,
				0,
				0,
				'',
				'width100 '
			);
			print '</td>';
		}

		if (in_array('thirdpartylist', $contexts, true)) {
			print '<td class="liste_titre">';
			print '<input type="text" name="search_routing_id" value="' . GETPOST('search_routing_id', 'alpha') . '" class="minwidth50 maxwidth100">';
			print '</td>';
		}

		return 0;
	}


	/**
	 * Column titles
	 *
	 * @param array<string,mixed> 	$parameters		Array of parameters
	 * @param CommonObject			$object			Object invoice
	 * @param string		 		$action			Code action
	 * @param Hookmanager			$hookmanager	Hookmanager
	 * @return int									Result
	 */
	public function printFieldListTitle($parameters, &$object, &$action, $hookmanager)
	{
		global $db, $langs;

		if (in_array('invoicelist', explode(':', $parameters['context']))) {
			$pdpConnectFr = new PdpConnectFr($db);
			$checkConfig = $pdpConnectFr->checkModulePrerequisites();
			if ($checkConfig < 0) {
				dol_syslog(__METHOD__ . "PDPCONNECTFR Module is not correctly configured.");
				return 0;
			}

			// Einvoice generated or not
			if (!empty($parameters['arrayfields']['einvoicegenerated']['checked'])) {
				print print_liste_field_titre($langs->transnoentitiesnoconv('EInvoiceFile'), '', '', '', $parameters['param'] ?? '', '', $parameters['sortfield'] ?? '', $parameters['sotorder'] ?? '', 'center ');
			}

			// syncstatus
			if (empty($parameters['arrayfields']['pdp_syncstatus']) || !empty($parameters['arrayfields']['pdp_syncstatus']['checked'])) {
				print print_liste_field_titre($langs->transnoentitiesnoconv('PDPSyncStatus'), '', '', '', $parameters['param'] ?? '', '', $parameters['sortfield'] ?? '', $parameters['sotorder'] ?? '', 'center ');
			}
		}

		// Supplier invoice list, Product list, Soc list
		$contexts = explode(':', $parameters['context']);
		if (array_intersect(
			$contexts,
			['supplierinvoicelist', 'thirdpartylist', 'productservicelist', 'societelist']
		)) {
			print print_liste_field_titre($langs->transnoentitiesnoconv('pdpconnectfrSourceTitle'));
		}

		if (in_array('thirdpartylist', $contexts, true)) {
			print print_liste_field_titre($langs->transnoentitiesnoconv('pdpconnectfrThirdPartyRoutingTitle'));
		}

		return 0;
	}


	/**
	 * Row values
	 *
	 * @param array<string,mixed> 	$parameters		Array of parameters
	 * @param CommonObject			$object			Object invoice
	 * @param string		 		$action			Code action
	 * @param Hookmanager			$hookmanager	Hookmanager
	 * @return int									Result
	 */
	public function printFieldListValue($parameters, &$object, &$action, $hookmanager)
	{
		global $db, $langs;

		if (in_array('invoicelist', explode(':', $parameters['context']))) {
			$obj = $parameters['obj'];

			$pdpConnectFr = new PdpConnectFr($db);
			$checkConfig = $pdpConnectFr->checkModulePrerequisites();
			if ($checkConfig < 0) {
				dol_syslog(__METHOD__ . "PDPCONNECTFR Module is not correctly configured.");
				return 0;
			}

			// Einvoice generated or not
			if (!empty($parameters['arrayfields']['einvoicegenerated']['checked'])) {
				$tmparray = $pdpConnectFr->fetchLastknownInvoiceStatus(0, $obj->ref);
				$einvoiceGenerated = $tmparray['file'];
				print '<td class="center tdoverflowmax125">';
				if ($einvoiceGenerated) {
					print '<i class="fas fa-check-circle" style="color:green;" title="'.$langs->trans('EInvoiceGenerated').'"></i>';
				}
				print '</td>';
				if (isset($parameters['i']) && empty($parameters['i'])) {
					$parameters['totalarray']['nbfield']++;
				}
			}

			// Sync status
			if (empty($parameters['arrayfields']['pdp_syncstatus']) || !empty($parameters['arrayfields']['pdp_syncstatus']['checked'])) {
				$currentStatusDetails = $obj->pdp_syncstatus ? $pdpConnectFr->getStatusLabel($obj->pdp_syncstatus) : '-';
				print '<td class="center tdoverflowmax125" title="'.dolPrintHTMLForAttribute($currentStatusDetails).'">';
				print $currentStatusDetails;
				print '</td>';
				if (isset($parameters['i']) && empty($parameters['i'])) {
					$parameters['totalarray']['nbfield']++;
				}
			}
		}

		// Supplier invoice list, Product list, Soc list
		$contexts = explode(':', $parameters['context']);
		if (array_intersect(
			$contexts,
			['supplierinvoicelist', 'thirdpartylist', 'productservicelist', 'societelist']
		)) {
			$obj = $parameters['obj'];

			print '<td class="tdoverflowmax125">';
			if ($obj->pdplink_id) {
				print dolPrintHTML($obj->pdp_provider);
			}
			print '</td>';
			if (isset($parameters['i']) && empty($parameters['i'])) {
				$parameters['totalarray']['nbfield']++;
			}
		}

		if (in_array('thirdpartylist', $contexts, true)) {
			$obj = $parameters['obj'];

			print '<td class="tdoverflowmax125">';
			if ($obj->pdplink_id) {
				print dolPrintHTML($obj->routing_id);
			}
			print '</td>';
			if (isset($parameters['i']) && empty($parameters['i'])) {
				$parameters['totalarray']['nbfield']++;
			}
		}

		return 0;
	}


	/**
	 * isEditable
	 *
	 * @param array<string,mixed> 	$parameters		Array of parameters
	 * @param CommonObject			$object			Object invoice
	 * @param string		 		$action			Code action
	 * @param Hookmanager			$hookmanager	Hookmanager
	 * @return int									Result
	 */
	public function isEditable($parameters, &$object, &$action, $hookmanager)
	{
		global $langs, $db;

		// Only target customer invoices
		if (!in_array($object->element, ['facture'])) {
			return 0;
		}

		$pdpConnectFr = new PdpConnectFr($db);
		$currentStatusDetails = $pdpConnectFr->fetchLastknownInvoiceStatus(0, $object->ref);

		// Block modification if invoice is already transmitted to PDP
		if ($currentStatusDetails['transmitted'] == 1) {
			$langs->load("pdpconnectfr@pdpconnectfr");

			$this->results = [
				'result' => -100, 	// Custom error code. Must be higher that core reserve code between -1...-50
				'error'  => $langs->trans('InvoiceLinkedToPdpCannotBeModified')
			];

			return 1;
		}

		return 0;
	}
}
