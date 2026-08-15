<?php
/* Copyright (C) 2026 InPoint Automation Sp z o.o.
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
 * \file    ksef/class/fa3_parser.class.php
 * \ingroup ksef
 * \brief   FA(3) XML Parser
 */

dol_include_once('/ksef/lib/ksef.lib.php');

class FA3Parser
{
    // Payment status
    const PAYMENT_PAID = 'paid';
    const PAYMENT_PAID_INSTALLMENTS = 'paid_installments';
    const PAYMENT_PARTIAL = 'partial';
    const PAYMENT_UNPAID = 'unpaid';

    private $db;
    public $error;
    public $errors = array();

    public function __construct($db)
    {
        $this->db = $db;
    }


    /**
     * @brief Parse FA(3) XML string into structured array
     * @param $xml Raw XML string
     * @return array|false Parsed data or false on error
     * @called_by KSEF::syncIncomingInvoices()
     * @calls parseNaglowek(), parsePodmiot1(), parsePodmiot2(), parseFa(), parseVatSummary(), parseFaWiersz(), parsePlatnosc(), parseDaneFaKorygowanej()
     */
    public function parse($xml)
    {
        if (empty($xml)) {
            $this->error = "Empty XML provided";
            return false;
        }

        try {
            $doc = new DOMDocument();
            $doc->preserveWhiteSpace = false;

            $loaded = @$doc->loadXML($xml);
            if (!$loaded) {
                $this->error = "Failed to parse XML";
                return false;
            }

            $rootNs = $doc->documentElement->namespaceURI;
            if ($rootNs !== KSEF_FA3_NAMESPACE) {
                $kodSystemowy = '';
                $kodNodes = $doc->getElementsByTagName('KodFormularza');
                if ($kodNodes->length > 0) {
                    $kodSystemowy = $kodNodes->item(0)->getAttribute('kodSystemowy');
                }
                $this->error = "Unsupported invoice schema: " . ($kodSystemowy ?: 'unknown') . " (namespace: {$rootNs}). Only FA(3) is supported.";
                return false;
            }

            $xpath = new DOMXPath($doc);
            $xpath->registerNamespace('fa', KSEF_FA3_NAMESPACE);

            $invoice = $this->parseFa($xpath);
            $lineData = $this->parseFaWiersz($xpath);

            // ZAL invoices use ZamowienieWiersz
            if (empty($lineData['lines']) && empty($lineData['lines_before'])) {
                $zamData = $this->parseZamowienieWiersz($xpath);
                if (!empty($zamData['lines']) || !empty($zamData['lines_before'])) {
                    $lineData = $zamData;
                }
            }

            $result = array(
                'header' => $this->parseNaglowek($xpath),
                'seller' => $this->parsePodmiot1($xpath),
                'buyer' => $this->parsePodmiot2($xpath),
                'buyer_before' => $this->parsePodmiot2K($xpath),
                'third_parties' => $this->parsePodmiot3($xpath),
                'invoice' => $invoice,
                'vat_summary' => $this->parseVatSummary($xpath),
                'lines' => $lineData['lines'],
                'lines_before' => $lineData['lines_before'],
                'payment' => $this->parsePlatnosc($xpath),
                'correction' => $this->parseDaneFaKorygowanej($xpath),
                'registries' => $this->parseRegistries($xpath),
                'stopka' => $this->parseStopka($xpath),
                'additional_info' => $this->parseAdditionalInfo($invoice),
                'additional_desc' => $this->parseAdditionalDesc($xpath),
                'exchange_rate' => $this->parseExchangeRate($xpath, $lineData['lines']),
                'warunki' => $this->parseWarunkiTransakcji($xpath),
            );

            return $result;

        } catch (Exception $e) {
            $this->error = "FA(3) parsing error: " . $e->getMessage();
            $this->errors[] = $this->error;
            dol_syslog("FA3Parser::parse ERROR: " . $this->error, LOG_ERR);
            return false;
        }
    }


    /**
     * @brief Parse header section (Naglowek)
     * @param $xpath DOMXPath object
     * @return array Header data
     * @called_by parse()
     * @calls getValue()
     */
    private function parseNaglowek($xpath)
    {
        $header = array(
            'form_code' => '',
            'system_code' => '',
            'schema_version' => '',
            'form_variant' => '',
            'creation_date' => null,
            'system_info' => '',
        );

        $kodFormularza = $xpath->query('//fa:Naglowek/fa:KodFormularza');
        if ($kodFormularza->length > 0) {
            $node = $kodFormularza->item(0);
            $header['form_code'] = $node->textContent;
            $header['system_code'] = $node->getAttribute('kodSystemowy');
            $header['schema_version'] = $node->getAttribute('wersjaSchemy');
        }

        $header['form_variant'] = $this->getValue($xpath, '//fa:Naglowek/fa:WariantFormularza');

        $header['creation_date'] = $this->getValue($xpath, '//fa:Naglowek/fa:DataWytworzeniaFa', null);

        $header['system_info'] = $this->getValue($xpath, '//fa:Naglowek/fa:SystemInfo');

        return $header;
    }


    /**
     * @brief Parse seller section (Podmiot1)
     * @param $xpath DOMXPath object
     * @return array Seller data
     * @called_by parse()
     * @calls getValue()
     */
    private function parsePodmiot1($xpath)
    {
        $seller = array(
            'nip' => '',
            'name' => '',
            'country' => 'PL',
            'address' => '',
            'email' => null,
            'phone' => null,
            'kod_ue' => null,
            'nr_vat_ue' => null,
            'nr_eori' => null,
        );

        $nrEori = $this->getValue($xpath, '//fa:Podmiot1/fa:NrEORI', null);
        if ($nrEori) $seller['nr_eori'] = $nrEori;

        $seller['nip'] = $this->getValue($xpath, '//fa:Podmiot1/fa:DaneIdentyfikacyjne/fa:NIP');

        $kodUE = $this->getValue($xpath, '//fa:Podmiot1/fa:DaneIdentyfikacyjne/fa:KodUE', null);
        if ($kodUE) $seller['kod_ue'] = $kodUE;
        $nrVatUE = $this->getValue($xpath, '//fa:Podmiot1/fa:DaneIdentyfikacyjne/fa:NrVatUE', null);
        if ($nrVatUE) $seller['nr_vat_ue'] = $nrVatUE;

        $seller['name'] = $this->getValue($xpath, '//fa:Podmiot1/fa:DaneIdentyfikacyjne/fa:Nazwa');
        $seller['country'] = $this->getValue($xpath, '//fa:Podmiot1/fa:Adres/fa:KodKraju', 'PL');
        $seller['address'] = $this->getValue($xpath, '//fa:Podmiot1/fa:Adres/fa:AdresL1');

        $adresL2 = $this->getValue($xpath, '//fa:Podmiot1/fa:Adres/fa:AdresL2');
        if ($adresL2) {
            $seller['address'] .= "\n" . $adresL2;
        }

        // Contact info
        $email = $this->getValue($xpath, '//fa:Podmiot1/fa:DaneKontaktowe/fa:Email', null);
        if ($email) $seller['email'] = $email;
        $phone = $this->getValue($xpath, '//fa:Podmiot1/fa:DaneKontaktowe/fa:Telefon', null);
        if ($phone) $seller['phone'] = $phone;

        return $seller;
    }


