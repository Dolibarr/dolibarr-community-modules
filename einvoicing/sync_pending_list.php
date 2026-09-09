<?php
/* Copyright (C) 2026		Jose Martinez					<jose.martinez@pichinov.com>
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
 *   	\file       sync_pending_list.php
 *		\ingroup    einvoicing
 *		\brief      List of the flows queued for a manual action during synchronization.
 *
 *		A flow that cannot be synchronized because it needs a manual action (a missing product, a
 *		missing thirdparty, a supplier invoice with a different amount) used to abort the whole
 *		synchronization run. It is now recorded in this queue so the run carries on with the other
 *		flows. From here the user does the manual action (create the product/thirdparty) and retries
 *		the flow, without having to re-run the whole synchronization.
 */

// Load Dolibarr environment
$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
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
/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var HookManager $hookmanager
 * @var Translate $langs
 * @var User $user
 */
include_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
include_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';
dol_include_once('/einvoicing/class/einvoicingsyncpending.class.php');

// Load translation files required by the page
$langs->loadLangs(array("einvoicing@einvoicing", "other", "bills", "products", "companies"));

/**
 * Extract the manual actions from the HTML action block the module computes for a flow.
 *
 * The normalized action list ('allactiondata', stored in action_data) is incomplete on
 * some provider variants (only the single "create" entry), while the HTML block the
 * synchronization panel renders always carries every button. Parsing it here rebuilds the
 * full set of actions (link + icon), so the queue offers exactly the same choices as that
 * panel, for every variant, and for rows already queued without re-synchronizing them.
 *
 * @param 	string 	$html 	HTML block stored in action_html
 * @return 	array 			List of array('url'=>string, 'icon'=>string, 'text'=>string)
 */
function einvsp_actionsFromHtml($html)
{
	$out = array();
	if (empty($html)) {
		return $out;
	}
	if (preg_match_all('/<a\b[^>]*\bhref="([^"]*)"[^>]*>(.*?)<\/a>/is', $html, $anchors, PREG_SET_ORDER)) {
		foreach ($anchors as $a) {
			$url = trim(html_entity_decode($a[1], ENT_QUOTES | ENT_HTML5));
			if ($url === '') {
				continue;
			}
			$icon = '';
			if (preg_match('/\bfa-[a-z0-9]+(?:-[a-z0-9]+)*/i', $a[2], $im)) {
				$icon = $im[0];
			}
			$text = trim(html_entity_decode(strip_tags($a[2]), ENT_QUOTES | ENT_HTML5));
			$out[] = array('url' => $url, 'icon' => $icon, 'text' => $text);
		}
	}
	return $out;
}

/**
 * Resolve a manual action link to a short label key, help key and icon, from its
 * destination (unambiguous, unlike guessing from the icon alone: a "create product" and a
 * "create thirdparty" share the same plus icon).
 *
 * @param 	string 	$url 	Destination URL of the action
 * @return 	array 			array('label'=>langkey, 'help'=>langkey, 'icon'=>faicon)
 */
function einvsp_actionMetaFromUrl($url)
{
	if (strpos($url, 'product_mapping.php') !== false) {
		return array('label' => 'AssociateExistingProductShort', 'help' => 'ActionAssociateProductHelp', 'icon' => 'fa-link');
	}
	if (strpos($url, '/societe/card.php') !== false && strpos($url, 'action=create') !== false) {
		return array('label' => 'CreateSupplierShort', 'help' => 'ActionCreateThirdpartyHelp', 'icon' => 'fa-plus-circle');
	}
	if (strpos($url, '/societe/card.php') !== false) {
		return array('label' => 'SetDefaultProductShort', 'help' => 'ActionSetDefaultProductHelp', 'icon' => 'fa-star');
	}
	if (strpos($url, '/product/card.php') !== false) {
		if (preg_match('/[?&]type=1(?:&|$)/', $url)) {
			return array('label' => 'CreateService', 'help' => 'ActionCreateProductHelp', 'icon' => 'fa-plus-circle');
		}
		return array('label' => 'CreateProduct', 'help' => 'ActionCreateProductHelp', 'icon' => 'fa-plus-circle');
	}
	return array('label' => '', 'help' => '', 'icon' => '');
}

// Parameters
$action     = GETPOST('action', 'aZ09') ? GETPOST('action', 'aZ09') : 'view';
$confirm    = GETPOST('confirm', 'alpha');
$rowid      = GETPOSTINT('rowid');
$optioncss  = GETPOST('optioncss', 'aZ');
$contextpage = GETPOST('contextpage', 'aZ') ? GETPOST('contextpage', 'aZ') : 'einvoicingsyncpendinglist';

$search_status = GETPOSTISSET('search_status') ? GETPOST('search_status', 'intcomma') : (string) EInvoicingSyncPending::STATUS_PENDING;
$search_flow_id = GETPOST('search_flow_id', 'alphanohtml');
$search_reason = GETPOST('search_reason', 'alphanohtml');

// Pagination
$limit = GETPOSTINT('limit') ? GETPOSTINT('limit') : $conf->liste_limit;
$sortfield = GETPOST('sortfield', 'aZ09comma');
$sortorder = GETPOST('sortorder', 'aZ09comma');
$page = GETPOSTISSET('pageplusone') ? (GETPOSTINT('pageplusone') - 1) : GETPOSTINT("page");
if (empty($page) || $page < 0 || GETPOST('button_search', 'alpha') || GETPOST('button_removefilter', 'alpha')) {
	$page = 0;
}
$offset = $limit * $page;
if (!$sortfield) {
	$sortfield = "t.status, t.flow_updatedat";
}
if (!$sortorder) {
	$sortorder = "ASC, DESC";
}

$form = new Form($db);
$object = new EInvoicingSyncPending($db);

$permissiontoread = $user->hasRight('einvoicing', 'read');
$permissiontowrite = $user->hasRight('einvoicing', 'write');
if (!$permissiontoread) {
	accessforbidden();
}

$providerkey = preg_replace('/ViaPartner$/', '', (string) getDolGlobalString('EINVOICING_PDP'));


/*
 * Actions
 */

