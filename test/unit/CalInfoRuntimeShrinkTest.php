<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\calendar\CalInfoJitHelper;
use PHPCompiler\ext\calendar\CalendarConstants;
use PHPCompiler\ext\calendar\VmCalendar;
use PHPUnit\Framework\TestCase;

/** cal_info() JIT embeds via CalInfoJitHelper + HashTableHelper (#27354). */
final class CalInfoRuntimeShrinkTest extends TestCase
{
    public function testBuiltinRoutesThroughJitHelperNotRefuse(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/calendar/cal_info.php');
        $this->assertStringContainsString('JitCalInfo::invoke', $builtin);
        $this->assertStringNotContainsString('not implemented for JIT', $builtin);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/CalInfoRuntime.php');
        $this->assertStringContainsString('CalInfoJitHelper', $bridge);
        $this->assertStringContainsString('variableFromVmHashTable', $bridge);
        $this->assertStringContainsString('phpc_cal_info', $bridge);
        $this->assertStringNotContainsString('ensureBridge', $bridge);
    }

    public function testJitHelperSemanticsMatchVmCalendar(): void
    {
        $viaHelper = CalInfoJitHelper::calInfoArgv(CalendarConstants::CAL_GREGORIAN);
        $viaVm = VmCalendar::calInfo(CalendarConstants::CAL_GREGORIAN);
        $this->assertSame(
            $viaVm->find('calname')?->toString(),
            $viaHelper->find('calname')?->toString()
        );
        $months = $viaHelper->find('months');
        $this->assertNotNull($months);
        $this->assertSame('February', $months->toArray()->findIndex(2)?->toString());
    }

    public function testJitHelperRejectsInvalidCalendarId(): void
    {
        $this->expectException(\ValueError::class);
        $this->expectExceptionMessage('must be a valid calendar ID');
        CalInfoJitHelper::calInfoArgv(99);
    }
}
