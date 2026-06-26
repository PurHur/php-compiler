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
            if (!CompilerVersion::supportsZendThreadId()
                && str_contains($name, 'zend_thread_id')
                && !str_contains($name, 'zend_thread_id_phantom')) {
                continue;
            }
            if (!CompilerVersion::supportsJsonValidate()
                && str_contains($name, 'json_validate')
                && !str_contains($name, 'json_validate_phantom')) {
                continue;
            }
            if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::advertisesBuiltins()
                && str_contains($name, 'grapheme_')
                && !str_contains($name, 'grapheme_phantom')) {
                continue;
            }
            if (!CompilerVersion::supportsStreamSupports()
                && (str_contains($name, 'stream_supports.phpt')
                    || str_contains($name, 'stream_support_constants')
                    || str_contains($name, 'stream_meta_seekable'))
                && !str_contains($name, 'stream_supports_phantom')) {
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