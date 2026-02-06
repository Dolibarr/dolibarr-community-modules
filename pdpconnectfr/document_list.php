<?php
/* Copyright (C) 2007-2017  Laurent Destailleur     <eldy@users.sourceforge.net>
 * Copyright (C) 2024       Frédéric France         <frederic.france@free.fr>
 * Copyright (C) 2025		SuperAdmin					<daoud.mouhamed@gmail.com>
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
 *   	\file       document_list.php
 *		\ingroup    pdpconnectfr
 *		\brief      List page for document
 */

// General defined Options
//if (! defined('CSRFCHECK_WITH_TOKEN'))     define('CSRFCHECK_WITH_TOKEN', '1');					// Force use of CSRF protection with tokens even for GET
//if (! defined('MAIN_AUTHENTICATION_MODE')) define('MAIN_AUTHENTICATION_MODE', 'aloginmodule');	// Force authentication handler
//if (! defined('MAIN_LANG_DEFAULT'))        define('MAIN_LANG_DEFAULT', 'auto');					// Force LANG (language) to a particular value
//if (! defined('MAIN_SECURITY_FORCECSP'))   define('MAIN_SECURITY_FORCECSP', 'none');				// Disable all Content Security Policies
//if (! defined('NOBROWSERNOTIF'))     		 define('NOBROWSERNOTIF', '1');					// Disable browser notification
//if (! defined('NOIPCHECK'))                define('NOIPCHECK', '1');						// Do not check IP defined into conf $dolibarr_main_restrict_ip
//if (! defined('NOLOGIN'))                  define('NOLOGIN', '1');						// Do not use login - if this page is public (can be called outside logged session). This includes the NOIPCHECK too.
//if (! defined('NOREQUIREAJAX'))            define('NOREQUIREAJAX', '1');       	  		// Do not load ajax.lib.php library
//if (! defined('NOREQUIREDB'))              define('NOREQUIREDB', '1');					// Do not create database handler $db
//if (! defined('NOREQUIREHTML'))            define('NOREQUIREHTML', '1');					// Do not load html.form.class.php
//if (! defined('NOREQUIREMENU'))            define('NOREQUIREMENU', '1');					// Do not load and show top and left menu
//if (! defined('NOREQUIRESOC'))             define('NOREQUIRESOC', '1');					// Do not load object $mysoc
//if (! defined('NOREQUIRETRAN'))            define('NOREQUIRETRAN', '1');					// Do not load object $langs
//if (! defined('NOREQUIREUSER'))            define('NOREQUIREUSER', '1');					// Do not load object $user
//if (! defined('NOSCANGETFORINJECTION'))    define('NOSCANGETFORINJECTION', '1');			// Do not check injection attack on GET parameters
//if (! defined('NOSCANPOSTFORINJECTION'))   define('NOSCANPOSTFORINJECTION', '1');			// Do not check injection attack on POST parameters
//if (! defined('NOSESSION'))                define('NOSESSION', '1');						// On CLI mode, no need to use web sessions
//if (! defined('NOSTYLECHECK'))             define('NOSTYLECHECK', '1');					// Do not check style html tag into posted data
//if (! defined('NOTOKENRENEWAL'))           define('NOTOKENRENEWAL', '1');					// Do not roll the Anti CSRF token (used if MAIN_SECURITY_CSRF_WITH_TOKEN is on)


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
include_once DOL_DOCUMENT_ROOT.'/core/class/html.formcompany.class.php';
include_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';
include_once DOL_DOCUMENT_ROOT.'/core/lib/company.lib.php';
include_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
include_once __DIR__.'/class/providers/PDPProviderManager.class.php';

// load module libraries
include_once __DIR__.'/class/document.class.php';
// for other modules
//dol_include_once('/othermodule/class/otherobject.class.php');

// Load translation files required by the page
$langs->loadLangs(array("pdpconnectfr@pdpconnectfr", "other"));

// Get parameters
$action     = GETPOST('action', 'aZ09') ? GETPOST('action', 'aZ09') : 'view'; // The action 'create'/'add', 'edit'/'update', 'view', ...
$massaction = GETPOST('massaction', 'alpha'); // The bulk action (combo box choice into lists)
$show_files = GETPOSTINT('show_files'); // Show files area generated by bulk actions ?
$confirm    = GETPOST('confirm', 'alpha'); // Result of a confirmation
$cancel     = GETPOST('cancel', 'alpha'); // We click on a Cancel button
$toselect   = GETPOST('toselect', 'array:int'); // Array of ids of elements selected into a list
$contextpage = GETPOST('contextpage', 'aZ') ? GETPOST('contextpage', 'aZ') : str_replace('_', '', basename(dirname(__FILE__)).basename(__FILE__, '.php')); // To manage different context of search
$backtopage = GETPOST('backtopage', 'alpha'); // Go back to a dedicated page
$optioncss  = GETPOST('optioncss', 'aZ'); // Option for the css output (always '' except when 'print')
$mode       = GETPOST('mode', 'aZ'); // The display mode ('list', 'kanban', 'hierarchy', 'calendar', 'gantt', ...)
$groupby = GETPOST('groupby', 'aZ09');	// Example: $groupby = 'p.fk_opp_status' or $groupby = 'p.fk_statut'

$id = GETPOSTINT('id');
$ref = GETPOST('ref', 'alpha');
$sync_result = '';
$maxflows = GETPOSTINT('maxflows');
$syncFromDate = GETPOSTINT('syncfromdate');

$syncfromdatehour = GETPOSTINT('last_sync_datetimehour');
$syncfromdatemin = GETPOSTINT('last_sync_datetimemin');
$syncfromdatemonth = GETPOSTINT('last_sync_datetimemonth');
$syncfromdateday = GETPOSTINT('last_sync_datetimeday');
$syncfromdateyear = GETPOSTINT('last_sync_datetimeyear');
if (empty($syncfromdateyear) || empty($syncfromdatemonth) || empty($syncfromdateday)) {
	// If "sync from" date not provied from the form, we may have it into the syncfromdate parameter.
	$syncfromdate = $syncFromDate;
} else {
	$syncfromdate = dol_mktime($syncfromdatehour, $syncfromdatemin, 0, $syncfromdatemonth, $syncfromdateday, $syncfromdateyear, 'tzuserrel');
}
//var_dump(dol_print_date($syncfromdate, 'dayhour', 'gmt'));exit;

// Load variable for pagination
$limit = GETPOSTINT('limit') ? GETPOSTINT('limit') : $conf->liste_limit;
$sortfield = GETPOST('sortfield', 'aZ09comma');
$sortorder = GETPOST('sortorder', 'aZ09comma');
$page = GETPOSTISSET('pageplusone') ? (GETPOSTINT('pageplusone') - 1) : GETPOSTINT('page');
if (empty($page) || $page < 0 || GETPOST('button_search', 'alpha') || GETPOST('button_removefilter', 'alpha')) {
	// If $page is not defined, or '' or -1 or if we click on clear filters
	$page = 0;
}
$offset = $limit * $page;
$pageprev = $page - 1;
$pagenext = $page + 1;

// Initialize technical objects
$object = new Document($db);
$extrafields = new ExtraFields($db);
$diroutputmassaction = $conf->pdpconnectfr->dir_output.'/temp/massgeneration/'.$user->id;
$hookmanager->initHooks(array($contextpage)); 	// Note that conf->hooks_modules contains array of activated contexes

// Fetch optionals attributes and labels
$extrafields->fetch_name_optionals_label($object->table_element);
//$extrafields->fetch_name_optionals_label($object->table_element_line);

$search_array_options = $extrafields->getOptionalsFromPost($object->table_element, '', 'search_');

// Default sort order (if not yet defined by previous GETPOST)
if (!$sortfield) {
	//reset($object->fields);					// Reset is required to avoid key() to return null.
	$sortfield = "t.rowid"; // Set here default search field. By default 1st field in definition.
}
if (!$sortorder) {
	$sortorder = "DESC";
}

