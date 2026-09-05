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
 * \file    einvoicing/scripts/build_function_map.php
 * \ingroup einvoicing
 * \brief   Build doc/FUNCTION-MAP.md and doc/function-map.html from doc/function-map.data.json.
 *
 * The JSON file is the single source of the narrative. Everything that can be read from the code
 * is read from the code at build time: the file and line of every referenced symbol, the options
 * the module actually uses, the trigger actions, the flow types and the SQL tables. A symbol that
 * moved is followed silently; a symbol that disappeared is reported instead of quietly lying.
 *
 * Usage, from anywhere:
 *   php einvoicing/scripts/build_function_map.php            write both documents
 *   php einvoicing/scripts/build_function_map.php --check    write nothing, exit 1 if out of date
 *   php einvoicing/scripts/build_function_map.php --report   drift report only
 */

if (PHP_SAPI !== 'cli') {
	print "This script must be run from the command line.\n";
	exit(1);
}

define('FNMAP_MODULE_DIR', dirname(__DIR__));
define('FNMAP_DATA_FILE', FNMAP_MODULE_DIR.'/doc/function-map.data.json');
define('FNMAP_MD_FILE', FNMAP_MODULE_DIR.'/doc/FUNCTION-MAP.md');
define('FNMAP_HTML_FILE', FNMAP_MODULE_DIR.'/doc/function-map.html');
define('FNMAP_BOX_WIDTH', 96);


/**
 * Read every PHP file of the module and index what can be located in it.
 *
 * @param	string	$moduledir	Absolute path of the module directory
 * @return	array{classes:array<string,string>,functions:array<string,array{file:string,line:int}>,methods:array<string,array{file:string,line:int}>,files:string[]}	Index of the sources
 */
