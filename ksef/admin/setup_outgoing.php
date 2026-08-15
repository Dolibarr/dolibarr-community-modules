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
 * \file    admin/setup_outgoing.php
 * \ingroup ksef
 * \brief   KSEF outgoing invoice settings tab
 */

// CSRF Check
if (!defined('CSRFCHECK_WITH_TOKEN')) {
    define('CSRFCHECK_WITH_TOKEN', '1');
}

$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
    $res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"] . "/main.inc.php";
}
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
    $i--;
    $j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1)) . "/main.inc.php")) {
    $res = @include substr($tmp, 0, ($i + 1)) . "/main.inc.php";
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1))) . "/main.inc.php")) {
    $res = @include dirname(substr($tmp, 0, ($i + 1))) . "/main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
    $res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
    $res = @include "../../../main.inc.php";
}
if (!$res) {
    die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/class/html.form.class.php';
require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
dol_include_once('/ksef/lib/ksef.lib.php');
dol_include_once('/core/class/extrafields.class.php');

if (!$user->admin) {
    accessforbidden();
}

$langs->loadLangs(array("admin", "ksef@ksef"));

$action = GETPOST('action', 'aZ09');

if ($action == 'update') {
    // Optional Fields
    $fa3_nrklienta_val = GETPOST('KSEF_FA3_INCLUDE_NRKLIENTA', 'alpha') ? '1' : '0';
    dolibarr_set_const($db, 'KSEF_FA3_INCLUDE_NRKLIENTA', $fa3_nrklienta_val, 'chaine', 0, '', $conf->entity);

    $fa3_indeks_val = GETPOST('KSEF_FA3_INCLUDE_INDEKS', 'alpha') ? '1' : '0';
    dolibarr_set_const($db, 'KSEF_FA3_INCLUDE_INDEKS', $fa3_indeks_val, 'chaine', 0, '', $conf->entity);

    $fa3_gtin_val = GETPOST('KSEF_FA3_INCLUDE_GTIN', 'alpha') ? '1' : '0';
    dolibarr_set_const($db, 'KSEF_FA3_INCLUDE_GTIN', $fa3_gtin_val, 'chaine', 0, '', $conf->entity);

    $fa3_unit_val = GETPOST('KSEF_FA3_INCLUDE_UNIT', 'alpha') ? '1' : '0';
    dolibarr_set_const($db, 'KSEF_FA3_INCLUDE_UNIT', $fa3_unit_val, 'chaine', 0, '', $conf->entity);

    $fa3_bankdesc_val = GETPOST('KSEF_FA3_INCLUDE_BANK_DESC', 'alpha') ? '1' : '0';
    dolibarr_set_const($db, 'KSEF_FA3_INCLUDE_BANK_DESC', $fa3_bankdesc_val, 'chaine', 0, '', $conf->entity);

    // Buyer contact in DaneKontaktowe
    $fa3_contact_val = GETPOST('KSEF_FA3_INCLUDE_CONTACT', 'alpha') ? '1' : '0';
    dolibarr_set_const($db, 'KSEF_FA3_INCLUDE_CONTACT', $fa3_contact_val, 'chaine', 0, '', $conf->entity);

    // Place of Issue
    $place_of_issue_mode = GETPOST('KSEF_FA3_PLACE_OF_ISSUE_MODE', 'alpha');
    if (in_array($place_of_issue_mode, array('disabled', 'company', 'custom'))) {
        dolibarr_set_const($db, 'KSEF_FA3_PLACE_OF_ISSUE_MODE', $place_of_issue_mode, 'chaine', 0, '', $conf->entity);
    }
    $place_of_issue_custom = GETPOST('KSEF_FA3_PLACE_OF_ISSUE_CUSTOM', 'alphanohtml');
    dolibarr_set_const($db, 'KSEF_FA3_PLACE_OF_ISSUE_CUSTOM', trim($place_of_issue_custom), 'chaine', 0, '', $conf->entity);

    // Sale Date Source
    $sale_date_source = GETPOST('KSEF_FA3_SALE_DATE_SOURCE', 'alpha');
    if (in_array($sale_date_source, array('invoice_date', 'delivery_date'))) {
        dolibarr_set_const($db, 'KSEF_FA3_SALE_DATE_SOURCE', $sale_date_source, 'chaine', 0, '', $conf->entity);
    }

    // NrZamowienia source
    $nr_zamowienia_source = GETPOST('KSEF_NR_ZAMOWIENIA_SOURCE', 'alphanohtml');
    if (in_array($nr_zamowienia_source, array('ref_client', 'linked_order', 'disabled')) || strpos($nr_zamowienia_source, 'extrafield:') === 0) {
        dolibarr_set_const($db, 'KSEF_NR_ZAMOWIENIA_SOURCE', $nr_zamowienia_source, 'chaine', 0, '', $conf->entity);
    }

    // NrUmowy source
    $nr_umowy_source = GETPOST('KSEF_NR_UMOWY_SOURCE', 'alphanohtml');
    if ($nr_umowy_source === 'disabled' || strpos($nr_umowy_source, 'thirdparty_extrafield:') === 0 || strpos($nr_umowy_source, 'extrafield:') === 0) {
        dolibarr_set_const($db, 'KSEF_NR_UMOWY_SOURCE', $nr_umowy_source, 'chaine', 0, '', $conf->entity);
    }

    // Parse date out of NrUmowy into DataUmowy
    $nr_umowy_parse_date = GETPOST('KSEF_NR_UMOWY_PARSE_DATE', 'alpha') ? '1' : '0';
    dolibarr_set_const($db, 'KSEF_NR_UMOWY_PARSE_DATE', $nr_umowy_parse_date, 'chaine', 0, '', $conf->entity);

    // VAT Exemption legal basis
    $zwolnienie_type = GETPOST('KSEF_ZWOLNIENIE_TYPE', 'alpha');
    if (in_array($zwolnienie_type, array('disabled', 'P_19A', 'P_19B', 'P_19C'))) {
        dolibarr_set_const($db, 'KSEF_ZWOLNIENIE_TYPE', $zwolnienie_type, 'chaine', 0, '', $conf->entity);
    }
    $zwolnienie_podstawa = GETPOST('KSEF_ZWOLNIENIE_PODSTAWA', 'alphanohtml');
    dolibarr_set_const($db, 'KSEF_ZWOLNIENIE_PODSTAWA', mb_substr(trim($zwolnienie_podstawa), 0, 256, 'UTF-8'), 'chaine', 0, '', $conf->entity);

    $zwolnienie_product_field = GETPOST('KSEF_ZWOLNIENIE_PRODUCT_FIELD', 'alphanohtml');
    dolibarr_set_const($db, 'KSEF_ZWOLNIENIE_PRODUCT_FIELD', trim($zwolnienie_product_field), 'chaine', 0, '', $conf->entity);

    // QR Code checkbox
    $qr_val = GETPOST('KSEF_ADD_QR', 'alpha') ? '1' : '0';
    dolibarr_set_const($db, 'KSEF_ADD_QR', $qr_val, 'chaine', 0, '', $conf->entity);

    // Disable Validate and Upload
    $disable_validate_and_upload = GETPOST('KSEF_DISABLE_VALIDATE_AND_UPLOAD', 'alpha') ? '1' : '0';
    dolibarr_set_const($db, 'KSEF_DISABLE_VALIDATE_AND_UPLOAD', $disable_validate_and_upload, 'chaine', 0, '', $conf->entity);

    // Confirmation before upload
    $confirm_before_upload = GETPOST('KSEF_CONFIRM_BEFORE_UPLOAD', 'alpha') ? '1' : '0';
    dolibarr_set_const($db, 'KSEF_CONFIRM_BEFORE_UPLOAD', $confirm_before_upload, 'chaine', 0, '', $conf->entity);

    // Show PDF after upload
    $show_pdf_after_upload = GETPOST('KSEF_SHOW_PDF_AFTER_UPLOAD', 'alpha') ? '1' : '0';
    dolibarr_set_const($db, 'KSEF_SHOW_PDF_AFTER_UPLOAD', $show_pdf_after_upload, 'chaine', 0, '', $conf->entity);

    // Confirmation PDF template
    dolibarr_set_const($db, 'KSEF_CONFIRM_PDF_TEMPLATE', GETPOST('KSEF_CONFIRM_PDF_TEMPLATE', 'alphanohtml'), 'chaine', 0, '', $conf->entity);

    // Payment defaults
    dolibarr_set_const($db, 'KSEF_DEFAULT_PAYMENT_TERM_ID', GETPOST('KSEF_DEFAULT_PAYMENT_TERM_ID', 'int'), 'chaine', 0, '', $conf->entity);
    dolibarr_set_const($db, 'KSEF_DEFAULT_PAYMENT_METHOD_ID', GETPOST('KSEF_DEFAULT_PAYMENT_METHOD_ID', 'int'), 'chaine', 0, '', $conf->entity);
    dolibarr_set_const($db, 'KSEF_DEFAULT_BANK_ACCOUNT_ID', GETPOST('KSEF_DEFAULT_BANK_ACCOUNT_ID', 'int'), 'chaine', 0, '', $conf->entity);

    // Correction invoices
    $corr_reason_preset = GETPOST('KSEF_DEFAULT_CORRECTION_REASON_PRESET', 'alphanohtml');
    if ($corr_reason_preset === 'custom') {
        $corr_default_reason = trim(GETPOST('KSEF_DEFAULT_CORRECTION_REASON_CUSTOM', 'alphanohtml'));
    } elseif (!empty($corr_reason_preset)) {
        $corr_default_reason = $corr_reason_preset;
    } else {
        $corr_default_reason = '';
    }
    dolibarr_set_const($db, 'KSEF_DEFAULT_CORRECTION_REASON', $corr_default_reason, 'chaine', 0, '', $conf->entity);

    $corr_default_type = GETPOST('KSEF_DEFAULT_CORRECTION_TYPE', 'int');
    if (in_array($corr_default_type, array('1', '2', '3', ''))) {
        dolibarr_set_const($db, 'KSEF_DEFAULT_CORRECTION_TYPE', $corr_default_type, 'chaine', 0, '', $conf->entity);
    }

    $kor_line_method = GETPOST('KSEF_KOR_LINE_METHOD', 'alpha');
    if (in_array($kor_line_method, array('differential', 'stanprzed'))) {
        dolibarr_set_const($db, 'KSEF_KOR_LINE_METHOD', $kor_line_method, 'chaine', 0, '', $conf->entity);
    }

    // Entity fields (Podmiot)
    $idnabywcy_source = GETPOST('KSEF_IDNABYWCY_SOURCE', 'alphanohtml');
    if ($idnabywcy_source === 'disabled' || $idnabywcy_source === 'code_client' || strpos($idnabywcy_source, 'thirdparty_extrafield:') === 0) {
        dolibarr_set_const($db, 'KSEF_IDNABYWCY_SOURCE', $idnabywcy_source, 'chaine', 0, '', $conf->entity);
    }

    // GTU / Procedura sources
    $gtu_source = GETPOST('KSEF_GTU_SOURCE', 'alphanohtml');
    if ($gtu_source === 'disabled' || strpos($gtu_source, 'product_extrafield:') === 0) {
        dolibarr_set_const($db, 'KSEF_GTU_SOURCE', $gtu_source, 'chaine', 0, '', $conf->entity);
    }

    $procedura_source = GETPOST('KSEF_PROCEDURA_SOURCE', 'alphanohtml');
    if ($procedura_source === 'disabled' || strpos($procedura_source, 'product_extrafield:') === 0) {
        dolibarr_set_const($db, 'KSEF_PROCEDURA_SOURCE', $procedura_source, 'chaine', 0, '', $conf->entity);
    }

    // UU_ID per line
    $uu_id_val = GETPOST('KSEF_FA3_INCLUDE_UU_ID', 'alpha') ? '1' : '0';
    dolibarr_set_const($db, 'KSEF_FA3_INCLUDE_UU_ID', $uu_id_val, 'chaine', 0, '', $conf->entity);

    // TP flag source
    $tp_source = GETPOST('KSEF_TP_SOURCE', 'alphanohtml');
    if ($tp_source === 'disabled' || strpos($tp_source, 'thirdparty_extrafield:') === 0 || strpos($tp_source, 'extrafield:') === 0) {
        dolibarr_set_const($db, 'KSEF_TP_SOURCE', $tp_source, 'chaine', 0, '', $conf->entity);
    }

    // Adnotacje flag sources
    $ksefFlagAllowed = array(
        'KSEF_FA3_MPP_SOURCE' => array('disabled', 'extrafield:'),
        'KSEF_FA3_FP_SOURCE'  => array('disabled', 'extrafield:'),
        'KSEF_P16_SOURCE'     => array('disabled', 'always_on'),
        'KSEF_P17_SOURCE'     => array('disabled', 'extrafield:'),
        'KSEF_P17_TP_SOURCE'  => array('disabled', 'thirdparty_extrafield:'),
        'KSEF_P18_SOURCE'     => array('disabled', 'always_on', 'extrafield:'),
        'KSEF_P18_TP_SOURCE'  => array('disabled', 'thirdparty_extrafield:'),
    );
    foreach ($ksefFlagAllowed as $_flagConst => $_allowed) {
        $_v = GETPOST($_flagConst, 'alphanohtml');
        $_ok = false;
        foreach ($_allowed as $_mode) {
            if (substr($_mode, -1) === ':') {
                if (strpos($_v, $_mode) === 0 && strlen($_v) > strlen($_mode)) { $_ok = true; break; }
            } elseif ($_v === $_mode) {
                $_ok = true;
                break;
            }
        }
        if ($_ok) {
            dolibarr_set_const($db, $_flagConst, $_v, 'chaine', 0, '', $conf->entity);
        }
    }

    // Podmiot3
    $p3_enable = GETPOST('KSEF_PODMIOT3_SOURCE', 'alpha') ? 'enabled' : 'disabled';
    dolibarr_set_const($db, 'KSEF_PODMIOT3_SOURCE', $p3_enable, 'chaine', 0, '', $conf->entity);

    $p3_role = GETPOST('KSEF_PODMIOT3_ROLE', 'alphanohtml');
    if (in_array($p3_role, array('6', '2', '3', '4', '5', '7', '8', '9', '10', '11', '1'), true)) {
        dolibarr_set_const($db, 'KSEF_PODMIOT3_ROLE', $p3_role, 'chaine', 0, '', $conf->entity);
    }

    $idwew_source = GETPOST('KSEF_IDWEW_SOURCE', 'alphanohtml');
    if ($idwew_source === 'disabled' || strpos($idwew_source, 'thirdparty_extrafield:') === 0) {
        dolibarr_set_const($db, 'KSEF_IDWEW_SOURCE', $idwew_source, 'chaine', 0, '', $conf->entity);
    }

    // Note mode
    $combined_note = GETPOST('KSEF_NOTE_COMBINED_MODE', 'alpha');
    $noteModeMaps = array(
        'disabled'           => array('mode' => 'disabled', 'target' => 'stopka_faktury'),
        'simple_stopka'      => array('mode' => 'simple',   'target' => 'stopka_faktury'),
        'simple_dodatkowy'   => array('mode' => 'simple',   'target' => 'dodatkowy_opis'),
        'keyvalue_dodatkowy' => array('mode' => 'keyvalue', 'target' => 'dodatkowy_opis'),
    );
    if (isset($noteModeMaps[$combined_note])) {
        dolibarr_set_const($db, 'KSEF_DODATKOWY_OPIS_NOTE_MODE', $noteModeMaps[$combined_note]['mode'], 'chaine', 0, '', $conf->entity);
        dolibarr_set_const($db, 'KSEF_NOTE_PUBLIC_TARGET', $noteModeMaps[$combined_note]['target'], 'chaine', 0, '', $conf->entity);
    }

    // StopkaFaktury boilerplate
    $stopka_boilerplate = GETPOST('KSEF_STOPKA_BOILERPLATE', 'restricthtml');
    $stopka_boilerplate = trim(strip_tags($stopka_boilerplate));
    if (mb_strlen($stopka_boilerplate, 'UTF-8') > 3500) {
        $stopka_boilerplate = mb_substr($stopka_boilerplate, 0, 3500, 'UTF-8');
    }
    dolibarr_set_const($db, 'KSEF_STOPKA_BOILERPLATE', $stopka_boilerplate, 'chaine', 0, '', $conf->entity);

    // Extrafields
    $ef_save = new ExtraFields($db);
    $_dodUnsupportedTypes = ksefDodatkowyOpisUnsupportedTypes();

    // Invoice extrafields
    $ef_save->fetch_name_optionals_label('facture');
    $extrafields_val = array();
    $facture_assign_nr_zamowienia = '';
    $facture_assign_nr_umowy = '';
    $facture_assign_tp = '';
    $facture_assign_mpp = '';
    $facture_assign_fp = '';
    $facture_assign_p17 = '';
    $facture_assign_p18 = '';
    foreach (array_keys($ef_save->attributes['facture']['label'] ?? array()) as $fname) {
        if (strpos($fname, 'ksef_') === 0) continue;
        $_t = $ef_save->attributes['facture']['type'][$fname] ?? '';
        if (in_array($_t, $_dodUnsupportedTypes)) continue;
        $assign = GETPOST('KSEF_ASSIGN_FACTURE_' . $fname, 'alphanohtml');
        if ($assign === 'dodatkowy' || $assign === 'stopka') {
            $extrafields_val[] = $fname . ':' . $assign;
        } elseif ($assign === 'nr_zamowienia') {
            $facture_assign_nr_zamowienia = $fname;
        } elseif ($assign === 'nr_umowy') {
            $facture_assign_nr_umowy = $fname;
        } elseif ($assign === 'tp') {
            $facture_assign_tp = $fname;
        } elseif ($assign === 'mpp') {
            $facture_assign_mpp = $fname;
        } elseif ($assign === 'fp') {
            $facture_assign_fp = $fname;
        } elseif ($assign === 'p17') {
            $facture_assign_p17 = $fname;
        } elseif ($assign === 'p18') {
            $facture_assign_p18 = $fname;
        }
    }
    dolibarr_set_const($db, 'KSEF_DODATKOWY_OPIS_EXTRAFIELDS', implode(',', $extrafields_val), 'chaine', 0, '', $conf->entity);
    // Apply row selector overrides
    if ($facture_assign_nr_zamowienia !== '') {
        dolibarr_set_const($db, 'KSEF_NR_ZAMOWIENIA_SOURCE', 'extrafield:' . $facture_assign_nr_zamowienia, 'chaine', 0, '', $conf->entity);
    }
    if ($facture_assign_nr_umowy !== '') {
        dolibarr_set_const($db, 'KSEF_NR_UMOWY_SOURCE', 'extrafield:' . $facture_assign_nr_umowy, 'chaine', 0, '', $conf->entity);
    }
    if ($facture_assign_tp !== '') {
        dolibarr_set_const($db, 'KSEF_TP_SOURCE', 'extrafield:' . $facture_assign_tp, 'chaine', 0, '', $conf->entity);
    }
    if ($facture_assign_mpp !== '') {
        dolibarr_set_const($db, 'KSEF_FA3_MPP_SOURCE', 'extrafield:' . $facture_assign_mpp, 'chaine', 0, '', $conf->entity);
    }
    if ($facture_assign_fp !== '') {
        dolibarr_set_const($db, 'KSEF_FA3_FP_SOURCE', 'extrafield:' . $facture_assign_fp, 'chaine', 0, '', $conf->entity);
    }
    if ($facture_assign_p17 !== '') {
        dolibarr_set_const($db, 'KSEF_P17_SOURCE', 'extrafield:' . $facture_assign_p17, 'chaine', 0, '', $conf->entity);
    }
    if ($facture_assign_p18 !== '') {
        dolibarr_set_const($db, 'KSEF_P18_SOURCE', 'extrafield:' . $facture_assign_p18, 'chaine', 0, '', $conf->entity);
    }

    // Line extrafields
    $ef_save->fetch_name_optionals_label('facturedet');
    $det_extrafields_val = array();
    foreach (array_keys($ef_save->attributes['facturedet']['label'] ?? array()) as $fname) {
        if (strpos($fname, 'ksef_') === 0) continue;
        $_t = $ef_save->attributes['facturedet']['type'][$fname] ?? '';
        if (in_array($_t, $_dodUnsupportedTypes)) continue;
        $assign = GETPOST('KSEF_ASSIGN_FACTUREDET_' . $fname, 'alphanohtml');
        if ($assign === 'dodatkowy') {
            $det_extrafields_val[] = $fname;
        }
    }
    dolibarr_set_const($db, 'KSEF_DODATKOWY_OPIS_DET_EXTRAFIELDS', implode(',', $det_extrafields_val), 'chaine', 0, '', $conf->entity);

    // Product extrafields
    $ef_save->fetch_name_optionals_label('product');
    $product_extrafields_val = array();
    $product_assign_gtu = '';
    $product_assign_procedura = '';
    $product_assign_zwolnienie = '';
    foreach (array_keys($ef_save->attributes['product']['label'] ?? array()) as $fname) {
        if (strpos($fname, 'ksef_') === 0) continue;
        $_t = $ef_save->attributes['product']['type'][$fname] ?? '';
        if (in_array($_t, $_dodUnsupportedTypes)) continue;
        $assign = GETPOST('KSEF_ASSIGN_PRODUCT_' . $fname, 'alphanohtml');
        if ($assign === 'dodatkowy') {
            $product_extrafields_val[] = $fname;
        } elseif ($assign === 'gtu') {
            $product_assign_gtu = $fname;
        } elseif ($assign === 'procedura') {
            $product_assign_procedura = $fname;
        } elseif ($assign === 'zwolnienie') {
            $product_assign_zwolnienie = $fname;
        }
    }
    dolibarr_set_const($db, 'KSEF_DODATKOWY_OPIS_PRODUCT_EXTRAFIELDS', implode(',', $product_extrafields_val), 'chaine', 0, '', $conf->entity);
    // Apply row selector overrides
    if ($product_assign_gtu !== '') {
        dolibarr_set_const($db, 'KSEF_GTU_SOURCE', 'product_extrafield:' . $product_assign_gtu, 'chaine', 0, '', $conf->entity);
    }
    if ($product_assign_procedura !== '') {
        dolibarr_set_const($db, 'KSEF_PROCEDURA_SOURCE', 'product_extrafield:' . $product_assign_procedura, 'chaine', 0, '', $conf->entity);
    }
    if ($product_assign_zwolnienie !== '') {
        dolibarr_set_const($db, 'KSEF_ZWOLNIENIE_PRODUCT_FIELD', $product_assign_zwolnienie, 'chaine', 0, '', $conf->entity);
    }

    // Societe extrafields
    $ef_save->fetch_name_optionals_label('societe');
    $societe_extrafields_val = array();
    $societe_assign_idnabywcy = '';
    $societe_assign_nr_umowy = '';
    $societe_assign_tp = '';
    $societe_assign_idwew = '';
    $societe_assign_p17 = '';
    $societe_assign_p18 = '';
    foreach (array_keys($ef_save->attributes['societe']['label'] ?? array()) as $fname) {
        if (strpos($fname, 'ksef_') === 0) continue;
        $_t = $ef_save->attributes['societe']['type'][$fname] ?? '';
        if (in_array($_t, $_dodUnsupportedTypes)) continue;
        $assign = GETPOST('KSEF_ASSIGN_SOCIETE_' . $fname, 'alphanohtml');
        if ($assign === 'dodatkowy' || $assign === 'stopka') {
            $societe_extrafields_val[] = $fname . ':' . $assign;
        } elseif ($assign === 'idnabywcy') {
            $societe_assign_idnabywcy = $fname;
        } elseif ($assign === 'nr_umowy') {
            $societe_assign_nr_umowy = $fname;
        } elseif ($assign === 'tp') {
            $societe_assign_tp = $fname;
        } elseif ($assign === 'idwew') {
            $societe_assign_idwew = $fname;
        } elseif ($assign === 'p17') {
            $societe_assign_p17 = $fname;
        } elseif ($assign === 'p18') {
            $societe_assign_p18 = $fname;
        }
    }
    dolibarr_set_const($db, 'KSEF_DODATKOWY_OPIS_SOCIETE_EXTRAFIELDS', implode(',', $societe_extrafields_val), 'chaine', 0, '', $conf->entity);
    // Apply row selector overrides
    if ($societe_assign_idnabywcy !== '') {
        dolibarr_set_const($db, 'KSEF_IDNABYWCY_SOURCE', 'thirdparty_extrafield:' . $societe_assign_idnabywcy, 'chaine', 0, '', $conf->entity);
    }
    if ($societe_assign_nr_umowy !== '') {
        dolibarr_set_const($db, 'KSEF_NR_UMOWY_SOURCE', 'thirdparty_extrafield:' . $societe_assign_nr_umowy, 'chaine', 0, '', $conf->entity);
    }
    if ($societe_assign_tp !== '') {
        dolibarr_set_const($db, 'KSEF_TP_SOURCE', 'thirdparty_extrafield:' . $societe_assign_tp, 'chaine', 0, '', $conf->entity);
    }
    if ($societe_assign_idwew !== '') {
        dolibarr_set_const($db, 'KSEF_IDWEW_SOURCE', 'thirdparty_extrafield:' . $societe_assign_idwew, 'chaine', 0, '', $conf->entity);
    }
    if ($societe_assign_p17 !== '') {
        dolibarr_set_const($db, 'KSEF_P17_TP_SOURCE', 'thirdparty_extrafield:' . $societe_assign_p17, 'chaine', 0, '', $conf->entity);
    }
    if ($societe_assign_p18 !== '') {
        dolibarr_set_const($db, 'KSEF_P18_TP_SOURCE', 'thirdparty_extrafield:' . $societe_assign_p18, 'chaine', 0, '', $conf->entity);
    }

    // Project extrafields
    $ef_save->fetch_name_optionals_label('projet');
    $project_extrafields_val = array();
    foreach (array_keys($ef_save->attributes['projet']['label'] ?? array()) as $fname) {
        if (strpos($fname, 'ksef_') === 0) continue;
        $_t = $ef_save->attributes['projet']['type'][$fname] ?? '';
        if (in_array($_t, $_dodUnsupportedTypes)) continue;
        $assign = GETPOST('KSEF_ASSIGN_PROJET_' . $fname, 'alphanohtml');
        if ($assign === 'dodatkowy' || $assign === 'stopka') {
            $project_extrafields_val[] = $fname . ':' . $assign;
        }
    }
    dolibarr_set_const($db, 'KSEF_DODATKOWY_OPIS_PROJECT_EXTRAFIELDS', implode(',', $project_extrafields_val), 'chaine', 0, '', $conf->entity);

    setEventMessages($langs->trans("SetupSaved"), null, 'mesgs');
    header("Location: " . $_SERVER["PHP_SELF"]);
    exit;
}

// DodatkowyOpis create extrafield
if ($action == 'create_dodatkowy_extrafield') {
    $label = trim(GETPOST('ef_label', 'alphanohtml'));
    $type = GETPOST('ef_type', 'alpha');
    $optionsRaw = GETPOST('ef_options', 'restricthtml');
    $efTarget = GETPOST('ef_target', 'alpha');
    if (!in_array($efTarget, array('facture', 'facturedet', 'societe', 'product', 'projet'))) $efTarget = 'facture';

    $validTypes = array('varchar', 'text', 'int', 'double', 'date', 'datetime', 'select');

    $err = '';
    $code = '';
    $ef_create = new ExtraFields($db);
    $ef_create->fetch_name_optionals_label($efTarget);

    if ($label === '') {
        $err = $langs->trans('KSEF_DODATKOWY_OPIS_CREATE_FIELD_ERROR_LABEL');
    } elseif (!in_array($type, $validTypes)) {
        $err = $langs->trans('KSEF_DODATKOWY_OPIS_CREATE_FIELD_ERROR_TYPE');
    } else {
        // Reject duplicate label (case-insensitive)
        $labelLower = mb_strtolower($label, 'UTF-8');
        foreach (($ef_create->attributes[$efTarget]['label'] ?? array()) as $existingName => $existingLabel) {
            if (strpos($existingName, 'ksef_') === 0) continue;
            $translated = $langs->trans($existingLabel);
            if (mb_strtolower($translated, 'UTF-8') === $labelLower) {
                $err = $langs->trans('KSEF_DODATKOWY_OPIS_CREATE_FIELD_ERROR_DUP_LABEL');
                break;
            }
        }
    }

    if (!$err) {
        $baseCode = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $label));
        $baseCode = trim($baseCode, '_');
        if ($baseCode === '' || !preg_match('/^[a-z]/', $baseCode)) {
            $baseCode = 'cf_' . $baseCode;
            $baseCode = trim($baseCode, '_');
        }
        if (strpos($baseCode, 'ksef_') === 0) {
            $baseCode = 'cf_' . substr($baseCode, 5);
        }
        $baseCode = substr($baseCode, 0, 50);
        $baseCode = rtrim($baseCode, '_');
        if ($baseCode === '' || !preg_match('/^[a-z][a-z0-9_]*$/', $baseCode)) {
            $err = $langs->trans('KSEF_DODATKOWY_OPIS_CREATE_FIELD_ERROR_LABEL');
        } else {
            // Avoid collisions
            $code = $baseCode;
            $counter = 2;
            while (isset($ef_create->attributes[$efTarget]['label'][$code])) {
                $code = $baseCode . '_' . $counter;
                $counter++;
                if ($counter > 100) { $err = 'Too many collisions'; break; }
            }
        }
    }

    if (!$err) {
        // Build param for select type
        $param = '';
        if ($type === 'select') {
            $options = array();
            $lines = preg_split('/\r\n|\r|\n/', $optionsRaw);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '') continue;
                if (strpos($line, '|') !== false) {
                    list($optCode, $optLabel) = explode('|', $line, 2);
                    $optCode = trim($optCode);
                    $optLabel = trim($optLabel);
                } else {
                    $optLabel = $line;
                    $optCode = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $optLabel));
                    $optCode = trim($optCode, '_');
                }
                if ($optCode !== '' && $optLabel !== '') {
                    $options[$optCode] = $optLabel;
                }
            }
            $param = array('options' => $options);
        }

        // Compute auto pos
        $maxPos = 200;
        if (!empty($ef_create->attributes[$efTarget]['pos'])) {
            $maxPos = max(array_map('intval', $ef_create->attributes[$efTarget]['pos'])) + 10;
        }

        $size = ($type === 'varchar') ? '255' : '';

        $result = $ef_create->addExtraField(
            $code, $label, $type, $maxPos, $size, $efTarget,
            0, 0, '', $param, 1, '', '1', '', '', '', 'ksef@ksef', '1', 0, 0
        );

        if ($result > 0) {
            // Auto-add to config
            $efTargetConfigMap = array(
                'facture' => 'KSEF_DODATKOWY_OPIS_EXTRAFIELDS',
                'facturedet' => 'KSEF_DODATKOWY_OPIS_DET_EXTRAFIELDS',
                'product' => 'KSEF_DODATKOWY_OPIS_PRODUCT_EXTRAFIELDS',
                'societe' => 'KSEF_DODATKOWY_OPIS_SOCIETE_EXTRAFIELDS',
                'projet' => 'KSEF_DODATKOWY_OPIS_PROJECT_EXTRAFIELDS',
            );
            // Types with target selection
            $efTargetWithSelection = array('facture', 'societe', 'projet');
            if (isset($efTargetConfigMap[$efTarget])) {
                $configKey = $efTargetConfigMap[$efTarget];
                $currentEf = getDolGlobalString($configKey, '');
                $currentList = array_filter(array_map('trim', explode(',', $currentEf)));
                $fieldEntry = in_array($efTarget, $efTargetWithSelection) ? $code . ':dodatkowy' : $code;
                $alreadyExists = false;
                foreach ($currentList as $entry) {
                    $parts = explode(':', $entry, 2);
                    if ($parts[0] === $code) { $alreadyExists = true; break; }
                }
                if (!$alreadyExists) {
                    $currentList[] = $fieldEntry;
                }
                dolibarr_set_const($db, $configKey, implode(',', $currentList), 'chaine', 0, '', $conf->entity);
            }
            // Auto-set NrUmowy source for new extrafield
            if ($efTarget === 'societe' && getDolGlobalString('KSEF_NR_UMOWY_SOURCE', 'disabled') === 'disabled') {
                dolibarr_set_const($db, 'KSEF_NR_UMOWY_SOURCE', 'thirdparty_extrafield:' . $code, 'chaine', 0, '', $conf->entity);
            }
            setEventMessages(sprintf($langs->trans('KSEF_DODATKOWY_OPIS_CREATE_FIELD_SUCCESS'), $label), null, 'mesgs');
        } else {
            setEventMessages($ef_create->error ?: 'Error creating extrafield', null, 'errors');
        }
    } else {
        setEventMessages($err, null, 'errors');
    }

    header("Location: " . $_SERVER["PHP_SELF"]);
    exit;
}

