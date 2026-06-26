<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

final class SelfHostBuiltinPolicy
{
    /** Self-host / JIT: array_map() lowers null, string builtins, and closure callbacks (#142, #1154). */
    public const ARRAY_MAP_CALLBACK_DEFERRED_NOTE = ArrayMapCallbackPolicy::DEFERRED_SUMMARY;

    /** @var array<string, string> */
    private const CATEGORY_NUMERIC = [
        'abs' => 'numeric',
        'ceil' => 'numeric',
        'cos' => 'numeric',
        'bindec' => 'numeric',
        'base_convert' => 'numeric',
        'decbin' => 'numeric',
        'dechex' => 'numeric',
        'decoct' => 'numeric',
        'hexdec' => 'numeric',
        'octdec' => 'numeric',
        'intval' => 'numeric',
        'floatval' => 'numeric',
        'doubleval' => 'numeric',
        'boolval' => 'numeric',
        'is_int' => 'numeric',
        'is_integer' => 'numeric',
        'is_float' => 'numeric',
        'is_double' => 'numeric',
        'is_bool' => 'numeric',
        'is_string' => 'numeric',
        'is_array' => 'numeric',
        'is_countable' => 'numeric',
        'is_iterable' => 'numeric',
        'iterator_count' => 'numeric',
        'is_null' => 'numeric',
        'is_numeric' => 'numeric',
        'time' => 'numeric',
        'microtime' => 'numeric',
        'gettimeofday' => 'numeric',
        'hrtime' => 'numeric',
        'clock_gettime' => 'array',
        'getdate' => 'numeric',
        'gmgetdate' => 'numeric',
        'gmmktime' => 'numeric',
        'mktime' => 'numeric',
        'localtime' => 'numeric',
        'idate' => 'numeric',
        'uniqid' => 'numeric',
        'getmypid' => 'numeric',
        'getmyuid' => 'numeric',
        'getmygid' => 'numeric',
        'zend_thread_id' => 'numeric',
        'getmyinode' => 'numeric',
        'getlastmod' => 'numeric',
        'getrusage' => 'numeric',
        'memory_get_peak_usage' => 'numeric',
        'memory_get_usage' => 'numeric',
        'pi' => 'numeric',
    ];

    /** @var array<string, string>|null */
    private static ?array $vmOnlyDeferredCache = null;

    /** @var array<string, string> */
    private const CATEGORY_OUTPUT = [
        'ob_start' => 'output', 'ob_get_clean' => 'output', 'ob_get_flush' => 'output',
        'ob_end_flush' => 'output', 'ob_get_level' => 'output', 'ob_implicit_flush' => 'output',
        'ob_flush' => 'output', 'ob_clean' => 'output', 'ob_list_handlers' => 'output',
        'flush' => 'output',
        'http_get_last_response_headers' => 'output', 'get_last_response_headers' => 'output',
        'http_clear_last_response_headers' => 'output',
        'headers_sent' => 'output', 'header_register_callback' => 'output',
        'register_shutdown_function' => 'output',
        'set_error_handler' => 'error', 'restore_error_handler' => 'error',
    ];

    /** User-script AOT (bin/compile.php): real ob_* / flush lowering (#3753, mirrors #3725 closures). */
    private const AOT_USER_SCRIPT_REAL_OUTPUT = [
        'ob_start' => true,
        'ob_get_clean' => true,
        'ob_get_flush' => true,
        'ob_end_flush' => true,
        'ob_get_level' => true,
        'ob_flush' => true,
        'ob_clean' => true,
        'ob_list_handlers' => true,
        'flush' => true,
        'gc_collect_cycles' => true,
        'gc_disable' => true,
        'gc_enable' => true,
        'gc_enabled' => true,
    ];

    /** Session builtins for AOT user scripts under PHP_COMPILER_SELFHOST_AOT (#1891, #1967). */
    private const CATEGORY_SESSION = [
        'session_start' => 'session',
        'session_id' => 'session',
        'session_name' => 'session',
        'session_module_name' => 'session',
        'session_status' => 'session',
        'session_write_close' => 'session',
        'session_destroy' => 'session',
        'session_regenerate_id' => 'session',
        'session_abort' => 'session',
        'session_reset' => 'session',
        'session_create_id' => 'session',
        'session_gc' => 'session',
    ];

