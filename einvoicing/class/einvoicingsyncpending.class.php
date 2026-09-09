<?php
/* Copyright (C) 2026		Jose Martinez					<jose.martinez@pichinov.com>
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
 * \file        class/einvoicingsyncpending.class.php
 * \ingroup     einvoicing
 * \brief       CRUD class for the synchronization pending queue.
 *
 * A flow that cannot be synchronized because it needs a manual action (missing product, missing
 * thirdparty, ...) used to abort the whole synchronization run. It is now recorded in this queue so
 * the run carries on with the other flows, and the queued flow is retried on demand once the manual
 * action is done - so it is not lost when it drifts out of the rolling synchronization window.
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';

/**
 * Class for EInvoicingSyncPending
 */
class EInvoicingSyncPending extends CommonObject
{
	/**
	 * @var string ID of module.
	 */
	public $module = 'einvoicing';

	/**
	 * @var string ID to identify managed object.
	 */
	public $element = 'einvoicingsyncpending';

	/**
	 * @var string Name of table without prefix where object is stored.
	 */
	public $table_element = 'einvoicing_sync_pending';

	/**
	 * @var string Permission is checked with hasRight('einvoicing', 'read'/'write').
	 */
	public $element_for_permission = 'einvoicing';

	/**
	 * @var string String with name of icon for object.
	 */
	public $picto = 'fa-hourglass-half';

	/**
	 * @var int<0,1> Does this object support multicompany module ? 1=Test with field entity.
	 */
	public $ismultientitymanaged = 1;

	const STATUS_PENDING  = 0;
	const STATUS_RESOLVED = 1;
	const STATUS_IGNORED  = 2;

	/**
	 * @var array<string,array<string,mixed>> Fields definition.
	 */
	public $fields = array(
		"rowid" => array("type" => "integer", "label" => "ID", "enabled" => "1", 'position' => 1, 'notnull' => 1, "visible" => "0", "noteditable" => "1", "index" => "1"),
		"provider" => array("type" => "varchar(50)", "label" => "AccessPoint", "langfile" => "einvoicing@einvoicing", "enabled" => "1", 'position' => 5, 'notnull' => 1, "visible" => "-1"),
		"flow_id" => array("type" => "varchar(255)", "label" => "flow_id", "enabled" => "1", 'position' => 10, 'notnull' => 1, "visible" => "1", "csslist" => "tdoverflowmax150"),
		"flow_direction" => array("type" => "varchar(10)", "label" => "flow_direction", "enabled" => "1", 'position' => 20, 'notnull' => 0, "visible" => "1", 'csslist' => 'center'),
		"flow_type" => array("type" => "varchar(64)", "label" => "flow_type", "enabled" => "1", 'position' => 30, 'notnull' => 0, "visible" => "1"),
		"tracking_idref" => array("type" => "varchar(255)", "label" => "RefObject", "langfile" => "einvoicing@einvoicing", "enabled" => "1", 'position' => 40, 'notnull' => 0, "visible" => "1", "csslist" => "nowraponall"),
		"fk_element_type" => array("type" => "varchar(100)", "label" => "fk_element_type", "enabled" => "1", 'position' => 50, 'notnull' => 0, "visible" => "-1"),
		"fk_element_id" => array("type" => "integer", "label" => "fk_element_id", "enabled" => "1", 'position' => 55, 'notnull' => 0, "visible" => "-1"),
		"reason_code" => array("type" => "varchar(64)", "label" => "ReasonCode", "langfile" => "einvoicing@einvoicing", "enabled" => "1", 'position' => 60, 'notnull' => 0, "visible" => "1"),
		"reason_message" => array("type" => "text", "label" => "ReasonMessage", "langfile" => "einvoicing@einvoicing", "enabled" => "1", 'position' => 70, 'notnull' => 0, "visible" => "-1", "csslist" => "tdoverflowmax300"),
		"action_data" => array("type" => "text", "label" => "action_data", "enabled" => "1", 'position' => 80, 'notnull' => 0, "visible" => "0"),
		"action_html" => array("type" => "text", "label" => "action_html", "enabled" => "1", 'position' => 85, 'notnull' => 0, "visible" => "0"),
		"match_data" => array("type" => "text", "label" => "match_data", "enabled" => "1", 'position' => 86, 'notnull' => 0, "visible" => "0"),
		"flow_updatedat" => array("type" => "datetime", "label" => "updatedAt", "enabled" => "1", 'position' => 90, 'notnull' => 0, "visible" => "1"),
		"nb_attempts" => array("type" => "integer", "label" => "Attempts", "langfile" => "einvoicing@einvoicing", "enabled" => "1", 'position' => 100, 'notnull' => 0, "visible" => "1", 'csslist' => 'center'),
		"date_lastattempt" => array("type" => "datetime", "label" => "DateLastAttempt", "langfile" => "einvoicing@einvoicing", "enabled" => "1", 'position' => 110, 'notnull' => 0, "visible" => "-1"),
		"entity" => array("type" => "integer", "label" => "entity", "enabled" => "1", 'position' => 170, 'notnull' => 0, "visible" => "0"),
		"date_creation" => array("type" => "datetime", "label" => "DateCreation", "enabled" => "1", 'position' => 500, 'notnull' => 1, "visible" => "-1"),
		"tms" => array("type" => "timestamp", "label" => "DateModification", "enabled" => "1", 'position' => 501, 'notnull' => 0, "visible" => "-2"),
		"fk_user_creat" => array("type" => "integer:User:user/class/user.class.php", "label" => "UserAuthor", "picto" => "user", "enabled" => "1", 'position' => 510, 'notnull' => 1, "visible" => "-2"),
		"fk_user_modif" => array("type" => "integer:User:user/class/user.class.php", "label" => "UserModif", "picto" => "user", "enabled" => "1", 'position' => 511, 'notnull' => -1, "visible" => "-2"),
		"status" => array("type" => "integer", "label" => "Status", "enabled" => "1", 'position' => 2000, 'notnull' => 1, "visible" => "1", "index" => "1", "arrayofkeyval" => array("0" => "Pending", "1" => "Resolved", "2" => "Ignored")),
	);

