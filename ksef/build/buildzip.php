<?php
/* Copyright (C) 2023 Eric Seigne          <eric.seigne@cap-rel.fr>
 * Copyright (C) 2025 InPoint Automation Sp z o.o.
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

/**
 * buildzip.php
 *
 * Build script for KSEF module with bundled dependencies
 * Adapted from FacturX build system
 */

$includeTests = in_array('-test', $argv);

// Configuration
$list = [
    'admin',
    'class',
    'LICENSE',
    'MAINTAINERS',
    'core',
    'css',
    'img',
    'js',
    'langs',
    'lib',
    'sql',
//    'vendor',
    '*.md',
    '*.php',
];

if ($includeTests) {
    $list[] = 'test';
    $list[] = 'test_xml';
}

$exclude_list = [
    '/^\.git$/',
    '/^\.idea$/',
    '/^\.vscode$/',
    '/^\.DS_Store$/',
    '/^build$/',
    '/.*\.map$/',
    '/^vendor\/.*\/tests?$/',
    '/^vendor\/.*\/\.git.*$/',
    '/^vendor\/.*\/docs?$/',
    '/^vendor\/bin$/',
];

function detectModule()
{
    $tab = glob("core/modules/mod*.class.php");
    if (count($tab) == 1) {
        $file = $tab[0];
        $mod = "";
        $pattern = "/.*mod(?<mod>.*)\.class\.php/";
        if (preg_match_all($pattern, $file, $matches)) {
            $mod = strtolower(reset($matches['mod']));
        }

        if (!file_exists($file) || $mod == "") {
            echo "Error: Could not detect module code in $file\n";
            exit(1);
        }
    } else {
        echo "Error: Multiple or no mod* files found in core/modules/ directory.\n";
        exit(1);
    }

    $contents = file_get_contents($file);
    $pattern = "/^.*this->version\s*=\s*'(?<version>.*)'\s*;.*\$/m";

    $version = '';
    if (preg_match_all($pattern, $contents, $matches)) {
        $version = reset($matches['version']);
    }

    echo "Detected Module: $mod (v$version)\n";
    return [$mod, $version];
}

function delTree($dir)
{
    $files = array_diff(scandir($dir), array('.', '..'));
    foreach ($files as $file) {
        (is_dir("$dir/$file")) ? delTree("$dir/$file") : unlink("$dir/$file");
    }
    return rmdir($dir);
}

function is_excluded($filename)
{
    global $exclude_list;
    $count = 0;
    preg_filter($exclude_list, '1', $filename, -1, $count);
    return ($count > 0);
}

function rcopy($src, $dst)
{
    if (is_dir($src)) {
        if (!is_dir($dst)) {
            @mkdir($dst);
        }
        $dir = opendir($src);
        while ($file = readdir($dir)) {
            if (($file != '.') && ($file != '..')) {
                if (!is_excluded($file)) {
                    if (is_dir($src . '/' . $file)) {
                        rcopy($src . '/' . $file, $dst . '/' . $file);
                    } else {
                        copy($src . '/' . $file, $dst . '/' . $file);
                    }
                }
            }
        }
        closedir($dir);
    } elseif (is_file($src)) {
        if (!is_excluded(basename($src))) {
            copy($src, $dst);
        }
    }
}

function zipDir($folder, &$zip, $root = "")
{
    foreach (new \DirectoryIterator($folder) as $f) {
        if ($f->isDot()) {
            continue;
        }
        $src = $folder . '/' . $f;
        $dst = substr($f->getPathname(), strlen($root));
        if ($f->isDir()) {
            $zip->addEmptyDir($dst);
            zipDir($src, $zip, $root);
            continue;
        }
        if ($f->isFile()) {
            $zip->addFile($src, $dst);
        }
    }
}

// Check directory
if (!is_dir('vendor')) {
    echo "ERROR: 'vendor' directory not found.\n";
    exit(1);
}

list($mod, $version) = detectModule();
$outzip = sys_get_temp_dir() . "/module_" . $mod . "-" . $version . ".zip";
$tmpdir = tempnam(sys_get_temp_dir(), $mod . "-module");
if (file_exists($tmpdir)) {
    unlink($tmpdir);
}
mkdir($tmpdir);
$dst = $tmpdir . "/" . $mod;
mkdir($dst);

echo "Copying files to build directory...\n";
foreach ($list as $l) {
    foreach (glob($l) as $entry) {
        echo " -> $entry\n";
        rcopy($entry, $dst . '/' . $entry);
    }
}

if (file_exists($outzip)) {
    unlink($outzip);
}

echo "Compressing...\n";
$z = new ZipArchive();
if ($z->open($outzip, ZIPARCHIVE::CREATE) !== true) {
    echo "Error: Could not create $outzip\n";
    exit(3);
}
zipDir($tmpdir, $z, $tmpdir . '/');
$z->close();

echo "Cleaning up...\n";
delTree($tmpdir);

if (file_exists($outzip)) {
    $size = round(filesize($outzip) / 1024 / 1024, 2);
    echo "\n===============================================\n";
    echo " SUCCESS: Module archive created\n";
    echo " Install it via Dolibarr in the\n";
    echo " Setup -> Modules -> Deploy tab\n";
    echo " Path:    $outzip\n";
    echo " Size:    $size MB\n";
} else {
    echo "\nError: Zip file was not created.\n";
    exit(3);
}
