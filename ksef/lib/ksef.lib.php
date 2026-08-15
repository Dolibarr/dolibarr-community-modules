<?php
/* Copyright (C) 2025-2026 InPoint Automation Sp z o.o.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    lib/ksef.lib.php
 * \ingroup ksef
 * \brief   common functions
 */

define('KSEF_PORTAL_URL_PRODUCTION', 'https://ksef.mf.gov.pl');
define('KSEF_PORTAL_URL_TEST', 'https://ksef-test.mf.gov.pl');
define('KSEF_PORTAL_URL_DEMO', 'https://ksef-demo.mf.gov.pl');

define('KSEF_QR_URL_PRODUCTION', 'https://qr.ksef.mf.gov.pl');
define('KSEF_QR_URL_TEST', 'https://qr-test.ksef.mf.gov.pl');
define('KSEF_QR_URL_DEMO', 'https://qr-demo.ksef.mf.gov.pl');

define('KSEF_FA3_NAMESPACE', 'http://crd.gov.pl/wzor/2025/06/25/13775/');
define('KSEF_FA3_SCHEMA_VERSION', '1-0E');

define('KSEF_EXCESS_RECEIVED_DESCRIPTION', '(EXCESS RECEIVED)');

/**
 * @brief Returns true if the module has been updated
 * @param string $currentVersion Version declared by the running module code
 * @param string $lastInitVersion Version stored in KSEF_LAST_INIT_VERSION (empty if never initialized)
 * @return bool
 */
function ksefNeedsReactivation($currentVersion, $lastInitVersion)
{
    if (empty($lastInitVersion)) return true;
    return version_compare($lastInitVersion, $currentVersion, '<');
}

/**
 * @brief Extrafield types not supported as DodatkowyOpis entries
 * @return string[]
 */
function ksefDodatkowyOpisUnsupportedTypes()
{
    return array('boolean', 'html', 'link', 'checkbox', 'chkbxlst', 'stars', 'radio', 'separate', 'password');
}

/**
 * @brief Gets KSeF portal base URL
 * @param string|null $environment Environment (TEST, DEMO, PRODUCTION). If null, uses configured environment.
 * @return string Portal base URL
 */
function ksefGetPortalBaseUrl($environment = null)
{
    if (empty($environment)) {
        $environment = getDolGlobalString('KSEF_ENVIRONMENT', 'DEMO');
    }

    switch (strtoupper($environment)) {
        case 'PRODUCTION':
            return KSEF_PORTAL_URL_PRODUCTION;
        case 'DEMO':
            return KSEF_PORTAL_URL_DEMO;
        case 'TEST':
        default:
            return KSEF_PORTAL_URL_TEST;
    }
}

/**
 * @brief Gets KSeF QR/verification base URL
 * @param string|null $environment Environment (TEST, DEMO, PRODUCTION). If null, uses configured environment.
 * @return string QR verification base URL
 */
function ksefGetQrBaseUrl($environment = null)
{
    if (empty($environment)) {
        $environment = getDolGlobalString('KSEF_ENVIRONMENT', 'DEMO');
    }

    switch (strtoupper($environment)) {
        case 'PRODUCTION':
            return KSEF_QR_URL_PRODUCTION;
        case 'DEMO':
            return KSEF_QR_URL_DEMO;
        case 'TEST':
        default:
            return KSEF_QR_URL_TEST;
    }
}

/**
 * @brief Returns a color-coded badge for KSeF Latarnia system status
 * @param string $status  Latarnia status code (AVAILABLE, MAINTENANCE, FAILURE, TOTAL_FAILURE, UNREACHABLE, UNKNOWN)
 * @return string Badge HTML
 * @called_by ksefindex.php, admin/setup.php
 */
function ksefGetLatarniaStatusBadge($status)
{
    global $langs;
    $langs->load("ksef@ksef");

    $badges = array(
        'AVAILABLE'     => '<span class="badge badge-status4 badge-status">' . $langs->trans('KSEF_LATARNIA_AVAILABLE') . '</span>',
        'MAINTENANCE'   => '<span class="badge badge-status1 badge-status" style="background-color: #fd7e14;">' . $langs->trans('KSEF_LATARNIA_MAINTENANCE') . '</span>',
        'FAILURE'       => '<span class="badge badge-status8 badge-status">' . $langs->trans('KSEF_LATARNIA_FAILURE') . '</span>',
        'TOTAL_FAILURE' => '<span class="badge badge-status8 badge-status" style="background-color: #721c24;">' . $langs->trans('KSEF_LATARNIA_TOTAL_FAILURE') . '</span>',
        'UNREACHABLE'   => '<span class="badge badge-status0 badge-status"><i class="fas fa-exclamation-triangle"></i> ' . $langs->trans('KSEF_LATARNIA_UNREACHABLE') . '</span>',
        'UNKNOWN'       => '<span class="badge badge-status0 badge-status">' . $langs->trans('KSEF_LATARNIA_UNKNOWN') . '</span>',
    );

    return isset($badges[$status]) ? $badges[$status] : '<span class="badge badge-status0">' . htmlspecialchars($status) . '</span>';
}

/**
 * @brief Calculates offline deadline based on the type of KSeF outage
 *
 * Legal deadlines:
 *   MAINTENANCE   - end of next business day after outage ends (Art. 106nh)
 *   FAILURE       - 7 business days after outage ends (Art. 106nf)
 *   TOTAL_FAILURE - no deadline, no obligation to re-send (Art. 106ng)
 *
 * @param int         $invoice_date  Invoice issue date (timestamp)
 * @param string|null $outage_type   MAINTENANCE, FAILURE, TOTAL_FAILURE, or null for default
 * @param int|null    $outage_end    Outage end timestamp (null if ongoing)
 * @return int|null   Deadline timestamp, null for TOTAL_FAILURE or unknown end
 * @called_by ksef_service.class.php
 */
function ksefCalculateOfflineDeadlineByOutageType($invoice_date, $outage_type = null, $outage_end = null)
{
    if (empty($outage_type)) {
        return ksefCalculateOfflineDeadline($invoice_date);
    }

    if ($outage_type === 'TOTAL_FAILURE') {
        return null;
    }

    if (empty($outage_end)) {
        return null;
    }

    if (!is_numeric($outage_end)) {
        $outage_end = strtotime($outage_end);
    }

    if ($outage_type === 'FAILURE') {
        return ksefGetNthWeekday($outage_end, 7);
    }

    // MAINTENANCE - next business day after outage ends
    return ksefGetNextWeekday($outage_end);
}

/**
 * @brief Gets the Nth weekday after a given date (end of that day)
 * @param int $date  Start date timestamp
 * @param int $n     Number of weekdays to advance
 * @return int Timestamp at 23:59:59 of the Nth weekday
 * @called_by ksefCalculateOfflineDeadlineByOutageType()
 */
function ksefGetNthWeekday($date, $n)
{
    if (!is_numeric($date)) {
        $date = strtotime($date);
    }

    $current = strtotime('00:00:00', $date);
    $count = 0;

    while ($count < $n) {
        $current = strtotime('+1 day', $current);
        if (ksefIsWeekDay($current)) {
            $count++;
        }
    }

    return strtotime('23:59:59', $current);
}

/**
 * @brief Prepares admin head tabs
 * @return array Tab array
 * @called_by setup.php, howtouse.php, about.php
 */
function ksefAdminPrepareHead()
{
    global $langs, $conf;
    $langs->load("ksef@ksef");

    $h = 0;
    $head = array();

    $head[$h][0] = dol_buildpath("/ksef/admin/setup.php", 1);
    $head[$h][1] = $langs->trans("KSEF_Tab_General");
    $head[$h][2] = 'general';
    $h++;

    $head[$h][0] = dol_buildpath("/ksef/admin/setup_auth.php", 1);
    $head[$h][1] = $langs->trans("KSEF_Tab_Authentication");
    $head[$h][2] = 'auth';
    $h++;

    $head[$h][0] = dol_buildpath("/ksef/admin/setup_outgoing.php", 1);
    $head[$h][1] = $langs->trans("KSEF_Tab_Outgoing");
    $head[$h][2] = 'outgoing';
    $h++;

    $head[$h][0] = dol_buildpath("/ksef/admin/setup_incoming.php", 1);
    $head[$h][1] = $langs->trans("KSEF_Tab_Incoming");
    $head[$h][2] = 'incoming';
    $h++;

    $head[$h][0] = dol_buildpath("/ksef/admin/howtouse.php", 1);
    $head[$h][1] = $langs->trans("KSEF_HowToUse");
    $head[$h][2] = 'howtouse';
    $h++;

    $head[$h][0] = dol_buildpath("/ksef/admin/about.php", 1);
    $head[$h][1] = $langs->trans("KSEF_About");
    $head[$h][2] = 'about';
    $h++;

    complete_head_from_modules($conf, $langs, null, $head, $h, 'ksef');
    return $head;
}

/**
 * @brief Formats NIP with dashes
 * @param $nip NIP string
 * @return string Formatted NIP
 * @called_by ksefindex.php
 */
function ksefFormatNIP($nip)
{
    $nip = preg_replace('/[^0-9]/', '', $nip);
    if (strlen($nip) == 10) {
//        return substr($nip, 0, 3).'-'.substr($nip, 3, 3).'-'.substr($nip, 6, 2).'-'.substr($nip, 8, 2);
        // not sure I like using the dashes everywhere ... //TODO see if we really want this or not?
        return $nip;
    }
    return $nip;
}

/**
 * @brief Cleans NIP (removes all non-digit characters)
 * @param $nip NIP string
 * @return string Cleaned NIP with only digits
 */
function ksefCleanNIP($nip)
{
    return preg_replace('/[^0-9]/', '', $nip);
}

/**
 * @brief Cleans REGON to its 9 digit core
 * @param $regon REGON string
 * @return string First 9 digits of the REGON
 */
function ksefCleanREGON($regon)
{
    return substr(ksefCleanNIP($regon), 0, 9);
}

/**
 * @brief Escapes a value for inclusion in XML (dol_escape_xml is a no-op)
 * @param $value Raw value
 * @return string XML safe value
 */
function ksefXmlEscape($value)
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/**
 * @brief Validates NIP checksum
 * @param $nip NIP string
 * @return bool True if valid
 * @called_by External validation
 */
function ksefValidateNIP($nip)
{
    $nip = ksefCleanNIP($nip);
    if (strlen($nip) != 10) return false;

    $weights = array(6, 5, 7, 2, 3, 4, 5, 6, 7);
    $sum = 0;
    for ($i = 0; $i < 9; $i++) {
        $sum += (int)$nip[$i] * $weights[$i];
    }

    $checksum = $sum % 11;
    if ($checksum == 10) return false;

    return ($checksum == (int)$nip[9]);
}

/**
 * @brief Strip the 2-letter country prefix from a VAT ID
 * @param string $vatId VAT ID
 * @return string VAT ID without country prefix
 */
function ksefStripVATPrefix($vatId)
{
    $vatId = trim($vatId);
    if (preg_match('/^[A-Za-z]{2}(.+)$/', $vatId, $m)) {
        return $m[1];
    }
    return $vatId;
}

/**
 * @brief Infer country code
 * @param object $thirdparty Societe or mysoc object
 * @return string 2-letter country code (uppercase)
 */
function ksefInferCountryCode($thirdparty)
{
    if (!empty($thirdparty->country_code)) {
        return strtoupper($thirdparty->country_code);
    }
    if (!empty($thirdparty->tva_intra) && preg_match('/^([A-Za-z]{2})/', trim($thirdparty->tva_intra), $m)) {
        return strtoupper($m[1]);
    }
    return 'PL';
}

/**
 * @brief Get the configured field name
 * @param string $identifier One of: 'NIP', 'KRS', 'REGON', 'BDO'
 * @return string Field name
 */
function ksefGetFieldName($identifier)
{
    global $conf;

    $defaults = array(
        'NIP'   => 'idprof1',
        'KRS'   => 'idprof2',
        'REGON' => 'idprof3',
        'BDO'   => 'idprof4',
        'EORI'  => 'idprof5',
    );

    $validFields = array('idprof1', 'idprof2', 'idprof3', 'idprof4', 'idprof5', 'idprof6', 'tva_intra');

    $constName = 'KSEF_FIELD_' . strtoupper($identifier);
    if (!empty($conf->global->$constName)) {
        $val = $conf->global->$constName;
        if (in_array($val, $validFields)) {
            return $val;
        }
        return isset($defaults[$identifier]) ? $defaults[$identifier] : '';
    }

    return isset($defaults[$identifier]) ? $defaults[$identifier] : '';
}

