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
 *	\file		einvoicing/core/boxes/box_einvoicing_sync.php
 *	\ingroup	einvoicing
 *	\brief		Widget that shows the state of the scheduled synchronization with the Access Point
 */

include_once DOL_DOCUMENT_ROOT.'/core/boxes/modules_boxes.php';	// @phpstan-ignore includeOnce.fileNotFound (PHPStan takes DOL_DOCUMENT_ROOT from install/inc.php of the core, where it is '..')
require_once __DIR__.'/../../lib/einvoicing.lib.php';

/**
 * Widget of the scheduled synchronization
 */
class box_einvoicing_sync extends ModeleBoxes
{
	/**
	 * @var string
	 */
	public $boxcode = "einvoicingsync";

	/**
	 * @var string
	 */
	public $boximg = "einvoicing@einvoicing";

	/**
	 * @var string
	 */
	public $boxlabel = "EInvoicingSyncWidget";

	/**
	 * @var string[]
	 */
	public $depends = array("einvoicing");

	/**
	 * @var string
	 */
	public $param;

	/**
	 * @var array<array{text:string,nbcol?:int,limit?:int,graph?:int<0,1>,sublink?:string,subtext?:string,picto?:string,target?:string,td?:string}>|array{text:string,nbcol?:int,limit?:int,graph?:int<0,1>,sublink?:string,subtext?:string,picto?:string,target?:string,td?:string}	Declared here, the base class only declares it from Dolibarr 19
	 */
	public $info_box_head = array();

	/**
	 * @var array<array<array{td?:string,text:string,asis?:int<0,1>,maxlength?:int}|array{td?:string,textnoformat:string,asis?:int<0,1>,maxlength?:int}>>|array<array{td?:string,text:string,asis?:int<0,1>,maxlength?:int}|array{td?:string,textnoformat:string,asis?:int<0,1>,maxlength?:int}>
	 */
	public $info_box_contents = array();

	/**
	 * Constructor
	 *
	 * @param	DoliDB	$db			Database handler
	 * @param	string	$param		More parameters
	 */
	public function __construct($db, $param = '')
	{
		global $user;

		$this->db = $db;
		$this->param = $param;
		$this->hidden = !$user->hasRight('einvoicing', 'read');
	}

	/**
	 * Load the lines of the widget
	 *
	 * @param	int		$max		Unused, the widget has a fixed number of lines
	 * @return	void
	 */
	public function loadBox($max = 5)
	{
		global $langs;

		$langs->load("einvoicing@einvoicing");

		$this->info_box_head = array(
			'text' => $langs->trans("EInvoicingSyncWidget"),
		);

		$line = 0;
		foreach (einvoicingSyncJobHealthLines($this->db, einvoicingSyncJobHealth($this->db)) as $syncline) {
			$this->info_box_contents[$line][0] = array(
				'td' => 'class="nowraponall"',
				'text' => $syncline[0],
			);
			$this->info_box_contents[$line][1] = array(
				'td' => 'class="right"',
				'text' => $syncline[1],
				'asis' => 1,
			);
			$line++;
		}
	}

	/**
	 * Show the widget
	 *
	 * @param	?array<array{text?:string,sublink?:string,subpicto:?string,nbcol?:int,limit?:int,subclass?:string,graph?:int,target?:string}>	$head	Array with properties of box title
	 * @param	?array<array{tr?:string,td?:string,target?:string,text?:string,text2?:string,textnoformat?:string,tooltip?:string,logo?:string,url?:string,maxlength?:int,asis?:int<0,1>}>	$contents	Array with properties of box lines
	 * @param	int<0,1>	$nooutput	No print, only return string
	 * @return	string
	 */
	public function showBox($head = null, $contents = null, $nooutput = 0)
	{
		return parent::showBox($this->info_box_head, $this->info_box_contents, $nooutput);
	}
}
