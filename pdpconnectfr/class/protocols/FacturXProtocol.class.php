<?php
/* Copyright (c) 2025       Eric Seigne                 <eric.seigne@cap-rel.fr>
 * Copyright (C) 2025       Laurent Destailleur         <eldy@users.sourceforge.net>
 * Copyright (C) 2025       Mohamed DAOUD               <mdaoud@dolicloud.com>
 *
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
 * \file    pdpconnectfr/class/protocols/FacturXProtocol.class.php
 * \ingroup pdpconnectfr
 * \brief   Factur-X Protocol integration class
 */

require_once DOL_DOCUMENT_ROOT . '/compta/bank/class/account.class.php';
require_once DOL_DOCUMENT_ROOT . '/expedition/class/expedition.class.php';
require_once DOL_DOCUMENT_ROOT . '/commande/class/commande.class.php';
include_once DOL_DOCUMENT_ROOT . '/core/class/translate.class.php';
include_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
include_once DOL_DOCUMENT_ROOT . '/core/class/discount.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/files.lib.php';

use horstoeko\zugferd\codelists\ZugferdCountryCodes;
use horstoeko\zugferd\codelists\ZugferdCurrencyCodes;
use horstoeko\zugferd\codelists\ZugferdElectronicAddressScheme;
use horstoeko\zugferd\codelists\ZugferdInvoiceType;
use horstoeko\zugferd\codelists\ZugferdReferenceCodeQualifiers;
use horstoeko\zugferd\codelists\ZugferdUnitCodes;
use horstoeko\zugferd\codelists\ZugferdVatCategoryCodes;
use horstoeko\zugferd\codelists\ZugferdVatTypeCodes;
use horstoeko\zugferd\ZugferdDocumentBuilder;
use horstoeko\zugferd\ZugferdProfiles;
use horstoeko\zugferd\ZugferdDocumentPdfBuilder;
use horstoeko\zugferd\ZugferdDocumentPdfBuilderAbstract;
use horstoeko\zugferd\ZugferdDocumentValidator;
use horstoeko\zugferd\ZugferdDocumentPdfReader;

require __DIR__ . "/../../vendor/autoload.php";

dol_include_once('/pdpconnectfr/class/protocols/AbstractProtocol.class.php');
dol_include_once('pdpconnectfr/class/pdpconnectfr.class.php');

/**
 * FacturX Protocol Class
 *
 * This class handles the FacturX protocol implementation for generating
 * and managing electronic invoices according to the FacturX standard.
 *
 * @note    This implementation is based on FacturX plugin developed by CAP REL.
 *          It has been adapted and integrated into the PDPConnectFR module to provide
 *          electronic invoicing capabilities compliant with the French Factur-X standard.
 *
 * @see     https://inligit.fr/cap-rel/dolibarr/plugin-facturx plugin repository
 * @author  Eric Seigne <eric.seigne@cap-rel.fr> - Original author
 */
class FacturXProtocol extends AbstractProtocol
{
    /**
     * Initialize available protocols.
     */
    public function __construct($db)
    {
    	$this->db = $db;
    }


