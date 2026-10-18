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
 *      \file       test/phpunit/EInvoicingTest.php
 *      \ingroup    test
 *      \brief      PHPUnit test for einvoicing/class/einvoicing.class.php: the message of a lifecycle status
 *                  is free text in a CDAR and its column holds 255 characters.
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
dol_include_once('einvoicing/class/einvoicing.class.php');
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
 * Class for PHPUnit tests
 *
 * @backupGlobals disabled
 * @backupStaticAttributes enabled
 * @remarks	backupGlobals must be disabled to have db,conf,user and lang not erased.
 */
class EInvoicingTest extends CommonClassTest
{
	/**
	 * A status whose message is longer than its column is recorded, cut to 255 characters. It was refused
	 * by the database and the status of the vendor was lost.
	 *
	 * @return	void
	 */
	public function testALongStatusMessageIsRecorded()
	{
		global $conf, $db;

		$saved = getDolGlobalString('EINVOICING_PDP');
		$conf->global->EINVOICING_PDP = $saved ?: 'SuperPDP';
		$message = str_repeat('Commentaire du destinataire, é ', 12);
		$id = (new EInvoicing($db))->storeStatusMessage(1, 'invoice_supplier', 210, $message, 'IN', 'test-long-' . uniqid());
		$conf->global->EINVOICING_PDP = $saved;

		$this->assertGreaterThan(0, $id, 'the status is recorded: ' . $db->lasterror());
		$obj = $db->fetch_object($db->query("SELECT lc_status_message FROM " . MAIN_DB_PREFIX . "einvoicing_lifecycle_msg WHERE rowid = " . ((int) $id)));
		$this->assertSame(dol_substr($message, 0, 255), $obj->lc_status_message);
	}
}