    /** @var array<string, string> */
    private const CATEGORY_PASSWORD = [
        'password_hash' => 'password',
        'password_verify' => 'password',
        'password_get_info' => 'password',
        'password_needs_rehash' => 'password',
        'crypt' => 'password',
    ];

    /** @var array<string, string> */
    private const CATEGORY_FILESYSTEM = [
        'copy' => 'filesystem',
        'dirname' => 'filesystem', 'basename' => 'filesystem', 'file_exists' => 'filesystem',
        'clearstatcache' => 'filesystem',
        'stat' => 'filesystem',
        'lstat' => 'filesystem',
        'fstat' => 'filesystem',
        'is_file' => 'filesystem', 'is_dir' => 'filesystem', 'is_readable' => 'filesystem',
        'is_writable' => 'filesystem', 'file_get_contents' => 'filesystem', 'file_put_contents' => 'filesystem',
        'filemtime' => 'filesystem',
        'fileatime' => 'filesystem',
        'filectime' => 'filesystem',
        'fileinode' => 'filesystem',
        'fileowner' => 'filesystem',
        'filegroup' => 'filesystem',
        'fileperms' => 'filesystem',
        'filesize' => 'filesystem',
        'filetype' => 'filesystem',
        'mkdir' => 'filesystem', 'unlink' => 'filesystem', 'rmdir' => 'filesystem', 'realpath' => 'filesystem',
        'chmod' => 'filesystem', 'chown' => 'filesystem', 'lchown' => 'filesystem', 'chgrp' => 'filesystem', 'lchgrp' => 'filesystem', 'umask' => 'filesystem',
        'glob' => 'filesystem', 'scandir' => 'filesystem', 'fnmatch' => 'filesystem',
        'opendir' => 'filesystem', 'readdir' => 'filesystem', 'closedir' => 'filesystem', 'rewinddir' => 'filesystem',
        'fopen' => 'filesystem', 'fread' => 'filesystem', 'fwrite' => 'filesystem', 'fputs' => 'filesystem', 'fgetc' => 'filesystem', 'fgets' => 'filesystem', 'stream_get_line' => 'filesystem',
        'fgetcsv' => 'filesystem',
        'fputcsv' => 'filesystem',
        'ftell' => 'filesystem', 'fseek' => 'filesystem', 'fclose' => 'filesystem', 'flock' => 'filesystem',
        'is_resource' => 'filesystem',
        'get_resource_type' => 'filesystem',
        'get_resource_id' => 'filesystem',
        'stream_get_contents' => 'filesystem',
        'stream_copy_to_string' => 'filesystem',
        'stream_get_meta_data' => 'filesystem',
        'stream_set_blocking' => 'filesystem',
        'feof' => 'filesystem', 'fflush' => 'filesystem', 'fsync' => 'filesystem', 'fdatasync' => 'filesystem', 'ftruncate' => 'filesystem', 'rewind' => 'filesystem', 'fpassthru' => 'filesystem',
        'pathinfo' => 'filesystem', 'readfile' => 'filesystem', 'readlink' => 'filesystem', 'link' => 'filesystem', 'symlink' => 'filesystem', 'rename' => 'filesystem',
        'is_uploaded_file' => 'filesystem', 'move_uploaded_file' => 'filesystem', 'touch' => 'filesystem',
        'getenv' => 'filesystem', 'putenv' => 'filesystem', 'sys_get_temp_dir' => 'filesystem', 'tempnam' => 'filesystem', 'tmpfile' => 'filesystem',
        'getcwd' => 'filesystem', 'chdir' => 'filesystem', 'gethostname' => 'filesystem',
        'get_include_path' => 'filesystem', 'set_include_path' => 'filesystem',
        'restore_include_path' => 'filesystem', 'stream_resolve_include_path' => 'filesystem',
        'get_current_user' => 'filesystem',
        'gethostbynamel' => 'filesystem',
        'gethostbyname' => 'filesystem',
        'gethostbyaddr' => 'filesystem',
        'long2ip' => 'filesystem',
        'ip2long' => 'filesystem',
        'inet_ntop' => 'filesystem',
        'inet_pton' => 'filesystem',
        'stream_context_create' => 'filesystem',
        'stream_context_get_default' => 'filesystem',
        'stream_context_set_default' => 'filesystem',
        'stream_context_get_options' => 'filesystem',
        'stream_context_set_options' => 'filesystem',
        'stream_notification_callback' => 'filesystem',
    ];

