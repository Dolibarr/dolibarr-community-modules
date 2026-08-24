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
 *      \file       test/phpunit/StatusComboMarkupTest.php
 *      \ingroup    test
 *      \brief      PHPUnit test for the markup the status combo of EInvoicing::getEinvoiceStatusOptions()
 *                  produces: the labels of an Access Point in France carry HTML in their 'data-html',
 *                  and Form::selectarray() of Dolibarr 17 prints data-* values as they are, so that HTML
 *                  used to close the attribute and leave an invalid <option>.
 *      \remarks    To run this script as CLI: phpunit filename.php
 */

global $conf, $user, $langs, $db, $mysoc;

// See RecipientDirectoryTest.php for why DOLIBARR_HTDOCS is honoured before the relative path.
$dolibarrHtdocs = getenv('DOLIBARR_HTDOCS');
if (!$dolibarrHtdocs) {
	$dolibarrHtdocs = dirname(__FILE__) . '/../../htdocs';
}
if (!file_exists($dolibarrHtdocs . '/master.inc.php')) {
	throw new \RuntimeException('Could not locate master.inc.php under "' . $dolibarrHtdocs . '/". Set the environment variable (export DOLIBARR_HTDOCS=...) to the htdocs directory of the Dolibarr instance to test against.');
}

require_once $dolibarrHtdocs . '/master.inc.php';
require_once DOL_DOCUMENT_ROOT . '/core/class/html.form.class.php';
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
 * Tests on the HTML of the e-invoice status combo.
 *
 * Nothing is written here: the tests only render the combo of the status list.
 */
class StatusComboMarkupTest extends CommonClassTest
{
	/** @var string Country of $mysoc before a test changed it */
	private $savedCountryCode;

	/**
	 * The HTML decoration of the labels is only added for an Access Point in France, so ask for France.
	 *
	 * @return void
	 */
	protected function setUp(): void
	{
		global $mysoc;

		parent::setUp();

		$this->savedCountryCode = $mysoc->country_code;
		$mysoc->country_code = 'FR';
	}

	/**
	 * Give $mysoc back the country the instance runs with.
	 *
	 * @return void
	 */
	protected function tearDown(): void
	{
		global $mysoc;

		$mysoc->country_code = $this->savedCountryCode;

		parent::tearDown();
	}

	/**
	 * The opening tags of the options rendered by the core for a given status list.
	 *
	 * A tag is read up to its first '>', which is exactly what a browser does: an attribute value that
	 * still holds raw HTML therefore shows up inside the tag, and that is what is asserted on.
	 *
	 * @param	array<string|int,array{label:string,data-html:string,disable?:int,css?:string}>	$options	Status list to render
	 * @return	string[]	Opening tags of the options
	 */
	private function optionTags($options)
	{
		global $db;

		$form = new Form($db);
		$html = $form->selectarray('search_pdp_status', $options, '', -2, 0, 0, '', 0, 0, 0, '', 'width100 ');

		$tags = array();
		if (preg_match_all('/<option\b[^>]*>/i', $html, $matches)) {
			$tags = $matches[0];
		}

		return $tags;
	}

	/**
	 * The status list of a supplier invoice renders options no markup leaks into.
	 *
	 * @return void
	 */
	public function testStatusOptionsRenderValidTags()
	{
		global $db;

		$einvoicing = new EInvoicing($db);
		$tags = $this->optionTags($einvoicing->getEinvoiceStatusOptions(0, 1));

		$this->assertNotEmpty($tags, 'the status list has to render at least one option');
		foreach ($tags as $tag) {
			$this->assertStringNotContainsString('<', substr($tag, 1), 'an attribute of ' . $tag . ' closed early and let markup out of it');
		}
	}

	/**
	 * The code of a status is still shown next to its label, escaped, and not lost by the escaping.
	 *
	 * @return void
	 */
	public function testStatusCodeDecorationSurvivesAsText()
	{
		global $db;

		$einvoicing = new EInvoicing($db);
		$tags = $this->optionTags($einvoicing->getEinvoiceStatusOptions(0, 1));

		$decorated = array_filter($tags, function ($tag) {
			return strpos($tag, '&lt;span') !== false;
		});

		$this->assertNotEmpty($decorated, 'the code of the Access Point statuses has to reach data-html as text');
	}

	/**
	 * The list of the invoice card, which asks for the codes inside the labels, renders as well.
	 *
	 * @return void
	 */
	public function testSendableStatusOptionsRenderValidTags()
	{
		global $db;

		$einvoicing = new EInvoicing($db);
		$tags = $this->optionTags($einvoicing->getEinvoiceStatusOptions(1, 1, 1));

		$this->assertNotEmpty($tags, 'the sendable status list has to render at least one option');
		foreach ($tags as $tag) {
			$this->assertStringNotContainsString('<', substr($tag, 1), 'an attribute of ' . $tag . ' closed early and let markup out of it');
		}
	}
}
