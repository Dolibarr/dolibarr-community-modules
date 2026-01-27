<?php
/* Copyright (C) 2025       Laurent Destailleur         <eldy@users.sourceforge.net>
 * Copyright (C) 2025       Mohamed DAOUD               <mdaoud@dolicloud.com>
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
 * \file    pdpconnectfr/class/pdpconnectfr.class.php
 * \ingroup pdpconnectfr
 * \brief   Base class for all functions to manage PDPCONNECTFR Module.
 */

/**
 * Validate mysoc configuration
 *
 * @return array{res:int, message:string}       Returns array with 'res' (1 on success, -1 on failure) and info 'message'
 */

class PdpConnectFr
{
    /**
	 * @var DoliDB Database handler.
	 */
	public $db;



    // Dolibarr internal statuses
    public const STATUS_UNKNOWN             = -1;
    public const STATUS_NOT_GENERATED       = 0;
    public const STATUS_GENERATED           = 1;
    public const STATUS_AWAITING_VALIDATION = 2;
    public const STATUS_AWAITING_ACK        = 3;
    public const STATUS_ERROR               = 4;

    // PDP / PA normalized statuses
    public const STATUS_DEPOSITED           = 200;
    public const STATUS_ISSUED              = 201;
    public const STATUS_RECEIVED            = 202;
    public const STATUS_AVAILABLE           = 203;
    public const STATUS_TAKEN_OVER          = 204;
    public const STATUS_APPROVED            = 205;
    public const STATUS_PARTIALLY_APPROVED  = 206;
    public const STATUS_DISPUTED            = 207;
    public const STATUS_SUSPENDED           = 208;
    public const STATUS_COMPLETED           = 209;
    public const STATUS_REFUSED             = 210;
    public const STATUS_PAYMENT_SENT        = 211;
    public const STATUS_PAID                = 212;
    public const STATUS_REJECTED            = 213;

    private const STATUS_LABEL_KEYS = [
        // Dolibarr
        self::STATUS_UNKNOWN             => 'EInvStatusUnknown',
        self::STATUS_NOT_GENERATED       => 'EInvStatusNotGenerated',
        self::STATUS_GENERATED           => 'EInvStatusGenerated',
        self::STATUS_AWAITING_VALIDATION => 'EInvStatusAwaitingValidation',
        self::STATUS_AWAITING_ACK        => 'EInvStatusAwaitingAck',
        self::STATUS_ERROR               => 'EInvStatusError',

        // PDP / PA
        self::STATUS_DEPOSITED           => 'EInvStatus200Deposited',
        self::STATUS_ISSUED              => 'EInvStatus201Issued',
        self::STATUS_RECEIVED            => 'EInvStatus202Received',
        self::STATUS_AVAILABLE           => 'EInvStatus203Available',
        self::STATUS_TAKEN_OVER          => 'EInvStatus204TakenOver',
        self::STATUS_APPROVED            => 'EInvStatus205Approved',
        self::STATUS_PARTIALLY_APPROVED  => 'EInvStatus206PartiallyApproved',
        self::STATUS_DISPUTED            => 'EInvStatus207Disputed',
        self::STATUS_SUSPENDED           => 'EInvStatus208Suspended',
        self::STATUS_COMPLETED           => 'EInvStatus209Completed',
        self::STATUS_REFUSED             => 'EInvStatus210Refused',
        self::STATUS_PAYMENT_SENT        => 'EInvStatus211PaymentTransmitted',
        self::STATUS_PAID                => 'EInvStatus212Paid',
        self::STATUS_REJECTED            => 'EInvStatus213Rejected',
    ];

    /**
	 * Constructor
	 *
	 * @param DoliDB $db handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

    /**
     * Return label for an e-invoice status code
     *
     * @param int|string $code
     * @return string
     */
    public function getStatusLabel($code)
    {
        global $langs;

        $code = (int) $code;

        return $langs->trans(
            self::STATUS_LABEL_KEYS[$code] ?? 'EInvStatusUnknown'
        );
    }

    /**
     * Get internal Dolibarr status code from PDP/PA status label (only for validation statuses 'Error', 'Pending', 'Ok', other status like lifecycle codes are normalized and with the same code in both systems)
     *
     * @param string $label PDP/PA status label can be 'Error', 'Pending', 'Ok', etc.
     * @return int
     */
    public function getDolibarrStatusCodeFromPdpLabel($label)
    {
        switch ($label) {
            case 'Error':
                return self::STATUS_ERROR;
            case 'Pending':
                return self::STATUS_AWAITING_VALIDATION;
            case 'Ok':
                return self::STATUS_AWAITING_ACK;
            default:
                return self::STATUS_UNKNOWN;
        }
    }

