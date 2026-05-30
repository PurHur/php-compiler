<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * LLVM compile-only verify for user function/method return types (#55).
 *
 * IR verify via {@see JIT\Context::compileCommon()} — no MCJIT link/execute.
 * MCJIT execute remains environment-sensitive (#2055); AOT execute covered by {@see AotTest}.
 *
 * php-src: Zend/zend_compile.c (zend_compile_return), Zend/zend_type.c
 *
 * @group llvm
 */
final class FunctionReturnTypeJitCompileTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason.' — return-type JIT compile test needs LLVM (#55)');
        }
    }

    public function testReturnTypeModulesVerify(): void
    {
        $runtime = new Runtime();
        $cases = [
            'function_return_string.php' => <<<'PHP'
<?php
function greet(): string { return 'ok'; }
echo greet();
PHP,
            'function_return_int.php' => <<<'PHP'
<?php
function count_items(): int { return 42; }
echo count_items();
PHP,
            'function_return_bool.php' => <<<'PHP'
<?php
function yes(): bool { return true; }
echo yes() ? '1' : '0';
PHP,
            'function_return_float.php' => <<<'PHP'
<?php
function ratio(): float { return 1.5; }
echo ratio();
PHP,
            'function_return_nullable.php' => <<<'PHP'
<?php
function maybe(): ?string { return null; }
echo maybe() ?? 'null';
PHP,
            'function_return_array.php' => <<<'PHP'
<?php
function pair(): array { return [1, 2]; }
echo count(pair());
PHP,
            'function_return_method.php' => <<<'PHP'
<?php
class C {
    public function label(): string { return 'ok'; }
}
echo (new C())->label();
PHP,
        ];

        foreach ($cases as $filename => $code) {
            $block = $runtime->parseAndCompile($code, $filename);
            $this->assertNotNull($block, $filename);
            $runtime->jitCompileBlock($block);
        }

        $context = $runtime->loadJitContext();
        $verify = new \ReflectionMethod($context, 'compileCommon');
        $verify->setAccessible(true);
        $verify->invoke($context);
        $this->addToAssertionCount(1);
    }
}