/**
 * PHP property name to database column name
 * @param string $fieldName PHP property
 * @return string DB column name
 */
function ksefFieldToColumn($fieldName)
{
    $map = array(
        'idprof1' => 'siren',
        'idprof2' => 'siret',
        'idprof3' => 'ape',
    );
    return isset($map[$fieldName]) ? $map[$fieldName] : $fieldName;
}

/**
 * label for KSeF FormaPlatnosci code
 * @param string|null $code KSeF payment method code (1-7)
 * @return string|null Polish label, or null
 */
function ksefGetPaymentMethodLabel($code)
{
    $labels = array(
        '1' => 'Gotówka',    // Cash
        '2' => 'Karta',      // Card
        '3' => 'Bon',        // Voucher
        '4' => 'Czek',       // Cheque
        '5' => 'Kredyt',     // Credit
        '6' => 'Przelew',    // Bank Transfer
        '7' => 'Mobilna',    // Mobile
    );
    return isset($labels[$code]) ? $labels[$code] : null;
}

/**
 * @brief Get the value of a configured identifier field
 * @param object $thirdparty Societe or mysoc object
 * @param string $identifier One of: 'NIP', 'KRS', 'REGON', 'BDO'
 * @return string The raw field value
 */
function ksefGetIdentifierField($thirdparty, $identifier)
{
    $fieldName = ksefGetFieldName($identifier);

    if (!empty($fieldName) && !empty($thirdparty->$fieldName)) {
        return $thirdparty->$fieldName;
    }

    if ($identifier === 'NIP' && $fieldName !== 'tva_intra' && !empty($thirdparty->tva_intra)) {
        return $thirdparty->tva_intra;
    }

    return '';
}

/**
 * @brief Get translated field options
 * @param bool $includeNone for optional fields like KRS/REGON/BDO
 * @return array array of field_name to translated label
 */
function ksefGetFieldOptions($includeNone = false)
{
    global $langs, $mysoc;

    $country_code = !empty($mysoc->country_code) ? $mysoc->country_code : 'PL';
    $langs->load("companies");

    $options = array();
    if ($includeNone) {
        $options[''] = $langs->trans("KSEF_FIELD_NONE");
    }

    for ($i = 1; $i <= 6; $i++) {
        // Use Dolibarr's country-specific label
        $longKey = "ProfId" . $i;
        $longLabel = $langs->transcountry($longKey, $country_code);
        if (empty($longLabel) || $longLabel === $longKey . $country_code || $longLabel === $longKey) {
            $longLabel = '';
        }
        $shortKey = "ProfId" . $i . "Short";
        $shortLabel = $langs->trans($shortKey);
        if (empty($shortLabel) || $shortLabel === $shortKey) {
            $shortLabel = "Prof. ID " . $i;
        }
        if (!empty($longLabel) && $longLabel !== $shortLabel) {
            $options['idprof' . $i] = $shortLabel . ' - ' . $longLabel . ' (idprof' . $i . ')';
        } else {
            $options['idprof' . $i] = $shortLabel . ' (idprof' . $i . ')';
        }
    }

    $vatLabel = $langs->trans("VATIntra");
    if (empty($vatLabel) || $vatLabel === 'VATIntra') {
        $vatLabel = 'VAT Intra';
    }
    $options['tva_intra'] = $vatLabel . ' (tva_intra)';

    return $options;
}

/**
 * @brief Get the list of translation override keys
 * @return array Array of key => alue pairs
 */
function ksefGetManagedTranslationOverrides()
{
    $overrides = array();

    $identifiers = array(
        'NIP'   => 'NIP',
        'KRS'   => 'KRS',
        'REGON' => 'REGON',
        'BDO'   => 'BDO',
        'EORI'  => 'EORI',
    );

    foreach ($identifiers as $ident => $label) {
        $fieldName = ksefGetFieldName($ident);
        if (empty($fieldName)) {
            continue;
        }

        if ($fieldName === 'tva_intra') {
            if ($ident === 'NIP') {
                $overrides['VATIntra'] = 'VAT/NIP';
                $overrides['VATIntraShort'] = 'VAT/NIP';
            } else {
                $overrides['VATIntra'] = $label;
                $overrides['VATIntraShort'] = $label;
            }
        } elseif (preg_match('/^idprof(\d+)$/', $fieldName, $m)) {
            $overrides['ProfId' . $m[1] . 'PL'] = $label;
            $overrides['ProfId' . $m[1] . 'ShortPL'] = $label;
        }
    }

    if (!isset($overrides['VATIntra'])) {
        $overrides['VATIntra'] = 'VAT';
        $overrides['VATIntraShort'] = 'VAT';
    }

    return $overrides;
}

/**
 * @brief All translation keys that KSeF manages
 * @return array List of transkey strings
 */
function ksefGetAllManagedTransKeys()
{
    $keys = array('VATIntra', 'VATIntraShort');
    for ($i = 1; $i <= 6; $i++) {
        $keys[] = 'ProfId' . $i . 'PL';
        $keys[] = 'ProfId' . $i . 'ShortPL';
    }
    return $keys;
}

/**
 * @brief Apply translation overrides
 * @param DoliDB $db Database handler
 * @param array $languages Array of language codes
 * @return int 1 on success, -1 on error
 */
function ksefApplyTranslationOverrides($db, $languages)
{
    global $conf;

    if (empty($languages)) {
        return -1;
    }

    if (empty($conf->global->MAIN_ENABLE_OVERWRITE_TRANSLATION)) {
        dolibarr_set_const($db, 'MAIN_ENABLE_OVERWRITE_TRANSLATION', '1', 'chaine', 0, '', $conf->entity);
    }

    $overrides = ksefGetManagedTranslationOverrides();
    $allManagedKeys = ksefGetAllManagedTransKeys();

    $db->begin();

    // Remove existing
    foreach ($allManagedKeys as $transkey) {
        foreach ($languages as $lang) {
            $sql = "DELETE FROM " . MAIN_DB_PREFIX . "overwrite_trans";
            $sql .= " WHERE transkey = '" . $db->escape($transkey) . "'";
            $sql .= " AND lang = '" . $db->escape($lang) . "'";
            $sql .= " AND entity IN (0, " . ((int) $conf->entity) . ")";
            $db->query($sql);
        }
    }

    // Insert new
    foreach ($overrides as $transkey => $transvalue) {
        foreach ($languages as $lang) {
            $sql = "INSERT INTO " . MAIN_DB_PREFIX . "overwrite_trans (lang, transkey, transvalue, entity)";
            $sql .= " VALUES ('" . $db->escape($lang) . "', '" . $db->escape($transkey) . "',";
            $sql .= " '" . $db->escape($transvalue) . "', " . ((int) $conf->entity) . ")";
            if (!$db->query($sql)) {
                $db->rollback();
                return -1;
            }
        }
    }

    $db->commit();
    return 1;
}

/**
 * @brief Remove all KSeF-managed translation override
 * @param DoliDB $db Database handler
 * @param array $languages Array of language codes
 * @return int 1 on success, -1 on error
 */
function ksefRemoveTranslationOverrides($db, $languages)
{
    global $conf;

    if (empty($languages)) {
        return -1;
    }

    $allManagedKeys = ksefGetAllManagedTransKeys();

    $db->begin();

    foreach ($allManagedKeys as $transkey) {
        foreach ($languages as $lang) {
            $sql = "DELETE FROM " . MAIN_DB_PREFIX . "overwrite_trans";
            $sql .= " WHERE transkey = '" . $db->escape($transkey) . "'";
            $sql .= " AND lang = '" . $db->escape($lang) . "'";
            $sql .= " AND entity IN (0, " . ((int) $conf->entity) . ")";
            if (!$db->query($sql)) {
                $db->rollback();
                return -1;
            }
        }
    }

    $db->commit();
    return 1;
}

/**
 * @brief Check which KSeF-managed translation
 * @param DoliDB $db Database handler
 * @return array Array of existing overrides
 */
function ksefGetCurrentTranslationOverrides($db)
{
    global $conf;

    static $cache = array();
    $cacheKey = (int) $conf->entity;
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $allManagedKeys = ksefGetAllManagedTransKeys();
    $escaped = array_map(function ($k) use ($db) {
        return "'" . $db->escape($k) . "'";
    }, $allManagedKeys);

    $sql = "SELECT lang, transkey, transvalue FROM " . MAIN_DB_PREFIX . "overwrite_trans";
    $sql .= " WHERE transkey IN (" . implode(',', $escaped) . ")";
    $sql .= " AND entity IN (0, " . ((int) $conf->entity) . ")";
    $sql .= " ORDER BY lang, transkey";

    $result = array();
    $resql = $db->query($sql);
    if ($resql) {
        while ($obj = $db->fetch_object($resql)) {
            $result[] = array(
                'lang' => $obj->lang,
                'transkey' => $obj->transkey,
                'transvalue' => $obj->transvalue,
            );
        }
    }

    $cache[$cacheKey] = $result;
    return $result;
}

/**
 * @brief Checks if the KSeF managed idprof/VAT translation overrides are fully applied
 * @param DoliDB $db Database handler
 * @param array $languages Language codes to require (defaults to en_US and pl_PL)
 * @return bool True only if is on and every override matches
 * @called_by admin/setup_auth.php, class/actions_ksef.class.php
 * @calls ksefGetManagedTranslationOverrides(), ksefGetCurrentTranslationOverrides()
 */
function ksefTranslationOverridesReady($db, $languages = array('en_US', 'pl_PL'))
{
    global $conf;

    if (empty($conf->global->MAIN_ENABLE_OVERWRITE_TRANSLATION)) {
        return false;
    }

    $want = ksefGetManagedTranslationOverrides();

    $have = array();
    foreach (ksefGetCurrentTranslationOverrides($db) as $ov) {
        $have[$ov['lang']][$ov['transkey']] = $ov['transvalue'];
    }

    foreach ($want as $key => $value) {
        if (preg_match('/Short/', $key)) {
            continue; // skip Short variants
        }
        foreach ($languages as $lang) {
            if (!isset($have[$lang][$key]) || $have[$lang][$key] !== $value) {
                return false;
            }
        }
    }

    return true;
}

/**
 * @brief Resolves the societe card DOM input id for a KSeF identifier
 * @param $identifier Identifier name (NIP, KRS, REGON, BDO, EORI)
 * @return string|null jQuery selector (e.g. #idprof1, #intra_vat) or null if unmapped
 * @called_by class/actions_ksef.class.php
 * @calls ksefGetFieldName()
 */
function ksefGusDomFieldId($identifier)
{
    $fieldName = ksefGetFieldName($identifier);

    if ($fieldName === 'tva_intra') {
        return '#intra_vat';
    }
    if (preg_match('/^idprof[1-6]$/', $fieldName)) {
        return '#' . $fieldName;
    }

    return null;
}

/**
 * @brief Builds the prefilled GUS email
 * @return string Plain text email body (Polish; the recipient is GUS)
 * @called_by admin/setup_auth.php, admin/howtouse.php
 * @calls ksefGetIdentifierField()
 */