    /**
     * Get all e-invoice status options
     *
     * @param int $includeCodesInLabel 0 to not include codes in label, 1 to include codes in label
     * @param int $onlyPdpStatuses If 1, only return PDP/PA statuses (exclude Dolibarr internal statuses)
     * @param int $onlySendable If 1, only return statuses that can be sent to PDP/PA (for example, exclude STATUS_ERROR)
     *
     * @return array<int, string>
     */
    public function getEinvoiceStatusOptions($includeCodesInLabel = 0, $onlyPdpStatuses = 0, $onlySendable = 0)
    {
        global $langs;
        $options = [];
        foreach (self::STATUS_LABEL_KEYS as $code => $labelKey) {
            $value = $langs->trans($labelKey);
            if ($includeCodesInLabel === 1) {
                $value = $value . ' (' . $code . ')';
            }
            $options[$code] = $value;
        }

        if ($onlyPdpStatuses === 1) {
            // Remove Dolibarr internal statuses
            unset($options[self::STATUS_UNKNOWN]);
            unset($options[self::STATUS_NOT_GENERATED]);
            unset($options[self::STATUS_GENERATED]);
            unset($options[self::STATUS_AWAITING_VALIDATION]);
            unset($options[self::STATUS_AWAITING_ACK]);
            unset($options[self::STATUS_ERROR]);
        }

        if ($onlySendable === 1) {
            // Remove Dolibarr internal statuses
            unset($options[self::STATUS_UNKNOWN]);
            unset($options[self::STATUS_NOT_GENERATED]);
            unset($options[self::STATUS_GENERATED]);
            unset($options[self::STATUS_AWAITING_VALIDATION]);
            unset($options[self::STATUS_AWAITING_ACK]);
            unset($options[self::STATUS_ERROR]);
            // Remove PDP/PA statuses that cannot be sent
            unset($options[self::STATUS_DEPOSITED]);
            unset($options[self::STATUS_ISSUED]);
            unset($options[self::STATUS_RECEIVED]);
            unset($options[self::STATUS_AVAILABLE]);


        }


        return $options;
    }

    /**
     * Validate my company configuration
     *
     * @return array{res:int, message:string} Returns array with 'res' (1 on success, -1 on error and 0 on warning) and info 'message'
     */
    public function validateMyCompanyConfiguration()
    {
        global $langs, $mysoc;

        $res = 1;
        $message = '';
        $baseErrors = [];
        $baseWarnings = [];

        if (empty($mysoc->idprof1)) {
            $baseErrors[] = $langs->trans("FxCheckErrorIDPROF1");
        }
        if (empty($mysoc->tva_intra)) {
            $baseWarnings[] = $langs->trans("FxCheckErrorVATnumber");
        }
        if (empty($mysoc->address)) {
            $baseWarnings[] = $langs->trans("FxCheckErrorAddress");
        }
        if (empty($mysoc->zip)) {
            $baseWarnings[] = $langs->trans("FxCheckErrorZIP");
        }
        if (empty($mysoc->town)) {
            $baseWarnings[] = $langs->trans("FxCheckErrorTown");
        }
        if (empty($mysoc->country_code)) {
            $baseErrors[] = $langs->trans("FxCheckErrorCountry");
        }

        if (!empty($baseWarnings)) {
            $res = 0;
            $message .= '<br> Warning: ' . implode('<br> Warning: ', $baseWarnings);
        }
        if (!empty($baseErrors)) {
            $res = -1;
            $message .= '<br> Error: ' . implode('<br> Error: ', $baseErrors);
        }
        if (empty($baseErrors) && empty($baseWarnings)) {
            $res = 1;
        }

        return ['res' => $res, 'message' => $message];
    }

