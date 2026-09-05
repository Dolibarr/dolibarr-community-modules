<?php
/* Copyright (C) 2025		SuperAdmin					<daoud.mouhamed@gmail.com>
 * Copyright (C) 2026		Jose Martinez				<jose.martinez@pichinov.com>
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
 * \file    einvoicing/lib/einvoicing.lib.php
 * \ingroup einvoicing
 * \brief   Library files with common functions for EInvoicing
 */

// dolPrintHTMLForAttribute() is a core function of Dolibarr 19 that several files of this module call
// on versions that do not have it. Its backport lives with the other core helpers, in compat/, and is
// loaded from here so every file that already loads this library keeps finding it.
// require_once on a path relative to this file, not dol_include_once: the latter resolves the module
// through dol_buildpath() and, when that resolution fails, only writes a line in the log (issue #565).
require_once __DIR__ . '/../compat/functions.lib.php';

/**
 * Prepare admin pages header
 *
 * @return array<array{string,string,string}>
 */
function einvoicingAdminPrepareHead()
{
	global $langs, $conf;

	// global $db;
	// $extrafields = new ExtraFields($db);
	// $extrafields->fetch_name_optionals_label('myobject');

	$langs->load("einvoicing@einvoicing");

	$h = 0;
	$head = array();

	$head[$h][0] = dol_buildpath("/einvoicing/admin/setup.php", 1);
	$head[$h][1] = $langs->trans("PASettings");
	$head[$h][2] = 'settings';
	$h++;

	$head[$h][0] = dol_buildpath("/einvoicing/admin/setup_options.php", 1);
	$head[$h][1] = $langs->trans("Options");
	$head[$h][2] = 'options';
	$h++;

	/*
	$head[$h][0] = dol_buildpath("/einvoicing/admin/myobject_extrafields.php", 1);
	$head[$h][1] = $langs->trans("ExtraFields");
	$nbExtrafields = (isset($extrafields->attributes['myobject']['label']) && is_countable($extrafields->attributes['myobject']['label'])) ? count($extrafields->attributes['myobject']['label']) : 0;
	if ($nbExtrafields > 0) {
		$head[$h][1] .= '<span class="badge marginleftonlyshort">' . $nbExtrafields . '</span>';
	}
	$head[$h][2] = 'myobject_extrafields';
	$h++;

	$head[$h][0] = dol_buildpath("/einvoicing/admin/myobjectline_extrafields.php", 1);
	$head[$h][1] = $langs->trans("ExtraFieldsLines");
	$nbExtrafields = (isset($extrafields->attributes['myobjectline']['label']) && is_countable($extrafields->attributes['myobjectline']['label'])) ? count($extrafields->attributes['myobject']['label']) : 0;
	if ($nbExtrafields > 0) {
		$head[$h][1] .= '<span class="badge marginleftonlyshort">' . $nbExtrafields . '</span>';
	}
	$head[$h][2] = 'myobject_extrafieldsline';
	$h++;
	*/

	$head[$h][0] = dol_buildpath("/einvoicing/admin/about.php", 1);
	$head[$h][1] = $langs->trans("About");
	$head[$h][2] = 'about';
	$h++;

	if (getDolGlobalInt('EINVOICING_ALLOW_DEVTOOLS')) {
		$head[$h][0] = dol_buildpath("/einvoicing/admin/setup_devtools.php", 1);
		$head[$h][1] = $langs->trans("DevTools");
		$head[$h][2] = 'devtools';
		$h++;
	}

	// Show more tabs from modules
	// Entries must be declared in modules descriptor with line
	//$this->tabs = array(
	//	'entity:+tabname:Title:@einvoicing:/einvoicing/mypage.php?id=__ID__'
	//); // to add new tab
	//$this->tabs = array(
	//	'entity:-tabname:Title:@einvoicing:/einvoicing/mypage.php?id=__ID__'
	//); // to remove a tab
	complete_head_from_modules($conf, $langs, null, $head, $h, 'einvoicing@einvoicing');

	complete_head_from_modules($conf, $langs, null, $head, $h, 'einvoicing@einvoicing', 'remove');

	return $head;
}

/**
 * Show a warning if setup not correct.
 *
 * @param 	EInvoicing $einvoicing	Object EInvoicing
 * @return	string						Return string with warning (or '')
 */
