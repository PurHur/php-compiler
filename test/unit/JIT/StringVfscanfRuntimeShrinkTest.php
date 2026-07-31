<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPUnit\Framework\TestCase;

/** vfscanf() JIT NestedJIT via JitVmHelperLink::ensureCompiledBundle (#12541, #25718). */
final class StringVfscanfRuntimeShrinkTest extends TestCase
{
    public function testSscanfEmbedRoutesVfscanfThroughJitHelper(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/Sscanf.php');
        $this->assertStringContainsString('StringVfscanf::implement', $source);
        $this->assertStringNotContainsString('SscanfJit::implementVfscanfOnly', $source);
    }

    public function testVfscanfJitHelperUsesVmVfscanf(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../../ext/standard/VfscanfJitHelper.php');
        $this->assertStringContainsString('VmVfscanf::parse', $source);
        $this->assertStringContainsString('SscanfJitHelper::packMetaFromVariables', $source);
    }

    public function testStringVfscanfRoutesThroughEnsureCompiledBundle(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/StringVfscanf.php');
        $this->assertStringContainsString('VfscanfJitHelper', $source);
        $this->assertStringContainsString('VmVfscanf.php', $source);
        $this->assertStringContainsString('VmSscanf.php', $source);
        $this->assertStringContainsString('SscanfJitHelper.php', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiledBundle', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT\\NestedJitCompileScope;', $source);
        $this->assertLessThan(200, \substr_count($source, "\n") + 1);
    }
}
