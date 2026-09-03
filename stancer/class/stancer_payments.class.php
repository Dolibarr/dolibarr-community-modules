<?php
/* Copyright (C) 2017  Laurent Destailleur <eldy@users.sourceforge.net>
 * Copyright (C) 2023 Eric Seigne <eric.seigne@cap-rel.fr>
 * Copyright (C) 2026		MDW					<mdeweerd@users.noreply.github.com>
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
 * \file        class/stancer_payments.class.php
 * \ingroup     stancer
 * \brief       This file is a CRUD class file for Stancer_payments (Create/Read/Update/Delete)
 */

// Put here all includes required by your class file
require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/functions.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
//require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
//require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
dol_include_once('/stancer/lib/stancer.lib.php');

/**
 * Class for Stancer_payments
 */
class Stancer_payments extends CommonObject
{
	public const TRIGGER_PREFIX = 'STANCER_PAYMENT';

	public $socid;
	public $labelStatusShort;
	public $labelStatus;
	public $output;
	public $user_validation;
	public $oldref;
	public $user_creation_id;
	public $user_modification_id;
	public $user_validation_id;
	/**
	 * @var string ID of module.
	 */
	public $module = 'stancer';

	/**
	 * @var string ID to identify managed object.
	 */
	public $element = 'stancer_payments';

	/**
	 * @var string Name of table without prefix where object is stored. This is also the key used for extrafields management.
	 */
	public $table_element = 'stancer_stancer_payments';

	/**
	 * @var int  Does this object support multicompany module ?
	 * 0=No test on entity, 1=Test with field entity, 'field@table'=Test with link by field@table
	 */
	public $ismultientitymanaged = 1;

	/**
	 * @var int  Does object support extrafields ? 0=No, 1=Yes
	 */
	public $isextrafieldmanaged = 0;

	/**
	 * @var string String with name of icon for stancer_payments. Must be a 'fa-xxx' fontawesome code (or 'fa-xxx_fa_color_size') or 'stancer_payments@stancer' if picto is file 'img/object_stancer_payments.png'.
	 */
	public $picto = 'fa-file';


	public const STATUS_ERROR = -10;
	public const STATUS_HIDDEN = -1;
	public const STATUS_DRAFT = 0;
	public const STATUS_AUTHORIZED = 1;
	public const STATUS_CAPTURED = 2;
	public const STATUS_CAPTURE_SENT = 3;
	public const STATUS_DISPUTED = 4;
	public const STATUS_EXPIRED = 5;
	public const STATUS_FAILED = 6;
	public const STATUS_REFUSED = 7;
	public const STATUS_TO_CAPTURE = 8;
	public const STATUS_CANCELED = 9;
	public const STATUS_VALIDATED = 10;

	/**
	 * Statuses meaning the money movement is engaged at Stancer and its outcome is
	 * not known yet. Starting a second payment on the same document while one of
	 * these is in the table debits the customer twice.
	 *
	 * STATUS_CAPTURED is deliberately out: the money is already collected, so a
	 * further payment can only be for the part left to pay, which the module
	 * supports (stancerMakeTAG() then appends a '.SEQ=<n>' part to the tag).
	 * The failure statuses (draft, expired, failed, refused, canceled, disputed)
	 * are out too, they must stay retryable.
	 */
	public const RUNNING_STATUS = array(self::STATUS_AUTHORIZED, self::STATUS_CAPTURE_SENT, self::STATUS_TO_CAPTURE);

	// internal stancer status
	static public $tab_status = array(
		'-10'=>'error',
		'-1'=>'hidden',
		'0'=>'draft',
		'1'=>'authorized',
		'2'=>'captured',
		'3'=>'capture_sent',
		'4'=>'disputed',
		'5'=>'expired',
		'6'=>'failed',
		'7'=>'refused',
		'8'=>'to_capture',
		'9'=>'canceled',
		'10'=>'validated'
	);

	public $tab_response = array(
		"00" => "Successful approval/completion or that VIP PIN verification is valid",
		"01" => "Refer to card issuer",
		"02" => "Refer to card issuer, special condition",
		"03" => "Invalid merchant or service provider",
		"04" => "Pickup",
		"05" => "Do not honor",
		"06" => "General error",
		"07" => "Pickup card, special condition (other than lost/stolen card)",
		"08" => "Honor with identification",
		"09" => "Request in progress",
		"10" => "Partial approval",
		"11" => "VIP approval",
		"12" => "Invalid transaction",
		"13" => "Invalid amount (currency conversion field overflow) or amount exceeds maximum for card program",
		"14" => "Invalid account number (no such number)",
		"15" => "No such issuer",
		"16" => "Insufficient funds",
		"17" => "Customer cancellation",
		"19" => "Re-enter transaction",
		"20" => "Invalid response",
		"21" => "No action taken (unable to back out prior transaction)",
		"22" => "Suspected Malfunction",
		"25" => "Unable to locate record in file, or account number is missing from the inquiry",
		"28" => "File is temporarily unavailable",
		"30" => "Format error",
		"41" => "Merchant should retain card (card reported lost)",
		"43" => "Merchant should retain card (card reported stolen)",
		"51" => "Insufficient funds",
		"52" => "No checking account",
		"53" => "No savings account",
		"54" => "Expired card",
		"55" => "Incorrect PIN",
		"57" => "Transaction not permitted to cardholder",
		"58" => "Transaction not allowed at terminal",
		"59" => "Suspected fraud",
		"61" => "Activity amount limit exceeded",
		"62" => "Restricted card (for example, in country exclusion table)",
		"63" => "Security violation",
		"65" => "Activity count limit exceeded",
		"68" => "Response received too late",
		"75" => "Allowable number of PIN-entry tries exceeded",
		"76" => "Unable to locate previous message (no match on retrieval reference number)",
		"77" => "Previous message located for a repeat or reversal, but repeat or reversal data are inconsistent with original message",
		"78" => "’Blocked, first used’—The transaction is from a new cardholder, and the card has not been properly unblocked.",
		"80" => "Visa transactions: credit issuer unavailable. Private label and check acceptance: Invalid date",
		"81" => "PIN cryptographic error found (error found by VIC security module during PIN decryption)",
		"82" => "Negative CAM, dCVV, iCVV, or CVV results",
		"83" => "Unable to verify PIN",
		"85" => "No reason to decline a request for account number verification, address verification, CVV2 verification; or a credit voucher or merchandise return",
		"91" => "Issuer unavailable or switch inoperative (STIP not applicable or available for this transaction)",
		"92" => "Destination cannot be found for routing",
		"93" => "Transaction cannot be completed, violation of law",
		"94" => "Duplicate transmission",
		"95" => "Reconcile error",
		"96" => "System malfunction, System malfunction or certain field error conditions",
		"A0" => " Authentication Required, you must do a card inserted payment with PIN code",
		"A1" => " Authentication Required, you must do a 3-D Secure authentication",
		"B1" => "Surcharge amount not permitted on Visa cards (U.S. acquirers only)",
		"N0" => "Force STIP",
		"N3" => "Cash service not available",
		"N4" => "Cashback request exceeds issuer limit",
		"N7" => "Decline for CVV2 failure",
		"P2" => "Invalid biller information",
		"P5" => "PIN change/unblock request declined",
		"P6" => "Unsafe PIN",
		"Q1" => "Card authentication failed",
		"R0" => "Stop payment order",
		"R1" => "Revocation of authorization order",
		"R3" => "Revocation of all authorizations order",
		"XA" => "Forward to issuer",
		"XD" => "Forward to issuer",
		"Z1" => "Offline-declined",
		"Z3" => "Unable to go online",
		"7810" => "Refusal count exceeded for this card / sepa",
		"7811" => "Exceeded payment volume for this card / sepa",
		"7840" => "Stolen or lost card",
		"7898" => "Bank server unavailable",
	);

	/**
	 *  'type' field format:
	 *  	'integer', 'integer:ObjectClass:PathToClass[:AddCreateButtonOrNot[:Filter[:Sortfield]]]',
	 *  	'select' (list of values are in 'options'),
	 *  	'sellist:TableName:LabelFieldName[:KeyFieldName[:KeyFieldParent[:Filter[:Sortfield]]]]',
	 *  	'chkbxlst:...',
	 *  	'varchar(x)',
	 *  	'text', 'text:none', 'html',
	 *   	'double(24,8)', 'real', 'price',
	 *  	'date', 'datetime', 'timestamp', 'duration',
	 *  	'boolean', 'checkbox', 'radio', 'array',
	 *  	'mail', 'phone', 'url', 'password', 'ip'
	 *		Note: Filter can be a string like "(t.ref:like:'SO-%') or (t.date_creation:<:'20160101') or (t.nature:is:NULL)"
	 *  'label' the translation key.
	 *  'picto' is code of a picto to show before value in forms
	 *  'enabled' is a condition when the field must be managed (Example: 1 or '$conf->global->MY_SETUP_PARAM' or 'isModEnabled("multicurrency")' ...)
	 *  'position' is the sort order of field.
	 *  'notnull' is set to 1 if not null in database. Set to -1 if we must set data to null if empty ('' or 0).
	 *  'visible' says if field is visible in list (Examples: 0=Not visible, 1=Visible on list and create/update/view forms, 2=Visible on list only, 3=Visible on create/update/view form only (not list), 4=Visible on list and update/view form only (not create). 5=Visible on list and view only (not create/not update). Using a negative value means field is not shown by default on list but can be selected for viewing)
	 *  'noteditable' says if field is not editable (1 or 0)
	 *  'alwayseditable' says if field can be modified also when status is not draft ('1' or '0')
	 *  'default' is a default value for creation (can still be overwrote by the Setup of Default Values if field is editable in creation form). Note: If default is set to '(PROV)' and field is 'ref', the default value will be set to '(PROVid)' where id is rowid when a new record is created.
	 *  'index' if we want an index in database.
	 *  'foreignkey'=>'tablename.field' if the field is a foreign key (it is recommended to name the field fk_...).
	 *  'searchall' is 1 if we want to search in this field when making a search from the quick search button.
	 *  'isameasure' must be set to 1 or 2 if field can be used for measure. Field type must be summable like integer or double(24,8). Use 1 in most cases, or 2 if you don't want to see the column total into list (for example for percentage)
	 *  'css' and 'cssview' and 'csslist' is the CSS style to use on field. 'css' is used in creation and update. 'cssview' is used in view mode. 'csslist' is used for columns in lists. For example: 'css'=>'minwidth300 maxwidth500 widthcentpercentminusx', 'cssview'=>'wordbreak', 'csslist'=>'tdoverflowmax200'
	 *  'help' is a 'TranslationString' to use to show a tooltip on field. You can also use 'TranslationString:keyfortooltiponlick' for a tooltip on click.
	 *  'showoncombobox' if value of the field must be visible into the label of the combobox that list record
	 *  'disabled' is 1 if we want to have the field locked by a 'disabled' attribute. In most cases, this is never set into the definition of $fields into class, but is set dynamically by some part of code.
	 *  'arrayofkeyval' to set a list of values if type is a list of predefined values. For example: array("0"=>"Draft","1"=>"Active","-1"=>"Cancel"). Note that type can be 'integer' or 'varchar'
	 *  'autofocusoncreate' to have field having the focus on a create form. Only 1 field should have this property set to 1.
	 *  'comment' is not used. You can store here any text of your choice. It is not used by application.
	 *	'validate' is 1 if need to validate with $this->validateField()
	 *  'copytoclipboard' is 1 or 2 to allow to add a picto to copy value into clipboard (1=picto after label, 2=picto after value)
	 *
	 *  Note: To have value dynamic, you can set value to 0 in definition and edit the value on the fly into the constructor.
	 */

