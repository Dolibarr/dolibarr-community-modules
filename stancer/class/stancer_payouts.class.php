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
 * \file        class/stancer_payouts.class.php
 * \ingroup     stancer
 * \brief       This file is a CRUD class file for Stancer_payouts (Create/Read/Update/Delete)
 */

// Put here all includes required by your class file
require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';
//require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
//require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';

/**
 * Class for Stancer_payouts
 */
class Stancer_payouts extends CommonObject
{
	public const TRIGGER_PREFIX = 'STANCER_PAYOUT';

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
	public $element = 'stancer_payouts';

	/**
	 * @var string Name of table without prefix where object is stored. This is also the key used for extrafields management.
	 */
	public $table_element = 'stancer_stancer_payouts';

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
	 * @var string String with name of icon for stancer_payouts. Must be a 'fa-xxx' fontawesome code (or 'fa-xxx_fa_color_size') or 'stancer_payouts@stancer' if picto is file 'img/object_stancer_payouts.png'.
	 */
	public $picto = 'fa-file';


	public const STATUS_ERROR = -10;
	public const STATUS_DRAFT = 0;
	public const STATUS_PENDING = 1;
	public const STATUS_TO_PAY = 2;
	public const STATUS_SENT = 3;
	public const STATUS_PAID = 4;
	public const STATUS_FAILED = 5;
	public const STATUS_CANCELED = 9;
	public const STATUS_VALIDATED = 10;

	// internal stancer status
	public $tab_status = array(
		'-10'=>'error',
		'0'=>'draft',
		'1'=>'pending',
		'2'=>'to_pay',
		'3'=>'sent',
		'4'=>'paid',
		'5'=>'failed',
		'9'=>'canceled',
		'10'=>'validated',
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
		'payout_id' => array('type'=>'varchar(30)', 'label'=>'PayoutID', 'enabled'=>'1', 'position'=>20, 'notnull'=>1, 'visible'=>1, 'index'=>1, 'showoncombobox'=>'1', 'validate'=>'1', 'comment'=>"Payout ID"),
		'amount' => array('type'=>'integer', 'label'=>'Amount', 'enabled'=>'1', 'position'=>40, 'notnull'=>0, 'visible'=>1, 'default'=>'null', 'isameasure'=>'1', 'help'=>"The total credit transfer amount you will receive", 'validate'=>'1',),
		'fees' => array('type'=>'integer', 'label'=>'Fees', 'enabled'=>'1', 'position'=>41, 'notnull'=>0, 'visible'=>1, 'showoncombobox'=>'1', 'isameasure'=>'1', 'validate'=>'1', 'comment'=>"The fees you paid for processing the payouts"),
		'fees_vat' => array('type'=>'integer', 'label'=>'FeesVat', 'enabled'=>'1', 'position'=>411, 'notnull'=>0, 'visible'=>-1, 'showoncombobox'=>'0', 'isameasure'=>'1', 'validate'=>'0', 'comment'=>"VAT applied on fees by Stancer"),
		'amount_net' => array('type'=>'integer', 'label'=>'Net', 'enabled'=>'1', 'position'=>42, 'notnull'=>0, 'visible'=>1, 'showoncombobox'=>'0', 'isameasure'=>'1', 'validate'=>'0', 'comment'=>"Amount less Fees"),
		'currency' => array('type'=>'varchar(4)', 'label'=>'Currency', 'enabled'=>'1', 'position'=>50, 'notnull'=>0, 'visible'=>-1, 'showoncombobox'=>'1', 'validate'=>'1', 'comment'=>"Currency"),
		'date_paym' => array('type'=>'datetime', 'label'=>'date_paym', 'enabled'=>'1', 'position'=>60, 'notnull'=>0, 'visible'=>1, 'showoncombobox'=>'1', 'validate'=>'1', 'comment'=>"The date the payout transactions were made"),
		'date_bank' => array('type'=>'datetime', 'label'=>'date_bank', 'enabled'=>'1', 'position'=>70, 'notnull'=>0, 'visible'=>1, 'showoncombobox'=>'1', 'validate'=>'1', 'comment'=>"The date you will receive the credit transfer"),
		'details' => array('type'=>'text', 'label'=>'details', 'enabled'=>'1', 'position'=>90, 'notnull'=>0, 'visible'=>-1, 'showoncombobox'=>'1', 'comment'=>"Details"),
		'payments' => array('type'=>'text', 'label'=>'payments', 'enabled'=>'1', 'position'=>100, 'notnull'=>0, 'visible'=>-1, 'showoncombobox'=>'1', 'comment'=>"statementDescription"),
		'refunds' => array('type'=>'text', 'label'=>'refunds', 'enabled'=>'1', 'position'=>110, 'notnull'=>0, 'visible'=>1, 'showoncombobox'=>'0', 'comment'=>"Refunds aggregated on this payout (JSON)"),
		'disputes' => array('type'=>'text', 'label'=>'disputes', 'enabled'=>'1', 'position'=>120, 'notnull'=>0, 'visible'=>1, 'showoncombobox'=>'0', 'comment'=>"Disputes aggregated on this payout (JSON)"),
		'statement_description' => array('type'=>'text', 'label'=>'statementDescription', 'enabled'=>'1', 'position'=>130, 'notnull'=>0, 'visible'=>-1, 'showoncombobox'=>'1', 'comment'=>"statementDescription"),
		'tms' => array('type'=>'timestamp', 'label'=>'DateModification', 'enabled'=>'1', 'position'=>501, 'notnull'=>0, 'visible'=>-2,),
		'status' => array('type'=>'integer', 'label'=>'Status', 'enabled'=>'1', 'position'=>2000, 'notnull'=>1, 'visible'=>1, 'index'=>1, 'arrayofkeyval'=>array('1'=>'authorized', '2'=>'captured', '3'=>'capture_sent', '4'=>'disputed', '5'=>'expired', '6'=>'failed', '7'=>'refused', '8'=>'to_capture'), 'validate'=>'1',),
		'entity' => array('type'=>'integer', 'label'=>'Entity', 'enabled'=>'1', 'position'=>3000, 'notnull'=>1, 'visible'=>0,)
	);
	public $rowid;
	public $payout_id;
	public $amount;
	public $currency;
	public $date_bank;
	public $date_paym;
	public $details;
	public $fees;
	public $fees_vat;
	public $amount_net;
	public $payments;
	public $refunds;
	public $disputes;
	public $statement_description;
	public $tms;
	public $status;
	public $entity;
	public $live_mode;
	// END MODULEBUILDER PROPERTIES