function pdpShowWarning($einvoicing)
{
	global $langs;

	$ret = '';

	if (getDolGlobalString('EINVOICING_LIVE')) {
		$mysocCheck = $einvoicing->validateMyCompanyConfiguration();
		if ($mysocCheck['res'] <= 0) {
			$ret .= '<div class="' . ($mysocCheck['res'] < 0 ? 'error' : 'warning') . '">';
			$ret .= $mysocCheck['message'];
			$ret .= '<br><br>';
			$ret .= $langs->trans("MyCompanyConfigurationWarning") . ': ';
			$ret .= '<a class="gotomycompanysetup" href="' . DOL_URL_ROOT . '/admin/company.php">';
			$ret .= $langs->trans("ModifyCompanyInformation") . '<i class="fas fa-tools marginleftonly"></i>';
			$ret .= '</a>';
			$ret .= '</div>';
		}
	}

	// A Factur-X file is a PDF/A-3 file carrying the XML, and the module can only embed the XML into the
	// PDF the core produced - it cannot repair its pages. Say so here, where the setting that decides it
	// can be reached, and not only when an invoice is generated. Not gated on EINVOICING_LIVE: a test
	// instance producing files no validator accepts is exactly what one wants to know about early.
	$carrierCheck = $einvoicing->validateEInvoiceCarrierConfiguration();
	if ($carrierCheck['res'] <= 0) {
		$ret .= '<div class="warning">';
		$ret .= $langs->trans("FxCheckWarningPdfaCarrierNotEnabled");
		$ret .= '<br><br>';
		$ret .= '<a class="gotopdfsetup" href="' . DOL_URL_ROOT . '/admin/pdf.php">';
		$ret .= $langs->trans("FxGoToPdfSetup") . '<i class="fas fa-tools marginleftonly"></i>';
		$ret .= '</a>';
		$ret .= '</div>';
	}

	// In proxy mode public/proxy_oauthcallback.php answers without authentication and hands the access
	// and refresh tokens to the redirect_uri the caller supplied, so the domains listed in
	// EINVOICING_SUPERPDPVIAPARTNER_ONLY_DOMAIN are the only thing separating a customer instance from
	// anyone else. An empty list is still accepted for one transition step, so warn on both setup pages.
	if (getDolGlobalString('EINVOICING_SUPERPDP_VIAPARTNER') == 'proxy' && !getDolGlobalString('EINVOICING_SUPERPDPVIAPARTNER_ONLY_DOMAIN')) {
		$ret .= '<div class="error">';
		$ret .= img_warning().' <b>'.$langs->trans("ProxyRedirectDomainsNotSet").'</b>';
		$ret .= '<br><br>';
		$ret .= $langs->trans("ProxyRedirectDomainsNotSetDetail");
		$ret .= '<br><br>';
		$ret .= '<a class="gotoothersetup" href="'.DOL_URL_ROOT.'/admin/const.php">';
		$ret .= $langs->trans("ProxyRedirectDomainsGoToOtherSetup").'<i class="fas fa-tools marginleftonly"></i>';
		$ret .= '</a>';
		$ret .= '</div>';
	}

	return ($ret ? $ret . '<br>' : '');
}

/**
 * Extract prof id : it depends on country ...
 *
 * @param 	Societe 	$thirdparty		Dolibarr thirdparty
 * @return 	string 						Return siren or locale prof id
 */
function idprof($thirdparty)
{
	$retour = "";
	switch ($thirdparty->country_code) {
		case 'BE':
			$retour = removeAllSpaces($thirdparty->idprof1);
			break;
		case 'DE':
			if (!empty($thirdparty->idprof6)) {
				$retour = removeAllSpaces($thirdparty->idprof6);
				break;
			} elseif (!empty($thirdparty->idprof2) && !empty($thirdparty->idprof3)) {
				$retour = removeAllSpaces($thirdparty->idprof2 . $thirdparty->idprof3);
			} else {
				$retour = removeAllSpaces($thirdparty->idprof1);
			}
			break;
		case 'FR':
			if (!empty($thirdparty->idprof1)) {
				$retour = removeAllSpaces($thirdparty->idprof1); // SIREN
			} else {
				$retour = substr(removeAllSpaces($thirdparty->idprof2), 0, 9); // SIREN = 9 first chars of the SIRET
			}
			break;
		default:
			$retour = removeAllSpaces($thirdparty->idprof1 ? $thirdparty->idprof1 : $thirdparty->idprof2);
	}

	return $retour;
}

/**
 * Buyer prof id depends on country
 *
 * @param 	CommonObject $object	Object invoice, ...
 * @return 	string 					Prof id
 */
function thirdpartyidprof($object)
{
	$object->fetch_thirdparty();
	$thirdparty = $object->thirdparty;
	return $thirdparty ? idprof($object->thirdparty) : '';
}

/**
 * Remove every space of an identifier, whatever kind of space it is.
 *
 * A value copied from a web page or a PDF often carries a non-breaking (U+00A0), thin or zero-width
 * space, which a '/\s+/' pattern written without the /u modifier does not see. Single place where the
 * module strips them, so the identifier it emits and the one it checks back agree; the deprecated
 * EInvoicing::removeSpaces() delegates here.
 *
 * @param  ?string $str					String to be cleaned. null is accepted and gives ''.
 * @param  ?string $original_encoding	Encoding of $str, null to detect it. The result is given back in that same encoding.
 * @return string						Cleaned up string
 */
function removeAllSpaces($str, $original_encoding = null)
{
	// Tolerate a null identifier (e.g. a party without any professional id): treat it as empty.
	if ($str === null) {
		$str = '';
	}
	$str = (string) $str;
	if ($str === '') {
		return '';
	}

	// mbstring is only recommended by Dolibarr, never required: without it we still strip what the
	// Unicode pattern below can strip, instead of fataling on mb_detect_encoding().
	$hasmbstring = (function_exists('mb_detect_encoding') && function_exists('mb_convert_encoding'));

	// find encoding
	if ($original_encoding === null && $hasmbstring) {
		$original_encoding = mb_detect_encoding($str, mb_detect_order(), true) ?: 'UTF-8';
	}

	// Convert to UTF-8 only when the encoding is known and we are able to convert: everything below works
	// on UTF-8. The encoding to restore is held in its own variable rather than in a boolean, so that it
	// is plainly a string on both conversions below - mb_convert_encoding() takes no null.
	$sourceencoding = ($hasmbstring && $original_encoding !== null && strtoupper($original_encoding) !== 'UTF-8') ? $original_encoding : '';
	if ($sourceencoding !== '') {
		$str = mb_convert_encoding($str, 'UTF-8', $sourceencoding);
	}

	// this transform '&nbsp;', '&ensp;', '&emsp;', '&thinsp;' etc. in real spaces Unicode
	$decoded = html_entity_decode($str, ENT_QUOTES | ENT_HTML5, 'UTF-8');
	// Without ENT_SUBSTITUTE, html_entity_decode() answers '' on a string that is not valid UTF-8.
	// Keep the bytes we were given rather than losing the identifier altogether.
	if ($decoded !== '') {
		$str = $decoded;
	}

	// suppress via Regex
	$cleaned = preg_replace('/[\p{Z}\s\x{200B}-\x{200D}\x{FEFF}]+/u', '', $str);
	if ($cleaned === null) {
		// The /u modifier makes preg_replace() answer null on a string that is not valid UTF-8. Fall
		// back to the ASCII-only strip, so a badly encoded identifier is at worst cleaned the way the
		// deprecated EInvoicing::removeSpaces() used to clean it, and never returned as null.
		$cleaned = preg_replace('/\s+/', '', $str);
	}
	$str = ($cleaned === null ? $str : $cleaned);

	// restore encoding
	if ($sourceencoding !== '') {
		$str = mb_convert_encoding($str, $sourceencoding, 'UTF-8');
	}

	return (string) $str;
}


