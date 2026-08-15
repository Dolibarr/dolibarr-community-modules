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
 * \file    ksef/incoming_import.php
 * \ingroup ksef
 * \brief   Import KSeF incoming invoice to Dolibarr
 */

// CSRF check
if (!defined('CSRFCHECK_WITH_TOKEN')) {
    define('CSRFCHECK_WITH_TOKEN', '1');
}

$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) $res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"] . "/main.inc.php";
if (!$res && file_exists("../main.inc.php")) $res = @include "../main.inc.php";
if (!$res && file_exists("../../main.inc.php")) $res = @include "../../main.inc.php";
if (!$res && file_exists("../../../main.inc.php")) $res = @include "../../../main.inc.php";
if (!$res) die("Include of main fails");

global $conf, $langs, $user, $db;

require_once DOL_DOCUMENT_ROOT . '/core/lib/date.lib.php';
require_once DOL_DOCUMENT_ROOT . '/fourn/class/fournisseur.facture.class.php';
require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
dol_include_once('/ksef/class/ksef_incoming.class.php');
dol_include_once('/ksef/lib/ksef.lib.php');

$langs->loadLangs(array("ksef@ksef", "bills", "companies", "suppliers", "products"));

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

$form = new Form($db);

// Permissions
$usercanwrite = $user->hasRight('ksef', 'write');

$hookmanager->initHooks(array('ksef_incomingimport', 'globalcard'));


/*
 * Actions
 */

$parameters = array();
$reshook = $hookmanager->executeHooks('doActions', $parameters, $object, $action);
if ($reshook < 0) {
    setEventMessages($hookmanager->error, $hookmanager->errors, 'errors');
}

if (empty($reshook)) {
    if ($action == 'import' && $usercanwrite) {
        $socid = GETPOSTINT('socid');
        if ($socid <= 0) {
            setEventMessages($langs->trans('KSEF_ImportErrorNoSupplier'), null, 'errors');
        } else {
            $lineProductMap = array();
            $lines = $object->getLineItems();
            foreach ($lines as $line) {
                $lineNum = $line['line_num'] ?? 0;
                $productId = GETPOSTINT('line_product_' . $lineNum);
                if ($productId > 0) {
                    $lineProductMap[$lineNum] = $productId;
                }
            }
            $correctionSourceId = GETPOSTINT('correction_source_id');
            $upwardMode = GETPOST('upward_mode', 'alpha');
            $result = $object->importToDolibarr($user, $socid, $lineProductMap, $correctionSourceId, $upwardMode);
            if ($result > 0) {
                setEventMessages($langs->trans('KSEF_ImportSuccess', $result), null, 'mesgs');
                // Replace mode creates two invoices - redirect to import page to show both
                if ($upwardMode === 'replace' && $object->fk_credit_note > 0) {
                    header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
                } else {
                    header('Location: ' . DOL_URL_ROOT . '/fourn/facture/card.php?facid=' . $result);
                }
                exit;
            } else {
                setEventMessages($object->error, $object->errors, 'errors');
            }
        }
    }

    if ($action == 'quickimport' && $usercanwrite) {
        if ($object->import_status == 'IMPORTED') {
            if ($object->fk_facture_fourn > 0) {
                header('Location: ' . DOL_URL_ROOT . '/fourn/facture/card.php?facid=' . $object->fk_facture_fourn);
                exit;
            }
        }
        if ($object->import_status == 'NEW' || $object->import_status == 'ERROR') {
            $autoSocid = $object->findMatchingThirdParty();
            if ($autoSocid > 0) {
                $lines = $object->getLineItems();
                $autoMatches = $object->autoMatchLineProducts($lines);
                $allMatched = true;
                foreach ($lines as $line) {
                    $lineNum = $line['line_num'] ?? 0;
                    if (!isset($autoMatches[$lineNum])) {
                        $allMatched = false;
                        break;
                    }
                }
                if ($allMatched) {
                    $productMap = array();
                    foreach ($autoMatches as $ln => $match) {
                        $productMap[$ln] = $match['product_id'];
                    }
                    $result = $object->importToDolibarr($user, $autoSocid, $productMap);
                    if ($result > 0) {
                        setEventMessages($langs->trans('KSEF_QuickImportAutoComplete'), null, 'mesgs');
                        header('Location: ' . DOL_URL_ROOT . '/fourn/facture/card.php?facid=' . $result);
                        exit;
                    } else {
                        setEventMessages($object->error, $object->errors, 'errors');
                    }
                }
            }
            $action = '';

        }
    }

    if ($action == 'skip' && $usercanwrite) {
        $object->import_status = 'SKIPPED';
        $object->import_date = dol_now();
        $result = $object->update($user);
        if ($result > 0) {
            setEventMessages($langs->trans('KSEF_InvoiceSkipped'), null, 'mesgs');
            header('Location: ' . dol_buildpath('/ksef/incoming_list.php', 1));
            exit;
        } else {
            setEventMessages($object->error, $object->errors, 'errors');
        }
    }

    if ($action == 'confirm_reset_import' && $confirm == 'yes' && $usercanwrite) {
        $result = $object->resetImportStatus($user);
        if ($result > 0) {
            setEventMessages($langs->trans('KSEF_ImportStatusReset'), null, 'mesgs');
            header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
            exit;
        } else {
            setEventMessages($object->error, $object->errors, 'errors');
        }
    }

    if ($action == 'reopen' && $usercanwrite) {
        $object->import_status = 'NEW';
        $object->import_date = null;
        $object->import_error = null;
        $result = $object->update($user);
        if ($result > 0) {
            setEventMessages($langs->trans('RecordModifiedSuccessfully'), null, 'mesgs');
            header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
            exit;
        } else {
            setEventMessages($object->error, $object->errors, 'errors');
        }
    }
}


/*
 * View
 */

$title = $langs->trans('KSEF_ImportToDolibarr') . ' - ' . $object->invoice_number;
$help_url = '';

llxHeader('', $title, $help_url, '', 0, 0, '', '', '', 'mod-ksef page-incoming-import');


// Prepare tabs
$head = ksef_incoming_prepare_head($object);

print dol_get_fiche_head($head, 'import', $langs->trans('KSEF_IncomingInvoice'), -1, 'supplier_invoice');


/*
 * Banner
 */

$linkback = '<a href="' . dol_buildpath('/ksef/incoming_list.php', 1) . '?restore_lastsearch_values=1">' . $langs->trans("BackToList") . '</a>';

