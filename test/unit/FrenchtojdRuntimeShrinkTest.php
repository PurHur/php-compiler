<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\calendar\FrenchtojdJitHelper;
use PHPCompiler\ext\calendar\VmJewishFrenchCalendar;
use PHPUnit\Framework\TestCase;

/** frenchtojd() JIT routes through FrenchtojdJitHelper (#27382). */
final class FrenchtojdRuntimeShrinkTest extends TestCase
{
    public function testBuiltinRoutesThroughJitHelperNotRefuse(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/calendar/frenchtojd.php');
        $this->assertStringContainsString('JitFrenchtojd::invoke', $builtin);
        $this->assertStringNotContainsString('not implemented for JIT', $builtin);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/FrenchtojdRuntime.php');
        $this->assertStringContainsString('FrenchtojdJitHelper', $bridge);
        $this->assertStringContainsString('phpc_frenchtojd', $bridge);
    }

    public function testJitHelperSemanticsMatchVmJewishFrenchCalendar(): void
    {
        $this->assertSame(2375840, FrenchtojdJitHelper::frenchtojdArgv(1, 1, 1));
        $this->assertSame(
            VmJewishFrenchCalendar::frenchtojd(1, 1, 1),
            FrenchtojdJitHelper::frenchtojdArgv(1, 1, 1)
        );
        $this->assertSame(
            VmJewishFrenchCalendar::frenchtojd(1, 10, 12),
            FrenchtojdJitHelper::frenchtojdArgv(1, 10, 12)
        );
    }
}
