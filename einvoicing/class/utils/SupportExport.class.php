<?php
/* Copyright (C) 2026 Pierre Grasswill
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    einvoicing/class/utils/SupportExport.class.php
 * \ingroup einvoicing
 * \brief   Build the archive a maintainer needs to instruct a platform refusal.
 */

dol_include_once('einvoicing/class/document.class.php');
dol_include_once('einvoicing/class/call.class.php');
dol_include_once('einvoicing/class/providers/AbstractPDPProvider.class.php');
dol_include_once('einvoicing/lib/einvoicing.lib.php');
require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';


/**
 * Build a support archive out of a selection of flows (llx_einvoicing_document) or of API calls
 * (llx_einvoicing_call).
 *
 * The two most useful columns (llx_einvoicing_call.response and llx_einvoicing_document.response_for_debug)
 * are only written when EINVOICING_DEBUG_MODE is set. This packs the flow, the API call, the setup
 * constants and the versions into one file, apart from the list pages so PHPUnit can cover the redaction.
 */
class SupportExport
{
	/** Export of rows of llx_einvoicing_document (the synchronization list). */
	const TYPE_FLOW = 'flow';

	/** Export of rows of llx_einvoicing_call (the API call log). */
	const TYPE_CALL = 'call';

	/**
	 * Records one archive may carry, unless EINVOICING_SUPPORT_EXPORT_MAX says otherwise.
	 *
	 * An e-invoice XML weighs 100 to 200 kB, so this default already builds an archive of some
	 * 10 MB. Going much further is how a support export turns into a timeout or into a memory
	 * limit reached, which is a far worse answer than being told the selection is too wide.
	 */
	const DEFAULT_MAX_RECORDS = 50;

	/**
	 * Fragments of constant name whose VALUE never leaves the instance: the manifest only says
	 * whether such a constant is set. Matched anywhere in the name, so the OAuth tokens the module
	 * stores as <PREFIX>_PROD_TOKEN / _REFRESH are covered as well as the credentials of the setup
	 * page (EINVOICING_SUPERPDP_CLIENT_SECRET, EINVOICING_ESALINK_PASSWORD, ...).
	 *
	 * @var string[]
	 */
	const SENSITIVE_CONST_FRAGMENTS = array('SECRET', 'TOKEN', 'REFRESH', 'PASSWORD', 'PASSWD', 'CLIENT_ID', 'API_KEY', 'CREDENTIAL', 'PRIVATE');

	/** Value written in the manifest in place of a secret that is set. */
	const CONST_SET = '[SET, NOT EXPORTED]';

	/** Value written in the manifest in place of a secret that is not set. */
	const CONST_NOT_SET = '[NOT SET]';

	/** @var DoliDB Database handler */
	private $db;

	/** @var string Error message of the last failed build() */
	public $error = '';

	/**
	 * Counters of the last build(), for the message shown to the user.
	 *
	 * @var array{requested:int,exported:int,skipped:int,files:int,size:int}
	 */
	public $report = array('requested' => 0, 'exported' => 0, 'skipped' => 0, 'files' => 0, 'size' => 0);

	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Number of records one export may carry.
	 *
	 * @return int Always at least 1
	 */
	public static function maxRecords()
	{
		$max = getDolGlobalInt('EINVOICING_SUPPORT_EXPORT_MAX', self::DEFAULT_MAX_RECORDS);

		return ($max > 0 ? $max : self::DEFAULT_MAX_RECORDS);
	}

