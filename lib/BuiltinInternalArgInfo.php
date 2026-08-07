<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPTypes\InternalArgInfo;

/**
 * php-src internal function arginfo arity (ext/* arginfo tables via ircmaxell/php-types).
 *
 * Used when {@see BuiltinParamNames} has no explicit entry (#11453, ext/reflection/php_reflection.c).
 */
final class BuiltinInternalArgInfo
{
    /**
     * php-src ZEND_TYPE_IS_TENTATIVE return labels (ext/reflection/php_reflection.c, #18226).
     *
     * Delegates to {@see BuiltinInternalTentativeReturnInfo} (Zend 8.2 snapshot).
     */
    public static function tentativeReturnTypeForClassMethod(string $class, string $method): ?string
    {
        return BuiltinInternalTentativeReturnInfo::tentativeReturnTypeLabelForClassMethod($class, $method);
    }

    /**
     * Stub return type label for an internal free function (php-types arginfo `return`).
     *
     * Empty / missing labels mean no declared return type (ext/reflection/php_reflection.c, #22068).
     */
    public static function returnTypeLabelForFunction(string $name): ?string
    {
        $lc = strtolower($name);
        $stub = self::stubReturnTypeLabelForFunction($lc);
        if (null !== $stub) {
            $stub = trim($stub);
            // Empty override clears a bogus InternalArgInfo return (stream_context_create #25508).
            return '' === $stub ? null : $stub;
        }
        $info = self::instance()->functions[$lc] ?? null;
        if (null === $info) {
            return null;
        }
        $ret = $info['return'] ?? '';
        if (!\is_string($ret)) {
            return null;
        }
        $ret = trim($ret);
        if ('' === $ret) {
            return null;
        }

        return $ret;
    }