// DodatkowyOpis edit extrafield
if ($action == 'edit_dodatkowy_extrafield') {
    $code = trim(GETPOST('ef_code', 'alphanohtml'));
    $label = trim(GETPOST('ef_label', 'alphanohtml'));
    $type = GETPOST('ef_type', 'alpha');
    $optionsRaw = GETPOST('ef_options', 'restricthtml');
    $efTarget = GETPOST('ef_target', 'alpha');
    if (!in_array($efTarget, array('facture', 'facturedet', 'societe', 'product', 'projet'))) $efTarget = 'facture';

    $validTypes = array('varchar', 'text', 'int', 'double', 'date', 'datetime', 'select');

    $err = '';
    $ef_edit = new ExtraFields($db);
    $ef_edit->fetch_name_optionals_label($efTarget);

    if (!isset($ef_edit->attributes[$efTarget]['label'][$code])) {
        $err = $langs->trans('KSEF_DODATKOWY_OPIS_CREATE_FIELD_ERROR_NOT_FOUND');
    } elseif (strpos($code, 'ksef_') === 0) {
        $err = $langs->trans('KSEF_DODATKOWY_OPIS_CREATE_FIELD_ERROR_RESERVED');
    } elseif ($label === '') {
        $err = $langs->trans('KSEF_DODATKOWY_OPIS_CREATE_FIELD_ERROR_LABEL');
    } elseif (!in_array($type, $validTypes)) {
        $err = $langs->trans('KSEF_DODATKOWY_OPIS_CREATE_FIELD_ERROR_TYPE');
    } else {
        $labelLower = mb_strtolower($label, 'UTF-8');
        foreach (($ef_edit->attributes[$efTarget]['label'] ?? array()) as $existingName => $existingLabel) {
            if ($existingName === $code) continue;
            if (strpos($existingName, 'ksef_') === 0) continue;
            $translated = $langs->trans($existingLabel);
            if (mb_strtolower($translated, 'UTF-8') === $labelLower) {
                $err = $langs->trans('KSEF_DODATKOWY_OPIS_CREATE_FIELD_ERROR_DUP_LABEL');
                break;
            }
        }
    }

    if (!$err) {
        $param = '';
        if ($type === 'select') {
            $options = array();
            $lines = preg_split('/\r\n|\r|\n/', $optionsRaw);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '') continue;
                if (strpos($line, '|') !== false) {
                    list($optCode, $optLabel) = explode('|', $line, 2);
                    $optCode = trim($optCode);
                    $optLabel = trim($optLabel);
                } else {
                    $optLabel = $line;
                    $optCode = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $optLabel));
                    $optCode = trim($optCode, '_');
                }
                if ($optCode !== '' && $optLabel !== '') {
                    $options[$optCode] = $optLabel;
                }
            }
            $param = array('options' => $options);
        }
        $attrs = $ef_edit->attributes[$efTarget];
        $pos = $attrs['pos'][$code] ?? 200;
        $size = ($type === 'varchar') ? (!empty($attrs['size'][$code]) ? $attrs['size'][$code] : '255') : '';
        $required = $attrs['required'][$code] ?? 0;
        $defaultValue = $attrs['default'][$code] ?? '';
        $perms = $attrs['perms'][$code] ?? '';
        $list = $attrs['list'][$code] ?? '1';
        $printable = $attrs['printable'][$code] ?? 0;
        $help = $attrs['help'][$code] ?? '';
        $computed = $attrs['computed'][$code] ?? '';
        $entity = $attrs['entityid'][$code] ?? '';
        $enabled = $attrs['enabled'][$code] ?? '1';
        $totalizable = $attrs['totalizable'][$code] ?? 0;
        $unique = $attrs['unique'][$code] ?? 0;
        $alwayseditable = $attrs['alwayseditable'][$code] ?? 1;
        $langfile = $attrs['langfile'][$code] ?? '';

        $result = $ef_edit->updateExtraField(
            $code,
            $label,
            $type,
            $pos,
            $size,
            $efTarget,
            $unique,
            $required,
            $defaultValue,
            $param,
            $alwayseditable,
            $perms,
            $list,
            $help,
            $computed,
            $entity,
            $langfile,
            $enabled,
            $totalizable,
            $printable
        );

        if ($result > 0) {
            setEventMessages(sprintf($langs->trans('KSEF_DODATKOWY_OPIS_UPDATE_SUCCESS'), $code), null, 'mesgs');
        } else {
            setEventMessages($ef_edit->error ?: 'Error updating extrafield', null, 'errors');
        }
    } else {
        setEventMessages($err, null, 'errors');
    }

    header("Location: " . $_SERVER["PHP_SELF"]);
    exit;
}

