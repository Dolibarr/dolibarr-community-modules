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
 * \file    admin/setup_auth.php
 * \ingroup ksef
 * \brief   KSEF authentication settings tab
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
require_once DOL_DOCUMENT_ROOT . '/core/lib/security.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/class/html.form.class.php';
dol_include_once('/ksef/lib/ksef.lib.php');
dol_include_once('/ksef/class/ksef_client.class.php');

if (!$user->admin) {
    accessforbidden();
}

$langs->loadLangs(array("admin", "ksef@ksef"));

$action = GETPOST('action', 'aZ09');
$activeEnv = getDolGlobalString('KSEF_ENVIRONMENT', 'TEST');
$allowedEnvs = array('TEST', 'DEMO', 'PRODUCTION');

$env = strtoupper(GETPOST('env', 'alpha'));
if (!in_array($env, $allowedEnvs)) {
    $env = $activeEnv;
}

if ($action == 'update_env') {
    $newEnv = strtoupper(GETPOST('KSEF_ENVIRONMENT', 'alpha'));
    if (in_array($newEnv, $allowedEnvs)) {
        dolibarr_set_const($db, 'KSEF_ENVIRONMENT', $newEnv, 'chaine', 0, '', $conf->entity);

        // Warn if new environment has no auth configured
        $hasEnvToken = !empty(getDolGlobalString('KSEF_AUTH_TOKEN_' . $newEnv));
        $hasEnvCert = !empty(getDolGlobalString('KSEF_AUTH_CERTIFICATE_' . $newEnv))
            && !empty(getDolGlobalString('KSEF_AUTH_PRIVATE_KEY_' . $newEnv))
            && !empty(getDolGlobalString('KSEF_AUTH_KEY_PASSWORD_' . $newEnv));
        if (!$hasEnvToken && !$hasEnvCert) {
            setEventMessages($langs->trans('KSEF_WARNING_ENV_NO_AUTH', $newEnv), null, 'warnings');
        }

        setEventMessages($langs->trans("SetupSaved"), null, 'mesgs');
    }
    header("Location: " . $_SERVER["PHP_SELF"]);
    exit;
}

if ($action == 'update') {
    $error = 0;

    // Auth method
    $auth_method = GETPOST('KSEF_AUTH_METHOD', 'alpha');
    if (in_array($auth_method, array('token', 'certificate'))) {
        dolibarr_set_const($db, 'KSEF_AUTH_METHOD_' . $env, $auth_method, 'chaine', 0, '', $conf->entity);
    }

    // Token
    $new_token = GETPOST('KSEF_AUTH_TOKEN', 'password');
    if (!empty($new_token)) {
        $new_token = trim($new_token);
        if (strlen($new_token) < 50) {
            setEventMessages($langs->trans("KSEF_TOKEN_TOO_SHORT"), null, 'errors');
            $error++;
        } elseif (preg_match('/[\r\n\t]/', $new_token)) {
            setEventMessages($langs->trans("KSEF_TOKEN_INVALID_FORMAT"), null, 'errors');
            $error++;
        } else {
            $encrypted = dol_encode($new_token);
            dolibarr_set_const($db, 'KSEF_AUTH_TOKEN_' . $env, $encrypted, 'chaine', 0, '', $conf->entity);
            dolibarr_set_const($db, 'KSEF_TOKEN_UPDATED_AT_' . $env, dol_now(), 'chaine', 0, '', $conf->entity);
            setEventMessages($langs->trans("KSEF_TOKEN_SAVED"), null, 'mesgs');

            // Auto-select token method if no certificate
            $has_cert_now = !empty(getDolGlobalString('KSEF_AUTH_CERTIFICATE_' . $env))
                && !empty(getDolGlobalString('KSEF_AUTH_PRIVATE_KEY_' . $env))
                && !empty(getDolGlobalString('KSEF_AUTH_KEY_PASSWORD_' . $env));
            if (!$has_cert_now) {
                dolibarr_set_const($db, 'KSEF_AUTH_METHOD_' . $env, 'token', 'chaine', 0, '', $conf->entity);
            }
        }
    }

    if (!$error) {
        setEventMessages($langs->trans("SetupSaved"), null, 'mesgs');
    }
    header("Location: " . $_SERVER["PHP_SELF"]);
    exit;
}

if ($action == 'upload_auth_cert') {
    $error_count = 0;

    // Certificate
    if (!empty($_FILES['auth_cert_file']['tmp_name'])) {
        $cert_content = file_get_contents($_FILES['auth_cert_file']['tmp_name']);
        if ($cert_content !== false) {
            $cert_content = trim($cert_content);
            $cert_resource = openssl_x509_read($cert_content);
            if ($cert_resource) {
                $cert_info = openssl_x509_parse($cert_resource);
                if ($cert_info) {
                    $serial = $cert_info['serialNumberHex'] ?? $cert_info['serialNumber'] ?? '';
                    $valid_from = $cert_info['validFrom_time_t'] ?? null;
                    $valid_to = $cert_info['validTo_time_t'] ?? null;

                    dolibarr_set_const($db, 'KSEF_AUTH_CERTIFICATE_' . $env, base64_encode($cert_content), 'chaine', 0, '', $conf->entity);
                    dolibarr_set_const($db, 'KSEF_AUTH_CERT_SERIAL_' . $env, $serial, 'chaine', 0, '', $conf->entity);
                    if ($valid_from) dolibarr_set_const($db, 'KSEF_AUTH_CERT_VALID_FROM_' . $env, $valid_from, 'chaine', 0, '', $conf->entity);
                    if ($valid_to) dolibarr_set_const($db, 'KSEF_AUTH_CERT_VALID_TO_' . $env, $valid_to, 'chaine', 0, '', $conf->entity);
                    setEventMessages($langs->trans("KSEF_AuthCertificateSaved"), null, 'mesgs');
                } else {
                    setEventMessages($langs->trans("KSEF_CertificateParseError"), null, 'errors');
                    $error_count++;
                }
            } else {
                setEventMessages($langs->trans("KSEF_CertificateInvalid") . ': ' . openssl_error_string(), null, 'errors');
                $error_count++;
            }
        }
    }

    // Private key
    if (!empty($_FILES['auth_key_file']['tmp_name'])) {
        $key_content = file_get_contents($_FILES['auth_key_file']['tmp_name']);
        if ($key_content !== false) {
            dolibarr_set_const($db, 'KSEF_AUTH_PRIVATE_KEY_' . $env, base64_encode(trim($key_content)), 'chaine', 0, '', $conf->entity);
            setEventMessages($langs->trans("KSEF_AuthPrivateKeySaved"), null, 'mesgs');
        }
    }

    // Password
    $key_password = GETPOST('auth_key_password', 'none');
    if (!empty($key_password)) {
        dolibarr_set_const($db, 'KSEF_AUTH_KEY_PASSWORD_' . $env, dol_encode($key_password), 'chaine', 0, '', $conf->entity);

        // Validate key with password
        $stored_key = getDolGlobalString('KSEF_AUTH_PRIVATE_KEY_' . $env, '');
        if (!empty($stored_key)) {
            $key_pem = base64_decode($stored_key);
            $pk = openssl_pkey_get_private($key_pem, $key_password);
            if (!$pk) {
                setEventMessages($langs->trans("KSEF_PrivateKeyPasswordMismatch"), null, 'warnings');
            } else {
                setEventMessages($langs->trans("KSEF_PrivateKeyValidated"), null, 'mesgs');
            }
        }
    }

    // Auto-select certificate method if no token
    if (!$error_count) {
        $has_token_now = !empty(getDolGlobalString('KSEF_AUTH_TOKEN_' . $env));
        $current_method = getDolGlobalString('KSEF_AUTH_METHOD_' . $env, 'token');
        if (!$has_token_now && $current_method != 'certificate') {
            dolibarr_set_const($db, 'KSEF_AUTH_METHOD_' . $env, 'certificate', 'chaine', 0, '', $conf->entity);
            setEventMessages($langs->trans("KSEF_AuthMethodAutoSwitchedToCert"), null, 'mesgs');
        }
    }

    header("Location: " . $_SERVER["PHP_SELF"]);
    exit;
}