    /**
     * Validate thirdparty configuration
     *
     * @param Societe $thirdparty   Thirdparty object
     * @return array{res:int, message:string} Returns array with 'res' (1 on success, -1 on error and 0 on warning) and info 'message'
     */
    public function validatethirdpartyConfiguration($thirdparty)
    {
        global $langs, $mysoc;

        $res = 1;
        $message = '';
        $baseErrors = [];
        $baseWarnings = [];

        if (empty($thirdparty->name)) {
            $baseErrors[] = $langs->trans("FxCheckErrorCustomerName");
        }
        if (empty($thirdparty->idprof1)) {
            $baseErrors[] = $langs->trans("FxCheckErrorCustomerIDPROF1");
        }
        // if (empty($thirdparty->idprof2)) {
        //     $baseErrors[] = $langs->trans("FxCheckErrorCustomerIDPROF2");
        // }
        if (empty($thirdparty->address)) {
            $baseWarnings[] = $langs->trans("FxCheckErrorCustomerAddress");
        }
        if (empty($thirdparty->zip)) {
            $baseWarnings[] = $langs->trans("FxCheckErrorCustomerZIP");
        }
        if (empty($thirdparty->town)) {
            $baseWarnings[] = $langs->trans("FxCheckErrorCustomerTown");
        }
        if (empty($thirdparty->country_code)) {
            $baseWarnings[] = $langs->trans("FxCheckErrorCustomerCountry");
        }
        if ($thirdparty->tva_assuj && empty($thirdparty->tva_intra)) {
            // Test VAT code only if thirdparty is subject to VAT
            $baseWarnings[] = $langs->trans("FxCheckErrorCustomerVAT");
        }
        if (empty($thirdparty->email)) {
            $baseErrors[] = $langs->trans("FxCheckErrorCustomerEmail");
        }

        if (!empty($baseWarnings)) {
            $res = 0;
            $message .= '<br> Warning: ' . implode('<br> Warning: ', $baseWarnings);
        }
        if (!empty($baseErrors)) {
            $res = -1;
            $message .= '<br> Error: ' . implode('<br> Error: ', $baseErrors);
        }
        if (empty($baseErrors) && empty($baseWarnings)) {
            $res = 1;
        }

        return ['res' => $res, 'message' => $message];
    }

    /**
     * Validate chorus specific informations
     *
     * @param Facture $object   Invoice object
     *
     * @return array{res:int, message:string} Returns array with 'res' (1 on success, -1 on error and 0 on warning) and info 'message'
     */
    public function validateChorusInformations($object)
    { // TODO add a field into pdpconnectfr_extlinks table to define if this invoice is for chorus or not and all chorus specific fields and then replace use of extrafields
        global $langs, $mysoc;

        $res = 1;
        $message = '';
        $baseErrors = [];
        $baseWarnings = [];

        if (empty($object->array_options['options_d4d_promise_code'])) {
            $baseWarnings[] = "N° d'engagement absent";
        } elseif (strlen($object->array_options['options_d4d_promise_code']) > 50) {
            $baseWarnings[] = "Ref client trop longue pour chorus (max 50 caractères)";
        }

        if (empty($object->array_options['options_d4d_contract_number'])) {
            $baseWarnings[] = "N° de marché absent";
        }

        if (empty($object->array_options['options_d4d_service_code'])) {
            $baseWarnings[] = "Code service absent";
        }

        if (empty($object->thirdparty->idprof2)) {
            $baseWarnings[] = "Numéro SIRET du client manquant";
        }

        if (!empty($baseWarnings)) {
            $res = 0;
            $message .= '<br> Warning chorus: ' . implode('<br> Warning chorus: ', $baseWarnings);
        }
        if (!empty($baseErrors)) {
            $res = -1;
            $message .= '<br> Error chorus: ' . implode('<br> Error chorus: ', $baseErrors);
        }
        if (empty($baseErrors) && empty($baseWarnings)) {
            $res = 1;
        }

        return ['res' => $res, 'message' => $message];

    }

    /**
     * Check required informations for E-Invoicing
     *
     * @param Facture $invoice   Invoice object
     *
     * @return array{res:int, message:string} Returns array with 'res' (1 on success, -1 on failure and 0 on warning) and info 'message'
     */
    public function checkRequiredinformations($invoice) {

        $messages = [];
        $mysocConfigCheck = $this->validateMyCompanyConfiguration();
        $socConfigCheck = $this->validatethirdpartyConfiguration($invoice->thirdparty);
        if (getDolGlobalInt('PDPCONNECTFR_USE_CHORUS')) {
            $chorusConfigCheck = $this->validateChorusInformations($invoice);
        }
        if (!empty($mysocConfigCheck['message'])) {
            $messages[] = $mysocConfigCheck['message'];
        }
        if (!empty($socConfigCheck['message'])) {
            $messages[] = $socConfigCheck['message'];
        }
        if (!empty($chorusConfigCheck['message'])) {
            $messages[] = $chorusConfigCheck['message'];
        }

        $res = 1;
        if ($mysocConfigCheck['res'] === -1 || $socConfigCheck['res'] === -1 || (isset($chorusConfigCheck) && $chorusConfigCheck['res'] === -1)) {
            $res = -1;
        } elseif ($mysocConfigCheck['res'] === 0 || $socConfigCheck['res'] === 0 || (isset($chorusConfigCheck) && $chorusConfigCheck['res'] === 0)) {
            $res = 0;
        }

        $message = implode('<br>', $messages);

        return ['res' => $res, 'message' => $message];
    }

