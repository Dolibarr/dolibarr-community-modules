<?php
/* Copyright (C) 2024 Laurent Destailleur  <eldy@users.sourceforge.net>
 * Copyright (C) 2025 	   Pablo Lagrave           <contact@devlandes.com>
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
 * \file    helloasso/admin/setup.php
 * \ingroup helloasso
 * \brief   HelloAsso setup page.
 */

use OAuth\Common\Storage\DoliStorage;

// Load Dolibarr environment
$res = 0;
// Try main.inc.php into web root known defined into CONTEXT_DOCUMENT_ROOT (not always defined)
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
// Try main.inc.php into web root detected using web root calculated from SCRIPT_FILENAME
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
require_once DOL_DOCUMENT_ROOT."/core/lib/geturl.lib.php";
require_once '../lib/helloasso.lib.php';
require_once DOL_DOCUMENT_ROOT.'/includes/OAuth/bootstrap.php';
include_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';
include_once DOL_DOCUMENT_ROOT.'/core/lib/security.lib.php';


// Translations
$langs->loadLangs(array("admin", "helloasso@helloasso"));

// Initialize technical object to manage hooks of page. Note that conf->hooks_modules contains array of hook context
$hookmanager->initHooks(array('helloassosetup', 'globalsetup'));

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
$type = 'myobject';


$error = 0;
$setupnotempty = 0;

// Set this to 1 to use the factory to manage constants. Warning, the generated module will be compatible with version v15+ only
$useFormSetup = 1;

if (!class_exists('FormSetup')) {
	require_once DOL_DOCUMENT_ROOT.'/core/class/html.formsetup.class.php';
}
$formSetup = new FormSetup($db);


// Enter here all parameters in your setup page

$item = $formSetup->newItem('HELLOASSO_BANK_ACCOUNT_FOR_PAYMENTS')->setAsSelectBankAccount();
$item->fieldParams['isMandatory'] = 1;

$item = $formSetup->newItem('HELLOASSO_LIVE')->setAsYesNo();
$item->fieldParams['forcereload'] = 'forcereload';

$item = $formSetup->newItem('HELLOASSO_STANDAR_BTN')->setAsYesNo();
$item->fieldParams['forcereload'] = 'forcereload';
$item->helpText = $langs->transnoentities('HELLOASSO_STANDAR_BTN_HELP');


// Credentials

$item = $formSetup->newItem('HELLOASSO_TEST_CLIENT_ORGANISATION');
$item->helpText = $langs->transnoentities('HELLOASSO_TEST_CLIENT_ORGANISATION_HELP');

$item = $formSetup->newItem('HELLOASSO_TEST_CLIENT_ID');
$item->cssClass = 'minwidth500';
$item->helpText = $langs->transnoentities('HELLOASSO_TEST_CLIENT_ID_HELP');

$item = $formSetup->newItem('HELLOASSO_TEST_CLIENT_SECRET');
$item->cssClass = 'minwidth500';
$item->helpText = $langs->transnoentities('HELLOASSO_TEST_SECRET_HELP');

$item = $formSetup->newItem('HELLOASSO_CLIENT_ORGANISATION');
$item->helpText = $langs->transnoentities('HELLOASSO_CLIENT_ORGANISATION_HELP');

$item = $formSetup->newItem('HELLOASSO_CLIENT_ID');
$item->cssClass = 'minwidth500';
$item->helpText = $langs->transnoentities('HELLOASSO_CLIENT_ID_HELP');

$item = $formSetup->newItem('HELLOASSO_CLIENT_SECRET');
$item->cssClass = 'minwidth500';
$item->helpText = $langs->transnoentities('HELLOASSO_SECRET_HELP');

$setupnotempty += count($formSetup->items);

$dirmodels = array_merge(array('/'), (array) $conf->modules_parts['models']);


