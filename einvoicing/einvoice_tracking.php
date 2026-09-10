<?php
/* Copyright (C) 2026	Charles Peltier

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
 *       \file       einvoicing/einvoice_tracking.php
 *       \ingroup    einvoicing
 *       \brief      Tab on the customer invoice card to track its e-invoicing lifecycle (current status + full history)
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
require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/invoice.lib.php';
// recordNotFound() below only exists from Dolibarr 20 on, and this page is served down to 18.
include_once __DIR__.'/compat/functions.lib.php';
dol_include_once('/einvoicing/class/einvoicing.class.php');
dol_include_once('/einvoicing/lib/einvoicing_lifecycle.lib.php');

// Load translation files required by the page
$langs->loadLangs(array('bills', 'einvoicing@einvoicing'));

$id  = GETPOSTINT('id');
$ref = GETPOST('ref', 'alpha');

// Security check (enable the most restrictive one)
if (!isModEnabled('einvoicing')) {
	accessforbidden('Module einvoicing not enabled');
}
if (!$user->hasRight('einvoicing', 'read')) {
	accessforbidden();
}

$object = new Facture($db);
if ($id > 0 || !empty($ref)) {
	$object->fetch($id, $ref);
}

// Standard invoice access rules (the einvoicing read right alone must not expose an invoice
// the user cannot otherwise read).
$result = restrictedArea($user, 'facture', $object->id);

// This tab only wires customer invoices (element_type 'facture'); supplier invoices write lifecycle
// events to the same table under 'invoice_supplier' and have their own separate UI, out of scope here.
// The reader below (EInvoicing::fetchLifecycleEvents()) is not specific to either type.
$elementType = 'facture';


/*
 * View
 */

$title = ($object->id > 0 ? $object->ref.' - ' : '').$langs->trans('EinvoiceEventsTab');
llxHeader('', $title);

$einvoicing = new EInvoicing($db);

