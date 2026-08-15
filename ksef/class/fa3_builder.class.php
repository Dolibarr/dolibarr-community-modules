<?php
/* Copyright (C) 2025-2026 InPoint Automation Sp z o.o.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    ksef/class/fa3_builder.class.php
 * \ingroup ksef
 * \brief   FA(3) XML Generator
 */

require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
dol_include_once('/ksef/lib/ksef.lib.php');

class FA3Builder
{
    private $db;
    public $error;
    public $errors = array();

    private $sellerName;
    private $buyerName;
    private $lastXmlHash;
    private $lastCreationDate;
    private $lastInvoiceType;
    private $currentInvoiceCurrency;
    private $currentKursWaluty;
    private $currentCustomer;
    private $currentCustomerCountry;
    private $currentCustomerIsEU;
    private $reconstructing = false;
    private $productCache = array();

    public function __construct($db)
    {
        $this->db = $db;
    }


    /**
     * @brief Builds FA(3) XML from Dolibarr invoice
     * @param int $invoice_id Invoice ID
     * @param array $options Optional settings:
     *   - offline_mode: bool - Whether invoice is being submitted in offline mode
     *   - include_attachments: bool - Attachments
     * @return string|false XML string or false on error
     * @called_by KSEF::generateFA3XML(), KSEF::submitInvoice(), KSEF::submitInvoiceOffline()
     * @calls buildNaglowek(), buildPodmiot1(), buildPodmiot2(), buildFa()
     */
    public function buildFromInvoice($invoice_id, $options = array())
    {
        global $conf, $mysoc, $extrafields;

        // Reset hash
        $this->lastXmlHash = null;
        $this->lastCreationDate = null;
        $this->lastInvoiceType = null;
        $this->productCache = array();

        try {
            // Reload extrafield definitions
            if (!isset($extrafields) || !is_object($extrafields)) {
                dol_include_once('/core/class/extrafields.class.php');
                $extrafields = new ExtraFields($this->db);
            }
            unset($extrafields->attributes['facture']);
            unset($extrafields->attributes['facturedet']);
            unset($extrafields->attributes['product']);
            $extrafields->fetch_name_optionals_label('facture', true);
            $extrafields->fetch_name_optionals_label('facturedet', true);
            $extrafields->fetch_name_optionals_label('product', true);

            $invoice = new Facture($this->db);
            if ($invoice->fetch($invoice_id) <= 0) {
                $this->error = "Invoice not found: $invoice_id";
                return false;
            }
            $invoice->fetch_lines();
            $invoice->fetch_optionals();

            $customer = new Societe($this->db);
            if ($customer->fetch($invoice->socid) <= 0) {
                $this->error = "Customer not found: " . $invoice->socid;
                return false;
            }
            if (method_exists($customer, 'fetch_optionals')) {
                $customer->fetch_optionals();
            }
            $this->currentCustomer = $customer;

            // For corrective invoices, load original + original XML
            $originalInvoice = null;
            $originalXmlData = null;
            if (($invoice->type == Facture::TYPE_CREDIT_NOTE || $invoice->type == Facture::TYPE_REPLACEMENT) && !empty($invoice->fk_facture_source)) {
                $originalInvoice = new Facture($this->db);
                if ($originalInvoice->fetch($invoice->fk_facture_source) <= 0) {
                    $this->error = "Original invoice not found: " . $invoice->fk_facture_source;
                    return false;
                }
                $originalInvoice->fetch_lines();
                if (ksefGetInvoiceCurrency($invoice) !== ksefGetInvoiceCurrency($originalInvoice)) {
                    $this->error = "Cross-currency correction not supported: original is "
                        . ksefGetInvoiceCurrency($originalInvoice) . ", correction is "
                        . ksefGetInvoiceCurrency($invoice) . ". A correction must use the original invoice currency.";
                    return false;
                }
                $originalXmlData = $this->fetchOriginalXmlData($originalInvoice->id);
                if ($originalXmlData === null) {
                    $originalXmlData = $this->reconstructOriginalXmlData($originalInvoice);
                }
            }

            $xml = new DOMDocument('1.0', 'UTF-8');
            $xml->formatOutput = true;

            $faktura = $xml->createElementNS(KSEF_FA3_NAMESPACE, 'Faktura');
            $xml->appendChild($faktura);

            if (!empty($options['original_creation_date'])) {
                $this->lastCreationDate = $options['original_creation_date'];
            } else {
                $this->lastCreationDate = dol_now();
            }

            $this->buildNaglowek($xml, $faktura, $invoice, $options);
            $this->buildPodmiot1($xml, $faktura, $mysoc, $customer);
            $this->buildPodmiot2($xml, $faktura, $customer);
            $this->buildPodmiot3($xml, $faktura, $invoice);
            $this->buildFa($xml, $faktura, $invoice, $originalInvoice, $originalXmlData);
            $this->buildStopka($xml, $faktura, $invoice);

            $xmlString = $xml->saveXML();

            $this->lastXmlHash = base64_encode(hash('sha256', $xmlString, true));

            return $xmlString;

        } catch (Exception $e) {
            $this->error = "FA(3) generation error: " . $e->getMessage();
            $this->errors[] = $this->error;
            dol_syslog("FA3Builder::buildFromInvoice ERROR: " . $this->error, LOG_ERR);
            return false;
        }
    }

    /**
     * @brief Gets creation date from last built XML
     * @return int|null Unix timestamp of FA3 creation date
     * @called_by KSEF::submitInvoice(), KSEF::submitInvoiceOffline()
     */
    public function getLastCreationDate()
    {
        return $this->lastCreationDate;
    }

    /**
     * @brief Gets hash of last built XML
     * @return string|null Base64-encoded SHA-256 hash
     */
    public function getLastXmlHash()
    {
        return $this->lastXmlHash;
    }

    /**
     * @brief Get invoice type from last XML (VAT, KOR, ZAL)
     * @return string|null
     */
    public function getLastInvoiceType()
    {
        return $this->lastInvoiceType;
    }

    /**
     * @brief Builds header section of FA(3)
     * @param DOMDocument $xml
     * @param DOMElement $parent Parent element
     * @param Facture $invoice Invoice object
     * @param array $options Build options
     * @called_by buildFromInvoice()
     */
    private function buildNaglowek($xml, $parent, $invoice, $options = array())
    {
        $naglowek = $xml->createElement('Naglowek');
        $parent->appendChild($naglowek);

        // Form code
        $kodFormularza = $xml->createElement('KodFormularza', 'FA');
        $kodFormularza->setAttribute('kodSystemowy', 'FA (3)');
        $kodFormularza->setAttribute('wersjaSchemy', KSEF_FA3_SCHEMA_VERSION);
        $naglowek->appendChild($kodFormularza);

        // Form variant
        $naglowek->appendChild($xml->createElement('WariantFormularza', '3'));

        if (!empty($options['original_creation_date'])) {
            if (is_numeric($options['original_creation_date'])) {
                $dateCreation = date('Y-m-d\TH:i:sP', $options['original_creation_date']);
            } else {
                $dateCreation = $options['original_creation_date'];
            }
        } else {
            $dateCreation = date('Y-m-d\TH:i:sP');
        }
        $naglowek->appendChild($xml->createElement('DataWytworzeniaFa', $dateCreation));

        // System identification
        $systemInfo = 'Dolibarr ERP ' . DOL_VERSION;
        if (!empty($options['offline_mode'])) {
            $systemInfo .= ' (offline)';
        }
        $naglowek->appendChild($xml->createElement('SystemInfo', $systemInfo));
    }

    /**
     * @brief Builds seller (Podmiot1) section
     * @param $xml DOMDocument
     * @param $parent Parent element
     * @param $mysoc Company object
     * @param $customer Customer object
     * @called_by buildFromInvoice()
     * @calls ksefCleanNIP(), xmlSafe()
     */
    private function buildPodmiot1($xml, $parent, $mysoc, $customer)
    {
        global $conf;

        // Seller
        $podmiot1 = $xml->createElement('Podmiot1');
        $parent->appendChild($podmiot1);

        // Check if buyer is not PL
        $buyerCountry = ksefInferCountryCode($customer);
        $isForeignBuyer = ($buyerCountry != 'PL');

        // PrefiksPodatnika - VAT prefix if buyer is foreign AND seller has valid EU VAT ID in tva_intra
        if ($isForeignBuyer && !empty($mysoc->tva_intra)) {
            $vatIntra = strtoupper(trim($mysoc->tva_intra));
            if (preg_match('/^([A-Z]{2})/', $vatIntra, $matches)) {
                $vatPrefix = $matches[1];
                $podmiot1->appendChild($xml->createElement('PrefiksPodatnika', $vatPrefix));
            }
        }

        // NrEORI
        $sellerEori = getDolGlobalString('KSEF_COMPANY_EORI', '');
        if (empty($sellerEori)) {
            $sellerEori = trim(ksefGetIdentifierField($mysoc, 'EORI'));
        }
        if (!empty($sellerEori)) {
            $podmiot1->appendChild($xml->createElement('NrEORI', $this->xmlSafe($sellerEori)));
        }

        // Identification
        $daneIdent = $xml->createElement('DaneIdentyfikacyjne');
        $podmiot1->appendChild($daneIdent);

        $nip = '';
        if (!empty($conf->global->KSEF_COMPANY_NIP)) {
            $nip = ksefCleanNIP($conf->global->KSEF_COMPANY_NIP);
        }
        if (empty($nip)) {
            $nip = ksefCleanNIP(ksefGetIdentifierField($mysoc, 'NIP'));
        }
        if (empty($nip)) {
            throw new Exception("Seller NIP is required - configure KSEF");
        }

        $this->sellerName = $this->xmlSafe($mysoc->name);
        $daneIdent->appendChild($xml->createElement('NIP', $nip));
        $daneIdent->appendChild($xml->createElement('Nazwa', $this->sellerName));

        // Address
        $adres = $xml->createElement('Adres');
        $podmiot1->appendChild($adres);

        $adres->appendChild($xml->createElement('KodKraju', $mysoc->country_code ?: 'PL'));

        // AdresL1: Street address
        $address = !empty($mysoc->address) ? $this->xmlSafe($mysoc->address) : 'ul. Testowa 1';
        $adres->appendChild($xml->createElement('AdresL1', $address));

        // AdresL2: Postal code + city
        $adresL2Parts = array();
        if (!empty($mysoc->zip)) {
            $adresL2Parts[] = $this->xmlSafe($mysoc->zip);
        }
        if (!empty($mysoc->town)) {
            $adresL2Parts[] = $this->xmlSafe($mysoc->town);
        }
        if (!empty($adresL2Parts)) {
            $adres->appendChild($xml->createElement('AdresL2', implode(' ', $adresL2Parts)));
        }

        // DaneKontaktowe
        if (!empty($mysoc->email) || !empty($mysoc->phone)) {
            $daneKontakt = $xml->createElement('DaneKontaktowe');
            $podmiot1->appendChild($daneKontakt);

            if (!empty($mysoc->email)) {
                $daneKontakt->appendChild($xml->createElement('Email', $this->xmlSafe($mysoc->email)));
            }
            if (!empty($mysoc->phone)) {
                $daneKontakt->appendChild($xml->createElement('Telefon', $this->xmlSafe($mysoc->phone)));
            }
        }
    }

    /**
     * @brief Builds buyer (Podmiot2) section
     * @param $xml DOMDocument
     * @param $parent Parent element
     * @param $customer Customer object
     * @called_by buildFromInvoice()
     * @calls ksefCleanNIP(), xmlSafe(), getEntityFlags(), isEUCountry()
     */
    private function buildPodmiot2($xml, $parent, $customer)
    {
        global $conf;

        // Buyer
        $podmiot2 = $xml->createElement('Podmiot2');
        $parent->appendChild($podmiot2);

        // NrEORI
        $nrEori = $this->getBuyerNrEORI($customer);
        if (!empty($nrEori) && empty($customer->array_options['options_ksef_hide_eori'])) {
            $podmiot2->appendChild($xml->createElement('NrEORI', $this->xmlSafe($nrEori)));
        }

        $daneIdent = $xml->createElement('DaneIdentyfikacyjne');
        $podmiot2->appendChild($daneIdent);

        $countryCode = ksefInferCountryCode($customer);
        $isPolish = ($countryCode === 'PL');
        $isEU = $this->isEUCountry($countryCode);

        $nipRaw = ksefGetIdentifierField($customer, 'NIP');
        $nip = ksefCleanNIP($nipRaw);

        if ($isPolish && !empty($nip)) {
            $daneIdent->appendChild($xml->createElement('NIP', $nip));
        } elseif ($isEU && !empty($customer->tva_intra)) {
            $vatNumber = ksefStripVATPrefix($customer->tva_intra);
            $daneIdent->appendChild($xml->createElement('KodUE', $countryCode));
            if (!empty($vatNumber)) {
                $daneIdent->appendChild($xml->createElement('NrVatUE', $vatNumber));
            }
        } elseif (!$isPolish && !empty($nipRaw)) {
            $daneIdent->appendChild($xml->createElement('KodKraju', $countryCode));
            $daneIdent->appendChild($xml->createElement('NrID', $this->xmlSafe(ksefStripVATPrefix($nipRaw))));
        } else {
            $daneIdent->appendChild($xml->createElement('BrakID', '1'));
        }

        $this->buyerName = $this->xmlSafe($customer->name);
        $daneIdent->appendChild($xml->createElement('Nazwa', $this->buyerName));

        // Address
        $adres = $xml->createElement('Adres');
        $podmiot2->appendChild($adres);

        $adres->appendChild($xml->createElement('KodKraju', $countryCode));

        // AdresL1: Street address
        $address = !empty($customer->address) ? $this->xmlSafe($customer->address) : 'Brak danych';
        $adres->appendChild($xml->createElement('AdresL1', $address));

        // AdresL2: Postal code + city
        $adresL2Parts = array();
        if (!empty($customer->zip)) {
            $adresL2Parts[] = $this->xmlSafe($customer->zip);
        }
        if (!empty($customer->town)) {
            $adresL2Parts[] = $this->xmlSafe($customer->town);
        }
        if (!empty($adresL2Parts)) {
            $adres->appendChild($xml->createElement('AdresL2', implode(' ', $adresL2Parts)));
        }

        // DaneKontaktowe
        $includeContact = getDolGlobalString('KSEF_FA3_INCLUDE_CONTACT', '1') !== '0'
            && empty($customer->array_options['options_ksef_hide_contact']);
        if ($includeContact && (!empty($customer->email) || !empty($customer->phone))) {
            $daneKontakt = $xml->createElement('DaneKontaktowe');
            $podmiot2->appendChild($daneKontakt);

            if (!empty($customer->email)) {
                $daneKontakt->appendChild($xml->createElement('Email', $this->xmlSafe($customer->email)));
            }
            if (!empty($customer->phone)) {
                $daneKontakt->appendChild($xml->createElement('Telefon', $this->xmlSafe($customer->phone)));
            }
        }

        // NrKlienta: Customer code from Dolibarr
        if (!empty($conf->global->KSEF_FA3_INCLUDE_NRKLIENTA) && !empty($customer->code_client)) {
            $podmiot2->appendChild($xml->createElement('NrKlienta', $this->xmlSafe($customer->code_client)));
        }

        // IDNabywcy
        $idNabywcy = $this->getIdNabywcy($customer);
        if (!empty($idNabywcy)) {
            $podmiot2->appendChild($xml->createElement('IDNabywcy', $this->xmlSafe($idNabywcy)));
        }

        $this->currentCustomerCountry = $countryCode;
        $this->currentCustomerIsEU = $isEU;

        $flags = $this->getEntityFlags($customer);
        $podmiot2->appendChild($xml->createElement('JST', $flags['jst'])); // Local Gov Unit flag
        $podmiot2->appendChild($xml->createElement('GV', $flags['gv']));   // VAT Group flag
    }

    /**
     * @brief Builds Podmiot3
     * @param DOMDocument $xml
     * @param DOMElement  $parent  Faktura root element
     * @param Facture     $invoice current invoice
     * @called_by buildFromInvoice()
     */
    private function buildPodmiot3($xml, $parent, $invoice)
    {
        if (getDolGlobalString('KSEF_PODMIOT3_SOURCE', 'disabled') !== 'enabled') {
            return;
        }
        if (!isset($invoice->array_options) || empty($invoice->array_options)) {
            if (method_exists($invoice, 'fetch_optionals')) {
                $invoice->fetch_optionals();
            }
        }

        $list = $this->getPodmiot3List($invoice);
        if (empty($list)) {
            return;
        }

        $emitted = 0;
        foreach ($list as $e) {
            $this->buildOnePodmiot3($xml, $parent, $e['id'], $e['role'], $e['udzial']);
            if (++$emitted >= 20) break; // soft cap (matches the editor)
        }
    }

