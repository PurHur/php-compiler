<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\CompilerVersion;
use PHPCompiler\ext\curl\CurlExtensionPolicy;
use PHPCompiler\ext\intl\IntlExtensionPolicy;

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
        if ('json_validate' === $lc) {
            return CompilerVersion::advertisesJsonValidate();
        }
        if (\in_array($lc, [
            'array_any',
            'array_all',
            'array_any_key',
            'array_all_key',
            'array_find',
            'array_find_key',
            'array_first',
            'array_last',
        ], true)) {
            return CompilerVersion::advertisesPhp84ArraySearchFunctions();
        }
        if ('generator_to_array' === $lc) {
            return CompilerVersion::advertisesGeneratorToArray();
        }
        if ('class_uses_recursive' === $lc) {
            return CompilerVersion::advertisesClassUsesRecursive();
        }
        if ('stream_supports' === $lc) {
            return CompilerVersion::advertisesStreamSupports();
        }
        if ('nextafter' === $lc) {
            return CompilerVersion::advertisesNextafter();
        }
        if ('mb_str_pad' === $lc) {
            return CompilerVersion::advertisesMbStrPad();
        }
        if (\in_array($lc, ['mb_trim', 'mb_ltrim', 'mb_rtrim'], true)) {
            return CompilerVersion::advertisesMbTrimFunctions();
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
        if ('bcround' === $lc) {
            return CompilerVersion::advertisesBcround();
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
        if ('request_parse_body' === $lc) {
            return CompilerVersion::advertisesRequestParseBody();
        }
        if ('stream_context_set_options' === $lc) {
            return CompilerVersion::advertisesStreamContextSetOptions();
        }
        if ('grapheme_str_contains' === $lc || 'grapheme_strimwidth' === $lc) {
            return IntlExtensionPolicy::advertisesBuiltins();
        }
        if (\in_array($lc, [
            'grapheme_strlen',
            'grapheme_substr',
            'grapheme_strpos',
            'grapheme_extract',
            'grapheme_str_split',
            'grapheme_stripos',
            'grapheme_stristr',
            'grapheme_strrpos',
            'intl_get_error_code',
            'intl_get_error_message',
            'intl_is_failure',
        ], true)) {
            return IntlExtensionPolicy::advertisesBuiltins();
        }
        if (\in_array($lc, ['curl_escape', 'curl_unescape'], true)) {
            return CurlExtensionPolicy::advertisesExtension();
        }
        if ('fastcgi_finish_request' === $lc) {
            return VmFastCgi::registersFinishRequestFunction();
        }
        if (\in_array($lc, ['class_has_method', 'class_has_property', 'class_has_constant'], true)) {
            return CompilerVersion::supportsClassHasFunctions();
        }

        return true;
    }

    public static function extensionIsAdvertised(string $extension): bool
    {
        $ext = strtolower($extension);
        if ('bcmath' === $ext) {
            return CompilerVersion::advertisesBcmath();
        }
        if ('curl' === $ext) {
            return \PHPCompiler\ext\curl\CurlExtensionPolicy::advertisesExtension();
        }
        if ('openssl' === $ext) {
            return \PHPCompiler\ext\openssl\OpensslExtensionPolicy::advertisesExtension();
        }

        return true;
    }
}
