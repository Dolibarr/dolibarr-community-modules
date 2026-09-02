<?php
/* Copyright (C) 2026		Pierre Grasswill				<da.grumpf@gmail.com>
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
 */

/**
 * \file       lib/xmlhighlight.lib.php
 * \ingroup    einvoicing
 * \brief      Render an XML source as coloured, foldable, numbered HTML, the way a browser shows it.
 *
 * Self-contained on purpose - no class, no global, no other Dolibarr function - so the two functions
 * can move to the core the day its preview learns XML. Nothing parses the document: it is tokenised as
 * text and printed escaped, so a truncated or invalid file still shows as it is on disk.
 */


/**
 * Render an XML source as HTML: every token escaped and wrapped in a span the stylesheet colours, one
 * element of the output per line of the input, and a fold handle on every element that spans several
 * lines.
 *
 * Never returns anything that the browser could execute: the whole source goes through
 * htmlspecialchars(), and the only tags of the output are the span and div this function writes itself.
 *
 * @param 	string	$xml		XML source, as read from the file
 * @param 	int<0,1>	$foldable	1 to emit the fold handles and the data attributes the folding script reads
 * @return 	string				HTML to print inside a <pre class="xmlsource">
 */
function einvoicingXmlSourceToHtml($xml, $foldable = 1)
{
	$lines = preg_split('/\r\n|\r|\n/', $xml);
	if ($lines === false) {
		return htmlspecialchars($xml, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}

	// First pass: for every line, which element it opens and which one it closes, so a line that opens an
	// element closed further down can be given the number of the line that closes it. Only the tags of a
	// line are looked at, in order, and an element whose start and end tags sit on the same line is not
	// foldable.
	$foldend = array();
	if ($foldable) {
		$stack = array();
		foreach ($lines as $i => $line) {
			if (preg_match_all('/<(\/?)([A-Za-z_][^\s\/>]*)([^<>]*?)(\/?)>/s', $line, $tags, PREG_SET_ORDER)) {
				foreach ($tags as $tag) {
					if (!empty($tag[4])) {
						continue;	// Self-closing: opens and closes at once
					}
					if (empty($tag[1])) {
						$stack[] = array($tag[2], $i);
						continue;
					}
					// Closing tag: it closes the last element opened, and only if it carries the same
					// name. An end tag that matches nothing simply folds nothing - a document that does
					// not close its tags gets fewer handles, never a handle over the wrong lines.
					$open = empty($stack) ? null : array_pop($stack);
					if (is_array($open) && $open[0] === $tag[2] && $open[1] < $i) {
						$foldend[$open[1]] = $i;
					}
				}
			}
		}
	}

	$out = '';
	foreach ($lines as $i => $line) {
		$out .= '<div class="xmlline"';
		if (isset($foldend[$i])) {
			$out .= ' data-foldend="'.((int) $foldend[$i]).'"';
		}
		$out .= ' id="xmlline'.((int) $i).'">';
		// The number is written here and not built by a CSS counter: a counter does not increment on a
		// line the folding hides, so folding would renumber everything below it.
		$out .= '<span class="xmlno">'.((int) $i + 1).'</span>';
		$out .= '<span class="xmlfold">'.(isset($foldend[$i]) ? '&#9662;' : '').'</span>';
		$out .= '<span class="xmlcode">'.einvoicingXmlLineToHtml($line).'</span>';
		$out .= '</div>';
	}

	return $out;
}

/**
 * Colour one line of an XML source.
 *
 * A line is cut into the markup units it holds - comment, CDATA section, processing instruction or
 * declaration, doctype, tag - and everything between them is text content. A unit opened on a line and
 * closed on another is coloured on both, each half being a token that starts or ends nowhere, which is
 * why the delimiters are also matched alone.
 *
 * @param 	string	$line	One line of the source
 * @return 	string			HTML for that line, everything escaped
 */
function einvoicingXmlLineToHtml($line)
{
	$parts = preg_split(
		'/(<!--.*?-->|<!--.*$|^.*?-->|<!\[CDATA\[.*?\]\]>|<\?.*?\?>|<!DOCTYPE[^>]*>|<\/?[A-Za-z_!\/][^<>]*>|<\/?[A-Za-z_][^<>]*$)/s',
		$line,
		-1,
		PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY
	);
	if ($parts === false) {
		return htmlspecialchars($line, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}

	$out = '';
	foreach ($parts as $part) {
		if (strpos($part, '<!--') === 0 || substr($part, -3) === '-->') {
			$out .= '<span class="xmlcomment">'.htmlspecialchars($part, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</span>';
		} elseif (strpos($part, '<![CDATA[') === 0) {
			$out .= '<span class="xmlcdata">'.htmlspecialchars($part, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</span>';
		} elseif (strpos($part, '<?') === 0 || stripos($part, '<!DOCTYPE') === 0) {
			$out .= '<span class="xmlpi">'.htmlspecialchars($part, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</span>';
		} elseif (strpos($part, '<') === 0) {
			$out .= einvoicingXmlTagToHtml($part);
		} else {
			$out .= '<span class="xmltext">'.htmlspecialchars($part, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</span>';
		}
	}

	return $out;
}

/**
 * Colour one tag: its punctuation, the name of the element - the namespace prefix apart, since it is what
 * makes a CII document unreadable when everything carries the same colour - and every attribute of it as
 * a name and a value.
 *
 * @param 	string	$tag	One tag, from its '<' to its '>' when it has one
 * @return 	string			HTML for that tag, everything escaped
 */
function einvoicingXmlTagToHtml($tag)
{
	if (!preg_match('/^<(\/?)([^\s\/>]*)(.*?)(\/?)(>?)$/s', $tag, $m)) {
		return '<span class="xmltag">'.htmlspecialchars($tag, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</span>';
	}

	$out = '<span class="xmlpunct">&lt;'.$m[1].'</span>';

	$name = $m[2];
	$colon = strpos($name, ':');
	if ($colon !== false) {
		$out .= '<span class="xmlprefix">'.htmlspecialchars(substr($name, 0, $colon + 1), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</span>';
		$name = substr($name, $colon + 1);
	}
	$out .= '<span class="xmlname">'.htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</span>';

	// Attributes, keeping the spacing of the file between them
	$attrs = $m[3];
	$offset = 0;
	if (preg_match_all('/([^\s=<>\/]+)(\s*=\s*)("[^"]*"|\'[^\']*\')/s', $attrs, $found, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
		foreach ($found as $one) {
			$start = $one[0][1];
			$out .= htmlspecialchars(substr($attrs, $offset, $start - $offset), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
			$out .= '<span class="xmlattr">'.htmlspecialchars($one[1][0], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</span>';
			$out .= htmlspecialchars($one[2][0], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
			$out .= '<span class="xmlvalue">'.htmlspecialchars($one[3][0], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</span>';
			$offset = $start + strlen($one[0][0]);
		}
	}
	$out .= htmlspecialchars(substr($attrs, $offset), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

	$out .= '<span class="xmlpunct">'.$m[4].$m[5].'</span>';

	return $out;
}

/**
 * Stylesheet of the coloured source, and the script that folds an element on a click on its handle.
 *
 * The block sets its own background and its own text colour rather than inheriting them, so it reads the
 * same under every theme; the colours are the ones a browser uses for the XML it displays itself, which
 * is what people expect to see.
 *
 * @param 	int<0,1>	$withscript		1 to append the folding script
 * @param 	string		$nonce			Value of the nonce attribute of the script tag, empty when the page has none
 * @return 	string						A <style> block, followed by a <script> block when asked for one
 */
function einvoicingXmlSourceCss($withscript = 1, $nonce = '')
{
	$out = '<style>
pre.xmlsource { margin: 0; background: #fbfbfd; color: #303030; border: 1px solid #e0e0e6; border-radius: 4px; padding: 8px 8px 8px 0; overflow-x: auto; }
pre.xmlsource .xmlline { display: block; white-space: pre-wrap; overflow-wrap: anywhere; padding-left: 5.5em; text-indent: -1.2em; }
pre.xmlsource .xmlline:hover { background: #eef2fa; }
pre.xmlsource .xmlno { display: inline-block; width: 3.2em; margin-left: -5.5em; padding-right: 0.7em; text-align: right; color: #a8a8b4; text-indent: 0; -webkit-user-select: none; user-select: none; }
pre.xmlsource .xmlfold { display: inline-block; width: 1.2em; color: #8a8a96; cursor: default; text-indent: 0; -webkit-user-select: none; user-select: none; }
pre.xmlsource .xmlline[data-foldend] > .xmlfold { cursor: pointer; }
pre.xmlsource .xmlline.xmlfolded > .xmlfold { transform: rotate(-90deg); }
pre.xmlsource .xmlline.xmlfolded > .xmlcode::after { content: " \\2026 "; color: #8a8a96; background: #ececf2; border-radius: 3px; padding: 0 4px; }
pre.xmlsource .xmlhidden { display: none; }
pre.xmlsource .xmlpunct { color: #7c7c88; }
pre.xmlsource .xmlprefix { color: #9a6ec4; }
pre.xmlsource .xmlname { color: #1a5fb4; }
pre.xmlsource .xmlattr { color: #b5651d; }
pre.xmlsource .xmlvalue { color: #1d7a3e; }
pre.xmlsource .xmltext { color: #202020; }
pre.xmlsource .xmlcomment { color: #8a8a96; font-style: italic; }
pre.xmlsource .xmlcdata { color: #7a5c00; }
pre.xmlsource .xmlpi { color: #7c7c88; }
</style>';

	if (empty($withscript)) {
		return $out;
	}

	// Folding: a line that opens an element carries the number of the line that closes it, so hiding what
	// is in between needs no tree. Lines already hidden by an outer fold stay hidden when an inner one is
	// reopened, which is why the state is recomputed from the folded lines rather than toggled.
	$out .= '<script'.($nonce ? ' nonce="'.$nonce.'"' : '').' type="text/javascript">
(function () {
	var root = document.querySelector("pre.xmlsource");
	if (!root) { return; }
	var lines = root.querySelectorAll(".xmlline");
	function refresh() {
		var hidden = [];
		var i, j;
		for (i = 0; i < lines.length; i++) { hidden[i] = false; }
		for (i = 0; i < lines.length; i++) {
			if (!lines[i].classList.contains("xmlfolded") || hidden[i]) { continue; }
			var end = parseInt(lines[i].getAttribute("data-foldend"), 10);
			for (j = i + 1; j <= end && j < lines.length; j++) { hidden[j] = true; }
		}
		for (i = 0; i < lines.length; i++) { lines[i].classList.toggle("xmlhidden", hidden[i]); }
	}
	root.addEventListener("click", function (e) {
		var handle = e.target.closest ? e.target.closest(".xmlfold") : null;
		if (!handle) { return; }
		var line = handle.parentNode;
		if (!line.hasAttribute("data-foldend")) { return; }
		line.classList.toggle("xmlfolded");
		refresh();
	});
})();
</script>';

	return $out;
}
