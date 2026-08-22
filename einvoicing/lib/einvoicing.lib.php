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
 * \file    einvoicing/lib/einvoicing.lib.php
 * \ingroup einvoicing
 * \brief   Library files with common functions for EInvoicing
 */

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
 * removeAllSpaces
 *
 * @param  string $str string to be cleaned
 * @param  ?string $original_encoding original encoding
 * @return string
 */
function removeAllSpaces(string $str, ?string $original_encoding = null)
{
	// find encoding
	if ($original_encoding === null) {
		$original_encoding = mb_detect_encoding($str, mb_detect_order(), true) ?: 'UTF-8';
	}

	$is_utf8 = (strtoupper($original_encoding) === 'UTF-8');
	if (!$is_utf8) {
		$str = mb_convert_encoding($str, 'UTF-8', $original_encoding);
	}

	// this transform '&nbsp;', '&ensp;', '&emsp;', '&thinsp;' etc. in real spaces Unicode
	$str = html_entity_decode($str, ENT_QUOTES | ENT_HTML5, 'UTF-8');

	// suppress via Regex
	$str = preg_replace('/[\p{Z}\s\x{200B}-\x{200D}\x{FEFF}]+/u', '', $str);

	// restore encoding
	if (!$is_utf8) {
		$str = mb_convert_encoding($str, $original_encoding, 'UTF-8');
	}

	return $str;
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
	function getMultidirTemp($object, $module = '', $forobject = 0)  // @phan-suppress-current-line PhanRedefineFunction
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
	function getMultidirVersion($object, $module = '', $forobject = 0)  // @phan-suppress-current-line PhanRedefineFunction
	{
		return getMultidirOutputCompat($object, $module, $forobject, 'version');
	}
}