if ($action == 'confirm_retry' && $rowid > 0 && $permissiontowrite && $confirm == 'yes') {
	$object->fetch($rowid);
	if ($object->id > 0) {
		dol_include_once('/einvoicing/class/providers/PDPProviderManager.class.php');
		$providerManager = new PDPProviderManager($db);
		$provider = $providerManager->getProvider(getDolGlobalString('EINVOICING_PDP'));
		if (!is_object($provider)) {
			setEventMessages($langs->trans("NoActivePDPProvider"), null, 'errors');
		} else {
			$syncres = $provider->syncFlow($object->flow_id, null);
			if (is_array($syncres) && isset($syncres['res']) && $syncres['res'] >= 0) {
				$object->resolveByFlowId($object->flow_id, $object->provider, $user);
				setEventMessages($langs->trans("FlowSynchronizedAndResolved", $object->flow_id), null, 'mesgs');
			} else {
				// Still needs a manual action: refresh the queued row (attempt counter, last message).
				$flowArr = array(
					'flowId' => $object->flow_id,
					'flowDirection' => $object->flow_direction,
					'flowType' => $object->flow_type,
					'trackingId' => $object->tracking_idref,
					'updatedAt' => $object->flow_updatedat,
				);
				$reason = (is_array($syncres) && !empty($syncres['actioncode'])) ? $syncres['actioncode'] : $object->reason_code;
				$message = (is_array($syncres) && !empty($syncres['message'])) ? $syncres['message'] : '';
				$manualactions = array();
				if (is_array($syncres) && !empty($syncres['allactiondata']) && is_array($syncres['allactiondata'])) {
					foreach ($syncres['allactiondata'] as $akey => $adata) {
						if (!empty($adata['url'])) {
							$manualactions[] = array('key' => $akey, 'url' => $adata['url'], 'label' => ($adata['label'] ?? ''));
						}
					}
				} elseif (is_array($syncres) && !empty($syncres['actionurl'])) {
					$manualactions[] = array('key' => ($reason == 'THIRDPARTY_NOT_FOUND' ? 'createthirdparty' : 'create'), 'url' => $syncres['actionurl'], 'label' => '');
				}
				$actionhtml = (is_array($syncres) && !empty($syncres['action'])) ? $syncres['action'] : '';
				$matchdata = (is_array($syncres) && !empty($syncres['actiondata'])) ? $syncres['actiondata'] : array();
				$object->queueFromFlow($flowArr, $object->provider, $reason, $message, $manualactions, $user, $actionhtml, $matchdata);
				setEventMessages($langs->trans("FlowStillNeedsManualAction", $object->flow_id).($message ? ' - '.$message : ''), null, 'warnings');
			}
		}
	}
	$action = 'view';
}

if ($action == 'ignore' && $rowid > 0 && $permissiontowrite) {
	$object->fetch($rowid);
	if ($object->id > 0) {
		$object->status = EInvoicingSyncPending::STATUS_IGNORED;
		if ($object->update($user) > 0) {
			setEventMessages($langs->trans("FlowIgnored", $object->flow_id), null, 'mesgs');
		}
	}
	$action = 'view';
}

if ($action == 'reopen' && $rowid > 0 && $permissiontowrite) {
	$object->fetch($rowid);
	if ($object->id > 0) {
		$object->status = EInvoicingSyncPending::STATUS_PENDING;
		if ($object->update($user) > 0) {
			setEventMessages($langs->trans("FlowReopened", $object->flow_id), null, 'mesgs');
		}
	}
	$action = 'view';
}

if ($action == 'confirm_delete' && $rowid > 0 && $permissiontowrite && $confirm == 'yes') {
	$object->fetch($rowid);
	if ($object->id > 0 && $object->delete($user) > 0) {
		setEventMessages($langs->trans("RecordDeleted"), null, 'mesgs');
	}
	$action = 'view';
}

// Link a THIRDPARTY_NOT_FOUND flow to an existing thirdparty: write the issuer identifiers (SIREN/SIRET/VAT)
// carried by the invoice onto the chosen thirdparty, so the matching finds it, then retry the flow.
if ($action == 'confirm_linkthirdparty' && $rowid > 0 && $permissiontowrite) {
	$linksocid = GETPOSTINT('socid');
	$object->fetch($rowid);
	if ($object->id <= 0 || $object->reason_code != 'THIRDPARTY_NOT_FOUND') {
		setEventMessages($langs->trans("RecordNotFound"), null, 'errors');
		$action = 'view';
	} elseif ($linksocid <= 0) {
		setEventMessages($langs->trans("SelectAThirdPartyFirst"), null, 'errors');
		$action = 'linkthirdparty';
	} else {
		require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
		$md = json_decode((string) $object->match_data, true);
		if (!is_array($md)) {
			$md = array();
		}
		$soc = new Societe($db);
		if ($soc->fetch($linksocid) <= 0) {
			setEventMessages($langs->trans("RecordNotFound"), null, 'errors');
			$action = 'linkthirdparty';
		} else {
			// Write only the fields the user ticked on the comparison screen (apply_<key>).
			$idmap = array('name' => 'name', 'vatnumber' => 'tva_intra', 'idprof1' => 'idprof1', 'idprof2' => 'idprof2', 'idprof3' => 'idprof3', 'email' => 'email');
			$nbwritten = 0;
			foreach ($idmap as $mdkey => $socfield) {
				$val = trim((string) ($md[$mdkey] ?? ''));
				if ($val === '' || !GETPOST('apply_'.$mdkey, 'int')) {
					continue;
				}
				$soc->$socfield = $val;
				$nbwritten++;
			}
			$okupd = 1;
			if ($nbwritten > 0) {
				$okupd = $soc->update($soc->id, $user);
			}
			if ($okupd <= 0) {
				setEventMessages($soc->error, $soc->errors, 'errors');
				$action = 'view';
			} else {
				if ($nbwritten > 0) {
					setEventMessages($langs->trans("ThirdpartyFieldsWritten", $soc->name, $nbwritten), null, 'mesgs');
				} else {
					setEventMessages($langs->trans("NoFieldSelectedToApply"), null, 'warnings');
				}
				// Retry the flow now: with the identifiers set, the matching should find the thirdparty.
				dol_include_once('/einvoicing/class/providers/PDPProviderManager.class.php');
				$providerManager = new PDPProviderManager($db);
				$provider = $providerManager->getProvider(getDolGlobalString('EINVOICING_PDP'));
				if (!is_object($provider)) {
					setEventMessages($langs->trans("ThirdpartyUpdatedButNoProvider", $soc->name), null, 'warnings');
				} else {
					$syncres = $provider->syncFlow($object->flow_id, null);
					if (is_array($syncres) && isset($syncres['res']) && $syncres['res'] >= 0) {
						$object->resolveByFlowId($object->flow_id, $object->provider, $user);
						setEventMessages($langs->trans("ThirdpartyLinkedAndFlowSynchronized", $soc->name, $object->flow_id), null, 'mesgs');
					} else {
						$stillmsg = (is_array($syncres) && !empty($syncres['message'])) ? $syncres['message'] : '';
						setEventMessages($langs->trans("ThirdpartyLinkedButFlowStillPending", $soc->name).($stillmsg ? ' - '.$stillmsg : ''), null, 'warnings');
					}
				}
				$action = 'view';
			}
		}
	}
}


