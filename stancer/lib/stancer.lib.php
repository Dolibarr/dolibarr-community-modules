<?php
/* Copyright (C) 2023 Eric Seigne <eric.seigne@cap-rel.fr>
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
 * \file    stancer/lib/stancer.lib.php
 * \ingroup stancer
 * \brief   Library files with common functions for Stancer
 */
dol_include_once('/stancer/backport/functions.php');

require_once DOL_DOCUMENT_ROOT . "/core/lib/company.lib.php";
require_once DOL_DOCUMENT_ROOT . '/core/class/html.formfile.class.php';
require_once DOL_DOCUMENT_ROOT . "/societe/class/societe.class.php";
require_once DOL_DOCUMENT_ROOT . "/contact/class/contact.class.php";
require_once DOL_DOCUMENT_ROOT . '/societe/class/companypaymentmode.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/geturl.lib.php';
require_once DOL_DOCUMENT_ROOT . '/compta/bank/class/account.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/payments.lib.php';
require_once DOL_DOCUMENT_ROOT . '/don/class/paymentdonation.class.php';
require_once DOL_DOCUMENT_ROOT . '/societe/class/companybankaccount.class.php';
require_once DOL_DOCUMENT_ROOT . '/compta/prelevement/class/bonprelevement.class.php';
require_once DOL_DOCUMENT_ROOT . "/commande/class/commande.class.php";
require_once DOL_DOCUMENT_ROOT . '/comm/action/class/actioncomm.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/class/CMailFile.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/class/html.formmail.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/modules/barcode/doc/tcpdfbarcode.modules.php';
require_once DOL_DOCUMENT_ROOT . '/don/class/don.class.php';
require_once DOL_DOCUMENT_ROOT . '/comm/propal/class/propal.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/security2.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/functions.lib.php';
require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';

/**
 * isModEnabled() aware of the module keys renamed by recent Dolibarr releases
 *
 * Dolibarr renamed several module keys: 'facture' became 'invoice', 'commande'
 * became 'order' and 'adherent' became 'member'. The conversion table inside
 * isModEnabled() only covers a handful of keys (bank, category, contract,
 * project, delivery_note), so on a release predating the rename,
 * isModEnabled('invoice') silently returns false while the invoice module IS
 * enabled. Every invoice related treatment of this module would then be
 * skipped, without any error.
 *
 * Measured on Dolibarr 18.0.8, with the modules enabled:
 *   isModEnabled('facture')  = true   isModEnabled('invoice') = false
 *   isModEnabled('commande') = true   isModEnabled('order')   = false
 *   isModEnabled('adherent') = true   isModEnabled('member')  = false
 *   isModEnabled('banque')   = true   isModEnabled('bank')    = true
 *
 * The core itself only switched to the modern keys in Dolibarr 23, while this
 * module supports Dolibarr 15 and above: both spellings must therefore be
 * handled. Callers use the modern key, this helper maps it back to the
 * historical one on older releases. The historical keys stay valid on recent
 * releases too, so the fallback is safe in every case.
 *
 * @param   string  $module   Modern module key ('invoice', 'order', 'member', ...)
 * @return  bool              True when the module is enabled
 */
function stancerIsModEnabled($module)
{
	// Dolibarr 23 is the first release whose core uses the modern keys.
	if (((int) DOL_VERSION) >= 23) {
		return (bool) isModEnabled($module);
	}

	// Historical keys, the only ones understood before the rename. The argument
	// is a variable on purpose: these legacy names must remain reachable.
	$legacyKeys = array(
		'invoice' => 'facture',
		'order'   => 'commande',
		'member'  => 'adherent',
	);
	$key = isset($legacyKeys[$module]) ? $legacyKeys[$module] : $module;

	return (bool) isModEnabled($key);
}

//a partir de dolibarr 16 la lib php-iban fonctionne correctement pour convertir un iban en rib...
if (floatval(DOL_VERSION) < 16.0) {
	dol_include_once('/stancer/backport/php-iban/oophp-iban.php');
} else {
	require_once DOL_DOCUMENT_ROOT . '/includes/php-iban/oophp-iban.php';
}

// Legacy Stancer PHP library - removed after migration to StancerApi
// dol_include_once('/stancer/vendor/autoload.php');
dol_include_once('/stancer/class/companypaymentmodestancer.class.php');
dol_include_once('/stancer/class/stancer_payments.class.php');
dol_include_once('/stancer/class/stancer_payouts.class.php');
dol_include_once('/stancer/class/stancer.class.php');
dol_include_once('/stancer/class/adherentstancer.class.php');

// New direct API client (no external library dependency)
dol_include_once('/stancer/class/stancer_api.class.php');

// Legacy lib initialization - commented out after migration to StancerApi
// $stancer = Stancer\Config::init([stancer_get_public_key(), stancer_get_private_key()]);
// if (getDolGlobalString('STANCER_IS_PROD', '0') == '1') {
// 	$stancer->setMode(Stancer\Config::LIVE_MODE);
// } else {
// 	$stancer->setMode(Stancer\Config::TEST_MODE);
// }

// New API client - global instance
$stancerApi = new StancerApi();