if ($action == 'upload_offline_cert') {
    $error_count = 0;

    // Certificate file
    if (!empty($_FILES['offline_cert_file']['tmp_name'])) {
        $cert_content = file_get_contents($_FILES['offline_cert_file']['tmp_name']);
        if ($cert_content !== false) {
            $cert_content = trim($cert_content);
            $cert_resource = openssl_x509_read($cert_content);
            if ($cert_resource) {
                $cert_info = openssl_x509_parse($cert_resource);
                if ($cert_info) {
                    $serial = $cert_info['serialNumberHex'] ?? $cert_info['serialNumber'] ?? '';
                    $valid_from = $cert_info['validFrom_time_t'] ?? null;
                    $valid_to = $cert_info['validTo_time_t'] ?? null;

                    dolibarr_set_const($db, 'KSEF_OFFLINE_CERTIFICATE_' . $env, base64_encode($cert_content), 'chaine', 0, '', $conf->entity);
                    dolibarr_set_const($db, 'KSEF_OFFLINE_CERT_SERIAL_' . $env, $serial, 'chaine', 0, '', $conf->entity);
                    if ($valid_from) dolibarr_set_const($db, 'KSEF_OFFLINE_CERT_VALID_FROM_' . $env, $valid_from, 'chaine', 0, '', $conf->entity);
                    if ($valid_to) dolibarr_set_const($db, 'KSEF_OFFLINE_CERT_VALID_TO_' . $env, $valid_to, 'chaine', 0, '', $conf->entity);
                    setEventMessages($langs->trans("KSEF_OfflineCertificateSaved"), null, 'mesgs');
                } else {
                    setEventMessages($langs->trans("KSEF_CertificateParseError"), null, 'errors');
                    $error_count++;
                }
            } else {
                setEventMessages($langs->trans("KSEF_CertificateInvalid") . ': ' . openssl_error_string(), null, 'errors');
                $error_count++;
            }
        }
    }

    // Private key file
    if (!empty($_FILES['offline_key_file']['tmp_name'])) {
        $key_content = file_get_contents($_FILES['offline_key_file']['tmp_name']);
        if ($key_content !== false) {
            dolibarr_set_const($db, 'KSEF_OFFLINE_PRIVATE_KEY_' . $env, base64_encode(trim($key_content)), 'chaine', 0, '', $conf->entity);
            setEventMessages($langs->trans("KSEF_OfflinePrivateKeySaved"), null, 'mesgs');
        }
    }

    // Password
    $key_password = GETPOST('offline_key_password', 'none');
    if (!empty($key_password)) {
        dolibarr_set_const($db, 'KSEF_OFFLINE_KEY_PASSWORD_' . $env, dol_encode($key_password), 'chaine', 0, '', $conf->entity);

        // Validate key with password
        $stored_key = getDolGlobalString('KSEF_OFFLINE_PRIVATE_KEY_' . $env, '');
        if (!empty($stored_key)) {
            $key_pem = base64_decode($stored_key);
            $pk = openssl_pkey_get_private($key_pem, $key_password);
            if (!$pk) {
                setEventMessages($langs->trans("KSEF_PrivateKeyPasswordMismatch"), null, 'warnings');
            } else {
                setEventMessages($langs->trans("KSEF_PrivateKeyValidated"), null, 'mesgs');
            }
        }
    }

    header("Location: " . $_SERVER["PHP_SELF"]);
    exit;
}

if ($action == 'remove_auth_cert') {
    dolibarr_del_const($db, 'KSEF_AUTH_CERTIFICATE_' . $env, $conf->entity);
    dolibarr_del_const($db, 'KSEF_AUTH_PRIVATE_KEY_' . $env, $conf->entity);
    dolibarr_del_const($db, 'KSEF_AUTH_KEY_PASSWORD_' . $env, $conf->entity);
    dolibarr_del_const($db, 'KSEF_AUTH_CERT_SERIAL_' . $env, $conf->entity);
    dolibarr_del_const($db, 'KSEF_AUTH_CERT_VALID_FROM_' . $env, $conf->entity);
    dolibarr_del_const($db, 'KSEF_AUTH_CERT_VALID_TO_' . $env, $conf->entity);

    if (getDolGlobalString('KSEF_AUTH_METHOD_' . $env) == 'certificate') {
        dolibarr_set_const($db, 'KSEF_AUTH_METHOD_' . $env, 'token', 'chaine', 0, '', $conf->entity);
    }

    setEventMessages($langs->trans("KSEF_AuthCertificateRemoved"), null, 'mesgs');
    header("Location: " . $_SERVER["PHP_SELF"]);
    exit;
}

