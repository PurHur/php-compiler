<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/** get_class_methods() C runtime shrink (#6339). */
final class GetClassMethodsRuntimeShrinkTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
    }

    public function testPhpcClassMethodsCRuntimeRemovedFromLinker(): void
    {
        $linker = file_get_contents($this->repoRoot.'/lib/AOT/Linker.php');
        $this->assertIsString($linker);
        $this->assertStringNotContainsString('phpc_class_methods.c', $linker);
        $this->assertFileDoesNotExist($this->repoRoot.'/lib/AOT/runtime/phpc_class_methods.c');
        $this->assertFileDoesNotExist($this->repoRoot.'/lib/JIT/Builtin/MethodRegistry.php');
    }

    public function testJitLoweringUsesPhpCompileTimePathOnly(): void
    {
        $source = file_get_contents($this->repoRoot.'/ext/standard/JitGetClassMethods.php');
        $this->assertIsString($source);
        $this->assertStringNotContainsString('phpc_get_class_methods', $source);
        $this->assertStringNotContainsString('MethodRegistry', $source);
        $this->assertStringNotContainsString('invokeNativeForClassName', $source);
        $this->assertStringContainsString('invokeCompileTimeForClassName', $source);
        $this->assertStringContainsString('invokeForRuntimeClassNameString', $source);
        $this->assertStringContainsString('invokeForEnumCaseValueBox', $source);
    }

    /**
     * @group llvm
     */
    public function testEnumCaseOperandLoweringCompiles(): void
    {
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason.' — get_class_methods enum-case JIT compile test needs LLVM');
        }
        $target = $this->repoRoot.'/test/fixtures/aot/compile-only/get_class_methods_enum_case.php';
        $this->assertFileExists($target);
        $code = (string) file_get_contents($target);
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'get_class_methods_enum_case_jit_compile.php');
        $runtime->jitCompileBlock($block);

        $this->addToAssertionCount(1);
    }
}
