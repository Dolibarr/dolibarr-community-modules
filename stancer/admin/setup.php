<?php
/* Copyright (C) 2004-2017 Laurent Destailleur  <eldy@users.sourceforge.net>
 * Copyright (C) 2023-2025 Eric Seigne <eric.seigne@cap-rel.fr>
 * Copyright (C) 2025 <contact@lesmetiersdubatiment.fr>
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
 * \file    stancer/admin/setup.php
 * \ingroup stancer
 * \brief   Stancer setup page.
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
	$i--; $j--;
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

global $langs, $user;

// Libraries
require_once DOL_DOCUMENT_ROOT."/core/lib/admin.lib.php";
require_once DOL_DOCUMENT_ROOT . '/core/lib/functions.lib.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';
dol_include_once('/stancer/lib/stancer.lib.php');
dol_include_once('/stancer/lib/stancer_validators.lib.php');

//require_once "../class/myclass.class.php";

// Translations
$langs->loadLangs(array("admin", "stancer@stancer"));

// Initialize technical object to manage hooks of page. Note that conf->hooks_modules contains array of hook context
$hookmanager->initHooks(array('stancersetup', 'globalsetup'));

// Access control
if (!$user->admin) {
	accessforbidden();
}

// Parameters
$action = GETPOST('action', 'aZ09');
$backtopage = GETPOST('backtopage', 'alpha');
$modulepart = GETPOST('modulepart', 'aZ09');	// Used by actions_setmoduleoptions.inc.php

$value = GETPOST('value', 'alpha');
$label = GETPOST('label', 'alpha');
$scandir = GETPOST('scan_dir', 'alpha');
$type = '';


$error = 0;
$setupnotempty = 0;

// Set this to 1 to use the factory to manage constants. Warning, the generated module will be compatible with version v15+ only
$useFormSetup = 1;

if (!class_exists('FormSetup')) {
	// For retrocompatibility Dolibarr < 16.0
	// if (floatval(DOL_VERSION) < 16.0 && !class_exists('FormSetup')) {
		dol_include_once('/stancer/backport/v16/core/class/html.formsetup.class.php');
	// } else {
	// require_once DOL_DOCUMENT_ROOT.'/core/class/html.formsetup.class.php';
	// }
}

$formSetup = new FormSetup($db);
$form = new Form($db);

// HTTP HOST
$item = $formSetup->newItem('MainAPIURI');
$item->fieldOverride = "https://api.stancer.com/";
$item->cssClass = 'minwidth500';

// List of Dolibarr user accounts that can be linked to Stancer actions
$conf->use_javascript_ajax = 1;
$formSetup->newItem('STANCER_USER_ACCOUNT_FOR_ACTIONS')->setAsSelectUser();

$compteid = null;
// $liste = $form->select_comptes($compteid, 'STANCER_BANK_ACCOUNT_FOR_PAYMENTS', 0, "courant=1", 2,'',0,'',1);
$sql = "SELECT rowid, label,ref FROM ".MAIN_DB_PREFIX."bank_account";
$sql.= " WHERE entity = '".getEntity('bank_account')."' ";
$result = $db->query($sql);
$optionsStancer = array();
$options = array();
if ($result) {
	while ($obj = $db->fetch_object($result)) {
		$options[$obj->rowid] = $obj->label;
		if ($obj->ref == "STANCER") {
			$optionsStancer[$obj->rowid] = $obj->label;
		}
	}
}
//note il faut utiliser des noms particuliers pour bénéficier du masquage de données:
//if (!preg_match('/^MAIN_LOGEVENTS/', $name) && (preg_match('/(_KEY|_EXPORTKEY|_SECUREKEY|_SERVERKEY|_PASS|_PASSWORD|_PW|_PW_TICKET|_PW_EMAILING|_SECRET|_SECURITY_TOKEN|_WEB_TOKEN)$/', $name))) {


$formSetup->newItem('STANCER_BANK')->setAsTitle();
$formSetup->newItem('STANCER_BANK_ACCOUNT_FOR_PAYMENTS')->setAsSelect($optionsStancer);
$formSetup->newItem('STANCER_BANK_MAIN_ACCOUNT_FOR_PAYOUTS')->setAsSelect($options);

$item = $formSetup->newItem('STANCER_ADD_FEES');
$TField = array(
	'NONE' => $langs->trans('STANCER_ADD_FEES_ON_BANK_NONE'),
	'PAYOUT' => $langs->trans('STANCER_ADD_FEES_ON_BANK_FOR_EACH_PAYOUT'),
	//note par rapport a la factuation ça semble tres complexe
	'PAYMENT' => $langs->trans('STANCER_ADD_FEES_ON_BANK_FOR_EACH_PAYMENT'),
);

$item->setAsSelect($TField);
$item->helpText = $langs->transnoentities('STANCER_ADD_FEESTooltip');

$formSetup->newItem('STANCER_IS_PROD')->setAsYesNo();

$item = $formSetup->newItem('STANCER_SHOW_RAW_API_PICTO');
$item->setAsYesNo();
$item->helpText = $langs->transnoentities('STANCER_SHOW_RAW_API_PICTOTooltip');

// Stancer key prefix rules come from stancerApiKeyRules() in
// lib/stancer_validators.lib.php (single source of truth shared with the
// server-side validator and unit tests).
$stancerKeyRules = stancerApiKeyRules();
$formSetup->newItem('STANCER_TEST_PARAMS')->setAsTitle();
foreach (array('STANCER_TEST_PUBLIC_KEY', 'STANCER_TEST_PRIVATE_KEY') as $constName) {
	$item = $formSetup->newItem($constName);
	$item->fieldAttr['pattern'] = $stancerKeyRules[$constName]['pattern'];
	$item->fieldAttr['title'] = $langs->trans('STANCER_KEY_HINT', $stancerKeyRules[$constName]['prefix']);
	$item->fieldAttr['placeholder'] = $stancerKeyRules[$constName]['prefix'].'...';
}


$formSetup->newItem('STANCER_PROD_PARAMS')->setAsTitle();
foreach (array('STANCER_PROD_PUBLIC_KEY', 'STANCER_PROD_PRIVATE_KEY') as $constName) {
	$item = $formSetup->newItem($constName);
	$item->fieldAttr['pattern'] = $stancerKeyRules[$constName]['pattern'];
	$item->fieldAttr['title'] = $langs->trans('STANCER_KEY_HINT', $stancerKeyRules[$constName]['prefix']);
	// Show only the first acceptable prefix as placeholder to keep it short.
	$firstPrefix = explode('/', $stancerKeyRules[$constName]['prefix'])[0];
	$item->fieldAttr['placeholder'] = $firstPrefix.'...';
}

$item = $formSetup->newItem('STANCER_NUMBER_OF_ITEMS_TO_SYNC');
$TField = array(
	10 => '10',
	20 => '20',
	30 => '30',
	40 => '40',
	50 => '50',
	60 => '60',
	70 => '70',
	80 => '80',
	90 => '90',
	100 => '100',
);
$item->setAsSelect($TField);
$item->helpText = $langs->transnoentities('STANCER_NUMBER_OF_ITEMS_TO_SYNCTooltip');

$item = $formSetup->newItem('STANCER_NB_DAYS_TO_SYNC');
$TField = array(
	10 => '10',
	20 => '20',
	30 => '30 (conseillé)',
	40 => '40',
	50 => '50',
	60 => '2 mois',
	90 => '3 mois',
	365 => '1 an (uniquement en cas de besoin)',
	730 => '2 ans (uniquement en cas de besoin)'
);
$item->setAsSelect($TField);
$item->helpText = $langs->transnoentities('STANCER_NB_DAYS_TO_SYNCTooltip');

$item = $formSetup->newItem('STANCER_AUDIT_CAPTURED_WINDOW_DAYS');
$TField = array(
	0 => $langs->transnoentities('Disabled'),
	7 => '7',
	15 => '15',
	30 => '30 (conseillé)',
	60 => '2 mois',
	90 => '3 mois',
);
$item->setAsSelect($TField);
$item->helpText = $langs->transnoentities('STANCER_AUDIT_CAPTURED_WINDOW_DAYSTooltip');

// Monthly subscription fee for POS
$item = $formSetup->newItem('STANCER_MONTHLY_SUBSCRIPTION_FEE');
$item->defaultFieldValue = '18';
$item->helpText = $langs->transnoentities('STANCER_MONTHLY_SUBSCRIPTION_FEETooltip');

// Propal payment options
$formSetup->newItem('STANCER_PROPAL_TITLE')->setAsTitle();
$formSetup->newItem('STANCER_AUTO_INVOICE_ON_PROPAL_PAID')->setAsYesNo();


// Setup conf STANCER_MYPARAM2 as a simple textarea input but we replace the text of field title
// $item = $formSetup->newItem('STANCER_MYPARAM2');
// $item->nameText = $item->getNameText().' more html text ';

// // Setup conf STANCER_MYPARAM3
// $item = $formSetup->newItem('STANCER_MYPARAM3');
// $item->setAsThirdpartyType();

// // Setup conf STANCER_MYPARAM5
// $formSetup->newItem('STANCER_MYPARAM5')->setAsEmailTemplate('thirdparty');

// // Setup conf STANCER_MYPARAM6
// $formSetup->newItem('STANCER_MYPARAM6')->setAsSecureKey()->enabled = 1; // disabled

// // Setup conf STANCER_MYPARAM7
// $formSetup->newItem('STANCER_MYPARAM7')->setAsProduct();

// $formSetup->newItem('Title')->setAsTitle();

// // Setup conf STANCER_MYPARAM8
// $item = $formSetup->newItem('STANCER_MYPARAM8');
// $TField = array(
// 	'test01' => $langs->trans('test01'),
// 	'test02' => $langs->trans('test02'),
// 	'test03' => $langs->trans('test03'),
// 	'test04' => $langs->trans('test04'),
// 	'test05' => $langs->trans('test05'),
// 	'test06' => $langs->trans('test06'),
// );
// $item->setAsMultiSelect($TField);
// $item->helpText = $langs->transnoentities('STANCER_MYPARAM8');


// // Setup conf STANCER_MYPARAM9
// $formSetup->newItem('STANCER_MYPARAM9')->setAsSelect($TField);


// // Setup conf STANCER_MYPARAM10
// $item = $formSetup->newItem('STANCER_MYPARAM10');
// $item->setAsColor();
// $item->defaultFieldValue = '#FF0000';
// $item->nameText = $item->getNameText().' more html text ';
// $item->fieldInputOverride = '';
// $item->helpText = $langs->transnoentities('AnHelpMessage');
//$item->fieldValue = '';
//$item->fieldAttr = array() ; // fields attribute only for compatible fields like input text
//$item->fieldOverride = false; // set this var to override field output will override $fieldInputOverride and $fieldOutputOverride too
//$item->fieldInputOverride = false; // set this var to override field input
//$item->fieldOutputOverride = false; // set this var to override field output


$setupnotempty += count($formSetup->items);


$dirmodels = array_merge(array('/'), (array) $conf->modules_parts['models']);


/*
 * Actions
 */
