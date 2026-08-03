<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\calendar\JdmonthnameJitHelper;
use PHPCompiler\ext\calendar\VmCalendar;
use PHPUnit\Framework\TestCase;

/** jdmonthname() JIT routes through JdmonthnameJitHelper (#27360). */
final class JdmonthnameRuntimeShrinkTest extends TestCase
{
    public function testBuiltinRoutesThroughJitHelperNotRefuse(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/calendar/jdmonthname.php');
        $this->assertStringContainsString('JitJdmonthname::invoke', $builtin);
        $this->assertStringNotContainsString('not implemented for JIT', $builtin);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/JdmonthnameRuntime.php');
        $this->assertStringContainsString('JdmonthnameJitHelper', $bridge);
        $this->assertStringContainsString('phpc_jdmonthname', $bridge);
    }

    public function testJitHelperSemanticsMatchVmCalendar(): void
    {
        $this->assertSame('December', JdmonthnameJitHelper::jdmonthnameArgv(2460310, 1));
        $this->assertSame(
            VmCalendar::jdMonthName(2460310, 1),
            JdmonthnameJitHelper::jdmonthnameArgv(2460310, 1)
        );
        $this->assertSame(
            VmCalendar::jdMonthName(2460310, 0),
            JdmonthnameJitHelper::jdmonthnameArgv(2460310, 0)
        );
    }
}