    /**
     * @brief Decode stored Podmiot3 list of invoice.
     * @return array List of id int, role string, udzial string
     * @called_by buildPodmiot3(), buildPlatnosc()
     */
    private function getPodmiot3List($invoice)
    {
        if (!isset($invoice->array_options) || empty($invoice->array_options)) {
            if (method_exists($invoice, 'fetch_optionals')) {
                $invoice->fetch_optionals();
            }
        }
        $raw = $invoice->array_options['options_ksef_podmiot3'] ?? '';
        $list = array();
        $decoded = (is_string($raw) && $raw !== '') ? json_decode($raw, true) : null;
        if (is_array($decoded)) {
            foreach ($decoded as $e) {
                $id = (int) ($e['id'] ?? 0);
                if ($id > 0) {
                    $list[] = array(
                        'id'     => $id,
                        'role'   => (string) ($e['role'] ?? ''),
                        'udzial' => trim((string) ($e['udzial'] ?? '')),
                    );
                }
            }
        } elseif ((int) $raw > 0) {
            $list[] = array(
                'id'     => (int) $raw,
                'role'   => trim((string) ($invoice->array_options['options_ksef_podmiot3_role'] ?? '')),
                'udzial' => '',
            );
        }
        return $list;
    }

    /**
     * @brief Build a single Podmiot3 node
     * @param $societeId Societe rowid
     * @param string $role Role code 1-11
     * @param string $udzial Percentage share (role 4 udzial)
     * @called_by buildPodmiot3()
     */
    private function buildOnePodmiot3($xml, $parent, $societeId, $role, $udzial = '')
    {
        require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
        $party = new Societe($this->db);
        if ($party->fetch($societeId) <= 0) {
            return;
        }
        if (method_exists($party, 'fetch_optionals')) {
            $party->fetch_optionals();
        }
        if ($role === '') {
            $role = getDolGlobalString('KSEF_PODMIOT3_ROLE', '6');
        }

        $podmiot3 = $xml->createElement('Podmiot3');
        $parent->appendChild($podmiot3);

        $daneIdent = $xml->createElement('DaneIdentyfikacyjne');
        $podmiot3->appendChild($daneIdent);

        $countryCode = ksefInferCountryCode($party);
        $isPolish = ($countryCode === 'PL');
        $isEU = $this->isEUCountry($countryCode);
        $nipRaw = ksefGetIdentifierField($party, 'NIP');
        $nip = ksefCleanNIP($nipRaw);

        $idwew = '';
        $idwewSource = getDolGlobalString('KSEF_IDWEW_SOURCE', 'disabled');
        if (strpos($idwewSource, 'thirdparty_extrafield:') === 0) {
            $idwewField = substr($idwewSource, strlen('thirdparty_extrafield:'));
            if (!empty($idwewField)) {
                $idwew = trim((string) ($party->array_options['options_' . $idwewField] ?? ''));
            }
        }
        if ($idwew !== '' && preg_match('/^[1-9]((\d[1-9])|([1-9]\d))\d{7}-\d{5}$/', $idwew)) {
            $daneIdent->appendChild($xml->createElement('IDWew', $idwew));
        } elseif ($isPolish && !empty($nip)) {
            $daneIdent->appendChild($xml->createElement('NIP', $nip));
        } elseif ($isEU && !empty($party->tva_intra)) {
            $vatNumber = ksefStripVATPrefix($party->tva_intra);
            $daneIdent->appendChild($xml->createElement('KodUE', $countryCode));
            if (!empty($vatNumber)) {
                $daneIdent->appendChild($xml->createElement('NrVatUE', $vatNumber));
            }
        } elseif (!$isPolish && !empty($nipRaw)) {
            $daneIdent->appendChild($xml->createElement('KodKraju', $countryCode));
            $daneIdent->appendChild($xml->createElement('NrID', $this->xmlSafe(ksefStripVATPrefix($nipRaw))));
        } else {
            $daneIdent->appendChild($xml->createElement('BrakID', '1'));
        }
        if (!empty($party->name)) {
            $daneIdent->appendChild($xml->createElement('Nazwa', $this->xmlSafe($party->name)));
        }

        // Adres
        $adres = $xml->createElement('Adres');
        $podmiot3->appendChild($adres);
        $adres->appendChild($xml->createElement('KodKraju', $countryCode));
        $address = !empty($party->address) ? $this->xmlSafe($party->address) : 'Brak danych';
        $adres->appendChild($xml->createElement('AdresL1', $address));
        $adresL2Parts = array();
        if (!empty($party->zip)) {
            $adresL2Parts[] = $this->xmlSafe($party->zip);
        }
        if (!empty($party->town)) {
            $adresL2Parts[] = $this->xmlSafe($party->town);
        }
        if (!empty($adresL2Parts)) {
            $adres->appendChild($xml->createElement('AdresL2', implode(' ', $adresL2Parts)));
        }

        // Role
        $podmiot3->appendChild($xml->createElement('Rola', $this->xmlSafe($role)));

        // Udzial
        if ($role === '4' && $udzial !== '') {
            $udzialVal = (float) str_replace(',', '.', $udzial);
            if ($udzialVal > 0 && $udzialVal <= 100) {
                // xsd:decimal: dot separator, max 2 fraction digits, no forced trailing zeros
                $udzialStr = rtrim(rtrim(number_format($udzialVal, 2, '.', ''), '0'), '.');
                $podmiot3->appendChild($xml->createElement('Udzial', $udzialStr));
            }
        }
    }

    /**
     * @brief Builds invoice data (Fa) section
     * @param $xml DOMDocument
     * @param $parent Parent element
     * @param $invoice Invoice object
     * @param $originalInvoice Original invoice for corrections
     * @param $originalXmlData Parsed original XML data (for KOR comparison)
     * @called_by buildFromInvoice()
     * @calls calculateVatSummary(), getInvoiceType(), buildDaneFaKorygowanej(), buildAdnotacje(), buildFaWiersz(), buildPlatnosc()
     */
    private function buildFa($xml, $parent, $invoice, $originalInvoice = null, $originalXmlData = null)
    {
        global $conf, $mysoc;

        $fa = $xml->createElement('Fa');
        $parent->appendChild($fa);

        $invoiceType = $this->getInvoiceType($invoice);
        $this->lastInvoiceType = $invoiceType;

        dol_include_once('/ksef/lib/ksef.lib.php');

        $invoiceCurrency = ksefGetInvoiceCurrency($invoice);

        if (!isset($invoice->array_options) || empty($invoice->array_options)) {
            $invoice->fetch_optionals();
        }

        $kursWaluty = null;
        if ($invoiceCurrency != 'PLN') {

            $dolibarrRate = $invoice->multicurrency_tx ?? 0;
            if ($dolibarrRate <= 0) {
                throw new Exception("Brak kursu NBP dla faktury w walucie obcej ($invoiceCurrency). Pobierz kurs przed wysłaniem do KSeF.");
            }

            // Invert Dolibarr rate to get KSeF rate
            $kursWaluty = 1 / $dolibarrRate;
        }
        $this->currentInvoiceCurrency = $invoiceCurrency;
        $this->currentKursWaluty = $kursWaluty;
        $useMulticurrency = ($invoiceCurrency != 'PLN');
        $fa->appendChild($xml->createElement('KodWaluty', $invoiceCurrency));

        // P_1: Invoice Date
        $fa->appendChild($xml->createElement('P_1', dol_print_date($invoice->date, '%Y-%m-%d')));

        // P_1M: Place of issue
        $placeOfIssueMode = $conf->global->KSEF_FA3_PLACE_OF_ISSUE_MODE ?? 'disabled';
        if ($placeOfIssueMode != 'disabled') {
            $placeOfIssue = '';
            if ($placeOfIssueMode == 'custom' && !empty($conf->global->KSEF_FA3_PLACE_OF_ISSUE_CUSTOM)) {
                $placeOfIssue = $conf->global->KSEF_FA3_PLACE_OF_ISSUE_CUSTOM;
            } elseif ($placeOfIssueMode == 'company' && !empty($mysoc->town)) {
                $placeOfIssue = $mysoc->town;
            }
            if (!empty($placeOfIssue)) {
                $fa->appendChild($xml->createElement('P_1M', $this->xmlSafe($placeOfIssue)));
            }
        }

        // P_2: Invoice Number
        $fa->appendChild($xml->createElement('P_2', $this->xmlSafe($invoice->ref)));
        // P_6: Sale Date
        $saleDate = $invoice->date;
        if (!empty($invoice->array_options['options_ksef_sale_date'])) {
            $saleDate = $invoice->array_options['options_ksef_sale_date'];
        }
        $fa->appendChild($xml->createElement('P_6', dol_print_date($saleDate, '%Y-%m-%d')));

        // Net/Tax Amounts (P_13_* / P_14_*) - in invoice currency!!!!
        $vatSummary = $this->calculateVatSummary($invoice, $useMulticurrency);

        // KOR TYPE_REPLACEMENT: P_13_x/P_14_x must contain delta
        if ($invoiceType == 'KOR' && $invoice->type == Facture::TYPE_REPLACEMENT && $originalInvoice) {
            $origVatSummary = $this->calculateVatSummary($originalInvoice, $useMulticurrency);
            foreach ($origVatSummary as $rate => $amounts) {
                if (isset($vatSummary[$rate])) {
                    $vatSummary[$rate]['net'] -= $amounts['net'];
                    $vatSummary[$rate]['vat'] -= $amounts['vat'];
                } else {
                    $vatSummary[$rate] = array('net' => -$amounts['net'], 'vat' => -$amounts['vat']);
                }
            }
        }

        // P_13_x/P_14_x: by rate
        $isMulticurrency = ($invoiceCurrency != 'PLN' && $kursWaluty > 0);

        // 23%/22% - P_13_1, P_14_1, P_14_1W
        if (isset($vatSummary['23']) || isset($vatSummary['22'])) {
            $net = ($vatSummary['23']['net'] ?? 0) + ($vatSummary['22']['net'] ?? 0);
            $vat = ($vatSummary['23']['vat'] ?? 0) + ($vatSummary['22']['vat'] ?? 0);
            $fa->appendChild($xml->createElement('P_13_1', number_format($net, 2, '.', '')));
            $fa->appendChild($xml->createElement('P_14_1', number_format($vat, 2, '.', '')));
            if ($isMulticurrency) {
                $fa->appendChild($xml->createElement('P_14_1W', number_format($vat * $kursWaluty, 2, '.', '')));
            }
        }

        // 8%/7% - P_13_2, P_14_2, P_14_2W
        if (isset($vatSummary['8']) || isset($vatSummary['7'])) {
            $net = ($vatSummary['8']['net'] ?? 0) + ($vatSummary['7']['net'] ?? 0);
            $vat = ($vatSummary['8']['vat'] ?? 0) + ($vatSummary['7']['vat'] ?? 0);
            $fa->appendChild($xml->createElement('P_13_2', number_format($net, 2, '.', '')));
            $fa->appendChild($xml->createElement('P_14_2', number_format($vat, 2, '.', '')));
            if ($isMulticurrency) {
                $fa->appendChild($xml->createElement('P_14_2W', number_format($vat * $kursWaluty, 2, '.', '')));
            }
        }

        // 5% - P_13_3, P_14_3, P_14_3W
        if (isset($vatSummary['5'])) {
            $fa->appendChild($xml->createElement('P_13_3', number_format($vatSummary['5']['net'], 2, '.', '')));
            $fa->appendChild($xml->createElement('P_14_3', number_format($vatSummary['5']['vat'], 2, '.', '')));
            if ($isMulticurrency) {
                $fa->appendChild($xml->createElement('P_14_3W', number_format($vatSummary['5']['vat'] * $kursWaluty, 2, '.', '')));
            }
        }

        // 4% - P_13_4, P_14_4, P_14_4W
        if (isset($vatSummary['4'])) {
            $fa->appendChild($xml->createElement('P_13_4', number_format($vatSummary['4']['net'], 2, '.', '')));
            $fa->appendChild($xml->createElement('P_14_4', number_format($vatSummary['4']['vat'], 2, '.', '')));
            if ($isMulticurrency) {
                $fa->appendChild($xml->createElement('P_14_4W', number_format($vatSummary['4']['vat'] * $kursWaluty, 2, '.', '')));
            }
        }

        // 3% (special procedure) - P_13_5, P_14_5
        if (isset($vatSummary['3'])) {
            $fa->appendChild($xml->createElement('P_13_5', number_format($vatSummary['3']['net'], 2, '.', '')));
            if (!empty($vatSummary['3']['vat'])) {
                $fa->appendChild($xml->createElement('P_14_5', number_format($vatSummary['3']['vat'], 2, '.', '')));
            }
        }

        // 0% domestic (KR) - P_13_6_1
        if (isset($vatSummary['0 KR'])) {
            $fa->appendChild($xml->createElement('P_13_6_1', number_format($vatSummary['0 KR']['net'], 2, '.', '')));
        }
        // 0% intra-EU (WDT) - P_13_6_2
        if (isset($vatSummary['0 WDT'])) {
            $fa->appendChild($xml->createElement('P_13_6_2', number_format($vatSummary['0 WDT']['net'], 2, '.', '')));
        }
        // 0% export - P_13_6_3
        if (isset($vatSummary['0 EX'])) {
            $fa->appendChild($xml->createElement('P_13_6_3', number_format($vatSummary['0 EX']['net'], 2, '.', '')));
        }
        // Exempt (zw) - P_13_7
        if (isset($vatSummary['zw'])) {
            $fa->appendChild($xml->createElement('P_13_7', number_format($vatSummary['zw']['net'], 2, '.', '')));
        }
        // Not subject I (np I) - P_13_8
        if (isset($vatSummary['np I'])) {
            $fa->appendChild($xml->createElement('P_13_8', number_format($vatSummary['np I']['net'], 2, '.', '')));
        }
        // Not subject II (np II, art. 100) - P_13_9
        if (isset($vatSummary['np II'])) {
            $fa->appendChild($xml->createElement('P_13_9', number_format($vatSummary['np II']['net'], 2, '.', '')));
        }
        // Reverse charge (oo) - P_13_10
        if (isset($vatSummary['oo'])) {
            $fa->appendChild($xml->createElement('P_13_10', number_format($vatSummary['oo']['net'], 2, '.', '')));
        }

        // P_15: Total (KOR: delta)
        $totalAmount = $useMulticurrency ? $invoice->multicurrency_total_ttc : $invoice->total_ttc;
        if ($invoiceType == 'KOR' && $invoice->type == Facture::TYPE_REPLACEMENT && $originalInvoice) {
            $origTotal = $useMulticurrency ? $originalInvoice->multicurrency_total_ttc : $originalInvoice->total_ttc;
            $totalAmount -= $origTotal;
        }
        $fa->appendChild($xml->createElement('P_15', number_format($totalAmount, 2, '.', '')));

        $this->buildAdnotacje($xml, $fa, $invoice, $vatSummary, $originalInvoice);

        // Invoice Type
        $fa->appendChild($xml->createElement('RodzajFaktury', $invoiceType));

        if ($invoiceType == 'KOR') {
            $corrReason = $invoice->array_options['options_ksef_correction_reason'] ?? '';
            $corrType = $invoice->array_options['options_ksef_correction_type'] ?? '';
            if (!empty($corrReason)) {
                $fa->appendChild($xml->createElement('PrzyczynaKorekty', $this->xmlSafe($corrReason)));
            }
            if (!empty($corrType) && in_array((int) $corrType, array(1, 2, 3))) {
                $fa->appendChild($xml->createElement('TypKorekty', (int) $corrType));
            }
            if ($originalInvoice) {
                $this->buildDaneFaKorygowanej($xml, $fa, $originalInvoice);
            }

            // Podmiot1K / Podmiot2K: emit original data if changed
            if ($originalXmlData) {
                $this->buildPodmiot1K($xml, $fa, $originalXmlData);
                $this->buildPodmiot2K($xml, $fa, $originalXmlData);
            }
        }

        if ($this->resolveBooleanFlagSource(getDolGlobalString('KSEF_FA3_FP_SOURCE', 'disabled'), $invoice)) {
            $fa->appendChild($xml->createElement('FP', '1'));
        }

        // TP
        if ($this->isRelatedPartyTransaction($invoice)) {
            $fa->appendChild($xml->createElement('TP', '1'));
        }

        // Additional Description (DodatkowyOpis)
        $this->buildDodatkowyOpis($xml, $fa, $invoice);

        // Invoice Lines
        $originalForLines = ($invoiceType == 'KOR') ? $originalInvoice : null;
        $this->buildFaWiersz($xml, $fa, $invoice, $originalForLines);

        // Payment Info
        $this->buildPlatnosc($xml, $fa, $invoice);

        // Transaction conditions
        $this->buildWarunkiTransakcji($xml, $fa, $invoice);
    }

