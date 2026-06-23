<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\CheckdateJitHelper;
use PHPCompiler\ext\standard\VmDate;
use PHPUnit\Framework\TestCase;

/** checkdate JIT routes through VmDate PHP, not duplicated LLVM calendar logic (#9242). */
final class CheckdateJitRuntimeShrinkTest extends TestCase
{
    public function testCheckdateJitHelperDelegatesToVmDate(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../ext/standard/CheckdateJitHelper.php');
        $this->assertStringContainsString('VmCheckdate::validate', $source);
    }

    public function testCheckdateRuntimeRoutesThroughCheckdateJitHelper(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../lib/JIT/Builtin/CheckdateRuntime.php');
        $this->assertStringContainsString('VmCheckdate', $source);
        $this->assertStringNotContainsString('MONTH_DAYS', $source);
        $this->assertStringNotContainsString('isLeapYear', $source);
        $this->assertStringNotContainsString('daysInMonth', $source);
    }

    public function testJitCheckdateRoutesThroughCheckdateRuntime(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../ext/standard/JitCheckdate.php');
        $this->assertStringContainsString('CheckdateRuntime::ensureLinked', $source);
    }

    public function testCheckdateJitHelperSemanticsMatchVmDate(): void
    {
        $this->assertSame(VmDate::checkdate(2, 29, 2024), CheckdateJitHelper::checkdate(2, 29, 2024));
        $this->assertSame(VmDate::checkdate(2, 29, 2023), CheckdateJitHelper::checkdate(2, 29, 2023));
        $this->assertSame(VmDate::checkdate(13, 1, 2024), CheckdateJitHelper::checkdate(13, 1, 2024));
    }
}
