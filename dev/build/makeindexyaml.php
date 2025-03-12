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
        }
        $combinedContent .= $content . "\n";
    }
    file_put_contents($outputFile, $combinedContent);
}

$directoryToSearch = realpath(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..');
$outputFile = $directoryToSearch . DIRECTORY_SEPARATOR . 'index.yaml';

$yamlFiles = findIndexYamlFiles($directoryToSearch, 2);

// Exclude the output file from the list of files to combine
$yamlFiles = array_filter($yamlFiles, function($file) use ($outputFile) {
    return $file != $outputFile;
});

combineYamlFiles($yamlFiles, $outputFile);

print "Combined index.yaml file created at: " . $outputFile;

