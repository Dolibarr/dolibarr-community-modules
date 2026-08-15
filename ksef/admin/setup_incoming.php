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
 * \file    admin/setup_incoming.php
 * \ingroup ksef
 * \brief   KSEF incoming invoice settings tab
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
dol_include_once('/ksef/lib/ksef.lib.php');

if (!$user->admin) {
    accessforbidden();
}

$langs->loadLangs(array("admin", "ksef@ksef"));

$action = GETPOST('action', 'aZ09');

if ($action == 'update') {
    // Auto-create suppliers
    dolibarr_set_const($db, 'KSEF_BATCH_AUTO_CREATE_SUPPLIERS', GETPOST('KSEF_BATCH_AUTO_CREATE_SUPPLIERS', 'int') ? '1' : '0', 'chaine', 0, '', $conf->entity);
    // Auto-create products
    dolibarr_set_const($db, 'KSEF_BATCH_AUTO_CREATE_PRODUCTS', GETPOST('KSEF_BATCH_AUTO_CREATE_PRODUCTS', 'int') ? '1' : '0', 'chaine', 0, '', $conf->entity);
    // Use INDEKS as product ref
    dolibarr_set_const($db, 'KSEF_PRODUCT_REF_USE_INDEKS', GETPOST('KSEF_PRODUCT_REF_USE_INDEKS', 'int') ? '1' : '0', 'chaine', 0, '', $conf->entity);

    setEventMessages($langs->trans("SetupSaved"), null, 'mesgs');
    header("Location: " . $_SERVER["PHP_SELF"]);
    exit;
}

$form = new Form($db);
$page_name = "KSEF_Setup";

llxHeader('', $langs->trans($page_name), '');

$linkback = '<a href="' . DOL_URL_ROOT . '/admin/modules.php?restore_lastsearch_values=1">' . $langs->trans("KSEF_BackToModuleList") . '</a>';
print load_fiche_titre($langs->trans($page_name), $linkback, 'title_setup');

$head = ksefAdminPrepareHead();
print dol_get_fiche_head($head, 'incoming', $langs->trans("KSEF_Module"), -1, 'ksef@ksef');

// Reactivation + update banners
echo ksefShowAdminBanners();

// Config warnings
$warnings = ksefGetConfigWarnings();
if (!empty($warnings)) {
    echo ksefRenderConfigWarnings($warnings, 'incoming');
}

print '<form method="POST" action="' . $_SERVER["PHP_SELF"] . '">';
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" name="action" value="update">';

// Importing Settings
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="2">' . $langs->trans("KSEF_IMPORTING_CONFIG") . '</td></tr>';

// Auto-create suppliers
print '<tr class="oddeven">';
print '<td class="titlefield">' . $form->textwithpicto($langs->trans('KSEF_BATCH_AUTO_CREATE_SUPPLIERS_SETTING'), $langs->trans('KSEF_BATCH_AUTO_CREATE_SUPPLIERS_SETTING_Help')) . '</td>';
print '<td>';
print '<input type="checkbox" name="KSEF_BATCH_AUTO_CREATE_SUPPLIERS" id="KSEF_BATCH_AUTO_CREATE_SUPPLIERS" value="1" ' . (getDolGlobalString('KSEF_BATCH_AUTO_CREATE_SUPPLIERS', '1') === '1' ? 'checked' : '') . '>';
print ' <label for="KSEF_BATCH_AUTO_CREATE_SUPPLIERS">' . $langs->trans("KSEF_Enabled") . '</label>';
print '</td></tr>';

// Auto-create products
print '<tr class="oddeven">';
print '<td class="titlefield">' . $form->textwithpicto($langs->trans('KSEF_BATCH_AUTO_CREATE_PRODUCTS_SETTING'), $langs->trans('KSEF_BATCH_AUTO_CREATE_PRODUCTS_SETTING_Help')) . '</td>';
print '<td>';
print '<input type="checkbox" name="KSEF_BATCH_AUTO_CREATE_PRODUCTS" id="KSEF_BATCH_AUTO_CREATE_PRODUCTS" value="1" ' . (getDolGlobalString('KSEF_BATCH_AUTO_CREATE_PRODUCTS', '1') === '1' ? 'checked' : '') . '>';
print ' <label for="KSEF_BATCH_AUTO_CREATE_PRODUCTS">' . $langs->trans("KSEF_Enabled") . '</label>';
print '</td></tr>';

// Use indeks as product ref
print '<tr class="oddeven">';
print '<td class="titlefield">' . $form->textwithpicto($langs->trans('KSEF_PRODUCT_REF_USE_INDEKS_SETTING'), $langs->trans('KSEF_PRODUCT_REF_USE_INDEKS_SETTING_Help')) . '</td>';
print '<td>';
print '<input type="checkbox" name="KSEF_PRODUCT_REF_USE_INDEKS" id="KSEF_PRODUCT_REF_USE_INDEKS" value="1" ' . (getDolGlobalString('KSEF_PRODUCT_REF_USE_INDEKS', '1') === '1' ? 'checked' : '') . '>';
print ' <label for="KSEF_PRODUCT_REF_USE_INDEKS">' . $langs->trans("KSEF_Enabled") . '</label>';
print '</td></tr>';

print '</table>';

print '<div class="center" style="margin-top: 10px;">';
print '<input type="submit" class="button button-save" value="' . $langs->trans("Save") . '">';
print '</div>';
print '</form>';

print dol_get_fiche_end();

llxFooter();