/*
 * View
 */

$title = $langs->trans("EInvoiceSyncPendingQueue");
llxHeader('', $title, '', '', 0, 0, '', '', '', 'bodyforlist');

// Compact icon-only manual actions on a single line, and keep the reason on a single line.
print '<style>
.einv-actions { white-space:nowrap; text-align:center; }
.einv-actions a { display:inline-block; margin:0 6px; font-size:1.15em; text-decoration:none; line-height:1; }
.einv-actions a:hover { opacity:0.7; }
.einv-reason { white-space:nowrap; }
.einv-rowactions { white-space:nowrap; }
.einv-rowactions a { margin:0 4px; }
.einv-manualactions a.button, .einv-manualactions a.butAction { display:block; width:auto; margin:2px 0; text-align:left; white-space:normal; }
</style>';

// Build query
$sql = "SELECT t.rowid, t.provider, t.flow_id, t.flow_direction, t.flow_type, t.tracking_idref,";
$sql .= " t.fk_element_type, t.fk_element_id, t.reason_code, t.reason_message, t.action_data, t.action_html,";
$sql .= " t.flow_updatedat, t.nb_attempts, t.date_lastattempt, t.status";
$sql .= " FROM ".MAIN_DB_PREFIX."einvoicing_sync_pending as t";
$sql .= " WHERE t.entity IN (".getEntity('einvoicing').")";
if ($search_status != '' && $search_status != '-1') {
	$sql .= " AND t.status = ".((int) $search_status);
}
if ($search_flow_id != '') {
	$sql .= natural_search('t.flow_id', $search_flow_id);
}
if ($search_reason != '') {
	$sql .= natural_search('t.reason_code', $search_reason);
}
$sql .= $db->order($sortfield, $sortorder);

$nbtotalofrecords = '';
$resql = $db->query($sql.$db->plimit($limit + 1, $offset));
if (!$resql) {
	dol_print_error($db);
	exit;
}
$num = $db->num_rows($resql);

// Count pending (for the badge)
$nbpending = 0;
$resc = $db->query("SELECT COUNT(*) as nb FROM ".MAIN_DB_PREFIX."einvoicing_sync_pending WHERE entity IN (".getEntity('einvoicing').") AND status = ".((int) EInvoicingSyncPending::STATUS_PENDING));
if ($resc) {
	$objc = $db->fetch_object($resc);
	$nbpending = (int) $objc->nb;
}

$param = '';
if (!empty($limit) && $limit != $conf->liste_limit) {
	$param .= '&limit='.((int) $limit);
}
if ($search_status != '') {
	$param .= '&search_status='.urlencode($search_status);
}
if ($search_flow_id != '') {
	$param .= '&search_flow_id='.urlencode($search_flow_id);
}
if ($search_reason != '') {
	$param .= '&search_reason='.urlencode($search_reason);
}

print '<form method="POST" id="searchFormList" action="'.$_SERVER["PHP_SELF"].'">'."\n";
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="view">';
print '<input type="hidden" name="sortfield" value="'.$sortfield.'">';
print '<input type="hidden" name="sortorder" value="'.$sortorder.'">';
print '<input type="hidden" name="contextpage" value="'.$contextpage.'">';

$titletext = $langs->trans("EInvoiceSyncPendingQueue");
if ($nbpending > 0) {
	$titletext .= ' <span class="badge badge-warning">'.$nbpending.'</span>';
}
print load_fiche_titre($titletext, '', 'fa-hourglass-half');

print '<div class="opacitymedium justify">'.$langs->trans("EInvoiceSyncPendingHelp").'</div><br>';

