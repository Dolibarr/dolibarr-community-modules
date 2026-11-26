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
 * along with this program. If not, see https://www.gnu.org/licenses/
 */

/**
 *      \file       test/phpunit/CallTest.php
 *      \ingroup    test
 *      \brief      PHPUnit test for einvoicing/class/call.class.php: the trigger codes all start with the
 *                  class prefix.
 *      \remarks    To run this script as CLI: phpunit filename.php
 */

global $conf, $user, $langs, $db;

// See RecipientDirectoryTest for why DOLIBARR_HTDOCS is honoured here.
$dolibarrHtdocs = getenv('DOLIBARR_HTDOCS');
if (!$dolibarrHtdocs) {
	$dolibarrHtdocs = dirname(__FILE__) . '/../../htdocs';
}
if (!file_exists($dolibarrHtdocs . '/master.inc.php')) {
	throw new \RuntimeException('Could not locate master.inc.php under "' . $dolibarrHtdocs . '/". Set the environment variable (export DOLIBARR_HTDOCS=...) to the htdocs directory of the Dolibarr instance to test against.');
}

require_once $dolibarrHtdocs . '/master.inc.php';
dol_include_once('einvoicing/class/call.class.php');
require_once __DIR__ . '/CommonClassTestCompat.inc.php';

if (empty($user->id)) {
	print "Load permissions for admin user nb 1\n";
	$user->fetch(1);
	// User::loadRights() only exists from Dolibarr 19 on, older versions name it getrights()
	if (method_exists($user, 'loadRights')) {
		$user->loadRights();
	} else {
		$user->getrights();
	}
}
$conf->global->MAIN_DISABLE_ALL_MAILS = 1;

/**
 * Records the trigger codes instead of running the triggers.
 */
class CallTriggerSpy extends Call
{
	/** @var string[] */
	public $fired = array();

	/**
	 * @param	string	$triggerName	Trigger code
	 * @param	?User	$user			User
	 * @return	int						0, no trigger ran
	 */
	public function call_trigger($triggerName, $user)	// phpcs:ignore PEAR.NamingConventions.ValidFunctionName.ScopeNotCamelCaps
	{
		$this->fired[] = $triggerName;
		return 0;
	}
}


/**
 * Class for PHPUnit tests
 *
 * @backupGlobals disabled
 * @backupStaticAttributes enabled
 * @remarks	backupGlobals must be disabled to have db,conf,user and lang not erased.
 */
class CallTest extends CommonClassTest
{
	/**
	 * The codes the class fires start with its prefix (class name up to core 24, EINVOICING_CALL from 25):
	 * core 23/24 logged an error for the ModuleBuilder codes (MYOBJECT_VALIDATE, EINVOICING_MYOBJECT_CANCEL...).
	 *
	 * @return	void
	 */
	public function testTriggerCodesStartWithTheClassPrefix()
	{
		global $db, $user;

		$call = new CallTriggerSpy($db);
		$prefix = ((float) DOL_VERSION >= 25 ? 'EINVOICING_' : '').'CALL';
		$this->assertSame($prefix, $call->TRIGGER_PREFIX);

		$call->id = 0;	// no row: the updates match nothing, only the trigger codes are recorded
		$call->ref = '1';
		$call->status = Call::STATUS_FAILED;
		$call->validate($user);
		$call->setDraft($user);
		$call->reopen($user);
		$call->cancel($user);

		$this->assertSame(array($prefix.'_VALIDATE', $prefix.'_UNVALIDATE', $prefix.'_REOPEN', $prefix.'_CANCEL'), $call->fired);
	}
}
