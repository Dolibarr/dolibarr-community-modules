<?php
/* Copyright (C) 2025       Laurent Destailleur         <eldy@users.sourceforge.net>
 * Copyright (C) 2025       Mohamed DAOUD               <mdaoud@dolicloud.com>
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
 * \file    pdpconnectfr/class/pdpconnectfr.php
 * \ingroup pdpconnectfr
 * \brief   Base class for all functions to manage PDPCONNECTFR Module.
 */

/**
 * Validate mysoc configuration
 *
 * @return array{res:int, message:string}       Returns array with 'res' (1 on success, -1 on failure) and info 'message'
 */
function validateMyCompanyConfiguration()
{
    global $langs, $mysoc;

    $baseErrors = [];

    if (empty($mysoc->tva_intra)) {
        $baseErrors[] = $langs->trans("FxCheckErrorVATnumber");
    }
    if (empty($mysoc->address)) {
        $baseErrors[] = $langs->trans("FxCheckErrorAddress");
    }
    if (empty($mysoc->zip)) {
        $baseErrors[] = $langs->trans("FxCheckErrorZIP");
    }
    if (empty($mysoc->town)) {
        $baseErrors[] = $langs->trans("FxCheckErrorTown");
    }
    if (empty($mysoc->country_code)) {
        $baseErrors[] = $langs->trans("FxCheckErrorCountry");
    }

    if (!empty($baseErrors)) {
        return ['res' => -1, 'message' => implode('<br>', $baseErrors)];
    }

    return ['res' => 1, 'message' => ''];
}

/**
 * Validate thirdparty configuration
 *
 * @param Societe $thirdparty   Thirdparty object
 * @return array{res:int, message:string} Returns array with 'res' (1 on success, -1 on failure) and info 'message'
 */
function validatethirdpartyConfiguration($thirdparty)
{
    global $langs, $mysoc;

    $baseErrors = [];

    if (empty($thirdparty->name)) {
        $baseErrors[] = $langs->trans("FxCheckErrorCustomerName");
    }
    if ($mysoc->country_code != 'FR' && empty($thirdparty->idprof1)) {
        $baseErrors[] = $langs->trans("FxCheckErrorCustomerIDPROF1");
    }
    if (empty($thirdparty->idprof2)) {
        $baseErrors[] = $langs->trans("FxCheckErrorCustomerIDPROF2");
    }
    if (empty($thirdparty->address)) {
        $baseErrors[] = $langs->trans("FxCheckErrorCustomerAddress");
    }
    if (empty($thirdparty->zip)) {
        $baseErrors[] = $langs->trans("FxCheckErrorCustomerZIP");
    }
    if (empty($thirdparty->town)) {
        $baseErrors[] = $langs->trans("FxCheckErrorCustomerTown");
    }
    if (empty($thirdparty->country_code)) {
        $baseErrors[] = $langs->trans("FxCheckErrorCustomerCountry");
    }
    if (empty($thirdparty->tva_intra)) {
        //$baseErrors[] = $langs->trans("FxCheckErrorCustomerVAT");
    }
    if (empty($thirdparty->email)) {
        $baseErrors[] = $langs->trans("FxCheckErrorCustomerEmail");
    }

    if (!empty($baseErrors)) {
        return ['res' => -1, 'message' => implode('<br>', $baseErrors)];
    }

    return ['res' => 1, 'message' => ''];
}

/**
 * Check required informations for PDP/PA invoicing
 *
 * @param Societe $soc   Thirdparty object
 *
 * @return array{res:int, message:string} Returns array with 'res' (1 on success, -1 on failure) and info 'message'
 */
function checkRequiredinformations($soc) {

    $baseErrors = [];
    $mysocConfigCheck = validateMyCompanyConfiguration();
    if ($mysocConfigCheck['res'] < 0) {
        $baseErrors[] = $mysocConfigCheck['message'];
    }

    $socConfigCheck = validatethirdpartyConfiguration($soc);
    if ($socConfigCheck['res'] < 0) {
        $baseErrors[] = $socConfigCheck['message'];
    }

    if (!empty($baseErrors)) {
        return ['res' => -1, 'message' => implode('<br>', $baseErrors)];
    }
    return ['res' => 1, 'message' => ''];
}