// Compatibility functions
/**
 * Return the full path of the directory where a module (or an object of a module) stores its files.
 * Path may depends on the entity if a multicompany module is enabled.
 *
 * Core getMultidirOutput() takes the four arguments this module needs only since Dolibarr 20.0.0; on
 * 18 and 19 it accepts ($object, $module) alone, hence the backported fallback body below.
 *
 * @param 	CommonObject|BlockedLog|null	$object 	Dolibarr common object.
 * @param 	string 							$module 	Override object element, for example to use 'mycompany' instead of 'societe'
 * @param	int								$forobject	Return the more complete path for the given object (including ref) instead of for the module only.
 * @param	string							$mode		'output' (full main dir) or 'outputrel' (relative dir) or 'temp' (full dir for temporary files) or 'version' (full dir for archived files)
 * @return 	string|null									The path of the relative directory of the module, ending with /
 */
function getMultidirOutputCompat($object, $module = '', $forobject = 0, $mode = 'output')
{
	global $conf;

	// version_compare() rather than a (float) cast: DOL_VERSION carries development suffixes
	// ('a.b.c-alpha', 'a.b.c-beta', 'a.b.c-rcX', see filefunc.inc.php) that a cast flattens, so
	// (float) '20.0.0-alpha' is exactly 20.0 and would hand a pre-release snapshot over to a core
	// signature that may not be there yet. version_compare() sorts those suffixed versions below
	// 20.0.0 and keeps them on the backport, which is the safe side.
	if (version_compare(DOL_VERSION, '20.0.0', '>=')) {
		return getMultidirOutput($object, $module, $forobject, $mode);
	}

	$subdirectory = '';
	if (!is_object($object) && empty($module)) {
		return null;
	}
	if (empty($module) && !empty($object->element)) {
		$module = $object->element;
	}

	// Special case for backward compatibility
	switch ($module) {
		case 'fichinter':
			$module = 'ficheinter';
			break;
		case 'invoice_supplier':
			$module = 'supplier_invoice';
			break;
		case 'order_supplier':
			$module = 'supplier_order';
			break;
		case 'recruitmentjobposition':
			$module = 'recruitment';
			$subdirectory = '/recruitmentjobposition';
			break;
		case 'recruitmentcandidature':
			$module = 'recruitment';
			$subdirectory = '/recruitmentcandidature';
			break;
		case 'knowledgerecord':
			$module = 'knowledgemanagement';
			$subdirectory = '/knowledgerecord';
			break;
		case 'commande_fournisseur':
			$module = 'fournisseur';
			$subdirectory = '/commande';
			break;
		case 'expedition':
			$subdirectory = '/sending';
			break;
		case 'company':
			$module = 'societe';
			break;
		case 'service':
		case 'produit':
			$module = 'product';
			break;
		case 'action':
		case 'actioncomm':
		case 'event':
			$module = 'agenda';
			break;
		default:
			break;
	}

	// Get the relative path of directory
	if ($mode == 'output' || $mode == 'outputrel' || $mode == 'version') {
		if (isset($conf->$module) && property_exists($conf->$module, 'multidir_output')) {
			$s = '';
			if ($mode != 'outputrel') {
				$s = $conf->$module->multidir_output[(empty($object->entity) ? $conf->entity : $object->entity)] . $subdirectory;
			}
			if ($forobject && $object->id > 0) {
				$s .= ($mode != 'outputrel' ? '/' : '') . get_exdir(0, 0, 0, 0, $object);
			}
			return $s;
		} elseif (isset($conf->$module) && property_exists($conf->$module, 'dir_output')) {
			$s = '';
			if ($mode != 'outputrel') {
				$s = $conf->$module->dir_output . $subdirectory;
			}
			if ($forobject && $object->id > 0) {
				$s .= ($mode != 'outputrel' ? '/' : '') . get_exdir(0, 0, 0, 0, $object);
			}
			return $s;
		} else {
			return 'error-diroutput-not-defined-for-this-object=' . $module;
		}
	} elseif ($mode == 'temp') {
		if (isset($conf->$module) && property_exists($conf->$module, 'multidir_temp')) {
			return $conf->$module->multidir_temp[(empty($object->entity) ? $conf->entity : $object->entity)];
		} elseif (isset($conf->$module) && property_exists($conf->$module, 'dir_temp')) {
			return $conf->$module->dir_temp;
		} else {
			return 'error-dirtemp-not-defined-for-this-object=' . $module;
		}
	} else {
		return 'error-bad-value-for-mode';
	}
}




