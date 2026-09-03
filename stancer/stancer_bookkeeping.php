<?php
/* Copyright (C) 2001-2005 Rodolphe Quiedeville <rodolphe@quiedeville.org>
 * Copyright (C) 2004-2015 Laurent Destailleur  <eldy@users.sourceforge.net>
 * Copyright (C) 2005-2012 Regis Houssin        <regis.houssin@inodbox.com>
 * Copyright (C) 2015      Jean-François Ferry	<jfefe@aternatik.fr>
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
 */

/**
 *    \file       stancer/stancerindex.php
 *    \ingroup    stancer
 *    \brief      Home page of stancer top menu
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

require_once DOL_DOCUMENT_ROOT.'/core/class/html.formfile.class.php';
dol_include_once('/stancer/lib/stancer.lib.php');

// Load translation files required by the page
$langs->loadLangs(array("stancer@stancer"));

$action = GETPOST('action', 'aZ09');

$max = 5;
$now = dol_now();

// Security check - Protection if external user
$socid = GETPOST('socid', 'int');
// if (isset($user->socid) && $user->socid > 0) {
//     $action = '';
//     $socid = $user->socid;
// }

// There is several ways to check permission.
// Set $enablepermissioncheck to 1 to enable a minimum low level of checks
$enablepermissioncheck = 1;
if ($enablepermissioncheck) {
	$permissiontoread = $user->hasRight('stancer', 'read');
	$permissiontoadd = $user->hasRight('stancer', 'write');
	$permissiontodelete = 0; // No delete permission defined in this module
} else {
	$permissiontoread = 1;
	$permissiontoadd = 1;
	$permissiontodelete = 0;
}

// Security check (enable the most restrictive one)
if ($user->socid > 0) {
	accessforbidden();
}
//if ($user->socid > 0) accessforbidden();
//$socid = 0; if ($user->socid > 0) $socid = $user->socid;
//$isdraft = (($object->status == $object::STATUS_DRAFT) ? 1 : 0);
//restrictedArea($user, $object->module, 0, $object->table_element, $object->element, 'fk_soc', 'rowid', $isdraft);
if (!isModEnabled("stancer")) {
	accessforbidden('Module stancer not enabled');
}
if (!$permissiontoread) {
	accessforbidden();
}

/*
 * Actions
 */
$html = "";
if ($action == "fix") {
	$code_frais = GETPOST('accountingaccount_number');
	if (!empty($code_frais)) {
		$html .= "<h2>Application du code $code_frais</h2>";

		//verification inverse: y a t il des ecritures qui ne sont pas dans la liste ...
		$sql = "UPDATE ".MAIN_DB_PREFIX."accounting_bookkeeping SET numero_compte='" . $db->escape($code_frais) . "' WHERE rowid IN(";
		$sql .= "  SELECT rowid FROM ".MAIN_DB_PREFIX."accounting_bookkeeping WHERE numero_compte LIKE '471%' AND doc_type='bank' AND (doc_ref LIKE '%Stancer%' OR label_operation LIKE '%Stancer%')";
		$sql .= ")";
		$resql = $db->query($sql);
		// $html .= "<p>" . $sql ." </p>";
		if ($resql) {
			$html .= "<p>Update terminée</p>";
		} else {
			$html .= "<p>Erreur sql ! $sql</p>";
		}

		$html .= "<p>Retour <a href='index.php'>à l'index</a></p>";
	} else {
		$action = "";
	}
}

/*
 * View
 */

$form = new Form($db);
$formfile = new FormFile($db);

llxHeader("", $langs->trans("StancerArea"));

print load_fiche_titre($langs->trans("StancerArea"), '', 'stancer.png@stancer');

print '<div class="fichecenter">';


if ($action != "fix") {
	$formaccounting = new FormAccounting($db);

	print "<p>Après avoir versé en compta le journal de banque Stancer un certain nombre d'écritures sont en 471, cette action vous permet de leur affecter le 627xxxx que vous voulez</p>";
	print '<form enctype="multipart/form-data" method="POST" action="'.$_SERVER["PHP_SELF"].'" name="form_index">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="fix">';
	print $formaccounting->select_account('', 'accountingaccount_number', 1, array(), 1, 1, 'minwidth200 maxwidth500');

	print '<input type="submit" class="button" name="submit" value="' .  $langs->trans("StartFix") . '">';
	print '</form>';
} else {
	print $html;
}

print '</div>'; //</div>

// End of page
llxFooter();
$db->close();
