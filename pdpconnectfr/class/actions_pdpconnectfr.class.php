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

        // Invoice pdf path
        $pdfPath = $parameters['file'];

        $invoiceObject = $parameters['object'];
        // Check if it's an invoice
        if (get_class($invoiceObject) === 'Facture') {
        	if (getDolGlobalString('PDPCONNECTFR_EINVOICE_IN_REAL_TIME')) {
	            // Call function to create Factur-X document
	            require __DIR__ . "/protocols/ProtocolManager.class.php";
	            require __DIR__ . "/pdpconnectfr.php";

	            $usedProtocols = getDolGlobalString('PDPCONNECTFR_PROTOCOL');
	            $ProtocolManager = new ProtocolManager($db);
	            $protocol = $ProtocolManager->getprotocol($usedProtocols);

	            // Check configuration
	            $result = checkRequiredinformations($invoiceObject->thirdparty);
	            if ($result['res'] < 0) {
	                $message = $langs->trans("InvoiceNotgeneratedDueToConfigurationIssues") . ': <br>' . $result['message'];
	                if (getDolGlobalString('PDPCONNECTFR_EINVOICE_CANCEL_IF_EINVOICE_FAILS')) {
	                	$this->errors[] = $message;
	                	return -1;
	                } else {
	                	$this->warnings[] = $message;
	                	return 0;
	                }
	            }

	            $result = $protocol->generateInvoice($invoiceObject->id);		// Generate E-invoice
	            if ($result) {
	                // No error;
	            } else {
	                if (getDolGlobalString('PDPCONNECTFR_EINVOICE_CANCEL_IF_EINVOICE_FAILS')) {
	            		$this->errors[] = $protocol->errors;
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
	 * Overload the addMoreMassActions function : replacing the parent's function with the one below
	 *
	 * @param	array<string,mixed>	$parameters     Hook metadata (context, etc...)
	 * @param	CommonObject		$object         The object to process (an invoice if you are in invoice module, a propale in propale's module, etc...)
	 * @param	?string				$action			Current action (if set). Generally create or edit or null
	 * @param	HookManager			$hookmanager	Hook manager propagated to allow calling another hook
	 * @return	int									Return integer < 0 on error, 0 on success, 1 to replace standard code
     */
    function addMoreActionsButtons($parameters, &$object, &$action, $hookmanager)
    {
        global $db, $langs, $user;

        if (in_array($object->element, ['facture'])) {
            $langs->load("pdpconnectfr@pdpconnectfr");

            $url_button = array();

            if ($object->status == Facture::STATUS_VALIDATED) {
                // if E-invoice is not generated, show button to generate e-invoice
                if ($object->array_options['options_pdpconnectfr_einvoice_status'] == 0) {
                    $url_button[] = array(
                        'lang' => 'pdpconnectfr',
                        'enabled' => 1,
                        'perm' => (bool) $user->rights->facture->creer,
                        'label' => $langs->trans('GenerateEinvoice'),
                        //'help' => $langs->trans('GenerateEinvoiceHelp'),
                        'url' => '/compta/facture/card.php?id=' . $object->id . '&action=generate_einvoice&token=' . newToken()
                    );
                }

                // if E-invoice is generated but not sent, show button to regenerate e-invoice
                if ($object->array_options['options_pdpconnectfr_einvoice_status'] == 1) {
                    $url_button[] = array(
                        'lang' => 'pdpconnectfr',
                        'enabled' => 1,
                        'perm' => (bool) $user->rights->facture->creer,
                        'label' => $langs->trans('RegenerateEinvoice'),
                        //'help' => $langs->trans('RegenerateEinvoiceHelp'),
                        'url' => '/compta/facture/card.php?id=' . $object->id . '&action=generate_einvoice&token=' . newToken()
                    );
                }

                // if E-invoice is generated but not sent, show button to send e-invoice
                if ($object->array_options['options_pdpconnectfr_einvoice_status'] == 1) { // E-invoice Generated but not sent
                    $url_button[] = array(
                        'lang' => 'pdpconnectfr',
                        'enabled' => 1,
                        'perm' => (bool) $user->rights->facture->creer,
                        'label' => $langs->trans('sendToPDP'),
                        //'help' => $langs->trans('SendToPDPHelp'),
                        'url' => '/compta/facture/card.php?id=' . $object->id . '&action=send_to_pdp&token=' . newToken()
                    );
                }
            }

            print dolGetButtonAction('', $langs->trans('einvoice'), 'default', $url_button, '', true);
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
    function doActions($parameters, &$object, &$action, $hookmanager) {
        global $db, $langs, $user;

        dol_syslog(__METHOD__ . " Hook doActions called for object " . get_class($object) . " action=" . $action);

        // Action to send invoice to PDP
        if ($action == 'send_to_pdp') {
            dol_include_once('/pdpconnectfr/class/providers/EsalinkPDPProvider.class.php');

            $provider = new EsalinkPDPProvider($db);

            // Send invoice
            $result = $provider->sendInvoice($object);

            if ($result) {
                $messages = array();
                $messages[] = $langs->trans("InvoiceSuccessfullySentToPDP");
                $messages[] = $langs->trans("FlowId") . ": " . $result;
                setEventMessages('', $messages, 'warnings');
                // TODO: Review and update the invoice workflow.
                // The "Modify" button may need to be disabled once the E-invoice has been sent and distributed by the PDP.
            } else {
                setEventMessages("", $provider->errors, 'errors');
            }
        }

        if ($action == 'generate_einvoice') {
            $invoiceObject = $object;
            // Call function to create Factur-X document
            require __DIR__ . "/protocols/ProtocolManager.class.php";
            require __DIR__ . "/pdpconnectfr.php";

            $usedProtocols = getDolGlobalString('PDPCONNECTFR_PROTOCOL');
            $ProtocolManager = new ProtocolManager($db);
            $protocol = $ProtocolManager->getprotocol($usedProtocols);

            // Check configuration
            $result = checkRequiredinformations($invoiceObject->thirdparty);
            if ($result['res'] < 0) {
                $message = $langs->trans("InvoiceNotgeneratedDueToConfigurationIssues") . ': <br>' . $result['message'];
                $this->warnings[] = $message;
            }

            $result = $protocol->generateInvoice($invoiceObject->id);		// Generate E-invoice
            if ($result) {
                // No error;
                $object->array_options['options_pdpconnectfr_einvoice_status'] = 1;
                return 0;
            } else {
                $this->errors[] = $protocol->errors;
                return 0;
            }
        }

        return 0;
    }
}