	/**
	 * Build the archive of a selection.
	 *
	 * @param	string		$type		self::TYPE_FLOW or self::TYPE_CALL
	 * @param	int[]		$ids		Rowids of the selected records
	 * @param	string		$destdir	Directory the archive is written into (created if needed)
	 * @return	string					Full path of the archive, '' when nothing could be built
	 */
	public function build($type, $ids, $destdir)
	{
		global $conf, $langs, $errormsg;

		// The two module messages, plus the ones dol_compress_dir() and dol_mkdir() answer with.
		$langs->loadLangs(array('errors', 'einvoicing@einvoicing'));

		$this->error = '';
		$this->report = array('requested' => count($ids), 'exported' => 0, 'skipped' => 0, 'files' => 0, 'size' => 0);

		if (!in_array($type, array(self::TYPE_FLOW, self::TYPE_CALL), true)) {
			$this->error = 'Unsupported export type '.$type;
			return '';
		}
		if (empty($ids)) {
			$this->error = $langs->trans('NoRecordSelected');
			return '';
		}
		// ZipArchive is a PHP extension, not something the module can require: say so plainly
		// rather than letting dol_compress_dir() answer "no handler found".
		if (!class_exists('ZipArchive')) {
			$this->error = $langs->trans('EInvoicingSupportExportNoZip');
			return '';
		}
		if (count($ids) > self::maxRecords()) {
			$this->error = $langs->trans('EInvoicingSupportExportTooMany', count($ids), self::maxRecords());
			return '';
		}

		$stamp = dol_print_date(dol_now(), '%Y%m%d-%H%M%S', 'tzuser');
		$basename = 'einvoicing-support-'.((int) $conf->entity).'-'.$stamp;

		if (dol_mkdir($destdir) < 0) {
			$this->error = $langs->trans('ErrorFailedToCreateDir', $destdir);
			return '';
		}
		// The archive is built from a directory, so the content is staged next to it and removed
		// as soon as dol_compress_dir() has read it.
		$stagingdir = $destdir.'/'.$basename.'.build';
		if (dol_mkdir($stagingdir) < 0) {
			$this->error = $langs->trans('ErrorFailedToCreateDir', $stagingdir);
			return '';
		}

		$records = ($type == self::TYPE_FLOW ? $this->stageFlows($ids, $stagingdir) : $this->stageCalls($ids, $stagingdir));

		$this->writeFile($stagingdir.'/manifest.json', $this->encodeJson($this->manifest($type, $records)));

		$zipfile = $destdir.'/'.$basename.'.zip';
		$res = dol_compress_dir($stagingdir, $zipfile, 'zip');

		dol_delete_dir_recursive($stagingdir);

		if ($res <= 0) {
			$this->error = (empty($errormsg) ? $langs->trans('ErrorFailedToBuildArchive', $zipfile) : $errormsg);
			return '';
		}

		$this->report['size'] = (int) dol_filesize($zipfile);

		return $zipfile;
	}

	/**
	 * Send the archive to the browser, then remove it from the server.
	 *
	 * Not left in the "generated documents" box: dol_check_secure_access_document() grants the modulepart
	 * massfilesarea_einvoicing on hasRight(<module>, 'lire') up to Dolibarr 21 and on 'read' from 22,
	 * while the module declares 'read', so that link is forbidden on 18 to 21.
	 *
	 * Headers are sent here, so the caller must have written nothing yet and must exit right after.
	 *
	 * @param	string	$zipfile	Archive returned by build()
	 * @return	void
	 */
	public static function deliver($zipfile)
	{
		top_httphead('application/zip');
		header('Content-Disposition: attachment; filename="'.basename($zipfile).'"');
		header('Content-Length: '.dol_filesize($zipfile));

		readfile($zipfile);

		dol_delete_file($zipfile, 0, 1);
	}

	/**
	 * Context of the export: everything needed to read the rest of the archive without asking the
	 * user a single further question - which core, which module build, which platform, which
	 * entity, and whether the debug columns could have been written at all.
	 *
	 * @param	string					$type		self::TYPE_FLOW or self::TYPE_CALL
	 * @param	array<int,string>		$records	Identifiers of the records actually exported
	 * @return	array<string,mixed>					Content of manifest.json
	 * @phan-suppress PhanPluginMoreSpecificActualReturnType
	 *  MoreSpecificElementTypePlugin asks the documented type to repeat the union of the literal
	 *  value types of this very array, which would have to be rewritten at every added field.
	 */
	public function manifest($type, $records = array())
	{
		global $conf;

		return array(
			'export_type' => $type,
			'generated_at' => dol_print_date(dol_now(), 'standard', 'gmt').' GMT',
			'dolibarr_version' => DOL_VERSION,
			'einvoicing_version' => einvoicingModuleStamp(),
			'php_version' => PHP_VERSION,
			'entity' => (int) $conf->entity,
			'provider' => getDolGlobalString('EINVOICING_PDP'),
			'protocol' => getDolGlobalString('EINVOICING_PROTOCOL'),
			// Without this flag a reader cannot tell an API call that answered nothing from a call
			// whose answer was simply never stored.
			// The providers gate the two debug columns on getDolGlobalString(), so read it the
			// same way rather than answering something the write path would not agree with.
			'debug_mode' => (getDolGlobalString('EINVOICING_DEBUG_MODE') ? true : false),
			'requested_count' => $this->report['requested'],
			'exported_count' => $this->report['exported'],
			'skipped_count' => $this->report['skipped'],
			'records' => array_values($records),
			'constants' => self::exportedConstants(),
		);
	}

