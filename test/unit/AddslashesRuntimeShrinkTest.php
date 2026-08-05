<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\AddslashesJitHelper;
use PHPCompiler\ext\standard\VmString;
use PHPUnit\Framework\TestCase;

/** addslashes() JIT routes through AddslashesJitHelper PHP for embed + user-script AOT (#14741, #18391). */
final class AddslashesRuntimeShrinkTest extends TestCase
{
    public function testStringAddslashesUsesJitHelperNotLlvmMonolith(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringAddslashes.php');
        $this->assertStringContainsString('AddslashesJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $source);
        // Thin AOT: pure LLVM inside StringAddslashes (no NestedJIT / no separate Llvm file) (#26907).
        $this->assertStringContainsString('isThinStandaloneAotMain', $source);
        $this->assertStringContainsString('implementThinLlvm', $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
        $this->assertStringNotContainsString('StringAddslashesLlvm', $source);
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StringAddslashesLlvm.php');
        $this->assertFileDoesNotExist(__DIR__.'/../../ext/standard/JitAddslashes.php');

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/addslashes.php');
        $this->assertStringContainsString('StringAddslashes::ensureLinked', $builtin);
        $this->assertStringContainsString('__string__addslashes', $builtin);
        $this->assertStringNotContainsString('JitAddslashes', $builtin);
    }

    public function testAddslashesJitHelperDelegatesToVmString(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/AddslashesJitHelper.php');
        $this->assertStringContainsString('VmString::addslashes', $source);

        $input = "a'b\\0c";
        $expected = VmString::addslashes($input);
        $this->assertSame($expected, AddslashesJitHelper::addslashesArgv($input));
        $this->assertSame($expected, VmString::addslashes($input));
    }

    public function testSpineBundleOmitsDeletedAddslashesLlvm(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringNotContainsString('JitAddslashes.php', $spine);
        $this->assertStringNotContainsString('StringAddslashesLlvm.php', $spine);
        $this->assertStringContainsString('AddslashesJitHelper.php', $spine);
        $this->assertStringContainsString('StringAddslashes.php', $spine);
    }
}
