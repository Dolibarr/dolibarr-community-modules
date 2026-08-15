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
 * \file    ksef/class/ksef_incoming.class.php
 * \ingroup ksef
 * \brief   KSeF Incoming Invoice DAO
 */

require_once DOL_DOCUMENT_ROOT . '/core/class/commonobject.class.php';
dol_include_once('/ksef/class/fa3_parser.class.php');

class KsefIncoming extends CommonObject
{
    public $element = 'ksef_incoming';
    public $table_element = 'ksef_incoming';

    // Database fields
    public $rowid;
    public $ksef_number;
    public $seller_nip;
    public $seller_vat_id;
    public $seller_name;
    public $seller_country;
    public $seller_address;
    public $buyer_nip;
    public $buyer_name;
    public $invoice_number;
    public $invoice_type;
    public $invoice_date;
    public $sale_date;
    public $currency;
    public $total_net;
    public $total_vat;
    public $total_gross;
    public $vat_summary;
    public $line_items;
    public $payment_due_date;
    public $payment_method;
    public $bank_account;
    public $payment_status;
    public $payment_date;
    public $corrected_ksef_number;
    public $corrected_invoice_number;
    public $corrected_invoice_date;
    public $correction_data;
    public $fa3_xml;
    public $fa3_creation_date;
    public $fa3_system_info;
    public $fetch_date;
    public $environment;
    public $fk_facture_fourn;
    public $fk_credit_note;
    public $import_status;
    public $import_date;
    public $import_error;
    public $entity;
    public $picto = 'supplier_invoice';

    // Status
    const STATUS_NEW = 'NEW';
    const STATUS_IMPORTED = 'IMPORTED';
    const STATUS_ERROR = 'ERROR';

    public function __construct($db)
    {
        $this->db = $db;
        $this->import_status = self::STATUS_NEW;
    }


    /**
     * @brief Safe strtotime
     * @param string $dateStr Date string
     * @return int|null Unix timestamp or null
     */
    private static function safeStrtotime($dateStr)
    {
        if (empty($dateStr)) return null;
        $ts = strtotime($dateStr);
        if ($ts === false || $ts < 0 || $ts > 2147483647) {
            dol_syslog("KsefIncoming::safeStrtotime: out of range or invalid date '{$dateStr}' (ts={$ts})", LOG_WARNING);
            return null;
        }
        return $ts;
    }


    /**
     * @brief Create new incoming invoice record
     * @param $user User object
     * @param $notrigger Skip triggers flag
     * @return int Record ID or negative on error
     * @called_by createFromParsed()
     */
    public function create($user, $notrigger = 0, $notransaction = false)
    {
        global $conf;

        $error = 0;

        $this->ksef_number = trim($this->ksef_number);
        if (empty($this->fetch_date)) {
            $this->fetch_date = dol_now();
        }
        if (empty($this->entity)) {
            $this->entity = $conf->entity;
        }

        if (!$notransaction) {
            $this->db->begin();
        }

        $sql = "INSERT INTO " . MAIN_DB_PREFIX . $this->table_element . " (";
        $sql .= "ksef_number,";
        $sql .= "seller_nip,";
        $sql .= "seller_vat_id,";
        $sql .= "seller_name,";
        $sql .= "seller_country,";
        $sql .= "seller_address,";
        $sql .= "buyer_nip,";
        $sql .= "buyer_name,";
        $sql .= "invoice_number,";
        $sql .= "invoice_type,";
        $sql .= "invoice_date,";
        $sql .= "sale_date,";
        $sql .= "currency,";
        $sql .= "total_net,";
        $sql .= "total_vat,";
        $sql .= "total_gross,";
        $sql .= "vat_summary,";
        $sql .= "line_items,";
        $sql .= "payment_due_date,";
        $sql .= "payment_method,";
        $sql .= "bank_account,";
        $sql .= "payment_status,";
        $sql .= "payment_date,";
        $sql .= "corrected_ksef_number,";
        $sql .= "corrected_invoice_number,";
        $sql .= "corrected_invoice_date,";
        $sql .= "correction_data,";
        $sql .= "fa3_xml,";
        $sql .= "fa3_creation_date,";
        $sql .= "fa3_system_info,";
        $sql .= "fetch_date,";
        $sql .= "environment,";
        $sql .= "import_status,";
        $sql .= "entity";
        $sql .= ")";
        $sql .= " VALUES (";
        $sql .= " '" . $this->db->escape($this->ksef_number) . "',";
        $sql .= " " . ($this->seller_nip ? "'" . $this->db->escape($this->seller_nip) . "'" : "NULL") . ",";
        $sql .= " " . ($this->seller_vat_id ? "'" . $this->db->escape($this->seller_vat_id) . "'" : "NULL") . ",";
        $sql .= " " . ($this->seller_name ? "'" . $this->db->escape($this->seller_name) . "'" : "NULL") . ",";
        $sql .= " " . ($this->seller_country ? "'" . $this->db->escape($this->seller_country) . "'" : "'PL'") . ",";
        $sql .= " " . ($this->seller_address ? "'" . $this->db->escape($this->seller_address) . "'" : "NULL") . ",";
        $sql .= " " . ($this->buyer_nip ? "'" . $this->db->escape($this->buyer_nip) . "'" : "NULL") . ",";
        $sql .= " " . ($this->buyer_name ? "'" . $this->db->escape($this->buyer_name) . "'" : "NULL") . ",";
        $sql .= " " . ($this->invoice_number ? "'" . $this->db->escape($this->invoice_number) . "'" : "NULL") . ",";
        $sql .= " " . ($this->invoice_type ? "'" . $this->db->escape($this->invoice_type) . "'" : "'VAT'") . ",";
        $sql .= " " . ($this->invoice_date ? (int)$this->invoice_date : "NULL") . ",";
        $sql .= " " . ($this->sale_date ? (int)$this->sale_date : "NULL") . ",";
        $sql .= " " . ($this->currency ? "'" . $this->db->escape($this->currency) . "'" : "'PLN'") . ",";
        $sql .= " " . ($this->total_net !== null ? (float)$this->total_net : "NULL") . ",";
        $sql .= " " . ($this->total_vat !== null ? (float)$this->total_vat : "NULL") . ",";
        $sql .= " " . ($this->total_gross !== null ? (float)$this->total_gross : "NULL") . ",";
        $sql .= " " . ($this->vat_summary ? "'" . $this->db->escape($this->vat_summary) . "'" : "NULL") . ",";
        $sql .= " " . ($this->line_items ? "'" . $this->db->escape($this->line_items) . "'" : "NULL") . ",";
        $sql .= " " . ($this->payment_due_date ? (int)$this->payment_due_date : "NULL") . ",";
        $sql .= " " . ($this->payment_method ? "'" . $this->db->escape($this->payment_method) . "'" : "NULL") . ",";
        $sql .= " " . ($this->bank_account ? "'" . $this->db->escape($this->bank_account) . "'" : "NULL") . ",";
        $sql .= " " . ($this->payment_status ? "'" . $this->db->escape($this->payment_status) . "'" : "NULL") . ",";
        $sql .= " " . ($this->payment_date ? (int)$this->payment_date : "NULL") . ",";
        $sql .= " " . ($this->corrected_ksef_number ? "'" . $this->db->escape($this->corrected_ksef_number) . "'" : "NULL") . ",";
        $sql .= " " . ($this->corrected_invoice_number ? "'" . $this->db->escape($this->corrected_invoice_number) . "'" : "NULL") . ",";
        $sql .= " " . ($this->corrected_invoice_date ? (int)$this->corrected_invoice_date : "NULL") . ",";
        $sql .= " " . ($this->correction_data ? "'" . $this->db->escape($this->correction_data) . "'" : "NULL") . ",";
        $sql .= " " . ($this->fa3_xml ? "'" . $this->db->escape($this->fa3_xml) . "'" : "NULL") . ",";
        $sql .= " " . ($this->fa3_creation_date ? (int)$this->fa3_creation_date : "NULL") . ",";
        $sql .= " " . ($this->fa3_system_info ? "'" . $this->db->escape($this->fa3_system_info) . "'" : "NULL") . ",";
        $sql .= " " . (int)$this->fetch_date . ",";
        $sql .= " " . ($this->environment ? "'" . $this->db->escape($this->environment) . "'" : "'TEST'") . ",";
        $sql .= " '" . $this->db->escape($this->import_status ?: self::STATUS_NEW) . "',";
        $sql .= " " . (int)$this->entity;
        $sql .= ")";

        dol_syslog("KsefIncoming::create", LOG_DEBUG);
        $resql = $this->db->query($sql);

        if (!$resql) {
            $error++;
            $this->errors[] = "Error " . $this->db->lasterror();
            dol_syslog("KsefIncoming::create " . $this->db->lasterror(), LOG_ERR);
        }

        if (!$error) {
            $this->rowid = $this->db->last_insert_id(MAIN_DB_PREFIX . $this->table_element);
            $this->id = $this->rowid;

            if (!$notrigger) {
                $result = $this->call_trigger('KSEF_INCOMING_CREATE', $user);
                if ($result < 0) {
                    $error++;
                }
            }
        }

        if ($error) {
            foreach ($this->errors as $errmsg) {
                dol_syslog("KsefIncoming::create " . $errmsg, LOG_ERR);
                $this->error .= ($this->error ? ', ' . $errmsg : $errmsg);
            }
            if (!$notransaction) {
                $this->db->rollback();
            }
            return -1 * $error;
        } else {
            if (!$notransaction) {
                $this->db->commit();
            }
            return $this->rowid;
        }
    }