    /**
     * @brief Parse buyer section (Podmiot2)
     * @param $xpath DOMXPath object
     * @return array Buyer data
     * @called_by parse()
     * @calls getValue()
     */
    private function parsePodmiot2($xpath)
    {
        $buyer = array(
            'nip' => '',
            'name' => '',
            'country' => 'PL',
            'address' => '',
            'jst' => '',
            'gv' => '',
            'email' => null,
            'phone' => null,
            'customer_number' => null,
            'kod_ue' => null,
            'nr_vat_ue' => null,
            'nr_eori' => null,
        );

        $nrEori = $this->getValue($xpath, '//fa:Podmiot2/fa:NrEORI', null);
        if ($nrEori) $buyer['nr_eori'] = $nrEori;

        // NIP
        $buyer['nip'] = $this->getValue($xpath, '//fa:Podmiot2/fa:DaneIdentyfikacyjne/fa:NIP');
        if (empty($buyer['nip'])) {
            $nrIdNode = $xpath->query('//fa:Podmiot2/fa:DaneIdentyfikacyjne/fa:NrID');
            if ($nrIdNode->length > 0) {
                $buyer['nip'] = $nrIdNode->item(0)->textContent;
                $kodKraju = $nrIdNode->item(0)->getAttribute('kodKraju');
                if ($kodKraju) {
                    $buyer['country'] = $kodKraju;
                }
            }
        }

        $buyer['name'] = $this->getValue($xpath, '//fa:Podmiot2/fa:DaneIdentyfikacyjne/fa:Nazwa');

        // EU buyer identification
        $kodUE = $this->getValue($xpath, '//fa:Podmiot2/fa:DaneIdentyfikacyjne/fa:KodUE', null);
        if ($kodUE) $buyer['kod_ue'] = $kodUE;
        $nrVatUE = $this->getValue($xpath, '//fa:Podmiot2/fa:DaneIdentyfikacyjne/fa:NrVatUE', null);
        if ($nrVatUE) $buyer['nr_vat_ue'] = $nrVatUE;

        // Address
        $adresKodKraju = $this->getValue($xpath, '//fa:Podmiot2/fa:Adres/fa:KodKraju');
        if ($adresKodKraju) {
            $buyer['country'] = $adresKodKraju;
        }

        $buyer['address'] = $this->getValue($xpath, '//fa:Podmiot2/fa:Adres/fa:AdresL1');
        $adresL2 = $this->getValue($xpath, '//fa:Podmiot2/fa:Adres/fa:AdresL2');
        if ($adresL2) {
            $buyer['address'] .= "\n" . $adresL2;
        }

        // Entity flags
        $buyer['jst'] = $this->getValue($xpath, '//fa:Podmiot2/fa:JST');
        $buyer['gv'] = $this->getValue($xpath, '//fa:Podmiot2/fa:GV');

        // Contact info
        $email = $this->getValue($xpath, '//fa:Podmiot2/fa:DaneKontaktowe/fa:Email', null);
        if ($email) $buyer['email'] = $email;
        $phone = $this->getValue($xpath, '//fa:Podmiot2/fa:DaneKontaktowe/fa:Telefon', null);
        if ($phone) $buyer['phone'] = $phone;

        // Customer number
        $nrKlienta = $this->getValue($xpath, '//fa:Podmiot2/fa:NrKlienta', null);
        if ($nrKlienta) $buyer['customer_number'] = $nrKlienta;

        return $buyer;
    }


    /**
     * @brief Parse Podmiot2K (buyer) from correction invoice
     * @param $xpath DOMXPath
     * @return array|null Original buyer or null
     * @called_by parse()
     * @calls getValue()
     */
    private function parsePodmiot2K($xpath)
    {
        $node = $xpath->query('//fa:Fa/fa:Podmiot2K');
        if ($node->length == 0) {
            return null;
        }

        $buyer = array(
            'nip' => '',
            'name' => '',
            'country' => 'PL',
            'address' => '',
            'kod_ue' => null,
            'nr_vat_ue' => null,
        );

        $buyer['nip'] = $this->getValue($xpath, '//fa:Fa/fa:Podmiot2K/fa:DaneIdentyfikacyjne/fa:NIP');
        if (empty($buyer['nip'])) {
            $nrIdNode = $xpath->query('//fa:Fa/fa:Podmiot2K/fa:DaneIdentyfikacyjne/fa:NrID');
            if ($nrIdNode->length > 0) {
                $buyer['nip'] = $nrIdNode->item(0)->textContent;
                $kodKraju = $nrIdNode->item(0)->getAttribute('kodKraju');
                if ($kodKraju) {
                    $buyer['country'] = $kodKraju;
                }
            }
        }

        $buyer['name'] = $this->getValue($xpath, '//fa:Fa/fa:Podmiot2K/fa:DaneIdentyfikacyjne/fa:Nazwa');

        $kodUE = $this->getValue($xpath, '//fa:Fa/fa:Podmiot2K/fa:DaneIdentyfikacyjne/fa:KodUE', null);
        if ($kodUE) $buyer['kod_ue'] = $kodUE;
        $nrVatUE = $this->getValue($xpath, '//fa:Fa/fa:Podmiot2K/fa:DaneIdentyfikacyjne/fa:NrVatUE', null);
        if ($nrVatUE) $buyer['nr_vat_ue'] = $nrVatUE;

        $adresKodKraju = $this->getValue($xpath, '//fa:Fa/fa:Podmiot2K/fa:Adres/fa:KodKraju');
        if ($adresKodKraju) {
            $buyer['country'] = $adresKodKraju;
        }

        $buyer['address'] = $this->getValue($xpath, '//fa:Fa/fa:Podmiot2K/fa:Adres/fa:AdresL1');
        $adresL2 = $this->getValue($xpath, '//fa:Fa/fa:Podmiot2K/fa:Adres/fa:AdresL2');
        if ($adresL2) {
            $buyer['address'] .= "\n" . $adresL2;
        }

        return $buyer;
    }


    /**
     * @brief Parse podmiot3 sections
     * @param $xpath DOMXPath object
     * @return array List of third-party entries
     * @called_by parse()
     * @calls getValue()
     */
    private function parsePodmiot3($xpath)
    {
        $result = array();
        $nodes = $xpath->query('//fa:Podmiot3');
        for ($i = 0; $i < $nodes->length; $i++) {
            $base = '//fa:Podmiot3[' . ($i + 1) . ']';
            $entry = array(
                'nip'         => $this->getValue($xpath, $base . '/fa:DaneIdentyfikacyjne/fa:NIP'),
                'idwew'       => $this->getValue($xpath, $base . '/fa:DaneIdentyfikacyjne/fa:IDWew', null),
                'name'        => $this->getValue($xpath, $base . '/fa:DaneIdentyfikacyjne/fa:Nazwa'),
                'kod_ue'      => $this->getValue($xpath, $base . '/fa:DaneIdentyfikacyjne/fa:KodUE', null),
                'nr_vat_ue'   => $this->getValue($xpath, $base . '/fa:DaneIdentyfikacyjne/fa:NrVatUE', null),
                'country'     => $this->getValue($xpath, $base . '/fa:Adres/fa:KodKraju', 'PL'),
                'address'     => $this->getValue($xpath, $base . '/fa:Adres/fa:AdresL1'),
                'role'        => $this->getValue($xpath, $base . '/fa:Rola'),
                'udzial'      => $this->getValue($xpath, $base . '/fa:Udzial', null),
                'nr_klienta'  => $this->getValue($xpath, $base . '/fa:NrKlienta', null),
                'id_nabywcy'  => $this->getValue($xpath, $base . '/fa:IDNabywcy', null),
                'email'       => $this->getValue($xpath, $base . '/fa:DaneKontaktowe/fa:Email', null),
                'phone'       => $this->getValue($xpath, $base . '/fa:DaneKontaktowe/fa:Telefon', null),
            );
            $adresL2 = $this->getValue($xpath, $base . '/fa:Adres/fa:AdresL2');
            if ($adresL2) {
                $entry['address'] .= "\n" . $adresL2;
            }
            if (empty($entry['nip'])) {
                $nrId = $this->getValue($xpath, $base . '/fa:DaneIdentyfikacyjne/fa:NrID', null);
                if ($nrId) {
                    $entry['nip'] = $nrId;
                } elseif (!empty($entry['idwew'])) {
                    $entry['nip'] = $entry['idwew'];
                }
            }
            $result[] = $entry;
        }
        return $result;
    }


