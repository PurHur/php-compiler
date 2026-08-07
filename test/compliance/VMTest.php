<?php

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;
use PHPCompiler\Backend\VM\Runtime;

require_once __DIR__ . '/../BaseTest.php';

class VMTest extends BaseTest {
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
            if (!CompilerVersion::supportsHex2binStrict()
                && str_contains($name, 'hex2bin_strict')
                && !str_contains($name, 'hex2bin_strict_arity_reference_profile')
                && !str_contains($name, 'hex2bin_strict_named_reference_profile')) {
                continue;
            }
            if (CompilerVersion::supportsHex2binStrict()
                && (str_contains($name, 'hex2bin_strict_arity_reference_profile')
                    || str_contains($name, 'hex2bin_strict_named_reference_profile'))) {
                continue;
            }
            if (!CompilerVersion::supportsFpow()
                && (str_contains($name, 'fpow') || str_contains($name, 'fmin') || str_contains($name, 'fmax')
                    || str_contains($name, 'fadd') || str_contains($name, 'fsub') || str_contains($name, 'fmul'))
                && !str_contains($name, 'php84_math_string_builtins_phantom')
                && !str_contains($name, 'fpow_function_exists_forward_profile')
                && !str_contains($name, 'fpow_roundingmode_argcount')) {
                continue;
            }
            if (!CompilerVersion::supportsNextafter()
                && str_contains($name, 'nextafter')
                && !str_contains($name, 'php84_math_string_builtins_phantom')
                && !str_contains($name, 'nextafter_profile')) {
                continue;
            }
            if (CompilerVersion::supportsNextafter()
                && str_contains($name, 'nextafter_profile')) {
                continue;
            }
            if (CompilerVersion::supportsNextafter()
                && !CompilerVersion::advertisesNextafter()
                && str_contains($name, 'nextafter')
                && !str_contains($name, 'nextafter_profile')
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
            // Functional json_validate_*_forward* cases set PROFILE via --ENV--; always include (#22544).
            if (!CompilerVersion::supportsJsonValidate()
                && str_contains($name, 'json_validate')
                && !str_contains($name, 'json_validate_phantom')
                && !str_contains($name, 'json_validate_function_exists_profile')
                && !str_contains($name, 'forward')) {
                continue;
            }
            if (!CompilerVersion::supportsSortingEnum()
                && str_contains($name, 'sort_sorting_enum')
                && !str_contains($name, 'sorting_enum_phantom')
                && !str_contains($name, 'usort_phantom_direction')
                && !str_contains($name, 'usort_sort_direction')) {
                continue;
            }
            if (CompilerVersion::supportsSortingEnum()
                && str_contains($name, 'sorting_enum_phantom')) {
                continue;
            }
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
                    || str_contains($name, 'string_trim_mode')
                    || str_contains($name, 'memory_usage_enum')
                    || str_contains($name, 'session_status_enum')
                    || str_contains($name, 'requestmethod_enum')
                    || str_contains($name, 'phpinfo_infoview')
                    || str_contains($name, 'http_response_code_enum')
                    || str_contains($name, 'filter_input_phpinputfilter')
                    || str_contains($name, 'connection_status_enum')
                    || str_contains($name, 'connection_status_cli')
                    || str_contains($name, 'parse_url_enum')
                    || str_contains($name, 'property_hook_type_enum')
                    || str_contains($name, 'exit_status_enum')
                    || str_contains($name, 'socket_type_enum')
                    || str_contains($name, 'ftp_ssl_connect')
                    || str_contains($name, 'ftp_connect')
                    || str_contains($name, 'ftp_fget')
                    || str_contains($name, 'ftp_connection_class'))
                && !str_contains($name, 'builtin_stub_enums_phantom')) {
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
                && !str_contains($name, 'array_any_all_key_phantom')) {
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
            // isSensitive (#22899 / #7072) — same 8.4 gate as isSensitiveParameter; exclude *_parameter* names.
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
            // Functional mb_str_pad_*_forward* cases set PROFILE via --ENV--; always include (#22373).
            if (!CompilerVersion::supportsMbStrPad()
                && str_contains($name, 'mb_str_pad')
                && !str_contains($name, 'mb_str_pad_phantom')
                && !str_contains($name, 'forward')) {
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
            // Functional reflection_constant_forward_profile* cases set PROFILE via --ENV--; always
            // include (#16837, #25504). Do not gate on advertisesReflectionConstantClass() in the
            // parent — that skips the child before --ENV-- can enable registration.
            if (!CompilerVersion::advertisesReflectionConstantClass()
                && str_contains($name, 'reflection_oop')) {
                continue;
            }
            if (CompilerVersion::advertisesReflectionConstantClass()
                && str_contains($name, 'reflection_constant_reference_profile')) {
                continue;
            }
            if (!CompilerVersion::supportsReflectionPropertyAccessProbes()
                && str_contains($name, 'reflection_property_isreadable_forward_profile')) {
                continue;
            }
            if (CompilerVersion::supportsReflectionPropertyAccessProbes()
                && str_contains($name, 'reflection_property_isreadable_profile')) {
                continue;
            }
            if (!CompilerVersion::supportsReflectionPropertyHookProbes()
                && str_contains($name, 'reflection_property_hook_methods_forward_profile')) {
                continue;
            }
            if (CompilerVersion::supportsReflectionPropertyHookProbes()
                && str_contains($name, 'reflection_property_hook_methods_reference_profile')) {
                continue;
            }
            if (!CompilerVersion::supportsReflectionPropertyReadableSettableType()
                && str_contains($name, 'reflection_property_readable_settable_type_forward_profile')) {
                continue;
            }
            if (CompilerVersion::supportsReflectionPropertyReadableSettableType()
                && str_contains($name, 'reflection_property_readable_settable_type_reference_profile')) {
                continue;
            }
            if (!CompilerVersion::supportsAsymmetricVisibility()
                && str_contains($name, 'reflection_property_asymmetric_probes_forward_profile')) {
                continue;
            }
            if (CompilerVersion::supportsAsymmetricVisibility()
                && str_contains($name, 'reflection_property_asymmetric_probes_profile')) {
                continue;
            }
            if (!CompilerVersion::supportsReflectionPropertyIsDynamic()
                && str_contains($name, 'reflection_property_isdynamic_forward_profile')) {
                continue;
            }
            if (CompilerVersion::supportsReflectionPropertyIsDynamic()
                && str_contains($name, 'reflection_property_isdynamic_profile')) {
                continue;
            }
            if (!CompilerVersion::supportsReflectionEnumUnitCaseIsDeprecated()
                && str_contains($name, 'reflection_enum_unit_case_is_deprecated_forward_profile')) {
                continue;
            }
            if (!CompilerVersion::supportsReflectionClassConstantIsDeprecated()
                && str_contains($name, 'reflection_class_constant_is_deprecated_forward_profile')) {
                continue;
            }
            if (CompilerVersion::supportsReflectionClassConstantIsDeprecated()
                && str_contains($name, 'reflection_class_constant_is_deprecated_profile')) {
                continue;
            }
            if (!CompilerVersion::supportsReflectionEnumFromName()
                && str_contains($name, 'reflection_enum_from_name_forward_profile')) {
                continue;
            }
            if (CompilerVersion::supportsReflectionEnumFromName()
                && str_contains($name, 'reflection_enum_from_name_profile')) {
                continue;
            }
            if (!CompilerVersion::supportsReflectionEnumUnitCaseIsDeprecated()
                && str_contains($name, 'reflection_enum_unit_case_is_deprecated_method_exists_guard_forward_profile')) {
                continue;
            }
            if (CompilerVersion::supportsReflectionEnumUnitCaseIsDeprecated()
                && str_contains($name, 'reflection_enum_unit_case_is_deprecated_profile')) {
                continue;
            }
            if (!CompilerVersion::supportsReflectionEnumCaseIsBacked()
                && str_contains($name, 'reflection_enum_case_is_backed_forward_profile')) {
                continue;
            }
            if (CompilerVersion::supportsReflectionEnumCaseIsBacked()
                && str_contains($name, 'reflection_enum_case_is_backed_profile')) {
                continue;
            }
            if (!CompilerVersion::supportsReflectionFunctionIsDeprecated()
                && str_contains($name, 'reflection_function_is_deprecated_forward_profile')) {
                continue;
            }
            if (CompilerVersion::supportsReflectionFunctionIsDeprecated()
                && str_contains($name, 'reflection_function_is_deprecated_profile')) {
                continue;
            }
            if (!CompilerVersion::supportsForwardProfileCreditsModuleAuthors()
                && str_contains($name, 'phpcredits_dom_authors_forward_profile')) {
                continue;
            }
            if (CompilerVersion::supportsForwardProfileCreditsModuleAuthors()
                && str_contains($name, 'phpcredits_dom_authors_reference_profile')) {
                continue;
            }
            if (!CompilerVersion::supportsReflectionCreateFromFactories()
                && (str_contains($name, 'reflection_create_from_callable_forward_profile')
                    || str_contains($name, 'reflection_function_create_from_forward_profile'))) {
                continue;
            }
            if (CompilerVersion::supportsReflectionCreateFromFactories()
                && str_contains($name, 'reflection_create_from_callable_profile')) {
                continue;
            }
            // Functional mb_trim_*_forward* cases set PROFILE via --ENV--; always include (#24176).
            if (!CompilerVersion::supportsMbTrimFunctions()
                && str_contains($name, 'mb_trim')
                && !str_contains($name, 'mb_trim_phantom')
                && !str_contains($name, 'forward')) {
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
                && !str_contains($name, 'grapheme_levenshtein_forward')) {
                continue;
            }
            if (CompilerVersion::supportsGraphemeLevenshtein()
                && str_contains($name, 'grapheme_levenshtein_phantom')) {
                continue;
            }
            // convert_cyr_string / money_format removed in php-src 8.0 (#21481): functional cases use
            // PROFILE=7.4 via --ENV--; phantom_* cases assert absence on 8.2/8.4 — always include
            // (do not gate on supportsConvertCyrString()/supportsMoneyFormat()).
            if (!CompilerVersion::supportsStrxfrm()
                && str_contains($name, 'strxfrm')) {
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
            if (!CompilerVersion::supportsXmlrpc()
                && str_contains($name, 'xmlrpc')
                && !str_contains($name, 'extension_loaded_xmlrpc')) {
                continue;
            }
            if (!CompilerVersion::supportsWddx()
                && str_contains($name, 'wddx')
                && !str_contains($name, 'extension_loaded_wddx')) {
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
            // redis_*_forward84 sets PROFILE via --ENV--; always include (#28094).
            if (!CompilerVersion::supportsRedis()
                && str_contains($name, 'redis')
                && !str_contains($name, 'redis_phantom')
                && !str_contains($name, 'forward84')) {
                continue;
            }
            if (CompilerVersion::supportsRedis()
                && str_contains($name, 'redis_phantom')) {
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
            // snmp_* PROFILE=8.4 via --ENV--; always include those cases.
            // Other snmp_* need forward profile.
            if (!CompilerVersion::supportsSnmp()
                && str_contains($name, 'snmp')
                && !str_contains($name, 'snmp_phantom')
                && !str_contains($name, 'snmp_exists')
                && !str_contains($name, 'snmp_set_getnext_realwalk')
                && !str_contains($name, 'snmp2_snmp3_helpers')) {
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
            if (!CompilerVersion::supportsGetHandlerIntrospection()
                && (str_contains($name, 'get_error_handler')
                    || str_contains($name, 'get_exception_handler'))
                && !str_contains($name, 'get_error_handler_phantom')
                && !str_contains($name, 'get_error_handler_forward_85')
                && !str_contains($name, 'get_error_handler_forward85')) {
                continue;
            }
            if (CompilerVersion::supportsGetHandlerIntrospection()
                && str_contains($name, 'get_error_handler_phantom')) {
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
                && (str_contains($name, 'readonly_builtin')
                    || preg_match('#(?:^|/)readonly_function$#', $name)
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
                && (str_contains($name, 'readonly_function/')
                    || str_contains($name, 'readonly_function_decl')
                    || str_contains($name, 'readonly_function_reject')
                    || str_contains($name, 'readonly_function_mutable_capture'))
                && (str_contains($name, 'reference_profile')
                    || str_contains($name, '_decl')
                    || str_contains($name, '_reject')
                    || str_contains($name, 'mutable_capture'))) {
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
            // lazy_object_factories_phantom always runs — free createLazy* never advertised (#28414).
            if (!CompilerVersion::supportsLazyObjectFactories()
                && (str_contains($name, 'create_lazy_ghost')
                    || str_contains($name, 'create_lazy_proxy')
                    || str_contains($name, 'lazy_ghost_create')
                    || str_contains($name, 'lazy_ghost_trait')
                    || str_contains($name, 'class_has_lazy_object_initializer')
                    || str_contains($name, 'class_has_lazy_object_uninitializer')
                    || str_contains($name, 'is_uninitialized_lazy_object')
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
            // Functional typed_class_const_*_forward* cases set PROFILE via --ENV--; always include (#23757).
            if (!CompilerVersion::supportsTypedClassConstants()
                && (str_contains($name, 'typed_class_const')
                    || str_contains($name, 'typed_enum_class_const')
                    || str_contains($name, 'enum_typed_class_const')
                    || str_contains($name, 'match_typed_class_const')
                    || str_contains($name, 'reflection_class_constant_get_type'))
                && !str_contains($name, 'typed_class_const_reject')
                && !str_contains($name, 'typed_class_const_reference_profile')
                && !str_contains($name, 'forward')) {
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
                // PROFILE-env SKIPIF (not supportsAsymmetricVisibility) — must stay runnable if the
                // gate wrongly returns true on the reference profile (#24819).
                && !str_contains($name, 'asymmetric_visibility_default_profile')
                && !str_contains($name, 'asymmetric_visibility_forward_84')
                && !str_contains($name, 'static_asymmetric_visibility_forward_85')
                && !str_contains($name, 'static_asymmetric_visibility_reject')
                && !str_contains($name, 'asymmetric_probes_profile')) {
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
            if (!CompilerVersion::supportsPropertyHooks()
                && str_contains($name, 'asymmetric_visibility_set_modifier')) {
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
            if (!CompilerVersion::supportsCloneWithSyntax()
                && str_contains($name, 'clone_with')
                && !str_contains($name, 'clone_with_reference_profile')
                && !str_contains($name, 'clone_with_forward_profile')) {
                continue;
            }
            // 8.4-target reject gate; skipped when try/catch/else enabled (#15817, #19128).
            if (CompilerVersion::supportsTryCatchElse()
                && str_contains($name, 'try_catch_else_reference_profile')) {
                continue;
            }
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
            // 8.4-target reject gate; skipped when list spread assign enabled (#17182).
            if (CompilerVersion::supportsListDestructuringSpreadAssign()
                && str_contains($name, 'list_destructuring_spread_reference_profile')) {
                continue;
            }
            if (!CompilerVersion::supportsListDestructuringSpreadAssign()
                && (str_contains($name, 'list_destructuring_spread')
                    || str_contains($name, 'list_destructuring_keyed_spread'))
                && !str_contains($name, 'reference_profile')
                && !str_contains($name, 'forward_profile')
                && !str_contains($name, 'lone_list_spread_assign_fatal')) {
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
            if (str_contains(strtolower($case[0]), 'splobjectstorage')) {
                continue;
            }
            if (str_contains(strtolower($case[0]), 'spl_autoload_register_jit')) {
                continue;
            }
            if (str_contains($name, 'setcookie_jit') || str_contains($name, 'setrawcookie_jit')) {
                continue;
            }
            if (str_contains($name, 'dynamic_property_deprecation')) {
                continue;
            }
            // Native preg stub error codes (JIT/AOT); VM uses host PCRE (issue #1181, #3110).
            if (str_contains(strtolower($case[0]), 'preg_last_error') && str_contains(strtolower($case[0]), 'jit')) {
                continue;
            }
            // AOT-only compliance fixtures — guarded by AotTest / TryCatchElseAotCompileTest (#19148).
            if (str_ends_with($name, '_aot')) {
                continue;
            }
            yield $name => $case;
        }
    }

    public function setUp(): void {
        $this->BIN = realpath(__DIR__ . '/../../bin/vm.php');
    }

}