function ksefGusRequestEmail()
{
    global $mysoc;

    $name = (is_object($mysoc) && !empty($mysoc->name)) ? $mysoc->name : '[nazwa firmy]';

    // Prefer KSeF settings, then company
    $nip = trim(getDolGlobalString('KSEF_COMPANY_NIP'));
    if ($nip === '') {
        $nip = trim(ksefGetIdentifierField($mysoc, 'NIP'));
    }
    if ($nip === '') {
        $nip = '[NIP]';
    }
    $regon = trim(getDolGlobalString('KSEF_COMPANY_REGON'));
    if ($regon === '') {
        $regon = trim(ksefGetIdentifierField($mysoc, 'REGON'));
    }
    if ($regon === '') {
        $regon = '[REGON]';
    }
    $email = !empty($mysoc->email) ? $mysoc->email : '[adres e-mail]';
    $phone = !empty($mysoc->phone) ? $mysoc->phone : '[+48 numer telefonu]';

    return "Do: regon_bir@stat.gov.pl\n"
        . "Temat: Wniosek o wydanie Klucza Użytkownika do usługi BIR1\n\n"
        . "Dzień dobry,\n\n"
        . "proszę o wydanie Klucza Użytkownika do środowiska produkcyjnego usługi BIR1.\n\n"
        . "Podmiot: " . $name . "\n"
        . "REGON: " . $regon . "\n"
        . "NIP: " . $nip . "\n"
        . "Osoba kontaktowa: [imię i nazwisko]\n"
        . "E-mail: " . $email . "\n"
        . "Telefon: " . $phone . "\n"
        . "Adresy IP: zmienne (połączenia bezpośrednio z własnego systemu)\n"
        . "Przybliżona liczba jednoczesnych użytkowników: [np. 2]\n\n"
        . "Klucz będzie wykorzystywany w naszym systemie Dolibarr z modułem KSeF (https://github.com/InPoint-Automation/Dolibarr-KSeF-Module) do weryfikacji danych rejestrowych kontrahentów na podstawie numeru NIP.\n\n"
        . "W razie potrzeby chętnie przekażę dodatkowe informacje.\n\n"
        . "Z poważaniem,\n"
        . "[imię i nazwisko]";
}

/**
 * @brief Gets status badge HTML
 * @param $status Status code
 * @return string Badge HTML
 * @called_by status.php, ksefindex.php, tab_ksef.php
 */
function ksefGetStatusBadge($status)
{
    global $langs;
    $langs->load("ksef@ksef");

    $badges = array(
        'PENDING' => '<span class="badge badge-status1 badge-status">'.$langs->trans('KSEF_STATUS_PENDING').'</span>',
        'SUBMITTED' => '<span class="badge badge-status1 badge-status">'.$langs->trans('KSEF_STATUS_SUBMITTED').'</span>',
        'ACCEPTED' => '<span class="badge badge-status4 badge-status">'.$langs->trans('KSEF_STATUS_ACCEPTED').'</span>',
        'REJECTED' => '<span class="badge badge-status8 badge-status">'.$langs->trans('KSEF_STATUS_REJECTED').'</span>',
        'FAILED' => '<span class="badge badge-status8 badge-status">'.$langs->trans('KSEF_STATUS_FAILED').'</span>',
        'TIMEOUT' => '<span class="badge badge-status7 badge-status">'.$langs->trans('KSEF_STATUS_TIMEOUT').'</span>',
        'OFFLINE' => '<span class="badge badge-status7 badge-status" style="background-color: #fd7e14;">'.$langs->trans('KSEF_STATUS_OFFLINE').'</span>',
    );

    return isset($badges[$status]) ? $badges[$status] : '<span class="badge badge-status">'.htmlspecialchars($status).'</span>';
}


/**
 * @brief Gets environment badge HTML
 * @param $environment Environment code
 * @return string Badge HTML
 * @called_by status.php, ksefindex.php, tab_ksef.php
 */
function ksefGetEnvironmentBadge($environment)
{
    global $langs;
    $badges = array(
        'TEST' => '<span class="badge badge-status1">'.$langs->trans('KSEF_ENV_TEST').'</span>',
        'DEMO' => '<span class="badge badge-status7">'.$langs->trans('KSEF_ENV_DEMO').'</span>',
        'PRODUCTION' => '<span class="badge badge-status4">'.$langs->trans('KSEF_ENV_PRODUCTION').'</span>'
    );
    return isset($badges[$environment]) ? $badges[$environment] : '<span class="badge">'.htmlspecialchars($environment).'</span>';
}

/**
 * @brief Gets KSeF verification URL
 * @param $ksef_number KSEFService number
 * @param $invoice_hash Invoice hash
 * @param $environment Environment
 * @param $nip Seller NIP
 * @param $invoice_date Invoice date timestamp
 * @return string Verification URL
 * @called_by KsefQR::addQRToPDF(), status.php
 */
function ksefGetVerificationURL($ksef_number, $invoice_hash = null, $environment = null, $nip = null, $invoice_date = null)
{
    global $conf, $mysoc;

    if (empty($environment)) {
        $environment = !empty($conf->global->KSEF_ENVIRONMENT) ? $conf->global->KSEF_ENVIRONMENT : 'DEMO';
    }

    $base_url = ksefGetQrBaseUrl($environment);

    if (empty($nip)) {
        if (!empty($ksef_number)) {
            $parts = explode('-', $ksef_number);
            $nip = $parts[0] ?? '';
        }
        if (empty($nip)) {
            $nip = ksefCleanNIP(ksefGetIdentifierField($mysoc, 'NIP'));
        }
    }

    if (empty($nip)) {
        throw new Exception("NIP is required for verification URL");
    }

    if (!empty($invoice_date)) {
        if (is_numeric($invoice_date)) {
            $formattedDate = date('d-m-Y', $invoice_date);
        } else {
            $formattedDate = date('d-m-Y', strtotime($invoice_date));
        }
    } elseif (!empty($ksef_number)) {
        $parts = explode('-', $ksef_number);
        if (count($parts) >= 2) {
            $dateStr = $parts[1];
            $date = DateTime::createFromFormat('Ymd', $dateStr);
            $formattedDate = $date ? $date->format('d-m-Y') : date('d-m-Y');
        } else {
            $formattedDate = date('d-m-Y');
        }
    } else {
        $formattedDate = date('d-m-Y');
    }

    if (!empty($invoice_hash)) {
        $decoded = base64_decode($invoice_hash);
        $base64Url = rtrim(strtr(base64_encode($decoded), '+/', '-_'), '=');
    } else {
        throw new Exception("Invoice hash is required for verification URL");
    }

    // return $base_url . '/client-app/invoice/' . $nip . '/' . $formattedDate . '/' . $base64Url;
    return $base_url . '/invoice/' . $nip . '/' . $formattedDate . '/' . $base64Url;
}

/**
 * @brief Checks if customer is excluded from KSeF submissions
 * @param $socid Customer ID
 * @return bool True if excluded
 * @called_by ActionsKSEF, setup.php, status.php
 */
function ksefIsCustomerExcluded($socid) {
    global $conf;
    if (empty($conf->global->KSEF_EXCLUDED_CUSTOMERS)) return false;
    $excluded = array_map('trim', explode(',', $conf->global->KSEF_EXCLUDED_CUSTOMERS));
    return in_array($socid, $excluded);
}

/**
 * @brief Updates invoice KSeF extrafields and optionally regenerates PDF
 * @param $db Database handler
 * @param $invoice_id Invoice ID
 * @param $ksef_number KSEFService number (optional)
 * @param $status Status (optional)
 * @param $submission_date Submission date timestamp (optional)
 * @param $regenerate_pdf Regenerate PDF flag
 * @return int Result code (1 on success, -1 on error)
 */
function ksefUpdateInvoiceExtrafields($db, $invoice_id, $ksef_number = null, $status = null, $submission_date = null, $regenerate_pdf = false)
{
    global $langs;

    require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';

    $invoice = new Facture($db);
    if ($invoice->fetch($invoice_id) <= 0) {
        return -1;
    }

    if (empty($invoice->array_options)) {
        $invoice->array_options = array();
    }

    // Update fields only if provided
    if ($ksef_number !== null) {
        $invoice->array_options['options_ksef_number'] = $ksef_number;
    }
    if ($status !== null) {
        $invoice->array_options['options_ksef_status'] = $status;
    }
    if ($submission_date !== null) {
        $invoice->array_options['options_ksef_submission_date'] = $submission_date;
    }

    $result = $invoice->insertExtraFields();

    // Regenerate PDF if requested
    if ($result >= 0 && $regenerate_pdf && !empty($ksef_number)) {
        $invoice->fetch($invoice_id); // Reload to get updated extrafields
        $pdf_result = $invoice->generateDocument($invoice->model_pdf, $langs, 0, 0, 0);
        if ($pdf_result > 0) {
            dol_syslog("KSeF: PDF regenerated for invoice " . $invoice->ref . " with KSEF number", LOG_INFO);
        } else {
            dol_syslog("KSeF: PDF regeneration failed for invoice " . $invoice->ref . ": " . $invoice->error, LOG_ERR);
        }
    }

    return $result >= 0 ? 1 : -1;
}

/**
 * @brief Checks if date is a weekday
 * @param int|string $date Date to check (timestamp or string)
 * @return bool True if Mon-Fri
 * @called_by ksefGetNextWorkingDay(), ksefCalculateOfflineDeadline()
 */
function ksefIsWeekDay($date)
{
    if (!is_numeric($date)) {
        $date = strtotime($date);
    }
    $dayOfWeek = date('N', $date);
    return ($dayOfWeek >= 1 && $dayOfWeek <= 5);
}

/**
 * @brief Gets next weekday
 * @param int|string $date Starting date (timestamp or string)
 * @return int Next working day timestamp (end of day 23:59:59)
 * @called_by ksefCalculateOfflineDeadline()
 */
function ksefGetNextWeekday($date)
{
    if (!is_numeric($date)) {
        $date = strtotime($date);
    }

    $nextDay = strtotime('+1 day', strtotime('00:00:00', $date));

    while (!ksefIsWeekDay($nextDay)) {
        $nextDay = strtotime('+1 day', $nextDay);
    }

    return strtotime('23:59:59', $nextDay);
}

/**
 * @brief Calculates offline submission deadline (end of next weekday)
 * @param int|string $invoice_date Invoice issue date
 * @return int Deadline timestamp
 * @called_by KSEF::submitInvoice(), ksefDetectBackdating()
 */
function ksefCalculateOfflineDeadline($invoice_date)
{
    if (!is_numeric($invoice_date)) {
        $invoice_date = strtotime($invoice_date);
    }
    return ksefGetNextWeekday($invoice_date);
}

/**
 * @brief Detects if invoice is backdated (issue date before today)
 * @param int|string $invoice_date Invoice date
 * @return array ['is_backdated' => bool, 'days_behind' => int, 'reason' => string, 'deadline' => int]
 * @called_by KSEF::needsOfflineConfirmation()
 */
function ksefDetectBackdating($invoice_date)
{
    if (!is_numeric($invoice_date)) {
        $invoice_date = strtotime($invoice_date);
    }

    $invoice_day_start = strtotime('00:00:00', $invoice_date);
    $today_start = strtotime('00:00:00', dol_now());

    $diff_seconds = $today_start - $invoice_day_start;
    $days_behind = (int) floor($diff_seconds / 86400);

    $result = array(
        'is_backdated' => false,
        'days_behind' => $days_behind,
        'reason' => '',
        'deadline' => 0
    );

    if ($days_behind > 0) {
        $result['is_backdated'] = true;
        $result['deadline'] = ksefCalculateOfflineDeadline($invoice_date);
        $result['reason'] = sprintf('Invoice date %d day(s) behind current date', $days_behind);
    }

    return $result;
}

/**
 * @brief Checks if offline deadline has passed
 * @param int $deadline Deadline timestamp
 * @return bool True if deadline passed
 * @called_by status.php, cron job
 */
function ksefIsDeadlinePassed($deadline)
{
    return (dol_now() > $deadline);
}

/**
 * @brief Formats offline deadline for display with urgency indicator
 * @param int $deadline Deadline timestamp
 * @return string Formatted deadline HTML
 * @called_by status.php, tab_ksef.php
 */
function ksefFormatDeadline($deadline)
{
    global $langs;
    $langs->load("ksef@ksef");

    $now = dol_now();
    $hours_remaining = ($deadline - $now) / 3600;

    $formatted = dol_print_date($deadline, 'dayhour');

    if ($hours_remaining < 0) {
        return '<span class="badge badge-danger">' . $formatted . ' (' . $langs->trans('KSEF_DeadlinePassed') . ')</span>';
    } elseif ($hours_remaining < 4) {
        return '<span class="badge badge-warning">' . $formatted . ' (' . $langs->trans('KSEF_DeadlineUrgent') . ')</span>';
    } else {
        return '<span class="badge badge-info">' . $formatted . '</span>';
    }
}

/**
 * @brief Gets offline mode badge HTML
 * @param string $mode Offline mode
 * @return string Badge HTML
 * @called_by status.php, tab_ksef.php
 */
