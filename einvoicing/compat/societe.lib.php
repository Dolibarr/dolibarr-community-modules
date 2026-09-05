<?php
/* Copyright (C) 2004-2026	Laurent Destailleur		<eldy@users.sourceforge.net>
 * Copyright (C) 2026		Pierre Grasswill		<da.grumpf@gmail.com>
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
 * or see https://www.gnu.org/
 */

/**
 *  \file		einvoicing/compat/societe.lib.php
 *  \ingroup	einvoicing
 *  \brief		Thirdparty helper of societe/class/societe.class.php this module uses and that Dolibarr
 *  			does not ship before 24
 */

// @phan-file-suppress PhanRedefineFunction

if (!function_exists('calculateVATNumberFromProperties')) {
	/**
	 *  Calculate VAT intracommunity number for a thirdparty if missing, from the professional ID.
	 *
	 *  Copy of Societe::calculateVATNumberFromProperties(), added to
	 *  htdocs/societe/class/societe.class.php in Dolibarr 24, kept identical so a VAT number built on
	 *  18 to 23 is the number the core would build. Measured: the method is absent from 18.0.10,
	 *  19.0.4, 20.0.4, 21.0.4, 22.0.5 and 23.0.4, and present in 24.0.1.
	 *
	 *  The core exposes it as a *method* of Societe, and a method cannot be added to a core class from
	 *  outside without patching the core, which a module must never do. So the backport takes the only
	 *  shape a shim can take here: a free function carrying the name and the signature of the core
	 *  symbol, guarded like every other shim of this directory. The module never reaches it when the
	 *  real method is there - EInvoicing::thirdpartyCalcVATIntra() tests the method first, and calls
	 *  this only as a fallback.
	 *
	 *  Note that the core method takes the thirdparty as an argument rather than working on $this,
	 *  which is what makes it transposable to a function without changing what it does.
	 *
	 *  @param	mixed	$thirdparty		A thirdparty object
	 *  @return	string					A VAT number, '' if none can be built
	 *  @since	Dolibarr V24
	 */
	function calculateVATNumberFromProperties($thirdparty)
	{
		if ($thirdparty->country_code == 'FR' && empty($thirdparty->tva_intra) && !empty($thirdparty->tva_assuj)) {
			// The core requires DOL_DOCUMENT_ROOT/core/lib/profid.lib.php here. That file only exists
			// since Dolibarr 20, so on 18 and 19 the backported checks of compat/profid.lib.php, which
			// sits next to this file, are the ones to load.
			if (!function_exists('isValidSiren')) {
				require_once __DIR__ . '/profid.lib.php';
			}

			$siren = preg_replace('/\s+/', '', (string) $thirdparty->idprof1);
			$siret = preg_replace('/\s+/', '', (string) $thirdparty->idprof2);
			if (!isValidSiren($siren)) {
				if (!isValidSiret($siret)) {
					return '';
				}

				$siren = substr($siret, 0, 9);
			}
			if (!empty($siren)) {
				// [FR + key code + SIREN number ]
				// Key VAT = [12 + 3 * (SIREN modulo 97)] modulo 97
				$cle = (12 + 3 * (((int) $siren) % 97)) % 97;
				$tva_intra = 'FR' . str_pad((string) $cle, 2, '0', STR_PAD_LEFT) . $siren;
			}
		}

		return $tva_intra ?? '';
	}
}
