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
 * \file    helloasso/admin/setup_member.php
 * \ingroup helloasso
 * \brief   HelloAsso setup page.
 */


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

global $langs, $user, $db;

// Libraries
require_once DOL_DOCUMENT_ROOT."/core/lib/admin.lib.php";
require_once DOL_DOCUMENT_ROOT."/core/lib/geturl.lib.php";
require_once DOL_DOCUMENT_ROOT.'/includes/OAuth/bootstrap.php';
include_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';
include_once DOL_DOCUMENT_ROOT.'/core/lib/security.lib.php';
require_once DOL_DOCUMENT_ROOT.'/adherents/class/adherent_type.class.php';
dol_include_once('helloasso/lib/helloasso.lib.php');
dol_include_once('helloasso/class/helloassomemberutils.class.php');


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

$helloassomemberutils = new HelloAssoMemberUtils($db);
$staticmembertype = new AdherentType($db);

$error = 0;
$setupnotempty = 0;

// Set this to 1 to use the factory to manage constants. Warning, the generated module will be compatible with version v15+ only
$useFormSetup = 1;

if (!class_exists('FormSetup')) {
	require_once DOL_DOCUMENT_ROOT.'/core/class/html.formsetup.class.php';
}
$formSetup = new FormSetup($db);


// Enter here all parameters in your setup page

$item = $formSetup->newItem('HELLOASSO_FORM_MEMBERSHIP_SLUG');
$item->helpText = $langs->transnoentities('HELLOASSO_FORM_MEMBERSHIP_SLUG_HELP');
$item = $formSetup->newItem('HELLOASSO_TEST_FORM_MEMBERSHIP_SLUG');
$item->helpText = $langs->transnoentities('HELLOASSO_TEST_FORM_MEMBERSHIP_SLUG_HELP');

$setupnotempty += count($formSetup->items);

$dirmodels = array_merge(array('/'), (array) $conf->modules_parts['models']);


/*
 * Actions
 */

include DOL_DOCUMENT_ROOT.'/core/actions_setmoduleoptions.inc.php';

