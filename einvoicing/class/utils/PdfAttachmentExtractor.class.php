<?php
/* Copyright (C) 2026		Pierre Grasswill			<da.grumpf@gmail.com>
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
 * \file    einvoicing/class/utils/PdfAttachmentExtractor.class.php
 * \ingroup einvoicing
 * \brief   Read the invoice attached to a received Factur-X PDF, with the core PDF parser.
 */

// tcpdi_parser is the PDF parser of the core and pulls nothing but tcpdf_filters.php: no TCPDF, no
// FPDF, no composer. TCPDI_PATH is the constant filefunc.inc.php defines for it, on every version.
$tcpdiPath = defined('TCPDI_PATH') ? constant('TCPDI_PATH') : DOL_DOCUMENT_ROOT.'/includes/tcpdi/';
require_once $tcpdiPath.'tcpdi_parser.php';


/**
 * Extract the files embedded in a PDF/A-3 container.
 *
 * Reception only. It stands on tcpdi_parser, which the core ships and which pulls nothing but
 * tcpdf_filters.php: no composer, no TCPDF, no FPDF, so it runs wherever Dolibarr itself runs.
 * Emission keeps horstoeko/zugferd, whose merger writes the container this class reads.
 */
class PdfAttachmentExtractor extends tcpdi_parser
{
	/**
	 * Names Factur-X, ZUGFeRD and XRechnung give to the invoice they attach
	 */
	const INVOICE_ATTACHMENT_NAMES = array('factur-x.xml', 'zugferd-invoice.xml', 'ZUGFeRD-invoice.xml', 'xrechnung.xml');

	/**
	 * Deepest dictionary read, a crafted container is not trusted to stop nesting
	 */
	const MAX_DICTIONARY_DEPTH = 64;

	/**
	 * Files found in the container, keyed by their declared name
	 *
	 * @var array<string,string>
	 */
	private $attachments = array();

	/**
	 * Whether the container was walked already
	 *
	 * @var bool
	 */
	private $collected = false;

	/**
	 * Raw content of the PDF, which the parent keeps private
	 *
	 * @var string
	 */
	private $content = '';

	/**
	 * Depth of the dictionary being read
	 *
	 * @var int
	 */
	private $dictionaryDepth = 0;

	/**
	 * Free the memory held by the parser.
	 *
	 * @return	void
	 */
	public function cleanUp()
	{
		$this->content = '';
		parent::cleanUp();
	}

	/**
	 * Return the invoice attached to a Factur-X container.
	 *
	 * @param	string		$pdfContent		Raw content of the PDF
	 * @return	string						The embedded XML
	 * @throws	Exception					When the container carries no invoice
	 */
	public static function getInvoiceXmlFromContent($pdfContent)
	{
		$parser = new PdfAttachmentExtractor($pdfContent, md5($pdfContent));
		$attachments = $parser->getEmbeddedFiles();
		$parser->cleanUp();

		foreach (self::INVOICE_ATTACHMENT_NAMES as $name) {
			if (isset($attachments[$name])) {
				return $attachments[$name];
			}
		}

		throw new Exception('No invoice attachment found in the PDF');
	}

	/**
	 * Return the invoice attached to a Factur-X container held in a file.
	 *
	 * @param	string		$pdfFilename	Full path of the PDF
	 * @return	string						The embedded XML
	 * @throws	Exception					When the file cannot be read or carries no invoice
	 */
	public static function getInvoiceXmlFromFile($pdfFilename)
	{
		if (!is_readable($pdfFilename)) {
			throw new Exception('PDF file is not readable: '.basename($pdfFilename));
		}

		return self::getInvoiceXmlFromContent((string) file_get_contents($pdfFilename));
	}

	/**
	 * @param	string		$data			Raw content of the PDF
	 * @param	string		$uniqueid		Identifier of this parser
	 */
	public function __construct($data, $uniqueid)
	{
		$this->content = self::blankCommentsBeforeStartxref((string) $data);
		parent::__construct($this->content, $uniqueid);
	}

