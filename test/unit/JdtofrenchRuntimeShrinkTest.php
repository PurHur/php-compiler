<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\calendar\JdtofrenchJitHelper;
use PHPCompiler\ext\calendar\VmJewishFrenchCalendar;
use PHPUnit\Framework\TestCase;

/** jdtofrench() JIT routes through JdtofrenchJitHelper (#27383). */
final class JdtofrenchRuntimeShrinkTest extends TestCase
{
    public function testBuiltinRoutesThroughJitHelperNotRefuse(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/calendar/jdtofrench.php');
        $this->assertStringContainsString('JitJdtofrench::invoke', $builtin);
        $this->assertStringNotContainsString('not implemented for JIT', $builtin);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/JdtofrenchRuntime.php');
        $this->assertStringContainsString('JdtofrenchJitHelper', $bridge);
        $this->assertStringContainsString('phpc_jdtofrench', $bridge);
    }

    public function testJitHelperSemanticsMatchVmJewishFrenchCalendar(): void
    {
        $this->assertSame('1/10/12', JdtofrenchJitHelper::jdtofrenchArgv(2379867));
        $this->assertSame(
            VmJewishFrenchCalendar::jdtofrench(2379867),
            JdtofrenchJitHelper::jdtofrenchArgv(2379867)
        );
        $this->assertSame(
            VmJewishFrenchCalendar::jdtofrench(2375840),
            JdtofrenchJitHelper::jdtofrenchArgv(2375840)
        );
    }
}
