<?php
/* Copyright (C) 2017  Laurent Destailleur      <eldy@users.sourceforge.net>
 * Copyright (C) 2024 Eric Seigne <eric.seigne@cap-rel.fr>
 * Copyright (C) 2026		MDW							<mdeweerd@users.noreply.github.com>
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
 * \file        class/stancer_disputes.class.php
 * \ingroup     stancer
 * \brief       This file is a CRUD class file for Stancer_disputes (Create/Read/Update/Delete)
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';

/**
 * Class for Stancer_disputes
 */
class Stancer_disputes extends CommonObject
{
	public const TRIGGER_PREFIX = 'STANCER_DISPUTE';

	public $socid;
	public $labelStatusShort;
	public $labelStatus;
	public $output;
	public $user_creation_id;
	public $user_modification_id;
	public $date_modification;

	/**
	 * @var string ID of module.
	 */
	public $module = 'stancer';

	/**
	 * @var string ID to identify managed object.
	 */
	public $element = 'stancer_disputes';

	/**
	 * @var string Name of table without prefix where object is stored.
	 */
	public $table_element = 'stancer_stancer_disputes';

	/**
	 * @var int  Does this object support multicompany module ?
	 */
	public $ismultientitymanaged = 0;

	/**
	 * @var int  Does object support extrafields ?
	 */
	public $isextrafieldmanaged = 0;

	/**
	 * @var string String with name of icon for stancer_disputes.
	 */
	public $picto = 'fa-exclamation-triangle';

	const STATUS_OPEN = 0;
	const STATUS_WON = 1;
	const STATUS_LOST = 2;

