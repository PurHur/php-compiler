<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\VmInfo;
use PHPCompiler\ext\standard\VmUnameNative;
use PHPCompiler\ext\standard\VmUnamePure;
use PHPUnit\Framework\TestCase;

/** php_uname VM must not delegate to host Zend (#8171); pure path without libc FFI (#8904). */
final class VmUnameRuntimeShrinkTest extends TestCase
{
    public function testVmInfoDoesNotCallHostPhpUname(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmInfo.php');
        $this->assertDoesNotMatchRegularExpression('/@?\\\\php_uname\\s*\\(/', $source);
        $this->assertStringContainsString('VmUnameNative::php_uname', $source);
    }

    public function testVmUnameNativeDelegatesToPurePath(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmUnameNative.php');
        $this->assertStringContainsString('VmUnamePure::php_uname', $source);
        $this->assertDoesNotMatchRegularExpression('/@?\\\\php_uname\\s*\\(/', $source);
        $this->assertDoesNotMatchRegularExpression('/\\$ffi->uname/', $source);
    }

    public function testVmUnamePureDoesNotCallHostPhpUname(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmUnamePure.php');
        $this->assertDoesNotMatchRegularExpression('/@?\\\\php_uname\\s*\\(/', $source);
    }

    public function testPhpUnameSysnameNonEmptyWithFfiDisabled(): void
    {
        $prev = getenv('PHP_COMPILER_DISABLE_FFI');
        putenv('PHP_COMPILER_DISABLE_FFI=1');
        try {
            $this->assertTrue(VmUnamePure::available());
            $sysname = VmInfo::php_uname('s');
            $this->assertNotSame('', $sysname);
            $all = VmInfo::php_uname('a');
            $this->assertStringContainsString($sysname, $all);
            foreach (['n', 'r', 'v', 'm'] as $mode) {
                $this->assertNotSame('', VmUnameNative::php_uname($mode), 'mode '.$mode);
            }
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_DISABLE_FFI');
            } else {
                putenv('PHP_COMPILER_DISABLE_FFI='.$prev);
            }
        }
    }

    public function testPhpUnameLinuxParityWithZendWhenAvailable(): void
    {
        if ('Linux' !== \PHP_OS_FAMILY) {
            $this->markTestSkipped('Zend uname parity probe is Linux-only');
        }

        $zend = \php_uname('a');
        $pure = VmUnamePure::php_uname('a');
        $this->assertSame($zend, $pure);
    }
}
