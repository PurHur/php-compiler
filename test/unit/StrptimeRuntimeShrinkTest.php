<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\StrptimeJitHelper;
use PHPCompiler\ext\standard\VmDate;
use PHPCompiler\VM\HashTable;
use PHPUnit\Framework\TestCase;

/**
 * strptime NestedJIT via JitVmHelperLink::ensureCompiled (#25409 / peer #25365).
 */
final class StrptimeRuntimeShrinkTest extends TestCase
{
    public function testStringStrptimeUsesJitVmHelperLink(): void
    {
        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringStrptime.php');
        $this->assertStringContainsString('StrptimeJitHelper', $bridge);
        $this->assertStringContainsString('__compiler_strptime', $bridge);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $bridge);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $bridge);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $bridge);
        $this->assertStringNotContainsString('parseAndCompile', $bridge);
        $this->assertStringNotContainsString('new JIT(', $bridge);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $bridge);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $bridge);
    }

    public function testStrptimeJitHelperIsVmDateSsot(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmDate.php');
        $this->assertStringContainsString('StrptimeJitHelper::strptimeArgv', $source);

        $parsed = StrptimeJitHelper::strptimeArgv('2020-01-15', '%Y-%m-%d');
        $this->assertInstanceOf(HashTable::class, $parsed);
        $viaVm = VmDate::strptime('2020-01-15', '%Y-%m-%d');
        $this->assertInstanceOf(HashTable::class, $viaVm);
        $this->assertSame($viaVm->find('tm_year')->toInt(), $parsed->find('tm_year')->toInt());
        $this->assertSame($viaVm->find('tm_mon')->toInt(), $parsed->find('tm_mon')->toInt());
        $this->assertSame($viaVm->find('tm_mday')->toInt(), $parsed->find('tm_mday')->toInt());
        $this->assertSame(120, $parsed->find('tm_year')->toInt());
        $this->assertSame(0, $parsed->find('tm_mon')->toInt());
        $this->assertSame(15, $parsed->find('tm_mday')->toInt());
        $this->assertFalse(StrptimeJitHelper::strptimeArgv('not-a-date', '%Y-%m-%d'));
    }

    public function testSpineBundleIncludesStrptimeJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('StrptimeJitHelper.php', $spine);
        $this->assertStringContainsString('StringStrptime.php', $spine);
    }
}
