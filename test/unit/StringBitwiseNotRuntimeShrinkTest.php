<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\StringBitwiseNotJitHelper;
use PHPUnit\Framework\TestCase;

/**
 * String bitwise: call-site LLVM for AOT (#32431 / #35301); PHP helper remains SSOT for VM probes.
 */
final class StringBitwiseNotRuntimeShrinkTest extends TestCase
{
    public function testStringBitwiseNotUsesCallSiteLlvmNotNestedJit(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringBitwiseNot.php');
        $this->assertStringContainsString('emitUnary', $source);
        $this->assertStringContainsString('emitBinary', $source);
        $this->assertStringContainsString('str_bitnot_body', $source);
        $this->assertStringContainsString('#35301', $source);
        $this->assertStringNotContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT\\NestedJitCompileScope;', $source);
        $this->assertLessThan(280, \substr_count($source, "\n") + 1);
    }

    public function testHelperUnaryOpUsesEmitUnary(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Helper.php');
        $this->assertStringContainsString('StringBitwiseNot::emitUnary', $source);
        $this->assertStringContainsString('#35301', $source);
        $fn = strpos($source, 'function unaryOp');
        $this->assertNotFalse($fn);
        $end = strpos($source, 'function binaryOp', $fn);
        $this->assertNotFalse($end);
        $chunk = substr($source, $fn, $end - $fn);
        $this->assertStringContainsString('TYPE_STRING', $chunk);
        $this->assertStringContainsString('StringBitwiseNot::emitUnary', $chunk);
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
