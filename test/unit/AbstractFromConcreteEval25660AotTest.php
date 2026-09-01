<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * @covers issue #25660 — cross-eval abstract-from-concrete must Fatal on VM and AOT emit
 *
 * @group llvm
 * @group aot
 */
final class AbstractFromConcreteEval25660AotTest extends TestCase
{
    public function testVmEvalRejectsAbstractFromConcrete(): void
    {
        $repro = dirname(__DIR__).'/repro/issue_25660_abstract_from_concrete_eval.php';
        $this->assertFileExists($repro);
        $code = file_get_contents($repro);
        $this->assertNotFalse($code);

        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'issue_25660_vm.php');
        $this->assertNotNull($block);
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Cannot make non abstract method A::f() abstract in class B');
        ob_start();
        try {
            $runtime->run($block);
        } finally {
            ob_end_clean();
        }
    }

    public function testAotEmitRejectsAbstractFromConcreteEval(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $repro = dirname(__DIR__).'/repro/issue_25660_abstract_from_concrete_eval.php';
        $code = file_get_contents($repro);
        $this->assertNotFalse($code);

        try {
            $aot = new Runtime(Runtime::MODE_AOT);
            $block = $aot->parseAndCompile($code, 'issue_25660_aot.php');
            $aot->jit($block);
            $this->fail('Expected CompileFatal during AOT emit of abstract-from-concrete eval (#25660)');
        } catch (\PHPCompiler\Compiler\CompileFatal $e) {
            $this->assertStringContainsString(
                'Cannot make non abstract method A::f() abstract in class B',
                $e->getMessage()
            );
        } catch (\FFI\Exception $e) {
            $this->markTestSkipped('LLVM FFI unavailable on host: '.$e->getMessage());
        }
    }
}
