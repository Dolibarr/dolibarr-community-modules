<?php
/* Copyright (C) 2025 InPoint Automation Sp z o.o.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */


// !! //
// TODO: Currently disabled ... will enable after fixing
$ENABLE_SCOPING = false;
// !! //

// =============================================================================
// scoping disabled
// =============================================================================
if (!$ENABLE_SCOPING) {
    $sourceDir = __DIR__ . '/../vendor';
    $targetDir = __DIR__ . '/../lib/vendor';

    if (!is_dir($sourceDir)) {
        fwrite(STDERR, "ERROR: vendor/ directory not found. Run 'composer install' first.\n");
        exit(1);
    }

    if (is_dir($targetDir)) {
        delTree($targetDir);
    }

    rcopy($sourceDir, $targetDir);

    if (!file_exists($targetDir . '/autoload.php')) {
        fwrite(STDERR, "ERROR: Copy failed - autoload.php not found in target.\n");
        exit(1);
    }

    echo "Dependencies copied to lib/vendor/\n";
    exit(0);
}

// =============================================================================
// scoping enabled (currently this isn't working properly - it is a reduced
// version of the string-find-and-replace hell that it was previously and needs
// to be rewritten to support phpseclib3
//
// If it's re-enabled, also need to update about.php and ksef_client.class.php
// to load the scoped classes
// =============================================================================

$scoperPhar = __DIR__ . '/../php-scoper.phar';
$composerPhar = __DIR__ . '/../composer.phar';
$outputDir = __DIR__ . '/../build/scoped';

if (!file_exists($scoperPhar)) {
    fwrite(STDERR, "ERROR: php-scoper.phar not found.\n");
    exit(1);
}

$config = <<<'PHP'
<?php
use Isolated\Symfony\Component\Finder\Finder;

return [
    'prefix' => 'KsefScoped',
    'finders' => [
        Finder::create()
            ->files()
            ->ignoreVCS(true)
            ->notName('/LICENSE|.*\.md|.*\.dist|Makefile|composer\.(json|lock)/')
            ->exclude(['doc', 'test', 'test_old', 'tests', 'Tests', 'vendor-bin'])
            ->in(__DIR__ . '/vendor'),
        Finder::create()->append([__DIR__ . '/composer.json']),
    ],
    'expose-global-constants' => false,
    'expose-global-classes' => false,
    'expose-global-functions' => false,
];
PHP;

file_put_contents(__DIR__ . '/../scoper.inc.php', $config);

$cmd = sprintf(
    '%s %s add-prefix --output-dir=%s --force --config=%s --no-interaction',
    escapeshellarg(PHP_BINARY),
    escapeshellarg($scoperPhar),
    escapeshellarg($outputDir),
    escapeshellarg(__DIR__ . '/../scoper.inc.php')
);

passthru($cmd, $exitCode);
if ($exitCode !== 0) {
    fwrite(STDERR, "ERROR: php-scoper failed.\n");
    exit(1);
}

if (!file_exists($composerPhar)) {
    fwrite(STDERR, "ERROR: composer.phar not found.\n");
    exit(1);
}

$cwd = getcwd();
chdir($outputDir);
passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($composerPhar) . ' dump-autoload --classmap-authoritative --no-dev', $exitCode);
chdir($cwd);

if ($exitCode !== 0) {
    fwrite(STDERR, "ERROR: Composer generation failed.\n");
    exit(1);
}

// Copy to lib/vendor
$sourceDir = $outputDir . '/vendor';
$targetDir = __DIR__ . '/../lib/vendor';

if (is_dir($targetDir)) {
    delTree($targetDir);
}

rcopy($sourceDir, $targetDir);

if (!file_exists($targetDir . '/autoload.php')) {
    fwrite(STDERR, "ERROR autoload.php not found.\n");
    exit(1);
}

echo "Scoped stuff copied to lib/vendor/\n";

function delTree($dir)
{
    if (!is_dir($dir)) {
        return;
    }
    $files = array_diff(scandir($dir), ['.', '..']);
    foreach ($files as $file) {
        $path = $dir . '/' . $file;
        is_dir($path) ? delTree($path) : unlink($path);
    }
    rmdir($dir);
}

function rcopy($src, $dst)
{
    if (is_dir($src)) {
        if (!is_dir($dst)) {
            mkdir($dst, 0755, true);
        }
        $dir = opendir($src);
        while (false !== ($file = readdir($dir))) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            rcopy($src . '/' . $file, $dst . '/' . $file);
        }
        closedir($dir);
    } elseif (is_file($src)) {
        copy($src, $dst);
    }
}
