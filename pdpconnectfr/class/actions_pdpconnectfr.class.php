<?php

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
        global $langs, $user, $conf;

        if (in_array($object->element, ['facture'])) {
            $langs->load("pdpconnectfr@pdpconnectfr");

            // Action to send invoice to PDP
            if ($action == 'send_to_pdp') {
                require_once DOL_DOCUMENT_ROOT . '/custom/pdpconnectfr/class/providers/EsalinkPDPProvider.class.php';

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


            /*print '<div class="inline-block">';
            print '<a class="butAction" href="'.dol_buildpath('/pdpconnectfr/script1.php', 1).'?facid='.$object->id.'">'.$langs->trans("MonBouton1").'</a>';
            print '<a class="butAction" href="'.dol_buildpath('/pdpconnectfr/script2.php', 1).'?facid='.$object->id.'">'.$langs->trans("MonBouton2").'</a>';
            print '<a class="butActionRefused" href="#">'.$langs->trans("BoutonDésactivé").'</a>';
            print '</div>';*/
        }

        return 0;
    }
}
