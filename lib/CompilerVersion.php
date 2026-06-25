<?php

declare(strict_types=1);

namespace PHPCompiler;

/** Runtime identity strings for phpversion() / php_sapi_name() (issue #3174). */
final class CompilerVersion
{
    /** Language/runtime version reported by phpversion() (php-src PHP_VERSION shape). */
    public const VERSION = '8.4.0-dev';

    public const MAJOR_VERSION = 8;

    public const MINOR_VERSION = 4;

    public const RELEASE_VERSION = 0;

    public const EXTRA_VERSION = '-dev';

    public const VERSION_ID = 80400;

    /** SAPI name for CLI entrypoints (bin/vm.php, AOT binaries). */
    public const SAPI = 'cli';

    /** PHP 8.3+ typed class constants in traits (Zend/zend_compile.c, issue #5212). */
    public static function supportsTypedTraitConstants(): bool
    {
        return version_compare(self::VERSION, '8.3', '>=');
    }

    /** PHP 8.3+ typed constants on interfaces (Zend/zend_compile.c, issue #5980, #7042). */
    public static function supportsInterfaceTypedConstants(): bool
    {
        return version_compare(self::VERSION, '8.3', '>=');
    }

    /** PHP 8.3+ typed constants at compile-unit scope (Zend/zend_compile.c, issue #7081). */
    public static function supportsGlobalTypedConstants(): bool
    {
        return version_compare(self::VERSION, '8.3', '>=');
    }

    /** PHP 8.3+ typed function-local static variables (Zend/zend_compile.c, issue #9998). */
    public static function supportsTypedFunctionStatic(): bool
    {
        return version_compare(self::VERSION, '8.3', '>=');
    }

    /** PHP 8.4+ `final const` at compile-unit scope (Zend/zend_compile.c, issue #9909, #10324). */
    public static function supportsFinalGlobalTypedConstants(): bool
    {
        // 8.4.0-dev is below stable 8.4.0 for version_compare — matches Zend ≤8.3 until release (#10324).
        return version_compare(self::VERSION, '8.4.0', '>=');
    }

    /**
     * Version profile for builtin advertisement / function_exists parity (#11842).
     *
     * Until stable 8.4.0, match Docker CI Zend 8.2 reference (php-env.sh) so forward-compat
     * builtins are not phantom-registered on the reference profile.
     */
    public static function builtinAdvertisementVersion(): string
    {
        if (version_compare(self::VERSION, '8.4.0', '<')) {
            return '8.2.0';
        }

        return self::VERSION;
    }

    /** Whether a builtin introduced in $since should appear in function_exists() for the active profile. */
    public static function advertisesBuiltinSince(string $since): bool
    {
        return version_compare(self::builtinAdvertisementVersion(), $since, '>=');
    }

    /** PHP 8.3+ str_increment() / str_decrement() (ext/standard/string.c, issue #5697). */
    public static function supportsStrIncrement(): bool
    {
        return version_compare(self::VERSION, '8.3', '>=');
    }

    /** PHP 8.3+ #[\Override] builtin attribute class (Zend/zend_attributes.c, issue #6303). */
    public static function supportsOverrideAttribute(): bool
    {
        return version_compare(self::VERSION, '8.3', '>=');
    }

    /** @deprecated Zend rejects `new` in class constants at compile time (#10391); always false. */
    public static function supportsClassConstObjectExpressions(): bool
    {
        return false;
    }

    /** PHP 8.4+ hexadecimal floating-point literals (Zend/zend_language_scanner.l, issue #7041). */
    public static function supportsHexFloatLiterals(): bool
    {
        return version_compare(self::VERSION, '8.4', '>=');
    }

    /** PHP 8.4+ #[\NoDiscard] builtin attribute class (Zend/zend_attributes.c, issue #6992). */
    public static function supportsNoDiscardAttribute(): bool
    {
        return version_compare(self::VERSION, '8.4', '>=');
    }

    /** PHP 8.4+ #[\DelayedTargetValidation] builtin attribute class (Zend/zend_attributes.c, issue #7101). */
    public static function supportsDelayedTargetValidationAttribute(): bool
    {
        return version_compare(self::VERSION, '8.4', '>=');
    }

    /** PHP 8.4+ #[\CompileTime] builtin attribute class (zend_attributes.stub.php, issue #7101). */
    public static function supportsCompileTimeAttribute(): bool
    {
        return version_compare(self::VERSION, '8.4', '>=');
    }

    /** PHP 8.4+ fpow() IEEE float power (ext/standard/math.c; issue #7045). */
    public static function supportsFpow(): bool
    {
        return version_compare(self::VERSION, '8.4', '>=');
    }

    /** PHP 8.4+ str_padded() multibyte-safe padding (ext/standard/string.c; issue #7044). */
    public static function supportsStrPadded(): bool
    {
        return version_compare(self::VERSION, '8.4', '>=');
    }

    /** PHP 8.4+ class_has_method/property/constant() (ext/standard/basic_functions.c; issue #9989). */
    public static function supportsClassHasFunctions(): bool
    {
        return version_compare(self::VERSION, '8.4', '>=');
    }

    /** PHP 8.4+ zend_thread_id() (ext/standard/basic_functions.c, issue #6870, #11842). */
    public static function supportsZendThreadId(): bool
    {
        return self::advertisesBuiltinSince('8.4.0');
    }
}
