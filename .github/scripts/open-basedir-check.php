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
 */

/**
 * \file    .github/scripts/open-basedir-check.php
 * \brief   Runs the paths of the module that create directories under an open_basedir, and refuses
 *          any path the module asks PHP for outside it (issue #1012).
 * \remarks open_basedir can only be narrowed at run time, never widened, so it is set here rather
 *          than on the command line: the instance is bootstrapped first, and the restriction is put
 *          in place just before the module is called. dol_mkdir() hides its probes with '@', but an
 *          error handler is still called inside '@', which is what counts them. Reads DOLIBARR_HTDOCS.
 */

if (PHP_SAPI !== 'cli') {
	echo "Error: this script must be run from the command line.\n";
	exit(1);
}

global $conf, $db, $langs, $user, $mysoc;

$htdocs = getenv('DOLIBARR_HTDOCS');
if (!$htdocs || !file_exists($htdocs . '/master.inc.php')) {
	fwrite(STDERR, 'DOLIBARR_HTDOCS does not point at an htdocs directory (got "' . $htdocs . '")' . "\n");
	exit(2);
}

require_once $htdocs . '/master.inc.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/files.lib.php';
require_once DOL_DOCUMENT_ROOT . '/user/class/user.class.php';
require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
dol_include_once('einvoicing/class/einvoicing.class.php');
dol_include_once('einvoicing/class/protocols/ProtocolManager.class.php');
dol_include_once('einvoicing/class/utils/SupportExport.class.php');

$user = new User($db);
$user->fetch(1);

// The module directory is reached through the htdocs/custom symlink, so its target has to be
// allowed as well: PHP checks the resolved path, not the link.
$allowed = array(
	realpath(DOL_DOCUMENT_ROOT),
	realpath(DOL_DATA_ROOT),
	realpath(dol_buildpath('/einvoicing', 0)),
	sys_get_temp_dir(),
);
$allowed = array_values(array_unique(array_filter($allowed)));
if (ini_set('open_basedir', implode(PATH_SEPARATOR, $allowed)) === false) {
	fwrite(STDERR, "open_basedir cannot be set at run time on this PHP\n");
	exit(2);
}
echo 'open_basedir = ' . ini_get('open_basedir') . "\n";

$violations = array();
set_error_handler(
	/**
	 * @param	int		$errno	Error level
	 * @param	string	$errstr	Error message
	 * @return	bool			Always false, so the normal handler still runs
	 */
	function ($errno, $errstr) use (&$violations) {
		if (strpos($errstr, 'open_basedir') === false) {
			return false;
		}
		// Only what the module itself asked for: the frame of dol_mkdir() carries the file that
		// called it, so a core caller of the same function is not counted against the module.
		foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
			if (isset($frame['function']) && $frame['function'] === 'dol_mkdir'
				&& isset($frame['file']) && strpos($frame['file'], '/einvoicing/') !== false) {
				$violations[] = basename($frame['file']) . ':' . $frame['line'] . ' - ' . $errstr;
				break;
			}
		}
		return false;
	},
	E_ALL
);

/**
 * Run one entry point of the module and say what it asked for outside open_basedir.
 *
 * @param	string		$label	What is being run
 * @param	callable	$run	The call itself, answering array{0:bool,1:string}: whether it produced
 *								its file, and what to print
 * @return	int[]				array{0:int,1:int}: paths asked for outside open_basedir, and 1 when
 *								the entry point produced its file
 */
function step($label, $run)
{
	global $violations;

	$violations = array();
	list($produced, $outcome) = $run();
	$count = count($violations);

	printf("  %-28s %-40s %s\n", $label, $outcome, ($count ? $count . ' path(s) outside open_basedir' : 'nothing outside open_basedir'));
	foreach (array_unique($violations) as $violation) {
		echo '      ' . substr($violation, 0, 200) . "\n";
	}

	return array($count, ($produced ? 1 : 0));
}

$einvoicing = new EInvoicing($db);
$manager = new ProtocolManager($db);
$total = 0;
$produced = 0;

// A directory that does not exist yet, which is the only case where dol_mkdir() walks the path.
dol_delete_dir_recursive($conf->einvoicing->dir_temp);

list($outside, $built) = step('support export', function () use ($db, $conf, $user) {
	$export = new SupportExport($db);
	// A rowid that matches nothing: the archive is built and holds its manifest alone, which is all
	// this needs - the directories are created before anything is read from the database.
	$archive = $export->build(SupportExport::TYPE_FLOW, array(PHP_INT_MAX), $conf->einvoicing->dir_output . '/temp/massgeneration/' . $user->id);
	if ($archive !== '' && file_exists($archive)) {
		dol_delete_file($archive, 0, 1);
		return array(true, 'archive built');
	}

	return array(false, 'NOT BUILT: ' . $export->error);
});
$total += $outside;
$produced += $built;

foreach (array('CII', 'FACTURX') as $format) {
	list($outside, $built) = step('sample invoice, ' . $format, function () use ($manager, $einvoicing, $mysoc, $format) {
		$protocol = $manager->getProtocol($format);
		if (!is_object($protocol)) {
			return array(false, 'protocol not available');
		}
		$options = array('invoiceformat' => $format, 'invoicetype' => Facture::TYPE_STANDARD, 'referencedinvoice' => '');
		$sample = ((float) DOL_VERSION < 24.0)
			? $protocol->generateSampleInvoiceOld($einvoicing, $mysoc, $mysoc, $options)
			: $protocol->generateSampleInvoice($einvoicing, $mysoc, $mysoc, $options);
		$path = (is_array($sample) && !empty($sample['path'])) ? $sample['path'] : '';
		$ok = ($path !== '' && file_exists($path));

		return array($ok, ($ok ? 'generated' : 'NOT GENERATED'));
	});
	$total += $outside;
	$produced += $built;
}

restore_error_handler();

// A green answer means something only if the module really created its directories here: an entry
// point that produced nothing would have nothing to say about the paths it asks for.
if ($produced === 0) {
	fwrite(STDERR, "::error::no entry point of the module produced its file, so this check proved nothing\n");
	exit(2);
}

if ($total > 0) {
	fwrite(STDERR, '::error::the module asked PHP for ' . $total . " path(s) outside open_basedir - pass the data root to dol_mkdir()\n");
	exit(1);
}

echo 'The module creates its directories without reaching outside open_basedir (' . $produced . " entry point(s) exercised).\n";
