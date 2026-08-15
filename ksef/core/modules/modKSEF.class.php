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
 * \defgroup   ksef     KSEF module
 * \brief      KSEF module
 *
 * \file       core/modules/modKSEF.class.php
 * \ingroup    ksef
 * \brief      KSEF module
 */
require_once DOL_DOCUMENT_ROOT . '/core/modules/DolibarrModules.class.php';

class modKSEF extends DolibarrModules
{
    public $url_last_version;
    public $tabs;
    public $dictionaries;

    public function __construct($db)
    {
        global $langs, $conf;
        $this->db = $db;

        $this->numero = 592189;
        $this->rights_class = 'ksef';
        $this->family = "financial";
        $this->module_position = '90';
        $this->name = preg_replace('/^mod/i', '', get_class($this));
        $this->description = "Polish KSEF (Krajowy System e-Faktur) integration module for Dolibarr";
        $this->descriptionlong = "Submit invoices to Polish KSEF system";
        $this->editor_name = 'InPoint Automation';
        $this->editor_url = 'https://inpointautomation.com';
        $this->version = '1.4.5';
        $this->url_last_version = '';
        $this->const_name = 'MAIN_MODULE_' . strtoupper($this->name);
        $this->picto = 'ksef@ksef';

        $this->module_parts = array(
            'triggers' => 1,
            'api' => 1,
            'css' => array('/ksef/css/ksef.css'),
            'login' => 0,
            'substitutions' => 0,
            'menus' => 0,
            'tpl' => 0,
            'barcode' => 0,
            'models' => 0,
            'printing' => 0,
            'theme' => 0,
            'actions' => array('/ksef/class/actions_ksef.class.php@ActionsKSEF'),
            'hooks' => array(
                'invoicecard',
                'invoicelist',
                'invoicenote',
                'invoicesuppliercard',
                'completeTabsHead',
                'pdfgeneration',
                'afterPDFTotalTable',
                'thirdpartycard',
                'sellsjournal',
                'recapcomptacard',
                'externalbalance',
                'customersupplierreportlist',
            ),
            'moduleforexternal' => 0,
        );

        $this->dirs = array("/ksef/temp");
        $this->config_page_url = array("setup.php@ksef");

        $this->hidden = false;
        $this->depends = array('modFacture');
        $this->requiredby = array();
        $this->conflictwith = array();

        $this->langfiles = array("ksef@ksef");

        $this->phpmin = array(7, 4);
        $this->need_dolibarr_version = array(14, 0);

        $this->warnings_activation = array();
        $this->warnings_activation_ext = array();

        $this->const = array();


        if (!isset($conf->ksef) || !isset($conf->ksef->enabled)) {
            $conf->ksef = new stdClass();
            $conf->ksef->enabled = 0;
        }

        $this->tabs = array(
            'invoice:+ksef:KSEF:ksef@ksef:$user->hasRight("ksef", "read"):/ksef/tab_ksef.php?id=__ID__'
        );

        $this->dictionaries = array();
        $this->boxes = array();

        $this->cronjobs = array(
            0 => array(
                'label'          => 'KSEF - Check pending/submitted invoice statuses',
                'jobtype'        => 'method',
                'class'          => '/ksef/class/ksef_service.class.php',
                'objectname'     => 'KsefService',
                'method'         => 'cronCheckStatuses',
                'parameters'     => '',
                'comment'        => 'Poll KSeF for status of PENDING/SUBMITTED/TIMEOUT invoices',
                'frequency'      => 30,
                'unitfrequency'  => 60,
                'status'         => 1,
                'test'           => 'isModEnabled("ksef")',
                'priority'       => 50,
            ),
            1 => array(
                'label'          => 'KSEF - Sync incoming invoices',
                'jobtype'        => 'method',
                'class'          => '/ksef/class/ksef_service.class.php',
                'objectname'     => 'KsefService',
                'method'         => 'cronSyncIncoming',
                'parameters'     => '',
                'comment'        => 'Fetch new incoming invoices from KSeF',
                'frequency'      => 4,
                'unitfrequency'  => 3600,
                'status'         => 0,
                'test'           => 'isModEnabled("ksef")',
                'priority'       => 50,
            ),
            2 => array(
                'label'          => 'KSEF - Download UPO confirmations',
                'jobtype'        => 'method',
                'class'          => '/ksef/class/ksef_service.class.php',
                'objectname'     => 'KsefService',
                'method'         => 'cronDownloadUPOs',
                'parameters'     => '',
                'comment'        => 'Download UPO XML for accepted submissions that lack it',
                'frequency'      => 6,
                'unitfrequency'  => 3600,
                'status'         => 0,
                'test'           => 'isModEnabled("ksef")',
                'priority'       => 50,
            ),
            3 => array(
                'label'          => 'KSEF - Warn approaching offline deadlines',
                'jobtype'        => 'method',
                'class'          => '/ksef/class/ksef_service.class.php',
                'objectname'     => 'KsefService',
                'method'         => 'cronWarnDeadlines',
                'parameters'     => '',
                'comment'        => 'Log warnings for offline invoices with deadlines in next 24h',
                'frequency'      => 1,
                'unitfrequency'  => 3600,
                'status'         => 1,
                'test'           => 'isModEnabled("ksef")',
                'priority'       => 50,
            ),
            4 => array(
                'label'          => 'KSEF - Check Latarnia (lighthouse) system status',
                'jobtype'        => 'method',
                'class'          => '/ksef/class/ksef_service.class.php',
                'objectname'     => 'KsefService',
                'method'         => 'cronCheckLatarniaStatus',
                'parameters'     => '',
                'comment'        => 'Poll KSeF Latarnia API for system availability status',
                'frequency'      => 15,
                'unitfrequency'  => 60,
                'status'         => 1,
                'test'           => 'isModEnabled("ksef")',
                'priority'       => 50,
            ),
            5 => array(
                'label'          => 'KSEF - Sync NBP currency rates (D-1)',
                'jobtype'        => 'method',
                'class'          => '/ksef/class/ksef_nbp_currency_rate.class.php',
                'objectname'     => 'KsefNbpCurrencyRate',
                'method'         => 'cronSyncMulticurrencyRates',
                'parameters'     => '',
                'comment'        => 'Fetch D-1 NBP rates for the Multicurrency module currencies into llx_multicurrency_rate',
                'frequency'      => 1,
                'unitfrequency'  => 86400,
                'status'         => 0,
                'test'           => 'isModEnabled("ksef") && isModEnabled("multicurrency")',
                'priority'       => 50,
            ),
        );

        $this->rights = array();
        $r = 0;

        $this->rights[$r][0] = $this->numero + $r;
        $this->rights[$r][1] = 'Read KSEF submissions';
        $this->rights[$r][4] = 'read';
        $this->rights[$r][5] = '1';
        $r++;

        $this->rights[$r][0] = $this->numero + $r;
        $this->rights[$r][1] = 'Create/Update KSEF submissions';
        $this->rights[$r][4] = 'write';
        $this->rights[$r][5] = '1';
        $r++;

        $this->menu = array();
        $r = 0;

        $this->menu[$r++] = array(
            'fk_menu' => 'fk_mainmenu=billing',
            'type' => 'left',
            'titre' => 'KSEF',
            'prefix' => '<i class="fas fa-file-invoice fa-fw paddingright"></i>',
            'mainmenu' => 'billing',
            'leftmenu' => 'ksef',
            'url' => '/ksef/ksefindex.php',
            'langs' => 'ksef@ksef',
            'position' => 1000 + $r,
            'enabled' => '$conf->ksef->enabled',
            'perms' => '1',
            'target' => '',
            'user' => 2,
        );

        $this->menu[$r++] = array(
            'fk_menu' => 'fk_mainmenu=billing,fk_leftmenu=ksef',
            'type' => 'left',
            'titre' => 'KSEF_MenuSubmissionStatus',
            'mainmenu' => 'billing',
            'leftmenu' => 'ksef_status',
            'url' => '/ksef/status.php',
            'langs' => 'ksef@ksef',
            'position' => 1000 + $r,
            'enabled' => '$conf->ksef->enabled',
            'perms' => '$user->hasRight("ksef", "read")',
            'target' => '',
            'user' => 2,
        );

        $this->menu[$r++] = array(
            'fk_menu' => 'fk_mainmenu=billing,fk_leftmenu=ksef',
            'type' => 'left',
            'titre' => 'KSEF_IncomingInvoices',
            'mainmenu' => 'billing',
            'leftmenu' => 'ksef_incoming',
            'url' => '/ksef/incoming_list.php',
            'langs' => 'ksef@ksef',
            'position' => 1000 + $r,
            'enabled' => '$conf->ksef->enabled',
            'perms' => '$user->hasRight("ksef", "read")',
            'target' => '',
            'user' => 2,
        );

        $this->menu[$r++] = array(
            'fk_menu' => 'fk_mainmenu=billing,fk_leftmenu=ksef',
            'type' => 'left',
            'titre' => 'KSEF_HowToUse',
            'mainmenu' => 'billing',
            'leftmenu' => 'ksef_howto',
            'url' => '/ksef/admin/howtouse.php',
            'langs' => 'ksef@ksef',
            'position' => 1000 + $r,
            'enabled' => '$conf->ksef->enabled',
            'perms' => '$user->hasRight("ksef", "read")',
            'target' => '',
            'user' => 2,
        );

        $this->menu[$r++] = array(
            'fk_menu' => 'fk_mainmenu=billing,fk_leftmenu=ksef',
            'type' => 'left',
            'titre' => 'KSEF_MenuConfiguration',
            'mainmenu' => 'billing',
            'leftmenu' => 'ksef_config',
            'url' => '/ksef/admin/setup.php',
            'langs' => 'ksef@ksef',
            'position' => 1000 + $r,
            'enabled' => '$conf->ksef->enabled',
            'perms' => '$user->admin',
            'target' => '',
            'user' => 2,
        );

        $this->export_code = array();
        $this->export_label = array();
        $this->import_code = array();
        $this->import_label = array();
    }

