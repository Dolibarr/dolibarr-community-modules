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

require_once DOL_DOCUMENT_ROOT . '/core/lib/pdf.lib.php';

/**
 * Class to generate PDF visualization of incoming KSeF invoices
 * Layout attempts to mimic the KSeF portal visualizations
 */
class KsefInvoicePdf
{
    public $db;
    public $error = '';
    public $errors = array();
    private $pageWidth = 210;
    private $pageHeight = 297;
    private $marginLeft = 10;
    private $marginRight = 10;
    private $marginTop = 10;
    private $marginBottom = 15;
    private $contentWidth;

    private $pdf;
    private $parsed = null;

    // Colors
    private $colorText = array(52, 58, 64);
    private $colorLine = array(186, 186, 186);
    private $colorHeaderBg = array(246, 247, 250);
    private $colorBorder = array(186, 186, 186);
    private $colorLink = array(0, 0, 255);
    private $borderWidth = 0.3;

    // Font
    private $fontFamily = 'freesans';
    private $fontSizeBase = 7;
    private $fontSizeTable = 7;
    private $fontSizeSmall = 7;
    private $fontSizeNormal = 7;
    private $fontSizeLabel = 7;
    private $fontSizeSection = 10;
    private $fontSizeSubsection = 7;
    private $fontSizeInvoiceNum = 16;
    private $fontSizeTitle = 18;

    // Spacing
    private $spaceSectionAfter = 3;
    private $spaceLineAfter = 4;
    private $spaceLineBefore = 4;
    private $spaceTableAfter = 4;
    private $spaceParagraph = 2;
    private $lineHeight = 1.2;

    private $countryNames = array(
        'AD' => 'Andora', 'AE' => 'Zjednoczone Emiraty Arabskie', 'AF' => 'Afganistan', 'AG' => 'Antigua i Barbuda',
        'AI' => 'Anguilla', 'AL' => 'Albania', 'AM' => 'Armenia', 'AN' => 'Antyle Holenderskie',
        'AO' => 'Angola', 'AQ' => 'Antarktyda', 'AR' => 'Argentyna', 'AS' => 'Samoa Amerykańskie',
        'AT' => 'Austria', 'AU' => 'Australia', 'AW' => 'Aruba', 'AX' => 'Aland Islands',
        'AZ' => 'Azerbejdżan', 'BA' => 'Bośnia i Hercegowina', 'BB' => 'Barbados', 'BD' => 'Bangladesz',
        'BE' => 'Belgia', 'BF' => 'Burkina Faso', 'BG' => 'Bułgaria', 'BH' => 'Bahrajn',
        'BI' => 'Burundi', 'BJ' => 'Benin', 'BL' => 'Saint Barthelemy', 'BM' => 'Bermudy',
        'BN' => 'Brunei Darussalam', 'BO' => 'Boliwia', 'BQ' => 'Bonaire, Sint Eustatius i Saba', 'BR' => 'Brazylia',
        'BS' => 'Bahamy', 'BT' => 'Bhutan', 'BV' => 'Wyspa Bouveta', 'BW' => 'Botswana',
        'BY' => 'Białoruś', 'BZ' => 'Belize', 'CA' => 'Kanada', 'CC' => 'Wyspy Kokosowe (Keelinga)',
        'CD' => 'Kongo, Republika Demokratyczna', 'CF' => 'Rep.środkowoafrykańska', 'CG' => 'Kongo', 'CH' => 'Szwajcaria',
        'CI' => 'Wybrzeże Kości Słoniowej', 'CK' => 'Wyspy Cooka', 'CL' => 'Chile', 'CM' => 'Kamerun',
        'CN' => 'Chiny', 'CO' => 'Kolumbia', 'CR' => 'Kostaryka', 'CU' => 'Kuba',
        'CV' => 'Zielony Przylądek', 'CW' => 'Curaçao', 'CX' => 'Wyspa Bożego Narodzenia', 'CY' => 'Cypr',
        'CZ' => 'Republika Czeska', 'DE' => 'Niemcy', 'DJ' => 'Dżibuti', 'DK' => 'Dania',
        'DM' => 'Dominika', 'DO' => 'Dominikana', 'DZ' => 'Algieria', 'EC' => 'Ekwador',
        'EE' => 'Estonia', 'EG' => 'Egipt', 'EH' => 'Sahara Zachodnia', 'ER' => 'Erytrea',
        'ES' => 'Hiszpania', 'ET' => 'Etiopia', 'FI' => 'Finlandia', 'FJ' => 'Fidżi Republika',
        'FK' => 'Falklandy', 'FM' => 'Mikronezja', 'FO' => 'Wyspy Owcze', 'FR' => 'Francja',
        'GA' => 'Gabon', 'GB' => 'Wielka Brytania', 'GD' => 'Grenada', 'GE' => 'Gruzja',
        'GF' => 'Gujana Francuska', 'GG' => 'Guernsey', 'GH' => 'Ghana', 'GI' => 'Gibraltar',
        'GL' => 'Grenlandia', 'GM' => 'Gambia', 'GN' => 'Gwinea', 'GP' => 'Gwadelupa',
        'GQ' => 'Gwinea Równikowa', 'GR' => 'Grecja', 'GS' => 'Południowa Georgia i Połud.Wyspy Sandwich', 'GT' => 'Gwatemala',
        'GU' => 'Guam', 'GW' => 'Gwinea Bissau', 'GY' => 'Gujana', 'HK' => 'Hongkong',
        'HM' => 'Wyspy Heard i Mcdonald', 'HN' => 'Honduras', 'HR' => 'Chorwacja', 'HT' => 'Haiti',
        'HU' => 'Węgry', 'ID' => 'Indonezja', 'IE' => 'Irlandia', 'IL' => 'Izrael',
        'IM' => 'Wyspa Man', 'IN' => 'Indie', 'IO' => 'Brytyjskie Terytorium Oceanu Indyjskiego', 'IQ' => 'Irak',
        'IR' => 'Iran', 'IS' => 'Islandia', 'IT' => 'Włochy', 'JE' => 'Jersey',
        'JM' => 'Jamajka', 'JO' => 'Jordania', 'JP' => 'Japonia', 'KE' => 'Kenia',
        'KG' => 'Kirgistan', 'KH' => 'Kambodża', 'KI' => 'Kiribati', 'KM' => 'Komory',
        'KN' => 'Saint Kitts i Nevis', 'KP' => 'Koreańska Republika Ludowo-Demokratyczna', 'KR' => 'Republika Korei', 'KW' => 'Kuwejt',
        'KY' => 'Kajmany', 'KZ' => 'Kazachstan', 'LA' => 'Laos', 'LB' => 'Liban',
        'LC' => 'Saint Lucia', 'LI' => 'Liechtenstein', 'LK' => 'Sri Lanka', 'LR' => 'Liberia',
        'LS' => 'Lesotho', 'LT' => 'Litwa', 'LU' => 'Luksemburg', 'LV' => 'Łotwa',
        'LY' => 'Libia', 'MA' => 'Maroko', 'MC' => 'Monako', 'MD' => 'Mołdowa',
        'ME' => 'Czarnogóra', 'MF' => 'Saint Martin', 'MG' => 'Madagaskar', 'MH' => 'Wyspy Marshalla',
        'MK' => 'Macedonia', 'ML' => 'Mali', 'MM' => 'Myanmar (Burma)', 'MN' => 'Mongolia',
        'MO' => 'Makau', 'MP' => 'Mariany Północne', 'MQ' => 'Martynika', 'MR' => 'Mauretania',
        'MS' => 'Montserrat', 'MT' => 'Malta', 'MU' => 'Mauritius', 'MV' => 'Malediwy',
        'MW' => 'Malawi', 'MX' => 'Meksyk', 'MY' => 'Malezja', 'MZ' => 'Mozambik',
        'NA' => 'Namibia', 'NC' => 'Nowa Kaledonia', 'NE' => 'Niger', 'NF' => 'Norfolk',
        'NG' => 'Nigeria', 'NI' => 'Nikaragua', 'NL' => 'Niderlandy (Holandia)', 'NO' => 'Norwegia',
        'NP' => 'Nepal', 'NR' => 'Nauru', 'NU' => 'Niue', 'NZ' => 'Nowa Zelandia',
        'OM' => 'Oman', 'PA' => 'Panama', 'PE' => 'Peru', 'PF' => 'Polinezja Francuska',
        'PG' => 'Papua Nowa Gwinea', 'PH' => 'Filipiny', 'PK' => 'Pakistan', 'PL' => 'Polska',
        'PM' => 'Saint Pierre i Miquelon', 'PN' => 'Pitcairn', 'PR' => 'Portoryko', 'PS' => 'Okupowane Terytorium Palestyny',
        'PT' => 'Portugalia', 'PW' => 'Palau', 'PY' => 'Paragwaj', 'QA' => 'Katar',
        'RE' => 'Reunion', 'RO' => 'Rumunia', 'RS' => 'Serbia', 'RU' => 'Rosja',
        'RW' => 'Rwanda', 'SA' => 'Arabia Saudyjska', 'SB' => 'Wyspy Salomona', 'SC' => 'Seszele',
        'SD' => 'Sudan', 'SE' => 'Szwecja', 'SG' => 'Singapur', 'SH' => 'Święta Helena',
        'SI' => 'Słowenia', 'SJ' => 'Svalbard i Jan Mayen', 'SK' => 'Słowacja', 'SL' => 'Sierra Leone',
        'SM' => 'San Marino', 'SN' => 'Senegal', 'SO' => 'Somalia', 'SR' => 'Surinam',
        'SS' => 'Sudan Południowy', 'ST' => 'Wyspy Świętego Tomasza i Książęca', 'SV' => 'Salwador', 'SX' => 'Wyspa Sint Maarten (część holenderska wyspy)',
        'SY' => 'Syria', 'SZ' => 'Suazi', 'TC' => 'Wyspy Turks i Caicos', 'TD' => 'Czad',
        'TF' => 'Francuskie Terytorium Południowe', 'TG' => 'Togo', 'TH' => 'Tajlandia', 'TJ' => 'Tadżykistan',
        'TK' => 'Tokelau', 'TL' => 'Wschodni Timor', 'TM' => 'Turkmenistan', 'TN' => 'Tunezja',
        'TO' => 'Tonga', 'TR' => 'Turcja', 'TT' => 'Trynidad i Tobago', 'TV' => 'Tuvalu',
        'TW' => 'Tajwan', 'TZ' => 'Tanzania', 'UA' => 'Ukraina', 'UG' => 'Uganda',
        'UM' => 'Minor', 'US' => 'Stany Zjednoczone Ameryki', 'UY' => 'Urugwaj', 'UZ' => 'Uzbekistan',
        'VA' => 'Watykan', 'VC' => 'Saint Vincent i Grenadyny', 'VE' => 'Wenezuela', 'VG' => 'Wyspy Dziewicze-W.B.',
        'VI' => 'Wyspy Dziewicze-USA', 'VN' => 'Wietnam', 'VU' => 'Vanuatu', 'WF' => 'Wallis i Futuna',
        'WS' => 'Samoa', 'XC' => 'Ceuta', 'XI' => 'Zjednoczone Królestwo (Irlandia Północna)', 'XK' => 'Kosowo',
        'XL' => 'Melilla', 'YE' => 'Jemen', 'YT' => 'Majotta', 'ZA' => 'Republika Południowej Afryki',
        'ZM' => 'Zambia', 'ZW' => 'Zimbabwe',
    );

    public function __construct($db)
    {
        $this->db = $db;
        $this->contentWidth = $this->pageWidth - $this->marginLeft - $this->marginRight;
    }

    /**
     * @brief Generate PDF visualization
     * @param $incoming KsefIncoming object
     * @param $outputPath Optional path to save PDF
     * @return string|bool PDF content as string, true if saved to file, false on error
     * @called_by KSEF::getIncomingInvoicePdf(), incoming_card.php
     * @calls renderHeader(), renderCorrectionData(), renderSellerBuyer(), renderDetails(),
     * renderPositionsTable(), renderGtinTable(), renderTotalAmount(), renderVatSummary(),
     * renderAdditionalInfo(), renderAdditionalDesc(), renderPayment(), renderRegistries(),
     * renderQrCode(), renderFooter()
     */
    public function generate($incoming, $outputPath = '', $previewMode = false)
    {
        global $conf, $langs;

        if (empty($incoming->rowid) && !$previewMode) {
            $this->error = "Invoice object not loaded";
            return false;
        }

        pdf_getInstance();
        dol_include_once('/ksef/class/fa3_parser.class.php');

        try {
            $parser = new FA3Parser($this->db);
            $fa3Xml = isset($incoming->fa3_xml) ? $incoming->fa3_xml : null;
            $this->parsed = !empty($fa3Xml) ? $parser->parse($fa3Xml) : false;
            if (!$this->parsed) {
                $this->parsed = $this->getEmptyParsed();
            }

            $this->pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
            $this->pdf->SetCreator('KSeF Module for Dolibarr');
            $this->pdf->SetAuthor($incoming->seller_name ?? '');
            $this->pdf->SetTitle('Faktura ' . ($incoming->invoice_number ?? ''));
            $this->pdf->SetSubject('KSeF Invoice');
            $this->pdf->setPrintHeader(false);
            $this->pdf->setPrintFooter(false);
            $this->pdf->SetMargins($this->marginLeft, $this->marginTop, $this->marginRight);
            $this->pdf->SetAutoPageBreak(false);
            $this->pdf->SetFont($this->fontFamily, '', $this->fontSizeNormal);
            $this->pdf->SetTextColorArray($this->colorText);
            $this->pdf->setCellHeightRatio($this->lineHeight);
            $this->pdf->AddPage();

            $y = $this->marginTop;

            // HEADER
            $y = $this->renderHeader($incoming, $y);

            // CORRECTION INVOICE DATA
            $correction = $this->parsed['correction'];
            if (!empty($correction['reason']) || !empty($correction['corrected_invoices'])) {
                $y = $this->renderCorrectionData($y);
            }

            // SELLER / BUYER
            $y = $this->renderSellerBuyer($incoming, $y);

            // DETAILS
            $y = $this->renderDetails($incoming, $y);

            // POSITIONS TABLE
            $y = $this->renderPositionsTable($incoming, $y);

            // GTIN/INDEKS TABLE
            $y = $this->renderGtinTable($y);

            // TOTAL AMOUNT
            $y = $this->renderTotalAmount($incoming, $y);

            // VAT SUMMARY
            $y = $this->renderVatSummary($incoming, $y);

            // ADNOTACJE
            $y = $this->renderAdnotacje($y);

            // ADDITIONAL INFO/DESC
            $addInfo = $this->parsed['additional_info'] ?? array();
            $addPrefix = array();
            if (!empty($this->parsed['invoice']['tp_flag'])) {
                $addPrefix[] = '- Istniejące powiązania między nabywcą a dokonującym dostawy towarów lub usługodawcą';
            }
            if (!empty($this->parsed['invoice']['fp_flag'])) {
                $addPrefix[] = '- Faktura, o której mowa w art. 109 ust. 3d ustawy';
            }
            $addInfo = array_merge($addPrefix, $addInfo);

            $hasAddDesc = !empty($this->parsed['additional_desc']);
            if (!empty($addInfo) || $hasAddDesc) {
                $y = $this->renderAdditionalInfo($addInfo, $y);
                if ($hasAddDesc) {
                    $y = $this->renderAdditionalDesc($y);
                }
            }

            // PAYMENT
            $y = $this->renderPayment($incoming, $y);

            // TRANSACTION CONDITIONS
            if ($this->hasWarunkiTransakcji()) {
                $y = $this->renderWarunkiTransakcji($y);
            }

            // REGISTRIES
            $reg = $this->parsed['registries'];
            if (!empty($reg['krs']) || !empty($reg['regon']) || !empty($reg['bdo'])) {
                $y = $this->renderRegistries($y);
            }

            // STOPKA INFO
            if (!empty($this->parsed['stopka'])) {
                $y = $this->renderStopkaInfo($y);
            }

            // QR CODE
            if (!$previewMode) {
                $y = $this->renderQrCode($incoming, $y);
            }

            // FOOTER
            $totalPages = $this->pdf->getNumPages();
            for ($p = 1; $p <= $totalPages; $p++) {
                $this->pdf->setPage($p);
                $this->renderFooter($p, $totalPages);
            }

            // Output
            if (!empty($outputPath)) {
                $dir = dirname($outputPath);
                if (!is_dir($dir)) {
                    $mkResult = dol_mkdir($dir);
                    if ($mkResult < 0) {
                        $this->error = "Cannot create directory: $dir";
                        dol_syslog("KsefInvoicePdf::generate - dol_mkdir failed for $dir", LOG_ERR);
                        return false;
                    }
                }
                $this->pdf->Output($outputPath, 'F');
                return true;
            } else {
                return $this->pdf->Output('', 'S');
            }

        } catch (Exception $e) {
            $this->error = $e->getMessage();
            dol_syslog("KsefInvoicePdf::generate ERROR: " . $e->getMessage(), LOG_ERR);
            return false;
        }
    }


