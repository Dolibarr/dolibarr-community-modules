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

require_once DOL_DOCUMENT_ROOT.'/core/lib/profid.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/geturl.lib.php';
dol_include_once('pdpconnectfr/lib/pdpconnectfr.lib.php');

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
	public const STATUS_UNKNOWN             = 0;		// By default, before the e-invoice has been generated

	public const STATUS_NOT_GENERATED       = 5;		// To sync
	public const STATUS_GENERATED           = 10;
	public const STATUS_AWAITING_VALIDATION = 15;
	public const STATUS_AWAITING_ACK        = 20;
	public const STATUS_ERROR               = 25;

	public const STATUS_IGNORE              = 99;		// To not sync

	// PDP / PA normalized statuses
	// public const STATUS_DEPOSITED           = 200;
	// public const STATUS_ISSUED              = 201;
	// public const STATUS_RECEIVED            = 202;
	// public const STATUS_AVAILABLE           = 203;
	// public const STATUS_TAKEN_OVER          = 204;
	// public const STATUS_APPROVED            = 205;
	// public const STATUS_PARTIALLY_APPROVED  = 206;
	// public const STATUS_DISPUTED            = 207;
	// public const STATUS_SUSPENDED           = 208;
	// public const STATUS_COMPLETED           = 209;
	// public const STATUS_REFUSED             = 210;
	// public const STATUS_PAYMENT_SENT        = 211;
	// public const STATUS_PAID                = 212;
	// public const STATUS_REJECTED            = 213;


	/**
	 * Invoice deposited
	 * Status sent only by the PDP/PA
	 */
	public const STATUS_DEPOSITED = 200;

	/**
	 * Invoice issued
	 * Status sent only by the PDP/PA
	 */
	public const STATUS_ISSUED = 201;

	/**
	 * Invoice received
	 * Status sent only by the PDP/PA
	 */
	public const STATUS_RECEIVED = 202;

	/**
	 * Invoice made available
	 * Status sent only by the PDP/PA
	 */
	public const STATUS_AVAILABLE = 203;

	/**
	 * Invoice taken over
	 * - Customer invoice: received from the PDP
	 * - Supplier invoice: can be sent by Dolibarr (optional)
	 */
	public const STATUS_TAKEN_OVER = 204;

	/**
	 * Invoice approved
	 * - Customer invoice: received from the PDP
	 * - Supplier invoice: can be sent by Dolibarr (optional)
	 */
	public const STATUS_APPROVED = 205;

	/**
	 * Invoice partially approved
	 * - Customer invoice: received from the PDP
	 * - Supplier invoice: can be sent by Dolibarr (optional)
	 */
	public const STATUS_PARTIALLY_APPROVED = 206;

	/**
	 * Invoice disputed
	 * - Customer invoice: received from the PDP
	 * - Supplier invoice: can be sent by Dolibarr (optional)
	 */
	public const STATUS_DISPUTED = 207;

	/**
	 * Invoice suspended
	 * - Customer invoice: received from the PDP
	 * - Supplier invoice: can be sent by Dolibarr (optional)
	 */
	public const STATUS_SUSPENDED = 208;

	/**
	 * Invoice refused
	 * - Customer invoice: received from the PDP
	 * - Supplier invoice: can be sent by Dolibarr (mandatory)
	 */
	public const STATUS_REFUSED = 210;

	/**
	 * Payment transmitted
	 * - Customer invoice: received from the PDP
	 * - Supplier invoice: can be sent by Dolibarr (optional, recommended)
	 */
	public const STATUS_PAYMENT_SENT = 211;

	/**
	 * Invoice paid
	 * - Customer invoice: can be sent by Dolibarr (optional, recommended)
	 * - Supplier invoice: /
	 */
	public const STATUS_PAID = 212;

	/**
	 * Invoice completed
	 * - Customer invoice: /
	 * - Supplier invoice: /
	 */
	public const STATUS_COMPLETED = 209;

	/**
	 * Invoice rejected (technical)
	 * - Customer invoice: received from the PDP
	 * - Supplier invoice: /
	 */
	public const STATUS_REJECTED = 213;

	/**
	 * List of Einvoice status
	 */
	public const STATUS_LABEL_KEYS = [
		// Dolibarr
		self::STATUS_UNKNOWN             => 'EInvStatusUnknown',
		self::STATUS_IGNORE              => 'EInvStatusDoNotSync',		// To exclude invoice from einvoice sync
		self::STATUS_NOT_GENERATED       => 'EInvStatusNotGenerated',
		self::STATUS_ERROR               => 'EInvStatusError',			// Error in generation by Dolibarr
		self::STATUS_GENERATED           => 'EInvStatusGenerated',
		self::STATUS_AWAITING_VALIDATION => 'EInvStatusAwaitingValidation',
		self::STATUS_AWAITING_ACK        => 'EInvStatusAwaitingAck',

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
		self::STATUS_PAYMENT_SENT        => 'EInvStatus211PaymentTransmitted',
		self::STATUS_PAID                => 'EInvStatus212Paid',
		self::STATUS_REFUSED             => 'EInvStatus210Refused',
		self::STATUS_REJECTED            => 'EInvStatus213Rejected',
	];


	// All reasons with their details (Used when sending supplier invoices status: Refused, Disputed, Suspended, Partially Approved)
	private const REASONS = [
		"NON_TRANSMISE" => [
			"label" => "Recipient not connected",
			"desc" => "This reason is used ONLY with the \"DEPOSITED\" status to indicate that the invoice could not be transmitted because the recipient (BUYER), although present in the PPF Directory, has no active invoice reception address (i.e., connected to an Approved Platform for reception)."
		],
		"JUSTIF_ABS" => [
			"label" => "Missing or insufficient supporting document",
			"desc" => "This reason should be used if attachments required for invoice processing are missing (status 'Suspended'). The issuer must then resubmit the lifecycle with a 'Completed' status, including the missing attachment(s)."
		],
		"ROUTAGE_ERR" => [
			"label" => "Routing error",
			"desc" => "This reason code should be used when the invoice routing information has become obsolete. This may occur, for example, due to a delay in directory updates or an error by the originating Certified Platform. Once the recipient has updated the directory, the invoice can be retransmitted (with no changes to the invoice data)."
		],
		"AUTRE" => [
			"label" => "Other",
			"desc" => "Ce motif nécessite une explication en Note de CDV"
		],
		"COORD_BANC_ERR" => [
			"label" => "Bank coordinates error",
			"desc" => "Les références bancaires sur la facture ne correspondent pas à ce qui est paramétré chez le Payeur / Acheteur"
		],
		"TX_TVA_ERR" => [
			"label" => "Incorrect VAT rate",
			"desc" => "Un taux de TVA utilisé n'est pas celui qui aurait dû"
		],
		"MONTANTTOTAL_ERR" => [
			"label" => "Incorrect Total Amount",
			"desc" => "One of the invoice totals is incorrect, such as the Net Payable amount."
		],
		"CALCUL_ERR" => [
			"label" => "Invoice calculation error",
			"desc" => "Soit détecté au schematron, soit après (pour les lignes, ou arrondi non accepté)"
		],
		"NON_CONFORME" => [
			"label" => "Missing legal mention",
			"desc" => "Toute mention légale non contrôlée"
		],
		"DOUBLON" => [
			"label" => "Duplicate invoice (already issued/received)",
			"desc" => "Facture en doublon (même numéro même fournisseur et même année de la date de facture)"
		],
		"DEST_INC" => [
			"label" => "Unknown recipient",
			"desc" => "A l'émission, le destinataire est inconnu. Il n'existe pas dans l'annuaire."
		],
		"DEST_ERR" => [
			"label" => "Recipient error",
			"desc" => "The recipient legal entity is incorrect (Recipient's SIREN/Registration number). For instance, within a multi-company group, the invoiced company may not be the one that should have been billed."
		],
		"TRANSAC_INC" => [
			"label" => "Unknown transaction",
			"desc" => "La facture ne correspond pas à une livraison effectuée ou une prestation de service livrée."
		],
		"EMMET_INC" => [
			"label" => "Unknown issuer",
			"desc" => "L'émetteur de la facture est inconnu du Destinataire (anti-spam)"
		],
		"CONTRAT_TERM" => [
			"label" => "Contract terminated",
			"desc" => "Contrat terminé, plus de facture possible"
		],
		"DOUBLE_FACT" => [
			"label" => "DOUBLE INVOICE",
			"desc" => "Prestation ou livraison déjà facturé sur une autre facture"
		],
		"CMD_ERR" => [
			"label" => "Incorrect or missing ORDER number",
			"desc" => "Purchase Order (PO) number is incorrect, non-existent, or already invoiced. This reason can only be used with a 'REFUSED' status if the PO number was provided by the BUYER PRIOR TO INVOICING."
		],
		"ADR_ERR" => [
			"label" => "L'adresse de facturation électronique erronée",
			"desc" => "L'adresse de facturation électronique du destinataire (BT-49 ou BT-34) est absente ou erronée"
		],
		"SIRET_ERR" => [
			"label" => "Incorrect or missing SIRET",
			"desc" => "Le SIRET du destinataire est erroné ou absent si exigé"
		],
		"CODE_ROUTAGE_ERR" => [
			"label" => "Missing or Incorrect ROUTING CODE",
			"desc" => "Le CODE_ROUTAGE du destinataire est erroné ou absent si exigé"
		],
		"REF_CT_ABSENT" => [
			"label" => "Missing contractual reference required for invoice processing",
			"desc" => "A contractually required reference is missing (list to be defined) and must be identified in the Lifecycle: BT-12 (Contract Reference), Delivery Note No. (BT-16), Buyer Reference (BT-10), Invoiced Object (BT-18), Project Reference (BT-11), Preceding Invoice (BG-3), etc."
		],
		"REF_ERR" => [
			"label" => "Incorrect reference",
			"desc" => "A préciser dans les autres données du CDV de quelle référence il s'agit"
		],
		"PU_ERR" => [
			"label" => "Incorrect Unit Prices",
			"desc" => "Un prix Unitaire n'est pas celui attendu"
		],
		"REM_ERR" => [
			"label" => "Incorrect discount",
			"desc" => "A discount is missing or does not match the expected value"
		],
		"QTE_ERR" => [
			"label" => "Incorrect billed quantity",
			"desc" => "Invoiced quantity does not match the expected quantity."
		],
		"ART_ERR" => [
			"label" => "Incorrect billed article",
			"desc" => "Un article facturé n'est pas le bon ou est erroné"
		],
		"MODPAI_ERR" => [
			"label" => "Incorrect payment terms",
			"desc" => "The payment terms (due date, for example) do not match the expected terms."
		],
		"QUALITE_ERR" => [
			"label" => "Qualité d'article livré incorrecte",
			"desc" => "Un des articles livré est défectueux"
		],
		"LIVR_INCOMP" => [
			"label" => "Delivery issue",
			"desc" => "Livraison incomplète, non conforme"
		],
		"REJ_SEMAN" => [
			"label" => "Rejection for semantic error",
			"desc" => "Analyse du format sémantique"
		],
		"REJ_UNI" => [
			"label" => "Rejection on uniqueness control",
			"desc" => "Contrôle d'unicité"
		],
		"REJ_COH" => [
			"label" => "Rejection on data consistency control",
			"desc" => "Contrôle cohérence de données (les balises et les référentiels)"
		],
		"REJ_ADR" => [
			"label" => "Rejet sur Contrôle d'adressage",
			"desc" => "Contrôle d'adressage"
		],
		"REJ_CONT_B2G" => [
			"label" => "Rejection on B2G Business Controls",
			"desc" => "Contrôles B2G (vérification du n° d'engagement…)"
		],
		"REJ_REF_PJ" => [
			"label" => "Rejection on Attachment Reference",
			"desc" => "Référence de PJ"
		],
		"REJ_ASS_PJ" => [
			"label" => "Rejet sur Erreur d'association de la PJ",
			"desc" => "Erreur d'association de la PJ"
		],
		"IRR_VIDE_F" => [
			"label" => "Non-empty control on flow files",
			"desc" => "Non-empty control on flow files"
		],
		"IRR_TYPE_F" => [
			"label" => "Control of type and extension of flow files",
			"desc" => "Control of type and extension of flow files"
		],
		"IRR_SYNTAX" => [
			"label" => "Syntax control of flow files",
			"desc" => "Syntax control of flow files"
		],
		"IRR_TAILLE_PJ" => [
			"label" => "Control of size of attachments in each flow file",
			"desc" => "Control of size of attachments in each flow file"
		],
		"IRR_NOM_PJ" => [
			"label" => "Control of attachment names in each flow file (absence of forbidden characters)",
			"desc" => "Control of attachment names in each flow file (absence of forbidden characters)"
		],
		"IRR_VID_PJ" => [
			"label" => "Control of non-empty attachment in each flow file",
			"desc" => "Control of non-empty attachment in each flow file"
		],
		"IRR_EXT_DOC" => [
			"label" => "Contrôle de l'extension des PJ de chaque fichier du flux",
			"desc" => "Contrôle de l'extension des PJ de chaque fichier du flux"
		],
		"IRR_TAILLE_F" => [
			"label" => "Control of max size of files contained in the flow",
			"desc" => "Control of max size of files contained in the flow"
		],
		"IRR_ANTIVIRUS" => [
			"label" => "Antivirus control",
			"desc" => "Le flux ne respecte pas les conditions de sécurité"
		]
	];

	// Codes reasons by status
	private const REASONS_CODE_FOR_STATUS = [
		self::STATUS_DISPUTED => [
			"AUTRE",
			"COORD_BANC_ERR",
			"TX_TVA_ERR",
			"MONTANTTOTAL_ERR",
			"CALCUL_ERR",
			"NON_CONFORME",
			"DOUBLON",
			"DEST_ERR",
			"TRANSAC_INC",
			"EMMET_INC",
			"CONTRAT_TERM",
			"DOUBLE_FACT",
			"CMD_ERR",
			"ADR_ERR",
			"SIRET_ERR",
			"CODE_ROUTAGE_ERR",
			"REF_CT_ABSENT",
			"REF_ERR",
			"PU_ERR",
			"REM_ERR",
			"QTE_ERR",
			"ART_ERR",
			"MODPAI_ERR",
			"QUALITE_ERR",
			"LIVR_INCOMP"
		],

		self::STATUS_REFUSED => [
			"TX_TVA_ERR",
			"MONTANTTOTAL_ERR",
			"CALCUL_ERR",
			"NON_CONFORME",
			"DOUBLON",
			"DEST_ERR",
			"TRANSAC_INC",
			"EMMET_INC",
			"CONTRAT_TERM",
			"DOUBLE_FACT",
			"CMD_ERR",
			"ADR_ERR",
			"REF_CT_ABSENT"
		],

		self::STATUS_PARTIALLY_APPROVED => [
			"COORD_BANC_ERR",
			"CMD_ERR",
			"SIRET_ERR",
			"CODE_ROUTAGE_ERR",
			"REF_CT_ABSENT",
			"REF_ERR"
		],

		self::STATUS_SUSPENDED => [
			"JUSTIF_ABS",
			"ROUTAGE_ERR",
			"CMD_ERR"
		]
	];

	public const STATUS_REQUIRING_REASONS = [
		self::STATUS_REFUSED,
		self::STATUS_DISPUTED,
		self::STATUS_PARTIALLY_APPROVED,
		self::STATUS_SUSPENDED
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
	 * Build an e-invoice payload.
	 *
	 * This method:
	 *  1. Extracts all relevant invoice data required for electronic invoicing.
	 *  2. Return an structured array that can be used by specific format generation methods (Factur-X, UBL, CII...) to generate the final e-invoice file to send to PDP/PA.
	 *  3. Computes an integrity hash of the payload for later verification in triggers (e.g. BILL_UPDATE) to prevent unauthorized modifications after sending to PDP/PA.
	 *
	 * @param Facture $invoice Fully loaded Dolibarr invoice object
	 * @return array Normalized e-invoice payload
	 */
	public function buildEInvoicePayloadFromInvoice($invoice): array
	{
		if (empty($invoice->id)) {
			return array(
				'payload' => array(),
				'integrity_hash' => ''
			);
		}

		$payload = array();
		// TODO : Complete and use this payload structure with methods to generate e-invoicing (Factur-X, UBL, CII...) instead of fetching data separately in each method for each format.
		// $payload = array(
		//     'header' => array(
		//         'invoice_number'   => $invoice->ref,
		//         'invoice_date'     => $invoice->date,
		//         'due_date'         => $invoice->date_lim_reglement,
		//         'currency'         => $invoice->multicurrency_code ?: $conf->currency,
		//         'total_ht'         => $invoice->total_ht,
		//         'total_vat'        => $invoice->total_tva,
		//         'total_ttc'        => $invoice->total_ttc,
		//         'payment_terms_id' => $invoice->cond_reglement_id,
		//         'payment_mode_id'  => $invoice->mode_reglement_id,
		//     ),
		//     'seller' => array(
		//         'name'        => $conf->global->MAIN_INFO_SOCIETE_NOM,
		//         'vat_number'  => $conf->global->MAIN_INFO_SIREN,
		//         'address'     => $conf->global->MAIN_INFO_SOCIETE_ADDRESS,
		//         'zip'         => $conf->global->MAIN_INFO_SOCIETE_ZIP,
		//         'town'        => $conf->global->MAIN_INFO_SOCIETE_TOWN,
		//         'country'     => $conf->global->MAIN_INFO_SOCIETE_COUNTRY,
		//     ),
		//     'buyer' => array(
		//         'name'        => $invoice->thirdparty->name,
		//         'vat_number'  => $invoice->thirdparty->tva_intra,
		//         'address'     => $invoice->thirdparty->address,
		//         'zip'         => $invoice->thirdparty->zip,
		//         'town'        => $invoice->thirdparty->town,
		//         'country'     => $invoice->thirdparty->country_code,
		//     ),
		//     'lines' => array()
		// );

		// // Extract invoice lines
		// foreach ($invoice->lines as $line) {
		//     $payload['lines'][] = array(
		//         'description' => $line->desc,
		//         'product_ref' => $line->ref,
		//         'qty'         => $line->qty,
		//         'unit_price'  => $line->subprice,
		//         'vat_rate'    => $line->tva_tx,
		//         'total_ht'    => $line->total_ht,
		//         'total_ttc'   => $line->total_ttc,
		//     );
		// }


		// TODO : Store payload and integrity hash in a dedicated table linked to invoice when sending to PDP/PA, and use it in BILL_UPDATE trigger to check integrity and block modifications on locked fields.
		$integrityHash = '';
		// $integrityHash = hash('sha256', json_encode($payload));

		return array(
			'payload'        => $payload,
			'integrity_hash' => $integrityHash
		);
	}



	/**
	 * Check Module prerequisites
	 *
	 * @return int Returns 1 if ok, -1 if not
	 */
	public function checkModulePrerequisites()
	{
		// Check if required module 'pdpconnectfr' is enabled
		if (!isModEnabled("pdpconnectfr")) {
			return -1;
		}

		if (!getDolGlobalString('PDPCONNECTFR_PDP')) {
			return -1;
		}

		if (!getDolGlobalString('PDPCONNECTFR_PROTOCOL')) {
			return -1;
		}

		return 1;
	}

	/**
	 * Return label for an e-invoice status code
	 *
	 * @param int|string 	$code		Code
	 * @return string					Label
	 */
	public function getStatusLabel($code)
	{
		global $langs;

		$code = (int) $code;

		return $langs->transnoentitiesnoconv(
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
	 * @param int $includeCodesInLabel 	0 to not include codes in label, 1 to include codes in label
	 * @param int $onlyPdpStatuses 		If 1, only return PDP/PA statuses (exclude Dolibarr internal statuses)
	 * @param int $onlySendable 		If 1, only return statuses that can be sent to Access Point (for example, exclude Access Point STATUS_ERROR)
	 * @param int $onlyCreate			Keep only status used in create mode
	 * @param int $onlyOut				Keep only status used for outgoing invoices
	 * @param int $addseparator			If 1, add decorators like a separator after status when einvoice life cycle has not started.
	 * @return array<int, string>		Array of status
	 */
	public function getEinvoiceStatusOptions($includeCodesInLabel = 0, $onlyPdpStatuses = 0, $onlySendable = 0, $onlyCreate = 0, $onlyOut = 0, $addseparator = 0)
	{
		global $langs;
		$options = [];
		foreach (self::STATUS_LABEL_KEYS as $code => $labelKey) {
			if ($code == self::STATUS_GENERATED) {
				$options['separator1'] = array('label' => '--------------------', 'disabled' => 1);
			}

			$value = $langs->trans($labelKey);
			if ($includeCodesInLabel === 1) {
				$value = '(' . $code . ') ' . $value;
			}
			$options[$code] = $value;

			if ($code == self::STATUS_PAID) {
				$options['separator2'] = array('label' => '--------------------', 'disabled' => 1);
			}
		}

		if ($onlyPdpStatuses || $onlySendable) {
			// Remove Dolibarr internal statuses
			unset($options[self::STATUS_UNKNOWN]);
			unset($options[self::STATUS_IGNORE]);
			unset($options[self::STATUS_NOT_GENERATED]);
		}
		if ($onlyPdpStatuses || $onlySendable || $onlyCreate) {
			unset($options[self::STATUS_GENERATED]);
			unset($options[self::STATUS_AWAITING_VALIDATION]);
			unset($options[self::STATUS_AWAITING_ACK]);
			unset($options[self::STATUS_ERROR]);					// Error in generation by Dolibarr
		}

		if ($onlySendable || $onlyCreate) {
			// Remove PDP/PA statuses that cannot be sent
			unset($options[self::STATUS_DEPOSITED]);
			unset($options[self::STATUS_ISSUED]);
			unset($options[self::STATUS_RECEIVED]);
			unset($options[self::STATUS_AVAILABLE]);
			unset($options[self::STATUS_COMPLETED]);
			unset($options[self::STATUS_REJECTED]);
			unset($options[self::STATUS_PAID]);

			// Remove statuses that are not supported for now.
			unset($options[self::STATUS_TAKEN_OVER]);
			unset($options[self::STATUS_DISPUTED]);
			unset($options[self::STATUS_PARTIALLY_APPROVED]);
			unset($options[self::STATUS_SUSPENDED]);
			unset($options[self::STATUS_PAYMENT_SENT]);
		}

		if ($onlyOut) {
			unset($options[self::STATUS_AVAILABLE]);
		}

		if ($onlyCreate) {
			unset($options[self::STATUS_APPROVED]);
			unset($options[self::STATUS_REFUSED]);
		}

		// TODO : remove statuses that cannot be chronologically be sent (for example, it doesn't make sense to send "Taken over" if invoice is refused), PDP may accept them and ignore them without returning an error.


		return $options;
	}

	/**
	 * Get reasons for a given status that will be used when sending supplier invoice status updates to PDP/PA (for statuses Refused, Disputed, Partially Approved, Suspended)
	 *
	 * @param int $statut		Status ID
	 * @param int $withDetails 	Return also desc if 1
	 * @return array<string, array{code:string, label:string, desc:string}>|null
	 */
	public function getRaisonsByStatus($statut, $withDetails = 1)
	{
		if (!isset(self::REASONS_CODE_FOR_STATUS[$statut])) {
			return null;
		}

		$reasons = [];
		foreach (self::REASONS_CODE_FOR_STATUS[$statut] as $code) {
			if (isset(self::REASONS[$code])) {
				$reasons[$code] = [
					'code' => $code,
					'label' => self::REASONS[$code]['label']
				];
				if ($withDetails === 1) {
					$reasons[$code]['desc'] = self::REASONS[$code]['desc'];
				}
			}
		}

		return $reasons;
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

		$einvoiceid = $this->getSellerCommunicationURI();

		// Error message if we failed to found the einvoiceid
		if (empty($einvoiceid)) {
			if (empty($mysoc->idprof1)) {
				$baseErrors[] = $langs->trans("FxCheckErrorIDPROF1");
			} else {
				if ($mysoc->country_code == 'FR') {
					// Get seller Einvoice ID
					$provider = getDolGlobalString('PDPCONNECTFR_PDP');

					$uriConf = 'PDPCONNECTFR_' . strtoupper($provider) . '_ROUTING_ID';
					$einvoiceid = getDolGlobalString($uriConf);
					if (!preg_match('/^'.preg_replace('/\s+/', '', $mysoc->idprof1).'/', $this->removeSpaces($einvoiceid))) {
						$baseWarnings[] = $langs->trans("FxCheckErrorRoutingIDFR", $einvoiceid);
					} else {
						$baseErrors[] = $langs->trans("FxCheckErrorRoutingID");
					}
				} else {
					$baseErrors[] = $langs->trans("FxCheckErrorRoutingID");
				}
			}
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
			$message .= '<b>'.$langs->trans("Warning").'</b>: ' . implode('<br><b>'.$langs->trans("Warning").'</b>: ', $baseWarnings);
		}
		if (!empty($baseErrors)) {
			$res = -1;
			$message .= '<b>'.$langs->trans("Error").'</b>: ' . implode('<br><b>'.$langs->trans("Error").'</b>: ', $baseErrors);
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
		global $langs;

		$res = 1;
		$message = '';
		$baseErrors = [];
		$baseWarnings = [];

		if (empty($thirdparty->name)) {
			$baseErrors[] = $langs->trans("FxCheckErrorCustomerName");
		}
		if (empty($thirdparty->idprof1)) {
			$baseErrors[] = $langs->trans("FxCheckErrorCustomerIDPROF1");
		} elseif (!empty($thirdparty->country_code) && $thirdparty->country_code === 'FR') {
			// Validate SIREN/SIRET format based on length (French companies only)
			$idprof1 = preg_replace('/\s+/', '', (string) $thirdparty->idprof1);
			if (strlen($idprof1) === 14) {
				if (!isValidSiret($idprof1)) {
					$baseWarnings[] = $langs->trans("FxCheckErrorCustomerSIRETFormat");
				}
			} elseif (strlen($idprof1) === 9) {
				if (!isValidSiren($idprof1)) {
					$baseWarnings[] = $langs->trans("FxCheckErrorCustomerSIRENFormat");
				}
			} else {
				$baseWarnings[] = $langs->trans("FxCheckErrorCustomerSIRETLength");
			}
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
			$baseErrors[] = $langs->trans("FxCheckErrorCustomerCountry");
		}
		// Check routing_id
		$routing_id = $this->getBuyerCommunicationURI($thirdparty);
		// If PDPCONNECTFR_BLOCK_INVOICE_NO_ROUTING_ID is off, we use the profid as einvoice id and we already have the previous error message of
		// profid missing. But if on, we also add a message dedicated to einvoice ID.
		if (getDolGlobalString('PDPCONNECTFR_BLOCK_INVOICE_NO_ROUTING_ID') && empty($routing_id)) {
			$baseErrors[] = $langs->trans("FxCheckErrorCustomerRoutingID");
		}
		if ($thirdparty->tva_assuj && empty($thirdparty->tva_intra)) {
			// Test VAT code only if thirdparty is subject to VAT
			$baseWarnings[] = $langs->trans("FxCheckErrorCustomerVAT");
		} elseif ($thirdparty->tva_assuj && !empty($thirdparty->tva_intra) && !empty($thirdparty->country_code) && $thirdparty->country_code === 'FR') {
			// Validate French intra-community VAT number format: FR + 2 alphanumeric characters + 9 digits (SIREN)
			$vatNormalized = strtoupper(preg_replace('/\s+/', '', $thirdparty->tva_intra));
			if (!preg_match('/^FR[0-9A-Z]{2}[0-9]{9}$/', $vatNormalized)) {
				$baseWarnings[] = $langs->trans("FxCheckErrorCustomerVATFormat");
			} elseif (!empty($thirdparty->idprof1)) {
				// Cross-check VAT against SIREN: French VAT key is deterministic (formula: (12 + 3 * (SIREN % 97)) % 97)
				$siren9 = substr(preg_replace('/\s+/', '', $thirdparty->idprof1), 0, 9);
				if (ctype_digit($siren9) && strlen($siren9) === 9) {
					$expectedKey = (12 + 3 * ((int) $siren9 % 97)) % 97;
					$expectedVAT = 'FR' . str_pad((string) $expectedKey, 2, '0', STR_PAD_LEFT) . $siren9;
					if ($vatNormalized !== $expectedVAT) {
						$baseWarnings[] = $langs->trans("FxCheckErrorCustomerVATMismatch", $thirdparty->tva_intra, $expectedVAT);
					}
				}
			}
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
	 * Validate chorus specific information
	 *
	 * @param Facture $object   Invoice object
	 *
	 * @return array{res:int, message:string} Returns array with 'res' (1 on success, -1 on error and 0 on warning) and info 'message'
	 */
	public function validateChorusInformations($object)
	{
		// TODO add a field into pdpconnectfr_extlinks table to define if this invoice is for chorus or not and all chorus specific fields and then replace use of extrafields
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
	 * Check the thirdparty existence and active status via the French National Business Registry API (data.gouv.fr).
	 * Search is performed by company name; the returned SIREN is then cross-checked against idprof1.
	 * No authentication required. API rate limit: 7 req/s.
	 *
	 * This check is optional and non-blocking: an API timeout or unavailability is
	 * silently ignored (warning logged, no error raised to the user).
	 * Only runs when PDPCONNECTFR_ENABLE_API_VALIDATION constant is set to 1.
	 *
	 * @param Societe $thirdparty   Thirdparty object to check
	 * @return array{res:int, message:string} res=1 OK, res=0 warning, res=-1 blocking error (never returned by this method)
	 */
	private function _checkThirdpartyViaExternalAPIs($thirdparty)
	{
		global $langs;

		$warnings = [];

		// Check company via the French National Business Registry API (data.gouv.fr)
		// Search by company name, then cross-check the returned SIREN against idprof1
		if (!empty($thirdparty->country_code) && $thirdparty->country_code === 'FR'
			&& !empty($thirdparty->name) && !empty($thirdparty->idprof1)) {
			$siren = substr(preg_replace('/\s+/', '', $thirdparty->idprof1), 0, 9);
			$apiUrl = 'https://recherche-entreprises.api.gouv.fr/search?q=' . urlencode($thirdparty->name) . '&per_page=5';

			$response = getURLContent($apiUrl, 'GET', '', 1, ['Accept: application/json']);

			if ($response['http_code'] !== 200) {
				// API unreachable: log and continue without blocking
				dol_syslog(get_class($this) . '::_checkThirdpartyViaExternalAPIs business registry API unreachable (HTTP ' . $response['http_code'] . ')', LOG_WARNING);
			} else {
				$data = json_decode($response['content'], true);
				$matchedCompany = null;

				// Look for a result whose SIREN matches the one stored in Dolibarr
				if (!empty($data['results']) && is_array($data['results'])) {
					foreach ($data['results'] as $result) {
						if (isset($result['siren']) && $result['siren'] === $siren) {
							$matchedCompany = $result;
							break;
						}
					}
				}

				if ($matchedCompany === null) {
					// No result matched the name + SIREN combination
					$warnings[] = $langs->trans("FxCheckWarnSIRENNotFound", $siren, $thirdparty->name);
				} else {
					// Check that the matched company is not closed
					if (isset($matchedCompany['etat_administratif']) && $matchedCompany['etat_administratif'] !== 'A') {
						$warnings[] = $langs->trans("FxCheckWarnSIRENClosed", $siren);
					}

					// Cross-check company name (partial match to handle legal form suffixes and abbreviations)
					$nomApi      = strtolower(preg_replace('/[^a-z0-9]/i', '', $matchedCompany['nom_complet'] ?? ''));
					$nomDolibarr = strtolower(preg_replace('/[^a-z0-9]/i', '', $thirdparty->name));
					if (!empty($nomApi) && !empty($nomDolibarr)
						&& strpos($nomApi, $nomDolibarr) === false
						&& strpos($nomDolibarr, $nomApi) === false) {
						$warnings[] = $langs->trans("FxCheckWarnNameMismatch", $thirdparty->name, $matchedCompany['nom_complet']);
					}

					// Cross-check ZIP code (objective field, no formatting ambiguity)
					$zipApi      = trim($matchedCompany['siege']['code_postal'] ?? '');
					$zipDolibarr = trim($thirdparty->zip ?? '');
					if (!empty($zipApi) && !empty($zipDolibarr) && $zipApi !== $zipDolibarr) {
						$warnings[] = $langs->trans("FxCheckWarnZIPMismatch", $zipDolibarr, $zipApi);
					}

					// Cross-check town (case-insensitive, strip accents for robustness)
					$townApi      = strtolower(trim($matchedCompany['siege']['libelle_commune'] ?? ''));
					$townDolibarr = strtolower(trim($thirdparty->town ?? ''));
					if (!empty($townApi) && !empty($townDolibarr) && $townApi !== $townDolibarr) {
						$warnings[] = $langs->trans("FxCheckWarnTownMismatch", $thirdparty->town, $matchedCompany['siege']['libelle_commune']);
					}
				}
			}
		}

		if (!empty($warnings)) {
			return ['res' => 0, 'message' => implode('<br> Warning API: ', $warnings)];
		}
		return ['res' => 1, 'message' => ''];
	}

	/**
	 * Validate invoice-level configuration for E-Invoicing.
	 * Checks constraints specific to the invoice itself (type, linked documents...).
	 *
	 * @param Facture $invoice   Invoice object
	 * @return array{res:int, message:string} Returns array with 'res' (1 on success, -1 on error and 0 on warning) and info 'message'
	 */
	public function validateInvoiceConfiguration($invoice)
	{
		global $langs;

		$res = 1;
		$message = '';
		$baseErrors = [];

		// Credit note: BT-25 (InvoiceReferencedDocument) is mandatory per EN16931
		// The source invoice reference must be set in fk_facture_source
		if ($invoice->type == $invoice::TYPE_CREDIT_NOTE) {
			if (empty($invoice->fk_facture_source)) {
				$baseErrors[] = $langs->trans("FxCheckErrorCreditNoteNoSource");
			}
		}

		if (!empty($baseErrors)) {
			$res = -1;
			$message .= '<br> Error: ' . implode('<br> Error: ', $baseErrors);
		}

		return ['res' => $res, 'message' => $message];
	}

	/**
	 * Check required information for E-Invoicing
	 *
	 * @param Facture 	$invoice   Invoice object
	 * @return array{res:int, message:string} Returns array with 'res' (1 on success, -1 on failure and 0 on warning) and info 'message'
	 */
	public function checkRequiredinformations($invoice)
	{
		$messages = [];
		$mysocConfigCheck    = $this->validateMyCompanyConfiguration();
		$socConfigCheck      = $this->validatethirdpartyConfiguration($invoice->thirdparty);
		$invoiceConfigCheck  = $this->validateInvoiceConfiguration($invoice);
		$chorusConfigCheck   = null;
		if (getDolGlobalInt('PDPCONNECTFR_USE_CHORUS')) {
			$chorusConfigCheck = $this->validateChorusInformations($invoice);
		}

		// External API checks (optional, enabled by PDPCONNECTFR_ENABLE_API_VALIDATION)
		$apiConfigCheck = null;
		if (getDolGlobalInt('PDPCONNECTFR_ENABLE_API_VALIDATION') && $socConfigCheck['res'] >= 0) {
			// Only call external APIs if format checks already passed (avoids unnecessary requests)
			$apiConfigCheck = $this->_checkThirdpartyViaExternalAPIs($invoice->thirdparty);
		}

		if (!empty($mysocConfigCheck['message'])) {
			$messages[] = $mysocConfigCheck['message'];
		}
		if (!empty($socConfigCheck['message'])) {
			$messages[] = $socConfigCheck['message'];
		}
		if (!empty($invoiceConfigCheck['message'])) {
			$messages[] = $invoiceConfigCheck['message'];
		}
		if (!empty($chorusConfigCheck['message'])) {
			$messages[] = $chorusConfigCheck['message'];
		}
		if (!empty($apiConfigCheck['message'])) {
			$messages[] = $apiConfigCheck['message'];
		}

		$res = 1;
		if ($mysocConfigCheck['res'] === -1 || $socConfigCheck['res'] === -1
			|| $invoiceConfigCheck['res'] === -1
			|| (isset($chorusConfigCheck) && $chorusConfigCheck['res'] === -1)) {
			$res = -1;
		} elseif ($mysocConfigCheck['res'] === 0 || $socConfigCheck['res'] === 0
			|| $invoiceConfigCheck['res'] === 0
			|| (isset($chorusConfigCheck) && $chorusConfigCheck['res'] === 0)
			|| (isset($apiConfigCheck) && $apiConfigCheck['res'] === 0)) {
			$res = 0;
		}

		$message = implode('<br>', $messages);

		return ['res' => $res, 'message' => $message];
	}

	/**
	 * EInvoiceCardBlock
	 *
	 * @param 	Facture 			$object					Facture
	 * @param	string				$mode					'create', 'view'
	 * @return 	string				HTML content to add
	 */
	public function EInvoiceCardBlock($object, $mode = '')
	{
		global $langs, $form, $user;
		global $action;

		$currentStatusInfo = $this->fetchLastknownInvoiceStatus($object->id, $object->ref);
		// Force value for test
		//$currentStatusInfo['code'] = 2;

		$resprints = '';

		// Set $extrafield_collapse_display_value (do we have to collapse/expand the group after the separator)
		$extrafield_collapse_display_value = -1;
		$expand_display = ((isset($_COOKIE['DOLUSER_COLLAPSE_facture_trpdpconnectseparator']) || GETPOSTINT('ignorecollapsesetup')) ? (!empty($_COOKIE['DOLUSER_COLLAPSE_facture_trpdpconnectseparator'])) : !($extrafield_collapse_display_value == 2));
		$disabledcookiewrite = 0;
		if ($mode == 'create') {
			// On create mode, force separator group to not be collapsible
			$extrafield_collapse_display_value = 1;
			$expand_display = true; // We force group to be shown expanded
			$disabledcookiewrite = 1; // We keep status of group unchanged into the cookie
		}
		$resprints .= '<!-- EInvoiceCardBlock -->
			<script nonce="" type="text/javascript">
			jQuery(document).ready(function() {';
		if (empty($disabledcookiewrite)) {
			if (!$expand_display) {
				$resprints .= 'console.log("Inject js for the collapsing of trpdpconnect_collapseseparator - hide");
						jQuery(".trpdpconnect_collapseseparator").hide();';
			} else {
				$resprints .= 'console.log("Inject js for collapsing of trpdpconnect_collapseseparator - keep visible and set cookie");
						document.cookie = "DOLUSER_COLLAPSE_facture_trpdpconnectseparator=1; path=' . $_SERVER["PHP_SELF"] . '";';
			}
		}
		$resprints .= 'jQuery("#trpdpconnect").click(function(){
			       console.log("We click on collapse/uncollapse to hide/show .trpdpconnectseparator");
			       jQuery(".trpdpconnect_collapseseparator").toggle(100, function(){
			           if (jQuery(".trpdpconnect_collapseseparator").is(":hidden")) {
			               jQuery("#trpdpconnect td span").addClass("fa-plus-square").removeClass("fa-minus-square");
			               document.cookie = "DOLUSER_COLLAPSE_facture_trpdpconnectseparator=0; path=' . $_SERVER["PHP_SELF"] . '"
			           } else {
			               jQuery("#trpdpconnect td span").addClass("fa-minus-square").removeClass("fa-plus-square");
			               document.cookie = "DOLUSER_COLLAPSE_facture_trpdpconnectseparator=1; path=' . $_SERVER["PHP_SELF"] . '"
			           }
			       });
			   });
			});
			</script>';

		// Title separator
		$resprints .= '<tr id="trpdpconnect" class="trpdpconnectseparator trtrpdpconnectseparator_1">';
		$resprints .= '<td><span class="far fa-'.(($expand_display ? 'minus' : 'plus').'-square').'"></span><strong> ' . $langs->trans("EInvoicing") . '</strong></td>';
		if ($object->element == 'facture' || $object->element == 'invoice') {
			$url = DOL_URL_ROOT.'/compta/facture/agenda.php?id=' . urlencode($object->id) . '&search_agenda_label=PDPCONNECTFR';
		} else {
			$url = DOL_URL_ROOT.'/fourn/facture/agenda.php?id=' . urlencode($object->id) . '&search_agenda_label=PDPCONNECTFR';
		}
		$langs->load("suppliers");
		$resprints .= '<td>';
		if ($action != 'create') {
			$resprints .= '<a href="' . $url . '">' . $langs->trans("History") . '<i class="marginleftonly fas fa-calendar-alt infobox-action"></i></a>';
		}
		$resprints .= '</td>';
		$resprints .= '</tr>';

		$info = $currentStatusInfo['info'] ?? '';

		$editenable = $user->hasRight('facture', 'creer');
		if (method_exists($object, 'isEditable') && !$object->isEditable()) {
			$editenable = false;
		}
		if ($action == 'create') {
			$editenable = false;
		}

		// Access Point Status + Field for real time update info
		$resprints .= '<tr class="trpdpconnect_collapseseparator">';
		$resprints .= '<td class="">';
		$resprints .= $form->editfieldkey($form->textwithpicto($langs->trans("pdpconnectfrInvoiceStatus"), $langs->transnoentitiesnoconv("einvoiceStatusFieldHelp")), 'einvoicestatus', '', $object, (int) $editenable);
		/*$resprints .= $langs->trans("pdpconnectfrInvoiceStatus");
		$resprints .= ' <i class="fas fa-info-circle em088 opacityhigh classfortooltip" title="';
		$resprints .= $langs->trans("einvoiceStatusFieldHelp") . '"></i>';*/
		$resprints .= '</td>';
		$resprints .= '<td>';
		if ($action == 'editeinvoicestatus' || $action == 'create') {
			if ($action != 'create') {
				$resprints .=  '<form name="seteinvoicestatus" action="' . $_SERVER["PHP_SELF"] . '?id=' . $object->id . '" method="post">';
				$resprints .=  '<input type="hidden" name="token" value="' . newToken() . '">';
				$resprints .=  '<input type="hidden" name="action" value="seteinvoicestatus">';
				$resprints .=  '<input type="hidden" name="page_y" value="page_y">';
				//$resprints .=  '<input type="hidden" name="backtopage" value="' . $backtopage . '">';
			}

			// TODO Use a combo list with only status for sync Dolibarr -> AP
			// Also status we can't modify manually must be greyed/disabled
			$arrayofeinvoicestatus = $this->getEinvoiceStatusOptions(0, 0, 0, ($action == 'create' ? 1 : 0));

			$resprints .=  $form->selectarray("seteinvoicestatus", $arrayofeinvoicestatus, $currentStatusInfo['code'], 0, 0, 0, '', 1);
			if ($action != 'create') {
				$resprints .=  '<input type="submit" class="button button-edit smallpaddingimp reposition" value="' . $langs->trans('Modify') . '">';
				$resprints .=  '</form>';
			}
		} else {
			$resprints .= '<span id="einvoice-status">';
			if ($currentStatusInfo['code'] == self::STATUS_NOT_GENERATED) {
				$resprints .= '<span class="opacitymedium">'.$currentStatusInfo['status'].'</span>';
			} else {
				$resprints .= $currentStatusInfo['status'];
			}
			$resprints .= '</span><br>';
			$resprints .= '<span id="einvoice-info" class="clearboth small opacitymedium">' . dolPrintHTML($info) . '</span>';
		}
		$resprints .= '</td>';
		$resprints .= '</tr>';

		// Invoice-level routing ID override (BT-49)
		if ($object->element == 'facture' || $object->element == 'invoice') {
			$currentOverrideRouting = $currentStatusInfo['override_routing_id'] ?? '';
			$allRoutings = $this->fetchAllRoutings($object->socid);
			if (is_array($allRoutings) && count($allRoutings) >= 1) {
				$resprints .= '<tr class="trpdpconnect_collapseseparator">';
				$resprints .= '<td>';
				$resprints .= $form->editfieldkey($langs->trans("InvoiceRoutingOverride"), 'override_routing_id', '', $object, (int) $editenable);
				$resprints .= '</td>';
				$resprints .= '<td>';
				if ($action == 'editoverride_routing_id') {
					$resprints .= '<form name="setoverrriderouting" action="' . $_SERVER["PHP_SELF"] . '?id=' . $object->id . '" method="post">';
					$resprints .= '<input type="hidden" name="token" value="' . newToken() . '">';
					$resprints .= '<input type="hidden" name="action" value="setoverriderouting">';
					$resprints .= '<input type="hidden" name="page_y" value="page_y">';

					// Build select options: first option = thirdparty default (empty = no override)
					$selectOptions = array('' => $langs->trans("InvoiceRoutingOverrideDefault"));
					foreach ($allRoutings as $r) {
						$label = $r['routing_id'];
						if ($r['is_default']) {
							$label .= ' (' . $langs->trans("Default") . ')';
						}
						if (!empty($r['info'])) {
							$label .= ' — ' . $r['info'];
						}
						$selectOptions[$r['routing_id']] = $label;
					}
					$resprints .= $form->selectarray('override_routing_id', $selectOptions, $currentOverrideRouting, 0, 0, 0, '', 1);
					$resprints .= '<input type="submit" class="button button-edit smallpaddingimp reposition" value="' . $langs->trans('Modify') . '">';
					$resprints .= '</form>';
				} else {
					if (!empty($currentOverrideRouting)) {
						$resprints .= dol_escape_htmltag($currentOverrideRouting);
					} else {
						$resprints .= '<span class="opacitymedium">' . $langs->trans("InvoiceRoutingOverrideDefault") . '</span>';
					}
				}
				$resprints .= '</td>';
				$resprints .= '</tr>';
			}
		}

		// If current status requires a reason, display it
		if (!empty($currentStatusInfo['reasonCode'])) {
			$reasonLabel = self::REASONS[$currentStatusInfo['reasonCode']]['label'] ?? $currentStatusInfo['reasonCode'];
			$resprints .= '<tr class="trpdpconnect_collapseseparator" id="trpdpconnect_reason">';
			$resprints .= '<td class="">' . $langs->trans("pdpconnectfrInvoiceReason") . '</td>';
			$resprints .= '<td><span id="einvoice-reason">' . $reasonLabel . '</span></td>';
			$resprints .= '</tr>';
		}

		// JavaScript for AJAX call to update status if current status is pending
		if ((int) $currentStatusInfo['code'] === self::STATUS_AWAITING_VALIDATION) {
			$urlajax = dol_buildpath('pdpconnectfr/ajax/checkinvoicestatus.php', 1);

			$resprints .= '
            <script type="text/javascript">
            (function() {
				var countCheckInvoiceStatus = 1;
                function checkInvoiceStatus() {
					console.log("checkInvoiceStatus Checking invoice status ("+countCheckInvoiceStatus+")...");
                    // alert("Checking invoice status...");
                    $.get("' . $urlajax . '", {
                        token: "' . currentToken() . '",
                        ref: "' . dol_escape_js($object->ref) . '"
                    }, function (data) {
						/* code is executed here if response is valid json */
                        if (!data || typeof data.code === "undefined") {
							console.log("checkInvoiceStatus no data returned");
                            return;
                        }
						console.log(data.status);

                        // Update UI
						if (typeof data.status !== "undefined") {
	                        $("#einvoice-status").html(data.status || "");
						}
                        $("#einvoice-info").html(data.info || "");

                        // Retry only if still awaiting validation
                        if (parseInt(data.code) === ' . self::STATUS_AWAITING_VALIDATION . ') {
							countCheckInvoiceStatus++;
							if (countCheckInvoiceStatus <= 5) {
                            	setTimeout(checkInvoiceStatus, 5000);
							} else if (countCheckInvoiceStatus <= 10) {
                            	setTimeout(checkInvoiceStatus, 10000);
							}
                        }
                    }, "json");
                }

                // First call
				console.log("checkInvoiceStatus Invoice has status pending, so we add a timer to run checkInvoiceStatus in few seconds...");
                setTimeout(checkInvoiceStatus, 2500);

            })();
            </script>';
		}

		// Disable edit button if invoice is already sent to PDP/PA
		// Note: Real protection is done in PHP side as it is not reliable in JS. This is for cosmetic purpose only
		/*
		if ($currentStatusInfo['transmitted'] == 1) {
			$resprints .= '
				<script>
				$(document).ready(function() {
					console.log("Invoice has a status saying is was already sent so we change the button Modify to disable it");
					// Target the "Edit" link in the action buttons
					$("a.butAction").filter(function() {
						return $(this).attr("href") && $(this).attr("href").indexOf("action=modif") !== -1;
					}).each(function() {
						// Replace with a disabled button
						$(this).replaceWith(
							$("<span>")
								.addClass("butActionRefused classfortooltip")
								.attr("title", "' . $langs->trans("InvoiceLinkedToPdpCannotBeModified") . '")
								.text($(this).text())
						);
					});
				});
				</script>';
		}
		*/

		return $resprints;
	}

	/**
	 * supplierInvoiceCardBlock
	 *
	 * @param 	FactureFournisseur 	$object					FactureFournisseur
	 * @param	string				$mode					'create', 'view'
	 * @return 	string				HTML content to add
	 */
	public function supplierInvoiceCardBlock($object, $mode = '')
	{
		global $langs;
		global $action;

		$resprints = '';

		// Check if this invoice is present into pdpconnectfr_extlinks table to know if it is an imported object
		$provider = '';
		$sql = "SELECT rowid, provider FROM ".MAIN_DB_PREFIX."pdpconnectfr_extlinks";
		$sql .= " WHERE element_type = '".$this->db->escape($object->element)."'";
		$sql .= " AND element_id = ".(int) $object->id;
		$sql .= " LIMIT 1";
		$resql = $this->db->query($sql);
		if ($resql && $this->db->num_rows($resql) > 0) {
			$obj = $this->db->fetch_object($resql);

			$provider = $obj->provider;
		}

		// Add block only for imported invoices

		// Set $extrafield_collapse_display_value (do we have to collapse/expand the group after the separator)
			$extrafield_collapse_display_value = -1;
			$expand_display = ((isset($_COOKIE['DOLUSER_COLLAPSE_facture_trpdpconnectseparator']) || GETPOSTINT('ignorecollapsesetup')) ? (!empty($_COOKIE['DOLUSER_COLLAPSE_facture_trpdpconnectseparator'])) : !($extrafield_collapse_display_value == 2));
			$disabledcookiewrite = 0;
		if ($mode == 'create') {
			// On create mode, force separator group to not be collapsible
			$extrafield_collapse_display_value = 1;
			$expand_display = true;	// We force group to be shown expanded
			$disabledcookiewrite = 1; // We keep status of group unchanged into the cookie
		}
			$resprints .= '<!-- supplierInvoiceCardBlock -->
            <script nonce="" type="text/javascript">
			jQuery(document).ready(function() {';
		if (empty($disabledcookiewrite)) {
			if (!$expand_display) {
				$resprints .= 'console.log("Inject js for the collapsing of trpdpconnect_collapseseparator - hide");
						jQuery(".trpdpconnect_collapseseparator").hide();';
			} else {
				$resprints .= 'console.log("Inject js for collapsing of trpdpconnect_collapseseparator - keep visible and set cookie");
						document.cookie = "DOLUSER_COLLAPSE_facture_trpdpconnectseparator=1; path='.$_SERVER["PHP_SELF"].'";';
			}
		}
			$resprints .= '
			   jQuery("#trpdpconnect").click(function(){
			       console.log("We click on collapse/uncollapse to hide/show .trpdpconnectseparator");
			       jQuery(".trpdpconnect_collapseseparator").toggle(100, function(){
			           if (jQuery(".trpdpconnect_collapseseparator").is(":hidden")) {
			               jQuery("#trpdpconnect td span").addClass("fa-plus-square").removeClass("fa-minus-square");
			               document.cookie = "DOLUSER_COLLAPSE_facture_trpdpconnectseparator=0; path='.$_SERVER["PHP_SELF"].'"
			           } else {
			               jQuery("#trpdpconnect td span").addClass("fa-minus-square").removeClass("fa-plus-square");
			               document.cookie = "DOLUSER_COLLAPSE_facture_trpdpconnectseparator=1; path='.$_SERVER["PHP_SELF"].'"
			           }
			       });
			   });
			});
			</script>';

			// Title separator
			$resprints .= '<tr id="trpdpconnect" class="trpdpconnectseparator trtrpdpconnectseparator_1">';
			$resprints .= '<td>';
			$resprints .= '<span class="far fa-'.(($expand_display ? 'minus' : 'plus').'-square').'"></span><strong> ' . $langs->trans("EInvoicing") . '</strong>';
			$resprints .= '</td>';
			$resprints .= '<td>';
		if ($action != 'create') {
			if ($object->element == 'facture' || $object->element == 'invoice') {
				$url = DOL_URL_ROOT.'/compta/facture/agenda.php?id=' . urlencode($object->id) . '&search_agenda_label=PDPCONNECTFR';
			} else {
				$url = DOL_URL_ROOT.'/fourn/facture/agenda.php?id=' . urlencode($object->id) . '&search_agenda_label=PDPCONNECTFR';
			}

			$resprints .= '<a href="' . $url . '">' . $langs->trans("History") . '<i class="marginleftonly fas fa-calendar-alt infobox-action"></i></a>';
		}

			$resprints .= '</td>';
			$resprints .= '</tr>';

			// Source
			$resprints .= '<tr class="trpdpconnect_collapseseparator">';
			$resprints .= '<td>' . $langs->trans("pdpconnectfrSourceTitle") . '</td>';
			$resprints .= '<td>' . ($provider ? dolPrintHTML($provider) : '<span class="opacitymedium">'.$langs->trans("CreatedManually").'</span>') . '</td>';
			$resprints .= '</tr>';

		if ($provider) {
			// Get current status
			$currentStatus = '-';
			$sql = "SELECT lc_status, lc_reason_code FROM ".MAIN_DB_PREFIX."pdpconnectfr_lifecycle_msg";
			$sql .= " WHERE element_type = '".$this->db->escape($object->element)."'";
			$sql .= " AND element_id = ".(int) $object->id;
			$sql .= " AND lc_validation_status = 'Ok'";
			$sql .= " ORDER BY rowid DESC LIMIT 1";
			$resql = $this->db->query($sql);
			if ($resql && $this->db->num_rows($resql) > 0) {
				$obj = $this->db->fetch_object($resql);
				$currentStatus = $obj->lc_status;
				$currentStatus = $this->getStatusLabel($currentStatus);
			}
			// Current status
			$resprints .= '<tr class="trpdpconnect_collapseseparator">';
			$resprints .= '<td class="">' . $langs->trans("pdpconnectfrInvoiceStatus") . '</td>';
			$resprints .= '<td><span id="einvoice-status">' . $currentStatus . '</span>';

			// If current status requires a reason, display it
			$reasonLabel = '';
			$displayReasonLabel = 'style="display:none;"';
			if (!empty($obj->lc_reason_code)) {
				$reasonLabel = $this->getRaisonsByStatus($obj->lc_status)[$obj->lc_reason_code]['label'] ?? $obj->lc_reason_code;
				$displayReasonLabel = '';
			}

			$resprints .= '<span id="einvoice-reason"'.($displayReasonLabel ? ' '.$displayReasonLabel : '').'>' . $reasonLabel . '</span>';

			$resprints .= '</td>';
			$resprints .= '</tr>';

			// Get last sent status to know if we need to add the JavaScript for real time update of status and to display last sent status validation if it is pending or in error
			$lastSentStatus = array();
			$sql = "SELECT lc_status, lc_status_message, lc_validation_status, lc_validation_message FROM ".MAIN_DB_PREFIX."pdpconnectfr_lifecycle_msg";
			$sql .= " WHERE element_type = '".$this->db->escape($object->element)."'";
			$sql .= " AND element_id = ".(int) $object->id;
			$sql .= " ORDER BY rowid DESC LIMIT 1";
			$resql = $this->db->query($sql);
			if ($resql && $this->db->num_rows($resql) > 0) {
				$obj = $this->db->fetch_object($resql);
				$lastSentStatus = [
					'lc_status' => $obj->lc_status,
					'lc_status_message' => $obj->lc_status_message,
					'lc_validation_status' => $obj->lc_validation_status,
					'lc_validation_message' => $obj->lc_validation_message
				];
			}

			if (!empty($lastSentStatus) && ($lastSentStatus['lc_validation_status'] == 'Pending' || $lastSentStatus['lc_validation_status'] == 'Error')) {
				$statusLabel = $this->getStatusLabel($lastSentStatus['lc_status']);
				$statusvalidationLabel = $this->getStatusLabel($this->getDolibarrStatusCodeFromPdpLabel($lastSentStatus['lc_validation_status']));
				if ($lastSentStatus['lc_validation_status'] === 'Pending') {
					$picto = img_picto('', 'timespent');
				} elseif ($lastSentStatus['lc_validation_status'] === 'Error') {
					$picto = img_picto('', 'error');
				}
				$statusValidation = ' : ' . $statusvalidationLabel . ' ' .$picto;
				$statusValidationInfo = $lastSentStatus['lc_validation_message'] ?? '';

				// Validation of last sent status to display it in the invoice card and to know if we need to add the JavaScript for real time update of status
				$resprints .= '<tr class="trpdpconnect_collapseseparator " id="trpdpconnect_lastsentstatusvalidation">';
				$resprints .= '<td class="">'. $langs->trans("pdpconnectfrLastSentStatus"). '</td>';
				$resprints .= '<td><span>'. $statusLabel .'</span><span id="status-validation"> ' . $statusValidation . '</span><br>';
				$resprints .= '<span id="status-validation-info" class="opacitymedium" style="overflow-wrap: anywhere;">' . htmlspecialchars($statusValidationInfo) . '</span>';
				$resprints .= '</td>';
				$resprints .= '</tr>';

				// JavaScript for AJAX call to update status if current status is pending
				if ($this->getDolibarrStatusCodeFromPdpLabel($lastSentStatus['lc_validation_status']) == self::STATUS_AWAITING_VALIDATION) {
					$urlajax = dol_buildpath('pdpconnectfr/ajax/checksupplierinvoicestatus.php', 1);

					$resprints .= '
                    <script type="text/javascript">
                    (function() {
                        function checkSupplierInvoiceStatus() {
                            console.log("checkSupplierInvoiceStatus Checking invoice status...");
                            $.get("' . $urlajax . '", {
                                token: "' . currentToken() . '",
                                id: "' . dol_escape_js($object->id) . '"
                            }, function (data) {
                                if (!data || typeof data.statusvalidationlabel === "undefined") {
                                    console.log("checkSupplierInvoiceStatus no data returned");
                                    return;
                                }
                                console.log(data);

                                // Update UI
                                $("#status-validation").html(data.htmlstatusvalidationLabel || "");
                                $("#status-validation-info").html(data.statusvalidationinfo || "");

                                // Hide row if status is OK and replace current status with the new one
                                if (data.statusvalidationlabel === "Ok") {
                                    $("#trpdpconnect_lastsentstatusvalidation").hide();
                                    $("#einvoice-status").html(data.statuslabel || "");
                                    if (data.statusreasonlabel) {
                                        $("#einvoice-reason").html(data.statusreasonlabel || "");
                                        $("#trpdpconnect_reason").show();
                                    } else {
                                        $("#trpdpconnect_reason").hide();
                                    }
                                }

                                // Retry only if still awaiting validation
                                if (data.statusvalidationlabel === "Pending") {
                                    setTimeout(checkSupplierInvoiceStatus, 5000);
                                }
                            }, "json");
                        }

                        // First call
                        console.log("checkSupplierInvoiceStatus Invoice has status pending, so we add a timer to run checkInvoiceStatus in few seconds...");
                        setTimeout(checkSupplierInvoiceStatus, 2500);

                    })();
                    </script>';
				}
			}
		}

		return $resprints;
	}

	/**
	 * thirdpartyCardBlock
	 *
	 * @param 	Societe 			$object			Thirdparty
	 * @param	string				$mode			'create', 'view'
	 * @return 	string								HTML content to add
	 */
	public function thirdpartyCardBlock($object, $mode = '')
	{
		global $langs, $form;

		$resprints = '';

		// Set $extrafield_collapse_display_value (do we have to collapse/expand the group after the separator)
		$extrafield_collapse_display_value = -1;
		$expand_display = ((isset($_COOKIE['DOLUSER_COLLAPSE_facture_trpdpconnectseparator']) || GETPOSTINT('ignorecollapsesetup')) ? (!empty($_COOKIE['DOLUSER_COLLAPSE_facture_trpdpconnectseparator'])) : !($extrafield_collapse_display_value == 2));
		$disabledcookiewrite = 0;
		if ($mode == 'create') {
			// On create mode, force separator group to not be collapsible
			$extrafield_collapse_display_value = 1;
			$expand_display = true;	// We force group to be shown expanded
			$disabledcookiewrite = 1; // We keep status of group unchanged into the cookie
		}

		$resprints .= '<!-- thirdpartyCardBlockfor objec->element = '.$object->element.' -->
        <script nonce="" type="text/javascript">
        jQuery(document).ready(function() {';
		if (empty($disabledcookiewrite)) {
			if (!$expand_display) {
				$resprints .= 'console.log("Inject js for the collapsing of trpdpconnect_collapseseparator - hide");
                    jQuery(".trpdpconnect_collapseseparator").hide();';
			} else {
				$resprints .= 'console.log("Inject js for collapsing of trpdpconnect_collapseseparator - keep visible and set cookie");
                    document.cookie = "DOLUSER_COLLAPSE_facture_trpdpconnectseparator=1; path='.$_SERVER["PHP_SELF"].'";';
			}
		}
		$resprints .= '
            jQuery("#trpdpconnect").click(function(){
                console.log("We click on collapse/uncollapse to hide/show .trpdpconnectseparator");
                jQuery(".trpdpconnect_collapseseparator").toggle(100, function(){
                    if (jQuery(".trpdpconnect_collapseseparator").is(":hidden")) {
                        jQuery("#trpdpconnect td span").addClass("fa-plus-square").removeClass("fa-minus-square");
                        document.cookie = "DOLUSER_COLLAPSE_facture_trpdpconnectseparator=0; path='.$_SERVER["PHP_SELF"].'"
                    } else {
                        jQuery("#trpdpconnect td span").addClass("fa-minus-square").removeClass("fa-plus-square");
                        document.cookie = "DOLUSER_COLLAPSE_facture_trpdpconnectseparator=1; path='.$_SERVER["PHP_SELF"].'"
                    }
                });
            });
        });
        </script>';

		// Title separator
		$resprints .= '<tr id="trpdpconnect" class="trpdpconnectseparator trtrpdpconnectseparator_1">';
		$resprints .= '<td colspan="2"><span class="far fa-'.(($expand_display ? 'minus' : 'plus').'-square').'"></span><strong> ' . $langs->trans("EInvoicing") . '</strong></td>';
		$resprints .= '</tr>';


		// Fetch routing_id and optional default product for import
		$routing_id = '';
		$product_id = 0;
		$resFetch = $this->fetchDefaultRouting($object->id, 'thirdparty');
		if ($resFetch !== false && $resFetch !== 0 && $resFetch !== '0') {
			$routing_id = $resFetch;
		}
		$resFetchP = $this->fetchDefaultRouting($object->id, 'product');
		if ($resFetchP > 0) {
			$product_id = (int) $resFetchP;
		}

		// In create/edit mode, keep simple text fields (thirdparty not yet saved, no routing rows exist)
		if ($mode == 'create' || $mode == 'edit') {
			$resprints .= '<tr class="trpdpconnect_collapseseparator">';
			$resprints .= '<td class="">' . $langs->trans("RoutingIdField") . '</td>';
			$resprints .= '<td>';
			$resprints .= '<input type="text" name="routing_id" ';
			$resprints .= 'value="' . dolPrintHTML($routing_id ?? '') . '" ';
			$resprints .= 'class="flat minwidth300" />';
			$resprints .= '</td>';
			$resprints .= '</tr>';

			// Add a line for the Default product for thirdparty (to use when importing vendor invoice and no product found)
			$resprints .= '<tr class="trpdpconnect_collapseseparator">';
			$resprints .= '<td>' . $form->textwithpicto($langs->trans("DefaultProductEBilling"), $langs->trans("DefaultProductEBillingHelp")) . '</td>';
			$resprints .= '<td>';
			// TODO Use a combo list of products
			$resprints .= '<input type="text" name="routing_product_id" ';
			$resprints .= 'value="' . dolPrintHTML($product_id ?? '') . '" ';
			$resprints .= 'class="flat minwidth300" />';
			$resprints .= '</td>';
			$resprints .= '</tr>';

			return $resprints;
		}

		// Check if this thirdparty is present into pdpconnectfr_extlinks table to know if it is an imported object
		$sql = "SELECT rowid, provider FROM ".MAIN_DB_PREFIX."pdpconnectfr_extlinks";
		$sql .= " WHERE element_type = '".$this->db->escape($object->element)."'";
		$sql .= " AND element_id = ".(int) $object->id;
		$sql .= " LIMIT 1";
		$resql = $this->db->query($sql);
		if ($resql && $this->db->num_rows($resql) > 0) {
			$obj = $this->db->fetch_object($resql);
			$resprints .= '<tr class="trpdpconnect_collapseseparator">';
			$resprints .= '<td>' . $langs->trans("pdpconnectfrSourceTitle") . '</td>';
			$resprints .= '<td>' . dolPrintHTML($obj->provider) . '</td>';
			$resprints .= '</tr>';
		}

		// Routing list management block (view mode)
		$allRoutings = $this->fetchAllRoutings($object->id);
		if (!is_array($allRoutings)) {
			$allRoutings = array();
		}

		$resprints .= '<tr class="trpdpconnect_collapseseparator">';
		$resprints .= '<td class="tdtop">' . $langs->trans("RoutingIdField") . '</td>';
		$resprints .= '<td>';

		// Existing routing list
		if (!empty($allRoutings)) {
			$resprints .= '<table class="nobordernopadding" style="width:100%">';
			foreach ($allRoutings as $r) {
				$resprints .= '<tr>';
				$resprints .= '<td>' . dol_escape_htmltag($r['routing_id']);
				if ($r['is_default']) {
					$resprints .= ' <span class="badge badge-status4 badge-status">' . $langs->trans("Default") . '</span>';
				}
				if (!empty($r['info'])) {
					$resprints .= ' <span class="opacitymedium small"> — ' . dol_escape_htmltag($r['info']) . '</span>';
				}
				$resprints .= '</td>';
				$resprints .= '<td class="right nowraponall">';
				if (!$r['is_default']) {
					$resprints .= '<a class="reposition paddingrightonly" href="' . $_SERVER["PHP_SELF"] . '?id=' . $object->id . '&action=pdp_setdefaultrouting&routing_rowid=' . $r['rowid'] . '&token=' . newToken() . '">';
					$resprints .= '<i class="fas fa-star" title="' . $langs->trans("SetAsDefault") . '"></i>';
					$resprints .= '</a>';
				}
				$resprints .= '<a class="reposition paddingrightonly" href="' . $_SERVER["PHP_SELF"] . '?id=' . $object->id . '&action=pdp_deleterouting&routing_rowid=' . $r['rowid'] . '&token=' . newToken() . '">';
				$resprints .= '<i class="fas fa-trash" title="' . $langs->trans("Delete") . '"></i>';
				$resprints .= '</a>';
				$resprints .= '</td>';
				$resprints .= '</tr>';
			}
			$resprints .= '</table>';
		} else {
			$resprints .= '<span class="opacitymedium">' . $langs->trans("None") . '</span>';
		}

		// Add new routing — use a JS-submitted form appended to body to avoid nested form issue
		$addToken = newToken();
		$addUrl   = dol_escape_js($_SERVER["PHP_SELF"] . '?id=' . $object->id);
		$resprints .= '<div style="margin-top:6px">';
		$resprints .= '<input type="text" id="pdp_new_routing_id" placeholder="' . dol_escape_htmltag($langs->trans("RoutingIdField")) . '" class="flat minwidth200">';
		$resprints .= ' <input type="text" id="pdp_new_routing_info" placeholder="' . dol_escape_htmltag($langs->trans("RoutingIdInfo")) . '" class="flat minwidth150">';
		$resprints .= ' <button type="button" class="button smallpaddingimp" onclick="pdpSubmitAddRouting()">' . $langs->trans("Add") . '</button>';
		$resprints .= '</div>';
		$resprints .= '<script>
function pdpSubmitAddRouting() {
	var f = document.createElement("form");
	f.method = "post";
	f.action = "' . $addUrl . '";
	var fields = {
		token: "' . dol_escape_js($addToken) . '",
		action: "pdp_addrouting",
		new_routing_id: document.getElementById("pdp_new_routing_id").value,
		new_routing_info: document.getElementById("pdp_new_routing_info").value
	};
	for (var k in fields) {
		var i = document.createElement("input");
		i.type = "hidden"; i.name = k; i.value = fields[k];
		f.appendChild(i);
	}
	document.body.appendChild(f);
	f.submit();
}
</script>';

		$resprints .= '</td>';
		$resprints .= '</tr>';

		// Default product for import (upstream addition)
		$resprints .= '<tr class="trpdpconnect_collapseseparator">';
		$resprints .= '<td>' . $form->textwithpicto($langs->trans("DefaultProductEBilling"), $langs->trans("DefaultProductEBillingHelp")) . '</td>';
		$resprints .= '<td>';
		if ($product_id > 0) {
			$tmpproduct = new Product($this->db);
			$tmpproduct->fetch($product_id);
			$resprints .= $tmpproduct->getNomUrl(1);
		}
		$resprints .= '</td>';
		$resprints .= '</tr>';

		return $resprints;
	}

	/**
	 * productServiceCardBlock
	 *
	 * @param 	Product			 	$object					Product or Service
	 * @param	string				$mode					'create', 'view'
	 * @return 	string				HTML content to add
	 */
	public function productServiceCardBlock($object, $mode = '')
	{
		global $langs;

		$resprints = '';

		// Check if this product or service is present into pdpconnectfr_extlinks table to know if it is an imported object
		$sql = "SELECT rowid, provider FROM ".MAIN_DB_PREFIX."pdpconnectfr_extlinks";
		$sql .= " WHERE element_type = '".$this->db->escape($object->element)."'";
		$sql .= " AND element_id = ".(int) $object->id;
		$sql .= " LIMIT 1";
		$resql = $this->db->query($sql);
		if ($resql && $this->db->num_rows($resql) > 0) {
			$obj = $this->db->fetch_object($resql);
			// Add block only for imported invoices
			$resprints .= '<!-- productServiceCardBlock -->'."\n";

			$resprints .= '<tr>';
			$resprints .= '<td>' . $langs->trans("pdpconnectfrSourceTitle") . '</td>';
			$resprints .= '<td>' . $obj->provider . '</td>';
			$resprints .= '</tr>';
		}

		return $resprints;
	}

	/**
	 * fetchLastknownInvoiceStatus
	 *
	 * @param int			$invoiceId		Invoice ID
	 * @param string		$invoiceRef		Invoice ref
	 * @return string[]|number[]|mixed[][]|mixed[]
	 */
	public function fetchLastknownInvoiceStatus($invoiceId = 0, $invoiceRef = '')
	{
		global $conf;

		// Default status is unknown until invoice is validated
		$status = array('code' => self::STATUS_UNKNOWN, 'status' => $this->getStatusLabel(self::STATUS_UNKNOWN), 'info' => '', 'file' => '0', 'transmitted' => 0, 'override_routing_id' => '');

		$provider = getDolGlobalString('PDPCONNECTFR_PDP');

		// Get last status from pdpconnectfr_extlinks table (table contain dolibarr object received or sent to PDP)
		$sql = "SELECT syncstatus, synccomment, override_routing_id"; // Validation message of einvoice sent.
		$sql .= " FROM ".MAIN_DB_PREFIX."pdpconnectfr_extlinks";
		$sql .= " WHERE element_type = '".$this->db->escape('facture')."'";
		$sql .= " AND provider = '".$this->db->escape($provider)."'";
		if ($invoiceId > 0) {
			$sql .= " AND element_id = ".((int) $invoiceId);
		} else {
			$sql .= " AND syncref = '".$this->db->escape($invoiceRef)."'";
		}

		$resql = $this->db->query($sql);
		if ($resql) {
			if ($this->db->num_rows($resql) > 0) {
				$obj = $this->db->fetch_object($resql);
				$status['code'] = (int) $obj->syncstatus;
				$status['status'] = $this->getStatusLabel((int) $obj->syncstatus);
				$status['info'] = $obj->synccomment ?? '';
				$status['override_routing_id'] = $obj->override_routing_id ?? '';
				if (!in_array((int) $obj->syncstatus, array(self::STATUS_UNKNOWN, self::STATUS_IGNORE, self::STATUS_NOT_GENERATED, self::STATUS_GENERATED))) {
					$status['transmitted'] = 1;
				} else {
					$status['transmitted'] = 0;
				}
			} else {
				dol_syslog("No entry found in pdpconnectfr_extlinks table for invoiceRef: " . $invoiceRef);
			}
		} else {
			dol_print_error($this->db);
		}

		// Fetch last status message from pdpconnectfr_lifecycle_msg table to get more details on current status of the invoice into the PDP system
		$currentStatus = '-';
		$sql = "SELECT lc_status, lc_reason_code FROM ".MAIN_DB_PREFIX."pdpconnectfr_lifecycle_msg";
		$sql .= " WHERE element_type = '".$this->db->escape('facture')."'";
		$sql .= " AND element_id = ".(int) $invoiceId;
		$sql .= " ORDER BY rowid DESC LIMIT 1";

		$resql = $this->db->query($sql);
		if ($resql) {
			if ($this->db->num_rows($resql) > 0) {
				$obj = $this->db->fetch_object($resql);
				$status['reasonCode'] = $obj->lc_reason_code ?? '';
			}
		} else {
			dol_print_error($this->db);
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
	 * @param int       $elementId      	Linked Element ID
	 * @param string    $elementType    	Linked Element type
	 * @param string    $flowId         	Flow ID
	 * @param int       $syncStatus     	If the object has a status into the einvoice external system
	 * @param string    $syncRef        	If the object has a given reference into the einvoice external system
	 * @param string    $syncComment    	If we want to store a message for the last sync action try
	 * @param string    $overrideRoutingId	Forced routing ID
	 * @return int 							-1 on error, 0 if nothing done, rowid on success
	 */
	public function insertOrUpdateExtLink($elementId, $elementType, $flowId = '', $syncStatus = 0, $syncRef = '', $syncComment = '', $overrideRoutingId = null)
	{
		global $db, $user;

		$provider = getDolGlobalString('PDPCONNECTFR_PDP');

		if (empty($provider) || $provider === '-1') {
			dol_syslog("Error: E-invoice Access Point is not defined");
			return 0;
		}

		// Check if record exists
		$sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "pdpconnectfr_extlinks";
		$sql .= " WHERE element_id = " . (int) $elementId;
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
			// Update override_routing_id only when explicitly provided (not null)
			if ($overrideRoutingId !== null) {
				$sql .= ", override_routing_id = " . ($overrideRoutingId !== '' ? "'" . $db->escape($overrideRoutingId) . "'" : "NULL");
			}
			$sql .= ", fk_user_modif = " . (int) $user->id;
			$sql .= " WHERE element_id = " . (int) $elementId;
			$sql .= " AND element_type = '" . $db->escape($elementType) . "'";
			$sql .= " AND provider = '" . $db->escape($provider) . "'";
		} else {
			// Insert new record
			$sql = "INSERT INTO " . MAIN_DB_PREFIX . "pdpconnectfr_extlinks";
			$sql .= " (element_id, element_type, provider, date_creation, fk_user_creat, syncstatus, syncref, synccomment, flow_id, override_routing_id)";
			$sql .= " VALUES (" . (int) $elementId . ", '" . $db->escape($elementType) . "', '" . $db->escape($provider) . "'";
			$sql .= ", NOW(), " . (int) $user->id . ", " . (int) $syncStatus;
			$sql .= ", " . ($syncRef ? "'" . $db->escape($syncRef) . "'" : "NULL");
			$sql .= ", " . ($syncComment ? "'" . $db->escape($syncComment) . "'" : "NULL");
			$sql .= ", " . ($flowId ? "'" . $db->escape($flowId) . "'" : "NULL");
			$sql .= ", " . ($overrideRoutingId !== null && $overrideRoutingId !== '' ? "'" . $db->escape($overrideRoutingId) . "'" : "NULL") . ")";
		}

		$resql = $db->query($sql);
		if (!$resql) {
			dol_print_error($db);
			return -1;
		}

		return $exists ? 1 : $db->last_insert_id(MAIN_DB_PREFIX."pdpconnectfr_extlinks");
	}


	/**
	 * Create or replace the default routing for a thirdparty.
	 *
	 * This method enforces a 1 → 1 relationship between a thirdparty and its active default routing:
	 * - Only one active default routing can exist per thirdparty at any given time
	 * - Any existing routing(s) for this thirdparty are automatically deleted before insertion
	 * - The new routing is marked as active (active = 1) and default (is_default = 1)
	 *
	 * Note: Future versions may support true 1 → N routing management with:
	 * - Multiple concurrent routings per thirdparty
	 * - Switching default routing without deletion
	 *
	 * @param 	int    $fk_soc   		Thirdparty ID
	 * @param 	string $routing_id		Routing ID
	 * @param 	string $source			Source
	 * @param 	string $info			Info
	 * @param 	string $syncflowid		Flow ID
	 * @param 	string $routing_type	Routing type ('thirdparty' to get the routing ID for a thirdparty when exporting invoice, 'product' to get internal ID of product to use as default product on invoice import)
	 * @return	int						Rowid on success, -1 on error
	 */
	public function setDefaultRouting($fk_soc, $routing_id, $source = '', $info = '', $syncflowid = '', $routing_type = 'thirdparty')
	{
		global $db, $user;

		$db->begin();

		// Delete existing routing(s) for this thirdparty (1→1 logic)
		$sql = "DELETE FROM " . MAIN_DB_PREFIX . "pdpconnectfr_routing";
		$sql .= " WHERE fk_soc = " . (int) $fk_soc;
		$sql .= " AND routing_type = '".$db->escape($routing_type)."'";

		if (!$db->query($sql)) {
			$db->rollback();
			dol_syslog(__METHOD__ . ' Delete error: ' . $db->lasterror(), LOG_ERR);
			return -1;
		}

		if ($routing_id === '') {
			// If routing_id is empty, we just delete existing routing(s) and do not insert a new one
			$db->commit();
			return 0;
		}

		// Insert new default routing
		$sql = "INSERT INTO " . MAIN_DB_PREFIX . "pdpconnectfr_routing (";
		$sql .= "fk_soc, source, routing_id, info, syncflowid, active, is_default, date_creation, fk_user_creat, routing_type";
		$sql .= ") VALUES (";
		$sql .= (int) $fk_soc . ", ";
		$sql .= "'" . $db->escape($source) . "', ";
		$sql .= "'" . $db->escape($routing_id) . "', ";
		$sql .= ($info !== '' ? "'" . $db->escape($info) . "'" : "NULL") . ", ";
		$sql .= ($syncflowid !== '' ? "'" . $db->escape($syncflowid) . "'" : "NULL") . ", ";
		$sql .= "1, 1, NOW(), " . (int) $user->id.", ";
		$sql .= "'" . $db->escape($routing_type) . "'";
		$sql .= ")";

		if (!$db->query($sql)) {
			$db->rollback();
			dol_syslog(__METHOD__ . ' Insert error: ' . $db->lasterror(), LOG_ERR);
			return -1;
		}

		$rowid = (int) $db->last_insert_id(MAIN_DB_PREFIX . 'pdpconnectfr_routing');

		$db->commit();

		return $rowid;
	}


	/**
	 * Add a new routing entry for a thirdparty.
	 * If no routing exists yet, the new entry is automatically set as default.
	 *
	 * @param  int    $fk_soc     Thirdparty ID
	 * @param  string $routing_id Routing ID value
	 * @param  string $info       Optional label/comment
	 * @return int    Rowid on success, -1 on error
	 */
	public function addRouting($fk_soc, $routing_id, $info = '')
	{
		global $db, $user;

		if (empty($routing_id)) {
			return -1;
		}

		$db->begin();

		// Determine if this will be the first routing (auto-default)
		$sql = "SELECT COUNT(*) AS cnt FROM " . MAIN_DB_PREFIX . "pdpconnectfr_routing";
		$sql .= " WHERE fk_soc = " . (int) $fk_soc . " AND active = 1";
		$resql = $db->query($sql);
		if (!$resql) {
			$db->rollback();
			return -1;
		}
		$obj = $db->fetch_object($resql);
		$isDefault = ($obj->cnt == 0) ? 1 : 0;

		$sql = "INSERT INTO " . MAIN_DB_PREFIX . "pdpconnectfr_routing (";
		$sql .= "fk_soc, source, routing_id, info, active, is_default, date_creation, fk_user_creat";
		$sql .= ") VALUES (";
		$sql .= (int) $fk_soc . ", 'manual', ";
		$sql .= "'" . $db->escape($routing_id) . "', ";
		$sql .= ($info !== '' ? "'" . $db->escape($info) . "'" : "NULL") . ", ";
		$sql .= "1, " . $isDefault . ", NOW(), " . (int) $user->id;
		$sql .= ")";

		if (!$db->query($sql)) {
			$db->rollback();
			dol_syslog(__METHOD__ . ' Insert error: ' . $db->lasterror(), LOG_ERR);
			return -1;
		}

		$rowid = (int) $db->last_insert_id(MAIN_DB_PREFIX . 'pdpconnectfr_routing');
		$db->commit();

		return $rowid;
	}

	/**
	 * Delete a routing entry by rowid.
	 * If the deleted entry was the default, the oldest remaining entry becomes the new default.
	 *
	 * @param  int  $rowid    Routing rowid to delete
	 * @param  int  $fk_soc   Thirdparty ID (used to reassign default if needed)
	 * @return int  1 on success, -1 on error
	 */
	public function deleteRouting($rowid, $fk_soc)
	{
		global $db;

		$db->begin();

		// Check if this entry was the default
		$sql = "SELECT is_default FROM " . MAIN_DB_PREFIX . "pdpconnectfr_routing";
		$sql .= " WHERE rowid = " . (int) $rowid;
		$resql = $db->query($sql);
		if (!$resql) {
			$db->rollback();
			return -1;
		}
		$obj = $db->fetch_object($resql);
		$wasDefault = $obj ? (int) $obj->is_default : 0;

		$sql = "DELETE FROM " . MAIN_DB_PREFIX . "pdpconnectfr_routing WHERE rowid = " . (int) $rowid;
		if (!$db->query($sql)) {
			$db->rollback();
			dol_syslog(__METHOD__ . ' Delete error: ' . $db->lasterror(), LOG_ERR);
			return -1;
		}

		// Reassign default to oldest remaining entry if needed
		if ($wasDefault) {
			$sql = "UPDATE " . MAIN_DB_PREFIX . "pdpconnectfr_routing";
			$sql .= " SET is_default = 1";
			$sql .= " WHERE fk_soc = " . (int) $fk_soc . " AND active = 1";
			$sql .= " ORDER BY rowid ASC LIMIT 1";
			$db->query($sql); // Non-blocking: if no rows remain, nothing to reassign
		}

		$db->commit();

		return 1;
	}

	/**
	 * Set a routing entry as the default for a thirdparty.
	 * Clears is_default on all other entries for the same thirdparty.
	 *
	 * @param  int  $rowid    Routing rowid to set as default
	 * @param  int  $fk_soc   Thirdparty ID
	 * @return int  1 on success, -1 on error
	 */
	public function setRoutingAsDefault($rowid, $fk_soc)
	{
		global $db;

		$db->begin();

		// Clear default on all entries for this thirdparty
		$sql = "UPDATE " . MAIN_DB_PREFIX . "pdpconnectfr_routing";
		$sql .= " SET is_default = 0 WHERE fk_soc = " . (int) $fk_soc;
		if (!$db->query($sql)) {
			$db->rollback();
			return -1;
		}

		// Set new default
		$sql = "UPDATE " . MAIN_DB_PREFIX . "pdpconnectfr_routing";
		$sql .= " SET is_default = 1 WHERE rowid = " . (int) $rowid;
		if (!$db->query($sql)) {
			$db->rollback();
			return -1;
		}

		$db->commit();

		return 1;
	}

	/**
	 * Fetch default routing for a thirdparty
	 *
	 * @param 	int 		$fk_soc   		Thirdparty ID
	 * @param 	string 		$routing_type	Routing type ('thirdparty' to get the routing ID for a thirdparty when exporting invoice, 'product' to get internal ID of product to use as default product on invoice import)
	 * @return 	string|int   				Routing ID string if found, 0 if not found, -1 if error
	 */
	public function fetchDefaultRouting($fk_soc, $routing_type = 'thirdparty')
	{
		global $db;

		$sql = "SELECT rowid, fk_soc, source, routing_id, info, syncflowid";
		$sql .= " FROM " . MAIN_DB_PREFIX . "pdpconnectfr_routing";
		$sql .= " WHERE fk_soc = " . (int) $fk_soc;
		$sql .= " AND routing_type = '".$db->escape($routing_type)."'";
		$sql .= " AND active = 1";
		$sql .= " AND is_default = 1";
		$sql .= " LIMIT 1";

		$resql = $db->query($sql);
		if (!$resql) {
			dol_syslog(__METHOD__ . ' SQL error: ' . $db->lasterror(), LOG_ERR);
			return -1;
		}

		if ($db->num_rows($resql) === 0) {
			return 0;
		}

		$obj = $db->fetch_object($resql);

		return (string) $obj->routing_id;
	}


	/**
	 * Fetch all active routings for a thirdparty
	 *
	 * @param  int    $fk_soc   Thirdparty ID
	 * @return array            Array of routing rows (assoc), empty array if none, -1 if error
	 */
	public function fetchAllRoutings($fk_soc)
	{
		global $db;

		$sql = "SELECT rowid, routing_id, source, info, is_default";
		$sql .= " FROM " . MAIN_DB_PREFIX . "pdpconnectfr_routing";
		$sql .= " WHERE fk_soc = " . (int) $fk_soc;
		$sql .= " AND active = 1";
		$sql .= " ORDER BY is_default DESC, rowid ASC";

		$resql = $db->query($sql);
		if (!$resql) {
			dol_syslog(__METHOD__ . ' SQL error: ' . $db->lasterror(), LOG_ERR);
			return -1;
		}

		$routings = array();
		while ($obj = $db->fetch_object($resql)) {
			$routings[] = array(
				'rowid'      => (int) $obj->rowid,
				'routing_id' => $obj->routing_id,
				'source'     => $obj->source,
				'info'       => $obj->info,
				'is_default' => (int) $obj->is_default,
			);
		}

		return $routings;
	}


	/**
	 * Store a lifecycle status message in the pdpconnectfr_lifecycle_msg table.
	 *
	 * This method is used to persist incoming or outgoing lifecycle status messages
	 * received from or sent to the PDP, and to link each message to a Dolibarr
	 * business object (invoice, supplier invoice, payment, etc.) in order to keep a full history
	 * of lifecycle events.
	 *
	 * @param int    		$elementId             	Element ID (rowid of the linked object)
	 * @param string 		$elementType           	Element type (class name)
	 * @param int    		$statusCode            	Lifecycle status code (normalized)
	 * @param string 		$statusMessage         	Optional detailed status message or comment
	 * @param string 		$direction             	Message direction: IN or OUT
	 * @param string 		$flowId                	PDP flow identifier (UUID), if available
	 * @param string 		$validationStatus      	Validation status: OK, PENDING or ERROR, if status is sent by dolibarr to PDP
	 * @param string 		$validationMessage     	Validation or error message returned by PDP, if status is sent by dolibarr to PDP
	 * @param string|null 	$date_creation    		Date of the event, if we want to store a past event (for example when importing lifecycle history from PDP), if null current date will be used
	 * @param string		$reasonCode				Reason code
	 * @return int  								Rowid inserted or -1 on error
	 */
	public function storeStatusMessage($elementId, $elementType, $statusCode, $statusMessage = '', $direction = 'OUT', $flowId = '', $validationStatus = '', $validationMessage = '', $date_creation = null, $reasonCode = '')
	{
		global $db, $user;

		$provider = getDolGlobalString('PDPCONNECTFR_PDP');

		if (empty($provider) || $provider === '-1') {
			dol_syslog("Error: E-invoice Access Point is not defined");
			return 0;
		}

		$date_creation = $date_creation ? $db->idate($date_creation) : $db->idate(dol_now());

		$sql = "INSERT INTO " . MAIN_DB_PREFIX . "pdpconnectfr_lifecycle_msg (";
		$sql .= "element_id, ";
		$sql .= "element_type, ";
		$sql .= "provider, ";
		$sql .= "flow_id, ";
		$sql .= "direction, ";
		$sql .= "lc_status, ";
		$sql .= "lc_status_message, ";
		$sql .= "lc_validation_status, ";
		$sql .= "lc_validation_message, ";
		$sql .= "date_creation, ";
		$sql .= "fk_user_creat, ";
		$sql .= "lc_reason_code";
		$sql .= ") VALUES (";
		$sql .= (int) $elementId . ", ";
		$sql .= "'" . $db->escape($elementType) . "', ";
		$sql .= "'" . $db->escape($provider) . "', ";
		$sql .= ($flowId ? "'" . $db->escape($flowId) . "'" : "NULL") . ", ";
		$sql .= "'" . $db->escape($direction) . "', ";
		$sql .= (int) $statusCode . ", ";
		$sql .= "'" . $db->escape($statusMessage) . "', ";
		$sql .= "'" . $db->escape($validationStatus) . "', ";
		$sql .= "'" . $db->escape($validationMessage) . "', ";
		$sql .= "'" . $date_creation . "', ";
		$sql .= (int) $user->id . ", ";
		$sql .= "'" . $db->escape($reasonCode) . "'";
		$sql .= ")";

		$resql = $db->query($sql);
		if (!$resql) {
			dol_syslog(__METHOD__ . ' SQL error: ' . $db->lasterror(), LOG_ERR);
			return -1;
		}

		return (int) $db->last_insert_id(MAIN_DB_PREFIX . 'pdpconnectfr_lifecycle_msg');
	}

	/**
	 * Fetch lifecycle status messages linked to a given flow ID.
	 *
	 * @param	int		$flowId		Flow ID
	 * @return	int					Return
	 */
	public function fetchStatusMessages($flowId)
	{
		global $db;

		$sql = "SELECT rowid, element_id, element_type, provider, flow_id, direction, lc_status, lc_status_message, lc_validation_status, lc_validation_message, date_creation";
		$sql .= " FROM " . MAIN_DB_PREFIX . "pdpconnectfr_lifecycle_msg";
		$sql .= " WHERE flow_id = '" . $db->escape($flowId) . "'";

		$resql = $db->query($sql);
		if (!$resql) {
			dol_syslog(__METHOD__ . ' SQL error: ' . $db->lasterror(), LOG_ERR);
			return -1;
		}

		// If no message is found, we return an empty array
		if ($db->num_rows($resql) === 0) {
			return -1;
		}

		// If more than 1 message is returned, we return an error
		if ($db->num_rows($resql) > 1) {
			dol_syslog(__METHOD__ . ' Error: more than 1 message found for flow_id ' . $flowId, LOG_ERR);
			return -1;
		}

		$messages = [];
		if ($db->num_rows($resql) > 0) {
			$obj = $db->fetch_object($resql);
			$messages = [
			'rowid' => (int) $obj->rowid,
			'element_id' => (int) $obj->element_id,
			'element_type' => $obj->element_type,
			'provider' => $obj->provider,
			'flow_id' => $obj->flow_id,
			'direction' => $obj->direction,
			'lc_status' => (int) $obj->lc_status,
			'lc_status_message' => $obj->lc_status_message,
			'lc_validation_status' => $obj->lc_validation_status,
			'lc_validation_message' => $obj->lc_validation_message,
			'date_creation' => $db->jdate($obj->date_creation),
			];
		}

		return $messages;
	}



	/**
	 * Update validation information of an existing lifecycle status message.
	 *
	 * @param int    $rowid					ID
	 * @param string $statusMessage         Optional detailed status message or comment
	 * @param string $validationStatus      Validation status: OK, PENDING or ERROR, if status is sent by dolibarr to PDP
	 * @param string $validationMessage     Validation or error message returned by PDP, if status is sent by dolibarr to PDP
	 *
	 * @return int 1 on success, -1 on error
	 */
	public function updateStatusMessageValidation($rowid, $statusMessage, $validationStatus, $validationMessage = '')
	{
		global $db, $user;

		$sql = "UPDATE " . MAIN_DB_PREFIX . "pdpconnectfr_lifecycle_msg SET ";
		$sql .= "lc_status_message = '" . $db->escape($statusMessage) . "', ";
		$sql .= "lc_validation_status = '" . $db->escape($validationStatus) . "', ";
		$sql .= "lc_validation_message = '" . $db->escape($validationMessage) . "', ";
		$sql .= "fk_user_modif = " . (int) $user->id . " ";
		$sql .= "WHERE rowid = " . (int) $rowid;

		if (!$db->query($sql)) {
			dol_syslog(__METHOD__ . ' SQL error: ' . $db->lasterror(), LOG_ERR);
			return -1;
		}

		return 1;
	}


	/**
	 * Update validation information of an existing lifecycle status message.
	 *
	 * @param 	Object	$object		Object
	 * @return 	int 				1 if the invoice object need management of EInvoicing, 0 if not.
	 */
	public function needEInvoiceManagement($object)
	{
		$return = 0;	// By default, no einvoicing.

		if ($object->thirdparty->country_code == 'FR') {	// We need to sync invoice if for french customer
			$return = 1;
		}
		if ($object->module_source == 'takepos') {			// Force to ignore for all invoices generated from TakePOS
			// If invoice is generated from TakePOS, we must not make any e-invoice sync.
			// We will do a Z sync instead from the cash closing feature.
			$return = 0;
		}

		// TODO More tests to do...
		// TODO Add hook

		return $return;
	}


	/**
	 * Update validation information of an existing lifecycle status message.
	 *
	 * @param 	Object	$object		Object
	 * @param 	string 	$status     Status
	 * @param	string	$comment	Comment
	 * @return 	int 				Rowid on success, 0 if nothing done, -1 on error
	 */
	public function setEInvoiceStatus($object, $status, $comment)
	{
		return $this->insertOrUpdateExtLink($object->id, $object->element, '', $status, $object->ref, $comment);
	}


	/**
	 * Calculate TVA intracommunity number for a thirdparty if missing, from the professional ID
	 *
	 * @param mixed $thirdparty		Third party
	 * @return string
	 */
	public function thirdpartyCalcVATIntra($thirdparty)
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

	/**
	 * Clean up temporary files
	 *
	 * @return void
	 */
	public function cleanUpTemporaryFiles()
	{
		global $conf;
		// Clean up temporary files
		$tempDir = $conf->pdpconnectfr->dir_temp ?? '';
		if (!empty($tempDir) && is_dir($tempDir)) {
			$files = scandir($tempDir);
			if (is_array($files)) {
				foreach ($files as $file) {
					if ($file !== '.' && $file !== '..') {
						$filePath = "$tempDir/$file";
						if (is_file($filePath)) {
							dol_delete_file($filePath);
						}
					}
				}
			}
		}
	}

	/**
	 * Get mycompany communication URI. Return '' if not defined OR if not valid.
	 *
	 * @param	int		$check		0=Do not clear value if check fails
	 * @return 	string				Prof ID
	 */
	public function getSellerCommunicationURI($check = 1)
	{
		global $mysoc;

		$einvoiceid = '';

		// Get seller Einvoice ID
		$provider = getDolGlobalString('PDPCONNECTFR_PDP');

		if (empty($provider) || $provider === '-1') {
			dol_syslog("Error: E-invoice Access Point is not defined");
			return '';
		}

		$uriConf = 'PDPCONNECTFR_' . strtoupper($provider) . '_ROUTING_ID';
		$einvoiceid = getDolGlobalString($uriConf);

		// If electronic invoicing routing ID is not set for the provider, we use professional ID 1 as default value
		if (empty($einvoiceid)) {
			$einvoiceid = idprof($mysoc);
		}

		// Check that einvoice id is ok. Control may depend on country
		if ($check) {
			if ($mysoc->country_code == 'FR') {
				if (!empty($einvoiceid)) {
					$einvoiceid = $this->removeSpaces($einvoiceid);
					if (!preg_match('/^'.preg_replace('/\s+/', '', $mysoc->idprof1).'/', $einvoiceid)) {
						dol_syslog("Error: The seller communication URI seems not correct (should be or start with your SIRET number). Value: " . $einvoiceid, LOG_ERR);
						$einvoiceid = '';
					}
				}
			}
		}

		return $this->removeSpaces($einvoiceid);
	}

	/**
	* Get buyer communication URI
	*
	* @param  Societe 		$thirdparty		Third party
	* @param  Facture|null	$invoice		Invoice
	* @return string
	*/
	public function getBuyerCommunicationURI($thirdparty, $invoice = null)
	{
		$uri = '';

		// If an invoice is provided, check for an invoice-level routing override first
		if ($invoice !== null && !empty($invoice->id)) {
			$statusInfo = $this->fetchLastknownInvoiceStatus($invoice->id, $invoice->ref);
			if (!empty($statusInfo['override_routing_id'])) {
				return $this->removeSpaces($statusInfo['override_routing_id']);
			}
		}

		// Fall back to thirdparty default routing
		$resFetch = $this->fetchDefaultRouting($thirdparty->id);
		if ($resFetch > 0) {
			$uri = $resFetch;
		}

		if (empty($uri) && !getDolGlobalString('PDPCONNECTFR_BLOCK_INVOICE_NO_ROUTING_ID')) {	// Fallback on profid1
			$uri = $thirdparty->idprof1;
		}

		return $this->removeSpaces($uri);
	}

	/**
	 * Remove spaces from string for example french people add spaces into long numbers like
	 * SIRET: 844 431 239 00020
	 *
	 * @param   string  $str  	String to cleanup
	 * @return  string  		cleaned up string
	 */
	public function removeSpaces($str)
	{
		// TODO: move this function to class utils
		return preg_replace('/\\s+/', '', $str);
	}
}