    /**
     * php-src stub return when InternalArgInfo omits the function entirely (#25392).
     *
     * Empty string = force no declared return type (overrides a wrong InternalArgInfo label).
     */
    public static function stubReturnTypeLabelForFunction(string $callableLc): ?string
    {
        // ext/standard/basic_functions.stub.php — StreamBucket shapes on PROFILE≥8.4 (#27797)
        // ≤8.3 keeps InternalArgInfo resource/object/empty (pre-StreamBucket stubs).
        if (CompilerVersion::supportsStreamBucketClass()) {
            $bucketReturn = match ($callableLc) {
                'stream_bucket_new' => 'StreamBucket',
                'stream_bucket_make_writeable' => '?StreamBucket',
                'stream_bucket_append', 'stream_bucket_prepend' => 'void',
                default => null,
            };
            if (null !== $bucketReturn) {
                return $bucketReturn;
            }
        }

        // ext/standard/streamsfuncs.stub.php — true return on PROFILE≥8.4; ≤8.3 keeps bool (#28344)
        if ('stream_context_set_option' === $callableLc
            && version_compare(CompilerVersion::languageProfileVersion(), '8.4.0', '>=')
        ) {
            return 'true';
        }

        return match ($callableLc) {
            // ext/date/php_date.stub.php — absent from php-types InternalArgInfo (#25392)
            'date_create' => 'DateTime|false',
            'date_create_immutable' => 'DateTimeImmutable|false',
            // ext/date/php_date.stub.php — InternalArgInfo return int (missing |false) (#25440)
            'idate' => 'int|false',
            // ext/date/php_date.stub.php — InternalArgInfo return int (missing |false) (#26325)
            'strtotime', 'mktime', 'gmmktime' => 'int|false',
            // ext/date/php_date.stub.php — InternalArgInfo return string (missing |false) (#26358)
            'timezone_name_from_abbr' => 'string|false',
            // ext/standard/string.stub.php — InternalArgInfo omits |false (#25442)
            'strpos', 'stripos', 'strrpos', 'strripos' => 'int|false',
            'strstr', 'stristr' => 'string|false',
            // ext/standard/basic_functions.stub.php — InternalArgInfo return bool (missing string|) (#25472)
            'highlight_string', 'highlight_file', 'show_source' => 'string|bool',
            // ext/standard/basic_functions.stub.php — PHP 8.4; InternalArgInfo bool → true (#25453, #28239)
            'stream_context_set_options', 'stream_context_set_params' => 'true',
            // ext/standard/file.stub.php — absent from InternalArgInfo (#23406)
            'fsync', 'fdatasync' => 'bool',
            // ext/standard/basic_functions.stub.php — no return type; InternalArgInfo says array (#25508)
            'stream_context_create' => '',
            // ext/standard/streamsfuncs.stub.php — no return type; InternalArgInfo says resource (#27848)
            'stream_socket_client' => '',
            // ext/standard/proc_open.stub.php — no return type; InternalArgInfo says resource (#27847)
            'proc_open' => '',
            // ext/standard/streamsfuncs.stub.php — InternalArgInfo return int (missing |bool) (#27684)
            'stream_socket_enable_crypto' => 'int|bool',
            // Zend/zend_builtin_functions.stub.php — InternalArgInfo omits return (#25480, #28223)
            'restore_error_handler', 'restore_exception_handler' => 'true',
            // Zend/zend_builtin_functions.stub.php — InternalArgInfo omits return; PHP 8.4+: true (#28222)
            'trigger_error', 'user_error' => 'true',
            // ext/standard/basic_functions.stub.php — InternalArgInfo omits / empty (#25623)
            'preg_last_error_msg' => 'string',
            'error_clear_last' => 'void',
            // ext/standard/basic_functions.stub.php — InternalArgInfo return empty; Zend void (#27998)
            'clearstatcache' => 'void',
            // ext/pcre/php_pcre.stub.php — InternalArgInfo omits |false (#26324)
            'preg_grep' => 'array|false',
            'preg_match_all' => 'int|false',
            // ext/standard/basic_functions.stub.php — InternalArgInfo omits void (#26104)
            'memory_reset_peak_usage' => 'void',
            // ext/standard/file.stub.php — InternalArgInfo omits |false (#25509)
            'file_get_contents', 'fread', 'fgets' => 'string|false',
            'file_put_contents', 'fwrite' => 'int|false',
            // ext/standard/file.stub.php — InternalArgInfo omits |false (#25750)
            'stream_get_contents' => 'string|false',
            // ext/standard/streamsfuncs.stub.php — InternalArgInfo return int (missing |false) (#27739)
            'stream_copy_to_stream' => 'int|false',
            // ext/standard/streamsfuncs.stub.php — InternalArgInfo return int (missing |false) (#27777)
            'stream_select' => 'int|false',
            // ext/standard/file.stub.php — InternalArgInfo omits |false (#26357, re-#23921)
            'stream_get_line' => 'string|false',
            // ext/standard/file.stub.php — InternalArgInfo return int (missing |false) (#26322)
            'ftell' => 'int|false',
            // ext/standard/dir.stub.php — InternalArgInfo return string (missing |false) (#26320)
            'readdir' => 'string|false',
            // ext/standard/file.stub.php — InternalArgInfo return string (missing |false) (#26320)
            'tempnam' => 'string|false',
            // ext/standard/basic_functions.stub.php — InternalArgInfo omits |false (#26320)
            'gethostbynamel' => 'array|false',
            'sys_getloadavg' => 'array|false',
            // ext/standard/basic_functions.stub.php — InternalArgInfo return string (missing |false) (#28000)
            'gethostname' => 'string|false',
            // ext/standard/link.stub.php — InternalArgInfo return int; Zend bool (#26323)
            'symlink' => 'bool',
            // ext/standard/basic_functions.stub.php — InternalArgInfo omits return (#26058)
            'fscanf' => 'array|int|false|null',
            // ext/standard/basic_functions.stub.php — InternalArgInfo omits |false; ini_alter absent (#26465, #26187)
            'ini_set', 'ini_alter' => 'string|false',
            // ext/standard/password.stub.php — absent from InternalArgInfo (#23292)
            'password_get_info' => 'array',
            'password_needs_rehash' => 'bool',
            // ext/standard/file.stub.php — InternalArgInfo omits |false (#26185)
            'filesize', 'filemtime' => 'int|false',
            'glob', 'scandir' => 'array|false',
            'realpath' => 'string|false',
            // ext/standard/basic_functions.stub.php — InternalArgInfo omits |false (#26317)
            'getmyuid', 'getmygid', 'getmypid', 'getlastmod' => 'int|false',
            // ext/zlib/zlib.stub.php — InternalArgInfo omits |false (#25511, #26342)
            'gzencode', 'gzdecode', 'gzcompress', 'gzuncompress', 'gzdeflate', 'gzinflate' => 'string|false',
            // pecl-file_formats-lzf lzf.stub.php — InternalArgInfo return int (missing |false) (#28063)
            'lzf_optimized_for' => 'int|false',
            // ext/zlib/zlib.stub.php — InternalArgInfo return resource; Zend DeflateContext|false / InflateContext|false (#27627)
            'deflate_init' => 'DeflateContext|false',
            'inflate_init' => 'InflateContext|false',
            // ext/standard/base64.c + string.stub.php — InternalArgInfo omits |false (#25477)
            'base64_decode', 'hex2bin' => 'string|false',
            // ext/fileinfo/fileinfo.stub.php — InternalArgInfo return resource / string (missing |false) (#25471)
            'finfo_open' => 'finfo|false',
            'finfo_file', 'finfo_buffer' => 'string|false',
            // ext/simplexml/simplexml.stub.php — php-types typo simplemxml_element (#25510)
            'simplexml_load_string', 'simplexml_load_file' => 'SimpleXMLElement|false',
            // ext/dom/php_dom.stub.php — php-types typo somNode (#26464)
            'dom_import_simplexml' => 'DOMAttr|DOMElement',
            // ext/simplexml/simplexml.stub.php — php-types typo simplemxml_element (#26464)
            'simplexml_import_dom' => '?SimpleXMLElement',
            // ext/hash/hash.stub.php — missing from InternalArgInfo (#25470)
            'hash_equals' => 'bool',
            // ext/hash/hash.stub.php — return string; InternalArgInfo omits / untyped (#25469)
            'hash_pbkdf2' => 'string',
            // ext/hash/hash.stub.php — return string; InternalArgInfo omits the function (#25845)
            'hash_hkdf' => 'string',
            // ext/hash/hash.stub.php — InternalArgInfo return string (missing |false) (#28318)
            'hash_file', 'hash_hmac_file' => 'string|false',
            // ext/hash/hash.stub.php — return array; InternalArgInfo omits the function (#27942)
            'hash_hmac_algos' => 'array',
            // ext/hash/hash.stub.php — InternalArgInfo return resource; Zend HashContext (#27745)
            'hash_copy' => 'HashContext',
            // ext/sodium/sodium_*.stub.php — absent from InternalArgInfo (#24490)
            'sodium_crypto_generichash',
            'sodium_crypto_secretbox',
            'sodium_crypto_box',
            'sodium_crypto_sign',
            'sodium_crypto_pwhash_str' => 'string',
            // ext/mbstring/mbstring.stub.php — PHP 8.4+; InternalArgInfo omits (#26283)
            'mb_trim', 'mb_ltrim', 'mb_rtrim' => 'string',
            // ext/mbstring/mbstring.stub.php — PHP 8.4+; InternalArgInfo omits (#26282)
            'mb_ucfirst', 'mb_lcfirst' => 'string',
            // ext/mbstring/mbstring.stub.php — InternalArgInfo return string (missing array| / |false) (#26466)
            'mb_convert_encoding' => 'array|string|false',
            // ext/session/session.stub.php — InternalArgInfo return string (missing |false) (#26460)
            'session_id' => 'string|false',
            // ext/session/session.stub.php — InternalArgInfo return string (missing |false) (#27726)
            'session_encode' => 'string|false',
            // ext/session/session.stub.php — InternalArgInfo empty / absent return; Zend bool / void (#28464)
            'session_write_close', 'session_commit', 'session_abort', 'session_reset', 'session_unset' => 'bool',
            'session_register_shutdown' => 'void',
            // ext/iconv/iconv.stub.php — InternalArgInfo return int (missing |false) (#27629)
            'iconv_strlen' => 'int|false',
            // ext/openssl/openssl.stub.php — absent from InternalArgInfo (#27685)
            'openssl_pkey_derive' => 'string|false',
            // ext/openssl/openssl.stub.php — InternalArgInfo omits return (#28368)
            'openssl_error_string' => 'string|false',
            // ext/openssl/openssl.stub.php — absent from InternalArgInfo (#27916)
            'openssl_cipher_key_length' => 'int|false',
            // ext/intl/grapheme/grapheme.stub.php — InternalArgInfo size_t/string/int without |false (#27884)
            'grapheme_strlen' => 'int|false|null',
            'grapheme_substr',
            'grapheme_strstr',
            'grapheme_stristr',
            'grapheme_extract' => 'string|false',
            'grapheme_strpos',
            'grapheme_stripos',
            'grapheme_strrpos',
            'grapheme_strripos' => 'int|false',
            // ext/intl/resourcebundle/resourcebundle.stub.php — InternalArgInfo return ResourceBundle (missing ?) (#25587)
            'resourcebundle_create' => '?ResourceBundle',
            // ext/json/json.stub.php — InternalArgInfo omits mixed / |false (#25458)
            'json_decode' => 'mixed',
            'json_encode' => 'string|false',
            // ext/standard/basic_functions.stub.php — InternalArgInfo return empty (#23260)
            'unserialize' => 'mixed',
            // ext/json/json.stub.php — PHP 8.3+; InternalArgInfo omits function entirely (#26211)
            'json_validate' => 'bool',
            // ext/curl/curl.stub.php — InternalArgInfo return int; Zend bool (#26107)
            'curl_multi_setopt' => 'bool',
            // ext/curl/curl.stub.php — InternalArgInfo resource / empty; Zend CurlHandle|false / void (#26186)
            'curl_init' => 'CurlHandle|false',
            'curl_close' => 'void',
            // InternalArgInfo bool|string; Zend Reflection string|bool (#26186)
            'curl_exec' => 'string|bool',
            // ext/filter/filter.stub.php — InternalArgInfo return empty (#25046, #26184)
            'filter_var' => 'mixed',
            'filter_input' => 'mixed',
            'filter_var_array' => 'array|false|null',
            'filter_input_array' => 'array|false|null',
            // Zend/zend_builtin_functions.stub.php — InternalArgInfo return array (missing |false) (#25498)
            'class_implements', 'class_parents', 'class_uses' => 'array|false',
            // Zend/zend_builtin_functions.stub.php — sizeof alias absent from InternalArgInfo (#25966)
            'sizeof' => 'int',
            // ext/standard/basic_functions.stub.php — PHP 8.3+; InternalArgInfo omits (#26210)
            'get_object_id' => 'int',
            // ext/spl/spl.stub.php — InternalArgInfo omits; Zend object→int (#27707, re-#24569)
            'spl_object_id' => 'int',
            // ext/standard/basic_functions.stub.php — absent from InternalArgInfo; Zend : int (#26376)
            'get_resource_id' => 'int',
            // ext/standard/basic_functions.stub.php — absent from InternalArgInfo; Zend : bool (#27774)
            'stream_isatty' => 'bool',
            // Zend/zend_builtin_functions.stub.php — InternalArgInfo omits return; Zend : string (#26375)
            'get_debug_type' => 'string',
            // Zend/zend_builtin_functions.stub.php — InternalArgInfo empty return; Zend string|false (#27902)
            'get_parent_class' => 'string|false',
            // Zend/zend_builtin_functions.stub.php — InternalArgInfo return string (missing |false) (#28004)
            'phpversion' => 'string|false',
            // Zend/zend_builtin_functions.stub.php — InternalArgInfo empty return; Zend : mixed (#28023)
            'func_get_arg' => 'mixed',
            // Zend/zend_builtin_functions.stub.php — InternalArgInfo empty / absent; Zend void / int (#28022)
            'gc_disable', 'gc_enable' => 'void',
            'gc_mem_caches' => 'int',
            // ext/spl/php_spl.stub.php — InternalArgInfo false|array; Zend array only (#27902)
            'spl_autoload_functions' => 'array',
            // Zend/zend_builtin_functions.stub.php — exit/die : never; InternalArgInfo empty / die absent (#26056)
            'exit', 'die' => 'never',
            // ext/standard/math.stub.php — InternalArgInfo float→int; Zend int|float→float (#25595)
            'ceil', 'floor' => 'float',
            // ext/standard/basic_functions.stub.php / head.c — InternalArgInfo omits |false / void (#25780)
            'get_headers' => 'array|false',
            'http_response_code' => 'int|bool',
            'stream_socket_pair' => 'array|false',
            'flush' => 'void',
            // InternalArgInfo false|array; Zend stubs are array only (#25780)
            'ob_get_status', 'ob_list_handlers' => 'array',
            // ext/libxml/libxml.stub.php — InternalArgInfo return object (#25844)
            'libxml_get_errors' => 'array',
            // ext/libxml/libxml.stub.php — InternalArgInfo return object/empty; Zend unions + voids (#28021)
            'libxml_get_last_error' => 'LibXMLError|false',
            'libxml_get_external_entity_loader' => '?callable',
            'libxml_clear_errors', 'libxml_set_streams_context' => 'void',
            // ext/libxml/libxml.stub.php — InternalArgInfo omits return; Zend bool (#27744)
            'libxml_set_external_entity_loader' => 'bool',
            // ext/xml/xml.stub.php — InternalArgInfo resource / int; Zend XMLParser / true (#26319)
            'xml_parser_create' => 'XMLParser',
            'xml_set_object' => 'true',
            // ext/xml/xml.stub.php — InternalArgInfo return int; Zend string|int (#27743)
            'xml_parser_get_option' => 'string|int',
            // ext/xml/xml.stub.php — InternalArgInfo return int; Zend bool (#27793)
            'xml_parser_free' => 'bool',
            // ext/xml/xml.stub.php — InternalArgInfo resource / int; Zend XMLParser / int|false (#26687)
            'xml_parser_create_ns' => 'XMLParser',
            'xml_parse_into_struct' => 'int|false',
            // ext/xml/xml.stub.php — InternalArgInfo return int; Zend true (#26589)
            'xml_set_character_data_handler',
            'xml_set_default_handler',
            'xml_set_element_handler',
            'xml_set_end_namespace_decl_handler',
            'xml_set_external_entity_ref_handler',
            'xml_set_notation_decl_handler',
            'xml_set_processing_instruction_handler',
            'xml_set_start_namespace_decl_handler',
            'xml_set_unparsed_entity_decl_handler' => 'true',
            // ext/standard/array.stub.php — InternalArgInfo return empty (#25441)
            'array_sum', 'array_product' => 'int|float',
            // ext/standard/array.stub.php — absent from InternalArgInfo (#26111)
            'array_key_first', 'array_key_last' => 'string|int|null',
            // ext/standard/array.stub.php — InternalArgInfo return bool; Zend true (#26172)
            'usort', 'uasort', 'uksort', 'ksort', 'krsort' => 'true',
            // ext/imap/php_imap.stub.php — InternalArgInfo return string (missing |false) (#27681, #27764, #27765)
            'imap_utf7_decode', 'imap_utf8_to_mutf7', 'imap_mutf7_to_utf8', 'imap_mail_compose' => 'string|false',
            // ext/imap/php_imap.stub.php — InternalArgInfo return empty; Zend int|bool (#27680)
            'imap_timeout' => 'int|bool',
            // ext/imap/php_imap.stub.php — PHP 8.3+; absent from InternalArgInfo (#27674)
            'imap_is_open' => 'bool',
            // ext/imap/php_imap.stub.php — InternalArgInfo string/object; Zend string|false / stdClass (#27682)
            'imap_rfc822_write_address' => 'string|false',
            'imap_rfc822_parse_headers' => 'stdClass',
            // ext/imap/php_imap.stub.php — codecs + mime header decode (#27683)
            'imap_base64', 'imap_qprint', 'imap_8bit', 'imap_binary' => 'string|false',
            'imap_utf8' => 'string',
            'imap_mime_header_decode' => 'array|false',
            // ext/sysvshm/sysvshm.stub.php — InternalArgInfo return int / empty; Zend SysvSharedMemory|false / mixed (#27943)
            'shm_attach' => 'SysvSharedMemory|false',
            'shm_get_var' => 'mixed',
            // ext/sockets/sockets.stub.php — InternalArgInfo resource / empty; Zend Socket|false / void (#27854)
            'socket_create', 'socket_create_listen', 'socket_accept', 'socket_import_stream' => 'Socket|false',
            'socket_close' => 'void',
            default => null,
        };
    }

