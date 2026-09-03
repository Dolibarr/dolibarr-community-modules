<?php
/* Copyright (C) 2025 CAP-REL <contact@cap-rel.fr>
 * Copyright (C) 2026		MDW		<mdeweerd@users.noreply.github.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

if (!defined('NOREQUIRESOC')) {
	define('NOREQUIRESOC', '1');
}
if (!defined('NOCSRFCHECK')) {
	define('NOCSRFCHECK', 1);
}
if (!defined('NOTOKENRENEWAL')) {
	define('NOTOKENRENEWAL', 1);
}
if (!defined('NOLOGIN')) {
	define('NOLOGIN', 1);
}
if (!defined('NOREQUIREHTML')) {
	define('NOREQUIREHTML', 1);
}
if (!defined('NOREQUIREAJAX')) {
	define('NOREQUIREAJAX', '1');
}

// Load Dolibarr environment
$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME']; $tmp2 = realpath(__FILE__); $i = strlen($tmp) - 1; $j = strlen($tmp2) - 1;  // @phan-suppress-current-line DolibarrForbiddenFunctionPlugin
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
	$i--; $j--;
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

require_once DOL_DOCUMENT_ROOT.'/core/lib/functions2.lib.php';

// Define css type
header('Content-type: text/css');
if (empty($dolibarr_nocache)) {
	header('Cache-Control: max-age=3600, public, must-revalidate');
} else {
	header('Cache-Control: no-cache');
}

?>

/* ============================================
   ABOUT PAGE - SUPPORT LAYOUT
   ============================================ */

.support-page {
	max-width: 1200px;
	margin: 20px auto;
	padding: 20px;
}

.support-header {
	text-align: center;
	margin-bottom: 40px;
}

.support-header h1 {
	font-size: 2em;
	color: #333;
	margin: 0;
}

.support-content {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 30px;
}

.support-box {
	background: #fff;
	border: 1px solid #ddd;
	border-radius: 8px;
	padding: 25px;
	margin-bottom: 20px;
}

.support-box h2 {
	margin-top: 0;
	color: #333;
	font-size: 1.5em;
}

.support-box h3 {
	margin-top: 0;
	color: #555;
	font-size: 1.2em;
}

.support-intro {
	color: #666;
	line-height: 1.6;
	margin-bottom: 20px;
}

/* Star Rating */
.rating-container {
	margin: 25px 0;
}

.star-rating {
	display: flex;
	flex-direction: row-reverse;
	justify-content: flex-end;
	font-size: 3em;
	gap: 5px;
}

.star-rating input {
	display: none;
}

.star-rating label {
	cursor: pointer;
	color: #ddd;
	transition: color 0.2s;
}

.star-rating input:checked ~ label,
.star-rating label:hover,
.star-rating label:hover ~ label {
	color: #ffc107;
}

/* Form */
.form-group {
	margin: 20px 0;
}

.form-group label {
	font-weight: 600;
	margin-bottom: 8px;
	display: block;
}

/* Links box */
.links-box {
	background: #f8f9fa;
}

.support-links {
	list-style: none;
	padding: 0;
	margin: 0;
}

.support-links li {
	padding: 10px 0;
	border-bottom: 1px solid #e0e0e0;
}

.support-links li:last-child {
	border-bottom: none;
}

.support-links a {
	color: #667eea;
	text-decoration: none;
	font-weight: 500;
	transition: color 0.2s;
}

.support-links a:hover {
	color: #764ba2;
}

/* Module info in about page */
.about-module-info {
	background: #f8f9fa;
}

.about-module-info table {
	width: 100%;
	border-collapse: collapse;
}

.about-module-info table td {
	padding: 8px 0;
	border-bottom: 1px solid #e0e0e0;
}

.about-module-info table tr:last-child td {
	border-bottom: none;
}

.about-module-info table td:first-child {
	font-weight: 600;
	color: #555;
	width: 40%;
}

/* Changelog */
.stc-changelog .stc-cl-title { font-size: 1.1em; color: #555; margin: 0 0 10px 0; padding: 0; }
.stc-changelog .stc-cl-h3 { font-size: 1.05em; margin: 15px 0 5px 0; padding: 8px 0 4px 0; border-bottom: 1px solid #ddd; }
.stc-changelog .stc-cl-h4 { font-size: 0.95em; margin: 10px 0 5px 0; padding: 0; color: #333; font-weight: 600; }
.stc-changelog ul { margin: 5px 0 10px 20px; padding: 0; list-style: disc; }
.stc-changelog li { margin: 2px 0; }
.stc-changelog p { margin: 0 0 5px 0; }

/* Responsive */
@media (max-width: 768px) {
	.support-content {
		grid-template-columns: 1fr;
	}
}
