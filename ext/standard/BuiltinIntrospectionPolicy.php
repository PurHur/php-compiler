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
        if ('fpow' === $lc) {
            return CompilerVersion::advertisesFpow();
        }
        // fadd/fsub/fmul/fmax/fmin — absent from php-src (#28565).
        if (\in_array($lc, ['fmin', 'fmax', 'fadd', 'fsub', 'fmul'], true)) {
            return CompilerVersion::advertisesIeeeFloatOpPhantoms();
        }
        if ('json_validate' === $lc) {
            return CompilerVersion::advertisesJsonValidate();
        }
        if ('socket_atmark' === $lc) {
            return CompilerVersion::advertisesSocketAtmark();
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
            'array_find',
            'array_find_key',
        ], true)) {
            return CompilerVersion::advertisesPhp84ArraySearchFunctions();
        }
        // PHP 8.4-only pcntl surface — absent on Zend 8.2 stubs (#26742).
        if (\in_array($lc, [
            'pcntl_getcpu',
            'pcntl_getcpuaffinity',
            'pcntl_setcpuaffinity',
            'pcntl_setns',
            'pcntl_waitid',
        ], true)) {
            return CompilerVersion::advertisesPhp84PcntlApis();
        }
        if (\in_array($lc, ['array_first', 'array_last'], true)) {
            return CompilerVersion::advertisesPhp85ArrayFirstLast();
        }
        if ('generator_to_array' === $lc) {
            return CompilerVersion::advertisesGeneratorToArray();
        }
        if ('get_declared_attributes' === $lc) {
            return CompilerVersion::advertisesGetDeclaredAttributes();
        }
        // get_declared_functions / get_declared_variables — phantoms vs php-src (#24223); never advertise.
        if (\in_array($lc, ['get_declared_functions', 'get_declared_variables'], true)) {
            return false;
        }
        if (\in_array($lc, ['attribute_exists', 'class_meth_exists', 'unitenum_exists'], true)) {
            return CompilerVersion::advertisesPhp84ReflectionProbeBuiltins();
        }
        if ('isanonymousclass' === $lc) {
            return CompilerVersion::advertisesIsAnonymousClass();
        }
        if ('class_uses_recursive' === $lc) {
            return CompilerVersion::advertisesClassUsesRecursive();
        }
        if ('stream_supports' === $lc) {
            return CompilerVersion::advertisesStreamSupports();
        }
        if (\in_array($lc, ['stream_last_errors', 'stream_clear_errors'], true)) {
            return CompilerVersion::advertisesStreamErrorApi();
        }
        if ('stream_socket_get_crypto_status' === $lc) {
            return CompilerVersion::advertisesStreamSocketGetCryptoStatus();
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
        if ('mb_ucwords' === $lc) {
            return CompilerVersion::advertisesMbUcwords();
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
            'grapheme_strripos',
            'intl_get_error_code',
            'intl_get_error_message',
            'intl_is_failure',
            'intl_error_name',
        ], true)) {
            return IntlExtensionPolicy::advertisesBuiltins();
        }
        if (\in_array($lc, [
            'normalizer_normalize',
            'normalizer_is_normalized',
            'normalizer_get_raw_decomposition',
        ], true)) {
            return IntlExtensionPolicy::advertisesNormalizer();
        }
        if (\in_array($lc, ['idn_to_ascii', 'idn_to_utf8'], true)) {
            return IntlExtensionPolicy::advertisesIdn();
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
        if (\in_array($lc, ['yaml_parse', 'yaml_parse_file', 'yaml_parse_url', 'yaml_emit', 'yaml_emit_file'], true)) {
            return \PHPCompiler\ext\yaml\YamlExtensionPolicy::advertisesExtension();
        }
        if (\in_array($lc, [
            'ldap_escape',
            'ldap_dn2ufn',
            'ldap_explode_dn',
            'ldap_connect',
            'ldap_bind',
            'ldap_unbind',
            'ldap_close',
            'ldap_errno',
            'ldap_error',
            'ldap_err2str',
            'ldap_set_option',
            'ldap_search',
            'ldap_list',
            'ldap_read',
            'ldap_count_entries',
            'ldap_get_entries',
            'ldap_first_entry',
            'ldap_next_entry',
            'ldap_free_result',
            'ldap_exop',
            'ldap_exop_sync',
            'ldap_parse_exop',
            'ldap_exop_refresh',
            'ldap_connect_wallet',
        ], true)) {
            if ('ldap_connect_wallet' === $lc) {
                return \PHPCompiler\ext\ldap\LdapExtensionPolicy::advertisesWalletConnect();
            }

            return \PHPCompiler\ext\ldap\LdapExtensionPolicy::advertisesBuiltins();
        }
        if ('fastcgi_finish_request' === $lc) {
            return VmFastCgi::registersFinishRequestFunction();
        }
        if (\in_array($lc, ['is_soap_fault', 'use_soap_error_handler'], true)) {
            return \PHPCompiler\ext\soap\SoapExtensionPolicy::advertisesExtension();
        }
        if (str_starts_with($lc, 'tidy_')) {
            return \PHPCompiler\ext\tidy\TidyExtensionPolicy::advertisesExtension();
        }
        if (str_starts_with($lc, 'gmp_')) {
            return \PHPCompiler\ext\gmp\GmpExtensionPolicy::advertisesExtension();
        }
        if (str_starts_with($lc, 'apcu_')) {
            return \PHPCompiler\ext\apcu\ApcuExtensionPolicy::advertisesExtension();
        }
        if (str_starts_with($lc, 'pspell_')) {
            return \PHPCompiler\ext\pspell\PspellExtensionPolicy::advertisesExtension();
        }
        if (str_starts_with($lc, 'enchant_')) {
            return \PHPCompiler\ext\enchant\EnchantExtensionPolicy::advertisesExtension();
        }
        if (str_starts_with($lc, 'uuid_')) {
            return \PHPCompiler\ext\uuid\UuidExtensionPolicy::advertisesExtension();
        }
        if (str_starts_with($lc, 'zmq_')) {
            return \PHPCompiler\ext\zmq\ZmqExtensionPolicy::advertisesExtension();
        }
        if (str_starts_with($lc, 'zstd_')) {
            return \PHPCompiler\ext\zstd\ZstdExtensionPolicy::advertisesExtension();
        }
        if (str_starts_with($lc, 'lzf_')) {
            return \PHPCompiler\ext\lzf\LzfExtensionPolicy::advertisesExtension();
        }
        if (str_starts_with($lc, 'lz4_')) {
            return \PHPCompiler\ext\lz4\Lz4ExtensionPolicy::advertisesExtension();
        }
        if (str_starts_with($lc, 'gnupg_')) {
            return \PHPCompiler\ext\gnupg\GnupgExtensionPolicy::advertisesExtension();
        }
        if (str_starts_with($lc, 'mailparse_')) {
            return \PHPCompiler\ext\mailparse\MailparseExtensionPolicy::advertisesExtension();
        }
        if (str_starts_with($lc, 'dba_')) {
            return \PHPCompiler\ext\dba\DbaExtensionPolicy::advertisesExtension();
        }
        if (str_starts_with($lc, 'odbc_')) {
            return \PHPCompiler\ext\odbc\OdbcExtensionPolicy::advertisesExtension();
        }
        if (str_starts_with($lc, 'mysqli_')) {
            return \PHPCompiler\ext\mysqli\MysqliExtensionPolicy::advertisesExtension();
        }
        if (str_starts_with($lc, 'stats_')) {
            return \PHPCompiler\ext\stats\StatsExtensionPolicy::advertisesBuiltins();
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
            return \PHPCompiler\ext\sqlite3\Sqlite3ExtensionPolicy::advertisesExtensionLoaded();
        }
        if ('brotli' === $ext) {
            return \PHPCompiler\ext\brotli\BrotliExtensionPolicy::advertisesExtension();
        }
        if ('msgpack' === $ext) {
            return \PHPCompiler\ext\msgpack\MsgpackExtensionPolicy::advertisesExtension();
        }
        if ('simdjson' === $ext) {
            return \PHPCompiler\ext\simdjson\SimdjsonExtensionPolicy::advertisesExtension();
        }
        if ('xmlrpc' === $ext) {
            return \PHPCompiler\ext\xmlrpc\XmlrpcExtensionPolicy::advertisesExtension();
        }
        if ('wddx' === $ext) {
            return \PHPCompiler\ext\wddx\WddxExtensionPolicy::advertisesExtension();
        }
        if ('yaml' === $ext) {
            return \PHPCompiler\ext\yaml\YamlExtensionPolicy::advertisesExtension();
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
        if ('phar' === $ext) {
            return \PHPCompiler\ext\phar\PharExtensionPolicy::advertisesExtension();
        }
        if ('ftp' === $ext) {
            return \PHPCompiler\ext\ftp\FtpExtensionPolicy::advertisesExtension();
        }
        if ('gnupg' === $ext) {
            return \PHPCompiler\ext\gnupg\GnupgExtensionPolicy::advertisesExtension();
        }
        if ('mailparse' === $ext) {
            return \PHPCompiler\ext\mailparse\MailparseExtensionPolicy::advertisesExtension();
        }
        if ('dba' === $ext) {
            return \PHPCompiler\ext\dba\DbaExtensionPolicy::advertisesExtension();
        }
        if ('odbc' === $ext) {
            return \PHPCompiler\ext\odbc\OdbcExtensionPolicy::advertisesExtension();
        }
        if ('mysqli' === $ext) {
            return \PHPCompiler\ext\mysqli\MysqliExtensionPolicy::advertisesExtension();
        }
        if ('soap' === $ext) {
            return \PHPCompiler\ext\soap\SoapExtensionPolicy::advertisesExtension();
        }
        if ('tidy' === $ext) {
            return \PHPCompiler\ext\tidy\TidyExtensionPolicy::advertisesExtension();
        }
        if ('gmp' === $ext) {
            return \PHPCompiler\ext\gmp\GmpExtensionPolicy::advertisesExtension();
        }
        if ('apcu' === $ext) {
            return \PHPCompiler\ext\apcu\ApcuExtensionPolicy::advertisesExtension();
        }
        if ('pspell' === $ext) {
            return \PHPCompiler\ext\pspell\PspellExtensionPolicy::advertisesExtension();
        }
        if ('enchant' === $ext) {
            return \PHPCompiler\ext\enchant\EnchantExtensionPolicy::advertisesExtension();
        }
        if ('uuid' === $ext) {
            return \PHPCompiler\ext\uuid\UuidExtensionPolicy::advertisesExtension();
        }
        if ('zmq' === $ext) {
            return \PHPCompiler\ext\zmq\ZmqExtensionPolicy::advertisesExtension();
        }
        if ('zstd' === $ext) {
            return \PHPCompiler\ext\zstd\ZstdExtensionPolicy::advertisesExtension();
        }
        if ('lzf' === $ext) {
            return \PHPCompiler\ext\lzf\LzfExtensionPolicy::advertisesExtension();
        }
        if ('lz4' === $ext) {
            return \PHPCompiler\ext\lz4\Lz4ExtensionPolicy::advertisesExtension();
        }
        if ('ds' === $ext) {
            return \PHPCompiler\ext\ds\DsExtensionPolicy::advertisesExtension();
        }
        if ('stats' === $ext) {
            return \PHPCompiler\ext\stats\StatsExtensionPolicy::advertisesExtension();
        }

        return true;
    }
}
