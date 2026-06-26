<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\VmCli;
use PHPCompiler\ext\standard\VmCliProcessTitleNative;
use PHPCompiler\ext\standard\VmCliProcessTitlePure;
use PHPUnit\Framework\TestCase;

/** VmCliProcessTitleNative — /proc/self/comm without prctl FFI (#12170). */
final class VmCliProcessTitleRuntimeShrinkTest extends TestCase
{
    public function testVmCliProcessTitleNativeDelegatesToPure(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmCliProcessTitleNative.php');
        $this->assertStringContainsString('VmCliProcessTitlePure::setKernelCommName', $source);
        $this->assertStringNotContainsString('FFI::cdef', $source);
        $this->assertStringNotContainsString('$ffi->prctl', $source);
    }

    public function testVmCliProcessTitlePureUsesProcComm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmCliProcessTitlePure.php');
        $this->assertStringContainsString('/proc/self/comm', $source);
        $this->assertStringNotContainsString('FFI::cdef', $source);
    }

    public function testSetProcessTitleWorksWithFfiDisabledOnLinux(): void
    {
        if (!VmCliProcessTitlePure::available()) {
            $this->markTestSkipped('/proc/self/comm unavailable');
        }
        $previous = getenv('PHP_COMPILER_DISABLE_FFI');
        putenv('PHP_COMPILER_DISABLE_FFI=1');
        try {
            $this->assertTrue(VmCliProcessTitleNative::available());
            $title = 'phpc_test_'.bin2hex(random_bytes(3));
            $this->assertTrue(VmCli::setProcessTitle($title));
            $this->assertSame($title, VmCli::getProcessTitle());
            $comm = @\file_get_contents('/proc/self/comm');
            if (false !== $comm) {
                $this->assertStringStartsWith(\substr($title, 0, 15), \trim($comm));
            }
        } finally {
            if (false === $previous) {
                putenv('PHP_COMPILER_DISABLE_FFI');
            } else {
                putenv('PHP_COMPILER_DISABLE_FFI='.$previous);
            }
        }
    }
}
