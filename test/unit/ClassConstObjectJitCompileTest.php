<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * LLVM compile-only verify for class constants with object expressions (#3196, #4021, #4028).
 *
 * php-src: Zend/zend_compile.c — zend_compile_const_expr; immortal singleton at class init.
 *
 * @group llvm
 */
final class ClassConstObjectJitCompileTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason.' — class const object JIT compile test needs LLVM (#4021)');
        }
    }

    public function testClassConstObjectModuleVerify(): void
    {
        $runtime = new Runtime();
        $path = $this->repoRoot.'/test/compliance/cases/language/class_const_object_run.php';
        $code = file_get_contents($path);
        $this->assertNotFalse($code);
        $block = $runtime->parseAndCompile($code, $path);
        $this->assertNotNull($block);
        $runtime->jitCompileBlock($block);

        $context = $runtime->loadJitContext();
        $bc = $context->module->printToString();
        $this->assertStringContainsString(
            'php_compiler_class_const_obj_',
            $bc,
            'Expected immortal class-const object global in LLVM module'
        );
        $verify = new \ReflectionMethod($context, 'compileCommon');
        $verify->setAccessible(true);
        $verify->invoke($context);
        $this->addToAssertionCount(1);
    }
}