    public static function paramCountForFunction(string $name): ?int
    {
        $lc = strtolower($name);
        $info = self::instance()->functions[$lc] ?? null;
        if (null === $info) {
            return null;
        }

        return \count($info['params']);
    }

    public static function paramCountForClassMethod(string $class, string $method): ?int
    {
        $classLc = strtolower($class);
        $methodLc = strtolower($method);
        $methods = self::instance()->methods[$classLc]['methods'] ?? [];
        $info = $methods[$methodLc] ?? null;
        if (null === $info) {
            return null;
        }

        return \count($info['params']);
    }

    public static function requiredParamCountForFunction(string $name): ?int
    {
        $lc = strtolower($name);
        $info = self::instance()->functions[$lc] ?? null;
        if (null === $info) {
            return null;
        }

        return self::requiredParamCountFromRawParams($info['params']);
    }

    public static function requiredParamCountForClassMethod(string $class, string $method): ?int
    {
        $classLc = strtolower($class);
        $methodLc = strtolower($method);
        $methods = self::instance()->methods[$classLc]['methods'] ?? [];
        $info = $methods[$methodLc] ?? null;
        if (null === $info) {
            return null;
        }

        return self::requiredParamCountFromRawParams($info['params']);
    }

    /**
     * @return list<string>
     */
    public static function paramNamesForClassMethod(string $class, string $method): array
    {
        $classLc = strtolower($class);
        $methodLc = strtolower($method);
        $methods = self::instance()->methods[$classLc]['methods'] ?? [];
        $info = $methods[$methodLc] ?? null;
        if (null === $info) {
            return [];
        }
        $names = [];
        foreach ($info['params'] as $param) {
            $names[] = self::normalizeParamInfo($param)['name'];
        }

        return $names;
    }

    /**
     * @return array<string, array{total: int, required: int}>
     */
    public static function functionArityTables(): array
    {
        $tables = [];
        foreach (self::instance()->functions as $funcLc => $info) {
            $tables[$funcLc] = [
                'total' => \count($info['params']),
                'required' => self::requiredParamCountFromRawParams($info['params']),
            ];
        }

        return $tables;
    }

    /**
     * @return array<string, array<string, array{total: int, required: int}>>
     */
    public static function methodArityTables(): array
    {
        $tables = [];
        foreach (self::instance()->methods as $classLc => $classInfo) {
            foreach ($classInfo['methods'] ?? [] as $methodLc => $info) {
                $tables[$classLc][$methodLc] = [
                    'total' => \count($info['params']),
                    'required' => self::requiredParamCountFromRawParams($info['params']),
                ];
            }
        }

        return $tables;
    }