$morehtmlref = '<div class="refidno">';
$morehtmlref .= $form->editfieldkey("RefSupplierBill", 'ref_supplier', $object->invoice_number, $object, 0, 'string', '', 0, 1);
$morehtmlref .= $form->editfieldval("RefSupplierBill", 'ref_supplier', $object->invoice_number, $object, 0, 'string', '', null, null, '', 1);
$morehtmlref .= '<br>' . $langs->trans("Supplier") . ': <strong>' . dol_escape_htmltag($object->seller_name) . '</strong>';
if (!empty($object->seller_nip)) {
    $morehtmlref .= ' (NIP: ' . ksefFormatNIP($object->seller_nip) . ')';
}
$morehtmlref .= '</div>';

dol_banner_tab($object, 'id', $linkback, 1, 'rowid', 'ksef_number', $morehtmlref);


/*
 * Import content
 */

if ($object->import_status == 'IMPORTED') {
    $linkedInvoiceExists = false;
    $linkedInvoice = null;
    if ($object->fk_facture_fourn > 0) {
        $linkedInvoice = new FactureFournisseur($db);
        if ($linkedInvoice->fetch($object->fk_facture_fourn) > 0) {
            $linkedInvoiceExists = true;
        }
    }

    if ($action == 'reset_import') {
        print $form->formconfirm(
            $_SERVER["PHP_SELF"] . '?id=' . $object->id,
            $langs->trans('KSEF_ResetImportStatus'),
            $langs->trans('KSEF_ConfirmResetImport'),
            'confirm_reset_import',
            '',
            0,
            1
        );
    }

    // Check for credit note created via replace mode
    $creditNoteExists = false;
    $creditNoteInvoice = null;
    if ($object->fk_credit_note > 0) {
        $creditNoteInvoice = new FactureFournisseur($db);
        if ($creditNoteInvoice->fetch($object->fk_credit_note) > 0) {
            $creditNoteExists = true;
        }
    }

    $isReplaceMode = ($creditNoteExists && $linkedInvoiceExists);

    // Status + import date header
    print '<div class="fichecenter">';
    print '<div class="fichehalfleft">';
    print '<div class="underbanner clearboth"></div>';
    print '<table class="border tableforfield centpercent">';

    print '<tr><td class="titlefield">' . $langs->trans("Status") . '</td><td>';
    print $object->getLibStatut(5);
    print '</td></tr>';

    if ($object->import_date) {
        print '<tr><td>' . $langs->trans("DateCreation") . '</td><td>';
        print dol_print_date($object->import_date, 'dayhour');
        print '</td></tr>';
    }

    if ($isReplaceMode) {
        print '<tr><td>' . $langs->trans("KSEF_ImportMode") . '</td><td>';
        print '<span class="badgeneutral">' . $langs->trans("KSEF_UpwardCorrectionReplace") . '</span>';
        print '</td></tr>';
    }

    print '</table>';
    print '</div>';
    print '</div>';
    print '<div class="clearboth"></div>';

    if ($isReplaceMode) {
        // Replace mode: show both invoices using card-style display

        // Resolve original invoice from credit note source
        $originalInv = null;
        if ($creditNoteInvoice->fk_facture_source > 0) {
            $originalInv = new FactureFournisseur($db);
            if ($originalInv->fetch($creditNoteInvoice->fk_facture_source) <= 0) {
                $originalInv = null;
            }
        }

        // 1. Credit note card
        ksefPrintSupplierInvoiceCard(
            $creditNoteInvoice,
            $langs->trans("KSEF_ReplaceCreditNote"),
            'fa-minus-circle',
            '#bc3434',
            $langs->trans("KSEF_ReplaceCreditNoteCardDesc"),
            $originalInv
        );

        // 2. Replacement invoice card
        ksefPrintSupplierInvoiceCard(
            $linkedInvoice,
            $langs->trans("KSEF_ReplaceNewInvoice"),
            'fa-plus-circle',
            '#46a546',
            $langs->trans("KSEF_ReplaceNewInvoiceDesc")
        );
    } else {
        // Standard mode: simple display
        print '<div class="fichecenter">';
        print '<div class="fichehalfleft">';
        print '<table class="border tableforfield centpercent">';

        if ($object->fk_facture_fourn > 0) {
            print '<tr><td class="titlefield">' . $langs->trans("KSEF_ImportedInvoice") . '</td><td>';
            if ($linkedInvoiceExists) {
                print $linkedInvoice->getNomUrl(1) . ' ' . $linkedInvoice->getLibStatut(5);
            } else {
                print '<span class="warning"><span class="fa fa-exclamation-triangle"></span> ' . $langs->trans("KSEF_LinkedInvoiceDeleted") . '</span>';
            }
            print '</td></tr>';
        }

        if ($creditNoteExists) {
            print '<tr><td>' . $langs->trans("KSEF_ReplaceCreditNote") . '</td><td>';
            print $creditNoteInvoice->getNomUrl(1) . ' ' . $creditNoteInvoice->getLibStatut(5);
            print '</td></tr>';
        }

        print '</table>';
        print '</div>';
        print '</div>';
        print '<div class="clearboth"></div>';
    }

    print dol_get_fiche_end();

    print '<div class="tabsAction">';
    if ($isReplaceMode) {
        print '<a class="butAction" href="' . DOL_URL_ROOT . '/fourn/facture/card.php?facid=' . $creditNoteInvoice->id . '">' . $langs->trans("KSEF_ViewCreditNote") . '</a>';
        print '<a class="butAction" href="' . DOL_URL_ROOT . '/fourn/facture/card.php?facid=' . $object->fk_facture_fourn . '">' . $langs->trans("KSEF_ViewReplacementInvoice") . '</a>';
    } elseif ($linkedInvoiceExists) {
        print '<a class="butAction" href="' . DOL_URL_ROOT . '/fourn/facture/card.php?facid=' . $object->fk_facture_fourn . '">' . $langs->trans("KSEF_ViewLinkedInvoice") . '</a>';
    } elseif ($usercanwrite) {
        print '<a class="butAction" href="' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=reset_import&token=' . newToken() . '">' . $langs->trans("KSEF_ResetImportStatus") . '</a>';
    }
    print '<a class="butAction" href="' . dol_buildpath('/ksef/incoming_card.php', 1) . '?id=' . $object->id . '">' . $langs->trans("Back") . '</a>';
    print '</div>';

    llxFooter();
    $db->close();
    return;
}