// Form to associate a THIRDPARTY_NOT_FOUND flow to an existing thirdparty (writes the issuer SIREN/SIRET/VAT).
if ($action == 'linkthirdparty' && $rowid > 0 && $permissiontowrite) {
	$linkobj = new EInvoicingSyncPending($db);
	$linkobj->fetch($rowid);
	if ($linkobj->id > 0 && $linkobj->reason_code == 'THIRDPARTY_NOT_FOUND') {
		$md = json_decode((string) $linkobj->match_data, true);
		if (!is_array($md)) {
			$md = array();
		}
		print load_fiche_titre($langs->trans("AssociateExistingThirdpartyTitle"), '', 'fa-link');
		print '<div class="opacitymedium">'.$langs->trans("AssociateExistingThirdpartyIntro").'</div>';
		// Issuer identifiers carried by the invoice (read-only)
		print '<div class="div-table-responsive-no-min">';
		print '<table class="border centpercent">';
		print '<tr><td class="titlefieldcreate">'.$langs->trans("Flow").'</td><td>'.dol_escape_htmltag($linkobj->flow_id).' &mdash; '.dol_escape_htmltag($linkobj->tracking_idref).'</td></tr>';
		print '<tr><td>'.$langs->trans("ThirdPartyName").'</td><td><b>'.dol_escape_htmltag($md['name'] ?? '-').'</b></td></tr>';
		print '<tr><td>'.$langs->trans("VATIntra").'</td><td>'.dol_escape_htmltag($md['vatnumber'] ?? '-').'</td></tr>';
		print '<tr><td>'.$langs->trans("ProfId1").' (SIREN)</td><td>'.dol_escape_htmltag($md['idprof1'] ?? '-').'</td></tr>';
		print '<tr><td>'.$langs->trans("ProfId2").' (SIRET)</td><td>'.dol_escape_htmltag($md['idprof2'] ?? '-').'</td></tr>';
		print '</table>';
		print '</div><br>';

		// Proactively surface candidate thirdparties matching the issuer: exact SIREN/SIRET/VAT, then name.
		$candidates = array();
		$searchmap = array(
			'idprof1'   => array('col' => 'siren',     'label' => $langs->trans("ProfId1").' (SIREN)'),
			'idprof2'   => array('col' => 'siret',     'label' => $langs->trans("ProfId2").' (SIRET)'),
			'vatnumber' => array('col' => 'tva_intra', 'label' => $langs->trans("VATIntra")),
		);
		foreach ($searchmap as $skey => $sdef) {
			$sval = trim((string) ($md[$skey] ?? ''));
			if ($sval === '') {
				continue;
			}
			$rs = $db->query("SELECT rowid, nom FROM ".MAIN_DB_PREFIX."societe WHERE ".$sdef['col']." = '".$db->escape($sval)."' AND entity IN (".getEntity('societe').") LIMIT 20");
			if ($rs) {
				while ($os = $db->fetch_object($rs)) {
					if (!isset($candidates[(int) $os->rowid])) {
						$candidates[(int) $os->rowid] = array('name' => $os->nom, 'crit' => array(), 'hasid' => 0);
					}
					$candidates[(int) $os->rowid]['crit'][$sdef['label']] = 1;
					$candidates[(int) $os->rowid]['hasid'] = 1;
				}
			}
		}
		// By name: full string, plus each significant word (>=4 chars), to catch a thirdparty that exists without the fiscal id.
		$nm = trim((string) ($md['name'] ?? ''));
		if ($nm !== '') {
			$likeclauses = array("nom LIKE '%".$db->escape($nm)."%'");
			// Ignore legal forms / very generic words so the per-word search does not flood the candidates.
			$stopwords = array('SARL', 'SARLU', 'SAS', 'SASU', 'EURL', 'SNC', 'SCI', 'SCM', 'SCOP', 'SCEA', 'GAEC', 'EARL', 'SELARL', 'SELAS', 'SCP', 'ASSOCIATION', 'ENTREPRISE', 'ETABLISSEMENT', 'ETABLISSEMENTS', 'SOCIETE', 'GROUPE', 'COMPAGNIE', 'LTD', 'GMBH', 'LLC', 'CORP', 'FRANCE');
			$words = array_filter(preg_split('/[^A-Za-z0-9&]+/', $nm), function ($w) use ($stopwords) {
				return strlen($w) >= 4 && !in_array(strtoupper($w), $stopwords, true);
			});
			foreach (array_slice(array_values($words), 0, 3) as $w) {
				// Whole-word match (start/end or space-bounded) to avoid substring noise ('NOUVEAU' vs 'reNOUVEAU').
				$we = $db->escape($w);
				$likeclauses[] = "(nom = '".$we."' OR nom LIKE '".$we." %' OR nom LIKE '% ".$we."' OR nom LIKE '% ".$we." %')";
			}
			$rs = $db->query("SELECT rowid, nom FROM ".MAIN_DB_PREFIX."societe WHERE (".implode(' OR ', $likeclauses).") AND entity IN (".getEntity('societe').") LIMIT 20");
			if ($rs) {
				while ($os = $db->fetch_object($rs)) {
					if (!isset($candidates[(int) $os->rowid])) {
						$candidates[(int) $os->rowid] = array('name' => $os->nom, 'crit' => array(), 'hasid' => 0);
					}
					$candidates[(int) $os->rowid]['crit'][$langs->trans("Name")] = 1;
				}
			}
		}
		// Fiscal-id matches first (they are the definitive ones), then name-only matches.
		uasort($candidates, function ($a, $b) {
			return ((int) ($b['hasid'] ?? 0)) <=> ((int) ($a['hasid'] ?? 0));
		});

		print '<div class="marginbottomonly">'.img_picto('', 'fa-search', 'class="paddingrightonly"').'<b>'.$langs->trans("MatchingThirdpartyCandidates").'</b></div>';
		if (!empty($candidates)) {
			print '<div class="div-table-responsive-no-min">';
			print '<table class="noborder centpercent">';
			print '<tr class="liste_titre"><th>'.$langs->trans("ThirdParty").'</th><th>'.$langs->trans("MatchedOn").'</th><th></th></tr>';
			foreach ($candidates as $csocid => $cinfo) {
				print '<tr class="oddeven">';
				print '<td><a href="'.DOL_URL_ROOT.'/societe/card.php?socid='.((int) $csocid).'" target="_blank">'.dol_escape_htmltag($cinfo['name']).'</a> <span class="opacitymedium small">(#'.((int) $csocid).')</span></td>';
				print '<td>'.dol_escape_htmltag(implode(', ', array_keys($cinfo['crit']))).'</td>';
				print '<td class="right"><a class="butAction small smallpaddingimp" href="'.$_SERVER["PHP_SELF"].'?action=comparelinkthirdparty&rowid='.((int) $rowid).'&socid='.((int) $csocid).'&token='.newToken().$param.'">'.$langs->trans("AssociateWithThisThirdparty").' &rarr;</a></td>';
				print '</tr>';
			}
			print '</table>';
			print '</div><br>';
		} else {
			print '<div class="opacitymedium marginbottomonly">'.$langs->trans("NoMatchingThirdpartyCandidate").'</div>';
		}

		// Manual selector fallback (search another thirdparty).
		print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'">';
		print '<input type="hidden" name="token" value="'.newToken().'">';
		print '<input type="hidden" name="action" value="comparelinkthirdparty">';
		print '<input type="hidden" name="rowid" value="'.((int) $rowid).'">';
		print '<div class="tabBar tabBarWithBottom">';
		print '<span class="opacitymedium marginrightonly">'.$langs->trans("OrSelectAnotherThirdparty").'</span> ';
		print $form->select_company(GETPOSTINT('socid'), 'socid', '', $langs->trans("SelectAThirdParty"), 0, 0, array(), 0, 'minwidth300');
		print ' <input type="submit" class="button smallpaddingimp" value="'.$langs->trans("CompareFields").'">';
		print '</div>';
		print '<div class="center margintopbottom5"><a class="button button-cancel" href="'.$_SERVER["PHP_SELF"].($param ? '?'.ltrim($param, '&') : '').'">'.$langs->trans("Cancel").'</a></div>';
		print '</form><br>';
	}
}

