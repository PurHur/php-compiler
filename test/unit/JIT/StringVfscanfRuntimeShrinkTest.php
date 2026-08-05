<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\SscanfStrtolApply;
use PHPUnit\Framework\TestCase;

/** vfscanf() JIT via fgets + strtol/sscanf (#12541, #25718, #27663). */
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

    public function testStringVfscanfUsesFgetsPlusCompilerSscanfNoVmVfscanfNestedJit(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/StringVfscanf.php');
        $this->assertStringContainsString('__compiler_sscanf', $source);
        $this->assertStringContainsString('__compiler_fgets', $source);
        $this->assertStringContainsString('forceLibcStreamPositionAbis', $source);
        $this->assertStringContainsString('StringSscanfByRef::ensureLinked', $source);
        $this->assertStringNotContainsString('SscanfJitHelper::parseAssignMeta', $source);
        $this->assertStringNotContainsString('VmVfscanf.php', $source);
        $this->assertStringNotContainsString('VfscanfJitHelper', $source);
        $this->assertStringNotContainsString('JitVmHelperLink::ensureCompiledBundle', $source);
        $this->assertLessThan(200, \substr_count($source, "\n") + 1);
    }

    public function testJitVfscanfThinAotUsesStrtolForPercentD(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../../ext/standard/JitVfscanf.php');
        $this->assertStringContainsString('SscanfStrtolApply', $source);
        $this->assertStringContainsString('phpc_sscanf_strtol_assign', $source);
        $this->assertStringContainsString('__compiler_fgets', $source);
        $this->assertStringContainsString('forceLibcStreamPositionAbis', $source);
        $this->assertTrue(SscanfStrtolApply::isStrtolOnlyFormat('%d %d %d'));
        $this->assertFalse(SscanfStrtolApply::isStrtolOnlyFormat('%s %d'));
    }

    public function testThinAotSkipsSscanfArrayEagerNestedJit(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/Sscanf.php');
        $this->assertStringContainsString('isThinStandaloneAotMain', $source);
        $this->assertStringContainsString('StringSscanfArray::implement', $source);
    }
}
