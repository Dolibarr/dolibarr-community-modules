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
 * Create the member extra fields of the association mode (STANCER_ASSO_ACTIVE).
 * addExtraField() is re-entrant: an existing field is left as is.
 *
 * @param   DoliDB  $db  Database handler
 * @return  int          Number of fields that could not be created
 */
function stancerEnsureMemberExtrafields($db)
{
	include_once DOL_DOCUMENT_ROOT . '/core/class/extrafields.class.php';
	$extrafields = new ExtraFields($db);
	// $size, $list and $help are declared as string by ExtraFields::addExtraField().
	// '32' is the column length, '0' hides the field from lists, '' means no tooltip.
	$fields = array(
		array('stancer_sepa_ref', 'StancerSEPAstart', 1, 0),
		array('stancer_cb_ref', 'StancerCardStart', 2, 0),
		array('stancer_account', 'StancerAccount', 3, 1),
	);
	$failed = 0;
	foreach ($fields as $field) {
		$res = $extrafields->addExtraField($field[0], $field[1], 'varchar', $field[2], '32', 'adherent', $field[3], 0, '', '', 1, '', '0', '', '', '', 'stancer@stancer', '$conf->stancer->enabled');
		if ($res < 0) {
			$failed++;
			dol_syslog("stancerEnsureMemberExtrafields: cannot create member extra field " . $field[0] . ": " . $extrafields->error, LOG_ERR);
		}
	}

	return $failed;
}

/**
 * Date of the most recent event of a list returned by ActionComm::getActions(),
 * whatever the sort order of that list.
 *
 * @param   array|int  $actions  Events, or a negative error code
 * @return  int                  Timestamp, 0 when there is none
 */
function stancerMostRecentActionDate($actions)
{
	$latest = 0;
	if (is_array($actions)) {
		foreach ($actions as $action) {
			if (is_object($action) && !empty($action->datep) && (int) $action->datep > $latest) {
				$latest = (int) $action->datep;
			}
		}
	}

	return $latest;
}

/**
 * Mandate reference (RUM) of the Stancer SEPA mandate of a thirdparty, default mandate first.
 *
 * @param   int     $socid  Thirdparty id
 * @return  string          RUM, empty string when the thirdparty has no Stancer mandate
 */
function stancerGetSepaRum($socid)
{
	global $db;

	$sql = "SELECT rum FROM " . MAIN_DB_PREFIX . "societe_rib";
	$sql .= " WHERE fk_soc = " . ((int) $socid) . " AND type = 'ban' AND label LIKE 'stancer-sepa%' AND rum <> ''";
	$sql .= " ORDER BY default_rib DESC, rowid DESC";
	$resql = $db->query($sql . $db->plimit(1));
	if (!$resql) {
		dol_syslog("stancerGetSepaRum: " . $db->lasterror(), LOG_ERR);
		return '';
	}
	$obj = $db->fetch_object($resql);

	return $obj ? (string) $obj->rum : '';
}

/**
 * Stop the page unless the user may run a write action reached by a link:
 * Stancer write right and session token. Refresh actions create bank lines,
 * reopen invoices and send emails, so a forged GET must not trigger them.
 *
 * @param   int|bool  $permissiontoadd  Write permission computed by the page
 * @param   string    $context          Page and action, for the log
 * @return  void
 */
function stancerCheckWriteActionAllowed($permissiontoadd, $context)
{
	global $user;

	if (!$permissiontoadd) {
		dol_syslog("stancer " . $context . ": denied for user " . (int) $user->id . " without write permission", LOG_WARNING);
		accessforbidden();
	}
	if (GETPOST('token', 'alpha') === '' || GETPOST('token', 'alpha') !== currentToken()) {
		dol_syslog("stancer " . $context . ": denied, missing or invalid token for user " . (int) $user->id, LOG_WARNING);
		accessforbidden('Invalid CSRF token');
	}
}