if (!function_exists('einvoicingDolGetButtonActionDropdown')) {
	/**
	 *  Build a dropdown action button from a list of sub-buttons.
	 *  Polyfill for Dolibarr < 18, where dolGetButtonAction() does not support an array as $url
	 *  (dropdown mode). Mirrors, line for line, the array-mode HTML built natively by
	 *  dolGetButtonAction() since Dolibarr 18, so the rendered dropdown is identical.
	 *
	 *  @param	string	$label			Dropdown toggle visible label
	 *  @param	array	$urlButtons		List of sub-buttons, same format as the native $url array
	 *                                  (each entry: 'lang', 'enabled', 'perm', 'label', 'url')
	 *  @param	array	$params			Extra params (only 'backtopage' is honored, like the core function)
	 *  @return	string					Dropdown HTML
	 *  @since	Dolibarr V18
	 */
	function einvoicingDolGetButtonActionDropdown($label, array $urlButtons, array $params = array())  // @phan-suppress-current-line PhanRedefineFunction
	{
		global $langs;

		$out = '<div id="einvoicing_button_dropdown" class="dropdown inline-block dropdown-holder">';
		$out .= '<a style="margin-right: auto;" class="dropdown-toggle butAction" data-toggle="dropdown">' . $label . '</a>';
		$out .= '<div class="dropdown-content">';
		foreach ($urlButtons as $subbutton) {
			if (!empty($subbutton['enabled']) && !empty($subbutton['perm'])) {
				if (!empty($subbutton['lang'])) {
					$langs->load($subbutton['lang']);
				}
				$out .= dolGetButtonAction('', $langs->trans($subbutton['label']), 'default', DOL_URL_ROOT . $subbutton['url'] . (empty($params['backtopage']) ? '' : '&amp;backtopage=' . urlencode($params['backtopage'])), '', 1);
			}
		}
		$out .= '</div>';
		$out .= '</div>';

		return $out;
	}
}


