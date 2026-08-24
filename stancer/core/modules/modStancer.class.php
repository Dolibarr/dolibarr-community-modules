<?php
/* Copyright (C) 2004-2018  Laurent Destailleur     <eldy@users.sourceforge.net>
 * Copyright (C) 2018-2019  Nicolas ZABOURI         <info@inovea-conseil.com>
 * Copyright (C) 2019-2020  Frédéric France         <frederic.france@netlogic.fr>
 * Copyright (C) 2023 Eric Seigne <eric.seigne@cap-rel.fr>
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
 * 	\defgroup   stancer     Module Stancer
 *  \brief      Stancer module descriptor.
 *
 *  \file       htdocs/stancer/core/modules/modStancer.class.php
 *  \ingroup    stancer
 *  \brief      Description and activation file for module Stancer
 */
include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/security2.lib.php';

/**
 *  Description and activation class for module Stancer
 */
class modStancer extends DolibarrModules
{
	public $url_last_version;
	public $tabs;
	public $dictionaries;

	/**
	 * Constructor. Define names, constants, directories, boxes, permissions
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		global $langs, $conf;
		$this->db = $db;

		// Id for module (must be unique).
		// Use here a free id (See in Home -> System information -> Dolibarr for list of used modules id).
		$this->numero = 471051;

		// Key text used to identify module (for permissions, menus, etc...)
		$this->rights_class = 'stancer';

		// Family can be 'base' (core modules),'crm','financial','hr','projects','products','ecm','technic' (transverse modules),'interface' (link with external tools),'other','...'
		// It is used to group modules by family in module setup page
		$this->family = "interface";

		// Module position in the family on 2 digits ('01', '10', '20', ...)
		$this->module_position = '90';

		// Gives the possibility for the module, to provide his own family info and position of this family (Overwrite $this->family and $this->module_position. Avoid this)
		//$this->familyinfo = array('myownfamily' => array('position' => '01', 'label' => $langs->trans("MyOwnFamily")));
		// Module label (no space allowed), used if translation string 'ModuleStancerName' not found (Stancer is name of module).
		$this->name = preg_replace('/^mod/i', '', get_class($this));

		// Module description, used if translation string 'ModuleStancerDesc' not found (Stancer is name of module).
		$this->description = "StancerDescription";
		// Used only if file README.md and README-LL.md not found.
		$this->descriptionlong = "StancerDescription";

		// Author
		$this->editor_name = 'CAP-REL';
		$this->editor_url = 'https://cap-rel.fr';

		// Possible values for version are: 'development', 'experimental', 'dolibarr', 'dolibarr_deprecated', 'experimental_deprecated' or a version string like 'x.y.z'
		$this->version = '2.0.22';
		// Url to the file with your last numberversion of this module
		$this->url_last_version = "https://cap-rel.fr/dolibarr/ver.php?m=" . $this->rights_class . "&v=" . $this->version . "&d=" . DOL_VERSION;

		// Key used in llx_const table to save module status enabled/disabled (where STANCER is value of property name of module in uppercase)
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);

		// Name of image file used for this module.
		// If file is in theme/yourtheme/img directory under name object_pictovalue.png, use this->picto='pictovalue'
		// If file is in module/img directory under name object_pictovalue.png, use this->picto='pictovalue@module'
		// To use a supported fa-xxx css style of font awesome, use this->picto='xxx'
		$this->picto = 'stancer@stancer';

		// Define some features supported by module (triggers, login, substitutions, menus, css, etc...)
		$this->module_parts = array(
			// Set this to 1 if module has its own trigger directory (core/triggers)
			'triggers' => 1,
			// Set this to 1 if module has its own login method file (core/login)
			'login' => 0,
			// Set this to 1 if module has its own substitution function file (core/substitutions)
			'substitutions' => 1,
			// Set this to 1 if module has its own menus handler directory (core/menus)
			'menus' => 0,
			// Set this to 1 if module overwrite template dir (core/tpl)
			'tpl' => 0,
			// Set this to 1 if module has its own barcode directory (core/modules/barcode)
			'barcode' => 0,
			// Set this to 1 if module has its own models directory (core/modules/xxx)
			'models' => 1,
			// Set this to 1 if module has its own printing directory (core/modules/printing)
			'printing' => 0,
			// Set this to 1 if module has its own theme directory (theme)
			'theme' => 0,
			// Set this to relative path of css file if module has its own css file
			'css' => array(
				   '/stancer/css/stancer.css',
			),
			// Set this to relative path of js file if module must load a js on all pages
			'js' => array(
				//   '/stancer/js/stancer.js.php',
			),
			// Set here all hooks context managed by module. To find available hook context, make a "grep -r '>initHooks(' *" on source code. You can also set hook context to 'all'
			'hooks' => array(
				'newpayment',
				'paymentlib',
				// 'globalcard',
				'invoicecard',
				'invoicesuppliercard',
				'ordercard',
				'propalcard',
				'formObjectOptions',
				'thirdpartybancard',
				'printFieldListTitle',
				'printFieldListValue',
				'subscription',
				'doncard',
				'membercard',
				'bankline',
				//   'data' => array(
				//       'hookcontext1',
				//       'hookcontext2',
				//   ),
				//   'entity' => '0',
			),
			// Set this to 1 if features of module are opened to external users
			'moduleforexternal' => 0,
		);

		// Data directories to create when module is enabled.
		// Example: this->dirs = array("/stancer/temp","/stancer/subdir");
		$this->dirs = array("/stancer/temp");

		// Config pages. Put here list of php page, stored into stancer/admin directory, to use to setup module.
		$this->config_page_url = array("setup.php@stancer");

		// Dependencies
		// A condition to hide module
		$this->hidden = false;
		// List of module class names as string that must be enabled if this module is enabled. Example: array('always1'=>'modModuleToEnable1','always2'=>'modModuleToEnable2', 'FR1'=>'modModuleToEnableFR'...)
		$this->depends = array('always1'=>'modPrelevement', 'always2'=>'modBanque');
		$this->requiredby = array(); // List of module class names as string to disable if this one is disabled. Example: array('modModuleToDisable1', ...)
		$this->conflictwith = array(); // List of module class names as string this module is in conflict with. Example: array('modModuleToDisable1', ...)

		// The language file dedicated to your module
		$this->langfiles = array("stancer@stancer");

		// Prerequisites
		$this->phpmin = array(7, 4); // Minimum version of PHP required by module
		$this->need_dolibarr_version = array(15, -3); // Minimum version of Dolibarr required by module

		// Messages at activation
		$this->warnings_activation = array(); // Warning to show when we activate module. array('always'='text') or array('FR'='textfr','MX'='textmx'...)
		$this->warnings_activation_ext = array(); // Warning to show when we activate an external module. array('always'='text') or array('FR'='textfr','MX'='textmx'...)
		//$this->automatic_activation = array('FR'=>'StancerWasAutomaticallyActivatedBecauseOfYourCountryChoice');
		//$this->always_enabled = true;								// If true, can't be disabled

		// Constants
		// List of particular constants to add when module is enabled (key, 'chaine', value, desc, visible, 'current' or 'allentities', deleteonunactive)
		// Example: $this->const=array(1 => array('STANCER_MYNEWCONST1', 'chaine', 'myvalue', 'This is a constant to add', 1),
		//                             2 => array('STANCER_MYNEWCONST2', 'chaine', 'myvalue', 'This is another constant to add', 0, 'current', 1)
		// );
		$this->const = array();

		// Some keys to add into the overwriting translation tables
		/*$this->overwrite_translation = array(
			'en_US:ParentCompany'=>'Parent company or reseller',
			'fr_FR:ParentCompany'=>'Maison mère ou revendeur'
		)*/

