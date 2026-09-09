<?php
/* Copyright (C) 2026	Charles Peltier

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
 * \file    lib/einvoicing_lifecycle.lib.php
 * \ingroup einvoicing
 * \brief   Actor classification and labelling for the e-invoicing lifecycle of an element (invoice).
 *
 * Reads the multi-provider llx_einvoicing_lifecycle_msg table, which stores a numeric XP Z12-012 status
 * code (EInvoicing::STATUS_*) plus a direction ('in'/'out'), and turns it into the three actors a
 * customer invoice tracking view cares about: 'fournisseur' (Dolibarr/seller side, us), 'pdp' (network /
 * Access Point layer) and 'client' (buyer side).
 */

/**
 * Swimlane (actor) a lifecycle event belongs to: 'fournisseur' (Dolibarr/seller side, us), 'pdp'
 * (network / Access Point layer) or 'client' (buyer side).
 *
 * Direction gives the primary signal (out = emitted by us, in = received back). For an 'in' event, the
 * XP Z12-012 status code refines it between the network acknowledging our submission and the buyer's own
 * processing, using EInvoicing::STATUS_* so the classification cannot drift from the module's status map.
 *
 * @param int    $status    Lifecycle status code (EInvoicing::STATUS_*)
 * @param string $direction 'in' or 'out'
 * @return string 'fournisseur'|'pdp'|'client'
 */
function einvoicingLifecycleFlux($status, $direction)
{
	$status = (int) $status;

	if (strtolower((string) $direction) == 'out') {
		return 'fournisseur';
	}

	$networkStatuses = array(
		EInvoicing::STATUS_DEPOSITED,
		EInvoicing::STATUS_ISSUED,
		EInvoicing::STATUS_RECEIVED,
		EInvoicing::STATUS_AVAILABLE,
		EInvoicing::STATUS_REJECTED,
	);
	if (in_array($status, $networkStatuses, true)) {
		return 'pdp';
	}

	return 'client';
}

/**
 * Human label for a lifecycle event: the provider's own message when it carries more context than the
 * bare status code, otherwise the module's canonical status label.
 *
 * @param EInvoicing $einvoicing EInvoicing instance (source of canonical status labels)
 * @param int        $status     Lifecycle status code
 * @param string     $override   Provider message (lc_status_message), if any
 * @return string
 */
function einvoicingLifecycleLabel($einvoicing, $status, $override = '')
{
	$override = trim((string) $override);
	if ($override !== '' && $override !== (string) $status) {
		return $override;
	}
	return $einvoicing->getStatusLabel($status);
}