	/**
	 * @var array Array of lines
	 */
	public $lines = array();

	/**
	 * @var string New reference after validation
	 */
	public $newref;

	/**
	 * @var int|string Date of creation
	 */
	public $date_creation;

	/**
	 * @var int|string Date of modification
	 */
	public $date_modification;

	/**
	 * @var int|string Date of validation
	 */
	public $date_validation;

	public $response;
	public $tab_response;


	// If this object has a subtable with lines

	// /**
	//  * @var string    Name of subtable line
	//  */
	// public $table_element_line = 'stancer_stancer_payoutsline';

	// /**
	//  * @var string    Field with ID of parent key if this object has a parent
	//  */
	// public $fk_element = 'fk_stancer_payouts';

	// /**
	//  * @var string    Name of subtable class that manage subtable lines
	//  */
	// public $class_element_line = 'Stancer_payoutsline';

	// /**
	//  * @var array	List of child tables. To test if we can delete object.
	//  */
	// protected $childtables = array();

	// /**
	//  * @var array    List of child tables. To know object to delete on cascade.
	//  *               If name matches '@ClassNAme:FilePathClass;ParentFkFieldName' it will
	//  *               call method deleteByParentField(parentId, ParentFkFieldName) to fetch and delete child object
	//  */
	// protected $childtablesoncascade = array('stancer_stancer_payoutsdet');

	// /**
	//  * @var Stancer_payoutsLine[]     Array of subtable lines
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

		if (getDolGlobalString('MAIN_SHOW_TECHNICAL_ID', '') == '' && isset($this->fields['rowid']) && !empty($this->fields['ref'])) {
			$this->fields['rowid']['visible'] = 0;
		}
		if (!isModEnabled('multicompany') && isset($this->fields['entity'])) {
			$this->fields['entity']['enabled'] = 0;
		}