    /**
     * @return array{name: string, type: string, isOptional: bool}|null
     */
    public static function paramInfoForFunction(string $name, int $index): ?array
    {
        $lc = strtolower($name);
        $info = self::instance()->functions[$lc] ?? null;
        if (null !== $info && isset($info['params'][$index])) {
            $normalized = self::normalizeParamInfo($info['params'][$index]);
            $typeOverride = self::stubParamTypeOverride($lc, $index);
            if (null !== $typeOverride) {
                $normalized['type'] = $typeOverride;
            }

            return $normalized;
        }

        // Trailing stub-only params missing from InternalArgInfo (#23587 preg_replace_callback $flags).
        $typeOverride = self::stubParamTypeOverride($lc, $index);
        if (null === $typeOverride) {
            return null;
        }
        $names = BuiltinParamNames::forFunction($name);
        $rawName = null !== $names && isset($names[$index]) ? $names[$index] : ('arg'.$index);
        $isOptional = str_ends_with($rawName, '=');
        $paramName = rtrim(ltrim($rawName, '&'), '=');
        if (str_starts_with($paramName, '...')) {
            $paramName = substr($paramName, 3);
        }

        return [
            'name' => $paramName,
            'type' => $typeOverride,
            'isOptional' => $isOptional,
        ];
    }

