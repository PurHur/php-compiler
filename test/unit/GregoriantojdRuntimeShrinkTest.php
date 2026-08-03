<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\calendar\GregoriantojdJitHelper;
use PHPCompiler\ext\calendar\VmCalendar;
use PHPUnit\Framework\TestCase;

/** gregoriantojd() JIT routes through GregoriantojdJitHelper (#27386). */
final class GregoriantojdRuntimeShrinkTest extends TestCase
{
    public function testBuiltinRoutesThroughJitHelperNotRefuse(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/calendar/gregoriantojd.php');
        $this->assertStringContainsString('JitGregoriantojd::invoke', $builtin);
        $this->assertStringNotContainsString('not implemented for JIT', $builtin);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/GregoriantojdRuntime.php');
        $this->assertStringContainsString('GregoriantojdJitHelper', $bridge);
        $this->assertStringContainsString('phpc_gregoriantojd', $bridge);
    }

    public function testJitHelperSemanticsMatchVmCalendar(): void
    {
        $this->assertSame(2461256, GregoriantojdJitHelper::gregoriantojdArgv(8, 3, 2026));
        $this->assertSame(
            VmCalendar::gregorianToJd(8, 3, 2026),
            GregoriantojdJitHelper::gregoriantojdArgv(8, 3, 2026)
        );
        $this->assertSame(
            VmCalendar::gregorianToJd(12, 31, 2023),
            GregoriantojdJitHelper::gregoriantojdArgv(12, 31, 2023)
        );
    }
}
