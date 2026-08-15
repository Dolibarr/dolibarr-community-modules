<?php
/* Copyright (C) 2002-2005  Rodolphe Quiedeville    <rodolphe@quiedeville.org>
 * Copyright (C) 2004-2020	Laurent Destailleur 	<eldy@users.sourceforge.net>
 * Copyright (C) 2004		Christophe Combelles	<ccomb@free.fr>
 * Copyright (C) 2005		Marc Barilley			<marc@ocebo.fr>
 * Copyright (C) 2005-2013	Regis Houssin			<regis.houssin@inodbox.com>
 * Copyright (C) 2010-2023	Juanjo Menent			<jmenent@simnandez.es>
 * Copyright (C) 2013-2022	Philippe Grand			<philippe.grand@atoo-net.com>
 * Copyright (C) 2013		Florian Henry			<florian.henry@open-concept.pro>
 * Copyright (C) 2014-2016  Marcos García			<marcosgdf@gmail.com>
 * Copyright (C) 2016-2025	Alexandre Spangaro		<alexandre@inovea-conseil.com>
 * Copyright (C) 2018-2025  Frédéric France         <frederic.france@free.fr>
 * Copyright (C) 2019       Ferran Marcet	        <fmarcet@2byte.es>
 * Copyright (C) 2022       Gauthier VERDOL         <gauthier.verdol@atm-consulting.fr>
 * Copyright (C) 2023		Nick Fragoulis
 * Copyright (C) 2024-2025	MDW						<mdeweerd@users.noreply.github.com>
 * Copyright (C) 2026       InPoint Automation Sp z o.o.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    ksef/incoming_card.php
 * \ingroup ksef
 * \brief   Card view for KSeF incoming invoice
 *
 * Based on htdocs/fourn/facture/card.php from Dolibarr
 * see https://github.com/Dolibarr/dolibarr/blob/develop/htdocs/fourn/facture/card.php
 */

// CSRF check
if (!defined('CSRFCHECK_WITH_TOKEN')) {
    define('CSRFCHECK_WITH_TOKEN', '1');
}

// Prevent token renewal for actions that exit without page reload
$action_raw = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : '');
if (in_array($action_raw, array('download_xml', 'view_xml', 'download_pdf'))) {
    if (!defined('NOTOKENRENEWAL')) {
        define('NOTOKENRENEWAL', '1');
    }
}

$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) $res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"] . "/main.inc.php";
if (!$res && file_exists("../main.inc.php")) $res = @include "../main.inc.php";
if (!$res && file_exists("../../main.inc.php")) $res = @include "../../main.inc.php";
if (!$res && file_exists("../../../main.inc.php")) $res = @include "../../../main.inc.php";
if (!$res) die("Include of main fails");

global $conf, $langs, $user, $db;

require_once DOL_DOCUMENT_ROOT . '/core/lib/date.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/class/html.formfile.class.php';
require_once DOL_DOCUMENT_ROOT . '/fourn/class/fournisseur.facture.class.php';
dol_include_once('/ksef/class/ksef_incoming.class.php');
dol_include_once('/ksef/class/fa3_parser.class.php');
dol_include_once('/ksef/lib/ksef.lib.php');

$langs->loadLangs(array("ksef@ksef", "bills", "companies", "suppliers"));

// Security check
if (!$user->hasRight('ksef', 'read')) accessforbidden();

// Get parameters
$id = GETPOSTINT('id');
$action = GETPOST('action', 'aZ09');
$confirm = GETPOST('confirm', 'alpha');

if (empty($id)) {
    header('Location: ' . dol_buildpath('/ksef/incoming_list.php', 1));
    exit;
}

// Initialize objects
$object = new KsefIncoming($db);
if ($object->fetch($id) <= 0) {
    accessforbidden('Record not found');
}

$parser = new FA3Parser($db);
$form = new Form($db);

// Permissions
$usercanread = $user->hasRight('ksef', 'read');
$usercanwrite = $user->hasRight('ksef', 'write');

