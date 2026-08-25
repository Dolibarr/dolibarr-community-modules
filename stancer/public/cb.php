<?php
/* Copyright (C) 2001-2002	Rodolphe Quiedeville	<rodolphe@quiedeville.org>
 * Copyright (C) 2006-2013	Laurent Destailleur		<eldy@users.sourceforge.net>
 * Copyright (C) 2023		Eric Seigne				<eric.seigne@cap-rel.fr>
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
 *     	\file       public/cb.php
 *		\ingroup    core
 *		\brief      File to show page for customer to enter CB data
 */

if (!defined('NOLOGIN')) {
	define("NOLOGIN", 1); // This means this output page does not require to be logged.
}
if (!defined('NOCSRFCHECK')) {
	define("NOCSRFCHECK", 1); // We accept to go on this page from external web site.
}
if (!defined('NOIPCHECK')) {
	define('NOIPCHECK', '1'); // Do not check IP defined into conf $dolibarr_main_restrict_ip
}
if (!defined('NOBROWSERNOTIF')) {
	define('NOBROWSERNOTIF', '1');
}

// For MultiCompany module.
// Do not use GETPOST here, function is not defined and define must be done before including main.inc.php
$entity = (!empty($_GET['e']) ? (int) $_GET['e'] : (!empty($_POST['e']) ? (int) $_POST['e'] : 1));
if (is_numeric($entity)) {
	define("DOLENTITY", $entity);
}
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
dol_include_once('/stancer/lib/stancer.lib.php');
require_once DOL_DOCUMENT_ROOT.'/core/lib/company.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/payments.lib.php';

global $dolibarr_main_instance_unique_id, $conf;

if (getDolGlobalString('STANCER_PUBLIC_CB_PAGE', '') == '') {
	dol_syslog("stancer CB public page is disabled by config !", LOG_ERR);
	accessforbidden($langs->trans('StancerPublicCBPageDisabled'), 0, 0, 1);
}

if (getDolGlobalString('STANCER_ENABLE_CB', '') == '') {
	dol_syslog("stancer CB is disabled by config !", LOG_ERR);
	accessforbidden($langs->trans('StancerPublicCBPageDisabled'), 0, 0, 1);
}

$langs->loadLangs(array("main", "other", "dict", "bills", "companies", "paybox", "paypal","stancer@stancer"));

// Clean parameters
parse_str(base64_decode(GETPOST('s', 'alpha')), $args);
// dol_syslog("stancer sepa-iban (args) is " . json_encode($args), LOG_DEBUG);

$tst = '';
if (isset($args['socid'])) {
	$societe = new Societe($db);
	$societe->fetch($args['socid']);
	$tst = "CB-" . $args['socid'] . "-" . $societe->name . "-" . getDolGlobalString('PAYMENT_SECURITY_TOKEN');
	$_SESSION['cb'] = 'new';
	$_SESSION['s'] = GETPOST('s', 'alpha');
} else {
	print "<p class=''>" . $langs->transnoentitiesnoconv("StancerThereIsNoSocID") . "</p>";
	exit;
}
// dol_syslog("stancer check securekey is from $tst");
// dol_syslog("stancer securekey is " . json_encode($args['securekey'] . " compare with " . dol_hash($tst,'1')), LOG_DEBUG);
if (dol_hash($tst, '1') != $args['securekey']) {
	dol_syslog("stancer CB form error, securekey no confirmed !", LOG_ERR);
	accessforbidden($langs->trans('StancerIBANCBinpoutSecurekeyError'), 0, 0, 1);
}

if (isset($_SESSION['cb']) && $_SESSION['cb'] == 'done') {
	$errmsg = 'stancer detect page reload';
	dol_syslog($errmsg);
	print "<p class=''>" . $langs->transnoentitiesnoconv("StancerPublicIBANCBPageReload", dol_print_url($mysoc->url, '_blank', 0, 1)) . "</p>";
	exit;
}

// dol_syslog("stancer s=" . base64_encode("socid=15&action=stancerSEPA&securekey=".dol_hash($tst,'1')));
// dol_syslog("stancer securekey=" . dol_hash($tst,'1'));
$ro = '';
$disabled = '';
$success = '';
$signLink = $signer = '';