	// BEGIN MODULEBUILDER PROPERTIES
	/**
	 * @var array  Array with all fields and their property. Do not use it as a static var. It may be modified by constructor.
	 */
	public $fields=array(
		'rowid' => array('type'=>'integer', 'label'=>'TechnicalID', 'enabled'=>'1', 'position'=>1, 'notnull'=>1, 'visible'=>0, 'noteditable'=>'1', 'index'=>1, 'css'=>'left', 'comment'=>"Id"),
		'stancer_id' => array('type'=>'varchar(30)', 'label'=>'StancerAccountID', 'enabled'=>'1', 'position'=>20, 'notnull'=>1, 'visible'=>1, 'index'=>1, 'showoncombobox'=>'1', 'validate'=>'1', 'comment'=>"Reference of object"),
		'amount' => array('type'=>'integer', 'label'=>'Amount', 'enabled'=>'1', 'position'=>115, 'notnull'=>0, 'visible'=>1, 'default'=>'null', 'isameasure'=>'1', 'help'=>"Amount in cents", 'validate'=>'1',),
		'fee' => array('type'=>'integer', 'label'=>'Fees', 'enabled'=>'1', 'position'=>116, 'notnull'=>0, 'visible'=>1, 'default'=>'null', 'isameasure'=>'1', 'help'=>"Fee in cents", 'validate'=>'1',),
		'currency' => array('type'=>'varchar(4)', 'label'=>'Currency', 'enabled'=>'1', 'position'=>50, 'notnull'=>0, 'visible'=>-1, 'showoncombobox'=>'1', 'validate'=>'1', 'comment'=>"Currency"),
		'description' => array('type'=>'varchar(64)', 'label'=>'Description', 'enabled'=>'1', 'position'=>60, 'notnull'=>0, 'visible'=>1, 'showoncombobox'=>'1', 'validate'=>'1', 'comment'=>"The payment description"),
		'order_id' => array('type'=>'varchar(36)', 'label'=>'StancerOrderID', 'enabled'=>'1', 'position'=>70, 'notnull'=>0, 'visible'=>-1, 'showoncombobox'=>'1', 'validate'=>'1', 'comment'=>"Your reference id"),
		'unique_id' => array('type'=>'varchar(36)', 'label'=>'StancerUniqueID', 'enabled'=>'1', 'position'=>80, 'notnull'=>0, 'visible'=>1, 'showoncombobox'=>'1', 'validate'=>'1', 'comment'=>"Your unicity key"),
		'grouped_invoice_ids' => array('type'=>'text', 'label'=>'StancerGroupedInvoiceIds', 'enabled'=>'1', 'position'=>81, 'notnull'=>0, 'visible'=>-1, 'validate'=>'1', 'comment'=>"Comma-separated list of Dolibarr invoice ids when this Stancer payment groups several same-day invoices for the same customer"),
		'method' => array('type'=>'varchar(4)', 'label'=>'Method', 'enabled'=>'1', 'position'=>90, 'notnull'=>0, 'visible'=>1, 'arrayofkeyval'=>array('' => '', 'card'=>'CB', 'sepa'=>'SEPA'), 'validate'=>'1', 'comment'=>"The payment method used to pay"),
		'card' => array('type'=>'varchar(30)', 'label'=>'StancerCBCard', 'enabled'=>'1', 'position'=>100, 'notnull'=>0, 'visible'=>1, 'showoncombobox'=>'1', 'validate'=>'1', 'comment'=>"If present, will perform a credit card payment"),
		'sepa' => array('type'=>'varchar(30)', 'label'=>'SEPA', 'enabled'=>'1', 'position'=>110, 'notnull'=>0, 'visible'=>1, 'showoncombobox'=>'1', 'validate'=>'1', 'comment'=>"If present, will perform a SEPA payment"),
		'customer' => array('type'=>'varchar(30)', 'label'=>'StancerCustomer', 'enabled'=>'1', 'position'=>120, 'notnull'=>0, 'visible'=>1, 'showoncombobox'=>'1', 'validate'=>'1', 'comment'=>"If present, will link payment to customer"),
		'refunds' => array('type'=>'text', 'label'=>'refunds', 'enabled'=>'1', 'position'=>130, 'notnull'=>0, 'visible'=>-1, 'showoncombobox'=>'1', 'validate'=>'1', 'comment'=>"Array of refund object"),
		'response' => array('type'=>'varchar(4)', 'label'=>'response', 'enabled'=>'1', 'position'=>150, 'notnull'=>0, 'visible'=>-1, 'showoncombobox'=>'1', 'validate'=>'1', 'comment'=>"The response of the bank processing"),
		'capture' => array('type'=>'boolean', 'label'=>'capture', 'enabled'=>'1', 'position'=>160, 'notnull'=>0, 'visible'=>-1, 'showoncombobox'=>'1', 'validate'=>'1', 'comment'=>"Capture immediately the payment"),
		'created' => array('type'=>'datetime', 'label'=>'created', 'enabled'=>'1', 'position'=>170, 'notnull'=>0, 'visible'=>-1, 'showoncombobox'=>'1', 'validate'=>'1', 'comment'=>"The Unix timestamp representing creation date of the object in local time"),
		'date_bank' => array('type'=>'timestamp', 'label'=>'date_bank', 'enabled'=>'1', 'position'=>180, 'notnull'=>0, 'visible'=>-1, 'showoncombobox'=>'1', 'validate'=>'1', 'comment'=>"Timestamp of the delivery date of the funds traded by the bank"),
		'live_mode' => array('type'=>'boolean', 'label'=>'live_mode', 'enabled'=>'1', 'position'=>190, 'notnull'=>0, 'visible'=>3, 'showoncombobox'=>'1', 'validate'=>'1', 'comment'=>"Test or Live mode"),
		'fk_soc' => array('type'=>'integer:VIEW ON CONSTRUCT FOR DOL VERSION HANDLE'),
		'date_creation' => array('type'=>'datetime', 'label'=>'DateCreation', 'enabled'=>'1', 'position'=>500, 'notnull'=>1, 'visible'=>1,),
		'tms' => array('type'=>'timestamp', 'label'=>'DateModification', 'enabled'=>'1', 'position'=>501, 'notnull'=>0, 'visible'=>-2,),
		'fk_user_creat' => array('type'=>'integer:User:user/class/user.class.php', 'label'=>'UserAuthor', 'picto'=>'user', 'enabled'=>'1', 'position'=>510, 'notnull'=>0, 'visible'=>-2, 'foreignkey'=>'user.rowid', 'csslist'=>'tdoverflowmax150',),
		'fk_user_modif' => array('type'=>'integer:User:user/class/user.class.php', 'label'=>'UserModif', 'picto'=>'user', 'enabled'=>'1', 'position'=>511, 'notnull'=>0, 'visible'=>-2, 'csslist'=>'tdoverflowmax150',),
		'status' => array('type'=>'integer', 'label'=>'Status', 'enabled'=>'1', 'position'=>2000, 'notnull'=>1, 'visible'=>1, 'index'=>1, 'arrayofkeyval'=>array('0' =>'draft', '1'=>'authorized', '2'=>'captured', '3'=>'capture_sent', '4'=>'disputed', '5'=>'expired', '6'=>'failed', '7'=>'refused', '8'=>'to_capture'), 'validate'=>'1',),
		'entity' => array('type'=>'integer', 'label'=>'Entity', 'enabled'=>'1', 'position'=>3000, 'notnull'=>1, 'visible'=>0,)
	);
	public $rowid;
	public $stancer_id;
	public $amount;
	/**
	 * Fee taken by Stancer on this payment, in cents (integer), as returned by
	 * the API field 'fee'. Beware: the property is named "fee", singular. Writing
	 * $payment->fees returns null and silently books a zero fee.
	 */
	public $fee;
	public $currency;
	public $description;
	public $order_id;
	public $unique_id;
	public $grouped_invoice_ids;
	public $method;
	public $card;
	public $sepa;
	public $customer;
	public $refunds;
	public $response;
	public $capture;
	public $created;
	public $date_bank;
	public $live_mode;
	public $fk_soc;
	public $date_creation;
	public $tms;
	public $fk_user_creat;
	public $fk_user_modif;
	public $status;
	public $entity;
	// END MODULEBUILDER PROPERTIES

	/**
	 * @var int|null Link to invoice (not persisted, used for relations)
	 */
	public $fk_facture;

	/**
	 * @var array Array of lines
	 */
	public $lines = array();

	/**
	 * @var string New reference after validation
	 */
	public $newref;

	/**
	 * @var int|string Date of modification
	 */
	public $date_modification;

	/**
	 * @var int|string Date of validation
	 */
	public $date_validation;


	// If this object has a subtable with lines

	// /**
	//  * @var string    Name of subtable line
	//  */
	// public $table_element_line = 'stancer_stancer_paymentsline';

	// /**
	//  * @var string    Field with ID of parent key if this object has a parent
	//  */
	// public $fk_element = 'fk_stancer_payments';

	// /**
	//  * @var string    Name of subtable class that manage subtable lines
	//  */
	// public $class_element_line = 'Stancer_paymentsline';

	// /**
	//  * @var array	List of child tables. To test if we can delete object.
	//  */
	// protected $childtables = array();

