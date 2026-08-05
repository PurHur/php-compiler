<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\StrcollJitHelper;
use PHPCompiler\ext\standard\VmLocaleCollate;
use PHPCompiler\JIT\Builtin\StringStrcoll;
use PHPUnit\Framework\TestCase;

/**
 * strcoll() JIT/AOT uses libc trampoline under thin AOT (#27059; was NestedJIT #13566).
 * NestedJIT StrcollJitHelper mis-reads __string__* (silent 0 — peer #27051 / #27053).
 */
final class StrcollRuntimeShrinkTest extends TestCase
{
    public function testStringStrcollUsesLibcTrampolineNotNestedJit(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringStrcoll.php');
        $this->assertStringContainsString('LibcExtern::register', $source);
        $this->assertStringContainsString("lookupFunction('strcoll')", $source);
        $this->assertStringContainsString(StringStrcoll::ABI_STRCOLL, $source);
        $this->assertStringNotContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringNotContainsString('StrcollJitHelper::', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertLessThan(120, \substr_count($source, "\n") + 1);
    }

    public function testStrcollJitHelperStillDelegatesToVmLocaleCollateForVm(): void
    {
        $this->assertSame(VmLocaleCollate::strcoll('a', 'b'), StrcollJitHelper::strcollArgv('a', 'b'));
        $this->assertSame(VmLocaleCollate::strcoll('b', 'a'), StrcollJitHelper::strcollArgv('b', 'a'));
    }

    public function testSpineBundleIncludesStrcollArtifacts(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('StrcollJitHelper.php', $spine);
        $this->assertStringContainsString('StringStrcoll.php', $spine);
    }

    public function testModulePhpLinksStrcollNotEmptyLibcStub(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/Module.php');
        $this->assertStringContainsString('StringStrcoll::ensureLinked', $source);
        $this->assertStringNotContainsString("addFunction('strcoll'", $source);
    }

    public function testHashTableRoutesStrcollThroughPhpBridge(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type/HashTable.php');
        $this->assertStringContainsString('StringStrcoll::ensureLinked', $source);
        $this->assertStringNotContainsString("addFunction('strcoll'", $source);
    }
}
