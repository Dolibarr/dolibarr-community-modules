<?php
/* Copyright (C) 2004-2017	Laurent Destailleur			<eldy@users.sourceforge.net>
 * Copyright (C) 2018-2024  Frédéric France				<frederic.france@free.fr>
 * Copyright (C) 2026		Alice Adminson				<laurent@destailleur.fr>
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
 * \file    dolisecure/core/boxes/dolisecurewidget1.php
 * \ingroup dolisecure
 * \brief   Widget provided by DoliSecure
 *
 * Put detailed description here.
 */

include_once DOL_DOCUMENT_ROOT."/core/boxes/modules_boxes.php";


/**
 * Class to manage the box
 *
 * Warning: for the box to be detected correctly by dolibarr,
 * the filename should be the lowercase classname
 */
class dolisecurewidget1 extends ModeleBoxes
{
	/**
	 * @var string Alphanumeric ID. Populated by the constructor.
	 */
	public $boxcode = "dolisecurebox";

	/**
	 * @var string Box icon (in configuration page)
	 */
	public $boximg = "fa-shield-alt";

	/**
	 * @var string Box label (in configuration page)
	 */
	public $boxlabel = 'DoliSecureBoxTitle';

	/**
	 * @var string Box language file if it needs a specific language file.
	 */
	public $lang = 'dolisecure@dolisecure';

	/**
	 * @var string[] Module dependencies
	 */
	public $depends = array('dolisecure');

	/**
	 * @var string 	Widget type ('graph' means the widget is a graph widget)
	 */
	public $widgettype = '';


	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 * @param string $param More parameters
	 */
	public function __construct(DoliDB $db, $param = '')
	{
		global $user;

		parent::__construct($db, $param);

		$this->param = $param;

		// Condition when module is enabled or not
		// $this->enabled = getDolGlobalInt('MAIN_FEATURES_LEVEL') > 0;
		// Condition when module is visible by user (test on permission)
		// $this->hidden = !$user->hasRight('dolisecure', 'myobject', 'read');
	}

	/**
	 * Load data into info_box_contents array to show array later. Called by Dolibarr before displaying the box.
	 *
	 * @param	int<0,max>	$max	Maximum number of records to load
	 * @return	void
	 */
	public function loadBox($max = 5)
	{
		global $langs, $user;

		// Use configuration value for max lines count
		$this->max = $max;

		$langs->load('dolisecure@dolisecure');

		$this->info_box_contents = array();

		// This indicator exposes the exact installed version and its known vulnerabilities: admin only
		if (empty($user->admin)) {
			$this->hidden = true;
			return;
		}

		$this->info_box_head = array(
			'text'     => $langs->trans('DoliSecureBoxTitle'),
			'sublink'  => dol_buildpath('/dolisecure/dolisecureindex.php', 1),
			'subpicto' => 'fa-shield-alt',
			'subtext'  => $langs->trans('DoliSecureSeeDetails'),
			'target'   => '',
			'subclass' => 'center',
			'limit'    => 0,
			'graph'    => 0,
		);

		dol_include_once('/dolisecure/class/dolisecurechecker.class.php');
		$checker = new DoliSecureChecker($this->db);
		$last = $checker->getLastResult();

		$trattr = 'class="left"';

		switch ($last['status']) {
			case 'vulnerable':
				$maxseverity = DoliSecureChecker::getMaxSeverity($last['cves']);
				$colors = DoliSecureChecker::severityColors($maxseverity);
				$trattr = 'style="background-color:'.$colors['bg'].';border-left: 4px solid '.$colors['border'].';"';
				$statustext = img_picto('', 'fa-shield-alt', 'class="pictofixedwidth valignmiddle"', 0, 0, 0, '', 'text-danger');
				$statustext .= ' '.$langs->trans('DoliSecureVulnerabilityFound', count($last['cves']), $last['version']);
				break;
			case 'ok':
				$statustext = img_picto('', 'fa-shield-alt', 'class="pictofixedwidth valignmiddle"', 0, 0, 0, '', 'text-success');
				$statustext .= ' '.$langs->trans('DoliSecureOk');
				break;
			case 'error':
				$statustext = img_picto('', 'fa-shield-alt', 'class="pictofixedwidth valignmiddle"', 0, 0, 0, '', 'text-warning');
				$statustext .= ' '.$langs->trans('DoliSecureCheckError', $last['error']);
				break;
			default:
				$statustext = dol_escape_htmltag($langs->trans('DoliSecureNeverChecked'));
				break;
		}

		$this->info_box_contents[0] = array(
			0 => array(
				'tr'   => $trattr,
				'text' => $statustext,
				'asis' => 1,
			),
			1 => array(
				'text' => !empty($last['date']) ? dol_print_date($last['date'], 'dayhour') : '',
			),
		);

		$i = 1;
		foreach ($last['cves'] as $cve) {
			if ($i > $this->max) {
				break;
			}
			$this->info_box_contents[$i] = array(
				0 => array(
					'tr'   => 'class="oddeven"',
					'text' => '<a href="'.dol_escape_htmltag($cve['url']).'" target="_blank" rel="noopener noreferrer">'.dol_escape_htmltag($cve['id']).'</a>',
					'asis' => 1,
				),
				1 => array(
					'text' => dol_escape_htmltag(trim($cve['severity'].' '.($cve['score'] !== null ? '('.$cve['score'].')' : ''))),
				),
			);
			$i++;
		}
	}






	/**
	 *	Method to show box.  Called when the box needs to be displayed.
	 *
	 *	@param	?array<array{text?:string,sublink?:string,subtext?:string,subpicto?:?string,picto?:string,nbcol?:int,limit?:int,subclass?:string,graph?:int<0,1>,target?:string}>   $head       Array with properties of box title
	 *	@param	?array<array{tr?:string,td?:string,target?:string,text?:string,text2?:string,textnoformat?:string,tooltip?:string,logo?:string,url?:string,maxlength?:int,asis?:int<0,1>}>   $contents   Array with properties of box lines
	 *	@param	int<0,1>	$nooutput	No print, only return string
	 *	@return	string
	 */
	public function showBox($head = null, $contents = null, $nooutput = 0)
	{
		// You may make your own code here…
		// … or use the parent's class function using the provided head and contents templates
		return parent::showBox($this->info_box_head, $this->info_box_contents, $nooutput);
	}
}