	/**
	 * Blank the comment lines written between a trailer and its startxref, which tcpdi 1.0 (core below 22) cannot skip.
	 *
	 * @param	string		$data			Raw content of the PDF
	 * @return	string						The same content, of the same length
	 */
	private static function blankCommentsBeforeStartxref($data)
	{
		// Length must be kept: every offset of the xref table points into this content.
		$blanked = preg_replace_callback('/>>((?:[ \t\r\n]*%[^\r\n]*[\r\n]+)+)(?=[ \t\r\n]*startxref)/', function ($matches) {
			return '>>'.preg_replace('/[^\r\n]/', ' ', $matches[1]);
		}, $data);

		return ($blanked === null ? $data : $blanked);
	}

	/**
	 * Refuse a PDF that cannot be read, instead of ending the request on the spot.
	 *
	 * tcpdi_parser::Error() calls die() - unconditionally below Dolibarr 24, and above it unless the
	 * instance asked TCPDF for exceptions. A truncated container coming from an access point would
	 * take the whole synchronization down with it, so the failure is raised as the exception the
	 * reception already knows how to report.
	 *
	 * @param	string		$msg			Message from the parser
	 * @return	void
	 * @throws	Exception					Always
	 */
	public function Error($msg)			// phpcs:ignore PEAR.NamingConventions.ValidFunctionName.ScopeNotCamelCaps
	{
		throw new Exception('PDF cannot be read: '.$msg);
	}

	/**
	 * Read one object; comments, dictionaries and hex strings are read here, the parent loops or fails on them.
	 *
	 * @param	int			$offset			Offset of the object
	 * @param	string|null	$data			Data to read, the whole PDF when null
	 * @return	array{0:array<int,mixed>,1:int}	Object, then the offset that follows it
	 * @throws	Exception					When the object cannot be read
	 */
	protected function getRawObject($offset = 0, $data = null)
	{
		if ($data === null) {
			$data = $this->content;
		}
		$length = strlen($data);
		$start = $offset + strspn($data, "\x00\x09\x0a\x0c\x0d\x20", $offset);
		while ($start < $length && $data[$start] === '%') {
			$start += strcspn($data, "\r\n", $start);
			$start += strspn($data, "\x00\x09\x0a\x0c\x0d\x20", $start);
		}
		if ($start >= $length) {
			$this->Error('unexpected end of data at offset '.$start);
		}
		if ($data[$start] !== '<') {
			$object = parent::getRawObject($start, $data);
			if ($object[1] <= $start) {		// the parent stays put on a byte it does not know, its callers would loop forever
				$this->Error('unexpected data at offset '.$start);
			}
			return $object;
		}

		if (substr($data, $start, 2) !== '<<') {
			$end = strpos($data, '>', $start);
			if ($end === false) {
				$this->Error('hexadecimal string not closed at offset '.$start);
			}
			return array(array(PDF_TYPE_HEX, substr($data, $start + 1, $end - $start - 1)), $end + 1);
		}

		if ($this->dictionaryDepth >= self::MAX_DICTIONARY_DEPTH) {
			$this->Error('dictionary nested too deeply at offset '.$start);
		}
		$this->dictionaryDepth++;
		try {
			$dict = array();
			$offset = $start + 2;
			do {
				list($key, $offset) = $this->getRawObject($offset, $data);
				if ($key[0] === '>>') {
					return array(array(PDF_TYPE_DICTIONARY, $dict), $offset);
				}
				if ($key[0] !== PDF_TYPE_TOKEN || $offset >= $length) {
					break;
				}
				list($element, $offset) = $this->getRawObject($offset, $data);
				$dict['/'.$key[1]] = $element;
			} while ($offset < $length);
		} finally {
			$this->dictionaryDepth--;
		}

		throw new Exception('PDF cannot be read: dictionary not closed at offset '.$start);
	}

