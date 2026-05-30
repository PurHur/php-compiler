<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

final class SelfHostBuiltinPolicy
{
    /** Self-host / JIT: array_map() lowers null and compile-time string builtins only (#1154). */
    public const ARRAY_MAP_CALLBACK_DEFERRED_NOTE = ArrayMapCallbackPolicy::DEFERRED_SUMMARY;

    /** @var array<string, string> */
    private const CATEGORY_NUMERIC = [
        'abs' => 'numeric',
        'ceil' => 'numeric',
        'cos' => 'numeric',
        'bindec' => 'numeric',
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
        'is_null' => 'numeric',
        'is_numeric' => 'numeric',
        'time' => 'numeric',
        'microtime' => 'numeric',
        'uniqid' => 'numeric',
        'getmypid' => 'numeric',
        'pi' => 'numeric',
    ];

    private const REQUIRED_FOR_BUNDLE = self::CATEGORY_FILESYSTEM
        + self::CATEGORY_STRING
        + self::CATEGORY_ARRAY
        + self::CATEGORY_HASH
        + self::CATEGORY_PREG
        + self::CATEGORY_FILTER
        + self::CATEGORY_JSON
        + self::CATEGORY_NUMERIC
        + self::CATEGORY_PASSWORD
        + self::CATEGORY_PROCESS;

    /** @var array<string, string>|null */
    private static ?array $vmOnlyDeferredCache = null;

    /** @var array<string, string> */
    private const CATEGORY_OUTPUT = [
        'ob_start' => 'output', 'ob_get_clean' => 'output', 'ob_end_flush' => 'output',
        'ob_get_level' => 'output',
        'getallheaders' => 'output', 'header_list' => 'output',
        'headers_sent' => 'output', 'register_shutdown_function' => 'output',
        'set_error_handler' => 'error', 'restore_error_handler' => 'error',
    ];

    /** @var array<string, string> */
    private const CATEGORY_PASSWORD = [
        'password_hash' => 'password', 'password_verify' => 'password',
    ];

    /** @var array<string, string> */
    private const CATEGORY_FILESYSTEM = [
        'copy' => 'filesystem',
        'dirname' => 'filesystem', 'basename' => 'filesystem', 'file_exists' => 'filesystem',
        'clearstatcache' => 'filesystem',
        'stat' => 'filesystem',
        'lstat' => 'filesystem',
        'is_file' => 'filesystem', 'is_dir' => 'filesystem', 'is_readable' => 'filesystem',
        'is_writable' => 'filesystem', 'file_get_contents' => 'filesystem', 'file_put_contents' => 'filesystem',
        'filemtime' => 'filesystem', 'fileperms' => 'filesystem', 'filesize' => 'filesystem', 'filetype' => 'filesystem',
        'mkdir' => 'filesystem', 'unlink' => 'filesystem', 'rmdir' => 'filesystem', 'realpath' => 'filesystem',
        'glob' => 'filesystem', 'scandir' => 'filesystem', 'fnmatch' => 'filesystem',
        'fopen' => 'filesystem', 'fread' => 'filesystem', 'fwrite' => 'filesystem', 'fgetc' => 'filesystem', 'fgets' => 'filesystem',
        'fgetcsv' => 'filesystem',
        'fputcsv' => 'filesystem',
        'ftell' => 'filesystem', 'fseek' => 'filesystem', 'fclose' => 'filesystem', 'flock' => 'filesystem',
        'is_resource' => 'filesystem',
        'feof' => 'filesystem', 'fflush' => 'filesystem', 'rewind' => 'filesystem', 'fpassthru' => 'filesystem',
        'pathinfo' => 'filesystem', 'readfile' => 'filesystem', 'readlink' => 'filesystem', 'rename' => 'filesystem',
        'is_uploaded_file' => 'filesystem', 'move_uploaded_file' => 'filesystem', 'touch' => 'filesystem',
        'getenv' => 'filesystem', 'putenv' => 'filesystem', 'sys_get_temp_dir' => 'filesystem', 'tempnam' => 'filesystem',
        'getcwd' => 'filesystem', 'chdir' => 'filesystem',
        'stream_context_create' => 'filesystem',
    ];

    /** @var array<string, string> */
    private const CATEGORY_PROCESS = [
        // Required for AOT linker/toolchain discovery (lib/AOT/Linker.php) and bootstrap M5 path.
        'shell_exec' => 'process',
        'escapeshellarg' => 'process',
        'phpc_run_command' => 'process',
    ];