/*
 * Actions
 */

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
		setEventMessages($langs->trans("SetupSaved"), null, 'mesgs');
	} else {
		setEventMessages($langs->trans("Error"), null, 'errors');
	}
} elseif ($action == 'specimen') {
	$modele = GETPOST('module', 'alpha');
	$tmpobjectkey = GETPOST('object', 'aZ09');

	$tmpobject = new $tmpobjectkey($db);
	$tmpobject->initAsSpecimen();

	// Search template files
	$file = '';
	$classname = '';
	$filefound = 0;
	$dirmodels = array_merge(array('/'), (array) $conf->modules_parts['models']);
	foreach ($dirmodels as $reldir) {
		$file = dol_buildpath($reldir."core/modules/helloasso/doc/pdf_".$modele."_".strtolower($tmpobjectkey).".modules.php", 0);
		if (file_exists($file)) {
			$filefound = 1;
			$classname = "pdf_".$modele."_".strtolower($tmpobjectkey);
			break;
		}
	}

	if ($filefound) {
		require_once $file;

		$module = new $classname($db);

		if ($module->write_file($tmpobject, $langs) > 0) {
			header("Location: ".DOL_URL_ROOT."/document.php?modulepart=helloasso-".strtolower($tmpobjectkey)."&file=SPECIMEN.pdf");
			return;
		} else {
			setEventMessages($module->error, null, 'errors');
			dol_syslog($module->error, LOG_ERR);
		}
	} else {
		setEventMessages($langs->trans("ErrorModuleNotFound"), null, 'errors');
		dol_syslog($langs->trans("ErrorModuleNotFound"), LOG_ERR);
	}
} elseif ($action == 'setmod') {
	// TODO Check if numbering module chosen can be activated by calling method canBeActivated
	$tmpobjectkey = GETPOST('object', 'aZ09');
	if (!empty($tmpobjectkey)) {
		$constforval = 'HELLOASSO_'.strtoupper($tmpobjectkey)."_ADDON";
		dolibarr_set_const($db, $constforval, $value, 'chaine', 0, '', $conf->entity);
	}
} elseif ($action == 'set') {
	// Activate a model
	$ret = addDocumentModel($value, $type, $label, $scandir);
} elseif ($action == 'del') {
	$ret = delDocumentModel($value, $type);
	if ($ret > 0) {
		$tmpobjectkey = GETPOST('object', 'aZ09');
		if (!empty($tmpobjectkey)) {
			$constforval = 'HELLOASSO_'.strtoupper($tmpobjectkey).'_ADDON_PDF';
			if (getDolGlobalString($constforval) == "$value") {
				dolibarr_del_const($db, $constforval, $conf->entity);
			}
		}
	}
} elseif ($action == 'setdoc') {
	// Set or unset default model
	$tmpobjectkey = GETPOST('object', 'aZ09');
	if (!empty($tmpobjectkey)) {
		$constforval = 'HELLOASSO_'.strtoupper($tmpobjectkey).'_ADDON_PDF';
		if (dolibarr_set_const($db, $constforval, $value, 'chaine', 0, '', $conf->entity)) {
			// The constant that was read before the new set
			// We therefore requires a variable to have a coherent view
			$conf->global->{$constforval} = $value;
		}

		// We disable/enable the document template (into llx_document_model table)
		$ret = delDocumentModel($value, $type);
		if ($ret > 0) {
			$ret = addDocumentModel($value, $type, $label, $scandir);
		}
	}
} elseif ($action == 'unsetdoc') {
	$tmpobjectkey = GETPOST('object', 'aZ09');
	if (!empty($tmpobjectkey)) {
		$constforval = 'HELLOASSO_'.strtoupper($tmpobjectkey).'_ADDON_PDF';
		dolibarr_del_const($db, $constforval, $conf->entity);
	}
} elseif ($action == 'testconnect') {
	$res = helloassoDoConnection();
	if ($res <= 0) {
		setEventMessages("", $langs->trans("ErrorBadClientIdOrSecret"), 'errors');
	} else {
		include_once DOL_DOCUMENT_ROOT.'/core/lib/geturl.lib.php';

		//Verify if Helloasso module is in test mode
		if (getDolGlobalInt("HELLOASSO_LIVE")) {
			$assoslug = getDolGlobalString("HELLOASSO_CLIENT_ORGANISATION");
			$helloassourl = "api.helloasso.com";
		} else {
			$assoslug = getDolGlobalString("HELLOASSO_TEST_CLIENT_ORGANISATION");
			$helloassourl = "api.helloasso-sandbox.com";
		}

		$headers = array();
		$headers[] = "Authorization: ".ucfirst($res["token_type"])." ".$res["access_token"];
		$headers[] = "Accept: application/json";
		$headers[] = "Content-Type: application/json";

		$url = "https://".urlencode($helloassourl)."/v5/organizations/".urlencode($assoslug);
		$ret2 = getURLContent($url, 'GET', '', 1, $headers);
		if ($ret2["http_code"] == 200) {
			setEventMessages("", $langs->trans("SuccessfullyConnected"));
		} else {
			$errors = array();
			$arrayofmessage = array();
			if (!empty($ret2['content'])) {
				$arrayofmessage = json_decode($ret2['content'], true);
			}
			if (!empty($arrayofmessage['message'])) {
				$errors[] = $arrayofmessage['message'];
			} else {
				if (!empty($arrayofmessage['errors']) && is_array($arrayofmessage['errors'])) {
					foreach($arrayofmessage['errors'] as $tmpkey => $tmpmessage) {
						if (!empty($tmpmessage['message'])) {
							$errors[] = $langs->trans("Error").' - '.$tmpmessage['message'];
						} else {
							$errors[] = $langs->trans("UnkownError").' - HTTP code = '.$ret2["http_code"];
						}
					}
				} else {
					$errors[] = $langs->trans("UnkownError").' - HTTP code = '.$ret2["http_code"];
				}
			}
			setEventMessages("", $errors, 'errors');
		}
	}
} elseif ($action == 'deletetoken') {
	$res = helloassoDeleteToken();
	if ($res <= 0) {
		setEventMessages("", $langs->trans("ErrorBadClientIdOrSecret"), 'errors');
	} else {
		setEventMessages("", $langs->trans("TokenDeleted"));
	}
}