    /**
     * @brief Parse invoice data section (Fa)
     * @param $xpath DOMXPath object
     * @return array Invoice data
     * @called_by parse()
     * @calls getValue(), getDecimal()
     */
    private function parseFa($xpath)
    {
        $invoice = array(
            'currency' => 'PLN',
            'number' => '',
            'type' => 'VAT',
            'date' => null,
            'sale_date' => null,
            'total_net' => 0.0,
            'total_vat' => 0.0,
            'total_gross' => 0.0,
            'place_of_issue' => null,
            'fp_flag' => false,
            'total_amount' => null,
        );

        $invoice['currency'] = $this->getValue($xpath, '//fa:Fa/fa:KodWaluty', 'PLN');
        $invoice['number'] = $this->getValue($xpath, '//fa:Fa/fa:P_2');
        $invoice['type'] = $this->getValue($xpath, '//fa:Fa/fa:RodzajFaktury', 'VAT');

        $invoice['date'] = $this->getValue($xpath, '//fa:Fa/fa:P_1', null);
        $invoice['sale_date'] = $this->getValue($xpath, '//fa:Fa/fa:P_6', null);
        $invoice['period_from'] = $this->getValue($xpath, '//fa:Fa/fa:OkresFa/fa:P_6_Od', null);
        $invoice['period_to'] = $this->getValue($xpath, '//fa:Fa/fa:OkresFa/fa:P_6_Do', null);

        // Place of issue
        $p1m = $this->getValue($xpath, '//fa:Fa/fa:P_1M', null);
        if ($p1m) $invoice['place_of_issue'] = $p1m;

        // FP flag (art. 109)
        $fp = $this->getValue($xpath, '//fa:Fa/fa:FP');
        $invoice['fp_flag'] = ($fp === '1');

        // Adnotacje flags
        $invoice['p_16_flag'] = ($this->getValue($xpath, '//fa:Fa/fa:Adnotacje/fa:P_16') === '1'); // cash accounting
        $invoice['p_17_flag'] = ($this->getValue($xpath, '//fa:Fa/fa:Adnotacje/fa:P_17') === '1'); // self-billing
        $invoice['p_18_flag'] = ($this->getValue($xpath, '//fa:Fa/fa:Adnotacje/fa:P_18') === '1'); // reverse charge
        $invoice['mpp_flag']  = ($this->getValue($xpath, '//fa:Fa/fa:Adnotacje/fa:P_18A') === '1'); // split payment (MPP)
        $invoice['p_23_flag'] = ($this->getValue($xpath, '//fa:Fa/fa:Adnotacje/fa:P_23') === '1');

        // TP related party
        $invoice['tp_flag'] = ($this->getValue($xpath, '//fa:Fa/fa:TP') === '1');

        // Tax exemption
        $invoice['p_19_flag'] = ($this->getValue($xpath, '//fa:Fa/fa:Adnotacje/fa:Zwolnienie/fa:P_19') === '1');
        $invoice['p_19a'] = $this->getValue($xpath, '//fa:Fa/fa:Adnotacje/fa:Zwolnienie/fa:P_19A', null);
        $invoice['p_19b'] = $this->getValue($xpath, '//fa:Fa/fa:Adnotacje/fa:Zwolnienie/fa:P_19B', null);
        $invoice['p_19c'] = $this->getValue($xpath, '//fa:Fa/fa:Adnotacje/fa:Zwolnienie/fa:P_19C', null);

        // PMarzy
        $invoice['pmarzy_flag'] = ($this->getValue($xpath, '//fa:Fa/fa:Adnotacje/fa:PMarzy/fa:P_PMarzy') === '1');
        $invoice['pmarzy_subtype'] = null;
        if ($invoice['pmarzy_flag']) {
            $pm = '//fa:Fa/fa:Adnotacje/fa:PMarzy/fa:';
            if ($this->getValue($xpath, $pm . 'P_PMarzy_3_1') === '1') {
                $invoice['pmarzy_subtype'] = '3_1';
            } elseif ($this->getValue($xpath, $pm . 'P_PMarzy_3_2') === '1') {
                $invoice['pmarzy_subtype'] = '3_2';
            } elseif ($this->getValue($xpath, $pm . 'P_PMarzy_2') === '1') {
                $invoice['pmarzy_subtype'] = '2';
            } elseif ($this->getValue($xpath, $pm . 'P_PMarzy_3_3') === '1') {
                $invoice['pmarzy_subtype'] = '3_3';
            }
        }

        $totalNet = 0.0;
        $totalVat = 0.0;

        // Net amounts by rate
        $totalNet += $this->getDecimal($xpath, '//fa:Fa/fa:P_13_1');   // 23%
        $totalNet += $this->getDecimal($xpath, '//fa:Fa/fa:P_13_2');   // 8%
        $totalNet += $this->getDecimal($xpath, '//fa:Fa/fa:P_13_3');   // 5%
        $totalNet += $this->getDecimal($xpath, '//fa:Fa/fa:P_13_6_1'); // 0% KR
        $totalNet += $this->getDecimal($xpath, '//fa:Fa/fa:P_13_6_2'); // 0% WDT
        $totalNet += $this->getDecimal($xpath, '//fa:Fa/fa:P_13_6_3'); // 0% export
        $totalNet += $this->getDecimal($xpath, '//fa:Fa/fa:P_13_7');   // zwolnione (zw)
        $totalNet += $this->getDecimal($xpath, '//fa:Fa/fa:P_13_8');   // nie podlega I (np I)
        $totalNet += $this->getDecimal($xpath, '//fa:Fa/fa:P_13_9');   // nie podlega II (np II)
        $totalNet += $this->getDecimal($xpath, '//fa:Fa/fa:P_13_10');  // odwrotne obciążenie (oo)

        // VAT amounts by rate
        $totalVat += $this->getDecimal($xpath, '//fa:Fa/fa:P_14_1');   // 23%
        $totalVat += $this->getDecimal($xpath, '//fa:Fa/fa:P_14_2');   // 8%
        $totalVat += $this->getDecimal($xpath, '//fa:Fa/fa:P_14_3');   // 5%
        $totalVat += $this->getDecimal($xpath, '//fa:Fa/fa:P_14_4');   // 4%
        $totalVat += $this->getDecimal($xpath, '//fa:Fa/fa:P_14_5');   // 3%

        $invoice['total_net'] = $totalNet;
        $invoice['total_vat'] = $totalVat;

        // P_15: Total
        $p15raw = $this->getValue($xpath, '//fa:Fa/fa:P_15', null);
        if ($p15raw !== null && $p15raw !== '') $invoice['total_amount'] = $p15raw;
        $p15 = $this->getDecimal($xpath, '//fa:Fa/fa:P_15');
        $invoice['total_gross'] = ($p15 !== null && $p15 !== false) ? $p15 : ($totalNet + $totalVat);

        return $invoice;
    }


