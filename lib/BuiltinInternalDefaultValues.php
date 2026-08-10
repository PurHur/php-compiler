<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\VM\Context;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\Variable;

/**
 * php-src internal parameter default values for ReflectionParameter (#18356).
 *
 * InternalArgInfo marks optional params with `=` but not whether reflection exposes
 * getDefaultValue() — mirror Zend via explicit tables + conservative inference
 * (ext/reflection/php_reflection.c — _reflection_parameter_get_default_value).
 */
final class BuiltinInternalDefaultValues
{
    /**
     * Optional internal params that must not report isDefaultValueAvailable (php-src).
     *
     * @var array<string, true>
     */
    private const NO_DEFAULT_AVAILABLE = [
        'array_walk::2' => true,
        'array_walk_recursive::2' => true,
        // php-src basic_functions.stub.php — mixed $value = UNKNOWN (#25845)
        'stream_context_set_option::3' => true,
    ];

    /**
     * Explicit Zend default materialization keyed by lowercase callable + param index.
     *
     * @var array<string, array<int, array{kind: string, value?: mixed}>>
     */
    private const EXPLICIT = [
        'arrayobject::__construct' => [
            0 => ['kind' => 'array'],
            1 => ['kind' => 'int', 'value' => 0],
            2 => ['kind' => 'string', 'value' => 'ArrayIterator'],
        ],
        // php-src resourcebundle.stub.php — bool $fallback = true (bool infer → false) (#25056, #25587)
        'resourcebundle::__construct' => [
            2 => ['kind' => 'bool', 'value' => true],
        ],
        'resourcebundle::create' => [
            2 => ['kind' => 'bool', 'value' => true],
        ],
        'resourcebundle_create' => [
            2 => ['kind' => 'bool', 'value' => true],
        ],
        'datetime::__construct' => [
            0 => ['kind' => 'string', 'value' => 'now'],
            1 => ['kind' => 'null'],
        ],
        'splfileobject::__construct' => [
            1 => ['kind' => 'string', 'value' => 'r'],
            2 => ['kind' => 'bool', 'value' => false],
            3 => ['kind' => 'null'],
        ],
        'htmlspecialchars' => [
            1 => ['kind' => 'int', 'value' => 11],
            2 => ['kind' => 'null'],
            3 => ['kind' => 'bool', 'value' => true],
        ],
        // php-src ext/standard/html.c / basic_functions.stub.php — ENT_QUOTES|ENT_SUBSTITUTE, encoding=null, double_encode=true (#24970)
        'htmlentities' => [
            1 => ['kind' => 'int', 'value' => 11],
            2 => ['kind' => 'null'],
            3 => ['kind' => 'bool', 'value' => true],
        ],
        // php-src ext/standard/basic_functions.stub.php — flags=ENT_QUOTES|ENT_SUBSTITUTE (#23265)
        'htmlspecialchars_decode' => [
            1 => ['kind' => 'int', 'value' => 11],
        ],
        // php-src ext/standard/basic_functions.stub.php — RoundingMode::HalfAwayFromZero (#28535)
        'round' => [
            1 => ['kind' => 'int', 'value' => 0],
            2 => ['kind' => 'enum_case', 'class' => 'RoundingMode', 'case' => 'HalfAwayFromZero'],
        ],
        // php-src ext/bcmath/bcmath.stub.php — RoundingMode::HalfAwayFromZero (#28566)
        'bcround' => [
            1 => ['kind' => 'int', 'value' => 0],
            2 => ['kind' => 'enum_case', 'class' => 'RoundingMode', 'case' => 'HalfAwayFromZero'],
        ],
        // php-src ext/standard/basic_functions.stub.php — flags=11, encoding=null (#23265)
        'html_entity_decode' => [
            1 => ['kind' => 'int', 'value' => 11],
            2 => ['kind' => 'null'],
        ],
        // php-src ext/intl/spoofchecker/spoofchecker.stub.php — &$errorCode = null (#25055)
        'spoofchecker::issuspicious' => [
            1 => ['kind' => 'null'],
        ],
        'spoofchecker::areconfusable' => [
            2 => ['kind' => 'null'],
        ],
        'array_search' => [
            2 => ['kind' => 'bool', 'value' => false],
        ],
        // php-src ext/standard/file.stub.php — ?int $length = null, int $offset = -1 (#25134)
        'stream_get_contents' => [
            1 => ['kind' => 'null'],
            2 => ['kind' => 'int', 'value' => -1],
        ],
        // php-src ext/standard/file.stub.php — string $ending = "" (#23921)
        'stream_get_line' => [
            2 => ['kind' => 'string', 'value' => ''],
        ],
        // php-src ext/standard/basic_functions.stub.php — string $mode = "a" (#25261)
        'php_uname' => [
            0 => ['kind' => 'string', 'value' => 'a'],
        ],
        // php-src ext/standard/basic_functions.stub.php — ?bool $exclude_disabled = true (#25277)
        'get_defined_functions' => [
            0 => ['kind' => 'bool', 'value' => true],
        ],
        // php-src ext/standard/basic_functions.stub.php — ?int $error_level = null (#25278)
        'error_reporting' => [
            0 => ['kind' => 'null'],
        ],
        // php-src ext/standard/basic_functions.stub.php — int $options = DEBUG_BACKTRACE_PROVIDE_OBJECT (1) (#25278)
        'debug_backtrace' => [
            0 => ['kind' => 'int', 'value' => 1],
        ],
        'get_debug_backtrace' => [
            0 => ['kind' => 'int', 'value' => 1],
        ],
        // php-src ext/standard/basic_functions.stub.php — bool $return = false (#23785)
        'highlight_string' => [
            1 => ['kind' => 'bool', 'value' => false],
        ],
        'highlight_file' => [
            1 => ['kind' => 'bool', 'value' => false],
        ],
        // php-src ext/sodium/sodium_*.stub.php — key="" / length=SODIUM_CRYPTO_GENERICHASH_BYTES (#24490)
        'sodium_crypto_generichash' => [
            1 => ['kind' => 'string', 'value' => ''],
            2 => ['kind' => 'int', 'value' => 32],
        ],
        'show_source' => [
            1 => ['kind' => 'bool', 'value' => false],
        ],
        // php-src ext/standard/string.stub.php — ?int $length = null (InternalArgInfo int → 0) (#25472)
        'substr_count' => [
            3 => ['kind' => 'null'],
        ],
        // php-src ext/standard/string.stub.php — ?string $delimiter = null (#25472)
        'preg_quote' => [
            1 => ['kind' => 'null'],
        ],
        // php-src ext/fileinfo/fileinfo.stub.php — ?string $magic_database = null; ? $context = null (#25471)
        'finfo_open' => [
            1 => ['kind' => 'null'],
        ],
        'finfo_file' => [
            3 => ['kind' => 'null'],
        ],
        'finfo_buffer' => [
            3 => ['kind' => 'null'],
        ],
        // php-src ext/fileinfo/fileinfo.stub.php — int $flags = 0, ?string $magic_database = null (#26181)
        'finfo::__construct' => [
            0 => ['kind' => 'int', 'value' => 0],
            1 => ['kind' => 'null'],
        ],
        // php-src ext/standard/basic_functions.stub.php — ?int $length = null (#23181, #25749)
        'substr' => [
            2 => ['kind' => 'null'],
        ],
        // php-src ext/mbstring/mbstring.stub.php — ?int $length = null, ?string $encoding = null (#25362)
        // InternalArgInfo length=int → 0; encoding=string → OPT without default.
        'mb_substr' => [
            2 => ['kind' => 'null'],
            3 => ['kind' => 'null'],
        ],
        // php-src ext/mbstring/mbstring.stub.php — ?string $characters = null, ?string $encoding = null (#26283)
        'mb_trim' => [
            1 => ['kind' => 'null'],
            2 => ['kind' => 'null'],
        ],
        'mb_ltrim' => [
            1 => ['kind' => 'null'],
            2 => ['kind' => 'null'],
        ],
        'mb_rtrim' => [
            1 => ['kind' => 'null'],
            2 => ['kind' => 'null'],
        ],
        // php-src ext/mbstring/mbstring.stub.php — ?string $encoding = null (#26282)
        'mb_ucfirst' => [
            1 => ['kind' => 'null'],
        ],
        'mb_lcfirst' => [
            1 => ['kind' => 'null'],
        ],
        'json_encode' => [
            1 => ['kind' => 'int', 'value' => 0],
            2 => ['kind' => 'int', 'value' => 512],
        ],
        'json_decode' => [
            1 => ['kind' => 'null'],
            2 => ['kind' => 'int', 'value' => 512],
            3 => ['kind' => 'int', 'value' => 0],
        ],
        // php-src ext/json/json.stub.php — depth=512, flags=0 (#23876)
        'json_validate' => [
            1 => ['kind' => 'int', 'value' => 512],
            2 => ['kind' => 'int', 'value' => 0],
        ],
        // php-src ext/curl/curl.stub.php — ?string $url = null (InternalArgInfo string OPT without default) (#26186)
        'curl_init' => [
            0 => ['kind' => 'null'],
        ],
        // php-src ext/sysvshm/sysvshm.stub.php — ?int $size = null, int $permissions = 0666 (#27943)
        // InternalArgInfo int OPT infers 0/0; Zend reflects NULL and 438.
        'shm_attach' => [
            1 => ['kind' => 'null'],
            2 => ['kind' => 'int', 'value' => 0666],
        ],
        'explode' => [
            2 => ['kind' => 'int', 'value' => \PHP_INT_MAX],
        ],
        // php-src ext/standard/string.stub.php — int $length = 1 (#25044)
        'str_split' => [
            1 => ['kind' => 'int', 'value' => 1],
        ],
        // php-src ext/standard/string.stub.php — ?array $array = null (#24811)
        // InternalArgInfo marks pieces/glue required without a nullable default.
        'implode' => [
            1 => ['kind' => 'null'],
        ],
        'join' => [
            1 => ['kind' => 'null'],
        ],
        // php-src ext/standard/basic_functions.stub.php — DNS_ANY / null / null / false (#23358)
        'dns_get_record' => [
            1 => ['kind' => 'int', 'value' => 268435456],
            2 => ['kind' => 'null'],
            3 => ['kind' => 'null'],
            4 => ['kind' => 'bool', 'value' => false],
        ],
        'checkdnsrr' => [
            1 => ['kind' => 'string', 'value' => 'MX'],
        ],
        'dns_check_record' => [
            1 => ['kind' => 'string', 'value' => 'MX'],
        ],
        // php-src basic_functions.stub.php — array &$weights = null (#23353)
        'getmxrr' => [
            2 => ['kind' => 'null'],
        ],
        'dns_get_mx' => [
            2 => ['kind' => 'null'],
        ],
        // php-src ext/standard/basic_functions.stub.php — separator/enclosure/escape string defaults (#24813)
        'str_getcsv' => [
            1 => ['kind' => 'string', 'value' => ','],
            2 => ['kind' => 'string', 'value' => '"'],
            3 => ['kind' => 'string', 'value' => '\\'],
        ],
        'preg_match' => [
            2 => ['kind' => 'null'],
            3 => ['kind' => 'int', 'value' => 0],
            4 => ['kind' => 'int', 'value' => 0],
        ],
        // php-src ext/pcre/php_pcre.stub.php — int $limit = -1; &$count = null (#24969)
        'preg_replace' => [
            3 => ['kind' => 'int', 'value' => -1],
            4 => ['kind' => 'null'],
        ],
        // php-src ext/pcntl/pcntl.stub.php — int $flags = 0; &$resource_usage = [] (#27849)
        'pcntl_waitpid' => [
            2 => ['kind' => 'int', 'value' => 0],
            3 => ['kind' => 'array'],
        ],
        'preg_filter' => [
            3 => ['kind' => 'int', 'value' => -1],
            4 => ['kind' => 'null'],
        ],
        'preg_replace_callback' => [
            3 => ['kind' => 'int', 'value' => -1],
            4 => ['kind' => 'null'],
            5 => ['kind' => 'int', 'value' => 0],
        ],
        'preg_replace_callback_array' => [
            2 => ['kind' => 'int', 'value' => -1],
            3 => ['kind' => 'null'],
            4 => ['kind' => 'int', 'value' => 0],
        ],
        'preg_split' => [
            2 => ['kind' => 'int', 'value' => -1],
            3 => ['kind' => 'int', 'value' => 0],
        ],
        // php-src ext/hash/hash.stub.php — binary=false, options=[] (#25068)
        'hash' => [
            2 => ['kind' => 'bool', 'value' => false],
            3 => ['kind' => 'array'],
        ],
        // php-src ext/standard/password.stub.php — array $options = [] (#23292)
        'password_needs_rehash' => [
            2 => ['kind' => 'array'],
        ],
        // php-src ext/standard/basic_functions.stub.php — array $options = [] (#23260)
        'unserialize' => [
            1 => ['kind' => 'array'],
        ],
        // php-src ext/hash/hash.stub.php — length=0, info="", salt="" (string defaults not inferred) (#25018)
        'hash_hkdf' => [
            2 => ['kind' => 'int', 'value' => 0],
            3 => ['kind' => 'string', 'value' => ''],
            4 => ['kind' => 'string', 'value' => ''],
        ],
        // php-src ext/openssl/openssl.stub.php — int $key_length = 0 (#27685)
        'openssl_pkey_derive' => [
            2 => ['kind' => 'int', 'value' => 0],
        ],
        // php-src ext/openssl/openssl.stub.php — &$iv = null / ?string $iv = null (#28754)
        'openssl_seal' => [
            5 => ['kind' => 'null'],
        ],
        'openssl_open' => [
            5 => ['kind' => 'null'],
        ],
        // php-src ext/intl/grapheme/grapheme.stub.php — ?int $length = null (InternalArgInfo int→0) (#27884)
        'grapheme_substr' => [
            2 => ['kind' => 'null'],
        ],
        // php-src grapheme.stub.php — bool $beforeNeedle = false
        'grapheme_strstr' => [
            2 => ['kind' => 'bool', 'value' => false],
        ],
        'grapheme_stristr' => [
            2 => ['kind' => 'bool', 'value' => false],
        ],
        // php-src grapheme.stub.php — type=0, offset=0, &$next = null
        'grapheme_extract' => [
            2 => ['kind' => 'int', 'value' => 0],
            3 => ['kind' => 'int', 'value' => 0],
            4 => ['kind' => 'null'],
        ],
        'grapheme_strpos' => [
            2 => ['kind' => 'int', 'value' => 0],
        ],
        'grapheme_stripos' => [
            2 => ['kind' => 'int', 'value' => 0],
        ],
        'grapheme_strrpos' => [
            2 => ['kind' => 'int', 'value' => 0],
        ],
        'grapheme_strripos' => [
            2 => ['kind' => 'int', 'value' => 0],
        ],
        // php-src ext/hash/hash.stub.php — length=0, binary=false, options=[] (#25469)
        'hash_pbkdf2' => [
            4 => ['kind' => 'int', 'value' => 0],
            5 => ['kind' => 'bool', 'value' => false],
            6 => ['kind' => 'array'],
        ],
        'openssl_encrypt' => [
            3 => ['kind' => 'int', 'value' => 0],
            4 => ['kind' => 'string', 'value' => ''],
            5 => ['kind' => 'null'],
            6 => ['kind' => 'string', 'value' => ''],
            7 => ['kind' => 'int', 'value' => 16],
        ],
        // php-src ext/openssl/openssl.stub.php — iv=""; tag=null; aad="" (string iv/aad have no infer) (#28593)
        'openssl_decrypt' => [
            3 => ['kind' => 'int', 'value' => 0],
            4 => ['kind' => 'string', 'value' => ''],
            5 => ['kind' => 'null'],
            6 => ['kind' => 'string', 'value' => ''],
        ],
        'array_slice' => [
            2 => ['kind' => 'null'],
            3 => ['kind' => 'bool', 'value' => false],
        ],
        // php-src ext/date/php_date.stub.php — ?int = null (InternalArgInfo int → 0) (#24845)
        'date' => [
            1 => ['kind' => 'null'],
        ],
        'gmdate' => [
            1 => ['kind' => 'null'],
        ],
        'strtotime' => [
            1 => ['kind' => 'null'],
        ],
        // php-src ext/date/php_date.stub.php — utcOffset/isDST = -1 (InternalArgInfo int → 0) (#26358)
        'timezone_name_from_abbr' => [
            1 => ['kind' => 'int', 'value' => -1],
            2 => ['kind' => 'int', 'value' => -1],
        ],
        // php-src ext/date/php_date.stub.php — ?int $timestamp = null (InternalArgInfo int → 0) (#25440)
        'idate' => [
            1 => ['kind' => 'null'],
        ],
        'getdate' => [
            0 => ['kind' => 'null'],
        ],
        // php-src ext/date/php_date.stub.php — ?int $timestamp = null (InternalArgInfo int → 0) (#27980)
        'localtime' => [
            0 => ['kind' => 'null'],
        ],
        // php-src ext/date/php_date.stub.php — ?int $timestamp = null (InternalArgInfo int → 0) (#27981)
        'strftime' => [
            1 => ['kind' => 'null'],
        ],
        'gmstrftime' => [
            1 => ['kind' => 'null'],
        ],
        // php-src ext/calendar/calendar.stub.php — ?int $timestamp = null (#24863)
        'unixtojd' => [
            0 => ['kind' => 'null'],
        ],
        // php-src ext/calendar/calendar.stub.php — ?int $year = null, int $mode = 0 (#28781)
        'easter_date' => [
            0 => ['kind' => 'null'],
            1 => ['kind' => 'int', 'value' => 0],
        ],
        'easter_days' => [
            0 => ['kind' => 'null'],
            1 => ['kind' => 'int', 'value' => 0],
        ],
        // php-src ext/standard/array.stub.php — callback=null, mode=0 (#24843)
        'array_filter' => [
            1 => ['kind' => 'null'],
            2 => ['kind' => 'int', 'value' => 0],
        ],
        // php-src ext/standard/array.stub.php — length=null, replacement=[] (#24824)
        // InternalArgInfo int length → 0; replacement typed array (ok) but length must be null.
        'array_splice' => [
            2 => ['kind' => 'null'],
            3 => ['kind' => 'array'],
        ],
        // php-src ext/standard/file.stub.php — permissions=0777, context=null (#24885)
        // InternalArgInfo int → 0; untyped context= has no inferrable default.
        'mkdir' => [
            1 => ['kind' => 'int', 'value' => 0777],
            3 => ['kind' => 'null'],
        ],
        // php-src ext/calendar/calendar.stub.php — int $calendar = -1 (#28907)
        // InternalArgInfo int → inferred 0; Zend default is the all-calendars sentinel.
        'cal_info' => [
            0 => ['kind' => 'int', 'value' => -1],
        ],
        // php-src ext/standard/basic_functions.stub.php — filename="" (#27998)
        // InternalArgInfo string= does not infer empty string for Reflection.
        'clearstatcache' => [
            1 => ['kind' => 'string', 'value' => ''],
        ],
        // php-src ext/standard/string.stub.php — &$count = null (InternalArgInfo int → 0) (#24886)
        'str_replace' => [
            3 => ['kind' => 'null'],
        ],
        'str_ireplace' => [
            3 => ['kind' => 'null'],
        ],
        // php-src ext/standard/string.stub.php — &$percent = null (InternalArgInfo float → 0.0) (#25361)
        'similar_text' => [
            2 => ['kind' => 'null'],
        ],
        // php-src ext/standard/string.stub.php — insertion/replacement/deletion_cost = 1 (#24791)
        // InternalArgInfo "levenshtein 1" marks cost params required with no defaults.
        'levenshtein' => [
            2 => ['kind' => 'int', 'value' => 1],
            3 => ['kind' => 'int', 'value' => 1],
            4 => ['kind' => 'int', 'value' => 1],
        ],
        // php-src Zend/zend_builtin_functions.stub.php — bool $autoload = true (#25388)
        // InternalArgInfo marks all params optional; bool infer defaults false.
        'class_alias' => [
            2 => ['kind' => 'bool', 'value' => true],
        ],
        // php-src Zend/zend_builtin_functions.stub.php — bool $allow_string = true (#25439)
        // Contrast is_a(..., bool $allow_string = false) which inference already matches.
        'is_subclass_of' => [
            2 => ['kind' => 'bool', 'value' => true],
        ],
        // php-src Zend/zend_builtin_functions.stub.php — bool $autoload = true (#25498)
        // InternalArgInfo marks autoload optional with bool infer → false.
        'class_implements' => [
            1 => ['kind' => 'bool', 'value' => true],
        ],
        'class_parents' => [
            1 => ['kind' => 'bool', 'value' => true],
        ],
        'class_uses' => [
            1 => ['kind' => 'bool', 'value' => true],
        ],
        // php-src ext/standard/basic_functions.stub.php — value/path/domain = "" (#24968)
        'setcookie' => [
            1 => ['kind' => 'string', 'value' => ''],
            2 => ['kind' => 'int', 'value' => 0],
            3 => ['kind' => 'string', 'value' => ''],
            4 => ['kind' => 'string', 'value' => ''],
            5 => ['kind' => 'bool', 'value' => false],
            6 => ['kind' => 'bool', 'value' => false],
        ],
        'setrawcookie' => [
            1 => ['kind' => 'string', 'value' => ''],
            2 => ['kind' => 'int', 'value' => 0],
            3 => ['kind' => 'string', 'value' => ''],
            4 => ['kind' => 'string', 'value' => ''],
            5 => ['kind' => 'bool', 'value' => false],
            6 => ['kind' => 'bool', 'value' => false],
        ],
        // php-src ext/standard/basic_functions.stub.php — int $offset = 0 (#24896)
        // InternalArgInfo omits offset; override has no type → need explicit default.
        'unpack' => [
            2 => ['kind' => 'int', 'value' => 0],
        ],
        // php-src ext/standard stubs — Reflection optional defaults cluster (#24971)
        'dirname' => [
            1 => ['kind' => 'int', 'value' => 1],
        ],
        'basename' => [
            1 => ['kind' => 'string', 'value' => ''],
        ],
        'http_build_query' => [
            1 => ['kind' => 'string', 'value' => ''],
            2 => ['kind' => 'null'],
            3 => ['kind' => 'int', 'value' => 1], // PHP_QUERY_RFC1738
        ],
        'chunk_split' => [
            1 => ['kind' => 'int', 'value' => 76],
            2 => ['kind' => 'string', 'value' => "\r\n"],
        ],
        'umask' => [
            0 => ['kind' => 'null'],
        ],
        'touch' => [
            1 => ['kind' => 'null'],
            2 => ['kind' => 'null'],
        ],
        'get_html_translation_table' => [
            0 => ['kind' => 'int', 'value' => 0],
            1 => ['kind' => 'int', 'value' => 11], // ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401
            2 => ['kind' => 'string', 'value' => 'UTF-8'],
        ],
        'version_compare' => [
            2 => ['kind' => 'null'],
        ],
        'getimagesize' => [
            1 => ['kind' => 'null'],
        ],
        // php-src ext/standard/image.stub.php — ?array &$image_info = null (#23681)
        'getimagesizefromstring' => [
            1 => ['kind' => 'null'],
        ],
        'session_set_cookie_params' => [
            1 => ['kind' => 'null'],
            2 => ['kind' => 'null'],
            3 => ['kind' => 'null'],
            4 => ['kind' => 'null'],
        ],
        // php-src ext/standard/basic_functions.stub.php — ?array = null (InternalArgInfo array → []) (#25069)
        'stream_context_create' => [
            0 => ['kind' => 'null'],
            1 => ['kind' => 'null'],
        ],
        // php-src ext/standard/file.stub.php — ?int $length = null (InternalArgInfo int → 0) (#24846)
        'fwrite' => [
            2 => ['kind' => 'null'],
        ],
        'fgets' => [
            1 => ['kind' => 'null'],
        ],
        // php-src ext/standard/file.stub.php — ?int length=null; separator/enclosure/escape strings (#24826)
        'fgetcsv' => [
            1 => ['kind' => 'null'],
            2 => ['kind' => 'string', 'value' => ','],
            3 => ['kind' => 'string', 'value' => '"'],
            4 => ['kind' => 'string', 'value' => '\\'],
        ],
        // php-src ext/standard/file.stub.php — separator/enclosure/escape/eol string defaults (#25135)
        'fputcsv' => [
            2 => ['kind' => 'string', 'value' => ','],
            3 => ['kind' => 'string', 'value' => '"'],
            4 => ['kind' => 'string', 'value' => '\\'],
            5 => ['kind' => 'string', 'value' => "\n"],
        ],
        // php-src ext/spl/spl_directory.stub.php — same CSV defaults; InternalArgInfo omits `=`/`eol` (#25135)
        'splfileobject::fputcsv' => [
            1 => ['kind' => 'string', 'value' => ','],
            2 => ['kind' => 'string', 'value' => '"'],
            3 => ['kind' => 'string', 'value' => '\\'],
            4 => ['kind' => 'string', 'value' => "\n"],
        ],
        // php-src ext/standard/file.stub.php — context=null, ?int length=null (#24814)
        'file_get_contents' => [
            2 => ['kind' => 'null'],
            4 => ['kind' => 'null'],
        ],
        // php-src ext/standard/file.stub.php — context=null (#25509)
        'file_put_contents' => [
            3 => ['kind' => 'null'],
        ],
        // Same context=null metadata hole as #24814/#24885
        'fopen' => [
            3 => ['kind' => 'null'],
        ],
        'rmdir' => [
            1 => ['kind' => 'null'],
        ],
        // php-src ext/spl/spl.stub.php — bool $preserve_keys = true (bool infer defaults false) (#25066)
        'iterator_to_array' => [
            1 => ['kind' => 'bool', 'value' => true],
        ],
        // php-src ext/standard/array.stub.php — int|float $step = 1 (InternalArgInfo int → 0) (#25070)
        'range' => [
            2 => ['kind' => 'int', 'value' => 1],
        ],
        // php-src ext/standard/basic_functions.stub.php — &$rest_index = null (missing from InternalArgInfo) (#25144)
        'getopt' => [
            2 => ['kind' => 'null'],
        ],
        // php-src ext/standard/string.stub.php — ?string $token = null (#25171)
        'strtok' => [
            1 => ['kind' => 'null'],
        ],
        // php-src ext/standard/basic_functions.stub.php — ?string $name = null, bool $local_only = false (#24855)
        'getenv' => [
            0 => ['kind' => 'null'],
            1 => ['kind' => 'bool', 'value' => false],
        ],
        // php-src ext/date/php_date.stub.php — timezoneGroup=DateTimeZone::ALL (2047), countryCode=null (#25172)
        'datetimezone::listidentifiers' => [
            0 => ['kind' => 'int', 'value' => 2047],
            1 => ['kind' => 'null'],
        ],
        // php-src ext/date/php_date.stub.php — same defaults; InternalArgInfo marks both required (#25173)
        'timezone_identifiers_list' => [
            0 => ['kind' => 'int', 'value' => 2047],
            1 => ['kind' => 'null'],
        ],
        // php-src ext/standard/basic_functions.stub.php — ?string $extension = null, bool $details = true (#25276)
        'ini_get_all' => [
            0 => ['kind' => 'null'],
            1 => ['kind' => 'bool', 'value' => true],
        ],
        // php-src ext/session/session.stub.php — ?string $id = null (InternalArgInfo string → no infer) (#26460)
        'session_id' => [
            0 => ['kind' => 'null'],
        ],
        // php-src ext/session/session.stub.php — string $prefix = "" (InternalArgInfo required) (#27725)
        'session_create_id' => [
            0 => ['kind' => 'string', 'value' => ''],
        ],
        // php-src ext/date/php_date.stub.php — string $datetime = "now", ?DateTimeZone $timezone = null (#25392)
        // Functions absent from InternalArgInfo entirely.
        'date_create' => [
            0 => ['kind' => 'string', 'value' => 'now'],
            1 => ['kind' => 'null'],
        ],
        'date_create_immutable' => [
            0 => ['kind' => 'string', 'value' => 'now'],
            1 => ['kind' => 'null'],
        ],
        // php-src ext/date/php_date.stub.php — ?DateTimeZone $timezone = null (#27773)
        'date_create_from_format' => [
            2 => ['kind' => 'null'],
        ],
        'date_create_immutable_from_format' => [
            2 => ['kind' => 'null'],
        ],
        // php-src ext/spl/spl.stub.php — ?callable=null, bool $throw=true, bool $prepend=false (#25390)
        // InternalArgInfo empty callback type + bool infer defaults throw to false.
        'spl_autoload_register' => [
            0 => ['kind' => 'null'],
            1 => ['kind' => 'bool', 'value' => true],
            2 => ['kind' => 'bool', 'value' => false],
        ],
        // php-src ext/date/php_date.stub.php — ?DateTimeZone $timezone = null (#25166)
        'datetime::createfromformat' => [
            2 => ['kind' => 'null'],
        ],
        'datetimeimmutable::createfromformat' => [
            2 => ['kind' => 'null'],
        ],
        // php-src ext/date/php_date.stub.php — int $options = 0 (#27923)
        'dateperiod::createfromiso8601string' => [
            1 => ['kind' => 'int', 'value' => 0],
        ],
        // php-src ext/date/php_date.stub.php — int $second = 0, int $microsecond = 0 (#25400)
        'datetime::settime' => [
            2 => ['kind' => 'int', 'value' => 0],
            3 => ['kind' => 'int', 'value' => 0],
        ],
        'datetimeimmutable::settime' => [
            2 => ['kind' => 'int', 'value' => 0],
            3 => ['kind' => 'int', 'value' => 0],
        ],
        // php-src Zend/zend_builtin_functions.stub.php — int $error_level = E_USER_NOTICE (1024) (#25174)
        // InternalArgInfo int → 0; user_error absent from InternalArgInfo entirely.
        'trigger_error' => [
            1 => ['kind' => 'int', 'value' => 1024],
        ],
        'user_error' => [
            1 => ['kind' => 'int', 'value' => 1024],
        ],
        // php-src Zend/zend_builtin_functions.stub.php — int $mode = 0; sizeof absent from InternalArgInfo (#25966)
        'sizeof' => [
            1 => ['kind' => 'int', 'value' => 0],
        ],
        // php-src ext/tokenizer/tokenizer.stub.php — int $flags = 0 (#26258)
        'token_get_all' => [
            1 => ['kind' => 'int', 'value' => 0],
        ],
        // php-src Zend/zend_builtin_functions.stub.php — string|int $status = 0 (union does not infer) (#26056)
        'exit' => [
            0 => ['kind' => 'int', 'value' => 0],
        ],
        'die' => [
            0 => ['kind' => 'int', 'value' => 0],
        ],
        // php-src ext/zlib/zlib.stub.php — level=-1; encoding=GZIP/DEFLATE/RAW (int infer → 0) (#25012)
        'gzencode' => [
            1 => ['kind' => 'int', 'value' => -1],
            2 => ['kind' => 'int', 'value' => 31], // ZLIB_ENCODING_GZIP
        ],
        'gzcompress' => [
            1 => ['kind' => 'int', 'value' => -1],
            2 => ['kind' => 'int', 'value' => 15], // ZLIB_ENCODING_DEFLATE
        ],
        'gzdeflate' => [
            1 => ['kind' => 'int', 'value' => -1],
            2 => ['kind' => 'int', 'value' => -15], // ZLIB_ENCODING_RAW
        ],
        // php-src ext/zlib/zlib.stub.php — int $max_length = 0 (#25132)
        'zlib_decode' => [
            1 => ['kind' => 'int', 'value' => 0],
        ],
        // php-src ext/zlib/zlib.stub.php — int $level = -1 (#25588)
        'zlib_encode' => [
            2 => ['kind' => 'int', 'value' => -1],
        ],
        // php-src ext/date/php_date.stub.php — ?int minute…year = null (InternalArgInfo int → 0) (#25147)
        'mktime' => [
            1 => ['kind' => 'null'],
            2 => ['kind' => 'null'],
            3 => ['kind' => 'null'],
            4 => ['kind' => 'null'],
            5 => ['kind' => 'null'],
        ],
        'gmmktime' => [
            1 => ['kind' => 'null'],
            2 => ['kind' => 'null'],
            3 => ['kind' => 'null'],
            4 => ['kind' => 'null'],
            5 => ['kind' => 'null'],
        ],
        // php-src ext/simplexml/simplexml.stub.php — class_name=SimpleXMLElement::class; ns="" (#25510)
        // ?string would infer null; plain string does not infer "" — both need explicit values.
        'simplexml_load_string' => [
            1 => ['kind' => 'string', 'value' => 'SimpleXMLElement'],
            3 => ['kind' => 'string', 'value' => ''],
        ],
        'simplexml_load_file' => [
            1 => ['kind' => 'string', 'value' => 'SimpleXMLElement'],
            3 => ['kind' => 'string', 'value' => ''],
        ],
        // php-src ext/simplexml/simplexml.stub.php — class_name=SimpleXMLElement::class (#26464)
        // ?string would otherwise infer null.
        'simplexml_import_dom' => [
            1 => ['kind' => 'string', 'value' => 'SimpleXMLElement'],
        ],
        // php-src ext/filter/filter.stub.php — int $filter = FILTER_DEFAULT (516), array|int $options = 0 (#25046)
        // InternalArgInfo int → 0; options untyped → no inferrable default.
        'filter_var' => [
            1 => ['kind' => 'int', 'value' => 516], // FILTER_DEFAULT
            2 => ['kind' => 'int', 'value' => 0],
        ],
        // php-src ext/filter/filter.stub.php — options=FILTER_DEFAULT, add_empty=true (#26184)
        'filter_var_array' => [
            1 => ['kind' => 'int', 'value' => 516], // FILTER_DEFAULT
            2 => ['kind' => 'bool', 'value' => true],
        ],
        // php-src ext/filter/filter.stub.php — filter=FILTER_DEFAULT, options=0 (#26184)
        'filter_input' => [
            2 => ['kind' => 'int', 'value' => 516], // FILTER_DEFAULT
            3 => ['kind' => 'int', 'value' => 0],
        ],
        // php-src ext/filter/filter.stub.php — options=FILTER_DEFAULT, add_empty=true (#26201)
        'filter_input_array' => [
            1 => ['kind' => 'int', 'value' => 516], // FILTER_DEFAULT
            2 => ['kind' => 'bool', 'value' => true],
        ],
        // php-src ext/standard/file.stub.php — ?bool &$would_block = null (InternalArgInfo int → 0) (#23352)
        'flock' => [
            2 => ['kind' => 'null'],
        ],
        // php-src ext/standard/dir.stub.php — sorting_order=0, context=null (#23448)
        'scandir' => [
            1 => ['kind' => 'int', 'value' => 0],
            2 => ['kind' => 'null'],
        ],
        // php-src ext/standard/basic_functions.stub.php — bool $associative = false; $context = null (#25780)
        'get_headers' => [
            1 => ['kind' => 'bool', 'value' => false],
            2 => ['kind' => 'null'],
        ],
        // php-src ext/standard/head.stub.php — &$filename = null, &$line = null (untyped; no infer) (#25780)
        'headers_sent' => [
            0 => ['kind' => 'null'],
            1 => ['kind' => 'null'],
        ],
        // php-src ext/xml/xml.stub.php — separator=":"; &$index = null (string/untyped; no infer) (#26687)
        'xml_parser_create_ns' => [
            1 => ['kind' => 'string', 'value' => ':'],
        ],
        'xml_parse_into_struct' => [
            3 => ['kind' => 'null'],
        ],
        // php-src ext/libxml/libxml.stub.php — ?bool $use_errors = null (bool infer → false) (#25844)
        'libxml_use_internal_errors' => [
            0 => ['kind' => 'null'],
        ],
        // php-src ext/libxml/libxml.stub.php — bool $disable = true (bool infer → false) (#28021)
        'libxml_disable_entity_loader' => [
            0 => ['kind' => 'bool', 'value' => true],
        ],
        // php-src ext/imap/php_imap.stub.php — int $timeout = -1 (int infer → 0) (#27680)
        'imap_timeout' => [
            1 => ['kind' => 'int', 'value' => -1],
        ],
        // php-src ext/imap/php_imap.stub.php — default_hostname="UNKNOWN" (string infer absent) (#27682)
        'imap_rfc822_parse_headers' => [
            1 => ['kind' => 'string', 'value' => 'UNKNOWN'],
        ],
        // php-src ext/standard/basic_functions.stub.php — int $component = -1; int $flags = PATHINFO_ALL (15)
        // InternalArgInfo optional int infer → 0 (#24857)
        'parse_url' => [
            1 => ['kind' => 'int', 'value' => -1],
        ],
        'pathinfo' => [
            1 => ['kind' => 'int', 'value' => 15],
        ],
        // php-src ext/standard/basic_functions.stub.php — port=-1; error outs/timeout null (#28919)
        'fsockopen' => [
            1 => ['kind' => 'int', 'value' => -1],
            2 => ['kind' => 'null'],
            3 => ['kind' => 'null'],
            4 => ['kind' => 'null'],
        ],
        'pfsockopen' => [
            1 => ['kind' => 'int', 'value' => -1],
            2 => ['kind' => 'null'],
            3 => ['kind' => 'null'],
            4 => ['kind' => 'null'],
        ],
    ];

