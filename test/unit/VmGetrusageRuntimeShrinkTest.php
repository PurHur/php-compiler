<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\GetrusageJitHelper;
use PHPCompiler\ext\standard\VmGetrusageNative;
use PHPCompiler\ext\standard\VmGetrusagePure;
use PHPCompiler\ext\standard\VmProcess;
use PHPUnit\Framework\TestCase;

/** getrusage() pure /proc path without libc getrusage FFI (#8970, php-in-php). */
final class VmGetrusageRuntimeShrinkTest extends TestCase
{
    public function testVmGetrusageNativeUsesPureBackendWithoutLibcFfi(): void
    {
        $native = (string) file_get_contents(__DIR__.'/../../ext/standard/VmGetrusageNative.php');
        $this->assertStringContainsString('VmGetrusagePure::getrusage', $native);
        $this->assertStringNotContainsString('\\FFI', $native);
        $this->assertStringNotContainsString('$ffi->getrusage', $native);
        $this->assertStringNotContainsString('int getrusage', $native);
        $this->assertDoesNotMatchRegularExpression('/\\\\getrusage\\s*\\(/', $native);

        $pure = (string) file_get_contents(__DIR__.'/../../ext/standard/VmGetrusagePure.php');
        $this->assertStringContainsString('/proc/self/stat', $pure);
        $this->assertStringNotContainsString('\\FFI', $pure);
    }

    public function testGetrusageJitHelperDelegatesToVmProcess(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/GetrusageJitHelper.php');
        $this->assertStringContainsString('VmProcess::getrusage', $source);
    }

    public function testNativeGetrusageShapeOnLinux(): void
    {
        if (!VmGetrusageNative::available()) {
            $this->markTestSkipped('/proc/self/stat unavailable');
        }

        $usage = VmGetrusageNative::getrusage(0);
        $this->assertIsArray($usage);
        $this->assertArrayHasKey('ru_maxrss', $usage);
        $this->assertArrayHasKey('ru_utime.tv_sec', $usage);
        $this->assertArrayNotHasKey(0, $usage);
    }

    public function testGetrusageWorksWithFfiDisabled(): void
    {
        if (!VmGetrusagePure::available()) {
            $this->markTestSkipped('/proc/self/stat unavailable');
        }
        $previous = getenv('PHP_COMPILER_DISABLE_FFI');
        putenv('PHP_COMPILER_DISABLE_FFI=1');
        try {
            $usage = VmProcess::getrusage(0);
            $this->assertNotFalse($usage);
            $this->assertGreaterThan(0, $usage->getNumElements());
        } finally {
            if (false === $previous) {
                putenv('PHP_COMPILER_DISABLE_FFI');
            } else {
                putenv('PHP_COMPILER_DISABLE_FFI='.$previous);
            }
        }
    }

    public function testJitHelperMatchesVmProcess(): void
    {
        if (!VmGetrusageNative::available()) {
            $this->markTestSkipped('/proc/self/stat unavailable');
        }

        $vm = VmProcess::getrusage(0);
        $this->assertNotFalse($vm);
        $jit = GetrusageJitHelper::resolve(0);
        $this->assertNotNull($jit);
        $this->assertSame($vm->getNumElements(), $jit->getNumElements());
    }
}