    /**
     * @brief Parse VAT summary from P_13 fields
     * @param $xpath DOMXPath object
     * @return array VAT summary by rate
     * @called_by parse()
     * @calls getDecimal()
     */
    private function parseVatSummary($xpath)
    {
        $summary = array();

        // 23% rate (P_13_1 / P_14_1)
        $net23 = $this->getDecimal($xpath, '//fa:Fa/fa:P_13_1');
        $vat23 = $this->getDecimal($xpath, '//fa:Fa/fa:P_14_1');
        if ($net23 != 0 || $vat23 != 0) {
            $summary['23'] = array('net' => $net23, 'vat' => $vat23, 'vat_pln' => null);
            $vatPln23 = $this->getValue($xpath, '//fa:Fa/fa:P_14_1W', null);
            if ($vatPln23 !== null && $vatPln23 !== '') {
                $summary['23']['vat_pln'] = (float)$vatPln23;
            }
        }

        // 8% rate (P_13_2 / P_14_2)
        $net8 = $this->getDecimal($xpath, '//fa:Fa/fa:P_13_2');
        $vat8 = $this->getDecimal($xpath, '//fa:Fa/fa:P_14_2');
        if ($net8 != 0 || $vat8 != 0) {
            $summary['8'] = array('net' => $net8, 'vat' => $vat8, 'vat_pln' => null);
            $vatPln8 = $this->getValue($xpath, '//fa:Fa/fa:P_14_2W', null);
            if ($vatPln8 !== null && $vatPln8 !== '') {
                $summary['8']['vat_pln'] = (float)$vatPln8;
            }
        }

        // 5% rate (P_13_3 / P_14_3)
        $net5 = $this->getDecimal($xpath, '//fa:Fa/fa:P_13_3');
        $vat5 = $this->getDecimal($xpath, '//fa:Fa/fa:P_14_3');
        if ($net5 != 0 || $vat5 != 0) {
            $summary['5'] = array('net' => $net5, 'vat' => $vat5, 'vat_pln' => null);
            $vatPln5 = $this->getValue($xpath, '//fa:Fa/fa:P_14_3W', null);
            if ($vatPln5 !== null && $vatPln5 !== '') {
                $summary['5']['vat_pln'] = (float)$vatPln5;
            }
        }

        // 4% rate (P_13_4 / P_14_4)
        $net4 = $this->getDecimal($xpath, '//fa:Fa/fa:P_13_4');
        $vat4 = $this->getDecimal($xpath, '//fa:Fa/fa:P_14_4');
        if ($net4 != 0 || $vat4 != 0) {
            $summary['4'] = array('net' => $net4, 'vat' => $vat4, 'vat_pln' => null);
            $vatPln4 = $this->getValue($xpath, '//fa:Fa/fa:P_14_4W', null);
            if ($vatPln4 !== null && $vatPln4 !== '') {
                $summary['4']['vat_pln'] = (float)$vatPln4;
            }
        }

        // 3% special (P_13_5 / P_14_5)
        $net3 = $this->getDecimal($xpath, '//fa:Fa/fa:P_13_5');
        $vat3 = $this->getDecimal($xpath, '//fa:Fa/fa:P_14_5');
        if ($net3 != 0 || $vat3 != 0) {
            $summary['3'] = array('net' => $net3, 'vat' => $vat3);
        }

        // 0% domestic (P_13_6_1)
        $net0KR = $this->getDecimal($xpath, '//fa:Fa/fa:P_13_6_1');
        if ($net0KR != 0) {
            $summary['0 KR'] = array('net' => $net0KR, 'vat' => 0.0);
        }

        // 0% intra-EU WDT (P_13_6_2)
        $net0WDT = $this->getDecimal($xpath, '//fa:Fa/fa:P_13_6_2');
        if ($net0WDT != 0) {
            $summary['0 WDT'] = array('net' => $net0WDT, 'vat' => 0.0);
        }

        // 0% export (P_13_6_3)
        $net0EX = $this->getDecimal($xpath, '//fa:Fa/fa:P_13_6_3');
        if ($net0EX != 0) {
            $summary['0 EX'] = array('net' => $net0EX, 'vat' => 0.0);
        }

        // Exempt (P_13_7)
        $netZw = $this->getDecimal($xpath, '//fa:Fa/fa:P_13_7');
        if ($netZw != 0) {
            $summary['zw'] = array('net' => $netZw, 'vat' => 0.0);
        }

        // Not subject I (P_13_8)
        $netNpI = $this->getDecimal($xpath, '//fa:Fa/fa:P_13_8');
        if ($netNpI != 0) {
            $summary['np I'] = array('net' => $netNpI, 'vat' => 0.0);
        }

        // Not subject II - art. 100 services (P_13_9)
        $netNpII = $this->getDecimal($xpath, '//fa:Fa/fa:P_13_9');
        if ($netNpII != 0) {
            $summary['np II'] = array('net' => $netNpII, 'vat' => 0.0);
        }

        // Reverse charge (P_13_10)
        $netOO = $this->getDecimal($xpath, '//fa:Fa/fa:P_13_10');
        if ($netOO != 0) {
            $summary['oo'] = array('net' => $netOO, 'vat' => 0.0);
        }

        return $summary;
    }


