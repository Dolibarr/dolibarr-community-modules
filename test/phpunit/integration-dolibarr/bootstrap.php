<?php

/**
 * Bootstrap for integration tests with real Dolibarr instance
 * Following ~/docs/TESTING.md guidelines
 */

// 1. Test constants
if (!defined('PHPUNIT_RUNNING')) {
    define('PHPUNIT_RUNNING', true);
}
if (!defined('PHPUNIT_TEST_MODE')) {
    define('PHPUNIT_TEST_MODE', true);
}

// 2. Composer autoloader
require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

// 3. Find and verify dolibarr-integration-sqlite package
$sqliteVendorPath = dirname(__DIR__, 3) . '/vendor/cap-rel/dolibarr-integration-sqlite';
$sqliteVendorPath = realpath($sqliteVendorPath);

if (!$sqliteVendorPath || !is_dir($sqliteVendorPath)) {
    echo "ERROR: cap-rel/dolibarr-integration-sqlite not installed.\n";
    echo "Run: composer require --dev cap-rel/dolibarr-integration-sqlite:@dev\n";
    exit(1);
}

// 4. Restore conf.php from template (in case previous test crashed)
$confPath = $sqliteVendorPath . '/htdocs/conf/conf.php';
$confTemplate = $sqliteVendorPath . '/htdocs/conf/conf.php_sqlite';
if (file_exists($confTemplate)) {
    copy($confTemplate, $confPath);
}

// 5. Redirect the SHARED SQLite database to a per-process RAM copy.
// The real database is documents/database_dolibarr.sdb (db name 'dolibarr').
// Without this, every test writes into that shared file and it is never reset,
// so state leaks across tests and runs. We back it up once (pristine), restore
// it before each run, then symlink it to a RAM copy so each run is isolated.
$ramDiskPath = is_dir('/dev/shm') ? '/dev/shm' : sys_get_temp_dir();
$ramDbPath = $ramDiskPath . '/stancer_test_' . getmypid() . '.sdb';
$originalDbPath = $sqliteVendorPath . '/documents/database_dolibarr.sdb';
$backupDbPath = $originalDbPath . '.backup';

if (!file_exists($originalDbPath)) {
    throw new RuntimeException("Source SQLite database not found at: $originalDbPath");
}

// Prepare a clean database: git checkout when the vendor is a git checkout,
// otherwise restore from (or create) a backup of the pristine database.
if (is_dir($sqliteVendorPath . '/.git')) {
    exec('cd ' . escapeshellarg($sqliteVendorPath) . ' && git checkout -- documents/database_dolibarr.sdb 2>/dev/null');
} elseif (is_file($backupDbPath)) {
    copy($backupDbPath, $originalDbPath);
} elseif (is_file($originalDbPath)) {
    copy($originalDbPath, $backupDbPath);
}

// Point the shared DB file at a per-process RAM copy.
if (is_file($originalDbPath)) {
    if (!file_exists($backupDbPath)) {
        copy($originalDbPath, $backupDbPath);
    }
    copy($originalDbPath, $ramDbPath);
    unlink($originalDbPath);
    symlink($ramDbPath, $originalDbPath);

    register_shutdown_function(function () use ($originalDbPath, $ramDbPath, $backupDbPath) {
        if (is_link($originalDbPath)) {
            unlink($originalDbPath);
        }
        if (file_exists($backupDbPath)) {
            copy($backupDbPath, $originalDbPath);
        }
        if (file_exists($ramDbPath)) {
            @unlink($ramDbPath);
        }
    });
}

// 6. Define DOL_DOCUMENT_ROOT BEFORE any Dolibarr loading
if (!defined('DOL_DOCUMENT_ROOT')) {
    define('DOL_DOCUMENT_ROOT', $sqliteVendorPath . '/htdocs');
}

// 7. Define CLI constants BEFORE master.inc.php
if (!defined('NOREQUIREMENU')) {
    define('NOREQUIREMENU', 1);
}
if (!defined('NOREQUIREHTML')) {
    define('NOREQUIREHTML', 1);
}
if (!defined('NOREQUIREAJAX')) {
    define('NOREQUIREAJAX', 1);
}
if (!defined('NOLOGIN')) {
    define('NOLOGIN', 1);
}
if (!defined('NOCSRFCHECK')) {
    define('NOCSRFCHECK', 1);
}

// 8. Define $_SERVER variables BEFORE master.inc.php
$_SERVER['PHP_SELF'] = '/test.php';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SCRIPT_NAME'] = '/test.php';
$_SERVER['SCRIPT_FILENAME'] = DOL_DOCUMENT_ROOT . '/test.php';
$_SERVER['REQUEST_URI'] = '/test.php';
$_SERVER['DOCUMENT_ROOT'] = DOL_DOCUMENT_ROOT;
$_SERVER['QUERY_STRING'] = '';
$_SERVER['REQUEST_METHOD'] = 'GET';

