<?php
/**
 * Mock FormFile class for PHPUnit tests
 */

if (!class_exists('FormFile')) {
    class FormFile
    {
        public function __construct($db = null)
        {
        }

        public function showdocuments($modulepart, $modulesubdir, $filedir, $urlsource, $genallowed, $delallowed = 0, $modelselected = '', $allowgenifempty = 1, $forcenomultilang = 0, $iconPDF = 0, $notused = 0, $noform = 0, $param = '', $title = '', $buttonlabel = '', $codelang = '')
        {
            return '';
        }
    }
}