    /** @var array<string, string> */
    private const CATEGORY_STRING = [
        'addslashes' => 'string',
        'bin2hex' => 'string',
        'chr' => 'string',
        'chunk_split' => 'string',
        'pack' => 'string',
        'strtolower' => 'string', 'strtoupper' => 'string', 'strcmp' => 'string', 'strncmp' => 'string', 'substr_compare' => 'string', 'strtok' => 'string',
        'strcasecmp' => 'string', 'strncasecmp' => 'string', 'strlen' => 'string', 'count' => 'string',
        'sizeof' => 'string', 'gettype' => 'string', 'get_debug_type' => 'string', 'var_export' => 'string',
        'str_replace' => 'string', 'str_ireplace' => 'string', 'strtr' => 'string', 'str_rot13' => 'string',
        'str_increment' => 'string', 'str_decrement' => 'string', 'strval' => 'string',
        'strip_tags' => 'string', 'sprintf' => 'string', 'chr' => 'string', 'number_format' => 'string',
        'soundex' => 'string',
        'base64_encode' => 'string', 'base64_decode' => 'string',
        'htmlspecialchars' => 'string', 'htmlspecialchars_decode' => 'string', 'header' => 'string', 'http_response_code' => 'string',
        'headers_sent' => 'string', 'register_shutdown_function' => 'string',
        'substr' => 'string', 'trim' => 'string', 'ltrim' => 'string', 'rtrim' => 'string',
        'urlencode' => 'string', 'rawurlencode' => 'string', 'http_build_query' => 'string',
        'parse_str' => 'string',
    ];

    /** @var array<string, string> */
    private const CATEGORY_ARRAY = [
        'array_merge' => 'array', 'array_keys' => 'array', 'array_values' => 'array',
        'in_array' => 'array', 'array_search' => 'array', 'array_fill' => 'array', 'array_slice' => 'array', 'array_splice' => 'array',
        'array_key_exists' => 'array', 'array_key_first' => 'array', 'array_key_last' => 'array',
        'array_is_list' => 'array', 'array_map' => 'array', 'array_count' => 'array',
        // array_map: null + compile-time string builtins only; closures deferred (#1154)
        'array_push' => 'array', 'array_pop' => 'array', 'array_shift' => 'array', 'array_unshift' => 'array',
        'array_reverse' => 'array', 'array_filter' => 'array', 'array_walk' => 'array',
        'array_walk_recursive' => 'array', 'array_reduce' => 'array', 'array_combine' => 'array', 'array_fill_keys' => 'array', 'array_pad' => 'array', 'array_flip' => 'array', 'array_change_key_case' => 'array',
        'array_chunk' => 'array', 'array_column' => 'array',
        'array_product' => 'array', 'array_unique' => 'array', 'array_diff' => 'array', 'array_intersect' => 'array',
        'array_replace' => 'array', 'array_sum' => 'array', 'sort' => 'array', 'rsort' => 'array',
        'ksort' => 'array', 'krsort' => 'array', 'asort' => 'array', 'arsort' => 'array',
        'array_multisort' => 'array',
        'usort' => 'array', 'uasort' => 'array', 'uksort' => 'array',
        'compact' => 'array', 'extract' => 'array', 'defined' => 'array', 'define' => 'array',
        'get_defined_constants' => 'array', 'get_defined_vars' => 'array',
        'class_exists' => 'array', 'enum_exists' => 'array', 'get_declared_enums' => 'array', 'function_exists' => 'array', 'method_exists' => 'array',
        'property_exists' => 'array',
        'get_object_vars' => 'array',
        'get_class' => 'array', 'get_parent_class' => 'array', 'is_a' => 'array', 'is_subclass_of' => 'array',
        'class_implements' => 'array',
        'assert' => 'array',
        'trigger_error' => 'array',
        'error_get_last' => 'array',
        'error_clear_last' => 'array',
        'ini_set' => 'array', 'ini_get' => 'array',
    ];

    /** @var array<string, string> */
    private const CATEGORY_HASH = ['hash' => 'hash', 'hash_hmac' => 'hash', 'md5' => 'hash', 'sha1' => 'hash', 'crc32' => 'hash'];

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

        return self::isAutoStubBatchMember($name) || self::looksLikeStdlibBuiltin($name);
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
