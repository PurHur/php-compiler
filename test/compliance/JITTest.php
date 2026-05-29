<?php

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;
use PHPCompiler\Backend\VM\Runtime;

require_once __DIR__ . '/../BaseTest.php';

/**
 * @group llvm
 * @group jit
 */
class JITTest extends BaseTest {

    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        foreach (parent::providePHPTests() as $name => $case) {
            // ?-> on objects needs JIT class/property support (#308); VM compliance covers it.
            if (str_contains(strtolower($case[0]), 'nullsafe')) {
                continue;
            }
            // SplObjectStorage JIT-only (#1998); see SplObjectStorageJITTest.
            if (str_contains(strtolower($case[0]), 'splobjectstorage')) {
                continue;
            }
            if (str_contains(strtolower($case[0]), 'spl_autoload_register_jit')) {
                continue;
            }
            // preserve_keys=true is VM-only until ArrayBuiltinHelper gains the branch (#3096).
            if (str_contains($name, 'array_chunk_preserve_keys')) {
                continue;
            }
            // class_uses() is VM-only until JIT lowering (#3119).
            if (str_contains($name, 'class_uses_runtime')) {
                continue;
            }
            yield $name => $case;
        }
    }

    public function setUp(): void {
        $this->BIN = realpath(__DIR__ . '/../../bin/jit.php');
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped(
                'LLVM 9 toolchain not available. Run script/install-llvm9.sh or use the 22.04-dev Docker image.'
            );
        }
    }

}