/**
 * Move the Stancer bank entries left on a 471 suspense account to a fee account.
 *
 * The predicate sits directly in the WHERE clause: MySQL refuses a subquery
 * reading the table being updated (error 1093).
 *
 * @param   string  $accountNumber  Accounting account to assign (627xxx)
 * @return  int                     Number of entries updated, -1 on error
 */
function stancerBookkeepingFixFeeAccount($accountNumber)
{
	global $db;

	$sql = "UPDATE " . MAIN_DB_PREFIX . "accounting_bookkeeping SET numero_compte = '" . $db->escape($accountNumber) . "'";
	$sql .= " WHERE numero_compte LIKE '471%' AND doc_type = 'bank'";
	$sql .= " AND (doc_ref LIKE '%Stancer%' OR label_operation LIKE '%Stancer%')";
	$sql .= " AND entity IN (" . getEntity('accountancy') . ")";
	$resql = $db->query($sql);
	if (!$resql) {
		dol_syslog("stancerBookkeepingFixFeeAccount: " . $db->lasterror(), LOG_ERR);
		return -1;
	}

	return (int) $db->affected_rows($resql);
}

/**
 * Resolve a log file name received by admin/logs.php into a readable path.
 *
 * Only the base name is kept, it must end with ".log", and the resolved file
 * must sit directly in the data root: anything else (conf.php through "..",
 * a symlink pointing elsewhere) is refused.
 *
 * @param   string  $name  File name received from the request
 * @return  string         Real path of the log file, empty string when refused
 */
function stancerResolveLogFile($name)
{
	$base = basename((string) $name);
	if ($base === '' || substr($base, -4) !== '.log') {
		dol_syslog("stancerResolveLogFile: refused name " . $base, LOG_WARNING);
		return '';
	}
	$root = realpath(DOL_DATA_ROOT);
	$path = realpath(DOL_DATA_ROOT . '/' . $base);
	if ($root === false || $path === false || dirname($path) !== $root || !is_file($path)) {
		dol_syslog("stancerResolveLogFile: " . $base . " is not a log file of the data root", LOG_WARNING);
		return '';
	}

	return $path;
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

/**
 * Return the Stancer logo, sized for an inline use (button, frame legend...).
 *
 * @param  int    $height Height in pixels
 * @return string         HTML of the logo
 */
function stancerBrandLogo($height = 16)
{
	$height = max(8, (int) $height);

	return '<img src="' . dol_buildpath('/stancer/img/object_stancer.png', 1) . '" alt="Stancer" height="' . $height . '" width="' . $height . '" style="vertical-align: middle;">';
}

/**
 * Wrap the Stancer payment button in a bordered block so the customer sees at a glance
 * that this payment method belongs to Stancer, and not to another payment module of the page.
 *
 * @param  string $content Inner HTML (button, hidden inputs, scripts)
 * @return string          HTML of the framed block
 */
function stancerPaymentFrame($content)
{
	global $langs;

	$out = '<fieldset style="border: 1px solid rgba(128, 128, 128, 0.4); border-radius: 8px; padding: 8px 16px 16px; margin: 1em 0; min-width: 0;">';
	$out .= '<legend style="padding: 0 8px; font-weight: bold; white-space: nowrap;">';
	$out .= stancerBrandLogo(16);
	$out .= '<span style="vertical-align: middle; margin-left: 8px;">' . $langs->trans('StancerPaymentFrameTitle') . '</span>';
	$out .= '</legend>';
	$out .= $content;
	$out .= '</fieldset>';

	return $out;
}


// Sub-library files
dol_include_once('/stancer/lib/stancer_customer.lib.php');
dol_include_once('/stancer/lib/stancer_payment.lib.php');
dol_include_once('/stancer/lib/stancer_bank.lib.php');
dol_include_once('/stancer/lib/stancer_refresh.lib.php');
dol_include_once('/stancer/lib/stancer_mail.lib.php');
dol_include_once('/stancer/lib/stancer_dispute.lib.php');