	/**
	 * The EINVOICING_* constants of the current entity, secrets replaced by whether they are set.
	 *
	 * Read from $conf->global rather than from llx_const: the manifest then shows what the code saw,
	 * entity resolution and decryption (dolDecrypt) included - which is why the values are filtered here.
	 *
	 * @return array<string,string> Constant name => value or placeholder, sorted by name
	 * @phan-suppress PhanPluginMoreSpecificActualReturnType
	 *  MoreSpecificElementTypePlugin reads the array as non-empty because it is filled in a loop.
	 *  It is empty when nothing matched, and callers have to keep handling that.
	 */
	public static function exportedConstants()
	{
		global $conf;

		$out = array();
		foreach ((array) $conf->global as $name => $value) {
			if (strpos($name, 'EINVOICING') !== 0) {
				continue;
			}
			if (self::isSensitiveConstant($name)) {
				$out[$name] = (($value === '' || $value === null) ? self::CONST_NOT_SET : self::CONST_SET);
				continue;
			}
			$out[$name] = (string) $value;
		}
		ksort($out);

		return $out;
	}

	/**
	 * Whether the value of a constant must stay on the instance.
	 *
	 * @param	string	$name	Constant name
	 * @return	bool			True when only the "set / not set" state may be exported
	 */
	public static function isSensitiveConstant($name)
	{
		foreach (self::SENSITIVE_CONST_FRAGMENTS as $fragment) {
			if (strpos($name, $fragment) !== false) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Write the selected flows under flows/<identifier>/.
	 *
	 * @param	int[]				$ids		Rowids of the selected flows
	 * @param	string				$stagingdir	Directory being staged
	 * @return	array<int,string>				Identifier of each exported flow
	 * @phan-suppress PhanPluginMoreSpecificActualReturnType
	 *  MoreSpecificElementTypePlugin reads the array as non-empty because it is filled in a loop.
	 *  It is empty when nothing matched, and callers have to keep handling that.
	 */
	private function stageFlows($ids, $stagingdir)
	{
		$records = array();
		$reachable = $this->reachableIds(new Document($this->db), $ids);

		foreach ($reachable as $id) {
			$flow = new Document($this->db);
			if ($flow->fetch($id) <= 0) {
				$this->report['skipped']++;
				continue;
			}

			$identifier = $this->identifierOf($flow, 'flow_id');
			$dir = $stagingdir.'/flows/'.$identifier;
			dol_mkdir($dir);

			// The bodies go to files of their own: they are what a maintainer opens in an editor,
			// and an XML escaped inside a JSON string is unreadable.
			$this->writeFile($dir.'/flow.json', $this->encodeJson($this->rowOf($flow, array('document_body', 'xml_data', 'response_for_debug'))));

			$xml = (empty($flow->document_body) ? $flow->xml_data : $flow->document_body);
			if (!empty($xml)) {
				$this->writeFile($dir.'/document.xml', self::redact($xml));
			}
			if (!empty($flow->response_for_debug)) {
				$this->writeFile($dir.'/metadata.json', self::redact($flow->response_for_debug));
			}

			$this->report['exported']++;
			$records[] = $identifier;
		}

		return $records;
	}

	/**
	 * Write the selected API calls under calls/<identifier>/.
	 *
	 * @param	int[]				$ids		Rowids of the selected calls
	 * @param	string				$stagingdir	Directory being staged
	 * @return	array<int,string>				Identifier of each exported call
	 * @phan-suppress PhanPluginMoreSpecificActualReturnType
	 *  MoreSpecificElementTypePlugin reads the array as non-empty because it is filled in a loop.
	 *  It is empty when nothing matched, and callers have to keep handling that.
	 */
	private function stageCalls($ids, $stagingdir)
	{
		$records = array();
		$reachable = $this->reachableIds(new Call($this->db), $ids);

		foreach ($reachable as $id) {
			$call = new Call($this->db);
			if ($call->fetch($id) <= 0) {
				$this->report['skipped']++;
				continue;
			}

			$identifier = $this->identifierOf($call, 'call_id');
			$dir = $stagingdir.'/calls/'.$identifier;
			dol_mkdir($dir);

			// Request and response stay in the row here: they are short enough to read in place,
			// and keeping them together is what makes a call log readable.
			$this->writeFile($dir.'/call.json', $this->encodeJson($this->rowOf($call, array())));

			$this->report['exported']++;
			$records[] = $identifier;
		}

		return $records;
	}

	/**
	 * Keep, of the posted selection, the rows the current entity may read.
	 *
	 * The filter has to be a query of its own: fetch() answers on the rowid alone and the entity column
	 * never reaches the object, CommonObject::getFieldList() leaving it out of the SELECT it builds
	 * (Dolibarr 18 to 24). So ids posted in a form are filtered here, on getEntity(), the way the list
	 * pages filter their own query. Anything dropped is counted as skipped in the manifest.
	 *
	 * @param	Document|Call	$object		An instance of the class being exported, for its table
	 * @param	int[]			$ids		Rowids as they were posted
	 * @return	int[]						Rowids that exist and are within reach, in ascending order
	 */
	private function reachableIds($object, $ids)
	{
		$wanted = array();
		foreach ($ids as $id) {
			if ((int) $id > 0) {
				$wanted[(int) $id] = (int) $id;
			}
		}
		if (empty($wanted)) {
			$this->report['skipped'] += count($ids);
			return array();
		}

		$sql = "SELECT rowid FROM ".$this->db->prefix().$object->table_element;
		$sql .= " WHERE rowid IN (".$this->db->sanitize(implode(',', $wanted)).")";
		$sql .= " AND entity IN (".getEntity($object->element).")";

		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog(__METHOD__.' '.$this->db->lasterror(), LOG_ERR);
			$this->report['skipped'] += count($wanted);
			return array();
		}

		$reachable = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$reachable[] = (int) $obj->rowid;
		}
		$this->db->free($resql);

		$this->report['skipped'] += (count($ids) - count($reachable));

		return $reachable;
	}