    /**
     * EInvoiceCardBlock
     *
     * @param 	Facture $object		Facture
     * @return 	string				HTML content to add
     */
    public function EInvoiceCardBlock($object) {
        global $langs;

        $currentStatusInfo = $this->fetchLastknownInvoiceStatus($object->ref);
		// Force value for test
		//$currentStatusInfo['code'] = 2;

        $resprints = '';

        // Title
        $resprints .= '<tr class="liste_titre">';
        $resprints .= '<td colspan="2"><span class="far fa-plus-square"></span><strong> ' . $langs->trans("pdpconnectfrInvoiceSeparator") . '</strong></td>';
        $resprints .= '</tr>';

        // PDP Status
        $resprints .= '<tr>';
        $resprints .= '<td class="titlefield">'
            . $langs->trans("pdpconnectfrInvoiceStatus")
            . ' <i class="fas fa-info-circle em088 opacityhigh classfortooltip" title="'
            . $langs->trans("einvoiceStatusFieldHelp") . '"></i></td>';
        $resprints .= '<td><span id="einvoice-status">'
            . $currentStatusInfo['status'] . '</span></td>';
        $resprints .= '</tr>';

        // PDP Info
        $info = $currentStatusInfo['info'] ?? '';
        $displayStyle = !empty($info) ? '' : 'style="display:none;"';

        $resprints .= '<tr id="einvoice-info-row" ' . $displayStyle . '>';
        $resprints .= '<td class="titlefield">' . $langs->trans("pdpconnectfrInvoiceInfo") . '</td>';
        $resprints .= '<td><span id="einvoice-info">' . htmlspecialchars($info) . '</span></td>';
        $resprints .= '</tr>';

        // E-Invoice events history link
        $resprints .= '<tr>';
        $resprints .= '<td>' . $langs->trans("EInvoiceEventsLabel") . '</td>';
        $url = dol_buildpath('compta/facture/agenda.php', 1) . '?id=' . urlencode($object->id) . '&search_agenda_label=PDPCONNECTFR';
        $resprints .= '<td><a href="' . $url . '">' . $langs->trans("EInvoiceEventsLink") . ' <i class="fas fa-history"></i></a></td>';
        $resprints .= '</tr>';


        // JavaScript for AJAX call to update status if current status is pending
        if ((int) $currentStatusInfo['code'] === self::STATUS_AWAITING_VALIDATION) {

            $urlajax = dol_buildpath('pdpconnectfr/ajax/checkinvoicestatus.php', 1);

            $resprints .= '
            <script type="text/javascript">
            (function() {
                function checkInvoiceStatus() {
					console.log("checkInvoiceStatus Checking invoice status...");
                    // alert("Checking invoice status...");
                    $.get("' . $urlajax . '", {
                        token: "' . currentToken() . '",
                        ref: "' . dol_escape_js($object->ref) . '"
                    }, function (data) {
                        if (!data || typeof data.code === "undefined") {
							console.log("checkInvoiceStatus no data returned");
                            return;
                        }
						console.log(data);

                        // Update UI
                        $("#einvoice-status").html(data.status || "");
                        $("#einvoice-info").html(data.info || "");
                        if (data.info) {
                            $("#einvoice-info-row").show();
                        }

                        // Retry only if still awaiting validation
                        if (parseInt(data.code, 10) === ' . self::STATUS_AWAITING_VALIDATION . ') {
                            setTimeout(checkInvoiceStatus, 5000);
                        }
                    }, "json");
                }

                // First call
				console.log("checkInvoiceStatus Invoice has status pending, so we add a timer to run checkInvoiceStatus in few seconds...");
                setTimeout(checkInvoiceStatus, 2500);

            })();
            </script>';
        }

        return $resprints;
    }

