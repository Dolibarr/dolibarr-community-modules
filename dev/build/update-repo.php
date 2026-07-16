#!/usr/bin/env php
<?php
/*
 * Copyright (C) 2025-2026  Mohamed Daoud           <mdaoud@dolicloud.com>
 * Copyright (C) 2025-2026  Laurent Destailleur     <eldy@users.sourceforge.net>
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
function findIndexYamlFiles($dir, $level = 1, &$results = array(), $currentLevel = 1)
{
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
function combineYamlFiles($files, $outputFile)
{
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

			if ($content != '-1') {
				$combinedContent .= $content . "\n\n";
			} else {
				print "Failed to get value to replace into the yaml source file\n";
				$combinedContent .= "\n\n";
			}
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
function completAutoTags($content, $modulePath)
{
	// Look for missing auto tags in the module's core class file
	$DOLIBARRMAXBYDEFAULT = '24.0';

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

	// We extract data from the YAML file
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
			$urltoget = preg_replace('/\.git$/', '/refs/heads/main', $urltoget);
			$urltoget .= '/core/modules/mod'.$modulename.'.class.php';
		} elseif ($gitsystem == 'gitlab') {
			$urltoget = preg_replace('/\.git$/', '/-/raw/'.$gitbranch, $git);
			$urltoget .= '/core/modules/mod'.$modulename.'.class.php';
			$urltoget .= '?inline=false';
			// Example: 'https://mydomain.com/account/project/repo/-/blob/master/core/modules/modFacturx.class.php?ref_type=heads'
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

	$coreClassContent = preg_replace('/^\s*\/\/.*$/m', '', $coreClassContent);


	if ($coreClassContent) {
		// Update tags with a corresponding value found into the descriptor file
		foreach ($tagsToExtractFromDescriptor as $tag => $property) {
			if (preg_match('/(' . preg_quote($tag) . ':\s*)["\']?auto["\']?/', $content)) {	// If the key: is 'auto'
				$value = '';

				$matches = array();
				if (preg_match('/\$this->' . preg_quote($property) . '\s*=\s*array\(([^)]+)\)/', $coreClassContent, $matches)) {
					// Case where the value is an array
					$value = trim($matches[1]);
					$value = preg_replace('/\s+/', '', $value); // Remove spaces
					$value = str_replace(',', '.', $value); // Replace commas with dots
					print "Found array value for '$property': $value\n";
				} elseif (preg_match('/\$this->' . preg_quote($property) . '\s*=\s*[\'"]([^\'"]+)[\'"]/', $coreClassContent, $matches)) {
					// Case where the value is a simple string
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

		// Now update the created_at

		// Now update the last_updated_at
		$tag = 'last_updated_at';
		if (preg_match('/(' . preg_quote($tag) . ':\s*)["\']?auto["\']?/', $content)) {	// If the key: is 'auto'
			$value = "";
			$urltoget = "";

			// TODO Try to guess value from git sources
			if (empty($gitsystem) || $gitsystem == 'github') {
				$urltoget = preg_replace('/https:\/\/github.com/', 'https://api.github.com/repos', $git);
				$urltoget = preg_replace('/\/tree\/.*$/', '/commits?per_page=1&sha='.$gitbranch, $urltoget);
				$urltoget = preg_replace('/\.git$/', '/commits?per_page=1&sha='.$gitbranch, $urltoget);
			} elseif ($gitsystem == 'gitlab') {
				$urltoget = preg_replace('/\.git$/', '/-/commits/'.$gitbranch.'?format=atom', $git);
				//$urltoget = ' https://inligit.fr/cap-rel/dolibarr/plugin-peppol/-/raw/master/core/modules/modPeppol.class.php?inline=false https://gitlab.com/api/v4/projects/cap-rel/repository/commits?per_page=1&ref_name=$branch";
				// Example: 'https://mydomain.com/account/project/repo/-/blob/master/core/modules/modFacturx.class.php?ref_type=heads'
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

/**
 * build modules zip file if module sources are available into the repository.
 *
 * @return void
 */