    /** @var array<string, string> php-src ext/standard/php_gc.c (#3209, #3160). */
    private const CATEGORY_GC = [
        'gc_collect_cycles' => 'gc',
        'gc_disable' => 'gc',
        'gc_enable' => 'gc',
        'gc_enabled' => 'gc',
    ];

    /** @var array<string, string> */
    private const CATEGORY_PROCESS = [
        // Required for AOT linker/toolchain discovery (lib/AOT/Linker.php) and bootstrap M5 path.
        'shell_exec' => 'process',
        'popen' => 'process',
        'pclose' => 'process',
        'escapeshellarg' => 'process',
        'escapeshellcmd' => 'process',
        'phpc_run_command' => 'process',
    ];

    /** @var array<string, string> */
    private const CATEGORY_STRING = [
        'addslashes' => 'string',
        'addcslashes' => 'string',
        'stripslashes' => 'string',
        'stripcslashes' => 'string',
        'substr_replace' => 'string',
        'bin2hex' => 'string',
        'chr' => 'string',
        'chunk_split' => 'string',
        'convert_uudecode' => 'string', 'convert_uuencode' => 'string',
        'utf8_decode' => 'string', 'utf8_encode' => 'string',
        'pack' => 'string',
        'unpack' => 'string',
        'strtolower' => 'string', 'strtoupper' => 'string', 'strcmp' => 'string', 'strncmp' => 'string', 'substr_compare' => 'string', 'strtok' => 'string',
        'wordwrap' => 'string', 'nl2br' => 'string',
        'strcasecmp' => 'string', 'strncasecmp' => 'string', 'strlen' => 'string', 'count' => 'string',
        'sizeof' => 'string', 'gettype' => 'string', 'get_debug_type' => 'string', 'var_export' => 'string',
        'str_replace' => 'string', 'str_ireplace' => 'string', 'strtr' => 'string', 'str_rot13' => 'string',
        'str_increment' => 'string', 'str_decrement' => 'string', 'strval' => 'string',
        'strip_tags' => 'string',         'sprintf' => 'string', 'printf' => 'string', 'vsprintf' => 'string', 'vprintf' => 'string', 'vfprintf' => 'string', 'fprintf' => 'string', 'sscanf' => 'long', 'vfscanf' => 'long', 'fscanf' => 'long',
        'chr' => 'string', 'number_format' => 'string',
        'phpversion' => 'string', 'php_sapi_name' => 'string', 'php_uname' => 'string',
        'version_compare' => 'string', 'extension_loaded' => 'string', 'get_loaded_extensions' => 'array',
        'soundex' => 'string',
        'base64_encode' => 'string', 'base64_decode' => 'string',
        'quoted_printable_encode' => 'string', 'quoted_printable_decode' => 'string',
        'htmlspecialchars' => 'string', 'htmlspecialchars_decode' => 'string',
        'htmlentities' => 'string', 'html_entity_decode' => 'string',
        'get_html_translation_table' => 'string',
        'header' => 'string', 'http_response_code' => 'string',
        'output_add_rewrite_var' => 'string', 'output_reset_rewrite_vars' => 'string',
        'getallheaders' => 'string', 'apache_request_headers' => 'string',
        'header_list' => 'string', 'headers_list' => 'string',
        'headers_sent' => 'string', 'header_register_callback' => 'string',
        'register_shutdown_function' => 'string',
        'substr' => 'string', 'trim' => 'string', 'ltrim' => 'string', 'rtrim' => 'string', 'chop' => 'string',
        'urlencode' => 'string', 'rawurlencode' => 'string', 'http_build_query' => 'string',
        'parse_str' => 'string',
    ];

