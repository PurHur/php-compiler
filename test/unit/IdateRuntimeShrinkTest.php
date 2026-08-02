<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\IdateJitHelper;
use PHPCompiler\ext\standard\VmDate;
use PHPUnit\Framework\TestCase;

/** Host IdateJitHelper NestedJIT-safe; AOT uses JitIdate IR (#26900). */
final class IdateRuntimeShrinkTest extends TestCase
{
    public function testIdateJitHelperIsNestedJitSafeInline(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/IdateJitHelper.php');
        $this->assertStringContainsString('civilYmdPacked', $source);
        $this->assertStringNotContainsString('VmDate::', $source);
    }

    public function testIdateJitHelperSemanticsMatchVmDate(): void
    {
        $ts = strtotime('2020-06-21 12:00:00 UTC');
        $this->assertSame(VmDate::idateValue('Y', $ts), IdateJitHelper::idate('Y', $ts));
        $this->assertSame(VmDate::idateValue('m', $ts), IdateJitHelper::idate('m', $ts));
        $this->assertSame(VmDate::idateValue('d', $ts), IdateJitHelper::idate('d', $ts));
        $this->assertSame(VmDate::idateValue('w', $ts), IdateJitHelper::idate('w', $ts));
        $this->assertSame(VmDate::idateValue('U', $ts), IdateJitHelper::idate('U', $ts));
    }

    public function testSpineBundleIncludesIdateJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('IdateJitHelper.php', $spine);
        $this->assertStringContainsString('StringIdate.php', $spine);
    }
}
