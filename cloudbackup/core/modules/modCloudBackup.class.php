<?php
/* Copyright (C) 2026		Pierre Grasswill		<da.grumpf@gmail.com>
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
 * \defgroup   cloudbackup     Module CloudBackup
 * \brief      Scheduled backup and restore of Dolibarr (database + documents) to S3, FTP, SFTP or a local directory, as a restic repository or plain archives
 *
 * \file       cloudbackup/core/modules/modCloudBackup.class.php
 * \ingroup    cloudbackup
 * \brief      Description and activation file for module CloudBackup
 */
include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';


/**
 * Description and activation class for module CloudBackup
 */
class modCloudBackup extends DolibarrModules
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

		// Range 95000-99999 is reserved for the modules of github.com/Dolibarr/dolibarr-community-modules
		$this->numero = 95040;

		$this->rights_class = 'cloudbackup';
		$this->family = "base";
		$this->module_position = '91';
		$this->name = preg_replace('/^mod/i', '', get_class($this));
		$this->description = "CloudBackupDescription";
		$this->descriptionlong = "CloudBackupDescriptionLong";
		$this->editor_name = 'Dolibarr community';
		$this->editor_url = 'https://github.com/Dolibarr/dolibarr-community-modules';
		$this->version = '1.0.0';
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
		$this->picto = 'fa-cloud-upload-alt';

		$this->module_parts = array(
			'triggers' => 0,
			'login' => 0,
			'substitutions' => 0,
			'menus' => 0,
			'tpl' => 0,
			'barcode' => 0,
			'models' => 0,
			'printing' => 0,
			'theme' => 0,
			'css' => array(),
			'js' => array(),
			'hooks' => array(),
			'moduleforexternal' => 0,
		);

		$this->dirs = array("/cloudbackup/temp");
		$this->config_page_url = array("setup.php@cloudbackup");

		$this->hidden = false;
		$this->depends = array('modCron');
		$this->requiredby = array();
		$this->conflictwith = array();
		$this->langfiles = array("cloudbackup@cloudbackup");

		$this->phpmin = array(7, 1);
		$this->need_dolibarr_version = array(18, 0);
		$this->need_javascript_ajax = 0;

		$this->warnings_activation = array();
		$this->warnings_activation_ext = array();

		// Settings of the whole instance (the backup covers every entity): stored in entity 0
		$this->const = array(
			array('CLOUDBACKUP_FORMAT', 'chaine', 'restic', 'restic or archive', 0, 'allentities', 0),
			array('CLOUDBACKUP_STORAGE', 'chaine', 's3', 'local, s3, ftp or sftp', 0, 'allentities', 0),
			array('CLOUDBACKUP_WITH_DATABASE', 'chaine', '1', 'Back up the database', 0, 'allentities', 0),
			array('CLOUDBACKUP_WITH_DOCUMENTS', 'chaine', '1', 'Back up the documents directory', 0, 'allentities', 0),
			array('CLOUDBACKUP_WITH_CONF', 'chaine', '1', 'Store conf.php in restic snapshots', 0, 'allentities', 0),
			array('CLOUDBACKUP_KEEP_LAST', 'chaine', '7', 'Retention: last backups', 0, 'allentities', 0),
			array('CLOUDBACKUP_KEEP_DAILY', 'chaine', '14', 'Retention: daily backups', 0, 'allentities', 0),
			array('CLOUDBACKUP_KEEP_WEEKLY', 'chaine', '8', 'Retention: weekly backups', 0, 'allentities', 0),
			array('CLOUDBACKUP_KEEP_MONTHLY', 'chaine', '12', 'Retention: monthly backups', 0, 'allentities', 0),
		);

		if (!isModEnabled("cloudbackup")) {
			$conf->cloudbackup = new stdClass();
			$conf->cloudbackup->enabled = 0;
		}

		$this->tabs = array();
		$this->dictionaries = array();
		$this->boxes = array();

		// Disabled at activation: the job only makes sense once a storage is configured
		$this->cronjobs = array(
			0 => array(
				'label' => 'CloudBackupJob',
				'jobtype' => 'method',
				'class' => '/cloudbackup/class/cloudbackup.class.php',
				'objectname' => 'CloudBackup',
				'method' => 'runScheduledBackup',
				'parameters' => '',
				'comment' => 'Back up the database and the documents to the storage of the CloudBackup setup',
				'frequency' => 1,
				'unitfrequency' => 86400,
				'status' => 0,
				'test' => 'isModEnabled("cloudbackup")',
				'priority' => 10,
			),
		);

		// Permission ids: module number, then a theme digit, then a permission digit
		$this->rights = array();
		$r = 0;
		$this->rights[$r][0] = $this->numero.'11';
		$this->rights[$r][1] = 'CloudBackupRightRead';
		$this->rights[$r][2] = 'r';
		$this->rights[$r][3] = 0;
		$this->rights[$r][4] = 'backup';
		$this->rights[$r][5] = 'read';
		$r++;
		$this->rights[$r][0] = $this->numero.'12';
		$this->rights[$r][1] = 'CloudBackupRightRun';
		$this->rights[$r][2] = 'w';
		$this->rights[$r][3] = 0;
		$this->rights[$r][4] = 'backup';
		$this->rights[$r][5] = 'write';
		$r++;
		$this->rights[$r][0] = $this->numero.'21';
		$this->rights[$r][1] = 'CloudBackupRightRestore';
		$this->rights[$r][2] = 'w';
		$this->rights[$r][3] = 0;
		$this->rights[$r][4] = 'restore';
		$this->rights[$r][5] = 'write';
		$r++;
		$this->rights[$r][0] = $this->numero.'31';
		$this->rights[$r][1] = 'CloudBackupRightSetup';
		$this->rights[$r][2] = 'w';
		$this->rights[$r][3] = 0;
		$this->rights[$r][4] = 'setup';
		$this->rights[$r][5] = 'write';
		$r++;

		$this->menu = array();
		$r = 0;
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=home,fk_leftmenu=admintools',
			'type' => 'left',
			'titre' => 'CloudBackupMenu',
			'mainmenu' => 'home',
			'leftmenu' => 'cloudbackup_admintools',
			'url' => '/cloudbackup/cloudbackupindex.php?mainmenu=home&leftmenu=admintools',
			'langs' => 'cloudbackup@cloudbackup',
			'position' => 1000 + $r,
			'enabled' => 'isModEnabled("cloudbackup") && preg_match("/^admintools/", $leftmenu)',
			'perms' => '$user->admin',
			'target' => '',
			'user' => 0,
		);
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=tools',
			'type' => 'left',
			'titre' => 'CloudBackupMenu',
			'prefix' => img_picto('', $this->picto, 'class="pictofixedwidth"'),
			'mainmenu' => 'tools',
			'leftmenu' => 'cloudbackup',
			'url' => '/cloudbackup/cloudbackupindex.php?mainmenu=tools&leftmenu=cloudbackup',
			'langs' => 'cloudbackup@cloudbackup',
			'position' => 1000 + $r,
			'enabled' => 'isModEnabled("cloudbackup")',
			'perms' => '$user->admin || $user->hasRight("cloudbackup", "backup", "read")',
			'target' => '',
			'user' => 0,
		);
	}

	/**
	 * Function called when module is enabled.
	 *
	 * @param	string		$options	Options when enabling module ('', 'noboxes')
	 * @return	int<-1,1>				1 if OK, <=0 if KO
	 */
	public function init($options = '')
	{
		$result = $this->_load_tables('/cloudbackup/sql/');
		if ($result < 0) {
			return -1;
		}
		$this->remove($options);
		return $this->_init(array(), $options);
	}

	/**
	 * Function called when module is disabled. The settings, the history and the backups are kept.
	 *
	 * @param	string		$options	Options when enabling module ('', 'noboxes')
	 * @return	int<-1,1>				1 if OK, <=0 if KO
	 */
	public function remove($options = '')
	{
		return $this->_remove(array(), $options);
	}
}
