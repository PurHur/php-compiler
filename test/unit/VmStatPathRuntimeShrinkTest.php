<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\VmFsTempnamNative;
use PHPCompiler\ext\standard\VmStatPath;
use PHPUnit\Framework\TestCase;

/** VM filestat + tempnam without host Zend delegation (#8186, pairs JitStat). */
final class VmStatPathRuntimeShrinkTest extends TestCase
{
    /** @var list<string> */
    private array $filesToUnlink = [];

    protected function tearDown(): void
    {
        foreach ($this->filesToUnlink as $path) {
            @unlink($path);
        }
        $this->filesToUnlink = [];
    }

    public function testFilestatBuiltinsDoNotReferenceHostDelegation(): void
    {
        $paths = [
            'file_exists.php',
            'is_dir.php',
            'is_file.php',
            'is_link.php',
            'is_readable.php',
            'is_writable.php',
            'is_executable.php',
        ];
        foreach ($paths as $basename) {
            $source = (string) file_get_contents(__DIR__.'/../../ext/standard/'.$basename);
            $this->assertStringContainsString('VmStatPath::', $source, $basename);
            $this->assertDoesNotMatchRegularExpression('/@\\\\file_exists\\s*\\(/', $source);
            $this->assertDoesNotMatchRegularExpression('/@\\\\is_dir\\s*\\(/', $source);
            $this->assertDoesNotMatchRegularExpression('/@\\\\is_file\\s*\\(/', $source);
            $this->assertDoesNotMatchRegularExpression('/@\\\\is_link\\s*\\(/', $source);
            $this->assertDoesNotMatchRegularExpression('/@\\\\is_readable\\s*\\(/', $source);
            $this->assertDoesNotMatchRegularExpression('/@\\\\is_writable\\s*\\(/', $source);
            $this->assertDoesNotMatchRegularExpression('/@\\\\is_executable\\s*\\(/', $source);
        }
    }

    public function testTempnamHelpersDoNotReferenceHostTempnam(): void
    {
        foreach (['VmFsTempnam.php', 'VmFs.php'] as $basename) {
            $source = (string) file_get_contents(__DIR__.'/../../ext/standard/'.$basename);
            $this->assertDoesNotMatchRegularExpression('/\\\\tempnam\\s*\\(/', $source, $basename);
            $this->assertDoesNotMatchRegularExpression('/@\\\\is_dir\\s*\\(/', $source, $basename);
            $this->assertDoesNotMatchRegularExpression('/@\\\\is_writable\\s*\\(/', $source, $basename);
        }
    }

    public function testVmStatPathUsesStatCacheAndAccessPure(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmStatPath.php');
        $this->assertStringContainsString('VmStatCache::stat', $source);
        $this->assertStringContainsString('VmFsAccessPure::', $source);
    }

    public function testVmStatPathMatchesSysTempDirWhenFfiAvailable(): void
    {
        if (!extension_loaded('ffi')) {
            $this->markTestSkipped('ext/ffi required');
        }
        $dir = sys_get_temp_dir();
        $this->assertTrue(VmStatPath::isDir($dir));
        $this->assertTrue(VmStatPath::isWritable($dir));
    }

    public function testTempnamNativeCreatesFileUnderSysTempWhenFfiAvailable(): void
    {
        if (!extension_loaded('ffi')) {
            $this->markTestSkipped('ext/ffi required');
        }
        $path = VmFsTempnamNative::mkstemp(sys_get_temp_dir(), 'phpc_');
        $this->assertIsString($path);
        $this->assertFileExists($path);
        $this->filesToUnlink[] = $path;
    }
}
