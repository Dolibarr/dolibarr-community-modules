<?php
/* Copyright (C) 2023 Laurent Destailleur  <eldy@users.sourceforge.net>
 * Copyright (C) 2026		MDW						<mdeweerd@users.noreply.github.com>
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
 *	\file       htdocs/core/class/commonhookactions.class.php
 *	\ingroup    core
 *	\brief      File of parent class of all other hook actions classes
 */

// @phan-suppress-file PhanRedefineClass

// Guarded like the other shims of this directory. The class is only missing before Dolibarr 19, and
// every module that has to run on both sides of that line carries the same copy of it: whoever gets
// loaded second declares a name that is already taken, which is a fatal error and not a recoverable
// one. It cost the activation of the module on the second entity of a multicompany setup (issue
// #630), where the other module happened to bring its own copy in first.
if (!class_exists('CommonHookActions', false)) {
	/**
	 *	Parent class of all other hook actions classes
	 */
	abstract class CommonHookActions
	{
		/**
		 * @var string	String of results.
		 */
		public $resprints;

		/**
		 * @var array 	Array of results.
		 */
		public $results = array();
	}
}
