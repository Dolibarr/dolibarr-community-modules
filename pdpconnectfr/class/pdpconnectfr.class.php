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
     * Facture déposée
     * Statut envoyé uniquement par le PDP/PA
     */
    public const STATUS_DEPOSITED = 200;

    /**
     * Facture émise
     * Statut envoyé uniquement par le PDP/PA
     */
    public const STATUS_ISSUED = 201;

    /**
     * Facture reçue
     * Statut envoyé uniquement par le PDP/PA
     */
    public const STATUS_RECEIVED = 202;

    /**
     * Facture mise à disposition
     * Statut envoyé uniquement par le PDP/PA
     */
    public const STATUS_AVAILABLE = 203;

    /**
     * Facture prise en charge
     * - Facture client : reçue à partir du PDP
     * - Facture fournisseur : peut être envoyée par Dolibarr (facultatif)
     */
    public const STATUS_TAKEN_OVER = 204;

    /**
     * Facture acceptée
     * - Facture client : reçue à partir du PDP
     * - Facture fournisseur : peut être envoyée par Dolibarr (facultatif)
     */
    public const STATUS_APPROVED = 205;

    /**
     * Facture partiellement acceptée
     * - Facture client : reçue à partir du PDP
     * - Facture fournisseur : peut être envoyée par Dolibarr (facultatif)
     */
    public const STATUS_PARTIALLY_APPROVED = 206;

    /**
     * Facture contestée
     * - Facture client : reçue à partir du PDP
     * - Facture fournisseur : peut être envoyée par Dolibarr (facultatif)
     */
    public const STATUS_DISPUTED = 207;

    /**
     * Facture suspendue
     * - Facture client : reçue à partir du PDP
     * - Facture fournisseur : peut être envoyée par Dolibarr (facultatif)
     */
    public const STATUS_SUSPENDED = 208;

    /**
     * Facture refusée
     * - Facture client : reçue à partir du PDP
     * - Facture fournisseur : peut être envoyée par Dolibarr (obligatoire)
     */
    public const STATUS_REFUSED = 210;

    /**
     * Paiement transmis
     * - Facture client : reçue à partir du PDP
     * - Facture fournisseur : peut être envoyée par Dolibarr (facultatif, recommandé)
     */
    public const STATUS_PAYMENT_SENT = 211;

    /**
     * Facture payée
     * - Facture client : peut être envoyée par Dolibarr (facultatif, recommandé)
     * - Facture fournisseur : /
     */
    public const STATUS_PAID = 212;

    /**
     * Facture complétée
     * - Facture client : /
     * - Facture fournisseur : /
     */
    public const STATUS_COMPLETED = 209;

    /**
     * Facture rejetée (technique)
     * - Facture client : reçue à partir du PDP
     * - Facture fournisseur : /
     */
    public const STATUS_REJECTED = 213;

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


    // All raisons with their details (Used when sending supplier invoices status : Refusee, Litige, Suspendue, Approuvee partiellement)
    private const RAISONS = [
        "NON_TRANSMISE" => [
            "label" => "Destinataire non connecté",
            "desc" => "Ce motif est utilisé UNIQUEMENT avec le statut \"DÉPOSÉE\" pour signifier que la facture n'a pas pu être transmise parce que le destinataire (ACHETEUR), bien que présent dans l'Annuaire PPF, n'a aucune adresse de réception de facture active (c'est-à-dire connectée à une Plateforme Agréée en réception)."
        ],
        "JUSTIF_ABS" => [
            "label" => "Justificatif absent ou insuffisant",
            "desc" => "Ce motif doit être utilisé s'il manque des pièces jointes pour le traitement de la facture (statut \"Suspendue\"). Elle devra faire l'objet d'un renvoi par l'émetteur d'un cycle de vie au statut \"Complétée\" avec la ou les pièce(s) jointe(s) manquante(s)"
        ],
        "ROUTAGE_ERR" => [
            "label" => "Erreur de routage",
            "desc" => "Ce motif doit être utilisé dans le cas où les informations servant au routage de la facture sont devenues obsolètes. Ceci peut se produire par exemple : en cas de décalage de mise à jour d'annuaire, une erreur de la Plateforme Agréée émettrice. Post correction de l'annuaire par le destinataire de la facture, la facture peut-être transmise à nouveau (Sans aucun changement sur les données de la facture)"
        ],
        "AUTRE" => [
            "label" => "Autre",
            "desc" => "Ce motif nécessite une explication en Note de CDV"
        ],
        "COORD_BANC_ERR" => [
            "label" => "Erreur de coordonnées bancaires",
            "desc" => "Les références bancaires sur la facture ne correspondent pas à ce qui est paramétré chez le Payeur / Acheteur"
        ],
        "TX_TVA_ERR" => [
            "label" => "Taux de TVA erroné",
            "desc" => "Un taux de TVA utilisé n'est pas celui qui aurait dû"
        ],
        "MONTANTTOTAL_ERR" => [
            "label" => "Montant Total Erroné",
            "desc" => "Un des montants totaux de la facture est erronée, par exemple Net à payer"
        ],
        "CALCUL_ERR" => [
            "label" => "Erreur de calcul de la facture",
            "desc" => "Soit détecté au schematron, soit après (pour les lignes, ou arrondi non accepté)"
        ],
        "NON_CONFORME" => [
            "label" => "Mention légale manquante",
            "desc" => "Toute mention légale non contrôlée"
        ],
        "DOUBLON" => [
            "label" => "Facture en doublon (déjà émise / réçue)",
            "desc" => "Facture en doublon (même numéro même fournisseur et même année de la date de facture)"
        ],
        "DEST_INC" => [
            "label" => "Destinataire inconnu",
            "desc" => "A l'émission, le destinataire est inconnu. Il n'existe pas dans l'annuaire."
        ],
        "DEST_ERR" => [
            "label" => "Erreur de destinataire",
            "desc" => "L'entité juridique destinataire de la facture n'est pas la bonne (n° de SIREN du Destinataire). Par exemple en cas de multi-société dans un groupe, il arrive que la société facturée ne soit pas celle qui aurait dû l'être."
        ],
        "TRANSAC_INC" => [
            "label" => "Transaction inconnue",
            "desc" => "La facture ne correspond pas à une livraison effectuée ou une prestation de service livrée."
        ],
        "EMMET_INC" => [
            "label" => "Emetteur inconnu",
            "desc" => "L'émetteur de la facture est inconnu du Destinataire (anti-spam)"
        ],
        "CONTRAT_TERM" => [
            "label" => "Contrat terminé",
            "desc" => "Contrat terminé, plus de facture possible"
        ],
        "DOUBLE_FACT" => [
            "label" => "DOUBLE FACTURE",
            "desc" => "Prestation ou livraison déjà facturé sur une autre facture"
        ],
        "CMD_ERR" => [
            "label" => "N° de COMMANDE Incorrect ou manquant",
            "desc" => "N° de commande erroné, inexistant ou déjà facturé. Ne peut être utilisé avec un statut REFUSÉE que si le numéro de commande a été fourni par l'ACHETEUR AVANT LA FACTURATION."
        ],
        "ADR_ERR" => [
            "label" => "L'adresse de facturation électronique erronée",
            "desc" => "L'adresse de facturation électronique du destinataire (BT-49 ou BT-34) est absente ou erronée"
        ],
        "SIRET_ERR" => [
            "label" => "SIRET Erroné ou absent",
            "desc" => "Le SIRET du destinataire est erroné ou absent si exigé"
        ],
        "CODE_ROUTAGE_ERR" => [
            "label" => "CODE_ROUTAGE Absent ou Erroné",
            "desc" => "Le CODE_ROUTAGE du destinataire est erroné ou absent si exigé"
        ],
        "REF_CT_ABSENT" => [
            "label" => "Référence contractuelle nécessaire pour le traitement de la facture manquante",
            "desc" => "Référence exigée contractuellement est absente (liste à encadrer) et à identifier dans le CDV : BT-12 (N° de contrat), N° de BL (BT-16), Ref Acheteur (BT-10), Objet Facturé (BT-18), Référence Projet (BT-11), Facture antérieure (BG-3), …"
        ],
        "REF_ERR" => [
            "label" => "Référence incorrecte",
            "desc" => "A préciser dans les autres données du CDV de quelle référence il s'agit"
        ],
        "PU_ERR" => [
            "label" => "Prix Unitaires incorrects",
            "desc" => "Un prix Unitaire n'est pas celui attendu"
        ],
        "REM_ERR" => [
            "label" => "Remise erronée",
            "desc" => "Une remise est absente ou n'est pas celle attendue"
        ],
        "QTE_ERR" => [
            "label" => "Quantité facturée incorrecte",
            "desc" => "Une quantité facturée n'est pas celle attendue"
        ],
        "ART_ERR" => [
            "label" => "Article facturé incorrect",
            "desc" => "Un article facturé n'est pas le bon ou est erroné"
        ],
        "MODPAI_ERR" => [
            "label" => "Modalités de paiement incorrectes",
            "desc" => "Les modalités de paiement (date d'échéance par exemple) n'est pas celle escomptées"
        ],
        "QUALITE_ERR" => [
            "label" => "Qualité d'article livré incorrecte",
            "desc" => "Un des articles livré est défectueux"
        ],
        "LIVR_INCOMP" => [
            "label" => "Problème de livraison",
            "desc" => "Livraison incomplète, non conforme"
        ],
        "REJ_SEMAN" => [
            "label" => "Rejet pour erreur sémantique",
            "desc" => "Analyse du format sémantique"
        ],
        "REJ_UNI" => [
            "label" => "Rejet sur contrôle unicité",
            "desc" => "Contrôle d'unicité"
        ],
        "REJ_COH" => [
            "label" => "Rejet sur contrôle Cohérence de données",
            "desc" => "Contrôle cohérence de données (les balises et les référentiels)"
        ],
        "REJ_ADR" => [
            "label" => "Rejet sur Contrôle d'adressage",
            "desc" => "Contrôle d'adressage"
        ],
        "REJ_CONT_B2G" => [
            "label" => "Rejet sur Contrôles métier B2G",
            "desc" => "Contrôles B2G (vérification du n° d'engagement…)"
        ],
        "REJ_REF_PJ" => [
            "label" => "Rejet sur Référence de PJ",
            "desc" => "Référence de PJ"
        ],
        "REJ_ASS_PJ" => [
            "label" => "Rejet sur Erreur d'association de la PJ",
            "desc" => "Erreur d'association de la PJ"
        ],
        "IRR_VIDE_F" => [
            "label" => "Contrôle de non vide sur les fichiers du flux",
            "desc" => "Contrôle de non vide sur les fichiers du flux"
        ],
        "IRR_TYPE_F" => [
            "label" => "Contrôle de type et extension des fichiers du flux",
            "desc" => "Contrôle de type et extension des fichiers du flux"
        ],
        "IRR_SYNTAX" => [
            "label" => "Contrôle syntaxique des fichiers du flux",
            "desc" => "Contrôle syntaxique des fichiers du flux"
        ],
        "IRR_TAILLE_PJ" => [
            "label" => "Contrôle de taille des PJ de chaque fichier du flux",
            "desc" => "Contrôle de taille des PJ de chaque fichier du flux"
        ],
        "IRR_NOM_PJ" => [
            "label" => "Contrôle du nom des PJ de chaque fichier du flux (absence de caractères interdits)",
            "desc" => "Contrôle du nom des PJ de chaque fichier du flux (absence de caractères interdits)"
        ],
        "IRR_VID_PJ" => [
            "label" => "Contrôle de PJ non vide de chaque fichier du flux",
            "desc" => "Contrôle de PJ non vide de chaque fichier du flux"
        ],
        "IRR_EXT_DOC" => [
            "label" => "Contrôle de l'extension des PJ de chaque fichier du flux",
            "desc" => "Contrôle de l'extension des PJ de chaque fichier du flux"
        ],
        "IRR_TAILLE_F" => [
            "label" => "Contrôle de taille max des fichiers contenus dans le flux",
            "desc" => "Contrôle de taille max des fichiers contenus dans le flux"
        ],
        "IRR_ANTIVIRUS" => [
            "label" => "Contrôle anti-virus",
            "desc" => "Le flux ne respecte pas les conditions de sécurité"
        ]
    ];

    // Codes raisons by status
    private const RAISONS_CODE_FOR_STATUS = [
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

    public const STATUS_REQUIRING_RAISONS = [
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
        global $conf;

        if (empty($invoice->id)) {
            return array(
                'payload' => array(),
                'integrity_hash' => ''
            );
        }

        $payload = array();
        // TODO : Complete and use this payload structure with methodes to generate e-invoicing (Factur-X, UBL, CII...) instead of fetching data separately in each method for each format.
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
    public function checkModulePrerequisites() {

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
                $value = '(' . $code . ') ' . $value;
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

        // TODO : remove statuses that cannot be chronologically be sent (for example, it doesn't make sense to send "Taken over" if invoice is refused), PDP may accept them and ignore them without returning an error.


        return $options;
    }

    /**
     * Get raisons for a given status that will be used when sending supplier invoice status updates to PDP/PA (for statuses Refused, Disputed, Partially Approved, Suspended)
     *
     * @param int $statut
     * @param int $withDetails return also desc if 1
     *
     * @return array<string, array{code:string, label:string, desc:string}>|null
     */
    public function getRaisonsByStatut($statut, $withDetails = 1) {

        if (!isset(self::RAISONS_CODE_FOR_STATUS[$statut])) {
            return null;
        }

        $raisons = [];
        foreach (self::RAISONS_CODE_FOR_STATUS[$statut] as $code) {
            if (isset(self::RAISONS[$code])) {
                $raisons[$code] = [
                    'code' => $code,
                    'label' => self::RAISONS[$code]['label']
                ];
                if ($withDetails === 1) {
                    $raisons[$code]['desc'] = self::RAISONS[$code]['desc'];
                }
            }
        }

        return $raisons;
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
            $baseErrors[] = $langs->trans("FxCheckErrorCustomerCountry");
        }
        // Check routing_id
        $routing_id = '';
        $resFetch = $this->fetchDefaultRouting($thirdparty->id);
        if ($resFetch <= 0) {
            if (getDolGlobalInt('PDPCONNECTFR_BLOCK_INVOICE_NO_ROUTING_ID') == 1) {
                $baseErrors[] = $langs->trans("FxCheckErrorCustomerRoutingID");
            }
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
     * @param 	Facture 			$object					Facture
     * @param	string				$mode					'create', 'view'
     * @return 	string				HTML content to add
     */
    public function EInvoiceCardBlock($object, $mode = '') {
        global $langs;

        $currentStatusInfo = $this->fetchLastknownInvoiceStatus($object->ref, $object->id);
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
				$expand_display = true;	// We force group to be shown expanded
				$disabledcookiewrite = 1; // We keep status of group unchanged into the cookie
			}
            $resprints .= '
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
        $resprints .= '<td colspan="2"><span class="far fa-'.(($expand_display ? 'minus' : 'plus').'-square').'"></span><strong> ' . $langs->trans("pdpconnectfrInvoiceSeparator") . '</strong></td>';
        $resprints .= '</tr>';

        $info = $currentStatusInfo['info'] ?? '';

        // Access Point Status + Field for real time update info
        $resprints .= '<tr class="trpdpconnect_collapseseparator">';
        $resprints .= '<td class="titlefield">'
            . $langs->trans("pdpconnectfrInvoiceStatus")
            . ' <i class="fas fa-info-circle em088 opacityhigh classfortooltip" title="'
            . $langs->trans("einvoiceStatusFieldHelp") . '"></i></td>';
        $resprints .= '<td><span id="einvoice-status">'
            . $currentStatusInfo['status'] . '</span><br>
			<span id="einvoice-info" class="clearboth">' . htmlspecialchars($info) . '</span></td>';
        $resprints .= '</tr>';

        // If current status requires a reason, display it
        if (!empty($currentStatusInfo['reasonCode'])) {
            $reasonLabel = self::RAISONS[$currentStatusInfo['reasonCode']]['label'] ?? $currentStatusInfo['reasonCode'];
            $resprints .= '<tr class="trpdpconnect_collapseseparator" id="trpdpconnect_reason">';
            $resprints .= '<td class="titlefield">' . $langs->trans("pdpconnectfrInvoiceReason") . '</td>';
            $resprints .= '<td><span id="einvoice-reason">' . $reasonLabel . '</span></td>';
            $resprints .= '</tr>';
        }

        // E-Invoice events history link
        $resprints .= '<tr class="trpdpconnect_collapseseparator">';
        $resprints .= '<td>' . $langs->trans("EInvoiceEventsLabel") . '</td>';
        if ($object->element == 'facture' || $object->element == 'invoice') {
        	$url = DOL_URL_ROOT.'/compta/facture/agenda.php?id=' . urlencode($object->id) . '&search_agenda_label=PDPCONNECTFR';
        } else {
        	$url = DOL_URL_ROOT.'/fourn/facture/agenda.php?id=' . urlencode($object->id) . '&search_agenda_label=PDPCONNECTFR';
        }
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

        // Disable edit button if invoice is already sent to PDP/PA
        if ($currentStatusInfo['transmitted'] == 1) {
            $resprints .= '
                <script>
                $(document).ready(function() {
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

        return $resprints;
    }

    /**
     * SupplierInvoiceCardBlock
     *
     * @param 	FactureFournisseur 	$object					FactureFournisseur
     * @param	string				$mode					'create', 'view'
     * @return 	string				HTML content to add
     */
    public function SupplierInvoiceCardBlock($object, $mode = '') {
        global $langs;

        $resprints = '';

        // Check if this invoice is present into pdpconnectfr_extlinks table to know if it is an imported object
        $sql = "SELECT rowid, provider FROM ".MAIN_DB_PREFIX."pdpconnectfr_extlinks";
        $sql .= " WHERE element_type = '".$object->element."'";
        $sql .= " AND element_id = ".(int) $object->id;
        $sql .= " LIMIT 1";
        $resql = $this->db->query($sql);
        if ($resql && $this->db->num_rows($resql) > 0) {
            $obj = $this->db->fetch_object($resql);
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
            $resprints .= '
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
            $resprints .= '<td colspan="2"><span class="far fa-'.(($expand_display ? 'minus' : 'plus').'-square').'"></span><strong> ' . $langs->trans("pdpconnectfrInvoiceSeparator") . '</strong></td>';
            $resprints .= '</tr>';

            // Source
            $resprints .= '<tr class="trpdpconnect_collapseseparator">';
            $resprints .= '<td>' . $langs->trans("pdpconnectfrSourceTitle") . '</td>';
            $resprints .= '<td>' . $obj->provider . '</td>';
            $resprints .= '</tr>';

            // Get current status
            $currentStatus = '-';
            $sql = "SELECT lc_status, lc_reason_code FROM ".MAIN_DB_PREFIX."pdpconnectfr_lifecycle_msg";
            $sql .= " WHERE element_type = '".$object->element."'";
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
            $resprints .= '<td class="titlefield">' . $langs->trans("pdpconnectfrInvoiceStatus") . '</td>';
            $resprints .= '<td><span id="einvoice-status">' . $currentStatus . '</span></td>';
            $resprints .= '</tr>';

            // If current status requires a reason, display it
            $reasonLabel = '';
            $displayReasonLabel = 'style="display:none;"';
            if (!empty($obj->lc_reason_code)) {
                $reasonLabel = $this->getRaisonsByStatut($obj->lc_status)[$obj->lc_reason_code]['label'] ?? $obj->lc_reason_code;
                $displayReasonLabel = '';
            }
            $resprints .= '<tr class="trpdpconnect_collapseseparator" id="trpdpconnect_reason" ' . $displayReasonLabel . '>';
            $resprints .= '<td class="titlefield">' . $langs->trans("pdpconnectfrInvoiceReason") . '</td>';
            $resprints .= '<td><span id="einvoice-reason">' . $reasonLabel . '</span></td>';
            $resprints .= '</tr>';

            // Get last sent status to know if we need to add the JavaScript for real time update of status and to display last sent status validation if it is pending or in error
            $lastSentStatus = array();
            $sql = "SELECT lc_status, lc_status_message, lc_validation_status, lc_validation_message FROM ".MAIN_DB_PREFIX."pdpconnectfr_lifecycle_msg";
            $sql .= " WHERE element_type = '".$object->element."'";
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
                $resprints .= '<td class="titlefield">'. $langs->trans("pdpconnectfrLastSentStatus"). '</td>';
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

            // E-Invoice events history link
            $resprints .= '<tr class="trpdpconnect_collapseseparator">';
            $resprints .= '<td>' . $langs->trans("EInvoiceEventsLabel") . '</td>';

            if ($object->element == 'facture' || $object->element == 'invoice') {
        		$url = DOL_URL_ROOT.'/compta/facture/agenda.php?id=' . urlencode($object->id) . '&search_agenda_label=PDPCONNECTFR';
        	} else {
        		$url = DOL_URL_ROOT.'/fourn/facture/agenda.php?id=' . urlencode($object->id) . '&search_agenda_label=PDPCONNECTFR';
        	}

            $resprints .= '<td><a href="' . $url . '">' . $langs->trans("EInvoiceEventsLink") . ' <i class="fas fa-history"></i></a></td>';
            $resprints .= '</tr>';
        }

        return $resprints;
    }

    /**
     * ThirdpartyCardBlock
     *
     * @param 	Societe 			$object			Thirdparty
     * @param	string				$mode			'create', 'view'
     * @return 	string								HTML content to add
     */
    public function ThirdpartyCardBlock($object, $mode = '') {
        global $langs;

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
        $resprints .= '
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
        $resprints .= '<td colspan="2"><span class="far fa-'.(($expand_display ? 'minus' : 'plus').'-square').'"></span><strong> ' . $langs->trans("pdpconnectfrInvoiceSeparator") . '</strong></td>';
        $resprints .= '</tr>';


        // Fetch routing_id
        $routing_id = '';
        $resFetch = $this->fetchDefaultRouting($object->id);
        if ($resFetch > 0) {
            $routing_id = $resFetch;
        }
        if ($mode == 'create' || $mode == 'edit') {
            $resprints .= '<tr class="trpdpconnect_collapseseparator">';
            $resprints .= '<td class="titlefield">' . $langs->trans("RoutingIdField") . '</td>';
            $resprints .= '<td>';
            $resprints .= '<input type="text" name="routing_id" ';
            $resprints .= 'value="' . dol_escape_htmltag($routing_id ?? '') . '" ';
            $resprints .= 'class="flat minwidth300" />';
            $resprints .= '</td>';
            $resprints .= '</tr>';

            return $resprints;
        }

        // Check if this thirdparty is present into pdpconnectfr_extlinks table to know if it is an imported object
        $sql = "SELECT rowid, provider FROM ".MAIN_DB_PREFIX."pdpconnectfr_extlinks";
        $sql .= " WHERE element_type = '".$object->element."'";
        $sql .= " AND element_id = ".(int) $object->id;
        $sql .= " LIMIT 1";
        $resql = $this->db->query($sql);
        if ($resql && $this->db->num_rows($resql) > 0) {
            $obj = $this->db->fetch_object($resql);
            // Add block only for imported invoices
            $resprints .= '<tr class="trpdpconnect_collapseseparator">';
            $resprints .= '<td>' . $langs->trans("pdpconnectfrSourceTitle") . '</td>';
            $resprints .= '<td>' . $obj->provider . '</td>';
            $resprints .= '</tr>';
        }

        $resprints .= '<tr class="trpdpconnect_collapseseparator">';
        $resprints .= '<td>' . $langs->trans("RoutingIdField") . '</td>';
        $resprints .= '<td>' . dol_escape_htmltag($routing_id ?? '') . '</td>';
        $resprints .= '</tr>';

        return $resprints;
    }

    /**
     * ProductServiceCardBlock
     * @param 	Product|Service 	$object					Product or Service
     * @param	string				$mode					'create', 'view'
     * @return 	string				HTML content to add
     */
    public function ProductServiceCardBlock($object, $mode= '') {
        global $langs;

        $resprints = '';

        // Check if this product or service is present into pdpconnectfr_extlinks table to know if it is an imported object
        $sql = "SELECT rowid, provider FROM ".MAIN_DB_PREFIX."pdpconnectfr_extlinks";
        $sql .= " WHERE element_type = '".$object->element."'";
        $sql .= " AND element_id = ".(int) $object->id;
        $sql .= " LIMIT 1";
        $resql = $this->db->query($sql);
        if ($resql && $this->db->num_rows($resql) > 0) {
            $obj = $this->db->fetch_object($resql);
            // Add block only for imported invoices
            $resprints .= '<tr>';
            $resprints .= '<td>' . $langs->trans("pdpconnectfrSourceTitle") . '</td>';
            $resprints .= '<td>' . $obj->provider . '</td>';
            $resprints .= '</tr>';
        }

        return $resprints;
    }

    function fetchLastknownInvoiceStatus($invoiceRef, $invoiceId = 0) {
        global $db, $conf;

        $status = array('code' => self::STATUS_NOT_GENERATED, 'status' => $this->getStatusLabel(self::STATUS_NOT_GENERATED), 'info' => '', 'file' => '0', 'transmitted' => 0);

        // Get last status from pdpconnectfr_extlinks table (table contain dolibarr object recieved or sent to PDP)
        $sql = "SELECT syncstatus, synccomment"; // Validation message of einvoice sent.
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
                    'transmitted' => 1, // If we have an entry in pdpconnectfr_extlinks table for this invoice, it means that it has been transmitted to PDP
                );
            } else {
                dol_syslog("No entry found in pdpconnectfr_extlinks table for invoiceRef: " . $invoiceRef);
            }
        } else {
            dol_print_error($db);
        }

        // Fetch last status message from pdpconnectfr_lifecycle_msg table to get more details on current status of the invoice into the PDP system
        $currentStatus = '-';
        $sql = "SELECT lc_status, lc_reason_code FROM ".MAIN_DB_PREFIX."pdpconnectfr_lifecycle_msg";
        $sql .= " WHERE element_type = '".Facture::class."'";
        $sql .= " AND element_id = ".(int) $invoiceId;
        $sql .= " ORDER BY rowid DESC LIMIT 1";
        $resql = $this->db->query($sql);
        if ($resql && $this->db->num_rows($resql) > 0) {
            $obj = $this->db->fetch_object($resql);
            $status['reasonCode'] = $obj->lc_reason_code ?? '';
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
     * @param int    $fk_soc   Thirdparty ID
     * @param string $routing_id
     * @param string $source
     * @param string $info
     * @param string $syncflowid
     *
     * @return int Rowid on success, -1 on error
     */
    public function setDefaultRouting($fk_soc, $routing_id, $source = '', $info = '', $syncflowid = '')
    {
        global $db, $user;

        $db->begin();

        // Delete existing routing(s) for this thirdparty (1→1 logic)
        $sql = "DELETE FROM " . MAIN_DB_PREFIX . "pdpconnectfr_routing";
        $sql .= " WHERE fk_soc = " . (int) $fk_soc;

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
        $sql .= "fk_soc, source, routing_id, info, syncflowid, active, is_default, date_creation, fk_user_creat";
        $sql .= ") VALUES (";
        $sql .= (int) $fk_soc . ", ";
        $sql .= "'" . $db->escape($source) . "', ";
        $sql .= "'" . $db->escape($routing_id) . "', ";
        $sql .= ($info !== '' ? "'" . $db->escape($info) . "'" : "NULL") . ", ";
        $sql .= ($syncflowid !== '' ? "'" . $db->escape($syncflowid) . "'" : "NULL") . ", ";
        $sql .= "1, 1, NOW(), " . (int) $user->id;
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
     * Fetch default routing for a thirdparty
     *
     * @param int $fk_soc   Thirdparty ID
     * @return string|int   Routing ID string if found, 0 if not found, -1 if error
     */
    public function fetchDefaultRouting($fk_soc)
    {
        global $db;

        $sql = "SELECT rowid, fk_soc, source, routing_id, info, syncflowid";
        $sql .= " FROM " . MAIN_DB_PREFIX . "pdpconnectfr_routing";
        $sql .= " WHERE fk_soc = " . (int) $fk_soc;
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

        return $obj->routing_id;
    }


    /**
     * Store a lifecycle status message in the pdpconnectfr_lifecycle_msg table.
     *
     * This method is used to persist incoming or outgoing lifecycle status messages
     * received from or sent to the PDP, and to link each message to a Dolibarr
     * business object (invoice, supplier invoice, payment, etc.) in order to keep a full history
     * of lifecycle events.
     *
     * @param int    $elementId             Element ID (rowid of the linked object)
     * @param string $elementType           Element type (class name)
     * @param int    $statusCode            Lifecycle status code (normalized)
     * @param string $statusMessage         Optional detailed status message or comment
     * @param string $direction             Message direction: IN or OUT
     * @param string $flowId                PDP flow identifier (UUID), if available
     * @param string $validationStatus      Validation status: OK, PENDING or ERROR, if status is sent by dolibarr to PDP
     * @param string $validationMessage     Validation or error message returned by PDP, if status is sent by dolibarr to PDP
     * @param string|null $date_creation    Date of the event, if we want to store a past event (for example when importing lifecycle history from PDP), if null current date will be used
     *
     * @return int  Rowid inserted or -1 on error
     */
    public function storeStatusMessage($elementId, $elementType, $statusCode, $statusMessage = '', $direction, $flowId = '', $validationStatus = '', $validationMessage = '', $date_creation = null, $reasonCode = '')
    {
        global $db, $user;

        $provider = getDolGlobalString('PDPCONNECTFR_PDP');
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
     * @param int    $rowid
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
     * Calculate TVA intracommunity number for a thirdparty if missing, from the professional ID
     *
     * @param mixed $thirdparty
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
}