    /**
     * php-src stub types when InternalArgInfo omits nullability (#24845).
     */
    public static function stubParamTypeOverride(string $callableLc, int $index): ?string
    {
        // ext/standard/basic_functions.stub.php — StreamBucket $bucket on PROFILE≥8.4 (#27797)
        if (CompilerVersion::supportsStreamBucketClass()
            && ('stream_bucket_append' === $callableLc || 'stream_bucket_prepend' === $callableLc)
            && 1 === $index
        ) {
            return 'StreamBucket';
        }

        return match ($callableLc) {
            // ext/date/php_date.stub.php — ?int $timestamp / $baseTimestamp = null
            'date', 'gmdate' => 1 === $index ? '?int' : null,
            'strtotime' => 1 === $index ? '?int' : null,
            // ext/standard/basic_functions.stub.php — RoundingMode|int $mode = RoundingMode::HalfAwayFromZero (#28535)
            'round' => (2 === $index && CompilerVersion::supportsRoundingModeEnum())
                ? 'RoundingMode|int'
                : null,
            // ext/date/php_date.stub.php — ?int $timestamp = null (InternalArgInfo int) (#25440)
            'idate' => 1 === $index ? '?int' : null,
            'getdate' => 0 === $index ? '?int' : null,
            // ext/date/php_date.stub.php — ?int $timestamp = null (InternalArgInfo int → 0) (#27980)
            'localtime' => 0 === $index ? '?int' : null,
            // ext/date/php_date.stub.php — absent from InternalArgInfo (#25392)
            'date_create', 'date_create_immutable' => match ($index) {
                0 => 'string',
                1 => '?DateTimeZone',
                default => null,
            },
            // ext/date/php_date.stub.php — ?string $countryCode = null (InternalArgInfo string required) (#25173)
            'timezone_identifiers_list' => 1 === $index ? '?string' : null,
            // ext/standard/basic_functions.stub.php — ?string $extension = null (InternalArgInfo string) (#25276)
            'ini_get_all' => 0 === $index ? '?string' : null,
            // Zend/zend_builtin_functions.stub.php — ?string $extension = null (InternalArgInfo string) (#28004)
            'phpversion' => 0 === $index ? '?string' : null,
            // ext/standard/basic_functions.stub.php — ini_alter absent; value union (#26465, #26187)
            'ini_set', 'ini_alter' => match ($index) {
                0 => 'string',
                1 => 'string|int|float|bool|null',
                default => null,
            },
            // ext/date/php_date.stub.php — hour required; ?int minute…year = null (#25147)
            'mktime', 'gmmktime' => ($index >= 1 && $index <= 5) ? '?int' : null,
            // ext/standard/basic_functions.stub.php — mixed &...$vars (InternalArgInfo string) (#26058)
            'fscanf' => 2 === $index ? 'mixed' : null,
            // ext/standard/password.stub.php — absent from InternalArgInfo (#23292)
            'password_get_info' => 0 === $index ? 'string' : null,
            'password_needs_rehash' => match ($index) {
                0 => 'string',
                1 => 'string|int|null',
                2 => 'array',
                default => null,
            },
            // ext/calendar/calendar.stub.php — ?int $timestamp = null (#24863)
            'unixtojd' => 0 === $index ? '?int' : null,
            // Zend/zend_builtin_functions.stub.php — mixed $object_or_class; InternalArgInfo empty (#26359)
            'is_a', 'is_subclass_of' => 0 === $index ? 'mixed' : null,
            // Zend/zend_builtin_functions.stub.php + basic_functions.stub.php — mixed $value; InternalArgInfo untyped (#26376)
            'gettype' => 0 === $index ? 'mixed' : null,
            // Zend/zend_builtin_functions.stub.php — mixed $value; InternalArgInfo untyped (#26375)
            'get_debug_type' => 0 === $index ? 'mixed' : null,
            // Zend/zend_builtin_functions.stub.php — object $object (InternalArgInfo omits row) (#25016)
            'get_mangled_object_vars' => 0 === $index ? 'object' : null,
            // ext/standard/basic_functions.stub.php — object $object (InternalArgInfo omits) (#26210)
            'get_object_id' => 0 === $index ? 'object' : null,
            // ext/spl/spl.stub.php — object $object (InternalArgInfo omits) (#27707, re-#24569)
            'spl_object_id' => 0 === $index ? 'object' : null,
            // Zend/zend_builtin_functions.stub.php — object|string; InternalArgInfo empty type (#27706, re-#23401)
            'get_class_methods' => 0 === $index ? 'object|string' : null,
            // Zend/zend_builtin_functions.stub.php — object|string; InternalArgInfo empty type (#27902)
            'get_parent_class' => 0 === $index ? 'object|string' : null,
            // Zend/zend_builtin_functions.stub.php — object|string untyped in Reflection (InternalArgInfo object) (#25498)
            'class_parents' => 0 === $index ? '' : null,
            // Zend/zend_builtin_functions.stub.php — user_error alias absent from InternalArgInfo (#25174)
            'user_error' => match ($index) {
                0 => 'string',
                1 => 'int',
                default => null,
            },
            // Zend/zend_builtin_functions.stub.php — count/sizeof Countable|array; sizeof absent (#25966)
            // InternalArgInfo still has untyped $var for count; sizeof has no row at all.
            'count', 'sizeof' => match ($index) {
                0 => 'Countable|array',
                1 => 'int',
                default => null,
            },
            // Zend/zend_builtin_functions.stub.php — string|int $status = 0; InternalArgInfo int / die absent (#26056)
            'exit', 'die' => 0 === $index ? 'string|int' : null,
            // ext/standard/array.stub.php — ?callable $callback; mode missing from InternalArgInfo (#24843)
            'array_filter' => match ($index) {
                1 => '?callable',
                2 => 'int',
                default => null,
            },
            // ext/standard/array.stub.php — array $array; absent from InternalArgInfo (#26111)
            'array_key_first', 'array_key_last' => 0 === $index ? 'array' : null,
            // ext/standard/array.stub.php — ?int $length = null, mixed $replacement = [] (#24824)
            'array_splice' => match ($index) {
                2 => '?int',
                3 => 'mixed',
                default => null,
            },
            // ext/hash/hash.stub.php — missing from InternalArgInfo (#25018)
            'hash_hkdf' => match ($index) {
                0, 1, 3, 4 => 'string',
                2 => 'int',
                default => null,
            },
            // ext/openssl/openssl.stub.php — absent from InternalArgInfo; $public_key/$private_key untyped (#27685)
            'openssl_pkey_derive' => match ($index) {
                0, 1 => '',
                2 => 'int',
                default => null,
            },
            // ext/openssl/openssl.stub.php — absent from InternalArgInfo; string $cipher_algo (#27916)
            'openssl_cipher_key_length' => 0 === $index ? 'string' : null,
            // ext/intl/grapheme/grapheme.stub.php — Zend names/types; &$next untyped (#27884)
            'grapheme_strlen' => 0 === $index ? 'string' : null,
            'grapheme_substr' => match ($index) {
                0 => 'string',
                1 => 'int',
                2 => '?int',
                default => null,
            },
            'grapheme_strstr', 'grapheme_stristr' => match ($index) {
                0, 1 => 'string',
                2 => 'bool',
                default => null,
            },
            'grapheme_extract' => match ($index) {
                0 => 'string',
                1, 2, 3 => 'int',
                4 => '',
                default => null,
            },
            'grapheme_strpos', 'grapheme_stripos', 'grapheme_strrpos', 'grapheme_strripos' => match ($index) {
                0, 1 => 'string',
                2 => 'int',
                default => null,
            },
            // ext/hash/hash.stub.php — typed params; length/binary/options optional (#25469)
            'hash_pbkdf2' => match ($index) {
                0, 1, 2 => 'string',
                3, 4 => 'int',
                5 => 'bool',
                6 => 'array',
                default => null,
            },
            // ext/hash/hash.stub.php — missing from InternalArgInfo (#25470)
            'hash_equals' => match ($index) {
                0, 1 => 'string',
                default => null,
            },
            // ext/zlib/zlib.stub.php — inflate_init $options omitted from InternalArgInfo (#27627)
            'inflate_init' => 1 === $index ? 'array' : null,
            // ext/hash/hash.stub.php — HashContext $context; InternalArgInfo untyped / resource (#27745, #27737)
            'hash_copy', 'hash_update', 'hash_final' => 0 === $index ? 'HashContext' : null,
            'hash_update_file' => 0 === $index ? 'HashContext' : null,
            // length: InternalArgInfo "integer"; Zend ?int advertised as int (optional) (#27737)
            'hash_update_stream' => match ($index) {
                0 => 'HashContext',
                2 => 'int',
                default => null,
            },
            // ext/sodium/sodium_*.stub.php — absent from InternalArgInfo (#24490)
            'sodium_crypto_generichash' => match ($index) {
                0, 1 => 'string',
                2 => 'int',
                default => null,
            },
            'sodium_crypto_secretbox', 'sodium_crypto_box' => match ($index) {
                0, 1, 2 => 'string',
                default => null,
            },
            'sodium_crypto_sign' => match ($index) {
                0, 1 => 'string',
                default => null,
            },
            'sodium_crypto_pwhash_str' => match ($index) {
                0 => 'string',
                1, 2 => 'int',
                default => null,
            },
            // ext/session/session.stub.php — ?string $id = null (InternalArgInfo string) (#26460)
            'session_id' => 0 === $index ? '?string' : null,
            // ext/iconv/iconv.stub.php — ?string $encoding = null (InternalArgInfo string) (#27629)
            'iconv_strlen' => 1 === $index ? '?string' : null,
            // ext/intl/resourcebundle/resourcebundle.stub.php — ?string $locale / ?string $bundle (#25587)
            'resourcebundle_create' => ($index === 0 || $index === 1) ? '?string' : null,
            // ext/json/json.stub.php — ?bool $associative; mixed $value (#25458)
            'json_decode' => 1 === $index ? '?bool' : null,
            'json_encode' => 0 === $index ? 'mixed' : null,
            // ext/standard/basic_functions.stub.php — mixed $value; string $data; array $options (#23260)
            // InternalArgInfo still variable (untyped) / variable_representation+allowed_classes (bool|array).
            'serialize' => 0 === $index ? 'mixed' : null,
            'unserialize' => match ($index) {
                0 => 'string',
                1 => 'array',
                default => null,
            },
            // ext/json/json.stub.php — string $json, int $depth, int $flags (#26211, re-#23876)
            'json_validate' => match ($index) {
                0 => 'string',
                1, 2 => 'int',
                default => null,
            },
            // ext/curl/curl.stub.php — CurlHandle / CurlMultiHandle + mixed $value (#26107, #26186)
            // InternalArgInfo still has resource url, untyped ch/mh/value, and return int on curl_multi_setopt.
            'curl_init' => 0 === $index ? '?string' : null,
            'curl_setopt' => match ($index) {
                0 => 'CurlHandle',
                2 => 'mixed',
                default => null,
            },
            'curl_exec', 'curl_close' => 0 === $index ? 'CurlHandle' : null,
            'curl_setopt_array' => 0 === $index ? 'CurlHandle' : null,
            'curl_multi_setopt' => match ($index) {
                0 => 'CurlMultiHandle',
                2 => 'mixed',
                default => null,
            },
            // ext/curl/curl.stub.php — CurlShareHandle $share_handle, mixed $value (#27704)
            // InternalArgInfo still has untyped sh/value (param name sh → share_handle via BuiltinParamNames).
            'curl_share_setopt' => match ($index) {
                0 => 'CurlShareHandle',
                2 => 'mixed',
                default => null,
            },
            'curl_share_close', 'curl_share_errno' => 0 === $index ? 'CurlShareHandle' : null,
            // ext/sysvshm/sysvshm.stub.php — SysvSharedMemory + ?int $size; InternalArgInfo int/untyped (#27943, re-#24640)
            'shm_attach' => match ($index) {
                0 => 'int',
                1 => '?int',
                2 => 'int',
                default => null,
            },
            'shm_detach', 'shm_remove' => 0 === $index ? 'SysvSharedMemory' : null,
            'shm_put_var' => match ($index) {
                0 => 'SysvSharedMemory',
                1 => 'int',
                2 => 'mixed',
                default => null,
            },
            'shm_get_var', 'shm_has_var', 'shm_remove_var' => match ($index) {
                0 => 'SysvSharedMemory',
                1 => 'int',
                default => null,
            },
            // ext/sockets/sockets.stub.php — Socket $socket; InternalArgInfo untyped / absent (#27854)
            'socket_export_stream', 'socket_close', 'socket_accept' => 0 === $index ? 'Socket' : null,
            // ext/filter/filter.stub.php — mixed $value; array|int $options (InternalArgInfo variable/untyped) (#25046)
            'filter_var' => match ($index) {
                0 => 'mixed',
                2 => 'array|int',
                default => null,
            },
            // ext/filter/filter.stub.php — array|int $options / bool $add_empty (#26184)
            'filter_var_array' => match ($index) {
                1 => 'array|int',
                2 => 'bool',
                default => null,
            },
            // ext/filter/filter.stub.php — array|int $options / bool $add_empty (#26201)
            'filter_input_array' => match ($index) {
                1 => 'array|int',
                2 => 'bool',
                default => null,
            },
            // ext/filter/filter.stub.php — array|int $options = 0 (#26184)
            'filter_input' => 3 === $index ? 'array|int' : null,
            // ext/standard/file.stub.php — &$would_block untyped; InternalArgInfo int (#23352)
            'flock' => 2 === $index ? '' : null,
            // ext/hash/hash.stub.php — trailing array $options = [] omitted from InternalArgInfo (#25068)
            'hash' => 3 === $index ? 'array' : null,
            // ext/standard/string.stub.php — &$count = null (untyped; InternalArgInfo int) (#24886)
            'str_replace', 'str_ireplace' => 3 === $index ? '' : null,
            // ext/standard/string.stub.php — &$percent = null (untyped; InternalArgInfo float) (#25361)
            'similar_text' => 2 === $index ? '' : null,
            // ext/standard/string.stub.php — array|string $separator, ?array $array = null (#24811)
            // InternalArgInfo: implode glue:string/pieces:array; join src:array/glue:string (inverted).
            'implode', 'join' => match ($index) {
                0 => 'array|string',
                1 => '?array',
                default => null,
            },
            // ext/standard/string.stub.php — array|string|null $allowed_tags = null (InternalArgInfo string) (#25594)
            'strip_tags' => 1 === $index ? 'array|string|null' : null,
            // ext/standard/basic_functions.stub.php — ?int $length = null (InternalArgInfo int) (#25749)
            'substr' => 2 === $index ? '?int' : null,
            // ext/standard/string.stub.php — ?int $length = null (InternalArgInfo int → 0) (#25472)
            'substr_count' => 3 === $index ? '?int' : null,
            // ext/standard/string.stub.php — ?string $delimiter = null (InternalArgInfo string, OPT no default) (#25472)
            'preg_quote' => 1 === $index ? '?string' : null,
            // ext/standard/basic_functions.stub.php — ?string $encoding = null (InternalArgInfo string) (#24970, #23265)
            'htmlentities', 'htmlspecialchars', 'html_entity_decode' => 2 === $index ? '?string' : null,
            // ext/fileinfo/fileinfo.stub.php — finfo object + string (InternalArgInfo resource/char) (#25471)
            'finfo_open' => 1 === $index ? '?string' : null,
            'finfo_file', 'finfo_buffer' => match ($index) {
                0 => 'finfo',
                1 => 'string',
                default => null,
            },
            // ext/simplexml/simplexml.stub.php — ?string $class_name (InternalArgInfo string) (#25510)
            'simplexml_load_string', 'simplexml_load_file' => 1 === $index ? '?string' : null,
            // ext/dom/php_dom.stub.php — object $node (InternalArgInfo typo sxeobject) (#26464)
            'dom_import_simplexml' => 0 === $index ? 'object' : null,
            // ext/simplexml/simplexml.stub.php — SimpleXMLElement|DOMNode + ?string $class_name (#26464)
            // InternalArgInfo: domnode / string (non-nullable).
            'simplexml_import_dom' => match ($index) {
                0 => 'SimpleXMLElement|DOMNode',
                1 => '?string',
                default => null,
            },
            // ext/standard/string.stub.php — ?string $token = null (InternalArgInfo type "str", required) (#25171)
            'strtok' => 1 === $index ? '?string' : null,
            // ext/standard/string.stub.php — int $insertion_cost/$replacement_cost/$deletion_cost = 1
            // InternalArgInfo only lists string1/string2; cost params are stub-only (#25538 / re-#24791)
            'levenshtein' => ($index >= 2 && $index <= 4) ? 'int' : null,
            // ext/standard/basic_functions.stub.php — ?string $name = null, bool $local_only = false (#24855)
            // InternalArgInfo still says varname:string required; local_only missing.
            'getenv' => match ($index) {
                0 => '?string',
                1 => 'bool',
                default => null,
            },
            // ext/pcre/php_pcre.stub.php — string|array unions; &$count = null untyped (#23587)
            'preg_replace', 'preg_filter' => match ($index) {
                0, 1, 2 => 'array|string',
                4 => '',
                default => null,
            },
            'preg_replace_callback' => match ($index) {
                0, 2 => 'array|string',
                1 => 'callable',
                4 => '',
                5 => 'int',
                default => null,
            },
            // ext/tokenizer/tokenizer.stub.php — int $flags = 0; InternalArgInfo omits flags (#26258)
            'token_get_all' => 1 === $index ? 'int' : null,
            // ext/standard/basic_functions.stub.php — ?array $options/$params = null (#25069)
            'stream_context_create' => match ($index) {
                0, 1 => '?array',
                default => null,
            },
            // ext/standard/basic_functions.stub.php — array $options (context untyped; #25453)
            'stream_context_set_options' => 1 === $index ? 'array' : null,
            // ext/standard/basic_functions.stub.php — array|string + optional ?string/mixed (#25845)
            // InternalArgInfo still has wrappername:string / optionname:string / value required.
            'stream_context_set_option' => match ($index) {
                1 => 'array|string',
                2 => '?string',
                3 => 'mixed',
                default => null,
            },
            // ext/standard/proc_open.stub.php — untyped &$pipes; ?string/?array optionals (#27847)
            // InternalArgInfo still has pipes:array and non-nullable cwd/env/other_options.
            'proc_open' => match ($index) {
                2 => '',
                3 => '?string',
                4, 5 => '?array',
                default => null,
            },
            // ext/standard/streamsfuncs.stub.php — ?int $crypto_method = null (#27684)
            'stream_socket_enable_crypto' => 2 === $index ? '?int' : null,
            // ext/standard/head.stub.php — ?string $name = null (InternalArgInfo string) (#25381)
            'header_remove' => 0 === $index ? '?string' : null,
            // ext/standard/head.stub.php — &$filename / &$line untyped (InternalArgInfo string/int) (#25381)
            'headers_sent' => ($index >= 0 && $index <= 1) ? '' : null,
            // ext/standard/head.stub.php — callable $callback (InternalArgInfo empty) (#25381)
            'header_register_callback' => 0 === $index ? 'callable' : null,
            // ext/standard/basic_functions.stub.php — ?array $options = null (InternalArgInfo array) (#25381)
            'stream_context_get_default' => 0 === $index ? '?array' : null,
            // ext/standard/basic_functions.stub.php — trailing bool $raw = false (#23358)
            'dns_get_record' => 4 === $index ? 'bool' : null,
            // ext/standard/file.stub.php — ?int $length = null (#24846)
            'fwrite' => 2 === $index ? '?int' : null,
            'fgets' => 1 === $index ? '?int' : null,
            // ext/standard/file.stub.php — ?int $length = null (#25750)
            'stream_get_contents' => 1 === $index ? '?int' : null,
            // ext/standard/streamsfuncs.stub.php — ?int $length = null (#27739)
            'stream_copy_to_stream' => 2 === $index ? '?int' : null,
            // ext/standard/streamsfuncs.stub.php — ?array &$read (InternalArgInfo array) (#27777)
            'stream_select' => 0 === $index ? '?array' : null,
            // ext/standard/streamsfuncs.stub.php — untyped &$error_* outs + ?float $timeout (#27848)
            'stream_socket_client' => match ($index) {
                1, 2 => '',
                3 => '?float',
                default => null,
            },
            // ext/standard/file.stub.php — ?int $length = null (#24826)
            'fgetcsv' => 1 === $index ? '?int' : null,
            // ext/standard/file.stub.php — string $eol = "\n" (missing from InternalArgInfo) (#25135)
            'fputcsv' => 5 === $index ? 'string' : null,
            // ext/standard/file.stub.php — ?int $length = null (#24814)
            'file_get_contents' => 4 === $index ? '?int' : null,
            // ext/standard/file.stub.php — mixed $data (InternalArgInfo untyped) (#25509)
            'file_put_contents' => 1 === $index ? 'mixed' : null,
            // ext/mbstring/mbstring.stub.php — ?int $length = null, ?string $encoding = null (#25362)
            'mb_substr' => match ($index) {
                2 => '?int',
                3 => '?string',
                default => null,
            },
            // ext/mbstring/mbstring.stub.php — string, ?string characters=, ?string encoding= (#26283)
            'mb_trim', 'mb_ltrim', 'mb_rtrim' => match ($index) {
                0 => 'string',
                1, 2 => '?string',
                default => null,
            },
            // ext/mbstring/mbstring.stub.php — string, ?string encoding=null (#26282)
            'mb_ucfirst', 'mb_lcfirst' => match ($index) {
                0 => 'string',
                1 => '?string',
                default => null,
            },
            // ext/mbstring/mbstring.stub.php — array|string $string; array|string|null $from_encoding (#26466)
            // InternalArgInfo still has str:string / from-encoding= untyped.
            'mb_convert_encoding' => match ($index) {
                0 => 'array|string',
                2 => 'array|string|null',
                default => null,
            },
            // ext/spl/spl.stub.php — Traversable|array (InternalArgInfo says traversable) (#25066)
            'iterator_to_array' => 0 === $index ? 'Traversable|array' : null,
            // ext/spl/spl.stub.php — ?callable $callback = null; InternalArgInfo empty type (#25390)
            'spl_autoload_register' => match ($index) {
                0 => '?callable',
                1, 2 => 'bool',
                default => null,
            },
            // ext/standard/array.stub.php — int|float $step = 1 (InternalArgInfo int) (#25480)
            'range' => 2 === $index ? 'int|float' : null,
            // ext/standard/basic_functions.stub.php — int $levels = 1 (missing from InternalArgInfo) (#25480)
            'dirname' => 1 === $index ? 'int' : null,
            // ext/standard/math.stub.php — int|float $num (InternalArgInfo float) (#25595)
            'ceil', 'floor' => 0 === $index ? 'int|float' : null,
            // ext/standard/basic_functions.stub.php — bool $associative = false; $context = null untyped (#25780)
            // InternalArgInfo still says format:int and omits context.
            'get_headers' => match ($index) {
                1 => 'bool',
                2 => '',
                default => null,
            },
            // ext/standard/dns.stub.php — &$hosts / &$weights untyped (InternalArgInfo array) (#25780)
            'getmxrr', 'dns_get_mx' => ($index >= 1 && $index <= 2) ? '' : null,
            // ext/libxml/libxml.stub.php — ?bool $use_errors = null (InternalArgInfo bool) (#25844)
            'libxml_use_internal_errors' => 0 === $index ? '?bool' : null,
            // ext/libxml/libxml.stub.php — ?callable $resolver_function (InternalArgInfo callable) (#27744)
            'libxml_set_external_entity_loader' => 0 === $index ? '?callable' : null,
            // ext/xml/xml.stub.php — XMLParser / ?string; InternalArgInfo untyped / string / resource (#26319)
            'xml_set_object' => 0 === $index ? 'XMLParser' : null,
            'xml_parser_create' => 0 === $index ? '?string' : null,
            'xml_parse' => 0 === $index ? 'XMLParser' : null,
            // ext/xml/xml.stub.php — XMLParser $parser; InternalArgInfo untyped (#27738)
            'xml_get_current_byte_index',
            'xml_get_current_column_number',
            'xml_get_current_line_number' => 0 === $index ? 'XMLParser' : null,
            // ext/xml/xml.stub.php — XMLParser $parser; InternalArgInfo untyped (#27743)
            'xml_parser_get_option' => 0 === $index ? 'XMLParser' : null,
            // ext/xml/xml.stub.php — XMLParser $parser; InternalArgInfo untyped (#27793)
            'xml_parser_free' => 0 === $index ? 'XMLParser' : null,
            // ext/xml/xml.stub.php — create_ns / into_struct; InternalArgInfo resource/sep/array (#26687)
            'xml_parser_create_ns' => 0 === $index ? '?string' : null,
            'xml_parse_into_struct' => match ($index) {
                0 => 'XMLParser',
                // Zend @param array but ReflectionParameter type is empty (#26687)
                2, 3 => '',
                default => null,
            },
            // ext/xml/xml.stub.php — XMLParser + untyped handler; InternalArgInfo hdl:string (#26589)
            'xml_set_character_data_handler',
            'xml_set_default_handler',
            'xml_set_end_namespace_decl_handler',
            'xml_set_external_entity_ref_handler',
            'xml_set_notation_decl_handler',
            'xml_set_processing_instruction_handler',
            'xml_set_start_namespace_decl_handler',
            'xml_set_unparsed_entity_decl_handler' => match ($index) {
                0 => 'XMLParser',
                1 => '',
                default => null,
            },
            'xml_set_element_handler' => match ($index) {
                0 => 'XMLParser',
                1, 2 => '',
                default => null,
            },
            // ext/imap/php_imap.stub.php — PHP 8.3+; absent from InternalArgInfo (#27674)
            'imap_is_open' => 0 === $index ? 'IMAP\\Connection' : null,
            default => null,
        };
    }

