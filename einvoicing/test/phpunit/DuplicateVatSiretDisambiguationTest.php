<?php
/* Copyright (C) 2026 Frédéric France <frederic.france@free.fr>
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
 *      \file       test/phpunit/DuplicateVatSiretDisambiguationTest.php
 *      \ingroup    test
 *      \brief      PHPUnit test for _syncOrCreateThirdpartyFromEInvoiceSeller() when the VAT-number
 *                  lookup returns more than one thirdparty. A French intra-EU VAT number is derived
 *                  from the SIREN alone, so two établissements of the same legal entity (same SIREN,
 *                  different SIRET) legitimately share one VAT number - this is not a data-quality
 *                  duplicate. The document's SIRET (BT-31 GlobalID, scheme 0009/0225) must be used to
 *                  pick the right établissement instead of refusing the import outright.
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
require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/company.lib.php';	// Societe::create() of Dolibarr 21 calls getCountry() without loading it
dol_include_once('einvoicing/class/protocols/CIIProtocol.class.php');
require_once __DIR__ . '/CommonClassTestCompat.inc.php';

/**
 * Class for PHPUnit tests
 *
 * @backupGlobals disabled
 * @backupStaticAttributes enabled
 * @remarks	backupGlobals must be disabled to have db,conf,user and lang not erased.
 */
class DuplicateVatSiretDisambiguationTest extends CommonClassTest
{
	/**
	 * Call the private CommonProtocol::_syncOrCreateThirdpartyFromEInvoiceSeller() through reflection.
	 *
	 * @param	array	$sellerInfo		Seller information extracted from the e-invoice
	 * @return	array					Same shape the method itself returns
	 */
	private function sync(array $sellerInfo): array
	{
		global $db;

		$protocol = new CIIProtocol($db);
		$method = new ReflectionMethod(CIIProtocol::class, '_syncOrCreateThirdpartyFromEInvoiceSeller');
		$method->setAccessible(true);

		return $method->invoke($protocol, $sellerInfo, 'dolibarr', '');
	}

	/**
	 * Create and save a French thirdparty carrying the given VAT number and SIRET.
	 *
	 * @param	string	$name		Thirdparty name
	 * @param	string	$vatNumber	Intra-EU VAT number
	 * @param	string	$siret		SIRET (idprof2)
	 * @return	Societe				The saved thirdparty
	 */
	private function createThirdparty(string $name, string $vatNumber, string $siret): Societe
	{
		global $db, $user;

		$thirdparty = new Societe($db);
		$thirdparty->initAsSpecimen();
		$thirdparty->name = $name;
		$thirdparty->country_id = 0;
		$thirdparty->country_code = 'FR';
		$thirdparty->tva_intra = $vatNumber;
		$thirdparty->tva_assuj = 1;
		// idprof1 (SIREN) is left empty on purpose: this environment enforces SOCIETE_IDPROF1_UNIQUE,
		// and the matching logic under test only ever reads idprof2 (SIRET) and tva_intra here.
		$thirdparty->idprof1 = '';
		$thirdparty->idprof2 = $siret;
		// initAsSpecimen() stamps both codes with a second-precision timestamp: two thirdparties
		// created within the same test collide on it (mod_codeclient_monkey rejects the duplicate).
		$thirdparty->code_client = substr('CU' . uniqid() . mt_rand(10, 99), 0, 24);
		$thirdparty->code_fournisseur = substr('SU' . uniqid() . mt_rand(10, 99), 0, 24);

		$id = $thirdparty->create($user);
		$this->assertGreaterThan(0, $id, 'Fixture thirdparty was not created: ' . implode(', ', array_merge(array($thirdparty->error), $thirdparty->errors)));

		$thirdparty->id = $id;

		return $thirdparty;
	}

