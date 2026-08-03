<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\calendar\CalFromJdJitHelper;
use PHPCompiler\ext\calendar\CalendarConstants;
use PHPCompiler\ext\calendar\VmCalendar;
use PHPUnit\Framework\TestCase;

/** cal_from_jd() JIT routes through CalFromJdJitHelper (#27359). */
final class CalFromJdRuntimeShrinkTest extends TestCase
{
    public function testBuiltinRoutesThroughJitHelperNotRefuse(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/calendar/cal_from_jd.php');
        $this->assertStringContainsString('JitCalFromJd::invoke', $builtin);
        $this->assertStringNotContainsString('not implemented for JIT', $builtin);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/CalFromJdRuntime.php');
        $this->assertStringContainsString('CalFromJdJitHelper', $bridge);
        $this->assertStringContainsString('phpc_cal_from_jd', $bridge);
    }

    public function testJitHelperSemanticsMatchVmCalendar(): void
    {
        $ht = CalFromJdJitHelper::calFromJdArgv(2460310, CalendarConstants::CAL_GREGORIAN);
        $date = $ht->find('date');
        $this->assertNotNull($date);
        $this->assertSame('12/31/2023', $date->toString());

        $vm = VmCalendar::calFromJd(2460310, CalendarConstants::CAL_GREGORIAN);
        $this->assertSame(
            $vm->find('date')?->toString(),
            $ht->find('date')?->toString()
        );
        $this->assertSame(
            $vm->find('year')?->toInt(),
            $ht->find('year')?->toInt()
        );
    }

    public function testJitHelperRejectsInvalidCalendar(): void
    {
        $this->expectException(\ValueError::class);
        $this->expectExceptionMessage('must be a valid calendar ID');
        CalFromJdJitHelper::calFromJdArgv(2460310, 99);
    }
}
