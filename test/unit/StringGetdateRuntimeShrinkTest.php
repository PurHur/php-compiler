<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\GetdateJitHelper;
use PHPUnit\Framework\TestCase;

/** StringGetdate routes through GetdateJitHelper PHP not localtime_r LLVM (#9181). */
final class StringGetdateRuntimeShrinkTest extends TestCase
{
    public function testStringGetdateRoutesThroughGetdateJitHelper(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringGetdate.php');
        $this->assertStringContainsString('GetdateJitHelper', $source);
        $this->assertStringNotContainsString("lookupFunction('localtime')", $source);
        $this->assertStringNotContainsString('__hashtable__setStringKeyLong', $source);
        $this->assertLessThan(170, \substr_count($source, "\n") + 1);
    }

    public function testGetdateJitHelperDelegatesToVmDate(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/GetdateJitHelper.php');
        $this->assertStringContainsString('VmDate::getdate', $source);
    }

    public function testGetdateJitHelperSemanticsMatchVmDate(): void
    {
        $ht = GetdateJitHelper::getdate(0);
        foreach (['hours', 'mday', 'minutes', 'mon', 'month', 'seconds', 'wday', 'weekday', 'year', 'yday'] as $key) {
            $this->assertNotNull($ht->find($key), 'missing key: '.$key);
        }
        $this->assertSame(1970, $ht->find('year')->resolveIndirect()->toInt());
    }
}