    /**
     * @return array{name: string, type: string, isOptional: bool}|null
     */
    public static function paramInfoForClassMethod(string $class, string $method, int $index): ?array
    {
        $classLc = strtolower($class);
        $methodLc = strtolower($method);
        $methods = self::instance()->methods[$classLc]['methods'] ?? [];
        $info = $methods[$methodLc] ?? null;
        if (null !== $info && isset($info['params'][$index])) {
            $normalized = self::normalizeParamInfo($info['params'][$index]);
            $typeOverride = self::stubParamTypeOverrideForClassMethod($classLc, $methodLc, $index);
            if (null !== $typeOverride) {
                $normalized['type'] = $typeOverride;
            }

            return $normalized;
        }

        // Stub-only methods missing from php-types InternalArgInfo (#25055 Spoofchecker).
        $typeOverride = self::stubParamTypeOverrideForClassMethod($classLc, $methodLc, $index);
        $names = BuiltinParamNames::forClassMethod($classLc.'::'.$methodLc);
        if (null === $names || !isset($names[$index])) {
            return null;
        }
        $rawName = $names[$index];
        $isOptional = str_ends_with($rawName, '=');
        $paramName = rtrim(ltrim($rawName, '&'), '=');
        if (str_starts_with($paramName, '...')) {
            $paramName = substr($paramName, 3);
            $isOptional = true;
        }

        return [
            'name' => $paramName,
            'type' => $typeOverride ?? '',
            'isOptional' => $isOptional,
        ];
    }