    /** @var array<string, string> */
    private const CATEGORY_ARRAY = [
        'array_merge' => 'array', 'array_merge_recursive' => 'array', 'array_keys' => 'array', 'array_values' => 'array',
        'in_array' => 'array', 'array_search' => 'array', 'array_fill' => 'array', 'array_slice' => 'array', 'array_splice' => 'array',
        'array_key_exists' => 'array', 'key_exists' => 'array', 'array_key_first' => 'array', 'array_key_last' => 'array',
        'array_first' => 'array', 'array_last' => 'array',
        'array_is_list' => 'array', 'array_is_assoc' => 'array', 'array_map' => 'array', 'array_count' => 'array',
        'iterator_apply' => 'array',
        // array_map: null + string builtins + closure/arrow (#142); [class,method] deferred (#1154)
        'array_push' => 'array', 'array_pop' => 'array', 'array_shift' => 'array', 'array_unshift' => 'array',
        'array_reverse' => 'array', 'array_filter' => 'array', 'array_walk' => 'array',
        'array_walk_recursive' => 'array', 'array_reduce' => 'array', 'array_combine' => 'array', 'array_fill_keys' => 'array', 'array_pad' => 'array', 'array_flip' => 'array', 'array_change_key_case' => 'array',
        'array_chunk' => 'array', 'array_column' => 'array',
        'array_product' => 'array', 'array_unique' => 'array', 'array_diff' => 'array', 'array_intersect' => 'array',
        'array_replace' => 'array', 'array_replace_recursive' => 'array', 'array_sum' => 'array', 'sort' => 'array', 'rsort' => 'array',
        'ksort' => 'array', 'krsort' => 'array', 'asort' => 'array', 'arsort' => 'array',
        'array_multisort' => 'array',
        'usort' => 'array', 'uasort' => 'array', 'uksort' => 'array',
        'compact' => 'array', 'extract' => 'array', 'defined' => 'array', 'define' => 'array', 'constant' => 'array',
        'get_defined_constants' => 'array', 'get_defined_vars' => 'array', 'get_declared_interfaces' => 'array',
        'get_declared_classes' => 'array', 'get_declared_traits' => 'array', 'get_declared_attributes' => 'array', 'get_declared_functions' => 'array', 'get_defined_functions' => 'array',
        'get_included_files' => 'array', 'get_required_files' => 'array',
        'get_loaded_extensions' => 'array',
        'class_exists' => 'array', 'interface_exists' => 'array', 'trait_exists' => 'array',
        'enum_exists' => 'array', 'unitenum_exists' => 'array', 'function_exists' => 'array', 'method_exists' => 'array', 'class_meth_exists' => 'array',
        'class_has_method' => 'array', 'class_has_property' => 'array', 'class_has_constant' => 'array',
        'property_exists' => 'array',
        'get_object_vars' => 'array',
        'get_mangled_object_vars' => 'array',
        'get_object_id' => 'array',
        'get_class' => 'array', 'get_class_methods' => 'array', 'get_class_vars' => 'array', 'get_parent_class' => 'array', 'is_a' => 'array', 'is_subclass_of' => 'array',
        'class_implements' => 'array', 'class_parents' => 'array', 'class_uses' => 'array', 'class_uses_recursive' => 'array',
        'assert' => 'array',
        'trigger_error' => 'array',
        'error_get_last' => 'array',
        'error_clear_last' => 'array',
        'ini_set' => 'array', 'ini_alter' => 'array', 'ini_get' => 'array', 'get_cfg_var' => 'array',
    ];

    /** @var array<string, string> */
    private const CATEGORY_HASH = ['hash' => 'hash', 'hash_hmac' => 'hash', 'hash_hmac_algos' => 'hash', 'md5' => 'hash', 'sha1' => 'hash', 'crc32' => 'hash', 'crc32c' => 'hash'];

    /** @var array<string, string> */
    private const CATEGORY_PREG = [
        'preg_match' => 'preg',
        'preg_match_all' => 'preg',
        'preg_grep' => 'preg',
        'preg_replace' => 'preg',
        'preg_replace_callback' => 'preg',
        'preg_split' => 'preg',
        'preg_quote' => 'preg',
        'preg_last_error' => 'preg',
        'preg_last_error_msg' => 'preg',
    ];

    /** @var array<string, string> */
    private const CATEGORY_FILTER = ['filter_var' => 'filter', 'filter_input' => 'filter'];

    /** @var array<string, string> */
    private const CATEGORY_JSON = [
        'json_encode' => 'json',
        'json_decode' => 'json',
        'json_validate' => 'json',
        'json_last_error' => 'json',
        'json_last_error_msg' => 'json',
        'serialize' => 'json',
        'unserialize' => 'json',
    ];