    /**
     * SupplierInvoiceCardBlock
     *
     * @param 	FactureFournisseur $object		FactureFournisseur
     * @return 	string				HTML content to add
     */
    public function SupplierInvoiceCardBlock($object) {
        global $langs;

        $resprints = '';

        // Check if this invoice is present into pdpconnectfr_extlinks table to know if it is an imported object
        $sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."pdpconnectfr_extlinks";
        $sql .= " WHERE element_type = '".$object->element."'";
        $sql .= " AND element_id = ".(int) $object->id;
        $sql .= " LIMIT 1"; 
        $resql = $this->db->query($sql);
        if ($resql && $this->db->num_rows($resql) > 0) {
            // Add block only for imported invoices
            $resprints .= '<tr>';
            $resprints .= '<td>' . $langs->trans("pdpconnectfrSourceTitle") . '</td>';
            $resprints .= '<td>' . $langs->trans("pdpconnectfrSourceDesc") . '</td>';
            $resprints .= '</tr>';
        }

        return $resprints;
    }

    /**
     * ThirdpartyCardBlock
     * @param 	Societe $object		Thirdparty
     * @return 	string				HTML content to add
     */
    public function ThirdpartyCardBlock($object) {
        global $langs;

        $resprints = '';

        // Check if this thirdparty is present into pdpconnectfr_extlinks table to know if it is an imported object
        $sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."pdpconnectfr_extlinks";
        $sql .= " WHERE element_type = '".$object->element."'";
        $sql .= " AND element_id = ".(int) $object->id;
        $sql .= " LIMIT 1";
        $resql = $this->db->query($sql);
        if ($resql && $this->db->num_rows($resql) > 0) {
            // Add block only for imported invoices
            $resprints .= '<tr>';
            $resprints .= '<td>' . $langs->trans("pdpconnectfrSourceTitle") . '</td>';
            $resprints .= '<td>' . $langs->trans("pdpconnectfrSourceDesc") . '</td>';
            $resprints .= '</tr>';
        }

        return $resprints;
    }

    /**
     * ProductServiceCardBlock
     * @param 	Product|Service $object		Product or Service
     * @return 	string				HTML content to add
     */
    public function ProductServiceCardBlock($object) {
        global $langs;

        $resprints = '';

        // Check if this product or service is present into pdpconnectfr_extlinks table to know if it is an imported object
        $sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."pdpconnectfr_extlinks";
        $sql .= " WHERE element_type = '".$object->element."'";
        $sql .= " AND element_id = ".(int) $object->id;
        $sql .= " LIMIT 1";
        $resql = $this->db->query($sql);
        if ($resql && $this->db->num_rows($resql) > 0) {
            // Add block only for imported invoices
            $resprints .= '<tr>';
            $resprints .= '<td>' . $langs->trans("pdpconnectfrSourceTitle") . '</td>';
            $resprints .= '<td>' . $langs->trans("pdpconnectfrSourceDesc") . '</td>';
            $resprints .= '</tr>';
        }

        return $resprints;
    }

    function fetchLastknownInvoiceStatus($invoiceRef) {
        global $db, $conf;

        $status = array('code' => self::STATUS_NOT_GENERATED, 'status' => $this->getStatusLabel(self::STATUS_NOT_GENERATED), 'info' => '', 'file' => '0');

        // Get last status from pdpconnectfr_extlinks table
        $sql = "SELECT syncstatus, synccomment";
        $sql .= " FROM ".MAIN_DB_PREFIX."pdpconnectfr_extlinks";
        $sql .= " WHERE element_type = '".Facture::class."'";
        $sql .= " AND syncref = '".$db->escape($invoiceRef)."'";

        $resql = $db->query($sql);
        if ($resql) {
            if ($db->num_rows($resql) > 0) {
                $obj = $db->fetch_object($resql);
                $status = array(
                    'code' => (int) $obj->syncstatus,
                    'status' => $this->getStatusLabel((int) $obj->syncstatus),
                    'info' => $obj->synccomment ?? '',
                );
            } else {
                dol_syslog("No entry found in pdpconnectfr_extlinks table for invoiceRef: " . $invoiceRef);
            }
        } else {
            dol_print_error($db);
        }

        // Check if there is an e-invoice file generated
        if (getDolGlobalString('PDPCONNECTFR_PROTOCOL') == 'FACTURX') {
            $filename = dol_sanitizeFileName($invoiceRef);
            $filedir = $conf->invoice->multidir_output[$conf->entity].'/'.dol_sanitizeFileName($invoiceRef);
            $pathfacturxpdf = $filedir.'/'.$filename.'_facturx.pdf';
            if (is_readable($pathfacturxpdf)) {
                $status['file'] = '1';
                if ($status['code'] == self::STATUS_NOT_GENERATED) {
                    $status['code'] = self::STATUS_GENERATED;
                    $status['status'] = $this->getStatusLabel(self::STATUS_GENERATED);
                }
            }
        }

        return $status;
    }

