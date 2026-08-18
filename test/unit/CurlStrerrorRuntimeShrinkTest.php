<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\curl\CurlStrerrorJitHelper;
use PHPCompiler\ext\curl\VmCurlCore;
use PHPUnit\Framework\TestCase;

/** curl_strerror/curl_multi_strerror JIT routes through CurlStrerrorJitHelper not libcurl FFI (#32352). */
final class CurlStrerrorRuntimeShrinkTest extends TestCase
{
    public function testVmCurlCoreMultiStrerrorUsesJitHelperMap(): void
    {
        $vm = (string) file_get_contents(__DIR__.'/../../ext/curl/VmCurlCore.php');
        $this->assertStringContainsString('CurlStrerrorJitHelper::multi', $vm);
        $this->assertStringNotContainsString('MULTI_ERRORS', $vm);

        $helper = (string) file_get_contents(__DIR__.'/../../ext/curl/CurlStrerrorJitHelper.php');
        $this->assertStringNotContainsString('FFI::cdef', $helper);
        $this->assertStringNotContainsString('\\FFI', $helper);
        $this->assertStringNotContainsString('curl_easy_strerror($code)', $helper);
    }

    public function testRuntimeUsesPhpHelperNotC(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/CurlStrerrorRuntime.php');
        $this->assertStringContainsString('CurlStrerrorJitHelper', $runtime);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $runtime);
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/AOT/runtime/curl_strerror.c');
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/runtime/curl_strerror.c');
    }

    public function testHelperMatchesVmMultiAndKnownCurleCodes(): void
    {
        foreach ([0, 1, 5, 11, 99, -1] as $code) {
            $this->assertSame(
                CurlStrerrorJitHelper::multi($code),
                VmCurlCore::multiStrerror($code),
                'multi code='.$code
            );
        }
        $this->assertSame('No error', CurlStrerrorJitHelper::easy(0));
        $this->assertSame('Couldn\'t resolve host name', CurlStrerrorJitHelper::easy(6));
        $this->assertSame('Unknown error', CurlStrerrorJitHelper::easy(9999));
        $this->assertSame('No error', CurlStrerrorJitHelper::multi(0));
        $this->assertSame('Invalid socket argument', CurlStrerrorJitHelper::multi(5));
        $this->assertSame('Unknown error', CurlStrerrorJitHelper::multi(99));
    }

    public function testCallOverrideDoesNotInheritJitStub(): void
    {
        $easy = (string) file_get_contents(__DIR__.'/../../ext/curl/curl_strerror.php');
        $multi = (string) file_get_contents(__DIR__.'/../../ext/curl/curl_multi_strerror.php');
        $this->assertStringContainsString('CurlStrerrorRuntime::strerror', $easy);
        $this->assertStringContainsString('CurlStrerrorRuntime::multiStrerror', $multi);
        $this->assertStringNotContainsString('not implemented for JIT', $easy);
        $this->assertStringNotContainsString('not implemented for JIT', $multi);
    }
}
