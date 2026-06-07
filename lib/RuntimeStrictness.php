<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * Runtime strictness policy (#7361): php-src-strict (default) vs php-compiler-strict (opt-in).
 *
 * php-src-strict: full Zend parity guards (enum-case TypeError, Z_PARAM_* rejection).
 * php-compiler-strict: self-host/AOT only; skip guards when static proof documented in PR.
 */
final class RuntimeStrictness
{
    public const MODE_PHP_SRC = 'php-src';

    public const MODE_PHP_COMPILER = 'php-compiler';

    /** Env var: PHP_COMPILER_RUNTIME_STRICT=php-src|php-compiler (unset → php-src). */
    private const ENV = 'PHP_COMPILER_RUNTIME_STRICT';

    private static ?string $cachedMode = null;

    public static function mode(): string
    {
        if (null !== self::$cachedMode) {
            return self::$cachedMode;
        }

        $raw = self::readEnv(self::ENV);
        if (false === $raw || '' === $raw) {
            self::$cachedMode = self::MODE_PHP_SRC;

            return self::$cachedMode;
        }

        $normalized = strtolower(trim((string) $raw));
        if (self::MODE_PHP_COMPILER === $normalized) {
            self::$cachedMode = self::MODE_PHP_COMPILER;

            return self::$cachedMode;
        }
        if (self::MODE_PHP_SRC === $normalized) {
            self::$cachedMode = self::MODE_PHP_SRC;

            return self::$cachedMode;
        }

        // Unknown value: stay php-src-strict (safe default).
        self::$cachedMode = self::MODE_PHP_SRC;

        return self::$cachedMode;
    }

    public static function isPhpSrcStrict(): bool
    {
        return self::MODE_PHP_SRC === self::mode();
    }

    public static function isPhpCompilerStrict(): bool
    {
        return self::MODE_PHP_COMPILER === self::mode();
    }

    /**
     * Whether enum-case / Z_PARAM string builtin guards run on this path (#5780).
     *
     * v1 (#7361): always true until follow-up perf issues supply static proofs.
     */
    public static function enforceStringBuiltinParityGuards(): bool
    {
        self::mode();

        return true;
    }

    /** @internal test reset */
    public static function resetCacheForTests(): void
    {
        self::$cachedMode = null;
    }

    /**
     * @return false|string
     */
    private static function readEnv(string $name)
    {
        if (\array_key_exists($name, $_SERVER)) {
            return $_SERVER[$name];
        }
        if (\array_key_exists($name, $_ENV)) {
            return $_ENV[$name];
        }
        if (\function_exists('getenv')) {
            $value = getenv($name);
            if (false !== $value) {
                return $value;
            }
        }

        return false;
    }
}