// Validate Stancer API key prefixes BEFORE the save handler runs. If a posted
// key has the wrong prefix (typical mistake: pasting the public key in the
// private field or swapping test/prod), restore the previous stored value in
// $_POST so saveConfFromPost() / actions_setmoduleoptions.inc.php rewrites the
// existing value instead of saving the bad one. stancerValidateApiKey() is
// the single source of truth, also used by unit tests.
if ($action == 'update' && !empty($user->admin)) {
	foreach ($stancerKeyRules as $constName => $rule) {
		if (!GETPOSTISSET($constName)) {
			continue;
		}
		$posted = GETPOST($constName, 'alphanohtml');
		if (!stancerValidateApiKey($constName, $posted)) {
			dol_syslog("stancer setup: rejecting $constName, expected prefix ".$rule['prefix'].", got '".substr($posted, 0, 8)."...'", LOG_WARNING);
			setEventMessages(
				$langs->trans('STANCER_KEY_INVALID', $langs->transnoentities($constName), $rule['prefix']),
				array(),
				'errors'
			);
			// Restore the currently stored value so the save handler does not
			// overwrite a valid key with the bad input.
			$_POST[$constName] = getDolGlobalString($constName, '');
			$error++;
		}
	}
}

// For retrocompatibility Dolibarr < 15.0
if ( versioncompare(explode('.', DOL_VERSION), array(15)) < 0 && $action == 'update' && !empty($user->admin)) {
	$formSetup->saveConfFromPost();
}

