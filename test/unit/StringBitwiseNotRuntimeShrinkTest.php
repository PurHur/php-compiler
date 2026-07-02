<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\StringBitwiseNotJitHelper;
use PHPUnit\Framework\TestCase;

/** String unary ~ JIT routes through StringBitwiseNotJitHelper PHP not inline LLVM (#14823). */
final class StringBitwiseNotRuntimeShrinkTest extends TestCase
{
    public function testStringBitwiseNotUsesJitHelperNotInlineLlvm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringBitwiseNot.php');
        $this->assertStringContainsString('StringBitwiseNotJitHelper', $source);
        $this->assertStringNotContainsString('bitwise_not_loop', $source);
        $this->assertStringNotContainsString('bitwise_not_body', $source);
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
