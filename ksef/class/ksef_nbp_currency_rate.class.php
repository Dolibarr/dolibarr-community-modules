<?php
/* Copyright (C) 2026 InPoint Automation Sp z o.o.
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
 * \file    ksef/class/ksef_nbp_currency_rate.class.php
 * \ingroup ksef
 * \brief   NBP Exchange Rate API Client for KSeF
 */

require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';

class KsefNbpCurrencyRate
{
    private $db;
    public $error = '';
    public $errors = array();
    public $output = '';
    const API_BASE_URL = 'https://api.nbp.pl/api/exchangerates/rates';
    const API_TABLES_URL = 'https://api.nbp.pl/api/exchangerates/tables';
    const LOOKBACK_DAYS = 14;
    const API_TIMEOUT = 10;
    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * @brief D-1 date
     * @param  int    $ts  Unix timestamp (GMT)
     * @return string      Day-before as YYYY-MM-DD in Europe/Warsaw
     */
    protected function warsawDayBefore($ts)
    {
        $dt = new DateTime('@' . (int) $ts);
        $dt->setTimezone(new DateTimeZone('Europe/Warsaw'));
        $dt->modify('-1 day');
        return $dt->format('Y-m-d');
    }

    /**
     * @brief Exact locally cached NBP rate for a currency on the invoice's D-1 business day
     * @param  string $currencyCode  ISO 4217 code
     * @param  string $endDate       The invoice D-1 date YYYY-MM-DD
     * @return array|false           array('date' => 'YYYY-MM-DD', 'rate' => nbp_mid, 'table' => 'CACHE') or false
     * @remarks Reads llx_multicurrency_rate
     */
    protected function getCachedRateForDate($currencyCode, $endDate)
    {
        require_once DOL_DOCUMENT_ROOT . '/multicurrency/class/multicurrency.class.php';
        $fk_multicurrency = MultiCurrency::getIdFromCode($this->db, $currencyCode);
        if ($fk_multicurrency <= 0) {
            return false;
        }

        $target = $endDate;
        $dow = (int) date('N', strtotime($target));
        if ($dow >= 6) {
            $target = date('Y-m-d', strtotime($target . ' -' . ($dow - 5) . ' days'));
        }

        $sql = "SELECT rate FROM " . MAIN_DB_PREFIX . "multicurrency_rate";
        $sql .= " WHERE fk_multicurrency = " . ((int) $fk_multicurrency);
        $sql .= " AND entity IN (" . getEntity('multicurrency') . ")";
        $sql .= " AND rate > 0";
        $sql .= " AND DATE(date_sync) = '" . $this->db->escape($target) . "'";
        $sql .= " ORDER BY rowid DESC LIMIT 1";
        $resql = $this->db->query($sql);
        if (!$resql || $this->db->num_rows($resql) == 0) {
            return false;
        }
        $obj = $this->db->fetch_object($resql);
        $tx = (float) $obj->rate;
        if ($tx <= 0) {
            return false;
        }
        return array('date' => $target, 'rate' => 1 / $tx, 'table' => 'CACHE');
    }