//note : public page, user is not set, so we use user who create that company
$user = new User($db);
$res = $user->fetch(getDolGlobalInt('STANCER_USER_ACCOUNT_FOR_ACTIONS'));
if ($res < 0) {
	// Fallback on the user who last modified the thirdparty, then on its creator.
	// Societe::fetch() fills user_modification/user_creation up to Dolibarr 18, and
	// user_modification_id/user_creation_id from Dolibarr 19, so the four spellings
	// are probed, modification before creation. The *_id properties are not declared
	// at all on Dolibarr 15, so they are only ever reached through empty(): a direct
	// read would raise a PHP 8 "Undefined property" warning on that version.
	$uid = 0;
	if (!empty($societe->user_modification_id)) {
		$uid = $societe->user_modification_id;
	}
	if (empty($uid)) {
		// @phan-suppress-next-line PhanDeprecatedProperty  only source up to Dolibarr 18
		$uid = $societe->user_modification;
	}
	if (empty($uid) && !empty($societe->user_creation_id)) {
		$uid = $societe->user_creation_id;
	}
	if (empty($uid)) {
		// @phan-suppress-next-line PhanDeprecatedProperty  only source up to Dolibarr 18
		$uid = $societe->user_creation;
	}
	if (empty($uid)) {
		dol_syslog("stancer cb.php: no fallback user found on thirdparty ".$societe->id, LOG_ERR);
	} elseif ($user->fetch((int) $uid) <= 0) {
		dol_syslog("stancer cb.php: cannot load fallback user ".((int) $uid)." for thirdparty ".$societe->id.": ".$user->error, LOG_ERR);
	}
}
// loadRights() only exists from Dolibarr 20; getrights() is the only call valid on 15..21.
// @phan-suppress-next-line PhanDeprecatedFunction
$user->getrights();
$cbccv = $cbname = $cbnumber = $cbexpiry = '';

// $sp = new Stancer_payments($db);
$action = (string) GETPOST('action', 'aZ09');
if ($action == "stancerGetCustomerCB") {
	$cbnumber = (string) GETPOST('cbnumber', 'alpha');
	$cbexpiry = (string) GETPOST('cbexpiry', 'alpha');
	$cbt = explode('/', $cbexpiry);
	$cbexp_month = $cbt[0];
	$cbexp_year =$cbt[1];
	$cbccv = (string) GETPOST('cbccv', 'alpha');
	$cbname = (string) GETPOST('cbname', 'alpha');
	$db->begin();

	// print "<p>Saisie CB : number=$cbnumber, expiry=$cbexp_month / $cbexp_year, cbname=$cbname, cbexp_month=$cbexp_month, cbexp_year=$cbexp_year, cbccv=$cbccv </p>";

	dol_syslog("stancer cb.php call stancerAddCustomerIfNeeded ...");
	$stancerID = stancerAddCustomerIfNeeded($societe);

	dol_syslog("stancer cb.php returned from stancerAddCustomerIfNeeded ...");

	$data = [
		'cbnumber'   	=> $cbnumber,
		'cbname' 		=> $cbname,
		'cbexp_month' 	=> $cbexp_month,
		'cbexp_year'	=> $cbexp_year,
		'cbccv'			=> $cbccv
	];
	$cbID = stancerAddCBIfNeeded((int) $args['socid'], $data);
	dol_syslog("stancer cb.php call stancerAddCBIfNeeded ...");
	if (substr($cbID, 0, 4) == "card") {
		$success = 1;
		$db->commit();

		if (getDolGlobalString('STANCER_AUTO_MAIL_NOTIFICATIONS_ON_NEW_CB_AND_SEPA', '') != '') {
			stancerSendMail(getDolGlobalString('STANCER_AUTO_MAIL_NOTIFICATIONS_PAYMENT', ''), $langs->trans('StancerMailSubjectCB'), $langs->trans('StancerMailCB', $societe->name), false, '', 'thi' . $societe->id);
		}
	}
	dol_syslog("stancer cb.php returned from stancerAddCBIfNeeded ...");
} elseif ($action == 'preauth') {
	// 3DS pre-authentication return. The card itself is stored by the
	// 'stancerGetCustomerCB' action above, so there is nothing to persist here:
	// we only trace the callback instead of failing silently.
	$preauthPayment = (string) GETPOST('payment', 'alpha');
	dol_syslog("stancer cb.php: 3DS pre-auth return for payment '".$preauthPayment."', nothing to process at this step", LOG_DEBUG);
}
$reg = array();
$object = new stdClass(); // For triggers
$error = 0;

