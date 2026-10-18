<?php
/* Copyright (C) 2023 Eric Seigne <eric.seigne@cap-rel.fr>
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
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 * or see http://www.gnu.org/
 */

/**
 *	\file			htdocs/core/modules/substitutions/functions_stancer.lib.php
 *	\brief			A set of functions for Dolibarr
 *					This file contains functions for plugin stancer.
 */

dol_include_once('/stancer/lib/stancer.lib.php');

/**
 * 		Function called to complete substitution array (before generating on ODT, or a personalized email)
 * 		functions xxx_completesubstitutionarray are called by make_substitutions() if file
 * 		is inside directory htdocs/core/substitutions
 *
 *		@param	array		$substitutionarray	Array with substitution key=>val
 *		@param	Translate	$outlangs			Output langs
 *		@param	Object		$object				Object to use to get values
 * 		@return	void							The entry parameter $substitutionarray is modified
 */
function stancer_completesubstitutionarray(&$substitutionarray, $outlangs, $object)
{
	global $conf;

	$substitutionarray['__STANCER_SEPA_DELAIS__'] = getDolGlobalString('STANCER_DELAY_SEPA');

	if (is_object($object) && ((!empty($object->id) && $object->id > 0) || !empty($object->specimen))) {	// We do not add substitution entries if object is not instantiated (->id not > 0)
		// The object received from make_substitutions() is the reference, never the
		// global $object of the page, and its thirdparty may not be loaded yet.
		$thirdparty = null;
		if ($object instanceof Societe) {
			$thirdparty = $object;
		} else {
			if (empty($object->thirdparty) && !empty($object->socid) && method_exists($object, 'fetch_thirdparty')) {
				$object->fetch_thirdparty();
			}
			if (!empty($object->thirdparty) && is_object($object->thirdparty)) {
				$thirdparty = $object->thirdparty;
			}
		}
		if (!empty($thirdparty->id)) {
			$substitutionarray['__STANCER_SEPA_RUM__'] = stancerGetSepaRum((int) $thirdparty->id);
			$substitutionarray['__STANCER_SEPA_URL__'] = stancerShowOnlineIBANLinkForEntity($thirdparty->id, $thirdparty->name);
		} else {
			dol_syslog("stancer substitutions: no thirdparty for " . get_class($object) . " " . ($object->id ?? ''), LOG_DEBUG);
			$substitutionarray['__STANCER_SEPA_RUM__'] = '';
			$substitutionarray['__STANCER_SEPA_URL__'] = '';
		}

		// Online payment URL for the current object (invoice or order)
		require_once DOL_DOCUMENT_ROOT . '/core/lib/payments.lib.php';
		$typeforonlinepayment = 'free';
		if (property_exists($object, 'element')) {
			if ($object->element == 'facture') {
				$typeforonlinepayment = 'invoice';
			} elseif ($object->element == 'commande') {
				$typeforonlinepayment = 'order';
			}
		}
		if ($typeforonlinepayment != 'free' && !empty($object->ref)) {
			$substitutionarray['__STANCER_PAYMENT_URL__'] = getOnlinePaymentUrl(0, $typeforonlinepayment, $object->ref);
		}
	}
}