// Add a recap field into $object->fields array for list (we add it into object directly to be able to position it)
// TODO : Update Module Builder to manage virtual fields
$object->fields['recap'] = array(
	'label' => $langs->trans("flowRecap"),
	'type' => 'text',
	'visible' => 1,
	'enabled' => '1',
	'position' => 51,
	'checked' => 0,
	'csslist' => 'small',
	'notsearchable' => 1
);


// Initialize array of search criteria
$search_all = trim(GETPOST('search_all', 'alphanohtml'));
$search = array();
foreach ($object->fields as $key => $val) {
	if ($key == 'recap') {
		continue;
	}
	if (GETPOST('search_'.$key, 'alpha') !== '') {
		$search[$key] = GETPOST('search_'.$key, 'alpha');
	}
	if (preg_match('/^(date|timestamp|datetime)/', $val['type'])) {
		$search[$key.'_dtstart'] = dol_mktime(0, 0, 0, GETPOSTINT('search_'.$key.'_dtstartmonth'), GETPOSTINT('search_'.$key.'_dtstartday'), GETPOSTINT('search_'.$key.'_dtstartyear'));
		$search[$key.'_dtend'] = dol_mktime(23, 59, 59, GETPOSTINT('search_'.$key.'_dtendmonth'), GETPOSTINT('search_'.$key.'_dtendday'), GETPOSTINT('search_'.$key.'_dtendyear'));
	}
}

// List of fields to search into when doing a "search in all"
$fieldstosearchall = array();
// foreach ($object->fields as $key => $val) {
// 	if (!empty($val['searchall'])) {
// 		$fieldstosearchall['t.'.$key] = $val['label'];
// 	}
// }
// $parameters = array('fieldstosearchall'=>$fieldstosearchall);
// $reshook = $hookmanager->executeHooks('completeFieldsToSearchAll', $parameters, $object, $action); // Note that $action and $object may have been modified by some hooks
// if ($reshook > 0) {
// 	$fieldstosearchall = empty($hookmanager->resArray['fieldstosearchall']) ? array() : $hookmanager->resArray['fieldstosearchall'];
// } elseif ($reshook == 0) {
// 	$fieldstosearchall = array_merge($fieldstosearchall, empty($hookmanager->resArray['fieldstosearchall']) ? array() : $hookmanager->resArray['fieldstosearchall']);
// }

// Definition of array of fields for columns from ->fields
$tableprefix = 't';
$arrayfields = array();
foreach ($object->fields as $key => $val) {
	// If $val['visible']==0, then we never show the field
	if (!empty($val['visible'])) {
		$visible = (int) dol_eval((string) $val['visible'], 1);
		$arrayfields[$tableprefix.'.'.$key] = array(
			'label' => $val['label'],
			'checked' => (($visible < 0) ? '0' : '1'),
			'enabled' => (string) (int) (abs($visible) != 3 && (bool) dol_eval((string) $val['enabled'], 1)),
			'position' => $val['position'],
			'help' => isset($val['help']) ? $val['help'] : ''
		);
	}
}

// Extra fields
include DOL_DOCUMENT_ROOT.'/core/tpl/extrafields_list_array_fields.tpl.php';

// Complete arrayfields with special fields
/*$arrayfields = array_merge($arrayfields, array(
	'anotherfield' => array('type'=>'integer', 'label'=>'AnotherField', 'checked'=>'1', 'enabled'=>'1', 'position'=>'90', 'csslist'=>'right'),
));*/

$object->fields = dol_sort_array($object->fields, 'position');
$arrayfields = dol_sort_array($arrayfields, 'position');

// There is several ways to check permission.
// Set $enablepermissioncheck to 1 to enable a minimum low level of checks
$enablepermissioncheck = getDolGlobalInt('PDPCONNECTFR_ENABLE_PERMISSION_CHECK');
if ($enablepermissioncheck) {
	$permissiontoread = $user->hasRight('pdpconnectfr', 'document', 'read');
	$permissiontoadd = $user->hasRight('pdpconnectfr', 'document', 'write');
	$permissiontodelete = $user->hasRight('pdpconnectfr', 'document', 'delete');
} else {
	$permissiontoread = 1;
	$permissiontoadd = 1;
	$permissiontodelete = 1;
}

// Security check (enable the most restrictive one)
if ($user->socid > 0) {
	accessforbidden();
}
//if ($user->socid > 0) accessforbidden();
//$socid = 0; if ($user->socid > 0) $socid = $user->socid;
//$isdraft = (($object->status == $object::STATUS_DRAFT) ? 1 : 0);
//restrictedArea($user, $object->module, 0, $object->table_element, $object->element, 'fk_soc', 'rowid', $isdraft);
if (!isModEnabled("pdpconnectfr")) {
	accessforbidden('Module pdpconnectfr not enabled');
}
if (!$permissiontoread) {
	accessforbidden();
}


/*
 * Actions
 */

if (GETPOST('cancel', 'alpha')) {
	$action = 'list';
	$massaction = '';
}
if (!GETPOST('confirmmassaction', 'alpha') && $massaction != 'presend' && $massaction != 'confirm_presend') {
	$massaction = '';
}

$parameters = array('arrayfields' => &$arrayfields);
$reshook = $hookmanager->executeHooks('doActions', $parameters, $object, $action); // Note that $action and $object may have been modified by some hooks
if ($reshook < 0) {
	setEventMessages($hookmanager->error, $hookmanager->errors, 'errors');
}

if (empty($reshook)) {
	// Selection of new fields
	include DOL_DOCUMENT_ROOT.'/core/actions_changeselectedfields.inc.php';

	// Purge search criteria
	if (GETPOST('button_removefilter_x', 'alpha') || GETPOST('button_removefilter.x', 'alpha') || GETPOST('button_removefilter', 'alpha')) { // All tests are required to be compatible with all browsers
		foreach ($object->fields as $key => $val) {
			$search[$key] = '';
			if (preg_match('/^(date|timestamp|datetime)/', $val['type'])) {
				$search[$key.'_dtstart'] = '';
				$search[$key.'_dtend'] = '';
			}
		}
		$search_all = '';
		$toselect = array();
		$search_array_options = array();
	}
	if (GETPOST('button_removefilter_x', 'alpha') || GETPOST('button_removefilter.x', 'alpha') || GETPOST('button_removefilter', 'alpha')
		|| GETPOST('button_search_x', 'alpha') || GETPOST('button_search.x', 'alpha') || GETPOST('button_search', 'alpha')) {
		$massaction = ''; // Protection to avoid mass action if we force a new search during a mass action confirmation
	}

	// Mass actions
	$objectclass = 'Document';
	$objectlabel = 'Document';
	$uploaddir = $conf->pdpconnectfr->dir_output;

	global $error;
	include DOL_DOCUMENT_ROOT.'/core/actions_massactions.inc.php';

	// You can add more action here
	// if ($action == 'xxx' && $permissiontoxxx) ...
}


if (getDolGlobalString('PDPCONNECTFR_PDP') && getDolGlobalString('PDPCONNECTFR_PDP') === "ESALINK") {
	$providerManager = new PDPProviderManager($db);
	$provider = $providerManager->getProvider(getDolGlobalString('PDPCONNECTFR_PDP'));
}

if ($action == 'confirm_sync' && getDolGlobalString('PDPCONNECTFR_PDP') && $confirm == 'yes') {
	if (isset($provider)) {
		// Sync all flows
		$sync_result = $provider->syncFlows($syncFromDate, $maxflows);

		if (!empty($sync_result['syncedFlows']) && $sync_result['syncedFlows'] > 0) {
			setEventMessages($langs->trans("DocumentsSyncedSuccessfully", $sync_result['syncedFlows']), null, 'mesgs');
		}
		if ($sync_result['res'] <= 0) {
			$errortype = 'errors';
			if (!empty($sync_result['actions'])) {
				$errortype = 'warnings';
			}
			//setEventMessages($langs->trans("FailedToSyncADocument").($errortype ? '<br>'.$langs->trans("FailedToSyncADocumentMore") : ''), null, $errortype);

		}
	} else {
		setEventMessages($langs->trans("NoPDPProviderConfigured"), null, 'errors');
	}
}


/*
 * View
 */

$form = new Form($db);