function buildModulePackages()
{
	// list of files & dirs to include into the zip
	$listOfModuleContent = [
		'admin',
		'ajax',
		'assets',
		'public',
		'scripts',
		'vendor',
		'backport',
		'class',
		'css',
		'COPYING',
		'core',
		'img',
		'js',
		'langs',
		'lib',
		'sql',
		'tpl',
		'*.md',
		'*.json',
		'*.php',
		'modulebuilder.txt',
	];

	// Get path of module dir
	$directoryToSearch = realpath(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..'); // We are in dev/build, we want to go to root of repository
	$outputFile = $directoryToSearch . DIRECTORY_SEPARATOR . 'index.yaml';
	$yamlFiles = findIndexYamlFiles($directoryToSearch, 2);
	$yamlFiles = array_filter($yamlFiles, function ($file) use ($outputFile) {
		return $file != $outputFile; // Exclude the output file from the list of files to combine
	});

	// Get module lists from yaml files
	$projects = [];
	foreach ($yamlFiles as $yamlFile) {
		// Parse YAML file to get the name of the module
		$content = file_get_contents($yamlFile);
		$reg = array();
		if (preg_match('/modulename:\s*[\'"]([^\'"]+)[\'"]/', $content, $reg)) {
			$projects[] = strtolower($reg[1]);
		} else {
			print "Can't extract module name from yaml file: ".$yamlFile."\n";
			continue;
		}
	}

	print "\n";

	// For each module, we generate the zip file
	foreach ($projects as $project) {
		print "*** Build package for project: ".$project." in directory ".$directoryToSearch . DIRECTORY_SEPARATOR . $project."\n";

		// Change current dir to module dir, we will execute all next operations from this dir
		chdir($directoryToSearch . DIRECTORY_SEPARATOR . $project);

		list($mod, $version) = detectModule();
		if ($mod == "" || $version == "") {
			print "[fail] This repository does not contain a valid module sources, skipped.\n";
			print "\n";
			continue;
			// TODO : Try to retrieve zip from Dolistore or make a git clone and then generate the build from sources.
		}

		//  Define the name of the output zip file and remove it if already exists
		$outzip = $directoryToSearch . DIRECTORY_SEPARATOR . $project . DIRECTORY_SEPARATOR . "module_" . $mod . "-" . $version . ".zip";
		if (file_exists($outzip)) {
			secureUnlink($outzip);
		}

		//copy all sources into system temp directory
		$tmpdir = tempnam(sys_get_temp_dir(), $mod . "-module");
		secureUnlink($tmpdir);
		mkdirAndCheck($tmpdir);
		$dst = $tmpdir . "/" . $mod;
		mkdirAndCheck($dst);

		foreach ($listOfModuleContent as $moduleContent) {
			foreach (glob($moduleContent) as $entry) {
				if (!rcopy($entry, $dst . '/' . $entry)) {
					print "[fail]  Error on copy " . $entry . " to " . $dst . "/" . $entry . " for project: ".$project."\n";
					print "Please take time to analyze the problem and fix the bug\n";
					print "\n";
					continue 3; // Skip to the next project if copy fails.
				}
			}
		}

		$z = new ZipArchive();
		$z->open($outzip, ZIPARCHIVE::CREATE);
		zipDir($tmpdir, $z, $tmpdir . '/');
		$z->close();
		delTree($tmpdir);
		if (file_exists($outzip)) {
			print "[success] module archive is ready : $outzip ...\n";
			print "\n";
		} else {
			print "[fail] build zip error\n";
			continue;
			print "\n";
		}
	}
}

/**
 * auto detect module name and version from file name
 *
 * @return  (string|string)[] module name and module version
 */
function detectModule()
{
	$name  = $version = "";
	$tab = glob("core/modules/mod*.class.php");
	if (count($tab) == 0) {
		print "[fail] Error on auto detect data : there is no mod*.class.php file into core/modules dir\n";
		return ["", ""];
	}
	if (count($tab) == 1) {
		$file = $tab[0];
		$pattern = "/.*mod(?<mod>.*)\.class\.php/";
		if (preg_match_all($pattern, $file, $matches)) {
			$name = strtolower(reset($matches['mod']));
		}

		print "extract data from $file\n";
		if (!file_exists($file) || $name == "") {
			print "[fail] Error on auto detect data\n";
			return ["", ""];
		}
	} else {
		print "[fail] Error there is more than one mod*.class.php file into core/modules dir\n";
		return ["", ""];
	}

	//extract version from file
	$contents = file_get_contents($file);
	$pattern = "/^.*this->version\s*=\s*'(?<version>.*)'\s*;.*\$/m";

	// search, and store all matching occurrences in $matches
	if (preg_match_all($pattern, $contents, $matches)) {
		$version = reset($matches['version']);
	}

	if (version_compare($version, '0.0.1', '>=') != 1) {
		print "[fail] Error auto extract version fail\n";
		return ["", ""];
	}

	print "module name = $name, version = $version\n";
	return [(string) $name, (string) $version];
}

/**
 * delete recursively a directory
 *
 * @param   string  $dir  dir path to delete
 *
 * @return bool true on success or false on failure.
 */
function delTree($dir)
{
	$files = array_diff(scandir($dir), array('.', '..'));
	foreach ($files as $file) {
		(is_dir("$dir/$file")) ? delTree("$dir/$file") : secureUnlink("$dir/$file");
	}
	return rmdir($dir);
}


/**
 * do a secure delete file/dir with double check
 * (don't trust unlink return)
 *
 * @param   string  $path  full path to delete
 *
 * @return bool true on success ($path does not exists at the end of process), else exit
 */
function secureUnlink($path)
{
	if (file_exists($path)) {
		if (unlink($path)) {
			//then check if really deleted
			clearstatcache();
			if (file_exists($path)) {	// @phpstan-ignore-line
				print "[fail] unlink of $path fail !\n";
				exit(2);
			}
		} else {
			print "[fail] unlink of $path fail !\n";
			exit(2);
		}
	}
	return true;
}

/**
 * create a directory and check if dir exists
 *
 * @param   string  $path  path to make
 *
 * @return bool true on success ($path exists at the end of process), else exit
 */
function mkdirAndCheck($path)
{
	if (mkdir($path)) {
		clearstatcache();
		if (is_dir($path)) {
			return true;
		}
	}
	print "[fail] Error on $path (mkdir)\n";
	exit(3);
}

/**
 * check if that filename is concerned by exclude filter
 *
 * @param   string  $filename  file name to check
 *
 * @return bool true if file is in excluded list
 */
function is_excluded($filename)
{
	/**
	 * if you want to exclude some files from your zip
	 */
	$exclude_list = [
		'/^.git$/',
		'/.*js.map/',
		'/DEV.md/'
	];

	$count = 0;
	$notused = preg_filter($exclude_list, '1', $filename, -1, $count);
	if ($count > 0) {
		print " - exclude $filename\n";
		return true;
	}
	return false;
}

/**
 * recursive copy files & dirs
 *
 * @param   string  $src  source dir
 * @param   string  $dst  target dir
 *
 * @return bool true on success or false on failure.
 */
function rcopy($src, $dst)
{
	if (is_dir($src)) {
		// Make the destination directory if not exist
		mkdirAndCheck($dst);
		// open the source directory
		$dir = opendir($src);

		// Loop through the files in source directory
		while ($file = readdir($dir)) {
			if (($file != '.') && ($file != '..')) {
				if (is_dir($src . '/' . $file)) {
					// Recursively calling custom copy function
					// for sub directory
					if (!rcopy($src . '/' . $file, $dst . '/' . $file)) {
						return false;
					}
				} else {
					if (!is_excluded($file)) {
						if (!copy($src . '/' . $file, $dst . '/' . $file)) {
							return false;
						}
					}
				}
			}
		}
		closedir($dir);
	} elseif (is_file($src)) {
		if (!is_excluded($src)) {
			if (!copy($src, $dst)) {
				return false;
			}
		}
	}
	return true;
}

/**
 * build a zip file from a folder
 *
 * @param   string  	$folder  folder to use as zip root
 * @param   ZipArchive  $zip     zip object (ZipArchive)
 * @param   string  	$root    relative root path into the zip
 *
 * @return bool true on success or false on failure.
 */
function zipDir($folder, &$zip, $root = "")
{
	foreach (new \DirectoryIterator($folder) as $f) {
		if ($f->isDot()) {
			continue;
		} //skip . ..
		$src = $folder . '/' . $f;
		$dst = substr($f->getPathname(), strlen($root));
		if ($f->isDir()) {
			if ($zip->addEmptyDir($dst)) {
				if (zipDir($src, $zip, $root)) {
					continue;
				} else {
					return false;
				}
			} else {
				return false;
			}
		}
		if ($f->isFile()) {
			if (! $zip->addFile($src, $dst)) {
				return false;
			}
		}
	}
	return true;
}


// Main

$sapi_type = php_sapi_name();
$script_file = basename(__FILE__);
$path = dirname(__FILE__).'/';

print "----- ".$script_file." -----\n";

// Test if batch mode
if (substr($sapi_type, 0, 3) == 'cgi') {
	print "Error: You are using PHP for CGI. To execute ".$script_file." from command line, you must use PHP for CLI mode.\n";
	exit(1);
}

// Test if zip extension is loaded
if (!extension_loaded('zip')) {
	print "Error: PHP extension 'zip' is not loaded. To execute ".$script_file." you must have this extension loaded.\n";
	exit(1);
}

if (empty($argv[1])) {
	print "Usage:   ".$script_file." index|makezip|pushdolistore\n";
	print "Example: ".$script_file." index      	to rebuild the index.yaml file (used by Dolibarr to retrieve list of community modules)\n";
	print "Example: ".$script_file." makezip      	to regenerate zip of packages \n";
	print "Example: ".$script_file." pushdolistore  to regenerate zip of packages and publish them on dolistore (TODO)\n";
	print "\n";
	exit(1);
}


if ($argv[1] == 'index') {
	$directoryToSearch = realpath(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..');
	$outputFile = $directoryToSearch . DIRECTORY_SEPARATOR . 'index.yaml';

	$yamlFiles = findIndexYamlFiles($directoryToSearch, 2);

	// Exclude the output file from the list of files to combine
	$yamlFiles = array_filter($yamlFiles, function ($file) use ($outputFile) {
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

if ($argv[1] == 'makezip') {
	// For each module, we generate the zip file
	buildModulePackages();
	print "All done.\n";
}

print "\n";
