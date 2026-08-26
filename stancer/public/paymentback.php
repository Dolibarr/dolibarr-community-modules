<?php
/* Copyright (C) 2001-2002	Rodolphe Quiedeville	<rodolphe@quiedeville.org>
 * Copyright (C) 2006-2013	Laurent Destailleur		<eldy@users.sourceforge.net>
 * Copyright (C) 2012		Regis Houssin			<regis.houssin@inodbox.com>
 * Copyright (C) 2021		Waël Almoman			<info@almoman.com>
 * Copyright (C) 2021		Maxime Demarest			<maxime@indelog.fr>
 * Copyright (C) 2021		Dorian Vabre			<dorian.vabre@gmail.com>
 * Copyright (C) 2023		Éric Seigne			<eric.seigne@cap-rel.fr>
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
 *     	\file       htdocs/public/payment/paymentok.php
 *		\ingroup    core
 *		\brief      File to show page after a successful payment on a payment line system.
 *					The payment was already really recorded. So an error here must send warning to admin but must still infor user that payment is ok.
 *                  This page is called by payment system with url provided to it completed with parameter TOKEN=xxx
 *                  This token and session can be used to get more informations.
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
// Do not use GETPOST here, function is not defined and define must be done before including main.inc.php
// TODO This should be useless. Because entity must be retrieve from object ref and not from url.
$entity = (!empty($_GET['e']) ? (int) $_GET['e'] : (!empty($_POST['e']) ? (int) $_POST['e'] : 1));
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
dol_include_once('/stancer/lib/stancer.lib.php');
require_once DOL_DOCUMENT_ROOT . '/core/lib/company.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/payments.lib.php';
require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT . "/commande/class/commande.class.php";
if (file_exists(DOL_DOCUMENT_ROOT . '/eventorganization/class/conferenceorboothattendee.class.php')) {
	require_once DOL_DOCUMENT_ROOT . '/eventorganization/class/conferenceorboothattendee.class.php';
}
include_once DOL_DOCUMENT_ROOT . '/compta/paiement/class/paiement.class.php';

global $dolibarr_main_instance_unique_id;

$langs->loadLangs(array("main", "other", "dict", "bills", "companies", "paybox", "paypal", "stancer@stancer"));


$rawS = GETPOST('s', 'alpha');
dol_syslog("stancer paymentback START, raw s param length=" . strlen($rawS), LOG_DEBUG);
parse_str(base64_decode($rawS), $args);
dol_syslog("stancer paymentback decoded args=" . json_encode($args), LOG_DEBUG);

$socid = $pid = null;
$FULLTAG = $source = $ref = $securekey = $uuid = $service = "";
$bankaccountid = $paymentmethodId = $customerID = $societe = null;
// $suffix is only ever concatenated into a constant name, so it must be a string.
$suffix = '';

$sp = new Stancer_payments($db);
if (is_array($args) && !empty($args)) {
	$FULLTAG = $args['fulltag'] ?? ($args['tag'] ?? '');
	$source = $args['source'] ?? '';
	$ref = $args['ref'] ?? '';
	$securekey = $args['securekey'] ?? '';
	$uuid = $args['tag'] ?? '';
	$service = "stancer";

	dol_syslog("stancer paymentback fetch payment by uuid=$uuid", LOG_DEBUG);
	$res = $sp->fetch(0, null, null, $uuid);
	if ($res > 0) {
		dol_syslog("stancer paymentback fetch OK (by uuid), socid=" . $sp->fk_soc . ", uuid=$uuid, source=$source, ref=$ref, fulltag=$FULLTAG", LOG_DEBUG);
		$socid = $sp->fk_soc;
	} elseif (isset($_SESSION["stancer_payment_id"])) {
		dol_syslog("stancer paymentback fetch by uuid failed (res=$res), trying session stancer_payment_id=" . $_SESSION["stancer_payment_id"], LOG_DEBUG);
		$res = $sp->fetch(0, null, $_SESSION["stancer_payment_id"]);
		if ($res > 0) {
			dol_syslog("stancer paymentback fetch OK (by session), socid=" . $sp->fk_soc, LOG_DEBUG);
			$socid = $sp->fk_soc;
		} else {
			dol_syslog("stancer paymentback fetch FAILED (by session too), res=$res, uuid=$uuid, fulltag=$FULLTAG", LOG_ERR);
		}
	} else {
		dol_syslog("stancer paymentback fetch by uuid failed (res=$res) and no stancer_payment_id in session", LOG_ERR);
	}
} else {
	dol_syslog("stancer paymentback ERROR: no URI data (args is empty or not array)", LOG_ERR);
	accessforbidden('', 0, 0, 1);
}

// Detect $paymentmethod
$paymentmethod = 'stancer';
$reg = array();

dol_syslog("stancer paymentback  is called paymentmethod=" . $paymentmethod . " FULLTAG=" . $FULLTAG . " REQUEST_URI=" . $_SERVER["REQUEST_URI"], LOG_DEBUG, 0, '_payment');

$validpaymentmethod = array();
$validpaymentmethod['stancer'] = 'stancer';

// Security check
if (empty($socid)) {
	accessforbidden('', 0, 0, 1);
}

$ispaymentok = false;
// If payment is ok
$TRANSACTIONID = '';
// If payment is ko
$ErrorCode = $ErrorShortMsg = $ErrorLongMsg = $ErrorSeverityCode = $statusTXT = "";

$object = new stdClass(); // For triggers

$error = 0;

/*
 * Actions
 */


/*
 * View
 */

$now = dol_now();

dol_syslog("stancer paymentback query_string=" . (dol_escape_htmltag($_SERVER["QUERY_STRING"] ?? '') ?: '(empty)'), LOG_DEBUG);

$head = '';
if (getDolGlobalString('ONLINE_PAYMENT_CSS_URL', '') != '') {
	$head = '<link rel="stylesheet" type="text/css" href="' . getDolGlobalString('ONLINE_PAYMENT_CSS_URL') . '?lang=' . $langs->defaultlang . '">' . "\n";
}

$arrayofcss =  array(
	'/stancer/public/css/tailwind.min.css',
);

$conf->dol_hide_topmenu = 1;
$conf->dol_hide_leftmenu = 1;

$replacemainarea = (empty($conf->dol_hide_leftmenu) ? '<div>' : '') . '<div>';
llxHeader($head, $langs->trans("PaymentForm"), '', '', 0, 0, '', $arrayofcss, '', 'onlinepaymentbody', $replacemainarea);


// Show message
//print '<span id="dolpaymentspan"></span>'."\n";
print '<div id="dolpaymentdiv" class="center">' . "\n";


// Show logo (search order: logo defined by PAYMENT_LOGO_suffix, then PAYMENT_LOGO, then small company logo, large company logo, theme logo, common logo)
// Define logo and logosmall
$logosmall = $mysoc->logo_small;
$logo = $mysoc->logo;
$paramlogo = 'ONLINE_PAYMENT_LOGO_' . $suffix;
if (!empty($conf->global->$paramlogo)) {
	$logosmall = getDolGlobalString($paramlogo);
} elseif (getDolGlobalString('ONLINE_PAYMENT_LOGO', '') != '') {
	$logosmall = getDolGlobalString('ONLINE_PAYMENT_LOGO');
}
//print '<!-- Show logo (logosmall='.$logosmall.' logo='.$logo.') -->'."\n";
// Define urllogo
$urllogo = '';
$urllogofull = '';
if (!empty($logosmall) && is_readable($conf->mycompany->dir_output . '/logos/thumbs/' . $logosmall)) {
	$urllogo = DOL_URL_ROOT . '/viewimage.php?modulepart=mycompany&amp;entity=' . $conf->entity . '&amp;file=' . urlencode('logos/thumbs/' . $logosmall);
	$urllogofull = $dolibarr_main_url_root . '/viewimage.php?modulepart=mycompany&entity=' . $conf->entity . '&file=' . urlencode('logos/thumbs/' . $logosmall);
} elseif (!empty($logo) && is_readable($conf->mycompany->dir_output . '/logos/' . $logo)) {
	$urllogo = DOL_URL_ROOT . '/viewimage.php?modulepart=mycompany&amp;entity=' . $conf->entity . '&amp;file=' . urlencode('logos/' . $logo);
	$urllogofull = $dolibarr_main_url_root . '/viewimage.php?modulepart=mycompany&entity=' . $conf->entity . '&file=' . urlencode('logos/' . $logo);
}

$user = new User($db);
$stancerUserAccountId = getDolGlobalInt('STANCER_USER_ACCOUNT_FOR_ACTIONS');
dol_syslog("stancer paymentback fetch user for actions, STANCER_USER_ACCOUNT_FOR_ACTIONS=" . $stancerUserAccountId, LOG_DEBUG);
$res = $user->fetch($stancerUserAccountId);
if ($res <= 0) {
	dol_syslog("stancer paymentback user fetch failed (res=$res), fallback to user id 1", LOG_WARNING);
	$user->fetch(1);
}
// loadRights() only exists from Dolibarr 20; getrights() is the only call valid on 15..21.
// @phan-suppress-next-line PhanDeprecatedFunction
$user->getrights();
dol_syslog("stancer paymentback user loaded: id=" . $user->id . ", login=" . $user->login, LOG_DEBUG);

// Output html code for logo
if ($urllogo) {
	print '<div class="backgreypublicpayment">' . "\n";
	print '<div class="logopublicpayment">' . "\n";
	print '<img id="dolpaymentlogo" src="' . $urllogo . '"';
	print '>' . "\n";
	print '</div>' . "\n";
	if (getDolGlobalString('MAIN_HIDE_POWERED_BY', '') == '') {
		print '<div class="poweredbypublicpayment opacitymedium right"><a class="poweredbyhref" href="https://www.dolibarr.org?utm_medium=website&utm_source=poweredby" target="dolibarr" rel="noopener">' . $langs->trans("PoweredBy") . '<br><img class="poweredbyimg" src="' . DOL_URL_ROOT . '/theme/dolibarr_logo.svg" width="80px"></a></div>';
	}
	print '</div>' . "\n";
}
if (getDolGlobalString('MAIN_IMAGE_PUBLIC_PAYMENT', '') != '') {
	print '<div class="backimagepublicpayment">' . "\n";
	print '<img id="idMAIN_IMAGE_PUBLIC_PAYMENT" src="' . getDolGlobalString('MAIN_IMAGE_PUBLIC_PAYMENT') . '">' . "\n";
	print '</div>' . "\n";
}

dol_syslog("stancer paymentback checking stancer module enabled=" . (!empty($conf->stancer->enabled) ? '1' : '0') . ", paymentmethod=$paymentmethod", LOG_DEBUG);

