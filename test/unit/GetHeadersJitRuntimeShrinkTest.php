<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** get_headers JIT routes through GetHeadersJitHelper PHP, not VM-only stub (#9212). */
final class GetHeadersJitRuntimeShrinkTest extends TestCase
{
    public function testGetHeadersJitHelperDelegatesToVmHttpFetch(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../ext/standard/GetHeadersJitHelper.php');
        $this->assertStringContainsString('VmHttpFetchNative::fetchHeaders', $source);
        $this->assertStringContainsString('VmHttpHeaders::toHashTable', $source);
    }

    public function testGetHeadersRuntimeRoutesThroughGetHeadersJitHelper(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../lib/JIT/Builtin/GetHeadersRuntime.php');
        $this->assertStringContainsString('GetHeadersJitHelper', $source);
        $this->assertStringNotContainsString('VmHttpFetchPure::request', $source);

        $lineCount = \substr_count($source, "\n");
        $this->assertLessThan(140, $lineCount, 'GetHeadersRuntime must be a thin bridge');
    }

    public function testJitGetHeadersUsesCompilerGetHeadersAbi(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../ext/standard/JitGetHeaders.php');
        $this->assertStringContainsString('__compiler_get_headers', $source);
        $this->assertStringContainsString('GetHeadersRuntime::ensureLinked', $source);
    }

    public function testGetHeadersBuiltinCallNoLongerThrowsLogicException(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../ext/standard/get_headers.php');
        $this->assertStringContainsString('JitGetHeaders::invoke', $source);
        $this->assertStringNotContainsString('not supported in JIT/AOT', $source);
    }

    public function testGetHeadersJitHelperRejectsNonHttpUrl(): void
    {
        $ht = \PHPCompiler\ext\standard\GetHeadersJitHelper::getHeaders('file:///etc/hosts', false);
        $this->assertNull($ht);
    }
}
