<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pgsql;

/**
 * ext/pgsql advertisement — php-src ext/pgsql/pgsql.c (#3741, #24994, #24627).
 *
 * In-tree libpq FFI ({@see VmPgsqlNative}) must not flip {@code extension_loaded('pgsql')} /
 * {@code function_exists('pg_*')} when host Zend has no ext/pgsql — same host-module gate as
 * gd/intl (#22740 / #22691). Forward {@code PHP_COMPILER_PROFILE=8.4} alone must not invent the
 * optional module (#24627).
 *
 * Enable via host {@code extension_loaded('pgsql')}, or explicit
 * {@code PHP_COMPILER_ENABLE_PGSQL=1} when libpq FFI is available (functional PHPT / local runs).
 */
final class PgsqlExtensionPolicy
{
    public static function advertisesExtension(): bool
    {
        if (\extension_loaded('pgsql')) {
            return true;
        }

        if (!self::explicitEnableRequested()) {
            return false;
        }

        return VmPgsqlNative::available();
    }

    public static function advertisesBuiltins(): bool
    {
        return self::advertisesExtension();
    }

    public static function advertisesClasses(): bool
    {
        return self::advertisesExtension();
    }

    /**
     * PHP 8.4 libpq helpers (pg_jit, pg_put_copy_*, …) — #7083 / #22543.
     *
     * Gated on {@see \PHPCompiler\CompilerVersion::languageProfileVersion()} ≥ 8.4
     * (not bare {@see \PHPCompiler\CompilerVersion::VERSION_ID}) so 8.4.0-dev /
     * {@code PHP_COMPILER_PROFILE=8.2} match Zend 8.2 {@code function_exists} phantoms.
     * Enable via stable 8.4.0+ or explicit {@code PHP_COMPILER_PROFILE=8.4}.
     */
    public static function advertisesPhp84Helpers(): bool
    {
        return self::advertisesBuiltins()
            && version_compare(
                \PHPCompiler\CompilerVersion::languageProfileVersion(),
                '8.4.0',
                '>='
            );
    }

    /**
     * PHP 8.3+ {@code pg_set_error_context_visibility} + {@code PGSQL_SHOW_CONTEXT_*} (#20674 / #22620).
     *
     * Withheld on 8.4.0-dev reference / {@code PROFILE=8.2} (Zend 8.2 has neither). Enable via
     * stable 8.4.0+ or explicit {@code PHP_COMPILER_PROFILE=8.3} / {@code 8.4}.
     * php-src: ext/pgsql/pgsql.stub.php (@since 8.3.0).
     */
    public static function advertisesPhp83ErrorContextVisibility(): bool
    {
        if (!self::advertisesBuiltins()) {
            return false;
        }

        if (version_compare(\PHPCompiler\CompilerVersion::VERSION, '8.4.0', '>=')) {
            return true;
        }

        $raw = getenv('PHP_COMPILER_PROFILE');
        if (!\is_string($raw) || '' === trim($raw)) {
            return false;
        }

        return version_compare(
            \PHPCompiler\CompilerVersion::languageProfileVersion(),
            '8.3.0',
            '>='
        );
    }

    /** Compliance filenames that exercise pg_* / PgSql\\* / extension_loaded('pgsql'). */
    public static function isPgsqlComplianceCase(string $testFileName): bool
    {
        return str_contains($testFileName, 'pgsql_')
            || str_contains($testFileName, 'extension_loaded_pgsql')
            || str_contains($testFileName, 'pdo_pgsql');
    }

    /**
     * Phantom-registration guards that assert pgsql is withheld (#24994 / #24627).
     *
     * Excludes version-helper phantoms ({@code pgsql_*_phantom_profile82}) that need ENABLE + PROFILE.
     */
    public static function isPgsqlModulePhantomComplianceCase(string $testFileName): bool
    {
        return str_contains($testFileName, 'extension_loaded_pgsql_phantom');
    }

    /**
     * Functional pgsql cases set {@code PHP_COMPILER_ENABLE_PGSQL} via {@code --ENV--}; module
     * phantom guards run only when pgsql is withheld (#24994).
     */
    public static function runsPgsqlCompliance(string $testFileName): bool
    {
        if (self::isPgsqlModulePhantomComplianceCase($testFileName)) {
            return !self::advertisesExtension();
        }

        return true;
    }

    /**
     * Explicit side-load / functional-test opt-in when host Zend lacks ext/pgsql (#24994).
     */
    private static function explicitEnableRequested(): bool
    {
        $raw = getenv('PHP_COMPILER_ENABLE_PGSQL');
        if (!\is_string($raw) || '' === trim($raw)) {
            return false;
        }

        $v = strtolower(trim($raw));

        return !\in_array($v, ['0', 'false', 'off', 'no'], true);
    }
}
