<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\Nl2brJitHelper;
use PHPCompiler\ext\standard\VmString;
use PHPUnit\Framework\TestCase;

/** nl2br() JIT routes through Nl2brJitHelper PHP not inline LLVM (#14714). */
final class Nl2brRuntimeShrinkTest extends TestCase
{
    public function testStringNl2brUsesJitHelperNotInlineLlvm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringNl2br.php');
        $this->assertStringContainsString('Nl2brJitHelper', $source);
        $this->assertStringNotContainsString('nl2br_count', $source);
        $this->assertStringNotContainsString('BR_XHTML', $source);
        $this->assertFileDoesNotExist(__DIR__.'/../../ext/standard/JitNl2br.php');

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/nl2br.php');
        $this->assertStringContainsString('StringNl2br::ensureLinked', $builtin);
        $this->assertStringContainsString('__string__nl2br', $builtin);
        $this->assertStringNotContainsString('JitNl2br', $builtin);
    }

    public function testNl2brJitHelperDelegatesToVmString(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/Nl2brJitHelper.php');
        $this->assertStringContainsString('VmString::nl2br', $source);

        $expected = VmString::nl2br("a\nb", true);
        $this->assertSame($expected, Nl2brJitHelper::nl2brArgv("a\nb", 1));
        $this->assertSame(VmString::nl2br("a\nb", false), Nl2brJitHelper::nl2brArgv("a\nb", 0));
    }

    public function testSpineBundleOmitsDeletedJitNl2br(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringNotContainsString('JitNl2br.php', $spine);
        $this->assertStringContainsString('Nl2brJitHelper.php', $spine);
        $this->assertStringContainsString('StringNl2br.php', $spine);
    }
}
