<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\StripslashesJitHelper;
use PHPCompiler\ext\standard\VmString;
use PHPUnit\Framework\TestCase;

/** stripslashes() JIT routes through StripslashesJitHelper PHP for embed + user-script AOT (#14742, #18792). */
final class StripslashesRuntimeShrinkTest extends TestCase
{
    public function testStringStripslashesUsesJitHelperNotLlvmMonolith(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringStripslashes.php');
        $this->assertStringContainsString('StripslashesJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $source);
        // Thin AOT: pure LLVM inside StringStripslashes (#26907).
        $this->assertStringContainsString('isThinStandaloneAotMain', $source);
        $this->assertStringContainsString('implementThinLlvm', $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
        $this->assertStringNotContainsString('StringStripslashesLlvm', $source);
        $this->assertStringNotContainsString('stripslashes_count_head', $source);
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StringStripslashesLlvm.php');
        $this->assertFileDoesNotExist(__DIR__.'/../../ext/standard/JitStripslashes.php');

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/stripslashes.php');
        $this->assertStringContainsString('StringStripslashes::ensureLinked', $builtin);
        $this->assertStringContainsString('__string__stripslashes', $builtin);
        $this->assertStringNotContainsString('JitStripslashes', $builtin);
    }

    public function testStripslashesJitHelperDelegatesToVmString(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/StripslashesJitHelper.php');
        $this->assertStringContainsString('VmString::stripslashes', $source);

        $input = VmString::addslashes("a'b\\0c");
        $expected = VmString::stripslashes($input);
        $this->assertSame($expected, StripslashesJitHelper::stripslashesArgv($input));
        $this->assertSame("a'b\\0c", $expected);
    }

    public function testSpineBundleOmitsDeletedStripslashesLlvm(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringNotContainsString('JitStripslashes.php', $spine);
        $this->assertStringNotContainsString('StringStripslashesLlvm.php', $spine);
        $this->assertStringContainsString('StripslashesJitHelper.php', $spine);
        $this->assertStringContainsString('StringStripslashes.php', $spine);
    }
}