    /**
     * @brief Fetch record by ID
     * @param $id Record ID
     * @param $ksef_number fetch by KSeF number
     * @return int Positive if success, negative if error, 0 if not found
     * @called_by fetchAll(), incoming_card.php
     */
    public function fetch($id, $ksef_number = '')
    {
        $sql = "SELECT * FROM " . MAIN_DB_PREFIX . $this->table_element . " WHERE ";

        if ($id > 0) {
            $sql .= "rowid = " . (int)$id;
        } elseif (!empty($ksef_number)) {
            $sql .= "ksef_number = '" . $this->db->escape($ksef_number) . "'";
        } else {
            return -1;
        }
        $sql .= " AND entity IN (" . getEntity($this->element) . ")";

        dol_syslog("KsefIncoming::fetch", LOG_DEBUG);
        $resql = $this->db->query($sql);

        if ($resql) {
            if ($this->db->num_rows($resql) > 0) {
                $obj = $this->db->fetch_object($resql);

                $this->rowid = $obj->rowid;
                $this->id = $obj->rowid;
                $this->ksef_number = $obj->ksef_number;
                $this->seller_nip = $obj->seller_nip;
                $this->seller_vat_id = $obj->seller_vat_id ?? null;
                $this->seller_name = $obj->seller_name;
                $this->seller_country = $obj->seller_country;
                $this->seller_address = $obj->seller_address;
                $this->buyer_nip = $obj->buyer_nip;
                $this->buyer_name = $obj->buyer_name;
                $this->invoice_number = $obj->invoice_number;
                $this->invoice_type = $obj->invoice_type;
                $this->invoice_date = $obj->invoice_date;
                $this->sale_date = $obj->sale_date;
                $this->currency = $obj->currency;
                $this->total_net = $obj->total_net;
                $this->total_vat = $obj->total_vat;
                $this->total_gross = $obj->total_gross;
                $this->vat_summary = $obj->vat_summary;
                $this->line_items = $obj->line_items;
                $this->payment_due_date = $obj->payment_due_date;
                $this->payment_method = $obj->payment_method;
                $this->bank_account = $obj->bank_account;
                $this->payment_status = $obj->payment_status ?? null;
                $this->payment_date = $obj->payment_date ?? null;
                $this->corrected_ksef_number = $obj->corrected_ksef_number;
                $this->corrected_invoice_number = $obj->corrected_invoice_number;
                $this->corrected_invoice_date = $obj->corrected_invoice_date;
                $this->correction_data = $obj->correction_data;
                $this->fa3_xml = $obj->fa3_xml;
                $this->fa3_creation_date = $obj->fa3_creation_date;
                $this->fa3_system_info = $obj->fa3_system_info;
                $this->fetch_date = $obj->fetch_date;
                $this->environment = $obj->environment;
                $this->fk_facture_fourn = $obj->fk_facture_fourn;
                $this->fk_credit_note = $obj->fk_credit_note ?? null;
                $this->import_status = $obj->import_status;
                $this->import_date = $obj->import_date;
                $this->import_error = $obj->import_error;
                $this->entity = $obj->entity;

                $this->db->free($resql);
                return 1;
            } else {
                $this->db->free($resql);
                return 0;
            }
        } else {
            $this->error = "Error " . $this->db->lasterror();
            return -1;
        }
    }


    /**
     * @brief Update record
     * @param $user User object
     * @param $notrigger Skip triggers flag
     * @return int Positive if success, negative if error
     * @called_by incoming_card.php import action
     */
    public function update($user, $notrigger = 0)
    {
        $error = 0;

        $this->db->begin();

        $sql = "UPDATE " . MAIN_DB_PREFIX . $this->table_element . " SET ";
        $sql .= "import_status = '" . $this->db->escape($this->import_status) . "',";
        $sql .= "import_date = " . ($this->import_date ? (int)$this->import_date : "NULL") . ",";
        $sql .= "import_error = " . ($this->import_error ? "'" . $this->db->escape($this->import_error) . "'" : "NULL") . ",";
        $sql .= "fk_facture_fourn = " . ($this->fk_facture_fourn ? (int)$this->fk_facture_fourn : "NULL") . ",";
        $sql .= "fk_credit_note = " . ($this->fk_credit_note ? (int)$this->fk_credit_note : "NULL");
        $sql .= " WHERE rowid = " . (int)$this->rowid;

        dol_syslog("KsefIncoming::update", LOG_DEBUG);
        $resql = $this->db->query($sql);

        if (!$resql) {
            $error++;
            $this->errors[] = "Error " . $this->db->lasterror();
        }

        if (!$error && !$notrigger) {
            $result = $this->call_trigger('KSEF_INCOMING_MODIFY', $user);
            if ($result < 0) {
                $error++;
            }
        }

        if ($error) {
            $this->db->rollback();
            return -1 * $error;
        } else {
            $this->db->commit();
            return 1;
        }
    }


    /**
     * @brief Delete record
     * @param $user User object
     * @param $notrigger Skip triggers flag
     * @return int Positive if success, negative if error
     * @called_by incoming_card.php delete action
     */
    public function delete($user, $notrigger = 0)
    {
        $error = 0;

        $this->db->begin();

        if (!$notrigger) {
            $result = $this->call_trigger('KSEF_INCOMING_DELETE', $user);
            if ($result < 0) {
                $error++;
            }
        }

        if (!$error) {
            $sql = "DELETE FROM " . MAIN_DB_PREFIX . $this->table_element;
            $sql .= " WHERE rowid = " . (int)$this->rowid;

            dol_syslog("KsefIncoming::delete", LOG_DEBUG);
            $resql = $this->db->query($sql);

            if (!$resql) {
                $error++;
                $this->errors[] = "Error " . $this->db->lasterror();
            }
        }

        if ($error) {
            $this->db->rollback();
            return -1 * $error;
        } else {
            $this->db->commit();
            return 1;
        }
    }


    /**
     * @brief Delete all records
     * @param $user User object
     * @param $environment Optional: only delete for specific environment
     * @return int Number of deleted records or -1 on error
     * @called_by incoming_list.php mass action
     */
    public function deleteAll($user, $environment = '')
    {
        global $conf;

        $this->db->begin();

        $sql = "DELETE FROM " . MAIN_DB_PREFIX . $this->table_element;
        $sql .= " WHERE entity IN (" . getEntity($this->element) . ")";

        if (!empty($environment)) {
            $sql .= " AND environment = '" . $this->db->escape($environment) . "'";
        }

        dol_syslog("KsefIncoming::deleteAll", LOG_DEBUG);
        $resql = $this->db->query($sql);

        if (!$resql) {
            $this->error = "Error " . $this->db->lasterror();
            $this->db->rollback();
            return -1;
        }

        $deleted = $this->db->affected_rows($resql);
        $this->db->commit();

        dol_syslog("KsefIncoming::deleteAll deleted $deleted records", LOG_INFO);
        return $deleted;
    }


    /**
     * @brief Check if record exists by KSeF number
     * @param $ksef_number KsefService number
     * @return bool True if exists
     * @called_by KSEF::syncIncomingInvoices()
     */
    public function existsByKsefNumber($ksef_number)
    {
        $sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . $this->table_element;
        $sql .= " WHERE ksef_number = '" . $this->db->escape($ksef_number) . "'";
        $sql .= " LIMIT 1";

        $resql = $this->db->query($sql);
        if ($resql) {
            $exists = ($this->db->num_rows($resql) > 0);
            $this->db->free($resql);
            return $exists;
        }
        return false;
    }


    /**
     * @brief which KSeF numbers already in the database
     * @param array $ksefNumbers Array of KSeF number strings to check
     * @param string $environment Environment (TEST/DEMO/PRODUCTION)
     * @return array Array of KSeF number strings that already exist
     */
    public function getExistingKsefNumbers(array $ksefNumbers, $environment)
    {
        if (empty($ksefNumbers)) {
            return array();
        }

        $escaped = array();
        foreach ($ksefNumbers as $num) {
            $escaped[] = "'" . $this->db->escape($num) . "'";
        }

        $sql = "SELECT ksef_number FROM " . MAIN_DB_PREFIX . $this->table_element;
        $sql .= " WHERE ksef_number IN (" . implode(',', $escaped) . ")";
        $sql .= " AND environment = '" . $this->db->escape($environment) . "'";
        $sql .= " AND entity IN (" . getEntity($this->element) . ")";

        $resql = $this->db->query($sql);
        if ($resql) {
            $existing = array();
            while ($obj = $this->db->fetch_object($resql)) {
                $existing[] = $obj->ksef_number;
            }
            $this->db->free($resql);
            return $existing;
        }
        return array();
    }


    /**
     * @brief Create from parsed FA(3) data
     * @param $parsedData Parsed data from FA3Parser
     * @param $rawXml Raw XML string
     * @param $ksefNumber KsefService reference number
     * @param $environment Environment (TEST/DEMO/PRODUCTION)
     * @param $user User object
     * @return int Record ID or negative on error
     * @called_by KSEF::syncIncomingInvoices()
     * @calls create()
     */
    public function createFromParsed($parsedData, $rawXml, $ksefNumber, $environment, $user, $notransaction = false)
    {
        $this->ksef_number = $ksefNumber;
        $this->seller_nip = $parsedData['seller']['nip'] ?? '';
        if (!empty($parsedData['seller']['kod_ue']) && !empty($parsedData['seller']['nr_vat_ue'])) {
            $this->seller_vat_id = $parsedData['seller']['kod_ue'] . $parsedData['seller']['nr_vat_ue'];
        }
        // use VAT ID as seller_nip for matching when no NIP present
        if (empty($this->seller_nip) && !empty($this->seller_vat_id)) {
            $this->seller_nip = $this->seller_vat_id;
        }
        $this->seller_name = $parsedData['seller']['name'] ?? '';
        $this->seller_country = $parsedData['seller']['country'] ?? 'PL';
        $this->seller_address = $parsedData['seller']['address'] ?? '';
        $this->buyer_nip = $parsedData['buyer']['nip'] ?? '';
        $this->buyer_name = $parsedData['buyer']['name'] ?? '';
        $this->invoice_number = $parsedData['invoice']['number'] ?? '';
        $this->invoice_type = $parsedData['invoice']['type'] ?? 'VAT';
        $this->invoice_date = self::safeStrtotime($parsedData['invoice']['date'] ?? '');
        $this->sale_date = self::safeStrtotime($parsedData['invoice']['sale_date'] ?? '');
        $this->currency = $parsedData['invoice']['currency'] ?? 'PLN';
        $this->total_net = $parsedData['invoice']['total_net'] ?? 0;
        $this->total_vat = $parsedData['invoice']['total_vat'] ?? 0;
        $this->total_gross = $parsedData['invoice']['total_gross'] ?? 0;
        if (!empty($parsedData['vat_summary'])) {
            $this->vat_summary = json_encode($parsedData['vat_summary']);
        }
        if (!empty($parsedData['lines'])) {
            $linesToStore = $parsedData['lines'];
            // For KOR invoices with before/after states, compute difference lines
            if (!empty($parsedData['lines_before'])) {
                $beforeMap = array();
                foreach ($parsedData['lines_before'] as $bLine) {
                    $beforeMap[$bLine['line_num']] = $bLine;
                }
                foreach ($linesToStore as &$diffLine) {
                    $lineNum = $diffLine['line_num'];
                    if (isset($beforeMap[$lineNum])) {
                        $before = $beforeMap[$lineNum];
                        $diffLine['net_amount'] = round(($diffLine['net_amount'] ?? 0) - ($before['net_amount'] ?? 0), 2);
                        $diffLine['unit_price_net'] = round(($diffLine['unit_price_net'] ?? 0) - ($before['unit_price_net'] ?? 0), 4);
                        if (!is_null($diffLine['gross_amount'] ?? null) && !is_null($before['gross_amount'] ?? null)) {
                            $diffLine['gross_amount'] = round($diffLine['gross_amount'] - $before['gross_amount'], 2);
                        }
                        if (!is_null($diffLine['unit_price_gross'] ?? null) && !is_null($before['unit_price_gross'] ?? null)) {
                            $diffLine['unit_price_gross'] = round($diffLine['unit_price_gross'] - $before['unit_price_gross'], 4);
                        }
                    }
                }
                unset($diffLine);
            }
            $this->line_items = json_encode($linesToStore);
        }
        $this->payment_due_date = self::safeStrtotime($parsedData['payment']['due_date'] ?? '');
        $this->payment_method = $parsedData['payment']['method'] ?? null;
        $this->bank_account = $parsedData['payment']['bank_account'] ?? null;
        $this->payment_status = $parsedData['payment']['status'] ?? null;
        $this->payment_date = self::safeStrtotime($parsedData['payment']['payment_date'] ?? '');
        if (!empty($parsedData['correction'])) {
            $firstCorrected = $parsedData['correction']['corrected_invoices'][0] ?? array();
            $this->corrected_ksef_number = $firstCorrected['ksef_number'] ?? null;
            $this->corrected_invoice_number = $firstCorrected['invoice_number'] ?? null;
            $this->corrected_invoice_date = self::safeStrtotime($firstCorrected['invoice_date'] ?? '');
            $this->correction_data = json_encode($parsedData['correction']);
        }
        $this->fa3_xml = $rawXml;
        $this->fa3_creation_date = self::safeStrtotime($parsedData['header']['creation_date'] ?? '');
        $this->fa3_system_info = $parsedData['header']['system_info'] ?? '';
        $this->fetch_date = dol_now();
        $this->environment = $environment;
        $this->import_status = self::STATUS_NEW;

        return $this->create($user, 0, $notransaction);
    }