	/**
	 * Read a cross-reference stream, in place of the parent which does not count the compressed objects
	 * and then files every object that follows under the wrong number.
	 *
	 * @param	int						$startxref		Offset of the cross-reference stream
	 * @param	array<string,mixed>		$xref			Entries read from the newer sections
	 * @return	array<string,mixed>						Entries and trailer
	 * @throws	Exception								When the stream cannot be read
	 */
	protected function decodeXrefStream($startxref, $xref = array())
	{
		list($xrefobj) = $this->getRawObject($startxref);
		$xrefcrs = $this->getIndirectObject($xrefobj[1], $startxref, true);
		$dict = $this->dictOf($xrefcrs[0]);
		if ($dict === null || !isset($dict['/Type'][1]) || $dict['/Type'][1] !== 'XRef' || !isset($xrefcrs[1][3][0])) {
			$this->Error('invalid cross-reference stream at offset '.$startxref);
		}

		if (!isset($xref['xref_location'])) {
			$xref['xref_location'] = $startxref;
			$xref['max_object'] = 0;
		}
		if (!isset($xref['xref'])) {
			$xref['xref'] = array();
		}
		if (empty($xref['trailer'])) {		// the newest section holds the trailer
			$xref['trailer'] = array(PDF_TYPE_DICTIONARY, array());
			foreach (array('/Size', '/Root', '/Info', '/ID') as $key) {
				if (isset($dict[$key])) {
					$xref['trailer'][1][$key] = $dict[$key];
				}
			}
		}

		$widths = array();
		foreach ($this->arrayOf(isset($dict['/W']) ? $dict['/W'] : null) as $width) {
			$widths[] = (int) $width[1];
		}
		$params = $this->dictOf(isset($dict['/DecodeParms']) ? $dict['/DecodeParms'] : null);
		$predictor = isset($params['/Predictor'][1]) ? (int) $params['/Predictor'][1] : 1;
		if (count($widths) != 3 || ($predictor > 1 && $predictor < 10)) {
			$this->Error('unsupported cross-reference stream at offset '.$startxref);
		}
		$rows = self::unpredictRows($xrefcrs[1][3][0], array_sum($widths), $predictor >= 10);

		// Each pair of /Index gives the first number and the count of a subsection, the whole table by default
		$index = $this->arrayOf(isset($dict['/Index']) ? $dict['/Index'] : null);
		if (empty($index)) {
			$index = array(array(PDF_TYPE_NUMERIC, 0), isset($dict['/Size']) ? $dict['/Size'] : array(PDF_TYPE_NUMERIC, count($rows)));
		}
		$row = 0;
		$rowCount = count($rows);
		$indexCount = count($index);
		for ($i = 1; $i < $indexCount; $i += 2) {
			$number = (int) $index[$i - 1][1];
			$end = $number + (int) $index[$i][1];
			for (; $number < $end && $row < $rowCount; $number++, $row++) {
				$fields = array($widths[0] ? 0 : 1, 0, 0);		// the type is 1 when its field is left out
				$byte = 0;
				for ($field = 0; $field < 3; $field++) {
					for ($b = 0; $b < $widths[$field]; $b++) {
						$fields[$field] = ($fields[$field] << 8) + $rows[$row][$byte++];
					}
				}
				// Only the objects stored uncompressed have an offset, the newest section wins
				if ($fields[0] == 1 && !isset($xref['xref'][$number][$fields[2]])) {
					$xref['xref'][$number][$fields[2]] = $fields[1];
				}
			}
			$xref['max_object'] = max($xref['max_object'], $number);
		}

		if (isset($dict['/Prev'][1])) {
			$prev = (int) $dict['/Prev'][1];
			if (!in_array($prev, $this->xref_seen_offsets)) {		// a /Prev chain is not trusted to end
				$this->xref_seen_offsets[] = $prev;
				$xref = $this->getXrefData($prev, $xref);
			}
		}

		return $xref;
	}

