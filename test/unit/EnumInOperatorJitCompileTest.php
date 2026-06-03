<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * LLVM compile-only verify for enum `in` operator JIT lowering (#4716).
 *
 * php-src: Zend/zend_compile.c — `in` operator
 *
 * @group llvm
 */
final class EnumInOperatorJitCompileTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason.' — enum `in` JIT compile test needs LLVM (#4716)');
        }
    }

    public function testEnumInOperatorModuleVerify(): void
    {
        $code = <<<'PHP'
<?php
enum E: string { case A = 'a'; case B = 'b'; }
var_export(E::A in [E::A, E::B]);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'enum_in_jit_compile.php');
        $runtime->jitCompileBlock($block);

        $context = $runtime->loadJitContext();
        $bc = $context->module->printToString();
        $this->assertStringContainsString('in_array_head_in_op', $bc);
        $verify = new \ReflectionMethod($context, 'compileCommon');
        $verify->setAccessible(true);
        $verify->invoke($context);
        $this->addToAssertionCount(1);
    }
}