    /**
     * @brief Check if we need a new page, add one if needed
     * @param $y Current Y position
     * @param $neededHeight Height needed for next content
     * @return float New Y position
     * @called_by render methods
     */
    private function checkPageBreak($y, $neededHeight = 10)
    {
        if ($y + $neededHeight > $this->pageHeight - $this->marginBottom) {
            $this->pdf->AddPage();
            return $this->marginTop;
        }
        return $y;
    }


    /**
     * @brief Draw separator line
     * @param $y Y position for line
     * @return float New Y position after line and spacing
     * @called_by render methods
     */
    private function drawLine($y)
    {
        $this->pdf->SetDrawColorArray($this->colorLine);
        $this->pdf->SetLineWidth(0.3);
        $this->pdf->Line($this->marginLeft, $y, $this->marginLeft + $this->contentWidth, $y);
        return $y + $this->spaceLineAfter;
    }


    /**
     * @brief Render invoice header section with company logo
     * @param $incoming KsefIncoming object
     * @param $y Current Y position
     * @return float New Y position
     * @called_by generate()
     */
    private function renderHeader($incoming, $y)
    {
        global $conf, $mysoc;

        $pdf = $this->pdf;
        $logoHeight = 0;
        $startY = $y;

        // Company logo when seller NIP == our nip from KSeF settings
        $ourNip = !empty($conf->global->KSEF_COMPANY_NIP) ? preg_replace('/[^0-9]/', '', $conf->global->KSEF_COMPANY_NIP) : '';
        $sellerNip = !empty($incoming->seller_nip) ? preg_replace('/[^0-9]/', '', $incoming->seller_nip) : '';

        $isOutgoing = (!empty($ourNip) && !empty($sellerNip) && $ourNip === $sellerNip);

        if ($isOutgoing && !empty($mysoc->logo)) {
            $logoFile = $conf->mycompany->dir_output . '/logos/' . $mysoc->logo;
            if (is_readable($logoFile)) {
                $logoHeight = pdf_getHeightForLogo($logoFile);
                $pdf->Image($logoFile, $this->marginLeft, $y, 0, $logoHeight); // width=0 (auto)
            }
        }

        $pdf->SetFont($this->fontFamily, '', $this->fontSizeSmall);
        $pdf->SetTextColorArray($this->colorText);
        $pdf->SetXY($this->marginLeft, $y);
        $pdf->Cell($this->contentWidth, 5, 'Numer Faktury:', 0, 1, 'R');
        $y += 5;

        $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeInvoiceNum);
        $pdf->SetXY($this->marginLeft, $y);
        $pdf->Cell($this->contentWidth, 6, $incoming->invoice_number, 0, 1, 'R');
        $y += 6;

        $pdf->SetFont($this->fontFamily, '', $this->fontSizeSmall);
        $pdf->SetXY($this->marginLeft, $y);
        $pdf->Cell($this->contentWidth, 4, $this->getInvoiceTypeLabel($incoming->invoice_type), 0, 1, 'R');
        $y += 4;