include DOL_DOCUMENT_ROOT.'/core/actions_setmoduleoptions.inc.php';

if ($action == 'updateMask') {
	$maskconst = GETPOST('maskconst', 'aZ09');
	$maskvalue = GETPOST('maskvalue', 'alpha');

	if ($maskconst && preg_match('/_MASK$/', $maskconst)) {
		$res = dolibarr_set_const($db, $maskconst, $maskvalue, 'chaine', 0, '', $conf->entity);
		if (!($res > 0)) {
			$error++;
		}
	}

	if (!$error) {
		setEventMessages($langs->trans("SetupSaved"), [], 'mesgs');
	} else {
		setEventMessages($langs->trans("Error"), [], 'errors');
	}
} elseif ($action == 'specimen') {
	$modele = GETPOST('module', 'alpha');
	// The value is used as a class name, so restrict it to a class-name charset.
	$tmpobjectkey = (string) GETPOST('object', 'aZ09');

	$tmpobject = new $tmpobjectkey($db);
	$tmpobject->initAsSpecimen();

	// Search template files
	$file = ''; $classname = ''; $filefound = 0;
	$dirmodels = array_merge(array('/'), (array) $conf->modules_parts['models']);
	foreach ($dirmodels as $reldir) {
		$file = dol_buildpath($reldir."core/modules/stancer/doc/pdf_".$modele."_".dol_strtolower($tmpobjectkey).".modules.php", 0);
		if (file_exists($file)) {
			$filefound = 1;
			$classname = "pdf_".$modele."_".dol_strtolower($tmpobjectkey);
			break;
		}
	}

	if ($filefound && $classname !== '') {
		require_once $file;

		$module = new $classname($db);

		if ($module->write_file($tmpobject, $langs) > 0) {
			header("Location: ".DOL_URL_ROOT."/document.php?modulepart=stancer-".dol_strtolower($tmpobjectkey)."&file=SPECIMEN.pdf");
			return;
		} else {
			setEventMessages($module->error, [], 'errors');
			dol_syslog($module->error, LOG_ERR);
		}
	} else {
		setEventMessages($langs->trans("ErrorModuleNotFound"), [], 'errors');
		dol_syslog($langs->trans("ErrorModuleNotFound"), LOG_ERR);
	}
} elseif ($action == 'setmod') {
	// TODO Check if numbering module chosen can be activated by calling method canBeActivated
	$tmpobjectkey = (string) GETPOST('object', 'aZ09');
	if (!empty($tmpobjectkey)) {
		$constforval = 'STANCER_'.dol_strtoupper($tmpobjectkey)."_ADDON";
		dolibarr_set_const($db, $constforval, $value, 'chaine', 0, '', $conf->entity);
	}
} elseif ($action == 'set') {
	// Activate a model
	$ret = addDocumentModel($value, $type, $label, $scandir);
} elseif ($action == 'del') {
	$ret = delDocumentModel($value, $type);
	if ($ret > 0) {
		$tmpobjectkey = (string) GETPOST('object', 'aZ09');
		if (!empty($tmpobjectkey)) {
			$constforval = 'STANCER_'.dol_strtoupper($tmpobjectkey).'_ADDON_PDF';
			if (getDolGlobalString($constforval) == "$value") {
				dolibarr_del_const($db, $constforval, $conf->entity);
			}
		}
	}
} elseif ($action == 'setdoc') {
	// Set or unset default model
	$tmpobjectkey = (string) GETPOST('object', 'aZ09');
	if (!empty($tmpobjectkey)) {
		$constforval = 'STANCER_'.dol_strtoupper($tmpobjectkey).'_ADDON_PDF';
		if (dolibarr_set_const($db, $constforval, $value, 'chaine', 0, '', $conf->entity)) {
			// The constant that was read before the new set
			// We therefore requires a variable to have a coherent view
			$conf->global->$constforval = $value;
		}

		// We disable/enable the document template (into llx_document_model table)
		$ret = delDocumentModel($value, $type);
		if ($ret > 0) {
			$ret = addDocumentModel($value, $type, $label, $scandir);
		}
	}
} elseif ($action == 'unsetdoc') {
	$tmpobjectkey = (string) GETPOST('object', 'aZ09');
	if (!empty($tmpobjectkey)) {
		$constforval = 'STANCER_'.dol_strtoupper($tmpobjectkey).'_ADDON_PDF';
		dolibarr_del_const($db, $constforval, $conf->entity);
	}
}