/*
 * Actions
 */



/*
 * View
 */

$now = dol_now();

$head = '';
if (getDolGlobalString('ONLINE_PAYMENT_CSS_URL', '') != '') {
	$head = '<link rel="stylesheet" type="text/css" href="' . getDolGlobalString('ONLINE_PAYMENT_CSS_URL').'?lang='.$langs->defaultlang.'">'."\n";
}

$arrayofcss =  array(
	'/stancer/public/css/tailwind.min.css',
);

$conf->dol_hide_topmenu = 1;
$conf->dol_hide_leftmenu = 1;

$replacemainarea = (empty($conf->dol_hide_leftmenu) ? '<div>' : '').'<div>';
llxHeader($head, $langs->trans("StancerPageTitleForCB"), '', '', 0, 0, '', $arrayofcss, '', 'onlinepaymentbody', $replacemainarea);


// Show message
print '<div id="dolpaymentdiv" class="center">'."\n";

$logo = $mysoc->logo;
$urllogofull = '';
// A payment page specific logo first, the company logo otherwise
if (getDolGlobalString('MAIN_IMAGE_PUBLIC_PAYMENT', '') != '') {
	$urllogofull = getDolGlobalString('MAIN_IMAGE_PUBLIC_PAYMENT');
} elseif (!empty($logo) && is_readable($conf->mycompany->dir_output.'/logos/'.$logo)) {
	$urllogofull = $dolibarr_main_url_root.'/viewimage.php?modulepart=mycompany&entity='.$conf->entity.'&file='.urlencode('logos/'.$logo);
}