    public static function isAvailable(
        string $callableLc,
        int $index,
        ?array $info,
        bool $isVariadic,
    ): bool {
        if (null === $info || !$info['isOptional'] || $isVariadic) {
            return false;
        }
        $key = $callableLc.'::'.$index;
        if (isset(self::NO_DEFAULT_AVAILABLE[$key])) {
            return false;
        }
        if (isset(self::EXPLICIT[$callableLc][$index])) {
            return true;
        }

        return null !== self::inferSpec($callableLc, $index, $info);
    }

    public static function materialize(
        Variable $dest,
        string $callableLc,
        int $index,
        ?array $info,
        ?Context $ctx = null,
    ): bool {
        if (null === $info) {
            return false;
        }
        $spec = self::EXPLICIT[$callableLc][$index] ?? self::inferSpec($callableLc, $index, $info);
        if (null === $spec) {
            return false;
        }
        // Profile-gate RoundingMode default — reference profile has no RoundingMode (#28535).
        if (($spec['kind'] ?? '') === 'enum_case'
            && 'RoundingMode' === ($spec['class'] ?? '')
            && !CompilerVersion::supportsRoundingModeEnum()
        ) {
            $spec = ['kind' => 'int', 'value' => 1]; // PHP_ROUND_HALF_UP
        }
        self::writeSpec($dest, $spec, $ctx);

        return true;
    }

