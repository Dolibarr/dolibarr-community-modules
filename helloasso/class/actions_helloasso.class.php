<?php
/* Copyright (C) 2024      Lucas Marcouiller    <lmarcouiller@dolicloud.com>
 * Copyright (C) 2025 	   Pablo Lagrave           <contact@devlandes.com>
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
 * \file    helloasso/class/actions_helloasso.class.php
 * \ingroup helloasso
 * \brief   Example hook overload.
 *
 * Put detailed description here.
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonhookactions.class.php';


/**
 * Class ActionsHelloAsso
 */
class ActionsHelloAsso extends CommonHookActions
{
	/**
	 * @var DoliDB Database handler.
	 */
	public $db;

	/**
	 * @var string Error code (or message)
	 */
	public $error = '';

	/**
	 * @var array Errors
	 */
	public $errors = array();


	/**
	 * @var array Hook results. Propagated to $hookmanager->resArray for later reuse
	 */
	public $results = array();

	/**
	 * @var string String displayed by executeHook() immediately after return
	 */
	public $resprints;

	/**
	 * @var int		Priority of hook (50 is used if value is not defined)
	 */
	public $priority;


	/**
	 * Constructor
	 *
	 *  @param		DoliDB		$db      Database handler
	 */
	public function __construct($db)
	{
		global $langs;
		$this->db = $db;
		$langs->load("helloasso@helloasso");
	}


	/**
	 * Execute action
	 *
	 * @param	array			$parameters		Array of parameters
	 * @param	CommonObject    $object         The object to process (an invoice if you are in invoice module, a propale in propale's module, etc...)
	 * @param	string			$action      	'add', 'update', 'view'
	 * @return	int         					Return integer <0 if KO,
	 *                           				=0 if OK but we want to process standard actions too,
	 *                            				>0 if OK and we want to replace standard actions.
	 */
	public function getNomUrl($parameters, &$object, &$action)
	{
		$this->resprints = '';
		return 0;
	}

	/**
	 * Overloading the doActions function : replacing the parent's function with the one below
	 *
	 * @param   array           $parameters     Hook metadatas (context, etc...)
	 * @param   CommonObject    $object         The object to process (an invoice if you are in invoice module, a propale in propale's module, etc...)
	 * @param   string          $action         Current action (if set). Generally create or edit or null
	 * @param   HookManager     $hookmanager    Hook manager propagated to allow calling another hook
	 * @return  int                             Return integer < 0 on error, 0 on success, 1 to replace standard code
	 */
	public function doActions($parameters, &$object, &$action, $hookmanager)
	{
		$error = 0; // Error counter

		/* print_r($parameters); print_r($object); echo "action: " . $action; */
		if (in_array($parameters['currentcontext'], array('somecontext1', 'somecontext2'))) {		// do something only for the context 'somecontext1' or 'somecontext2'
			foreach ($parameters['toselect'] as $objectid) {
				// Do action on each object id
			}

			if (!$error) {
				$this->results = array('myreturn' => 999);
				$this->resprints = 'A text to show';
				return 0; // or return 1 to replace standard code
			} else {
				$this->errors[] = 'Error message';
				return -1;
			}
		}

		return 0;
	}


	/**
	 * Overloading the doMassActions function : replacing the parent's function with the one below
	 *
	 * @param   array           $parameters     Hook metadatas (context, etc...)
	 * @param   CommonObject    $object         The object to process (an invoice if you are in invoice module, a propale in propale's module, etc...)
	 * @param   string          $action         Current action (if set). Generally create or edit or null
	 * @param   HookManager     $hookmanager    Hook manager propagated to allow calling another hook
	 * @return  int                             Return integer < 0 on error, 0 on success, 1 to replace standard code
	 */
	public function doMassActions($parameters, &$object, &$action, $hookmanager)
	{
		$error = 0; // Error counter

		/* print_r($parameters); print_r($object); echo "action: " . $action; */
		if (in_array($parameters['currentcontext'], array('somecontext1', 'somecontext2'))) {		// do something only for the context 'somecontext1' or 'somecontext2'
			foreach ($parameters['toselect'] as $objectid) {
				// Do action on each object id
			}

			if (!$error) {
				$this->results = array('myreturn' => 999);
				$this->resprints = 'A text to show';
				return 0; // or return 1 to replace standard code
			} else {
				$this->errors[] = 'Error message';
				return -1;
			}
		}

		return 0;
	}

	/**
	 * Overloading the addMoreMassActions function : replacing the parent's function with the one below
	 *
	 * @param   array           $parameters     Hook metadatas (context, etc...)
	 * @param   CommonObject    $object         The object to process (an invoice if you are in invoice module, a propale in propale's module, etc...)
	 * @param   string          $action         Current action (if set). Generally create or edit or null
	 * @param   HookManager     $hookmanager    Hook manager propagated to allow calling another hook
	 * @return  int                             Return integer < 0 on error, 0 on success, 1 to replace standard code
	 */
	public function addMoreMassActions($parameters, &$object, &$action, $hookmanager)
	{
		global $langs;

		$error = 0; // Error counter
		$disabled = 1;

		/* print_r($parameters); print_r($object); echo "action: " . $action; */
		if (in_array($parameters['currentcontext'], array('somecontext1', 'somecontext2'))) {		// do something only for the context 'somecontext1' or 'somecontext2'
			$this->resprints = '<option value="0"'.($disabled ? ' disabled="disabled"' : '').'>'.$langs->trans("HelloAssoMassAction").'</option>';
		}

		if (!$error) {
			return 0; // or return 1 to replace standard code
		} else {
			$this->errors[] = 'Error message';
			return -1;
		}
	}



