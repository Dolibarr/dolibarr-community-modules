<?php

/*
* This file is a part of horstoeko/mimedb.
*
* For the full copyright and license information, please view the LICENSE
* file that was distributed with this source code.
*/

namespace horstoeko\mimedb;

/**
 * Class representing the mime repository
 *
 * @category MimeDb
 * @package  MimeDb
 * @author   D. Erling <horstoeko@erling.com.de>
 * @license  https://opensource.org/licenses/MIT MIT
 * @link     https://github.com/horstoeko/mimedb
 */
class MimeDb
{
    /**
     * Instance
     *
     * @var MimeDb
     */
    protected static $singleton = null;

    /**
     * The internal mime database
     *
     * @var array
     */
    protected $mimeDatabase = [];

    /**
     * Create instance (Singleton pattern)
     *
     * @return MimeDb
     */
    public static function singleton(): MimeDb
    {
        if (is_null(static::$singleton)) {
            static::$singleton = new MimeDb();
        }

        return static::$singleton;
    }

    /**
     * Load mime db
     *
     * @return MimeDb
     */
    protected function initializeDatabase(): MimeDb
    {
        if (!$this->loadedDatabase()) {
            $this->loadDatabase();
        }

        return $this;
    }

    /**
     * Returns true if the internal database was already loaded
     *
     * @return boolean
     */
    protected function loadedDatabase(): bool
    {
        return !empty($this->mimeDatabase);
    }

    /**
     * Load database file
     *
     * @return MimeDb
     */
    protected function loadDatabase(): MimeDb
    {
        $this->mimeDatabase = json_decode(file_get_contents($this->getDbFilename()), true);
        $this->mimeDatabase = array_filter(
            $this->mimeDatabase,
            function ($mimetypeDefinition) {
                return isset($mimetypeDefinition['extensions']) && !empty($mimetypeDefinition['extensions']);
            }
        );

        return $this;
    }

    /**
     * Get first mimetype by file extension
     *
     * @param  string $lookupFileExtension
     * @return string|null
     */
    public function findFirstMimeTypeByExtension(string $lookupFileExtension): ?string
    {
        $mimeTypes = $this->findAllMimeTypesByExtension($lookupFileExtension);

        if (is_null($mimeTypes)) {
            return null;
        }

        return $mimeTypes[0];
    }

    /**
     * Get all mimetypes by file extension
     *
     * @param  string $lookupFileExtension
     * @return array|null
     */
    public function findAllMimeTypesByExtension(string $lookupFileExtension): ?array
    {
        $this->initializeDatabase();

        $foundDbEntries = array_filter(
            $this->mimeDatabase,
            function ($mimetypeDefinition) use ($lookupFileExtension) {
                return in_array(strtolower(ltrim($lookupFileExtension, ".")), $mimetypeDefinition['extensions']);
            }
        );

        if (count($foundDbEntries) === 0) {
            return null;
        }

        return array_keys($foundDbEntries);
    }

    /**
     * Get first file extension by mimetype
     *
     * @param  string $lookupMimeType
     * @return string|null
     */
    public function findFirstFileExtensionByMimeType(string $lookupMimeType): ?string
    {
        $fileExtensions = $this->findAllFileExtensionsByMimeType($lookupMimeType);

        if (is_null($fileExtensions)) {
            return null;
        }

        return $fileExtensions[0];
    }

    /**
     * Get all file extensions by mimetype
     *
     * @param  string $lookupMimeType
     * @return array|null
     */
    public function findAllFileExtensionsByMimeType(string $lookupMimeType): ?array
    {
        $this->initializeDatabase();

        $foundDbEntries = array_filter(
            $this->mimeDatabase,
            function ($_, $mimetype) use ($lookupMimeType) {
                return strcasecmp($mimetype, $lookupMimeType) === 0;
            },
            ARRAY_FILTER_USE_BOTH
        );

        if (reset($foundDbEntries) === false) {
            return null;
        }

        return current($foundDbEntries)["extensions"];
    }

    /**
     * Returns the full-qualified filename where
     * the database is located
     *
     * @return             string
     * @codeCoverageIgnore
     */
    private function getDbFilename(): string
    {
        return dirname(__FILE__) . "/assets/mimetypes.json";
    }
}