function ksefGetOfflineModeBadge($mode)
{
    global $langs;
    $langs->load("ksef@ksef");

    if (empty($mode)) {
        return '';
    }

    $badges = array(
        'OFFLINE' => '<span class="badge badge-warning">' . $langs->trans('KSEF_MODE_OFFLINE') . '</span>',
    );

    return isset($badges[$mode]) ? $badges[$mode] : '<span class="badge">' . htmlspecialchars($mode) . '</span>';
}

/**
 * @brief Gets authentication method badge HTML
 * @param string $method Authentication method (token/certificate)
 * @return string Badge HTML
 * @called_by setup.php, status.php
 */
function ksefGetAuthMethodBadge($method)
{
    global $langs;
    $langs->load("ksef@ksef");

    $badges = array(
        'token' => '<span class="badge badge-info"><i class="fa fa-key"></i> ' . $langs->trans('KSEF_AUTH_METHOD_TOKEN') . '</span>',
        'certificate' => '<span class="badge badge-primary"><i class="fa fa-certificate"></i> ' . $langs->trans('KSEF_AUTH_METHOD_CERTIFICATE') . '</span>'
    );

    return isset($badges[$method]) ? $badges[$method] : '<span class="badge">' . htmlspecialchars($method) . '</span>';
}

/**
 * @brief Checks authentication certificate status
 * @return array ['configured' => bool, 'valid' => bool, 'expires_soon' => bool, 'serial' => string]
 * @called_by setup.php, ksef_client.class.php
 */
function ksefCheckAuthCertificate($environment = null)
{
    if ($environment === null) {
        $environment = getDolGlobalString('KSEF_ENVIRONMENT', 'TEST');
    }
    $environment = strtoupper($environment);

    $result = array(
        'configured' => false,
        'valid' => false,
        'expires_soon' => false,
        'serial' => '',
        'valid_to' => null
    );

    if (empty(getDolGlobalString('KSEF_AUTH_CERTIFICATE_' . $environment)) ||
        empty(getDolGlobalString('KSEF_AUTH_PRIVATE_KEY_' . $environment))) {
        return $result;
    }

    $result['configured'] = true;
    $result['serial'] = getDolGlobalString('KSEF_AUTH_CERT_SERIAL_' . $environment, '');

    $valid_to = getDolGlobalString('KSEF_AUTH_CERT_VALID_TO_' . $environment, '');
    if (!empty($valid_to)) {
        $result['valid_to'] = $valid_to;

        $now = dol_now();
        if ($valid_to > $now) {
            $result['valid'] = true;

            // Check if expires within 30 days
            $thirty_days = 30 * 24 * 3600;
            if (($valid_to - $now) < $thirty_days) {
                $result['expires_soon'] = true;
            }
        }
    } else {
        $result['valid'] = true;
    }

    return $result;
}

/**
 * @brief Converts standard Base64 to Base64URL (URL-safe, no padding)
 * @param string $base64 Standard Base64 string
 * @return string Base64URL string
 */
function ksefBase64ToBase64URL($base64)
{
    return rtrim(strtr($base64, '+/', '-_'), '=');
}

/**
 * @brief Converts Base64URL back to standard Base64
 * @param string $base64url Base64URL string
 * @return string Standard Base64 string
 */
function ksefBase64URLToBase64($base64url)
{
    $base64 = strtr($base64url, '-_', '+/');
    $padding = strlen($base64) % 4;
    if ($padding) {
        $base64 .= str_repeat('=', 4 - $padding);
    }
    return $base64;
}

/**
 * @brief Converts PEM certificate to DER format
 * @param string $pem PEM-encoded certificate
 * @return string DER binary data
 */
function ksefPemToDer($pem)
{
    $begin = '-----BEGIN CERTIFICATE-----';
    $end = '-----END CERTIFICATE-----';
    $pem = str_replace(array($begin, $end, "\r", "\n", " "), '', $pem);
    return base64_decode($pem);
}

/**
 * @brief Converts ECDSA DER signature
 * @param string $derSignature DER-encoded signature
 * @param int $componentSize Size of R and S components
 * @return string Raw signature
 */
function ksefConvertEcdsaDerToRaw($derSignature, $componentSize = 32)
{
    $offset = 0;
    $len = strlen($derSignature);

    // Check for SEQUENCE tag (0x30)
    if ($len < 2 || ord($derSignature[$offset]) != 0x30) {
        return $derSignature;
    }
    $offset++;

    // sequence length
    $seqLen = ord($derSignature[$offset]);
    $offset++;
    if ($seqLen > 0x80) {
        $offset += ($seqLen - 0x80);
    }

    // Parse R
    if ($offset >= $len || ord($derSignature[$offset]) != 0x02) {
        return $derSignature;
    }
    $offset++;
    $rLength = ord($derSignature[$offset]);
    $offset++;
    $r = substr($derSignature, $offset, $rLength);
    $offset += $rLength;

    // Parse S
    if ($offset >= $len || ord($derSignature[$offset]) != 0x02) {
        return $derSignature;
    }
    $offset++;
    $sLength = ord($derSignature[$offset]);
    $offset++;
    $s = substr($derSignature, $offset, $sLength);

    // Remove leading zeros
    $r = ltrim($r, "\x00");
    $s = ltrim($s, "\x00");

    // Pad to fixed component size
    $r = str_pad($r, $componentSize, "\x00", STR_PAD_LEFT);
    $s = str_pad($s, $componentSize, "\x00", STR_PAD_LEFT);

    return $r . $s;
}

/**
 * @brief Signs data with private key
 * @param string $data Data to sign
 * @param string $privateKeyPem Decrypted private key in PEM format
 * @param bool $returnBase64URL Return as Base64URL instead of raw
 * @return string|false Signature or false on error
 */