if (!method_exists('Societe', 'findNearest')) {
	/**
	 *    Search the thirdparty that match the most the provided parameters.
	 *    Searching rules try to find the existing third party.
	 *
	 *    @param	int		$rowid			Id of third party
	 *    @param    string	$ref			Reference of third party, name (Warning, this can return several records)
	 *    @param    string	$ref_ext       	External reference of third party (Warning, this information is a free field not provided by Dolibarr)
	 *    @param    string	$barcode       	Barcode of third party to load
	 *    @param    string	$idprof1		Prof id 1 of third party (Warning, this can return several records)
	 *    @param    string	$idprof2		Prof id 2 of third party (Warning, this can return several records)
	 *    @param    string	$idprof3		Prof id 3 of third party (Warning, this can return several records)
	 *    @param    string	$idprof4		Prof id 4 of third party (Warning, this can return several records)
	 *    @param    string	$idprof5		Prof id 5 of third party (Warning, this can return several records)
	 *    @param    string	$idprof6		Prof id 6 of third party (Warning, this can return several records)
	 *    @param    string	$email   		Email of third party (Warning, this can return several records)
	 *    @param    string	$ref_alias 		Name_alias of third party (Warning, this can return several records)
	 * 	  @param	int		$is_client		Only client third party
	 *    @param	int		$is_supplier	Only supplier third party
	 *    @return   int						ID of thirdparty found if OK, <0 if KO (-2 if two records found or other negative if error), 0 if not found.
	 */
	function findNearest($rowid = 0, $ref = '', $ref_ext = '', $barcode = '', $idprof1 = '', $idprof2 = '', $idprof3 = '', $idprof4 = '', $idprof5 = '', $idprof6 = '', $email = '', $ref_alias = '', $is_client = 0, $is_supplier = 0)
	{
		global $db;

		// A rowid is known, it is a unique key so we found it
		if ($rowid) {
			return $rowid;
		}

		dol_syslog("findNearest", LOG_DEBUG);
		$tmpthirdparty = new Societe($db);

		// We try to find the thirdparty with exact matching on all fields
		// Societe::fetch() answers 1 on a match up to Dolibarr 19, and the row id from 20 on. This
		// function has to answer an id whatever the core, because that is what its callers book the
		// document on - taking the raw answer attached it to the thirdparty of id 1 (issue #739).
		$result = $tmpthirdparty->fetch($rowid, $ref, $ref_ext, $barcode, $idprof1, $idprof2, $idprof3, $idprof4, $idprof5, $idprof6, $email, $ref_alias, $is_client, $is_supplier);
		if ($result > 0) {
			return $tmpthirdparty->id;
		}
		if ($result != 0) {
			return $result;
		}

		// Then search on barcode if we have it (+ restriction on is_client and is_supplier)
		dol_syslog("Thirdparty not found with exact match so we try barcode search", LOG_DEBUG);
		if ($barcode) {
			$result = $tmpthirdparty->fetch(0, '', '', $barcode, '', '', '', '', '', '', '', '', $is_client, $is_supplier);
			if ($result > 0) {
				return $tmpthirdparty->id;
			}
			if ($result != 0) {
				return $result;
			}
		}

		$sqlstart = "SELECT s.rowid as id FROM ".MAIN_DB_PREFIX."societe as s";
		$sqlstart .= ' WHERE s.entity IN ('.getEntity('societe').')';
		if ($is_client) {
			$sqlstart .= ' AND s.client > 0';
		}
		if ($is_supplier) {
			$sqlstart .= ' AND s.fournisseur > 0';
		} // if both false, no test (the thirdparty can be client and/or supplier)

		// Then search on profids with a OR (+ restriction on is_client and is_supplier)
		dol_syslog("Thirdparty not found with barcode search so we try profids search", LOG_DEBUG);
		$sqlprof = "";
		if ($idprof1) {
			$sqlprof .= " s.siren = '".$db->escape($idprof1)."'";
		}
		if ($idprof2) {
			if ($sqlprof) {
				$sqlprof .= " OR";
			}
			$sqlprof .= " s.siret = '".$db->escape($idprof2)."'";
		}
		if ($idprof3) {
			if ($sqlprof) {
				$sqlprof .= " OR";
			}
			$sqlprof .= " s.ape = '".$db->escape($idprof3)."'";
		}
		if ($idprof4) {
			if ($sqlprof) {
				$sqlprof .= " OR";
			}
			$sqlprof .= " s.idprof4 = '".$db->escape($idprof4)."'";
		}
		if ($idprof5) {
			if ($sqlprof) {
				$sqlprof .= " OR";
			}
			$sqlprof .= " s.idprof5 = '".$db->escape($idprof5)."'";
		}
		if ($idprof6) {
			if ($sqlprof) {
				$sqlprof .= " OR";
			}
			$sqlprof .= " s.idprof6 = '".$db->escape($idprof6)."'";
		}

		if ($sqlprof) {
			$sqlprofquery = $sqlstart . " AND (".$sqlprof." )";
			$resql = $db->query($sqlprofquery);
			if ($resql) {
				$num = $db->num_rows($resql);
				if ($num > 1) {
					$error = 'Fetch found several records. Rename one of thirdparties to avoid duplicate.';
					dol_syslog($error, LOG_WARNING);
					$result = -2;
				} elseif ($num) {
					$obj = $db->fetch_object($resql);
					$result = $obj->id;
				} else {
					$result = 0;
				}
			} else {
				$error = $db->lasterror();
				dol_syslog($error, LOG_ERR);
				$result = -3;
			}
			if ($result != 0) {
				return $result;
			}
		}

		// Then search on email (+ restriction on is_client and is_supplier)
		dol_syslog("Thirdparty not found with profids search so we try email search", LOG_DEBUG);
		if ($email) {
			$result = $tmpthirdparty->fetch(0, '', '', '', '', '', '', '', '', '', $email, '', $is_client, $is_supplier);
			if ($result > 0) {
				return $tmpthirdparty->id;
			}
			if ($result != 0) {
				return $result;
			}
		}

		// Then search ref, ref_ext or alias with a OR (+ restriction on is_client and is_supplier)
		dol_syslog("Thirdparty not found with email search so we try ref, ref_ext or ref_alias search", LOG_DEBUG);
		$sqlref = "";
		if ($ref) {
			$sqlref .= " s.nom = '".$db->escape($ref)."'";
		}
		if ($ref_alias) {
			if ($sqlref) {
				$sqlref .= " OR";
			}
			$sqlref .= " s.name_alias = '".$db->escape($ref_alias)."'";
		}
		if ($ref_ext) {
			if ($sqlref) {
				$sqlref .= " OR";
			}
			$sqlref .= " s.ref_ext = '".$db->escape($ref_ext)."'";
		}

		if ($sqlref) {
			$sqlrefquery = $sqlstart . " AND (".$sqlref." )";
			$resql = $db->query($sqlrefquery);
			if ($resql) {
				$num = $db->num_rows($resql);
				if ($num > 1) {
					$error = 'Fetch found several records. Rename one of thirdparties to avoid duplicate.';
					dol_syslog($error, LOG_WARNING);
					$result = -2;
				} elseif ($num) {
					$obj = $db->fetch_object($resql);
					$result = $obj->id;
				} else {
					$result = 0;
				}
			} else {
				$error = $db->lasterror();
				dol_syslog($error, LOG_ERR);
				$result = -3;
			}
		}

		return $result;
	}
}


/**
 * Tell whether the seller reports the VAT on debits ("TVA d'apres les debits").
 *
 * The scheme is not a setting of this module: Dolibarr holds it in the Tax/VAT module setup, where
 * "TVA d'apres les debits" is TAX_MODE 1, the one that puts both sell modes on 'invoice'. Those same
 * two constants are what the VAT report of Dolibarr declares on, hence no override here.
 *
 * @return bool		True when the invoices must carry the "VAT on debits" mention
 */
function einvoicingVatOnDebits()
{
	return (getDolGlobalString('TAX_MODE_SELL_PRODUCT') != 'payment' && getDolGlobalString('TAX_MODE_SELL_SERVICE') != 'payment');
}

/**
 * VAT point date code (BT-8) the generated document has to declare.
 *
 * BR-CL-06 restricts BT-8 to 5, 29 or 72, BR-CO-03 makes it exclusive with BT-7, and CII-SR-462 allows
 * one distinct value for the whole document. Under the French socle 5 does not mean "payable now" but
 * "the seller took the debits option" (G1.43, BR-FR-MAP-03), so a seller under the standard scheme
 * sends nothing on goods and 72 on services. 29 is never sent: BR-FR-MAP-29 says the PPF expects only 5.
 *
 * @param  bool		$hasProductLine		The document carries at least one goods line
 * @param  bool		$hasServiceLine		The document carries at least one service line
 * @param  bool		$isDeposit			The document is a down payment invoice
 * @return string						'5', '29', '72', or '' to leave BT-8 out
 */
