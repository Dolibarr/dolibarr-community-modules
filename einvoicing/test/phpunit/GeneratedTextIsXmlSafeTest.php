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
 *      \file       test/phpunit/GeneratedTextIsXmlSafeTest.php
 *      \ingroup    test
 *      \brief      A text typed on an invoice cannot make the document unreadable.
 *      \remarks    Text is where the generation breaks, because the text is the user's and no
 *                  validator is consulted before the document leaves. An ampersand in a company name
 *                  produced an empty element for a while (#695, fixed by #708, and CIITextEscapingTest
 *                  covers it at the level of the builder).
 *
 *                  This file asks the same question of the whole generation path, on an invoice that
 *                  exists in the database, with the shapes a description really carries: a control
 *                  character pasted along with text from a PDF, several lines, an ampersand, quotes,
 *                  accents, and a text longer than the field the document has for it. The document
 *                  produced has to be XML, and it has to still carry the text.
 *
 *                  The control character is not hypothetical: 0x0B travelling with a description
 *                  made the generation write a document XML 1.0 refuses, which the platform answered
 *                  with HTTP 400 - a document that never reached its recipient, and nothing in the
 *                  module noticed. Everything happens in the transaction the test class rolls back.
 */


// This script must only be run from the command line.
if (PHP_SAPI !== 'cli') {
	echo "Error: this script must be run from the command line (CLI), not through a web server.\n";
	exit(1);
}

global $conf, $user, $langs, $db;

// Load Dolibarr environment. Same resolution as the other test files of the module.
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

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

dol_include_once('einvoicing/class/protocols/CIIProtocol.class.php');
require_once __DIR__ . '/CommonClassTestCompat.inc.php';


/**
 * Class GeneratedTextIsXmlSafeTest
 *
 * Generates one invoice per hostile text and reads the document back.
 */
class GeneratedTextIsXmlSafeTest extends CommonClassTest
{
	/** @var Societe|null	The buyer every invoice of this class is made for */
	private static $buyer = null;

	/**
	 * The texts an invoice line is made to carry, and what has to survive of each.
	 *
	 * @return array<string,array{0:string,1:string}>	Case name => text typed, fragment that must remain
	 */
	public function hostileTexts()
	{
		return array(
			'a vertical tab pasted from a PDF' => array("Assembly\x0Bkit, ref 4711", 'Assembly'),
			'a unit separator' => array("Ref\x1F0042 delivered", 'delivered'),
			'a null byte' => array("Serial\x00number", 'Serial'),
			'an ampersand' => array('Nuts & bolts', 'Nuts & bolts'),
			'quotes' => array('The "special" article', 'special'),
			'accents' => array('Café crème, thé, à l\'unité', 'Café crème'),
			'several lines' => array("First line\nSecond line", 'Second line'),
			'a long text' => array(str_repeat('A very long description. ', 40), 'A very long description.'),
		);
	}

	/**
	 * The buyer of every invoice built here, created once.
	 *
	 * @return Societe	A customer third party
	 */
	private function buyer()
	{
		global $db;

		if (self::$buyer !== null) {
			return self::$buyer;
		}

		$user = new User($db);
		$this->assertGreaterThan(0, $user->fetch(1), 'the instance has a user to act as');

		$buyer = new Societe($db);
		$buyer->name = 'EINVOICING TEXT BUYER';
		$buyer->client = 1;
		// Some instances - the demo database among them - number their customers with a module that
		// refuses a third party without a code, and refuses a short one.
		$buyer->code_client = 'EINVTX' . strtoupper(substr(md5(uniqid('', true)), 0, 6));
		$buyer->address = '2 rue du Test';
		$buyer->zip = '75000';
		$buyer->town = 'Paris';
		$buyer->country_id = 1;
		$buyer->country_code = 'FR';
		$buyer->idprof1 = '000000002';
		$buyer->idprof2 = '00000000200010';
		$buyer->tva_intra = 'FR12000000002';
		$this->assertGreaterThan(0, $buyer->create($user), 'the buyer is created: ' . $buyer->error . ' ' . implode(', ', (array) $buyer->errors));

		self::$buyer = $buyer;

		return self::$buyer;
	}

	/**
	 * Build a one-line invoice carrying the given text and generate its document.
	 *
	 * @param	string	$text	The description of the line
	 * @return	string			The document produced
	 */
	private function generateWith($text)
	{
		global $conf, $db, $langs, $mysoc;

		$user = new User($db);
		$user->fetch(1);

		$savPdp = getDolGlobalString('EINVOICING_PDP');
		$conf->global->EINVOICING_PDP = 'SPECIMEN';
		// A demo company whose SIREN is "123456" stops the generation before the text is reached.
		$savSeller = array(
			'idprof1' => $mysoc->idprof1,
			'idprof2' => $mysoc->idprof2,
			'tva_intra' => $mysoc->tva_intra,
			'country_id' => $mysoc->country_id,
			'country_code' => $mysoc->country_code,
		);
		$mysoc->idprof1 = '000000001';
		$mysoc->idprof2 = '00000000100010';
		$mysoc->tva_intra = 'FR12000000001';
		$mysoc->country_id = 1;
		$mysoc->country_code = 'FR';

		try {
			$invoice = new Facture($db);
			$invoice->socid = $this->buyer()->id;
			$invoice->type = Facture::TYPE_STANDARD;
			$invoice->date = dol_now();
			$this->assertGreaterThan(0, $invoice->create($user), 'the invoice is created: ' . $invoice->error);
			$this->assertGreaterThan(0, $invoice->addline($text, 10.00, 1, 20), 'the line is added: ' . $invoice->error);

			$reloaded = new Facture($db);
			$reloaded->fetch($invoice->id);
			$reloaded->fetch_lines();
			$reloaded->fetch_thirdparty();

			$protocol = new CIIProtocol($db);
			$path = $protocol->generateXML($reloaded, $langs);
			$this->assertNotEmpty($path, 'the document is generated: ' . $protocol->error);
			$this->assertFileExists((string) $path, 'the generated document is written');

			return (string) file_get_contents((string) $path);
		} finally {
			$conf->global->EINVOICING_PDP = $savPdp;
			foreach ($savSeller as $property => $value) {
				$mysoc->$property = $value;
			}
		}
	}

	/**
	 * Whatever the text, the document produced is XML.
	 *
	 * @dataProvider hostileTexts
	 * @param	string	$text		The text typed on the invoice line
	 * @return	void
	 */
	public function testTheDocumentIsWellFormed($text)
	{
		$xml = $this->generateWith($text);

		$document = new DOMDocument();
		$parsed = @$document->loadXML($xml);

		$this->assertTrue(
			$parsed,
			'the document generated for this text is not XML: ' . trim((string) (($error = libxml_get_last_error()) ? $error->message : ''))
		);
	}

	/**
	 * And the text is still in it. A document that drops what was typed is as wrong as one that
	 * cannot be parsed - #695 produced empty elements, which are well formed.
	 *
	 * @dataProvider hostileTexts
	 * @param	string	$text		The text typed on the invoice line
	 * @param	string	$fragment	What has to remain readable in the document
	 * @return	void
	 */
	public function testTheTextIsStillThere($text, $fragment)
	{
		$xml = $this->generateWith($text);

		$document = new DOMDocument();
		$this->assertTrue(@$document->loadXML($xml), 'the document generated for this text is XML');

		$this->assertStringContainsString(
			$fragment,
			(string) $document->textContent,
			'the document no longer carries "' . $fragment . '"'
		);
	}
}
