<?php
/* Copyright (C) 2004-2017 Laurent Destailleur  <eldy@users.sourceforge.net>
 * Copyright (C) 2023-2026 Eric Seigne <eric.seigne@cap-rel.fr>
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
 * \file    stancer/admin/about.php
 * \ingroup stancer
 * \brief   About page of module Stancer.
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

// Libraries
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/functions2.lib.php';
dol_include_once('/stancer/lib/stancer.lib.php');

// Translations
$langs->loadLangs(array("errors", "admin", "install", "stancer@stancer"));

// Access control
if (!$user->admin) {
	accessforbidden();
}

// Parameters
$action = GETPOST('action', 'aZ09');
$backtopage = GETPOST('backtopage', 'alpha');


/*
 * Actions
 */

if ($action == 'send_feedback') {
	$rating = GETPOST('rating', 'int');
	$feedback = GETPOST('feedback', 'restricthtml');
	$email = GETPOST('email', 'email');

	if ($rating > 0) {
		require_once DOL_DOCUMENT_ROOT.'/core/class/CMailFile.class.php';

		$to = 'commercial+stancer@cap-rel.fr';
		// Named $fromEmail and not $from: this value goes into a mail header, it never
		// reaches the database, and the SQL analyzer treats a bare $from as a SQL fragment.
		$fromEmail = !empty($email) ? $email : getDolGlobalString('MAIN_MAIL_EMAIL_FROM');
		$subject = 'Stancer Module Feedback - '.$rating.'/5 stars';

		$message = "New feedback from Stancer module:\n\n";
		$message .= "Rating: ".$rating."/5\n";
		$message .= "User: ".$user->getFullName($langs)." (".$user->login.")\n";
		$message .= "Email: ".$email."\n";
		$message .= "Dolibarr version: ".DOL_VERSION."\n\n";
		$message .= "Feedback:\n".$feedback."\n";

		$mail = new CMailFile($subject, $to, $fromEmail, $message);

		if ($mail->sendfile()) {
			setEventMessage($langs->trans('StancerFeedbackSent'), 'mesgs');
		} else {
			dol_syslog("stancer about.php: failed to send feedback email to ".$to, LOG_ERR);
			setEventMessage($langs->trans('StancerFeedbackSendError'), 'errors');
		}
	} else {
		dol_syslog("stancer about.php: feedback submitted without rating", LOG_WARNING);
	}

	$action = '';
}


/*
 * View
 */

$form = new Form($db);

$help_url = 'https://doc.cap-rel.fr/stancer/';
$page_name = "StancerAbout";

llxHeader('', $langs->trans($page_name), $help_url, '', 0, 0, [], ['/stancer/css/admin.css.php']);