	/**
	 * Execute action
	 *
	 * @param	array	$parameters     Array of parameters
	 * @param   Object	$object		   	Object output on PDF
	 * @param   string	$action     	'add', 'update', 'view'
	 * @return  int 		        	Return integer <0 if KO,
	 *                          		=0 if OK but we want to process standard actions too,
	 *  	                            >0 if OK and we want to replace standard actions.
	 */
	public function beforePDFCreation($parameters, &$object, &$action)
	{
		$ret = 0;
		dol_syslog(get_class($this).'::executeHooks action='.$action);

		/* print_r($parameters); print_r($object); echo "action: " . $action; */
		if (in_array($parameters['currentcontext'], array('somecontext1', 'somecontext2'))) {
			// do something only for the context 'somecontext1' or 'somecontext2'
		}

		return $ret;
	}

	/**
	 * Execute action
	 *
	 * @param	array	$parameters     Array of parameters
	 * @param   Object	$pdfhandler     PDF builder handler
	 * @param   string	$action         'add', 'update', 'view'
	 * @return  int 		            Return integer <0 if KO,
	 *                                  =0 if OK but we want to process standard actions too,
	 *                                  >0 if OK and we want to replace standard actions.
	 */
	public function afterPDFCreation($parameters, &$pdfhandler, &$action)
	{
		$ret = 0;
		dol_syslog(get_class($this).'::executeHooks action='.$action);

		/* print_r($parameters); print_r($object); echo "action: " . $action; */
		if (in_array($parameters['currentcontext'], array('somecontext1', 'somecontext2'))) {
			// do something only for the context 'somecontext1' or 'somecontext2'
		}

		return $ret;
	}



	/**
	 * Overloading the loadDataForCustomReports function : returns data to complete the customreport tool
	 *
	 * @param   array           $parameters     Hook metadatas (context, etc...)
	 * @param   string          $action         Current action (if set). Generally create or edit or null
	 * @param   HookManager     $hookmanager    Hook manager propagated to allow calling another hook
	 * @return  int                             Return integer < 0 on error, 0 on success, 1 to replace standard code
	 */
	public function loadDataForCustomReports($parameters, &$action, $hookmanager)
	{
		global $langs;

		$langs->load("helloasso@helloasso");

		$this->results = array();

		$head = array();
		$h = 0;

		if ($parameters['tabfamily'] == 'helloasso') {
			$head[$h][0] = dol_buildpath('/module/index.php', 1);
			$head[$h][1] = $langs->trans("Home");
			$head[$h][2] = 'home';
			$h++;

			$this->results['title'] = $langs->trans("HelloAsso");
			$this->results['picto'] = 'helloasso@helloasso';
		}

		$head[$h][0] = 'customreports.php?objecttype='.$parameters['objecttype'].(empty($parameters['tabfamily']) ? '' : '&tabfamily='.$parameters['tabfamily']);
		$head[$h][1] = $langs->trans("CustomReports");
		$head[$h][2] = 'customreports';

		$this->results['head'] = $head;

		return 1;
	}



	/**
	 * Overloading the restrictedArea function : check permission on an object
	 *
	 * @param   array           $parameters     Hook metadatas (context, etc...)
	 * @param   string          $action         Current action (if set). Generally create or edit or null
	 * @param   HookManager     $hookmanager    Hook manager propagated to allow calling another hook
	 * @return  int 		      			  	Return integer <0 if KO,
	 *                          				=0 if OK but we want to process standard actions too,
	 *  	                            		>0 if OK and we want to replace standard actions.
	 */
	public function restrictedArea($parameters, &$action, $hookmanager)
	{
		global $user;

		if ($parameters['features'] == 'myobject') {
			if ($user->hasRight('helloasso', 'myobject', 'read')) {
				$this->results['result'] = 1;
				return 1;
			} else {
				$this->results['result'] = 0;
				return 1;
			}
		}

		return 0;
	}

	/**
	 * Execute action completeTabsHead
	 *
	 * @param   array           $parameters     Array of parameters
	 * @param   CommonObject    $object         The object to process (an invoice if you are in invoice module, a propale in propale's module, etc...)
	 * @param   string          $action         'add', 'update', 'view'
	 * @param   Hookmanager     $hookmanager    hookmanager
	 * @return  int                             Return integer <0 if KO,
	 *                                          =0 if OK but we want to process standard actions too,
	 *                                          >0 if OK and we want to replace standard actions.
	 */
	public function completeTabsHead(&$parameters, &$object, &$action, $hookmanager)
	{
		global $langs;

		if (!isset($parameters['object']->element)) {
			return 0;
		}
		if ($parameters['mode'] == 'remove') {
			// used to make some tabs removed
			return 0;
		} elseif ($parameters['mode'] == 'add') {
			$langs->load('helloasso@helloasso');
			// used when we want to add some tabs
			$counter = count($parameters['head']);
			$element = $parameters['object']->element;
			$id = $parameters['object']->id;
			// verifier le type d'onglet comme member_stats où ça ne doit pas apparaitre
			// if (in_array($element, ['societe', 'member', 'contrat', 'fichinter', 'project', 'propal', 'commande', 'facture', 'order_supplier', 'invoice_supplier'])) {
			if (in_array($element, ['context1', 'context2'])) {
				$datacount = 0;

				$parameters['head'][$counter][0] = dol_buildpath('/helloasso/helloasso_tab.php', 1) . '?id=' . $id . '&amp;module='.$element;
				$parameters['head'][$counter][1] = $langs->trans('HelloAssoTab');
				if ($datacount > 0) {
					$parameters['head'][$counter][1] .= '<span class="badge marginleftonlyshort">' . $datacount . '</span>';
				}
				$parameters['head'][$counter][2] = 'helloassoemails';
				$counter++;
			}
			if ($counter > 0 && (int) DOL_VERSION < 14) {
				$this->results = $parameters['head'];
				// return 1 to replace standard code
				return 1;
			} else {
				// en V14 et + $parameters['head'] est modifiable par référence
				return 0;
			}
		} else {
			// Bad value for $parameters['mode']
			return -1;
		}
	}

