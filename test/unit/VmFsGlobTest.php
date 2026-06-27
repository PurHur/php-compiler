<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

final class VmFsGlobTest extends TestCase
{
    private static string $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 2);
    }

    public function testGlobBuiltinDoesNotCallHostGlob(): void
    {
        $source = (string) file_get_contents(self::$root.'/ext/standard/glob_.php');
        $this->assertStringNotContainsString('\\glob(', $source);
        $this->assertStringContainsString('VmFsGlob::glob', $source);
    }

    /** Issue #7906 / #12208: VmFsGlob must not delegate to host \\glob() or libc FFI. */
    public function testVmFsGlobDoesNotReferenceHostGlob(): void
    {
        $source = (string) file_get_contents(self::$root.'/ext/standard/VmFsGlob.php');
        $pure = (string) file_get_contents(self::$root.'/ext/standard/VmFsGlobPure.php');
        $this->assertStringContainsString('VmFsGlobPure::glob', $source);
        $this->assertStringNotContainsString('FFI::cdef', $source);
        $this->assertStringNotContainsString("function_exists('glob')", $source);
        $this->assertStringNotContainsString('hostGlob', $source);
        $this->assertStringNotContainsString('FFI::cdef', $pure);
    }

    /** Issue #8167: VmFsGlob pathIsDir must not delegate to host \\stat(). */
    public function testVmFsGlobDoesNotReferenceHostStat(): void
    {
        $source = (string) file_get_contents(self::$root.'/ext/standard/VmFsGlobPure.php');
        $this->assertStringContainsString('VmStatCache::stat', $source);
        $this->assertDoesNotMatchRegularExpression('/@\\\\stat\\s*\\(/', $source);
        $this->assertDoesNotMatchRegularExpression('/[^:]\\\\stat\\s*\\(/', $source);
    }

    public function testScandirBuiltinDoesNotCallHostScandir(): void
    {
        $source = (string) file_get_contents(self::$root.'/ext/standard/scandir.php');
        $this->assertStringNotContainsString('\\scandir(', $source);
        $this->assertStringContainsString('VmDir::scandir', $source);
    }

    public function testVmDirScandirMatchesFixture(): void
    {
        $dir = self::$root.'/test/compliance/cases/stdlib/glob_scandir_fixture';
        $entries = \PHPCompiler\ext\standard\VmDir::scandir($dir);
        $this->assertIsArray($entries);
        $this->assertTrue(\in_array('a.php', $entries, true));
        $this->assertTrue(\in_array('readme.txt', $entries, true));
        $this->assertFalse(\PHPCompiler\ext\standard\VmDir::scandir('/nonexistent/path/for/php-compiler'));
    }

    public function testVmFsGlobMatchesFixturePhpFiles(): void
    {
        $dir = self::$root.'/test/compliance/cases/stdlib/glob_scandir_fixture';
        $matches = \PHPCompiler\ext\standard\VmFsGlob::glob($dir.'/*.php');
        $this->assertIsArray($matches);
        $this->assertCount(2, $matches);
        $names = array_map('basename', $matches);
        sort($names);
        $this->assertSame(['a.php', 'b.php'], $names);
    }

    public function testVmFsGlobOnlydirFiltersNonDirectories(): void
    {
        $dir = self::$root.'/test/compliance/cases/stdlib/glob_onlydir_fixture';
        $matches = \PHPCompiler\ext\standard\VmFsGlob::glob($dir.'/*', \GLOB_ONLYDIR);
        $this->assertIsArray($matches);
        $this->assertCount(1, $matches);
        $this->assertSame('subdir', basename($matches[0]));
    }

    /** Issue #12626 — GLOB_BRACE with no matches returns empty array, not false. */
    public function testVmFsGlobBraceEmptyReturnsArray(): void
    {
        $matches = \PHPCompiler\ext\standard\VmFsGlob::glob('{a,b}.txt', \GLOB_BRACE);
        $this->assertIsArray($matches);
        $this->assertSame([], $matches);
    }

    /** Issue #12626 — GLOB_BRACE expands alternatives. */
    public function testVmFsGlobBraceExpandsMatches(): void
    {
        $dir = self::$root.'/test/compliance/cases/stdlib/glob_scandir_fixture';
        $matches = \PHPCompiler\ext\standard\VmFsGlob::glob($dir.'/{a,b}.php', \GLOB_BRACE);
        $this->assertIsArray($matches);
        $this->assertCount(2, $matches);
        $names = array_map('basename', $matches);
        sort($names);
        $this->assertSame(['a.php', 'b.php'], $names);
    }

    /** Issue #12627 — GLOB_MARK appends slash to directories only. */
    public function testVmFsGlobMarkAppendsSlashToDirectories(): void
    {
        $dir = self::$root.'/test/compliance/cases/stdlib/glob_onlydir_fixture';
        $matches = \PHPCompiler\ext\standard\VmFsGlob::glob($dir.'/*', \GLOB_MARK);
        $this->assertIsArray($matches);
        $marked = false;
        foreach ($matches as $entry) {
            if (str_ends_with($entry, '/')) {
                $marked = true;
                $this->assertStringEndsWith('/subdir/', $entry);
            }
        }
        $this->assertTrue($marked);
        $files = \PHPCompiler\ext\standard\VmFsGlob::glob($dir.'/*.php', \GLOB_MARK);
        foreach ($files as $entry) {
            $this->assertFalse(str_ends_with($entry, '/'));
        }
    }
}
