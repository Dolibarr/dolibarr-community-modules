<?php
/**
 * HTTP Test Router for stancer admin pages
 *
 * Used with: php -S 127.0.0.1:PORT -t $docRoot test/http/admin-router.php
 *
 * This router:
 * 1. Bootstraps Dolibarr (SQLite) and deploys the stancer module
 * 2. Creates a shim main.inc.php that wraps the real one
 * 3. Routes requests to admin/, ajax/, public/, and root pages
 * 4. Detects PHP fatal errors via shutdown handler
 */

if (!defined('PHPUNIT_RUNNING')) {
	define('PHPUNIT_RUNNING', true);
}

$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$requestPath = parse_url($requestUri, PHP_URL_PATH);

// Serve static files directly
if (preg_match('/\.(js|css|png|jpg|gif|ico|svg|webp)$/i', $requestPath)) {
	return false;
}

// Shutdown handler: mark fatal errors in the body
register_shutdown_function(function () {
	$error = error_get_last();
	if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
		echo "\n<!--PHPUNIT_FATAL_ERROR:" . $error['message'] . '-->';
	}
});

$projectRoot = dirname(__DIR__, 2);
$sqliteVendorPath = $projectRoot . '/vendor/cap-rel/dolibarr-integration-sqlite';
$dolibarrPath = realpath($sqliteVendorPath . '/htdocs');

// ---------------------------------------------------------------
// Ping endpoint (health check)
// ---------------------------------------------------------------
if ($requestPath === '/ping') {
	header('Content-Type: application/json');
	echo json_encode(['status' => 'ok']);
	return;
}

// ---------------------------------------------------------------
// 1. Database in RAM + Bootstrap (once per server process)
// ---------------------------------------------------------------
static $dbInitialized = false;