if ($action == 'remove_offline_cert') {
    dolibarr_del_const($db, 'KSEF_OFFLINE_CERTIFICATE_' . $env, $conf->entity);
    dolibarr_del_const($db, 'KSEF_OFFLINE_PRIVATE_KEY_' . $env, $conf->entity);
    dolibarr_del_const($db, 'KSEF_OFFLINE_KEY_PASSWORD_' . $env, $conf->entity);
    dolibarr_del_const($db, 'KSEF_OFFLINE_CERT_SERIAL_' . $env, $conf->entity);
    dolibarr_del_const($db, 'KSEF_OFFLINE_CERT_VALID_FROM_' . $env, $conf->entity);
    dolibarr_del_const($db, 'KSEF_OFFLINE_CERT_VALID_TO_' . $env, $conf->entity);

    setEventMessages($langs->trans("KSEF_OfflineCertificateRemoved"), null, 'mesgs');
    header("Location: " . $_SERVER["PHP_SELF"]);
    exit;
}

if ($action == 'testconnection') {
    $testEnv = strtoupper(GETPOST('testenv', 'alpha'));
    if (!in_array($testEnv, $allowedEnvs)) $testEnv = $activeEnv;

    $orig_env = getDolGlobalString('KSEF_ENVIRONMENT', 'DEMO');
    $conf->global->KSEF_ENVIRONMENT = $testEnv;

    try {
        $client = new KsefClient($db);
        if ($client->testConnection()) {
            setEventMessages($langs->trans('KSEF_CONNECTION_SUCCESS') . ' [' . $testEnv . ']', null, 'mesgs');
        } else {
            setEventMessages($langs->trans('KSEF_CONNECTION_FAILED') . ': ' . $client->error, null, 'errors');
        }
    } catch (Exception $e) {
        setEventMessages($langs->trans('KSEF_CONNECTION_ERROR') . ': ' . $e->getMessage(), null, 'errors');
    }

    $conf->global->KSEF_ENVIRONMENT = $orig_env;
}

if ($action == 'testtokenauth') {
    $testEnv = strtoupper(GETPOST('testenv', 'alpha'));
    if (!in_array($testEnv, $allowedEnvs)) $testEnv = $activeEnv;

    $orig_env = getDolGlobalString('KSEF_ENVIRONMENT', 'DEMO');
    $conf->global->KSEF_ENVIRONMENT = $testEnv;

    $envKey = 'KSEF_AUTH_METHOD_' . $testEnv;
    $orig_method = getDolGlobalString($envKey, 'token');
    $conf->global->$envKey = 'token';

    try {
        $client = new KsefClient($db);
        if ($client->authenticate()) {
            setEventMessages($langs->trans('KSEF_AUTH_SUCCESS') . ' [' . $testEnv . '] (Token)', null, 'mesgs');
        } else {
            setEventMessages($langs->trans('KSEF_AUTH_FAILED') . ': ' . $client->error, null, 'errors');
        }
    } catch (Exception $e) {
        setEventMessages($langs->trans('KSEF_AUTH_ERROR') . ': ' . $e->getMessage(), null, 'errors');
    }

    $conf->global->$envKey = $orig_method;
    $conf->global->KSEF_ENVIRONMENT = $orig_env;
}

if ($action == 'testcertauth') {
    $testEnv = strtoupper(GETPOST('testenv', 'alpha'));
    if (!in_array($testEnv, $allowedEnvs)) $testEnv = $activeEnv;

    $orig_env = getDolGlobalString('KSEF_ENVIRONMENT', 'DEMO');
    $conf->global->KSEF_ENVIRONMENT = $testEnv;

    $envKey = 'KSEF_AUTH_METHOD_' . $testEnv;
    $orig_method = getDolGlobalString($envKey, 'token');
    $conf->global->$envKey = 'certificate';

    try {
        $client = new KsefClient($db);
        if ($client->authenticate()) {
            setEventMessages($langs->trans('KSEF_CERT_AUTH_SUCCESS') . ' [' . $testEnv . ']', null, 'mesgs');
        } else {
            setEventMessages($langs->trans('KSEF_CERT_AUTH_FAILED') . ': ' . $client->error, null, 'errors');
        }
    } catch (Exception $e) {
        setEventMessages($langs->trans('KSEF_CERT_AUTH_ERROR') . ': ' . $e->getMessage(), null, 'errors');
    }
    $conf->global->$envKey = $orig_method;
    $conf->global->KSEF_ENVIRONMENT = $orig_env;
}

if ($action == 'update_gus') {
    $gusEnabled = GETPOST('KSEF_GUS_ENABLED', 'int') ? '1' : '0';

    $gusEnv = GETPOST('KSEF_GUS_ENV', 'alpha');
    if (!in_array($gusEnv, array('TEST', 'PROD'))) {
        $gusEnv = 'TEST';
    }

    // Require field labels applied
    if ($gusEnabled === '1' && !ksefTranslationOverridesReady($db)) {
        $gusEnabled = '0';
        setEventMessages($langs->trans("KSEF_GusSetupRequiredBanner"), null, 'warnings');
    }

    $gusNameStyle = GETPOST('KSEF_GUS_NAME_STYLE', 'alpha');
    if (!in_array($gusNameStyle, array('full', 'upper', 'mixed'))) {
        $gusNameStyle = 'full';
    }

    dolibarr_set_const($db, 'KSEF_GUS_ENABLED', $gusEnabled, 'chaine', 0, '', $conf->entity);
    dolibarr_set_const($db, 'KSEF_GUS_ENV', $gusEnv, 'chaine', 0, '', $conf->entity);
    dolibarr_set_const($db, 'KSEF_GUS_NAME_STYLE', $gusNameStyle, 'chaine', 0, '', $conf->entity);

    $gusKey = GETPOST('KSEF_GUS_KEY', 'password');
    if ($gusKey !== '') {
        dolibarr_set_const($db, 'KSEF_GUS_KEY', dol_encode(trim($gusKey)), 'chaine', 0, '', $conf->entity);
    }

    setEventMessages($langs->trans("SetupSaved"), null, 'mesgs');
    header("Location: " . $_SERVER["PHP_SELF"]);
    exit;
}

if ($action == 'testgusconnection') {
    dol_include_once('/ksef/class/ksef_gus_client.class.php');
    try {
        $gusClient = new KsefGusClient($db, getDolGlobalString('KSEF_GUS_ENV', 'TEST'));
        if ($gusClient->login()) {
            $gusClient->logout();
            setEventMessages($langs->trans("KSEF_GusTestOk"), null, 'mesgs');
        } else {
            setEventMessages($langs->trans("KSEF_GusTestFail", ($gusClient->error ? $gusClient->error : 'error')), null, 'errors');
        }
    } catch (Exception $e) {
        setEventMessages($langs->trans("KSEF_GusTestFail", $e->getMessage()), null, 'errors');
    }
}

/*
 * View
 */

$form = new Form($db);
$page_name = "KSEF_Setup";

llxHeader('', $langs->trans($page_name), '');

