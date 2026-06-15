<?php

/**
 * This file is a part of horstoeko/stringmanagement.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace horstoeko\stringmanagement;

/**
 * Class representing some string utilities for directories/paths
 *
 * @category StringManagement
 * @package  StringManagement
 * @author   D. Erling <horstoeko@erling.com.de>
 * @license  https://opensource.org/licenses/MIT MIT
 * @link     https://github.com/horstoeko/stringmanagement
 */
class PathUtils
{
    /**
     * Combine a path and a filename savely
     *
     * @param  string $path
     * @param  string $filename
     * @return string
     */
    public static function combinePathWithFile(string $path, string $filename): string
    {
        $path = rtrim($path, DIRECTORY_SEPARATOR);
        $filename = ltrim($filename, DIRECTORY_SEPARATOR);
        return $path . DIRECTORY_SEPARATOR . $filename;
    }

    /**
     * Combine two paths savely
     *
     * @param  string $path
     * @param  string $path2
     * @return string
     */
    public static function combinePathWithPath(string $path, string $path2): string
    {
        $path = rtrim($path, DIRECTORY_SEPARATOR);
        $path2 = ltrim($path2, DIRECTORY_SEPARATOR);
        return $path . DIRECTORY_SEPARATOR . $path2;
    }

    /**
     * Combine multiple paths
     *
     * @param  string ...$paths
     * @return string
     */
    public static function combineAllPaths(...$paths): string
    {
        if ($paths === []) {
            return "";
        }

        $result = array_shift($paths);

        foreach ($paths as $path) {
            $result = self::combinePathWithPath($result, $path);
        }

        return $result;
    }

    /**
     * Generate a hashed directory structur
     *
     * @param  integer $maxLevel
     * @return string
     */
    public static function getHashedDirectory(int $maxLevel = 1): string
    {
        if ($maxLevel <= 0) {
            throw new \Exception("The maximum level must be greater than zero");
        }

        $result = "";

        for ($level = 1; $level <= $maxLevel; $level++) {
            $result = self::combinePathWithPath($result, chr(random_int(97, 122)));
        }

        return $result;
    }

    /**
     * Create a hashed directory
     *
     * @param  string  $basedir
     * @param  integer $maxLevel
     * @param  integer $mode
     * @return boolean|string
     */
    public static function createHashedDirectory(string $basedir, int $maxLevel = 1, $mode = 0777)
    {
        $directory = self::combinePathWithPath($basedir, self::getHashedDirectory($maxLevel));

        if (is_dir($directory)) {
            return $directory;
        }

        if (mkdir($directory, $mode, true)) {
            return $directory;
        }

        return false;
    }

    /**
     * Remove directory in a recursive way
     *
     * @param  string $directory
     * @return void
     */
    public static function recursiveRemoveDirectory(string $directory): void
    {
        foreach (glob(self::combineAllPaths($directory, "/*")) as $file) {
            if (is_dir($file)) {
                self::recursiveRemoveDirectory($file);
            } else {
                unlink($file);
            }
        }

        rmdir($directory);
    }
}
