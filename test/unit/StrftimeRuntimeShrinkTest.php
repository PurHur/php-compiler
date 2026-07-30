<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\StrftimeJitHelper;
use PHPCompiler\ext\standard\VmDate;
use PHPUnit\Framework\TestCase;

/**
 * strftime NestedJIT via JitVmHelperLink::ensureCompiled (#25365 / peer #25328).
 */
final class StrftimeRuntimeShrinkTest extends TestCase
{
    public function testStringStrftimeUsesJitVmHelperLink(): void
    {
        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringStrftime.php');
        $this->assertStringContainsString('StrftimeJitHelper', $bridge);
        $this->assertStringContainsString('__compiler_strftime', $bridge);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $bridge);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $bridge);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $bridge);
        $this->assertStringNotContainsString('parseAndCompile', $bridge);
        $this->assertStringNotContainsString('new JIT(', $bridge);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $bridge);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $bridge);
    }

    public function testStrftimeJitHelperDelegatesToVmDate(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/StrftimeJitHelper.php');
        $this->assertStringContainsString('VmDate::strftime', $source);
        $this->assertStringContainsString('VmDate::gmstrftime', $source);

        $ts = 0;
        $local = VmDate::strftime('%Y', $ts);
        $gmt = VmDate::gmstrftime('%Y', $ts);
        $this->assertNotFalse($local);
        $this->assertNotFalse($gmt);
        $this->assertSame($local, StrftimeJitHelper::strftimeArgv('%Y', $ts, 0));
        $this->assertSame($gmt, StrftimeJitHelper::strftimeArgv('%Y', $ts, 1));
    }

    public function testSpineBundleIncludesStrftimeJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('StrftimeJitHelper.php', $spine);
        $this->assertStringContainsString('StringStrftime.php', $spine);
    }
}
