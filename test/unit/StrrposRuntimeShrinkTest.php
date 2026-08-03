<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\StrrposJitHelper;
use PHPCompiler\ext\standard\VmString;
use PHPUnit\Framework\TestCase;

/** strrpos()/strripos() JIT routes through StrrposJitHelper PHP not inline LLVM (#14752). */
final class StrrposRuntimeShrinkTest extends TestCase
{
    public function testStringStrrposUsesJitHelperNotInlineLlvm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringStrrpos.php');
        $this->assertStringContainsString('VmStringCompare::findROffset', $source);
        $this->assertStringContainsString('boxIntOrFalse', $source);
        $this->assertFileDoesNotExist(__DIR__.'/../../ext/standard/JitStrrpos.php');

        $strrpos = (string) file_get_contents(__DIR__.'/../../ext/standard/strrpos.php');
        $this->assertStringContainsString('StringStrrpos::invoke', $strrpos);
        $this->assertStringContainsString('StringStrrpos::boxIntOrFalse', $strrpos);
        $this->assertStringNotContainsString('JitStrrpos::find', $strrpos);

        $strripos = (string) file_get_contents(__DIR__.'/../../ext/standard/strripos.php');
        $this->assertStringContainsString('StringStrrpos::invoke', $strripos);
    }

    public function testStrrposJitHelperDelegatesToVmString(): void
    {
        $this->assertSame(7, StrrposJitHelper::strrposArgv('hello world', 'o', 0));
        $this->assertSame(StrrposJitHelper::NOT_FOUND, StrrposJitHelper::strrposArgv('hello', 'z', 0));
        $expected = VmString::strripos('Hello World', 'O', 0);
        $this->assertSame(
            false === $expected ? StrrposJitHelper::NOT_FOUND : $expected,
            StrrposJitHelper::strriposArgv('Hello World', 'O', 0)
        );
        $this->assertSame(0, StrrposJitHelper::strrposArgv('hello', 'h', 0));
    }

    public function testSpineBundleIncludesStrrposJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('StrrposJitHelper.php', $spine);
        $this->assertStringContainsString('StringStrrpos.php', $spine);
        $this->assertStringNotContainsString('JitStrrpos.php', $spine);
    }
}
