<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\CompilerVersion;

/**
 * function_exists() / extension_loaded() advertisement vs forward-profile callability (#16086).
 *
 * Forward profile ({@code PHP_COMPILER_PROFILE=8.4}) may register builtins for direct calls while
 * introspection matches the Zend 8.2 reference harness until {@see CompilerVersion::VERSION} is stable 8.4+.
 * php-src: ext/standard/basic_functions.c — function_exists / get_internal_function
 */
final class BuiltinIntrospectionPolicy
{
    public static function functionIsAdvertised(string $functionName): bool
    {
        $lc = strtolower($functionName);
        if (\in_array($lc, ['fpow', 'fmin', 'fmax'], true)) {
            return CompilerVersion::advertisesFpow();
        }
        if ('nextafter' === $lc) {
            return CompilerVersion::advertisesNextafter();
        }
        if ('mb_str_pad' === $lc) {
            return CompilerVersion::advertisesMbStrPad();
        }
        if (\in_array($lc, ['str_increment', 'str_decrement'], true)) {
            return CompilerVersion::advertisesStrIncrement();
        }
        if ('zend_thread_id' === $lc) {
            return CompilerVersion::advertisesZendThreadId();
        }
        if ('readonly' === $lc) {
            return CompilerVersion::advertisesReadonlyBuiltin();
        }
        if (str_starts_with($lc, 'bc')) {
            return CompilerVersion::advertisesBcmath();
        }
        if (\in_array($lc, [
            'http_get_last_response_headers',
            'get_last_response_headers',
            'http_clear_last_response_headers',
        ], true)) {
            return CompilerVersion::advertisesHttpLastResponseHeaders();
        }
        if ('stream_context_set_options' === $lc) {
            return CompilerVersion::advertisesStreamContextSetOptions();
        }
        if ('grapheme_str_contains' === $lc) {
            return CompilerVersion::advertisesGraphemeStrContains();
        }
        if ('grapheme_strimwidth' === $lc) {
            return CompilerVersion::advertisesGraphemeStrimwidth();
        }
        if (\in_array($lc, ['class_has_method', 'class_has_property', 'class_has_constant'], true)) {
            return CompilerVersion::supportsClassHasFunctions();
        }

        return true;
    }

    public static function extensionIsAdvertised(string $extension): bool
    {
        if ('bcmath' === strtolower($extension)) {
            return CompilerVersion::advertisesBcmath();
        }

        return true;
    }
}
