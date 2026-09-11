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
 *      \file       test/phpunit/MultidirOutputCompatTest.php
 *      \ingroup    test
 *      \brief      PHPUnit test for getMultidirOutputCompat(): it must hand the call over to the core
 *                  getMultidirOutput() as soon as the core knows the four arguments.
 *      \remarks    The core function takes ($object, $module) only up to Dolibarr 19 and
 *                  ($object, $module, $forobject, $mode) from Dolibarr 20 on, hence the guard. The
 *                  backported body is unreachable once the guard delegates, so it is re-declared here
 *                  under another name to be compared against the core function of the instance.
 *      \remarks    To run this script as CLI: phpunit filename.php
 */

global $conf, $user, $langs, $db;

// See RecipientDirectoryTest.php for why DOLIBARR_HTDOCS is honoured before the relative path.
$dolibarrHtdocs = getenv('DOLIBARR_HTDOCS');
if (!$dolibarrHtdocs) {
	$dolibarrHtdocs = dirname(__FILE__) . '/../../htdocs';
}
if (!file_exists($dolibarrHtdocs . '/master.inc.php')) {
	throw new \RuntimeException('Could not locate master.inc.php under "' . $dolibarrHtdocs . '/". Set the environment variable (export DOLIBARR_HTDOCS=...) to the htdocs directory of the Dolibarr instance to test against.');
}

require_once $dolibarrHtdocs . '/master.inc.php';
require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
dol_include_once('einvoicing/lib/einvoicing.lib.php');
require_once __DIR__ . '/CommonClassTestCompat.inc.php';


/**
 * Tests on the delegation of getMultidirOutputCompat() to the core getMultidirOutput().
 *
 * Nothing is written here: the tests only compute paths, no file and no record is created.
 */
class MultidirOutputCompatTest extends CommonClassTest
{
	/** @var string	Name under which the backported body is re-declared, without its delegation */
	const BACKPORT_FUNCTION = 'einvoicingTestMultidirOutputBackport';

	/** @var string	First Dolibarr version whose getMultidirOutput() takes $forobject and $mode */
	const FIRST_VERSION_WITH_FOUR_ARGS = '20.0.0';

	/**
	 * Re-declare the backported body of getMultidirOutputCompat() under another name.
	 *
	 * The body is read from lib/einvoicing.lib.php itself, so it cannot drift from the code under
	 * test, and the delegation to the core is removed so that the backport is really the one that
	 * runs. The result is written to a file rather than eval()-ed to keep it debuggable.
	 *
	 * @return void
	 */
	public static function setUpBeforeClass(): void
	{
		if (function_exists(self::BACKPORT_FUNCTION)) {
			return;
		}

		$libfile = dirname(dirname(__DIR__)) . '/lib/einvoicing.lib.php';
		if (!file_exists($libfile)) {
			$libfile = dol_buildpath('/einvoicing/lib/einvoicing.lib.php');
		}
		$source = file_get_contents($libfile);
		if ($source === false) {
			throw new \RuntimeException('Could not read ' . $libfile);
		}

		$start = strpos($source, 'function getMultidirOutputCompat(');
		if ($start === false) {
			throw new \RuntimeException('getMultidirOutputCompat() not found in ' . $libfile);
		}
		$end = strpos($source, "\n}\n", $start);		// the function is the only thing closing on column 0
		if ($end === false) {
			throw new \RuntimeException('End of getMultidirOutputCompat() not found in ' . $libfile);
		}
		$body = substr($source, $start, $end - $start + 3);

		// Strip the version guard, so that what is declared is the backported body alone
		$count = 0;
		$body = preg_replace(
			'/(?:\n\t\/\/[^\n]*)*\n\tif \(version_compare\(DOL_VERSION[^\n]*\n\t\treturn getMultidirOutput\([^\n]*\n\t\}\n/',
			"\n",
			$body,
			1,
			$count
		);
		if ($count !== 1) {
			throw new \RuntimeException('The delegation guard of getMultidirOutputCompat() could not be located');
		}
		$body = str_replace('function getMultidirOutputCompat(', 'function ' . self::BACKPORT_FUNCTION . '(', $body);

		$tmpfile = tempnam(sys_get_temp_dir(), 'einvoicing_multidir_') . '.php';
		file_put_contents($tmpfile, "<?php\n\n" . $body);
		require_once $tmpfile;
		unlink($tmpfile);
	}

