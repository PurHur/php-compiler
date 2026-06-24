<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** VmDirNative pure-PHP directory listing when FFI disabled (#9034). */
final class VmDirNativePureTest extends TestCase
{
    private ?string $savedDisableFfi = null;

    protected function setUp(): void
    {
        $this->savedDisableFfi = getenv('PHP_COMPILER_DISABLE_FFI') ?: null;
        putenv('PHP_COMPILER_DISABLE_FFI=1');
    }

    protected function tearDown(): void
    {
        if (null === $this->savedDisableFfi) {
            putenv('PHP_COMPILER_DISABLE_FFI');
        } else {
            putenv('PHP_COMPILER_DISABLE_FFI='.$this->savedDisableFfi);
        }
    }

    public function testOpendirReaddirWorksWithoutFfi(): void
    {
        $dir = 'test/compliance/cases/stdlib/glob_scandir_fixture';
        $this->assertTrue(\PHPCompiler\ext\standard\VmDirNative::available());
        $handle = \PHPCompiler\ext\standard\VmDirNative::opendir($dir);
        $this->assertIsInt($handle);
        $names = [];
        $entry = \PHPCompiler\ext\standard\VmDirNative::readdir((int) $handle);
        while (false !== $entry) {
            $names[] = $entry;
            $entry = \PHPCompiler\ext\standard\VmDirNative::readdir((int) $handle);
        }
        \PHPCompiler\ext\standard\VmDirNative::closedir((int) $handle);
        $this->assertContains('.', $names);
        $this->assertContains('..', $names);
        $this->assertContains('a.php', $names);
    }

    public function testListSortedMatchesVmDirPure(): void
    {
        $dir = 'test/compliance/cases/stdlib/glob_scandir_fixture';
        $viaNative = \PHPCompiler\ext\standard\VmDirNative::listSorted($dir);
        $viaPure = \PHPCompiler\ext\standard\VmDirPure::listSorted($dir);
        $this->assertSame($viaPure, $viaNative);
    }
}