    /**
     * Get exchange rate for last working day before reference date
     * @param string $currencyCode  ISO 4217 currency code
     * @param int    $referenceDate Unix timestamp of date
     * @return array|false          ['date' => 'YYYY-MM-DD', 'rate' => 4.2130, 'table' => 'A'] or false
     */
    public function getRateForDate($currencyCode, $referenceDate, $preferCache = false)
    {
        $this->error = '';
        $this->errors = array();

        if (empty($currencyCode)) {
            $this->error = 'Currency code is required';
            return false;
        }

        if ($currencyCode === 'PLN') {
            $this->error = 'PLN does not require exchange rate';
            return false;
        }

        $currencyCode = strtoupper(trim($currencyCode));
        $endDate = $this->warsawDayBefore($referenceDate);
        $startDate = date('Y-m-d', strtotime('-' . self::LOOKBACK_DAYS . ' days', strtotime($endDate)));

        if ($preferCache) {
            $cached = $this->getCachedRateForDate($currencyCode, $endDate);
            if ($cached !== false) {
                dol_syslog("KsefNbpCurrencyRate::getRateForDate cache hit $currencyCode = " . $cached['rate'] . " on " . $cached['date'], LOG_INFO);
                return $cached;
            }
        }

        dol_syslog("KsefNbpCurrencyRate::getRateForDate currency=$currencyCode reference=" . date('Y-m-d', $referenceDate) . " range=$startDate/$endDate", LOG_DEBUG);

        // Try Table A
        $result = $this->fetchFromAPI($currencyCode, $startDate, $endDate, 'a');

        if ($result === false && strpos($this->error, '404') !== false) {
            // Currency not in it try Table B
            dol_syslog("KsefNbpCurrencyRate::getRateForDate Table A returned 404, trying Table B", LOG_DEBUG);
            $this->error = '';
            $result = $this->fetchFromAPI($currencyCode, $startDate, $endDate, 'b');
        }

        if ($result === false) {
            if (empty($this->error)) {
                $this->error = sprintf('No NBP rate found for %s in date range %s to %s', $currencyCode, $startDate, $endDate);
            }
            dol_syslog("KsefNbpCurrencyRate::getRateForDate FAILED: " . $this->error, LOG_WARNING);
            return false;
        }

        dol_syslog("KsefNbpCurrencyRate::getRateForDate SUCCESS: " . $currencyCode . " = " . $result['rate'] . " from " . $result['date'], LOG_INFO);
        return $result;
    }

