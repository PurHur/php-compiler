<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\curl\CurlShareStrerrorJitHelper;
use PHPCompiler\ext\curl\VmCurlCore;
use PHPUnit\Framework\TestCase;

/** curl_share_strerror VM/JIT routes through CurlShareStrerrorJitHelper not libcurl FFI (#32340). */
final class CurlShareStrerrorRuntimeShrinkTest extends TestCase
{
    public function testVmCurlCoreShareStrerrorUsesJitHelperMap(): void
    {
        $vm = (string) file_get_contents(__DIR__.'/../../ext/curl/VmCurlCore.php');
        $this->assertStringContainsString('CurlShareStrerrorJitHelper::message', $vm);
        $this->assertStringNotContainsString('SHARE_ERRORS', $vm);

        $helper = (string) file_get_contents(__DIR__.'/../../ext/curl/CurlShareStrerrorJitHelper.php');
        $this->assertStringNotContainsString('FFI::cdef', $helper);
        $this->assertStringNotContainsString('\\FFI', $helper);
        $this->assertStringNotContainsString('curl_share_strerror($code)', $helper);
    }

    public function testRuntimeUsesPhpHelperNotC(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/CurlShareStrerrorRuntime.php');
        $this->assertStringContainsString('CurlShareStrerrorJitHelper', $runtime);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $runtime);
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/AOT/runtime/curl_share_strerror.c');
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/runtime/curl_share_strerror.c');
    }

    public function testHelperMatchesVmAndKnownCurlsheCodes(): void
    {
        foreach ([0, 1, 2, 3, 4, 5, 999, -1] as $code) {
            $this->assertSame(
                CurlShareStrerrorJitHelper::message($code),
                VmCurlCore::shareStrerror($code),
                'code='.$code
            );
        }
        $this->assertSame('No error', CurlShareStrerrorJitHelper::message(0));
        $this->assertSame('Unknown share option', CurlShareStrerrorJitHelper::message(1));
        $this->assertSame('CURLSHcode unknown', CurlShareStrerrorJitHelper::message(999));
    }
}