    /**
     * @brief Calculates VAT summary by rate
     * @param $invoice Invoice object
     * @return array VAT summary array
     * @called_by buildFa()
     */
    private function calculateVatSummary($invoice, $useMulticurrency = false)
    {
        $summary = array();
        foreach ($invoice->lines as $line) {
            $ksefRate = $this->mapVatRateToKSeF($line->tva_tx, $line);
            if (!isset($summary[$ksefRate])) $summary[$ksefRate] = array('net' => 0, 'vat' => 0);

            if ($useMulticurrency) {
                $summary[$ksefRate]['net'] += $line->multicurrency_total_ht;
                $summary[$ksefRate]['vat'] += $line->multicurrency_total_tva;
            } else {
                $summary[$ksefRate]['net'] += $line->total_ht;
                $summary[$ksefRate]['vat'] += $line->total_tva;
            }
        }
        return $summary;
    }

    /**
     * @brief Determines invoice type (VAT, KOR, ZAL)
     * @param $invoice Invoice object
     * @return string Invoice type code
     * @called_by buildFa()
     */
    private function getInvoiceType($invoice)
    {
        switch ($invoice->type) {
            case Facture::TYPE_CREDIT_NOTE:
                return 'KOR';
            case Facture::TYPE_DEPOSIT:
                return 'ZAL';
            case Facture::TYPE_REPLACEMENT:
                return 'KOR';
            case Facture::TYPE_STANDARD:
            case Facture::TYPE_SITUATION:
            default:
                return 'VAT';
        }
    }

    /**
     * @brief Builds corrective invoice reference section
     * @param $xml DOMDocument
     * @param $parent Parent element
     * @param $originalInvoice Original invoice object
     * @called_by buildFa()
     * @calls getInvoiceKsefNumber()
     */
    private function buildDaneFaKorygowanej($xml, $parent, $originalInvoice)
    {
        // Only reference metadata (no amounts)
        $daneFaKor = $xml->createElement('DaneFaKorygowanej');
        $parent->appendChild($daneFaKor);

        // Date of original invoice
        $daneFaKor->appendChild($xml->createElement('DataWystFaKorygowanej', dol_print_date($originalInvoice->date, '%Y-%m-%d')));
        // Number of original invoice
        $daneFaKor->appendChild($xml->createElement('NrFaKorygowanej', $this->xmlSafe($originalInvoice->ref)));

        // KSeF reference check
        $originalKsefNumber = $this->getInvoiceKsefNumber($originalInvoice->id);

        if ($originalKsefNumber) {
            $daneFaKor->appendChild($xml->createElement('NrKSeF', '1'));
            $daneFaKor->appendChild($xml->createElement('NrKSeFFaKorygowanej', $originalKsefNumber));
        } else {
            $daneFaKor->appendChild($xml->createElement('NrKSeFN', '1'));
        }
    }

    /**
     * @brief Fetch Podmiot1/Podmiot2 from original FA3 XML
     * @param int $originalInvoiceId
     * @return array|null podmiot1/podmiot2 data, or null
     * @called_by buildFromInvoice()
     */
    private function fetchOriginalXmlData($originalInvoiceId)
    {
        dol_include_once('/ksef/class/ksef_submission.class.php');
        $submission = new KsefSubmission($this->db);
        if ($submission->fetchByInvoice($originalInvoiceId) <= 0 || empty($submission->fa3_xml)) {
            return null;
        }

        $origXml = new DOMDocument();
        if (!@$origXml->loadXML($submission->fa3_xml)) {
            return null;
        }

        return $this->extractPodmiotData($origXml);
    }

    /**
     * @brief Reconstruct the correction before-state when no FA(3) XML was archived (H3a)
     * @param Facture $originalInvoice
     * @return array|null
     */
    private function reconstructOriginalXmlData($originalInvoice)
    {
        if ($this->reconstructing) {
            return null;
        }
        $saved = array(
            $this->currentCustomer, $this->currentCustomerCountry, $this->currentCustomerIsEU,
            $this->currentInvoiceCurrency, $this->currentKursWaluty,
            $this->lastInvoiceType, $this->lastCreationDate, $this->lastXmlHash,
            $this->error, $this->errors,
        );
        $this->reconstructing = true;
        $xmlString = $this->buildFromInvoice($originalInvoice->id);
        $this->reconstructing = false;
        list($this->currentCustomer, $this->currentCustomerCountry, $this->currentCustomerIsEU,
            $this->currentInvoiceCurrency, $this->currentKursWaluty,
            $this->lastInvoiceType, $this->lastCreationDate, $this->lastXmlHash,
            $this->error, $this->errors) = $saved;

        if (!is_string($xmlString) || $xmlString === '') {
            dol_syslog("FA3Builder::reconstructOriginalXmlData - could not rebuild original "
                . $originalInvoice->id . " for the correction before-state; Podmiot1K/2K omitted", LOG_WARNING);
            return null;
        }
        $doc = new DOMDocument();
        if (!@$doc->loadXML($xmlString)) {
            return null;
        }
        return $this->extractPodmiotData($doc);
    }

    /**
     * @brief Extract Podmiot1/Podmiot2 before-state fields from an FA(3) DOMDocument
     * @param DOMDocument $origXml Parsed FA(3) document
     * @return array podmiot1/podmiot2 field maps
     * @remarks Shared by fetchOriginalXmlData (stored XML) and reconstructOriginalXmlData (rebuilt XML).
     */
    private function extractPodmiotData($origXml)
    {
        $xpath = new DOMXPath($origXml);
        $xpath->registerNamespace('fa', KSEF_FA3_NAMESPACE);

        $data = array('podmiot1' => array(), 'podmiot2' => array());

        // Parse Podmiot1
        $data['podmiot1']['prefiks_podatnika'] = $this->xpathValue($xpath, '//fa:Podmiot1/fa:PrefiksPodatnika');
        $data['podmiot1']['nip'] = $this->xpathValue($xpath, '//fa:Podmiot1/fa:DaneIdentyfikacyjne/fa:NIP');
        $data['podmiot1']['nazwa'] = $this->xpathValue($xpath, '//fa:Podmiot1/fa:DaneIdentyfikacyjne/fa:Nazwa');
        $data['podmiot1']['nr_eori'] = $this->xpathValue($xpath, '//fa:Podmiot1/fa:NrEORI');
        $data['podmiot1']['gln'] = $this->xpathValue($xpath, '//fa:Podmiot1/fa:Adres/fa:GLN');
        $data['podmiot1']['kod_kraju'] = $this->xpathValue($xpath, '//fa:Podmiot1/fa:Adres/fa:KodKraju');
        $data['podmiot1']['adres_l1'] = $this->xpathValue($xpath, '//fa:Podmiot1/fa:Adres/fa:AdresL1');
        $data['podmiot1']['adres_l2'] = $this->xpathValue($xpath, '//fa:Podmiot1/fa:Adres/fa:AdresL2');

        // Parse Podmiot2
        $data['podmiot2']['nip'] = $this->xpathValue($xpath, '//fa:Podmiot2/fa:DaneIdentyfikacyjne/fa:NIP');
        $data['podmiot2']['kod_ue'] = $this->xpathValue($xpath, '//fa:Podmiot2/fa:DaneIdentyfikacyjne/fa:KodUE');
        $data['podmiot2']['nr_vat_ue'] = $this->xpathValue($xpath, '//fa:Podmiot2/fa:DaneIdentyfikacyjne/fa:NrVatUE');
        $data['podmiot2']['kod_kraju_id'] = $this->xpathValue($xpath, '//fa:Podmiot2/fa:DaneIdentyfikacyjne/fa:KodKraju');
        $data['podmiot2']['nr_id'] = $this->xpathValue($xpath, '//fa:Podmiot2/fa:DaneIdentyfikacyjne/fa:NrID');
        $data['podmiot2']['brak_id'] = $this->xpathValue($xpath, '//fa:Podmiot2/fa:DaneIdentyfikacyjne/fa:BrakID');
        $data['podmiot2']['nazwa'] = $this->xpathValue($xpath, '//fa:Podmiot2/fa:DaneIdentyfikacyjne/fa:Nazwa');
        $data['podmiot2']['id_nabywcy'] = $this->xpathValue($xpath, '//fa:Podmiot2/fa:IDNabywcy');
        $data['podmiot2']['nr_eori'] = $this->xpathValue($xpath, '//fa:Podmiot2/fa:NrEORI');
        $data['podmiot2']['gln'] = $this->xpathValue($xpath, '//fa:Podmiot2/fa:Adres/fa:GLN');
        $data['podmiot2']['kod_kraju'] = $this->xpathValue($xpath, '//fa:Podmiot2/fa:Adres/fa:KodKraju');
        $data['podmiot2']['adres_l1'] = $this->xpathValue($xpath, '//fa:Podmiot2/fa:Adres/fa:AdresL1');
        $data['podmiot2']['adres_l2'] = $this->xpathValue($xpath, '//fa:Podmiot2/fa:Adres/fa:AdresL2');

        return $data;
    }

    /**
     * @brief Get single XPath text value
     * @param DOMXPath $xpath
     * @param string $expression
     * @return string
     */
    private function xpathValue($xpath, $expression)
    {
        $nodes = $xpath->query($expression);
        return ($nodes && $nodes->length > 0) ? $nodes->item(0)->textContent : '';
    }

    /**
     * @brief Emit Podmiot1K if seller data changed
     * @param $xml DOMDocument
     * @param $parent Fa element
     * @param array $originalXmlData
     * @called_by buildFa()
     */
    private function buildPodmiot1K($xml, $parent, $originalXmlData)
    {
        global $conf, $mysoc;

        $orig = $originalXmlData['podmiot1'];

        // Current seller values
        $currentPrefiks = '';
        $buyerCountry = ksefInferCountryCode($this->currentCustomer);
        if ($buyerCountry != 'PL' && !empty($mysoc->tva_intra)) {
            $vatIntra = strtoupper(trim($mysoc->tva_intra));
            if (preg_match('/^([A-Z]{2})/', $vatIntra, $matches)) {
                $currentPrefiks = $matches[1];
            }
        }

        $nip = '';
        if (!empty($conf->global->KSEF_COMPANY_NIP)) {
            $nip = ksefCleanNIP($conf->global->KSEF_COMPANY_NIP);
        }
        if (empty($nip)) {
            $nip = ksefCleanNIP(ksefGetIdentifierField($mysoc, 'NIP'));
        }
        $currentNazwa = $this->xmlSafe($mysoc->name);

        $currentNrEori = getDolGlobalString('KSEF_COMPANY_EORI', '');
        if (empty($currentNrEori)) {
            $currentNrEori = trim(ksefGetIdentifierField($mysoc, 'EORI'));
        }

        $currentKodKraju = $mysoc->country_code ?: 'PL';
        $currentAdresL1 = !empty($mysoc->address) ? $this->xmlSafe($mysoc->address) : 'ul. Testowa 1';
        $adresL2Parts = array();
        if (!empty($mysoc->zip)) $adresL2Parts[] = $this->xmlSafe($mysoc->zip);
        if (!empty($mysoc->town)) $adresL2Parts[] = $this->xmlSafe($mysoc->town);
        $currentAdresL2 = implode(' ', $adresL2Parts);

        // Compare
        if (($orig['prefiks_podatnika'] ?? '') === $currentPrefiks
            && $orig['nip'] === $nip
            && $orig['nazwa'] === $currentNazwa
            && ($orig['nr_eori'] ?? '') === $currentNrEori
            && ($orig['gln'] ?? '') === ''
            && $orig['kod_kraju'] === $currentKodKraju
            && $orig['adres_l1'] === $currentAdresL1
            && $orig['adres_l2'] === $currentAdresL2) {
            return; // No change
        }

        // Emit Podmiot1K
        $podmiot1k = $xml->createElement('Podmiot1K');
        $parent->appendChild($podmiot1k);

        if (!empty($orig['prefiks_podatnika'])) {
            $podmiot1k->appendChild($xml->createElement('PrefiksPodatnika', $orig['prefiks_podatnika']));
        }

        $daneIdent = $xml->createElement('DaneIdentyfikacyjne');
        $podmiot1k->appendChild($daneIdent);
        if (!empty($orig['nip'])) {
            $daneIdent->appendChild($xml->createElement('NIP', $orig['nip']));
        }
        if (!empty($orig['nazwa'])) {
            $daneIdent->appendChild($xml->createElement('Nazwa', $orig['nazwa']));
        }

        $adres = $xml->createElement('Adres');
        $podmiot1k->appendChild($adres);
        $adres->appendChild($xml->createElement('KodKraju', !empty($orig['kod_kraju']) ? $orig['kod_kraju'] : 'PL'));
        $adres->appendChild($xml->createElement('AdresL1', !empty($orig['adres_l1']) ? $orig['adres_l1'] : 'Brak danych'));
        if (!empty($orig['adres_l2'])) {
            $adres->appendChild($xml->createElement('AdresL2', $orig['adres_l2']));
        }
        if (!empty($orig['gln'])) {
            $adres->appendChild($xml->createElement('GLN', $orig['gln']));
        }
    }