		if (!isset($conf->stancer) || !isset($conf->stancer->enabled)) {
			$conf->stancer = new stdClass();
			$conf->stancer->enabled = 0;
		}

		// Array to add new pages in new tabs
		$this->tabs = array();
		$this->tabs[] = array('data'=>'thirdparty:+tabStancer:Stancer:stancer@stancer:$user->hasRight(\'stancer\',\'write\'):/stancer/stancer_thirdparty.php?socid=__ID__');

		// Example:
		// $this->tabs[] = array('data'=>'objecttype:+tabname1:Title1:mylangfile@stancer:$user->rights->stancer->read:/stancer/mynewtab1.php?id=__ID__');  					// To add a new tab identified by code tabname1
		// $this->tabs[] = array('data'=>'objecttype:+tabname2:SUBSTITUTION_Title2:mylangfile@stancer:$user->rights->othermodule->read:/stancer/mynewtab2.php?id=__ID__',  	// To add another new tab identified by code tabname2. Label will be result of calling all substitution functions on 'Title2' key.
		// $this->tabs[] = array('data'=>'objecttype:-tabname:NU:conditiontoremove');                                                     										// To remove an existing tab identified by code tabname
		//
		// Where objecttype can be
		// 'categories_x'	  to add a tab in category view (replace 'x' by type of category (0=product, 1=supplier, 2=customer, 3=member)
		// 'contact'          to add a tab in contact view
		// 'contract'         to add a tab in contract view
		// 'group'            to add a tab in group view
		// 'intervention'     to add a tab in intervention view
		// 'invoice'          to add a tab in customer invoice view
		// 'invoice_supplier' to add a tab in supplier invoice view
		// 'member'           to add a tab in fundation member view
		// 'opensurveypoll'	  to add a tab in opensurvey poll view
		// 'order'            to add a tab in sale order view
		// 'order_supplier'   to add a tab in supplier order view
		// 'payment'		  to add a tab in payment view
		// 'payment_supplier' to add a tab in supplier payment view
		// 'product'          to add a tab in product view
		// 'propal'           to add a tab in propal view
		// 'project'          to add a tab in project view
		// 'stock'            to add a tab in stock view
		// 'thirdparty'       to add a tab in third party view
		// 'user'             to add a tab in user view

		// Dictionaries
		$this->dictionaries = array();
		/* Example:
		$this->dictionaries=array(
			'langs'=>'stancer@stancer',
			// List of tables we want to see into dictonnary editor
			'tabname'=>array("table1", "table2", "table3"),
			// Label of tables
			'tablib'=>array("Table1", "Table2", "Table3"),
			// Request to select fields
			'tabsql'=>array('SELECT f.rowid as rowid, f.code, f.label, f.active FROM '.MAIN_DB_PREFIX.'table1 as f', 'SELECT f.rowid as rowid, f.code, f.label, f.active FROM '.MAIN_DB_PREFIX.'table2 as f', 'SELECT f.rowid as rowid, f.code, f.label, f.active FROM '.MAIN_DB_PREFIX.'table3 as f'),
			// Sort order
			'tabsqlsort'=>array("label ASC", "label ASC", "label ASC"),
			// List of fields (result of select to show dictionary)
			'tabfield'=>array("code,label", "code,label", "code,label"),
			// List of fields (list of fields to edit a record)
			'tabfieldvalue'=>array("code,label", "code,label", "code,label"),
			// List of fields (list of fields for insert)
			'tabfieldinsert'=>array("code,label", "code,label", "code,label"),
			// Name of columns with primary key (try to always name it 'rowid')
			'tabrowid'=>array("rowid", "rowid", "rowid"),
			// Condition to show each dictionary
			'tabcond'=>array($conf->stancer->enabled, $conf->stancer->enabled, $conf->stancer->enabled),
			// Tooltip for every fields of dictionaries: DO NOT PUT AN EMPTY ARRAY
			'tabhelp'=>array(array('code'=>$langs->trans('CodeTooltipHelp'), 'field2' => 'field2tooltip'), array('code'=>$langs->trans('CodeTooltipHelp'), 'field2' => 'field2tooltip'), ...),
		);
		*/

