<?php
/* Copyright (C) 2025		Mohamed Daoud			<mdaoud@dolicloud.com>
 * Copyright (C) 2025		Laurent Destailleur		<eldy@users.sourceforge.net>
 * Copyright (C) 2026		Charlene Benke			<charlene@patas-monkey.com>
 * Copyright (C) 2026       Frédéric France         <frederic.france@free.fr>
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
 * \file    einvoicing/class/actions_einvoicing.class.php
 * \ingroup einvoicing
 * \brief   Hook of module
 */

if ((float) DOL_VERSION < 19) {
	dol_include_once('/einvoicing/compat/commonhookactions.class.php');
} else {
	require_once DOL_DOCUMENT_ROOT . '/core/class/commonhookactions.class.php';
}
require_once __DIR__ . "/einvoicing.class.php";
dol_include_once('/einvoicing/class/providers/PDPProviderManager.class.php');


/**
 * Class for hooks of module
 */
class ActionsEInvoicing extends CommonHookActions
{
	/**
	 * systemMessage
	 *
	 * @param array<string,mixed> 	$parameters		Array of parameters
	 * @param CommonObject			$object			Object invoice
	 * @param string		 		$action			Code action
	 * @param Hookmanager			$hookmanager	Hookmanager
	 * @return int									Result
	 */
	public function messageOfTheDay($parameters, $object, &$action, $hookmanager)
	{
		return 0;
	}

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

		$einvoicing = new EInvoicing($db);
		$checkConfig = $einvoicing->checkModulePrerequisites();
		if ($checkConfig < 0) {
			dol_syslog(__METHOD__ . "EINVOICING Module is not correctly configured.");
			return 0;
		}

		$invoiceObject = $parameters['object'];

