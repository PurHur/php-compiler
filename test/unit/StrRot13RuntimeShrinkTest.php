<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\StrRot13JitHelper;
use PHPCompiler\ext\standard\VmString;
use PHPUnit\Framework\TestCase;

/** str_rot13() JIT routes through StrRot13JitHelper PHP not inline LLVM (#14896, #26868). */
final class StrRot13RuntimeShrinkTest extends TestCase
{
    public function testStringStrRot13UsesJitHelperNotInlineLlvm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringStrRot13.php');
        $this->assertStringContainsString('StrRot13JitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $source);
        $this->assertStringContainsString('NestedJitCompileScope::isActive', $source);
        $this->assertFileDoesNotExist(__DIR__.'/../../ext/standard/JitStrRot13.php');

        $strRot13 = (string) file_get_contents(__DIR__.'/../../ext/standard/str_rot13.php');
        $this->assertStringContainsString('StringStrRot13::ensureLinked', $strRot13);
        $this->assertStringContainsString('__compiler_str_rot13', $strRot13);
        $this->assertStringNotContainsString('JitStrRot13', $strRot13);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringStrRot13.php');
        $this->assertStringContainsString('__compiler_str_rot13', $bridge);
    }

    public function testStrRot13JitHelperIsSelfContained(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/StrRot13JitHelper.php');
        // Call site must not delegate into VmString (NestedJIT stubs that path — #26868).
        $this->assertStringNotContainsString('return VmString::', $source);
        $this->assertStringContainsString('rot13Char', $source);
        $this->assertStringContainsString('match ($ch)', $source);

        $this->assertSame('uryyb', StrRot13JitHelper::rot13Argv('hello'));
        $this->assertSame('CUC', StrRot13JitHelper::rot13Argv('PHP'));
        $this->assertSame('PHP', StrRot13JitHelper::rot13Argv('CUC'));
        $this->assertSame('123', StrRot13JitHelper::rot13Argv('123'));
        $this->assertSame('', StrRot13JitHelper::rot13Argv(''));
        // Host / VM SSOT still matches.
        $this->assertSame(VmString::strRot13('hello'), StrRot13JitHelper::rot13Argv('hello'));
        $this->assertSame(VmString::strRot13('Hello'), StrRot13JitHelper::rot13Argv('Hello'));
    }

    public function testSpineBundleIncludesStrRot13JitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringNotContainsString('JitStrRot13.php', $spine);
        $this->assertStringContainsString('StrRot13JitHelper.php', $spine);
        $this->assertStringContainsString('StringStrRot13.php', $spine);
    }
}