if (!empty($conf->stancer->enabled)) {
	print '<form id="stancerGetCB" class="center" name="stancerGetCB" action="'.$_SERVER["PHP_SELF"].'" method="POST">'."\n";
	print '<input type="hidden" name="token" value="'.newToken().'">'."\n";
	print '<input type="hidden" name="action" value="stancerGetCustomerCB">'."\n";
	print '<input type="hidden" name="s" value="'.GETPOST("s", 'alpha').'">'."\n";
	print '<input type="hidden" name="e" value="'.$entity.'" />'; ?>

	<?php if ($success) { ?>
<section class="text-gray-600 body-font">
	<div class="container mx-auto flex px-5 md:flex-row flex-col items-center">
		<div class="lg:max-w-lg lg:w-full md:w-1/2 w-5/6 mb-10 md:mb-0">
			<svg class="h-64 w-64 text-green-400" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">
				<path stroke="none" d="M0 0h24v24H0z" />
				<polyline points="9 11 12 14 20 6" />
				<path d="M20 12v6a2 2 0 0 1 -2 2h-12a2 2 0 0 1 -2 -2v-12a2 2 0 0 1 2 -2h9" />
			</svg>
		</div>
		<div class="lg:flex-grow md:w-1/2 lg:pl-24 md:pl-16 flex flex-col md:items-start md:text-left items-center text-center">
			<h1 class="title-font sm:text-4xl text-3xl mb-4 font-medium text-gray-900">
					<?php echo $langs->trans("StancerPleaseEnterYourIBANorCBTitleDone"); ?>
			</h1>
			<p class="mb-4">
					<?php echo $langs->trans("StancerPleaseEnterYourCBDone"); ?>
			</p>
		</div>
	</div>
</section>

	<?php } else { ?>
<section class="text-gray-600 body-font">
	<h1 class="title-font sm:text-4xl text-3xl mb-4 font-medium text-gray-900">
		<?php echo $langs->trans("StancerPleaseEnterYourCBTitle"); ?>
	</h1>
	<p class="mb-4">
		<?php echo $langs->trans("StancerPleaseEnterYourCB"); ?>
	</p>
	<div class="container mx-auto flex px-5 md:flex-row flex-col items-center">
		<div class="lg:max-w-lg lg:w-full md:w-1/2 mb-10 md:mb-0">
			<img class="object-cover object-center rounded" alt="<?php echo dol_escape_htmltag($mysoc->name);?>" src="<?php echo $urllogofull; ?>">
			<p class="text-sm mt-2 text-gray-500 mb-8 w-full justify">
					<?php echo $langs->trans('CBLegalTextStancer2', dol_escape_htmltag($mysoc->name), dol_escape_htmltag($mysoc->name)); ?>
			</p>
		</div>
		<div class="lg:flex-grow md:w-1/2 xl:pt-24 lg:pl-24 md:pl-16 flex flex-col md:items-start md:text-left items-center text-center">
			<div class="flex w-full justify-end items-end">
				<div class="xl:w-8/12 md:w-10/12 w-full text-left relative mr-4">
					<label for="StancerCustomerName" class="leading-7 text-sm text-gray-600">
							<?php echo $langs->trans('StancerCustomerName'); ?>
					</label>
					<input type="text" id="StancerCustomerName" name="StancerCustomerName" value="<?php echo dol_escape_htmltag($societe->name); ?>" disabled class="w-full bg-gray-100 bg-opacity-50 rounded border border-gray-300 focus:ring-2 focus:ring-indigo-200 focus:bg-transparent focus:border-indigo-500 text-base outline-none text-gray-700 py-1 px-3 leading-8 transition-colors duration-200 ease-in-out">
				</div>
			</div>
			<div class="flex w-full justify-end items-end">
				<div class="xl:w-8/12 md:w-10/12 w-full text-left relative mr-4">
					<label for="cbname" class="leading-7 text-sm text-gray-600">
							<?php echo $langs->trans('StancerHolderName'); ?>
					</label>
					<input type="text" id="cbname" name="cbname" placeholder="Jean DUPONT" value="<?php echo dol_escape_htmltag($cbname); ?>" <?php echo $ro; ?> class="w-full bg-gray-100 bg-opacity-50 rounded border border-gray-300 focus:ring-2 focus:ring-indigo-200 focus:bg-transparent focus:border-indigo-500 text-base outline-none text-gray-700 py-1 px-3 leading-8 transition-colors duration-200 ease-in-out">
				</div>
			</div>
			<div class="flex w-full justify-end items-end">
				<div class="xl:w-8/12 md:w-10/12 w-full text-left relative mr-4">
					<label for="cbnumber" class="leading-7 text-sm text-gray-600">
							<?php echo $langs->trans('StancerCBNumber'); ?>
					</label>
					<input type="text" id="cbnumber" name="cbnumber" value="<?php echo dol_escape_htmltag($cbnumber);?>" <?php echo $ro; ?> placeholder="____ ____ ____ ____" data-slots="_" data-accept="\d" size="19" class="w-full bg-gray-100 bg-opacity-50 rounded border border-gray-300 focus:ring-2 focus:ring-indigo-200 focus:bg-transparent focus:border-indigo-500 text-base outline-none text-gray-700 py-1 px-3 leading-8 transition-colors duration-200 ease-in-out pl-8"> <i class="absolute left-2 top-[20px] text-gray-400 text-sm fa fa-credit-card" style="position: absolute;left: 10px;top: 38px;"></i>
				</div>
			</div>
			<div class="flex w-full justify-end items-end flex-wrap">
				<div class="xl:w-8/12 md:w-8/12 w-full text-left relative mr-4 pr-6 max-w-xs">
					<div class="mt-8 flex gap-5 ">
						<div class="input_text relative w-full">
							<input type="text" id="cbexpiry" name="cbexpiry" value="<?php echo dol_escape_htmltag($cbexpiry);?>" class="h-12 pl-7 outline-none px-2 focus:border-blue-900 transition-all w-full border-b " placeholder="mm/yyyy" data-slots="my" />
							<span class="absolute left-0 text-sm -top-4"><?php echo $langs->trans('StancerCBExpiry'); ?></span> <i class="absolute left-2 top-4 text-gray-400 fa fa-calendar-o"></i>
						</div>
						<div class="input_text relative w-full">
							<input type="text" id="cbccv" name="cbccv" value="<?php echo dol_escape_htmltag($cbccv);?>"  class="h-12 pl-7 outline-none px-2 focus:border-blue-900 transition-all w-full border-b " placeholder="___" data-slots="_" data-accept="\d" size="3" />
							<span class="absolute left-0 text-sm -top-4"><?php echo $langs->trans('StancerCBCCV'); ?></span> <i class="absolute left-2 top-4 text-gray-400 fa fa-lock"></i>
						</div>
					</div>
				</div>
			</div>
			<div class="flex w-full justify-end items-end flex-wrap">
				<div class="xl:w-3/12 md:w-5/12 w-full text-right relative mt-4 mr-4 max-w-xs">
					<button <?php echo $disabled; ?> class="inline-flex text-white bg-indigo-500 border-0 py-2 px-6 focus:outline-none hover:bg-indigo-600 rounded text-lg">
							<?php echo $langs->trans('Save'); ?>
					</button>
				</div>
			</div>
		</div>
	</div>
</section>
<script language="javascript">
	document.addEventListener('DOMContentLoaded', () => {
		for (const el of document.querySelectorAll("[placeholder][data-slots]")) {
			const pattern = el.getAttribute("placeholder"),
				slots = new Set(el.dataset.slots || "_"),
				prev = (j => Array.from(pattern, (c, i) => slots.has(c) ? j = i + 1 : j))(0),
				first = [...pattern].findIndex(c => slots.has(c)),
				accept = new RegExp(el.dataset.accept || "\\d", "g"),
				clean = input => {
					input = input.match(accept) || [];
					return Array.from(pattern, c =>
						input[0] === c || slots.has(c) ? input.shift() || c : c
					);
				},
				format = () => {
					const [i, j] = [el.selectionStart, el.selectionEnd].map(i => {
						i = clean(el.value.slice(0, i)).findIndex(c => slots.has(c));
						console.log("input" + i);
						return i < 0 ? prev[prev.length - 1] : back ? prev[i - 1] || first : i;
					}); el.value = clean(el.value).join``; el.setSelectionRange(i, j); back = false;
				}; let back = false; el.addEventListener("keydown", (e) => back = e.key === "Backspace");
			el.addEventListener("input", format);
			el.addEventListener("focus", format);
			el.addEventListener("blur", () => el.value === pattern && (el.value = ""));
		}
	});

	var pay = document.querySelector(".pay");
	var allinputs = document.querySelectorAll(".input_text input")
	pay.addEventListener('click', function () {
		allinputs.forEach((e) => {
			e.classList.remove('warning');
			if (e.value.length < 1) {
				e.classList.add('warning');
			}
		});
	});

	allinputs.forEach((all) => {
		all.addEventListener('keyup', function () {
			if (all.value.length < 1) {
				all.classList.add('warning');
			}
			else {
				all.classList.remove('warning');
			}
		});
	});
</script>
	<?php } ?>

	<?php
	print '</form>'."\n";
}
// If data not provided from back url, search them into the session env.
// $ipaddress is never set earlier in this script: read it straight from the session,
// and default to an empty string when the session does not carry it either.
$ipaddress = isset($_SESSION['ipaddress']) ? $_SESSION['ipaddress'] : '';

print "\n</div>\n";

print '<script src="'.dol_buildpath('/stancer/js/stancer_submit_once.js', 1).'"></script>'."\n";

if (((int) DOL_VERSION) < 18) {
	// Renamed into htmlPrintOnlineFooter() in Dolibarr 18, kept for older versions
	// @phpstan-ignore-next-line
	// @phan-suppress-next-line PhanUndeclaredFunction
	htmlPrintOnlinePaymentFooter($mysoc, $langs);
} else {
	// @phpstan-ignore-next-line
	htmlPrintOnlineFooter($mysoc, $langs);
}


llxFooter('', 'public');

$db->close();
