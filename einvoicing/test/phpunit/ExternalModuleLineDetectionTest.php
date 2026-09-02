<?php
/* Copyright (C) 2026 Regis Houssin
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
 *      \file       test/phpunit/ExternalModuleLineDetectionTest.php
 *      \ingroup    test
 *      \brief      PHPUnit test for CommonProtocol::_isLineFromExternalModule() on shipping/delivery
 *                  lines. Dolibarr 20 renamed ExpeditionLigne/LivraisonLigne::fk_origin_line to
 *                  fk_elementdet with no BC alias; the method must resolve the origin order line id
 *                  from whichever property the line object actually carries, since this module
 *                  supports back to Dolibarr 18.
 *      \remarks    To run this script as CLI: phpunit filename.php
 */

global $conf, $user, $langs, $db;

$dolibarrHtdocs = getenv('DOLIBARR_HTDOCS');
if (!$dolibarrHtdocs) {
	$dolibarrHtdocs = dirname(__FILE__) . '/../../htdocs';
}
if (!file_exists($dolibarrHtdocs . '/master.inc.php')) {
	throw new \RuntimeException('Could not locate master.inc.php under "' . $dolibarrHtdocs . '/". Set the environment variable (export DOLIBARR_HTDOCS=...) to the htdocs directory of the Dolibarr instance to test against.');
}

require_once $dolibarrHtdocs . '/master.inc.php';
require_once DOL_DOCUMENT_ROOT . '/commande/class/commande.class.php';
require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
dol_include_once('einvoicing/class/protocols/CIIProtocol.class.php');
require_once __DIR__ . '/CommonClassTestCompat.inc.php';

/**
 * Class for PHPUnit tests
 *
 * @backupGlobals disabled
 * @backupStaticAttributes enabled
 * @remarks	backupGlobals must be disabled to have db,conf,user and lang not erased.
 */
class ExternalModuleLineDetectionTest extends CommonClassTest
{
	/**
	 * A real order line with product_type 9 (title/subtotal pseudo-line), inserted directly with SQL
	 * rather than through Commande::create()/addline(): the only thing under test is which id
	 * CommonProtocol resolves and fetches, not the order business logic. Wrapped in the class-level
	 * transaction like every other fixture in this suite, so nothing survives the test run.
	 *
	 * @return int Rowid of the inserted commandedet line
	 */
	private function subtotalOrderLineId()
	{
		global $db, $user;

		$thirdparty = new Societe($db);
		$thirdparty->name = 'ExternalModuleLineDetectionTest';
		$thirdparty->country_code = 'FR';
		$thirdparty->create($user);

		$order = new Commande($db);
		$order->socid = $thirdparty->id;
		$order->date = dol_now();
		$order->create($user);

		$lineId = $order->addline('Subtotal line', 0, 0, 0, 0, 0, 0, 0, 0, 0, 'HT', 0, '', '', 9);
		$this->assertGreaterThan(0, $lineId, 'fixture setup: the subtotal order line must be created');

		return (int) $lineId;
	}

	/**
	 * Reflection wrapper: _isLineFromExternalModule() is private, like buildLineItem()/
	 * resolveLinePeriod() in CIIProtocolTest.
	 *
	 * @param	CIIProtocol	$protocol	Protocol instance
	 * @param	object		$line		Line object to test
	 * @param	string		$element	Line object element ('shipping', 'delivery', ...)
	 * @param	string		$searchName	Module name to look for
	 * @return	bool
	 */
	private function callIsLineFromExternalModule(CIIProtocol $protocol, $line, $element, $searchName)
	{
		$method = new ReflectionMethod(CIIProtocol::class, '_isLineFromExternalModule');
		$method->setAccessible(true);

		return $method->invoke($protocol, $line, $element, $searchName);
	}

	/**
	 * Current core (Dolibarr 20+): the shipment/delivery line only carries fk_elementdet. Before the
	 * fix, the method read the no-longer-existing fk_origin_line (silently null), fetched OrderLine
	 * with a null id, and never recognized the pseudo-line as a subtotal.
	 *
	 * @return void
	 */
	public function testCurrentCoreFkElementdetIsResolved()
	{
		global $db;

		$originLineId = $this->subtotalOrderLineId();

		$shipmentLine = new stdClass();
		$shipmentLine->fk_elementdet = $originLineId;

		$protocol = new CIIProtocol($db);
		$this->assertTrue(
			$this->callIsLineFromExternalModule($protocol, $shipmentLine, 'shipping', 'modSubtotal'),
			'the product_type 9 order line behind fk_elementdet must be recognized as a subtotal pseudo-line'
		);
	}

	/**
	 * Dolibarr 18-19 (this module's declared minimum): the property is still fk_origin_line. The fix
	 * must keep honouring it when fk_elementdet is not there.
	 *
	 * @return void
	 */
	public function testLegacyFkOriginLineIsStillHonoured()
	{
		global $db;

		$originLineId = $this->subtotalOrderLineId();

		$shipmentLine = new stdClass();
		$shipmentLine->fk_origin_line = $originLineId;

		$protocol = new CIIProtocol($db);
		$this->assertTrue(
			$this->callIsLineFromExternalModule($protocol, $shipmentLine, 'delivery', 'modSubtotal'),
			'the legacy fk_origin_line property must still resolve the origin order line on pre-20 cores'
		);
	}

	/**
	 * When both properties are present (should not happen on a real core, but pins the intended
	 * priority), fk_elementdet - the current core property - wins over the stale legacy one.
	 *
	 * @return void
	 */
	public function testFkElementdetTakesPriorityOverLegacyProperty()
	{
		global $db;

		$realLineId = $this->subtotalOrderLineId();

		$shipmentLine = new stdClass();
		$shipmentLine->fk_elementdet = $realLineId;
		$shipmentLine->fk_origin_line = 999999999; // does not exist: would make OrderLine::fetch() fail

		$protocol = new CIIProtocol($db);
		$this->assertTrue(
			$this->callIsLineFromExternalModule($protocol, $shipmentLine, 'shipping', 'modSubtotal'),
			'fk_elementdet must be read in priority over a stale fk_origin_line'
		);
	}
}
