<?php
/* Copyright (C) 2004-2018	Laurent Destailleur			<eldy@users.sourceforge.net>
 * Copyright (C) 2018-2019	Nicolas ZABOURI				<info@inovea-conseil.com>
 * Copyright (C) 2019-2024	Frédéric France				<frederic.france@free.fr>
 * Copyright (C) 2025		SuperAdmin					<daoud.mouhamed@gmail.com>
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
 */

/**
 * 	\defgroup   pdpconnectfr     Module PDPConnectFR
 *  \brief      PDPConnectFR module descriptor.
 *
 *  \file       htdocs/pdpconnectfr/core/modules/modPDPConnectFR.class.php
 *  \ingroup    pdpconnectfr
 *  \brief      Description and activation file for module PDPConnectFR
 */
include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';


/**
 *  Description and activation class for module PDPConnectFR
 */
class modPDPConnectFR extends DolibarrModules
{
	/**
	 * Constructor. Define names, constants, directories, boxes, permissions
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		global $conf;

		$this->db = $db;

		// Id for module (must be unique).
		// Use here a free id (See in Home -> System information -> Dolibarr for list of used modules id).
		$this->numero = 95020;

		// Key text used to identify module (for permissions, menus, etc...)
		$this->rights_class = 'pdpconnectfr';

		// Family can be 'base' (core modules),'crm','financial','hr','projects','products','ecm','technic' (transverse modules),'interface' (link with external tools),'other','...'
		// It is used to group modules by family in module setup page
		$this->family = "other";

		// Module position in the family on 2 digits ('01', '10', '20', ...)
		$this->module_position = '90';

		// Gives the possibility for the module, to provide his own family info and position of this family (Overwrite $this->family and $this->module_position. Avoid this)
		//$this->familyinfo = array('myownfamily' => array('position' => '01', 'label' => $langs->trans("MyOwnFamily")));
		// Module label (no space allowed), used if translation string 'ModulePDPConnectFRName' not found (PDPConnectFR is name of module).
		$this->name = preg_replace('/^mod/i', '', get_class($this));

		// DESCRIPTION_FLAG
		// Module description, used if translation string 'ModulePDPConnectFRDesc' not found (PDPConnectFR is name of module).
		$this->description = "PDPConnectFRDescription";
		// Used only if file README.md and README-LL.md not found.
		$this->descriptionlong = "PDPConnectFRDescription";

		// Author
		$this->editor_name = 'Dolibarr Association';
		$this->editor_url = 'https://www.dolibarr.org';		// Must be an external online web site
		$this->editor_squarred_logo = '';					// Must be image filename into the module/img directory followed with @modulename. Example: 'myimage.png@pdpconnectfr'

		// Possible values for version are: 'development', 'experimental', 'dolibarr', 'dolibarr_deprecated', 'experimental_deprecated' or a version string like 'x.y.z'
		$this->version = '1.0';
		// Url to the file with your last numberversion of this module
		//$this->url_last_version = 'http://www.example.com/versionmodule.txt';

		// Key used in llx_const table to save module status enabled/disabled (where PDPCONNECTFR is value of property name of module in uppercase)
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);

		// Name of image file used for this module.
		// If file is in theme/yourtheme/img directory under name object_pictovalue.png, use this->picto='pictovalue'
		// If file is in module/img directory under name object_pictovalue.png, use this->picto='pictovalue@module'
		// To use a supported fa-xxx css style of font awesome, use this->picto='xxx'
		$this->picto = 'pdpconnectfr.png@pdpconnectfr';

		// Define some features supported by module (triggers, login, substitutions, menus, css, etc...)
		$this->module_parts = array(
			// Set this to 1 if module has its own trigger directory (core/triggers)
			'triggers' => 1,
			// Set this to 1 if module has its own login method file (core/login)
			'login' => 0,
			// Set this to 1 if module has its own substitution function file (core/substitutions)
			'substitutions' => 0,
			// Set this to 1 if module has its own menus handler directory (core/menus)
			'menus' => 0,
			// Set this to 1 if module overwrite template dir (core/tpl)
			'tpl' => 0,
			// Set this to 1 if module has its own barcode directory (core/modules/barcode)
			'barcode' => 0,
			// Set this to 1 if module has its own models directory (core/modules/xxx)
			'models' => 0,
			// Set this to 1 if module has its own printing directory (core/modules/printing)
			'printing' => 0,
			// Set this to 1 if module has its own theme directory (theme)
			'theme' => 0,
			// Set this to relative path of css file if module has its own css file
			'css' => array(),
			// Set this to relative path of js file if module must load a js on all pages
			'js' => array(
				//   '/pdpconnectfr/js/pdpconnectfr.js.php',
			),
			// Set here all hooks context managed by module. To find available hook context, make a "grep -r '>initHooks(' *" on source code. You can also set hook context to 'all'
			/* BEGIN MODULEBUILDER HOOKSCONTEXTS */
			'hooks' => [
                'all', 'invoicecard'
            ],
			/* END MODULEBUILDER HOOKSCONTEXTS */
			// Set this to 1 if features of module are opened to external users
			'moduleforexternal' => 0,
			// Set this to 1 if the module provides a website template into doctemplates/websites/website_template-mytemplate
			'websitetemplates' => 0,
			// Set this to 1 if the module provides a captcha driver
			'captcha' => 0
		);

		// Data directories to create when module is enabled.
		// Example: this->dirs = array("/pdpconnectfr/temp","/pdpconnectfr/subdir");
		$this->dirs = array("/pdpconnectfr/temp");

		// Config pages. Put here list of php page, stored into pdpconnectfr/admin directory, to use to setup module.
		$this->config_page_url = array("setup.php@pdpconnectfr");

		// Dependencies
		// A condition to hide module
		$this->hidden = getDolGlobalInt('MODULE_PDPCONNECTFR_DISABLED'); // A condition to disable module;
		// List of module class names that must be enabled if this module is enabled. Example: array('always'=>array('modModuleToEnable1','modModuleToEnable2'), 'FR'=>array('modModuleToEnableFR')...)
		$this->depends = array();
		// List of module class names to disable if this one is disabled. Example: array('modModuleToDisable1', ...)
		$this->requiredby = array();
		// List of module class names this module is in conflict with. Example: array('modModuleToDisable1', ...)
		$this->conflictwith = array();

		// The language file dedicated to your module
		$this->langfiles = array("pdpconnectfr@pdpconnectfr");

		// Prerequisites
		$this->phpmin = array(7, 2); // Minimum version of PHP required by module
		// $this->phpmax = array(8, 0); // Maximum version of PHP required by module
		$this->need_dolibarr_version = array(22, -3); // Minimum version of Dolibarr required by module
		// $this->max_dolibarr_version = array(19, -3); // Maximum version of Dolibarr required by module
		$this->need_javascript_ajax = 0;

		// Messages at activation
		$this->warnings_activation = array(); 		// Warning to show when we activate module. array('always'='text') or array('FR'='textfr','MX'='textmx'...)
		$this->warnings_activation_ext = array(); 	// Warning to show when we activate an external module. array('always'='text') or array('FR'='textfr','MX'='textmx'...)
		//$this->automatic_activation = array('FR'=>'PDPConnectFRWasAutomaticallyActivatedBecauseOfYourCountryChoice');
		//$this->always_enabled = false;			// If true, can't be disabled. Value true is reserved for core modules. Not allowed for external modules.

		// Constants
		// List of particular constants to add when module is enabled (key, 'chaine', value, desc, visible, 'current' or 'allentities', deleteonunactive)
		// Example: $this->const=array(1 => array('PDPCONNECTFR_MYNEWCONST1', 'chaine', 'myvalue', 'This is a constant to add', 1),
		//                             2 => array('PDPCONNECTFR_MYNEWCONST2', 'chaine', 'myvalue', 'This is another constant to add', 0, 'current', 1)
		// );
		$this->const = array(
			1 => array('PDPCONNECTFR_EINVOICE_IN_REAL_TIME', 'chaine', '1', 0),
			2 => array('PDPCONNECTFR_FLOWS_SYNC_CALL_LIMIT', 'chaine', '1', 0),
			3 => array('PDPCONNECTFR_SYNC_MARGIN_TIME_HOURS_HELP', 'chaine', '12', 0),
			4 => array('PDPCONNECTFR_FLOWS_SYNC_CALL_SIZE', 'chaine', '100', 0),
		);

		// Some keys to add into the overwriting translation tables
		/*$this->overwrite_translation = array(
			'en_US:ParentCompany'=>'Parent company or reseller',
			'fr_FR:ParentCompany'=>'Maison mère ou revendeur'
		)*/

		if (!isModEnabled("pdpconnectfr")) {
			$conf->pdpconnectfr = new stdClass();
			$conf->pdpconnectfr->enabled = 0;
		}

		// Array to add new pages in new tabs
		/* BEGIN MODULEBUILDER TABS */
		// Don't forget to deactivate/reactivate your module to test your changes
		$this->tabs = array();
		//$this->tabs[] = array('data' => 'invoice:+CustomerLCtab:einvoicecustomerlctab:pdpconnectfr@pdpconnectfr:$user->hasRight("facture", "read"):/pdpconnectfr/einvoice_object_timeline.php?id=__ID__');

		//$this->tabs[] = array('data' => 'invoice:+EinvoiceEvents:EinvoiceEventsTab:@pdpconnectfr:$user->hasRight("facture","read"):/pdpconnectfr/einvoice_events.php?id=__ID__&elementtype=invoice');

		/* END MODULEBUILDER TABS */
		// Example:
		// To add a new tab identified by code tabname1
		// $this->tabs[] = array('data' => 'objecttype:+tabname1:Title1:mylangfile@pdpconnectfr:$user->hasRight('pdpconnectfr', 'call', 'read'):/pdpconnectfr/mynewtab1.php?id=__ID__');
		// To add another new tab identified by code tabname2. Label will be result of calling all substitution functions on 'Title2' key.
		// $this->tabs[] = array('data' => 'objecttype:+tabname2:SUBSTITUTION_Title2:mylangfile@pdpconnectfr:$user->hasRight('othermodule', 'otherobject', 'read'):/pdpconnectfr/mynewtab2.php?id=__ID__',
		// To remove an existing tab identified by code tabname
		// $this->tabs[] = array('data' => 'objecttype:-tabname:NU:conditiontoremove');
		//
		// Where objecttype can be
		// 'categories_x'	  to add a tab in category view (replace 'x' by type of category (0=product, 1=supplier, 2=customer, 3=member)
		// 'contact'          to add a tab in contact view
		// 'contract'         to add a tab in contract view
		// 'delivery'         to add a tab in delivery view
		// 'group'            to add a tab in group view
		// 'intervention'     to add a tab in intervention view
		// 'invoice'          to add a tab in customer invoice view
		// 'supplier_invoice' to add a tab in supplier invoice view
		// 'member'           to add a tab in foundation member view
		// 'opensurveypoll'	  to add a tab in opensurvey poll view
		// 'order'            to add a tab in sale order view
		// 'supplier_order'   to add a tab in supplier order view
		// 'payment'		  to add a tab in payment view
		// 'supplier_payment' to add a tab in supplier payment view
		// 'product'          to add a tab in product view
		// 'propal'           to add a tab in propal view
		// 'project'          to add a tab in project view
		// 'stock'            to add a tab in stock view
		// 'thirdparty'       to add a tab in third party view
		// 'user'             to add a tab in user view


		// Dictionaries
		/* Example:
		 $this->dictionaries=array(
		 'langs' => 'pdpconnectfr@pdpconnectfr',
		 // List of tables we want to see into dictionary editor
		 'tabname' => array("table1", "table2", "table3"),
		 // Label of tables
		 'tablib' => array("Table1", "Table2", "Table3"),
		 // Request to select fields
		 'tabsql' => array('SELECT f.rowid as rowid, f.code, f.label, f.active FROM '.$this->db->prefix().'table1 as f', 'SELECT f.rowid as rowid, f.code, f.label, f.active FROM '.$this->db->prefix().'table2 as f', 'SELECT f.rowid as rowid, f.code, f.label, f.active FROM '.$this->db->prefix().'table3 as f'),
		 // Sort order
		 'tabsqlsort' => array("label ASC", "label ASC", "label ASC"),
		 // List of fields (result of select to show dictionary)
		 'tabfield' => array("code,label", "code,label", "code,label"),
		 // List of fields (list of fields to edit a record)
		 'tabfieldvalue' => array("code,label", "code,label", "code,label"),
		 // List of fields (list of fields for insert)
		 'tabfieldinsert' => array("code,label", "code,label", "code,label"),
		 // Name of columns with primary key (try to always name it 'rowid')
		 'tabrowid' => array("rowid", "rowid", "rowid"),
		 // Condition to show each dictionary
		 'tabcond' => array(isModEnabled('pdpconnectfr'), isModEnabled('pdpconnectfr'), isModEnabled('pdpconnectfr')),
		 // Tooltip for every fields of dictionaries: DO NOT PUT AN EMPTY ARRAY
		 'tabhelp' => array(array('code' => $langs->trans('CodeTooltipHelp'), 'field2' => 'field2tooltip'), array('code' => $langs->trans('CodeTooltipHelp'), 'field2' => 'field2tooltip'), ...),
		 );
		 */
		/* BEGIN MODULEBUILDER DICTIONARIES */
		$this->dictionaries = array();
		/* END MODULEBUILDER DICTIONARIES */

		// Boxes/Widgets
		// Add here list of php file(s) stored in pdpconnectfr/core/boxes that contains a class to show a widget.
		/* BEGIN MODULEBUILDER WIDGETS */
		$this->boxes = array(
			//  0 => array(
			//      'file' => 'pdpconnectfrwidget1.php@pdpconnectfr',
			//      'note' => 'Widget provided by PDPConnectFR',
			//      'enabledbydefaulton' => 'Home',
			//  ),
			//  ...
		);
		/* END MODULEBUILDER WIDGETS */

		// Cronjobs (List of cron jobs entries to add when module is enabled)
		// unit_frequency must be 60 for minute, 3600 for hour, 86400 for day, 604800 for week
		/* BEGIN MODULEBUILDER CRON */
		$this->cronjobs = array(
			//  0 => array(
			//      'label' => 'MyJob label',
			//      'jobtype' => 'method',
			//      'class' => '/pdpconnectfr/class/call.class.php',
			//      'objectname' => 'Call',
			//      'method' => 'doScheduledJob',
			//      'parameters' => '',
			//      'comment' => 'Comment',
			//      'frequency' => 2,
			//      'unitfrequency' => 3600,
			//      'status' => 0,
			//      'test' => 'isModEnabled("pdpconnectfr")',
			//      'priority' => 50,
			//  ),
		);
		/* END MODULEBUILDER CRON */
		// Example: $this->cronjobs=array(
		//    0=>array('label'=>'My label', 'jobtype'=>'method', 'class'=>'/dir/class/file.class.php', 'objectname'=>'MyClass', 'method'=>'myMethod', 'parameters'=>'param1, param2', 'comment'=>'Comment', 'frequency'=>2, 'unitfrequency'=>3600, 'status'=>0, 'test'=>'isModEnabled("pdpconnectfr")', 'priority'=>50),
		//    1=>array('label'=>'My label', 'jobtype'=>'command', 'command'=>'', 'parameters'=>'param1, param2', 'comment'=>'Comment', 'frequency'=>1, 'unitfrequency'=>3600*24, 'status'=>0, 'test'=>'isModEnabled("pdpconnectfr")', 'priority'=>50)
		// );

		// Permissions provided by this module
		$this->rights = array();
		$r = 0;
		// Add here entries to declare new permissions
		/* BEGIN MODULEBUILDER PERMISSIONS */
		$this->rights[$r][0] = $this->numero . sprintf('%02d', (0 * 10) + 0 + 1);
		$this->rights[$r][1] = 'Read Call object of PDPConnectFR';
		$this->rights[$r][4] = 'call';
		$this->rights[$r][5] = 'read';
		$r++;
		$this->rights[$r][0] = $this->numero . sprintf('%02d', (0 * 10) + 1 + 1);
		$this->rights[$r][1] = 'Create/Update Call object of PDPConnectFR';
		$this->rights[$r][4] = 'call';
		$this->rights[$r][5] = 'write';
		$r++;
		$this->rights[$r][0] = $this->numero . sprintf('%02d', (0 * 10) + 2 + 1);
		$this->rights[$r][1] = 'Delete Call object of PDPConnectFR';
		$this->rights[$r][4] = 'call';
		$this->rights[$r][5] = 'delete';
		$r++;
		$this->rights[$r][0] = $this->numero . sprintf('%02d', (1 * 10) + 0 + 1);
		$this->rights[$r][1] = 'Read Document object of PDPConnectFR';
		$this->rights[$r][4] = 'document';
		$this->rights[$r][5] = 'read';
		$r++;
		$this->rights[$r][0] = $this->numero . sprintf('%02d', (1 * 10) + 1 + 1);
		$this->rights[$r][1] = 'Create/Update Document object of PDPConnectFR';
		$this->rights[$r][4] = 'document';
		$this->rights[$r][5] = 'write';
		$r++;
		$this->rights[$r][0] = $this->numero . sprintf('%02d', (1 * 10) + 2 + 1);
		$this->rights[$r][1] = 'Delete Document object of PDPConnectFR';
		$this->rights[$r][4] = 'document';
		$this->rights[$r][5] = 'delete';
		$r++;

		/* END MODULEBUILDER PERMISSIONS */


		// Main menu entries to add
		$this->menu = array();
		$r = 0;
		// Add here entries to declare new menus
		/* BEGIN MODULEBUILDER TOPMENU */
		/*$this->menu[$r++] = array(
			'fk_menu' => '', // Will be stored into mainmenu + leftmenu. Use '' if this is a top menu. For left menu, use 'fk_mainmenu=xxx' or 'fk_mainmenu=xxx,fk_leftmenu=yyy' where xxx is mainmenucode and yyy is a leftmenucode
			'type' => 'top', // This is a Top menu entry
			'titre' => 'ModulePDPConnectFRName',
			'prefix' => img_picto('', $this->picto, 'class="pictofixedwidth valignmiddle"'),
			'mainmenu' => 'pdpconnectfr',
			'leftmenu' => '',
			'url' => '/pdpconnectfr/pdpconnectfrindex.php',
			'langs' => 'pdpconnectfr@pdpconnectfr', // Lang file to use (without .lang) by module. File must be in langs/code_CODE/ directory.
			'position' => 1000 + $r,
			'enabled' => 'isModEnabled("pdpconnectfr")', // Define condition to show or hide menu entry. Use 'isModEnabled("pdpconnectfr")' if entry must be visible if module is enabled.
			'perms' => '1', // Use 'perms'=>'$user->hasRight("pdpconnectfr", "call", "read")' if you want your menu with a permission rules
			'target' => '',
			'user' => 2, // 0=Menu for internal users, 1=external users, 2=both
		);*/
		/* END MODULEBUILDER TOPMENU */



		/* BEGIN MODULEBUILDER LEFTMENU PDPEXCHANGE */
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=billing',
			'type' => 'left',
			'titre' => 'EInvoiceManagement',
			'prefix' => img_picto('', 'pdpconnectfr.png@pdpconnectfr', 'class="pictofixedwidth valignmiddle paddingright"'),
			'mainmenu' => 'billing',
			'leftmenu' => 'pdpconnectfr_billing',
			'url' => '/pdpconnectfr/pdpconnectfrindex.php',
			'langs' => 'pdpconnectfr@pdpconnectfr',
			'position' => 1000,
			'enabled' => 'isModEnabled(\'pdpconnectfr\')',
			'perms' => '$user->hasRight(\'facture\', \'lire\')',
			'target' => '',
			'user' => 2,
			'object' => '',
		);
		/* END MODULEBUILDER LEFTMENU PDPEXCHANGE */
		/* BEGIN MODULEBUILDER LEFTMENU PDPDOCUMENTS */
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=billing,fk_leftmenu=pdpconnectfr_billing',
			'type' => 'left',
			'titre' => 'EInvoiceSynchronization',
			'mainmenu' => 'billing',
			'leftmenu' => 'pdpconnectfr_documents',
			'url' => '/pdpconnectfr/document_list.php',
			'langs' => 'pdpconnectfr@pdpconnectfr',
			'position' => 1001,
			'enabled' => 'isModEnabled(pdpconnectfr)',
			'perms' => '$user->hasRight(facture, lire)',
			'target' => '',
			'user' => 2,
			'object' => '',
		);
		/* END MODULEBUILDER LEFTMENU PDPDOCUMENTS */
		/* BEGIN MODULEBUILDER LEFTMENU PDPSOCIETIES */
		// $this->menu[$r++] = array(
		// 	'fk_menu' => 'fk_mainmenu=billing,fk_leftmenu=pdpconnectfr_billing',
		// 	'type' => 'left',
		// 	'titre' => 'pdpSocieties',
		// 	'mainmenu' => 'billing',
		// 	'leftmenu' => 'pdpconnectfr_societies',
		// 	'url' => '/pdpconnectfr/pdpconnectfrindex.php',
		// 	'langs' => 'pdpconnectfr@pdpconnectfr',
		// 	'position' => 1002,
		// 	'enabled' => 'isModEnabled(\'pdpconnectfr\')',
		// 	'perms' => '$user->hasRight(\'facture\', \'lire\')',
		// 	'target' => '',
		// 	'user' => 2,
		// 	'object' => '',
		// );
		/* END MODULEBUILDER LEFTMENU PDPSOCIETIES */
		/* BEGIN MODULEBUILDER LEFTMENU PDPFEEDBACK */
		$this->menu[$r++] = array( // TODO : we can move this page into the administration menu or module configuration page
			'fk_menu' => 'fk_mainmenu=billing,fk_leftmenu=pdpconnectfr_billing',
			'type' => 'left',
			'titre' => 'pdpFeedback',
			'mainmenu' => 'billing',
			'leftmenu' => 'pdpconnectfr_feedback',
			'url' => '/pdpconnectfr/call_list.php',
			'langs' => 'pdpconnectfr@pdpconnectfr',
			'position' => 1003,
			'enabled' => 'isModEnabled(\'pdpconnectfr\')',
			'perms' => '$user->hasRight(\'facture\', \'lire\')',
			'target' => '',
			'user' => 2,
			'object' => '',
		);
		/* END MODULEBUILDER LEFTMENU PDPFEEDBACK */


		/*
		$this->menu[$r++]=array(
			'fk_menu' => 'fk_mainmenu=pdpconnectfr,fk_leftmenu=call',	    // '' if this is a top menu. For left menu, use 'fk_mainmenu=xxx' or 'fk_mainmenu=xxx,fk_leftmenu=yyy' where xxx is mainmenucode and yyy is a leftmenucode
			'type' => 'left',			                // This is a Left menu entry
			'titre' => 'New_Call',
			'mainmenu' => 'pdpconnectfr',
			'leftmenu' => 'pdpconnectfr_call_new',
			'url' => '/pdpconnectfr/call_card.php?action=create',
			'langs' => 'pdpconnectfr@pdpconnectfr',	        // Lang file to use (without .lang) by module. File must be in langs/code_CODE/ directory.
			'position' => 1000 + $r,
			'enabled' => 'isModEnabled("pdpconnectfr")', // Define condition to show or hide menu entry. Use 'isModEnabled("pdpconnectfr")' if entry must be visible if module is enabled. Use '$leftmenu==\'system\'' to show if leftmenu system is selected.
			'perms' => '$user->hasRight("pdpconnectfr", "call", "write")'
			'target' => '',
			'user' => 2,				                // 0=Menu for internal users, 1=external users, 2=both
			'object' => 'Call'
		);
		$this->menu[$r++]=array(
			'fk_menu' => 'fk_mainmenu=pdpconnectfr,fk_leftmenu=call',	    // '' if this is a top menu. For left menu, use 'fk_mainmenu=xxx' or 'fk_mainmenu=xxx,fk_leftmenu=yyy' where xxx is mainmenucode and yyy is a leftmenucode
			'type' => 'left',			                // This is a Left menu entry
			'titre' => 'List_Call',
			'mainmenu' => 'pdpconnectfr',
			'leftmenu' => 'pdpconnectfr_call_list',
			'url' => '/pdpconnectfr/call_list.php',
			'langs' => 'pdpconnectfr@pdpconnectfr',	        // Lang file to use (without .lang) by module. File must be in langs/code_CODE/ directory.
			'position' => 1000 + $r,
			'enabled' => 'isModEnabled("pdpconnectfr")', // Define condition to show or hide menu entry. Use 'isModEnabled("pdpconnectfr")' if entry must be visible if module is enabled.
			'perms' => '$user->hasRight("pdpconnectfr", "call", "read")'
			'target' => '',
			'user' => 2,				                // 0=Menu for internal users, 1=external users, 2=both
			'object' => 'Call'
		);
		*/
		/* END MODULEBUILDER LEFTMENU MYOBJECT */


		// Exports profiles provided by this module
		$r = 0;
		/* BEGIN MODULEBUILDER EXPORT MYOBJECT */
		/*
		$langs->load("pdpconnectfr@pdpconnectfr");
		$this->export_code[$r] = $this->rights_class.'_'.$r;
		$this->export_label[$r] = 'CallLines';	// Translation key (used only if key ExportDataset_xxx_z not found)
		$this->export_icon[$r] = $this->picto;
		// Define $this->export_fields_array, $this->export_TypeFields_array and $this->export_entities_array
		$keyforclass = 'Call'; $keyforclassfile='/pdpconnectfr/class/call.class.php'; $keyforelement='call@pdpconnectfr';
		include DOL_DOCUMENT_ROOT.'/core/commonfieldsinexport.inc.php';
		//$this->export_fields_array[$r]['t.fieldtoadd']='FieldToAdd'; $this->export_TypeFields_array[$r]['t.fieldtoadd']='Text';
		//unset($this->export_fields_array[$r]['t.fieldtoremove']);
		//$keyforclass = 'CallLine'; $keyforclassfile='/pdpconnectfr/class/call.class.php'; $keyforelement='callline@pdpconnectfr'; $keyforalias='tl';
		//include DOL_DOCUMENT_ROOT.'/core/commonfieldsinexport.inc.php';
		$keyforselect='call'; $keyforaliasextra='extra'; $keyforelement='call@pdpconnectfr';
		include DOL_DOCUMENT_ROOT.'/core/extrafieldsinexport.inc.php';
		//$keyforselect='callline'; $keyforaliasextra='extraline'; $keyforelement='callline@pdpconnectfr';
		//include DOL_DOCUMENT_ROOT.'/core/extrafieldsinexport.inc.php';
		//$this->export_dependencies_array[$r] = array('callline' => array('tl.rowid','tl.ref')); // To force to activate one or several fields if we select some fields that need same (like to select a unique key if we ask a field of a child to avoid the DISTINCT to discard them, or for computed field than need several other fields)
		//$this->export_special_array[$r] = array('t.field' => '...');
		//$this->export_examplevalues_array[$r] = array('t.field' => 'Example');
		//$this->export_help_array[$r] = array('t.field' => 'FieldDescHelp');
		$this->export_sql_start[$r]='SELECT DISTINCT ';
		$this->export_sql_end[$r]  =' FROM '.$this->db->prefix().'pdpconnectfr_call as t';
		//$this->export_sql_end[$r]  .=' LEFT JOIN '.$this->db->prefix().'pdpconnectfr_call_line as tl ON tl.fk_call = t.rowid';
		$this->export_sql_end[$r] .=' WHERE 1 = 1';
		$this->export_sql_end[$r] .=' AND t.entity IN ('.getEntity('call').')';
		$r++; */
		/* END MODULEBUILDER EXPORT MYOBJECT */

		// Imports profiles provided by this module
		$r = 0;
		/* BEGIN MODULEBUILDER IMPORT MYOBJECT */
		/*
		$langs->load("pdpconnectfr@pdpconnectfr");
		$this->import_code[$r] = $this->rights_class.'_'.$r;
		$this->import_label[$r] = 'CallLines';	// Translation key (used only if key ExportDataset_xxx_z not found)
		$this->import_icon[$r] = $this->picto;
		$this->import_tables_array[$r] = array('t' => $this->db->prefix().'pdpconnectfr_call', 'extra' => $this->db->prefix().'pdpconnectfr_call_extrafields');
		$this->import_tables_creator_array[$r] = array('t' => 'fk_user_author'); // Fields to store import user id
		$import_sample = array();
		$keyforclass = 'Call'; $keyforclassfile='/pdpconnectfr/class/call.class.php'; $keyforelement='call@pdpconnectfr';
		include DOL_DOCUMENT_ROOT.'/core/commonfieldsinimport.inc.php';
		$import_extrafield_sample = array();
		$keyforselect='call'; $keyforaliasextra='extra'; $keyforelement='call@pdpconnectfr';
		include DOL_DOCUMENT_ROOT.'/core/extrafieldsinimport.inc.php';
		$this->import_fieldshidden_array[$r] = array('extra.fk_object' => 'lastrowid-'.$this->db->prefix().'pdpconnectfr_call');
		$this->import_regex_array[$r] = array();
		$this->import_examplevalues_array[$r] = array_merge($import_sample, $import_extrafield_sample);
		$this->import_updatekeys_array[$r] = array('t.ref' => 'Ref');
		$this->import_convertvalue_array[$r] = array(
			't.ref' => array(
				'rule'=>'getrefifauto',
				'class'=>(!getDolGlobalString('PDPCONNECTFR_MYOBJECT_ADDON') ? 'mod_call_standard' : getDolGlobalString('PDPCONNECTFR_MYOBJECT_ADDON')),
				'path'=>"/core/modules/pdpconnectfr/".(!getDolGlobalString('PDPCONNECTFR_MYOBJECT_ADDON') ? 'mod_call_standard' : getDolGlobalString('PDPCONNECTFR_MYOBJECT_ADDON')).'.php',
				'classobject'=>'Call',
				'pathobject'=>'/pdpconnectfr/class/call.class.php',
			),
			't.fk_soc' => array('rule' => 'fetchidfromref', 'file' => '/societe/class/societe.class.php', 'class' => 'Societe', 'method' => 'fetch', 'element' => 'ThirdParty'),
			't.fk_user_valid' => array('rule' => 'fetchidfromref', 'file' => '/user/class/user.class.php', 'class' => 'User', 'method' => 'fetch', 'element' => 'user'),
			't.fk_mode_reglement' => array('rule' => 'fetchidfromcodeorlabel', 'file' => '/compta/paiement/class/cpaiement.class.php', 'class' => 'Cpaiement', 'method' => 'fetch', 'element' => 'cpayment'),
		);
		$this->import_run_sql_after_array[$r] = array();
		$r++; */
		/* END MODULEBUILDER IMPORT MYOBJECT */
	}

	/**
	 *  Function called when module is enabled.
	 *  The init function add constants, boxes, permissions and menus (defined in constructor) into Dolibarr database.
	 *  It also creates data directories
	 *
	 *  @param      string  $options    Options when enabling module ('', 'noboxes')
	 *  @return     int<-1,1>          	1 if OK, <=0 if KO
	 */
	public function init($options = '')
	{
		global $conf, $langs;

		// Create tables of module at module activation
		//$result = $this->_load_tables('/install/mysql/', 'pdpconnectfr');
		$result = $this->_load_tables('/pdpconnectfr/sql/');
		if ($result < 0) {
			return -1; // Do not activate module if error 'not allowed' returned when loading module SQL queries (the _load_table run sql with run_sql with the error allowed parameter set to 'default')
		}

		// Create extrafields during init
		include_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';
		$extrafields = new ExtraFields($this->db);
		$param = array('options' => array(1 => 0));
		$sql = array();

		// Product extrafields
		$result = $extrafields->addExtraField(
			'pdpconnectfr_product_separator',
			$langs->trans('PdpConnectFRProductSeparator'),
			'separate',
			95020,
			'',
			'product',
			0,
			1,
			'',
			$param,
			1,
			'',
			1,
			0,
			'',
			'',
			'pdpconnectfr@pdpconnectfr',
			'isModEnabled("pdpconnectfr")'
		);
		// $result = $extrafields->addExtraField(
		// 	'pdpconnectfr_source',
		// 	$langs->trans('PdpConnectFRProductSource'),
		// 	'varchar',
		// 	95022,
		// 	100,
		// 	'product',
		// 	0,
		// 	0,
		// 	'',
		// 	null,
		// 	1,
		// 	'',
		// 	1,
		// 	0,
		// 	'',
		// 	'',
		// 	'pdpconnectfr@pdpconnectfr',
		// 	'isModEnabled("pdpconnectfr")',
		// 	0,
		// 	1
		// );

		// Invoice extrafields
		// Chorus fields
		// TODO : Remove Chorus extrafields and move them to pdpconnectfr_extlinks table
		$result = $extrafields->addExtraField('d4d_separator', $langs->trans('ChorusSeparator'), 'separate', 95024, '', 'facture', 0, 1, '', $param, 1, '', 1, 0, '', '', 'pdpconnectfr@pdpconnectfr', 'getDolGlobalInt("PDPCONNECTFR_USE_CHORUS")');
        $result = $extrafields->addExtraField('d4d_service_code', $langs->trans('ChorusServiceCode'), 'varchar', 95026, 100, 'facture', 0, 0, '', null, 1, '', 1, 0, '', '', 'pdpconnectfr@pdpconnectfr', 'getDolGlobalInt("PDPCONNECTFR_USE_CHORUS")', 0, 1);
        $result = $extrafields->addExtraField('d4d_contract_number', $langs->trans('ChorusContractNumber'), 'varchar', 95028, 50, 'facture', 0, 0, '', null, 1, '', 1, 0, '', '', 'pdpconnectfr@pdpconnectfr', 'getDolGlobalInt("PDPCONNECTFR_USE_CHORUS")', 0, 1);
        $result = $extrafields->addExtraField('d4d_promise_code', $langs->trans('ChorusPromiseCode'), 'varchar', 95030, 50, 'facture', 0, 0, '', null, 1, '', 1, 0, '', '', 'pdpconnectfr@pdpconnectfr', 'getDolGlobalInt("PDPCONNECTFR_USE_CHORUS")', 0, 1);
        $result = $extrafields->addExtraField('d4d_chorus_id', $langs->trans('ChorusId'), 'varchar', 95032, 36, 'facture', 0, 0, '', null, 1, '', 1, 0, '$object->array_options["options_chorus_id"]', '', 'pdpconnectfr@pdpconnectfr', 'getDolGlobalInt("PDPCONNECTFR_USE_CHORUS")', 0, 1);

		// Same fields for orders
        $result = $extrafields->addExtraField('d4d_separator', $langs->trans('ChorusSeparator'), 'separate', 95042, '', 'commande', 0, 1, '', $param, 1, '', 1, 0, '', '', 'pdpconnectfr@pdpconnectfr', 'getDolGlobalInt("PDPCONNECTFR_USE_CHORUS")');
        $result = $extrafields->addExtraField('d4d_service_code', $langs->trans('ChorusServiceCode'), 'varchar', 95044, 100, 'commande', 0, 0, '', null, 1, '', 1, 0, '', '', 'pdpconnectfr@pdpconnectfr', 'getDolGlobalInt("PDPCONNECTFR_USE_CHORUS")', 0, 1);
        $result = $extrafields->addExtraField('d4d_contract_number', $langs->trans('ChorusContractNumber'), 'varchar', 95046, 50, 'commande', 0, 0, '', null, 1, '', 1, 0, '', '', 'pdpconnectfr@pdpconnectfr', 'getDolGlobalInt("PDPCONNECTFR_USE_CHORUS")', 0, 1);
        $result = $extrafields->addExtraField('d4d_promise_code', $langs->trans('ChorusPromiseCode'), 'varchar', 95048, 50, 'commande', 0, 0, '', null, 1, '', 1, 0, '', '', 'pdpconnectfr@pdpconnectfr', 'getDolGlobalInt("PDPCONNECTFR_USE_CHORUS")', 0, 1);

		// Fix condition of extrafields for old installations
        $sql = array_merge(
            $sql,
            array(
                "UPDATE " . MAIN_DB_PREFIX . "extrafields SET enabled='getDolGlobalInt(\"PDPCONNECTFR_USE_CHORUS\")' WHERE enabled = '\$conf->pdpconnectfr->enabled'",
                "UPDATE " . MAIN_DB_PREFIX . "extrafields SET enabled='getDolGlobalInt(\"PDPCONNECTFR_USE_CHORUS\")' WHERE enabled = '\$conf->global->PDPCONNECTFR_USE_CHORUS'"
            )
        );

		// Update extrafield par rapport au module openDSI, il faut pouvoir éditer le champ ChorusId
        $result = $extrafields->update(
            'd4d_chorus_id', //$attrname
            $langs->trans('ChorusId'), //$label
            'varchar', //$type
            95050, //$length
            'facture', //$elementtype
            0, //$unique
            0, //$required
            1112, //$pos
            null, //$param
            1, //$alwayseditable
            '', //$perms
            1, //$list
            0, //$help
            '', //$default
            '', //$computerd
            '', //$entity
            'pdpconnectfr@pdpconnectfr', //$langfile
			'getDolGlobalInt("PDPCONNECTFR_USE_CHORUS")',
            0, //$totalizable
            0, //$printable
            array() //$moreparams
        );

			/*
			CREATE TABLE llx_pdpconnectfr_call(
				-- BEGIN MODULEBUILDER FIELDS
				rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
				date_creation datetime NOT NULL,
				tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				fk_user_creat integer NOT NULL,
				fk_user_modif integer,
				status integer NOT NULL, 			-- Status of the call
				call_type varchar(50) NOT NULL, 	-- Type of API call
				method varchar(10), 				-- HTTP method
				endpoint varchar(255) NOT NULL, 	-- API endpoint URL
				request_body text, 					-- Request body content (JSON)
				response text, 						-- Response content (JSON)
				entity integer DEFAULT 1, 			-- Entity
				fk_provider integer NOT NULL 		-- Foreign key to provider (EsaLink...)
				-- END MODULEBUILDER FIELDS
			) ENGINE=innodb;

			CREATE TABLE llx_pdpconnectfr_document(
				-- BEGIN MODULEBUILDER FIELDS
				rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
				date_creation datetime NOT NULL,
				tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				fk_user_creat integer NOT NULL,
				fk_user_modif integer,
				fk_element_id integer,
				fk_element_type varchar(50),
				status integer NOT NULL, 			-- Status of the document
				fk_call integer,		 			-- Reference to the original call
				flow_id integer, 					-- PDP Flow identifier
				tracking_id integer, 				-- Document tracking identifier
				flow_type varchar(255), 			-- Type of flow (CustomerInvoice, etc.)
				flow_direction varchar(10), 		-- Direction of flow (In/Out)
				flow_syntax varchar(50), 			-- Document syntax (FACTUR-X, etc.)
				flow_profile varchar(50), 			-- Profile used (Basic, Cius, etc.)
				ack_status varchar(50), 			-- Acknowledgment status (Success, Error, Pending)
				ack_reason_code varchar(255), 		-- Reason code for acknowledgment
				ack_info text, 						-- Additional acknowledgment information
				document_body text, 				-- Full document content XML
				entity integer DEFAULT 1 			-- Entity identifier
				-- END MODULEBUILDER FIELDS
			) ENGINE=innodb;

			*/

		//$result0=$extrafields->addExtraField('pdpconnectfr_separator1', "Separator 1", 'separator', 1,  0, 'thirdparty',   0, 0, '', array('options'=>array(1=>1)), 1, '', 1, 0, '', '', 'pdpconnectfr@pdpconnectfr', 'isModEnabled("pdpconnectfr")');
		//$result1=$extrafields->addExtraField('pdpconnectfr_myattr1', "New Attr 1 label", 'boolean', 1,  3, 'thirdparty',   0, 0, '', '', 1, '', -1, 0, '', '', 'pdpconnectfr@pdpconnectfr', 'isModEnabled("pdpconnectfr")');
		//$result2=$extrafields->addExtraField('pdpconnectfr_myattr2', "New Attr 2 label", 'varchar', 1, 10, 'project',      0, 0, '', '', 1, '', -1, 0, '', '', 'pdpconnectfr@pdpconnectfr', 'isModEnabled("pdpconnectfr")');
		//$result3=$extrafields->addExtraField('pdpconnectfr_myattr3', "New Attr 3 label", 'varchar', 1, 10, 'bank_account', 0, 0, '', '', 1, '', -1, 0, '', '', 'pdpconnectfr@pdpconnectfr', 'isModEnabled("pdpconnectfr")');
		//$result4=$extrafields->addExtraField('pdpconnectfr_myattr4', "New Attr 4 label", 'select',  1,  3, 'thirdparty',   0, 1, '', array('options'=>array('code1'=>'Val1','code2'=>'Val2','code3'=>'Val3')), 1,'', -1, 0, '', '', 'pdpconnectfr@pdpconnectfr', 'isModEnabled("pdpconnectfr")');
		//$result5=$extrafields->addExtraField('pdpconnectfr_myattr5', "New Attr 5 label", 'text',    1, 10, 'user',         0, 0, '', '', 1, '', -1, 0, '', '', 'pdpconnectfr@pdpconnectfr', 'isModEnabled("pdpconnectfr")');

		// Permissions
		$this->remove($options);


		// Document templates
		// $moduledir = dol_sanitizeFileName('pdpconnectfr');
		// $myTmpObjects = array();
		// $myTmpObjects['Call'] = array('includerefgeneration' => 0, 'includedocgeneration' => 0);

		// foreach ($myTmpObjects as $myTmpObjectKey => $myTmpObjectArray) {
		// 	if ($myTmpObjectArray['includerefgeneration']) {
		// 		$src = DOL_DOCUMENT_ROOT.'/install/doctemplates/'.$moduledir.'/template_calls.odt';
		// 		$dirodt = DOL_DATA_ROOT.($conf->entity > 1 ? '/'.$conf->entity : '').'/doctemplates/'.$moduledir;
		// 		$dest = $dirodt.'/template_calls.odt';

		// 		if (file_exists($src) && !file_exists($dest)) {
		// 			require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
		// 			dol_mkdir($dirodt);
		// 			$result = dol_copy($src, $dest, '0', 0);
		// 			if ($result < 0) {
		// 				$langs->load("errors");
		// 				$this->error = $langs->trans('ErrorFailToCopyFile', $src, $dest);
		// 				return 0;
		// 			}
		// 		}

		// 		$sql = array_merge($sql, array(
		// 			"DELETE FROM ".$this->db->prefix()."document_model WHERE nom = 'standard_".strtolower($myTmpObjectKey)."' AND type = '".$this->db->escape(strtolower($myTmpObjectKey))."' AND entity = ".((int) $conf->entity),
		// 			"INSERT INTO ".$this->db->prefix()."document_model (nom, type, entity) VALUES('standard_".strtolower($myTmpObjectKey)."', '".$this->db->escape(strtolower($myTmpObjectKey))."', ".((int) $conf->entity).")",
		// 			"DELETE FROM ".$this->db->prefix()."document_model WHERE nom = 'generic_".strtolower($myTmpObjectKey)."_odt' AND type = '".$this->db->escape(strtolower($myTmpObjectKey))."' AND entity = ".((int) $conf->entity),
		// 			"INSERT INTO ".$this->db->prefix()."document_model (nom, type, entity) VALUES('generic_".strtolower($myTmpObjectKey)."_odt', '".$this->db->escape(strtolower($myTmpObjectKey))."', ".((int) $conf->entity).")"
		// 		));
		// 	}
		// }

		if (!getDolGlobalString('PDPCONNECTFR_PDP')) {
			// Set the live mode to on, but only if it it the first time we enable the module
			$sqltmp = "INSERT INTO ".MAIN_DB_PREFIX."const (name, value, visible, entity, note) VALUES";
			$sqltmp .= " ('PDPCONNECTFR_LIVE'";
			$sqltmp .= ", ".$this->db->encrypt('1');
			$sqltmp .= ", 0, ".((int) $conf->entity);
			$sqltmp .= ", '')";
			$this->db->query($sqltmp);
		}

		return $this->_init($sql, $options);
	}

	/**
	 *	Function called when module is disabled.
	 *	Remove from database constants, boxes and permissions from Dolibarr database.
	 *	Data directories are not deleted
	 *
	 *	@param	string		$options	Options when enabling module ('', 'noboxes')
	 *	@return	int<-1,1>				1 if OK, <=0 if KO
	 */
	public function remove($options = '')
	{
		$sql = array();
		return $this->_remove($sql, $options);
	}
}
