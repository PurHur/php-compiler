<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\calendar\JdtojulianJitHelper;
use PHPCompiler\ext\calendar\VmCalendar;
use PHPUnit\Framework\TestCase;

/** jdtojulian() JIT routes through JdtojulianJitHelper (#27388). */
final class JdtojulianRuntimeShrinkTest extends TestCase
{
    public function testBuiltinRoutesThroughJitHelperNotRefuse(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/calendar/jdtojulian.php');
        $this->assertStringContainsString('JitJdtojulian::invoke', $builtin);
        $this->assertStringNotContainsString('not implemented for JIT', $builtin);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/JdtojulianRuntime.php');
        $this->assertStringContainsString('JdtojulianJitHelper', $bridge);
        $this->assertStringContainsString('phpc_jdtojulian', $bridge);
    }

    public function testJitHelperSemanticsMatchVmCalendar(): void
    {
        $this->assertSame('7/21/2026', JdtojulianJitHelper::jdtojulianArgv(2461256));
        $this->assertSame(
            VmCalendar::jdtojulian(2461256),
            JdtojulianJitHelper::jdtojulianArgv(2461256)
        );
        $this->assertSame(
            VmCalendar::jdtojulian(2440588),
            JdtojulianJitHelper::jdtojulianArgv(2440588)
        );
    }
}