    /**
     * @brief Check if an invoice type is a correction type
     * @param string $type Invoice type code
     * @return bool True for KOR, KOR_ZAL, KOR_ROZ
     */
    public static function isCorrectionType($type)
    {
        return in_array($type, array('KOR', 'KOR_ZAL', 'KOR_ROZ'));
    }


    /**
     * @brief matching incoming records and supplier invoices
     * @return array Array of ['invoice_number', 'invoice_date', 'ksef_number', 'incoming' => KsefIncoming|null, 'supplier_invoice' => FactureFournisseur|null]
     * @called_by incoming_card.php, incoming_import.php, importToDolibarr()
     */
    public function resolveCorrectedInvoices()
    {
        require_once DOL_DOCUMENT_ROOT . '/fourn/class/fournisseur.facture.class.php';

        $correctionData = self::isCorrectionType($this->invoice_type) ? $this->getCorrectionData() : null;
        if (empty($correctionData) && self::isCorrectionType($this->invoice_type) && ($this->corrected_invoice_number || $this->corrected_ksef_number)) {
            $correctionData = array(
                'reason' => null,
                'corrected_invoices' => array(
                    array(
                        'invoice_number' => $this->corrected_invoice_number,
                        'invoice_date' => $this->corrected_invoice_date ? dol_print_date($this->corrected_invoice_date, '%Y-%m-%d') : '',
                        'ksef_number' => $this->corrected_ksef_number,
                    ),
                ),
            );
        }

        $correctedInvoices = !empty($correctionData['corrected_invoices']) ? $correctionData['corrected_invoices'] : array();
        $resolved = array();

        foreach ($correctedInvoices as $corrInv) {
            if (empty($corrInv['ksef_number']) && empty($corrInv['invoice_number']) && empty($corrInv['invoice_date'])) {
                continue;
            }

            $entry = array(
                'invoice_number' => $corrInv['invoice_number'] ?? '',
                'invoice_date' => $corrInv['invoice_date'] ?? '',
                'ksef_number' => $corrInv['ksef_number'] ?? '',
                'incoming' => null,
                'supplier_invoice' => null,
            );

            $lookup = new KsefIncoming($this->db);
            $found = false;
            if (!empty($corrInv['ksef_number'])) {
                $found = ($lookup->fetch(0, $corrInv['ksef_number']) > 0);
            }
            if (!$found && !empty($corrInv['invoice_number']) && !empty($this->seller_nip)) {
                $found = ($lookup->fetchBySellerAndInvoice($this->seller_nip, $corrInv['invoice_number']) > 0);
            }
            if ($found) {
                $entry['incoming'] = $lookup;
                if ($lookup->fk_facture_fourn > 0) {
                    $suppInv = new FactureFournisseur($this->db);
                    if ($suppInv->fetch($lookup->fk_facture_fourn) > 0) {
                        $entry['supplier_invoice'] = $suppInv;
                    }
                }
            }

            if (empty($entry['supplier_invoice']) && !empty($corrInv['invoice_number']) && (!empty($this->seller_nip) || !empty($this->seller_vat_id))) {
                $nip = preg_replace('/[^0-9]/', '', $this->seller_nip);
                $vatId = !empty($this->seller_vat_id) ? $this->seller_vat_id : '';

                $matchWhere = $this->buildThirdPartyMatchWhere($nip, $vatId);
                $sql = "SELECT f.rowid FROM " . MAIN_DB_PREFIX . "facture_fourn f";
                $sql .= " INNER JOIN " . MAIN_DB_PREFIX . "societe s ON f.fk_soc = s.rowid";
                $sql .= " WHERE f.ref_supplier = '" . $this->db->escape($corrInv['invoice_number']) . "'";
                $sql .= " AND s.fournisseur = 1";
                $sql .= " AND s.entity IN (" . getEntity('societe') . ")";
                $sql .= " AND (" . $matchWhere . ")";
                $sql .= " LIMIT 1";
                $resql = $this->db->query($sql);
                if ($resql && ($obj = $this->db->fetch_object($resql))) {
                    $suppInv = new FactureFournisseur($this->db);
                    if ($suppInv->fetch($obj->rowid) > 0) {
                        $entry['supplier_invoice'] = $suppInv;
                    }
                }
            }

            $resolved[] = $entry;
        }

        return $resolved;
    }


    /**
     * @brief Get correction data as decoded array
     * @return array|null Correction data with 'reason', 'type', 'corrected_invoices' array, or null
     * @called_by incoming_card.php
     */
    public function getCorrectionData()
    {
        if (empty($this->correction_data)) {
            return null;
        }
        $decoded = json_decode($this->correction_data, true);
        if (!is_array($decoded)) return null;
        return $decoded;
    }


    /**
     * @brief Fetch an incoming invoice by seller NIP and invoice number
     * @param string $sellerNip Seller NIP
     * @param string $invoiceNumber Invoice number (P_2)
     * @return int 1 if found, 0 if not, -1 on error
     * @called_by incoming_card.php
     */
    public function fetchBySellerAndInvoice($sellerNip, $invoiceNumber)
    {
        $sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . $this->table_element
            . " WHERE seller_nip = '" . $this->db->escape($sellerNip) . "'"
            . " AND invoice_number = '" . $this->db->escape($invoiceNumber) . "'"
            . " LIMIT 1";

        $resql = $this->db->query($sql);
        if ($resql) {
            if ($this->db->num_rows($resql) > 0) {
                $obj = $this->db->fetch_object($resql);
                $this->db->free($resql);
                return $this->fetch($obj->rowid);
            }
            $this->db->free($resql);
            return 0;
        }
        return -1;
    }


    /**
     * @brief Fetch incoming invoice linked to a supplier invoice
     * @param int $fk_facture_fourn Supplier invoice ID
     * @return int >0 if found, 0 if not found, -1 on error
     * @called_by ActionsKSEF
     */
    public function fetchBySupplierInvoice($fk_facture_fourn)
    {
        $sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . $this->table_element
            . " WHERE fk_facture_fourn = " . (int)$fk_facture_fourn
            . " LIMIT 1";

        $resql = $this->db->query($sql);
        if ($resql) {
            if ($this->db->num_rows($resql) > 0) {
                $obj = $this->db->fetch_object($resql);
                $this->db->free($resql);
                return $this->fetch($obj->rowid);
            }
            $this->db->free($resql);
            return 0;
        }
        return -1;
    }

    /**
     * @brief Get line items as array
     * @return array Line items
     * @called_by incoming_card.php
     */
    public function getLineItems()
    {
        if (empty($this->line_items)) {
            return array();
        }
        $decoded = json_decode($this->line_items, true);
        if (!is_array($decoded)) return array();

        // Calculate unit prices
        foreach ($decoded as &$line) {
            if ($this->isNullOrBlank($line['unit_price_net'] ?? null) && !$this->isNullOrBlank($line['net_amount'] ?? null) && !$this->isNullOrBlank($line['quantity'] ?? null) && $line['quantity'] != 0) {
                $line['unit_price_net'] = round($line['net_amount'] / $line['quantity'], 4);
            }
            if ($this->isNullOrBlank($line['unit_price_gross'] ?? null) && !$this->isNullOrBlank($line['gross_amount'] ?? null) && !$this->isNullOrBlank($line['quantity'] ?? null) && $line['quantity'] != 0) {
                $line['unit_price_gross'] = round($line['gross_amount'] / $line['quantity'], 4);
            }

            // calculate net from gross
            $vatRate = isset($line['vat_rate']) && is_numeric($line['vat_rate']) ? (float)$line['vat_rate'] : 0;
            $vatMultiplier = 1 + $vatRate / 100;
            if ($this->isNullOrBlank($line['net_amount'] ?? null) && !$this->isNullOrBlank($line['gross_amount'] ?? null) && $vatMultiplier > 0) {
                $line['net_amount'] = round($line['gross_amount'] / $vatMultiplier, 2);
            }
            if ($this->isNullOrBlank($line['unit_price_net'] ?? null) && !$this->isNullOrBlank($line['unit_price_gross'] ?? null) && $vatMultiplier > 0) {
                $line['unit_price_net'] = round($line['unit_price_gross'] / $vatMultiplier, 4);
            }
            // calculate gross from net
            if ($this->isNullOrBlank($line['gross_amount'] ?? null) && !$this->isNullOrBlank($line['net_amount'] ?? null)) {
                $line['gross_amount'] = round($line['net_amount'] * $vatMultiplier, 2);
            }
            if ($this->isNullOrBlank($line['unit_price_gross'] ?? null) && !$this->isNullOrBlank($line['unit_price_net'] ?? null)) {
                $line['unit_price_gross'] = round($line['unit_price_net'] * $vatMultiplier, 4);
            }
        }

        return $decoded;
    }


    /**
     * @brief Get VAT summary as array
     * @return array VAT summary by rate
     * @called_by incoming_card.php
     */
    public function getVatSummary()
    {
        if (empty($this->vat_summary)) {
            return array();
        }
        $decoded = json_decode($this->vat_summary, true);
        return is_array($decoded) ? $decoded : array();
    }


