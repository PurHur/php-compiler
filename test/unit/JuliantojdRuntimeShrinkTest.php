<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\calendar\JuliantojdJitHelper;
use PHPCompiler\ext\calendar\VmJewishFrenchCalendar;
use PHPUnit\Framework\TestCase;

/** juliantojd() JIT routes through JuliantojdJitHelper (#27384). */
final class JuliantojdRuntimeShrinkTest extends TestCase
{
    public function testBuiltinRoutesThroughJitHelperNotRefuse(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/calendar/juliantojd.php');
        $this->assertStringContainsString('JitJuliantojd::invoke', $builtin);
        $this->assertStringNotContainsString('not implemented for JIT', $builtin);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/JuliantojdRuntime.php');
        $this->assertStringContainsString('JuliantojdJitHelper', $bridge);
        $this->assertStringContainsString('phpc_juliantojd', $bridge);
    }

    public function testJitHelperSemanticsMatchVmJewishFrenchCalendar(): void
    {
        $this->assertSame(2451558, JuliantojdJitHelper::juliantojdArgv(1, 1, 2000));
        $this->assertSame(
            VmJewishFrenchCalendar::juliantojd(1, 1, 2000),
            JuliantojdJitHelper::juliantojdArgv(1, 1, 2000)
        );
        $this->assertSame(
            VmJewishFrenchCalendar::juliantojd(12, 31, 1999),
            JuliantojdJitHelper::juliantojdArgv(12, 31, 1999)
        );
    }
}
