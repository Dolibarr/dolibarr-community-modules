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
 *      \file       test/phpunit/RecurringSupplierInvoiceHelperTest.php
 *      \ingroup    test
 *      \brief      PHPUnit test for RecurringSupplierInvoiceHelper: attaching a received supplier
 *                  invoice to the recurring template of its vendor (issue #997).
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
require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.facture-rec.class.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
dol_include_once('einvoicing/class/utils/RecurringSupplierInvoiceHelper.class.php');
require_once __DIR__ . '/CommonClassTestCompat.inc.php';

if (empty($user->id)) {
	print "Load permissions for admin user nb 1\n";
	$user->fetch(1);
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
class RecurringSupplierInvoiceHelperTest extends CommonClassTest
{
	/**
	 * Id of the third party every fixture of this class belongs to.
	 *
	 * @var int
	 */
	private $socid = 0;

	/**
	 * Build the vendor the fixtures belong to.
	 *
	 * A vendor of its own, never one already in the database: what is asserted here is how many
	 * templates that vendor has, and a template of the demo data would answer for it.
	 *
	 * @return void
	 */
	protected function setUp(): void
	{
		global $db, $user, $mysoc;

		parent::setUp();

		$vendor = new Societe($db);
		$vendor->name = 'REC997 vendor ' . uniqid();
		$vendor->fournisseur = 1;
		// An instance where a vendor code is mandatory (MAIN_COMPANY_CODE_ALWAYS_REQUIRED) refuses the
		// creation without one; 'auto' asks create() for the code its numbering module gives.
		$vendor->code_fournisseur = 'auto';
		$vendor->country_id = !empty($mysoc->country_id) ? (int) $mysoc->country_id : 1;
		$result = $vendor->create($user);
		$this->assertGreaterThan(0, $result, $vendor->errorsToString());

		$this->socid = (int) $vendor->id;
	}

	/**
	 * A draft supplier invoice of the fixture vendor.
	 *
	 * @param	int		$templateId		Template it was generated from, 0 for none
	 * @return	FactureFournisseur
	 */
	private function createSupplierInvoice($templateId = 0)
	{
		global $db, $user;

		$invoice = new FactureFournisseur($db);
		$invoice->socid = $this->socid;
		$invoice->ref_supplier = 'REC_TEST_' . uniqid();
		$invoice->date = dol_now();
		$invoice->libelle = 'Recurring template attachment test';
		if ($templateId > 0) {
			$invoice->fk_fac_rec_source = $templateId;
		}
		$result = $invoice->create($user);
		$this->assertGreaterThan(0, $result, $invoice->errorsToString());

		return $invoice;
	}

	/**
	 * A recurring template of the fixture vendor, built from a supplier invoice.
	 *
	 * @param	int		$frequency		Frequency in months, 0 for a template that generates nothing
	 * @param	int		$dateWhen		Date of next generation, 0 for none
	 * @return	FactureFournisseurRec
	 */
	private function createTemplate($frequency = 1, $dateWhen = 0)
	{
		global $db, $user;

		$source = $this->createSupplierInvoice();

		$template = new FactureFournisseurRec($db);
		$template->titre = 'TPL_' . uniqid();
		$template->title = $template->titre;
		$template->socid = $this->socid;
		$template->ref_supplier = $source->ref_supplier;
		$template->frequency = $frequency;
		$template->unit_frequency = 'm';
		$template->date_when = $dateWhen;
		$template->nb_gen_max = 0;
		$result = $template->create($user, $source->id);
		$this->assertGreaterThan(0, $result, $template->errorsToString());

		return $template;
	}

	/**
	 * The templates of a vendor are listed, and only a single active one is attached without asking.
	 *
	 * @return void
	 */
	public function testSoleActiveTemplate()
	{
		$template = $this->createTemplate();

		$templates = RecurringSupplierInvoiceHelper::listTemplatesOfVendor($this->socid);
		$this->assertArrayHasKey($template->id, $templates, 'The template of the vendor is not listed');
		$this->assertEquals($template->titre, $templates[$template->id]['title']);

		$this->assertEquals($template->id, RecurringSupplierInvoiceHelper::soleActiveTemplate($templates), 'The only active template is not the one picked');

		$second = $this->createTemplate();
		$templates = RecurringSupplierInvoiceHelper::listTemplatesOfVendor($this->socid);
		$this->assertEquals(0, RecurringSupplierInvoiceHelper::soleActiveTemplate($templates), 'Two templates must not be told apart automatically');

		$second->setValueFrom('suspended', 1, '', null, 'int');
		$templates = RecurringSupplierInvoiceHelper::listTemplatesOfVendor($this->socid);
		$this->assertEquals($template->id, RecurringSupplierInvoiceHelper::soleActiveTemplate($templates), 'A suspended template must not count as a candidate');
	}

	/**
	 * Only the fields the received document leaves empty are taken from the template.
	 *
	 * @return void
	 */
	public function testFieldsToInherit()
	{
		global $db;

		$template = new FactureFournisseurRec($db);
		$template->fk_account = 7;
		$template->fk_project = 8;
		$template->cond_reglement_id = 9;
		$template->mode_reglement_id = 10;

		$invoice = new FactureFournisseur($db);
		$invoice->mode_reglement_id = 42;	// read in the document, must win

		$toinherit = RecurringSupplierInvoiceHelper::fieldsToInherit($invoice, $template);

		$this->assertEquals(7, $toinherit['fk_account']);
		$this->assertEquals(8, $toinherit['fk_project']);
		$this->assertEquals(9, $toinherit['cond_reglement_id']);
		$this->assertArrayNotHasKey('mode_reglement_id', $toinherit, 'The payment method of the document must not be overwritten');

		$empty = new FactureFournisseurRec($db);
		$this->assertEquals(array(), RecurringSupplierInvoiceHelper::fieldsToInherit($invoice, $empty), 'A template that predefines nothing must add nothing');
	}

	/**
	 * Extrafields of the template fill only what the invoice does not carry.
	 *
	 * @return void
	 */
	public function testOptionsToInherit()
	{
		global $db;

		$template = new FactureFournisseurRec($db);
		$template->array_options = array('options_a' => 'fromtemplate', 'options_b' => 'alsofromtemplate', 'options_c' => '');

		$invoice = new FactureFournisseur($db);
		$invoice->array_options = array('options_b' => 'fromdocument');

		$toinherit = RecurringSupplierInvoiceHelper::optionsToInherit($invoice, $template);

		$this->assertEquals(array('options_a' => 'fromtemplate'), $toinherit);
	}

	/**
	 * An invoice already stored takes the link and the settings of the template.
	 *
	 * @return void
	 */
	public function testAttachStoredInvoice()
	{
		global $db, $user;

		$template = $this->createTemplate();
		$template->setValueFrom('fk_cond_reglement', 1, '', null, 'int');
		$this->assertGreaterThan(0, $template->fetch($template->id));

		$invoice = $this->createSupplierInvoice();
		$result = RecurringSupplierInvoiceHelper::attachStoredInvoice($invoice, $template, $user);
		$this->assertGreaterThan(0, $result['res'], $result['message']);

		$stored = new FactureFournisseur($db);
		$this->assertGreaterThan(0, $stored->fetch($invoice->id));
		$this->assertEquals($template->id, (int) $stored->fk_fac_rec_source, 'The invoice is not attached to the template');
		$this->assertEquals(1, (int) $stored->cond_reglement_id, 'The payment terms of the template were not applied');
	}

	/**
	 * The draft generated by a template is found, and an invoice an import produced is not.
	 *
	 * @return void
	 */
	public function testFindGeneratedDraft()
	{
		global $db;

		$template = $this->createTemplate();
		$generated = $this->createSupplierInvoice($template->id);

		$this->assertEquals($generated->id, RecurringSupplierInvoiceHelper::findGeneratedDraft((int) $template->id, $this->socid), 'The draft generated by the template is not found');
		$this->assertEquals(0, RecurringSupplierInvoiceHelper::findGeneratedDraft((int) $template->id, $this->socid, (int) $generated->id), 'The invoice being attached must not be a candidate');

		$sql = "INSERT INTO " . $db->prefix() . "einvoicing_extlinks (element_id, element_type, provider, date_creation, fk_user_creat)";
		$sql .= " VALUES (" . ((int) $generated->id) . ", 'invoice_supplier', 'TESTPROVIDER', '" . $db->idate(dol_now()) . "', 1)";
		$this->assertNotEquals(false, $db->query($sql), (string) $db->lasterror());
		$this->assertEquals(0, RecurringSupplierInvoiceHelper::findGeneratedDraft((int) $template->id, $this->socid), 'An imported invoice must never be offered for deletion');
	}

	/**
	 * A template that still owes an invoice is moved forward, one that does not is left alone.
	 *
	 * @return void
	 */
	public function testSkipNextGeneration()
	{
		global $db, $user;

		$due = dol_time_plus_duree(dol_now(), -2, 'd');
		$template = $this->createTemplate(1, $due);
		$this->assertEquals(1, RecurringSupplierInvoiceHelper::skipNextGeneration($template, $user), 'A template due for generation must be moved forward');

		$moved = new FactureFournisseurRec($db);
		$this->assertGreaterThan(0, $moved->fetch($template->id));
		$this->assertGreaterThan(dol_now(), (int) $moved->date_when, 'The next generation date was not moved past today');

		$this->assertEquals(0, RecurringSupplierInvoiceHelper::skipNextGeneration($moved, $user), 'A template that already generated its invoice must not be moved again');
	}
}