// DodatkowyOpis delete extrafield
if ($action == 'delete_dodatkowy_extrafield') {
    $code = trim(GETPOST('ef_code', 'alphanohtml'));
    $efTarget = GETPOST('ef_target', 'alpha');
    if (!in_array($efTarget, array('facture', 'facturedet', 'societe', 'product', 'projet'))) $efTarget = 'facture';

    $err = '';
    if ($code === '' || !preg_match('/^[a-z][a-z0-9_]*$/', $code)) {
        $err = $langs->trans('KSEF_DODATKOWY_OPIS_CREATE_FIELD_ERROR_CODE');
    } elseif (strpos($code, 'ksef_') === 0) {
        $err = $langs->trans('KSEF_DODATKOWY_OPIS_CREATE_FIELD_ERROR_RESERVED');
    } else {
        $ef_del = new ExtraFields($db);
        $ef_del->fetch_name_optionals_label($efTarget);
        if (!isset($ef_del->attributes[$efTarget]['label'][$code])) {
            $err = $langs->trans('KSEF_DODATKOWY_OPIS_CREATE_FIELD_ERROR_NOT_FOUND');
        }
    }

    if (!$err) {
        $result = $ef_del->delete($code, $efTarget);
        if ($result >= 0) {
            $efTargetConfigMap = array(
                'facture' => 'KSEF_DODATKOWY_OPIS_EXTRAFIELDS',
                'facturedet' => 'KSEF_DODATKOWY_OPIS_DET_EXTRAFIELDS',
                'product' => 'KSEF_DODATKOWY_OPIS_PRODUCT_EXTRAFIELDS',
                'societe' => 'KSEF_DODATKOWY_OPIS_SOCIETE_EXTRAFIELDS',
                'projet' => 'KSEF_DODATKOWY_OPIS_PROJECT_EXTRAFIELDS',
            );
            $configKey = isset($efTargetConfigMap[$efTarget]) ? $efTargetConfigMap[$efTarget] : null;
            if ($configKey) {
                $currentEf = getDolGlobalString($configKey, '');
                $currentList = array_filter(array_map('trim', explode(',', $currentEf)));
                $currentList = array_values(array_filter($currentList, function($entry) use ($code) {
                    $parts = explode(':', $entry, 2);
                    return $parts[0] !== $code;
                }));
                dolibarr_set_const($db, $configKey, implode(',', $currentList), 'chaine', 0, '', $conf->entity);
            }
            setEventMessages(sprintf($langs->trans('KSEF_DODATKOWY_OPIS_DELETE_SUCCESS'), $code), null, 'mesgs');
        } else {
            setEventMessages($ef_del->error ?: 'Error deleting extrafield', null, 'errors');
        }
    } else {
        setEventMessages($err, null, 'errors');
    }

    header("Location: " . $_SERVER["PHP_SELF"]);
    exit;
}

// Customer exclusion management
if ($action == 'add_excluded') {
    $socid = GETPOST('socid', 'int');
    $current = getDolGlobalString('KSEF_EXCLUDED_CUSTOMERS', '');
    $excluded = array_filter(array_map('trim', explode(',', $current)));
    if (!in_array($socid, $excluded)) {
        $excluded[] = $socid;
        dolibarr_set_const($db, "KSEF_EXCLUDED_CUSTOMERS", implode(',', $excluded), 'chaine', 0, '', $conf->entity);
        setEventMessages($langs->trans("KSEF_CustomerExcludedFromKSEF"), null, 'mesgs');
    }
    header("Location: " . $_SERVER["PHP_SELF"]);
    exit;
}

if ($action == 'remove_excluded') {
    $socid = GETPOST('socid', 'int');
    $current = getDolGlobalString('KSEF_EXCLUDED_CUSTOMERS', '');
    $excluded = array_filter(array_map('trim', explode(',', $current)));
    $excluded = array_diff($excluded, array($socid));
    dolibarr_set_const($db, "KSEF_EXCLUDED_CUSTOMERS", implode(',', $excluded), 'chaine', 0, '', $conf->entity);
    setEventMessages($langs->trans("KSEF_CustomerRemovedFromKSEFExclusions"), null, 'mesgs');
    header("Location: " . $_SERVER["PHP_SELF"]);
    exit;
}


$form = new Form($db);
$page_name = "KSEF_Setup";

llxHeader('', $langs->trans($page_name), '');

$linkback = '<a href="' . DOL_URL_ROOT . '/admin/modules.php?restore_lastsearch_values=1">' . $langs->trans("KSEF_BackToModuleList") . '</a>';
print load_fiche_titre($langs->trans($page_name), $linkback, 'title_setup');

$head = ksefAdminPrepareHead();
print dol_get_fiche_head($head, 'outgoing', $langs->trans("KSEF_Module"), -1, 'ksef@ksef');

// Reactivation + update banners
echo ksefShowAdminBanners();

// Config warnings
$warnings = ksefGetConfigWarnings();
if (!empty($warnings)) {
    echo ksefRenderConfigWarnings($warnings, 'outgoing');
}

// Shared lookups for entity sections
$ef_entity = new ExtraFields($db);
$ef_entity->fetch_name_optionals_label('societe');
$ef_entity->fetch_name_optionals_label('facture');
$ef_entity->fetch_name_optionals_label('product');
$ef_entity->fetch_name_optionals_label('facturedet');
$ef_entity->fetch_name_optionals_label('projet');

$_dodUnsupportedTypes = ksefDodatkowyOpisUnsupportedTypes();

print '<form method="POST" action="' . $_SERVER["PHP_SELF"] . '">';
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" name="action" value="update">';

// Submission & PDF
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="2">' . $langs->trans("KSEF_SUBMISSION_PDF_SECTION") . '</td></tr>';

print '<tr class="oddeven">';
print '<td class="titlefield">' . $form->textwithpicto($langs->trans('KSEF_ADD_QR'), $langs->trans('KSEF_ADD_QR_Help')) . '</td>';
print '<td>';
print '<input type="checkbox" name="KSEF_ADD_QR" id="KSEF_ADD_QR" value="1" ' . (getDolGlobalInt('KSEF_ADD_QR') ? 'checked' : '') . '>';
print ' <label for="KSEF_ADD_QR">' . $langs->trans("KSEF_Enabled") . '</label>';
print '</td></tr>';

print '<tr class="oddeven">';
print '<td class="titlefield">' . $form->textwithpicto($langs->trans('KSEF_DISABLE_VALIDATE_AND_UPLOAD'), $langs->trans('KSEF_DISABLE_VALIDATE_AND_UPLOAD_Help')) . '</td>';
print '<td>';
print '<input type="checkbox" name="KSEF_DISABLE_VALIDATE_AND_UPLOAD" id="KSEF_DISABLE_VALIDATE_AND_UPLOAD" value="1" ' . (getDolGlobalInt('KSEF_DISABLE_VALIDATE_AND_UPLOAD') ? 'checked' : '') . '>';
print ' <label for="KSEF_DISABLE_VALIDATE_AND_UPLOAD">' . $langs->trans("KSEF_Enabled") . '</label>';
print '</td></tr>';

print '<tr class="oddeven">';
print '<td class="titlefield">' . $form->textwithpicto($langs->trans('KSEF_CONFIRM_BEFORE_UPLOAD'), $langs->trans('KSEF_CONFIRM_BEFORE_UPLOAD_Help')) . '</td>';
print '<td>';
print '<input type="checkbox" name="KSEF_CONFIRM_BEFORE_UPLOAD" id="KSEF_CONFIRM_BEFORE_UPLOAD" value="1" ' . (getDolGlobalInt('KSEF_CONFIRM_BEFORE_UPLOAD', 1) ? 'checked' : '') . '>';
print ' <label for="KSEF_CONFIRM_BEFORE_UPLOAD">' . $langs->trans("KSEF_Enabled") . '</label>';
print '</td></tr>';

print '<tr class="oddeven">';
print '<td class="titlefield">' . $form->textwithpicto($langs->trans('KSEF_SHOW_PDF_AFTER_UPLOAD'), $langs->trans('KSEF_SHOW_PDF_AFTER_UPLOAD_Help')) . '</td>';
print '<td>';
print '<input type="checkbox" name="KSEF_SHOW_PDF_AFTER_UPLOAD" id="KSEF_SHOW_PDF_AFTER_UPLOAD" value="1" ' . (getDolGlobalInt('KSEF_SHOW_PDF_AFTER_UPLOAD', 1) ? 'checked' : '') . '>';
print ' <label for="KSEF_SHOW_PDF_AFTER_UPLOAD">' . $langs->trans("KSEF_Enabled") . '</label>';
print '</td></tr>';

// Confirmation PDF template dropdown
print '<tr class="oddeven">';
print '<td class="titlefield">' . $form->textwithpicto($langs->trans('KSEF_CONFIRM_PDF_TEMPLATE'), $langs->trans('KSEF_CONFIRM_PDF_TEMPLATE_Help')) . '</td>';
print '<td>';
$current_template = getDolGlobalString('KSEF_CONFIRM_PDF_TEMPLATE', 'ksef');
print '<select name="KSEF_CONFIRM_PDF_TEMPLATE" id="KSEF_CONFIRM_PDF_TEMPLATE">';
print '<option value="ksef"' . ($current_template == 'ksef' ? ' selected' : '') . '>' . $langs->trans('KSEF_CONFIRM_PDF_TEMPLATE_KSEF') . '</option>';
require_once DOL_DOCUMENT_ROOT . '/core/modules/facture/modules_facture.php';
$dolibarr_models = ModelePDFFactures::liste_modeles($db);
if (is_array($dolibarr_models)) {
    foreach ($dolibarr_models as $key => $label) {
        print '<option value="' . dol_escape_htmltag($key) . '"' . ($current_template == $key ? ' selected' : '') . '>' . dol_escape_htmltag(ucfirst($key)) . '</option>';
    }
}
print '</select>';
print '</td></tr>';

print '</table>';

// VAT Exemption
print '<br><table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="2">' . $langs->trans("KSEF_VAT_EXEMPTION_SECTION") . '</td></tr>';

print '<tr class="oddeven">';
print '<td class="titlefield">' . $form->textwithpicto($langs->trans('KSEF_ZWOLNIENIE_PODSTAWA'), $langs->trans('KSEF_ZWOLNIENIE_PODSTAWA_Help')) . '</td>';
print '<td>';
$zwolnienie_types = array(
    'disabled' => $langs->trans('KSEF_ZWOLNIENIE_TYPE_DISABLED'),
    'P_19A' => $langs->trans('KSEF_ZWOLNIENIE_TYPE_P19A'),
    'P_19B' => $langs->trans('KSEF_ZWOLNIENIE_TYPE_P19B'),
    'P_19C' => $langs->trans('KSEF_ZWOLNIENIE_TYPE_P19C'),
);
$current_zwolnienie_type = getDolGlobalString('KSEF_ZWOLNIENIE_TYPE', 'disabled');
print $form->selectarray('KSEF_ZWOLNIENIE_TYPE', $zwolnienie_types, $current_zwolnienie_type, 0, 0, 0, 'onchange="ksefToggleZwolnienie()"', 0, 0, 0, '', 'minwidth200');
print '<br>';
$zwolnienie_hidden = ($current_zwolnienie_type === 'disabled') ? ' style="display:none;"' : '';
print '<span id="ksef_zwolnienie_text_row"' . $zwolnienie_hidden . '>';
print '<input type="text" name="KSEF_ZWOLNIENIE_PODSTAWA" class="flat minwidth400" maxlength="256" value="' . dol_escape_htmltag(getDolGlobalString('KSEF_ZWOLNIENIE_PODSTAWA', '')) . '" placeholder="' . dol_escape_htmltag($langs->trans('KSEF_ZWOLNIENIE_PODSTAWA_Placeholder')) . '">';
print '</span>';
print '<script>function ksefToggleZwolnienie(){var s=document.getElementById("KSEF_ZWOLNIENIE_TYPE");var d=(s&&s.value==="disabled");document.getElementById("ksef_zwolnienie_text_row").style.display=d?"none":"";}</script>';
print '</td></tr>';

print '</table>';

// Note Settings
print '<br><table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="2">' . $langs->trans("KSEF_NOTE_SETTINGS_SECTION") . '</td></tr>';

print '<tr class="oddeven">';
print '<td class="titlefield">' . $form->textwithpicto($langs->trans('KSEF_STOPKA_BOILERPLATE'), $langs->trans('KSEF_STOPKA_BOILERPLATE_Help')) . '</td>';
print '<td>';
print '<textarea name="KSEF_STOPKA_BOILERPLATE" class="flat minwidth300" rows="4" maxlength="3500">';
print dol_escape_htmltag(getDolGlobalString('KSEF_STOPKA_BOILERPLATE', ''));
print '</textarea>';
print '<br><span class="small">' . $langs->trans('KSEF_STOPKA_BOILERPLATE_Limit') . '</span>';
print '</td></tr>';