	public $rowid;
	public $entity;
	public $provider;
	public $flow_id;
	public $flow_direction;
	public $flow_type;
	public $tracking_idref;
	public $fk_element_type;
	public $fk_element_id;
	public $reason_code;
	public $reason_message;
	public $action_data;
	public $action_html;
	public $match_data;
	public $flow_updatedat;
	public $nb_attempts;
	public $date_lastattempt;
	public $status;
	public $date_creation;
	public $tms;
	public $fk_user_creat;
	public $fk_user_modif;

	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		global $conf;

		$this->db = $db;

		if (empty($conf->global->MAIN_SHOW_TECHNICAL_ID) && isset($this->fields['rowid']) && !empty($this->fields['status'])) {
			$this->fields['rowid']['visible'] = 0;
		}
	}

	/**
	 * Create object into database
	 *
	 * @param  User $user      User that creates
	 * @param  int  $notrigger 0=launch triggers after, 1=disable triggers
	 * @return int             Return integer <0 if KO, Id of created object if OK
	 */
	public function create(User $user, $notrigger = 0)
	{
		return $this->createCommon($user, $notrigger);
	}

	/**
	 * Load object in memory from the database
	 *
	 * @param  int    $id  Id object
	 * @param  string $ref Ref (unused, kept for signature compatibility)
	 * @return int         Return integer <0 if KO, 0 if not found, >0 if OK
	 */
	public function fetch($id, $ref = null)
	{
		return $this->fetchCommon($id, $ref);
	}

	/**
	 * Update object into database
	 *
	 * @param  User $user      User that modifies
	 * @param  int  $notrigger 0=launch triggers after, 1=disable triggers
	 * @return int             Return integer <0 if KO, >0 if OK
	 */
	public function update(User $user, $notrigger = 0)
	{
		return $this->updateCommon($user, $notrigger);
	}

	/**
	 * Delete object in database
	 *
	 * @param  User $user      User that deletes
	 * @param  int  $notrigger 0=launch triggers after, 1=disable triggers
	 * @return int             Return integer <0 if KO, >0 if OK
	 */
	public function delete(User $user, $notrigger = 0)
	{
		return $this->deleteCommon($user, $notrigger);
	}

	/**
	 * Find a pending row by its flow id (any status), for the current entity.
	 *
	 * @param  string $flowId   PDP flow id
	 * @param  string $provider Provider short key
	 * @return int              rowid if found, 0 if none, <0 on SQL error
	 */
	public function fetchByFlowId($flowId, $provider)
	{
		global $conf;

		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX.$this->table_element;
		$sql .= " WHERE entity = ".((int) $conf->entity);
		$sql .= " AND provider = '".$this->db->escape($provider)."'";
		$sql .= " AND flow_id = '".$this->db->escape($flowId)."'";
		$sql .= " LIMIT 1";

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		if (!$this->db->num_rows($resql)) {
			return 0;
		}
		$obj = $this->db->fetch_object($resql);
		return $this->fetch((int) $obj->rowid) > 0 ? (int) $obj->rowid : 0;
	}

	/**
	 * Record (insert or update) a flow that could not be synchronized because it needs a manual action.
	 *
	 * The row is keyed by (entity, provider, flow_id): a flow already queued is refreshed (reason,
	 * message, attempt counter) instead of being duplicated. A previously resolved/ignored flow that
	 * fails again is reopened as pending.
	 *
	 * @param  array  $flow     Flow as returned by the AP search (flowId, flowDirection, flowType, trackingId, updatedAt)
	 * @param  string $provider Provider short key ('superpdp', ...)
	 * @param  string $reason   Business reason code ('PRODUCT_NOT_FOUND', ...)
	 * @param  string $message  Human readable message of the failed attempt
	 * @param  array  $data       Action data (supplier, supplierref, label, socid, ...) to help the manual action
	 * @param  User   $user       User running the synchronization
	 * @param  string $actionHtml Ready-to-display block of manual-action buttons built by the protocol (create / associate an existing product / set default), same as shown on the synchronization page
	 * @param  array  $matchData  Issuer identifiers of the flow (name, vatnumber, idprof1=SIREN, idprof2=SIRET, ...) used to link the flow to an existing thirdparty
	 * @return int                rowid of the queued row if OK, <0 if KO
	 */
	public function queueFromFlow($flow, $provider, $reason, $message, $data, User $user, $actionHtml = '', $matchData = array())
	{
		$flowId = (string) ($flow['flowId'] ?? '');
		if ($flowId === '') {
			$this->error = 'Cannot queue a flow without a flowId';
			return -1;
		}

		$updatedatSql = null;
		if (!empty($flow['updatedAt'])) {
			// The platform sends ISO 8601 with fractional seconds ('2026-09-01T10:00:00.626638Z'),
			// which strtotime() does not parse: drop the fraction before converting.
			$ts = strtotime(preg_replace('/\.\d+/', '', (string) $flow['updatedAt']));
			if ($ts !== false && $ts > 0) {
				$updatedatSql = $ts;
			}
		}

		$existing = $this->fetchByFlowId($flowId, $provider);
		if ($existing < 0) {
			return -1;
		}

		$this->provider        = $provider;
		$this->flow_id         = $flowId;
		$this->flow_direction  = (string) ($flow['flowDirection'] ?? '');
		$this->flow_type       = (string) ($flow['flowType'] ?? '');
		$this->tracking_idref  = (string) ($flow['trackingId'] ?? '');
		$this->reason_code     = (string) $reason;
		$this->reason_message  = (string) $message;
		$this->action_data     = empty($data) ? null : json_encode($data);
		$this->action_html     = ($actionHtml !== '' && $actionHtml !== null) ? $actionHtml : null;
		$this->match_data      = empty($matchData) ? null : json_encode($matchData);
		$this->flow_updatedat  = $updatedatSql;
		$this->date_lastattempt = dol_now();

		if ($existing > 0) {
			$this->nb_attempts = (int) $this->nb_attempts + 1;
			$this->status = self::STATUS_PENDING;	// reopen if it had been resolved/ignored
			return $this->update($user) > 0 ? (int) $this->id : -1;
		}

		$this->nb_attempts   = 1;
		$this->status        = self::STATUS_PENDING;
		$this->date_creation = dol_now();
		return $this->create($user);
	}

	/**
	 * Mark the queued row of a flow as resolved (the flow finally synchronized).
	 *
	 * @param  string $flowId   PDP flow id
	 * @param  string $provider Provider short key
	 * @param  User   $user     User running the synchronization
	 * @return int              1 if a row was resolved, 0 if none, <0 on error
	 */
	public function resolveByFlowId($flowId, $provider, User $user, $elementType = '', $elementId = 0)
	{
		$existing = $this->fetchByFlowId($flowId, $provider);
		if ($existing <= 0) {
			return $existing;	// 0 = nothing queued (normal), <0 = error
		}
		if ((int) $this->status == self::STATUS_RESOLVED) {
			return 0;
		}
		$this->status = self::STATUS_RESOLVED;
		// Keep a link to the element the flow finally created (traceability flow -> invoice), when known.
		if ($elementType !== '') {
			$this->fk_element_type = $elementType;
		}
		if ((int) $elementId > 0) {
			$this->fk_element_id = (int) $elementId;
		}
		return $this->update($user) > 0 ? 1 : -1;
	}
}