$now = dol_now();

$title = $langs->trans("EInvoiceSynchronization");
$help_url = '';
$morejs = array();
$morecss = array();


// Build and execute select
// --------------------------------------------------------------------
$sql = "SELECT";
$sql .= " ".$object->getFieldList('t', array('recap'));
// Add fields from extrafields
if (!empty($extrafields->attributes[$object->table_element]['label'])) {
	foreach ($extrafields->attributes[$object->table_element]['label'] as $key => $val) {
		$sql .= ($extrafields->attributes[$object->table_element]['type'][$key] != 'separate' ? ", ef.".$key." as options_".$key : "");
	}
}
// Add fields from hooks
$parameters = array();
$reshook = $hookmanager->executeHooks('printFieldListSelect', $parameters, $object, $action); // Note that $action and $object may have been modified by hook
$sql .= $hookmanager->resPrint;
$sql = preg_replace('/,\s*$/', '', $sql);

$sqlfields = $sql; // $sql fields to remove for count total

$sql .= " FROM ".$db->prefix().$object->table_element." as t";
//$sql .= " LEFT JOIN ".$db->prefix()."anothertable as rc ON rc.parent = t.rowid";
if (isset($extrafields->attributes[$object->table_element]['label']) && is_array($extrafields->attributes[$object->table_element]['label']) && count($extrafields->attributes[$object->table_element]['label'])) {
	$sql .= " LEFT JOIN ".$db->prefix().$object->table_element."_extrafields as ef on (t.rowid = ef.fk_object)";
}
// Add table from hooks
$parameters = array();
$reshook = $hookmanager->executeHooks('printFieldListFrom', $parameters, $object, $action); // Note that $action and $object may have been modified by hook
$sql .= $hookmanager->resPrint;

if (!empty($object->ismultientitymanaged) && (int) $object->ismultientitymanaged == 1) {
	$sql .= " WHERE t.entity IN (".getEntity($object->element, (GETPOSTINT('search_current_entity') ? 0 : 1)).")";
} elseif (preg_match('/^\w+@\w+$/', (string) $object->ismultientitymanaged)) {
	$tmparray = explode('@', (string) $object->ismultientitymanaged);
	$sql .= " LEFT JOIN ".$object->db->prefix().$tmparray[1]." as pt ON t.".$db->sanitize($tmparray[0])." = pt.rowid";
	$sql .= " WHERE pt.entity IN (".getEntity($object->element, (GETPOSTINT('search_current_entity') ? 0 : 1)).")";
} else {
	$sql .= " WHERE 1 = 1";
}
foreach ($search as $key => $val) {
	if (array_key_exists($key, $object->fields)) {
		if ($key == 'status' && $search[$key] == -1) {
			continue;
		}
		$field_spec = $object->fields[$key];
		if ($field_spec === null) {
			continue;
		}
		$mode_search = (($object->isInt($field_spec) || $object->isFloat($field_spec)) ? 1 : 0);
		if ((strpos($field_spec['type'], 'integer:') === 0) || (strpos($field_spec['type'], 'sellist:') === 0) || !empty($field_spec['arrayofkeyval'])) {
			if ($search[$key] == '-1' || ($search[$key] === '0' && (empty($field_spec['arrayofkeyval']) || !array_key_exists('0', $field_spec['arrayofkeyval'])))) {
				$search[$key] = '';
			}
			$mode_search = 2;
		}
		if ($field_spec['type'] === 'boolean') {
			$mode_search = 1;
			if ($search[$key] == '-1') {
				$search[$key] = '';
			}
		}
		if (empty($field_spec['searchmulti'])) {
			if (!is_array($search[$key]) && $search[$key] != '') {
				$sql .= natural_search("t.".$db->escape($key), $search[$key], (($key == 'status') ? 2 : $mode_search));
			}
		} else {
			if (is_array($search[$key]) && !empty($search[$key])) {
				$sql .= natural_search("t.".$db->escape($key), implode(',', $search[$key]), (($key == 'status') ? 2 : $mode_search));
			}
		}
	} else {
		if (preg_match('/(_dtstart|_dtend)$/', $key) && $search[$key] != '') {
			$columnName = preg_replace('/(_dtstart|_dtend)$/', '', $key);
			if (preg_match('/^(date|timestamp|datetime)/', $object->fields[$columnName]['type'])) {
				if (preg_match('/_dtstart$/', $key)) {
					$sql .= " AND t.".$db->sanitize($columnName)." >= '".$db->idate($search[$key])."'";
				}
				if (preg_match('/_dtend$/', $key)) {
					$sql .= " AND t.".$db->sanitize($columnName)." <= '".$db->idate($search[$key])."'";
				}
			}
		}
	}
}
if ($search_all) {
	$sql .= natural_search(array_keys($fieldstosearchall), $search_all);
}
/*
// If the internal user must only see his customers, force searching by him
$search_sale = 0;
if (!$user->hasRight('societe', 'client', 'voir')) {
	$search_sale = $user->id;
}
// Search on sale representative
if ($search_sale && $search_sale != '-1') {
	if ($search_sale == -2) {
		$sql .= " AND NOT EXISTS (SELECT sc.fk_soc FROM ".$db->prefix()."societe_commerciaux as sc WHERE sc.fk_soc = t.fk_soc)";
	} elseif ($search_sale > 0) {
		$sql .= " AND EXISTS (SELECT sc.fk_soc FROM ".$db->prefix()."societe_commerciaux as sc WHERE sc.fk_soc = t.fk_soc AND sc.fk_user = ".((int) $search_sale).")";
	}
}
// Search on socid
if ($socid) {
	$sql .= " AND t.fk_soc = ".((int) $socid);
}
*/
//$sql.= dolSqlDateFilter("t.field", $search_xxxday, $search_xxxmonth, $search_xxxyear);
// Add where from extra fields
include DOL_DOCUMENT_ROOT.'/core/tpl/extrafields_list_search_sql.tpl.php';
// Add where from hooks
$parameters = array();
$reshook = $hookmanager->executeHooks('printFieldListWhere', $parameters, $object, $action); // Note that $action and $object may have been modified by hook
$sql .= $hookmanager->resPrint;

/* If a group by is required
$sql .= " GROUP BY ";
foreach($object->fields as $key => $val) {
	$sql .= "t.".$db->sanitize($key).", ";
}
// Add fields from extrafields
if (!empty($extrafields->attributes[$object->table_element]['label'])) {
	foreach ($extrafields->attributes[$object->table_element]['label'] as $key => $val) {
		$sql .= ($extrafields->attributes[$object->table_element]['type'][$key] != 'separate' ? "ef.".$key.', ' : '');
	}
}
// Add groupby from hooks
$parameters = array();
$reshook = $hookmanager->executeHooks('printFieldListGroupBy', $parameters, $object, $action); // Note that $action and $object may have been modified by hook
if (empty($reshook)) {
	$sql .= $hookmanager->resPrint;
} else {
	$sql = $hookmanager->resPrint;
}

$sql = preg_replace('/,\s*$/', '', $sql);
*/

// Add HAVING from hooks
/*
$parameters = array();
$reshook = $hookmanager->executeHooks('printFieldListHaving', $parameters, $object, $action); // Note that $action and $object may have been modified by hook
if (empty($reshook)) {
	$sql .= empty($hookmanager->resPrint) ? "" : " HAVING 1=1 ".$hookmanager->resPrint;
} else {
	$sql = $hookmanager->resPrint;
}
*/

// Count total nb of records
$nbtotalofrecords = '';
if (!getDolGlobalInt('MAIN_DISABLE_FULL_SCANLIST')) {
	/* The fast and low memory method to get and count full list converts the sql into a sql count */
	$sqlforcount = preg_replace('/^'.preg_quote($sqlfields, '/').'/', 'SELECT COUNT(*) as nbtotalofrecords', $sql);
	$sqlforcount = preg_replace('/GROUP BY .*$/', '', $sqlforcount);

	$resql = $db->query($sqlforcount);
	if ($resql) {
		$objforcount = $db->fetch_object($resql);
		$nbtotalofrecords = $objforcount->nbtotalofrecords;
	} else {
		dol_print_error($db);
	}

	if (($page * $limit) > (int) $nbtotalofrecords) {	// if total resultset is smaller than the paging size (filtering), goto and load page 0
		$page = 0;
		$offset = 0;
	}
	$db->free($resql);
}