    public function init($options = '')
    {
        global $conf, $langs, $db;

        $sql = array();
        $langs->loadLangs(array("ksef@ksef"));

        // remove old cron job processPendingSubmissions
        $sql[] = "DELETE FROM " . MAIN_DB_PREFIX . "cronjob"
            . " WHERE methodename = 'processPendingSubmissions'"
            . " AND classesname LIKE '%ksef%'";

        $result = $this->_load_tables('/ksef/sql/');
        if ($result < 0) return -1;

        $persistentDefaults = array(
            // PDF & QR Settings
            'KSEF_ADD_TO_PDF'        => '1',
            'KSEF_ADD_QR'            => '1',
            'KSEF_QR_SIZE'           => '25',

            // UI Customization
            'KSEF_BUTTON_COLOR'      => '#dc3545',

            // Purge all configuration when module is disabled?
            'KSEF_PURGE_ON_DISABLE'  => '0',

            // Company & Environment
            'KSEF_COMPANY_NIP'       => '',
            'KSEF_COMPANY_KRS'       => '',
            'KSEF_COMPANY_REGON'     => '',
            'KSEF_COMPANY_BDO'       => '',
            'KSEF_COMPANY_EORI'      => '',

            // Field Mapping
            'KSEF_FIELD_NIP'         => 'idprof1',
            'KSEF_FIELD_KRS'         => 'idprof2',
            'KSEF_FIELD_REGON'       => 'idprof3',
            'KSEF_FIELD_BDO'         => 'idprof4',
            'KSEF_FIELD_EORI'        => 'idprof5',
            'KSEF_ENVIRONMENT'       => 'DEMO',

            // Customer Exclusions
            'KSEF_EXCLUDED_CUSTOMERS' => '',

            // FA3 Optional Fields
            'KSEF_FA3_INCLUDE_NRKLIENTA'   => '0',
            'KSEF_FA3_INCLUDE_INDEKS'      => '0',
            'KSEF_FA3_INCLUDE_GTIN'        => '0',
            'KSEF_FA3_INCLUDE_UNIT'        => '0',
            'KSEF_FA3_INCLUDE_BANK_DESC'   => '0',
            'KSEF_FA3_PLACE_OF_ISSUE_MODE' => 'disabled',
            'KSEF_FA3_PLACE_OF_ISSUE_CUSTOM' => '',
            'KSEF_FA3_SALE_DATE_SOURCE'      => 'delivery_date',
            'KSEF_NBP_RATE_MODE'           => 'keep_base',

            'KSEF_DEFAULT_CORRECTION_REASON' => '',
            'KSEF_DEFAULT_CORRECTION_TYPE'   => '',
            'KSEF_KOR_LINE_METHOD'           => 'stanprzed',
            'KSEF_IDNABYWCY_SOURCE'          => 'disabled',
            'KSEF_TP_SOURCE'                 => 'disabled',

            'KSEF_FA3_MPP_SOURCE'            => 'disabled',
            'KSEF_FA3_FP_SOURCE'             => 'disabled',
            'KSEF_P17_SOURCE'                => 'disabled',
            'KSEF_P17_TP_SOURCE'             => 'disabled',
            'KSEF_P16_SOURCE'                => 'disabled',
            'KSEF_P18_SOURCE'                => 'disabled',
            'KSEF_P18_TP_SOURCE'             => 'disabled',

            'KSEF_PODMIOT3_SOURCE'           => 'disabled',
            'KSEF_PODMIOT3_ROLE'             => '6',
            'KSEF_IDWEW_SOURCE'              => 'disabled',

            // GUS / REGON Lookup
            'KSEF_GUS_ENABLED'               => '0',
            'KSEF_GUS_ENV'                   => 'TEST',
            'KSEF_GUS_KEY'                   => '',
            'KSEF_GUS_FETCH_SUPPLIERS'       => '0',
            'KSEF_GUS_NAME_STYLE'            => 'full',
        );

        foreach ($persistentDefaults as $name => $defaultValue) {
            $sql_check = "SELECT COUNT(*) as cnt FROM " . MAIN_DB_PREFIX . "const"
                . " WHERE name = '" . $db->escape($name) . "'"
                . " AND entity = " . (int) $conf->entity;
            $resql = $db->query($sql_check);
            if ($resql) {
                $obj = $db->fetch_object($resql);
                if ($obj->cnt == 0) {
                    dolibarr_set_const($db, $name, $defaultValue,
                        'chaine', 0, '', $conf->entity);
                }
                $db->free($resql);
            }
        }

        // Visible constants
        $visibleDefaults = array(
            'KSEF_TIMEOUT' => array('value' => '7', 'note' => 'KSeF API timeout in seconds'),
        );
        foreach ($visibleDefaults as $name => $def) {
            $sql_check = "SELECT COUNT(*) as cnt FROM " . MAIN_DB_PREFIX . "const"
                . " WHERE name = '" . $db->escape($name) . "'"
                . " AND entity = " . (int) $conf->entity;
            $resql = $db->query($sql_check);
            if ($resql) {
                $obj = $db->fetch_object($resql);
                if ($obj->cnt == 0) {
                    dolibarr_set_const($db, $name, $def['value'],
                        'chaine', 1, $def['note'], $conf->entity);
                }
                $db->free($resql);
            }
        }

        include_once DOL_DOCUMENT_ROOT . '/core/class/extrafields.class.php';
        $extrafields = new ExtraFields($db);

        $extraFieldDefs = array(
            'ksef_number' => array(
                'label' => 'KSEF_ExtraFieldNumber',
                'type' => 'varchar',
                'pos' => 100,
                'size' => '255',
                'unique' => 0,
                'required' => 0,
                'default_value' => '',
                'param' => '',
                'alwayseditable' => 1,
                'perms' => '',
                'list' => '1',
                'help' => '',
                'computed' => '',
                'entity' => '',
                'langfile' => 'ksef@ksef',
                'enabled' => '$conf->ksef->enabled',
                'totalizable' => 0,
                'printable' => 2,
            ),
            'ksef_status' => array(
                'label' => 'KSEF_ExtraFieldStatus',
                'type' => 'select',
                'pos' => 101,
                'size' => '',
                'unique' => 0,
                'required' => 0,
                'default_value' => 'PENDING',
                'param' => array('options' => array(
                    'PENDING' => 'KSEF_StatusPending',
                    'ACCEPTED' => 'KSEF_StatusAccepted',
                    'REJECTED' => 'KSEF_StatusRejected',
                    'FAILED' => 'KSEF_StatusFailed',
                    'OFFLINE' => 'KSEF_StatusOffline',
                    'VALIDATION_FAILED' => 'KSEF_StatusValidationFailed'
                )),
                'alwayseditable' => 1,
                'perms' => '',
                'list' => '1',
                'help' => '',
                'computed' => '',
                'entity' => '',
                'langfile' => 'ksef@ksef',
                'enabled' => '$conf->ksef->enabled',
                'totalizable' => 0,
                'printable' => 0,
            ),
            'ksef_submission_date' => array(
                'label' => 'KSEF_ExtraFieldSubmissionDate',
                'type' => 'datetime',
                'pos' => 102,
                'size' => '',
                'unique' => 0,
                'required' => 0,
                'default_value' => '',
                'param' => '',
                'alwayseditable' => 0,
                'perms' => '',
                'list' => '1',
                'help' => '',
                'computed' => '',
                'entity' => '',
                'langfile' => 'ksef@ksef',
                'enabled' => '$conf->ksef->enabled',
                'totalizable' => 0,
                'printable' => 0,
            ),
            'ksef_kurs_data' => array(
                'label' => 'KSEF_ExtraFieldKursData',
                'type' => 'date',
                'pos' => 103,
                'size' => '',
                'unique' => 0,
                'required' => 0,
                'default_value' => '',
                'param' => '',
                'alwayseditable' => 1,
                'perms' => '',
                'list' => '1',
                'help' => 'KSEF_ExtraFieldKursDataHelp',
                'computed' => '',
                'entity' => '',
                'langfile' => 'ksef@ksef',
                'enabled' => 'isModEnabled("multicurrency") && isModEnabled("ksef")',
                'totalizable' => 0,
                'printable' => 2,
            ),
            'ksef_sale_date' => array(
                'label' => 'KSEF_ExtraFieldSaleDate',
                'type' => 'date',
                'pos' => 104,
                'size' => '',
                'unique' => 0,
                'required' => 0,
                'default_value' => '',
                'param' => '',
                'alwayseditable' => 1,
                'perms' => '',
                'list' => '1',
                'help' => 'KSEF_ExtraFieldSaleDateHelp',
                'computed' => '',
                'entity' => '',
                'langfile' => 'ksef@ksef',
                'enabled' => '$conf->ksef->enabled',
                'totalizable' => 0,
                'printable' => 2,
            ),
            'ksef_dodatkowy_opis_mode' => array(
                'label' => 'KSEF_ExtraFieldDodatkowyOpisMode',
                'type' => 'select',
                'pos' => 110,
                'size' => '',
                'unique' => 0,
                'required' => 0,
                'default_value' => '',
                'param' => array('options' => array(
                    ''                   => 'KSEF_DodatkowyOpisMode_Default',
                    'simple_stopka'      => 'KSEF_DodatkowyOpisMode_SimpleStopka',
                    'simple_dodatkowy'   => 'KSEF_DodatkowyOpisMode_SimpleDodatkowy',
                    'keyvalue_dodatkowy' => 'KSEF_DodatkowyOpisMode_KeyValueDodatkowy',
                    'disabled'           => 'KSEF_DodatkowyOpisMode_Disabled',
                )),
                'alwayseditable' => 1,
                'perms' => '',
                'list' => '0',
                'help' => 'KSEF_ExtraFieldDodatkowyOpisModeHelp',
                'computed' => '',
                'entity' => '',
                'langfile' => 'ksef@ksef',
                'enabled' => '$conf->ksef->enabled',
                'totalizable' => 0,
                'printable' => 0,
                'element_types' => array('facture'),
            ),
            'ksef_correction_reason' => array(
                'label' => 'KSEF_ExtraFieldCorrectionReason',
                'type' => 'varchar',
                'pos' => 106,
                'size' => '256',
                'unique' => 0,
                'required' => 0,
                'default_value' => '',
                'param' => '',
                'alwayseditable' => 1,
                'perms' => '',
                'list' => '0',
                'help' => 'KSEF_CorrectionReasonHelp',
                'computed' => '',
                'entity' => '',
                'langfile' => 'ksef@ksef',
                'enabled' => '$conf->ksef->enabled',
                'totalizable' => 0,
                'printable' => 0,
                'element_types' => array('facture'),
            ),
            'ksef_podmiot3' => array(
                'label' => 'KSEF_Podmiot3_Field',
                'type' => 'text',
                'pos' => 510,
                'size' => '',
                'unique' => 0,
                'required' => 0,
                'default_value' => '',
                'param' => '',
                'alwayseditable' => 1,
                'perms' => '',
                'list' => '0',
                'help' => 'KSEF_Podmiot3_Field_Help',
                'computed' => '',
                'entity' => '',
                'langfile' => 'ksef@ksef',
                'enabled' => '$conf->ksef->enabled',
                'totalizable' => 0,
                'printable' => 0,
                'element_types' => array('facture'),
            ),
            'ksef_podmiot3_role' => array(
                'label' => 'KSEF_Podmiot3_Role_Field',
                'type' => 'varchar',
                'pos' => 511,
                'size' => '2',
                'unique' => 0,
                'required' => 0,
                'default_value' => '',
                'param' => '',
                'alwayseditable' => 1,
                'perms' => '',
                'list' => '0',
                'help' => 'KSEF_Podmiot3_Role_Field_Help',
                'computed' => '',
                'entity' => '',
                'langfile' => 'ksef@ksef',
                'enabled' => '$conf->ksef->enabled',
                'totalizable' => 0,
                'printable' => 0,
                'element_types' => array('facture'),
            ),
            'ksef_correction_type' => array(
                'label' => 'KSEF_ExtraFieldCorrectionType',
                'type' => 'select',
                'pos' => 107,
                'size' => '',
                'unique' => 0,
                'required' => 0,
                'default_value' => '',
                'param' => array('options' => array(
                    '1' => 'KSEF_CorrectionType1',
                    '2' => 'KSEF_CorrectionType2',
                    '3' => 'KSEF_CorrectionType3',
                )),
                'alwayseditable' => 1,
                'perms' => '',
                'list' => '0',
                'help' => '',
                'computed' => '',
                'entity' => '',
                'langfile' => 'ksef@ksef',
                'enabled' => '$conf->ksef->enabled',
                'totalizable' => 0,
                'printable' => 0,
                'element_types' => array('facture'),
            ),
            'ksef_correction_original_ht' => array(
                'label' => 'KSEF_CorrectionOriginalHT',
                'type' => 'double',
                'pos' => 108,
                'size' => '24,8',
                'unique' => 0,
                'required' => 0,
                'default_value' => '',
                'param' => '',
                'alwayseditable' => 0,
                'perms' => '',
                'list' => '0',
                'help' => '',
                'computed' => '',
                'entity' => '',
                'langfile' => 'ksef@ksef',
                'enabled' => '$conf->ksef->enabled',
                'totalizable' => 0,
                'printable' => 0,
                'element_types' => array('facture'),
            ),
            'ksef_correction_original_tva' => array(
                'label' => 'KSEF_CorrectionOriginalTVA',
                'type' => 'double',
                'pos' => 109,
                'size' => '24,8',
                'unique' => 0,
                'required' => 0,
                'default_value' => '',
                'param' => '',
                'alwayseditable' => 0,
                'perms' => '',
                'list' => '0',
                'help' => '',
                'computed' => '',
                'entity' => '',
                'langfile' => 'ksef@ksef',
                'enabled' => '$conf->ksef->enabled',
                'totalizable' => 0,
                'printable' => 0,
                'element_types' => array('facture'),
            ),
            'ksef_correction_original_ttc' => array(
                'label' => 'KSEF_CorrectionOriginalTTC',
                'type' => 'double',
                'pos' => 113,
                'size' => '24,8',
                'unique' => 0,
                'required' => 0,
                'default_value' => '',
                'param' => '',
                'alwayseditable' => 0,
                'perms' => '',
                'list' => '0',
                'help' => '',
                'computed' => '',
                'entity' => '',
                'langfile' => 'ksef@ksef',
                'enabled' => '$conf->ksef->enabled',
                'totalizable' => 0,
                'printable' => 0,
                'element_types' => array('facture'),
            ),
            'ksef_correction_original_discount_ids' => array(
                'label' => 'KSEF_CorrectionOriginalDiscountIds',
                'type' => 'varchar',
                'pos' => 112,
                'size' => '255',
                'unique' => 0,
                'required' => 0,
                'default_value' => '',
                'param' => '',
                'alwayseditable' => 0,
                'perms' => '',
                'list' => '0',
                'help' => '',
                'computed' => '',
                'entity' => '',
                'langfile' => 'ksef@ksef',
                'enabled' => '$conf->ksef->enabled',
                'totalizable' => 0,
                'printable' => 0,
                'element_types' => array('facture'),
            ),
            'ksef_correction_discount_id' => array(
                'label' => 'KSEF_CorrectionDiscountId',
                'type' => 'int',
                'pos' => 111,
                'size' => '',
                'unique' => 0,
                'required' => 0,
                'default_value' => '',
                'param' => '',
                'alwayseditable' => 0,
                'perms' => '',
                'list' => '0',
                'help' => '',
                'computed' => '',
                'entity' => '',
                'langfile' => 'ksef@ksef',
                'enabled' => '$conf->ksef->enabled',
                'totalizable' => 0,
                'printable' => 0,
                'element_types' => array('facture'),
            ),
            'ksef_correction_settled_amount' => array(
                'label' => 'KSEF_CorrectionSettledAmount',
                'type' => 'double',
                'pos' => 114,
                'size' => '24,8',
                'unique' => 0,
                'required' => 0,
                'default_value' => '',
                'param' => '',
                'alwayseditable' => 0,
                'perms' => '',
                'list' => '0',
                'help' => '',
                'computed' => '',
                'entity' => '',
                'langfile' => 'ksef@ksef',
                'enabled' => '$conf->ksef->enabled',
                'totalizable' => 0,
                'printable' => 0,
                'element_types' => array('facture'),
            ),
            'ksef_correction_settled_amount_mc' => array(
                'label' => 'KSEF_CorrectionSettledAmountMC',
                'type' => 'double',
                'pos' => 115,
                'size' => '24,8',
                'unique' => 0,
                'required' => 0,
                'default_value' => '',
                'param' => '',
                'alwayseditable' => 0,
                'perms' => '',
                'list' => '0',
                'help' => '',
                'computed' => '',
                'entity' => '',
                'langfile' => 'ksef@ksef',
                'enabled' => '$conf->ksef->enabled',
                'totalizable' => 0,
                'printable' => 0,
                'element_types' => array('facture'),
            ),

        );

        // Apply extrafields to both customer and supplier invoices
        $elementTypes = array('facture', 'facture_fourn');
        foreach ($elementTypes as $elementType) {
            $existingFields = $extrafields->fetch_name_optionals_label($elementType);

            foreach ($extraFieldDefs as $fieldName => $def) {
                if (isset($def['element_types']) && is_array($def['element_types']) && !in_array($elementType, $def['element_types'])) {
                    continue;
                }
                if (!isset($existingFields[$fieldName])) {
                    $result = $extrafields->addExtraField(
                        $fieldName,
                        $def['label'],
                        $def['type'],
                        $def['pos'],
                        $def['size'],
                        $elementType,
                        $def['unique'],
                        $def['required'],
                        $def['default_value'],
                        $def['param'],
                        $def['alwayseditable'],
                        $def['perms'],
                        $def['list'],
                        $def['help'],
                        $def['computed'],
                        $def['entity'],
                        $def['langfile'],
                        $def['enabled'],
                        $def['totalizable'],
                        $def['printable']
                    );
                    if ($result < 0 && $db->errno() != 'DB_ERROR_COLUMN_ALREADY_EXISTS' && $db->errno() != 'DB_ERROR_RECORD_ALREADY_EXISTS') {
                        $this->error = $extrafields->error;
                        return -1;
                    }
                } else {
                    // Update existing extrafield
                    $result = $extrafields->updateExtraField(
                        $fieldName,
                        $def['label'],
                        $def['type'],
                        $def['pos'],
                        $def['size'],
                        $elementType,
                        $def['unique'],
                        $def['required'],
                        $def['default_value'],
                        $def['param'],
                        $def['alwayseditable'],
                        $def['perms'],
                        $def['list'],
                        $def['help'],
                        $def['computed'],
                        $def['entity'],
                        $def['langfile'],
                        $def['enabled'],
                        $def['totalizable'],
                        $def['printable'],
                        array()
                    );
                    if ($result > 0) {
                        dol_syslog("modKSEF::init - Updated extrafield '$fieldName' for $elementType", LOG_INFO);
                    }
                }
            }
        }

        $societeExtraFieldDefs = array(
            'ksef_hide_contact' => array(
                'label' => 'KSEF_ExtraFieldHideContact',
                'type' => 'boolean',
                'pos' => 600,
                'size' => '',
                'unique' => 0,
                'required' => 0,
                'default_value' => '',
                'param' => '',
                'alwayseditable' => 1,
                'perms' => '',
                'list' => '3',
                'help' => 'KSEF_ExtraFieldHideContactHelp',
                'computed' => '',
                'entity' => '',
                'langfile' => 'ksef@ksef',
                'enabled' => '$conf->ksef->enabled',
                'totalizable' => 0,
                'printable' => 0,
            ),
            'ksef_hide_eori' => array(
                'label' => 'KSEF_ExtraFieldHideEori',
                'type' => 'boolean',
                'pos' => 601,
                'size' => '',
                'unique' => 0,
                'required' => 0,
                'default_value' => '',
                'param' => '',
                'alwayseditable' => 1,
                'perms' => '',
                'list' => '3',
                'help' => 'KSEF_ExtraFieldHideEoriHelp',
                'computed' => '',
                'entity' => '',
                'langfile' => 'ksef@ksef',
                'enabled' => '$conf->ksef->enabled',
                'totalizable' => 0,
                'printable' => 0,
            ),
        );

        $existingSocieteFields = $extrafields->fetch_name_optionals_label('societe');
        foreach ($societeExtraFieldDefs as $fieldName => $def) {
            if (!isset($existingSocieteFields[$fieldName])) {
                $result = $extrafields->addExtraField(
                    $fieldName,
                    $def['label'],
                    $def['type'],
                    $def['pos'],
                    $def['size'],
                    'societe',
                    $def['unique'],
                    $def['required'],
                    $def['default_value'],
                    $def['param'],
                    $def['alwayseditable'],
                    $def['perms'],
                    $def['list'],
                    $def['help'],
                    $def['computed'],
                    $def['entity'],
                    $def['langfile'],
                    $def['enabled'],
                    $def['totalizable'],
                    $def['printable']
                );
                if ($result < 0 && $db->errno() != 'DB_ERROR_COLUMN_ALREADY_EXISTS' && $db->errno() != 'DB_ERROR_RECORD_ALREADY_EXISTS') {
                    $this->error = $extrafields->error;
                    return -1;
                }
            }
        }

        // Invoice line extrafields
        $lineExtraFieldDefs = array(
            'ksef_uu_id' => array(
                'label' => 'KSEF_ExtraFieldUUID',
                'type' => 'varchar',
                'pos' => 200,
                'size' => '36',
                'unique' => 0,
                'required' => 0,
                'default_value' => '',
                'param' => '',
                'alwayseditable' => 0,
                'perms' => '',
                'list' => '0',
                'help' => '',
                'computed' => '',
                'entity' => '',
                'langfile' => 'ksef@ksef',
                'enabled' => '$conf->ksef->enabled',
                'totalizable' => 0,
                'printable' => 0,
            ),
        );

        $lineElementTypes = array('facturedet', 'facture_fourn_det');
        foreach ($lineElementTypes as $lineElementType) {
            $existingLineFields = $extrafields->fetch_name_optionals_label($lineElementType);

            foreach ($lineExtraFieldDefs as $fieldName => $def) {
                if (!isset($existingLineFields[$fieldName])) {
                    $result = $extrafields->addExtraField(
                        $fieldName,
                        $def['label'],
                        $def['type'],
                        $def['pos'],
                        $def['size'],
                        $lineElementType,
                        $def['unique'],
                        $def['required'],
                        $def['default_value'],
                        $def['param'],
                        $def['alwayseditable'],
                        $def['perms'],
                        $def['list'],
                        $def['help'],
                        $def['computed'],
                        $def['entity'],
                        $def['langfile'],
                        $def['enabled'],
                        $def['totalizable'],
                        $def['printable']
                    );
                    if ($result < 0 && $db->errno() != 'DB_ERROR_COLUMN_ALREADY_EXISTS' && $db->errno() != 'DB_ERROR_RECORD_ALREADY_EXISTS') {
                        $this->error = $extrafields->error;
                        return -1;
                    }
                }
            }
        }

        // specific migrations for version updates
        $migrations = array(
            '1.2.0' => array(
                // Add correction_data column
                function () use ($db, $conf) {
                    $sql_check = "SHOW COLUMNS FROM " . MAIN_DB_PREFIX . "ksef_incoming LIKE 'correction_data'";
                    $resql = $db->query($sql_check);
                    if ($resql && $db->num_rows($resql) == 0) {
                        $sql_alter = "ALTER TABLE " . MAIN_DB_PREFIX . "ksef_incoming ADD COLUMN correction_data MEDIUMTEXT AFTER corrected_invoice_date";
                        $db->query($sql_alter);
                        dol_syslog("modKSEF::migration 1.2.0 - Added correction_data column", LOG_INFO);
                    }
                },
                // composite index
                function () use ($db, $conf) {
                    $sql_check = "SHOW INDEX FROM " . MAIN_DB_PREFIX . "ksef_incoming WHERE Key_name = 'idx_ksef_incoming_seller_invoice'";
                    $resql = $db->query($sql_check);
                    if ($resql && $db->num_rows($resql) == 0) {
                        $db->query("CREATE INDEX idx_ksef_incoming_seller_invoice ON " . MAIN_DB_PREFIX . "ksef_incoming (seller_nip, invoice_number)");
                        dol_syslog("modKSEF::migration 1.2.0 - Added seller_nip+invoice_number index", LOG_INFO);
                    }
                },
                // Re-parse stored incoming invoices
                function () use ($db, $conf) {
                    $migrated = 0;
                    $sql_incoming = "SELECT rowid, fa3_xml, invoice_type FROM " . MAIN_DB_PREFIX . "ksef_incoming"
                        . " WHERE fa3_xml IS NOT NULL AND fa3_xml != ''"
                        . " AND entity = " . (int) $conf->entity;
                    $resql = $db->query($sql_incoming);
                    if ($resql) {
                        dol_include_once('/ksef/class/fa3_parser.class.php');
                        $parser = new FA3Parser($db);
                        while ($obj = $db->fetch_object($resql)) {
                            $parsed = $parser->parse($obj->fa3_xml);
                            if ($parsed) {
                                $updates = array();
                                if (!empty($parsed['lines'])) {
                                    $updates[] = "line_items = '" . $db->escape(json_encode($parsed['lines'])) . "'";
                                }
                                if (!empty($parsed['correction'])) {
                                    $updates[] = "correction_data = '" . $db->escape(json_encode($parsed['correction'])) . "'";
                                }
                                if (!empty($updates)) {
                                    $sql_update = "UPDATE " . MAIN_DB_PREFIX . "ksef_incoming"
                                        . " SET " . implode(", ", $updates)
                                        . " WHERE rowid = " . (int) $obj->rowid;
                                    $db->query($sql_update);
                                    $migrated++;
                                }
                            }
                        }
                        $db->free($resql);
                    }
                    dol_syslog("modKSEF::migration 1.2.0 - Re-parsed data for $migrated incoming invoices", LOG_INFO);
                },
            ),
            '1.3.0' => array(
                // seller_vat_id column
                function () use ($db, $conf) {
                    $sql_check = "SHOW COLUMNS FROM " . MAIN_DB_PREFIX . "ksef_incoming LIKE 'seller_vat_id'";
                    $resql = $db->query($sql_check);
                    if ($resql && $db->num_rows($resql) == 0) {
                        $sql_alter = "ALTER TABLE " . MAIN_DB_PREFIX . "ksef_incoming ADD COLUMN seller_vat_id VARCHAR(50) AFTER seller_nip";
                        $db->query($sql_alter);
                        dol_syslog("modKSEF::migration 1.3.0 - Added seller_vat_id column", LOG_INFO);
                    }
                },
                // Re-parse to populate seller_vat_id
                function () use ($db, $conf) {
                    $migrated = 0;
                    $sql_incoming = "SELECT rowid, fa3_xml FROM " . MAIN_DB_PREFIX . "ksef_incoming"
                        . " WHERE fa3_xml IS NOT NULL AND fa3_xml != ''"
                        . " AND entity = " . (int) $conf->entity;
                    $resql = $db->query($sql_incoming);
                    if ($resql) {
                        dol_include_once('/ksef/class/fa3_parser.class.php');
                        $parser = new FA3Parser($db);
                        while ($obj = $db->fetch_object($resql)) {
                            $parsed = $parser->parse($obj->fa3_xml);
                            if ($parsed && !empty($parsed['seller']['kod_ue']) && !empty($parsed['seller']['nr_vat_ue'])) {
                                $vatId = $parsed['seller']['kod_ue'] . $parsed['seller']['nr_vat_ue'];
                                $sql_update = "UPDATE " . MAIN_DB_PREFIX . "ksef_incoming"
                                    . " SET seller_vat_id = '" . $db->escape($vatId) . "'"
                                    . " WHERE rowid = " . (int) $obj->rowid;
                                $db->query($sql_update);
                                $migrated++;
                            }
                        }
                        $db->free($resql);
                    }
                    dol_syslog("modKSEF::migration 1.3.0 - Populated seller_vat_id for $migrated incoming invoices", LOG_INFO);
                },
                // Add fk_credit_note column for replace-mode upward corrections
                function () use ($db, $conf) {
                    $sql_check = "SHOW COLUMNS FROM " . MAIN_DB_PREFIX . "ksef_incoming LIKE 'fk_credit_note'";
                    $resql = $db->query($sql_check);
                    if ($resql && $db->num_rows($resql) == 0) {
                        $sql_alter = "ALTER TABLE " . MAIN_DB_PREFIX . "ksef_incoming ADD COLUMN fk_credit_note INTEGER DEFAULT NULL AFTER fk_facture_fourn";
                        $db->query($sql_alter);
                        dol_syslog("modKSEF::migration 1.3.0 - Added fk_credit_note column", LOG_INFO);
                    }
                },
            ),
            '1.3.2' => array(
                // Fix cron jobs
                function () use ($db, $conf) {
                    $sql = "UPDATE " . MAIN_DB_PREFIX . "cronjob"
                        . " SET objectname = 'KsefService'"
                        . " WHERE objectname = 'KSEF'"
                        . " AND classesname = '/ksef/class/ksef_service.class.php'"
                        . " AND entity IN (0, " . ((int) $conf->entity) . ")";
                    $db->query($sql);
                    dol_syslog("modKSEF::migration 1.3.2 - Fixed cron job objectname KSEF -> KsefService", LOG_INFO);
                },
                // Re-parse incoming invoices
                function () use ($db, $conf) {
                    $migrated = 0;
                    $sql_incoming = "SELECT rowid, fa3_xml FROM " . MAIN_DB_PREFIX . "ksef_incoming"
                        . " WHERE fa3_xml IS NOT NULL AND fa3_xml != ''"
                        . " AND entity = " . (int) $conf->entity;
                    $resql = $db->query($sql_incoming);
                    if ($resql) {
                        dol_include_once('/ksef/class/fa3_parser.class.php');
                        $parser = new FA3Parser($db);
                        while ($obj = $db->fetch_object($resql)) {
                            $parsed = $parser->parse($obj->fa3_xml);
                            if ($parsed) {
                                $updates = array();
                                $updates[] = "total_net = " . (float) ($parsed['invoice']['total_net'] ?? 0);
                                $updates[] = "total_vat = " . (float) ($parsed['invoice']['total_vat'] ?? 0);
                                $updates[] = "total_gross = " . (float) ($parsed['invoice']['total_gross'] ?? 0);
                                if (!empty($parsed['vat_summary'])) {
                                    $updates[] = "vat_summary = '" . $db->escape(json_encode($parsed['vat_summary'])) . "'";
                                }
                                if (!empty($parsed['lines'])) {
                                    $updates[] = "line_items = '" . $db->escape(json_encode($parsed['lines'])) . "'";
                                }
                                $sql_update = "UPDATE " . MAIN_DB_PREFIX . "ksef_incoming"
                                    . " SET " . implode(", ", $updates)
                                    . " WHERE rowid = " . (int) $obj->rowid;
                                $db->query($sql_update);
                                $migrated++;
                            } else {
                                dol_syslog("modKSEF::migration 1.3.2 - Failed to parse invoice rowid=" . (int) $obj->rowid, LOG_WARNING);
                            }
                        }
                        $db->free($resql);
                    }
                    dol_syslog("modKSEF::migration 1.3.2 - Re-parsed data for $migrated incoming invoices (fix unit prices)", LOG_INFO);
                },
            ),
            '1.3.3' => array(
                // Add payment_status
                function () use ($db, $conf) {
                    $sql_check = "SHOW COLUMNS FROM " . MAIN_DB_PREFIX . "ksef_incoming LIKE 'payment_status'";
                    $resql = $db->query($sql_check);
                    if ($resql && $db->num_rows($resql) == 0) {
                        $db->query("ALTER TABLE " . MAIN_DB_PREFIX . "ksef_incoming ADD COLUMN payment_status VARCHAR(20) AFTER bank_account");
                        dol_syslog("modKSEF::migration 1.3.3 - Added payment_status column", LOG_INFO);
                    }
                },
                // Add payment_date
                function () use ($db, $conf) {
                    $sql_check = "SHOW COLUMNS FROM " . MAIN_DB_PREFIX . "ksef_incoming LIKE 'payment_date'";
                    $resql = $db->query($sql_check);
                    if ($resql && $db->num_rows($resql) == 0) {
                        $db->query("ALTER TABLE " . MAIN_DB_PREFIX . "ksef_incoming ADD COLUMN payment_date INTEGER AFTER payment_status");
                        dol_syslog("modKSEF::migration 1.3.3 - Added payment_date column", LOG_INFO);
                    }
                },
                // payment_status/payment_date/sale_date reparse
                function () use ($db, $conf) {
                    $migrated = 0;
                    $sql = "SELECT rowid, fa3_xml FROM " . MAIN_DB_PREFIX . "ksef_incoming"
                        . " WHERE fa3_xml IS NOT NULL AND fa3_xml != ''"
                        . " AND (payment_status IS NULL OR sale_date IS NULL)"
                        . " AND entity = " . (int) $conf->entity;
                    $resql = $db->query($sql);
                    if ($resql) {
                        dol_include_once('/ksef/class/fa3_parser.class.php');
                        $parser = new FA3Parser($db);
                        while ($obj = $db->fetch_object($resql)) {
                            $parsed = $parser->parse($obj->fa3_xml);
                            if (!$parsed) continue;

                            $updates = array();

                            if (!empty($parsed['payment'])) {
                                $status = $parsed['payment']['status'] ?? null;
                                $payDate = !empty($parsed['payment']['payment_date']) ? strtotime($parsed['payment']['payment_date']) : null;
                                if ($status) {
                                    $updates[] = "payment_status = '" . $db->escape($status) . "'";
                                    if ($payDate && $payDate > 0) {
                                        $updates[] = "payment_date = " . (int) $payDate;
                                    }
                                }
                            }

                            if (!empty($parsed['invoice']['sale_date'])) {
                                $saleDate = strtotime($parsed['invoice']['sale_date']);
                                if ($saleDate && $saleDate > 0) {
                                    $updates[] = "sale_date = " . (int) $saleDate;
                                }
                            }

                            if (!empty($updates)) {
                                $db->query("UPDATE " . MAIN_DB_PREFIX . "ksef_incoming SET " . implode(", ", $updates) . " WHERE rowid = " . (int) $obj->rowid);
                                $migrated++;
                            }
                        }
                        $db->free($resql);
                    }
                    dol_syslog("modKSEF::migration 1.3.3 - Backfilled payment/sale_date for $migrated incoming invoices", LOG_INFO);
                },
                // Fix cron job classesname again
                function () use ($db, $conf) {
                    $sql = "UPDATE " . MAIN_DB_PREFIX . "cronjob"
                        . " SET classesname = '/ksef/class/ksef_service.class.php',"
                        . " objectname = 'KsefService'"
                        . " WHERE classesname LIKE '%ksef%'"
                        . " AND methodename LIKE 'cron%'"
                        . " AND entity IN (0, " . ((int) $conf->entity) . ")";
                    $db->query($sql);
                    dol_syslog("modKSEF::migration 1.3.3 - Fixed cron job classesname and objectname", LOG_INFO);
                },
            ),
            '1.3.7' => array(
                // Preserve existing if set
                function () use ($db, $conf) {
                    $sql = "SELECT value FROM " . MAIN_DB_PREFIX . "const"
                        . " WHERE name = 'KSEF_DODATKOWY_OPIS_NOTE_MODE'"
                        . " AND entity = " . ((int) $conf->entity);
                    $resql = $db->query($sql);
                    if ($resql && $db->num_rows($resql) == 0) {
                        dolibarr_set_const($db, 'KSEF_DODATKOWY_OPIS_NOTE_MODE', 'disabled', 'chaine', 0, '', $conf->entity);
                        dolibarr_set_const($db, 'KSEF_NOTE_PUBLIC_TARGET', 'stopka_faktury', 'chaine', 0, '', $conf->entity);
                        dol_syslog("modKSEF::migration 1.3.7 - Preserved implicit 'disabled' note mode for existing installation", LOG_INFO);
                    }
                },
                // Backfill langfile on module extrafields
                function () use ($db, $conf) {
                    $backfillMap = array(
                        'facture'    => 'KSEF_DODATKOWY_OPIS_EXTRAFIELDS',
                        'facturedet' => 'KSEF_DODATKOWY_OPIS_DET_EXTRAFIELDS',
                        'product'    => 'KSEF_DODATKOWY_OPIS_PRODUCT_EXTRAFIELDS',
                        'societe'    => 'KSEF_DODATKOWY_OPIS_SOCIETE_EXTRAFIELDS',
                        'projet'     => 'KSEF_DODATKOWY_OPIS_PROJECT_EXTRAFIELDS',
                    );
                    foreach ($backfillMap as $elType => $configKey) {
                        $configVal = getDolGlobalString($configKey, '');
                        if (empty($configVal)) continue;
                        $fieldEntries = array_filter(array_map('trim', explode(',', $configVal)));
                        foreach ($fieldEntries as $entry) {
                            if (empty($entry)) continue;
                            $parts = explode(':', $entry, 2);
                            $fn = $parts[0];
                            if (empty($fn)) continue;
                            $sql = "UPDATE " . MAIN_DB_PREFIX . "extrafields SET langs = 'ksef@ksef'"
                                . " WHERE name = '" . $db->escape($fn) . "'"
                                . " AND elementtype = '" . $db->escape($elType) . "'"
                                . " AND (langs IS NULL OR langs = '')";
                            $db->query($sql);
                        }
                    }
                    dol_syslog("modKSEF::migration 1.3.7 - Backfilled langfile on module-managed extrafields", LOG_INFO);
                },
                // Migrate DodatkowyOpis overrides
                function () use ($db, $conf) {
                    $table = MAIN_DB_PREFIX . "facture_extrafields";
                    $db->query("UPDATE $table SET ksef_dodatkowy_opis_mode = 'simple_dodatkowy' WHERE ksef_dodatkowy_opis_mode = 'simple'");
                    $db->query("UPDATE $table SET ksef_dodatkowy_opis_mode = 'keyvalue_dodatkowy' WHERE ksef_dodatkowy_opis_mode = 'keyvalue'");
                    dol_syslog("modKSEF::migration 1.3.7 - Migrated per-invoice note override values to combined format", LOG_INFO);
                },
                // copy existing auth config to the active environment
                function () use ($db, $conf) {
                    $env = getDolGlobalString('KSEF_ENVIRONMENT', 'DEMO');
                    $env = strtoupper($env);

                    $authKeys = array(
                        'KSEF_AUTH_METHOD',
                        'KSEF_AUTH_TOKEN',
                        'KSEF_TOKEN_UPDATED_AT',
                        'KSEF_AUTH_CERTIFICATE',
                        'KSEF_AUTH_PRIVATE_KEY',
                        'KSEF_AUTH_KEY_PASSWORD',
                        'KSEF_AUTH_CERT_SERIAL',
                        'KSEF_AUTH_CERT_VALID_FROM',
                        'KSEF_AUTH_CERT_VALID_TO',
                        'KSEF_OFFLINE_CERTIFICATE',
                        'KSEF_OFFLINE_PRIVATE_KEY',
                        'KSEF_OFFLINE_KEY_PASSWORD',
                        'KSEF_OFFLINE_CERT_SERIAL',
                        'KSEF_OFFLINE_CERT_VALID_FROM',
                        'KSEF_OFFLINE_CERT_VALID_TO',
                    );

                    foreach ($authKeys as $oldKey) {
                        $oldValue = dolibarr_get_const($db, $oldKey, $conf->entity);
                        if ($oldValue !== '' && $oldValue !== null) {
                            $newKey = $oldKey . '_' . $env;
                            // Idempotent: only copy if suffixed key does not already have a value
                            $existingVal = dolibarr_get_const($db, $newKey, $conf->entity);
                            if ($existingVal === '' || $existingVal === null) {
                                dolibarr_set_const($db, $newKey, $oldValue, 'chaine', 0, '', $conf->entity);
                                dol_syslog("modKSEF::migration 1.3.7 - Copied $oldKey to $newKey", LOG_INFO);
                            }
                        }
                        // Delete old
                        dolibarr_del_const($db, $oldKey, $conf->entity);
                    }
                    dol_syslog("modKSEF::migration 1.3.7 - Per-environment auth key migration complete (env=$env)", LOG_INFO);
                },
            ),
            '1.4.0' => array(
                // Add invoice_type column to submissions
                function () use ($db) {
                    $sql_check = "SHOW COLUMNS FROM " . MAIN_DB_PREFIX . "ksef_submissions LIKE 'invoice_type'";
                    $resql = $db->query($sql_check);
                    if ($resql && $db->num_rows($resql) == 0) {
                        $db->query("ALTER TABLE " . MAIN_DB_PREFIX . "ksef_submissions ADD COLUMN invoice_type VARCHAR(10) DEFAULT NULL");
                        $db->query("CREATE INDEX idx_ksef_submissions_invoice_type ON " . MAIN_DB_PREFIX . "ksef_submissions (invoice_type)");
                        dol_syslog("modKSEF::migration 1.4.0 - Added invoice_type column and index to submissions", LOG_INFO);
                    }
                },
            ),
            '1.4.1' => array(
                // Delete old KSEF_TIMEOUT
                function () use ($db, $conf) {
                    $db->query("DELETE FROM " . MAIN_DB_PREFIX . "const"
                        . " WHERE name = 'KSEF_TIMEOUT' AND entity = " . (int) $conf->entity);
                    dolibarr_set_const($db, 'KSEF_TIMEOUT', '7',
                        'chaine', 1, 'KSeF API timeout in seconds', $conf->entity);
                    dol_syslog("modKSEF::migration 1.4.1 - Recreated KSEF_TIMEOUT as visible with default 7s (#27)", LOG_INFO);
                },
                function () use ($db, $conf) {
                    if (getDolGlobalInt('KSEF_FA3_INCLUDE_FP')) {
                        dolibarr_set_const($db, 'KSEF_FA3_FP_SOURCE', 'always_on', 'chaine', 0, '', $conf->entity);
                        dol_syslog("modKSEF::migration 1.4.1 - Migrated KSEF_FA3_INCLUDE_FP=1 to KSEF_FA3_FP_SOURCE=always_on (#29)", LOG_INFO);
                    }
                },
            ),
            '1.4.4' => array(
                function () use ($db, $conf) {
                    dolibarr_del_const($db, 'KSEF_FA3_INCLUDE_FP', $conf->entity);
                    dolibarr_del_const($db, 'KSEF_NREORI_BUYER_SOURCE', $conf->entity);
                    dol_syslog("modKSEF::migration 1.4.4 - Removed dead consts KSEF_FA3_INCLUDE_FP and KSEF_NREORI_BUYER_SOURCE", LOG_INFO);
                },
            ),
        );

        $lastMigration = getDolGlobalString('KSEF_MIGRATION_VERSION', '');
        foreach ($migrations as $version => $steps) {

            // TODO /!\: ALWAYS MIGRATE - if commented then migrations run always
            if (!empty($lastMigration) && version_compare($lastMigration, $version, '>=')) {
                continue;
            }
            // END ALWAYS MIGRATE

            dol_syslog("modKSEF::init - Running migrations for v$version", LOG_INFO);
            foreach ($steps as $step) {
                $step();
            }
            dolibarr_set_const($db, 'KSEF_MIGRATION_VERSION', $version, 'chaine', 0, '', $conf->entity);
        }

        // Record the version this module was activated at
        dolibarr_set_const($db, 'KSEF_LAST_INIT_VERSION', $this->version, 'chaine', 0, '', $conf->entity);

        // KSeF VAT rate codes
        $polandId = 0;
        $plSql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "c_country WHERE code = 'PL'";
        $plRes = $db->query($plSql);
        if ($plRes && $plObj = $db->fetch_object($plRes)) $polandId = (int) $plObj->rowid;
        if ($polandId > 0) {
            $vatSeeds = array(
                array('code' => 'ZW',  'note' => 'Zwolniony z VAT (art. 43/113) - KSeF: zw'),
                array('code' => 'RC',  'note' => 'Odwrotne obciazenie (art. 17) - KSeF: oo'),
                array('code' => 'NP',  'note' => 'Niepodlegajace - poza terytorium kraju - KSeF: np I'),
                array('code' => 'NP2', 'note' => 'Niepodlegajace - uslugi art. 100 ust. 1 pkt 4 - KSeF: np II'),
                array('code' => 'WDT', 'note' => 'WDT - wewnatrzwspolnotowa dostawa towarow - KSeF: 0 WDT'),
                array('code' => 'EX',  'note' => 'Eksport towarow - KSeF: 0 EX'),
            );
            foreach ($vatSeeds as $vs) {
                $chk = "SELECT rowid FROM " . MAIN_DB_PREFIX . "c_tva"
                    . " WHERE entity IN (0, " . ((int) $conf->entity) . ")"
                    . " AND fk_pays = " . $polandId
                    . " AND code = '" . $db->escape($vs['code']) . "' AND taux = 0";
                $chkRes = $db->query($chk);
                if ($chkRes && $db->num_rows($chkRes) > 0) continue;
                $db->query("INSERT INTO " . MAIN_DB_PREFIX . "c_tva (entity, fk_pays, code, taux, note, active, recuperableonly)"
                    . " VALUES (" . ((int) $conf->entity) . ", " . $polandId . ", '" . $db->escape($vs['code']) . "'"
                    . ", 0, '" . $db->escape($vs['note']) . "', 0, 0)");
            }
        }

        return $this->_init($sql, $options);
    }

