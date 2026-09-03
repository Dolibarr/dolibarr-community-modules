<?php
/* Copyright (C) 2001-2005  Rodolphe Quiedeville    <rodolphe@quiedeville.org>
 * Copyright (C) 2004-2015  Laurent Destailleur     <eldy@users.sourceforge.net>
 * Copyright (C) 2005-2012  Regis Houssin           <regis.houssin@inodbox.com>
 * Copyright (C) 2015       Jean-François Ferry     <jfefe@aternatik.fr>
 * Copyright (C) 2024       Frédéric France         <frederic.france@free.fr>
 * Copyright (C) 2025		SuperAdmin					<daoud.mouhamed@gmail.com>
 * Copyright (C) 2026		MDW							<mdeweerd@users.noreply.github.com>
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
 *	\file       einvoicing/einvoicingindex.php
 *	\ingroup    einvoicing
 *	\brief      Home page of einvoicing top menu
 */

// Load Dolibarr environment
$res = 0;
// Try main.inc.php into web root known defined into CONTEXT_DOCUMENT_ROOT (not always defined)
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
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
if (!$res && file_exists("../../../../main.inc.php")) {
	$res = @include "../../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}
/**
 * The main.inc.php has been included so the following variable are now defined:
 * @var Conf $conf
 * @var DoliDB $db
 * @var HookManager $hookmanager
 * @var Translate $langs
 * @var User $user
 */
'@phan-var-force User $user';
require_once DOL_DOCUMENT_ROOT . '/core/lib/functions.lib.php';
include_once DOL_DOCUMENT_ROOT.'/core/class/html.formfile.class.php';

// Load translation files required by the page
$langs->loadLangs(array("einvoicing@einvoicing"));

// Load required classes
include_once __DIR__ . '/class/providers/PDPProviderManager.class.php';
include_once __DIR__ . '/class/providers/AbstractPDPProvider.class.php';

$action = GETPOST('action', 'aZ09');

$now = dol_now();
$max = getDolGlobalInt('MAIN_SIZE_SHORTLIST_LIMIT', 5);

// Security check - Protection if external user
$socid = GETPOSTINT('socid');
if (!empty($user->socid) && $user->socid > 0) {
	$action = '';
	$socid = $user->socid;
}

// Initialize a technical object to manage hooks. Note that conf->hooks_modules contains array
//$hookmanager->initHooks(array($object->element.'index'));

// Security check (enable the most restrictive one)
if (!isModEnabled('einvoicing')) {
	accessforbidden('Module not enabled');
}
if (!$user->hasRight('einvoicing', 'read')) {
	accessforbidden();
}


/*
 * Actions
 */

// None


/*
 * View
 */

$form = new Form($db);
$formfile = new FormFile($db);

llxHeader("", $langs->trans("EInvoiceManagement"), '', '', 0, 0, '', '', '', 'mod-einvoicing page-index');

print load_fiche_titre($langs->trans("EInvoiceManagement"), '', 'einvoicing.png@einvoicing');

print '<div class="fichecenter">';



// Check if connected to a PA (Access Point)
$PDPManager = new PDPProviderManager($db);
$pa_connected = false;
$pa_name = '';

if (getDolGlobalString('EINVOICING_PDP')) {
	$provider = $PDPManager->getProvider(getDolGlobalString('EINVOICING_PDP'));

	if ($provider instanceof AbstractPDPProvider) {
		$pa_name = $provider->name;

		$tokenData = $provider->getTokenData();
		// Check if there is a token or credentials configured
		if ($tokenData['token']) {
			$pa_connected = true;
		}
	}
}

// Display PA connection status
if ($pa_connected) {
	print '<div class="green greenborder nomargintop">';
	print '<td colspan="2" class="center">' . $langs->trans("YourSoftwareSeemsConnectedWith", dol_strtoupper($pa_name)) . '</td>';
	print '</div>';
	print '<br>';
} else {
	print '<div class="warning nomargintop">';
	print '<td colspan="2" class="center">' . $langs->trans("YourSoftwareDoesNotSeemsConnectedWith") . '</td>';
	print '</div>';
	print '<br>';
}



// Dashboard - Synchronization statistics
print '<div class="fichehalfleft">';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<th colspan="2">'.$langs->trans("SynchronizationDashboard").'</th>';
print '</tr>';

// Get last synchronization date
$sql_last_sync = "SELECT MAX(date_creation) as last_sync FROM " . $db->prefix() . "einvoicing_document";
$resql_last_sync = $db->query($sql_last_sync);
$last_sync_date = '';
if ($resql_last_sync && $db->num_rows($resql_last_sync) > 0) {
	$obj_last_sync = $db->fetch_object($resql_last_sync);
	if (!empty($obj_last_sync->last_sync)) {
		$last_sync_date = dol_print_date($db->jdate($obj_last_sync->last_sync), 'dayhour');
	}
	$db->free($resql_last_sync);
}

// Get count of customer invoices
$sql_customer = "SELECT COUNT(*) as nb_customer FROM " . $db->prefix() . "einvoicing_document WHERE fk_element_type = 'facture' and flow_type = 'CustomerInvoice'";
$resql_customer = $db->query($sql_customer);
$nb_customer = 0;
if ($resql_customer && $db->num_rows($resql_customer) > 0) {
	$obj_customer = $db->fetch_object($resql_customer);
	$nb_customer = $obj_customer->nb_customer;
	$db->free($resql_customer);
}

// Get count of supplier invoices
$sql_supplier = "SELECT COUNT(*) as nb_supplier FROM " . $db->prefix() . "einvoicing_document WHERE fk_element_type = 'invoice_supplier' and flow_type = 'SupplierInvoice'";
$resql_supplier = $db->query($sql_supplier);
$nb_supplier = 0;
if ($resql_supplier && $db->num_rows($resql_supplier) > 0) {
	$obj_supplier = $db->fetch_object($resql_supplier);
	$nb_supplier = $obj_supplier->nb_supplier;
	$db->free($resql_supplier);
}

// Display dashboard
print '<tr class="oddeven">';
print '<td>'.$langs->trans("LastSynchronizationDate").'</td>';
print '<td class="right">' . ($last_sync_date ?: $langs->trans("None")) . '</td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>'.$langs->trans("CustomerInvoicesSynchronized").'</td>';
print '<td class="right"><span class="badge badge-info">' . $nb_customer . '</span></td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>'.$langs->trans("SupplierInvoicesSynchronized").'</td>';
print '<td class="right"><span class="badge badge-info">' . $nb_supplier . '</span></td>';
print '</tr>';

print '</table>';
print '</div>';


print '</div>';

print '<div class="fichehalfright">';



print '</div><div class="clearboth"></div>';

print '<div class="fichethirdleft">';


/* BEGIN MODULEBUILDER DRAFT MYOBJECT
// Draft MyObject
if (isModEnabled('einvoicing') && $user->hasRight('einvoicing', 'read')) {
	$langs->load("orders");

	$sql = "SELECT c.rowid, c.ref, c.ref_client, c.total_ht, c.tva as total_tva, c.total_ttc, s.rowid as socid, s.nom as name, s.client, s.canvas";
	$sql.= ", s.code_client";
	$sql.= " FROM ".$db->prefix()."commande as c";
	$sql.= ", ".$db->prefix()."societe as s";
	$sql.= " WHERE c.fk_soc = s.rowid";
	$sql.= " AND c.fk_statut = 0";
	$sql.= " AND c.entity IN (".getEntity('commande').")";
	if ($socid)	$sql.= " AND c.fk_soc = ".((int) $socid);

	$resql = $db->query($sql);
	if ($resql)
	{
		$total = 0;
		$num = $db->num_rows($resql);

		print '<table class="noborder centpercent">';
		print '<tr class="liste_titre">';
		print '<th colspan="3">'.$langs->trans("DraftMyObjects").($num?'<span class="badge marginleftonlyshort">'.$num.'</span>':'').'</th></tr>';

		$var = true;
		if ($num > 0)
		{
			$i = 0;
			while ($i < $num)
			{

				$obj = $db->fetch_object($resql);
				print '<tr class="oddeven"><td class="nowrap">';

				$myobjectstatic->id=$obj->rowid;
				$myobjectstatic->ref=$obj->ref;
				$myobjectstatic->ref_client=$obj->ref_client;
				$myobjectstatic->total_ht = $obj->total_ht;
				$myobjectstatic->total_tva = $obj->total_tva;
				$myobjectstatic->total_ttc = $obj->total_ttc;

				print $myobjectstatic->getNomUrl(1);
				print '</td>';
				print '<td class="nowrap">';
				print '</td>';
				print '<td class="right" class="nowrap">'.price($obj->total_ttc).'</td></tr>';
				$i++;
				$total += $obj->total_ttc;
			}
			if ($total>0)
			{

				print '<tr class="liste_total"><td>'.$langs->trans("Total").'</td><td colspan="2" class="right">'.price($total)."</td></tr>";
			}
		}
		else
		{

			print '<tr class="oddeven"><td colspan="3" class="opacitymedium">'.$langs->trans("NoOrder").'</td></tr>';
		}
		print "</table><br>";

		$db->free($resql);
	}
	else
	{
		dol_print_error($db);
	}
}
END MODULEBUILDER DRAFT MYOBJECT */


print '</div><div class="fichetwothirdright">';


/* BEGIN MODULEBUILDER LASTMODIFIED MYOBJECT
// Last modified myobject
if (isModEnabled('einvoicing') && $user->hasRight('einvoicing', 'read')) {
	$sql = "SELECT s.rowid, s.ref, s.label, s.date_creation, s.tms";
	$sql.= " FROM ".$db->prefix()."einvoicing_myobject as s";
	$sql.= " WHERE s.entity IN (".getEntity($myobjectstatic->element).")";
	//if ($socid)	$sql.= " AND s.rowid = $socid";
	$sql .= " ORDER BY s.tms DESC";
	$sql .= $db->plimit($max, 0);

	$resql = $db->query($sql);
	if ($resql)
	{
		$num = $db->num_rows($resql);
		$i = 0;

		print '<table class="noborder centpercent">';
		print '<tr class="liste_titre">';
		print '<th colspan="2">';
		print $langs->trans("BoxTitleLatestModifiedMyObjects", $max);
		print '</th>';
		print '<th class="right">'.$langs->trans("DateModificationShort").'</th>';
		print '</tr>';
		if ($num)
		{
			while ($i < $num)
			{
				$objp = $db->fetch_object($resql);

				$myobjectstatic->id=$objp->rowid;
				$myobjectstatic->ref=$objp->ref;
				$myobjectstatic->label=$objp->label;
				$myobjectstatic->status = $objp->status;

				print '<tr class="oddeven">';
				print '<td class="nowrap">'.$myobjectstatic->getNomUrl(1).'</td>';
				print '<td class="right nowrap">';
				print "</td>";
				print '<td class="right nowrap">'.dol_print_date($db->jdate($objp->tms), 'day')."</td>";
				print '</tr>';
				$i++;
			}

			$db->free($resql);
		} else {
			print '<tr class="oddeven"><td colspan="3" class="opacitymedium">'.$langs->trans("None").'</td></tr>';
		}
		print "</table><br>";
	}
}
*/

print '</div></div>';

// End of page
llxFooter();
$db->close();