// Step 2: compare the invoice identifiers with the chosen thirdparty, one checkbox per field to apply.
if ($action == 'comparelinkthirdparty' && $rowid > 0 && $permissiontowrite) {
	$linkobj = new EInvoicingSyncPending($db);
	$linkobj->fetch($rowid);
	$cmpsocid = GETPOSTINT('socid');
	if ($linkobj->id <= 0 || $linkobj->reason_code != 'THIRDPARTY_NOT_FOUND') {
		setEventMessages($langs->trans("RecordNotFound"), null, 'errors');
	} elseif ($cmpsocid <= 0) {
		setEventMessages($langs->trans("SelectAThirdPartyFirst"), null, 'errors');
		$action = 'linkthirdparty';
	} else {
		require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
		$md = json_decode((string) $linkobj->match_data, true);
		if (!is_array($md)) {
			$md = array();
		}
		$cmpsoc = new Societe($db);
		if ($cmpsoc->fetch($cmpsocid) <= 0) {
			setEventMessages($langs->trans("RecordNotFound"), null, 'errors');
			$action = 'linkthirdparty';
		} else {
			// Fields comparable between the invoice issuer and the chosen thirdparty.
			$cmpfields = array(
				'name'      => array('socfield' => 'name',      'label' => $langs->trans("ThirdPartyName")),
				'vatnumber' => array('socfield' => 'tva_intra', 'label' => $langs->trans("VATIntra")),
				'idprof1'   => array('socfield' => 'idprof1',   'label' => $langs->trans("ProfId1").' (SIREN)'),
				'idprof2'   => array('socfield' => 'idprof2',   'label' => $langs->trans("ProfId2").' (SIRET)'),
				'idprof3'   => array('socfield' => 'idprof3',   'label' => $langs->trans("ProfId3")),
				'email'     => array('socfield' => 'email',     'label' => $langs->trans("Email")),
			);
			print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'">';
			print '<input type="hidden" name="token" value="'.newToken().'">';
			print '<input type="hidden" name="action" value="confirm_linkthirdparty">';
			print '<input type="hidden" name="rowid" value="'.((int) $rowid).'">';
			print '<input type="hidden" name="socid" value="'.((int) $cmpsocid).'">';
			print load_fiche_titre($langs->trans("AssociateExistingThirdpartyTitle").' &rarr; '.dol_escape_htmltag($cmpsoc->name), '', 'fa-link');
			print '<div class="opacitymedium">'.$langs->trans("CompareFieldsIntro").'</div>';

			// Uniqueness pre-check: a professional identifier (SIREN/SIRET/VAT) that already belongs to
			// ANOTHER thirdparty cannot be written here (Dolibarr enforces it). Warn and offer that thirdparty,
			// which is very often the right one to associate.
			$uniqueCols = array('vatnumber' => 'tva_intra', 'idprof1' => 'siren', 'idprof2' => 'siret');
			$fieldOwner = array();
			$ownerGroups = array();
			foreach ($uniqueCols as $ukey => $ucol) {
				$uval = trim((string) ($md[$ukey] ?? ''));
				if ($uval === '') {
					continue;
				}
				$ro = $db->query("SELECT rowid, nom FROM ".MAIN_DB_PREFIX."societe WHERE ".$ucol." = '".$db->escape($uval)."' AND rowid <> ".((int) $cmpsocid)." AND entity IN (".getEntity('societe').") LIMIT 1");
				if ($ro && $db->num_rows($ro) > 0) {
					$oo = $db->fetch_object($ro);
					$fieldOwner[$ukey] = array('socid' => (int) $oo->rowid, 'name' => $oo->nom);
					if (!isset($ownerGroups[(int) $oo->rowid])) {
						$ownerGroups[(int) $oo->rowid] = array('name' => $oo->nom, 'fields' => array());
					}
					$ownerGroups[(int) $oo->rowid]['fields'][] = $cmpfields[$ukey]['label'];
				}
			}
			if (!empty($ownerGroups)) {
				print '<div class="warning clearboth">'.img_picto('', 'warning', 'class="pictowarning paddingrightonly"').'<b>'.$langs->trans("IdentifiersAlreadyUsedTitle").'</b><br>';
				print '<span class="opacitymedium">'.$langs->trans("IdentifiersAlreadyUsedHelp").'</span>';
				foreach ($ownerGroups as $osocid => $info) {
					print '<div class="margintoponly">&bull; <b>'.dol_escape_htmltag($info['name']).'</b> (#'.((int) $osocid).') — '.dol_escape_htmltag(implode(', ', $info['fields']));
					print ' <a class="butAction small smallpaddingimp marginleftonly" href="'.$_SERVER["PHP_SELF"].'?action=comparelinkthirdparty&rowid='.((int) $rowid).'&socid='.((int) $osocid).'&token='.newToken().$param.'">'.$langs->trans("AssociateWithThisThirdparty").' &rarr;</a></div>';
				}
				print '</div><br>';
			}

			print '<div class="div-table-responsive-no-min">';
			print '<table class="noborder centpercent">';
			print '<tr class="liste_titre">';
			print '<th>'.$langs->trans("Field").'</th>';
			print '<th>'.$langs->trans("ValueFromInvoice").'</th>';
			print '<th>'.$langs->trans("CurrentThirdpartyValue").'</th>';
			print '<th class="center">'.$langs->trans("Apply").'</th>';
			print '</tr>';
			$anyapplicable = false;
			foreach ($cmpfields as $mdkey => $fdef) {
				$newval = trim((string) ($md[$mdkey] ?? ''));
				if ($newval === '') {
					continue;
				}
				$curval = trim((string) $cmpsoc->{$fdef['socfield']});
				$same = (preg_replace('/\s+/', '', $curval) === preg_replace('/\s+/', '', $newval));
				$isconflict = ($curval !== '' && !$same);
				$isempty = ($curval === '');
				$defaultcheck = ($isempty && $mdkey !== 'name');		// fill empty identifiers, never rename by default
				print '<tr class="oddeven">';
				print '<td><b>'.dol_escape_htmltag($fdef['label']).'</b></td>';
				print '<td>'.dol_escape_htmltag($newval).'</td>';
				print '<td>';
				if ($same) {
					print '<span class="badge badge-status4 badge-status">'.$langs->trans("Identical").'</span>';
				} elseif ($isconflict) {
					print '<span class="warning">'.dol_escape_htmltag($curval).'</span> '.$form->textwithpicto('', $langs->trans("FieldConflictHelp"), 1, 'warning');
				} else {
					print '<span class="opacitymedium">'.$langs->trans("Empty").'</span>';
				}
				print '</td>';
				$ownedByOther = isset($fieldOwner[$mdkey]);
				print '<td class="center">';
				if ($same) {
					print '<span class="opacitymedium">-</span>';
				} elseif ($ownedByOther) {
					print '<input type="checkbox" disabled> '.$form->textwithpicto('', $langs->trans("FieldOwnedByOtherHelp", $fieldOwner[$mdkey]['name']), 1, 'warning');
				} else {
					print '<input type="checkbox" name="apply_'.$mdkey.'" value="1"'.($defaultcheck ? ' checked' : '').'>';
					if ($isconflict) {
						print ' <span class="opacitymedium small">'.$langs->trans("Overwrite").'</span>';
					}
					$anyapplicable = true;
				}
				print '</td>';
				print '</tr>';
			}
			print '</table>';
			print '</div>';
			print '<div class="center margintopbottom5">';
			if (!$anyapplicable) {
				print '<div class="opacitymedium marginbottomonly">'.$langs->trans("NothingToApplyThirdpartyAlreadyMatches").'</div>';
			}
			print '<input type="submit" class="button" value="'.$langs->trans("Associate").'">';
			print ' &nbsp; <a class="button button-cancel" href="'.$_SERVER["PHP_SELF"].'?action=linkthirdparty&rowid='.((int) $rowid).'&token='.newToken().$param.'">'.$langs->trans("Back").'</a>';
			print '</div>';
			print '</form><br>';
		}
	}
}

