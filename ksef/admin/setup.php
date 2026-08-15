<?php
/* Copyright (C) 2004-2017 Laurent Destailleur  <eldy@users.sourceforge.net>
 * Copyright (C) 2021      Éric Seigne          <eric.seigne@cap-rel.fr>
 * Copyright (C) 2025-2026      InPoint Automation Sp z o.o.
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
 * \file    admin/setup.php
 * \ingroup ksef
 * \brief   KSEF configuration page
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

global $langs, $user, $conf, $db;

require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/security.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/class/html.form.class.php';
require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
dol_include_once('/ksef/lib/ksef.lib.php');
dol_include_once('/ksef/class/ksef_client.class.php');
dol_include_once('/ksef/class/ksef_latarnia.class.php');

$langs->loadLangs(array("admin", "ksef@ksef"));

if (!$user->admin) {
    accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$backtopage = GETPOST('backtopage', 'alpha');
$error = 0;

// Auto-pull identifiers from company object using configured field mappings
global $mysoc;

if (empty(getDolGlobalString('KSEF_COMPANY_NIP'))) {
    $auto_nip = ksefCleanNIP(ksefGetIdentifierField($mysoc, 'NIP'));
    if (!empty($auto_nip)) {
        dolibarr_set_const($db, 'KSEF_COMPANY_NIP', $auto_nip, 'chaine', 0, '', $conf->entity);
    }
}

if (empty(getDolGlobalString('KSEF_COMPANY_KRS'))) {
    $auto_krs = trim(ksefGetIdentifierField($mysoc, 'KRS'));
    if (!empty($auto_krs)) {
        dolibarr_set_const($db, 'KSEF_COMPANY_KRS', $auto_krs, 'chaine', 0, '', $conf->entity);
    }
}

if (empty(getDolGlobalString('KSEF_COMPANY_REGON'))) {
    $auto_regon = trim(ksefGetIdentifierField($mysoc, 'REGON'));
    if (!empty($auto_regon)) {
        dolibarr_set_const($db, 'KSEF_COMPANY_REGON', $auto_regon, 'chaine', 0, '', $conf->entity);
    }
}

if (empty(getDolGlobalString('KSEF_COMPANY_BDO'))) {
    $auto_bdo = trim(ksefGetIdentifierField($mysoc, 'BDO'));
    if (!empty($auto_bdo)) {
        dolibarr_set_const($db, 'KSEF_COMPANY_BDO', $auto_bdo, 'chaine', 0, '', $conf->entity);
    }
}

if (empty(getDolGlobalString('KSEF_COMPANY_EORI'))) {
    $auto_eori = trim(ksefGetIdentifierField($mysoc, 'EORI'));
    if (!empty($auto_eori)) {
        dolibarr_set_const($db, 'KSEF_COMPANY_EORI', $auto_eori, 'chaine', 0, '', $conf->entity);
    }
}


if ($action == 'apply_trans_overrides' || $action == 'remove_trans_overrides') {
    $langs_selected = array();
    if (GETPOST('trans_lang_en_US', 'alpha')) {
        $langs_selected[] = 'en_US';
    }
    if (GETPOST('trans_lang_pl_PL', 'alpha')) {
        $langs_selected[] = 'pl_PL';
    }

    if (empty($langs_selected)) {
        setEventMessages($langs->trans("KSEF_TransOverridesNoLang"), null, 'errors');
    } else {
        if ($action == 'apply_trans_overrides') {
            $result = ksefApplyTranslationOverrides($db, $langs_selected);
            if ($result > 0) {
                setEventMessages($langs->trans("KSEF_TransOverridesApplied", implode(', ', $langs_selected)), null, 'mesgs');
            } else {
                setEventMessages($langs->trans("KSEF_TransOverridesError"), null, 'errors');
            }
        } else {
            $result = ksefRemoveTranslationOverrides($db, $langs_selected);
            if ($result > 0) {
                setEventMessages($langs->trans("KSEF_TransOverridesRemoved", implode(', ', $langs_selected)), null, 'mesgs');
            } else {
                setEventMessages($langs->trans("KSEF_TransOverridesError"), null, 'errors');
            }
        }
    }

    // Redirect so $langs reloads
    header("Location: " . $_SERVER["PHP_SELF"]);
    exit;
}

if ($action == 'update') {
    // NIP
    $nip = GETPOST("KSEF_COMPANY_NIP", 'alphanohtml');
    $nip = ksefCleanNIP($nip);
    dolibarr_set_const($db, 'KSEF_COMPANY_NIP', $nip, 'chaine', 0, '', $conf->entity);

    // KRS
    $krs = GETPOST("KSEF_COMPANY_KRS", 'alphanohtml');
    dolibarr_set_const($db, 'KSEF_COMPANY_KRS', trim($krs), 'chaine', 0, '', $conf->entity);

    // REGON
    $regon = GETPOST("KSEF_COMPANY_REGON", 'alphanohtml');
    dolibarr_set_const($db, 'KSEF_COMPANY_REGON', trim($regon), 'chaine', 0, '', $conf->entity);

    // BDO
    $bdo = GETPOST("KSEF_COMPANY_BDO", 'alphanohtml');
    dolibarr_set_const($db, 'KSEF_COMPANY_BDO', trim($bdo), 'chaine', 0, '', $conf->entity);

    // EORI
    $eori = GETPOST("KSEF_COMPANY_EORI", 'alphanohtml');
    dolibarr_set_const($db, 'KSEF_COMPANY_EORI', trim($eori), 'chaine', 0, '', $conf->entity);

    // Field Mappings
    $validFields = array('', 'idprof1', 'idprof2', 'idprof3', 'idprof4', 'idprof5', 'idprof6', 'tva_intra');
    $fieldMappings = array();
    foreach (array('NIP', 'KRS', 'REGON', 'BDO', 'EORI') as $ident) {
        $fieldVal = GETPOST('KSEF_FIELD_' . $ident, 'alpha');
        if (in_array($fieldVal, $validFields)) {
            dolibarr_set_const($db, 'KSEF_FIELD_' . $ident, $fieldVal, 'chaine', 0, '', $conf->entity);
            if (!empty($fieldVal)) {
                $fieldMappings[$ident] = $fieldVal;
            }
        }
    }

    // Warn if multiple identifiers use the same source field
    $fieldUsage = array();
    foreach ($fieldMappings as $ident => $fieldVal) {
        $fieldUsage[$fieldVal][] = $ident;
    }
    foreach ($fieldUsage as $fieldVal => $idents) {
        if (count($idents) > 1) {
            setEventMessages($langs->trans("KSEF_WARNING_DUPLICATE_FIELD", implode(', ', $idents), $fieldVal), null, 'warnings');
        }
    }

    // Re-pull identifier values
    foreach (array('NIP' => 'KSEF_COMPANY_NIP', 'KRS' => 'KSEF_COMPANY_KRS', 'REGON' => 'KSEF_COMPANY_REGON', 'BDO' => 'KSEF_COMPANY_BDO', 'EORI' => 'KSEF_COMPANY_EORI') as $ident => $constName) {
        $currentVal = GETPOST($constName, 'alphanohtml');
        if (empty(trim($currentVal)) && isset($fieldMappings[$ident])) {
            $autoVal = ksefGetIdentifierField($mysoc, $ident);
            if ($ident === 'NIP') {
                $autoVal = ksefCleanNIP($autoVal);
            } else {
                $autoVal = trim($autoVal);
            }
            if (!empty($autoVal)) {
                dolibarr_set_const($db, $constName, $autoVal, 'chaine', 0, '', $conf->entity);
            }
        }
    }

    // Environment
    $env = GETPOST('KSEF_ENVIRONMENT', 'alpha');
    if (in_array($env, array('TEST', 'DEMO', 'PRODUCTION'))) {
        dolibarr_set_const($db, 'KSEF_ENVIRONMENT', $env, 'chaine', 0, '', $conf->entity);
    }

    // Warn if new environment has no auth configured
    $newEnv = GETPOST('KSEF_ENVIRONMENT', 'alpha');
    if (!empty($newEnv)) {
        $newEnv = strtoupper($newEnv);
        $hasEnvToken = !empty(getDolGlobalString('KSEF_AUTH_TOKEN_' . $newEnv));
        $hasEnvCert = !empty(getDolGlobalString('KSEF_AUTH_CERTIFICATE_' . $newEnv))
            && !empty(getDolGlobalString('KSEF_AUTH_PRIVATE_KEY_' . $newEnv))
            && !empty(getDolGlobalString('KSEF_AUTH_KEY_PASSWORD_' . $newEnv));
        if (!$hasEnvToken && !$hasEnvCert) {
            $authTabUrl = dol_buildpath('/ksef/admin/setup_auth.php', 1);
            setEventMessages($langs->trans('KSEF_WARNING_ENV_NO_AUTH', $newEnv) . ' <a href="' . $authTabUrl . '">' . $langs->trans('KSEF_Tab_Authentication') . '</a>', null, 'warnings');
        }
    }

    // Button color
    $color = GETPOST('KSEF_BUTTON_COLOR', 'alpha');
    if (!empty($color)) {
        dolibarr_set_const($db, 'KSEF_BUTTON_COLOR', $color, 'chaine', 0, '', $conf->entity);
    }

    // Purge on disable
    $purge_val = GETPOST('KSEF_PURGE_ON_DISABLE', 'alpha') ? '1' : '0';
    dolibarr_set_const($db, 'KSEF_PURGE_ON_DISABLE', $purge_val, 'chaine', 0, '', $conf->entity);

    // NBP Rate Mode
    $nbp_rate_mode = GETPOST('KSEF_NBP_RATE_MODE', 'alpha');
    if (in_array($nbp_rate_mode, array('keep_base', 'keep_foreign'))) {
        dolibarr_set_const($db, 'KSEF_NBP_RATE_MODE', $nbp_rate_mode, 'chaine', 0, '', $conf->entity);
    }

    // NBP cron currency subset
    $nbp_sync_curr = GETPOST('KSEF_NBP_SYNC_CURRENCIES', 'array');
    $nbp_sync_clean = array();
    if (is_array($nbp_sync_curr)) {
        foreach ($nbp_sync_curr as $c) {
            $c = strtoupper(preg_replace('/[^A-Za-z]/', '', $c));
            if ($c !== '') $nbp_sync_clean[] = $c;
        }
    }
    dolibarr_set_const($db, 'KSEF_NBP_SYNC_CURRENCIES', implode(',', $nbp_sync_clean), 'chaine', 0, '', $conf->entity);

    // VAT Rate Code toggles (enable/disable dictionary)
    $_vatPlId = 0;
    $_vatPlSql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "c_country WHERE code = 'PL'";
    $_vatPlRes = $db->query($_vatPlSql);
    if ($_vatPlRes && $_vatPlObj = $db->fetch_object($_vatPlRes)) $_vatPlId = (int) $_vatPlObj->rowid;
    if ($_vatPlId > 0) {
        $_vatAllSql = "SELECT rowid, code, taux, active, entity FROM " . MAIN_DB_PREFIX . "c_tva"
            . " WHERE fk_pays = " . $_vatPlId
            . " AND entity = " . ((int) $conf->entity);
        $_vatAllRes = $db->query($_vatAllSql);
        while ($_vatAllRes && $_vatRow = $db->fetch_object($_vatAllRes)) {
            $fieldName = 'KSEF_VAT_TOGGLE_' . ((int) $_vatRow->rowid);
            $wantEnabled = GETPOST($fieldName, 'alpha') ? 1 : 0;
            if ($wantEnabled != (int) $_vatRow->active) {
                $db->query("UPDATE " . MAIN_DB_PREFIX . "c_tva SET active = " . $wantEnabled . " WHERE rowid = " . ((int) $_vatRow->rowid));
            }
        }
    }

    if (!$error) {
        setEventMessages($langs->trans("SetupSaved"), null, 'mesgs');
    }
    header("Location: " . $_SERVER["PHP_SELF"]);
    exit;
}

// Check lighthouse status
if ($action == 'checklatarnia') {
    $latarnia = new KsefLatarnia($db);
    $result = $latarnia->checkAndCache();
    if ($result !== false) {
        setEventMessages($langs->trans('KSEF_LatarniaCheckSuccess', $langs->trans('KSEF_LATARNIA_' . $result['status'])), null, 'mesgs');
    } else {
        setEventMessages($langs->trans('KSEF_LatarniaCheckError', $latarnia->error), null, 'errors');
    }
}

$activeEnv = getDolGlobalString('KSEF_ENVIRONMENT', 'DEMO');

if ($action == 'testconnection') {
    try {
        $client = new KsefClient($db);
        if ($client->testConnection()) {
            setEventMessages($langs->trans('KSEF_CONNECTION_SUCCESS') . ' [' . $activeEnv . ']', null, 'mesgs');
        } else {
            setEventMessages($langs->trans('KSEF_CONNECTION_FAILED') . ': ' . $client->error, null, 'errors');
        }
    } catch (Exception $e) {
        setEventMessages($langs->trans('KSEF_CONNECTION_ERROR') . ': ' . $e->getMessage(), null, 'errors');
    }
}

if ($action == 'testtokenauth') {
    $envKey = 'KSEF_AUTH_METHOD_' . $activeEnv;
    $orig_method = getDolGlobalString($envKey, 'token');
    $conf->global->$envKey = 'token';

    try {
        $client = new KsefClient($db);
        if ($client->authenticate()) {
            setEventMessages($langs->trans('KSEF_AUTH_SUCCESS') . ' [' . $activeEnv . '] (Token)', null, 'mesgs');
        } else {
            setEventMessages($langs->trans('KSEF_AUTH_FAILED') . ': ' . $client->error, null, 'errors');
        }
    } catch (Exception $e) {
        setEventMessages($langs->trans('KSEF_AUTH_ERROR') . ': ' . $e->getMessage(), null, 'errors');
    }

    $conf->global->$envKey = $orig_method;
}

if ($action == 'testcertauth') {
    $envKey = 'KSEF_AUTH_METHOD_' . $activeEnv;
    $orig_method = getDolGlobalString($envKey, 'token');
    $conf->global->$envKey = 'certificate';

    try {
        $client = new KsefClient($db);
        if ($client->authenticate()) {
            setEventMessages($langs->trans('KSEF_CERT_AUTH_SUCCESS') . ' [' . $activeEnv . ']', null, 'mesgs');
        } else {
            setEventMessages($langs->trans('KSEF_CERT_AUTH_FAILED') . ': ' . $client->error, null, 'errors');
        }
    } catch (Exception $e) {
        setEventMessages($langs->trans('KSEF_CERT_AUTH_ERROR') . ': ' . $e->getMessage(), null, 'errors');
    }
    $conf->global->$envKey = $orig_method;
}

// Run update check
$ksefUpdate = ksefMaybeRunUpdateCheck($db);

$form = new Form($db);
$page_name = "KSEF_Setup";

llxHeader('', $langs->trans($page_name), '');

$linkback = '<a href="' . ($backtopage ?: DOL_URL_ROOT . '/admin/modules.php?restore_lastsearch_values=1') . '">' . $langs->trans("KSEF_BackToModuleList") . '</a>';
print load_fiche_titre($langs->trans($page_name), $linkback, 'title_setup');

$head = ksefAdminPrepareHead();
print dol_get_fiche_head($head, 'general', $langs->trans("KSEF_Module"), -1, 'ksef@ksef');

echo ksefShowAdminBanners();

$warnings = ksefGetConfigWarnings();
if (!empty($warnings)) {
    echo ksefRenderConfigWarnings($warnings, 'general');
}

print '<span>' . $langs->trans("KSEF_SetupPage") . '</span><br><br>';

// Lighthouse status
$latarnia_cached = KsefLatarnia::getCachedStatus();
print '<div style="margin-bottom: 15px; padding: 10px; border: 1px solid #ddd; border-radius: 4px; background: #f8f9fa;">';
print '<strong><i class="fas fa-signal"></i> ' . $langs->trans("KSEF_SystemStatus") . ':</strong> ';
print ksefGetLatarniaStatusBadge($latarnia_cached['status']);
if ($latarnia_cached['timestamp'] > 0) {
    print ' <span style="color: #666; font-size: 0.85em;">(' . $langs->trans("KSEF_LastChecked") . ': ' . dol_print_date($latarnia_cached['timestamp'], 'dayhour') . ')</span>';
} else {
    print ' <span style="color: #666; font-size: 0.85em;">(' . $langs->trans("KSEF_NeverChecked") . ')</span>';
}
if ($latarnia_cached['status'] !== 'AVAILABLE' && $latarnia_cached['status'] !== 'UNKNOWN' && !empty($latarnia_cached['messages'])) {
    $lmsg = $latarnia_cached['messages'][0];
    print '<br><span style="font-size: 0.9em; margin-left: 20px;">' . dol_escape_htmltag($lmsg['title'] ?? '') . '</span>';
}
print '</div>';

print '<form method="POST" action="' . $_SERVER["PHP_SELF"] . '">';
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" name="action" value="update">';

print '<div style="border: 1px solid #bbb; border-radius: 6px; padding: 15px; margin-bottom: 10px;">';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="2">' . $langs->trans("KSEF_BASIC_CONFIG") . '</td></tr>';

// Field mapping options
$fieldOptionsNip = ksefGetFieldOptions(false);
$fieldOptionsOptional = ksefGetFieldOptions(true);

// Company NIP
print '<tr class="oddeven">';
print '<td class="titlefield">' . $form->textwithpicto($langs->trans("KSEF_COMPANY_NIP"), $langs->trans("KSEF_COMPANY_NIP_Help")) . '</td>';
print '<td>';
print '<input type="text" class="flat minwidth200" name="KSEF_COMPANY_NIP" value="' . dol_escape_htmltag(getDolGlobalString('KSEF_COMPANY_NIP', '')) . '" placeholder="1234567890"> ';
print '<span class="small">' . $langs->trans("KSEF_FIELD_SOURCE") . ': </span>';
print $form->selectarray('KSEF_FIELD_NIP', $fieldOptionsNip, getDolGlobalString('KSEF_FIELD_NIP', 'idprof1'), 0, 0, 0, '', 0, 0, 0, '', 'minwidth200 small');
$nipField = getDolGlobalString('KSEF_FIELD_NIP', 'idprof1');
if ($nipField === 'tva_intra') {
    print ' <span class="small">(' . $langs->trans("KSEF_NIP_FROM_VATID_NOTE") . ')</span>';
}
print '</td>';
print '</tr>';

// VAT ID
print '<tr class="oddeven">';
print '<td class="titlefield">' . $form->textwithpicto($langs->trans("KSEF_FIELD_VATID"), $langs->trans("KSEF_FIELD_VATID_Help")) . '</td>';
print '<td><span>' . $langs->trans("VATIntra") . ' (tva_intra) - ' . $langs->trans("KSEF_FIELD_VATID_ALWAYS") . '</span></td>';
print '</tr>';

// KRS
print '<tr class="oddeven">';
print '<td class="titlefield">' . $form->textwithpicto($langs->trans("KSEF_COMPANY_KRS"), $langs->trans("KSEF_COMPANY_KRS_Help")) . '</td>';
print '<td>';
print '<input type="text" class="flat minwidth200" name="KSEF_COMPANY_KRS" value="' . dol_escape_htmltag(getDolGlobalString('KSEF_COMPANY_KRS', '')) . '" placeholder="0000000000">';
print ' <span class="small">(' . $langs->trans("KSEF_Optional") . ')</span> ';
print '<span class="small">' . $langs->trans("KSEF_FIELD_SOURCE") . ': </span>';
print $form->selectarray('KSEF_FIELD_KRS', $fieldOptionsOptional, getDolGlobalString('KSEF_FIELD_KRS', 'idprof2'), 0, 0, 0, '', 0, 0, 0, '', 'minwidth200 small');
print '</td>';
print '</tr>';

// REGON
print '<tr class="oddeven">';
print '<td class="titlefield">' . $form->textwithpicto($langs->trans("KSEF_COMPANY_REGON"), $langs->trans("KSEF_COMPANY_REGON_Help")) . '</td>';
print '<td>';
print '<input type="text" class="flat minwidth200" name="KSEF_COMPANY_REGON" value="' . dol_escape_htmltag(getDolGlobalString('KSEF_COMPANY_REGON', '')) . '" placeholder="000000000">';
print ' <span class="small">(' . $langs->trans("KSEF_Optional") . ')</span> ';
print '<span class="small">' . $langs->trans("KSEF_FIELD_SOURCE") . ': </span>';
print $form->selectarray('KSEF_FIELD_REGON', $fieldOptionsOptional, getDolGlobalString('KSEF_FIELD_REGON', 'idprof3'), 0, 0, 0, '', 0, 0, 0, '', 'minwidth200 small');
print '</td>';
print '</tr>';

// BDO
print '<tr class="oddeven">';
print '<td class="titlefield">' . $form->textwithpicto($langs->trans("KSEF_COMPANY_BDO"), $langs->trans("KSEF_COMPANY_BDO_Help")) . '</td>';
print '<td>';
print '<input type="text" class="flat minwidth200" name="KSEF_COMPANY_BDO" value="' . dol_escape_htmltag(getDolGlobalString('KSEF_COMPANY_BDO', '')) . '" placeholder="000000000">';
print ' <span class="small">(' . $langs->trans("KSEF_Optional") . ')</span> ';
print '<span class="small">' . $langs->trans("KSEF_FIELD_SOURCE") . ': </span>';
print $form->selectarray('KSEF_FIELD_BDO', $fieldOptionsOptional, getDolGlobalString('KSEF_FIELD_BDO', 'idprof4'), 0, 0, 0, '', 0, 0, 0, '', 'minwidth200 small');
print '</td>';
print '</tr>';

// EORI
print '<tr class="oddeven">';
print '<td class="titlefield">' . $form->textwithpicto($langs->trans("KSEF_COMPANY_EORI"), $langs->trans("KSEF_COMPANY_EORI_Help")) . '</td>';
print '<td>';
print '<input type="text" class="flat minwidth200" name="KSEF_COMPANY_EORI" value="' . dol_escape_htmltag(getDolGlobalString('KSEF_COMPANY_EORI', '')) . '" placeholder="PL1234567890ABCDE">';
print ' <span class="small">(' . $langs->trans("KSEF_Optional") . ')</span> ';
print '<span class="small">' . $langs->trans("KSEF_FIELD_SOURCE") . ': </span>';
print $form->selectarray('KSEF_FIELD_EORI', $fieldOptionsOptional, getDolGlobalString('KSEF_FIELD_EORI', 'idprof5'), 0, 0, 0, '', 0, 0, 0, '', 'minwidth200 small');
print '</td>';
print '</tr>';

// Environment
print '<tr class="oddeven">';
print '<td class="titlefield">' . $form->textwithpicto($langs->trans("KSEF_ENVIRONMENT"), $langs->trans("KSEF_ENVIRONMENT_Help")) . '</td>';
print '<td>';
$array_env = array(
    'TEST' => $langs->trans('KSEF_ENV_TEST') . ' (ksef-test.mf.gov.pl)',
    'DEMO' => $langs->trans('KSEF_ENV_DEMO') . ' (ksef-demo.mf.gov.pl)',
    'PRODUCTION' => $langs->trans('KSEF_ENV_PRODUCTION') . ' (ksef.mf.gov.pl)'
);
print $form->selectarray('KSEF_ENVIRONMENT', $array_env, getDolGlobalString('KSEF_ENVIRONMENT', 'DEMO'));

$current_env = getDolGlobalString('KSEF_ENVIRONMENT', 'DEMO');
print '<div id="production_warning" class="warning" style="margin-top: 10px; padding: 8px 12px; display: ' . ($current_env == 'PRODUCTION' ? 'block' : 'none') . ';">';
print '<i class="fa fa-exclamation-triangle"></i> ' . $langs->trans("KSEF_PRODUCTION_WARNING");
print '</div>';

print '<script type="text/javascript">
$(document).ready(function() {
    $("#KSEF_ENVIRONMENT").on("change", function() {
        if ($(this).val() == "PRODUCTION") {
            $("#production_warning").show();
        } else {
            $("#production_warning").hide();
        }
    });
});
</script>';

print '</td></tr>';

print '</table>';

// PDF & UI Settings
print '<br><table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="2">' . $langs->trans("KSEF_UI_CONFIG") . '</td></tr>';

// Button color
print '<tr class="oddeven">';
print '<td class="titlefield">' . $form->textwithpicto($langs->trans("KSEF_BUTTON_COLOR"), $langs->trans("KSEF_BUTTON_COLOR_Help")) . '</td>';
print '<td>';
$array_colors = array(
    '#dc3545' => $langs->trans('KSEF_Red') . ' (Default)',
    '#fd7e14' => $langs->trans('KSEF_Orange'),
    '#ffc107' => $langs->trans('KSEF_Yellow'),
    '#28a745' => $langs->trans('KSEF_Green'),
    '#17a2b8' => $langs->trans('KSEF_Cyan'),
    '#007bff' => $langs->trans('KSEF_Blue'),
    '#6f42c1' => $langs->trans('KSEF_Purple'),
);
print $form->selectarray('KSEF_BUTTON_COLOR', $array_colors, getDolGlobalString('KSEF_BUTTON_COLOR', '#dc3545'));
print '</td></tr>';

// Purge configuration on module disable
print '<tr class="oddeven">';
print '<td class="titlefield">' . $form->textwithpicto($langs->trans('KSEF_PURGE_ON_DISABLE'), $langs->trans('KSEF_PURGE_ON_DISABLE_Help')) . '</td>';
print '<td>';
print '<input type="checkbox" name="KSEF_PURGE_ON_DISABLE" id="KSEF_PURGE_ON_DISABLE" value="1" ' . (getDolGlobalInt('KSEF_PURGE_ON_DISABLE') ? 'checked' : '') . '>';
print ' <label for="KSEF_PURGE_ON_DISABLE">' . $langs->trans("KSEF_Enabled") . '</label>';
print '</td></tr>';

print '</table>';

// VAT Rate Codes section
print '<br><table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="4">' . $langs->trans("KSEF_VAT_DICTIONARY_HELPER") . '</td></tr>';
print '<tr class="oddeven"><td colspan="4">' . $form->textwithpicto($langs->trans('KSEF_VAT_DICTIONARY_HELPER_Desc'), $langs->trans('KSEF_VAT_DICTIONARY_HELPER_Help')) . '</td></tr>';

// Fetch Poland ID
$_vatPolandId = 0;
$_vatSqlPl = "SELECT rowid FROM " . MAIN_DB_PREFIX . "c_country WHERE code = 'PL'";
$_vatResPl = $db->query($_vatSqlPl);
if ($_vatResPl && $_vatObjPl = $db->fetch_object($_vatResPl)) $_vatPolandId = (int) $_vatObjPl->rowid;

// KSeF code metadata (for special 0% codes only)
$ksefVatMeta = array(
    'ZW'  => array('desc' => $langs->trans('KSEF_VAT_CODE_ZW')),
    'RC'  => array('desc' => $langs->trans('KSEF_VAT_CODE_RC')),
    'NP'  => array('desc' => $langs->trans('KSEF_VAT_CODE_NP')),
    'NP2' => array('desc' => $langs->trans('KSEF_VAT_CODE_NP2')),
    'WDT' => array('desc' => $langs->trans('KSEF_VAT_CODE_WDT')),
    'EX'  => array('desc' => $langs->trans('KSEF_VAT_CODE_EX')),
);

// Fetch ALL Polish VAT entries
$_vatAllEntries = array();
if ($_vatPolandId > 0) {
    $_vatSql = "SELECT rowid, code, taux, note, active, entity FROM " . MAIN_DB_PREFIX . "c_tva"
        . " WHERE entity IN (0, " . ((int) $conf->entity) . ")"
        . " AND fk_pays = " . $_vatPolandId
        . " ORDER BY taux DESC, code";
    $_vatRes = $db->query($_vatSql);
    while ($_vatRes && $_vatObj = $db->fetch_object($_vatRes)) {
        $_vatAllEntries[] = $_vatObj;
    }
}

print '<tr class="liste_titre">';
print '<td style="width:28px;">' . $langs->trans('KSEF_Enabled') . '</td>';
print '<td style="width:50px;">' . $langs->trans('Rate') . '</td>';
print '<td style="width:60px;">' . $langs->trans('KSEF_VAT_CODE_COLUMN') . '</td>';
print '<td>' . $langs->trans('KSEF_VAT_CODE_DESC_COLUMN') . '</td>';
print '</tr>';

foreach ($_vatAllEntries as $entry) {
    $codeUpper = strtoupper(trim($entry->code));
    $isKsefCode = isset($ksefVatMeta[$codeUpper]);
    $isGlobal = ((int) $entry->entity === 0);
    $isActive = !empty($entry->active);

    print '<tr class="oddeven">';

    // Checkbox
    if ($isGlobal) {
        print '<td class="center"><input type="checkbox" ' . ($isActive ? 'checked' : '') . ' disabled title="' . dol_escape_htmltag($langs->trans('KSEF_VAT_CODE_GLOBAL')) . '"></td>';
    } else {
        print '<td class="center"><input type="checkbox" name="KSEF_VAT_TOGGLE_' . ((int) $entry->rowid) . '" value="1"' . ($isActive ? ' checked' : '') . '></td>';
    }

    // Rate
    print '<td>' . dol_escape_htmltag($entry->taux) . '%</td>';

    // Code
    $codeDisplay = !empty($entry->code) ? $entry->code : '';
    print '<td><code style="font-size: inherit;">' . dol_escape_htmltag($codeDisplay) . '</code></td>';

    // Description
    if ($isKsefCode) {
        print '<td>' . dol_escape_htmltag($ksefVatMeta[$codeUpper]['desc']) . '</td>';
    } else {
        print '<td>' . dol_escape_htmltag($entry->note ?: '') . '</td>';
    }

    print '</tr>';
}

print '<tr class="oddeven"><td colspan="4">';
$dictUrl = DOL_URL_ROOT . '/admin/dict.php?id=10';
print '<a href="' . $dictUrl . '" target="_blank" class="small">' . $langs->trans('KSEF_VAT_CODE_DICT_LINK') . '</a>';
print '</td></tr>';
print '</table>';

// Multicurrency Settings
print '<br><table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="2">' . $langs->trans("KSEF_MULTICURRENCY_CONFIG") . '</td></tr>';
print '<tr class="oddeven">';
print '<td class="titlefield">' . $form->textwithpicto($langs->trans('KSEF_NBP_RATE_MODE'), $langs->trans('KSEF_NBP_RATE_MODE_Help')) . '</td>';
print '<td>';
$nbp_modes = array(
    'keep_base' => $langs->trans('KSEF_NBP_RATE_MODE_KEEP_BASE'),
    'keep_foreign' => $langs->trans('KSEF_NBP_RATE_MODE_KEEP_FOREIGN'),
);
$current_mode = getDolGlobalString('KSEF_NBP_RATE_MODE', 'keep_base');
print $form->selectarray('KSEF_NBP_RATE_MODE', $nbp_modes, $current_mode, 0, 0, 0, '', 0, 0, 0, '', 'minwidth300');
print '</td></tr>';

// NBP cron currency subset
print '<tr class="oddeven">';
print '<td class="titlefield">' . $form->textwithpicto($langs->trans('KSEF_NBP_SYNC_CURRENCIES'), $langs->trans('KSEF_NBP_SYNC_CURRENCIES_Help')) . '</td>';
print '<td>';
$nbp_curr_dict = array();
$nbp_dict_sql = "SELECT code FROM " . MAIN_DB_PREFIX . "multicurrency WHERE entity = " . ((int) $conf->entity) . " ORDER BY code";
$nbp_dict_res = $db->query($nbp_dict_sql);
if ($nbp_dict_res) {
    while ($nbp_dict_obj = $db->fetch_object($nbp_dict_res)) {
        $nbp_dict_code = strtoupper(trim($nbp_dict_obj->code));
        if ($nbp_dict_code !== '' && $nbp_dict_code !== 'PLN') $nbp_curr_dict[$nbp_dict_code] = $nbp_dict_code;
    }
}
$nbp_curr_selected = array();
$nbp_curr_raw = getDolGlobalString('KSEF_NBP_SYNC_CURRENCIES', '');
if ($nbp_curr_raw !== '') $nbp_curr_selected = array_filter(array_map('trim', explode(',', $nbp_curr_raw)));
print $form->multiselectarray('KSEF_NBP_SYNC_CURRENCIES', $nbp_curr_dict, $nbp_curr_selected, 0, 0, 'minwidth300', 0, '0', '', '', $langs->trans('KSEF_NBP_SYNC_CURRENCIES_All'));
print '</td></tr>';

print '</table>';

print '</div>';

print '<div class="center" style="margin-top: 10px;">';
print '<input type="submit" class="button button-save" value="' . $langs->trans("Save") . '">';
print '</div>';
print '</form>';

// Translation Overrides
print '<br><table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="4">' . $langs->trans("KSEF_TranslationOverrides") . '</td></tr>';

print '<tr class="oddeven">';
print '<td colspan="4">';
print '<span>' . $langs->trans("KSEF_TransOverridesDesc") . '</span>';
print '</td>';
print '</tr>';

// status table
$previewOverrides = ksefGetManagedTranslationOverrides();
$currentOverrides = ksefGetCurrentTranslationOverrides($db);

// current overrides
$currentByLangKey = array();
foreach ($currentOverrides as $ov) {
    $currentByLangKey[$ov['lang']][$ov['transkey']] = $ov['transvalue'];
}

$fieldGroups = array();
foreach ($previewOverrides as $tk => $tv) {
    if (preg_match('/Short/', $tk)) {
        continue; // skip Short variants, they share the same value
    }
    $fieldGroups[$tk] = $tv;
}

if (!empty($fieldGroups)) {
    print '<tr class="liste_titre">';
    print '<td>' . $langs->trans("KSEF_TransOverridesField") . '</td>';
    print '<td>' . $langs->trans("KSEF_TransOverridesLabel") . '</td>';
    print '<td class="center">en_US</td>';
    print '<td class="center">pl_PL</td>';
    print '</tr>';

    foreach ($fieldGroups as $field => $label) {
        print '<tr class="oddeven">';
        print '<td>' . dol_escape_htmltag($field) . '</td>';
        print '<td><strong>' . dol_escape_htmltag($label) . '</strong></td>';

        foreach (array('en_US', 'pl_PL') as $lang) {
            $applied = isset($currentByLangKey[$lang][$field]) && $currentByLangKey[$lang][$field] === $label;
            if ($applied) {
                print '<td class="center"><span class="badge badge-status4 badge-status">✓</span></td>';
            } else {
                print '<td class="center"><span class="badge badge-status8 badge-status">-</span></td>';
            }
        }
        print '</tr>';
    }
} else {
    print '<tr class="oddeven"><td colspan="4"><span>' . $langs->trans("None") . '</span></td></tr>';
}

print '<tr class="oddeven">';
print '<td colspan="4">';
print '<form method="POST" action="' . $_SERVER["PHP_SELF"] . '" style="display: inline;">';
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" name="action" value="apply_trans_overrides">';
print '<label style="margin-right: 15px;"><input type="checkbox" name="trans_lang_en_US" value="1" checked> en_US</label>';
print '<label style="margin-right: 15px;"><input type="checkbox" name="trans_lang_pl_PL" value="1" checked> pl_PL</label>';
print '<input type="submit" class="button small" value="' . $langs->trans("KSEF_ApplyTransOverrides") . '">';
print '</form>';
print '<span style="margin-left: 20px;">';
print '<a href="' . $_SERVER["PHP_SELF"] . '?action=remove_trans_overrides&trans_lang_en_US=1&trans_lang_pl_PL=1&token=' . newToken() . '" class="opacitymedium" style="font-size: 0.85em;" onclick="return confirm(\'' . dol_escape_js($langs->trans("KSEF_ConfirmRemoveTransOverrides")) . '\');">';
print '<i class="fas fa-eraser"></i> ' . $langs->trans("KSEF_RemoveTransOverrides");
print '</a></span>';
print '</td>';
print '</tr>';

print '</table>';

print '<br>';
print ksefRenderSupportBox('', $ksefUpdate);

print dol_get_fiche_end();

$has_active_token = !empty(getDolGlobalString('KSEF_AUTH_TOKEN_' . $activeEnv));
$has_active_cert = !empty(getDolGlobalString('KSEF_AUTH_CERTIFICATE_' . $activeEnv))
    && !empty(getDolGlobalString('KSEF_AUTH_PRIVATE_KEY_' . $activeEnv))
    && !empty(getDolGlobalString('KSEF_AUTH_KEY_PASSWORD_' . $activeEnv));

print '<br><div class="tabsAction">';

if (!empty(getDolGlobalString('KSEF_COMPANY_NIP'))) {
    print '<a class="butAction" href="' . $_SERVER["PHP_SELF"] . '?action=testconnection&token=' . newToken() . '">' . $langs->trans("KSEF_TEST_CONNECTION") . '</a>';

    if ($has_active_token) {
        print '<a class="butAction" href="' . $_SERVER["PHP_SELF"] . '?action=testtokenauth&token=' . newToken() . '">' . $langs->trans("KSEF_TEST_TOKEN_AUTH") . '</a>';
    }

    if ($has_active_cert) {
        print '<a class="butAction" href="' . $_SERVER["PHP_SELF"] . '?action=testcertauth&token=' . newToken() . '">' . $langs->trans("KSEF_TEST_CERT_AUTH") . '</a>';
    }
}

print '<a class="butAction" href="' . $_SERVER["PHP_SELF"] . '?action=checklatarnia&token=' . newToken() . '">' . $langs->trans("KSEF_CheckLatarnia") . '</a>';
print '</div>';

llxFooter();
$db->close();