// Complete request and execute it with limit
$sql .= $db->order($sortfield, $sortorder);
if ($limit) {
	$sql .= $db->plimit($limit + 1, $offset);
}

$resql = $db->query($sql);
if (!$resql) {
	dol_print_error($db);
	exit;
}

$num = $db->num_rows($resql);


// Direct jump if only one record found
if ($num == 1 && getDolGlobalInt('MAIN_SEARCH_DIRECT_OPEN_IF_ONLY_ONE') && $search_all && !$page) {
	$obj = $db->fetch_object($resql);
	$id = $obj->rowid;
	header("Location: ".dol_buildpath('/pdpconnectfr/document_card.php', 1).'?id='.((int) $id));
	exit;
}


// Output page
// --------------------------------------------------------------------

llxHeader('', $title, $help_url, '', 0, 0, $morejs, $morecss, '', 'mod-pdpconnectfr page-list bodyforlist');	// Can use also classforhorizontalscrolloftabs instead of bodyforlist for a horizontal scroll in the table instead of page

// Example : Adding jquery code
// print '<script type="text/javascript">
// jQuery(document).ready(function() {
// 	function init_myfunc()
// 	{
// 		jQuery("#myid").removeAttr(\'disabled\');
// 		jQuery("#myid").attr(\'disabled\',\'disabled\');
// 	}
// 	init_myfunc();
// 	jQuery("#mybutton").click(function() {
// 		init_myfunc();
// 	});
// });
// </script>';

$arrayofselected = is_array($toselect) ? $toselect : array();

$param = '';
if (!empty($mode)) {
	$param .= '&mode='.urlencode($mode);
}
if (!empty($contextpage) && $contextpage != $_SERVER["PHP_SELF"]) {
	$param .= '&contextpage='.urlencode($contextpage);
}
if ($limit > 0 && $limit != $conf->liste_limit) {
	$param .= '&limit='.((int) $limit);
}
if ($optioncss != '') {
	$param .= '&optioncss='.urlencode($optioncss);
}
if ($groupby != '') {
	$param .= '&groupby='.urlencode($groupby);
}
foreach ($search as $key => $val) {
	if (is_array($search[$key])) {
		foreach ($search[$key] as $skey) {
			if ($skey != '') {
				$param .= '&search_'.$key.'[]='.urlencode($skey);
			}
		}
	} elseif (preg_match('/(_dtstart|_dtend)$/', $key) && !empty($val)) {
		$param .= '&search_'.$key.'month='.GETPOSTINT('search_'.$key.'month');
		$param .= '&search_'.$key.'day='.GETPOSTINT('search_'.$key.'day');
		$param .= '&search_'.$key.'year='.GETPOSTINT('search_'.$key.'year');
	} elseif ($search[$key] != '') {
		$param .= '&search_'.$key.'='.urlencode($search[$key]);
	}
}
// Add $param from extra fields
include DOL_DOCUMENT_ROOT.'/core/tpl/extrafields_list_search_param.tpl.php';
// Add $param from hooks
$parameters = array('param' => &$param);
$reshook = $hookmanager->executeHooks('printFieldListSearchParam', $parameters, $object, $action); // Note that $action and $object may have been modified by hook
$param .= $hookmanager->resPrint;

// List of mass actions available
$arrayofmassactions = array(
	//'validate'=>img_picto('', 'check', 'class="pictofixedwidth"').$langs->trans("Validate"),
	//'generate_doc'=>img_picto('', 'pdf', 'class="pictofixedwidth"').$langs->trans("ReGeneratePDF"),
	//'builddoc'=>img_picto('', 'pdf', 'class="pictofixedwidth"').$langs->trans("PDFMerge"),
	//'presend'=>img_picto('', 'email', 'class="pictofixedwidth"').$langs->trans("SendByMail"),
);
if (!empty($permissiontodelete)) {
	$arrayofmassactions['predelete'] = img_picto('', 'delete', 'class="pictofixedwidth"').$langs->trans("Delete");
}
if (GETPOSTINT('nomassaction') || in_array($massaction, array('presend', 'predelete'))) {
	$arrayofmassactions = array();
}
$massactionbutton = $form->selectMassAction('', $arrayofmassactions);

print '<form method="POST" id="searchFormList" action="'.$_SERVER["PHP_SELF"].'">'."\n";
if ($optioncss != '') {
	print '<input type="hidden" name="optioncss" value="'.$optioncss.'">';
}
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="formfilteraction" id="formfilteraction" value="list">';
print '<input type="hidden" name="action" value="list">';
print '<input type="hidden" name="sortfield" value="'.$sortfield.'">';
print '<input type="hidden" name="sortorder" value="'.$sortorder.'">';
print '<input type="hidden" name="page" value="'.$page.'">';
print '<input type="hidden" name="contextpage" value="'.$contextpage.'">';
print '<input type="hidden" name="page_y" value="">';
print '<input type="hidden" name="mode" value="'.$mode.'">';


$newcardbutton = '';
//$newcardbutton .= dolGetButtonTitle($langs->trans('ViewList'), '', 'fa fa-bars imgforviewmode', $_SERVER["PHP_SELF"].'?mode=common'.preg_replace('/(&|\?)*(mode|groupby)=[^&]+/', '', $param), '', ((empty($mode) || $mode == 'common') ? 2 : 1), array('morecss' => 'reposition'));
//$newcardbutton .= dolGetButtonTitle($langs->trans('ViewKanban'), '', 'fa fa-th-list imgforviewmode', $_SERVER["PHP_SELF"].'?mode=kanban'.preg_replace('/(&|\?)*(mode|groupby)=[^&]+/', '', $param), '', ($mode == 'kanban' ? 2 : 1), array('morecss' => 'reposition'));
//$newcardbutton .= dolGetButtonTitle($langs->trans('ViewKanbanGroupBy'), '', 'fa fa-grip-vertical imgforviewmode', $_SERVER["PHP_SELF"].'?mode=kanbangroupby&groupby=p.fk_opp_status'.preg_replace('/(&|\?)*(mode|groupby)=[^&]+/', '', $param), '', ($mode == 'kanbangroupby' ? 2 : 1), array('morecss' => 'reposition'));
//$newcardbutton .= dolGetButtonTitle($langs->trans('HierarchicView'), '', 'fa fa-stream paddingleft imgforviewmode', $_SERVER["PHP_SELF"].'?mode=hierarchy'.preg_replace('/(&|\?)*(mode|groupby)=[^&]+/', '', $param), '', (($mode == 'hierarchy') ? 2 : 1), array('morecss' => 'reposition'));
//$newcardbutton .= dolGetButtonTitleSeparator();
//$newcardbutton .= dolGetButtonTitle($langs->trans('New'), '', 'fa fa-plus-circle', dol_buildpath('/pdpconnectfr/document_card.php', 1).'?action=create&backtopage='.urlencode($_SERVER['PHP_SELF']), '', $permissiontoadd);

if ($provider) {
	$title = $langs->trans("EInvoiceSynchronizationHelp", $provider->providerName);
}

print_barre_liste($title, $page, $_SERVER["PHP_SELF"], $param, $sortfield, $sortorder, $massactionbutton, $num, $nbtotalofrecords, $object->picto, 0, $newcardbutton, '', $limit, 0, 0, 1);



// Add code for pre mass action (confirmation or email presend form)
$topicmail = "SendDocumentRef";
$modelmail = "document";
$objecttmp = new Document($db);
$trackid = 'xxxx'.$object->id;
include DOL_DOCUMENT_ROOT.'/core/tpl/massactions_pre.tpl.php';