$linkback = '<a href="' . DOL_URL_ROOT . '/admin/modules.php?restore_lastsearch_values=1">' . $langs->trans("KSEF_BackToModuleList") . '</a>';
print load_fiche_titre($langs->trans($page_name), $linkback, 'title_setup');

$head = ksefAdminPrepareHead();
print dol_get_fiche_head($head, 'auth', $langs->trans("KSEF_Module"), -1, 'ksef@ksef');

// Reactivation + update banners
echo ksefShowAdminBanners();

// Config warnings
$warnings = ksefGetConfigWarnings();
if (!empty($warnings)) {
    echo ksefRenderConfigWarnings($warnings, 'auth');
}

$gusReady = ksefTranslationOverridesReady($db);
if (!$gusReady) {
    print '<div class="warning" style="margin-bottom: 15px;">' . img_warning() . ' ' . $langs->trans("KSEF_GusSetupRequiredBanner");
    print ' <a href="' . dol_buildpath('/ksef/admin/setup.php', 1) . '">' . $langs->trans("KSEF_GusRunSetup") . '</a>';
    print '</div>';
}

// GUS / REGON lookup
$gusEnabled = getDolGlobalString('KSEF_GUS_ENABLED');
$gusEnv = getDolGlobalString('KSEF_GUS_ENV', 'TEST');
$gusHasKey = !empty(getDolGlobalString('KSEF_GUS_KEY'));
$gusNameStyle = getDolGlobalString('KSEF_GUS_NAME_STYLE', 'full');
$gusOpen = ($gusEnabled || !$gusReady);

print '<div style="border: 1px solid #dee2e6; border-radius: 6px; margin-bottom: 15px; overflow: hidden;">';
print '<div onclick="ksefToggleEnv(\'GUS\')" style="cursor: pointer; padding: 12px 15px; background: #f8f9fa; display: flex; justify-content: space-between; align-items: center;">';
print '<div>';
print '<i id="ksef_env_toggle_GUS" class="fa fa-chevron-' . ($gusOpen ? 'down' : 'right') . '" style="margin-right: 8px;"></i>';
print '<strong>' . $langs->trans('KSEF_GusSectionTitle') . '</strong>';
if ($gusEnabled) {
    print ' <span class="badge badge-status4 badge-status">' . $langs->trans('Enabled') . '</span>';
} else {
    print ' <span class="badge badge-status8 badge-status">' . $langs->trans('Disabled') . '</span>';
}
print '</div>';
print '</div>';

print '<div id="ksef_env_body_GUS" style="padding: 15px; ' . ($gusOpen ? '' : 'display: none;') . '">';

print '<div style="margin-bottom: 10px;"><span class="opacitymedium">' . $langs->trans("KSEF_GusSectionDesc") . '</span></div>';

if (!$gusHasKey) {
    print '<div style="padding: 8px 12px; background: #eef4ff; border: 1px solid #cddffb; border-radius: 4px; margin-bottom: 10px;">';
    print '<i class="fa fa-info-circle"></i> ' . $langs->trans("KSEF_GusNoKeyBanner");
    print '</div>';

    print '<div style="border: 1px solid #dee2e6; border-radius: 6px; margin-bottom: 15px; overflow: hidden;">';
    print '<div onclick="ksefToggleEnv(\'GUSKEY\')" style="cursor: pointer; padding: 10px 12px; background: #f8f9fa; display: flex; align-items: center;">';
    print '<i id="ksef_env_toggle_GUSKEY" class="fa fa-chevron-right" style="margin-right: 8px;"></i>';
    print '<strong>' . $langs->trans("KSEF_GusKeyHelpTitle") . '</strong>';
    print '</div>';
    print '<div id="ksef_env_body_GUSKEY" style="padding: 15px; display: none;">';
    print '<ol><li>' . $langs->trans("KSEF_GusKeyApplyStep1") . '</li><li>' . $langs->trans("KSEF_GusKeyApplyStep2") . '</li><li>' . $langs->trans("KSEF_GusKeyApplyStep3") . '</li><li>' . $langs->trans("KSEF_GusKeyApplyStep4") . '</li></ol>';
    print '<p><strong>' . $langs->trans("KSEF_GusHowTo_EmailLabel") . '</strong></p>';
    print '<pre style="background: #f5f5f5; border: 1px solid #ddd; padding: 10px; white-space: pre-wrap;">' . htmlspecialchars(ksefGusRequestEmail()) . '</pre>';
    print '</div>';
    print '</div>';
}

print '<form method="POST" action="' . $_SERVER["PHP_SELF"] . '">';
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" name="action" value="update_gus">';
print '<table class="noborder centpercent">';

print '<tr class="oddeven"><td class="titlefield">' . $langs->trans("KSEF_GusEnabled") . '</td><td>';
print '<input type="checkbox" name="KSEF_GUS_ENABLED" value="1"' . ($gusEnabled ? ' checked' : '') . ($gusReady ? '' : ' disabled') . '>';
print '</td></tr>';

print '<tr class="oddeven"><td>' . $langs->trans("KSEF_GusEnv") . '</td><td>';
print '<select name="KSEF_GUS_ENV" class="flat">';
print '<option value="TEST"' . ($gusEnv === 'TEST' ? ' selected' : '') . '>' . $langs->trans("KSEF_GusEnvTest") . '</option>';
print '<option value="PROD"' . ($gusEnv === 'PROD' ? ' selected' : '') . '>' . $langs->trans("KSEF_GusEnvProd") . '</option>';
print '</select>';
print '</td></tr>';

print '<tr class="oddeven"><td>' . $langs->trans("KSEF_GusApiKey") . '</td><td>';
print '<input type="password" name="KSEF_GUS_KEY" autocomplete="new-password" value="" placeholder="' . ($gusHasKey ? '********' : '') . '" size="30">';
print '<br><span class="opacitymedium">' . $langs->trans("KSEF_GusApiKeyHelp") . '</span>';
print '</td></tr>';

print '<tr class="oddeven"><td>' . $langs->trans("KSEF_GusNameStyle") . '</td><td>';
print '<select name="KSEF_GUS_NAME_STYLE" class="flat">';
print '<option value="full"' . ($gusNameStyle === 'full' ? ' selected' : '') . '>' . $langs->trans("KSEF_GusNameStyleFull") . '</option>';
print '<option value="upper"' . ($gusNameStyle === 'upper' ? ' selected' : '') . '>' . $langs->trans("KSEF_GusNameStyleUpper") . '</option>';
print '<option value="mixed"' . ($gusNameStyle === 'mixed' ? ' selected' : '') . '>' . $langs->trans("KSEF_GusNameStyleMixed") . '</option>';
print '</select>';
print '</td></tr>';