function einvoicingVatPointDateCode($hasProductLine, $hasServiceLine, $isDeposit = false)
{
	// A down payment is the one case the socle settles on its own, and it settles it against every other rule here: XP Z12-014 annexe A reads
	// @phpcs:ignore
	// "La TVA est exigible a l'encaissement de l'acompte pour les livraisons de biens comme pour les prestations de service, meme avec option sur les debits".
	// So it is decided first, before the declared regime and before the VAT mode. Dolibarr
	// builds every down payment line as a goods line, so without this the document would say nothing
	// while its cash-in is reported to the platform with the status 212 for that very reason.
	if ($isDeposit) {
		return '72';
	}

	// The debits option is general and prevails over every invoice issued (G1.43), so it is declared
	// before anything the document itself could say.
	if (einvoicingVatOnDebits()) {
		return '5';
	}

	$sellProductOnPayment = (getDolGlobalString('TAX_MODE_SELL_PRODUCT') == 'payment');
	$sellServiceOnPayment = (getDolGlobalString('TAX_MODE_SELL_SERVICE') == 'payment');

	// Nothing is sent when no operation of the document is taxed on collection. The socle reads that
	// silence: XP Z12-014 annexe A describes a VAT due on collection as
	// "avec BT-8 absent, ou bien present et signifiant a l'encaissement (72)", making the two equivalent.
	if (($sellServiceOnPayment && $hasServiceLine) || ($sellProductOnPayment && $hasProductLine)) {
		return '72';
	}
	if ($sellProductOnPayment && $sellServiceOnPayment) {
		return '72';
	}

	return '';
}

/**
 * Tell whether the VAT of this document falls due on collection, i.e. whether a cash-in on it has to
 * be reported to the platform with the status 212.
 *
 * Not the same question as BT-8: they part company on the down payment of a seller who took the debits
 * option, whose document declares 5 because the option is general (G1.43) while the down payment
 * itself stays taxed on collection.
 *
 * @param  bool		$hasProductLine		The document carries at least one goods line
 * @param  bool		$hasServiceLine		The document carries at least one service line
 * @return bool							True when the cash-in has to be reported
 */
function einvoicingVatDueOnCollection($hasProductLine, $hasServiceLine)
{
	$sellProductOnPayment = (getDolGlobalString('TAX_MODE_SELL_PRODUCT') == 'payment');
	$sellServiceOnPayment = (getDolGlobalString('TAX_MODE_SELL_SERVICE') == 'payment');

	if ($sellProductOnPayment && $sellServiceOnPayment) {
		return true;
	}
	if (!$sellProductOnPayment && !$sellServiceOnPayment) {
		return false;
	}

	return (($sellServiceOnPayment && $hasServiceLine) || ($sellProductOnPayment && $hasProductLine));
}

/**
 * VAT regime of the seller, as far as the identifier it declares on its invoices is concerned.
 *
 * A seller that charges VAT declares BT-31, one that does not (franchise en base, "Non assujetti a la
 * TVA" in Dolibarr) declares BT-32 - in France its SIREN. Without either, every exempt line trips
 * BR-E-02 and the platform refuses the document (issue #560). The regime is read from the property the
 * core computed (Societe::setMysoc() into ->tva_assuj), never re-derived, so the two cannot disagree.
 *
 * @param	Societe		$seller		Selling company, normally $mysoc
 * @return	string					'standard' (the seller charges VAT, BT-31) or 'franchise' (it does not, BT-32)
 */
function einvoicingSellerVatRegime($seller)
{
	// The reading of tva_assuj is the one the core makes of it in get_default_tva(): for $mysoc it is
	// always the int of FACTURE_TVAOPTION, but the column of a thirdparty also holds the literal forms,
	// and there is no reason for this to answer differently from the core.
	$assuj = $seller->tva_assuj;
	$subjectToVat = !((is_numeric($assuj) && !$assuj) || (!is_numeric($assuj) && $assuj == 'franchise'));

	return $subjectToVat ? 'standard' : 'franchise';
}

/**
 * Build the deliver-to address of a shipping contact, the way the core builds the shipping frame.
 *
 * @param	Contact		$shipContact	Contact designated as the delivery point
 * @param	Societe		$buyer			Thirdparty being invoiced, last resort for the name
 * @param	Translate	$outputlangs	Language the name is built in
 * @param	DoliDB		$db				Database handler
 * @return	array{name:string,address:string,zip:string,town:string,country:string}
 */
function einvoicingShipToFromContact($shipContact, $buyer, $outputlangs, $db)
{
	require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';

	$shipSoc = null;
	$shipSocId = (int) (!empty($shipContact->socid) ? $shipContact->socid : ($shipContact->fk_soc ?? 0));
	if ($shipSocId > 0) {
		$tmpsoc = new Societe($db);
		if ($tmpsoc->fetch($shipSocId) > 0) {
			$shipSoc = $tmpsoc;
		}
	}

	// The core makes the distinction here and this follows it: pdf_build_address() switches to the
	// company of the contact only when that company is not the one being invoiced
	// ($targetcontact->socid != $targetcompany->id).
	$anothercompany = ($shipSoc !== null && $shipSoc->id != $buyer->id);

	// BT-70 names a party. When the contact belongs to another company, that company is the party the
	// goods go to, and naming it also spares the document a personal name it has no use for. When the
	// contact belongs to the company being invoiced, its name is already the BuyerTradeParty name and
	// would say nothing here, while the label the user gave the contact is what names the delivery
	// point - so that one is kept.
	$name = ($anothercompany && !empty($shipSoc->name)) ? $shipSoc->name : trim($shipContact->getFullName($outputlangs));
	if ($name === '') {
		$name = ($shipSoc !== null && !empty($shipSoc->name)) ? $shipSoc->name : $buyer->name;
	}

	// The contact wins when it carries an address of its own - a delivery site the user entered
	// deliberately; with none, its company address is used, as pdf_build_address() does. A contact of
	// the invoiced company with no address then equals the buyer, so no distinct BG-15 is emitted.
	$source = !empty($shipContact->address) ? $shipContact : ($shipSoc !== null ? $shipSoc : $shipContact);

	return array(
		'name'    => (string) $name,
		'address' => (string) $source->address,
		'zip'     => (string) $source->zip,
		'town'    => (string) $source->town,
		'country' => (string) ($shipContact->country_code ?: ($shipSoc !== null ? $shipSoc->country_code : '')),
	);
}