    /**
     * @brief Parse invoice line items (FaWiersz)
     * @param $xpath DOMXPath object
     * @return array Array with 'lines' and 'lines_before'
     * @called_by parse()
     */
    private function parseFaWiersz($xpath)
    {
        $lines = array();
        $linesBefore = array();

        $faWierszNodes = $xpath->query('//fa:Fa/fa:FaWiersz');
        $hasStanPrzed = false;

        foreach ($faWierszNodes as $node) {
            foreach ($node->childNodes as $child) {
                if ($child->nodeType === XML_ELEMENT_NODE && $child->localName === 'StanPrzed') {
                    $hasStanPrzed = true;
                    break 2;
                }
            }
        }

        foreach ($faWierszNodes as $node) {
            $line = array(
                'line_num' => 0,
                'uuid' => null,
                'indeks' => null,
                'gtin' => null,
                'cn' => null,
                'description' => '',
                'quantity' => 0.0,
                'unit' => null,
                'unit_price_net' => null,
                'unit_price_gross' => null,
                'discount' => null,
                'net_amount' => null,
                'gross_amount' => null,
                'vat_rate' => null,
                'kurs_waluty' => null,
            );

            $isBefore = false;

            foreach ($node->childNodes as $child) {
                if ($child->nodeType !== XML_ELEMENT_NODE) continue;

                switch ($child->localName) {
                    case 'NrWierszaFa':
                        $line['line_num'] = (int)$child->textContent;
                        break;
                    case 'UU_ID':
                        $line['uuid'] = trim($child->textContent);
                        break;
                    case 'Indeks':
                        $line['indeks'] = trim($child->textContent);
                        break;
                    case 'GTIN':
                        $line['gtin'] = trim($child->textContent);
                        break;
                    case 'P_7':
                        $line['description'] = $child->textContent;
                        break;
                    case 'P_8B':
                        $line['quantity'] = (float)$child->textContent;
                        break;
                    case 'P_8A':
                        $line['unit'] = trim($child->textContent);
                        break;
                    case 'P_9A':
                        $line['unit_price_net'] = (float)$child->textContent;
                        break;
                    case 'P_9B':
                        $line['unit_price_gross'] = (float)$child->textContent;
                        break;
                    case 'P_10':
                        $line['discount'] = trim($child->textContent);
                        break;
                    case 'P_11':
                        $line['net_amount'] = (float)$child->textContent;
                        break;
                    case 'P_11A':
                        $line['gross_amount'] = (float)$child->textContent;
                        break;
                    case 'P_12':
                        $line['vat_rate'] = trim($child->textContent);
                        break;
                    case 'CN':
                        $line['cn'] = trim($child->textContent);
                        break;
                    case 'KursWaluty':
                        $line['kurs_waluty'] = trim($child->textContent);
                        break;
                    case 'StanPrzed':
                        if ($child->textContent === '1') {
                            $isBefore = true;
                        }
                        break;
                }
            }

            // Calculate unit prices
            if ($this->isNullOrBlank($line['unit_price_net']) && !$this->isNullOrBlank($line['net_amount']) && !$this->isNullOrBlank($line['quantity']) && $line['quantity'] != 0) {
                $line['unit_price_net'] = round($line['net_amount'] / $line['quantity'], 4);
            }
            if ($this->isNullOrBlank($line['unit_price_gross']) && !$this->isNullOrBlank($line['gross_amount']) && !$this->isNullOrBlank($line['quantity']) && $line['quantity'] != 0) {
                $line['unit_price_gross'] = round($line['gross_amount'] / $line['quantity'], 4);
            }

            // calculate net from gross
            $vatRate = is_numeric($line['vat_rate']) ? (float)$line['vat_rate'] : 0;
            $vatMultiplier = 1 + $vatRate / 100;
            if ($this->isNullOrBlank($line['net_amount']) && !$this->isNullOrBlank($line['gross_amount']) && $vatMultiplier > 0) {
                $line['net_amount'] = round($line['gross_amount'] / $vatMultiplier, 2);
            }
            if ($this->isNullOrBlank($line['unit_price_net']) && !$this->isNullOrBlank($line['unit_price_gross']) && $vatMultiplier > 0) {
                $line['unit_price_net'] = round($line['unit_price_gross'] / $vatMultiplier, 4);
            }
            // Cross-calculate gross from net when gross is absent
            if ($this->isNullOrBlank($line['gross_amount']) && !$this->isNullOrBlank($line['net_amount'])) {
                $line['gross_amount'] = round($line['net_amount'] * $vatMultiplier, 2);
            }
            if ($this->isNullOrBlank($line['unit_price_gross']) && !$this->isNullOrBlank($line['unit_price_net'])) {
                $line['unit_price_gross'] = round($line['unit_price_net'] * $vatMultiplier, 4);
            }

            if ($hasStanPrzed && $isBefore) {
                $linesBefore[] = $line;
            } else {
                $lines[] = $line;
            }
        }

        return array('lines' => $lines, 'lines_before' => $linesBefore);
    }

    /**
     * @brief Parse advance payment order lines
     * @param $xpath DOMXPath object
     * @return array Array with 'lines' and 'lines_before'
     * @called_by parse()
     */
    private function parseZamowienieWiersz($xpath)
    {
        $lines = array();
        $linesBefore = array();

        $zamWierszNodes = $xpath->query('//fa:Fa/fa:Zamowienie/fa:ZamowienieWiersz');
        $hasStanPrzed = false;

        foreach ($zamWierszNodes as $node) {
            foreach ($node->childNodes as $child) {
                if ($child->nodeType === XML_ELEMENT_NODE && $child->localName === 'StanPrzedZ') {
                    $hasStanPrzed = true;
                    break 2;
                }
            }
        }

        foreach ($zamWierszNodes as $node) {
            $line = array(
                'line_num' => 0,
                'uuid' => null,
                'indeks' => null,
                'gtin' => null,
                'cn' => null,
                'description' => '',
                'quantity' => 0.0,
                'unit' => null,
                'unit_price_net' => null,
                'unit_price_gross' => null,
                'discount' => null,
                'net_amount' => null,
                'gross_amount' => null,
                'vat_rate' => null,
                'kurs_waluty' => null,
            );

            $isBefore = false;
            $vatAmount = null;

            foreach ($node->childNodes as $child) {
                if ($child->nodeType !== XML_ELEMENT_NODE) continue;

                switch ($child->localName) {
                    case 'NrWierszaZam':
                        $line['line_num'] = (int)$child->textContent;
                        break;
                    case 'UU_IDZ':
                        $line['uuid'] = trim($child->textContent);
                        break;
                    case 'IndeksZ':
                        $line['indeks'] = trim($child->textContent);
                        break;
                    case 'GTINZ':
                        $line['gtin'] = trim($child->textContent);
                        break;
                    case 'P_7Z':
                        $line['description'] = $child->textContent;
                        break;
                    case 'P_8BZ':
                        $line['quantity'] = (float)$child->textContent;
                        break;
                    case 'P_8AZ':
                        $line['unit'] = trim($child->textContent);
                        break;
                    case 'P_9AZ':
                        $line['unit_price_net'] = (float)$child->textContent;
                        break;
                    case 'P_11NettoZ':
                        $line['net_amount'] = (float)$child->textContent;
                        break;
                    case 'P_11VatZ':
                        $vatAmount = (float)$child->textContent;
                        break;
                    case 'P_12Z':
                        $line['vat_rate'] = trim($child->textContent);
                        break;
                    case 'CNZ':
                        $line['cn'] = trim($child->textContent);
                        break;
                    case 'StanPrzedZ':
                        if ($child->textContent === '1') {
                            $isBefore = true;
                        }
                        break;
                }
            }

            // Calculate gross from net + VAT amount
            if (!$this->isNullOrBlank($line['net_amount']) && !$this->isNullOrBlank($vatAmount)) {
                $line['gross_amount'] = round($line['net_amount'] + $vatAmount, 2);
            }

            // Calculate unit prices
            if ($this->isNullOrBlank($line['unit_price_net']) && !$this->isNullOrBlank($line['net_amount']) && !$this->isNullOrBlank($line['quantity']) && $line['quantity'] != 0) {
                $line['unit_price_net'] = round($line['net_amount'] / $line['quantity'], 4);
            }
            if ($this->isNullOrBlank($line['unit_price_gross']) && !$this->isNullOrBlank($line['gross_amount']) && !$this->isNullOrBlank($line['quantity']) && $line['quantity'] != 0) {
                $line['unit_price_gross'] = round($line['gross_amount'] / $line['quantity'], 4);
            }

            // calculate net from gross
            $vatRate = is_numeric($line['vat_rate']) ? (float)$line['vat_rate'] : 0;
            $vatMultiplier = 1 + $vatRate / 100;
            if ($this->isNullOrBlank($line['net_amount']) && !$this->isNullOrBlank($line['gross_amount']) && $vatMultiplier > 0) {
                $line['net_amount'] = round($line['gross_amount'] / $vatMultiplier, 2);
            }
            if ($this->isNullOrBlank($line['unit_price_net']) && !$this->isNullOrBlank($line['unit_price_gross']) && $vatMultiplier > 0) {
                $line['unit_price_net'] = round($line['unit_price_gross'] / $vatMultiplier, 4);
            }
            // Cross-calculate gross from net when gross is absent
            if ($this->isNullOrBlank($line['gross_amount']) && !$this->isNullOrBlank($line['net_amount'])) {
                $line['gross_amount'] = round($line['net_amount'] * $vatMultiplier, 2);
            }
            if ($this->isNullOrBlank($line['unit_price_gross']) && !$this->isNullOrBlank($line['unit_price_net'])) {
                $line['unit_price_gross'] = round($line['unit_price_net'] * $vatMultiplier, 4);
            }

            if ($hasStanPrzed && $isBefore) {
                $linesBefore[] = $line;
            } else {
                $lines[] = $line;
            }
        }

        return array('lines' => $lines, 'lines_before' => $linesBefore);
    }


