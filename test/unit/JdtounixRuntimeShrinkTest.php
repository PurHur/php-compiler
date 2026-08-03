<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\calendar\JdtounixJitHelper;
use PHPCompiler\ext\calendar\VmCalendar;
use PHPUnit\Framework\TestCase;

/** jdtounix() JIT routes through JdtounixJitHelper (#27387). */
final class JdtounixRuntimeShrinkTest extends TestCase
{
    public function testBuiltinRoutesThroughJitHelperNotRefuse(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/calendar/jdtounix.php');
        $this->assertStringContainsString('JitJdtounix::invoke', $builtin);
        $this->assertStringNotContainsString('not implemented for JIT', $builtin);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/JdtounixRuntime.php');
        $this->assertStringContainsString('JdtounixJitHelper', $bridge);
        $this->assertStringContainsString('phpc_jdtounix', $bridge);
    }

    public function testJitHelperSemanticsMatchVmCalendar(): void
    {
        $this->assertSame(1785715200, JdtounixJitHelper::jdtounixArgv(2461256));
        $this->assertSame(
            VmCalendar::jdtounix(2461256),
            JdtounixJitHelper::jdtounixArgv(2461256)
        );
        $this->assertSame(
            VmCalendar::jdtounix(2440588),
            JdtounixJitHelper::jdtounixArgv(2440588)
        );
    }
}