// Initialize a technical object to manage hooks
$hookmanager->initHooks(array('ksef_incomingcard', 'globalcard'));


/*
 * Actions
 */

// Download XML
if ($action == 'download_xml') {
    if (!empty($object->fa3_xml)) {
        $filename = 'FA3_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $object->ksef_number) . '.xml';
        header('Content-Type: application/xml; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo $object->fa3_xml;
        exit;
    }
}

// View raw XML
if ($action == 'view_xml') {
    if (!empty($object->fa3_xml)) {
        header('Content-Type: application/xml; charset=utf-8');
        echo $object->fa3_xml;
        exit;
    }
}

// Download PDF visualization
if ($action == 'download_pdf') {
    if (!empty($object->rowid) && !empty($object->fa3_xml)) {
        dol_include_once('/ksef/class/ksef_invoice_pdf.class.php');
        $pdfGenerator = new KsefInvoicePdf($db);
        $pdfContent = $pdfGenerator->generate($object);

        if ($pdfContent !== false) {
            $filename = 'FA3_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $object->ksef_number) . '.pdf';
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . strlen($pdfContent));
            echo $pdfContent;
            exit;
        } else {
            setEventMessages($pdfGenerator->error, $pdfGenerator->errors, 'errors');
        }
    }
}

// Delete record
if ($action == 'confirm_delete' && $confirm == 'yes' && $usercanwrite) {
    $result = $object->delete($user);
    if ($result > 0) {
        setEventMessages($langs->trans("RecordDeleted"), null, 'mesgs');
        header('Location: ' . dol_buildpath('/ksef/incoming_list.php', 1));
        exit;
    } else {
        setEventMessages($object->error, $object->errors, 'errors');
    }
}


/*
 * View
 */

$title = $langs->trans('KSEF_IncomingInvoice') . ' - ' . $object->invoice_number;
$help_url = '';

llxHeader('', $title, $help_url, '', 0, 0, '', '', '', 'mod-ksef page-incoming-card');


// Prepare tabs
$head = ksef_incoming_prepare_head($object);

print dol_get_fiche_head($head, 'card', $langs->trans('KSEF_IncomingInvoice'), -1, 'supplier_invoice');


/*
 * Confirmation dialogs
 */

$formconfirm = '';

if ($action == 'delete') {
    $formconfirm = $form->formconfirm(
        $_SERVER["PHP_SELF"] . '?id=' . $object->id,
        $langs->trans('Delete'),
        $langs->trans('ConfirmDeleteIncomingInvoice', $object->invoice_number),
        'confirm_delete',
        '',
        0,
        1
    );
}

// Call Hook formConfirm
$parameters = array('formConfirm' => $formconfirm);
$reshook = $hookmanager->executeHooks('formConfirm', $parameters, $object, $action);
if (empty($reshook)) {
    $formconfirm .= $hookmanager->resPrint;
} elseif ($reshook > 0) {
    $formconfirm = $hookmanager->resPrint;
}

// Print form confirm
print $formconfirm;


/*
 * Banner
 */

$linkback = '<a href="' . dol_buildpath('/ksef/incoming_list.php', 1) . '?restore_lastsearch_values=1">' . $langs->trans("BackToList") . '</a>';

$morehtmlref = '<div class="refidno">';
// Ref supplier (invoice number from supplier)
$morehtmlref .= $form->editfieldkey("RefSupplierBill", 'ref_supplier', $object->invoice_number, $object, 0, 'string', '', 0, 1);
$morehtmlref .= $form->editfieldval("RefSupplierBill", 'ref_supplier', $object->invoice_number, $object, 0, 'string', '', null, null, '', 1);
// Thirdparty (seller)
$morehtmlref .= '<br>' . $langs->trans("Supplier") . ': <strong>' . dol_escape_htmltag($object->seller_name) . '</strong>';
if (!empty($object->seller_nip)) {
    $morehtmlref .= ' <span class="opacitymedium">(NIP: ' . ksefFormatNIP($object->seller_nip) . ')</span>';
}
if (!empty($object->seller_vat_id)) {
    $morehtmlref .= ' <span class="opacitymedium">(VAT: ' . dol_escape_htmltag($object->seller_vat_id) . ')</span>';
}
$morehtmlref .= '</div>';