if (!$dbInitialized) {
	$ramDiskPath = is_dir('/dev/shm') ? '/dev/shm' : sys_get_temp_dir();
	$ramDbPath = $ramDiskPath . '/stancer_http_test_' . getmypid() . '.sdb';
	$originalDbPath = $sqliteVendorPath . '/documents/database_dolibarr.sdb';

	if (is_dir($sqliteVendorPath . '/.git')) {
		exec('cd ' . escapeshellarg($sqliteVendorPath) . ' && git reset --hard HEAD 2>/dev/null');
	}

	// Restore conf.php from template
	$confPath = $sqliteVendorPath . '/htdocs/conf/conf.php';
	$confTemplate = $sqliteVendorPath . '/htdocs/conf/conf.php_sqlite';
	if (file_exists($confTemplate)) {
		copy($confTemplate, $confPath);
	}

	if (is_file($originalDbPath)) {
		if (!file_exists($originalDbPath . '.backup')) {
			copy($originalDbPath, $originalDbPath . '.backup');
		}
		copy($originalDbPath, $ramDbPath);
		unlink($originalDbPath);
		symlink($ramDbPath, $originalDbPath);

		register_shutdown_function(function () use ($originalDbPath, $ramDbPath) {
			if (is_link($originalDbPath)) {
				unlink($originalDbPath);
			}
			if (file_exists($originalDbPath . '.backup')) {
				copy($originalDbPath . '.backup', $originalDbPath);
				unlink($originalDbPath . '.backup');
			}
			if (file_exists($ramDbPath)) {
				unlink($ramDbPath);
			}
		});
	}

	// ---------------------------------------------------------------
	// 2. Bootstrap Dolibarr
	// ---------------------------------------------------------------
	require_once $projectRoot . '/vendor/autoload.php';

	// Only NOLOGIN and NOCSRFCHECK: do NOT define NOREQUIREMENU/HTML/AJAX
	// (constants persist and pages need Form, llxHeader, ajax helpers)
	if (!defined('NOLOGIN'))    define('NOLOGIN', 1);
	if (!defined('NOCSRFCHECK')) define('NOCSRFCHECK', 1);

	$_SERVER['SCRIPT_FILENAME'] = $dolibarrPath . '/test.php';
	$_SERVER['DOCUMENT_ROOT'] = $dolibarrPath;

	$originalDir = getcwd();
	chdir($dolibarrPath);

	ob_start();
	error_reporting(E_ALL & ~E_WARNING & ~E_DEPRECATED);
	global $conf, $db, $user, $langs, $hookmanager, $mysoc;
	require_once $dolibarrPath . '/filefunc.inc.php';
	require_once DOL_DOCUMENT_ROOT . '/master.inc.php';
	error_reporting(E_ALL);
	ob_end_clean();

	chdir($originalDir);

	if (!$db || !$user) {
		http_response_code(500);
		echo json_encode(['error' => 'Dolibarr failed to initialize']);
		exit;
	}

	$user->fetch(1);

	// Register module path in dol_document_root BEFORE init()
	$parentDir = dirname($projectRoot);
	if (!isset($conf->file->dol_document_root) || !is_array($conf->file->dol_document_root)) {
		$conf->file->dol_document_root = array('main' => DOL_DOCUMENT_ROOT);
	}
	$altIndex = 0;
	while (isset($conf->file->dol_document_root['alt' . $altIndex])) {
		if ($conf->file->dol_document_root['alt' . $altIndex] === $parentDir) {
			break;
		}
		$altIndex++;
	}
	$conf->file->dol_document_root['alt' . $altIndex] = $parentDir;

	// Deploy stancer module
	$modFile = $projectRoot . '/core/modules/modStancer.class.php';
	require_once $modFile;
	$previousErrorReporting = error_reporting(E_ALL & ~E_WARNING & ~E_DEPRECATED);
	$mod = new \modStancer($db);
	$mod->init();
	error_reporting($previousErrorReporting);

	// Enable module in $conf
	if (!isset($conf->stancer)) {
		$conf->stancer = new \stdClass();
	}
	$conf->stancer->enabled = 1;
	$conf->stancer->dir_output = DOL_DATA_ROOT . '/stancer';
	if (!is_dir($conf->stancer->dir_output)) {
		@mkdir($conf->stancer->dir_output, 0755, true);
	}
	if (!isset($conf->modules)) {
		$conf->modules = array();
	}
	$conf->modules['stancer'] = 'stancer';

	// ---------------------------------------------------------------
	// 3. Create test data (societe + contact + business objects)
	// ---------------------------------------------------------------
	require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
	require_once DOL_DOCUMENT_ROOT . '/contact/class/contact.class.php';

	$soc = new \Societe($db);
	$soc->name = 'Test Company';
	$soc->client = 1;
	$soc->create($user);

	$contact = new \Contact($db);
	$contact->socid = $soc->id;
	$contact->lastname = 'TestContact';
	$contact->create($user);

	// Create one instance of each stancer business class so list pages render at least one row
	// and per-row methods (getLibStatut, getNomUrl) are exercised.
	$previousErrorReporting = error_reporting(E_ALL & ~E_WARNING & ~E_DEPRECATED & ~E_NOTICE);
	try {
		dol_include_once('/stancer/class/stancer_payments.class.php');
		dol_include_once('/stancer/class/stancer_payouts.class.php');
		dol_include_once('/stancer/class/stancer_refunds.class.php');

		$payment = new \Stancer_payments($db);
		$payment->stancer_id = 'paym_test_http_' . uniqid();
		$payment->amount = 1000;
		$payment->fee = 30;
		$payment->currency = 'EUR';
		$payment->description = 'Test HTTP payment';
		$payment->status = 2;
		$payment->date_creation = dol_now();
		$payment->fk_soc = $soc->id;
		$payment->live_mode = 0;
		$payment->method = 'card';
		if (method_exists($payment, 'create')) {
			@$payment->create($user);
		}

		$payout = new \Stancer_payouts($db);
		$payout->payout_id = 'pout_test_http_' . uniqid();
		$payout->amount = 5000;
		$payout->fees = 50;
		$payout->amount_net = 4950;
		$payout->currency = 'EUR';
		$payout->status = 1;
		$payout->date_creation = dol_now();
		$payout->live_mode = 0;
		if (method_exists($payout, 'create')) {
			@$payout->create($user);
		}

		$refund = new \Stancer_refunds($db);
		$refund->refund_id = 'refd_test_http_' . uniqid();
		$refund->payment_id = $payment->stancer_id;
		$refund->amount = 500;
		$refund->currency = 'EUR';
		$refund->status = 1;
		$refund->date_creation = dol_now();
		$refund->live_mode = 0;
		if (method_exists($refund, 'create')) {
			@$refund->create($user);
		}
	} catch (\Throwable $e) {
		// Don't break the bootstrap if business object creation fails.
		// The tests will still cover the pages without data.
	}
	error_reporting($previousErrorReporting);

	// ---------------------------------------------------------------
	// 4. Create shim main.inc.php (replaces real one in htdocs)
	// ---------------------------------------------------------------

	// Build rights forcing code from module descriptor
	$rightsCode = '';
	foreach ($mod->rights as $r) {
		$perm1 = $r[4] ?? '';
		$perm2 = $r[5] ?? '';
		if (!empty($perm1)) {
			$rightsCode .= 'if (!isset($user->rights->stancer->' . $perm1 . ')) { $user->rights->stancer->' . $perm1 . ' = new stdClass(); }' . "\n";
			if (!empty($perm2)) {
				$rightsCode .= '$user->rights->stancer->' . $perm1 . '->' . $perm2 . ' = 1;' . "\n";
			} else {
				$rightsCode .= '$user->rights->stancer->' . $perm1 . ' = 1;' . "\n";
			}
		}
	}

	$shimContent = '<?php
// Shim main.inc.php for stancer HTTP tests -- DO NOT EDIT
// This file wraps the real main.inc.php and deploys stancer for testing

// Include the real main.inc.php (idempotent)
require_once __DIR__ . "/main.inc.php.real";

// Force prod mode to expose fatal errors
$dolibarr_main_prod = "1";

// Register stancer module path
$parentDir = ' . var_export($parentDir, true) . ';
if (!isset($conf->file->dol_document_root) || !is_array($conf->file->dol_document_root)) {
    $conf->file->dol_document_root = array("main" => DOL_DOCUMENT_ROOT);
}
$altIndex = 0;
while (isset($conf->file->dol_document_root["alt" . $altIndex])) {
    if ($conf->file->dol_document_root["alt" . $altIndex] === $parentDir) {
        break;
    }
    $altIndex++;
}
$conf->file->dol_document_root["alt" . $altIndex] = $parentDir;

// Enable stancer in $conf->modules (CRITICAL for isModEnabled)
if (!isset($conf->stancer)) {
    $conf->stancer = new stdClass();
}
$conf->stancer->enabled = 1;
if (!isset($conf->modules)) {
    $conf->modules = array();
}
$conf->modules["stancer"] = "stancer";

// Bypass CSRF for test POSTs: force session token to match test token
if (!empty($_POST["token"]) && $_POST["token"] === "test") {
    $_SESSION["newtoken"] = "test";
    $_SESSION["token"] = "test";
}

// Force user rights at EVERY inclusion (outside static block)
global $user;
$user->admin = 1;
$user->getrights("stancer");
if (!isset($user->rights->stancer)) {
    $user->rights->stancer = new stdClass();
}
' . $rightsCode . '
';

	// Rename real main.inc.php and install shim
	$realMainPath = $dolibarrPath . '/main.inc.php';
	$realMainBackup = $dolibarrPath . '/main.inc.php.real';
	if (!file_exists($realMainBackup)) {
		copy($realMainPath, $realMainBackup);
	}
	file_put_contents($realMainPath, $shimContent);

	// Also create shims for pages that use relative paths to find main.inc.php
	// admin/*.php does @include "../main.inc.php" -> project root
	$rootShim = $projectRoot . '/main.inc.php';
	if (!file_exists($rootShim)) {
		file_put_contents($rootShim, '<?php require_once ' . var_export($realMainPath, true) . ';' . "\n");
	}
	// ajax/*.php and public/*.php do require '../../main.inc.php' -> parent of project root
	$parentShim = dirname($projectRoot) . '/main.inc.php';
	if (!file_exists($parentShim)) {
		file_put_contents($parentShim, '<?php require_once ' . var_export($realMainPath, true) . ';' . "\n");
	}

	// Do NOT use register_shutdown_function to clean up shim:
	// in PHP built-in server, shutdown runs after each request

	$dbInitialized = true;
}