    private const REQUIRED_FOR_BUNDLE = self::CATEGORY_FILESYSTEM
        + self::CATEGORY_STRING
        + self::CATEGORY_ARRAY
        + self::CATEGORY_HASH
        + self::CATEGORY_PREG
        + self::CATEGORY_FILTER
        + self::CATEGORY_JSON
        + self::CATEGORY_NUMERIC
        + self::CATEGORY_GC
        + self::CATEGORY_PASSWORD
        + self::CATEGORY_PROCESS
        + self::CATEGORY_SESSION;

    /** @var list<string> Former auto-stub batch — now in REQUIRED_FOR_BUNDLE categories (#1056). */
    public const AUTO_STUB_BATCH = [];

    /** @var array<string, true> */
    private const AUTO_STUB_LOOKUP = [];

    public static function isSelfHostAot(): bool
    {
        $flag = getenv('PHP_COMPILER_SELFHOST_AOT');

        return '1' === $flag || 'true' === strtolower((string) $flag);
    }

    public static function normalizeName(string $name): string
    {
        $lower = strtolower($name);

        return str_contains($lower, '\\') ? substr($lower, strrpos($lower, '\\') + 1) : $lower;
    }

    public static function isRequiredForBundle(string $name): bool
    {
        return isset(self::REQUIRED_FOR_BUNDLE[self::normalizeName($name)]);
    }

    public static function categoryFor(string $name): ?string
    {
        $key = self::normalizeName($name);

        return self::REQUIRED_FOR_BUNDLE[$key]
            ?? self::CATEGORY_OUTPUT[$key]
            ?? self::CATEGORY_PASSWORD[$key]
            ?? null;
    }

    public static function isVmOnlyDeferred(string $name): bool
    {
        return isset(self::vmOnlyDeferredByCategory()[self::normalizeName($name)]);
    }

    /** @return array<string, string> */
    public static function vmOnlyDeferredByCategory(): array
    {
        if (null === self::$vmOnlyDeferredCache) {
            $lib = dirname(__DIR__, 2).'/script/stdlib-jit-deferred-lib.php';
            if (is_readable($lib)) {
                require_once $lib;
                self::$vmOnlyDeferredCache = stdlib_jit_deferred_by_category();
            } else {
                self::$vmOnlyDeferredCache = [];
            }
        }

        return self::$vmOnlyDeferredCache;
    }

    /** @return array<string, string> */
    public static function requiredByCategory(): array
    {
        return self::REQUIRED_FOR_BUNDLE;
    }

    public static function isAutoStubBatchMember(string $name): bool
    {
        return isset(self::AUTO_STUB_LOOKUP[self::normalizeName($name)]);
    }

    public static function autoStubBatchCount(): int
    {
        return count(self::AUTO_STUB_BATCH);
    }

    public static function shouldExternalStub(string $name): bool
    {
        if (!self::isSelfHostAot() || self::isRequiredForBundle($name)) {
            return false;
        }

        if (self::isAotUserScriptRealOutput($name)) {
            return false;
        }

        return self::isAutoStubBatchMember($name) || self::looksLikeStdlibBuiltin($name);
    }

    private static function isAotUserScriptRealOutput(string $name): bool
    {
        $userScript = getenv('PHP_COMPILER_AOT_USER_SCRIPT');
        if ('1' !== $userScript && 'true' !== strtolower((string) $userScript)) {
            return false;
        }

        return isset(self::AOT_USER_SCRIPT_REAL_OUTPUT[self::normalizeName($name)]);
    }

    /** @var array<string, true>|null */
    private static ?array $registeredStdlib = null;

    private static function looksLikeStdlibBuiltin(string $name): bool
    {
        $key = self::normalizeName($name);
        if ('' === $key || str_contains($key, '::')) {
            return false;
        }

        if (null === self::$registeredStdlib) {
            self::$registeredStdlib = [];
            foreach ((new \PHPCompiler\ext\standard\Module())->getFunctions() as $fn) {
                self::$registeredStdlib[strtolower($fn->getName())] = true;
            }
            foreach ((new \PHPCompiler\ext\types\Module())->getFunctions() as $fn) {
                self::$registeredStdlib[strtolower($fn->getName())] = true;
            }
        }

        return isset(self::$registeredStdlib[$key]);
    }
}