		// Example to show how to set values of fields definition dynamically
		/*if ($user->rights->stancer->stancer_payouts->read) {
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
	 * @param int         $id         Id object
	 * @param string|null $ref        Ref
	 * @param string|null $payout_id  Stancer payout id, used when neither id nor ref is known
	 * @return int                    <0 if KO, 0 if not found, >0 if OK
	 */
	public function fetch($id, $ref = null, $payout_id = null)//, $uuid=null)
	{
		$more = "";
		if (!empty($payout_id)) {
			$more = " AND payout_id='" . $this->db->escape($payout_id) . "'";
		}
		//        if (!empty($uuid)) {
		//            $more = " AND unique_id='" . $this->db->escape($uuid) . "'";
		//        }
		$result = $this->fetchCommon($id, $ref, $more);
		if ($result > 0 && !empty($this->table_element_line)) {
			$this->fetchLines();
		}
		return $result;
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
	 * @param  string      $filtermode   Filter mode (AND or OR)
	 * @return array<int,Stancer_payouts>|int          int <0 if KO, array of records (possibly empty) if OK
	 * @phan-suppress PhanPluginMoreSpecificActualReturnType  The array is legitimately empty when no row matches the filter, so the non-empty-array type inferred by Phan must not be documented here
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
			// Whitelist the glue: $filtermode comes from the caller and must never reach the SQL as is.
			$sql .= " AND (".implode((strtoupper($filtermode) == 'OR' ? " OR " : " AND "), $sqlwhere).")";
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
		return $this->updateCommon($user, $notrig);	}

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

		// Update status only (no ref column in Stancer tables, we use payout_id as reference)
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
			$result = $this->call_trigger('STANCER_PAYOUTS_VALIDATE', $user);
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

		return $this->setStatusCommon($user, self::STATUS_DRAFT, $notrigger, 'STANCER_PAYOUTS_UNVALIDATE');
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

		return $this->setStatusCommon($user, self::STATUS_CANCELED, $notrigger, 'STANCER_PAYOUTS_CANCEL');
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

		return $this->setStatusCommon($user, self::STATUS_VALIDATED, $notrigger, 'STANCER_PAYOUTS_REOPEN');
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

		$label = img_picto('', $this->picto).' <u>'.$langs->trans("Stancer_payouts").'</u>';
		if (isset($this->status)) {
			$label .= ' '.$this->getLibStatut(5);
		}
		$label .= '<br>';
		$label .= '<b>'.$langs->trans('Ref').':</b> '.$this->ref;

		$url = dol_buildpath('/stancer/stancer_payouts_card.php', 1).'?id='.$this->id;

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
				$label = $langs->trans("ShowStancer_payouts");
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
				require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';