	/**
	 * Split a cross-reference stream into rows of bytes, undoing the PNG predictor of each row when it has one.
	 *
	 * @param	string		$data			Decoded stream
	 * @param	int			$rowLength		Bytes in a row, without the predictor byte
	 * @param	bool		$png			Whether each row starts with a PNG predictor byte
	 * @return	array<int,array<int,int>>	Rows of bytes
	 */
	private static function unpredictRows($data, $rowLength, $png)
	{
		$bytes = array_values((array) unpack('C*', $data));
		$stride = $rowLength + ($png ? 1 : 0);
		$rows = array();
		$previous = array_fill(0, $rowLength, 0);
		$byteCount = count($bytes);
		for ($offset = 0; $rowLength > 0 && $offset + $stride <= $byteCount; $offset += $stride) {
			if (!$png) {
				$rows[] = array_slice($bytes, $offset, $rowLength);
				continue;
			}
			$filter = $bytes[$offset];
			$row = array();
			for ($i = 0; $i < $rowLength; $i++) {
				$value = $bytes[$offset + 1 + $i];
				$left = $i > 0 ? $row[$i - 1] : 0;
				$up = $previous[$i];
				$upLeft = $i > 0 ? $previous[$i - 1] : 0;
				switch ($filter) {
					case 1:		// Sub
						$value += $left;
						break;
					case 2:		// Up
						$value += $up;
						break;
					case 3:		// Average
						$value += intdiv($left + $up, 2);
						break;
					case 4:		// Paeth
						$p = $left + $up - $upLeft;
						$pa = abs($p - $left);
						$pb = abs($p - $up);
						$pc = abs($p - $upLeft);
						$value += ($pa <= $pb && $pa <= $pc) ? $left : ($pb <= $pc ? $up : $upLeft);
						break;
				}
				$row[$i] = $value & 0xff;
			}
			$rows[] = $row;
			$previous = $row;
		}

		return $rows;
	}

	/**
	 * List the objects an object stream holds, in place of the parent which splits its header on spaces only
	 * while a PDF may separate the pairs with any white space.
	 *
	 * @param	array{0:int|string,1:int|string}	$key	Number and generation of the object stream
	 * @return	void
	 */
	public function extractObjectStream($key)
	{
		$object = $this->getObjectVal(array(PDF_TYPE_OBJREF, $key[0], $key[1]));
		if ($object[0] !== PDF_TYPE_STREAM || !isset($object[1][1]['/First'][1])) {
			return;		// not an object stream, the parent skips it as well
		}
		$stream = $this->decodeStream($object[1][1], $object[2][1]);
		$first = (int) $object[1][1]['/First'][1];
		$pairs = preg_split('/\s+/', trim(substr($stream[0], 0, $first)));
		$pairCount = count($pairs);
		for ($i = 1; $i < $pairCount; $i += 2) {
			$this->objstreamobjs[$pairs[$i - 1]] = array($key, (int) $pairs[$i] + $first);
		}
	}

	/**
	 * Return every file embedded in the container, keyed by its declared name.
	 *
	 * @return	array<string,string>		Name => raw content, empty when the PDF carries none
	 */
	public function getEmbeddedFiles()
	{
		if ($this->collected) {
			return $this->attachments;
		}
		$this->collected = true;

		$root = $this->dictOf(isset($this->xref['trailer'][1]['/Root']) ? $this->xref['trailer'][1]['/Root'] : null);
		if ($root === null) {
			return $this->attachments;
		}

		// The name tree of the catalog, where a PDF lists what it embeds since version 1.4
		if (isset($root['/Names'])) {
			$names = $this->dictOf($root['/Names']);
			if ($names !== null && isset($names['/EmbeddedFiles'])) {
				$this->walkNameTree($names['/EmbeddedFiles'], 0);
			}
		}

		// The associated files of the catalog, the form PDF/A-3 requires and Factur-X mandates
		if (isset($root['/AF'])) {
			foreach ($this->arrayOf($root['/AF']) as $fileSpec) {
				$this->readFileSpec($fileSpec);
			}
		}

		return $this->attachments;
	}

