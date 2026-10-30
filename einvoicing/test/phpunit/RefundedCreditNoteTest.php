<?php
/* Copyright (C) 2026 ATM Consulting
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
 *      \file       test/phpunit/RefundedCreditNoteTest.php
 *      \ingroup    test
 *      \brief      A refunded credit note declares its refund as already paid and nothing left due.
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
require_once DOL_DOCUMENT_ROOT . '/user/class/user.class.php';
require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT . '/compta/paiement/class/paiement.class.php';
dol_include_once('einvoicing/class/protocols/CIIProtocol.class.php');
require_once __DIR__ . '/CommonClassTestCompat.inc.php';

/**
 * Class RefundedCreditNoteTest
 *
 * @backupGlobals disabled
 * @backupStaticAttributes enabled
 * @remarks	backupGlobals must be disabled to have db,conf,user and lang not erased.
 */
class RefundedCreditNoteTest extends CommonClassTest
{
	const RAM = 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100';

	/** @var float	Net amount of the single line of the credit note */
	const LINE_HT = 29.45;
	/** @var float	VAT rate of the line */
	const VAT_RATE = 20.0;
	/** @var float	Gross amount of the credit note */
	const CREDIT_NOTE_TTC = 35.34;

	/** @var array<string,string>	Setup pinned during a build: generation needs a PDP, Dolibarr 17 reads MAIN_USE_ADVANCED_PERMS unguarded, the instance PDF model fails from CLI */
	const PINNED_CONSTANTS = array(
		'EINVOICING_PDP' => 'SPECIMEN',
		'MAIN_USE_ADVANCED_PERMS' => null,
		'MAIN_DISABLE_PDF_AUTOUPDATE' => '1',
	);

	/**
	 * Pin the seller and the setup the generation needs, for the duration of one build.
	 *
	 * @return	array{mysoc:array<string,mixed>,conf:array<string,string>}	The values to hand back to restoreEnvironment()
	 */
	private function pinEnvironment()
	{
		global $conf, $mysoc;

		$saved = array(
			'mysoc' => array(
				'idprof1' => $mysoc->idprof1,
				'idprof2' => $mysoc->idprof2,
				'tva_intra' => $mysoc->tva_intra,
				'country_id' => $mysoc->country_id,
				'country_code' => $mysoc->country_code,
			),
			'conf' => array(),
		);

		$mysoc->idprof1 = '000000001';
		$mysoc->idprof2 = '00000000100010';
		$mysoc->tva_intra = 'FR12000000001';
		$mysoc->country_id = 1;
		$mysoc->country_code = 'FR';

		foreach (self::PINNED_CONSTANTS as $name => $value) {
			$saved['conf'][$name] = getDolGlobalString($name);
			$conf->global->$name = ($value === null) ? $saved['conf'][$name] : $value;
		}

		return $saved;
	}

	/**
	 * Undo pinEnvironment().
	 *
	 * @param	array{mysoc:array<string,mixed>,conf:array<string,string>}	$saved	What pinEnvironment() returned
	 * @return	void
	 */
	private function restoreEnvironment($saved)
	{
		global $conf, $mysoc;

		foreach ($saved['conf'] as $name => $value) {
			$conf->global->$name = $value;
		}
		foreach ($saved['mysoc'] as $property => $value) {
			$mysoc->$property = $value;
		}
	}

	/**
	 * Create and validate a one-service-line invoice of the given type.
	 *
	 * @param	User	$user		The user acting
	 * @param	int		$socid		Third party of the invoice
	 * @param	int		$type		One of the Facture::TYPE_* constants
	 * @param	int		$sourceId	Invoice corrected by a credit note, 0 otherwise
	 * @return	Facture				The validated invoice
	 */
	private function createValidatedInvoice($user, $socid, $type, $sourceId = 0)
	{
		global $db;

		$invoice = new Facture($db);
		$invoice->socid = $socid;
		$invoice->type = $type;
		$invoice->date = dol_now();
		if ($sourceId > 0) {
			$invoice->fk_facture_source = $sourceId;
		}
		$this->assertGreaterThan(0, $invoice->create($user), 'the invoice of type ' . $type . ' is created: ' . $invoice->error);

		$lineid = $invoice->addline('E-invoicing refunded credit note test line', self::LINE_HT, 1, self::VAT_RATE, 0, 0, 0, 0, '', '', 0, 0, '', 'HT', 0, 1);
		$this->assertGreaterThan(0, $lineid, 'the line of the invoice of type ' . $type . ' is added: ' . $invoice->error);
		$this->assertGreaterThan(0, $invoice->validate($user), 'the invoice of type ' . $type . ' is validated: ' . $invoice->error);

		return $invoice;
	}