    /**
     * php-src stub nullability when InternalArgInfo omits `?` on class methods (#24923).
     */
    public static function stubParamTypeOverrideForClassMethod(string $classLc, string $methodLc, int $index): ?string
    {
        return match ($classLc.'::'.$methodLc) {
            // ext/dom/php_dom.stub.php — createElementNS(?string $namespace, …)
            'domdocument::createelementns' => 0 === $index ? '?string' : null,
            // ext/dom/php_dom.stub.php — createAttributeNS(?string $namespace, …)
            'domdocument::createattributens' => 0 === $index ? '?string' : null,
            // ext/intl/spoofchecker/spoofchecker.stub.php — string $string / $string1/$string2 (#25055)
            'spoofchecker::issuspicious' => 0 === $index ? 'string' : null,
            'spoofchecker::areconfusable' => ($index === 0 || $index === 1) ? 'string' : null,
            // ext/spl/spl_directory.stub.php — string $eol = "\n" (missing from InternalArgInfo) (#25135)
            'splfileobject::fputcsv' => 4 === $index ? 'string' : null,
            // ext/spl/spl_heap.stub.php — mixed $value1/$value2 / $priority1/$priority2 (#25555)
            'splheap::compare',
            'splminheap::compare',
            'splmaxheap::compare' => ($index === 0 || $index === 1) ? 'mixed' : null,
            'splpriorityqueue::compare' => ($index === 0 || $index === 1) ? 'mixed' : null,
            // ext/date/php_date.stub.php — ?string $countryCode = null (InternalArgInfo string) (#25172)
            'datetimezone::listidentifiers' => 1 === $index ? '?string' : null,
            // ext/date/php_date.stub.php — ?DateTimeZone $timezone = null (InternalArgInfo datetimezone) (#25166)
            'datetime::createfromformat',
            'datetimeimmutable::createfromformat' => 2 === $index ? '?DateTimeZone' : null,
            // ext/date/php_date.stub.php — int $microsecond = 0 missing from InternalArgInfo (#25400)
            'datetime::settime',
            'datetimeimmutable::settime' => 3 === $index ? 'int' : null,
            // ext/date/php_date.stub.php — int $microsecond (PHP 8.4+; missing from InternalArgInfo) (#26098)
            'datetime::setmicrosecond',
            'datetimeimmutable::setmicrosecond' => 0 === $index ? 'int' : null,
            // ext/date/php_date.stub.php — int|float $timestamp (PHP 8.4+; missing from InternalArgInfo) (#26097)
            'datetime::createfromtimestamp',
            'datetimeimmutable::createfromtimestamp' => 0 === $index ? 'int|float' : null,
            // ext/pdo/pdo_dbh.stub.php — PHP 8.4+ connect (absent from InternalArgInfo) (#26223)
            'pdo::connect' => match ($index) {
                0 => 'string',
                1, 2 => '?string',
                3 => '?array',
                default => null,
            },
            // ext/reflection/php_reflection.stub.php — PHP 8.4+ getRawValue/setRawValue (#27599)
            'reflectionproperty::getrawvalue' => 0 === $index ? 'object' : null,
            'reflectionproperty::setrawvalue' => match ($index) {
                0 => 'object',
                1 => 'mixed',
                default => null,
            },
            // ext/reflection/php_reflection.stub.php — PHP 8.5+ isReadable/isWritable (#28533)
            'reflectionproperty::isreadable',
            'reflectionproperty::iswritable' => match ($index) {
                0 => '?string',
                1 => '?object',
                default => null,
            },
            // ext/reflection/php_reflection.stub.php — PHP 8.4+ lazy factories (#27741)
            'reflectionclass::newlazyghost' => match ($index) {
                0 => 'callable',
                1 => 'int',
                default => null,
            },
            'reflectionclass::newlazyproxy' => match ($index) {
                0 => 'callable',
                1 => 'int',
                default => null,
            },
            'reflectionclass::resetaslazyghost' => match ($index) {
                0 => 'object',
                1 => 'callable',
                2 => 'int',
                default => null,
            },
            'reflectionclass::resetaslazyproxy' => match ($index) {
                0 => 'object',
                1 => 'callable',
                2 => 'int',
                default => null,
            },
            // ext/dom/php_dom.stub.php — string $source / int $options = 0 / ?string $overrideEncoding = null (#26080)
            'dom\\htmldocument::createfromstring',
            'dom\\xmldocument::createfromstring' => match ($index) {
                0 => 'string',
                1 => 'int',
                2 => '?string',
                default => null,
            },
            // ext/xmlreader/php_xmlreader.stub.php — PHP 8.4+ factories (#27713)
            'xmlreader::fromstring' => match ($index) {
                0 => 'string',
                1 => '?string',
                2 => 'int',
                default => null,
            },
            'xmlreader::fromuri' => match ($index) {
                0 => 'string',
                1 => '?string',
                2 => 'int',
                default => null,
            },
            // $stream is untyped (@param resource); encoding/?string, flags/int, documentUri/?string
            'xmlreader::fromstream' => match ($index) {
                1 => '?string',
                2 => 'int',
                3 => '?string',
                default => null,
            },
            // ext/xmlwriter/php_xmlwriter.stub.php — PHP 8.4+ factories (#27922)
            'xmlwriter::touri' => 0 === $index ? 'string' : null,
            // toStream($stream) is untyped (@param resource) — no type override
            // ext/dom/php_dom.stub.php — string $path / int $options = 0 / ?string $overrideEncoding = null (#27924)
            'dom\\htmldocument::createfromfile',
            'dom\\xmldocument::createfromfile' => match ($index) {
                0 => 'string',
                1 => 'int',
                2 => '?string',
                default => null,
            },
            // ext/fileinfo/fileinfo.stub.php — ?string $magic_database = null (InternalArgInfo string) (#26181)
            'finfo::__construct' => 1 === $index ? '?string' : null,
            // ext/bcmath/bcmath.stub.php — string|int $num (InternalArgInfo empty) (#24626)
            'bcmath\\number::__construct' => 0 === $index ? 'string|int' : null,
            // ext/date/php_date.stub.php — untyped UNKNOWN params (InternalArgInfo object/DateInterval/int) (#25164)
            'dateperiod::__construct' => '',
            // ext/date/php_date.stub.php — PHP 8.4+ createFromISO8601String (absent from InternalArgInfo) (#27923)
            'dateperiod::createfromiso8601string' => match ($index) {
                0 => 'string',
                1 => 'int',
                default => null,
            },
            // ext/intl/resourcebundle/resourcebundle.stub.php — ?string $locale / ?string $bundle (#25056)
            'resourcebundle::__construct' => ($index === 0 || $index === 1) ? '?string' : null,
            'resourcebundle::create' => ($index === 0 || $index === 1) ? '?string' : null,
            default => null,
        };
    }

