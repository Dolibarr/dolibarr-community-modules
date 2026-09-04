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
 *      \file       test/phpunit/MandatoryThirdpartyExtrafieldTest.php
 *      \ingroup    test
 *      \brief      PHPUnit test for the synchronization of the seller of a received document when the
 *                  base carries a mandatory extrafield on thirdparties.
 *                  The module never sets an extrafield on a thirdparty, but from Dolibarr 20 on
 *                  Societe::fetch() pre-fills array_options with a null entry for every declared
 *                  extrafield, and update() hands that array to insertExtraFields(), which refuses the
 *                  whole record as soon as one of those fields is mandatory and empty. A thirdparty
 *                  created programmatically holds no extrafield row at all - which is exactly what the
 *                  automatic creation of this same function produces - so every received document was
 *                  then rejected on the seller.
 *      \remarks    To run this script as CLI: phpunit filename.php
 */

global $conf, $user, $langs, $db;

// See VatPointDateCodeTest for why DOLIBARR_HTDOCS is honoured before the relative path.
$dolibarrHtdocs = getenv('DOLIBARR_HTDOCS');
if (!$dolibarrHtdocs) {
	$dolibarrHtdocs = dirname(__FILE__) . '/../../htdocs';
}
if (!file_exists($dolibarrHtdocs . '/master.inc.php')) {
	throw new \RuntimeException('Could not locate master.inc.php under "' . $dolibarrHtdocs . '/". Set the environment variable (export DOLIBARR_HTDOCS=...) to the htdocs directory of the Dolibarr instance to test against.');
}

require_once $dolibarrHtdocs . '/master.inc.php';
require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/class/extrafields.class.php';
// Societe::create() calls getCountry() on some cores (Dolibarr 21), and master.inc.php does not
// load company.lib.php: without this the test errors out on an undefined function, not on the module.
require_once DOL_DOCUMENT_ROOT . '/core/lib/company.lib.php';
dol_include_once('einvoicing/class/protocols/CIIProtocol.class.php');
require_once __DIR__ . '/CommonClassTestCompat.inc.php';

$conf->global->MAIN_DISABLE_ALL_MAILS = 1;


/**
 * Class for PHPUnit tests
 *
 * @backupGlobals disabled
 * @backupStaticAttributes enabled
 * @remarks	backupGlobals must be disabled to have db,conf,user and lang not erased.
 */
class MandatoryThirdpartyExtrafieldTest extends CommonClassTest
{
	/** @var string	Name of the mandatory extrafield declared on thirdparties for the test */
	const ATTRNAME = 'einvmandatorytest';

	/**
	 * Declare a mandatory extrafield on thirdparties, then open the transaction of the test class.
	 *
	 * Order matters: adding an extrafield runs an ALTER TABLE, which is not transactional and commits
	 * whatever the surrounding transaction holds. Declared before the transaction opens, and removed
	 * after it is rolled back, it leaves the instance exactly as it was found.
	 *
	 * @return void
	 */
	public static function setUpBeforeClass(): void
	{
		global $db;

		$extrafields = new ExtraFields($db);
		// A run interrupted before tearDownAfterClass() would leave the field behind, and addExtraField()
		// refuses an existing name: drop it first so the suite stays runnable.
		$extrafields->delete(self::ATTRNAME, 'societe');
		$extrafields->addExtraField(self::ATTRNAME, 'Mandatory field of the test', 'varchar', 100, 32, 'societe', 0, 1);

		parent::setUpBeforeClass();
	}

	/**
	 * Roll the transaction back, then remove the extrafield declared for the test.
	 *
	 * @return void
	 */
	public static function tearDownAfterClass(): void
	{
		global $db;

		parent::tearDownAfterClass();

		$extrafields = new ExtraFields($db);
		$extrafields->delete(self::ATTRNAME, 'societe');
	}

	/**
	 * A vendor as the automatic creation of _syncOrCreateThirdpartyFromEInvoiceSeller() leaves it:
	 * identified by its SIREN, and holding no extrafield row at all, since a programmatic create()
	 * writes none.
	 *
	 * @param	string	$siren	SIREN identifying the vendor
	 * @return	int				Id of the created thirdparty
	 */
	private function createVendor($siren)
	{
		global $db, $user;

		$thirdparty = new Societe($db);
		$thirdparty->name = 'Vendor of the mandatory extrafield test';
		$thirdparty->country_code = 'FR';
		$thirdparty->idprof1 = $siren;
		$thirdparty->fournisseur = 1;
		$thirdparty->code_fournisseur = 'auto';

		$id = $thirdparty->create($user);
		$this->assertGreaterThan(0, $id, 'Could not create the vendor of the test: ' . $thirdparty->error . ' ' . implode(', ', $thirdparty->errors));

		return $id;
	}

	/**
	 * Seller block of a received document, reduced to what the synchronization reads.
	 *
	 * @param	string	$siren	SIREN carried by the document
	 * @return	array			Seller information, as the protocols parse it
	 */
	private function sellerInfo($siren)
	{
		return array(
			'sellername' => 'Vendor of the mandatory extrafield test',
			'sellerlineone' => '1 rue du Test',
			'sellerpostcode' => '86000',
			'sellercity' => 'Poitiers',
			'sellercountry' => 'FR',
			'sellerGlobalIds' => array('0002' => $siren),
		);
	}

	/**
	 * Run the seller synchronization, the private step both protocols call on a received document.
	 *
	 * @param	array	$sellerInfo	Seller information
	 * @return	array				Answer of _syncOrCreateThirdpartyFromEInvoiceSeller()
	 */
	private function syncSeller($sellerInfo)
	{
		global $db;

		// CommonProtocol is a trait: the method belongs to the class that uses it.
		$method = new ReflectionMethod(CIIProtocol::class, '_syncOrCreateThirdpartyFromEInvoiceSeller');
		$method->setAccessible(true);

		return $method->invoke(new CIIProtocol($db), $sellerInfo, 'dolibarr', '');
	}

	/**
	 * A received document whose seller is already known must be imported even though the base carries
	 * a mandatory extrafield on thirdparties that this vendor does not fill.
	 *
	 * @return void
	 */
	public function testSellerSyncSucceedsWithAnEmptyMandatoryExtrafield()
	{
		$siren = '000000011';
		$socid = $this->createVendor($siren);

		$res = $this->syncSeller($this->sellerInfo($siren));

		$this->assertEquals($socid, $res['res'], 'The seller synchronization was refused: ' . $res['message']);
	}

	/**
	 * The synchronization must not empty the extrafields it does not set: a vendor whose mandatory
	 * extrafield is filled keeps its value once the document is imported.
	 *
	 * @return void
	 */
	public function testStoredExtrafieldValuesSurviveTheSellerSync()
	{
		global $db, $user;

		$siren = '000000012';
		$socid = $this->createVendor($siren);

		$thirdparty = new Societe($db);
		$thirdparty->fetch($socid);
		$thirdparty->array_options['options_' . self::ATTRNAME] = 'KEEP ME';
		$this->assertGreaterThan(0, $thirdparty->update($socid, $user), 'Could not store the extrafield value: ' . implode(', ', $thirdparty->errors));

		$res = $this->syncSeller($this->sellerInfo($siren));
		$this->assertEquals($socid, $res['res'], 'The seller synchronization was refused: ' . $res['message']);

		$reread = new Societe($db);
		$reread->fetch($socid);
		$this->assertEquals('KEEP ME', $reread->array_options['options_' . self::ATTRNAME], 'The synchronization lost the stored extrafield value');
	}
}
