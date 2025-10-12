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

    $tagsToExtractFromDescriptor = array(
        'current_version'   => 'version',
        'dolibarrmin'       => 'need_dolibarr_version',
        'dolibarrmax'       => 'max_dolibarr_version',
        'phpmin'            => 'phpmin',
        'phpmax'            => 'phpmax',
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

	// Set the name of the local descriptor module file
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

    $git = '';
    $gitbranch = '';
	$gitsystem = '';

   	// We extract data fro mthe YAML file
   	$reg = array();
	if (preg_match('/git:\s*[\'"]([^\'"]+)[\'"]/', $content, $reg)) {
		$git = $reg[1];
	}
	if (empty($git)) {
		print "Can't extract git url from yaml file\n";
		return -1;
	}
	if (preg_match('/git-branch:\s*[\'"]([^\'"]+)[\'"]/', $content, $reg)) {
   		$gitbranch = $reg[1];
	}
	if (preg_match('/git-system:\s*[\'"]([^\'"]+)[\'"]/', $content, $reg)) {
   		$gitsystem = $reg[1];
	}

    $coreClassContent = '';
    if (file_exists($coreClassFile)) {
    	print "Try to get local content of descriptor file ".$coreClassFile."\n";
        $coreClassContent = file_get_contents($coreClassFile);
        //print "Core class file content:\n$coreClassContent\n";
    } else {
    	// Try do get remote content.
		// Define the URL to get the descriptor file.
		// For github sources
		if (empty($gitsystem) || $gitsystem == 'github') {
	    	$urltoget = preg_replace('/https:\/\/github.com/', 'https://raw.githubusercontent.com', $git);
    		$urltoget = preg_replace('/\/tree\//', '/refs/heads/', $urltoget);
    		$urltoget .= '/core/modules/mod'.$modulename.'.class.php';
		} elseif ($gitsystem == 'gitlab') {
			$urltoget = preg_replace('/\.git$/', '/-/raw/'.$gitbranch, $git);
    		$urltoget .= '/core/modules/mod'.$modulename.'.class.php';
    		$urltoget .= '?inline=false';
    		// Example: 'https://mydomain.com/accoun/project/repo/-/blob/master/core/modules/modFacturx.class.php?ref_type=heads'
		}

    	print "Try to get remote content of descriptor file ".$urltoget." (url guessed from ".$git.")\n";
		$coreClassContent = file_get_contents($urltoget);
		if (empty($coreClassContent)) {
			print "Failed to get remote content descriptor file.\n";
			return -1;
		} else {
			print "Success in getting remote content descriptor file.\n";
		}
    }

    if ($coreClassContent) {
    	// Update tags with a corresponding value found into the descriptor file
        foreach ($tagsToExtractFromDescriptor as $tag => $property) {
        	if (preg_match('/(' . preg_quote($tag) . ':\s*)["\']?auto["\']?/', $content)) {	// If the key: is 'auto'
	            $value = '';

	            // Case where the value is an array
	            $matches = array();
	            if (preg_match('/\$this->' . preg_quote($property) . '\s*=\s*array\(([^)]+)\)/', $coreClassContent, $matches)) {
	                $value = trim($matches[1]);
	                $value = preg_replace('/\s+/', '', $value); // Remove spaces
	                $value = str_replace(',', '.', $value); // Replace commas with dots
	                print "Found array value for '$property': $value\n";
	            }

	            // Case where the value is a simple string
	            elseif (preg_match('/\$this->' . preg_quote($property) . '\s*=\s*[\'"]([^\'"]+)[\'"]/', $coreClassContent, $matches)) {
	                $value = trim($matches[1]);
	                print "Found string value for '$property': $value\n";
	            }

                // Clean version x.y.z into x.y
	            if (in_array($tag, array('dolibarrmin', 'dolibarrmax', 'phpmin', 'phpmax'))) {
	                if (preg_match('/^(\d+\.\d+)\.[\-\d\*]+$/', $value, $reg)) {
	                	$value = $reg[1];
	                }
	                // Clean version x.-y into x.0
	                if (preg_match('/^(\d+)\.\-\d+.*$/', $value, $reg)) {
	                	$value = $reg[1].'.0';
	                }
	            }

	            if (!empty($value)) {
	                // Replace "auto" with the found value
	                $content = preg_replace('/(' . preg_quote($tag) . ':\s*)["\']?auto["\']?/', "$1\"$value\"", $content);
	                print "Replaced auto for '$tag' with value: $value\n";
	            } else {
	                // Remove "auto" if no value is found
	                if ($tag == 'dolibarrmax') {
	                	$content = preg_replace('/(' . preg_quote($tag) . ':\s*)["\']?auto["\']?/', "$1\"".$DOLIBARRMAXBYDEFAULT."\"", $content);
	                	print "No value found for '$tag', replaced auto with ".$DOLIBARRMAXBYDEFAULT."\n";
	                } else {
	                	$content = preg_replace('/(' . preg_quote($tag) . ':\s*)["\']?auto["\']?/', "$1\"\"", $content);
	                	print "No value found for '$tag', replaced auto with empty string.\n";
	                }
	            }
        	} else {
        		// Nothing done, we keep value as in source file
        	}
        }

        // Now udate the created_at

        // Now udate the last_updated_at
        $tag = 'last_updated_at';
        if (preg_match('/(' . preg_quote($tag) . ':\s*)["\']?auto["\']?/', $content)) {	// If the key: is 'auto'
	    	$value = "";
			$urltoget = "";

	    	// TODO Try to guess value from git sources
	    	if (empty($gitsystem) || $gitsystem == 'github') {
		    	$urltoget = preg_replace('/https:\/\/github.com/', 'https://api.github.com/repos', $git);
	    		$urltoget = preg_replace('/\/tree\/.*$/', '/commits?per_page=1&sha='.$gitbranch, $urltoget);
			} elseif ($gitsystem == 'gitlab') {
				$urltoget = preg_replace('/\.git$/', '/-/commits/'.$gitbranch.'?format=atom', $git);
				//$urltoget = ' https://inligit.fr/cap-rel/dolibarr/plugin-peppol/-/raw/master/core/modules/modPeppol.class.php?inline=false https://gitlab.com/api/v4/projects/cap-rel/repository/commits?per_page=1&ref_name=$branch";
	    		// Example: 'https://mydomain.com/accoun/project/repo/-/blob/master/core/modules/modFacturx.class.php?ref_type=heads'
			}

			$commitContent = '';
	    	if ($urltoget) {
		    	print "Try to get remote commit list from ".$urltoget." (url guessed from ".$git.")\n";

		    	$options = [
				    "http" => [
        				"header" => "User-Agent: Update-Repo script\r\n\r\n"
    					]
					];
				$context = stream_context_create($options);

				$commitContent = file_get_contents($urltoget, false, $context);
				if (empty($commitContent)) {
					print "Failed to get remote commit list.\n";
					return -1;
				} else {
					print "Success in getting remote commit list.\n";

		    	    if (empty($gitsystem) || $gitsystem == 'github') {
		    	    	$commitContentarray = json_decode($commitContent);
		    	    	$datestring = $commitContentarray[0]->commit->committer->date;
					} elseif ($gitsystem == 'gitlab') {
						$xml = simplexml_load_string($commitContent);
						if ($xml) {
							$datestring = $xml->entry[0]->updated;
						}
					}
					$datestring = preg_replace('/T.*$/', '', $datestring);
					print "Replaced auto for '".$tag."' with value: ".$datestring."\n";

					// Replace "auto" with the found value
					$content = preg_replace('/(' . preg_quote($tag) . ':\s*)["\']?auto["\']?/', "$1\"".$datestring."\"", $content);
				}
	    	}
        }
    } else {
        print "Core class file does not exist: $coreClassFile\n";
    }

    return $content;
}


