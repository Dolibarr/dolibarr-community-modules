#!/usr/bin/env php
<?php
/*
 * Copyright (C) 2025  Mohamed Daoud           <mdaoud@dolicloud.com>
 * Copyright (C) 2025  Laurent Destailleur     <eldy@users.sourceforge.net>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * This script searches through all directories for files named 'index.yaml'
 * and combines their contents into a single 'index.yaml' file.
 */

/**
 * Recursively searches directories for 'index.yaml' files.
 *
 * @param   string      $dir Directory to search.
 * @param   int         $level Max depth of directories to search.
 * @param   array       $results Array to store found file paths.
 * @param   int         $currentLevel Current depth level of the search.
 * @return  array       Paths of found 'index.yaml' files.
 */
function findIndexYamlFiles($dir, $level = 1, &$results = array(), $currentLevel = 1) {
    if ($currentLevel > $level) {
        return $results;
    }

    $files = scandir($dir);

    foreach ($files as $key => $value) {
        $path = realpath($dir . DIRECTORY_SEPARATOR . $value);
        if (!is_dir($path)) {
            if (basename($path) == 'index.yaml') {
                $results[] = $path;
            }
        } elseif ($value != "." && $value != "..") {
            findIndexYamlFiles($path, $level, $results, $currentLevel + 1);
        }
    }

    return $results;
}

/**
 * Combines the contents of multiple YAML files into a single file index.yaml by updating substitution keys.
 *
 * @param   array   $files Array of file paths to combine.
 * @param   string  $outputFile Path of the output file.
 * @return	void
 */
function combineYamlFiles($files, $outputFile) {
    $combinedContent = '';
    foreach ($files as $file) {
    	print "--- Process file ".$file."\n";
        $content = file_get_contents($file);

        if ($content) {
	        // Remove any text before the first occurrence of 'packages:' for all files except the first one
    	    if ($file !== $files[0]) {
        		// Remove any text before the first occurrence of 'packages:' for all files except the first one
	        	$content = preg_replace('/^.*?(?=packages:\s*)/s', '', $content);
    	    }

	        // remove the first line of the file
	        $content = preg_replace('/^.+\n/', '', $content);

	        // Complete auto tags
	        $content = completAutoTags($content, dirname($file));

        	$combinedContent .= $content . "\n";
        } else {
        	print "Failed to get content of yaml source file\n";
        }
    }
    file_put_contents($outputFile, $combinedContent);
}

/**
 * Completes auto tags in the YAML content.
 *
 * @param   string  $content        YAML content.
 * @param   string  $modulePath     Path of the module directory.
 * @return  string  Modified YAML content.
 */
