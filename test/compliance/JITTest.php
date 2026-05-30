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
            // ?-> LLVM lowering verified in NullsafeJitCompileTest (#3219); MCJIT execute needs jit-runtime-probe (#98).
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
            // enum case ->name / ->value is VM-only until JIT enum case objects (#3420).
            if (str_contains($name, 'enum_case_name_value')) {
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
            // #[\AllowDynamicProperties] is VM-only until JIT class flag (#3467).
            if (str_contains($name, 'allow_dynamic_properties')) {
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
            // (unset) cast reference break is VM-only until JIT TYPE_CAST_UNSET lowering (#3517).
            if (str_contains($name, 'cast_unset')) {
                continue;
            }
            // exit/die expression ScriptExit status — VM compliance (#3539).
            if (str_contains($name, 'exit_expression') || str_contains($name, 'die_expression')) {
                continue;
            }
            // class const scalar expressions — VM defineClass eval (#3567); JIT deferred.
            if (str_contains($name, 'class_const_scalar_expr')) {
                continue;
            }
            // Top-level script globals — VM symbol table (#3601); JIT LLVM global slots deferred.
            if (str_contains($name, 'global_top_level')) {
                continue;
            }
            // Stringable __toString in echo/concat is VM-only until magic method JIT (#146, #3296).
            if (str_contains($name, 'stringable')) {
                continue;
            }
            // Enum::cases() is VM-only until JIT enum case lowering (#3308).
            if (str_contains($name, 'enum_cases')) {
                continue;
            }
            // object == structural compare is VM-only until JIT Object_ lowering (#3602).
            if (str_contains($name, 'object_loose_equals')) {
                continue;
            }
            // string/number loose == juggling is VM-only until ArrayBuiltinHelper string-long compare (#3644).
            if (str_contains($name, 'loose_numeric_string')) {
                continue;
            }
            // object === identity compare is VM-only until JIT handle compare is stable (#3622).
            if (str_contains($name, 'object_identical')) {
                continue;
            }
            // gettype() object/resource is VM-only until __compiler_gettype JIT path is stable (#3618).
            if (str_contains($name, 'gettype_object_resource')) {
                continue;
            }
            // __TRAIT__ in trait bodies requires trait JIT lowering (#3609); parse-time fold is VM-only for now.
            if (str_contains($name, 'magic_const_trait')) {
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