    public function remove($options = '')
    {
        global $conf, $db;

        $sql = array();

        // purge on disable
        if (!empty($conf->global->KSEF_PURGE_ON_DISABLE)) {
            $constantsToPurge = array(
                // PDF & QR Settings
                'KSEF_ADD_TO_PDF',
                'KSEF_ADD_QR',
                'KSEF_QR_SIZE',

                // UI Customization
                'KSEF_BUTTON_COLOR',

                // Purge on disable (reset itself too)
                'KSEF_PURGE_ON_DISABLE',

                // Company & Environment
                'KSEF_COMPANY_NIP',
                'KSEF_COMPANY_KRS',
                'KSEF_COMPANY_REGON',
                'KSEF_COMPANY_BDO',
                'KSEF_ENVIRONMENT',
                'KSEF_TIMEOUT',

                // Authentication
                'KSEF_AUTH_METHOD_TEST',
                'KSEF_AUTH_METHOD_DEMO',
                'KSEF_AUTH_METHOD_PRODUCTION',
                'KSEF_AUTH_TOKEN_TEST',
                'KSEF_AUTH_TOKEN_DEMO',
                'KSEF_AUTH_TOKEN_PRODUCTION',
                'KSEF_TOKEN_UPDATED_AT_TEST',
                'KSEF_TOKEN_UPDATED_AT_DEMO',
                'KSEF_TOKEN_UPDATED_AT_PRODUCTION',

                // Authentication Certificate
                'KSEF_AUTH_CERTIFICATE_TEST',
                'KSEF_AUTH_CERTIFICATE_DEMO',
                'KSEF_AUTH_CERTIFICATE_PRODUCTION',
                'KSEF_AUTH_PRIVATE_KEY_TEST',
                'KSEF_AUTH_PRIVATE_KEY_DEMO',
                'KSEF_AUTH_PRIVATE_KEY_PRODUCTION',
                'KSEF_AUTH_KEY_PASSWORD_TEST',
                'KSEF_AUTH_KEY_PASSWORD_DEMO',
                'KSEF_AUTH_KEY_PASSWORD_PRODUCTION',
                'KSEF_AUTH_CERT_SERIAL_TEST',
                'KSEF_AUTH_CERT_SERIAL_DEMO',
                'KSEF_AUTH_CERT_SERIAL_PRODUCTION',
                'KSEF_AUTH_CERT_VALID_FROM_TEST',
                'KSEF_AUTH_CERT_VALID_FROM_DEMO',
                'KSEF_AUTH_CERT_VALID_FROM_PRODUCTION',
                'KSEF_AUTH_CERT_VALID_TO_TEST',
                'KSEF_AUTH_CERT_VALID_TO_DEMO',
                'KSEF_AUTH_CERT_VALID_TO_PRODUCTION',

                // Offline Certificate
                'KSEF_OFFLINE_CERTIFICATE_TEST',
                'KSEF_OFFLINE_CERTIFICATE_DEMO',
                'KSEF_OFFLINE_CERTIFICATE_PRODUCTION',
                'KSEF_OFFLINE_PRIVATE_KEY_TEST',
                'KSEF_OFFLINE_PRIVATE_KEY_DEMO',
                'KSEF_OFFLINE_PRIVATE_KEY_PRODUCTION',
                'KSEF_OFFLINE_KEY_PASSWORD_TEST',
                'KSEF_OFFLINE_KEY_PASSWORD_DEMO',
                'KSEF_OFFLINE_KEY_PASSWORD_PRODUCTION',
                'KSEF_OFFLINE_CERT_SERIAL_TEST',
                'KSEF_OFFLINE_CERT_SERIAL_DEMO',
                'KSEF_OFFLINE_CERT_SERIAL_PRODUCTION',
                'KSEF_OFFLINE_CERT_VALID_FROM_TEST',
                'KSEF_OFFLINE_CERT_VALID_FROM_DEMO',
                'KSEF_OFFLINE_CERT_VALID_FROM_PRODUCTION',
                'KSEF_OFFLINE_CERT_VALID_TO_TEST',
                'KSEF_OFFLINE_CERT_VALID_TO_DEMO',
                'KSEF_OFFLINE_CERT_VALID_TO_PRODUCTION',

                // Customer Exclusions
                'KSEF_EXCLUDED_CUSTOMERS',

                // FA3 Optional Fields
                'KSEF_FA3_INCLUDE_NRKLIENTA',
                'KSEF_FA3_INCLUDE_INDEKS',
                'KSEF_FA3_INCLUDE_GTIN',
                'KSEF_FA3_INCLUDE_UNIT',
                'KSEF_FA3_INCLUDE_BANK_DESC',
                'KSEF_FA3_PLACE_OF_ISSUE_MODE',
                'KSEF_FA3_PLACE_OF_ISSUE_CUSTOM',
                'KSEF_FA3_SALE_DATE_SOURCE',
                'KSEF_NBP_RATE_MODE',

                // Correction Invoices
                'KSEF_DEFAULT_CORRECTION_REASON',
                'KSEF_DEFAULT_CORRECTION_TYPE',
                'KSEF_KOR_LINE_METHOD',

                // Entity Fields
                'KSEF_IDNABYWCY_SOURCE',

                // Company Identifiers
                'KSEF_COMPANY_EORI',
                'KSEF_FIELD_EORI',

                // Invoice Flags
                'KSEF_TP_SOURCE',
                'KSEF_FA3_MPP_SOURCE',
                'KSEF_FA3_FP_SOURCE',
                'KSEF_P17_SOURCE',
                'KSEF_P17_TP_SOURCE',
                'KSEF_P16_SOURCE',
                'KSEF_P18_SOURCE',
                'KSEF_P18_TP_SOURCE',

                'KSEF_PODMIOT3_SOURCE',
                'KSEF_PODMIOT3_ROLE',
                'KSEF_IDWEW_SOURCE',

                // GUS / REGON
                'KSEF_GUS_ENABLED',
                'KSEF_GUS_ENV',
                'KSEF_GUS_KEY',
                'KSEF_GUS_FETCH_SUPPLIERS',
                'KSEF_GUS_NAME_STYLE',

                // Update check
                'KSEF_UPDATE_LAST_CHECK',
                'KSEF_UPDATE_AVAILABLE',
                'KSEF_UPDATE_LATEST_VERSION',
                'KSEF_UPDATE_RELEASE_URL',
                'KSEF_UPDATE_ZIP_URL',

                'KSEF_MIGRATION_VERSION',
            );

            foreach ($constantsToPurge as $constName) {
                dolibarr_del_const($db, $constName, $conf->entity);
            }
        }

        return $this->_remove($sql, $options);
    }
}