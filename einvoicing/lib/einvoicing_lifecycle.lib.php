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
 * XP Z12-012 status code refines it: the deposit acknowledgement belongs to the seller, the network
 * statuses to the platform, the rest to the buyer. It uses EInvoicing::STATUS_* so the classification
 * cannot drift from the module's status map.
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

	// "Deposited" (200) acknowledges the seller's own submission: it is the seller's step of the
	// lifecycle, even though the platform is the one reporting it back to us.
	if ($status === EInvoicing::STATUS_DEPOSITED) {
		return 'fournisseur';
	}

	$networkStatuses = array(
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

/**
 * Translation key of the note shown under the seller card when no lifecycle event has been recorded
 * for the seller yet and the card falls back to the local status of llx_einvoicing_extlinks.
 *
 * That local status is a Dolibarr-side code (EInvoicing::STATUS_UNKNOWN .. STATUS_ERROR) before the
 * first exchange with the platform, and the last XP Z12-012 code received once the platform answered.
 * The note must say which of the two it is: "not yet transmitted" is wrong once the invoice was
 * deposited and the platform acknowledged it.
 *
 * @param int $code Status code from EInvoicing::fetchLastknownInvoiceStatus()['code']
 * @return string Translation key, or '' when no note applies
 */
function einvoicingLifecycleLocalStatusNote($code)
{
	$code = (int) $code;

	if (in_array($code, array(EInvoicing::STATUS_AWAITING_VALIDATION, EInvoicing::STATUS_AWAITING_ACK), true)) {
		return 'EInvLocalStatusAwaitingPlatform';
	}
	if ($code < EInvoicing::STATUS_DEPOSITED && $code !== EInvoicing::STATUS_ERROR) {
		return 'EInvLocalStatusNotYetTransmitted';
	}

	return '';
}