/**
 * Tax registrations (BT-31 / BT-32) the seller declares, in the shape the two writers consume.
 *
 * One entry only: the two identifiers answer the same question. Which one is decided by the regime,
 * not by "is a VAT number recorded", so a seller subject to VAT that left the field empty still gets
 * the explicit BADVATNUMBER message instead of a silent fallback on its SIREN (issue #560).
 *
 * @param	Societe		$seller		Selling company, normally $mysoc
 * @return	array<array{type:string,value:string}>	Registrations to write, possibly empty
 */
function einvoicingSellerTaxRegistrations($seller)
{
	if (einvoicingSellerVatRegime($seller) === 'franchise') {
		// BT-32. In France the tax registration identifier of a company with no VAT number is its
		// SIREN, which idprof1 holds; it is already what BT-30 carries under the scheme 0002.
		$taxId = trim((string) ($seller->idprof1 ?? ''));

		return $taxId !== '' ? array(array('type' => 'FC', 'value' => $taxId)) : array();
	}

	$vatNumber = trim((string) ($seller->tva_intra ?? ''));

	return $vatNumber !== '' ? array(array('type' => 'VA', 'value' => $vatNumber)) : array();
}

/**
 * Invoicing period of the document (BG-14 / BT-73 / BT-74), derived from the periods of its lines.
 *
 * Dolibarr has no period at invoice level, only BT-134/BT-135 on the line, so the header takes the
 * earliest start and the latest end (issue #572). A start later than its end is not emitted at all:
 * BR-29 refuses it and the whole document would be rejected. $billingPeriod is the accumulator
 * buildinvoicelines.inc.php fills, ['start' => [<numligne> => <timestamp>], 'end' => [...]].
 *
 * @param	array<string,array<int,int>>	$billingPeriod	Line periods collected from the invoice
 * @return	array{start: ?int, end: ?int}					BT-73 and BT-74, null when there is none
 */
function einvoicingInvoicingPeriodFromLines($billingPeriod)
{
	$starts = array_filter(array_map('intval', (array) ($billingPeriod['start'] ?? array())));
	$ends = array_filter(array_map('intval', (array) ($billingPeriod['end'] ?? array())));

	$start = $starts ? min($starts) : null;
	$end = $ends ? max($ends) : null;

	if ($start !== null && $end !== null && $start > $end) {
		dol_syslog('einvoicingInvoicingPeriodFromLines: the lines derive a period starting ' . dol_print_date($start, 'day')
			. ' and ending ' . dol_print_date($end, 'day') . ', which BR-29 refuses; BT-73/BT-74 are left out', LOG_WARNING);

		return array('start' => null, 'end' => null);
	}

	return array('start' => $start, 'end' => $end);
}

/**
 * Version of the module, followed by the commit it was built from when that one is known.
 *
 * The VERSION file does not move between two releases, so the version alone does not name sources.
 * Single place deciding how version and commit read together, so the stamp is the same wherever it is
 * printed. An installation whose commit cannot be known gets the version alone, with no parentheses.
 *
 * @return	string	Something like "1.4.2 (a6f4d2b)", or "1.4.2" when the commit is unknown
 */
function einvoicingModuleStamp()
{
	$versionfile = dirname(__DIR__).'/VERSION';
	$version = (is_readable($versionfile) ? trim((string) file_get_contents($versionfile)) : '');
	$commit = einvoicingModuleCommit();

	if ($version === '') {
		return $commit;		// the file is part of the module, but nothing forces a deployment to keep it
	}

	return $version.($commit !== '' ? ' ('.$commit.')' : '');
}

/**
 * Commit the module sources were built from, empty string when it cannot be known.
 *
 * An installed module has no repository to ask, so the packager writes the commit into a COMMIT file
 * at the root of the module (dev/build/makepack-modules.php); a deployment made from a clone has none,
 * hence the repository metadata read as a second source. Deliberately not part of VERSION, which the
 * core compares with version_compare() to flag an available update. Always shortened to 7 characters.
 *
 * @return	string	Short commit hash, or '' when neither source answers
 */
function einvoicingModuleCommit()
{
	$stampfile = dirname(__DIR__).'/COMMIT';

	if (is_readable($stampfile)) {
		$commit = trim((string) file_get_contents($stampfile));
		if (preg_match('/^[0-9a-f]{7,40}$/', $commit)) {
			return substr($commit, 0, 7);
		}
	}

	// Deployed from a clone: no stamp was ever written, but the checkout itself knows. Two
	// places are looked at and no more - the module directory, when the module is a repository
	// of its own, and the directory above it, which is this repository.
	foreach (array(dirname(__DIR__), dirname(__DIR__, 2)) as $repodir) {
		$commit = einvoicingCheckoutCommit($repodir);
		if ($commit !== '') {
			return $commit;
		}
	}

	return '';
}