	/**
	 * Build the invoice the paths are computed for. Never saved, never fetched.
	 *
	 * @return Facture		An invoice with just what a path needs: element, id, ref and entity
	 */
	private function buildInvoice()
	{
		global $db, $conf;

		$invoice = new Facture($db);
		$invoice->id = 424242;
		$invoice->ref = 'FA2601-0042';
		$invoice->entity = $conf->entity;

		return $invoice;
	}

	/**
	 * The bound of the guard is the one the core really draws.
	 *
	 * This is the measurement the guard rests on: getMultidirOutput() must accept $forobject and
	 * $mode exactly on the versions the guard delegates to.
	 *
	 * @return void
	 */
	public function testGuardBoundMatchesTheCoreSignature()
	{
		$this->assertTrue(function_exists('getMultidirOutput'), 'The core getMultidirOutput() must exist on every supported version');

		$reflection = new ReflectionFunction('getMultidirOutput');
		$coreTakesFourArguments = ($reflection->getNumberOfParameters() >= 4);

		$guardDelegates = version_compare(DOL_VERSION, self::FIRST_VERSION_WITH_FOUR_ARGS, '>=');

		$this->assertSame(
			$coreTakesFourArguments,
			$guardDelegates,
			'Dolibarr ' . DOL_VERSION . ': getMultidirOutput() takes ' . $reflection->getNumberOfParameters()
			. ' argument(s), which contradicts the ' . self::FIRST_VERSION_WITH_FOUR_ARGS . ' bound of getMultidirOutputCompat()'
		);
	}

	/**
	 * The modes and the $forobject values the module actually asks for.
	 *
	 * @return array<string,array{string,int}>	case name => mode, forobject
	 */
	public function pathCaseProvider()
	{
		return array(
			'output'				=> array('output', 0),
			'output for object'		=> array('output', 1),
			'outputrel'				=> array('outputrel', 0),
			'outputrel for object'	=> array('outputrel', 1),
			'temp'					=> array('temp', 0),
			'temp for object'		=> array('temp', 1),
			'version'				=> array('version', 0),
			'version for object'	=> array('version', 1),
		);
	}

	/**
	 * On the core we run on, the backported body and the core function give the same path.
	 *
	 * That is what makes the delegation safe for the module: the four call sites all pass an invoice,
	 * and an invoice takes the same route through both implementations.
	 *
	 * @param	string	$mode		Mode asked for ('output', 'outputrel', 'temp' or 'version')
	 * @param	int		$forobject	Whether the path of the object itself is asked for
	 * @return	void
	 * @dataProvider pathCaseProvider
	 */
	public function testBackportAndCoreGiveTheSamePathForAnInvoice($mode, $forobject)
	{
		if (version_compare(DOL_VERSION, self::FIRST_VERSION_WITH_FOUR_ARGS, '<')) {
			$this->markTestSkipped('Dolibarr ' . DOL_VERSION . ': the core getMultidirOutput() cannot be asked for a mode nor for an object');
		}

		$invoice = $this->buildInvoice();
		$backport = call_user_func(self::BACKPORT_FUNCTION, $invoice, '', $forobject, $mode);
		$core = getMultidirOutput($invoice, '', $forobject, $mode);

		$this->assertSame($core, $backport, 'mode ' . $mode . ', forobject ' . $forobject . ': the backport and the core disagree on the path of an invoice');
	}

