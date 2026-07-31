<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\CheckdateJitHelper;
use PHPCompiler\ext\standard\VmDate;
use PHPUnit\Framework\TestCase;

/**
 * checkdate JIT routes through VmCheckdate PHP; NestedJIT via JitVmHelperLink (#9242, #26196).
 */
final class CheckdateJitRuntimeShrinkTest extends TestCase
{
    public function testCheckdateJitHelperDelegatesToVmDate(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../ext/standard/CheckdateJitHelper.php');
        $this->assertStringContainsString('VmCheckdate::validate', $source);
    }

    public function testCheckdateRuntimeRoutesThroughVmCheckdateViaJitVmHelperLink(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../lib/JIT/Builtin/CheckdateRuntime.php');
        $this->assertStringContainsString('VmCheckdate', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringContainsString('/ext/standard/VmCheckdate.php', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('captureInsertBlock', $source);
        $this->assertStringNotContainsString('MONTH_DAYS', $source);
        $this->assertStringNotContainsString('isLeapYear', $source);
        $this->assertStringNotContainsString('daysInMonth', $source);
        $this->assertLessThan(100, \substr_count($source, "\n") + 1);
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
