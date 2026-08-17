<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * LLVM compile-only verify for numeric-string + int binary ops (#31967).
 *
 * php-src: Zend/zend_operators.c add_function()
 *
 * @group llvm
 */
final class NumericStringArithmeticJitCompileTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason.' — numeric-string arithmetic JIT compile test needs LLVM (#31967)');
        }
    }

    public function testNumericStringPlusNativeLongModuleVerify(): void
    {
        $code = <<<'PHP'
<?php
var_dump("5" + 5);
var_dump(5 + "5");
var_dump("10" - "3");
var_dump("6" * "7");
var_dump("10" / "4");
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'numeric_string_arithmetic_jit.php');
        $this->assertNotNull($block);
        $runtime->jitCompileBlock($block);
        $this->addToAssertionCount(1);
    }

    public function testNanSpaceshipModuleVerify(): void
    {
        $code = <<<'PHP'
<?php
var_dump(NAN <=> 1.0);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'nan_spaceship_jit.php');
        $this->assertNotNull($block);
        $runtime->jitCompileBlock($block);
        $this->addToAssertionCount(1);
    }
}
