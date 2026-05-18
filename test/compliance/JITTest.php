<?php

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;
use PHPCompiler\Backend\VM\Runtime;

require_once __DIR__ . '/../BaseTest.php';

/**
 * @group llvm
 */
class JITTest extends BaseTest {

    protected static string $DIR = __DIR__;

    public function setUp(): void {
        $this->BIN = realpath(__DIR__ . '/../../bin/jit.php');
        $llvmDir = dirname(__DIR__, 2).'/.llvm';
        if (is_file($llvmDir.'/libLLVM-9.so.1')) {
            $prefix = realpath($llvmDir) ?: $llvmDir;
            if ('' === getenv('PHP_COMPILER_LLVM_PATH')) {
                putenv('PHP_COMPILER_LLVM_PATH='.$prefix);
            }
        }
        try {
            \PHPLLVM\Chooser::choose();
        } catch (\Throwable $e) {
            $this->markTestSkipped('LLVM not available for JIT tests: '.$e->getMessage());
        }
    }

}