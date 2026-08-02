<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\GetdateJitHelper;
use PHPCompiler\ext\standard\IdateJitHelper;
use PHPCompiler\ext\standard\VmDate;
use PHPUnit\Framework\TestCase;

/** idate/getdate AOT use LLVM civil math; host helpers stay NestedJIT-safe (#26900). */
final class StringGetdateRuntimeShrinkTest extends TestCase
{
    public function testStringGetdateIsNoOpLinkForAot(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringGetdate.php');
        $this->assertStringContainsString('Intentionally empty', $source);
        $this->assertLessThan(40, \substr_count($source, "\n") + 1);
    }

    public function testStringIdateIsNoOpLinkForAot(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringIdate.php');
        $this->assertStringContainsString('Intentionally empty', $source);
    }

    public function testJitGetdateAndIdateUseLlvmCivilMath(): void
    {
        $gd = (string) file_get_contents(__DIR__.'/../../ext/standard/JitGetdate.php');
        $this->assertStringContainsString('__hashtable__alloc', $gd);
        $this->assertStringContainsString('719468', $gd);
        $id = (string) file_get_contents(__DIR__.'/../../ext/standard/JitIdate.php');
        $this->assertStringContainsString('civilPartsPublic', $id);
        $this->assertStringContainsString('selectPart', $id);
    }

    public function testHostHelpersMatchVmDate(): void
    {
        $ts = 1577923200;
        $this->assertSame(
            VmDate::idateValue('Y', $ts),
            IdateJitHelper::idate('Y', $ts)
        );
        $b = VmDate::getdate($ts);
        $ymd = GetdateJitHelper::ymdPacked($ts);
        $this->assertSame(
            $b->find('year')->resolveIndirect()->toInt() * 10000
            + $b->find('mon')->resolveIndirect()->toInt() * 100
            + $b->find('mday')->resolveIndirect()->toInt(),
            $ymd
        );
    }
}
