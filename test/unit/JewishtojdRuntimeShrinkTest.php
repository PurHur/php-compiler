<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\calendar\JewishtojdJitHelper;
use PHPCompiler\ext\calendar\VmJewishFrenchCalendar;
use PHPUnit\Framework\TestCase;

/** jewishtojd() JIT routes through JewishtojdJitHelper (#27357). */
final class JewishtojdRuntimeShrinkTest extends TestCase
{
    public function testBuiltinRoutesThroughJitHelperNotRefuse(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/calendar/jewishtojd.php');
        $this->assertStringContainsString('JitJewishtojd::invoke', $builtin);
        $this->assertStringNotContainsString('not implemented for JIT', $builtin);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/JewishtojdRuntime.php');
        $this->assertStringContainsString('JewishtojdJitHelper', $bridge);
        $this->assertStringContainsString('phpc_jewishtojd', $bridge);
    }

    public function testJitHelperSemanticsMatchVmCalendar(): void
    {
        $this->assertSame(2460204, JewishtojdJitHelper::jewishtojdArgv(1, 1, 5784));
        $this->assertSame(
            VmJewishFrenchCalendar::jewishtojd(1, 1, 5784),
            JewishtojdJitHelper::jewishtojdArgv(1, 1, 5784)
        );
        $this->assertSame(
            VmJewishFrenchCalendar::jewishtojd(1, 1, 5781),
            JewishtojdJitHelper::jewishtojdArgv(1, 1, 5781)
        );
    }
}
