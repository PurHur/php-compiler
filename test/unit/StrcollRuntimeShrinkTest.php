<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\StrcollJitHelper;
use PHPCompiler\ext\standard\VmLocaleCollate;
use PHPUnit\Framework\TestCase;

/**
 * strcoll() JIT routes through StrcollJitHelper PHP not libc LLVM (#13566 phase 2).
 * NestedJIT via JitVmHelperLink::ensureCompiled (#22256 / peer #22231).
 */
final class StrcollRuntimeShrinkTest extends TestCase
{
    public function testStrcollUsesPhpBridgeNotLibcOnly(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/strcoll.php');
        $this->assertStringContainsString('StringStrcoll::ensureLinked', $builtin);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringStrcoll.php');
        $this->assertStringContainsString('StrcollJitHelper', $bridge);
        $this->assertStringContainsString('strcollArgv', $bridge);
    }

    public function testStringStrcollUsesJitVmHelperLink(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringStrcoll.php');
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT\\NestedJitCompileScope;', $source);
        $this->assertLessThan(160, \substr_count($source, "\n") + 1);
    }

    public function testJitHelperDelegatesToVmLocaleCollate(): void
    {
        $this->assertSame(VmLocaleCollate::strcoll('a', 'b'), StrcollJitHelper::strcollArgv('a', 'b'));
        $this->assertSame(VmLocaleCollate::strcoll('b', 'a'), StrcollJitHelper::strcollArgv('b', 'a'));
    }

    public function testSpineBundleIncludesStrcollJitHelper(): void
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