$action = 'edit';


/*
 * View
 */

$form = new Form($db);

$help_url = '';
$page_name = "HelloAssoSetup";

llxHeader('', $langs->trans($page_name), $help_url, '', 0, 0, '', '', '', 'mod-helloasso page-admin');

// Subheader
$linkback = '<a href="'.($backtopage ? $backtopage : DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1').'">'.$langs->trans("BackToModuleList").'</a>';

print load_fiche_titre($langs->trans($page_name), $linkback, 'title_setup');

// Configuration header
$head = helloassoAdminPrepareHead();
print dol_get_fiche_head($head, 'settings', $langs->trans($page_name), -1, "");

// Setup page goes here
echo '<span class="opacitymedium">'.$langs->trans("ModuleHelloAssoDesc").'</span><br><br>';
echo '<div class="info">';
echo $langs->trans("HelloAssoExplanatoryText");
echo '<br>'.$langs->trans("HelloAssoExplanatoryText2");
echo '<br>'.$langs->trans("HelloAssoExplanatoryText3");
echo '</div><br><br>';


if ($action == 'edit') {
	print $formSetup->generateOutput(true);
	print '<br>';
} elseif (!empty($formSetup->items)) {
	print $formSetup->generateOutput();
	print '<div class="tabsAction">';
	print '<a class="butAction" href="'.$_SERVER["PHP_SELF"].'?action=edit&token='.newToken().'">'.$langs->trans("Modify").'</a>';
	print '</div>';
} else {
	print '<br>'.$langs->trans("NothingToSetup");
}


$moduledir = 'helloasso';
$myTmpObjects = array();
// TODO Scan list of objects
$myTmpObjects['myobject'] = array('label'=>'MyObject', 'includerefgeneration'=>0, 'includedocgeneration'=>0, 'class'=>'MyObject');


foreach ($myTmpObjects as $myTmpObjectKey => $myTmpObjectArray) {
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
						if (strpos($file, 'mod_'.strtolower($myTmpObjectKey).'_') === 0 && substr($file, dol_strlen($file) - 3, 3) == 'php') {
							$file = substr($file, 0, dol_strlen($file) - 4);

							require_once $dir.'/'.$file.'.php';

							$module = new $file($db);

							// Show modules according to features level
							if ($module->version == 'development' && getDolGlobalInt('MAIN_FEATURES_LEVEL') < 2) {
								continue;
							}
							if ($module->version == 'experimental' && getDolGlobalInt('MAIN_FEATURES_LEVEL') < 1) {
								continue;
							}

							if ($module->isEnabled()) {
								dol_include_once('/'.$moduledir.'/class/'.strtolower($myTmpObjectKey).'.class.php');

								print '<tr class="oddeven"><td>'.$module->name."</td><td>\n";
								print $module->info($langs);
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
								$constforvar = 'HELLOASSO_'.strtoupper($myTmpObjectKey).'_ADDON';
								if (getDolGlobalString($constforvar) == $file) {
									print img_picto($langs->trans("Activated"), 'switch_on');
								} else {
									print '<a href="'.$_SERVER["PHP_SELF"].'?action=setmod&token='.newToken().'&object='.strtolower($myTmpObjectKey).'&value='.urlencode($file).'">';
									print img_picto($langs->trans("Disabled"), 'switch_off');
									print '</a>';
								}
								print '</td>';

								$nameofclass = $myTmpObjectArray['class'];
								$mytmpinstance = new $nameofclass($db);
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
								print $form->textwithpicto('', $htmltooltip, 1, 0);
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
		$type = strtolower($myTmpObjectKey);

		print load_fiche_titre($langs->trans("DocumentModules", $myTmpObjectKey), '', '');

		// Load array def with activated templates
		$def = array();
		$sql = "SELECT nom";
		$sql .= " FROM ".MAIN_DB_PREFIX."document_model";
		$sql .= " WHERE type = '".$db->escape($type)."'";
		$sql .= " AND entity = ".$conf->entity;
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

		print '<table class="noborder centpercent">'."\n";
		print '<tr class="liste_titre">'."\n";
		print '<td>'.$langs->trans("Name").'</td>';
		print '<td>'.$langs->trans("Description").'</td>';
		print '<td class="center" width="60">'.$langs->trans("Status")."</td>\n";
		print '<td class="center" width="60">'.$langs->trans("Default")."</td>\n";
		print '<td class="center" width="38">'.$langs->trans("ShortInfo").'</td>';
		print '<td class="center" width="38">'.$langs->trans("Preview").'</td>';
		print "</tr>\n";

		clearstatcache();

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
									if ($module->version == 'development' && getDolGlobalInt('MAIN_FEATURES_LEVEL') < 2) {
										$modulequalified = 0;
									}
									if ($module->version == 'experimental' && getDolGlobalInt('MAIN_FEATURES_LEVEL') < 1) {
										$modulequalified = 0;
									}

									if ($modulequalified) {
										print '<tr class="oddeven"><td width="100">';
										print(empty($module->name) ? $name : $module->name);
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
										$constforvar = 'HELLOASSO_'.strtoupper($myTmpObjectKey).'_ADDON_PDF';
										if (getDolGlobalString($constforvar) == $name) {
											//print img_picto($langs->trans("Default"), 'on');
											// Even if choice is the default value, we allow to disable it. Replace this with previous line if you need to disable unset
											print '<a href="'.$_SERVER["PHP_SELF"].'?action=unsetdoc&token='.newToken().'&object='.urlencode(strtolower($myTmpObjectKey)).'&value='.urlencode($name).'&scan_dir='.urlencode($module->scandir).'&label='.urlencode($module->name).'&amp;type='.urlencode($type).'" alt="'.$langs->trans("Disable").'">'.img_picto($langs->trans("Enabled"), 'on').'</a>';
										} else {
											print '<a href="'.$_SERVER["PHP_SELF"].'?action=setdoc&token='.newToken().'&object='.urlencode(strtolower($myTmpObjectKey)).'&value='.urlencode($name).'&scan_dir='.urlencode($module->scandir).'&label='.urlencode($module->name).'" alt="'.$langs->trans("Default").'">'.img_picto($langs->trans("Disabled"), 'off').'</a>';
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
										print $form->textwithpicto('', $htmltooltip, 1, 0);
										print '</td>';

										// Preview
										print '<td class="center">';
										if ($module->type == 'pdf') {
											$newname = preg_replace('/_'.preg_quote(strtolower($myTmpObjectKey), '/').'/', '', $name);
											print '<a href="'.$_SERVER["PHP_SELF"].'?action=specimen&module='.urlencode($newname).'&object='.urlencode($myTmpObjectKey).'">'.img_object($langs->trans("Preview"), 'pdf').'</a>';
										} else {
											print img_object($langs->transnoentitiesnoconv("PreviewNotAvailable"), 'generic');
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

print '<br>';


// Show info on connection
$helloassourl = "api.helloasso-sandbox.com";
$service = "Helloasso-Test";

// Verify if Helloasso module is in test mode
if (getDolGlobalInt("HELLOASSO_LIVE")) {
	$client_id = getDolGlobalString("HELLOASSO_CLIENT_ID");
	$client_id_secret = getDolGlobalString("HELLOASSO_CLIENT_SECRET");
	$helloassourl = "api.helloasso.com";
	$service = "Helloasso-Live";
} else{
	$client_id = getDolGlobalString("HELLOASSO_TEST_CLIENT_ID");
	$client_id_secret = getDolGlobalString("HELLOASSO_TEST_CLIENT_SECRET");
}



$titlebutton = $langs->trans('TestConnectionHelloasso');
if ((float) DOL_VERSION >= 21) {
	if (getDolGlobalString('HELLOASSO_LIVE')) {
		$titlebutton .= ' (Live)';
	} else {
		$titlebutton .= ' (Test)';
		dol_htmloutput_mesg($langs->trans('YouAreCurrentlyInSandboxMode', 'HelloAsso'), [], 'warning');
	}
}
print dolGetButtonAction('', $titlebutton, 'default', $_SERVER["PHP_SELF"].'?action=testconnect', '', 1, array('attr' => array('class' => 'reposition')));


$storage = new DoliStorage($db, $conf);
try {
	dol_syslog("Call retrieveAccessToken");

	$tokenobj = $storage->retrieveAccessToken($service);
	$ttl = $tokenobj->getEndOfLife();

	print '<br><br><br>';
	print $langs->trans("Connected").'<br>';
	print $langs->trans('LastRecordedToken');

	print '<br>';

	print '<textarea class="small quatrevingtpercent" rows="5">';
	print var_export($tokenobj, true);
	print '</textarea>';
	print '<br>';
	print $langs->trans("TTL").': '.dol_print_date($ttl, 'dayhour')." UTC<br>";

	print '<a class="reposition" href="'.$_SERVER["PHP_SELF"].'?action=deletetoken&token='.newToken().'">'.img_picto('', 'delete').'</a>';
} catch(Exception $e) {
	print '<br><br>'.$e->getMessage();
	print ' Run a connection test to validate a first connexion';

}

print '<br><br>';

print info_admin($langs->trans("ExampleOfTestCreditCardHelloAsso").'<a href="https://docs.sips.worldline-solutions.com/fr/cartes-de-test.html">https://docs.sips.worldline-solutions.com/fr/cartes-de-test.html</a>');


// Page end
print dol_get_fiche_end();

llxFooter();
$db->close();
