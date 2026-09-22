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
 *      \file       test/phpunit/PayeeBankAccountHelperTest.php
 *      \ingroup    test
 *      \brief      PHPUnit test for the bank account a received e-invoice announces (BT-84, BT-85,
 *                  BT-86), issue #1031: it is compared with the accounts already recorded on the
 *                  vendor, and the user action that records it never touches an existing account.
 *      \remarks    To run this script as CLI: phpunit filename.php
 */

global $conf, $user, $langs, $db;

// This module is deployed by symlinking this repository into htdocs/custom/einvoicing of one or several
// Dolibarr instances. Some test runners resolve the real (non-symlinked) path of this file before including
// it, which breaks a fixed "../../htdocs/master.inc.php" relative path. DOLIBARR_HTDOCS let's the developer/CI
// point explicitly at the Dolibarr instance to test against; otherwise we fall back to the relative path.
$dolibarrHtdocs = getenv('DOLIBARR_HTDOCS');
if (!$dolibarrHtdocs) {
	$dolibarrHtdocs = dirname(__FILE__) . '/../../htdocs';
}
if (!file_exists($dolibarrHtdocs . '/master.inc.php')) {
	throw new \RuntimeException('Could not locate master.inc.php under "' . $dolibarrHtdocs . '/". Set the environment variable (export DOLIBARR_HTDOCS=...) to the htdocs directory of the Dolibarr instance to test against.');
}

require_once $dolibarrHtdocs . '/master.inc.php';
dol_include_once('einvoicing/class/utils/PayeeBankAccountHelper.class.php');
require_once __DIR__ . '/CommonClassTestCompat.inc.php';

/**
 * Class for PHPUnit tests
 *
 * @backupGlobals disabled
 * @backupStaticAttributes enabled
 * @remarks	backupGlobals must be disabled to have db,conf,user and lang not erased.
 */
class PayeeBankAccountHelperTest extends CommonClassTest
{
	/**
	 * Two writings of the same IBAN compare equal, and anything that carries no IBAN compares to nothing.
	 *
	 * @return void
	 */
	public function testIbansCompareWhateverTheirWriting()
	{
		$this->assertSame('FR7630006000011234567890189', PayeeBankAccountHelper::normalizeIban('FR76 3000 6000 0112 3456 7890 189'));
		$this->assertSame('FR7630006000011234567890189', PayeeBankAccountHelper::normalizeIban('fr76-3000-6000-0112-3456-7890-189'));
		$this->assertSame('', PayeeBankAccountHelper::normalizeIban(''));
		$this->assertSame('', PayeeBankAccountHelper::normalizeIban(null));
	}

	/**
	 * The account a document announces is recorded on the vendor on demand, once: asked again it
	 * changes nothing, and the account already there keeps its own values.
	 *
	 * @return void
	 */
	public function testAnAnnouncedAccountIsRecordedOnceAndNeverOverwritesAnother()
	{
		global $db, $user;

		$socid = $this->createVendor();

		$announced = array(
			'iban' => 'FR76 3000 6000 0112 3456 7890 189',
			'bic' => 'AGRIFRPPXXX',
			'accountName' => 'ACME SAS',
		);

		// Nothing is known of that vendor yet
		$known = PayeeBankAccountHelper::thirdpartyAccounts($db, $socid);
		$this->assertCount(0, $known);
		$this->assertFalse(PayeeBankAccountHelper::isKnownAccount($announced, $known));

		$error = '';
		$ribid = PayeeBankAccountHelper::addAccountToThirdparty($db, $user, $socid, $announced, 'FA-1031', $error);
		$this->assertGreaterThan(0, $ribid, 'The announced account was not recorded: ' . $error);

		$known = PayeeBankAccountHelper::thirdpartyAccounts($db, $socid);
		$this->assertCount(1, $known, 'The vendor should carry exactly one account');
		$this->assertTrue(PayeeBankAccountHelper::isKnownAccount($announced, $known), 'The account just recorded must be seen as known');

		$rib = reset($known);
		$this->assertSame('FR76 3000 6000 0112 3456 7890 189', $rib->iban, 'The IBAN must be readable back as the document wrote it');
		$this->assertSame('AGRIFRPPXXX', $rib->bic);
		// The column is written from owner_name since Dolibarr 21 and from proprio before: read the column
		$res = $db->query("SELECT proprio FROM " . MAIN_DB_PREFIX . "societe_rib WHERE rowid = " . ((int) $ribid));
		$this->assertSame('ACME SAS', $db->fetch_object($res)->proprio, 'The account holder (BT-85) must reach the column');
		$this->assertStringContainsString('FA-1031', (string) $rib->label, 'The label must say which document announced the account');
		$this->assertStringContainsString('0189', (string) $rib->label, 'The label must say which account it holds');

		// Asked again, with the IBAN written differently: the account is already there
		$again = array('iban' => 'FR7630006000011234567890189', 'bic' => 'OTHERBIC', 'accountName' => 'SOMEONE ELSE');
		$this->assertSame(0, PayeeBankAccountHelper::addAccountToThirdparty($db, $user, $socid, $again, 'FA-1031-BIS', $error));

		$known = PayeeBankAccountHelper::thirdpartyAccounts($db, $socid);
		$this->assertCount(1, $known, 'No second record for an account already there');
		$rib = reset($known);
		$this->assertSame('AGRIFRPPXXX', $rib->bic, 'The account already recorded must not be modified');

		// A second, different account of the same vendor is recorded next to the first one
		$other = array('iban' => 'DE89370400440532013000', 'bic' => '', 'accountName' => '');
		$this->assertGreaterThan(0, PayeeBankAccountHelper::addAccountToThirdparty($db, $user, $socid, $other, 'FA-1031', $error), $error);
		$this->assertCount(2, PayeeBankAccountHelper::thirdpartyAccounts($db, $socid));

		$this->deleteVendor($socid);
	}

