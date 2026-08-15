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
 * \file    ksef/admin/howtouse.php
 * \ingroup ksef
 * \brief   KSEF How-To Page
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
dol_include_once('/ksef/lib/ksef.lib.php');

if (!$user->admin) {
    accessforbidden();
}

$langs->loadLangs(array("ksef@ksef", "admin"));

$action = GETPOST('action', 'aZ09');
$backtopage = GETPOST('backtopage', 'alpha');

$page_name = "KSEF_HowToUse";

// Get current environment
$current_env = !empty($conf->global->KSEF_ENVIRONMENT) ? $conf->global->KSEF_ENVIRONMENT : 'DEMO';

if ($action == 'testconnection') {
    dol_include_once('/ksef/class/ksef_client.class.php');

    $environment = $current_env;

    try {
        $client = new KsefClient($db, $environment);
        dol_syslog("Testing KSeF connection to $environment environment", LOG_INFO);

        if ($client->testConnection()) {
            setEventMessages(
                    $langs->trans('KSEF_CONNECTION_SUCCESS') . ' [' . $environment . ']',
                    null,
                    'mesgs'
            );
        } else {
            setEventMessages(
                    $langs->trans('KSEF_CONNECTION_FAILED') . ': ' . $client->error,
                    null,
                    'errors'
            );
        }
    } catch (Exception $e) {
        dol_syslog("Connection test exception: " . $e->getMessage(), LOG_ERR);
        setEventMessages(
                $langs->trans('KSEF_CONNECTION_ERROR') . ': ' . $e->getMessage(),
                null,
                'errors'
        );
    }

    header("Location: " . $_SERVER["PHP_SELF"]);
    exit;
}

if ($action == 'testcertauth') {
    dol_include_once('/ksef/class/ksef_client.class.php');

    $environment = $current_env;

    $has_auth_cert = !empty(getDolGlobalString('KSEF_AUTH_CERTIFICATE_' . $current_env)) &&
            !empty(getDolGlobalString('KSEF_AUTH_PRIVATE_KEY_' . $current_env)) &&
            !empty(getDolGlobalString('KSEF_AUTH_KEY_PASSWORD_' . $current_env));

    if (!$has_auth_cert) {
        setEventMessages($langs->trans('KSEF_CertificateNotConfigured'), null, 'errors');
        header("Location: " . $_SERVER["PHP_SELF"]);
        exit;
    }

    $envKey = 'KSEF_AUTH_METHOD_' . $current_env;
    $orig_method = getDolGlobalString($envKey, 'token');
    $conf->global->$envKey = 'certificate';

    try {
        $client = new KsefClient($db, $environment);

        if ($client->authenticate()) {
            setEventMessages(
                    $langs->trans('KSEF_CERT_AUTH_SUCCESS') . ' [' . $environment . ']',
                    null,
                    'mesgs'
            );
        } else {
            setEventMessages(
                    $langs->trans('KSEF_CERT_AUTH_FAILED') . ': ' . $client->error,
                    null,
                    'errors'
            );
        }
    } catch (Exception $e) {
        setEventMessages(
                $langs->trans('KSEF_CERT_AUTH_ERROR') . ': ' . $e->getMessage(),
                null,
                'errors'
        );
    }

    $conf->global->$envKey = $orig_method;

    header("Location: " . $_SERVER["PHP_SELF"]);
    exit;
}

if ($action == 'testtokenauth') {
    dol_include_once('/ksef/class/ksef_client.class.php');

    $environment = $current_env;

    $envKey = 'KSEF_AUTH_METHOD_' . $current_env;
    $orig_method = getDolGlobalString($envKey, 'token');
    $conf->global->$envKey = 'token';

    try {
        dol_syslog("Testing KSeF token authentication with $environment environment", LOG_INFO);
        $client = new KsefClient($db, $environment);

        if (!$client->authenticate()) {
            setEventMessages(
                    $langs->trans('KSEF_AUTH_FAILED') . ': ' . $client->error,
                    null,
                    'errors'
            );
        } else {
            setEventMessages(
                    $langs->trans('KSEF_AUTH_SUCCESS') . ' [' . $environment . ']',
                    null,
                    'mesgs'
            );
        }
    } catch (Exception $e) {
        dol_syslog("Authentication test exception: " . $e->getMessage(), LOG_ERR);
        setEventMessages(
                $langs->trans('KSEF_AUTH_ERROR') . ': ' . $e->getMessage(),
                null,
                'errors'
        );
    }

    $conf->global->$envKey = $orig_method;

    header("Location: " . $_SERVER["PHP_SELF"]);
    exit;
}

$form = new Form($db);

llxHeader('', $langs->trans($page_name));

$linkback = '<a href="' . DOL_URL_ROOT . '/admin/modules.php?restore_lastsearch_values=1' . '">' . $langs->trans("KSEF_BackToModuleList") . '</a>';
print load_fiche_titre($langs->trans($page_name), $linkback, 'title_setup');