				list($class, $module) = explode('@', $this->picto);
				$upload_dir = $conf->$module->multidir_output[$conf->entity]."/$class/".dol_sanitizeFileName($this->ref);
				$filearray = dol_dir_list($upload_dir, "files");
				$filename = $filearray[0]['name'];
				if (!empty($filename)) {
					$pospoint = strpos($filearray[0]['name'], '.');
					$pathtophoto = $class.'/'.$this->ref.'/thumbs/';
					if ($pospoint !== false) {
						$pathtophoto .= substr($filename, 0, $pospoint).'_mini'.substr($filename, $pospoint);
					} else {
						$pathtophoto .= $filename.'_mini';
					}

					if (empty($conf->global->{strtoupper($module.'_'.$class).'_FORMATLISTPHOTOSASUSERS'})) {
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
	 * get translated response
	 *
	 * @return  string translated string
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
			$this->labelStatus[self::STATUS_PENDING] = $langs->transnoentitiesnoconv('StancerPending');
			$this->labelStatus[self::STATUS_TO_PAY] = $langs->transnoentitiesnoconv('StancerToPay');
			$this->labelStatus[self::STATUS_SENT] = $langs->transnoentitiesnoconv('StancerCaptureSent');
			$this->labelStatus[self::STATUS_PAID] = $langs->transnoentitiesnoconv('StancerPaid');
			$this->labelStatus[self::STATUS_FAILED] = $langs->transnoentitiesnoconv('StancerFailed');

			foreach ($this->labelStatus as $k => $v) {
				$this->labelStatusShort[$k] = $v;
			}
		}


		$statusType = 'status'.$status;
		if ($status == self::STATUS_DRAFT) {
			$statusType = 'status0';
		}
		if ($status == self::STATUS_PENDING) {
			$statusType = 'status1';
		}
		if ($status == self::STATUS_TO_PAY) {
			$statusType = 'status1';
		}
		if ($status == self::STATUS_SENT) {
			$statusType = 'status7';
		}
		if ($status == self::STATUS_PAID) {
			$statusType = 'status4';
		}
		if ($status == self::STATUS_FAILED) {
			$statusType = 'status8';
		}

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

		$objectline = new Stancer_payoutsLine($this->db);
		$result = $objectline->fetchAll('ASC', 'position', 0, 0, array('customsql'=>'fk_stancer_payouts = '.((int) $this->id)));

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
	 *  @return int      		Object free reference (row count + 1), or -1 in case of error
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
			return (int) ($obj->count + 1);
		} else {
			$this->errors[] = 'Error '.$this->db->lasterror();
			dol_syslog(__METHOD__.' '.implode(',', $this->errors), LOG_ERR);
			return -1;
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
			$modele = 'standard_stancer_payouts';

			if (!empty($this->model_pdf)) {
				$modele = $this->model_pdf;
			} elseif (getDolGlobalString('STANCER_PAYOUTS_ADDON_PDF', '') != '') {
				$modele = getDolGlobalString('STANCER_PAYOUTS_ADDON_PDF');
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
	 * fill object data from Stancer Payout
	 *
	 * @deprecated Use fillDataFromApi() instead
	 * @param   mixed $payout Stancer Payout object (deprecated)
	 * @return  void
	 */
	public function fillData($payout)
	{
		global $conf;
		$this->entity = $conf->entity;
		$data = null;

		//joel astuce
		$res = $payout->populate()->get();
		// if($payout->getId() == "pout_W1PpmCVl8CUfiRty3BbHRCVl") {
		// print "<p>RES = " . json_encode($payout->getFees()) . "</p>";
		// print json_encode($payout);exit;
		// }
		try {
			$data = [
				'payout_id' => $payout->getId(),
				'amount' => $res['payments']['amount'] ?? $res['total'], //en attendant d'avoir $payout->getAmount(),
				'currency' => $payout->getCurrency(),
				'status' => $payout->getStatus(),
				'date_creation' => $payout->getCreated(),
				'date_bank' => $payout->getDateBank(),
				'live_mode' => getDolGlobalString('STANCER_IS_PROD', '0'),
				'date_paym' => $payout->getDatePaym(),
				'details' => $payout->getDetails(),
				// 'payments' => $payout->getPayments(),
				// 'refunds' => $payout->getRefunds(),
				// 'disputes' => $payout->getDisputes(),
				'statement_description' => $payout->getStatementDescription(),
				'fees' => $payout->getFees(),
				//champ calculé
				'amount_net' => $res['payments']['amount'] - $payout->getFees()
			];
		} catch (Exception $e) {
			$message = $e->getMessage();
			dol_syslog("StancerPayout::fillData exception occurs for payout " . json_encode($payout), LOG_ERR);
		}
		// print "<p>" . json_encode($data) . "</p>";

		$this->fillDataArray($data);
	}

	/**
	 * Copy an associative array of Stancer values into the object properties
	 *
	 * @param	array	$array	Key/value pairs coming from the Stancer API or from the local table
	 * @return	void
	 */
	public function fillDataArray($array)
	{
		foreach ($array as $key => $value) {
			if ($key == "status") {
				// print "<p>Capture status = $value ...</p>";
				$this->{$key} = $this->convert_status_code($value);
			} elseif (is_object($value)) {
				if (method_exists($value, "getId")) {
					$this->{$key} = $value->getId();
				} elseif (method_exists($value, "getTimestamp")) {
					$this->{$key} = $value->getTimestamp();
				} else {
					$this->{$key} = json_encode($value);
				}
			} elseif (is_array($value)) {
				$this->{$key} = json_encode($value);
			} elseif (is_bool($value)) {
				$this->{$key} = $value;
			} else {
				// print "<p>$key is = $value</p>";
				$this->{$key} = trim($value);
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
			$res = array_search($value, $this->tab_status);
			if ($res === false) {
				$res = self::STATUS_ERROR;
			}
		} else {
			if (isset($this->tab_status[$value])) {
				$res = $this->tab_status[$value];
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
		$this->entity = $conf->entity;

		// Stancer v2 payout has 3 movement blocks: payments, refunds, disputes.
		// Real gross settlement = payments.amount + refunds.amount + disputes.amount
		// (refunds.amount and disputes.amount are <= 0 when they reduce the payout).
		$paymentsAmount = isset($apiData['payments']['amount']) ? (int) $apiData['payments']['amount'] : (isset($apiData['total']) ? (int) $apiData['total'] : 0);
		$refundsAmount = isset($apiData['refunds']['amount']) ? (int) $apiData['refunds']['amount'] : 0;
		$disputesAmount = isset($apiData['disputes']['amount']) ? (int) $apiData['disputes']['amount'] : 0;
		$grossAmount = $paymentsAmount + $refundsAmount + $disputesAmount;

		$fees = isset($apiData['fees']) ? (int) $apiData['fees'] : 0;
		$feesVat = isset($apiData['fees_vat']) ? (int) $apiData['fees_vat'] : 0;

		// Prefer the "amount" field returned by Stancer (real net received on bank).
		// Fallback to gross - fees - fees_vat when absent (legacy responses).
		if (isset($apiData['amount'])) {
			$netAmount = (int) $apiData['amount'];
		} else {
			$netAmount = $grossAmount - $fees - $feesVat;
		}

		$data = array(
			'payout_id' => isset($apiData['id']) ? $apiData['id'] : '',
			'amount' => $grossAmount,
			'currency' => isset($apiData['currency']) ? $apiData['currency'] : '',
			'status' => isset($apiData['status']) ? $apiData['status'] : '',
			'date_creation' => isset($apiData['created']) ? $apiData['created'] : (isset($apiData['date']) ? $apiData['date'] : null),
			'date_bank' => isset($apiData['date_bank']) ? $apiData['date_bank'] : null,
			'live_mode' => getDolGlobalString('STANCER_IS_PROD', '0'),
			'date_paym' => isset($apiData['date_paym']) ? $apiData['date_paym'] : null,
			'details' => isset($apiData['details']) ? $apiData['details'] : null,
			'payments' => isset($apiData['payments']) ? $apiData['payments'] : null,
			'refunds' => isset($apiData['refunds']) ? $apiData['refunds'] : null,
			'disputes' => isset($apiData['disputes']) ? $apiData['disputes'] : null,
			'statement_description' => isset($apiData['statement_description']) ? $apiData['statement_description'] : '',
			'fees' => $fees,
			'fees_vat' => $feesVat,
			'amount_net' => $netAmount
		);

		$this->fillDataArray($data);
		return 0;
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
		if (in_array($key, ['amount','fees'])) {
			// print "clé = $key, val=" . json_encode($object);
			return price((float) $object / 100);
		}
		if ($key == 'amount_net') {
			if (empty($object)) {
				$object = $this->amount - $this->fees;
			}
			return price((float) $object / 100);
		}
		if ($key == 'payout_id') {
				$linkExternal = "<a href='https://manage.stancer.com/fr/details-du-reversement?id=" . $object . "' target='_stancer'>" . img_picto($langs->trans('ShowInStancer'), 'globe') . " " . $object . "</a>";
				$linkRaw = '';
			if (getDolGlobalString('STANCER_SHOW_RAW_API_PICTO', '0') == '1') {
				$linkRaw = " <a href='#' class='stancer-raw-link' data-stancer-type='payout' data-stancer-id='" . dol_escape_htmltag($object) . "' title='" . dol_escape_htmltag($langs->trans('ShowRawApiResponse')) . "'>" . img_picto($langs->trans('ShowRawApiResponse'), 'search') . "</a>";
			}
				$csrfToken = function_exists('newToken') ? newToken() : '';
				$refreshHref = "?action=refreshone&payout_id=" . urlencode($object) . "&token=" . $csrfToken;
				$linkRefresh = " <a href='" . $refreshHref . "' title='" . dol_escape_htmltag($langs->trans('StancerRefreshThisPayout')) . "'>" . img_picto($langs->trans('StancerRefreshThisPayout'), 'refresh') . "</a>";
				return $linkExternal . $linkRaw . $linkRefresh;
		}

		// For refunds/disputes columns, show only the aggregated amount (cents -> euros)
		// and nothing when the block is empty or amount is zero.
		if ($key == 'refunds' || $key == 'disputes') {
			if (empty($object)) {
				return '';
			}
			$decoded = is_array($object) ? $object : json_decode($object, true);
			if (!is_array($decoded) || !isset($decoded['amount']) || (int) $decoded['amount'] == 0) {
				return '';
			}
			$amt = (int) $decoded['amount'];
			$css = $amt < 0 ? 'color:#c00;' : '';
			return '<span style="' . $css . '">' . price($amt / 100) . '</span>';
		}

		return parent::showOutputField($val, $key, $object, $moreparam, $keysuffix, $keyprefix, $showsize);
	}
}


require_once DOL_DOCUMENT_ROOT.'/core/class/commonobjectline.class.php';

/**
 * Class Stancer_payoutsLine. You can also remove this and generate a CRUD class for lines objects.
 */
class Stancer_payoutsLine extends CommonObjectLine
{
	// To complete with content of an object Stancer_payoutsLine
	// We should have a field rowid, fk_stancer_payouts and position
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