    /**
     * Generate the XML content for a given invoice according to the Factur-X standard.
     *
     * This method converts the provided invoice data into a structured XML file
     * compliant with the Factur-X specification (hybrid PDF + XML format).
     *
     * @param object $invoice Invoice object containing all necessary data.
     * @return string XML representation of the invoice.
     */
    public function generateXML($invoice)
    {
        global $conf, $user, $langs, $mysoc, $db;

        $this->sourceinvoice = $invoice;
        $outputlang = $langs->defaultlang;

        // Load PDPConnectFr class
        $pdpconnectfr = new PdpConnectFr($db);

        // Initialize variables
        $ret = $prepaidAmount = 0;
        $billing_period = [];

        $object = $invoice;
        if (!is_object($invoice->thirdparty)) {
            $invoice->fetch_thirdparty();
        }

        // Prepare Invoice Data for XML Generation
        $facture_number = $object->ref;
        $note_pub = $object->note_public ? $object->note_public : "";

        // Convert dates to DateTime objects
        $ladate = new \DateTime(dol_print_date($object->date, 'dayrfc'));
        $ladatepaiement = new \DateTime(dol_print_date($object->date_lim_reglement, 'dayrfc'));

        // details about payment mode
        $account = new Account($db);
        if ($object->fk_account > 0) {
            $bankid = $object->fk_account;
            // For backward compatibility when object->fk_account is forced with object->fk_bank
            $account->fetch($bankid);
        } else {
            $account->fetch(getDolGlobalString('FACTURX_DEFAULT_BANK_ACCOUNT'));
        }
        $account_proprio = \trim($account->owner_name);
        if ($account_proprio == '') {
            dol_syslog('Bank account holder name is empty, please correct it, use socname instead but it could be inccorrect for XRechnung BT-85: Payment account name', \LOG_WARNING);
            $account_proprio = $mysoc->name;
        }

        // customer account linked
        $contact = $object->thirdparty;
        if (isset($object->contact)) {
            $contact = $object->contact;
        }

        // Calculate missing VAT number for thirdparty if applicable
        if ($object->thirdparty->tva_assuj && empty($object->thirdparty->tva_intra)) {
            $object->thirdparty->tva_intra = $pdpconnectfr->thirdpartyCalcTva_intra($object->thirdparty);
        }

        // Customer Email
        $buyerEmail = $this->extractBuyerMail($contact, $object->thirdparty);

        // Get customer order references and delivery dates
        $customerOrderReferenceList = [];
        $deliveryDateList = [];
        $this->_determineDeliveryDatesAndCustomerOrderNumbers($customerOrderReferenceList, $deliveryDateList, $object);

        // Specific handling for Chorus (French invoicing platform) ---
        // if a chorus extrafield is present we have to check if all others are ok, and display warning in case of trouble
        $chorus = false;
        $chorusErrors = [];
        if (getDolGlobalInt('PDPCONNECTFR_USE_CHORUS')) {
            $chorus = true; // TODO : check this condition
        }
        $promise_code = $object->array_options['options_d4d_promise_code'] ?? '';
        if ($promise_code == '') {
            $promise_code = $object->ref_customer ?? ''; // Determine promise_code (engagement number / client ref)
        }
        if ($promise_code == '' && !empty($customerOrderReferenceList)) {
            $promise_code = $customerOrderReferenceList[0];
        }

        // // Chorus errors checks
        // if ($promise_code == '') {
        //     $chorusErrors[] = "N° d'engagement absent";
        // } elseif (\strlen($promise_code) > 50 && $promise_code == $object->ref_customer) {
        //     $chorusErrors[] = "Ref client trop longue pour chorus (max 50 caractères)";
        // }
        // if ($object->array_options['options_d4d_contract_number'] == '') {
        //     $chorusErrors[] = "N° de marché absent";
        // } else {
        //     $chorus = true;
        // }
        // if ($object->array_options['options_d4d_service_code'] == '') {
        //     $chorusErrors[] = "Code service absent";
        // } else {
        //     $chorus = true;
        // }
        // if (isset($object->thirdparty->idprof2) && trim($object->thirdparty->idprof2) == '') {
        //     $chorusErrors[] = "Numéro SIRET du client manquant";
        // }

        // // Display Chorus warnings/errors
        // if ($chorus) {
        //     if (count($chorusErrors) > 0) {
        //         setEventMessages("Alerte conformité Chorus:", $chorusErrors, 'warnings');
        //         dol_syslog(\get_class($this) . '::executeHooks error chorus : ' . \json_encode($chorusErrors), \LOG_ERR);
        //     } else {
        //         dol_syslog(\get_class($this) . '::executeHooks chorus enabled, no errors detected');
        //     }
        // } else {
        //     dol_syslog(\get_class($this) . '::executeHooks no chorus data'); // TODO: maybe disable by default
        // }


        // Base Data Validation (FacturX mandatory fields) ---
        // TODO : use validateMyCompanyConfiguration() and validatethirdpartyConfiguration()
        // $baseErrors = [];

        // // Seller (mysoc) checks
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

        // // Buyer (thirdparty) checks
        // if (empty($object->thirdparty->name)) {
        //     $baseErrors[] = $langs->trans("FxCheckErrorCustomerName");
        // }
        // if (empty($object->thirdparty->idprof1)) {
        //     $baseErrors[] = $langs->trans("FxCheckErrorCustomerIDPROF1");
        // }
        // // if (empty($object->thirdparty->idprof2)) {
        // //     $baseErrors[] = $langs->trans("FxCheckErrorCustomerIDPROF2");
        // // }
        // if (empty($object->thirdparty->address)) {
        //     $baseErrors[] = $langs->trans("FxCheckErrorCustomerAddress");
        // }
        // if (empty($object->thirdparty->zip)) {
        //     $baseErrors[] = $langs->trans("FxCheckErrorCustomerZIP");
        // }
        // if (empty($object->thirdparty->town)) {
        //     $baseErrors[] = $langs->trans("FxCheckErrorCustomerTown");
        // }
        // if (empty($object->thirdparty->country_code)) {
        //     $baseErrors[] = $langs->trans("FxCheckErrorCustomerCountry");
        // }
        // if ($object->thirdparty->tva_assuj &&  empty($object->thirdparty->tva_intra)) {
        //     $baseErrors[] = $langs->trans("FxCheckErrorCustomerVAT");
        // }
        // if (!$buyerEmail) {
        //     $baseErrors[] = $langs->trans("FxCheckErrorCustomerEmail");
        // }

        // // Display base data warnings
        // if (count($baseErrors) > 0) {
        //     dol_syslog(get_class($this) . '::executeHooks baseErrors count > 0');
        //     setEventMessages($langs->trans("FxCheckError"), $baseErrors, 'warnings');
        //     dol_syslog(get_class($this) . '::executeHooks baseErrors count > 0, error = ' . json_encode($baseErrors));
        // }

        // Initialize ZugferdDocumentBuilder (FacturX XML) -----------------------------------------------
        dol_syslog(\get_class($this) . '::executeHooks create new XML document based on PROFILE_EN16931 (CIUS-FR)');
        $profile = getDolGlobalString('PDPCONNECTFR_PROFILE');
        switch ($profile) {
            case 'EN16931' :
                $used_profile = ZugferdProfiles::PROFILE_EN16931;
                $facturxpdf = ZugferdDocumentBuilder::createNew($used_profile);
            default :
                $used_profile = ZugferdProfiles::PROFILE_EN16931;
                $facturxpdf = ZugferdDocumentBuilder::createNew($used_profile);
        }
        dol_syslog(\get_class($this) . '::executeHooks create new XML document based on ' . $used_profile);
        // Initialize ZugferdDocumentBuilder (FacturX XML) -----------------------------------------------

        // Get the type of invoice in FacturX nomenclature
        $objecttype = $this->_getTypeOfInvoice($object);
        if ($objecttype == null) {
        	throw new Exception('BADINVOICETYPE: The type for invoice id '.$object->id.' is not yet supported.');
        }

        $idprof = $this->_remove_spaces($this->thirdpartyidprof($object) ?? '');
        $myidprof = $this->idprof($mysoc);

        // Add test
        if (empty($idprof)) {
			throw new Exception('BADTHIRDPARTYPROFID: The main professional ID of the thirdparty '.$object->name.' is empty.');
        }
        if (empty($myidprof)) {
			throw new Exception('BADPROFID: The professional ID of your company is empty. Fix this in your company setup page.');
        }

        //  Build XML Document Header (Seller, Buyer, Dates)
        $facturxpdf
            ->setDocumentInformation(
                $facture_number,
                $objecttype,
                $ladate,
                $conf->currency,
                $object->ref_customer,
                $outputlang
            )
            ->addDocumentNote($note_pub)
            ->addDocumentNote(getDolGlobalString('PDPCONNECTFR_PMT', 'NA'), null, "PMT")
            ->addDocumentNote(getDolGlobalString('PDPCONNECTFR_PMD', 'NA'), null, "PMD")
            ->addDocumentNote(getDolGlobalString('PDPCONNECTFR_AAB', 'NA'), null, "AAB")

            // ---------------- Seller ----------------
            ->setDocumentSeller($mysoc->name, $myidprof)
            ->addDocumentSellerTaxRegistration("VA", $mysoc->tva_intra ?? 'FRSPECIMEN')
            ->setDocumentSellerLegalOrganisation(
                $myidprof,
                $this->IEC_6523_code($mysoc->country_code), // TODO: maybe we can add a parameter to customize this.
                $mysoc->name ?? 'SPECIMEN'
            )
            ->setDocumentSellerAddress(
                $mysoc->address      ?? 'ADDRESS EMPTY',
                "",
                "",
                $mysoc->zip          ?? 'ZIP EMPTY',
                $mysoc->town         ?? 'NO TOWN',
                $mysoc->country_code ?? 'COUNTRY NOT SET'
            )

            // ---------------- Buyer ----------------
            ->setDocumentBuyer(
                $object->thirdparty->name ?? 'CUSTOMER',
                $idprof ?: 'IDPROF'
            )
            ->setDocumentBuyerAddress(
                $object->thirdparty->address      ?? 'ADDRESS',
                "",
                "",
                $object->thirdparty->zip          ?? 'ZIP',
                $object->thirdparty->town         ?? 'TOWN',
                $object->thirdparty->country_code ?? 'COUNTRY'
            )
            ->addDocumentBuyerTaxRegistration("VA", $object->thirdparty->tva_intra ?? '') //
            ->setDocumentBuyerLegalOrganisation(
                $idprof,
                $this->IEC_6523_code($object->thirdparty->country_code),
                $contact->name ?? $contact->lastname
            )
            ->setDocumentBuyerCommunication(
                '0225',
                $this->_remove_spaces($this->thirdpartyidprof($object) ?? '') // 0002 => SIREN, EM => Email
            );


        // Add seller ID scheme
        $facturxpdf->addDocumentSellerGlobalId($myidprof, $this->IEC_6523_code($mysoc->country_code)); // TODO: maybe we can add a parameter to customize this.

        // Add buyer ID scheme
        /*if (!empty($this->thirdpartyidprof($object))) {
            $facturxpdf->addDocumentBuyerGlobalId($this->thirdpartyidprof($object), $this->IEC_6523_code($object->thirdparty->country_code));
        }*/

        // Add delivery date
        if (!empty($deliveryDateList)) {
            $facturxpdf->setDocumentSupplyChainEvent(new DateTime($deliveryDateList[0]));
        }

        // Add additional referenced documents (Order references) - Disabled for Chorus
        // Not for chorus : a été rejetée pour le(s) motif(s) suivants, identifié(s) dans le flux cycle de vie : L'element (AttachmentBinaryObject.value) est obligatoire si l'element (FichierXml.SupplyChainTradeTransaction.ApplicableHeaderTradeAgreement.AdditionalReferencedDocument) est renseigne.
        if (!$chorus) { // TODO : check this condition
            foreach ($customerOrderReferenceList as $customerOrderRef) {
                if ($customerOrderRef != $promise_code) {
                    $facturxpdf->addDocumentAdditionalReferencedDocument($customerOrderRef, "130");
                }
            }
        }

        // use customer language
        $outputlangs = $langs;
        $newlang = '';

        // Set Trade Contact details --- TODO: Check logic
        $contacts = $object->getIdContact('internal', 'SALESREPFOLL');
        $object->user = null;
        if (!empty($contacts) && $object->fetch_user($contacts[0]) > 0) {
            $name = $object->user->getFullName($outputlangs);
            $office_phone = $object->user->office_phone;
            $office_fax = $object->user->office_fax;
            $email = $object->user->email;
        } else {
            // Fallback to current user if no sales representative found
            $name = $user->getFullName($outputlangs);
            $office_phone = $user->office_phone;
            $office_fax = $user->office_fax;
            $email = $user->email;
        }
        // Fallback to company details if user details are missing
        if (empty($office_phone)) {
            $office_phone = $mysoc->phone;
        }
        if (empty($office_fax)) {
            $office_fax = $mysoc->fax;
        }
        if (empty($email)) {
            $email = $mysoc->email;
        }
        $facturxpdf->setDocumentSellerContact($name, "", $office_phone, $office_fax, $email);
        $facturxpdf->setDocumentSellerCommunication("0225", $this->_remove_spaces($this->idprof($mysoc)));


        // Set Buyer Reference (Service Code for Chorus) and Contract References
        if (!empty($object->array_options['options_d4d_service_code'])) {
            // CHORUS Debtor. Service Code
            $facturxpdf->setDocumentBuyerReference($object->array_options['options_d4d_service_code']);
        }

        // CHORUS Commitment. Contract Number
        if (!empty($object->array_options['options_d4d_contract_number'])) {
            $facturxpdf->setDocumentContractReferencedDocument($object->array_options['options_d4d_contract_number']);
        }

        // CHORUS Commitment. Commitment Number / Client Ref
        if (!empty($promise_code)) {
            $facturxpdf->setDocumentBuyerOrderReferencedDocument($promise_code);
        }

        // Set Business Process ID according to invoice type
        $facturxpdf->setDocumentBusinessProcess($this->getBillingProcessID($object->type));


        // --- 11. Process Invoice Lines ---
        // is there multi VAT informatins ? in case we need to collect all data to be able to join it at the end
        $tabTVA = [];
        // in case of prepaid invoice we have to forget dolibarr point of view with negative line
        $grand_total_ht = $grand_total_tva = $grand_total_ttc = 0;

        // Determine customer language for line labels
        if (isset($object->thirdparty->default_lang)) {
            $newlang = $object->thirdparty->default_lang;
            // for proposal, order, invoice, ...
        }
        // @phan-suppress-next-line PhanUndeclaredProperty
        if (isset($object->default_lang)) {
            $newlang = $object->default_lang;
            // for thirdparty @phan-suppress-current-line PhanUndeclaredProperty
        }
        if (GETPOST('lang_id', 'alphanohtml') != "") {
            $newlang = GETPOST('lang_id', 'alphanohtml');
        }
        if (!empty($newlang)) {
            $outputlangs = new Translate("", $conf);
            $outputlangs->setDefaultLang($newlang);
        }

        // Add invoice lines
        $numligne = 1;
        foreach ($object->lines as $line) {
            // Skip subtotal lines
            $isSubTotalLine = $this->_isLineFromExternalModule($line, $object->element, 'modSubtotal');
            if ($isSubTotalLine) {
                continue;
            }

            // Handle deposit/prepayment lines (treated as a global allowance/charge)
            if ($line->desc == '(DEPOSIT)') {
                $origFactRef = "";
                $origFactDate = new DateTime();
                $discount = new DiscountAbsolute($this->db);
                $resdiscount = $discount->fetch($line->fk_remise_except);

                dol_syslog("Fetch discount " . $line->fk_remise_except . ", res=".$resdiscount, LOG_DEBUG);

                if ($resdiscount > 0) {
                    $origFact = new Facture($this->db);
                    $resOrigFact = $origFact->fetch($discount->fk_facture_source);

                    dol_syslog("Fetch origFact " . $discount->fk_facture_source . ", res=".$resOrigFact, LOG_DEBUG);

                    if ($resOrigFact > 0) {
                        $origFactRef = $origFact->ref;
                        $origFactDate = new DateTime(dol_print_date($origFact->date, 'dayrfc'));
                    }
                }
                $prepaidAmount += abs($line->total_ttc);
                $facturxpdf->addDocumentAllowanceCharge(\abs($line->total_ttc), false, "S", "VAT", $line->tva_tx, null, null, null, null, null, "Prepayment invoice (386)", $origFactRef);

                dol_syslog("Set setDocumentBuyerOrderReferencedDocument : " . json_encode($origFactRef) . " :: " . json_encode($origFactDate), LOG_DEBUG);

                $facturxpdf->setDocumentInvoiceReferencedDocument($origFactRef, $origFactDate->format('Y-m-d'));
                continue;
            }

            // Get product labels (multilangs support)
            $libelle = $description = "";
            if ($newlang != "") {
                if (!isset($line->multilangs)) {
                    $tmpproduct = new Product($db);
                    $resproduct = $tmpproduct->fetch($line->fk_product);
                    if ($resproduct > 0) {
                        $getm = $tmpproduct->getMultiLangs();
                        if ($getm < 0) {
                            dol_syslog("PDPConnectFR error fetching multilang for product error is " . $tmpproduct->error, LOG_DEBUG);
                        }
                        $line->multilangs = $tmpproduct->multilangs;
                    } else {
                        dol_syslog("PDPConnectFR error fetching product", LOG_DEBUG);
                    }
                }
                if (isset($line->multilangs)) {
                    $libelle = $line->multilangs[$newlang]["label"];
                    $description = $line->multilangs[$newlang]["description"];
                }
            }
            if (empty($libelle)) {
                $libelle = $line->product_label ? $line->product_label : "";
            }
            if (empty($description)) {
                $description = $line->desc ? dol_string_nohtmltag($line->desc, 0) : "";
            }
            if (empty($libelle) && !empty($description)) {
                $libelle = dol_trunc(dolGetFirstLineOfText(dol_string_nohtmltag($description)), 49, 'right', 'UTF-8', 1);

                if ($libelle == $description) {
                    $description = "";
                }
            }
            $lineref = $line->product_ref ? $line->product_ref : "0000";
            $lineproductref = $line->product_ref ? $line->product_ref : "0000";

            // TODO: ADD supplier product reference if available
            // TODO: Add globalIDType and globalID if available

            // Add the line item to the XML
            $facturxpdf
                ->addNewPosition($numligne)
                ->setDocumentPositionProductDetails($libelle, $description, $lineproductref)
                ->setDocumentPositionGrossPrice($line->subprice)
                ->setDocumentPositionNetPrice($line->subprice)
                ->setDocumentPositionQuantity($line->qty, "H87") // H87 = Pieces (TODO: customize unit code, required from 09/2027) https://unece.org/trade/documents/2021/06/uncefact-rec20-0
                ->setDocumentPositionLineSummation($line->total_ht);

            // Set billing period for the line
            if (!empty($line->date_start)) {
                $billing_period["start"][$numligne] = $line->date_start;
            }
            if (!empty($line->date_end)) {
                $billing_period["end"][$numligne] = $line->date_end;
            }
            if (isset($billing_period["start"][$numligne]) && isset($billing_period["end"][$numligne])) {
                $facturxpdf->setDocumentPositionBillingPeriod($this->_tsToDateTime($billing_period["start"][$numligne]), $this->_tsToDateTime($billing_period["end"][$numligne]));
            }

            // Handle negative amount lines as a line discount
            // please read 3 types of discount available
            // https://github.com/horstoeko/zugferd/wiki/Creating-XML-Documents#working-with-discounts-and-charges
            if ($line->subprice < 0) {
                dol_syslog("PDPConnectFR : there is negative line, convert as a global discount", \LOG_INFO);
                // setEventMessages($langs->transnoentitiesnoconv('FxNegativeLine'), [], 'warnings');
                // print json_encode($line);exit;
                $facturxpdf->addDocumentPositionGrossPriceAllowanceCharge(abs($line->subprice) * $line->qty, false, null, null, "Discount");
                //other ideas
                // $facturxpdf->addDocumentPositionAllowanceCharge(abs($remise_amount), true, null, null, null, "Discount");
                // $facturxpdf->addDocumentAllowanceCharge(abs($line->subprice) * $line->qty, false, "S","VAT", $line->tva_tx,null, null, null, null, null, null, "Discount");
            }

            // VAT informations (Line Tax)
            if ($line->tva_tx > 0) {
                $facturxpdf->addDocumentPositionTax('S', 'VAT', $line->tva_tx);
            } else {
                $facturxpdf->addDocumentPositionTax('K', 'VAT', '0.00');
            }

            // Discount percentage on a line
            if (isset($line->remise_percent) && $line->remise_percent > 0) {
                $remise_amount = $line->total_ht - $line->subprice * $line->qty;
                dol_syslog("PDPConnectFR : there is a discount on that line : " . $line->remise_percent . ", amount is " . $remise_amount);
                $facturxpdf->addDocumentPositionAllowanceCharge(abs($remise_amount), false, $line->remise_percent, $line->subprice * $line->qty, null, "Discount");
            }

            // Aggregate VAT totals for document summary
            if (!isset($tabTVA[$line->tva_tx])) {
                $tabTVA[$line->tva_tx] = [];
            }
            if (!isset($tabTVA[$line->tva_tx]['totalHT'])) {
                $tabTVA[$line->tva_tx]['totalHT'] = 0;
            }
            if (!isset($tabTVA[$line->tva_tx]['totalTVA'])) {
                $tabTVA[$line->tva_tx]['totalTVA'] = 0;
            }
            $tabTVA[$line->tva_tx]['totalHT'] += $line->total_ht;
            $tabTVA[$line->tva_tx]['totalTVA'] += $line->total_tva;

            // Update grand totals
            $grand_total_ht += $line->total_ht;
            $grand_total_ttc += $line->total_ttc;
            $grand_total_tva += $line->total_tva;

            $numligne++;
        }

        // Final Document Summation and Payment Means

        // Multi VAT (Document Tax Summary)
        foreach ($tabTVA as $k => $v) {
            $code = "S";
            if ($k == 0) {
                $code = 'K';
            }
            $facturxpdf->addDocumentTax($code, "VAT", $v['totalHT'], $v['totalTVA'], $k);
        }

        // Set final summation details (totals, payable amount, prepaid amount)
        $facturxpdf
            ->setDocumentSummation(
                $grand_total_ttc,
                $grand_total_ttc - $prepaidAmount,
                $grand_total_ht,
                0.0,
                0.0,
                $grand_total_ht,
                $grand_total_tva,
                null,
                $prepaidAmount
            )
            ->addDocumentPaymentTerm(
                $langs->transnoentitiesnoconv("PaymentConditions") . ": " . $langs->transnoentitiesnoconv("PaymentCondition" . $object->cond_reglement_code),
                $ladatepaiement
            )
            ->addDocumentPaymentMean(
                $this->_get_paymentMean_number($object),
                $langs->transnoentitiesnoconv("PaymentType" . $object->mode_reglement_code),
                null,
                null,
                null,
                null,
                $this->_remove_spaces($account->iban),
                $account_proprio,
                $this->_remove_spaces($account->number),
                $this->_remove_spaces($account->bic)
            );

        // is there a billing period for that invoice ?
        //setDocumentBillingPeriod

        // Validate the generated XML document
        dol_syslog(get_class($this) . '::executeHooks try to validate XML');
        $pdfCheck = new ZugferdDocumentValidator($facturxpdf);
        $res = $pdfCheck->validateDocument();
        if (count($res) > 0) {
            $allErrors = "";
            foreach ($res as $error) {
                if (is_array($error)) {
                    $allErrors .= json_encode($error) . "\n";
                } else {
                    $allErrors .= $error . "\n";
                }
            }
            // if (empty(getDolGlobalString('FACTURX_USE_TRIGGER',''))) {
            setEventMessages($allErrors, [], 'errors');
            // }
            // $this->errors[] = json_encode($res);
            dol_syslog(get_class($this) . '::executeHooks  (1) : ' . $allErrors, LOG_ERR);
        } else {
            dol_syslog(get_class($this) . '::executeHooks XML validation ok');
        }

        // Generate file XML Factur-X
        $filename = dol_sanitizeFileName($invoice->ref);
		$filedir = getMultidirOutput($invoice, '', 1);
        $xmlfile = getMultidirOutput($invoice, '', 1, 'temp').'/'.$filename.'/factur-x.xml';	// Nameof file should be factur-x.xml so it will also have this nameonce added into PDF

        dol_mkdir(dirname($xmlfile));
		dol_delete_file($xmlfile);

        $facturxpdf->writeFile($xmlfile);

        return $xmlfile;
    }