$head = ksefAdminPrepareHead();
print dol_get_fiche_head($head, 'howtouse', $langs->trans("KSEF_Module"), -1, 'ksef@ksef');

$has_token = !empty(getDolGlobalString('KSEF_AUTH_TOKEN_' . $current_env));
$has_auth_cert = !empty(getDolGlobalString('KSEF_AUTH_CERTIFICATE_' . $current_env)) &&
        !empty(getDolGlobalString('KSEF_AUTH_PRIVATE_KEY_' . $current_env)) &&
        !empty(getDolGlobalString('KSEF_AUTH_KEY_PASSWORD_' . $current_env));

?>

    <table class="noborder centpercent">
        <tr class="liste_titre">
            <th><span class="fas fa-bolt paddingright"></span><?php echo $langs->trans("KSEF_QuickStartGuide"); ?></th>
        </tr>
        <tr class="oddeven">
            <td>
                <div style="display: flex; gap: 20px; flex-wrap: wrap;">
                    <div style="flex: 1; min-width: 220px; padding: 15px; background: #f8f9fa; border-left: 4px solid #dc3545;">
                        <h4 style="margin-top: 0;">1. <?php echo $langs->trans("KSEF_GetCredentials"); ?></h4>
                        <p><?php echo $langs->trans("KSEF_GetCredentialsDesc"); ?></p>
                        <p><strong><?php echo $langs->trans("KSEF_Portals"); ?>:</strong></p>
                        <?php
                        $portals = array(
                                'PRODUCTION' => array('url' => 'https://ap.ksef.mf.gov.pl/web/', 'label' => $langs->trans('KSEF_ENV_PRODUCTION')),
                                'DEMO' => array('url' => 'https://ap-demo.ksef.mf.gov.pl/web/', 'label' => $langs->trans('KSEF_ENV_DEMO')),
                                'TEST' => array('url' => 'https://ap-test.ksef.mf.gov.pl/web/', 'label' => $langs->trans('KSEF_ENV_TEST')),
                        );
                        foreach ($portals as $env => $portal) {
                            $is_active = ($env === $current_env);
                            $style = $is_active ? 'font-weight: bold;' : 'opacity: 0.5;';
                            $icon = $is_active ? '<span class="fas fa-check-circle" style="color: #28a745;"></span> ' : '';
                            print '<div style="' . $style . ' margin-bottom: 4px;">';
                            print $icon . '<a href="' . $portal['url'] . '" target="_blank">' . $portal['label'] . '</a>';
                            if ($is_active) {
                                print ' <small>(' . $langs->trans("KSEF_CurrentEnvironment") . ')</small>';
                            }
                            print '</div>';
                        }
                        ?>

                        <div style="margin-top: 12px; padding: 10px; background: #fff3cd; border: 1px solid #ffc107; border-radius: 4px;">
                            <span class="fas fa-exclamation-triangle" style="color: #856404;"></span>
                            <strong><?php echo $langs->trans("KSEF_Important"); ?>:</strong>
                            <?php echo $langs->trans("KSEF_PermissionsWarning"); ?>
                        </div>
                    </div>

                    <div style="flex: 1; min-width: 220px; padding: 15px; background: #f8f9fa; border-left: 4px solid #fd7e14;">
                        <h4 style="margin-top: 0;">2. <?php echo $langs->trans("KSEF_ConfigureModule"); ?></h4>
                        <p><?php echo $langs->trans("KSEF_ConfigureModuleDesc"); ?></p>
                        <p><?php echo $langs->trans("KSEF_DefaultEnvironmentNote"); ?></p>
                        <p><a href="<?php echo dol_buildpath('/ksef/admin/setup_auth.php', 1); ?>" class="button small">
                                <span class="fas fa-cog paddingright"></span><?php echo $langs->trans("KSEF_GoToSetup"); ?>
                            </a></p>
                    </div>

                    <div style="flex: 1; min-width: 220px; padding: 15px; background: #f8f9fa; border-left: 4px solid #ffc107;">
                        <h4 style="margin-top: 0;">3. <?php echo $langs->trans("KSEF_TestConnection"); ?></h4>
                        <p><?php echo $langs->trans("KSEF_TestConnectionDesc"); ?></p>

                        <a href="<?php echo $_SERVER["PHP_SELF"]; ?>?action=testconnection&token=<?php echo newToken(); ?>"
                           class="button small" style="margin-bottom: 8px; display: block;">
                            <span class="fas fa-plug paddingright"></span><?php echo $langs->trans("KSEF_TEST_CONNECTION"); ?>
                        </a>

                        <?php if ($has_token) { ?>
                            <a href="<?php echo $_SERVER["PHP_SELF"]; ?>?action=testtokenauth&token=<?php echo newToken(); ?>"
                               class="button small" style="margin-bottom: 8px; display: block;">
                                <span class="fas fa-key paddingright"></span><?php echo $langs->trans("KSEF_TEST_TOKEN_AUTH"); ?>
                            </a>
                        <?php } else { ?>
                            <span class="button small butActionRefused classfortooltip"
                                  style="margin-bottom: 8px; display: block; opacity: 0.6; cursor: not-allowed;"
                                  title="<?php echo $langs->trans('KSEF_ConfigureTokenFirst'); ?>">
                            <span class="fas fa-key paddingright"></span><?php echo $langs->trans("KSEF_TEST_TOKEN_AUTH"); ?>
                        </span>
                        <?php } ?>

                        <?php if ($has_auth_cert) { ?>
                            <a href="<?php echo $_SERVER["PHP_SELF"]; ?>?action=testcertauth&token=<?php echo newToken(); ?>"
                               class="button small" style="margin-bottom: 8px; display: block;">
                                <span class="fas fa-certificate paddingright"></span><?php echo $langs->trans("KSEF_TEST_CERT_AUTH"); ?>
                            </a>
                        <?php } else { ?>
                            <span class="button small butActionRefused classfortooltip"
                                  style="margin-bottom: 8px; display: block; opacity: 0.6; cursor: not-allowed;"
                                  title="<?php echo $langs->trans('KSEF_ConfigureCertificateFirst'); ?>">
                            <span class="fas fa-certificate paddingright"></span><?php echo $langs->trans("KSEF_TEST_CERT_AUTH"); ?>
                        </span>
                        <?php } ?>
                    </div>

                    <div style="flex: 1; min-width: 220px; padding: 15px; background: #f8f9fa; border-left: 4px solid #28a745;">
                        <h4 style="margin-top: 0;">4. <?php echo $langs->trans("KSEF_SubmitFirstInvoice"); ?></h4>
                        <p><?php echo $langs->trans("KSEF_SubmitFirstInvoiceDesc"); ?></p>
                    </div>

                </div>
            </td>
        </tr>
    </table>

    <br>

    <table class="noborder centpercent">
        <tr class="liste_titre">
            <th><span class="fas fa-cog paddingright"></span><?php echo $langs->trans("KSEF_ConfigurationSection"); ?></th>
        </tr>
        <tr class="oddeven">
            <td>
                <h4><?php echo $langs->trans("KSEF_Environments"); ?></h4>
                <ul>
                    <li><strong><?php echo $langs->trans("KSEF_ENV_TEST"); ?></strong> - <?php echo $langs->trans("KSEF_ENV_TEST_FullDesc"); ?></li>
                    <li><strong><?php echo $langs->trans("KSEF_ENV_DEMO"); ?></strong> - <?php echo $langs->trans("KSEF_ENV_DEMO_FullDesc"); ?></li>
                    <li><strong><?php echo $langs->trans("KSEF_ENV_PRODUCTION"); ?></strong> - <?php echo $langs->trans("KSEF_ENV_PRODUCTION_FullDesc"); ?></li>
                </ul>

                <h4><?php echo $langs->trans("KSEF_Authentication"); ?></h4>
                <p><?php echo $langs->trans("KSEF_AuthenticationDesc"); ?></p>

                <h4><?php echo $langs->trans("KSEF_HowTo_Config_PerEnvAuth"); ?></h4>
                <p><?php echo $langs->trans("KSEF_HowTo_Config_PerEnvAuthDesc"); ?></p>

                <h4><?php echo $langs->trans("KSEF_HowTo_Config_PaymentDefaults"); ?></h4>
                <p><?php echo $langs->trans("KSEF_HowTo_Config_PaymentDefaultsDesc"); ?></p>

                <h4><?php echo $langs->trans("KSEF_OptionalFields"); ?></h4>
                <p><?php echo $langs->trans("KSEF_OptionalFieldsDesc"); ?></p>

                <h4><?php echo $langs->trans("KSEF_HowTo_Config_Notes"); ?></h4>
                <p><?php echo $langs->trans("KSEF_HowTo_Config_NotesIntro"); ?></p>
                <p><?php echo $langs->trans("KSEF_HowTo_Config_NotesModes"); ?></p>
                <div style="background: #f8f9fa; border-left: 3px solid #6c757d; padding: 8px 12px; margin: 8px 0; font-family: monospace; font-size: 13px;">
                    <?php echo $langs->trans("KSEF_HowTo_Config_NotesKeyValueExample1"); ?><br>
                    <?php echo $langs->trans("KSEF_HowTo_Config_NotesKeyValueExample2"); ?>
                </div>
                <p><?php echo $langs->trans("KSEF_HowTo_Config_CustomFields"); ?></p>
                <p><?php echo $langs->trans("KSEF_HowTo_Config_CustomFieldsTarget"); ?></p>
                <p><?php echo $langs->trans("KSEF_HowTo_Config_Limits"); ?></p>

                <h4><?php echo $langs->trans("KSEF_HowTo_Config_OrderContract"); ?></h4>
                <p><?php echo $langs->trans("KSEF_HowTo_Config_OrderContractDesc"); ?></p>

                <h4><?php echo $langs->trans("KSEF_HowTo_Config_Boilerplate"); ?></h4>
                <p><?php echo $langs->trans("KSEF_HowTo_Config_BoilerplateDesc"); ?></p>

                <h4><?php echo $langs->trans("KSEF_CompanyIdentifiers"); ?></h4>
                <p><?php echo $langs->trans("KSEF_CompanyIdentifiersDesc"); ?></p>

                <h4><?php echo $langs->trans("KSEF_CountryRequirement"); ?></h4>
                <p><?php echo $langs->trans("KSEF_CountryRequirementDesc"); ?></p>

                <h4><?php echo $langs->trans("KSEF_TranslationOverridesHowTo"); ?></h4>
                <p><?php echo $langs->trans("KSEF_TranslationOverridesHowToDesc"); ?></p>

                <h4><?php echo $langs->trans("KSEF_HowTo_Config_VatRates"); ?></h4>
                <p><?php echo $langs->trans("KSEF_HowTo_Config_VatRatesIntro"); ?></p>
                <p><b><?php echo $langs->trans("KSEF_HowTo_Config_VatRatesCodes_Intro"); ?></b></p>
                <ol>
                    <li><?php echo $langs->trans("KSEF_HowTo_Config_VatRatesCodes_Step1"); ?></li>
                    <li><?php echo $langs->trans("KSEF_HowTo_Config_VatRatesCodes_Step2"); ?></li>
                    <li><?php echo $langs->trans("KSEF_HowTo_Config_VatRatesCodes_Step3"); ?></li>
                    <li><?php echo $langs->trans("KSEF_HowTo_Config_VatRatesCodes_Step4"); ?></li>
                </ol>
                <p><?php echo $langs->trans("KSEF_HowTo_Config_VatRatesCodes_Outro"); ?></p>
                <div style="background: #f8f9fa; border-left: 3px solid #6c757d; padding: 8px 12px; margin: 8px 0; font-size: 13px;">
                    <table style="width:100%; border-collapse:collapse;">
                        <tr style="border-bottom:1px solid #ccc;">
                            <th style="text-align:left; padding:4px;"><?php echo $langs->trans("KSEF_HowTo_Config_VatRatesTable_ColCode"); ?></th>
                            <th style="text-align:left; padding:4px;"><?php echo $langs->trans("KSEF_HowTo_Config_VatRatesTable_ColUse"); ?></th>
                        </tr>
                        <tr style="border-bottom:1px solid #eee;">
                            <td style="padding:4px;"><i><?php echo $langs->trans("KSEF_HowTo_Config_VatRatesTable_None"); ?></i></td>
                            <td style="padding:4px;"><?php echo $langs->trans("KSEF_HowTo_Config_VatRatesTable_NoneUse"); ?></td>
                        </tr>
                        <tr style="border-bottom:1px solid #eee;">
                            <td style="padding:4px;"><b>ZW</b></td>
                            <td style="padding:4px;"><?php echo $langs->trans("KSEF_HowTo_Config_VatRatesTable_ZWUse"); ?></td>
                        </tr>
                        <tr style="border-bottom:1px solid #eee;">
                            <td style="padding:4px;"><b>RC</b></td>
                            <td style="padding:4px;"><?php echo $langs->trans("KSEF_HowTo_Config_VatRatesTable_RCUse"); ?></td>
                        </tr>
                        <tr style="border-bottom:1px solid #eee;">
                            <td style="padding:4px;"><b>NP</b></td>
                            <td style="padding:4px;"><?php echo $langs->trans("KSEF_HowTo_Config_VatRatesTable_NPUse"); ?></td>
                        </tr>
                        <tr style="border-bottom:1px solid #eee;">
                            <td style="padding:4px;"><b>NP2</b></td>
                            <td style="padding:4px;"><?php echo $langs->trans("KSEF_HowTo_Config_VatRatesTable_NP2Use"); ?></td>
                        </tr>
                        <tr style="border-bottom:1px solid #eee;">
                            <td style="padding:4px;"><b>WDT</b></td>
                            <td style="padding:4px;"><?php echo $langs->trans("KSEF_HowTo_Config_VatRatesTable_WDTUse"); ?></td>
                        </tr>
                        <tr>
                            <td style="padding:4px;"><b>EX</b></td>
                            <td style="padding:4px;"><?php echo $langs->trans("KSEF_HowTo_Config_VatRatesTable_EXUse"); ?></td>
                        </tr>
                    </table>
                </div>
                <p><?php echo $langs->trans("KSEF_HowTo_Config_VatRatesSetup"); ?></p>

                <h4><?php echo $langs->trans("KSEF_HowTo_Config_VatExemptSetup"); ?></h4>
                <p><?php echo $langs->trans("KSEF_HowTo_Config_VatExemptIntro"); ?></p>
                <p><b><?php echo $langs->trans("KSEF_HowTo_Config_VatExemptSteps_S1Title"); ?></b></p>
                <ol>
                    <li><?php echo $langs->trans("KSEF_HowTo_Config_VatExemptSteps_S1_1"); ?></li>
                    <li><?php echo $langs->trans("KSEF_HowTo_Config_VatExemptSteps_S1_2"); ?></li>
                    <li><?php echo $langs->trans("KSEF_HowTo_Config_VatExemptSteps_S1_3"); ?></li>
                </ol>
                <p><?php echo $langs->trans("KSEF_HowTo_Config_VatExemptSteps_S1Outro"); ?></p>
                <p><b><?php echo $langs->trans("KSEF_HowTo_Config_VatExemptSteps_S2Title"); ?></b></p>
                <ol>
                    <li><?php echo $langs->trans("KSEF_HowTo_Config_VatExemptSteps_S2_1"); ?></li>
                    <li><?php echo $langs->trans("KSEF_HowTo_Config_VatExemptSteps_S2_2"); ?></li>
                    <li><?php echo $langs->trans("KSEF_HowTo_Config_VatExemptSteps_S2_3"); ?></li>
                </ol>
                <p><?php echo $langs->trans("KSEF_HowTo_Config_VatExemptSteps_S2Outro"); ?></p>

                <h4><?php echo $langs->trans("KSEF_HowTo_AdvancedFields"); ?></h4>
                <p><?php echo $langs->trans("KSEF_HowTo_AdvancedFields_Intro"); ?></p>

                <p><b><?php echo $langs->trans("KSEF_HowTo_EntityFields"); ?></b></p>
                <ul>
                    <li><?php echo $langs->trans("KSEF_HowTo_EntityFields_IDNabywcy"); ?></li>
                    <li><?php echo $langs->trans("KSEF_HowTo_EntityFields_EORI"); ?></li>
                </ul>

                <p><b><?php echo $langs->trans("KSEF_HowTo_LineFields"); ?></b></p>
                <ul>
                    <li><?php echo $langs->trans("KSEF_HowTo_LineFields_GTU"); ?></li>
                    <li><?php echo $langs->trans("KSEF_HowTo_LineFields_Procedura"); ?></li>
                    <li><?php echo $langs->trans("KSEF_HowTo_LineFields_UUID"); ?></li>
                </ul>

                <p><b><?php echo $langs->trans("KSEF_HowTo_InvoiceFlags"); ?></b></p>
                <ul>
                    <li><?php echo $langs->trans("KSEF_HowTo_InvoiceFlags_FP"); ?></li>
                    <li><?php echo $langs->trans("KSEF_HowTo_InvoiceFlags_TP"); ?></li>
                    <li><?php echo $langs->trans("KSEF_HowTo_InvoiceFlags_MPP"); ?></li>
                    <li><?php echo $langs->trans("KSEF_HowTo_InvoiceFlags_P16"); ?></li>
                    <li><?php echo $langs->trans("KSEF_HowTo_InvoiceFlags_P17"); ?></li>
                    <li><?php echo $langs->trans("KSEF_HowTo_InvoiceFlags_P18"); ?></li>
                </ul>

                <h4><?php echo $langs->trans("KSEF_HowTo_Podmiot3"); ?></h4>
                <p><?php echo $langs->trans("KSEF_HowTo_Podmiot3_Intro"); ?></p>
                <ol>
                    <li><?php echo $langs->trans("KSEF_HowTo_Podmiot3_Step1"); ?></li>
                    <li><?php echo $langs->trans("KSEF_HowTo_Podmiot3_Step2"); ?></li>
                    <li><?php echo $langs->trans("KSEF_HowTo_Podmiot3_Step3"); ?></li>
                </ol>
                <p><?php echo $langs->trans("KSEF_HowTo_Podmiot3_Reconciliation"); ?></p>

                <p><b><?php echo $langs->trans("KSEF_HowTo_Podmiot3_JSTGV_Title"); ?></b></p>
                <p><?php echo $langs->trans("KSEF_HowTo_Podmiot3_JSTGV"); ?></p>

                <p><b><?php echo $langs->trans("KSEF_HowTo_Podmiot3_IDWew_Title"); ?></b></p>
                <p><?php echo $langs->trans("KSEF_HowTo_Podmiot3_IDWew_What"); ?></p>
                <p><?php echo $langs->trans("KSEF_HowTo_Podmiot3_IDWew_How"); ?></p>

                <p><b><?php echo $langs->trans("KSEF_HowTo_Podmiot3_Limits_Title"); ?></b></p>
                <ul>
                    <li><?php echo $langs->trans("KSEF_HowTo_Podmiot3_Faktor"); ?></li>
                    <li><?php echo $langs->trans("KSEF_HowTo_Podmiot3_Role4"); ?></li>
                </ul>
            </td>
        </tr>
    </table>

    <br>

    <table class="noborder centpercent">
        <tr class="liste_titre">
            <th><span class="fas fa-search paddingright"></span><?php echo $langs->trans("KSEF_GusHowTo_Title"); ?></th>
        </tr>
        <tr class="oddeven">
            <td>
                <p><?php echo $langs->trans("KSEF_GusHowTo_Intro"); ?></p>
                <p><?php echo $langs->trans("KSEF_GusHowTo_KeyTest"); ?></p>
                <p><?php echo $langs->trans("KSEF_GusHowTo_KeyProd"); ?></p>
                <ol>
                    <li><?php echo $langs->trans("KSEF_GusHowTo_Step1"); ?></li>
                    <li><?php echo $langs->trans("KSEF_GusHowTo_Step2"); ?></li>
                    <li><?php echo $langs->trans("KSEF_GusHowTo_Step3"); ?></li>
                    <li><?php echo $langs->trans("KSEF_GusHowTo_Step4"); ?></li>
                </ol>
                <p><strong><?php echo $langs->trans("KSEF_GusRequestKeyTitle"); ?></strong></p>
                <ol>
                    <li><?php echo $langs->trans("KSEF_GusKeyApplyStep1"); ?></li>
                    <li><?php echo $langs->trans("KSEF_GusKeyApplyStep2"); ?></li>
                    <li><?php echo $langs->trans("KSEF_GusKeyApplyStep3"); ?></li>
                    <li><?php echo $langs->trans("KSEF_GusKeyApplyStep4"); ?></li>
                </ol>
                <p><?php echo $langs->trans("KSEF_GusHowTo_EmailLabel"); ?></p>
                <pre style="background: #f5f5f5; border: 1px solid #ddd; padding: 10px; white-space: pre-wrap;"><?php echo htmlspecialchars(ksefGusRequestEmail()); ?></pre>
            </td>
        </tr>
    </table>

    <br>

    <table class="noborder centpercent">
        <tr class="liste_titre">
            <th><span class="fas fa-file-invoice paddingright"></span><?php echo $langs->trans("KSEF_SendingInvoices"); ?></th>
        </tr>
        <tr class="oddeven">
            <td>
                <h4><?php echo $langs->trans("KSEF_TheWorkflow"); ?></h4>
                <ol>
                    <li><?php echo $langs->trans("KSEF_Workflow_Step1"); ?></li>
                    <li><?php echo $langs->trans("KSEF_Workflow_Step2"); ?></li>
                    <li><?php echo $langs->trans("KSEF_Workflow_Step3"); ?>
                        <ul>
                            <li><strong><?php echo $langs->trans("KSEF_ValidateAndUpload"); ?></strong> - <?php echo $langs->trans("KSEF_ValidateAndUpload_Desc"); ?></li>
                            <li><strong><?php echo $langs->trans("KSEF_UploadToKSEF"); ?></strong> - <?php echo $langs->trans("KSEF_UploadToKSEF_Desc"); ?></li>
                        </ul>
                    </li>
                    <li><?php echo $langs->trans("KSEF_Workflow_Step4"); ?></li>
                </ol>

                <h4><?php echo $langs->trans("KSEF_HowTo_Sending_PaymentFirst"); ?></h4>
                <p><?php echo $langs->trans("KSEF_HowTo_Sending_PaymentFirstDesc"); ?></p>

                <h4><?php echo $langs->trans("KSEF_Statuses"); ?></h4>
                <ul style="list-style: none; padding-left: 0;">
                    <li style="margin-bottom: 8px;"><span class="badge badge-status4"><?php echo $langs->trans("KSEF_STATUS_ACCEPTED"); ?></span> - <?php echo $langs->trans("KSEF_STATUS_ACCEPTED_Desc"); ?></li>
                    <li style="margin-bottom: 8px;"><span class="badge badge-status3"><?php echo $langs->trans("KSEF_STATUS_PENDING"); ?></span> - <?php echo $langs->trans("KSEF_STATUS_PENDING_Desc"); ?></li>
                    <li style="margin-bottom: 8px;"><span class="badge badge-status8"><?php echo $langs->trans("KSEF_STATUS_REJECTED"); ?></span> / <span class="badge badge-status8"><?php echo $langs->trans("KSEF_STATUS_FAILED"); ?></span> - <?php echo $langs->trans("KSEF_STATUS_REJECTED_Desc"); ?></li>
                    <li style="margin-bottom: 8px;"><span class="badge badge-status1"><?php echo $langs->trans("KSEF_STATUS_OFFLINE"); ?></span> - <?php echo $langs->trans("KSEF_STATUS_OFFLINE_Desc"); ?></li>
                </ul>

                <h4><?php echo $langs->trans("KSEF_ForeignCurrencyInvoices"); ?></h4>
                <p><?php echo $langs->trans("KSEF_ForeignCurrencyDesc"); ?></p>

                <h4><?php echo $langs->trans("KSEF_BankAccountOnInvoice"); ?></h4>
                <p><?php echo $langs->trans("KSEF_BankAccountOnInvoiceDesc"); ?></p>
                <ol>
                    <li><?php echo $langs->trans("KSEF_BankAccount_Step1"); ?></li>
                    <li><?php echo $langs->trans("KSEF_BankAccount_Step2"); ?></li>
                    <li><?php echo $langs->trans("KSEF_BankAccount_Step3"); ?></li>
                    <li><?php echo $langs->trans("KSEF_BankAccount_Step4"); ?></li>
                </ol>

                <h4><?php echo $langs->trans("KSEF_HowTo_Sending_PDFPreview"); ?></h4>
                <p><?php echo $langs->trans("KSEF_HowTo_Sending_PDFPreviewDesc"); ?></p>

                <h4><?php echo $langs->trans("KSEF_HowTo_Sending_Notes"); ?></h4>
                <p><?php echo $langs->trans("KSEF_HowTo_Sending_NotesEditing"); ?></p>
                <p><?php echo $langs->trans("KSEF_HowTo_Sending_NotesPreview"); ?></p>
                <p><?php echo $langs->trans("KSEF_HowTo_Sending_NotesOverride"); ?></p>

                <h4><?php echo $langs->trans("KSEF_AfterAcceptance"); ?></h4>
                <p><?php echo $langs->trans("KSEF_AfterAcceptanceDesc"); ?></p>

                <h4><?php echo $langs->trans("KSEF_CheckingStatus"); ?></h4>
                <p><?php echo $langs->trans("KSEF_CheckingStatusDesc"); ?>
                    <a href="<?php echo dol_buildpath('/ksef/status.php', 1); ?>"><?php echo $langs->trans("KSEF_SubmissionStatus"); ?></a>
                </p>
            </td>
        </tr>
    </table>

    <br>

    <table class="noborder centpercent">
        <tr class="liste_titre">
            <th><span class="fas fa-exchange-alt paddingright"></span><?php echo $langs->trans("KSEF_HowTo_Corrections"); ?></th>
        </tr>
        <tr class="oddeven">
            <td>
                <p><?php echo $langs->trans("KSEF_HowTo_Corrections_Intro"); ?></p>

                <h4><?php echo $langs->trans("KSEF_HowTo_Corrections_WhenTitle"); ?></h4>
                <ul>
                    <li><?php echo $langs->trans("KSEF_HowTo_Corrections_CreditNote"); ?></li>
                    <li><?php echo $langs->trans("KSEF_HowTo_Corrections_Replacement"); ?></li>
                </ul>

                <h4><?php echo $langs->trans("KSEF_HowTo_Corrections_CreateTitle"); ?></h4>
                <p><?php echo $langs->trans("KSEF_HowTo_Corrections_CreateButton"); ?></p>
                <p><?php echo $langs->trans("KSEF_HowTo_Corrections_CreateRadio"); ?></p>
                <p><?php echo $langs->trans("KSEF_HowTo_Corrections_PaidInvoice"); ?></p>

                <h4><?php echo $langs->trans("KSEF_HowTo_Corrections_DetailsTitle"); ?></h4>
                <p><?php echo $langs->trans("KSEF_HowTo_Corrections_Reason"); ?></p>
                <p><?php echo $langs->trans("KSEF_HowTo_Corrections_Type"); ?></p>

                <h4><?php echo $langs->trans("KSEF_HowTo_Corrections_KsefTitle"); ?></h4>
                <p><?php echo $langs->trans("KSEF_HowTo_Corrections_KsefXml"); ?></p>
                <ul>
                    <li><?php echo $langs->trans("KSEF_HowTo_Corrections_MethodDiff"); ?></li>
                    <li><?php echo $langs->trans("KSEF_HowTo_Corrections_MethodStan"); ?></li>
                </ul>
                <p><?php echo $langs->trans("KSEF_HowTo_Corrections_CreditNoteDiff"); ?></p>

                <h4><?php echo $langs->trans("KSEF_HowTo_Corrections_ChainTitle"); ?></h4>
                <p><?php echo $langs->trans("KSEF_HowTo_Corrections_Chain"); ?></p>

                <h4><?php echo $langs->trans("KSEF_HowTo_Corrections_ConfigTitle"); ?></h4>
                <p><?php echo $langs->trans("KSEF_HowTo_Corrections_Config"); ?></p>
            </td>
        </tr>
    </table>

    <br>

    <table class="noborder centpercent">
        <tr class="liste_titre">
            <th><span class="fas fa-download paddingright"></span><?php echo $langs->trans("KSEF_ReceivingInvoices"); ?></th>
        </tr>
        <tr class="oddeven">
            <td>
                <p><?php echo $langs->trans("KSEF_ReceivingInvoicesDesc"); ?></p>
                <ol>
                    <li><?php echo $langs->trans("KSEF_Receiving_Step1"); ?>
                        <a href="<?php echo dol_buildpath('/ksef/incoming_list.php', 1); ?>"><?php echo $langs->trans("KSEF_IncomingInvoices"); ?></a>
                    </li>
                    <li><?php echo $langs->trans("KSEF_Receiving_Step2"); ?></li>
                    <li><?php echo $langs->trans("KSEF_Receiving_Step3"); ?></li>
                </ol>
                <p><?php echo $langs->trans("KSEF_ReceivingActions"); ?></p>

                <h4><?php echo $langs->trans("KSEF_ImportingToDolibarr"); ?></h4>
                <p><?php echo $langs->trans("KSEF_ImportingToDolibarrDesc"); ?></p>

                <h4><?php echo $langs->trans("KSEF_Importing_HowItWorks"); ?></h4>
                <ol>
                    <li><?php echo $langs->trans("KSEF_Importing_Step1"); ?></li>
                    <li><?php echo $langs->trans("KSEF_Importing_Step2"); ?></li>
                    <li><?php echo $langs->trans("KSEF_Importing_Step3"); ?></li>
                    <li><?php echo $langs->trans("KSEF_Importing_Step4"); ?></li>
                </ol>

                <p><strong><?php echo $langs->trans("KSEF_Importing_Individual"); ?></strong> - <?php echo $langs->trans("KSEF_Importing_IndividualDesc"); ?></p>
                <p><strong><?php echo $langs->trans("KSEF_Importing_Batch"); ?></strong> - <?php echo $langs->trans("KSEF_Importing_BatchDesc"); ?></p>

                <p><?php echo $langs->trans("KSEF_Importing_StockNote"); ?>
                    <a href="<?php echo DOL_URL_ROOT; ?>/admin/stock.php"><?php echo $langs->trans("KSEF_Importing_StockNoteLink"); ?></a>.
                    <?php echo $langs->trans("KSEF_Importing_StockNoteFreeText"); ?>
                </p>
            </td>
        </tr>
    </table>

    <br>

    <table class="noborder centpercent">
        <tr class="liste_titre">
            <th><span class="fas fa-user-slash paddingright"></span><?php echo $langs->trans("KSEF_CustomerExclusions"); ?></th>
        </tr>
        <tr class="oddeven">
            <td>
                <p><?php echo $langs->trans("KSEF_CustomerExclusionsDesc"); ?></p>
            </td>
        </tr>
    </table>

    <br>

    <table class="noborder centpercent">
        <tr class="liste_titre">
            <th><span class="fas fa-wrench paddingright"></span><?php echo $langs->trans("KSEF_Troubleshooting"); ?></th>
        </tr>
        <tr class="oddeven">
            <td>
                <h4><?php echo $langs->trans("KSEF_FindingErrorDetails"); ?></h4>
                <p><?php echo $langs->trans("KSEF_FindingErrorDetailsDesc"); ?>
                    <a href="<?php echo dol_buildpath('/ksef/status.php', 1); ?>"><?php echo $langs->trans("KSEF_SubmissionStatus"); ?></a>
                </p>

                <h4><?php echo $langs->trans("KSEF_CommonIssues"); ?></h4>

                <p><strong><?php echo $langs->trans("KSEF_Issue_DuplicateNumber"); ?></strong><br>
                    <?php echo $langs->trans("KSEF_Issue_DuplicateNumber_Desc"); ?></p>

                <p><strong><?php echo $langs->trans("KSEF_Issue_AuthFailed"); ?></strong><br>
                    <?php echo $langs->trans("KSEF_Issue_AuthFailed_Desc"); ?></p>

                <p><strong><?php echo $langs->trans("KSEF_Issue_NBPMissing"); ?></strong><br>
                    <?php echo $langs->trans("KSEF_Issue_NBPMissing_Desc"); ?></p>

                <p><strong><?php echo $langs->trans("KSEF_Issue_Timeout"); ?></strong><br>
                    <?php echo $langs->trans("KSEF_Issue_Timeout_Desc"); ?><br>
                    <em><?php echo $langs->trans("KSEF_Issue_Timeout_Solution"); ?></em></p>

                <h4><?php echo $langs->trans("KSEF_RetryLimits"); ?></h4>
                <p><?php echo $langs->trans("KSEF_RetryLimitsDesc"); ?></p>
            </td>
        </tr>
    </table>

<?php

print dol_get_fiche_end();

llxFooter();
$db->close();
?>