    /**
     * Insert or update external link record
     *
     * @param int       $elementId      Linked Element ID
     * @param string    $elementType    Linked Element type
     * @param string    $flowId         Flow ID
     * @param int       $syncStatus     If the object has a status into the einvoice external system
     * @param string    $syncRef        If the object has a given reference into the einvoice external system
     * @param string    $syncComment    If we want to store a message for the last sync action try
     *
     * @return int -1 on error, rowid on success
     */
    public function insertOrUpdateExtLink($elementId, $elementType, $flowId = '', $syncStatus = 0, $syncRef = '', $syncComment = '')
    {
        global $db, $user;

        $provider = getDolGlobalString('PDPCONNECTFR_PDP');

        // Check if record exists
        $sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "pdpconnectfr_extlinks";
        $sql .= " WHERE element_id = " . (int)$elementId;
        $sql .= " AND element_type = '" . $db->escape($elementType) . "'";
        $sql .= " AND provider = '" . $db->escape($provider) . "'";

        $resql = $db->query($sql);
        if (!$resql) {
            dol_print_error($db);
            return -1;
        }

        $exists = $db->num_rows($resql) > 0;

        if ($exists) {
            // Update existing record
            $sql = "UPDATE " . MAIN_DB_PREFIX . "pdpconnectfr_extlinks SET";
            $sql .= " syncstatus = " . (int) $syncStatus;
            $sql .= ", synccomment = '" . $db->escape($syncComment) . "'";
            if (!empty($syncRef)) {
                $sql .= ", syncref = '" . $db->escape($syncRef) . "'";
            }
            if (!empty($flowId)) {
                $sql .= ", flow_id = '" . $db->escape($flowId) . "'";
            }
            $sql .= ", fk_user_modif = " . (int) $user->id;
            $sql .= " WHERE element_id = " . (int) $elementId;
            $sql .= " AND element_type = '" . $db->escape($elementType) . "'";
            $sql .= " AND provider = '" . $db->escape($provider) . "'";
        } else {
            // Insert new record
            $sql = "INSERT INTO " . MAIN_DB_PREFIX . "pdpconnectfr_extlinks";
            $sql .= " (element_id, element_type, provider, date_creation, fk_user_creat, syncstatus, syncref, synccomment, flow_id)";
            $sql .= " VALUES (" . (int)$elementId . ", '" . $db->escape($elementType) . "', '" . $db->escape($provider) . "'";
            $sql .= ", NOW(), " . (int)$user->id . ", " . (int)$syncStatus;
            $sql .= ", " . ($syncRef ? "'" . $db->escape($syncRef) . "'" : "NULL");
            $sql .= ", " . ($syncComment ? "'" . $db->escape($syncComment) . "'" : "NULL");
            $sql .= ", " . ($flowId ? "'" . $db->escape($flowId) . "'" : "NULL") . ")";
        }

        $resql = $db->query($sql);
        if (!$resql) {
            dol_print_error($db);
            return -1;
        }

        return $exists ? 1 : $db->last_insert_id(MAIN_DB_PREFIX."pdpconnectfr_extlinks");
    }


    /**
     * Calculate TVA intracommunity number for a thirdparty if missing
     *
     * @param mixed $thirdparty
     *
     * @return string
     */
    public function thirdpartyCalcTva_intra($thirdparty)
    {
        if ($thirdparty->country_code == 'FR' && empty($thirdparty->tva_intra) && !empty($thirdparty->tva_assuj)) {
            $siren = trim($thirdparty->idprof1);
            if (empty($siren)) {
                $siren = (int) substr(str_replace(' ', '', $thirdparty->idprof2), 0, 9);
            }
            if (!empty($siren)) {
                // [FR + code clé  + numéro SIREN ]
                //Clé TVA = [12 + 3 × (SIREN modulo 97)] modulo 97
                $cle = (12 + 3 * $siren % 97) % 97;
                $tva_intra = 'FR' . $cle . $siren;
            }
        }
        return $tva_intra ?? '';
    }
}