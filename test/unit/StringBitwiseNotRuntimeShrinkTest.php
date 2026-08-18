<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\StringBitwiseNotJitHelper;
use PHPUnit\Framework\TestCase;

/** String unary ~ JIT: StringBitwiseNotJitHelper via JitVmHelperLink (#14823, #24513). */
final class StringBitwiseNotRuntimeShrinkTest extends TestCase
{
    public function testStringBitwiseNotUsesJitHelperNotInlineLlvm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringBitwiseNot.php');
        $this->assertStringContainsString('StringBitwiseNotJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT\\NestedJitCompileScope;', $source);
        $this->assertStringNotContainsString('bitwise_not_loop', $source);
        $this->assertStringNotContainsString('bitwise_not_body', $source);
        $this->assertLessThan(220, \substr_count($source, "\n") + 1);
        $this->assertStringContainsString('emitBinary', $source);
    }

    public function testStringBitwiseBinaryJitHelperMatchesZendByteWise(): void
    {
        $this->assertSame('A', StringBitwiseNotJitHelper::bitwiseAndArgv('AB', 'A'));
        $this->assertSame('CC', StringBitwiseNotJitHelper::bitwiseOrArgv('A', 'BC'));
        $this->assertSame("\x02", StringBitwiseNotJitHelper::bitwiseXorArgv('AB', 'C'));
        $this->assertSame('03', \bin2hex(StringBitwiseNotJitHelper::bitwiseXorArgv('a', 'b')));
        $this->assertSame('x', StringBitwiseNotJitHelper::bitwiseOrArgv('', 'x'));
        $this->assertSame('', StringBitwiseNotJitHelper::bitwiseAndArgv('xy', ''));
        $this->assertSame('3', StringBitwiseNotJitHelper::bitwiseAndArgv('7', '3'));
    }

    public function testStringBitwiseNotJitHelperMatchesVmStringOperand(): void
    {
        $input = '5';
        $expected = '';
        for ($i = 0, $len = \strlen($input); $i < $len; ++$i) {
            $expected .= \chr((~\ord($input[$i])) & 0xFF);
        }
        $this->assertSame($expected, StringBitwiseNotJitHelper::bitwiseNotArgv($input));
        $this->assertSame('ca', \bin2hex(StringBitwiseNotJitHelper::bitwiseNotArgv('5')));
    }

    public function testSpineBundleIncludesStringBitwiseNotJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('StringBitwiseNotJitHelper.php', $spine);
        $this->assertStringContainsString('StringBitwiseNot.php', $spine);
    }
}