    /**
     * @brief Fetch exchange rate from the NBP API
     * @param string $currencyCode Currency code (e.g., "EUR")
     * @param string $startDate    Start date YYYY-MM-DD
     * @param string $endDate      End date YYYY-MM-DD
     * @param string $table        Table type: 'a' or 'b'
     * @return array|false         ['date' => 'YYYY-MM-DD', 'rate' => 4.2130, 'table' => 'A'] or false
     */
    protected function fetchFromAPI($currencyCode, $startDate, $endDate, $table = 'a')
    {
        // /api/exchangerates/rates/{table}/{code}/{startDate}/{endDate}/
        $url = self::API_BASE_URL . '/' . $table . '/' . strtolower($currencyCode) . '/' . $startDate . '/' . $endDate . '/?format=json';

        dol_syslog("KsefNbpCurrencyRate::fetchFromAPI URL: " . $url, LOG_DEBUG);

        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::API_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER => array(
                'Accept: application/json',
                'User-Agent: Dolibarr-KSeF-Module/1.0'
            ),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => true,
        ));

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            $this->error = 'NBP API connection error: ' . $curlError;
            return false;
        }

        if ($httpCode == 404) {
            $this->error = '404 - Currency not found in table ' . strtoupper($table) . ' or no rates in date range';
            return false;
        }

        if ($httpCode != 200) {
            $this->error = 'NBP API error: HTTP ' . $httpCode;
            return false;
        }

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->error = 'NBP API error: Invalid JSON response';
            return false;
        }

        if (empty($data['rates']) || !is_array($data['rates'])) {
            $this->error = 'NBP API error: No rates in response';
            return false;
        }

        // Get the LAST rate in the array ... rates are not published on non-working days
        $lastRate = end($data['rates']);

        if (empty($lastRate['effectiveDate']) || !isset($lastRate['mid'])) {
            $this->error = 'NBP API error: Invalid rate data structure';
            return false;
        }

        return array(
            'date' => $lastRate['effectiveDate'],
            'rate' => (float) $lastRate['mid'],
            'table' => strtoupper($table),
            'currency' => strtoupper($currencyCode),
        );
    }

    /**
     * @brief Fetch a whole NBP table (all currencies) for the last publication in a date range
     * @param string $table     Table type: 'a' or 'b'
     * @param string $startDate Start date YYYY-MM-DD
     * @param string $endDate   End date YYYY-MM-DD
     * @return array|false      array(CODE => array('rate' => mid, 'date' => 'YYYY-MM-DD')) or false
     * @remarks One request returns every currency in the table; caller filters to its dictionary.
     */
    protected function fetchTableRates($table, $startDate, $endDate)
    {
        // /api/exchangerates/tables/{table}/{startDate}/{endDate}/
        $url = self::API_TABLES_URL . '/' . $table . '/' . $startDate . '/' . $endDate . '/?format=json';

        dol_syslog("KsefNbpCurrencyRate::fetchTableRates URL: " . $url, LOG_DEBUG);

        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::API_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER => array(
                'Accept: application/json',
                'User-Agent: Dolibarr-KSeF-Module/1.0'
            ),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => true,
        ));

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            $this->error = 'NBP API connection error: ' . $curlError;
            return false;
        }

        if ($httpCode == 404) {
            $this->error = '404 - No table ' . strtoupper($table) . ' published in date range';
            return false;
        }

        if ($httpCode != 200) {
            $this->error = 'NBP API error: HTTP ' . $httpCode;
            return false;
        }

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE || empty($data) || !is_array($data)) {
            $this->error = 'NBP API error: Invalid JSON response';
            return false;
        }

        $lastTable = end($data);
        if (empty($lastTable['effectiveDate']) || empty($lastTable['rates']) || !is_array($lastTable['rates'])) {
            $this->error = 'NBP API error: Invalid table data structure';
            return false;
        }

        $rates = array();
        foreach ($lastTable['rates'] as $row) {
            if (empty($row['code']) || !isset($row['mid'])) {
                continue;
            }
            $rates[strtoupper($row['code'])] = array(
                'rate' => (float) $row['mid'],
                'date' => $lastTable['effectiveDate'],
            );
        }

        return $rates;
    }

    /**
     * Fetch NBP rate and store it on invoice
     * @param Facture $invoice Invoice object (modified in place)
     * @param User    $user    User performing the action
     * @return array|false     ['date' => ..., 'rate' => ...] on success, false on error
     */
    public function fetchAndStoreForInvoice(&$invoice, $user, $preferCache = false)
    {
        global $conf;

        $this->error = '';
        $this->errors = array();
        $invoiceCurrency = $this->getInvoiceCurrency($invoice);

        if ($invoiceCurrency === 'PLN') {
            $this->error = 'Invoice is in PLN, no NBP rate needed';
            return false;
        }

        $referenceDate = !empty($invoice->date_livraison) ? $invoice->date_livraison : $invoice->date;

        if (empty($referenceDate)) {
            $this->error = 'Invoice date is required to fetch NBP rate';
            return false;
        }

        $rateData = $this->getRateForDate($invoiceCurrency, $referenceDate, $preferCache);

        if ($rateData === false) {
            return false;
        }

        // NBP rate is given as 1 EUR = X.XX PLN
        $nbpRate = (float) $rateData['rate'];
        $dolibarrTx = 1 / $nbpRate;
        $txForSql = number_format($dolibarrTx, 10, '.', '');

        // keep_base (default): Keep PLN amounts, recalculate EUR amounts
        // keep_foreign: Keep EUR amounts, recalculate PLN amounts
        $rateMode = getDolGlobalString('KSEF_NBP_RATE_MODE', 'keep_base');

        dol_syslog("KsefNbpCurrencyRate::fetchAndStoreForInvoice - NBP rate: $nbpRate, Dolibarr tx: $dolibarrTx, Mode: $rateMode", LOG_DEBUG);

        $this->db->begin();
        $error = 0;

        if ($rateMode === 'keep_foreign') {
            $sql = "UPDATE " . MAIN_DB_PREFIX . "facture SET";
            $sql .= " multicurrency_tx = " . $txForSql;
            $sql .= ", total_ht = ROUND(multicurrency_total_ht / " . $txForSql . ", 2)";
            $sql .= ", total_tva = ROUND(multicurrency_total_tva / " . $txForSql . ", 2)";
            $sql .= ", total_ttc = ROUND(multicurrency_total_ttc / " . $txForSql . ", 2)";
            $sql .= " WHERE rowid = " . ((int) $invoice->id);
        } else {
            $sql = "UPDATE " . MAIN_DB_PREFIX . "facture SET";
            $sql .= " multicurrency_tx = " . $txForSql;
            $sql .= ", multicurrency_total_ht = ROUND(total_ht * " . $txForSql . ", 2)";
            $sql .= ", multicurrency_total_tva = ROUND(total_tva * " . $txForSql . ", 2)";
            $sql .= ", multicurrency_total_ttc = ROUND(total_ttc * " . $txForSql . ", 2)";
            $sql .= " WHERE rowid = " . ((int) $invoice->id);
        }

        dol_syslog("KsefNbpCurrencyRate::fetchAndStoreForInvoice SQL header: " . $sql, LOG_DEBUG);
        $resql = $this->db->query($sql);
        if (!$resql) {
            $error++;
            $this->error = 'Failed to update invoice header: ' . $this->db->lasterror();
            dol_syslog("KsefNbpCurrencyRate::fetchAndStoreForInvoice ERROR: " . $this->error, LOG_ERR);
        }

        if (!$error) {
            if ($rateMode === 'keep_foreign') {
                $sql = "UPDATE " . MAIN_DB_PREFIX . "facturedet SET";
                $sql .= " subprice = ROUND(multicurrency_subprice / " . $txForSql . ", 5)";
                $sql .= ", total_ht = ROUND(multicurrency_total_ht / " . $txForSql . ", 2)";
                $sql .= ", total_tva = ROUND(multicurrency_total_tva / " . $txForSql . ", 2)";
                $sql .= ", total_ttc = ROUND(multicurrency_total_ttc / " . $txForSql . ", 2)";
                $sql .= " WHERE fk_facture = " . ((int) $invoice->id);
            } else {
                $sql = "UPDATE " . MAIN_DB_PREFIX . "facturedet SET";
                $sql .= " multicurrency_subprice = ROUND(subprice * " . $txForSql . ", 5)";
                $sql .= ", multicurrency_total_ht = ROUND(total_ht * " . $txForSql . ", 2)";
                $sql .= ", multicurrency_total_tva = ROUND(total_tva * " . $txForSql . ", 2)";
                $sql .= ", multicurrency_total_ttc = ROUND(total_ttc * " . $txForSql . ", 2)";
                $sql .= " WHERE fk_facture = " . ((int) $invoice->id);
            }

            dol_syslog("KsefNbpCurrencyRate::fetchAndStoreForInvoice SQL lines: " . $sql, LOG_DEBUG);
            $resql = $this->db->query($sql);
            if (!$resql) {
                $error++;
                $this->error = 'Failed to update invoice lines: ' . $this->db->lasterror();
                dol_syslog("KsefNbpCurrencyRate::fetchAndStoreForInvoice ERROR: " . $this->error, LOG_ERR);
            }
        }

        if (!$error) {
            if (!isset($invoice->array_options) || !is_array($invoice->array_options)) {
                $invoice->array_options = array();
            }
            $invoice->fetch_optionals();
            $invoice->array_options['options_ksef_kurs_data'] = strtotime($rateData['date']);

            $result = $invoice->insertExtraFields();
            if ($result < 0) {
                $error++;
                $this->error = 'Failed to save extrafields: ' . $invoice->error;
                dol_syslog("KsefNbpCurrencyRate::fetchAndStoreForInvoice ERROR: " . $this->error, LOG_ERR);
            }
        }

        if ($error) {
            $this->db->rollback();
            return false;
        }

        $this->db->commit();
        $invoice->fetch($invoice->id);
        $invoice->fetch_lines();

        // Since we have the data, add it to multicurrency module rate list, it cannot hurt
        $this->addRateToCache($invoiceCurrency, $dolibarrTx, $rateData['date'], $user);

        dol_syslog("KsefNbpCurrencyRate::fetchAndStoreForInvoice SUCCESS: Invoice " . $invoice->ref .
            " NBP rate=" . $nbpRate . " PLN/" . $invoiceCurrency .
            " (stored tx=" . $dolibarrTx . ") mode=" . $rateMode . " date=" . $rateData['date'], LOG_INFO);

        return $rateData;
    }

    /**
     * @brief Add rate to multicurrency rate cache table
     * @param string $currencyCode Currency code (e.g., EUR)
     * @param float  $rate Rate in Dolibarr format (foreign/base)
     * @param string $date Rate date YYYY-MM-DD
     * @param User   $user User object
     * @param string $action By-ref: set to 'created', 'unchanged' or 'failed'
     * @return bool True on success
     */
    protected function addRateToCache($currencyCode, $rate, $date, $user, &$action = null)
    {
        global $conf;

        require_once DOL_DOCUMENT_ROOT . '/multicurrency/class/multicurrency.class.php';
        $fk_multicurrency = MultiCurrency::getIdFromCode($this->db, $currencyCode);

        if ($fk_multicurrency <= 0) {
            dol_syslog("KsefNbpCurrencyRate::addRateToCache Currency $currencyCode not found in multicurrency table", LOG_DEBUG);
            $action = 'failed';
            return false;
        }
        $sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "multicurrency_rate";
        $sql .= " WHERE fk_multicurrency = " . ((int) $fk_multicurrency);
        $sql .= " AND DATE(date_sync) = '" . $this->db->escape($date) . "'";
        $sql .= " AND entity = " . ((int) $conf->entity);

        $resql = $this->db->query($sql);
        if ($resql && $this->db->num_rows($resql) > 0) {
            dol_syslog("KsefNbpCurrencyRate::addRateToCache Rate for $currencyCode on $date already exists", LOG_DEBUG);
            $action = 'unchanged';
            return true;
        }

        $currencyRate = new CurrencyRate($this->db);
        $currencyRate->rate = (float) $rate;
        $currencyRate->date_sync = strtotime($date);
        $currencyRate->entity = $conf->entity;

        $result = $currencyRate->create($user, $fk_multicurrency, 1);

        if ($result > 0) {
            dol_syslog("KsefNbpCurrencyRate::addRateToCache Added rate $rate for $currencyCode on $date (id=$result)", LOG_INFO);
            $action = 'created';
            return true;
        }

        dol_syslog("KsefNbpCurrencyRate::addRateToCache Failed to add rate: " . implode(',', $currencyRate->errors), LOG_WARNING);
        $action = 'failed';
        return false;
    }

    /**
     * @brief Cron: store the D-1 NBP rate
     * @return int 0 on success (Dolibarr cron convention), <0 on hard error
     */
    public function cronSyncMulticurrencyRates()
    {
        global $conf, $user;

        $this->error = '';
        $this->output = '';

        if (!isModEnabled('multicurrency')) {
            $this->output = 'Multicurrency module disabled, nothing to sync';
            return 0;
        }

        $baseCurrency = strtoupper(empty($conf->currency) ? '' : $conf->currency);

        if ($baseCurrency !== 'PLN') {
            $this->output = 'Base currency is ' . ($baseCurrency ?: 'unset') . ', not PLN; NBP D-1 sync only applies to a PLN base';
            return 0;
        }

        $sql = "SELECT code FROM " . MAIN_DB_PREFIX . "multicurrency";
        $sql .= " WHERE entity = " . ((int) $conf->entity);
        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->error = $this->db->lasterror();
            $this->output = 'Failed to read multicurrency dictionary: ' . $this->error;
            return -1;
        }

        $endDate = $this->warsawDayBefore(dol_now());
        $startDate = date('Y-m-d', strtotime('-' . self::LOOKBACK_DAYS . ' days', strtotime($endDate)));

        $tableRates = array();
        $tableErrors = array();
        foreach (array('a', 'b') as $t) {
            $this->error = '';
            $res = $this->fetchTableRates($t, $startDate, $endDate);
            if ($res === false) {
                $tableErrors[strtoupper($t)] = $this->error;
                continue;
            }
            foreach ($res as $code => $info) {
                if (!isset($tableRates[$code])) {
                    $tableRates[$code] = $info;
                }
            }
        }

        if (empty($tableRates)) {
            $this->db->free($resql);
            $transient = array();
            foreach ($tableErrors as $tbl => $e) {
                if (strpos($e, '404') === false) {
                    $transient[] = $tbl . ': ' . $e;
                }
            }
            if (!empty($transient)) {
                $this->error = 'NBP table fetch failed: ' . implode(' | ', $transient);
                $this->output = $this->error;
                return -1;
            }
            $this->output = 'NBP rate sync (D-1): no tables published in range ' . $startDate . ' to ' . $endDate;
            dol_syslog("KsefNbpCurrencyRate::cronSyncMulticurrencyRates " . $this->output, LOG_INFO);
            return 0;
        }

        $subset = null;
        $subsetRaw = getDolGlobalString('KSEF_NBP_SYNC_CURRENCIES', '');
        if ($subsetRaw !== '') {
            $subset = array_filter(array_map('trim', explode(',', strtoupper($subsetRaw))));
        }

        $updated = 0;
        $skipped = 0;
        $failed = 0;
        $unpublished = 0;
        $failCodes = array();
        $unpublishedCodes = array();

        while ($obj = $this->db->fetch_object($resql)) {
            $code = strtoupper(trim($obj->code));
            if ($code === '' || $code === 'PLN' || $code === $baseCurrency) {
                $skipped++;
                continue;
            }
            if ($subset !== null && !in_array($code, $subset)) {
                $skipped++;
                continue;
            }

            if (empty($tableRates[$code]) || (float) $tableRates[$code]['rate'] <= 0) {
                $unpublished++;
                $unpublishedCodes[] = $code;
                continue;
            }

            $dolibarrTx = 1 / (float) $tableRates[$code]['rate'];
            $action = null;
            $this->addRateToCache($code, $dolibarrTx, $tableRates[$code]['date'], $user, $action);
            if ($action === 'created') {
                $updated++;
            } elseif ($action === 'unchanged') {
                $skipped++;
            } else {
                $failed++;
                $failCodes[] = $code;
            }
        }
        $this->db->free($resql);

        $this->output = "NBP rate sync (D-1): updated $updated, skipped $skipped, unpublished $unpublished, failed $failed";
        if (!empty($failCodes)) {
            $this->output .= ' [write failed: ' . implode(',', $failCodes) . ']';
        }
        if (!empty($unpublishedCodes)) {
            $this->output .= ' [not quoted: ' . implode(',', $unpublishedCodes) . ']';
        }
        dol_syslog("KsefNbpCurrencyRate::cronSyncMulticurrencyRates " . $this->output, LOG_INFO);

        if ($updated === 0 && $skipped === 0 && $failed > 0) {
            $this->error = 'NBP rate sync failed to write all ' . $failed . ' currency(ies): ' . implode(',', $failCodes);
            return -1;
        }

        return 0;
    }

    /**
     * Get effective currency for an invoice
     * @param Facture $invoice Invoice object
     * @return string Currency code
     */
    public function getInvoiceCurrency($invoice)
    {
        if (!empty($invoice->multicurrency_code)) {
            return $invoice->multicurrency_code;
        }

        global $conf;
        return !empty($conf->currency) ? $conf->currency : 'EUR';
    }

    /**
     * Check if invoice needs NBP rate for KSeF
     * @param Facture $invoice Invoice object
     * @return bool True if NBP rate is needed
     */
    public function invoiceNeedsNBPRate($invoice)
    {
        $currency = $this->getInvoiceCurrency($invoice);
        return ($currency !== 'PLN');
    }

    /**
     * Check if invoice has NBP rate data set
     * @param Facture $invoice Invoice object
     * @return bool True if rate data is present
     */
    public function invoiceHasNBPRate($invoice)
    {
        if (!$this->invoiceNeedsNBPRate($invoice)) {
            return true;
        }

        if (!isset($invoice->array_options)) {
            $invoice->fetch_optionals();
        }

        $hasDate = !empty($invoice->array_options['options_ksef_kurs_data']);
        $hasRate = !empty($invoice->multicurrency_tx) && $invoice->multicurrency_tx > 0;

        return ($hasDate && $hasRate);
    }

    /**
     * Get formatted rate info for display
     * @param Facture $invoice Invoice object
     * @return array [rate, date, formatted] or empty
     */
    public function getFormattedRateInfo($invoice)
    {
        if (!$this->invoiceNeedsNBPRate($invoice)) {
            return array();
        }

        if (!isset($invoice->array_options)) {
            $invoice->fetch_optionals();
        }

        $rateDate = $invoice->array_options['options_ksef_kurs_data'] ?? null;
        $rate = $invoice->multicurrency_tx ?? 0;

        if (empty($rateDate) || $rate <= 0) {
            return array();
        }

        $dateFormatted = is_numeric($rateDate) ? date('Y-m-d', $rateDate) : $rateDate;
        $rateFormatted = rtrim(rtrim(number_format($rate, 6, '.', ''), '0'), '.');
        $dotPos = strpos($rateFormatted, '.');
        if ($dotPos !== false) {
            $decimals = strlen($rateFormatted) - $dotPos - 1;
            if ($decimals < 4) {
                $rateFormatted = number_format($rate, 4, '.', '');
            }
        }

        return array(
            'rate' => $rateFormatted,
            'date' => $dateFormatted,
            'formatted' => $rateFormatted . ' z dnia ' . $dateFormatted,
            'currency' => $this->getInvoiceCurrency($invoice),
        );
    }
}