<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\UcwordsJitHelper;
use PHPCompiler\ext\standard\VmString;
use PHPUnit\Framework\TestCase;

/** ucwords() JIT routes through UcwordsJitHelper + JitVmHelperLink (#14717, #21726). */
final class UcwordsRuntimeShrinkTest extends TestCase
{
    public function testStringUcwordsUsesJitHelperNotInlineLlvm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringUcwords.php');
        $this->assertStringContainsString('UcwordsJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('ensureJitHelperCompiled', $source);
        $this->assertStringNotContainsString('transformInPlace', $source);
        $this->assertStringNotContainsString('emitCharInStringCheck', $source);

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/ucwords.php');
        $this->assertStringContainsString('StringUcwords::ensureLinked', $builtin);
        $this->assertStringContainsString('__string__ucwords', $builtin);
    }

    public function testUcwordsJitHelperDelegatesToVmString(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/UcwordsJitHelper.php');
        $this->assertStringContainsString('VmString::asciiUcwords', $source);
        $this->assertStringContainsString('VmString::asciiUcwordsEx', $source);

        $this->assertSame('Hello World', UcwordsJitHelper::ucwordsArgv('hello world'));
        $this->assertSame(VmString::asciiUcwords('hello world'), UcwordsJitHelper::ucwordsArgv('hello world'));
        $this->assertSame('Hello-World', UcwordsJitHelper::ucwordsExArgv('hello-world', '-'));
    }

    public function testSpineBundleIncludesUcwordsJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('UcwordsJitHelper.php', $spine);
        $this->assertStringContainsString('StringUcwords.php', $spine);
    }
}
