<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * LLVM compile-only verify for class constants holding enum case singletons (#4445).
 *
 * php-src: Zend/zend_compile.c — enum case in zend_compile_const_expr / class constants.
 *
 * @group llvm
 * @runInSeparateProcess
 * @preserveGlobalState disabled
 */
final class ClassConstEnumCaseJitCompileTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason.' — enum class const JIT compile test needs LLVM (#4445)');
        }
    }

    public function testEnumCaseClassConstModuleVerify(): void
    {
        // Compile class-const fetch + enum case `->name` (full phpt also uses get_debug_type / `->value`).
        $code = <<<'PHP'
<?php
enum E: int {
    case A = 1;
}
class C {
    public const X = E::A;
}
echo (C::X === E::A) ? "same\n" : "diff\n";
echo C::X->name, "\n";
PHP;

        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'enum_case_class_const_jit.php');
        $runtime->jitCompileBlock($block);

        $context = $runtime->loadJitContext();
        $bc = $context->module->printToString();
        $this->assertStringContainsString(
            'php_compiler_enum_case_singleton_',
            $bc,
            'Expected immortal enum-case singleton global for class constant (#4445)'
        );
        $verify = new \ReflectionMethod($context, 'compileCommon');
        $verify->setAccessible(true);
        $verify->invoke($context);
        $this->addToAssertionCount(1);
    }
}
