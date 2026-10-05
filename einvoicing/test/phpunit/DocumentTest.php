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
 *      \file       test/phpunit/DocumentTest.php
 *      \ingroup    test
 *      \brief      PHPUnit test for einvoicing/class/document.class.php: the label and reason of a lifecycle
 *                  status are free text in a CDAR and their columns hold 255 characters.
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
dol_include_once('einvoicing/class/document.class.php');
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
class DocumentTest extends CommonClassTest
{
	/**
	 * A flow record whose status label and reason are longer than their columns is created, both cut to
	 * 255 characters, the whole text staying in the detail. It was refused and the status was lost.
	 *
	 * @return	void
	 */
	public function testALongStatusReasonIsRecorded()
	{
		global $conf, $db, $user;

		$reason = str_repeat('Motif de refus en texte libre, é ', 12);
		$document = new Document($db);
		$document->entity = $conf->entity;
		$document->provider = 'SuperPDP';
		$document->submittedat = dol_now();
		$document->flow_id = 'test-long-' . uniqid();
		$document->flow_direction = 'In';
		$document->flow_type = 'SupplierInvoiceLC';
		$document->cdar_lifecycle_label = $reason;
		$document->cdar_reason_desc = $reason;
		$document->cdar_reason_detail = $reason;
		$id = $document->create($user);

		$this->assertGreaterThan(0, $id, 'the record is created: ' . implode(', ', $document->errors));
		$obj = $db->fetch_object($db->query("SELECT cdar_lifecycle_label, cdar_reason_desc, cdar_reason_detail FROM " . MAIN_DB_PREFIX . "einvoicing_document WHERE rowid = " . ((int) $id)));
		$this->assertSame(dol_substr($reason, 0, 255), $obj->cdar_lifecycle_label);
		$this->assertSame(dol_substr($reason, 0, 255), $obj->cdar_reason_desc);
		$this->assertSame($reason, $obj->cdar_reason_detail, 'the detail keeps the whole text');
	}
}