dol_banner_tab($object, 'id', $linkback, 1, 'rowid', 'ksef_number', $morehtmlref);


/*
 * Card body
 */

// Call Hook tabContentViewKsefIncoming
$linkedInvoiceExists = false;
$parameters = array();
$reshook = $hookmanager->executeHooks('tabContentViewKsefIncoming', $parameters, $object, $action);
if (empty($reshook)) {
    print '<div class="fichecenter">';
    print '<div class="fichehalfleft">';
    print '<div class="underbanner clearboth"></div>';

    print '<table class="border tableforfield centpercent">';

    // Type
    print '<tr><td class="titlefield">' . $langs->trans('Type') . '</td><td>';
    print '<span class="badgeneutral">';
    print ksefGetInvoiceTypeBadge($object->invoice_type);
    print '</span>';
    if (!empty($parser->getInvoiceTypeDescription($object->invoice_type))) {
        print ' <span class="opacitymedium">(' . dol_escape_htmltag($parser->getInvoiceTypeDescription($object->invoice_type)) . ')</span>';
    }
    print '</td></tr>';

    // Correction reference
    $correctionData = KsefIncoming::isCorrectionType($object->invoice_type) ? $object->getCorrectionData() : null;
    $resolvedCorrections = KsefIncoming::isCorrectionType($object->invoice_type) ? $object->resolveCorrectedInvoices() : array();
    $correctedCount = count($resolvedCorrections);

    if ($correctedCount == 1) {
        $rc = $resolvedCorrections[0];
        $isSelfReference = (!empty($rc['ksef_number']) && $rc['ksef_number'] === $object->ksef_number);
        print '<tr><td>' . $langs->trans("KSEF_CorrectsInvoice") . '</td><td>';

        // Line 1: KSeF number (links to incoming record if available)
        print $langs->trans("KSEF_CorrectsKsefNr") . ': ';
        if ($rc['incoming']) {
            print $rc['incoming']->getNomUrl(1);
        } elseif (!empty($rc['ksef_number'])) {
            print dol_escape_htmltag($rc['ksef_number']);
        } else {
            print '<span class="opacitymedium">-</span>';
        }
        if ($isSelfReference) {
            print '<br><span class="warning"><span class="fa fa-exclamation-triangle"></span> ' . $langs->trans("KSEF_CorrectedKsefNumberSameAsOwn") . '</span>';
        }

        // Line 2: Vendor invoice reference from XML
        if (!empty($rc['invoice_number'])) {
            print '<br>' . $langs->trans("KSEF_VendorRef") . ': ';
            print dol_escape_htmltag($rc['invoice_number']);
        }

        // Line 3: Dolibarr supplier invoice (if imported)
        if ($rc['supplier_invoice']) {
            print '<br>' . $langs->trans("KSEF_ImportedAs") . ': ';
            print $rc['supplier_invoice']->getNomUrl(1) . ' ' . $rc['supplier_invoice']->getLibStatut(5);
        }

        print '</td></tr>';
    } elseif ($correctedCount > 1) {
        print '<tr><td>' . $langs->trans("KSEF_CorrectedInvoices") . '</td><td>';
        print $langs->trans("KSEF_CorrectsXInvoices", $correctedCount);
        print '</td></tr>';
    }

    // Correction reason
    if (!empty($correctionData['reason'])) {
        print '<tr><td>' . $langs->trans("KSEF_CorrectionReason") . '</td>';
        print '<td>' . dol_escape_htmltag($correctionData['reason']) . '</td></tr>';
    }

    // Linked Dolibarr invoice(s)
    $linkedInvoiceExists = false;
    $creditNoteExists = false;
    $creditNoteInvoice = null;

    // Check for credit note created via replace mode
    if ($object->fk_credit_note > 0) {
        $creditNoteInvoice = new FactureFournisseur($db);
        if ($creditNoteInvoice->fetch($object->fk_credit_note) > 0) {
            $creditNoteExists = true;
        }
    }

    if ($object->fk_facture_fourn > 0) {
        $linkedInvoice = new FactureFournisseur($db);
        if ($linkedInvoice->fetch($object->fk_facture_fourn) > 0) {
            $linkedInvoiceExists = true;
        }
    }

    $isReplaceMode = ($creditNoteExists && $linkedInvoiceExists);

    if ($isReplaceMode) {
        // Replace mode: note in the main table, full cards rendered after the main layout
        print '<tr><td>' . $langs->trans("KSEF_ImportMode") . '</td>';
        print '<td><span class="badgeneutral">' . $langs->trans("KSEF_UpwardCorrectionReplace") . '</span></td></tr>';
    } elseif ($object->fk_facture_fourn > 0) {
        if ($linkedInvoiceExists) {
            print '<tr><td>' . $langs->trans("LinkedSupplierInvoice") . '</td>';
            print '<td>' . $linkedInvoice->getNomUrl(1) . ' ' . $linkedInvoice->getLibStatut(5) . '</td></tr>';
        } else {
            print '<tr><td>' . $langs->trans("LinkedSupplierInvoice") . '</td>';
            print '<td><span class="warning"><span class="fa fa-exclamation-triangle"></span> ' . $langs->trans("KSEF_LinkedInvoiceDeleted") . '</span></td></tr>';
        }

        if ($creditNoteExists) {
            print '<tr><td>' . $langs->trans("KSEF_ReplaceCreditNote") . '</td>';
            print '<td>' . $creditNoteInvoice->getNomUrl(1) . ' ' . $creditNoteInvoice->getLibStatut(5) . '</td></tr>';
        }
    }

    // Label (Invoice number)
    print '<tr>';
    print '<td>' . $langs->trans("Label") . '</td>';
    print '<td>' . dol_escape_htmltag($object->invoice_number) . '</td>';
    print '</tr>';

    // Invoice Date
    print '<tr><td>' . $langs->trans("DateInvoice") . '</td>';
    print '<td>' . dol_print_date($object->invoice_date, 'day') . '</td></tr>';

    // Sale Date (if different)
    if ($object->sale_date && $object->sale_date != $object->invoice_date) {
        print '<tr><td>' . $langs->trans("KSEF_SaleDate") . '</td>';
        print '<td>' . dol_print_date($object->sale_date, 'day') . '</td></tr>';
    }

    // Payment Due Date
    if ($object->payment_due_date) {
        print '<tr><td>' . $langs->trans("DateMaxPayment") . '</td>';
        print '<td>' . dol_print_date($object->payment_due_date, 'day') . '</td></tr>';
    }

    // Payment Method
    if ($object->payment_method) {
        print '<tr><td>' . $langs->trans("PaymentMode") . '</td>';
        print '<td>' . dol_escape_htmltag($parser->getPaymentMethodDescription($object->payment_method)) . '</td></tr>';
    }

    // Bank Account
    if ($object->bank_account) {
        print '<tr><td>' . $langs->trans("BankAccount") . '</td>';
        print '<td>' . dol_escape_htmltag($object->bank_account) . '</td></tr>';
    }

    // Payment Status
    if (!empty($object->payment_status) && $object->payment_status !== 'unpaid') {
        $notSpecified = '<span class="opacitymedium">' . $langs->trans("KSEF_NotSpecifiedInXml") . '</span>';

        print '<tr><td>' . $langs->trans("KSEF_PaymentStatus") . '</td><td>';
        if ($object->payment_status === 'paid') {
            print '<span class="badge badge-status4 badge-status">' . $langs->trans("KSEF_SellerMarkedPaid") . '</span>';
        } elseif ($object->payment_status === 'paid_installments') {
            print '<span class="badge badge-status4 badge-status">' . $langs->trans("KSEF_SellerMarkedPaidInstallments") . '</span>';
        } elseif ($object->payment_status === 'partial') {
            print '<span class="badge badge-status1 badge-status">' . $langs->trans("KSEF_SellerMarkedPartial") . '</span>';
        }
        print '</td></tr>';

        print '<tr><td>' . $langs->trans("KSEF_PaymentDate") . '</td><td>';
        print $object->payment_date ? dol_print_date($object->payment_date, 'day') : $notSpecified;
        print '</td></tr>';

        print '<tr><td>' . $langs->trans("KSEF_PaymentMethod") . '</td><td>';
        $methodLabel = ksefGetPaymentMethodLabel($object->payment_method);
        print $methodLabel ? dol_escape_htmltag($methodLabel) : $notSpecified;
        print '</td></tr>';
    }

    // Seller info
    print '<tr><td>' . $langs->trans("KSEF_Seller") . '</td><td>';
    print '<strong>' . dol_escape_htmltag($object->seller_name) . '</strong>';
    print '<br><span class="opacitymedium">';
    if (!empty($object->seller_nip)) {
        print 'NIP: ' . ksefFormatNIP($object->seller_nip);
    }
    if (!empty($object->seller_vat_id)) {
        if (!empty($object->seller_nip)) print '<br>';
        print 'VAT: ' . dol_escape_htmltag($object->seller_vat_id);
    }
    if ($object->seller_address) {
        print '<br>' . dol_escape_htmltag($object->seller_address);
    }
    if ($object->seller_country) {
        print ', ' . dol_escape_htmltag($object->seller_country);
    }
    print '</span>';
    print '</td></tr>';

    // KSeF Environment
    print '<tr><td>' . $langs->trans("KSEF_ENVIRONMENT") . '</td>';
    print '<td>' . ksefGetEnvironmentBadge($object->environment) . '</td></tr>';

    // Fetched On
    print '<tr><td>' . $langs->trans("KSEF_FetchedOn") . '</td>';
    print '<td>' . dol_print_date($object->fetch_date, 'dayhour') . '</td></tr>';

    // Import Status
    print '<tr><td>' . $langs->trans("Status") . '</td>';
    print '<td>' . $object->getLibStatut(5) . '</td></tr>';

    print '</table>';

    print '</div>'; // fichehalfleft


    /*
     * Right column - Amounts
     */

    print '<div class="fichehalfright">';
    print '<div class="underbanner clearboth"></div>';

    print '<table class="border tableforfield centpercent">';

    // Currency row
    print '<tr><td><table class="nobordernopadding centpercent"><tr><td>' . $langs->trans("Currency") . '</td></tr></table></td>';
    print '<td>' . dol_escape_htmltag($object->currency);
    // Add currency name if we can get it
    if ($object->currency == 'PLN') {
        print ' - Poland Zloty';
    }
    print '</td></tr>';

    // Amount (excl. tax)
    print '<tr>';
    print '<td class="titlefieldmiddle">' . $langs->trans('AmountHT') . '</td>';
    print '<td class="nowrap amountcard right">' . price($object->total_net, 0, $langs, 0, -1, -1, $object->currency) . '</td>';
    print '</tr>';

    // Amount tax
    print '<tr>';
    print '<td>' . $langs->trans('AmountVAT') . '</td>';
    print '<td class="nowrap amountcard right">' . price($object->total_vat, 0, $langs, 0, -1, -1, $object->currency) . '</td>';
    print '</tr>';

    // Amount (inc. tax)
    print '<tr>';
    print '<td>' . $langs->trans('AmountTTC') . '</td>';
    print '<td class="nowrap amountcard right">' . price($object->total_gross, 0, $langs, 0, -1, -1, $object->currency) . '</td>';
    print '</tr>';

    print '</table>';


    /*
     * VAT Summary
     */

    $vatSummary = $object->getVatSummary();
    if (!empty($vatSummary)) {
        print '<div class="div-table-responsive-no-min">';
        print '<table class="noborder paymenttable centpercent">';

        print '<tr class="liste_titre">';
        print '<td class="liste_titre">' . $langs->trans("VATRate") . '</td>';
        print '<td class="right"><span class="hideonsmartphone">' . $langs->trans("KSEF_Net") . '</span></td>';
        print '<td class="right">' . $langs->trans("VATAmount") . '</td>';
        print '</tr>';

        $vatRateLabels = array(
            '23' => '23%', '22' => '22%', '8' => '8%', '7' => '7%', '5' => '5%',
            '4' => '4%', '3' => '3%', '0' => '0%',
            '0 KR' => '0% (' . $langs->trans("KSEF_VATDomestic") . ')',
            '0 WDT' => '0% (WDT)',
            '0 EX' => '0% (' . $langs->trans("KSEF_VATExport") . ')',
            'zw' => $langs->trans("KSEF_VATExempt"),
            'np' => $langs->trans("KSEF_VATNotSubject"),
            'np I' => $langs->trans("KSEF_VATNotSubject") . ' (I)',
            'np II' => $langs->trans("KSEF_VATNotSubject") . ' (II)',
            'oo' => $langs->trans("KSEF_VATReverseCharge"),
        );
        foreach ($vatSummary as $rate => $amounts) {
            print '<tr class="oddeven">';
            $rateLabel = isset($vatRateLabels[$rate]) ? $vatRateLabels[$rate] : dol_escape_htmltag($rate);
            $hasVat = !empty($amounts['vat']) && $amounts['vat'] != 0;
            print '<td>' . $rateLabel . '</td>';
            print '<td class="right">' . price($amounts['net'], 0, $langs, 1, -1, -1, $object->currency) . '</td>';
            print '<td class="right">' . ($hasVat ? price($amounts['vat'], 0, $langs, 1, -1, -1, $object->currency) : '-') . '</td>';
            print '</tr>';
        }

        // Total row
        print '<tr>';
        print '<td colspan="2" class="right"><span class="opacitymedium">' . $langs->trans("Total") . '</span></td>';
        print '<td class="right">' . price($object->total_vat, 0, $langs, 1, -1, -1, $object->currency) . '</td>';
        print '</tr>';

        print '</table>';
        print '</div>';
    }


    // Corrected invoice table (if we have a bunch of corrected invoices)
    if ($correctedCount > 1) {
        print '<div class="div-table-responsive-no-min">';
        print '<table class="noborder paymenttable centpercent">';

        print '<tr class="liste_titre">';
        print '<td class="liste_titre">' . $langs->trans("KSEF_CorrectsKsefNr") . '</td>';
        print '<td class="liste_titre">' . $langs->trans("KSEF_VendorRef") . '</td>';
        print '<td class="center">' . $langs->trans("Date") . '</td>';
        print '<td class="right">' . $langs->trans("KSEF_ImportedAs") . '</td>';
        print '</tr>';

        foreach ($resolvedCorrections as $rc) {
            $isSelfReference = (!empty($rc['ksef_number']) && $rc['ksef_number'] === $object->ksef_number);
            print '<tr class="oddeven">';

            // KSeF reference / link
            print '<td class="tdoverflowmax200">';
            if ($rc['incoming']) {
                print $rc['incoming']->getNomUrl(1);
            } elseif (!empty($rc['ksef_number'])) {
                print dol_escape_htmltag($rc['ksef_number']);
            } else {
                print '<span class="opacitymedium">-</span>';
            }
            if ($isSelfReference) {
                print ' <span class="warning"><span class="fa fa-exclamation-triangle" title="' . dol_escape_htmltag($langs->trans("KSEF_CorrectedKsefNumberSameAsOwn")) . '"></span></span>';
            }
            print '</td>';

            // Invoice number
            print '<td>';
            if (!empty($rc['invoice_number'])) {
                print dol_escape_htmltag($rc['invoice_number']);
            } else {
                print '<span class="opacitymedium">-</span>';
            }
            print '</td>';

            // Date
            print '<td class="center nowraponall">';
            if (!empty($rc['invoice_date'])) {
                print dol_print_date(strtotime($rc['invoice_date']), 'day');
            }
            print '</td>';

            // Supplier invoice link
            print '<td class="right">';
            if ($rc['supplier_invoice']) {
                print $rc['supplier_invoice']->getNomUrl(1) . ' ' . $rc['supplier_invoice']->getLibStatut(3);
            } else {
                print '<span class="opacitymedium">-</span>';
            }
            print '</td>';

            print '</tr>';
        }

        print '</table>';
        print '</div>';
    }


    print '</div>'; // fichehalfright

    print '</div>'; // fichecenter

    print '<div class="clearboth"></div><br>';


    /*
     * Replace mode invoice cards (credit note + replacement)
     */

    if ($isReplaceMode) {
        // Resolve original invoice from credit note source
        $originalInv = null;
        if ($creditNoteInvoice->fk_facture_source > 0) {
            $originalInv = new FactureFournisseur($db);
            if ($originalInv->fetch($creditNoteInvoice->fk_facture_source) <= 0) {
                $originalInv = null;
            }
        }

        // Credit note card
        ksefPrintSupplierInvoiceCard(
            $creditNoteInvoice,
            $langs->trans("KSEF_ReplaceCreditNote"),
            'fa-minus-circle',
            '#bc3434',
            $langs->trans("KSEF_ReplaceCreditNoteCardDesc"),
            $originalInv
        );

        // Replacement invoice card
        ksefPrintSupplierInvoiceCard(
            $linkedInvoice,
            $langs->trans("KSEF_ReplaceNewInvoice"),
            'fa-plus-circle',
            '#46a546',
            $langs->trans("KSEF_ReplaceNewInvoiceDesc")
        );

        print '<br>';
    }


    /*
     * Invoice Line Items
     */

    $lines = $object->getLineItems();
    if (!empty($lines)) {
        $hasGrossPrice = false;
        $hasGrossAmount = false;
        foreach ($lines as $line) {
            if (!empty($line['unit_price_gross'])) $hasGrossPrice = true;
            if (!empty($line['gross_amount'])) $hasGrossAmount = true;
        }

        print '<div class="div-table-responsive-no-min">';
        print '<table id="tablelines" class="noborder noshadow centpercent">';

        print '<tr class="liste_titre nodrag nodrop">';
        print '<td class="linecoldescription">' . $langs->trans("Description") . '</td>';
        print '<td class="linerefsupplier maxwidth125">' . $langs->trans("VendorSKU") . '</td>';
        print '<td class="linecolvat right nowraponall">' . $langs->trans("VAT") . '</td>';
        print '<td class="linecoluht right nowraponall">' . $langs->trans("PriceUHT") . '</td>';
        print '<td class="linecoluttc right nowraponall">' . $langs->trans("PriceUTTC") . '</td>';
        print '<td class="linecolqty right">' . $langs->trans("Qty") . '</td>';
        print '<td class="linecoldiscount right nowraponall">' . $langs->trans("ReductionShort") . '</td>';
        print '<td class="linecolht right">' . $langs->trans("TotalHT") . '</td>';
        if ($hasGrossAmount) {
            print '<td class="linecolttc right">' . $langs->trans("TotalTTC") . '</td>';
        }
        print '</tr>';

        foreach ($lines as $line) {
            $vatRate = is_numeric($line['vat_rate']) ? (float)$line['vat_rate'] : 0;

            if (!empty($line['unit_price_gross'])) {
                $unitPriceTTC = (float)$line['unit_price_gross'];
            } else {
                $unitPriceTTC = $line['unit_price_net'] * (1 + $vatRate / 100);
            }

            print '<tr class="oddeven">';
            print '<td class="linecoldescription minwidth300imp">' . dol_escape_htmltag($line['description']) . '</td>';
            print '<td class="linecolrefsupplier">' . (isset($line['supplier_ref']) ? dol_escape_htmltag($line['supplier_ref']) : '') . '</td>';
            print '<td class="linecolvat nowrap right">' . ($line['vat_rate'] == 'zw' ? $langs->trans("KSEF_VATExempt") : (int)$line['vat_rate'] . '%') . '</td>';
            print '<td class="linecoluht nowraponall right">' . price($line['unit_price_net'], 0, '', 1, -1, 2) . '</td>';
            print '<td class="linecoluttc nowraponall right">' . price($unitPriceTTC, 0, '', 1, -1, 4) . '</td>';
            print '<td class="linecolqty nowraponall right">' . price($line['quantity'], 0, '', 1, -1, 4) . '</td>';
            print '<td class="linecoldiscount">&nbsp;</td>';
            print '<td class="linecolht nowrap right">' . price($line['net_amount'], 0, '', 1, -1, 2) . '</td>';
            if ($hasGrossAmount) {
                print '<td class="linecolttc nowrap right">' . price(!empty($line['gross_amount']) ? $line['gross_amount'] : 0, 0, '', 1, -1, 2) . '</td>';
            }
            print '</tr>';
        }

        print '</table>';
        print '</div>';
    }
}

