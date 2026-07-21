<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\CslashesJitHelper;
use PHPCompiler\ext\standard\VmString;
use PHPUnit\Framework\TestCase;

/** addcslashes/stripcslashes JIT routes through CslashesJitHelper + JitVmHelperLink (#9578, #21617). */
final class CslashesRuntimeShrinkTest extends TestCase
{
    public function testStringCslashesUsesJitHelperNotInlineLlvm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringCslashes.php');
        $this->assertStringContainsString('CslashesJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertFileDoesNotExist(__DIR__.'/../../ext/standard/JitCslashes.php');

        $add = (string) file_get_contents(__DIR__.'/../../ext/standard/addcslashes.php');
        $this->assertStringContainsString('StringCslashes::ensureLinked', $add);
        $this->assertStringContainsString('__compiler_addcslashes', $add);

        $strip = (string) file_get_contents(__DIR__.'/../../ext/standard/stripcslashes.php');
        $this->assertStringContainsString('StringCslashes::ensureStripcslashes', $strip);
        $this->assertStringContainsString('__compiler_stripcslashes', $strip);

        $jitStrip = (string) file_get_contents(__DIR__.'/../../ext/standard/JitStripcslashes.php');
        $this->assertStringContainsString('StringCslashes::ensureStripcslashes', $jitStrip);
        $this->assertStringNotContainsString('StringCslashes::ensureLinked', $jitStrip);
    }

    public function testCslashesJitHelperDelegatesToVmString(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/CslashesJitHelper.php');
        $this->assertStringContainsString('VmString::addcslashes', $source);
        $this->assertStringContainsString('VmString::stripcslashes', $source);

        $added = VmString::addcslashes("a'b", "'");
        $this->assertSame($added, CslashesJitHelper::addcslashes("a'b", "'"));
        $stripped = VmString::stripcslashes($added);
        $this->assertSame($stripped, CslashesJitHelper::stripcslashes($added));
    }

    public function testSpineBundleIncludesCslashesJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('CslashesJitHelper.php', $spine);
        $this->assertStringContainsString('StringCslashes.php', $spine);
    }
}
