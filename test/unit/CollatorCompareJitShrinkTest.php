<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** Collator::compare JIT routes through JitStringCompare (#28649). */
final class CollatorCompareJitShrinkTest extends TestCase
{
    public function testBuiltinRoutesThroughJitHelperNotRefuse(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/intl/collator_compare.php');
        $this->assertStringContainsString('JitCollatorCompare::invokeProcedural', $builtin);
        $this->assertStringNotContainsString('not implemented for JIT', $builtin);

        $method = (string) file_get_contents(__DIR__.'/../../ext/intl/VmCollator.php');
        $this->assertStringContainsString('JitCollatorCompare::invokeMethod', $method);

        $lowering = (string) file_get_contents(__DIR__.'/../../ext/intl/JitCollatorCompare.php');
        $this->assertStringContainsString('JitStringCompare::strcmp', $lowering);
        $this->assertStringNotContainsString('CollatorCompareRuntime', $lowering);

        $ctx = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString("functionProxies['collator::compare']", $ctx);
    }

    public function testJitHelperCompareOrder(): void
    {
        $this->assertSame(
            -1,
            \PHPCompiler\ext\intl\CollatorCompareJitHelper::compareUtf8Argv('a', 'b')
        );
        $this->assertSame(
            0,
            \PHPCompiler\ext\intl\CollatorCompareJitHelper::compareUtf8Argv('x', 'x')
        );
        $this->assertSame(
            1,
            \PHPCompiler\ext\intl\CollatorCompareJitHelper::compareUtf8Argv('b', 'a')
        );
    }
}