print '<tr class="oddeven">';
print '<td class="titlefield">' . $form->textwithpicto($langs->trans('KSEF_DODATKOWY_OPIS_NOTE_MODE'), $langs->trans('KSEF_DODATKOWY_OPIS_NOTE_MODE_Help')) . '</td>';
print '<td>';
$combined_note_modes = array(
    'simple_stopka' => $langs->trans('KSEF_NOTE_COMBINED_SIMPLE_STOPKA'),
    'simple_dodatkowy' => $langs->trans('KSEF_NOTE_COMBINED_SIMPLE_DODATKOWY'),
    'keyvalue_dodatkowy' => $langs->trans('KSEF_NOTE_COMBINED_KEYVALUE_DODATKOWY'),
    'disabled' => $langs->trans('KSEF_DODATKOWY_OPIS_NOTE_MODE_DISABLED'),
);
$_cm = getDolGlobalString('KSEF_DODATKOWY_OPIS_NOTE_MODE', 'simple');
$_ct = getDolGlobalString('KSEF_NOTE_PUBLIC_TARGET', 'stopka_faktury');
if ($_cm === 'disabled') {
    $current_combined_note = 'disabled';
} elseif ($_cm === 'keyvalue') {
    $current_combined_note = 'keyvalue_dodatkowy';
} elseif ($_ct === 'dodatkowy_opis') {
    $current_combined_note = 'simple_dodatkowy';
} else {
    $current_combined_note = 'simple_stopka';
}
print $form->selectarray('KSEF_NOTE_COMBINED_MODE', $combined_note_modes, $current_combined_note, 0, 0, 0, '', 0, 0, 0, '', 'minwidth300');
print '</td></tr>';

print '</table>';

// inline create/edit form
function ksefPrintInlineCreateForm($entity, $langs) {
	$token = newToken();
	print '<tr class="oddeven"><td colspan="5">';
	print '<details id="ksef_ef_create_details_' . $entity . '" style="padding:4px;">';
	print '<summary style="cursor:pointer; font-weight:bold;">' . $langs->trans('KSEF_ENTITY_BOX_CREATE_FIELD') . '</summary>';
	print '<p class="opacitymedium" style="margin-top:8px;">' . $langs->trans('KSEF_EXTRAFIELD_CREATE_EXPLANATION') . '</p>';
	print '<div id="ksef_ef_form_area_' . $entity . '" data-mode="create" style="margin-top:8px;">';
	print '<input type="hidden" form="ksef_ef_create_form_' . $entity . '" name="token" value="' . $token . '">';
	print '<input type="hidden" form="ksef_ef_edit_form_' . $entity . '" name="token" value="' . $token . '">';
	print '<input type="hidden" form="ksef_ef_create_form_' . $entity . '" name="ef_target" value="' . $entity . '">';
	print '<input type="hidden" form="ksef_ef_edit_form_' . $entity . '" name="ef_target" value="' . $entity . '">';
	print '<table class="noborder" style="width:100%;">';
	// Label
	print '<tr><td style="width:160px;"><label for="ksef_ef_label_' . $entity . '">' . $langs->trans('KSEF_DODATKOWY_OPIS_CREATE_FIELD_LABEL') . '</label></td>';
	print '<td><input type="text" id="ksef_ef_label_' . $entity . '" form="ksef_ef_create_form_' . $entity . '" name="ef_label" class="flat minwidth300"></td></tr>';
	// Code (edit mode only)
	print '<tr id="ksef_ef_code_row_' . $entity . '" style="display:none;"><td>' . $langs->trans('KSEF_DODATKOWY_OPIS_INTERNAL_CODE') . '</td>';
	print '<td><code id="ksef_ef_code_display_' . $entity . '"></code><input type="hidden" id="ksef_ef_code_' . $entity . '" form="ksef_ef_edit_form_' . $entity . '" name="ef_code" value=""></td></tr>';
	// Type
	print '<tr><td><label for="ksef_ef_type_' . $entity . '">' . $langs->trans('KSEF_DODATKOWY_OPIS_CREATE_FIELD_TYPE') . '</label></td>';
	print '<td><select id="ksef_ef_type_' . $entity . '" form="ksef_ef_create_form_' . $entity . '" name="ef_type" onchange="ksefToggleOptions(\'' . $entity . '\')">';
	print '<option value="varchar">' . $langs->trans('KSEF_DODATKOWY_OPIS_TYPE_VARCHAR') . '</option>';
	print '<option value="text">' . $langs->trans('KSEF_DODATKOWY_OPIS_TYPE_TEXT') . '</option>';
	print '<option value="int">' . $langs->trans('KSEF_DODATKOWY_OPIS_TYPE_INT') . '</option>';
	print '<option value="double">' . $langs->trans('KSEF_DODATKOWY_OPIS_TYPE_DOUBLE') . '</option>';
	print '<option value="date">' . $langs->trans('KSEF_DODATKOWY_OPIS_TYPE_DATE') . '</option>';
	print '<option value="datetime">' . $langs->trans('KSEF_DODATKOWY_OPIS_TYPE_DATETIME') . '</option>';
	print '<option value="select">' . $langs->trans('KSEF_DODATKOWY_OPIS_TYPE_SELECT') . '</option>';
	print '</select></td></tr>';
	// Options (for select type)
	print '<tr id="ksef_ef_options_row_' . $entity . '" style="display:none;"><td><label for="ksef_ef_options_' . $entity . '">' . $langs->trans('KSEF_DODATKOWY_OPIS_CREATE_FIELD_OPTIONS') . '</label></td>';
	print '<td><textarea id="ksef_ef_options_' . $entity . '" form="ksef_ef_create_form_' . $entity . '" name="ef_options" class="flat" rows="4" style="width:100%;"></textarea></td></tr>';
	// Buttons
	print '<tr><td></td><td>';
	print '<button type="submit" id="ksef_ef_create_btn_' . $entity . '" form="ksef_ef_create_form_' . $entity . '" class="button">' . $langs->trans('KSEF_DODATKOWY_OPIS_CREATE_FIELD_SUBMIT') . '</button> ';
	print '<button type="submit" id="ksef_ef_edit_btn_' . $entity . '" form="ksef_ef_edit_form_' . $entity . '" class="button" style="display:none;">' . $langs->trans('KSEF_DODATKOWY_OPIS_EDIT_FIELD_SUBMIT') . '</button> ';
	print '<button type="button" id="ksef_ef_cancel_btn_' . $entity . '" class="button" style="display:none;" onclick="ksefCancelEdit(\'' . $entity . '\')">' . $langs->trans('Cancel') . '</button>';
	print '</td></tr>';
	print '</table>';
	print '</div>';
	print '</details>';
	print '</td></tr>';
}

// Render one flag source row
function ksefRenderFlagSourceRow($fr, $form, $langs, $ef_entity) {
	print '<tr class="oddeven">';
	print '<td class="titlefield">' . $form->textwithpicto($langs->trans($fr['const']), $langs->trans($fr['const'] . '_Help')) . '</td>';
	print '<td colspan="4">';
	$flag_options = array('disabled' => $langs->trans($fr['const'] . '_DISABLED'));
	if ($fr['always_on']) {
		$flag_options['always_on'] = $langs->trans($fr['const'] . '_ALWAYS_ON');
	}
	if ($fr['fkey'] !== '' && !empty($ef_entity->attributes['facture']['label'])) {
		foreach ($ef_entity->attributes['facture']['label'] as $fname => $flabel) {
			if (strpos($fname, 'ksef_') === 0) continue;
			$ftype = $ef_entity->attributes['facture']['type'][$fname] ?? '';
			if (in_array($ftype, array('boolean', 'select', 'varchar', 'int'))) {
				$flag_options['extrafield:' . $fname] = $langs->trans($fr['const'] . '_EXTRAFIELD') . ': ' . $langs->trans($flabel);
			}
		}
	}
	if ($fr['skey'] !== '' && !empty($ef_entity->attributes['societe']['label'])) {
		foreach ($ef_entity->attributes['societe']['label'] as $fname => $flabel) {
			if (strpos($fname, 'ksef_') === 0) continue;
			$ftype = $ef_entity->attributes['societe']['type'][$fname] ?? '';
			if (in_array($ftype, array('boolean', 'select', 'varchar', 'int'))) {
				$flag_options['thirdparty_extrafield:' . $fname] = $langs->trans($fr['const'] . '_THIRDPARTY_EXTRAFIELD') . ': ' . $langs->trans($flabel);
			}
		}
	}
	$flag_current = getDolGlobalString($fr['const'], 'disabled');
	$flag_sync = array();
	if ($fr['fkey'] !== '') {
		$flag_sync[] = 'ksefSyncFromDropdown(\'facture\', \'' . $fr['const'] . '\', \'' . $fr['fkey'] . '\', \'extrafield:\')';
	}
	if ($fr['skey'] !== '') {
		$flag_sync[] = 'ksefSyncFromDropdown(\'societe\', \'' . $fr['const'] . '\', \'' . $fr['skey'] . '\', \'thirdparty_extrafield:\')';
	}
	$flag_onchange = $flag_sync ? 'onchange="' . implode('; ', $flag_sync) . '"' : '';
	print $form->selectarray($fr['const'], $flag_options, $flag_current, 0, 0, 0, $flag_onchange, 0, 0, 0, '', 'minwidth300');
	print '</td></tr>';
}

// Invoice Fields
print '<br><table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="5">' . $langs->trans('KSEF_DODATKOWY_OPIS_FACTURE_TITLE') . '</td></tr>';

// Invoice settings
print '<tr class="oddeven">';
print '<td class="titlefield">' . $form->textwithpicto($langs->trans('KSEF_FA3_INCLUDE_BANK_DESC'), $langs->trans('KSEF_FA3_INCLUDE_BANK_DESC_Help')) . '</td>';
print '<td colspan="4">';
print '<input type="checkbox" name="KSEF_FA3_INCLUDE_BANK_DESC" id="KSEF_FA3_INCLUDE_BANK_DESC" value="1" ' . (getDolGlobalInt('KSEF_FA3_INCLUDE_BANK_DESC') ? 'checked' : '') . '>';
print ' <label for="KSEF_FA3_INCLUDE_BANK_DESC">' . $langs->trans("KSEF_Enabled") . '</label>';
print '</td></tr>';

// Buyer contact in DaneKontaktowe
print '<tr class="oddeven">';
print '<td class="titlefield">' . $form->textwithpicto($langs->trans('KSEF_FA3_INCLUDE_CONTACT'), $langs->trans('KSEF_FA3_INCLUDE_CONTACT_Help')) . '</td>';
print '<td colspan="4">';
print '<input type="checkbox" name="KSEF_FA3_INCLUDE_CONTACT" id="KSEF_FA3_INCLUDE_CONTACT" value="1" ' . (getDolGlobalString('KSEF_FA3_INCLUDE_CONTACT', '1') !== '0' ? 'checked' : '') . '>';
print ' <label for="KSEF_FA3_INCLUDE_CONTACT">' . $langs->trans("KSEF_Enabled") . '</label>';
print '</td></tr>';