		// Boxes/Widgets
		// Add here list of php file(s) stored in stancer/core/boxes that contains a class to show a widget.
		$this->boxes = array(
			//  0 => array(
			//      'file' => 'stancerwidget1.php@stancer',
			//      'note' => 'Widget provided by Stancer',
			//      'enabledbydefaulton' => 'Home',
			//  ),
			//  ...
		);

		// Cronjobs (List of cron jobs entries to add when module is enabled)
		// random time during the night (take care of stancer infra in case of all dolibarr make same request at the same time)
		$arraydate = dol_getdate(dol_now());
		$datestart = dol_mktime(rand(0, 6), rand(0, 59), 0, $arraydate['mon'], $arraydate['mday'], $arraydate['year']);
		$this->cronjobs = array(
			 0 => array(
				 'label' => 'StancerCheckPay',
				 'jobtype' => 'method',
				 'class' => '/stancer/class/stancer.class.php',
				 'objectname' => 'Stancer',
				 'method' => 'doScheduledJob',
				 'parameters' => '',
				 'comment' => 'StancerCheckPaymentAndPayout',
				 'frequency' => 1,
				 'unitfrequency' => 3600 * 24,
				 'status' => 0,
				 'test' => '$conf->stancer->enabled',
				 'priority' => 50,
				 'datestart'=>$datestart
			),
			1 => array(
				'label' => 'StancerCheckTakePayments',
				'jobtype' => 'method',
				'class' => '/stancer/class/stancer.class.php',
				'objectname' => 'Stancer',
				'method' => 'doTakePaymentStancer',
				'parameters' => '0, 0, null, true',
				'comment' => 'StancerCheckTakePaymentsDetails',
				'frequency' => 1,
				'unitfrequency' => 3600 * 24,
				'status' => 0,
				'test' => '$conf->stancer->enabled',
				'priority' => 50,
				'datestart'=>$datestart
			),
			2 => array(
				'label' => 'StancerCheckInvoicesPaid',
				'jobtype' => 'method',
				'class' => '/stancer/class/stancer.class.php',
				'objectname' => 'Stancer',
				'method' => 'doCheckInvoicesPaid',
				'parameters' => '0, 0, null, true',
				'comment' => 'StancerCheckInvoicesPaidDetails',
				'frequency' => 1,
				'unitfrequency' => 3600 * 24,
				'status' => 0,
				'test' => '$conf->stancer->enabled',
				'priority' => 50,
				'datestart'=>$datestart
		   ),
			3 => array(
				'label' => 'StancerSendPaymentReminders',
				'jobtype' => 'method',
				'class' => '/stancer/class/stancer.class.php',
				'objectname' => 'Stancer',
				'method' => 'doSendPaymentReminders',
				'parameters' => '',
				'comment' => 'StancerSendPaymentRemindersDetails',
				'frequency' => 1,
				'unitfrequency' => 3600 * 24,
				'status' => 0,
				'test' => '$conf->stancer->enabled',
				'priority' => 50,
				'datestart' => $datestart
		   ),
			4 => array(
				'label' => 'StancerSendPendingValidationMails',
				'jobtype' => 'method',
				'class' => '/stancer/class/stancer.class.php',
				'objectname' => 'Stancer',
				'method' => 'doSendPendingValidationMails',
				'parameters' => '',
				'comment' => 'StancerSendPendingValidationMailsDetails',
				'frequency' => 1,
				'unitfrequency' => 3600,
				'status' => 0,
				'test' => '$conf->stancer->enabled',
				'priority' => 50,
				'datestart' => $datestart
		   )
		);

		// Example: $this->cronjobs=array(
		//    0=>array('label'=>'My label', 'jobtype'=>'method', 'class'=>'/dir/class/file.class.php', 'objectname'=>'MyClass', 'method'=>'myMethod', 'parameters'=>'param1, param2', 'comment'=>'Comment', 'frequency'=>2, 'unitfrequency'=>3600, 'status'=>0, 'test'=>'$conf->stancer->enabled', 'priority'=>50),
		//    1=>array('label'=>'My label', 'jobtype'=>'command', 'command'=>'', 'parameters'=>'param1, param2', 'comment'=>'Comment', 'frequency'=>1, 'unitfrequency'=>3600*24, 'status'=>0, 'test'=>'$conf->stancer->enabled', 'priority'=>50)
		// );