	/**
	 * Two établissements of the same entity share one VAT number: the document's SIRET must pick
	 * the one it actually belongs to, rather than being reported as an unresolvable duplicate.
	 *
	 * The document's SIRET is sent with the paper-form spacing (XXX XXX XXX XXXXX): step 1 (direct
	 * fetch on idprof2) does an exact match and will not find it, since the SIRET is stored without
	 * spaces - so this exercises the VAT-lookup disambiguation (step 2) and not step 1.
	 *
	 * @return void
	 */
	public function testSiretDisambiguatesAmongThirdpartiesSharingVatNumber()
	{
		$vatNumber = 'FR' . rand(10, 99) . rand(100000000, 999999999);
		$siretHq = rand(100000000, 999999999) . '00011';
		$siretBranch = substr($siretHq, 0, 9) . '00029';

		$this->createThirdparty('HQ establishment', $vatNumber, $siretHq);
		$branch = $this->createThirdparty('Branch establishment', $vatNumber, $siretBranch);

		$spacedSiretBranch = substr($siretBranch, 0, 3) . ' ' . substr($siretBranch, 3, 3) . ' ' . substr($siretBranch, 6, 3) . ' ' . substr($siretBranch, 9);

		$result = $this->sync(array(
			'sellername' => 'Branch establishment',
			'sellercountry' => 'FR',
			'sellerTaxRegistations' => array('VA' => $vatNumber),
			'sellerGlobalIds' => array('0009' => $spacedSiretBranch),
		));

		$this->assertSame((int) $branch->id, (int) $result['res'], 'The branch sharing the VAT number was not picked by its SIRET: ' . ($result['message'] ?? ''));
		$this->assertArrayNotHasKey('actioncode', $result);
	}

	/**
	 * The document's SIRET matches neither établissement already on file: this is a third,
	 * not-yet-created établissement of the same entity, not an unresolvable duplicate. The import
	 * must not be blocked - a new thirdparty is created for it instead (consistent with the
	 * existing auto-creation behaviour when no match is found at all).
	 *
	 * @return void
	 */
	public function testUnmatchedSiretFallsThroughToThirdpartyCreation()
	{
		global $conf;

		$vatNumber = 'FR' . rand(10, 99) . rand(100000000, 999999999);
		$siretHq = rand(100000000, 999999999) . '00011';
		$siretBranch1 = substr($siretHq, 0, 9) . '00029';
		$siretBranch2 = substr($siretHq, 0, 9) . '00037';

		$hq = $this->createThirdparty('HQ establishment', $vatNumber, $siretHq);
		$branch1 = $this->createThirdparty('Branch 1', $vatNumber, $siretBranch1);

		$savAutoGeneration = $conf->global->EINVOICING_THIRDPARTIES_AUTO_GENERATION ?? null;
		$conf->global->EINVOICING_THIRDPARTIES_AUTO_GENERATION = 1;

		try {
			$result = $this->sync(array(
				'sellername' => 'Branch 2',
				'sellercountry' => 'FR',
				'sellerTaxRegistations' => array('VA' => $vatNumber),
				'sellerGlobalIds' => array('0009' => $siretBranch2),
			));
		} finally {
			$conf->global->EINVOICING_THIRDPARTIES_AUTO_GENERATION = $savAutoGeneration;
		}

		$this->assertNotSame('THIRDPARTY_DUPLICATE_VAT', $result['actioncode'] ?? '', 'A third, not-yet-known établissement must not be reported as a VAT duplicate: ' . ($result['message'] ?? ''));
		$this->assertGreaterThan(0, $result['res'], 'A new thirdparty should have been created for the unmatched établissement.');
		$this->assertNotSame((int) $hq->id, (int) $result['res']);
		$this->assertNotSame((int) $branch1->id, (int) $result['res']);
	}

	/**
	 * When the document carries no SIRET at all, there is nothing to disambiguate with: the
	 * existing protective behaviour (refuse the import, point at the two candidates) must stand.
	 *
	 * @return void
	 */
	public function testNoSiretOnDocumentStillReportsDuplicateVat()
	{
		$vatNumber = 'FR' . rand(10, 99) . rand(100000000, 999999999);
		$siretHq = rand(100000000, 999999999) . '00011';
		$siretBranch = substr($siretHq, 0, 9) . '00029';

		$this->createThirdparty('HQ establishment', $vatNumber, $siretHq);
		$this->createThirdparty('Branch establishment', $vatNumber, $siretBranch);

		$result = $this->sync(array(
			'sellername' => 'Unknown establishment',
			'sellercountry' => 'FR',
			'sellerTaxRegistations' => array('VA' => $vatNumber),
		));

		$this->assertSame(-1, $result['res']);
		$this->assertSame('THIRDPARTY_DUPLICATE_VAT', $result['actioncode'] ?? '');
	}
}
