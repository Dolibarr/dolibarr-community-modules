<?php
/* Copyright (C) 2026 Pierre Grasswill
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
 * or see https://www.gnu.org/
 */

/**
 *      \file       test/phpunit/CommonClassTestCompat.inc.php
 *      \ingroup    test
 *      \brief      Make CommonClassTest available whatever the Dolibarr version.
 *      \remarks    test/phpunit/CommonClassTest.class.php does not exist on Dolibarr 18: requiring it
 *                  fatals and the whole suite is unrunnable. Use the core class when it is there,
 *                  otherwise declare the subset the module tests rely on. Include this file instead of
 *                  CommonClassTest.class.php directly, and after master.inc.php.
 */

if (!defined('DOL_DOCUMENT_ROOT')) {
	throw new \RuntimeException('CommonClassTestCompat.inc.php must be included after master.inc.php');
}

$coreCommonClassTest = DOL_DOCUMENT_ROOT . '/../test/phpunit/CommonClassTest.class.php';

if (file_exists($coreCommonClassTest)) {
	require_once $coreCommonClassTest;
} elseif (!class_exists('CommonClassTest', false)) {
	/**
	 * Minimal stand-in for the core CommonClassTest, for Dolibarr versions that do not ship it.
	 *
	 * Globals are captured in setUp() rather than in a constructor on purpose: the constructor
	 * signature of PHPUnit\Framework\TestCase changed between PHPUnit major versions, and the tests
	 * only read $this->sav* from inside the test methods anyway.
	 */
	abstract class CommonClassTest extends PHPUnit\Framework\TestCase
	{
		/** @var Conf 		Global $conf saved at setUp() */
		protected $savconf;
		/** @var User 		Global $user saved at setUp() */
		protected $savuser;
		/** @var Translate 	Global $langs saved at setUp() */
		protected $savlangs;
		/** @var DoliDB 	Global $db saved at setUp() */
		protected $savdb;

		/**
		 * Open a transaction around the whole test class, like the core class does.
		 *
		 * @return void
		 */
		public static function setUpBeforeClass(): void
		{
			global $db;
			$db->begin();
		}

		/**
		 * Save the globals the tests read back.
		 *
		 * @return void
		 */
		protected function setUp(): void
		{
			global $conf, $user, $langs, $db;

			$this->savconf  = $conf;
			$this->savuser  = $user;
			$this->savlangs = $langs;
			$this->savdb    = $db;
		}

		/**
		 * Roll the transaction back so a test run leaves no trace in the database.
		 *
		 * @return void
		 */
		public static function tearDownAfterClass(): void
		{
			global $db;
			$db->rollback();
		}
	}
}