    /**
     * @brief Parse payment information (Platnosc)
     * @param $xpath DOMXPath object
     * @return array Payment info
     * @called_by parse()
     * @calls getValue()
     */
    private function parsePlatnosc($xpath)
    {
        $payment = array(
            'due_date' => null,
            'due_date_description' => null,
            'method' => null,
            'bank_account' => null,
            'status' => self::PAYMENT_UNPAID,
            'payment_date' => null,
            'bank_swift' => null,
            'bank_name' => null,
            'bank_own_account' => null,
            'bank_description' => null,
            'factor_bank_accounts' => array(),
        );

        // Due date
        $termin = $this->getValue($xpath, '//fa:Fa/fa:Platnosc/fa:TerminPlatnosci/fa:Termin', null);
        if ($termin) {
            $payment['due_date'] = $termin;
        } else {
            // Check for TerminOpis (relative due date like "14 days from invoice date")
            $ilosc = $this->getValue($xpath, '//fa:Fa/fa:Platnosc/fa:TerminPlatnosci/fa:TerminOpis/fa:Ilosc', null);
            $jednostka = $this->getValue($xpath, '//fa:Fa/fa:Platnosc/fa:TerminPlatnosci/fa:TerminOpis/fa:Jednostka', null);
            $zdarzenie = $this->getValue($xpath, '//fa:Fa/fa:Platnosc/fa:TerminPlatnosci/fa:TerminOpis/fa:ZdarzeniePoczatkowe', null);

            if ($ilosc && $jednostka) {
                $payment['due_date_description'] = $ilosc . ' ' . $jednostka . ($zdarzenie ? ' ' . $zdarzenie : '');
            }

            // Fall back to Fa/TerminPlatnosci
            $terminFa = $this->getValue($xpath, '//fa:Fa/fa:TerminPlatnosci', null);
            if ($terminFa) {
                $payment['due_date'] = $terminFa;
            }
        }

        // Payment method code
        $method = $this->getValue($xpath, '//fa:Fa/fa:Platnosc/fa:FormaPlatnosci', null);
        if ($method) $payment['method'] = $method;

        // Payment status
        $zaplacono = $this->getValue($xpath, '//fa:Fa/fa:Platnosc/fa:Zaplacono');
        $czesciowa = $this->getValue($xpath, '//fa:Fa/fa:Platnosc/fa:ZnacznikZaplatyCzesciowej');
        if ($zaplacono === '1') {
            $payment['status'] = self::PAYMENT_PAID;
        } elseif ($czesciowa === '2') {
            // Fully paid in installments
            $payment['status'] = self::PAYMENT_PAID_INSTALLMENTS;
        } elseif ($czesciowa === '1') {
            $payment['status'] = self::PAYMENT_PARTIAL;
        }

        // Payment date
        $dataZaplaty = $this->getValue($xpath, '//fa:Fa/fa:Platnosc/fa:DataZaplaty', null);
        if ($dataZaplaty) $payment['payment_date'] = $dataZaplaty;

        // Partial payments
        $partialNodes = $xpath->query('//fa:Fa/fa:Platnosc/fa:ZaplataCzesciowa');
        if ($partialNodes && $partialNodes->length > 0) {
            $payment['partial_payments'] = array();
            foreach ($partialNodes as $node) {
                $partial = array();
                $kwota = $xpath->query('fa:KwotaZaplatyCzesciowej', $node);
                if ($kwota->length > 0) $partial['amount'] = $kwota->item(0)->textContent;
                $data = $xpath->query('fa:DataZaplatyCzesciowej', $node);
                if ($data->length > 0) $partial['date'] = $data->item(0)->textContent;
                $forma = $xpath->query('fa:FormaPlatnosci', $node);
                if ($forma->length > 0) {
                    $partial['method'] = $forma->item(0)->textContent;
                } else {
                    // PlatnoscInna
                    $opis = $xpath->query('fa:OpisPlatnosci', $node);
                    if ($opis->length > 0) $partial['method_description'] = $opis->item(0)->textContent;
                }
                $payment['partial_payments'][] = $partial;
            }
        }

        // Bank account - full details
        $nrRB = $this->getValue($xpath, '//fa:Fa/fa:Platnosc/fa:RachunekBankowy/fa:NrRB', null);
        if ($nrRB) $payment['bank_account'] = $nrRB;
        $swift = $this->getValue($xpath, '//fa:Fa/fa:Platnosc/fa:RachunekBankowy/fa:SWIFT', null);
        if ($swift) $payment['bank_swift'] = $swift;
        $bankName = $this->getValue($xpath, '//fa:Fa/fa:Platnosc/fa:RachunekBankowy/fa:NazwaBanku', null);
        if ($bankName) $payment['bank_name'] = $bankName;
        $ownAccount = $this->getValue($xpath, '//fa:Fa/fa:Platnosc/fa:RachunekBankowy/fa:RachunekWlasnyBanku', null);
        if ($ownAccount) $payment['bank_own_account'] = $ownAccount;
        $description = $this->getValue($xpath, '//fa:Fa/fa:Platnosc/fa:RachunekBankowy/fa:OpisRachunku', null);
        if ($description) $payment['bank_description'] = $description;

        // Factor bank accounts
        $factorNodes = $xpath->query('//fa:Fa/fa:Platnosc/fa:RachunekBankowyFaktora');
        if ($factorNodes && $factorNodes->length > 0) {
            foreach ($factorNodes as $fn) {
                $acc = array(
                    'bank_account'     => null,
                    'bank_swift'       => null,
                    'bank_name'        => null,
                    'bank_own_account' => null,
                    'bank_description' => null,
                );
                $nr = $xpath->query('fa:NrRB', $fn);
                if ($nr->length > 0) $acc['bank_account'] = $nr->item(0)->textContent;
                $sw = $xpath->query('fa:SWIFT', $fn);
                if ($sw->length > 0) $acc['bank_swift'] = $sw->item(0)->textContent;
                $bn = $xpath->query('fa:NazwaBanku', $fn);
                if ($bn->length > 0) $acc['bank_name'] = $bn->item(0)->textContent;
                $oa = $xpath->query('fa:RachunekWlasnyBanku', $fn);
                if ($oa->length > 0) $acc['bank_own_account'] = $oa->item(0)->textContent;
                $de = $xpath->query('fa:OpisRachunku', $fn);
                if ($de->length > 0) $acc['bank_description'] = $de->item(0)->textContent;
                if (!empty($acc['bank_account'])) $payment['factor_bank_accounts'][] = $acc;
            }
        }

        return $payment;
    }