// ---------------------------------------------------------------
// 5. Route the request to the correct PHP file
// ---------------------------------------------------------------
$targetFile = null;
$cwd = null;

if (preg_match('#^/admin/([a-zA-Z_]+\.php)$#', $requestPath, $matches)) {
	$candidate = $projectRoot . '/admin/' . $matches[1];
	if (is_file($candidate)) {
		$targetFile = $candidate;
		$cwd = $projectRoot . '/admin';
	}
} elseif (preg_match('#^/ajax/([a-zA-Z_]+\.php)$#', $requestPath, $matches)) {
	$candidate = $projectRoot . '/ajax/' . $matches[1];
	if (is_file($candidate)) {
		$targetFile = $candidate;
		$cwd = $projectRoot . '/ajax';
	}
} elseif (preg_match('#^/public/([a-zA-Z0-9_-]+\.php)$#', $requestPath, $matches)) {
	$candidate = $projectRoot . '/public/' . $matches[1];
	if (is_file($candidate)) {
		$targetFile = $candidate;
		$cwd = $projectRoot . '/public';
	}
} elseif (preg_match('#^/([a-zA-Z_]+\.php)$#', $requestPath, $matches)) {
	$candidate = $projectRoot . '/' . $matches[1];
	if (is_file($candidate)) {
		$targetFile = $candidate;
		$cwd = $projectRoot;
	}
}

if ($targetFile === null || !is_file($targetFile)) {
	http_response_code(404);
	header('Content-Type: application/json');
	echo json_encode(['error' => 'Not found', 'path' => $requestPath]);
	return;
}

// Set CONTEXT_DOCUMENT_ROOT so pages find the shim main.inc.php
$_SERVER['CONTEXT_DOCUMENT_ROOT'] = $dolibarrPath;

// Change to the target file directory (some pages use relative paths)
chdir($cwd);

// Buffer output to catch errors
ob_start();
try {
	include $targetFile;
} catch (\Throwable $e) {
	echo "\n<!--PHPUNIT_FATAL_ERROR:" . $e->getMessage() . '-->';
}
$output = ob_get_clean();

echo $output;