print '<tr class="oddeven">';
print '<td class="titlefield">' . $form->textwithpicto($langs->trans('KSEF_FA3_PLACE_OF_ISSUE'), $langs->trans('KSEF_FA3_PLACE_OF_ISSUE_Help')) . '</td>';
print '<td colspan="4">';
$place_modes = array(
    'disabled' => $langs->trans('KSEF_FA3_PLACE_OF_ISSUE_DISABLED'),
    'company' => $langs->trans('KSEF_FA3_PLACE_OF_ISSUE_COMPANY'),
    'custom' => $langs->trans('KSEF_FA3_PLACE_OF_ISSUE_CUSTOM'),
);
$current_place_mode = getDolGlobalString('KSEF_FA3_PLACE_OF_ISSUE_MODE', 'disabled');
print $form->selectarray('KSEF_FA3_PLACE_OF_ISSUE_MODE', $place_modes, $current_place_mode, 0, 0, 0, 'onchange="togglePlaceOfIssueCustom()"', 0, 0, 0, '', 'minwidth200');
print '<span id="place_of_issue_custom_wrapper" style="margin-left: 10px; ' . ($current_place_mode != 'custom' ? 'display:none;' : '') . '">';
print '<input type="text" name="KSEF_FA3_PLACE_OF_ISSUE_CUSTOM" class="flat minwidth200" value="' . dol_escape_htmltag(getDolGlobalString('KSEF_FA3_PLACE_OF_ISSUE_CUSTOM', '')) . '" placeholder="' . $langs->trans("KSEF_FA3_PLACE_OF_ISSUE_CUSTOM_Placeholder") . '">';
print '</span>';
print '</td></tr>';
print '<script>
function togglePlaceOfIssueCustom() {
    var mode = document.querySelector(\'select[name="KSEF_FA3_PLACE_OF_ISSUE_MODE"]\').value;
    var wrapper = document.getElementById("place_of_issue_custom_wrapper");
    wrapper.style.display = (mode == "custom") ? "inline" : "none";
}
</script>';

print '<tr class="oddeven">';
print '<td class="titlefield">' . $form->textwithpicto($langs->trans('KSEF_FA3_SALE_DATE_SOURCE'), $langs->trans('KSEF_FA3_SALE_DATE_SOURCE_Help')) . '</td>';
print '<td colspan="4">';
$sale_date_modes = array(
    'invoice_date' => $langs->trans('KSEF_FA3_SALE_DATE_SOURCE_INVOICE'),
    'delivery_date' => $langs->trans('KSEF_FA3_SALE_DATE_SOURCE_DELIVERY'),
);
$current_sale_date_source = getDolGlobalString('KSEF_FA3_SALE_DATE_SOURCE', 'delivery_date');
print $form->selectarray('KSEF_FA3_SALE_DATE_SOURCE', $sale_date_modes, $current_sale_date_source, 0, 0, 0, '', 0, 0, 0, '', 'minwidth300');
print '</td></tr>';

// Podmiot3
print '<tr class="oddeven">';
print '<td class="titlefield">' . $form->textwithpicto($langs->trans('KSEF_PODMIOT3_SOURCE'), $langs->trans('KSEF_PODMIOT3_SOURCE_Help')) . '</td>';
print '<td colspan="4">';
print '<input type="checkbox" name="KSEF_PODMIOT3_SOURCE" id="KSEF_PODMIOT3_SOURCE" value="1" ' . (getDolGlobalString('KSEF_PODMIOT3_SOURCE', 'disabled') === 'enabled' ? 'checked' : '') . '>';
print ' <label for="KSEF_PODMIOT3_SOURCE">' . $langs->trans("KSEF_Enabled") . '</label>';
print '</td></tr>';

print '<tr class="oddeven">';
print '<td class="titlefield">' . $form->textwithpicto($langs->trans('KSEF_PODMIOT3_ROLE'), $langs->trans('KSEF_PODMIOT3_ROLE_Help')) . '</td>';
print '<td colspan="4">';
$p3_role_options = array();
foreach (array('6', '2', '3', '4', '5', '7', '8', '9', '10', '11', '1') as $r) {
    $p3_role_options[$r] = $r . ' - ' . $langs->trans('KSEF_Podmiot3_Role' . $r);
}
$current_p3_role = getDolGlobalString('KSEF_PODMIOT3_ROLE', '6');
print $form->selectarray('KSEF_PODMIOT3_ROLE', $p3_role_options, $current_p3_role, 0, 0, 0, '', 0, 0, 0, '', 'minwidth300');
print '</td></tr>';

print '<tr class="oddeven">';
print '<td class="titlefield">' . $form->textwithpicto($langs->trans('KSEF_NR_ZAMOWIENIA_SOURCE'), $langs->trans('KSEF_NR_ZAMOWIENIA_SOURCE_Help')) . '</td>';
print '<td colspan="4">';
$zamowienia_options = array(
    'ref_client' => $langs->trans('KSEF_NR_ZAMOWIENIA_SOURCE_REF_CLIENT'),
    'linked_order' => $langs->trans('KSEF_NR_ZAMOWIENIA_SOURCE_LINKED_ORDER'),
    'disabled' => $langs->trans('KSEF_NR_ZAMOWIENIA_SOURCE_DISABLED'),
);
if (!empty($ef_entity->attributes['facture']['label'])) {
    foreach ($ef_entity->attributes['facture']['label'] as $fname => $flabel) {
        if (strpos($fname, 'ksef_') === 0) continue;
        $ftype = $ef_entity->attributes['facture']['type'][$fname] ?? '';
        if (in_array($ftype, array('varchar', 'text', 'int'))) {
            $zamowienia_options['extrafield:' . $fname] = $langs->trans('KSEF_NR_ZAMOWIENIA_SOURCE_EXTRAFIELD') . ': ' . $langs->trans($flabel);
        }
    }
}
$current_zamowienia = getDolGlobalString('KSEF_NR_ZAMOWIENIA_SOURCE', 'ref_client');
print $form->selectarray('KSEF_NR_ZAMOWIENIA_SOURCE', $zamowienia_options, $current_zamowienia, 0, 0, 0, 'onchange="ksefSyncFromDropdown(\'facture\', \'KSEF_NR_ZAMOWIENIA_SOURCE\', \'nr_zamowienia\', \'extrafield:\')"', 0, 0, 0, '', 'minwidth300');
print '</td></tr>';

// Adnotacje flag source
$ksefInvoiceFlagRows = array(
    array('const' => 'KSEF_FA3_MPP_SOURCE', 'always_on' => false, 'fkey' => 'mpp', 'skey' => ''),
    array('const' => 'KSEF_FA3_FP_SOURCE',  'always_on' => false, 'fkey' => 'fp',  'skey' => ''),
    array('const' => 'KSEF_P16_SOURCE',     'always_on' => true,  'fkey' => '',    'skey' => ''),
    array('const' => 'KSEF_P17_SOURCE',     'always_on' => false, 'fkey' => 'p17', 'skey' => ''),
    array('const' => 'KSEF_P18_SOURCE',     'always_on' => true,  'fkey' => 'p18', 'skey' => ''),
);
foreach ($ksefInvoiceFlagRows as $fr) {
    ksefRenderFlagSourceRow($fr, $form, $langs, $ef_entity);
}

// Corrections
print '<tr class="liste_titre"><td colspan="5">' . $langs->trans("KSEF_CorrectionInvoicesSection") . '</td></tr>';

print '<tr class="oddeven">';
print '<td colspan="5">';
print '<span class="opacitymedium">' . $langs->trans("KSEF_CorrectionInvoicesDesc") . '</span>';
print '</td></tr>';

// Default correction reason - dropdown with presets + "Other" custom input
$corrReasonPresetsSetup = array(
    'Zwrot towaru'          => 'KSEF_CorrectionReasonPreset_return',
    'Błędna ilość'          => 'KSEF_CorrectionReasonPreset_qty',
    'Błędna cena'           => 'KSEF_CorrectionReasonPreset_price',
    'Błędna stawka VAT'     => 'KSEF_CorrectionReasonPreset_vat',
    'Rabat potransakcyjny'  => 'KSEF_CorrectionReasonPreset_discount',
    'Skonto'                => 'KSEF_CorrectionReasonPreset_skonto',
    'Zwrot zaliczki'        => 'KSEF_CorrectionReasonPreset_advance',
    'Błędne dane nabywcy'   => 'KSEF_CorrectionReasonPreset_buyer',
);
$currentDefaultReason = getDolGlobalString('KSEF_DEFAULT_CORRECTION_REASON', '');
$defaultIsPreset = !empty($currentDefaultReason) && array_key_exists($currentDefaultReason, $corrReasonPresetsSetup);
$defaultIsCustom = !empty($currentDefaultReason) && !$defaultIsPreset;

print '<tr class="oddeven">';
print '<td class="titlefield">' . $form->textwithpicto($langs->trans("KSEF_DefaultCorrectionReason"), $langs->trans("KSEF_DefaultCorrectionReasonHelp")) . '</td>';
print '<td colspan="4">';
print '<select name="KSEF_DEFAULT_CORRECTION_REASON_PRESET" id="KSEF_DEFAULT_CORRECTION_REASON_PRESET" style="min-width: 220px;">';
print '<option value=""></option>';
foreach ($corrReasonPresetsSetup as $plText => $langKey) {
    $selected = ($defaultIsPreset && $currentDefaultReason === $plText) ? ' selected' : '';
    print '<option value="' . dol_escape_htmltag($plText) . '"' . $selected . '>' . $langs->trans($langKey) . '</option>';
}
$otherSelected = $defaultIsCustom ? ' selected' : '';
print '<option value="custom"' . $otherSelected . '>' . $langs->trans("KSEF_CorrectionReasonPreset_other") . '</option>';
print '</select>';
$customDisplay = $defaultIsCustom ? 'inline-block' : 'none';
$customValue = $defaultIsCustom ? dol_escape_htmltag($currentDefaultReason) : '';
print ' <input type="text" name="KSEF_DEFAULT_CORRECTION_REASON_CUSTOM" id="KSEF_DEFAULT_CORRECTION_REASON_CUSTOM"';
print ' value="' . $customValue . '" maxlength="256" style="display: ' . $customDisplay . '; width: 300px;"';
print ' placeholder="' . dol_escape_htmltag($langs->trans("KSEF_CorrectionReasonCustomPlaceholder")) . '">';
print '</td></tr>';

// Default correction type
$currentDefaultType = getDolGlobalString('KSEF_DEFAULT_CORRECTION_TYPE', '');
print '<tr class="oddeven">';
print '<td class="titlefield">' . $form->textwithpicto($langs->trans("KSEF_DefaultCorrectionType"), $langs->trans("KSEF_DefaultCorrectionTypeHelp")) . '</td>';
print '<td colspan="4">';
print '<select name="KSEF_DEFAULT_CORRECTION_TYPE" style="min-width: 200px;">';
print '<option value=""></option>';
for ($t = 1; $t <= 3; $t++) {
    $tSelected = ($currentDefaultType === (string) $t) ? ' selected' : '';
    print '<option value="' . $t . '"' . $tSelected . '>' . $langs->trans("KSEF_CorrectionType" . $t) . '</option>';
}
print '</select>';
print '</td></tr>';

print '<tr class="oddeven">';
print '<td class="titlefield">' . $form->textwithpicto($langs->trans('KSEF_KOR_LINE_METHOD'), $langs->trans('KSEF_KOR_LINE_METHOD_Help')) . '</td>';
print '<td colspan="4">';
$kor_method_options = array(
    'differential' => $langs->trans('KSEF_KOR_LINE_METHOD_DIFFERENTIAL'),
    'stanprzed' => $langs->trans('KSEF_KOR_LINE_METHOD_STANPRZED'),
);
$current_kor_method = getDolGlobalString('KSEF_KOR_LINE_METHOD', 'stanprzed');
print $form->selectarray('KSEF_KOR_LINE_METHOD', $kor_method_options, $current_kor_method, 0, 0, 0, '', 0, 0, 0, '', 'minwidth300');
print '</td></tr>';

// Payment Defaults
print '<tr class="liste_titre"><td colspan="5">' . $langs->trans("KSEF_DEFAULT_PAYMENT_CONFIG") . '</td></tr>';

print '<tr class="oddeven">';
print '<td class="titlefield">' . $form->textwithpicto($langs->trans('KSEF_DEFAULT_PAYMENT_TERM'), $langs->trans('KSEF_DEFAULT_PAYMENT_TERM_Help')) . '</td>';
print '<td colspan="4">';
print $form->getSelectConditionsPaiements(getDolGlobalInt('KSEF_DEFAULT_PAYMENT_TERM_ID', 0), 'KSEF_DEFAULT_PAYMENT_TERM_ID', -1, 1, 0, 'minwidth300');
print '</td></tr>';

print '<tr class="oddeven">';
print '<td class="titlefield">' . $form->textwithpicto($langs->trans('KSEF_DEFAULT_PAYMENT_METHOD'), $langs->trans('KSEF_DEFAULT_PAYMENT_METHOD_Help')) . '</td>';
print '<td colspan="4">';
print $form->select_types_paiements(getDolGlobalString('KSEF_DEFAULT_PAYMENT_METHOD_ID', ''), 'KSEF_DEFAULT_PAYMENT_METHOD_ID', '', 0, 1, 0, 0, 1, 'minwidth300', 1);
print '</td></tr>';

print '<tr class="oddeven">';
print '<td class="titlefield">' . $form->textwithpicto($langs->trans('KSEF_DEFAULT_BANK_ACCOUNT'), $langs->trans('KSEF_DEFAULT_BANK_ACCOUNT_Help')) . '</td>';
print '<td colspan="4">';
print $form->select_comptes(getDolGlobalString('KSEF_DEFAULT_BANK_ACCOUNT_ID', ''), 'KSEF_DEFAULT_BANK_ACCOUNT_ID', 0, '', 1, '', 0, 'minwidth300', 1);
print '</td></tr>';

// Extrafields
print '<tr class="liste_titre"><td colspan="5">' . $langs->trans('KSEF_ENTITY_BOX_EXTRAFIELDS_INVOICE') . '</td></tr>';

print '<tr class="oddeven"><td colspan="5"><span class="small">' . $langs->trans('KSEF_DODATKOWY_OPIS_FACTURE_SUBTITLE') . '</span></td></tr>';

// Determine current assignment
$current_ef_config = getDolGlobalString('KSEF_DODATKOWY_OPIS_EXTRAFIELDS', '');
$enabled_fields = ksefParseEfConfig($current_ef_config);
$_nrZamSrc = getDolGlobalString('KSEF_NR_ZAMOWIENIA_SOURCE', 'ref_client');
$_nrUmowySrc = getDolGlobalString('KSEF_NR_UMOWY_SOURCE', 'disabled');
$_tpSrc = getDolGlobalString('KSEF_TP_SOURCE', 'disabled');
$_mppSrc = getDolGlobalString('KSEF_FA3_MPP_SOURCE', 'disabled');
$_fpSrc = getDolGlobalString('KSEF_FA3_FP_SOURCE', 'disabled');
$_p17Src = getDolGlobalString('KSEF_P17_SOURCE', 'disabled');
$_p18Src = getDolGlobalString('KSEF_P18_SOURCE', 'disabled');
$facture_field_assigns = array(); // fname => assignment value
if (strpos($_nrZamSrc, 'extrafield:') === 0) $facture_field_assigns[substr($_nrZamSrc, 11)] = 'nr_zamowienia';
if (strpos($_nrUmowySrc, 'extrafield:') === 0) $facture_field_assigns[substr($_nrUmowySrc, 11)] = 'nr_umowy';
if (strpos($_tpSrc, 'extrafield:') === 0) $facture_field_assigns[substr($_tpSrc, 11)] = 'tp';
if (strpos($_mppSrc, 'extrafield:') === 0) $facture_field_assigns[substr($_mppSrc, 11)] = 'mpp';
if (strpos($_fpSrc, 'extrafield:') === 0) $facture_field_assigns[substr($_fpSrc, 11)] = 'fp';
if (strpos($_p17Src, 'extrafield:') === 0) $facture_field_assigns[substr($_p17Src, 11)] = 'p17';
if (strpos($_p18Src, 'extrafield:') === 0) $facture_field_assigns[substr($_p18Src, 11)] = 'p18';

$has_facture_fields = false;

print '<tr class="liste_titre">';
print '<td>' . $langs->trans('KSEF_DODATKOWY_OPIS_CREATE_FIELD_LABEL') . '</td>';
print '<td>' . $langs->trans('KSEF_DODATKOWY_OPIS_CREATE_FIELD_CODE') . '</td>';
print '<td>' . $langs->trans('KSEF_DODATKOWY_OPIS_CREATE_FIELD_TYPE') . '</td>';
print '<td>' . $langs->trans('KSEF_ASSIGN_COLUMN') . '</td>';
print '<td style="text-align:right; width:120px;"></td>';
print '</tr>';

if (!empty($ef_entity->attributes['facture']['label'])) {
    foreach ($ef_entity->attributes['facture']['label'] as $fname => $flabel) {
        if (strpos($fname, 'ksef_') === 0) continue;
        $ftype = $ef_entity->attributes['facture']['type'][$fname] ?? '';
        if (in_array($ftype, $_dodUnsupportedTypes)) continue;
        $has_facture_fields = true;
        $translatedLabel = $langs->trans($flabel);

        // Determine current assignment
        $currentAssign = '';
        if (isset($facture_field_assigns[$fname])) {
            $currentAssign = $facture_field_assigns[$fname];
        } elseif (isset($enabled_fields[$fname])) {
            $currentAssign = $enabled_fields[$fname]; // 'dodatkowy' or 'stopka'
        }

        // Build assignment options based on field type
        $assignOptions = array('' => $langs->trans('KSEF_ASSIGN_NONE'));
        $assignOptions['dodatkowy'] = $langs->trans('KSEF_ASSIGN_DODATKOWY');
        $assignOptions['stopka'] = $langs->trans('KSEF_ASSIGN_STOPKA');
        if (in_array($ftype, array('varchar', 'text', 'int'))) {
            $assignOptions['nr_zamowienia'] = $langs->trans('KSEF_ASSIGN_NR_ZAMOWIENIA');
            $assignOptions['nr_umowy'] = $langs->trans('KSEF_ASSIGN_NR_UMOWY');
        }
        if (in_array($ftype, array('boolean', 'select', 'varchar', 'int'))) {
            $assignOptions['tp'] = $langs->trans('KSEF_ASSIGN_TP');
            $assignOptions['mpp'] = $langs->trans('KSEF_ASSIGN_MPP');
            $assignOptions['fp'] = $langs->trans('KSEF_ASSIGN_FP');
            $assignOptions['p17'] = $langs->trans('KSEF_ASSIGN_P17');
            $assignOptions['p18'] = $langs->trans('KSEF_ASSIGN_P18');
        }

        $optsText = '';
        if ($ftype === 'select' && !empty($ef_entity->attributes['facture']['param'][$fname]['options'])) {
            $optLines = array();
            foreach ($ef_entity->attributes['facture']['param'][$fname]['options'] as $ocode => $olabel) {
                $optLines[] = $ocode . '|' . $olabel;
            }
            $optsText = implode("\n", $optLines);
        }

        $isModuleField = (($ef_entity->attributes['facture']['langfile'][$fname] ?? '') === 'ksef@ksef');
        $externalBadge = $isModuleField ? '' : ' <span class="badge badge-secondary" title="' . dol_escape_htmltag($langs->trans('KSEF_EXTRAFIELD_EXTERNAL')) . '">ext</span>';

        print '<tr class="oddeven">';
        print '<td>' . dol_escape_htmltag($translatedLabel) . $externalBadge . '</td>';
        print '<td><code>' . dol_escape_htmltag($fname) . '</code></td>';
        print '<td>' . dol_escape_htmltag($ftype) . '</td>';
        print '<td><select name="KSEF_ASSIGN_FACTURE_' . dol_escape_htmltag($fname) . '" class="flat ksef-assign-facture" data-field="' . dol_escape_htmltag($fname) . '" onchange="ksefSyncFromRow(\'facture\', this)">';
        foreach ($assignOptions as $aval => $alabel) {
            print '<option value="' . dol_escape_htmltag($aval) . '"' . ($currentAssign === $aval ? ' selected' : '') . '>' . $alabel . '</option>';
        }
        print '</select></td>';
        print '<td style="text-align:right;">';
        print '<a href="#" onclick="ksefEditExtrafield(this); return false;"';
        print ' data-code="' . dol_escape_htmltag($fname) . '"';
        print ' data-label="' . dol_escape_htmltag($translatedLabel) . '"';
        print ' data-type="' . dol_escape_htmltag($ftype) . '"';
        print ' data-options="' . dol_escape_htmltag($optsText) . '"';
        print ' data-target="facture">';
        print img_edit() . '</a>';
        print '<a href="#" style="margin-left:20px;" onclick="ksefDeleteExtrafield(this); return false;"';
        print ' data-code="' . dol_escape_htmltag($fname) . '"';
        print ' data-target="facture">';
        print img_delete() . '</a>';
        print '</td>';
        print '</tr>';
    }
}
if (!$has_facture_fields) {
    print '<tr class="oddeven"><td colspan="5"><span class="small">' . $langs->trans('KSEF_DODATKOWY_OPIS_FACTURE_NO_EXTRAFIELDS') . '</span></td></tr>';
}

ksefPrintInlineCreateForm('facture', $langs);
print '</table>';

// Third Party fields
print '<br><table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="5">' . $langs->trans('KSEF_DODATKOWY_OPIS_SOCIETE_TITLE') . '</td></tr>';

print '<tr class="oddeven">';
print '<td class="titlefield">' . $form->textwithpicto($langs->trans('KSEF_IDNABYWCY_SOURCE'), $langs->trans('KSEF_IDNABYWCY_SOURCE_Help')) . '</td>';
print '<td colspan="4">';
$idnabywcy_options = array(
    'disabled' => $langs->trans('KSEF_IDNABYWCY_SOURCE_DISABLED'),
    'code_client' => $langs->trans('KSEF_IDNABYWCY_SOURCE_CODE_CLIENT'),
);
if (!empty($ef_entity->attributes['societe']['label'])) {
    foreach ($ef_entity->attributes['societe']['label'] as $fname => $flabel) {
        if (strpos($fname, 'ksef_') === 0) continue;
        $ftype = $ef_entity->attributes['societe']['type'][$fname] ?? '';
        if (in_array($ftype, array('varchar', 'text', 'int'))) {
            $idnabywcy_options['thirdparty_extrafield:' . $fname] = $langs->trans('KSEF_IDNABYWCY_SOURCE_EXTRAFIELD') . ': ' . $langs->trans($flabel);
        }
    }
}
$current_idnabywcy = getDolGlobalString('KSEF_IDNABYWCY_SOURCE', 'disabled');
print $form->selectarray('KSEF_IDNABYWCY_SOURCE', $idnabywcy_options, $current_idnabywcy, 0, 0, 0, 'onchange="ksefSyncFromDropdown(\'societe\', \'KSEF_IDNABYWCY_SOURCE\', \'idnabywcy\', \'thirdparty_extrafield:\')"', 0, 0, 0, '', 'minwidth300');
print '</td></tr>';

print '<tr class="oddeven">';
print '<td class="titlefield">' . $form->textwithpicto($langs->trans('KSEF_NR_UMOWY_SOURCE'), $langs->trans('KSEF_NR_UMOWY_SOURCE_Help')) . '</td>';
print '<td colspan="4">';
$umowy_options = array(
    'disabled' => $langs->trans('KSEF_NR_UMOWY_SOURCE_DISABLED'),
);
if (!empty($ef_entity->attributes['societe']['label'])) {
    foreach ($ef_entity->attributes['societe']['label'] as $fname => $flabel) {
        if (strpos($fname, 'ksef_') === 0) continue;
        $ftype = $ef_entity->attributes['societe']['type'][$fname] ?? '';
        if (in_array($ftype, array('varchar', 'text', 'int'))) {
            $umowy_options['thirdparty_extrafield:' . $fname] = $langs->trans('KSEF_NR_UMOWY_SOURCE_THIRDPARTY_EXTRAFIELD') . ': ' . $langs->trans($flabel);
        }
    }
}
if (!empty($ef_entity->attributes['facture']['label'])) {
    foreach ($ef_entity->attributes['facture']['label'] as $fname => $flabel) {
        if (strpos($fname, 'ksef_') === 0) continue;
        $ftype = $ef_entity->attributes['facture']['type'][$fname] ?? '';
        if (in_array($ftype, array('varchar', 'text', 'int'))) {
            $umowy_options['extrafield:' . $fname] = $langs->trans('KSEF_NR_UMOWY_SOURCE_EXTRAFIELD') . ': ' . $langs->trans($flabel);
        }
    }
}
$current_umowy = getDolGlobalString('KSEF_NR_UMOWY_SOURCE', 'disabled');
print $form->selectarray('KSEF_NR_UMOWY_SOURCE', $umowy_options, $current_umowy, 0, 0, 0, 'onchange="ksefSyncFromDropdown(\'societe\', \'KSEF_NR_UMOWY_SOURCE\', \'nr_umowy\', \'thirdparty_extrafield:\'); ksefSyncFromDropdown(\'facture\', \'KSEF_NR_UMOWY_SOURCE\', \'nr_umowy\', \'extrafield:\')"', 0, 0, 0, '', 'minwidth300');
if (count($umowy_options) <= 1) {
    print '<br><span class="small">' . $langs->trans('KSEF_NR_UMOWY_NO_EXTRAFIELDS') . '</span>';
}
print '<br><label class="paddingtop"><input type="checkbox" name="KSEF_NR_UMOWY_PARSE_DATE" id="KSEF_NR_UMOWY_PARSE_DATE" value="1" ' . (getDolGlobalInt('KSEF_NR_UMOWY_PARSE_DATE') ? 'checked' : '') . '> ' . $langs->trans('KSEF_NR_UMOWY_PARSE_DATE') . '</label>';
print '<br><span class="opacitymedium small">' . $langs->trans('KSEF_NR_UMOWY_PARSE_DATE_Help') . '</span>';
print '</td></tr>';

print '<tr class="oddeven">';
print '<td class="titlefield">' . $form->textwithpicto($langs->trans('KSEF_TP_SOURCE'), $langs->trans('KSEF_TP_SOURCE_Help')) . '</td>';
print '<td colspan="4">';
$tp_options = array(
    'disabled' => $langs->trans('KSEF_TP_SOURCE_DISABLED'),
);
if (!empty($ef_entity->attributes['societe']['label'])) {
    foreach ($ef_entity->attributes['societe']['label'] as $fname => $flabel) {
        if (strpos($fname, 'ksef_') === 0) continue;
        $ftype = $ef_entity->attributes['societe']['type'][$fname] ?? '';
        if (in_array($ftype, array('boolean', 'select', 'varchar', 'int'))) {
            $tp_options['thirdparty_extrafield:' . $fname] = $langs->trans('KSEF_TP_SOURCE_THIRDPARTY_EXTRAFIELD') . ': ' . $langs->trans($flabel);
        }
    }
}
if (!empty($ef_entity->attributes['facture']['label'])) {
    foreach ($ef_entity->attributes['facture']['label'] as $fname => $flabel) {
        if (strpos($fname, 'ksef_') === 0) continue;
        $ftype = $ef_entity->attributes['facture']['type'][$fname] ?? '';
        if (in_array($ftype, array('boolean', 'select', 'varchar', 'int'))) {
            $tp_options['extrafield:' . $fname] = $langs->trans('KSEF_TP_SOURCE_EXTRAFIELD') . ': ' . $langs->trans($flabel);
        }
    }
}
$current_tp = getDolGlobalString('KSEF_TP_SOURCE', 'disabled');
print $form->selectarray('KSEF_TP_SOURCE', $tp_options, $current_tp, 0, 0, 0, 'onchange="ksefSyncFromDropdown(\'societe\', \'KSEF_TP_SOURCE\', \'tp\', \'thirdparty_extrafield:\'); ksefSyncFromDropdown(\'facture\', \'KSEF_TP_SOURCE\', \'tp\', \'extrafield:\')"', 0, 0, 0, '', 'minwidth300');
print '</td></tr>';

// P_17 P_18
$ksefThirdpartyFlagRows = array(
    array('const' => 'KSEF_P17_TP_SOURCE', 'always_on' => false, 'fkey' => '', 'skey' => 'p17'),
    array('const' => 'KSEF_P18_TP_SOURCE', 'always_on' => false, 'fkey' => '', 'skey' => 'p18'),
);
foreach ($ksefThirdpartyFlagRows as $fr) {
    ksefRenderFlagSourceRow($fr, $form, $langs, $ef_entity);
}

// IDWew source
print '<tr class="oddeven">';
print '<td class="titlefield">' . $form->textwithpicto($langs->trans('KSEF_IDWEW_SOURCE'), $langs->trans('KSEF_IDWEW_SOURCE_Help')) . '</td>';
print '<td colspan="4">';
$idwew_options = array(
    'disabled' => $langs->trans('KSEF_IDWEW_SOURCE_DISABLED'),
);
if (!empty($ef_entity->attributes['societe']['label'])) {
    foreach ($ef_entity->attributes['societe']['label'] as $fname => $flabel) {
        if (strpos($fname, 'ksef_') === 0) continue;
        $ftype = $ef_entity->attributes['societe']['type'][$fname] ?? '';
        if (in_array($ftype, array('varchar', 'text'))) {
            $idwew_options['thirdparty_extrafield:' . $fname] = $langs->trans('KSEF_IDWEW_SOURCE_THIRDPARTY_EXTRAFIELD') . ': ' . $langs->trans($flabel);
        }
    }
}
$current_idwew = getDolGlobalString('KSEF_IDWEW_SOURCE', 'disabled');
print $form->selectarray('KSEF_IDWEW_SOURCE', $idwew_options, $current_idwew, 0, 0, 0, 'onchange="ksefSyncFromDropdown(\'societe\', \'KSEF_IDWEW_SOURCE\', \'idwew\', \'thirdparty_extrafield:\')"', 0, 0, 0, '', 'minwidth300');
print '</td></tr>';

// Extrafields
print '<tr class="liste_titre"><td colspan="5">' . $langs->trans('KSEF_ENTITY_BOX_EXTRAFIELDS_THIRDPARTY') . '</td></tr>';

print '<tr class="oddeven"><td colspan="5"><span class="small">' . $langs->trans('KSEF_DODATKOWY_OPIS_SOCIETE_SUBTITLE') . '</span></td></tr>';

// Determine current assignment
$current_soc_config = getDolGlobalString('KSEF_DODATKOWY_OPIS_SOCIETE_EXTRAFIELDS', '');
$enabled_soc_fields = ksefParseEfConfig($current_soc_config);
$_idnSrc = getDolGlobalString('KSEF_IDNABYWCY_SOURCE', 'disabled');
$_nrUmowySrc2 = getDolGlobalString('KSEF_NR_UMOWY_SOURCE', 'disabled');
$_tpSrc2 = getDolGlobalString('KSEF_TP_SOURCE', 'disabled');
$_idwewSrc2 = getDolGlobalString('KSEF_IDWEW_SOURCE', 'disabled');
$_p17TpSrc = getDolGlobalString('KSEF_P17_TP_SOURCE', 'disabled');
$_p18TpSrc = getDolGlobalString('KSEF_P18_TP_SOURCE', 'disabled');
$societe_field_assigns = array();
if (strpos($_idnSrc, 'thirdparty_extrafield:') === 0) $societe_field_assigns[substr($_idnSrc, 22)] = 'idnabywcy';
if (strpos($_nrUmowySrc2, 'thirdparty_extrafield:') === 0) $societe_field_assigns[substr($_nrUmowySrc2, 22)] = 'nr_umowy';
if (strpos($_tpSrc2, 'thirdparty_extrafield:') === 0) $societe_field_assigns[substr($_tpSrc2, 22)] = 'tp';
if (strpos($_idwewSrc2, 'thirdparty_extrafield:') === 0) $societe_field_assigns[substr($_idwewSrc2, 22)] = 'idwew';
if (strpos($_p17TpSrc, 'thirdparty_extrafield:') === 0) $societe_field_assigns[substr($_p17TpSrc, 22)] = 'p17';
if (strpos($_p18TpSrc, 'thirdparty_extrafield:') === 0) $societe_field_assigns[substr($_p18TpSrc, 22)] = 'p18';

$has_soc_fields = false;

print '<tr class="liste_titre">';
print '<td>' . $langs->trans('KSEF_DODATKOWY_OPIS_CREATE_FIELD_LABEL') . '</td>';
print '<td>' . $langs->trans('KSEF_DODATKOWY_OPIS_CREATE_FIELD_CODE') . '</td>';
print '<td>' . $langs->trans('KSEF_DODATKOWY_OPIS_CREATE_FIELD_TYPE') . '</td>';
print '<td>' . $langs->trans('KSEF_ASSIGN_COLUMN') . '</td>';
print '<td style="text-align:right; width:120px;"></td>';
print '</tr>';

if (!empty($ef_entity->attributes['societe']['label'])) {
    foreach ($ef_entity->attributes['societe']['label'] as $fname => $flabel) {
        if (strpos($fname, 'ksef_') === 0) continue;
        $ftype = $ef_entity->attributes['societe']['type'][$fname] ?? '';
        if (in_array($ftype, $_dodUnsupportedTypes)) continue;
        $has_soc_fields = true;
        $translatedLabel = $langs->trans($flabel);

        $currentAssign = '';
        if (isset($societe_field_assigns[$fname])) {
            $currentAssign = $societe_field_assigns[$fname];
        } elseif (isset($enabled_soc_fields[$fname])) {
            $currentAssign = $enabled_soc_fields[$fname];
        }

        $assignOptions = array('' => $langs->trans('KSEF_ASSIGN_NONE'));
        $assignOptions['dodatkowy'] = $langs->trans('KSEF_ASSIGN_DODATKOWY');
        $assignOptions['stopka'] = $langs->trans('KSEF_ASSIGN_STOPKA');
        if (in_array($ftype, array('varchar', 'text', 'int'))) {
            $assignOptions['idnabywcy'] = $langs->trans('KSEF_ASSIGN_IDNABYWCY');
            $assignOptions['nr_umowy'] = $langs->trans('KSEF_ASSIGN_NR_UMOWY');
        }
        if (in_array($ftype, array('varchar', 'text'))) {
            $assignOptions['idwew'] = $langs->trans('KSEF_ASSIGN_IDWEW');
        }
        if (in_array($ftype, array('boolean', 'select', 'varchar', 'int'))) {
            $assignOptions['tp'] = $langs->trans('KSEF_ASSIGN_TP');
            $assignOptions['p17'] = $langs->trans('KSEF_ASSIGN_P17');   // P_17 self-billing, per-buyer default (#29)
            $assignOptions['p18'] = $langs->trans('KSEF_ASSIGN_P18');   // P_18 reverse charge, per-buyer default (#29)
        }

        $socOptsText = '';
        if ($ftype === 'select' && !empty($ef_entity->attributes['societe']['param'][$fname]['options'])) {
            $socOptLines = array();
            foreach ($ef_entity->attributes['societe']['param'][$fname]['options'] as $ocode => $olabel) {
                $socOptLines[] = $ocode . '|' . $olabel;
            }
            $socOptsText = implode("\n", $socOptLines);
        }

        $isModuleSocField = (($ef_entity->attributes['societe']['langfile'][$fname] ?? '') === 'ksef@ksef');
        $socExternalBadge = $isModuleSocField ? '' : ' <span class="badge badge-secondary" title="' . dol_escape_htmltag($langs->trans('KSEF_EXTRAFIELD_EXTERNAL')) . '">ext</span>';

        print '<tr class="oddeven">';
        print '<td>' . dol_escape_htmltag($translatedLabel) . $socExternalBadge . '</td>';
        print '<td><code>' . dol_escape_htmltag($fname) . '</code></td>';
        print '<td>' . dol_escape_htmltag($ftype) . '</td>';
        print '<td><select name="KSEF_ASSIGN_SOCIETE_' . dol_escape_htmltag($fname) . '" class="flat ksef-assign-societe" data-field="' . dol_escape_htmltag($fname) . '" onchange="ksefSyncFromRow(\'societe\', this)">';
        foreach ($assignOptions as $aval => $alabel) {
            print '<option value="' . dol_escape_htmltag($aval) . '"' . ($currentAssign === $aval ? ' selected' : '') . '>' . $alabel . '</option>';
        }
        print '</select></td>';
        print '<td style="text-align:right;">';
        print '<a href="#" onclick="ksefEditExtrafield(this); return false;"';
        print ' data-code="' . dol_escape_htmltag($fname) . '"';
        print ' data-label="' . dol_escape_htmltag($translatedLabel) . '"';
        print ' data-type="' . dol_escape_htmltag($ftype) . '"';
        print ' data-options="' . dol_escape_htmltag($socOptsText) . '"';
        print ' data-target="societe">';
        print img_edit() . '</a>';
        print '<a href="#" style="margin-left:20px;" onclick="ksefDeleteExtrafield(this); return false;"';
        print ' data-code="' . dol_escape_htmltag($fname) . '"';
        print ' data-target="societe">';
        print img_delete() . '</a>';
        print '</td>';
        print '</tr>';
    }
}
if (!$has_soc_fields) {
    print '<tr class="oddeven"><td colspan="5"><span class="small">' . $langs->trans('KSEF_DODATKOWY_OPIS_SOCIETE_NO_EXTRAFIELDS') . '</span></td></tr>';
}

ksefPrintInlineCreateForm('societe', $langs);
print '</table>';

// Product fields
print '<br><table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="5">' . $langs->trans('KSEF_DODATKOWY_OPIS_PRODUCT_TITLE') . '</td></tr>';

print '<tr class="oddeven">';
print '<td class="titlefield">' . $form->textwithpicto($langs->trans('KSEF_GTU_SOURCE'), $langs->trans('KSEF_GTU_SOURCE_Help')) . '</td>';
print '<td colspan="4">';
$gtu_options = array(
    'disabled' => $langs->trans('KSEF_GTU_SOURCE_DISABLED'),
);
if (!empty($ef_entity->attributes['product']['label'])) {
    foreach ($ef_entity->attributes['product']['label'] as $fname => $flabel) {
        if (strpos($fname, 'ksef_') === 0) continue;
        $ftype = $ef_entity->attributes['product']['type'][$fname] ?? '';
        if (in_array($ftype, array('varchar', 'select', 'text'))) {
            $gtu_options['product_extrafield:' . $fname] = $langs->trans('KSEF_GTU_SOURCE_PRODUCT_EXTRAFIELD') . ': ' . $langs->trans($flabel);
        }
    }
}
$current_gtu = getDolGlobalString('KSEF_GTU_SOURCE', 'disabled');
print $form->selectarray('KSEF_GTU_SOURCE', $gtu_options, $current_gtu, 0, 0, 0, 'onchange="ksefSyncFromDropdown(\'product\', \'KSEF_GTU_SOURCE\', \'gtu\', \'product_extrafield:\')"', 0, 0, 0, '', 'minwidth300');
print '</td></tr>';

print '<tr class="oddeven">';
print '<td class="titlefield">' . $form->textwithpicto($langs->trans('KSEF_PROCEDURA_SOURCE'), $langs->trans('KSEF_PROCEDURA_SOURCE_Help')) . '</td>';
print '<td colspan="4">';
$procedura_options = array(
    'disabled' => $langs->trans('KSEF_PROCEDURA_SOURCE_DISABLED'),
);
if (!empty($ef_entity->attributes['product']['label'])) {
    foreach ($ef_entity->attributes['product']['label'] as $fname => $flabel) {
        if (strpos($fname, 'ksef_') === 0) continue;
        $ftype = $ef_entity->attributes['product']['type'][$fname] ?? '';
        if (in_array($ftype, array('varchar', 'select', 'text'))) {
            $procedura_options['product_extrafield:' . $fname] = $langs->trans('KSEF_PROCEDURA_SOURCE_PRODUCT_EXTRAFIELD') . ': ' . $langs->trans($flabel);
        }
    }
}
$current_procedura = getDolGlobalString('KSEF_PROCEDURA_SOURCE', 'disabled');
print $form->selectarray('KSEF_PROCEDURA_SOURCE', $procedura_options, $current_procedura, 0, 0, 0, 'onchange="ksefSyncFromDropdown(\'product\', \'KSEF_PROCEDURA_SOURCE\', \'procedura\', \'product_extrafield:\')"', 0, 0, 0, '', 'minwidth300');
print '</td></tr>';

// Per-product VAT exemption basis
print '<tr class="oddeven">';
print '<td class="titlefield">' . $form->textwithpicto($langs->trans('KSEF_ZWOLNIENIE_PRODUCT_FIELD'), $langs->trans('KSEF_ZWOLNIENIE_PRODUCT_FIELD_Help')) . '</td>';
print '<td colspan="4">';
$zwolnienie_field_options = array('' => $langs->trans('KSEF_ZWOLNIENIE_PRODUCT_FIELD_DISABLED'));
if (!empty($ef_entity->attributes['product']['label'])) {
    foreach ($ef_entity->attributes['product']['label'] as $fname => $flabel) {
        $ftype = $ef_entity->attributes['product']['type'][$fname] ?? '';
        if (in_array($ftype, array('varchar', 'text', 'select'))) {
            $zwolnienie_field_options[$fname] = $langs->trans($flabel) . ' (' . $fname . ')';
        }
    }
}
$current_zwolnienie_field = getDolGlobalString('KSEF_ZWOLNIENIE_PRODUCT_FIELD', '');
print $form->selectarray('KSEF_ZWOLNIENIE_PRODUCT_FIELD', $zwolnienie_field_options, $current_zwolnienie_field, 0, 0, 0, 'onchange="ksefSyncFromDropdown(\'product\', \'KSEF_ZWOLNIENIE_PRODUCT_FIELD\', \'zwolnienie\', \'\')"', 0, 0, 0, '', 'minwidth300');
print '</td></tr>';

// Extrafields
print '<tr class="liste_titre"><td colspan="5">' . $langs->trans('KSEF_ENTITY_BOX_EXTRAFIELDS_PRODUCT') . '</td></tr>';

print '<tr class="oddeven"><td colspan="5"><span class="small">' . $langs->trans('KSEF_DODATKOWY_OPIS_PRODUCT_SUBTITLE') . '</span></td></tr>';

// Determine current assignment
$current_prod_config = getDolGlobalString('KSEF_DODATKOWY_OPIS_PRODUCT_EXTRAFIELDS', '');
$enabled_prod_fields = array_filter(array_map('trim', explode(',', $current_prod_config)));
$_gtuSrc = getDolGlobalString('KSEF_GTU_SOURCE', 'disabled');
$_procSrc = getDolGlobalString('KSEF_PROCEDURA_SOURCE', 'disabled');
$_zwolProdField = getDolGlobalString('KSEF_ZWOLNIENIE_PRODUCT_FIELD', '');
$product_field_assigns = array();
if (strpos($_gtuSrc, 'product_extrafield:') === 0) $product_field_assigns[substr($_gtuSrc, 19)] = 'gtu';
if (strpos($_procSrc, 'product_extrafield:') === 0) $product_field_assigns[substr($_procSrc, 19)] = 'procedura';
if ($_zwolProdField !== '') $product_field_assigns[$_zwolProdField] = 'zwolnienie';

$has_prod_fields = false;

print '<tr class="liste_titre">';
print '<td>' . $langs->trans('KSEF_DODATKOWY_OPIS_CREATE_FIELD_LABEL') . '</td>';
print '<td>' . $langs->trans('KSEF_DODATKOWY_OPIS_CREATE_FIELD_CODE') . '</td>';
print '<td>' . $langs->trans('KSEF_DODATKOWY_OPIS_CREATE_FIELD_TYPE') . '</td>';
print '<td>' . $langs->trans('KSEF_ASSIGN_COLUMN') . '</td>';
print '<td style="text-align:right; width:120px;"></td>';
print '</tr>';

if (!empty($ef_entity->attributes['product']['label'])) {
    foreach ($ef_entity->attributes['product']['label'] as $fname => $flabel) {
        if (strpos($fname, 'ksef_') === 0) continue;
        $ftype = $ef_entity->attributes['product']['type'][$fname] ?? '';
        if (in_array($ftype, $_dodUnsupportedTypes)) continue;
        $has_prod_fields = true;
        $translatedLabel = $langs->trans($flabel);

        $currentAssign = '';
        if (isset($product_field_assigns[$fname])) {
            $currentAssign = $product_field_assigns[$fname];
        } elseif (in_array($fname, $enabled_prod_fields)) {
            $currentAssign = 'dodatkowy';
        }

        $assignOptions = array('' => $langs->trans('KSEF_ASSIGN_NONE'));
        $assignOptions['dodatkowy'] = $langs->trans('KSEF_ASSIGN_DODATKOWY');
        if (in_array($ftype, array('varchar', 'select', 'text'))) {
            $assignOptions['gtu'] = $langs->trans('KSEF_ASSIGN_GTU');
            $assignOptions['procedura'] = $langs->trans('KSEF_ASSIGN_PROCEDURA');
            $assignOptions['zwolnienie'] = $langs->trans('KSEF_ASSIGN_ZWOLNIENIE');
        }

        $prodOptsText = '';
        if ($ftype === 'select' && !empty($ef_entity->attributes['product']['param'][$fname]['options'])) {
            $prodOptLines = array();
            foreach ($ef_entity->attributes['product']['param'][$fname]['options'] as $ocode => $olabel) {
                $prodOptLines[] = $ocode . '|' . $olabel;
            }
            $prodOptsText = implode("\n", $prodOptLines);
        }

        $isModuleProdField = (($ef_entity->attributes['product']['langfile'][$fname] ?? '') === 'ksef@ksef');
        $prodExternalBadge = $isModuleProdField ? '' : ' <span class="badge badge-secondary" title="' . dol_escape_htmltag($langs->trans('KSEF_EXTRAFIELD_EXTERNAL')) . '">ext</span>';

        print '<tr class="oddeven">';
        print '<td>' . dol_escape_htmltag($translatedLabel) . $prodExternalBadge . '</td>';
        print '<td><code>' . dol_escape_htmltag($fname) . '</code></td>';
        print '<td>' . dol_escape_htmltag($ftype) . '</td>';
        print '<td><select name="KSEF_ASSIGN_PRODUCT_' . dol_escape_htmltag($fname) . '" class="flat ksef-assign-product" data-field="' . dol_escape_htmltag($fname) . '" onchange="ksefSyncFromRow(\'product\', this)">';
        foreach ($assignOptions as $aval => $alabel) {
            print '<option value="' . dol_escape_htmltag($aval) . '"' . ($currentAssign === $aval ? ' selected' : '') . '>' . $alabel . '</option>';
        }
        print '</select></td>';
        print '<td style="text-align:right;">';
        print '<a href="#" onclick="ksefEditExtrafield(this); return false;"';
        print ' data-code="' . dol_escape_htmltag($fname) . '"';
        print ' data-label="' . dol_escape_htmltag($translatedLabel) . '"';
        print ' data-type="' . dol_escape_htmltag($ftype) . '"';
        print ' data-options="' . dol_escape_htmltag($prodOptsText) . '"';
        print ' data-target="product">';
        print img_edit() . '</a>';
        print '<a href="#" style="margin-left:20px;" onclick="ksefDeleteExtrafield(this); return false;"';
        print ' data-code="' . dol_escape_htmltag($fname) . '"';
        print ' data-target="product">';
        print img_delete() . '</a>';
        print '</td>';
        print '</tr>';
    }
}
if (!$has_prod_fields) {
    print '<tr class="oddeven"><td colspan="5"><span class="small">' . $langs->trans('KSEF_DODATKOWY_OPIS_PRODUCT_NO_EXTRAFIELDS') . '</span></td></tr>';
}

ksefPrintInlineCreateForm('product', $langs);
print '</table>';

// Invoice lines
print '<br><table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="5">' . $langs->trans('KSEF_DODATKOWY_OPIS_DET_TITLE') . '</td></tr>';

// Line item settings
print '<tr class="oddeven">';
print '<td class="titlefield">' . $form->textwithpicto($langs->trans('KSEF_FA3_INCLUDE_NRKLIENTA'), $langs->trans('KSEF_FA3_INCLUDE_NRKLIENTA_Help')) . '</td>';
print '<td colspan="4">';
print '<input type="checkbox" name="KSEF_FA3_INCLUDE_NRKLIENTA" id="KSEF_FA3_INCLUDE_NRKLIENTA" value="1" ' . (getDolGlobalInt('KSEF_FA3_INCLUDE_NRKLIENTA') ? 'checked' : '') . '>';
print ' <label for="KSEF_FA3_INCLUDE_NRKLIENTA">' . $langs->trans("KSEF_Enabled") . '</label>';
print '</td></tr>';

print '<tr class="oddeven">';
print '<td class="titlefield">' . $form->textwithpicto($langs->trans('KSEF_FA3_INCLUDE_INDEKS'), $langs->trans('KSEF_FA3_INCLUDE_INDEKS_Help')) . '</td>';
print '<td colspan="4">';
print '<input type="checkbox" name="KSEF_FA3_INCLUDE_INDEKS" id="KSEF_FA3_INCLUDE_INDEKS" value="1" ' . (getDolGlobalInt('KSEF_FA3_INCLUDE_INDEKS') ? 'checked' : '') . '>';
print ' <label for="KSEF_FA3_INCLUDE_INDEKS">' . $langs->trans("KSEF_Enabled") . '</label>';
print '</td></tr>';

print '<tr class="oddeven">';
print '<td class="titlefield">' . $form->textwithpicto($langs->trans('KSEF_FA3_INCLUDE_GTIN'), $langs->trans('KSEF_FA3_INCLUDE_GTIN_Help')) . '</td>';
print '<td colspan="4">';
print '<input type="checkbox" name="KSEF_FA3_INCLUDE_GTIN" id="KSEF_FA3_INCLUDE_GTIN" value="1" ' . (getDolGlobalInt('KSEF_FA3_INCLUDE_GTIN') ? 'checked' : '') . '>';
print ' <label for="KSEF_FA3_INCLUDE_GTIN">' . $langs->trans("KSEF_Enabled") . '</label>';
print '</td></tr>';

print '<tr class="oddeven">';
print '<td class="titlefield">' . $form->textwithpicto($langs->trans('KSEF_FA3_INCLUDE_UNIT'), $langs->trans('KSEF_FA3_INCLUDE_UNIT_Help')) . '</td>';
print '<td colspan="4">';
print '<input type="checkbox" name="KSEF_FA3_INCLUDE_UNIT" id="KSEF_FA3_INCLUDE_UNIT" value="1" ' . (getDolGlobalInt('KSEF_FA3_INCLUDE_UNIT') ? 'checked' : '') . '>';
print ' <label for="KSEF_FA3_INCLUDE_UNIT">' . $langs->trans("KSEF_Enabled") . '</label>';
print '</td></tr>';

print '<tr class="oddeven">';
print '<td class="titlefield">' . $form->textwithpicto($langs->trans('KSEF_FA3_INCLUDE_UU_ID'), $langs->trans('KSEF_FA3_INCLUDE_UU_ID_Help')) . '</td>';
print '<td colspan="4">';
print '<input type="checkbox" name="KSEF_FA3_INCLUDE_UU_ID" id="KSEF_FA3_INCLUDE_UU_ID" value="1" ' . (getDolGlobalInt('KSEF_FA3_INCLUDE_UU_ID') ? 'checked' : '') . '>';
print ' <label for="KSEF_FA3_INCLUDE_UU_ID">' . $langs->trans("KSEF_Enabled") . '</label>';
print '</td></tr>';

// Extrafields
print '<tr class="liste_titre"><td colspan="5">' . $langs->trans('KSEF_ENTITY_BOX_EXTRAFIELDS_INVOICELINE') . '</td></tr>';

print '<tr class="oddeven"><td colspan="5"><span class="small">' . $langs->trans('KSEF_DODATKOWY_OPIS_DET_SUBTITLE') . '</span></td></tr>';

$current_det_config = getDolGlobalString('KSEF_DODATKOWY_OPIS_DET_EXTRAFIELDS', '');
$enabled_det_fields = array_filter(array_map('trim', explode(',', $current_det_config)));
$has_det_fields = false;

print '<tr class="liste_titre">';
print '<td>' . $langs->trans('KSEF_DODATKOWY_OPIS_CREATE_FIELD_LABEL') . '</td>';
print '<td>' . $langs->trans('KSEF_DODATKOWY_OPIS_CREATE_FIELD_CODE') . '</td>';
print '<td>' . $langs->trans('KSEF_DODATKOWY_OPIS_CREATE_FIELD_TYPE') . '</td>';
print '<td>' . $langs->trans('KSEF_ASSIGN_COLUMN') . '</td>';
print '<td style="text-align:right; width:120px;"></td>';
print '</tr>';

if (!empty($ef_entity->attributes['facturedet']['label'])) {
    foreach ($ef_entity->attributes['facturedet']['label'] as $fname => $flabel) {
        if (strpos($fname, 'ksef_') === 0) continue;
        $ftype = $ef_entity->attributes['facturedet']['type'][$fname] ?? '';
        if (in_array($ftype, $_dodUnsupportedTypes)) continue;
        $has_det_fields = true;
        $translatedLabel = $langs->trans($flabel);

        $currentAssign = in_array($fname, $enabled_det_fields) ? 'dodatkowy' : '';

        $detOptsText = '';
        if ($ftype === 'select' && !empty($ef_entity->attributes['facturedet']['param'][$fname]['options'])) {
            $detOptLines = array();
            foreach ($ef_entity->attributes['facturedet']['param'][$fname]['options'] as $ocode => $olabel) {
                $detOptLines[] = $ocode . '|' . $olabel;
            }
            $detOptsText = implode("\n", $detOptLines);
        }

        $isModuleDetField = (($ef_entity->attributes['facturedet']['langfile'][$fname] ?? '') === 'ksef@ksef');
        $detExternalBadge = $isModuleDetField ? '' : ' <span class="badge badge-secondary" title="' . dol_escape_htmltag($langs->trans('KSEF_EXTRAFIELD_EXTERNAL')) . '">ext</span>';

        print '<tr class="oddeven">';
        print '<td>' . dol_escape_htmltag($translatedLabel) . $detExternalBadge . '</td>';
        print '<td><code>' . dol_escape_htmltag($fname) . '</code></td>';
        print '<td>' . dol_escape_htmltag($ftype) . '</td>';
        print '<td><select name="KSEF_ASSIGN_FACTUREDET_' . dol_escape_htmltag($fname) . '" class="flat">';
        print '<option value=""' . ($currentAssign === '' ? ' selected' : '') . '>' . $langs->trans('KSEF_ASSIGN_NONE') . '</option>';
        print '<option value="dodatkowy"' . ($currentAssign === 'dodatkowy' ? ' selected' : '') . '>' . $langs->trans('KSEF_ASSIGN_DODATKOWY') . '</option>';
        print '</select></td>';
        print '<td style="text-align:right;">';
        print '<a href="#" onclick="ksefEditExtrafield(this); return false;"';
        print ' data-code="' . dol_escape_htmltag($fname) . '"';
        print ' data-label="' . dol_escape_htmltag($translatedLabel) . '"';
        print ' data-type="' . dol_escape_htmltag($ftype) . '"';
        print ' data-options="' . dol_escape_htmltag($detOptsText) . '"';
        print ' data-target="facturedet">';
        print img_edit() . '</a>';
        print '<a href="#" style="margin-left:20px;" onclick="ksefDeleteExtrafield(this); return false;"';
        print ' data-code="' . dol_escape_htmltag($fname) . '"';
        print ' data-target="facturedet">';
        print img_delete() . '</a>';
        print '</td>';
        print '</tr>';
    }
}
if (!$has_det_fields) {
    print '<tr class="oddeven"><td colspan="5"><span class="small">' . $langs->trans('KSEF_DODATKOWY_OPIS_DET_NO_EXTRAFIELDS') . '</span></td></tr>';
}

ksefPrintInlineCreateForm('facturedet', $langs);
print '</table>';

// Project fields
print '<br><table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="5">' . $langs->trans('KSEF_DODATKOWY_OPIS_PROJECT_TITLE') . '</td></tr>';

// Extrafields
print '<tr class="liste_titre"><td colspan="5">' . $langs->trans('KSEF_ENTITY_BOX_EXTRAFIELDS_PROJECT') . '</td></tr>';

print '<tr class="oddeven"><td colspan="5"><span class="small">' . $langs->trans('KSEF_DODATKOWY_OPIS_PROJECT_SUBTITLE') . '</span></td></tr>';

$current_proj_config = getDolGlobalString('KSEF_DODATKOWY_OPIS_PROJECT_EXTRAFIELDS', '');
$enabled_proj_fields = ksefParseEfConfig($current_proj_config);
$has_proj_fields = false;

print '<tr class="liste_titre">';
print '<td>' . $langs->trans('KSEF_DODATKOWY_OPIS_CREATE_FIELD_LABEL') . '</td>';
print '<td>' . $langs->trans('KSEF_DODATKOWY_OPIS_CREATE_FIELD_CODE') . '</td>';
print '<td>' . $langs->trans('KSEF_DODATKOWY_OPIS_CREATE_FIELD_TYPE') . '</td>';
print '<td>' . $langs->trans('KSEF_ASSIGN_COLUMN') . '</td>';
print '<td style="text-align:right; width:120px;"></td>';
print '</tr>';

if (!empty($ef_entity->attributes['projet']['label'])) {
    foreach ($ef_entity->attributes['projet']['label'] as $fname => $flabel) {
        if (strpos($fname, 'ksef_') === 0) continue;
        $ftype = $ef_entity->attributes['projet']['type'][$fname] ?? '';
        if (in_array($ftype, $_dodUnsupportedTypes)) continue;
        $has_proj_fields = true;
        $translatedLabel = $langs->trans($flabel);

        $currentAssign = isset($enabled_proj_fields[$fname]) ? $enabled_proj_fields[$fname] : '';

        $projOptsText = '';
        if ($ftype === 'select' && !empty($ef_entity->attributes['projet']['param'][$fname]['options'])) {
            $projOptLines = array();
            foreach ($ef_entity->attributes['projet']['param'][$fname]['options'] as $ocode => $olabel) {
                $projOptLines[] = $ocode . '|' . $olabel;
            }
            $projOptsText = implode("\n", $projOptLines);
        }

        $isModuleProjField = (($ef_entity->attributes['projet']['langfile'][$fname] ?? '') === 'ksef@ksef');
        $projExternalBadge = $isModuleProjField ? '' : ' <span class="badge badge-secondary" title="' . dol_escape_htmltag($langs->trans('KSEF_EXTRAFIELD_EXTERNAL')) . '">ext</span>';

        print '<tr class="oddeven">';
        print '<td>' . dol_escape_htmltag($translatedLabel) . $projExternalBadge . '</td>';
        print '<td><code>' . dol_escape_htmltag($fname) . '</code></td>';
        print '<td>' . dol_escape_htmltag($ftype) . '</td>';
        print '<td><select name="KSEF_ASSIGN_PROJET_' . dol_escape_htmltag($fname) . '" class="flat">';
        print '<option value=""' . ($currentAssign === '' ? ' selected' : '') . '>' . $langs->trans('KSEF_ASSIGN_NONE') . '</option>';
        print '<option value="dodatkowy"' . ($currentAssign === 'dodatkowy' ? ' selected' : '') . '>' . $langs->trans('KSEF_ASSIGN_DODATKOWY') . '</option>';
        print '<option value="stopka"' . ($currentAssign === 'stopka' ? ' selected' : '') . '>' . $langs->trans('KSEF_ASSIGN_STOPKA') . '</option>';
        print '</select></td>';
        print '<td style="text-align:right;">';
        print '<a href="#" onclick="ksefEditExtrafield(this); return false;"';
        print ' data-code="' . dol_escape_htmltag($fname) . '"';
        print ' data-label="' . dol_escape_htmltag($translatedLabel) . '"';
        print ' data-type="' . dol_escape_htmltag($ftype) . '"';
        print ' data-options="' . dol_escape_htmltag($projOptsText) . '"';
        print ' data-target="projet">';
        print img_edit() . '</a>';
        print '<a href="#" style="margin-left:20px;" onclick="ksefDeleteExtrafield(this); return false;"';
        print ' data-code="' . dol_escape_htmltag($fname) . '"';
        print ' data-target="projet">';
        print img_delete() . '</a>';
        print '</td>';
        print '</tr>';
    }
}
if (!$has_proj_fields) {
    print '<tr class="oddeven"><td colspan="5"><span class="small">' . $langs->trans('KSEF_DODATKOWY_OPIS_PROJECT_NO_EXTRAFIELDS') . '</span></td></tr>';
}

ksefPrintInlineCreateForm('projet', $langs);
print '</table>';

print '<script>
function ksefToggleOptions(entity) {
    var typeSel = document.getElementById("ksef_ef_type_" + entity);
    document.getElementById("ksef_ef_options_row_" + entity).style.display = (typeSel.value === "select") ? "" : "none";
}
function ksefEditExtrafield(btn) {
    var entity = btn.dataset.target || "facture";
    var area = document.getElementById("ksef_ef_form_area_" + entity);
    area.dataset.mode = "edit";
    var code = btn.dataset.code || "";
    document.getElementById("ksef_ef_label_" + entity).value = btn.dataset.label || "";
    document.getElementById("ksef_ef_code_" + entity).value = code;
    document.getElementById("ksef_ef_code_display_" + entity).textContent = code;
    document.getElementById("ksef_ef_code_row_" + entity).style.display = "";
    document.getElementById("ksef_ef_type_" + entity).value = btn.dataset.type || "varchar";
    document.getElementById("ksef_ef_options_" + entity).value = btn.dataset.options || "";
    var inputs = ["ksef_ef_label_" + entity, "ksef_ef_type_" + entity, "ksef_ef_options_" + entity];
    inputs.forEach(function(id) { document.getElementById(id).setAttribute("form", "ksef_ef_edit_form_" + entity); });
    document.getElementById("ksef_ef_create_btn_" + entity).style.display = "none";
    document.getElementById("ksef_ef_edit_btn_" + entity).style.display = "";
    document.getElementById("ksef_ef_cancel_btn_" + entity).style.display = "";
    ksefToggleOptions(entity);
    document.getElementById("ksef_ef_create_details_" + entity).open = true;
    document.getElementById("ksef_ef_label_" + entity).focus();
}
function ksefCancelEdit(entity) {
    var area = document.getElementById("ksef_ef_form_area_" + entity);
    area.dataset.mode = "create";
    document.getElementById("ksef_ef_label_" + entity).value = "";
    document.getElementById("ksef_ef_code_" + entity).value = "";
    document.getElementById("ksef_ef_code_display_" + entity).textContent = "";
    document.getElementById("ksef_ef_code_row_" + entity).style.display = "none";
    document.getElementById("ksef_ef_type_" + entity).value = "varchar";
    document.getElementById("ksef_ef_options_" + entity).value = "";
    var inputs = ["ksef_ef_label_" + entity, "ksef_ef_type_" + entity, "ksef_ef_options_" + entity];
    inputs.forEach(function(id) { document.getElementById(id).setAttribute("form", "ksef_ef_create_form_" + entity); });
    document.getElementById("ksef_ef_create_btn_" + entity).style.display = "";
    document.getElementById("ksef_ef_edit_btn_" + entity).style.display = "none";
    document.getElementById("ksef_ef_cancel_btn_" + entity).style.display = "none";
    ksefToggleOptions(entity);
}
function ksefDeleteExtrafield(btn) {
    var code = btn.dataset.code;
    var entity = btn.dataset.target || "facture";
    var msg = "' . dol_escape_js($langs->transnoentities('KSEF_DODATKOWY_OPIS_DELETE_CONFIRM', '__CODE__')) . '".replace("__CODE__", code);
    if (confirm(msg)) {
        document.getElementById("ksef_ef_delete_code_" + entity).value = code;
        document.getElementById("ksef_ef_delete_form_" + entity).submit();
    }
}

var ksefFieldSourceMap = {
    facture: {
        nr_zamowienia: {dropdown: "KSEF_NR_ZAMOWIENIA_SOURCE", prefix: "extrafield:"},
        nr_umowy: {dropdown: "KSEF_NR_UMOWY_SOURCE", prefix: "extrafield:"},
        tp: {dropdown: "KSEF_TP_SOURCE", prefix: "extrafield:"},
        mpp: {dropdown: "KSEF_FA3_MPP_SOURCE", prefix: "extrafield:"},
        fp: {dropdown: "KSEF_FA3_FP_SOURCE", prefix: "extrafield:"},
        p17: {dropdown: "KSEF_P17_SOURCE", prefix: "extrafield:"},
        p18: {dropdown: "KSEF_P18_SOURCE", prefix: "extrafield:"}
    },
    societe: {
        idnabywcy: {dropdown: "KSEF_IDNABYWCY_SOURCE", prefix: "thirdparty_extrafield:"},
        nr_umowy: {dropdown: "KSEF_NR_UMOWY_SOURCE", prefix: "thirdparty_extrafield:"},
        tp: {dropdown: "KSEF_TP_SOURCE", prefix: "thirdparty_extrafield:"},
        idwew: {dropdown: "KSEF_IDWEW_SOURCE", prefix: "thirdparty_extrafield:"},
        p17: {dropdown: "KSEF_P17_TP_SOURCE", prefix: "thirdparty_extrafield:"},
        p18: {dropdown: "KSEF_P18_TP_SOURCE", prefix: "thirdparty_extrafield:"}
    },
    product: {
        gtu: {dropdown: "KSEF_GTU_SOURCE", prefix: "product_extrafield:"},
        procedura: {dropdown: "KSEF_PROCEDURA_SOURCE", prefix: "product_extrafield:"},
        zwolnienie: {dropdown: "KSEF_ZWOLNIENIE_PRODUCT_FIELD", prefix: ""}
    }
};

function ksefSyncFromRow(entity, sel) {
    var fieldName = sel.dataset.field;
    var assignVal = sel.value;
    var map = ksefFieldSourceMap[entity] || {};

    if (map[assignVal]) {
        var cfg = map[assignVal];
        var dd = document.querySelector("select[name=\"" + cfg.dropdown + "\"]");
        if (dd) {
            var newVal = cfg.prefix + fieldName;
            // Add option if it does not exist
            var found = false;
            for (var i = 0; i < dd.options.length; i++) {
                if (dd.options[i].value === newVal) { found = true; break; }
            }
            if (!found) {
                var opt = document.createElement("option");
                opt.value = newVal;
                opt.text = fieldName;
                dd.appendChild(opt);
            }
            dd.value = newVal;
        }

        var entityUpper = entity.charAt(0).toUpperCase() + entity.slice(1);
        if (entity === "facture") entityUpper = "FACTURE";
        else if (entity === "societe") entityUpper = "SOCIETE";
        else if (entity === "product") entityUpper = "PRODUCT";
        document.querySelectorAll("select.ksef-assign-" + entity).forEach(function(other) {
            if (other !== sel && other.value === assignVal) {
                other.value = "";
            }
        });
    }

    var noteValues = ["", "dodatkowy", "stopka"];
    if (noteValues.indexOf(assignVal) !== -1) {
        Object.keys(map).forEach(function(srcKey) {
            var cfg = map[srcKey];
            var dd = document.querySelector("select[name=\"" + cfg.dropdown + "\"]");
            if (dd && dd.value === cfg.prefix + fieldName) {
                dd.value = "disabled";
            }
        });
    }
}

function ksefSyncFromDropdown(entity, dropdownName, assignKey, prefix) {
    var dd = document.querySelector("select[name=\"" + dropdownName + "\"]");
    if (!dd) return;
    var val = dd.value;
    var rows = document.querySelectorAll("select.ksef-assign-" + entity);

    rows.forEach(function(sel) {
        if (sel.value === assignKey) {
            sel.value = "";
        }
    });

    if (prefix !== "" && val.indexOf(prefix) === 0) {
        var efName = val.substring(prefix.length);
        rows.forEach(function(sel) {
            if (sel.dataset.field === efName) {
                sel.value = assignKey;
            }
        });
    } else if (prefix === "" && val !== "" && val !== "disabled") {
        rows.forEach(function(sel) {
            if (sel.dataset.field === val) {
                sel.value = assignKey;
            }
        });
    }
}

document.addEventListener("DOMContentLoaded", function() {
    var presetSel = document.getElementById("KSEF_DEFAULT_CORRECTION_REASON_PRESET");
    if (presetSel) {
        presetSel.addEventListener("change", function() {
            var customInput = document.getElementById("KSEF_DEFAULT_CORRECTION_REASON_CUSTOM");
            if (this.value === "custom") {
                customInput.style.display = "inline-block";
                customInput.focus();
            } else {
                customInput.style.display = "none";
                customInput.value = "";
            }
        });
    }

    Object.keys(ksefFieldSourceMap).forEach(function(entity) {
        var map = ksefFieldSourceMap[entity];
        Object.keys(map).forEach(function(assignKey) {
            var cfg = map[assignKey];
            var dd = document.querySelector("select[name=\"" + cfg.dropdown + "\"]");
            if (dd) {
                dd.addEventListener("change", function() {
                    ksefSyncFromDropdown(entity, cfg.dropdown, assignKey, cfg.prefix);
                });
            }
        });
    });
});
</script>';

// Save button
print '<div class="center" style="margin-top: 10px;">';
print '<input type="submit" class="button button-save" value="' . $langs->trans("Save") . '">';
print '</div>';
print '</form>';

// Customer Exclusions
print '<br><form method="POST" action="' . $_SERVER["PHP_SELF"] . '">';
print '<input type="hidden" name="token" value="' . newToken() . '">';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="2">' . $langs->trans("KSEF_CUSTOMER_EXCLUSIONS") . '</td></tr>';

print '<tr class="oddeven">';
print '<td class="titlefield">' . $form->textwithpicto($langs->trans("KSEF_EXCLUDED_CUSTOMERS"), $langs->trans("KSEF_EXCLUDED_CUSTOMERS_Help")) . '</td>';
print '<td>';

$_excludedStr = getDolGlobalString('KSEF_EXCLUDED_CUSTOMERS', '');
if (!empty($_excludedStr)) {
    $excluded_ids = array_filter(array_map('trim', explode(',', $_excludedStr)));
    foreach ($excluded_ids as $socid) {
        $tmpsoc = new Societe($db);
        if ($tmpsoc->fetch($socid) > 0) {
            print '<span class="badge badge-secondary" style="margin-right: 5px;">';
            print dol_escape_htmltag($tmpsoc->name);
            print ' <a href="' . $_SERVER["PHP_SELF"] . '?action=remove_excluded&socid=' . $socid . '&token=' . newToken() . '" style="color:white;">×</a>';
            print '</span> ';
        }
    }
    print '<br><br>';
}

print '<select id="excluded_customer" name="socid" class="flat minwidth300">';
print '<option value="">-- ' . $langs->trans("KSEF_SelectCustomer") . ' --</option>';
$sql = "SELECT rowid, nom FROM " . MAIN_DB_PREFIX . "societe WHERE client IN (1, 3) AND entity = " . $conf->entity . " ORDER BY nom";
$resql = $db->query($sql);
if ($resql) {
    while ($obj = $db->fetch_object($resql)) {
        print '<option value="' . $obj->rowid . '">' . dol_escape_htmltag($obj->nom) . '</option>';
    }
}
print '</select> ';
print '<input type="hidden" name="action" value="add_excluded">';
print '<input type="submit" class="button small" value="' . $langs->trans("Add") . '">';
print '</td></tr>';

print '</table>';
print '</form>';

$_efEntities = array('facture', 'societe', 'product', 'facturedet', 'projet');
foreach ($_efEntities as $_ent) {
	print '<form id="ksef_ef_create_form_' . $_ent . '" method="POST" action="' . $_SERVER["PHP_SELF"] . '">';
	print '<input type="hidden" name="action" value="create_dodatkowy_extrafield">';
	print '<input type="hidden" name="token" value="' . newToken() . '">';
	print '</form>';
	print '<form id="ksef_ef_edit_form_' . $_ent . '" method="POST" action="' . $_SERVER["PHP_SELF"] . '">';
	print '<input type="hidden" name="action" value="edit_dodatkowy_extrafield">';
	print '<input type="hidden" name="token" value="' . newToken() . '">';
	print '</form>';
	print '<form id="ksef_ef_delete_form_' . $_ent . '" method="POST" action="' . $_SERVER["PHP_SELF"] . '">';
	print '<input type="hidden" name="action" value="delete_dodatkowy_extrafield">';
	print '<input type="hidden" name="token" value="' . newToken() . '">';
	print '<input type="hidden" id="ksef_ef_delete_code_' . $_ent . '" name="ef_code" value="">';
	print '<input type="hidden" name="ef_target" value="' . $_ent . '">';
	print '</form>';
}

print dol_get_fiche_end();

llxFooter();
