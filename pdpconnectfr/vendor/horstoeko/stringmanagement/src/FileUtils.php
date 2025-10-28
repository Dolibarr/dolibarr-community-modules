<?php

/**
 * This file is a part of horstoeko/stringmanagement.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace horstoeko\stringmanagement;

use horstoeko\stringmanagement\StringUtils;
use horstoeko\stringmanagement\PathUtils;

/**
 * Class representing some string utilities for files
 *
 * @category StringManagement
 * @package  StringManagement
 * @author   D. Erling <horstoeko@erling.com.de>
 * @license  https://opensource.org/licenses/MIT MIT
 * @link     https://github.com/horstoeko/stringmanagement
 */
class FileUtils
{
    /**
     * Check if file $filename exists, also checks if it's readable
     * To turn off the readble check set $checkreadable to false
     *
     * @param  string  $filename
     * @param  boolean $checkreadable
     * @return boolean
     */
    public static function fileExists(string $filename, bool $checkreadable = true): bool
    {
        $exists = file_exists($filename);

        if ($exists && $checkreadable) {
            return is_readable($filename);
        }

        return $exists;
    }

    /**
     * Converts a file $filename to base64 string. If the file does not exist
     * this function returns false
     *
     * @param  string $filename
     * @return boolean|string
     */
    public static function fileToBase64(string $filename)
    {
        if (self::fileExists($filename) === false) {
            return false;
        }

        $data = file_get_contents($filename);

        if ($data === false) {
            return false;
        }

        $data = base64_encode($data);

        if (StringUtils::stringIsNullOrEmpty($data)) {
            return false;
        }

        return $data;
    }

    /**
     * Converts the content of a file to BASE64 encoded file
     *
     * @param  string $filename
     * Source filename
     * @param  string $toFilename
     * Filename to which the BASE64 is saved to
     * @return boolean
     */
    public static function fileToBase64File(string $filename, string $toFilename): bool
    {
        $base64String = static::fileToBase64($filename);

        if ($base64String === false) {
            return false;
        }

        return file_put_contents($toFilename, $base64String) !== false;
    }

    /**
     * Decodes a base64 string and saves it to file
     *
     * @param  string $base64String
     * The base64 encoded data
     * @param  string $toFilename
     * Filename to which the binary (=decoded base64) data is saved to
     * @return boolean
     */
    public static function base64ToFile(string $base64String, string $toFilename): bool
    {
        return file_put_contents($toFilename, base64_decode($base64String)) !== false;
    }

    /**
     * Decodes a file which is containing base64 data to another file
     *
     * @param  string $filename
     * Name of the file which contains base64 data
     * @param  string $toFilename
     * Filename where the decoded base64 data are stored to
     * @return boolean
     */
    public static function base64FileToFile(string $filename, string $toFilename): bool
    {
        if (self::fileExists($filename) === false) {
            return false;
        }

        $base64String = file_get_contents($filename);

        if ($base64String === false) {
            return false;
        }

        return self::base64ToFile($base64String, $toFilename);
    }

    /**
     * Combine a filename (which has no extension) with a fileextension
     *
     * @param  string $filename
     * @param  string $fileextension
     * @return string
     */
    public static function combineFilenameWithFileextension(string $filename, string $fileextension): string
    {
        $extensionDelimiter = ".";
        $filename = rtrim($filename, $extensionDelimiter);
        $fileextension = ltrim($fileextension, $extensionDelimiter);
        return $filename . $extensionDelimiter . $fileextension;
    }

    /**
     * Returns the directory where $filename is located
     *
     * @param  string $filename
     * @return string
     */
    public static function getFileDirectory(string $filename): string
    {
        return pathinfo($filename, PATHINFO_DIRNAME);
    }

    /**
     * Returns the filename only including it's extension
     *
     * @param  string $filename
     * @return string
     */
    public static function getFilenameWithExtension(string $filename): string
    {
        return pathinfo($filename, PATHINFO_BASENAME);
    }

    /**
     * Returns the filename only without it's extension
     *
     * @param  string $filename
     * @return string
     */
    public static function getFilenameWithoutExtension(string $filename): string
    {
        return pathinfo($filename, PATHINFO_FILENAME);
    }

    /**
     * Returns the fileextension of a $filename, Optionally you can
     * add the dot on the beginning
     *
     * @param  string  $filename
     * @param  boolean $withdot
     * @return string
     */
    public static function getFileExtension(string $filename, bool $withdot = false): string
    {
        $extension = ltrim(pathinfo($filename, PATHINFO_EXTENSION), ".");

        if ($withdot) {
            return "." . $extension;
        }

        return $extension;
    }

    /**
     * Change the extension of a filename
     *
     * @param  string $filename
     * @param  string $newFileextension
     * @return string
     */
    public static function changeFileExtension(string $filename, string $newFileextension): string
    {
        $directory = static::getFileDirectory($filename);
        $filename = static::getFilenameWithoutExtension($filename);

        return PathUtils::combinePathWithFile(
            $directory,
            static::combineFilenameWithFileextension(
                $filename,
                $newFileextension
            )
        );
    }

    /**
     * Returns the size of the file $filename
     *
     * @param  string $filename
     * @return integer
     */
    public static function getFileSize(string $filename): int
    {
        if (!self::fileExists($filename)) {
            return 0;
        }

        return filesize($filename);
    }

    /**
     * Get the file real file size of a base64 encoded string
     *
     * @param  string $base64string
     * @return integer
     */
    public static function getFileSizeFromBase64String(string $base64string): int
    {
        $decodedBase64String = base64_decode($base64string);

        if ($decodedBase64String == false) {
            return 0;
        }

        return strlen($decodedBase64String);
    }
}
