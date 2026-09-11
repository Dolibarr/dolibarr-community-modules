<?php
/* Copyright (C) 2026		Pierre Grasswill			<da.grumpf@gmail.com>
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
 * \file    einvoicing/class/utils/PostponedFlow.class.php
 * \ingroup einvoicing
 * \brief   Backlog of the incoming flows the access point still holds.
 */

/**
 * A postponed flow stores nothing else - that is what makes it retriable - so nothing else records
 * that it was ever seen. This class keeps that record: what is waiting, why, since when, and how
 * many synchronizations have already met it, so the synchronization panel can show a backlog
 * instead of a count that dies with the request.
 */
class PostponedFlow
{
	/**
	 * Record that a flow was postponed, or that an already known one was met again.
	 * The unique key on (flow_id, entity) is what turns this into an upsert: the first attempt sets
	 * date_creation and never moves it, every later one bumps the counter and the last reason.
	 *
	 * @param  DoliDB					$db        Database handler
	 * @param  string					$flowId    Flow identifier at the access point
	 * @param  string					$provider  Access point the flow comes from
	 * @param  array<string,mixed>		$res       Import result that carried 'postponeflow'
	 * @param  string					$callId    Synchronization call that met it, for the support export
	 * @return int								   1 if recorded, -1 on database failure
	 */
	public static function record($db, $flowId, $provider, $res, $callId = '')
	{
		global $user, $conf;

		$flowId = trim((string) $flowId);
		if ($flowId === '') {
			return 1;	// Nothing to key the backlog on: the caller still reports the flow in its run
		}

		$actiondata = $res['actiondata'] ?? array();

		// Every value goes through $db->escape() or an (int) cast where it is written: the SQL guard
		// of phan stops at a safe call, not at a variable that was assigned from one.
		$refValue = (string) ($actiondata['linkedref'] ?? ($actiondata['supplierref'] ?? ''));
		// One timestamp for the whole write, so the two dates of a first sighting are the same second.
		$nowts = dol_now();

		// No upsert here: "ON DUPLICATE KEY" is MySQL only and "ON CONFLICT" is PostgreSQL only,
		// while the module runs on both. One synchronization runs at a time, so read then write.
		$sql = "SELECT rowid FROM " . $db->prefix() . "einvoicing_postponed";
		$sql .= " WHERE flow_id = '" . $db->escape($flowId) . "'";
		$sql .= " AND entity = " . ((int) $conf->entity);
		$resql = $db->query($sql);
		if (!$resql) {
			dol_syslog(__METHOD__ . ' ' . $db->lasterror(), LOG_ERR, 0, "_einvoicing");
			return -1;
		}
		$existing = $db->fetch_object($resql);
		$db->free($resql);

		if ($existing) {
			// date_creation never moves: it is the "waiting since" the panel shows.
			$sql = "UPDATE " . $db->prefix() . "einvoicing_postponed SET";
			$sql .= " reason_code = '" . $db->escape((string) ($res['actioncode'] ?? '')) . "'";
			$sql .= ", reason_message = '" . $db->escape((string) ($res['message'] ?? '')) . "'";
			$sql .= ", business_message = '" . $db->escape((string) ($res['businessmessage'] ?? '')) . "'";
			$sql .= ", action_html = '" . $db->escape((string) ($res['action'] ?? '')) . "'";
			$sql .= ", action_data = '" . $db->escape((string) json_encode($actiondata)) . "'";
			$sql .= ", fk_soc = " . (empty($actiondata['socid']) ? 'NULL' : ((int) $actiondata['socid']));
			$sql .= ", document_ref = '" . $db->escape($refValue) . "'";
			$sql .= ", call_id = '" . $db->escape((string) $callId) . "'";
			$sql .= ", nb_attempts = nb_attempts + 1";
			$sql .= ", date_last_attempt = '" . $db->idate($nowts) . "'";
			$sql .= ", fk_user_modif = " . ((int) (empty($user->id) ? 0 : $user->id));
			$sql .= " WHERE rowid = " . ((int) $existing->rowid);
		} else {
			$sql = "INSERT INTO " . $db->prefix() . "einvoicing_postponed";
			$sql .= " (flow_id, provider, call_id, entity, nb_attempts, date_creation, date_last_attempt, fk_user_creat,";
			$sql .= " reason_code, reason_message, business_message, action_html, action_data, fk_soc, document_ref)";
			$sql .= " VALUES ('" . $db->escape($flowId) . "'";
			$sql .= ", '" . $db->escape($provider) . "'";
			$sql .= ", '" . $db->escape((string) $callId) . "'";
			$sql .= ", " . ((int) $conf->entity);
			$sql .= ", 1";
			$sql .= ", '" . $db->idate($nowts) . "'";
			$sql .= ", '" . $db->idate($nowts) . "'";
			$sql .= ", " . ((int) (empty($user->id) ? 0 : $user->id));
			$sql .= ", '" . $db->escape((string) ($res['actioncode'] ?? '')) . "'";
			$sql .= ", '" . $db->escape((string) ($res['message'] ?? '')) . "'";
			$sql .= ", '" . $db->escape((string) ($res['businessmessage'] ?? '')) . "'";
			$sql .= ", '" . $db->escape((string) ($res['action'] ?? '')) . "'";
			$sql .= ", '" . $db->escape((string) json_encode($actiondata)) . "'";
			$sql .= ", " . (empty($actiondata['socid']) ? 'NULL' : ((int) $actiondata['socid']));
			$sql .= ", '" . $db->escape($refValue) . "')";
		}

		if (!$db->query($sql)) {
			dol_syslog(__METHOD__ . ' ' . $db->lasterror(), LOG_ERR, 0, "_einvoicing");
			return -1;
		}

		return 1;
	}

