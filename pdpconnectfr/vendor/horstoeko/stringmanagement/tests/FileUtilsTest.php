<?php

namespace horstoeko\stringmanagement\tests;

use \PHPUnit\Framework\TestCase;
use \horstoeko\stringmanagement\FileUtils;

class FileUtilsTest extends TestCase
{
    /**
     * @covers \horstoeko\stringmanagement\FileUtils::fileExists
     */
    public function testFileExists(): void
    {
        $this->assertTrue(FileUtils::fileExists(__FILE__, true));
        $this->assertFalse(FileUtils::fileExists(__FILE__ . ".xxx", true));
    }

    /**
     * @covers \horstoeko\stringmanagement\FileUtils::fileToBase64
     */
    public function testFileToBase64(): void
    {
        $this->assertSame("SSBhbSBhIHRlc3RmaWxl", substr(FileUtils::fileToBase64(__DIR__ . "/data/tobase64.txt"), 0, 20));
        $this->assertEquals(false, FileUtils::fileToBase64(__FILE__ . ".xxx"));
    }

    /**
     * @covers \horstoeko\stringmanagement\FileUtils::fileToBase64File
     */
    public function testFileToBase64File(): void
    {
        $sourceFilename = __DIR__ . "/data/tobase64.txt";
        $destinationFilename = __DIR__ . "/data/encbase64.txt";
        $this->assertTrue(FileUtils::fileToBase64File($sourceFilename, $destinationFilename));
        $this->assertTrue(FileUtils::fileExists($destinationFilename));
        $destinationFilenameContent = file_get_contents($destinationFilename);
        $this->assertSame("SSBhbSBhIHRlc3RmaWxl", substr($destinationFilenameContent, 0, 20));
        @unlink($destinationFilename);
    }

    /**
     * @covers \horstoeko\stringmanagement\FileUtils::fileToBase64File
     */
    public function testFileToBase64FileSourceNotExisting(): void
    {
        $sourceFilename = __DIR__ . "/data/tobase64_2.txt";
        $destinationFilename = __DIR__ . "/data/encbase64_2.txt";
        $this->assertFalse(FileUtils::fileToBase64File($sourceFilename, $destinationFilename));
        $this->assertFalse(FileUtils::fileExists($destinationFilename));
    }

    /**
     * @covers \horstoeko\stringmanagement\FileUtils::base64ToFile
     */
    public function testBase64ToFile(): void
    {
        $sourceData = "SSBhbSBhIHRlc3RmaWxlLiBEb24ndCBtb2RpZnkgbWUuLi4=";
        $destinationFilename = __DIR__ . "/data/decbase64.txt";
        $this->assertTrue(FileUtils::base64ToFile($sourceData, $destinationFilename));
        $this->assertTrue(FileUtils::fileExists($destinationFilename));
        $destinationFilenameContent = file_get_contents($destinationFilename);
        $this->assertSame("I am a testfile. Don", substr($destinationFilenameContent, 0, 20));
        @unlink($destinationFilename);
    }

    /**
     * @covers \horstoeko\stringmanagement\FileUtils::base64FileToFile
     */
    public function testBase64FileToFile(): void
    {
        $sourceFilename = __DIR__ . "/data/base64.txt";
        $destinationFilename = __DIR__ . "/data/decbase64.txt";
        $this->assertTrue(FileUtils::base64FileToFile($sourceFilename, $destinationFilename));
        $this->assertTrue(FileUtils::fileExists($destinationFilename));
        $destinationFilenameContent = file_get_contents($destinationFilename);
        $this->assertSame("I am a testfile. Don", substr($destinationFilenameContent, 0, 20));
        @unlink($destinationFilename);
    }

