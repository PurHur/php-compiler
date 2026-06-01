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
            // Sealed classes: VM declare-time guard; JIT lowering pending (#3322).
            if (str_contains($name, 'sealed_class')) {
                continue;
            }
            // preserve_keys=true: VM + JIT/AOT via ArrayBuiltinHelper (#3524).
            // VM-only until ArrayBuiltinHelper gains recursive replace (#3127).
            if (str_contains(strtolower($case[0]), 'array_replace_recursive')) {
                continue;
            }
            // array_merge_recursive(): VM + AOT via C overlay (#3297); MCJIT execute unstable.
            if (str_contains(strtolower($case[0]), 'array_merge_recursive')) {
                continue;
            }
            // unpack() insufficient data false + E_WARNING: VM + AOT (#3775); JIT MCJIT execute exit 255.
            if (str_contains($name, 'unpack_insufficient_data')) {
                continue;
            }
            // readline() MCJIT false return boxing unstable (#3776); VM + AOT lint green.
            if (str_contains($name, 'readline_exists')) {
                continue;
            }
            // strspn()/strcspn() offset/length: VM + AOT lint (#3734); MCJIT phpc_strspn_ex execute pending.
            if (str_contains($name, 'strspn_strcspn_offset')) {
                continue;
            }
            // vsprintf()/sscanf() VM + AOT (#3190); MCJIT execute segfaults (argv hashtable pack, same as vfprintf).
            if (str_contains($name, 'vsprintf_basic') || str_contains($name, 'sscanf_int')) {
                continue;
            }
            // array_key_exists() null key → "": VM + AOT (#3687); MCJIT execute segfaults (pre-existing hashtable path).
            if (str_contains($name, 'array_key_exists_null_key')) {
                continue;
            }
            // array_key_exists() float key coercion: VM + AOT (#3470); MCJIT execute unstable (float array keys).
            if (str_contains($name, 'array_key_exists_float')) {
                continue;
            }
            // array numeric-string key coercion: VM (#3679); MCJIT execute exit -1 until lookupStringKeyValue stable.
            if (str_contains($name, 'array_numeric_string_key')) {
                continue;
            }
            // base_convert() MCJIT execute unstable until MathBaseConvert verify (#3173).
            if (str_contains($name, 'base_convert') || str_contains(strtolower($case[0]), 'base_convert')) {
                continue;
            }
            // hexdec()/bindec() overflow boxed return: VM + AOT (#3688); MCJIT until MathBaseConvert stable.
            if (str_contains($name, 'hexdec_bindec_overflow')) {
                continue;
            }
            // hexdec/bindec/octdec boxed int|float return: VM + AOT compile (#3688); MCJIT execute pending.
            if (str_contains($name, 'hexdec') || str_contains($name, 'bindec') || str_contains($name, 'octdec')) {
                continue;
            }
            // class_uses() is VM-only until JIT lowering (#3119).
            if (str_contains($name, 'class_uses_runtime')) {
                continue;
            }
            // new static() / : static return — VM late binding (#3412); JIT phase 2.
            if (str_contains($name, 'new_static') || str_contains($name, 'static_return_type')) {
                continue;
            }
            // gc_collect_cycles() MCJIT execute unstable (#3160); compile: GcCollectCyclesJitCompileTest.
            if (str_contains($name, 'gc_collect_cycles')) {
                continue;
            }
            // gc_enable/gc_disable/gc_enabled() are VM-only (#3209).
            if (str_contains($name, 'gc_enabled')) {
                continue;
            }
            // set_exception_handler() / restore_exception_handler() VM-only (#3146).
            if (str_contains($name, 'exception_handler')) {
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
            // get_debug_type() on enum cases: VM enum class names; MCJIT deferred (#3454).
            if (str_contains($name, 'get_debug_type_enum')) {
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
            // get_defined_functions()/get_declared_functions() MCJIT: VM + dedicated PHPT (#3128/#3739); jit.php execute segfaults on merge runtime.
            if (str_contains($name, 'get_defined_functions') || str_contains($name, 'get_declared_functions')) {
                continue;
            }
            // get_resources() MCJIT: VM + AOT + dedicated --JIT-- PHPT (#3646); jit.php execute segfaults on hashtable return.
            if (str_contains($name, 'get_resources')) {
                continue;
            }
            // get_resource_id() MCJIT: VM + AOT lint (#3180); fopen/__compiler_is_resource execute segfault until stable.
            if (str_contains($name, 'get_resource_id')) {
                continue;
            }
            // stream_set_timeout/chunk_size MCJIT: VM + AOT (#3754); jit.php execute exit -1 until stable.
            if (str_contains($name, 'stream_set_timeout')) {
                continue;
            }
            // filter_var() FILTER_NULL_ON_FAILURE MCJIT: VM + AOT (#3805); jit.php execute unstable (#104).
            if (str_contains($name, 'filter_null_on_failure')) {
                continue;
            }
            // gethostname() MCJIT: dedicated GethostnameJITTest (#3465); umbrella JITTest skips until stable.
            if (str_contains($name, 'gethostname')) {
                continue;
            }
            // gethostbynamel() MCJIT: dedicated GethostbynamelJITTest (#3707).
            if (str_contains($name, 'gethostbynamel')) {
                continue;
            }
            // getprotobynumber()/getservbyport() MCJIT: NetworkServicesJITTest (#3650).
            if (str_contains($name, 'getprotobynumber')) {
                continue;
            }
            // substr() boxed int/class const MCJIT: VM passes (#587); execute segfaults until stable.
            if (str_contains($name, 'substr_jit')) {
                continue;
            }
            // addcslashes/stripcslashes/substr_replace MCJIT: VM + AOT (#3356); execute segfaults like addslashes.
            if (str_contains($name, 'addcslashes_stripcslashes_substr_replace')) {
                continue;
            }
            // getrusage() MCJIT: dedicated compliance JIT path (#3240); umbrella JITTest skips until stable.
            if (str_contains($name, 'getrusage')) {
                continue;
            }
            // memory_get_usage() MCJIT: VM + AOT (#3134); umbrella JITTest skips until stable.
            if (str_contains($name, 'memory_get_usage')) {
                continue;
            }
            // password_get_info() MCJIT: VM + AOT (#3649); jit.php execute exit -1 until stable.
            if (str_contains($name, 'password_get_info')) {
                continue;
            }
            // password_needs_rehash() MCJIT: VM + AOT (#3279); MCJIT execute segfault until stable.
            if (str_contains($name, 'password_needs_rehash')) {
                continue;
            }
            // error_reporting() MCJIT: VM + AOT (#3220); phpc_ini_set.c __value__* mismatch until stable.
            if (str_contains($name, 'error_reporting')) {
                continue;
            }
            // settype() MCJIT: LLVM JitSettype landed (#3151); umbrella JITTest until MCJIT execute stable.
            if (str_contains($name, 'settype')) {
                continue;
            }
            // compact() array/nested args: VM + AOT (#3468); MCJIT __hashtable__ type mismatch until stable.
            if (str_contains($name, 'compact_array_arg')) {
                continue;
            }
            // round() precision/mode uses __compiler_round: VM + AOT (#3522); MCJIT until runtime link stable.
            if (str_contains($name, 'round_precision_mode')) {
                continue;
            }
            // phpversion/php_sapi_name/php_uname MCJIT: VM + AOT (#3174); umbrella JITTest skips until stable.
            if (str_contains($name, 'phpversion')) {
                continue;
            }
            // ReflectionProperty/Function/Constant builtins are VM-only (#3354).
            if (str_contains($name, 'reflection_oop')) {
                continue;
            }
            // ReflectionClass::getProperties/getMethods are VM-only (#3815).
            if (str_contains($name, 'reflection_class_members')) {
                continue;
            }
            // array_walk_recursive() is VM-only until recursive LLVM walk (#3111).
            if (str_contains($name, 'array_walk_recursive')) {
                continue;
            }
            // uasort()/uksort() closure comparators are VM-only (#3582, #3143).
            if (str_contains($name, 'uasort_closure') || str_contains($name, 'uksort_closure')) {
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
            // User __destruct() is VM-only until refcount/GC ordering is stable in JIT (#3144).
            if (str_contains($name, 'class_destruct')) {
                continue;
            }
            // preg_last_error_msg() MCJIT path unsafe with preg_match stub runtime (#3110).
            if (str_contains($name, 'preg_last_error_msg')) {
                continue;
            }
            // preg_replace() $limit: VM + AOT lint (#3605); MCJIT until __compiler_preg_replace gains limit.
            if (str_contains($name, 'preg_replace_limit')) {
                continue;
            }
            // json_validate() MCJIT path unsafe until __compiler_json_validate link is stable (#3101).
            if (str_contains($name, 'json_validate')) {
                continue;
            }
            // JsonSerializable json_encode() needs VM method dispatch (#3370).
            if (str_contains($name, 'json_serializable')) {
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
            // define() case_insensitive + constant() MCJIT execute segfaults (#3711); VM + AOT PHPT green.
            if (str_contains($name, 'define_case_insensitive')) {
                continue;
            }
            // E_* error level constants MCJIT: VM + AOT (#3422); jit.php segfault on TYPE_CONST_FETCH until stable.
            if (str_contains($name, 'error_level_constants')) {
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
            // ??= MCJIT execute: LLVM verify in CoalesceAssignJitCompileTest (#3792).
            if (str_contains($name, 'coalesce_assign_jit')) {
                continue;
            }
            // Chained ?? MCJIT: VM-only until nested coalesce JIT (#3798).
            if (str_contains($name, 'coalesce_chain')) {
                continue;
            }
            // var_dump() not JIT-implemented; int↔string loose == IR guarded by LooseScientificStringJitCompileTest (#3658).
            // MCJIT execute for loose == still segfaults (jit-runtime-probe #98); compile-only JIT gates cover #3644/#3658.
            if (str_contains($name, 'loose_numeric_string') || str_contains($name, 'loose_scientific_string')) {
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
            // get_object_id() MCJIT execute segfaults with user objects; AOT verified (#3537).
            if (str_contains($name, 'get_object_id')) {
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
            // Generator foreach MCJIT resume (#3074); VM in GeneratorVMTest, compile in GeneratorJITTest/GeneratorJitCompileTest.
            if (str_contains($name, 'generator_')) {
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
            // Variable variables MCJIT execute segfaults; VM + compile probe in JitVariableVariablesTest (#3801, #1226).
            if (str_contains($name, 'variable_variables')) {
                continue;
            }
            // class_parents()/get_class_vars() MCJIT execute segfaults (#3159); AOT PHPT covers native path.
            if (str_contains($name, 'class_parents_get_class_vars')) {
                continue;
            }
            // __callStatic is VM-only until JIT static magic dispatch (#3273).
            if (str_contains($name, 'magic_call_static')) {
                continue;
            }
            // Object class constants: VM-only until JIT execute path is stable (#3196).
            if (str_contains($name, 'class_const_object')) {
                continue;
            }
            // Object foreach is VM-only until IteratorHelper gains Iterator protocol (#3234).
            if (str_contains($name, 'foreach_iterator')) {
                continue;
            }
            // gettimeofday() array sec compare is VM-only until boxed array fetch compare (#3208).
            if (str_contains($name, 'gettimeofday')) {
                continue;
            }
            // PHP 8.4 asymmetric visibility is VM-only until JIT property guards (#3165).
            if (str_contains($name, 'asymmetric_visibility')) {
                continue;
            }
            // BackedEnum::from/tryFrom VM-only until JIT lowering (#3114, #3076).
            if (str_contains($name, 'enum_from') || str_contains($name, 'enum_try_from')) {
                continue;
            }
            // DNF types VM-only until JIT param/property checks (#3094).
            if (str_contains($name, 'dnf_')) {
                continue;
            }
            // highlight_string/highlight_file/show_source VM-only (#3164, #3447).
            if (str_contains($name, 'highlight_string')
                || str_contains($name, 'highlight_file')
                || str_contains($name, 'show_source')) {
                continue;
            }
            // get_meta_tags() VM-only until HTML meta LLVM lowering (#3703).
            if (str_contains($name, 'get_meta_tags')) {
                continue;
            }
            // ob_get_contents/ob_end_clean/ob_get_length VM-only until LLVM ob read API (#3236).
            if (str_contains($name, 'ob_get_contents')) {
                continue;
            }
            // print_r()/var_dump() VM-only until debug export LLVM lowering (#3133).
            if (str_contains($name, 'print_r') || str_contains($name, 'var_dump')) {
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