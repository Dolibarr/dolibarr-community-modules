<?php
/* Copyright (C) 2004-2017 Laurent Destailleur  <eldy@users.sourceforge.net>
 * Copyright (C) 2023 Eric Seigne <eric.seigne@cap-rel.fr>
 * Copyright (C) 2026		MDW						<mdeweerd@users.noreply.github.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    stancer/admin/test.php
 * \ingroup stancer
 * \brief   Test page of module Stancer.
 */

// Load Dolibarr environment
$res = 0;
// Try main.inc.php into web root known defined into CONTEXT_DOCUMENT_ROOT (not always defined)
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
// Try main.inc.php into web root detected using web root calculated from SCRIPT_FILENAME
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME']; $tmp2 = realpath(__FILE__); $i = strlen($tmp) - 1; $j = strlen($tmp2) - 1;  // @phan-suppress-current-line DolibarrForbiddenFunctionPlugin
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
	$i--;
	$j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1))."/main.inc.php")) {
	$res = @include substr($tmp, 0, ($i + 1))."/main.inc.php";
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php")) {
	$res = @include dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php";
}
// Try main.inc.php using relative path
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

// Libraries
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/functions2.lib.php';
dol_include_once('/stancer/lib/stancer.lib.php');

// Translations
$langs->loadLangs(array("errors", "admin", "stancer@stancer"));

// Access control
if (!$user->admin) {
	accessforbidden();
}

// Parameters
$action = GETPOST('action', 'aZ09');
$backtopage = GETPOST('backtopage', 'alpha');
$modulepart = GETPOST('modulepart', 'aZ09');	// Used by actions_setmoduleoptions.inc.php

$error = 0;
// $setupnotempty = 0;

$useFormSetup = 1;

if (!class_exists('FormSetup')) {
	// For retrocompatibility Dolibarr < 16.0
	if (floatval(DOL_VERSION) < 16.0 && !class_exists('FormSetup')) {
		dol_include_once('/stancer/backport/v16/core/class/html.formsetup.class.php');
	} else {
		require_once DOL_DOCUMENT_ROOT.'/core/class/html.formsetup.class.php';
	}
}


$formSetup = new FormSetup($db);
$form = new Form($db);

$formSetup->newItem('STANCER_ENABLE_CB')->setAsYesNo();

$formSetup->newItem('STANCER_CB_AS_PAID')->setAsYesNo();

$formSetup->newItem('STANCER_PUBLIC_CB_PAGE')->setAsYesNo();

$sql = "SELECT rowid, nom FROM ".MAIN_DB_PREFIX."societe WHERE status='1' AND client='1' AND entity = '".((int) $conf->entity)."'";
$result = $db->query($sql);
$options = array();
if ($result) {
	while ($obj = $db->fetch_object($result)) {
		$options[$obj->rowid] = $obj->nom;
	}
}
$formSetup->newItem('STANCER_DEFAULT_CUSTOMER_IF_NULL')->setAsSelect($options);

$formSetup->newItem('STANCER_CB_ALLOW_RETRY')->setAsYesNo();

if (floatval(DOL_VERSION) > 16.0) {
	//Facture::createDepositFromOrigin only on dolibarr > 16.0
	$formSetup->newItem('STANCER_CB_ORDER_PARTIAL_PAY')->setAsYesNo();
	$formSetup->newItem('STANCER_CB_PROPAL_PARTIAL_PAY')->setAsYesNo();
}
/*
 * Actions
 */
if ( versioncompare(explode('.', DOL_VERSION), array(15)) < 0 && $action == 'update' && !empty($user->admin)) {
	$formSetup->saveConfFromPost();
}
include DOL_DOCUMENT_ROOT.'/core/actions_setmoduleoptions.inc.php';

/*
 * View
 */

$help_url = '';
$page_name = "StancerSetupCB";

llxHeader('', $langs->trans($page_name), $help_url);

// Subheader
$linkback = '<a href="'.($backtopage ? $backtopage : DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1').'">'.$langs->trans("BackToModuleList").'</a>';

print load_fiche_titre($langs->trans($page_name), $linkback, 'title_setup');

// Configuration header
$head = stancerAdminPrepareHead();
print dol_get_fiche_head($head, 'StancerCBMenu', $langs->trans($page_name), 0, 'stancer@stancer');

dol_include_once('/stancer/core/modules/modStancer.class.php');
$tmpmodule = new modStancer($db);

print "<h1>" . $langs->trans("StancerCBConfig") . "</h1>";

// $stancer->setDebug(true);
print "<p>" . $langs->transnoentitiesnoconv("StancerCBPresentation") . "</p>";

//paiements CB
$idpaiementcard = dol_getIdFromCode($db, 'CB', 'c_paiement', 'code', 'id', 1);
if ($idpaiementcard) {
} else {
	print '<div class="error"><span class="fa fa-warning"> </span> <span class="clear"> ' . $langs->trans("STANCER_NO_CODE_CB") . '</span></div>';
}

//note j'ai pas capté les modifs de dolibarr qui n'utilise plus cette variable globale ...

if ($action == 'edit') {
	print $formSetup->generateOutput(true);
} else {
	print $formSetup->generateOutput();
	print '<div class="tabsAction">';
	print '<a class="butAction" href="'.$_SERVER["PHP_SELF"].'?action=edit&token='.newToken().'">'.$langs->trans("Modify").'</a>';
	print '</div>';
}


// $del = $customerRead->delete();
// print "<p>Delete = " . $del . "</p>";

// $list = Stancer\Payment::list(['created' => '1660338149', 'limit' => 2]);

// foreach ($list as $payment) {
//   // Do some stuff with $payment
//   print "<p>" . json_encode($payment) . "</p>";
// }

// print "<p>Apres le list</p>";

// $card = new Stancer\Card();
// $card->setNumber('5555555555554444');
// $card->setCvc('123');
// $card->setExpirationMonth('02');
// $card->setExpirationYear('2023');

// $customer = new Stancer\Customer();
// $customer->setEmail('david@example.net');
// $customer->setMobile('+33639980102');
// $customer->setName('David Coaster');

// $payment = new Stancer\Payment();
// $payment->setAmount(100);
// $payment->setCard($card);
// $payment->setCurrency('eur');
// $payment->setCustomer($customer);
// $payment->setDescription('Test Payment Company');

// $res = $payment->send();
// //paym_QClw6D2VqTxNIOTySbExArZd
// print json_encode($res);


if (getDolGlobalString('STANCER_PUBLIC_CB_PAGE', '') != '') {
	if (getDolGlobalString('STANCER_EMAIL_DPO', '') == '') {
		setEventMessages($langs->trans("ErrorStancerDPOmissing"), [], 'warnings');
	}
}

// Page end
print dol_get_fiche_end();
llxFooter();
$db->close();
