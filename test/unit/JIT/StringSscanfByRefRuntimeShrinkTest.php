<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPUnit\Framework\TestCase;

/** sscanf() by-ref JIT NestedJIT via JitVmHelperLink::ensureCompiledBundle (#12467, #25691). */
final class StringSscanfByRefRuntimeShrinkTest extends TestCase
{
    public function testSscanfJitHelperDelegatesAssignToVmSscanf(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../../ext/standard/SscanfJitHelper.php');
        $this->assertStringContainsString('VmSscanf::parseWithConsumed', $source);
        $this->assertStringContainsString('parseAssignMeta', $source);
        // NestedJIT of SscanfJitHelper must not emit phpc_round (#27663 / peer #26862).
        $this->assertStringNotContainsString('(int) \\round(', $source);
        $this->assertStringContainsString('(int) ($scaled + 0.5)', $source);
        $vmSscanf = (string) \file_get_contents(__DIR__.'/../../../ext/standard/VmSscanf.php');
        $this->assertStringNotContainsString('\\str_pad(', $vmSscanf);
        $this->assertStringNotContainsString('byRefTarget()', $vmSscanf);
    }

    public function testStringSscanfByRefRoutesThroughEnsureCompiledBundle(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/StringSscanfByRef.php');
        $this->assertStringContainsString('SscanfJitHelper', $source);
        $this->assertStringContainsString('VmSscanf.php', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiledBundle', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT\\NestedJitCompileScope;', $source);
        $this->assertLessThan(200, \substr_count($source, "\n") + 1);

        $router = (string) \file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/Sscanf.php');
        $this->assertStringContainsString('StringSscanfByRef::implement', $router);
        $this->assertStringContainsString('StringVfscanf::implement', $router);
        $this->assertStringNotContainsString('SscanfJit::implementVfscanfOnly', $router);
        $this->assertStringNotContainsString("StringSscanfByRef::implement(\$context);\n        SscanfJit::implement(\$context);", $router);
    }

    public function testParseAssignMetaRoundtrip(): void
    {
        $blob = \PHPCompiler\ext\standard\SscanfJitHelper::parseAssignMeta('42 7', '%d %d', 2);
        $this->assertGreaterThanOrEqual(16, \strlen($blob));
        $assigned = (int) \unpack('q', \substr($blob, 0, 8))[1];
        $this->assertSame(2, $assigned);
        $this->assertGreaterThan(0, (int) \unpack('q', \substr($blob, 8, 8))[1]);

        $empty = \PHPCompiler\ext\standard\SscanfJitHelper::parseAssignMeta('x', '%d', 0);
        $this->assertSame(16, \strlen($empty));
    }
}