if ($search_all) {
	$setupstring = '';
	// @phan-suppress-next-line PhanEmptyForeach
	foreach ($fieldstosearchall as $key => $val) {
		$fieldstosearchall[$key] = $langs->trans($val);
		$setupstring .= $key."=".$val.";";
	}
	print '<!-- Search done like if MYOBJECT_QUICKSEARCH_ON_FIELDS = '.$setupstring.' -->'."\n";
	print '<div class="divsearchfieldfilter">'.$langs->trans("FilterOnInto", $search_all).implode(', ', $fieldstosearchall).'</div>'."\n";
}

$moreforfilter = '';
/*$moreforfilter.='<div class="divsearchfield">';
$moreforfilter.= $langs->trans('MyFilter') . ': <input type="text" name="search_myfield" value="'.dol_escape_htmltag($search_myfield).'">';
$moreforfilter.= '</div>';*/

$parameters = array();
$reshook = $hookmanager->executeHooks('printFieldPreListTitle', $parameters, $object, $action); // Note that $action and $object may have been modified by hook
if (empty($reshook)) {
	$moreforfilter .= $hookmanager->resPrint;
} else {
	$moreforfilter = $hookmanager->resPrint;
}
$parameters = array(
	'arrayfields' => &$arrayfields,
);

if (!empty($moreforfilter)) {
	print '<div class="liste_titre liste_titre_bydiv centpercent">';
	print $moreforfilter;
	print '</div>';
}

$varpage = empty($contextpage) ? $_SERVER["PHP_SELF"] : $contextpage;
//$varpage = '';
$htmlofselectarray = $form->multiSelectArrayWithCheckbox('selectedfields', $arrayfields, $varpage, $conf->main_checkbox_left_column);  // This also change content of $arrayfields with user setup
$selectedfields = (($mode != 'kanban' && $mode != 'kanbangroupby') ? $htmlofselectarray : '');
$selectedfields .= (count($arrayofmassactions) ? $form->showCheckAddButtons('checkforselect', 1) : '');



// Last flow sync info
$last_sync = 0;
if (GETPOSTDATE('last_sync_datetime', 'getpost', 'tzuserrel')) {
	$last_sync = GETPOSTDATE('last_sync_datetime', 'getpost', 'tzuserrel');
}
$last_sync_info = '<span class="opacitylow">'.img_picto('', 'long-arrow-alt-right', 'class="pictofixedwidth"');

$Lastsyncinfosql = "SELECT flow_id, updatedat
FROM ".MAIN_DB_PREFIX."pdpconnectfr_document
WHERE provider = '".$db->escape($provider->providerName)."'
ORDER BY updatedat DESC
LIMIT 1";

$resLastsyncinfosql = $db->query($Lastsyncinfosql);
if ($resLastsyncinfosql) {
	$obj = $db->fetch_object($resLastsyncinfosql);
	if ($obj) {
		$last_sync = $db->jdate($obj->updatedat);
		$last_sync_info .= " ". $langs->trans("lastSyncedFlow") . ': ' . $obj->flow_id . ' &nbsp; &nbsp; '.img_picto('', 'calendar').' ' . dol_print_date($last_sync, 'dayhour', 'tzuserrel');
	} else {
		$last_sync = 0;
		$last_sync_info = $langs->trans("NoSyncYet");
	}
} else {
	$last_sync_info = $langs->trans("ErrorGettingLastSyncInfo");
}
$last_sync_info .= '</span>';

// Last supplier invoice that could not be processed by the system
$last_supplier_invoice_error = '';
$filePath = $conf->pdpconnectfr->dir_temp . '/facturx.pdf';
if (file_exists($filePath)) {
	$urlOriginalFile = DOL_URL_ROOT . '/document.php?modulepart=pdpconnectfr&file=' . urlencode('temp/facturx.pdf');
	$urlConvertedFile = DOL_URL_ROOT . '/document.php?modulepart=pdpconnectfr&file=' . urlencode('temp/facturx_readable.pdf');


	$last_supplier_invoice_error = '<span class="opacitylow">'.img_picto('', 'times', 'class="pictofixedwidth" style="color:red;"');
	$last_supplier_invoice_error .= ' ' . $langs->trans("LastSupplierInvoiceCouldNotBeProcessed");
	$last_supplier_invoice_error .= '<i class="fas fa-info-circle em088 opacityhigh classfortooltip" title="'. $langs->trans("LastSupplierInvoiceCouldNotBeProcessedInfo") .'"></i>';
	$last_supplier_invoice_error .= ' : </span>';
	$last_supplier_invoice_error .= '<a href="'.$urlOriginalFile.'">' . $langs->trans("facturXDownloadOriginal") . ' ' . img_picto('', 'download', 'class="pictofixedwidth"') . '</a>';
	$last_supplier_invoice_error .= ' <span class="opacitylow">|</span> ';
	$last_supplier_invoice_error .= '<a href="'.$urlConvertedFile.'">' . $langs->trans("facturXDownloadConverted") . ' ' . img_picto('', 'download', 'class="pictofixedwidth"') . '</a>';
}


// Form for sync action
if ($provider) {
	print '<!-- Form to make the Sync -->'."\n";
	print '<div class="fichecenter">'."\n";

	print '<div class="formconsumeproduce" style="padding: 10px;">'."\n";

	print '<div class="div-table-responsive">'; // You can use div-table-responsive-no-min if you don't need reserved height for your table
	print '<table>'."\n";

	if ($last_sync > 0) {
		// Apply a lookback if configured
		if (getDolGlobalInt('PDPCONNECTFR_SYNC_MARGIN_TIME_HOURS') && !GETPOSTDATE('last_sync_datetime', 'getpost', 'tzuserrel')) {
			$last_sync -= (getDolGlobalInt('PDPCONNECTFR_SYNC_MARGIN_TIME_HOURS') * 3600);
		}
		print '<tr>';
		print '<td class="syncFormLabel">'.$langs->trans("StartSynchronizationFrom").'</td>';
		print '<td>';

		$gmtdatetosuggest = $last_sync;
		if ($syncfromdate && $syncfromdate > $last_sync) {
			$gmtdatetosuggest = $syncfromdate;
		}

		print $form->selectDate($gmtdatetosuggest, 'last_sync_datetime', 1, 1, 1, '', 1, 0, 0, '', '', '', '', 1, '', $langs->trans('From'), 'tzuserrel');

		print '</td>';

		$rowspan = getDolGlobalInt('PDPCONNECTFR_FLOWS_SYNC_CALL_LIMIT') ? 2 : 1;
		print '<td style="padding-left: 40px; padding-right: 40px"'.($rowspan > 1 ? ' rowspan="'.$rowspan.'"' : '').'>';

		// Button to submit
		print '<a href="#" id="runSyncBtn" class="butAction small" style="margin: 0;">'.img_picto('', 'refresh', 'class="pictofixedwidth"').' '.$langs->trans("RUN_SYNC").'</a>'."\n";

		print '</td>';
		print '</tr>';
	}

	if (getDolGlobalInt('PDPCONNECTFR_FLOWS_SYNC_CALL_LIMIT')) {
		print '<tr>';
		print '<td class="syncFormLabel">'.$langs->trans("maxNumberToProcess").'</td>';
		print '<td>';
		print '<input type="number" id="maxflows" name="maxflows" class="maxwidth75 right" min="1" step="1" value="'.(GETPOSTINT('maxflows') ? GETPOSTINT('maxflows') : getDolGlobalInt('PDPCONNECTFR_FLOWS_SYNC_CALL_SIZE', 100)).'" required> ';
		if ($conf->browser->layout != 'phone') {
			print '<span class="opacitymedium ">'.$langs->trans("maxNumberToProcessHelp").'</span>';
		}
		print '</td>';
		print '</tr>';
	}

	print '</table>'."\n";
	print '</div>';


	print '<hr class="margintoponly paddingbottom">';

	print '<div class="floatleft margintoponly">'.$last_sync_info.'</div>'."\n";

	if ($last_supplier_invoice_error) {
		print '<div class="floatleft margintoponly">'.$last_supplier_invoice_error.'</div>'."\n";
	}

	print "</div>"."\n";

	print '</div>'."\n";

	print '<script>'."\n";
	print "document.getElementById('runSyncBtn').addEventListener('click', function(e){"."\n";
	print "  e.preventDefault();"."\n";
	print "  var maxFlows = document.getElementById('maxflows') ? encodeURIComponent(document.getElementById('maxflows').value) : '0';"."\n";
	print "  var token = encodeURIComponent('".newToken()."');"."\n";
	print "  var lastSyncDatetimehour = document.getElementById('last_sync_datetimehour') ? encodeURIComponent(document.getElementById('last_sync_datetimehour').value) : '0';"."\n";
	print "  var lastSyncDatetimemin = document.getElementById('last_sync_datetimemin') ? encodeURIComponent(document.getElementById('last_sync_datetimemin').value) : '0';"."\n";
	print "  var lastSyncDatetimemonth = document.getElementById('last_sync_datetimemonth') ? encodeURIComponent(document.getElementById('last_sync_datetimemonth').value) : '0';"."\n";
	print "  var lastSyncDatetimeday = document.getElementById('last_sync_datetimeday') ? encodeURIComponent(document.getElementById('last_sync_datetimeday').value) : '0';"."\n";
	print "  var lastSyncDatetimeyear = document.getElementById('last_sync_datetimeyear') ? encodeURIComponent(document.getElementById('last_sync_datetimeyear').value) : '0';"."\n";
	print "  window.location.href = '".$_SERVER["PHP_SELF"]."?action=sync&maxflows=' + maxFlows + '&last_sync_datetimehour=' + lastSyncDatetimehour + '&last_sync_datetimemin=' + lastSyncDatetimemin + '&last_sync_datetimemonth=' + lastSyncDatetimemonth + '&last_sync_datetimeday=' + lastSyncDatetimeday + '&last_sync_datetimeyear=' + lastSyncDatetimeyear + '&token=' + token;"."\n";
	print "});"."\n";
	print '</script>'."\n";
} else {
	// Message to check module configuration
	print info_admin($langs->transnoentities("checkPdpConnectFrModuleConfiguration"), 0, 0, '1', '', '', 'warning');
}