    public static function methodIsVariadic(string $class, string $method): bool
    {
        $classLc = strtolower($class);
        $methodLc = strtolower($method);
        $methods = self::instance()->methods[$classLc]['methods'] ?? [];
        $info = $methods[$methodLc] ?? null;
        if (null === $info) {
            return false;
        }
        foreach ($info['params'] ?? [] as $param) {
            $name = $param['name'] ?? '';
            if (str_starts_with($name, '...')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Index of the `...$name` param in InternalArgInfo, or null (#22825).
     *
     * Note: legacy arginfo may keep a pre-variadic optional (sprintf arg1= + ...=);
     * {@see BuiltinParamNames::variadicParamIndexForFunction()} prefers Zend stub arity.
     */
    public static function variadicParamIndexForFunction(string $name): ?int
    {
        $lc = strtolower($name);
        if (str_contains($lc, '::')) {
            [$classLc, $methodLc] = explode('::', $lc, 2);
            $methods = self::instance()->methods[$classLc]['methods'] ?? [];
            $info = $methods[$methodLc] ?? null;
        } else {
            $info = self::instance()->functions[$lc] ?? null;
        }
        if (null === $info) {
            return null;
        }
        foreach ($info['params'] as $index => $param) {
            $paramName = (string) ($param['name'] ?? '');
            if (str_ends_with($paramName, '=')) {
                $paramName = substr($paramName, 0, -1);
            }
            if (str_starts_with($paramName, '&')) {
                $paramName = substr($paramName, 1);
            }
            if (str_starts_with($paramName, '...')) {
                return $index;
            }
        }

        return null;
    }

    private static ?InternalArgInfo $argInfo = null;

    public static function typeStringAllowsNull(string $type): bool
    {
        $type = trim($type);
        if ('' === $type) {
            return true;
        }
        if (str_starts_with($type, '?')) {
            return true;
        }
        // Explicit `mixed` includes null (php-src ReflectionNamedType::allowsNull).
        if ('mixed' === strtolower($type)) {
            return true;
        }
        if (str_contains($type, '|')) {
            foreach (explode('|', $type) as $member) {
                $member = strtolower(trim($member));
                if ('null' === $member || 'mixed' === $member) {
                    return true;
                }
            }
        }

        return false;
    }

    public static function typeStringAllowsPassByValueWithByRef(string $type): bool
    {
        $type = trim($type);
        if ('' === $type || 'mixed' === strtolower($type)) {
            return true;
        }
        if (str_starts_with($type, '?')) {
            $type = substr($type, 1);
        }
        foreach (explode('|', $type) as $member) {
            $member = trim($member);
            if ('null' === strtolower($member)) {
                continue;
            }
            if (!self::isScalarInternalTypeName($member)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{name: string, type: string} $param
     *
     * @return array{name: string, type: string, isOptional: bool}
     */
    /**
     * @param list<array{name: string, type: string}> $params
     */
    private static function requiredParamCountFromRawParams(array $params): int
    {
        $required = 0;
        foreach ($params as $param) {
            $name = $param['name'] ?? '';
            if (str_ends_with($name, '=') || str_starts_with($name, '...')) {
                break;
            }
            ++$required;
        }

        return $required;
    }

    private static function normalizeParamInfo(array $param): array
    {
        $name = $param['name'];
        $isOptional = str_ends_with($name, '=');
        if ($isOptional) {
            $name = substr($name, 0, -1);
        }
        if (str_starts_with($name, '...')) {
            $name = substr($name, 3);
            $isOptional = true;
        }

        return [
            'name' => $name,
            'type' => $param['type'],
            'isOptional' => $isOptional,
        ];
    }

    private static function isScalarInternalTypeName(string $name): bool
    {
        return \in_array(strtolower($name), [
            'int', 'float', 'string', 'bool', 'array', 'callable', 'iterable',
            'resource', 'void', 'never', 'true', 'false', 'object', 'mixed',
            'self', 'parent', 'static',
        ], true);
    }

    private static function instance(): InternalArgInfo
    {
        return self::$argInfo ??= new InternalArgInfo();
    }
}
