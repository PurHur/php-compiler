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
            // new static() / : static return — VM late binding (#3412); JIT phase 2.
            if (str_contains($name, 'new_static') || str_contains($name, 'static_return_type')) {
                continue;
            }
            // gc_collect_cycles() is VM-only (#3113).
            if (str_contains($name, 'gc_collect_cycles')) {
                continue;
            }
            // WeakReference get() return used in locals — MCJIT execute (#3667).
            if (str_contains($name, 'weak_reference_gc_jit')) {
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
            // __halt_compiler() is compile-time only (#3479).
            if (str_contains($name, 'halt_compiler')) {
                continue;
            }
            // gethostname() MCJIT: dedicated GethostnameJITTest (#3465); umbrella JITTest skips until stable.
            if (str_contains($name, 'gethostname')) {
                continue;
            }
            // substr() boxed int/class const MCJIT: VM passes (#587); execute segfaults until stable.
            if (str_contains($name, 'substr_jit')) {
                continue;
            }
            // getrusage() MCJIT: dedicated compliance JIT path (#3240); umbrella JITTest skips until stable.
            if (str_contains($name, 'getrusage')) {
                continue;
            }
            // phpversion/php_sapi_name/php_uname MCJIT: VM + AOT (#3174); umbrella JITTest skips until stable.
            if (str_contains($name, 'phpversion')) {
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
            // count() COUNT_RECURSIVE is VM-only until recursive LLVM count (#3511).
            if (str_contains($name, 'count_recursive')) {
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
            // Generator::getReturn() and generator return slot are VM-only until JIT generators (#167, #3350).
            if (str_contains($name, 'generator_get_return')) {
                continue;
            }
            // ?: merge branch slot unification is VM-only until JIT CFG merge (#3790).
            if (str_contains($name, 'ternary_func_call')) {
                continue;
            }
            // Nested break/continue levels use php-cfg goto labels; VM-only until JIT (#3405).
            if (str_contains($name, 'break2_') || str_contains($name, 'continue2_')) {
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
            // Global function __METHOD__/__FUNCTION__ — parse-time literals; MCJIT segfault (#3595).
            if (str_contains($name, 'magic_const_method_function')) {
                continue;
            }
            // object == structural compare is VM-only until JIT Object_ lowering (#3602).
            if (str_contains($name, 'object_loose_equals')) {
                continue;
            }
            // object <=> is VM-only until JIT zend_compare_objects lowering (#3691).
            if (str_contains($name, 'spaceship_objects')) {
                continue;
            }
            // Return-by-reference MCJIT execute: LLVM verify in ReturnByRefJitCompileTest (#3778).
            if (str_contains($name, 'return_by_ref_jit')) {
                continue;
            }
            // string/number loose == juggling is VM-only until ArrayBuiltinHelper string-long compare (#3644).
            if (str_contains($name, 'loose_numeric_string')) {
                continue;
            }
            if (str_contains($name, 'loose_int_empty_string')) {
                continue;
            }
            // string/string loose == numeric compare is VM-only until JIT string== is stable (#3680).
            if (str_contains($name, 'loose_string_scientific')) {
                continue;
            }
            // array/scalar loose == is VM-only until JIT array compare matrix is stable (#3736).
            if (str_contains($name, 'loose_eq_array_scalar')) {
                continue;
            }
            // object === identity compare is VM-only until JIT handle compare is stable (#3622).
            if (str_contains($name, 'object_identical')) {
                continue;
            }
            // foreach over user objects / stdClass is VM-only until IteratorHelper object walk (#3661).
            if (str_contains($name, 'foreach_object_by_ref')) {
                continue;
            }
            // array <=> array is VM-only until __hashtable__compareSpaceship JIT lowering (#3672).
            if (str_contains($name, 'spaceship_array')) {
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
            // pre/post inc/dec VM-only until JIT lowering (#3552).
            if (str_contains($name, 'pre_post_inc')) {
                continue;
            }
            // Property hooks: LLVM dispatch lands in #3723; MCJIT still crashes on hook classes (#3145).
            if (str_contains($name, 'property_hook')) {
                continue;
            }
            // array union (+/+=) is VM-only until JIT TYPE_PLUS array branch (#3690).
            if (str_contains($name, 'array_union')) {
                continue;
            }
            // Instance method first-class callable is VM-only until JIT bound-method FCC (#3566).
            if (str_contains($name, 'first_class_callable_method')) {
                continue;
            }
            // User enum DECLARE_ENUM segfaults in MCJIT until enum lowering is stable (#3518).
            if (str_contains($name, 'enum_') || str_contains($name, 'abstract_enum')) {
                continue;
            }
            // ++/-- string increment_string is VM-only until JIT reads OpCode::isIncDec (#3469).
            if (str_contains($name, 'string_increment')) {
                continue;
            }
            // Negative string offsets: VM (#3751); MCJIT StringOffsetHelper still segfaults (#198).
            if (str_contains($name, 'string_negative_offset')) {
                continue;
            }
            // substr() boxed int/class const MCJIT: VM passes (#587); execute segfaults until stable.
            if (str_contains($name, 'substr_jit')) {
                continue;
            }
            // property default `new` expressions — VM-only until JIT runtime init (#3391).
            if (str_contains($name, 'property_default_new')) {
                continue;
            }
            // HashTable COW is VM-only until JIT mirrors refcount separation (#3760).
            if (str_contains($name, 'array_cow')) {
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