if (!empty($conf->stancer->enabled)) {
	if ($paymentmethod == 'stancer') {
		$pid = $_SESSION["stancer_payment_id"] ?? '';
		dol_syslog("stancer paymentback session stancer_payment_id=" . $pid, LOG_DEBUG);

		$stancerApi = new StancerApi();
		$paymentData = $stancerApi->getPayment($pid);
		if ($paymentData === false) {
			dol_syslog("stancer paymentback API error fetching payment pid=$pid: " . $stancerApi->error . " (http=" . $stancerApi->lastHttpCode . ")", LOG_ERR);
			$status = '';
		} else {
			$status = isset($paymentData['status']) ? $paymentData['status'] : '';
			dol_syslog("stancer paymentback API OK, pid=$pid, status=$status", LOG_DEBUG);
		}
		$statusTXT = $sp->getLabelStatus($status);

		$listOfPaidStatus = [
			'authorized',
			'captured',
			'capture_sent',
			'to_capture'
		];

		if (in_array($status, $listOfPaidStatus)) {
			$ispaymentok = true;
			dol_syslog("stancer paymentback payment OK, status=$status", LOG_DEBUG);
			$method = isset($paymentData['method']) ? $paymentData['method'] : '';
			if ($method == "card" && isset($paymentData['card'])) {
				$cb = $paymentData['card'];

				$label = 'stancer-card-tst';
				if (getDolGlobalString('STANCER_IS_PROD', '0') == '1') {
					$label = 'stancer-card';
				}

				$dataUpdate = [
					'socid' => $socid,
					'fk_soc' => $socid,
					'label' => $label,
					'bank' => $db->escape(isset($cb['brand']) ? $cb['brand'] : ''),
					'stancer_account' => $db->escape($customerID),
					'stancer_object_ref' => $db->escape(isset($cb['id']) ? $cb['id'] : ''),
					'last_four' => $db->escape(isset($cb['last4']) ? $cb['last4'] : ''),
					'number' => 0000,
					'proprio' => $db->escape(isset($cb['name']) ? $cb['name'] : ''),
					'exp_date_month' => $db->escape(isset($cb['exp_month']) ? $cb['exp_month'] : ''),
					'exp_date_year' => $db->escape(isset($cb['exp_year']) ? $cb['exp_year'] : ''),
					'cvn' => null,
					'card_type' => $db->escape(isset($cb['brand']) ? $cb['brand'] : ''),
					'type' => 'card',
					'entity' => $conf->entity,
					'country_code' => $db->escape(isset($cb['country']) ? $cb['country'] : ''),
					'status' => 1,
					'default_rib' => 1,
				];

				$compPayModeId = stancerAddCompanyPaymentModeifNeeded($dataUpdate);
				if ($compPayModeId < 0) {
					dol_syslog("stancer paymentback card payment mode creation error", LOG_ERR);
				} else {
					dol_syslog("stancer paymentback card payment mode created/updated, id=$compPayModeId", LOG_DEBUG);
				}

				$_SESSION["paymentType"] = "CB";
			}
			$_SESSION["TRANSACTIONID"] = isset($paymentData['id']) ? $paymentData['id'] : $pid;
			$_SESSION["FinalPaymentAmt"] = isset($paymentData['amount']) ? $paymentData['amount'] / 100 : 0;
			$_SESSION["FinalFees"] = isset($paymentData['fee']) ? $paymentData['fee'] : 0; // cents (divided once at consumption)
			$statusTXT = $langs->trans('stancer_status_to_capture');
		} else {
			dol_syslog("stancer paymentback payment NOT OK, status=$status", LOG_WARNING);
			$ErrorCode = $status;
			$statusTXT = $status;
		}

		// Detect already paid (F5 on payment ok)
		$transId = $_SESSION["TRANSACTIONID"] ?? '';
		dol_syslog("stancer paymentback checking duplicate payment, TRANSACTIONID=$transId", LOG_DEBUG);
		$sql = "SELECT * FROM " . MAIN_DB_PREFIX . "paiement WHERE ext_payment_id='" . $db->escape($transId) . "'";
		$resql = $db->query($sql);
		if ($resql) {
			$num = $db->num_rows($resql);
			if ($num > 0) {
				$error++;
				dol_syslog("stancer paymentback RELOAD DETECTED, payment already exists for TRANSACTIONID=$transId", LOG_WARNING);
				print "<p class=''>" . $langs->transnoentitiesnoconv("StancerReloadDetected", dol_print_url($mysoc->url, '_blank', 0, 1)) . "</p>";
				print "\n</div>\n";
				llxFooter('', 'public', 1);
				$db->close();
				exit;
			}
		}

		// Update Stancer_Payment db
		if ($paymentData !== false) {
			$resFill = $sp->fillDataFromApi($paymentData);
			if ($resFill < 0) {
				dol_syslog("stancer paymentback fillDataFromApi error: $resFill", LOG_ERR);
			} else {
				// If the upstream fetch didn't find a local row (paym created out of
				// stancerCBstartPay, eg via a shared payment link), we MUST insert it
				// here so that subsequent stancerCheckIfPaymentInProgress() sees this
				// paym and the cron does not retry it on the next night. Without this
				// the table llx_stancer_stancer_payments stays empty for that paym_id
				// and the anti-doublon is blind -> double billing risk.
				if (empty($sp->id)) {
					if (empty($sp->live_mode)) {
						$sp->live_mode = getDolGlobalString('STANCER_IS_PROD', '0');
					}
					if (empty($sp->fk_soc) && !empty($socid)) {
						$sp->fk_soc = (int) $socid;
					}
					$res2 = $sp->create($user, true);
					dol_syslog("stancer paymentback no local row found upstream, INSERT new row, res=$res2", LOG_INFO);
				} else {
					$res2 = $sp->update($user);
					dol_syslog("stancer paymentback payment record updated, res=$res2", LOG_DEBUG);
				}
			}
		}
	}
} else {
	dol_syslog("stancer paymentback WARNING: stancer module not enabled in conf", LOG_WARNING);
}

$action = '';
$parameters = [
	'paymentmethod' => $paymentmethod,
];

// If data not provided from back url, search them into the session env.
// $ipaddress is never set earlier in this script: read it straight from the session.
$ipaddress = isset($_SESSION['ipaddress']) ? $_SESSION['ipaddress'] : '';
if ($ipaddress === '') {
	dol_syslog("stancer paymentback no ipaddress in session, payment notes will not carry the customer IP", LOG_WARNING);
}
if (empty($TRANSACTIONID)) {
	$TRANSACTIONID   = $_SESSION['TRANSACTIONID'];
}
if (empty($FinalPaymentAmt)) {
	$FinalPaymentAmt = $_SESSION["FinalPaymentAmt"];
}
if (empty($paymentType)) {
	$paymentType     = $_SESSION["paymentType"];
}

if (empty($currencyCodeType)) {
	$currencyCodeType = $_SESSION['currencyCodeType'];
}

$fulltag = $FULLTAG;
$tmptag = dolExplodeIntoArray($fulltag, '.', '=');

dol_syslog("stancer paymentback ispaymentok=" . ($ispaymentok ? '1' : '0') . ", TRANSACTIONID=$TRANSACTIONID, FinalPaymentAmt=$FinalPaymentAmt, paymentType=$paymentType, fulltag=$fulltag, tmptag=" . json_encode($tmptag), LOG_DEBUG);