		// Permissions provided by this module
		$this->rights = array();
		$r = 0;
		// Add here entries to declare new permissions
		/* BEGIN MODULEBUILDER PERMISSIONS */
		$this->rights[$r][0] = $this->numero . sprintf("%02d", $r + 1); // Permission id (must not be already used)
		$this->rights[$r][1] = 'Read Stancer'; // Permission label
		$this->rights[$r][4] = 'read';
		$r++;
		$this->rights[$r][0] = $this->numero . sprintf("%02d", $r + 1); // Permission id (must not be already used)
		$this->rights[$r][1] = 'Create/Update Stancer'; // Permission label
		$this->rights[$r][4] = 'write';
		$r++;
		// $this->rights[$r][0] = $this->numero . sprintf("%02d", $r + 1); // Permission id (must not be already used)
		// $this->rights[$r][1] = 'Delete objects of Stancer'; // Permission label
		// $this->rights[$r][4] = 'stancer';
		// $this->rights[$r][5] = 'delete'; // In php code, permission will be checked by test if ($user->rights->stancer->stancer->delete)
		// $r++;
		/* END MODULEBUILDER PERMISSIONS */

		// Main menu entries to add
		$this->menu = array();
		$r = 0;
		// Add here entries to declare new menus
		/* BEGIN MODULEBUILDER TOPMENU */
		// $this->menu[$r++] = array(
		// 	'fk_menu'=>'', // '' if this is a top menu. For left menu, use 'fk_mainmenu=xxx' or 'fk_mainmenu=xxx,fk_leftmenu=yyy' where xxx is mainmenucode and yyy is a leftmenucode
		// 	'type'=>'top', // This is a Top menu entry
		// 	'titre'=>'ModuleStancerName',
		// 	'prefix' => img_picto('', $this->picto, 'class="paddingright pictofixedwidth valignmiddle"'),
		// 	'mainmenu'=>'stancer',
		// 	'leftmenu'=>'',
		// 	'url'=>'/stancer/stancerindex.php',
		// 	'langs'=>'stancer@stancer', // Lang file to use (without .lang) by module. File must be in langs/code_CODE/ directory.
		// 	'position'=>1000 + $r,
		// 	'enabled'=>'isModEnabled("stancer")', // Define condition to show or hide menu entry. Use 'isModEnabled("stancer")' if entry must be visible if module is enabled.
		// 	'perms'=>'1', // Use 'perms'=>'$user->hasRight("stancer", "stancer", "read")' if you want your menu with a permission rules
		// 	'target'=>'',
		// 	'user'=>2, // 0=Menu for internal users, 1=external users, 2=both
		// );
		/* END MODULEBUILDER TOPMENU */
		/* BEGIN MODULEBUILDER LEFTMENU STANCER */
		$this->menu[$r++]=array(
			'fk_menu'=>'fk_mainmenu=bank',      // '' if this is a top menu. For left menu, use 'fk_mainmenu=xxx' or 'fk_mainmenu=xxx,fk_leftmenu=yyy' where xxx is mainmenucode and yyy is a leftmenucode
			'type'=>'left',                          // This is a Left menu entry
			'titre'=>'Stancer',
			// 'prefix' => img_picto('', $this->picto, 'class="paddingright pictofixedwidth valignmiddle"'),
			'mainmenu'=>'bank',
			'leftmenu'=>'stancer',
			'url'=>'/stancer/index.php',
			'langs'=>'stancer@stancer',	        // Lang file to use (without .lang) by module. File must be in langs/code_CODE/ directory.
			'position'=>1000+$r,
			'enabled'=>'$conf->stancer->enabled', // Define condition to show or hide menu entry. Use 'isModEnabled("stancer")' if entry must be visible if module is enabled.
			'perms'=>'$user->hasRight(\'stancer\',\'read\')',
			'target'=>'',
			'user'=>2,				                // 0=Menu for internal users, 1=external users, 2=both
		);

		$this->menu[$r++]=array(
			'fk_menu'=>'fk_mainmenu=bank,fk_leftmenu=stancer',	    // '' if this is a top menu. For left menu, use 'fk_mainmenu=xxx' or 'fk_mainmenu=xxx,fk_leftmenu=yyy' where xxx is mainmenucode and yyy is a leftmenucode
			'type'=>'left',			                // This is a Left menu entry
			'titre'=>'StancerPayments',
			'mainmenu'=>'bank',
			'leftmenu'=>'stancer_payments',
			'url'=>'/stancer/stancer_payments_list.php',
			'langs'=>'stancer@stancer',	        // Lang file to use (without .lang) by module. File must be in langs/code_CODE/ directory.
			'position'=>1000+$r,
			'enabled'=>'$conf->stancer->enabled', // Define condition to show or hide menu entry. Use 'isModEnabled("stancer")' if entry must be visible if module is enabled. Use '$leftmenu==\'system\'' to show if leftmenu system is selected.
			'perms'=>'$user->hasRight(\'stancer\',\'read\')',
			'target'=>'',
			'user'=>2,				                // 0=Menu for internal users, 1=external users, 2=both
		);

		$this->menu[$r++]=array(
			'fk_menu'=>'fk_mainmenu=bank,fk_leftmenu=stancer',	    // '' if this is a top menu. For left menu, use 'fk_mainmenu=xxx' or 'fk_mainmenu=xxx,fk_leftmenu=yyy' where xxx is mainmenucode and yyy is a leftmenucode
			'type'=>'left',			                // This is a Left menu entry
			'titre'=>'StancerPayouts',
			'mainmenu'=>'bank',
			'leftmenu'=>'stancer_payouts',
			'url'=>'/stancer/stancer_payouts_list.php',
			'langs'=>'stancer@stancer',	        // Lang file to use (without .lang) by module. File must be in langs/code_CODE/ directory.
			'position'=>1000+$r,
			'enabled'=>'$conf->stancer->enabled', // Define condition to show or hide menu entry. Use 'isModEnabled("stancer")' if entry must be visible if module is enabled.
			'perms'=>'$user->hasRight(\'stancer\',\'read\')',
			'target'=>'',
			'user'=>2,				                // 0=Menu for internal users, 1=external users, 2=both
		);

