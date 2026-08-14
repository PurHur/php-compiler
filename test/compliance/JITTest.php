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
            // FreeType FFI optional (#6532/#20496); skip when libfreetype is not on the host image.
            if ((str_contains($name, 'imagettftext') || str_contains($name, 'gd_imageft_20496'))
                && !\PHPCompiler\ext\gd\VmGdFreeType::available()) {
                continue;
            }
            // Host without php-gd: withhold functional gd_* / image* cases; keep phantom (#22740).
            if (!\PHPCompiler\ext\gd\GdExtensionPolicy::runsGdCompliance($name)
                && \PHPCompiler\ext\gd\GdExtensionPolicy::isGdComplianceCase($name)) {
                continue;
            }
            // Host/profile without soap: withhold functional soap_* cases; keep phantom (#22859).
            if (!\PHPCompiler\ext\soap\SoapExtensionPolicy::runsSoapCompliance($name)
                && \PHPCompiler\ext\soap\SoapExtensionPolicy::isSoapComplianceCase($name)) {
                continue;
            }
            // Host without ext/tidy: withhold functional tidy_* cases; keep phantom (#23955).
            if (!\PHPCompiler\ext\tidy\TidyExtensionPolicy::runsTidyCompliance($name)
                && \PHPCompiler\ext\tidy\TidyExtensionPolicy::isTidyComplianceCase($name)) {
                continue;
            }
            // Host/profile without gmp: withhold functional gmp_* cases; keep phantom (#22860).
            if (!\PHPCompiler\ext\gmp\GmpExtensionPolicy::runsGmpCompliance($name)
                && \PHPCompiler\ext\gmp\GmpExtensionPolicy::isGmpComplianceCase($name)) {
                continue;
            }
            // Host/profile without pecl-uuid: withhold functional uuid_* cases; keep phantom (#23962).
            if (!\PHPCompiler\ext\uuid\UuidExtensionPolicy::runsUuidCompliance($name)
                && \PHPCompiler\ext\uuid\UuidExtensionPolicy::isUuidComplianceCase($name)) {
                continue;
            }
            // Host/profile without sqlite3: withhold functional sqlite3_* cases; keep phantom (#22791).
            if (!\PHPCompiler\ext\sqlite3\Sqlite3ExtensionPolicy::runsSqlite3Compliance($name)
                && \PHPCompiler\ext\sqlite3\Sqlite3ExtensionPolicy::isSqlite3ComplianceCase($name)) {
                continue;
            }
            // extension_loaded / class_exists sqlite3 phantoms — VM-only (JIT module-verify; #22791).
            // extension_loaded / class_exists zip phantoms — VM-only (JIT module-verify; #18137/#25010).
            // Throwable Reflection getMethods — VM-only (JIT ReflectionClass module-verify; #25427).
            if (str_contains($name, 'throwable_reflection_methods')) {
                continue;
            }
            // Dom\HTMLDocument/XMLDocument ReflectionMethod — VM-only (JIT StreamLibcHandle
            // pointerCast abort on any living Dom\* ReflectionMethod; named-arg runtime covered by
            // dom_createfromstring_named / dom_createfromfile_named; #26080 / #27924 / #28740 / #28741).
            if (str_contains($name, 'dom_createfromstring_reflection')
                || str_contains($name, 'dom_createfromfile_reflection')
                || str_contains($name, 'dom_document_instance_reflection')
                || str_contains($name, 'dom_htmlelement_selector_reflection')
                || str_contains($name, 'from_factories_reflection_84')) {
                continue;
            }
            // Dom\Element getAttribute* Reflection returns — same JIT ReflectionMethod abort (#26065).
            if (str_contains($name, 'dom_element_getattr_reflection')) {
                continue;
            }
            if (str_contains($name, 'extension_loaded_zip_phantom')
                || str_contains($name, 'zip/extension_loaded_zip_phantom')) {
                continue;
            }
            if (str_contains($name, 'extension_loaded_sqlite3_phantom')
                || str_contains($name, 'sqlite3_reference_profile')
                || str_contains($name, 'sqlite3_forward_profile_surface')) {
                continue;
            }
            // VM-first (#6212/#6248/#6064): JIT hangs/OOM on create_listen / datagram accept scripts; defer JIT PHPT.
            if (str_contains($name, 'socket_create_listen')
                || str_contains($name, 'socket_datagram')
                || str_contains($name, 'socket_shutdown')
                || str_contains($name, 'socket_addrinfo')
                || str_contains($name, 'socket_sendmsg')
                || str_contains($name, 'socket_cmsg')) {
                continue;
            }
            // Functional str_increment*_forward* / *_profile cases set PROFILE via --ENV--; always include (#24820).
            if (!CompilerVersion::supportsStrIncrement()
                && (str_contains($name, 'str_increment') || str_contains($name, 'str_decrement'))
                && !str_contains($name, 'str_increment_phantom')
                && !str_contains($name, 'str_increment_profile')
                && !str_contains($name, 'forward')) {
                continue;
            }
            if (CompilerVersion::supportsStrIncrement()
                && str_contains($name, 'str_increment_phantom')) {
                continue;
            }
            if (!CompilerVersion::supportsGetObjectId()
                && str_contains($name, 'get_object_id')
                && !str_contains($name, 'get_object_id_phantom')
                && !str_contains($name, 'get_object_id_function_exists_forward')) {
                continue;
            }
            if (CompilerVersion::supportsGetObjectId()
                && str_contains($name, 'get_object_id_phantom')) {
                continue;
            }
            if (CompilerVersion::supportsClamp()
                && str_contains($name, 'clamp_phantom')) {
                continue;
            }
            if (!CompilerVersion::supportsFpow()
                && str_contains($name, 'fpow')
                && !str_contains($name, 'php84_math_string_builtins_phantom')
                && !str_contains($name, 'fpow_function_exists_forward_profile')
                && !str_contains($name, 'fpow_roundingmode_argcount')) {
                continue;
            }
            if (!CompilerVersion::supportsIeeeFloatOpPhantoms()
                && (str_contains($name, 'fmin') || str_contains($name, 'fmax')
                    || str_contains($name, 'fadd') || str_contains($name, 'fsub') || str_contains($name, 'fmul'))
                && !str_contains($name, 'fadd_fsub_fmul_phantom')
                && !str_contains($name, 'fmin_fmax_phantom')
                && !str_contains($name, 'ieee_float_op_phantom')) {
                continue;
            }
            if (!CompilerVersion::supportsNextafter()
                && str_contains($name, 'nextafter')
                && !str_contains($name, 'php84_math_string_builtins_phantom')
                && !str_contains($name, 'nextafter_profile')
                && !str_contains($name, 'nextafter_phantom')) {
                continue;
            }
            if (CompilerVersion::supportsNextafter()
                && (str_contains($name, 'nextafter_profile') || str_contains($name, 'nextafter_phantom'))) {
                continue;
            }
            if (CompilerVersion::supportsNextafter()
                && !CompilerVersion::advertisesNextafter()
                && str_contains($name, 'nextafter')
                && !str_contains($name, 'nextafter_profile')
                && !str_contains($name, 'nextafter_phantom')
                && !str_contains($name, 'php84_math_string_builtins_phantom')
                && !str_contains($name, 'forward_profile_phantom_introspection')) {
                continue;
            }
            if (!CompilerVersion::supportsRoundingModeEnum()
                && (str_contains($name, 'rounding_mode') || str_contains($name, 'bcround'))
                && !str_contains($name, 'rounding_mode_reference_profile')) {
                continue;
            }
            if (CompilerVersion::supportsRoundingModeEnum()
                && str_contains($name, 'rounding_mode_reference_profile')) {
                continue;
            }
            if (!CompilerVersion::supportsRoundingModeEnum()
                && str_contains($name, 'round_invalid_mode_84')) {
                continue;
            }
            if (CompilerVersion::supportsRoundingModeEnum()
                && 'round_invalid_mode.phpt' === $name) {
                continue;
            }
            if (!CompilerVersion::supportsNumberFormatNegativeDecimals()
                && str_contains($name, 'number_format_negative_decimals')
                && !str_contains($name, 'number_format_negative_decimals_84')) {
                continue;
            }
            if (CompilerVersion::supportsNumberFormatNegativeDecimals()
                && 'number_format_negative_decimals.phpt' === $name) {
                continue;
            }
            if (!CompilerVersion::supportsRandomIntervalBoundary()
                && str_contains($name, 'random_interval_boundary')
                && !str_contains($name, 'random_interval_boundary_reference_profile')) {
                continue;
            }
            if (CompilerVersion::supportsRandomIntervalBoundary()
                && str_contains($name, 'random_interval_boundary_reference_profile')) {
                continue;
            }
            if (!CompilerVersion::supportsGetDeclaredExcludeDeprecated()
                && (str_contains($name, 'get_declared_exclude_deprecated')
                    || str_contains($name, 'classes_exclude_deprecated'))
                && !str_contains($name, 'get_declared_exclude_deprecated_reference_profile')) {
                continue;
            }
            if (CompilerVersion::supportsGetDeclaredExcludeDeprecated()
                && str_contains($name, 'get_declared_exclude_deprecated_reference_profile')) {
                continue;
            }
            // get_class $allow_string gate retired (#28310) — both allow_string*.phpt cases always run.
            if (!CompilerVersion::supportsGetDefinedFunctionsExcludeDisabled()
                && str_contains($name, 'get_defined_functions_exclude_disabled')
                && !str_contains($name, 'get_defined_functions_exclude_disabled_reference_profile')) {
                continue;
            }
            if (CompilerVersion::supportsGetDefinedFunctionsExcludeDisabled()
                && str_contains($name, 'get_defined_functions_exclude_disabled_reference_profile')) {
                continue;
            }
            if ((CompilerVersion::supportsStrIncrement() || CompilerVersion::supportsFpow() || CompilerVersion::supportsNextafter())
                && str_contains($name, 'php84_math_string_builtins_phantom')) {
                continue;
            }
            if (!CompilerVersion::supportsZendThreadId()
                && str_contains($name, 'zend_thread_id')
                && !str_contains($name, 'zend_thread_id_phantom')
                && !str_contains($name, 'zend_thread_id_function_exists_forward_profile')) {
                continue;
            }
            if (CompilerVersion::supportsZendThreadId()
                && str_contains($name, 'zend_thread_id_phantom')) {
                continue;
            }
            if (!CompilerVersion::supportsClassUsesRecursive()
                && str_contains($name, 'class_uses_recursive')
                && !str_contains($name, 'class_uses_recursive_phantom')) {
                continue;
            }
            if (CompilerVersion::supportsClassUsesRecursive()
                && str_contains($name, 'class_uses_recursive_phantom')) {
                continue;
            }
            if (!CompilerVersion::supportsPhp84ReflectionProbeBuiltins()
                && (str_contains($name, 'attribute_exists')
                    || str_contains($name, 'class_meth_exists')
                    || str_contains($name, 'unitenum_exists'))
                && !str_contains($name, 'reflection_probe_builtins_phantom')) {
                continue;
            }
            if (!CompilerVersion::supportsIsAnonymousClass()
                && str_contains($name, 'is_anonymous_class')
                && !str_contains($name, 'is_anonymous_class_phantom')) {
                continue;
            }
            if (!CompilerVersion::supportsGetmygrgid()
                && str_contains($name, 'getmygrgid')
                && !str_contains($name, 'getmygrgid_phantom')) {
                continue;
            }
            if (!CompilerVersion::supportsCrc32c()
                && str_contains($name, 'crc32c')
                && !str_contains($name, 'crc32c_phantom')) {
                continue;
            }
            // hebrevc removed in php-src 8.0 (#20354): functional cases use PROFILE=7.4 via --ENV--;
            // phantom_* cases assert absence on 8.2/8.4 — always include (do not gate on supportsHebrevc()).
            // Functional mb_str_pad_*_forward* / empty_pad / named_args cases set PROFILE via --ENV--; always include (#22373, #31174).
            if (!CompilerVersion::supportsMbStrPad()
                && str_contains($name, 'mb_str_pad')
                && !str_contains($name, 'mb_str_pad_phantom')
                && !str_contains($name, 'forward')
                && !str_contains($name, 'empty_pad')
                && !str_contains($name, 'named_args_mb_str_pad')) {
                continue;
            }
            if (CompilerVersion::supportsMbStrPad()
                && str_contains($name, 'mb_str_pad_phantom')) {
                continue;
            }
            if (!CompilerVersion::supportsMbUcfirstLcfirst()
                && (str_contains($name, 'mb_ucfirst') || str_contains($name, 'mb_lcfirst'))
                && !str_contains($name, 'mb_ucfirst_lcfirst_phantom')
                && !str_contains($name, 'forward')) {
                continue;
            }
            if (CompilerVersion::supportsMbUcfirstLcfirst()
                && str_contains($name, 'mb_ucfirst_lcfirst_phantom')) {
                continue;
            }
            // Sorting / SortDirection phantoms retired (#28930) — absence cases always run.
            if (!CompilerVersion::supportsRange()
                && str_contains($name, 'range_from_84')
                && !str_contains($name, 'range_phantom')) {
                continue;
            }
            if (CompilerVersion::supportsRange()
                && str_contains($name, 'range_phantom')) {
                continue;
            }
            if (!CompilerVersion::supportsBuiltinStubEnums()
                && (str_contains($name, 'pad_type_enum')
                    || str_contains($name, 'session_status_enum')
                    || str_contains($name, 'phpinfo_infoview')
                    || str_contains($name, 'filter_input_phpinputfilter')
                    || str_contains($name, 'connection_status_cli')
                    || str_contains($name, 'property_hook_type_enum')
                    || str_contains($name, 'socket_type_enum')
                    || str_contains($name, 'ftp_ssl_connect')
                    || str_contains($name, 'ftp_connect')
                    || str_contains($name, 'ftp_fget')
                    || str_contains($name, 'ftp_connection_class'))
                && !str_contains($name, 'builtin_stub_enums_phantom')
                && !str_contains($name, 'exit_status_enum')
                // HTTP phantoms retired (#28931) — absence cases always run.
                && !str_contains($name, 'http_response_code_enum')
                && !str_contains($name, 'connection_status_enum')
                && !str_contains($name, 'requestmethod_enum')
                // ParseUrl phantom retired (#28536) — absence cases always run.
                && !str_contains($name, 'parse_url_enum')
                // StringTrimMode phantom retired (#28202) — absence cases always run.
                && !str_contains($name, 'string_trim_mode')
                // MemoryUsage phantom retired (#28411) — absence cases always run.
                && !str_contains($name, 'memory_usage_enum')
                && !str_contains($name, 'memory_get_usage_enum')) {
                continue;
            }
            if (CompilerVersion::supportsBuiltinStubEnums()
                && str_contains($name, 'builtin_stub_enums_phantom')) {
                continue;
            }
            if (!CompilerVersion::supportsPhp84ArraySearchFunctions()
                && (str_contains($name, 'array_find')
                    || str_contains($name, 'array_any')
                    || str_contains($name, 'array_all'))
                && !str_contains($name, 'php84_array_search_phantom')
                && !str_contains($name, 'array_any_key_forward_84')
                && !str_contains($name, 'array_any_all_key_phantom')
                && !str_contains($name, 'array_find_byref_callback_warning_forward_84')) {
                continue;
            }
            if (CompilerVersion::supportsPhp84ArraySearchFunctions()
                && str_contains($name, 'php84_array_search_phantom')) {
                continue;
            }
            // PHP 8.4 pcntl_* — *_forward84 cases set PROFILE via --ENV--; phantom on default (#26742).
            if (!CompilerVersion::supportsPhp84PcntlApis()
                && (str_contains($name, 'pcntl_setns')
                    || str_contains($name, 'pcntl_cpuaffinity')
                    || str_contains($name, 'pcntl_getcpu')
                    || str_contains($name, 'pcntl_waitid')
                    || str_contains($name, 'pcntl_84_apis_exists'))
                && !str_contains($name, 'pcntl_84_apis_phantom')
                && !str_contains($name, 'forward84')) {
                continue;
            }
            if (CompilerVersion::supportsPhp84PcntlApis()
                && str_contains($name, 'pcntl_84_apis_phantom')) {
                continue;
            }
            if (!CompilerVersion::supportsPhp85ArrayFirstLast()
                && ((str_contains($name, 'array_first') && !str_contains($name, 'array_first_key') && !str_contains($name, 'array_first_last_key'))
                    || (str_contains($name, 'array_last') && !str_contains($name, 'array_last_key') && !str_contains($name, 'array_first_last_key')))
                && !str_contains($name, 'array_first_last_phantom_forward_84')
                && !str_contains($name, 'array_first_last_forward_85')
                && !str_contains($name, 'php84_array_search_phantom')) {
                continue;
            }
            if (CompilerVersion::supportsPhp85ArrayFirstLast()
                && str_contains($name, 'array_first_last_phantom_forward_84')) {
                continue;
            }
            if (!CompilerVersion::supportsGeneratorToArray()
                && str_contains($name, 'generator_to_array')
                && !str_contains($name, 'php84_generator_to_array_phantom')
                && !str_contains($name, 'generator_to_array_forward_84')) {
                continue;
            }
            if (!CompilerVersion::supportsDateTimeMicrosecond()
                && str_contains($name, 'datetime_microsecond')
                && !str_contains($name, 'datetime_microsecond_phantom')
                && !str_contains($name, 'datetime_microsecond_forward_84')) {
                continue;
            }
            if (CompilerVersion::supportsDateTimeMicrosecond()
                && str_contains($name, 'datetime_microsecond_phantom')) {
                continue;
            }
            if (str_contains($name, 'datetime_create_from_timestamp')) {
                continue;
            }
            if (CompilerVersion::supportsDateTimeCreateFromTimestamp()
                && str_contains($name, 'create_from_timestamp_profile_phantom')) {
                continue;
            }
            if (!CompilerVersion::supportsArrayReplaceKey()
                && str_contains($name, 'array_replace_key')
                && !str_contains($name, 'array_replace_key_phantom')) {
                continue;
            }
            if (CompilerVersion::supportsArrayReplaceKey()
                && str_contains($name, 'array_replace_key_phantom')) {
                continue;
            }
            if (!CompilerVersion::supportsClosureGetCurrent()
                && str_contains($name, 'closure_get_current')
                && !str_contains($name, 'closure_get_current_phantom')
                && !str_contains($name, 'closure_get_current_profile')
                && !str_contains($name, 'closure_get_current_forward_85')
                && !str_contains($name, 'closure_get_current_nested_85')
                && !str_contains($name, 'closure_get_current_method_exists_85')) {
                continue;
            }
            if (CompilerVersion::supportsClosureGetCurrent()
                && (str_contains($name, 'closure_get_current_phantom')
                    || str_contains($name, 'closure_get_current_profile'))) {
                continue;
            }
            if (!CompilerVersion::supportsClosureFromStatic()
                && str_contains($name, 'closure_from_static')
                && !str_contains($name, 'closure_from_static_profile')) {
                continue;
            }
            if (CompilerVersion::supportsClosureFromStatic()
                && str_contains($name, 'closure_from_static_profile')) {
                continue;
            }
            if (!CompilerVersion::supportsClosureGetUsedVariables()
                && str_contains($name, 'closure_get_used_variables')
                && !str_contains($name, 'closure_get_used_variables_phantom')) {
                continue;
            }
            if (CompilerVersion::supportsClosureGetUsedVariables()
                && str_contains($name, 'closure_get_used_variables_phantom')) {
                continue;
            }
            // Empty Closure dump on reference profile; forward_84 uses --ENV-- (#22565).
            if (CompilerVersion::supportsClosureRichDebugInfo()
                && str_contains($name, 'closure_debug_info')
                && !str_contains($name, 'closure_debug_info_forward_84')
                && !str_contains($name, 'closure_debuginfo_phantom')) {
                continue;
            }
            // Parameter-only dump on reference profile; forward_84 adds name/file/line (#24521).
            if (CompilerVersion::supportsClosureRichDebugInfo()
                && str_contains($name, 'closure_var_dump_parameter')
                && !str_contains($name, 'closure_var_dump_parameter_forward_84')) {
                continue;
            }
            // HashContext::__debugInfo withheld on reference; forward_84 / profile82 use --ENV-- (#22563).
            if (CompilerVersion::supportsHashContextDebugInfo()
                && str_contains($name, 'hash_context_debug_info')
                && !str_contains($name, 'hash_context_debug_info_forward_84')
                && !str_contains($name, 'hash_context_debug_info_profile82')) {
                continue;
            }
            if (!CompilerVersion::supportsReflectionParameterIsSensitiveParameter()
                && str_contains($name, 'reflection_parameter_is_sensitive_parameter')
                && !str_contains($name, 'reflection_parameter_is_sensitive_parameter_phantom')) {
                continue;
            }
            if (CompilerVersion::supportsReflectionParameterIsSensitiveParameter()
                && str_contains($name, 'reflection_parameter_is_sensitive_parameter_phantom')) {
                continue;
            }
            // isSensitive (#22899 / #7072) — same 8.4 gate; exclude *_parameter* names.
            if (!CompilerVersion::supportsReflectionParameterIsSensitiveParameter()
                && str_contains($name, 'reflection_parameter_is_sensitive')
                && !str_contains($name, 'reflection_parameter_is_sensitive_parameter')
                && !str_contains($name, 'reflection_parameter_is_sensitive_phantom')) {
                continue;
            }
            if (CompilerVersion::supportsReflectionParameterIsSensitiveParameter()
                && str_contains($name, 'reflection_parameter_is_sensitive_phantom')
                && !str_contains($name, 'reflection_parameter_is_sensitive_parameter_phantom')) {
                continue;
            }
            // JIT: method_exists(Closure::class, …) segfaults; phantom/profile introspection is VM-only (#14504, #16989, #22583).
            if (str_contains($name, 'closure_get_current_phantom')
                || str_contains($name, 'closure_get_current_profile')
                || str_contains($name, 'closure_get_current_method_exists')
                || str_contains($name, 'closure_fwd_apis_phantom')
                || str_contains($name, 'closure_from_static_profile')
                || str_contains($name, 'closure_debuginfo_phantom')
                || str_contains($name, 'closure_debug_info')) {
                continue;
            }
            // JIT: method_exists(ReflectionParameter::class, …) segfaults; phantom is VM-only (#16130, #22899).
            if (str_contains($name, 'reflection_parameter_is_sensitive_parameter_phantom')
                || (str_contains($name, 'reflection_parameter_is_sensitive_phantom')
                    && !str_contains($name, 'reflection_parameter_is_sensitive_parameter_phantom'))) {
                continue;
            }
            // JIT: method_exists(ReflectionClass/Property::class, …) fails (pcreJit); profile phantoms are VM-only (#22599, #22601, #25503).
            if (str_contains($name, 'reflectionclass_84_phantoms_profile82')
                || str_contains($name, 'reflectionclass_84_apis_forward_profile')
                || str_contains($name, 'reflectionclass_lazy_apis_phantom_profile82')
                || str_contains($name, 'reflectionclass_lazy_apis_forward_84')
                || str_contains($name, 'reflectionproperty_phantoms_profile82')
                || str_contains($name, 'reflectionproperty_raw_value_forward_profile')) {
                continue;
            }
            // JIT: method_exists(PDO::class, …) fails (pcreJit); profile gate is VM-only (#22600).
            if (str_contains($name, 'pdo_connect_profile82')) {
                continue;
            }
            // Functional mb_trim_*_forward* cases set PROFILE via --ENV--; always include (#24176).
            if (!CompilerVersion::supportsMbTrimFunctions()
                && str_contains($name, 'mb_trim')
                && !str_contains($name, 'mb_trim_phantom')
                && !str_contains($name, 'forward')) {
                continue;
            }
            // Foldable mb_trim + try/catch ValueError still breaks MCJIT IR parentFunction (#23883);
            // VM path is the php-src-strict gate; happy-path AOT fold remains covered by mb_trim.phpt.
            if (str_contains($name, 'mb_trim_invalid_encoding_valueerror')) {
                continue;
            }
            if (!CompilerVersion::supportsMbUcwords()
                && str_contains($name, 'mb_ucwords')
                && !str_contains($name, 'mb_ucwords_phantom')
                && !str_contains($name, 'mb_ucwords_forward')) {
                continue;
            }
            if (CompilerVersion::supportsMbUcwords()
                && str_contains($name, 'mb_ucwords_phantom')) {
                continue;
            }
            if (!CompilerVersion::supportsGraphemeLevenshtein()
                && str_contains($name, 'grapheme_levenshtein')
                && !str_contains($name, 'grapheme_levenshtein_phantom')
                && !str_contains($name, 'grapheme_levenshtein_forward')
                && !str_contains($name, 'grapheme_levenshtein_profile_85')) {
                continue;
            }
            if (CompilerVersion::supportsGraphemeLevenshtein()
                && str_contains($name, 'grapheme_levenshtein_phantom')) {
                continue;
            }
            if (!CompilerVersion::supportsStreamSupports()
                && (('stdlib/stream_supports' === $name)
                    || str_contains($name, 'stream_support_constants')
                    || str_contains($name, 'stream_supports_string_feature')
                    || str_contains($name, 'stream_meta_seekable'))
                && !str_contains($name, 'stream_supports_phantom')) {
                continue;
            }
            if (CompilerVersion::supportsStreamSupports()
                && str_contains($name, 'stream_supports_phantom')) {
                continue;
            }
            // STREAM_SUPPORT_READ/WRITE PHP 8.4 constants; forward profile only (#16846).
            if (!CompilerVersion::supportsStreamSupportReadWriteConstants()
                && str_contains($name, 'stream_support_read_write_constants')) {
                continue;
            }
            if (!CompilerVersion::supportsClockGettime()
                && (str_contains($name, 'clock_gettime')
                    || str_contains($name, 'hrtime_nsec_precision'))
                && !str_contains($name, 'clock_gettime_phantom')) {
                continue;
            }
            if (CompilerVersion::supportsClockGettime()
                && str_contains($name, 'clock_gettime_phantom')) {
                continue;
            }
            if (!CompilerVersion::supportsReadonlyBuiltin()
                && (preg_match('#(?:^|/)readonly_function$#', $name)
                    || str_contains($name, 'readonly_function_jit'))
                && !str_contains($name, 'readonly_phantom')) {
                continue;
            }
            if (CompilerVersion::supportsReadonlyBuiltin()
                && str_contains($name, 'readonly_phantom')) {
                continue;
            }
            if (!CompilerVersion::supportsReadonlyFunction()
                && (str_contains($name, 'readonly_function/')
                    || str_contains($name, 'readonly_function_84'))) {
                continue;
            }
            if (CompilerVersion::supportsReadonlyFunction()
                && (str_contains($name, 'readonly_function_decl')
                    || str_contains($name, 'readonly_function_reject')
                    || str_contains($name, 'readonly_function_mutable_capture'))) {
                continue;
            }
            // *_forward84 cases set PROFILE via --ENV--; always include (#25453).
            if (!CompilerVersion::supportsStreamContextSetOptions()
                && str_contains($name, 'stream_context_set_options')
                && !str_contains($name, 'stream_context_set_options_phantom')
                && !str_contains($name, '_forward84')) {
                continue;
            }
            if (CompilerVersion::supportsStreamContextSetOptions()
                && str_contains($name, 'stream_context_set_options_phantom')) {
                continue;
            }
            if (!CompilerVersion::supportsClassConstants()
                && str_contains($name, 'class_constants')
                && !str_contains($name, 'class_constants_phantom')) {
                continue;
            }
            if (CompilerVersion::supportsClassConstants()
                && str_contains($name, 'class_constants_phantom')) {
                continue;
            }
            $usesHeaderList = str_contains($name, 'header_list')
                || str_contains($name, 'header_remove')
                || str_contains($name, 'setcookie')
                || str_contains($name, 'setrawcookie')
                || str_contains($name, 'session_cookie');
            if (!CompilerVersion::supportsHeaderList()
                && $usesHeaderList
                && !str_contains($name, 'header_list_phantom')) {
                continue;
            }
            if (CompilerVersion::supportsHeaderList()
                && str_contains($name, 'header_list_phantom')) {
                continue;
            }
            $usesHttpLastResponseHeaders = str_contains($name, 'http_get_last_response_headers')
                || str_contains($name, 'get_last_response_headers')
                || str_contains($name, 'http_clear_last_response_headers');
            if (!CompilerVersion::supportsHttpLastResponseHeaders()
                && $usesHttpLastResponseHeaders
                && !str_contains($name, 'http_last_response_headers_phantom')) {
                continue;
            }
            if (CompilerVersion::supportsHttpLastResponseHeaders()
                && str_contains($name, 'http_last_response_headers_phantom')) {
                continue;
            }
            if (!CompilerVersion::advertisesOverrideAttributeClass()
                && str_contains($name, 'override_class_exists')) {
                continue;
            }
            if (CompilerVersion::advertisesOverrideAttributeClass()
                && str_contains($name, 'override_class_phantom')) {
                continue;
            }
            if (!CompilerVersion::supportsLazyPropertyModifier()
                && (str_contains($name, 'lazy_property_modifier')
                    || str_contains($name, 'reflection_lazy_modifier_property_names')
                    || str_contains($name, 'lazy_property_init_once'))) {
                continue;
            }
            // lazy_object_factories_phantom + class_has_lazy_object_* phantom PHPTs always run (#28414, #28517).
            if (!CompilerVersion::supportsLazyObjectFactories()
                && (str_contains($name, 'create_lazy_ghost')
                    || str_contains($name, 'create_lazy_proxy')
                    || str_contains($name, 'lazy_ghost_create')
                    || str_contains($name, 'lazy_ghost_trait')
                    || str_contains($name, 'is_uninitialized_lazy_object')
                    || str_contains($name, 'lazy_object_introspection')
                    || str_contains($name, 'reflection_lazy_property')
                    || str_contains($name, 'reflection_property_set_raw_without_lazy')
                    || str_contains($name, 'reflection_property_skip_lazy'))
                && !str_contains($name, 'lazy_object_factories_phantom')) {
                continue;
            }
            // 8.2 reference profile: #[\Override] parent validation off (#11559, #12201).
            // override_property_invalid uses SKIPIF + --ENV-- PROFILE=8.5 (#25138) — do not
            // drop it here or --ENV-- never runs.
            if (!CompilerVersion::supportsOverrideAttribute()
                && (str_contains($name, 'override_attribute_invalid')
                    || str_contains($name, 'override_attribute_fatal')
                    || str_contains($name, 'override_attribute_invalid_target')
                    || str_contains($name, 'override_signature_mismatch')
                    || str_contains($name, 'override_private_parent')
                    || str_contains($name, 'override_class_constant_invalid'))) {
                continue;
            }
            if (CompilerVersion::supportsOverrideAttribute()
                && (str_contains($name, 'override_attribute_82_no_validate')
                    || str_contains($name, 'override_missing_parent_reference_profile'))) {
                continue;
            }
            if (!CompilerVersion::advertisesDeprecatedAttributeClass()
                && str_contains($name, 'deprecated_attribute_class')
                && !str_contains($name, 'deprecated_attribute_class_forward_84')) {
                continue;
            }
            if (!CompilerVersion::supportsDeprecatedAttributeRuntimeNotices()
                && 'deprecated_attribute.phpt' === $name) {
                continue;
            }
            if (CompilerVersion::supportsDeprecatedAttributeRuntimeNotices()
                && 'deprecated_attribute_profile.phpt' === $name) {
                continue;
            }
            if (!CompilerVersion::advertisesNoDiscardAttributeClass()
                && str_contains($name, 'nodiscard_class_exists')) {
                continue;
            }
            if (!CompilerVersion::advertisesEnumCasesAttributeClass()
                && str_contains($name, 'enumcases_class_exists')) {
                continue;
            }
            if (CompilerVersion::advertisesNoDiscardAttributeClass()
                && str_contains($name, 'nodiscard_class_phantom')) {
                continue;
            }
            if (CompilerVersion::advertisesEnumCasesAttributeClass()
                && str_contains($name, 'enumcases_class_phantom')) {
                continue;
            }
            if (!CompilerVersion::advertisesDateExceptionHierarchy()
                && (str_contains($name, 'dateexception')
                    || str_contains($name, 'dateerror')
                    || str_contains($name, 'date_malformed')
                    || str_contains($name, 'datetimezone_invalid'))
                && !str_contains($name, 'reference_profile')) {
                continue;
            }
            if (CompilerVersion::advertisesDateExceptionHierarchy()
                && str_contains($name, 'dateexception_reference_profile')) {
                continue;
            }
            if (CompilerVersion::advertisesDateExceptionHierarchy()
                && str_contains($name, 'date_interval_malformed_exception_class_reference_profile')) {
                continue;
            }
            if (CompilerVersion::advertisesDateExceptionHierarchy()
                && str_contains($name, 'datetime_bad_spec_reference_profile')) {
                continue;
            }
            if (CompilerVersion::advertisesDateExceptionHierarchy()
                && str_contains($name, 'date_malformed_exceptions_phantom_reference_profile')) {
                continue;
            }
            if (!CompilerVersion::advertisesRequestParseBodyExceptionClass()
                && str_contains($name, 'request_parse_body_exception')
                && !str_contains($name, 'reference_profile')) {
                continue;
            }
            if (CompilerVersion::advertisesRequestParseBodyExceptionClass()
                && str_contains($name, 'request_parse_body_exception_reference_profile')) {
                continue;
            }
            // fiber_stack_overflow.phpt sets PROFILE=8.4 via --ENV--; always include (#26741).
            if (CompilerVersion::advertisesFiberStackOverflowClass()
                && str_contains($name, 'fiber_stack_overflow_reference_profile')) {
                continue;
            }
            if ((!CompilerVersion::advertisesDelayedTargetValidationAttributeClass()
                    || !CompilerVersion::advertisesCompileTimeAttributeClass()
                    || !CompilerVersion::advertisesNoDiscardAttributeClass())
                && str_contains($name, 'builtin_attribute_classes_84')) {
                continue;
            }
            if (\PHPCompiler\ext\intl\IntlExtensionPolicy::advertisesBuiltins()
                && (str_contains($name, 'grapheme_phantom')
                    || str_contains($name, 'grapheme_stripos_intl_gated')
                    || str_contains($name, 'grapheme_forward_profile')
                    || str_contains($name, 'grapheme_profile_84')
                    || str_contains($name, 'idn_phantom')
                    || str_contains($name, 'normalizer_phantom')
                    || str_contains($name, 'intl_phantom')
                    || str_contains($name, 'intl_skeleton_stub')
                    || str_contains($name, 'locale_gated'))) {
                continue;
            }
            // Host without php-intl: withhold ICU unicode core advertisement case (#22691).
            if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::advertisesBuiltins()
                && str_contains($name, 'intl_unicode_core_icu')) {
                continue;
            }
            if (!CompilerVersion::supportsGraphemeStrimwidth()
                && str_contains($name, 'grapheme_strimwidth')
                && !str_contains($name, 'grapheme_phantom')) {
                continue;
            }
            if (!CompilerVersion::supportsGraphemeStrSplit()
                && str_contains($name, 'grapheme_str_split')
                && !str_contains($name, 'grapheme_str_split_profile_82')
                && !str_contains($name, 'grapheme_str_split_function_exists_forward_84')
                && !str_contains($name, 'grapheme_phantom')) {
                continue;
            }
            if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsGraphemeCompliance($name)
                && str_contains($name, 'grapheme_')
                && !str_contains($name, 'grapheme_phantom')) {
                continue;
            }
            if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIdnCompliance($name)
                && (str_contains($name, 'idn_to_ascii') || str_contains($name, 'idn_to_utf8') || str_contains($name, 'idn_enum'))
                && !str_contains($name, 'idn_phantom')) {
                continue;
            }
            if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsNormalizerCompliance($name)
                && str_contains($name, 'normalizer_')
                && !str_contains($name, 'normalizer_phantom')) {
                continue;
            }
            if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsLocaleCompliance($name)
                && (str_contains($name, 'locale_get_default')
                    || str_contains($name, 'locale_class')
                    || str_contains($name, 'locale_set_default')
                    || str_contains($name, 'locale_display')
                    || str_contains($name, 'locale_get_display'))
                && !str_contains($name, 'locale_gated')
                && !str_contains($name, 'intl_phantom')) {
                continue;
            }
            if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsLocaleParserCompliance($name)
                && str_contains($name, 'locale_get_parts')
                && !str_contains($name, 'locale_gated')
                && !str_contains($name, 'intl_phantom')) {
                continue;
            }
            if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::runsIntlOopCompliance($name)
                && (str_contains($name, 'intldateformatter')
                    || str_contains($name, 'numberformatter')
                    || str_contains($name, 'intlcalendar')
                    || str_contains($name, 'msgfmt_format')
                    || str_contains($name, 'intl_list_formatter')
                    || str_contains($name, 'transliterator')
                    || str_contains($name, 'resourcebundle')
                    || str_contains($name, 'intl_skeleton')
                    || str_contains($name, 'intl_char')
                    || str_contains($name, 'intl_uconverter')
                    || str_contains($name, 'collator_')
                    || str_contains($name, 'breakiterator'))
                && !str_contains($name, 'intl_phantom')) {
                continue;
            }
            // locale_get_parts/locale_gated: JIT lowering deferred (#5125, #19670). locale_get_default via LocaleParser (#27369).
            if (str_contains($name, 'locale_get_parts')
                || str_contains($name, 'locale_gated')) {
                continue;
            }
            // Functional curl cases set PHP_COMPILER_ENABLE_CURL via --ENV--; phantoms when withheld (#23953).
            if (!\PHPCompiler\ext\curl\CurlExtensionPolicy::runsCurlCompliance($name)
                && \PHPCompiler\ext\curl\CurlExtensionPolicy::isCurlComplianceCase($name)) {
                continue;
            }
            if (!\PHPCompiler\ext\curl\CurlExtensionPolicy::runsCurlFileCompliance($name)
                && (str_contains($name, 'curl_file_create')
                    || str_contains($name, 'curl_string_file')
                    || str_contains($name, 'curl_file_phantom'))) {
                continue;
            }
            if (!\PHPCompiler\ext\curl\CurlExtensionPolicy::runsCurlShareCompliance($name)
                && str_contains($name, 'curl_share')) {
                continue;
            }
            if (!\PHPCompiler\ext\curl\CurlExtensionPolicy::runsCurlEasyCompliance($name)
                && (str_contains($name, 'curl_setopt_array')
                    || str_contains($name, 'curl_opt_constants')
                    || str_contains($name, 'curl_easy_phantom'))) {
                continue;
            }
            if (!\PHPCompiler\ext\curl\CurlExtensionPolicy::runsCurlMultiCompliance($name)
                && str_contains($name, 'curl_multi')
                && !str_contains($name, 'curl_multi_strerror')) {
                continue;
            }
            // Reference profile withholds ldap; functional cases set PROFILE via --ENV-- (#23857).
            if (!\PHPCompiler\ext\ldap\LdapExtensionPolicy::runsLdapCompliance($name)
                && \PHPCompiler\ext\ldap\LdapExtensionPolicy::isLdapComplianceCase($name)) {
                continue;
            }
            if (\PHPCompiler\ext\ldap\LdapExtensionPolicy::advertisesWalletConnect()
                && str_contains($name, 'ldap_connect_wallet_withhold')) {
                continue;
            }
            if (!\PHPCompiler\ext\ldap\LdapExtensionPolicy::advertisesWalletConnect()
                && str_contains($name, 'ldap_connect_wallet')
                && str_contains($name, 'oracle')) {
                continue;
            }
            // Functional pgsql cases set PHP_COMPILER_ENABLE_PGSQL via --ENV--; module phantoms when withheld (#24994).
            if (!\PHPCompiler\ext\pgsql\PgsqlExtensionPolicy::runsPgsqlCompliance($name)
                && \PHPCompiler\ext\pgsql\PgsqlExtensionPolicy::isPgsqlComplianceCase($name)) {
                continue;
            }
            // Functional bz2 cases set PHP_COMPILER_ENABLE_BZ2 via --ENV--; phantoms when withheld (#25011).
            if (!\PHPCompiler\ext\bz2\Bz2ExtensionPolicy::runsBz2Compliance($name)
                && \PHPCompiler\ext\bz2\Bz2ExtensionPolicy::isBz2ComplianceCase($name)) {
                continue;
            }
            // Functional pspell cases set PHP_COMPILER_ENABLE_PSPELL via --ENV--; phantoms when withheld (#23968).
            if (!\PHPCompiler\ext\pspell\PspellExtensionPolicy::runsPspellCompliance($name)
                && \PHPCompiler\ext\pspell\PspellExtensionPolicy::isPspellComplianceCase($name)) {
                continue;
            }
            // Functional enchant cases set PHP_COMPILER_ENABLE_ENCHANT via --ENV--; phantoms when withheld (#23963).
            if (!\PHPCompiler\ext\enchant\EnchantExtensionPolicy::runsEnchantCompliance($name)
                && \PHPCompiler\ext\enchant\EnchantExtensionPolicy::isEnchantComplianceCase($name)) {
                continue;
            }
            // Functional zmq cases set PHP_COMPILER_ENABLE_ZMQ via --ENV--; phantoms when withheld (#23964).
            if (!\PHPCompiler\ext\zmq\ZmqExtensionPolicy::runsZmqCompliance($name)
                && \PHPCompiler\ext\zmq\ZmqExtensionPolicy::isZmqComplianceCase($name)) {
                continue;
            }
            // Functional zstd cases set PHP_COMPILER_ENABLE_ZSTD via --ENV--; phantoms when withheld (#25287).
            if (!\PHPCompiler\ext\zstd\ZstdExtensionPolicy::runsZstdCompliance($name)
                && \PHPCompiler\ext\zstd\ZstdExtensionPolicy::isZstdComplianceCase($name)) {
                continue;
            }
            // Functional lzf cases set PHP_COMPILER_ENABLE_LZF via --ENV--; phantoms when withheld (#25287).
            if (!\PHPCompiler\ext\lzf\LzfExtensionPolicy::runsLzfCompliance($name)
                && \PHPCompiler\ext\lzf\LzfExtensionPolicy::isLzfComplianceCase($name)) {
                continue;
            }
            // Functional lz4 cases set PHP_COMPILER_ENABLE_LZ4 via --ENV--; phantoms when withheld (#25087).
            if (!\PHPCompiler\ext\lz4\Lz4ExtensionPolicy::runsLz4Compliance($name)
                && \PHPCompiler\ext\lz4\Lz4ExtensionPolicy::isLz4ComplianceCase($name)) {
                continue;
            }
            // Functional ds cases set PHP_COMPILER_ENABLE_DS via --ENV--; phantoms when withheld (#25086).
            if (!\PHPCompiler\ext\ds\DsExtensionPolicy::runsDsCompliance($name)
                && \PHPCompiler\ext\ds\DsExtensionPolicy::isDsComplianceCase($name)) {
                continue;
            }
            // Functional gnupg cases set PHP_COMPILER_ENABLE_GNUPG via --ENV--; phantoms when withheld (#25360).
            if (!\PHPCompiler\ext\gnupg\GnupgExtensionPolicy::runsGnupgCompliance($name)
                && \PHPCompiler\ext\gnupg\GnupgExtensionPolicy::isGnupgComplianceCase($name)) {
                continue;
            }
            // Functional stats cases set PHP_COMPILER_ENABLE_STATS via --ENV--; phantoms when withheld (#26743).
            if (!\PHPCompiler\ext\stats\StatsExtensionPolicy::runsStatsCompliance($name)
                && \PHPCompiler\ext\stats\StatsExtensionPolicy::isStatsComplianceCase($name)) {
                continue;
            }
            // Functional mailparse cases set PHP_COMPILER_ENABLE_MAILPARSE via --ENV--; phantoms when withheld (#24908).
            if (!\PHPCompiler\ext\mailparse\MailparseExtensionPolicy::runsMailparseCompliance($name)
                && \PHPCompiler\ext\mailparse\MailparseExtensionPolicy::isMailparseComplianceCase($name)) {
                continue;
            }
            // Functional uploadprogress cases set PHP_COMPILER_ENABLE_UPLOADPROGRESS via --ENV--; phantoms when withheld (#26744).
            if (!\PHPCompiler\ext\uploadprogress\UploadprogressExtensionPolicy::runsUploadprogressCompliance($name)
                && \PHPCompiler\ext\uploadprogress\UploadprogressExtensionPolicy::isUploadprogressComplianceCase($name)) {
                continue;
            }
            // Functional dba cases set PHP_COMPILER_ENABLE_DBA via --ENV--; phantoms when withheld (#24134).
            if (!\PHPCompiler\ext\dba\DbaExtensionPolicy::runsDbaCompliance($name)
                && \PHPCompiler\ext\dba\DbaExtensionPolicy::isDbaComplianceCase($name)) {
                continue;
            }
            // Functional pdo_sqlite cases set PHP_COMPILER_ENABLE_PDO_SQLITE via --ENV--; phantoms when withheld (#24523).
            if (!\PHPCompiler\ext\pdo\PdoExtensionPolicy::runsPdoSqliteCompliance($name)
                && \PHPCompiler\ext\pdo\PdoExtensionPolicy::isPdoSqliteComplianceCase($name)) {
                continue;
            }
            // Functional odbc cases set PHP_COMPILER_ENABLE_ODBC via --ENV--; phantoms when withheld (#23969).
            if (!\PHPCompiler\ext\odbc\OdbcExtensionPolicy::runsOdbcCompliance($name)
                && \PHPCompiler\ext\odbc\OdbcExtensionPolicy::isOdbcComplianceCase($name)) {
                continue;
            }
            // Functional mysqli cases set PHP_COMPILER_ENABLE_MYSQLI via --ENV--; phantoms when withheld (#23954).
            if (!\PHPCompiler\ext\mysqli\MysqliExtensionPolicy::runsMysqliCompliance($name)
                && \PHPCompiler\ext\mysqli\MysqliExtensionPolicy::isMysqliComplianceCase($name)) {
                continue;
            }
            if (!CompilerVersion::supportsBrotli()
                && (str_contains($name, 'brotli') || str_contains($name, 'brotli_'))
                && !str_contains($name, 'brotli_phantom')) {
                continue;
            }
            if (CompilerVersion::supportsBrotli()
                && str_contains($name, 'brotli_phantom')) {
                continue;
            }
            if (!CompilerVersion::supportsMsgpack()
                && str_contains($name, 'msgpack')
                && !str_contains($name, 'extension_loaded_msgpack')) {
                continue;
            }
            if (!CompilerVersion::supportsSimdjson()
                && str_contains($name, 'simdjson')
                && !str_contains($name, 'extension_loaded_simdjson')
                && !str_contains($name, 'simdjson_phantom')) {
                continue;
            }
            if (CompilerVersion::supportsSimdjson()
                && (str_contains($name, 'simdjson_phantom')
                    || str_contains($name, 'extension_loaded_simdjson'))) {
                continue;
            }
            // xmlrpc_server_* / *_forward* set PROFILE via --ENV--; always include (#27879).
            if (!CompilerVersion::supportsXmlrpc()
                && str_contains($name, 'xmlrpc')
                && !str_contains($name, 'extension_loaded_xmlrpc')
                && !str_contains($name, 'xmlrpc_server')
                && !str_contains($name, 'forward')) {
                continue;
            }
            // wddx_packet_builders / *_forward* set PROFILE via --ENV--; always include (#27858).
            if (!CompilerVersion::supportsWddx()
                && str_contains($name, 'wddx')
                && !str_contains($name, 'extension_loaded_wddx')
                && !str_contains($name, 'wddx_packet_builders')
                && !str_contains($name, 'forward')) {
                continue;
            }
            // yaml_parse_url_forward_84 sets PROFILE via --ENV--; always include (#22252).
            if (!CompilerVersion::supportsYaml()
                && str_contains($name, 'yaml')
                && !str_contains($name, 'yaml_phantom')
                && !str_contains($name, 'yaml_parse_url_forward_84')) {
                continue;
            }
            if (CompilerVersion::supportsYaml()
                && str_contains($name, 'yaml_phantom')) {
                continue;
            }
            if (!\PHPCompiler\ext\redis\RedisExtensionPolicy::runsRedisCompliance($name)
                && \PHPCompiler\ext\redis\RedisExtensionPolicy::isRedisComplianceCase($name)) {
                continue;
            }
            if (!CompilerVersion::supportsMemcached()
                && str_contains($name, 'memcached')
                && !str_contains($name, 'memcached_phantom')
                && !str_contains($name, 'forward84')) {
                continue;
            }
            if (CompilerVersion::supportsMemcached()
                && str_contains($name, 'memcached_phantom')) {
                continue;
            }
            if (!\PHPCompiler\ext\rar\RarExtensionPolicy::runsRarCompliance($name)
                && \PHPCompiler\ext\rar\RarExtensionPolicy::isRarComplianceCase($name)) {
                continue;
            }
            if (!\PHPCompiler\ext\imap\ImapExtensionPolicy::runsImapCompliance($name)
                && \PHPCompiler\ext\imap\ImapExtensionPolicy::isImapComplianceCase($name)) {
                continue;
            }
            if (!\PHPCompiler\ext\eio\EioExtensionPolicy::runsEioCompliance($name)
                && \PHPCompiler\ext\eio\EioExtensionPolicy::isEioComplianceCase($name)) {
                continue;
            }
            if (!\PHPCompiler\ext\ssh2\Ssh2ExtensionPolicy::runsSsh2Compliance($name)
                && \PHPCompiler\ext\ssh2\Ssh2ExtensionPolicy::isSsh2ComplianceCase($name)) {
                continue;
            }
            // snmpget/snmpwalk not JIT-lowered yet (#6070); phantom registration checks are fine.
            if (str_contains($name, 'snmp')
                && !str_contains($name, 'snmp_phantom')) {
                continue;
            }
            if (CompilerVersion::supportsSnmp()
                && str_contains($name, 'snmp_phantom')) {
                continue;
            }
            // Functional zip cases set PHP_COMPILER_ENABLE_ZIP via --ENV--; phantoms when withheld (#25010).
            if (!\PHPCompiler\ext\zip\ZipExtensionPolicy::runsZipCompliance($name)
                && \PHPCompiler\ext\zip\ZipExtensionPolicy::isZipComplianceCase($name)) {
                continue;
            }
            // uri_*_profile85 / uri_phantom_profile84 set PROFILE via --ENV--; always include (#26254).
            if (!CompilerVersion::supportsUri()
                && str_contains($name, 'uri_rfc3986')
                && !str_contains($name, 'uri_phantom')
                && !str_contains($name, 'uri_exists_profile85')) {
                continue;
            }
            if (CompilerVersion::supportsUri()
                && str_contains($name, 'uri_phantom')
                && !str_contains($name, 'uri_phantom_profile84')) {
                continue;
            }
            if (!CompilerVersion::supportsBcmath()
                && (str_contains($name, 'bcadd')
                    || str_contains($name, 'bcsub')
                    || str_contains($name, 'bcmul')
                    || str_contains($name, 'bcdiv')
                    || str_contains($name, 'bcmod')
                    || str_contains($name, 'bcpow')
                    || str_contains($name, 'bcsqrt')
                    || str_contains($name, 'bcscale')
                    || str_contains($name, 'bccomp')
                    || str_contains($name, 'bcround')
                    || str_contains($name, 'bcceil')
                    || str_contains($name, 'bcfloor')
                    || str_contains($name, 'bcpowmod')
                    || str_contains($name, 'bcdivmod')
                    || str_contains($name, 'bcmath_number'))
                && !str_contains($name, 'bcmath_phantom')) {
                continue;
            }
            // convert_cyr_string / money_format removed in php-src 8.0 (#21481): functional cases use
            // PROFILE=7.4 via --ENV--; phantom_* cases assert absence — always include.
            if (!CompilerVersion::supportsStrxfrm()
                && str_contains($name, 'strxfrm')) {
                continue;
            }
            // 8.2-target reject gate; skipped when CompilerVersion 8.3+ enables typed trait constants (#5993).
            if (CompilerVersion::supportsTypedTraitConstants()
                && str_contains($name, 'trait_typed_const_reject')) {
                continue;
            }
            // 8.2-target reject gate; skipped when CompilerVersion 8.4.0+ enables typed class constants (#12798).
            if (CompilerVersion::supportsTypedClassConstants()
                && (str_contains($name, 'typed_class_const_reject')
                    || str_contains($name, 'typed_class_const_reference_profile'))) {
                continue;
            }
            // Reject gate: class const `new` is never enabled (Zend rejects all profiles, #21493).
            if (CompilerVersion::supportsClassConstObjectExpressions()) {
                if (str_contains($name, 'class_const_new_rejected')
                    || str_contains($name, 'class_const_new_reject')
                    || str_contains($name, 'class_const_new_expr')
                    || str_contains($name, 'class_const_new_reference_profile')
                    || str_contains($name, 'new_in_class_constant_reject')
                    || str_contains($name, 'new_in_constant')
                    || (str_contains($name, 'const_expr_new') && !str_contains($name, 'reject'))
                    || (str_contains($name, 'class_const_new_expression') && !str_contains($name, '_run'))
                    || (str_contains($name, 'class_const_new_object') && !str_contains($name, '_run'))
                    || (str_contains($name, 'class_const_object') && !str_contains($name, '_run'))
                    || str_contains($name, 'class_const_object_jit')) {
                    continue;
                }
            }
            if (!CompilerVersion::supportsClassConstObjectExpressions()
                && (str_contains($name, 'class_const_new_expression_run')
                    || str_contains($name, 'class_const_new_object_run')
                    || str_contains($name, 'class_const_new_stdclass')
                    || str_ends_with($name, 'class_const_new'))) {
                continue;
            }
            // Functional typed_class_const_*_forward* / --ENV-- PROFILE>=8.3 cases always include (#23757, #30857).
            if (!CompilerVersion::supportsTypedClassConstants()
                && (str_contains($name, 'typed_class_const')
                    || str_contains($name, 'typed_enum_class_const')
                    || str_contains($name, 'enum_typed_class_const')
                    || str_contains($name, 'match_typed_class_const')
                    || str_contains($name, 'reflection_class_constant_get_type'))
                && !str_contains($name, 'typed_class_const_reject')
                && !str_contains($name, 'typed_class_const_reference_profile')
                && !str_contains($name, 'forward')
                && !preg_match('/PHP_COMPILER_PROFILE\s*=\s*8\.[3-9]/', (string) ($case[2]['ENV'] ?? ''))) {
                continue;
            }
            // 8.4-target reject gate; skipped when exit()/die() function form enabled (#12413, #12435).
            if (CompilerVersion::supportsExitFunctionForm()
                && (str_contains($name, 'exit_named_status_reference_profile')
                    || str_contains($name, 'die_named_message_reference_profile')
                    || str_contains($name, 'exit_die_fcc_reference_profile'))) {
                continue;
            }
            // Pre-8.4 construct soft-coerces array status (#5441); 8.4+ TypeError (#22492).
            if (CompilerVersion::supportsExitFunctionForm()
                && str_contains($name, 'exit_array_status')
                && !str_contains($name, 'exit_array_status_type_error')) {
                continue;
            }
            if (!CompilerVersion::supportsExitFunctionForm()
                && (str_contains($name, 'exit_function_php84')
                    || str_contains($name, 'exit_function_strict_types')
                    || str_contains($name, 'exit_bool_status_php84')
                    || str_contains($name, 'exit_float_status_php84')
                    || str_contains($name, 'exit_null_status_php84')
                    || str_contains($name, 'exit_resource_typeerror_php84')
                    || str_contains($name, 'exit_die_two_args')
                    || str_contains($name, 'exit_type_error')
                    || str_contains($name, 'exit_array_status_type_error')
                    || str_contains($name, 'exit_status_named')
                    || (str_contains($name, 'exit_named_status')
                        && !str_contains($name, 'exit_named_status_reference_profile'))
                    || str_contains($name, 'die_status_named')
                    || (str_contains($name, 'die_named_message')
                        && !str_contains($name, 'die_named_message_reference_profile')))) {
                continue;
            }
            // 8.4-target reject gate; skipped when asymmetric visibility enabled (#12508).
            if (CompilerVersion::supportsAsymmetricVisibility()
                && (str_contains($name, 'private_set_reference_profile')
                    || str_contains($name, 'asymmetric_double_modifier_reference_profile')
                    || str_contains($name, 'asymmetric_visibility_reference_profile')
                    || str_contains($name, 'asymmetric_visibility_profile_gate')
                    || str_contains($name, 'asymmetric_visibility_public_protected_set_compile_error')
                    || str_contains($name, 'asymmetric_visibility_promoted_public_protected_set_compile_error')
                    || str_contains($name, 'asymmetric_visibility_promoted_public_private_set_compile_error'))) {
                continue;
            }
            if (CompilerVersion::supportsParenthesizedAsymmetricSetModifier()
                && str_contains($name, 'asymmetric_visibility_paren_reference_profile')) {
                continue;
            }
            if (CompilerVersion::supportsParenthesizedAsymmetricSetModifier()
                && str_contains($name, 'asymmetric_visibility_bare_set_reject')) {
                continue;
            }
            if (!CompilerVersion::supportsParenthesizedAsymmetricSetModifier()
                && str_contains($name, 'asymmetric_visibility_bare_private_set_forward')) {
                continue;
            }
            if (!CompilerVersion::supportsParenthesizedAsymmetricSetModifier()
                && str_contains($name, 'asymmetric_visibility_paren_syntax')) {
                continue;
            }
            if (!CompilerVersion::supportsAsymmetricVisibility()
                && (str_contains($name, 'asymmetric')
                    || str_contains($name, 'property_hook_private_set')
                    || str_contains($name, 'reflection_property_asymmetric')
                    || str_contains($name, 'promoted_private_set'))
                && !str_contains($name, 'private_set_reference_profile')
                && !str_contains($name, 'asymmetric_double_modifier_reference_profile')
                && !str_contains($name, 'asymmetric_visibility_reference_profile')
                && !str_contains($name, 'asymmetric_visibility_profile_gate')
                // PROFILE-env SKIPIF — keep runnable if the gate wrongly returns true (#24819).
                && !str_contains($name, 'asymmetric_visibility_default_profile')
                && !str_contains($name, 'asymmetric_visibility_forward_84')) {
                continue;
            }
            if (!CompilerVersion::supportsPropertyHooks()
                && (str_contains($name, 'property_hook')
                    || str_contains($name, 'property_magic_const'))
                && !str_contains($name, 'reference_profile')
                && !str_contains($name, 'profile_gate')
                && !str_contains($name, 'forward_profile')
                && !str_contains($name, 'property_magic_outside_hook_compile_error')) {
                continue;
            }
            if (CompilerVersion::supportsPropertyHooks()
                && str_contains($name, 'property_magic_outside_hook_runtime_error')) {
                continue;
            }
            if (!CompilerVersion::supportsPropertyHooks()
                && (str_contains($name, 'asymmetric_get_only_hook_compile')
                    || str_contains($name, 'asymmetric_get_only_hook_write'))) {
                continue;
            }
            // 8.4-target reject gate; skipped when bare rethrow enabled (#3508, #14239, #15357).
            if (CompilerVersion::supportsBareRethrow()
                && str_contains($name, 'bare_throw_reference_profile')) {
                continue;
            }
            if (!CompilerVersion::supportsBareRethrow()
                && (str_contains($name, 'bare_throw') || str_contains($name, 'throw_rethrow'))
                && !str_contains($name, 'bare_throw_reference_profile')) {
                continue;
            }
            // gc_status PHP 8.4 schema; forward profile only (#15784, ext/standard/php_gc.c).
            if (!CompilerVersion::supportsGcStatusPhp84Schema()
                && str_contains($name, 'gc_status')
                && !str_contains($name, 'gc_status_reference_profile')) {
                continue;
            }
            if (CompilerVersion::supportsGcStatusPhp84Schema()
                && str_contains($name, 'gc_status_reference_profile')) {
                continue;
            }
            if (!CompilerVersion::supportsDatePeriodCreateFromISO8601String()
                && str_contains($name, 'date_period_create_from_iso8601')
                && !str_contains($name, 'date_period_create_from_iso8601_phantom')
                && !str_contains($name, 'date_period_create_from_iso8601_include_end_date_forward_profile')) {
                continue;
            }
            if (CompilerVersion::supportsDatePeriodCreateFromISO8601String()
                && str_contains($name, 'date_period_create_from_iso8601_phantom')) {
                continue;
            }
            // JIT/AOT runtime object dispatch for materialized DatePeriod still segfaults (#16796).
            if (str_contains($name, 'date_period_create_from_iso8601')
                && !str_contains($name, 'date_period_create_from_iso8601_phantom')) {
                continue;
            }
            // getIterator → InternalIterator snapshot still trips MCJIT pcreJit init (#22263 / #16796).
            if (str_contains($name, 'dateperiod_getiterator')) {
                continue;
            }
            if (!CompilerVersion::supportsDomElementInsertAdjacentHtml()
                && str_contains($name, 'dom_element_insert_adjacent_html')
                && !str_contains($name, 'insert_adjacent_html_phantom')) {
                continue;
            }
            if (CompilerVersion::supportsDomElementInsertAdjacentHtml()
                && str_contains($name, 'insert_adjacent_html_phantom')) {
                continue;
            }
            if (!CompilerVersion::supportsDomElementGetElementsByClassName()
                && str_contains($name, 'get_elements_by_class_name_85')) {
                continue;
            }
            if (!CompilerVersion::supportsDomElementGetElementsByClassName()
                && str_contains($name, 'dom_html_collection_classname')) {
                continue;
            }
            if (CompilerVersion::supportsDomElementGetElementsByClassName()
                && str_contains($name, 'get_elements_by_class_name_phantom')) {
                continue;
            }
            if (!CompilerVersion::supportsDomElementInsertAdjacentElement()
                && str_contains($name, 'dom_element_insert_adjacent_element')
                && !str_contains($name, 'insert_adjacent_element_phantom')) {
                continue;
            }
            if (CompilerVersion::supportsDomElementInsertAdjacentElement()
                && str_contains($name, 'insert_adjacent_element_phantom')) {
                continue;
            }
            if (!CompilerVersion::supportsDomElementInsertAdjacentText()
                && str_contains($name, 'dom_element_insert_adjacent_text')
                && !str_contains($name, 'insert_adjacent_text_phantom')) {
                continue;
            }
            if (CompilerVersion::supportsDomElementInsertAdjacentText()
                && str_contains($name, 'insert_adjacent_text_phantom')) {
                continue;
            }
            if (!CompilerVersion::supportsDomElementInnerOuterHtml()
                && str_contains($name, 'dom_element_inner_outer_html')
                && !str_contains($name, 'inner_outer_html_phantom')) {
                continue;
            }
            if (CompilerVersion::supportsDomElementInnerOuterHtml()
                && str_contains($name, 'inner_outer_html_phantom')) {
                continue;
            }
            if (!CompilerVersion::supportsDomElementToggleAttribute()
                && str_contains($name, 'dom_element_toggle_attribute')
                && !str_contains($name, 'toggle_attribute_phantom')) {
                continue;
            }
            if (CompilerVersion::supportsDomElementToggleAttribute()
                && str_contains($name, 'toggle_attribute_phantom')) {
                continue;
            }
            if (!CompilerVersion::supportsDomElementGetAttributeNames()
                && str_contains($name, 'dom_element_get_attribute_names')
                && !str_contains($name, 'get_attribute_names_phantom')) {
                continue;
            }
            if (CompilerVersion::supportsDomElementGetAttributeNames()
                && str_contains($name, 'get_attribute_names_phantom')) {
                continue;
            }
            if (!CompilerVersion::supportsDomNodeContains()
                && str_contains($name, 'dom_node_contains')
                && !str_contains($name, 'dom_node_contains_phantom')) {
                continue;
            }
            if (CompilerVersion::supportsDomNodeContains()
                && str_contains($name, 'dom_node_contains_phantom')) {
                continue;
            }
            if (!CompilerVersion::supportsDomNodeCompareDocumentPosition()
                && str_contains($name, 'dom_node_compare_document_position')
                && !str_contains($name, 'compare_document_position_phantom')) {
                continue;
            }
            if (CompilerVersion::supportsDomNodeCompareDocumentPosition()
                && str_contains($name, 'compare_document_position_phantom')) {
                continue;
            }
            if (!CompilerVersion::supportsDomNodeGetRootNode()
                && str_contains($name, 'dom_node_get_root_node')
                && !str_contains($name, 'get_root_node_phantom')) {
                continue;
            }
            if (CompilerVersion::supportsDomNodeGetRootNode()
                && str_contains($name, 'get_root_node_phantom')) {
                continue;
            }
            if (!CompilerVersion::supportsDomNodeIsConnected()
                && str_contains($name, 'dom_node_is_connected')
                && !str_contains($name, 'is_connected_phantom')) {
                continue;
            }
            if (CompilerVersion::supportsDomNodeIsConnected()
                && str_contains($name, 'is_connected_phantom')) {
                continue;
            }
            if (!CompilerVersion::supportsDomNodeIsEqualNode()
                && str_contains($name, 'dom_node_is_equal_node')
                && !str_contains($name, 'is_equal_node_phantom')) {
                continue;
            }
            if (CompilerVersion::supportsDomNodeIsEqualNode()
                && str_contains($name, 'is_equal_node_phantom')) {
                continue;
            }
            if (!CompilerVersion::supportsDomNodeReplaceChildren()
                && str_contains($name, 'dom_node_replace_children')
                && !str_contains($name, 'replace_children_phantom')) {
                continue;
            }
            if (CompilerVersion::supportsDomNodeReplaceChildren()
                && str_contains($name, 'replace_children_phantom')) {
                continue;
            }
            // DOMElement::getAttributeNames() multi-element array return segfaults under JIT (#16823); VM-only for now.
            if (str_contains($name, 'dom_element_get_attribute_names')
                && !str_contains($name, 'get_attribute_names_phantom')) {
                continue;
            }
            // Dom\TokenList / Dom\Element::$classList — VM ext/dom method dispatch; JIT VM-only (#16876, #28227).
            if (str_contains($name, 'dom_token_list')) {
                continue;
            }
            // socket_create/connect/read/write — VM + libc FFI first; JIT lowering follows (#19286).
            if (str_contains($name, 'socket_create_connect_rw')) {
                continue;
            }
            // socket_recv/socket_send — VM + libc FFI first; JIT lowering follows (#20238).
            if (str_contains($name, 'socket_recv_send')) {
                continue;
            }
            // AF_UNIX bind/listen — VM + libc FFI first (#20268).
            if (str_contains($name, 'socket_afunix_bind_listen')) {
                continue;
            }
            // socket_strerror/last_error/clear_error — VM first (#6227).
            if (str_contains($name, 'socket_strerror')) {
                continue;
            }
            // 8.4-target reject gate; skipped when encapsed ?? interpolation enabled (#14063).
            if (CompilerVersion::supportsEncapsedCoalesce()
                && str_contains($name, 'encapsed_coalesce_parse_error')) {
                continue;
            }
            if (!CompilerVersion::supportsEncapsedCoalesce()
                && str_contains($name, 'encapsed_coalesce_interpolation')) {
                continue;
            }
            // 8.5-target reject gate; skipped when clone-with syntax enabled (#12987, #23877).
            if (CompilerVersion::supportsCloneWithSyntax()
                && str_contains($name, 'clone_with_reference_profile')) {
                continue;
            }
            // Keyword `with` rejection cases run on every profile (#29187); paren form needs 8.5+.
            if (!CompilerVersion::supportsCloneWithSyntax()
                && str_contains($name, 'clone_with')
                && !str_contains($name, 'clone_with_reference_profile')
                && !str_contains($name, 'clone_with_forward_profile')
                && !str_contains($name, 'clone_with_brace_rejected')
                && !str_contains($name, 'clone_with_keyword_array')
                && !str_contains($name, 'clone_with_paren')) {
                continue;
            }
            // php-src never shipped try/catch/else (#31159) — reject cases always run.
            // Execute fixtures skipped while the gate is false (defense if re-enabled).
            if (!CompilerVersion::supportsTryCatchElse()
                && str_contains($name, 'try_catch_else')
                && !str_contains($name, 'try_catch_else_reference_profile')) {
                continue;
            }
            // 8.5-target reject gate; skipped when pipe operator enabled (#12424, #18007, #22792).
            // profile84_reject uses --ENV-- PROFILE=8.4 — always include.
            if (CompilerVersion::supportsPipeOperator()
                && str_contains($name, 'pipe_operator_reference_profile')) {
                continue;
            }
            if (!CompilerVersion::supportsPipeOperator()
                && str_contains($name, 'pipe_operator')
                && !str_contains($name, 'pipe_operator_reference_profile')
                && !str_contains($name, 'pipe_operator_profile84_reject')
                && !str_contains($name, 'pipe_operator_forward_profile')) {
                continue;
            }
            if (!CompilerVersion::supportsPipeOperator()
                && (str_contains($name, 'pipe_first_class') || str_contains($name, 'pipe_enum_case'))) {
                continue;
            }
            // 8.3-target reject gate; skipped when new readonly class enabled (#16255).
            if (CompilerVersion::supportsReadonlyAnonymousClass()
                && str_contains($name, 'readonly_anonymous_class_reference_profile')) {
                continue;
            }
            if (!CompilerVersion::supportsReadonlyAnonymousClass()
                && str_contains($name, 'readonly_anonymous_class')
                && !str_contains($name, 'readonly_anonymous_class_reference_profile')) {
                continue;
            }
            if (!CompilerVersion::supportsReadonlyAnonymousClass()
                && str_contains($name, 'readonly_anonymous_defaults')) {
                continue;
            }
            if (!CompilerVersion::supportsReadonlyAnonymousClass()
                && str_contains($name, 'readonly_anonymous_ctor_args')) {
                continue;
            }
            if (!CompilerVersion::supportsReadonlyAnonymousClass()
                && str_contains($name, 'anonymous_readonly_class_forward_84')) {
                continue;
            }
            // 8.3-target reject gate; skipped when typed function-local static enabled (#16512, #9998).
            if (CompilerVersion::supportsTypedFunctionStatic()
                && str_contains($name, 'typed_function_static_reference_profile')) {
                continue;
            }
            if (!CompilerVersion::supportsTypedFunctionStatic()
                && str_contains($name, 'typed_function_static')
                && !str_contains($name, 'typed_function_static_reference_profile')) {
                continue;
            }
            // 8.2 reject gate; skipped when arbitrary static initializers enabled (#22923).
            // static_var_param_init_83 sets PROFILE via --ENV--; always include.
            if (CompilerVersion::supportsArbitraryStaticVariableInitializers()
                && str_contains($name, 'static_var_param_init_fatal')) {
                continue;
            }
            // 8.3-target reject gate; skipped when file/namespace typed constants enabled (#16651, #7081).
            if (CompilerVersion::supportsGlobalTypedConstants()
                && str_contains($name, 'typed_top_level_const_82')) {
                continue;
            }
            if (!CompilerVersion::supportsGlobalTypedConstants()
                && str_contains($name, 'global_typed_const')
                && !str_contains($name, 'typed_top_level_const_82')
                && !str_contains($name, 'final_global_typed_constant_reject')) {
                continue;
            }
            // global_deprecated_const.phpt sets PROFILE=8.5 via --ENV-- (#26308); always include
            // so SKIPIF/--ENV-- can enable TARGET_CONSTANT — do not gate on the provider profile.
            if (CompilerVersion::supportsGlobalDeprecatedConstAttributes()
                && str_contains($name, 'global_deprecated_const_reference_profile')) {
                continue;
            }
            if (!CompilerVersion::supportsFinalGlobalTypedConstants()
                && str_contains($name, 'final_global_typed_const')
                && !str_contains($name, 'final_global_typed_constant_reject')) {
                continue;
            }
            if (CompilerVersion::supportsFinalGlobalTypedConstants()
                && str_contains($name, 'final_global_typed_constant_reject')) {
                continue;
            }
            // 8.3-target reject gate; skipped when class const brace deref enabled (#16597).
            if (CompilerVersion::supportsClassConstBraceDeref()
                && str_contains($name, 'class_const_brace_deref')
                && !str_contains($name, '_forward')) {
                continue;
            }
            if (!CompilerVersion::supportsClassConstBraceDeref()
                && str_contains($name, 'class_const_brace_deref_forward')) {
                continue;
            }
            // 8.3-target reject gate; skipped when dynamic class const fetch enabled (#17863).
            // Functional *_forward_83 cases set PROFILE via --ENV--; always include (#23760).
            if (CompilerVersion::supportsDynamicClassConstFetch()
                && str_contains($name, 'class_const_dynamic_fetch_profile')) {
                continue;
            }
            if (!CompilerVersion::supportsDynamicClassConstFetch()
                && str_contains($name, 'class_const_dynamic_fetch_forward')
                && !str_contains($name, 'forward_83')) {
                continue;
            }
            if (!CompilerVersion::supportsDynamicClassConstFetch()
                && str_contains($name, 'class_const_dynamic_fetch_no_warnings')) {
                continue;
            }
            // 8.4-target reject gate; skipped when parenthesized DNF intersection types enabled (#14904).
            if (CompilerVersion::supportsParenthesizedDnfIntersectionTypes()
                && str_contains($name, 'dnf_paren_intersection_reference_profile')) {
                continue;
            }
            if (!CompilerVersion::supportsParenthesizedDnfIntersectionTypes()
                && str_contains($name, 'dnf_paren_intersection')
                && !str_contains($name, 'dnf_paren_intersection_reference_profile')
                && !str_contains($name, 'dnf_paren_union_only')) {
                continue;
            }
            if (!CompilerVersion::supportsClassHasFunctions()
                && str_contains($name, 'class_has_')
                && !str_contains($name, 'class_has_lazy_object')
                && !str_contains($name, 'class_has_functions_phantom')) {
                continue;
            }
            if (CompilerVersion::supportsClassHasFunctions()
                && str_contains($name, 'class_has_functions_phantom')) {
                continue;
            }
            if (!CompilerVersion::supportsDomLivingStandardNamespace()
                && (str_contains($name, 'dom_html_document') || str_contains($name, 'dom_xml_document'))
                && !str_contains($name, 'dom_html_document_phantom')
                && !str_contains($name, 'dom_xml_document_phantom')) {
                continue;
            }
            if (CompilerVersion::supportsDomLivingStandardNamespace()
                && (str_contains($name, 'dom_html_document_phantom')
                    || str_contains($name, 'dom_xml_document_phantom')
                    || str_contains($name, 'dom_html_no_default_ns_phantom'))) {
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
            // eval() final class const override: VM E_COMPILE_ERROR via TYPE_EVAL (#22922); MCJIT inline eval deferral pending.
            if (str_contains($name, 'final_class_const_eval_override')) {
                continue;
            }
            // eval() final plain property override: VM inheritFromParent (#22988); MCJIT inline eval deferral pending.
            if (str_contains($name, 'final_plain_property_eval_override')) {
                continue;
            }
            // eval() final static property override: VM inheritFromParent (#24992); MCJIT inline eval deferral pending.
            if (str_contains($name, 'final_static_property_eval_override')) {
                continue;
            }
            // eval() final method override: VM inheritFromParent (#24884); MCJIT inline eval deferral pending.
            if (str_contains($name, 'final_method_eval_override')) {
                continue;
            }
            // eval() inheritance variance: VM inheritFromParent (#25384); MCJIT inline eval deferral pending.
            if (str_contains($name, 'inheritance_variance_cross_eval')) {
                continue;
            }
            // eval() catchable CompileError (zend_throw_exception): VM TYPE_EVAL (#25114); MCJIT TYPE_SMALLER / inline eval pending.
            if (str_contains($name, 'eval_compile_error_catchable')) {
                continue;
            }
            // final plain property override/child_override _84/_85: host PHP 8.2 parser rejects `final` on plain properties (#24687, #26306).
            if (str_contains($name, 'final_plain_property_override_84')
                || str_contains($name, 'final_plain_property_override_85')
                || str_contains($name, 'final_plain_property_child_override_84')
                || str_contains($name, 'final_plain_property_trait_override_84')
                || str_contains($name, 'final_plain_property_override_after_ternary_84')) {
                continue;
            }
            // final static property read: VM green (#23403); MCJIT static prop module-verify pending (same as plain static $x).
            if (str_ends_with($name, 'final_static_property')) {
                continue;
            }
            // preserve_keys=true: VM + JIT/AOT via ArrayBuiltinHelper (#3524).
            // array_merge_recursive(): VM + JIT via ArrayMergeRecursiveJitHelper PHP (#3297, #10183).
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
            if (str_contains($name, 'gc_collect_cycles') && !str_contains($name, 'argcount')) {
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
            // var_export(StaticCall::__set_state([]), true): inline StaticCall producer wiring (#11896); VM only.
            if (str_contains($name, 'var_export_set_state_inline')) {
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
            // ZipArchive::count()/Countable MCJIT: VM green (#19492); jit.php subprocess unstable (fclose harness).
            if (str_contains($name, 'ziparchive_forward_profile')) {
                continue;
            }
            // curl_share_init_persistent happy-path / PROFILE=8.4 phantom: VM green (#20530);
            // jit.php hits MathBaseConvertRuntime::constFloat on some hosts (hexdec/WeakRef bootstrap).
            if (str_contains($name, 'curl_share_init_persistent')
                && !str_contains($name, 'curl_share_init_persistent_errors')
                && !str_contains($name, 'curl_share_init_persistent_type')) {
                continue;
            }
            // Dom\XPath::query()/evaluate node-sets: VM green (#20757); jit.php WeakRefNativeOpsJit::nullSlot
            // getValue() abort during helper bootstrap when compiling Dom\XPath::query().
            if (str_contains($name, 'dom_xpath_living')) {
                continue;
            }
            // ResourceBundle getLocales/errors: VM green (#20739); jit.php WeakRefNativeOpsJit::nullSlot
            // getValue() abort during helper bootstrap (same as dom_xpath_living).
            if (str_contains($name, 'resourcebundle_locales_errors')) {
                continue;
            }
            // IntlBreakIterator preceding/following: VM + bin/jit.php repro green (#20771);
            // MCJIT PHPT harness hits WeakRefNativeOpsJit::nullSlot getValue() abort (same as above).
            if (str_contains($name, 'breakiterator_preceding_following')) {
                continue;
            }
            // IntlCodePointBreakIterator: VM green (#20822); MCJIT PHPT harness same WeakRef abort.
            if (str_contains($name, 'breakiterator_codepoint')) {
                continue;
            }
            // IntlRuleBasedBreakIterator construct/getRules: VM green (#20907); MCJIT PHPT harness
            // same WeakRefNativeOpsJit::nullSlot getValue() abort.
            if (str_contains($name, 'intl_rulebased_breakiterator_construct')) {
                continue;
            }
            // IntlPartsIterator instanceof Iterator: VM green (#20985); MCJIT PHPT harness same
            // WeakRefNativeOpsJit::nullSlot getValue() abort as other breakiterator PHPTs.
            if (str_contains($name, 'intl_parts_iterator_20985')) {
                continue;
            }
            // IntlBreakIterator IteratorAggregate: VM green (#20986); MCJIT PHPT harness same
            // WeakRefNativeOpsJit::nullSlot getValue() abort as other breakiterator PHPTs.
            if (str_contains($name, 'intl_breakiterator_aggregate_20986')) {
                continue;
            }
            // Spoofchecker::setAllowedChars: VM + bin/jit.php repro green (#20823);
            // MCJIT PHPT harness hits WeakRefNativeOpsJit::nullSlot getValue() abort (same as above).
            if (str_contains($name, 'spoofchecker_set_allowed_chars')) {
                continue;
            }
            // IntlListFormatter: VM green (#23229); JIT lowering deferred (VmClassMethod VM-only).
            if (str_contains($name, 'intl_list_formatter')) {
                continue;
            }
            // Phar instance API: VM green (#20628); jit.php hits MathBaseConvertRuntime::constFloat
            // (hexdec/WeakRef bootstrap) same as curl_share_init_persistent.
            if (str_contains($name, 'phar_instance_api')) {
                continue;
            }
            // Phar extends RecursiveDirectoryIterator: VM green (#22293); same Phar ctor MCJIT harness skip.
            if (str_contains($name, 'phar_extends_rdi')) {
                continue;
            }
            // Phar delete/count/isBuffering: VM green (#21228); MCJIT PHPT same Phar ctor harness
            // failure as #20628 ("Current basic block has no parent function").
            if (str_contains($name, 'phar_delete_count_buffer')) {
                continue;
            }
            // Phar metadata API: VM green (#21229); same Phar ctor MCJIT harness skip as #21228.
            if (str_contains($name, 'phar_metadata')) {
                continue;
            }
            // Phar getVersion/isWritable/getModified: VM green (#21230); same Phar ctor MCJIT skip.
            if (str_contains($name, 'phar_version_writable_modified')) {
                continue;
            }
            // Phar decompressFiles/setDefaultStub/copy: VM green (#21231); same Phar ctor MCJIT skip.
            if (str_contains($name, 'phar_decompress_stub_copy')) {
                continue;
            }
            // Phar loadPhar/unlinkArchive: VM green (#21232); same Phar ctor MCJIT skip.
            if (str_contains($name, 'phar_unlink_load')) {
                continue;
            }
            // PharFileInfo metadata/chmod/compress: VM green (#21651/#21652/#21653); same Phar ctor MCJIT skip.
            if (str_contains($name, 'pharfileinfo_metadata')
                || str_contains($name, 'pharfileinfo_chmod_flags')
                || str_contains($name, 'pharfileinfo_compress')) {
                continue;
            }
            // Phar::convertToData(ZIP): VM green (#21675); same Phar ctor MCJIT harness skip as #20628.
            if (str_contains($name, 'phar_convert_to_data_zip')) {
                continue;
            }
            // PharData ZIP open/isFileFormat: VM green (#21676); same Phar ctor MCJIT harness skip.
            if (str_contains($name, 'phar_data_zip_open')) {
                continue;
            }
            // PharData introspection on tar: VM green (#21692); same Phar ctor MCJIT harness skip.
            if (str_contains($name, 'phardata_introspection')) {
                continue;
            }
            // Phar::convertToData(TAR, GZ): VM green (#21677); same Phar ctor MCJIT harness skip.
            if (str_contains($name, 'phar_convert_to_data_tar_gz')) {
                continue;
            }
            // Phar::convertToExecutable(ZIP): VM green (#21678); same Phar ctor MCJIT harness skip.
            if (str_contains($name, 'phar_convert_to_executable_zip')) {
                continue;
            }
            if (str_contains($name, 'curl_share_persistent_phantom')) {
                continue;
            }
            // SoapClient v1 — VM-only until JIT class-method lowering (#20037 / #3724).
            if (str_contains($name, 'soap_client')) {
                continue;
            }
            // ext/mysqli — VM host bridge; JIT builtins deferred (#3435, #21788).
            if (str_contains($name, 'mysqli')) {
                continue;
            }
            // FFI::cdef / dynamic C calls — VM + host libffi first (#4420); JIT class-method deferred.
            if (str_contains($name, 'ffi_puts')) {
                continue;
            }
            // use_soap_error_handler — VM-first (#20267).
            if (str_contains($name, 'soap_use_soap_error_handler')) {
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
            // method_exists/property_exists named stubs (#23399): Reflection + DateTime::class MCJIT unstable — light *_jit.phpt.
            if (str_contains($name, 'named_args_method_exists_property_exists') && !str_contains($name, '_jit')) {
                continue;
            }
            // DateTime format-constant ReflectionClass map: VM + AOT (#22271); MCJIT Reflection unstable — light *_jit.phpt.
            if (str_contains($name, 'datetime_format_constants_defined') && !str_contains($name, '_jit')) {
                continue;
            }
            // DatePeriod option-flag ReflectionClass map: VM + AOT (#20071); MCJIT OOM on Reflection — light *_jit.phpt.
            if (str_contains($name, 'dateperiod_option_flags_defined') && !str_contains($name, '_jit')) {
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
            // filter_input() request-input snapshot (#19640): VM captures IF_G tables; MCJIT still reads live sg_*.
            if (str_contains($name, 'filter_input_request_snapshot')
                || str_contains($name, 'filter_input_cgi_snapshot_immutable')) {
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
            // ReflectionProperty/Constant/Function::getAttributes() MCJIT: VM read path (#4136, #2467, #19418).
            if (str_contains($name, 'reflection_property_attributes')
                || str_contains($name, 'reflection_constant_attributes')
                || str_contains($name, 'reflection_function_getattributes')) {
                continue;
            }
            // ReflectionProperty asymmetric probes: VM builtins + asymmetric syntax (#6977).
            if (str_contains($name, 'reflection_property_asymmetric')) {
                continue;
            }
            // ReflectionProperty::{isReadable,isWritable} profile gates: VM-only (#15664).
            if (str_contains($name, 'reflection_property_isreadable')) {
                continue;
            }
            // ReflectionProperty::isDynamic profile gates: VM-only (#15676).
            if (str_contains($name, 'reflection_property_isdynamic')) {
                continue;
            }
            // ReflectionEnumUnitCase::isDeprecated profile gates: VM-only (#15767).
            if (str_contains($name, 'reflection_enum_unit_case_is_deprecated')) {
                continue;
            }
            // ReflectionClassConstant::isDeprecated profile gates: VM-only (#17104).
            if (str_contains($name, 'reflection_class_constant_is_deprecated')) {
                continue;
            }
            // ReflectionFunction::isDeprecated profile gates: VM-only (#9760).
            if (str_contains($name, 'reflection_function_is_deprecated')) {
                continue;
            }
            // Reflection createFrom* factory profile gates: VM-only (#16724).
            if (str_contains($name, 'reflection_create_from_callable')
                || str_contains($name, 'reflection_function_create_from')) {
                continue;
            }
            // Reflection docblock/source getters are VM-only (#7358).
            if (str_contains($name, 'reflection_docblock_source')) {
                continue;
            }
            // ReflectionFunction location/namespace getters are VM-only (#22144).
            if (str_contains($name, 'reflection_function_location_namespace')) {
                continue;
            }
            // ReflectionFunctionAbstract::returnsReference is VM-only (#22171).
            if (str_contains($name, 'reflection_returns_reference')) {
                continue;
            }
            // ReflectionMethod::getClosureCalledClass is VM-only (#22166).
            if (str_contains($name, 'reflection_method_get_closure_called_class')) {
                continue;
            }
            // ReflectionMethod namespace helpers are VM-only (#22167).
            if (str_contains($name, 'reflection_method_namespace_helpers')) {
                continue;
            }
            // ReflectionFunction tentative return type getters are VM-only (#22169).
            if (str_contains($name, 'reflection_function_tentative_return')) {
                continue;
            }
            // ReflectionMethod isClosure/__toString are VM-only (#22173).
            if (str_contains($name, 'reflection_method_isclosure_tostring')) {
                continue;
            }
            // ReflectionClass/Property/Function __toString are VM-only (#22379).
            if (str_contains($name, 'reflection_class_property_function_tostring')) {
                continue;
            }
            // ReflectionType::__toString DNF/?T: VM-only (#23065); MCJIT lacks ReflectionFunction::getParameters.
            if (str_contains($name, 'reflection_type_tostring_dnf_null')) {
                continue;
            }
            // ReflectionClass::getExtension() / ReflectionExtension are VM-only (#11462).
            if (str_contains($name, 'reflection_extension_class')) {
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
            // printf()/sprintf() null format TypeError getMessage in try/catch: VM + AOT (#16042); MCJIT pending TypeError introspection (#98).
            if (str_contains($name, 'printf_null_format_typeerror') && !str_contains($name, '_jit')) {
                continue;
            }
            // printf(null) E_DEPRECATED writes to SAPI stdout under MCJIT — VM + AOT (#18764); JIT via sprintf_null_deprecated_jit.
            if (str_contains($name, 'printf_null_deprecated') && !str_contains($name, '_jit')) {
                continue;
            }
            // addcslashes() null characters TypeError getMessage in try/catch under strict_types: VM + AOT (#17829); MCJIT pending TypeError introspection (#98).
            if (str_contains($name, 'addcslashes_null_characters_strict_typeerror') && !str_contains($name, '_jit')) {
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
            // ++/-- Undefined property + deprecation: DynPropIncUndefinedWarnJITTest (#29241).
            if (str_contains($name, 'dyn_prop_inc_undefined_warn')) {
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
            // Fiber getTrace phantom guard + ReflectionFiber::getTrace — VM FiberState (#22562).
            if (str_contains($name, 'fiber_get_trace')) {
                continue;
            }
            // Fiber::suspend($this) from instance-method closure — VM applyClosureBinding (#25777);
            // class+Fiber MCJIT still trips nested helper StreamLibcHandle link (same as plain class Fiber).
            if (str_contains($name, 'fiber_suspend_this')) {
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
            // 8.4+ array TypeError case (exit_array_status_type_error) stays on JIT (#22492).
            if (str_contains($name, 'exit_array_status')
                && !str_contains($name, 'exit_array_status_type_error')) {
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
            // Builtin enums (PropertyHookType): VM + enum registration; MCJIT execute gated (#7222).
            // ExitStatus phantom retired — exit_status_enum.phpt asserts absence (#28500, re-#28200).
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
                && !str_contains($name, 'enum_method_nullable_self_return')
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
            // isset() on uninitialized static typed property: VM (#15112); MCJIT file-scope declare segfault (#4908).
            if (str_contains($name, 'isset_static_typed_uninit')) {
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
            // preg_match() float offset: VM + AOT (#13818); MCJIT stderr deprecation merged before stdout.
            if (str_contains($name, 'preg_match_float_offset') && !str_contains($name, '_jit')) {
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