    /**
     * @return array{kind: string, value?: mixed}|null
     */
    private static function inferSpec(string $callableLc, int $index, array $info): ?array
    {
        $type = strtolower(trim($info['type']));
        $name = strtolower($info['name']);

        if (str_contains($callableLc, '::') && str_ends_with($callableLc, '::__construct')) {
            if ('iterator_class' === $name) {
                return ['kind' => 'string', 'value' => 'ArrayIterator'];
            }
            if ('datetime' === $name) {
                return ['kind' => 'string', 'value' => 'now'];
            }
            if ('mode' === $name) {
                return ['kind' => 'string', 'value' => 'r'];
            }
            if ('flags' === $name && ('int' === $type || '' === $type)) {
                return ['kind' => 'int', 'value' => 0];
            }
            if ('array' === $type || self::isArrayLikeParamName($name)) {
                return ['kind' => 'array'];
            }
            if (self::typeIsNullable($type)) {
                return ['kind' => 'null'];
            }
        }

        if ('bool' === $type) {
            return ['kind' => 'bool', 'value' => false];
        }
        if ('int' === $type) {
            return ['kind' => 'int', 'value' => 0];
        }
        if ('float' === $type || 'double' === $type) {
            return ['kind' => 'float', 'value' => 0.0];
        }
        if ('array' === $type || self::isArrayLikeParamName($name)) {
            return ['kind' => 'array'];
        }
        if ('string' === $type && 'characters' === $name) {
            return ['kind' => 'string', 'value' => " \t\n\r\0\x0B"];
        }
        if (self::typeIsNullable($type)) {
            return ['kind' => 'null'];
        }

        return null;
    }