	/**
	 * Drop a flow from the backlog, because it was imported or is already known to Dolibarr.
	 * Called on every outcome that is not a postponement, so a backlog line never outlives its cause.
	 *
	 * @param  DoliDB	$db      Database handler
	 * @param  string	$flowId  Flow identifier at the access point
	 * @return int				 1 if cleared, -1 on database failure
	 */
	public static function clear($db, $flowId)
	{
		global $conf;

		$flowId = trim((string) $flowId);
		if ($flowId === '') {
			return 1;
		}

		$sql = "DELETE FROM " . $db->prefix() . "einvoicing_postponed";
		$sql .= " WHERE flow_id = '" . $db->escape($flowId) . "'";
		$sql .= " AND entity = " . ((int) $conf->entity);

		if (!$db->query($sql)) {
			dol_syslog(__METHOD__ . ' ' . $db->lasterror(), LOG_ERR, 0, "_einvoicing");
			return -1;
		}

		return 1;
	}

	/**
	 * The backlog of the current entity, oldest first: what has been waiting longest is what needs
	 * a decision most.
	 *
	 * @param  DoliDB	$db     Database handler
	 * @param  int		$limit  Maximum rows, 0 for all of them
	 * @return array<int,stdClass>|int   Rows, or -1 on database failure
	 */
	public static function fetchBacklog($db, $limit = 0)
	{
		global $conf;

		$sql = "SELECT rowid, flow_id, provider, call_id, reason_code, reason_message, business_message,";
		$sql .= " action_html, action_data, fk_soc, document_ref, nb_attempts, date_creation, date_last_attempt";
		$sql .= " FROM " . $db->prefix() . "einvoicing_postponed";
		$sql .= " WHERE entity IN (" . $db->sanitize(getEntity('einvoicing_document')) . ")";
		$sql .= " ORDER BY date_creation ASC, rowid ASC";
		if ($limit > 0) {
			$sql .= " LIMIT " . ((int) $limit);
		}

		$resql = $db->query($sql);
		if (!$resql) {
			dol_syslog(__METHOD__ . ' ' . $db->lasterror(), LOG_ERR, 0, "_einvoicing");
			return -1;
		}

		$rows = array();
		while ($obj = $db->fetch_object($resql)) {
			$rows[] = $obj;
		}
		$db->free($resql);

		return $rows;
	}

	/**
	 * How many flows are waiting, for the badge the panel shows before anything is unfolded.
	 *
	 * @param  DoliDB	$db  Database handler
	 * @return int			 Number of flows waiting, 0 when the table is empty or unreadable
	 */
	public static function countBacklog($db)
	{
		global $conf;

		$sql = "SELECT COUNT(rowid) as nb FROM " . $db->prefix() . "einvoicing_postponed";
		$sql .= " WHERE entity IN (" . $db->sanitize(getEntity('einvoicing_document')) . ")";

		$resql = $db->query($sql);
		if (!$resql) {
			dol_syslog(__METHOD__ . ' ' . $db->lasterror(), LOG_ERR, 0, "_einvoicing");
			return 0;
		}
		$obj = $db->fetch_object($resql);
		$db->free($resql);

		return empty($obj) ? 0 : (int) $obj->nb;
	}
}