	/**
	 * An announced account without an IBAN is refused: nothing else identifies an account well enough
	 * to be recorded on a vendor.
	 *
	 * @return void
	 */
	public function testAnAccountWithoutIbanIsRefused()
	{
		global $db, $user;

		$error = '';
		$this->assertSame(-1, PayeeBankAccountHelper::addAccountToThirdparty($db, $user, 1, array('iban' => '', 'bic' => 'AGRIFRPPXXX', 'accountName' => 'ACME SAS'), 'FA-1031', $error));
	}

	/**
	 * The card only offers to record the account when the option is on: it is opt-in, and the right
	 * to write payment information is still needed.
	 *
	 * @return void
	 */
	public function testTheAccountIsOnlyOfferedWhenTheOptionIsOn()
	{
		global $conf, $user;

		$saved = getDolGlobalInt('EINVOICING_THIRDPARTIES_ADD_PAYEE_BANK_ACCOUNT');

		$conf->global->EINVOICING_THIRDPARTIES_ADD_PAYEE_BANK_ACCOUNT = 0;
		$this->assertFalse(PayeeBankAccountHelper::addAccountOffered($user), 'Nothing may be offered while the option is off');

		$conf->global->EINVOICING_THIRDPARTIES_ADD_PAYEE_BANK_ACCOUNT = 1;
		$this->assertSame(PayeeBankAccountHelper::userCanAddAccount($user), PayeeBankAccountHelper::addAccountOffered($user), 'With the option on, the right decides');

		$conf->global->EINVOICING_THIRDPARTIES_ADD_PAYEE_BANK_ACCOUNT = $saved;
	}

	/**
	 * Create the vendor of the test.
	 *
	 * @return int	Id of the vendor
	 */
	private function createVendor()
	{
		global $db, $user;

		require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
		// getCountry(), called by Societe::create() and not loaded by the core up to Dolibarr 21
		require_once DOL_DOCUMENT_ROOT . '/core/lib/company.lib.php';

		$thirdparty = new Societe($db);
		$thirdparty->name = 'Vendor of the payee bank account test';
		$thirdparty->country_code = 'FR';
		$thirdparty->fournisseur = 1;
		$thirdparty->code_fournisseur = 'auto';

		$id = $thirdparty->create($user);
		$this->assertGreaterThan(0, $id, 'Could not create the vendor of the test: ' . $thirdparty->error . ' ' . implode(', ', $thirdparty->errors));

		return $id;
	}

	/**
	 * Remove the vendor of the test and the accounts recorded on it.
	 *
	 * @param	int		$socid	Id of the vendor
	 * @return	void
	 */
	private function deleteVendor($socid)
	{
		global $db, $user;

		$db->query("DELETE FROM " . MAIN_DB_PREFIX . "societe_rib WHERE fk_soc = " . ((int) $socid));

		$thirdparty = new Societe($db);
		$thirdparty->fetch((int) $socid);
		$thirdparty->delete((int) $socid, $user);
	}
}
