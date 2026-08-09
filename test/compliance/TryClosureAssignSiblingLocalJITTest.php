<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';
require_once __DIR__.'/TryClosureAssignSiblingLocalVMTest.php';

/**
 * JIT: try-block assign of closure call result writes target CV (#29482).
 *
 * @group llvm
 * @group jit
 */
final class TryClosureAssignSiblingLocalJITTest extends TryClosureAssignSiblingLocalVMTest
{
    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped(
                'LLVM shared library not available (set PHP_COMPILER_LLVM_PATH or install via script/install-llvm9.sh)'
            );
        }
    }
}
