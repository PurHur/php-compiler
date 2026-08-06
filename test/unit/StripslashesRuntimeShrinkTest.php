<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\StripslashesJitHelper;
use PHPCompiler\ext\standard\VmString;
use PHPUnit\Framework\TestCase;

/** stripslashes() JIT routes through StripslashesJitHelper PHP for embed + user-script AOT (#14742, #18792, #28104). */
final class StripslashesRuntimeShrinkTest extends TestCase
{
    public function testStringStripslashesUsesJitHelperNotLlvmMonolith(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringStripslashes.php');
        $this->assertStringContainsString('StripslashesJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $source);
        // Always bridge — no thin hand LLVM (#28104; NestedJIT-safe mutual $i+1 helper).
        $this->assertStringNotContainsString('isThinStandaloneAotMain', $source);
        $this->assertStringNotContainsString('implementThinLlvm', $source);
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

    public function testStripslashesJitHelperMatchesVmStringWithoutVmStringDep(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/StripslashesJitHelper.php');
        $this->assertDoesNotMatchRegularExpression('/return\s+VmString::/', $source);
        $this->assertStringContainsString('stripFrom', $source);
        $this->assertStringContainsString('emitEscaped', $source);

        $input = VmString::addslashes("a'b\\0c");
        $expected = VmString::stripslashes($input);
        $this->assertSame($expected, StripslashesJitHelper::stripslashesArgv($input));
        $this->assertSame("a'b\\0c", $expected);

        $this->assertSame("O'Reilly", StripslashesJitHelper::stripslashesArgv("O\\'Reilly"));
        $this->assertSame("ab", StripslashesJitHelper::stripslashesArgv('a\\b'));
        $this->assertSame("a\0b", StripslashesJitHelper::stripslashesArgv('a\\0b'));
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
