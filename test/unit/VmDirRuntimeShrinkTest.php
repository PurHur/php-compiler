<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\VmDir;
use PHPCompiler\ext\standard\VmDirNative;
use PHPCompiler\ext\standard\VmDirPure;
use PHPUnit\Framework\TestCase;

/** VM opendir/readdir/scandir must not delegate to host PHP (#5494, php-in-php, #12235). */
final class VmDirRuntimeShrinkTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
    }

    public function testVmDirDoesNotCallHostOpendir(): void
    {
        $source = (string) file_get_contents($this->repoRoot.'/ext/standard/VmDir.php');
        $this->assertStringContainsString('VmDirNative::', $source);
        $this->assertDoesNotMatchRegularExpression('/@\\\\opendir\\s*\\(/', $source);
        $this->assertDoesNotMatchRegularExpression('/@\\\\readdir\\s*\\(/', $source);
        $this->assertDoesNotMatchRegularExpression('/@\\\\closedir\\s*\\(/', $source);
    }

    public function testVmDirNativeDelegatesToPureWithoutFfi(): void
    {
        $source = (string) file_get_contents($this->repoRoot.'/ext/standard/VmDirNative.php');
        $this->assertStringContainsString('VmDirPure::listSorted', $source);
        $this->assertStringNotContainsString('FFI::cdef', $source);
        $this->assertStringNotContainsString('$ffi->scandir', $source);
        $this->assertDoesNotMatchRegularExpression('/@\\\\opendir\\s*\\(/', $source);
        $this->assertDoesNotMatchRegularExpression('/@\\\\scandir\\s*\\(/', $source);
    }

    public function testVmFsGlobPureDoesNotCallHostOpendir(): void
    {
        $source = (string) file_get_contents($this->repoRoot.'/ext/standard/VmFsGlobPure.php');
        $this->assertStringContainsString('VmDirNative::listSorted', $source);
        $this->assertDoesNotMatchRegularExpression('/@\\\\opendir\\s*\\(/', $source);
        $this->assertDoesNotMatchRegularExpression('/@\\\\readdir\\s*\\(/', $source);
    }

    public function testJitStripWhitespaceCompileTimePathUsesReadNative(): void
    {
        $source = (string) file_get_contents($this->repoRoot.'/ext/standard/JitStripWhitespace.php');
        $this->assertStringContainsString('VmFsReadNative::read', $source);
        $this->assertDoesNotMatchRegularExpression('/@\\\\file_get_contents\\s*\\(/', $source);
    }

    public function testOpendirReaddirRoundTripWithFfiDisabled(): void
    {
        if (!VmDirPure::available()) {
            $this->markTestSkipped('host scandir unavailable');
        }

        $dir = $this->repoRoot.'/test/compliance/cases/stdlib/glob_scandir_fixture';
        $previous = getenv('PHP_COMPILER_DISABLE_FFI');
        putenv('PHP_COMPILER_DISABLE_FFI=1');
        try {
            $this->assertTrue(VmDirNative::available());
            $handle = VmDir::opendir($dir);
            $this->assertIsInt($handle);
            $this->assertNotFalse($handle);

            $seen = [];
            while (true) {
                $entry = VmDir::readdir($handle);
                if (false === $entry) {
                    break;
                }
                $seen[] = $entry;
            }
            VmDir::closedir($handle);

            $this->assertContains('.', $seen);
            $this->assertContains('a.php', $seen);

            $handle2 = VmDir::opendir($dir);
            $this->assertIsInt($handle2);
            $first = VmDir::readdir($handle2);
            VmDir::rewinddir($handle2);
            $this->assertSame($first, VmDir::readdir($handle2));
            VmDir::closedir($handle2);
        } finally {
            if (false === $previous) {
                putenv('PHP_COMPILER_DISABLE_FFI');
            } else {
                putenv('PHP_COMPILER_DISABLE_FFI='.$previous);
            }
        }
    }
}
