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
}