/*
 * View
 */

$form = new Form($db);

$help_url = '';
$page_name = "StancerSetup";

llxHeader('', $langs->trans($page_name), $help_url);

// Subheader
$linkback = '<a href="'.($backtopage ? $backtopage : DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1').'">'.$langs->trans("BackToModuleList").'</a>';

print load_fiche_titre($langs->trans($page_name), $linkback, 'title_setup');

// Configuration header
$head = stancerAdminPrepareHead();
print dol_get_fiche_head($head, 'StancerSettingsMenu', $langs->trans($page_name), 0, "stancer@stancer");

// Setup page goes here
echo '<span class="opacitymedium">'.$langs->trans("StancerSetupPage").'</span><br><br>';

// Sponsored link - shown prominently when no API keys configured
$hasKeys = getDolGlobalString('STANCER_TEST_PRIVATE_KEY') || getDolGlobalString('STANCER_PROD_PRIVATE_KEY');
if (!$hasKeys) {
	print '<div class="warning">';
	print '<span class="fa fa-exclamation-triangle"></span> ';
	print $langs->trans("StancerNoApiKeysMessage", $langs->trans("StancerSignupURL"));
	print '</div><br>';
} else {
	print '<div class="info">';
	print $langs->trans("StancerSignupPromo", $langs->trans("StancerSignupURL"));
	print '</div><br>';
}

if ($action =='cleanStancerTests') {
	// Test SEPA mandates (type='ban') are NEVER deleted here: the IBAN and the RUM
	// they hold are real, signed by the customer, and Dolibarr shows the bank
	// account of a thirdparty only through its type='ban' rows. Dropping one would
	// silently stop every direct debit of that customer. Only the test card links
	// go away; mandates are reported so the admin can review them one by one.
	$sqlKept = "SELECT COUNT(*) as nb FROM ".MAIN_DB_PREFIX."societe_rib";
	$sqlKept .= " WHERE stancer_account IS NOT NULL AND label LIKE 'stancer-%-tst%' AND type = 'ban'";
	$nbKept = 0;
	$resqlKept = $db->query($sqlKept);
	if ($resqlKept && $objKept = $db->fetch_object($resqlKept)) {
		$nbKept = (int) $objKept->nb;
	} else {
		dol_syslog("stancer cleanStancerTests: cannot count the SEPA mandates to keep: ".$db->lasterror(), LOG_ERR);
	}

	$sql = "DELETE FROM ".MAIN_DB_PREFIX."societe_rib WHERE stancer_account IS NOT NULL and label LIKE 'stancer-%-tst%'";
	$sql .= " AND (type IS NULL OR type <> 'ban')";
	$resql = $db->query($sql);
	if ($resql) {
		dol_syslog("stancer cleanStancerTests: deleted ".((int) $db->affected_rows($resql))." test payment mode(s), kept "
			.$nbKept." SEPA mandate(s)", LOG_NOTICE);
		print "<p>" . $langs->trans("CleanStancerTestsDataDone") . "</p>";
		if ($nbKept > 0) {
			print '<div class="warning">' . $langs->trans("CleanStancerTestsDataKeptMandates", $nbKept) . '</div>';
		}
	} else {
		dol_syslog("stancer cleanStancerTests: DELETE failed: ".$db->lasterror(), LOG_ERR);
		setEventMessages($langs->trans("CleanStancerTestsDataError"), null, 'errors');
	}
} elseif ($action == 'edit') {
	print $formSetup->generateOutput(true);
	print '<br>';
} elseif (!empty($formSetup->items)) {
	print $formSetup->generateOutput();
	print '<div class="tabsAction">';
	print '<a class="butAction" href="'.$_SERVER["PHP_SELF"].'?action=edit&token='.newToken().'">'.$langs->trans("Modify").'</a>';
	if (getDolGlobalString('STANCER_IS_PROD', '0') == '1') {
		// Count only what the cleanup can actually delete: SEPA mandates are kept,
		// so counting them here would leave the button displayed for ever.
		$sql = "SELECT count(*) as nb FROM ".MAIN_DB_PREFIX."societe_rib WHERE stancer_account IS NOT NULL and label LIKE 'stancer-%-tst%'";
		$sql .= " AND (type IS NULL OR type <> 'ban')";
		$resql = $db->query($sql);
		if ($resql && $obj = $db->fetch_object($resql)) {
			if ($obj->nb > 0) {
				print '<a class="butAction" href="'.$_SERVER["PHP_SELF"].'?action=cleanStancerTests&token='.newToken().'" title="'. $langs->trans("CleanStancerTestsDataHelp"). '">'.$langs->trans("CleanStancerTestsData").'</a>';
			}
		}
	}
	print '</div>';
} else {
	print '<br>'.$langs->trans("NothingToSetup");
}


$moduledir = 'stancer';
$myTmpObjects = array();
// TODO Scan list of objects
// $myTmpObjects['myobject'] = array('label'=>'MyObject', 'includerefgeneration'=>0, 'includedocgeneration'=>0);


// The list above is still empty: this whole block is the modulebuilder skeleton kept
// for the day the module exposes numbering and document models of its own.
// @phan-suppress-next-line PhanEmptyForeach
foreach ($myTmpObjects as $myTmpObjectKey => $myTmpObjectArray) {
	if ($myTmpObjectKey != $type) {
		continue;
	}
	if ($myTmpObjectArray['includerefgeneration']) {
		/*
		 * Orders Numbering model
		 */
		$setupnotempty++;

		print load_fiche_titre($langs->trans("NumberingModules", $myTmpObjectArray['label']), '', '');

		print '<table class="noborder centpercent">';
		print '<tr class="liste_titre">';
		print '<td>'.$langs->trans("Name").'</td>';
		print '<td>'.$langs->trans("Description").'</td>';
		print '<td class="nowrap">'.$langs->trans("Example").'</td>';
		print '<td class="center" width="60">'.$langs->trans("Status").'</td>';
		print '<td class="center" width="16">'.$langs->trans("ShortInfo").'</td>';
		print '</tr>'."\n";

		clearstatcache();

		foreach ($dirmodels as $reldir) {
			$dir = dol_buildpath($reldir."core/modules/".$moduledir);

			if (is_dir($dir)) {
				$handle = opendir($dir);
				if (is_resource($handle)) {
					while (($file = readdir($handle)) !== false) {
						if (strpos($file, 'mod_'.dol_strtolower($myTmpObjectKey).'_') === 0 && substr($file, dol_strlen($file) - 3, 3) == 'php') {
							$file = substr($file, 0, dol_strlen($file) - 4);

							require_once $dir.'/'.$file.'.php';

							$module = new $file($db);

							// Show modules according to features level
							if ($module->version == 'development' && getDolGlobalString('MAIN_FEATURES_LEVEL') < 2) {
								continue;
							}
							if ($module->version == 'experimental' && getDolGlobalString('MAIN_FEATURES_LEVEL') < 1) {
								continue;
							}

							if ($module->isEnabled()) {
								dol_include_once('/'.$moduledir.'/class/'.dol_strtolower($myTmpObjectKey).'.class.php');

								print '<tr class="oddeven"><td>'.$module->name."</td><td>\n";
								print $module->info();
								print '</td>';

								// Show example of numbering model
								print '<td class="nowrap">';
								$tmp = $module->getExample();
								if (preg_match('/^Error/', $tmp)) {
									$langs->load("errors");
									print '<div class="error">'.$langs->trans($tmp).'</div>';
								} elseif ($tmp == 'NotConfigured') {
									print $langs->trans($tmp);
								} else {
									print $tmp;
								}
								print '</td>'."\n";

								print '<td class="center">';
								$constforvar = 'STANCER_'.dol_strtoupper($myTmpObjectKey).'_ADDON';
								if (getDolGlobalString($constforvar) == $file) {
									print img_picto($langs->trans("Activated"), 'switch_on');
								} else {
									print '<a href="'.$_SERVER["PHP_SELF"].'?action=setmod&token='.newToken().'&object='.dol_strtolower($myTmpObjectKey).'&value='.urlencode($file).'">';
									print img_picto($langs->trans("Disabled"), 'switch_off');
									print '</a>';
								}
								print '</td>';

								// $myTmpObjectKey is the class name of the scanned object. Phan sees the
								// empty skeleton array above and infers the empty string here.
								// @phan-suppress-next-line PhanEmptyFQSENInClasslike, PhanTypeExpectedObjectOrClassName
								$mytmpinstance = new $myTmpObjectKey($db);
								$mytmpinstance->initAsSpecimen();

								// Info
								$htmltooltip = '';
								$htmltooltip .= ''.$langs->trans("Version").': <b>'.$module->getVersion().'</b><br>';

								$nextval = $module->getNextValue($mytmpinstance);
								if ("$nextval" != $langs->trans("NotAvailable")) {  // Keep " on nextval
									$htmltooltip .= ''.$langs->trans("NextValue").': ';
									if ($nextval) {
										if (preg_match('/^Error/', $nextval) || $nextval == 'NotConfigured') {
											$nextval = $langs->trans($nextval);
										}
										$htmltooltip .= $nextval.'<br>';
									} else {
										$htmltooltip .= $langs->trans($module->error).'<br>';
									}
								}

								print '<td class="center">';
								print $form->textwithpicto('', $htmltooltip, 1, '0');
								print '</td>';

								print "</tr>\n";
							}
						}
					}
					closedir($handle);
				}
			}
		}
		print "</table><br>\n";
	}

	if ($myTmpObjectArray['includedocgeneration']) {
		/*
		 * Document templates generators
		 */
		$setupnotempty++;
		$type = dol_strtolower($myTmpObjectKey);

		print load_fiche_titre($langs->trans("DocumentModules", $myTmpObjectKey), '', '');

		// Load array def with activated templates
		$def = array();
		$sql = "SELECT nom";
		$sql .= " FROM ".MAIN_DB_PREFIX."document_model";
		$sql .= " WHERE type = '".$db->escape($type)."'";
		$sql .= " AND entity = ".((int) $conf->entity);
		$resql = $db->query($sql);
		if ($resql) {
			$i = 0;
			$num_rows = $db->num_rows($resql);
			while ($i < $num_rows) {
				$array = $db->fetch_array($resql);
				array_push($def, $array[0]);
				$i++;
			}
		} else {
			dol_print_error($db);
		}

		print "<table class=\"noborder\" width=\"100%\">\n";
		print "<tr class=\"liste_titre\">\n";
		print '<td>'.$langs->trans("Name").'</td>';
		print '<td>'.$langs->trans("Description").'</td>';
		print '<td class="center" width="60">'.$langs->trans("Status")."</td>\n";
		print '<td class="center" width="60">'.$langs->trans("Default")."</td>\n";
		print '<td class="center" width="38">'.$langs->trans("ShortInfo").'</td>';
		print '<td class="center" width="38">'.$langs->trans("Preview").'</td>';
		print "</tr>\n";

		clearstatcache();

		// Accumulates the file names of every scanned directory, so it must be an array
		// from the start: arsort() and the foreach below read it without any null check.
		$filelist = array();
		foreach ($dirmodels as $reldir) {
			foreach (array('', '/doc') as $valdir) {
				$realpath = $reldir."core/modules/".$moduledir.$valdir;
				$dir = dol_buildpath($realpath);

				if (is_dir($dir)) {
					$handle = opendir($dir);
					if (is_resource($handle)) {
						while (($file = readdir($handle)) !== false) {
							$filelist[] = $file;
						}
						closedir($handle);
						arsort($filelist);

						foreach ($filelist as $file) {
							if (preg_match('/\.modules\.php$/i', $file) && preg_match('/^(pdf_|doc_)/', $file)) {
								if (file_exists($dir.'/'.$file)) {
									$name = substr($file, 4, dol_strlen($file) - 16);
									$classname = substr($file, 0, dol_strlen($file) - 12);

									require_once $dir.'/'.$file;
									$module = new $classname($db);

									$modulequalified = 1;
									if ($module->version == 'development' && getDolGlobalString('MAIN_FEATURES_LEVEL') < 2) {
										$modulequalified = 0;
									}
									if ($module->version == 'experimental' && getDolGlobalString('MAIN_FEATURES_LEVEL') < 1) {
										$modulequalified = 0;
									}

									if ($modulequalified) {
										print '<tr class="oddeven"><td width="100">';
										print (empty($module->name) ? $name : $module->name);
										print "</td><td>\n";
										if (method_exists($module, 'info')) {
											print $module->info($langs);
										} else {
											print $module->description;
										}
										print '</td>';

										// Active
										if (in_array($name, $def)) {
											print '<td class="center">'."\n";
											print '<a href="'.$_SERVER["PHP_SELF"].'?action=del&token='.newToken().'&value='.urlencode($name).'">';
											print img_picto($langs->trans("Enabled"), 'switch_on');
											print '</a>';
											print '</td>';
										} else {
											print '<td class="center">'."\n";
											print '<a href="'.$_SERVER["PHP_SELF"].'?action=set&token='.newToken().'&value='.urlencode($name).'&scan_dir='.urlencode($module->scandir).'&label='.urlencode($module->name).'">'.img_picto($langs->trans("Disabled"), 'switch_off').'</a>';
											print "</td>";
										}

										// Default
										print '<td class="center">';
										$constforvar = 'STANCER_'.dol_strtoupper($myTmpObjectKey).'_ADDON_PDF';
										if (getDolGlobalString($constforvar) == $name) {
											//print img_picto($langs->trans("Default"), 'on');
											// Even if choice is the default value, we allow to disable it. Replace this with previous line if you need to disable unset
											print '<a href="'.$_SERVER["PHP_SELF"].'?action=unsetdoc&token='.newToken().'&object='.urlencode(dol_strtolower($myTmpObjectKey)).'&value='.urlencode($name).'&scan_dir='.urlencode($module->scandir).'&label='.urlencode($module->name).'&amp;type='.urlencode($type).'" alt="'.$langs->trans("Disable").'">'.img_picto($langs->trans("Enabled"), 'on').'</a>';
										} else {
											print '<a href="'.$_SERVER["PHP_SELF"].'?action=setdoc&token='.newToken().'&object='.urlencode(dol_strtolower($myTmpObjectKey)).'&value='.urlencode($name).'&scan_dir='.urlencode($module->scandir).'&label='.urlencode($module->name).'" alt="'.$langs->trans("Default").'">'.img_picto($langs->trans("Disabled"), 'off').'</a>';
										}
										print '</td>';

										// Info
										$htmltooltip = ''.$langs->trans("Name").': '.$module->name;
										$htmltooltip .= '<br>'.$langs->trans("Type").': '.($module->type ? $module->type : $langs->trans("Unknown"));
										if ($module->type == 'pdf') {
											$htmltooltip .= '<br>'.$langs->trans("Width").'/'.$langs->trans("Height").': '.$module->page_largeur.'/'.$module->page_hauteur;
										}
										$htmltooltip .= '<br>'.$langs->trans("Path").': '.preg_replace('/^\//', '', $realpath).'/'.$file;

										$htmltooltip .= '<br><br><u>'.$langs->trans("FeaturesSupported").':</u>';
										$htmltooltip .= '<br>'.$langs->trans("Logo").': '.yn($module->option_logo, 1, 1);
										$htmltooltip .= '<br>'.$langs->trans("MultiLanguage").': '.yn($module->option_multilang, 1, 1);

										print '<td class="center">';
										print $form->textwithpicto('', $htmltooltip, 1, '0');
										print '</td>';

										// Preview
										print '<td class="center">';
										if ($module->type == 'pdf') {
											$newname = preg_replace('/_'.preg_quote(dol_strtolower($myTmpObjectKey), '/').'/', '', $name);
											print '<a href="'.$_SERVER["PHP_SELF"].'?action=specimen&token='.newToken().'&module='.urlencode($newname).'&object='.urlencode($myTmpObjectKey).'">'.img_object($langs->trans("Preview"), 'pdf').'</a>';
										} else {
											print img_object($langs->trans("PreviewNotAvailable"), 'generic');
										}
										print '</td>';

										print "</tr>\n";
									}
								}
							}
						}
					}
				}
			}
		}

		print '</table>';
	}
}

if (empty($setupnotempty)) {
	print '<br>'.$langs->trans("NothingToSetup");
}

// Page end
print dol_get_fiche_end();

llxFooter();
$db->close();