		// Check if it's an invoice
		if ($invoiceObject instanceof Facture) {
			$invoiceObject->fetch_thirdparty();
			$thirdpartyCountryCode = $invoiceObject->thirdparty->country_code;

			// Get current status of e-invoice
			$currentStatusDetails = $einvoicing->fetchLastknownInvoiceStatus($invoiceObject->id, $invoiceObject->ref);

			if ($thirdpartyCountryCode === 'FR' && (!isset($currentStatusDetails['code']) || $currentStatusDetails['code'] != $einvoicing::STATUS_IGNORE)) {
				/** @var Facture $invoiceObject */
				// Never generate/transmit an e-invoice for a DRAFT: regenerating a draft PDF (e.g. after
				// adding a line) must NOT push anything to the PA. At validation the invoice is already
				// VALIDATED when Dolibarr regenerates the final PDF, so the legitimate flow is preserved.
				if ($invoiceObject->status != $invoiceObject::STATUS_DRAFT
					&& !getDolGlobalString('EINVOICING_DISABLE_SYNC_DOLI_TO_AP')
					&& getDolGlobalString('EINVOICING_EINVOICE_IN_REAL_TIME')) {
					// Call function to create Factur-X document
					require_once __DIR__ . '/protocols/ProtocolManager.class.php';

					$usedProtocols = getDolGlobalString('EINVOICING_PROTOCOL');
					$ProtocolManager = new ProtocolManager($db);
					$protocol = $ProtocolManager->getProtocol($usedProtocols);

					// Check configuration
					$result = $einvoicing->checkRequiredinformations($invoiceObject);
					if ($result['res'] < 0) {			// Error case
						$message = $langs->trans("InvoiceNotgeneratedDueToConfigurationIssues") . ': <br>' . $result['message'];

						dol_syslog(__METHOD__ . " " . $message);

						if (getDolGlobalString('EINVOICING_EINVOICE_CANCEL_IF_EINVOICE_FAILS')) {
							// Add more conditions like thirdparty nature to avoid blocking invoice creation for non FR companies
							// or for thirdparties that are not subject to E-invoicing obligation
							$messagecss = 'errors';
							setEventMessages($message, array(), $messagecss);
							return -1;
						} else {
							$messagecss = 'warnings';
							setEventMessages($message, array(), $messagecss);
							$this->warnings[] = $message;
							return 0;
						}
					} elseif ($result['res'] == 0) {	// Warning case
						$message = $langs->trans("InvoiceGeneratedWithWarnings") . ': <br>' . $result['message'];
						$this->warnings[] = $message;

						dol_syslog(__METHOD__ . " " . $message);
						$messagecss = 'warnings';
						//setEventMessages($message, array(), $messagecss);
					}

					$result = $protocol->generateInvoice($invoiceObject, $outputlangs);		// Generate E-invoice

					if ($result >= 0) {
						setEventMessages($message, array(), $messagecss);
					}

					if ($result && (!is_numeric($result) || $result > 0)) {
						// No error
						setEventMessages($langs->trans("EInvoiceGenerated"), array(), 'mesgs');

						// Forward non-blocking size warning from the protocol if any
						if (!empty($protocol->warnings)) {
							setEventMessages($langs->trans("InvoiceGeneratedWithWarnings"), $protocol->warnings, 'warnings');
						}

						// Optionally transmit to the Access Point right after generation (opt-in + idempotent).
						// Without this, validation only generates the Factur-X; the invoice is never sent to the
						// PA (transmission was a manual "send_to_pdp" click only). The 'transmitted' guard prevents
						// re-sending (and creating duplicate flows) when the PDF is regenerated later.
						if (getDolGlobalString('EINVOICING_AUTO_SEND_ON_GENERATION') && empty($currentStatusDetails['transmitted'])) {
							require_once __DIR__ . '/providers/PDPProviderManager.class.php';
							$PDPManager = new PDPProviderManager($db);
							$provider = $PDPManager->getProvider(getDolGlobalString('EINVOICING_PDP'));
							if (is_object($provider)) {
								$sendres = $provider->sendInvoice($invoiceObject);
								if ($sendres) {
									setEventMessages($langs->trans("InvoiceSuccessfullySentToPDP") . ' - ' . $langs->trans("FlowId") . ': ' . $sendres, null, 'mesgs');
								} else {
									// Don't block validation if auto-send fails: the e-invoice is generated and can still be sent manually.
									$senderrors = $provider->errors ?: array($provider->error);
									$this->warnings = array_merge($this->warnings, (array) $senderrors);
									dol_syslog(__METHOD__ . " auto-send to PA failed: " . implode('; ', (array) $senderrors), LOG_WARNING, 0, "_einvoicing");
								}
							}
						}
					} else {
						if (getDolGlobalString('EINVOICING_EINVOICE_CANCEL_IF_EINVOICE_FAILS')) {
							// If einvoice fails here, it must be always an error
							$this->errors = array_merge($this->errors, $protocol->errors);
							return -1;
						} else {
							if ($result < 0) {
								if ((float) DOL_VERSION < 24.0) {
									$this->errors = array_merge($this->errors, $protocol->errors);
									$this->warnings = array();	// We remove warning array to keep only the error array, because only errors array is managed with version < 24.0 of Dolibarr.
								} else {
									$this->warnings = array_merge($this->errors, $protocol->errors);	// We want to return the error as a warning.
								}
								return -1;
							} else {
								return 0;
							}
						}
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

		$langs->load("einvoicing@einvoicing");
		$einvoicing = new EInvoicing($db);
		$checkConfig = $einvoicing->checkModulePrerequisites();
		if ($checkConfig < 0) {
			dol_syslog(__METHOD__ . "EINVOICING Module is not correctly configured.");
			return 0;
		}

		// Add buttons in invoice card
		if (in_array($object->element, ['facture']) && !getDolGlobalString('EINVOICING_DISABLE_SYNC_DOLI_TO_AP')) {
			// Get current status of e-invoice
			$currentStatusDetails = $einvoicing->fetchLastknownInvoiceStatus($object->id, $object->ref);

			$url_button = array();

			if ($object->status == Facture::STATUS_VALIDATED || $object->status == Facture::STATUS_CLOSED) {
				// if E-invoice is not generated, show button to generate e-invoice
				if (
					$currentStatusDetails['code'] == $einvoicing::STATUS_NOT_GENERATED
					|| !array_key_exists($currentStatusDetails['code'], $einvoicing::STATUS_LABEL_KEYS)
				) {
					$url_button[] = array(
						'lang' => 'einvoicing',
						'enabled' => 1,
						'perm' => (bool) $user->hasRight("facture", "creer"),
						'label' => $langs->trans('GenerateEinvoice'),
						//'help' => $langs->trans('GenerateEinvoiceHelp'),
						'url' => '/compta/facture/card.php?id=' . $object->id . '&action=generate_einvoice&token=' . newToken()
					);
				}

				// If the e-invoice is generated but not sent, or if it was sent and a validation error was received,
				// display the button to regenerate the e-invoice
				if (in_array($currentStatusDetails['code'], [
					$einvoicing::STATUS_GENERATED,
					$einvoicing::STATUS_ERROR,
					$einvoicing::STATUS_UNKNOWN
				])) {
					$perm = (bool) $user->hasRight("facture", "creer");
				} else {
					$perm = false;
				}
				$url_button[] = array(
					'lang' => 'einvoicing',
					'enabled' => 1,
					'perm' => $perm,
					'label' => $langs->trans('RegenerateEinvoice'),
					//'help' => $langs->trans('RegenerateEinvoiceHelp'),
					'url' => '/compta/facture/card.php?id=' . $object->id . '&action=generate_einvoice&token=' . newToken()
				);

				// If the e-invoice is generated but not sent, or if it was sent and a validation error was received,
				// display the button to regenerate the e-invoice
				if (in_array($currentStatusDetails['code'], [
					$einvoicing::STATUS_GENERATED,
					$einvoicing::STATUS_ERROR,
					$einvoicing::STATUS_UNKNOWN,
					$einvoicing::STATUS_AWAITING_VALIDATION,		// We may retry to resend. We should get an error if we do, but it is interesting to test the retry.
					$einvoicing::STATUS_AWAITING_ACK				// We may retry to resend. We should get an error if we do, but it is interesting to test the retry.
				])) {
					$url_button[] = array(
						'lang' => 'einvoicing',
						'enabled' => 1,
						'perm' => (bool) $user->hasRight("einvoicing", "write") && ($currentStatusDetails['file'] == 1),
						'label' => $langs->trans('sendToPDP'),
						//'help' => $langs->trans('SendToPDPHelp'),
						'url' => '/compta/facture/card.php?id=' . $object->id . '&action=send_to_pdp&token=' . newToken()
					);
				}
			}

			if (empty($parameters['context']) || !preg_match('/takepospay/', $parameters['context'])) {
				print '<!-- Current AP: ' . getDolGlobalString('EINVOICING_PDP') . ' -->';
				if (!empty($url_button)) {
					// Pass the visible label as the 1st arg ($label), not the 2nd ($text). On Dolibarr 18/19
					// the dropdown <a> renders only $label; v22+ falls back to $text when $label is empty,
					// but to keep behavior consistent across versions we always use $label.
					if ((float) DOL_VERSION < 22) {
						print dolGetButtonAction($langs->trans('einvoice'), '', 'default', $url_button, '', true);
					} else {
						print dolGetButtonAction('', $langs->trans('einvoice'), 'default', $url_button, '', true);
					}
				}
			}
		}


		// Add buttons in supplier invoice card
		if (in_array($object->element, ['invoice_supplier']) && !getDolGlobalString('EINVOICING_DISABLE_SYNC_AP_TO_DOLI')) {
			// Check if this invoice is present into einvoicing_extlinks table to know if it is an imported invoice from PDP or not
			$sql = "SELECT rowid, provider FROM " . MAIN_DB_PREFIX . "einvoicing_extlinks";
			$sql .= " WHERE element_type = '" . $db->escape($object->element) . "'";
			$sql .= " AND element_id = " . (int) $object->id;
			$sql .= " LIMIT 1";

			$resql = $db->query($sql);
			if ($resql && $db->num_rows($resql) > 0) {
				// Check if a final status (approved or rejected) has already been sent and validated
				// → in this case, the lifecycle is complete, so we hide the button
				$sqlFinal = "SELECT rowid FROM " . MAIN_DB_PREFIX . "einvoicing_lifecycle_msg";
				$sqlFinal .= " WHERE element_id = " . (int) $object->id;
				$sqlFinal .= " AND element_type = '" . $db->escape($object->element) . "'";
				$sqlFinal .= " AND direction = 'out'";
				$sqlFinal .= " AND lc_status IN (" . (int) EInvoicing::STATUS_APPROVED . ", " . (int) EInvoicing::STATUS_REFUSED . ")";
				$sqlFinal .= " AND lc_validation_status = 'Ok'";
				$sqlFinal .= " LIMIT 1";
				$resqlFinal = $db->query($sqlFinal);
				$hasFinalLifecycle = ($resqlFinal && $db->num_rows($resqlFinal) > 0);

				if (!$hasFinalLifecycle) {
					$availableStatuses = $einvoicing->getEinvoiceStatusOptions(1, 1, 1);
					$url_button = array();
					foreach ($availableStatuses as $code => $label) {
						$url_button[] = array(
							'lang' => 'einvoicing',
							'enabled' => 1,
							'perm' => (bool) $user->hasRight("facture", "creer"),
							'label' => (string) $label,
							'url' => dol_buildpath('/fourn/facture/card.php?id=' . $object->id . '&action=sendStatusMessage&pdpstatuscode=' . $code . '&token=' . newToken(), 1)
						);
					}

					if (!empty($url_button)) {
						print dolGetButtonAction($langs->trans('einvoice'), '', 'default', $url_button, '', true);
					}
				}
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

		$einvoicing = new EInvoicing($db);
		$checkConfig = $einvoicing->checkModulePrerequisites();
		if ($checkConfig < 0) {
			dol_syslog(__METHOD__ . "EINVOICING Module is not correctly configured.");
			return 0;
		}
		$langs->load("einvoicing@einvoicing");
		$contexts = explode(':', $parameters['context']);

		$outputlangs = $langs;

		$error = 0;

		$db->begin();

		if (isset($object->element) && in_array($object->element, ['facture']) && !getDolGlobalString('EINVOICING_DISABLE_SYNC_DOLI_TO_AP')) {
			$permissiontoedit = $user->hasRight('facture', 'write');

			if ($action == 'add') {
				// On create, we can do nothing here. We will update the einvoice status into the CREATE trigger.
			} else {
				// Get current status of e-invoice
				$currentStatusDetails = $einvoicing->fetchLastknownInvoiceStatus($object->id, $object->ref);
				// Action to set the E-invoice status manually
				if ($action == 'seteinvoicestatus' && $permissiontoedit) {
					$result = $einvoicing->setEInvoiceStatus($object, GETPOSTINT('seteinvoicestatus'), '');
					if ($result < 0) {
						$error++;
						$this->errors = array_merge($this->errors, $einvoicing->errors);
					}
				}
			}

			// Action to set an invoice-level routing ID override
			if ($action == 'setoverriderouting' && $permissiontoedit) {
				$overrideRoutingId = GETPOST('override_routing_id', 'alphanohtml');
				$result = $einvoicing->insertOrUpdateExtLink($object->id, $object->element, '', $currentStatusDetails['code'], $object->ref, $currentStatusDetails['info'], $overrideRoutingId);
				if ($result < 0) {
					$error++;
					$this->errors = array_merge($this->errors, $einvoicing->errors);
				}
			}

			// Action to send invoice to Access Point
			if (
				$action == 'send_to_pdp' && $permissiontoedit
				&& $currentStatusDetails['file'] == 1
				&& in_array($currentStatusDetails['code'], [
					$einvoicing::STATUS_GENERATED,
					$einvoicing::STATUS_ERROR,
					$einvoicing::STATUS_UNKNOWN
				])
			) {
				// Validate thirdparty data before sending to Access Point
				$object->fetch_thirdparty();
				$checkResult = $einvoicing->checkRequiredinformations($object);
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
					$provider = $PDPManager->getProvider(getDolGlobalString('EINVOICING_PDP'));

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

			// Action to generate the E-invoice alone
			if ($action == 'generate_einvoice' && $permissiontoedit) {
				$object->fetch_thirdparty();
				$invoiceObject = $object;

				// Call function to create E-invoice document
				require_once __DIR__ . '/protocols/ProtocolManager.class.php';

				$usedProtocols = getDolGlobalString('EINVOICING_PROTOCOL');
				$ProtocolManager = new ProtocolManager($db);
				$protocol = $ProtocolManager->getProtocol($usedProtocols);

				// Check configuration
				$result = $einvoicing->checkRequiredinformations($invoiceObject);
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
						// No error
						dol_syslog(__METHOD__ . " Invoice generated successfully for invoice ID " . $invoiceObject->id);
						// Merge non-blocking size warnings from the protocol
						if (!empty($protocol->warnings)) {
							$this->warnings = array_merge($this->warnings, (array) $protocol->warnings);
						}
						if (!empty($this->warnings)) {
							setEventMessages($langs->trans("InvoiceGeneratedWithWarnings"), $this->warnings, 'warnings');
						} else {
							setEventMessages($langs->trans("EInvoiceGenerated"), array(), 'mesgs');
						}
					} else {
						// If there is an error, we move warnings into error message
						// Cast to array to avoid TypeError on PHP 8 when property is null
						$this->errors = array_merge($this->errors, (array) $protocol->errors);
						if (!empty($this->warnings)) {
							$this->errors = array_merge($this->errors, (array) $this->warnings);
						}
						$this->warnings = array();
						dol_syslog(__METHOD__ . " " . implode(',', (array) $protocol->errors));
						$error++;
					}
				}
			}
		}


		if (isset($object->element) && in_array($object->element, ['invoice_supplier']) && !getDolGlobalString('EINVOICING_DISABLE_SYNC_AP_TO_DOLI')) {
			$permissiontoedit = $user->hasRight('fournisseur', 'facture', 'creer');

			if ($action == 'confirm_sendStatusMessage' && $permissiontoedit) {
				$PDPManager = new PDPProviderManager($db);
				$provider = $PDPManager->getProvider(getDolGlobalString('EINVOICING_PDP'));
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

		if (array_intersect(['thirdpartycard', 'thirdpartycomm'], $contexts) && (!getDolGlobalString('EINVOICING_DISABLE_SYNC_DOLI_TO_AP') || !getDolGlobalString('EINVOICING_DISABLE_SYNC_AP_TO_DOLI'))) {
			$permissiontoedit = $user->hasRight('societe', 'creer');

			// $object->id may be empty at hook time if core hasn't fetched the object yet
			$socId = !empty($object->id) ? (int) $object->id : GETPOSTINT('id');

			// Save einvoice ID from creation formonly
			// (action=update excludes intentionally : in edit mode, we are using the routing edit array)
			if ($action == 'add' && !empty($socId) && $permissiontoedit) {
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
						setEventMessages($langs->trans('FailedToSaveRoutingID').' '.$einvoicing->error, null, 'errors');
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
						setEventMessages($langs->trans('FailedToSaveRoutingID').' '.$einvoicing->error, null, 'errors');
					}
				}
			}

			// Add a new routing entry
			if ($action == 'pdp_addrouting' && !empty($socId) && $permissiontoedit) {
				$newRoutingId = GETPOST('new_routing_id', 'alphanohtml');
				$newRoutingInfo = GETPOST('new_routing_info', 'alphanohtml');
				if (!empty($newRoutingId)) {
					$result = $einvoicing->addRouting($socId, $newRoutingId, $newRoutingInfo, 'thirdparty');
					if ($result < 0) {
						$error++;
						setEventMessages($langs->trans('FailedToSaveRoutingID').' '.$einvoicing->error, null, 'errors');
					}
				}
			}

			// Delete a routing entry
			if ($action == 'pdp_deleterouting' && !empty($socId) && $permissiontoedit) {
				$routingRowid = GETPOSTINT('routing_rowid');
				if ($routingRowid > 0) {
					$result = $einvoicing->deleteRouting($routingRowid, $socId);
					if ($result < 0) {
						$error++;
						setEventMessages($langs->trans('FailedToDeleteRoutingID').' '.$einvoicing->error, null, 'errors');
					}
				}
			}

			// Set a routing entry as default
			if ($action == 'pdp_setdefaultrouting' && !empty($socId) && $permissiontoedit) {
				$routingRowid = GETPOSTINT('routing_rowid');
				if ($routingRowid > 0) {
					$result = $einvoicing->setRoutingAsDefault($routingRowid, $socId);
					if ($result < 0) {
						$error++;
						setEventMessages($langs->trans('FailedToSetDefaultRoutingID').' '.$einvoicing->error, null, 'errors');
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
	 * @return int
	 */
	public function formConfirm($parameters, $object, &$action, $hookmanager)
	{
		global $db, $langs, $form;

		if (empty($object->element)) {
			return 0;
		}

		$einvoicing = new EInvoicing($db);
		$checkConfig = $einvoicing->checkModulePrerequisites();
		if ($checkConfig < 0) {
			dol_syslog(__METHOD__ . "EINVOICING Module is not correctly configured.");
			return 0;
		}
		$langs->load("einvoicing@einvoicing");

		if (in_array($object->element, ['invoice_supplier']) && !getDolGlobalString('EINVOICING_DISABLE_SYNC_AP_TO_DOLI')) {
			// Clone confirmation
			if ($action == 'sendStatusMessage') {
				$form = new Form($db);
				$pdpstatuscode = GETPOST('pdpstatuscode', 'alpha');

				$formquestion = array();
				if (in_array($pdpstatuscode, array_values($einvoicing::STATUS_REQUIRING_REASONS))) {
					$formquestion = array(
						'array' => [
							'type' => 'select',
							'name' => 'statusRaison',
							'label' => $langs->trans("SelectStatusReason"),
							'value' => '',
							'values' => $einvoicing->getReasonsByStatus($pdpstatuscode, 1)
						]
					);
				}

				$formconfirm = $form->formconfirm(
					DOL_URL_ROOT . "/fourn/facture/card.php?id={$object->id}&action=confirm_sendStatusMessage&pdpstatuscode={$pdpstatuscode}",
					$langs->trans('SendStatusMessage'),
					$langs->trans('ConfirmSendStatusMessage', $object->ref, $einvoicing->getStatusLabel($pdpstatuscode)),
					'confirm_sendStatusMessage',
					$formquestion,
					'yes',
					1,
					250
				);

				$this->resprints .= $formconfirm;
			}
		}

		return 0;
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

		$einvoicing = new EInvoicing($db);
		$checkConfig = $einvoicing->checkModulePrerequisites();
		if ($checkConfig < 0) {
			dol_syslog(__METHOD__ . "EINVOICING Module is not correctly configured.");
			return 0;
		}

		$langs->load("einvoicing@einvoicing");

		if (empty($parameters['tpl_context'])) {	// Do not show the new fields when we are in the public form to register a thirdparty.
			// Add block in invoice card
			if (in_array($object->element, ['facture']) && !getDolGlobalString('EINVOICING_DISABLE_SYNC_DOLI_TO_AP')) {
				$this->resprints .= $einvoicing->EInvoiceCardBlock($object, $action, $parameters);		// Output fields in card, including js for refreshing state
			}

			// Add block in supplier invoice card
			if (in_array($object->element, ['invoice_supplier']) && !getDolGlobalString('EINVOICING_DISABLE_SYNC_AP_TO_DOLI')) {
				$this->resprints .= $einvoicing->supplierInvoiceCardBlock($object, $action, $parameters);		// Output fields in card, including js for refreshing state
			}

			// Add block in product/service card
			if (in_array($object->element, ['product']) && (!getDolGlobalString('EINVOICING_DISABLE_SYNC_DOLI_TO_AP') || !getDolGlobalString('EINVOICING_DISABLE_SYNC_AP_TO_DOLI'))) {
				$this->resprints .= $einvoicing->productServiceCardBlock($object, $action, $parameters);		// Output fields in card, including js for refreshing state
			}

			// Add block in thirdparty card
			if (in_array($object->element, ['societe']) && (!getDolGlobalString('EINVOICING_DISABLE_SYNC_DOLI_TO_AP') || !getDolGlobalString('EINVOICING_DISABLE_SYNC_AP_TO_DOLI'))) {
				$this->resprints .= $einvoicing->thirdpartyCardBlock($object, $action, $parameters);		// Output fields in card
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
		if (in_array('invoicelist', explode(':', $parameters['context'])) && !getDolGlobalString('EINVOICING_DISABLE_SYNC_DOLI_TO_AP')) {
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

		if (in_array('thirdpartylist', explode(':', $parameters['context'])) && (!getDolGlobalString('EINVOICING_DISABLE_SYNC_DOLI_TO_AP') || !getDolGlobalString('EINVOICING_DISABLE_SYNC_AP_TO_DOLI'))) {
			// Add fields to invoice list
			$parameters['arrayfields']['routing_id'] = array(
				'label' => 'RoutingIdField',
				'help' => 'SpecificRoutingFieldHelp',
				'checked' => -1,
				'position' => 900,
				'enabled' => 1,
				'perms' => '1'
			);
			$parameters['arrayfields']['routing_product_id'] = array(
				'label' => 'DefaultProductEBilling',
				'checked' => -1,
				'position' => 901,
				'enabled' => '1',
				'perms' => '1'
			);
		}

		return 0;
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
		if (in_array('supplierinvoicelist', explode(':', $parameters['context']))) {
			$this->resprints .= ', ext.rowid AS pdplink_id, ext.provider AS pdp_provider';
		}

		if (in_array('thirdpartylist', explode(':', $parameters['context']))) {
			$this->resprints .= ', ext.rowid AS pdplink_id, ext.provider AS pdp_provider';
			$this->resprints .= ', rt.routing_id AS routing_id';
		}

		if (in_array('societelist', explode(':', $parameters['context']))) {
			$this->resprints .= ', ext.rowid AS pdplink_id, ext.provider AS pdp_provider';
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
			$this->resprints .= " LEFT JOIN " . MAIN_DB_PREFIX . "einvoicing_extlinks as ext ON ext.element_id = f.rowid AND ext.element_type = 'facture'";
		}

		// Supplier invoice list, Product list, Soc list
		$contexts = explode(':', $parameters['context']);

		if (array_intersect($contexts, ['supplierinvoicelist', 'thirdpartylist', 'productservicelist', 'societelist'])) {
			if (in_array('thirdpartylist', $contexts, true)) {
				$this->resprints .= ' LEFT JOIN ' . MAIN_DB_PREFIX . "einvoicing_extlinks as ext ON ext.element_id = s.rowid AND ext.element_type = 'societe'";
				$this->resprints .= ' LEFT JOIN ' . MAIN_DB_PREFIX . "einvoicing_routing rt ON rt.fk_soc = s.rowid";
			}

			if (in_array('supplierinvoicelist', $contexts, true)) {
				$this->resprints .= ' LEFT JOIN ' . MAIN_DB_PREFIX . "einvoicing_extlinks as ext ON ext.element_id = f.rowid AND ext.element_type = 'invoice_supplier'";
			}

			if (in_array('productservicelist', $contexts, true)) {
				$this->resprints .= ' LEFT JOIN ' . MAIN_DB_PREFIX . "einvoicing_extlinks as ext ON ext.element_id = p.rowid AND ext.element_type = 'product'";
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
				$this->resprints .= ' AND ext.syncstatus = ' . ((int) GETPOST('search_pdp_syncstatus'));
			}
		}

		// Supplier invoice list, Product list, Soc list
		$contexts = explode(':', $parameters['context']);
		if (array_intersect(
			$contexts,
			['supplierinvoicelist', 'thirdpartylist', 'productservicelist', 'societelist']
		)) {
			if (GETPOST('search_pdplinked', 'alpha') !== '' && GETPOST('search_pdplinked', 'alpha') == getDolGlobalString('EINVOICING_PDP')) {
				$this->resprints .= ' AND ext.provider = "' . getDolGlobalString('EINVOICING_PDP') . '"';
			}

			if (GETPOST('search_routing_id', 'alpha') !== '' && GETPOST('search_routing_id', 'alpha') != "") {
				$this->resprints .= ' AND ext.routing_id = "' . GETPOST('search_routing_id', 'alpha') . '"';
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

		if (in_array('invoicelist', explode(':', $parameters['context'])) && !getDolGlobalString('EINVOICING_DISABLE_SYNC_DOLI_TO_AP')) {
			$einvoicing = new EInvoicing($db);
			$checkConfig = $einvoicing->checkModulePrerequisites();
			if ($checkConfig < 0) {
				dol_syslog(__METHOD__ . "EINVOICING Module is not correctly configured.");
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
				$listofoptions = $einvoicing->getEinvoiceStatusOptions(0, 0, 0, 0, 1, 0, 1);

				// Remove option related to E-invoice generation status
				//unset($listofoptions[$einvoicing::STATUS_NOT_GENERATED]);
				//unset($listofoptions[$einvoicing::STATUS_GENERATED]);

				// Remove unknown status because "unknown" means there is no status set so we can't search on it.
				//if (in_array($action, array('add', 'create', 'edit', 'save'))) {
				unset($listofoptions[$einvoicing::STATUS_UNKNOWN]);
				//}

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
		if (in_array('supplierinvoicelist', explode(':', $parameters['context'])) && !getDolGlobalString('EINVOICING_DISABLE_SYNC_AP_TO_DOLI')) {
			$listofoptions = array(
				getDolGlobalString('EINVOICING_PDP') => getDolGlobalString('EINVOICING_PDP'),
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


		if (in_array('thirdpartylist', explode(':', $parameters['context'])) && (!getDolGlobalString('EINVOICING_DISABLE_SYNC_DOLI_TO_AP') || !getDolGlobalString('EINVOICING_DISABLE_SYNC_AP_TO_DOLI'))) {
			if (!empty($parameters['arrayfields']['einvoicegenerated']['checked'])) {
				print '<td class="liste_titre">';
				print '<input type="text" name="search_routing_id" value="' . GETPOST('search_routing_id', 'alpha') . '" class="minwidth50 maxwidth100">';
				print '</td>';
			}
		}

		if (in_array('productlist', explode(':', $parameters['context'])) && (!getDolGlobalString('EINVOICING_DISABLE_SYNC_DOLI_TO_AP') || !getDolGlobalString('EINVOICING_DISABLE_SYNC_AP_TO_DOLI'))) {
			// None yet
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

		if (in_array('invoicelist', explode(':', $parameters['context'])) && !getDolGlobalString('EINVOICING_DISABLE_SYNC_DOLI_TO_AP')) {
			$einvoicing = new EInvoicing($db);
			$checkConfig = $einvoicing->checkModulePrerequisites();
			if ($checkConfig < 0) {
				dol_syslog(__METHOD__ . "EINVOICING Module is not correctly configured.");
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
		if (in_array('supplierinvoicelist', explode(':', $parameters['context'])) && !getDolGlobalString('EINVOICING_DISABLE_SYNC_AP_TO_DOLI')) {
			print print_liste_field_titre($langs->transnoentitiesnoconv('einvoicingSourceTitle'));
		}

		if (in_array('thirdpartylist', explode(':', $parameters['context'])) && (!getDolGlobalString('EINVOICING_DISABLE_SYNC_DOLI_TO_AP') || !getDolGlobalString('EINVOICING_DISABLE_SYNC_AP_TO_DOLI'))) {
			if (!empty($parameters['arrayfields']['einvoicegenerated']['checked'])) {
				print print_liste_field_titre($langs->transnoentitiesnoconv('einvoicingThirdPartyRoutingTitle'));
			}
		}

		if (in_array('productlist', explode(':', $parameters['context'])) && (!getDolGlobalString('EINVOICING_DISABLE_SYNC_DOLI_TO_AP') || !getDolGlobalString('EINVOICING_DISABLE_SYNC_AP_TO_DOLI'))) {
			// None yet
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

		if (in_array('invoicelist', explode(':', $parameters['context'])) && !getDolGlobalString('EINVOICING_DISABLE_SYNC_DOLI_TO_AP')) {
			$obj = $parameters['obj'];

			$einvoicing = new EInvoicing($db);
			$checkConfig = $einvoicing->checkModulePrerequisites();
			if ($checkConfig < 0) {
				dol_syslog(__METHOD__ . "EINVOICING Module is not correctly configured.");
				return 0;
			}

			// E-invoice generation status
			if (!empty($parameters['arrayfields']['einvoicegenerated']['checked'])) {
				$tmparray = $einvoicing->fetchLastknownInvoiceStatus($obj->id, $obj->ref);
				$einvoiceGenerated = $tmparray['file'];
				print '<td class="center tdoverflowmax100">';
				if ($einvoiceGenerated) {
					print '<i class="fas fa-check-circle" style="color:green;" title="' . $langs->trans('EInvoiceGenerated') . '"></i>';
				}
				print '</td>';
				if (isset($parameters['i']) && empty($parameters['i'])) {
					$parameters['totalarray']['nbfield']++;
				}
			}

			// E-invoice sync status
			if (empty($parameters['arrayfields']['pdp_syncstatus']) || !empty($parameters['arrayfields']['pdp_syncstatus']['checked'])) {
				$currentStatusDetails = $obj->pdp_syncstatus ? $einvoicing->getStatusLabel($obj->pdp_syncstatus) : '-';
				print '<td class="center tdoverflowmax100" title="' . dolPrintHTMLForAttribute($currentStatusDetails) . '">';
				print $currentStatusDetails;
				print '</td>';
				if (isset($parameters['i']) && empty($parameters['i'])) {
					$parameters['totalarray']['nbfield']++;
				}
			}
		}

		// Supplier invoice list, Product list, Soc list
		if (in_array('supplierinvoicelist', explode(':', $parameters['context'])) && !getDolGlobalString('EINVOICING_DISABLE_SYNC_AP_TO_DOLI')) {
			$obj = $parameters['obj'];

			print '<td class="tdoverflowmax100">';
			if ($obj->pdplink_id) {
				print dolPrintHTML($obj->pdp_provider);
			}
			print '</td>';
			if (isset($parameters['i']) && empty($parameters['i'])) {
				$parameters['totalarray']['nbfield']++;
			}
		}

		if (in_array('thirdpartylist', explode(':', $parameters['context']), true)) {
			if (!empty($parameters['arrayfields']['einvoicegenerated']['checked'])) {
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

		$einvoicing = new EInvoicing($db);
		$currentStatusDetails = $einvoicing->fetchLastknownInvoiceStatus($object->id, $object->ref);

		// Block modification if invoice is already transmitted to PDP
		if ($currentStatusDetails['transmitted'] == 1) {
			$langs->load("einvoicing@einvoicing");

			$this->results = [
				'result' => -100, 	// Custom error code. Must be higher that core reserve code between -1...-50
				'error'  => $langs->trans('InvoiceLinkedToPdpCannotBeModified')
			];

			return 1;
		}

		return 0;
	}
}