function completAutoTags($content, $modulePath) {
    // Look for missing auto tags in the module's core class file
	$DOLIBARRMAXBYDEFAULT = '22.0';

    $tags = array(
        'current_version'   => 'version',
        'dolibarrmin'       => 'need_dolibarr_version',
        'dolibarrmax'       => 'max_dolibarr_version',
        'phpmin'            => 'phpmin',
        'phpmax'            => 'phpmax'
    );

	$modulename = '';
	$reg = array();
	if (preg_match('/modulename:\s*[\'"]([^\'"]+)[\'"]/', $content, $reg)) {
		$modulename = $reg[1];
	}
	if (empty($modulename)) {
		print "Can't extract module name from yaml file\n";
		return -1;
	}

	// Set the name of the descriptor module file
    $coreClassFile = $modulePath . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'modules' . DIRECTORY_SEPARATOR . 'mod' . $modulename . '.class.php';

    /**
     * Replaces only the double quotes that surround values in the given content.
     *
     * This function uses a regular expression to find patterns in the format of `: "value"`,
     * and replaces the double quotes with single quotes. Additionally, it replaces any
     * internal single quotes within the value with typographic apostrophes (’).
     *
     * @param string $content The content in which to perform the replacements.
     * @return string The modified content with the replacements made.
     */
    $content = preg_replace_callback('/:\s*"([^"]*)"/', function ($matches) {
        return ": '" . str_replace("'", "’", $matches[1]) . "'";
    }, $content);

    print "Process module path: ".$modulePath."\n";

    $coreClassContent = '';
    if (file_exists($coreClassFile)) {
    	print "Try to get local content of descriptor file ".$coreClassFile."\n";
        $coreClassContent = file_get_contents($coreClassFile);
        //print "Core class file content:\n$coreClassContent\n";
    } else {
    	// Try do get remote content.
    	$git = '';
		$reg = array();
		if (preg_match('/git:\s*[\'"]([^\'"]+)[\'"]/', $content, $reg)) {
			$git = $reg[1];
		}
		if (empty($git)) {
			print "Can't extract git url from yaml file\n";
			return -1;
		}

    	$urltoget = preg_replace('/https:\/\/github.com/', 'https://raw.githubusercontent.com', $git);
    	$urltoget = preg_replace('/\/tree\//', '/refs/heads/', $urltoget);
    	$urltoget .= '/core/modules/mod'.$modulename.'.class.php';
    	print "Try to get remote content of descriptor file ".$urltoget."\n";
		$coreClassContent = file_get_contents($urltoget);
		if (empty($coreClassContent)) {
			print "Failed to get remote content\n";
			return -1;
		}
    }

    if ($coreClassContent) {
        foreach ($tags as $tag => $property) {
            $value = '';

            // Case where the value is an array
            $matches = array();
            if (preg_match('/\$this->' . preg_quote($property) . '\s*=\s*array\(([^)]+)\)/', $coreClassContent, $matches)) {
                $value = trim($matches[1]);
                $value = preg_replace('/\s+/', '', $value); // Remove spaces
                $value = str_replace(',', '.', $value); // Replace commas with dots
                // Clean version x.y.z into x.y
                if (preg_match('/^(\d+\.\d+)\.[\-\d]+$/', $value, $reg)) {
                	$value = $reg[1];
                }
                print "Found array value for '$property': $value\n";
            }

            // Case where the value is a simple string
            elseif (preg_match('/\$this->' . preg_quote($property) . '\s*=\s*[\'"]([^\'"]+)[\'"]/', $coreClassContent, $matches)) {
                $value = trim($matches[1]);
                // Clean version x.y.z into x.y
                if (preg_match('/^(\d+\.\d+)\.[\-\d]+$/', $value, $reg)) {
                	$value = $reg[1];
                }
                print "Found string value for '$property': $value\n";
            }

            if (!empty($value)) {
                // Replace "auto" with the found value
                $content = preg_replace('/(' . preg_quote($tag) . ':\s*)["\']?auto["\']?/', "$1\"$value\"", $content);
                print "Replaced auto for '$tag' with value: $value\n";
            } else {
                // Remove "auto" if no value is found
                if ($tag == 'dolibarrmax') {
                	$content = preg_replace('/(' . preg_quote($tag) . ':\s*)["\']?auto["\']?/', "$1\"".$DOLIBARRMAXBYDEFAULT."\"", $content);
                	print "No value found for '$tag', replaced auto with ".$DOLIBARRMAXBYDEFAULT.".\n";
                } else {
                	$content = preg_replace('/(' . preg_quote($tag) . ':\s*)["\']?auto["\']?/', "$1\"\"", $content);
                	print "No value found for '$tag', replaced auto with empty string.\n";
                }
            }
        }
    } else {
        print "Core class file does not exist: $coreClassFile\n";
    }

    return $content;
}


$directoryToSearch = realpath(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..');
$outputFile = $directoryToSearch . DIRECTORY_SEPARATOR . 'index.yaml';

$yamlFiles = findIndexYamlFiles($directoryToSearch, 2);

// Exclude the output file from the list of files to combine
$yamlFiles = array_filter($yamlFiles, function($file) use ($outputFile) {
    return $file != $outputFile;
});

print "Found ".count($yamlFiles)." files to process.\n";

combineYamlFiles($yamlFiles, $outputFile);

print "Combined index.yaml file created at: " . $outputFile;
syslog(LOG_INFO, "Combined index.yaml file created at: " . $outputFile);

print "\n";
