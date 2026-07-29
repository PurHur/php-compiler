<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** stream_socket_get_name JIT via StreamSocketGetNameJitHelper + JitVmHelperLink::ensureCompiled (#12223, #24850). */
final class StreamSocketGetNameRuntimeShrinkTest extends TestCase
{
    public function testStreamSocketGetNameJitHelperDelegatesToVmStreamSocketGetName(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/StreamSocketGetNameJitHelper.php');
        $this->assertStringContainsString('VmStreamSocketGetName::getName', $source);
    }

    public function testStreamSocketGetNameRuntimeUsesJitVmHelperLinkEnsureCompiled(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StreamSocketGetNameRuntime.php');
        $this->assertStringContainsString('StreamSocketGetNameJitHelper::getNameArgv', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringContainsString('__compiler_stream_socket_get_name', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
        $this->assertLessThan(150, \substr_count($source, "\n") + 1);
    }
}
