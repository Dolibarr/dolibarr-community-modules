<?php
/* Copyright (C) 2025 	   Pablo Lagrave           <contact@devlandes.com>
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
 * \file    helloasso/css/helloasso.css.php
 * \ingroup helloasso
 * \brief   CSS file for module HelloAsso.
 */

//if (!defined('NOREQUIREUSER')) define('NOREQUIREUSER','1');	// Not disabled because need to load personalized language
//if (!defined('NOREQUIREDB'))   define('NOREQUIREDB','1');	// Not disabled. Language code is found on url.
if (!defined('NOREQUIRESOC')) {
	define('NOREQUIRESOC', '1');
}
//if (!defined('NOREQUIRETRAN')) define('NOREQUIRETRAN','1');	// Not disabled because need to do translations
//if (!defined('NOCSRFCHECK'))   define('NOCSRFCHECK', 1);		// Should be disable only for special situation
if (!defined('NOTOKENRENEWAL')) {
	define('NOTOKENRENEWAL', 1);
}
if (!defined('NOLOGIN')) {
	define('NOLOGIN', 1); // File must be accessed by logon page so without login
}
//if (! defined('NOREQUIREMENU'))   define('NOREQUIREMENU',1);  // We need top menu content
if (!defined('NOREQUIREHTML')) {
	define('NOREQUIREHTML', 1);
}
if (!defined('NOREQUIREAJAX')) {
	define('NOREQUIREAJAX', '1');
}

session_cache_limiter('public');
// false or '' = keep cache instruction added by server
// 'public'  = remove cache instruction added by server
// and if no cache-control added later, a default cache delay (10800) will be added by PHP.

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
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1))."/../main.inc.php")) {
	$res = @include substr($tmp, 0, ($i + 1))."/../main.inc.php";
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

require_once DOL_DOCUMENT_ROOT.'/core/lib/functions2.lib.php';

// Load user to have $user->conf loaded (not done by default here because of NOLOGIN constant defined) and load permission if we need to use them in CSS
/*if (empty($user->id) && !empty($_SESSION['dol_login'])) {
	$user->fetch('',$_SESSION['dol_login']);
	$user->loadRights();
}*/


// Define css type
header('Content-type: text/css');
// Important: Following code is to cache this file to avoid page request by browser at each Dolibarr page access.
// You can use CTRL+F5 to refresh your browser cache.
if (empty($dolibarr_nocache)) {
	header('Cache-Control: max-age=10800, public, must-revalidate');
} else {
	header('Cache-Control: no-cache');
}

?>

div.mainmenu.helloasso::before {
	content: "\f249";
}
div.mainmenu.helloasso {
	background-image: none;
}

.HaPay {
        width: fit-content;
        display: -webkit-box;
        display: -ms-flexbox;
        display: flex;
        flex-direction: column;
        justify-content: center;
        align-items: center;
        -webkit-box-pack: center;
        -ms-flex-pack: center;
      }

      .HaPay * {
        font-family: "Open Sans", "Trebuchet MS", "Lucida Sans Unicode",
          "Lucida Grande", "Lucida Sans", Arial, sans-serif;
        transition: all 0.3s ease-out;
      }

      .HaPayButton {
        align-items: stretch;
        -webkit-box-pack: stretch;
        -ms-flex-pack: stretch;
        background: none;
        border: none;
        display: -webkit-box;
        display: -ms-flexbox;
        display: flex;
        padding: 0;
        border-radius: 8px;
      }

      .HaPayButton:hover {
        cursor: pointer;
      }

      .HaPayButton:not(:disabled):focus {
        box-shadow: 0 0 0 0.25rem rgba(73, 211, 138, 0.25);
        -webkit-box-shadow: 0 0 0 0.25rem rgba(73, 211, 138, 0.25);
      }

      .HaPayButton:not(:disabled):hover .HaPayButtonLabel,
      .HaPayButton:not(:disabled):focus .HaPayButtonLabel {
        background-color: #483dbe;
      }

      .HaPayButton:not(:disabled):hover .HaPayButtonLogo,
      .HaPayButton:not(:disabled):focus .HaPayButtonLogo,
      .HaPayButton:not(:disabled):hover .HaPayButtonLabel,
      .HaPayButton:not(:disabled):focus .HaPayButtonLabel {
        border: 1px solid #483dbe;
      }

      .HaPayButton:disabled {
        cursor: not-allowed;
      }

      .HaPayButton:disabled .HaPayButtonLogo,
      .HaPayButton:disabled .HaPayButtonLabel {
        border: 1px solid #d1d6de;
      }

      .HaPayButtonLogo {
        background-color: #ffffff;
        border: 1px solid #4c40cf;
        border-top-left-radius: 8px;
        border-bottom-left-radius: 8px;
        padding: 10px 16px;
        width: 30px;
      }

      .HaPayButtonLabel {
        align-items: center;
        -webkit-box-pack: center;
        -ms-flex-pack: center;
        justify-content: space-between;
        column-gap: 5px;
        background-color: #4c40cf;
        border: 1px solid #4c40cf;
        border-top-right-radius: 8px;
        border-bottom-right-radius: 8px;
        color: #ffffff;
        font-size: 16px;
        font-weight: 800;
        display: -webkit-box;
        display: -ms-flexbox;
        display: flex;
        padding: 0 16px;
      }

      .HaPayButton:disabled .HaPayButtonLabel {
        background-color: #d1d6de;
        color: #505870;
      }

      .HaPaySecured {
        align-items: center;
        -webkit-box-pack: center;
        -ms-flex-pack: center;
        justify-content: space-between;
        display: -webkit-box;
        display: -ms-flexbox;
        display: flex;
        column-gap: 5px;
        padding: 8px 16px;
        font-size: 12px;
        font-weight: 600;
        color: #2e2f5e;
      }

      .HaPay svg {
        fill: currentColor;
      }