	/* Add here any other hooked methods... */
	/**
	 * Overloading the doAddButton function : replacing the parent's function with the one below
	 *
	 * @param   array           $parameters     Hook metadatas (context, etc...)
	 * @param   CommonObject    $object         The object to process (an invoice if you are in invoice module, a propale in propale's module, etc...)
	 * @param   string          $action         Current action (if set). Generally create or edit or null
	 * @param   HookManager     $hookmanager    Hook manager propagated to allow calling another hook
	 * @return  int                             Return integer < 0 on error, 0 on success, 1 to replace standard code
	 */
	public function doAddButton($parameters, &$object, &$action, $hookmanager)
	{
		global $langs;

		$error = 0; // Error counter
		$resprints = "";
		$error = "";

		if (array_key_exists("paymentmethod", $parameters) && (empty($parameters["paymentmethod"]) || $parameters["paymentmethod"] == 'helloasso') && isModEnabled('helloasso')) {
			if (!getDolGlobalString('HELLOASSO_LIVE')) {
				dol_htmloutput_mesg($langs->trans('YouAreCurrentlyInSandboxMode', 'HelloAsso'), [], 'warning');
			}
			if (!getDolGlobalBool('HELLOASSO_STANDAR_BTN')) {
				$resprints .= '<div class="button buttonpayment" id="div_dopayment_helloasso"><span class="fa fa-credit-card"></span> <input class="" type="submit" id="dopayment_helloasso" name="dopayment_helloasso" value="'.$langs->trans("HelloAssoDoPayment").'">';
				$resprints .= '<input type="hidden" name="noidempotency" value="'.GETPOST('noidempotency', 'int').'">';
				$resprints .= '<input type="hidden" name="s" value="'.(GETPOST('s', 'alpha') ? GETPOST('s', 'alpha') : GETPOST('source', 'alpha')).'">';
				$resprints .= '<input type="hidden" name="ref" value="'.GETPOST('ref').'">';
				$resprints .= '<br>';
				$resprints .= '<span class="buttonpaymentsmall">'.$langs->trans("CreditOrDebitCard").'</span>';
				$resprints .= '</div>';
				$resprints .= '<script>
								$( document ).ready(function() {
									$("#div_dopayment_helloasso").click(function(){
										$("#dopayment_helloasso").click();
									});
									$("#dopayment_helloasso").click(function(e){
										$("#div_dopayment_helloasso").css( \'cursor\', \'wait\' );
										e.stopPropagation();
										return true;
									});
								});
							</script>
				';
			} else {
				// Bouton au format standard HelloAsso (avec logo) https://dev.helloasso.com/docs/bouton-payer-avec-helloasso
				$resprints .= '<div class="HaPay" id="div_dopayment_helloasso">';
				$resprints .= '  <button type="submit" class="HaPayButton" id="dopayment_helloasso" name="dopayment_helloasso" value="'.$langs->trans("HelloAssoDoPayment").'">';
				$resprints .= '    <img src="https://api.helloasso.com/v5/img/logo-ha.svg" alt="" class="HaPayButtonLogo" />';
				$resprints .= '    <div class="HaPayButtonLabel">';
				$resprints .= '      <span> Payer avec </span>';
				$resprints .= '      <svg width="73" height="14" viewBox="0 0 73 14" fill="none" xmlns="http://www.w3.org/2000/svg">';
				$resprints .= '        <path
              d="M72.9992 8.78692C72.9992 11.7371 71.242 13.6283 68.4005 13.6283C65.5964 13.6283 63.8018 11.9073 63.8018 8.74909C63.8018 5.79888 65.559 3.90771 68.4005 3.90771C71.2046 3.90771 72.9992 5.64759 72.9992 8.78692ZM67.2041 8.74909C67.2041 10.5457 67.5779 11.2265 68.4005 11.2265C69.223 11.2265 69.5969 10.5079 69.5969 8.78692C69.5969 6.99031 69.223 6.30949 68.4005 6.30949C67.5779 6.30949 67.1854 7.04705 67.2041 8.74909Z"
            />';
				$resprints .= '        <path
              d="M62.978 5.08045L61.8003 6.89597C61.1647 6.47991 60.4356 6.25297 59.6692 6.23406C59.1084 6.23406 58.9214 6.40426 58.9214 6.65011C58.9214 6.9527 59.0149 7.08508 60.716 7.61461C62.4172 8.14413 63.3332 8.88169 63.3332 10.527C63.3332 12.3803 61.576 13.6474 59.1084 13.6474C57.5381 13.6474 56.0986 13.0801 55.1826 12.2101L56.7529 10.4514C57.3885 10.962 58.211 11.3402 59.0336 11.3402C59.6131 11.3402 59.9683 11.1511 59.9683 10.7918C59.9683 10.3568 59.7813 10.2622 58.2484 9.78945C56.5847 9.27883 55.65 8.31434 55.65 6.85814C55.65 5.23174 57.0333 3.92684 59.5383 3.92684C60.8656 3.90793 62.1555 4.36181 62.978 5.08045Z"
            />';
				$resprints .= '        <path
              d="M54.7358 5.08045L53.5581 6.89597C52.9225 6.47991 52.1934 6.25297 51.427 6.23406C50.8662 6.23406 50.6792 6.40426 50.6792 6.65011C50.6792 6.9527 50.7727 7.08508 52.4738 7.61461C54.175 8.14413 55.091 8.88169 55.091 10.527C55.091 12.3803 53.3338 13.6474 50.8662 13.6474C49.2959 13.6474 47.8564 13.0801 46.9404 12.2101L48.5107 10.4514C49.1463 10.962 49.9689 11.3402 50.7914 11.3402C51.3709 11.3402 51.7261 11.1511 51.7261 10.7918C51.7261 10.3568 51.5391 10.2622 50.0062 9.78945C48.3238 9.27883 47.4078 8.31434 47.4078 6.85814C47.4078 5.23174 48.7911 3.92684 51.2961 3.92684C52.6234 3.90793 53.9133 4.36181 54.7358 5.08045Z"
            />';
				$resprints .= '       <path
              d="M46.7721 11.4156L46.0991 13.5526C44.9401 13.477 44.1923 13.1555 43.6876 12.3045C43.0333 13.3068 42.0051 13.6283 40.9956 13.6283C39.201 13.6283 38.042 12.418 38.042 10.7537C38.042 8.74909 39.5375 7.65222 42.3603 7.65222H42.9959V7.42528C42.9959 6.51752 42.6968 6.27167 41.706 6.27167C40.9209 6.30949 40.1357 6.4797 39.4067 6.74446L38.6963 4.62636C39.8179 4.17248 41.0143 3.94554 42.2294 3.90771C45.0709 3.90771 46.23 5.00459 46.23 7.23616V10.3566C46.23 10.9996 46.3795 11.2643 46.7721 11.4156ZM43.0146 10.7348V9.39209H42.6594C41.7247 9.39209 41.2947 9.71359 41.2947 10.4133C41.2947 10.9239 41.5752 11.2643 42.0238 11.2643C42.4164 11.2643 42.7903 11.0563 43.0146 10.7348Z"
            />';
				$resprints .= '    <path
              d="M37.5363 8.78692C37.5363 11.7371 35.7791 13.6283 32.9376 13.6283C30.1335 13.6283 28.3389 11.9073 28.3389 8.74909C28.3389 5.79888 30.0961 3.90771 32.9376 3.90771C35.7417 3.90771 37.5363 5.64759 37.5363 8.78692ZM31.7412 8.74909C31.7412 10.5457 32.1151 11.2265 32.9376 11.2265C33.7601 11.2265 34.134 10.5079 34.134 8.78692C34.134 6.99031 33.7601 6.30949 32.9376 6.30949C32.1151 6.30949 31.7225 7.04705 31.7412 8.74909Z"
            />;';
				$resprints .= '       <path
              d="M23.8154 10.6972V0.692948L27.1243 0.352539V10.527C27.1243 10.8296 27.2551 10.9809 27.5355 10.9809C27.6477 10.9809 27.7786 10.962 27.8907 10.9052L28.4889 13.2881C27.8907 13.4961 27.2738 13.6096 26.6569 13.5907C24.8249 13.6285 23.8154 12.5505 23.8154 10.6972Z"
            />';
			$resprints .='<path
              d="M18.8057 10.6972V0.692948L22.1145 0.352539V10.527C22.1145 10.8296 22.2454 10.9809 22.5071 10.9809C22.6192 10.9809 22.7501 10.962 22.8623 10.9052L23.4418 13.2881C22.8436 13.4961 22.2267 13.6096 21.6098 13.5907C19.8151 13.6285 18.8057 12.5505 18.8057 10.6972Z"
            /> ';
			$resprints .=' <path
              d="M17.9071 9.71359H12.4859C12.6728 11.0185 13.3084 11.2454 14.2805 11.2454C14.9161 11.2454 15.533 10.9807 16.2994 10.4511L17.6454 12.2856C16.6172 13.1555 15.3087 13.6283 13.9627 13.6283C10.6912 13.6283 9.13965 11.5858 9.13965 8.78692C9.13965 6.13929 10.6352 3.90771 13.5888 3.90771C16.2247 3.90771 17.9632 5.60976 17.9632 8.63562C17.9819 8.93821 17.9445 9.39209 17.9071 9.71359ZM14.7291 7.70895C14.7105 6.80119 14.5235 6.04473 13.6823 6.04473C12.9719 6.04473 12.6167 6.46079 12.4859 7.84134H14.7291V7.70895Z"
            /> ';
			$resprints .='<path
              d="M8.24307 6.61229V13.2692H4.93423V7.21746C4.93423 6.49882 4.7286 6.32862 4.4295 6.32862C4.07431 6.32862 3.70043 6.61229 3.30786 7.21746V13.2503H-0.000976562V0.692948L3.30786 0.352539V5.06154C4.07431 4.24834 4.82207 3.90793 5.83154 3.90793C7.32706 3.90793 8.24307 4.89133 8.24307 6.61229Z"
            /> ';
				$resprints .= '      </svg>';
				$resprints .= '    </div>';
				$resprints .= '  </button>';
				$resprints .= '  <div class="HaPaySecured">';
				$resprints .= '    <svg width="9" height="10" viewBox="0 0 11 12" fill="none" xmlns="http://www.w3.org/2000/svg">';
				$resprints .= '      <path d="M3.875 3V4.5H7.625V3C7.625 1.96875 6.78125 1.125 5.75 1.125C4.69531 1.125 3.875 1.96875 3.875 3Z"/>';
				$resprints .= '    </svg>';
				$resprints .= '    <span>Paiement sécurisé</span>';
				$resprints .= '    <img src="https://helloassodocumentsprod.blob.core.windows.net/public-documents/bouton_payer_avec_helloasso/logo-visa.svg" alt="Logo Visa" />';
				$resprints .= '    <img src="https://helloassodocumentsprod.blob.core.windows.net/public-documents/bouton_payer_avec_helloasso/logo-mastercard.svg" alt="Logo Mastercard" />';
				$resprints .= '    <img src="https://helloassodocumentsprod.blob.core.windows.net/public-documents/bouton_payer_avec_helloasso/logo-cb.svg" alt="Logo CB" />';
				$resprints .= '    <img src="https://helloassodocumentsprod.blob.core.windows.net/public-documents/bouton_payer_avec_helloasso/logo-pci.svg" alt="Logo PCI" />';
				$resprints .= '  </div>';
				$resprints .= '</div>';
				$resprints .= '<script>
					$(document).ready(function() {
						$("#div_dopayment_helloasso").click(function(){
							$("#dopayment_helloasso").click();
						});
						$("#dopayment_helloasso").click(function(e){
							$("#div_dopayment_helloasso").css("cursor", "wait");
							e.stopPropagation();
							return true;
						});
					});
				</script>';
				$resprints .= '<input type="hidden" name="noidempotency" value="'.GETPOST('noidempotency', 'int').'">';
				$resprints .= '<input type="hidden" name="s" value="'.(GETPOST('s', 'alpha') ? GETPOST('s', 'alpha') : GETPOST('source', 'alpha')).'">';
				$resprints .= '<input type="hidden" name="ref" value="'.GETPOST('ref').'">';

			}

			if (!$error) {
				$this->resprints = $resprints;
				return 0; // or return 1 to replace standard code
			} else {
				$this->errors[] = $error;
				return -1;
			}
		}

		return 0;
	}

	/* Add here any other hooked methods... */
	/**
	 * Overloading the getValidPayment function : replacing the parent's function with the one below
	 *
	 * @param   array           $parameters     Hook metadatas (context, etc...)
	 * @param   CommonObject    $object         The object to process (an invoice if you are in invoice module, a propale in propale's module, etc...)
	 * @param   string          $action         Current action (if set). Generally create or edit or null
	 * @param   HookManager     $hookmanager    Hook manager propagated to allow calling another hook
	 * @return  int                             Return integer < 0 on error, 0 on success, 1 to replace standard code
	 */
	public function getValidPayment($parameters, &$object, &$action, $hookmanager)
	{
		global $langs;

		$error = "";
		$validpaymentmethod = array();

		if (array_key_exists("paymentmethod", $parameters) && (empty($parameters["paymentmethod"]) || $parameters["paymentmethod"] == 'helloasso') && isModEnabled('helloasso')) {
			$langs->load("helloasso");
			if (!empty($parameters['mode'])) {
				$validpaymentmethod['helloasso'] = array('label' => 'HelloAsso', 'status' => 'valid');
			} else {
				$validpaymentmethod['helloasso'] = 'valid';
			}
		}

		if (!$error) {
			if (!empty($validpaymentmethod)) {
				$this->results["validpaymentmethod"] = $validpaymentmethod;
			}
			return 0;
		} else {
			$this->errors[] = $error;
			return -1;
		}
	}

	/**
	 * Overloading the doPayment function : replacing the parent's function with the one below
	 *
	 * @param   array           $parameters     Hook metadatas (context, etc...)
	 * @param   CommonObject    $object         The object to process (an invoice if you are in invoice module, a propale in propale's module, etc...)
	 * @param   string          $action         Current action (if set). Generally create or edit or null
	 * @param   HookManager     $hookmanager    Hook manager propagated to allow calling another hook
	 * @return  int                             Return integer < 0 on error, 0 on success, 1 to replace standard code
	 */
	public function doPayment($parameters, &$object, &$action, $hookmanager)
	{
		global $conf, $langs;

		dol_include_once('helloasso/lib/helloasso.lib.php');

		$resprints = "";

		$error = 0; // Error counter
		$errors = array();

		$urlwithroot = DOL_MAIN_URL_ROOT; // This is to use same domain name than current. For Paypal payment, we can use internal URL like localhost.

		// Complete urls for post treatment
		$ref = $REF = GETPOST('ref', 'alpha');
		$TAG = GETPOST("tag", 'alpha');
		$FULLTAG = GETPOST("fulltag", 'alpha'); // fulltag is tag with more informations
		$SECUREKEY = GETPOST("securekey"); // Secure key
		$source = GETPOST('s', 'alpha') ? GETPOST('s', 'alpha') : GETPOST('source', 'alpha');
		$suffix = GETPOST('suffix'); 	// ???
		$entity = GETPOST('entity');
		$getpostlang = GETPOST('lang');
		$amount = price2num(GETPOST("amount", 'alpha'));

		$object = null;

		if ($action == "returnDoPaymentHelloAsso") {
			dol_syslog("Data after redirect from helloasso payment page with session FinalPaymentAmt = ".$_SESSION["FinalPaymentAmt"]." currencycodeType = ".$_SESSION["currencyCodeType"], LOG_DEBUG);

			$urlredirect = $urlwithroot.'/public/payment/';
			$typereturn = GETPOST("typereturn");
			if ($typereturn == "error") {
				$urlredirect .= "paymentko.php?fulltag=".urlencode($FULLTAG);
				header("Location: ".$urlredirect);
				exit;
			} elseif ($typereturn == "return") {
				$code = GETPOST("code");
				$urlredirect .= "paymentok.php?fulltag=".urlencode($FULLTAG).'&code='.urlencode($code);
				header("Location: ".$urlredirect);
				exit;
			}
		}

		if (in_array($parameters['context'],array('newpayment')) && empty($parameters['paymentmethod'])) {
			$amount = price2num(helloassoGetDataFromObjects($source, $ref));
			if (!GETPOST("currency", 'alpha')) {
				$currency = $conf->currency;
			} else {
				$currency = GETPOST("currency", 'aZ09');
			}
			$_SESSION["FinalPaymentAmt"] = $amount;
			$_SESSION["currencyCodeType"] = $currency;

		} elseif (in_array($parameters['paymentmethod'], array('helloasso')) && $parameters['validpaymentmethod']["helloasso"] == "valid") {
			require_once DOL_DOCUMENT_ROOT."/core/lib/geturl.lib.php";
			$urlback = $urlwithroot.'/public/payment/newpayment.php?';

			if (!preg_match('/^https:/i', $urlback)) {
				$langs->load("errors");
				$error++;
				$errors[] = $langs->trans("WarningAvailableOnlyForHTTPSServers");
			}

			//Verify if Helloasso module is in test mode
			if (getDolGlobalInt("HELLOASSO_LIVE")) {
				$client_organisation = getDolGlobalString("HELLOASSO_CLIENT_ORGANISATION");
				$helloassourl = "api.helloasso.com";
			} else {
				$client_organisation = getDolGlobalString("HELLOASSO_TEST_CLIENT_ORGANISATION");
				$helloassourl = "api.helloasso-sandbox.com";
			}

			$paymentmethod = $parameters['paymentmethod'];

			if ($paymentmethod && !preg_match('/'.preg_quote('PM='.$paymentmethod, '/').'/', $FULLTAG)) {
				$FULLTAG .= ($FULLTAG ? '.' : '').'PM='.$paymentmethod;
			}
			if (!empty($suffix)) {
				$urlback .= 'suffix='.urlencode($suffix).'&';
			}
			if ($source) {
				$urlback .= 's='.urlencode($source).'&';
			}
			if (!empty($REF)) {
				$urlback .= 'ref='.urlencode($REF).'&';
			}
			if (!empty($TAG)) {
				$urlback .= 'tag='.urlencode($TAG).'&';
			}
			if (!empty($FULLTAG)) {
				$urlback .= 'fulltag='.urlencode($FULLTAG).'&';
			}
			if (!empty($SECUREKEY)) {
				$urlback .= 'securekey='.urlencode($SECUREKEY).'&';
			}
			if (!empty($entity)) {
				$urlback .= 'e='.urlencode($entity).'&';
			}
			if (!empty($getpostlang)) {
				$urlback .= 'lang='.urlencode($getpostlang).'&';
			}
			$urlback .= 'action=returnDoPaymentHelloAsso';

			$result = helloassoDoConnection();

			if ($result <= 0) {
				$errors[] = $langs->trans("ErrorFailedToGetTokenFromClientIdAndSecret");
				$error++;
				$action = '';
			}


			if (!$error) {
				$payerarray = array();
				helloassoGetDataFromObjects($source, $ref, 'payer', $payerarray);

				$FinalPaymentAmt = $_SESSION["FinalPaymentAmt"];
				$amounttotest = $amount;
				if (!$error) {
					//Permit to format the amount string to call HelloAsso API
					$posdot = strpos($amount, '.');
					if ( $posdot === false) {
						$amount .= '00';
					} else {
						$amounttab = explode('.', $amount);
						if (strlen($amounttab[1]) == 1) {
							$amounttab[1] .= "0";
						} else if (strlen($amounttab[1]) > 2) {
							$amounttab[1] = substr($amounttab[1], 0, 2);
						}
						if (isset($amounttab[0])) {
							$val = intval($amounttab[0]);
							if ($val  == 0) {
								$amount = $amounttab[1];
							} else {
								$amount = strval($val) .$amounttab[1];
							}
						} else {
							$amount = $amounttab[1];
						}
					}

					if ($FinalPaymentAmt == $amounttotest) {
						$headers = array();
						$headers[] = "Authorization: ".ucfirst($result["token_type"])." ".$result["access_token"];
						$headers[] = "Accept: application/json";
						$headers[] = "Content-Type: application/json";

						$jsontosenddata = '{
							"totalAmount": '.$amount.',
							"initialAmount": '.$amount.',
							"itemName": "'.dol_escape_js($ref).'",
							"backUrl": "'.$urlback.'&typereturn=back",
							"returnUrl": "'.$urlback.'&typereturn=return",
							"errorUrl": "'.$urlback.'&typereturn=error",
							"containsDonation": false,';

							if (!empty($payerarray)) {
								$jsontosenddata .= '
									"payer": {
										'.(!empty($payerarray['firstName']) ? '"firstName": "'.dol_escape_js($payerarray['firstName']).'",' : '' ).'
										'.(!empty($payerarray['lastName']) ? '"lastName": "'.dol_escape_js($payerarray['lastName']).'",' : '' ).'
										'.(!empty($payerarray['email']) ? '"email": "'.dol_escape_js($payerarray['email']).'",' : '' ).'
										'.(!empty($payerarray['dateOfBirth']) ? '"dateOfBirth": "'.dol_escape_js($payerarray['dateOfBirth']).'",' : '' ).'
										'.(!empty($payerarray['address']) ? '"address": "'.dol_escape_js($payerarray['address']).'",' : '' ).'
										'.(!empty($payerarray['city']) ? '"city": "'.dol_escape_js($payerarray['city']).'",' : '' ).'
										'.(!empty($payerarray['zipCode']) ? '"zipCode": "'.dol_escape_js($payerarray['zipCode']).'",' : '' ).'
										'.(!empty($payerarray['country']) ? '"country": "'.dol_escape_js($payerarray['country']).'",' : '' ).'
										'.(!empty($payerarray['companyName']) ? '"companyName": "'.dol_escape_js($payerarray['companyName']).'",' : '' ).'
									},';
							}
						$jsontosenddata .= '
							"metadata": {
								"source": "'.dol_escape_js($source).'",
								"ref": "'.dol_escape_js($ref).'",
								"ip": "'.dol_escape_js(getUserRemoteIP()).'"
							}';
						$jsontosenddata .= '}';
						//var_dump($jsontosenddata);exit;

						$assoslug = str_replace('_', '-', dol_string_nospecial(strtolower(dol_string_unaccent($client_organisation)), '-'));

						$urlforcheckout = "https://".urlencode($helloassourl)."/v5/organizations/".urlencode($assoslug)."/checkout-intents";

						dol_syslog("Send Post to url=".$urlforcheckout." with session FinalPaymentAmt = ".$FinalPaymentAmt." currencyCodeType = ".$_SESSION["currencyCodeType"], LOG_DEBUG);

						$ret2 = getURLContent($urlforcheckout, 'POSTALREADYFORMATED', $jsontosenddata, 1, $headers);
						if ($ret2["http_code"] == 200) {
							$result2 = $ret2["content"];
							$json2 = json_decode($result2);

							$_SESSION["HelloAssoPaymentId"] = $json2->id;

							dol_syslog("Send redirect to ".$json2->redirectUrl);

							header("Location: ".$json2->redirectUrl);
							exit;
						} else {
							$arrayofmessage = array();
							if (!empty($ret2['content'])) {
								$arrayofmessage = json_decode($ret2['content'], true);
							}
							if (!empty($arrayofmessage['message'])) {
								$errors[] = $arrayofmessage['message'];
							} else {
								if (!empty($arrayofmessage['errors']) && is_array($arrayofmessage['errors'])) {
									foreach($arrayofmessage['errors'] as $tmpkey => $tmpmessage) {
										if (!empty($tmpmessage['message'])) {
											$errors[] = $langs->trans("Error").' - '.$tmpmessage['message'];
										} else {
											$errors[] = $langs->trans("UnkownError").' - HTTP code = '.$ret2["http_code"];
										}
									}
								} else {
									$errors[] = $langs->trans("UnkownError").' - HTTP code = '.$ret2["http_code"];
								}
							}
							$error++;
							$action = '';
						}
					} else {
						$error++;
						$errors[] = $langs->trans("ErrorValueFinalPaymentDiffers", $FinalPaymentAmt, $amounttotest);
					}
				}
			}
		}

		if (!$error) {
			$this->resprints = $resprints;
			return 1; // or return 1 to replace standard code
		} else {
			$this->errors = $errors;
			return -1;
		}
	}

	/**
	 * Overloading the isPaymentOK function : replacing the parent's function with the one below
	 *
	 * @param   array           $parameters     Hook metadatas (context, etc...)
	 * @param   CommonObject    $object         The object to process (an invoice if you are in invoice module, a propale in propale's module, etc...)
	 * @param   string          $action         Current action (if set). Generally create or edit or null
	 * @param   HookManager     $hookmanager    Hook manager propagated to allow calling another hook
	 * @return  int                             Return integer < 0 on error, 0 on success, 1 to replace standard code
	 */
	public function isPaymentOK($parameters, &$object, &$action, $hookmanager)
	{
		global $conf, $langs, $db;
		require_once DOL_DOCUMENT_ROOT.'/core/lib/functions.lib.php';
		dol_include_once('helloasso/lib/helloasso.lib.php');

		$error = 0; // Error counter
		$ispaymentok = true;
		$FULLTAG = GETPOST("fulltag", 'alpha'); // fulltag is tag with more informations
		$tmptag = dolExplodeIntoArray($FULLTAG, '.', '=');

		if (in_array($parameters['paymentmethod'], array('helloasso'))){
			$db->begin();
			$code = GETPOST("code");
			if ($code == "refused") {
				$ispaymentok = false;
				$error ++;
			}

			if (!$error) {
				if (empty($_SESSION["HelloAssoPaymentId"])) {
					$error++;
					$ispaymentok = false;
				} else {
					if (getDolGlobalInt("HELLOASSO_LIVE")) {
						$client_organisation = getDolGlobalString("HELLOASSO_CLIENT_ORGANISATION");
						$helloassourl = "api.helloasso.com";
					} else {
						$client_organisation = getDolGlobalString("HELLOASSO_TEST_CLIENT_ORGANISATION");
						$helloassourl = "api.helloasso-sandbox.com";
					}
					$assoslug = str_replace('_', '-', dol_string_nospecial(strtolower(dol_string_unaccent($client_organisation)), '-'));

					$result = helloassoDoConnection();
					if ($result <= 0) {
						$error++;
						$ispaymentok = false;
					}
					if (!$error) {
						$paymentid = $_SESSION["HelloAssoPaymentId"];
						$headers = array();
						$headers[] = "Authorization: ".ucfirst($result["token_type"])." ".$result["access_token"];
						$headers[] = "Accept: text/plain";

						$urlforcheckout = "https://".urlencode($helloassourl)."/v5/organizations/".urlencode($assoslug)."/checkout-intents/".urlencode($paymentid);
						dol_syslog("Send GET to url=".$urlforcheckout, LOG_DEBUG);
						$ret = getURLContent($urlforcheckout, 'GET', '', 1, $headers);
						if ($ret["http_code"] == 200) {
							//Add payer data to Dolibarr -> contact + test if paymentdone id test 47257
							$json = json_decode($ret["content"]);
							if ($json->id == $_SESSION["HelloAssoPaymentId"] && $json->order->payments[0]->state == "Authorized") {
								if (!empty($json->order->payer)) {
									$payer = $json->order->payer;
									$found = 0;
									$countryid = 0;

									$sql = "SELECT cc.rowid as id";
									$sql .= " FROM ".MAIN_DB_PREFIX."c_country as cc";
									$sql .= " WHERE cc.code_iso = '".$db->escape($payer->country)."'";
									$sql .= " AND cc.active = 1";
									$resql = $db->query($sql);
									if ($resql) {
										$objcount = $db->fetch_object($resql);
										if ($objcount) {
											$countryid = $objcount->id;
										}
									} else {
										$error ++;
										$ispaymentok = false;
									}
									if ($countryid != 0) {
										$sql = "SELECT COUNT(s.rowid) as nb";
										$sql .= " FROM ".MAIN_DB_PREFIX."socpeople as s";
										$sql .= " WHERE s.fk_soc = ".((int) $tmptag["CUS"]);
										$sql .= " AND s.entity = ".$conf->entity;
										$sql .= " AND s.firstname = '".$db->escape($payer->firstName)."'";
										$sql .= " AND s.lastname = '".$db->escape($payer->lastName)."'";
										$sql .= " AND s.email = '".$db->escape($payer->email)."'";
										$sql .= " AND s.address = '".$db->escape($payer->address)."'";
										$sql .= " AND s.zip = '".$db->escape($payer->zipCode)."'";
										$sql .= " AND s.town = '".$db->escape($payer->city)."'";
										$sql .= " AND s.fk_pays = ".(int) $countryid;
										$resqlcount = $db->query($sql);
										if ($resqlcount) {
											$objcount = $db->fetch_object($resqlcount);
											if ($objcount) {
												$found = $objcount->nb;
											}
										} else {
											$error ++;
											$ispaymentok = false;
										}
									}
									if (!$found && $countryid != 0) {
										//Make contact SQL
										$sql = "INSERT INTO ".MAIN_DB_PREFIX."socpeople (";
										$sql .= "entity, fk_soc, firstname, lastname, email, address, zip, town, fk_pays, fk_user_creat";
										$sql .= ") VALUES (";
										$sql .= ((int) $conf->entity).", ".((int) $tmptag["CUS"]).", '".$db->escape($payer->firstName)."', '".$db->escape($payer->lastName)."', '".$db->escape($payer->email)."', '".$db->escape($payer->address)."', '".$db->escape($payer->zipCode)."', '".$db->escape($payer->city)."', ".((int) $countryid).', null';
										$sql .= ")";

										$resqlinsert= $db->query($sql);
										if (!$resqlinsert) {
											$error ++;
											$ispaymentok = false;
										}
									}
								}
							} else {
								$error++;
								$ispaymentok = false;
							}
						} else {
							$error++;
							$ispaymentok = false;
						}
					}

				}
			}
		}

		if (!$error) {
			$db->commit();
			$this->results["ispaymentok"] = $ispaymentok;
			return 1;
		} else {
			$db->rollback();
			$this->errors[] = $langs->trans("PaymentRefused");
			return -1;
		}
	}

	/**
	 * Overloading the getBankAccountPaymentMethod function : replacing the parent's function with the one below
	 *
	 * @param   array           $parameters     Hook metadatas (context, etc...)
	 * @param   CommonObject    $object         The object to process (an invoice if you are in invoice module, a propale in propale's module, etc...)
	 * @param   string          $action         Current action (if set). Generally create or edit or null
	 * @param   HookManager     $hookmanager    Hook manager propagated to allow calling another hook
	 * @return  int                             Return integer < 0 on error, 0 on success, 1 to replace standard code
	 */
	public function getBankAccountPaymentMethod($parameters, &$object, &$action, $hookmanager)
	{
		$error = 0; // Error counter

		$bankaccountid = 0;

		if (in_array($parameters['paymentmethod'], array('helloasso'))){
			$bankaccountid = getDolGlobalInt('HELLOASSO_BANK_ACCOUNT_FOR_PAYMENTS');
			if ($bankaccountid == 0) {
				$error++;
			}
		}

		if (!$error && $bankaccountid > 0) {
			$this->results["bankaccountid"] = $bankaccountid;
			return 1;
		} else {
			return 0;
		}
	}

	/**
	 * Overloading the getBankAccountPaymentMethod function : replacing the parent's function with the one below
	 *
	 * @param   array           $parameters     Hook metadatas (context, etc...)
	 * @param   CommonObject    $object         The object to process (an invoice if you are in invoice module, a propale in propale's module, etc...)
	 * @param   string          $action         Current action (if set). Generally create or edit or null
	 * @param   HookManager     $hookmanager    Hook manager propagated to allow calling another hook
	 * @return  int                             Return integer < 0 on error, 0 on success, 1 to replace standard code
	 */
	public function doShowOnlinePaymentUrl($parameters, &$object, &$action, $hookmanager){
		if (isModEnabled('helloasso')) {
			$this->results['showonlinepaymenturl'] = isModEnabled('helloasso');
		}else {
			return -1;
		}
		return 1;
	}
}