function ksefSignData($data, $privateKeyPem, $returnBase64URL = true)
{
    $privateKey = openssl_pkey_get_private($privateKeyPem);
    if (!$privateKey) {
        dol_syslog("ksefSignData: Failed to load private key: " . openssl_error_string(), LOG_ERR);
        return false;
    }

    $keyDetails = openssl_pkey_get_details($privateKey);
    $isEcKey = ($keyDetails['type'] == OPENSSL_KEYTYPE_EC);

    $signature = '';
    if (!openssl_sign($data, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
        dol_syslog("ksefSignData: Signing failed: " . openssl_error_string(), LOG_ERR);
        return false;
    }

    // Convert ECDSA DER
    if ($isEcKey) {
        $curveName = $keyDetails['ec']['curve_name'] ?? 'prime256v1';
        $componentSize = 32;
        if (strpos($curveName, '384') !== false) {
            $componentSize = 48;
        } elseif (strpos($curveName, '521') !== false) {
            $componentSize = 66;
        }
        $signature = ksefConvertEcdsaDerToRaw($signature, $componentSize);
    }

    if ($returnBase64URL) {
        return ksefBase64ToBase64URL(base64_encode($signature));
    }

    return $signature;
}

/**
 * @brief Loads and decrypts offline certificate credentials
 * @return array|false ['certificate_pem', 'private_key_pem', 'serial'] or false
 */
function ksefLoadOfflineCertificate($environment = null)
{
    require_once DOL_DOCUMENT_ROOT . '/core/lib/security.lib.php';

    if ($environment === null) {
        $environment = getDolGlobalString('KSEF_ENVIRONMENT', 'TEST');
    }
    $environment = strtoupper($environment);

    $cert_base64 = getDolGlobalString('KSEF_OFFLINE_CERTIFICATE_' . $environment, '');
    if (empty($cert_base64)) {
        dol_syslog("ksefLoadOfflineCertificate: Certificate not configured for $environment", LOG_WARNING);
        return false;
    }

    $key_base64 = getDolGlobalString('KSEF_OFFLINE_PRIVATE_KEY_' . $environment, '');
    if (empty($key_base64)) {
        dol_syslog("ksefLoadOfflineCertificate: Private key not configured for $environment", LOG_WARNING);
        return false;
    }

    $encrypted_password = getDolGlobalString('KSEF_OFFLINE_KEY_PASSWORD_' . $environment, '');
    if (empty($encrypted_password)) {
        dol_syslog("ksefLoadOfflineCertificate: Password not configured for $environment", LOG_WARNING);
        return false;
    }

    $certificate_pem = base64_decode($cert_base64);
    $encrypted_key_pem = base64_decode($key_base64);
    $password = dol_decode($encrypted_password);

    $private_key = openssl_pkey_get_private($encrypted_key_pem, $password);
    if (!$private_key) {
        dol_syslog("ksefLoadOfflineCertificate: Failed to decrypt key for $environment: " . openssl_error_string(), LOG_ERR);
        return false;
    }

    $private_key_pem = '';
    openssl_pkey_export($private_key, $private_key_pem);

    return array(
        'certificate_pem' => $certificate_pem,
        'private_key_pem' => $private_key_pem,
        'serial' => getDolGlobalString('KSEF_OFFLINE_CERT_SERIAL_' . $environment, '')
    );
}

/**
 * @brief Checks if offline certificate is fully configured
 * @return array ['configured' => bool, 'missing' => array of missing items]
 */
function ksefIsOfflineCertificateConfigured($environment = null)
{
    if ($environment === null) {
        $environment = getDolGlobalString('KSEF_ENVIRONMENT', 'TEST');
    }
    $environment = strtoupper($environment);

    $result = array(
        'configured' => false,
        'missing' => array()
    );

    if (empty(getDolGlobalString('KSEF_OFFLINE_CERTIFICATE_' . $environment))) {
        $result['missing'][] = 'certificate';
    }
    if (empty(getDolGlobalString('KSEF_OFFLINE_PRIVATE_KEY_' . $environment))) {
        $result['missing'][] = 'private_key';
    }
    if (empty(getDolGlobalString('KSEF_OFFLINE_KEY_PASSWORD_' . $environment))) {
        $result['missing'][] = 'password';
    }

    $result['configured'] = empty($result['missing']);

    return $result;
}

/**
 * @brief Generates and saves KSeF style PDF visualization
 * @param DoliDB          $db          Database handle
 * @param KsefSubmission  $submission  Submission with fa3_xml populated
 * @param int             $invoice_id  Invoice (facture) rowid
 * @param string          &$errorMsg   error message
 * @return bool True on success, false on failure (non-fatal - logged only)
 */
function ksefAutoGeneratePdf($db, $submission, $invoice_id, &$errorMsg = '')
{
    global $conf;

    try {
        require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
        dol_include_once('/ksef/class/ksef_invoice_pdf.class.php');
        dol_include_once('/ksef/class/actions_ksef.class.php');

        $invoice = new Facture($db);
        if ($invoice->fetch($invoice_id) <= 0) {
            $errorMsg = "Could not fetch invoice $invoice_id";
            dol_syslog("ksefAutoGeneratePdf - " . $errorMsg, LOG_WARNING);
            return false;
        }

        $actions = new ActionsKSEF($db);
        $invoiceData = $actions->createPdfDataFromSubmission($submission, $invoice);

        $pdfGenerator = new KsefInvoicePdf($db);
        $pdfContent = $pdfGenerator->generate($invoiceData);

        if ($pdfContent === false) {
            $errorMsg = $pdfGenerator->error ?: 'PDF generation failed';
            dol_syslog("ksefAutoGeneratePdf - generate failed: " . $errorMsg, LOG_WARNING);
            return false;
        }

        $sanitizedRef = dol_sanitizeFileName($invoice->ref);
        $outputDir = $conf->invoice->multidir_output[$invoice->entity ?? $conf->entity] . '/' . $sanitizedRef;
        if (!is_dir($outputDir)) {
            $mkdirResult = dol_mkdir($outputDir);
            if ($mkdirResult < 0) {
                $errorMsg = "Could not create directory: $outputDir";
                dol_syslog("ksefAutoGeneratePdf - dol_mkdir failed for $outputDir (result=$mkdirResult)", LOG_ERR);
                return false;
            }
        }

        $filepath = $outputDir . '/' . $sanitizedRef . '_ksef.pdf';
        $bytesWritten = file_put_contents($filepath, $pdfContent);
        if ($bytesWritten === false) {
            $lastError = error_get_last();
            $detail = $lastError ? $lastError['message'] : 'unknown';
            $errorMsg = "Could not write file: $filepath - $detail";
            dol_syslog("ksefAutoGeneratePdf - file_put_contents failed: " . $errorMsg, LOG_ERR);
            return false;
        }

        dol_syslog("ksefAutoGeneratePdf - saved $filepath ($bytesWritten bytes)", LOG_INFO);
        return true;

    } catch (Exception $e) {
        $errorMsg = $e->getMessage();
        dol_syslog("ksefAutoGeneratePdf ERROR: " . $errorMsg, LOG_ERR);
        return false;
    }
}

/**
 * @brief Calculates SHA-256 hash of data in Base64 format
 * @param string $data Data to hash
 * @return string Base64-encoded SHA-256 hash
 */
function ksefCalculateHash($data)
{
    return base64_encode(hash('sha256', $data, true));
}

/**
 * @brief Builds ASN.1 length encoding
 * @param int $length Length value
 * @return string Encoded length bytes
 * @called_by ksefBuildASN1Sequence(), ksefBuildASN1Set(), ksefBuildASN1Integer(), etc.
 */
function ksefBuildASN1Length($length)
{
    if ($length < 128) {
        return chr($length);
    }
    $lengthBytes = '';
    $temp = $length;
    while ($temp > 0) {
        $lengthBytes = chr($temp & 0xFF) . $lengthBytes;
        $temp >>= 8;
    }
    return chr(0x80 | strlen($lengthBytes)) . $lengthBytes;
}

/**
 * @brief Builds ASN.1 SEQUENCE (tag 0x30)
 * @param string $content Sequence content
 * @return string DER-encoded SEQUENCE
 * @called_by ksefBuildX500NameDER(), ksefBuildRDN(), ksefGenerateIssuerSerialV2DER()
 */
function ksefBuildASN1Sequence($content)
{
    return chr(0x30) . ksefBuildASN1Length(strlen($content)) . $content;
}

/**
 * @brief Builds ASN.1 SET (tag 0x31)
 * @param string $content Set content
 * @return string DER-encoded SET
 * @called_by ksefBuildRDN()
 */
function ksefBuildASN1Set($content)
{
    return chr(0x31) . ksefBuildASN1Length(strlen($content)) . $content;
}

/**
 * @brief Builds ASN.1 INTEGER from decimal or hex string
 * @param string $number Number in decimal or hex format
 * @return string DER-encoded INTEGER
 * @called_by ksefGenerateIssuerSerialV2DER()
 */
function ksefBuildASN1Integer($number)
{
    if (preg_match('/^[0-9A-Fa-f]+$/', $number) && strlen($number) % 2 === 0 && strlen($number) > 2) {
        $bytes = hex2bin($number);
    } else {
        if (function_exists('gmp_init')) {
            $hex = gmp_strval(gmp_init($number, 10), 16);
        } else {
            $hex = dechex($number);
        }
        if (strlen($hex) % 2) $hex = '0' . $hex;
        $bytes = hex2bin($hex);
    }
    if (ord($bytes[0]) & 0x80) {
        $bytes = "\x00" . $bytes;
    }
    return chr(0x02) . ksefBuildASN1Length(strlen($bytes)) . $bytes;
}

/**
 * @brief Encodes a single OID component using base-128 encoding
 * @param int $value OID component value
 * @return string Encoded bytes
 * @called_by ksefBuildASN1ObjectIdentifier()
 */
function ksefEncodeOIDComponent($value)
{
    if ($value < 128) {
        return chr($value);
    }
    $bytes = array();
    while ($value > 0) {
        array_unshift($bytes, $value & 0x7F);
        $value >>= 7;
    }
    $encoded = '';
    for ($i = 0; $i < count($bytes); $i++) {
        $byte = $bytes[$i];
        if ($i < count($bytes) - 1) {
            $byte |= 0x80;
        }
        $encoded .= chr($byte);
    }
    return $encoded;
}

/**
 * @brief Builds ASN.1 OBJECT IDENTIFIER (tag 0x06)
 * @param string $oid OID in dotted notation (e.g., "2.5.4.3")
 * @return string DER-encoded OBJECT IDENTIFIER
 * @called_by ksefBuildRDN()
 */
function ksefBuildASN1ObjectIdentifier($oid)
{
    $parts = explode('.', $oid);
    $encoded = chr(40 * intval($parts[0]) + intval($parts[1]));
    for ($i = 2; $i < count($parts); $i++) {
        $encoded .= ksefEncodeOIDComponent(intval($parts[$i]));
    }
    return chr(0x06) . ksefBuildASN1Length(strlen($encoded)) . $encoded;
}

/**
 * @brief Builds ASN.1 UTF8String (tag 0x0C)
 * @param string $string String value
 * @return string DER-encoded UTF8String
 * @called_by ksefBuildRDN()
 */
function ksefBuildASN1UTF8String($string)
{
    return chr(0x0C) . ksefBuildASN1Length(strlen($string)) . $string;
}

/**
 * @brief Builds ASN.1 PrintableString (tag 0x13)
 * @param string $string String value
 * @return string DER-encoded PrintableString
 * @called_by ksefBuildRDN()
 */
function ksefBuildASN1PrintableString($string)
{
    return chr(0x13) . ksefBuildASN1Length(strlen($string)) . $string;
}

/**
 * @brief Builds a single RelativeDistinguishedName in DER format
 * @param string $attr Attribute name (C, ST, L, O, OU, CN, etc.)
 * @param string $value Attribute value
 * @return string DER-encoded RDN
 * @called_by ksefBuildX500NameDER()
 */
function ksefBuildRDN($attr, $value)
{
    $oids = array(
        'C'            => '2.5.4.6',
        'ST'           => '2.5.4.8',
        'L'            => '2.5.4.7',
        'O'            => '2.5.4.10',
        'OU'           => '2.5.4.11',
        'CN'           => '2.5.4.3',
        'serialNumber' => '2.5.4.5',
    );
    $oid = isset($oids[$attr]) ? $oids[$attr] : $attr;
    $oidDER = ksefBuildASN1ObjectIdentifier($oid);
    if ($attr === 'C') {
        $valueDER = ksefBuildASN1PrintableString($value);
    } else {
        $valueDER = ksefBuildASN1UTF8String($value);
    }
    $attrTypeAndValue = ksefBuildASN1Sequence($oidDER . $valueDER);
    return ksefBuildASN1Set($attrTypeAndValue);
}

/**
 * @brief Builds X.500 Name in DER format from parsed certificate components
 * @param array $components Issuer components from openssl_x509_parse()
 * @return string DER-encoded X.500 Name
 * @called_by ksefGenerateIssuerSerialV2DER()
 */
function ksefBuildX500NameDER($components)
{
    $order = array('CN', 'OU', 'O', 'L', 'ST', 'C', 'serialNumber');
    $rdns = '';

    foreach ($order as $key) {
        $value = null;
        if (isset($components[$key])) {
            $value = $components[$key];
        } elseif (isset($components[strtolower($key)])) {
            $value = $components[strtolower($key)];
        }
        if ($value !== null) {
            if (is_array($value)) {
                $value = $value[0];
            }
            $rdns .= ksefBuildRDN($key, $value);
        }
    }
    return ksefBuildASN1Sequence($rdns);
}

/**
 * @brief Formats issuer name in RFC 2253 format for XAdES
 * @param array $issuer Issuer array from openssl_x509_parse()
 * @return string RFC 2253 formatted issuer name
 * @called_by KsefClient::signXadesAuthRequest()
 */
function ksefFormatIssuerNameRfc2253($issuer)
{
    $parts = array();
    $order = array('CN', 'OU', 'O', 'L', 'ST', 'C');

    foreach ($order as $key) {
        if (isset($issuer[$key])) {
            $val = $issuer[$key];
            $val = str_replace(
                array('\\', ',', '+', '"', '<', '>', ';'),
                array('\\\\', '\\,', '\\+', '\\"', '\\<', '\\>', '\\;'),
                $val
            );
            $parts[] = $key . '=' . $val;
        }
    }
    return implode(',', $parts);
}

/**
 * @brief Generates DER-encoded IssuerSerial for IssuerSerialV2 in XAdES
 * @param string $certificatePem Certificate in PEM format
 * @return string Base64-encoded DER IssuerSerial
 * @called_by KsefClient::buildSignedProperties()
 */
function ksefGenerateIssuerSerialV2DER($certificatePem)
{
    $certInfo = openssl_x509_parse($certificatePem);
    if (!$certInfo) {
        return '';
    }

    $issuerNameDER = ksefBuildX500NameDER($certInfo['issuer']);
    $generalNameDER = chr(0xA4) . ksefBuildASN1Length(strlen($issuerNameDER)) . $issuerNameDER;
    $generalNamesDER = ksefBuildASN1Sequence($generalNameDER);
    $serialDER = ksefBuildASN1Integer($certInfo['serialNumber']);
    $issuerSerialDER = ksefBuildASN1Sequence($generalNamesDER . $serialDER);

    return base64_encode($issuerSerialDER);
}

/**
 * @brief Loads and decrypts authentication certificate credentials
 * @return array|false ['certificate_pem', 'private_key_pem', 'serial'] or false
 * @called_by KsefClient::authenticateWithCertificate()
 */
function ksefLoadAuthCertificate($environment = null)
{
    require_once DOL_DOCUMENT_ROOT . '/core/lib/security.lib.php';

    if ($environment === null) {
        $environment = getDolGlobalString('KSEF_ENVIRONMENT', 'TEST');
    }
    $environment = strtoupper($environment);

    $cert_base64 = getDolGlobalString('KSEF_AUTH_CERTIFICATE_' . $environment, '');
    if (empty($cert_base64)) {
        dol_syslog("ksefLoadAuthCertificate: Certificate not configured for $environment", LOG_WARNING);
        return false;
    }

    $key_base64 = getDolGlobalString('KSEF_AUTH_PRIVATE_KEY_' . $environment, '');
    if (empty($key_base64)) {
        dol_syslog("ksefLoadAuthCertificate: Private key not configured for $environment", LOG_WARNING);
        return false;
    }

    $encrypted_password = getDolGlobalString('KSEF_AUTH_KEY_PASSWORD_' . $environment, '');
    if (empty($encrypted_password)) {
        dol_syslog("ksefLoadAuthCertificate: Password not configured for $environment", LOG_WARNING);
        return false;
    }

    $certificate_pem = base64_decode($cert_base64);
    $encrypted_key_pem = base64_decode($key_base64);
    $password = dol_decode($encrypted_password);

    $private_key = openssl_pkey_get_private($encrypted_key_pem, $password);
    if (!$private_key) {
        dol_syslog("ksefLoadAuthCertificate: Failed to decrypt key for $environment: " . openssl_error_string(), LOG_ERR);
        return false;
    }

    $private_key_pem = '';
    openssl_pkey_export($private_key, $private_key_pem);

    return array(
        'certificate_pem' => $certificate_pem,
        'private_key_pem' => $private_key_pem,
        'serial' => getDolGlobalString('KSEF_AUTH_CERT_SERIAL_' . $environment, '')
    );
}

/**
 * @brief writes data to session
 * @param string $key Session key to set
 * @param mixed $value Value to store
 * @param bool $closeAfter Whether to close session after write
 * @return bool True on success
 * @called_by ActionsKSEF::doActions()
 */
function ksefSessionWrite($key, $value, $closeAfter = true)
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        } else {
            dol_syslog("ksefSessionWrite: Cannot start session, status=" . session_status(), LOG_WARNING);
            return false;
        }
    }

    $_SESSION[$key] = $value;

    if ($closeAfter) {
        session_write_close();
    }

    return true;
}

/**
 * @brief Removes data from session
 * @param string $key Session key to unset
 * @param bool $closeAfter Whether to close session after (default: true)
 * @return bool True on success
 */
function ksefSessionUnset($key, $closeAfter = true)
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        } else {
            return false;
        }
    }

    unset($_SESSION[$key]);

    if ($closeAfter) {
        session_write_close();
    }

    return true;
}

/**
 * @brief Reads session data
 * @param string $key Session key to read
 * @param mixed $default Default value if key doesn't exist
 * @param bool $closeAfter Whether to close session after read (default: true)
 * @return mixed Session value or default
 */
