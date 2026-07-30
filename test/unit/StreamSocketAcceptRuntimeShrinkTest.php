<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\StreamSocketAcceptJitHelper;
use PHPUnit\Framework\TestCase;

/**
 * StreamSocketAcceptRuntime routes through StreamSocketAcceptJitHelper PHP via
 * JitVmHelperLink::ensureCompiled (#15346 / #25183 / peer #24850 / #25139).
 */
final class StreamSocketAcceptRuntimeShrinkTest extends TestCase
{
    public function testStreamSocketAcceptRuntimeRoutesThroughJitHelper(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StreamSocketAcceptRuntime.php');
        $this->assertStringContainsString('StreamSocketAcceptJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT\\NestedJitCompileScope;', $source);
        $this->assertStringNotContainsString('PHP_COMPILER_SELFHOST_AOT', $source);
        $this->assertStringNotContainsString('putenv', $source);
        $this->assertLessThan(140, \substr_count($source, "\n") + 1);
    }

    public function testStreamSocketAcceptJitHelperDelegatesToVmStreamSocketAccept(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/StreamSocketAcceptJitHelper.php');
        $this->assertStringContainsString('VmStreamSocketAccept::accept', $source);
        $this->assertStringContainsString('acceptArgv', $source);
    }

    public function testStreamSocketAcceptJitHelperFailureReturnsZero(): void
    {
        // Invalid handle → accept fails → 0 (Zend false) without needing a live socket.
        $this->assertSame(0, StreamSocketAcceptJitHelper::acceptArgv(0, 0, 0.0));
        $this->assertSame(0, StreamSocketAcceptJitHelper::acceptArgv(-1, 1, 0.001));
    }
}