	/**
	 * And getMultidirOutputCompat() gives that same path, whichever branch of the guard it took.
	 *
	 * @param	string	$mode		Mode asked for ('output', 'outputrel', 'temp' or 'version')
	 * @param	int		$forobject	Whether the path of the object itself is asked for
	 * @return	void
	 * @dataProvider pathCaseProvider
	 */
	public function testCompatGivesThatSamePathForAnInvoice($mode, $forobject)
	{
		$invoice = $this->buildInvoice();
		$compat = getMultidirOutputCompat($invoice, '', $forobject, $mode);
		$backport = call_user_func(self::BACKPORT_FUNCTION, $invoice, '', $forobject, $mode);

		$this->assertSame($backport, $compat, 'mode ' . $mode . ', forobject ' . $forobject . ': getMultidirOutputCompat() changed the path of an invoice');
	}

	/**
	 * From Dolibarr 20 on, the guard really sends the call to the core and not to the backport.
	 *
	 * Comparing paths that agree would prove nothing, so the proof is made on an element the two
	 * implementations disagree about on the running core, whichever it turns out to be: what
	 * getMultidirOutputCompat() answers there must be what the core answers. If the backport happens
	 * to agree with the core on all of them, there is nothing to tell apart and the test says so.
	 *
	 * @return void
	 */
	public function testGuardSendsTheCallToTheCore()
	{
		global $db;

		if (version_compare(DOL_VERSION, self::FIRST_VERSION_WITH_FOUR_ARGS, '<')) {
			$this->markTestSkipped('Dolibarr ' . DOL_VERSION . ': the guard is expected to use the backport, see testGuardUsesTheBackport()');
		}

		// Elements whose module alias or subdirectory moved between versions of the core function
		// 'company' is mapped to 'societe' by the backport only up to Dolibarr 23, and 'shipment' is
		// mapped to 'expedition' by the core only from Dolibarr 24 on: one of the two always tells
		// the two implementations apart, whatever modules are enabled on the instance.
		$candidates = array('company', 'shipment', 'expedition', 'commande_fournisseur', 'recruitmentjobposition', 'knowledgerecord', 'produit', 'actioncomm', 'invoice_supplier');

		$found = '';
		foreach ($candidates as $element) {
			$object = new Facture($db);		// only ->element, ->id and ->entity are read to forge the path
			$object->element = $element;
			$object->id = 0;
			$object->entity = 0;

			$backport = call_user_func(self::BACKPORT_FUNCTION, $object, '', 0, 'output');
			$core = getMultidirOutput($object, '', 0, 'output');
			if ($backport !== $core) {
				$compat = getMultidirOutputCompat($object, '', 0, 'output');
				$this->assertSame($core, $compat, 'element ' . $element . ': getMultidirOutputCompat() answered the backport where the core says otherwise');
				$found = $element;
				break;
			}
		}

		if ($found === '') {
			$this->markTestSkipped('Dolibarr ' . DOL_VERSION . ': the backport and the core agree on every element tried, nothing tells the two branches apart here');
		}
	}

	/**
	 * Up to Dolibarr 19, the guard must keep using the backport, because the core cannot answer.
	 *
	 * The two-argument core function ignores $forobject and $mode: it always returns the directory of
	 * the module. getMultidirOutputCompat() must not.
	 *
	 * @return void
	 */
	public function testGuardUsesTheBackport()
	{
		if (version_compare(DOL_VERSION, self::FIRST_VERSION_WITH_FOUR_ARGS, '>=')) {
			$this->markTestSkipped('Dolibarr ' . DOL_VERSION . ': the guard is expected to use the core, see testGuardSendsTheCallToTheCore()');
		}

		$invoice = $this->buildInvoice();

		$moduledir = getMultidirOutputCompat($invoice, '', 0);
		$objectdir = getMultidirOutputCompat($invoice, '', 1);
		$tempdir = getMultidirOutputCompat($invoice, '', 0, 'temp');

		$this->assertNotSame($moduledir, $objectdir, 'Dolibarr ' . DOL_VERSION . ': $forobject was ignored, the core function answered instead of the backport');
		$this->assertStringStartsWith($moduledir, $objectdir, 'The path of the invoice must be under the directory of the module');
		$this->assertNotSame($moduledir, $tempdir, 'Dolibarr ' . DOL_VERSION . ': the temp mode was ignored, the core function answered instead of the backport');
	}
}