// Confirmation dialog
if ($action == 'sync' && $provider) {
	$validationFormError = 0;

	if ($maxflows < 0) {
		$validationFormError++;
		setEventMessages($langs->trans("InvalidSyncLimit"), array(), 'errors');
	}
	if ($syncfromdate === '') {
		$validationFormError++;
		setEventMessages($langs->trans("InvalidSyncFromDate"), array(), 'errors');
	}
	if ($syncfromdate > $provider->getLastSyncDate()) {
		$validationFormError++;
		setEventMessages($langs->trans("SyncFromDateIsAfterLastSyncDate"), array(), 'errors');
	}

	if ($validationFormError == 0) {
		$confirmurl = $_SERVER["PHP_SELF"] . '?';
		$confirmurl .= 'maxflows='.$maxflows;
		$confirmurl .= '&syncfromdate='.urlencode($syncfromdate);
		$confirmurl .= '&token='.newToken();

		//print $confirmurl;
		$formconfirm = $form->formconfirm($confirmurl, $langs->trans("ConfirmSyncTitle"), $langs->trans("ConfirmSyncDesc"), 'confirm_sync', '', '', 1);
		print $formconfirm;
	}
}

// Sync results
if ($action == 'confirm_sync' && getDolGlobalString('PDPCONNECTFR_PDP') && $confirm == 'yes' && !empty($sync_result)) {
	if (isset($provider)) {

		$cssclass = ($sync_result['res'] > 0) ? 'info' : 'error';

		$syncerrortype = '';		// '', 'business' or 'technical'
		if ($sync_result['res'] <= 0) {
			if (empty($sync_result['actions'])) {
				$syncerrortype = 'technical';
				$cssclass = 'error';
			} else {
				$syncerrortype = 'business';
				$cssclass = 'warning';
			}
		} else {
			if ($sync_result['totalFlows'] > $sync_result['batchlimit'] && empty($sync_result['syncedFlows']) && !empty($sync_result['alreadyExist'])) {
				$cssclass = 'warning';
				$sync_result['actions'][] = $langs->trans("TryToIncreaseStartDateOrMax", $langs->transnoentitiesnoconv("StartSynchronizationFrom"), $langs->transnoentitiesnoconv("maxNumberToProcess"));
			} else {
				$cssclass = 'info';
			}
		}

		// Show sync summary result (to show in popup)
		print '<div class="wordbreak '.$cssclass.' clearboth">';
		print '<strong><u>'.$langs->trans("SyncResults").'</u></strong></br>';
		print implode("<br>", $sync_result['messages']);
		if (getDolGlobalInt('PDPCONNECTFR_DEBUG_MODE') && !empty($sync_result['details'])) {
			print '<br><br><small>'.$langs->trans("TechnicalInformation").' : '.implode("<br>", $sync_result['details']).'</small>';
		}
		print '</div>';

		// Suggested action after sync failed (to show under the form)
		if ($sync_result['actions']) {
			print '<div class="wordbreak warning clearboth">';
			print '<strong><u>'.$langs->trans("SuggestedActions").'</u></strong></br>';
			print implode("<br>", $sync_result['actions']);
			print '</div>';
		}

		// If error but no suggested action and debug mode is off, show a message to ask to enable debug mode
		if ($sync_result['res'] < 0 && empty($sync_result['actions']) && !getDolGlobalInt('PDPCONNECTFR_DEBUG_MODE')) {
			print '<div class="wordbreak warning clearboth">';
			print '<strong><u>'.$langs->trans("SuggestedActions").' :</u></strong></br>';
			print $langs->trans("EnableDebugModeToSeeMoreDetails");
			print '</div>';
		}
		print '<br>';
	}
}


print '<br class="clearboth">';


// List of flows sync

print '<div class="div-table-responsive">'; // You can use div-table-responsive-no-min if you don't need reserved height for your table
print '<table class="tagtable nobottomiftotal noborder liste'.($moreforfilter ? " listwithfilterbefore" : "").'">'."\n";

// Fields title search
// --------------------------------------------------------------------
print '<tr class="liste_titre_filter">';
// Action column
if ($conf->main_checkbox_left_column) {
	print '<td class="liste_titre center maxwidthsearch">';
	$searchpicto = $form->showFilterButtons('left');
	print $searchpicto;
	print '</td>';
}
foreach ($object->fields as $key => $val) {
	//$searchkey = empty($search[$key]) ? '' : $search[$key];
	$cssforfield = (empty($val['csslist']) ? (empty($val['css']) ? '' : $val['css']) : $val['csslist']);
	if ($key == 'status') {
		$cssforfield .= ($cssforfield ? ' ' : '').'center';
	} elseif (in_array($val['type'], array('date', 'datetime', 'timestamp'))) {
		$cssforfield .= ($cssforfield ? ' ' : '').'center';
	} elseif (in_array($val['type'], array('timestamp'))) {
		$cssforfield .= ($cssforfield ? ' ' : '').'nowrap';
	} elseif (in_array($val['type'], array('double(24,8)', 'double(6,3)', 'integer', 'real', 'price')) && !in_array($key, array('id', 'rowid', 'ref', 'status')) && $val['label'] != 'TechnicalID' && empty($val['arrayofkeyval'])) {
		$cssforfield .= ($cssforfield ? ' ' : '').'right';
	}
	if (!empty($arrayfields['t.'.$key]['checked'])) {
		print '<td class="liste_titre'.($cssforfield ? ' '.$cssforfield : '').($key == 'status' ? ' parentonrightofpage' : '').'">';
		if (!empty($val['arrayofkeyval']) && is_array($val['arrayofkeyval'])) {
			if (empty($val['searchmulti'])) {
				print $form->selectarray('search_'.$key, $val['arrayofkeyval'], (isset($search[$key]) ? $search[$key] : ''), 1, 0, 0, '', 1, 0, 0, '', 'maxwidth100'.($key == 'status' ? ' search_status width100 onrightofpage' : ''), 1);
			} else {
				print $form->multiselectarray('search_'.$key, $val['arrayofkeyval'], (isset($search[$key]) ? $search[$key] : ''), 0, 0, 'maxwidth100'.($key == 'status' ? ' search_status width100 onrightofpage' : ''), 1);
			}
		} elseif ((strpos($val['type'], 'integer:') === 0) || (strpos($val['type'], 'sellist:') === 0)) {
			print $object->showInputField($val, $key, (isset($search[$key]) ? $search[$key] : ''), '', '', 'search_', $cssforfield.' maxwidth250', 1);
		} elseif (preg_match('/^(date|timestamp|datetime)/', $val['type'])) {
			print '<div class="nowrap">';
			print $form->selectDate($search[$key.'_dtstart'] ? $search[$key.'_dtstart'] : '', "search_".$key."_dtstart", 0, 0, 1, '', 1, 0, 0, '', '', '', '', 1, '', $langs->trans('From'));
			print '</div>';
			print '<div class="nowrap">';
			print $form->selectDate($search[$key.'_dtend'] ? $search[$key.'_dtend'] : '', "search_".$key."_dtend", 0, 0, 1, '', 1, 0, 0, '', '', '', '', 1, '', $langs->trans('to'));
			print '</div>';
		} elseif ($key == 'lang') {
			require_once DOL_DOCUMENT_ROOT.'/core/class/html.formadmin.class.php';
			$formadmin = new FormAdmin($db);
			print $formadmin->select_language((isset($search[$key]) ? $search[$key] : ''), 'search_lang', 0, array(), 1, 0, 0, 'minwidth100imp maxwidth125', 2);
		} elseif ($val['type'] === 'boolean') {
			print $form->selectyesno('search_' . $key, $search[$key] ?? '', 1, false, 1);
		} else {
			if ($val['notsearchable']) {
				continue;
			}
			print '<input type="text" class="flat maxwidth'.(in_array($val['type'], array('integer', 'price')) ? '50' : '75').'" name="search_'.$key.'" value="'.dol_escape_htmltag(isset($search[$key]) ? $search[$key] : '').'">';
		}
		print '</td>';
	}
}
// Extra fields
include DOL_DOCUMENT_ROOT.'/core/tpl/extrafields_list_search_input.tpl.php';