if ($action == 'test') {
	$helloassomemberutils->helloassoSyncMembersToDolibarr(1, "test");
	header("Location: ".$_SERVER["PHP_SELF"]);
} elseif ($action == 'addmembertype'){
	$dolibarrmembertype = GETPOST("select_mapdolibarrhelloassomember", 'int');
	if (empty($dolibarrmembertype)) {
		setEventMessages($langs->transnoentities("ErrorHelloAssoBadParameter", $langs->transnoentities("HelloAssoDolibarrMemberTypeID")), null, 'errors');
		$error++;
	}
	$helloassomembertype = GETPOST("input_mapdolibarrhelloassomember", 'int');
	if (empty($helloassomembertype)) {
		setEventMessages($langs->transnoentities("ErrorHelloAssoBadParameter", $langs->transnoentities("HelloAssoMemberTypeID")), null, 'errors');
		$error++;
	}
	if (!$error) {
		$res = $helloassomemberutils->setHelloAssoTypeMemberMapping($dolibarrmembertype, $helloassomembertype);
		if ($res <= 0) {
			$error++;
			setEventMessages($helloassomemberutils->error, $helloassomemberutils->errors, 'errors');
		}
	}
	if (!$error) {
		$db->commit();
		setEventMessages($langs->trans("HelloAssoMemberTypeDictionaryAddedSucesfully"), null, 'mesgs');
		header("Location: ".$_SERVER["PHP_SELF"]);
	} else {
		$db->rollback();
	}
} elseif ($action == 'delmembertype'){
	$helloassomembertype = GETPOST("helloassomembertype", 'int');
	if (empty($helloassomembertype)) {
		setEventMessages($langs->transnoentities("ErrorHelloAssoBadParameter", $langs->transnoentities("HelloAssoMemberTypeID")), null, 'errors');
		$error++;
	}
	if (!$error) {
		$mappingstr = getDolGlobalString("HELLOASSO_TYPE_MEMBER_MAPPING");
		if (empty($mappingstr)) {
			setEventMessages($langs->transnoentities("HelloAssoRecordNotFound"), null, 'warnings');
			$error++;
		}
		if (!$error) {
			$mapping = json_decode($mappingstr, true);
			if (empty($mapping[$helloassomembertype])) {
				$error++;
				setEventMessages($langs->trans("HelloAssoRecordNotFound"), null, 'warnings');
			} else {
				unset($mapping[$helloassomembertype]);
				$mappingstr = json_encode($mapping);
				$res = dolibarr_set_const($db, 'HELLOASSO_TYPE_MEMBER_MAPPING', $mappingstr, 'chaine', 0, '', $conf->entity);
				if ($res <= 0) {
					$error++;
					setEventMessages($langs->transnoentities("ErrorHelloAssoRemovingMemberType"), null, 'errors');
				}
			}
		}
	}
	if (!$error) {
		$db->commit();
		setEventMessages($langs->trans("HelloAssoMemberTypeDictionaryRemovedSucesfully"), null, 'mesgs');
		header("Location: ".$_SERVER["PHP_SELF"]);
	} else {
		$db->rollback();
	}
} elseif ($action == 'addcustomfield'){
	$dolibarrfield = GETPOST("select_mapcutomfield");
	if (empty($dolibarrfield)) {
		setEventMessages($langs->transnoentities("ErrorHelloAssoBadParameter", $langs->transnoentities("HelloAssoDolibarrMemberTypeID")), null, 'errors');
		$error++;
	}
	$helloassofield = GETPOST("input_mapcutomfield");
	if (empty($helloassofield)) {
		setEventMessages($langs->transnoentities("ErrorHelloAssoBadParameter", $langs->transnoentities("HelloAssoMemberTypeID")), null, 'errors');
		$error++;
	}
	if (!$error) {
		$res = $helloassomemberutils->setHelloAssoCustomFieldMapping($dolibarrfield, $helloassofield);
		if ($res <= 0) {
			$error++;
			setEventMessages($helloassomemberutils->error, $helloassomemberutils->errors, 'errors');
		}
	}
	if (!$error) {
		$db->commit();
		setEventMessages($langs->trans("HelloAssoCustomFieldDictionaryAddedSucesfully"), null, 'mesgs');
		header("Location: ".$_SERVER["PHP_SELF"]);
	} else {
		$db->rollback();
	}
} elseif ($action == 'delcustomfield'){
	$dolibarrfield = GETPOST("dolibarrfield");
	if (empty($dolibarrfield)) {
		setEventMessages($langs->transnoentities("ErrorHelloAssoBadParameter", $langs->transnoentities("HelloAssoDolibarrMemberTypeID")), null, 'errors');
		$error++;
	}
	if (!$error) {
		$mappingstr = getDolGlobalString("HELLOASSO_CUSTOM_FIELD_MAPPING");
		if (empty($mappingstr)) {
			setEventMessages($langs->transnoentities("HelloAssoRecordNotFound"), null, 'warnings');
			$error++;
		}
		if (!$error) {
			$mapping = json_decode($mappingstr, true);
			if (empty($mapping[$dolibarrfield])) {
				$error++;
				setEventMessages($langs->trans("HelloAssoRecordNotFound"), null, 'warnings');
			} else {
				unset($mapping[$dolibarrfield]);
				$mappingstr = json_encode($mapping);
				$res = dolibarr_set_const($db, 'HELLOASSO_CUSTOM_FIELD_MAPPING', $mappingstr, 'chaine', 0, '', $conf->entity);
				if ($res <= 0) {
					$error++;
					setEventMessages($langs->transnoentities("ErrorHelloAssoRemovingCustomField"), null, 'errors');
				}
			}
		}
	}
	if (!$error) {
		$db->commit();
		setEventMessages($langs->trans("HelloAssoCustomFieldDictionaryRemovedSucesfully"), null, 'mesgs');
		header("Location: ".$_SERVER["PHP_SELF"]);
	} else {
		$db->rollback();
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
print dol_get_fiche_head($head, 'member', $langs->trans($page_name), -1, "");

// Setup page goes here
echo '<span class="opacitymedium">'.$langs->trans("ModuleHelloAssoDesc").'</span><br><br>';
echo '<div class="info">';
echo $langs->trans("HelloAssoExplanatoryText");
echo '<br>'.$langs->trans("HelloAssoExplanatoryText2");
echo '<br>'.$langs->trans("HelloAssoExplanatoryText3");
echo '</div><br><br>';

print $formSetup->generateOutput(true);
print '<br>';


print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td>'.$langs->trans("Parameter").'</td>';
print '<td class="right"></td>';
print '</tr>';
print '<tr class="oddeven nohover">';
print '<td class="col-setup-title">'.$langs->trans("HelloAssoMemberTypeDictionary").'</td>';
print '<td>';

print '<form name="formmembertype" action="'.$_SERVER["PHP_SELF"].'" method="POST">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="addmembertype">';
$membertypes = $staticmembertype->liste_array(1);
print '<select id="select_mapdolibarrhelloassomember" class="flat minwidth300" name="select_mapdolibarrhelloassomember">';
print '<option value="-1">&nbsp;</option>';
foreach ($membertypes as $key => $membertype) {
	$disabled = in_array($key, $helloassomemberutils->helloasso_member_types) ? true : false;
	$selected = GETPOST("select_mapdolibarrhelloassomember", 'int') == $key;
	print '<option value="'.$key.'" '.($disabled ? 'disabled="disabled"' : '').' '.($selected ? "selected" : "").'>'.$membertype.'</option>';
}
print '</select>';
print ajax_combobox("select_mapdolibarrhelloassomember");
print '<input name="input_mapdolibarrhelloassomember" id="input_mapdolibarrhelloassomember" pattern="^[0-9]+$" title="'.$langs->trans("HelloAssoInputId").'" value="'.GETPOST("input_mapdolibarrhelloassomember", "int").'">';
print '<input type="submit" id="btn_mapdolibarrhelloassomember" name="btn_mapdolibarrhelloassomember" class="butAction small smallpaddingimp" value="'.$langs->trans("Add").'" disabled="">';
print '</form>';
print '<br>';

print '<div class="div-table-responsive-no-min">';
if (!empty($helloassomemberutils->helloasso_member_types)) {
	print img_picto('', 'graph', 'class="pictofixedwidth"').$langs->trans("HierarchicView").'<br>';
	print '<br>';
	print '<ul>';
	$mapping = $helloassomemberutils->helloasso_member_types;
	foreach ($mapping as $helloassomembertype => $dolibarrmembertype) {
		$membertype = new AdherentType($db);
		$membertype->fetch($dolibarrmembertype);
		print '<li><span>'.$membertype->label.'</span> : <span>'.$helloassomembertype.'</span>&nbsp;<a href="'.$_SERVER["PHP_SELF"].'?action=delmembertype&helloassomembertype='.$helloassomembertype.'&token='.newToken().'">'.img_delete().'</a></li>';
	}
	print '</ul>';
}
print '</div>';
print '</td>';
print '</tr>';

print '<tr class="oddeven nohover">';
print '<td class="col-setup-title">'.$langs->trans("HelloAssoMemberCustomFieldDictionary").'</td>';
print '<td>';
print '<form name="formcustomfield" action="'.$_SERVER["PHP_SELF"].'" method="POST">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="addcustomfield">';
print '<select id="select_mapcutomfield" class="flat minwidth300" name="select_mapcutomfield">';
print '<option value="-1">&nbsp;</option>';
foreach ($helloassomemberutils->memberfields as $key => $field) {
	$disabled = !empty($helloassomemberutils->customfields[$field]);
	$selected = GETPOST("select_mapcutomfield") == $field;
	print '<option value="'.$field.'" '.($disabled ? 'disabled="disabled"' : '').' '.($selected ? "selected" : "").'>'.$field.'</option>';
}
print '</select>';
print ajax_combobox("select_mapcutomfield");
print '<input name="input_mapcutomfield" id="input_mapcutomfield" title="'.$langs->trans("HelloAssoCustomFieldInput").'" value="'.GETPOST("input_mapcutomfield").'">';
print '<input type="submit" id="btn_mapcutomfield" name="btn_mmapcutomfield" class="butAction small smallpaddingimp" value="'.$langs->trans("Add").'" disabled="">';
print '</form>';
print '<br>';
print '<div class="div-table-responsive-no-min">';
if (!empty($helloassomemberutils->customfields)) {
	print img_picto('', 'graph', 'class="pictofixedwidth"').$langs->trans("HierarchicView").'<br>';
	print '<br>';
	print '<ul>';
	$mapping = $helloassomemberutils->customfields;
	foreach ($mapping as $dolibarrfield => $hellofield) {
		print '<li><span>'.$dolibarrfield.'</span> : <span>'.$hellofield.'</span>&nbsp;<a href="'.$_SERVER["PHP_SELF"].'?action=delcustomfield&dolibarrfield='.$dolibarrfield.'&token='.newToken().'">'.img_delete().'</a></li>';
	}
	print '</ul>';
}
print '</td>';
print '</tr>';
print '</table>';
print '</div>';
print '<script>
$(document).ready(function() {
	$("#select_mapdolibarrhelloassomember").on("change", function(){
		if($("#input_mapdolibarrhelloassomember").val().match("^[0-9]+$") && $(this).find(":selected").val() != -1){
			$("#btn_mapdolibarrhelloassomember").prop("disabled",false);
		} else {
			$("#btn_mapdolibarrhelloassomember").prop("disabled",true);
		}
	});

	$("#input_mapdolibarrhelloassomember").on("change keyup", function(){
		if($(this).val().match("^[0-9]+$") && $("#select_mapdolibarrhelloassomember").find(":selected").val() != -1){
			$("#btn_mapdolibarrhelloassomember").prop("disabled",false);
		} else {
			$("#btn_mapdolibarrhelloassomember").prop("disabled",true);
		}
	});

	$("#select_mapcutomfield").on("change", function(){
		if($("#input_mapcutomfield").val() != "" && $(this).find(":selected").val() != -1){
			$("#btn_mapcutomfield").prop("disabled",false);
		} else {
			$("#btn_mapcutomfield").prop("disabled",true);
		}
	});

	$("#input_mapcutomfield").on("change keyup", function(){
		if($(this).val() != "" && $("#select_mapcutomfield").find(":selected").val() != -1){
			$("#btn_mapcutomfield").prop("disabled",false);
		} else {
			$("#btn_mapcutomfield").prop("disabled",true);
		}
	});
});
</script>';

// Show info on connection
$titlebutton = $langs->trans('TestGetMembersHelloasso');
print dolGetButtonAction('', $titlebutton, 'default', $_SERVER["PHP_SELF"].'?action=test', '', 1, array('attr' => array('class' => 'reposition')));


print '<br><br>';

print info_admin($langs->trans("ExampleOfTestCreditCardHelloAsso").'<a href="https://docs.stripe.com/testing?numbers-or-method-or-token=card-numbers">https://docs.stripe.com/testing?numbers-or-method-or-token=card-numbers</a>');


// Page end
print dol_get_fiche_end();

llxFooter();
$db->close();
