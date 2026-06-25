<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\GmmktimeJitHelper;
use PHPCompiler\ext\standard\MktimeJitHelper;
use PHPCompiler\ext\standard\StrftimeJitHelper;
use PHPCompiler\ext\standard\StrptimeJitHelper;
use PHPCompiler\ext\standard\VmDate;
use PHPUnit\Framework\TestCase;

/** mktime/gmmktime/strftime/strptime route through VmDate PHP helpers not libc LLVM (#9132). */
final class CalendarBuiltinRuntimeShrinkTest extends TestCase
{
    public function testStringMktimeRoutesThroughMktimeJitHelper(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringMktime.php');
        $this->assertStringContainsString('MktimeJitHelper', $source);
        $this->assertStringNotContainsString('TM_SEC', $source);
        $this->assertStringNotContainsString("lookupFunction('mktime')", $source);
        $this->assertLessThan(210, \substr_count($source, "\n") + 1);
    }

    public function testStringGmmktimeRoutesThroughGmmktimeJitHelper(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringGmmktime.php');
        $this->assertStringContainsString('GmmktimeJitHelper', $source);
        $this->assertStringNotContainsString('TM_SEC', $source);
        $this->assertStringNotContainsString("lookupFunction('timegm')", $source);
        $this->assertLessThan(210, \substr_count($source, "\n") + 1);
    }

    public function testStringStrftimeRoutesThroughStrftimeJitHelper(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringStrftime.php');
        $this->assertStringContainsString('StrftimeJitHelper', $source);
        $this->assertStringNotContainsString("lookupFunction('strftime')", $source);
        $this->assertStringNotContainsString("lookupFunction('localtime')", $source);
        $this->assertLessThan(150, \substr_count($source, "\n") + 1);
    }

    public function testStringStrptimeRoutesThroughStrptimeJitHelper(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringStrptime.php');
        $this->assertStringContainsString('StrptimeJitHelper', $source);
        $this->assertStringNotContainsString('TM_SEC', $source);
        $this->assertStringNotContainsString("lookupFunction('strptime')", $source);
        $this->assertLessThan(180, \substr_count($source, "\n") + 1);
    }

    public function testMktimeJitHelperSemanticsMatchVmDate(): void
    {
        $this->assertSame(
            MktimeJitHelper::TAG_INT,
            MktimeJitHelper::mktimeArgv(22, 13, 20, 11, 14, 2023, 0)
        );
        $this->assertSame(
            VmDate::mktime(22, 13, 20, 11, 14, 2023),
            MktimeJitHelper::lastTimestamp()
        );
    }

    public function testGmmktimeJitHelperSemanticsMatchVmDate(): void
    {
        $this->assertSame(
            GmmktimeJitHelper::TAG_INT,
            GmmktimeJitHelper::gmmktimeArgv(0, 0, 0, 1, 1, 2000, 0)
        );
        $this->assertSame(
            VmDate::gmmktime(0, 0, 0, 1, 1, 2000),
            GmmktimeJitHelper::lastTimestamp()
        );
    }

    public function testStrftimeJitHelperSemanticsMatchVmDate(): void
    {
        $this->assertSame(
            VmDate::strftime('%Y', 0),
            StrftimeJitHelper::strftimeArgv('%Y', 0, 0)
        );
        $this->assertSame(
            VmDate::gmstrftime('%Y', 0),
            StrftimeJitHelper::strftimeArgv('%Y', 0, 1)
        );
    }

    public function testStrptimeJitHelperSemanticsMatchVmDate(): void
    {
        $expected = VmDate::strptime('2000-01-01', '%Y-%m-%d');
        $actual = StrptimeJitHelper::strptimeArgv('2000-01-01', '%Y-%m-%d');
        $this->assertInstanceOf(\PHPCompiler\VM\HashTable::class, $actual);
        $this->assertSame(
            $expected->find('tm_year')->resolveIndirect()->toInt(),
            $actual->find('tm_year')->resolveIndirect()->toInt()
        );
        $this->assertFalse(StrptimeJitHelper::strptimeArgv('not-a-date', '%Y-%m-%d'));
    }
}
