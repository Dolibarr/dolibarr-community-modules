<?php
/**
 * Mock CommonObjectLine class for PHPUnit tests
 */

if (!class_exists('CommonObjectLine')) {
    class CommonObjectLine
    {
        public $id;
        public $rowid;
        public $error = '';
        public $errors = [];

        public function __construct($db = null)
        {
        }
    }
}