    /**
     * @brief Get exchange rate from parsed XML data
     * @return float Exchange rate or 0 if not available
     */
    public function getExchangeRate()
    {
        if (empty($this->fa3_xml)) {
            return 0;
        }
        $parser = new FA3Parser($this->db);
        $parsed = $parser->parse($this->fa3_xml);
        if ($parsed && !empty($parsed['exchange_rate']['rate'])) {
            return (float)$parsed['exchange_rate']['rate'];
        }
        return 0;
    }


    /**
     * @brief Check if a value is null or blank string
     * @param mixed $val Value to check
     * @return bool True if null or empty string
     */
    private function isNullOrBlank($val)
    {
        return $val === null || $val === '';
    }


    /**
     * @brief Fetch all records with filters
     * @param $filters Filter conditions array
     * @param $sortfield Sort field
     * @param $sortorder Sort order (ASC/DESC)
     * @param $limit Limit
     * @param $offset Offset
     * @return array|int Array of objects or -1 on error
     * @called_by incoming_list.php
     * @calls fetch()
     */
    public function fetchAll($filters = array(), $sortfield = 'i.invoice_date', $sortorder = 'DESC', $limit = 0, $offset = 0)
    {
        global $conf;

        $allowedSortFields = array(
            'i.invoice_number', 'i.invoice_type', 'i.seller_name', 'i.seller_nip',
            'i.import_status', 'i.invoice_date', 'i.total_gross',
        );
        if (!in_array($sortfield, $allowedSortFields)) {
            $sortfield = 'i.invoice_date';
        }
        $sortorder = strtoupper($sortorder);
        if (!in_array($sortorder, array('ASC', 'DESC'))) {
            $sortorder = 'DESC';
        }

        $results = array();

        $sql = "SELECT i.rowid FROM " . MAIN_DB_PREFIX . $this->table_element . " as i";
        $sql .= " WHERE i.entity IN (" . getEntity($this->element) . ")";

        if (!empty($filters['seller_nip'])) {
            $sql .= " AND i.seller_nip LIKE '%" . $this->db->escape($filters['seller_nip']) . "%'";
        }
        if (!empty($filters['seller_name'])) {
            $sql .= " AND i.seller_name LIKE '%" . $this->db->escape($filters['seller_name']) . "%'";
        }
        if (!empty($filters['invoice_number'])) {
            $sql .= " AND i.invoice_number LIKE '%" . $this->db->escape($filters['invoice_number']) . "%'";
        }
        if (!empty($filters['ksef_number'])) {
            $sql .= " AND i.ksef_number LIKE '%" . $this->db->escape($filters['ksef_number']) . "%'";
        }
        if (!empty($filters['import_status'])) {
            $sql .= " AND i.import_status = '" . $this->db->escape($filters['import_status']) . "'";
        }
        if (!empty($filters['environment'])) {
            $sql .= " AND i.environment = '" . $this->db->escape($filters['environment']) . "'";
        }
        if (!empty($filters['invoice_date_start'])) {
            $sql .= " AND i.invoice_date >= " . (int)$filters['invoice_date_start'];
        }
        if (!empty($filters['invoice_date_end'])) {
            $sql .= " AND i.invoice_date <= " . (int)$filters['invoice_date_end'];
        }

        $sql .= " ORDER BY " . $sortfield . " " . $sortorder;

        if ($limit > 0) {
            $sql .= " LIMIT " . (int)$limit;
            if ($offset > 0) {
                $sql .= " OFFSET " . (int)$offset;
            }
        }

        dol_syslog("KsefIncoming::fetchAll", LOG_DEBUG);
        $resql = $this->db->query($sql);

        if ($resql) {
            while ($obj = $this->db->fetch_object($resql)) {
                $record = new KsefIncoming($this->db);
                if ($record->fetch($obj->rowid) > 0) {
                    $results[] = $record;
                }
            }
            $this->db->free($resql);
            return $results;
        } else {
            $this->error = "Error " . $this->db->lasterror();
            return -1;
        }
    }


    /**
     * @brief Count records with filters
     * @param $filters Filter conditions array
     * @return int Count or -1 on error
     * @called_by incoming_list.php
     */
    public function countAll($filters = array())
    {
        $sql = "SELECT COUNT(*) as total FROM " . MAIN_DB_PREFIX . $this->table_element . " as i";
        $sql .= " WHERE i.entity IN (" . getEntity($this->element) . ")";

        // Apply same filters as fetchAll
        if (!empty($filters['seller_nip'])) {
            $sql .= " AND i.seller_nip LIKE '%" . $this->db->escape($filters['seller_nip']) . "%'";
        }
        if (!empty($filters['seller_name'])) {
            $sql .= " AND i.seller_name LIKE '%" . $this->db->escape($filters['seller_name']) . "%'";
        }
        if (!empty($filters['invoice_number'])) {
            $sql .= " AND i.invoice_number LIKE '%" . $this->db->escape($filters['invoice_number']) . "%'";
        }
        if (!empty($filters['ksef_number'])) {
            $sql .= " AND i.ksef_number LIKE '%" . $this->db->escape($filters['ksef_number']) . "%'";
        }
        if (!empty($filters['import_status'])) {
            $sql .= " AND i.import_status = '" . $this->db->escape($filters['import_status']) . "'";
        }
        if (!empty($filters['environment'])) {
            $sql .= " AND i.environment = '" . $this->db->escape($filters['environment']) . "'";
        }
        if (!empty($filters['invoice_date_start'])) {
            $sql .= " AND i.invoice_date >= " . (int)$filters['invoice_date_start'];
        }
        if (!empty($filters['invoice_date_end'])) {
            $sql .= " AND i.invoice_date <= " . (int)$filters['invoice_date_end'];
        }

        $resql = $this->db->query($sql);
        if ($resql) {
            $obj = $this->db->fetch_object($resql);
            $this->db->free($resql);
            return (int)$obj->total;
        }
        return -1;
    }


    /**
     * @brief Get statistics for incoming invoices
     * @param $days Number of days to look back
     * @return array Statistics array with total, new, imported, error counts
     * @called_by KSEF::getIncomingStatistics()
     */
    public function getStatistics($days = 30)
    {
        $stats = array(
            'total' => 0,
            'new' => 0,
            'imported' => 0,
            'error' => 0,
        );

        $since = dol_now() - ($days * 86400);

        $sql = "SELECT import_status, COUNT(*) as count FROM " . MAIN_DB_PREFIX . $this->table_element;
        $sql .= " WHERE fetch_date > " . (int)$since;
        $sql .= " AND entity IN (" . getEntity($this->element) . ")";
        $sql .= " GROUP BY import_status";

        $resql = $this->db->query($sql);
        if ($resql) {
            while ($obj = $this->db->fetch_object($resql)) {
                $stats['total'] += $obj->count;
                if ($obj->import_status == self::STATUS_NEW) {
                    $stats['new'] = $obj->count;
                } elseif ($obj->import_status == self::STATUS_IMPORTED) {
                    $stats['imported'] = $obj->count;
                } elseif ($obj->import_status == self::STATUS_ERROR) {
                    $stats['error'] = $obj->count;
                }
            }
            $this->db->free($resql);
        }

        return $stats;
    }


    /**
     * @brief Get list of IDs for mass operations
     * @param $filters Filter conditions array
     * @return array Array of rowids
     * @called_by incoming_list.php mass actions
     */
    public function getIds($filters = array())
    {
        $ids = array();

        $sql = "SELECT i.rowid FROM " . MAIN_DB_PREFIX . $this->table_element . " as i";
        $sql .= " WHERE i.entity IN (" . getEntity($this->element) . ")";

        if (!empty($filters['import_status'])) {
            $sql .= " AND i.import_status = '" . $this->db->escape($filters['import_status']) . "'";
        }
        if (!empty($filters['environment'])) {
            $sql .= " AND i.environment = '" . $this->db->escape($filters['environment']) . "'";
        }

        $resql = $this->db->query($sql);
        if ($resql) {
            while ($obj = $this->db->fetch_object($resql)) {
                $ids[] = $obj->rowid;
            }
            $this->db->free($resql);
        }

        return $ids;
    }


    /**
     * @brief Return clickable link of object (with eventually picto)
     * @param $withpicto Add picto into link
     * @param $option Options
     * @param $notooltip 1=Disable tooltip
     * @param $morecss Add more css on link
     * @param $save_lastsearch_value -1=Auto, 0=No save, 1=Save lastsearch_values
     * @return string String with URL
     * @called_by Various display pages
     */
    public function getNomUrl($withpicto = 0, $option = '', $notooltip = 0, $morecss = '', $save_lastsearch_value = -1)
    {
        global $conf, $langs, $hookmanager;

        if (!empty($conf->dol_no_mouse_hover)) {
            $notooltip = 1;
        }

        $result = '';

        $label = img_picto('', $this->picto) . ' <u class="paddingrightonly">' . $langs->trans("KSEF_IncomingInvoice") . '</u>';
        if (isset($this->import_status)) {
            $label .= ' ' . $this->getLibStatut(5);
        }
        $label .= '<br>';
        $label .= '<b>' . $langs->trans('KSEF_Number') . ':</b> ' . $this->ksef_number;
        if (!empty($this->invoice_number)) {
            $label .= '<br><b>' . $langs->trans('RefSupplierBill') . ':</b> ' . $this->invoice_number;
        }
        if (!empty($this->seller_name)) {
            $label .= '<br><b>' . $langs->trans('Supplier') . ':</b> ' . $this->seller_name;
        }
        if (!empty($this->total_gross)) {
            $label .= '<br><b>' . $langs->trans('AmountTTC') . ':</b> ' . price($this->total_gross, 0, $langs, 1, -1, -1, $this->currency);
        }

        $url = dol_buildpath('/ksef/incoming_card.php', 1) . '?id=' . $this->id;

        if ($option != 'nolink') {
            $add_save_lastsearch_values = ($save_lastsearch_value == 1 ? 1 : 0);
            if ($save_lastsearch_value == -1 && isset($_SERVER["PHP_SELF"]) && preg_match('/list\.php/', $_SERVER["PHP_SELF"])) {
                $add_save_lastsearch_values = 1;
            }
            if ($add_save_lastsearch_values) {
                $url .= '&save_lastsearch_values=1';
            }
        }

        $linkclose = '';
        if (empty($notooltip)) {
            if (getDolGlobalString('MAIN_OPTIMIZEFORTEXTBROWSER')) {
                $label = $langs->trans("ShowKsefIncoming");
                $linkclose .= ' alt="' . dol_escape_htmltag($label, 1) . '"';
            }
            $linkclose .= ' title="' . dol_escape_htmltag($label, 1) . '"';
            $linkclose .= ' class="classfortooltip' . ($morecss ? ' ' . $morecss : '') . '"';
        } else {
            $linkclose = ($morecss ? ' class="' . $morecss . '"' : '');
        }

        $linkstart = '<a href="' . $url . '"';
        $linkstart .= $linkclose . '>';
        $linkend = '</a>';

        $result .= $linkstart;

        if ($withpicto) {
            $result .= img_object(($notooltip ? '' : $label), ($this->picto ? $this->picto : 'generic'), ($notooltip ? (($withpicto != 2) ? 'class="paddingright"' : '') : 'class="' . (($withpicto != 2) ? 'paddingright ' : '') . 'classfortooltip"'), 0, 0, $notooltip ? 0 : 1);
        }

        if ($withpicto != 2) {
            $result .= $this->ksef_number;
        }

        $result .= $linkend;

        global $action;
        $hookmanager->initHooks(array('ksef_incomingdao'));
        $parameters = array('id' => $this->id, 'getnomurl' => &$result);
        $reshook = $hookmanager->executeHooks('getNomUrl', $parameters, $this, $action);
        if ($reshook > 0) {
            $result = $hookmanager->resPrint;
        } else {
            $result .= $hookmanager->resPrint;
        }

        return $result;
    }