// Fields from hook
$parameters = array('arrayfields' => $arrayfields);
$reshook = $hookmanager->executeHooks('printFieldListOption', $parameters, $object, $action); // Note that $action and $object may have been modified by hook
print $hookmanager->resPrint;
/*if (!empty($arrayfields['anotherfield']['checked'])) {
	print '<td class="liste_titre"></td>';
}*/
// Action column
if (!$conf->main_checkbox_left_column) {
	print '<td class="liste_titre center maxwidthsearch">';
	$searchpicto = $form->showFilterButtons();
	print $searchpicto;
	print '</td>';
}
print '</tr>'."\n";

$totalarray = array();
$totalarray['nbfield'] = 0;

// Fields title label
// --------------------------------------------------------------------
print '<tr class="liste_titre">';
// Action column
if ($conf->main_checkbox_left_column) {
	print getTitleFieldOfList($selectedfields, 0, $_SERVER["PHP_SELF"], '', '', '', '', $sortfield, $sortorder, 'center maxwidthsearch ')."\n";
	$totalarray['nbfield']++;
}
foreach ($object->fields as $key => $val) {
	$cssforfield = (empty($val['csslist']) ? (empty($val['css']) ? '' : $val['css']) : $val['csslist']);
	if ($key == 'status') {
		$cssforfield .= ($cssforfield ? ' ' : '').'center';
	} elseif (in_array($val['type'], array('date', 'datetime', 'timestamp'))) {
		$cssforfield .= ($cssforfield ? ' ' : '').'center';
	} elseif (in_array($val['type'], array('timestamp'))) {
		$cssforfield .= ($cssforfield ? ' ' : '').'nowrap';
	} elseif (in_array($val['type'], array('double(24,8)', 'double(6,3)', 'integer', 'real', 'price')) && !in_array($key, array('id', 'rowid', 'ref', 'status')) && $val['label'] != 'TechnicalID' && empty($val['arrayofkeyval'])) {
		$cssforfield .= ($cssforfield ? ' ' : '').'right';
	}
	$cssforfield = preg_replace('/small\s*/', '', $cssforfield);	// the 'small' css must not be used for the title label
	if (!empty($arrayfields['t.'.$key]['checked'])) {
		print getTitleFieldOfList($arrayfields['t.'.$key]['label'], 0, $_SERVER['PHP_SELF'], 't.'.$key, '', $param, ($cssforfield ? 'class="'.$cssforfield.'"' : ''), $sortfield, $sortorder, ($cssforfield ? $cssforfield.' ' : ''), 0, (empty($val['helplist']) ? '' : $val['helplist']))."\n";
		$totalarray['nbfield']++;
	}
}
// Extra fields
include DOL_DOCUMENT_ROOT.'/core/tpl/extrafields_list_search_title.tpl.php';
// Hook fields
$parameters = array('arrayfields' => $arrayfields, 'param' => $param, 'sortfield' => $sortfield, 'sortorder' => $sortorder, 'totalarray' => &$totalarray);
$reshook = $hookmanager->executeHooks('printFieldListTitle', $parameters, $object, $action); // Note that $action and $object may have been modified by hook
print $hookmanager->resPrint;
/*if (!empty($arrayfields['anotherfield']['checked'])) {
	print '<th class="liste_titre">'.$langs->trans("AnotherField").'</th>';
	$totalarray['nbfield']++;
}*/
// Action column
if (!$conf->main_checkbox_left_column) {
	print getTitleFieldOfList($selectedfields, 0, $_SERVER["PHP_SELF"], '', '', '', '', $sortfield, $sortorder, 'center maxwidthsearch ')."\n";
	$totalarray['nbfield']++;
}
print '</tr>'."\n";

// Detect if we need a fetch on each output line
$needToFetchEachLine = 0;
if (isset($extrafields->attributes[$object->table_element]['computed']) && is_array($extrafields->attributes[$object->table_element]['computed']) && count($extrafields->attributes[$object->table_element]['computed']) > 0) {
	foreach ($extrafields->attributes[$object->table_element]['computed'] as $key => $val) {
		if (!is_null($val) && preg_match('/\$object/', $val)) {
			$needToFetchEachLine++; // There is at least one compute field that use $object
		}
	}
}