	/**
	 * Walk one node of a name tree, its children first, then the pairs it holds itself.
	 *
	 * @param	array		$node			Node element
	 * @param	int			$depth			Current depth, the tree is not trusted to end
	 * @return	void
	 */
	private function walkNameTree($node, $depth)
	{
		if ($depth > 32) {
			return;
		}
		$dict = $this->dictOf($node);
		if ($dict === null) {
			return;
		}

		if (isset($dict['/Kids'])) {
			foreach ($this->arrayOf($dict['/Kids']) as $kid) {
				$this->walkNameTree($kid, $depth + 1);
			}
		}

		if (isset($dict['/Names'])) {
			$pairs = $this->arrayOf($dict['/Names']);
			$count = count($pairs);
			for ($i = 1; $i < $count; $i += 2) {		// a name tree alternates name and value
				$this->readFileSpec($pairs[$i]);
			}
		}
	}

	/**
	 * Store the content of one file specification, when it does carry an embedded stream.
	 *
	 * @param	array		$element		File specification, or a reference to one
	 * @return	void
	 */
	private function readFileSpec($element)
	{
		$spec = $this->dictOf($element);
		if ($spec === null || !isset($spec['/EF'])) {
			return;
		}
		$embedded = $this->dictOf($spec['/EF']);
		if ($embedded === null) {
			return;
		}

		$streamRef = isset($embedded['/F']) ? $embedded['/F'] : (isset($embedded['/UF']) ? $embedded['/UF'] : null);
		if ($streamRef === null || $streamRef[0] != PDF_TYPE_OBJREF) {
			return;
		}
		$object = $this->getObjectVal($streamRef);
		if ($object[0] != PDF_TYPE_STREAM) {
			return;
		}
		$content = $this->applyFilters($object[1][1], $object[2][1]);
		if ($content === null) {
			return;
		}

		$name = '';
		foreach (array('/UF', '/F') as $key) {			// the unicode name first, it is the reliable one
			if (isset($spec[$key]) && in_array($spec[$key][0], array(PDF_TYPE_STRING, PDF_TYPE_HEX))) {
				$name = $this->decodeText($spec[$key][0], (string) $spec[$key][1]);
				if ($name !== '') {
					break;
				}
			}
		}
		if ($name === '') {
			$name = 'attachment-'.count($this->attachments);
		}

		$this->attachments[$name] = $content;
	}

	/**
	 * Undo the filter chain of a stream.
	 *
	 * tcpdi_parser::decodeStream() is not used: its branch for a /Filter written as an array sits
	 * under a test on PDF_TYPE_TOKEN, so it never runs and such a stream comes back still encoded,
	 * with an empty list of filters left to apply. Silently wrong is worse than unsupported.
	 *
	 * @param	array		$dict			Stream dictionary
	 * @param	string		$stream			Raw stream
	 * @return	string|null					Decoded content, null when a filter cannot be undone
	 */
	private function applyFilters($dict, $stream)
	{
		$filters = array();
		if (isset($dict['/Filter'])) {
			$filter = $dict['/Filter'];
			if ($filter[0] == PDF_TYPE_OBJREF) {
				$resolved = $this->getObjectVal($filter);
				$filter = ($resolved[0] == PDF_TYPE_OBJECT) ? $resolved[1] : $resolved;
			}
			if ($filter[0] == PDF_TYPE_TOKEN) {
				$filters[] = $filter[1];
			} elseif ($filter[0] == PDF_TYPE_ARRAY) {
				foreach ($filter[1] as $one) {
					if ($one[0] == PDF_TYPE_TOKEN) {
						$filters[] = $one[1];
					}
				}
			}
		}

		$available = TCPDF_FILTERS::getAvailableFilters();
		foreach ($filters as $filter) {
			if ($filter === 'Crypt' || !in_array($filter, $available)) {
				return null;							// an encrypted or exotic stream is not ours to read
			}
			$stream = TCPDF_FILTERS::decodeFilter($filter, $stream);
			if (!is_string($stream)) {
				return null;
			}
		}

		return $stream;
	}