    /**
     * @brief Emit Podmiot2K if buyer data changed
     * @param $xml DOMDocument
     * @param $parent Fa element
     * @param array $originalXmlData Parsed original XML data
     * @called_by buildFa()
     */
    private function buildPodmiot2K($xml, $parent, $originalXmlData)
    {
        $orig = $originalXmlData['podmiot2'];
        $customer = $this->currentCustomer;

        // Current buyer identification
        $countryCode = ksefInferCountryCode($customer);
        $isPolish = ($countryCode === 'PL');
        $isEU = $this->isEUCountry($countryCode);
        $nipRaw = ksefGetIdentifierField($customer, 'NIP');
        $nip = ksefCleanNIP($nipRaw);

        // Current identification
        $currentNip = ($isPolish && !empty($nip)) ? $nip : '';
        $currentKodUE = ($isEU && !$isPolish && !empty($customer->tva_intra)) ? $countryCode : '';
        $currentNrVatUE = '';
        if (!empty($currentKodUE)) {
            $currentNrVatUE = ksefStripVATPrefix($customer->tva_intra);
        }
        $currentKodKrajuId = (!$isPolish && empty($currentKodUE) && !empty($nipRaw)) ? $countryCode : '';
        $currentNrId = !empty($currentKodKrajuId) ? ksefStripVATPrefix($nipRaw) : '';
        $currentBrakId = (empty($currentNip) && empty($currentKodUE) && empty($currentKodKrajuId)) ? '1' : '';

        $currentNazwa = $this->xmlSafe($customer->name);
        $currentIdNabywcy = $this->getIdNabywcy($customer);
        $currentNrEori = empty($customer->array_options['options_ksef_hide_eori']) ? $this->getBuyerNrEORI($customer) : '';
        $currentKodKraju = $countryCode;
        $currentAdresL1 = !empty($customer->address) ? $this->xmlSafe($customer->address) : 'Brak danych';
        $adresL2Parts = array();
        if (!empty($customer->zip)) $adresL2Parts[] = $this->xmlSafe($customer->zip);
        if (!empty($customer->town)) $adresL2Parts[] = $this->xmlSafe($customer->town);
        $currentAdresL2 = implode(' ', $adresL2Parts);

        // Compare identification
        $identSame = ($orig['nip'] === $currentNip
            && $orig['kod_ue'] === $currentKodUE
            && $orig['nr_vat_ue'] === $currentNrVatUE
            && $orig['kod_kraju_id'] === $currentKodKrajuId
            && $orig['nr_id'] === $currentNrId
            && $orig['brak_id'] === $currentBrakId
            && $orig['nazwa'] === $currentNazwa
            && ($orig['id_nabywcy'] ?? '') === $currentIdNabywcy
            && ($orig['nr_eori'] ?? '') === $currentNrEori);

        // Compare address
        $addrSame = (($orig['gln'] ?? '') === ''
            && $orig['kod_kraju'] === $currentKodKraju
            && $orig['adres_l1'] === $currentAdresL1
            && $orig['adres_l2'] === $currentAdresL2);

        if ($identSame && $addrSame) {
            return; // No change
        }

        $origHasIdent = !empty($orig['nip']) || !empty($orig['kod_ue']) || !empty($orig['kod_kraju_id']) || !empty($orig['nr_id']);
        if (!$origHasIdent && ($orig['brak_id'] ?? '') !== '1') {
            dol_syslog("FA3Builder::buildPodmiot2K - original buyer identity unreproducible from stored XML; Podmiot2K omitted to avoid a false BrakID", LOG_WARNING);
            return;
        }

        // Emit Podmiot2K
        $podmiot2k = $xml->createElement('Podmiot2K');
        $parent->appendChild($podmiot2k);

        $daneIdent = $xml->createElement('DaneIdentyfikacyjne');
        $podmiot2k->appendChild($daneIdent);

        if (!empty($orig['nip'])) {
            $daneIdent->appendChild($xml->createElement('NIP', $orig['nip']));
        } elseif (!empty($orig['kod_ue'])) {
            $daneIdent->appendChild($xml->createElement('KodUE', $orig['kod_ue']));
            if (!empty($orig['nr_vat_ue'])) {
                $daneIdent->appendChild($xml->createElement('NrVatUE', $orig['nr_vat_ue']));
            }
        } elseif (!empty($orig['kod_kraju_id']) || !empty($orig['nr_id'])) {
            if (!empty($orig['kod_kraju_id'])) {
                $daneIdent->appendChild($xml->createElement('KodKraju', $orig['kod_kraju_id']));
            }
            if (!empty($orig['nr_id'])) {
                $daneIdent->appendChild($xml->createElement('NrID', $orig['nr_id']));
            }
        } else {
            $daneIdent->appendChild($xml->createElement('BrakID', '1'));
        }
        if (!empty($orig['nazwa'])) {
            $daneIdent->appendChild($xml->createElement('Nazwa', $orig['nazwa']));
        }

        $adres = $xml->createElement('Adres');
        $podmiot2k->appendChild($adres);
        $adres->appendChild($xml->createElement('KodKraju', !empty($orig['kod_kraju']) ? $orig['kod_kraju'] : 'PL'));
        $adres->appendChild($xml->createElement('AdresL1', !empty($orig['adres_l1']) ? $orig['adres_l1'] : 'Brak danych'));
        if (!empty($orig['adres_l2'])) {
            $adres->appendChild($xml->createElement('AdresL2', $orig['adres_l2']));
        }
        if (!empty($orig['gln'])) {
            $adres->appendChild($xml->createElement('GLN', $orig['gln']));
        }
        if (!empty($orig['id_nabywcy'])) {
            $podmiot2k->appendChild($xml->createElement('IDNabywcy', $orig['id_nabywcy']));
        }
    }

    /**
     * @brief Get IDNabywcy for customer
     * @param $customer Thirdparty
     * @return string IDNabywcy or empty
     */
    private function getIdNabywcy($customer)
    {
        $source = getDolGlobalString('KSEF_IDNABYWCY_SOURCE', 'disabled');
        if ($source === 'disabled') {
            return '';
        }
        if ($source === 'code_client') {
            return !empty($customer->code_client) ? $customer->code_client : '';
        }
        if (strpos($source, 'thirdparty_extrafield:') === 0) {
            $fieldName = substr($source, strlen('thirdparty_extrafield:'));
            if (!empty($fieldName) && isset($customer->array_options['options_' . $fieldName])) {
                return trim($customer->array_options['options_' . $fieldName]);
            }
        }
        return '';
    }

    /**
     * @brief Get NrEORI for buyer
     * @param $customer Thirdparty
     * @return string NrEORI or empty
     */
    private function getBuyerNrEORI($customer)
    {
        return trim(ksefGetIdentifierField($customer, 'EORI'));
    }

    /**
     * @brief Get product extrafield value
     * @param $line Invoice line
     * @param string $fieldName Extrafield code
     * @return string Value or empty
     */
    private function getProductExtraFieldValue($line, $fieldName)
    {
        if (empty($line->fk_product) || empty($fieldName)) {
            return '';
        }
        if (!isset($this->productCache[$line->fk_product])) {
            require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
            $prod = new Product($this->db);
            if ($prod->fetch($line->fk_product) > 0) {
                if (method_exists($prod, 'fetch_optionals')) $prod->fetch_optionals();
                $this->productCache[$line->fk_product] = $prod;
            } else {
                $this->productCache[$line->fk_product] = false;
            }
        }
        if (empty($this->productCache[$line->fk_product])) {
            return '';
        }
        $val = $this->productCache[$line->fk_product]->array_options['options_' . $fieldName] ?? '';
        return trim(strip_tags((string) $val));
    }

    /**
     * @brief Get GTU marker for line
     * @param $line Invoice line
     * @return string GTU_01..GTU_13 or empty
     */
    private function getLineGTU($line)
    {
        $source = getDolGlobalString('KSEF_GTU_SOURCE', 'disabled');
        if ($source === 'disabled') {
            return '';
        }
        if (strpos($source, 'product_extrafield:') === 0) {
            $fieldName = substr($source, strlen('product_extrafield:'));
            $val = $this->getProductExtraFieldValue($line, $fieldName);
            if (preg_match('/^GTU_(0[1-9]|1[0-3])$/', $val)) {
                return $val;
            }
        }
        return '';
    }

    /**
     * @brief Get Procedura marker for line
     * @param $line Invoice line
     * @return string Procedura or empty
     */
    private function getLineProcedura($line)
    {
        $source = getDolGlobalString('KSEF_PROCEDURA_SOURCE', 'disabled');
        if ($source === 'disabled') {
            return '';
        }
        $validValues = array('WSTO_EE', 'IED', 'TT_D', 'I_42', 'I_63', 'B_SPV', 'B_SPV_DOSTAWA', 'B_MPV_PROWIZJA', 'MPP');
        if (strpos($source, 'product_extrafield:') === 0) {
            $fieldName = substr($source, strlen('product_extrafield:'));
            $val = $this->getProductExtraFieldValue($line, $fieldName);
            if (in_array($val, $validValues)) {
                return $val;
            }
        }
        return '';
    }

    /**
     * @brief Resolve Adnotacje flag
     * @param string $source  'disabled' 'always_on' 'extrafield:name' thirdparty_extrafield:name;
     * @param object $invoice  current invoice (for extrafield: mode)
     * @param object|null $fallbackInvoice  for correction inheritance
     * @return bool
     * @called_by buildFa(), buildAdnotacje(), isRelatedPartyTransaction()
     */
    private function resolveBooleanFlagSource($source, $invoice, $fallbackInvoice = null)
    {
        if ($source === 'disabled' || $source === '') {
            return false;
        }
        if ($source === 'always_on') {
            return true;
        }
        if (strpos($source, 'thirdparty_extrafield:') === 0) {
            $fieldName = substr($source, strlen('thirdparty_extrafield:'));
            return !empty($fieldName) && !empty($this->currentCustomer->array_options['options_' . $fieldName]);
        }
        if (strpos($source, 'extrafield:') === 0) {
            $fieldName = substr($source, strlen('extrafield:'));
            if (!empty($fieldName) && !empty($invoice->array_options['options_' . $fieldName])) {
                return true;
            }
            if ($fallbackInvoice !== null && !empty($fieldName)
                && !empty($fallbackInvoice->array_options['options_' . $fieldName])) {
                return true;
            }
        }
        return false;
    }

    /**
     * @brief Check if invoice is TP
     * @param $invoice
     * @return bool
     */
    private function isRelatedPartyTransaction($invoice)
    {
        return $this->resolveBooleanFlagSource(
            getDolGlobalString('KSEF_TP_SOURCE', 'disabled'), $invoice);
    }

    /**
     * @brief Builds annotations/flags section
     * @param $xml DOMDocument
     * @param $parent Parent element
     * @param $invoice Invoice object
     * @param $vatSummary VAT summary by rate
     * @param object|null $originalInvoice  source invoice for a correction (P_18A inheritance); null otherwise
     * @called_by buildFa()
     */
    private function buildAdnotacje($xml, $parent, $invoice, $vatSummary = array(), $originalInvoice = null)
    {
        global $conf;

        $adnotacje = $xml->createElement('Adnotacje');
        $parent->appendChild($adnotacje);

        $p16On = $this->resolveBooleanFlagSource(getDolGlobalString('KSEF_P16_SOURCE', 'disabled'), $invoice);
        $p17On = $this->resolveBooleanFlagSource(getDolGlobalString('KSEF_P17_SOURCE', 'disabled'), $invoice) || $this->resolveBooleanFlagSource(getDolGlobalString('KSEF_P17_TP_SOURCE', 'disabled'), $invoice);
        $p18On = $this->resolveBooleanFlagSource(getDolGlobalString('KSEF_P18_SOURCE', 'disabled'), $invoice) || $this->resolveBooleanFlagSource(getDolGlobalString('KSEF_P18_TP_SOURCE', 'disabled'), $invoice);
        $mppOn = $this->resolveBooleanFlagSource(getDolGlobalString('KSEF_FA3_MPP_SOURCE', 'disabled'), $invoice, $originalInvoice);

        $adnotacje->appendChild($xml->createElement('P_16',  $p16On ? '1' : '2')); // Cash accounting
        $adnotacje->appendChild($xml->createElement('P_17',  $p17On ? '1' : '2')); // Self-bill
        $adnotacje->appendChild($xml->createElement('P_18',  $p18On ? '1' : '2')); // Reverse charge
        $adnotacje->appendChild($xml->createElement('P_18A', $mppOn ? '1' : '2')); // Split payment

        $zwolnienie = $xml->createElement('Zwolnienie');
        $adnotacje->appendChild($zwolnienie);

        // Check invoice lines for zw rate
        $hasExempt = false;
        foreach ($invoice->lines as $line) {
            if ($this->mapVatRateToKSeF($line->tva_tx, $line) === 'zw') {
                $hasExempt = true;
                break;
            }
        }
        $globalPodstawa = getDolGlobalString('KSEF_ZWOLNIENIE_PODSTAWA', '');
        $zwolnienieType = getDolGlobalString('KSEF_ZWOLNIENIE_TYPE', 'disabled');
        $productField = getDolGlobalString('KSEF_ZWOLNIENIE_PRODUCT_FIELD', '');

        $podstawa = '';
        if ($hasExempt && $zwolnienieType !== 'disabled') {
            // Per-product exemption bases
            $bases = array();
            if (!empty($productField) && !empty($invoice->lines)) {
                require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
                $productCache = array();
                foreach ($invoice->lines as $line) {
                    $ksefRate = $this->mapVatRateToKSeF($line->tva_tx, $line);
                    if ($ksefRate !== 'zw' || empty($line->fk_product)) continue;

                    if (!isset($productCache[$line->fk_product])) {
                        $prod = new Product($this->db);
                        if ($prod->fetch($line->fk_product) > 0) {
                            if (method_exists($prod, 'fetch_optionals')) $prod->fetch_optionals();
                            $productCache[$line->fk_product] = $prod;
                        }
                    }
                    if (isset($productCache[$line->fk_product])) {
                        $val = $productCache[$line->fk_product]->array_options['options_' . $productField] ?? '';
                        $val = trim(strip_tags((string) $val));
                        if (!empty($val)) $bases[$val] = true;
                    }
                }
            }

            if (!empty($bases)) {
                $podstawa = implode(', ', array_keys($bases));
            } elseif (!empty($globalPodstawa)) {
                $podstawa = $globalPodstawa;
            }
        }

        if ($hasExempt && !empty($podstawa)) {
            $zwolnienie->appendChild($xml->createElement('P_19', '1'));
            $zwolnienie->appendChild($xml->createElement($zwolnienieType, $this->xmlSafe(mb_substr($podstawa, 0, 256, 'UTF-8'))));
        } else {
            $zwolnienie->appendChild($xml->createElement('P_19N', '1'));
        }

        $noweSrodki = $xml->createElement('NoweSrodkiTransportu');
        $adnotacje->appendChild($noweSrodki);
        $noweSrodki->appendChild($xml->createElement('P_22N', '1')); // Not new transport means

        $adnotacje->appendChild($xml->createElement('P_23', '2')); // Not simplified chain transaction

        $pmarzy = $xml->createElement('PMarzy');
        $adnotacje->appendChild($pmarzy);
        $pmarzy->appendChild($xml->createElement('P_PMarzyN', '1')); // Not margin scheme
    }

    /**
     * @brief Strips HTML from note text
     * @param string $html HTML text
     * @return string Plain text
     * @called_by parseDodatkowyOpisPreview()
     */
    private static function stripNoteHtml($html)
    {
        $text = str_replace("\r\n", "\n", $html);
        $text = preg_replace('/<br\s*\/?>/i', "\n", $text);
        $text = preg_replace('/<\/(p|div)>/i', "\n", $text);
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        $text = preg_replace('/[ \t]+\n/', "\n", $text);
        $text = preg_replace('/\n{2,}/', "\n", $text);
        return trim(self::sanitizeForXml($text));
    }