	// BEGIN MODULEBUILDER PROPERTIES
	/**
	 * @var array  Array with all fields and their property.
	 */
	public $fields = array(
		'rowid' => array('type'=>'integer', 'label'=>'TechnicalID', 'enabled'=>'1', 'position'=>1, 'notnull'=>1, 'visible'=>0, 'noteditable'=>'1', 'index'=>1, 'css'=>'left', 'comment'=>"Id", 'default'=>null),
		'dispute_id' => array('type'=>'varchar(30)', 'label'=>'StancerDisputeID', 'enabled'=>'1', 'position'=>20, 'notnull'=>1, 'visible'=>1, 'index'=>1, 'showoncombobox'=>'1', 'validate'=>'1', 'comment'=>"Stancer dispute ID", 'default'=>null),
		'payment_id' => array('type'=>'varchar(30)', 'label'=>'StancerPaymentID', 'enabled'=>'1', 'position'=>25, 'notnull'=>1, 'visible'=>1, 'index'=>1, 'showoncombobox'=>'1', 'validate'=>'1', 'comment'=>"Related payment ID", 'default'=>null),
		'amount' => array('type'=>'integer', 'label'=>'Amount', 'enabled'=>'1', 'position'=>30, 'notnull'=>0, 'visible'=>1, 'default'=>null, 'isameasure'=>'1', 'help'=>"Amount in cents", 'validate'=>'1'),
		'fee' => array('type'=>'integer', 'label'=>'Fee', 'enabled'=>'1', 'position'=>35, 'notnull'=>0, 'visible'=>1, 'default'=>null, 'isameasure'=>'1', 'help'=>"Fee in cents", 'validate'=>'1'),
		'currency' => array('type'=>'varchar(4)', 'label'=>'Currency', 'enabled'=>'1', 'position'=>40, 'notnull'=>0, 'visible'=>-1, 'showoncombobox'=>'1', 'validate'=>'1', 'comment'=>"Currency", 'default'=>null),
		'dispute_type' => array('type'=>'varchar(30)', 'label'=>'Type', 'enabled'=>'1', 'position'=>45, 'notnull'=>0, 'visible'=>1, 'validate'=>'1', 'comment'=>"Dispute type", 'default'=>null),
		'status' => array('type'=>'varchar(30)', 'label'=>'Status', 'enabled'=>'1', 'position'=>50, 'notnull'=>1, 'visible'=>1, 'index'=>1, 'arrayofkeyval'=>array('open'=>'StancerDisputeOpen', 'evidence_pending'=>'StancerDisputeEvidencePending', 'evidence_sent'=>'StancerDisputeEvidenceSent', 'evidence_verification'=>'StancerDisputeEvidenceVerification', 'accepted'=>'StancerDisputeAccepted', 'won'=>'StancerDisputeWon', 'lost'=>'StancerDisputeLost', 'out_of_time'=>'StancerDisputeOutOfTime', 'not_contestable'=>'StancerDisputeNotContestable'), 'validate'=>'1', 'default'=>'open'),
		'response' => array('type'=>'varchar(100)', 'label'=>'Response', 'enabled'=>'1', 'position'=>55, 'notnull'=>0, 'visible'=>-1, 'validate'=>'1', 'default'=>null),
		'order_id' => array('type'=>'varchar(100)', 'label'=>'OrderID', 'enabled'=>'1', 'position'=>60, 'notnull'=>0, 'visible'=>1, 'validate'=>'1', 'default'=>null),
		'created' => array('type'=>'datetime', 'label'=>'created', 'enabled'=>'1', 'position'=>70, 'notnull'=>0, 'visible'=>-1, 'validate'=>'1', 'comment'=>"Creation date from Stancer API", 'default'=>null),
		'date_bank' => array('type'=>'timestamp', 'label'=>'date_bank', 'enabled'=>'1', 'position'=>80, 'notnull'=>0, 'visible'=>-1, 'validate'=>'1', 'comment'=>"Bank date", 'default'=>null),
		'live_mode' => array('type'=>'boolean', 'label'=>'live_mode', 'enabled'=>'1', 'position'=>190, 'notnull'=>0, 'visible'=>3, 'validate'=>'1', 'comment'=>"Test or Live mode", 'default'=>0),
		'fk_soc' => array('type'=>'integer:VIEW ON CONSTRUCT FOR DOL VERSION HANDLE'),
		'date_creation' => array('type'=>'datetime', 'label'=>'DateCreation', 'enabled'=>'1', 'position'=>500, 'notnull'=>1, 'visible'=>1, 'default'=>null),
		'tms' => array('type'=>'timestamp', 'label'=>'DateModification', 'enabled'=>'1', 'position'=>501, 'notnull'=>0, 'visible'=>-2, 'default'=>null),
		'fk_user_creat' => array('type'=>'integer:User:user/class/user.class.php', 'label'=>'UserAuthor', 'picto'=>'user', 'enabled'=>'1', 'position'=>510, 'notnull'=>0, 'visible'=>-2, 'foreignkey'=>'user.rowid', 'csslist'=>'tdoverflowmax150', 'default'=>null),
		'fk_user_modif' => array('type'=>'integer:User:user/class/user.class.php', 'label'=>'UserModif', 'picto'=>'user', 'enabled'=>'1', 'position'=>511, 'notnull'=>0, 'visible'=>-2, 'csslist'=>'tdoverflowmax150', 'default'=>null),
		'entity' => array('type'=>'integer', 'label'=>'Entity', 'enabled'=>'1', 'position'=>3000, 'notnull'=>1, 'visible'=>0, 'default'=>1)
	);
	public $rowid;
	public $dispute_id;
	public $payment_id;
	public $amount;
	public $fee;
	public $currency;
	public $dispute_type;
	public $status;
	public $response;
	public $order_id;
	public $created;
	public $date_bank;
	public $live_mode;
	public $fk_soc;
	public $date_creation;
	public $tms;
	public $fk_user_creat;
	public $fk_user_modif;
	public $entity;
	// END MODULEBUILDER PROPERTIES


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
			$this->fields['fk_soc'] = array('type'=>'integer:Societe:societe/class/societe.class.php:1:((status:=:1) AND (entity:IN:__SHARED_ENTITIES__))', 'label'=>'ThirdParty', 'picto'=>'company', 'enabled'=>'$conf->societe->enabled', 'position'=>50, 'notnull'=>-1, 'visible'=>1, 'index'=>1, 'css'=>'maxwidth500 widthcentpercentminusxx', 'csslist'=>'tdoverflowmax150', 'help'=>"OrganizationEventLinkToThirdParty", 'validate'=>'1');
		} else {
			$this->fields['fk_soc'] = array('type'=>'integer:Societe:societe/class/societe.class.php:1:status=1 AND entity IN (__SHARED_ENTITIES__)', 'label'=>'ThirdParty', 'picto'=>'company', 'enabled'=>'$conf->societe->enabled', 'position'=>50, 'notnull'=>-1, 'visible'=>1, 'index'=>1, 'css'=>'maxwidth500 widthcentpercentminusxx', 'csslist'=>'tdoverflowmax150', 'help'=>"OrganizationEventLinkToThirdParty", 'validate'=>'1');
		}

		if (!getDolGlobalInt('MAIN_SHOW_TECHNICAL_ID') && isset($this->fields['rowid']) && !empty($this->fields['ref'])) {
			$this->fields['rowid']['visible'] = 0;
		}
		if (!isModEnabled('multicompany') && isset($this->fields['entity'])) {
			$this->fields['entity']['enabled'] = 0;
		}

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
		return $this->createCommon($user, $notrig);
	}

	/**
	 * Load object in memory from the database
	 *
	 * @param int    $id          Id object
	 * @param string $ref         Ref
	 * @param string $dispute_id  Stancer dispute ID (dspt_xxx)
	 * @return int                <0 if KO, 0 if not found, >0 if OK
	 */
	public function fetch($id, $ref = null, $dispute_id = null)
	{
		$more = "";
		if (!empty($dispute_id)) {
			$more = " AND dispute_id='" . $this->db->escape($dispute_id) . "'";
		}
		$result = $this->fetchCommon($id, $ref, $more);
		return $result;
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
	 * Fill object properties from API response data
	 *
	 * @param  array $apiData  Data from Stancer API
	 * @return int             0 on success
	 */
	public function fillDataFromApi($apiData)
	{
		global $conf;
		$this->entity = $conf->entity;

		$this->dispute_id = isset($apiData['id']) ? $apiData['id'] : '';
		$this->payment_id = isset($apiData['payment']) ? $apiData['payment'] : '';
		$this->amount = isset($apiData['amount']) ? (int) $apiData['amount'] : 0;
		$this->fee = isset($apiData['fee']) ? (int) $apiData['fee'] : 0;
		$this->currency = isset($apiData['currency']) ? $apiData['currency'] : 'eur';
		$this->dispute_type = isset($apiData['type']) ? $apiData['type'] : '';
		$this->status = isset($apiData['status']) ? $apiData['status'] : 'open';
		$this->response = isset($apiData['response']) ? $apiData['response'] : '';
		$this->order_id = isset($apiData['order_id']) ? $apiData['order_id'] : '';
		$this->created = isset($apiData['created']) ? $apiData['created'] : null;
		$this->date_bank = isset($apiData['date_bank']) ? $apiData['date_bank'] : null;
		$this->live_mode = getDolGlobalString('STANCER_IS_PROD', '0');
		if (empty($this->date_creation)) {
			$this->date_creation = dol_now();
		}

		return 0;
	}

	/**
	 *	Return a thumb for kanban views
	 *
	 *	@param      string	    $option                 Where point the link
	 *  @param		array		$arraydata				Array of data
	 *  @return		string								HTML Code for Kanban thumb.
	 */
	public function getKanbanView($option = '', $arraydata = null)
	{
		global $conf, $langs;

		$selected = (empty($arraydata['selected']) ? 0 : $arraydata['selected']);

		$return = '<div class="box-flex-item box-flex-grow-zero">';
		$return .= '<div class="info-box info-box-sm">';
		$return .= '<span class="info-box-icon bg-infobox-action">';
		$return .= img_picto('', $this->picto);
		$return .= '</span>';
		$return .= '<div class="info-box-content">';
		$return .= '<span class="info-box-ref inline-block tdoverflowmax150 valignmiddle">'.$this->dispute_id.'</span>';
		if ($selected >= 0) {
			$return .= '<input id="cb'.$this->id.'" class="flat checkforselect fright" type="checkbox" name="toselect[]" value="'.$this->id.'"'.($selected ? ' checked="checked"' : '').'>';
		}
		if (property_exists($this, 'amount')) {
			$return .= '<br>';
			$return .= '<span class="info-box-label amount">'.price($this->amount / 100, 0, $langs, 1, -1, -1, $conf->currency).'</span>';
		}
		if (method_exists($this, 'getLibStatut')) {
			$return .= '<br><div class="info-box-status margintoponly">'.$this->getLibStatut(3).'</div>';
		}
		$return .= '</div>';
		$return .= '</div>';
		$return .= '</div>';

		return $return;
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
	 *  Return the label of a given status
	 *
	 *  @param	int|string	$status    Status
	 *  @param  int			$mode      0=long label, 1=short label, 2=Picto + short label, 3=Picto, 4=Picto + long label, 5=Short label + Picto, 6=Long label + Picto
	 *  @return string 			       Label of status
	 */
	public function LibStatut($status, $mode = 0)
	{
		// phpcs:enable
		global $langs;

		$labelMap = array(
			'open' => 'StancerDisputeOpen',
			'evidence_pending' => 'StancerDisputeEvidencePending',
			'evidence_sent' => 'StancerDisputeEvidenceSent',
			'evidence_verification' => 'StancerDisputeEvidenceVerification',
			'accepted' => 'StancerDisputeAccepted',
			'won' => 'StancerDisputeWon',
			'lost' => 'StancerDisputeLost',
			'out_of_time' => 'StancerDisputeOutOfTime',
			'not_contestable' => 'StancerDisputeNotContestable',
		);

		$statusTypeMap = array(
			'open' => 'status1',
			'evidence_pending' => 'status1',
			'evidence_sent' => 'status3',
			'evidence_verification' => 'status3',
			'accepted' => 'status4',
			'won' => 'status4',
			'lost' => 'status8',
			'out_of_time' => 'status8',
			'not_contestable' => 'status5',
		);

		$labelKey = isset($labelMap[$status]) ? $labelMap[$status] : 'Unknown';
		$label = $langs->transnoentitiesnoconv($labelKey);
		$statusType = isset($statusTypeMap[$status]) ? $statusTypeMap[$status] : 'status0';

		return dolGetStatus($label, $label, '', $statusType, $mode);
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
				$this->date_creation     = $this->db->jdate($obj->datec);
				$this->date_modification = empty($obj->datem) ? '' : $this->db->jdate($obj->datem);
			}
			$this->db->free($result);
		} else {
			dol_print_error($this->db);
		}
	}

	/**
	 * Initialise object with example values
	 *
	 * @return void
	 */
	public function initAsSpecimen()
	{
		$this->initAsSpecimenCommon();
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
		global $langs;

		if (in_array($key, array('amount'))) {
			// Amounts are stored in cents. $object is typed string by the parent
			// signature, cast it before the division to stay on a numeric operation.
			return price((float) $object / 100);
		}
		if ($key == 'dispute_id') {
			$label = dol_escape_htmltag($object);
			if (getDolGlobalString('STANCER_SHOW_RAW_API_PICTO', '0') == '1') {
				$link = " <a href='#' class='stancer-raw-link' data-stancer-type='dispute' data-stancer-id='" . dol_escape_htmltag($object) . "' title='" . dol_escape_htmltag($langs->trans('ShowRawApiResponse')) . "'>" . img_picto($langs->trans('ShowRawApiResponse'), 'search') . "</a>";
				return $label . $link;
			}
			return $label;
		}

		return parent::showOutputField($val, $key, $object, $moreparam, $keysuffix, $keyprefix, $showsize);
	}
}
