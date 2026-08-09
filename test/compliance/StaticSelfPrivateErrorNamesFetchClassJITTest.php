<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/StaticSelfPrivateErrorNamesFetchClassVMTest.php';

/**
 * JIT: static private Error names fetch class (#29524).
 *
 * @group llvm
 * @group jit
 */
final class StaticSelfPrivateErrorNamesFetchClassJITTest extends StaticSelfPrivateErrorNamesFetchClassVMTest
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
