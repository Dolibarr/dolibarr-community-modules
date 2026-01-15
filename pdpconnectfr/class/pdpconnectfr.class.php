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

    public function validateMyCompanyConfiguration()
    {
        global $langs, $mysoc;

        $baseErrors = [];

        if (empty($mysoc->idprof1)) {
            $baseErrors[] = $langs->trans("FxCheckErrorIDPROF1");
        }
        // if (empty($mysoc->tva_intra)) {
        //     $baseErrors[] = $langs->trans("FxCheckErrorVATnumber");
        // }
        // if (empty($mysoc->address)) {
        //     $baseErrors[] = $langs->trans("FxCheckErrorAddress");
        // }
        // if (empty($mysoc->zip)) {
        //     $baseErrors[] = $langs->trans("FxCheckErrorZIP");
        // }
        // if (empty($mysoc->town)) {
        //     $baseErrors[] = $langs->trans("FxCheckErrorTown");
        // }
        // if (empty($mysoc->country_code)) {
        //     $baseErrors[] = $langs->trans("FxCheckErrorCountry");
        // }

        if (!empty($baseErrors)) {
            return ['res' => -1, 'message' => implode('<br>', $baseErrors)];
        }

        return ['res' => 1, 'message' => ''];
    }

    /**
     * Validate thirdparty configuration
     *
     * @param Societe $thirdparty   Thirdparty object
     * @return array{res:int, message:string} Returns array with 'res' (1 on success, -1 on failure) and info 'message'
     */
    public function validatethirdpartyConfiguration($thirdparty)
    {
        global $langs, $mysoc;

        $baseErrors = [];

        if (empty($thirdparty->name)) {
            $baseErrors[] = $langs->trans("FxCheckErrorCustomerName");
        }
        // if (empty($thirdparty->idprof1)) {
        //     $baseErrors[] = $langs->trans("FxCheckErrorCustomerIDPROF1");
        // }
        // if (empty($thirdparty->idprof2)) {
        //     $baseErrors[] = $langs->trans("FxCheckErrorCustomerIDPROF2");
        // }
        // if (empty($thirdparty->address)) {
        //     $baseErrors[] = $langs->trans("FxCheckErrorCustomerAddress");
        // }
        // if (empty($thirdparty->zip)) {
        //     $baseErrors[] = $langs->trans("FxCheckErrorCustomerZIP");
        // }
        // if (empty($thirdparty->town)) {
        //     $baseErrors[] = $langs->trans("FxCheckErrorCustomerTown");
        // }
        // if (empty($thirdparty->country_code)) {
        //     $baseErrors[] = $langs->trans("FxCheckErrorCustomerCountry");
        // }
        // if (empty($thirdparty->tva_intra)) {
        //     $baseErrors[] = $langs->trans("FxCheckErrorCustomerVAT");
        // }
        // if (empty($thirdparty->email)) {
        //     $baseErrors[] = $langs->trans("FxCheckErrorCustomerEmail");
        // }

        if (!empty($baseErrors)) {
            return ['res' => -1, 'message' => implode('<br>', $baseErrors)];
        }

        return ['res' => 1, 'message' => ''];
    }

    /**
     * Check required informations for PDP/PA invoicing
     *
     * @param Societe $soc   Thirdparty object
     *
     * @return array{res:int, message:string} Returns array with 'res' (1 on success, -1 on failure) and info 'message'
     */
    public function checkRequiredinformations($soc) {

        $baseErrors = [];
        $mysocConfigCheck = $this->validateMyCompanyConfiguration();
        if ($mysocConfigCheck['res'] < 0) {
            $baseErrors[] = $mysocConfigCheck['message'];
        }

        $socConfigCheck = $this->validatethirdpartyConfiguration($soc);
        if ($socConfigCheck['res'] < 0) {
            $baseErrors[] = $socConfigCheck['message'];
        }

        if (!empty($baseErrors)) {
            return ['res' => -1, 'message' => implode('<br>', $baseErrors)];
        }
        return ['res' => 1, 'message' => ''];
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
}