function ksefSessionRead($key, $default = null, $closeAfter = true)
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        } else {
            return $default;
        }
    }

    $value = isset($_SESSION[$key]) ? $_SESSION[$key] : $default;

    if ($closeAfter) {
        session_write_close();
    }

    return $value;
}

/**
 * @brief Gets status badge HTML
 * @param $status Import status (NEW, IMPORTED, SKIPPED, ERROR)
 * @return string HTML badge
 */
function ksefGetIncomingStatusBadge($status)
{
    global $langs;
    $langs->load("ksef@ksef");

    switch ($status) {
        case 'NEW':
            return '<span class="badge badge-status1 badge-status">' . $langs->trans('KSEF_ImportStatusNEW') . '</span>';
        case 'IMPORTED':
            return '<span class="badge badge-status4 badge-status">' . $langs->trans('KSEF_ImportStatusIMPORTED') . '</span>';
        case 'SKIPPED':
            return '<span class="badge badge-status8 badge-status">' . $langs->trans('KSEF_ImportStatusSKIPPED') . '</span>';
        case 'ERROR':
            return '<span class="badge badge-status9 badge-status">' . $langs->trans('KSEF_ImportStatusERROR') . '</span>';
        default:
            return '<span class="badge badge-status0 badge-status">' . dol_escape_htmltag($status) . '</span>';
    }
}

/**
 * @brief Gets invoice type badge HTML
 * @param $type Invoice type (VAT, KOR, ZAL, etc.)
 * @return string HTML badge
 */
function ksefGetInvoiceTypeBadge($type)
{
    switch ($type) {
        case 'VAT':
            return '<span class="badge badge-status4">VAT</span>';
        case 'KOR':
            return '<span class="badge badge-status1">KOR</span>';
        case 'KOR_ZAL':
            return '<span class="badge badge-status1">KOR_ZAL</span>';
        case 'KOR_ROZ':
            return '<span class="badge badge-status1">KOR_ROZ</span>';
        case 'ZAL':
            return '<span class="badge badge-status5">ZAL</span>';
        case 'ROZ':
            return '<span class="badge badge-status6">ROZ</span>';
        default:
            return '<span class="badge badge-status0">' . dol_escape_htmltag($type) . '</span>';
    }
}


/**
 * @brief Prepares tabs for KSeF incoming invoice card
 * @param $object KsefIncoming object related to tabs
 * @return array Array of tabs to show
 */
function ksef_incoming_prepare_head($object)
{
    global $langs, $conf;
    $langs->load("ksef@ksef");

    $h = 0;
    $head = array();

    $head[$h][0] = dol_buildpath("/ksef/incoming_card.php", 1) . '?id=' . $object->id;
    $head[$h][1] = $langs->trans("Card");
    $head[$h][2] = 'card';
    $h++;

    $head[$h][0] = dol_buildpath("/ksef/incoming_import.php", 1) . '?id=' . $object->id;
    $head[$h][1] = $langs->trans("KSEF_ImportToDolibarr");
    $head[$h][2] = 'import';
    $h++;

    complete_head_from_modules($conf, $langs, $object, $head, $h, 'ksef_incoming');

    complete_head_from_modules($conf, $langs, $object, $head, $h, 'ksef_incoming', 'remove');

    return $head;
}


/**
 * @brief Gets KSeF verification URL for incoming invoice from raw XML
 * @param $ksef_number KSEFService invoice number
 * @param $fa3_xml Raw FA(3) XML content
 * @param $environment Environment (TEST, DEMO, PRODUCTION)
 * @return string Verification URL for KSeF portal
 */
function ksefGetVerificationUrlFromXml($ksef_number, $fa3_xml, $environment = null)
{
    if (empty($ksef_number) || empty($fa3_xml)) {
        return '';
    }

    $hash = base64_encode(hash('sha256', $fa3_xml, true));

    try {
        return ksefGetVerificationURL($ksef_number, $hash, $environment);
    } catch (Exception $e) {
        dol_syslog("ksefGetVerificationUrlFromXml: " . $e->getMessage(), LOG_WARNING);
        return '';
    }
}

/**
 * Get effective invoice currency for KSeF
 * @param Facture $invoice Invoice object
 * @return string Currency code
 */
function ksefGetInvoiceCurrency($invoice)
{
    if (!empty($invoice->multicurrency_code)) {
        return $invoice->multicurrency_code;
    }

    global $conf;
    return !empty($conf->currency) ? $conf->currency : 'EUR';
}


/**
 * Render a Dolibarr supplier invoice card display in the same style as incoming_card.php
 *
 * Shows fichecenter layout with left column (ref, type, date, status), right column
 * (currency, amounts HT/VAT/TTC), and line items table below - mirroring how the
 * incoming card and import pages display invoice data.
 *
 * @param FactureFournisseur $invoice       The supplier invoice to display
 * @param string             $title         Section title/heading
 * @param string             $icon          FontAwesome icon class (e.g. 'fa-minus-circle')
 * @param string             $iconColor     CSS color for the icon
 * @param string             $description   Optional description text below the title
 * @param FactureFournisseur|null $originalInv  Optional original invoice (for credit notes that zero out an original)
 * @return void
 */
function ksefPrintSupplierInvoiceCard($invoice, $title, $icon = '', $iconColor = '', $description = '', $originalInv = null)
{
    global $langs;

    $invoice->fetch_lines();

    $currency = !empty($invoice->multicurrency_code) ? $invoice->multicurrency_code : 'PLN';
    $isMulticurrency = ($currency !== 'PLN');

    // Section heading
    print '<div class="div-table-responsive-no-min" style="margin-top: 15px; margin-bottom: 5px;">';
    print '<table class="noborder centpercent">';
    print '<tr class="liste_titre">';
    print '<td class="liste_titre">';
    if ($icon) {
        print '<span class="fa ' . $icon . '" style="color: ' . dol_escape_htmltag($iconColor) . '; margin-right: 6px;"></span>';
    }
    print dol_escape_htmltag($title);
    print '</td>';
    print '</tr>';
    print '</table>';
    print '</div>';

    // fichecenter layout
    print '<div class="fichecenter">';

    // Left column: key fields
    print '<div class="fichehalfleft">';
    print '<table class="border tableforfield centpercent">';

    // Ref + status
    print '<tr><td class="titlefield">' . $langs->trans("Ref") . '</td>';
    print '<td>' . $invoice->getNomUrl(1) . ' ' . $invoice->getLibStatut(5) . '</td></tr>';

    // Type
    print '<tr><td>' . $langs->trans("Type") . '</td><td>';
    if ($invoice->type == FactureFournisseur::TYPE_CREDIT_NOTE) {
        print '<span class="badgeneutral">' . $langs->trans("InvoiceAvoir") . '</span>';
    } else {
        print '<span class="badgeneutral">' . $langs->trans("InvoiceStandard") . '</span>';
    }
    print '</td></tr>';

    // Source invoice (for credit notes)
    if ($originalInv) {
        print '<tr><td>' . $langs->trans("KSEF_ReplaceCreditNoteDesc_Source") . '</td>';
        print '<td>' . $originalInv->getNomUrl(1) . ' ' . $originalInv->getLibStatut(5) . '</td></tr>';
    }

    // Supplier ref
    if (!empty($invoice->ref_supplier)) {
        print '<tr><td>' . $langs->trans("RefSupplierBill") . '</td>';
        print '<td>' . dol_escape_htmltag($invoice->ref_supplier) . '</td></tr>';
    }

    // Date
    print '<tr><td>' . $langs->trans("DateInvoice") . '</td>';
    print '<td>' . dol_print_date($invoice->date, 'day') . '</td></tr>';

    // Description
    if (!empty($description)) {
        print '<tr><td>' . $langs->trans("Description") . '</td>';
        print '<td><span class="opacitymedium">' . dol_escape_htmltag($description) . '</span></td></tr>';
    }

    print '</table>';
    print '</div>'; // fichehalfleft

    // Right column: amounts
    print '<div class="fichehalfright">';
    print '<table class="border tableforfield centpercent">';

    // Currency
    if ($isMulticurrency) {
        print '<tr><td class="titlefieldmiddle">' . $langs->trans("Currency") . '</td>';
        print '<td>' . dol_escape_htmltag($currency) . '</td></tr>';
    }

    // Amount HT
    print '<tr>';
    print '<td class="titlefieldmiddle">' . $langs->trans('AmountHT') . '</td>';
    if ($isMulticurrency) {
        print '<td class="nowrap amountcard right">' . price($invoice->multicurrency_total_ht, 0, $langs, 0, -1, -1, $currency) . '</td>';
    } else {
        print '<td class="nowrap amountcard right">' . price($invoice->total_ht, 0, $langs, 0, -1, -1, $currency) . '</td>';
    }
    print '</tr>';

    // Amount VAT
    print '<tr>';
    print '<td>' . $langs->trans('AmountVAT') . '</td>';
    if ($isMulticurrency) {
        print '<td class="nowrap amountcard right">' . price($invoice->multicurrency_total_tva, 0, $langs, 0, -1, -1, $currency) . '</td>';
    } else {
        print '<td class="nowrap amountcard right">' . price($invoice->total_tva, 0, $langs, 0, -1, -1, $currency) . '</td>';
    }
    print '</tr>';

    // Amount TTC
    print '<tr>';
    print '<td>' . $langs->trans('AmountTTC') . '</td>';
    if ($isMulticurrency) {
        print '<td class="nowrap amountcard right">' . price($invoice->multicurrency_total_ttc, 0, $langs, 0, -1, -1, $currency) . '</td>';
    } else {
        print '<td class="nowrap amountcard right">' . price($invoice->total_ttc, 0, $langs, 0, -1, -1, $currency) . '</td>';
    }
    print '</tr>';

    print '</table>';
    print '</div>'; // fichehalfright

    print '</div>'; // fichecenter
    print '<div class="clearboth"></div>';

    // Line items table
    if (!empty($invoice->lines)) {
        print '<div class="div-table-responsive-no-min">';
        print '<table class="noborder noshadow centpercent">';

        print '<tr class="liste_titre nodrag nodrop">';
        print '<td class="linecoldescription">' . $langs->trans("Description") . '</td>';
        print '<td class="linecolvat right nowraponall">' . $langs->trans("VAT") . '</td>';
        print '<td class="linecoluht right nowraponall">' . $langs->trans("PriceUHT") . '</td>';
        print '<td class="linecolqty right">' . $langs->trans("Qty") . '</td>';
        print '<td class="linecolht right">' . $langs->trans("TotalHT") . '</td>';
        print '</tr>';

        foreach ($invoice->lines as $line) {
            $unitPrice = $isMulticurrency ? $line->multicurrency_subprice : $line->subprice;
            $totalHT = $isMulticurrency ? $line->multicurrency_total_ht : $line->total_ht;

            print '<tr class="oddeven">';
            print '<td class="linecoldescription">' . dol_escape_htmltag($line->desc) . '</td>';
            print '<td class="linecolvat nowrap right">' . vatrate($line->tva_tx) . '%</td>';
            print '<td class="linecoluht nowraponall right">' . price($unitPrice, 0, '', 1, -1, 2) . '</td>';
            print '<td class="linecolqty nowraponall right">' . price($line->qty, 0, '', 1, -1, 4) . '</td>';
            print '<td class="linecolht nowrap right">' . price($totalHT, 0, '', 1, -1, 2) . '</td>';
            print '</tr>';
        }

        // Total row
        $totalHT = $isMulticurrency ? $invoice->multicurrency_total_ht : $invoice->total_ht;
        print '<tr class="liste_total">';
        print '<td colspan="4" class="right">' . $langs->trans("Total") . '</td>';
        print '<td class="right nowraponall">' . price($totalHT, 0, $langs, 0, -1, -1, $currency) . '</td>';
        print '</tr>';

        print '</table>';
        print '</div>';
    }
}

/**
 * @brief Parses a comma-separated extrafield config string
 * @param string $configStr  e.g. "field1:dodatkowy,field2:stopka"
 * @return array  ['fieldname' => 'target', ...]
 */
function ksefParseEfConfig($configStr)
{
    $result = array();
    $items = array_filter(array_map('trim', explode(',', $configStr)));
    foreach ($items as $item) {
        $parts = explode(':', $item, 2);
        $fname = $parts[0];
        $target = isset($parts[1]) ? $parts[1] : 'dodatkowy';
        if ($fname !== '') $result[$fname] = $target;
    }
    return $result;
}