	/**
	 * Read one amount of the document settlement.
	 *
	 * @param	DOMXPath	$xpath	The generated document
	 * @param	string		$name	Element name, under ram:SpecifiedTradeSettlementHeaderMonetarySummation
	 * @return	float|null			The amount, null when the element is absent
	 */
	private function summation($xpath, $name)
	{
		$found = $xpath->query('//ram:SpecifiedTradeSettlementHeaderMonetarySummation/ram:' . $name);

		return ($found->length > 0) ? (float) $found->item(0)->textContent : null;
	}

	/**
	 * Build a credit note fully refunded by a payment and generate its document.
	 *
	 * @return	DOMXPath	The generated document
	 */
	private function buildRefundedCreditNote()
	{
		global $conf, $db, $langs;

		$user = new User($db);
		$this->assertGreaterThan(0, $user->fetch(1), 'the instance has a user to act as');
		$user->getrights();

		$saved = $this->pinEnvironment();

		try {
			$buyer = new Societe($db);
			$buyer->name = 'EINVOICING TEST REFUNDED CREDIT NOTE BUYER';
			$buyer->client = 1;
			$buyer->code_client = 'EINVRC' . strtoupper(substr(md5(uniqid('', true)), 0, 6));
			$buyer->address = '2 rue du Test';
			$buyer->zip = '75000';
			$buyer->town = 'Paris';
			$buyer->country_id = 1;
			$buyer->country_code = 'FR';
			$buyer->idprof1 = '000000002';
			$buyer->idprof2 = '00000000200010';
			$buyer->tva_intra = 'FR12000000002';
			$this->assertGreaterThan(0, $buyer->create($user), 'the buyer is created: ' . $buyer->error);

			$source = $this->createValidatedInvoice($user, $buyer->id, Facture::TYPE_STANDARD);
			$creditNote = $this->createValidatedInvoice($user, $buyer->id, Facture::TYPE_CREDIT_NOTE, $source->id);

			$paymentModeId = dol_getIdFromCode($db, 'VIR', 'c_paiement', 'code', 'id', 1);
			$this->assertGreaterThan(0, (int) $paymentModeId, 'the instance has the transfer payment mode');

			// A refund is a payment with a negative amount, as compta/paiement.php records it.
			$refund = new Paiement($db);
			$refund->datepaye = dol_now();
			$refund->amounts = array($creditNote->id => -self::CREDIT_NOTE_TTC);
			$refund->multicurrency_amounts = array();
			$refund->multicurrency_code = array($creditNote->id => $conf->currency);
			$refund->multicurrency_tx = array($creditNote->id => 1);
			$refund->paiementid = (int) $paymentModeId;
			$refund->paiementcode = 'VIR';
			$this->assertGreaterThan(0, $refund->create($user, 1), 'the refund is recorded and closes the credit note: ' . $refund->error);

			$reloaded = new Facture($db);
			$this->assertGreaterThan(0, $reloaded->fetch($creditNote->id), 'the credit note is read back');
			$reloaded->fetch_lines();
			$reloaded->fetch_thirdparty();
			$this->assertEqualsWithDelta(0.0, (float) $reloaded->getRemainToPay(), 0.011, 'the core says nothing remains to refund');

			$protocol = new CIIProtocol($db);
			$path = $protocol->generateXML($reloaded, $langs);
			$this->assertNotEmpty($path, 'the document of the credit note is generated: ' . $protocol->error . ' ' . implode(', ', (array) $protocol->errors));
			$this->assertFileExists((string) $path, 'the generated document is written');

			$doc = new DOMDocument();
			$this->assertTrue($doc->loadXML((string) file_get_contents((string) $path)), 'the generated document is well formed XML');
			$xpath = new DOMXPath($doc);
			$xpath->registerNamespace('ram', self::RAM);

			return $xpath;
		} finally {
			$this->restoreEnvironment($saved);
		}
	}

	/**
	 * Before the fix BT-113 read -35.34 and BT-115 read 70.68.
	 *
	 * @return void
	 */
	public function testRefundedCreditNoteHasNothingLeftDue()
	{
		$xpath = $this->buildRefundedCreditNote();

		$this->assertEqualsWithDelta(self::CREDIT_NOTE_TTC, $this->summation($xpath, 'GrandTotalAmount'), 0.011, 'BT-112 is the positive gross amount of the credit note');
		$this->assertEqualsWithDelta(self::CREDIT_NOTE_TTC, $this->summation($xpath, 'TotalPrepaidAmount'), 0.011, 'BT-113 is the refunded amount, positive');
		$this->assertEqualsWithDelta(0.0, $this->summation($xpath, 'DuePayableAmount'), 0.011, 'BT-115 is 0 once refunded');
	}
}
