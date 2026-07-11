<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\DateIntervalFormatJitHelper;
use PHPUnit\Framework\TestCase;

/** DateIntervalFormatRuntime: embed + standalone route through DateIntervalFormatJitHelper PHP (#9499, #12800). */
final class DateIntervalFormatRuntimeShrinkTest extends TestCase
{
    public function testDateIntervalFormatRuntimeUsesJitHelperBridgeOnly(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/DateIntervalFormatRuntime.php');
        $this->assertStringContainsString('formatFromScalars', $source);
        $this->assertStringContainsString('DateIntervalFormatJitHelper', $source);
        $this->assertStringNotContainsString('DateIntervalFormatStandaloneLlvm', $source);
        $this->assertStringNotContainsString('emitFormatCode', $source);
        $this->assertStringNotContainsString('implementFormat(', $source);
        $this->assertLessThan(200, \substr_count($source, "\n") + 1);

        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/DateIntervalFormatStandaloneLlvm.php');
    }

    public function testDateIntervalFormatJitHelperDelegatesToVmDateInterval(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/DateIntervalFormatJitHelper.php');
        $this->assertStringContainsString('VmDateInterval::format', $source);
    }

    public function testDateIntervalFormatJitHelperFormatFromScalars(): void
    {
        $this->assertSame(
            '1 2 3 4 5 6',
            DateIntervalFormatJitHelper::formatFromScalars(1, 2, 3, 4, 5, 6, 0.0, 0, 0, 0, '%y %m %d %h %i %s')
        );
        $this->assertSame(
            '7',
            DateIntervalFormatJitHelper::formatFromScalars(0, 0, 0, 0, 0, 0, 0.0, 0, 1, 7, '%a')
        );
        $this->assertSame(
            '(unknown)',
            DateIntervalFormatJitHelper::formatFromScalars(0, 0, 0, 0, 0, 0, 0.0, 0, 0, 0, '%a')
        );
    }
}