    /**
     * @brief Strips ASCII control characters
     * @param string $str
     * @return string
     */
    private static function sanitizeForXml($str)
    {
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string) $str);
    }

    /**
     * @brief Parses DodatkowyOpis entries
     * @param object $invoice Invoice object
     * @param object $conf Dolibarr config
     * @param object $db Database handler
     * @return array Array of ['key' => string, 'value' => string] entries
     * @called_by buildDodatkowyOpis(), actions_ksef formObjectOptions preview
     */
    public static function parseDodatkowyOpisPreview($invoice, $conf, $db)
    {
        global $langs;

        dol_include_once('/ksef/lib/ksef.lib.php');

        $entries = array();

        // Load invoice extrafields once
        if (!isset($invoice->array_options) || empty($invoice->array_options)) {
            if (method_exists($invoice, 'fetch_optionals')) {
                $invoice->fetch_optionals();
            }
        }

        // Per-invoice override
        $invoiceOverride = isset($invoice->array_options['options_ksef_dodatkowy_opis_mode'])
            ? trim((string) $invoice->array_options['options_ksef_dodatkowy_opis_mode'])
            : '';

        $globalNoteMode = isset($conf->global->KSEF_DODATKOWY_OPIS_NOTE_MODE) ? $conf->global->KSEF_DODATKOWY_OPIS_NOTE_MODE : 'simple';
        $noteTarget = getDolGlobalString('KSEF_NOTE_PUBLIC_TARGET', 'stopka_faktury');

        if (!empty($invoiceOverride)) {
            $parsed = self::parseCombinedNoteMode($invoiceOverride);
            if ($parsed) {
                $effectiveNoteMode = $parsed['mode'];
                $noteTarget = $parsed['target'];
            } else {
                $effectiveNoteMode = $globalNoteMode;
            }
        } else {
            $effectiveNoteMode = $globalNoteMode;
        }

        if ($effectiveNoteMode === 'disabled' && !empty($invoiceOverride) && $invoiceOverride === 'disabled') {
            return $entries;
        }

        // note_public as DodatkowyOpis
        if ($effectiveNoteMode !== 'disabled' && $noteTarget === 'dodatkowy_opis' && !empty($invoice->note_public)) {
            $text = self::stripNoteHtml($invoice->note_public);

            if (!empty($text)) {
                if ($effectiveNoteMode === 'simple') {
                    $totalLen = mb_strlen($text, 'UTF-8');
                    if ($totalLen <= 256) {
                        $entries[] = array('key' => 'Uwagi', 'value' => $text, 'source' => 'note');
                    } else {
                        $chunkNum = 0;
                        for ($offset = 0; $offset < $totalLen; $offset += 256) {
                            $chunk = mb_substr($text, $offset, 256, 'UTF-8');
                            $chunkNum++;
                            $label = ($chunkNum == 1) ? 'Uwagi' : 'Uwagi ' . $chunkNum;
                            $entries[] = array('key' => $label, 'value' => $chunk, 'source' => 'note');
                        }
                    }
                } elseif ($effectiveNoteMode === 'keyvalue') {
                    $lines = explode("\n", $text);
                    foreach ($lines as $line) {
                        $line = trim($line);
                        if (empty($line)) continue;
                        $parts = explode(':', $line, 2);
                        if (count($parts) < 2) continue;
                        $key = trim($parts[0]);
                        $value = trim($parts[1]);
                        if ($key === '' || $value === '') continue;
                        $entries[] = array(
                            'key' => mb_substr($key, 0, 256, 'UTF-8'),
                            'value' => mb_substr($value, 0, 256, 'UTF-8'),
                            'source' => 'note',
                        );
                    }
                }
            }
        }

        // Process extrafields
        $extrafieldsConf = isset($conf->global->KSEF_DODATKOWY_OPIS_EXTRAFIELDS) ? $conf->global->KSEF_DODATKOWY_OPIS_EXTRAFIELDS : '';
        if (!empty($extrafieldsConf)) {
            $fieldEntries = array_filter(array_map('trim', explode(',', $extrafieldsConf)));

            dol_include_once('/core/class/extrafields.class.php');
            $ef = new ExtraFields($db);
            $ef->fetch_name_optionals_label('facture');

            $skipTypes = ksefDodatkowyOpisUnsupportedTypes();

            foreach ($fieldEntries as $entry) {
                if (empty($entry)) continue;
                $parts = explode(':', $entry, 2);
                $name = $parts[0];
                $target = isset($parts[1]) ? $parts[1] : 'dodatkowy';
                if ($target !== 'dodatkowy') continue;

                if (!isset($ef->attributes['facture']['label'][$name])) continue;

                $type = isset($ef->attributes['facture']['type'][$name]) ? $ef->attributes['facture']['type'][$name] : '';
                if (in_array($type, $skipTypes)) continue;

                $rawValue = isset($invoice->array_options['options_' . $name]) ? $invoice->array_options['options_' . $name] : '';
                if ($rawValue === '' || $rawValue === null) continue;

                $displayValue = self::formatExtraFieldValue($rawValue, $type, $ef, 'facture', $name, $langs);
                if ($displayValue === '' || $displayValue === null) continue;

                $label = $langs->trans($ef->attributes['facture']['label'][$name]);
                $entries[] = array(
                    'key' => mb_substr(self::sanitizeForXml($label), 0, 256, 'UTF-8'),
                    'value' => mb_substr(self::sanitizeForXml((string) $displayValue), 0, 256, 'UTF-8'),
                    'source' => 'extrafield',
                );
            }
        }

        // Line extrafields
        $detExtrafieldsConf = getDolGlobalString('KSEF_DODATKOWY_OPIS_DET_EXTRAFIELDS', '');
        if (!empty($detExtrafieldsConf) && !empty($invoice->lines)) {
            $detFieldNames = array_filter(array_map('trim', explode(',', $detExtrafieldsConf)));
            if (!empty($detFieldNames)) {
                if (!isset($ef)) {
                    dol_include_once('/core/class/extrafields.class.php');
                    $ef = new ExtraFields($db);
                }
                $ef->fetch_name_optionals_label('facturedet');

                if (!isset($skipTypes)) $skipTypes = ksefDodatkowyOpisUnsupportedTypes();

                $lineNum = 0;
                foreach ($invoice->lines as $line) {
                    $lineNum++;
                    if (!isset($line->array_options) || empty($line->array_options)) {
                        if (method_exists($line, 'fetch_optionals')) {
                            $line->fetch_optionals();
                        }
                    }
                    foreach ($detFieldNames as $name) {
                        if (empty($name)) continue;
                        if (!isset($ef->attributes['facturedet']['label'][$name])) continue;

                        $type = isset($ef->attributes['facturedet']['type'][$name]) ? $ef->attributes['facturedet']['type'][$name] : '';
                        if (in_array($type, $skipTypes)) continue;

                        $rawValue = isset($line->array_options['options_' . $name]) ? $line->array_options['options_' . $name] : '';
                        if ($rawValue === '' || $rawValue === null) continue;

                        $displayValue = self::formatExtraFieldValue($rawValue, $type, $ef, 'facturedet', $name, $langs);
                        if ($displayValue === '' || $displayValue === null) continue;

                        $label = $langs->trans($ef->attributes['facturedet']['label'][$name]);
                        $entries[] = array(
                            'key' => mb_substr(self::sanitizeForXml($label), 0, 256, 'UTF-8'),
                            'value' => mb_substr(self::sanitizeForXml((string) $displayValue), 0, 256, 'UTF-8'),
                            'source' => 'extrafield_det',
                            'nr_wiersza' => $lineNum,
                        );
                    }
                }
            }
        }

        // Product extrafields
        $prodExtrafieldsConf = getDolGlobalString('KSEF_DODATKOWY_OPIS_PRODUCT_EXTRAFIELDS', '');
        if (!empty($prodExtrafieldsConf) && !empty($invoice->lines)) {
            $prodFieldNames = array_filter(array_map('trim', explode(',', $prodExtrafieldsConf)));
            if (!empty($prodFieldNames)) {
                if (!isset($ef)) {
                    dol_include_once('/core/class/extrafields.class.php');
                    $ef = new ExtraFields($db);
                }
                $ef->fetch_name_optionals_label('product');

                if (!isset($skipTypes)) $skipTypes = ksefDodatkowyOpisUnsupportedTypes();

                require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
                $productCache = array();
                $lineNum = 0;
                foreach ($invoice->lines as $line) {
                    $lineNum++;
                    if (empty($line->fk_product)) continue;

                    if (!isset($productCache[$line->fk_product])) {
                        $prod = new Product($db);
                        if ($prod->fetch($line->fk_product) > 0) {
                            if (method_exists($prod, 'fetch_optionals')) $prod->fetch_optionals();
                            $productCache[$line->fk_product] = $prod;
                        }
                    }
                    if (!isset($productCache[$line->fk_product])) continue;

                    $prod = $productCache[$line->fk_product];

                    foreach ($prodFieldNames as $name) {
                        if (empty($name)) continue;
                        if (!isset($ef->attributes['product']['label'][$name])) continue;

                        $type = isset($ef->attributes['product']['type'][$name]) ? $ef->attributes['product']['type'][$name] : '';
                        if (in_array($type, $skipTypes)) continue;

                        $rawValue = isset($prod->array_options['options_' . $name]) ? $prod->array_options['options_' . $name] : '';
                        if ($rawValue === '' || $rawValue === null) continue;

                        $displayValue = self::formatExtraFieldValue($rawValue, $type, $ef, 'product', $name, $langs);
                        if ($displayValue === '' || $displayValue === null) continue;

                        $label = $langs->trans($ef->attributes['product']['label'][$name]);
                        $entries[] = array(
                            'key' => mb_substr(self::sanitizeForXml($label), 0, 256, 'UTF-8'),
                            'value' => mb_substr(self::sanitizeForXml((string) $displayValue), 0, 256, 'UTF-8'),
                            'source' => 'extrafield_product',
                            'nr_wiersza' => $lineNum,
                        );
                    }
                }
            }
        }

        // Societe extrafields
        $socExtrafieldsConf = getDolGlobalString('KSEF_DODATKOWY_OPIS_SOCIETE_EXTRAFIELDS', '');
        if (!empty($socExtrafieldsConf) && !empty($invoice->socid)) {
            $socFieldEntries = array_filter(array_map('trim', explode(',', $socExtrafieldsConf)));
            if (!empty($socFieldEntries)) {
                if (!isset($ef)) {
                    dol_include_once('/core/class/extrafields.class.php');
                    $ef = new ExtraFields($db);
                }
                $ef->fetch_name_optionals_label('societe');

                if (!isset($skipTypes)) $skipTypes = ksefDodatkowyOpisUnsupportedTypes();

                require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
                $soc = new Societe($db);
                if ($soc->fetch($invoice->socid) > 0) {
                    if (method_exists($soc, 'fetch_optionals')) $soc->fetch_optionals();

                    foreach ($socFieldEntries as $entry) {
                        if (empty($entry)) continue;
                        $parts = explode(':', $entry, 2);
                        $name = $parts[0];
                        $target = isset($parts[1]) ? $parts[1] : 'dodatkowy';
                        if ($target !== 'dodatkowy') continue;

                        if (!isset($ef->attributes['societe']['label'][$name])) continue;
                        $type = isset($ef->attributes['societe']['type'][$name]) ? $ef->attributes['societe']['type'][$name] : '';
                        if (in_array($type, $skipTypes)) continue;

                        $rawValue = isset($soc->array_options['options_' . $name]) ? $soc->array_options['options_' . $name] : '';
                        if ($rawValue === '' || $rawValue === null) continue;

                        $displayValue = self::formatExtraFieldValue($rawValue, $type, $ef, 'societe', $name, $langs);
                        if ($displayValue === '' || $displayValue === null) continue;

                        $label = $langs->trans($ef->attributes['societe']['label'][$name]);
                        $entries[] = array(
                            'key' => mb_substr(self::sanitizeForXml($label), 0, 256, 'UTF-8'),
                            'value' => mb_substr(self::sanitizeForXml((string) $displayValue), 0, 256, 'UTF-8'),
                            'source' => 'extrafield_societe',
                        );
                    }
                }
            }
        }

        // Project extrafields
        $projExtrafieldsConf = getDolGlobalString('KSEF_DODATKOWY_OPIS_PROJECT_EXTRAFIELDS', '');
        if (!empty($projExtrafieldsConf) && !empty($invoice->fk_project)) {
            $projFieldEntries = array_filter(array_map('trim', explode(',', $projExtrafieldsConf)));
            if (!empty($projFieldEntries)) {
                if (!isset($ef)) {
                    dol_include_once('/core/class/extrafields.class.php');
                    $ef = new ExtraFields($db);
                }
                $ef->fetch_name_optionals_label('projet');

                if (!isset($skipTypes)) $skipTypes = ksefDodatkowyOpisUnsupportedTypes();

                require_once DOL_DOCUMENT_ROOT . '/projet/class/project.class.php';
                $proj = new Project($db);
                if ($proj->fetch($invoice->fk_project) > 0) {
                    if (method_exists($proj, 'fetch_optionals')) $proj->fetch_optionals();

                    foreach ($projFieldEntries as $entry) {
                        if (empty($entry)) continue;
                        $parts = explode(':', $entry, 2);
                        $name = $parts[0];
                        $target = isset($parts[1]) ? $parts[1] : 'dodatkowy';
                        if ($target !== 'dodatkowy') continue;

                        if (!isset($ef->attributes['projet']['label'][$name])) continue;
                        $type = isset($ef->attributes['projet']['type'][$name]) ? $ef->attributes['projet']['type'][$name] : '';
                        if (in_array($type, $skipTypes)) continue;

                        $rawValue = isset($proj->array_options['options_' . $name]) ? $proj->array_options['options_' . $name] : '';
                        if ($rawValue === '' || $rawValue === null) continue;

                        $displayValue = self::formatExtraFieldValue($rawValue, $type, $ef, 'projet', $name, $langs);
                        if ($displayValue === '' || $displayValue === null) continue;

                        $label = $langs->trans($ef->attributes['projet']['label'][$name]);
                        $entries[] = array(
                            'key' => mb_substr(self::sanitizeForXml($label), 0, 256, 'UTF-8'),
                            'value' => mb_substr(self::sanitizeForXml((string) $displayValue), 0, 256, 'UTF-8'),
                            'source' => 'extrafield_project',
                        );
                    }
                }
            }
        }

        return $entries;
    }

    /**
     * Collect extrafield valuess.
     * Returns a single text block of "Label: Value" lines.
     *
     * @param object $invoice Invoice object
     * @param object $conf Global conf
     * @param object $db Database handler
     * @return string Combined text for StopkaFaktury
     */
    public static function collectStopkaExtrafields($invoice, $conf, $db, $customer = null)
    {
        global $langs;

        dol_include_once('/ksef/lib/ksef.lib.php');
        dol_include_once('/core/class/extrafields.class.php');

        $lines = array();
        $skipTypes = ksefDodatkowyOpisUnsupportedTypes();
        $ef = new ExtraFields($db);

        if (!isset($invoice->array_options) || empty($invoice->array_options)) {
            if (method_exists($invoice, 'fetch_optionals')) $invoice->fetch_optionals();
        }

        // Invoice extrafields for stopka
        $extrafieldsConf = getDolGlobalString('KSEF_DODATKOWY_OPIS_EXTRAFIELDS', '');
        if (!empty($extrafieldsConf)) {
            $ef->fetch_name_optionals_label('facture');
            $fieldEntries = array_filter(array_map('trim', explode(',', $extrafieldsConf)));
            foreach ($fieldEntries as $entry) {
                $parts = explode(':', $entry, 2);
                $name = $parts[0];
                $target = isset($parts[1]) ? $parts[1] : 'dodatkowy';
                if ($target !== 'stopka') continue;
                if (empty($name) || !isset($ef->attributes['facture']['label'][$name])) continue;
                $type = $ef->attributes['facture']['type'][$name] ?? '';
                if (in_array($type, $skipTypes)) continue;

                $rawValue = isset($invoice->array_options['options_' . $name]) ? $invoice->array_options['options_' . $name] : '';
                if ($rawValue === '' || $rawValue === null) continue;
                $displayValue = self::formatExtraFieldValue($rawValue, $type, $ef, 'facture', $name, $langs);
                if ($displayValue === '' || $displayValue === null) continue;

                $label = $langs->trans($ef->attributes['facture']['label'][$name]);
                $lines[] = self::sanitizeForXml($label) . ': ' . self::sanitizeForXml((string) $displayValue);
            }
        }

        // Societe extrafields for stopka
        $socConf = getDolGlobalString('KSEF_DODATKOWY_OPIS_SOCIETE_EXTRAFIELDS', '');
        if (!empty($socConf) && !empty($invoice->socid)) {
            $ef->fetch_name_optionals_label('societe');
            $socEntries = array_filter(array_map('trim', explode(',', $socConf)));
            $hasStopka = false;
            foreach ($socEntries as $entry) {
                $parts = explode(':', $entry, 2);
                if (isset($parts[1]) && $parts[1] === 'stopka') { $hasStopka = true; break; }
            }
            if ($hasStopka) {
                $soc = null;
                if ($customer && $customer->id == $invoice->socid) {
                    $soc = $customer;
                } else {
                    require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
                    $soc = new Societe($db);
                    if ($soc->fetch($invoice->socid) <= 0) $soc = null;
                }
                if ($soc) {
                    if (method_exists($soc, 'fetch_optionals')) $soc->fetch_optionals();
                    foreach ($socEntries as $entry) {
                        $parts = explode(':', $entry, 2);
                        $name = $parts[0];
                        $target = isset($parts[1]) ? $parts[1] : 'dodatkowy';
                        if ($target !== 'stopka') continue;
                        if (empty($name) || !isset($ef->attributes['societe']['label'][$name])) continue;
                        $type = $ef->attributes['societe']['type'][$name] ?? '';
                        if (in_array($type, $skipTypes)) continue;

                        $rawValue = isset($soc->array_options['options_' . $name]) ? $soc->array_options['options_' . $name] : '';
                        if ($rawValue === '' || $rawValue === null) continue;
                        $displayValue = self::formatExtraFieldValue($rawValue, $type, $ef, 'societe', $name, $langs);
                        if ($displayValue === '' || $displayValue === null) continue;

                        $label = $langs->trans($ef->attributes['societe']['label'][$name]);
                        $lines[] = self::sanitizeForXml($label) . ': ' . self::sanitizeForXml((string) $displayValue);
                    }
                }
            }
        }

        // Project extrafields for stopka
        $projConf = getDolGlobalString('KSEF_DODATKOWY_OPIS_PROJECT_EXTRAFIELDS', '');
        if (!empty($projConf) && !empty($invoice->fk_project)) {
            $ef->fetch_name_optionals_label('projet');
            $projEntries = array_filter(array_map('trim', explode(',', $projConf)));
            $hasStopka = false;
            foreach ($projEntries as $entry) {
                $parts = explode(':', $entry, 2);
                if (isset($parts[1]) && $parts[1] === 'stopka') { $hasStopka = true; break; }
            }
            if ($hasStopka) {
                require_once DOL_DOCUMENT_ROOT . '/projet/class/project.class.php';
                $proj = new Project($db);
                if ($proj->fetch($invoice->fk_project) > 0) {
                    if (method_exists($proj, 'fetch_optionals')) $proj->fetch_optionals();
                    foreach ($projEntries as $entry) {
                        $parts = explode(':', $entry, 2);
                        $name = $parts[0];
                        $target = isset($parts[1]) ? $parts[1] : 'dodatkowy';
                        if ($target !== 'stopka') continue;
                        if (empty($name) || !isset($ef->attributes['projet']['label'][$name])) continue;
                        $type = $ef->attributes['projet']['type'][$name] ?? '';
                        if (in_array($type, $skipTypes)) continue;

                        $rawValue = isset($proj->array_options['options_' . $name]) ? $proj->array_options['options_' . $name] : '';
                        if ($rawValue === '' || $rawValue === null) continue;
                        $displayValue = self::formatExtraFieldValue($rawValue, $type, $ef, 'projet', $name, $langs);
                        if ($displayValue === '' || $displayValue === null) continue;

                        $label = $langs->trans($ef->attributes['projet']['label'][$name]);
                        $lines[] = self::sanitizeForXml($label) . ': ' . self::sanitizeForXml((string) $displayValue);
                    }
                }
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @brief Formats an extrafield value for display in DodatkowyOpis
     * @param mixed $rawValue Raw extrafield value
     * @param string $type Extrafield type
     * @param ExtraFields $ef ExtraFields instance
     * @param string $elementType Element type ('facture' or 'facturedet')
     * @param string $name Field name
     * @param Translate $langs Language object
     * @return string Formatted display value
     */
    private static function formatExtraFieldValue($rawValue, $type, $ef, $elementType, $name, $langs)
    {
        if ($type === 'date') {
            return dol_print_date($rawValue, 'day');
        } elseif ($type === 'datetime') {
            return dol_print_date($rawValue, 'dayhour');
        } elseif ($type === 'select') {
            $param = isset($ef->attributes[$elementType]['param'][$name]) ? $ef->attributes[$elementType]['param'][$name] : array();
            if (isset($param['options'][$rawValue])) {
                $optLabel = $param['options'][$rawValue];
                if (($pos = strpos($optLabel, '|')) !== false) {
                    $optLabel = substr($optLabel, 0, $pos);
                }
                return $langs->trans($optLabel);
            }
            return (string) $rawValue;
        } elseif ($type === 'double' || $type === 'price') {
            return (string) $rawValue;
        } else {
            return strip_tags((string) $rawValue);
        }
    }

    /**
     * @brief Returns the per-invoice DodatkowyOpis override value
     * @param object $invoice Invoice object
     * @return string
     */
    public static function getDodatkowyOpisOverride($invoice)
    {
        if (!isset($invoice->array_options) || empty($invoice->array_options)) {
            if (method_exists($invoice, 'fetch_optionals')) {
                $invoice->fetch_optionals();
            }
        }
        return isset($invoice->array_options['options_ksef_dodatkowy_opis_mode'])
            ? trim((string) $invoice->array_options['options_ksef_dodatkowy_opis_mode'])
            : '';
    }

    /**
     * @brief Parses a combined note mode value into mode and target components
     * @param string $combined Combined value (e.g. 'simple_stopka', 'keyvalue_dodatkowy', 'disabled')
     * @return array|null Array with 'mode' and 'target' keys, or null if not recognized
     */
    public static function parseCombinedNoteMode($combined)
    {
        $map = array(
            'simple_stopka'      => array('mode' => 'simple',   'target' => 'stopka_faktury'),
            'simple_dodatkowy'   => array('mode' => 'simple',   'target' => 'dodatkowy_opis'),
            'keyvalue_dodatkowy' => array('mode' => 'keyvalue', 'target' => 'dodatkowy_opis'),
            'disabled'           => array('mode' => 'disabled', 'target' => ''),
        );
        return isset($map[$combined]) ? $map[$combined] : null;
    }

    /**
     * @brief Builds DodatkowyOpis XML elements from invoice data
     * @param $xml DOMDocument
     * @param $parent Parent element (Fa)
     * @param $invoice Invoice object
     * @called_by buildFa()
     * @calls parseDodatkowyOpisPreview(), xmlSafe()
     */
    private function buildDodatkowyOpis($xml, $parent, $invoice)
    {
        global $conf;

        $entries = self::parseDodatkowyOpisPreview($invoice, $conf, $this->db);

        foreach ($entries as $entry) {
            $dodatkowy = $xml->createElement('DodatkowyOpis');
            // NrWiersza before Klucz (schema order)
            if (!empty($entry['nr_wiersza'])) {
                $dodatkowy->appendChild($xml->createElement('NrWiersza', (int) $entry['nr_wiersza']));
            }
            $dodatkowy->appendChild($xml->createElement('Klucz', $this->xmlSafe($entry['key'])));
            $dodatkowy->appendChild($xml->createElement('Wartosc', $this->xmlSafe($entry['value'])));
            $parent->appendChild($dodatkowy);
        }
    }

    /**
     * @brief Builds invoice line items section
     * @param $xml DOMDocument
     * @param $parent Parent element
     * @param $invoice Invoice object
     * @param $originalInvoice Original for KOR
     * @called_by buildFa()
     */
    private function buildFaWiersz($xml, $parent, $invoice, $originalInvoice = null)
    {
        $isKor = ($invoice->type == Facture::TYPE_CREDIT_NOTE
               || $invoice->type == Facture::TYPE_REPLACEMENT);

        if (!$isKor) {
            $this->buildFaWierszStandard($xml, $parent, $invoice);
            return;
        }

        $method = getDolGlobalString('KSEF_KOR_LINE_METHOD', 'stanprzed');

        if ($invoice->type == Facture::TYPE_CREDIT_NOTE) {
            $this->buildFaWierszDifferentialCreditNote($xml, $parent, $invoice);
            return;
        }

        // TYPE_REPLACEMENT
        if ($method === 'stanprzed') {
            $this->buildFaWierszStanPrzed($xml, $parent, $invoice, $originalInvoice);
        } else {
            $this->buildFaWierszDifferentialReplacement($xml, $parent, $invoice, $originalInvoice);
        }
    }

    /**
     * @brief Standard line emission
     * @called_by buildFaWiersz()
     */
    private function buildFaWierszStandard($xml, $parent, $invoice)
    {
        $lineNum = 1;
        foreach ($invoice->lines as $line) {
            $this->buildSingleFaWiersz($xml, $parent, $line, $lineNum);
            $lineNum++;
        }
    }

    /**
     * @brief Credit note lines - delta with sign fix
     * @called_by buildFaWiersz()
     */
    private function buildFaWierszDifferentialCreditNote($xml, $parent, $invoice)
    {
        $lineNum = 1;
        foreach ($invoice->lines as $line) {
            $this->buildSingleFaWiersz($xml, $parent, $line, $lineNum, false, true);
            $lineNum++;
        }
    }

    /**
     * @brief Match replacement lines to originals via fk_prev_id in matched pairs/unmatched originals/new lines
     * @called_by buildFaWierszStanPrzed(), buildFaWierszDifferentialReplacement()
     */
    private function matchCorrectionLines($origLines, $corrLines)
    {
        // Build lookup
        $origById = array();
        foreach ($origLines as $origLine) {
            if (!empty($origLine->id)) {
                $origById[$origLine->id] = $origLine;
            }
        }

        $pairs = array();
        $matchedOrigIds = array();
        $matchedCorrIndices = array();

        // Match correction lines via fk_prev_id
        foreach ($corrLines as $ci => $corrLine) {
            $prevId = $corrLine->fk_prev_id ?? 0;
            if ($prevId > 0 && isset($origById[$prevId])) {
                $pairs[] = array('orig' => $origById[$prevId], 'corr' => $corrLine);
                $matchedOrigIds[$prevId] = true;
                $matchedCorrIndices[$ci] = true;
            }
        }

        if (empty($pairs)) {
            $maxLines = max(count($origLines), count($corrLines));
            for ($i = 0; $i < $maxLines; $i++) {
                $pairs[] = array(
                    'orig' => isset($origLines[$i]) ? $origLines[$i] : null,
                    'corr' => isset($corrLines[$i]) ? $corrLines[$i] : null,
                );
            }
            return $pairs;
        }

        // Unmatched originals (-)
        foreach ($origLines as $origLine) {
            if (!empty($origLine->id) && !isset($matchedOrigIds[$origLine->id])) {
                $pairs[] = array('orig' => $origLine, 'corr' => null);
            }
        }

        // Unmatched corrections (+)
        foreach ($corrLines as $ci => $corrLine) {
            if (!isset($matchedCorrIndices[$ci])) {
                $pairs[] = array('orig' => null, 'corr' => $corrLine);
            }
        }

        return $pairs;
    }

    /**
     * @brief StanPrzed method
     * @called_by buildFaWiersz()
     */
    private function buildFaWierszStanPrzed($xml, $parent, $invoice, $originalInvoice)
    {
        $corrLines = $invoice->lines;
        $origLines = ($originalInvoice && !empty($originalInvoice->lines)) ? $originalInvoice->lines : array();
        $lineNum = 1;

        // Original exchange rate
        $origKursWaluty = null;
        if ($originalInvoice && !empty($originalInvoice->multicurrency_tx) && $originalInvoice->multicurrency_tx > 0) {
            $origKursWaluty = 1 / $originalInvoice->multicurrency_tx;
        }

        $pairs = $this->matchCorrectionLines($origLines, $corrLines);

        foreach ($pairs as $pair) {
            $origLine = $pair['orig'];
            $corrLine = $pair['corr'];

            if ($origLine && $corrLine) {
                if ($this->linesAreDifferent($origLine, $corrLine)) {
                    // Changed: before (StanPrzed=1) + after, same NrWierszaFa
                    $this->buildSingleFaWiersz($xml, $parent, $origLine, $lineNum, true, false, $origKursWaluty);
                    $this->buildSingleFaWiersz($xml, $parent, $corrLine, $lineNum, false);
                    $lineNum++;
                }
                // Unchanged
            } elseif ($origLine) {
                // Removed line
                $this->buildSingleFaWiersz($xml, $parent, $origLine, $lineNum, true, false, $origKursWaluty);
                $lineNum++;
            } else {
                // Added line
                $this->buildSingleFaWiersz($xml, $parent, $corrLine, $lineNum, false);
                $lineNum++;
            }
        }
    }

    /**
     * @brief Replacement differential
     * @called_by buildFaWiersz()
     */
    private function buildFaWierszDifferentialReplacement($xml, $parent, $invoice, $originalInvoice)
    {
        $corrLines = $invoice->lines;
        $origLines = ($originalInvoice && !empty($originalInvoice->lines)) ? $originalInvoice->lines : array();
        $useMulti = (!empty($this->currentInvoiceCurrency) && $this->currentInvoiceCurrency != 'PLN');
        $origKursWaluty = ($useMulti && $originalInvoice && !empty($originalInvoice->multicurrency_tx) && $originalInvoice->multicurrency_tx > 0)
            ? 1 / $originalInvoice->multicurrency_tx : null;
        $lineNum = 1;

        $pairs = $this->matchCorrectionLines($origLines, $corrLines);

        foreach ($pairs as $pair) {
            $origLine = $pair['orig'];
            $corrLine = $pair['corr'];

            if ($origLine && $corrLine) {
                $origRate = $this->mapVatRateToKSeF($origLine->tva_tx, $origLine);
                $corrRate = $this->mapVatRateToKSeF($corrLine->tva_tx, $corrLine);
                $origTotal = $useMulti ? $origLine->multicurrency_total_ht : $origLine->total_ht;
                $corrTotal = $useMulti ? $corrLine->multicurrency_total_ht : $corrLine->total_ht;
                $deltaQty = $corrLine->qty - $origLine->qty;
                $deltaTotal = $corrTotal - $origTotal;

                if ($origRate !== $corrRate) {
                    $this->buildDifferentialRow($xml, $parent, $origLine, $lineNum, -$origLine->qty, -$origTotal, $origRate, $useMulti, null, $origKursWaluty);
                    $this->buildDifferentialRow($xml, $parent, $corrLine, $lineNum, $corrLine->qty, $corrTotal, $corrRate, $useMulti);
                    $lineNum++;
                } elseif (abs($deltaTotal) >= 0.005 || abs($deltaQty) >= 0.005) {
                    $emitQty = (abs($deltaQty) < 0.005) ? $corrLine->qty : $deltaQty;
                    $corrPrice = $useMulti ? $corrLine->multicurrency_subprice : $corrLine->subprice;
                    $this->buildDifferentialRow($xml, $parent, $corrLine, $lineNum, $emitQty, $deltaTotal, $corrRate, $useMulti, $corrPrice);
                    $lineNum++;
                } elseif ($this->linesAreDifferent($origLine, $corrLine)) {
                    $corrPrice = $useMulti ? $corrLine->multicurrency_subprice : $corrLine->subprice;
                    $this->buildDifferentialRow($xml, $parent, $corrLine, $lineNum, 0, 0, $corrRate, $useMulti, $corrPrice);
                    $lineNum++;
                }
            } elseif ($origLine) {
                $origRate = $this->mapVatRateToKSeF($origLine->tva_tx, $origLine);
                $origTotal = $useMulti ? $origLine->multicurrency_total_ht : $origLine->total_ht;
                $this->buildDifferentialRow($xml, $parent, $origLine, $lineNum, -$origLine->qty, -$origTotal, $origRate, $useMulti, null, $origKursWaluty);
                $lineNum++;
            } else {
                $corrRate = $this->mapVatRateToKSeF($corrLine->tva_tx, $corrLine);
                $corrTotal = $useMulti ? $corrLine->multicurrency_total_ht : $corrLine->total_ht;
                $this->buildDifferentialRow($xml, $parent, $corrLine, $lineNum, $corrLine->qty, $corrTotal, $corrRate, $useMulti);
                $lineNum++;
            }
        }
    }

    /**
     * @brief Emit differential FaWiersz row
     * @param $xml DOMDocument
     * @param $parent Parent element
     * @param $line Invoice line (P_7/Indeks/GTIN/P_8A source)
     * @param $lineNum NrWierszaFa number
     * @param $qty Quantity (delta or negated)
     * @param $total Net total (delta or negated)
     * @param $vatRate KSeF VAT rate string
     * @param $useMulti Use multicurrency fields
     * @param $unitPrice Override (null = derive from line)
     * @param $kursWalutyOverride Exchange rate override
     * @called_by buildFaWierszDifferentialReplacement()
     */
    private function buildDifferentialRow($xml, $parent, $line, $lineNum, $qty, $total, $vatRate, $useMulti, $unitPrice = null, $kursWalutyOverride = null)
    {
        global $conf;

        $faWiersz = $xml->createElement('FaWiersz');
        $parent->appendChild($faWiersz);

        $faWiersz->appendChild($xml->createElement('NrWierszaFa', $lineNum));

        // UU_ID
        if (getDolGlobalInt('KSEF_FA3_INCLUDE_UU_ID') && !empty($line->array_options['options_ksef_uu_id'])) {
            $faWiersz->appendChild($xml->createElement('UU_ID', $this->xmlSafe($line->array_options['options_ksef_uu_id'])));
        }

        // P_7: Product Name
        $descText = !empty($line->desc) ? self::stripNoteHtml($line->desc) : '';
        $label = !empty($line->product_label) ? trim($line->product_label) : '';
        if (!empty($label) && !empty($descText)) {
            if (stripos($descText, $label) !== 0) {
                $description = $label . "\n" . $descText;
            } else {
                $description = $descText;
            }
        } elseif (!empty($descText)) {
            $description = $descText;
        } elseif (!empty($label)) {
            $description = $label;
        } else {
            $description = '';
        }
        $faWiersz->appendChild($xml->createElement('P_7', $this->xmlSafe(mb_substr($description, 0, 512, 'UTF-8'))));

        // Indeks
        if (!empty($conf->global->KSEF_FA3_INCLUDE_INDEKS) && !empty($line->product_ref)) {
            $faWiersz->appendChild($xml->createElement('Indeks', $this->xmlSafe(substr($line->product_ref, 0, 50))));
        }

        // GTIN
        if (!empty($conf->global->KSEF_FA3_INCLUDE_GTIN) && !empty($line->product_barcode)) {
            $faWiersz->appendChild($xml->createElement('GTIN', $this->xmlSafe($line->product_barcode)));
        }

        // P_8A: Unit of measure
        if (!empty($conf->global->KSEF_FA3_INCLUDE_UNIT) && !empty($line->product_unit)) {
            $faWiersz->appendChild($xml->createElement('P_8A', $this->xmlSafe($line->product_unit)));
        }

        // P_8B: Quantity
        $faWiersz->appendChild($xml->createElement('P_8B', number_format($qty, 2, '.', '')));

        // P_9A: Unit Net Price
        if ($unitPrice === null) {
            $unitPrice = $useMulti ? $line->multicurrency_subprice : $line->subprice;
        }
        $faWiersz->appendChild($xml->createElement('P_9A', number_format(abs($unitPrice), 2, '.', '')));

        // P_10: ... TBD?

        // P_11: Net Line Total
        $faWiersz->appendChild($xml->createElement('P_11', number_format($total, 2, '.', '')));

        // P_12: VAT Rate
        $faWiersz->appendChild($xml->createElement('P_12', $vatRate));

        // GTU
        $gtu = $this->getLineGTU($line);
        if (!empty($gtu)) {
            $faWiersz->appendChild($xml->createElement('GTU', $gtu));
        }

        // Procedura
        $procedura = $this->getLineProcedura($line);
        if (!empty($procedura)) {
            $faWiersz->appendChild($xml->createElement('Procedura', $procedura));
        }

        // KursWaluty
        $rowKurs = ($kursWalutyOverride !== null) ? $kursWalutyOverride : $this->currentKursWaluty;
        if (!empty($this->currentInvoiceCurrency) && $this->currentInvoiceCurrency != 'PLN' && !empty($rowKurs)) {
            $faWiersz->appendChild($xml->createElement('KursWaluty', number_format($rowKurs, 6, '.', '')));
        }
    }

    /**
     * @brief Emit single FaWiersz element
     * @param $xml DOMDocument
     * @param $parent Parent element
     * @param $line Invoice line
     * @param $lineNum NrWierszaFa number
     * @param $stanPrzed Before-correction marker
     * @param $isCreditNote Flips P_8B/P_9A signs
     * @param $kursWalutyOverride Exchange rate override for StanPrzed
     * @return void
     * @called_by buildFaWiersz()
     * @calls xmlSafe(), mapVatRateToKSeF()
     */
    private function buildSingleFaWiersz($xml, $parent, $line, $lineNum, $stanPrzed = false, $isCreditNote = false, $kursWalutyOverride = null)
    {
        global $conf;

        $faWiersz = $xml->createElement('FaWiersz');
        $parent->appendChild($faWiersz);

        $faWiersz->appendChild($xml->createElement('NrWierszaFa', $lineNum));

        // UU_ID (after NrWierszaFa, before P_6A)
        if (getDolGlobalInt('KSEF_FA3_INCLUDE_UU_ID') && !empty($line->array_options['options_ksef_uu_id'])) {
            $faWiersz->appendChild($xml->createElement('UU_ID', $this->xmlSafe($line->array_options['options_ksef_uu_id'])));
        }

        // P_7: Product Name
        $descText = !empty($line->desc) ? self::stripNoteHtml($line->desc) : '';
        $label = !empty($line->product_label) ? trim($line->product_label) : '';
        if (!empty($label) && !empty($descText)) {
            if (stripos($descText, $label) !== 0) {
                $description = $label . "\n" . $descText;
            } else {
                $description = $descText;
            }
        } elseif (!empty($descText)) {
            $description = $descText;
        } elseif (!empty($label)) {
            $description = $label;
        } else {
            $description = '';
        }
        $faWiersz->appendChild($xml->createElement('P_7', $this->xmlSafe(mb_substr($description, 0, 512, 'UTF-8'))));

        // Indeks: Product reference
        if (!empty($conf->global->KSEF_FA3_INCLUDE_INDEKS) && !empty($line->product_ref)) {
            $faWiersz->appendChild($xml->createElement('Indeks', $this->xmlSafe(substr($line->product_ref, 0, 50))));
        }

        // GTIN: Product barcode
        if (!empty($conf->global->KSEF_FA3_INCLUDE_GTIN) && !empty($line->product_barcode)) {
            $faWiersz->appendChild($xml->createElement('GTIN', $this->xmlSafe($line->product_barcode)));
        }

        // P_8A: Unit of measure
        if (!empty($conf->global->KSEF_FA3_INCLUDE_UNIT) && !empty($line->product_unit)) {
            $faWiersz->appendChild($xml->createElement('P_8A', $this->xmlSafe($line->product_unit)));
        }

        // P_8B: Quantity
        // Credit notes: qty=+abs, subprice=-abs. Method A: P_8B=-qty, P_9A=+price.
        $qty = $isCreditNote ? -abs($line->qty) : $line->qty;
        $faWiersz->appendChild($xml->createElement('P_8B', number_format($qty, 2, '.', '')));

        // P_9A: Unit Net Price
        $unitPrice = (!empty($this->currentInvoiceCurrency) && $this->currentInvoiceCurrency != 'PLN')
            ? $line->multicurrency_subprice
            : $line->subprice;
        if ($isCreditNote) {
            $unitPrice = abs($unitPrice);
        }
        $faWiersz->appendChild($xml->createElement('P_9A', number_format($unitPrice, 2, '.', '')));

        // P_10: Discount
        if (!$isCreditNote && !empty($line->remise_percent) && $line->remise_percent > 0) {
            $discountAmount = $unitPrice * $line->qty - ((!empty($this->currentInvoiceCurrency) && $this->currentInvoiceCurrency != 'PLN')
                ? $line->multicurrency_total_ht
                : $line->total_ht);
            if ($discountAmount > 0) {
                $faWiersz->appendChild($xml->createElement('P_10', number_format($discountAmount, 2, '.', '')));
            }
        }

        // P_11: Net Line Total
        $lineTotal = (!empty($this->currentInvoiceCurrency) && $this->currentInvoiceCurrency != 'PLN')
            ? $line->multicurrency_total_ht
            : $line->total_ht;
        $faWiersz->appendChild($xml->createElement('P_11', number_format($lineTotal, 2, '.', '')));

        // P_12: VAT Rate
        $vatRate = $this->mapVatRateToKSeF($line->tva_tx, $line);
        $faWiersz->appendChild($xml->createElement('P_12', $vatRate));

        // GTU
        $gtu = $this->getLineGTU($line);
        if (!empty($gtu)) {
            $faWiersz->appendChild($xml->createElement('GTU', $gtu));
        }

        // Procedura
        $procedura = $this->getLineProcedura($line);
        if (!empty($procedura)) {
            $faWiersz->appendChild($xml->createElement('Procedura', $procedura));
        }

        // KursWaluty
        $kursWaluty = ($kursWalutyOverride !== null) ? $kursWalutyOverride : $this->currentKursWaluty;
        if (!empty($this->currentInvoiceCurrency) && $this->currentInvoiceCurrency != 'PLN' && !empty($kursWaluty)) {
            $faWiersz->appendChild($xml->createElement('KursWaluty', number_format($kursWaluty, 6, '.', '')));
        }

        // StanPrzed
        if ($stanPrzed) {
            $faWiersz->appendChild($xml->createElement('StanPrzed', '1'));
        }

    }

    /**
     * @brief Compare lines for KOR differences
     * @param $origLine Original line
     * @param $corrLine Correction line
     * @return bool
     * @called_by buildFaWiersz()
     */
    private function linesAreDifferent($origLine, $corrLine)
    {
        global $conf;

        $useMulti = (!empty($this->currentInvoiceCurrency) && $this->currentInvoiceCurrency != 'PLN');

        // P_7
        $origDesc = !empty($origLine->desc) ? self::stripNoteHtml($origLine->desc) : '';
        $corrDesc = !empty($corrLine->desc) ? self::stripNoteHtml($corrLine->desc) : '';
        $origLabel = !empty($origLine->product_label) ? trim($origLine->product_label) : '';
        $corrLabel = !empty($corrLine->product_label) ? trim($corrLine->product_label) : '';
        if ($origDesc !== $corrDesc || $origLabel !== $corrLabel) return true;

        // Indeks
        if (!empty($conf->global->KSEF_FA3_INCLUDE_INDEKS)) {
            $origRef = !empty($origLine->product_ref) ? substr($origLine->product_ref, 0, 50) : '';
            $corrRef = !empty($corrLine->product_ref) ? substr($corrLine->product_ref, 0, 50) : '';
            if ($origRef !== $corrRef) return true;
        }

        // GTIN
        if (!empty($conf->global->KSEF_FA3_INCLUDE_GTIN)) {
            $origGtin = !empty($origLine->product_barcode) ? $origLine->product_barcode : '';
            $corrGtin = !empty($corrLine->product_barcode) ? $corrLine->product_barcode : '';
            if ($origGtin !== $corrGtin) return true;
        }

        // P_8A
        if (!empty($conf->global->KSEF_FA3_INCLUDE_UNIT)) {
            $origUnit = !empty($origLine->product_unit) ? $origLine->product_unit : '';
            $corrUnit = !empty($corrLine->product_unit) ? $corrLine->product_unit : '';
            if ($origUnit !== $corrUnit) return true;
        }

        // P_8B
        if (number_format($origLine->qty, 2, '.', '') !== number_format($corrLine->qty, 2, '.', '')) return true;

        // P_9A
        $origPrice = $useMulti ? $origLine->multicurrency_subprice : $origLine->subprice;
        $corrPrice = $useMulti ? $corrLine->multicurrency_subprice : $corrLine->subprice;
        if (number_format($origPrice, 2, '.', '') !== number_format($corrPrice, 2, '.', '')) return true;

        // P_10
        $origDiscount = (!empty($origLine->remise_percent) && $origLine->remise_percent > 0) ? $origLine->remise_percent : 0;
        $corrDiscount = (!empty($corrLine->remise_percent) && $corrLine->remise_percent > 0) ? $corrLine->remise_percent : 0;
        if (number_format($origDiscount, 2, '.', '') !== number_format($corrDiscount, 2, '.', '')) return true;

        // P_11
        $origTotal = $useMulti ? $origLine->multicurrency_total_ht : $origLine->total_ht;
        $corrTotal = $useMulti ? $corrLine->multicurrency_total_ht : $corrLine->total_ht;
        if (number_format($origTotal, 2, '.', '') !== number_format($corrTotal, 2, '.', '')) return true;

        // P_12
        if ($this->mapVatRateToKSeF($origLine->tva_tx, $origLine) !== $this->mapVatRateToKSeF($corrLine->tva_tx, $corrLine)) return true;

        return false;
    }

    /**
     * @brief Builds payment information section
     * @param $xml DOMDocument
     * @param $parent Parent element
     * @param $invoice Invoice object
     * @called_by buildFa()
     * @calls getPaymentMethodCode(), getPaymentMethodCodeByType(), cleanIBAN(), xmlSafe()
     */
    private function buildPlatnosc($xml, $parent, $invoice)
    {
        global $conf;

        $platnosc = $xml->createElement('Platnosc');
        $parent->appendChild($platnosc);

        // Payment status
        if ($invoice->statut >= 1) {
            $payments = $invoice->getListOfPayments('', 0, 1);

            if (!empty($payments)) {
                $isFullyPaid = !empty($invoice->paye);
                $paymentCount = count($payments);

                if ($isFullyPaid && $paymentCount == 1) {
                    // Full payment
                    $platnosc->appendChild($xml->createElement('Zaplacono', '1'));
                    $payDate = dol_print_date($this->db->jdate($payments[0]['date']), '%Y-%m-%d');
                    $platnosc->appendChild($xml->createElement('DataZaplaty', $payDate));
                } else {
                    // Partial/multiple: 1=partial, 2=fully paid in installments
                    $znacznik = $isFullyPaid ? '2' : '1';
                    $platnosc->appendChild($xml->createElement('ZnacznikZaplatyCzesciowej', $znacznik));

                    foreach ($payments as $payment) {
                        $zaplataCzesciowa = $xml->createElement('ZaplataCzesciowa');
                        $platnosc->appendChild($zaplataCzesciowa);

                        $zaplataCzesciowa->appendChild($xml->createElement(
                            'KwotaZaplatyCzesciowej',
                            number_format((float)$payment['amount'], 2, '.', '')
                        ));
                        $zaplataCzesciowa->appendChild($xml->createElement(
                            'DataZaplatyCzesciowej',
                            dol_print_date($this->db->jdate($payment['date']), '%Y-%m-%d')
                        ));

                        if (!empty($payment['type'])) {
                            $partialPayCode = $this->getPaymentMethodCodeByType($payment['type']);
                            if ($partialPayCode !== null) {
                                $zaplataCzesciowa->appendChild($xml->createElement('FormaPlatnosci', $partialPayCode));
                            }
                        }
                    }
                }
            }
        }

        // Payment terms
        $terminPlatnosci = $xml->createElement('TerminPlatnosci');
        $platnosc->appendChild($terminPlatnosci);

        $dueDate = $invoice->date_lim_reglement ?: ($invoice->date + (30 * 86400));
        $terminPlatnosci->appendChild($xml->createElement('Termin', dol_print_date($dueDate, '%Y-%m-%d')));

        // Payment method
        $paymentCode = $this->getPaymentMethodCode($invoice->mode_reglement_id);
        $platnosc->appendChild($xml->createElement('FormaPlatnosci', $paymentCode));

        // Bank account for transfers
        if ($paymentCode == '6' && !empty($invoice->fk_account)) {
            require_once DOL_DOCUMENT_ROOT . '/compta/bank/class/account.class.php';
            $account = new Account($this->db);
            if ($account->fetch($invoice->fk_account) > 0 && !empty($account->iban)) {
                $rachunekBankowy = $xml->createElement('RachunekBankowy');
                $platnosc->appendChild($rachunekBankowy);
                $rachunekBankowy->appendChild($xml->createElement('NrRB', $this->cleanIBAN($account->iban)));
                if (!empty($account->bic)) {
                    $rachunekBankowy->appendChild($xml->createElement('SWIFT', strtoupper(trim($account->bic))));
                }
                if (!empty($account->bank)) {
                    $rachunekBankowy->appendChild($xml->createElement('NazwaBanku', $this->xmlSafe($account->bank)));
                }
                // OpisRachunku: Bank account description/label
                if (!empty($conf->global->KSEF_FA3_INCLUDE_BANK_DESC) && !empty($account->label)) {
                    $rachunekBankowy->appendChild($xml->createElement('OpisRachunku', $this->xmlSafe($account->label)));
                }
            }
        }

        // Factor bank accounts from Dolibarr third-party bank accounts
        if (getDolGlobalString('KSEF_PODMIOT3_SOURCE', 'disabled') === 'enabled') {
            $this->buildRachunekBankowyFaktora($xml, $platnosc, $invoice);
        }
    }

    /**
     * @brief Emit <RachunekBankowyFaktora> nodes for factor third-parties
     * @called_by buildPlatnosc()
     */
    private function buildRachunekBankowyFaktora($xml, $platnosc, $invoice)
    {
        $emitted = 0;
        foreach ($this->getPodmiot3List($invoice) as $e) {
            if ($e['role'] !== '1') {
                continue;
            }
            $accounts = $this->getSocieteBankAccounts((int) $e['id']);
            if (empty($accounts)) {
                dol_syslog('FA3Builder::buildRachunekBankowyFaktora - factor societe ' . $e['id'] . ' has no bank account on file; RachunekBankowyFaktora omitted', LOG_WARNING);
                continue;
            }
            foreach ($accounts as $account) {
                if ($emitted >= 20) {
                    break 2;
                }
                $rb = $xml->createElement('RachunekBankowyFaktora');
                $platnosc->appendChild($rb);
                $rb->appendChild($xml->createElement('NrRB', $this->cleanIBAN($account->iban)));
                if (!empty($account->bic)) {
                    $rb->appendChild($xml->createElement('SWIFT', strtoupper(trim($account->bic))));
                }
                if (!empty($account->bank)) {
                    $rb->appendChild($xml->createElement('NazwaBanku', $this->xmlSafe($account->bank)));
                }
                if (!empty($account->label)) {
                    $rb->appendChild($xml->createElement('OpisRachunku', $this->xmlSafe($account->label)));
                }
                $emitted++;
            }
        }
    }

    /**
     * @brief Load a third-party's bank accounts from llx_societe_rib.
     * @param int $socid Societe rowid
     * @return array List of CompanyBankAccount objects (decrypted IBAN), empty if none
     * @called_by buildRachunekBankowyFaktora()
     */
    private function getSocieteBankAccounts($socid)
    {
        if ($socid <= 0) {
            return array();
        }
        require_once DOL_DOCUMENT_ROOT . '/societe/class/companybankaccount.class.php';
        $accounts = array();
        $sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "societe_rib"
            . " WHERE fk_soc = " . ((int) $socid) . " AND type = 'ban'"
            . " ORDER BY default_rib DESC, rowid ASC";
        $res = $this->db->query($sql);
        if ($res) {
            while ($obj = $this->db->fetch_object($res)) {
                $acc = new CompanyBankAccount($this->db);
                if ($acc->fetch($obj->rowid) > 0 && !empty($acc->iban)) {
                    $accounts[] = $acc;
                }
            }
        }
        return $accounts;
    }

    /**
     * @brief Builds WarunkiTransakcji section (order/contract numbers)
     * @param DOMDocument $xml
     * @param DOMElement $parent Parent element (Fa)
     * @param object $invoice Invoice object
     * @called_by buildFa()
     */
    private function buildWarunkiTransakcji($xml, $parent, $invoice)
    {
        global $conf;

        // NrZamowienia
        $orderRefs = array();
        $zamowieniaSource = getDolGlobalString('KSEF_NR_ZAMOWIENIA_SOURCE', 'ref_client');
        if ($zamowieniaSource === 'ref_client') {
            $val = isset($invoice->ref_client) ? trim((string) $invoice->ref_client) : '';
            if (!empty($val)) $orderRefs[] = mb_substr($val, 0, 256, 'UTF-8');
        } elseif ($zamowieniaSource === 'linked_order') {
            foreach ($this->getLinkedOrderRefs($invoice) as $ref) {
                $orderRefs[] = mb_substr($ref, 0, 256, 'UTF-8');
            }
        } elseif (strpos($zamowieniaSource, 'extrafield:') === 0) {
            $fieldName = substr($zamowieniaSource, strlen('extrafield:'));
            if (!empty($fieldName)) {
                if (!isset($invoice->array_options) || empty($invoice->array_options)) {
                    if (method_exists($invoice, 'fetch_optionals')) $invoice->fetch_optionals();
                }
                $val = isset($invoice->array_options['options_' . $fieldName])
                    ? trim(strip_tags((string) $invoice->array_options['options_' . $fieldName]))
                    : '';
                if (!empty($val)) $orderRefs[] = mb_substr($val, 0, 256, 'UTF-8');
            }
        }

        // NrUmowy
        $nrUmowy = '';
        $umowySource = getDolGlobalString('KSEF_NR_UMOWY_SOURCE', 'disabled');
        if (strpos($umowySource, 'thirdparty_extrafield:') === 0) {
            $fieldName = substr($umowySource, strlen('thirdparty_extrafield:'));
            if (!empty($fieldName) && !empty($this->currentCustomer)) {
                if (!isset($this->currentCustomer->array_options) || empty($this->currentCustomer->array_options)) {
                    if (method_exists($this->currentCustomer, 'fetch_optionals')) $this->currentCustomer->fetch_optionals();
                }
                $nrUmowy = isset($this->currentCustomer->array_options['options_' . $fieldName])
                    ? trim(strip_tags((string) $this->currentCustomer->array_options['options_' . $fieldName]))
                    : '';
            }
        } elseif (strpos($umowySource, 'extrafield:') === 0) {
            // Invoice extrafield
            $fieldName = substr($umowySource, strlen('extrafield:'));
            if (!empty($fieldName)) {
                if (!isset($invoice->array_options) || empty($invoice->array_options)) {
                    if (method_exists($invoice, 'fetch_optionals')) $invoice->fetch_optionals();
                }
                $nrUmowy = isset($invoice->array_options['options_' . $fieldName])
                    ? trim(strip_tags((string) $invoice->array_options['options_' . $fieldName]))
                    : '';
            }
        }
        // NrUmowy parse into DataUmowy
        $dataUmowy = '';
        if (!empty($nrUmowy) && getDolGlobalInt('KSEF_NR_UMOWY_PARSE_DATE')) {
            $parsed = ksefParseContractDate($nrUmowy);
            if (!empty($parsed['date'])) {
                $dataUmowy = $parsed['date'];
                $nrUmowy = $parsed['nr'];
            }
        }
        $nrUmowy = mb_substr($nrUmowy, 0, 256, 'UTF-8');

        if (empty($orderRefs) && empty($nrUmowy) && empty($dataUmowy)) {
            return;
        }

        $warunki = $xml->createElement('WarunkiTransakcji');
        $parent->appendChild($warunki);

        if (!empty($nrUmowy) || !empty($dataUmowy)) {
            $umowy = $xml->createElement('Umowy');
            $warunki->appendChild($umowy);
            if (!empty($dataUmowy)) {
                $umowy->appendChild($xml->createElement('DataUmowy', $dataUmowy));
            }
            if (!empty($nrUmowy)) {
                $umowy->appendChild($xml->createElement('NrUmowy', $this->xmlSafe($nrUmowy)));
            }
        }

        foreach ($orderRefs as $ref) {
            $zamowienia = $xml->createElement('Zamowienia');
            $warunki->appendChild($zamowienia);
            $zamowienia->appendChild($xml->createElement('NrZamowienia', $this->xmlSafe($ref)));
        }
    }

    /**
     * @brief Builds footer section with registry numbers
     * @param DOMDocument $xml
     * @param DOMElement $parent Parent element
     * @called_by buildFromInvoice()
     */
    private function buildStopka($xml, $parent, $invoice = null)
    {
        global $conf, $mysoc;

        $krs = !empty($conf->global->KSEF_COMPANY_KRS) ? trim($conf->global->KSEF_COMPANY_KRS) : '';
        $regon = !empty($conf->global->KSEF_COMPANY_REGON) ? trim($conf->global->KSEF_COMPANY_REGON) : '';
        $bdo = !empty($conf->global->KSEF_COMPANY_BDO) ? trim($conf->global->KSEF_COMPANY_BDO) : '';
        if (empty($krs)) {
            $krs = trim(ksefGetIdentifierField($mysoc, 'KRS'));
        }
        if (empty($regon)) {
            $regon = trim(ksefGetIdentifierField($mysoc, 'REGON'));
        }
        if (empty($bdo)) {
            $bdo = trim(ksefGetIdentifierField($mysoc, 'BDO'));
        }

        $hasRejestry = !empty($krs) || !empty($regon) || !empty($bdo);

        // StopkaFaktury
        $stopkaTexts = array();

        // note_public
        $noteTarget = getDolGlobalString('KSEF_NOTE_PUBLIC_TARGET', 'stopka_faktury');
        $noteMode = getDolGlobalString('KSEF_DODATKOWY_OPIS_NOTE_MODE', 'simple');
        $includeNoteInStopka = ($noteTarget === 'stopka_faktury' && $noteMode !== 'disabled');

        $isDisabledOverride = false;
        if ($invoice) {
            if (!isset($invoice->array_options) || empty($invoice->array_options)) {
                if (method_exists($invoice, 'fetch_optionals')) $invoice->fetch_optionals();
            }
            $invoiceOverride = isset($invoice->array_options['options_ksef_dodatkowy_opis_mode'])
                ? trim((string) $invoice->array_options['options_ksef_dodatkowy_opis_mode'])
                : '';
            if (!empty($invoiceOverride)) {
                if ($invoiceOverride === 'disabled') {
                    $isDisabledOverride = true;
                    $includeNoteInStopka = false;
                } else {
                    $parsed = self::parseCombinedNoteMode($invoiceOverride);
                    if ($parsed) {
                        $includeNoteInStopka = ($parsed['target'] === 'stopka_faktury' && $parsed['mode'] !== 'disabled');
                    }
                }
            }
        }

        if ($includeNoteInStopka && $invoice && !empty($invoice->note_public)) {
            $text = trim(self::stripNoteHtml($invoice->note_public));
            if (!empty($text)) {
                $stopkaTexts[] = mb_substr($text, 0, 3500, 'UTF-8');
            }
        }

        // Boilerplate note
        $boilerplate = getDolGlobalString('KSEF_STOPKA_BOILERPLATE', '');
        if (!empty($boilerplate)) {
            $stopkaTexts[] = mb_substr(trim($boilerplate), 0, 3500, 'UTF-8');
        }

        // Extrafields for stopka
        if ($invoice && !$isDisabledOverride) {
            $stopkaEfText = self::collectStopkaExtrafields($invoice, $conf, $this->db, $this->currentCustomer);
            if (!empty($stopkaEfText)) {
                $stopkaTexts[] = mb_substr($stopkaEfText, 0, 3500, 'UTF-8');
            }
        }

        if (!$hasRejestry && empty($stopkaTexts)) {
            return;
        }

        $stopka = $xml->createElement('Stopka');
        $parent->appendChild($stopka);

        // StopkaFaktury before Rejestry
        if (!empty($stopkaTexts)) {
            foreach ($stopkaTexts as $footerText) {
                $informacje = $xml->createElement('Informacje');
                $stopka->appendChild($informacje);
                $informacje->appendChild($xml->createElement('StopkaFaktury', $this->xmlSafe($footerText)));
            }
        }

        if ($hasRejestry) {
            $rejestry = $xml->createElement('Rejestry');
            $stopka->appendChild($rejestry);

            if (!empty($krs)) {
                $rejestry->appendChild($xml->createElement('KRS', $this->xmlSafe($krs)));
            }
            if (!empty($regon)) {
                $rejestry->appendChild($xml->createElement('REGON', $this->xmlSafe($regon)));
            }
            if (!empty($bdo)) {
                $rejestry->appendChild($xml->createElement('BDO', $this->xmlSafe($bdo)));
            }
        }
    }

    /**
     * @brief Cleans IBAN (removes spaces)
     * @param $iban IBAN string
     * @return string Cleaned IBAN
     * @called_by buildPlatnosc()
     */
    private function cleanIBAN($iban)
    {
        return preg_replace('/\s+/', '', strtoupper($iban));
    }


    /**
     * @brief Makes string XML-safe
     * @param $str String to escape
     * @return string Escaped string
     * @called_by buildPodmiot1(), buildPodmiot2(), buildFaWiersz()
     */
    private function xmlSafe($str)
    {
        return ksefXmlEscape(trim($str));
    }

    // https://github.com/Dolibarr/dolibarr/blob/develop/htdocs/install/mysql/data/llx_c_paiement.sql
    /**
     * @brief Maps Dolibarr payment mode to KSeF code
     * @param $mode_reglement_id Payment mode ID
     * @return string KSeF payment code
     * @called_by buildPlatnosc()
     * KSeF codes: 1=Cash, 2=Card, 3=Voucher, 4=Check, 5=Credit, 6=Transfer, 7=Mobile
     */
    private function getPaymentMethodCode($mode_reglement_id)
    {
        $mapping = array(
            1  => '6',  // TIP
            2  => '6',  // VIR
            3  => '6',  // PRE
            4  => '1',  // LIQ
            6  => '2',  // CB
            7  => '4',  // CHQ
            50 => '6',  // VAD
            51 => '6',  // TRA
            52 => '6',  // LCR
            53 => '6',  // FAC
        );
        return isset($mapping[$mode_reglement_id]) ? $mapping[$mode_reglement_id] : '6';
    }

    /**
     * @brief Maps Dolibarr string to KSeF FormaPlatnosci code
     * @param string $typeCode Payment type code from c_paiement table (e.g. 'VIR', 'CB', 'CHQ')
     * @return string|null KSeF payment code, or null if unknown
     * @called_by buildPlatnosc()
     * KSeF codes: 1=Cash, 2=Card, 3=Voucher, 4=Check, 5=Credit, 6=Transfer, 7=Mobile
     */
    private function getPaymentMethodCodeByType($typeCode)
    {
        $mapping = array(
            'TIP' => '6',
            'VIR' => '6',
            'PRE' => '6',
            'LIQ' => '1',
            'CB'  => '2',
            'CHQ' => '4',
            'VAD' => '6',
            'TRA' => '6',
            'LCR' => '6',
            'FAC' => '6',
        );
        return isset($mapping[$typeCode]) ? $mapping[$typeCode] : null;
    }

    /**
     * @brief Gets KSeF number from previous submission
     * @param $invoice_id Invoice ID
     * @return string|null KSeF number
     * @called_by buildDaneFaKorygowanej()
     */
    private function getInvoiceKsefNumber($invoice_id)
    {
        $sql = "SELECT ksef_number FROM " . MAIN_DB_PREFIX . "ksef_submissions";
        $sql .= " WHERE fk_facture = " . (int)$invoice_id . " AND status = 'ACCEPTED' AND ksef_number IS NOT NULL";
        $sql .= " ORDER BY date_submission DESC LIMIT 1";

        $resql = $this->db->query($sql);
        if ($resql) {
            if ($this->db->num_rows($resql) > 0) {
                $obj = $this->db->fetch_object($resql);
                return $obj->ksef_number;
            }
        }
        return null;
    }

    /**
     * @brief Gets entity flags (JST, VAT group)
     * @param $entity Entity object
     * @return array Flags array
     * @called_by buildPodmiot2()
     */
    private function getEntityFlags($entity)
    {
        $flags = array('jst' => '2', 'gv' => '2'); // Defaults: No

        if (!empty($entity->array_options['options_is_jst'])) {
            $flags['jst'] = $entity->array_options['options_is_jst'] ? '1' : '2';
        }
        if (!empty($entity->array_options['options_is_vat_group'])) {
            $flags['gv'] = $entity->array_options['options_is_vat_group'] ? '1' : '2';
        }
        return $flags;
    }

    /**
     * @brief Validates XML against schema
     * @param $xml XML string
     * @return bool True if valid
     * @called_by KSEF::validateFA3XML()
     */
    public function validate($xml)
    {
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $valid = $dom->loadXML($xml);
        if (!$valid) {
            foreach (libxml_get_errors() as $error) {
                $this->errors[] = "XML Error: " . $error->message;
            }
            libxml_clear_errors();
        }
        return $valid;
    }

    /**
     * @brief Checks if country is EU member state
     * @param string $countryCode ISO 2-letter country code
     * @return bool True if EU member
     * @called_by buildPodmiot2()
     */
    private function isEUCountry($countryCode)
    {
        $euCountries = array(
            'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR',
            'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL',
            'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE'
        );
        return in_array(strtoupper($countryCode), $euCountries);
    }

    /**
     * @brief Gets reference numbers of linked sales orders
     * @param object $invoice Invoice object
     * @return array Array of order reference strings
     * @called_by buildWarunkiTransakcji()
     */
    private function getLinkedOrderRefs($invoice)
    {
        $invoice->fetchObjectLinked(null, '', null, '', 'OR', 1, 'sourcetype', 0);
        if (empty($invoice->linkedObjectsIds['commande'])) return array();

        require_once DOL_DOCUMENT_ROOT . '/commande/class/commande.class.php';
        $refs = array();
        foreach ($invoice->linkedObjectsIds['commande'] as $orderId) {
            $order = new Commande($this->db);
            if ($order->fetch($orderId) > 0) {
                $ref = trim($order->ref);
                if (!empty($ref)) $refs[] = $ref;
            }
        }
        return $refs;
    }

    /**
     * @brief Maps VAT rate to KSeF P_12 value
     * @param float $vatRate VAT rate from invoice line
     * @param object $line Invoice line object (for additional context)
     * @return string KSeF P_12 value
     * @called_by buildFaWiersz(), calculateVatSummary()
     */
    private function mapVatRateToKSeF($vatRate, $line = null)
    {
        $rateInt = (int) round($vatRate);
        if (in_array($rateInt, array(23, 22, 8, 7, 5, 4, 3))) {
            return (string) $rateInt;
        }

        if ($rateInt == 0) {
            $code = !empty($line->vat_src_code) ? strtoupper(trim($line->vat_src_code)) : '';
            if (!empty($code)) {
                if ($code === 'ZW')  return 'zw';
                if ($code === 'RC' || $code === 'OO')  return 'oo';
                if ($code === 'NP2' || $code === 'NPII' || $code === 'NP II') return 'np II';
                if ($code === 'NP' || $code === 'NP1' || $code === 'NPI' || $code === 'NP I') return 'np I';
                if ($code === 'WDT') return '0 WDT';
                if ($code === 'EX')  return '0 EX';

                if (strpos($code, 'ZW') !== false)  return 'zw';
                if (strpos($code, 'RC') !== false || strpos($code, 'OO') !== false)  return 'oo';
                if (strpos($code, 'WDT') !== false) return '0 WDT';
                if (strpos($code, 'EX') !== false)  return '0 EX';
                if (strpos($code, 'NP2') !== false || strpos($code, 'NPII') !== false) return 'np II';
                if (strpos($code, 'NP') !== false)  return 'np I';
            }

            if (!empty($this->currentCustomerCountry)) {
                if ($this->currentCustomerCountry === 'PL') {
                    return '0 KR';
                } elseif ($this->currentCustomerIsEU) {
                    return '0 WDT';
                } else {
                    return '0 EX';
                }
            }

            return '0 KR';
        }

        if ($vatRate > 0) {
            return (string) $rateInt;
        }

        return 'np I';
    }
}