<?php
/* Copyright (C) 2025		SuperAdmin					<daoud.mouhamed@gmail.com>
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
 * \file    pdpconnectfr/lib/pdpconnectfr.lib.php
 * \ingroup pdpconnectfr
 * \brief   Library files with common functions for PDPConnectFR
 */

/**
 * Prepare admin pages header
 *
 * @return array<array{string,string,string}>
 */
function pdpconnectfrAdminPrepareHead()
{
	global $langs, $conf;

	// global $db;
	// $extrafields = new ExtraFields($db);
	// $extrafields->fetch_name_optionals_label('myobject');

	$langs->load("pdpconnectfr@pdpconnectfr");

	$h = 0;
	$head = array();

	$head[$h][0] = dol_buildpath("/pdpconnectfr/admin/setup.php", 1);
	$head[$h][1] = $langs->trans("PASettings");
	$head[$h][2] = 'settings';
	$h++;

	$head[$h][0] = dol_buildpath("/pdpconnectfr/admin/setup_options.php", 1);
	$head[$h][1] = $langs->trans("Options");
	$head[$h][2] = 'options';
	$h++;

	/*
	$head[$h][0] = dol_buildpath("/pdpconnectfr/admin/myobject_extrafields.php", 1);
	$head[$h][1] = $langs->trans("ExtraFields");
	$nbExtrafields = (isset($extrafields->attributes['myobject']['label']) && is_countable($extrafields->attributes['myobject']['label'])) ? count($extrafields->attributes['myobject']['label']) : 0;
	if ($nbExtrafields > 0) {
		$head[$h][1] .= '<span class="badge marginleftonlyshort">' . $nbExtrafields . '</span>';
	}
	$head[$h][2] = 'myobject_extrafields';
	$h++;

	$head[$h][0] = dol_buildpath("/pdpconnectfr/admin/myobjectline_extrafields.php", 1);
	$head[$h][1] = $langs->trans("ExtraFieldsLines");
	$nbExtrafields = (isset($extrafields->attributes['myobjectline']['label']) && is_countable($extrafields->attributes['myobjectline']['label'])) ? count($extrafields->attributes['myobject']['label']) : 0;
	if ($nbExtrafields > 0) {
		$head[$h][1] .= '<span class="badge marginleftonlyshort">' . $nbExtrafields . '</span>';
	}
	$head[$h][2] = 'myobject_extrafieldsline';
	$h++;
	*/

	$head[$h][0] = dol_buildpath("/pdpconnectfr/admin/about.php", 1);
	$head[$h][1] = $langs->trans("About");
	$head[$h][2] = 'about';
	$h++;

	if (getDolGlobalInt('PDPCONNECTFR_ALLOW_DEVTOOLS')) {
		$head[$h][0] = dol_buildpath("/pdpconnectfr/admin/setup_devtools.php", 1);
		$head[$h][1] = $langs->trans("DevTools");
		$head[$h][2] = 'devtools';
		$h++;
	}

	// Show more tabs from modules
	// Entries must be declared in modules descriptor with line
	//$this->tabs = array(
	//	'entity:+tabname:Title:@pdpconnectfr:/pdpconnectfr/mypage.php?id=__ID__'
	//); // to add new tab
	//$this->tabs = array(
	//	'entity:-tabname:Title:@pdpconnectfr:/pdpconnectfr/mypage.php?id=__ID__'
	//); // to remove a tab
	complete_head_from_modules($conf, $langs, null, $head, $h, 'pdpconnectfr@pdpconnectfr');

	complete_head_from_modules($conf, $langs, null, $head, $h, 'pdpconnectfr@pdpconnectfr', 'remove');

	return $head;
}

/**
 * Show a warning if setup not correct.
 *
 * @param 	PdpConnectFr $pdpconnectfr	Object PdpConnectFr
 * @return	string						Return string with warning (or '')
 */
