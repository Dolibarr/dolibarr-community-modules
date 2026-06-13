<?php

namespace horstoeko\stringmanagement\tests;

use \PHPUnit\Framework\TestCase;
use \horstoeko\stringmanagement\PathUtils;

class PathUtilsTest extends TestCase
{
    /**
     * @covers \horstoeko\stringmanagement\PathUtils::combinePathWithFile
     */
    public function testCombinePathWithFile(): void
    {
        $ds = DIRECTORY_SEPARATOR;

        $this->assertSame(sprintf('%shome%suser%stest.txt', $ds, $ds, $ds), PathUtils::combinePathWithFile(sprintf('%shome%suser', $ds, $ds), "test.txt"));
        $this->assertSame(sprintf('%shome%suser%stest.txt', $ds, $ds, $ds), PathUtils::combinePathWithFile(sprintf('%shome%suser%s', $ds, $ds, $ds), "test.txt"));
        $this->assertSame(sprintf('%shome%suser%stest.txt', $ds, $ds, $ds), PathUtils::combinePathWithFile(sprintf('%shome%suser', $ds, $ds), $ds . 'test.txt'));
    }

    /**
     * @covers \horstoeko\stringmanagement\PathUtils::combinePathWithPath
     */
    public function testCombinePathWithPath(): void
    {
        $ds = DIRECTORY_SEPARATOR;

        $this->assertSame(sprintf('%shome%suser', $ds, $ds), PathUtils::combinePathWithPath($ds . 'home', "user"));
        $this->assertSame(sprintf('%shome%suser', $ds, $ds), PathUtils::combinePathWithPath($ds . 'home', $ds . 'user'));
        $this->assertSame(sprintf('%shome%suser', $ds, $ds), PathUtils::combinePathWithPath(sprintf('%shome%s%s', $ds, $ds, $ds), $ds . 'user'));
        $this->assertSame(sprintf('%shome%suser', $ds, $ds), PathUtils::combinePathWithPath(sprintf('%shome%s%s', $ds, $ds, $ds), "user"));
    }

    /**
     * @covers \horstoeko\stringmanagement\PathUtils::combineAllPaths
     */
    public function testCombineAllPaths(): void
    {
        $ds = DIRECTORY_SEPARATOR;

        $this->assertSame(sprintf('home%suser%sjohn', $ds, $ds), PathUtils::combineAllPaths("home", "user", "john"));
        $this->assertSame(sprintf('%shome%suser%sjohn', $ds, $ds, $ds), PathUtils::combineAllPaths($ds . 'home', "user", "john"));
        $this->assertSame(sprintf('%shome%suser%sjohn', $ds, $ds, $ds), PathUtils::combineAllPaths($ds . 'home', $ds . 'user', "john"));
        $this->assertSame(sprintf('%shome%suser%sjohn', $ds, $ds, $ds), PathUtils::combineAllPaths($ds . 'home', $ds . 'user', $ds . 'john'));
        $this->assertSame(sprintf('%shome%suser%sjohn', $ds, $ds, $ds), PathUtils::combineAllPaths(sprintf('%shome%s%s', $ds, $ds, $ds), "user", "john"));
        $this->assertSame(sprintf('%shome%suser%sjohn', $ds, $ds, $ds), PathUtils::combineAllPaths(sprintf('%shome%s%s', $ds, $ds, $ds), sprintf('%suser%s%s', $ds, $ds, $ds), "john"));
        $this->assertSame(sprintf('%shome%suser%sjohn', $ds, $ds, $ds), PathUtils::combineAllPaths(sprintf('%shome%s%s', $ds, $ds, $ds), sprintf('%suser%s%s', $ds, $ds, $ds), $ds . 'john'));
    }

    /**
     * @covers \horstoeko\stringmanagement\PathUtils::getHashedDirectory
     */
    public function testGetHashedDirectory(): void
    {
        $this->assertNotSame("", PathUtils::getHashedDirectory(3));
        $this->assertSame(3, substr_count(PathUtils::getHashedDirectory(3), DIRECTORY_SEPARATOR));
        $this->assertStringStartsWith(DIRECTORY_SEPARATOR, PathUtils::getHashedDirectory(3));
        $this->assertStringEndsNotWith(DIRECTORY_SEPARATOR, PathUtils::getHashedDirectory(3));
        $this->expectException(\Exception::class);
        PathUtils::getHashedDirectory(0);
    }

    /**
     * @covers \horstoeko\stringmanagement\PathUtils::createHashedDirectory
     * @covers \horstoeko\stringmanagement\PathUtils::recursiveRemoveDirectory
     */
    public function testCreateHashedDirectory(): void
    {
        $ds = DIRECTORY_SEPARATOR;

        $baseDirectory = PathUtils::combineAllPaths(__DIR__, "test");
        $createdDirectory = PathUtils::createHashedDirectory($baseDirectory, 3);

        $this->assertIsString($createdDirectory);
        $this->assertNotSame("", PathUtils::getHashedDirectory(3));
        $this->assertSame(3, substr_count(PathUtils::getHashedDirectory(3), DIRECTORY_SEPARATOR));
        $this->assertStringStartsWith($ds, PathUtils::getHashedDirectory(3));
        $this->assertStringEndsNotWith($ds, PathUtils::getHashedDirectory(3));
        $this->assertDirectoryExists($createdDirectory);
        $this->assertFileExists($createdDirectory);

        PathUtils::recursiveRemoveDirectory($baseDirectory);

        $this->assertDirectoryDoesNotExist($createdDirectory);
        $this->assertFileDoesNotExist($createdDirectory);
    }
}