// Subheader
$linkback = '<a href="'.($backtopage ? $backtopage : DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1').'">'.$langs->trans("BackToModuleList").'</a>';

print load_fiche_titre($langs->trans($page_name), $linkback, 'title_setup');

// Configuration header
$head = stancerAdminPrepareHead();
print dol_get_fiche_head($head, 'about', $langs->trans($page_name), 0, 'stancer@stancer');

dol_include_once('/stancer/core/modules/modStancer.class.php');
$tmpmodule = new modStancer($db);

// Module description
print $tmpmodule->getDescLong();
print '<br>';

// Partnership message
print '<div class="info">';
print $langs->trans("StancerPartnershipMessage", $langs->trans("StancerSignupURL"));
print '</div>';

// Support page layout
print '<div class="support-page">';
print '<div class="support-content">';

// -- Left column --
print '<div class="support-column">';

// Module info box
print '<div class="support-box about-module-info">';
print '<h3>'.$langs->trans('StancerAboutModuleInfo').'</h3>';
print '<table>';

// Version
print '<tr>';
print '<td>'.$langs->trans("Version").'</td>';
print '<td>'.dol_escape_htmltag($tmpmodule->version).'</td>';
print '</tr>';

// Publisher
print '<tr>';
print '<td>'.$langs->trans("Publisher").'</td>';
print '<td>';
$url = $tmpmodule->editor_url;
if ($url && strpos($url, '://') === false) {
	$url = 'https://'.$url;
}
if ($url) {
	print '<a href="'.dol_escape_htmltag($url).'" target="_blank" rel="noopener noreferrer">'.dol_escape_htmltag($tmpmodule->editor_name).'</a>';
} else {
	print dol_escape_htmltag($tmpmodule->editor_name);
}
print ' '.$langs->trans("StancerAndDolibarrCommunity");
print '</td>';
print '</tr>';

// License
print '<tr>';
print '<td>'.$langs->trans("License").'</td>';
print '<td>GPL v3+</td>';
print '</tr>';

// Dolibarr min version
$minversion = $tmpmodule->need_dolibarr_version;
if (is_array($minversion) && count($minversion) >= 2) {
	print '<tr>';
	print '<td>'.$langs->trans("StancerDolibarrMinVersion").'</td>';
	print '<td>'.$minversion[0].'.'.$minversion[1].'</td>';
	print '</tr>';
}

// PHP min version
$phpmin = $tmpmodule->phpmin;
if (is_array($phpmin) && count($phpmin) >= 2) {
	print '<tr>';
	print '<td>'.$langs->trans("StancerPHPMinVersion").'</td>';
	print '<td>'.$phpmin[0].'.'.$phpmin[1].'</td>';
	print '</tr>';
}

print '</table>';
print '</div>';

// Feedback box
print '<div class="support-box">';
print '<h2>'.$langs->trans('StancerDoYouLikeModule').'</h2>';
print '<p class="support-intro">'.$langs->trans('StancerFeedbackIntro').'</p>';

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="send_feedback">';

// Star rating
print '<div class="rating-container">';
print '<label>'.$langs->trans('StancerYourRating').':</label><br>';
print '<div class="star-rating">';
for ($i = 5; $i >= 1; $i--) {
	print '<input type="radio" id="star'.$i.'" name="rating" value="'.$i.'" required>';
	print '<label for="star'.$i.'" title="'.$i.'">&#9733;</label>';
}
print '</div>';
print '</div>';

// Feedback text
print '<div class="form-group">';
print '<label for="feedback">'.$langs->trans('StancerYourFeedback').':</label><br>';
print '<textarea name="feedback" id="feedback" rows="6" class="flat minwidth400" placeholder="'.$langs->trans('StancerFeedbackPlaceholder').'"></textarea>';
print '</div>';

// Email
print '<div class="form-group">';
print '<label for="email">'.$langs->trans('StancerYourEmail').' ('.$langs->trans('Optional').'):</label><br>';
print '<input type="email" name="email" id="email" class="flat minwidth300" value="'.dol_escape_htmltag($user->email).'" placeholder="your-email@example.com">';
print '</div>';

print '<button type="submit" class="button">'.$langs->trans('StancerSendFeedback').'</button>';
print '</form>';

print '</div>';

print '</div>'; // End left column

// -- Right column --
print '<div class="support-column">';

// Useful links box
print '<div class="support-box links-box">';
print '<h3>'.$langs->trans('StancerAboutUsefulLinks').'</h3>';
print '<ul class="support-links">';
print '<li><a href="https://doc.cap-rel.fr/stancer/" target="_blank" rel="noopener noreferrer">'.img_picto('', 'fa-book').' '.$langs->trans('StancerOnlineDocumentation').'</a></li>';
print '<li><a href="https://cap-rel.fr/sav-module-dolibarr/" target="_blank" rel="noopener noreferrer">'.img_picto('', 'fa-wrench').' '.$langs->trans('StancerAboutSAV').'</a></li>';
print '<li><a href="https://www.dolibarr.fr/forum" target="_blank" rel="noopener noreferrer">'.img_picto('', 'fa-comments').' '.$langs->trans('StancerAboutForum').'</a></li>';
print '<li><a href="https://cap-rel.fr/contact/" target="_blank" rel="noopener noreferrer">'.img_picto('', 'fa-envelope').' '.$langs->trans('StancerAboutContact').'</a></li>';
print '</ul>';
print '</div>';

print '</div>'; // End right column

print '</div>'; // End support-content
print '</div>'; // End support-page

// Changelog section
$changelog_path = dol_buildpath('/stancer/ChangeLog.md', 0);
if (file_exists($changelog_path)) {
	print '<div class="div-table-responsive-no-min">';
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre">';
	print '<td>'.$langs->trans("ChangeLog").'</td>';
	print '</tr>';
	print '<tr class="oddeven">';
	print '<td class="wordbreak">';
	$changelog_raw = file_get_contents($changelog_path);
	$lines = explode("\n", str_replace("\r\n", "\n", $changelog_raw));
	$html = '';
	$inList = false;
	foreach ($lines as $line) {
		$trimmed = trim($line);
		if ($trimmed === '') {
			if ($inList) {
				$html .= '</ul>';
				$inList = false;
			}
			continue;
		}
		if (strpos($trimmed, '### ') === 0) {
			if ($inList) {
				$html .= '</ul>';
				$inList = false;
			}
			$html .= '<h4 class="stc-cl-h4">' . dol_escape_htmltag(substr($trimmed, 4)) . '</h4>';
		} elseif (strpos($trimmed, '## ') === 0) {
			if ($inList) {
				$html .= '</ul>';
				$inList = false;
			}
			$html .= '<h3 class="stc-cl-h3">' . dol_escape_htmltag(substr($trimmed, 3)) . '</h3>';
		} elseif (strpos($trimmed, '# ') === 0) {
			if ($inList) {
				$html .= '</ul>';
				$inList = false;
			}
			$html .= '<h3 class="stc-cl-title">' . dol_escape_htmltag(substr($trimmed, 2)) . '</h3>';
		} elseif (preg_match('/^\s*-\s+(.+)$/', $trimmed, $m)) {
			if (!$inList) {
				$html .= '<ul>';
				$inList = true;
			}
			$html .= '<li>' . dol_escape_htmltag($m[1]) . '</li>';
		} else {
			if ($inList) {
				$html .= '</ul>';
				$inList = false;
			}
			$html .= '<p>' . dol_escape_htmltag($trimmed) . '</p>';
		}
	}
	if ($inList) {
		$html .= '</ul>';
	}
	print '<div class="stc-changelog">' . $html . '</div>';
	print '</td>';
	print '</tr>';
	print '</table>';
	print '</div>';
}

// Page end
print dol_get_fiche_end();
llxFooter();
$db->close();
