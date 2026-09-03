<?php
/* Copyright (C) 2001-2002	Rodolphe Quiedeville	<rodolphe@quiedeville.org>
 * Copyright (C) 2006-2017	Laurent Destailleur		<eldy@users.sourceforge.net>
 * Copyright (C) 2009-2012	Regis Houssin			<regis.houssin@inodbox.com>
 * Copyright (C) 2018	    Juanjo Menent			<jmenent@2byte.es>
 * Copyright (C) 2018-2021	Thibault FOUCART	    <support@ptibogxiv.net>
 * Copyright (C) 2021		Waël Almoman	    	<info@almoman.com>
 * Copyright (C) 2021		Dorian Vabre			<dorian.vabre@gmail.com>
 * Copyright (C) 2026		MDW						<mdeweerd@users.noreply.github.com>
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
 *
 */

/**
 *     	\file       htdocs/public/payment/newpayment_propal.php
 *		\ingroup    core
 *		\brief      File to offer a way to make a payment for a particular Dolibarr object
 */

if (!defined('NOLOGIN')) {
	define("NOLOGIN", 1); // This means this output page does not require to be logged.
}
if (!defined('NOCSRFCHECK')) {
	define("NOCSRFCHECK", 1); // We accept to go on this page from external web site.
}
if (!defined('NOIPCHECK')) {
	define('NOIPCHECK', '1'); // Do not check IP defined into conf $dolibarr_main_restrict_ip
}
if (!defined('NOBROWSERNOTIF')) {
	define('NOBROWSERNOTIF', '1');
}

// For MultiCompany module.
// Do not use GETPOST here, function is not defined and get of entity must be done before including main.inc.php
$entity = (!empty($_GET['entity']) ? (int) $_GET['entity'] : (!empty($_POST['entity']) ? (int) $_POST['entity'] : (!empty($_GET['e']) ? (int) $_GET['e'] : (!empty($_POST['e']) ? (int) $_POST['e'] : 1))));
if (is_numeric($entity)) {
	define("DOLENTITY", $entity);
}