/**
 * @brief Returns the module version
 * @param DoliDB $db
 * @return string
 * @called_by ksefCheckForUpdate(), ksefShowUpdateBanner(), ksefShowReactivationWarning()
 */
function ksefGetModuleVersion($db)
{
    static $version = null;
    if ($version === null) {
        dol_include_once('/ksef/core/modules/modKSEF.class.php');
        $module = new modKSEF($db);
        $version = $module->version;
    }
    return $version;
}

/**
 * @brief Renders admin banner box
 * @param string $iconClass  Font Awesome icon classes
 * @param string $iconColor  Icon CSS color
 * @param string $bg         Background CSS color
 * @param string $border     Border CSS color
 * @param string $innerHtml  Pre-escaped banner body HTML
 * @return string HTML
 * @called_by ksefShowReactivationWarning(), ksefShowUpdateBanner()
 */
function ksefRenderBanner($iconClass, $iconColor, $bg, $border, $innerHtml)
{
    $h = '<div style="margin-bottom: 15px; padding: 12px; background: ' . $bg . '; border: 1px solid ' . $border . '; border-radius: 4px;">';
    $h .= '<i class="' . $iconClass . '" style="color: ' . $iconColor . '; margin-right: 6px;"></i>';
    $h .= $innerHtml;
    $h .= '</div>';
    return $h;
}

/**
 * @brief Renders the confirm-gated .zip download link
 * @param string $latest  Latest version (for the confirm prompt)
 * @param string $zipUrl  Download URL
 * @param string $attrs   Optional extra anchor attributes (class/style)
 * @return string HTML
 * @called_by ksefShowUpdateBanner(), ksefRenderSupportBox()
 */
function ksefRenderDownloadLink($latest, $zipUrl, $attrs = '')
{
    global $langs;
    $confirm = dol_escape_js($langs->trans('KSEF_UpdateDownloadConfirm', $latest));
    return '<a href="' . dol_escape_htmltag($zipUrl) . '" target="_blank" rel="noopener noreferrer"' . ($attrs !== '' ? ' ' . $attrs : '') . ' onclick="return confirm(\'' . $confirm . '\');">' . $langs->trans('KSEF_DownloadUpdate') . '</a>';
}

/**
 * @brief Shows reactivation warning banner if module version changed since last init
 * @return string HTML string (empty if no warning needed)
 */
function ksefShowReactivationWarning()
{
    global $db, $langs;
    $langs->load("ksef@ksef");

    $currentVersion = ksefGetModuleVersion($db);
    $lastInit = getDolGlobalString('KSEF_LAST_INIT_VERSION', '');

    if (!ksefNeedsReactivation($currentVersion, $lastInit)) {
        return '';
    }

    $fromVersion = $lastInit !== '' ? $lastInit : $langs->trans('KSEF_VERSION_UNKNOWN');
    $inner = '<strong>' . $langs->trans('KSEF_MODULE_NEEDS_REACTIVATION') . '</strong><br>';
    $inner .= dol_escape_htmltag($langs->trans('KSEF_MODULE_NEEDS_REACTIVATION_Desc', $fromVersion, $currentVersion));
    $inner .= ' <a href="' . DOL_URL_ROOT . '/admin/modules.php?restore_lastsearch_values=1" class="button button-small" style="margin-left: 8px;">' . $langs->trans('KSEF_GOTO_MODULES_LIST') . '</a>';

    return ksefRenderBanner('fa fa-exclamation-triangle', '#856404', '#fff3cd', '#ffc107', $inner);
}

/**
 * @brief Query GitHub for the latest release
 * @param DoliDB $db
 * @return array {status:'ok'|'error', current, latest, available:bool, release_url, zip_url, message}
 * @called_by ksefMaybeRunUpdateCheck()
 * @calls getURLContent(), ksefGetModuleVersion()
 */
function ksefCheckForUpdate($db)
{
    global $conf, $langs;
    require_once DOL_DOCUMENT_ROOT . '/core/lib/geturl.lib.php';

    $current = ksefGetModuleVersion($db);

    $result = array('status' => 'error', 'current' => $current, 'latest' => '', 'available' => false, 'release_url' => 'https://github.com/InPoint-Automation/Dolibarr-KSeF-Module/releases', 'zip_url' => '', 'message' => '');

    $apiurl = 'https://api.github.com/repos/InPoint-Automation/Dolibarr-KSeF-Module/releases/latest';
    $headers = array('Accept: application/vnd.github+json', 'User-Agent: Dolibarr-KSeF-Module');

    dol_syslog("ksefCheckForUpdate GET $apiurl", LOG_INFO);
    $res = getURLContent($apiurl, 'GET', '', 1, $headers, array('https'), 0, -1, 5, 10);

    if (!empty($res['curl_error_no'])) {
        $result['message'] = $res['curl_error_msg'];
        return $result;
    }
    if (empty($res['http_code']) || $res['http_code'] != 200 || empty($res['content'])) {
        $result['message'] = 'HTTP ' . (empty($res['http_code']) ? '0' : $res['http_code']);
        return $result;
    }

    $data = json_decode($res['content'], true);
    $latest = (!empty($data) && !empty($data['tag_name'])) ? ltrim($data['tag_name'], 'vV') : '';
    if ($latest === '') {
        $result['message'] = $langs->trans('KSEF_UpdateCheckParseError');
        return $result;
    }

    if (!empty($data['html_url'])) {
        $result['release_url'] = $data['html_url'];
    }

    $zipUrl = '';
    if (!empty($data['assets']) && is_array($data['assets'])) {
        foreach ($data['assets'] as $asset) {
            if (!empty($asset['browser_download_url']) && !empty($asset['name']) && preg_match('/\.zip$/i', $asset['name'])) {
                $zipUrl = $asset['browser_download_url'];
                break;
            }
        }
    }
    if ($zipUrl === '' && !empty($data['zipball_url'])) {
        $zipUrl = $data['zipball_url'];
    }

    $available = (version_compare($current, $latest) < 0);

    $result['status'] = 'ok';
    $result['latest'] = $latest;
    $result['available'] = $available;
    $result['zip_url'] = $zipUrl;

    dolibarr_set_const($db, 'KSEF_UPDATE_LATEST_VERSION', $latest, 'chaine', 0, '', $conf->entity);
    dolibarr_set_const($db, 'KSEF_UPDATE_RELEASE_URL', $result['release_url'], 'chaine', 0, '', $conf->entity);
    dolibarr_set_const($db, 'KSEF_UPDATE_ZIP_URL', $zipUrl, 'chaine', 0, '', $conf->entity);

    return $result;
}

/**
 * @brief Update-available banner
 * @return string HTML
 * @called_by ksefShowAdminBanners()
 * @calls ksefGetModuleVersion(), ksefRenderDownloadLink(), ksefRenderBanner()
 */
function ksefShowUpdateBanner()
{
    global $db, $langs;
    $langs->load("ksef@ksef");

    // Show if latest newer than running
    $latest = getDolGlobalString('KSEF_UPDATE_LATEST_VERSION', '');
    $current = ksefGetModuleVersion($db);
    if ($latest === '' || version_compare($current, $latest) >= 0) {
        return '';
    }

    $releaseUrl = getDolGlobalString('KSEF_UPDATE_RELEASE_URL', 'https://github.com/InPoint-Automation/Dolibarr-KSeF-Module/releases');
    $zipUrl = getDolGlobalString('KSEF_UPDATE_ZIP_URL', '');

    $inner = '<strong>' . $langs->trans('KSEF_UpdateBannerTitle') . '</strong> ';
    $inner .= dol_escape_htmltag($langs->trans('KSEF_UpdateBannerDesc', $latest, $current));
    if ($zipUrl !== '') {
        $inner .= ' ' . ksefRenderDownloadLink($latest, $zipUrl, 'class="button button-small" style="margin-left: 8px;"');
    }
    $inner .= ' <a href="' . dol_escape_htmltag($releaseUrl) . '" target="_blank" rel="noopener noreferrer" style="margin-left: 8px;">' . $langs->trans('KSEF_Support_Releases') . '</a>';

    return ksefRenderBanner('fa fa-arrow-circle-up', '#0c5460', '#d1ecf1', '#17a2b8', $inner);
}

/**
 * @brief Runs the GitHub update check
 * @param DoliDB $db
 * @return array|null  Update result
 * @called_by admin/about.php, admin/setup.php
 * @calls ksefCheckForUpdate()
 */
function ksefMaybeRunUpdateCheck($db)
{
    if (GETPOST('action', 'aZ09') === 'ksef_checkupdate') {
        return ksefCheckForUpdate($db);
    }
    return null;
}

/**
 * @brief reactivation + update banners shown on settings tab.
 * @return string HTML
 * @called_by admin/setup.php, admin/setup_auth.php, admin/setup_incoming.php, admin/setup_outgoing.php
 * @calls ksefShowReactivationWarning(), ksefShowUpdateBanner()
 */
function ksefShowAdminBanners()
{
    return ksefShowReactivationWarning() . ksefShowUpdateBanner();
}

/**
 * @brief update-check button.
 * @param string     $selfurl  Host page URL for the button (defaults to PHP_SELF)
 * @param array|null $update   Result of ksefMaybeRunUpdateCheck()
 * @return string HTML
 * @called_by admin/about.php, admin/setup.php
 * @calls ksefRenderDownloadLink()
 */
function ksefRenderSupportBox($selfurl = '', $update = null)
{
    global $langs;
    $langs->load("ksef@ksef");

    if ($selfurl === '') {
        $selfurl = $_SERVER['PHP_SELF'];
    }

    $sep = (strpos($selfurl, '?') !== false) ? '&' : '?';

    $h = '<table class="noborder centpercent">';
    $h .= '<tr class="liste_titre"><th><span class="fa fa-life-ring paddingright"></span>' . $langs->trans('KSEF_NeedHelp') . '</th></tr>';
    $h .= '<tr class="oddeven"><td>';

    // Update check
    $h .= '<p><strong>' . $langs->trans('KSEF_UpdatesTitle') . '</strong></p>';
    $h .= '<a href="' . $selfurl . $sep . 'action=ksef_checkupdate&token=' . newToken() . '" class="button small"><span class="fas fa-sync paddingright"></span>' . $langs->trans('KSEF_CheckForUpdates') . '</a>';

    if (!empty($update)) {
        if ($update['status'] === 'ok' && $update['available']) {
            $h .= '<div class="warning" style="margin-top:10px;"><span class="fas fa-arrow-circle-up paddingright"></span> ';
            // Escape %s args individually
            $h .= $langs->trans('KSEF_UpdateAvailable', dol_escape_htmltag($update['latest']), dol_escape_htmltag($update['current']));
            if (!empty($update['zip_url'])) {
                $h .= ' ' . ksefRenderDownloadLink($update['latest'], $update['zip_url']);
            } else {
                $h .= ' <a href="' . dol_escape_htmltag($update['release_url']) . '" target="_blank" rel="noopener noreferrer">' . $langs->trans('KSEF_Support_Releases') . '</a>';
            }
            $h .= '</div>';
        } elseif ($update['status'] === 'ok') {
            $h .= '<div class="ok" style="margin-top:10px;"><span class="fas fa-check-circle paddingright"></span> ' . $langs->trans('KSEF_UpToDate', $update['current']) . '</div>';
        } else {
            $h .= '<div class="warning" style="margin-top:10px;"><span class="fas fa-exclamation-triangle paddingright"></span> ';
            $h .= $langs->trans('KSEF_UpdateCheckFailed', dol_escape_htmltag($update['message']));
            $h .= ' <a href="https://github.com/InPoint-Automation/Dolibarr-KSeF-Module/releases" target="_blank" rel="noopener noreferrer">' . $langs->trans('KSEF_Support_Releases') . '</a></div>';
        }
    }

    // Help links
    $h .= '<p style="margin-top:15px;"><strong>' . $langs->trans('KSEF_NeedHelp') . '</strong></p>';
    $h .= '<p>' . $langs->trans('KSEF_Support_Intro') . '</p>';
    $h .= '<ul>';
    $h .= '<li><a href="https://github.com/InPoint-Automation/Dolibarr-KSeF-Module/issues" target="_blank" rel="noopener noreferrer"><span class="fab fa-github paddingright"></span>' . $langs->trans('KSEF_Support_Issues') . '</a> - ' . $langs->trans('KSEF_Support_IssuesDesc') . '</li>';
    $h .= '<li><a href="https://www.dolibarr.org/forum/t/ksef-module-for-dolibarr/30788" target="_blank" rel="noopener noreferrer"><span class="fas fa-comments paddingright"></span>' . $langs->trans('KSEF_Support_Forum') . '</a> - ' . $langs->trans('KSEF_Support_ForumDesc') . '</li>';
    $h .= '<li><a href="https://inpointautomation.com/content/ksef-integration-module-for-dolibarr/" target="_blank" rel="noopener noreferrer"><span class="fas fa-book paddingright"></span>' . $langs->trans('KSEF_Support_DocsEN') . '</a></li>';
    $h .= '<li><a href="https://inpointautomation.com/pl/content/modul-ksef-dla-dolibarr/" target="_blank" rel="noopener noreferrer"><span class="fas fa-book paddingright"></span>' . $langs->trans('KSEF_Support_DocsPL') . '</a></li>';
    $h .= '<li><a href="https://github.com/InPoint-Automation/Dolibarr-KSeF-Module/releases" target="_blank" rel="noopener noreferrer"><span class="fas fa-tag paddingright"></span>' . $langs->trans('KSEF_Support_Releases') . '</a></li>';
    $h .= '</ul>';

    $h .= '</td></tr></table>';

    return $h;
}

