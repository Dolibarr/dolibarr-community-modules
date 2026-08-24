<?php

/**
 * Initialize DOL_DOCUMENT_ROOT for unit tests
 * This file detects execution mode to avoid conflicts with integration tests
 *
 * Following ~/docs/TESTING.md guidelines
 */

// Detect if running integration tests (they have their own bootstrap)
$_isIntegrationTest = false;
if (isset($_SERVER['argv'])) {
    foreach ($_SERVER['argv'] as $_arg) {
        if (strpos($_arg, 'phpunit-integration') !== false) {
            $_isIntegrationTest = true;
            break;
        }
    }
}

// Skip initialization if already done or in integration mode
if (defined('DOL_DOCUMENT_ROOT') || defined('PHPUNIT_RUNNING') || $_isIntegrationTest) {
    unset($_isIntegrationTest);
    return;
}
unset($_isIntegrationTest);

// Check if cap-rel/dolibarr-integration-sqlite is installed
$_dolibarr_autoload_init_path = __DIR__ . '/../../vendor/cap-rel/dolibarr-integration-sqlite/htdocs';
if (!is_dir($_dolibarr_autoload_init_path)) {
    unset($_dolibarr_autoload_init_path);
    return; // Package not installed, unit tests will use mocks
}

// Define DOL_DOCUMENT_ROOT immediately (before any class loading)
define('DOL_DOCUMENT_ROOT', realpath($_dolibarr_autoload_init_path));

// Create minimal global $conf object for class loading
global $conf;
$conf = new stdClass();
$conf->file = new stdClass();
$conf->file->main_limit_users = 0;
$conf->global = new stdClass();
$conf->entity = 1;
$conf->currency = 'EUR';

// Create minimal global $langs object
global $langs;
$langs = new stdClass();
$langs->defaultlang = 'fr_FR';

unset($_dolibarr_autoload_init_path);
