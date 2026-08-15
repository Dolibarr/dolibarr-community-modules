<?php
/* Copyright (C) 2025 InPoint Automation Sp z o.o.
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
 * \file    ksef/class/ksef_qr.class.php
 * \ingroup ksef
 * \brief   QR Code generation
 */

require_once DOL_DOCUMENT_ROOT . '/core/class/commonobject.class.php';

class KsefQR
{
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * @brief Generates QR Code I URL
     * @param string $nip Seller NIP
     * @param int|string $date Invoice date
     * @param string $hash Invoice SHA-256 hash (base64)
     * @param string $env Environment
     * @return string QR Code I URL
     * @called_by addQRToPDF()
     */
    private function generateQRCodeI($nip, $date, $hash, $env)
    {
        return ksefGetVerificationURL(null, $hash, $env, $nip, $date);
    }

    /**
     * @brief Generates QR Code II URL (certificate authentication for offline invoices)
     * @param array $params Parameters
     * @return string|false QR Code II URL or false on error
     */
    private function generateQRCodeII($params)
    {
        global $conf;
        dol_include_once('/ksef/lib/ksef.lib.php');

        $credentials = ksefLoadOfflineCertificate();
        if (!$credentials) {
            dol_syslog("KsefQR::generateQRCodeII - Offline certificate not properly configured", LOG_WARNING);
            return false;
        }

        $nip = $params['sellerNIP'];
        $invoiceHash = $params['invoiceHash'];
        $env = $params['env'] ?? 'TEST';

        $hashBase64URL = ksefBase64ToBase64URL($invoiceHash);

        $signature = ksefSignData($invoiceHash, $credentials['private_key_pem'], true);

        if (!$signature) {
            dol_syslog("KsefQR::generateQRCodeII - Failed to sign invoice hash", LOG_ERR);
            return false;
        }

        $base_url = ksefGetQrBaseUrl($env);
        $certSerial = $credentials['serial'];

        return "{$base_url}/client-app/certificate/nip/{$nip}/{$nip}/{$certSerial}/{$hashBase64URL}/{$signature}";
    }

    /**
     * @brief Renders a box if QR Code II generation fails
     * @param object $pdf TCPDF object
     * @param float $x X position
     * @param float $y Y position
     * @param float $size Box size (matches QR code dimensions)
     * @return void
     * @called_by addQRToPDF()
     */
    private function renderQRCodeIIPlaceholder(&$pdf, $x, $y, $size)
    {
        global $langs;

        // Draw a light grey bordered box matching the QR code dimensions
        $pdf->SetDrawColor(180, 180, 180);
        $pdf->SetFillColor(245, 245, 245);
        $pdf->Rect($x, $y, $size, $size, 'DF');

        $pdf->SetFont('freesans', '', 5.5);
        $pdf->SetTextColor(120, 120, 120);

        $line1 = $langs->transnoentities('KSEF_QRCodeII');
        $line2 = $langs->transnoentities('KSEF_QRCodeIIUnavailable');

        $midY = $y + ($size / 2) - 4;
        $pdf->SetXY($x, $midY);
        $pdf->MultiCell($size, 3, $line1 . "\n" . $line2, 0, 'C', false);

        // Reset drawing colors
        $pdf->SetDrawColor(0, 0, 0);
        $pdf->SetFillColor(255, 255, 255);
        $pdf->SetTextColor(0, 0, 0);
    }

    /**
     * @brief Renders the verification link section to the right of QR codes
     * @param object $pdf TCPDF object
     * @param float $textX X position for text (right of QR codes)
     * @param float $textY Y position for text
     * @param float $textWidth Available width for text
     * @param string $verifyUrl Verification URL
     * @param string|null $ksefNumber KSeF number to display (optional)
     * @return void
     * @called_by addQRToPDF()
     */
    private function renderVerificationLink(&$pdf, $textX, $textY, $textWidth, $verifyUrl, $ksefNumber = null)
    {
        global $langs;

        if (empty($verifyUrl)) {
            return;
        }

        $pdf->SetFont('freesans', '', 6);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetXY($textX, $textY);
        $instructionText = $langs->transnoentities('KSEF_QRVerificationInstruction');
        $pdf->MultiCell($textWidth, 3, $instructionText, 0, 'L', false);

        $textY = $pdf->GetY() + 1;

        // KSeF number if provided
        if (!empty($ksefNumber)) {
            $pdf->SetFont('freesans', 'B', 6);
            $pdf->SetXY($textX, $textY);
            $pdf->Cell($textWidth, 3, $ksefNumber, 0, 1, 'L');
            $textY = $pdf->GetY() + 1;
        }

        // Verification URL
        $pdf->SetFont('freesans', '', 5);
        $pdf->SetTextColor(0, 0, 255);
        $pdf->SetXY($textX, $textY);
        $linkHtml = '<a href="' . htmlspecialchars($verifyUrl) . '">' . htmlspecialchars($verifyUrl) . '</a>';
        $pdf->writeHTMLCell($textWidth, 0, $textX, $textY, $linkHtml, 0, 1, false, true, 'L');
        $pdf->SetTextColor(0, 0, 0);
    }