// Main

$sapi_type = php_sapi_name();
$script_file = basename(__FILE__);
$path = dirname(__FILE__).'/';

print "----- ".$script_file." -----\n";

// Test if batch mode
if (substr($sapi_type, 0, 3) == 'cgi') {
	echo "Error: You are using PHP for CGI. To execute ".$script_file." from command line, you must use PHP for CLI mode.\n";
	exit(1);
}

if (empty($argv[1])) {
	print "Usage:   ".$script_file." index|dolistore\n";
	print "Example: ".$script_file." index      to rebuild the index.yaml file (used by Dolibarr to scan community modules)\n";
	print "Example: ".$script_file." dolistore  to regenerate zip of packages and publish them on dolistore\n";
	exit(1);
}


if ($argv[1] == 'index') {
	$directoryToSearch = realpath(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..');
	$outputFile = $directoryToSearch . DIRECTORY_SEPARATOR . 'index.yaml';

	$yamlFiles = findIndexYamlFiles($directoryToSearch, 2);

	// Exclude the output file from the list of files to combine
	$yamlFiles = array_filter($yamlFiles, function($file) use ($outputFile) {
	    return $file != $outputFile;
	});

	print "Found ".count($yamlFiles)." yaml files to merge into the main index.yaml file.\n";

	combineYamlFiles($yamlFiles, $outputFile);

	print "\n";
	print "The combined index.yaml file was created at: " . $outputFile;
	syslog(LOG_INFO, "The combined index.yaml file was created at: " . $outputFile);
	print "\n";
}

if ($argv[1] == 'dolistore') {
	// TODO Ask the api key

	// Scan all modules, for each one, call the makepack.pl to regenerate the zip file then publish the file using the api key.

}

print "\n";