// 9. Change directory to htdocs BEFORE loading
$originalDir = getcwd();
chdir(DOL_DOCUMENT_ROOT);

// 10. Suppress warnings during bootstrap
ob_start();
$previousErrorReporting = error_reporting(E_ALL & ~E_WARNING & ~E_DEPRECATED);

// 11. Load filefunc.inc.php THEN master.inc.php
global $conf, $db, $user, $langs, $hookmanager, $mysoc;
require_once DOL_DOCUMENT_ROOT . '/filefunc.inc.php';
require_once DOL_DOCUMENT_ROOT . '/master.inc.php';

// 12. Restore state
error_reporting($previousErrorReporting);
ob_end_clean();
chdir($originalDir);

// 12b. Replace geturl.lib.php with mock version BEFORE any module code loads it
// master.inc.php does NOT load geturl.lib.php, so we can safely replace it now.
// stancer_api.class.php will later do require_once on this path and get our mock.
$geturlPath = DOL_DOCUMENT_ROOT . '/core/lib/geturl.lib.php';
$geturlBackup = $geturlPath . '.bak_phpunit';
if (file_exists($geturlPath) && !file_exists($geturlBackup)) {
    copy($geturlPath, $geturlBackup);
    $mockDir = dirname(__DIR__, 3) . '/test/phpunit/Mocks';
    $mockGeturl = <<<MOCK_PHP
<?php
require_once '$mockDir/HttpMock.php';

function getURLContent(\$url, \$postorget = 'GET', \$param = '', \$followlocation = 1, \$addheaders = array(), \$allowedschemes = array('http', 'https'), \$localurl = 0, \$ssl_verifypeer = -1)
{
    \$method = \$postorget;
    if (\$postorget === 'POSTALREADYFORMATED') {
        \$method = 'POST';
    } elseif (\$postorget === 'PUTALREADYFORMATED') {
        \$method = 'PUT';
    }
    if (class_exists('HttpMock') && HttpMock::isActive()) {
        return HttpMock::getResponse(\$url, \$method, \$param);
    }
    return array('http_code' => 0, 'content' => '', 'curl_error_no' => 7, 'curl_error_msg' => 'No mock configured');
}
MOCK_PHP;
    file_put_contents($geturlPath, $mockGeturl);
    register_shutdown_function(function () use ($geturlPath, $geturlBackup) {
        if (file_exists($geturlBackup)) {
            copy($geturlBackup, $geturlPath);
            unlink($geturlBackup);
        }
    });
}

// 13. Load admin user
$user->fetch(1);

// 14. Configure dol_document_root for dol_buildpath()
$projectRoot = dirname(__DIR__, 3);

if (!isset($conf->file->dol_document_root) || !is_array($conf->file->dol_document_root)) {
    $conf->file->dol_document_root = array('main' => DOL_DOCUMENT_ROOT);
}

// 15. Create symlink for case-insensitive module path resolution
$parentDir = dirname($projectRoot);
$moduleName = strtolower(basename($projectRoot)); // 'stancer'
$symlinkPath = $parentDir . '/' . $moduleName;

if (!file_exists($symlinkPath) && basename($projectRoot) !== $moduleName) {
    @symlink($projectRoot, $symlinkPath);
    // Clean up symlink on shutdown
    register_shutdown_function(function () use ($symlinkPath) {
        if (is_link($symlinkPath)) {
            @unlink($symlinkPath);
        }
    });
}

$conf->file->dol_document_root['alt0'] = $parentDir;

// 16. Initialize module using its init() function
$moduleClassFile = $projectRoot . '/core/modules/modStancer.class.php';
if (file_exists($moduleClassFile)) {
    require_once $moduleClassFile;

    // Suppress warnings during init (SQL conversion warnings are expected with SQLite)
    $previousErrorReporting = error_reporting(E_ALL & ~E_WARNING & ~E_DEPRECATED);

    $module = new modStancer($db);
    $result = $module->init();

    // Restore error reporting
    error_reporting($previousErrorReporting);

    // Drop and recreate module tables to ensure correct schema
    // The init() may create tables with incorrect schema from PHP field definitions
    $moduleTables = [
        'llx_stancer_stancer_payments',
        'llx_stancer_stancer_payouts',
        'llx_stancer_stancer_refunds',
    ];

    foreach ($moduleTables as $table) {
        $db->query("DROP TABLE IF EXISTS " . $table);
    }

    // Recreate tables from SQL files with correct schema
    createStancerModuleTables($db, $projectRoot);

    // Reload $conf from the DB so modules_parts (hooks, triggers, ...) reflects the
    // just-activated module. master.inc.php built modules_parts BEFORE init() ran,
    // so on a pristine (isolated) DB the stancer hooks would otherwise be missing.
    $previousErrorReporting = error_reporting(E_ALL & ~E_WARNING & ~E_DEPRECATED);
    $conf->setValues($db);
    error_reporting($previousErrorReporting);
}