    /**
     * @brief Parse correction reference (DaneFaKorygowanej)
     * @param $xpath DOMXPath object
     * @return array|null Correction data with reason, type, and corrected_invoices[], or null if not a correction
     * @called_by parse()
     * @calls getValue()
     */
    private function parseDaneFaKorygowanej($xpath)
    {
        $daneFaKor = $xpath->query('//fa:Fa/fa:DaneFaKorygowanej');
        if ($daneFaKor->length == 0) {
            // Check if correction reason/type exist
            $reason = $this->getValue($xpath, '//fa:Fa/fa:PrzyczynaKorekty', null);
            $type = $this->getValue($xpath, '//fa:Fa/fa:TypKorekty', null);
            if (empty($reason) && empty($type)) {
                return null;
            }
            return array(
                'reason' => $reason,
                'type' => $type,
                'corrected_invoices' => array(),
            );
        }

        $correction = array(
            'reason' => null,
            'type' => null,
            'corrected_invoices' => array(),
        );

        // Correction reason and type
        $reason = $this->getValue($xpath, '//fa:Fa/fa:PrzyczynaKorekty', null);
        if ($reason) $correction['reason'] = $reason;
        $type = $this->getValue($xpath, '//fa:Fa/fa:TypKorekty', null);
        if ($type) $correction['type'] = $type;

        // Multiple corrected invoices
        foreach ($daneFaKor as $node) {
            $inv = array(
                'invoice_date' => null,
                'invoice_number' => null,
                'ksef_number' => null,
            );

            foreach ($node->childNodes as $child) {
                if ($child->nodeType !== XML_ELEMENT_NODE) continue;

                switch ($child->localName) {
                    case 'DataWystFaKorygowanej':
                        $inv['invoice_date'] = trim($child->textContent);
                        break;
                    case 'NrFaKorygowanej':
                        $inv['invoice_number'] = trim($child->textContent);
                        break;
                    case 'NrKSeFFaKorygowanej':
                        $inv['ksef_number'] = trim($child->textContent);
                        break;
                }
            }

            $correction['corrected_invoices'][] = $inv;
        }

        return $correction;
    }


    /**
     * @brief Parse registries section (Stopka/Rejestry)
     * @param $xpath DOMXPath object
     * @return array Registry data
     * @called_by parse()
     * @calls getValue()
     */
    private function parseRegistries($xpath)
    {
        return array(
            'krs' => $this->getValue($xpath, '//fa:Stopka/fa:Rejestry/fa:KRS', null),
            'regon' => $this->getValue($xpath, '//fa:Stopka/fa:Rejestry/fa:REGON', null),
            'bdo' => $this->getValue($xpath, '//fa:Stopka/fa:Rejestry/fa:BDO', null),
            'pelna_nazwa' => $this->getValue($xpath, '//fa:Stopka/fa:Rejestry/fa:PelnaNazwa', null),
        );
    }


    /**
     * @brief Parse stopka (footer) information
     * @param $xpath DOMXPath object
     * @return array Array of stopka text entries
     * @called_by parse()
     */
    private function parseStopka($xpath)
    {
        $items = array();
        $nodes = $xpath->query('//fa:Stopka/fa:Informacje/fa:StopkaFaktury');
        foreach ($nodes as $node) {
            $text = trim($node->textContent);
            if ($text !== '') {
                $items[] = $text;
            }
        }
        return $items;
    }


    /**
     * @brief Build additional info array
     * @param $invoice Parsed invoice array
     * @return array Additional info strings
     * @called_by parse()
     */
    private function parseAdditionalInfo($invoice)
    {
        return array();
    }


    /**
     * @brief Parse additional description entries
     * @param $xpath DOMXPath object
     * @return array Array of key-value pairs
     * @called_by parse()
     */
    private function parseAdditionalDesc($xpath)
    {
        $items = array();
        $nodes = $xpath->query('//fa:Fa/fa:DodatkowyOpis');
        foreach ($nodes as $node) {
            $key = null;
            $value = null;
            $nrWiersza = null;
            foreach ($node->childNodes as $child) {
                if ($child->nodeType !== XML_ELEMENT_NODE) continue;
                if ($child->localName === 'NrWiersza') $nrWiersza = trim($child->textContent);
                if ($child->localName === 'Klucz') $key = trim($child->textContent);
                if ($child->localName === 'Wartosc') $value = trim($child->textContent);
            }
            if ($key && $value) {
                $item = array('key' => $key, 'value' => $value);
                if ($nrWiersza !== null && $nrWiersza !== '') {
                    $item['nr_wiersza'] = (int) $nrWiersza;
                }
                $items[] = $item;
            }
        }
        return $items;
    }


    /**
     * @brief Parse WarunkiTransakcji section
     * @param $xpath DOMXPath object
     * @return array Structured transaction conditions
     * @called_by parse()
     * @calls parseTransport()
     */
    private function parseWarunkiTransakcji($xpath)
    {
        $result = array(
            'umowy' => array(),
            'zamowienia' => array(),
            'partie' => array(),
            'waluta_umowna' => null,
            'kurs_umowny' => null,
            'warunki_dostawy' => null,
            'podmiot_posredniczacy' => false,
            'transport' => array(),
        );

        $base = '//fa:Fa/fa:WarunkiTransakcji';
        if ($xpath->query($base)->length === 0) {
            return $result;
        }

        // Umowy
        foreach ($xpath->query($base . '/fa:Umowy') as $node) {
            $nr = $this->getValueRel($xpath, 'fa:NrUmowy', $node);
            $data = $this->getValueRel($xpath, 'fa:DataUmowy', $node);
            if ($nr !== null || $data !== null) {
                $result['umowy'][] = array('nr' => $nr, 'data' => $data);
            }
        }

        // Zamowienia
        foreach ($xpath->query($base . '/fa:Zamowienia') as $node) {
            $nr = $this->getValueRel($xpath, 'fa:NrZamowienia', $node);
            $data = $this->getValueRel($xpath, 'fa:DataZamowienia', $node);
            if ($nr !== null || $data !== null) {
                $result['zamowienia'][] = array('nr' => $nr, 'data' => $data);
            }
        }

        // NrPartiiTowaru
        foreach ($xpath->query($base . '/fa:NrPartiiTowaru') as $node) {
            $v = trim($node->textContent);
            if ($v !== '') $result['partie'][] = $v;
        }

        $result['waluta_umowna'] = $this->getValue($xpath, $base . '/fa:WalutaUmowna', null);
        $result['kurs_umowny'] = $this->getValue($xpath, $base . '/fa:KursUmowny', null);
        $result['warunki_dostawy'] = $this->getValue($xpath, $base . '/fa:WarunkiDostawy', null);
        $result['podmiot_posredniczacy'] = ($this->getValue($xpath, $base . '/fa:PodmiotPosredniczacy', '') === '1');

        // Transport (may repeat)
        foreach ($xpath->query($base . '/fa:Transport') as $node) {
            $result['transport'][] = $this->parseTransport($xpath, $node);
        }

        return $result;
    }