        if (!empty($incoming->ksef_number) &&
            strpos($incoming->ksef_number, 'OFFLINE') === false &&
            strpos($incoming->ksef_number, 'PENDING') === false &&
            strpos($incoming->ksef_number, 'ERROR') === false
        ) {
            $pdf->SetXY($this->marginLeft, $y);
            $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSmall);
            $label = 'Numer KSEF:';
            $pdf->Cell($this->contentWidth - $pdf->GetStringWidth($incoming->ksef_number) - 2, 4, $label, 0, 0, 'R');
            $pdf->SetFont($this->fontFamily, '', $this->fontSizeSmall);
            $pdf->Cell(0, 4, $incoming->ksef_number, 0, 1, 'R');
            $y += 4;
        }

        $y = max($y, $startY + $logoHeight) + $this->spaceSectionAfter;

        return $y;
    }


    /**
     * @brief Render correction invoice data section (Dane faktury korygowanej)
     * @param $correctionData Correction data array
     * @param $y Current Y position
     * @return float New Y position
     * @called_by generate()
     * @calls checkPageBreak(), drawLine(), renderLabelValueAt()
     */
    private function renderCorrectionData($y)
    {
        $pdf = $this->pdf;
        $correction = $this->parsed['correction'];
        $y = $this->checkPageBreak($y, 40);
        $y = $this->drawLine($y);

        $hasLeftContent = !empty($correction['reason']) || !empty($correction['type']);

        if ($hasLeftContent) {
            // Two-column layout
            $colWidth = ($this->contentWidth - 5) / 2;
            $leftX = $this->marginLeft;
            $rightX = $this->marginLeft + $colWidth + 5;
        } else {
            // Single-column layout
            $colWidth = $this->contentWidth;
            $leftX = $this->marginLeft;
            $rightX = $this->marginLeft;
        }
        $startY = $y;

        $leftY = $startY;

        if ($hasLeftContent) {
            // LEFT COLUMN - Section header
            $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSection);
            $pdf->SetXY($leftX, $leftY);
            $pdf->Cell($colWidth, 5, 'Dane faktury korygowanej', 0, 1, 'L');
            $leftY += 5 + $this->spaceSectionAfter;

            $pdf->SetFont($this->fontFamily, '', $this->fontSizeSmall);

            // Correction reason
            if (!empty($correction['reason'])) {
                $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSmall);
                $label = 'Przyczyna korekty dla faktur korygujących: ';
                $labelWidth = $pdf->GetStringWidth($label);
                $pdf->SetFont($this->fontFamily, '', $this->fontSizeSmall);
                $valueWidth = $pdf->GetStringWidth($correction['reason']);

                $pdf->SetXY($leftX, $leftY);
                if ($labelWidth + $valueWidth < $colWidth - 2) {
                    $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSmall);
                    $pdf->Cell($labelWidth, 4, $label, 0, 0, 'L');
                    $pdf->SetFont($this->fontFamily, '', $this->fontSizeSmall);
                    $pdf->Cell($valueWidth, 4, $correction['reason'], 0, 1, 'L');
                    $leftY += 4 + $this->spaceParagraph;
                } else {
                    $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSmall);
                    $pdf->MultiCell($colWidth, 4, $label, 0, 'L');
                    $leftY = $pdf->GetY();
                    $pdf->SetXY($leftX, $leftY);
                    $pdf->SetFont($this->fontFamily, '', $this->fontSizeSmall);
                    $pdf->MultiCell($colWidth, 4, $correction['reason'], 0, 'L');
                    $leftY = $pdf->GetY() + $this->spaceParagraph;
                }
            }

            // Correction type (if exists)
            if (!empty($correction['type'])) {
                $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSmall);
                $label = 'Typ skutku korekty: ';
                $labelWidth = $pdf->GetStringWidth($label);
                $typeLabel = $this->getCorrectionTypeLabel($correction['type']);
                $pdf->SetFont($this->fontFamily, '', $this->fontSizeSmall);
                $valueWidth = $pdf->GetStringWidth($typeLabel);

                $pdf->SetXY($leftX, $leftY);
                if ($labelWidth + $valueWidth < $colWidth - 2) {
                    $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSmall);
                    $pdf->Cell($labelWidth, 4, $label, 0, 0, 'L');
                    $pdf->SetFont($this->fontFamily, '', $this->fontSizeSmall);
                    $pdf->Cell($valueWidth, 4, $typeLabel, 0, 1, 'L');
                    $leftY += 4 + $this->spaceParagraph;
                } else {
                    $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSmall);
                    $pdf->MultiCell($colWidth, 4, $label, 0, 'L');
                    $leftY = $pdf->GetY();
                    $pdf->SetXY($leftX, $leftY);
                    $pdf->SetFont($this->fontFamily, '', $this->fontSizeSmall);
                    $pdf->MultiCell($colWidth, 4, $typeLabel, 0, 'L');
                    $leftY = $pdf->GetY() + $this->spaceParagraph;
                }
            }
        }

        // Corrected invoice details
        $rightY = $startY;
        $correctedInvoices = $correction['corrected_invoices'] ?? array();
        if (!empty($correctedInvoices)) {
            $invoiceCount = count($correctedInvoices);
            $detailsX = $hasLeftContent ? $rightX : $this->marginLeft;
            $detailsWidth = $hasLeftContent ? $colWidth : $this->contentWidth;

            foreach ($correctedInvoices as $idx => $inv) {
                $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSection);
                $pdf->SetXY($detailsX, $rightY);
                if ($invoiceCount > 1) {
                    $pdf->Cell($detailsWidth, 5, 'Dane identyfikacyjne faktury korygowanej ' . ($idx + 1), 0, 1, 'L');
                } else {
                    $pdf->Cell($detailsWidth, 5, 'Dane identyfikacyjne faktury korygowanej', 0, 1, 'L');
                }
                $rightY += 5 + $this->spaceSectionAfter;

                $pdf->SetFont($this->fontFamily, '', $this->fontSizeSmall);

                if (!empty($inv['invoice_date'])) {
                    $dateStr = $this->formatDatePolish($inv['invoice_date']);
                    $rightY = $this->renderLabelValueAt($detailsX, $rightY, $detailsWidth, 'Data wystawienia faktury, której dotyczy faktura korygująca: ', $dateStr);
                }

                if (!empty($inv['invoice_number'])) {
                    $rightY = $this->renderLabelValueAt($detailsX, $rightY, $detailsWidth, 'Numer faktury korygowanej: ', $inv['invoice_number']);
                }

                if (!empty($inv['ksef_number'])) {
                    $rightY = $this->renderLabelValueAt($detailsX, $rightY, $detailsWidth, 'Numer KSeF faktury korygowanej: ', $inv['ksef_number']);
                }

                $rightY += 2;
            }
        }

        $y = max($leftY, $rightY) + 2;
        return $y;
    }


    /**
     * @brief render "Label: Value" at specific X position
     * @param $x X position
     * @param $y Y position
     * @param $colWidth Column width constraint
     * @param $label Label text (bold)
     * @param $value Value text
     * @return float New Y position
     * @called_by renderCorrectionData()
     */
    private function renderLabelValueAt($x, $y, $colWidth, $label, $value)
    {
        $pdf = $this->pdf;
        $pdf->SetXY($x, $y);
        $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSmall);
        $labelWidth = $pdf->GetStringWidth($label);

        $pdf->SetFont($this->fontFamily, '', $this->fontSizeSmall);
        $valueWidth = $pdf->GetStringWidth($value);

        // Check if label + value fits on one line
        if ($labelWidth + $valueWidth < $colWidth - 2) {
            $pdf->SetXY($x, $y);
            $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSmall);
            $pdf->Cell($labelWidth, 4, $label, 0, 0, 'L');
            $pdf->SetFont($this->fontFamily, '', $this->fontSizeSmall);
            $pdf->Cell($valueWidth, 4, $value, 0, 1, 'L');
            return $y + 4;
        } else {
            $pdf->SetXY($x, $y);
            $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSmall);
            $pdf->MultiCell($colWidth, 4, $label, 0, 'L');
            $newY = $pdf->GetY();
            $pdf->SetXY($x, $newY);
            $pdf->SetFont($this->fontFamily, '', $this->fontSizeSmall);
            $pdf->MultiCell($colWidth, 4, $value, 0, 'L');
            return $pdf->GetY();
        }
    }


    /**
     * @brief Helper to render "Label: Value" pattern
     * @param $y Y position
     * @param $label Label text (bold)
     * @param $value Value text
     * @param $valueFormat Optional format for value
     * @return float New Y position
     * @called_by renderPayment()
     */
    private function renderLabelValue($y, $label, $value, $valueFormat = null)
    {
        $pdf = $this->pdf;
        $pdf->SetXY($this->marginLeft, $y);
        $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSmall);
        $pdf->Cell($pdf->GetStringWidth($label), 4, $label, 0, 0, 'L');
        $pdf->SetFont($this->fontFamily, '', $this->fontSizeSmall);
        $pdf->Cell(0, 4, $value, 0, 1, 'L');
        return $y + 4;
    }


    /**
     * @brief Render seller and buyer sections in two columns
     * @param $incoming KsefIncoming object
     * @param $y Current Y position
     * @return float New Y position
     * @called_by generate()
     * @calls drawLine(), renderEntity()
     */
    private function renderSellerBuyer($incoming, $y)
    {
        $pdf = $this->pdf;
        $y = $this->drawLine($y);

        $colWidth = ($this->contentWidth - 5) / 2;
        $leftX = $this->marginLeft;
        $rightX = $this->marginLeft + $colWidth + 5;
        $startY = $y;

        // SELLER
        $sellerY = $this->renderEntity($leftX, $y, $colWidth, 'Sprzedawca', array(
            'nip' => $incoming->seller_nip,
            'eori' => $this->parsed['seller']['nr_eori'] ?? null,
            'name' => $incoming->seller_name,
            'address' => $incoming->seller_address,
            'country' => $incoming->seller_country ?? 'PL',
            'email' => $this->parsed['seller']['email'] ?? null,
            'phone' => $this->parsed['seller']['phone'] ?? null,
        ));

        // BUYER
        $buyer = $this->parsed['buyer'];
        $vatUE = !empty($buyer['kod_ue'])
            ? $buyer['kod_ue'] . ' ' . ($buyer['nr_vat_ue'] ?: '')
            : null;

        $buyerY = $this->renderEntity($rightX, $startY, $colWidth, 'Nabywca', array(
            'nip' => $incoming->buyer_nip,
            'eori' => $buyer['nr_eori'] ?? null,
            'vatUE' => $vatUE,
            'isEU' => !empty($buyer['kod_ue']),
            'name' => $incoming->buyer_name,
            'address' => $buyer['address'] ?? null,
            'country' => $buyer['country'] ?? 'PL',
            'email' => $buyer['email'] ?? null,
            'phone' => $buyer['phone'] ?? null,
            'customerNumber' => $buyer['customer_number'] ?? null,
        ));

        // JST/GV indicators
        $buyerY += 2;
        $jstValue = (isset($buyer['jst']) && $buyer['jst'] === '1') ? 'TAK' : 'NIE';
        $gvValue = (isset($buyer['gv']) && $buyer['gv'] === '1') ? 'TAK' : 'NIE';
        $pdf->SetFont($this->fontFamily, '', $this->fontSizeSmall);
        $pdf->SetXY($rightX, $buyerY);
        $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSmall);
        $label = 'Faktura dotyczy jednostki podrzędnej JST: ';
        $pdf->Cell($pdf->GetStringWidth($label), 4, $label, 0, 0, 'L');
        $pdf->SetFont($this->fontFamily, '', $this->fontSizeSmall);
        $pdf->Cell(0, 4, $jstValue, 0, 1, 'L');
        $buyerY += 4;

        $pdf->SetXY($rightX, $buyerY);
        $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSmall);
        $label = 'Faktura dotyczy członka grupy GV: ';
        $pdf->Cell($pdf->GetStringWidth($label), 4, $label, 0, 0, 'L');
        $pdf->SetFont($this->fontFamily, '', $this->fontSizeSmall);
        $pdf->Cell(0, 4, $gvValue, 0, 1, 'L');
        $buyerY += 4;

        $y = max($sellerY, $buyerY) + 2;

        // Podmiot2K: render buyer before/after comparison
        $buyerBefore = $this->parsed['buyer_before'] ?? null;
        if (!empty($buyerBefore)) {
            $y = $this->renderBuyerCorrection($buyerBefore, $buyer, $incoming, $y);
        }

        // Podmiot3
        $thirdParties = $this->parsed['third_parties'] ?? array();
        foreach ($thirdParties as $idx => $tp) {
            $y = $this->renderThirdParty($idx, $tp, $y);
        }

        return $y;
    }


    /**
     * @brief Render a single Podmiot3 (third entity) block
     * @param int $index 0-based position (header shows N = index + 1)
     * @param array $tp Parsed third-party entry
     * @param float $y Current Y position
     * @return float New Y position
     */
    private function renderThirdParty($index, $tp, $y)
    {
        $pdf = $this->pdf;
        $y = $this->checkPageBreak($y, 45);
        $y = $this->drawLine($y);

        $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSection);
        $pdf->SetXY($this->marginLeft, $y);
        $pdf->Cell($this->contentWidth, 5, 'Podmiot inny ' . ($index + 1), 0, 1, 'L');
        $y += 6;

        $colWidth = ($this->contentWidth - 5) / 2;
        $leftX = $this->marginLeft;
        $rightX = $this->marginLeft + $colWidth + 5;

        // l column
        $ly = $y;
        if (!empty($tp['kod_ue'])) {
            $ly = $this->renderTpField($leftX, $ly, $colWidth, 'Numer VAT-UE: ', trim($tp['kod_ue'] . ' ' . ($tp['nr_vat_ue'] ?: '')));
        } elseif (!empty($tp['nip'])) {
            $ly = $this->renderTpField($leftX, $ly, $colWidth, 'NIP: ', $this->formatNIP($tp['nip']));
        }
        if (!empty($tp['name'])) {
            $ly = $this->renderTpField($leftX, $ly, $colWidth, 'Nazwa: ', $tp['name']);
        }
        if (!empty($tp['id_nabywcy'])) {
            $ly = $this->renderTpField($leftX, $ly, $colWidth, 'Identyfikator nabywcy: ', $tp['id_nabywcy']);
        }
        $ly = $this->renderTpField($leftX, $ly, $colWidth, 'Rola: ', $this->thirdPartyRoleLabel($tp['role'] ?? ''));
        if (!empty($tp['udzial'])) {
            $ly = $this->renderTpField($leftX, $ly, $colWidth, 'Udział: ', $this->formatPercent($tp['udzial']));
        }

        // r column
        $ry = $y;
        $pdf->SetXY($rightX, $ry);
        $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSmall);
        $pdf->Cell($colWidth, 4, 'Adres', 0, 1, 'L');
        $ry += 4;
        $pdf->SetFont($this->fontFamily, '', $this->fontSizeSmall);
        if (!empty($tp['address'])) {
            foreach ($this->parseAddressLines($tp['address']) as $line) {
                $pdf->SetXY($rightX, $ry);
                $pdf->Cell($colWidth, 4, $line, 0, 1, 'L');
                $ry += 4;
            }
        }
        if (!empty($tp['country'])) {
            $pdf->SetXY($rightX, $ry);
            $pdf->Cell($colWidth, 4, $this->getCountryName($tp['country']), 0, 1, 'L');
            $ry += 4;
        }
        if (!empty($tp['email']) || !empty($tp['phone']) || !empty($tp['nr_klienta'])) {
            $ry += 2;
            $pdf->SetXY($rightX, $ry);
            $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSmall);
            $pdf->Cell($colWidth, 4, 'Dane kontaktowe', 0, 1, 'L');
            $ry += 4;
            if (!empty($tp['email'])) $ry = $this->renderTpField($rightX, $ry, $colWidth, 'E-mail: ', $tp['email']);
            if (!empty($tp['phone'])) $ry = $this->renderTpField($rightX, $ry, $colWidth, 'Tel.: ', $tp['phone']);
            if (!empty($tp['nr_klienta'])) $ry = $this->renderTpField($rightX, $ry, $colWidth, 'Numer klienta: ', $tp['nr_klienta']);
        }

        return max($ly, $ry) + 2;
    }

    /**
     * @brief Render a label + value line
     * @called_by renderThirdParty()
     */
    private function renderTpField($x, $y, $w, $label, $value)
    {
        $pdf = $this->pdf;
        $pdf->SetFont($this->fontFamily, '', $this->fontSizeSmall);
        $html = '<b>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</b>' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        $pdf->writeHTMLCell($w, 0, $x, $y, $html, 0, 1);
        return $pdf->GetY();
    }

    /**
     * @brief role label for a podmio3 code
     * @called_by renderThirdParty()
     */
    private function thirdPartyRoleLabel($role)
    {
        $map = array(
            '1'  => 'Faktor - w przypadku, gdy na fakturze występują dane faktora',
            '2'  => 'Odbiorca - w przypadku, gdy na fakturze występują dane jednostek wewnętrznych, oddziałów, wyodrębnionych w ramach nabywcy, które same nie stanowią nabywcy w rozumieniu ustawy',
            '3'  => 'Podmiot pierwotny - w przypadku, gdy na fakturze występują dane podmiotu będącego w stosunku do podatnika podmiotem przejętym lub przekształconym, który dokonywał dostawy lub świadczył usługę. Z wyłączeniem przypadków, o których mowa w art. 106j ust.2 pkt 3 ustawy, gdy dane te wykazywane są w części Podmiot1K',
            '4'  => 'Dodatkowy nabywca - w przypadku, gdy na fakturze występują dane kolejnych (innych niż wymieniony w części Podmiot2) nabywców',
            '5'  => 'Wystawca faktury - w przypadku, gdy na fakturze występują dane podmiotu wystawiającego fakturę w imieniu podatnika. Nie dotyczy przypadku, gdy wystawcą faktury jest nabywca',
            '6'  => 'Dokonujący płatności - w przypadku, gdy na fakturze występują dane podmiotu regulującego zobowiązanie w miejsce nabywcy',
            '7'  => 'Jednostka samorządu terytorialnego - wystawca',
            '8'  => 'Jednostka samorządu terytorialnego - odbiorca',
            '9'  => 'Członek grupy VAT - wystawca',
            '10' => 'Członek grupy VAT - odbiorca',
            '11' => 'Pracownik',
        );
        return isset($map[$role]) ? $map[$role] : ('Rola ' . $role);
    }


    /**
     * @brief Render buyer before/after (Podmiot2K vs Podmiot2)
     * @param $buyerBefore array Podmiot2K data
     * @param $buyerAfter array Podmiot2 data
     * @param $incoming KsefIncoming object
     * @param $y Current Y position
     * @return float New Y position
     * @called_by renderSellerBuyer()
     * @calls checkPageBreak(), parseAddressLines(), getCountryName(), formatNIP()
     */
    private function renderBuyerCorrection($buyerBefore, $buyerAfter, $incoming, $y)
    {
        $pdf = $this->pdf;

        $colWidth = ($this->contentWidth - 5) / 2;
        $leftX = $this->marginLeft;
        $rightX = $this->marginLeft + $colWidth + 5;

        $y = $this->checkPageBreak($y, 40);

        // Left: Treść korygowana (before)
        $leftY = $y;
        $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSmall);
        $pdf->SetXY($leftX, $leftY);
        $pdf->Cell($colWidth, 5, 'Treść korygowana', 0, 1, 'L');
        $leftY += 6;

        $pdf->SetFont($this->fontFamily, '', $this->fontSizeSmall);
        $leftY = $this->renderBuyerColumn($leftX, $leftY, $colWidth, $buyerBefore);

        // Right: Treść korygująca (after)
        $rightY = $y;
        $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSmall);
        $pdf->SetXY($rightX, $rightY);
        $pdf->Cell($colWidth, 5, 'Treść korygująca', 0, 1, 'L');
        $rightY += 6;

        $pdf->SetFont($this->fontFamily, '', $this->fontSizeSmall);
        $afterData = array(
            'nip' => $incoming->buyer_nip,
            'name' => $incoming->buyer_name,
            'country' => $buyerAfter['country'] ?? 'PL',
            'address' => $buyerAfter['address'] ?? null,
            'kod_ue' => $buyerAfter['kod_ue'] ?? null,
            'nr_vat_ue' => $buyerAfter['nr_vat_ue'] ?? null,
        );
        $rightY = $this->renderBuyerColumn($rightX, $rightY, $colWidth, $afterData);

        return max($leftY, $rightY) + 2;
    }


    /**
     * @brief Render single buyer data column
     * @param $x X position
     * @param $y Y position
     * @param $width Column width
     * @param $data Buyer data
     * @return float New Y position
     * @called_by renderBuyerCorrection()
     * @calls formatNIP(), parseAddressLines(), getCountryName()
     */
    private function renderBuyerColumn($x, $y, $width, $data)
    {
        $pdf = $this->pdf;

        // NIP or VAT-UE
        if (!empty($data['kod_ue'])) {
            $vatUE = $data['kod_ue'] . ' ' . ($data['nr_vat_ue'] ?: '');
            $pdf->SetXY($x, $y);
            $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSmall);
            $lw = $pdf->GetStringWidth('Numer VAT-UE: ') + 1;
            $pdf->Cell($lw, 4, 'Numer VAT-UE: ', 0, 0, 'L');
            $pdf->SetFont($this->fontFamily, '', $this->fontSizeSmall);
            $pdf->Cell($width - $lw, 4, trim($vatUE), 0, 1, 'L');
            $y += 4;
        } elseif (!empty($data['nip'])) {
            $pdf->SetXY($x, $y);
            $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSmall);
            $lw = $pdf->GetStringWidth('NIP: ') + 1;
            $pdf->Cell($lw, 4, 'NIP: ', 0, 0, 'L');
            $pdf->SetFont($this->fontFamily, '', $this->fontSizeSmall);
            $pdf->Cell($width - $lw, 4, $this->formatNIP($data['nip']), 0, 1, 'L');
            $y += 4;
        }

        // Name
        if (!empty($data['name'])) {
            $pdf->SetXY($x, $y);
            $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSmall);
            $lw = $pdf->GetStringWidth('Nazwa: ') + 1;
            $pdf->Cell($lw, 4, 'Nazwa: ', 0, 0, 'L');
            $pdf->SetFont($this->fontFamily, '', $this->fontSizeSmall);
            $pdf->Cell($width - $lw, 4, $data['name'], 0, 1, 'L');
            $y += 4;
        }

        // Address
        if (!empty($data['address'])) {
            $y += 2;
            $pdf->SetXY($x, $y);
            $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSmall);
            $pdf->Cell($width, 4, 'Adres', 0, 1, 'L');
            $y += 4;

            $pdf->SetFont($this->fontFamily, '', $this->fontSizeSmall);
            $lines = $this->parseAddressLines($data['address']);
            foreach ($lines as $line) {
                $pdf->SetXY($x, $y);
                $pdf->Cell($width, 4, $line, 0, 1, 'L');
                $y += 4;
            }
        }

        // Country
        if (!empty($data['country'])) {
            $pdf->SetXY($x, $y);
            $pdf->Cell($width, 4, $this->getCountryName($data['country']), 0, 1, 'L');
            $y += 4;
        }

        return $y;
    }


    /**
     * @brief Render entity (seller or buyer) section
     * @param $x X position
     * @param $y Y position
     * @param $width Column width
     * @param $header Section header text
     * @param $data Entity data array
     * @return float New Y position
     * @called_by renderSellerBuyer()
     * @calls formatNIP(), parseAddressLines(), getCountryName()
     */
    private function renderEntity($x, $y, $width, $header, $data)
    {
        $pdf = $this->pdf;

        // Header
        $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSection);
        $pdf->SetXY($x, $y);
        $pdf->Cell($width, 5, $header, 0, 1, 'L');
        $y += 6;

        $pdf->SetFont($this->fontFamily, '', $this->fontSizeSmall);

        // EORI number
        if (!empty($data['eori'])) {
            $pdf->SetXY($x, $y);
            $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSmall);
            $lw = $pdf->GetStringWidth('Numer EORI: ') + 1;
            $pdf->Cell($lw, 4, 'Numer EORI: ', 0, 0, 'L');
            $pdf->SetFont($this->fontFamily, '', $this->fontSizeSmall);
            $pdf->Cell($width - $lw, 4, $data['eori'], 0, 1, 'L');
            $y += 4;
        }

        // NIP or VAT-UE number
        if (!empty($data['vatUE'])) {
            $pdf->SetXY($x, $y);
            $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSmall);
            $lw = $pdf->GetStringWidth('Numer VAT-UE: ') + 1;
            $pdf->Cell($lw, 4, 'Numer VAT-UE: ', 0, 0, 'L');
            $pdf->SetFont($this->fontFamily, '', $this->fontSizeSmall);
            $pdf->Cell($width - $lw, 4, trim($data['vatUE']), 0, 1, 'L');
            $y += 4;
        } elseif (!empty($data['nip'])) {
            // NIP
            $pdf->SetXY($x, $y);
            $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSmall);
            $lw = $pdf->GetStringWidth('NIP: ') + 1;
            $pdf->Cell($lw, 4, 'NIP: ', 0, 0, 'L');
            $pdf->SetFont($this->fontFamily, '', $this->fontSizeSmall);
            $pdf->Cell($width - $lw, 4, $this->formatNIP($data['nip']), 0, 1, 'L');
            $y += 4;
        }

        // Name
        if (!empty($data['name'])) {
            $pdf->SetXY($x, $y);
            $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSmall);
            $lw = $pdf->GetStringWidth('Nazwa: ') + 1;
            $pdf->Cell($lw, 4, 'Nazwa: ', 0, 0, 'L');
            $pdf->SetFont($this->fontFamily, '', $this->fontSizeSmall);
            $pdf->Cell($width - $lw, 4, $data['name'], 0, 1, 'L');
            $y += 4;
        }

        // Address header
        $y += 2;
        $pdf->SetXY($x, $y);
        $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSmall);
        $pdf->Cell($width, 4, 'Adres', 0, 1, 'L');
        $y += 4;

        // Address lines
        $pdf->SetFont($this->fontFamily, '', $this->fontSizeSmall);
        if (!empty($data['address'])) {
            $lines = $this->parseAddressLines($data['address']);
            foreach ($lines as $line) {
                $pdf->SetXY($x, $y);
                $pdf->Cell($width, 4, $line, 0, 1, 'L');
                $y += 4;
            }
        }

        // Country
        if (!empty($data['country'])) {
            $pdf->SetXY($x, $y);
            $pdf->Cell($width, 4, $this->getCountryName($data['country']), 0, 1, 'L');
            $y += 4;
        }

        // Contact data
        if (!empty($data['email']) || !empty($data['phone']) || !empty($data['customerNumber'])) {
            $y += 2;
            $pdf->SetXY($x, $y);
            $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSmall);
            $pdf->Cell($width, 4, 'Dane kontaktowe', 0, 1, 'L');
            $y += 4;

            $pdf->SetFont($this->fontFamily, '', $this->fontSizeSmall);

            if (!empty($data['email'])) {
                $pdf->SetXY($x, $y);
                $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSmall);
                $lw = $pdf->GetStringWidth('E-mail: ') + 1;
                $pdf->Cell($lw, 4, 'E-mail: ', 0, 0, 'L');
                $pdf->SetFont($this->fontFamily, '', $this->fontSizeSmall);
                $pdf->Cell($width - $lw, 4, $data['email'], 0, 1, 'L');
                $y += 4;
            }
            if (!empty($data['phone'])) {
                $pdf->SetXY($x, $y);
                $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSmall);
                $lw = $pdf->GetStringWidth('Tel.: ') + 1;
                $pdf->Cell($lw, 4, 'Tel.: ', 0, 0, 'L');
                $pdf->SetFont($this->fontFamily, '', $this->fontSizeSmall);
                $pdf->Cell($width - $lw, 4, $data['phone'], 0, 1, 'L');
                $y += 4;
            }
            // Customer number (Numer klienta)
            if (!empty($data['customerNumber'])) {
                $pdf->SetXY($x, $y);
                $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSmall);
                $lw = $pdf->GetStringWidth('Numer klienta: ') + 1;
                $pdf->Cell($lw, 4, 'Numer klienta: ', 0, 0, 'L');
                $pdf->SetFont($this->fontFamily, '', $this->fontSizeSmall);
                $pdf->Cell($width - $lw, 4, $data['customerNumber'], 0, 1, 'L');
                $y += 4;
            }
        }

        return $y;
    }


    /**
     * @brief Render invoice details section (dates, place of issue)
     * @param $incoming KsefIncoming object
     * @param $y Current Y position
     * @return float New Y position
     * @called_by generate()
     * @calls checkPageBreak(), drawLine()
     */
    private function renderDetails($incoming, $y)
    {
        $pdf = $this->pdf;
        $y = $this->checkPageBreak($y, 20);
        $y = $this->drawLine($y);

        // Header
        $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSection);
        $pdf->SetXY($this->marginLeft, $y);
        $pdf->Cell($this->contentWidth, 5, 'Szczegóły', 0, 1, 'L');
        $y += 5;


        $colWidth = ($this->contentWidth - 5) / 2;
        $leftX = $this->marginLeft;
        $rightX = $this->marginLeft + $colWidth + 5;
        $detailsFontSize = 6;

        $leftY = $y;
        $rightY = $y;

        // Issue date
        if (!empty($incoming->invoice_date)) {
            $dateStr = $this->formatDatePolish($incoming->invoice_date);
            $label = 'Data wystawienia, z zastrzeżeniem art. 106na ust. 1 ustawy: ';

            $pdf->SetXY($leftX, $leftY);
            $pdf->SetFont($this->fontFamily, 'B', $detailsFontSize);
            $labelWidth = $pdf->GetStringWidth($label);

            // Check if label + date fits on one line
            $pdf->SetFont($this->fontFamily, '', $detailsFontSize);
            $dateWidth = $pdf->GetStringWidth($dateStr);

            if ($labelWidth + $dateWidth < $colWidth - 2) {
                $pdf->SetXY($leftX, $leftY);
                $pdf->SetFont($this->fontFamily, 'B', $detailsFontSize);
                $pdf->Cell($labelWidth, 3, $label, 0, 0, 'L');
                $pdf->SetFont($this->fontFamily, '', $detailsFontSize);
                $pdf->Cell($dateWidth, 3, $dateStr, 0, 1, 'L');
                $leftY += 4;
            } else {
                $pdf->SetXY($leftX, $leftY);
                $pdf->SetFont($this->fontFamily, 'B', $detailsFontSize);
                $pdf->MultiCell($colWidth - 2, 3, $label, 0, 'L', false, 1);
                $leftY = $pdf->GetY();
                $pdf->SetXY($leftX, $leftY);
                $pdf->SetFont($this->fontFamily, '', $detailsFontSize);
                $pdf->Cell($dateWidth, 3, $dateStr, 0, 1, 'L');
                $leftY += 4;
            }
        }

        // Currency code
        $currencyCode = $this->parsed['invoice']['currency'] ?? ($incoming->currency ?: 'PLN');
        $pdf->SetXY($leftX, $leftY);
        $pdf->SetFont($this->fontFamily, 'B', $detailsFontSize);
        $label = 'Kod waluty: ';
        $pdf->Cell($pdf->GetStringWidth($label), 3, $label, 0, 0, 'L');
        $pdf->SetFont($this->fontFamily, '', $detailsFontSize);
        $pdf->Cell(0, 3, $currencyCode, 0, 1, 'L');
        $leftY += 4;

        // Place of issue
        $placeOfIssue = $this->parsed['invoice']['place_of_issue'] ?? null;
        if (!empty($placeOfIssue)) {
            $pdf->SetXY($leftX, $leftY);
            $pdf->SetFont($this->fontFamily, 'B', $detailsFontSize);
            $label = 'Miejsce wystawienia: ';
            $pdf->Cell($pdf->GetStringWidth($label), 3, $label, 0, 0, 'L');
            $pdf->SetFont($this->fontFamily, '', $detailsFontSize);
            $pdf->Cell($colWidth - $pdf->GetStringWidth($label), 3, $placeOfIssue, 0, 1, 'L');
            $leftY += 4;
        }

        // RIGHT COLUMN
        // Sale date / delivery date
        $periodFrom = $this->parsed['invoice']['period_from'] ?? null;
        $periodTo = $this->parsed['invoice']['period_to'] ?? null;
        $saleP6 = $this->parsed['invoice']['sale_date'] ?? null;
        $dateStr = '';
        if (!empty($periodFrom) && !empty($periodTo)) {
            $dateStr = 'od ' . $this->formatDatePolish($periodFrom) . ' do ' . $this->formatDatePolish($periodTo);
        } elseif (!empty($saleP6)) {
            $dateStr = $this->formatDatePolish($saleP6);
        } elseif (!empty($incoming->sale_date)) {
            $dateStr = $this->formatDatePolish($incoming->sale_date);
        }
        if ($dateStr !== '') {
            $label = 'Data dokonania lub zakończenia dostawy towarów lub wykonania usługi: ';

            $pdf->SetXY($rightX, $rightY);
            $pdf->SetFont($this->fontFamily, 'B', $detailsFontSize);
            $labelWidth = $pdf->GetStringWidth($label);

            $pdf->SetFont($this->fontFamily, '', $detailsFontSize);
            $dateWidth = $pdf->GetStringWidth($dateStr);

            if ($labelWidth + $dateWidth < $colWidth - 2) {
                // Fits on one line
                $pdf->SetXY($rightX, $rightY);
                $pdf->SetFont($this->fontFamily, 'B', $detailsFontSize);
                $pdf->Cell($labelWidth, 3, $label, 0, 0, 'L');
                $pdf->SetFont($this->fontFamily, '', $detailsFontSize);
                $pdf->Cell($dateWidth, 3, $dateStr, 0, 1, 'L');
                $rightY += 4;
            } else {
                // Needs to wrap
                $pdf->SetXY($rightX, $rightY);
                $pdf->SetFont($this->fontFamily, 'B', $detailsFontSize);
                $pdf->MultiCell($colWidth - 2, 3, $label, 0, 'L', false, 1);
                $rightY = $pdf->GetY();
                $pdf->SetXY($rightX, $rightY);
                $pdf->SetFont($this->fontFamily, '', $detailsFontSize);
                $pdf->Cell($dateWidth, 3, $dateStr, 0, 1, 'L');
                $rightY += 4;
            }
        }

        $y = max($leftY, $rightY) + 2;

        // Exchange rate info
        $currency = $this->parsed['exchange_rate']['currency'] ?? 'PLN';
        $kursWaluty = $this->parsed['exchange_rate']['rate'] ?? null;

        if ($currency != 'PLN' && !empty($kursWaluty)) {
            $y = $this->checkPageBreak($y, 10);

            $pdf->SetXY($this->marginLeft, $y);
            $pdf->SetFont($this->fontFamily, 'B', $detailsFontSize);
            $label = 'Kurs waluty: ';
            $pdf->Cell($pdf->GetStringWidth($label), 3, $label, 0, 0, 'L');

            $pdf->SetFont($this->fontFamily, '', $detailsFontSize);
            $rateText = rtrim(rtrim(number_format((float)$kursWaluty, 6, ',', ' '), '0'), ',');
            $pdf->Cell(0, 3, $rateText, 0, 1, 'L');
            $y += 4;

            $pdf->SetXY($this->marginLeft, $y);
            $pdf->SetFont($this->fontFamily, 'B', $detailsFontSize);
            $pdf->Cell(0, 3, 'Kurs waluty wspólny dla wszystkich wierszy faktury', 0, 1, 'L');
            $y += 4;
        }

        return $y;
    }


    /**
     * @brief Render invoice line items (Pozycje) table
     * @param $incoming KsefIncoming object
     * @param $y Current Y position
     * @return float New Y position
     * @called_by generate()
     * @calls checkPageBreak(), drawLine(), hasAnyField(), drawPositionsTableHeader(), formatQuantity(), formatMoney()
     */
    private function renderPositionsTable($incoming, $y)
    {
        $pdf = $this->pdf;
        $lines = $this->parsed['lines'] ?? array();
        $linesBefore = $this->parsed['lines_before'] ?? array();

        if (empty($lines) && empty($linesBefore)) return $y;

        $y = $this->checkPageBreak($y, 30);
        $y = $this->drawLine($y);

        // Header
        $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSection);
        $pdf->SetXY($this->marginLeft, $y);
        $pdf->Cell($this->contentWidth, 5, 'Pozycje', 0, 1, 'L');
        $y += 5 + 3;

        // Currency info
        $currency = $this->parsed['exchange_rate']['currency'] ?? ($incoming->currency ?: 'PLN');
        $kursWaluty = $this->parsed['exchange_rate']['rate'] ?? null;

        $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeNormal);
        $pdf->SetXY($this->marginLeft, $y);

        $currencyInfo = 'Faktura wystawiona w walucie ' . $currency;

        $pdf->Cell($this->contentWidth, 4, $currencyInfo, 0, 1, 'L');
        $y += 4 + $this->spaceParagraph;

        $allLines = array_merge($linesBefore, $lines);
        $hasUUID = $this->hasAnyField($allLines, 'uuid');
        $hasGrossPrice = $this->hasAnyField($allLines, 'unit_price_gross');
        $hasDiscount = $this->hasAnyField($allLines, 'discount');
        $hasGrossAmount = $this->hasAnyField($allLines, 'gross_amount');
        $hasStanPrzed = !empty($linesBefore);
        $hasUnit = $this->hasAnyField($allLines, 'unit');
        $hasKursWaluty = $this->hasAnyField($allLines, 'kurs_waluty');

        $cols = array();
        $lpWidth = 8;
        $uuidWidth = $hasUUID ? 30 : 0;
        $priceNetWidth = 14;
        $priceGrossWidth = $hasGrossPrice ? 14 : 0;
        $qtyWidth = 12;
        $unitWidth = $hasUnit ? 10 : 0;
        $discountWidth = $hasDiscount ? 10 : 0;
        $vatRateWidth = 14;
        $netAmtWidth = 18;
        $grossAmtWidth = $hasGrossAmount ? 18 : 0;
        $stanPrzedWidth = $hasStanPrzed ? 12 : 0;
        $kursWalutyWidth = $hasKursWaluty ? 16 : 0;

        $fixedWidth = $lpWidth + $uuidWidth + $priceNetWidth + $priceGrossWidth +
            $qtyWidth + $unitWidth + $discountWidth + $vatRateWidth +
            $netAmtWidth + $grossAmtWidth + $stanPrzedWidth + $kursWalutyWidth;
        $nameWidth = $this->contentWidth - $fixedWidth;

        if ($nameWidth < 25) $nameWidth = 25;

        $cols[] = array('h' => 'Lp.', 'w' => $lpWidth, 'a' => 'C', 'k' => 'line_num');

        if ($hasUUID) {
            $cols[] = array('h' => "Unikalny numer wiersza", 'w' => $uuidWidth, 'a' => 'L', 'k' => 'uuid');
        }

        $cols[] = array('h' => "Nazwa towaru\nlub usługi", 'w' => $nameWidth, 'a' => 'L', 'k' => 'description');
        $cols[] = array('h' => "Cena\njedn.\nnetto", 'w' => $priceNetWidth, 'a' => 'R', 'k' => 'unit_price_net');

        if ($hasGrossPrice) {
            $cols[] = array('h' => "Cena\njedn.\nbrutto", 'w' => $priceGrossWidth, 'a' => 'R', 'k' => 'unit_price_gross');
        }

        $cols[] = array('h' => 'Ilość', 'w' => $qtyWidth, 'a' => 'R', 'k' => 'quantity');

        if ($hasUnit) {
            $cols[] = array('h' => 'Miara', 'w' => $unitWidth, 'a' => 'C', 'k' => 'unit');
        }

        if ($hasDiscount) {
            $cols[] = array('h' => 'Rabat', 'w' => $discountWidth, 'a' => 'R', 'k' => 'discount');
        }

        $cols[] = array('h' => "Stawka\npodatku", 'w' => $vatRateWidth, 'a' => 'C', 'k' => 'vat_rate');
        $cols[] = array('h' => "Wartość\nsprzedaży\nnetto", 'w' => $netAmtWidth, 'a' => 'R', 'k' => 'net_amount');

        if ($hasGrossAmount) {
            $cols[] = array('h' => "Wartość\nsprzedaży\nbrutto", 'w' => $grossAmtWidth, 'a' => 'R', 'k' => 'gross_amount');
        }

        if ($hasKursWaluty) {
            $cols[] = array('h' => "Kurs\nwaluty", 'w' => $kursWalutyWidth, 'a' => 'R', 'k' => 'kurs_waluty');
        }

        if ($hasStanPrzed) {
            $cols[] = array('h' => "Stan\nprzed", 'w' => $stanPrzedWidth, 'a' => 'C', 'k' => 'stan_przed');
        }

        $tableFontSize = 7;
        $y = $this->drawPositionsTableHeader($cols, $y);
        $pdf->SetFont($this->fontFamily, '', $tableFontSize);

        $allRenderLines = array();
        if (!empty($linesBefore)) {
            // Interleave before/after rows by line number
            $beforeByNum = array();
            foreach ($linesBefore as $line) {
                $line['stan_przed'] = 'Tak';
                $num = $line['line_num'] ?? 0;
                $beforeByNum[$num] = $line;
            }
            $afterByNum = array();
            foreach ($lines as $line) {
                $line['stan_przed'] = '';
                $num = $line['line_num'] ?? 0;
                $afterByNum[$num][] = $line;
            }
            // Collect line numbers
            $allNums = array_unique(array_merge(array_keys($beforeByNum), array_keys($afterByNum)));
            sort($allNums, SORT_NUMERIC);
            foreach ($allNums as $num) {
                if (isset($beforeByNum[$num])) {
                    $allRenderLines[] = $beforeByNum[$num];
                }
                if (isset($afterByNum[$num])) {
                    foreach ($afterByNum[$num] as $afterLine) {
                        $allRenderLines[] = $afterLine;
                    }
                }
            }
        } else {
            foreach ($lines as $line) {
                $line['stan_przed'] = '';
                $allRenderLines[] = $line;
            }
        }

        $pdf->SetLineWidth($this->borderWidth);
        $pdf->SetDrawColorArray($this->colorBorder);

        foreach ($allRenderLines as $line) {
            $rowData = array();
            $maxLines = 1;

            foreach ($cols as $col) {
                $value = '';
                switch ($col['k']) {
                    case 'line_num': $value = $line['line_num'] ?? ''; break;
                    case 'uuid': $value = $line['uuid'] ?? ''; break;
                    case 'description': $value = $line['description'] ?? ''; break;
                    case 'quantity': $value = $this->formatQuantity($line['quantity'] ?? 0); break;
                    case 'unit': $value = $line['unit'] ?? ''; break;
                    case 'unit_price_net': $value = isset($line['unit_price_net']) ? $this->formatMoney($line['unit_price_net']) : ''; break;
                    case 'unit_price_gross': $value = isset($line['unit_price_gross']) ? $this->formatMoney($line['unit_price_gross']) : ''; break;
                    case 'discount': $value = isset($line['discount']) ? $this->formatMoney($line['discount']) : ''; break;
                    case 'vat_rate': $value = $this->getVatRateLabel($line['vat_rate'] ?? '', true); break;
                    case 'net_amount': $value = isset($line['net_amount']) ? $this->formatMoney($line['net_amount']) : ''; break;
                    case 'gross_amount': $value = isset($line['gross_amount']) ? $this->formatMoney($line['gross_amount']) : ''; break;
                    case 'kurs_waluty': $value = isset($line['kurs_waluty']) ? rtrim(rtrim(number_format((float)$line['kurs_waluty'], 6, ',', ' '), '0'), ',') : ''; break;
                    case 'stan_przed': $value = $line['stan_przed'] ?? ''; break;
                }
                $rowData[] = $value;

                if (in_array($col['k'], array('uuid', 'description')) && !empty($value)) {
                    $cellLines = $pdf->getNumLines($value, $col['w'] - 1);
                    if ($cellLines > $maxLines) $maxLines = $cellLines;
                }
            }

            $rowHeight = max(5, $maxLines * 3.2);

            $oldY = $y;
            $y = $this->checkPageBreak($y, $rowHeight + 2);
            if ($y < $oldY) {
                // on a new page, redraw the table header
                $y = $this->drawPositionsTableHeader($cols, $y);
                $pdf->SetFont($this->fontFamily, '', $tableFontSize);
                $pdf->SetLineWidth($this->borderWidth);
                $pdf->SetDrawColorArray($this->colorBorder);
            }

            $x = $this->marginLeft;
            foreach ($cols as $i => $col) {
                $pdf->SetXY($x, $y);

                if (in_array($col['k'], array('uuid', 'description'))) {
                    $pdf->Cell($col['w'], $rowHeight, '', 1, 0, $col['a']);
                    $pdf->SetXY($x + 0.5, $y + 0.5);
                    $pdf->MultiCell($col['w'] - 1, 3.2, $rowData[$i], 0, 'L', false, 0);
                } else {
                    $pdf->Cell($col['w'], $rowHeight, $rowData[$i], 1, 0, $col['a']);
                }
                $x += $col['w'];
            }
            $y += $rowHeight;
        }

        return $y + $this->spaceParagraph;
    }


    /**
     * @brief Helper to draw positions table header
     * @param $cols Column definitions array
     * @param $y Current Y position
     * @return float New Y position after header
     * @called_by renderPositionsTable()
     */
    private function drawPositionsTableHeader($cols, $y)
    {
        $pdf = $this->pdf;
        $tableFontSize = 7;
        $headerHeight = 12;

        $pdf->SetFont($this->fontFamily, 'B', $tableFontSize);
        $pdf->SetFillColorArray($this->colorHeaderBg);
        $pdf->SetDrawColorArray($this->colorBorder);
        $pdf->SetLineWidth($this->borderWidth);

        $x = $this->marginLeft;
        foreach ($cols as $col) {
            $pdf->SetXY($x, $y);
            $pdf->Cell($col['w'], $headerHeight, '', 1, 0, 'C', true);
            $pdf->SetXY($x, $y + 1);
            $pdf->MultiCell($col['w'], 3.2, $col['h'], 0, 'C', false, 0);
            $x += $col['w'];
        }
        return $y + $headerHeight;
    }


    /**
     * @brief Render GTIN/Indeks table (if data exists)
     * @param $y Current Y position
     * @return float New Y position
     * @called_by generate()
     * @calls hasAnyField(), drawGtinTableHeader()
     */
    private function renderGtinTable($y)
    {
        $pdf = $this->pdf;
        $lines = array_merge($this->parsed['lines_before'] ?? array(), $this->parsed['lines'] ?? array());

        $hasGtin = $this->hasAnyField($lines, 'gtin');
        $hasIndeks = $this->hasAnyField($lines, 'indeks');

        if (!$hasGtin && !$hasIndeks) return $y;

        $gtinLines = array();
        foreach ($lines as $line) {
            if (!empty($line['gtin']) || !empty($line['indeks'])) {
                $gtinLines[] = $line;
            }
        }
        if (empty($gtinLines)) return $y;

        $y += 2;

        $cols = array(array('h' => 'Lp.', 'w' => 10));
        if ($hasGtin) $cols[] = array('h' => 'GTIN', 'w' => 30);
        if ($hasIndeks) $cols[] = array('h' => 'Indeks', 'w' => 30);

        $y = $this->drawGtinTableHeader($cols, $y);

        $pdf->SetFont($this->fontFamily, '', $this->fontSizeTable);
        $rowHeight = 4;

        foreach ($gtinLines as $line) {
            // Check if we need a page break
            if ($y + $rowHeight > $this->pageHeight - $this->marginBottom) {
                $pdf->AddPage();
                $y = $this->marginTop;
                $y = $this->drawGtinTableHeader($cols, $y);
                $pdf->SetFont($this->fontFamily, '', $this->fontSizeTable);
            }

            $x = $this->marginLeft;
            $pdf->SetXY($x, $y);
            $pdf->Cell($cols[0]['w'], $rowHeight, $line['line_num'], 1, 0, 'C');
            $x += $cols[0]['w'];

            $colIdx = 1;
            if ($hasGtin) {
                $pdf->SetXY($x, $y);
                $pdf->Cell($cols[$colIdx]['w'], $rowHeight, $line['gtin'] ?? '', 1, 0, 'L');
                $x += $cols[$colIdx]['w'];
                $colIdx++;
            }
            if ($hasIndeks) {
                $pdf->SetXY($x, $y);
                $pdf->Cell($cols[$colIdx]['w'], $rowHeight, $line['indeks'] ?? '', 1, 0, 'L');
            }
            $y += $rowHeight;
        }

        return $y + $this->spaceTableAfter;
    }


    /**
     * @brief Helper to draw GTIN table header - bold with gray background
     * @param $cols Column definitions array
     * @param $y Current Y position
     * @return float New Y position after header
     * @called_by renderGtinTable()
     */
    private function drawGtinTableHeader($cols, $y)
    {
        $pdf = $this->pdf;
        $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeTable);
        $pdf->SetFillColorArray($this->colorHeaderBg);
        $pdf->SetDrawColorArray($this->colorBorder);
        $pdf->SetLineWidth($this->borderWidth);
        $x = $this->marginLeft;
        foreach ($cols as $col) {
            $pdf->SetXY($x, $y);
            $pdf->Cell($col['w'], 5, $col['h'], 1, 0, 'C', true);
            $x += $col['w'];
        }
        return $y + 5;
    }


    /**
     * @brief Render total amount line (Kwota należności ogółem)
     * @param $incoming KsefIncoming object
     * @param $y Current Y position
     * @return float New Y position
     * @called_by generate()
     * @calls checkPageBreak(), formatMoney()
     */
    private function renderTotalAmount($incoming, $y)
    {
        $pdf = $this->pdf;
        $y = $this->checkPageBreak($y, 10);

        $currency = $incoming->currency ?: 'PLN';
        // Use parsed P_15
        $totalGross = $this->parsed['invoice']['total_gross'] ?? null;
        if ($totalGross !== null) {
            $amount = $this->formatMoney($totalGross);
        } else {
            $amount = $this->formatMoney($incoming->total_gross);
        }

        $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSection);
        $pdf->SetXY($this->marginLeft, $y);
        $pdf->Cell($this->contentWidth, 5, 'Kwota należności ogółem: ' . $amount . ' ' . $currency, 0, 1, 'R');

        return $y + 7;
    }


    /**
     * @brief Render VAT summary table (Podsumowanie stawek podatku)
     * @param $incoming KsefIncoming object
     * @param $y Current Y position
     * @return float New Y position
     * @called_by generate()
     * @calls checkPageBreak(), getVatRateLabel(), formatMoney()
     */
    private function renderVatSummary($incoming, $y)
    {
        $pdf = $this->pdf;

        $vatSummary = array();
        if (method_exists($incoming, 'getVatSummary')) {
            $vatSummary = $incoming->getVatSummary();
        }
        if (empty($vatSummary) && !empty($this->parsed['vat_summary'])) {
            foreach ($this->parsed['vat_summary'] as $rate => $amounts) {
                $vatSummary[$rate] = array(
                    'net' => $amounts['net'] ?? 0,
                    'vat' => $amounts['vat'] ?? 0,
                );
            }
        }
        if (empty($vatSummary)) return $y;

        $y = $this->checkPageBreak($y, 25);

        // Header
        $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSection);
        $pdf->SetXY($this->marginLeft, $y);
        $pdf->Cell($this->contentWidth, 5, 'Podsumowanie stawek podatku', 0, 1, 'L');
        $y += 6;

        // Table columns
        $colW = array(10, 35, 30, 30, 30);
        $headers = array('Lp.', 'Stawka podatku', 'Kwota netto', 'Kwota podatku', 'Kwota brutto');

        // Header row
        $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeTable);
        $pdf->SetFillColorArray($this->colorHeaderBg);
        $pdf->SetDrawColorArray($this->colorBorder);
        $pdf->SetLineWidth($this->borderWidth);
        $x = $this->marginLeft;
        foreach ($headers as $i => $h) {
            $pdf->SetXY($x, $y);
            $pdf->Cell($colW[$i], 5, $h, 1, 0, 'C', true);
            $x += $colW[$i];
        }
        $y += 5;

        // Data rows
        $pdf->SetFont($this->fontFamily, '', $this->fontSizeTable);
        $rowNum = 1;
        foreach ($vatSummary as $rate => $amounts) {
            $oldY = $y;
            $y = $this->checkPageBreak($y, 7);
            if ($y < $oldY) {
                // Redraw header on new page
                $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeTable);
                $pdf->SetFillColorArray($this->colorHeaderBg);
                $pdf->SetDrawColorArray($this->colorBorder);
                $pdf->SetLineWidth($this->borderWidth);
                $x = $this->marginLeft;
                foreach ($headers as $i => $h) {
                    $pdf->SetXY($x, $y);
                    $pdf->Cell($colW[$i], 5, $h, 1, 0, 'C', true);
                    $x += $colW[$i];
                }
                $y += 5;
                $pdf->SetFont($this->fontFamily, '', $this->fontSizeTable);
            }

            $x = $this->marginLeft;
            $pdf->SetXY($x, $y);
            $pdf->Cell($colW[0], 5, $rowNum, 1, 0, 'C');
            $x += $colW[0];
            $pdf->SetXY($x, $y);
            $pdf->Cell($colW[1], 5, $this->getVatRateLabel($rate), 1, 0, 'L');
            $x += $colW[1];
            $pdf->SetXY($x, $y);
            $pdf->Cell($colW[2], 5, $this->formatMoney($amounts['net'] ?? 0), 1, 0, 'R');
            $x += $colW[2];
            $pdf->SetXY($x, $y);
            $pdf->Cell($colW[3], 5, $this->formatMoney($amounts['vat'] ?? 0), 1, 0, 'R');
            $x += $colW[3];
            $pdf->SetXY($x, $y);
            $gross = ($amounts['net'] ?? 0) + ($amounts['vat'] ?? 0);
            $pdf->Cell($colW[4], 5, $this->formatMoney($gross), 1, 0, 'R');
            $y += 5;
            $rowNum++;
        }

        return $y + 3;
    }

    /**
     * @brief Render the Adnotacje section
     * @param $y Current Y position
     * @return float New Y position
     */
    private function renderAdnotacje($y)
    {
        $inv = $this->parsed['invoice'] ?? array();
        $entries = array();

        if (!empty($inv['p_19_flag'])) {
            $entries[] = array('label' => '', 'text' => 'Dostawa towarów lub świadczenie usług zwolnionych od podatku na podstawie art. 43 ust. 1, art. 113 ust. 1 i 9 albo przepisów wydanych na podstawie art. 82 ust. 3 lub na podstawie innych przepisów', 'balance' => true);
            if (!empty($inv['p_19a'])) {
                $entries[] = array('label' => 'Podstawa zwolnienia od podatku: ', 'text' => 'Przepis ustawy albo aktu wydanego na podstawie ustawy, na podstawie którego podatnik stosuje zwolnienie od podatku', 'balance' => true);
                $entries[] = array('label' => 'Przepis ustawy albo aktu wydanego na podstawie ustawy: ', 'text' => $inv['p_19a'], 'balance' => true);
            }
            if (!empty($inv['p_19b'])) {
                $entries[] = array('label' => 'Podstawa zwolnienia od podatku: ', 'text' => 'Przepis dyrektywy 2006/112/WE, który zwalnia od podatku taką dostawę towarów lub takie świadczenie usług', 'balance' => true);
                $entries[] = array('label' => 'Przepis dyrektywy: ', 'text' => $inv['p_19b'], 'balance' => true);
            }
            if (!empty($inv['p_19c'])) {
                $entries[] = array('label' => 'Podstawa zwolnienia od podatku: ', 'text' => 'Inna podstawa prawna wskazującą na to, że dostawa towarów lub świadczenie usług korzysta ze zwolnienia', 'balance' => true);
                $entries[] = array('label' => 'Inna podstawa prawna: ', 'text' => $inv['p_19c'], 'balance' => true);
            }
        }

        if (!empty($inv['mpp_flag']))  $entries[] = array('label' => '', 'text' => 'Mechanizm podzielonej płatności', 'balance' => false); // P_18A
        if (!empty($inv['p_16_flag'])) $entries[] = array('label' => '', 'text' => 'Metoda kasowa', 'balance' => false);                   // P_16
        if (!empty($inv['p_18_flag'])) $entries[] = array('label' => '', 'text' => 'Odwrotne obciążenie', 'balance' => false);             // P_18
        if (!empty($inv['p_23_flag'])) $entries[] = array('label' => '', 'text' => 'Procedura trójstronna uproszczona', 'balance' => false); // P_23
        if (!empty($inv['pmarzy_flag'])) {
            $marzyTypes = array('2' => 'biura podróży', '3_1' => 'towary używane', '3_2' => 'dzieła sztuki', '3_3' => 'przedmioty kolekcjonerskie i antyki');
            $marzyVal = isset($marzyTypes[$inv['pmarzy_subtype']]) ? $marzyTypes[$inv['pmarzy_subtype']] : '';
            $entries[] = array('label' => 'Procedura marży: ', 'text' => $marzyVal, 'balance' => false);
        }
        if (!empty($inv['p_17_flag'])) $entries[] = array('label' => '', 'text' => 'Samofakturowanie', 'balance' => false);                // P_17

        if (empty($entries)) {
            return $y;
        }

        $left = array();
        $right = array();
        foreach ($entries as $e) {
            if (count($left) > count($right) && $e['balance']) {
                $right[] = $e;
            } else {
                $left[] = $e;
            }
        }

        $pdf = $this->pdf;
        $colGap = 6;
        $colW = ($this->contentWidth - $colGap) / 2;

        $pdf->SetFont($this->fontFamily, '', $this->fontSizeSmall);
        $measure = function ($items) use ($pdf, $colW) {
            $h = 0;
            foreach ($items as $e) $h += $pdf->getStringHeight($colW, $e['label'] . $e['text']);
            return $h;
        };
        $y = $this->checkPageBreak($y, 8 + max($measure($left), $measure($right)));

        $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSection);
        $pdf->SetXY($this->marginLeft, $y);
        $pdf->Cell($this->contentWidth, 5, 'Adnotacje', 0, 1, 'L');
        $y += 5;

        $yLeft = $this->renderAdnotacjeColumn($left, $this->marginLeft, $colW, $y);
        $yRight = $this->renderAdnotacjeColumn($right, $this->marginLeft + $colW + $colGap, $colW, $y);

        return max($yLeft, $yRight) + 2;
    }

    /**
     * @brief Render one column
     * @called_by renderAdnotacje()
     */
    private function renderAdnotacjeColumn($items, $x, $w, $y)
    {
        $pdf = $this->pdf;
        $pdf->SetFont($this->fontFamily, '', $this->fontSizeSmall);
        foreach ($items as $e) {
            $html = ($e['label'] !== '' ? '<b>' . htmlspecialchars($e['label'], ENT_QUOTES, 'UTF-8') . '</b>' : '')
                . htmlspecialchars($e['text'], ENT_QUOTES, 'UTF-8');
            $pdf->writeHTMLCell($w, 0, $x, $y, $html, 0, 1);
            $y = $pdf->GetY();
        }
        return $y;
    }

    /**
     * @brief Render additional info section (Dodatkowe informacje)
     * @param $items Array of info items
     * @param $y Current Y position
     * @return float New Y position
     * @called_by generate()
     * @calls checkPageBreak(), drawLine()
     */
    private function renderAdditionalInfo($items, $y)
    {
        $pdf = $this->pdf;
        $y = $this->checkPageBreak($y, 15);
        $y = $this->drawLine($y);

        $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSection);
        $pdf->SetXY($this->marginLeft, $y);
        $pdf->Cell($this->contentWidth, 5, 'Dodatkowe informacje', 0, 1, 'L');
        $y += 5;

        $pdf->SetFont($this->fontFamily, '', $this->fontSizeSmall);
        foreach ($items as $item) {
            $pdf->SetXY($this->marginLeft, $y);
            $pdf->Cell($this->contentWidth, 4, $item, 0, 1, 'L');
            $y += 4;
        }

        return $y + 2;
    }


    /**
     * @brief Render additional description table (Dodatkowy opis)
     * @param $items Array of description items with rodzaj and tresc
     * @param $y Current Y position
     * @return float New Y position
     * @called_by generate()
     * @calls checkPageBreak()
     */
    private function renderAdditionalDesc($y)
    {
        $pdf = $this->pdf;
        $items = $this->parsed['additional_desc'] ?? array();
        $y = $this->checkPageBreak($y, 20);

        $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSmall);
        $pdf->SetXY($this->marginLeft, $y);
        $pdf->Cell($this->contentWidth, 4, 'Dodatkowy opis', 0, 1, 'L');
        $y += 5;

        $smallTableFont = 6;

        // Check for NrWiersza column
        $hasNrWiersza = false;
        foreach ($items as $item) {
            if (!empty($item['nr_wiersza'])) {
                $hasNrWiersza = true;
                break;
            }
        }

        if ($hasNrWiersza) {
            $colW = array(10, 22, 38, 120); // Lp, Numer wiersza, Rodzaj, Treść
            $headers = array('Lp.', 'Numer wiersza', 'Rodzaj informacji', 'Treść informacji');
        } else {
            $colW = array(10, 50, 130);
            $headers = array('Lp.', 'Rodzaj informacji', 'Treść informacji');
        }

        $pdf->SetFont($this->fontFamily, 'B', $smallTableFont);
        $pdf->SetFillColorArray($this->colorHeaderBg);
        $pdf->SetDrawColorArray($this->colorBorder);
        $pdf->SetLineWidth($this->borderWidth);
        $x = $this->marginLeft;
        foreach ($headers as $i => $h) {
            $pdf->SetXY($x, $y);
            $pdf->Cell($colW[$i], 5, $h, 1, 0, 'C', true);
            $x += $colW[$i];
        }
        $y += 5;

        $pdf->SetFont($this->fontFamily, '', $smallTableFont);
        $colIdxRodzaj = $hasNrWiersza ? 2 : 1;
        $colIdxTresc = $hasNrWiersza ? 3 : 2;
        $rowNum = 1;
        foreach ($items as $item) {
            $rodzaj = $item['key'] ?? '';
            $tresc = $item['value'] ?? '';

            $rodzajLines = $rodzaj !== '' ? $pdf->getNumLines($rodzaj, $colW[$colIdxRodzaj] - 2) : 1;
            $trescLines = $tresc !== '' ? $pdf->getNumLines($tresc, $colW[$colIdxTresc] - 2) : 1;
            $maxLines = max($rodzajLines, $trescLines, 1);
            $rowHeight = max(5, $maxLines * 4);

            $oldY = $y;
            $y = $this->checkPageBreak($y, $rowHeight + 2);
            if ($y < $oldY) {
                // Redraw header on new page
                $pdf->SetFont($this->fontFamily, 'B', $smallTableFont);
                $pdf->SetFillColorArray($this->colorHeaderBg);
                $pdf->SetDrawColorArray($this->colorBorder);
                $pdf->SetLineWidth($this->borderWidth);
                $x = $this->marginLeft;
                foreach ($headers as $i => $h) {
                    $pdf->SetXY($x, $y);
                    $pdf->Cell($colW[$i], 5, $h, 1, 0, 'C', true);
                    $x += $colW[$i];
                }
                $y += 5;
                $pdf->SetFont($this->fontFamily, '', $smallTableFont);
            }

            $x = $this->marginLeft;

            // Lp.
            $pdf->SetXY($x, $y);
            $pdf->Cell($colW[0], $rowHeight, $rowNum, 1, 0, 'C');
            $x += $colW[0];

            // NrWiersza
            if ($hasNrWiersza) {
                $nrPoz = !empty($item['nr_wiersza']) ? (string) $item['nr_wiersza'] : '';
                $pdf->SetXY($x, $y);
                $pdf->Cell($colW[1], $rowHeight, $nrPoz, 1, 0, 'C');
                $x += $colW[1];
            }

            // Rodzaj
            $pdf->SetXY($x, $y);
            $pdf->Cell($colW[$colIdxRodzaj], $rowHeight, '', 1, 0, 'L');
            $pdf->SetXY($x + 1, $y + 1);
            $pdf->MultiCell($colW[$colIdxRodzaj] - 2, 4, $rodzaj, 0, 'L', false, 0);
            $x += $colW[$colIdxRodzaj];

            // Treść
            $pdf->SetXY($x, $y);
            $pdf->Cell($colW[$colIdxTresc], $rowHeight, '', 1, 0, 'L');
            $pdf->SetXY($x + 1, $y + 1);
            $pdf->MultiCell($colW[$colIdxTresc] - 2, 4, $tresc, 0, 'L', false, 0);

            $y += $rowHeight;
            $rowNum++;
        }

        return $y + 3;
    }


    /**
     * @brief Render payment section (Płatność)
     * @param $incoming KsefIncoming object
     * @param $y Current Y position
     * @return float New Y position
     * @called_by generate()
     * @calls checkPageBreak(), drawLine(), renderLabelValue(), getPaymentMethodLabel(), renderBankAccounts()
     */
    private function renderPayment($incoming, $y)
    {
        $pdf = $this->pdf;
        $payment = $this->parsed['payment'] ?? array();
        if (empty($payment) && empty($incoming->payment_method)) return $y;

        $y = $this->checkPageBreak($y, 30);
        $y = $this->drawLine($y);

        $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSection);
        $pdf->SetXY($this->marginLeft, $y);
        $pdf->Cell($this->contentWidth, 5, 'Płatność', 0, 1, 'L');
        $y += 6;

        $pdf->SetFont($this->fontFamily, '', $this->fontSizeSmall);

        // Status
        if (!empty($payment['status'])) {
            $statusLabel = 'Brak zapłaty';
            if ($payment['status'] == 'paid') $statusLabel = 'Zapłacono';
            elseif ($payment['status'] == 'paid_installments') $statusLabel = 'Zapłacono w częściach';
            elseif ($payment['status'] == 'partial') $statusLabel = 'Zapłata częściowa';
            $y = $this->renderLabelValue($y, 'Informacja o płatności: ', $statusLabel);
        }

        // Payment date
        if (!empty($payment['payment_date'])) {
            $y = $this->renderLabelValue($y, 'Data zapłaty: ', $payment['payment_date']);
        }

        // Partial payments
        if (!empty($payment['partial_payments'])) {
            $y = $this->renderPartialPayments($payment['partial_payments'], $y);
        }

        // Payment method
        $method = $incoming->payment_method ?? $payment['method'] ?? null;
        if (!empty($method)) {
            $y = $this->renderLabelValue($y, 'Forma płatności: ', $this->getPaymentMethodLabel($method));
        }

        // Payment due date (ISO string) or description (relative terms)
        $dueDate = $payment['due_date'] ?? null;
        $dueDateDesc = $payment['due_date_description'] ?? null;
        if (!empty($dueDate) || !empty($dueDateDesc)) {
            $y = $this->checkPageBreak($y, 12);
            $boxWidth = 45;
            $boxX = $this->marginLeft + $this->contentWidth - $boxWidth;

            $pdf->SetDrawColorArray($this->colorBorder);
            $pdf->SetLineWidth($this->borderWidth);

            $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeTable);
            $pdf->SetFillColorArray($this->colorHeaderBg);
            $pdf->SetXY($boxX, $y);
            $pdf->Cell($boxWidth, 5, 'Termin płatności', 1, 1, 'C', true);
            $y += 5;

            $pdf->SetFont($this->fontFamily, '', $this->fontSizeTable);
            $pdf->SetXY($boxX, $y);
            $displayValue = !empty($dueDate) ? $this->formatDatePolish($dueDate) : $dueDateDesc;
            $pdf->Cell($boxWidth, 5, $displayValue, 1, 1, 'C', false);
            $y += 6;
        }

        //Bank accounts (can be multiple with faktor etc)
        $accounts = array();
        if (!empty($payment['bank_account'])) {
            $accounts[] = array('title' => 'Numer rachunku bankowego', 'data' => $payment);
        }
        if (!empty($payment['factor_bank_accounts'])) {
            foreach ($payment['factor_bank_accounts'] as $factorAcc) {
                $accounts[] = array('title' => 'Numer rachunku bankowego faktora', 'data' => $factorAcc);
            }
        }
        if (!empty($accounts)) {
            $y = $this->renderBankAccounts($accounts, $y);
        }

        return $y + 2;
    }


    /**
     * @brief Render partial/installment payment details table
     * @param $partialPayments Array of partial payment entries
     * @param $y Current Y position
     * @return float New Y position
     * @called_by renderPayment()
     */
    private function renderPartialPayments($partialPayments, $y)
    {
        $pdf = $this->pdf;

        $y = $this->checkPageBreak($y, 10 + count($partialPayments) * 6);

        $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSmall);
        $pdf->SetXY($this->marginLeft, $y);
        $pdf->Cell($this->contentWidth, 4, 'Zapłaty częściowe', 0, 1, 'L');
        $y += 5;

        $colW = array(15, 40, 40, 40); // Nr, Kwota, Data, Forma
        $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeTable);
        $pdf->SetFillColorArray($this->colorHeaderBg);
        $pdf->SetDrawColorArray($this->colorBorder);
        $pdf->SetLineWidth($this->borderWidth);

        $x = $this->marginLeft;
        $headers = array('Nr', 'Kwota', 'Data', 'Forma płatności');
        for ($i = 0; $i < 4; $i++) {
            $pdf->SetXY($x, $y);
            $pdf->Cell($colW[$i], 5, $headers[$i], 1, 0, 'C', true);
            $x += $colW[$i];
        }
        $y += 5;

        $pdf->SetFont($this->fontFamily, '', $this->fontSizeTable);
        $num = 1;
        foreach ($partialPayments as $partial) {
            $y = $this->checkPageBreak($y, 6);
            $x = $this->marginLeft;

            $pdf->SetXY($x, $y);
            $pdf->Cell($colW[0], 5, $num, 1, 0, 'C');
            $x += $colW[0];

            $pdf->SetXY($x, $y);
            $pdf->Cell($colW[1], 5, isset($partial['amount']) ? $partial['amount'] : '', 1, 0, 'R');
            $x += $colW[1];

            $pdf->SetXY($x, $y);
            $pdf->Cell($colW[2], 5, isset($partial['date']) ? $partial['date'] : '', 1, 0, 'C');
            $x += $colW[2];

            $pdf->SetXY($x, $y);
            if (isset($partial['method'])) {
                $methodLabel = $this->getPaymentMethodLabel($partial['method']);
            } elseif (isset($partial['method_description'])) {
                $methodLabel = $partial['method_description'];
            } else {
                $methodLabel = '';
            }
            $pdf->Cell($colW[3], 5, $methodLabel, 1, 0, 'C');

            $y += 5;
            $num++;
        }

        return $y + 2;
    }


    /** Bank-account fields
     *
     *
     * */
    private function bankAccountFields()
    {
        return array(
            array('label' => 'Pełny numer rachunku', 'key' => 'bank_account'),
            array('label' => 'Kod SWIFT', 'key' => 'bank_swift'),
            array('label' => 'Rachunek własny banku', 'key' => 'bank_own_account'),
            array('label' => 'Nazwa banku', 'key' => 'bank_name'),
            array('label' => 'Opis rachunku', 'key' => 'bank_description'),
        );
    }

    /** rendered height (mm)
     *
     *
     * */
    private function bankAccountHeight($data)
    {
        $rowCount = 0;
        foreach ($this->bankAccountFields() as $field) {
            if (!empty($data[$field['key']])) $rowCount++;
        }
        return $rowCount == 0 ? 0 : (6 + $rowCount * 5);
    }

    /**
     * @brief Render one or more bank-account blocks
     * @param array $accounts List of ['title' => ..., 'data' => bank-field array]
     * @param float $y Current Y position
     * @return float New Y position
     * @called_by renderPayment()
     */
    private function renderBankAccounts($accounts, $y)
    {
        $count = count($accounts);
        if ($count === 1) {
            $y = $this->checkPageBreak($y, $this->bankAccountHeight($accounts[0]['data']) + 2);
            return $this->renderOneBankAccount($accounts[0]['title'], $accounts[0]['data'], $this->marginLeft, $y, $this->contentWidth) + 2;
        }

        $gap = 6;
        $colW = ($this->contentWidth - $gap) / 2;
        $leftX = $this->marginLeft;
        $rightX = $this->marginLeft + $colW + $gap;

        for ($i = 0; $i < $count; $i += 2) {
            $hL = $this->bankAccountHeight($accounts[$i]['data']);
            $hR = isset($accounts[$i + 1]) ? $this->bankAccountHeight($accounts[$i + 1]['data']) : 0;
            $y = $this->checkPageBreak($y, max($hL, $hR) + 2);

            $yL = $this->renderOneBankAccount($accounts[$i]['title'], $accounts[$i]['data'], $leftX, $y, $colW);
            $yR = $y;
            if (isset($accounts[$i + 1])) {
                $yR = $this->renderOneBankAccount($accounts[$i + 1]['title'], $accounts[$i + 1]['data'], $rightX, $y, $colW);
            }
            $y = max($yL, $yR) + 2;
        }

        return $y;
    }

    /**
     * @brief Render a single bank-account block
     * @return float New Y position after the block
     * @called_by renderBankAccounts()
     */
    private function renderOneBankAccount($title, $data, $x, $y, $w)
    {
        $pdf = $this->pdf;
        $fields = $this->bankAccountFields();

        $rowCount = 0;
        foreach ($fields as $field) {
            if (!empty($data[$field['key']])) $rowCount++;
        }
        if ($rowCount == 0) return $y;

        $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSection);
        $pdf->SetXY($x, $y);
        $pdf->Cell($w, 5, $title, 0, 1, 'L');
        $y += 6;

        $labelW = min(45, round($w * 0.40));
        $valueW = $w - $labelW;

        $pdf->SetFillColorArray($this->colorHeaderBg);
        $pdf->SetDrawColorArray($this->colorBorder);
        $pdf->SetLineWidth($this->borderWidth);

        foreach ($fields as $field) {
            if (empty($data[$field['key']])) continue;
            $value = $data[$field['key']];
            if ($field['key'] === 'bank_account') $value = $this->formatBankAccount($value);
            $pdf->SetXY($x, $y);
            $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeTable);
            $pdf->Cell($labelW, 5, $field['label'], 1, 0, 'L', true);
            $pdf->SetFont($this->fontFamily, '', $this->fontSizeTable);
            $pdf->Cell($valueW, 5, $value, 1, 1, 'L');
            $y += 5;
        }

        return $y;
    }

    /**
     * @brief Format IBAN
     * @called_by renderOneBankAccount()
     */
    private function formatBankAccount($acct)
    {
        $acct = trim((string) $acct);
        $digits = preg_replace('/\s+/', '', $acct);
        if (!preg_match('/^\d{26}$/', $digits)) {
            return $acct;
        }
        return substr($digits, 0, 2) . ' ' . trim(chunk_split(substr($digits, 2), 4, ' '));
    }


    /**
     * @brief WarunkiTransakcji section should render
     * @return bool
     * @called_by generate()
     */
    private function hasWarunkiTransakcji()
    {
        $w = $this->parsed['warunki'] ?? array();
        if (empty($w)) return false;
        return !empty($w['umowy']) || !empty($w['zamowienia']) || !empty($w['partie'])
            || !empty($w['waluta_umowna']) || !empty($w['kurs_umowny']) || !empty($w['warunki_dostawy'])
            || !empty($w['podmiot_posredniczacy']) || !empty($w['transport']);
    }


    /**
     * @brief Render WarunkiTransakcji
     * @param $y Current Y position
     * @return float New Y position
     * @called_by generate()
     * @calls checkPageBreak(), drawLine(), renderWarunkiSubBlock(), renderMiniTable(),
     *         renderLabelValue(), renderTransport()
     */
    private function renderWarunkiTransakcji($y)
    {
        $pdf = $this->pdf;
        $w = $this->parsed['warunki'];

        $y = $this->checkPageBreak($y, 25);
        $y = $this->drawLine($y);

        $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSection);
        $pdf->SetXY($this->marginLeft, $y);
        $pdf->Cell($this->contentWidth, 5, 'Warunki transakcji', 0, 1, 'L');
        $y += 6;

        // Umowa / Zamówienie
        $hasUmowy = !empty($w['umowy']);
        $hasZam = !empty($w['zamowienia']);
        if ($hasUmowy || $hasZam) {
            $colGap = 6;
            $colW = ($this->contentWidth - $colGap) / 2;
            $rightX = $this->marginLeft + $colW + $colGap;
            $rows = (max($hasUmowy ? count($w['umowy']) : 0, $hasZam ? count($w['zamowienia']) : 0)) + 1;
            $y = $this->checkPageBreak($y, 6 + 5 * $rows);
            $yStart = $y;
            $yLeft = $y;
            $yRight = $y;
            if ($hasUmowy) {
                $yLeft = $this->renderWarunkiSubBlock($this->marginLeft, $yStart, $colW, 'Umowa',
                    array('Data umowy', 'Numer umowy'),
                    array_map(function ($u) { return array($this->formatDatePolish($u['data']), (string) $u['nr']); }, $w['umowy']));
            }
            if ($hasZam) {
                $yRight = $this->renderWarunkiSubBlock($rightX, $yStart, $colW, 'Zamówienie',
                    array('Data zamówienia', 'Numer zamówienia'),
                    array_map(function ($z) { return array($this->formatDatePolish($z['data']), (string) $z['nr']); }, $w['zamowienia']));
            }
            $y = max($yLeft, $yRight) + 3;
        }

        // Waluta umowna i kurs umowny
        if (!empty($w['waluta_umowna']) || !empty($w['kurs_umowny'])) {
            $y = $this->checkPageBreak($y, 18);
            $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSection);
            $pdf->SetXY($this->marginLeft, $y);
            $pdf->Cell($this->contentWidth, 5, 'Waluta umowna i kurs umowny', 0, 1, 'L');
            $y += 5;
            if (!empty($w['waluta_umowna'])) $y = $this->renderLabelValue($y, 'Waluta umowna: ', $w['waluta_umowna']);
            if (!empty($w['kurs_umowny'])) $y = $this->renderLabelValue($y, 'Kurs umowny: ', $w['kurs_umowny']);
            $y += 2;
        }

        // NrPartiiTowaru
        if (!empty($w['partie'])) {
            $colW = ($this->contentWidth - 6) / 2;
            $batchRows = array_map(function ($p) { return array($p); }, $w['partie']);
            $y = $this->checkPageBreak($y, 5 * (count($batchRows) + 1) + 3);
            $y = $this->renderMiniTable($this->marginLeft, $y, $colW, array('Numer partii towaru'), $batchRows) + 3;
        }

        // Warunki dostawy
        if (!empty($w['warunki_dostawy'])) {
            $y = $this->checkPageBreak($y, 8);
            $y = $this->renderLabelValue($y, 'Warunki dostawy towarów: ', $w['warunki_dostawy']) + 2;
        }

        // Podmiot posredniczacy
        if (!empty($w['podmiot_posredniczacy'])) {
            $y = $this->checkPageBreak($y, 14);
            $pdf->SetFont($this->fontFamily, '', $this->fontSizeSmall);
            $text = 'Dostawa dokonana przez podmiot, o którym mowa w art. 22 ust. 2d ustawy. Pole dotyczy sytuacji, w której podmiot uczestniczy w transakcji łańcuchowej innej niż procedura trójstronna uproszczona, o której mowa w art. 135 ust. 1 pkt 4 ustawy';
            $pdf->SetXY($this->marginLeft, $y);
            $pdf->MultiCell($this->contentWidth, 4, $text, 0, 'L');
            $y = $pdf->GetY() + 2;
        }

        // Transport
        if (!empty($w['transport'])) {
            $multi = count($w['transport']) > 1;
            foreach ($w['transport'] as $i => $t) {
                $y = $this->renderTransport($y, $t, $multi ? ($i + 1) : null);
            }
        }

        return $y + 2;
    }


    /**
     * @brief Render a labelled sub-block
     * @param $x Column X
     * @param $y Y position
     * @param $w Column width
     * @param $title Sub-header title
     * @param $headers Table header labels
     * @param $rows Table rows
     * @return float New Y position
     * @called_by renderWarunkiTransakcji()
     * @calls renderMiniTable()
     */
    private function renderWarunkiSubBlock($x, $y, $w, $title, $headers, $rows)
    {
        $pdf = $this->pdf;
        $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSmall);
        $pdf->SetXY($x, $y);
        $pdf->Cell($w, 5, $title, 0, 1, 'L');
        return $this->renderMiniTable($x, $y + 5, $w, $headers, $rows);
    }


    /**
     * @brief Render a bordered mini-table
     * @param $x Left X
     * @param $y Y position
     * @param $w Total width
     * @param $headers Array of column header labels
     * @param $rows Array of rows (each an array of cell strings)
     * @return float New Y position
     * @called_by renderWarunkiTransakcji(), renderWarunkiSubBlock()
     */
    private function renderMiniTable($x, $y, $w, $headers, $rows)
    {
        $pdf = $this->pdf;
        $n = count($headers);
        if ($n === 0) return $y;
        $cw = $w / $n;

        $pdf->SetDrawColorArray($this->colorBorder);
        $pdf->SetLineWidth($this->borderWidth);

        // Header row
        $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeTable);
        $pdf->SetFillColorArray($this->colorHeaderBg);
        $cx = $x;
        foreach ($headers as $h) {
            $pdf->SetXY($cx, $y);
            $pdf->Cell($cw, 5, $h, 1, 0, 'L', true);
            $cx += $cw;
        }
        $y += 5;

        // Value rows
        $pdf->SetFont($this->fontFamily, '', $this->fontSizeTable);
        foreach ($rows as $row) {
            $cx = $x;
            foreach ($row as $cell) {
                $pdf->SetXY($cx, $y);
                $pdf->Cell($cw, 5, (string) $cell, 1, 0, 'L');
                $cx += $cw;
            }
            $y += 5;
        }

        return $y;
    }


    /**
     * @brief Render a single Transport
     * @param $y Current Y position
     * @param $t Transport data array
     * @param $index 1-based index when multiple, null
     * @return float New Y position
     * @called_by renderWarunkiTransakcji()
     * @calls checkPageBreak(), renderLabelValue(), renderTransportCarrier(), renderTransportAdres()
     */
    private function renderTransport($y, $t, $index)
    {
        $pdf = $this->pdf;
        $y = $this->checkPageBreak($y, 20);
        $y = $this->drawLine($y);

        $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSection);
        $pdf->SetXY($this->marginLeft, $y);
        $pdf->Cell($this->contentWidth, 5, 'Transport' . ($index ? ' ' . $index : ''), 0, 1, 'L');
        $y += 5;

        $colGap = 6;
        $colW = ($this->contentWidth - $colGap) / 2;
        $rightX = $this->marginLeft + $colW + $colGap;

        // Rodzaj transportu
        $leftItems = array();
        if (!empty($t['rodzaj'])) {
            $leftItems[] = array('Rodzaj transportu: ', $this->getTransportTypeName($t['rodzaj']));
        } elseif (($t['transport_inny'] ?? '') === '1' && !empty($t['opis_innego_transportu'])) {
            $leftItems[] = array('Rodzaj transportu: ', 'Transport inny');
            $leftItems[] = array('Opis innego rodzaju transportu: ', $t['opis_innego_transportu']);
        }

        // Dane transportu
        $rightItems = array();
        if (!empty($t['nr_zlecenia'])) $rightItems[] = array('Numer zlecenia transportu: ', $t['nr_zlecenia']);
        if (!empty($t['opis_ladunku'])) {
            $rightItems[] = array('Opis ładunku: ', $this->getCargoTypeName($t['opis_ladunku']));
            if (($t['ladunek_inny'] ?? '') === '1' && !empty($t['opis_innego_ladunku'])) {
                $rightItems[] = array('Opis ładunku: ', 'Ładunek inny');
                $rightItems[] = array('Opis innego ładunku: ', $t['opis_innego_ladunku']);
            }
        }
        if (!empty($t['jednostka_opakowania'])) $rightItems[] = array('Jednostka opakowania: ', $t['jednostka_opakowania']);
        if (!empty($t['data_rozp'])) $rightItems[] = array('Data i godzina rozpoczęcia transportu: ', $this->formatDateTimePolish($t['data_rozp']));
        if (!empty($t['data_zak'])) $rightItems[] = array('Data i godzina zakończenia transportu: ', $this->formatDateTimePolish($t['data_zak']));

        if (!empty($leftItems) || !empty($rightItems)) {
            $yStart = $y;
            $yL = $yStart;
            $yR = $yStart;
            foreach ($leftItems as $it) {
                $yL = $this->renderLabelValueAt($this->marginLeft, $yL, $colW, $it[0], $it[1]);
            }
            if (!empty($rightItems)) {
                $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSmall);
                $pdf->SetXY($rightX, $yR);
                $pdf->Cell($colW, 4, 'Dane transportu', 0, 1, 'L');
                $yR += 4;
                foreach ($rightItems as $it) {
                    $yR = $this->renderLabelValueAt($rightX, $yR, $colW, $it[0], $it[1]);
                }
            }
            $y = max($yL, $yR) + 3;
        }

        // Carrier
        if (!empty($t['przewoznik'])) {
            $y = $this->renderTransportCarrier($y, $t['przewoznik']);
        }

        // Addresses
        $z = $t['wysylka_z'] ?? null;
        $do = $t['wysylka_do'] ?? null;
        $przez = $t['wysylka_przez'] ?? array();
        if (!empty($z) || !empty($do) || !empty($przez)) {
            $y = $this->checkPageBreak($y, 18);
            $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSection);
            $pdf->SetXY($this->marginLeft, $y);
            $pdf->Cell($this->contentWidth, 5, 'Wysyłka', 0, 1, 'L');
            $y += 5;

            $yStart = $y;
            $yL = $yStart;
            $yR = $yStart;
            // WysylkaZ/WysylkaPrzez
            if (!empty($z)) $yL = $this->renderTransportAdres($this->marginLeft, $yL, $colW, 'Adres miejsca wysyłki', $z);
            foreach ($przez as $adr) {
                $yL += 2;
                $yL = $this->renderTransportAdres($this->marginLeft, $yL, $colW, 'Adres pośredni wysyłki', $adr);
            }
            // WysylkaDo
            if (!empty($do)) $yR = $this->renderTransportAdres($rightX, $yR, $colW, 'Adres miejsca docelowego, do którego został zlecony transport', $do);
            $y = max($yL, $yR) + 2;
        }

        return $y + 2;
    }


    /**
     * @brief Render transport carrier
     * @param $y Current Y position
     * @param $p Carrier data array
     * @return float New Y position
     * @called_by renderTransport()
     * @calls renderLabelValue(), renderTransportAdres()
     */
    private function renderTransportCarrier($y, $p)
    {
        $pdf = $this->pdf;
        $y = $this->checkPageBreak($y, 16);

        $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSection);
        $pdf->SetXY($this->marginLeft, $y);
        $pdf->Cell($this->contentWidth, 5, 'Przewoźnik', 0, 1, 'L');
        $y += 5;

        $colGap = 6;
        $colW = ($this->contentWidth - $colGap) / 2;
        $rightX = $this->marginLeft + $colW + $colGap;
        $yStart = $y;
        $yL = $yStart;
        $yR = $yStart;

        // identity
        if (!empty($p['nip'])) $yL = $this->renderLabelValueAt($this->marginLeft, $yL, $colW, 'NIP: ', $p['nip']);
        if (!empty($p['nr_vat_ue'])) $yL = $this->renderLabelValueAt($this->marginLeft, $yL, $colW, 'Numer VAT UE: ', trim(($p['kod_ue'] ?? '') . ' ' . $p['nr_vat_ue']));
        if (!empty($p['nr_id'])) $yL = $this->renderLabelValueAt($this->marginLeft, $yL, $colW, 'Identyfikator podatkowy inny: ', trim(($p['kod_kraju'] ?? '') . ' ' . $p['nr_id']));
        if (($p['brak_id'] ?? '') === '1') $yL = $this->renderLabelValueAt($this->marginLeft, $yL, $colW, 'Brak identyfikatora podatkowego', '');
        if (!empty($p['nazwa'])) $yL = $this->renderLabelValueAt($this->marginLeft, $yL, $colW, 'Nazwa: ', $p['nazwa']);

        // address
        if (!empty($p['adres'])) {
            $yR = $this->renderTransportAdres($rightX, $yR, $colW, 'Adres przewoźnika', $p['adres']);
        }

        return max($yL, $yR) + 2;
    }


    /**
     * @brief Render a single address block
     * @param $x Column X
     * @param $y Y position
     * @param $w Column width
     * @param $title Sub-header title
     * @param $adr Address data array
     * @return float New Y position
     * @called_by renderTransport(), renderTransportCarrier()
     */
    private function renderTransportAdres($x, $y, $w, $title, $adr)
    {
        $pdf = $this->pdf;
        $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSmall);
        $pdf->SetXY($x, $y);
        $pdf->MultiCell($w, 4, $title, 0, 'L');
        $y = $pdf->GetY();

        $pdf->SetFont($this->fontFamily, '', $this->fontSizeSmall);
        foreach (array($adr['l1'] ?? '', $adr['l2'] ?? '') as $line) {
            if ($line === '') continue;
            $pdf->SetXY($x, $y);
            $pdf->MultiCell($w, 4, $line, 0, 'L');
            $y = $pdf->GetY();
        }
        if (!empty($adr['kod_kraju'])) {
            $pdf->SetXY($x, $y);
            $pdf->MultiCell($w, 4, $this->getCountryName($adr['kod_kraju']), 0, 'L');
            $y = $pdf->GetY();
        }
        if (!empty($adr['gln'])) {
            $y = $this->renderLabelValueAt($x, $y, $w, 'GLN: ', $adr['gln']);
        }
        return $y;
    }


    /**
     * @brief Map RodzajTransportu to Polish
     * @param $code Transport type code
     * @return string Name (or raw code if unknown)
     * @called_by renderTransport()
     */
    private function getTransportTypeName($code)
    {
        $map = array(
            '1' => 'Transport morski',
            '2' => 'Transport kolejowy',
            '3' => 'Transport drogowy',
            '4' => 'Transport lotniczy',
            '5' => 'Przesyłka pocztowa',
            '7' => 'Stałe instalacje przesyłowe',
            '8' => 'Żegluga śródlądowa',
        );
        return $map[$code] ?? $code;
    }


    /**
     * @brief Map OpisLadunku to Polish
     * @param $code Cargo type code
     * @return string Name (or raw code if unknown)
     * @called_by renderTransport()
     */
    private function getCargoTypeName($code)
    {
        $map = array(
            '1' => 'Bańka', '2' => 'Beczka', '3' => 'Butla', '4' => 'Karton', '5' => 'Kanister',
            '6' => 'Klatka', '7' => 'Kontener', '8' => 'Kosz/koszyk', '9' => 'Łubianka',
            '10' => 'Opakowanie zbiorcze', '11' => 'Paczka', '12' => 'Pakiet', '13' => 'Paleta',
            '14' => 'Pojemnik', '15' => 'Pojemnik do ładunków masowych stałych',
            '16' => 'Pojemnik do ładunków masowych w postaci płynnej', '17' => 'Pudełko',
            '18' => 'Puszka', '19' => 'Skrzynia', '20' => 'Worek',
        );
        return $map[$code] ?? $code;
    }


    /**
     * @brief Format an ISO dateTime
     * @param $value ISO dateTime string
     * @return string Formatted value
     * @called_by renderTransport()
     */
    private function formatDateTimePolish($value)
    {
        if (empty($value)) return '';
        try {
            $dt = new DateTime($value);
            $dt->setTimezone(new DateTimeZone('Europe/Warsaw'));
            return $dt->format('d.m.Y H:i');
        } catch (Exception $e) {
            $ts = strtotime($value);
            return $ts ? date('d.m.Y H:i', $ts) : $value;
        }
    }


    /**
     * @brief Render registries section (KRS, REGON, BDO)
     * @param $registries Registry data array
     * @param $y Current Y position
     * @return float New Y position
     * @called_by generate()
     * @calls checkPageBreak(), drawLine()
     */
    private function renderRegistries($y)
    {
        $pdf = $this->pdf;
        $registries = $this->parsed['registries'];

        // Populated columns
        $cols = array();
        if (!empty($registries['krs'])) $cols[] = array('label' => 'KRS', 'value' => $registries['krs']);
        if (!empty($registries['regon'])) $cols[] = array('label' => 'REGON', 'value' => $registries['regon']);
        if (!empty($registries['bdo'])) $cols[] = array('label' => 'BDO', 'value' => $registries['bdo']);

        if (empty($cols)) return $y;

        $y = $this->checkPageBreak($y, 20);
        $y = $this->drawLine($y);

        $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSection);
        $pdf->SetXY($this->marginLeft, $y);
        $pdf->Cell($this->contentWidth, 5, 'Rejestry', 0, 1, 'L');
        $y += 6;

        $colW = 35;

        $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeTable);
        $pdf->SetFillColorArray($this->colorHeaderBg);
        $pdf->SetDrawColorArray($this->colorBorder);
        $pdf->SetLineWidth($this->borderWidth);

        // Header row
        $x = $this->marginLeft;
        foreach ($cols as $col) {
            $pdf->SetXY($x, $y);
            $pdf->Cell($colW, 5, $col['label'], 1, 0, 'L', true);
            $x += $colW;
        }
        $y += 5;

        // Value row
        $pdf->SetFont($this->fontFamily, '', $this->fontSizeTable);
        $x = $this->marginLeft;
        foreach ($cols as $col) {
            $pdf->SetXY($x, $y);
            $pdf->Cell($colW, 5, $col['value'], 1, 0, 'L');
            $x += $colW;
        }
        $y += 5;

        return $y + 3;
    }


    /**
     * @brief Render Stopka info section
     * @param $stopkaInfo Stopka faktury text value
     * @param $y Current Y position
     * @return float New Y position
     * @called_by generate()
     * @calls checkPageBreak(), drawLine()
     */
    private function renderStopkaInfo($y)
    {
        $stopkaItems = $this->parsed['stopka'];
        if (empty($stopkaItems)) {
            return $y;
        }

        $pdf = $this->pdf;
        $y = $this->checkPageBreak($y, 25);
        $y = $this->drawLine($y);

        $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSection);
        $pdf->SetXY($this->marginLeft, $y);
        $pdf->Cell($this->contentWidth, 5, 'Pozostałe informacje', 0, 1, 'L');
        $y += 5 + $this->spaceSectionAfter;

        $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSmall);
        $pdf->SetFillColorArray($this->colorHeaderBg);
        $pdf->SetXY($this->marginLeft, $y);
        $pdf->Cell($this->contentWidth, 5, 'Stopka faktury', 1, 1, 'L', true);
        $y += 5;

        $pdf->SetFont($this->fontFamily, '', $this->fontSizeSmall);
        $entries = is_array($stopkaItems) ? $stopkaItems : array($stopkaItems);
        $text = implode("\n", $entries);
        $pdf->SetXY($this->marginLeft, $y);
        $pdf->MultiCell($this->contentWidth, 4, $text, 1, 'L');
        $y = $pdf->GetY();
        $y += $this->spaceSectionAfter;

        return $y;
    }


    /**
     * @brief Render QR code section with verification URL
     * @param $incoming KsefIncoming object
     * @param $y Current Y position
     * @return float New Y position
     * @called_by generate()
     * @calls renderQrCodeOffline(), getVerificationUrl(), checkPageBreak(), drawLine()
     */
    private function renderQrCode($incoming, $y)
    {
        $pdf = $this->pdf;

        $isOffline = !empty($incoming->offline_mode) && (
            empty($incoming->ksef_number) ||
            strpos($incoming->ksef_number, 'OFFLINE') !== false
        );

        if ($isOffline) {
            return $this->renderQrCodeOffline($incoming, $y);
        }

        $verificationUrl = $this->getVerificationUrl($incoming);
        if (empty($verificationUrl) && empty($incoming->ksef_number)) return $y;

        $y = $this->checkPageBreak($y, 55);
        $y = $this->drawLine($y);

        $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSection);
        $pdf->SetXY($this->marginLeft, $y);
        $pdf->Cell($this->contentWidth, 5, 'Sprawdź, czy Twoja faktura znajduje się w KSeF!', 0, 1, 'L');
        $y += 6;

        $qrSize = 40;
        $textX = $this->marginLeft + $qrSize + 5;
        $textWidth = $this->contentWidth - $qrSize - 5;

        // QR code
        if (!empty($verificationUrl)) {
            try {
                $pdf->write2DBarcode($verificationUrl, 'QRCODE,M', $this->marginLeft, $y, $qrSize, $qrSize);
            } catch (Exception $e) {
                dol_syslog("KsefInvoicePdf::renderQrCode QR error: " . $e->getMessage(), LOG_WARNING);
            }
        }

        if (!empty($incoming->ksef_number)) {
            $pdf->SetFont($this->fontFamily, '', 5);
            $ksefNum = $incoming->ksef_number;
            if (strlen($ksefNum) > 35) {
                $part1 = substr($ksefNum, 0, 35);
                $part2 = substr($ksefNum, 35);
                $pdf->SetXY($this->marginLeft, $y + $qrSize + 1);
                $pdf->Cell($qrSize, 2.5, $part1, 0, 1, 'C');
                $pdf->SetXY($this->marginLeft, $y + $qrSize + 3.5);
                $pdf->Cell($qrSize, 2.5, $part2, 0, 1, 'C');
            } else {
                $pdf->SetXY($this->marginLeft, $y + $qrSize + 1);
                $pdf->Cell($qrSize, 3, $ksefNum, 0, 1, 'C');
            }
        }

        $pdf->SetFont($this->fontFamily, '', $this->fontSizeSmall);
        $pdf->SetXY($textX, $y);
        $pdf->Cell($textWidth, 4, 'Nie możesz zeskanować kodu z obrazka? Kliknij w link weryfikacyjny i przejdź do weryfikacji faktury!', 0, 1, 'L');

        if (!empty($verificationUrl)) {
            $pdf->SetTextColorArray($this->colorLink);
            $pdf->SetXY($textX, $y + 6);
            $pdf->MultiCell($textWidth, 4, $verificationUrl, 0, 'L');
            $pdf->SetTextColorArray($this->colorText);
        }

        return $y + $qrSize + 10;
    }


    /**
     * @brief Render offline QR code section
     * @param $incoming Invoice data object
     * @param $y Current Y position
     * @return float New Y position
     * @called_by renderQrCode()
     * @calls checkPageBreak(), drawLine(), ksefGetVerificationURL(), ksefLoadOfflineCertificate(), ksefSignData()
     */
    private function renderQrCodeOffline($incoming, $y)
    {
        global $conf, $mysoc;

        dol_include_once('/ksef/lib/ksef.lib.php');

        $pdf = $this->pdf;

        $y = $this->checkPageBreak($y, 60);
        $y = $this->drawLine($y);

        // Section title
        $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSection);
        $pdf->SetXY($this->marginLeft, $y);
        $pdf->Cell($this->contentWidth, 5, 'Sprawdź, czy Twoja faktura znajduje się w KSeF!', 0, 1, 'L');
        $y += 6;

        $qrSize = 35;
        $spacing = 6;

        // Determine seller NIP
        $nip = '';
        if (!empty($conf->global->KSEF_COMPANY_NIP)) {
            $nip = ksefCleanNIP($conf->global->KSEF_COMPANY_NIP);
        }
        if (empty($nip) && !empty($incoming->seller_nip)) {
            $nip = ksefCleanNIP($incoming->seller_nip);
        }
        if (empty($nip) && is_object($mysoc)) {
            $nip = ksefCleanNIP(ksefGetIdentifierField($mysoc, 'NIP'));
        }

        $invoiceHash = $incoming->invoice_hash ?? '';
        $environment = $incoming->environment ?? 'TEST';
        $invoiceDate = $incoming->invoice_date ?? dol_now();

        // KOD I (verification URL)
        $qr1X = $this->marginLeft;
        $qr1Url = '';
        if (!empty($nip) && !empty($invoiceHash)) {
            try {
                $qr1Url = ksefGetVerificationURL(null, $invoiceHash, $environment, $nip, $invoiceDate);
            } catch (Exception $e) {
                dol_syslog("KsefInvoicePdf::renderQrCodeOffline QR I error: " . $e->getMessage(), LOG_WARNING);
            }
        }

        if (!empty($qr1Url)) {
            try {
                $pdf->write2DBarcode($qr1Url, 'QRCODE,H', $qr1X, $y, $qrSize, $qrSize);
            } catch (Exception $e) {
                dol_syslog("KsefInvoicePdf::renderQrCodeOffline QR OFFLINE error: " . $e->getMessage(), LOG_WARNING);
            }
        }
        $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSmall);
        $pdf->SetTextColorArray($this->colorText);
        $pdf->SetXY($qr1X, $y + $qrSize + 1);
        $pdf->Cell($qrSize, 3, 'OFFLINE', 0, 0, 'C');

        // KOD II (certificate verification)
        $qr2X = $qr1X + $qrSize + $spacing;
        $qr2Url = '';
        if (!empty($nip) && !empty($invoiceHash)) {
            $credentials = ksefLoadOfflineCertificate();
            if ($credentials) {
                $hashBase64URL = ksefBase64ToBase64URL($invoiceHash);
                $signature = ksefSignData($invoiceHash, $credentials['private_key_pem'], true);
                if ($signature) {
                    $base_url = ksefGetQrBaseUrl($environment);
                    $certSerial = $credentials['serial'];
                    $qr2Url = "{$base_url}/certificate/nip/{$nip}/{$nip}/{$certSerial}/{$hashBase64URL}/{$signature}";
                }
            }
        }

        $qr2Rendered = false;
        if (!empty($qr2Url)) {
            try {
                $pdf->write2DBarcode($qr2Url, 'QRCODE,H', $qr2X, $y, $qrSize, $qrSize);
                $qr2Rendered = true;
            } catch (Exception $e) {
                dol_syslog("KsefInvoicePdf::renderQrCodeOffline QR CERTYFIKAT error: " . $e->getMessage(), LOG_WARNING);
            }
        }
        if (!$qr2Rendered) {
            // Placeholder box
            $pdf->SetDrawColorArray(array(200, 200, 200));
            $pdf->SetFillColorArray(array(248, 248, 248));
            $pdf->Rect($qr2X, $y, $qrSize, $qrSize, 'DF');
            $pdf->SetFont($this->fontFamily, '', $this->fontSizeSmall);
            $pdf->SetTextColor(150, 150, 150);
            $pdf->SetXY($qr2X, $y + ($qrSize / 2) - 2);
            $pdf->Cell($qrSize, 3, 'Brak certyfikatu', 0, 1, 'C');
            $pdf->SetDrawColorArray($this->colorLine);
        }
        $pdf->SetFont($this->fontFamily, 'B', $this->fontSizeSmall);
        $pdf->SetTextColorArray($this->colorText);
        $pdf->SetXY($qr2X, $y + $qrSize + 1);
        $pdf->Cell($qrSize, 3, 'CERTYFIKAT', 0, 0, 'C');
        $textX = $qr2X + $qrSize + 5;
        $textWidth = $this->contentWidth - ($textX - $this->marginLeft);
        if ($textWidth > 20) {
            $pdf->SetFont($this->fontFamily, '', $this->fontSizeSmall);
            $pdf->SetXY($textX, $y);
            $pdf->Cell($textWidth, 4, 'Nie możesz zeskanować kodu z obrazka? Kliknij w link weryfikacyjny i przejdź do weryfikacji faktury!', 0, 1, 'L');

            if (!empty($qr1Url)) {
                $pdf->SetFont($this->fontFamily, '', 5);
                $pdf->SetTextColorArray($this->colorLink);
                $pdf->SetXY($textX, $pdf->GetY() + 1);
                $pdf->MultiCell($textWidth, 3, $qr1Url, 0, 'L');
                $pdf->SetTextColorArray($this->colorText);
            }
        }

        return $y + $qrSize + 10;
    }


    /**
     * @brief Render footer
     * @called_by generate()
     */
    private function renderFooter($pageNum = 0, $totalPages = 0)
    {
        $pdf = $this->pdf;
        $footerY = $this->pageHeight - $this->marginBottom + 2;

        $pdf->SetDrawColorArray($this->colorLine);
        $pdf->SetLineWidth(0.3);
        $pdf->Line($this->marginLeft, $footerY - 4, $this->marginLeft + $this->contentWidth, $footerY - 4);

        $pdf->SetFont($this->fontFamily, '', $this->fontSizeTable);

        $systemInfo = $this->parsed['header']['system_info'] ?? null;
        if (!empty($systemInfo)) {
            $pdf->SetXY($this->marginLeft, $footerY - 3);
            $pdf->Cell($this->contentWidth, 3, 'Wytworzona w:' . $systemInfo, 0, 1, 'L');
        }

        $pdf->SetXY($this->marginLeft, $footerY);
        $pdf->SetFont($this->fontFamily, '', $this->fontSizeTable);
        $pdf->Cell($this->contentWidth, 3, 'Wizualizacja danych XML wygenerowana przez Dolibarr z użyciem TCPDF', 0, 0, 'L');

        // Page numbering
        if ($pageNum > 0 && $totalPages > 0) {
            $pdf->SetXY($this->marginLeft, $footerY);
            $pdf->Cell($this->contentWidth, 3, $pageNum . ' z ' . $totalPages, 0, 0, 'R');
        }
    }


    /**
     * @brief Check if any line has a specific field populated
     * @param $lines Array of line items
     * @param $field Field name to check
     * @return bool True if at least one line has the field
     * @called_by renderPositionsTable(), renderGtinTable()
     */
    private function hasAnyField($lines, $field)
    {
        foreach ($lines as $line) {
            if (!empty($line[$field])) return true;
        }
        return false;
    }


    /**
     * @brief Format NIP
     * @param $nip NIP string
     * @return string Cleaned NIP
     * @called_by renderEntity()
     */
    private function formatNIP($nip)
    {
        return preg_replace('/[^0-9]/', '', $nip);
    }


    /**
     * @brief Format money
     * @param $value Numeric value
     * @return string Formatted money string
     * @called_by renderPositionsTable(), renderTotalAmount(), renderVatSummary()
     */
    private function formatDatePolish($date)
    {
        if (empty($date)) return '';
        if (is_numeric($date)) {
            return date('d.m.Y', $date);
        }
        // ISO date YYYY-MM-DD
        $ts = strtotime($date);
        return $ts ? date('d.m.Y', $ts) : $date;
    }


    private function formatMoney($value)
    {
        if ($value === null || $value === '') return '';
        // Use comma as decimal separator (Polish format)
        return number_format((float)$value, 2, ',', ' ');
    }


    /**
     * @brief Format a percentage with comma separator
     * @param $value Numeric percentage (XML dot-decimal)
     * @return string Formatted percentage string with % suffix
     * @called_by renderThirdParty()
     */
    private function formatPercent($value)
    {
        if ($value === null || $value === '') return '';
        return rtrim(rtrim(number_format((float) str_replace(',', '.', (string) $value), 2, ',', ''), '0'), ',') . '%';
    }


    /**
     * @brief Format quantity
     * @param $value Numeric value
     * @return string Formatted quantity string
     * @called_by renderPositionsTable()
     */
    private function formatQuantity($value)
    {
        if ($value === null || $value === '') return '';
        $num = (float)$value;
        return number_format($num, 2, ',', ' ');
    }


    /**
     * @brief Get country name from code
     * @param $code Country code (e.g., 'PL')
     * @return string Country name in Polish
     * @called_by renderEntity()
     */
    private function getCountryName($code)
    {
        $code = strtoupper(trim($code));
        return $this->countryNames[$code] ?? $code;
    }


    /**
     * @brief Parse address into display lines
     * @param $address Address string
     * @return array Array of address lines
     * @called_by renderEntity()
     */
    private function parseAddressLines($address)
    {
        if (strpos($address, "\n") !== false) {
            return array_filter(explode("\n", $address));
        }
        if (preg_match('/^(.+?),\s*(\d{2}-\d{3}\s+.+)$/', $address, $m)) {
            return array(trim($m[1]), trim($m[2]));
        }
        return array($address);
    }


    /**
     * @brief Get invoice type label in Polish
     * @param $type Invoice type code (VAT, ZAL, KOR, etc.)
     * @return string Invoice type label
     * @called_by renderHeader()
     */
    private function getInvoiceTypeLabel($type)
    {
        $labels = array(
            'VAT' => 'Faktura podstawowa',
            'ZAL' => 'Faktura zaliczkowa',
            'KOR' => 'Faktura korygująca',
            'ROZ' => 'Faktura rozliczeniowa',
            'UPR' => 'Faktura uproszczona',
            'KOR_ZAL' => 'Faktura korygująca zaliczkowa',
            'KOR_ROZ' => 'Faktura korygująca rozliczeniowa',
        );
        return $labels[$type] ?? $type;
    }


    /**
     * @brief Get correction type label in Polish
     * @param $type Correction type code
     * @return string Correction type label
     * @called_by renderCorrectionData()
     */
    private function getCorrectionTypeLabel($type)
    {
        $labels = array(
            '1' => 'Korekta skutkująca w dacie ujęcia faktury pierwotnej',
            '2' => 'Korekta skutkująca w bieżącym okresie rozliczeniowym',
            '3' => 'Korekta skutkująca w dacie innej, w tym gdy dla różnych pozycji faktury korygującej daty te są różne',
        );
        return $labels[$type] ?? $type;
    }


    /**
     * @brief Get VAT rate label
     * @param $rate VAT rate code
     * @return string VAT rate label
     * @called_by renderVatSummary()
     */
    private function getVatRateLabel($rate, $short = false)
    {
        if ($short) {
            $labels = array(
                '23' => '23%', '22' => '22%', '8' => '8%', '7' => '7%',
                '5' => '5%', '4' => '4%', '3' => '3%', '0' => '0%',
                '0 KR' => '0% KR', '0 WDT' => '0% WDT', '0 EX' => '0% EX',
                'zw' => 'zw', 'np' => 'np', 'np I' => 'np I', 'np II' => 'np II',
                'oo' => 'oo',
            );
        } else {
            $labels = array(
                '23' => '23% lub 22%', '22' => '22% lub 23%', '8' => '8% lub 7%', '7' => '7% lub 8%',
                '5' => '5%',
                '4' => '4% (ryczalt)',
                '3' => '3%',
                '0' => '0%',
                '0 KR' => '0% (krajowe)',
                '0 WDT' => '0% (WDT)',
                '0 EX' => '0% (eksport)',
                'zw' => 'Zwolnione od podatku',
                'np' => 'Niepodlegajace',
                'np I' => 'Niepodlegajace (I)',
                'np II' => 'Niepodlegajace (II)',
                'oo' => 'Odwrotne obciazenie',
            );
        }
        return $labels[$rate] ?? $rate;
    }


    /**
     * @brief Get payment method label in Polish
     * @param $method Payment method code
     * @return string Payment method label
     * @called_by renderPayment()
     */
    private function getPaymentMethodLabel($method)
    {
        $labels = array(
            '1' => 'Gotówka', '2' => 'Karta', '3' => 'Bon',
            '4' => 'Czek', '5' => 'Kredyt', '6' => 'Przelew', '7' => 'Płatność mobilna',
        );
        return $labels[$method] ?? 'Inna';
    }


    /**
     * @brief Get KSeF verification URL
     * @param $incoming KsefIncoming object
     * @return string Verification URL or empty string
     * @called_by renderQrCode()
     */
    private function getVerificationUrl($incoming)
    {
        if (!empty($incoming->fa3_xml) && !empty($incoming->ksef_number)) {
            dol_include_once('/ksef/lib/ksef.lib.php');
            return ksefGetVerificationUrlFromXml($incoming->ksef_number, $incoming->fa3_xml);
        }

        return '';
    }


    /**
     * @brief Return empty parsed structure matching FA3Parser output shape for error cases
     * @return array Empty data structure
     * @called_by generate()
     */
    private function getEmptyParsed()
    {
        return array(
            'header' => array('creation_date' => null, 'system_info' => null),
            'seller' => array('nip' => '', 'name' => '', 'country' => 'PL', 'address' => '', 'email' => null, 'phone' => null),
            'buyer' => array('nip' => '', 'name' => '', 'country' => 'PL', 'address' => '', 'jst' => null, 'gv' => null, 'email' => null, 'phone' => null, 'customer_number' => null, 'kod_ue' => null, 'nr_vat_ue' => null),
            'buyer_before' => null,
            'third_parties' => array(),
            'invoice' => array('currency' => 'PLN', 'number' => '', 'type' => 'VAT', 'date' => null, 'sale_date' => null, 'total_net' => 0, 'total_vat' => 0, 'total_gross' => 0, 'place_of_issue' => null, 'period_from' => null, 'period_to' => null, 'fp_flag' => false, 'p_16_flag' => false, 'p_17_flag' => false, 'p_18_flag' => false, 'mpp_flag' => false, 'p_23_flag' => false, 'tp_flag' => false, 'p_19_flag' => false, 'p_19a' => null, 'p_19b' => null, 'p_19c' => null, 'pmarzy_flag' => false, 'pmarzy_subtype' => null, 'total_amount' => null),
            'vat_summary' => array(),
            'lines' => array(),
            'lines_before' => array(),
            'payment' => array('due_date' => null, 'due_date_description' => null, 'method' => null, 'bank_account' => null, 'status' => 'unpaid', 'payment_date' => null, 'bank_swift' => null, 'bank_name' => null, 'bank_own_account' => null, 'bank_description' => null, 'factor_bank_accounts' => array()),
            'correction' => null,
            'registries' => array('krs' => null, 'regon' => null, 'bdo' => null, 'pelna_nazwa' => null),
            'stopka' => array(),
            'additional_info' => array(),
            'additional_desc' => array(),
            'exchange_rate' => array('rate' => null),
            'warunki' => array('umowy' => array(), 'zamowienia' => array(), 'partie' => array(), 'waluta_umowna' => null, 'kurs_umowny' => null, 'warunki_dostawy' => null, 'podmiot_posredniczacy' => false, 'transport' => array()),
        );
    }
}