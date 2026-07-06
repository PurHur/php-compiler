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

    /** PHP version for Docker/php-src 8.2 reference profile (ext/standard/info.c, #16337). */
    public const REFERENCE_PHP_VERSION = '8.2.31';

    public const REFERENCE_PHP_VERSION_ID = 80231;

    public const REFERENCE_PHP_MAJOR_VERSION = 8;

    public const REFERENCE_PHP_MINOR_VERSION = 2;

    public const REFERENCE_PHP_RELEASE_VERSION = 31;

    public const REFERENCE_PHP_EXTRA_VERSION = '';

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

    /** PHP version reported by phpversion() / PHP_VERSION (ext/standard/info.c, #11470, #16337). */
    public static function reportedPhpVersion(): string
    {
        if (version_compare(self::VERSION, '8.4.0', '<')) {
            return self::REFERENCE_PHP_VERSION;
        }

        return self::VERSION;
    }

    public static function reportedPhpVersionId(): int
    {
        if (version_compare(self::VERSION, '8.4.0', '<')) {
            return self::REFERENCE_PHP_VERSION_ID;
        }

        return self::VERSION_ID;
    }

    public static function reportedPhpMajorVersion(): int
    {
        if (version_compare(self::VERSION, '8.4.0', '<')) {
            return self::REFERENCE_PHP_MAJOR_VERSION;
        }

        return self::MAJOR_VERSION;
    }

    public static function reportedPhpMinorVersion(): int
    {
        if (version_compare(self::VERSION, '8.4.0', '<')) {
            return self::REFERENCE_PHP_MINOR_VERSION;
        }

        return self::MINOR_VERSION;
    }

    public static function reportedPhpReleaseVersion(): int
    {
        if (version_compare(self::VERSION, '8.4.0', '<')) {
            return self::REFERENCE_PHP_RELEASE_VERSION;
        }

        return self::RELEASE_VERSION;
    }

    public static function reportedPhpExtraVersion(): string
    {
        if (version_compare(self::VERSION, '8.4.0', '<')) {
            return self::REFERENCE_PHP_EXTRA_VERSION;
        }

        return self::EXTRA_VERSION;
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

    /**
     * PHP 8.3+ typed class constants on classes/enums (Zend/zend_compile.c, #3592, #12798, #12994, #15367).
     *
     * Rejected on the 8.4.0-dev reference profile (matches Zend 8.2 parse error). Enable via stable
     * 8.4.0+ or explicit `PHP_COMPILER_PROFILE=8.3` / `8.4` forward profile (#12798, #15662).
     */
    public static function supportsTypedClassConstants(): bool
    {
        if (version_compare(self::VERSION, '8.3', '<')) {
            return false;
        }

        if (version_compare(self::VERSION, '8.4.0', '>=')) {
            return true;
        }

        $raw = getenv('PHP_COMPILER_PROFILE');
        if (!\is_string($raw) || '' === trim($raw)) {
            return false;
        }

        return version_compare(self::languageProfileVersion(), '8.3.0', '>=');
    }

    /**
     * PHP 8.3+ class constant brace dereference (`C::{'NAME'}`, `C::{"NAME"}`).
     *
     * Rejected on the 8.4.0-dev reference profile (matches Zend 8.2 parse error). Enable via stable
     * 8.4.0+ or explicit `PHP_COMPILER_PROFILE=8.3` / `8.4` forward profile (#16597).
     * php-src: Zend/zend_language_parser.y class_constant; Zend/zend_compile.c.
     */
    public static function supportsClassConstBraceDeref(): bool
    {
        if (version_compare(self::VERSION, '8.3', '<')) {
            return false;
        }

        if (version_compare(self::VERSION, '8.4.0', '>=')) {
            return true;
        }

        $raw = getenv('PHP_COMPILER_PROFILE');
        if (!\is_string($raw) || '' === trim($raw)) {
            return false;
        }

        return version_compare(self::languageProfileVersion(), '8.3.0', '>=');
    }

    /**
     * PHP 8.3+ `new readonly class` anonymous readonly modifier (#6991, #16255, #16348, #16379).
     *
     * Withheld on 8.4.0-dev reference profile (matches Zend 8.2 parse error). Enable via stable
     * 8.4.0+ or explicit `PHP_COMPILER_PROFILE=8.3` / `8.4` forward profile.
     * php-src: Zend/zend_compile.c ZEND_ACC_READONLY_ANON_CLASS.
     */
    public static function supportsReadonlyAnonymousClass(): bool
    {
        if (version_compare(self::VERSION, '8.3', '<')) {
            return false;
        }

        if (version_compare(self::VERSION, '8.4.0', '>=')) {
            return true;
        }

        $raw = getenv('PHP_COMPILER_PROFILE');
        if (!\is_string($raw) || '' === trim($raw)) {
            return false;
        }

        return version_compare(self::languageProfileVersion(), '8.3.0', '>=');
    }

    /**
     * PHP 8.3+ typed constants at compile-unit scope (Zend/zend_compile.c, issue #7081, #16651).
     *
     * Withheld on 8.4.0-dev reference profile (matches Zend 8.2 parse error). Enable via stable
     * 8.4.0+ or explicit `PHP_COMPILER_PROFILE=8.3` / `8.4` forward profile.
     */
    public static function supportsGlobalTypedConstants(): bool
    {
        if (version_compare(self::VERSION, '8.3', '<')) {
            return false;
        }

        if (version_compare(self::VERSION, '8.4.0', '>=')) {
            return true;
        }

        $raw = getenv('PHP_COMPILER_PROFILE');
        if (!\is_string($raw) || '' === trim($raw)) {
            return false;
        }

        return version_compare(self::languageProfileVersion(), '8.3.0', '>=');
    }

    /**
     * PHP 8.3+ typed function-local static variables (Zend/zend_compile.c, issue #9998, #16512).
     *
     * Withheld on 8.4.0-dev reference profile (matches Zend 8.2 parse error). Enable via stable
     * 8.4.0+ or explicit `PHP_COMPILER_PROFILE=8.3` / `8.4` forward profile.
     */
    public static function supportsTypedFunctionStatic(): bool
    {
        if (version_compare(self::VERSION, '8.3', '<')) {
            return false;
        }

        if (version_compare(self::VERSION, '8.4.0', '>=')) {
            return true;
        }

        $raw = getenv('PHP_COMPILER_PROFILE');
        if (!\is_string($raw) || '' === trim($raw)) {
            return false;
        }

        return version_compare(self::languageProfileVersion(), '8.3.0', '>=');
    }

    /**
     * `final const` at compile-unit scope — never valid in php-src (Zend/zend_compile.c, #10324, #15185).
     *
     * Class-scoped `final const` remains valid via Stmt\ClassConst; only file/namespace scope is rejected.
     */
    public static function supportsFinalGlobalTypedConstants(): bool
    {
        return false;
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

    /**
     * Effective language-syntax profile for php-src-strict gates (#15357).
     *
     * {@see getenv()} `PHP_COMPILER_PROFILE` (`8.2`, `8.4`, …) overrides {@see VERSION} for
     * version-gated syntax (bare `throw;`, typed const, …). Unset uses VERSION — 8.4.0-dev
     * reference matches Zend 8.2 for PHP 8.4-only syntax.
     */
    public static function languageProfileVersion(): string
    {
        $raw = getenv('PHP_COMPILER_PROFILE');
        if (!\is_string($raw) || '' === $raw) {
            return self::VERSION;
        }
        $raw = trim($raw);
        if (preg_match('/^\d+\.\d+$/', $raw)) {
            return $raw.'.0';
        }
        if (preg_match('/^\d+\.\d+\.\d+/', $raw, $m)) {
            return $m[0];
        }

        return self::VERSION;
    }

    /**
     * PHP 8.3+ str_increment() / str_decrement() (ext/standard/string.c, issue #5697, #12378, #14518, #14709, #15026, #16292).
     *
     * Withheld on 8.4.0-dev reference profile (matches Zend 8.2 function_exists gate). Enable via
     * stable 8.4.0+ or explicit `PHP_COMPILER_PROFILE=8.3` / `8.4` forward profile.
     */
    public static function supportsStrIncrement(): bool
    {
        if (version_compare(self::VERSION, '8.3', '<')) {
            return false;
        }

        if (version_compare(self::VERSION, '8.4.0', '>=')) {
            return true;
        }

        $raw = getenv('PHP_COMPILER_PROFILE');
        if (!\is_string($raw) || '' === trim($raw)) {
            return false;
        }

        return version_compare(self::languageProfileVersion(), '8.3.0', '>=');
    }

    /**
     * str_increment()/str_decrement() visible to function_exists() — stable runtime or forward 8.3+ (#16292).
     *
     * Callable under forward profile via {@see supportsStrIncrement()}; withheld from introspection on 8.4.0-dev
     * reference harness like Zend 8.2.
     */
    public static function advertisesStrIncrement(): bool
    {
        if (version_compare(self::VERSION, '8.4.0', '>=')) {
            return true;
        }

        $raw = getenv('PHP_COMPILER_PROFILE');
        if (!\is_string($raw) || '' === trim($raw)) {
            return false;
        }

        $profile = self::languageProfileVersion();
        if (version_compare($profile, '8.4.0', '>=')) {
            return false;
        }

        return version_compare($profile, '8.3.0', '>=');
    }

    /**
     * PHP 8.3+ class_uses_recursive() (ext/standard/basic_functions.c, issue #6469, #12816, #16708).
     *
     * Gated on stable 8.4.0 / {@see languageProfileVersion()} so 8.4.0-dev reference profile
     * matches Zend 8.2 phantom gate; enable forward profile via `PHP_COMPILER_PROFILE=8.4`.
     */
    public static function supportsClassUsesRecursive(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * PHP 8.3+ #[\Override] compile-time validation (Zend/zend_compile.c, #6303, #11559, #12201, #15801).
     *
     * Withheld on 8.4.0-dev reference profile (matches Zend 8.2 — Override is a normal attribute).
     * Enable via stable 8.4.0+ or explicit `PHP_COMPILER_PROFILE=8.3` / `8.4` forward profile.
     * Distinct from advertisesOverrideAttributeClass() which may register the builtin class earlier (#12387).
     */
    public static function supportsOverrideAttribute(): bool
    {
        if (version_compare(self::VERSION, '8.3', '<')) {
            return false;
        }

        if (version_compare(self::VERSION, '8.4.0', '>=')) {
            return true;
        }

        $raw = getenv('PHP_COMPILER_PROFILE');
        if (!\is_string($raw) || '' === trim($raw)) {
            return false;
        }

        return version_compare(self::languageProfileVersion(), '8.3.0', '>=');
    }

    /**
     * PHP 8.3+ #[\Override] builtin attribute class advertisement (Zend/zend_attributes.c, #11902, #12387).
     *
     * Gated on stable 8.4.0 so 8.4.0-dev reference profile matches Zend 8.2 phantom gate.
     */
    public static function advertisesOverrideAttributeClass(): bool
    {
        return version_compare(self::VERSION, '8.4.0', '>=');
    }

    /** PHP 8.4+ #[\Deprecated] builtin attribute class advertisement (Zend/zend_attributes.c, #11902). */
    public static function advertisesDeprecatedAttributeClass(): bool
    {
        // Stable 8.4.0+ only — 8.4.0-dev reference profile matches Zend 8.2 phantom gate (#12588).
        return version_compare(self::VERSION, '8.4.0', '>=');
    }

    /**
     * PHP 8.4+ #[\Deprecated(message|since)] runtime E_USER_DEPRECATED at use sites (Zend/zend_execute.c, #16090).
     *
     * Gated on stable 8.4.0 / {@see languageProfileVersion()} so 8.4.0-dev reference profile matches Zend 8.2 silent gate.
     */
    public static function supportsDeprecatedAttributeRuntimeNotices(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /** PHP 8.4+ #[\NoDiscard] builtin attribute class advertisement (Zend/zend_attributes.c, #11902). */
    public static function advertisesNoDiscardAttributeClass(): bool
    {
        // Stable 8.4.0+ only — 8.4.0-dev reference profile matches Zend 8.2 phantom gate (#13706).
        return version_compare(self::VERSION, '8.4.0', '>=');
    }

    /**
     * PHP 8.3+ hex2bin() optional $strict parameter (ext/standard/string.c, #13116, #16081).
     *
     * Gated on stable 8.4.0 / {@see languageProfileVersion()} so 8.4.0-dev reference profile matches Zend 8.2 ArgumentCountError gate.
     */
    public static function supportsHex2binStrict(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /** PHP 8.4+ #[\EnumCases] builtin attribute class advertisement (Zend/zend_attributes.c, #13057). */
    public static function advertisesEnumCasesAttributeClass(): bool
    {
        // Stable 8.4.0+ only — 8.4.0-dev reference profile matches Zend 8.2 phantom gate (#13706).
        return version_compare(self::VERSION, '8.4.0', '>=');
    }

    /** PHP 8.3+ ReflectionConstant class advertisement (ext/reflection/php_reflection.c, #12385, #13497). */
    public static function advertisesReflectionConstantClass(): bool
    {
        // Stable 8.4.0+ only — 8.4.0-dev reference profile matches Zend 8.2 phantom gate.
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

    /**
     * Whether class constants may use `new Class(...)` initializers.
     *
     * php-src: Zend/zend_compile.c — zend_compile_const_expr() rejects all `new` in class
     * constant context ("New expressions are not supported in this context") on 8.2–8.4
     * (#15766, re-#15608). Forward profile must not enable this until php-src does (#12940).
     */
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

    /**
     * PHP 8.4+ fpow() IEEE float power (ext/standard/math.c; issue #7045, #12412, #15028, #15692).
     *
     * Gated on stable 8.4.0 / {@see languageProfileVersion()} so 8.4.0-dev reference profile matches Zend 8.2 phantom gate.
     */
    public static function supportsFpow(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * fpow()/fmin()/fmax() visible to function_exists() — stable runtime or forward 8.4+ (#16677).
     *
     * Callable under forward profile via {@see supportsFpow()}; withheld from introspection on 8.4.0-dev
     * reference harness like Zend 8.2.
     */
    public static function advertisesFpow(): bool
    {
        if (version_compare(self::VERSION, '8.4.0', '>=')) {
            return true;
        }

        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * PHP 8.4+ nextafter() IEEE next representable float (ext/standard/math.c; #9241, #15584, #15677).
     *
     * Gated on stable 8.4.0 / PHP_COMPILER_PROFILE=8.4 so 8.4.0-dev reference profile matches Zend 8.2 phantom gate.
     */
    public static function supportsNextafter(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /** nextafter() visible to function_exists() — stable runtime or forward 8.4+ (#16677). */
    public static function advertisesNextafter(): bool
    {
        if (version_compare(self::VERSION, '8.4.0', '>=')) {
            return true;
        }

        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * PHP 8.4+ RoundingMode builtin enum (ext/standard/basic_functions.stub.php; #5934, #14846, #15572, #15692).
     *
     * Gated on stable 8.4.0 / {@see languageProfileVersion()} so 8.4.0-dev reference profile matches Zend 8.2 phantom gate.
     */
    public static function supportsRoundingModeEnum(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * PHP 8.3+ number_format() negative $decimals (ext/standard/number_format.c, #15917).
     *
     * Prior to 8.3, negative values are ignored like 0. Gated on stable 8.4.0 or
     * PHP_COMPILER_PROFILE=8.3+ so 8.4.0-dev reference profile matches Zend 8.2.
     */
    public static function supportsNumberFormatNegativeDecimals(): bool
    {
        if (version_compare(self::VERSION, '8.3', '<')) {
            return false;
        }
        if (version_compare(self::VERSION, '8.4.0', '>=')) {
            return true;
        }
        $raw = getenv('PHP_COMPILER_PROFILE');
        if (!\is_string($raw) || '' === trim($raw)) {
            return false;
        }

        return version_compare(self::languageProfileVersion(), '8.3.0', '>=');
    }

    /**
     * PHP 8.3+ Random\IntervalBoundary unit enum (ext/random/random.stub.php; #11551, #14847).
     *
     * Gated on stable 8.4.0 so 8.4.0-dev reference profile matches Zend 8.2 phantom gate.
     */
    public static function supportsRandomIntervalBoundary(): bool
    {
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
     * PHP 8.4+ exit()/die() as proper functions — FCC, named args, two-arg (#6975, #12413, #12414, #12435, #13650, #13885, #13973).
     *
     * Gated on stable 8.4.0 so 8.4.0-dev reference profile rejects named/two-arg/FCC forms like Zend 8.2.
     * Forward profile via {@see languageProfileVersion()} enables exit(status:)/die(message:) on 8.4.0-dev (#13487).
     * Reference-profile rejection tests skip when this returns true (exit_named_status_reference_profile.phpt).
     */
    public static function supportsExitFunctionForm(): bool
    {
        if (version_compare(self::VERSION, '8.4.0', '>=')) {
            return true;
        }

        $raw = getenv('PHP_COMPILER_PROFILE');
        if (!\is_string($raw) || '' === trim($raw)) {
            return false;
        }

        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * PHP 8.4+ pipe operator (|>) — Zend/zend_language_parser.y (#7219, #12424, #16675).
     *
     * Gated on stable 8.4.0 / {@see languageProfileVersion()} so 8.4.0-dev reference profile
     * rejects |> like Zend 8.2. Forward profile via `PHP_COMPILER_PROFILE=8.4` enables desugar.
     */
    public static function supportsPipeOperator(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * PHP 8.3+ clone-with syntax (`clone $obj with { }`, `clone($obj, [...])`, `clone ($obj, with: [...])`).
     *
     * Gated on stable 8.4.0 so 8.4.0-dev reference profile rejects like Zend 8.2 parse error (#12987).
     * Forward profile via {@see languageProfileVersion()} enables clone-with on 8.4.0-dev (#16676).
     * php-src: Zend/zend_language_parser.y clone_expr with clause; zend_clones.c.
     */
    public static function supportsCloneWithSyntax(): bool
    {
        if (version_compare(self::VERSION, '8.4.0', '>=')) {
            return true;
        }

        $raw = getenv('PHP_COMPILER_PROFILE');
        if (!\is_string($raw) || '' === trim($raw)) {
            return false;
        }

        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * PHP 8.5+ comma-separated enum case declarations (`case A, B, C;`).
     *
     * Withheld on 8.4.0-dev reference profile (matches Zend 8.2 parse error). Enable via
     * stable 8.5.0+ or explicit `PHP_COMPILER_PROFILE=8.5` forward profile (#16665).
     * php-src: Zend/zend_language_parser.y enum_case_list (PHP 8.5); Zend/zend_compile.c.
     */
    public static function supportsEnumCaseList(): bool
    {
        if (version_compare(self::VERSION, '8.5.0', '>=')) {
            return true;
        }

        return version_compare(self::languageProfileVersion(), '8.5.0', '>=');
    }

    /**
     * PHP 8.4+ asymmetric property visibility (private(set), protected(set), …).
     *
     * Enabled on the 8.4 development line by default (#16068, #3165). Explicit
     * `PHP_COMPILER_PROFILE=8.2` (or older) keeps Zend 8.2 reference rejection (#12508).
     * php-src: Zend/zend_language_parser.y T_PRIVATE_SET; Zend/zend_compile.c ZEND_ACC_*_SET.
     */
    public static function supportsAsymmetricVisibility(): bool
    {
        if (version_compare(self::languageProfileVersion(), '8.4.0', '>=')) {
            return true;
        }
        $raw = getenv('PHP_COMPILER_PROFILE');
        if (\is_string($raw) && '' !== trim($raw)) {
            return false;
        }

        return self::MAJOR_VERSION > 8
            || (self::MAJOR_VERSION === 8 && self::MINOR_VERSION >= 4);
    }

    /**
     * PHP 8.4+ property hooks (`$prop { get; set; }`, default initializer + hook block).
     *
     * Enabled on the 8.4 development line by default (#15994, #16068). Explicit
     * `PHP_COMPILER_PROFILE=8.2` (or older) keeps Zend 8.2 reference rejection (#14062, #15800).
     * php-src: Zend/zend_language_parser.y / Zend/zend_compile.c property hooks.
     */
    public static function supportsPropertyHooks(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * PHP 8.4+ try/catch/else — else runs when no exception was thrown (#15817).
     *
     * Gated on stable 8.4.0 / {@see languageProfileVersion()} so 8.4.0-dev reference profile matches Zend 8.2
     * parse error. Enable forward profile on dev via `PHP_COMPILER_PROFILE=8.4`.
     * php-src: Zend/zend_language_parser.y try_catch_list else; zend_compile.c zend_compile_try.
     */
    public static function supportsTryCatchElse(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * PHP 8.4+ null coalesce (??) inside double-quoted `{$...}` interpolation (#14063, #14113, #15893).
     *
     * Default 8.4.0-dev reference profile rejects ?? in encapsed braces like Zend 8.2; forward profile
     * (`PHP_COMPILER_PROFILE=8.4`) enables desugar via {@see languageProfileVersion()}.
     * php-src: Zend/zend_language_parser.y encapsed variable grammar.
     */
    public static function supportsEncapsedCoalesce(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * PHP 8.4+ ReflectionProperty::{isReadable,isWritable} (ext/reflection/php_reflection.c, #13065, #13663, #15664).
     *
     * Gated on stable 8.4.0 / {@see languageProfileVersion()} so 8.4.0-dev reference profile matches Zend 8.2
     * (methods absent). Enable forward profile on dev via `PHP_COMPILER_PROFILE=8.4`.
     */
    public static function supportsReflectionPropertyAccessProbes(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * PHP 8.4+ ReflectionProperty::isDynamic() (ext/reflection/php_reflection.c, #7295, #15676).
     *
     * Gated on stable 8.4.0 / {@see languageProfileVersion()} so 8.4.0-dev reference profile matches Zend 8.2
     * (method absent). Enable forward profile on dev via `PHP_COMPILER_PROFILE=8.4`.
     */
    public static function supportsReflectionPropertyIsDynamic(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * PHP 8.4+ ReflectionEnumUnitCase::isDeprecated() (ext/reflection/php_reflection.c, #9864, #15767).
     *
     * Gated on stable 8.4.0 / {@see languageProfileVersion()} so 8.4.0-dev reference profile matches Zend 8.2
     * (method absent). Enable forward profile on dev via `PHP_COMPILER_PROFILE=8.4`.
     */
    public static function supportsReflectionEnumUnitCaseIsDeprecated(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * PHP 8.4+ ReflectionFunction::isDeprecated() (ext/reflection/php_reflection.c, #9760).
     *
     * Gated on stable 8.4.0 / {@see languageProfileVersion()} so 8.4.0-dev reference profile matches Zend 8.2
     * (method absent). Enable forward profile on dev via `PHP_COMPILER_PROFILE=8.4`.
     */
    public static function supportsReflectionFunctionIsDeprecated(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * PHP 8.4+ ReflectionProperty/ReflectionParameter::isDeprecated() (ext/reflection/php_reflection.c, #9768).
     *
     * Gated on stable 8.4.0 / {@see languageProfileVersion()} so 8.4.0-dev reference profile matches Zend 8.2
     * (method absent). Enable forward profile on dev via `PHP_COMPILER_PROFILE=8.4`.
     */
    public static function supportsReflectionPropertyParameterIsDeprecated(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * PHP 8.4+ ReflectionParameter::isSensitiveParameter() (ext/reflection/php_reflection.c, #16130).
     *
     * Gated on stable 8.4.0 / {@see languageProfileVersion()} so 8.4.0-dev reference profile matches Zend 8.2
     * (method absent). Enable forward profile on dev via `PHP_COMPILER_PROFILE=8.4`.
     */
    public static function supportsReflectionParameterIsSensitiveParameter(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * PHP 8.4+ class_has_method/property/constant() (ext/standard/basic_functions.c; issue #9989, #14722, #15025, #16664).
     *
     * Gated on stable 8.4.0 / {@see languageProfileVersion()} so 8.4.0-dev reference profile matches Zend 8.2
     * (functions absent). Enable forward profile on dev via `PHP_COMPILER_PROFILE=8.4`.
     */
    public static function supportsClassHasFunctions(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * PHP 8.4+ attribute_exists(), class_meth_exists(), unitenum_exists()
     * (ext/reflection/php_reflection.c, ext/standard/basic_functions.c; #14995, #15692).
     *
     * Gated on stable 8.4.0 / {@see languageProfileVersion()} so 8.4.0-dev reference profile matches Zend 8.2 phantom gate.
     */
    public static function supportsPhp84ReflectionProbeBuiltins(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * PHP 8.4+ zend_thread_id() (ext/standard/basic_functions.c, issue #6870, #11842, #12386, #15692).
     *
     * Gated on stable 8.4.0 / {@see languageProfileVersion()} so 8.4.0-dev reference profile matches Zend 8.2 phantom gate.
     */
    public static function supportsZendThreadId(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * zend_thread_id() visible to function_exists() — stable runtime only (#16357, re-#16292).
     *
     * Callable under forward profile via {@see supportsZendThreadId()}; withheld from introspection on 8.4.0-dev
     * reference harness like Zend 8.2.
     */
    public static function advertisesZendThreadId(): bool
    {
        return version_compare(self::VERSION, '8.4.0', '>=');
    }

    /**
     * PHP 8.4+ Sorting / SortDirection builtin enums (ext/standard/basic_functions.stub.php, #7229, #7261, #12362).
     *
     * Gated on stable 8.4.0 so 8.4.0-dev reference profile matches Zend 8.2 phantom gate.
     */
    public static function supportsSortingEnum(): bool
    {
        return self::supportsBuiltinStubEnums();
    }

    /**
     * PHP 8.4+ builtin stub enums (StringTrimMode, PadType, MemoryUsage, ExitStatus, …).
     *
     * Gated on stable 8.4.0 / {@see languageProfileVersion()} so 8.4.0-dev reference profile matches Zend 8.2 phantom gate (#13630, #15692).
     * php-src: Zend/zend_enum.def; ext/standard/basic_functions.stub.php
     */
    public static function supportsBuiltinStubEnums(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * PHP 8.4+ FTP\Connection internal class (ext/ftp/ftp.stub.php; #7270, #3353).
     *
     * Gated on stable 8.4.0 / {@see languageProfileVersion()} so 8.4.0-dev reference profile matches Zend 8.2 phantom gate.
     */
    public static function supportsFtpConnection(): bool
    {
        return self::supportsBuiltinStubEnums();
    }

    /**
     * PHP 8.3+ stream_supports() / STREAM_SUPPORT_* (ext/standard/streams.c, issue #11819, #13238, #15692).
     *
     * Gated on stable 8.4.0 / {@see languageProfileVersion()} so 8.4.0-dev reference profile matches Zend 8.2 phantom gate.
     */
    public static function supportsStreamSupports(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * PHP 8.3+ json_validate() (ext/json/php_json.c, issue #3101, #11826, #12363, #13365, #14518, #14708, #14972, #15026, #15196, #15241, #16091).
     *
     * Withheld on 8.4.0-dev reference profile (matches Zend 8.2 — json_validate absent).
     * Enable via stable 8.4.0+ or explicit `PHP_COMPILER_PROFILE=8.3` / `8.4` forward profile.
     */
    public static function supportsJsonValidate(): bool
    {
        if (version_compare(self::VERSION, '8.3', '<')) {
            return false;
        }

        if (version_compare(self::VERSION, '8.4.0', '>=')) {
            return true;
        }

        $raw = getenv('PHP_COMPILER_PROFILE');
        if (!\is_string($raw) || '' === trim($raw)) {
            return false;
        }

        return version_compare(self::languageProfileVersion(), '8.3.0', '>=');
    }

    /**
     * PHP 8.4+ readonly(object) dynamic object marker (ext/standard/basic_functions.c, #12607, #15692).
     *
     * Gated on stable 8.4.0 / {@see languageProfileVersion()} so 8.4.0-dev reference profile matches Zend 8.2 phantom gate.
     */
    public static function supportsReadonlyBuiltin(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * readonly() visible to function_exists() — stable runtime only (#16357, re-#16292).
     *
     * Callable under forward profile via {@see supportsReadonlyBuiltin()}; withheld from introspection on 8.4.0-dev
     * reference harness like Zend 8.2.
     */
    public static function advertisesReadonlyBuiltin(): bool
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
        return self::supportsBuiltinStubEnums();
    }

    /**
     * PHP 8.4+ createLazyGhost()/createLazyProxy() and ReflectionClass lazy factories (#6708, #12375).
     *
     * Gated on stable 8.4.0 so 8.4.0-dev reference profile matches Zend 8.2 phantom gate.
     */
    public static function supportsLazyObjectFactories(): bool
    {
        return version_compare(self::VERSION, '8.4.0', '>=');
    }

    /**
     * PHP 8.4+ gc_status() schema (running/protected/full/buffer_size; ext/standard/php_gc.c, #12780, #13673, #14431).
     *
     * Gated on stable 8.4.0 / {@see languageProfileVersion()} so 8.4.0-dev reference profile keeps legacy
     * runs/collected/threshold/roots (#12993, #13293, #14612, #15784); enable via `PHP_COMPILER_PROFILE=8.4`.
     */
    public static function supportsGcStatusPhp84Schema(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * PHP 8.4+ hrtime(true) returns double (ext/standard/hrtime.c, #12779).
     *
     * Gated on stable 8.4.0 so 8.4.0-dev reference profile keeps integer nanoseconds (#12789, #13696).
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
     * PHP 8.4+ get_defined_constants() optional $category named filter (ext/standard/basic_functions.c, #12947).
     *
     * Gated on stable 8.4.0 so 8.4.0-dev reference profile rejects unknown named param like Zend 8.2.
     */
    public static function supportsGetDefinedConstantsCategory(): bool
    {
        return version_compare(self::VERSION, '8.4.0', '>=');
    }

    /**
     * PHP 8.4+ http_get_last_response_headers()/get_last_response_headers()/http_clear_last_response_headers()
     * (ext/standard/http.c, issue #12855, #12948).
     *
     * Gated on stable 8.4.0 / {@see languageProfileVersion()} so 8.4.0-dev reference profile matches Zend 8.2 phantom gate (#14122, #15706).
     */
    public static function supportsHttpLastResponseHeaders(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * http_get_last_response_headers()/get_last_response_headers()/http_clear_last_response_headers()
     * visible to function_exists() — stable runtime or forward 8.4+ profile (#16346, #16494).
     */
    public static function advertisesHttpLastResponseHeaders(): bool
    {
        if (version_compare(self::VERSION, '8.4.0', '>=')) {
            return true;
        }

        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * PHP 8.4+ stream_context_set_options() (ext/standard/streams.c, #12597, #10056).
     *
     * Gated on stable 8.4.0 / {@see languageProfileVersion()} so 8.4.0-dev reference profile matches Zend 8.2 phantom gate (#15706).
     */
    public static function supportsStreamContextSetOptions(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /** stream_context_set_options() visible to function_exists() — stable runtime or forward 8.4+ profile (#16346, #16494). */
    public static function advertisesStreamContextSetOptions(): bool
    {
        if (version_compare(self::VERSION, '8.4.0', '>=')) {
            return true;
        }

        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * PHP 8.3+ mb_str_pad() (ext/mbstring/mbstring.c, issue #11964, #4006).
     *
     * Withheld on 8.4.0-dev reference profile (matches Zend 8.2 function_exists gate). Enable via
     * stable 8.4.0+ or explicit `PHP_COMPILER_PROFILE=8.3` / `8.4` forward profile.
     */
    public static function supportsMbStrPad(): bool
    {
        if (version_compare(self::VERSION, '8.3', '<')) {
            return false;
        }

        if (version_compare(self::VERSION, '8.4.0', '>=')) {
            return true;
        }

        $raw = getenv('PHP_COMPILER_PROFILE');
        if (!\is_string($raw) || '' === trim($raw)) {
            return false;
        }

        return version_compare(self::languageProfileVersion(), '8.3.0', '>=');
    }

    /**
     * mb_str_pad() visible to function_exists() — stable runtime only (#16086).
     *
     * Callable under forward profile via {@see supportsMbStrPad()}.
     */
    public static function advertisesMbStrPad(): bool
    {
        return version_compare(self::VERSION, '8.4.0', '>=');
    }

    /**
     * PHP 8.4+ grapheme_str_contains() (ext/intl/grapheme/grapheme.c, issue #7128, #16667).
     *
     * Withheld on 8.4.0-dev reference profile (matches Zend 8.2 function_exists gate). Enable via
     * stable 8.4.0+ or explicit `PHP_COMPILER_PROFILE=8.4` forward profile.
     */
    public static function supportsGraphemeStrContains(): bool
    {
        if (version_compare(self::VERSION, '8.4', '<')) {
            return false;
        }

        if (version_compare(self::VERSION, '8.4.0', '>=')) {
            return true;
        }

        $raw = getenv('PHP_COMPILER_PROFILE');
        if (!\is_string($raw) || '' === trim($raw)) {
            return false;
        }

        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /** grapheme_str_contains() visible to function_exists() — stable runtime or forward 8.4+ profile (#16667). */
    public static function advertisesGraphemeStrContains(): bool
    {
        if (version_compare(self::VERSION, '8.4.0', '>=')) {
            return true;
        }

        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * PHP 8.4+ grapheme_strimwidth() (ext/intl/grapheme/grapheme_string.c, issue #9793).
     */
    public static function supportsGraphemeStrimwidth(): bool
    {
        if (version_compare(self::VERSION, '8.4', '<')) {
            return false;
        }

        if (version_compare(self::VERSION, '8.4.0', '>=')) {
            return true;
        }

        $raw = getenv('PHP_COMPILER_PROFILE');
        if (!\is_string($raw) || '' === trim($raw)) {
            return false;
        }

        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /** grapheme_strimwidth() visible to function_exists() — stable runtime or forward 8.4+ profile (#16667). */
    public static function advertisesGraphemeStrimwidth(): bool
    {
        if (version_compare(self::VERSION, '8.4.0', '>=')) {
            return true;
        }

        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * PHP 8.3+ array_first_key()/array_last_key() (ext/standard/array.c, issue #15539, #15675).
     *
     * Withheld on 8.4.0-dev reference profile (matches Zend 8.2 function_exists gate). Enable via
     * stable 8.4.0+ or explicit `PHP_COMPILER_PROFILE=8.3` / `8.4` forward profile.
     */
    public static function supportsPhp83ArrayKeyFunctions(): bool
    {
        if (version_compare(self::VERSION, '8.3', '<')) {
            return false;
        }

        if (version_compare(self::VERSION, '8.4.0', '>=')) {
            return true;
        }

        $raw = getenv('PHP_COMPILER_PROFILE');
        if (!\is_string($raw) || '' === trim($raw)) {
            return false;
        }

        return version_compare(self::languageProfileVersion(), '8.3.0', '>=');
    }

    /**
     * PHP 8.4+ array_all/any/find/find_key/first/last (ext/standard/array.c, issue #11845, #12796, #14505, #14516, #14621, #14622, #15027, #15675).
     *
     * Withheld on 8.4.0-dev reference profile (matches Zend 8.2 function_exists gate). Enable via
     * stable 8.4.0+ or explicit `PHP_COMPILER_PROFILE=8.4` forward profile.
     */
    public static function supportsPhp84ArraySearchFunctions(): bool
    {
        if (version_compare(self::VERSION, '8.4.0', '>=')) {
            return true;
        }

        $raw = getenv('PHP_COMPILER_PROFILE');
        if (!\is_string($raw) || '' === trim($raw)) {
            return false;
        }

        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * PHP 8.4+ DateTime::getMicrosecond() / setMicrosecond() (ext/date/php_date.c, #7082, #14503).
     *
     * Gated on stable 8.4.0 so 8.4.0-dev reference profile matches Zend 8.2 phantom gate.
     */
    public static function supportsDateTimeMicrosecond(): bool
    {
        return version_compare(self::VERSION, '8.4.0', '>=');
    }

    /**
     * PHP 8.4+ Closure::getCurrent() (Zend/zend_closures.c, issue #13981, #14061, #14188, #14221, #14371, #14433, #14515, #14533, #15167, #15197, #15239, #15674).
     *
     * Gated on stable 8.4.0 / PHP_COMPILER_PROFILE=8.4 so 8.4.0-dev reference profile matches Zend 8.2 phantom gate.
     */
    public static function supportsClosureGetCurrent(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * PHP 8.4+ Closure::fromStatic() (Zend/zend_closures.c, issue #9992, #16666).
     *
     * Gated on stable 8.4.0 / PHP_COMPILER_PROFILE=8.4 so 8.4.0-dev reference profile matches Zend 8.2 phantom gate.
     */
    public static function supportsClosureFromStatic(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * PHP 8.4+ bare `throw;` catch rethrow on the forward profile (Zend/zend_compile.c, #3508, #15299, #15630).
     *
     * Gated on stable 8.4.0 / {@see languageProfileVersion()} so 8.4.0-dev reference profile matches
     * Zend 8.2 phantom gate (#14239, #15719); enable via `PHP_COMPILER_PROFILE=8.4`.
     */
    public static function supportsBareRethrow(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * PHP 8.4+ array_replace_key() (ext/standard/array.c, issue #5650, #12826).
     *
     * Gated on stable 8.4.0 so 8.4.0-dev reference profile matches Zend 8.2 phantom gate.
     */
    public static function supportsArrayReplaceKey(): bool
    {
        return version_compare(self::VERSION, '8.4.0', '>=');
    }

    /**
     * PHP 8.4+ array_pad() optional $pad_type + ARRAY_PAD_* constants (ext/standard/array.c, #14993).
     *
     * Forward profile on 8.4.0-dev — advertisesBuiltinSince treats -dev as 8.4.0 (#14983 reference gate).
     */
    public static function supportsArrayPadPadType(): bool
    {
        return self::advertisesBuiltinSince('8.4.0');
    }

    /**
     * PHP 8.4+ mb_trim/ltrim/rtrim (ext/mbstring/mbstring.c, issue #11901, #12797, #9977).
     *
     * Gated on stable 8.4.0 / {@see languageProfileVersion()} so 8.4.0-dev reference profile
     * matches Zend 8.2 phantom gate; enable forward profile via `PHP_COMPILER_PROFILE=8.4`.
     */
    public static function supportsMbTrimFunctions(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * PHP 8.3+ DateException / DateError hierarchy (ext/date/php_date.h, #7276, #7277, #13118, #15382, #16490).
     *
     * Withheld on 8.4.0-dev reference profile (matches Zend 8.2 Exception on malformed DateInterval).
     * Enable via stable 8.4.0+ or explicit `PHP_COMPILER_PROFILE=8.3` / `8.4` forward profile.
     */
    public static function advertisesDateExceptionHierarchy(): bool
    {
        if (version_compare(self::VERSION, '8.3', '<')) {
            return false;
        }
        if (version_compare(self::VERSION, '8.4.0', '>=')) {
            return true;
        }
        $raw = getenv('PHP_COMPILER_PROFILE');
        if (!\is_string($raw) || '' === trim($raw)) {
            return false;
        }

        return version_compare(self::languageProfileVersion(), '8.3.0', '>=');
    }

    /**
     * PHP 8.4+ RequestParseBodyException (ext/standard/http.c, #13124).
     *
     * Gated on stable 8.4.0 so 8.4.0-dev reference profile matches Zend 8.2 phantom gate.
     */
    public static function advertisesRequestParseBodyExceptionClass(): bool
    {
        return version_compare(self::VERSION, '8.4.0', '>=');
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

    /** ext/bz2 via pure PHP {@see \PHPCompiler\ext\bz2\VmBz2Core} — withheld on reference profile (#11992, #14219). */
    public static function supportsBz2(): bool
    {
        return false;
    }

    /**
     * ext/bcmath + BcMath\Number OOP (ext/bcmath/bcmath.c; #7220, #12131, #15705).
     *
     * Withheld on 8.4.0-dev reference profile; enabled when {@see languageProfileVersion()} is
     * stable 8.4+ ({@code PHP_COMPILER_PROFILE=8.4}) so phantom gates match Zend 8.2 CI.
     */
    public static function supportsBcmath(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * ext/bcmath surface visible to extension_loaded()/function_exists() — stable runtime only (#16086).
     */
    public static function advertisesBcmath(): bool
    {
        return version_compare(self::VERSION, '8.4.0', '>=');
    }

    /**
     * PHP 8.4+ bcround() (ext/bcmath/bcmath.c; #5935, #16709).
     *
     * Callable under forward profile via {@see supportsBcmath()}; advertised on stable 8.4+ or
     * {@code PHP_COMPILER_PROFILE=8.4} like fpow/nextafter (#16677).
     */
    public static function advertisesBcround(): bool
    {
        if (version_compare(self::VERSION, '8.4.0', '>=')) {
            return true;
        }

        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * PHP 8.4+ DOMNode::contains() (ext/dom/node.c, #14447, #14535, #14723, #15613).
     *
     * Gated on stable 8.4.0 / PHP_COMPILER_PROFILE=8.4 so 8.4.0-dev reference profile
     * matches Zend 8.2 phantom gate (#14599).
     */
    public static function supportsDomNodeContains(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * PHP 8.4+ DOMNode::compareDocumentPosition() (ext/dom/node.c, #14448, #15613).
     *
     * Gated on stable 8.4.0 / PHP_COMPILER_PROFILE=8.4 so 8.4.0-dev reference profile
     * matches Zend 8.2 phantom gate.
     */
    public static function supportsDomNodeCompareDocumentPosition(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * PHP 8.4+ DOMNode::getRootNode() (ext/dom/node.c, #14449, #14599).
     *
     * Gated on stable 8.4.0 / PHP_COMPILER_PROFILE=8.4 so 8.4.0-dev reference profile
     * matches Zend 8.2 phantom gate.
     */
    public static function supportsDomNodeGetRootNode(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * PHP 8.4+ DOMNode::isEqualNode() (ext/dom/node.c, #15195, #14599).
     *
     * Gated on stable 8.4.0 / PHP_COMPILER_PROFILE=8.4 so 8.4.0-dev reference profile
     * matches Zend 8.2 phantom gate.
     */
    public static function supportsDomNodeIsEqualNode(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * PHP 8.4+ DOMElement::insertAdjacentHTML() (ext/dom/dom_element.c, #16128).
     *
     * Gated on stable 8.4.0 / PHP_COMPILER_PROFILE=8.4 so 8.4.0-dev reference profile
     * matches Zend 8.2 phantom gate.
     */
    public static function supportsDomElementInsertAdjacentHtml(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * PHP 8.4+ parenthesized asymmetric set modifier `public (private(set))` on properties.
     *
     * Gated on stable 8.4.0 / {@see languageProfileVersion()} so 8.4.0-dev reference profile
     * rejects like Zend 8.2 (#16450); forward profile enables rewriter (#11546).
     * php-src: Zend/zend_compile.c asymmetric visibility scope parsing.
     */
    public static function supportsParenthesizedAsymmetricSetModifier(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * PHP 8.3+ parenthesized DNF intersection-only types `(I1&I2) $param` / `(): (I1&I2)`.
     *
     * Gated on stable 8.4.0 / {@see languageProfileVersion()} so 8.4.0-dev reference profile
     * rejects like Zend 8.2 (#14904); forward profile enables rewriter (#15792).
     * php-src: Zend/zend_compile.c zend_compile_type / DNF normalization.
     */
    public static function supportsParenthesizedDnfIntersectionTypes(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }
}