//check stancer module version vs last init version in database
dol_include_once('/stancer/core/modules/modStancer.class.php');
if (isset($db)) {
	$tmpmodule = new modStancer($db);
	if ($tmpmodule->version != getDolGlobalString('STANCER_MODULE_VERSION')) {
		setEventMessages($langs->trans("ErrorStancerModuleVersionDatabase"), [], 'errors');
	}
}
// debug stancer
// $log = new Monolog\Logger('Stancer');
// $log->pushHandler(new Monolog\Handler\StreamHandler('/tmp/stancer.log', Monolog\Logger::DEBUG));
// $stancer->setLogger($log);
// $stancer->setDebug(true);

/**
 * get stancer public key
 *
 * @return	string	Live or test public API key depending on STANCER_IS_PROD, empty string when not configured
 */
function stancer_get_public_key()
{
	global $conf;
	if (getDolGlobalString('STANCER_IS_PROD', '0') == '1') {
		return getDolGlobalString('STANCER_PROD_PUBLIC_KEY', '');
	} else {
		return getDolGlobalString('STANCER_TEST_PUBLIC_KEY', '');
	}
}

/**
 * get stancer private key
 *
 * @return	string	Live or test private API key depending on STANCER_IS_PROD, empty string when not configured
 */
function stancer_get_private_key()
{
	global $conf;
	if (getDolGlobalString('STANCER_IS_PROD', '0') == '1') {
		return getDolGlobalString('STANCER_PROD_PRIVATE_KEY', '');
	} else {
		return getDolGlobalString('STANCER_TEST_PRIVATE_KEY', '');
	}
}

/**
 * Prepare admin pages header
 *
 * @return array<int,array<int,string>>	Array of tabs, each tab being array(url, label, code)
 */
function stancerAdminPrepareHead()
{
	global $langs, $conf;

	// global $db;
	// $extrafields = new ExtraFields($db);
	// $extrafields->fetch_name_optionals_label('myobject');

	$langs->loadLangs(array("stancer@stancer"));

	$h = 0;
	$head = array();

	$head[$h][0] = dol_buildpath("/stancer/admin/setup.php", 1);
	$head[$h][1] = $langs->trans("StancerSettingsMenu");
	$head[$h][2] = 'StancerSettingsMenu';
	$h++;

	/*
	$head[$h][0] = dol_buildpath("/stancer/admin/myobject_extrafields.php", 1);
	$head[$h][1] = $langs->trans("ExtraFields");
	$nbExtrafields = is_countable($extrafields->attributes['myobject']['label']) ? count($extrafields->attributes['myobject']['label']) : 0;
	if ($nbExtrafields > 0) {
		$head[$h][1] .= ' <span class="badge">' . $nbExtrafields . '</span>';
	}
	$head[$h][2] = 'myobject_extrafields';
	$h++;
	*/
	$head[$h][0] = dol_buildpath("/stancer/admin/cb.php", 1);
	$head[$h][1] = $langs->trans("StancerCBMenu");
	$head[$h][2] = 'StancerCBMenu';
	$h++;

	$head[$h][0] = dol_buildpath("/stancer/admin/sepa.php", 1);
	$head[$h][1] = $langs->trans("StancerSEPAMenu");
	$head[$h][2] = 'StancerSEPAMenu';
	$h++;

	$head[$h][0] = dol_buildpath("/stancer/admin/mail.php", 1);
	$head[$h][1] = $langs->trans("StancerMailMenu");
	$head[$h][2] = 'StancerMailMenu';
	$h++;

	$head[$h][0] = dol_buildpath("/stancer/admin/asso.php", 1);
	$head[$h][1] = $langs->trans("StancerAssoMenu");
	$head[$h][2] = 'StancerAssoMenu';
	$h++;

	$head[$h][0] = dol_buildpath("/stancer/admin/compta.php", 1);
	$head[$h][1] = $langs->trans("StancerComptaMenu");
	$head[$h][2] = 'StancerComptaMenu';
	$h++;

	$head[$h][0] = dol_buildpath("/stancer/admin/test.php", 1);
	$head[$h][1] = $langs->trans("Test");
	$head[$h][2] = 'test';
	$h++;

	$head[$h][0] = dol_buildpath("/stancer/admin/about.php", 1);
	$head[$h][1] = $langs->trans("About");
	$head[$h][2] = 'about';
	$h++;

	// Show more tabs from modules
	// Entries must be declared in modules descriptor with line
	//$this->tabs = array(
	//    'entity:+tabname:Title:@stancer:/stancer/mypage.php?id=__ID__'
	//); // to add new tab
	//$this->tabs = array(
	//    'entity:-tabname:Title:@stancer:/stancer/mypage.php?id=__ID__'
	//); // to remove a tab
	complete_head_from_modules($conf, $langs, null, $head, $h, 'stancer@stancer');

	complete_head_from_modules($conf, $langs, null, $head, $h, 'stancer@stancer', 'remove');

	return $head;
}


// Sub-library files
dol_include_once('/stancer/lib/stancer_customer.lib.php');
dol_include_once('/stancer/lib/stancer_payment.lib.php');
dol_include_once('/stancer/lib/stancer_bank.lib.php');
dol_include_once('/stancer/lib/stancer_refresh.lib.php');
dol_include_once('/stancer/lib/stancer_mail.lib.php');
dol_include_once('/stancer/lib/stancer_dispute.lib.php');
