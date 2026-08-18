<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * LLVM compile-only verify for remaining #31967 AOT lowering gaps.
 *
 * php-src: Zend/zend_compile.c (const expr), Zend/zend_execute.c (INIT_STATIC_METHOD_CALL),
 * Zend/zend_enum.c (enum case class constants), Zend/zend_inheritance.c (interface consts).
 *
 * @group llvm
 */
final class Issue31967AotConstStaticTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason.' — #31967 remaining AOT probes need LLVM');
        }
    }

    public function testEnumCaseClassConstModuleVerify(): void
    {
        $this->compileOrFail(file_get_contents($this->repoRoot.'/test/repro/issue_31967_enum_class_const.php') ?: '', 'enum_class_const');
    }

    public function testVariableStaticCallModuleVerify(): void
    {
        $this->compileOrFail(file_get_contents($this->repoRoot.'/test/repro/issue_31967_variable_static_call.php') ?: '', 'variable_static_call');
    }

    public function testInterfaceSelfConstModuleVerify(): void
    {
        $this->compileOrFail(file_get_contents($this->repoRoot.'/test/repro/issue_31967_interface_self_const.php') ?: '', 'interface_self_const');
    }

    public function testStaticPropArrayLiteralModuleVerify(): void
    {
        $this->compileOrFail(file_get_contents($this->repoRoot.'/test/repro/issue_31967_static_prop_array.php') ?: '', 'static_prop_array');
    }

    private function compileOrFail(string $code, string $label): void
    {
        $this->assertNotSame('', $code);
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'issue_31967_'.$label.'.php');
        $this->assertNotNull($block);
        $runtime->jitCompileBlock($block);
        $this->addToAssertionCount(1);
    }
}
