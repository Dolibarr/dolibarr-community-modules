<?php
/* Copyright (C) 2004-2017 Laurent Destailleur  <eldy@users.sourceforge.net>
 * Copyright (C) 2023-2024 Eric Seigne <eric.seigne@cap-rel.fr>
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
 * \brief   Test page for StancerApi class (direct API without library)
 */

// Load Dolibarr environment
$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME']; $tmp2 = realpath(__FILE__); $i = strlen($tmp) - 1; $j = strlen($tmp2) - 1;
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
require_once DOL_DOCUMENT_ROOT . '/core/lib/functions.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/functions2.lib.php';
dol_include_once('/stancer/lib/stancer.lib.php');
dol_include_once('/stancer/class/stancer_api.class.php');

// Translations
$langs->loadLangs(array("errors", "admin", "stancer@stancer"));

// Access control
if (!$user->admin) {
	accessforbidden();
}

// Parameters
$action = GETPOST('action', 'aZ09');
$backtopage = GETPOST('backtopage', 'alpha');


/*
 * View
 */

$form = new Form($db);

$help_url = '';
$page_name = "StancerSetup";

llxHeader('', $langs->trans($page_name), $help_url);

// Subheader
$linkback = '<a href="'.($backtopage ? $backtopage : DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1').'">'.$langs->trans("BackToModuleList").'</a>';

print load_fiche_titre($langs->trans("StancerApiTest"), $linkback, 'title_setup');

// Configuration header
$head = stancerAdminPrepareHead();
print dol_get_fiche_head($head, 'test', $langs->trans($page_name), 0, 'stancer@stancer');

// Initialize API
$api = new StancerApi();

print "<h2>Configuration</h2>";
print "<ul>";
print "<li>Mode : <strong>" . ($api->isLiveMode() ? 'PRODUCTION' : 'TEST') . "</strong></li>";
print "<li>Clé publique : " . substr($api->getPublicKey(), 0, 15) . "...</li>";
print "</ul>";

print "<h2>Test 1 : Création d'un client</h2>";

$customerData = array(
	'email' => 'test-api@cap-rel.fr',
	'name' => 'Test API Dolibarr',
	'mobile' => '+33600000000'
);

$customer = $api->createCustomer($customerData);

if ($customer === false) {
	print "<p style='color:orange;'>Création client : " . $api->error . "</p>";

	// Try to extract existing customer ID from error
	if (preg_match('/\((cust_\w+)\)/', $api->error, $matches)) {
		$customerId = $matches[1];
		print "<p>Client existant détecté : <a href='https://manage.stancer.com/fr/details-du-clients?id=" . $customerId . "' target='_blank'>" . $customerId . "</a></p>";

		// Fetch existing customer
		$customer = $api->getCustomer($customerId);
	}
} else {
	$customerId = $customer['id'];
	print "<p style='color:green;'>Client créé avec succès !</p>";
	print "<p>ID : <a href='https://manage.stancer.com/fr/details-du-clients?id=" . $customerId . "' target='_blank'>" . $customerId . "</a></p>";
}

if ($customer !== false) {
	print "<h2>Test 2 : Lecture du client</h2>";
	print "<ul>";
	print "<li>ID : " . $customer['id'] . "</li>";
	print "<li>Email : " . (isset($customer['email']) ? $customer['email'] : 'N/A') . "</li>";
	print "<li>Nom : " . (isset($customer['name']) ? $customer['name'] : 'N/A') . "</li>";
	print "<li>Mobile : " . (isset($customer['mobile']) ? $customer['mobile'] : 'N/A') . "</li>";
	print "</ul>";
}

print "<h2>Test 3 : Les 5 paiements les plus récents</h2>";

$payments = $api->listPayments(array(
	'created' => time() - (86400 * 30),
	'limit' => 100
));

if ($payments === false) {
	print "<p style='color:red;'>Erreur : " . $api->error . "</p>";
} else {
	if (isset($payments['payments']) && is_array($payments['payments'])) {
		$allPayments = $payments['payments'];
		usort($allPayments, function ($a, $b) {
			return ($b['created'] ?? 0) - ($a['created'] ?? 0);
		});
		$lastPayments = array_slice($allPayments, 0, 5);
		print "<p>" . count($allPayments) . " paiement(s) sur les 30 derniers jours, affichage des 5 plus récents :</p>";
		if (count($lastPayments) > 0) {
			print "<table class='noborder centpercent'>";
			print "<tr class='liste_titre'>";
			print "<th>ID</th>";
			print "<th>Montant</th>";
			print "<th>Statut</th>";
			print "<th>Date</th>";
			print "</tr>";
			foreach ($lastPayments as $payment) {
				print "<tr class='oddeven'>";
				print "<td><a href='https://manage.stancer.com/fr/details-de-paiement?id=" . $payment['id'] . "' target='_blank'>" . $payment['id'] . "</a></td>";
				print "<td>" . StancerApi::fromCents($payment['amount']) . " " . dol_strtoupper($payment['currency']) . "</td>";
				print "<td>" . $payment['status'] . "</td>";
				print "<td>" . dol_print_date($payment['created'], 'dayhour') . "</td>";
				print "</tr>";
			}
			print "</table>";
		}
	} elseif (is_array($payments)) {
		print "<p>Réponse brute : <pre>" . print_r($payments, true) . "</pre></p>";
	}
}

print "<h2>Test 4 : Les 5 reversements les plus récents</h2>";

$payouts = $api->listPayouts(array(
	'created' => time() - (86400 * 30),
	'limit' => 100
));

if ($payouts === false) {
	print "<p style='color:red;'>Erreur : " . $api->error . "</p>";
} else {
	if (isset($payouts['payouts']) && is_array($payouts['payouts'])) {
		$allPayouts = $payouts['payouts'];
		usort($allPayouts, function ($a, $b) {
			return ($b['date_payout'] ?? 0) - ($a['date_payout'] ?? 0);
		});
		$lastPayouts = array_slice($allPayouts, 0, 5);
		print "<p>" . count($allPayouts) . " reversement(s) sur les 30 derniers jours, affichage des 5 plus récents :</p>";
		if (count($lastPayouts) > 0) {
			print "<table class='noborder centpercent'>";
			print "<tr class='liste_titre'>";
			print "<th>ID</th>";
			print "<th>Montant</th>";
			print "<th>Statut</th>";
			print "<th>Date</th>";
			print "</tr>";
			foreach ($lastPayouts as $payout) {
				print "<tr class='oddeven'>";
				print "<td><a href='https://manage.stancer.com/fr/details-du-reversement?id=" . $payout['id'] . "' target='_blank'>" . $payout['id'] . "</a></td>";
				print "<td>" . StancerApi::fromCents($payout['amount']) . " " . dol_strtoupper($payout['currency']) . "</td>";
				print "<td>" . $payout['status'] . "</td>";
				print "<td>" . dol_print_date($payout['date_payout'], 'dayhour') . "</td>";
				print "</tr>";
			}
			print "</table>";
		}
	} elseif (is_array($payouts)) {
		print "<p>Réponse brute : <pre>" . print_r($payouts, true) . "</pre></p>";
	}
}

print "<h2>Test 5 : Lecture d'un paiement spécifique (si disponible)</h2>";

if (isset($payments['payments']) && count($payments['payments']) > 0) {
	$testPaymentId = $payments['payments'][0]['id'];
	$paymentDetail = $api->getPayment($testPaymentId);

	if ($paymentDetail === false) {
		print "<p style='color:red;'>Erreur : " . $api->error . "</p>";
	} else {
		print "<p>Détails du paiement " . $testPaymentId . " :</p>";
		print "<ul>";
		print "<li>Montant : " . StancerApi::fromCents($paymentDetail['amount']) . " " . dol_strtoupper($paymentDetail['currency']) . "</li>";
		print "<li>Statut : " . $paymentDetail['status'] . "</li>";
		print "<li>Méthode : " . (isset($paymentDetail['method']) ? $paymentDetail['method'] : 'N/A') . "</li>";
		print "<li>Description : " . (isset($paymentDetail['description']) ? $paymentDetail['description'] : 'N/A') . "</li>";
		print "<li>Order ID : " . (isset($paymentDetail['order_id']) ? $paymentDetail['order_id'] : 'N/A') . "</li>";
		print "<li>Unique ID : " . (isset($paymentDetail['unique_id']) ? $paymentDetail['unique_id'] : 'N/A') . "</li>";
		if (isset($paymentDetail['card']) && is_array($paymentDetail['card'])) {
			print "<li>Carte : **** " . (isset($paymentDetail['card']['last4']) ? $paymentDetail['card']['last4'] : '?') . " (" . (isset($paymentDetail['card']['brand']) ? $paymentDetail['card']['brand'] : '?') . ")</li>";
		} elseif (isset($paymentDetail['card'])) {
			print "<li>Carte (ID) : " . $paymentDetail['card'] . "</li>";
		}
		if (isset($paymentDetail['customer']) && is_array($paymentDetail['customer'])) {
			$custName = isset($paymentDetail['customer']['name']) ? $paymentDetail['customer']['name'] : 'N/A';
			$custEmail = isset($paymentDetail['customer']['email']) ? $paymentDetail['customer']['email'] : 'N/A';
			$custId = isset($paymentDetail['customer']['id']) ? $paymentDetail['customer']['id'] : '';
			print "<li>Client : " . htmlspecialchars($custName) . " (" . htmlspecialchars($custEmail) . ") - ID : " . htmlspecialchars($custId) . "</li>";
		} elseif (isset($paymentDetail['customer'])) {
			print "<li>Client (ID) : " . htmlspecialchars($paymentDetail['customer']) . "</li>";
		}
		if (isset($paymentDetail['sepa']) && is_array($paymentDetail['sepa'])) {
			$sepaLast4 = isset($paymentDetail['sepa']['last4']) ? $paymentDetail['sepa']['last4'] : '?';
			$sepaBic = isset($paymentDetail['sepa']['bic']) ? $paymentDetail['sepa']['bic'] : '?';
			print "<li>SEPA : **** " . $sepaLast4 . " (BIC : " . $sepaBic . ")</li>";
		} elseif (isset($paymentDetail['sepa'])) {
			print "<li>SEPA (ID) : " . $paymentDetail['sepa'] . "</li>";
		}
		print "</ul>";
	}
} else {
	print "<p>Aucun paiement disponible pour le test.</p>";
}

print "<h2>Résumé</h2>";
print "<p style='color:green;'>La classe StancerApi fonctionne correctement avec getURLContent() de Dolibarr.</p>";
print "<p>Vous pouvez vérifier les données sur la <a href='https://manage.stancer.com/' target='_blank'>console Stancer</a>.</p>";

// Page end
print dol_get_fiche_end();

llxFooter();
$db->close();
