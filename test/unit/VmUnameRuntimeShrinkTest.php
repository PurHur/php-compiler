<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\VmInfo;
use PHPCompiler\ext\standard\VmUnameNative;
use PHPUnit\Framework\TestCase;

/** php_uname VM must not delegate to host Zend (#8171). */
final class VmUnameRuntimeShrinkTest extends TestCase
{
    public function testVmInfoDoesNotCallHostPhpUname(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmInfo.php');
        $this->assertDoesNotMatchRegularExpression('/@?\\\\php_uname\\s*\\(/', $source);
        $this->assertStringContainsString('VmUnameNative::php_uname', $source);
    }

    public function testVmUnameNativeUsesLibcUname(): void
    {
        $this->assertFileExists(__DIR__.'/../../ext/standard/VmUnameNative.php');
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmUnameNative.php');
        $this->assertStringContainsString('$ffi->uname', $source);
        $this->assertDoesNotMatchRegularExpression('/@?\\\\php_uname\\s*\\(/', $source);
    }

    public function testPhpUnameSysnameNonEmptyWhenFfiAvailable(): void
    {
        if (!VmUnameNative::available()) {
            $this->markTestSkipped('libc FFI unavailable');
        }

        $sysname = VmInfo::php_uname('s');
        $this->assertNotSame('', $sysname);
        $all = VmInfo::php_uname('a');
        $this->assertStringContainsString($sysname, $all);
    }
}