		$this->menu[$r++]=array(
			'fk_menu'=>'fk_mainmenu=bank,fk_leftmenu=stancer',
			'type'=>'left',
			'titre'=>'StancerRefunds',
			'mainmenu'=>'bank',
			'leftmenu'=>'stancer_refunds',
			'url'=>'/stancer/stancer_refunds_list.php',
			'langs'=>'stancer@stancer',
			'position'=>1000+$r,
			'enabled'=>'$conf->stancer->enabled',
			'perms'=>'$user->hasRight(\'stancer\',\'read\')',
			'target'=>'',
			'user'=>2,
		);

		$this->menu[$r++]=array(
			'fk_menu'=>'fk_mainmenu=bank,fk_leftmenu=stancer',
			'type'=>'left',
			'titre'=>'StancerDisputes',
			'mainmenu'=>'bank',
			'leftmenu'=>'stancer_disputes',
			'url'=>'/stancer/stancer_disputes_list.php',
			'langs'=>'stancer@stancer',
			'position'=>1000+$r,
			'enabled'=>'$conf->stancer->enabled',
			'perms'=>'$user->hasRight(\'stancer\',\'read\')',
			'target'=>'',
			'user'=>2,
		);

		$this->menu[$r++]=array(
			'fk_menu'=>'fk_mainmenu=bank,fk_leftmenu=stancer',
			'type'=>'left',
			'titre'=>'StancerAuditTitle',
			'mainmenu'=>'bank',
			'leftmenu'=>'stancer_audit',
			'url'=>'/stancer/stancer_audit.php',
			'langs'=>'stancer@stancer',
			'position'=>1000+$r,
			'enabled'=>'$conf->stancer->enabled && $user->admin',
			'perms'=>'$user->admin',
			'target'=>'',
			'user'=>0,
		);

		$this->menu[$r++]=array(
			'fk_menu'=>'fk_mainmenu=bank,fk_leftmenu=stancer',
			'type'=>'left',
			'titre'=>'StancerRepairTitle',
			'mainmenu'=>'bank',
			'leftmenu'=>'stancer_repair',
			'url'=>'/stancer/stancer_repair.php',
			'langs'=>'stancer@stancer',
			'position'=>1000+$r,
			'enabled'=>'$conf->stancer->enabled && $user->admin',
			'perms'=>'$user->admin',
			'target'=>'',
			'user'=>0,
		);

		// $this->menu[$r++]=array(
		//     // '' if this is a top menu. For left menu, use 'fk_mainmenu=xxx' or 'fk_mainmenu=xxx,fk_leftmenu=yyy' where xxx is mainmenucode and yyy is a leftmenucode
		//     'fk_menu'=>'fk_mainmenu=stancer',
		//     // This is a Left menu entry
		//     'type'=>'left',
		//     'titre'=>'List Stancer',
		//     'mainmenu'=>'stancer',
		//     'leftmenu'=>'stancer_stancer',
		//     'url'=>'/stancer/stancer_list.php',
		//     // Lang file to use (without .lang) by module. File must be in langs/code_CODE/ directory.
		//     'langs'=>'stancer@stancer',
		//     'position'=>1100+$r,
		//     // Define condition to show or hide menu entry. Use '$conf->stancer->enabled' if entry must be visible if module is enabled. Use '$leftmenu==\'system\'' to show if leftmenu system is selected.
		//     'enabled'=>'$conf->stancer->enabled',
		//     // Use 'perms'=>'$user->rights->stancer->level1->level2' if you want your menu with a permission rules
		//     'perms'=>'1',
		//     'target'=>'',
		//     // 0=Menu for internal users, 1=external users, 2=both
		//     'user'=>2,
		// );
		// $this->menu[$r++]=array(
		//     // '' if this is a top menu. For left menu, use 'fk_mainmenu=xxx' or 'fk_mainmenu=xxx,fk_leftmenu=yyy' where xxx is mainmenucode and yyy is a leftmenucode
		//     'fk_menu'=>'fk_mainmenu=stancer,fk_leftmenu=stancer_stancer',
		//     // This is a Left menu entry
		//     'type'=>'left',
		//     'titre'=>'New Stancer',
		//     'mainmenu'=>'stancer',
		//     'leftmenu'=>'stancer_stancer',
		//     'url'=>'/stancer/stancer_card.php?action=create',
		//     // Lang file to use (without .lang) by module. File must be in langs/code_CODE/ directory.
		//     'langs'=>'stancer@stancer',
		//     'position'=>1100+$r,
		//     // Define condition to show or hide menu entry. Use '$conf->stancer->enabled' if entry must be visible if module is enabled. Use '$leftmenu==\'system\'' to show if leftmenu system is selected.
		//     'enabled'=>'$conf->stancer->enabled',
		//     // Use 'perms'=>'$user->rights->stancer->level1->level2' if you want your menu with a permission rules
		//     'perms'=>'1',
		//     'target'=>'',
		//     // 0=Menu for internal users, 1=external users, 2=both
		//     'user'=>2
		// );