function pdpShowWarning($pdpconnectfr)
{
	global $langs;

	$ret = '';

	if (getDolGlobalString('PDPCONNECTFR_LIVE')) {
		$mysocCheck = $pdpconnectfr->validateMyCompanyConfiguration();
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
			$retour = $thirdparty->idprof1;
			break;
		case 'DE':
			if (!empty($thirdparty->idprof6)) {
				$retour = $thirdparty->idprof6;
				break;
			} elseif (!empty($thirdparty->idprof2) && !empty($thirdparty->idprof3)) {
				$retour = $thirdparty->idprof2 . $thirdparty->idprof3;
			} else {
				$retour = $thirdparty->idprof1;
			}
			break;
		case 'FR':
			if (!empty($thirdparty->idprof1)) {
				$retour = $thirdparty->idprof1; // SIREN
			} else {
				$retour = substr($thirdparty->idprof2, 9); // 9 first chars of SIRET
			}
			break;
		default:
			$retour = $thirdparty->idprof1 ? $thirdparty->idprof1 : $thirdparty->idprof2;
	}

	return preg_replace('/\\s+/', '', $retour);
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
	return idprof($object->thirdparty);
}



// Compatibility functions
/**
 * Return the full path of the directory where a module (or an object of a module) stores its files.
 * Path may depends on the entity if a multicompany module is enabled.
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

if (!function_exists("getMultidirTemp")) {
	/**
	 * Return the full path of the directory where a module (or an object of a module) stores its temporary files.
	 * Path may depends on the entity if a multicompany module is enabled.
	 *
	 * @param 	CommonObject 	$object 	Dolibarr common object
	 * @param 	string 			$module 	Override object element, for example to use 'mycompany' instead of 'societe'
	 * @param	int				$forobject	Return the more complete path for the given object instead of for the module only.
	 * @return 	string|null					The path of the relative temp directory of the module
	 */
	function getMultidirTemp($object, $module = '', $forobject = 0)
	{
		return getMultidirOutputCompat($object, $module, $forobject, 'temp');
	}
}

if (!function_exists("getMultidirVersion")) {
	/**
	 * Return the full path of the directory where a module (or an object of a module) stores its versioned files.
	 * Path may depends on the entity if a multicompany module is enabled.
	 *
	 * @param 	CommonObject 	$object 	Dolibarr common object
	 * @param 	string 			$module 	Override object element, for example to use 'mycompany' instead of 'societe'
	 * @param	int				$forobject	Return the more complete path for the given object instead of for the module only.
	 * @return string|null					The path of the relative version directory of the module
	 */
	function getMultidirVersion($object, $module = '', $forobject = 0)
	{
		return getMultidirOutputCompat($object, $module, $forobject, 'version');
	}
}

if (!function_exists("GETPOSTFLOAT")) {
	/**
	 *  Return the value of a $_GET or $_POST supervariable, converted into float.
	 *  Warning: This function assumes by default that the input is a number entered by end user in user format in local language (with possible thousands separator and decimal separator).
	 *  If it is not the case, use the parameter $option = 1 instead.
	 *
	 *  @param  string          $paramname      Name of the $_GET or $_POST parameter
	 *	@param	''|'MU'|'MT'|'MS'|'CU'|'CT'|int	$rounding	Type of rounding ('', 'MU', 'MT, 'MS', 'CU', 'CT', integer) {@see price2num()}
	 * 	@param	int<0,2>		$option			Put 1 if you know that content is already universal format number (so no correction on decimal will be done)
	 * 											Put 2 if you know that number is a user input (so we know we have to fix decimal separator).
	 * 					                        Use 0 if unknown (never use this anymore, automatic detection is not reliable with some languages).
	 *  @return float                           Value converted into float
	 *  @since	Dolibarr V20
	 */
	function GETPOSTFLOAT($paramname, $rounding = '', $option = 2)
	{
		// price2num() can be used to round to an expected accuracy and/or to sanitize any valid user input (such as "1 234.5", "1 234,5", "1'234,5", "1·234,5", "1,234.5", etc.)
		return (float) price2num(GETPOST($paramname), $rounding, $option);
	}
}