	/**
	 * Turn a PDF text string into UTF-8.
	 *
	 * @param	int			$type			PDF_TYPE_STRING or PDF_TYPE_HEX
	 * @param	string		$value			Raw value, as tcpdi_parser returned it
	 * @return	string						Decoded name, empty when nothing could be decoded
	 */
	private function decodeText($type, $value)
	{
		if ($type == PDF_TYPE_HEX) {
			$hex = preg_replace('/[^0-9A-Fa-f]/', '', $value);
			if (strlen($hex) % 2) {
				$hex .= '0';							// an odd hexadecimal string is padded with a zero
			}
			$value = pack('H*', $hex);
		} else {
			$value = $this->unescapeLiteral($value);
		}

		if (strncmp($value, "\xFE\xFF", 2) !== 0) {		// the byte order mark of a UTF-16BE text string
			return $value;
		}

		$utf16 = substr($value, 2);
		$utf8 = '';
		for ($i = 0, $count = strlen($utf16) - 1; $i < $count; $i += 2) {
			$code = (ord($utf16[$i]) << 8) | ord($utf16[$i + 1]);
			if ($code < 0x80) {
				$utf8 .= chr($code);
			} elseif ($code < 0x800) {
				$utf8 .= chr(0xC0 | ($code >> 6)).chr(0x80 | ($code & 0x3F));
			} else {
				$utf8 .= chr(0xE0 | ($code >> 12)).chr(0x80 | (($code >> 6) & 0x3F)).chr(0x80 | ($code & 0x3F));
			}
		}

		return $utf8;
	}

	/**
	 * Undo the escape sequences of a PDF literal string.
	 *
	 * @param	string		$value			Content of the string, parentheses excluded
	 * @return	string						Decoded bytes
	 */
	private function unescapeLiteral($value)
	{
		if (strpos($value, '\\') === false) {
			return $value;
		}

		$named = array('n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'f' => "\x0C", '(' => '(', ')' => ')', '\\' => '\\');
		$out = '';
		for ($i = 0, $count = strlen($value); $i < $count; $i++) {
			if ($value[$i] !== '\\' || $i + 1 >= $count) {
				$out .= $value[$i];
				continue;
			}

			$next = $value[++$i];
			if (isset($named[$next])) {
				$out .= $named[$next];
			} elseif ($next >= '0' && $next <= '7') {
				$octal = $next;
				while (strlen($octal) < 3 && isset($value[$i + 1]) && $value[$i + 1] >= '0' && $value[$i + 1] <= '7') {
					$octal .= $value[++$i];
				}
				$out .= chr(octdec($octal) & 0xFF);
			} elseif ($next === "\n") {
				continue;								// a backslash at end of line continues the string
			} elseif ($next === "\r") {
				if (isset($value[$i + 1]) && $value[$i + 1] === "\n") {
					$i++;
				}
			} else {
				$out .= $next;							// before anything else the backslash is dropped
			}
		}

		return $out;
	}

	/**
	 * Resolve an element down to a dictionary, following one indirect reference.
	 *
	 * @param	array|null	$element		Element
	 * @return	array|null					Content of the dictionary, null when it is not one
	 */
	private function dictOf($element)
	{
		if (!is_array($element)) {
			return null;
		}
		if ($element[0] == PDF_TYPE_OBJREF) {
			$object = $this->getObjectVal($element);
			$element = ($object[0] == PDF_TYPE_STREAM || $object[0] == PDF_TYPE_OBJECT) ? $object[1] : $object;
		}

		return (is_array($element) && $element[0] == PDF_TYPE_DICTIONARY) ? $element[1] : null;
	}

	/**
	 * Resolve an element down to an array, following one indirect reference.
	 *
	 * @param	array|null	$element		Element
	 * @return	array						Content of the array, empty when it is not one
	 */
	private function arrayOf($element)
	{
		if (!is_array($element)) {
			return array();
		}
		if ($element[0] == PDF_TYPE_OBJREF) {
			$object = $this->getObjectVal($element);
			$element = ($object[0] == PDF_TYPE_OBJECT) ? $object[1] : $object;
		}

		return (is_array($element) && $element[0] == PDF_TYPE_ARRAY) ? $element[1] : array();
	}
}