    /**
     * Generate a complete Factur-X invoice file by embedding the XML
     * into a PDF.
     *
     * This function combines the invoice data with its corresponding XML
     * to produce a final hybrid document ready for exchange or archiving.
     *
     * @param object $invoice_id    Invoice ID to be processed.
     * @return string               -1 if ko, path if ok.
     */
    public function generateInvoice($invoice_id)
    {
        // Global variables declaration (typical for Dolibarr environment)
        global $langs, $db;

        dol_syslog(get_class($this) . '::generateInvoice');

        require_once DOL_DOCUMENT_ROOT."/compta/facture/class/facture.class.php";
        $invoice = new Facture($db);
	    $invoiceObject = $invoice->fetch((int) $invoice_id);

        if ($invoiceObject < 0) {
            dol_syslog(get_class($this) . "::generateInvoice failed to load invoice id=" . $invoice_id, LOG_ERR);
            setEventMessages($langs->trans("ErrorLoadingInvoice"), [], 'errors');
            return -1;
        }

        // Generate XML
        try {
	        $xmlfile = $this->generateXML($invoice);
        } catch(Exception $e) {
            dol_syslog(get_class($this) . "::generateInvoice failed to generate XML for invoice id=" . $invoice_id.". Error ".$e->getMessage(), LOG_ERR);
            setEventMessages($langs->trans("ErrorGeneratingXML").'. '.$e->getMessage(), [], 'errors');
            return -1;
        }

        if (empty($xmlfile) || !file_exists($xmlfile)) {
            dol_syslog(get_class($this) . "::generateInvoice failed to generate XML for invoice id=" . $invoice_id, LOG_ERR);
            setEventMessages($langs->trans("ErrorGeneratingXML"), [], 'errors');
            return -1;
        }


        // Load PDPConnectFR specific translations
        $langs->loadLangs(array("admin", "pdpconnectfr@pdpconnectfr"));

        $filename = dol_sanitizeFileName($invoice->ref);
		$filedir = getMultidirOutput($invoice, '', 1);
        $orig_pdf = $filedir.'/'.$filename.'.pdf';

        // Make a copy of the original PDF file
        $pathfacturxpdf = $filedir.'/'.$filename.'_facturx.pdf';	// The new name of the PDF including xml
        if (dol_copy($orig_pdf, $pathfacturxpdf)) {
            dol_syslog(get_class($this) . "::executeHooks copied original PDF to " . $pathfacturxpdf);
            $orig_pdf = $pathfacturxpdf;
        } else {
            dol_syslog(get_class($this) . "::executeHooks failed to copy original PDF to " . $pathfacturxpdf, LOG_ERR);
            return -1;
        }


        // Initial PDF File Pre-check ---
        $precheck = false;
        if (file_exists($orig_pdf) && is_readable($orig_pdf)) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if (finfo_file($finfo, $orig_pdf) == 'application/pdf') {
                $precheck = true;
            }
        }

        // Check if the source PDF is valid, log error and exit if not.
        if ($precheck == false) {
            dol_syslog(get_class($this) . "::executeHooks orig pdf file does not exists, can't create facturX");
            return -1;
        }

        clearstatcache(true);


        // Embed XML into PDF using FPDI and save
        require_once DOL_DOCUMENT_ROOT.'/core/lib/pdf.lib.php';
        require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';

        $pdf = pdf_getInstance();
        $pagecount = $pdf->setSourceFile($orig_pdf);

        // import all pages of the original PDF
        for ($i = 1; $i <= $pagecount; $i++) {
            $tpl = $pdf->importPage($i);
            $pdf->addPage();
            $pdf->useTemplate($tpl);
        }

        // Embed the XML file as a file attachment in the PDF
        if (file_exists($xmlfile)) {
            $pdf->Annotation(0, 0, 0, 0, '', array(
                'Subtype' => 'FileAttachment',
                'Name' => 'PushPin',
                'FS' => $xmlfile
            ));
        }

        // Save the final PDF with the embedded XML
        $pdf->Output($orig_pdf, 'F');

        // Clean up the temporary XML file
        if (file_exists($xmlfile) && !getDolGlobalString('PDPCONNECTFR_DEBUG_MODE')) {
            dol_delete_file($xmlfile);
            dol_syslog(get_class($this) . '::generateInvoice cleaned up temporary XML file: ' . $xmlfile);
        }

        return 1;