$res = 0;
// Try main.inc.php into web root known defined into CONTEXT_DOCUMENT_ROOT (not always defined)
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"] . "/main.inc.php";
}
// Try main.inc.php into web root detected using web root calculated from SCRIPT_FILENAME
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
// Try main.inc.php using relative path
if (!$res && file_exists("../main.inc.php")) {
	$res = @include "../main.inc.php";
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
require_once DOL_DOCUMENT_ROOT . '/core/lib/company.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/payments.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/functions2.lib.php';
require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT . '/societe/class/societeaccount.class.php';
require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT . "/commande/class/commande.class.php";
require_once DOL_DOCUMENT_ROOT . '/projet/class/project.class.php';
// Hook to be used by external payment modules (ie Payzen, ...)
include_once DOL_DOCUMENT_ROOT . '/core/class/hookmanager.class.php';
if (file_exists(DOL_DOCUMENT_ROOT . '/eventorganization/class/conferenceorboothattendee.class.php')) {
	require_once DOL_DOCUMENT_ROOT . '/eventorganization/class/conferenceorboothattendee.class.php';
}

$hookmanager = new HookManager($db);
$hookmanager->initHooks(array('newpayment'));

// Load translation files
$langs->loadLangs(array("main", "other", "dict", "bills", "companies", "errors")); // File with generic data

// Security check
// No check on module enabled. Done later according to $validpaymentmethod

$action = GETPOST('action', 'aZ09');
$ref = $REF = GETPOST('ref', 'alpha');
$TAG = GETPOST("tag", 'alpha');
$FULLTAG = GETPOST("fulltag", 'alpha'); // fulltag is tag with more information
$SECUREKEY = GETPOST("securekey"); // Secure key

$suffix = GETPOST("suffix", 'aZ09');
$amount = (float) price2num(GETPOST("amount", 'alpha'));
if (!GETPOST("currency", 'alpha')) {
	$currency = $conf->currency;
} else {
	$currency = GETPOST("currency", 'aZ09');
}
$source = GETPOST("s", 'aZ09') ? GETPOST("s", 'aZ09') : GETPOST("source", 'aZ09');
//$download = GETPOST('d', 'int') ?GETPOST('d', 'int') : GETPOST('download', 'int');
$object = null;
$numPaiement = $error = $partialPayment = 0;
//partialPayment set to 1 in case of for "30% on order" for example

if (!$action) {
	if (!GETPOST("amount", 'alpha') && !$source) {
		print $langs->trans('ErrorBadParameters') . " - amount or source";
		exit;
	}
	if (is_numeric($amount) && !GETPOST("tag", 'alpha') && !$source) {
		print $langs->trans('ErrorBadParameters') . " - tag or source";
		exit;
	}
	if ($source && !GETPOST("ref", 'alpha')) {
		print $langs->trans('ErrorBadParameters') . " - ref";
		exit;
	}
}
if ($source == 'propal') {
	$found = true;
	require_once DOL_DOCUMENT_ROOT . '/comm/propal/class/propal.class.php';

	$propal = new Propal($db);
	$result = $propal->fetch(0, $ref);
	if (is_numeric($result) && $result <= 0) {
		$mesg = $propal->error;
		$error++;
	} else {
		$result = $propal->fetch_thirdparty($propal->socid);
	}
	$object = $propal;
	$amount = $object->total_ttc;

	// M1: verify the online-payment security token for this propal before any
	// sensitive action (payment/customer creation). Aligned EXACTLY with the link
	// generation in stancerGetPropalPaymentUrl():
	// dol_hash(PAYMENT_SECURITY_TOKEN + 'propalpayment' + id + ref).
	if (!empty($conf->global->PAYMENT_SECURITY_TOKEN) && !empty($propal->id)) {
		$tokenisok = false;
		if (!empty($conf->global->PAYMENT_SECURITY_TOKEN_UNIQUE)) {
			$tokenisok = dol_verifyHash($conf->global->PAYMENT_SECURITY_TOKEN . 'propalpayment' . $propal->id . $propal->ref, $SECUREKEY, '2');
		} else {
			$tokenisok = ($conf->global->PAYMENT_SECURITY_TOKEN == $SECUREKEY);
		}
		if (!$tokenisok && empty($conf->global->PAYMENT_SECURITY_ACCEPT_ANY_TOKEN)) {
			dol_syslog("stancer newpayment_propal: invalid securekey for propal ref=" . $REF, LOG_ERR);
			accessforbidden('Bad value for payment security key');
		}
	}

	dol_syslog("stancer newpayment_propal.php : amount=$amount, deposit_percent=" . $object->deposit_percent . " setup partial pay=" . getDolGlobalString('STANCER_CB_PROPAL_PARTIAL_PAY'), LOG_DEBUG);
	// Partial payment (deposit) for propal - e.g. "20% on order"
	if (
		getDolGlobalString('STANCER_CB_PROPAL_PARTIAL_PAY') != ''
		&& isset($object->deposit_percent) && $object->deposit_percent > 0 && $object->deposit_percent < 100
	) {
		$partialAmount = ($object->total_ttc * $object->deposit_percent / 100);
		if ($partialAmount > 0 && $partialAmount < $amount) {
			dol_syslog("stancer newpayment_propal.php : apply partial Amount (deposit) : $partialAmount", LOG_DEBUG);
			$amount = $partialAmount;
			$partialPayment = 1;
		} else {
			dol_syslog("stancer newpayment_propal.php : partial Amount not used : $partialAmount is <= 0 or not < amount ($amount)", LOG_DEBUG);
		}
	}
} else {
	dol_syslog("stancer newpayment_propal.php : source is not propal but $source ! wtf", LOG_DEBUG);
	print $langs->trans('ErrorBadParameters') . " - not a propal !";
	exit;
}

// Add more content on page for some services

// Save some data for the paymentok
$remoteip = getUserRemoteIP();
$_SESSION["currencyCodeType"] = $currency;
$_SESSION["FinalPaymentAmt"] = $amount;
$_SESSION['ipaddress'] = ($remoteip ? $remoteip : 'unknown'); // Payer ip
$_SESSION["paymentType"] = 'CB';
$_SESSION["partialPayment"] = $partialPayment;

$tag = stancerMakeTAG($object);
// $tag = GETPOST("tag", 'alpha');
//Ajouter le numéro de séquence si paiement en plusieurs fois et que le TAG n'embarque pas cette info
if ($numPaiement > 0 && (strpos($tag, 'SEQ=') === false)) {
	$tag .= '.SEQ=' . $numPaiement;
}

// print "<p>FACTURE : $ref reste à payer $resteapayer num=$numPaiement, tag=$tag</p>";
// exit;

// For any other payment services
// This hook can be used to show the embedded form to make payments with external payment modules (ie Payzen, ...)
$parameters = [
	'paymentmethod' => 'stancer',
	'amount' => $amount,
	'currency' => $currency,
	'tag' => $tag,
	'dopayment' => GETPOST('dopayment', 'alpha')
];
$reshook = $hookmanager->executeHooks('doPayment', $parameters, $object, $action);
if ($reshook < 0) {
	// setEventMessages($hookmanager->error, $hookmanager->errors, 'errors');
	dol_syslog("stancer error on doPayment hook : " . $hookmanager->error . ", " . json_encode($hookmanager->errors), LOG_ERR);
} elseif ($reshook > 0) {
	print $hookmanager->resPrint;
}


$db->close();
