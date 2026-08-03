<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\calendar\JdtogregorianJitHelper;
use PHPCompiler\ext\calendar\VmCalendar;
use PHPUnit\Framework\TestCase;

/** jdtogregorian() JIT routes through JdtogregorianJitHelper (#27355). */
final class JdtogregorianRuntimeShrinkTest extends TestCase
{
    public function testBuiltinRoutesThroughJitHelperNotRefuse(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/calendar/jdtogregorian.php');
        $this->assertStringContainsString('JitJdtogregorian::invoke', $builtin);
        $this->assertStringNotContainsString('not implemented for JIT', $builtin);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/JdtogregorianRuntime.php');
        $this->assertStringContainsString('JdtogregorianJitHelper', $bridge);
        $this->assertStringContainsString('phpc_jdtogregorian', $bridge);
    }

    public function testJitHelperSemanticsMatchVmCalendar(): void
    {
        $this->assertSame('12/31/2023', JdtogregorianJitHelper::jdtogregorianArgv(2460310));
        $this->assertSame(
            VmCalendar::jdtogregorian(2460310),
            JdtogregorianJitHelper::jdtogregorianArgv(2460310)
        );
        $this->assertSame(
            VmCalendar::jdtogregorian(2440588),
            JdtogregorianJitHelper::jdtogregorianArgv(2440588)
        );
    }
}