if (!function_exists('dolPrintHTML')) {
	/**
	 * Return a string ready to be output on HTML page
	 * To use text inside an attribute, use can use only dol_escape_htmltag()
	 *
	 * @param	string	$s		String to print
	 * @return	string			String ready for HTML output
	 */
	function dolPrintHTML($s)
	{
		return dol_escape_htmltag(dol_htmlwithnojs(dol_string_onlythesehtmltags(dol_htmlentitiesbr($s), 1, 1, 1)), 1, 1, 'common', 0, 1);
	}
}

if (!function_exists('dolPrintHTMLForAttribute')) {
	/**
	 * Return a string ready to be output into an HTML attribute (alt, title, data-html, ...)
	 * With dolPrintHTMLForAttribute(), the content is HTML encode, even if it is already HTML content.
	 *
	 * @param	string		$s						String to print
	 * @param	int			$escapeonlyhtmltags		1=Escape only html tags, not the special chars like accents.
	 * @param	string[]	$allowothertags			List of other tags allowed
	 * @return	string								String ready for HTML output
	 * @see dolPrintHTML(), dolPrintHTMLFortextArea()
	 */
	function dolPrintHTMLForAttribute($s, $escapeonlyhtmltags = 0, $allowothertags = array())
	{
		$allowedtags = array('br', 'b', 'font', 'hr', 'span');
		if (!empty($allowothertags) && is_array($allowothertags)) {
			$allowedtags = array_merge($allowedtags, $allowothertags);
		}
		// The dol_htmlentitiesbr will convert simple text into html, including switching accent into HTML entities
		// The dol_escape_htmltag will escape html tags.
		if ($escapeonlyhtmltags) {
			return dol_escape_htmltag(dol_string_onlythesehtmltags($s, 1, 0, 0, 0, $allowedtags), 1, -1, '', 1, 1);
		} else {
			return dol_escape_htmltag(dol_string_onlythesehtmltags(dol_htmlentitiesbr($s), 1, 0, 0, 0, $allowedtags), 1, -1, '', 0, 1);
		}
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
		$result = $tmpthirdparty->fetch($rowid, $ref, $ref_ext, $barcode, $idprof1, $idprof2, $idprof3, $idprof4, $idprof5, $idprof6, $email, $ref_alias, $is_client, $is_supplier);
		if ($result != 0) {
			return $result;
		}

		// Then search on barcode if we have it (+ restriction on is_client and is_supplier)
		dol_syslog("Thirdparty not found with exact match so we try barcode search", LOG_DEBUG);
		if ($barcode) {
			$result = $tmpthirdparty->fetch(0, '', '', $barcode, '', '', '', '', '', '', '', '', $is_client, $is_supplier);
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
				$errors[] = $db->lasterror();
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
				$errors[] = $db->lasterror();
				$result = -3;
			}
		}

		return $result;
	}
}

// Force-load core's pdf.lib.php on versions where pdfExtractMetadata exists in core,
// otherwise function_exists() returns false here (core lib not loaded yet) and the
// polyfill below is declared, then core's later require_once redeclares it -> fatal.
if (file_exists(DOL_DOCUMENT_ROOT . '/core/lib/pdf.lib.php')) {
	require_once DOL_DOCUMENT_ROOT . '/core/lib/pdf.lib.php';
}

if (!function_exists('pdfExtractMetadata')) {
	/**
	 * Function to extract metadata from a PDF file by doing a binary parsing of the PDF file
	 *
	 * @param 	string	$file		Path of file
	 * @param 	string	$field		Key to extract
	 * @return 	string				String of the extracted key or string started with 'ERROR:' if error.
	 */
	function pdfExtractMetadata($file, $field = 'Keywords')
	{
		if (!dol_is_file($file)) {
			return "ERROR: FILE NOT FOUND OR NOT VALID";
		}

		// Get content of PDF file
		$content = file_get_contents(dol_osencode($file));

		// Use a regex to capture the metadata
		if ($content) {
			$matches = array();

			// Remove non printablecaracters
			$content = preg_replace('/[^(\x20-\x7F)]*/', '', $content);
			if (preg_match('/\/' . preg_quote($field, '/') . '\s*\((.*?)\)/', $content, $matches)) {
				return trim($matches[1]);
			}
			return "ERROR: NOT FOUND";
		} else {
			return "ERROR: FAILED TO READ PDF";
		}
	}
}
