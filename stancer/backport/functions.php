<?php
require_once DOL_DOCUMENT_ROOT.'/core/lib/security2.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/functions.lib.php';

/**
 * Is Dolibarr module enabled
 *
 * @param string $module module name to check
 * @return int
 */
if (!function_exists('isModEnabled')) {
	function isModEnabled($module)
	{
		global $conf;
		return !empty($conf->$module->enabled);
	}
}

if (!function_exists('dolJSToSetRandomPassword')) {


	/**
	 * Ouput javacript to autoset a generated password using default module into a HTML element.
	 *
	 * @param		string 		$htmlname			HTML name of element to insert key into
	 * @param		string		$htmlnameofbutton	HTML name of button
	 * @param		int			$generic			1=Return a generic pass, 0=Return a pass following setup rules
	 * @return		string		    				HTML javascript code to set a password
	 * @see getRandomPassword()
	 */
	function dolJSToSetRandomPassword($htmlname, $htmlnameofbutton = 'generate_token', $generic = 1)
	{
		global $conf;

		$html = "";
		if (!empty($conf->use_javascript_ajax)) {
			$html .= "\n".'<!-- Js code to suggest a security key --><script type="text/javascript">';
			$html .= '$(document).ready(function () {
            $("#'.dol_escape_js($htmlnameofbutton).'").click(function() {
				console.log("We click on the button '.dol_escape_js($htmlnameofbutton).' to suggest a key. We will fill '.dol_escape_js($htmlname).'");
            	$.get( "'.DOL_URL_ROOT.'/core/ajax/security.php", {
            		action: \'getrandompassword\',
            		generic: '.($generic ? '1' : '0').',
					token: \''.dol_escape_js(newToken()).'\'
				},
				function(result) {
					$("#'.dol_escape_js($htmlname).'").val(result);
				});
            });
		});'."\n";
			$html .= '</script>';
		}

		print $html;
		$html = "";
		return $html;
	}
}
