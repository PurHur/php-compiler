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
            if (!CompilerVersion::supportsStrIncrement()
                && (str_contains($name, 'str_increment') || str_contains($name, 'str_decrement'))) {
                continue;
            }
            if (!CompilerVersion::supportsFpow()
                && (str_contains($name, 'fpow') || str_contains($name, 'fmin') || str_contains($name, 'fmax'))
                && !str_contains($name, 'php84_math_string_builtins_phantom')) {
                continue;
            }
            if (!CompilerVersion::supportsGetDeclaredExcludeDeprecated()
                && str_contains($name, 'get_declared_exclude_deprecated')
                && !str_contains($name, 'get_declared_exclude_deprecated_reference_profile')) {
                continue;
            }
            if (CompilerVersion::supportsGetDeclaredExcludeDeprecated()
                && str_contains($name, 'get_declared_exclude_deprecated_reference_profile')) {
                continue;
            }
            if ((CompilerVersion::supportsStrIncrement() || CompilerVersion::supportsFpow())
                && str_contains($name, 'php84_math_string_builtins_phantom')) {
                continue;
            }
            if (!CompilerVersion::supportsZendThreadId()
                && str_contains($name, 'zend_thread_id')
                && !str_contains($name, 'zend_thread_id_phantom')) {
                continue;
            }
            if (CompilerVersion::supportsZendThreadId()
                && str_contains($name, 'zend_thread_id_phantom')) {
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
            if (!CompilerVersion::supportsJsonValidate()
                && str_contains($name, 'json_validate')
                && !str_contains($name, 'json_validate_phantom')) {
                continue;
            }
            if (CompilerVersion::supportsJsonValidate()
                && str_contains($name, 'json_validate_phantom')) {
                continue;
            }
            if (!CompilerVersion::supportsPhp84ArraySearchFunctions()
                && (str_contains($name, 'array_find')
                    || str_contains($name, 'array_any')
                    || str_contains($name, 'array_all')
                    || (str_contains($name, 'array_first') && !str_contains($name, 'array_first_key'))
                    || str_contains($name, 'array_last'))) {
                continue;
            }
            if (!CompilerVersion::supportsMbStrPad()
                && str_contains($name, 'mb_str_pad')
                && !str_contains($name, 'mb_str_pad_phantom')) {
                continue;
            }
            if (CompilerVersion::supportsMbStrPad()
                && str_contains($name, 'mb_str_pad_phantom')) {
                continue;
            }
            if (!CompilerVersion::supportsConvertCyrString()
                && str_contains($name, 'convert_cyr_string')) {
                continue;
            }
            if (!CompilerVersion::supportsStrxfrm()
                && str_contains($name, 'strxfrm')) {
                continue;
            }
            if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::advertisesBuiltins()
                && str_contains($name, 'grapheme_')
                && !str_contains($name, 'grapheme_phantom')) {
                continue;
            }
            if (!CompilerVersion::supportsBz2()
                && (str_contains($name, 'bz2') || str_contains($name, 'bzcompress'))
                && !str_contains($name, 'bz2_phantom')) {
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
            if (!CompilerVersion::supportsStreamSupports()
                && (str_contains($name, 'stream_supports.phpt')
                    || str_contains($name, 'stream_support_constants')
                    || str_contains($name, 'stream_meta_seekable'))
                && !str_contains($name, 'stream_supports_phantom')) {
                continue;
            }
            if (CompilerVersion::supportsStreamSupports()
                && str_contains($name, 'stream_supports_phantom')) {
                continue;
            }
            if (!CompilerVersion::advertisesOverrideAttributeClass()
                && str_contains($name, 'override_class_exists')) {
                continue;
            }
            // 8.2 reference profile: #[\Override] parent validation off (#11559, #12201).
            if (!CompilerVersion::supportsOverrideAttribute()
                && (str_contains($name, 'override_attribute_invalid')
                    || str_contains($name, 'override_attribute_fatal')
                    || str_contains($name, 'override_attribute_invalid_target')
                    || str_contains($name, 'override_signature_mismatch')
                    || str_contains($name, 'override_private_parent')
                    || str_contains($name, 'override_class_constant_invalid')
                    || str_contains($name, 'override_property_invalid'))) {
                continue;
            }
            if (CompilerVersion::supportsOverrideAttribute()
                && (str_contains($name, 'override_attribute_82_no_validate')
                    || str_contains($name, 'override_missing_parent_reference_profile'))) {
                continue;
            }
            if (!CompilerVersion::advertisesDeprecatedAttributeClass()
                && str_contains($name, 'deprecated_attribute_class')) {
                continue;
            }
            if (!CompilerVersion::advertisesNoDiscardAttributeClass()
                && str_contains($name, 'nodiscard_class_exists')) {
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
            // 8.4-target reject gate; skipped when exit()/die() function form enabled (#12413, #12435).
            if (CompilerVersion::supportsExitFunctionForm()
                && (str_contains($name, 'exit_named_status_reference_profile')
                    || str_contains($name, 'die_named_message_reference_profile'))) {
                continue;
            }
            if (!CompilerVersion::supportsExitFunctionForm()
                && (str_contains($name, 'exit_function_php84')
                    || str_contains($name, 'exit_function_strict_types')
                    || str_contains($name, 'exit_die_two_args')
                    || str_contains($name, 'exit_type_error')
                    || (str_contains($name, 'die_named_message')
                        && !str_contains($name, 'die_named_message_reference_profile')))) {
                continue;
            }
            // 8.4-target reject gate; skipped when asymmetric visibility enabled (#12508).
            if (CompilerVersion::supportsAsymmetricVisibility()
                && str_contains($name, 'private_set_reference_profile')) {
                continue;
            }
            if (!CompilerVersion::supportsAsymmetricVisibility()
                && (str_contains($name, 'asymmetric')
                    || str_contains($name, 'property_hook_private_set')
                    || str_contains($name, 'reflection_property_asymmetric')
                    || str_contains($name, 'promoted_private_set'))
                && !str_contains($name, 'private_set_reference_profile')) {
                continue;
            }
            // 8.4-target reject gate; skipped when property hooks enabled (#12574).
            if (CompilerVersion::supportsPropertyHooks()
                && str_contains($name, 'property_hook_reference_profile')) {
                continue;
            }
            if (!CompilerVersion::supportsPropertyHooks()
                && CompilerVersion::complianceCaseUsesPropertyHooks($name)) {
                continue;
            }
            if (!CompilerVersion::supportsClassHasFunctions()
                && str_contains($name, 'class_has_')) {
                continue;
            }
            if (!CompilerVersion::supportsStrPadded()
                && str_contains($name, 'str_padded')) {
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
            yield $name => $case;
        }
    }

    public function setUp(): void {
        $this->BIN = realpath(__DIR__ . '/../../bin/vm.php');
    }

}