print '<tr class="oddeven"><td colspan="2">';
print '<input type="submit" class="button" value="' . $langs->trans("Save") . '">';
if ($gusEnabled) {
    print ' <a class="butAction" href="' . $_SERVER["PHP_SELF"] . '?action=testgusconnection&token=' . newToken() . '">' . $langs->trans("KSEF_GusTestConnection") . '</a>';
}
print '</td></tr>';

print '</table>';
print '</form>';
print '</div>';
print '</div>';

// Environment selector
print '<div style="margin-bottom: 15px;">';
print '<span>' . $langs->trans("KSEF_AuthPageInfo") . '</span>';
print '</div>';

print '<form method="POST" action="' . $_SERVER["PHP_SELF"] . '" style="margin-bottom: 15px;">';
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" name="action" value="update_env">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="2">' . $langs->trans("KSEF_ENVIRONMENT") . '</td></tr>';
print '<tr class="oddeven">';
print '<td>' . $form->textwithpicto($langs->trans("KSEF_ENVIRONMENT"), $langs->trans("KSEF_ENVIRONMENT_Help")) . '</td>';
print '<td>';
$array_env = array(
    'TEST' => $langs->trans('KSEF_ENV_TEST') . ' (ksef-test.mf.gov.pl)',
    'DEMO' => $langs->trans('KSEF_ENV_DEMO') . ' (ksef-demo.mf.gov.pl)',
    'PRODUCTION' => $langs->trans('KSEF_ENV_PRODUCTION') . ' (ksef.mf.gov.pl)'
);
print $form->selectarray('KSEF_ENVIRONMENT', $array_env, $activeEnv);
print ' <input type="submit" class="button small" value="' . $langs->trans("Modify") . '">';

print '<div id="auth_production_warning" class="warning" style="margin-top: 10px; padding: 8px 12px; display: ' . ($activeEnv == 'PRODUCTION' ? 'block' : 'none') . ';">';
print '<i class="fa fa-exclamation-triangle"></i> ' . $langs->trans("KSEF_PRODUCTION_WARNING");
print '</div>';

print '<script type="text/javascript">
$(document).ready(function() {
    $("#KSEF_ENVIRONMENT").on("change", function() {
        if ($(this).val() == "PRODUCTION") {
            $("#auth_production_warning").show();
        } else {
            $("#auth_production_warning").hide();
        }
    });
});
</script>';

print '</td></tr>';
print '</table>';
print '</form>';

// Per-environment auth sections
$environments = array('TEST', 'DEMO', 'PRODUCTION');

