<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\DefaultTimezoneJitHelper;
use PHPCompiler\ext\standard\VmDate;
use PHPUnit\Framework\TestCase;

/** DefaultTimezoneRuntime routes through DefaultTimezoneJitHelper PHP not LLVM globals (#9243, #24962). */
final class DefaultTimezoneRuntimeShrinkTest extends TestCase
{
    public function testDefaultTimezoneJitHelperDelegatesToVmDate(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/DefaultTimezoneJitHelper.php');
        $this->assertStringContainsString('VmDate::defaultTimezoneGet', $source);
        $this->assertStringContainsString('VmDate::tryDefaultTimezoneSet', $source);
        $this->assertStringContainsString('TriggerErrorJitHelper', $source);
    }

    public function testDefaultTimezoneRuntimeRoutesThroughJitHelper(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/DefaultTimezoneRuntime.php');
        $this->assertStringContainsString('DefaultTimezoneJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('BasicBlockHelper::tryGetInsertBlock', $source);
        $this->assertStringContainsString('BasicBlockHelper::restoreInsertBlock', $source);
        $this->assertStringContainsString('#27550', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT\\NestedJitCompileScope;', $source);
        $this->assertStringNotContainsString('phpc_default_timezone_ptr', $source);
        $this->assertStringNotContainsString("lookupFunction('access')", $source);
        $this->assertStringNotContainsString('ZONEINFO_PREFIX', $source);
        $this->assertLessThan(200, \substr_count($source, "\n") + 1);
    }

    public function testDefaultTimezoneJitHelperSemanticsMatchVmDate(): void
    {
        VmDate::tryDefaultTimezoneSet('UTC');
        $this->assertSame(VmDate::defaultTimezoneGet(), DefaultTimezoneJitHelper::defaultTimezoneGet());

        $this->assertTrue(DefaultTimezoneJitHelper::tryDefaultTimezoneSet('Europe/Berlin'));
        $this->assertSame('Europe/Berlin', DefaultTimezoneJitHelper::defaultTimezoneGet());

        $this->assertFalse(DefaultTimezoneJitHelper::tryDefaultTimezoneSet('Invalid/Zone'));
        $this->assertSame('Europe/Berlin', DefaultTimezoneJitHelper::defaultTimezoneGet());

        VmDate::tryDefaultTimezoneSet('UTC');
    }
}
