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
 *      \file       test/phpunit/StatusBadgeTest.php
 *      \ingroup    test
 *      \brief      PHPUnit test for the badge of the e-invoice status (#1227).
 *      \remarks    To run this script as CLI: phpunit filename.php
 */

global $conf, $user, $langs, $db;

// DOLIBARR_HTDOCS is honoured first so the module can be tested against any of the supported cores
// without moving the working copy.
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

/**
 * Class for PHPUnit tests
 *
 * @backupGlobals disabled
 * @backupStaticAttributes enabled
 * @remarks	backupGlobals must be disabled to have db,conf,user and lang not erased.
 */
class StatusBadgeTest extends CommonClassTest
{
	/**
	 * Every status the module can show has its badge, and only badges the core theme draws.
	 *
	 * @return void
	 */
	public function testEveryStatusHasABadgeOfTheCore()
	{
		$themeBadges = array('status0', 'status1', 'status1b', 'status4', 'status4b', 'status5', 'status6', 'status8');

		foreach (array_keys(EInvoicing::STATUS_LABEL_KEYS) as $code) {
			$this->assertArrayHasKey($code, EInvoicing::STATUS_BADGE_TYPES, 'status ' . $code . ' has a badge');
			$this->assertContains(EInvoicing::STATUS_BADGE_TYPES[$code], $themeBadges, 'status ' . $code);
		}
	}

	/**
	 * The badge carries the full label, the variant of the rejection included, as text and tooltip.
	 *
	 * @return void
	 */
	public function testTheBadgeCarriesTheFullLabel()
	{
		global $db, $langs;

		$langs->load('einvoicing@einvoicing');
		$einvoicing = new EInvoicing($db);

		foreach (array(array(EInvoicing::STATUS_DISPUTED, 'facture', ''), array(EInvoicing::STATUS_REJECTED, 'invoice_supplier', '')) as $case) {
			list($code, $element, $roles) = $case;
			$badge = $einvoicing->getStatusBadge($code, $element, $roles);
			$label = $einvoicing->getStatusLabel($code, $element, $roles);

			$this->assertStringContainsString('badge-' . EInvoicing::STATUS_BADGE_TYPES[$code], $badge);
			$this->assertStringContainsString($label, $badge);
		}

		$this->assertStringContainsString('badge-status0', $einvoicing->getStatusBadge(424242), 'an unknown code falls back on the grey badge');
	}
}
