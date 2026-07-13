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
        if (\in_array($lc, ['fpow', 'fmin', 'fmax', 'fadd', 'fsub', 'fmul'], true)) {
            return CompilerVersion::advertisesFpow();
        }
        if ('json_validate' === $lc) {
            return CompilerVersion::advertisesJsonValidate();
        }
        if ('crc32c' === $lc) {
            return CompilerVersion::advertisesCrc32c();
        }
        if ('disktotalspace' === $lc) {
            return CompilerVersion::advertisesDisktotalspace();
        }
        if ('hebrevc' === $lc) {
            return CompilerVersion::advertisesHebrevc();
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
        if (\in_array($lc, ['attribute_exists', 'class_meth_exists', 'unitenum_exists'], true)) {
            return CompilerVersion::advertisesPhp84ReflectionProbeBuiltins();
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
        if (\in_array($lc, ['mb_ucfirst', 'mb_lcfirst'], true)) {
            return CompilerVersion::advertisesMbUcfirstLcfirst();
        }
        if (\in_array($lc, ['str_increment', 'str_decrement'], true)) {
            return CompilerVersion::advertisesStrIncrement();
        }
        if ('get_object_id' === $lc) {
            return CompilerVersion::advertisesGetObjectId();
        }
        if ('clamp' === $lc) {
            return CompilerVersion::advertisesClamp();
        }
        if ('zend_thread_id' === $lc) {
            return CompilerVersion::advertisesZendThreadId();
        }
        if ('readonly' === $lc) {
            return CompilerVersion::advertisesReadonlyBuiltin();
        }
        if (\in_array($lc, ['bcceil', 'bcfloor', 'bcround'], true)) {
            return CompilerVersion::advertisesBcround();
        }
        if (str_starts_with($lc, 'bc')) {
            return CompilerVersion::advertisesBcmath();
        }
        if (\in_array($lc, ['get_error_handler', 'get_exception_handler'], true)) {
            return CompilerVersion::advertisesGetHandlerIntrospection();
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
        if ('grapheme_str_contains' === $lc) {
            return IntlExtensionPolicy::advertisesGraphemeStrContains();
        }
        if ('grapheme_strimwidth' === $lc) {
            return IntlExtensionPolicy::advertisesGraphemeStrimwidth();
        }
        if (\in_array($lc, [
            'grapheme_strlen',
            'grapheme_substr',
            'grapheme_strpos',
            'grapheme_extract',
            'grapheme_str_split',
        ], true)) {
            return IntlExtensionPolicy::advertisesGraphemeCore();
        }
        if (\in_array($lc, [
            'grapheme_stripos',
            'grapheme_stristr',
            'grapheme_strrpos',
            'intl_get_error_code',
            'intl_get_error_message',
            'intl_is_failure',
        ], true)) {
            return IntlExtensionPolicy::advertisesBuiltins();
        }
        if (\in_array($lc, [
            'locale_get_primary_language',
            'locale_get_region',
            'locale_get_script',
        ], true)) {
            return IntlExtensionPolicy::advertisesLocaleParsers();
        }
        if (\in_array($lc, ['locale_get_default', 'locale_set_default'], true)) {
            return IntlExtensionPolicy::advertisesLocale();
        }
        if (\in_array($lc, ['curl_escape', 'curl_unescape'], true)) {
            return CurlExtensionPolicy::advertisesExtension();
        }
        if (\in_array($lc, [
            'curl_version',
            'curl_strerror',
            'curl_multi_strerror',
            'curl_upkeep',
            'curl_file_create',
        ], true)) {
            return CurlExtensionPolicy::advertisesIntrospectionFunctions();
        }
        if (\in_array($lc, ['xmlrpc_encode', 'xmlrpc_decode'], true)) {
            return \PHPCompiler\ext\xmlrpc\XmlrpcExtensionPolicy::advertisesExtension();
        }
        if (\in_array($lc, ['wddx_serialize_value', 'wddx_serialize_vars', 'wddx_deserialize'], true)) {
            return \PHPCompiler\ext\wddx\WddxExtensionPolicy::advertisesExtension();
        }
        if ('ldap_escape' === $lc) {
            return \PHPCompiler\ext\ldap\LdapExtensionPolicy::advertisesBuiltins();
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
        if ('sqlite3' === $ext) {
            return \PHPCompiler\ext\sqlite3\Sqlite3ExtensionPolicy::advertisesExtension();
        }
        if ('brotli' === $ext) {
            return \PHPCompiler\ext\brotli\BrotliExtensionPolicy::advertisesExtension();
        }
        if ('msgpack' === $ext) {
            return \PHPCompiler\ext\msgpack\MsgpackExtensionPolicy::advertisesExtension();
        }
        if ('xmlrpc' === $ext) {
            return \PHPCompiler\ext\xmlrpc\XmlrpcExtensionPolicy::advertisesExtension();
        }
        if ('wddx' === $ext) {
            return \PHPCompiler\ext\wddx\WddxExtensionPolicy::advertisesExtension();
        }
        if ('uri' === $ext) {
            return \PHPCompiler\ext\uri\UriExtensionPolicy::advertisesExtension();
        }
        if ('ldap' === $ext) {
            return \PHPCompiler\ext\ldap\LdapExtensionPolicy::advertisesExtension();
        }
        if ('inotify' === $ext) {
            return \PHPCompiler\ext\inotify\InotifyExtensionPolicy::advertisesExtension();
        }
        if ('zip' === $ext) {
            return \PHPCompiler\ext\zip\ZipExtensionPolicy::advertisesExtension();
        }
        if ('xsl' === $ext) {
            return \PHPCompiler\ext\xsl\XslExtensionPolicy::advertisesExtension();
        }

        return true;
    }
}