if ($object->import_status == 'SKIPPED') {
    print '<div class="fichecenter">';
    print '<div class="fichehalfleft">';
    print '<div class="underbanner clearboth"></div>';

    print '<table class="border tableforfield centpercent">';

    print '<tr><td class="titlefield">' . $langs->trans("Status") . '</td><td>';
    print $object->getLibStatut(5);
    print '</td></tr>';

    print '<tr><td>' . $langs->trans("Invoice") . '</td><td>';
    print '<strong>' . dol_escape_htmltag($object->invoice_number) . '</strong>';
    print ' - ' . dol_print_date($object->invoice_date, 'day');
    print ' - ' . price($object->total_gross, 0, $langs, 1, -1, -1, $object->currency);
    print '</td></tr>';

    print '</table>';

    print '<div class="opacitymedium" style="margin: 10px 0;">';
    print $langs->trans("KSEF_InvoiceSkipped");
    print '</div>';

    print '</div>';
    print '</div>';
    print '<div class="clearboth"></div>';

    print dol_get_fiche_end();

    print '<div class="tabsAction">';
    if ($usercanwrite) {
        print '<a class="butAction" href="' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=reopen&token=' . newToken() . '">' . $langs->trans("KSEF_ReopenForImport") . '</a>';
    }
    print '<a class="butAction" href="' . dol_buildpath('/ksef/incoming_card.php', 1) . '?id=' . $object->id . '">' . $langs->trans("Back") . '</a>';
    print '</div>';

    llxFooter();
    $db->close();
    return;
}


$autoSocid = $object->findMatchingThirdParty();
$selectedSocid = GETPOSTINT('socid');
if ($selectedSocid <= 0) {
    $selectedSocid = $autoSocid;
}

$lines = $object->getLineItems();
$autoMatches = $object->autoMatchLineProducts($lines);

$backtopageRaw = $_SERVER['PHP_SELF'] . '?id=' . $object->id;

print '<form id="ksef_create_supplier_form" method="POST" action="' . DOL_URL_ROOT . '/societe/card.php"></form>';
if (!empty($lines)) {
    foreach ($lines as $line) {
        print '<form id="ksef_create_product_form_' . ((int) ($line['line_num'] ?? 0)) . '" method="POST" action="' . DOL_URL_ROOT . '/product/card.php"></form>';
    }
}

print '<form method="POST" action="' . $_SERVER['PHP_SELF'] . '" id="import_form">';
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" name="id" value="' . $object->id . '">';
print '<input type="hidden" name="action" value="">';

print '<div class="fichecenter">';
print '<div class="fichehalfleft">';
print '<div class="underbanner clearboth"></div>';

print '<table class="border tableforfield centpercent">';

// Current status
print '<tr><td class="titlefield">' . $langs->trans("Status") . '</td><td>';
print $object->getLibStatut(5);
if ($object->import_status == 'ERROR' && !empty($object->import_error)) {
    print '<br><span class="error">' . dol_escape_htmltag($object->import_error) . '</span>';
}
print '</td></tr>';

// Invoice summary
print '<tr><td>' . $langs->trans("Invoice") . '</td><td>';
print '<strong>' . dol_escape_htmltag($object->invoice_number) . '</strong>';
print ' - ' . dol_print_date($object->invoice_date, 'day');
print ' - ' . price($object->total_gross, 0, $langs, 1, -1, -1, $object->currency);
print '</td></tr>';

// Type
print '<tr><td>' . $langs->trans("Type") . '</td><td>';
print ksefGetInvoiceTypeBadge($object->invoice_type);
print '</td></tr>';

