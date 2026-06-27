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

    /** Build timestamp for phpinfo() INFO_GENERAL Build Date row (php-src PHP_BUILD_DATE). */
    public const BUILD_DATE = '';

    /** SAPI name for CLI entrypoints (bin/vm.php, AOT binaries). */
    public const SAPI = 'cli';

    /** Zend engine version for Docker/php-src 8.2 reference profile (Zend/zend.c, #12471). */
    public const REFERENCE_ZEND_VERSION = '4.2.31';

    /** Zend engine version when VERSION is stable 8.4+ (php-src 8.4.x → 4.4.x). */
    public const FORWARD_ZEND_VERSION = '4.4.0';

    /** Zend engine version reported by zend_version() for the active profile (Zend/zend.c). */
    public static function zendVersion(): string
    {
        if (version_compare(self::VERSION, '8.4.0', '<')) {
            return self::REFERENCE_ZEND_VERSION;
        }

        return self::FORWARD_ZEND_VERSION;
    }

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
     * On the 8.4 development line, advertise implemented forward-compat builtins
     * (json_validate, array_find family, str_increment, …) even while VERSION is
     * 8.4.0-dev — version_compare treats -dev below stable (#12327, #12328).
     */
    public static function builtinAdvertisementVersion(): string
    {
        if (self::MAJOR_VERSION > 8 || (self::MAJOR_VERSION === 8 && self::MINOR_VERSION >= 4)) {
            return '8.4.0';
        }
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
        return self::advertisesBuiltinSince('8.3.0');
    }

    /**
     * PHP 8.3+ #[\Override] compile-time validation (Zend/zend_compile.c, #6303, #11559, #12201).
     *
     * Gated on stable 8.4.0 so 8.4.0-dev reference profile matches Zend 8.2 (no validation;
     * php-src 8.2 treats Override as a normal attribute). Distinct from
     * advertisesOverrideAttributeClass() which may register the builtin class earlier (#12387).
     */
    public static function supportsOverrideAttribute(): bool
    {
        return version_compare(self::VERSION, '8.4.0', '>=');
    }

    /** PHP 8.3+ #[\Override] builtin attribute class advertisement (Zend/zend_attributes.c, #11902). */
    public static function advertisesOverrideAttributeClass(): bool
    {
        return self::advertisesBuiltinSince('8.3.0');
    }

    /** PHP 8.4+ #[\Deprecated] builtin attribute class advertisement (Zend/zend_attributes.c, #11902). */
    public static function advertisesDeprecatedAttributeClass(): bool
    {
        // Stable 8.4.0+ only — 8.4.0-dev reference profile matches Zend 8.2 phantom gate (#12588).
        return version_compare(self::VERSION, '8.4.0', '>=');
    }

    /** PHP 8.4+ #[\NoDiscard] builtin attribute class advertisement (Zend/zend_attributes.c, #11902). */
    public static function advertisesNoDiscardAttributeClass(): bool
    {
        // Stable 8.4.0+ only — 8.4.0-dev reference profile matches Zend 8.2 phantom gate (#12596).
        return version_compare(self::VERSION, '8.4.0', '>=');
    }

    /** PHP 8.4+ #[\DelayedTargetValidation] builtin attribute class advertisement (#11902). */
    public static function advertisesDelayedTargetValidationAttributeClass(): bool
    {
        // Stable 8.4.0+ only — 8.4.0-dev reference profile matches Zend 8.2 phantom gate (#12598).
        return version_compare(self::VERSION, '8.4.0', '>=');
    }

    /** PHP 8.4+ #[\CompileTime] builtin attribute class advertisement (#11902). */
    public static function advertisesCompileTimeAttributeClass(): bool
    {
        // Stable 8.4.0+ only — 8.4.0-dev reference profile matches Zend 8.2 phantom gate (#12598).
        return version_compare(self::VERSION, '8.4.0', '>=');
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

    /** PHP 8.4+ fpow() IEEE float power (ext/standard/math.c; issue #7045, #12412). */
    public static function supportsFpow(): bool
    {
        // Stable 8.4.0+ only — 8.4.0-dev reference profile matches Zend 8.2 phantom gate (#11846).
        return version_compare(self::VERSION, '8.4.0', '>=');
    }

    /**
     * PHP 8.4+ get_declared_* optional $exclude_deprecated (ext/standard/basic_functions.c, #12403).
     *
     * Gated on stable 8.4.0 so 8.4.0-dev reference profile rejects any argument like Zend 8.2.
     */
    public static function supportsGetDeclaredExcludeDeprecated(): bool
    {
        return version_compare(self::VERSION, '8.4.0', '>=');
    }

    /**
     * PHP 8.4+ exit()/die() as proper functions — FCC, named args, two-arg (#6975, #12413, #12414, #12435).
     *
     * Gated on stable 8.4.0 so 8.4.0-dev reference profile rejects named exit/die like Zend 8.2.
     */
    public static function supportsExitFunctionForm(): bool
    {
        return version_compare(self::VERSION, '8.4.0', '>=');
    }

    /**
     * PHP 8.4+ pipe operator (|>) — Zend/zend_language_parser.y (#7219, #12424).
     *
     * Gated on stable 8.4.0 so 8.4.0-dev reference profile rejects |> like Zend 8.2.
     */
    public static function supportsPipeOperator(): bool
    {
        return version_compare(self::VERSION, '8.4.0', '>=');
    }

    /**
     * PHP 8.4+ asymmetric property visibility (private(set), protected(set), …).
     *
     * Gated on stable 8.4.0 so 8.4.0-dev reference profile rejects (set) syntax like Zend 8.2 (#12508).
     * php-src: Zend/zend_language_parser.y T_PRIVATE_SET.
     */
    public static function supportsAsymmetricVisibility(): bool
    {
        return version_compare(self::VERSION, '8.4.0', '>=');
    }

    /**
     * PHP 8.4+ property hooks (`$prop { get; set; }`, default initializer + hook block).
     *
     * Gated on stable 8.4.0 so 8.4.0-dev reference profile rejects hook syntax like Zend 8.2 (#12574).
     * php-src: Zend/zend_language_parser.y / Zend/zend_compile.c property hooks.
     */
    public static function supportsPropertyHooks(): bool
    {
        return version_compare(self::VERSION, '8.4.0', '>=');
    }

    /** PHP 8.4+ str_padded() multibyte-safe padding (ext/standard/string.c; issue #7044). */
    public static function supportsStrPadded(): bool
    {
        return self::advertisesBuiltinSince('8.4.0');
    }

    /** PHP 8.4+ class_has_method/property/constant() (ext/standard/basic_functions.c; issue #9989). */
    public static function supportsClassHasFunctions(): bool
    {
        return self::advertisesBuiltinSince('8.4.0');
    }

    /** PHP 8.4+ zend_thread_id() (ext/standard/basic_functions.c, issue #6870, #11842). */
    public static function supportsZendThreadId(): bool
    {
        return self::advertisesBuiltinSince('8.4.0');
    }

    /** PHP 8.5+ stream_supports() / STREAM_SUPPORT_* (ext/standard/streams.c, issue #11819, #12422). */
    public static function supportsStreamSupports(): bool
    {
        return self::advertisesBuiltinSince('8.5.0');
    }

    /** PHP 8.3+ json_validate() (ext/json/php_json.c, issue #3101, #11826). */
    public static function supportsJsonValidate(): bool
    {
        return self::advertisesBuiltinSince('8.3.0');
    }

    /**
     * PHP 8.4+ readonly(object) dynamic object marker (ext/standard/basic_functions.c, #12607).
     *
     * Gated on stable 8.4.0 so 8.4.0-dev reference profile matches Zend 8.2 phantom gate.
     */
    public static function supportsReadonlyBuiltin(): bool
    {
        return version_compare(self::VERSION, '8.4.0', '>=');
    }

    /**
     * PHP 8.3+ clock_gettime() / ClockInterface (ext/standard/hrtime.c, #11624, #12470).
     *
     * Gated on stable 8.4.0 so 8.4.0-dev reference profile matches Zend 8.2 phantom gate.
     */
    public static function supportsClockGettime(): bool
    {
        return version_compare(self::VERSION, '8.4.0', '>=');
    }

    /**
     * PHP 8.4+ gc_status() schema (running/protected/full/buffer_size; ext/standard/php_gc.c, #12780).
     *
     * Gated on stable 8.4.0 so 8.4.0-dev reference profile keeps legacy runs/collected/threshold/roots (#12790).
     */
    public static function supportsGcStatusPhp84Schema(): bool
    {
        return version_compare(self::VERSION, '8.4.0', '>=');
    }

    /**
     * PHP 8.4+ hrtime(true) returns double (ext/standard/hrtime.c, #12779).
     *
     * Gated on stable 8.4.0 so 8.4.0-dev reference profile keeps integer nanoseconds (#12789).
     */
    public static function supportsHrtimeAsNumberFloat(): bool
    {
        return version_compare(self::VERSION, '8.4.0', '>=');
    }

    /**
     * PHP 8.3+ class_constants() (ext/standard/basic_functions.c, #7309, #12448).
     *
     * Gated on stable 8.4.0 so 8.4.0-dev reference profile matches Zend 8.2 phantom gate.
     */
    public static function supportsClassConstants(): bool
    {
        return version_compare(self::VERSION, '8.4.0', '>=');
    }

    /**
     * PHP 8.3+ header_list() (ext/standard/head.c, #12546).
     *
     * Gated on stable 8.4.0 so 8.4.0-dev reference profile matches Zend 8.2 CLI phantom gate.
     */
    public static function supportsHeaderList(): bool
    {
        return version_compare(self::VERSION, '8.4.0', '>=');
    }

    /**
     * PHP 8.4+ stream_context_set_options() (ext/standard/streams.c, #12597).
     *
     * Gated on stable 8.4.0 so 8.4.0-dev reference profile matches Zend 8.2 phantom gate.
     */
    public static function supportsStreamContextSetOptions(): bool
    {
        return version_compare(self::VERSION, '8.4.0', '>=');
    }

    /**
     * PHP 8.3+ mb_str_pad() (ext/mbstring/mbstring.c, issue #11964).
     *
     * Gated on stable 8.4.0 so 8.4.0-dev reference profile matches Zend 8.2 phantom gate.
     */
    public static function supportsMbStrPad(): bool
    {
        return version_compare(self::VERSION, '8.4.0', '>=');
    }

    /** PHP 8.4+ array_all/any/find/find_key/first/last (ext/standard/array.c, issue #11845). */
    public static function supportsPhp84ArraySearchFunctions(): bool
    {
        return self::advertisesBuiltinSince('8.4.0');
    }

    /** PHP 8.4+ mb_trim/ltrim/rtrim (ext/mbstring/mbstring.c, issue #11901). */
    public static function supportsMbTrimFunctions(): bool
    {
        return self::advertisesBuiltinSince('8.4.0');
    }

    /**
     * Whether a builtin removed in $removedIn should appear in function_exists() for the active profile.
     *
     * php-src drops symbols at major boundaries (e.g. convert_cyr_string/strxfrm in 8.0 — #11907).
     */
    public static function advertisesBuiltinRemovedIn(string $removedIn): bool
    {
        return version_compare(self::builtinAdvertisementVersion(), $removedIn, '<');
    }

    /** PHP 8.0 removed strxfrm() (ext/standard/string.c, issue #11907). */
    public static function supportsStrxfrm(): bool
    {
        return self::advertisesBuiltinRemovedIn('8.0.0');
    }

    /** PHP 8.0 removed convert_cyr_string() (ext/standard/cyr_convert.c, issue #11907). */
    public static function supportsConvertCyrString(): bool
    {
        return self::advertisesBuiltinRemovedIn('8.0.0');
    }

    /** getmygrgid() never exported in php-src — use getmygid() / posix_getgrgid() (#11923). */
    public static function supportsGetmygrgid(): bool
    {
        return false;
    }

    /** disktotalspace() legacy alias not in php-src 8.2 — use disk_total_space() (#11922). */
    public static function supportsDisktotalspace(): bool
    {
        return false;
    }

    /** Standalone crc32c() not in php-src — use hash('crc32c') via ext/hash (#11920). */
    public static function supportsCrc32c(): bool
    {
        return false;
    }

    /** ext/bz2 not on Zend reference profile — withhold until real module parity (#11992). */
    public static function supportsBz2(): bool
    {
        return false;
    }

    /** ext/bcmath not on Docker Zend 8.2 reference profile — withhold phantom registration (#12131). */
    public static function supportsBcmath(): bool
    {
        return false;
    }
}
