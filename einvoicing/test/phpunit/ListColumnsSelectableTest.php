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
 *      \file       test/phpunit/ListColumnsSelectableTest.php
 *      \ingroup    test
 *      \brief      PHPUnit test for the columns the module adds to the invoice lists: each one is declared
 *                  in the column selector of the core, and its title, filter and cell follow its checkbox.
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
require_once DOL_DOCUMENT_ROOT . '/core/class/hookmanager.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/class/html.form.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/functions2.lib.php';
dol_include_once('einvoicing/class/actions_einvoicing.class.php');
require_once __DIR__ . '/CommonClassTestCompat.inc.php';

/**
 * Class for PHPUnit tests
 *
 * @backupGlobals disabled
 * @backupStaticAttributes enabled
 * @remarks	backupGlobals must be disabled to have db,conf,user and lang not erased.
 */
class ListColumnsSelectableTest extends CommonClassTest
{
	/**
	 * @var array<string,mixed>	Constants of the module as they were before the test, null when unset
	 */
	private $savedConst = array();

	/**
	 * Set a constant for this test only, the way getDolGlobalString() reads it.
	 *
	 * @param	string	$name	Constant
	 * @param	string	$value	Value, '' to unset it
	 * @return	void
	 */
	private function setConst($name, $value)
	{
		global $conf;

		if (!array_key_exists($name, $this->savedConst)) {
			$this->savedConst[$name] = isset($conf->global->$name) ? $conf->global->$name : null;
		}
		if ($value === '') {
			unset($conf->global->$name);
		} else {
			$conf->global->$name = $value;
		}
	}

	/**
	 * Give back the constants as the instance had them.
	 *
	 * @return void
	 */
	protected function tearDown(): void
	{
		global $conf;

		foreach ($this->savedConst as $name => $value) {
			if ($value === null) {
				unset($conf->global->$name);
			} else {
				$conf->global->$name = $value;
			}
		}
		$this->savedConst = array();

		parent::tearDown();
	}

	/**
	 * A configured module, sending and receiving.
	 *
	 * @return void
	 */
	private function configure()
	{
		global $conf;

		$conf->modules['einvoicing'] = 'einvoicing';
		$this->setConst('EINVOICING_PDP', 'SUPERPDP');
		$this->setConst('EINVOICING_PROTOCOL', 'CII');
		$this->setConst('EINVOICING_DISABLE_SYNC_DOLI_TO_AP', '');
		$this->setConst('EINVOICING_DISABLE_SYNC_AP_TO_DOLI', '');
		$this->setConst('EINVOICING_ONLY_GENERATE', '');
	}

	/**
	 * The fields the module declares for a list, through the hook the core calls.
	 *
	 * @param	string	$context	Context of the list
	 * @return	array<string,mixed>	Its arrayfields once the hook ran
	 */
	private function declaredFields($context)
	{
		global $db;

		$arrayfields = array();
		$parameters = array('context' => $context, 'arrayfields' => &$arrayfields);
		$action = '';
		$hooks = new ActionsEInvoicing($db);
		$hooks->completeArrayFields($parameters, null, $action, new HookManager($db));

		return $arrayfields;
	}

	/**
	 * Number of cells the title, filter and value hooks print, and the columns the value hook counts.
	 *
	 * @param	string				$context		Context of the list
	 * @param	array<string,mixed>	$arrayfields	Fields of the list, with their checkbox state
	 * @return	array{title:int,filter:int,value:int,nbfield:int}
	 */
	private function printedCells($context, $arrayfields)
	{
		global $db, $form;

		$form = new Form($db);
		$hooks = new ActionsEInvoicing($db);
		$hookmanager = new HookManager($db);
		$action = '';
		$obj = (object) array('id' => 0, 'ref' => '', 'pdplink_id' => 0, 'pdp_provider' => '', 'pdp_syncstatus' => 0, 'pdp_lcstatus' => 0);
		$counts = array();

		$parameters = array('context' => $context, 'arrayfields' => $arrayfields, 'param' => '', 'sortfield' => '', 'sortorder' => '');
		ob_start();
		$hooks->printFieldListTitle($parameters, null, $action, $hookmanager);
		$counts['title'] = substr_count(ob_get_clean(), '<th');

		ob_start();
		$hooks->printFieldListOption($parameters, null, $action, $hookmanager);
		$counts['filter'] = substr_count(ob_get_clean(), '<td');

		$totalarray = array('nbfield' => 0);
		$parameters = array('context' => $context, 'arrayfields' => $arrayfields, 'obj' => $obj, 'i' => 0, 'totalarray' => &$totalarray);
		ob_start();
		$hooks->printFieldListValue($parameters, null, $action, $hookmanager);
		$counts['value'] = substr_count(ob_get_clean(), '<td');
		$counts['nbfield'] = $totalarray['nbfield'];

		return $counts;
	}

	/**
	 * The Access Point column of the customer invoice list has its checkbox, checked by default (#1233).
	 *
	 * @return void
	 */
	public function testTheAccessPointColumnOfTheInvoiceListIsSelectable()
	{
		$this->configure();

		$fields = $this->declaredFields('invoicelist:main');

		$this->assertArrayHasKey('pdp_provider', $fields);
		$this->assertSame('einvoicingSourceTitle', $fields['pdp_provider']['label']);
		$this->assertSame(1, $fields['pdp_provider']['checked']);
	}

	/**
	 * Both columns of the supplier invoice list have their checkbox, and none while reception is off.
	 *
	 * @return void
	 */
	public function testTheColumnsOfTheSupplierInvoiceListAreSelectable()
	{
		$this->configure();

		$fields = $this->declaredFields('supplierinvoicelist:main');
		$this->assertArrayHasKey('pdp_provider', $fields);
		$this->assertArrayHasKey('pdp_lcstatus', $fields);

		$this->setConst('EINVOICING_ONLY_GENERATE', '1');
		$this->assertSame(array(), $this->declaredFields('supplierinvoicelist:main'));
	}

	/**
	 * Unchecking a column takes away its title, its filter and its cell together, so the columns that
	 * follow stay aligned; a checked "E-invoice file" column gets its filter cell too.
	 *
	 * @return void
	 */
	public function testTitleFilterAndCellFollowTheCheckbox()
	{
		$this->configure();

		foreach (array('invoicelist:main' => array('pdp_provider', 'einvoicegenerated', 'pdp_syncstatus'), 'supplierinvoicelist:main' => array('pdp_provider', 'pdp_lcstatus')) as $context => $keys) {
			$fields = $this->declaredFields($context);
			foreach ($keys as $key) {
				foreach (array(0, 1) as $checked) {
					$arrayfields = $fields;
					$arrayfields[$key]['checked'] = $checked;
					$cells = $this->printedCells($context, $arrayfields);

					$this->assertSame($cells['value'], $cells['title'], $context . ' ' . $key . '=' . $checked . ': titles');
					$this->assertSame($cells['value'], $cells['filter'], $context . ' ' . $key . '=' . $checked . ': filters');
					$this->assertSame($cells['value'], $cells['nbfield'], $context . ' ' . $key . '=' . $checked . ': nbfield');
				}
				$arrayfields = $fields;
				$arrayfields[$key]['checked'] = 1;
				$shown = $this->printedCells($context, $arrayfields);
				$arrayfields[$key]['checked'] = 0;
				$hidden = $this->printedCells($context, $arrayfields);
				$this->assertSame($shown['value'] - 1, $hidden['value'], $context . ' ' . $key . ': one cell less once unchecked');
			}
		}
	}
}