if (!function_exists("GETPOSTDATE")) {
	/**
	 *  Return a timestamp built from the year, month, day (and optionally hour, minute, second) fields
	 *  posted by a Dolibarr date selector (Form::selectDate()).
	 *  Provided as a polyfill for Dolibarr < 18, where this function does not exist yet.
	 *
	 *  @param	string	$prefix		Prefix used to build the date selector
	 *  @param	string	$hourTime	'getpost' to read the hour, minute and second from the request,
	 *  							'HH:MM:SS' to force them, anything else for midnight
	 *  @param	string	$gm			Timezone the posted values are expressed in ('auto', 'gmt', 'tzserver', 'tzuserrel', ...)
	 *  @return	int|''				Timestamp, or '' when the selector was left empty
	 *  @since	Dolibarr V18
	 */
	function GETPOSTDATE($prefix, $hourTime = '', $gm = 'auto')  // @phan-suppress-current-line PhanRedefineFunction
	{
		if ($hourTime === 'getpost') {
			$hour = GETPOSTINT($prefix.'hour');
			$minute = GETPOSTINT($prefix.'min');
			$second = GETPOSTINT($prefix.'sec');
		} elseif (preg_match('/^(\d\d):(\d\d):(\d\d)$/', $hourTime, $m)) {
			$hour = intval($m[1]);
			$minute = intval($m[2]);
			$second = intval($m[3]);
		} else {
			$hour = $minute = $second = 0;
		}
		// normalize out of range values
		$hour = min($hour, 23);
		$minute = min($minute, 59);
		$second = min($second, 59);

		return dol_mktime($hour, $minute, $second, GETPOSTINT($prefix.'month'), GETPOSTINT($prefix.'day'), GETPOSTINT($prefix.'year'), $gm);
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
	function GETPOSTFLOAT($paramname, $rounding = '', $option = 2)  // @phan-suppress-current-line PhanRedefineFunction
	{
		// price2num() can be used to round to an expected accuracy and/or to sanitize any valid user input (such as "1 234.5", "1 234,5", "1'234,5", "1·234,5", "1,234.5", etc.)
		return (float) price2num(GETPOST($paramname), $rounding, $option);
	}
}

if (!function_exists('getDolGlobalFloat')) {
	/**
	 *  Return a Dolibarr global constant value, converted into float.
	 *  Provided as a polyfill for Dolibarr < 21, where this function does not exist yet.
	 *
	 *  @param  string  $key        Name of the constant
	 *  @param  float   $default    Default value if constant is not defined
	 *  @return float               Value converted into float
	 *  @since  Dolibarr V21
	 */
	function getDolGlobalFloat($key, $default = 0)  // @phan-suppress-current-line PhanRedefineFunction
	{
		global $conf;
		return (float) (isset($conf->global->$key) ? $conf->global->$key : $default);
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

if (!function_exists('dolPrintHTML')) {
	/**
	 * Return a string ready to be output on HTML page
	 * To use text inside an attribute, use can use only dol_escape_htmltag()
	 *
	 * @param	string	$s		String to print
	 * @return	string			String ready for HTML output
	 */
	function dolPrintHTML($s)  // @phan-suppress-current-line PhanRedefineFunction
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
	function dolPrintHTMLForAttribute($s, $escapeonlyhtmltags = 0, $allowothertags = array())  // @phan-suppress-current-line PhanRedefineFunction
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

		$errors = array();

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


/**
 * Tell whether the seller reports the VAT on debits ("TVA d'apres les debits").
 *
 * That option makes the VAT of a service fall due when the invoice is issued instead of when it is
 * collected, and the seller who took it must carry the corresponding legal mention on its invoices.
 * The scheme itself is not a setting of this module: Dolibarr already holds it in the setup of the
 * Tax/VAT module (Home - Setup - Modules - Tax/VAT, "VAT mode"), where "TVA d'apres les debits" is
 * TAX_MODE 1, the one that puts both sell modes on 'invoice'. Conf::setValues() always populates the
 * two constants, defaulting to the French standard scheme.
 *
 * There is no option of this module to override it, deliberately: the same two constants are what the
 * VAT report of Dolibarr declares on (compta/tva/, through tax.lib.php), so an override would make the
 * document tell the buyer one regime while the seller declares its VAT under another.
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
 * BT-8 tells the buyer when the VAT falls due, hence from when it may be deducted. BR-CL-06 restricts
 * it to a subset of UNTDID 2475 - 5 (invoice date), 29 (delivery date) and 72 (payment date) - and
 * BR-CO-03 makes it mutually exclusive with BT-7, the VAT point date itself. In CII the code lives in
 * the VAT breakdown (BG-23), which repeats per rate, but only one distinct value may appear in the
 * whole document (CII-SR-462): it is a document-level decision.
 *
 * What the French socle asks of it is narrower than the semantics of the codes, and it is what this
 * function follows. XP Z12-012 annexe A introduces BT-8 as the "champ permettant de specifier l'option
 * pour le paiement de la taxe d'apres les debits", reads its three values as "5 : date de la facture
 * (TVA sur DEBITS)", "29 : date de livraison (TVA sur DEBITS)" and "72 : date de paiement (TVA sur
 * ENCAISSEMENTS)", and carries two rules on it:
 *
 * @phpcs:ignore
 *   G1.43        "Le BT-8 ne sera obligatoire que si l'entreprise a opte pour la TVA sur les debits
 *                 et le specifie au moyen du code 5 (CII)"
 * @phpcs:ignore
 *   BR-FR-MAP-03 "BT-8 est obligatoire pour les factures de service des lors que l'assujetti Vendeur
 *                 a opte pour les debits"
 *
 * So 5 is not "this invoice is payable now", it is "I took the debits option", and a seller who did
 * not take it must not send it. That is why a goods invoice of a seller under the standard scheme
 * declares nothing: it has no option to signal, and its VAT falls due on a delivery the document
 * already dates. 72 is not mandatory anywhere, but it is true of an operation taxed on collection and
 * the annexe B examples carry it on every services invoice, so it is sent.
 *
 *   TAX_MODE 0, the French default   products on invoice, services on payment  -> 72 on a service line
 *   TAX_MODE 1, "d'apres les debits" everything on invoice                     -> 5
 *   TAX_MODE 2                       everything on payment                     -> 72
 *
 * 29 is never sent. It says the same thing as 5, BR-FR-MAP-29 states that "le PPF attend uniquement 5"
 * - a 29 having to be reported as 5 to the public portal - and Dolibarr has nothing to derive it from
 * anyway: its own setup reads the goods delivery as "OnDelivery (SupposedToBeInvoiceDate)".
 *
 * None of this is a setting of this module. Dolibarr holds the scheme once, in admin/taxes.php, and
 * that same setting decides how its VAT report is built, so a second place to state it would be a
 * second place to state it differently: a document declaring the debits option to the buyer while the
 * seller declares its VAT on collection, or the reverse. This is the same reason there is no option
 * for the VAT regime of the seller (BT-31 / BT-32) - see einvoicingSellerVatRegime().
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
 * Close to the question BT-8 answers, but not the same one: BT-8 is what the French socle makes of it,
 * the declaration of the debits option, while this is the plain fact of when the VAT falls due. They
 * part company on the down payment of a seller who took the option, whose document declares 5 because
 * the option is general (G1.43), while the down payment itself is still taxed on collection - the
 * option "ne peut avoir pour effet de retarder l'exigibilite".
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
 * EN 16931 lets a seller identify itself for tax purposes in two ways, and a document carries the one
 * that matches its regime:
 *
 *   BT-31  Seller VAT identifier            ram:SpecifiedTaxRegistration/ram:ID[@schemeID='VA']
 *   BT-32  Seller tax registration identif. ram:SpecifiedTaxRegistration/ram:ID[@schemeID='FC']
 *
 * A seller that charges VAT declares BT-31. A seller that does not - franchise en base de TVA of the
 * micro-entrepreneur, and more generally the "Non assujetti a la TVA" setup of Dolibarr - has no VAT
 * identifier to declare, and BT-32 is what the standard leaves it: in France, its SIREN. Without one
 * or the other, every exempt line of the document trips BR-E-02 and the platform refuses it, which is
 * the whole of issue #560.
 *
 * This is deliberately not a setting of this module, and there is no option to override it: Dolibarr
 * already holds the regime in the setup of the company (Home - Setup - Company/Organization, the "VAT
 * is used / is not used" radio, which admin/company.php writes into FACTURE_TVAOPTION as 1 or 0), and a
 * second place to state the same thing is a second place for it to be stated differently. Nothing is
 * re-derived from that constant here either: Societe::setMysoc() already turns it into ->tva_assuj, and
 * getCategoryRate() already decides from that same ->tva_assuj whether a line is exempt. Reading the
 * property the core computed is what keeps the two from ever disagreeing - a document declaring an
 * exempt line while claiming a VAT registration, or the reverse.
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
 * Tax registrations (BT-31 / BT-32) the seller declares, in the shape the two writers consume.
 *
 * One entry, because the two identifiers answer the same question and a document that carried both
 * would be claiming a VAT registration it does not use. Which one is decided by the regime rather
 * than by "is a VAT number recorded": a seller subject to VAT that simply left the field empty must
 * keep getting the explicit BADVATNUMBER message that names what to fill in, not a silent fallback on
 * its SIREN (issue #560).
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
 * Dolibarr has no invoicing period at invoice level: the period lives on the line, as the date_start
 * and date_end a service line carries. EN 16931 has both - BT-134/BT-135 on the line, BT-73/BT-74 on
 * the header - and says nothing about deriving one from the other, so the derivation is a decision:
 * the document covers everything its lines cover, hence the earliest start and the latest end (issue
 * #572, option 1, chosen by the maintainer because most receiving software only reads the header).
 *
 * A period that would be its own contradiction is not emitted. One line billed from March with another
 * billed until January derives a start after its end, which BR-29 refuses ("The Invoicing period end
 * date shall be later or equal to the Invoicing period start date") - and a document refused whole for
 * a header the operator never filled in would be worse than not deriving anything. The lines keep
 * their own periods in that case, which is what happened before this existed.
 *
 * The argument is the accumulator buildinvoicelines.inc.php fills as it walks the lines:
 * ['start' => [<numligne> => <timestamp>, ...], 'end' => [...]], either key absent when no line has
 * that side.
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