function fnmapIndexSources($moduledir)
{
	$index = array('classes' => array(), 'functions' => array(), 'methods' => array(), 'files' => array());

	$directory = new RecursiveDirectoryIterator($moduledir, FilesystemIterator::SKIP_DOTS);
	$filter = new RecursiveCallbackFilterIterator($directory, 'fnmapKeepPath');
	$iterator = new RecursiveIteratorIterator($filter);

	foreach ($iterator as $fileinfo) {
		$path = $fileinfo->getPathname();
		if (substr($path, -4) !== '.php' && substr($path, -4) !== '.inc') {
			if (substr($path, -8) !== '.inc.php') {
				continue;
			}
		}

		$relative = fnmapRelativePath($path, $moduledir);
		$index['files'][] = $relative;

		$lines = file($path, FILE_IGNORE_NEW_LINES);
		if ($lines === false) {
			continue;
		}

		$currentclass = '';
		foreach ($lines as $number => $content) {
			$matches = array();
			if (preg_match('/^\s*(?:abstract\s+|final\s+)*(?:class|trait|interface)\s+([A-Za-z_][A-Za-z0-9_]*)/', $content, $matches)) {
				$currentclass = $matches[1];
				$index['classes'][$currentclass] = $relative;
				continue;
			}
			if (preg_match('/^\s*(?:(?:public|protected|private|static|abstract|final)\s+)*function\s+&?([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $content, $matches)) {
				$position = array('file' => $relative, 'line' => $number + 1);
				if ($currentclass !== '') {
					$key = $currentclass.'::'.$matches[1];
					if (!isset($index['methods'][$key])) {
						$index['methods'][$key] = $position;
					}
				} elseif (!isset($index['functions'][$matches[1]])) {
					$index['functions'][$matches[1]] = $position;
				}
			}
		}
	}

	return $index;
}

/**
 * Directories the index must never walk into.
 *
 * @param	SplFileInfo				$current	Current entry
 * @param	string					$key		Iteration key
 * @param	RecursiveDirectoryIterator	$iterator	Iterator being filtered
 * @return	bool								True to keep the entry
 */
function fnmapKeepPath($current, $key, $iterator)
{
	$skipped = array('vendor', 'test', 'doc', 'langs', 'img', 'node_modules');
	if ($current->isDir()) {
		return !in_array($current->getFilename(), $skipped, true);
	}
	return true;
}

/**
 * Turn an absolute path into one relative to the module directory.
 *
 * @param	string	$path		Absolute path
 * @param	string	$moduledir	Absolute path of the module directory
 * @return	string				Relative path, forward slashes
 */
function fnmapRelativePath($path, $moduledir)
{
	$path = str_replace('\\', '/', $path);
	$moduledir = str_replace('\\', '/', $moduledir);
	if (strpos($path, $moduledir) === 0) {
		return ltrim(substr($path, strlen($moduledir)), '/');
	}
	return $path;
}

/**
 * Locate one declared symbol in the sources.
 *
 * @param	string		$symbol		"Class::method", "functionName()" or a relative file path
 * @param	string		$locate		Optional literal to look for inside the resolved file
 * @param	array		$index		Index returned by fnmapIndexSources()
 * @param	string		$moduledir	Absolute path of the module directory
 * @param	string[]	$problems	Drift messages, appended to
 * @return	array{file:string,line:int,found:bool}	Where the symbol lives
 */
function fnmapResolveSymbol($symbol, $locate, $index, $moduledir, &$problems)
{
	$result = array('file' => $symbol, 'line' => 0, 'found' => false);

	if (strpos($symbol, '::') !== false) {
		if (isset($index['methods'][$symbol])) {
			$result = $index['methods'][$symbol];
			$result['found'] = true;
		} else {
			$problems[] = 'symbol not found in the sources: '.$symbol;
		}
	} elseif (substr($symbol, -2) === '()') {
		$name = substr($symbol, 0, -2);
		if (isset($index['functions'][$name])) {
			$result = $index['functions'][$name];
			$result['found'] = true;
		} else {
			$problems[] = 'function not found in the sources: '.$symbol;
		}
	} else {
		$candidate = preg_replace('#^einvoicing/#', '', $symbol);
		if (file_exists($moduledir.'/'.$candidate)) {
			$result = array('file' => $candidate, 'line' => 1, 'found' => true);
		} else {
			$problems[] = 'file not found in the module: '.$symbol;
		}
	}

	if ($result['found'] && $locate !== '') {
		$line = fnmapFindLiteral($moduledir.'/'.$result['file'], $locate, $result['line']);
		if ($line > 0) {
			$result['line'] = $line;
		} else {
			$problems[] = 'literal not found in '.$result['file'].': '.$locate;
		}
	}

	return $result;
}

/**
 * Find the first line of a file holding a literal, at or after a starting line.
 *
 * @param	string	$path		Absolute file path
 * @param	string	$literal	Literal to look for
 * @param	int		$fromline	Line to start from, 1 based
 * @return	int					Line number, 0 when not found
 */
function fnmapFindLiteral($path, $literal, $fromline)
{
	$lines = file($path, FILE_IGNORE_NEW_LINES);
	if ($lines === false) {
		return 0;
	}
	$start = max(0, $fromline - 1);
	$count = count($lines);
	for ($i = $start; $i < $count; $i++) {
		if (strpos($lines[$i], $literal) !== false) {
			return $i + 1;
		}
	}
	return 0;
}

/**
 * Read from the sources the facts the map claims, so a drift can be reported.
 *
 * @param	string	$moduledir	Absolute path of the module directory
 * @param	array	$index		Index returned by fnmapIndexSources()
 * @return	array{options:string[],triggers:string[],flowtypes:string[],tables:string[]}	Facts extracted
 */
function fnmapExtractFacts($moduledir, $index)
{
	$facts = array('options' => array(), 'triggers' => array(), 'flowtypes' => array(), 'tables' => array());

	foreach ($index['files'] as $relative) {
		$content = file_get_contents($moduledir.'/'.$relative);
		if ($content === false) {
			continue;
		}
		$matches = array();
		if (preg_match_all('/getDolGlobal(?:String|Int|Bool)\(\s*[\'"](EINVOICING_[A-Z0-9_]+)[\'"]/', $content, $matches)) {
			foreach ($matches[1] as $option) {
				$facts['options'][$option] = $option;
			}
		}
	}

	$triggerfile = $moduledir.'/core/triggers/interface_98_modEInvoicing_EInvoicingTriggers.class.php';
	if (file_exists($triggerfile)) {
		$matches = array();
		if (preg_match_all('/\$action\s*==\s*\'([A-Z_]+)\'/', file_get_contents($triggerfile), $matches)) {
			$facts['triggers'] = array_values(array_unique($matches[1]));
		}
	}

	$providerfile = $moduledir.'/class/providers/SuperPDPProvider.class.php';
	if (file_exists($providerfile)) {
		$matches = array();
		if (preg_match_all('/^\s*case "([A-Za-z]*)":/m', file_get_contents($providerfile), $matches)) {
			$facts['flowtypes'] = array_values(array_unique($matches[1]));
		}
	}

	foreach (glob($moduledir.'/sql/llx_einvoicing_*.sql') as $sqlfile) {
		if (strpos($sqlfile, '.key.sql') !== false) {
			continue;
		}
		$facts['tables'][] = basename($sqlfile, '.sql');
	}

	ksort($facts['options']);
	sort($facts['tables']);

	return $facts;
}

/**
 * Compare what the map says with what the code holds.
 *
 * @param	array		$data		Decoded data file
 * @param	array		$facts		Facts returned by fnmapExtractFacts()
 * @param	string[]	$problems	Drift messages, appended to
 * @return	void
 */
function fnmapReportDrift($data, $facts, &$problems)
{
	$documented = array();
	foreach ($data['sections'] as $section) {
		foreach ($section['blocks'] as $block) {
			if ($block['type'] !== 'table') {
				continue;
			}
			foreach ($block['rows'] as $row) {
				$matches = array();
				if (preg_match_all('/EINVOICING_[A-Z0-9_]+/', implode(' ', $row), $matches)) {
					foreach ($matches[0] as $option) {
						$documented[$option] = $option;
					}
				}
			}
		}
	}

	// Only the options really read through getDolGlobal*() are compared: a setup label or a
	// translation key is not an option. An undocumented one is a hint, never an error.
	$ignored = isset($data['meta']['ignoredOptions']) ? $data['meta']['ignoredOptions'] : array();
	$missing = array();
	foreach (array_keys($facts['options']) as $option) {
		if (!isset($documented[$option]) && !fnmapIsIgnoredOption($option, $ignored)) {
			$missing[] = $option;
		}
	}
	if (count($missing) > 0) {
		$problems[] = 'options used in the code and absent from the options table: '.implode(', ', $missing);
	}
	$unknown = array_diff(array_keys($documented), array_keys($facts['options']));
	if (count($unknown) > 0) {
		$problems[] = 'options documented but never read in the code: '.implode(', ', $unknown);
	}

	foreach ($facts['tables'] as $table) {
		if (strpos(json_encode($data), $table) === false) {
			$problems[] = 'table present in sql/ and absent from the map: '.$table;
		}
	}
}

/**
 * Whether an option is deliberately out of the map: a credential, a provider key, a screen setting.
 *
 * @param	string		$option		Option name
 * @param	string[]	$patterns	Patterns of the data file, a trailing star meaning "starts with"
 * @return	bool					True when the option must not be reported
 */
function fnmapIsIgnoredOption($option, $patterns)
{
	foreach ($patterns as $pattern) {
		if (substr($pattern, -1) === '*') {
			if (strpos($option, substr($pattern, 0, -1)) === 0) {
				return true;
			}
		} elseif ($option === $pattern) {
			return true;
		}
	}
	return false;
}

/**
 * Version and commit the generated documents are stamped with.
 *
 * @param	string	$moduledir	Absolute path of the module directory
 * @return	string				One line stamp
 */
function fnmapStamp($moduledir)
{
	$version = 'unknown';
	if (file_exists($moduledir.'/VERSION')) {
		$version = trim(file_get_contents($moduledir.'/VERSION'));
	}
	$commit = trim((string) @shell_exec('git -C '.escapeshellarg($moduledir).' rev-parse --short HEAD 2>/dev/null'));
	$stamp = 'module '.$version;
	if ($commit !== '') {
		$stamp .= ', commit '.$commit;
	}
	return $stamp.', generated '.date('Y-m-d');
}


/*
 * Markdown rendering
 */

/**
 * Draw one ASCII box.
 *
 * @param	string		$title		Box title
 * @param	string[]	$lines		Body lines
 * @param	int			$width		Inner width
 * @return	non-empty-list<string>	Rendered lines: the top border, the title, the body, the bottom border
 */
function fnmapAsciiBox($title, $lines, $width)
{
	$out = array();
	$out[] = '  +'.str_repeat('-', $width + 2).'+';
	$out[] = '  | '.fnmapPad($title, $width).' |';
	foreach ($lines as $line) {
		$out[] = '  | '.fnmapPad('  '.$line, $width).' |';
	}
	$out[] = '  +'.str_repeat('-', $width + 2).'+';
	return $out;
}

/**
 * Pad a text to a fixed width, counting characters and not bytes.
 *
 * @param	string	$text	Text
 * @param	int		$width	Total width
 * @return	string			Padded line
 */
function fnmapPad($text, $width)
{
	$length = fnmapLength($text);
	if ($length > $width) {
		return fnmapClip($text, $width);
	}
	return $text.str_repeat(' ', $width - $length);
}

/**
 * Character length of a text, whatever its accents.
 *
 * @param	string	$text	Text
 * @return	int				Length in characters
 */
function fnmapLength($text)
{
	// Characters, never bytes: a byte count shifts every box holding an accent or a dash. PCRE in
	// UTF-8 mode is always there, unlike mbstring, so it is the only path - and the only one tested.
	// It returns false on a text that is not valid UTF-8, where a byte count is the best guess left.
	$length = preg_match_all('/./u', $text);
	return $length === false ? strlen($text) : $length;
}

/**
 * Cut a text that would overflow a box.
 *
 * @param	string	$text	Text
 * @param	int		$width	Maximum width
 * @return	string			Text, clipped
 */
function fnmapClip($text, $width)
{
	if (fnmapLength($text) <= $width) {
		return $text;
	}
	$characters = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
	if ($characters === false) {
		return substr($text, 0, $width - 3).'...';
	}
	return implode('', array_slice($characters, 0, $width - 3)).'...';
}

/**
 * Body lines of one node: its own lines, then the source reference.
 *
 * @param	array		$node		Node or branch of a diagram
 * @param	?array		$function	Resolved function definition, or null
 * @return	string[]				Lines to draw inside the box
 */
function fnmapNodeLines($node, $function)
{
	$lines = isset($node['lines']) ? $node['lines'] : array();
	if ($function !== null) {
		$reference = fnmapSourceRef($function);
		if ($reference !== '') {
			$lines[] = '@ '.$reference;
		}
	}
	return $lines;
}

/**
 * Width every box of one diagram is drawn at: the longest line it holds, capped.
 *
 * @param	array	$diagram	Diagram block
 * @param	array	$functions	Function definitions, already resolved
 * @return	int					Inner width
 */
function fnmapDiagramWidth($diagram, $functions)
{
	$width = 40;
	foreach ($diagram['steps'] as $step) {
		$candidates = array();
		if (isset($step['label'])) {
			$candidates[] = $step['label'];
		}
		if (isset($step['items'])) {
			foreach ($step['items'] as $item) {
				$candidates[] = '  '.$item;
			}
		}
		$function = (isset($step['fn']) && isset($functions[$step['fn']])) ? $functions[$step['fn']] : null;
		foreach (fnmapNodeLines($step, $function) as $line) {
			$candidates[] = '  '.$line;
		}
		if (isset($step['branches'])) {
			foreach ($step['branches'] as $branch) {
				$candidates[] = '  * '.$branch['label'];
				$sub = (isset($branch['fn']) && isset($functions[$branch['fn']])) ? $functions[$branch['fn']] : null;
				foreach (fnmapNodeLines($branch, $sub) as $line) {
					$candidates[] = '      '.$line;
				}
			}
		}
		foreach ($candidates as $candidate) {
			$width = max($width, fnmapLength($candidate));
		}
	}
	return min($width, FNMAP_BOX_WIDTH);
}

/**
 * Render one diagram as ASCII art.
 *
 * @param	array	$diagram	Diagram block
 * @param	array	$functions	Function definitions, already resolved
 * @return	string				Fenced code block
 */
function fnmapRenderDiagramMd($diagram, $functions)
{
	$out = array();
	$width = fnmapDiagramWidth($diagram, $functions);
	$previouswasarrow = true;

	foreach ($diagram['steps'] as $position => $step) {
		if ($position > 0 && !$previouswasarrow && $step['kind'] !== 'arrow') {
			$out[] = '                |';
			$out[] = '                v';
		}
		$previouswasarrow = false;

		if ($step['kind'] === 'event') {
			$out[] = '  '.$step['label'];
			continue;
		}
		if ($step['kind'] === 'arrow') {
			$out[] = '                |';
			if (isset($step['label']) && $step['label'] !== '') {
				$out[] = '                |  '.$step['label'];
			}
			$out[] = '                v';
			$previouswasarrow = true;
			continue;
		}
		if ($step['kind'] === 'list') {
			$out = array_merge($out, fnmapAsciiBox($step['label'], $step['items'], $width));
			continue;
		}
		if ($step['kind'] === 'branch') {
			$body = array();
			foreach ($step['branches'] as $branch) {
				$body[] = '';
				$body[] = '* '.$branch['label'];
				$sub = (isset($branch['fn']) && isset($functions[$branch['fn']])) ? $functions[$branch['fn']] : null;
				foreach (fnmapNodeLines($branch, $sub) as $line) {
					$body[] = '    '.$line;
				}
			}
			$out = array_merge($out, fnmapAsciiBox($step['label'], $body, $width));
			continue;
		}

		$function = (isset($step['fn']) && isset($functions[$step['fn']])) ? $functions[$step['fn']] : null;
		$label = (isset($step['label']) && $step['label'] !== '') ? $step['label'] : ($function ? $function['label'] : '');
		$out = array_merge($out, fnmapAsciiBox($label, fnmapNodeLines($step, $function), $width));
	}

	return "```\n".implode("\n", $out)."\n```";
}

/**
 * Short "file:line" reference of a resolved function.
 *
 * @param	array	$function	Resolved function definition
 * @return	string				Reference, or an empty string
 */
function fnmapSourceRef($function)
{
	if (empty($function['position']['found'])) {
		return '';
	}
	return $function['position']['file'].':'.$function['position']['line'];
}

/**
 * Render the whole markdown document.
 *
 * @param	array	$data		Decoded data file
 * @param	array	$functions	Function definitions, already resolved
 * @param	string	$stamp		Generation stamp
 * @return	string				Markdown
 */
function fnmapRenderMarkdown($data, $functions, $stamp)
{
	$out = array();
	$out[] = '# '.$data['meta']['title'];
	$out[] = '';
	$out[] = '<!-- Generated by scripts/build_function_map.php from doc/function-map.data.json. Do not edit by hand. -->';
	$out[] = '';
	$out[] = '*'.$stamp.'. Clickable version: [function-map.html](function-map.html).*';
	$out[] = '';
	foreach ($data['meta']['intro'] as $paragraph) {
		$out[] = $paragraph;
		$out[] = '';
	}
	$out[] = '---';
	$out[] = '';

	foreach ($data['sections'] as $number => $section) {
		$out[] = '## '.($number + 1).'. '.$section['title'];
		$out[] = '';
		foreach ($section['blocks'] as $block) {
			$out = array_merge($out, fnmapRenderBlockMd($block, $functions));
		}
		$out[] = '---';
		$out[] = '';
	}

	array_pop($out);
	array_pop($out);

	return rtrim(implode("\n", $out), "\n")."\n";
}

/**
 * Render one block as markdown.
 *
 * @param	array	$block		Block of a section
 * @param	array	$functions	Function definitions, already resolved
 * @return	string[]			Markdown lines
 */
function fnmapRenderBlockMd($block, $functions)
{
	$out = array();

	if ($block['type'] === 'prose') {
		foreach ($block['md'] as $line) {
			$out[] = $line;
			$out[] = '';
		}
		return $out;
	}
	if ($block['type'] === 'note') {
		foreach ($block['md'] as $line) {
			$out[] = '> '.$line;
		}
		$out[] = '';
		return $out;
	}
	if ($block['type'] === 'diagram') {
		if (!empty($block['title'])) {
			$out[] = '### '.$block['title'];
			$out[] = '';
		}
		$out[] = fnmapRenderDiagramMd($block, $functions);
		$out[] = '';
		return $out;
	}
	if ($block['type'] === 'table') {
		if (!empty($block['caption'])) {
			$out[] = '### '.$block['caption'];
			$out[] = '';
		}
		$out[] = '| '.implode(' | ', $block['columns']).' |';
		$out[] = '|'.str_repeat('---|', count($block['columns']));
		foreach ($block['rows'] as $row) {
			$out[] = '| '.implode(' | ', $row).' |';
		}
		$out[] = '';
		return $out;
	}
	if ($block['type'] === 'fnTable') {
		if (!empty($block['caption'])) {
			$out[] = '### '.$block['caption'];
			$out[] = '';
		}
		$out[] = '| Function | Source | Role | Reads | Writes / core action |';
		$out[] = '|---|---|---|---|---|';
		foreach ($block['keys'] as $key) {
			if (!isset($functions[$key])) {
				continue;
			}
			$function = $functions[$key];
			$reference = fnmapSourceRef($function);
			$out[] = '| `'.$function['label'].'` | '.($reference === '' ? '*missing*' : '`'.$reference.'`').' | '.$function['role'].' | '.$function['reads'].' | '.$function['writes'].' |';
		}
		$out[] = '';
		return $out;
	}

	return $out;
}


/*
 * HTML rendering
 */

/**
 * Escape a value for HTML output.
 *
 * @param	string	$text	Raw text
 * @return	string			Escaped text
 */
function fnmapEscape($text)
{
	return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

/**
 * Render the small subset of markdown the data file uses inside a cell or a paragraph.
 *
 * @param	string	$text	Markdown fragment
 * @return	string			HTML fragment
 */
function fnmapInlineMd($text)
{
	$html = fnmapEscape($text);
	$html = preg_replace('/`([^`]+)`/', '<code>$1</code>', $html);
	$html = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $html);
	$html = preg_replace('/(?<!\*)\*([^*]+)\*(?!\*)/', '<em>$1</em>', $html);
	return $html;
}

/**
 * Render a paragraph of the data file, which may be a heading or a bullet list.
 *
 * @param	string[]	$paragraphs		Markdown paragraphs
 * @return	string						HTML
 */
function fnmapProseHtml($paragraphs)
{
	$html = '';
	$bullets = array();

	foreach ($paragraphs as $paragraph) {
		if (strpos($paragraph, '* ') === 0) {
			$bullets[] = '<li>'.fnmapInlineMd(substr($paragraph, 2)).'</li>';
			continue;
		}
		if (count($bullets) > 0) {
			$html .= '<ul>'.implode('', $bullets).'</ul>';
			$bullets = array();
		}
		if (strpos($paragraph, '### ') === 0) {
			$html .= '<h3>'.fnmapInlineMd(substr($paragraph, 4)).'</h3>';
			continue;
		}
		$html .= '<p>'.fnmapInlineMd($paragraph).'</p>';
	}
	if (count($bullets) > 0) {
		$html .= '<ul>'.implode('', $bullets).'</ul>';
	}

	return $html;
}

/**
 * Absolute URL of a resolved symbol, on the repository browser.
 *
 * @param	array	$function	Resolved function definition
 * @param	string	$baseurl	Base URL of the module on the repository browser
 * @return	string				URL, or an empty string
 */
function fnmapSourceUrl($function, $baseurl)
{
	if (empty($function['position']['found'])) {
		return '';
	}
	return $baseurl.$function['position']['file'].'#L'.$function['position']['line'];
}

/**
 * Render one diagram as clickable HTML.
 *
 * @param	array	$diagram	Diagram block
 * @param	array	$functions	Function definitions, already resolved
 * @param	string	$baseurl	Base URL of the module on the repository browser
 * @return	string				HTML
 */
function fnmapRenderDiagramHtml($diagram, $functions, $baseurl)
{
	$html = '';
	if (!empty($diagram['title'])) {
		$html .= '<h3>'.fnmapEscape($diagram['title']).'</h3>';
	}
	$html .= '<ol class="flow">';

	foreach ($diagram['steps'] as $step) {
		if ($step['kind'] === 'event') {
			$html .= '<li class="step step-event">'.fnmapInlineMd($step['label']).'</li>';
			continue;
		}
		if ($step['kind'] === 'arrow') {
			$label = isset($step['label']) ? $step['label'] : '';
			$html .= '<li class="step step-arrow">'.($label === '' ? '&nbsp;' : fnmapInlineMd($label)).'</li>';
			continue;
		}
		if ($step['kind'] === 'list') {
			$html .= '<li class="step"><div class="card card-list"><div class="card-head">'.fnmapInlineMd($step['label']).'</div><ul>';
			foreach ($step['items'] as $item) {
				$html .= '<li>'.fnmapInlineMd($item).'</li>';
			}
			$html .= '</ul></div></li>';
			continue;
		}
		if ($step['kind'] === 'branch') {
			$html .= '<li class="step"><div class="branch"><div class="branch-head">'.fnmapInlineMd($step['label']).'</div><div class="branch-grid">';
			foreach ($step['branches'] as $branch) {
				$html .= fnmapCardHtml($branch, $functions, $baseurl, 'branch-card');
			}
			$html .= '</div></div></li>';
			continue;
		}
		$html .= '<li class="step">'.fnmapCardHtml($step, $functions, $baseurl, '').'</li>';
	}

	$html .= '</ol>';
	return $html;
}

/**
 * Render one node of a diagram, clickable when it points at a known function.
 *
 * @param	array	$node		Node or branch
 * @param	array	$functions	Function definitions, already resolved
 * @param	string	$baseurl	Base URL of the module on the repository browser
 * @param	string	$extracss	Extra class of the card
 * @return	string				HTML
 */
function fnmapCardHtml($node, $functions, $baseurl, $extracss)
{
	$key = isset($node['fn']) ? $node['fn'] : '';
	$function = ($key !== '' && isset($functions[$key])) ? $functions[$key] : null;

	$label = isset($node['label']) && $node['label'] !== '' ? $node['label'] : ($function ? $function['label'] : '');
	$lines = isset($node['lines']) ? $node['lines'] : array();

	$classes = 'card '.$extracss;
	$attributes = '';
	if ($function !== null) {
		$classes .= ' card-clickable';
		$attributes = ' data-fn="'.fnmapEscape($key).'" tabindex="0" role="button"';
	}

	$html = '<div class="'.trim($classes).'"'.$attributes.'>';
	$html .= '<div class="card-head">'.fnmapInlineMd($label).'</div>';
	if (count($lines) > 0) {
		$html .= '<ul class="card-lines">';
		foreach ($lines as $line) {
			$html .= '<li>'.fnmapInlineMd($line).'</li>';
		}
		$html .= '</ul>';
	}
	if ($function !== null) {
		$reference = fnmapSourceRef($function);
		if ($reference !== '') {
			$html .= '<div class="card-src"><a href="'.fnmapEscape(fnmapSourceUrl($function, $baseurl)).'" target="_blank" rel="noopener">'.fnmapEscape($reference).'</a></div>';
		}
	}
	$html .= '</div>';

	return $html;
}

/**
 * Render one block as HTML.
 *
 * @param	array	$block		Block of a section
 * @param	array	$functions	Function definitions, already resolved
 * @param	string	$baseurl	Base URL of the module on the repository browser
 * @return	string				HTML
 */
function fnmapRenderBlockHtml($block, $functions, $baseurl)
{
	if ($block['type'] === 'prose') {
		return fnmapProseHtml($block['md']);
	}
	if ($block['type'] === 'note') {
		return '<aside class="note">'.fnmapProseHtml($block['md']).'</aside>';
	}
	if ($block['type'] === 'diagram') {
		return fnmapRenderDiagramHtml($block, $functions, $baseurl);
	}
	if ($block['type'] === 'table') {
		$html = '';
		if (!empty($block['caption'])) {
			$html .= '<h3>'.fnmapEscape($block['caption']).'</h3>';
		}
		$html .= '<div class="tablewrap"><table><thead><tr>';
		foreach ($block['columns'] as $column) {
			$html .= '<th>'.fnmapInlineMd($column).'</th>';
		}
		$html .= '</tr></thead><tbody>';
		foreach ($block['rows'] as $row) {
			$html .= '<tr class="searchable">';
			foreach ($row as $cell) {
				$html .= '<td>'.fnmapInlineMd($cell).'</td>';
			}
			$html .= '</tr>';
		}
		$html .= '</tbody></table></div>';
		return $html;
	}
	if ($block['type'] === 'fnTable') {
		$html = '';
		if (!empty($block['caption'])) {
			$html .= '<h3>'.fnmapEscape($block['caption']).'</h3>';
		}
		$html .= '<div class="tablewrap"><table class="fntable"><thead><tr><th>Function</th><th>Source</th><th>Role</th><th>Reads</th><th>Writes / core action</th></tr></thead><tbody>';
		foreach ($block['keys'] as $key) {
			if (!isset($functions[$key])) {
				continue;
			}
			$function = $functions[$key];
			$reference = fnmapSourceRef($function);
			$source = $reference === ''
				? '<span class="missing">missing</span>'
				: '<a href="'.fnmapEscape(fnmapSourceUrl($function, $baseurl)).'" target="_blank" rel="noopener"><code>'.fnmapEscape($reference).'</code></a>';
			$html .= '<tr class="searchable" id="fn-'.fnmapEscape($key).'">';
			$html .= '<td><code>'.fnmapEscape($function['label']).'</code></td>';
			$html .= '<td>'.$source.'</td>';
			$html .= '<td>'.fnmapInlineMd($function['role']).'</td>';
			$html .= '<td>'.fnmapInlineMd($function['reads']).'</td>';
			$html .= '<td>'.fnmapInlineMd($function['writes']).'</td>';
			$html .= '</tr>';
		}
		$html .= '</tbody></table></div>';
		return $html;
	}

	return '';
}

/**
 * Render the whole HTML document.
 *
 * @param	array	$data		Decoded data file
 * @param	array	$functions	Function definitions, already resolved
 * @param	string	$stamp		Generation stamp
 * @return	string				HTML
 */
function fnmapRenderHtml($data, $functions, $stamp)
{
	$baseurl = $data['meta']['sourceBaseUrl'];

	$payload = array();
	foreach ($functions as $key => $function) {
		$payload[$key] = array(
			'label' => $function['label'],
			'role' => $function['role'],
			'reads' => $function['reads'],
			'writes' => $function['writes'],
			'chain' => $function['chain'],
			'src' => fnmapSourceRef($function),
			'url' => fnmapSourceUrl($function, $baseurl)
		);
	}

	$navigation = '';
	$body = '';
	foreach ($data['sections'] as $number => $section) {
		$title = ($number + 1).'. '.$section['title'];
		$navigation .= '<a href="#'.fnmapEscape($section['id']).'">'.fnmapEscape($title).'</a>';
		$body .= '<section id="'.fnmapEscape($section['id']).'"><h2>'.fnmapEscape($title).'</h2>';
		foreach ($section['blocks'] as $block) {
			$body .= fnmapRenderBlockHtml($block, $functions, $baseurl);
		}
		$body .= '</section>';
	}

	$intro = fnmapProseHtml($data['meta']['intro']);
	$json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

	$html = "<!DOCTYPE html>\n";
	$html .= '<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
	$html .= '<title>'.fnmapEscape($data['meta']['title']).'</title>';
	$html .= '<style>'.fnmapStyles().'</style></head><body>';
	$html .= '<!-- Generated by scripts/build_function_map.php from doc/function-map.data.json. Do not edit by hand. -->';
	$html .= '<header><h1>'.fnmapEscape($data['meta']['title']).'</h1>';
	$html .= '<p class="stamp">'.fnmapEscape($stamp).' &middot; <a href="FUNCTION-MAP.md">markdown version</a> &middot; <a href="LIFECYCLE-STATUSES.md">lifecycle statuses</a></p>';
	$html .= '<input type="search" id="filter" placeholder="Filter the tables (function, table, option, BT...)" autocomplete="off">';
	$html .= '</header>';
	$html .= '<nav>'.$navigation.'</nav>';
	$html .= '<main><div class="intro">'.$intro.'</div>'.$body.'</main>';
	$html .= '<aside id="drawer" hidden><button id="drawer-close" aria-label="Close">&times;</button><div id="drawer-body"></div></aside>';
	$html .= '<script>var FNMAP='.$json.';'.fnmapScript().'</script>';
	$html .= '</body></html>';

	return $html."\n";
}

/**
 * Stylesheet of the generated page.
 *
 * @return	string	CSS
 */
function fnmapStyles()
{
	return <<<'CSS'
:root{--bg:#fbfaf8;--fg:#1e2126;--muted:#6a7079;--line:#e0dcd5;--card:#fff;--accent:#8a5a2b;--accent-bg:#f6efe6;--code:#f2efe9;}
@media (prefers-color-scheme:dark){:root{--bg:#16181c;--fg:#e6e4e0;--muted:#9aa0a8;--line:#2c3037;--card:#1e2126;--accent:#d9a76a;--accent-bg:#26221c;--code:#232730;}}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--fg);font:15px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif}
header{padding:28px 24px 16px;border-bottom:1px solid var(--line)}
h1{margin:0 0 6px;font-size:24px;letter-spacing:-.01em}
h2{margin:38px 0 14px;font-size:19px;padding-bottom:6px;border-bottom:1px solid var(--line)}
h3{margin:26px 0 10px;font-size:15px;color:var(--accent);text-transform:uppercase;letter-spacing:.05em}
p{margin:10px 0}
.stamp{color:var(--muted);font-size:13px;margin:0 0 12px}
a{color:var(--accent)}
code{background:var(--code);padding:1px 5px;border-radius:4px;font:12.5px/1.5 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}
#filter{width:100%;max-width:460px;padding:8px 12px;border:1px solid var(--line);border-radius:8px;background:var(--card);color:var(--fg);font-size:14px}
nav{position:sticky;top:0;z-index:5;display:flex;gap:4px;flex-wrap:wrap;padding:10px 24px;background:var(--bg);border-bottom:1px solid var(--line)}
nav a{padding:4px 10px;border-radius:999px;font-size:13px;text-decoration:none;border:1px solid var(--line)}
nav a:hover{background:var(--accent-bg)}
main{max-width:1360px;margin:0 auto;padding:0 24px 90px}
.intro{padding:18px 0;border-bottom:1px solid var(--line)}
.note{border-left:3px solid var(--accent);background:var(--accent-bg);padding:2px 16px;margin:16px 0;border-radius:0 6px 6px 0}
.note p{margin:10px 0}
.tablewrap{overflow-x:auto;margin:14px 0}
table{border-collapse:collapse;width:100%;font-size:13.5px}
th,td{border:1px solid var(--line);padding:7px 10px;text-align:left;vertical-align:top}
th{background:var(--accent-bg);font-weight:600;white-space:nowrap}
tbody tr:hover{background:var(--accent-bg)}
.fntable td:first-child{white-space:nowrap}
.missing{color:#c0392b;font-weight:600}
.flow{list-style:none;margin:18px 0;padding:0;display:flex;flex-direction:column;align-items:stretch}
.step{position:relative;margin:0}
.step+.step{margin-top:26px}
.step+.step::before{content:"";position:absolute;top:-24px;left:50%;width:2px;height:22px;background:var(--line)}
.step+.step::after{content:"";position:absolute;top:-8px;left:50%;margin-left:-4px;border:4px solid transparent;border-top-color:var(--line)}
.step-arrow{text-align:center;color:var(--muted);font-size:13px;font-style:italic;padding:2px 0}
.step-event{text-align:center;color:var(--muted);font-size:13.5px;padding:4px 0}
.card{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:12px 14px}
.card-head{font-weight:600;font-size:14px}
.card-lines{margin:8px 0 0;padding-left:18px;color:var(--muted);font-size:13px}
.card-lines li{margin:2px 0}
.card-list ul{margin:8px 0 0;padding-left:18px;font-size:13.5px}
.card-src{margin-top:8px;font-size:12px}
.card-src a{text-decoration:none;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}
.card-clickable{cursor:pointer;border-left:3px solid var(--accent)}
.card-clickable:hover,.card-clickable:focus{background:var(--accent-bg);outline:none;box-shadow:0 0 0 2px var(--accent-bg)}
.branch{border:1px dashed var(--line);border-radius:12px;padding:12px}
.branch-head{font-weight:600;font-size:13px;color:var(--muted);text-align:center;margin-bottom:10px}
.branch-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:10px}
.branch-card{font-size:13px}
#drawer{position:fixed;right:0;top:0;bottom:0;width:min(430px,92vw);background:var(--card);border-left:1px solid var(--line);box-shadow:-8px 0 28px rgba(0,0,0,.16);padding:22px;overflow-y:auto;z-index:20}
#drawer h4{margin:0 0 4px;font-size:16px}
#drawer dt{font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--accent);margin-top:14px}
#drawer dd{margin:4px 0 0}
#drawer-close{position:absolute;right:12px;top:10px;border:0;background:none;color:var(--muted);font-size:26px;line-height:1;cursor:pointer}
.hiddenrow{display:none}
@media print{nav,#filter,#drawer{display:none}.tablewrap{overflow:visible}}
CSS;
}

/**
 * Behaviour of the generated page: the drawer and the filter.
 *
 * @return	string	Javascript
 */
function fnmapScript()
{
	return <<<'JS'
(function(){
var drawer=document.getElementById('drawer'),body=document.getElementById('drawer-body');
function open(key){var f=FNMAP[key];if(!f)return;
var src=f.src?'<a href="'+f.url+'" target="_blank" rel="noopener"><code>'+f.src+'</code></a>':'<span class="missing">not found in the sources</span>';
body.innerHTML='<h4>'+f.label+'</h4><p><small>'+src+' &middot; chain '+f.chain+'</small></p>'+
'<dl><dt>Role</dt><dd>'+f.role+'</dd><dt>Reads</dt><dd>'+f.reads+'</dd><dt>Writes / core action</dt><dd>'+f.writes+'</dd></dl>'+
(document.getElementById('fn-'+key)?'<p><a href="#fn-'+key+'">see it in the function table</a></p>':'');
drawer.hidden=false;}
document.addEventListener('click',function(e){
if(e.target.closest&&e.target.closest('a')){return;}
var card=e.target.closest?e.target.closest('.card-clickable'):null;
if(card){open(card.getAttribute('data-fn'));return;}
if(e.target.id==='drawer-close'||(drawer.hidden===false&&!drawer.contains(e.target))){drawer.hidden=true;}});
document.addEventListener('keydown',function(e){
if(e.key==='Escape'){drawer.hidden=true;}
if((e.key==='Enter'||e.key===' ')&&e.target.classList&&e.target.classList.contains('card-clickable')){e.preventDefault();open(e.target.getAttribute('data-fn'));}});
var filter=document.getElementById('filter');
filter.addEventListener('input',function(){
var q=filter.value.toLowerCase();
var rows=document.querySelectorAll('tr.searchable');
for(var i=0;i<rows.length;i++){
rows[i].className=(!q||rows[i].textContent.toLowerCase().indexOf(q)>=0)?'searchable':'searchable hiddenrow';}});
})();
JS;
}


/*
 * Main
 */

$mode = 'write';
foreach (array_slice($argv, 1) as $argument) {
	if ($argument === '--check') {
		$mode = 'check';
	} elseif ($argument === '--report') {
		$mode = 'report';
	} else {
		print "Unknown argument: ".$argument."\n";
		exit(2);
	}
}

if (!file_exists(FNMAP_DATA_FILE)) {
	print "Data file not found: ".FNMAP_DATA_FILE."\n";
	exit(2);
}

$data = json_decode(file_get_contents(FNMAP_DATA_FILE), true);
if (!is_array($data)) {
	print "Invalid JSON in ".FNMAP_DATA_FILE.": ".json_last_error_msg()."\n";
	exit(2);
}

$problems = array();
$index = fnmapIndexSources(FNMAP_MODULE_DIR);

$functions = array();
foreach ($data['functions'] as $key => $definition) {
	$definition['position'] = fnmapResolveSymbol(
		$definition['symbol'],
		isset($definition['locate']) ? $definition['locate'] : '',
		$index,
		FNMAP_MODULE_DIR,
		$problems
	);
	$functions[$key] = $definition;
}

$facts = fnmapExtractFacts(FNMAP_MODULE_DIR, $index);
fnmapReportDrift($data, $facts, $problems);

$stamp = fnmapStamp(FNMAP_MODULE_DIR);
$markdown = fnmapRenderMarkdown($data, $functions, $stamp);
$page = fnmapRenderHtml($data, $functions, $stamp);

print "Indexed ".count($index['files'])." source files, ".count($index['methods'])." methods.\n";
print "Resolved ".count($functions)." mapped symbols, ".count($facts['options'])." options, ";
print count($facts['triggers'])." trigger actions, ".count($facts['flowtypes'])." flow types, ".count($facts['tables'])." tables.\n";

if (count($problems) > 0) {
	print "\nDrift report:\n";
	foreach ($problems as $problem) {
		print "  - ".$problem."\n";
	}
	print "\n";
} else {
	print "No drift: every mapped symbol was found in the sources.\n";
}

if ($mode === 'report') {
	exit(count($problems) > 0 ? 1 : 0);
}

// The stamp carries a date and a commit, so a comparison must ignore it or --check would fail on
// every new day. Only the body of the two documents is compared.
if ($mode === 'check') {
	$stale = array();
	foreach (array(FNMAP_MD_FILE => $markdown, FNMAP_HTML_FILE => $page) as $path => $expected) {
		$actual = file_exists($path) ? file_get_contents($path) : '';
		if (fnmapWithoutStamp($actual) !== fnmapWithoutStamp($expected)) {
			$stale[] = basename($path);
		}
	}
	if (count($stale) > 0) {
		print "OUT OF DATE: ".implode(', ', $stale)."\n";
		print "Run: php einvoicing/scripts/build_function_map.php\n";
		exit(1);
	}
	print "Up to date.\n";
	exit(0);
}

file_put_contents(FNMAP_MD_FILE, $markdown);
file_put_contents(FNMAP_HTML_FILE, $page);
print "Written: doc/FUNCTION-MAP.md (".strlen($markdown)." bytes), doc/function-map.html (".strlen($page)." bytes)\n";
exit(0);


/**
 * Strip the generation stamp, so two builds of the same source compare equal.
 *
 * @param	string	$content	Document content
 * @return	string				Content without its stamp
 */
function fnmapWithoutStamp($content)
{
	return preg_replace('/module [^\n<*]*generated \d{4}-\d{2}-\d{2}/', 'STAMP', $content);
}
