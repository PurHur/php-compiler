<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPUnit\Framework\TestCase;

/** sscanf() by-ref JIT routes through SscanfJitHelper PHP not SscanfJit LLVM monolith (#12467). */
final class StringSscanfByRefRuntimeShrinkTest extends TestCase
{
    public function testSscanfEmbedRoutesByRefThroughJitHelper(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../../lib/JIT/Builtin/Sscanf.php');
        $this->assertStringContainsString('StringSscanfByRef::implement', $source);
        $this->assertStringContainsString('StringVfscanf::implement', $source);
        $this->assertStringNotContainsString('SscanfJit::implementVfscanfOnly', $source);
        $this->assertStringNotContainsString("StringSscanfByRef::implement(\$context);\n        SscanfJit::implement(\$context);", $source);
    }

    public function testSscanfJitHelperParseAssignMetaUsesVmSscanf(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../../ext/standard/SscanfJitHelper.php');
        $this->assertStringContainsString('parseAssignMeta', $source);
        $this->assertStringContainsString('VmSscanf::parseWithConsumed', $source);
    }

    public function testParseAssignMetaRoundtrip(): void
    {
        $blob = \PHPCompiler\ext\standard\SscanfJitHelper::parseAssignMeta('42 7', '%d %d', 2);
        $this->assertGreaterThanOrEqual(16, \strlen($blob));
        $assigned = (int) \unpack('q', \substr($blob, 0, 8))[1];
        $this->assertSame(2, $assigned);
        $this->assertGreaterThan(0, (int) \unpack('q', \substr($blob, 8, 8))[1]);
    }
}