    /**
     * @covers \horstoeko\stringmanagement\FileUtils::combineFilenameWithFileextension
     */
    public function testCombineFilenameWithFileextension(): void
    {
        $this->assertSame("file.txt", FileUtils::combineFilenameWithFileextension("file", "txt"));
        $this->assertSame("file.txt", FileUtils::combineFilenameWithFileextension("file.", "txt"));
        $this->assertSame("file.txt", FileUtils::combineFilenameWithFileextension("file.", ".txt"));
        $this->assertSame("file.txt", FileUtils::combineFilenameWithFileextension("file..", "txt"));
        $this->assertSame("file.txt", FileUtils::combineFilenameWithFileextension("file..", "..txt"));
        $this->assertSame("file.x.txt", FileUtils::combineFilenameWithFileextension("file.x", "txt"));
        $this->assertSame("file.x.txt", FileUtils::combineFilenameWithFileextension("file.x", ".txt"));
        $this->assertSame("/home/john/file.txt", FileUtils::combineFilenameWithFileextension("/home/john/file", "txt"));
    }

    /**
     * @covers \horstoeko\stringmanagement\FileUtils::getFileDirectory
     */
    public function testGetFileDirectory(): void
    {
        $this->assertSame("/home/john", FileUtils::getFileDirectory("/home/john/file.txt"));
        $this->assertSame("/home/john", FileUtils::getFileDirectory("/home/john/file.x.txt"));
    }

    /**
     * @covers \horstoeko\stringmanagement\FileUtils::getFilenameWithExtension
     */
    public function testGetFilenameWithExtension(): void
    {
        $this->assertSame("file.txt", FileUtils::getFilenameWithExtension("/home/john/file.txt"));
        $this->assertSame("file.x.txt", FileUtils::getFilenameWithExtension("/home/john/file.x.txt"));
    }

    /**
     * @covers \horstoeko\stringmanagement\FileUtils::getFilenameWithoutExtension
     */
    public function testGetFilenameWithoutExtension(): void
    {
        $this->assertSame("file", FileUtils::getFilenameWithoutExtension("/home/john/file.txt"));
        $this->assertSame("file.x", FileUtils::getFilenameWithoutExtension("/home/john/file.x.txt"));
    }

    /**
     * @covers \horstoeko\stringmanagement\FileUtils::getFileExtension
     */
    public function testGetFileExtension(): void
    {
        $this->assertSame(".txt", FileUtils::getFileExtension("file.txt", true));
        $this->assertSame(".txt", FileUtils::getFileExtension("file.x.txt", true));
        $this->assertSame(".txt", FileUtils::getFileExtension("/home/john/file.x.txt", true));
        $this->assertSame("txt", FileUtils::getFileExtension("file.txt"));
        $this->assertSame("txt", FileUtils::getFileExtension("file.x.txt"));
        $this->assertSame("txt", FileUtils::getFileExtension("/home/john/file.x.txt"));
    }

    /**
     * @covers \horstoeko\stringmanagement\FileUtils::changeFileExtension
     */
    public function testChangeFileExtension(): void
    {
        $ds = DIRECTORY_SEPARATOR;

        $this->assertSame(sprintf('.%sfile.new', $ds), FileUtils::changeFileExtension("file.txt", "new"));
        $this->assertSame(sprintf('.%sfile.new', $ds), FileUtils::changeFileExtension("file.txt", ".new"));
        $this->assertSame(sprintf('%shome%sjohn%sfile.new', $ds, $ds, $ds), FileUtils::changeFileExtension(sprintf('%shome%sjohn%sfile.txt', $ds, $ds, $ds), "new"));
        $this->assertSame(sprintf('%shome%sjohn%sfile.new', $ds, $ds, $ds), FileUtils::changeFileExtension(sprintf('%shome%sjohn%sfile.txt', $ds, $ds, $ds), ".new"));
    }

    /**
     * @covers \horstoeko\stringmanagement\FileUtils::getFileSize
     */
    public function testGetFileSize(): void
    {
        $this->assertSame(35, FileUtils::getFileSize(__DIR__ . "/data/tobase64.txt"));
        $this->assertSame(0, FileUtils::getFileSize(__DIR__ . "/data/filenotexists.txt"));
    }

    /**
     * @covers \horstoeko\stringmanagement\FileUtils::getFileSizeFromBase64String
     */
    public function testGetFileSizeFromBase64String(): void
    {
        $this->assertSame(35, FileUtils::getFileSizeFromBase64String("SSBhbSBhIHRlc3RmaWxlLiBEb24ndCBtb2RpZnkgbWUuLi4="));
        $this->assertSame(0, FileUtils::getFileSizeFromBase64String(""));
    }
}
