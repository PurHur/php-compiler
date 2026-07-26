<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * PHP parameter names for VM builtins (named arguments, issue #168).
 */
final class BuiltinParamNames
{
    /**
     * php-src stub parameter names for VM builtin class methods (#11785, ext/date/php_date.stub.php).
     *
     * @return list<string>|null
     */
    public static function forClassMethod(string $qualifiedMethod): ?array
    {
        return match (strtolower($qualifiedMethod)) {
            'datetime::createfromformat',
            'datetimeimmutable::createfromformat' => ['format', 'datetime', 'timezone'],
            'datetime::__construct',
            'datetimeimmutable::__construct' => ['datetime', 'timezone'],
            'datetime::format' => ['format'],
            'datetimeimmutable::format' => ['format'],
            'datetimezone::__construct' => ['timezone'],
            'arrayobject::__construct' => ['array', 'flags', 'iterator_class'],
            'splfileobject::seek' => ['line'],
            'splfileobject::fgetcsv' => ['separator', 'enclosure', 'escape'],
            'splfileobject::fputcsv' => ['fields', 'separator', 'enclosure', 'escape', 'eol'],
            'collator::create' => ['locale'],
            'collator::compare' => ['string1', 'string2'],
            'collator::asort' => ['array', 'flags'],
            'messageformatter::create' => ['locale', 'pattern'],
            'messageformatter::format' => ['args'],
            'messageformatter::setpattern' => ['pattern'],
            'messageformatter::getpattern' => [],
            'messageformatter::formatmessage' => ['locale', 'pattern', 'args'],
            'transliterator::create' => ['id', 'direction'],
            'transliterator::transliterate' => ['string', 'start', 'end'],
            'resourcebundle::create' => ['locale', 'bundlename', 'fallback'],
            'resourcebundle::get' => ['index'],
            'resourcebundle::count' => [],
            // php-src timezone.stub.php — ICU≥74 (#21553)
            'intltimezone::getianaid' => ['zoneId'],
            default => null,
        };
    }

    public static function forFunction(string $name): ?array
    {
        $classMethod = self::forClassMethod($name);
        if (null !== $classMethod) {
            return $classMethod;
        }

        $lc = strtolower($name);
        switch ($lc) {
            case 'intltz_get_iana_id':
                return ['zoneId'];
            case 'strlen':
            case 'ucfirst':
            case 'lcfirst':
            case 'strtoupper':
            case 'strtolower':
            case 'addslashes':
            case 'bin2hex':
                return ['string'];
            case 'substr':
                return \PHPCompiler\CompilerVersion::supportsSubstrTruncate()
                    ? ['string', 'offset', 'length', 'truncate']
                    : ['string', 'offset', 'length'];
            // php-src ext/standard/string.stub.php — InternalArgInfo still says cut (#23191)
            case 'wordwrap':
                return ['string', 'width', 'break', 'cut_long_words'];
            case 'date':
                return ['format', 'timestamp'];
            // php-src ext/date/php_date.stub.php — InternalArgInfo still says time/now (#23216)
            case 'strtotime':
                return ['datetime', 'baseTimestamp'];
            // php-src ext/date/php_date.stub.php — Reflection had empty params (#23276)
            case 'date_create':
            case 'date_create_immutable':
                return ['datetime', 'timezone'];
            // php-src ext/date/php_date.stub.php — InternalArgInfo still says min/sec/mon (#23275)
            case 'mktime':
            case 'gmmktime':
                return ['hour', 'minute', 'second', 'month', 'day', 'year'];
            case 'array_all':
            case 'array_any':
            case 'array_all_key':
            case 'array_any_key':
            case 'array_find':
            case 'array_find_key':
                return ['array', 'callback', 'strict'];
            case 'str_pad':
                return ['string', 'length', 'pad_string', 'pad_type'];
            case 'str_replace':
            case 'str_ireplace':
                return ['search', 'replace', 'subject', 'count'];
            case 'parse_str':
                return \PHPCompiler\CompilerVersion::supportsParseStrSeparator()
                    ? ['string', 'result', 'separator']
                    : ['string', 'result'];
            case 'mb_parse_str':
                return ['string', 'result'];
            case 'dns_get_mx':
            case 'getmxrr':
                return ['hostname', 'mxhosts', 'weight'];
            case 'sort':
            case 'rsort':
                return ['array', 'flags', 'direction'];
            case 'asort':
            case 'arsort':
            case 'ksort':
            case 'krsort':
            case 'natsort':
            case 'natcasesort':
                return ['array', 'flags'];
            case 'usort':
            case 'uasort':
            case 'uksort':
                return \PHPCompiler\CompilerVersion::supportsSortingEnum()
                    ? ['array', 'callback', 'direction']
                    : ['array', 'callback'];
            case 'shuffle':
            case 'array_sum':
            case 'array_product':
                return ['array'];
            case 'array_push':
                return ['array', 'values'];
            case 'array_unshift':
                return ['array', 'values'];
            case 'array_pop':
            case 'array_shift':
            case 'current':
            case 'end':
            case 'key':
            case 'next':
            case 'prev':
            case 'reset':
                return ['array'];
            case 'array_replace':
            case 'array_replace_recursive':
                return ['array', 'replacements'];
            case 'array_walk':
            case 'array_walk_recursive':
                return ['array', 'callback', 'arg'];
            case 'array_slice':
                return ['array', 'offset', 'length', 'preserve_keys'];
            case 'array_splice':
                return ['array', 'offset', 'length', 'replacement'];
            case 'array_multisort':
                return ['array', 'rest'];
            case 'array_map':
                return ['callback', 'array', 'arrays'];
            case 'array_filter':
                return ['array', 'callback', 'mode'];
            case 'array_reduce':
                return ['array', 'callback', 'initial'];
            case 'array_pad':
                return \PHPCompiler\CompilerVersion::supportsArrayPadPadType()
                    ? ['array', 'length', 'value', 'pad_type']
                    : ['array', 'length', 'value'];
            case 'array_combine':
                return ['keys', 'values'];
            case 'array_chunk':
                return ['array', 'length', 'preserve_keys'];
            case 'similar_text':
                return ['string1', 'string2', 'percent'];
            case 'levenshtein':
                return ['string1', 'string2', 'insertion_cost', 'replacement_cost', 'deletion_cost'];
            case 'settype':
                return ['var', 'type'];
            case 'register_shutdown_function':
                return ['function', 'parameter'];
            case 'header':
                return ['header', 'replace', 'response_code'];
            case 'header_register_callback':
                return ['callback'];
            case 'headers_sent':
                return ['filename', 'line'];
            case 'number_format':
                return ['num', 'decimals', 'decimal_separator', 'thousands_separator'];
            case 'modf':
                return ['num', 'num2'];
            case 'round':
                return ['num', 'precision', 'mode'];
            case 'frexp':
                return ['arg1', 'exp'];
            case 'ldexp':
                return ['num', 'exp'];
            case 'clearstatcache':
                return ['clear_realpath_cache', 'filename'];
            case 'mkdir':
                return ['directory', 'permissions', 'recursive'];
            case 'sem_get':
                // php-src ext/sysvsem/sysvsem.stub.php (#19515)
                return ['key', 'max_acquire', 'permissions', 'auto_release'];
            case 'msg_get_queue':
                // php-src ext/sysvmsg/sysvmsg.stub.php (#3666)
                return ['key', 'permissions'];
            case 'msg_send':
                return ['queue', 'message_type', 'message', 'serialize', 'blocking', 'error_code'];
            case 'msg_receive':
                return [
                    'queue',
                    'desired_message_type',
                    'received_message_type',
                    'max_message_size',
                    'message',
                    'unserialize',
                    'flags',
                    'error_code',
                ];
            case 'msg_remove_queue':
            case 'msg_stat_queue':
                return ['queue'];
            case 'msg_queue_exists':
                return ['key'];
            case 'spl_autoload_register':
                return ['callback', 'throw', 'prepend'];
            case 'touch':
                return ['filename', 'mtime', 'atime'];
            case 'token_get_all':
                return ['code', 'flags'];
            case 'getenv':
                return ['name', 'local_only'];
            case 'ini_get':
                return ['option'];
            case 'ini_set':
                return ['option', 'value'];
            case 'define':
                return ['constant_name', 'value', 'case_insensitive'];
            case 'vsprintf':
                return ['format', 'args'];
            case 'sprintf':
            case 'printf':
                // Zend stub: format + ...values (#22825); arity via zendInternalVariadicReflectionArity.
                return ['format', 'values'];
            case 'sscanf':
                return ['string', 'format', 'vars'];
            case 'vfscanf':
            case 'fscanf':
                return ['stream', 'format', 'vars'];
            case 'fprintf':
                return ['stream', 'format', 'values'];
            case 'pack':
                return ['format', 'values'];
            case 'array_merge':
            case 'array_merge_recursive':
                return ['arrays'];
            case 'max':
            case 'min':
                return ['value', 'values'];
            case 'fread':
                return ['stream', 'length'];
            case 'fwrite':
            case 'fputs':
                return ['stream', 'data', 'length'];
            case 'socket_select':
                return ['read', 'write', 'except', 'seconds', 'microseconds'];
            case 'fputcsv':
                return ['stream', 'fields', 'separator', 'enclosure', 'escape', 'eol'];
            case 'stream_context_create':
                return ['options', 'params'];
            case 'stream_copy_to_stream':
                return ['from', 'to', 'length', 'offset'];
            case 'stream_socket_client':
                return ['address', 'error_code', 'error_message', 'timeout', 'flags', 'context'];
            case 'flock':
                return ['stream', 'operation', 'wouldblock'];
            case 'get_resources':
                return ['resource_type'];
            case 'get_browser':
                return ['browser_name', 'return_array'];
            case 'get_defined_constants':
                return \PHPCompiler\CompilerVersion::supportsGetDefinedConstantsCategory()
                    ? ['categorize', 'category']
                    : ['categorize'];
            case 'get_declared_classes':
            case 'get_declared_interfaces':
            case 'get_declared_traits':
                return \PHPCompiler\CompilerVersion::supportsGetDeclaredExcludeDeprecated()
                    ? ['exclude_deprecated']
                    : [];
            case 'get_defined_functions':
                return \PHPCompiler\CompilerVersion::supportsGetDefinedFunctionsExcludeDisabled()
                    ? ['exclude_disabled']
                    : [];
            case 'fdiv':
                return \PHPCompiler\CompilerVersion::supportsRoundingModeEnum()
                    ? ['num1', 'num2', 'rounding_mode']
                    : ['num1', 'num2'];
            case 'bcadd':
            case 'bcsub':
            case 'bcmul':
            case 'bcdiv':
            case 'bcmod':
                return \PHPCompiler\CompilerVersion::supportsRoundingModeEnum()
                    ? ['num1', 'num2', 'scale', 'rounding_mode']
                    : ['num1', 'num2', 'scale'];
            case 'bcpowmod':
                return \PHPCompiler\CompilerVersion::supportsRoundingModeEnum()
                    ? ['num', 'exponent', 'modulus', 'scale', 'rounding_mode']
                    : ['num', 'exponent', 'modulus', 'scale'];
            case 'fpow':
                return \PHPCompiler\CompilerVersion::supportsRoundingModeEnum()
                    ? ['num', 'exponent', 'rounding_mode']
                    : ['num', 'exponent'];
            case 'intdiv':
                return ['num1', 'num2'];
            case 'atan2':
                return ['y', 'x'];
            case 'hypot':
                return ['x', 'y'];
            case 'random_int':
                return ['min', 'max'];
            case 'hex2bin':
                return \PHPCompiler\CompilerVersion::supportsHex2binStrict()
                    ? ['string', 'strict']
                    : ['string'];
            case 'unpack':
                return ['format', 'string', 'offset'];
            case 'openssl_cipher_iv_length':
            case 'openssl_cipher_key_length':
                return ['cipher_algo'];
            case 'openssl_encrypt':
                // php-src ext/openssl/openssl.stub.php (#21135)
                return ['data', 'cipher_algo', 'passphrase', 'options', 'iv', 'tag', 'aad', 'tag_length'];
            case 'openssl_decrypt':
                return ['data', 'cipher_algo', 'passphrase', 'options', 'iv', 'tag', 'aad'];
            case 'openssl_cms_verify':
                // php-src ext/openssl/openssl.stub.php (#22368, re-#6592)
                return [
                    'input_filename',
                    'flags',
                    'certificates',
                    'ca_info',
                    'untrusted_certificates_filename',
                    'content',
                    'pk7',
                    'sigfile',
                    'encoding',
                ];
            case 'openssl_cms_sign':
                return [
                    'input_filename',
                    'output_filename',
                    'certificate',
                    'private_key',
                    'headers',
                    'flags',
                    'encoding',
                    'untrusted_certificates_filename',
                ];
            case 'hash':
                return ['algo', 'data', 'binary', 'options'];
            case 'hash_hmac':
                return ['algo', 'data', 'key', 'binary'];
            case 'hash_pbkdf2':
                return ['algo', 'password', 'salt', 'iterations', 'length', 'binary'];
            // php-src ext/hash/hash.stub.php — Reflection was empty without this map (#23205)
            case 'hash_equals':
                return ['known_string', 'user_string'];
            case 'base64_decode':
                return ['string', 'strict'];
            case 'resetaslazyghost':
                return ['object', 'initializer', 'options'];
            case 'exit':
            case 'die':
                return ['status', 'message'];
            case 'http_build_query':
                return ['data', 'numeric_prefix', 'arg_separator', 'encoding_type'];
            case 'json_encode':
                return ['value', 'flags', 'depth'];
            case 'json_decode':
                return ['json', 'associative', 'depth', 'flags'];
            case 'filter_var':
                return ['value', 'filter', 'options'];
            case 'filter_input':
                return ['type', 'variable_name', 'filter', 'options'];
            case 'explode':
                return ['separator', 'string', 'limit'];
            case 'implode':
            case 'join':
                return ['separator', 'array'];
            case 'nl2br':
                return ['string', 'use_xhtml'];
            case 'str_contains':
            case 'str_starts_with':
            case 'str_ends_with':
                return ['haystack', 'needle'];
            // php-src ext/standard/string.stub.php — named dispatch uses forFunction (#23182, re-#16616)
            case 'strpos':
            case 'stripos':
            case 'strrpos':
                return ['haystack', 'needle', 'offset'];
            case 'strstr':
            case 'strchr':
                // InternalArgInfo: strstr uses `part`, strchr omits 3rd; Zend stub is before_needle (#23218)
                return ['haystack', 'needle', 'before_needle'];
            case 'preg_match':
                return ['pattern', 'subject', 'matches', 'flags', 'offset'];
            case 'preg_match_all':
                return ['pattern', 'subject', 'matches', 'flags', 'offset'];
            case 'preg_split':
                return ['pattern', 'subject', 'limit', 'flags'];
            case 'preg_replace':
            case 'preg_filter':
                return ['pattern', 'replacement', 'subject', 'limit', 'count'];
            case 'preg_replace_callback':
                // php-src ext/pcre/php_pcre.c — pattern/callback/subject/limit/count/flags (#19637, #19697)
                return ['pattern', 'callback', 'subject', 'limit', 'count', 'flags'];
            case 'preg_replace_callback_array':
                // php-src ext/pcre/php_pcre.c — pattern/subject/limit/count/flags (#19697)
                return ['pattern', 'subject', 'limit', 'count', 'flags'];
            case 'preg_grep':
                return ['pattern', 'array', 'flags'];
            case 'preg_quote':
                return ['str', 'delimiter'];
            case 'file_get_contents':
                return ['filename', 'use_include_path', 'context', 'offset', 'length'];
            case 'file_put_contents':
                return ['filename', 'data', 'flags', 'context'];
            case 'fopen':
                return ['filename', 'mode', 'use_include_path', 'context'];
            case 'stream_get_contents':
                return ['stream', 'length', 'offset'];
            case 'fgets':
            case 'fgetss':
                return ['stream', 'length'];
            case 'fgetcsv':
                return ['stream', 'length', 'separator', 'enclosure', 'escape'];
            case 'str_getcsv':
                return ['string', 'separator', 'enclosure', 'escape'];
            case 'parse_ini_string':
                return ['ini_string', 'process_sections', 'scanner_mode'];
            case 'parse_ini_file':
                return ['filename', 'process_sections', 'scanner_mode'];
            case 'parse_url':
                return ['url', 'component'];
            case 'proc_open':
                return ['command', 'descriptor_spec', 'pipes', 'cwd', 'env', 'options'];
            case 'proc_get_status':
            case 'proc_close':
                return ['process'];
            case 'proc_terminate':
                return ['process', 'signal'];
            case 'getopt':
                return ['short_options', 'long_options', 'rest_index'];
            case 'call_user_func':
                return ['callback'];
            case 'call_user_func_array':
                return ['callback', 'args'];
            case 'is_callable':
                return ['value', 'syntax_only', 'callable_name'];
            case 'get_class':
                return \PHPCompiler\CompilerVersion::supportsGetClassAllowString()
                    ? ['object', 'allow_string']
                    : ['object'];
            case 'get_parent_class':
                return \PHPCompiler\CompilerVersion::supportsGetClassAllowString()
                    ? ['object_or_class', 'allow_string']
                    : ['object_or_class'];
            case 'class_exists':
                return ['class', 'autoload'];
            case 'interface_exists':
                return ['interface', 'autoload'];
            case 'trait_exists':
                return ['trait', 'autoload'];
            case 'enum_exists':
                return ['enum', 'autoload'];
            case 'class_parents':
            case 'class_implements':
            case 'class_uses':
            case 'class_uses_recursive':
                return ['object_or_class', 'autoload'];
            case 'is_subclass_of':
            case 'is_a':
                return ['object_or_class', 'class', 'allow_string'];
            case 'iterator_to_array':
                return ['iterator', 'preserve_keys'];
            case 'generator_to_array':
                return ['generator', 'preserve_keys'];
            case 'hrtime':
                return ['as_number'];
            case 'memory_get_usage':
            case 'memory_get_peak_usage':
                return ['real_usage'];
            case 'microtime':
            case 'gettimeofday':
                return ['as_float'];
            case 'sleep':
                return ['seconds'];
            case 'usleep':
                return ['microseconds'];
            case 'http_response_code':
                return ['response_code'];
            case 'setcookie':
                return ['name', 'value', 'expires', 'path', 'domain', 'secure', 'httponly'];
            case 'trim':
            case 'ltrim':
            case 'rtrim':
                // php-src basic_functions.stub.php — no $mode; StringTrimMode is not in php-src (#23224)
                return ['string', 'characters'];
            case 'mb_strlen':
                return ['string', 'encoding'];
            case 'mb_substr':
                return \PHPCompiler\CompilerVersion::supportsSubstrTruncate()
                    ? ['string', 'start', 'length', 'encoding', 'truncate']
                    : ['string', 'start', 'length', 'encoding'];
            case 'mb_strcut':
                return ['string', 'start', 'length', 'encoding'];
            case 'mb_stripos':
            case 'mb_strpos':
            case 'mb_strripos':
            case 'mb_strrpos':
                return ['haystack', 'needle', 'offset', 'encoding'];
            case 'mb_strstr':
            case 'mb_stristr':
            case 'mb_strrchr':
            case 'mb_strrichr':
                return ['haystack', 'needle', 'part', 'encoding'];
            case 'mb_strimwidth':
                return ['string', 'start', 'width', 'trimmarker', 'encoding'];
            case 'mb_convert_encoding':
                return ['string', 'to_encoding', 'from_encoding'];
            case 'mb_trim':
            case 'mb_ltrim':
            case 'mb_rtrim':
                return ['string', 'characters', 'encoding'];
            case 'htmlspecialchars':
            case 'htmlentities':
                return ['string', 'flags', 'encoding', 'double_encode'];
            case 'version_compare':
                return ['version1', 'version2', 'operator'];
            case 'in_array':
                return ['needle', 'haystack', 'strict'];
            case 'array_search':
                return ['needle', 'haystack', 'strict'];
            case 'array_rand':
                return ['array', 'num'];
            case 'array_column':
                return ['array', 'column_key', 'index_key'];
            case 'debug_backtrace':
            case 'get_debug_backtrace':
                return ['options', 'limit'];
            case 'pathinfo':
                return ['path', 'flags'];
            case 'dirname':
                return ['path', 'levels'];
            // php-src ext/standard/file.stub.php / basic_functions.stub.php / array.stub.php (#23193)
            case 'basename':
                return ['path', 'suffix'];
            case 'uniqid':
                return ['prefix', 'more_entropy'];
            case 'gettype':
                // InternalArgInfo still says `var`; Zend stub is value
                return ['value'];
            // php-src Zend/zend_builtin_functions.stub.php + basic_functions.stub.php (#23263)
            // InternalArgInfo still says `var` (or empty Reflection) for these; Zend stubs use value/num
            case 'get_debug_type':
                return ['value'];
            case 'count':
            case 'sizeof':
                return ['value', 'mode'];
            case 'is_string':
            case 'is_array':
            case 'is_bool':
            case 'is_int':
            case 'is_integer':
            case 'is_long':
            case 'is_float':
            case 'is_double':
            case 'is_null':
            case 'is_object':
            case 'is_resource':
            case 'is_countable':
            case 'is_iterable':
            case 'is_numeric':
            case 'is_scalar':
                return ['value'];
            case 'is_finite':
            case 'is_infinite':
            case 'is_nan':
                return ['num'];
            case 'array_key_exists':
            case 'key_exists':
                // InternalArgInfo still says `search`; Zend stub is array
                return ['key', 'array'];
            case 'extract':
                return ['array', 'flags', 'prefix'];
            case 'file':
                return ['filename', 'flags'];
            case 'glob':
                return ['pattern', 'flags'];
            case 'substr_compare':
                return ['haystack', 'needle', 'offset', 'length', 'case_insensitive'];
            // php-src ext/standard/string.stub.php — InternalArgInfo still says str/repl/start (#23183)
            case 'substr_replace':
                return ['string', 'replace', 'offset', 'length'];
            // php-src ext/standard/string.stub.php — InternalArgInfo still says input/mult (#23204)
            case 'str_repeat':
                return ['string', 'times'];
            // php-src ext/standard/basic_functions.stub.php — InternalArgInfo still says ascii (#23240)
            case 'chr':
                return ['codepoint'];
            // php-src ext/ctype/ctype.stub.php — InternalArgInfo still says c (#23192)
            case 'ctype_alnum':
            case 'ctype_alpha':
            case 'ctype_cntrl':
            case 'ctype_digit':
            case 'ctype_graph':
            case 'ctype_lower':
            case 'ctype_print':
            case 'ctype_punct':
            case 'ctype_space':
            case 'ctype_upper':
            case 'ctype_xdigit':
                return ['text'];
            case 'file_exists':
            case 'filesize':
            case 'filemtime':
            case 'fileatime':
            case 'filectime':
            case 'fileinode':
            case 'fileowner':
            case 'filegroup':
            case 'fileperms':
            case 'is_file':
            case 'is_dir':
            case 'is_readable':
            case 'is_writable':
            case 'is_writeable':
            case 'is_executable':
            case 'is_link':
            case 'stat':
            case 'lstat':
                return ['filename'];
            case 'msgfmt_create':
                return ['locale', 'pattern'];
            case 'msgfmt_format':
                return ['formatter', 'args'];
            case 'msgfmt_format_message':
                return ['locale', 'pattern', 'args'];
            case 'transliterator_create':
                return ['id', 'direction'];
            case 'transliterator_transliterate':
                return ['transliterator', 'string', 'start', 'end'];
        }

        return null;
    }

    /**
     * Parameter count for internal builtins (BuiltinParamNames first, then InternalArgInfo; #11453).
     *
     * Zend stub arity wins for known internal variadics (#22825).
     */
    public static function paramCountForInternalFunction(string $name): ?int
    {
        $meta = self::zendInternalVariadicReflectionArity($name);
        if (null !== $meta) {
            return $meta['total'];
        }
        $names = self::paramNamesForInternalFunction($name);
        if (null === $names) {
            return null;
        }
        $count = \count($names);
        $variadic = self::variadicParamIndexForFunction($name);
        if (null !== $variadic) {
            $count = max($count, $variadic + 1);
        }

        return $count;
    }

    /**
     * Parameter names for internal functions (explicit table first, InternalArgInfo fallback; #18337).
     *
     * @return list<string>|null
     */
    public static function paramNamesForInternalFunction(string $name): ?array
    {
        if (str_contains($name, '::')) {
            $explicit = self::forClassMethod(strtolower($name));
            if (null !== $explicit) {
                return $explicit;
            }
            [$class, $method] = explode('::', $name, 2);
            $fromArgInfo = BuiltinInternalArgInfo::paramNamesForClassMethod($class, $method);
            if ([] !== $fromArgInfo) {
                return $fromArgInfo;
            }

            return null;
        }

        $explicit = self::forFunction($name);
        if (null !== $explicit) {
            return $explicit;
        }
        $count = BuiltinInternalArgInfo::paramCountForFunction($name);
        if (null === $count) {
            return null;
        }
        $names = [];
        for ($i = 0; $i < $count; $i++) {
            $info = BuiltinInternalArgInfo::paramInfoForFunction($name, $i);
            if (null === $info) {
                return null;
            }
            $names[] = $info['name'];
        }

        return $names;
    }

    public static function paramCountForInternalMethod(string $class, string $method): ?int
    {
        $names = self::forClassMethod(strtolower($class).'::'.strtolower($method));
        if (null !== $names) {
            return \count($names);
        }

        return BuiltinInternalArgInfo::paramCountForClassMethod($class, $method);
    }

    public static function requiredParamCountForInternalFunction(string $name): ?int
    {
        $meta = self::zendInternalVariadicReflectionArity($name);
        if (null !== $meta) {
            return $meta['required'];
        }
        $names = self::forFunction($name);
        if (null !== $names) {
            // Bare name tables are for named-arg dispatch; optionality lives in
            // InternalArgInfo (`=` markers). Only trust names when they encode optionals (#23181).
            if (self::namesEncodeOptionalParams(array_values($names))) {
                $required = self::requiredParamCountFromNames(array_values($names));
                $variadic = self::variadicParamIndexForFunction($name);
                if (null !== $variadic) {
                    $required = min($required, $variadic);
                }

                return $required;
            }
            $fromArgInfo = BuiltinInternalArgInfo::requiredParamCountForFunction($name);
            if (null !== $fromArgInfo) {
                return $fromArgInfo;
            }

            return self::requiredParamCountFromNames(array_values($names));
        }

        return BuiltinInternalArgInfo::requiredParamCountForFunction($name);
    }

    public static function requiredParamCountForInternalMethod(string $class, string $method): ?int
    {
        $names = self::forClassMethod(strtolower($class).'::'.strtolower($method));
        if (null !== $names) {
            return self::requiredParamCountFromNames($names);
        }

        return BuiltinInternalArgInfo::requiredParamCountForClassMethod($class, $method);
    }

    /**
     * @param list<int|string> $names
     */
    private static function requiredParamCountFromNames(array $names): int
    {
        $required = 0;
        foreach ($names as $name) {
            $label = (string) $name;
            if (str_ends_with($label, '=') || str_starts_with($label, '...')) {
                break;
            }
            ++$required;
        }

        return $required;
    }

    /**
     * True when the name table marks optionals with trailing `=` or a `...` variadic (#23181).
     *
     * @param list<int|string> $names
     */
    private static function namesEncodeOptionalParams(array $names): bool
    {
        foreach ($names as $name) {
            $label = (string) $name;
            if (str_ends_with($label, '=') || str_starts_with($label, '...')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Variadic parameter index for builtins that accept ...$args (#10637, #22825).
     *
     * Prefer Zend stub reflection arity; fall back to InternalArgInfo `...` markers.
     */
    public static function variadicParamIndexForFunction(string $name): ?int
    {
        $meta = self::zendInternalVariadicReflectionArity($name);
        if (null !== $meta) {
            return $meta['index'];
        }

        return BuiltinInternalArgInfo::variadicParamIndexForFunction($name);
    }

    /**
     * php-src stub reflection arity for internal variadics (ext/standard/*.stub.php, #22825).
     *
     * Legacy InternalArgInfo often keeps pre-stub shapes (e.g. sprintf format+arg1+... → tot=3);
     * Zend ReflectionFunction reports the stub shape (format+...values → tot=2).
     *
     * @return array{index: int, required: int, total: int}|null
     */
    private static function zendInternalVariadicReflectionArity(string $name): ?array
    {
        return match (strtolower($name)) {
            'sprintf',
            'printf',
            'pack' => ['index' => 1, 'required' => 1, 'total' => 2],
            'fprintf',
            'sscanf',
            'fscanf',
            'vfscanf' => ['index' => 2, 'required' => 2, 'total' => 3],
            'array_merge',
            'array_merge_recursive' => ['index' => 0, 'required' => 0, 'total' => 1],
            'array_push',
            'array_unshift',
            'array_replace',
            'array_replace_recursive',
            'array_diff',
            'array_diff_assoc',
            'array_diff_key',
            'array_diff_uassoc',
            'array_diff_ukey',
            'array_intersect',
            'array_intersect_assoc',
            'array_intersect_key',
            'array_intersect_uassoc',
            'array_intersect_ukey',
            'array_udiff',
            'array_udiff_assoc',
            'array_udiff_uassoc',
            'array_uintersect',
            'array_uintersect_assoc',
            'array_uintersect_uassoc',
            'array_multisort',
            'call_user_func',
            'forward_static_call',
            'compact',
            'var_dump',
            'debug_zval_dump',
            'register_shutdown_function',
            'register_tick_function',
            'max',
            'min' => ['index' => 1, 'required' => 1, 'total' => 2],
            'array_map' => ['index' => 2, 'required' => 2, 'total' => 3],
            'setlocale' => ['index' => 2, 'required' => 2, 'total' => 3],
            'mb_convert_variables' => ['index' => 3, 'required' => 3, 'total' => 4],
            default => null,
        };
    }

    /**
     * php-src rejects all named parameters on these variadic array builtins (#11349).
     */
    public static function rejectsNamedParameters(string $name): bool
    {
        return match (strtolower($name)) {
            'array_replace',
            'array_merge',
            'array_replace_recursive',
            'array_merge_recursive',
            'pack' => true,
            default => false,
        };
    }

    /**
     * @throws \ArgumentCountError
     */
    public static function throwUnknownNamedParameterError(string $name): never
    {
        throw new \ArgumentCountError(strtolower($name).'() does not accept unknown named parameters');
    }

    /**
     * PHP 8.4+ named-parameter aliases (php-src arginfo alias tables).
     *
     * @return array<string, int> lowercase alias => parameter index
     */
    public static function aliasesForFunction(string $name): array
    {
        $lc = strtolower($name);
        if (str_contains($lc, '::')) {
            return self::aliasesForClassMethod($lc);
        }
        if ('implode' === $lc || 'join' === $lc) {
            // php-src InternalArgInfo glue/pieces; public stub names separator/array (#9985).
            return [
                'glue' => 0,
                'pieces' => 1,
            ];
        }
        if ('array_column' === $lc) {
            // php-src basic_functions.stub.php — public name `input` aliases internal `array` (#10042).
            return [
                'input' => 0,
            ];
        }
        if ('fgetcsv' === $lc) {
            // php-src 8.2 arginfo `delimiter` → 8.4 stub `separator` (#12018).
            return [
                'delimiter' => 2,
            ];
        }
        if ('str_getcsv' === $lc) {
            return [
                'delimiter' => 1,
            ];
        }

        return [];
    }

    /**
     * Public stub names that differ from internal arginfo (#11785, DateTime::createFromFormat datetime).
     *
     * @return array<string, int>
     */
    public static function aliasesForClassMethod(string $qualifiedMethod): array
    {
        $lc = strtolower($qualifiedMethod);
        if (str_ends_with($lc, '::createfromformat')) {
            return ['datetime' => 1];
        }
        if (str_ends_with($lc, '::fgetcsv') || str_ends_with($lc, '::fputcsv')) {
            // php-src arginfo `delimiter` → stub `separator` (#12018, #22097).
            return ['delimiter' => str_ends_with($lc, '::fputcsv') ? 1 : 0];
        }

        return [];
    }

    /**
     * @param list<string> $paramNames
     */
    public static function lookupNamedParamIndex(array $paramNames, string $namedParam, ?string $function = null): int|false
    {
        $lc = strtolower($namedParam);
        // InternalArgInfo may prefix by-ref params with '&' (e.g. '&count'); callers use bare names (#19697).
        $lowerNames = array_map(
            static fn (string $name): string => strtolower(ltrim($name, '&')),
            $paramNames
        );
        $idx = array_search($lc, $lowerNames, true);
        if (false !== $idx) {
            return $idx;
        }
        if (null !== $function) {
            $aliases = self::aliasesForFunction($function);
            if (isset($aliases[$lc])) {
                return $aliases[$lc];
            }
        }

        return false;
    }
}
