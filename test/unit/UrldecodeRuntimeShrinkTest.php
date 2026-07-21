<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\UrldecodeJitHelper;
use PHPCompiler\ext\standard\VmString;
use PHPUnit\Framework\TestCase;

/** urldecode()/rawurldecode() JIT routes through UrldecodeJitHelper + JitVmHelperLink (#14726, #21686). */
final class UrldecodeRuntimeShrinkTest extends TestCase
{
    public function testStringUrldecodeUsesJitHelperNotInlineLlvm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringUrldecode.php');
        $this->assertStringContainsString('UrldecodeJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('ensureJitHelperCompiled', $source);
        $this->assertStringNotContainsString('countLoop', $source);
        $this->assertStringNotContainsString('formDecoding', $source);
    }

    public function testUrldecodeJitHelperDelegatesToVmString(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/UrldecodeJitHelper.php');
        $this->assertStringContainsString('VmString::urldecode', $source);
        $this->assertStringContainsString('VmString::rawurldecode', $source);

        $this->assertSame('foo bar', UrldecodeJitHelper::urldecodeArgv('foo+bar'));
        $this->assertSame('foo+bar', UrldecodeJitHelper::rawurldecodeArgv('foo%2Bbar'));
        $this->assertSame('foo bar', VmString::urldecode('foo+bar'));
        $this->assertSame('foo+bar', VmString::rawurldecode('foo%2Bbar'));
    }

    public function testSpineBundleIncludesUrldecodeJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('UrldecodeJitHelper.php', $spine);
        $this->assertStringContainsString('StringUrldecode.php', $spine);
    }
}
