<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

final class SelfHostBuiltinPolicy
{
    /** @var array<string, string> */
    private const REQUIRED_FOR_BUNDLE = self::CATEGORY_FILESYSTEM
        + self::CATEGORY_STRING
        + self::CATEGORY_HASH
        + self::CATEGORY_PREG
        + self::CATEGORY_JSON;

    /** @var array<string, string> */
    private const CATEGORY_FILESYSTEM = [
        'dirname' => 'filesystem', 'basename' => 'filesystem', 'file_exists' => 'filesystem',
        'is_file' => 'filesystem', 'is_dir' => 'filesystem', 'is_readable' => 'filesystem',
        'is_writable' => 'filesystem', 'file_get_contents' => 'filesystem', 'realpath' => 'filesystem',
    ];

    /** @var array<string, string> */
    private const CATEGORY_STRING = [
        'strtolower' => 'string', 'strtoupper' => 'string', 'strcmp' => 'string', 'strncmp' => 'string',
        'strcasecmp' => 'string', 'strncasecmp' => 'string', 'strlen' => 'string', 'count' => 'string',
        'sizeof' => 'string',
    ];

    /** @var array<string, string> */
    private const CATEGORY_HASH = ['hash' => 'hash', 'hash_hmac' => 'hash'];

    /** @var array<string, string> */
    private const CATEGORY_PREG = ['preg_match' => 'preg', 'preg_quote' => 'preg'];

    /** @var array<string, string> */
    private const CATEGORY_JSON = ['json_encode' => 'json'];

    /** @var list<string> */
    public const AUTO_STUB_BATCH = [
        'abs', 'addslashes', 'array_combine', 'array_count', 'array_fill', 'array_filter', 'array_flip',
        'array_key_exists', 'array_keys', 'array_map', 'array_merge', 'array_pop', 'array_product',
        'array_push', 'array_reverse', 'array_search', 'array_shift', 'array_slice', 'array_sum',
        'array_unique', 'array_values', 'bin2hex', 'bindec', 'boolval', 'ceil', 'chr', 'chunk_split',
        'compact', 'copy', 'cos',
    ];

    /** @var array<string, true> */
    private const AUTO_STUB_LOOKUP = [
        'abs' => true, 'addslashes' => true, 'array_combine' => true, 'array_count' => true,
        'array_fill' => true, 'array_filter' => true, 'array_flip' => true, 'array_key_exists' => true,
        'array_keys' => true, 'array_map' => true, 'array_merge' => true, 'array_pop' => true,
        'array_product' => true, 'array_push' => true, 'array_reverse' => true, 'array_search' => true,
        'array_shift' => true, 'array_slice' => true, 'array_sum' => true, 'array_unique' => true,
        'array_values' => true, 'bin2hex' => true, 'bindec' => true, 'boolval' => true, 'ceil' => true,
        'chr' => true, 'chunk_split' => true, 'compact' => true, 'copy' => true, 'cos' => true,
    ];

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
        return self::REQUIRED_FOR_BUNDLE[self::normalizeName($name)] ?? null;
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