if ($object->id > 0) {
	$object->fetch_thirdparty();

	$head = facture_prepare_head($object);
	print dol_get_fiche_head($head, 'einvoicetracking', $langs->trans('InvoiceCustomer'), -1, $object->picto);

	$linkback = '<a href="'.DOL_URL_ROOT.'/compta/facture/list.php?restore_lastsearch_values=1">'.$langs->trans("BackToList").'</a>';

	$morehtmlref = '<div class="refidno">';
	$morehtmlref .= $object->thirdparty->getNomUrl(1, 'customer');
	$morehtmlref .= '</div>';

	dol_banner_tab($object, 'ref', $linkback, 1, 'ref', 'ref', $morehtmlref, '', 0, '', '', 1);

	print dol_get_fiche_end();

	// --- Full lifecycle history for this invoice ---
	$events = $einvoicing->fetchLifecycleEvents($elementType, $object->id);

	// --- Current status: last event per actor (Fournisseur / PDP-PA / Client) ---
	// Ported from the LemonSuperPDP module (tab_lifecycle.php), generalized to einvoicing's
	// multi-provider status model.
	print load_fiche_titre($langs->trans('EInvCurrentStatus'), '', '');

	$last = array('fournisseur' => null, 'pdp' => null, 'client' => null);
	foreach (array_reverse($events) as $evt) {
		$flux = einvoicingLifecycleFlux($evt['lc_status'], $evt['direction']);
		if ($last[$flux] === null) {
			$last[$flux] = $evt;
		}
	}

	// Before the first PDP exchange, llx_einvoicing_lifecycle_msg has nothing to show yet: fall back to
	// the local Dolibarr-side status (not generated / generated / generation error) for the Fournisseur
	// card only, since it is the only actor with a meaningful state at that stage.
	$localStatus = empty($last['fournisseur']) ? $einvoicing->fetchLastknownInvoiceStatus($object->id, $object->ref) : null;

	$actors = array(
		'fournisseur' => array('label' => $langs->trans('EInvActorSeller'),   'color' => '#185FA5', 'dot' => '#185FA5'),
		'pdp'         => array('label' => $langs->trans('EInvActorPlatform'), 'color' => '#5F5E5A', 'dot' => '#888780'),
		'client'      => array('label' => $langs->trans('EInvActorBuyer'),    'color' => '#3B6D11', 'dot' => '#3B6D11'),
	);
	print '<div style="display:flex;align-items:stretch;padding:8px 0;gap:8px;border-top:1px solid #e0ddd6;border-bottom:1px solid #e0ddd6;margin-bottom:8px;">';
	foreach ($actors as $fluxKey => $actor) {
		$evt = $last[$fluxKey];
		print '<div style="flex:1;display:flex;flex-direction:column;gap:2px;padding:6px 10px;border-radius:6px;border:1px solid #e0ddd6;">';
		print '<div style="display:flex;align-items:center;gap:5px;margin-bottom:2px;">';
		print '<span style="width:7px;height:7px;border-radius:50%;background:'.$actor['dot'].';flex-shrink:0;"></span>';
		print '<span style="font-size:10px;font-weight:500;text-transform:uppercase;letter-spacing:.04em;color:'.$actor['color'].';">'.dol_escape_htmltag($actor['label']).'</span>';
		print '</div>';
		if ($evt) {
			$evtFull = einvoicingLifecycleLabel($einvoicing, (int) $evt['lc_status'], (string) $evt['lc_status_message']);
			print '<div style="font-size:12px;font-weight:500;overflow-wrap:anywhere;">'.dol_escape_htmltag($evtFull).'</div>';
			print '<div style="font-size:10px;color:#888780;margin-top:2px;">'.dol_print_date($evt['date_creation'], 'dayhour').'</div>';
		} elseif ($fluxKey === 'fournisseur' && !empty($localStatus)) {
			print '<div style="font-size:12px;font-weight:500;overflow-wrap:anywhere;">'.dol_escape_htmltag((string) $localStatus['status']).'</div>';
			print '<div style="font-size:10px;color:#888780;margin-top:2px;font-style:italic;">'.$langs->trans('EInvLocalStatusNotYetTransmitted').'</div>';
		} else {
			print '<div style="font-size:11px;color:#b4b2a9;font-style:italic;">'.$langs->trans('EInvNoLifecycleEvent').'</div>';
		}
		print '</div>';
	}
	print '</div>';

	// --- Detailed log (full text: validation status/message, reason code) ---
	print load_fiche_titre($langs->trans('EInvLifecycleHistory'), '', '');

	print '<div class="div-table-responsive-no-min">';
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre">';
	print '<th>'.$langs->trans('Date').'</th>';
	print '<th>'.$langs->trans('provider').'</th>';
	print '<th class="center">'.$langs->trans('EInvDirection').'</th>';
	print '<th>'.$langs->trans('Status').'</th>';
	print '<th>'.$langs->trans('Comments').'</th>';
	print '<th class="center">'.$langs->trans('EInvValidationStatus').'</th>';
	print '</tr>';

	if (!empty($events)) {
		foreach ($events as $evt) {
			$isOut = (strtolower((string) $evt['direction']) == 'out');

			print '<tr class="oddeven">';
			print '<td class="nowraponall">'.dol_print_date($evt['date_creation'], 'dayhour').'</td>';
			print '<td>'.dol_escape_htmltag((string) $evt['provider']).'</td>';
			print '<td class="center" title="'.dol_escape_htmltag($isOut ? $langs->trans('EInvDirectionOut') : $langs->trans('EInvDirectionIn')).'">'.($isOut ? img_picto($langs->trans('EInvDirectionOut'), 'sign-out', 'class="paddingright"') : img_picto($langs->trans('EInvDirectionIn'), 'sign-in-alt', 'class="paddingright"')).dol_escape_htmltag(strtoupper((string) $evt['direction'])).'</td>';
			print '<td>'.dol_escape_htmltag($einvoicing->getStatusLabel((int) $evt['lc_status']));
			if (!empty($evt['lc_reason_code'])) {
				print ' <span class="opacitymedium">('.dol_escape_htmltag((string) $evt['lc_reason_code']).')</span>';
			}
			print '</td>';
			print '<td>'.dol_escape_htmltag((string) $evt['lc_status_message']);
			if (!empty($evt['lc_validation_message'])) {
				print '<br><span class="opacitymedium">'.dol_escape_htmltag((string) $evt['lc_validation_message']).'</span>';
			}
			print '</td>';
			print '<td class="center">';
			// lc_validation_status is only filled for outbound ('out') events; an inbound row has it
			// empty by construction and that must not be read as a failed validation.
			if ($isOut && !empty($evt['lc_validation_status'])) {
				print dol_escape_htmltag((string) $evt['lc_validation_status']);
			}
			print '</td>';
			print '</tr>';
		}
	} else {
		print '<tr class="oddeven"><td colspan="6" class="opacitymedium">'.$langs->trans('EInvNoLifecycleEvent').'</td></tr>';
	}

	print '</table>';
	print '</div>';
} else {
	// Record not found
	recordNotFound('', 0);
}

// End of page
llxFooter();
$db->close();