// Loop on record
// --------------------------------------------------------------------
$i = 0;
$savnbfield = $totalarray['nbfield'];
$totalarray = array();
$totalarray['nbfield'] = 0;
$imaxinloop = ($limit ? min($num, $limit) : $num);
while ($i < $imaxinloop) {
	$obj = $db->fetch_object($resql);
	if (empty($obj)) {
		break; // Should not happen
	}

	// Store properties in $object
	$object->setVarsFromFetchObj($obj);

	// Set value of additional properties, For example, to compute a field from others
	$object->recap = '';
	if ($object->ack_status) {
		$object->recap .= 'Ack: '.$object->ack_status.'<br>';
	}
	if ($object->ack_reason_code) {
		$object->recap .= 'Reason: '.$object->ack_reason_code.'<br>';
	}
	if ($object->ack_info) {
		$object->recap .= 'Info: '.$object->ack_info.'<br>';
	}
	if ($object->cdar_lifecycle_code) {
		$object->recap .= 'Lifecycle Code: '.$object->cdar_lifecycle_code.'<br>';
	}
	if ($object->cdar_lifecycle_label) {
		$object->recap .= 'Lifecycle Label: '.$object->cdar_lifecycle_label.'<br>';
	}
	if ($object->cdar_reason_code) {
		$object->recap .= 'CDAR Reason Code: '.$object->cdar_reason_code.'<br>';
	}
	if ($object->cdar_reason_description) {
		$object->recap .= 'CDAR Reason Description: '.$object->cdar_reason_description.'<br>';
	}
	if ($object->cdar_reason_detail) {
		$object->recap .= 'CDAR Reason Detail: '.$object->cdar_reason_detail.'<br>';
	}

	/*
	$object->thirdparty = null;
	if ($obj->fk_soc > 0) {
		if (!empty($conf->cache['thirdparty'][$obj->fk_soc])) {
			$companyobj = $conf->cache['thirdparty'][$obj->fk_soc];
		} else {
			$companyobj = new Societe($db);
			$companyobj->fetch($obj->fk_soc);
			$conf->cache['thirdparty'][$obj->fk_soc] = $companyobj;
		}

		$object->thirdparty = $companyobj;
	}*/

	if ($mode == 'kanban' || $mode == 'kanbangroupby') {
		if ($i == 0) {
			print '<tr class="trkanban"><td colspan="'.$savnbfield.'">';
			print '<div class="box-flex-container kanban">';
		}
		// Output Kanban
		$selected = -1;
		if ($massactionbutton || $massaction) { // If we are in select mode (massactionbutton defined) or if we have already selected and sent an action ($massaction) defined
			$selected = 0;
			if (in_array($object->id, $arrayofselected)) {
				$selected = 1;
			}
		}
		//print $object->getKanbanView('', array('thirdparty'=>$object->thirdparty, 'selected' => $selected));
		print $object->getKanbanView('', array('selected' => $selected));
		if ($i == ($imaxinloop - 1)) {
			print '</div>';
			print '</td></tr>';
		}
	} else {
		// Show line of result
		$j = 0;
		print '<tr data-rowid="'.$object->id.'" class="oddeven row-with-select">';

		// Action column
		if ($conf->main_checkbox_left_column) {
			print '<td class="nowrap center">';
			if ($massactionbutton || $massaction) { // If we are in select mode (massactionbutton defined) or if we have already selected and sent an action ($massaction) defined
				$selected = 0;
				if (in_array($object->id, $arrayofselected)) {
					$selected = 1;
				}
				print '<input id="cb'.$object->id.'" class="flat checkforselect" type="checkbox" name="toselect[]" value="'.$object->id.'"'.($selected ? ' checked="checked"' : '').'>';
			}
			print '</td>';
			if (!$i) {
				$totalarray['nbfield']++;
			}
		}
		// Fields
		foreach ($object->fields as $key => $val) {
			$cssforfield = (empty($val['csslist']) ? (empty($val['css']) ? '' : $val['css']) : $val['csslist']);
			if (in_array($val['type'], array('date', 'datetime', 'timestamp'))) {
				$cssforfield .= ($cssforfield ? ' ' : '').'center';
			} elseif ($key == 'status') {
				$cssforfield .= ($cssforfield ? ' ' : '').'center';
			}

			if (in_array($val['type'], array('timestamp'))) {
				$cssforfield .= ($cssforfield ? ' ' : '').'nowraponall';
			} elseif ($key == 'ref') {
				$cssforfield .= ($cssforfield ? ' ' : '').'nowraponall';
			}

			if (in_array($val['type'], array('double(24,8)', 'double(6,3)', 'integer', 'real', 'price')) && !in_array($key, array('id', 'rowid', 'ref', 'status')) && empty($val['arrayofkeyval'])) {
				$cssforfield .= ($cssforfield ? ' ' : '').'right';
			}
			//if (in_array($key, array('fk_soc', 'fk_user', 'fk_warehouse'))) $cssforfield = 'tdoverflowmax100';

			if (!empty($arrayfields['t.'.$key]['checked'])) {
				print '<td'.($cssforfield ? ' class="'.$cssforfield.((preg_match('/tdoverflow/', $cssforfield) && !in_array($val['type'], array('ip', 'url')) && !is_numeric($object->$key)) ? ' classfortooltip' : '').'"' : '');
				if (preg_match('/tdoverflow/', $cssforfield) && !in_array($val['type'], array('ip', 'url')) && !is_numeric($object->$key) && !in_array($key, array('ref'))) {
					print ' title="'.dol_escape_htmltag((string) $object->$key).'"';
				}
				print '>';
				if ($key == 'status') {
					print $object->getLibStatut(5);
				} elseif ($key == 'rowid') {
					print $object->showOutputField($val, $key, (string) $object->id, '');
				} else {
					if ($val['type'] == 'html' || $val['type'] == 'text') {
						print '<div class="small minwidth150 lineheightsmall threelinesmax-normallineheight classfortooltip" title="'.dolPrintHTMLForAttribute((string) $object->$key).'">';
					}
					print $object->showOutputField($val, $key, (string) $object->$key, '');
					if ($val['type'] == 'html' || $val['type'] == 'text') {
						print '</div>';
					}
				}
				print '</td>';
				if (!$i) {
					$totalarray['nbfield']++;
				}
				if (!empty($val['isameasure']) && $val['isameasure'] == 1) {
					if (!$i) {
						$totalarray['pos'][$totalarray['nbfield']] = 't.'.$key;
					}
					if (!isset($totalarray['val'])) {
						$totalarray['val'] = array();
					}
					if (!isset($totalarray['val']['t.'.$key])) {
						$totalarray['val']['t.'.$key] = 0;
					}
					$totalarray['val']['t.'.$key] += $object->$key;
				}
			}
		}
		// Extra fields
		include DOL_DOCUMENT_ROOT.'/core/tpl/extrafields_list_print_fields.tpl.php';
		// Fields from hook
		$parameters = array('arrayfields' => $arrayfields, 'object' => $object, 'obj' => $obj, 'i' => $i, 'totalarray' => &$totalarray);
		$reshook = $hookmanager->executeHooks('printFieldListValue', $parameters, $object, $action); // Note that $action and $object may have been modified by hook
		print $hookmanager->resPrint;

		/*if (!empty($arrayfields['anotherfield']['checked'])) {
			print '<td class="right">'.$obj->anotherfield.'</td>';
		}*/

		// Action column
		if (empty($conf->main_checkbox_left_column)) {
			print '<td class="nowrap center">';
			if ($massactionbutton || $massaction) { // If we are in select mode (massactionbutton defined) or if we have already selected and sent an action ($massaction) defined
				$selected = 0;
				if (in_array($object->id, $arrayofselected)) {
					$selected = 1;
				}
				print '<input id="cb'.$object->id.'" class="flat checkforselect" type="checkbox" name="toselect[]" value="'.$object->id.'"'.($selected ? ' checked="checked"' : '').'>';
			}
			print '</td>';
			if (!$i) {
				$totalarray['nbfield']++;
			}
		}

		print '</tr>'."\n";
	}

	$i++;
}

// Show total line
include DOL_DOCUMENT_ROOT.'/core/tpl/list_print_total.tpl.php';

// If no record found
if ($num == 0) {
	$colspan = 1;
	foreach ($arrayfields as $key => $val) {
		if (!empty($val['checked'])) {
			$colspan++;
		}
	}
	print '<tr><td colspan="'.$colspan.'"><span class="opacitymedium">'.$langs->trans("NoRecordFound").'</span></td></tr>';
}


$db->free($resql);

$parameters = array('arrayfields' => $arrayfields, 'sql' => $sql);
$reshook = $hookmanager->executeHooks('printFieldListFooter', $parameters, $object, $action); // Note that $action and $object may have been modified by hook
print $hookmanager->resPrint;

print '</table>'."\n";
print '</div>'."\n";

print '</form>'."\n";

if (in_array('builddoc', array_keys($arrayofmassactions)) && ($nbtotalofrecords === '' || $nbtotalofrecords)) {
	$hidegeneratedfilelistifempty = 1;
	if ($massaction == 'builddoc' || $action == 'remove_file' || $show_files) {
		$hidegeneratedfilelistifempty = 0;
	}

	require_once DOL_DOCUMENT_ROOT.'/core/class/html.formfile.class.php';
	$formfile = new FormFile($db);

	// Show list of available documents
	$urlsource = $_SERVER['PHP_SELF'].'?sortfield='.$sortfield.'&sortorder='.$sortorder;
	$urlsource .= str_replace('&amp;', '&', $param);

	$filedir = $diroutputmassaction;
	$genallowed = $permissiontoread;
	$delallowed = $permissiontoadd;

	print $formfile->showdocuments('massfilesarea_'.$object->module, '', $filedir, $urlsource, 0, $delallowed, '', 1, 1, 0, 48, 1, $param, $title, '', '', '', null, $hidegeneratedfilelistifempty);
}

// End of page
llxFooter();
$db->close();
