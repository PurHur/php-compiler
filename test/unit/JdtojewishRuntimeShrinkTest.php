<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\calendar\JdtojewishJitHelper;
use PHPCompiler\ext\calendar\VmJewishFrenchCalendar;
use PHPUnit\Framework\TestCase;

/** jdtojewish() JIT routes through JdtojewishJitHelper (#27368). */
final class JdtojewishRuntimeShrinkTest extends TestCase
{
    public function testBuiltinRoutesThroughJitHelperNotRefuse(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/calendar/jdtojewish.php');
        $this->assertStringContainsString('JitJdtojewish::invoke', $builtin);
        $this->assertStringNotContainsString('not implemented for JIT', $builtin);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/JdtojewishRuntime.php');
        $this->assertStringContainsString('JdtojewishJitHelper', $bridge);
        $this->assertStringContainsString('phpc_jdtojewish', $bridge);
    }

    public function testJitHelperSemanticsMatchVm(): void
    {
        $this->assertSame('12/8/5785', JdtojewishJitHelper::jdtojewishArgv(2460890));
        $this->assertSame(
            VmJewishFrenchCalendar::jdtojewish(2460890),
            JdtojewishJitHelper::jdtojewishArgv(2460890)
        );
        $this->assertSame(
            VmJewishFrenchCalendar::jdtojewish(2460890, 0),
            JdtojewishJitHelper::jdtojewishArgv(2460890, 0)
        );
    }
}