/**
 * @brief Collects all configuration warnings across all settings tabs
 * @return array of ['message' => string, 'severity' => 'error'|'warning', 'tab' => string]
 */
function ksefGetConfigWarnings()
{
    global $db, $langs;
    $langs->load("ksef@ksef");

    $warnings = array();
    $env = getDolGlobalString('KSEF_ENVIRONMENT', 'TEST');

    // PHP extensions (general tab)
    $missing = array();
    if (!extension_loaded('openssl')) $missing[] = 'OpenSSL';
    if (!extension_loaded('curl')) $missing[] = 'cURL';
    if (!extension_loaded('dom')) $missing[] = 'DOM';
    if (!empty($missing)) {
        $warnings[] = array(
            'message' => '<strong>' . $langs->trans("KSEF_ErrorMessage") . ':</strong> ' . $langs->trans("KSEF_WARNING_MISSING_EXTENSIONS", implode(', ', $missing)),
            'severity' => 'error',
            'tab' => 'general'
        );
    }

    // No NIP configured (general tab)
    if (empty(getDolGlobalString('KSEF_COMPANY_NIP'))) {
        $warnings[] = array(
            'message' => $langs->trans("KSEF_WARNING_NO_NIP"),
            'severity' => 'warning',
            'tab' => 'general'
        );
    }

    // Duplicate field mappings (general tab)
    $configuredFields = array();
    foreach (array('NIP', 'KRS', 'REGON', 'BDO', 'EORI') as $ident) {
        $fv = ksefGetFieldName($ident);
        if (!empty($fv)) {
            $configuredFields[$fv][] = $ident;
        }
    }
    foreach ($configuredFields as $fv => $idents) {
        if (count($idents) > 1) {
            $warnings[] = array(
                'message' => $langs->trans("KSEF_WARNING_DUPLICATE_FIELD", implode(', ', $idents), $fv),
                'severity' => 'warning',
                'tab' => 'general'
            );
        }
    }

    // Translation overrides not applied (general tab)
    $currentOverrides = ksefGetCurrentTranslationOverrides($db);
    if (empty($currentOverrides)) {
        $warnings[] = array(
            'message' => $langs->trans("KSEF_WARNING_NO_TRANS_OVERRIDES"),
            'severity' => 'warning',
            'tab' => 'general'
        );
    }

    // No auth at all for active env (auth tab)
    $hasToken = !empty(getDolGlobalString('KSEF_AUTH_TOKEN_' . $env));
    $hasCertFile = !empty(getDolGlobalString('KSEF_AUTH_CERTIFICATE_' . $env));
    $hasPrivKey = !empty(getDolGlobalString('KSEF_AUTH_PRIVATE_KEY_' . $env));
    $hasKeyPass = !empty(getDolGlobalString('KSEF_AUTH_KEY_PASSWORD_' . $env));
    $hasAuthCert = $hasCertFile && $hasPrivKey && $hasKeyPass;
    $hasPartialCert = ($hasCertFile || $hasPrivKey || $hasKeyPass) && !$hasAuthCert;

    if (!$hasToken && !$hasAuthCert) {
        if ($hasPartialCert) {
            // Some cert components present but incomplete
            $missing = array();
            if (!$hasCertFile) $missing[] = $langs->trans("KSEF_Certificate");
            if (!$hasPrivKey) $missing[] = $langs->trans("KSEF_PrivateKey");
            if (!$hasKeyPass) $missing[] = $langs->trans("KSEF_Password");
            $warnings[] = array(
                'message' => '<strong style="color: #dc3545;">' . $langs->trans("KSEF_WARNING_AUTH_CERT_INCOMPLETE") . '</strong> - ' . $langs->trans("KSEF_WARNING_AUTH_CERT_MISSING_PARTS", implode(', ', $missing)),
                'severity' => 'error',
                'tab' => 'auth'
            );
        } else {
            $warnings[] = array(
                'message' => '<strong style="color: #dc3545;">' . $langs->trans("KSEF_WARNING_NO_AUTH") . '</strong> - ' . $langs->trans("KSEF_WARNING_CONFIGURE_TOKEN_OR_CERT"),
                'severity' => 'error',
                'tab' => 'auth'
            );
        }
    } else {
        $authMethod = getDolGlobalString('KSEF_AUTH_METHOD_' . $env, 'token');

        // Auth method=token but no token (auth tab)
        if ($authMethod === 'token' && !$hasToken) {
            $warnings[] = array(
                'message' => $langs->trans("KSEF_WARNING_NO_TOKEN"),
                'severity' => 'warning',
                'tab' => 'auth'
            );
        }

        // Auth method=certificate but no cert (auth tab)
        if ($authMethod === 'certificate' && !$hasAuthCert) {
            $missing = array();
            if (!$hasCertFile) $missing[] = $langs->trans("KSEF_Certificate");
            if (!$hasPrivKey) $missing[] = $langs->trans("KSEF_PrivateKey");
            if (!$hasKeyPass) $missing[] = $langs->trans("KSEF_Password");
            $warnings[] = array(
                'message' => $langs->trans("KSEF_WARNING_NO_AUTH_CERTIFICATE") . ' - ' . $langs->trans("KSEF_WARNING_AUTH_CERT_MISSING_PARTS", implode(', ', $missing)),
                'severity' => 'warning',
                'tab' => 'auth'
            );
        }
    }

    // Auth certificate expired (auth tab)
    $authCertCheck = ksefCheckAuthCertificate($env);
    if ($authCertCheck['configured'] && !$authCertCheck['valid']) {
        $warnings[] = array(
            'message' => $langs->trans("KSEF_WARNING_AUTH_CERT_EXPIRED"),
            'severity' => 'warning',
            'tab' => 'auth'
        );
    }

    // Offline certificate expired (auth tab)
    $offlineCertCheck = ksefIsOfflineCertificateConfigured($env);
    if ($offlineCertCheck['configured']) {
        $offlineValidTo = getDolGlobalString('KSEF_OFFLINE_CERT_VALID_TO_' . $env, '');
        if (!empty($offlineValidTo) && $offlineValidTo < dol_now()) {
            $warnings[] = array(
                'message' => $langs->trans("KSEF_WARNING_OFFLINE_CERT_EXPIRED"),
                'severity' => 'warning',
                'tab' => 'auth'
            );
        }
    }

    // GUS lookup not enabled
    if (empty(getDolGlobalString('KSEF_GUS_ENABLED'))) {
        $warnings[] = array(
            'message' => $langs->trans("KSEF_GusNotConfiguredWarning"),
            'severity' => 'info',
            'tab' => 'auth'
        );
    }

    return $warnings;
}

/**
 * @brief Renders config warnings as HTML with links
 * @param array  $warnings    Array from ksefGetConfigWarnings()
 * @param string $currentTab  Current tab ID ('general', 'auth', 'outgoing', 'incoming')
 * @return string HTML
 */
function ksefRenderConfigWarnings($warnings, $currentTab = '')
{
    global $langs;
    $langs->load("ksef@ksef");

    if (empty($warnings)) {
        return '';
    }

    $tabUrls = array(
        'general'  => 'setup.php',
        'auth'     => 'setup_auth.php',
        'outgoing' => 'setup_outgoing.php',
        'incoming' => 'setup_incoming.php',
    );
    $tabLabels = array(
        'general'  => $langs->trans('KSEF_Tab_General'),
        'auth'     => $langs->trans('KSEF_Tab_Authentication'),
        'outgoing' => $langs->trans('KSEF_Tab_Outgoing'),
        'incoming' => $langs->trans('KSEF_Tab_Incoming'),
    );

    $html = '<div class="warning" style="margin-bottom: 15px;">';
    foreach ($warnings as $w) {
        $html .= '&bull; ' . $w['message'];
        if (!empty($w['tab']) && $w['tab'] !== $currentTab && isset($tabUrls[$w['tab']])) {
            $url = dol_buildpath('/ksef/admin/' . $tabUrls[$w['tab']], 1);
            $label = $tabLabels[$w['tab']];
            $html .= ' - ' . $langs->trans('KSEF_WarningFixOnTab', $url, $label);
        }
        $html .= '<br>';
    }
    $html .= '</div>';

    return $html;
}

/**
 * @brief Build full correction chain for an invoice
 * @param Facture $invoice Starting invoice (root or any correction in chain)
 * @param DoliDB $db Database handler
 * @param int $maxDepth Safety limit
 * @return Facture[] Chain order [root, correction1, correction2, ...]
 */
function ksefBuildCorrectionChain($invoice, $db, $maxDepth = 20)
{
    // Walk backward to root
    $current = $invoice;
    $backChain = array($current);
    $depth = 0;
    while ($current->type == Facture::TYPE_REPLACEMENT
        && !empty($current->fk_facture_source)
        && $depth < $maxDepth) {
        $parent = new Facture($db);
        if ($parent->fetch((int) $current->fk_facture_source) <= 0) {
            break;
        }
        array_unshift($backChain, $parent);
        $current = $parent;
        $depth++;
    }

    // Walk forward from root
    $chain = array($backChain[0]);
    $current = $backChain[0];
    $depth = 0;
    while ($depth < $maxDepth) {
        $sql_next = "SELECT rowid FROM " . MAIN_DB_PREFIX . "facture"
            . " WHERE fk_facture_source = " . ((int) $current->id)
            . " AND type = " . Facture::TYPE_REPLACEMENT
            . " ORDER BY fk_statut DESC, rowid DESC LIMIT 1";
        $res_next = $db->query($sql_next);
        $nextId = 0;
        if ($res_next && ($obj_next = $db->fetch_object($res_next))) {
            $nextId = (int) $obj_next->rowid;
        }
        if ($nextId <= 0) {
            break;
        }
        $next = new Facture($db);
        if ($next->fetch($nextId) <= 0) {
            break;
        }
        $chain[] = $next;
        $current = $next;
        $depth++;
    }

    return $chain;
}

/**
 * @brief Parse day/month/year date out of contract string
 * @param string $text Raw contract text
 * @return array{date: ?string, nr: string} 'date' as 'Y-m-d' or null, 'nr' as remainder
 * @called_by FA3Builder::buildWarunkiTransakcji()
 */
function ksefParseContractDate($text)
{
    $text = (string) $text;
    if (preg_match('/^\s*(\d{1,2})[.\/-](\d{1,2})[.\/-](\d{2,4})\s*(?:[-–—]\s*)?(.*)$/su', $text, $m)) {
        $day = (int) $m[1];
        $month = (int) $m[2];
        $year = (int) $m[3];
        if (strlen($m[3]) <= 2) {
            $year += 2000;
        }
        if (checkdate($month, $day, $year)) {
            return array(
                'date' => sprintf('%04d-%02d-%02d', $year, $month, $day),
                'nr'   => trim($m[4]),
            );
        }
    }
    return array('date' => null, 'nr' => trim($text));
}
