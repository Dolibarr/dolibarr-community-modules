<?php
/* Copyright (C) 2025		SuperAdmin					<daoud.mouhamed@gmail.com>
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
class ActionsPdpconnectfr
{
    /**
     * Hook called after a PDF is created
     *
     * @param array   $parameters Hook parameters
     * @param CommonObject $object The object related to the PDF (invoice, order, etc.)
     * @param string  $action     Current action
     * @param HookManager $hookmanager Hook manager instance
     * @return int    0 or 1
     */
    public function afterPDFCreation($parameters, &$object, &$action, $hookmanager)
    {
        global $db, $langs, $conf;

        dol_syslog(__METHOD__ . " Hook afterPDFCreation called for object " . get_class($object));

        // Invoice pdf path
        $pdfPath = $parameters['file'];

        $invoiceObject = $parameters['object'];
        // Check if it's an invoice
        if (get_class($invoiceObject) === 'Facture') {
            // Call function to create Factur-X document
            require __DIR__ . "/protocols/ProtocolManager.class.php";

            $usedProtocols = getDolGlobalString('PDPCONNECTFR_PROTOCOL');
            $ProtocolManager = new ProtocolManager($db);
            $protocol = $ProtocolManager->getprotocol($usedProtocols);

            $result = $protocol->generateInvoice($invoiceObject->id);
            if ($result) {
                setEventMessages('Result : ' . $result, null, 'warnings');
            } else {
                setEventMessages('', $protocol->errors, 'errors');
            }
        }

        return 0;
    }


    /**
     * Hook to add buttons on invoice card
     */
    function addMoreActionsButtons($parameters, &$object, &$action, $hookmanager)
    {
        global $db, $langs, $user, $conf;

        if (in_array($object->element, ['facture'])) {
            $langs->load("pdpconnectfr@pdpconnectfr");

            // Action to send invoice to PDP
            if ($action == 'send_to_pdp') {
                dol_include_once('/pdpconnectfr/class/providers/EsalinkPDPProvider.class.php');

                $provider = new EsalinkPDPProvider($db);

                // Exécution de la fonction d’envoi
                $result = $provider->sendInvoice($object);

                if ($result) {
                    $messages = array();
                    $messages[] = $langs->trans("InvoiceSuccessfullySentToPDP");
                    $messages[] = $langs->trans("FlowId") . ": " . $result;
                    setEventMessages('', $messages, 'warnings');
                } else {
                    setEventMessages("", $provider->errors, 'errors');
                }
            }

            $url_button = array();
            $url_button[] = array(
                'lang' => 'pdpconnectfr',
                'enabled' => (isModEnabled('pdpconnectfr')),
                'perm' => (bool) $user->rights->facture->creer,
                'label' => $langs->trans('checkInvoiceData') . (' TODO'),
                'url' => '/facture/card.php?id=' . $object->id . '&action=validate_invoice&token=' . newToken()
            );

            $url_button[] = array(
                'lang' => 'pdpconnectfr',
                'enabled' => (isModEnabled('pdpconnectfr')),
                'perm' => (bool) $user->rights->facture->creer,
                'label' => $langs->trans('checkCustomerData') . (' TODO'),
                'url' => '/facture/card.php?id=' . $object->id . '&action=validate_invoice&token=' . newToken()
            );

            $url_button[] = array(
                'lang' => 'pdpconnectfr',
                'enabled' => (isModEnabled('pdpconnectfr') /*&& $parameters['object']->status == Facture::STATUS_VALIDATED*/),
                'perm' => (bool) $user->rights->facture->creer,
                'label' => $langs->trans('sendToPDP'),
                'url' => '/compta/facture/card.php?id=' . $object->id . '&action=send_to_pdp&token=' . newToken()
            );

            print dolGetButtonAction('', $langs->trans('pdpBottom'), 'default', $url_button, '', true);
        }

        return 0;
    }
}