    /**
     * @brief Return the label of the status
     * @param $mode 0=long label, 1=short label, 2=Picto + short label, 3=Picto, 4=Picto + long label, 5=Short label + Picto, 6=Long label + Picto
     * @return string Label of status
     * @called_by getNomUrl()
     * @calls LibStatut()
     */
    public function getLibStatut($mode = 0)
    {
        return $this->LibStatut($this->import_status, $mode);
    }


    /**
     * @brief Return the label of a given status
     * @param $status Status to get label for
     * @param $mode 0=long label, 1=short label, 2=Picto + short label, 3=Picto, 4=Picto + long label, 5=Short label + Picto, 6=Long label + Picto
     * @return string Label of status
     * @called_by getLibStatut()
     */
    public function LibStatut($status, $mode = 0)
    {
        global $langs;
        $langs->load("ksef@ksef");

        $statusType = 'status0';
        $statusLabel = $status;
        $statusLabelShort = $status;

        if ($status == self::STATUS_NEW || $status == 'NEW') {
            $statusType = 'status1';
            $statusLabel = $langs->transnoentitiesnoconv('KSEF_ImportStatusNEW');
            $statusLabelShort = $langs->transnoentitiesnoconv('KSEF_ImportStatusNEW');
        } elseif ($status == self::STATUS_IMPORTED || $status == 'IMPORTED') {
            $statusType = 'status4';
            $statusLabel = $langs->transnoentitiesnoconv('KSEF_ImportStatusIMPORTED');
            $statusLabelShort = $langs->transnoentitiesnoconv('KSEF_ImportStatusIMPORTED');
        } elseif ($status == 'SKIPPED') {
            $statusType = 'status6';
            $statusLabel = $langs->transnoentitiesnoconv('KSEF_ImportStatusSKIPPED');
            $statusLabelShort = $langs->transnoentitiesnoconv('KSEF_ImportStatusSKIPPED');
        } elseif ($status == self::STATUS_ERROR || $status == 'ERROR') {
            $statusType = 'status8';
            $statusLabel = $langs->transnoentitiesnoconv('KSEF_ImportStatusERROR');
            $statusLabelShort = $langs->transnoentitiesnoconv('KSEF_ImportStatusERROR');
        }

        return dolGetStatus($statusLabel, $statusLabelShort, '', $statusType, $mode);
    }


    /**
     * @brief Build WHERE conditions for matching a third party by NIP and/or VAT ID
     * @param string $nip Polish NIP (digits only)
     * @param string $vatId Full EU VAT ID (e.g. "PL1234567890")
     * @param string $alias Table alias (default 's')
     * @return string SQL WHERE conditions (without leading AND)
     */
    private function buildThirdPartyMatchWhere($nip, $vatId, $alias = 's')
    {
        $vatDigits = !empty($vatId) ? preg_replace('/[^0-9]/', '', $vatId) : '';
        $nipField = ksefGetFieldName('NIP');
        $nipColumn = ksefFieldToColumn($nipField);

        $conditions = array();
        if (!empty($nip)) {
            $escapedNip = $this->db->escape($nip);
            if (!empty($nipColumn)) {
                $conditions[] = "REPLACE(REPLACE({$alias}." . $this->db->escape($nipColumn) . ", '-', ''), ' ', '') LIKE '%" . $escapedNip . "%'";
            }
            $otherFields = array('siren', 'siret', 'ape', 'idprof4', 'idprof5', 'idprof6', 'tva_intra');
            foreach ($otherFields as $field) {
                if ($field !== $nipColumn) {
                    $conditions[] = "REPLACE(REPLACE({$alias}." . $field . ", '-', ''), ' ', '') LIKE '%" . $escapedNip . "%'";
                }
            }
        }
        if (!empty($vatDigits) && $vatDigits !== $nip) {
            $escapedVat = $this->db->escape($vatDigits);
            $conditions[] = "REPLACE(REPLACE({$alias}.tva_intra, '-', ''), ' ', '') LIKE '%" . $escapedVat . "%'";
        }

        return implode(" OR ", $conditions);
    }


    /**
     * @brief Build full SQL for matching a third party by NIP and/or VAT ID
     * @param string $nip Polish NIP (digits only)
     * @param string $vatId Full EU VAT ID (e.g. "PL1234567890")
     * @return string SQL query returning rowid, ordered by match priority
     */
    private function buildThirdPartyMatchSQL($nip, $vatId)
    {
        $vatDigits = !empty($vatId) ? preg_replace('/[^0-9]/', '', $vatId) : '';
        $nipField = ksefGetFieldName('NIP');
        $nipColumn = ksefFieldToColumn($nipField);

        $sql = "SELECT s.rowid FROM " . MAIN_DB_PREFIX . "societe s";
        $sql .= " WHERE s.fournisseur = 1";
        $sql .= " AND s.entity IN (" . getEntity('societe') . ")";
        $sql .= " AND (" . $this->buildThirdPartyMatchWhere($nip, $vatId) . ")";

        $orderCases = array();
        if (!empty($nip)) {
            $escapedNip = $this->db->escape($nip);
            if (!empty($nipColumn)) {
                $orderCases[] = "CASE WHEN REPLACE(REPLACE(s." . $this->db->escape($nipColumn) . ", '-', ''), ' ', '') LIKE '%" . $escapedNip . "%' THEN 0";
            } else {
                $orderCases[] = "CASE WHEN 1=0 THEN 0";
            }
            $otherFields = array('siren', 'siret', 'ape', 'idprof4', 'idprof5', 'idprof6');
            foreach ($otherFields as $field) {
                if ($field !== $nipColumn) {
                    $orderCases[] = "WHEN REPLACE(REPLACE(s." . $field . ", '-', ''), ' ', '') LIKE '%" . $escapedNip . "%' THEN 1";
                }
            }
            if ($nipField !== 'tva_intra') {
                $orderCases[] = "WHEN REPLACE(REPLACE(s.tva_intra, '-', ''), ' ', '') LIKE '%" . $escapedNip . "%' THEN 2";
            }
        }
        if (!empty($vatDigits)) {
            $escapedVat = $this->db->escape($vatDigits);
            if (empty($orderCases)) {
                $orderCases[] = "CASE WHEN REPLACE(REPLACE(s.tva_intra, '-', ''), ' ', '') LIKE '%" . $escapedVat . "%' THEN " . (empty($nip) ? '0' : '2');
            } else {
                $orderCases[] = "WHEN REPLACE(REPLACE(s.tva_intra, '-', ''), ' ', '') LIKE '%" . $escapedVat . "%' THEN " . (empty($nip) ? '0' : '2');
            }
        }
        if (empty($orderCases)) {
            $orderCases[] = "CASE WHEN 1=0 THEN 0";
        }
        $orderCases[] = "ELSE 9 END";

        $sql .= " ORDER BY " . implode(" ", $orderCases) . ", s.rowid ASC";
        $sql .= " LIMIT 1";

        return $sql;
    }


    /**
     * @brief Find matching third party (supplier) by seller NIP
     * @return int socid if found, 0 if not found
     * @called_by incoming_import.php
     */
    public function findMatchingThirdParty()
    {
        if (empty($this->seller_nip) && empty($this->seller_vat_id)) {
            return 0;
        }

        $nip = preg_replace('/[^0-9]/', '', $this->seller_nip);
        $vatId = !empty($this->seller_vat_id) ? $this->seller_vat_id : '';

        if (empty($nip) && empty($vatId)) {
            return 0;
        }

        $sql = $this->buildThirdPartyMatchSQL($nip, $vatId);

        dol_syslog("KsefIncoming::findMatchingThirdParty nip=" . $nip . " vatId=" . $vatId, LOG_DEBUG);
        $resql = $this->db->query($sql);

        if ($resql) {
            if ($this->db->num_rows($resql) > 0) {
                $obj = $this->db->fetch_object($resql);
                $this->db->free($resql);
                return (int)$obj->rowid;
            }
            $this->db->free($resql);
        }

        return 0;
    }