// Make complementary actions
dol_syslog("stancer paymentback starting post-actions, ispaymentok=" . ($ispaymentok ? '1' : '0') . ", tmptag keys=" . implode(',', array_keys($tmptag)), LOG_DEBUG);
$ispostactionok = 0;
$postactionmessages = array();
if ($ispaymentok) {
	// Set permission for the anonymous user
	if (empty($user->rights->societe)) {
		$user->rights->societe = new stdClass();
	}
	if (empty($user->rights->facture)) {
		$user->rights->facture = new stdClass();
	}
	if (empty($user->rights->adherent)) {
		$user->rights->adherent = new stdClass();
	}
	if (empty($user->rights->adherent->cotisation)) {
		$user->rights->adherent->cotisation = new stdClass();
	}
	$user->rights->societe->creer = 1;
	$user->rights->facture->creer = 1;
	$user->rights->adherent->cotisation->creer = 1;

	//TODO gestion des adhésions
	// if (array_key_exists('MEM', $tmptag) && $tmptag['MEM'] > 0) {
	//     // Validate member
	//     // Create subscription
	//     // Create complementary actions (this include creation of thirdparty)
	//     // Send confirmation email

	//     $defaultdelay = 1;
	//     $defaultdelayunit = 'y';

	//     // Record subscription
	//     include_once DOL_DOCUMENT_ROOT.'/adherents/class/adherent.class.php';
	//     include_once DOL_DOCUMENT_ROOT.'/adherents/class/adherent_type.class.php';
	//     include_once DOL_DOCUMENT_ROOT.'/adherents/class/subscription.class.php';
	//     $adht = new AdherentType($db);
	//     $object = new AdherentStancer($db);

	//     $result1 = $object->fetch($tmptag['MEM']);
	//     $result2 = $adht->fetch($object->typeid);

	//     dol_syslog("stancer paymentback We have to process member with id=".$tmptag['MEM']." result1=".$result1." result2=".$result2, LOG_DEBUG, 0, '_payment');

	//     if ($result1 > 0 && $result2 > 0) {
	//         $paymentTypeId = 0;
	//             $paymentType = $_SESSION["paymentType"];
	//             if (empty($paymentType)) {
	//                 $paymentType = 'CB';
	//             }
	//             $paymentTypeId = dol_getIdFromCode($db, $paymentType, 'c_paiement', 'code', 'id', 1);

	//         $currencyCodeType = $_SESSION['currencyCodeType'];

	//         dol_syslog("stancer paymentback FinalPaymentAmt=".$FinalPaymentAmt." paymentTypeId=".$paymentTypeId, LOG_DEBUG, 0, '_payment');

	//         // Do action only if $FinalPaymentAmt is set (session variable is cleaned after this page to avoid duplicate actions when page is POST a second time)
	//         if (!empty($FinalPaymentAmt) && $paymentTypeId > 0) {
	//             $result = ($object->status == $object::STATUS_EXCLUDED) ? -1 : $object->validate($user); // if membre is excluded (status == -2) the new validation is not possible
	//             if ($result < 0 || empty($object->datevalid)) {
	//                 $error++;
	//                 $errmsg = $object->error;
	//                 $postactionmessages[] = $errmsg;
	//                 $postactionmessages = array_merge($postactionmessages, $object->errors);
	//                 $ispostactionok = -1;
	//                 dol_syslog("stancer paymentback Failed to validate member: ".$errmsg, LOG_ERR, 0, '_payment');
	//             }

	//             // Subscription informations
	//             $datesubscription = $object->datevalid;
	//             if ($object->datefin > 0) {
	//                 $datesubscription = dol_time_plus_duree($object->datefin, 1, 'd');
	//             }

	//             $datesubend = null;
	//             if ($datesubscription && $defaultdelay && $defaultdelayunit) {
	//                 $datesubend = dol_time_plus_duree($datesubscription, $defaultdelay, $defaultdelayunit);
	//                 // the new end date of subscription must be in futur
	//                 while ($datesubend < $now) {
	//                     $datesubend = dol_time_plus_duree($datesubend, $defaultdelay, $defaultdelayunit);
	//                     $datesubscription = dol_time_plus_duree($datesubscription, $defaultdelay, $defaultdelayunit);
	//                 }
	//                 $datesubend = dol_time_plus_duree($datesubend, -1, 'd');
	//             }

	//             $paymentdate = $now;
	//             $amount = $FinalPaymentAmt;
	//             $label = 'Online subscription '.dol_print_date($now, 'standard').' using '.$paymentmethod.' from '.$ipaddress.' - Transaction ID = '.$TRANSACTIONID;

	//             // Payment informations
	//             $accountid = 0;
	//             if ($paymentmethod == 'stancer') {
	//                 $accountid = getDolGlobalString('STANCER_BANK_ACCOUNT_FOR_PAYMENTS');
	//             }
	//             if ($accountid < 0) {
	//                 $error++;
	//                 $errmsg = 'Setup of bank account to use for payment is not correctly done for payment method '.$paymentmethod;
	//                 $postactionmessages[] = $errmsg;
	//                 $ispostactionok = -1;
	//                 dol_syslog("stancer paymentback Failed to get the bank account to record payment: ".$errmsg, LOG_ERR, 0, '_payment');
	//             }

	//             $operation = $paymentType; // Payment mode code
	//             $num_chq = '';
	//             $emetteur_nom = '';
	//             $emetteur_banque = '';
	//             // Define default choice for complementary actions
	//             $option = '';
	//             if (getDolGlobalString('ADHERENT_BANK_USE','') != '' && $conf->global->ADHERENT_BANK_USE == 'bankviainvoice' && !empty($conf->banque->enabled) && !empty($conf->societe->enabled) && !empty($conf->facture->enabled)) {
	//                 $option = 'bankviainvoice';
	//             } elseif (getDolGlobalString('ADHERENT_BANK_USE','') != '' && $conf->global->ADHERENT_BANK_USE == 'bankdirect' && !empty($conf->banque->enabled)) {
	//                 $option = 'bankdirect';
	//             } elseif (getDolGlobalString('ADHERENT_BANK_USE','') != '' && $conf->global->ADHERENT_BANK_USE == 'invoiceonly' && !empty($conf->banque->enabled) && !empty($conf->societe->enabled) && !empty($conf->facture->enabled)) {
	//                 $option = 'invoiceonly';
	//             }
	//             if (empty($option)) {
	//                 $option = 'none';
	//             }
	//             $sendalsoemail = 1;

	//             // Record the subscription then complementary actions
	//             $db->begin();

	//             // Create subscription
	//             if (!$error) {
	//                 dol_syslog("stancer paymentback Call ->subscription to create subscription", LOG_DEBUG, 0, '_payment');

	//                 $crowid = $object->subscription($datesubscription, $amount, $accountid, $operation, $label, $num_chq, $emetteur_nom, $emetteur_banque, $datesubend, $membertypeid);
	//                 if ($crowid <= 0) {
	//                     $error++;
	//                     $errmsg = $object->error;
	//                     $postactionmessages[] = $errmsg;
	//                     $ispostactionok = -1;
	//                 } else {
	//                     $postactionmessages[] = 'Subscription created (id='.$crowid.')';
	//                     $ispostactionok = 1;
	//                 }
	//             }

	//             if (!$error) {
	//                 dol_syslog("stancer paymentback Call ->subscriptionComplementaryActions option=".$option, LOG_DEBUG, 0, '_payment');

	//                 $autocreatethirdparty = 1; // will create thirdparty if member not yet linked to a thirdparty

	//                 $result = $object->subscriptionComplementaryActions($crowid, $option, $accountid, $datesubscription, $paymentdate, $operation, $label, $amount, $num_chq, $emetteur_nom, $emetteur_banque, $autocreatethirdparty, $TRANSACTIONID, $service);
	//                 if ($result < 0) {
	//                     dol_syslog("stancer paymentback Error ".$object->error." ".join(',', $object->errors), LOG_DEBUG, 0, '_payment');

	//                     $error++;
	//                     $postactionmessages[] = $object->error;
	//                     $postactionmessages = array_merge($postactionmessages, $object->errors);
	//                     $ispostactionok = -1;
	//                 } else {
	//                     if ($option == 'bankviainvoice') {
	//                         $postactionmessages[] = 'Invoice, payment and bank record created';
	//                         dol_syslog("stancer paymentback Invoice, payment and bank record created", LOG_DEBUG, 0, '_payment');
	//                     }
	//                     if ($option == 'bankdirect') {
	//                         $postactionmessages[] = 'Bank record created';
	//                         dol_syslog("stancer paymentback Bank record created", LOG_DEBUG, 0, '_payment');
	//                     }
	//                     if ($option == 'invoiceonly') {
	//                         $postactionmessages[] = 'Invoice recorded';
	//                         dol_syslog("stancer paymentback Invoice recorded", LOG_DEBUG, 0, '_payment');
	//                     }
	//                     $ispostactionok = 1;

	//                     // If an invoice was created, it is into $object->invoice
	//                 }
	//             }

	//             if (!$error) {
	//                 if ($paymentmethod == 'stancer' && $autocreatethirdparty && $option == 'bankviainvoice') {
	//                     $thirdparty_id = $object->fk_soc;

	//                     dol_syslog("stancer paymentback Search existing Stancer customer profile for thirdparty_id=".$thirdparty_id, LOG_DEBUG, 0, '_payment');

	//                     $service = 'StripeTest';
	//                     $servicestatus = 0;
	//                     if (getDolGlobalString('STRIPE_LIVE','') != '' && !GETPOST('forcesandbox', 'alpha')) {
	//                         $service = 'StripeLive';
	//                         $servicestatus = 1;
	//                     }
	//                     $stripeacc = null; // No Oauth/connect use for public pages

	//                     $thirdparty = new Societe($db);
	//                     $thirdparty->fetch($thirdparty_id);

	//                     include_once DOL_DOCUMENT_ROOT.'/stripe/class/stripe.class.php';	// This also set $stripearrayofkeysbyenv
	//                     $stripe = new Stripe($db);
	//                     //$stripeacc = $stripe->getStripeAccount($service);		Already defined previously

	//                     $customer = $stripe->customerStripe($thirdparty, $stripeacc, $servicestatus, 0);

	//                     if (!$customer && $TRANSACTIONID) {	// Not linked to a stripe customer, we make the link
	//                         dol_syslog("stancer paymentback No stripe profile found, so we add it for TRANSACTIONID = ".$TRANSACTIONID, LOG_DEBUG, 0, '_payment');

	//                         try {
	//                             global $stripearrayofkeysbyenv;
	//                             \Stripe\Stripe::setApiKey($stripearrayofkeysbyenv[$servicestatus]['secret_key']);

	//                             if (preg_match('/^pi_/', $TRANSACTIONID)) {
	//                                 // This may throw an error if not found.
	//                                 $chpi = \Stripe\PaymentIntent::retrieve($TRANSACTIONID);	// payment_intent (pi_...)
	//                             } else {
	//                                 // This throw an error if not found
	//                                 $chpi = \Stripe\Charge::retrieve($TRANSACTIONID); // old method, contains the charge id (ch_...)
	//                             }

	//                             if ($chpi) {
	//                                 $stripecu = $chpi->customer; // value 'cus_....'. WARNING: This property may be empty if first payment was recorded before the stripe customer was created.

	//                                 if (empty($stripecu)) {
	//                                     // This include the INSERT
	//                                     $customer = $stripe->customerStripe($thirdparty, $stripeacc, $servicestatus, 1);

	//                                     // Link this customer to the payment intent
	//                                     if (preg_match('/^pi_/', $TRANSACTIONID) && $customer) {
	//                                         \Stripe\PaymentIntent::update($chpi->id, array('customer' => $customer->id));
	//                                     }
	//                                 } else {
	//                                     $sql = "INSERT INTO ".MAIN_DB_PREFIX."societe_account (fk_soc, login, key_account, site, site_account, status, entity, date_creation, fk_user_creat)";
	//                                     $sql .= " VALUES (".$object->fk_soc.", '', '".$db->escape($stripecu)."', 'stripe', '".$db->escape($stripearrayofkeysbyenv[$servicestatus]['publishable_key'])."', ".$servicestatus.", ".$conf->entity.", '".$db->idate(dol_now())."', 0)";
	//                                     $resql = $db->query($sql);
	//                                     if (!$resql) {	// should not happen
	//                                         $error++;
	//                                         $errmsg = 'stancer paymentback Failed to insert customer stripe id in database : '.$db->lasterror();
	//                                         dol_syslog($errmsg, LOG_ERR, 0, '_payment');
	//                                         $postactionmessages[] = $errmsg;
	//                                         $ispostactionok = -1;
	//                                     }
	//                                 }
	//                             } else {	// should not happen
	//                                 $error++;
	//                                 $errmsg = 'stancer paymentback Failed to retreive paymentintent or charge from id';
	//                                 dol_syslog($errmsg, LOG_ERR, 0, '_payment');
	//                                 $postactionmessages[] = $errmsg;
	//                                 $ispostactionok = -1;
	//                             }
	//                         } catch (Exception $e) {	// should not happen
	//                             $error++;
	//                             $errmsg = 'stancer paymentback Failed to get or save customer stripe id in database : '.$e->getMessage();
	//                             dol_syslog($errmsg, LOG_ERR, 0, '_payment');
	//                             $postactionmessages[] = $errmsg;
	//                             $ispostactionok = -1;
	//                         }
	//                     }
	//                 }
	//             }

	//             if (!$error) {
	//                 $db->commit();
	//             } else {
	//                 $db->rollback();
	//             }

	//             // Send email to member
	//             if (!$error) {
	//                 dol_syslog("stancer paymentback Send email to customer to ".$object->email." if we have to (sendalsoemail = ".$sendalsoemail.")", LOG_DEBUG, 0, '_payment');

	//                 // Send confirmation Email
	//                 if ($object->email && $sendalsoemail) {
	//                     $subject = '';
	//                     $msg = '';

	//                     // Send subscription email
	//                     include_once DOL_DOCUMENT_ROOT.'/core/class/html.formmail.class.php';
	//                     $formmail = new FormMail($db);
	//                     // Set output language
	//                     $outputlangs = new Translate('', $conf);
	//                     $outputlangs->setDefaultLang(empty($object->thirdparty->default_lang) ? $mysoc->default_lang : $object->thirdparty->default_lang);
	//                     // Load traductions files required by page
	//                     $outputlangs->loadLangs(array("main", "members"));
	//                     // Get email content from template
	//                     $arraydefaultmessage = null;
	//                     $labeltouse = $conf->global->ADHERENT_EMAIL_TEMPLATE_SUBSCRIPTION;

	//                     if (!empty($labeltouse)) {
	//                         $arraydefaultmessage = $formmail->getEMailTemplate($db, 'member', $user, $outputlangs, 0, 1, $labeltouse);
	//                     }

	//                     if (!empty($labeltouse) && is_object($arraydefaultmessage) && $arraydefaultmessage->id > 0) {
	//                         $subject = $arraydefaultmessage->topic;
	//                         $msg     = $arraydefaultmessage->content;
	//                     }

	//                     $substitutionarray = getCommonSubstitutionArray($outputlangs, 0, null, $object);

	//                     // Create external user
	//                     if (getDolGlobalString('ADHERENT_CREATE_EXTERNAL_USER_LOGIN','') != '') {
	//                         $infouserlogin = '';
	//                         $nuser = new User($db);
	//                         $tmpuser = dol_clone($object);

	//                         $result = $nuser->create_from_member($tmpuser, $object->login);
	//                         $newpassword = $nuser->setPassword($user, '');

	//                         if ($result < 0) {
	//                             $outputlangs->load("errors");
	//                             $postactionmessages[] = 'Error in create external user : '.$nuser->error;
	//                         } else {
	//                             $infouserlogin = $outputlangs->trans("Login").': '.$nuser->login.' '."\n".$outputlangs->trans("Password").': '.$newpassword;
	//                             $postactionmessages[] = $langs->trans("NewUserCreated", $nuser->login);
	//                         }
	//                         $substitutionarray['__MEMBER_USER_LOGIN_INFORMATION__'] = $infouserlogin;
	//                     }

	//                     complete_substitutions_array($substitutionarray, $outputlangs, $object);
	//                     $subjecttosend = make_substitutions($subject, $substitutionarray, $outputlangs);
	//                     $texttosend = make_substitutions(dol_concatdesc($msg, $adht->getMailOnSubscription()), $substitutionarray, $outputlangs);

	//                     // Attach a file ?
	//                     $file = '';
	//                     $listofpaths = array();
	//                     $listofnames = array();
	//                     $listofmimes = array();
	//                     if (is_object($object->invoice)) {
	//                         $invoicediroutput = $conf->facture->dir_output;
	//                         $fileparams = dol_most_recent_file($invoicediroutput.'/'.$object->invoice->ref, preg_quote($object->invoice->ref, '/').'[^\-]+');
	//                         $file = $fileparams['fullname'];

	//                         $listofpaths = array($file);
	//                         $listofnames = array(basename($file));
	//                         $listofmimes = array(dol_mimetype($file));
	//                     }

	//                     $moreinheader = 'X-Dolibarr-Info: send_an_email by public/payment/paymentok.php'."\r\n";

	//                     $result = $object->send_an_email($texttosend, $subjecttosend, $listofpaths, $listofmimes, $listofnames, "", "", 0, -1, "", $moreinheader);

	//                     if ($result < 0) {
	//                         $errmsg = $object->error;
	//                         $postactionmessages[] = $errmsg;
	//                         $ispostactionok = -1;
	//                     } else {
	//                         if ($file) {
	//                             $postactionmessages[] = 'Email sent to member (with invoice document attached)';
	//                         } else {
	//                             $postactionmessages[] = 'Email sent to member (without any attached document)';
	//                         }

	//                         // TODO Add actioncomm event
	//                     }
	//                 }
	//             }
	//         } else {
	//             $postactionmessages[] = 'Failed to get a valid value for "amount paid" or "payment type" to record the payment of subscription for member '.$tmptag['MEM'].'. May be payment was already recorded.';
	//             $ispostactionok = -1;
	//         }
	//     } else {
	//         $postactionmessages[] = 'Member '.$tmptag['MEM'].' for subscription paid was not found';
	//         $ispostactionok = -1;
	//     }
	// } else

	//facture
	if (array_key_exists('INV', $tmptag) && $tmptag['INV'] > 0) {
		dol_syslog("stancerPaymentBack is invoice", LOG_DEBUG, 0, '_payment');
		// Record payment
		$object = new Facture($db);
		// fetch() returns 1 when found, 0 when not found and <0 on error. It returns
		// -1 right away for an id that casts to 0, which a tampered or malformed tag
		// can produce ('INV=FA2304-1134' passes the >0 test above under PHP 8), so
		// only a strictly positive result means $object really holds an invoice.
		$result = $object->fetch((int) $tmptag['INV']);
		// print "<p>facture 1</p>";
		if ($result > 0) {
			// M2: the recorded amount must come from the Stancer API (source of
			// truth), not the session (seeded from a GETPOST in newpayment). The
			// API amount is an integer number of cents, so /100 is exact. Log any
			// divergence and never record more than what was actually captured.
			$sessionAmt = (float) $_SESSION["FinalPaymentAmt"];
			$FinalPaymentAmt = isset($paymentData['amount']) ? ((float) $paymentData['amount'] / 100) : $sessionAmt;
			if (isset($paymentData['amount']) && abs($FinalPaymentAmt - $sessionAmt) > 0.01) {
				dol_syslog("stancer paymentback: recorded amount mismatch session=" . $sessionAmt
					. " api=" . $FinalPaymentAmt . " for payment " . $_SESSION["stancer_payment_id"]
					. ", using the API amount", LOG_ERR);
			}
			$paymentType = $_SESSION["paymentType"];
			if (empty($paymentType)) {
				$paymentType = 'CB';
			}
			$paymentTypeId = dol_getIdFromCode($db, $paymentType, 'c_paiement', 'code', 'id', 1);

			$label = '(CustomerInvoicePayment)';
			if ($object->type == Facture::TYPE_CREDIT_NOTE) {
				$label = '(CustomerInvoicePaymentBack)'; // Refund of a credit note
			}

			$apiCustomerIdPb = null;
			if (isset($paymentData['customer'])) {
				$apiCustomerIdPb = is_array($paymentData['customer'])
					? (isset($paymentData['customer']['id']) ? $paymentData['customer']['id'] : null)
					: $paymentData['customer'];
			}
			$data = [
				'payment_id' => $_SESSION["stancer_payment_id"],
				'date' => $now,
				'FinalPaymentAmt' => $FinalPaymentAmt,
				'paymentTypeId' => $paymentTypeId,
				'ipaddress' => $ipaddress,
				'TRANSACTIONID' => $TRANSACTIONID,
				'service' => $service,
				'paymentmethod' => $paymentmethod,
				'label' => $label,
				'FinalFees' => $_SESSION['FinalFees'],
				'ref' => $object->ref,
				// Propagated for stancerAddPaymentOnObject misattribution guards. These
				// catch the scenario where the FULLTAG of the return URL
				// points at one invoice but the Stancer paym_id was issued for another.
				'api_order_id' => isset($paymentData['order_id']) ? $paymentData['order_id'] : null,
				'api_customer_id' => $apiCustomerIdPb,
			];

			$errorMessage = "";
			$error = stancerAddPaymentOnObject($object, $data, $errorMessage);

			if (!$error) {
				//erics update payment method on invoice, like #14
				dol_syslog("stancer update invoice with bankAccount and paymentType", LOG_DEBUG, 0, '_payment');
				// setBankAccount() writes fk_account unconditionally, so an unset constant
				// would wipe the bank account already carried by the invoice. Same guard as
				// the order branch below.
				$bankaccountid = getDolGlobalInt('STANCER_BANK_ACCOUNT_FOR_PAYMENTS');
				$paymentmethodId = dol_getIdFromCode($db, 'CB', 'c_paiement', 'code', 'id', 1);
				$object->setPaymentMethods($paymentmethodId);
				if ($bankaccountid > 0) {
					$object->setBankAccount($bankaccountid);
				} else {
					dol_syslog("stancer STANCER_BANK_ACCOUNT_FOR_PAYMENTS is not set, bank account of invoice " . $object->ref . " left unchanged", LOG_WARNING, 0, '_payment');
				}
				$object->update($user, 1);

				if (getDolGlobalString('STANCER_CB_AS_PAID', '') != '') {
					$object->setPaid($user);
				}

				//Envoi de la facture au client
				if (getDolGlobalString('STANCER_AUTO_MAIL_INVOICES_CB', '') != '') {
					stancerSendInvoiceMailModele(getDolGlobalString('STANCER_AUTO_MAIL_INVOICES_CB_MAILTYPE', ''), $object, 'BILL_PAYED_SENTBYMAIL');
				}
			}
		} else {
			dol_syslog("stancer paymentback invoice " . $tmptag['INV'] . " was not found (fetch returned $result), payment not recorded", LOG_ERR, 0, '_payment');
			$postactionmessages[] = 'Invoice paid ' . $tmptag['INV'] . ' was not found';
			$ispostactionok = -1;
		}
	} elseif (array_key_exists('ORD', $tmptag) && $tmptag['ORD'] > 0) {
		dol_syslog("stancerPaymentBack is order", LOG_DEBUG, 0, '_payment');
		//commande
		$object = new Commande($db);
		// Same contract as Facture::fetch(): 1 found, 0 not found, <0 on error, and
		// -1 right away for an id that casts to 0. Test the sign, not the truthiness.
		$result = $object->fetch((int) $tmptag['ORD']);
		if ($result > 0) {
			$FinalPaymentAmt = $_SESSION["FinalPaymentAmt"];
			//if partialPayment=1 -> make a deposit
			$partialPayment = $_SESSION["partialPayment"];

			$paymentTypeId = 0;
			if ($paymentmethod == 'stancer') {
				$paymentTypeId = getDolGlobalString('STANCER_PAYMENT_MODE_FOR_PAYMENTS');
			}
			if ($paymentmethod == 'paybox') {
				$paymentTypeId = getDolGlobalString('PAYBOX_PAYMENT_MODE_FOR_PAYMENTS');
			}
			if ($paymentmethod == 'paypal') {
				$paymentTypeId = getDolGlobalString('PAYPAL_PAYMENT_MODE_FOR_PAYMENTS');
			}
			if ($paymentmethod == 'stripe') {
				$paymentTypeId = getDolGlobalString('STRIPE_PAYMENT_MODE_FOR_PAYMENTS');
			}
			if (empty($paymentTypeId)) {
				dol_syslog("stancer paymentType = " . $paymentType, LOG_DEBUG, 0, '_payment');

				if (empty($paymentType)) {
					$paymentType = 'CB';
				}
				// May return nothing when paymentType means nothing
				// (for example when paymentType is 'Mark', 'Sole', 'Sale', for paypal)
				$paymentTypeId = dol_getIdFromCode($db, $paymentType, 'c_paiement', 'code', 'id', 1);

				// If previous line has returned nothing, we force to get the ID of payment of Credit Card (hard coded code 'CB').
				if (empty($paymentTypeId) || $paymentTypeId < 0) {
					$paymentTypeId = dol_getIdFromCode($db, 'CB', 'c_paiement', 'code', 'id', 1);
				}
			}

			if ($paymentTypeId) {
				//update order payment type, like #14
				dol_syslog("stancer update order paymentType", LOG_DEBUG, 0, '_payment');
				// This branch never fed $bankaccountid, so setBankAccount() received null
				// and wiped the bank account of the order. Read the constant here, exactly
				// like the invoice branch does, and never overwrite with an empty account.
				$bankaccountid = getDolGlobalInt('STANCER_BANK_ACCOUNT_FOR_PAYMENTS');
				$paymentmethodId = dol_getIdFromCode($db, 'CB', 'c_paiement', 'code', 'id', 1);
				$object->setPaymentMethods($paymentmethodId);
				if ($bankaccountid > 0) {
					$object->setBankAccount($bankaccountid);
				} else {
					dol_syslog("stancer STANCER_BANK_ACCOUNT_FOR_PAYMENTS is not set, bank account of order " . $object->ref . " left unchanged", LOG_WARNING, 0, '_payment');
				}
				$object->update($user);
			}


			// Do action only if $FinalPaymentAmt is set (session variable is cleaned after this page to avoid duplicate actions when page is POST a second time)
			if (stancerIsModEnabled('invoice')) {
				$invoice = null;
				if (
					$partialPayment == 1
					&& getDolGlobalString('STANCER_CB_ORDER_PARTIAL_PAY') != ''
					&& isset($object->deposit_percent) && $object->deposit_percent > 0 && $object->deposit_percent < 100
				) {
					$amount = ($object->total_ttc * $object->deposit_percent / 100);
					dol_syslog("stancerPaymentBack create deposit invoice", LOG_DEBUG, 0, '_payment');
					// @phpstan-ignore-next-line
					$invoice = Facture::createDepositFromOrigin($object, $now, (int) $object->cond_reglement_id, $user, 0, true);

					if ($invoice) {
						dol_syslog("stancerPaymentBack deposit invoice done", LOG_DEBUG, 0, '_payment');
					} else {
						$error++;
						dol_syslog("stancer Failed to create deposit invoice form order " . $tmptag['ORD'] . ", result=$result.", LOG_ERR);
						$postactionmessages[] = 'Failed to create deposit invoice form order ' . $tmptag['ORD'] . '.';
						$ispostactionok = -1;
					}
				}

				dol_syslog("stancerPaymentBack facture module is enabled, create invoice", LOG_DEBUG, 0, '_payment');
				if ($invoice === null && !empty($FinalPaymentAmt) && $paymentTypeId > 0) {
					$invoice = new Facture($db);
					$result = $invoice->createFromOrder($object, $user);
					if ($result > 0) {
						dol_syslog("stancerPaymentBack invoice from order done", LOG_DEBUG, 0, '_payment');
					} else {
						$error++;
						dol_syslog("stancer Failed to create invoice form order " . $tmptag['ORD'] . ", result=$result.", LOG_ERR);
						$postactionmessages[] = 'Failed to create invoice form order ' . $tmptag['ORD'] . '.';
						$ispostactionok = -1;
					}
				}

				if ($invoice != null) {
					if ($partialPayment) {
						//do not classiy billed order in case of deposit invoice !
					} else {
						$object->classifyBilled($user);
					}
					// setBankAccount() writes fk_account unconditionally, so an unset constant
					// would wipe the bank account already carried by the invoice. Same guard as
					// the other branches of this page.
					$bankaccountid = getDolGlobalInt('STANCER_BANK_ACCOUNT_FOR_PAYMENTS');
					$paymentmethodId = dol_getIdFromCode($db, 'CB', 'c_paiement', 'code', 'id', 1);
					$invoice->setPaymentMethods($paymentmethodId);
					if ($bankaccountid > 0) {
						$invoice->setBankAccount($bankaccountid);
					} else {
						dol_syslog("stancer STANCER_BANK_ACCOUNT_FOR_PAYMENTS is not set, bank account of invoice " . $invoice->ref . " left unchanged", LOG_WARNING, 0, '_payment');
					}
					//$invoice->update($user); //validate do the job?

					if ($invoice->status != Facture::STATUS_VALIDATED && $invoice->status != Facture::STATUS_CLOSED) {
						$invoiceValidate = $invoice->validate($user);
						if (is_numeric($invoiceValidate) &&  $invoiceValidate <= 0) {
							dol_syslog("stancer validate invoice ref=" . $invoice->ref . ", validate code is = " . $invoiceValidate . " let me try without trigger", LOG_ERR);
							$invoiceValidate = $invoice->validate($user, '', 0, 1, 0); //try cf ops
							dol_syslog("stancer validate invoice ref is now=" . $invoice->ref . ", validate code is = " . $invoiceValidate, LOG_ERR);
						} else {
							dol_syslog("stancerPaymentBack invoice validated ok", LOG_DEBUG, 0, '_payment');
						}
					}

					// Creation of payment line
					$paiement = new Paiement($db);
					$paiement->datepaye = $now;
					if ($currencyCodeType == $conf->currency) {
						$paiement->amounts = array($invoice->id => $FinalPaymentAmt); // Array with all payments dispatching with invoice id
					} else {
						$paiement->multicurrency_amounts = array($invoice->id => $FinalPaymentAmt); // Array with all payments dispatching
						$postactionmessages[] = 'Payment was done in a different currency that currency expected of company';
						$ispostactionok = -1;
						$error++;
					}
					$paiement->paiementid = $paymentTypeId;
					$paiement->num_payment = $_SESSION["stancer_payment_id"]; //erics was ''
					$paiement->note_public = 'Online payment ' . dol_print_date($now, 'standard') . ' from ' . $ipaddress;
					$paiement->ext_payment_id = $TRANSACTIONID;
					$paiement->ext_payment_site = 'stancer'; // E4: must be 'stancer' so reconciliation recognizes the posting

					if (!$error) {
						$paiement_id = $paiement->create($user, 1); // This include closing invoices and regenerating documents
						if ($paiement_id < 0) {
							dol_syslog("stancer Failed to create payment : " . $paiement->error, LOG_ERR);
							$postactionmessages[] = $paiement->error . ' ' . implode("<br>\n", array_filter($paiement->errors, 'strlen'));
							$ispostactionok = -1;
							$error++;
						} else {
							dol_syslog("stancer payment created");
							$postactionmessages[] = 'Payment created (id=' . $paiement_id . ')';
							$ispostactionok = 1;
						}
					}

					if (!$error && isModEnabled("bank")) {
						$bankaccountid = 0;
						if ($paymentmethod == 'paybox') {
							$bankaccountid = getDolGlobalInt('PAYBOX_BANK_ACCOUNT_FOR_PAYMENTS');
						} elseif ($paymentmethod == 'paypal') {
							$bankaccountid = getDolGlobalInt('PAYPAL_BANK_ACCOUNT_FOR_PAYMENTS');
						} elseif ($paymentmethod == 'stripe') {
							$bankaccountid = getDolGlobalInt('STRIPE_BANK_ACCOUNT_FOR_PAYMENTS');
						} elseif ($paymentmethod == 'stancer') {
							$bankaccountid = getDolGlobalInt('STANCER_BANK_ACCOUNT_FOR_PAYMENTS');
						}

						if ($bankaccountid > 0) {
							$label = '(CustomerInvoicePayment)';
							// if ($object->type == Facture::TYPE_CREDIT_NOTE) {
							// 	$label = '(CustomerInvoicePaymentBack)';
							// } // Refund of a credit note
							$result = $paiement->addPaymentToBank($user, 'payment', $label, $bankaccountid, '', '');
							if ($result < 0) {
								dol_syslog("stancer add payment to bank error : " . json_encode($paiement->error), LOG_ERR);
								$postactionmessages[] = $paiement->error . ' ' . implode("<br>\n", array_filter($paiement->errors, 'strlen'));
								$ispostactionok = -1;
								$error++;
							} else {
								dol_syslog("stancer bank transaction created");
								$postactionmessages[] = 'Bank transaction of payment created';
								$ispostactionok = 1;

								if (getDolGlobalString('STANCER_CB_AS_PAID', '') != '') {
									$invoice->setPaid($user);
								}
							}
						} else {
							dol_syslog("stancer Setup of bank account to use in module ($paymentmethod) was not set. No way to record the payment", LOG_ERR);
							$postactionmessages[] = 'Setup of bank account to use in module ' . $paymentmethod . ' was not set. No way to record the payment.';
							$ispostactionok = -1;
							$error++;
						}
					}

					if (!$error) {
						$db->commit();
					} else {
						$db->rollback();
					}
				} else {
					dol_syslog("stancer Failed to get a valid value for amount paid ($FinalPaymentAmt) or payment type id ($paymentTypeId) to record the payment of order " . $tmptag['ORD'] . ". May be payment was already recorded. ", LOG_ERR);
					$postactionmessages[] = 'Failed to get a valid value for "amount paid" (' . $FinalPaymentAmt . ') or "payment type id" (' . $paymentTypeId . ') to record the payment of order ' . $tmptag['ORD'] . '. May be payment was already recorded.';
					$ispostactionok = -1;
				}
			} else {
				dol_syslog("stancer invoice module is not enabled", LOG_ERR);
				$postactionmessages[] = 'Invoice module is not enable';
				$ispostactionok = -1;
			}
		} else {
			dol_syslog("stancer paymentback order " . $tmptag['ORD'] . " was not found (fetch returned $result), payment not recorded", LOG_ERR, 0, '_payment');
			$postactionmessages[] = 'Order paid ' . $tmptag['ORD'] . ' was not found';
			$ispostactionok = -1;
		}
	} elseif (array_key_exists('PRO', $tmptag) && $tmptag['PRO'] > 0) {
		// Propal payment
		dol_syslog("stancerPaymentBack is propal", LOG_DEBUG, 0, '_payment');
		require_once DOL_DOCUMENT_ROOT . '/comm/propal/class/propal.class.php';

		$propal = new Propal($db);
		$propalid = (int) $tmptag['PRO'];
		$result = $propal->fetch($propalid);
		if ($result) {
			$FinalPaymentAmt = $_SESSION["FinalPaymentAmt"];
			$paymentType = $_SESSION["paymentType"];
			if (empty($paymentType)) {
				$paymentType = 'CB';
			}
			$paymentTypeId = dol_getIdFromCode($db, $paymentType, 'c_paiement', 'code', 'id', 1);

			// Mark propal as signed/billed
			if ($propal->status == Propal::STATUS_VALIDATED) {
				$propal->closeProposal($user, Propal::STATUS_SIGNED, 'Paiement Stancer');
			}

			// Auto create invoice if option is enabled
			if (getDolGlobalString('STANCER_AUTO_INVOICE_ON_PROPAL_PAID') && stancerIsModEnabled('invoice')) {
				dol_syslog("stancerPaymentBack propal: auto create invoice enabled", LOG_DEBUG, 0, '_payment');

				$partialPayment = isset($_SESSION["partialPayment"]) ? $_SESSION["partialPayment"] : 0;

				// Check if deposit invoice should be created
				if (
					$partialPayment == 1
					&& getDolGlobalString('STANCER_CB_PROPAL_PARTIAL_PAY') != ''
					&& isset($propal->deposit_percent) && $propal->deposit_percent > 0 && $propal->deposit_percent < 100
				) {
					// Create deposit invoice from propal
					$FinalPaymentAmt = ($propal->total_ttc * $propal->deposit_percent / 100);
					dol_syslog("stancerPaymentBack propal: create deposit invoice, amount=" . $FinalPaymentAmt, LOG_DEBUG, 0, '_payment');
					// @phpstan-ignore-next-line
					$result = Facture::createDepositFromOrigin($propal, $now, (int) $propal->cond_reglement_id, $user, 0, true);
				} else {
					// Create full invoice from propal
					$result = stancerCreateInvoiceFromPropal($db, $user, $propalid);
				}
				if (is_object($result) && $result->id > 0) {
					$invoice = $result;
					dol_syslog("stancerPaymentBack invoice from propal created, id=" . $invoice->id, LOG_DEBUG, 0, '_payment');

					// Validate the invoice if needed
					if ($invoice->status != Facture::STATUS_VALIDATED && $invoice->status != Facture::STATUS_CLOSED) {
						$invoiceValidate = $invoice->validate($user);
						if (is_numeric($invoiceValidate) && $invoiceValidate <= 0) {
							dol_syslog("stancer validate invoice ref=" . $invoice->ref . ", validate code is = " . $invoiceValidate . " let me try without trigger", LOG_ERR);
							$invoiceValidate = $invoice->validate($user, '', 0, 1, 0);
						}
					} else {
						$invoiceValidate = 1;
					}

					if ($invoiceValidate > 0) {
						dol_syslog("stancerPaymentBack invoice validated ok", LOG_DEBUG, 0, '_payment');

						// Set payment method and bank account. setBankAccount() writes fk_account
						// unconditionally, so an unset constant would wipe the bank account
						// already carried by the invoice. Same guard as the other branches.
						$bankaccountid = getDolGlobalInt('STANCER_BANK_ACCOUNT_FOR_PAYMENTS');
						$paymentmethodId = dol_getIdFromCode($db, 'CB', 'c_paiement', 'code', 'id', 1);
						$invoice->setPaymentMethods($paymentmethodId);
						if ($bankaccountid > 0) {
							$invoice->setBankAccount($bankaccountid);
						} else {
							dol_syslog("stancer STANCER_BANK_ACCOUNT_FOR_PAYMENTS is not set, bank account of invoice " . $invoice->ref . " left unchanged", LOG_WARNING, 0, '_payment');
						}

						// Create payment
						$paiement = new Paiement($db);
						$paiement->datepaye = $now;
						if ($currencyCodeType == $conf->currency) {
							$paiement->amounts = array($invoice->id => $FinalPaymentAmt);
						} else {
							$paiement->multicurrency_amounts = array($invoice->id => $FinalPaymentAmt);
						}
						$paiement->paiementid = $paymentTypeId;
						$paiement->num_payment = $_SESSION["stancer_payment_id"];
						$paiement->note_public = 'Online payment ' . dol_print_date($now, 'standard') . ' from ' . $ipaddress . ' (propal ' . $propal->ref . ')';
						$paiement->ext_payment_id = $TRANSACTIONID;
						$paiement->ext_payment_site = 'stancer';

						$paiement_id = $paiement->create($user, 1);
						if ($paiement_id < 0) {
							dol_syslog("stancer Failed to create payment for propal invoice: " . $paiement->error, LOG_ERR);
							$postactionmessages[] = $paiement->error . ' ' . implode("<br>\n", array_filter($paiement->errors, 'strlen'));
							$ispostactionok = -1;
							$error++;
						} else {
							dol_syslog("stancerPaymentBack Payment created id=" . $paiement_id, LOG_DEBUG, 0, '_payment');

							// Add payment to bank
							if (!$error && isModEnabled('bank')) {
								$bankaccountid = getDolGlobalInt('STANCER_BANK_ACCOUNT_FOR_PAYMENTS');
								if ($bankaccountid > 0) {
									$label = '(CustomerInvoicePayment)';
									$result = $paiement->addPaymentToBank($user, 'payment', $label, $bankaccountid, '', '');
									if ($result < 0) {
										dol_syslog("stancer Failed to add payment to bank: " . $paiement->error, LOG_ERR);
										$postactionmessages[] = $paiement->error . ' ' . implode("<br>\n", array_filter($paiement->errors, 'strlen'));
										$ispostactionok = -1;
										$error++;
									}
								}
							}

							// Mark invoice as paid if configured
							if (!$error && getDolGlobalString('STANCER_CB_AS_PAID')) {
								$invoice->setPaid($user);
							}

							// Send invoice by mail if configured
							if (!$error && getDolGlobalString('STANCER_AUTO_MAIL_INVOICES_CB')) {
								stancerSendInvoiceMailModele(getDolGlobalString('STANCER_AUTO_MAIL_INVOICES_CB_MAILTYPE', ''), $invoice, 'BILL_PAYED_SENTBYMAIL');
							}

							$postactionmessages[] = 'Invoice ' . $invoice->ref . ' created and paid from propal ' . $propal->ref;
						}
					} else {
						dol_syslog("stancer Failed to validate invoice from propal " . $tmptag['PRO'], LOG_ERR);
						$postactionmessages[] = 'Failed to validate invoice from propal ' . $tmptag['PRO'];
						$ispostactionok = -1;
						$error++;
					}
				} else {
					dol_syslog("stancer Failed to create invoice from propal " . $tmptag['PRO'] . ", result=$result", LOG_ERR);
					$postactionmessages[] = 'Failed to create invoice from propal ' . $tmptag['PRO'];
					$ispostactionok = -1;
					$error++;
				}
			} else {
				// No auto invoice, just mark the propal as paid/signed
				$postactionmessages[] = 'Propal ' . $propal->ref . ' marked as signed. No invoice created (auto invoice disabled).';
			}
		} else {
			$postactionmessages[] = 'Propal paid ' . $tmptag['PRO'] . ' was not found';
			$ispostactionok = -1;
		}
	} elseif (array_key_exists('DON', $tmptag) && $tmptag['DON'] > 0) {
		include_once DOL_DOCUMENT_ROOT . '/don/class/don.class.php';
		$don = new Don($db);
		$result = $don->fetch((int) $tmptag['DON']);
		if ($result) {
			$paymentTypeId = 0;
			if ($paymentmethod == 'paybox') {
				$paymentTypeId = getDolGlobalInt('PAYBOX_PAYMENT_MODE_FOR_PAYMENTS');
			}
			if ($paymentmethod == 'paypal') {
				$paymentTypeId = getDolGlobalInt('global->PAYPAL_PAYMENT_MODE_FOR_PAYMENTS');
			}
			if ($paymentmethod == 'stripe') {
				$paymentTypeId = getDolGlobalInt('STRIPE_PAYMENT_MODE_FOR_PAYMENTS');
			}
			if (empty($paymentTypeId)) {
				dol_syslog("stancer paymentType = " . $paymentType, LOG_DEBUG, 0, '_payment');

				if (empty($paymentType)) {
					$paymentType = 'CB';
				}
				// May return nothing when paymentType means nothing
				// (for example when paymentType is 'Mark', 'Sole', 'Sale', for paypal)
				$paymentTypeId = dol_getIdFromCode($db, $paymentType, 'c_paiement', 'code', 'id', 1);

				// If previous line has returned nothing, we force to get the ID of payment of Credit Card (hard coded code 'CB').
				if (empty($paymentTypeId) || $paymentTypeId < 0) {
					$paymentTypeId = dol_getIdFromCode($db, 'CB', 'c_paiement', 'code', 'id', 1);
				}
			}

			// Do action only if $FinalPaymentAmt is set (session variable is cleaned after this page to avoid duplicate actions when page is POST a second time)
			if (!empty($FinalPaymentAmt) && $paymentTypeId > 0) {
				$db->begin();

				// Creation of paiement line for donation
				include_once DOL_DOCUMENT_ROOT . '/don/class/paymentdonation.class.php';
				$paiement = new PaymentDonation($db);

				$totalpaid = $FinalPaymentAmt;

				if ($currencyCodeType == $conf->currency) {
					$paiement->amounts = array($object->id => $totalpaid); // Array with all payments dispatching with donation
				} else {
					// PaymentDonation does not support multi currency
					$postactionmessages[] = 'Payment donation can\'t be paid with different currency than ' . $conf->currency;
					$ispostactionok = -1;
					$error++; // Not yet supported
				}

				$paiement->fk_donation = $don->id;
				$paiement->datep = $now;
				$paiement->datepaid = $now;
				$paiement->paymenttype = $paymentTypeId;
				$paiement->num_payment = $_SESSION["stancer_payment_id"]; //erics was ''
				$paiement->note_public  = 'Online payment ' . dol_print_date($now, 'standard') . ' from ' . $ipaddress;
				$paiement->ext_payment_id = $TRANSACTIONID;
				$paiement->ext_payment_site = $service;
				$paiement->comment = $paymentmethod;

				if (!$error) {
					$paiement_id = $paiement->create($user, 1);
					if ($paiement_id < 0) {
						$postactionmessages[] = $paiement->error . ' ' . implode("<br>\n", $paiement->errors);
						$ispostactionok = -1;
						$error++;
					} else {
						$postactionmessages[] = 'Payment created';
						$ispostactionok = 1;

						if ($totalpaid >= $don->getRemainToPay()) {
							$don->setPaid($don->id);
						}
					}
				}

				if (!$error && isModEnabled("bank")) {
					$bankaccountid = 0;
					if ($paymentmethod == 'paybox') {
						$bankaccountid = getDolGlobalInt('PAYBOX_BANK_ACCOUNT_FOR_PAYMENTS');
					} elseif ($paymentmethod == 'paypal') {
						$bankaccountid = getDolGlobalInt('PAYPAL_BANK_ACCOUNT_FOR_PAYMENTS');
					} elseif ($paymentmethod == 'stripe') {
						$bankaccountid = getDolGlobalInt('STRIPE_BANK_ACCOUNT_FOR_PAYMENTS');
					}

					//Get bank account for a specific paymentmedthod
					$parameters = [
						'paymentmethod' => $paymentmethod,
					];
					$reshook = $hookmanager->executeHooks('getBankAccountPaymentMethod', $parameters, $object, $action);
					if ($reshook >= 0) {
						if (isset($hookmanager->resArray['bankaccountid'])) {
							dol_syslog('bankaccountid overwrite by hook return with value=' . $hookmanager->resArray['bankaccountid'], LOG_DEBUG, 0, '_payment');
							$bankaccountid = $hookmanager->resArray['bankaccountid'];
						}
					}
					if ($bankaccountid > 0) {
						$label = '(DonationPayment)';
						$result = $paiement->addPaymentToBank($user, 'payment_donation', $label, $bankaccountid, '', '');
						if ($result < 0) {
							$postactionmessages[] = $paiement->error . ' ' . implode("<br>\n", $paiement->errors);
							$ispostactionok = -1;
							$error++;
						} else {
							$postactionmessages[] = 'Bank transaction of payment created';
							$ispostactionok = 1;
						}
					} else {
						$postactionmessages[] = 'Setup of bank account to use in module ' . $paymentmethod . ' was not set. Your payment was really executed but we failed to record it. Please contact us.';
						$ispostactionok = -1;
						$error++;
					}
				}

				if (!$error) {
					$db->commit();
				} else {
					$db->rollback();
				}
			} else {
				$postactionmessages[] = 'Failed to get a valid value for "amount paid" (' . $FinalPaymentAmt . ') or "payment type id" (' . $paymentTypeId . ') to record the payment of donation ' . $tmptag['DON'] . '. May be payment was already recorded.';
				$ispostactionok = -1;
			}
		} else {
			$postactionmessages[] = 'Donation paid ' . $tmptag['DON'] . ' was not found';
			$ispostactionok = -1;
		}

		// TODO send email with acknowledgment for the donation
		//      (we need first that the donation module is able to generate a pdf document for the cerfa with pre filled content)	} elseif (array_key_exists('ATT', $tmptag) && $tmptag['ATT'] > 0) {
	} elseif (array_key_exists('ATT', $tmptag) && $tmptag['ATT'] > 0) {
		// Record payment for registration to an event for an attendee
		$object = new Facture($db);
		$result = $object->fetch($ref);
		if ($result) {
			$FinalPaymentAmt = $_SESSION["FinalPaymentAmt"];

			$paymentTypeId = 0;
			if ($paymentmethod == 'paybox') {
				$paymentTypeId = getDolGlobalString('PAYBOX_PAYMENT_MODE_FOR_PAYMENTS');
			}
			if ($paymentmethod == 'paypal') {
				$paymentTypeId = getDolGlobalString('PAYPAL_PAYMENT_MODE_FOR_PAYMENTS');
			}
			if ($paymentmethod == 'stripe') {
				$paymentTypeId = getDolGlobalString('STRIPE_PAYMENT_MODE_FOR_PAYMENTS');
			}
			if (empty($paymentTypeId)) {
				$paymentType = $_SESSION["paymentType"];
				if (empty($paymentType)) {
					$paymentType = 'CB';
				}
				$paymentTypeId = dol_getIdFromCode($db, $paymentType, 'c_paiement', 'code', 'id', 1);
			}

			$currencyCodeType = $_SESSION['currencyCodeType'];

			// Do action only if $FinalPaymentAmt is set (session variable is cleaned after this page to avoid duplicate actions when page is POST a second time)
			if (!empty($FinalPaymentAmt) && $paymentTypeId > 0) {
				$resultvalidate = $object->validate($user);
				if ($resultvalidate < 0) {
					$postactionmessages[] = 'Cannot validate invoice';
					$ispostactionok = -1;
					$error++; // Not yet supported
				} else {
					$db->begin();

					// Creation of payment line
					include_once DOL_DOCUMENT_ROOT . '/compta/paiement/class/paiement.class.php';
					$paiement = new Paiement($db);
					$paiement->datepaye = $now;
					if ($currencyCodeType == $conf->currency) {
						$paiement->amounts = array($object->id => $FinalPaymentAmt); // Array with all payments dispatching with invoice id
					} else {
						$paiement->multicurrency_amounts = array($object->id => $FinalPaymentAmt); // Array with all payments dispatching

						$postactionmessages[] = 'Payment was done in a different currency that currency expected of company';
						$ispostactionok = -1;
						$error++; // Not yet supported
					}
					$paiement->paiementid   = $paymentTypeId;
					$paiement->num_payment = $_SESSION["stancer_payment_id"]; //erics was ''
					$paiement->note_public  = 'Online payment ' . dol_print_date($now, 'standard') . ' from ' . $ipaddress . ' for event registration';
					$paiement->ext_payment_id = $TRANSACTIONID;
					$paiement->ext_payment_site = $service;
					$paiement->comment = $paymentmethod;

					if (!$error) {
						$paiement_id = $paiement->create($user, 1); // This include closing invoices and regenerating documents
						if ($paiement_id < 0) {
							$postactionmessages[] = $paiement->error . ' ' . implode("<br>\n", array_filter($paiement->errors, 'strlen'));
							$ispostactionok = -1;
							$error++;
						} else {
							$postactionmessages[] = 'Payment created';
							$ispostactionok = 1;
						}
					}

					if (!$error && !empty($conf->banque->enabled)) {
						$bankaccountid = 0;
						if ($paymentmethod == 'paybox') {
							$bankaccountid = getDolGlobalInt('PAYBOX_BANK_ACCOUNT_FOR_PAYMENTS');
						} elseif ($paymentmethod == 'paypal') {
							$bankaccountid = getDolGlobalInt('PAYPAL_BANK_ACCOUNT_FOR_PAYMENTS');
						} elseif ($paymentmethod == 'stripe') {
							$bankaccountid = getDolGlobalInt('STRIPE_BANK_ACCOUNT_FOR_PAYMENTS');
						}

						if ($bankaccountid > 0) {
							$label = '(CustomerInvoicePayment)';
							if ($object->type == Facture::TYPE_CREDIT_NOTE) {
								$label = '(CustomerInvoicePaymentBack)'; // Refund of a credit note
							}
							$result = $paiement->addPaymentToBank($user, 'payment', $label, $bankaccountid, '', '');
							if ($result < 0) {
								$postactionmessages[] = $paiement->error . ' ' . implode("<br>\n", array_filter($paiement->errors, 'strlen'));
								$ispostactionok = -1;
								$error++;
							} else {
								$postactionmessages[] = 'Bank transaction of payment created';
								$ispostactionok = 1;
							}
						} else {
							$postactionmessages[] = 'Setup of bank account to use in module ' . $paymentmethod . ' was not set. Your payment was really executed but we failed to record it. Please contact us.';
							$ispostactionok = -1;
							$error++;
						}
					}

					$attendeetovalidate = new ConferenceOrBoothAttendee($db);
					if (!$error) {
						// Validating the attendee
						$resultattendee = $attendeetovalidate->fetch((int) $tmptag['ATT']);
						if ($resultattendee < 0) {
							$error++;
							setEventMessages("", $attendeetovalidate->errors, "errors");
						} else {
							$attendeetovalidate->validate($user);

							$attendeetovalidate->amount = $FinalPaymentAmt;
							$attendeetovalidate->date_subscription = dol_now();
							$attendeetovalidate->update($user);
						}
					}

					if (!$error) {
						$db->commit();
					} else {
						setEventMessages("", $postactionmessages, 'warnings');

						$db->rollback();
					}

					if (! $error) {
						// Sending mail
						$thirdparty = new Societe($db);
						$resultthirdparty = $thirdparty->fetch($attendeetovalidate->fk_soc);
						if ($resultthirdparty < 0) {
							setEventMessages("", $attendeetovalidate->errors, "errors");
						} else {
							require_once DOL_DOCUMENT_ROOT . '/core/class/CMailFile.class.php';
							include_once DOL_DOCUMENT_ROOT . '/core/class/html.formmail.class.php';
							$formmail = new FormMail($db);
							// Set output language
							$outputlangs = new Translate('', $conf);
							$outputlangs->setDefaultLang(empty($thirdparty->default_lang) ? $mysoc->default_lang : $thirdparty->default_lang);
							// Load traductions files required by page
							$outputlangs->loadLangs(array("main", "members"));
							// Get email content from template
							$arraydefaultmessage = null;

							$labeltouse = getDolGlobalString('EVENTORGANIZATION_TEMPLATE_EMAIL_AFT_SUBS_EVENT');

							if (!empty($labeltouse)) {
								$arraydefaultmessage = $formmail->getEMailTemplate($db, 'conferenceorbooth', $user, $outputlangs, (int) $labeltouse, 1, '');
							}

							$subject = $msg = "";
							if (!empty($labeltouse) && is_object($arraydefaultmessage) && $arraydefaultmessage->id > 0) {
								$subject = $arraydefaultmessage->topic;
								$msg     = $arraydefaultmessage->content;
							}

							$substitutionarray = getCommonSubstitutionArray($outputlangs, 0, null, $thirdparty);
							complete_substitutions_array($substitutionarray, $outputlangs, $object);

							$subjecttosend = make_substitutions($subject, $substitutionarray, $outputlangs);
							$texttosend = make_substitutions($msg, $substitutionarray, $outputlangs);

							$sendto = $attendeetovalidate->email;
							$from = getDolGlobalString('MAIN_MAIL_EMAIL_FROM');
							$urlback = $_SERVER["REQUEST_URI"];

							$ishtml = 0;
							// May contain urls
							if (dol_textishtml($texttosend)) {
								$ishtml = 1;
							}

							$moreinheader = 'X-Dolibarr-Info: stancerPaymentbackAttendee' . "\r\n";
							$mailfile = new CMailFile($subjecttosend, $sendto, $from, $texttosend, array(), array(), array(), '', '', 0, $ishtml, '', '', '', $moreinheader);

							$result = $mailfile->sendfile();
							if ($result) {
								dol_syslog("stancer paymentback EMail sent to " . $sendto, LOG_DEBUG, 0, '_payment');
							} else {
								dol_syslog("stancer paymentback Failed to send EMail to " . $sendto, LOG_ERR, 0, '_payment');
							}
						}
					}
				}
			} else {
				$postactionmessages[] = 'Failed to get a valid value for "amount paid" (' . $FinalPaymentAmt . ') or "payment type" (' . $paymentType . ') to record the payment of invoice ' . $tmptag['ATT'] . '. May be payment was already recorded.';
				$ispostactionok = -1;
			}
		} else {
			$postactionmessages[] = 'Invoice paid ' . $tmptag['ATT'] . ' was not found';
			$ispostactionok = -1;
		}
	} elseif (array_key_exists('BOO', $tmptag) && $tmptag['BOO'] > 0) {
		// Record payment for booth or conference
		$object = new Facture($db);
		$result = $object->fetch($ref);
		if ($result) {
			$FinalPaymentAmt = $_SESSION["FinalPaymentAmt"];

			$paymentTypeId = 0;
			if ($paymentmethod == 'paybox') {
				$paymentTypeId = getDolGlobalString('PAYBOX_PAYMENT_MODE_FOR_PAYMENTS');
			}
			if ($paymentmethod == 'paypal') {
				$paymentTypeId = getDolGlobalString('PAYPAL_PAYMENT_MODE_FOR_PAYMENTS');
			}
			if ($paymentmethod == 'stripe') {
				$paymentTypeId = getDolGlobalString('STRIPE_PAYMENT_MODE_FOR_PAYMENTS');
			}
			if (empty($paymentTypeId)) {
				$paymentType = $_SESSION["paymentType"];
				if (empty($paymentType)) {
					$paymentType = 'CB';
				}
				$paymentTypeId = dol_getIdFromCode($db, $paymentType, 'c_paiement', 'code', 'id', 1);
			}

			$currencyCodeType = $_SESSION['currencyCodeType'];

			// Do action only if $FinalPaymentAmt is set (session variable is cleaned after this page to avoid duplicate actions when page is POST a second time)
			if (!empty($FinalPaymentAmt) && $paymentTypeId > 0) {
				$resultvalidate = $object->validate($user);
				if ($resultvalidate < 0) {
					$postactionmessages[] = 'Cannot validate invoice';
					$ispostactionok = -1;
					$error++; // Not yet supported
				} else {
					$db->begin();

					// Creation of payment line
					include_once DOL_DOCUMENT_ROOT . '/compta/paiement/class/paiement.class.php';
					$paiement = new Paiement($db);
					$paiement->datepaye = $now;
					if ($currencyCodeType == $conf->currency) {
						$paiement->amounts = array($object->id => $FinalPaymentAmt); // Array with all payments dispatching with invoice id
					} else {
						$paiement->multicurrency_amounts = array($object->id => $FinalPaymentAmt); // Array with all payments dispatching

						$postactionmessages[] = 'Payment was done in a different currency that currency expected of company';
						$ispostactionok = -1;
						$error++; // Not yet supported
					}
					$paiement->paiementid   = $paymentTypeId;
					$paiement->num_payment = $_SESSION["stancer_payment_id"]; //erics was ''
					$paiement->note_public  = 'Online payment ' . dol_print_date($now, 'standard') . ' from ' . $ipaddress;
					$paiement->ext_payment_id = $TRANSACTIONID;
					$paiement->ext_payment_site = $service;
					$paiement->comment = $paymentmethod;

					if (!$error) {
						$paiement_id = $paiement->create($user, 1); // This include closing invoices and regenerating documents
						if ($paiement_id < 0) {
							$postactionmessages[] = $paiement->error . ' ' . implode("<br>\n", array_filter($paiement->errors, 'strlen'));
							$ispostactionok = -1;
							$error++;
						} else {
							$postactionmessages[] = 'Payment created';
							$ispostactionok = 1;
						}
					}

					if (!$error && !empty($conf->banque->enabled)) {
						$bankaccountid = 0;
						if ($paymentmethod == 'paybox') {
							$bankaccountid = getDolGlobalInt('PAYBOX_BANK_ACCOUNT_FOR_PAYMENTS');
						} elseif ($paymentmethod == 'paypal') {
							$bankaccountid = getDolGlobalInt('PAYPAL_BANK_ACCOUNT_FOR_PAYMENTS');
						} elseif ($paymentmethod == 'stripe') {
							$bankaccountid = getDolGlobalInt('STRIPE_BANK_ACCOUNT_FOR_PAYMENTS');
						}

						if ($bankaccountid > 0) {
							$label = '(CustomerInvoicePayment)';
							if ($object->type == Facture::TYPE_CREDIT_NOTE) {
								$label = '(CustomerInvoicePaymentBack)'; // Refund of a credit note
							}
							$result = $paiement->addPaymentToBank($user, 'payment', $label, $bankaccountid, '', '');
							if ($result < 0) {
								$postactionmessages[] = $paiement->error . ' ' . implode("<br>\n", array_filter($paiement->errors, 'strlen'));
								$ispostactionok = -1;
								$error++;
							} else {
								$postactionmessages[] = 'Bank transaction of payment created';
								$ispostactionok = 1;
							}
						} else {
							$postactionmessages[] = 'Setup of bank account to use in module ' . $paymentmethod . ' was not set. Your payment was really executed but we failed to record it. Please contact us.';
							$ispostactionok = -1;
							$error++;
						}
					}

					if (!$error) {
						// Putting the booth to "suggested" state
						$booth = new ConferenceOrBooth($db);
						$resultbooth = $booth->fetch((int) $tmptag['BOO']);
						if ($resultbooth < 0) {
							$error++;
							setEventMessages("", $booth->errors, "errors");
						} else {
							$booth->status = ConferenceOrBooth::STATUS_SUGGESTED;
							$resultboothupdate = $booth->update($user);
							if ($resultboothupdate < 0) {
								// Finding the thirdparty by getting the invoice
								$invoice = new Facture($db);
								$resultinvoice = $invoice->fetch($ref);
								if ($resultinvoice < 0) {
									$postactionmessages[] = 'Could not find the associated invoice.';
									$ispostactionok = -1;
									$error++;
								} else {
									$thirdparty = new Societe($db);
									$resultthirdparty = $thirdparty->fetch($invoice->socid);
									if ($resultthirdparty < 0) {
										$error++;
										setEventMessages("", $thirdparty->errors, "errors");
									} else {
										// Sending mail
										require_once DOL_DOCUMENT_ROOT . '/core/class/CMailFile.class.php';
										include_once DOL_DOCUMENT_ROOT . '/core/class/html.formmail.class.php';
										$formmail = new FormMail($db);
										// Set output language
										$outputlangs = new Translate('', $conf);
										$outputlangs->setDefaultLang(empty($thirdparty->default_lang) ? $mysoc->default_lang : $thirdparty->default_lang);
										// Load traductions files required by page
										$outputlangs->loadLangs(array("main", "members"));
										// Get email content from template
										$arraydefaultmessage = null;

										$labeltouse = getDolGlobalString('EVENTORGANIZATION_TEMPLATE_EMAIL_AFT_SUBS_EVENT');
										if (!empty($labeltouse)) {
											$arraydefaultmessage = $formmail->getEMailTemplate($db, 'conferenceorbooth', $user, $outputlangs, (int) $labeltouse, 1, '');
										}

										$msg = $subject = "";
										if (!empty($labeltouse) && is_object($arraydefaultmessage) && $arraydefaultmessage->id > 0) {
											$subject = $arraydefaultmessage->topic;
											$msg     = $arraydefaultmessage->content;
										}

										$substitutionarray = getCommonSubstitutionArray($outputlangs, 0, null, $thirdparty);
										complete_substitutions_array($substitutionarray, $outputlangs, $object);

										$subjecttosend = make_substitutions($subject, $substitutionarray, $outputlangs);
										$texttosend = make_substitutions($msg, $substitutionarray, $outputlangs);

										$sendto = $thirdparty->email;
										$from = getDolGlobalString('MAIN_MAIL_EMAIL_FROM');
										$urlback = $_SERVER["REQUEST_URI"];

										$ishtml = 0;
										// May contain urls
										if (dol_textishtml($texttosend)) {
											$ishtml = 1;
										}

										$moreinheader = 'X-Dolibarr-Info: stancerPaymentbackThirdparty' . "\r\n";
										$mailfile = new CMailFile($subjecttosend, $sendto, $from, $texttosend, array(), array(), array(), '', '', 0, $ishtml, '', '', '', $moreinheader);

										$result = $mailfile->sendfile();
										if ($result) {
											dol_syslog("stancer paymentback EMail sent to " . $sendto, LOG_DEBUG, 0, '_payment');
										} else {
											dol_syslog("stancer paymentback Failed to send EMail to " . $sendto, LOG_ERR, 0, '_payment');
										}
									}
								}
							}
						}
					}

					if (!$error) {
						$db->commit();
					} else {
						$db->rollback();
					}
				}
			} else {
				$postactionmessages[] = 'Failed to get a valid value for "amount paid" (' . $FinalPaymentAmt . ') or "payment type" (' . $paymentType . ') to record the payment of invoice ' . $tmptag['ATT'] . '. May be payment was already recorded.';
				$ispostactionok = -1;
			}
		} else {
			$postactionmessages[] = 'Invoice paid ' . $tmptag['ATT'] . ' was not found';
			$ispostactionok = -1;
		}
	} else {
		// Nothing done
	}
}

