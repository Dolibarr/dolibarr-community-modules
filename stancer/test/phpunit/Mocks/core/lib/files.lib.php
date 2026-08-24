<?php
/**
 * Mock files.lib.php for PHPUnit tests
 */

if (!function_exists('dol_mkdir')) {
    function dol_mkdir($dir, $dataroot = '', $newmask = '') {
        if (!is_dir($dir)) {
            return @mkdir($dir, 0755, true);
        }
        return true;
    }
}

if (!function_exists('dol_copy')) {
    function dol_copy($srcfile, $destfile, $newmask = 0, $overwriteifexists = 1, $testvirus = 0, $indexdatabase = 0) {
        return @copy($srcfile, $destfile) ? 1 : -1;
    }
}

if (!function_exists('dol_delete_file')) {
    function dol_delete_file($file, $disableglob = 0, $nophperrors = 0, $nohook = 0, $object = null, $allowdotdot = false, $indexdatabase = 1, $nolog = 0) {
        if (file_exists($file)) {
            return @unlink($file) ? 1 : -1;
        }
        return 0;
    }
}

if (!function_exists('dol_move')) {
    function dol_move($srcfile, $destfile, $newmask = 0, $overwriteifexists = 1, $testvirus = 0, $indexdatabase = 1, $moreinfo = array()) {
        return @rename($srcfile, $destfile) ? 1 : -1;
    }
}

if (!function_exists('dol_filemtime')) {
    function dol_filemtime($pathoffile) {
        return @filemtime($pathoffile);
    }
}

if (!function_exists('dol_filesize')) {
    function dol_filesize($pathoffile) {
        return @filesize($pathoffile);
    }
}