		/* END MODULEBUILDER LEFTMENU STANCER */
		// Exports profiles provided by this module
		// $r = 1;
		/* BEGIN MODULEBUILDER EXPORT STANCER */
		/*
		$langs->load("stancer@stancer");
		$this->export_code[$r]=$this->rights_class.'_'.$r;
		$this->export_label[$r]='StancerLines';	// Translation key (used only if key ExportDataset_xxx_z not found)
		$this->export_icon[$r]='stancer@stancer';
		// Define $this->export_fields_array, $this->export_TypeFields_array and $this->export_entities_array
		$keyforclass = 'Stancer'; $keyforclassfile='/stancer/class/stancer.class.php'; $keyforelement='stancer@stancer';
		include DOL_DOCUMENT_ROOT.'/core/commonfieldsinexport.inc.php';
		//$this->export_fields_array[$r]['t.fieldtoadd']='FieldToAdd'; $this->export_TypeFields_array[$r]['t.fieldtoadd']='Text';
		//unset($this->export_fields_array[$r]['t.fieldtoremove']);
		//$keyforclass = 'StancerLine'; $keyforclassfile='/stancer/class/stancer.class.php'; $keyforelement='stancerline@stancer'; $keyforalias='tl';
		//include DOL_DOCUMENT_ROOT.'/core/commonfieldsinexport.inc.php';
		$keyforselect='stancer'; $keyforaliasextra='extra'; $keyforelement='stancer@stancer';
		include DOL_DOCUMENT_ROOT.'/core/extrafieldsinexport.inc.php';
		//$keyforselect='stancerline'; $keyforaliasextra='extraline'; $keyforelement='stancerline@stancer';
		//include DOL_DOCUMENT_ROOT.'/core/extrafieldsinexport.inc.php';
		//$this->export_dependencies_array[$r] = array('stancerline'=>array('tl.rowid','tl.ref')); // To force to activate one or several fields if we select some fields that need same (like to select a unique key if we ask a field of a child to avoid the DISTINCT to discard them, or for computed field than need several other fields)
		//$this->export_special_array[$r] = array('t.field'=>'...');
		//$this->export_examplevalues_array[$r] = array('t.field'=>'Example');
		//$this->export_help_array[$r] = array('t.field'=>'FieldDescHelp');
		$this->export_sql_start[$r]='SELECT DISTINCT ';
		$this->export_sql_end[$r]  =' FROM '.MAIN_DB_PREFIX.'stancer as t';
		//$this->export_sql_end[$r]  =' LEFT JOIN '.MAIN_DB_PREFIX.'stancer_line as tl ON tl.fk_stancer = t.rowid';
		$this->export_sql_end[$r] .=' WHERE 1 = 1';
		$this->export_sql_end[$r] .=' AND t.entity IN ('.getEntity('stancer').')';
		$r++; */
		/* END MODULEBUILDER EXPORT STANCER */

