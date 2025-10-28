<?php

/**
 * This file is a part of horstoeko/stringmanagement.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace horstoeko\stringmanagement;

/**
 * Class representing some string utilities
 *
 * @category StringManagement
 * @package  StringManagement
 * @author   D. Erling <horstoeko@erling.com.de>
 * @license  https://opensource.org/licenses/MIT MIT
 * @link     https://github.com/horstoeko/stringmanagement
 */
class StringUtils
{
    /**
     * Its like the almost known C#-Methods
     * Tests is string is not nul and has a value != ""
     *
     * @param  string|null $str
     * @return boolean
     */
    public static function stringIsNullOrEmpty(?string $str): bool
    {
        return (!isset($str) || trim($str) === '');
    }

    /**
     * Tests if an string $astring is starting with $astartswith
     * The parameter $acaseinsensitive controls wether the comparission is
     * case sensitive or not
     *
     * @param  string  $astring
     * @param  string  $astartswith
     * @param  boolean $acaseinsensitive
     * @return boolean
     */
    public static function strisstartingwith(string $astring, string $astartswith, bool $acaseinsensitive = false): bool
    {
        return $acaseinsensitive
            ? stripos($astring, $astartswith) === 0
            : strpos($astring, $astartswith) === 0;
    }
}
