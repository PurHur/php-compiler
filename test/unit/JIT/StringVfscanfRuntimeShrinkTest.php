<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPUnit\Framework\TestCase;

/** vfscanf() JIT via fgets + SscanfJitHelper (#12541, #25718, #27663). */
final class StringVfscanfRuntimeShrinkTest extends TestCase
{
    public function testSscanfEmbedRoutesVfscanfThroughStringVfscanf(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/Sscanf.php');
        $this->assertStringContainsString('StringVfscanf::implement', $source);
        $this->assertStringNotContainsString('SscanfJit::implementVfscanfOnly', $source);
    }

    public function testVfscanfJitHelperKeptForVmHost(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../../ext/standard/VfscanfJitHelper.php');
        $this->assertStringContainsString('VmVfscanf::parse', $source);
        $this->assertStringContainsString('SscanfJitHelper::packMetaFromVariables', $source);
    }

    public function testStringVfscanfUsesFgetsPlusSscanfNoVmVfscanfNestedJit(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/StringVfscanf.php');
        $this->assertStringContainsString('SscanfJitHelper::parseAssignMeta', $source);
        $this->assertStringContainsString('__compiler_fgets', $source);
        $this->assertStringContainsString('forceLibcStreamPositionAbis', $source);
        $this->assertStringContainsString('StringSscanfByRef::ensureLinked', $source);
        $this->assertStringNotContainsString('VmVfscanf.php', $source);
        $this->assertStringNotContainsString('VfscanfJitHelper', $source);
        $this->assertStringNotContainsString('JitVmHelperLink::ensureCompiledBundle', $source);
        $this->assertLessThan(220, \substr_count($source, "\n") + 1);
    }

    public function testThinAotSkipsSscanfArrayEagerNestedJit(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/Sscanf.php');
        $this->assertStringContainsString('isThinStandaloneAotMain', $source);
        $this->assertStringContainsString('StringSscanfArray::implement', $source);
    }
}
