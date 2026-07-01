<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\StrposJitHelper;
use PHPCompiler\ext\standard\VmString;
use PHPUnit\Framework\TestCase;

/** strpos()/stripos() JIT routes through StrposJitHelper PHP not inline LLVM (#14766). */
final class StrposRuntimeShrinkTest extends TestCase
{
    public function testStringStrposUsesJitHelperNotInlineLlvm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringStrpos.php');
        $this->assertStringContainsString('StrposJitHelper', $source);
        $this->assertFileDoesNotExist(__DIR__.'/../../ext/standard/JitStrpos.php');

        $strpos = (string) file_get_contents(__DIR__.'/../../ext/standard/strpos.php');
        $this->assertStringContainsString('StringStrpos::invoke', $strpos);
        $this->assertStringNotContainsString('JitStrpos::find', $strpos);

        $stripos = (string) file_get_contents(__DIR__.'/../../ext/standard/stripos.php');
        $this->assertStringContainsString('StringStrpos::invoke', $stripos);
    }

    public function testStrposJitHelperDelegatesToVmString(): void
    {
        $this->assertSame(2, StrposJitHelper::strposArgv('hello', 'l', 0));
        $this->assertSame(0, StrposJitHelper::strposArgv('hello', 'z', 0));
        $expected = VmString::stripos('Hello World', 'O', 0);
        $this->assertSame(
            false === $expected ? 0 : $expected,
            StrposJitHelper::striposArgv('Hello World', 'O', 0)
        );
    }

    public function testSpineBundleIncludesStrposJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('StrposJitHelper.php', $spine);
        $this->assertStringContainsString('StringStrpos.php', $spine);
        $this->assertStringNotContainsString('JitStrpos.php', $spine);
    }
}