print '<div class="div-table-responsive">';
print '<table class="tagtable nobottomiftotal liste">'."\n";

// Filters line (9 columns: Flow id, Type, RefObject, Reason, Action, Attempts, Updated, Status, Actions)
print '<tr class="liste_titre_filter">';
print '<td class="liste_titre"><input type="text" class="flat maxwidth100" name="search_flow_id" placeholder="'.dol_escape_htmltag($langs->trans("flow_id")).'" value="'.dol_escape_htmltag($search_flow_id).'"></td>';
print '<td class="liste_titre center"></td>';
print '<td class="liste_titre"></td>';
print '<td class="liste_titre"><input type="text" class="flat maxwidth125" name="search_reason" placeholder="'.dol_escape_htmltag($langs->trans("ReasonCode")).'" value="'.dol_escape_htmltag($search_reason).'"></td>';
print '<td class="liste_titre center"></td>';
print '<td class="liste_titre center"></td>';
print '<td class="liste_titre"></td>';
print '<td class="liste_titre right">';
$arrayofstatus = array(
	'-1' => $langs->trans("All"),
	(string) EInvoicingSyncPending::STATUS_PENDING => $langs->trans("Pending"),
	(string) EInvoicingSyncPending::STATUS_RESOLVED => $langs->trans("Resolved"),
	(string) EInvoicingSyncPending::STATUS_IGNORED => $langs->trans("Ignored"),
);
print $form->selectarray('search_status', $arrayofstatus, $search_status, 0, 0, 0, '', 0, 0, 0, '', 'maxwidth100');
print '</td>';
print '<td class="liste_titre center maxwidthsearch">';
print $form->showFilterButtons();
print '</td>';
print '</tr>';

// Header line
print '<tr class="liste_titre">';
print getTitleFieldOfList($langs->trans("flow_id"), 0, $_SERVER["PHP_SELF"], "t.flow_id", "", $param, "", $sortfield, $sortorder)."\n";
print getTitleFieldOfList($langs->trans("Type"), 0, $_SERVER["PHP_SELF"], "t.flow_type", "", $param, 'align="center"', $sortfield, $sortorder)."\n";
print getTitleFieldOfList($langs->trans("RefObject"), 0, $_SERVER["PHP_SELF"], "t.tracking_idref", "", $param, "", $sortfield, $sortorder)."\n";
print getTitleFieldOfList($langs->trans("ReasonCode"), 0, $_SERVER["PHP_SELF"], "t.reason_code", "", $param, "", $sortfield, $sortorder)."\n";
print getTitleFieldOfList($langs->trans("ManualAction"), 0, $_SERVER["PHP_SELF"], "", "", $param, 'align="center"', $sortfield, $sortorder)."\n";
print getTitleFieldOfList($langs->trans("Attempts"), 0, $_SERVER["PHP_SELF"], "t.nb_attempts", "", $param, 'align="center"', $sortfield, $sortorder)."\n";
print getTitleFieldOfList($langs->trans("updatedAt"), 0, $_SERVER["PHP_SELF"], "t.flow_updatedat", "", $param, "", $sortfield, $sortorder)."\n";
print getTitleFieldOfList($langs->trans("Status"), 0, $_SERVER["PHP_SELF"], "t.status", "", $param, 'align="right"', $sortfield, $sortorder)."\n";
print getTitleFieldOfList('', 0, $_SERVER["PHP_SELF"], "", "", $param, '', $sortfield, $sortorder, 'center maxwidthsearch ')."\n";
print '</tr>';