if ($ispaymentok) {
	// Get on url call
	$onlinetoken        = empty($PAYPALTOKEN) ? $_SESSION['onlinetoken'] : $PAYPALTOKEN;
	$payerID            = empty($PAYPALPAYERID) ? $_SESSION['payerID'] : $PAYPALPAYERID;
	// Set by newpayment.php
	$paymentType        = $_SESSION['PaymentType'];
	$currencyCodeType   = $_SESSION['currencyCodeType'];
	$FinalPaymentAmt    = $_SESSION["FinalPaymentAmt"];

	if (is_object($object) && method_exists($object, 'call_trigger')) {
		// Call trigger
		$result = $object->call_trigger('PAYMENTONLINE_PAYMENT_OK', $user);
		if ($result < 0) {
			$error++;
		}
		// End call triggers
	} elseif (get_class($object) == 'stdClass') {
		//In some case $object is not instanciate (for paiement on custom object) We need to deal with payment
		include_once DOL_DOCUMENT_ROOT . '/compta/paiement/class/paiement.class.php';
		$paiement = new Paiement($db);
		$result = $paiement->call_trigger('PAYMENTONLINE_PAYMENT_OK', $user);
		if ($result < 0) {
			$error++;
		}
	}

	// print $langs->trans("YourPaymentHasBeenRecorded")."<br>\n";
	// if ($TRANSACTIONID) {
	// print $langs->trans("ThisIsTransactionId", $TRANSACTIONID)."<br><br>\n";
	// }

	$key = 'ONLINE_PAYMENT_MESSAGE_OK';
	// if (!empty($conf->global->$key)) {
	//     print '<br>';
	//     print $conf->global->$key;
	// }

	$sendemail = '';
	if (getDolGlobalString('ONLINE_PAYMENT_SENDEMAIL', '') != '') {
		$sendemail = getDolGlobalString('ONLINE_PAYMENT_SENDEMAIL');
	}

	$tmptag = dolExplodeIntoArray($fulltag, '.', '=');

	dol_syslog("stancer paymentback Send email to admins if we have to (sendemail = " . $sendemail . ")", LOG_DEBUG, 0, '_payment');

	// Send an email to admins
	if ($sendemail) {
		$companylangs = new Translate('', $conf);
		$companylangs->setDefaultLang($mysoc->default_lang);
		$companylangs->loadLangs(array('main', 'members', 'bills', 'paypal', 'paybox'));

		$sendto = $sendemail;
		$from = getDolGlobalString('MAIN_MAIL_EMAIL_FROM');
		// Define $urlwithroot
		$urlwithouturlroot = preg_replace('/' . preg_quote(DOL_URL_ROOT, '/') . '$/i', '', trim($dolibarr_main_url_root));
		$urlwithroot = $urlwithouturlroot . DOL_URL_ROOT; // This is to use external domain name found into config file
		//$urlwithroot=DOL_MAIN_URL_ROOT;					// This is to use same domain name than current

		// Define link to login card
		$appli = constant('DOL_APPLICATION_TITLE');
		if (getDolGlobalString('MAIN_APPLICATION_TITLE', '') != '') {
			$appli = getDolGlobalString('MAIN_APPLICATION_TITLE');
			if (preg_match('/\d\.\d/', $appli)) {
				if (!preg_match('/' . preg_quote(DOL_VERSION) . '/', $appli)) {
					$appli .= " (" . DOL_VERSION . ")"; // If new title contains a version that is different than core
				}
			} else {
				$appli .= " " . DOL_VERSION;
			}
		} else {
			$appli .= " " . DOL_VERSION;
		}

		$urlback = $_SERVER["REQUEST_URI"];
		$topic = '[' . $appli . '] ' . $companylangs->transnoentitiesnoconv("NewOnlinePaymentReceived");
		// Fetch thirdparty name if available. $object is a Facture or a Commande here,
		// and neither fetch() ever fills $fk_soc on Dolibarr 15..21: only $socid is set,
		// so reading $fk_soc left $thirdpartyName always empty in the notification mail.
		$thirdpartyName = '';
		if (is_object($object) && !empty($object->socid)) {
			require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
			$soc = new Societe($db);
			if ($soc->fetch((int) $object->socid) > 0) {
				$thirdpartyName = $soc->name;
			} else {
				dol_syslog("stancer paymentback cannot load thirdparty " . $object->socid . " for the notification mail", LOG_WARNING);
			}
		}
		$content = "";
		if (array_key_exists('MEM', $tmptag)) {
			$url = $urlwithroot . "/adherents/subscription.php?rowid=" . $tmptag['MEM'];
			$content .= '<strong>' . $companylangs->trans("PaymentSubscription") . "</strong><br><br>\n";
			$content .= $companylangs->trans("MemberId") . ': <strong>' . $tmptag['MEM'] . "</strong><br>\n";
			if (!empty($thirdpartyName)) {
				$content .= $companylangs->trans("Customer") . ' : <strong>' . dol_escape_htmltag($thirdpartyName) . "</strong><br>\n";
			}
			$content .= $companylangs->trans("Link") . ': <a href="' . $url . '">' . $url . '</a>' . "<br>\n";
		} elseif (array_key_exists('INV', $tmptag)) {
			$url = $urlwithroot . "/compta/facture/card.php?id=" . $tmptag['INV'];
			$invoiceRef = (is_object($object) && !empty($object->ref)) ? $object->ref : $tmptag['INV'];
			$content .= '<strong>' . $companylangs->trans("Payment") . "</strong><br><br>\n";
			$content .= $companylangs->trans("Invoice") . ' : <strong><a href="' . $url . '">' . dol_escape_htmltag($invoiceRef) . "</a></strong><br>\n";
			if (!empty($thirdpartyName)) {
				$content .= $companylangs->trans("Customer") . ' : <strong>' . dol_escape_htmltag($thirdpartyName) . "</strong><br>\n";
			}
			$content .= $companylangs->trans("Amount") . ' : <strong>' . $FinalPaymentAmt . ' ' . $currencyCodeType . "</strong><br>\n";
			$content .= $companylangs->trans("PaymentMode") . ' : <strong>Stancer (CB)</strong><br>' . "\n";
			$content .= 'Transaction ID : <strong>' . $TRANSACTIONID . "</strong><br>\n";
		} elseif (array_key_exists('ORD', $tmptag)) {
			$url = $urlwithroot . "/commande/card.php?id=" . $tmptag['ORD'];
			$orderRef = (is_object($object) && !empty($object->ref)) ? $object->ref : $tmptag['ORD'];
			$content .= '<strong>' . $companylangs->trans("Payment") . "</strong><br><br>\n";
			$content .= $companylangs->trans("Order") . ' : <strong><a href="' . $url . '">' . dol_escape_htmltag($orderRef) . "</a></strong><br>\n";
			if (!empty($thirdpartyName)) {
				$content .= $companylangs->trans("Customer") . ' : <strong>' . dol_escape_htmltag($thirdpartyName) . "</strong><br>\n";
			}
			$content .= $companylangs->trans("Amount") . ' : <strong>' . $FinalPaymentAmt . ' ' . $currencyCodeType . "</strong><br>\n";
			$content .= $companylangs->trans("PaymentMode") . ' : <strong>Stancer (CB)</strong><br>' . "\n";
			$content .= 'Transaction ID : <strong>' . $TRANSACTIONID . "</strong><br>\n";
		} else {
			$content .= $companylangs->transnoentitiesnoconv("NewOnlinePaymentReceived") . "<br><br>\n";
			$content .= $companylangs->trans("Amount") . ' : <strong>' . $FinalPaymentAmt . ' ' . $currencyCodeType . "</strong><br>\n";
			$content .= $companylangs->trans("PaymentMode") . ' : <strong>Stancer (CB)</strong><br>' . "\n";
			$content .= 'Transaction ID : <strong>' . $TRANSACTIONID . "</strong><br>\n";
		}
		// Post-action status
		$content .= "<br>\n";
		$content .= $companylangs->transnoentities("PostActionAfterPayment") . ' : ';
		if ($ispostactionok > 0) {
			$content .= '<font color="green">' . $companylangs->transnoentitiesnoconv("OK") . '</font>';
		} elseif ($ispostactionok == 0) {
			$content .= $companylangs->transnoentitiesnoconv("None");
		} else {
			$topic .= ($ispostactionok ? '' : ' (' . $companylangs->trans("WarningPostActionErrorAfterPayment") . ')');
			$content .= '<font color="red">' . $companylangs->transnoentitiesnoconv("Error") . '</font>';
		}
		$content .= '<br>' . "\n";
		foreach ($postactionmessages as $postactionmessage) {
			$content .= ' * ' . $postactionmessage . '<br>' . "\n";
		}
		if ($ispostactionok < 0) {
			$content .= $langs->transnoentities("ARollbackWasPerformedOnPostActions");
		}
		$content .= '<br>' . "\n";

		// Technical details
		$content .= '<small style="color:#666;">';
		$content .= '<u>' . $companylangs->transnoentitiesnoconv("TechnicalInformation") . ":</u><br>\n";
		$content .= "IP : " . $ipaddress . "<br>\n";
		$content .= "tag=" . $fulltag . "<br>\n";

		if (!empty($ErrorCode)) {
			$content .= "ErrorCode = " . $ErrorCode . "<br>\n";
		}
		if (!empty($ErrorShortMsg)) {
			$content .= "ErrorShortMsg = " . $ErrorShortMsg . "<br>\n";
		}
		if (!empty($ErrorLongMsg)) {
			$content .= "ErrorLongMsg = " . $ErrorLongMsg . "<br>\n";
		}
		if (!empty($ErrorSeverityCode)) {
			$content .= "ErrorSeverityCode = " . $ErrorSeverityCode . "<br>\n";
		}
		$content .= '</small>';

		// Stancer signature
		$content .= "<br><br>\n";
		$content .= '<small style="color:#888;">Mail envoy&eacute; par le module Stancer pour Dolibarr</small>' . "\n";


		$ishtml = 0;
		// May contain urls
		if (dol_textishtml($content)) {
			$ishtml = 1;
		}

		require_once DOL_DOCUMENT_ROOT . '/core/class/CMailFile.class.php';
		$moreinheader = 'X-Dolibarr-Info: stancerPaymentbackSuccess' . "\r\n";
		$mailfile = new CMailFile($topic, $sendto, $from, $content, array(), array(), array(), '', '', 0, $ishtml, '', '', '', $moreinheader);

		$result = $mailfile->sendfile();
		if ($result) {
			dol_syslog("stancer paymentback EMail sent to " . $sendto, LOG_DEBUG, 0, '_payment');
		} else {
			dol_syslog("stancer paymentback Failed to send EMail to " . $sendto, LOG_ERR, 0, '_payment');
		}
	}
} else {
	// Get on url call
	$onlinetoken = empty($PAYPALTOKEN) ? $_SESSION['onlinetoken'] : $PAYPALTOKEN;
	$payerID            = empty($PAYPALPAYERID) ? $_SESSION['payerID'] : $PAYPALPAYERID;
	// Set by newpayment.php
	$paymentType        = $_SESSION['PaymentType'];
	$currencyCodeType   = $_SESSION['currencyCodeType'];
	$FinalPaymentAmt    = $_SESSION["FinalPaymentAmt"];

	if (is_object($object) && method_exists($object, 'call_trigger')) {
		// Call trigger
		$result = $object->call_trigger('PAYMENTONLINE_PAYMENT_KO', $user);
		if ($result < 0) {
			$error++;
		}
		// End call triggers
	}

	// Send failure notification email to customer (template-based with PDF and payment link)
	if (is_object($object) && $object->element == 'facture' && getDolGlobalString('STANCER_AUTO_MAIL_INVOICES_ERROR')) {
		dol_syslog("stancer paymentback payment failed, sending error email to customer for " . $object->ref, LOG_DEBUG);
		stancerSendInvoiceMailModele(
			getDolGlobalString('STANCER_AUTO_MAIL_INVOICES_ERROR', ''),
			$object,
			'BILL_PAYMENTFAILED_SENTBYMAIL',
			1  // forceMail=1: each failed attempt should notify the customer
		);
	}

	$sendemail = '';
	if (getDolGlobalString('PAYMENTONLINE_SENDEMAIL', '') != '') {
		$sendemail = getDolGlobalString('PAYMENTONLINE_SENDEMAIL');
	}
	// TODO Remove local option to keep only the generic one ?
	if ($paymentmethod == 'paypal' && getDolGlobalString('PAYPAL_PAYONLINE_SENDEMAIL', '') != '') {
		$sendemail = getDolGlobalString('PAYPAL_PAYONLINE_SENDEMAIL');
	} elseif ($paymentmethod == 'paybox' && getDolGlobalString('PAYBOX_PAYONLINE_SENDEMAIL', '') != '') {
		$sendemail = getDolGlobalString('PAYBOX_PAYONLINE_SENDEMAIL');
	} elseif ($paymentmethod == 'stripe' && getDolGlobalString('STRIPE_PAYONLINE_SENDEMAIL', '') != '') {
		$sendemail = getDolGlobalString('STRIPE_PAYONLINE_SENDEMAIL');
	}

	// Send warning of error to administrator
	if ($sendemail) {
		$companylangs = new Translate('', $conf);
		$companylangs->setDefaultLang($mysoc->default_lang);
		$companylangs->loadLangs(array('main', 'members', 'bills', 'paypal', 'paybox'));

		$sendto = $sendemail;
		$from = getDolGlobalString('MAIN_MAIL_EMAIL_FROM');
		// Define $urlwithroot
		$urlwithouturlroot = preg_replace('/' . preg_quote(DOL_URL_ROOT, '/') . '$/i', '', trim($dolibarr_main_url_root));
		$urlwithroot = $urlwithouturlroot . DOL_URL_ROOT; // This is to use external domain name found into config file
		//$urlwithroot=DOL_MAIN_URL_ROOT;					// This is to use same domain name than current

		// Define link to login card
		$appli = constant('DOL_APPLICATION_TITLE');
		if (getDolGlobalString('MAIN_APPLICATION_TITLE', '') != '') {
			$appli = getDolGlobalString('MAIN_APPLICATION_TITLE');
			if (preg_match('/\d\.\d/', $appli)) {
				if (!preg_match('/' . preg_quote(DOL_VERSION) . '/', $appli)) {
					$appli .= " (" . DOL_VERSION . ")"; // If new title contains a version that is different than core
				}
			} else {
				$appli .= " " . DOL_VERSION;
			}
		} else {
			$appli .= " " . DOL_VERSION;
		}

		$urlback = $_SERVER["REQUEST_URI"];
		$topic = '[' . $appli . '] ' . $companylangs->transnoentitiesnoconv("ValidationOfPaymentFailed");
		$content = "";
		$content .= '<font color="orange">' . $companylangs->transnoentitiesnoconv("PaymentSystemConfirmPaymentPageWasCalledButFailed") . "</font>\n";

		$content .= "<br><br>\n";
		$content .= '<u>' . $companylangs->transnoentitiesnoconv("TechnicalInformation") . ":</u><br>\n";
		$content .= $companylangs->transnoentitiesnoconv("OnlinePaymentSystem") . ': <strong>' . $paymentmethod . "</strong><br>\n";
		$content .= $companylangs->transnoentitiesnoconv("ReturnURLAfterPayment") . ': ' . $urlback . "<br>\n";
		$content .= "<br>\n";
		$content .= "tag=" . $fulltag . "<br>\ntoken=" . $onlinetoken . "<br>\npaymentType=" . $paymentType . "<br>\ncurrencycodeType=" . $currencyCodeType . "<br>\npayerId=" . $payerID . "<br>\nipaddress=" . $ipaddress . "<br>\nFinalPaymentAmt=" . $FinalPaymentAmt . "<br>\n";


		$ishtml = 0;
		// May contain urls
		if (dol_textishtml($content)) {
			$ishtml = 1;
		}

		require_once DOL_DOCUMENT_ROOT . '/core/class/CMailFile.class.php';
		$moreinheader = 'X-Dolibarr-Info: stancerPaymentbackFailure' . "\r\n";
		$mailfile = new CMailFile($topic, $sendto, $from, $content, array(), array(), array(), '', '', 0, $ishtml, '', '', '', $moreinheader);

		$result = $mailfile->sendfile();
		if ($result) {
			dol_syslog("stancer paymentback EMail sent to " . $sendto, LOG_DEBUG, 0, '_payment');
		} else {
			dol_syslog("stancer paymentback Failed to send EMail to " . $sendto, LOG_ERR, 0, '_payment');
		}
	}
}