foreach ($environments as $envName) {
    $isActive = ($envName === $activeEnv);

    // Read status for this environment
    $has_token = !empty(getDolGlobalString('KSEF_AUTH_TOKEN_' . $envName));
    $has_auth_cert = !empty(getDolGlobalString('KSEF_AUTH_CERTIFICATE_' . $envName))
        && !empty(getDolGlobalString('KSEF_AUTH_PRIVATE_KEY_' . $envName))
        && !empty(getDolGlobalString('KSEF_AUTH_KEY_PASSWORD_' . $envName));
    $current_auth_method = getDolGlobalString('KSEF_AUTH_METHOD_' . $envName, '');
    $no_auth_configured = (!$has_token && !$has_auth_cert);

    $certCheck = ksefCheckAuthCertificate($envName);
    $offlineCheck = ksefIsOfflineCertificateConfigured($envName);

    // Status badges
    $tokenBadge = $has_token
        ? '<span class="badge badge-status4 badge-status">' . $langs->trans('KSEF_EnvStatusToken') . ' <i class="fa fa-check-circle"></i></span>'
        : '<span class="badge badge-status8 badge-status">' . $langs->trans('KSEF_EnvStatusToken') . ' <i class="fa fa-times-circle"></i></span>';

    if ($has_auth_cert) {
        $certBadge = ($certCheck['configured'] && !$certCheck['valid'])
            ? '<span class="badge badge-status1 badge-status">' . $langs->trans('KSEF_EnvStatusCert') . ' <i class="fa fa-times-circle"></i> ' . $langs->trans("KSEF_Expired") . '</span>'
            : '<span class="badge badge-status4 badge-status">' . $langs->trans('KSEF_EnvStatusCert') . ' <i class="fa fa-check-circle"></i></span>';
    } else {
        $certBadge = '<span class="badge badge-status8 badge-status">' . $langs->trans('KSEF_EnvStatusCert') . ' <i class="fa fa-times-circle"></i></span>';
    }

    $has_offline_cert_summary = !empty(getDolGlobalString('KSEF_OFFLINE_CERTIFICATE_' . $envName))
        && !empty(getDolGlobalString('KSEF_OFFLINE_PRIVATE_KEY_' . $envName))
        && !empty(getDolGlobalString('KSEF_OFFLINE_KEY_PASSWORD_' . $envName));
    $offlineBadge = $has_offline_cert_summary
        ? '<span class="badge badge-status4 badge-status">' . $langs->trans('KSEF_EnvStatusOffline') . ' <i class="fa fa-check-circle"></i></span>'
        : '<span class="badge badge-status8 badge-status">' . $langs->trans('KSEF_EnvStatusOffline') . ' <i class="fa fa-times-circle"></i></span>';

    $methodBadge = '';
    if (!empty($current_auth_method)) {
        $methodBadge = '<span class="badge badge-status6 badge-status">' . $langs->trans('KSEF_EnvStatusMethod') . ': ' . dol_escape_htmltag($current_auth_method) . '</span>';
    }

    $borderColor = $isActive ? '#28a745' : '#dee2e6';
    $borderWidth = $isActive ? '3px' : '1px';
    print '<div style="border: ' . $borderWidth . ' solid ' . $borderColor . '; border-radius: 6px; margin-bottom: 15px; overflow: hidden;">';
    print '<div onclick="ksefToggleEnv(\'' . $envName . '\')" style="cursor: pointer; padding: 12px 15px; background: ' . ($isActive ? '#f0fff0' : '#f8f9fa') . '; display: flex; justify-content: space-between; align-items: center;">';
    print '<div>';
    print '<i id="ksef_env_toggle_' . $envName . '" class="fa fa-chevron-' . ($isActive ? 'down' : 'right') . '" style="margin-right: 8px;"></i>';
    print '<strong>' . $langs->trans('KSEF_EnvSection_' . $envName) . '</strong>';
    if ($isActive) {
        print ' <span class="badge badge-status4 badge-status">' . $langs->trans('KSEF_EnvSection_Active') . '</span>';
    }
    print '</div>';
    print '</div>';

    print '<div style="padding: 8px 15px; background: ' . ($isActive ? '#f8fff8' : '#fdfdfd') . '; border-top: 1px solid #eee; display: flex; gap: 8px; flex-wrap: wrap; align-items: center;">';
    print $tokenBadge . ' ' . $certBadge . ' ' . $offlineBadge;
    if (!empty($methodBadge)) {
        print ' ' . $methodBadge;
    }
    if ($no_auth_configured) {
        print ' <span class="badge badge-status1 badge-status"><i class="fa fa-exclamation-triangle"></i> ' . $langs->trans("KSEF_NO_AUTH_CONFIGURED") . '</span>';
    }
    print '</div>';

    print '<div id="ksef_env_body_' . $envName . '" style="padding: 15px; ' . ($isActive ? '' : 'display: none;') . '">';

    print '<form method="POST" action="' . $_SERVER["PHP_SELF"] . '">';
    print '<input type="hidden" name="token" value="' . newToken() . '">';
    print '<input type="hidden" name="action" value="update">';
    print '<input type="hidden" name="env" value="' . $envName . '">';

    // Auth Method
    print '<table class="noborder centpercent">';
    print '<tr class="liste_titre"><td colspan="2">' . $langs->trans("KSEF_AUTH_METHOD_SELECT") . '</td></tr>';
    print '<tr class="oddeven"><td colspan="2">';
    print '<div style="display: flex; gap: 20px; padding: 10px 0; flex-wrap: wrap;">';

    if ($no_auth_configured) {
        print '<div style="flex: 1; min-width: 250px; max-width: 300px; padding: 15px; border: 2px solid #dc3545; border-radius: 8px; background: #fff5f5;">';
        print '<div style="display: flex; align-items: center; margin-bottom: 10px;">';
        print '<span style="width: 40px; height: 40px; background: #dc354520; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; margin-right: 10px;">';
        print '<i class="fa fa-exclamation-triangle" style="color: #dc3545; font-size: 18px;"></i>';
        print '</span>';
        print '<strong style="color: #dc3545;">' . $langs->trans("KSEF_NO_AUTH_CONFIGURED") . '</strong>';
        print '</div>';
        print '<div style="padding-left: 50px;">';
        print '<span style="font-size: 12px;">' . $langs->trans("KSEF_ConfigureAuthBelow") . '</span>';
        print '</div></div>';
    }

    // Token card
    $token_selected = ($current_auth_method == 'token' && $has_token)
        || (empty($current_auth_method) && $has_token);
    $token_border_color = $token_selected ? '#28a745' : ($has_token ? '#17a2b8' : '#dee2e6');
    $token_bg_color = $token_selected ? '#f8fff8' : '#fff';

    print '<div style="flex: 1; min-width: 250px; padding: 15px; border: 2px solid ' . $token_border_color . '; border-radius: 8px; background: ' . $token_bg_color . ';">';
    print '<label style="display: flex; align-items: center; cursor: pointer; margin-bottom: 10px;">';
    print '<input type="radio" name="KSEF_AUTH_METHOD" value="token" ' . ($token_selected ? 'checked' : '') . ' ' . (!$has_token ? 'disabled' : '') . ' style="margin-right: 10px;">';
    print '<strong>' . $langs->trans("KSEF_AUTH_METHOD_TOKEN") . '</strong>';
    if ($token_selected && $has_token) {
        print ' <span class="badge badge-success" style="margin-left: 10px;">' . $langs->trans("KSEF_Active") . '</span>';
    }
    print '</label>';
    print '<div style="margin-left: 24px;">';
    if ($has_token) {
        print '<span class="badge badge-status4"><i class="fa fa-check-circle"></i> ' . $langs->trans("KSEF_TokenConfigured") . '</span>';
        $token_updated = getDolGlobalString('KSEF_TOKEN_UPDATED_AT_' . $envName, '');
        if (!empty($token_updated)) {
            print '<br><small>' . $langs->trans("KSEF_Updated") . ': ' . dol_print_date($token_updated, 'dayhour') . '</small>';
        }
    } else {
        print '<span class="badge badge-warning"><i class="fa fa-exclamation-triangle"></i> ' . $langs->trans("KSEF_TokenNotConfigured") . '</span>';
        print '<br><small>' . $langs->trans("KSEF_ConfigureTokenBelow") . '</small>';
    }
    print '</div></div>';

    // Certificate card
    $cert_selected = ($current_auth_method == 'certificate' && $has_auth_cert)
        || (empty($current_auth_method) && $has_auth_cert && !$has_token);
    $cert_border_color = $cert_selected ? '#28a745' : ($has_auth_cert ? '#17a2b8' : '#dee2e6');
    $cert_bg_color = $cert_selected ? '#f8fff8' : '#fff';

    print '<div style="flex: 1; min-width: 250px; padding: 15px; border: 2px solid ' . $cert_border_color . '; border-radius: 8px; background: ' . $cert_bg_color . ';">';
    print '<label style="display: flex; align-items: center; cursor: pointer; margin-bottom: 10px;">';
    print '<input type="radio" name="KSEF_AUTH_METHOD" value="certificate" ' . ($cert_selected ? 'checked' : '') . ' ' . (!$has_auth_cert ? 'disabled title="' . $langs->trans("KSEF_ConfigureCertificateFirst") . '"' : '') . ' style="margin-right: 10px;">';
    print '<strong>' . $langs->trans("KSEF_AUTH_METHOD_CERTIFICATE") . '</strong>';
    if ($cert_selected && $has_auth_cert) {
        print ' <span class="badge badge-success" style="margin-left: 10px;">' . $langs->trans("KSEF_Active") . '</span>';
    }
    print '</label>';
    print '<div style="margin-left: 24px;">';
    if ($has_auth_cert) {
        $cert_valid_to = getDolGlobalString('KSEF_AUTH_CERT_VALID_TO_' . $envName, '');
        $is_expired = (!empty($cert_valid_to) && $cert_valid_to < dol_now());
        if ($is_expired) {
            print '<span class="badge badge-danger"><i class="fa fa-times-circle"></i> ' . $langs->trans("KSEF_CertificateExpired") . '</span>';
        } else {
            print '<span class="badge badge-status4"><i class="fa fa-check-circle"></i> ' . $langs->trans("KSEF_CertificateConfigured") . '</span>';
        }
        if (!empty($cert_valid_to)) {
            print '<br><small>' . $langs->trans("KSEF_ValidUntil") . ': ' . dol_print_date($cert_valid_to, 'day') . '</small>';
        }
    } else {
        print '<span class="badge badge-secondary"><i class="fa fa-certificate"></i> ' . $langs->trans("KSEF_CertificateNotConfigured") . '</span>';
        print '<br><small>' . $langs->trans("KSEF_ConfigureCertificateBelow") . '</small>';
    }
    print '</div></div>';

    print '</div>';
    print '</td></tr>';
    print '</table>';

    // Token input
    print '<br><table class="noborder centpercent">';
    print '<tr class="liste_titre"><td colspan="2">' . $langs->trans("KSEF_TOKEN_CONFIG") . '</td></tr>';
    print '<tr class="oddeven">';
    print '<td>' . $form->textwithpicto($langs->trans("KSEF_AUTH_TOKEN"), $langs->trans("KSEF_AUTH_TOKEN_Help")) . '</td>';
    print '<td>';
    if ($has_token) {
        print '<div style="margin-bottom: 10px;">';
        print '<span class="badge badge-status4"><i class="fa fa-check-circle"></i> ' . $langs->trans("KSEF_TokenConfigured") . '</span>';
        print '</div>';
        print '<strong>' . $langs->trans("KSEF_UpdateToken") . ':</strong><br>';
    }
    print '<input type="password" class="flat minwidth400" name="KSEF_AUTH_TOKEN" value="" placeholder="' . ($has_token ? $langs->trans("KSEF_EnterNewTokenToReplace") : $langs->trans("KSEF_PasteTokenHere")) . '" autocomplete="new-password">';
    print '<br><span>' . $langs->trans("KSEF_TOKEN_OBTAIN_INFO") . '</span>';
    print '</td></tr>';
    print '</table>';

    print '<div class="center" style="margin-top: 10px;">';
    print '<input type="submit" class="button button-save" value="' . $langs->trans("Save") . '">';
    print '</div>';
    print '</form>';
    print '<div style="display: flex; flex-wrap: wrap; gap: 20px; align-items: flex-start; margin-top: 20px;">';

    // Auth certificate
    print '<div style="flex: 1; min-width: 450px;">';
    print '<form method="POST" action="' . $_SERVER["PHP_SELF"] . '" enctype="multipart/form-data">';
    print '<input type="hidden" name="token" value="' . newToken() . '">';
    print '<input type="hidden" name="action" value="upload_auth_cert">';
    print '<input type="hidden" name="env" value="' . $envName . '">';

    print '<table class="noborder centpercent">';
    print '<tr class="liste_titre"><td colspan="2">' . $langs->trans("KSEF_AUTH_CERTIFICATE_CONFIG") . ' <small>(' . $langs->trans("KSEF_ForAPIAuthentication") . ')</small></td></tr>';
    print '<tr class="oddeven"><td colspan="2">';

    if ($has_auth_cert) {
        $cert_valid_to = getDolGlobalString('KSEF_AUTH_CERT_VALID_TO_' . $envName, '');
        $is_expired = (!empty($cert_valid_to) && $cert_valid_to < dol_now());

        print '<div style="padding: 15px; border-radius: 4px; margin-bottom: 15px; ';
        print 'background-color: ' . ($is_expired ? '#f8d7da' : '#d4edda') . '; ';
        print 'border: 1px solid ' . ($is_expired ? '#f5c6cb' : '#c3e6cb') . ';">';

        print '<table class="nobordernopadding">';
        print '<tr><td><i class="fa fa-check text-success"></i></td><td>' . $langs->trans("KSEF_Certificate") . ' (.crt)</td></tr>';
        print '<tr><td><i class="fa fa-' . (!empty(getDolGlobalString('KSEF_AUTH_PRIVATE_KEY_' . $envName)) ? 'check text-success' : 'times text-danger') . '"></i></td><td>' . $langs->trans("KSEF_PrivateKey") . ' (.key)</td></tr>';
        print '<tr><td><i class="fa fa-' . (!empty(getDolGlobalString('KSEF_AUTH_KEY_PASSWORD_' . $envName)) ? 'check text-success' : 'times text-danger') . '"></i></td><td>' . $langs->trans("KSEF_Password") . '</td></tr>';
        print '</table>';

        $cert_serial = getDolGlobalString('KSEF_AUTH_CERT_SERIAL_' . $envName, '');
        if (!empty($cert_serial)) {
            print '<br><strong>' . $langs->trans("KSEF_CertSerial") . ':</strong> ' . dol_escape_htmltag(substr($cert_serial, 0, 24)) . '...';
        }
        if (!empty($cert_valid_to)) {
            print '<br><strong>' . $langs->trans("KSEF_ValidUntil") . ':</strong> ' . dol_print_date($cert_valid_to, 'day');
            if ($is_expired) print ' <span class="badge badge-danger">' . $langs->trans("Expired") . '</span>';
        }

        print '<br><br><a class="button button-cancel small" href="' . $_SERVER["PHP_SELF"] . '?action=remove_auth_cert&env=' . $envName . '&token=' . newToken() . '" onclick="return confirm(\'' . dol_escape_js($langs->trans("KSEF_ConfirmRemoveAuthCert")) . '\');">';
        print '<i class="fa fa-trash"></i> ' . $langs->trans("KSEF_RemoveAll") . '</a>';
        print '</div>';
    }

    print '<div style="background: #f8f9fa; padding: 15px; border-radius: 4px;">';
    print '<div style="margin-bottom: 15px;">';
    print '<label><strong>' . $langs->trans("KSEF_CertificateFile") . ' (.crt):</strong></label><br>';
    print '<input type="file" name="auth_cert_file" accept=".crt,.pem,.cer" class="flat">';
    print '</div>';
    print '<div style="margin-bottom: 15px;">';
    print '<label><strong>' . $langs->trans("KSEF_PrivateKeyFile") . ' (.key):</strong></label><br>';
    print '<input type="file" name="auth_key_file" accept=".key,.pem" class="flat">';
    print '</div>';
    print '<div style="margin-bottom: 15px;">';
    print '<label><strong>' . $langs->trans("KSEF_PrivateKeyPassword") . ':</strong></label><br>';
    print '<input type="password" name="auth_key_password" class="flat minwidth200" placeholder="' . $langs->trans("KSEF_EnterPassword") . '" autocomplete="new-password">';
    print '</div>';
    print '<input type="submit" class="button" value="' . $langs->trans("KSEF_SaveAuthCertificate") . '">';
    print '</div>';

    print '</td></tr>';
    print '</table>';
    print '</form>';
    print '</div>';

    // Offline certificate
    print '<div style="flex: 1; min-width: 450px;">';

    $has_offline_cert = !empty(getDolGlobalString('KSEF_OFFLINE_CERTIFICATE_' . $envName))
        && !empty(getDolGlobalString('KSEF_OFFLINE_PRIVATE_KEY_' . $envName))
        && !empty(getDolGlobalString('KSEF_OFFLINE_KEY_PASSWORD_' . $envName));

    print '<form method="POST" action="' . $_SERVER["PHP_SELF"] . '" enctype="multipart/form-data">';
    print '<input type="hidden" name="token" value="' . newToken() . '">';
    print '<input type="hidden" name="action" value="upload_offline_cert">';
    print '<input type="hidden" name="env" value="' . $envName . '">';

    print '<table class="noborder centpercent">';
    print '<tr class="liste_titre"><td colspan="2">' . $langs->trans("KSEF_OFFLINE_CERTIFICATE_CONFIG") . ' <small>(' . $langs->trans("KSEF_ForQRCodeSigning") . ')</small></td></tr>';
    print '<tr class="oddeven"><td colspan="2">';

    if ($has_offline_cert) {
        $cert_valid_to = getDolGlobalString('KSEF_OFFLINE_CERT_VALID_TO_' . $envName, '');
        $is_expired = (!empty($cert_valid_to) && $cert_valid_to < dol_now());

        print '<div style="padding: 15px; border-radius: 4px; margin-bottom: 15px; ';
        print 'background-color: ' . ($is_expired ? '#f8d7da' : '#d4edda') . '; ';
        print 'border: 1px solid ' . ($is_expired ? '#f5c6cb' : '#c3e6cb') . ';">';

        print '<table class="nobordernopadding">';
        print '<tr><td><i class="fa fa-check text-success"></i></td><td>' . $langs->trans("KSEF_Certificate") . ' (.crt)</td></tr>';
        print '<tr><td><i class="fa fa-' . (!empty(getDolGlobalString('KSEF_OFFLINE_PRIVATE_KEY_' . $envName)) ? 'check text-success' : 'times text-danger') . '"></i></td><td>' . $langs->trans("KSEF_PrivateKey") . ' (.key)</td></tr>';
        print '<tr><td><i class="fa fa-' . (!empty(getDolGlobalString('KSEF_OFFLINE_KEY_PASSWORD_' . $envName)) ? 'check text-success' : 'times text-danger') . '"></i></td><td>' . $langs->trans("KSEF_Password") . '</td></tr>';
        print '</table>';

        $cert_serial = getDolGlobalString('KSEF_OFFLINE_CERT_SERIAL_' . $envName, '');
        if (!empty($cert_serial)) {
            print '<br><strong>' . $langs->trans("KSEF_CertSerial") . ':</strong> ' . dol_escape_htmltag(substr($cert_serial, 0, 24)) . '...';
        }
        if (!empty($cert_valid_to)) {
            print '<br><strong>' . $langs->trans("KSEF_ValidUntil") . ':</strong> ' . dol_print_date($cert_valid_to, 'day');
            if ($is_expired) print ' <span class="badge badge-danger">' . $langs->trans("Expired") . '</span>';
        }

        print '<br><br><a class="button button-cancel small" href="' . $_SERVER["PHP_SELF"] . '?action=remove_offline_cert&env=' . $envName . '&token=' . newToken() . '" onclick="return confirm(\'' . dol_escape_js($langs->trans("KSEF_ConfirmRemoveOfflineCert")) . '\');">';
        print '<i class="fa fa-trash"></i> ' . $langs->trans("KSEF_RemoveAll") . '</a>';
        print '</div>';
    }

    print '<div style="background: #f8f9fa; padding: 15px; border-radius: 4px;">';
    print '<div style="margin-bottom: 15px;">';
    print '<label><strong>' . $langs->trans("KSEF_CertificateFile") . ' (.crt):</strong></label><br>';
    print '<input type="file" name="offline_cert_file" accept=".crt,.pem,.cer" class="flat">';
    print '</div>';
    print '<div style="margin-bottom: 15px;">';
    print '<label><strong>' . $langs->trans("KSEF_PrivateKeyFile") . ' (.key):</strong></label><br>';
    print '<input type="file" name="offline_key_file" accept=".key,.pem" class="flat">';
    print '</div>';
    print '<div style="margin-bottom: 15px;">';
    print '<label><strong>' . $langs->trans("KSEF_PrivateKeyPassword") . ':</strong></label><br>';
    print '<input type="password" name="offline_key_password" class="flat minwidth200" placeholder="' . $langs->trans("KSEF_EnterPassword") . '" autocomplete="new-password">';
    print '</div>';
    print '<input type="submit" class="button" value="' . $langs->trans("KSEF_SaveOfflineCertificate") . '">';
    print '</div>';

    print '</td></tr>';
    print '</table>';
    print '</form>';
    print '</div>';

    print '</div>';

    if (!empty(getDolGlobalString('KSEF_COMPANY_NIP'))) {
        print '<div class="tabsAction" style="margin-top: 15px; padding-top: 10px; border-top: 1px solid #ddd;">';
        print '<a class="butAction" href="' . $_SERVER["PHP_SELF"] . '?action=testconnection&testenv=' . $envName . '&token=' . newToken() . '">' . $langs->trans("KSEF_TEST_CONNECTION") . '</a>';
        if ($has_token) {
            print '<a class="butAction" href="' . $_SERVER["PHP_SELF"] . '?action=testtokenauth&testenv=' . $envName . '&token=' . newToken() . '">' . $langs->trans("KSEF_TEST_TOKEN_AUTH") . '</a>';
        }
        if ($has_auth_cert) {
            print '<a class="butAction" href="' . $_SERVER["PHP_SELF"] . '?action=testcertauth&testenv=' . $envName . '&token=' . newToken() . '">' . $langs->trans("KSEF_TEST_CERT_AUTH") . '</a>';
        }
        print '</div>';
    }

    print '</div>';
    print '</div>';
}

print dol_get_fiche_end();

print '<script>
function ksefToggleEnv(envName) {
    var body = document.getElementById("ksef_env_body_" + envName);
    var toggle = document.getElementById("ksef_env_toggle_" + envName);
    if (body.style.display === "none") {
        body.style.display = "block";
        toggle.className = "fa fa-chevron-down";
    } else {
        body.style.display = "none";
        toggle.className = "fa fa-chevron-right";
    }
}
document.querySelectorAll("form").forEach(function(f) {
    f.addEventListener("change", function() { f.dataset.dirty = "1"; });
    f.addEventListener("submit", function() { f.dataset.dirty = ""; });
});
window.addEventListener("beforeunload", function(e) {
    if (document.querySelector("form[data-dirty=\'1\']")) { e.preventDefault(); }
});
</script>';

llxFooter();