$statuslabels = array(
	EInvoicingSyncPending::STATUS_PENDING => array('label' => $langs->trans("Pending"), 'css' => 'status1', 'help' => $langs->trans("PendingHelp")),
	EInvoicingSyncPending::STATUS_RESOLVED => array('label' => $langs->trans("Resolved"), 'css' => 'status4', 'help' => $langs->trans("ResolvedHelp")),
	EInvoicingSyncPending::STATUS_IGNORED => array('label' => $langs->trans("Ignored"), 'css' => 'status9', 'help' => $langs->trans("IgnoredHelp")),
);

// Short explanation of each business reason code, shown in the tooltip of the reason badge.
$reasonhelp = array(
	'PRODUCT_NOT_FOUND' => $langs->trans("ReasonProductNotFoundHelp"),
	'THIRDPARTY_NOT_FOUND' => $langs->trans("ReasonThirdpartyNotFoundHelp"),
	'SUPPLIER_INVOICE_FOUND_WITH_BAD_AMOUNT' => $langs->trans("ReasonBadAmountHelp"),
);

// Short human label of each reason code (the raw code stays in the tooltip), kept on a single line.
$reasonshort = array(
	'PRODUCT_NOT_FOUND' => $langs->trans("ReasonProductNotFoundShort"),
	'THIRDPARTY_NOT_FOUND' => $langs->trans("ReasonThirdpartyNotFoundShort"),
	'SUPPLIER_INVOICE_FOUND_WITH_BAD_AMOUNT' => $langs->trans("ReasonBadAmountShort"),
);

// Icon + tooltip for each manual action key computed by the protocol.
$actionmeta = array(
	'createproduct'       => array('icon' => 'fa-plus-circle', 'label' => 'CreateProduct',                 'help' => 'ActionCreateProductHelp'),
	'createservice'       => array('icon' => 'fa-plus-circle', 'label' => 'CreateService',                 'help' => 'ActionCreateProductHelp'),
	'create'              => array('icon' => 'fa-plus-circle', 'label' => 'Create',                        'help' => 'ActionCreateProductHelp'),
	'createthirdparty'    => array('icon' => 'fa-plus-circle', 'label' => 'CreateSupplierShort',           'help' => 'ActionCreateThirdpartyHelp'),
	'addsupplierrefprice' => array('icon' => 'fa-link',        'label' => 'AssociateExistingProductShort', 'help' => 'ActionAssociateProductHelp'),
	'setdefaultproduct'   => array('icon' => 'fa-star',        'label' => 'SetDefaultProductShort',        'help' => 'ActionSetDefaultProductHelp'),
);