    /**
     * @brief Generates verification URL for display
     * @param string $ksefNumber KSeF invoice number
     * @param string $invoiceHash Base64-encoded invoice hash
     * @param string $environment Environment
     * @return string Verification URL
     * @called_by addQRToPDF()
     */
    private function generateDisplayVerificationUrl($ksefNumber, $invoiceHash, $environment)
    {
        try {
            return ksefGetVerificationURL($ksefNumber, $invoiceHash, $environment);
        } catch (Exception $e) {
            dol_syslog("KsefQR::generateDisplayVerificationUrl - " . $e->getMessage(), LOG_WARNING);
            return '';
        }
    }

    /**
     * @brief Adds QR code(s) to invoice PDF
     * Online mode (has KSeF number): Single QR Code with verification URL
     * Offline mode (no KSeF number yet): Two QR codes - OFFLINE + CERTYFIKAT
     * @param object $pdf TCPDF object
     * @param int $invoice_id Invoice ID
     * @return bool True if QR added successfully
     */
    public function addQRToPDF(&$pdf, $invoice_id)
    {
        global $conf, $mysoc, $langs;

        if (empty($conf->ksef->enabled) || empty($conf->global->KSEF_ADD_QR)) {
            return false;
        }

        if (!is_object($pdf) || !method_exists($pdf, 'write2DBarcode')) {
            return false;
        }

        dol_include_once('/ksef/class/ksef_submission.class.php');
        dol_include_once('/ksef/lib/ksef.lib.php');

        $submission = new KsefSubmission($this->db);
        if ($submission->fetchByInvoice($invoice_id) <= 0) {
            dol_syslog("KsefQR::addQRToPDF - No submission found for invoice $invoice_id", LOG_DEBUG);
            return false;
        }

        if (empty($submission->invoice_hash)) {
            dol_syslog("KsefQR::addQRToPDF - No invoice hash available for submission " . $submission->rowid, LOG_WARNING);
            return false;
        }

        // seller NIP
        $nip = '';
        if (!empty($conf->global->KSEF_COMPANY_NIP)) {
            $nip = ksefCleanNIP($conf->global->KSEF_COMPANY_NIP);
        }
        if (empty($nip)) {
            $nip = ksefCleanNIP(ksefGetIdentifierField($mysoc, 'NIP'));
        }
        if (empty($nip)) {
            dol_syslog("KsefQR::addQRToPDF - No seller NIP configured", LOG_WARNING);
            return false;
        }

        require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
        $invoice = new Facture($this->db);
        if ($invoice->fetch($invoice_id) <= 0) {
            return false;
        }

        $langs->load("ksef@ksef");

        $has_real_ksef_number = !empty($submission->ksef_number) &&
            strpos($submission->ksef_number, 'OFFLINE') === false &&
            strpos($submission->ksef_number, 'PENDING') === false &&
            strpos($submission->ksef_number, 'ERROR') === false;

        $is_offline_mode = !empty($submission->offline_mode);
        $is_offline_pending = ($is_offline_mode && !$has_real_ksef_number) || ($submission->status == 'OFFLINE');

        dol_syslog("KsefQR::addQRToPDF - invoice=$invoice_id, status={$submission->status}, " .
            "offline_mode={$submission->offline_mode}, ksef_number={$submission->ksef_number}, " .
            "has_real_ksef=$has_real_ksef_number, is_offline_pending=$is_offline_pending", LOG_DEBUG);

        try {
            // Save current PDF state to prevent interfering with the main template
            $currentY = $pdf->GetY();
            $currentX = $pdf->GetX();
            $currentPage = $pdf->getPage();
            $currentFont = $pdf->getFontFamily();
            $currentFontSize = $pdf->getFontSizePt();
            $currentFontStyle = $pdf->getFontStyle();
            $currentAutoPageBreak = $pdf->getAutoPageBreak();
            $margins = $pdf->getMargins();
            $currentAutoPageBreakMargin = $margins['bottom'] ?? 0;

            $pdf->SetAutoPageBreak(false);

            $pageWidth = $pdf->getPageWidth();
            $pageHeight = $pdf->getPageHeight();
            $marginRight = $margins['right'] ?? 10;
            $marginLeft = $margins['left'] ?? 10;

            $qrSize = !empty($conf->global->KSEF_QR_SIZE) ? (int)$conf->global->KSEF_QR_SIZE : 25;
            $bottomOffset = 50;
            $qrBlockHeight = $qrSize + 6; // QR code + label below
            $qrY = $pageHeight - $bottomOffset;

            // If main content extends into add a new page
            if ($currentY > ($qrY - 2)) {
                $pdf->AddPage('', '', true);
                $pageHeight = $pdf->getPageHeight();
                $qrY = $pageHeight - $bottomOffset;
                dol_syslog("KsefQR::addQRToPDF - Content overlapped QR area, added new page", LOG_DEBUG);
            }

            $style = array(
                'border' => false,
                'vpadding' => 'auto',
                'hpadding' => 'auto',
                'fgcolor' => array(0, 0, 0),
                'bgcolor' => false,
                'module_width' => 1,
                'module_height' => 1
            );

            if ($is_offline_pending) {
                $spacing = 8;
                $qr_i_x = $marginLeft;
                $qr_ii_x = $marginLeft + $qrSize + $spacing;

                // QR Code I (OFFLINE)
                $qr_i_url = $this->generateQRCodeI(
                    $nip,
                    $invoice->date,
                    $submission->invoice_hash,
                    $submission->environment ?? 'TEST'
                );

                if (!empty($qr_i_url)) {
                    $pdf->write2DBarcode($qr_i_url, 'QRCODE,H', $qr_i_x, $qrY, $qrSize, $qrSize, $style, 'N');
                    $pdf->SetFont('freesans', 'B', 7);
                    $pdf->SetTextColor(0, 0, 0);
                    $pdf->SetXY($qr_i_x, $qrY + $qrSize + 1);
                    $pdf->Cell($qrSize, 4, 'OFFLINE', 0, 0, 'C');
                }

                // QR Code II (CERTYFIKAT)
                $qr_ii_url = $this->generateQRCodeII(array(
                    'sellerNIP' => $nip,
                    'invoiceHash' => $submission->invoice_hash,
                    'env' => $submission->environment ?? 'TEST'
                ));

                if (!empty($qr_ii_url)) {
                    $pdf->write2DBarcode($qr_ii_url, 'QRCODE,H', $qr_ii_x, $qrY, $qrSize, $qrSize, $style, 'N');
                    $pdf->SetFont('freesans', 'B', 7);
                    $pdf->SetXY($qr_ii_x, $qrY + $qrSize + 1);
                    $pdf->Cell($qrSize, 4, 'CERTYFIKAT', 0, 0, 'C');
                } else {
                    dol_syslog("KsefQR::addQRToPDF - QR Code II not generated (certificate not configured or signing failed)", LOG_WARNING);
                    $this->renderQRCodeIIPlaceholder($pdf, $qr_ii_x, $qrY, $qrSize);
                    $pdf->SetFont('freesans', 'B', 7);
                    $pdf->SetTextColor(0, 0, 0);
                    $pdf->SetXY($qr_ii_x, $qrY + $qrSize + 1);
                    $pdf->Cell($qrSize, 4, 'CERTYFIKAT', 0, 0, 'C');
                }

                // Verification link
                $textX = $qr_ii_x + $qrSize + 5;
                $textWidth = $pageWidth - $textX - $marginRight;
                if ($textWidth > 30 && !empty($qr_i_url)) {
                    $this->renderVerificationLink($pdf, $textX, $qrY, $textWidth, $qr_i_url, null);
                }
            } else if ($has_real_ksef_number) {
                dol_syslog("KsefQR::addQRToPDF - Generating ACCEPTED QR code for {$submission->ksef_number}", LOG_INFO);
                $qrX = $marginLeft;

                $verifyUrl = $this->generateDisplayVerificationUrl(
                    $submission->ksef_number,
                    $submission->invoice_hash,
                    $submission->environment ?? 'TEST'
                );

                if (!empty($verifyUrl)) {
                    $pdf->write2DBarcode($verifyUrl, 'QRCODE,H', $qrX, $qrY, $qrSize, $qrSize, $style, 'N');

                    // KSeF number below QR code
                    $pdf->SetFont('freesans', '', 5);
                    $pdf->SetTextColor(0, 0, 0);
                    $pdf->SetXY($qrX, $qrY + $qrSize + 1);
                    $ksefNum = $submission->ksef_number;
                    if (strlen($ksefNum) > 35) {
                        $part1 = substr($ksefNum, 0, 35);
                        $part2 = substr($ksefNum, 35);
                        $pdf->Cell($qrSize, 2.5, $part1, 0, 1, 'C');
                        $pdf->SetXY($qrX, $qrY + $qrSize + 3.5);
                        $pdf->Cell($qrSize, 2.5, $part2, 0, 1, 'C');
                    } else {
                        $pdf->Cell($qrSize, 3, $ksefNum, 0, 1, 'C');
                    }

                    $textX = $qrX + $qrSize + 5;
                    $textWidth = $pageWidth - $textX - $marginRight;
                    if ($textWidth > 30) {
                        $this->renderVerificationLink($pdf, $textX, $qrY, $textWidth, $verifyUrl, null);
                    }
                }
            }

            $pdf->SetAutoPageBreak($currentAutoPageBreak, $currentAutoPageBreakMargin);
            $pdf->setPage($currentPage);
            $pdf->SetXY($currentX, $currentY);
            $pdf->SetFont($currentFont, $currentFontStyle, $currentFontSize);
            $pdf->SetTextColor(0, 0, 0);

            return true;

        } catch (Exception $e) {
            dol_syslog("KsefQR::addQRToPDF: Error - " . $e->getMessage(), LOG_ERR);
            return false;
        }
    }

}