    /**
     * @brief Auto-match line items to products by ref, supplier ref, or barcode
     * @param array $lines Line items from getLineItems()
     * @return array array of line_num => array('product_id' => int, 'match_method' => string, 'product_ref' => string, 'product_label' => string) for matched lines
     * @called_by incoming_import.php, incoming_list.php
     */
    public function autoMatchLineProducts($lines)
    {
        $matches = array();

        if (empty($lines)) {
            return $matches;
        }

        foreach ($lines as $line) {
            $lineNum = $line['line_num'] ?? null;
            if ($lineNum === null) {
                continue;
            }

            $indeks = !empty($line['indeks']) ? trim($line['indeks']) : '';
            $gtin = !empty($line['gtin']) ? trim($line['gtin']) : '';

            if (!empty($indeks)) {
                $sql = "SELECT rowid, ref, label FROM " . MAIN_DB_PREFIX . "product";
                $sql .= " WHERE ref = '" . $this->db->escape($indeks) . "'";
                $sql .= " AND entity IN (" . getEntity('product') . ")";
                $sql .= " LIMIT 1";

                $resql = $this->db->query($sql);
                if ($resql && $this->db->num_rows($resql) > 0) {
                    $obj = $this->db->fetch_object($resql);
                    $matches[$lineNum] = array(
                        'product_id' => (int)$obj->rowid,
                        'match_method' => 'product_ref',
                        'product_ref' => $obj->ref,
                        'product_label' => $obj->label,
                    );
                    $this->db->free($resql);
                    continue;
                }
                if ($resql) {
                    $this->db->free($resql);
                }

                $sql = "SELECT pfp.fk_product, pfp.ref_fourn, p.ref, p.label FROM " . MAIN_DB_PREFIX . "product_fournisseur_price as pfp";
                $sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "product as p ON p.rowid = pfp.fk_product";
                $sql .= " WHERE pfp.ref_fourn = '" . $this->db->escape($indeks) . "'";
                $sql .= " AND pfp.entity IN (" . getEntity('product') . ")";
                $sql .= " LIMIT 1";

                $resql = $this->db->query($sql);
                if ($resql && $this->db->num_rows($resql) > 0) {
                    $obj = $this->db->fetch_object($resql);
                    $matches[$lineNum] = array(
                        'product_id' => (int)$obj->fk_product,
                        'match_method' => 'supplier_ref',
                        'product_ref' => $obj->ref,
                        'product_label' => $obj->label,
                    );
                    $this->db->free($resql);
                    continue;
                }
                if ($resql) {
                    $this->db->free($resql);
                }
            }

            if (!empty($gtin)) {
                $sql = "SELECT rowid, ref, label FROM " . MAIN_DB_PREFIX . "product";
                $sql .= " WHERE barcode = '" . $this->db->escape($gtin) . "'";
                $sql .= " AND entity IN (" . getEntity('product') . ")";
                $sql .= " LIMIT 1";

                $resql = $this->db->query($sql);
                if ($resql && $this->db->num_rows($resql) > 0) {
                    $obj = $this->db->fetch_object($resql);
                    $matches[$lineNum] = array(
                        'product_id' => (int)$obj->rowid,
                        'match_method' => 'barcode',
                        'product_ref' => $obj->ref,
                        'product_label' => $obj->label,
                    );
                    $this->db->free($resql);
                    continue;
                }
                if ($resql) {
                    $this->db->free($resql);
                }
            }
        }

        return $matches;
    }


