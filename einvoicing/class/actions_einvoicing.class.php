<?php
/* Copyright (C) 2025		Mohamed Daoud			<mdaoud@dolicloud.com>
 * Copyright (C) 2025		Laurent Destailleur		<eldy@users.sourceforge.net>
 * Copyright (C) 2026		Charlene Benke			<charlene@patas-monkey.com>
 * Copyright (C) 2026       Frédéric France         <frederic.france@free.fr>
 * Copyright (C) 2026		Jose Martinez				<jose.martinez@pichinov.com>
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
	// Relative to this file rather than through dol_buildpath(), for the reason given in
	// einvoicing.class.php: a resolution that fails is silent, and the class would just be missing.
	require_once __DIR__ . '/../compat/commonhookactions.class.php';
} else {
	require_once DOL_DOCUMENT_ROOT . '/core/class/commonhookactions.class.php';
}
require_once __DIR__ . "/einvoicing.class.php";
dol_include_once('/einvoicing/class/providers/PDPProviderManager.class.php');


/**
 * Class for hooks of module
 */
class ActionsEInvoicing extends CommonHookActions  // @phan-suppress-current-line PhanRedefinedExtendedClass
{
	/**
	 * @var string[] Errors the hook reports back to whoever executed it.
	 *
	 * Declared here rather than relied upon from the parent: CommonHookActions only declares ->errors
	 * from Dolibarr 21 and ->warnings from 23, and the compat class this module ships for the versions
	 * before 19 declares neither. Writing to an undeclared property is a deprecation on PHP 8.2, and
	 * reading one back into array_merge() is a fatal TypeError - which is what a failed generation did
	 * on 17 to 20.
	 */
	public $errors = array();

	/**
	 * @var string[] Warnings the hook reports back, on the versions whose core carries them
	 */
	public $warnings = array();

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
	public function afterPDFCreation($parameters, $object, &$action, $hookmanager)
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
			/** @var Facture $invoiceObject */

			// This PDF is the one the module is rebuilding to embed the e-invoice into: the caller is
			// producing the document, this hook must not produce it a second time and must not clean up
			// the temporary XML the caller still needs (issue #658). Nothing in $parameters says who
			// called generateDocument(), hence the request-scoped marker.
			if (EInvoicing::isEInvoiceGenerationInProgress($invoiceObject->id)) {
				dol_syslog(__METHOD__ . " the module is rebuilding the PDF of invoice id=" . $invoiceObject->id . " itself, nothing to do here");
				return 0;
			}