	// /**
	//  * @var array    List of child tables. To know object to delete on cascade.
	//  *               If name matches '@ClassNAme:FilePathClass;ParentFkFieldName' it will
	//  *               call method deleteByParentField(parentId, ParentFkFieldName) to fetch and delete child object
	//  */
	// protected $childtablesoncascade = array('stancer_stancer_paymentsdet');

	// /**
	//  * @var Stancer_paymentsLine[]     Array of subtable lines
	//  */
	// public $lines = array();



	/**
	 * Constructor
	 *
	 * @param DoliDb $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		global $conf, $langs;

		$this->db = $db;

		if (floatval(DOL_VERSION) > 16.0) {
			$this->fields['fk_soc'] = array('type'=>'integer:Societe:societe/class/societe.class.php:1:((status:=:1) AND (entity:IN:__SHARED_ENTITIES__))', 'label'=>'ThirdParty', 'picto'=>'company', 'enabled'=>'$conf->societe->enabled', 'position'=>50, 'notnull'=>-1, 'visible'=>1, 'index'=>1, 'css'=>'maxwidth500 widthcentpercentminusxx', 'csslist'=>'tdoverflowmax150', 'help'=>"OrganizationEventLinkToThirdParty", 'validate'=>'1',);
		} else {
			$this->fields['fk_soc'] = array('type'=>'integer:Societe:societe/class/societe.class.php:1:status=1 AND entity IN (__SHARED_ENTITIES__)', 'label'=>'ThirdParty', 'picto'=>'company', 'enabled'=>'$conf->societe->enabled', 'position'=>50, 'notnull'=>-1, 'visible'=>1, 'index'=>1, 'css'=>'maxwidth500 widthcentpercentminusxx', 'csslist'=>'tdoverflowmax150', 'help'=>"OrganizationEventLinkToThirdParty", 'validate'=>'1',);
		}


		if (getDolGlobalString('MAIN_SHOW_TECHNICAL_ID', '') == '' && isset($this->fields['rowid']) && !empty($this->fields['ref'])) {
			$this->fields['rowid']['visible'] = 0;
		}
		if (!isModEnabled('multicompany') && isset($this->fields['entity'])) {
			$this->fields['entity']['enabled'] = 0;
		}

		// Example to show how to set values of fields definition dynamically
		/*if ($user->rights->stancer->stancer_payments->read) {
			$this->fields['myfield']['visible'] = 1;
			$this->fields['myfield']['noteditable'] = 0;
		}*/

		// Unset fields that are disabled
		foreach ($this->fields as $key => $val) {
			if (isset($val['enabled']) && empty($val['enabled'])) {
				unset($this->fields[$key]);
			}
		}