	/**
	 * Name of the directory holding one record: its functional identifier when it has one, its
	 * rowid otherwise. A flow that never reached the platform has no flow_id.
	 *
	 * @param	Document|Call	$object		Record being exported
	 * @param	string			$property	Property holding the functional identifier
	 * @return	string						A file name, safe to use as a directory name
	 */
	private function identifierOf($object, $property)
	{
		$identifier = (empty($object->$property) ? (string) $object->id : (string) $object->$property);

		return dol_sanitizeFileName($identifier);
	}

	/**
	 * The columns of a record, read from the field definition of its class so the export follows
	 * the class rather than a list copied by hand, redacted, and with the bodies left out.
	 *
	 * @param	Document|Call		$object		Record read back from the database
	 * @param	string[]			$except		Properties written to files of their own
	 * @return	array<string,mixed>				Row ready to be encoded
	 * @phan-suppress PhanPluginMoreSpecificActualReturnType
	 *  MoreSpecificElementTypePlugin asks the documented type to repeat the union of the literal
	 *  value types of this very array, which would have to be rewritten at every added field.
	 */
	private function rowOf($object, $except)
	{
		$row = array('rowid' => (int) $object->id);

		foreach (array_keys($object->fields) as $field) {
			// 'entity' is skipped, not forgotten: CommonObject::getFieldList() leaves it out of the
			// SELECT of fetch(), so the property is always null here and writing it would only say
			// something false. The entity of the export is named once, in the manifest.
			if ($field == 'rowid' || $field == 'entity' || in_array($field, $except, true)) {
				continue;
			}
			$value = (isset($object->$field) ? $object->$field : null);

			// Dates come back from fetch() as unix timestamps. An archive that is read by a person
			// says them in full, and the class already knows which columns are dates.
			if ($object->isDate($object->fields[$field])) {
				$row[$field] = (empty($value) ? null : dol_print_date($value, 'standard', 'gmt').' GMT');
				continue;
			}
			if (is_int($value) || is_float($value) || is_bool($value) || is_null($value)) {
				$row[$field] = $value;
				continue;
			}
			$row[$field] = self::redact((string) $value);
		}

		return $row;
	}

	/**
	 * Redact the credentials of a value on its way out.
	 *
	 * The call log is already written redacted (AbstractPDPProvider::logCall()), so this is
	 * defence in depth: rows written before that redaction existed still hold what the platform
	 * answered, tokens included, and this export is precisely what would take them out of the
	 * instance.
	 *
	 * @param	string	$value	Value about to be written into the archive
	 * @return	string			Same value, credentials replaced
	 */
	public static function redact($value)
	{
		$redacted = AbstractPDPProvider::redactSensitiveData($value);

		return (is_string($redacted) ? $redacted : (string) json_encode($redacted));
	}

	/**
	 * @param	array<string,mixed>	$data	Data to encode
	 * @return	string						Pretty printed JSON, readable in a text editor
	 */
	private function encodeJson($data)
	{
		return (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	}

	/**
	 * @param	string	$path		File to write
	 * @param	string	$content	Its content
	 * @return	void
	 */
	private function writeFile($path, $content)
	{
		if (file_put_contents($path, $content) !== false) {
			$this->report['files']++;
			dolChmod($path);
		}
	}
}