			// Ask the boolean question: needEInvoiceManagement() answers with a status code, and the codes
			// meaning "out of the e-invoicing scope" are truthy, so testing its answer for truth alone let an
			// ignored invoice (a B2C one when EINVOICING_SKIP_B2C is on, typically) walk into the checks below
			// and be reported as misconfigured.
			if ($einvoicing->mustManageEInvoice($invoiceObject)) {
				// Get current status of e-invoice
				$currentStatusDetails = $einvoicing->fetchLastknownInvoiceStatus($invoiceObject->id, $invoiceObject->ref);

				if (!isset($currentStatusDetails['code']) || !EInvoicing::isIgnoredStatus($currentStatusDetails['code'])) {
					if ($invoiceObject->status != $invoiceObject::STATUS_DRAFT	// Never generate/transmit an e-invoice for a DRAFT (note: at validation the invoice has already status VALIDATED when Dolibarr regenerates the final PDF, so the legitimate flow is preserved).
						&& !getDolGlobalString('EINVOICING_DISABLE_SYNC_DOLI_TO_AP')
						&& getDolGlobalString('EINVOICING_EINVOICE_IN_REAL_TIME')) {
						// Call function to create Factur-X document
						require_once __DIR__ . '/protocols/ProtocolManager.class.php';

						$usedProtocols = getDolGlobalString('EINVOICING_PROTOCOL');
						$ProtocolManager = new ProtocolManager($db);
						$protocol = $ProtocolManager->getProtocol($usedProtocols);

						$messagecss = '';
						$message = '';
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

						// Recipient directory reachability (opt-in): a recipient that is not routable does not make
						// the e-invoice document invalid, only undeliverable, so keep generating it and only warn.
						// The actual transmission is what gets blocked, by the send_to_pdp gate below.
						$routecheck = $einvoicing->checkRecipientRoutableForSend($invoiceObject);
						if (!$routecheck['ok']) {
							// "not routable" is only said when the directory proved it: an unconfirmed answer gets
							// its own wording, or the message would claim more than the directory reported.
							$warnkey = ($routecheck['status'] === 'undetermined') ? "EInvoiceGeneratedButRecipientReachabilityUnconfirmed" : "EInvoiceGeneratedButRecipientNotRoutable";
							$warnmsg = $langs->trans($warnkey) . ': <br>' . $routecheck['message'];
							dol_syslog(__METHOD__ . " " . strip_tags($warnmsg), LOG_WARNING);
							setEventMessages($warnmsg, array(), 'warnings');
							$this->warnings[] = $warnmsg;
						}

						$result = $protocol->generateInvoice($invoiceObject, $outputlangs, $pdfPath);		// Generate E-invoice (embed into the real generated file)

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

							// If the precheck is set to auto, we call the precheck function.
							$precheckresult = 0; // 0 = skipped , 1 = success, -1 = failed
							if (getDolGlobalString('EINVOICING_PDP') && getDolGlobalString('EINVOICING_AP_PRECHECK') === 'auto') {
								$PDPManager = new PDPProviderManager($db);
								$provider = $PDPManager->getProvider(getDolGlobalString('EINVOICING_PDP'));
								$precheckAvailable = $provider->hasValidator();
								if (!empty($currentStatusDetails['file']) && $currentStatusDetails['file'] == 1 && $precheckAvailable) {
									$einvoiceFilePath = $einvoicing->getEInvoiceFilePath($invoiceObject->ref);
									$result = $provider->validateEInvoiceFile($invoiceObject->id, $einvoiceFilePath);
									if ($result['res'] > 0) {
										$precheckresult = 1;
										setEventMessages($langs->trans("InvoicePrecheckSuccessful"), array(), 'mesgs');
									} else {
										$precheckresult = -1;
										setEventMessages($langs->trans("InvoicePrecheckFailed"), array(), 'errors');
									}
								}
							}

							// Optionally transmit to the Access Point right after generation (opt-in + idempotent) and if not yet generated.
							// Without this, validation only generates the Factur-X; the invoice is never sent to the
							// PA (transmission was a manual "send_to_pdp" click only).
							// Restricted to the generation that follows a validation, which is what the option says
							// it does. This hook is called for every rebuild of the invoice PDF, and several of them
							// happen long after the validation and with nobody asking for a transmission: recording a
							// payment rebuilds the document from inside Paiement::create(), and so do the "Generate"
							// button of the invoice card and any mass/cron PDF rebuild. On an invoice validated before
							// the module was set up - or deliberately left to be sent by hand - the first of those
							// rebuilds used to deposit it at the PA on its own, months after its date, and to unlock
							// the cash-in status (212) that the very same payment then reported.
							// Then two more guards, because one is not enough: 'transmitted' reads the syncstatus,
							// which generateInvoice() just reset to GENERATED a few lines above, so it stops seeing a
							// transmission from the second regeneration on. isTransmittedLockActive() reads the
							// flow_id, which the first submission assigned and nothing clears, so it holds for good
							// (and honors EINVOICING_ALLOW_RESEND_TRANSMITTED like the manual send does).
							if (getDolGlobalString('EINVOICING_AUTO_SEND_ON_GENERATION') && EInvoicing::isInvoiceValidatedInThisRequest($invoiceObject->id)
								&& empty($currentStatusDetails['transmitted'])
								&& !$einvoicing->isTransmittedLockActive($invoiceObject->id, $invoiceObject->ref) && $precheckresult >= 0) {
								dol_syslog("actions_einvoicing: Invoice seems not yet transmitted and EINVOICING_AUTO_SEND_ON_GENERATION is on, so we try to send it");

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
									// A hook can only report a warning where the core carries one back. That chain -
									// HookManager collecting $actionclassinstance->warnings, the document generator
									// copying $hookmanager->warnings, and commonGenerateDocument() copying $obj->warnings
									// onto the object - appears whole in Dolibarr 23 and is absent in 22. Below it the
									// warning would be reported nowhere, so the failure is raised as an error instead.
									if ((float) DOL_VERSION < 23) {
										$this->errors = array_merge($this->errors, $protocol->errors);
										$this->warnings = array();
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
		}

		return 0;
	}

	/**
	 * Hook called after an ODT/ODS document is created.
	 *
	 * Invoice templates rendered from an ODT model emit `afterODTCreation` (not `afterPDFCreation`),
	 * so the e-invoice generation must be triggered here too. Same logic as afterPDFCreation:
	 * $parameters['file'] then points to the .odt, and the protocol derives the matching PDF rendition.
	 *
	 * @param 	array   		$parameters 	Hook parameters
	 * @param 	CommonObject 	$object 		The object related to the document (invoice, order, etc.)
	 * @param 	string  		$action     	Current action
	 * @param 	HookManager 	$hookmanager 	Hook manager instance
	 * @return 	int    			0 or 1
	 */
	public function afterODTCreation($parameters, $object, &$action, $hookmanager)
	{
		return $this->afterPDFCreation($parameters, $object, $action, $hookmanager);
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
	public function addMoreActionsButtons($parameters, $object, &$action, $hookmanager)
	{
		global $db, $langs, $user;

		$langs->load("einvoicing@einvoicing");
		$einvoicing = new EInvoicing($db);
		$checkConfig = $einvoicing->checkModulePrerequisites();
		if ($checkConfig < 0) {
			dol_syslog(__METHOD__ . "EINVOICING Module is not correctly configured.");
			return 0;
		}

		$forcedisabling = '';
		// Add buttons in invoice card
		if (in_array($object->element, ['facture']) && !getDolGlobalString('EINVOICING_DISABLE_SYNC_DOLI_TO_AP')) {
			// Get current status of e-invoice
			$currentStatusDetails = $einvoicing->fetchLastknownInvoiceStatus($object->id, $object->ref);

			// Already transmitted to the PA (persistent flow_id): regenerate/re-send are locked by default
			// (immutable invoice; correct with a credit note / corrective invoice). Opt out with
			// EINVOICING_ALLOW_RESEND_TRANSMITTED.
			$locked = $einvoicing->isTransmittedLockActive($object->id, $object->ref);

			if (!empty($currentStatusDetails['otherprovider'])) {
				$forcedisabling = $langs->trans("WarningEinvoicingInvoiceStatusDifferentProvider", $currentStatusDetails['otherprovider']);
			}

			$url_button = array();

			if ($object->status == Facture::STATUS_VALIDATED || $object->status == Facture::STATUS_CLOSED) {
				// if E-invoice is not generated, show button to generate e-invoice
				// STATUS_IGNORE_2 has no entry in STATUS_LABEL_KEYS, so the "unknown code" fallback below used to
				// offer the generation button on an invoice explicitly excluded from e-invoicing. An ignored
				// invoice has nothing to generate, whatever its code is known or not.
				if (
					!EInvoicing::isIgnoredStatus($currentStatusDetails['code'])
					&& ($currentStatusDetails['code'] == $einvoicing::STATUS_NOT_GENERATED
						|| !array_key_exists($currentStatusDetails['code'], $einvoicing::STATUS_LABEL_KEYS))
				) {
					$url_button[] = array(
						'lang' => 'einvoicing',
						'enabled' => true,
						'perm' => ($forcedisabling ? -1 : ((bool) $user->hasRight("facture", "creer"))),
						'label' => $langs->trans('GenerateEinvoice'),
						//'help' => $langs->trans('GenerateEinvoiceHelp'),
						'url' => '/compta/facture/card.php?id=' . $object->id . '&action=generate_einvoice&token=' . newToken()
					);
				}

				// If the e-invoice is generated but not sent, or if it was sent and a validation error was received,
				// display the button to regenerate the e-invoice.
				// EINVOICING_ALLOW_REGEN_TRANSMITTED forces the regenerate button even on a transmitted-locked
				// invoice (dev only: let's you rebuild the CII/Factur-X to inspect the XML; nothing is re-sent).
				if (getDolGlobalString('EINVOICING_ALLOW_REGEN_TRANSMITTED')) {
					$perm = (bool) $user->hasRight("facture", "creer");
				} elseif (!$locked && in_array($currentStatusDetails['code'], [
					$einvoicing::STATUS_GENERATED,
					$einvoicing::STATUS_ERROR,
					$einvoicing::STATUS_UNKNOWN,
					$einvoicing::STATUS_AWAITING_VALIDATION,		// We may retry to Regenerate/resend. We should get an error if we do, but it is interesting to test the retry.
					$einvoicing::STATUS_AWAITING_ACK				// We may retry to Regenerate/resend. We should get an error if we do, but it is interesting to test the retry.
				])) {
					$perm = (bool) $user->hasRight("facture", "creer");
				} else {
					$perm = false;
				}
				$url_button[] = array(
					'lang' => 'einvoicing',
					'enabled' => true,
					'perm' => ($forcedisabling ? -1 : $perm),
					'label' => $langs->trans('RegenerateEinvoice'),
					'text' => $forcedisabling,
					//'help' => $langs->trans('RegenerateEinvoiceHelp'),
					'url' => '/compta/facture/card.php?id=' . $object->id . '&action=generate_einvoice&token=' . newToken()
				);

				// If the e-invoice is generated, display the button to precheck the e-invoice with the Access Point validation service if available.
				if (getDolGlobalString('EINVOICING_PDP') && getDolGlobalString('EINVOICING_AP_PRECHECK') === 'manuel') {
					$PDPManager = new PDPProviderManager($db);
					$provider = $PDPManager->getProvider(getDolGlobalString('EINVOICING_PDP'));
					$precheckAvailable = $provider->hasValidator();
					if (!empty($currentStatusDetails['file']) && $currentStatusDetails['file'] == 1 && $precheckAvailable) {
						$url_button[] = array(
							'lang' => 'einvoicing',
							'enabled' => true,
							'perm' => (bool) $user->hasRight("facture", "creer"),
							'label' => $langs->trans('PrecheckEinvoice'),
							'url' => '/compta/facture/card.php?id=' . $object->id . '&action=precheck_einvoice&token=' . newToken()
						);
					}
				}

				// If the e-invoice is generated but not sent, or if it was sent and a validation error was received,
				// display the button to regenerate the e-invoice
				// Re-send is offered for not-yet-transmitted states, plus AWAITING_* as a deliberate retry
				// affordance. Once REALLY transmitted (persistent flow_id), it is locked by default unless
				// EINVOICING_ALLOW_RESEND_TRANSMITTED is set ($locked already accounts for that opt-out).
				if (!$locked && in_array($currentStatusDetails['code'], [
					$einvoicing::STATUS_GENERATED,
					$einvoicing::STATUS_ERROR,
					$einvoicing::STATUS_UNKNOWN,
					$einvoicing::STATUS_AWAITING_VALIDATION,		// retry affordance (PA will refuse a duplicate)
					$einvoicing::STATUS_AWAITING_ACK				// retry affordance (PA will refuse a duplicate)
				])) {
					$resend = false;
					if (in_array($currentStatusDetails['code'], [$einvoicing::STATUS_AWAITING_VALIDATION, $einvoicing::STATUS_AWAITING_ACK])) {
						$resend = true;
					}
					$url_button[] = array(
						'lang' => 'einvoicing',
						'enabled' => 1,
						'perm' => ($forcedisabling ? -1 : ((bool) $user->hasRight("einvoicing", "write") && ($currentStatusDetails['file'] == 1))),
						'label' => $langs->trans('sendToPDP' . ($resend ? '2' : '')),
						'text' => $forcedisabling,
						//'help' => $langs->trans('SendToPDPHelp'),
						'url' => '/compta/facture/card.php?id=' . $object->id . '&action=send_to_pdp&token=' . newToken()
					);
				}
			}

			if (empty($parameters['context']) || !preg_match('/takepospay/', $parameters['context'])) {
				print '<!-- Current AP: ' . getDolGlobalString('EINVOICING_PDP') . ' -->';
				if (!empty($url_button)) {
					// dolGetButtonAction() only supports an array $url (dropdown mode) since Dolibarr 18;
					// use our own polyfill below that version.
					// Pass the visible label as the 1st arg ($label), not the 2nd ($text). On Dolibarr 18/19
					// the dropdown <a> renders only $label; v22+ falls back to $text when $label is empty,
					// but to keep behavior consistent across versions we always use $label.
					if ((float) DOL_VERSION < 18) {
						print einvoicingDolGetButtonActionDropdown($langs->trans('einvoice'), $url_button);
					} elseif ((float) DOL_VERSION < 22) {
						print dolGetButtonAction($langs->trans('einvoice'), '', 'default', $url_button, '', true);
					} else {
						print dolGetButtonAction('', $langs->trans('einvoice'), 'default', $url_button, '', true);
					}
				}

				// Once transmitted to the PA, the invoice is immutable. The BILL_UNVALIDATE / BILL_MODIFY
				// triggers already refuse the change server-side, but the core "Modify" (re-open) button
				// stays clickable and would just throw an error. There is no clean hook to remove a single
				// core button, so neutralize it client-side (disable + tooltip) for a clear UX. The trigger
				// remains the real enforcement (defense in depth). Honors EINVOICING_ALLOW_RESEND_TRANSMITTED.
				if ($locked && (empty($parameters['context']) || !preg_match('/takepospay/', $parameters['context']))) {
					print "\n<!-- einvoicing: lock core Modify button (transmitted invoice) -->\n";
					// Match by href (action=modif) so it works regardless of the button class / Dolibarr
					// version (top-level butAction or v22+ dropdown item).
					$jsmsg = json_encode($langs->trans('EInvoiceTransmittedModifyDisabled'));
					print '<script>jQuery(function($){var t=' . $jsmsg . ';$("div.tabsAction a[href*=\'action=modif\']").each(function(){$(this).removeClass("butAction").addClass("butActionRefused").removeAttr("href").css("cursor","not-allowed").attr("title",t).on("click",function(e){e.preventDefault();e.stopImmediatePropagation();return false;});});});</script>';
				}
			}
		}


		// Add buttons in supplier invoice card
		if (in_array($object->element, ['invoice_supplier']) && !getDolGlobalString('EINVOICING_DISABLE_SYNC_AP_TO_DOLI')) {
			// Check if this invoice is present into einvoicing_extlinks table to know if it is an imported invoice from PDP or not
			$sql = "SELECT rowid, provider FROM " . $db->prefix() . "einvoicing_extlinks";
			$sql .= " WHERE element_type = '" . $db->escape($object->element) . "'";
			$sql .= " AND element_id = " . (int) $object->id;
			$sql .= " LIMIT 1";

			$resql = $db->query($sql);
			if ($resql && $db->num_rows($resql) > 0) {
				$db->free($resql);
				// Offer what the exchange still allows, rather than closing it on the first final status:
				// the button group used to disappear as soon as an "Approved" (205) was accepted, while
				// the payment - and the "Payment transmitted" (211) that reports it - necessarily comes
				// after the approval (issue #548).
				$availableStatuses = $einvoicing->getSendableStatusesForReceivedInvoice($object->id, $object->element);

				// A button that quietly disappears looks like a bug. Say why the credit note of a refused
				// invoice cannot be accepted, and name the invoice it credits (issue #594).
				dol_include_once('einvoicing/class/utils/SupplierInvoiceHelper.class.php');
				$refusedSourceId = SupplierInvoiceHelper::refusedSourceOfCreditNote((int) $object->id);
				if ($refusedSourceId > 0) {
					$sourceInvoice = new FactureFournisseur($db);
					$sourceRef = ($sourceInvoice->fetch($refusedSourceId) > 0) ? ($sourceInvoice->ref_supplier ?: $sourceInvoice->ref) : (string) $refusedSourceId;
					print '<div class="info">' . $langs->trans('EInvoiceCreditNoteOfRefusedInvoice', $sourceRef) . '</div>';
				}

				$url_button = array();
				foreach ($availableStatuses as $code => $label) {
					$url_button[] = array(
						'lang' => 'einvoicing',
						'enabled' => true,
						'perm' => ($forcedisabling ? -1 : ((bool) $user->hasRight("fournisseur", "facture", "creer") && empty($forcedisabling))),
						'label' => (string) (is_array($label) ? ($label['label'] ?? '') : $label),
						'url' => '/fourn/facture/card.php?id=' . $object->id . '&action=sendStatusMessage&pdpstatuscode=' . $code . '&token=' . newToken()
					);
				}

				if (!empty($url_button)) {
					if ((float) DOL_VERSION < 18) {
						print einvoicingDolGetButtonActionDropdown($langs->trans('einvoice'), $url_button);
					} elseif ((float) DOL_VERSION < 22) {
						print dolGetButtonAction($langs->trans('einvoice'), '', 'default', $url_button, '', true);
					} else {
						print dolGetButtonAction('', $langs->trans('einvoice'), 'default', $url_button, '', true);
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
	public function doActions($parameters, $object, &$action, $hookmanager)
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
		$currentStatusDetails = null;

		$isFactureContext = isset($object->element) && in_array($object->element, ['facture']) && !getDolGlobalString('EINVOICING_DISABLE_SYNC_DOLI_TO_AP');
		$isSupplierInvoiceContext = isset($object->element) && in_array($object->element, ['invoice_supplier']) && !getDolGlobalString('EINVOICING_DISABLE_SYNC_AP_TO_DOLI');
		$isThirdpartyContext = array_intersect(['thirdpartycard', 'thirdpartycomm'], $contexts) && (!getDolGlobalString('EINVOICING_DISABLE_SYNC_DOLI_TO_AP') || !getDolGlobalString('EINVOICING_DISABLE_SYNC_AP_TO_DOLI'));

		if (!$isFactureContext && !$isSupplierInvoiceContext && !$isThirdpartyContext) {
			// Nothing relevant to this hook call for the current object/context: skip the transaction entirely.
			return 0;
		}

		$db->begin();

		if ($isFactureContext) {
			'@phan-var-force Facture $object';
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
			if ($action == 'setoverriderouting' && $permissiontoedit && is_array($currentStatusDetails)) {
				$overrideRoutingId = GETPOST('override_routing_id', 'alphanohtml');
				$result = $einvoicing->insertOrUpdateExtLink($object->id, $object->element, '', $currentStatusDetails['code'], $object->ref, $currentStatusDetails['info'], $overrideRoutingId);
				if ($result < 0) {
					$error++;
					$this->errors = array_merge($this->errors, $einvoicing->errors);
				}
			}

			// Action to set the buyer reference of the invoice (BT-10, issue #678)
			if ($action == 'setbuyerreference' && $permissiontoedit) {
				$buyerReference = GETPOST('einvoice_buyer_reference', 'alphanohtml');
				$result = $einvoicing->insertOrUpdateExtraField($object->id, $object->element, EInvoicing::EXTRAFIELD_BUYER_REFERENCE, $buyerReference);
				if ($result < 0) {
					$error++;
					$this->errors = array_merge($this->errors, $einvoicing->errors);
				}
			}

			// An invoice already transmitted to the Access Point (a flow_id is assigned, by any provider) is
			// immutable: re-sending it makes the PA refuse a duplicate, and regenerating it would only reset
			// the local status and re-open that trap. Block both by default; correct a transmitted invoice
			// with a credit note / corrective invoice. The operator can opt in (e.g. to test PA retry) via
			// EINVOICING_ALLOW_RESEND_TRANSMITTED. Based on the persistent flow_id, not the resettable status.
			// EINVOICING_ALLOW_REGEN_TRANSMITTED keeps regenerate (generate_einvoice) available on a
			// transmitted-locked invoice for dev/inspection; re-sending (send_to_pdp) stays locked.
			$lockedActions = getDolGlobalString('EINVOICING_ALLOW_REGEN_TRANSMITTED')
				? array('send_to_pdp')
				: array('send_to_pdp', 'generate_einvoice');
			if (in_array($action, $lockedActions) && isset($currentStatusDetails)
				&& $einvoicing->isTransmittedLockActive($object->id, $object->ref)) {
				setEventMessages($langs->trans('EInvoiceAlreadyTransmittedLocked', $currentStatusDetails['flow_id']), null, 'warnings');
				$action = '';
			}

			// Action to send invoice to Access Point
			if (
				$action == 'send_to_pdp' && $permissiontoedit
				&& is_array($currentStatusDetails)
				&& $currentStatusDetails['file'] == 1
				&& in_array($currentStatusDetails['code'], [
					$einvoicing::STATUS_GENERATED,
					$einvoicing::STATUS_ERROR,
					$einvoicing::STATUS_UNKNOWN
				])
			) {
				// Same gates and same transmission as the mass action of the invoice list
				$PDPManager = new PDPProviderManager($db);
				$provider = $PDPManager->getProvider(getDolGlobalString('EINVOICING_PDP'));

				$sendresult = $this->sendOneInvoiceToAccessPoint($object, $einvoicing, $provider);

				foreach ($sendresult['warnings'] as $warning) {
					// Non-blocking warning: notify user but proceed with sending
					dol_syslog(__METHOD__ . " " . $warning);
					setEventMessages($warning, array(), 'warnings');
				}

				if ($sendresult['res'] > 0) {
					$messages = array();
					$messages[] = $langs->trans("InvoiceSuccessfullySentToPDP");
					$messages[] = $langs->trans("FlowId") . ": " . $sendresult['flowid'];
					setEventMessages('', $messages, 'mesgs');
					// Once transmitted, the invoice is locked from re-edit/regenerate/re-send: the
					// BILL_UNVALIDATE / BILL_MODIFY triggers and the guards above key on the persistent
					// flow_id (EInvoicing::isTransmittedLockActive), overridable via EINVOICING_ALLOW_RESEND_TRANSMITTED.
				} elseif ($sendresult['res'] < 0) {
					$error++;
					foreach ($sendresult['errors'] as $senderror) {
						dol_syslog(__METHOD__ . " " . $senderror, LOG_ERR);
					}
					$this->errors = array_merge($this->errors, $sendresult['errors']);
				}
			}

			// Action to generate the E-invoice alone
			if ($action == 'generate_einvoice' && $permissiontoedit) {
				// Same gates and same generation as the mass action of the invoice list
				require_once __DIR__ . '/protocols/ProtocolManager.class.php';
				$ProtocolManager = new ProtocolManager($db);
				$protocol = $ProtocolManager->getProtocol(getDolGlobalString('EINVOICING_PROTOCOL'));

				$genresult = $this->generateOneEInvoice($object, $einvoicing, $protocol, $outputlangs);

				if ($genresult['res'] > 0) {
					dol_syslog(__METHOD__ . " Invoice generated successfully for invoice ID " . $object->id);

					$this->warnings = array_merge($this->warnings, $genresult['warnings']);
					if (!empty($this->warnings)) {
						setEventMessages($langs->trans("InvoiceGeneratedWithWarnings"), $this->warnings, 'warnings');
					} else {
						setEventMessages($langs->trans("EInvoiceGenerated"), array(), 'mesgs');
					}

					if ($genresult['precheck'] === 1) {
						setEventMessages($langs->trans("InvoicePrecheckSuccessful"), array(), 'mesgs');
					} elseif ($genresult['precheck'] === 0) {
						dol_syslog(__METHOD__ . " Invoice precheck failed for invoice ID " . $object->id);
					}
				} elseif ($genresult['res'] < 0) {
					$error++;
					dol_syslog(__METHOD__ . " " . implode(',', $genresult['errors']));
					$this->errors = array_merge($this->errors, $genresult['errors']);
					$this->warnings = array();
				}
			}

			// Action to precheck the E-invoice with the Access Point validation service (only if not already sent)
			if (
				$action == 'precheck_einvoice' && $permissiontoedit
				&& is_array($currentStatusDetails)
				&& $currentStatusDetails['file'] == 1
			) {
				// Call precheck method of the Access Point provider
				$PDPManager = new PDPProviderManager($db);
				$provider = $PDPManager->getProvider(getDolGlobalString('EINVOICING_PDP'));
				$einvoiceFilePath = $einvoicing->getEInvoiceFilePath($object->ref);
				$result = $provider->validateEInvoiceFile($object->id, $einvoiceFilePath);
				if ($result['res'] > 0) {
					setEventMessages($langs->trans("InvoicePrecheckSuccessful"), array(), 'mesgs');
				} else {
					setEventMessages($langs->trans("InvoicePrecheckFailed"), array(), 'errors');
				}
			}
		}


		if ($isSupplierInvoiceContext) {
			$permissiontoedit = $user->hasRight('fournisseur', 'facture', 'creer');

			if ($action == 'confirm_sendStatusMessage' && $permissiontoedit) {
				$PDPManager = new PDPProviderManager($db);
				$provider = $PDPManager->getProvider(getDolGlobalString('EINVOICING_PDP'));
				$pdpstatuscode = GETPOSTINT('pdpstatuscode') ?: 0;
				$statusRaison = GETPOST('statusRaison', 'alpha');

				// The card stops offering it, but the card is not what sends: a status travels here as a
				// parameter of an URL, so this is where a credit note crediting an invoice we refused is
				// actually kept from being accepted (issue #594).
				if (in_array($pdpstatuscode, EInvoicing::STATUSES_ACCEPTING_A_DOCUMENT, true)) {
					dol_include_once('einvoicing/class/utils/SupplierInvoiceHelper.class.php');
					$refusedSourceId = SupplierInvoiceHelper::refusedSourceOfCreditNote((int) $object->id);
					if ($refusedSourceId > 0) {
						$sourceInvoice = new FactureFournisseur($db);
						$sourceRef = ($sourceInvoice->fetch($refusedSourceId) > 0) ? ($sourceInvoice->ref_supplier ?: $sourceInvoice->ref) : (string) $refusedSourceId;
						$message = $langs->trans('EInvoiceCannotAcceptCreditNoteOfRefusedInvoice', $sourceRef);
						dol_syslog(__METHOD__ . ' ' . strip_tags($message), LOG_WARNING, 0, '_einvoicing');
						setEventMessages($message, array(), 'errors');
						$this->errors[] = $message;

						return 0;
					}
				}

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

		if ($isThirdpartyContext) {
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
	 * Add the e-invoice mass actions to the combo of the customer invoice list.
	 *
	 * @param array<string,mixed>	$parameters		Array of parameters
	 * @param CommonObject|null		$object			Object (not provided for this hook)
	 * @param string				$action			Action code
	 * @param HookManager			$hookmanager	Hook manager
	 * @return int									0 to let the caller add its own actions too
	 */
	public function addMoreMassActions($parameters, $object, &$action, $hookmanager)
	{
		global $langs, $user;

		if (!$this->isMassSendAvailable($parameters)) {
			return 0;
		}

		$langs->load("einvoicing@einvoicing");

		$out = '';
		foreach (array('einvoicing_generate' => "EInvoiceMassGenerate", 'einvoicing_send_to_pdp' => "EInvoiceMassSendToPDP") as $code => $key) {
			$label = $langs->trans($key);
			$out .= '<option value="'.$code.'" data-html="'.dol_escape_htmltag($label).'">'.$label.'</option>';
		}
		$this->resprints = $out;

		return 0;
	}

	/**
	 * Transmit the selected invoices to the Access Point.
	 *
	 * Only the invoices whose e-invoice file is already generated and not transmitted yet are sent;
	 * nothing is generated here, so the mass action never depends on the document generation of a
	 * given protocol. The others are counted and reported, never silently dropped.
	 *
	 * @param array<string,mixed>	$parameters		Array of parameters, with 'toselect' and 'massaction'
	 * @param CommonObject			$object			Object of the list
	 * @param string				$action			Action code
	 * @param HookManager			$hookmanager	Hook manager
	 * @return int									1 when the mass action was handled, <0 on setup error
	 */
	public function doMassActions($parameters, $object, &$action, $hookmanager)
	{
		global $db, $langs, $user;

		$massaction = empty($parameters['massaction']) ? '' : $parameters['massaction'];
		if (!in_array($massaction, array('einvoicing_send_to_pdp', 'einvoicing_generate'))) {
			return 0;
		}
		if (!$this->isMassSendAvailable($parameters)) {
			return 0;
		}

		$langs->load("einvoicing@einvoicing");

		$einvoicing = new EInvoicing($db);
		if ($einvoicing->checkModulePrerequisites() < 0) {
			$this->errors[] = $langs->trans("CheckPdpConfiguration");
			return -1;
		}

		$provider = null;
		if ($massaction == 'einvoicing_send_to_pdp') {
			require_once __DIR__ . '/providers/PDPProviderManager.class.php';
			$PDPManager = new PDPProviderManager($db);
			$provider = $PDPManager->getProvider(getDolGlobalString('EINVOICING_PDP'));
			if (!is_object($provider)) {
				$this->errors[] = $langs->trans("CheckPdpConfiguration");
				return -1;
			}
		} else {
			require_once __DIR__ . '/protocols/ProtocolManager.class.php';
		}

		require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';

		$toselect = (isset($parameters['toselect']) && is_array($parameters['toselect'])) ? $parameters['toselect'] : array();
		$done = 0;
		$skipped = 0;
		$failed = 0;
		$lines = array();

		foreach ($toselect as $id) {
			$invoice = new Facture($db);
			if ($invoice->fetch((int) $id) <= 0) {
				$failed++;
				$lines[] = $langs->trans("ErrorLoadingInvoice") . ' (id ' . ((int) $id) . ')';
				continue;
			}

			if ($massaction == 'einvoicing_send_to_pdp') {
				$result = $this->sendOneInvoiceToAccessPoint($invoice, $einvoicing, $provider);
				$okline = $langs->trans("FlowId") . ' ' . $result['flowid'];
			} else {
				// A protocol per invoice: the object collects the warnings and the errors of the one
				// document it produced, and must not carry them over to the next invoice of the batch.
				$ProtocolManager = new ProtocolManager($db);
				$protocol = $ProtocolManager->getProtocol(getDolGlobalString('EINVOICING_PROTOCOL'));
				$result = $this->generateOneEInvoice($invoice, $einvoicing, $protocol, $langs);
				$okline = $langs->trans("EInvoiceGenerated");
			}

			if ($result['res'] > 0) {
				$done++;
				$lines[] = $invoice->ref . ' : ' . $okline
					. (empty($result['warnings']) ? '' : ' - ' . implode(' - ', $result['warnings']));
			} elseif ($result['res'] == 0) {
				$skipped++;
				$lines[] = $invoice->ref . ' : ' . $result['reason'];
			} else {
				$failed++;
				$lines[] = $invoice->ref . ' : ' . implode(' - ', $result['errors']);
			}
		}

		$summary = ($massaction == 'einvoicing_send_to_pdp') ? "EInvoiceMassSendResult" : "EInvoiceMassGenerateResult";
		setEventMessages($langs->trans($summary, $done, $skipped, $failed), $lines, $failed ? 'warnings' : 'mesgs');

		return 1;
	}

	/**
	 * Generate the e-invoice document of one invoice, with the gates of the invoice card.
	 *
	 * @param Facture		$invoice		Invoice whose e-invoice has to be produced
	 * @param EInvoicing	$einvoicing		Module object, reused over a batch
	 * @param object|null	$protocol		Protocol of the module, one instance per invoice
	 * @param Translate		$outputlangs	Output language
	 * @return array{res:int<-1,1>, reason:string, precheck:string|int, warnings:string[], errors:string[]}	res: 1 generated, 0 skipped (reason), -1 failed (errors)
	 */
	private function generateOneEInvoice($invoice, $einvoicing, $protocol, $outputlangs)
	{
		global $db, $langs;

		$out = array('res' => 0, 'reason' => '', 'precheck' => '', 'warnings' => array(), 'errors' => array());

		if (!is_object($protocol)) {
			$out['res'] = -1;
			$out['errors'][] = $langs->trans("CheckPdpConfiguration");
			return $out;
		}

		// Regenerating a transmitted invoice resets its local status and re-opens the trap the send gate
		// closes, so it is refused here as on the invoice card, unless EINVOICING_ALLOW_REGEN_TRANSMITTED.
		if (!getDolGlobalString('EINVOICING_ALLOW_REGEN_TRANSMITTED')
			&& $einvoicing->isTransmittedLockActive($invoice->id, (string) $invoice->ref)) {
			$status = $einvoicing->fetchLastknownInvoiceStatus($invoice->id, (string) $invoice->ref);
			$out['reason'] = $langs->trans('EInvoiceAlreadyTransmittedLocked', is_array($status) ? $status['flow_id'] : '');
			return $out;
		}

		$invoice->fetch_thirdparty();

		$check = $einvoicing->checkRequiredinformations($invoice);
		if ($check['res'] < 0) {
			$out['res'] = -1;
			$out['errors'][] = $langs->trans("InvoiceNotgeneratedDueToConfigurationIssues") . ': ' . strip_tags($check['message']);
			return $out;
		} elseif ($check['res'] == 0) {
			$out['warnings'][] = strip_tags($check['message']);
		}

		// A recipient that is not routable does not make the document invalid, only undeliverable: keep
		// generating and only warn. The transmission is what gets blocked, by the send gate.
		$routecheck = $einvoicing->checkRecipientRoutableForSend($invoice);
		if (!$routecheck['ok']) {
			$warnkey = ($routecheck['status'] === 'undetermined') ? "EInvoiceGeneratedButRecipientReachabilityUnconfirmed" : "EInvoiceGeneratedButRecipientNotRoutable";
			$out['warnings'][] = $langs->trans($warnkey) . ': ' . strip_tags($routecheck['message']);
		}

		$result = $protocol->generateInvoice($invoice, $outputlangs);
		if (!$result || (is_numeric($result) && $result <= 0)) {
			// On failure the warnings are part of the story: they are moved into the errors, as the card does
			$out['res'] = -1;
			$out['errors'] = array_merge((array) $protocol->errors, $out['warnings']);
			$out['warnings'] = array();
			return $out;
		}

		$out['res'] = 1;
		$out['warnings'] = array_merge($out['warnings'], (array) $protocol->warnings);

		// Precheck the e-invoice with the validation service of the Access Point when the setup asks for it
		if (getDolGlobalString('EINVOICING_PDP') && getDolGlobalString('EINVOICING_AP_PRECHECK') === 'auto') {
			require_once __DIR__ . '/providers/PDPProviderManager.class.php';
			$PDPManager = new PDPProviderManager($db);
			$provider = $PDPManager->getProvider(getDolGlobalString('EINVOICING_PDP'));
			if (is_object($provider) && $provider->hasValidator()) {
				$precheck = $provider->validateEInvoiceFile($invoice->id, $einvoicing->getEInvoiceFilePath($invoice->ref));
				$out['precheck'] = ($precheck['res'] > 0) ? 1 : 0;
				if ($out['precheck'] === 0) {
					$out['warnings'][] = $langs->trans("InvoicePrecheckFailed");
				}
			}
		}

		return $out;
	}

	/**
	 * Tell whether the e-invoice mass actions apply to the current call.
	 *
	 * @param array<string,mixed>	$parameters		Parameters of the hook, with its 'context'
	 * @return bool
	 */
	private function isMassSendAvailable($parameters)
	{
		global $user;

		$contexts = explode(':', empty($parameters['context']) ? '' : $parameters['context']);
		if (!in_array('invoicelist', $contexts)) {
			return false;
		}

		return !getDolGlobalString('EINVOICING_DISABLE_SYNC_DOLI_TO_AP') && $user->hasRight('facture', 'write');
	}

	/**
	 * Transmit one already generated e-invoice to the Access Point, with the gates of the invoice card.
	 *
	 * @param Facture			$invoice		Invoice to transmit
	 * @param EInvoicing		$einvoicing		Module object, reused over a batch
	 * @param object|null		$provider		Access Point provider
	 * @return array{res:int<-1,1>, flowid:string, reason:string, warnings:string[], errors:string[]}	res: 1 sent, 0 skipped (reason), -1 failed (errors)
	 */
	private function sendOneInvoiceToAccessPoint($invoice, $einvoicing, $provider)
	{
		global $langs;

		$out = array('res' => 0, 'flowid' => '', 'reason' => '', 'warnings' => array(), 'errors' => array());

		if (!is_object($provider)) {
			$out['res'] = -1;
			$out['errors'][] = $langs->trans("CheckPdpConfiguration");
			return $out;
		}

		$status = $einvoicing->fetchLastknownInvoiceStatus($invoice->id, (string) $invoice->ref);

		// An invoice already transmitted is immutable: re-sending it makes the PA refuse a duplicate.
		// Keyed on the persistent flow_id, like the invoice card, and honors EINVOICING_ALLOW_RESEND_TRANSMITTED.
		if ($einvoicing->isTransmittedLockActive($invoice->id, (string) $invoice->ref)) {
			$out['reason'] = $langs->trans('EInvoiceAlreadyTransmittedLocked', is_array($status) ? $status['flow_id'] : '');
			return $out;
		}

		if (!is_array($status) || empty($status['file']) || !in_array($status['code'], array(
			$einvoicing::STATUS_GENERATED,
			$einvoicing::STATUS_ERROR,
			$einvoicing::STATUS_UNKNOWN
		))) {
			$out['reason'] = $langs->trans("EInvoiceNotGeneratedYetSoNotSent");
			return $out;
		}

		$invoice->fetch_thirdparty();

		$checkResult = $einvoicing->checkRequiredinformations($invoice);
		if ($checkResult['res'] < 0) {
			$out['res'] = -1;
			$out['errors'][] = $langs->trans("InvoiceNotSentToPDPDueToThirdpartyIssues") . ': ' . strip_tags($checkResult['message']);
			return $out;
		} elseif ($checkResult['res'] == 0) {
			$out['warnings'][] = strip_tags($checkResult['message']);
		}

		// Gate on recipient directory reachability (opt-in): do not transmit to a recipient the platform
		// would reject for a routing error (fr:213).
		$routecheck = $einvoicing->checkRecipientRoutableForSend($invoice);
		if (!$routecheck['ok']) {
			$errkey = ($routecheck['status'] === 'undetermined') ? "EInvoiceNotSentRecipientReachabilityUnconfirmed" : "EInvoiceNotSentRecipientNotRoutable";
			$out['res'] = -1;
			$out['errors'][] = $langs->trans($errkey) . ': ' . strip_tags($routecheck['message']);
			return $out;
		}

		$flowid = $provider->sendInvoice($invoice);
		if ($flowid) {
			$out['res'] = 1;
			$out['flowid'] = (string) $flowid;
			return $out;
		}

		$out['res'] = -1;
		$out['errors'] = $provider->errors ? $provider->errors : array($provider->error);

		return $out;
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
							'values' => $einvoicing->getReasonsByStatus($pdpstatuscode, 1),
							'select_translate' => 1
						]
					);
				}

				$formconfirm = $form->formconfirm(
					DOL_URL_ROOT . "/fourn/facture/card.php?id={$object->id}&action=confirm_sendStatusMessage&pdpstatuscode={$pdpstatuscode}",
					$langs->trans('SendStatusMessage'),
					$langs->trans('ConfirmSendStatusMessage', (string) $object->ref, (string) $einvoicing->getStatusLabel($pdpstatuscode)),
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
	public function formObjectOptions($parameters, $object, &$action, $hookmanager)
	{
		global $db, $langs;

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
				'@phan-var-force Facture $object';
				$this->resprints .= $einvoicing->EInvoiceCardBlock($object, $action, $parameters);		// Output fields in card, including js for refreshing state
			}

			// Add block in supplier invoice card
			if (in_array($object->element, ['invoice_supplier']) && !getDolGlobalString('EINVOICING_DISABLE_SYNC_AP_TO_DOLI')) {
				'@phan-var-force FactureFournisseur $object';
				$this->resprints .= $einvoicing->supplierInvoiceCardBlock($object, $action, $parameters);		// Output fields in card, including js for refreshing state
			}

			// Add block in product/service card
			if (in_array($object->element, ['product']) && (!getDolGlobalString('EINVOICING_DISABLE_SYNC_DOLI_TO_AP') || !getDolGlobalString('EINVOICING_DISABLE_SYNC_AP_TO_DOLI'))) {
				'@phan-var-force Product $object';
				$this->resprints .= $einvoicing->productServiceCardBlock($object, $action, $parameters);		// Output fields in card, including js for refreshing state
			}

			// Add block in thirdparty card
			if (in_array($object->element, ['societe']) && (!getDolGlobalString('EINVOICING_DISABLE_SYNC_DOLI_TO_AP') || !getDolGlobalString('EINVOICING_DISABLE_SYNC_AP_TO_DOLI'))) {
				'@phan-var-force Societe $object';
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
	public function completeArrayFields($parameters, $object, &$action, $hookmanager)
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
	 * Build the sub query returning the last lifecycle status validated by the Access Point for a supplier invoice.
	 * Same selection rule as the one used by EInvoicing::supplierInvoiceCardBlock() so list and card always agree.
	 *
	 * @return string								SQL sub query (without the surrounding parenthesis), correlated on f.rowid
	 */
	protected static function getSupplierLifecycleStatusSubQuery()
	{
		$sql = 'SELECT lc.lc_status FROM ' . MAIN_DB_PREFIX . 'einvoicing_lifecycle_msg as lc';
		$sql .= " WHERE lc.element_type = 'invoice_supplier' AND lc.element_id = f.rowid";
		$sql .= " AND lc.lc_validation_status = 'Ok'";
		$sql .= ' ORDER BY lc.rowid DESC LIMIT 1';

		return $sql;
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
	public function printFieldListSelect($parameters, $object, &$action, $hookmanager)
	{
		// Invoice list
		if (in_array('invoicelist', explode(':', $parameters['context']))) {
			$this->resprints .= ', ext.rowid AS pdplink_id, ext.provider AS pdp_provider';
			$this->resprints .= ', ext.syncstatus AS pdp_syncstatus';
		}

		// Supplier invoice list, Product list, Soc list
		if (in_array('supplierinvoicelist', explode(':', $parameters['context']))) {
			$this->resprints .= ', ext.rowid AS pdplink_id, ext.provider AS pdp_provider';
			// Last known lifecycle status accepted by the Access Point, same source as the one shown on the invoice card
			$this->resprints .= ', (' . self::getSupplierLifecycleStatusSubQuery() . ') AS pdp_lcstatus';
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
	public function printFieldListFrom($parameters, $object, &$action, $hookmanager)
	{
		global $db;

		// Supplier invoice list, Product list, Soc list
		$contexts = explode(':', $parameters['context']);

		if (array_intersect($contexts, ['invoicelist', 'supplierinvoicelist', 'thirdpartylist', 'productservicelist', 'societelist'])) {
			if (in_array('thirdpartylist', $contexts, true)) {
				$this->resprints .= ' LEFT JOIN ' . $db->prefix() . "einvoicing_extlinks as ext ON ext.element_id = s.rowid AND ext.element_type = 'societe'";
				$this->resprints .= ' LEFT JOIN ' . $db->prefix() . "einvoicing_routing rt ON rt.fk_soc = s.rowid";
			}

			if (in_array('invoicelist', explode(':', $parameters['context']))) {
				$this->resprints .= " LEFT JOIN " . $db->prefix() . "einvoicing_extlinks as ext ON ext.element_id = f.rowid AND ext.element_type = 'facture'";
			}

			if (in_array('supplierinvoicelist', $contexts, true)) {
				$this->resprints .= ' LEFT JOIN ' . $db->prefix() . "einvoicing_extlinks as ext ON ext.element_id = f.rowid AND ext.element_type = 'invoice_supplier'";
			}

			if (in_array('productservicelist', $contexts, true)) {
				$this->resprints .= ' LEFT JOIN ' . $db->prefix() . "einvoicing_extlinks as ext ON ext.element_id = p.rowid AND ext.element_type = 'product'";
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
	public function printFieldListWhere($parameters, $object, &$action, $hookmanager)
	{
		global $db;

		$contexts = explode(':', $parameters['context']);

		if (array_intersect($contexts, ['invoicelist', 'supplierinvoicelist', 'thirdpartylist', 'productservicelist', 'societelist'])) {
			if (GETPOST('search_pdplinked', 'alpha') !== '' && GETPOST('search_pdplinked', 'alpha') == getDolGlobalString('EINVOICING_PDP')) {
				$this->resprints .= " AND ext.provider = '" . $db->escape(getDolGlobalString('EINVOICING_PDP')) . "'";
			}

			if (GETPOST('search_routing_id', 'alpha') !== '' && GETPOST('search_routing_id', 'alpha') != "") {
				$this->resprints .= " AND ext.routing_id = '" . $db->escape(GETPOST('search_routing_id', 'alpha')) . "'";
			}
		}

		if (in_array('invoicelist', $contexts) && !getDolGlobalString('EINVOICING_DISABLE_SYNC_DOLI_TO_AP')) {
			if (GETPOST('search_pdp_syncstatus', 'alpha') !== '' && GETPOST('search_pdp_syncstatus', 'alpha') != -2) {
				$this->resprints .= ' AND ext.syncstatus = ' . ((int) GETPOST('search_pdp_syncstatus'));
			}
		}

		if (in_array('supplierinvoicelist', $contexts) && !getDolGlobalString('EINVOICING_DISABLE_SYNC_AP_TO_DOLI') && GETPOST('search_pdp_lcstatus', 'alpha') !== '' && GETPOST('search_pdp_lcstatus', 'alpha') != -2) {
			$this->resprints .= ' AND (' . self::getSupplierLifecycleStatusSubQuery() . ') = ' . GETPOSTINT('search_pdp_lcstatus');
		}

		// Supplier invoice lines to bind to accountancy : always exclude invoices abandoned
		// because their refusal was confirmed by the e-invoicing platform (PDP/PA), they must
		// never be transferred to accountancy.
		if (in_array('accountancysupplierlist', $contexts)) {
			require_once DOL_DOCUMENT_ROOT . '/fourn/class/fournisseur.facture.class.php';
			dol_include_once('einvoicing/class/utils/SupplierInvoiceHelper.class.php');

			$this->resprints .= ' AND NOT (f.fk_statut = ' . ((int) FactureFournisseur::STATUS_ABANDONED)
				. " AND f.close_code = '" . $db->escape(SupplierInvoiceHelper::CLOSECODE_PDPREFUSED) . "')";
		}

		return 0;
	}


	/**
	 * Add GROUP BY fields
	 * Mandatory for the fields added by printFieldListSelect() on lists that build a GROUP BY clause,
	 * otherwise MySQL rejects the query with sql_mode=only_full_group_by (error 1055).
	 * Only supplierinvoicelist is concerned: thirdpartylist/societelist call the hook without any
	 * GROUP BY clause, and productservicelist selects no column from the joined table.
	 *
	 * @param array<string,mixed> 	$parameters		Array of parameters
	 * @param CommonObject			$object			Object invoice
	 * @param string		 		$action			Code action
	 * @param Hookmanager			$hookmanager	Hookmanager
	 * @return int									Result
	 */
	public function printFieldListGroupBy($parameters, $object, &$action, $hookmanager)
	{
		if (in_array('supplierinvoicelist', explode(':', $parameters['context']), true)) {
			$this->resprints .= ', ext.rowid, ext.provider';
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
	public function printFieldListOption($parameters, $object, &$action, $hookmanager)
	{
		global $form, $db;

		if (in_array('invoicelist', explode(':', $parameters['context'])) && !getDolGlobalString('EINVOICING_DISABLE_SYNC_DOLI_TO_AP')) {
			$einvoicing = new EInvoicing($db);
			$checkConfig = $einvoicing->checkModulePrerequisites();
			if ($checkConfig < 0) {
				dol_syslog(__METHOD__ . "EINVOICING Module is not correctly configured.");
				return 0;
			}

			$tmpeinvoicingpartner = preg_replace('/ViaPartner/i', '', getDolGlobalString('EINVOICING_PDP'));
			$listofoptions = array(
				$tmpeinvoicingpartner => $tmpeinvoicingpartner,
			);

			// AP platform
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

			// E-invoice status
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

				// Einvoice status
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
			$tmpeinvoicingpartner = preg_replace('/ViaPartner/i', '', getDolGlobalString('EINVOICING_PDP'));
			$listofoptions = array(
				$tmpeinvoicingpartner => $tmpeinvoicingpartner,
			);

			// AP platform
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

			// E-invoice status of the supplier invoice into the Access Point system
			$einvoicing = new EInvoicing($db);
			print '<td class="liste_titre pdp_lcstatus">';
			print $form->selectarray(
				'search_pdp_lcstatus',
				$einvoicing->getEinvoiceStatusOptions(0, 1),
				GETPOST('search_pdp_lcstatus', 'alpha'),
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
				print '<input type="text" name="search_routing_id" value="' . dolPrintHTMLForAttribute(GETPOST('search_routing_id', 'alpha')) . '" class="minwidth50 maxwidth100">';
				print '</td>';
			}
		}

		// @phan-suppress-next-line PhanPluginEmptyStatementIf
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
	public function printFieldListTitle($parameters, $object, &$action, $hookmanager)
	{
		global $db, $langs;

		$contexts = explode(':', $parameters['context']);

		if (in_array('invoicelist', $contexts) && !getDolGlobalString('EINVOICING_DISABLE_SYNC_DOLI_TO_AP')) {
			$einvoicing = new EInvoicing($db);
			$checkConfig = $einvoicing->checkModulePrerequisites();
			if ($checkConfig < 0) {
				dol_syslog(__METHOD__ . "EINVOICING Module is not correctly configured.");
				return 0;
			}

			print_liste_field_titre($langs->transnoentitiesnoconv('einvoicingSourceTitle'));

			// Einvoice generated or not
			if (!empty($parameters['arrayfields']['einvoicegenerated']['checked'])) {
				print_liste_field_titre($langs->transnoentitiesnoconv('EInvoiceFile'), '', '', '', $parameters['param'] ?? '', '', $parameters['sortfield'] ?? '', $parameters['sotorder'] ?? '', 'center ');
			}

			// syncstatus
			if (empty($parameters['arrayfields']['pdp_syncstatus']) || !empty($parameters['arrayfields']['pdp_syncstatus']['checked'])) {
				print_liste_field_titre($langs->transnoentitiesnoconv('PDPSyncStatus'), '', '', '', $parameters['param'] ?? '', '', $parameters['sortfield'] ?? '', $parameters['sotorder'] ?? '', 'center ');
			}
		}

		// Supplier invoice list, Product list, Soc list
		if (in_array('supplierinvoicelist', $contexts) && !getDolGlobalString('EINVOICING_DISABLE_SYNC_AP_TO_DOLI')) {
			print_liste_field_titre($langs->transnoentitiesnoconv('einvoicingSourceTitle'));
			print_liste_field_titre($langs->transnoentitiesnoconv('einvoicingInvoiceStatus'), '', '', '', $parameters['param'] ?? '', '', '', '', 'center ');
		}

		if (in_array('thirdpartylist', $contexts) && (!getDolGlobalString('EINVOICING_DISABLE_SYNC_DOLI_TO_AP') || !getDolGlobalString('EINVOICING_DISABLE_SYNC_AP_TO_DOLI'))) {
			if (!empty($parameters['arrayfields']['einvoicegenerated']['checked'])) {
				print_liste_field_titre($langs->transnoentitiesnoconv('einvoicingThirdPartyRoutingTitle'));
			}
		}

		// @phan-suppress-next-line PhanPluginEmptyStatementIf
		if (in_array('productlist', $contexts) && (!getDolGlobalString('EINVOICING_DISABLE_SYNC_DOLI_TO_AP') || !getDolGlobalString('EINVOICING_DISABLE_SYNC_AP_TO_DOLI'))) {
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
	public function printFieldListValue($parameters, $object, &$action, $hookmanager)
	{
		global $db, $langs;

		$contexts = explode(':', $parameters['context']);

		// Every block below counts the cells it prints into the caller's column counter, which sizes the
		// footer of the list. A hook is not guaranteed to receive that counter already built, so make sure
		// of it once, before the first increment rather than after it. Only create the key when it is
		// missing: the caller passes its own counter by reference (list.php builds 'totalarray' =>
		// &$totalarray), so replacing an existing one would write through that reference and reset the
		// count it has already accumulated.
		if (!array_key_exists('totalarray', $parameters)) {
			$parameters['totalarray'] = array('nbfield' => 0);
		} elseif (!array_key_exists('nbfield', $parameters['totalarray'])) {
			$parameters['totalarray']['nbfield'] = 0;
		}

		if (in_array('invoicelist', $contexts) && !getDolGlobalString('EINVOICING_DISABLE_SYNC_DOLI_TO_AP')) {
			$einvoicing = new EInvoicing($db);
			$checkConfig = $einvoicing->checkModulePrerequisites();
			if ($checkConfig < 0) {
				dol_syslog(__METHOD__ . "EINVOICING Module is not correctly configured.");
				return 0;
			}

			$obj = $parameters['obj'];

			print '<td class="tdoverflowmax100">';
			if ($obj->pdplink_id) {
				print dolPrintHTML($obj->pdp_provider);
			}
			print '</td>';
			if (isset($parameters['i']) && empty($parameters['i'])) {
				$parameters['totalarray']['nbfield']++;
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
		if (in_array('supplierinvoicelist', $contexts) && !getDolGlobalString('EINVOICING_DISABLE_SYNC_AP_TO_DOLI')) {
			$obj = $parameters['obj'];

			print '<td class="tdoverflowmax100">';
			if ($obj->pdplink_id) {
				print dolPrintHTML($obj->pdp_provider);
			}
			print '</td>';
			if (isset($parameters['i']) && empty($parameters['i'])) {
				$parameters['totalarray']['nbfield']++;
			}

			// E-invoice status of the supplier invoice into the Access Point system
			$einvoicing = new EInvoicing($db);
			$currentStatusDetails = $obj->pdp_lcstatus ? $einvoicing->getStatusLabel((int) $obj->pdp_lcstatus) : '-';
			print '<td class="center tdoverflowmax100" title="' . dolPrintHTMLForAttribute($currentStatusDetails) . '">';
			print $currentStatusDetails;
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
	public function isEditable($parameters, $object, &$action, $hookmanager)
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

	/**
	 * Called by Societe::mergeCompany() when two thirdparties are merged, so the e-invoicing
	 * routing IDs registered on the absorbed thirdparty (llx_einvoicing_routing.fk_soc) are not
	 * silently orphaned and lost on the surviving one.
	 *
	 * @param array{soc_origin:int,soc_dest:int} 	$parameters		Array of parameters (soc_origin = absorbed thirdparty id, soc_dest = surviving thirdparty id)
	 * @param CommonObject							$object			Destination thirdparty object
	 * @param string								$action			Code action
	 * @param Hookmanager							$hookmanager	Hookmanager
	 * @return int									0 on success/nothing to do, -1 on error (sets $this->error/$this->errors)
	 */
	public function replaceThirdparty($parameters, $object, &$action, $hookmanager)
	{
		global $db;

		$socOrigin = (int) ($parameters['soc_origin'] ?? 0);
		$socDest = (int) ($parameters['soc_dest'] ?? 0);

		if ($socOrigin <= 0 || $socDest <= 0) {
			return 0;
		}

		$sql = "UPDATE " . $db->prefix() . "einvoicing_routing";
		$sql .= " SET fk_soc = " . (int) $socDest;
		$sql .= " WHERE fk_soc = " . (int) $socOrigin;

		$resql = $db->query($sql);
		if (!$resql) {
			dol_syslog(__METHOD__ . " Failed to reassign einvoicing_routing.fk_soc from " . $socOrigin . " to " . $socDest . ": " . $db->lasterror(), LOG_ERR);
			$this->error = $db->lasterror();
			$this->errors[] = $this->error;
			return -1;
		}

		return 0;
	}
}
