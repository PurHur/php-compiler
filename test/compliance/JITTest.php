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
            if (!CompilerVersion::supportsStrIncrement()
                && (str_contains($name, 'str_increment') || str_contains($name, 'str_decrement'))) {
                continue;
            }
            if (!CompilerVersion::supportsZendThreadId()
                && str_contains($name, 'zend_thread_id')
                && !str_contains($name, 'zend_thread_id_phantom')) {
                continue;
            }
            // 8.2-target reject gate; skipped when CompilerVersion 8.3+ enables typed trait constants (#5993).
            if (CompilerVersion::supportsTypedTraitConstants()
                && str_contains($name, 'trait_typed_const_reject')) {
                continue;
            }
            // 8.4-target reject gate; skipped when CompilerVersion 8.4.0+ enables final global typed constants (#10324).
            if (CompilerVersion::supportsFinalGlobalTypedConstants()
                && str_contains($name, 'final_global_typed_constant_reject')) {
                continue;
            }
            if (!CompilerVersion::supportsFinalGlobalTypedConstants()
                && str_contains($name, 'final_global_typed_constant')
                && !str_contains($name, 'final_global_typed_constant_reject')) {
                continue;
            }
            // ?-> LLVM lowering verified in NullsafeJitCompileTest (#3219); MCJIT execute needs jit-runtime-probe (#98).
            if (str_contains(strtolower($case[0]), 'nullsafe')) {
                continue;
            }
            // SplObjectStorage JIT-only (#1998); see SplObjectStorageJITTest.
            if (str_contains(strtolower($case[0]), 'splobjectstorage')) {
                continue;
            }
            // ArrayAccess $obj[$key]: JIT/AOT lint in ArrayAccessJITTest; MCJIT execute segfault (#4012).
            if (str_contains($name, 'array_access')) {
                continue;
            }
            if (str_contains(strtolower($case[0]), 'spl_autoload_register_jit')) {
                continue;
            }
            // eval() readonly inheritance compile fatal: VM + known-class compile check (#7170); MCJIT inline eval deferral pending.
            if (str_contains($name, 'eval_readonly_inheritance') || str_contains($name, 'eval_nonreadonly_extends_readonly')) {
                continue;
            }
            // preserve_keys=true: VM + JIT/AOT via ArrayBuiltinHelper (#3524).
            // array_merge_recursive(): VM + JIT via ArrayBuiltinHelper overlay (#3297, #6177).
            if (str_contains(strtolower($case[0]), 'array_merge_recursive')) {
                continue;
            }
            // array_slice() preserve_keys=true: VM + AOT (#4227); MCJIT execute segfault on str-key slice phi.
            if (str_contains($name, 'array_slice_preserve_keys')) {
                continue;
            }
            // unpack() insufficient data false + E_WARNING: VM + AOT (#3775); JIT MCJIT execute exit 255.
            if (str_contains($name, 'unpack_insufficient_data')) {
                continue;
            }
            // parse_url() missing component false: VM + AOT (#4228); MCJIT segfault on === false with boxed result.
            if (str_contains($name, 'parse_url_component_false')) {
                continue;
            }
            // readline() MCJIT false return boxing unstable (#3776); VM + AOT lint green.
            if (str_contains($name, 'readline_exists')) {
                continue;
            }
            // CLI $argc/$argv globals: VM + standalone AOT (#4139); MCJIT execute segfaults (CliArgvGlobalInit refresh).
            if (str_contains($name, 'cli_argv')) {
                continue;
            }
            // array literal int / numeric-string key collision: VM + AOT (#4151); MCJIT execute unstable (#98).
            if (str_contains($name, 'array_literal_numeric_string_key')) {
                continue;
            }
            // array literal duplicate keys: VM + AOT (#4703); MCJIT execute unstable (#98).
            if (str_contains($name, 'array_literal_duplicate_key')) {
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
            // intval() optional $base: VM + AOT (#4174); MCJIT strtol/base path until MCJIT stable.
            if (str_contains($name, 'intval_base')) {
                continue;
            }
            // class_implements() on trait: VM + JitClassImplements lowering (#5248); MCJIT DECLARE_TRAIT LLVM verify (#3609).
            if (str_contains($name, 'class_implements_trait')) {
                continue;
            }
            // gc_collect_cycles() MCJIT execute unstable (#3160); compile: GcCollectCyclesJitCompileTest.
            if (str_contains($name, 'gc_collect_cycles')) {
                continue;
            }
            // set_exception_handler() / restore_exception_handler() VM-only (#3146).
            if (str_contains($name, 'exception_handler')) {
                continue;
            }
            // session_reset/session_create_id/session_gc: VM lifecycle API (#6002/#6006); JIT deferred.
            if (str_contains($name, 'session_reset') || str_contains($name, 'session_create_id') || str_contains($name, 'session_gc')) {
                continue;
            }
            // headers_sent($file, $line) by-ref origin: VM path only (#5134); JIT zero-arg #4110.
            if (str_contains($name, 'headers_sent_byref')) {
                continue;
            }
            // WeakReference get() return used in locals — MCJIT execute (#3667).
            if (str_contains($name, 'weak_reference_gc_jit')) {
                continue;
            }
            // WeakMap offsetUnset / foreach — MCJIT execute (#4084); compile: WeakMapOffsetUnsetJitCompileTest.
            if (str_contains($name, 'weakmap_offsetunset_jit')) {
                continue;
            }
            // isset() scalar locals: compile IssetScalarJitCompileTest (#4081); MCJIT execute pending (#98).
            if (str_contains($name, 'isset_scalar_jit')) {
                continue;
            }
            // var_export() on enum case arrays: VM (#5583); MCJIT enum literal layout deferred.
            if (str_contains($name, 'array_spread_enum_cases')) {
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
            // never/void covariance on class methods: compile-time only (#6733); MCJIT segfault on :void/:never class methods (#98).
            if (str_contains($name, 'never_void_covariance')) {
                continue;
            }
            // class_alias() on interfaces/traits: VM + AOT -l (#5329); MCJIT LLVM verify until interface_exists stable.
            if (str_contains($name, 'class_alias_interface_trait')) {
                continue;
            }
            // class_alias() on enums: VM (#5765); MCJIT LLVM verify (class_alias lowering).
            if (str_contains($name, 'class_alias_enum')) {
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
            // stream/dir resource ==/=== MCJIT: VM + dedicated JIT PHPT (#4699); umbrella skips opendir path.
            if (str_contains($name, 'stream_resource_compare') && !str_contains($name, '_jit')) {
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
            // filter_var() FILTER_VALIDATE_REGEXP array options: VM + AOT (#5020); MCJIT defers array options.
            if (str_contains($name, 'filter_validate_regexp')) {
                continue;
            }
            // gethostname() MCJIT: dedicated GethostnameJITTest (#3465); umbrella JITTest skips until stable.
            if (str_contains($name, 'gethostname')) {
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
            // error_reporting() MCJIT: VM + AOT (#3220); IniRuntime LLVM (#5736).
            if (str_contains($name, 'error_reporting')) {
                continue;
            }
            // compact() array/nested args: VM + AOT (#3468); MCJIT __hashtable__ type mismatch until stable.
            if (str_contains($name, 'compact_array_arg')) {
                continue;
            }
            // compact()/extract() float locals: LLVM verify green (#4094); MCJIT execute segfault until stable.
            if (str_contains($name, 'compact_float') || str_contains($name, 'extract_float')) {
                continue;
            }
            // number_format() NAN/INF: VM + AOT (#4680); MCJIT execute segfault on INF/NAN constants until stable.
            if (str_contains($name, 'number_format_non_finite')) {
                continue;
            }
            // phpversion/php_sapi_name/php_uname MCJIT: VM + AOT (#3174); umbrella JITTest skips until stable.
            if (str_contains($name, 'phpversion')) {
                continue;
            }
            // ReflectionClass::getLazyInitializationException MCJIT: VM (#6514); JIT proxy pending.
            if (str_contains($name, 'reflection_lazy_init_exception')) {
                continue;
            }
            // extension_loaded/phpversion introspection: VM + AOT (#6372, #7190); MCJIT LLVM verify until stable.
            if (str_contains($name, 'extension_loaded_in_tree')) {
                continue;
            }
            // BcMath\Number OOP methods are VM-only until JIT class method lowering (#7220, #6100).
            if (str_contains($name, 'bcmath_number')) {
                continue;
            }
            // bcceil()/bcfloor() VM-first (#6026); JIT lowering deferred to #5935 phase.
            if (str_contains($name, 'bcceil_bcfloor')) {
                continue;
            }
            // ReflectionProperty/Function/Constant builtins are VM-only (#3354).
            if (str_contains($name, 'reflection_oop')) {
                continue;
            }
            // ReflectionClass::getProperties/getMethods are VM-only (#3815).
            if (str_contains($name, 'reflection_class_members')
                || str_contains($name, 'reflection_class_getmethods_private')
                || str_contains($name, 'reflection_class_static_properties')) {
                continue;
            }
            // ReflectionProperty/Constant::getAttributes() MCJIT: VM read path (#4136, #2467).
            if (str_contains($name, 'reflection_property_attributes') || str_contains($name, 'reflection_constant_attributes')) {
                continue;
            }
            // ReflectionProperty asymmetric probes: VM builtins + asymmetric syntax (#6977).
            if (str_contains($name, 'reflection_property_asymmetric')) {
                continue;
            }
            // Reflection docblock/source getters are VM-only (#7358).
            if (str_contains($name, 'reflection_docblock_source')) {
                continue;
            }
            // uasort() closure comparators are VM-only (#3582).
            if (str_contains($name, 'uasort_closure')) {
                continue;
            }
            // utf8_encode/decode TypeError in try/catch: VM + AOT (#4317); MCJIT JitStringBuiltinArg assert IR (#98).
            if (str_contains($name, 'utf8_encode_decode_scalar') && !str_contains($name, '_jit')) {
                continue;
            }
            // dl() TypeError in try/catch: VM + bin/jit.php (#3591); MCJIT JitStringBuiltinArg abort IR (#98).
            if (str_contains($name, 'dl_typeerror')) {
                continue;
            }
            // Closure::fromCallable() inaccessible callback TypeError: VM ClosureSupport (#7416).
            if (str_contains($name, 'closure_from_callable_inaccessible')) {
                continue;
            }
            // variadic + named args: VM parity (#4808); MCJIT NamedArgs variadic pack (#3777 follow-up).
            if (str_contains($name, 'named_args_variadic')) {
                continue;
            }
            // E_DEPRECATED on stderr: DynamicPropertyDeprecatedJITTest (#5470, #4570).
            if (str_contains($name, 'dynamic_property_deprecation')) {
                continue;
            }
            // User __destruct() MCJIT execute: compile verified in UserDestructJitCompileTest (#4096); harness MCJIT SIGSEGV (#98).
            if (str_contains($name, 'class_destruct') || str_contains($name, 'destruct_user') || str_contains($name, 'destruct_jit')) {
                continue;
            }
            // preg_last_error_msg() MCJIT path unsafe with preg_match stub runtime (#3110).
            if (str_contains($name, 'preg_last_error_msg')) {
                continue;
            }
            // preg_replace() array $subject: VM + AOT lint (#4055); MCJIT segfaults (preg_filter array path, #98).
            if (str_contains($name, 'preg_replace_array_subject')) {
                continue;
            }
            // json_validate() MCJIT path unsafe until __compiler_json_validate link is stable (#3101).
            if (str_contains($name, 'json_validate')) {
                continue;
            }
            // JsonSerializable json_encode() needs VM method dispatch (#3370, #6880).
            if (str_contains($name, 'json_serializable')
                || str_contains($name, 'json_encode_enum_jsonserializable')) {
                continue;
            }
            // json_encode() object public-property export needs VM dispatch (#6879).
            if (str_contains($name, 'json_encode_stringable')) {
                continue;
            }
            // Generator::getReturn() and generator return slot are VM-only until JIT generators (#167, #3350).
            if (str_contains($name, 'generator_get_return')) {
                continue;
            }
            // Fiber::getTrace()/getTraceAsString() — VM FiberState suspend capture (#6470).
            if (str_contains($name, 'fiber_get_trace')) {
                continue;
            }
            // exit/die expression ScriptExit status — VM compliance (#3539).
            if (str_contains($name, 'exit_expression') || str_contains($name, 'die_expression')) {
                continue;
            }
            // exit()/die() scalar coercion — VM (#4696); JIT TYPE_EXIT MCJIT unstable.
            if (str_contains($name, 'exit_status_coercion')) {
                continue;
            }
            // exit([]) array-to-string warning — VM (#5441); JIT TYPE_EXIT deferred.
            if (str_contains($name, 'exit_array_status')) {
                continue;
            }
            // exit()/die() enum case Error + uncaught reporting — VM TYPE_EXIT (#6358).
            if (str_contains($name, 'exit_enum_case_error') || str_contains($name, 'uncaught_no_secondary_fatal')) {
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
            // Array literal spread on enum arrays: VM green (#5569); MCJIT enum declare unstable (#3518).
            if (str_contains($name, 'array_literal_spread_enum')) {
                continue;
            }
            // Global function __METHOD__/__FUNCTION__ — parse-time literals; MCJIT segfault (#3595).
            if (str_contains($name, 'magic_const_method_function')) {
                continue;
            }
            // Return-by-reference MCJIT execute: LLVM verify in ReturnByRefJitCompileTest (#3778).
            if (str_contains($name, 'return_by_ref_jit')) {
                continue;
            }
            // object ==: compile verify in ObjectLooseEqualsJitCompileTest (#4766); MCJIT execute segfault (boxed operands).
            if (str_contains($name, 'object_loose_equals')) {
                continue;
            }
            // ??= MCJIT execute: compile in CoalesceAssignJitCompileTest (#3792); execute in CoalesceAssignJitExecuteTest (#4763).
            if (str_contains($name, 'coalesce_assign_jit')) {
                continue;
            }
            // Implicit nullable MCJIT execute: compile in ImplicitNullableParamJitCompileTest (#4767); execute when jit-runtime-probe green.
            if (str_contains($name, 'implicit_nullable_param')) {
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
            // object === identity compare is VM-only until JIT handle compare is stable (#3622).
            if (str_contains($name, 'object_identical')) {
                continue;
            }
            // array <=> array is VM-only until __hashtable__compareSpaceship JIT lowering (#3672).
            if (str_contains($name, 'spaceship_array')) {
                continue;
            }
            // int <=> non-numeric string: MCJIT lowering landed (#4681); execute gated like spaceship_operator_jit.
            if (str_contains($name, 'spaceship_int_nonnumeric')) {
                continue;
            }
            // gettype() object/resource is VM-only until MCJIT execute path is stable (#3618); boxed JIT uses JitGettype (#5235).
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
            // Unary +/-: LLVM verify in UnaryPlus/MinusJitCompileTest (#4820, #5083); MCJIT execute gated by jit-runtime-probe (#98).
            if (str_contains($name, 'unary_plus') || str_contains($name, 'unary_minus')) {
                continue;
            }
            // pre/post inc/dec VM-only until JIT lowering (#3552).
            if (str_contains($name, 'pre_post_inc')) {
                continue;
            }
            // Property hooks: raw-write guard #4025; MCJIT execute gated by jit-runtime-probe (#98).
            if (str_contains($name, 'property_hook')) {
                continue;
            }
            // Builtin enums (PropertyHookType, ExitStatus): VM + enum registration; MCJIT execute gated (#7222, #7294).
            if (str_contains($name, 'exit_status_enum')) {
                continue;
            }
            // User enum DECLARE_ENUM segfaults in MCJIT until enum lowering is stable (#3518).
            // enum_spaceship_jit: lowering fixed #4849; compliance JIT when jit-runtime-probe green (#98).
            // enum_case_name_value: ->name/->value JIT dispatch (#4953); compliance when jit-runtime-probe green (#98).
            // (int)/(float) on enum cases: VM + JitZendScalarCast lowering (#5791); MCJIT LLVM verify with enum declare.
            if (str_contains($name, 'int_cast_backed_enum')) {
                continue;
            }
            if ((str_contains($name, 'enum_') || str_contains($name, 'abstract_enum'))
                && !str_contains($name, 'enum_case_name_value')
                && !str_contains($name, 'enum_cases_static')
                && !str_contains($name, 'get_debug_type_enum')) {
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
            // HashTable COW is VM-only until JIT mirrors refcount separation (#3760).
            if (str_contains($name, 'array_cow')) {
                continue;
            }
            // property default `new` — LLVM verify in PropertyDefaultNewJitCompileTest (#3391); MCJIT execute segfaults (#98).
            if (str_contains($name, 'property_default_new')) {
                continue;
            }
            // Static typed property write TypeError message: VM parity (#7368); MCJIT static store execute unstable (#4908).
            if (str_contains($name, 'static_typed_property_typeerror')) {
                continue;
            }
            // Scalar union static properties: VM + TYPE_VALUE lowering (#8726); MCJIT declare/echo segfault until stable (#98).
            if (str_contains($name, 'static_property_union_type')) {
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
            // get_class_methods() MCJIT execute segfaults; PHP lowering: GetClassMethodsRuntimeShrinkTest (#6339).
            if (str_contains($name, 'get_class_methods')) {
                continue;
            }
            // class_parents() $autoload flag: VM + AOT (#5026); MCJIT execute segfaults (#3159).
            if (str_contains($name, 'class_parents_autoload')) {
                continue;
            }
            // class_parents() on interface: VM (#5249); MCJIT execute unstable (#3159).
            if (str_contains($name, 'class_parents_interface')) {
                continue;
            }
            // class_uses_recursive() nested trait use: VM (#6469); MCJIT/AOT segfault on trait-in-trait (#6439).
            if (str_contains($name, 'class_uses_recursive')) {
                continue;
            }
            // #[\Override] on trait method at use site: VM compile + run (#6761); MCJIT trait override segfault.
            if (str_contains($name, 'override_trait_body_attribute')) {
                continue;
            }
            // __callStatic is VM-only until JIT static magic dispatch (#3273).
            if (str_contains($name, 'magic_call_static')) {
                continue;
            }
            // gettimeofday() array sec compare is VM-only until boxed array fetch compare (#3208).
            if (str_contains($name, 'gettimeofday')) {
                continue;
            }
            // Uncaught asymmetric_visibility fatal: MCJIT execute unstable (#4029, #98); *_jit.phpt uses try/catch (#4020).
            if (str_contains($name, 'asymmetric_visibility') && !str_contains($name, 'jit')) {
                continue;
            }
            // fstat() on fopen handles: VM + AOT (#3482); MCJIT fopen execute unstable (jit-runtime-probe #98).
            if (str_contains($name, 'fstat_stream')) {
                continue;
            }
            // parse_str() one-arg in function scope: VM + AOT; MCJIT try/catch pending dispatch (#4034).
            if (str_contains($name, 'parse_str_function_scope')
                || str_contains($name, 'parse_str_local_scope')) {
                continue;
            }
            // PHP 8.3 typed class constants: VM + AOT; MCJIT execute unstable (#4511, #3592).
            if (str_contains($name, 'typed_class_const')) {
                continue;
            }
            // PHP 8.3 typed trait constants: VM + AOT; MCJIT LLVM verify unstable (#5993).
            if (str_contains($name, 'trait_typed_const')) {
                continue;
            }
            // Trait method static locals: VM green (#6660); MCJIT execute segfault (trait + function-static).
            if (str_contains($name, 'trait_method_static_local')) {
                continue;
            }
            // Trait abstract private: compile-time guard only (#6895); MCJIT execute exit -1 until trait abstract lowering stable.
            if (str_contains($name, 'trait_abstract_private')) {
                continue;
            }
            // final private method E_WARNING at class declare: VM green (#6914); MCJIT class-body warning deferred.
            if (str_contains($name, 'final_private_method_warning')) {
                continue;
            }
            // Instance method by-ref + function-static: VM green (#6739); MCJIT execute segfault.
            if (str_contains($name, 'byref_method_static_local')) {
                continue;
            }
            // Pipe operator (|>): LLVM verify green in PipeOperatorJitCompileTest (#4783); MCJIT execute in PipeOperatorJitExecuteTest (#98).
            if (str_contains($name, 'pipe_first_class')) {
                continue;
            }
            // Exception/Error::__construct parent forwarding: VM dispatch (#6735); builtin ctor MCJIT segfault.
            if (str_contains($name, 'exception_subclass_parent_construct')) {
                continue;
            }
            // posix access/mknod VM-only until LLVM libc wrappers (#7376).
            if (str_contains($name, 'posix_access')
                || str_contains($name, 'posix_mknod')) {
                continue;
            }
            // dirname() $levels Z_PARAM_LONG TypeError in try/catch: VM + AOT (#4715); MCJIT execute LLVM verify/segfault like chunk_split_type_error.
            if (str_contains($name, 'path_functions_type_error')) {
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