dol_syslog("stancer paymentback rendering HTML, ispaymentok=" . ($ispaymentok ? '1' : '0') . ", ispostactionok=$ispostactionok, error=$error, postactionmessages=" . json_encode($postactionmessages), LOG_DEBUG);

if ($ispaymentok) {
	?>
	<section class="text-gray-600 body-font">
		<div class="container mx-auto flex px-5 py-24 md:flex-row flex-col items-center">
			<div class="lg:max-w-sm lg:w-full md:w-1/3 w-5/6 mb-10 md:mb-0">
				<svg class="h-64 w-64 text-green-400" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">
					<path stroke="none" d="M0 0h24v24H0z" />
					<polyline points="9 11 12 14 20 6" />
					<path d="M20 12v6a2 2 0 0 1 -2 2h-12a2 2 0 0 1 -2 -2v-12a2 2 0 0 1 2 -2h9" />
				</svg>
			</div>
			<div class="lg:flex-grow md:w-1/2 lg:pl-24 md:pl-16 flex flex-col md:items-start md:text-left items-center text-center">
				<h1 class="title-font sm:text-4xl text-3xl mb-4 font-medium text-gray-900"><?php echo $langs->trans("StancerPaymentDoneSuccessTitle"); ?></h1>
				<p class="mb-4"><?php echo $langs->trans("StancerPaymentDoneSuccessMessage"); ?></p>
				<ul class="mb-4">
					<li><?php echo $langs->transnoentitiesnoconv("StancerPaymentDoneID", "<b>" . $pid . "</b>"); ?></li>
					<li><?php echo $langs->transnoentitiesnoconv("StancerPaymentInvoiceOrOrder", "<b>" . $object->ref . "</b>"); ?></li>
					<li><?php echo $langs->transnoentitiesnoconv("StancerPaymentDoneStatus", "<b>" . $statusTXT . "</b>"); ?></li>
					<li><?php echo $langs->trans("StancerPaymentAmount", $_SESSION["FinalPaymentAmt"], $_SESSION['currencyCodeType']); ?></li>
				</ul>
				<p><?php echo $langs->transnoentitiesnoconv("StancerPaymentDoneSuccessMessageFacture", dol_print_url($mysoc->url, '_blank', 0, 1)); ?></p>
				<p><?php echo $langs->transnoentitiesnoconv("StancerPaymentDoneEndMessage", dol_print_url($mysoc->url, '_blank', 0, 1)); ?></p>
			</div>
		</div>
	</section>
	<?php
} else {
	//erreur de paiement
	?>
	<section class="text-gray-600 body-font">
		<div class="container mx-auto flex px-5 py-24 md:flex-row flex-col items-center">
			<div class="lg:max-w-sm lg:w-full md:w-1/3 w-5/6 mb-10 md:mb-0">
				<svg class="h-64 w-64 text-red-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
					<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" />
					<line x1="12" y1="9" x2="12" y2="13" />
					<line x1="12" y1="17" x2="12.01" y2="17" />
				</svg>
			</div>
			<div class="lg:flex-grow md:w-1/2 lg:pl-24 md:pl-16 flex flex-col md:items-start md:text-left items-center text-center">
				<h1 class="title-font sm:text-4xl text-3xl mb-4 font-medium text-gray-900"><?php echo $langs->trans("StancerPaymentDoneErrorTitle"); ?></h1>
				<p class="mb-4"><?php echo $langs->trans("StancerPaymentDoneErrorMessage"); ?></p>
				<ul class="mb-4">
					<li><?php echo $langs->transnoentitiesnoconv("StancerPaymentDoneID", "<b>" . $pid . "</b>"); ?></li>
					<li><?php echo $langs->transnoentitiesnoconv("StancerPaymentInvoiceOrOrder", "<b>" . $object->ref . "</b>"); ?></li>
					<li><?php echo $langs->transnoentitiesnoconv("StancerPaymentDoneErrorMessageShortByStancer", "<b>" . $statusTXT . "</b>"); ?></li>
					<li><?php echo $langs->transnoentitiesnoconv("StancerPaymentDoneErrorSeverityStancer", $ErrorSeverityCode); ?></li>
				</ul>
				<p><?php echo $langs->transnoentitiesnoconv("StancerPaymentDoneErrorEndMessage", $mysoc->email); ?></p>
			</div>
		</div>
	</section>

	<?php
}

print "\n</div>\n";


if (((int) DOL_VERSION) < 18) {
	// Renamed into htmlPrintOnlineFooter() in Dolibarr 18, kept for older versions
	// @phpstan-ignore-next-line
	// @phan-suppress-next-line PhanUndeclaredFunction
	htmlPrintOnlinePaymentFooter($mysoc, $langs);
} else {
	// @phpstan-ignore-next-line
	htmlPrintOnlineFooter($mysoc, $langs);
}


// Clean session variables to avoid duplicate actions if post is resent
unset($_SESSION["FinalPaymentAmt"]);
unset($_SESSION["TRANSACTIONID"]);


llxFooter('', 'public', 1);

$db->close();
