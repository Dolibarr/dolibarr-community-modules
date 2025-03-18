<?php
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
 *
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
 * Combines the contents of multiple YAML files into a single file index.yaml.
 *
 * @param   array   $files Array of file paths to combine.
 * @param   string  $outputFile Path of the output file.
 */
function combineYamlFiles($files, $outputFile) {
    $combinedContent = '';
    foreach ($files as $file) {
        $content = file_get_contents($file);
        // Remove any text before the first occurrence of 'packages:' for all files except the first one
        if ($file !== $files[0]) {
            $content = preg_replace('/^.*?(?=packages:\s*)/s', '', $content);

            // remove the first line of the file
            $content = preg_replace('/^.+\n/', '', $content);

            // Complet auto tags
            $content = completAutoTags($content, dirname($file));
        }
        $combinedContent .= $content . "\n";
    }
    file_put_contents($outputFile, $combinedContent);
}

/**
 * Completes auto tags in the YAML content.
 *
 * @param   string  $content        YAML content.
 * @param   string  $modulePath     Path of the module directory.
 *
 * @return  string  Modified YAML content.
 */
function completAutoTags($content, $modulePath) {
    // Look for missing auto tags in the module's core class file
    $coreClassFile = $modulePath . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'modules' . DIRECTORY_SEPARATOR . 'mod' . basename($modulePath) . '.class.php';

    $tags = array(
        'current_version'   => 'version',
        'dolibarrmin'       => 'need_dolibarr_version',
        'dolibarrmax'       => 'max_dolibarr_version',
        'phpmin'            => 'phpmin',
        'phpmax'            => 'phpmax'
    );

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

    if (file_exists($coreClassFile)) {
        $coreClassContent = file_get_contents($coreClassFile);

        foreach ($tags as $tag => $property) {
            $value = '';

            // Case where the value is an array
            if (preg_match('/\$this->' . preg_quote($property) . '\s*=\s*array\(([^)]+)\)/', $coreClassContent, $matches)) {
                $value = trim($matches[1]);
                $value = preg_replace('/\s+/', '', $value); // Remove spaces
                $value = str_replace(',', '.', $value); // Replace commas with dots
            }

            // Case where the value is a simple string
            elseif (preg_match('/\$this->' . preg_quote($property) . '\s*=\s*[\'"]([^\'"]+)[\'"]/', $coreClassContent, $matches)) {
                $value = trim($matches[1]);
            }

            if (!empty($value)) {
                // Replace "auto" with the found value
                $content = preg_replace('/(' . preg_quote($tag) . ':\s*)["\']?auto["\']?/', "$1\"$value\"", $content);
            } else {
                // Remove "auto" if no value is found
                $content = preg_replace('/(' . preg_quote($tag) . ':\s*)["\']?auto["\']?/', "$1\"\"", $content);
            }
        }
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

combineYamlFiles($yamlFiles, $outputFile);

syslog(LOG_INFO, "Combined index.yaml file created at: " . $outputFile);