    private static function typeIsNullable(string $type): bool
    {
        if ('' === $type) {
            return false;
        }
        if (str_starts_with($type, '?')) {
            return true;
        }
        if (str_contains($type, '|')) {
            foreach (explode('|', $type) as $member) {
                if ('null' === strtolower(trim($member))) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function isArrayLikeParamName(string $name): bool
    {
        return \in_array($name, [
            'input', 'array', 'arr', 'arr1', 'stack', 'haystack', 'values', 'array_arg',
        ], true);
    }

    /**
     * @param array{kind: string, value?: mixed, class?: string, case?: string} $spec
     */
    private static function writeSpec(Variable $dest, array $spec, ?Context $ctx = null): void
    {
        switch ($spec['kind']) {
            case 'null':
                $dest->null();
                break;
            case 'bool':
                $dest->bool((bool) ($spec['value'] ?? false));
                break;
            case 'int':
                $dest->int((int) ($spec['value'] ?? 0));
                break;
            case 'float':
                $dest->float((float) ($spec['value'] ?? 0.0));
                break;
            case 'string':
                $dest->string((string) ($spec['value'] ?? ''));
                break;
            case 'array':
                $dest->newArray();
                break;
            case 'enum_case':
                $className = (string) ($spec['class'] ?? '');
                $caseName = (string) ($spec['case'] ?? '');
                if (null === $ctx || '' === $className || '' === $caseName) {
                    throw new \LogicException('enum_case default requires Context, class, and case');
                }
                $enum = $ctx->classes[strtolower($className)] ?? null;
                if (null === $enum || !$enum->isEnum) {
                    throw new \LogicException('enum_case default: '.$className.' is not a registered enum');
                }
                $memberLc = ClassConstName::key($caseName);
                if (!EnumCaseSupport::fetchCaseByMemberName($enum, $memberLc, $dest, $ctx)) {
                    throw new \LogicException('enum_case default: '.$className.'::'.$caseName.' missing');
                }
                break;
            default:
                throw new \LogicException('Unknown internal default kind: '.$spec['kind']);
        }
    }
}
