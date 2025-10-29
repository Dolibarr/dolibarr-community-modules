<?php

namespace horstoeko\stringmanagement\tests;

use \PHPUnit\Framework\TestCase;
use \horstoeko\stringmanagement\StringUtils;

class StringUtilsTest extends TestCase
{
    /**
     * @covers \horstoeko\stringmanagement\StringUtils::stringIsNullOrEmpty
     */
    public function testStringIsNullOrEmpty(): void
    {
        $this->assertTrue(StringUtils::stringIsNullOrEmpty(null));
        $this->assertTrue(StringUtils::stringIsNullOrEmpty(""));
        $this->assertTrue(StringUtils::stringIsNullOrEmpty("  "));
        $this->assertFalse(StringUtils::stringIsNullOrEmpty("abc"));
        $this->assertFalse(StringUtils::stringIsNullOrEmpty(" abc "));
        $this->assertFalse(StringUtils::stringIsNullOrEmpty(222));
    }

    /**
     * @covers \horstoeko\stringmanagement\StringUtils::strisstartingwith
     */
    public function testStrisstartingwith(): void
    {
        $this->assertTrue(StringUtils::strisstartingwith("abcdef", "abc"));
        $this->assertFalse(StringUtils::strisstartingwith("abcdef", "ABC"));
        $this->assertTrue(StringUtils::strisstartingwith("abcdef", "ABC", true));
        $this->assertFalse(StringUtils::strisstartingwith("abcdef", "def"));
        $this->assertFalse(StringUtils::strisstartingwith("abcdef", "def", true));
    }
}