    /**
     * @brief Import incoming invoice as a Dolibarr FactureFournisseur
     * @param User $user User performing the import
     * @param int $socid Supplier (societe) ID
     * @param array $lineProductMap Associative array of line_num => fk_product
     * @return int FactureFournisseur ID on success, negative on error
     * @called_by incoming_import.php
     */
    public function importToDolibarr($user, $socid, $lineProductMap = array(), $correctionSourceId = 0, $upwardMode = '')
    {
        global $conf, $langs;

        if ($socid <= 0) {
            $this->error = $langs->trans('KSEF_ImportErrorNoSupplier');
            return -1;
        }

        if ($this->import_status === 'IMPORTED') {
            $this->error = $langs->trans('KSEF_ImportErrorAlreadyImported');
            return -2;
        }

        require_once DOL_DOCUMENT_ROOT . '/fourn/class/fournisseur.facture.class.php';
        require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';

        $this->db->begin();

        $lockSql = "SELECT import_status FROM " . MAIN_DB_PREFIX . $this->table_element;
        $lockSql .= " WHERE rowid = " . (int)$this->id . " FOR UPDATE";
        $lockRes = $this->db->query($lockSql);
        if ($lockRes) {
            $lockObj = $this->db->fetch_object($lockRes);
            if ($lockObj && $lockObj->import_status === 'IMPORTED') {
                $this->error = $langs->trans('KSEF_ImportErrorAlreadyImported');
                $this->db->rollback();
                return -2;
            }
        }

        $isUpwardCorrection = (self::isCorrectionType($this->invoice_type) && (float)$this->total_gross > 0);

        $facture = new FactureFournisseur($this->db);
        $facture->fk_soc = $socid;
        $facture->socid = $socid;
        $facture->ref_supplier = $this->invoice_number;
        $facture->date = $this->invoice_date ? $this->invoice_date : dol_now();
        $facture->date_echeance = $this->payment_due_date ? $this->payment_due_date : null;
        $facture->note_public = 'KSeF: ' . $this->ksef_number;

        // Track the source invoice ID resolved from corrections (used for both linking and replace mode)
        $resolvedSourceId = 0;

        if (self::isCorrectionType($this->invoice_type)) {
            // For upward corrections: TYPE_STANDARD (difference mode) or handled specially (replace mode)
            // For downward corrections: TYPE_CREDIT_NOTE
            if ($isUpwardCorrection && $upwardMode !== 'replace') {
                $facture->type = FactureFournisseur::TYPE_STANDARD;
            } else {
                $facture->type = FactureFournisseur::TYPE_CREDIT_NOTE;
            }

            $resolvedCorrections = $this->resolveCorrectedInvoices();
            $correctedCount = count($resolvedCorrections);

            // Build ref list for note_public (used in multiple branches)
            $refList = array();
            foreach ($resolvedCorrections as $rc) {
                $ref = '';
                if (!empty($rc['invoice_number'])) {
                    $ref .= $rc['invoice_number'];
                }
                if (!empty($rc['ksef_number'])) {
                    $ref .= ($ref ? ' / ' : '') . $rc['ksef_number'];
                }
                if ($ref) {
                    $refList[] = $ref;
                }
            }

            if ($correctedCount > 1 && $correctionSourceId != 0) {
                // Multi-correction with explicit user choice
                if ($correctionSourceId == -1) {
                    // Standalone mode: no fk_facture_source, put refs in note_public
                    $facture->note_public .= "\n" . $langs->trans('KSEF_CorrectionStandaloneNoteText', implode(', ', $refList));
                } elseif ($correctionSourceId > 0) {
                    // Link to a specific chosen invoice
                    $facture->fk_facture_source = $correctionSourceId;
                    $resolvedSourceId = $correctionSourceId;
                    $facture->note_public .= "\n" . $langs->trans('KSEF_CorrectionStandaloneNoteText', implode(', ', $refList));
                }
            } else {
                // Single correction or legacy behavior ($correctionSourceId == 0)
                $sourceInvoiceId = 0;
                $firstCorrectedRef = '';
                foreach ($resolvedCorrections as $rc) {
                    if (empty($firstCorrectedRef)) {
                        $firstCorrectedRef = $rc['invoice_number'];
                    }
                    if ($rc['supplier_invoice']) {
                        $sourceInvoiceId = $rc['supplier_invoice']->id;
                        break;
                    }
                }
                if ($sourceInvoiceId > 0) {
                    $facture->fk_facture_source = $sourceInvoiceId;
                    $resolvedSourceId = $sourceInvoiceId;
                } elseif ($isUpwardCorrection && $upwardMode !== 'replace') {
                    // Upward + difference: original not required, proceed without fk_facture_source
                    $facture->note_public .= "\n" . $langs->trans('KSEF_CorrectionStandaloneNoteText', implode(', ', $refList));
                } else {
                    if (empty($resolvedCorrections)) {
                        $this->error = $langs->trans('KSEF_ImportErrorNoCorrectionsReference');
                    } else {
                        $this->error = $langs->trans('KSEF_ImportErrorOriginalNotImported', $firstCorrectedRef ?: $this->corrected_invoice_number);
                    }
                    $this->import_status = self::STATUS_ERROR;
                    $this->import_error = $this->error;
                    $this->db->rollback();
                    return -6;
                }
            }

            // Backend guard: replace mode is only valid for single corrections
            if ($isUpwardCorrection && $upwardMode === 'replace' && $correctedCount > 1) {
                $upwardMode = 'difference';
                $facture->type = FactureFournisseur::TYPE_STANDARD;
            }

            // For upward correction in "replace" mode: we need the original to zero out
            // The main $facture will be the replacement standard invoice (with corrected totals)
            // We first create a credit note to cancel the original
            if ($isUpwardCorrection && $upwardMode === 'replace' && $resolvedSourceId > 0) {
                $originalInv = new FactureFournisseur($this->db);
                if ($originalInv->fetch($resolvedSourceId) > 0) {
                    $originalInv->fetch_lines();

                    // Create credit note zeroing out the original
                    $creditNote = new FactureFournisseur($this->db);
                    $creditNote->fk_soc = $socid;
                    $creditNote->socid = $socid;
                    $creditNote->ref_supplier = $originalInv->ref_supplier . ' [auto-zero]';
                    $creditNote->date = $this->invoice_date ? $this->invoice_date : dol_now();
                    $creditNote->type = FactureFournisseur::TYPE_CREDIT_NOTE;
                    $creditNote->fk_facture_source = $resolvedSourceId;
                    $creditNote->note_public = $langs->trans('KSEF_UpwardCorrectionCreditNoteRef', $originalInv->ref, $this->ksef_number);

                    // Use the original invoice's multicurrency settings, not the KOR's,
                    // since the credit note must mirror the original's amounts and rate
                    if (!empty($originalInv->multicurrency_code) && $originalInv->multicurrency_code !== 'PLN') {
                        $creditNote->multicurrency_code = $originalInv->multicurrency_code;
                        if ($originalInv->multicurrency_tx > 0) {
                            $creditNote->multicurrency_tx = $originalInv->multicurrency_tx;
                        }
                    }

                    $cnId = $creditNote->create($user);
                    if ($cnId <= 0) {
                        $this->error = $creditNote->error;
                        $this->errors = $creditNote->errors;
                        $this->import_status = self::STATUS_ERROR;
                        $this->import_error = $creditNote->error;
                        $this->db->rollback();
                        return -7;
                    }

                    // Re-fetch to get the Dolibarr-assigned ref
                    $creditNote->fetch($cnId);

                    // Copy lines from original invoice to the credit note
                    $cnIsMulticurrency = (!empty($originalInv->multicurrency_code) && $originalInv->multicurrency_code !== 'PLN');
                    foreach ($originalInv->lines as $origLine) {
                        if ($cnIsMulticurrency) {
                            $cnPu = 0;
                            $cnPuDevise = abs($origLine->multicurrency_subprice);
                        } else {
                            $cnPu = abs($origLine->subprice);
                            $cnPuDevise = 0;
                        }
                        $result = $creditNote->addline(
                            $origLine->desc,
                            $cnPu,
                            $origLine->tva_tx,
                            $origLine->localtax1_tx,
                            $origLine->localtax2_tx,
                            $origLine->qty,
                            $origLine->fk_product,
                            $origLine->remise_percent,
                            0, 0, 0, 0,
                            'HT',
                            $origLine->product_type,
                            -1, 0, array(), null, 0,
                            $cnPuDevise,
                            $origLine->ref_supplier
                        );
                        if ($result <= 0) {
                            $this->error = $creditNote->error;
                            $this->errors = $creditNote->errors;
                            $this->import_status = self::STATUS_ERROR;
                            $this->import_error = $creditNote->error;
                            $this->db->rollback();
                            return -8;
                        }
                    }

                    // Store the credit note ID for display purposes
                    $this->fk_credit_note = $cnId;

                    // Now reconfigure $facture as the replacement standard invoice
                    // with the corrected totals (re-parsed from the XML "after" state)
                    $facture->type = FactureFournisseur::TYPE_STANDARD;
                    // Standard invoices don't use fk_facture_source, corrected refs go in note_public
                    $facture->fk_facture_source = null;
                    $facture->note_public .= "\n" . $langs->trans('KSEF_CorrectionStandaloneNoteText', implode(', ', $refList));
                    // Reference the zeroing credit note so it can be found from the replacement
                    $facture->note_public .= "\n" . $langs->trans('KSEF_UpwardCorrectionCreditNoteRef', $creditNote->ref, $this->ksef_number);
                }
            }
        } elseif ($this->invoice_type === 'ZAL') {
            $facture->type = FactureFournisseur::TYPE_DEPOSIT;
            $facture->cond_reglement_id = 1;
        } else {
            $facture->type = FactureFournisseur::TYPE_STANDARD;
        }

        $isMulticurrency = (!empty($this->currency) && $this->currency !== 'PLN');
        if ($isMulticurrency) {
            $facture->multicurrency_code = $this->currency;
            $exchangeRate = $this->getExchangeRate();
            if ($exchangeRate > 0) {
                $facture->multicurrency_tx = $exchangeRate;
            }
        }

        // payment method from XML
        if (!empty($this->payment_method)) {
            $facture->mode_reglement_id = $this->mapKsefPaymentMethod($this->payment_method);
        }

        $factureId = $facture->create($user);
        if ($factureId <= 0) {
            $this->error = $facture->error;
            $this->errors = $facture->errors;
            $this->import_status = self::STATUS_ERROR;
            $this->import_error = $facture->error;
            $this->db->rollback();
            return -3;
        }

        // For correction invoices, re-parse the XML to compute correct line items.
        // - Replace mode: use after-state lines (full corrected amounts)
        // - Difference mode: compute before/after diff lines (delta amounts)
        // Re-parsing ensures correctness even if stored line_items lack the diff.
        $reParsedVatSummary = null;
        if (self::isCorrectionType($this->invoice_type) && !empty($this->fa3_xml)) {
            $parser = new FA3Parser($this->db);
            $parsed = $parser->parse($this->fa3_xml);
            if ($parsed && !empty($parsed['lines'])) {
                $lines = $parsed['lines'];
                if (!empty($parsed['vat_summary'])) {
                    $reParsedVatSummary = $parsed['vat_summary'];
                }

                // For difference mode (not replace): compute before/after diff
                if ($upwardMode !== 'replace' && !empty($parsed['lines_before'])) {
                    $beforeMap = array();
                    foreach ($parsed['lines_before'] as $bLine) {
                        $beforeMap[$bLine['line_num']] = $bLine;
                    }
                    foreach ($lines as &$diffLine) {
                        $lineNum = $diffLine['line_num'];
                        if (isset($beforeMap[$lineNum])) {
                            $before = $beforeMap[$lineNum];
                            $diffLine['net_amount'] = round(($diffLine['net_amount'] ?? 0) - ($before['net_amount'] ?? 0), 2);
                            $diffLine['unit_price_net'] = round(($diffLine['unit_price_net'] ?? 0) - ($before['unit_price_net'] ?? 0), 4);
                            if (!is_null($diffLine['gross_amount'] ?? null) && !is_null($before['gross_amount'] ?? null)) {
                                $diffLine['gross_amount'] = round($diffLine['gross_amount'] - $before['gross_amount'], 2);
                            }
                            if (!is_null($diffLine['unit_price_gross'] ?? null) && !is_null($before['unit_price_gross'] ?? null)) {
                                $diffLine['unit_price_gross'] = round($diffLine['unit_price_gross'] - $before['unit_price_gross'], 4);
                            }
                            $diffNet = (float)($diffLine['net_amount'] ?? 0);
                            $diffQty = (float)($diffLine['quantity'] ?? 0);
                            if ($diffLine['unit_price_net'] == 0 && $diffNet != 0 && $diffQty != 0) {
                                $diffLine['unit_price_net'] = round($diffNet / $diffQty, 4);
                            }
                            if (($diffLine['unit_price_gross'] ?? 0) == 0 && ($diffLine['gross_amount'] ?? 0) != 0 && $diffQty != 0) {
                                $diffLine['unit_price_gross'] = round($diffLine['gross_amount'] / $diffQty, 4);
                            }
                        }
                    }
                    unset($diffLine);
                }

                // Apply the same unit price calculations as getLineItems()
                foreach ($lines as &$rLine) {
                    if ($this->isNullOrBlank($rLine['unit_price_net'] ?? null) && !$this->isNullOrBlank($rLine['net_amount'] ?? null) && !$this->isNullOrBlank($rLine['quantity'] ?? null) && $rLine['quantity'] != 0) {
                        $rLine['unit_price_net'] = round($rLine['net_amount'] / $rLine['quantity'], 4);
                    }
                    $vatRate = isset($rLine['vat_rate']) && is_numeric($rLine['vat_rate']) ? (float)$rLine['vat_rate'] : 0;
                    $vatMultiplier = 1 + $vatRate / 100;
                    if ($this->isNullOrBlank($rLine['net_amount'] ?? null) && !$this->isNullOrBlank($rLine['gross_amount'] ?? null) && $vatMultiplier > 0) {
                        $rLine['net_amount'] = round($rLine['gross_amount'] / $vatMultiplier, 2);
                    }
                    if ($this->isNullOrBlank($rLine['unit_price_net'] ?? null) && !$this->isNullOrBlank($rLine['unit_price_gross'] ?? null) && $vatMultiplier > 0) {
                        $rLine['unit_price_net'] = round($rLine['unit_price_gross'] / $vatMultiplier, 4);
                    }
                }
                unset($rLine);
            } else {
                $lines = $this->getLineItems();
            }
        } else {
            $lines = $this->getLineItems();
        }
        $error = 0;

        // For credit notes, Dolibarr's addline() forces -abs() on all amounts, making it impossible
        // to have mixed-sign lines. Detect this and fall back to VAT summary lines when needed.
        $useSummaryLines = false;
        if ($facture->type === FactureFournisseur::TYPE_CREDIT_NOTE && !empty($lines)) {
            foreach ($lines as $line) {
                $lineNet = isset($line['net_amount']) ? (float)$line['net_amount'] : 0;
                if ($lineNet > 0) {
                    $useSummaryLines = true;
                    break;
                }
            }
        }

        if ($useSummaryLines) {
            // Mixed-sign lines detected on a credit note - Dolibarr forces -abs() on amounts
            // so we can't use the XML lines directly. Instead:
            // 1) Add each XML line item as a zero-value descriptive line showing what was corrected
            // 2) Add the actual correction amount per VAT rate from the invoice totals

            // Add descriptive lines for each XML line item (price=0, purely informational)
            foreach ($lines as $line) {
                $lineDesc = $line['description'] ?? '';
                $lineNet = isset($line['net_amount']) ? (float)$line['net_amount'] : 0;
                $lineQty = isset($line['quantity']) ? (float)$line['quantity'] : 0;
                $lineUnit = $line['unit'] ?? '';
                $lineRef = $line['indeks'] ?? '';

                // Build a descriptive text showing the item and its correction delta
                $deltaSign = $lineNet >= 0 ? '+' : '';
                $desc = $lineDesc;
                $desc .= ' (' . $langs->trans('KSEF_CorrectionItemDetail', $deltaSign . price($lineNet, 0, $langs, 0, -1, -1, $this->currency), $lineQty, $lineUnit) . ')';

                $result = $facture->addline(
                    $desc, 0, 0,
                    0, 0,           // localtax1, localtax2
                    0,              // qty = 0 (informational only)
                    0,              // fk_product
                    0, 0, 0, 0, 0, // remise, date_start, date_end, ventilation, info_bits
                    'HT',
                    1,              // type = service
                    -1, 0, array(), null, 0,
                    0, $lineRef
                );

                if ($result <= 0) {
                    $error++;
                    $this->error = $facture->error;
                    $this->errors = $facture->errors;
                    break;
                }
            }

            // Add the actual correction amounts per VAT rate
            $vatSummary = $reParsedVatSummary ?: $this->getVatSummary();
            foreach ($vatSummary as $rate => $amounts) {
                $netAmount = abs((float)($amounts['net'] ?? 0));
                if ($netAmount == 0) continue;

                $desc = $langs->trans('KSEF_CorrectionSummaryLine', $rate . '%');
                $txtva = is_numeric($rate) ? (float)$rate : 0;

                if ($isMulticurrency) {
                    $pu = 0;
                    $pu_devise = $netAmount;
                } else {
                    $pu = $netAmount;
                    $pu_devise = 0;
                }

                $result = $facture->addline(
                    $desc, $pu, $txtva,
                    0, 0,           // localtax1, localtax2
                    1,              // qty
                    0,              // fk_product
                    0, 0, 0, 0, 0, // remise, date_start, date_end, ventilation, info_bits
                    'HT',
                    1,              // type = service
                    -1, 0, array(), null, 0,
                    $pu_devise, ''
                );

                if ($result <= 0) {
                    $error++;
                    $this->error = $facture->error;
                    $this->errors = $facture->errors;
                    break;
                }
            }
        } else {
            // Normal path: one Dolibarr line per XML line item
            foreach ($lines as $line) {
                $lineNum = $line['line_num'] ?? 0;
                $fk_product = isset($lineProductMap[$lineNum]) ? (int)$lineProductMap[$lineNum] : 0;

                $type = 0;
                if ($fk_product > 0) {
                    $product = new Product($this->db);
                    if ($product->fetch($fk_product) > 0) {
                        $type = $product->type;
                    }
                }

                $desc = $line['description'] ?? '';
                $xmlPrice = isset($line['unit_price_net']) ? (float)$line['unit_price_net'] : 0;
                $txtva = isset($line['vat_rate']) && is_numeric($line['vat_rate']) ? (float)$line['vat_rate'] : 0;
                $qty = isset($line['quantity']) ? (float)$line['quantity'] : 1;
                $ref_supplier = $line['indeks'] ?? '';

                if ($isMulticurrency) {
                    $pu = 0;
                    $pu_devise = $xmlPrice;
                } else {
                    $pu = $xmlPrice;
                    $pu_devise = 0;
                }

                $result = $facture->addline(
                    $desc,          // description
                    $pu,            // unit price HT
                    $txtva,         // VAT rate
                    0,              // localtax1
                    0,              // localtax2
                    $qty,           // quantity
                    $fk_product,    // product id
                    0,              // remise_percent
                    0,              // date_start
                    0,              // date_end
                    0,              // fk_code_ventilation
                    0,              // info_bits
                    'HT',           // price_base_type
                    $type,          // type (product/service)
                    -1,             // rang
                    0,              // notrigger
                    array(),        // array_options
                    null,           // fk_unit
                    0,              // origin_id
                    $pu_devise,     // pu_devise
                    $ref_supplier   // ref_supplier
                );

                if ($result <= 0) {
                    $error++;
                    $this->error = $facture->error;
                    $this->errors = $facture->errors;
                    break;
                }
            }
        }

        if ($error) {
            $this->import_status = self::STATUS_ERROR;
            $this->import_error = $this->error;
            $this->db->rollback();
            return -4;
        }

        $facture->array_options['options_ksef_number'] = $this->ksef_number;
        $facture->array_options['options_ksef_status'] = 'ACCEPTED';
        $facture->array_options['options_ksef_submission_date'] = $this->fa3_creation_date;
        $facture->array_options['options_ksef_sale_date'] = $this->sale_date;
        $facture->insertExtraFields();

        // Attach generated KSeF PDF visualization to the supplier invoice
        $this->attachPdfToSupplierInvoice($facture);

        $this->import_status = self::STATUS_IMPORTED;
        $this->import_date = dol_now();
        $this->fk_facture_fourn = $facture->id;
        $this->import_error = null;

        $updResult = $this->update($user, 1);
        if ($updResult < 0) {
            $this->db->rollback();
            return -5;
        }

        $this->db->commit();
        return $facture->id;
    }


