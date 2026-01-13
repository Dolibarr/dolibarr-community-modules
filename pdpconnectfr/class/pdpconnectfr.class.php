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

        // TODO for the lastSyncDate excluding the ones with Flow type is "ManualCheck"

        $resprints = '';

        $currentStatusInfo = $this->fetchLastknownInvoiceStatus($object->ref);
		// Force value for test
		//$currentStatusInfo['code'] = 2;

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
        $resprints .= '<tr>';
        $resprints .= '<td class="titlefield">'
            . $langs->trans("pdpconnectfrInvoiceInfo") . '</td>';
        $resprints .= '<td><span id="einvoice-info">'
            . $currentStatusInfo['info'] . '</span></td>';
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

        $sql = "SELECT ack_status, ack_info, cdar_lifecycle_code, cdar_lifecycle_label, cdar_reason_code, cdar_reason_desc, cdar_reason_detail";
        $sql .= " FROM ".MAIN_DB_PREFIX."pdpconnectfr_document";
        $sql .= " WHERE tracking_idref = '".$db->escape($invoiceRef)."'";
        $sql .= " ORDER BY rowid DESC LIMIT 1";

        $resql = $db->query($sql);
        if ($resql) {
            if ($db->num_rows($resql) > 0) {
                $obj = $db->fetch_object($resql);

                switch ($obj->ack_status) {
                    case 'Error':
                        $status = array('code' => self::STATUS_ERROR, 'status' => $this->getStatusLabel(self::STATUS_ERROR), 'info' => $obj->ack_info);
                        break;
                    case 'Pending':
                        $status = array('code' => self::STATUS_AWAITING_VALIDATION, 'status' => $this->getStatusLabel(self::STATUS_AWAITING_VALIDATION), 'info' => '');
                        break;
                    case 'Ok':
                        // Further check lifecycle code for more details
                        if (!empty($obj->cdar_lifecycle_code)) {
                            $statusLabel = $obj->cdar_lifecycle_label;
                            $statusInfo = '';
                            if (!empty($obj->cdar_reason_code)) {
                                $statusInfo .= $obj->cdar_reason_code;
                            }
                            if (!empty($obj->cdar_reason_desc)) {
                                $statusInfo .= "<br>" . $obj->cdar_reason_desc;
                            }
                            if (!empty($obj->cdar_reason_detail)) {
                                $statusInfo .= "<br>" . $obj->cdar_reason_detail;
                            }
                            $status = array('code' => (int) $obj->cdar_lifecycle_code, 'status' => $statusLabel, 'info' => $statusInfo);
                        } else {
                            $status = array('code' => self::STATUS_AWAITING_ACK, 'status' => $this->getStatusLabel(self::STATUS_AWAITING_ACK), 'info' => 'No further lifecycle information.');
                        }
                        break;
                    default:
                        $status['status'] = array('code' => self::STATUS_UNKNOWN, 'status' => 'N/A', 'info' => 'Unknown status retrieved from PDP/PA.');
                }
            } else {
                $status = array('code' => self::STATUS_NOT_GENERATED, 'status' => $this->getStatusLabel(self::STATUS_NOT_GENERATED), 'info' => '');
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
}