$resolvedCorrections = array();
$correctionSourceFound = false;
$correctedCount = 0;
$allCorrectionsImported = true;
$isMultiCorrection = false;
if (KsefIncoming::isCorrectionType($object->invoice_type)) {
    $resolvedCorrections = $object->resolveCorrectedInvoices();
    $correctionData = $object->getCorrectionData();
    $correctedCount = count($resolvedCorrections);
    $isMultiCorrection = ($correctedCount > 1);

    // Check which corrections are imported
    foreach ($resolvedCorrections as $rc) {
        if ($rc['supplier_invoice']) {
            $correctionSourceFound = true;
        } else {
            $allCorrectionsImported = false;
        }
    }

    if (empty($resolvedCorrections)) {
        print '<tr><td>' . $langs->trans("KSEF_CorrectsInvoice") . '</td><td>';
        print '<span class="warning"><span class="fa fa-exclamation-triangle"></span> ' . $langs->trans("KSEF_CorrectionNoReferenceData") . '</span>';
        print '</td></tr>';
    } elseif ($correctedCount == 1) {
        $rc = $resolvedCorrections[0];
        $isSelfReference = (!empty($rc['ksef_number']) && $rc['ksef_number'] === $object->ksef_number);
        print '<tr><td>' . $langs->trans("KSEF_CorrectsInvoice") . '</td><td>';

        // Line 1: KSeF number (links to incoming record if available) + import warnings
        print $langs->trans("KSEF_CorrectsKsefNr") . ': ';
        if ($rc['incoming']) {
            print $rc['incoming']->getNomUrl(1);
            if (!$rc['supplier_invoice'] && $rc['incoming']->fk_facture_fourn <= 0) {
                print '<br><span class="warning"><span class="fa fa-exclamation-triangle"></span> ' . $langs->trans("KSEF_OriginalNotImported") . '</span>';
                print ' <a href="' . dol_buildpath('/ksef/incoming_import.php', 1) . '?id=' . $rc['incoming']->id . '">' . $langs->trans("KSEF_ImportOriginalFirst") . '</a>';
            }
        } elseif (!empty($rc['ksef_number'])) {
            print dol_escape_htmltag($rc['ksef_number']);
            print '<br><span class="warning"><span class="fa fa-exclamation-triangle"></span> ' . $langs->trans("KSEF_OriginalNotInSystem") . '</span>';
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
    } else {
        print '<tr><td>' . $langs->trans("KSEF_CorrectedInvoices") . '</td><td>';
        print $langs->trans("KSEF_CorrectsXInvoices", $correctedCount);
        print '</td></tr>';
    }

    if (!empty($correctionData['reason'])) {
        print '<tr><td>' . $langs->trans("KSEF_CorrectionReason") . '</td>';
        print '<td>' . dol_escape_htmltag($correctionData['reason']) . '</td></tr>';
    }

    // Multi-correction strategy selection
    if ($isMultiCorrection) {
        print '<tr><td>' . $langs->trans("KSEF_CorrectionChooseStrategy") . '</td><td>';

        // Override checkbox if not all originals are imported
        if (!$allCorrectionsImported) {
            print '<div class="warning" style="margin-bottom: 8px;">';
            print '<span class="fa fa-exclamation-triangle"></span> ' . $langs->trans("KSEF_CorrectionNotAllImported");
            print '<br><label style="margin-top: 4px; display: inline-block;">';
            print '<input type="checkbox" id="correction_override" name="correction_override" value="1"> ';
            print $langs->trans("KSEF_CorrectionOverride");
            print '</label>';
            print '</div>';
        }

        // Radio group for correction strategy
        print '<div id="correction_strategy_radios">';

        // Default: standalone
        print '<div style="margin-bottom: 4px;">';
        print '<label><input type="radio" name="correction_source_id" value="-1" checked> ';
        print $langs->trans("KSEF_CorrectionStandalone");
        print '</label>';
        print '<br><span class="small" style="margin-left: 20px;">' . $langs->trans("KSEF_CorrectionStandaloneDesc") . '</span>';
        print '</div>';

        // One radio per imported original
        foreach ($resolvedCorrections as $rc) {
            if ($rc['supplier_invoice']) {
                $refLabel = $rc['supplier_invoice']->ref;
                if (!empty($rc['ksef_number'])) {
                    $refLabel .= ' (' . $rc['ksef_number'] . ')';
                } elseif (!empty($rc['invoice_number'])) {
                    $refLabel .= ' (' . $rc['invoice_number'] . ')';
                }
                print '<div style="margin-bottom: 4px;">';
                print '<label><input type="radio" name="correction_source_id" value="' . $rc['supplier_invoice']->id . '"> ';
                print $langs->trans("KSEF_CorrectionLinkTo", dol_escape_htmltag($refLabel));
                print '</label>';
                print '</div>';
            } else {
                // Unimported original - show with warning, no radio
                $refLabel = !empty($rc['invoice_number']) ? $rc['invoice_number'] : ($rc['ksef_number'] ?? '-');
                print '<div style="margin-bottom: 4px; opacity: 0.6;">';
                print '<span class="fa fa-exclamation-triangle warning" style="margin-right: 4px;"></span>';
                print dol_escape_htmltag($refLabel) . ' - <em>' . $langs->trans("KSEF_CorrectionOriginalNotImported") . '</em>';
                print '</div>';
            }
        }

        print '</div>'; // correction_strategy_radios
        print '</td></tr>';
    }
}

// Upward correction options (total_gross > 0 means more to pay)
$isUpwardCorrection = false;
if (KsefIncoming::isCorrectionType($object->invoice_type) && (float)$object->total_gross > 0) {
    $isUpwardCorrection = true;
    $diffAmount = price(abs($object->total_gross), 0, $langs, 1, -1, -1, $object->currency);
    print '<tr><td><span class="fa fa-exclamation-triangle warning" style="margin-right: 4px;"></span>' . $langs->trans("KSEF_UpwardCorrectionChoose") . '</td><td>';

    print '<div id="upward_correction_radios">';

    // Option 1: difference only (standard invoice for the delta)
    print '<div style="margin-bottom: 4px;">';
    print '<label><input type="radio" name="upward_mode" value="difference" checked> ';
    print $langs->trans("KSEF_UpwardCorrectionDifference");
    print '</label>';
    print '<br><span class="small" style="margin-left: 20px;">' . $langs->trans("KSEF_UpwardCorrectionDifferenceDesc", $diffAmount) . '</span>';
    print '</div>';

    // Option 2: zero out + replace (requires single correction with imported original)
    $canReplace = !$isMultiCorrection && $correctionSourceFound;
    print '<div style="margin-bottom: 4px;' . (!$canReplace ? ' opacity: 0.65;' : '') . '">';
    print '<label><input type="radio" name="upward_mode" value="replace" id="upward_mode_replace"' . (!$canReplace ? ' disabled' : '') . '> ';
    print $langs->trans("KSEF_UpwardCorrectionReplace");
    if (!$canReplace) {
        print ' <span class="small">(' . $langs->trans("KSEF_UpwardCorrectionReplaceRequiresLink") . ')</span>';
    }
    print '</label>';
    print '<br><span class="small" style="margin-left: 20px;">' . $langs->trans("KSEF_UpwardCorrectionReplaceDesc") . '</span>';
    print '</div>';

    print '</div>'; // upward_correction_radios
    print '</td></tr>';
}

// Supplier
print '<tr><td>' . $langs->trans("Supplier") . '</td><td>';

if ($selectedSocid > 0) {
    $supplierObj = new Societe($db);
    if ($supplierObj->fetch($selectedSocid) > 0) {
        print '<strong>' . dol_escape_htmltag($supplierObj->name) . '</strong>';
        if (!empty($object->seller_nip)) {
            print ' (NIP: ' . ksefFormatNIP($object->seller_nip) . ')';
        }
        if (!empty($object->seller_vat_id)) {
            print ' <span class="opacitymedium">(VAT: ' . dol_escape_htmltag($object->seller_vat_id) . ')</span>';
        } elseif (!empty($supplierObj->tva_intra) && empty($object->seller_nip)) {
            print ' <span class="opacitymedium">(' . dol_escape_htmltag($supplierObj->tva_intra) . ')</span>';
        }
        if ($selectedSocid == $autoSocid) {
            print ' <span class="badge badge-info">' . $langs->trans("KSEF_SupplierAutoMatched") . '</span>';
        }
        print '<input type="hidden" name="socid" id="socid_hidden" value="' . $selectedSocid . '">';
        print '<br><a href="#" id="change_supplier_link" class="small">' . $langs->trans("KSEF_SupplierChangeSelection") . '</a>';
        print '<div id="supplier_selector_div" style="display:none; margin-top:5px;">';
    } else {
        $selectedSocid = 0;
    }
}

if ($selectedSocid <= 0) {
    $sellerIdDisplay = '';
    if (!empty($object->seller_nip)) {
        $sellerIdDisplay = 'NIP: ' . ksefFormatNIP($object->seller_nip);
    }
    if (!empty($object->seller_vat_id)) {
        $sellerIdDisplay .= ($sellerIdDisplay ? ', VAT: ' : 'VAT: ') . dol_escape_htmltag($object->seller_vat_id);
    }
    if (!empty($sellerIdDisplay)) {
        print '<span class="warning"><span class="fa fa-exclamation-triangle"></span> ' . $langs->trans("KSEF_SupplierNotFound", $sellerIdDisplay) . '</span><br>';
    }
    print '<div id="supplier_selector_div" style="margin-top:5px;">';
}

print $form->select_company($selectedSocid > 0 ? 0 : '', 'socid_select', 's.fournisseur=1', $langs->trans("SelectThirdParty"), 0, 0, array(), 0, 'minwidth300 maxwidth500');

// Create Supplier - POST
$sForm = 'ksef_create_supplier_form';
print ' ';
print '<input type="hidden" form="' . $sForm . '" name="token" value="' . newToken() . '">';
print '<input type="hidden" form="' . $sForm . '" name="action" value="create">';
print '<input type="hidden" form="' . $sForm . '" name="type" value="f">';
print '<input type="hidden" form="' . $sForm . '" name="backtopage" value="' . dol_escape_htmltag($backtopageRaw) . '">';
if (!empty($object->seller_name)) {
    print '<input type="hidden" form="' . $sForm . '" name="name" value="' . dol_escape_htmltag($object->seller_name) . '">';
}
if (!empty($object->seller_nip) && $object->seller_country == 'PL') {
    $nipField = ksefGetFieldName('NIP');
    if (!empty($nipField) && $nipField !== 'tva_intra') {
        print '<input type="hidden" form="' . $sForm . '" name="' . dol_escape_htmltag($nipField) . '" value="' . dol_escape_htmltag($object->seller_nip) . '">';
    } elseif ($nipField === 'tva_intra') {
        print '<input type="hidden" form="' . $sForm . '" name="tva_intra" value="' . dol_escape_htmltag($object->seller_nip) . '">';
    }
}
if (!empty($object->seller_vat_id)) {
    print '<input type="hidden" form="' . $sForm . '" name="tva_intra" value="' . dol_escape_htmltag($object->seller_vat_id) . '">';
} elseif (!empty($object->seller_nip) && $object->seller_country != 'PL') {
    print '<input type="hidden" form="' . $sForm . '" name="tva_intra" value="' . dol_escape_htmltag($object->seller_nip) . '">';
}
if (!empty($object->seller_address)) {
    print '<input type="hidden" form="' . $sForm . '" name="address" value="' . dol_escape_htmltag($object->seller_address, 0, 1) . '">';
}
if (!empty($object->seller_country)) {
    print '<input type="hidden" form="' . $sForm . '" name="country_code" value="' . dol_escape_htmltag($object->seller_country) . '">';
}
print '<button type="submit" form="' . $sForm . '" class="btnTitle btnTitlePlus" title="' . dol_escape_htmltag($langs->trans("KSEF_CreateSupplier")) . '">';
print '<span class="fa fa-plus-circle valignmiddle btnTitle-icon"></span>';
print '</button>';

print '</div>';

print '</td></tr>';

print '</table>';

print '</div>'; // fichehalfleft


/*
 * Right column - Amounts
 */

print '<div class="fichehalfright">';
print '<div class="underbanner clearboth"></div>';

print '<table class="border tableforfield centpercent">';

// Currency
print '<tr><td class="titlefieldmiddle">' . $langs->trans("Currency") . '</td>';
print '<td>' . dol_escape_htmltag($object->currency) . '</td></tr>';

// Amount HT
print '<tr><td>' . $langs->trans('AmountHT') . '</td>';
print '<td class="nowrap amountcard right">' . price($object->total_net, 0, $langs, 0, -1, -1, $object->currency) . '</td></tr>';

// Amount VAT
print '<tr><td>' . $langs->trans('AmountVAT') . '</td>';
print '<td class="nowrap amountcard right">' . price($object->total_vat, 0, $langs, 0, -1, -1, $object->currency) . '</td></tr>';

// Amount TTC
print '<tr><td>' . $langs->trans('AmountTTC') . '</td>';
print '<td class="nowrap amountcard right">' . price($object->total_gross, 0, $langs, 0, -1, -1, $object->currency) . '</td></tr>';

// Payment due date
if ($object->payment_due_date) {
    print '<tr><td>' . $langs->trans("DateMaxPayment") . '</td>';
    print '<td>' . dol_print_date($object->payment_due_date, 'day') . '</td></tr>';
}

// Payment status from KSeF XML
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

print '</table>';


// Corrected invoice table for multi-corrections
if ($isMultiCorrection) {
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
            if (!$rc['supplier_invoice'] && $rc['incoming']->fk_facture_fourn <= 0) {
                print '<br><span class="warning"><span class="fa fa-exclamation-triangle"></span> ' . $langs->trans("KSEF_OriginalNotImported") . '</span>';
                print ' <a href="' . dol_buildpath('/ksef/incoming_import.php', 1) . '?id=' . $rc['incoming']->id . '">' . $langs->trans("KSEF_ImportOriginalFirst") . '</a>';
            }
        } elseif (!empty($rc['ksef_number'])) {
            print dol_escape_htmltag($rc['ksef_number']);
            print '<br><span class="warning"><span class="fa fa-exclamation-triangle"></span> ' . $langs->trans("KSEF_OriginalNotInSystem") . '</span>';
        } else {
            print '<span class="opacitymedium">-</span>';
        }
        if ($isSelfReference) {
            print '<br><span class="warning"><span class="fa fa-exclamation-triangle"></span> ' . $langs->trans("KSEF_CorrectedKsefNumberSameAsOwn") . '</span>';
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
print '<div class="clearboth"></div>';


/*
 * Line Items Table
 */

if (!empty($lines)) {
    print '<br>';
    print '<div class="div-table-responsive-no-min">';
    print '<table id="tablelines" class="noborder noshadow centpercent">';

    print '<tr class="liste_titre nodrag nodrop">';
    print '<td class="linecolnum center" style="width:30px">#</td>';
    print '<td class="linecoldescription">' . $langs->trans("Description") . '</td>';
    print '<td class="linecolref">' . $langs->trans("Ref") . ' / GTIN</td>';
    print '<td class="linecolqty right">' . $langs->trans("Qty") . '</td>';
    print '<td class="linecolunit">' . $langs->trans("Unit") . '</td>';
    print '<td class="linecoluht right">' . $langs->trans("PriceUHT") . '</td>';
    print '<td class="linecolvat right">' . $langs->trans("VAT") . '</td>';
    print '<td class="linecolht right">' . $langs->trans("TotalHT") . '</td>';
    print '<td class="linecolproduct" style="min-width:250px">' . $langs->trans("KSEF_ProductColumn") . '</td>';
    print '</tr>';

    foreach ($lines as $line) {
        $lineNum = $line['line_num'] ?? 0;
        $autoProductId = isset($autoMatches[$lineNum]) ? $autoMatches[$lineNum]['product_id'] : 0;
        $vatDisplay = (isset($line['vat_rate']) && $line['vat_rate'] === 'zw') ? $langs->trans("KSEF_VATExempt") : ((isset($line['vat_rate']) ? (int)$line['vat_rate'] : 0) . '%');
        $refDisplay = '';
        if (!empty($line['indeks'])) {
            $refDisplay = dol_escape_htmltag($line['indeks']);
        }
        if (!empty($line['gtin'])) {
            $refDisplay .= ($refDisplay ? '<br>' : '') . '<span class="opacitymedium">' . dol_escape_htmltag($line['gtin']) . '</span>';
        }

        print '<tr class="oddeven">';
        print '<td class="linecolnum center">' . $lineNum . '</td>';
        print '<td class="linecoldescription">' . dol_escape_htmltag($line['description'] ?? '') . '</td>';
        print '<td class="linecolref">' . ($refDisplay ?: '–') . '</td>';
        print '<td class="linecolqty right nowraponall">' . price($line['quantity'] ?? 0, 0, '', 1, -1, 4) . '</td>';
        print '<td class="linecolunit">' . dol_escape_htmltag($line['unit'] ?? '') . '</td>';
        print '<td class="linecoluht right nowraponall">' . price($line['unit_price_net'] ?? 0, 0, '', 1, -1, 2) . '</td>';
        print '<td class="linecolvat right nowraponall">' . $vatDisplay . '</td>';
        print '<td class="linecolht right nowraponall">' . price($line['net_amount'] ?? 0, 0, '', 1, -1, 2) . '</td>';

        print '<td class="linecolproduct">';
        $prodHtmlName = 'line_product_' . $lineNum;
        print $form->select_produits(
            $autoProductId,                             // selected
            $prodHtmlName,                              // htmlname (no brackets - breaks AJAX selectors)
            '',                                         // filtertype
            0,                                          // limit
            0,                                          // price_level
            -1,                                         // status (-1 = any sale status, supplier products may not be "for sale")
            2,                                          // finished
            '',                                         // selected_input_value
            0,                                          // hidelabel
            array(),                                    // ajaxoptions
            0,                                          // socid
            '1',                                        // showempty
            0,                                          // forcecombo
            'maxwidth250',                              // morecss
            0,                                          // hidepriceinlabel
            '',                                         // warehouseStatus
            null,                                       // selected_combinations
            0,                                          // nooutput
            1                                           // status_purchase
        );
        // Create Product - POST
        $pForm = 'ksef_create_product_form_' . ((int) $lineNum);
        print ' ';
        print '<input type="hidden" form="' . $pForm . '" name="token" value="' . newToken() . '">';
        print '<input type="hidden" form="' . $pForm . '" name="action" value="create">';
        print '<input type="hidden" form="' . $pForm . '" name="type" value="0">';
        print '<input type="hidden" form="' . $pForm . '" name="backtopage" value="' . dol_escape_htmltag($backtopageRaw) . '">';
        if (!empty($line['indeks'])) {
            print '<input type="hidden" form="' . $pForm . '" name="ref" value="' . dol_escape_htmltag($line['indeks']) . '">';
        }
        if (!empty($line['description'])) {
            print '<input type="hidden" form="' . $pForm . '" name="label" value="' . dol_escape_htmltag($line['description']) . '">';
        }
        if (!empty($line['unit_price_net'])) {
            print '<input type="hidden" form="' . $pForm . '" name="price" value="' . dol_escape_htmltag($line['unit_price_net']) . '">';
        }
        if (isset($line['vat_rate']) && is_numeric($line['vat_rate'])) {
            print '<input type="hidden" form="' . $pForm . '" name="tva_tx" value="' . dol_escape_htmltag($line['vat_rate']) . '">';
        }
        if (!empty($line['gtin'])) {
            print '<input type="hidden" form="' . $pForm . '" name="barcode" value="' . dol_escape_htmltag($line['gtin']) . '">';
        }
        print '<button type="submit" form="' . $pForm . '" class="btnTitle btnTitlePlus" title="' . dol_escape_htmltag($langs->trans("KSEF_CreateProduct")) . '">';
        print '<span class="fa fa-plus-circle valignmiddle paddingleft"></span>';
        print '</button>';
        print '</td>';

        print '</tr>';
    }

    print '<tr class="liste_total">';
    print '<td colspan="7" class="right">' . $langs->trans("Total") . '</td>';
    print '<td class="right nowraponall">' . price($object->total_net, 0, $langs, 0, -1, -1, $object->currency) . '</td>';
    print '<td></td>';
    print '</tr>';

    print '</table>';
    print '</div>';
}

if (isModEnabled('stock')) {
    $stockEnabled = getDolGlobalString('STOCK_CALCULATE_ON_SUPPLIER_BILL');
    $stockShort = $stockEnabled ? $langs->trans('KSEF_StockWillIncrease') : $langs->trans('KSEF_StockWillNotIncrease');
    $stockVerbose = $stockEnabled ? $langs->trans('KSEF_StockAutoIncreaseEnabled') : $langs->trans('KSEF_StockAutoIncreaseDisabled');

    print '<div class="info-box" style="margin-top: 10px;">';
    print $stockShort;
    print ' <span class="fa fa-info-circle classfortooltip" style="opacity: 0.5;" title="' . dol_escape_htmltag($stockVerbose) . '"></span>';
    print '</div>';
}

print '</form>';

print dol_get_fiche_end();


/*
 * Action Buttons
 */

print '<div class="tabsAction">';

$parameters = array();
$reshook = $hookmanager->executeHooks('addMoreActionsButtons', $parameters, $object, $action);
if (empty($reshook)) {
    if ($usercanwrite && ($object->import_status == 'NEW' || $object->import_status == 'ERROR')) {
        if (KsefIncoming::isCorrectionType($object->invoice_type) && !$isMultiCorrection && !$correctionSourceFound && !$isUpwardCorrection) {
            // Single downward correction, original not imported - block
            print '<span class="butActionRefused classfortooltip" title="' . dol_escape_htmltag($langs->trans("KSEF_ImportOriginalFirst")) . '">' . $langs->trans("KSEF_Import") . '</span>';
        } elseif ($isMultiCorrection && !$allCorrectionsImported) {
            // Multi-correction, not all imported - disabled until override checked (JS enables it)
            print '<a class="butActionRefused classfortooltip" href="#" id="btn_import" title="' . dol_escape_htmltag($langs->trans("KSEF_CorrectionNotAllImported")) . '">' . $langs->trans("KSEF_Import") . '</a>';
        } else {
            print '<a class="butAction" href="#" id="btn_import" title="' . dol_escape_htmltag($langs->trans("KSEF_ImportDesc")) . '">' . $langs->trans("KSEF_Import") . '</a>';
        }

        print '<a class="butAction" href="#" id="btn_skip" title="' . dol_escape_htmltag($langs->trans("KSEF_ImportSkipDesc")) . '">' . $langs->trans("KSEF_ImportSkip") . '</a>';
    }

    // Back to card
    print '<a class="butAction" href="' . dol_buildpath('/ksef/incoming_card.php', 1) . '?id=' . $object->id . '">' . $langs->trans("Back") . '</a>';
}

print '</div>';


print '<div id="import-confirm-modal" style="display:none;" title="' . dol_escape_htmltag($langs->trans("KSEF_ImportConfirmTitle")) . '">';
print '<div id="import-confirm-content"></div>';
print '</div>';

$jsLineData = array();
foreach ($lines as $line) {
    $lineNum = $line['line_num'] ?? 0;
    $jsLineData[] = array(
        'num' => $lineNum,
        'desc' => $line['description'] ?? '',
        'indeks' => $line['indeks'] ?? '',
        'gtin' => $line['gtin'] ?? '',
        'qty' => $line['quantity'] ?? 0,
        'unit_price_net' => $line['unit_price_net'] ?? 0,
        'vat_rate' => $line['vat_rate'] ?? 0,
        'net_amount' => $line['net_amount'] ?? 0,
        'field' => 'line_product_' . $lineNum,
    );
}

print '<script type="text/javascript">
$(document).ready(function() {
    var lineData = ' . json_encode($jsLineData) . ';
    var txtFreeText = ' . json_encode($langs->trans("KSEF_ImportAsFreeText")) . ';
    var txtLinkedTo = ' . json_encode($langs->trans("KSEF_ImportLinkedTo")) . ';
    var txtConfirmTitle = ' . json_encode($langs->trans("KSEF_ImportConfirmTitle")) . ';
    var txtConfirmImport = ' . json_encode($langs->trans("KSEF_Import")) . ';
    var txtCancel = ' . json_encode($langs->trans("Cancel")) . ';
    var txtSupplier = ' . json_encode($langs->trans("KSEF_ImportConfirmSupplier")) . ';
    var txtDescription = ' . json_encode($langs->trans("Description")) . ';
    var txtRef = ' . json_encode($langs->trans("Ref")) . ';
    var txtQty = ' . json_encode($langs->trans("Qty")) . ';
    var txtUnitPrice = ' . json_encode($langs->trans("PriceUHT")) . ';
    var txtVAT = ' . json_encode($langs->trans("VAT")) . ';
    var txtTotalHT = ' . json_encode($langs->trans("TotalHT")) . ';
    var txtProduct = ' . json_encode($langs->trans("Product")) . ';
    var txtVATExempt = ' . json_encode($langs->trans("KSEF_VATExempt")) . ';
    var invoiceNumber = ' . json_encode($object->invoice_number) . ';
    var invoiceDate = ' . json_encode(dol_print_date($object->invoice_date, 'day')) . ';
    var invoiceType = ' . json_encode($object->invoice_type) . ';
    var totalGross = ' . json_encode(price($object->total_gross, 0, $langs, 1, -1, -1, $object->currency)) . ';
    var stockModuleEnabled = ' . (isModEnabled('stock') ? 'true' : 'false') . ';
    var stockShortText = ' . json_encode(isModEnabled('stock') ? (getDolGlobalString('STOCK_CALCULATE_ON_SUPPLIER_BILL') ? $langs->trans('KSEF_StockWillIncrease') : $langs->trans('KSEF_StockWillNotIncrease')) : '') . ';
    var isMultiCorrection = ' . ($isMultiCorrection ? 'true' : 'false') . ';
    var isUpwardCorrection = ' . ($isUpwardCorrection ? 'true' : 'false') . ';
    var allCorrectionsImported = ' . ($allCorrectionsImported ? 'true' : 'false') . ';
    var txtCorrectionStandalone = ' . json_encode($langs->trans("KSEF_CorrectionStandalone")) . ';
    var txtCorrectionLinkTo = ' . json_encode($langs->trans("KSEF_CorrectionLinkTo", '%s')) . ';
    var txtUpwardDifference = ' . json_encode($langs->trans("KSEF_UpwardCorrectionDifference")) . ';
    var txtUpwardReplace = ' . json_encode($langs->trans("KSEF_UpwardCorrectionReplace")) . ';

    // Override checkbox toggles import button for multi-corrections
    $("#correction_override").on("change", function() {
        var btn = $("#btn_import");
        if ($(this).is(":checked")) {
            btn.removeClass("butActionRefused").addClass("butAction");
            btn.removeAttr("title");
        } else {
            btn.removeClass("butAction").addClass("butActionRefused");
            btn.attr("title", ' . json_encode($langs->trans("KSEF_CorrectionNotAllImported")) . ');
        }
    });

    // Replace mode availability is determined server-side via $canReplace
    // (disabled for multi-corrections and when original is not imported)

    $("#change_supplier_link").on("click", function(e) {
        e.preventDefault();
        $("#supplier_selector_div").toggle();
    });

    $("select[name=socid_select], input[name=socid_select]").on("change", function() {
        var val = $(this).val();
        if (val > 0) {
            if ($("#socid_hidden").length) {
                $("#socid_hidden").val(val);
            }
        }
    });

    function getSocid() {
        var selectVal = 0;
        if ($("select[name=socid_select]").length) {
            selectVal = parseInt($("select[name=socid_select]").val()) || 0;
        } else if ($("input[name=socid_select]").length) {
            selectVal = parseInt($("input[name=socid_select]").val()) || 0;
        }
        if (selectVal > 0) {
            return selectVal;
        }
        if ($("#socid_hidden").length && $("#socid_hidden").val() > 0) {
            return parseInt($("#socid_hidden").val());
        }
        return 0;
    }

    function getSupplierName() {
        var sel = $("select[name=socid_select]");
        if (sel.length && parseInt(sel.val()) > 0) {
            return sel.find("option:selected").text().trim();
        }
        var searchField = $("input#search_socid_select");
        if (searchField.length && searchField.val()) {
            return searchField.val().trim();
        }
        var supplierStrong = $("td:contains(\'' . addslashes($langs->trans("Supplier")) . '\')").next().find("strong").first();
        if (supplierStrong.length) {
            return supplierStrong.text().trim();
        }
        return "";
    }

    function getProductLabel(fieldName) {
        var sel = $("select[name=" + fieldName + "]");
        if (sel.length && sel.val() > 0) {
            return sel.find("option:selected").text().trim();
        }
        var hidden = $("input[name=" + fieldName + "]");
        if (hidden.length && parseInt(hidden.val()) > 0) {
            var searchField = $("#search_" + fieldName);
            if (searchField.length && searchField.val()) {
                return searchField.val().trim();
            }
            return "#" + hidden.val();
        }
        return "";
    }

    function getProductId(fieldName) {
        var sel = $("select[name=" + fieldName + "]");
        if (sel.length) {
            return parseInt(sel.val()) || 0;
        }
        var hidden = $("input[name=" + fieldName + "]");
        if (hidden.length) {
            return parseInt(hidden.val()) || 0;
        }
        return 0;
    }

    function escapeHtml(str) {
        if (!str) return "";
        var div = document.createElement("div");
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    function buildConfirmDialog() {
        var supplierName = getSupplierName();
        var typeHtml = (invoiceType === "KOR" || invoiceType === "KOR_ZAL" || invoiceType === "KOR_ROZ") ? \' <span class="badge badge-status1">\' + escapeHtml(invoiceType) + \'</span>\' : "";

        var html = "";

        html += \'<table class="border tableforfield centpercent" style="margin-bottom:10px;">\';
        html += \'<tr><td class="titlefield">\' + escapeHtml(txtSupplier) + \'</td>\';
        html += \'<td><strong>\' + escapeHtml(supplierName) + \'</strong></td></tr>\';
        html += \'</table>\';

        html += \'<div class="div-table-responsive">\';
        html += \'<table class="noborder centpercent" style="background:#f8f8f8;">\';
        html += \'<tr class="liste_titre">\';
        html += \'<th>#</th>\';
        html += \'<th>\' + txtDescription + \'</th>\';
        html += \'<th>\' + txtRef + \'</th>\';
        html += \'<th class="right">\' + txtQty + \'</th>\';
        html += \'<th class="right">\' + txtUnitPrice + \'</th>\';
        html += \'<th class="right">\' + txtVAT + \'</th>\';
        html += \'<th class="right">\' + txtTotalHT + \'</th>\';
        html += \'<th>\' + txtProduct + \'</th>\';
        html += \'</tr>\';

        for (var i = 0; i < lineData.length; i++) {
            var line = lineData[i];
            var productId = getProductId(line.field);
            var vatDisplay = (line.vat_rate === "zw") ? txtVATExempt : (parseInt(line.vat_rate) + "%");
            var refDisplay = escapeHtml(line.indeks);
            if (line.gtin) {
                refDisplay += (refDisplay ? "<br>" : "") + \'<span class="opacitymedium">\' + escapeHtml(line.gtin) + \'</span>\';
            }

            html += \'<tr class="oddeven">\';
            html += \'<td>\' + line.num + \'</td>\';
            html += \'<td>\' + escapeHtml(line.desc) + \'</td>\';
            html += \'<td>\' + (refDisplay || "–") + \'</td>\';
            html += \'<td class="right">\' + line.qty + \'</td>\';
            html += \'<td class="right">\' + line.unit_price_net + \'</td>\';
            html += \'<td class="right">\' + vatDisplay + \'</td>\';
            html += \'<td class="right">\' + line.net_amount + \'</td>\';

            if (productId > 0) {
                var productLabel = getProductLabel(line.field);
                html += \'<td><span class="badge badge-status4">\' + txtLinkedTo + \'</span> \' + escapeHtml(productLabel) + \'</td>\';
            } else {
                html += \'<td><span class="badge badge-status1">\' + txtFreeText + \'</span></td>\';
            }
            html += \'</tr>\';
        }

        html += \'</table></div>\';

        // Show correction strategy info in confirm dialog
        var strategyParts = [];
        if (isMultiCorrection) {
            var selectedVal = $("input[name=correction_source_id]:checked").val();
            if (selectedVal == "-1") {
                strategyParts.push(txtCorrectionStandalone);
            } else {
                strategyParts.push($("input[name=correction_source_id]:checked").parent().text().trim());
            }
        }
        if (isUpwardCorrection) {
            var upwardVal = $("input[name=upward_mode]:checked").val();
            strategyParts.push(upwardVal === "replace" ? txtUpwardReplace : txtUpwardDifference);
        }
        if (strategyParts.length > 0) {
            html += \'<div style="margin-top: 8px; padding: 6px; background: #fffbe6; border: 1px solid #ffe58f; border-radius: 3px;">\';
            html += \'<span class="fa fa-info-circle" style="margin-right: 5px;"></span>\';
            html += \'<strong>\' + escapeHtml(strategyParts.join(" · ")) + \'</strong>\';
            html += \'</div>\';
        }

        if (stockModuleEnabled) {
            html += \'<div class="opacitymedium" style="margin-top: 8px;"><span class="fa fa-info-circle" style="margin-right: 5px;"></span>\' + escapeHtml(stockShortText) + \'</div>\';
        }

        return html;
    }

    function submitForm(actionName) {
        var socid = getSocid();
        if (socid <= 0) {
            alert(' . json_encode($langs->trans("KSEF_ImportErrorNoSupplier")) . ');
            return false;
        }
        if ($("#socid_post").length == 0) {
            $("#import_form").append(\'<input type="hidden" name="socid" id="socid_post" value="0">\');
        }
        $("#socid_post").val(socid);
        $("input[name=action]").val(actionName);
        $("#import_form").submit();
    }

    $("#btn_import").on("click", function(e) {
        e.preventDefault();
        if ($(this).hasClass("butActionRefused")) {
            return false;
        }
        var socid = getSocid();
        if (socid <= 0) {
            alert(' . json_encode($langs->trans("KSEF_ImportErrorNoSupplier")) . ');
            return false;
        }

        var html = buildConfirmDialog();
        $("#import-confirm-content").html(html);

        $("#import-confirm-modal").dialog({
            modal: true,
            width: Math.min(900, $(window).width() - 40),
            maxHeight: $(window).height() - 100,
            closeOnEscape: true,
            buttons: [
                {
                    text: txtConfirmImport,
                    class: "butAction",
                    click: function() {
                        $(this).dialog("close");
                        submitForm("import");
                    }
                },
                {
                    text: txtCancel,
                    click: function() {
                        $(this).dialog("close");
                    }
                }
            ]
        });
    });

    $("#btn_skip").on("click", function(e) {
        e.preventDefault();
        if (confirm(' . json_encode($langs->trans("KSEF_ImportSkipConfirm")) . ')) {
            $("input[name=action]").val("skip");
            $("#import_form").submit();
        }
    });
});
</script>';


llxFooter();
$db->close();