    /**
     * @brief Attach KSeF PDF visualization to a supplier invoice's document area
     * @param FactureFournisseur $facture The supplier invoice to attach the PDF to
     * @return bool True on success, false on error (non-fatal, logged only)
     * @called_by importToDolibarr()
     */
    private function attachPdfToSupplierInvoice($facture)
    {
        global $conf;

        $pdfContent = $this->generatePdfVisualization();
        if ($pdfContent === false) {
            dol_syslog("KsefIncoming::attachPdfToSupplierInvoice PDF generation failed: " . $this->error, LOG_WARNING);
            return false;
        }

        $ref = dol_sanitizeFileName($facture->ref);
        $dir = $conf->fournisseur->facture->dir_output . '/' . get_exdir($facture->id, 2, 0, 0, $facture, 'invoice_supplier') . $ref;

        if (dol_mkdir($dir) < 0) {
            dol_syslog("KsefIncoming::attachPdfToSupplierInvoice mkdir failed for: " . $dir, LOG_WARNING);
            return false;
        }

        $filename = dol_sanitizeFileName('ksef_' . $this->invoice_number . '.pdf');
        $fullPath = $dir . '/' . $filename;

        if (file_put_contents($fullPath, $pdfContent) === false) {
            dol_syslog("KsefIncoming::attachPdfToSupplierInvoice write failed: " . $fullPath, LOG_WARNING);
            return false;
        }

        $facture->indexFile($fullPath, 0);

        dol_syslog("KsefIncoming::attachPdfToSupplierInvoice attached " . $filename . " to invoice " . $facture->ref, LOG_INFO);
        return true;
    }


    /**
     * @brief Reset import status back to NEW when the linked supplier invoice has been deleted
     * @param User $user User performing the reset
     * @return int 1 on success, negative on error
     */
    public function resetImportStatus($user)
    {
        global $langs;

        if ($this->fk_facture_fourn > 0) {
            require_once DOL_DOCUMENT_ROOT . '/fourn/class/fournisseur.facture.class.php';
            $check = new FactureFournisseur($this->db);
            if ($check->fetch($this->fk_facture_fourn) > 0) {
                $this->error = $langs->trans('KSEF_LinkedInvoiceStillExists');
                return -1;
            }
        }

        $this->import_status = self::STATUS_NEW;
        $this->import_date = null;
        $this->import_error = null;
        $this->fk_facture_fourn = null;
        $this->fk_credit_note = null;

        return $this->update($user);
    }


    /**
     * @brief Auto-create a supplier (Societe) from the invoice's seller data
     * @param User $user User performing the creation
     * @param $gusData Optional GUS lookup result override
     * @return int New societe ID on success, negative on error
     */
    public function autoCreateSupplier($user, $gusData = null)
    {
        require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';

        $societe = new Societe($this->db);
        $societe->name = $this->seller_name;
        $societe->fournisseur = 1;
        $societe->client = 0;
        $societe->code_fournisseur = '-1';

        if (!empty($this->seller_nip) && $this->seller_country == 'PL') {
            $nipField = ksefGetFieldName('NIP');
            if (!empty($nipField) && $nipField !== 'tva_intra') {
                $societe->$nipField = $this->seller_nip;
            }
        }
        if (!empty($this->seller_vat_id)) {
            $societe->tva_intra = $this->seller_vat_id;
        } elseif (!empty($this->seller_nip) && $this->seller_country != 'PL') {
            $societe->tva_intra = ($this->seller_country ?: '') . $this->seller_nip;
        }
        $nipField = ksefGetFieldName('NIP');
        if ($nipField === 'tva_intra' && empty($societe->tva_intra) && !empty($this->seller_nip) && $this->seller_country == 'PL') {
            $societe->tva_intra = $this->seller_nip;
        }

        // Set KRS/REGON/BDO from parsed XML
        if (!empty($this->fa3_xml)) {
            dol_include_once('/ksef/class/fa3_parser.class.php');
            $parser = new FA3Parser($this->db);
            $parsed = $parser->parse($this->fa3_xml);
            if (!empty($parsed) && !empty($parsed['registries'])) {
                $reg = $parsed['registries'];
                foreach (array('KRS' => 'krs', 'REGON' => 'regon', 'BDO' => 'bdo') as $ident => $key) {
                    if (!empty($reg[$key])) {
                        $field = ksefGetFieldName($ident);
                        if (!empty($field) && $field !== 'tva_intra') {
                            $societe->$field = trim($reg[$key]);
                        }
                    }
                }
            }
        }

        if (!empty($this->seller_address)) {
            $societe->address = $this->seller_address;
        }

        if (!empty($this->seller_country)) {
            require_once DOL_DOCUMENT_ROOT . '/core/lib/company.lib.php';
            $countryId = getCountry($this->seller_country, '3');
            if ($countryId > 0) {
                $societe->country_id = $countryId;
                $societe->country_code = $this->seller_country;
            }
        }

        // Override with GUS data
        if (!empty($gusData) && is_array($gusData)) {
            if (!empty($gusData['name'])) {
                $societe->name = $gusData['name'];
            }
            if (!empty($gusData['address'])) {
                $societe->address = $gusData['address'];
            }
            if (!empty($gusData['zip'])) {
                $societe->zip = $gusData['zip'];
            }
            if (!empty($gusData['town'])) {
                $societe->town = $gusData['town'];
            }
            foreach (array('REGON' => 'regon', 'KRS' => 'krs') as $ident => $key) {
                if (!empty($gusData[$key])) {
                    $field = ksefGetFieldName($ident);
                    if (!empty($field) && $field !== 'tva_intra') {
                        $societe->$field = trim($gusData[$key]);
                    }
                }
            }
        }

        $result = $societe->create($user);
        if ($result <= 0) {
            $this->error = $societe->error;
            $this->errors = $societe->errors;
            return -1;
        }

        return $result;
    }


    /**
     * @brief Auto-create a product from invoice line data
     * @param User $user User performing the creation
     * @param array $lineData Line item data from getLineItems()
     * @return int New product ID on success, negative on error
     */
    public function autoCreateProduct($user, $lineData)
    {
        require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';

        $product = new Product($this->db);

        $useIndeks = getDolGlobalString('KSEF_PRODUCT_REF_USE_INDEKS', '1') === '1';
        if ($useIndeks && !empty($lineData['indeks'])) {
            $product->ref = $lineData['indeks'];
        } else {
            $product->ref = $product->getNextNumRef(null);
            if (empty($product->ref)) {
                $product->ref = 'KSEF-' . dol_now() . '-' . ($lineData['line_num'] ?? 0);
            }
        }

        $product->label = $lineData['description'] ?? $product->ref;
        $product->type = 0;
        $product->status = 1;
        $product->status_buy = 1;

        if (!empty($lineData['unit_price_net'])) {
            $product->price = (float)$lineData['unit_price_net'];
            $product->price_base_type = 'HT';
        }

        if (isset($lineData['vat_rate']) && is_numeric($lineData['vat_rate'])) {
            $product->tva_tx = (float)$lineData['vat_rate'];
        }

        if (!empty($lineData['gtin'])) {
            $product->barcode = $lineData['gtin'];
        }

        $result = $product->create($user);
        if ($result <= 0) {
            $this->error = $product->error;
            $this->errors = $product->errors;
            return -1;
        }

        return $result;
    }


    /**
     * @brief Generate PDF visualization of the invoice
     * @return string|false PDF content as string or false on error
     */
    public function generatePdfVisualization()
    {
        dol_include_once('/ksef/class/ksef_invoice_pdf.class.php');

        if (empty($this->rowid)) {
            $this->error = "Invoice not loaded";
            return false;
        }

        $pdfGenerator = new KsefInvoicePdf($this->db);
        $pdfContent = $pdfGenerator->generate($this);

        if ($pdfContent === false) {
            $this->error = $pdfGenerator->error ?: 'PDF generation failed';
            return false;
        }

        return $pdfContent;
    }

    /**
     * Map KSeF FormaPlatnosci code to Dolibarr c_paiement ID
     * @param string|null $ksefMethod KSeF payment method code (1-7)
     * @return int Dolibarr c_paiement ID, or 0 if no suitable mapping
     */
    public function mapKsefPaymentMethod($ksefMethod)
    {
        $map = array(
            '1' => 'LIQ',  // Gotówka -> Cash
            '2' => 'CB',   // Karta -> Credit card
            '3' => 'LIQ',  // Bon -> Cash
            '4' => 'CHQ',  // Czek -> Cheque
            '5' => 'VIR',  // Kredyt -> Bank Transfer
            '6' => 'VIR',  // Przelew -> Bank Transfer
            '7' => 'CB',   // Mobilna -> Credit card
        );

        $code = isset($map[$ksefMethod]) ? $map[$ksefMethod] : 'VIR';

        $sql = "SELECT id FROM " . MAIN_DB_PREFIX . "c_paiement"
            . " WHERE code = '" . $this->db->escape($code) . "'"
            . " AND active = 1 LIMIT 1";
        $resql = $this->db->query($sql);
        if ($resql && $this->db->num_rows($resql) > 0) {
            $obj = $this->db->fetch_object($resql);
            return (int)$obj->id;
        }
        return 0;
    }
}