		// Imports profiles provided by this module
		// $r = 1;
		/* BEGIN MODULEBUILDER IMPORT STANCER */
		/*
		$langs->load("stancer@stancer");
		$this->import_code[$r]=$this->rights_class.'_'.$r;
		$this->import_label[$r]='StancerLines';	// Translation key (used only if key ExportDataset_xxx_z not found)
		$this->import_icon[$r]='stancer@stancer';
		$this->import_tables_array[$r] = array('t' => MAIN_DB_PREFIX.'stancer_stancer', 'extra' => MAIN_DB_PREFIX.'stancer_stancer_extrafields');
		$this->import_tables_creator_array[$r] = array('t' => 'fk_user_author'); // Fields to store import user id
		$import_sample = array();
		$keyforclass = 'Stancer'; $keyforclassfile='/stancer/class/stancer.class.php'; $keyforelement='stancer@stancer';
		include DOL_DOCUMENT_ROOT.'/core/commonfieldsinimport.inc.php';
		$import_extrafield_sample = array();
		$keyforselect='stancer'; $keyforaliasextra='extra'; $keyforelement='stancer@stancer';
		include DOL_DOCUMENT_ROOT.'/core/extrafieldsinimport.inc.php';
		$this->import_fieldshidden_array[$r] = array('extra.fk_object' => 'lastrowid-'.MAIN_DB_PREFIX.'stancer_stancer');
		$this->import_regex_array[$r] = array();
		$this->import_examplevalues_array[$r] = array_merge($import_sample, $import_extrafield_sample);
		$this->import_updatekeys_array[$r] = array('t.ref' => 'Ref');
		$this->import_convertvalue_array[$r] = array(
			't.ref' => array(
				'rule'=>'getrefifauto',
				'class'=>(getDolGlobalString('STANCER_STANCER_ADDON','') == '' ? 'mod_stancer_standard' : getDolGlobalString('STANCER_STANCER_ADDON')),
				'path'=>"/core/modules/commande/".(getDolGlobalString('STANCER_STANCER_ADDON','') == '' ? 'mod_stancer_standard' : getDolGlobalString('STANCER_STANCER_ADDON')).'.php'
				'classobject'=>'Stancer',
				'pathobject'=>'/stancer/class/stancer.class.php',
			),
			't.fk_soc' => array('rule' => 'fetchidfromref', 'file' => '/societe/class/societe.class.php', 'class' => 'Societe', 'method' => 'fetch', 'element' => 'ThirdParty'),
			't.fk_user_valid' => array('rule' => 'fetchidfromref', 'file' => '/user/class/user.class.php', 'class' => 'User', 'method' => 'fetch', 'element' => 'user'),
			't.fk_mode_reglement' => array('rule' => 'fetchidfromcodeorlabel', 'file' => '/compta/paiement/class/cpaiement.class.php', 'class' => 'Cpaiement', 'method' => 'fetch', 'element' => 'cpayment'),
		);
		$r++; */
		/* END MODULEBUILDER IMPORT STANCER */
	}

	/**
	 *  Function called when module is enabled.
	 *  The init function add constants, boxes, permissions and menus (defined in constructor) into Dolibarr database.
	 *  It also creates data directories
	 *
	 *  @param      string  $options    Options when enabling module ('', 'noboxes')
	 *  @return     int             	1 if OK, 0 if KO
	 */
	public function init($options = '')
	{
		global $conf, $langs, $mysoc, $user;

		// Read installed module version to allow targeted re-runnable migrations (MODULE.md point 15)
		$installedVersion = explode('.', getDolGlobalString('STANCER_MODULE_VERSION', ''));

		//$result = $this->_load_tables('/install/mysql/', 'stancer');
		$result = $this->_load_tables('/stancer/sql/');
		if ($result < 0) {
			dol_syslog("stancer ****************** Stancer SQL UPDATE ERROR", LOG_ERR);
			return -1; // Do not activate module if error 'not allowed' returned when loading module SQL queries (the _load_table run sql with run_sql with the error allowed parameter set to 'default')
		} else {
			dol_syslog("stancer ****************** Stancer SQL UPDATE OK", LOG_DEBUG);
		}
		dol_syslog("stancer ****************** Stancer after SQL", LOG_DEBUG);

		// Create extrafields during init
		//include_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';
		//$extrafields = new ExtraFields($this->db);
		//$result1=$extrafields->addExtraField('stancer_myattr1', "New Attr 1 label", 'boolean', 1,  3, 'thirdparty',   0, 0, '', '', 1, '', 0, 0, '', '', 'stancer@stancer', '$conf->stancer->enabled');
		//$result2=$extrafields->addExtraField('stancer_myattr2', "New Attr 2 label", 'varchar', 1, 10, 'project',      0, 0, '', '', 1, '', 0, 0, '', '', 'stancer@stancer', '$conf->stancer->enabled');
		//$result3=$extrafields->addExtraField('stancer_myattr3', "New Attr 3 label", 'varchar', 1, 10, 'bank_account', 0, 0, '', '', 1, '', 0, 0, '', '', 'stancer@stancer', '$conf->stancer->enabled');
		//$result4=$extrafields->addExtraField('stancer_myattr4', "New Attr 4 label", 'select',  1,  3, 'thirdparty',   0, 1, '', array('options'=>array('code1'=>'Val1','code2'=>'Val2','code3'=>'Val3')), 1,'', 0, 0, '', '', 'stancer@stancer', '$conf->stancer->enabled');
		//$result5=$extrafields->addExtraField('stancer_myattr5', "New Attr 5 label", 'text',    1, 10, 'user',         0, 0, '', '', 1, '', 0, 0, '', '', 'stancer@stancer', '$conf->stancer->enabled');

		$sql = array();

		//Creation d'un compte bancaire STANCER
		dol_syslog("stancer init : check if STANCER bank account exists");
		$bank = new Account($this->db);
		$bankres = $bank->fetch(0, 'STANCER');
		if ($bankres) {
			dol_syslog("stancer init : bank account STANCER exists");
			//existe déjà
		} else {
			$bank->specimen        = 0;
			$bank->ref             = 'STANCER';
			$bank->label           = 'Stancer';
			$bank->bank            = 'Stancer';
			$bank->courant         = Account::TYPE_CURRENT;
			$bank->clos            = Account::STATUS_OPEN;
			$bank->code_banque     = '';
			$bank->code_guichet    = '';
			$bank->number          = '';
			$bank->cle_rib         = '';
			$bank->bic             = '';
			$bank->iban            = '';
			$bank->proprio         = $mysoc->name;
			$bank->owner_address   = $mysoc->address;
			$bank->country_id      = $mysoc->country_id;
			$bank->date_solde	   = dol_now();
			$bank->entity		   = $conf->entity;
			$res = $bank->create($user);
			dol_syslog("stancer init : bank account STANCER doest not exist, try to create it, return code is $res");
		}

		// Document templates
		$moduledir = dol_sanitizeFileName('stancer');
		$myTmpObjects = array();
		$myTmpObjects['Stancer'] = array('includerefgeneration'=>0, 'includedocgeneration'=>0);

		foreach ($myTmpObjects as $myTmpObjectKey => $myTmpObjectArray) {
			if ($myTmpObjectKey == 'Stancer') {
				continue;
			}
			if ($myTmpObjectArray['includerefgeneration']) {
				$src = DOL_DOCUMENT_ROOT.'/install/doctemplates/'.$moduledir.'/template_stancers.odt';
				$dirodt = DOL_DATA_ROOT.'/doctemplates/'.$moduledir;
				$dest = $dirodt.'/template_stancers.odt';

				if (file_exists($src) && !file_exists($dest)) {
					require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
					dol_mkdir($dirodt);
					$result = dol_copy($src, $dest, 0, 0);
					if ($result < 0) {
						$langs->load("errors");
						$this->error = $langs->trans('ErrorFailToCopyFile', $src, $dest);
						return 0;
					}
				}

				$sql = array_merge($sql, array(
					"DELETE FROM ".MAIN_DB_PREFIX."document_model WHERE nom = 'standard_".strtolower($myTmpObjectKey)."' AND type = '".$this->db->escape(strtolower($myTmpObjectKey))."' AND entity = ".((int) $conf->entity),
					"INSERT INTO ".MAIN_DB_PREFIX."document_model (nom, type, entity) VALUES('standard_".strtolower($myTmpObjectKey)."', '".$this->db->escape(strtolower($myTmpObjectKey))."', ".((int) $conf->entity).")",
					"DELETE FROM ".MAIN_DB_PREFIX."document_model WHERE nom = 'generic_".strtolower($myTmpObjectKey)."_odt' AND type = '".$this->db->escape(strtolower($myTmpObjectKey))."' AND entity = ".((int) $conf->entity),
					"INSERT INTO ".MAIN_DB_PREFIX."document_model (nom, type, entity) VALUES('generic_".strtolower($myTmpObjectKey)."_odt', '".$this->db->escape(strtolower($myTmpObjectKey))."', ".((int) $conf->entity).")"
				));
			}
		}

		//activation du modele de document
		$sql = array_merge($sql, array(
			"DELETE FROM ".MAIN_DB_PREFIX."document_model WHERE nom = 'sepamandate_stancer' AND type = 'bankaccount' AND entity = ".((int) $conf->entity),
			"INSERT INTO ".MAIN_DB_PREFIX."document_model (nom, libelle, type, entity) VALUES('sepamandate_stancer', 'sepamandate_stancer', 'bankaccount', ".((int) $conf->entity).")",
		));

		if (class_exists('Memcached')) {
			$m = new Memcached();
			$m->addServer('localhost', 11211);
			/* invalide tous les éléments dans 10 secondes */
			$m->flush(1);
		}

		if (getDolGlobalString('PAYMENT_SECURITY_TOKEN', '') == '') {
			dolibarr_set_const($this->db, 'PAYMENT_SECURITY_TOKEN', getRandomPassword(true), 'chaine', 0, '', $conf->entity);
		}
		if (getDolGlobalString('PAYMENT_SECURITY_TOKEN_UNIQUE', '') == '') {
			dolibarr_set_const($this->db, 'PAYMENT_SECURITY_TOKEN_UNIQUE', "1", 'chaine', 0, '', $conf->entity);
		}

		// Targeted re-runnable migrations by version boundary (MODULE.md point 15).
		// Add a boundary here when a fix must be replayed on existing installations.
		$fixon = explode('.', '2.0.11');
		if (versioncompare($installedVersion, $fixon) < 0) {
			dol_syslog('stancer init: applying migrations for versions < 2.0.11', LOG_NOTICE);
			// Aucun fix concret a appliquer aujourd'hui : ce bloc sert d'amorce
			// pour les migrations futures (voir MODULE.md point 15).
		}

		// Re-runnable data fix: tag legacy Stancer payments that were recorded with
		// an empty ext_payment_site (the old command-path posting bug). Every Stancer
		// Dolibarr payment carries num_paiement = paym_xxx (and ext_payment_id = paym_xxx),
		// which are exactly the stancer_id values stored in llx_stancer_stancer_payments.
		// So we can safely re-tag ONLY the untagged rows that match a known stancer_id
		// (never touching a stripe/mollie/already-stancer row). Idempotent: a second run
		// matches nothing. Without this tag, such payments are wrongly surfaced by the
		// repair tool as unposted "doubles" and shown as "paid via ?".
		$sqlTag = "UPDATE " . MAIN_DB_PREFIX . "paiement";
		$sqlTag .= " SET ext_payment_site = 'stancer'";
		$sqlTag .= " WHERE (ext_payment_site IS NULL OR ext_payment_site = '')";
		$sqlTag .= " AND (";
		$sqlTag .= "   num_paiement IN (SELECT stancer_id FROM " . MAIN_DB_PREFIX . "stancer_stancer_payments WHERE stancer_id <> '')";
		$sqlTag .= "   OR ext_payment_id IN (SELECT stancer_id FROM " . MAIN_DB_PREFIX . "stancer_stancer_payments WHERE stancer_id <> '')";
		$sqlTag .= " )";
		$resTag = $this->db->query($sqlTag);
		if ($resTag) {
			$nbTagged = $this->db->affected_rows($resTag);
			dol_syslog("stancer init: tagged " . (int) $nbTagged . " legacy untagged Stancer payment(s) with ext_payment_site='stancer'", LOG_NOTICE);
		} else {
			dol_syslog("stancer init: failed to tag legacy Stancer payments: " . $this->db->lasterror(), LOG_ERR);
		}

		dolibarr_set_const($this->db, 'STANCER_MODULE_VERSION', $this->version, 'chaine', 0, 'Active module version', $conf->entity);

		// Check if a more recent version is available (best-effort, non-blocking on failure)
		$langs->load("stancer@stancer");
		$checkRes = $this->checkForUpdate();
		if ($checkRes > 0) {
			setEventMessages($langs->trans('StancerNewVersionAvailable', $this->version, $this->lastVersion), null, 'warnings');
		}

		return $this->_init($sql, $options);
	}

	/**
	 *  Function called when module is disabled.
	 *  Remove from database constants, boxes and permissions from Dolibarr database.
	 *  Data directories are not deleted
	 *
	 *  @param      string	$options    Options when enabling module ('', 'noboxes')
	 *  @return     int                 1 if OK, 0 if KO
	 */
	public function remove($options = '')
	{
		$sql = array();
		return $this->_remove($sql, $options);
	}
}
