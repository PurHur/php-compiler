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
            // VM-only until ArrayBuiltinHelper gains recursive replace (#3127).
            if (str_contains(strtolower($case[0]), 'array_replace_recursive')) {
                continue;
            }
            // ksort/uksort string-key hashtable JIT — KsortJITTest / UksortJITTest (#2271, #3143).
            if (str_contains($name, 'ksort_jit') || str_contains($name, 'uksort')) {
                continue;
            }
            // class_uses() is VM-only until JIT lowering (#3119).
            if (str_contains($name, 'class_uses_runtime')) {
                continue;
            }
            // class_alias() is VM-only (#3095).
            if (str_contains(strtolower($case[0]), 'class_alias')) {
                continue;
            }
            // gc_collect_cycles() is VM-only (#3113).
            if (str_contains($name, 'gc_collect_cycles')) {
                continue;
            }
            // count() on Countable objects is VM-only until JIT object dispatch (#3364).
            if (str_contains($name, 'countable')) {
                continue;
            }
            // array_walk_recursive() is VM-only until recursive LLVM walk (#3111).
            if (str_contains($name, 'array_walk_recursive')) {
                continue;
            }
            // preg_last_error_msg() MCJIT path unsafe with preg_match stub runtime (#3110).
            if (str_contains($name, 'preg_last_error_msg')) {
                continue;
            }
            // json_validate() MCJIT path unsafe until __compiler_json_validate link is stable (#3101).
            if (str_contains($name, 'json_validate')) {
                continue;
            }
            // Stringable __toString in echo/concat is VM-only until magic method JIT (#146, #3296).
            if (str_contains($name, 'stringable')) {
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