print dol_get_fiche_end();


/*
 * Action Buttons
 */

print '<div class="tabsAction">';

$parameters = array();
$reshook = $hookmanager->executeHooks('addMoreActionsButtons', $parameters, $object, $action);
if (empty($reshook)) {
    // Import to Dolibarr
    if (($object->import_status == 'NEW' || $object->import_status == 'ERROR') && $usercanwrite) {
        print '<a class="butAction" href="' . dol_buildpath('/ksef/incoming_import.php', 1) . '?id=' . $object->id . '">' . $langs->trans("KSEF_ImportToDolibarr") . '</a>';
    } elseif ($object->import_status == 'IMPORTED' && !$linkedInvoiceExists && $usercanwrite) {
        print '<a class="butAction" href="' . dol_buildpath('/ksef/incoming_import.php', 1) . '?id=' . $object->id . '">' . $langs->trans("KSEF_ResetImportStatus") . '</a>';
    } elseif ($object->import_status == 'IMPORTED') {
        print '<span class="butActionRefused classfortooltip" title="' . dol_escape_htmltag($langs->trans("KSEF_AlreadyImported")) . '">' . $langs->trans("KSEF_ImportToDolibarr") . '</span>';
    }

    // Download XML
    if (!empty($object->fa3_xml)) {
        print '<a class="butAction" href="' . $_SERVER["PHP_SELF"] . '?id=' . $object->id . '&action=download_xml&token=' . newToken() . '">' . $langs->trans("KSEF_DownloadXml") . '</a>';
    }

    // View on KSeF Portal
    if (!empty($object->ksef_number) && !empty($object->fa3_xml)) {
        $objEnvironment = !empty($object->environment) ? $object->environment : getDolGlobalString('KSEF_ENVIRONMENT', 'TEST');
        $verifyUrl = ksefGetVerificationUrlFromXml($object->ksef_number, $object->fa3_xml, $objEnvironment);
        print '<a class="butAction" href="' . $verifyUrl . '" target="_blank" rel="noopener noreferrer">' . $langs->trans("KSEF_ViewOnPortal") . '</a>';
    }

    // Download PDF visualization
    if (!empty($object->fa3_xml)) {
        print '<a class="butAction" href="' . $_SERVER["PHP_SELF"] . '?id=' . $object->id . '&action=download_pdf&token=' . newToken() . '">' . $langs->trans("KSEF_DownloadPdf") . '</a>';
    }

    // Delete
    if ($usercanwrite && $object->import_status == 'NEW') {
        print dolGetButtonAction('', $langs->trans('Delete'), 'delete', $_SERVER["PHP_SELF"] . '?id=' . $object->id . '&action=delete&token=' . newToken(), '', true);
    }
}

print '</div>';


llxFooter();
$db->close();