        // Saving ---
        // Embed XML into PDF and save using horstoeko method
        /*$pdfBuilder = new ZugferdDocumentPdfBuilder($facturxpdf, $orig_pdf);
        $pdfBuilder->generateDocument();

        $new_pdf = $orig_pdf;
        if (getDolGlobalString('FACTURX_SUFFIX_ENABLE', '') != '') {
            $suffix = getDolGlobalString('FACTURX_SUFFIX_CUSTOM', '_facturx');
            $new_pdf = \str_replace('.pdf', $suffix . '.pdf', $orig_pdf);
        }

        $pdfBuilder->saveDocument($new_pdf);
        dol_syslog(\get_class($this) . '::executeHooks save facturx document to : ' . $new_pdf . ', checksum : ' . \sha1_file($new_pdf));

        // Rename if no suffix is used
        if (empty(getDolGlobalString('FACTURX_SUFFIX_ENABLE', '')) && \file_exists($new_pdf)) {
            \rename($new_pdf, $orig_pdf);
        }

        \clearstatcache(\true);

        // dol_syslog(get_class($this) . '::executeHooks end action=' . $action . ', file saved as ' . $new_pdf);
        return $ret;*/
    }

    /**
     * Generate a sample Factur-X invoice for demonstration or testing purposes.
     *
     * This method creates a dummy invoice with representative data
     * to illustrate the Factur-X structure without using real business information.
     *
     * @return string Path or content of the generated sample invoice.
     */
    public function generateSampleInvoice()
    {
    	global $conf;

		dol_mkdir($conf->pdpconnectfr->dir_temp);

        require __DIR__ . "/ExampleHelpers.php";

        $existingPdfFilename = __DIR__ . "/../../assets/00_ZugferdDocumentPdfBuilder_PrintLayout.pdf";
        $newPdfFilename = $conf->pdpconnectfr->dir_temp."/02_ZugferdDocumentPdfBuilder_PrintLayout_Merged.pdf";
        $AdditionalDocument = __DIR__ . "/../../assets/00_AdditionalDocument.csv";

        // First we create a new valid document in EN16931-Profile (== COMFORT-Profile)
        // See examples/01_ZugferdDocumentBuilder_EN16931.php for detailed explanations

        $documentBuilder = ZugferdDocumentBuilder::createNew(ZugferdProfiles::PROFILE_EN16931);

        $documentBuilder->setDocumentInformation(
            'R-2024/00001',                                     // Invoice Number (BT-1)
            ZugferdInvoiceType::INVOICE,                        // Type "Invoice" (BT-3)
            DateTime::createFromFormat("Ymd", "20241231"),      // Invoice Date (BT-2)
            ZugferdCurrencyCodes::EURO                          // Invoice currency is EUR (Euro) (BT-5)
        );

        $documentBuilder->addDocumentNote('Lieferant GmbH' . PHP_EOL . 'Lieferantenstraße 20' . PHP_EOL . '80333 München' . PHP_EOL . 'Deutschland' . PHP_EOL . 'Geschäftsführer: Hans Muster' . PHP_EOL . 'Handelsregisternummer: H A 123' . PHP_EOL . PHP_EOL, null, 'REG');
        $documentBuilder->setDocumentBillingPeriod(DateTime::createFromFormat("Ymd", "20250101"), DateTime::createFromFormat("Ymd", "20250131"), "01.01.2025 - 31.01.2025");
        $documentBuilder->addDocumentInvoiceSupportingDocumentWithUri('REFDOC-2024/00001-1', 'http.//some.url', 'Inhaltsstoffe Joghurt');
        $documentBuilder->addDocumentInvoiceSupportingDocumentWithFile('REFDOC-2024/00001-2', $AdditionalDocument, 'Herkunftsnachweis Trennblätter');
        $documentBuilder->addDocumentTenderOrLotReferenceDocument('LOS 738625');
        $documentBuilder->addDocumentInvoicedObjectReferenceDocument('125', ZugferdReferenceCodeQualifiers::SALE_PERS_NUMB); // Sales person number
        $documentBuilder->setDocumentContractReferencedDocument('CON-2024/2025-001');
        $documentBuilder->setDocumentProcuringProject('PROJ-2025-001-1', 'Allgemeine Dienstleistungen');
        $documentBuilder->addDocumentPaymentMeanToDirectDebit("DE12500105170648489890", "R-2024/00001");
        $documentBuilder->addDocumentPaymentTerm('Wird von Konto DE12500105170648489890 abgebucht', DateTime::createFromFormat("Ymd", "20250131"), 'MANDATE-2024/000001');
        $documentBuilder->setDocumentSeller("Lieferant GmbH", "549910");
        $documentBuilder->addDocumentSellerGlobalId("4000001123452", "0088");
        $documentBuilder->addDocumentSellerTaxNumber("201/113/40209");
        $documentBuilder->addDocumentSellerVATRegistrationNumber("DE123456789");
        $documentBuilder->setDocumentSellerAddress("Lieferantenstraße 20", "", "", "80333", "München", ZugferdCountryCodes::GERMANY);
        $documentBuilder->setDocumentSellerContact("H. Müller", "Verkauf", "+49-111-2222222", "+49-111-3333333", "hm@lieferant.de");
        $documentBuilder->setDocumentSellerCommunication(ZugferdElectronicAddressScheme::UNECE3155_EM, 'sales@lieferant.de');
        $documentBuilder->setDocumentBuyer("Kunden AG Mitte", "GE2020211");
        $documentBuilder->setDocumentBuyerAddress("Kundenstraße 15", "", "", "69876", "Frankfurt", ZugferdCountryCodes::GERMANY);
        $documentBuilder->setDocumentBuyerContact("H. Meier", "Einkauf", "+49-333-4444444", "+49-333-5555555", "hm@kunde.de");
        $documentBuilder->setDocumentBuyerCommunication(ZugferdElectronicAddressScheme::UNECE3155_EM, 'purchase@kunde.de');
        $documentBuilder->setDocumentPayee('Kunden AG Zahlungsdienstleistung');
        $documentBuilder->setDocumentBuyerOrderReferencedDocument("PO-2024-0003324");
        $documentBuilder->setDocumentSellerOrderReferencedDocument('SO-2024-000993337');
        $documentBuilder->setDocumentShipTo("Kunden AG Ost");
        $documentBuilder->setDocumentShipToAddress("Lieferstraße 1", "", "", "04109", "Leipzig", ZugferdCountryCodes::GERMANY);
        $documentBuilder->setDocumentSupplyChainEvent(DateTime::createFromFormat("Ymd", "20250115"));
        $documentBuilder->addNewPosition("1");
        $documentBuilder->setDocumentPositionProductDetails("Trennblätter A4", "50er Pack", "TB100A4");
        $documentBuilder->setDocumentPositionNetPrice(9.9000);
        $documentBuilder->setDocumentPositionQuantity(20, ZugferdUnitCodes::REC20_PIECE);
        $documentBuilder->addDocumentPositionTax(ZugferdVatCategoryCodes::STAN_RATE, ZugferdVatTypeCodes::VALUE_ADDED_TAX, 19);
        $documentBuilder->setDocumentPositionLineSummation(198.0);
        $documentBuilder->addNewPosition("2");
        $documentBuilder->setDocumentPositionProductDetails("Joghurt Banane", "B-Ware", "ARNR2");
        $documentBuilder->setDocumentPositionNetPrice(5.5000);
        $documentBuilder->setDocumentPositionQuantity(50, ZugferdUnitCodes::REC20_PIECE);
        $documentBuilder->addDocumentPositionTax(ZugferdVatCategoryCodes::STAN_RATE, ZugferdVatTypeCodes::VALUE_ADDED_TAX, 7);
        $documentBuilder->setDocumentPositionLineSummation(275.0);
        $documentBuilder->addNewPosition("3");
        $documentBuilder->setDocumentPositionProductDetails("Joghurt Erdbeer", "", "ARNR3");
        $documentBuilder->setDocumentPositionNetPrice(4.0000);
        $documentBuilder->setDocumentPositionQuantity(100, ZugferdUnitCodes::REC20_PIECE);
        $documentBuilder->addDocumentPositionTax(ZugferdVatCategoryCodes::STAN_RATE, ZugferdVatTypeCodes::VALUE_ADDED_TAX, 7);
        $documentBuilder->setDocumentPositionLineSummation(400.0);
        $documentBuilder->addDocumentTax(ZugferdVatCategoryCodes::STAN_RATE, ZugferdVatTypeCodes::VALUE_ADDED_TAX, 198.0, 37.62, 19.0);
        $documentBuilder->addDocumentTax(ZugferdVatCategoryCodes::STAN_RATE, ZugferdVatTypeCodes::VALUE_ADDED_TAX, 675.0, 47.25, 7.0);
        $documentBuilder->setDocumentSummation(957.87, 957.87, 873.00, 0.0, 0.0, 873.00, 84.87);

        // Next let's do the ZugferddocumentPdfBuilder it's job - let's attach the XML to the PDF. The attachment filename will be factur-x.xml
        // since whe choosed the profile EN16931 in the ZugferdDocumentBuilder (see above)
        // In the following there are multiple methods how you can build a conform PDF from an existing print layout

        // First method: Merge the generated XML from ZugferdDocumentBuilder with an existing print layout file to a new PDF file

        $zugferdDocumentPdfBuilder = ZugferdDocumentPdfBuilder::fromPdfFile($documentBuilder, $existingPdfFilename);
        $zugferdDocumentPdfBuilder->generateDocument();
        $zugferdDocumentPdfBuilder->saveDocument($newPdfFilename);

        // Second method: Merge the generated XML from ZugferdDocumentBuilder with an stream (string) which contains the PDF to a new PDF file
        // Note: We simulate the PDF stream (string) by calling file_get_contents.

        $pdfContent = file_get_contents($existingPdfFilename);

        $zugferdDocumentPdfBuilder = ZugferdDocumentPdfBuilder::fromPdfString($documentBuilder, $pdfContent);
        $zugferdDocumentPdfBuilder->generateDocument();
        $zugferdDocumentPdfBuilder->saveDocument($newPdfFilename);

        // There is not only the saveDocument method of the ZugferdDocumentPdfBuilder. It is also possible to receive the merged
        // content (PDF with embedded XML) as a stream (string)

        $mergedPdfContent = $zugferdDocumentPdfBuilder->downloadString();

        // If you would like to brand the merged PDF with the name of you own solution you can call
        // the method setAdditionalCreatorTool. Before calling this method the creator of the PDF is identified as 'Factur-X library 1.x.x by HorstOeko'.
        // After calling this method you get 'MyERPSolution 1.0 / Factur-X PHP library 1.x.x by HorstOeko' as the creator

        $zugferdDocumentPdfBuilder = ZugferdDocumentPdfBuilder::fromPdfString($documentBuilder, $pdfContent);
        $zugferdDocumentPdfBuilder->setAdditionalCreatorTool('MyERPSolution 1.0');
        $zugferdDocumentPdfBuilder->generateDocument();
        $zugferdDocumentPdfBuilder->saveDocument($newPdfFilename);

        // And last but not least, it is also possible to add additional attachments to the merged PDF. These can be any files that can help the invoice
        // recipient with processing. For example, a time sheet as an Excel file would be conceivable.
        // The method attachAdditionalFileByRealFile has 3 parameters:
        // - The file to attach which must exist and must be readable
        // - (Optional) A name to display in the attachments of the PDF
        // - (Optional) The type of the relationship of the attachment. Valid values are defined in the class ZugferdDocumentPdfBuilderAbstract. The constants are starting with AF_
        // If you omit the last 2 parameters the following will happen:
        // - The displayname is calculated from the filename you specified
        // - The type of the relationship of the attachment will be AF_RELATIONSHIP_SUPPLEMENT (Supplement)

        $zugferdDocumentPdfBuilder = ZugferdDocumentPdfBuilder::fromPdfString($documentBuilder, $pdfContent);
        $zugferdDocumentPdfBuilder->attachAdditionalFileByRealFile($AdditionalDocument, "Some display Name", ZugferdDocumentPdfBuilderAbstract::AF_RELATIONSHIP_SUPPLEMENT);
        $zugferdDocumentPdfBuilder->generateDocument();
        $zugferdDocumentPdfBuilder->saveDocument($newPdfFilename);

        // You can also add an attachment to the PDF as an stream (string). The conditions are the same as above for the attachAdditionalFileByRealFile method
        // The only difference to attachAdditionalFileByRealFile is that the attachAdditionalFileByContent method accepts 4 parameters, whereby here (as with attachAdditionalFileByRealFile)
        // the last two can be omitted. You only need to specify a file name under which the file is to be embedded
        // Note: We simulate the attachment stream (string) by calling file_get_contents.

        $attachmentContent = file_get_contents($AdditionalDocument);

        $zugferdDocumentPdfBuilder = ZugferdDocumentPdfBuilder::fromPdfString($documentBuilder, $pdfContent);
        $zugferdDocumentPdfBuilder->attachAdditionalFileByContent($attachmentContent, 'additionalDocument.csv', "Some other display Name", ZugferdDocumentPdfBuilderAbstract::AF_RELATIONSHIP_SUPPLEMENT);
        $zugferdDocumentPdfBuilder->generateDocument();
        $zugferdDocumentPdfBuilder->saveDocument($newPdfFilename);

        // Set values for metadata-fields
        // We can change some meta information such as the title, the subject, the author and the keywords.  This library essentially provides 4 methods for this.
        // These methods use so-called templates. These methods are:

        $zugferdDocumentPdfBuilder = ZugferdDocumentPdfBuilder::fromPdfFile($documentBuilder, $existingPdfFilename);
        $zugferdDocumentPdfBuilder->setAuthorTemplate('.....');
        $zugferdDocumentPdfBuilder->setTitleTemplate('.....');
        $zugferdDocumentPdfBuilder->setSubjectTemplate('.....');
        $zugferdDocumentPdfBuilder->setKeywordTemplate('.....');
        $zugferdDocumentPdfBuilder->generateDocument();
        $zugferdDocumentPdfBuilder->saveDocument($newPdfFilename);

        // The 4 methods just mentioned accept a free text that can accept the following placeholders:
        // - %1$s .... contains the invoice number (is extracted from the XML data)
        // - %2$s .... contains the type of XML document, such as ‘Invoice’ (is extracted from the XML data)
        // - %3$s .... contains the name of the seller (extracted from the XML data)
        // - %4$s .... contains the invoice date (extracted from the XML data)
        // The following example generates...
        // - the author:  .... Issued by seller with name Lieferant GmbH
        // - the title    .... Lieferant GmbH : Invoice R-2024/00001
        // - the subject  .... Invoice-Document, Issued by Lieferant GmbH
        // - the keywords .... R-2024/00001, Invoice, Lieferant GmbH, 2024-12-31

        $zugferdDocumentPdfBuilder = ZugferdDocumentPdfBuilder::fromPdfFile($documentBuilder, $existingPdfFilename);
        $zugferdDocumentPdfBuilder->setAuthorTemplate('Issued by seller with name %3$s');
        $zugferdDocumentPdfBuilder->setTitleTemplate('%3$s : %2$s %1$s');
        $zugferdDocumentPdfBuilder->setSubjectTemplate('%2$s-Document, Issued by %3$s');
        $zugferdDocumentPdfBuilder->setKeywordTemplate('%1$s, %2$s, %3$s, %4$s');
        $zugferdDocumentPdfBuilder->generateDocument();
        $zugferdDocumentPdfBuilder->saveDocument($newPdfFilename);

        // If the previously mentioned options for manipulating the meta information are not sufficient,
        // you can also use a callback function. The following 4 parameters are passed to the callback
        // function in the specified order:
        // - $which               .... one of "author", "title", "subject" and "keywords"
        // - $xmlContent          .... the content of the xml as a string
        // - $invoiceInformation  .... an array with some information about the invoice
        // - $default             .... The default value for the specified field (see $which

        $zugferdDocumentPdfBuilder = ZugferdDocumentPdfBuilder::fromPdfFile($documentBuilder, $existingPdfFilename);
        $zugferdDocumentPdfBuilder->setMetaInformationCallback(
            function ($which) {
                if ($which === 'title') {
                    return "DummyTitle";
                }

                if ($which === 'author') {
                    return "DummyAuthor";
                }

                if ($which === 'subject') {
                    return "DummySubject";
                }

                if ($which === 'keywords') {
                    return "DummyKeywords";
                }
            }
        );
        $zugferdDocumentPdfBuilder->generateDocument();
        $zugferdDocumentPdfBuilder->saveDocument($newPdfFilename);

        // To remove the callback you can call the setMetaInformationCallback
        // method with a null value

        $zugferdDocumentPdfBuilder = ZugferdDocumentPdfBuilder::fromPdfFile($documentBuilder, $existingPdfFilename);
        $zugferdDocumentPdfBuilder->setMetaInformationCallback(null);
        $zugferdDocumentPdfBuilder->generateDocument();
        $zugferdDocumentPdfBuilder->saveDocument($newPdfFilename);

        return $newPdfFilename;
    }


    /**
     * Create a supplier invoice from a Factur-X file.
     *
     * @param  string $file                         Content of Factur-X file.
     * @param  string|null $ReadableViewFile        Readable view file. (PDP Generated readable PDF)
     * @param  string $flowId                       Flow identifier source of the invoice.
     *
     * @return array{res:int, message:string, action:string|null}       Returns array with 'res' (1 on success, 0 already exists, -1 on failure) with a 'message' and an optional 'action'.
     */
    public function createSupplierInvoiceFromFacturX($file, $ReadableViewFile = null, $flowId = '')
    {
        global $conf, $db, $user;
        $return_messages = array();

        // Save uploaded file to temporary directory
        $tempDir = $conf->pdpconnectfr->dir_temp;
        if (!dol_is_dir($tempDir)) {
            dol_mkdir($tempDir);
        }

        // If tmp dir in not empty, clean it
        $files = scandir($tempDir);
        foreach ($files as $f) {
            if ($f != '.' && $f != '..') {
                dol_delete_file($tempDir . '/' . $f);
            }
        }

        $tempFile = $tempDir . '/facturx.pdf';
        if (file_put_contents($tempFile, $file) === false) {
            return ['res' => -1, 'message' => 'Failed to save Factur-X file to temporary location' ];
        }

        if ($ReadableViewFile) {
            $tempFileReadableView = $tempDir . '/facturx_readable.pdf';
            if (file_put_contents($tempFileReadableView, $ReadableViewFile) === false) {
                return ['res' => -1, 'message' => 'Failed to save readable view file to temporary location' ];
            }
        }

        //return ['res' => 1, 'message' => 'bypass' ];

        // --- Create Supplier Invoice object
        require_once DOL_DOCUMENT_ROOT . '/fourn/class/fournisseur.facture.class.php';
        $supplierInvoice = new FactureFournisseur($db);


        // --- Read the Factur-X file
        $document = ZugferdDocumentPdfReader::readAndGuessFromFile($tempFile);

        $document->getDocumentInformation($documentno, $documenttypecode, $documentdate, $invoiceCurrency, $taxCurrency, $documentname, $documentlanguage, $effectiveSpecifiedPeriod);

        $document->getDocumentSupplyChainEvent(
            $documentDeliveryDate
        );

        // Get seller information (supplier)
        $document->getDocumentSeller($sellername, $sellerids, $sellerdescription);

        // Get seller address
        $document->getDocumentSellerAddress(
            $sellerlineone,
            $sellerlinetwo,
            $sellerlinethree,
            $sellerpostcode,
            $sellercity,
            $sellercountry,
            $sellersubdivision
        );

        // Get seller contact
        $document->getDocumentSellerContact(
            $sellercontactpersonname,
            $sellercontactdepartmentname,
            $sellercontactphoneno,
            $sellercontactfaxno,
            $sellercontactemailaddr
        );

        $document->getDocumentSellerCommunication(
            $sellerCommunicationUriScheme,
            $sellerCommunicationUri
        );

        // Get document summation
        $document->getDocumentSummation($grandTotalAmount, $duePayableAmount, $lineTotalAmount, $chargeTotalAmount, $allowanceTotalAmount, $taxBasisTotalAmount, $taxTotalAmount, $roundingAmount, $totalPrepaidAmount);

        $document->getDocumentSellerGlobalId(
            $sellerGlobalIds
        );

        $document->getDocumentSellerTaxRegistration(
            $sellerTaxRegistations
        );

        // Debug: print all retrieved variables
        $parsedData = array(
            'documentno' => $documentno ?? null,
            'documenttypecode' => $documenttypecode ?? null,
            'documentdate' => isset($documentdate) && $documentdate instanceof DateTime ? $documentdate->format('Y-m-d') : ($documentdate ?? null),
            'invoiceCurrency' => $invoiceCurrency ?? null,
            'taxCurrency' => $taxCurrency ?? null,
            'documentname' => $documentname ?? null,
            'documentlanguage' => $documentlanguage ?? null,
            'effectiveSpecifiedPeriod' => $effectiveSpecifiedPeriod ?? null,
            'documentDeliveryDate' => isset($documentDeliveryDate) && $documentDeliveryDate instanceof DateTime ? $documentDeliveryDate->format('Y-m-d') : ($documentDeliveryDate ?? null),

            // Seller
            'sellername' => $sellername ?? null,
            'sellerids' => $sellerids ?? null,
            'sellerdescription' => $sellerdescription ?? null,

            // Seller Address
            'sellerlineone' => $sellerlineone ?? null,
            'sellerlinetwo' => $sellerlinetwo ?? null,
            'sellerlinethree' => $sellerlinethree ?? null,
            'sellerpostcode' => $sellerpostcode ?? null,
            'sellercity' => $sellercity ?? null,
            'sellercountry' => $sellercountry ?? null,
            'sellersubdivision' => $sellersubdivision ?? null,

            // Seller Contact
            'sellercontactpersonname' => $sellercontactpersonname ?? null,
            'sellercontactdepartmentname' => $sellercontactdepartmentname ?? null,
            'sellercontactphoneno' => $sellercontactphoneno ?? null,
            'sellercontactfaxno' => $sellercontactfaxno ?? null,
            'sellercontactemailaddr' => $sellercontactemailaddr ?? null,

            // Seller Communication (may be unset due to reader var name)
            'sellerCommunicationUriScheme' => $sellerCommunicationUriScheme ?? null,
            'sellerCommunicationUri' => $sellerCommunicationUri ?? null,

            // Summation
            'grandTotalAmount' => $grandTotalAmount ?? null,
            'duePayableAmount' => $duePayableAmount ?? null,
            'lineTotalAmount' => $lineTotalAmount ?? null,
            'chargeTotalAmount' => $chargeTotalAmount ?? null,
            'allowanceTotalAmount' => $allowanceTotalAmount ?? null,
            'taxBasisTotalAmount' => $taxBasisTotalAmount ?? null,
            'taxTotalAmount' => $taxTotalAmount ?? null,
            'roundingAmount' => $roundingAmount ?? null,
            'totalPrepaidAmount' => $totalPrepaidAmount ?? null,

            // Seller Global Ids and Tax Registrations (may be unset due to reader var name)
            'sellerGlobalIds' => $sellerGlobalIds ?? null,
            'sellerTaxRegistations' => $sellerTaxRegistations ?? null,
        );

        // Check if this invoice has already been imported
        $sql = "SELECT rowid as id FROM " . MAIN_DB_PREFIX . "facture_fourn";
        $sql .= " WHERE ref_supplier = '" . $db->escape($documentno) . "'";
        $resql = $db->query($sql);
        if ($resql) {
            if ($db->num_rows($resql) > 0) {
                $supplierInvoiceId = $db->fetch_object($resql)->id;
                $pdpconnectfr = new PdpConnectFr($db);
                $pdpconnectfr->cleanUpTemporaryFiles(); // Clean up temp files to remove retrieved Factur-X file since invoice already exists
                return ['res' => $supplierInvoiceId, 'message' => 'Supplier Invoice with reference ' . $documentno . ' already exists' ];
            }
        } else {
            return ['res' => -1, 'message' => 'Database error while checking existing supplier invoice: ' . $db->lasterror() ];
        }

        /*print "<pre>";
        print_r($parsedData);
        print "</pre>";*/
        dol_syslog(get_class($this) . '::createSupplierInvoiceFromFacturX parsedData: ' . json_encode($parsedData));

        // Sync or create supplier based on seller info
        $syncSocRes = $this->_syncOrCreateThirdpartyFromFacturXSeller($parsedData, 'dolibarr', $flowId);
        $socId = $syncSocRes['res'];
        $return_messages[] = $syncSocRes['message'];
        $action = $syncSocRes['action'] ?? null;
        if ($socId < 0) {
            return ['res' => -1, 'message' => 'Thirdparty sync or creation error: ' . implode("\n", $return_messages) , 'action' => $action ];
        }

        // Load supplier (thirdparty)
        require_once DOL_DOCUMENT_ROOT . '/fourn/class/fournisseur.class.php';
        $supplier = new Fournisseur($db);
        if ($supplier->fetch($socId) < 0) {
            return ['res' => -1, 'message' => 'Failed to load supplier id ' . $socId];
        }

        // Set supplier reference
        $supplierInvoice->socid = $socId;

        // Set basic invoice information
        $supplierInvoice->ref_supplier = $documentno;
        $supplierInvoice->type = $this->_getDolibarrInvoiceType($documenttypecode);
        if ($supplierInvoice->type === '-1') {
            return ['res' => -1, 'message' => 'Unfounded dolibarr corresponding Invoice code for document type code: ' . $documenttypecode ];
        }
        $supplierInvoice->date = isset($documentdate) && $documentdate instanceof DateTime ? $documentdate->format('Y-m-d') : null;


        // Set currency
        $supplierInvoice->multicurrency_code = $invoiceCurrency;

        // Set import_key
        $supplierInvoice->import_key = AbstractPDPProvider::$PDPCONNECTFR_LAST_IMPORT_KEY;

        // Add invoice lines
        if ($document->firstDocumentPosition()) {
            do {
                // Get line information
                $document->getDocumentPositionGenerals($lineid, $linestatuscode, $linestatusreasoncode);
                $document->getDocumentPositionProductDetails($prodname, $proddesc, $prodsellerid, $prodbuyerid, $prodglobalidtype, $prodglobalid);
                $document->getDocumentPositionGrossPrice($grosspriceamount, $grosspricebasisquantity, $grosspricebasisquantityunitcode);
                $document->getDocumentPositionNetPrice($netpriceamount, $netpricebasisquantity, $netpricebasisquantityunitcode);
                $document->getDocumentPositionLineSummation($lineTotalAmount, $totalAllowanceChargeAmount);
                $document->getDocumentPositionQuantity($billedquantity, $billedquantityunitcode, $chargeFreeQuantity, $chargeFreeQuantityunitcode, $packageQuantity, $packageQuantityunitcode);

                // Get tax information for the line
                $vatRate = 0;
                if ($document->firstDocumentPositionTax()) {
                    $document->getDocumentPositionTax($categoryCode, $typeCode, $rateApplicablePercent, $calculatedAmount, $exemptionReason, $exemptionReasonCode);
                    $vatRate = $rateApplicablePercent;
                }

                $productRetrievedData = array(
                    'lineid' => $lineid ?? null,
                    'linestatuscode' => $linestatuscode ?? null,
                    'linestatusreasoncode' => $linestatusreasoncode ?? null,
                    'prodname' => $prodname ?? null,
                    'proddesc' => $proddesc ?? null,
                    'prodsellerid' => $prodsellerid ?? null,
                    'prodbuyerid' => $prodbuyerid ?? null,
                    'prodglobalidtype' => $prodglobalidtype ?? null,
                    'prodglobalid' => $prodglobalid ?? null,
                    'grosspriceamount' => $grosspriceamount ?? null,
                    'grosspricebasisquantity' => $grosspricebasisquantity ?? null,
                    'grosspricebasisquantityunitcode' => $grosspricebasisquantityunitcode ?? null,
                    'netpriceamount' => $netpriceamount ?? null,
                    'netpricebasisquantity' => $netpricebasisquantity ?? null,
                    'netpricebasisquantityunitcode' => $netpricebasisquantityunitcode ?? null,
                    'lineTotalAmount' => $lineTotalAmount ?? null,
                    'totalAllowanceChargeAmount' => $totalAllowanceChargeAmount ?? null,
                    'billedquantity' => $billedquantity ?? null,
                    'billedquantityunitcode' => $billedquantityunitcode ?? null,
                    'chargeFreeQuantity' => $chargeFreeQuantity ?? null,
                    'chargeFreeQuantityunitcode' => $chargeFreeQuantityunitcode ?? null,
                    'packageQuantity' => $packageQuantity ?? null,
                    'packageQuantityunitcode' => $packageQuantityunitcode ?? null,
                    // Tax
                    'categoryCode' => $categoryCode ?? null,
                    'typeCode' => $typeCode ?? null,
                    'rateApplicablePercent' => $rateApplicablePercent ?? null,
                    'calculatedAmount' => $calculatedAmount ?? null,
                    'exemptionReason' => $exemptionReason ?? null,
                    'exemptionReasonCode' => $exemptionReasonCode ?? null,
                    // Parent invoice ref
                    'parentDocumentNo' => $documentno ?? null,
                );



                /*print "<pre>";
                print_r($productRetrievedData);
                print "</pre>";*/
                dol_syslog(get_class($this) . '::createSupplierInvoiceFromFacturX productRetrievedData: ' . json_encode($productRetrievedData));

                // TODO : Add a parameter to choose between sync or create invoice with a free text line (no product)
                // Sync or create product
                $res = $this->_findOrCreateProductFromFacturXLine($productRetrievedData, $flowId);
                if ($res['res'] < 0) {
                    return ['res' => -1, 'message' => 'Product sync or creation error: ' . $res['message'], 'action' => $res['action'] ?? null ];
                }
                /*print "<pre>";
                print_r($res);
                print "</pre>";*/
                $productId = $res['res'];


                // Add line to invoice
                $line = new SupplierInvoiceLine($db);
                //$line->desc = $prodname . (!empty($proddesc) ? "\n" . $proddesc : '');
                $line->fk_product = $productId;
                $line->qty = $billedquantity;
                $line->subprice = $netpriceamount;
                $line->tva_tx = $vatRate;
                $line->total_ht = $lineTotalAmount;
                $line->total_tva = $calculatedAmount ?? 0;
                $line->total_ttc = $lineTotalAmount + ($calculatedAmount ?? 0);

                $supplierInvoice->lines[] = $line;

            } while ($document->nextDocumentPosition());
        }

        //return ['res' => 1, 'message' => 'Not implemented yet' ];

        // Set invoice totals
        $supplierInvoice->total_ht = $taxBasisTotalAmount;
        $supplierInvoice->total_tva = $taxTotalAmount;
        $supplierInvoice->total_ttc = $grandTotalAmount;

        // Add a note about PDP import ( TODO: add a hook or extrafields to store import details)
        $supplierInvoice->note_private = "Imported from PDP";

        // Create the invoice
        $result = $supplierInvoice->create($user);

        if ($result < 0) {
            return ['res' => -1, 'message' => 'Invoice creation error: ' . $supplierInvoice->error];
        } else {

            // Update thirdparty as a supplier if not already the case
            if ($supplier->fournisseur != 1) {
                $supplier->fournisseur = 1;
                $supplier->code_fournisseur = 'auto';
                $supplier->update($supplier->id, $user);
            }

            // TODO : Add supplier price for products (all lines of the invoice)

            // Set import_key
            $sql = 'UPDATE '.MAIN_DB_PREFIX."facture_fourn SET import_key = '".$db->escape($supplierInvoice->import_key)."' WHERE rowid = ".((int) $result);
			$db->query($sql);

            // Add entry in pdpconnectfr_extlinks table to mark that this supplier invoice is imported from PDP
            $pdpconnectfr = new PdpConnectFr($db);
            $pdpconnectfr->insertOrUpdateExtLink($result, $supplierInvoice->element, $flowId);

            dol_syslog(__METHOD__ . ' New supplier invoice created (ID: ' . $result . ')');

            $return_messages[] = 'Supplier Invoice created with ID: ' . $result;

            // Save original invoice in supplier invoice attachments
            if ($tempFile && file_exists($tempFile)) {
                $res = $this->_saveFacturXFileToSupplierInvoiceAttachment($supplierInvoice, $tempFile);

                if ($res['res'] < 0) {
                    $return_messages[] = 'Failed to save Factur-X file as attachment: ' . $res['message'];
                } else {
                    $return_messages[] = 'Factur-X file saved as attachment';
                }
            } else {
                dol_syslog("Temporary file not found for attachment", LOG_ERR);
            }

            // Save readable view file in supplier invoice attachments
            if ($ReadableViewFile && $tempFileReadableView && file_exists($tempFileReadableView)) {
                $res = $this->_saveFacturXFileToSupplierInvoiceAttachment($supplierInvoice, $tempFileReadableView, 'PDP');

                if ($res['res'] < 0) {
                    $return_messages[] = 'Failed to save readable view file as attachment: ' . $res['message'];
                } else {
                    $return_messages[] = 'Readable view file saved as attachment';
                }
            } else {
                dol_syslog("Temporary file not found for attachment", LOG_ERR);
            }

            // TODO : Save receivedFile in supplier invoice attachments
            return ['res' => $result, 'message' => implode("\n", $return_messages) ];
        }
    }


    /**
     * determines the delivery dates and the corresponding order numbers within two arrays
     *
     * @param Array   $customerOrderReferenceList  array to store the corresponding order ids as strings
     * @param Array   $deliveryDateList            array to store the corresponding delivery dates as string in format YYYY-MM-DD
     * @param Facture $object invoice              object
     */
    private function _determineDeliveryDatesAndCustomerOrderNumbers(&$customerOrderReferenceList, &$deliveryDateList, $object)
    {
        // TODO: move this function to class utils
        $object->fetchObjectLinked();
        // check for delivery notes and correponding real delivery dates
        if (isset($object->linkedObjectsIds['shipping']) && \is_array($object->linkedObjectsIds['shipping'])) {
            foreach ($object->linkedObjectsIds['shipping'] as $expeditionId) {
                $expedition = new \Expedition($this->db);
                $expeditionFetchResult = $expedition->fetch($expeditionId);
                if ($expeditionFetchResult > 0) {
                    if (!empty($expedition->origin) && $expedition->origin == "commande" && !empty($expedition->origin_id)) {
                        $commande = new \Commande($this->db);
                        $commandeFetchResult = $commande->fetch($expedition->origin_id);
                        if ($commandeFetchResult > 0 && !empty($commande->ref_client)) {
                            $customerOrderReferenceList[] = $commande->ref_client;
                        }
                    }
                    if (!empty($expedition->date_delivery)) {
                        $deliveryDateList[] = \date('Y-m-d', $expedition->date_delivery);
                    }
                }
            }
        }
        // if delivery notes are linked and take the real delivery date from there. if no delivery notes are available,
        // take delivery date from order.
        if (isset($object->linkedObjectsIds['commande']) && \is_array($object->linkedObjectsIds['commande'])) {
            foreach ($object->linkedObjectsIds['commande'] as $commandeId) {
                $commande = new \Commande($this->db);
                $commandeFetchResult = $commande->fetch($commandeId);
                if ($commandeFetchResult > 0) {
                    if (!empty($commande->ref_client)) {
                        $customerOrderReferenceList[] = $commande->ref_client;
                    }
                    $commande->fetchObjectLinked();
                    $found = 0;
                    if (!empty($commande->linkedObjectsIds) && !empty($commande->linkedObjectsIds['shipping']) && \count($commande->linkedObjectsIds['shipping']) > 0) {
                        foreach ($commande->linkedObjectsIds['shipping'] as $expeditionId) {
                            $expedition = new \Expedition($this->db);
                            $expeditionFetchResult = $expedition->fetch($expeditionId);
                            if ($expeditionFetchResult > 0) {
                                if (!empty($expedition->date_delivery)) {
                                    $found++;
                                    $deliveryDateList[] = \date('Y-m-d', $expedition->date_delivery);
                                }
                            }
                        }
                    }
                    if ($found == 0) {
                        if (!empty($commande->delivery_date)) {
                            $deliveryDateList[] = \date('Y-m-d', $commande->delivery_date);
                        }
                    }
                }
            }
        }
        $customerOrderReferenceList = \array_unique($customerOrderReferenceList);
        \sort($customerOrderReferenceList);
        $deliveryDateList = \array_unique($deliveryDateList);
        \rsort($deliveryDateList);
    }

    /**
     * map type of invoices dolibarr <-> facturx
     * @param $object the invoice object
     *
     * @return  string|null code of invoice type
     */
    private function _getTypeOfInvoice($object)
    { // TODO: move this function to class utils
        $map = [
            CommonInvoice::TYPE_STANDARD        => ZugferdInvoiceType::INVOICE,
            CommonInvoice::TYPE_REPLACEMENT     => ZugferdInvoiceType::CORRECTION,
            CommonInvoice::TYPE_CREDIT_NOTE     => ZugferdInvoiceType::CREDITNOTE,
            CommonInvoice::TYPE_DEPOSIT         => ZugferdInvoiceType::PREPAYMENTINVOICE,
        	CommonInvoice::TYPE_SITUATION       => ZugferdInvoiceType::INVOICE,				// Process situation invoice as common invoice
        ];
        return $map[$object->type] ?? null;
    }

    /**
     * extract id prof : it depends on country ...
     *
     * @param   Societe		$thirdparty  	Dolibarr thirdparty
     * @return  string 						Return siret siren or locale prof id
     */
    private function idprof($thirdpart)
    { // TODO: move this function to class utils
        $retour = "";
        switch ($thirdpart->country_code) {
            case 'BE':
                $retour = $thirdpart->idprof1;
                break;
            case 'DE':
                if (!empty($thirdpart->idprof6)) {
                    $retour = $thirdpart->idprof6;
                    break;
                } elseif (!empty($thirdpart->idprof2) && !empty($thirdpart->idprof3)) {
                    $retour = $thirdpart->idprof2 . $thirdpart->idprof3;
                } else {
                    $retour = $thirdpart->idprof1;
                }
                break;
            case 'FR':
                $retour = $thirdpart->idprof1;		// SIRET
                break;
            default:
                $retour = $thirdpart->idprof1 ? $thirdpart->idprof1 : $thirdpart->idprof2;
        }

        return $this->_remove_spaces($retour);
    }

    /**
     * remove spaces from string for example french people add spaces into long numbers like
     * SIRET: 844 431 239 00020
     *
     * @param   string  $str  string to cleanup
     *
     * @return  string  cleaned up string
     */
    private function _remove_spaces($str)
    { // TODO: move this function to class utils
        return preg_replace('/\\s+/', '', $str);
    }

    /**
     * buyer id prof depends on country
     *
     * @return  string idprof
     */
    private function thirdpartyidprof($object)
    { // TODO: move this function to class utils
        return $this->idprof($object->thirdparty);
    }

    /**
     * extract mail from contact or thirdparty
     *
     * @param   $contact dolibarr contact
     * @param   $thirdpart  dolibarr thirdpart/societe
     *
     * @return  string email of buyer
     */
    private function extractBuyerMail($contact, $thirdpart)
    { // TODO: move this function to class utils
        dol_syslog("pdpconnectfr extractBuyerMail : contact=" . $contact->email . " | soc=" . $thirdpart->email);
        if (!empty($contact->email)) {
            return $contact->email;
        }
        return $thirdpart->email;
    }

    /**
     * return IEC_6523 code (https://docs.peppol.eu/poacc/billing/3.0/codelist/ICD/)
     *
     * TODO: add other countries, at least europeans countries ...
     *
     * @return string code
     */
    private function IEC_6523_code($country_code)
    { // TODO: move this function to class utils
        $retour = "";
        switch ($country_code) {
            case 'BE':
                $retour = "0008";
                break;
            case 'DE':
                $retour = "0000";
                break;
            case 'FR':
                $retour = "0002";
                break;
            default:
        }
        return $retour;
    }

    /************************************************
     *    Check line type from external module ?
     *
     * @param  object $line       line we work on
     * @param  string $element    line object element (for special case like shipping)
     * @param  string $searchName module name we look for
     * @return boolean                        true if the line is a special one and was created by the module we ask for
     ************************************************/
    private function _isLineFromExternalModule($line, $element, $searchName)
    { // TODO: move this function to class utils
        global $db;
        if ($element == 'shipping' || $element == 'delivery') {
            $fk_origin_line = $line->fk_origin_line;
            $line = new OrderLine($db);
            $line->fetch($fk_origin_line);
        }
        if ($line->product_type == 9 && $line->special_code == $this->_get_mod_number($searchName)) {
            return true;
        } else {
            return false;
        }
    }
    /************************************************
     *    Find module number
     *
     * @param  string $searchName module name we look for
     * @return integer                        -1 if KO, 0 not found or module number if Ok
     ************************************************/
    private function _get_mod_number($modName)
    { // TODO: move this function to class utils
        global $db;
        if (class_exists($modName)) {
            $objMod = new $modName($db);
            return $objMod->numero;
        }
        return 0;
    }

    /**
     * Get a timestamp and return a php DateTime object
     *
     * @param   $ts  timestamp
     *
     * @return \DateTime|null DateTime object or null if $ts is empty
     */
    private function _tsToDateTime($ts)
    {
        dol_syslog("facturx call _tsToDateTime for {$ts} ...");
        if (empty($ts)) {
            return null;
        }
        $dt = new \DateTime();
        $dt->setTimestamp($ts);
        return $dt;
    }

    /************************************************
     *    Find paymentMean number
     *
     * @param  object $invoice object name we look for
     * @return integer                        paymentMeanId for HorstOeko libs
     ************************************************/
    private function _get_paymentMean_number($invoice)
    {
        $paymentMeanId = 97;
        //"Must be defined between trading parties" for empty values
        switch ($invoice->mode_reglement_code) {
            case 'CB':
                $paymentMeanId = 54;
                break;
            //Credit Card
            case 'CHQ':
                $paymentMeanId = 20;
                break;
            //Check
            case 'FAC':
                $paymentMeanId = 1;
                break;
            //Local payment method
            case 'LIQ':
                $paymentMeanId = 10;
                break;
            //Cash
            case 'PRE':
                $paymentMeanId = 59;
                break;
            //SEPA direct debit
            case 'TIP':
                $paymentMeanId = 45;
                break;
            //Bank Transfer with document
            case 'TRA':
                $paymentMeanId = 23;
                break;
            //Check
            case 'VAD':
                $paymentMeanId = 68;
                break;
            //Online Payment
            case 'VIR':
                $paymentMeanId = 30;
                break;
        }
        return $paymentMeanId;
    }

    /**
     * Map Dolibarr invoice type to Factur-X BillingProcessID
     *
     * @param int $type Dolibarr invoice type
     * @return string Factur-X BillingProcessID
     */
    public function getBillingProcessID($type)
    {

        /**
         * Other codes provided by EN16931 standard but not used in Dolibarr:
         *
         *  - S1: Simplified invoice (e.g., POS receipt) - in Dolibarr considered as standard invoice
         *  - S2: Simplified prepayment invoice
         *  - M2: Prepayment credit note
         *  - S4: Simplified partial invoice
         *  - M4: Partial credit note
         *  - S5: Simplified specific invoice (special tax case)
         *  - S6: Simplified credit note (e.g., cancelled receipt / POS return)
         *  - B7: Final settlement invoice
         *  - S7: Simplified final settlement invoice
         */

        switch ($type) {

            case Facture::TYPE_STANDARD:
                return 'B1'; // Standard invoice

            case Facture::TYPE_REPLACEMENT:
                return 'M1'; // Replacement invoice (corrective)

            case Facture::TYPE_CREDIT_NOTE:
                return 'M1'; // Credit note

            case Facture::TYPE_DEPOSIT:
                return 'B2'; // Prepayment invoice

            case Facture::TYPE_SITUATION:
                return 'B4'; // Progress/partial invoice
            default:
                return 'B1';
        }
    }

    /**
     * Map Factur-X document type code to Dolibarr invoice type
     *
     * @param string $documenttypecode Factur-X document type code
     * @return int|string Dolibarr invoice type or '-1' if unknown
     */
    public function _getDolibarrInvoiceType($documenttypecode)
    {

        /**
         * Codes UNTDID 1001 utilisés par EN16931 pour le type de facture (InvoiceTypeCode BT-3).
         * 325 – Facture pro-forma
         * 211 – Demande de paiement intermédiaire (une facture de situation?)
         * 210 – Facture d’acompte
         * 380 – Note de crédit
         * 384 – Facture corrective
         * 380 – Facture standard
         *
         * 80  – Note de débit (biens ou services) --- Not used in Dolibarr
         * 82  – Facture de services mesurés (ex : gaz, électricité) --- Not used in Dolibarr
         * 84  – Note de débit (ajustements financiers) --- Not used in Dolibarr
         * 130 – Feuille de données de facturation --- Not used in Dolibarr
         * 202 – Valorisation de paiement direct --- Not used in Dolibarr
         * 203 – Valorisation de paiement provisoire --- Not used in Dolibarr
         * 204 – Valorisation de paiement --- Not used in Dolibarr
         * 218 – Demande de paiement finale après achèvement des travaux --- Not used in Dolibarr
         * 219 – Demande de paiement pour unités terminées --- Not used in Dolibarr
         * 295 – Facture de variation de prix --- Not used in Dolibarr
         *
         * 326 – Facture partielle --- Not used in Dolibarr
         */

        $map = [
            ZugferdInvoiceType::INVOICE                         => CommonInvoice::TYPE_STANDARD,
            ZugferdInvoiceType::CORRECTION                      => CommonInvoice::TYPE_REPLACEMENT,
            ZugferdInvoiceType::CREDITNOTE                      => CommonInvoice::TYPE_CREDIT_NOTE,
            ZugferdInvoiceType::PREPAYMENTINVOICE               => CommonInvoice::TYPE_DEPOSIT,
            ZugferdInvoiceType::INTERIMAPPLICATIONFORPAYMENT    => CommonInvoice::TYPE_SITUATION,
            ZugferdInvoiceType::PROFORMAINVOICE                 => CommonInvoice::TYPE_PROFORMA,
        ];


        if (!isset($map[$documenttypecode])) {
            dol_syslog(get_class($this) . '::_getDolibarrInvoiceType Unknown document type code: ' . $documenttypecode, LOG_WARNING);
            return '-1';
        }

        return $map[$documenttypecode];
    }

    /**
     * Synchronize or create a Dolibarr thirdparty based on Factur-X seller information.
     *
     * @param array     $sellerInfo Array containing seller information extracted from Factur-X
     * @param string    $priority Fill priority ('dolibarr' or 'pdp'). If both data are available, which one to prefer
     * @param string    $flowId Flow identifier source of the thirdparty.
     *
     * @return array{res:int, message:string, actioncode:string|null, actionurl:string|null, action:string|null}   Returns array with 'res' (ID of the synchronized or created thirdparty, -1 on error) with a 'message' and an optional 'action'.
     */
    private function _syncOrCreateThirdpartyFromFacturXSeller($sellerInfo, $priority = 'dolibarr', $flowId = '')
    {
        /**
         * Scenario to find or create a thirdparty based on Factur-X seller information:
         *
         * 1. Try to find thirdparty by global IDs (SIREN, VAT number ...)
         * 1.1 If found, update thirdparty information with provided data
         *
         * 2. If not found, try to find thirdparty by closest match (findNearest)
         * 2.1 If found one match, update thirdparty information with provided data
         * 2.2 If found multiple matches, log warning and return error
         *
         * 3. If still not found, create new thirdparty with provided data
         */
        global $db, $langs, $user;
        require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';

        $thirdparty = new Societe($db);
        $thirdpartyId = -1;

        // Step 1: Try to find thirdparty by global IDs
        if (!empty($sellerInfo['sellerGlobalIds']) && is_array($sellerInfo['sellerGlobalIds'])) {
            foreach ($sellerInfo['sellerGlobalIds'] as $idScheme => $globalId) {
                if (!empty($globalId)) {
                    // Map scheme to idprof field (0002 = SIREN)
                    $idprofField = $this->_mapGlobalIdSchemeToIdprof($idScheme);
                    if (!empty($idprofField)) {
                        $result = 0;
                        // Fetch thirdparty by corresponding idprof field
                        if ($idprofField === 'idprof1') { // SIREN
                            $result = $thirdparty->fetch(0, '', '', '', $globalId);
                        }
                        // TODO: Add more idprof fields mapping if needed

                        if ($result > 0) {
                            $thirdpartyId = $thirdparty->id;
                            dol_syslog(get_class($this) . '::_syncOrCreateThirdpartyFromFacturXSeller Found thirdparty by ' . $idScheme . ': ' . $thirdpartyId);
                            break;
                        }
                    }
                }
            }
        }
        if ($thirdpartyId < 0) {
            // Try to find by VAT number if provided
            if (!empty($sellerInfo['sellerTaxRegistations']['VA'])) {
                $sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "societe WHERE REPLACE(tva_intra, ' ', '') = '" . $db->escape($this->_remove_spaces($sellerInfo['sellerTaxRegistations']['VA'])) . "'";
                $resql = $db->query($sql);
                if ($resql) {
                    if ($db->num_rows($resql) > 1) {
                        dol_syslog(get_class($this) . '::_syncOrCreateThirdpartyFromFacturXSeller Error: Multiple thirdparties found for VAT number: ' . $sellerInfo['sellerTaxRegistations']['VA'], LOG_ERR);
                        return array(
                        	'res' => -1,
                        	'message' => 'Multiple thirdparties found for VAT number: ' . $sellerInfo['sellerTaxRegistations']['VA'],
                        	'actioncode' => 'DUPLICATE_THIRDPARTIES',
                        	'action' => 'Merge the 2 thirdparties'
                        );
                    } elseif ($db->num_rows($resql) === 1) {
                        $obj = $db->fetch_object($resql);
                        $result = $thirdparty->fetch($obj->rowid);
                        if ($result > 0) {
                            $thirdpartyId = $thirdparty->id;
                            dol_syslog(get_class($this) . '::_syncOrCreateThirdpartyFromFacturXSeller Found thirdparty by VAT number: ' . $thirdpartyId);
                        }
                    }
                }
            }
        }

        // Step 2: If not found, try to find by findNearest function
        if ($thirdpartyId < 0) {
            $result = $thirdparty->findNearest(
                0,
                $sellerInfo['sellername'] ?? '',
                $sellerInfo['sellername'] ?? '',
                '',
                '',
                '',
                '',
                '',
                '',
                '',
                $sellerInfo['sellercontactemailaddr'] ?? '',
                $sellerInfo['sellername'] ?? ''
            ); // TODO: we can add phone, address and vat number to improve matching
            if ($result > 0) {
                $thirdpartyId = $thirdparty->id;
                dol_syslog(get_class($this) . '::_syncOrCreateThirdpartyFromFacturXSeller Found thirdparty by findNearest: ' . $thirdpartyId);
            }
        }

        // Step 3: Create or update thirdparty

        //$thirdpartyId = -2; // For testing

        // if found, update information
        if ($thirdpartyId > 0) {
            // if complete info is disabled, we return directly the thirdpartyId
            if (!empty(getDolGlobalInt('PDPCONNECTFR_THIRDPARTIES_COMPLETE_INFO'))) {
                dol_syslog(get_class($this) . '::_syncOrCreateThirdpartyFromFacturXSeller Complete info disabled, returning existing thirdparty: ' . $thirdpartyId);
                return array('res' => $thirdpartyId, 'message' => 'Existing thirdparty used without update: ' . $thirdpartyId);
            }

            dol_syslog(get_class($this) . '::_syncOrCreateThirdpartyFromFacturXSeller Updating existing thirdparty: ' . $thirdpartyId);
            // TODO: MAYBE we should call PDP to retrieve more information

            $thirdparty = new Societe($db);
            $thirdparty->fetch($thirdpartyId);

            // Update thirdparty information based on priority
            if ($priority === 'pdp') { // Ecrase dolibarr data with pdp data
                $thirdparty->name = $sellerInfo['sellername'] ?? $thirdparty->name;
                $thirdparty->address = $sellerInfo['sellerlineone'] ?? $thirdparty->address;
                if (!empty($sellerInfo['sellerlinetwo'])) {
                    $thirdparty->address .= "\n" . $sellerInfo['sellerlinetwo'];
                }
                if (!empty($sellerInfo['sellerlinethree'])) {
                    $thirdparty->address .= "\n" . $sellerInfo['sellerlinethree'];
                }
                $thirdparty->zip = $sellerInfo['sellerpostcode'] ?? $thirdparty->zip;
                $thirdparty->town = $sellerInfo['sellercity'] ?? $thirdparty->town;
                $thirdparty->country_code = $sellerInfo['sellercountry'] ?? $thirdparty->country_code;
                $thirdparty->email = $sellerInfo['sellercontactemailaddr'] ?? $thirdparty->email;
                $thirdparty->phone = $sellerInfo['sellercontactphoneno'] ?? $thirdparty->phone;
                $thirdparty->fax = $sellerInfo['sellercontactfaxno'] ?? $thirdparty->fax;

                // Set identification numbers
                if (!empty($sellerInfo['sellerGlobalIds']) && is_array($sellerInfo['sellerGlobalIds'])) {
                    foreach ($sellerInfo['sellerGlobalIds'] as $idScheme => $globalId) {
                        if (!empty($globalId)) {
                            $idprofField = $this->_mapGlobalIdSchemeToIdprof($idScheme);
                            if (!empty($idprofField)) {
                                $thirdparty->$idprofField = $this->_remove_spaces($globalId);
                            }
                        }
                    }
                }
                if (!empty($sellerInfo['sellerTaxRegistations']['VA'])) {
                    $thirdparty->tva_intra = $this->_remove_spaces($sellerInfo['sellerTaxRegistations']['VA']);
                    $thirdparty->tva_assuj = 1;
                }
            } elseif ($priority === 'dolibarr') { // Fill only empty fields from pdp data
                dol_syslog(get_class($this) . '::_syncOrCreateThirdpartyFromFacturXSeller Keeping existing thirdparty data and fill only empty fields as priority is dolibarr: ' . $thirdpartyId);

                if (empty($thirdparty->name) && !empty($sellerInfo['sellername'])) {
                    $thirdparty->name = $sellerInfo['sellername'];
                }
                if (empty($thirdparty->address) && !empty($sellerInfo['sellerlineone'])) {
                    $thirdparty->address = $sellerInfo['sellerlineone'];
                    if (!empty($sellerInfo['sellerlinetwo'])) {
                        $thirdparty->address .= "\n" . $sellerInfo['sellerlinetwo'];
                    }
                    if (!empty($sellerInfo['sellerlinethree'])) {
                        $thirdparty->address .= "\n" . $sellerInfo['sellerlinethree'];
                    }
                }
                if (empty($thirdparty->zip) && !empty($sellerInfo['sellerpostcode'])) {
                    $thirdparty->zip = $sellerInfo['sellerpostcode'];
                }
                if (empty($thirdparty->town) && !empty($sellerInfo['sellercity'])) {
                    $thirdparty->town = $sellerInfo['sellercity'];
                }
                if (empty($thirdparty->country_code) && !empty($sellerInfo['sellercountry'])) {
                    $thirdparty->country_code = $sellerInfo['sellercountry'];
                }
                if (empty($thirdparty->email) && !empty($sellerInfo['sellercontactemailaddr'])) {
                    $thirdparty->email = $sellerInfo['sellercontactemailaddr'];
                }
                if (empty($thirdparty->phone) && !empty($sellerInfo['sellercontactphoneno'])) {
                    $thirdparty->phone = $sellerInfo['sellercontactphoneno'];
                }
                if (empty($thirdparty->fax) && !empty($sellerInfo['sellercontactfaxno'])) {
                    $thirdparty->fax = $sellerInfo['sellercontactfaxno'];
                }
                // Set identification numbers if empty
                if (!empty($sellerInfo['sellerGlobalIds']) && is_array($sellerInfo['sellerGlobalIds'])) {
                    foreach ($sellerInfo['sellerGlobalIds'] as $idScheme => $globalId) {
                        if (!empty($globalId)) {
                            $idprofField = $this->_mapGlobalIdSchemeToIdprof($idScheme);
                            if (!empty($idprofField) && empty($thirdparty->$idprofField)) {
                                $thirdparty->$idprofField = $this->_remove_spaces($globalId);
                            }
                        }
                    }
                }
                if (!empty($sellerInfo['sellerTaxRegistations']['VA']) && empty($thirdparty->tva_intra)) {
                    $thirdparty->tva_intra = $this->_remove_spaces($sellerInfo['sellerTaxRegistations']['VA']);
                    $thirdparty->tva_assuj = 1;
                }
            }
            $result = $thirdparty->update($thirdpartyId, $user);
            if ($result < 0) {
                dol_syslog(get_class($this) . '::_syncOrCreateThirdpartyFromFacturXSeller Error updating thirdparty: ' . $thirdparty->error, LOG_ERR);
                return array('res' => -1, 'message' => 'Thirdparty update error: ' . $thirdparty->error);
            } else {
                dol_syslog(get_class($this) . '::_syncOrCreateThirdpartyFromFacturXSeller Updated thirdparty: ' . $thirdpartyId);
                return array('res' => $thirdpartyId, 'message' => 'Thirdparty ' . $thirdparty->name . ' updated successfully');
            }
        }

        // if not found, create new thirdparty
        if ($thirdpartyId < 0 && !empty(getDolGlobalInt('PDPCONNECTFR_THIRDPARTIES_AUTO_GENERATION'))) {
            dol_syslog(get_class($this) . '::_syncOrCreateThirdpartyFromFacturXSeller Creating new thirdparty: ' . $sellerInfo['sellername']);

            $thirdparty = new Societe($db);

            $thirdparty->name = $sellerInfo['sellername'] ?? 'Unknown Supplier name';
            $thirdparty->address = $sellerInfo['sellerlineone'] ?? '';
            if (!empty($sellerInfo['sellerlinetwo'])) {
                $thirdparty->address .= "\n" . $sellerInfo['sellerlinetwo'];
            }
            if (!empty($sellerInfo['sellerlinethree'])) {
                $thirdparty->address .= "\n" . $sellerInfo['sellerlinethree'];
            }
            $thirdparty->zip = $sellerInfo['sellerpostcode'] ?? '';
            $thirdparty->town = $sellerInfo['sellercity'] ?? '';
            $thirdparty->country_code = $sellerInfo['sellercountry'] ?? '';
            $thirdparty->email = $sellerInfo['sellercontactemailaddr'] ?? '';
            $thirdparty->phone = $sellerInfo['sellercontactphoneno'] ?? '';
            $thirdparty->fax = $sellerInfo['sellercontactfaxno'] ?? '';

            // Set identification numbers
            if (!empty($sellerInfo['sellerGlobalIds']) && is_array($sellerInfo['sellerGlobalIds'])) {
                foreach ($sellerInfo['sellerGlobalIds'] as $idScheme => $globalId) {
                    if (!empty($globalId)) {
                        $idprofField = $this->_mapGlobalIdSchemeToIdprof($idScheme);
                        if (!empty($idprofField)) {
                            $thirdparty->$idprofField = $this->_remove_spaces($globalId);
                        }
                    }
                }
            }

            if (!empty($sellerInfo['sellerTaxRegistations']['VA'])) {
                $thirdparty->tva_intra = $this->_remove_spaces($sellerInfo['sellerTaxRegistations']['VA']);
                $thirdparty->tva_assuj = 1;
            }

            // Set as supplier
            $thirdparty->fournisseur = 1;
            $thirdparty->code_fournisseur = 'auto';

            $result = $thirdparty->create($user);
            if ($result > 0) {
                $thirdpartyId = $thirdparty->id;

                // Add entry in pdpconnectfr_extlinks table to mark that this thirdparty is imported from PDP
                $pdpconnectfr = new PdpConnectFr($db);
                $pdpconnectfr->insertOrUpdateExtLink($thirdpartyId, $thirdparty->element, $flowId);

                dol_syslog(get_class($this) . '::_syncOrCreateThirdpartyFromFacturXSeller Created new thirdparty: ' . $thirdpartyId);
                return array('res' => $thirdpartyId, 'message' => 'Thirdparty ' . $thirdparty->name . ' created successfully');
            } else {
                dol_syslog(get_class($this) . '::_syncOrCreateThirdpartyFromFacturXSeller Error creating thirdparty: ' . $thirdparty->error, LOG_ERR);
                return array('res' => -1, 'message' => 'Thirdparty creation error: ' . implode("\n", $thirdparty->errors));
            }
        } else {
            dol_syslog(get_class($this) . '::_syncOrCreateThirdpartyFromFacturXSeller Auto-creation of thirdparties is disabled', LOG_ERR);

            $sellername = trim($sellerInfo['sellername'] ?? '');
            $selleremail = trim($sellerInfo['sellercontactemailaddr'] ?? '');

            $selleridents = [];
            $createParams = [];

            if (!empty($sellername)) {
                $selleridents[] = 'Supplier: ' . $sellername;
                $createParams['name'] = $sellername;
            }
            if (!empty($selleremail)) {
                $selleridents[] = 'Email: ' . $selleremail;
                $createParams['email'] = $selleremail;
            }

            if (!empty($sellerInfo['sellerGlobalIds']) && is_array($sellerInfo['sellerGlobalIds'])) {
                foreach ($sellerInfo['sellerGlobalIds'] as $idScheme => $globalId) {
                    if (!empty($globalId)) {
                        $idprofField = $this->_mapGlobalIdSchemeToIdprof($idScheme);
                        if (!empty($idprofField)) {
                            $selleridents[] = $idScheme . ': ' . $globalId;
                            $createParams[$idprofField] = $globalId;
                        }
                    }
                }
            }

            // Create URL to prefill thirdparty creation form
            $createUrl = DOL_URL_ROOT . '/societe/card.php?action=create&type=f';
            if (!empty($createParams)) {
                $createUrl .= '&' . http_build_query($createParams);
            }
			$createUrl .= '&backtopage='.urlencode(dol_buildpath('/pdpconnectfr/document_list.php', 1));

            $errorDetails = [];
            if (!empty($sellername)) {
                $errorDetails[] = 'Supplier: ' . $sellername;
            }
            if (!empty($selleremail)) {
                $errorDetails[] = 'Email: ' . $selleremail;
            }
            if (!empty($selleridents)) {
                $errorDetails[] = 'Identifiers: ' . implode(', ', $selleridents);
            }

            $detailsStr = !empty($errorDetails) ? ' (' . implode(' | ', $errorDetails) . ')' : '';

            $message = 'Unable to find supplier' . $detailsStr . '. Auto-creation of thirdparties is disabled in settings.';

            $action = 'Manual supplier creation based on the retrieved information from E-invoice ';
            $action .= '<a class="butAction small" href="' . dol_escape_htmltag($createUrl) . '" target="_blank">';
            $action .= '<i class="fas fa-plus-circle"></i> ';
            $action .= $langs->trans('CreateSupplier');
            $action .= '</a>';

            return array(
            	'res' => -1,
            	'message' => $message,
            	'actioncode' => 'THIRDPARTY_NOT_FOUND',
            	'actionurl' => $createUrl,
            	'action' => $action);
        }
    }

    /**
     * Find or create a Dolibarr product based on Factur-X invoice line data
     * @param array $lineData Array containing invoice line data extracted from Factur-X
     * @param string $flowId Flow identifier source of the product. Used for logging purposes.
     *
     * @return array{res:int, message:string, actioncode:string|null, actionurl:string|null, action:string|null}   Returns array with 'res' (ID of the found or created product, -1 on error) with a 'message' and an optional 'action'.
     */
    private function _findOrCreateProductFromFacturXLine($lineData, $flowId = '')
    {
        /*
        * PRODUCT MATCHING FOR SUPPLIER INVOICE (Factur-X)
        *
        * This matching strategy attempts to find or create a product based on
        * Factur-X invoice line data, following a priority-based approach.
        *
        * 1. Search in product supplier prices table using prodsellerid
        *    - Ok if match found
        *    - ko, continue to step 2
        *
        * 2. Global ID (prodglobalid + prodglobalidtype) and prodglobalidtype = '0160' search by barcode
        *    - ok if match found
        *    - KO if Other schemes or no match, continue to step 3
        *
        * 3. if Buyer Reference (prodbuyerid) is available search prodbuyerid = internal product reference
        *    - ok if match found
        *    - ko, continue to step 4
        *
        * 4. Text Search using prodname
        *    - ok if match found
        *    - ko if multiple matches or no match, continue to create product
        *
        * 5. If no match found after all steps:
        *    - Automatic product creation (with extrafield source=facturx and to be verified tag)
        *    - Use this product for supplier invoice line (with extrafield to be verified tag)
        *    - Add supplier price information (if not added automatically by Dolibarr)
        */
        global $db, $user, $langs;

        // 1. Search in product supplier prices table using prodsellerid
        $sql = "SELECT p.rowid ";
        $sql .= " FROM " . MAIN_DB_PREFIX . "product as p ";
        $sql .= " INNER JOIN " . MAIN_DB_PREFIX . "product_fournisseur_price as pfp ON pfp.fk_product = p.rowid ";
        $sql .= " WHERE pfp.product_supplier_id = '" . $db->escape($lineData['prodsellerid']) . "' ";
        $sql .= " AND pfp.fk_soc = " . intval($lineData['supplierId']) . " ";
        $sql .= " LIMIT 1";
        $resql = $db->query($sql);
        if ($resql && $db->num_rows($resql) > 0) {
            $obj = $db->fetch_object($resql);
            dol_syslog(get_class($this) . '::_findOrCreateProductFromFacturXLine Found product by prodsellerid: ' . $obj->rowid);
            return array('res' => $obj->rowid, 'message' => 'Product found by prodsellerid');
            // No match found, continue to next step
        }

        // 2. Global ID (prodglobalid + prodglobalidtype) and prodglobalidtype = '0160' search by barcode
        // TODO

        // 3. if Buyer Reference (prodbuyerid) is available search prodbuyerid = internal product reference
        if (!empty($lineData['prodbuyerid'])) {
            $sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "product ";
            $sql .= " WHERE ref = '" . $db->escape($lineData['prodbuyerid']) . "' OR rowid = '" . $db->escape($lineData['prodbuyerid']) . "' ";
            $sql .= " LIMIT 1";
            $resql = $db->query($sql);
            if ($resql && $db->num_rows($resql) > 0) {
                $obj = $db->fetch_object($resql);
                dol_syslog(get_class($this) . '::_findOrCreateProductFromFacturXLine Found product by prodbuyerid: ' . $obj->rowid);
                return array('res' => $obj->rowid, 'message' => 'Product found by prodbuyerid');
            }
        }

        // 4. Text Search using prodname
        $sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "product ";
        $sql .= " WHERE label = '" . $db->escape($lineData['prodname']) . "' ";
        $resql = $db->query($sql);
        if ($resql) {
            if ($db->num_rows($resql) === 1) {
                $obj = $db->fetch_object($resql);
                dol_syslog(get_class($this) . '::_findOrCreateProductFromFacturXLine Found product by text search: ' . $obj->rowid);
                return array('res' => $obj->rowid, 'message' => 'Product found by text search');
            }
        }

        // 5. If no match found after all steps: Create new product
        if (!empty(getDolGlobalInt('PDPCONNECTFR_PRODUCTS_AUTO_GENERATION'))) {
            $product = new Product($db);
            $product->type        = $this->_detectProductTypeFromFacturx($lineData);
            $product->ref = 'FACTURX-' . dol_sanitizeFileName(!empty($lineData['prodsellerid'] && $lineData['prodsellerid'] !== "0000") ? $lineData['prodsellerid'] : time());
            $product->ref_ext     = trim($lineData['prodsellerid'] ?? '');
            $product->label       = !empty($lineData['prodname'])
                ? $lineData['prodname']
                : 'Imported product from supplier invoice (Ref: ' . $lineData['parentDocumentNo'] . ')';
            $product->description = trim($lineData['proddesc'] ?? '');
            $product->tva_tx      = (float) ($lineData['rateApplicablePercent'] ?? 0);
            $product->status      = 1; // Active
            $product->note_private = 'Product created automatically from Factur-X import.';
            $product->import_key  = AbstractPDPProvider::$PDPCONNECTFR_LAST_IMPORT_KEY; // It does not work here, so we will update it after creation
            // Set barcode if global ID is provided and is a GTIN/EAN type
            if (!empty($lineData['prodglobalid']) && !empty($lineData['prodglobalidtype']) && in_array($lineData['prodglobalidtype'], ['0160', '0011'])) {
                $product->barcode = $lineData['prodglobalid'];
                $product->barcode_type = getDolGlobalInt('PRODUIT_DEFAULT_BARCODE_TYPE', 0);
            } else {
                $product->barcode = 'auto';
            }
            // Validate before creation
            $resCheck = $product->check();
            if ($resCheck < 0) {
                dol_syslog(__METHOD__ . ' Product check failed: ' . $product->error, LOG_ERR);
                return [
                    'res'     => -1,
                    'message' => 'Product check failed: ' . implode("\n", $product->errors),
                ];
            }

            // Create product
            $resCreate = $product->create($user);
            if ($resCreate > 0) {
                $productId = $product->id;

                // Set import_key
                $sql = 'UPDATE '.MAIN_DB_PREFIX."product SET import_key = '".$db->escape($product->import_key)."' WHERE rowid = ".((int) $productId);
                $db->query($sql);

                // Add entry in pdpconnectfr_extlinks table to mark product as created from e-invoice
                $pdpconnectfr = new PdpConnectFr($db);
                $pdpconnectfr->insertOrUpdateExtLink($productId, $product->element, $flowId);

                dol_syslog(__METHOD__ . ' New product created (ID: ' . $productId . ')');
                return [
                    'res'     => $productId,
                    'message' => 'Product successfully created from Factur-X import',
                ];
            }

            // Error on creation
            dol_syslog(__METHOD__ . ' Product creation error: ' . $product->error, LOG_ERR);
            return [
                'res'     => -1,
                'message' => 'Product creation error: ' . implode("\n", $product->errors),
            ];
        } else {
            dol_syslog(get_class($this) . '::_findOrCreateProductFromFacturXLine Auto-creation of products is disabled', LOG_ERR);

            $prodRef = trim($lineData['prodsellerid'] ?? '');
            $prodName = trim($lineData['prodname'] ?? '');
            $prodDesc = trim($lineData['proddesc'] ?? '');

            $errorDetails = [];
            $createParams = [];
            if (!empty($prodRef) && $prodRef !== "0000") {
                $errorDetails[] = $prodRef . " | ";
                $createParams['ref_ext'] = $prodRef;
            }
            if (!empty($prodName)) {
                $errorDetails[] = 'Name: ' . $prodName;
                $createParams['label'] = $prodName;
            }
            if (!empty($prodDesc)) {
                //$errorDetails[] = 'Description: ' . $prodDesc;
                $createParams['desc'] = $prodDesc;
            }

            // Detect product type to prefill form
            $createParams['type'] = $this->_detectProductTypeFromFacturx($lineData);
            $createParams['tva_tx'] = (float) ($lineData['rateApplicablePercent'] ?? 0);
            $createParams['status'] = 1; // Active
            if (!empty($lineData['prodglobalid']) && !empty($lineData['prodglobalidtype']) && in_array($lineData['prodglobalidtype'], ['0160', '0011'])) {
                $createParams['barcode'] = $lineData['prodglobalid'];
                $createParams['barcode_type'] = getDolGlobalInt('PRODUIT_DEFAULT_BARCODE_TYPE', 0);
            } else {
                $createParams['barcode'] = 'auto';
            }

            // Create URL to prefill product creation form
            $createUrl = DOL_URL_ROOT . '/product/card.php?action=create';
            if (!empty($createParams)) {
                $createUrl .= '&' . http_build_query($createParams);
            }
			$createUrl .= '&backtopage='.urlencode(dol_buildpath('/pdpconnectfr/document_list.php', 1));

            $detailsStr = !empty($errorDetails) ? ' (' . implode(' | ', $errorDetails) . ')' : '';

            $message = 'Unable to find product' . $detailsStr . '. Auto-creation of products is disabled in settings.';

            $action = $langs->trans('ManualUnfoundProductCreationFromEInvoice', $detailsStr) . ' ';
            $action .= '<a class="butAction small" href="' . dol_escape_htmltag($createUrl) . '" target="_blank">';
            $action .= '<i class="fas fa-plus-circle"></i> ';
            $action .= $langs->trans('CreateProduct');
            $action .= '</a>';

            return array(
            	'res' => -1,
            	'message' => $message,
            	'actioncode' => 'PROUCT_NOT_FOUND',
            	'actionurl' => $createUrl,
            	'action' => $action
            );
        }

    }

    /**
     * Map Factur-X global ID scheme to Dolibarr idprof field
     *
     * @param string $scheme Global ID scheme code
     * @return string Corresponding idprof field name
     */
    private function _mapGlobalIdSchemeToIdprof($scheme)
    {
        $map = [
            '0002' => 'idprof1', // SIREN
        ];

        return $map[$scheme] ?? '';
    }

    /**
     * Determine if a Factur-X line corresponds to a product (0) or a service (1)
     *
     * @param array $line Factur-X line data
     * @return int 0 = product / 1 = service
     */
    private function _detectProductTypeFromFacturx(array $line): int
    {
        $globalId     = trim($line['prodglobalid'] ?? '');
        $globalIdType = trim($line['prodglobalidtype'] ?? '');
        $sellerId     = trim($line['prodsellerid'] ?? '');
        $unitCode     = strtoupper(trim($line['billedquantityunitcode'] ?? ''));
        $name         = strtolower($line['prodname'] ?? '');
        $desc         = strtolower($line['proddesc'] ?? '');

        // A. Global ID known => product
        $productGlobalIdTypes = ['0160', '0011', '0002', '0023', '0004', '0001', '0088']; // GTIN/UPC/EAN/GLN...
        if ($globalId !== '' && in_array($globalIdType, $productGlobalIdTypes, true)) {
            return 0;
        }

        // B. Units typical for services
        $serviceUnits = ['HUR', 'HRS', 'DAY', 'MON', 'ANN', 'MIN', 'WEE', 'E48']; // hours, days, months...
        if (in_array($unitCode, $serviceUnits, true)) {
            return 1;
        }

        // C. Piece but no seller reference => likely service
        if ($sellerId === '' || $sellerId === '0000') {
            return 1;
        }

        // D. Keywords indicating service
        $keywordsService = ['service', 'prestation', 'maintenance', 'installation', 'abonnement', 'support', 'forfait', 'consult'];
        foreach ($keywordsService as $kw) {
            if (stripos($name, $kw) !== false || stripos($desc, $kw) !== false) {
                return 1;
            }
        }

        // Fallback = service
        return 0;
    }

    /**
     * Save Factur-X file to dolibarr supplier invoice attachment.
     * @param FactureFournisseur    $supplierInvoice Supplier invoice object
     * @param string                $filePath        Path to the Factur-X file to save
     * @param string                $prefix          Optional prefix for the saved file name
     *
     * @return array{res:int, message:string}   Returns array with 'res' (1 on success, -1 on error) and info 'message'
     */
    private function _saveFacturXFileToSupplierInvoiceAttachment($supplierInvoice, $filePath, $prefix = '')
    {
        global $conf, $langs;

        // Ensure upload directory exists
        $folder_part   = get_exdir(0, 0, 0, 0, $supplierInvoice);
        $relative_path = 'fournisseur/facture/' . $folder_part . dol_sanitizeFileName($supplierInvoice->ref);
        $upload_dir    = $conf->fournisseur->dir_output . '/facture/' . $folder_part . dol_sanitizeFileName($supplierInvoice->ref);

        if (!file_exists($upload_dir)) {
            if (!dol_mkdir($upload_dir)) {
                dol_syslog(__METHOD__ . " Failed to create upload directory: $upload_dir", LOG_ERR);
                return array('res' => -1, 'message' => 'Failed to create upload directory');
            }
        }

        // Prepare destination filename with optional prefix
        $filename  = dol_sanitizeFileName($supplierInvoice->ref_supplier . '.pdf');
        if (!empty($prefix)) {
            $filename = dol_sanitizeFileName($prefix . '_' . $filename);
        }

        $dest_path = $upload_dir . '/' . $filename;

        // Copy file to destination
        if (!copy($filePath, $dest_path)) {
            dol_syslog(__METHOD__ . " Failed to copy file from $filePath to $dest_path", LOG_ERR);
            return array('res' => -1, 'message' => 'Failed to save attachment file');
        }

        // Verify file was copied successfully
        if (!file_exists($dest_path) || filesize($dest_path) === 0) {
            dol_syslog(__METHOD__ . " File verification failed: $dest_path", LOG_ERR);
            return array('res' => -1, 'message' => 'File verification failed after copy');
        }

        // Set proper file permissions
        chmod($dest_path, 0660);
        dol_syslog(__METHOD__ . " File saved successfully to: $dest_path", LOG_DEBUG);

        // Register file in database index
        $res = addFileIntoDatabaseIndex(
            $dest_path,
            $filename,
            $filename,
            'generated',
            0,
            $supplierInvoice
        );

        if ($res > 0) {
            dol_syslog(__METHOD__ . " File attachment registered in database: $dest_path", LOG_DEBUG);
        } else {
            dol_syslog(__METHOD__ . " Error registering file attachment in database: $dest_path", LOG_ERR);
            // File exists but not indexed - not a critical error, continue
        }

        // Clean up temporary file
        if (file_exists($filePath)) {
            unlink($filePath);
            dol_syslog(__METHOD__ . " Temporary file deleted: $filePath", LOG_DEBUG);
        }

        return array('res' => 1, 'message' => 'Attachment saved successfully ' . $dest_path);
    }


}