// 17. Initialize module configuration in $conf
if (!isset($conf->stancer)) {
    $conf->stancer = new stdClass();
}
$conf->stancer->enabled = 1;
$conf->stancer->dir_output = DOL_DATA_ROOT . '/stancer';

/**
 * Create module-specific tables if they don't exist (fallback)
 *
 * @param DoliDB $db Database handler
 * @param string $projectRoot Project root path
 * @return void
 */
function createStancerModuleTables($db, string $projectRoot): void
{
    $sqlDir = $projectRoot . '/sql/';

    if (!is_dir($sqlDir)) {
        return;
    }

    $sqlFiles = glob($sqlDir . 'llx_*.sql');
    foreach ($sqlFiles as $sqlFile) {
        // Skip .key.sql files (indexes) for now
        if (strpos($sqlFile, '.key.sql') !== false) {
            continue;
        }

        $sql = file_get_contents($sqlFile);
        if (empty($sql)) {
            continue;
        }

        $sql = convertMysqlToSqlite($sql);

        // Execute each statement separately
        $statements = preg_split('/;\s*$/m', $sql);
        foreach ($statements as $statement) {
            $statement = trim($statement);
            if (!empty($statement)) {
                $db->query($statement);
            }
        }
    }
}

/**
 * Convert MySQL SQL syntax to SQLite
 *
 * @param string $sql MySQL SQL statement
 * @return string SQLite compatible SQL
 */
function convertMysqlToSqlite(string $sql): string
{
    // Remove inline comments before converting whitespace
    $sql = preg_replace('/--[^\n]*$/m', '', $sql);

    // Remove ENGINE clause with all options
    $sql = preg_replace('/\)\s*ENGINE\s*=\s*\w+[^;]*;/i', ');', $sql);

    // Convert AUTO_INCREMENT to SQLite style
    $sql = preg_replace('/\binteger\s+AUTO_INCREMENT\s+PRIMARY\s+KEY/i', 'INTEGER PRIMARY KEY AUTOINCREMENT', $sql);
    $sql = preg_replace('/\bint\s+AUTO_INCREMENT/i', 'INTEGER PRIMARY KEY AUTOINCREMENT', $sql);
    $sql = preg_replace('/\bAUTO_INCREMENT/i', '', $sql);

    // Remove inline INDEX/KEY definitions
    $sql = preg_replace('/,?\s*(?:INDEX|KEY)\s+\w+\s*\([^)]+\)/i', '', $sql);

    // Remove inline COMMENT
    $sql = preg_replace('/\s+COMMENT\s+\'[^\']*\'/i', '', $sql);

    // Remove CHARSET and COLLATE
    $sql = preg_replace('/\s+DEFAULT\s+CHARSET\s*=\s*[a-z0-9_]+/i', '', $sql);
    $sql = preg_replace('/\s+COLLATE\s*=?\s*[a-z0-9_]+/i', '', $sql);

    // Remove PRIMARY KEY constraint when using AUTOINCREMENT
    $sql = preg_replace('/,\s*PRIMARY\s+KEY\s*\([^)]+\)/i', '', $sql);

    // Convert data types
    $sql = preg_replace('/\bsmallint\b/i', 'INTEGER', $sql);
    $sql = preg_replace('/\btinyint\b/i', 'INTEGER', $sql);
    $sql = preg_replace('/\bbigint\b/i', 'INTEGER', $sql);
    $sql = preg_replace('/\bint\(\d+\)/i', 'INTEGER', $sql);
    $sql = preg_replace('/\bdouble\b/i', 'REAL', $sql);
    $sql = preg_replace('/\bfloat\b/i', 'REAL', $sql);
    $sql = preg_replace('/\bvarchar\(\d+\)/i', 'TEXT', $sql);
    $sql = preg_replace('/\bdatetime\b/i', 'TEXT', $sql);
    $sql = preg_replace('/\btimestamp\b/i', 'TEXT', $sql);

    // Remove UNSIGNED
    $sql = preg_replace('/\bUNSIGNED\b/i', '', $sql);

    // Remove ON UPDATE CURRENT_TIMESTAMP
    $sql = preg_replace('/\s+ON\s+UPDATE\s+CURRENT_TIMESTAMP/i', '', $sql);

    return trim($sql);
}