    /**
     * @brief Parse Transport node
     * @param $xpath DOMXPath object
     * @param $node Transport DOM element
     * @return array Structured transport data
     * @called_by parseWarunkiTransakcji()
     * @calls parseAdresNode()
     */
    private function parseTransport($xpath, $node)
    {
        $t = array(
            'rodzaj' => $this->getValueRel($xpath, 'fa:RodzajTransportu', $node),
            'transport_inny' => $this->getValueRel($xpath, 'fa:TransportInny', $node),
            'opis_innego_transportu' => $this->getValueRel($xpath, 'fa:OpisInnegoTransportu', $node),
            'nr_zlecenia' => $this->getValueRel($xpath, 'fa:NrZleceniaTransportu', $node),
            'opis_ladunku' => $this->getValueRel($xpath, 'fa:OpisLadunku', $node),
            'ladunek_inny' => $this->getValueRel($xpath, 'fa:LadunekInny', $node),
            'opis_innego_ladunku' => $this->getValueRel($xpath, 'fa:OpisInnegoLadunku', $node),
            'jednostka_opakowania' => $this->getValueRel($xpath, 'fa:JednostkaOpakowania', $node),
            'data_rozp' => $this->getValueRel($xpath, 'fa:DataGodzRozpTransportu', $node),
            'data_zak' => $this->getValueRel($xpath, 'fa:DataGodzZakTransportu', $node),
            'przewoznik' => null,
            'wysylka_z' => null,
            'wysylka_do' => null,
            'wysylka_przez' => array(),
        );

        $prz = $xpath->query('fa:Przewoznik', $node);
        if ($prz->length > 0) {
            $pn = $prz->item(0);
            $t['przewoznik'] = array(
                'nip' => $this->getValueRel($xpath, 'fa:DaneIdentyfikacyjne/fa:NIP', $pn),
                'kod_ue' => $this->getValueRel($xpath, 'fa:DaneIdentyfikacyjne/fa:KodUE', $pn),
                'nr_vat_ue' => $this->getValueRel($xpath, 'fa:DaneIdentyfikacyjne/fa:NrVatUE', $pn),
                'kod_kraju' => $this->getValueRel($xpath, 'fa:DaneIdentyfikacyjne/fa:KodKraju', $pn),
                'nr_id' => $this->getValueRel($xpath, 'fa:DaneIdentyfikacyjne/fa:NrID', $pn),
                'brak_id' => $this->getValueRel($xpath, 'fa:DaneIdentyfikacyjne/fa:BrakID', $pn),
                'nazwa' => $this->getValueRel($xpath, 'fa:DaneIdentyfikacyjne/fa:Nazwa', $pn),
                'adres' => $this->parseAdresNode($xpath, $xpath->query('fa:AdresPrzewoznika', $pn)->item(0)),
            );
        }

        $t['wysylka_z'] = $this->parseAdresNode($xpath, $xpath->query('fa:WysylkaZ', $node)->item(0));
        $t['wysylka_do'] = $this->parseAdresNode($xpath, $xpath->query('fa:WysylkaDo', $node)->item(0));
        foreach ($xpath->query('fa:WysylkaPrzez', $node) as $wn) {
            $adr = $this->parseAdresNode($xpath, $wn);
            if ($adr !== null) $t['wysylka_przez'][] = $adr;
        }

        return $t;
    }


    /**
     * @brief Parse address node
     * @param $xpath DOMXPath object
     * @param $node Address DOM element or null
     * @return array|null Address data or null if empty/absent
     * @called_by parseTransport()
     */
    private function parseAdresNode($xpath, $node)
    {
        if ($node === null) return null;
        $adr = array(
            'kod_kraju' => $this->getValueRel($xpath, 'fa:KodKraju', $node),
            'l1' => $this->getValueRel($xpath, 'fa:AdresL1', $node),
            'l2' => $this->getValueRel($xpath, 'fa:AdresL2', $node),
            'gln' => $this->getValueRel($xpath, 'fa:GLN', $node),
        );
        if ($adr['l1'] === null && $adr['l2'] === null && $adr['gln'] === null && $adr['kod_kraju'] === null) {
            return null;
        }
        return $adr;
    }


    /**
     * @brief Get text value from XPath
     * @param $xpath DOMXPath object
     * @param $query XPath query string (relative to $node)
     * @param $node Context DOM element
     * @param $default Default value if not found
     * @return string|null Text value or default
     * @called_by parseWarunkiTransakcji(), parseTransport(), parseAdresNode()
     */
    private function getValueRel($xpath, $query, $node, $default = null)
    {
        $nodes = $xpath->query($query, $node);
        if ($nodes->length > 0) {
            $v = trim($nodes->item(0)->textContent);
            return $v !== '' ? $v : $default;
        }
        return $default;
    }


    /**
     * @brief Parse exchange rate data for multicurrency invoices
     * @param $xpath DOMXPath object
     * @param $lines Parsed after-state lines
     * @return array Exchange rate info
     * @called_by parse()
     */
    private function parseExchangeRate($xpath, $lines)
    {
        $data = array(
            'rate' => null,
        );

        // Get exchange rate from first line item
        if (!empty($lines)) {
            foreach ($lines as $line) {
                if (!empty($line['kurs_waluty'])) {
                    $data['rate'] = $line['kurs_waluty'];
                    break;
                }
            }
        }

        return $data;
    }


    /**
     * @brief Get text value from XPath query
     * @param $xpath DOMXPath object
     * @param $query XPath query string
     * @param $default Default value if not found
     * @return string Text value
     * @called_by parseNaglowek(), parsePodmiot1(), parsePodmiot2(), parseFa(), parsePlatnosc(), parseDaneFaKorygowanej()
     */
    private function getValue($xpath, $query, $default = '')
    {
        $nodes = $xpath->query($query);
        if ($nodes->length > 0) {
            return trim($nodes->item(0)->textContent);
        }
        return $default;
    }


    /**
     * @brief Get decimal value from XPath query
     * @param $xpath DOMXPath object
     * @param $query XPath query string
     * @param $default Default value if not found
     * @return float Decimal value
     * @called_by parseFa(), parseVatSummary()
     */
    private function getDecimal($xpath, $query, $default = 0.0)
    {
        $value = $this->getValue($xpath, $query, null);
        if ($value === null || $value === '') {
            return $default;
        }
        return (float)$value;
    }


    /**
     * @brief Check if null or blank
     * @param mixed $val Value to check
     * @return bool True if null or empty string
     */
    private function isNullOrBlank($val)
    {
        return $val === null || $val === '';
    }


    /**
     * @brief Get payment method description
     * @param $code Payment method code
     * @return string Description
     */
    public function getPaymentMethodDescription($code)
    {
        $methods = array(
            '1' => 'Gotówka',           // Cash
            '2' => 'Karta',             // Card
            '3' => 'Bon',               // Voucher
            '4' => 'Czek',              // Check
            '5' => 'Kredyt',            // Credit
            '6' => 'Przelew',           // Bank transfer
            '7' => 'Mobilna',           // Mobile payment
        );
        return isset($methods[$code]) ? $methods[$code] : $code;
    }


    /**
     * @brief Get invoice type description
     * @param $type Invoice type code
     * @return string Description
     */
    public function getInvoiceTypeDescription($type)
    {
        $types = array(
            'VAT' => 'Faktura VAT',
            'KOR' => 'Faktura korygująca',
            'ZAL' => 'Faktura zaliczkowa',
            'ROZ' => 'Faktura rozliczeniowa',
            'UPR' => 'Faktura uproszczona',
            'KOR_ZAL' => 'Korekta faktury zaliczkowej',
            'KOR_ROZ' => 'Korekta faktury rozliczeniowej',
        );
        return isset($types[$type]) ? $types[$type] : $type;
    }
}