/**
 * Commit at the tip of a repository checkout, read from its metadata files.
 *
 * git is never run: exec() is forbidden on a good many hostings. What is read is what
 * `git rev-parse HEAD` would resolve, through the two indirections a checkout may add - a .git file
 * naming the real directory (linked worktree, submodule), and refs kept in the repository it came from.
 *
 * @param	string	$repodir	Directory expected to hold the .git of a checkout
 * @return	string				Short commit hash, or '' when it is not a readable checkout
 */
function einvoicingCheckoutCommit($repodir)
{
	$gitdir = $repodir.'/.git';

	// A linked worktree and a submodule replace .git with a file naming the real directory
	$reg = array();
	if (is_file($gitdir) && preg_match('/^gitdir:\s*(\S.*)$/m', (string) file_get_contents($gitdir), $reg)) {
		$gitdir = trim($reg[1]);
		if (strpos($gitdir, '/') !== 0) {
			$gitdir = $repodir.'/'.$gitdir;
		}
	}
	if (!is_dir($gitdir) || !is_readable($gitdir.'/HEAD')) {
		return '';
	}

	$head = trim((string) file_get_contents($gitdir.'/HEAD'));
	if (preg_match('/^[0-9a-f]{40,}$/', $head)) {
		return substr($head, 0, 7);		// detached HEAD carries the commit itself
	}
	if (!preg_match('#^ref:\s*(refs/\S+)#', $head, $reg)) {
		return '';
	}
	$ref = $reg[1];

	// A linked worktree has a HEAD of its own but shares the refs of the main repository
	$refdir = $gitdir;
	if (is_readable($gitdir.'/commondir')) {
		$commondir = trim((string) file_get_contents($gitdir.'/commondir'));
		$refdir = (strpos($commondir, '/') === 0 ? $commondir : $gitdir.'/'.$commondir);
	}

	$commit = '';
	if (is_readable($refdir.'/'.$ref)) {
		$commit = trim((string) file_get_contents($refdir.'/'.$ref));
	} elseif (is_readable($refdir.'/packed-refs')) {
		// git packs refs away instead of keeping one file each: "<commit> <refname>" per line
		$packed = (string) file_get_contents($refdir.'/packed-refs');
		if (preg_match('/^([0-9a-f]{40,})\s+'.preg_quote($ref, '/').'$/m', $packed, $reg)) {
			$commit = $reg[1];
		}
	}

	return (preg_match('/^[0-9a-f]{40,}$/', $commit) ? substr($commit, 0, 7) : '');
}

/**
 * Key identifying the VAT breakdown group (BG-23) a line belongs to.
 *
 * A group is identified by BT-118, BT-119 and BT-120/BT-121 and by nothing else - in particular not by
 * the Dolibarr vat_src_code, which split otherwise identical groups in two and had the platform reject
 * the document (BR-S-08). A function because the same key is built in more than one place.
 *
 * @param	string		$categoryVAT			VAT category code of the line (BT-118)
 * @param	float|string	$rate				VAT rate of the line (BT-119)
 * @param	string		$exemptionReasonCode	Exemption reason code (BT-121), empty when there is none
 * @param	string		$exemptionReason		Exemption reason text (BT-120), empty when there is none
 * @return	string								Key of the group in the breakdown accumulator
 */
function einvoicingVatBreakdownKey($categoryVAT, $rate, $exemptionReasonCode = '', $exemptionReason = '')
{
	return $categoryVAT.'|'.$rate.'|'.$exemptionReasonCode.'|'.$exemptionReason;
}

/**
 * Tell whether an URL may be used as the target of a redirect made by the OAuth proxy.
 *
 * public/proxy_oauthcallback.php is reachable without authentication and hands the freshly issued
 * tokens to the redirect_uri the caller supplied: a trust decision. Only an absolute http(s) URL whose
 * host matches, on a dot boundary, a comma separated EINVOICING_SUPERPDPVIAPARTNER_ONLY_DOMAIN entry
 * is allowed. An empty list is accepted for one transition step.
 *
 * @param	string	$url	Candidate destination, as received from the caller
 * @return	bool			True when the URL may be passed to header('Location: ...')
 */
function einvoicingIsAllowedRedirectUrl($url)
{
	$url = trim((string) $url);
	if ($url === '') {
		return false;
	}
	if (!preg_match('#^https?://#i', $url)) {
		return false;
	}

	$host = parse_url($url, PHP_URL_HOST);
	if (!is_string($host) || $host === '') {
		return false;
	}
	$host = strtolower($host);

	$alloweddomains = getDolGlobalString('EINVOICING_SUPERPDPVIAPARTNER_ONLY_DOMAIN');
	if ($alloweddomains === '') {
		// TRANSITION. Nothing ever set this option, so refusing here would cut every customer instance off
		// its proxy on the day of the update. Both setup pages warn for as long as the list is empty.
		// TODO Remove this and return false instead, once deployments have had time to declare the
		// domains of their customer instances.
		return true;
	}

	foreach (explode(',', $alloweddomains) as $alloweddomain) {
		$alloweddomain = strtolower(trim($alloweddomain, " \t\n\r\0\x0B."));
		if ($alloweddomain === '') {
			continue;
		}
		if ($host === $alloweddomain) {
			return true;
		}
		if (substr($host, -(strlen($alloweddomain) + 1)) === '.'.$alloweddomain) {
			return true;
		}
	}

	return false;
}