		// Translate some data of arrayofkeyval
		if (is_object($langs)) {
			foreach ($this->fields as $key => $val) {
				if (!empty($val['arrayofkeyval']) && is_array($val['arrayofkeyval'])) {
					foreach ($val['arrayofkeyval'] as $key2 => $val2) {
						$this->fields[$key]['arrayofkeyval'][$key2] = $langs->trans($val2);
					}
				}
			}
		}
	}

	/**
	 * Create object into database
	 *
	 * @param  User $user      User that creates
	 * @param  bool $notrigger false=launch triggers after, true=disable triggers
	 * @return int             <0 if KO, Id of created object if OK
	 */
	public function create(User $user, $notrigger = false)
	{
		//capturer le risque de doublon -> erreur
		// $check = new Stancer_payments($this->db);
		// $res = $check->fetch(0, null, null, $this->unique_id);
		// if ($res > 0) {
		// 	$this->unique_id = stancerCleanUpDuplicate($this->unique_id . ".UNIQ=" . rand(100, 999));
		// 	dol_syslog("Stancer_payments collision on unique_id, rename it to " . $this->unique_id);
		// }

		$notrig = null;
		if (floatval(DOL_VERSION) < 20.0) {
			$notrig = $notrigger;
		} else {
			if ($notrigger) {
				$notrig = 1;
			} else {
				$notrig = 0;
			}
		}

		//$resultvalidate = $this->validate($user, $notrigger);
		return $this->createCommon($user, $notrig);
	}

	/**
	 * Load object in memory from the database
	 *
	 * The lookup keys are cumulative: passing two of them narrows the search
	 * instead of replacing the first one.
	 *
	 * How unique each key is, as declared in sql/llx_stancer_stancer_payments.sql
	 * and sql/llx_stancer_stancer_payments.key.sql:
	 *  - $id is the rowid, unique;
	 *  - $stancer_id is unique per entity (uk_stancer_stancer_payments_stancer_id);
	 *  - $uuid is stored in unique_id, which carries a UNIQUE constraint;
	 *  - $order_id has NO unique constraint. Payment retries and same-day grouped
	 *    SEPA debits legitimately share one value, and fetchCommon() ends with
	 *    "LIMIT 1" without any ORDER BY, so the row loaded is whichever one the
	 *    database returns first. Call fetchAllRunningForInvoice() or fetchAll()
	 *    when every matching row matters.
	 *  - $ref has no matching column in this table, so a non empty value makes
	 *    fetchCommon() query a column that does not exist. Leave it empty.
	 *
	 * @param int         $id          Id object
	 * @param string|null $ref         Ref (unused here, see above)
	 * @param string|null $stancer_id  stancer_id (compute by stancer)
	 * @param string|null $uuid        unique id (dolibarr tag)
	 * @param string|null $order_id    Reference of the source document, as stored in the order_id column
	 * @return int                     <0 if KO, 0 if not found, >0 if OK
	 */
	public function fetch($id, $ref = null, $stancer_id = null, $uuid = null, $order_id = null)
	{
		$more = "";
		if (!empty($stancer_id)) {
			$more .= " AND stancer_id='" . $this->db->escape($stancer_id) . "'";
		}
		if (!empty($uuid)) {
			$more .= " AND unique_id='" . $this->db->escape($uuid) . "'";
		}
		if (!empty($order_id)) {
			$more .= " AND order_id='" . $this->db->escape($order_id) . "'";
		}
		$result = $this->fetchCommon($id, $ref, $more);
		if ($result > 0 && !empty($this->table_element_line)) {
			$this->fetchLines();
		}
		return $result;
	}

	/**
	 * Load every Stancer payment that is still moving money for one Dolibarr invoice.
	 *
	 * A payment is reported here when its status is one of RUNNING_STATUS, it belongs
	 * to the current live/test mode, and it is attached to the invoice by one of the
	 * three links the module creates:
	 *  - unique_id, the tag stancerMakeTAG() builds (lib/stancer_payment.lib.php).
	 *    That tag is NOT 'INV=<rowid>' followed by the other parts: it ends with
	 *    stancerCleanUpDuplicate(), which ksort()s the parts, so the value really
	 *    stored is 'CUS=<socid>.INV=<rowid>' and the optional '.SEQ=' / '.UNIQ='
	 *    parts sort after 'INV='. This is why the two clauses below start with a
	 *    leading '%'. Never turn them into a prefix match on 'INV=<rowid>%': it
	 *    would match no real tag any more, and the guard would silently let a
	 *    second debit through instead of refusing it;
	 *  - order_id, which stancerCardstartPayWithRedirect() and stancerSEPAstartPay()
	 *    fill with the exact invoice ref;
	 *  - grouped_invoice_ids, the comma separated list written by
	 *    stancerSEPAstartPayGrouped() when several same-day invoices of one customer
	 *    share a single debit. That column is the only reliable link for a grouped
	 *    payment: stancerBuildGroupedOrderId() joins the refs with '+' and truncates
	 *    the result to the 36 chars Stancer accepts, so a large group only names its
	 *    first refs in order_id, the others being replaced by a '+<count>' suffix.
	 *
	 * @param  int    $invoiceId   Dolibarr invoice rowid
	 * @param  string $invoiceRef  Dolibarr invoice ref
	 * @return array<int,Stancer_payments>|int  Matching records keyed by rowid (possibly empty), -1 on error
	 */
	public function fetchAllRunningForInvoice($invoiceId, $invoiceRef)
	{
		$sanitizedInvoiceId = (int) $invoiceId;
		$invoiceRef = (string) $invoiceRef;
		if ($sanitizedInvoiceId <= 0 && $invoiceRef === '') {
			dol_syslog(__METHOD__ . " called with neither an invoice id nor an invoice ref, nothing to look for", LOG_ERR);
			return -1;
		}

		// Same live/test partition as every other payment lookup of the module, so a
		// test row can never talk about a production invoice and the other way round.
		$customSql = "live_mode = '" . $this->db->escape(getDolGlobalString('STANCER_IS_PROD', '0')) . "'";
		$customSql .= " AND status IN (" . implode(',', array_map('intval', self::RUNNING_STATUS)) . ")";
		$customSql .= " AND (";
		$firstClause = true;
		if ($sanitizedInvoiceId > 0) {
			$customSql .= "unique_id LIKE '%INV=" . $sanitizedInvoiceId . "'";
			$customSql .= " OR unique_id LIKE '%INV=" . $sanitizedInvoiceId . ".%'";
			$customSql .= " OR grouped_invoice_ids = '" . $sanitizedInvoiceId . "'";
			$customSql .= " OR grouped_invoice_ids LIKE '" . $sanitizedInvoiceId . ",%'";
			$customSql .= " OR grouped_invoice_ids LIKE '%," . $sanitizedInvoiceId . "'";
			$customSql .= " OR grouped_invoice_ids LIKE '%," . $sanitizedInvoiceId . ",%'";
			$firstClause = false;
		}
		if ($invoiceRef !== '') {
			// Equality on purpose: a ref may hold '%' or '_' with a custom numbering
			// mask, and those are wildcards for LIKE. Grouped payments are covered by
			// grouped_invoice_ids above, so no LIKE on order_id is needed.
			$customSql .= ($firstClause ? "" : " OR ") . "order_id = '" . $this->db->escape($invoiceRef) . "'";
		}
		$customSql .= ")";

		$records = $this->fetchAll('DESC', 't.rowid', 0, 0, array('customsql' => $customSql));
		if (!is_array($records)) {
			dol_syslog(__METHOD__ . " sql error while looking for running payments of invoice id=" . $sanitizedInvoiceId . " ref=" . $invoiceRef . ": " . implode(',', $this->errors), LOG_ERR);
			return -1;
		}
		return $records;
	}

	/**
	 * Name the given payments the same way everywhere.
	 *
	 * The invoice card tooltip, the invoice card log line and the server side guards
	 * of stancerSEPAstartPay() and stancerCBstartPay() all have to tell the user and
	 * the administrator which rows blocked a debit. One formatter keeps those three
	 * places readable with the same vocabulary, so a refusal seen on screen can be
	 * grepped in dolibarr.log without translating anything.
	 *
	 * @param  array<int,Stancer_payments> $records Records, typically from fetchAllRunningForInvoice()
	 * @return list<string>                         One 'rowid=12 stancer_id=paym_xxx status=8' per record
	 */
	public static function describeRecords(array $records)
	{
		return array_map(
			/**
			 * @param  Stancer_payments $record One matching payment
			 * @return string                   Its label
			 */
			static function ($record) {
				return 'rowid=' . ((int) $record->id) . ' stancer_id=' . $record->stancer_id . ' status=' . ((int) $record->status);
			},
			array_values($records)
		);
	}

	/**
	 * Load object lines in memory from the database
	 *
	 * @return int         <0 if KO, 0 if not found, >0 if OK
	 */
	public function fetchLines()
	{
		$this->lines = array();

		$result = $this->fetchLinesCommon();
		return $result;
	}


	/**
	 * Load list of objects in memory from the database.
	 *
	 * @param  string      $sortorder    Sort Order
	 * @param  string      $sortfield    Sort field
	 * @param  int         $limit        limit
	 * @param  int         $offset       Offset
	 * @param  array       $filter       Filter array. Example array('field'=>'valueforlike', 'customurl'=>...)
	 * @param  string      $filtermode   Filter mode ('OR' for OR, anything else for AND)
	 * @return array<int,Stancer_payments>|int   int <0 if KO, array of records keyed by rowid if OK (possibly empty)
	 *
	 * @phan-suppress PhanPluginMoreSpecificActualReturnType  The array is legitimately empty when the
	 *                query matches no row, so 'non-empty-associative-array' must not be documented here.
	 */
	public function fetchAll($sortorder = '', $sortfield = '', $limit = 0, $offset = 0, $filter = array(), $filtermode = 'AND')
	{
		global $conf;

		dol_syslog(__METHOD__, LOG_DEBUG);

		$records = array();

		$sql = 'SELECT ';
		$sql .= $this->getFieldList();
		// foreach ($this->fields as $key => $val) {
		// 	$sql .= 't.' . $key . ', ';
		// }
		$sql .= " FROM ".MAIN_DB_PREFIX.$this->table_element." as t";
		if (isset($this->ismultientitymanaged) && $this->ismultientitymanaged == 1) {
			$sql .= " WHERE t.entity IN (".getEntity($this->element).")";
		} else {
			$sql .= " WHERE 1 = 1";
		}
		// Manage filter
		$sqlwhere = array();
		if (count($filter) > 0) {
			foreach ($filter as $key => $value) {
				if ($key == 't.rowid') {
					$sqlwhere[] = $key." = ".((int) $value);
				} elseif ($key == 'customsql') {
					$sqlwhere[] = $value;
				} elseif (isset($this->fields[$key]) && isset($this->fields[$key]['type']) && in_array($this->fields[$key]['type'], array('date', 'datetime', 'timestamp'))) {
					$sqlwhere[] = $key." = '".$this->db->idate($value)."'";
				} elseif (strpos($value, '%') === false) {
					$sqlwhere[] = $key." IN (".$this->db->sanitize($this->db->escape($value)).")";
				} else {
					$sqlwhere[] = $key." LIKE '%".$this->db->escape($value)."%'";
				}
			}
		}
		if (count($sqlwhere) > 0) {
			// Whitelist the glue instead of interpolating $filtermode: only 'OR' and 'AND'
			// can ever reach the query, whatever an external caller passes.
			$sql .= " AND (".implode((dol_strtoupper($filtermode) == 'OR' ? " OR " : " AND "), $sqlwhere).")";
		}

		if (!empty($sortfield)) {
			$sql .= $this->db->order($sortfield, $sortorder);
		}
		if (!empty($limit)) {
			$sql .= $this->db->plimit($limit, $offset);
		}

		$resql = $this->db->query($sql);
		if ($resql) {
			$num = $this->db->num_rows($resql);
			$i = 0;
			while ($i < ($limit ? min($limit, $num) : $num)) {
				$obj = $this->db->fetch_object($resql);

				$record = new self($this->db);
				$record->setVarsFromFetchObj($obj);

				$records[$record->id] = $record;

				$i++;
			}
			$this->db->free($resql);

			return $records;
		} else {
			$this->errors[] = 'Error '.$this->db->lasterror();
			dol_syslog(__METHOD__.' '.implode(',', $this->errors), LOG_ERR);

			return -1;
		}
	}

	/**
	 * Update object into database
	 *
	 * @param  User $user      User that modifies
	 * @param  bool $notrigger false=launch triggers after, true=disable triggers
	 * @return int             <0 if KO, >0 if OK
	 */
	public function update(User $user, $notrigger = false)
	{
		//fix race conditions where some data will be deleted
		if (empty($this->stancer_id)) {
			dol_syslog("StancerPayment: refuse update due to empty stancer id");
		}

		$notrig = null;
		if (floatval(DOL_VERSION) < 20.0) {
			$notrig = $notrigger;
		} else {
			if ($notrigger) {
				$notrig = 1;
			} else {
				$notrig = 0;
			}
		}
		return $this->updateCommon($user, $notrig);
	}

	/**
	 * Delete object in database
	 *
	 * @param User $user       User that deletes
	 * @param bool $notrigger  false=launch triggers, true=disable triggers
	 * @return int             <0 if KO, >0 if OK
	 */
	public function delete(User $user, $notrigger = false)
	{
		$notrig = null;
		if (floatval(DOL_VERSION) < 20.0) {
			$notrig = $notrigger;
		} else {
			if ($notrigger) {
				$notrig = 1;
			} else {
				$notrig = 0;
			}
		}

		return $this->deleteCommon($user, $notrig);
	}

	/**
	 *  Delete a line of object in database
	 *
	 *	@param  User	$user       User that delete
	 *  @param	int		$idline		Id of line to delete
	 *  @param 	bool 	$notrigger  false=launch triggers after, true=disable triggers
	 *  @return int         		>0 if OK, <0 if KO
	 */
	public function deleteLine(User $user, $idline, $notrigger = false)
	{
		if ($this->status < 0) {
			$this->error = 'ErrorDeleteLineNotAllowedByObjectStatus';
			return -2;
		}

		$notrig = null;
		if (floatval(DOL_VERSION) < 20.0) {
			$notrig = $notrigger;
		} else {
			if ($notrigger) {
				$notrig = 1;
			} else {
				$notrig = 0;
			}
		}

		return $this->deleteLineCommon($user, $idline, $notrig);
	}


	/**
	 *	Validate object
	 *
	 *	@param		User	$user     		User making status change
	 *  @param		int		$notrigger		1=Does not execute triggers, 0= execute triggers
	 *	@return  	int						<=0 if OK, 0=Nothing done, >0 if KO
	 */
	public function validate($user, $notrigger = 0)
	{
		global $conf, $langs;
		$error = 0;

		// Protection
		if ($this->status == self::STATUS_VALIDATED) {
			dol_syslog(get_class($this)."::validate action abandoned: already validated", LOG_WARNING);
			return 0;
		}

		$this->db->begin();

		// Update status only (no ref column in Stancer tables, we use stancer_id as reference)
		$sql = "UPDATE ".MAIN_DB_PREFIX.$this->table_element;
		$sql .= " SET status = ".self::STATUS_VALIDATED;
		$sql .= " WHERE rowid = ".((int) $this->id);

		dol_syslog(get_class($this)."::validate()", LOG_DEBUG);
		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_print_error($this->db);
			$this->error = $this->db->lasterror();
			$error++;
		}

		if (!$error && !$notrigger) {
			// Call trigger
			$result = $this->call_trigger('STANCER_PAYMENTS_VALIDATE', $user);
			if ($result < 0) {
				$error++;
			}
		}

		if (!$error) {
			$this->status = self::STATUS_VALIDATED;
			$this->db->commit();
			return 1;
		} else {
			$this->db->rollback();
			return -1;
		}
	}


	/**
	 *	Set draft status
	 *
	 *	@param	User	$user			Object user that modify
	 *  @param	int		$notrigger		1=Does not execute triggers, 0=Execute triggers
	 *	@return	int						<0 if KO, >0 if OK
	 */
	public function setDraft($user, $notrigger = 0)
	{
		// Protection
		if ($this->status <= self::STATUS_DRAFT) {
			return 0;
		}

		/*if (! ((getDolGlobalString('MAIN_USE_ADVANCED_PERMS','') == '' && !empty($user->rights->stancer->write))
		 || (getDolGlobalString('MAIN_USE_ADVANCED_PERMS','') != '' && !empty($user->rights->stancer->stancer_advance->validate))))
		 {
		 $this->error='Permission denied';
		 return -1;
		 }*/

		return $this->setStatusCommon($user, self::STATUS_DRAFT, $notrigger, 'STANCER_PAYMENTS_UNVALIDATE');
	}

	/**
	 *	Set cancel status
	 *
	 *	@param	User	$user			Object user that modify
	 *  @param	int		$notrigger		1=Does not execute triggers, 0=Execute triggers
	 *	@return	int						<0 if KO, 0=Nothing done, >0 if OK
	 */
	public function cancel($user, $notrigger = 0)
	{
		// Protection
		if ($this->status != self::STATUS_VALIDATED) {
			return 0;
		}

		/*if (! ((getDolGlobalString('MAIN_USE_ADVANCED_PERMS','') == '' && !empty($user->rights->stancer->write))
		 || (getDolGlobalString('MAIN_USE_ADVANCED_PERMS','') != '' && !empty($user->rights->stancer->stancer_advance->validate))))
		 {
		 $this->error='Permission denied';
		 return -1;
		 }*/

		return $this->setStatusCommon($user, self::STATUS_CANCELED, $notrigger, 'STANCER_PAYMENTS_CANCEL');
	}

	/**
	 *	Set back to validated status
	 *
	 *	@param	User	$user			Object user that modify
	 *  @param	int		$notrigger		1=Does not execute triggers, 0=Execute triggers
	 *	@return	int						<0 if KO, 0=Nothing done, >0 if OK
	 */
	public function reopen($user, $notrigger = 0)
	{
		// Protection
		if ($this->status == self::STATUS_VALIDATED) {
			return 0;
		}

		/*if (! ((getDolGlobalString('MAIN_USE_ADVANCED_PERMS','') == '' && !empty($user->rights->stancer->write))
		 || (getDolGlobalString('MAIN_USE_ADVANCED_PERMS','') != '' && !empty($user->rights->stancer->stancer_advance->validate))))
		 {
		 $this->error='Permission denied';
		 return -1;
		 }*/

		return $this->setStatusCommon($user, self::STATUS_VALIDATED, $notrigger, 'STANCER_PAYMENTS_REOPEN');
	}

	/**
	 *  Return a link to the object card (with optionally the picto)
	 *
	 *  @param  int     $withpicto                  Include picto in link (0=No picto, 1=Include picto into link, 2=Only picto)
	 *  @param  string  $option                     On what the link point to ('nolink', ...)
	 *  @param  int     $notooltip                  1=Disable tooltip
	 *  @param  string  $morecss                    Add more css on link
	 *  @param  int     $save_lastsearch_value      -1=Auto, 0=No save of lastsearch_values when clicking, 1=Save lastsearch_values whenclicking
	 *  @return	string                              String with URL
	 */
	public function getNomUrl($withpicto = 0, $option = '', $notooltip = 0, $morecss = '', $save_lastsearch_value = -1)
	{
		global $conf, $langs, $hookmanager;

		if (!empty($conf->dol_no_mouse_hover)) {
			$notooltip = 1; // Force disable tooltips
		}

		$result = '';

		$label = img_picto('', $this->picto).' <u>'.$langs->trans("Stancer_payments").'</u>';
		if (isset($this->status)) {
			$label .= ' '.$this->getLibStatut(5);
		}
		$label .= '<br>';
		$label .= '<b>'.$langs->trans('Ref').':</b> '.$this->ref;

		$url = dol_buildpath('/stancer/stancer_payments_list.php', 1).'?search_stancer_id='.urlencode((string) $this->ref);

		if ($option != 'nolink') {
			// Add param to save lastsearch_values or not
			$add_save_lastsearch_values = ($save_lastsearch_value == 1 ? 1 : 0);
			if ($save_lastsearch_value == -1 && preg_match('/list\.php/', $_SERVER["PHP_SELF"])) {
				$add_save_lastsearch_values = 1;
			}
			if ($url && $add_save_lastsearch_values) {
				$url .= '&save_lastsearch_values=1';
			}
		}

		$linkclose = '';
		if (empty($notooltip)) {
			if (getDolGlobalString('MAIN_OPTIMIZEFORTEXTBROWSER', '') != '') {
				$label = $langs->trans("ShowStancer_payments");
				$linkclose .= ' alt="'.dol_escape_htmltag($label, 1).'"';
			}
			$linkclose .= ' title="'.dol_escape_htmltag($label, 1).'"';
			$linkclose .= ' class="classfortooltip'.($morecss ? ' '.$morecss : '').'"';
		} else {
			$linkclose = ($morecss ? ' class="'.$morecss.'"' : '');
		}

		if ($option == 'nolink' || empty($url)) {
			$linkstart = '<span';
		} else {
			$linkstart = '<a href="'.$url.'"';
		}
		$linkstart .= $linkclose.'>';
		if ($option == 'nolink' || empty($url)) {
			$linkend = '</span>';
		} else {
			$linkend = '</a>';
		}

		$result .= $linkstart;

		if (empty($this->showphoto_on_popup)) {
			if ($withpicto) {
				$result .= img_object(($notooltip ? '' : $label), ($this->picto ? $this->picto : 'generic'), ($notooltip ? (($withpicto != 2) ? 'class="paddingright"' : '') : 'class="'.(($withpicto != 2) ? 'paddingright ' : '').'classfortooltip"'), 0, 0, $notooltip ? 0 : 1);
			}
		} else {
			if ($withpicto) {
				list($class, $module) = explode('@', $this->picto);
				$upload_dir = $conf->$module->multidir_output[$conf->entity]."/$class/".dol_sanitizeFileName($this->ref);
				$filearray = dol_dir_list($upload_dir, "files");
				$filename = $filearray[0]['name'];
				if (!empty($filename)) {
					$pospoint = strpos($filearray[0]['name'], '.');

					$pospoint = strpos($filearray[0]['name'], '.');
					$pathtophoto = $class.'/'.$this->ref.'/thumbs/';
					if ($pospoint !== false) {
						$pathtophoto .= substr($filename, 0, $pospoint).'_mini'.substr($filename, $pospoint);
					} else {
						$pathtophoto .= $filename.'_mini';
					}

					if (empty($conf->global->{dol_strtoupper($module.'_'.$class).'_FORMATLISTPHOTOSASUSERS'})) {
						$result .= '<div class="floatleft inline-block valignmiddle divphotoref"><div class="photoref"><img class="photo'.$module.'" alt="No photo" border="0" src="'.DOL_URL_ROOT.'/viewimage.php?modulepart='.$module.'&entity='.$conf->entity.'&file='.urlencode($pathtophoto).'"></div></div>';
					} else {
						$result .= '<div class="floatleft inline-block valignmiddle divphotoref"><img class="photouserphoto userphoto" alt="No photo" border="0" src="'.DOL_URL_ROOT.'/viewimage.php?modulepart='.$module.'&entity='.$conf->entity.'&file='.urlencode($pathtophoto).'"></div>';
					}

					$result .= '</div>';
				} else {
					$result .= img_object(($notooltip ? '' : $label), ($this->picto ? $this->picto : 'generic'), ($notooltip ? (($withpicto != 2) ? 'class="paddingright"' : '') : 'class="'.(($withpicto != 2) ? 'paddingright ' : '').'classfortooltip"'), 0, 0, $notooltip ? 0 : 1);
				}
			}
		}

		if ($withpicto != 2) {
			$result .= $this->ref;
		}

		$result .= $linkend;
		//if ($withpicto != 2) $result.=(($addlabel && $this->label) ? $sep . dol_trunc($this->label, ($addlabel > 1 ? $addlabel : 0)) : '');

		global $action, $hookmanager;
		$hookmanager->initHooks(array($this->element.'dao'));
		$parameters = array('id'=>$this->id, 'getnomurl' => &$result);
		$reshook = $hookmanager->executeHooks('getNomUrl', $parameters, $this, $action); // Note that $action and $object may have been modified by some hooks
		if ($reshook > 0) {
			$result = $hookmanager->resPrint;
		} else {
			$result .= $hookmanager->resPrint;
		}

		return $result;
	}

	/**
	 *	Return a thumb for kanban views
	 *
	 *	@param      string	    $option                 Where point the link (0=> main card, 1,2 => shipment, 'nolink'=>No link)
	 *  @return		string								HTML Code for Kanban thumb.
	 */
	/*
	public function getKanbanView($option = '')
	{
		$return = '<div class="box-flex-item box-flex-grow-zero">';
		$return .= '<div class="info-box info-box-sm">';
		$return .= '<span class="info-box-icon bg-infobox-action">';
		$return .= img_picto('', $this->picto);
		$return .= '</span>';
		$return .= '<div class="info-box-content">';
		$return .= '<span class="info-box-ref">'.(method_exists($this, 'getNomUrl') ? $this->getNomUrl() : $this->ref).'</span>';
		if (property_exists($this, 'label')) {
			$return .= '<br><span class="info-box-label opacitymedium">'.$this->label.'</span>';
		}
		if (method_exists($this, 'getLibStatut')) {
			$return .= '<br><div class="info-box-status margintoponly">'.$this->getLibStatut(5).'</div>';
		}
		$return .= '</div>';
		$return .= '</div>';
		$return .= '</div>';

		return $return;
	}
	*/

	/**
	 * get response from lib
	 *
	 * @return  string message
	 */
	public function getLibResponse()
	{
		global $langs;
		$langs->load("stancer@stancer");

		if ($this->response != '') {
			return $langs->trans($this->tab_response[$this->response]);
		}
		return '';
	}

	/**
	 *  Return the label of the status
	 *
	 *  @param  int		$mode          0=long label, 1=short label, 2=Picto + short label, 3=Picto, 4=Picto + long label, 5=Short label + Picto, 6=Long label + Picto
	 *  @return	string 			       Label of status
	 */
	public function getLabelStatus($mode = 0)
	{
		return $this->LibStatut($this->status, $mode);
	}

	/**
	 *  Return the label of the status
	 *
	 *  @param  int		$mode          0=long label, 1=short label, 2=Picto + short label, 3=Picto, 4=Picto + long label, 5=Short label + Picto, 6=Long label + Picto
	 *  @return	string 			       Label of status
	 */
	public function getLibStatut($mode = 0)
	{
		return $this->LibStatut($this->status, $mode);
	}

    // phpcs:disable PEAR.NamingConventions.ValidFunctionName.ScopeNotCamelCaps
	/**
	 *  Return the status
	 *
	 *  @param	int		$status        Id status
	 *  @param  int		$mode          0=long label, 1=short label, 2=Picto + short label, 3=Picto, 4=Picto + long label, 5=Short label + Picto, 6=Long label + Picto
	 *  @return string 			       Label of status
	 */
	public function LibStatut($status, $mode = 0)
	{
        // phpcs:enable
		if (empty($this->labelStatus) || empty($this->labelStatusShort)) {
			global $langs;
			$langs->load("stancer@stancer");
			$this->labelStatus[self::STATUS_DRAFT] = $langs->transnoentitiesnoconv('Draft');
			$this->labelStatus[self::STATUS_AUTHORIZED] = $langs->transnoentitiesnoconv('StancerAuthorized');
			$this->labelStatus[self::STATUS_CAPTURED] = $langs->transnoentitiesnoconv('StancerCaptured');
			$this->labelStatus[self::STATUS_CAPTURE_SENT] = $langs->transnoentitiesnoconv('StancerCaptureSent');
			$this->labelStatus[self::STATUS_DISPUTED] = $langs->transnoentitiesnoconv('StancerDisputed');
			$this->labelStatus[self::STATUS_EXPIRED] = $langs->transnoentitiesnoconv('StancerExpired');
			$this->labelStatus[self::STATUS_FAILED] = $langs->transnoentitiesnoconv('StancerFailed');
			$this->labelStatus[self::STATUS_REFUSED] = $langs->transnoentitiesnoconv('StancerRefused');
			$this->labelStatus[self::STATUS_TO_CAPTURE] = $langs->transnoentitiesnoconv('StancerToCapture');

			foreach ($this->labelStatus as $k => $v) {
				$this->labelStatusShort[$k] = $v;
			}
		}


		$statusType = 'status'.$status;
		if ($status == self::STATUS_DRAFT) {
			$statusType = 'status0';
		}
		if ($status == self::STATUS_AUTHORIZED) {
			$statusType = 'status1';
		}
		if ($status == self::STATUS_CAPTURED) {
			$statusType = 'status4';
		}
		if ($status == self::STATUS_CAPTURE_SENT) {
			$statusType = 'status7';
		}
		if ($status == self::STATUS_DISPUTED) {
			$statusType = 'status10';
		}
		if ($status == self::STATUS_EXPIRED) {
			$statusType = 'status9';
		}
		if ($status == self::STATUS_FAILED) {
			$statusType = 'status8';
		}
		if ($status == self::STATUS_REFUSED) {
			$statusType = 'status8';
		}
		if ($status == self::STATUS_TO_CAPTURE) {
			$statusType = 'status3';
		}
		// if ($status == self::STATUS_CANCELED) {
		// 	$statusType = 'status6';
		// }

		return dolGetStatus($this->labelStatus[$status], $this->labelStatusShort[$status], '', $statusType, $mode);
	}

	/**
	 *	Load the info information in the object
	 *
	 *	@param  int		$id       Id of object
	 *	@return	void
	 */
	public function info($id)
	{
		$sql = "SELECT rowid,";
		$sql .= " date_creation as datec, tms as datem,";
		$sql .= " fk_user_creat, fk_user_modif";
		$sql .= " FROM ".MAIN_DB_PREFIX.$this->table_element." as t";
		$sql .= " WHERE t.rowid = ".((int) $id);

		$result = $this->db->query($sql);
		if ($result) {
			if ($this->db->num_rows($result)) {
				$obj = $this->db->fetch_object($result);

				$this->id = $obj->rowid;

				$this->user_creation_id = $obj->fk_user_creat;
				$this->user_modification_id = $obj->fk_user_modif;
				if (!empty($obj->fk_user_valid)) {
					$this->user_validation_id = $obj->fk_user_valid;
				}
				$this->date_creation     = $this->db->jdate($obj->datec);
				$this->date_modification = empty($obj->datem) ? '' : $this->db->jdate($obj->datem);
				if (!empty($obj->datev)) {
					$this->date_validation   = empty($obj->datev) ? '' : $this->db->jdate($obj->datev);
				}
			}

			$this->db->free($result);
		} else {
			dol_print_error($this->db);
		}
	}

	/**
	 * Initialise object with example values
	 * Id must be 0 if object instance is a specimen
	 *
	 * @return void
	 */
	public function initAsSpecimen()
	{
		// Set here init that are not commonf fields
		// $this->property1 = ...
		// $this->property2 = ...

		$this->initAsSpecimenCommon();
	}

	/**
	 * 	Create an array of lines
	 *
	 * 	@return CommonObjectLine[]|int		array of lines if OK, <0 if KO
	 */
	public function getLinesArray()
	{
		$this->lines = array();

		$objectline = new Stancer_paymentsLine($this->db);
		$result = $objectline->fetchAll('ASC', 'position', 0, 0, array('customsql'=>'fk_stancer_payments = '.((int) $this->id)));

		if (is_numeric($result)) {
			$this->error = $objectline->error;
			$this->errors = $objectline->errors;
			return $result;
		} else {
			$this->lines = $result;
			return $this->lines;
		}
	}

	/**
	 *  Returns the reference to the following non used object depending on the active numbering module.
	 *
	 *  @return string      		Object free reference, or '-1' in case of error
	 */
	public function getNextNumRef()
	{
		global $langs, $conf;
		$sql = "SELECT COUNT(*) as count";
		$sql .= " FROM ".MAIN_DB_PREFIX.$this->table_element;
		$resql = $this->db->query($sql);
		if ($resql) {
			$obj = $this->db->fetch_object($resql);
			dol_syslog(__METHOD__.' count = '. $obj->count, LOG_DEBUG);
			// COUNT(*) always comes back as a numeric string: return the ref as the
			// declared string type, callers concatenate or substr() it.
			return (string) (((int) $obj->count) + 1);
		} else {
			$this->errors[] = 'Error '.$this->db->lasterror();
			dol_syslog(__METHOD__.' '.implode(',', $this->errors), LOG_ERR);
			return '-1';
		}
	}

	/**
	 *  Create a document onto disk according to template module.
	 *
	 *  @param	    string		$modele			Force template to use ('' to not force)
	 *  @param		Translate	$outputlangs	objet lang a utiliser pour traduction
	 *  @param      int			$hidedetails    Hide details of lines
	 *  @param      int			$hidedesc       Hide description
	 *  @param      int			$hideref        Hide ref
	 *  @param      null|array  $moreparams     Array to provide more information
	 *  @return     int         				0 if KO, 1 if OK
	 */
	public function generateDocument($modele, $outputlangs, $hidedetails = 0, $hidedesc = 0, $hideref = 0, $moreparams = null)
	{
		global $conf, $langs;

		$result = 0;
		$includedocgeneration = 0;

		$langs->load("stancer@stancer");

		if (!dol_strlen($modele)) {
			$modele = 'standard_stancer_payments';

			if (!empty($this->model_pdf)) {
				$modele = $this->model_pdf;
			} elseif (getDolGlobalString('STANCER_PAYMENTS_ADDON_PDF', '') != '') {
				$modele = getDolGlobalString('STANCER_PAYMENTS_ADDON_PDF');
			}
		}

		$modelpath = "core/modules/stancer/doc/";

		if ($includedocgeneration && !empty($modele)) {
			$result = $this->commonGenerateDocument($modelpath, $modele, $outputlangs, $hidedetails, $hidedesc, $hideref, $moreparams);
		}

		return $result;
	}

	/**
	 * Action executed by scheduler
	 * CAN BE A CRON TASK. In such a case, parameters come from the schedule job setup field 'Parameters'
	 * Use public function doScheduledJob($param1, $param2, ...) to get parameters
	 *
	 * @return	int			0 if OK, <>0 if KO (this function is used also by cron so only 0 is OK)
	 */
	public function doScheduledJob()
	{
		global $conf, $langs;

		//$conf->global->SYSLOG_FILE = 'DOL_DATA_ROOT/dolibarr_mydedicatedlofile.log';

		$error = 0;
		$this->output = '';
		$this->error = '';

		dol_syslog(__METHOD__, LOG_DEBUG);

		$now = dol_now();

		$this->db->begin();

		// ...

		$this->db->commit();

		return $error;
	}

	/**
	 * fill object data from Stancer Payment
	 *
	 * Takes an object of the Stancer PHP SDK (getUniqueId(), populate()->get()...).
	 * The module dropped that SDK for its own StancerApi client, so nothing calls
	 * this method any more: every remaining call site is commented out
	 * (stancer_payouts_list.php, lib/stancer_dispute.lib.php). Kept for third party
	 * code that would still pass an SDK object.
	 *
	 * @deprecated Use fillDataFromApi() instead
	 * @param   mixed $payment Stancer Payment object (deprecated)
	 *
	 * @return  int ret code, <0 on error
	 */
	public function fillData($payment)
	{
		global $conf;
		dol_syslog("Stancer_payments fillData");
		$this->entity = $conf->entity;
		$ret = 0;

		dol_syslog("stancer fillData uuid tracking : this=" . $this->unique_id  . ", stancer=" . $payment->getUniqueId());
		$uuid = $payment->getUniqueId();
		if ($this->unique_id != $uuid) {
			dol_syslog("stancer fillData uuid tracking mic mac, keep our uniqid", LOG_INFO);
			$uuid = $this->unique_id;
		}
		if (empty($uuid)) {
			dol_syslog("stancer fillData uuid stancer empty, keep using our");
			$uuid = $this->unique_id;
		}
		if (empty($uuid)) {
			dol_syslog("stancer fillData uuid stancer AND our are empty, return");
			return -1;
		}

		//le client
		$customer = null;
		try {
			$customer = $payment->getCustomer();
		} catch (Exception $e) {
			dol_syslog("stancer fillData ERROR catch " . $e->getMessage(), LOG_WARNING);
			// $customer = null;
			// return -1;
		}

		// Prefer the CUS=<id> embedded in the tag over the stancer_account mapping.
		// See fillDataFromApi() for the full rationale.
		$fk_soc = null;
		$fk_soc_from_tag = stancerGetCustomerSocidFromTag($uuid);
		if (!empty($fk_soc_from_tag)) {
			$fk_soc = $fk_soc_from_tag;
			dol_syslog("stancer fillData fk_soc=$fk_soc resolved from CUS= tag");
		}

		//TODO customer peut-être vide si c'est payé par TPE physique
		if (null === $customer) {
			//customer is null, use default TPE ?
			if (empty($fk_soc)) {
				$fk_soc = getDolGlobalString('STANCER_DEFAULT_CUSTOMER_IF_NULL');
			}
			$customer = "";
			$email = "default.customer (TPE ?)";
		} else {
			//il faudrait aller récupérer le socid du client dans dolibarr
			if (empty($fk_soc)) {
				$companypaymentmode = new CompanyPaymentModeStancer($this->db);
				$res = $companypaymentmode->fetch(0, '', 0, '', " AND label LIKE 'stancer-%' AND stancer_account = ".$customer);
				if ($res > 0) {
					$fk_soc = $companypaymentmode->fk_soc;
					dol_syslog("stancer fillData fk_soc=$fk_soc resolved from companypaymentmode mapping (no CUS= tag)");
				}
			}
			$email = $customer->getEmail() ?? '(no mail)';
		}
		dol_syslog("stancer fillData : customer is " . json_encode($customer) . " : $email", LOG_DEBUG);

		$json = null;
		//en attendant que fee soit proposé par un accesseur
		try {
			$json = $payment->populate()->get();
		} catch (Exception $e) {
			dol_syslog("stancer payment->populate catch " . $e->getMessage());
		}

		// dol_syslog("stancer fillData : json is " . json_encode($json), LOG_DEBUG);

		//et a cause du throw plutôt que du return null de la lib stancer
		$listOfPropToGet = [
			'stancer_id' => 'Id',
			'amount' => 'Amount',
			'currency' => 'Currency',
			'description' => 'Description',
			'order_id' => 'OrderId',
			'method' => 'Method',
			'card' => 'Card',
			'sepa' => 'Sepa',
			'refunds' => 'Refunds',
			'status' => 'Status',
			'response' => 'Response',
			'capture' => 'Capture',
			'created' => 'Created',
			'date_bank' => 'DateBank',
			'return_url' => 'ReturnUrl'
		];
		// Collected values, fed to fillDataArray() below. Declared here because the
		// loop only writes keys and the block after it reads $data['status'].
		$data = array();
		foreach ($listOfPropToGet as $key => $val) {
			try {
				$func = "get" . $val;
				dol_syslog("stancer listOfPropToGet run $func ...");
				$data[$key] = $payment->{$func};
			} catch (Exception $e) {
				dol_syslog("stancer listOfPropToGet $val catch " . $e->getMessage());
				$data[$key] = null;
				--$ret;
			}
		}

		//try to get data from basic json ...
		if (is_null($data['status']) && isset($json['status'])) {
			$data['status']		= $json['status'];
		}

		// The fee is accounting data of its own: stancerCheckBankLines() books it as a
		// separate negative bank line (stancerAddPaimentFeeOnBank(), lib/stancer_bank.lib.php).
		// $json stays null when populate()->get() threw above, and the key can also be
		// missing from a partial answer. Reading $json['fee'] then would be an undefined
		// index, and skipping the assignment would keep whatever ->fee already held, so
		// the caller could not tell a real zero fee from a missing one. Stop instead,
		// with a log: nothing has been written to the object yet except ->entity.
		if (!is_array($json) || !isset($json['fee'])) {
			$idForLog = '';
			if (isset($data['stancer_id']) && is_scalar($data['stancer_id'])) {
				$idForLog = (string) $data['stancer_id'];
			} elseif (!empty($this->stancer_id)) {
				$idForLog = (string) $this->stancer_id;
			}
			dol_syslog("stancer fillData no fee in Stancer answer for payment " . $idForLog . " (unique_id=" . $uuid . ", answer type=" . gettype($json) . "), object left unchanged and not saved", LOG_ERR);
			return -1;
		}

		$data['fee'] 		= $json['fee'];
		$data['unique_id'] 	= $uuid;
		$data['customer'] 	= $customer;
		$data['live_mode'] 	= getDolGlobalString('STANCER_IS_PROD', '0');
		$data['fk_soc'] 	= $fk_soc;
		$this->fillDataArray($data);

		dol_syslog("Stancer_payments fillData end, returns error = $ret");
		return $ret;
	}

	/**
	 * Copy an associative array of Stancer values into the object properties
	 *
	 * @param	array	$array				Key/value pairs coming from the Stancer API or from the local table
	 * @param	bool	$forceEmptyValues	Also copy empty values, otherwise they are left untouched
	 * @return	void
	 */
	public function fillDataArray($array, $forceEmptyValues = false)
	{
		// Cast for the log line only: a bool interpolates as '' or '1', which reads badly.
		$forceEmptyValuesLog = (int) $forceEmptyValues;
		dol_syslog("Stancer_payments fillDataArray, forceEmptyValues=$forceEmptyValuesLog");
		//dol_syslog("Stancer_payments fillDataArray (listOfPropToGet), input array is " . json_encode($array));
		foreach ($array as $key => $value) {
			if ($key == "status") {
				// print "<p>Capture status = $value ...</p>";
				if (!is_null($value)) {
					$this->status = $this->convert_status_code($value);
					dol_syslog("Stancer_payments fillDataArray statusCode return " . $this->status);
				} else {
					dol_syslog("Stancer_payments fillDataArray statusCode on null value !");
				}
			} elseif (is_object($value)) {
				$v = null;
				if (method_exists($value, "getId")) {
					$v = $value->getId();
				} elseif (method_exists($value, "getTimestamp")) {
					$v = $value->getTimestamp();
				} else {
					$v = json_encode($value);
				}

				//update value only if not empty or if force is enabled
				if ($forceEmptyValues || !empty($v)) {
					$this->{$key} = $v;
				}
			} elseif (is_array($value)) {
				$this->{$key} = json_encode($value);
			} elseif (is_bool($value)) {
				$this->{$key} = $value;
			} else {
				// print "<p>$key is = $value</p>";
				if ($forceEmptyValues || !empty($value)) {
					$this->{$key} = $value;
				}
			}
		}
	}

	// phpcs:disable PEAR.NamingConventions.ValidFunctionName.ScopeNotCamelCaps
	/**
	 * Convert a Stancer status to its Dolibarr counterpart, both ways
	 *
	 * @param	string|int	$value	Stancer status label (string) or Dolibarr status code (int)
	 * @return	string|int			Dolibarr status code for a label, label for a code, STATUS_ERROR if unknown
	 */
	public function convert_status_code($value)
	{
		// phpcs:enable
		$res = self::STATUS_DRAFT;
		if (is_string($value)) {
			$res = array_search($value, self::$tab_status);
			if ($res === false) {
				$res = self::STATUS_ERROR;
			}
		} else {
			if (isset(self::$tab_status[$value])) {
				$res = self::$tab_status[$value];
			} else {
				$res = self::STATUS_ERROR;
			}
		}
		return $res;
	}

	/**
	 * Fill data from API response (without using Stancer PHP library)
	 *
	 * @param array $apiData Data from StancerApi response
	 * @return int 0 on success, <0 on error
	 */
	public function fillDataFromApi($apiData)
	{
		global $conf;
		dol_syslog("Stancer_payments fillDataFromApi");
		$this->entity = $conf->entity;
		$ret = 0;

		// Get unique_id
		$uuid = isset($apiData['unique_id']) ? $apiData['unique_id'] : '';
		if ($this->unique_id != $uuid && !empty($this->unique_id)) {
			dol_syslog("stancer fillDataFromApi uuid tracking mic mac, keep our uniqid", LOG_INFO);
			$uuid = $this->unique_id;
		}
		if (empty($uuid)) {
			$uuid = $this->unique_id;
		}
		if (empty($uuid)) {
			dol_syslog("stancer fillDataFromApi uuid stancer AND our are empty, return");
			return -1;
		}

		// Handle customer
		$customer = isset($apiData['customer']) ? $apiData['customer'] : null;
		$fk_soc = null;

		// Prefer the CUS=<id> embedded in the tag (unique_id / order_id) over the
		// stancer_account -> llx_societe_rib mapping: a same cust_xxx can be linked
		// to several Dolibarr thirdparties (same payer paying for different orgs),
		// and CompanyPaymentMode::fetch() returns only the first match, which is
		// often the wrong one.
		$fk_soc_from_tag = stancerGetCustomerSocidFromTag($uuid);
		if (empty($fk_soc_from_tag)) {
			$orderIdForTag = isset($apiData['order_id']) ? $apiData['order_id'] : '';
			$fk_soc_from_tag = stancerGetCustomerSocidFromTag($orderIdForTag);
		}
		if (!empty($fk_soc_from_tag)) {
			$fk_soc = $fk_soc_from_tag;
			dol_syslog("stancer fillDataFromApi fk_soc=$fk_soc resolved from CUS= tag");
		}

		if (empty($customer)) {
			if (empty($fk_soc)) {
				$fk_soc = getDolGlobalString('STANCER_DEFAULT_CUSTOMER_IF_NULL');
			}
			$email = "default.customer (TPE ?)";
		} else {
			// Customer can be an ID string or an object with id
			$customerId = is_array($customer) ? (isset($customer['id']) ? $customer['id'] : '') : $customer;
			if (empty($fk_soc) && !empty($customerId)) {
				$companypaymentmode = new CompanyPaymentModeStancer($this->db);
				$res = $companypaymentmode->fetch(0, '', 0, '', " AND label LIKE 'stancer-%' AND stancer_account = '".$this->db->escape($customerId)."'");
				if ($res > 0) {
					$fk_soc = $companypaymentmode->fk_soc;
					dol_syslog("stancer fillDataFromApi fk_soc=$fk_soc resolved from companypaymentmode mapping (no CUS= tag)");
				}
			}
			$email = is_array($customer) && isset($customer['email']) ? $customer['email'] : '(no mail)';
		}
		dol_syslog("stancer fillDataFromApi : customer is " . json_encode($customer) . " : $email", LOG_DEBUG);

		// Map API fields to object properties
		$data = array(
			'stancer_id' => isset($apiData['id']) ? $apiData['id'] : '',
			'amount' => isset($apiData['amount']) ? $apiData['amount'] : 0,
			'currency' => isset($apiData['currency']) ? $apiData['currency'] : '',
			'description' => isset($apiData['description']) ? $apiData['description'] : '',
			'order_id' => isset($apiData['order_id']) ? $apiData['order_id'] : '',
			'method' => isset($apiData['method']) ? $apiData['method'] : '',
			'card' => isset($apiData['card']) ? (is_array($apiData['card']) ? $apiData['card']['id'] : $apiData['card']) : null,
			'sepa' => isset($apiData['sepa']) ? (is_array($apiData['sepa']) ? $apiData['sepa']['id'] : $apiData['sepa']) : null,
			'refunds' => isset($apiData['refunds']) ? $apiData['refunds'] : null,
			'status' => isset($apiData['status']) ? $apiData['status'] : '',
			'response' => isset($apiData['response']) ? $apiData['response'] : '',
			'capture' => isset($apiData['capture']) ? $apiData['capture'] : null,
			'created' => isset($apiData['created']) ? $apiData['created'] : null,
			'date_bank' => isset($apiData['date_bank']) ? $apiData['date_bank'] : null,
			'return_url' => isset($apiData['return_url']) ? $apiData['return_url'] : '',
			'fee' => isset($apiData['fee']) ? $apiData['fee'] : 0,
			'unique_id' => $uuid,
			'customer' => is_array($customer) ? (isset($customer['id']) ? $customer['id'] : null) : $customer,
			'live_mode' => getDolGlobalString('STANCER_IS_PROD', '0'),
			'fk_soc' => $fk_soc,
		);

		$this->fillDataArray($data);

		dol_syslog("Stancer_payments fillDataFromApi end, returns error = $ret");
		return $ret;
	}

	/**
	 * Special variant of showOutputField() that puts the link on "order id" instead of "unique id"
	 *
	 * @param	array	$val		Array of properties of the field to show
	 * @param	string	$key		Key of the attribute
	 * @param	string	$object		Value to show
	 * @param	string	$moreparam	More parameters for the HTML field
	 * @param	array	$valU		Array of properties of the unique_id field
	 * @param	string	$keyU		Key of the unique_id attribute
	 * @param	string	$objectU	Value of the unique_id field
	 * @return	string				HTML string to show the field
	 */
	public function showOutputFieldSpecialOrder($val, $key, $object, $moreparam, $valU, $keyU, $objectU)
	{
		$res = $this->showOutputField($valU, "unique_id_switch", $objectU, $moreparam);
		if ($res != "") {
			return $res;
		}
		return $this->showOutputField($val, $key, $object, $moreparam);
	}

	/**
	 * Return HTML string to show a field into a page,
	 * override from showOutputField to put own formats
	 *
	 * @param  array   $val		       Array of properties of field to show
	 * @param  string  $key            Key of attribute
	 * @param  string  $object         list object with preselected value to show (for date type it must be in timestamp format, for amount or price it must be a php numeric value)
	 * @param  string  $moreparam      To add more parameters on html input tag
	 * @param  string  $keysuffix      Prefix string to add into name and id of field (can be used to avoid duplicate names)
	 * @param  string  $keyprefix      Suffix string to add into name and id of field (can be used to avoid duplicate names)
	 * @param  mixed   $showsize       Value for css to define size. May also be a numeric.
	 * @return string
	 */
	public function showOutputField($val, $key, $object, $moreparam = '', $keysuffix = '', $keyprefix = '', $showsize = 0)
	{
		global $conf, $langs, $form;

		//stancer store amount in cents
		if (in_array($key, ['amount','fee'])) {
			return price((float) $object / 100);
		} elseif ($key == 'stancer_id') {
			$linkExternal = "<a href='https://manage.stancer.com/fr/details-de-paiement?id=" . $object . "' target='_stancer'>" . img_picto($langs->trans('ShowInStancer'), 'globe') . " " . $object . "</a>";
			$linkRaw = '';
			if (getDolGlobalString('STANCER_SHOW_RAW_API_PICTO', '0') == '1') {
				$linkRaw = " <a href='#' class='stancer-raw-link' data-stancer-type='payment' data-stancer-id='" . dol_escape_htmltag($object) . "' title='" . dol_escape_htmltag($langs->trans('ShowRawApiResponse')) . "'>" . img_picto($langs->trans('ShowRawApiResponse'), 'search') . "</a>";
			}
			return $linkExternal . $linkRaw;
		} elseif ($key == 'customer') {
			// Show the Stancer customer id + the Dolibarr thirdparty(ies) it maps to via
			// societe_rib.stancer_account (the "Stancer name"). Displayed next to the row's
			// fk_soc thirdparty so a divergence (the customer-id mixing bug) is obvious.
			$idHtml = dol_escape_htmltag((string) $object);
			if ((string) $object === '') {
				return $idHtml;
			}
			$nameHtml = $this->getStancerCustomerNameHtml((string) $object);
			return $nameHtml !== '' ? ($idHtml . ' <span class="opacitymedium">(' . $nameHtml . ')</span>') : $idHtml;
		} elseif ($key == 'order_id') {
		} elseif ($key == 'unique_id_switch') {
			//split tag to make link to object
			$tmptag = dolExplodeIntoArray($object, '.', '=');
			// The tag value must be a positive rowid. Guard it before fetching: fetch(0) does
			// not mean "not found" in the core classes. Facture and Commande return -1 (a truthy
			// value) on an empty id, Don and Adherent drop the rowid filter and load the FIRST
			// record of the table, which would render a link to an unrelated object.
			if (isset($tmptag['INV'])) {
				$targetId = (int) $tmptag['INV'];
				if ($targetId <= 0) {
					dol_syslog("stancer showOutputField unique_id_switch: INV tag '".$tmptag['INV']."' is not a valid rowid, no invoice link built", LOG_WARNING);
				} else {
					$fac = new Facture($this->db);
					if ($fac->fetch($targetId)) {
						return $fac->getNomUrl(1, '', 0, 0, '');
					}
				}
			} elseif (isset($tmptag['ORD'])) {
				$targetId = (int) $tmptag['ORD'];
				if ($targetId <= 0) {
					dol_syslog("stancer showOutputField unique_id_switch: ORD tag '".$tmptag['ORD']."' is not a valid rowid, no order link built", LOG_WARNING);
				} else {
					$ord = new Commande($this->db);
					if ($ord->fetch($targetId)) {
						// Commande::getNomUrl($withpicto, $option, $max, $short, $notooltip)
						return $ord->getNomUrl(1, '', 0, 0, 0);
					}
				}
			} elseif (isset($tmptag['DON'])) {
				$targetId = (int) $tmptag['DON'];
				if ($targetId <= 0) {
					dol_syslog("stancer showOutputField unique_id_switch: DON tag '".$tmptag['DON']."' is not a valid rowid, no donation link built", LOG_WARNING);
				} else {
					$don = new Don($this->db);
					if ($don->fetch($targetId)) {
						// Don::getNomUrl($withpicto, $notooltip, $moretitle, $save_lastsearch_value)
						return $don->getNomUrl(1);
					}
				}
			} elseif (isset($tmptag['MEM'])) {
				$targetId = (int) $tmptag['MEM'];
				if ($targetId <= 0) {
					dol_syslog("stancer showOutputField unique_id_switch: MEM tag '".$tmptag['MEM']."' is not a valid rowid, no member link built", LOG_WARNING);
				} else {
					$adh = new AdherentStancer($this->db);
					if ($adh->fetch($targetId)) {
						// Adherent::getNomUrl($withpictoimg, $maxlen, $option, $mode, $morecss)
						return $adh->getNomUrl(1, 0, 'card', '', '');
					}
				}
			}
			//return "<a href='https://manage.stancer.com/fr/details-de-paiement?id=" . $object . "' target='_stancer'>" . img_picto($langs->trans('ShowInStancer'), 'globe') . " " . $object . "</a>";
		}

		return parent::showOutputField($val, $key, $object, $moreparam, $keysuffix, $keyprefix, $showsize);
	}

	/**
	 * Per-request cache for Stancer customer id -> thirdparty name(s) HTML,
	 * to avoid one SQL query per row when rendering a list.
	 *
	 * @var array<string,string>
	 */
	protected static $stancerCustomerNameCache = array();

	/**
	 * Resolve the Dolibarr thirdparty(ies) linked to a Stancer customer id
	 * (cust_xxx) through societe_rib.stancer_account, and return them as clickable
	 * HTML links. When several thirdparties share the same Stancer customer id
	 * (the id-mixing bug), all are returned comma-separated, which makes the
	 * anomaly visible directly in the list.
	 *
	 * @param  string  $custId  Stancer customer id (cust_xxx).
	 * @return string           HTML links (may be empty when no mapping is found).
	 */
	public function getStancerCustomerNameHtml($custId)
	{
		$custId = (string) $custId;
		if ($custId === '') {
			return '';
		}
		if (!array_key_exists($custId, self::$stancerCustomerNameCache)) {
			$names = array();
			$sql = "SELECT DISTINCT s.rowid, s.nom";
			$sql .= " FROM " . MAIN_DB_PREFIX . "societe_rib r";
			$sql .= " INNER JOIN " . MAIN_DB_PREFIX . "societe s ON s.rowid = r.fk_soc";
			$sql .= " WHERE r.stancer_account = '" . $this->db->escape($custId) . "'";
			$sql .= " AND s.entity IN (" . getEntity('societe') . ")";
			$resql = $this->db->query($sql);
			if ($resql) {
				while (($o = $this->db->fetch_object($resql))) {
					$url = DOL_URL_ROOT . '/societe/card.php?socid=' . (int) $o->rowid;
					$names[] = '<a href="' . $url . '">' . dol_escape_htmltag((string) $o->nom) . '</a>';
				}
				$this->db->free($resql);
			} else {
				dol_syslog("stancer getStancerCustomerNameHtml SQL error for custId=" . $custId . ": " . $this->db->lasterror(), LOG_ERR);
			}
			self::$stancerCustomerNameCache[$custId] = implode(', ', $names);
		}
		return self::$stancerCustomerNameCache[$custId];
	}


	/**
	 * return true if that payment is initial process pay is ok (initial ok but maybe not confirmed) + confirmed
	 *
	 * @return	bool	True when the current status means the payment went through, even if the capture is not confirmed yet
	 */
	public function isInitPaid()
	{
		//quels sont les etats où on considere cette transaction payée ?
		$listOfPaidStatus = [
			self::STATUS_AUTHORIZED, // The bank authorized the payment but the transaction will only be processed when the capture will be set to true
			self::STATUS_CAPTURED,   // The amount of the payment have been credited to your account
			self::STATUS_CAPTURE_SENT, // The capture operation is being processed, the payment can not be cancelled anymore, refunds must wait the end of the capture process
			// self::STATUS_DISPUTED, // The customer declined the payment after it have been captured on your account
			self::STATUS_TO_CAPTURE // The bank authorized the payment, money will be processed within the day
		];
		return in_array($this->status, $listOfPaidStatus);
	}

	/**
	 * Return true if that payment is really paid
	 *
	 * @param	int|null	$statusCode		Status to test, current object status when null
	 * @return	bool						True when the money is definitely credited
	 */
	public function isDefinitivePaid($statusCode = null)
	{
		if (empty($statusCode)) {
			$statusCode = $this->status;
		}
		//quels sont les etats où on considere cette transaction payée ?
		$listOfPaidStatus = [
			self::STATUS_CAPTURE_SENT, // The capture operation is being processed, the payment can not be cancelled anymore, refunds must wait the end of the capture process
			self::STATUS_CAPTURED,   // The amount of the payment have been credited to your account
		];

		return in_array($statusCode, $listOfPaidStatus);
	}



	/**
	 * return true if that payment is reusable
	 *
	 * @return	bool	True when the payment is still a draft and can be reused for a new attempt
	 */
	public function canBeReused()
	{
		$listOfPaidStatus = [
			self::STATUS_DRAFT, // The bank authorized the payment but the transaction will only be processed when the capture will be set to true
		];
		return in_array($this->status, $listOfPaidStatus);
	}
}


require_once DOL_DOCUMENT_ROOT.'/core/class/commonobjectline.class.php';

/**
 * Class Stancer_paymentsLine. You can also remove this and generate a CRUD class for lines objects.
 */
class Stancer_paymentsLine extends CommonObjectLine
{
	// To complete with content of an object Stancer_paymentsLine
	// We should have a field rowid, fk_stancer_payments and position
	public $db;

	/**
	 * @var int  Does object support extrafields ? 0=No, 1=Yes
	 */
	public $isextrafieldmanaged = 0;

	/**
	 * Constructor
	 *
	 * @param DoliDb $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;
	}
}