$imaxinloop = ($limit ? min($num, $limit) : $num);
$i = 0;
while ($i < $imaxinloop) {
	$obj = $db->fetch_object($resql);
	if (empty($obj)) {
		break;
	}
	$i++;

	$data = array();
	if (!empty($obj->action_data)) {
		$data = json_decode($obj->action_data, true);
		if (!is_array($data)) {
			$data = array();
		}
	}

	print '<tr class="oddeven">';
	// Flow id
	print '<td class="tdoverflowmax150 small" title="'.dol_escape_htmltag($obj->flow_id).'">'.dol_escape_htmltag($obj->flow_id).'</td>';
	// Type = direction icon (incoming supplier invoice to import / outgoing customer invoice refresh) + flow type
	print '<td class="center nowraponall">';
	if ($obj->flow_direction == 'In') {
		print '<span class="classfortooltip" title="'.dol_escape_htmltag($langs->trans("FlowDirectionIn").' - '.$langs->trans("FlowDirectionInHelp"), 1).'">'.img_picto('', 'fa-arrow-down', 'class="paddingrightonly"').'</span>';
	} elseif ($obj->flow_direction == 'Out') {
		print '<span class="classfortooltip" title="'.dol_escape_htmltag($langs->trans("FlowDirectionOut").' - '.$langs->trans("FlowDirectionOutHelp"), 1).'">'.img_picto('', 'fa-arrow-up', 'class="paddingrightonly"').'</span>';
	}
	print dol_escape_htmltag($obj->flow_type);
	print '</td>';
	// Tracking ref
	print '<td class="nowraponall">'.dol_escape_htmltag($obj->tracking_idref).'</td>';
	// Reason: short human label on a single line, with the raw code + explanation + last message in the tooltip
	print '<td class="einv-reason">';
	if ($obj->reason_code) {
		$short = $reasonshort[$obj->reason_code] ?? $obj->reason_code;
		$rhelp = $reasonhelp[$obj->reason_code] ?? '';
		$tip = '<b>'.dol_escape_htmltag($obj->reason_code).'</b>';
		if ($rhelp) {
			$tip .= '<br>'.dol_escape_htmltag($rhelp);
		}
		if (!empty($obj->reason_message)) {
			$tip .= '<br><br>'.dol_escape_htmltag($obj->reason_message);
		}
		print $form->textwithpicto('<span class="badge badge-status1 badge-status">'.dol_escape_htmltag($short).'</span>', $tip, 1, 'warning');
	}
	print '</td>';
	// Manual action: only the icons (create / associate an existing product / set a default one), on one
	// line, each with an explanatory tooltip. Same actions and URLs as the synchronization page.
	print '<td class="einv-actions">';
	$hasactions = false;
	// Prefer the module HTML block: it always lists every manual action the synchronization
	// panel shows (the normalized list is incomplete on some provider variants). Each label
	// is resolved from the link destination, which is unambiguous. Fall back to the
	// normalized action_data list for older rows that have no HTML block.
	$htmlactions = einvsp_actionsFromHtml($obj->action_html);
	if (!empty($htmlactions)) {
		foreach ($htmlactions as $act) {
			$meta = einvsp_actionMetaFromUrl($act['url']);
			$icon = !empty($meta['icon']) ? $meta['icon'] : ($act['icon'] !== '' ? $act['icon'] : 'fa-wrench');
			$lbl = !empty($meta['label']) ? $langs->trans($meta['label']) : ($act['text'] !== '' ? $act['text'] : $icon);
			$hlp = !empty($meta['help']) ? $langs->trans($meta['help']) : '';
			$tip = dol_escape_htmltag('<b>'.$lbl.'</b>'.($hlp ? "\n".$hlp : ''), 1, 1);
			print '<a class="classfortooltip" href="'.dol_escape_htmltag($act['url']).'" target="_blank" title="'.$tip.'">'.img_picto('', $icon).'</a>';
			$hasactions = true;
		}
	} elseif (is_array($data) && !empty($data) && isset($data[0]) && is_array($data[0]) && isset($data[0]['key'])) {
		foreach ($data as $act) {
			if (empty($act['url'])) {
				continue;
			}
			$meta = $actionmeta[$act['key']] ?? array('icon' => 'fa-wrench', 'label' => '', 'help' => '');
			$lbl = !empty($meta['label']) ? $langs->trans($meta['label']) : (!empty($act['label']) ? $act['label'] : $act['key']);
			$hlp = !empty($meta['help']) ? $langs->trans($meta['help']) : '';
			$tip = dol_escape_htmltag('<b>'.$lbl.'</b>'.($hlp ? "\n".$hlp : ''), 1, 1);
			print '<a class="classfortooltip" href="'.dol_escape_htmltag($act['url']).'" target="_blank" title="'.$tip.'">'.img_picto('', $meta['icon']).'</a>';
			$hasactions = true;
		}
	}
	// Queue-specific action the module does not provide: link a missing thirdparty to an existing one
	// (writes the issuer SIREN/SIRET/VAT onto it so the matching finds it). Stays on this page (a form).
	if ($obj->reason_code == 'THIRDPARTY_NOT_FOUND' && $user->hasRight('societe', 'creer') && (int) $obj->status == EInvoicingSyncPending::STATUS_PENDING) {
		$tip = dol_escape_htmltag('<b>'.$langs->trans("AssociateExistingThirdpartyShort")."</b>\n".$langs->trans("ActionAssociateThirdpartyHelp"), 1, 1);
		print '<a class="classfortooltip" href="'.$_SERVER["PHP_SELF"].'?action=linkthirdparty&rowid='.$obj->rowid.'&token='.newToken().$param.'" title="'.$tip.'">'.img_picto('', 'fa-link').'</a>';
		$hasactions = true;
	}
	if (!$hasactions) {
		print '<span class="opacitymedium">-</span>';
	}
	print '</td>';
	// Attempts
	print '<td class="center">'.((int) $obj->nb_attempts).'</td>';
	// Updated at
	print '<td class="nowraponall">'.dol_print_date($db->jdate($obj->flow_updatedat), 'dayhour').'</td>';
	// Status + explanatory tooltip
	print '<td class="right">';
	$st = $statuslabels[(int) $obj->status] ?? array('label' => $obj->status, 'css' => 'status0', 'help' => '');
	print '<span class="badge badge-'.$st['css'].' classfortooltip" title="'.dol_escape_htmltag($st['help'] ?? '', 1).'">'.$st['label'].'</span>';
	print '</td>';
	// Row actions
	print '<td class="einv-rowactions center">';
	if ($permissiontowrite) {
		if ((int) $obj->status != EInvoicingSyncPending::STATUS_RESOLVED) {
			$tipretry = dol_escape_htmltag('<b>'.$langs->trans("RetrySync")."</b>\n".$langs->trans("RetrySyncHelp"), 1, 1);
			print '<a class="marginrightonly classfortooltip reposition" href="'.$_SERVER["PHP_SELF"].'?action=confirm_retry&confirm=yes&rowid='.$obj->rowid.'&token='.newToken().$param.'" title="'.$tipretry.'">'.img_picto('', 'refresh', 'class="pictofixedwidth"').'</a>';
		}
		if ((int) $obj->status == EInvoicingSyncPending::STATUS_PENDING) {
			$tipignore = dol_escape_htmltag('<b>'.$langs->trans("IgnoreFlow")."</b>\n".$langs->trans("IgnoreFlowHelp"), 1, 1);
			print '<a class="marginrightonly classfortooltip reposition" href="'.$_SERVER["PHP_SELF"].'?action=ignore&rowid='.$obj->rowid.'&token='.newToken().$param.'" title="'.$tipignore.'">'.img_picto('', 'fa-ban', 'class="pictofixedwidth"').'</a>';
		} else {
			$tipreopen = dol_escape_htmltag('<b>'.$langs->trans("ReopenFlow")."</b>\n".$langs->trans("ReopenFlowHelp"), 1, 1);
			print '<a class="marginrightonly classfortooltip reposition" href="'.$_SERVER["PHP_SELF"].'?action=reopen&rowid='.$obj->rowid.'&token='.newToken().$param.'" title="'.$tipreopen.'">'.img_picto('', 'fa-undo', 'class="pictofixedwidth"').'</a>';
		}
		$tipdelete = dol_escape_htmltag('<b>'.$langs->trans("Delete")."</b>\n".$langs->trans("DeletePendingFlowHelp"), 1, 1);
		print '<a class="marginrightonly classfortooltip reposition" href="'.$_SERVER["PHP_SELF"].'?action=delete&rowid='.$obj->rowid.'&token='.newToken().$param.'" title="'.$tipdelete.'">'.img_picto('', 'delete', 'class="pictofixedwidth"').'</a>';
	}
	print '</td>';
	print '</tr>';
}

if ($num == 0) {
	print '<tr><td colspan="9" class="opacitymedium center">'.$langs->trans("NoPendingFlow").'</td></tr>';
}

print '</table>';
print '</div>';
print '</form>';

// Confirmation popups
if ($action == 'delete' && $rowid > 0 && $permissiontowrite) {
	print $form->formconfirm($_SERVER["PHP_SELF"].'?rowid='.$rowid.$param, $langs->trans("Delete"), $langs->trans("ConfirmDeletePendingFlow"), 'confirm_delete', '', 0, 1);
}

llxFooter();
$db->close();
