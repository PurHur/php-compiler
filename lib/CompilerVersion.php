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

    /** Build timestamp for phpinfo() INFO_GENERAL Build Date row (kept empty on reference profile; #12141). */
    public const BUILD_DATE = '';

    /**
     * Deterministic {@see PHP_BUILD_DATE} stamp when profile ≥ 8.5 (php-src main/php_version.h; #23231).
     * Format matches php-src {@code __DATE__}/{@code __TIME__}: {@code M j Y H:i:s}.
     */
    public const PHP_BUILD_DATE_STAMP = 'Jan 1 2026 00:00:00';

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

    /**
     * PHP 8.3+ typed constants on interfaces (Zend/zend_compile.c, issue #5980, #7042, #24917).
     *
     * Same withholding as supportsTypedClassConstants(): an interface typed constant is a typed
     * class constant, and Zend 8.2 rejects both with the same parse error. Do not use a plain
     * VERSION compare here — that re-enabled SKIPIF acceptance on the 8.4.0-dev reference profile
     * while the class form stayed withheld (#24809 / #24917).
     */
    public static function supportsInterfaceTypedConstants(): bool
    {
        return self::supportsTypedClassConstants();
    }

    /**
     * PHP 8.3+ typed class constants on classes/enums (Zend/zend_compile.c, #3592, #12798, #12994, #15367, #22705, #24809).
     *
     * Withheld on 8.4.0-dev reference profile (phpversion() 8.2.31 matches Zend 8.2 parse error).
     * Enable via stable 8.4.0+ or explicit `PHP_COMPILER_PROFILE=8.3` / `8.4` forward profile.
     * Do not use VERSION_ID / isForwardProfileAtLeast here — that re-enabled acceptance on default
     * after #24719 and broke Zend 8.2 parity (#24809, re-#22782/#22705).
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
     * PHP 8.3+ dynamic class constant fetch (`C::{$name}`, `$cls::{$name}`).
     *
     * Rejected on the 8.4.0-dev reference profile (matches Zend 8.2 parse error). Enable via stable
     * 8.4.0+ or explicit `PHP_COMPILER_PROFILE=8.3` / `8.4` forward profile (#17863, re-#17801, #24823).
     * Do not use bare `VERSION >= 8.3` — VERSION is `8.4.0-dev`, which would always enable and break
     * PROFILE=8.2 / default php-src-strict parity (#24725 regression).
     * php-src: Zend/zend_language_parser.y class_constant; Zend/zend_compile.c.
     */
    public static function supportsDynamicClassConstFetch(): bool
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
    /**
     * PHP 8.3+ one-shot readonly property reinit during `__clone()` (#23526, #15365).
     *
     * Withheld on 8.4.0-dev reference / PROFILE=8.2 (Zend 8.2 throws Error). Enable via stable
     * 8.4.0+ or explicit `PHP_COMPILER_PROFILE=8.3` / `8.4` forward profile.
     * php-src: Zend/zend_readonly.c IS_PROP_REINITABLE during zend_objects_clone_obj.
     */
    public static function supportsReadonlyCloneReinit(): bool
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
     * PHP 8.5+ #[\Deprecated] on file/namespace constants (Attribute::TARGET_CONSTANT).
     *
     * php-src Zend/zend_attributes.stub.php: Deprecated gains TARGET_CONSTANT only in 8.5;
     * Zend 8.4 parse-errors `#[\Deprecated] const` (`syntax error, unexpected token "const"`).
     * Withheld on ≤8.4 (reference + PROFILE=8.4). Enable via stable 8.5.0+ or
     * `PHP_COMPILER_PROFILE=8.5` (#16819, #26308).
     */
    public static function supportsGlobalDeprecatedConstAttributes(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.5.0', '>=');
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
     * PHP 8.3+ arbitrary function-local static initializers (Zend/zend_compile.c, #22923).
     *
     * On PHP &lt; 8.3, `static $a = $param` is a compile fatal ("Constant expression contains
     * invalid operations"). PHP 8.3+ allows non-constant expressions and evaluates them once.
     * Withheld on 8.4.0-dev reference profile (matches Zend 8.2). Enable via stable 8.4.0+ or
     * explicit `PHP_COMPILER_PROFILE=8.3` / `8.4` forward profile.
     */
    public static function supportsArbitraryStaticVariableInitializers(): bool
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
     * PHP 8.4+ `final const` at compile-unit scope (Zend/zend_compile.c, #15165, #16859).
     *
     * Rejected on reference profile and PHP 8.3 forward profile (#10324, #15185). Class-scoped
     * `final const` remains valid via Stmt\ClassConst at all versions.
     */
    public static function supportsFinalGlobalTypedConstants(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * Version profile for builtin advertisement / function_exists parity (#11842).
     *
     * On the 8.4 development line, some forward-compat builtins use explicit PROFILE gates
     * (array_find family, json_validate, str_increment, …) and withhold on the unset-PROFILE
     * reference harness while VERSION is 8.4.0-dev — version_compare treats -dev below stable
     * (#12327, #12328, #22544, #24808, #24820, #24821).
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
     * Whether the compiler's effective profile is at least $minVersion.
     *
     * Handles the 8.4.0-dev case: version_compare treats -dev as below stable, but the compiler
     * IS version 8.4 and should support 8.4 language features by default — same logic as
     * {@see builtinAdvertisementVersion()} which already maps MAJOR.MINOR >= 8.4 to '8.4.0'.
     *
     * Explicit PHP_COMPILER_PROFILE overrides as usual.
     */
    private static function isForwardProfileAtLeast(string $minVersion): bool
    {
        $profile = self::languageProfileVersion();
        if (version_compare($profile, $minVersion, '>=')) {
            return true;
        }

        $raw = getenv('PHP_COMPILER_PROFILE');
        if (\is_string($raw) && '' !== trim($raw)) {
            return false;
        }

        [$minMajor, $minMinor] = array_map('intval', explode('.', $minVersion));

        return self::MAJOR_VERSION > $minMajor
            || (self::MAJOR_VERSION === $minMajor && self::MINOR_VERSION >= $minMinor);
    }

    /**
     * PHP 8.3+ str_increment() / str_decrement() (ext/standard/string.c, issue #5697, #12378, #14518, #14709, #15026, #16292, #24820).
     *
     * Withheld on 8.4.0-dev reference profile (matches Zend 8.2 function_exists gate when reported
     * PHP_VERSION is the 8.2 reference string). Enable via stable 8.4.0+ or explicit
     * `PHP_COMPILER_PROFILE=8.3` / `8.4` forward profile (#16292, #18614, #22544).
     *
     * Do not use {@see isForwardProfileAtLeast()} here — that would re-advertise on unset PROFILE
     * while {@see phpversion()} still reports {@see REFERENCE_PHP_VERSION} (#24746 regression / #24820).
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
     * str_increment()/str_decrement() visible to function_exists() — same gate as registration (#16292, #18614, #24820).
     *
     * Withheld on 8.4.0-dev reference harness (no {@code PHP_COMPILER_PROFILE}) like Zend 8.2.
     */
    public static function advertisesStrIncrement(): bool
    {
        return self::supportsStrIncrement();
    }

    /**
     * PHP 8.3+ get_object_id() (ext/standard/basic_functions.c, issue #3537, #17564).
     *
     * Withheld on 8.4.0-dev reference profile (matches Zend 8.2 function_exists gate). Enable via
     * stable 8.4.0+ or explicit `PHP_COMPILER_PROFILE=8.3` / `8.4` forward profile.
     */
    public static function supportsGetObjectId(): bool
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
     * get_object_id() visible to function_exists() — stable runtime or forward 8.3+ (#17564, #17607).
     *
     * Callable under forward profile via {@see supportsGetObjectId()}; withheld from introspection on
     * 8.4.0-dev reference harness like Zend 8.2.
     */
    public static function advertisesGetObjectId(): bool
    {
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
     * PHP 8.6+ clamp() (ext/standard/math.c; RFC clamp_v2, #21022).
     *
     * Withheld on ≤8.5 profiles (matches Zend — clamp landed in php-src 8.6). Enable via
     * stable 8.6.0+ or explicit `PHP_COMPILER_PROFILE=8.6` forward profile.
     */
    public static function supportsClamp(): bool
    {
        if (version_compare(self::VERSION, '8.6.0', '>=')) {
            return true;
        }

        return version_compare(self::languageProfileVersion(), '8.6.0', '>=');
    }

    /**
     * clamp() visible to function_exists() — same gate as registration (#21022).
     */
    public static function advertisesClamp(): bool
    {
        return self::supportsClamp();
    }

    /**
     * class_uses_recursive() — absent from php-src (class_uses() only; ext/spl/spl.stub.php /
     * basic_functions.stub.php).
     *
     * Never register or advertise on php-src-strict profiles (including PROFILE=8.3/8.4/8.5).
     * Forward-profile enable (#16708/#17137) retired by #28365 (re-#12816).
     */
    public static function supportsClassUsesRecursive(): bool
    {
        return false;
    }

    /** class_uses_recursive() visible to function_exists() — never (php-src absent, #28365). */
    public static function advertisesClassUsesRecursive(): bool
    {
        return false;
    }

    /**
     * PHP 8.3+ #[\Override] compile-time validation (Zend/zend_compile.c, #6303, #11559, #12201, #15801, #22142).
     *
     * Withheld on the 8.4.0-dev reference profile (matches Zend 8.2 — attribute is inert / unvalidated).
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
     * PHP 8.5+ #[\Override] on properties (Attribute::TARGET_PROPERTY).
     *
     * php-src PHP-8.4 zend_attributes.stub.php: Override = TARGET_METHOD only.
     * PHP-8.5 adds TARGET_PROPERTY (#25138). Enable via stable 8.5.0+ or
     * explicit {@code PHP_COMPILER_PROFILE=8.5} forward profile.
     */
    public static function supportsOverridePropertyTarget(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.5.0', '>=');
    }

    /**
     * PHP 8.4+ forward-profile builtin attribute classes on 8.4.0-dev reference builds (#13706, #16977).
     *
     * Withheld when {@see PHP_COMPILER_PROFILE} is unset (matches Zend 8.2 phantom gate even if the
     * harness exports a forward profile). Enable via stable 8.4.0+ or explicit profile opt-in.
     */
    private static function advertisesForwardProfile84BuiltinAttributeClass(): bool
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
     * PHP 8.3+ #[\Override] builtin attribute class advertisement (Zend/zend_attributes.c, #11902, #12387, #16825).
     *
     * Gated on stable 8.4.0 / explicit forward profile so 8.4.0-dev reference matches Zend 8.2 phantom gate.
     */
    public static function advertisesOverrideAttributeClass(): bool
    {
        if (self::supportsOverrideAttribute()) {
            return true;
        }

        return self::advertisesForwardProfile84BuiltinAttributeClass();
    }

    /** PHP 8.4+ #[\Deprecated] builtin attribute class advertisement (Zend/zend_attributes.c, #11902, #17318). */
    public static function advertisesDeprecatedAttributeClass(): bool
    {
        return self::advertisesForwardProfile84BuiltinAttributeClass();
    }

    /**
     * PHP 8.4+ #[\Deprecated] runtime E_USER_DEPRECATED at use sites (Zend/zend_execute.c, #16090, #27825).
     *
     * Bare attribute (no message/since) still emits; message/since only shape the notice text.
     * Gated on stable 8.4.0 / {@see languageProfileVersion()} so 8.4.0-dev reference profile matches Zend 8.2 silent gate.
     */
    public static function supportsDeprecatedAttributeRuntimeNotices(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * PHP 8.5+ #[\Deprecated] on traits — use-site E_USER_DEPRECATED + reject on interfaces/classes
     * (Zend/zend_attributes.c validate_deprecated, rfc:deprecated_traits, #22989).
     *
     * Withheld on ≤8.4 profiles (matches Zend 8.4 — attribute on traits is inert; interfaces not fatal yet).
     * Enable via stable 8.5.0+ or explicit `PHP_COMPILER_PROFILE=8.5` forward profile.
     */
    public static function supportsDeprecatedTraitAttribute(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.5.0', '>=');
    }

    /** PHP 8.5+ #[\NoDiscard] — absent from Zend 8.4 (#24946, zend_attributes.stub.php). */
    public static function advertisesNoDiscardAttributeClass(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.5.0', '>=');
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
        return self::advertisesForwardProfile84BuiltinAttributeClass();
    }

    /**
     * PHP 8.3+ ReflectionConstant class advertisement (ext/reflection/php_reflection.c, #12385, #13497, #16837, #25504).
     *
     * Withheld on 8.4.0-dev reference profile (matches Zend 8.2 class_exists gate). Enable via
     * stable 8.4.0+ or explicit `PHP_COMPILER_PROFILE=8.3` / `8.4` forward profile.
     * Do not use {@see isForwardProfileAtLeast()} / bare languageProfileVersion()>=8.4 — that
     * withholds the class under PROFILE=8.3 while php-src has had it since 8.3.0 (#25504).
     */
    public static function advertisesReflectionConstantClass(): bool
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
     * PHP 8.5+ ReflectionConstant::getFileName()/getExtension()/getExtensionName()
     * (ext/reflection/php_reflection.stub.php — absent on PHP-8.4.x stubs; #21551, #22662).
     *
     * Withheld on ≤8.4 profiles (php-src-strict phantom gate). getDocComment()/getStartLine()/
     * getEndLine() are never on ReflectionConstant in php-src — do not advertise them.
     */
    public static function advertisesReflectionConstantFileExtensionApis(): bool
    {
        if (version_compare(self::VERSION, '8.5.0', '>=')) {
            return true;
        }

        return version_compare(self::languageProfileVersion(), '8.5.0', '>=');
    }

    /**
     * PHP 8.5+ ReflectionConstant::getAttributes()
     * (ext/reflection/php_reflection.stub.php — absent on PHP-8.4.x stubs; #28157).
     *
     * Withheld on ≤8.4 profiles (php-src-strict phantom gate). ReflectionClassConstant::getAttributes()
     * is unrelated and remains available whenever that class is registered.
     */
    public static function advertisesReflectionConstantGetAttributes(): bool
    {
        if (version_compare(self::VERSION, '8.5.0', '>=')) {
            return true;
        }

        return version_compare(self::languageProfileVersion(), '8.5.0', '>=');
    }

    /**
     * PHP 8.6+ ReflectionConstant::inNamespace() (php/php-src#20902, master 2026-02-17).
     *
     * Absent on PHP-8.4 / PHP-8.5 stubs — withhold on ≤8.5 profiles (#22662).
     */
    public static function advertisesReflectionConstantInNamespace(): bool
    {
        if (version_compare(self::VERSION, '8.6.0', '>=')) {
            return true;
        }

        return version_compare(self::languageProfileVersion(), '8.6.0', '>=');
    }

    /** PHP 8.5+ #[\DelayedTargetValidation] — absent from Zend 8.4 (#24946). */
    public static function advertisesDelayedTargetValidationAttributeClass(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.5.0', '>=');
    }

    /** PHP 8.4+ #[\CompileTime] builtin attribute class advertisement (#11902). */
    public static function advertisesCompileTimeAttributeClass(): bool
    {
        return self::advertisesForwardProfile84BuiltinAttributeClass();
    }

    /**
     * Whether class constants may use `new Class(...)` initializers.
     *
     * Always false: Zend/php-src rejects `new` in class constant expressions on every shipping
     * version (including 8.4) — "New expressions are not supported in this context"
     * ({@see Zend/zend_compile.c} zend_compile_const_expr). The "new in initializers" RFC covers
     * parameter defaults, static variables, and attribute args only — not class constants (#21493).
     * Prior forward-profile enables (#12940, #15693) were incorrect vs php-src-strict.
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

    /**
     * PHP 8.5+ #[\NoDiscard] unused-return E_WARNING — absent from Zend 8.4 (#24946).
     */
    public static function supportsNoDiscardAttribute(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.5.0', '>=');
    }

    /**
     * PHP 8.5+ `(void)` cast statement (`T_VOID_CAST`) — discards a value / suppresses #[\NoDiscard].
     *
     * php-src: Zend/zend_language_parser.y `T_VOID_CAST expr ';'` (statement, not expression).
     * Withheld on PROFILE≤8.4 (Zend 8.4 has no T_VOID_CAST). Enable via stable 8.5.0+ or
     * explicit `PHP_COMPILER_PROFILE=8.5` (#28441; supersedes inverted #28183).
     */
    public static function supportsVoidCast(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.5.0', '>=');
    }

    /** PHP 8.5+ #[\DelayedTargetValidation] builtin attribute class — absent from Zend 8.4 (#24946). */
    public static function supportsDelayedTargetValidationAttribute(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.5.0', '>=');
    }

    /** PHP 8.4+ #[\CompileTime] builtin attribute class (zend_attributes.stub.php, issue #7101). */
    public static function supportsCompileTimeAttribute(): bool
    {
        return version_compare(self::VERSION, '8.4', '>=');
    }

    /**
     * PHP 8.3+ E_WARNING when ++/-- on bool or -- on null has no effect (zend_operators.c, #26378).
     *
     * RFC saner-inc-dec-operators. Withheld on 8.4.0-dev reference profile (matches Zend 8.2 silence).
     * Enable via stable 8.4.0+ or explicit `PHP_COMPILER_PROFILE=8.3` / `8.4` forward profile.
     */
    public static function supportsIncDecNoEffectWarning(): bool
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
     * PHP 8.4+ deprecates implicit nullable typed params (`int $x = null`) at compile time.
     *
     * php-src: Zend/zend_compile.c (zend_compile_params), RFC deprecate-implicitly-nullable-types.
     */
    public static function supportsImplicitNullableParameterDeprecation(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * PHP 8.5+ deprecates null as an array offset / array_key_exists() key (coerce to "").
     *
     * php-src: Zend/zend_execute.c / zend_vm_def.h; ext/standard/array.c (#26276).
     * RFC: deprecations_php_8_5 — null array offset / array_key_exists.
     * Enable via stable 8.5.0+ or explicit {@code PHP_COMPILER_PROFILE=8.5}.
     */
    public static function supportsNullArrayOffsetDeprecation(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.5.0', '>=');
    }

    /**
     * PHP 8.3+ typed illegal array/string offset TypeError messages (#26380).
     *
     * Replaces bare {@code Illegal offset type} with
     * {@code Cannot access offset of type %s on array} (zend_zval_type_name; class name for objects/enums).
     * php-src: Zend/zend.c — zend_illegal_container_offset(); Zend/zend_API.c — zend_zval_type_name().
     *
     * Withheld on 8.4.0-dev reference profile (matches Zend 8.2 "Illegal offset type"). Enable via
     * stable 8.4.0+ or explicit {@code PHP_COMPILER_PROFILE=8.3} / {@code 8.4} forward profile.
     * Same withhold shape as {@see supportsTypedClassConstants()} — do not use bare
     * languageProfileVersion() alone (VERSION is 8.4.0-dev).
     */
    public static function supportsTypedIllegalContainerOffset(): bool
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
     * PHP 8.4+ TENTATIVE_RETURN Core constant (Zend/zend_attributes.h, issue #18060).
     *
     * Withheld on 8.4.0-dev reference profile (matches Zend 8.2). Enable via stable 8.4.0+ or
     * explicit `PHP_COMPILER_PROFILE=8.4` forward profile.
     */
    public static function supportsTentativeReturnConstant(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * PHP 8.4+ PHP_SBINDIR Core path constant (php-src main/main.c REGISTER_MAIN_STRINGL_CONSTANT; #28170).
     *
     * Withheld on ≤8.3 profiles (matches Zend — constant landed in 8.4). Enable via stable 8.4.0+
     * or explicit {@code PHP_COMPILER_PROFILE=8.4} / {@code 8.5} forward profile.
     */
    public static function supportsPhpSbindirConstant(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * PHP 8.4+ PHP_OUTPUT_HANDLER_PROCESSED Core constant (main/php_output.h; #28169).
     *
     * Withheld on ≤8.3 profiles (matches Zend). Enable via stable 8.4.0+ or explicit
     * {@code PHP_COMPILER_PROFILE=8.4} / {@code 8.5}.
     */
    public static function supportsPhpOutputHandlerProcessedConstant(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * PHP 8.5+ PHP_BUILD_DATE Core constant (php-src main/php_version.h / main/main.c; #23231).
     *
     * Withheld on ≤8.4 profiles (matches Zend — constant landed in 8.5). Enable via stable 8.5.0+
     * or explicit {@code PHP_COMPILER_PROFILE=8.5} forward profile. Do not force-define
     * PHP_BUILD_PROVIDER (optional configure stamp only).
     */
    public static function supportsPhpBuildDateConstant(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.5.0', '>=');
    }

    /** Parseable {@code M j Y H:i:s} stamp for {@see PHP_BUILD_DATE} (#23231). */
    public static function phpBuildDateStamp(): string
    {
        return self::PHP_BUILD_DATE_STAMP;
    }

    /**
     * PHP 8.3+ HTTP_TOO_EARLY constant (ext/standard/http.c, issue #18059).
     *
     * Withheld on 8.4.0-dev reference profile (matches Zend 8.2). Enable via stable 8.4.0+ or
     * explicit `PHP_COMPILER_PROFILE=8.3` / `8.4` forward profile.
     */
    public static function supportsHttpTooEarlyConstant(): bool
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
     * PHP 8.3+ range() TypeError for array/object/resource endpoints (proper-range-semantics RFC).
     *
     * Withheld on 8.4.0-dev reference profile (matches Zend 8.2 zval_get_long coerce). Enable via
     * stable 8.4.0+ or explicit `PHP_COMPILER_PROFILE=8.3` / `8.4` forward profile (#23592).
     * php-src: ext/standard/array.c PHP_FUNCTION(range)
     */
    public static function supportsRangeStrictEndpointTypes(): bool
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
     * PHP 8.4+ fpow() IEEE float power (ext/standard/math.c; issue #7045, #12412, #15028, #15692).
     *
     * Gated on stable 8.4.0 / {@see languageProfileVersion()} so 8.4.0-dev reference profile matches Zend 8.2 phantom gate.
     */
    public static function supportsFpow(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * PHP 8.4+ ini_get()/ini_set() $option — historically gated int TypeError (#17268).
     *
     * Zend 8.4 Z_PARAM_STR soft-coerces null and int; callers must use trim-family /
     * soft helpers (#21312). Kept returning false so leftover gates stay soft.
     * php-src: ext/standard/ini.c / basic_functions.stub.php.
     */
    public static function iniOptionRequiresStrictStringType(): bool
    {
        return false;
    }

    /**
     * json_decode() $json — soft-null (DEP+coerce) on 8.4 forward profile, matching Zend (#21223).
     *
     * Same soft-null path as {@see jsonValidateStringOperandRequiresStrictType()} (#28333).
     */
    public static function jsonStringOperandRequiresStrictType(): bool
    {
        return false;
    }

    /**
     * json_validate() $json — soft-null (DEP+coerce to '') then false under PROFILE≥8.4 (#28333).
     *
     * Prior tickets (#27995/#26190) claimed Zend TypeError; real Zend 8.4 uses Z_PARAM_STR soft-null
     * (E_DEPRECATED + empty string → invalid JSON → false). Kept returning false so leftover gates
     * stay soft. php-src: ext/json/json.stub.php `json_validate(string $json, …): bool`.
     */
    public static function jsonValidateStringOperandRequiresStrictType(): bool
    {
        return false;
    }

    /**
     * fpow()/fmin()/fmax()/fadd()/fsub()/fmul() visible to function_exists() — stable runtime or forward 8.4+ (#16677).
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
     * PHP 8.3+ number_format() negative $decimals (ext/standard/math.c, #15917, #27899).
     *
     * Prior to 8.3, negative values are ignored like 0. From 8.3 onward php-src rounds with the
     * negative place count then clamps display precision with MAX(0, dec) — no ValueError on 8.4+.
     * Prior closes (#17261 / #17369) treated ValueError as Zend-correct; that does not match
     * `_php_math_number_format_ex` on PHP-8.3 through master.
     *
     * Requires explicit `PHP_COMPILER_PROFILE=8.3` / `8.4` / `8.5` so the 8.4.0-dev reference
     * profile (unset PROFILE) still matches Zend 8.2 ignore-as-0.
     */
    public static function supportsNumberFormatNegativeDecimals(): bool
    {
        if (version_compare(self::VERSION, '8.3', '<')) {
            return false;
        }
        $raw = getenv('PHP_COMPILER_PROFILE');
        if (!\is_string($raw) || '' === trim($raw)) {
            return false;
        }

        return version_compare(self::languageProfileVersion(), '8.3.0', '>=');
    }

    /**
     * PHP 8.3+ Random\IntervalBoundary unit enum (ext/random/random.stub.php; #11551, #14847, #17292).
     *
     * Requires explicit `PHP_COMPILER_PROFILE=8.3` / `8.4` so the 8.4.0-dev reference profile matches Zend 8.2.
     */
    public static function supportsRandomIntervalBoundary(): bool
    {
        if (version_compare(self::VERSION, '8.3', '<')) {
            return false;
        }
        $raw = getenv('PHP_COMPILER_PROFILE');
        if (!\is_string($raw) || '' === trim($raw)) {
            return false;
        }

        return version_compare(self::languageProfileVersion(), '8.3.0', '>=');
    }

    /**
     * get_declared_* optional $exclude_deprecated — never shipped by Zend/php-src (#27900 / #12403).
     *
     * php-src Zend/zend_builtin_functions.stub.php keeps arity 0 through master
     * (`function get_declared_classes(): array {}` and the interfaces/traits twins). Prior
     * forward-profile gate (#4711 / #12177) was wrong-direction; always withhold.
     */
    public static function supportsGetDeclaredExcludeDeprecated(): bool
    {
        return false;
    }

    /**
     * Historical forward-profile gate for get_class() optional $allow_string (#17395).
     *
     * php-src Zend/zend_builtin_functions.stub.php (incl. PHP 8.4) does **not** give get_class()
     * or get_parent_class() an $allow_string parameter — that belongs to is_a / is_subclass_of.
     * Always withhold: get_parent_class (#23948) and get_class (#28310) stay arity 1 on every profile.
     */
    public static function supportsGetClassAllowString(): bool
    {
        return false;
    }

    /**
     * PHP 8.3+ E_DEPRECATED for get_class()/get_parent_class() without arguments (#26369).
     *
     * php-src: Zend/zend_builtin_functions.c — "Calling get_class() without arguments is deprecated"
     * (same for get_parent_class). Withheld on 8.4.0-dev reference profile (matches Zend 8.2 silence).
     * Enable via stable 8.4.0+ or explicit `PHP_COMPILER_PROFILE=8.3` / `8.4` forward profile.
     */
    public static function supportsGetClassParentClassParameterlessDeprecation(): bool
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
     * PHP 8.2+ get_defined_functions() optional $exclude_disabled (ext/standard/basic_functions.c, #4942).
     *
     * Unlike {@see supportsGetDeclaredExcludeDeprecated()} (PHP 8.4-only), Zend exposes this on the
     * reference profile — BuiltinParamNames must register exclude_disabled without forward gating.
     */
    public static function supportsGetDefinedFunctionsExcludeDisabled(): bool
    {
        return version_compare(self::REFERENCE_PHP_VERSION, '8.2.0', '>=');
    }

    /**
     * get_defined_functions() $exclude_disabled deprecated-internal stripping (#4942, #16969, #16978).
     *
     * php-src omits disabled functions only — deprecated-yet-enabled builtins such as utf8_encode
     * stay in the internal list on every profile. Prior forward-profile filtering was incorrect.
     */
    public static function supportsGetDefinedFunctionsExcludeDeprecatedInternals(): bool
    {
        return false;
    }

    /**
     * PHP 8.4+ exit()/die() as proper functions — FCC, named status (#6975, #12413, #12435, #13650, #13885, #13973, #23957).
     *
     * Gated on stable 8.4.0 so 8.4.0-dev reference profile rejects named/multi-arg/FCC forms like Zend 8.2.
     * Forward profile via {@see languageProfileVersion()} enables exit(status:)/die(status:) on 8.4.0-dev (#13487).
     * php-src arity is a single optional $status (string|int); excess args → ArgumentCountError (#23957).
     * Reference-profile rejection tests skip when this returns true (exit_named_status_reference_profile.phpt).
     */
    public static function supportsExitFunctionForm(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * PHP 8.5+ pipe operator (|>) — Zend/zend_language_parser.y (#7219, #12424, #16675, #22792).
     *
     * Withheld on reference / PROFILE=8.4 (matches Zend 8.2 / 8.4 parse error). Enable via
     * stable 8.5.0+ or explicit `PHP_COMPILER_PROFILE=8.5` forward profile.
     */
    public static function supportsPipeOperator(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.5.0', '>=');
    }

    /**
     * PHP 8.5+ casts in constant expressions — Zend/zend_ast.c / zend_compile.c (#24947).
     *
     * Scalar/(array) casts are allowed; (object)/(void)/(unset) remain invalid. Withheld on
     * reference / PROFILE≤8.4 (matches Zend; #24905). Enable via stable 8.5.0+ or
     * `PHP_COMPILER_PROFILE=8.5`.
     */
    public static function supportsCastsInConstantExpressions(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.5.0', '>=');
    }

    /**
     * PHP 8.5+ Closures and first-class callables in constant expressions (#26240).
     *
     * RFCs: closures_in_const_expr / fcc_in_const_expr — Zend/zend_compile.c.
     * Closures must be {@code static} and must not {@code use} outer variables; FCC is
     * {@code func(...)} / {@code Class::method(...)} only. Withheld on PROFILE≤8.4.
     */
    public static function supportsClosuresInConstantExpressions(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.5.0', '>=');
    }

    /**
     * PHP 8.5+ clone-with syntax (`clone $obj with { }`, `clone($obj, [...])`).
     *
     * Zend landed clone-with in 8.5 (RFC); PROFILE=8.4 must reject like Zend 8.4 (#23877, re-#16676/#12987).
     * Named `clone($obj, with: [...])` is rejected on PROFILE≥8.5 like Zend 8.5.8 (#28182).
     * Forward profile via {@see languageProfileVersion()} enables clone-with on 8.5+.
     * php-src: Zend/zend_language_parser.y clone_expr with clause; zend_vm_def.h ZEND_CLONE.
     */
    public static function supportsCloneWithSyntax(): bool
    {
        if (version_compare(self::VERSION, '8.5.0', '>=')) {
            return true;
        }

        $raw = getenv('PHP_COMPILER_PROFILE');
        if (!\is_string($raw) || '' === trim($raw)) {
            return false;
        }

        return version_compare(self::languageProfileVersion(), '8.5.0', '>=');
    }

    /**
     * PHP 8.4+ list destructuring spread assignment (`[$a, ...$rest] = $arr`, keyed `['k' => $v, ...$tail] = $arr`).
     *
     * Gated on stable 8.4.0 so 8.4.0-dev reference profile rejects like Zend 8.2 parse fatal (#17182, #9248).
     * Forward profile via {@see languageProfileVersion()} enables spread on 8.4.0-dev.
     * php-src: Zend/zend_compile.c list spread assign.
     */
    public static function supportsListDestructuringSpreadAssign(): bool
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
     * PHP 8.4+ CURLOPT/CURLINFO symbols (php-src ext/curl/curl.stub.php; #21336, #22837).
     *
     * {@code CURLOPT_TCP_KEEPCNT}, {@code CURLOPT_PREREQFUNCTION},
     * {@code CURLOPT_SERVER_RESPONSE_TIMEOUT}, {@code CURLOPT_DEBUGFUNCTION},
     * {@code CURLINFO_POSTTRANSFER_TIME_T}, {@code CURL_HTTP_VERSION_3}/{@code 3ONLY}
     * are absent on Zend 8.2/8.3. Withhold on 8.4.0-dev reference / PROFILE=8.2;
     * enable via stable 8.4.0+ or explicit {@code PHP_COMPILER_PROFILE=8.4}.
     */
    public static function advertisesPhp84CurlOptionConstants(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * PHP 8.4+ curl_version()['feature_list'] (php-src ext/curl/interface.c; #25357, #21337).
     *
     * Absent on Zend 8.2/8.3. Withhold on 8.4.0-dev reference / PROFILE≤8.3;
     * enable via stable 8.4.0+ or explicit {@code PHP_COMPILER_PROFILE=8.4}.
     */
    public static function advertisesCurlVersionFeatureList(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * PHP 8.5+ curl_multi_get_handles() (php-src ext/curl/multi.c; #20520).
     *
     * Withheld on 8.4.0-dev / PROFILE=8.4 so function_exists matches Zend 8.4. Enable via
     * stable 8.5.0+ or explicit `PHP_COMPILER_PROFILE=8.5` forward profile.
     */
    public static function advertisesCurlMultiGetHandles(): bool
    {
        if (version_compare(self::VERSION, '8.5.0', '>=')) {
            return true;
        }

        return version_compare(self::languageProfileVersion(), '8.5.0', '>=');
    }

    /**
     * PHP 8.5+ curl_share_init_persistent() / CurlSharePersistentHandle (php-src ext/curl/share.c; #20530).
     *
     * Withheld on 8.4.0-dev / PROFILE=8.4 so function_exists matches Zend 8.4. Enable via
     * stable 8.5.0+ or explicit `PHP_COMPILER_PROFILE=8.5` forward profile.
     */
    public static function advertisesCurlShareInitPersistent(): bool
    {
        if (version_compare(self::VERSION, '8.5.0', '>=')) {
            return true;
        }

        return version_compare(self::languageProfileVersion(), '8.5.0', '>=');
    }

    /**
     * PHP 8.5+ compile-time rejection of {@code #[\AllowDynamicProperties]} on enums (php-src GH-15731, #17402).
     *
     * Withheld on reference profile (matches Zend 8.2 acceptance). Enable via stable 8.5.0+ or explicit
     * {@code PHP_COMPILER_PROFILE=8.5} forward profile.
     * php-src: {@code Zend/zend_attributes.c} {@code validate_allow_dynamic_properties}.
     */
    public static function rejectsAllowDynamicPropertiesOnEnum(): bool
    {
        if (version_compare(self::VERSION, '8.5.0', '>=')) {
            return true;
        }

        return version_compare(self::languageProfileVersion(), '8.5.0', '>=');
    }

    /**
     * PHP 8.5+ compile-time rejection of {@code #[\Attribute]} on abstract class / interface / trait / enum (#26241).
     *
     * Withheld on PROFILE≤8.4 (Zend only fails at ReflectionAttribute::newInstance of the attribute class).
     * Enable via stable 8.5.0+ or explicit {@code PHP_COMPILER_PROFILE=8.5}.
     * php-src: Zend/zend_attributes.c {@code validate_attribute}; defer with {@code #[\DelayedTargetValidation]}.
     */
    public static function rejectsAttributeOnNonConcreteClassLike(): bool
    {
        if (version_compare(self::VERSION, '8.5.0', '>=')) {
            return true;
        }

        return version_compare(self::languageProfileVersion(), '8.5.0', '>=');
    }

    /**
     * PHP 8.5+ {@code Attribute::TARGET_CONSTANT} and 8.5 Attribute flag bit layout (#20727).
     *
     * On ≤8.4: no TARGET_CONSTANT; TARGET_ALL=63; IS_REPEATABLE=(1<<6)=64.
     * On 8.5+: TARGET_CONSTANT=(1<<6); TARGET_ALL=127; IS_REPEATABLE=(1<<7)=128.
     * php-src: Zend/zend_attributes.h ZEND_ATTRIBUTE_TARGET_CONST / TARGET_ALL / IS_REPEATABLE.
     */
    public static function supportsAttributeTargetConstant(): bool
    {
        if (version_compare(self::VERSION, '8.5.0', '>=')) {
            return true;
        }

        return version_compare(self::languageProfileVersion(), '8.5.0', '>=');
    }

    /**
     * PHP 8.5+ Locale APIs added after the 8.4 baseline (#20927, #20928).
     *
     * Withheld on 8.4.0-dev / PROFILE=8.4 so method_exists matches Zend ≤8.4. Enable via
     * stable 8.5.0+ or explicit `PHP_COMPILER_PROFILE=8.5` forward profile.
     */
    public static function advertisesLocalePhp85Apis(): bool
    {
        if (version_compare(self::VERSION, '8.5.0', '>=')) {
            return true;
        }

        return version_compare(self::languageProfileVersion(), '8.5.0', '>=');
    }

    /**
     * PHP 8.5+ Locale::isRightToLeft / addLikelySubtags / minimizeSubtags (#20927).
     *
     * php-src: ext/intl/locale/locale.stub.php (GH-18345 / GH-18344).
     */
    public static function advertisesLocaleRtlAndLikelySubtags(): bool
    {
        return self::advertisesLocalePhp85Apis();
    }

    /**
     * PHP 8.5+ Locale::getDisplayKeyword / getDisplayKeywordValue (#20928, php-src #22264).
     *
     * php-src: ext/intl/locale/locale.stub.php.
     */
    public static function advertisesLocaleDisplayKeyword(): bool
    {
        return self::advertisesLocalePhp85Apis();
    }

    /**
     * PHP 8.5+ IntlListFormatter — ICU list formatting (php-src ext/intl/listformatter; #23229).
     *
     * php-src: listformatter.stub.php / listformatter_class.cpp (GH-18519).
     * Enable via stable 8.5.0+ or explicit {@code PHP_COMPILER_PROFILE=8.5} forward profile.
     */
    public static function advertisesIntlListFormatter(): bool
    {
        return self::advertisesLocalePhp85Apis();
    }

    /**
     * `new ClassName(...)` first-class callable — never supported by php-src.
     *
     * Always false: Zend rejects ZEND_AST_NEW + ZEND_AST_CALLABLE_CONVERT on every version with
     * "Cannot create Closure for new expression" (Zend/zend_compile.c). #23714 wrongly enabled a
     * PROFILE=8.4 accept path; #26188 restores php-src-strict rejection on all profiles.
     */
    public static function supportsNewFirstClassCallable(): bool
    {
        return false;
    }

    /**
     * PHP 8.4+ asymmetric property visibility (private(set), protected(set), …).
     *
     * Gated on {@see languageProfileVersion()} so 8.4.0-dev reference profile rejects like Zend 8.2
     * ("Multiple access type modifiers are not allowed") — #24819, re-#12508 / #17197.
     * `version_compare` treats `8.4.0-dev` as below `8.4.0`, so unset `PHP_COMPILER_PROFILE`
     * keeps this false (do not use {@see isForwardProfileAtLeast()} here — that reopened the
     * reference-profile accept regression via #24720/#24722).
     * Forward profile: `PHP_COMPILER_PROFILE=8.4` or stable 8.4.0+.
     * php-src: Zend/zend_language_parser.y T_PRIVATE_SET; Zend/zend_compile.c ZEND_ACC_*_SET.
     */
    public static function supportsAsymmetricVisibility(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * PHP 8.5+ asymmetric visibility on static properties (`public private(set) static $x`).
     *
     * PHP 8.4 allows aviz on instance properties only; static + explicit read/set is a
     * "Multiple access type modifiers are not allowed" compile fatal (#7013). PHP 8.5
     * extends aviz to static properties (RFC static-aviz / UPGRADING).
     * Gated on {@see languageProfileVersion()} so unset / ≤8.4 profiles keep the reject.
     * php-src: Zend/zend_language_parser.y; Zend/zend_compile.c zend_add_member_modifier().
     */
    public static function supportsStaticAsymmetricVisibility(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.5.0', '>=');
    }

    /**
     * PHP 8.4+ property hooks (`$prop { get; set; }`, default initializer + hook block).
     *
     * Gated on {@see languageProfileVersion()} so 8.4.0-dev reference profile rejects like Zend 8.2
     * (#24818, re-#22781 / #22371 / #18531). `version_compare` treats `8.4.0-dev` as below `8.4.0`,
     * so unset `PHP_COMPILER_PROFILE` keeps this false. Forward profile via `PHP_COMPILER_PROFILE=8.4`
     * (or stable 8.4.0+) enables hook syntax.
     * Do not use VERSION_ID / isForwardProfileAtLeast here — that re-enabled acceptance on default
     * after #24754/#24760 and broke Zend 8.2 parity (#24818).
     * php-src: Zend/zend_language_parser.y / Zend/zend_compile.c property hooks.
     */
    public static function supportsPropertyHooks(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * PHP 8.4+ final properties — hooked and plain (Zend/zend_compile.c, #16799, #22241, #22308).
     *
     * Gated on {@see languageProfileVersion()} so 8.4.0-dev reference profile rejects like Zend 8.2
     * ("Cannot declare property … final, the final modifier is allowed only for methods…").
     * `version_compare` treats `8.4.0-dev` as below `8.4.0`, so unset `PHP_COMPILER_PROFILE`
     * keeps this false (#25379, re-#24895/#24316/#24216). Forward profile via
     * `PHP_COMPILER_PROFILE=8.4` enables parse/compile of `final public $x`.
     * php-src: Zend/zend_inheritance.c — Cannot override final property.
     *
     * Compliance reject cases must SKIPIF on PROFILE env, not this method — otherwise a
     * wrongly-true gate would skip the guard and reopen the regression silently.
     */
    public static function supportsFinalProperties(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * PHP 8.5+ `final` on constructor-promoted properties (RFC final_promotion, #27123).
     *
     * Plain `final` properties are 8.4+ ({@see supportsFinalProperties()}); promotion of
     * `final` on a ctor parameter landed in 8.5. Zend ≤8.4 compiles
     * `function __construct(public final string $x)` as
     * {@code Cannot use the final modifier on a parameter}.
     *
     * Enable via stable 8.5.0+ or explicit {@code PHP_COMPILER_PROFILE=8.5}.
     * php-src: Zend/zend_language_parser.y property_modifier; Zend/zend_compile.c.
     */
    public static function supportsFinalPromotedProperties(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.5.0', '>=');
    }

    /**
     * PHP 8.4+ `static class` declarations (Zend/zend_language_parser.y, #24894, re-#6929).
     *
     * Gated on {@see languageProfileVersion()} so 8.4.0-dev reference profile and
     * `PHP_COMPILER_PROFILE=8.2` parse-reject like Zend 8.2 (`unexpected token "class"`).
     * `version_compare` treats `8.4.0-dev` as below `8.4.0`, so unset profile keeps this false.
     * Forward profile via `PHP_COMPILER_PROFILE=8.4` enables strip/annotate via
     * {@see Ast\StaticClassPreprocessor}.
     *
     * Compliance reject cases must SKIPIF on PROFILE env, not this method — otherwise a
     * wrongly-true gate would skip the guard and reopen the regression silently.
     */
    public static function supportsStaticClass(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * PHP 8.4+ `lazy` property modifier — deferred default initializer (#16813).
     *
     * Gated on {@see languageProfileVersion()} so 8.4.0-dev reference profile rejects like Zend 8.2.
     * php-src: Zend/zend_compile.c ZEND_ACC_LAZY; zend_lazy_objects.c.
     */
    public static function supportsLazyPropertyModifier(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * PHP 8.4+ `readonly function` / `readonly fn` declarations (#17657).
     *
     * Gated on {@see languageProfileVersion()} so 8.4.0-dev reference profile rejects like Zend 8.2.
     * php-src: Zend/zend_compile.c ZEND_ACC_READONLY_FUNCTION; zend_ast.c ZEND_AST_FUNC_DECL.
     */
    public static function supportsReadonlyFunction(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * Whether instance property defaults may use `new Class(...)`.
     *
     * Always false: Zend rejects `new` in property default expressions (all profiles) —
     * same message as class constants (#21493, Zend/zend_compile.c zend_compile_property).
     * Prior 8.4 forward-profile enable (#18040) was incorrect vs php-src-strict.
     */
    public static function supportsPropertyDefaultObjectExpressions(): bool
    {
        return false;
    }

    /**
     * Whether static property defaults may use `new Class` / `new Class(...)`.
     *
     * Always false: Zend rejects `new` in static property defaults (#21493, Zend/zend_compile.c).
     */
    public static function supportsStaticPropertyDefaultObjectExpressions(): bool
    {
        return false;
    }

    /**
     * Whether bare `new Class` (without `()`) is allowed in class constants / static property defaults.
     *
     * Always false: those contexts reject all `new` on Zend (#21493). Parameter defaults and
     * static variables still allow `new` via separate compile paths.
     */
    public static function supportsNewWithoutParensInConstAndStaticInitializers(): bool
    {
        return false;
    }

    /**
     * PHP 8.4+ dereferencable `new` without outer parentheses (`new Class()->m()`, RFC new_without_parentheses).
     *
     * Gated on {@see languageProfileVersion()} so 8.4.0-dev reference profile rejects like Zend 8.2
     * (#24883, re-#22783 / #19684; undoes #24755 default enable). `version_compare` treats
     * `8.4.0-dev` as below `8.4.0`, so unset `PHP_COMPILER_PROFILE` keeps this false. Forward
     * profile via `PHP_COMPILER_PROFILE=8.4` (or stable 8.4.0+) enables the form.
     * Do not use isForwardProfileAtLeast here — that re-enabled acceptance on default after #24755
     * and broke Zend 8.2 parse parity (#24883).
     * php-src: Zend/zend_language_parser.y — new_dereferenceable / new_non_dereferenceable.
     */
    public static function supportsDereferencableNewWithoutOuterParens(): bool
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
     * PHP 8.2+ deprecates `"${var}"` dollar-brace string interpolation (prefer `"{$var}"`).
     *
     * php-src: Zend/zend_compile.c / T_DOLLAR_OPEN_CURLY_BRACES (#22001).
     */
    public static function supportsDollarBraceStringDeprecation(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.2.0', '>=');
    }

    /**
     * PHP 8.2+ deprecates `"self::method"` / `"static::method"` / `"parent::method"` (and
     * `["self"|"static"|"parent", "method"]`) callables in is_callable / call_user_func*.
     *
     * php-src: Zend/zend_execute_API.c zend_is_callable_ex (#27915).
     */
    public static function supportsScopeKeywordCallableDeprecation(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.2.0', '>=');
    }

    /**
     * PHP 8.5+ deprecates `case <expr>;` / `default;` in switch (prefer `:`).
     *
     * php-src: Zend/zend_compile.c ZEND_ALT_CASE_SYNTAX (#26279).
     * Enable via stable 8.5.0+ or explicit `PHP_COMPILER_PROFILE=8.5`.
     */
    public static function supportsSwitchCaseSemicolonDeprecation(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.5.0', '>=');
    }

    /**
     * PHP 8.5+ deprecates the backtick operator as an alias for shell_exec().
     *
     * php-src: Zend/zend_compile.c zend_compile_shell_exec (#26280).
     * Enable via stable 8.5.0+ or explicit `PHP_COMPILER_PROFILE=8.5`.
     */
    public static function supportsBacktickShellExecDeprecation(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.5.0', '>=');
    }

    /**
     * PHP 8.5+ curl_close()/curl_share_close() #[\Deprecated] E_DEPRECATED on call (#28133).
     *
     * php-src: ext/curl/curl.stub.php — since 8.5, "as it has no effect since PHP 8.0".
     * Enable via stable 8.5.0+ or explicit `PHP_COMPILER_PROFILE=8.5`.
     */
    public static function supportsCurlCloseDeprecation(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.5.0', '>=');
    }

    /**
     * PHP 8.5+ deprecates non-canonical cast spellings (integer)/(boolean)/(double)/(binary).
     *
     * php-src: Zend/zend_language_scanner.l (#26281).
     * Enable via stable 8.5.0+ or explicit `PHP_COMPILER_PROFILE=8.5`.
     */
    public static function supportsNonCanonicalCastDeprecation(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.5.0', '>=');
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
     * PHP 8.4+ ReflectionGenerator::isClosed() (ext/reflection/php_reflection.c, #22242).
     *
     * Gated on stable 8.4.0 / {@see languageProfileVersion()} so 8.4.0-dev reference profile matches Zend 8.2
     * (method absent). Enable forward profile on dev via `PHP_COMPILER_PROFILE=8.4`.
     */
    public static function supportsReflectionGeneratorIsClosed(): bool
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
     * PHP 8.4+ ReflectionProperty hook/lazy introspection
     * ({hasHook,hasHooks,getHook,getHooks,isLazy,skipLazyInitialization,isFinal,isAbstract,isVirtual},
     * ext/reflection/php_reflection.c, #17493, #20511, #22309).
     *
     * Gated on stable 8.4.0 / {@see languageProfileVersion()} so 8.4.0-dev reference profile matches
     * Zend 8.2 (methods absent). Aligns with {@see supportsPropertyHooks()} (#24818, #24672, #6983).
     * Enable forward profile on dev via `PHP_COMPILER_PROFILE=8.4`.
     */
    public static function supportsReflectionPropertyHookProbes(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * PHP 8.4+ ReflectionProperty::{getReadableType,getSettableType}
     * (ext/reflection/php_reflection.c, #7053, #9873, #22309).
     *
     * Gated on stable 8.4.0 / {@see languageProfileVersion()} so 8.4.0-dev reference profile matches Zend 8.2
     * (methods absent). Enable forward profile on dev via `PHP_COMPILER_PROFILE=8.4`.
     */
    public static function supportsReflectionPropertyReadableSettableType(): bool
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
     * PHP 8.4+ ReflectionEnumUnitCase::isBacked() / ReflectionEnumBackedCase::isBacked()
     * (ext/reflection/php_reflection.c, #5675, #18648).
     *
     * Gated on stable 8.4.0 / {@see languageProfileVersion()} so 8.4.0-dev reference profile matches Zend 8.2
     * (method absent). Enable forward profile on dev via `PHP_COMPILER_PROFILE=8.4`.
     */
    public static function supportsReflectionEnumCaseIsBacked(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * PHP 8.3+ ReflectionEnum::fromName() (ext/reflection/php_reflection.c, #16877, #17103).
     *
     * Withheld on 8.4.0-dev reference profile (matches Zend 8.2 — method absent). Enable via stable 8.4.0+
     * or explicit `PHP_COMPILER_PROFILE=8.3` / `8.4` forward profile.
     */
    public static function supportsReflectionEnumFromName(): bool
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
     * PHP 8.4+ ReflectionClassConstant::isDeprecated() (ext/reflection/php_reflection.c, #16820, #17104).
     *
     * Gated on stable 8.4.0 / {@see languageProfileVersion()} so 8.4.0-dev reference profile matches Zend 8.2
     * (method absent). Enable forward profile on dev via `PHP_COMPILER_PROFILE=8.4`.
     */
    public static function supportsReflectionClassConstantIsDeprecated(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * PHP 8.4+ ReflectionFunction::isDeprecated() reports #[\Deprecated] metadata (ext/reflection/php_reflection.c, #9760).
     *
     * On 8.2 reference profile the method exists but always returns false (php-src #80400 guard). Enable
     * forward semantics via `PHP_COMPILER_PROFILE=8.4` or stable 8.4.0+.
     */
    public static function supportsReflectionFunctionIsDeprecated(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * php-src credits_modules forward rows (DOM Nora Dossche, ext/uri row; ext/standard/credits.c, #16740).
     *
     * Withheld on 8.4.0-dev reference profile so CREDITS_ALL matches Zend 8.2. Enable via
     * `PHP_COMPILER_PROFILE=8.4` or stable 8.4.0+.
     */
    public static function supportsForwardProfileCreditsModuleAuthors(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * PHP 8.4+ ReflectionFunction::createFromCallable()/createFromFunction() and
     * ReflectionMethod::createFromClosure()/createFromMethodName() (ext/reflection/php_reflection.c; #6994, #7039, #16724).
     *
     * Gated on stable 8.4.0 / {@see languageProfileVersion()} so 8.4.0-dev reference profile matches Zend 8.2
     * (methods absent). Enable forward profile on dev via `PHP_COMPILER_PROFILE=8.4`.
     */
    public static function supportsReflectionCreateFromFactories(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * PHP 8.4+ ReflectionFunctionAbstract::getNamedArguments() (ext/reflection/php_reflection.c, #17658).
     *
     * Gated on stable 8.4.0 / {@see languageProfileVersion()} so 8.4.0-dev reference profile matches Zend 8.2
     * (method absent). Enable forward profile on dev via `PHP_COMPILER_PROFILE=8.4`.
     */
    public static function supportsReflectionFunctionGetNamedArguments(): bool
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
     * ReflectionParameter::isSensitive() / isSensitiveParameter() — phantom vs php-src (#28528, re-#7072/#16130/#22899).
     *
     * php-src never ships these methods (ext/reflection/php_reflection.stub.php on 8.2–8.5).
     * #[\SensitiveParameter] exception-trace redaction is separate ({@see \PHPCompiler\VM\SensitiveParamSupport})
     * and stays enabled. Never advertise on any php-src-strict profile.
     */
    public static function supportsReflectionParameterIsSensitiveParameter(): bool
    {
        return false;
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
     * attribute_exists() / class_meth_exists() / unitenum_exists() — absent from php-src
     * (ext/standard/basic_functions.stub.php; #14995, #17138, #22584).
     *
     * Never register or advertise on php-src-strict profiles (including PROFILE=8.4/8.5).
     * Prior mistaken 8.4 forward-profile enable (#17138) is retired.
     */
    public static function supportsPhp84ReflectionProbeBuiltins(): bool
    {
        return false;
    }

    /** attribute_exists()/class_meth_exists()/unitenum_exists() visible to function_exists() — never (php-src absent, #22584). */
    public static function advertisesPhp84ReflectionProbeBuiltins(): bool
    {
        return false;
    }

    /**
     * isAnonymousClass() global probe (#19969) — kept on forward 8.4+ until a dedicated phantom issue.
     *
     * Not part of php-src basic_functions / reflection stubs as a free function (ReflectionClass::isAnonymous
     * is the Zend API); gated separately from the #22584 trio so PROFILE=8.4 still exercises the helper.
     */
    public static function supportsIsAnonymousClass(): bool
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

    /** isAnonymousClass() visible to function_exists() — stable runtime or forward 8.4+ (#19969). */
    public static function advertisesIsAnonymousClass(): bool
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
     * PHP 8.4+ zend_thread_id() (ext/standard/basic_functions.c, issue #6870, #11842, #12386, #15692).
     *
     * Gated on stable 8.4.0 / {@see languageProfileVersion()} so 8.4.0-dev reference profile matches Zend 8.2 phantom gate.
     */
    public static function supportsZendThreadId(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * zend_thread_id() visible to function_exists() — stable runtime or forward 8.4+ profile (#16357, #16851).
     */
    public static function advertisesZendThreadId(): bool
    {
        if (version_compare(self::VERSION, '8.4.0', '>=')) {
            return true;
        }

        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
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
     * PHP 8.4+ Range value object — Range::from() inclusive intervals (ext/standard/range.c, #17427).
     */
    public static function supportsRange(): bool
    {
        return self::supportsBuiltinStubEnums();
    }

    /**
     * PHP 8.4+ builtin stub enums (StringTrimMode, PadType, MemoryUsage, …).
     *
     * Gated on stable 8.4.0 / {@see languageProfileVersion()} so 8.4.0-dev reference profile matches Zend 8.2 phantom gate (#13630, #15692).
     * ExitStatus is not among these — php-src never ships it (#28500, re-#28200 / #7294).
     * php-src: Zend/zend_enum.def; ext/standard/basic_functions.stub.php
     */
    public static function supportsBuiltinStubEnums(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * FTP\Connection opaque object + procedural ftp_* (ext/ftp/ftp.stub.php; #7270, #3353, #20083).
     *
     * Present since PHP 8.1 resource→object (Zend 8.2 reference has both class_exists and ftp_connect).
     * Do not tie to {@see supportsBuiltinStubEnums()} — that hid the whole module on 8.4.0-dev (#20083).
     */
    public static function supportsFtpConnection(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.1.0', '>=');
    }

    /**
     * stream_supports() / STREAM_SUPPORT_* — absent from php-src (only stream_supports_lock();
     * ext/standard/streams.c / basic_functions.stub.php).
     *
     * Never register or advertise on php-src-strict profiles (including PROFILE=8.3/8.4/8.5).
     * Forward-profile enable (#16741/#17007) retired by #28367 (re-#12422/#17697).
     */
    public static function supportsStreamSupports(): bool
    {
        return false;
    }

    /** stream_supports() visible to function_exists() — never (php-src absent, #28367). */
    public static function advertisesStreamSupports(): bool
    {
        return false;
    }

    /**
     * PHP 8.6+ stream error store API — stream_last_errors()/stream_clear_errors() + StreamError* types (#21020).
     *
     * Withheld on ≤8.5 profiles (matches Zend phantom gate). Enable via stable 8.6.0+ or
     * explicit `PHP_COMPILER_PROFILE=8.6` forward profile.
     * php-src: ext/standard/streamsfuncs.c — PHP_FUNCTION(stream_last_errors / stream_clear_errors)
     * php-src: main/streams/stream_errors.stub.php
     */
    public static function supportsStreamErrorApi(): bool
    {
        if (version_compare(self::VERSION, '8.6.0', '>=')) {
            return true;
        }

        return version_compare(self::languageProfileVersion(), '8.6.0', '>=');
    }

    /** stream_last_errors()/stream_clear_errors() visible to function_exists() — profile ≥ 8.6 (#21020). */
    public static function advertisesStreamErrorApi(): bool
    {
        return self::supportsStreamErrorApi();
    }

    /**
     * PHP 8.6+ stream_socket_get_crypto_status() (ext/standard/streamsfuncs.c; #21021).
     *
     * Withheld on ≤8.5 profiles (matches Zend phantom gate). Enable via stable 8.6.0+ or
     * explicit `PHP_COMPILER_PROFILE=8.6` forward profile.
     * php-src: PHP_FUNCTION(stream_socket_get_crypto_status) → php_stream_xport_crypto_get_status
     */
    public static function supportsStreamSocketGetCryptoStatus(): bool
    {
        if (version_compare(self::VERSION, '8.6.0', '>=')) {
            return true;
        }

        return version_compare(self::languageProfileVersion(), '8.6.0', '>=');
    }

    /** stream_socket_get_crypto_status() visible to function_exists() — profile ≥ 8.6 (#21021). */
    public static function advertisesStreamSocketGetCryptoStatus(): bool
    {
        return self::supportsStreamSocketGetCryptoStatus();
    }

    /**
     * STREAM_SUPPORT_READ/WRITE — absent from php-src (tied to phantom stream_supports(); #16846/#28367).
     *
     * Never advertise on php-src-strict profiles. Keep {@see stream_supports_lock()} only.
     */
    public static function supportsStreamSupportReadWriteConstants(): bool
    {
        return false;
    }

    /**
     * PHP 8.3+ json_validate() (ext/json/php_json.c, issue #3101, #11826, #12363, #13365, #14518, #14708, #14972, #15026, #15196, #15241, #16091, #22544, #24808).
     *
     * Withheld on 8.4.0-dev reference profile (matches Zend 8.2 function_exists gate when reported
     * PHP_VERSION is the 8.2 reference string). Enable via stable 8.4.0+ or explicit
     * `PHP_COMPILER_PROFILE=8.3` / `8.4` forward profile (#16091, #17007, #22544).
     *
     * Do not use {@see isForwardProfileAtLeast()} here — that would re-advertise on unset PROFILE
     * while {@see phpversion()} still reports {@see REFERENCE_PHP_VERSION} (#24745 regression / #24808).
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
     * json_validate() visible to function_exists() — same gate as registration (#17007, #22544, #24808).
     *
     * Withheld on 8.4.0-dev reference harness (no {@code PHP_COMPILER_PROFILE}) like Zend 8.2.
     */
    public static function advertisesJsonValidate(): bool
    {
        return self::supportsJsonValidate();
    }

    /**
     * PHP 8.3+ socket_atmark() (ext/sockets/sockets.c, issue #6544, #9215, #25874).
     *
     * Withheld on 8.4.0-dev reference profile (matches Zend 8.2 function_exists gate when reported
     * PHP_VERSION is the 8.2 reference string). Enable via stable 8.4.0+ or explicit
     * `PHP_COMPILER_PROFILE=8.3` / `8.4` forward profile.
     *
     * Same shape as {@see supportsJsonValidate()} — do not use {@see isForwardProfileAtLeast()}
     * or {@see advertisesBuiltinSince()} alone (both re-advertise on unset PROFILE while
     * {@see phpversion()} still reports {@see REFERENCE_PHP_VERSION}).
     */
    public static function supportsSocketAtmark(): bool
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
     * socket_atmark() visible to function_exists() — same gate as registration (#25874).
     *
     * Withheld on 8.4.0-dev reference harness (no {@code PHP_COMPILER_PROFILE}) like Zend 8.2.
     */
    public static function advertisesSocketAtmark(): bool
    {
        return self::supportsSocketAtmark();
    }

    /**
     * PHP 8.5+ sockets SHUT_RD / SHUT_WR / SHUT_RDWR (ext/sockets/sockets.stub.php; #26760).
     *
     * Absent from Zend 8.2–8.4 sockets category; registered under `#ifdef SHUT_RDWR` from 8.5.
     * Withheld on ≤8.4 (reference + PROFILE≤8.4). Enable via stable 8.5.0+ or
     * `PHP_COMPILER_PROFILE=8.5`.
     */
    public static function supportsSocketShutConstants(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.5.0', '>=');
    }

    /**
     * Formerly gated a mistaken ValueError for json_encode(unit enum) on forward profiles (#5683).
     *
     * php-src (8.2–8.4) always returns false + JSON_ERROR_NON_BACKED_ENUM; never ValueError
     * (#22681/#22688). Kept as a always-false probe so advertisement/gate tests stay stable.
     */
    public static function jsonEncodeUnitEnumValueError(): bool
    {
        return false;
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
     * readonly() visible to function_exists() — stable runtime or forward 8.4+ profile (#16357, #17693).
     *
     * Withheld on 8.4.0-dev reference profile (matches Zend 8.2 phantom gate). Callable and advertised when
     * {@see supportsReadonlyBuiltin()} is true (stable 8.4.0+ or explicit {@code PHP_COMPILER_PROFILE=8.4}).
     */
    public static function advertisesReadonlyBuiltin(): bool
    {
        if (version_compare(self::VERSION, '8.4.0', '>=')) {
            return true;
        }

        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * PHP 8.5+ clock_gettime() / ClockInterface (ext/standard/hrtime.c, #11624, #12470, #24201).
     *
     * Gated on profile ≥8.5.0 — php-src registers these in 8.5, not 8.2/8.4.
     */
    public static function supportsClockGettime(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.5.0', '>=');
    }

    /**
     * PHP 8.4+ ReflectionClass lazy factories (newLazyGhost/newLazyProxy + reset/initialize helpers)
     * (#6708, #12375, #16812, #28414, #28516).
     *
     * Gated on stable 8.4.0 / {@see languageProfileVersion()} so 8.4.0-dev reference profile matches Zend 8.2 phantom gate.
     * Free-function createLazyGhost()/createLazyProxy() are never advertised — see {@see supportsLazyObjectFreeFunctions()}.
     * Free-function class_has_lazy_object_* are never advertised — see {@see supportsClassHasLazyObjectFreeFunctions()}.
     * ReflectionClass::{createLazyGhost,createLazyProxy,resetAsLazyObject,getLazyInitializationException,
     * getLazyProxyFactory} are also phantoms vs php-src stub — never register them (#28516).
     */
    public static function supportsLazyObjectFactories(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * Free-function createLazyGhost()/createLazyProxy() — phantom vs php-src (#28414, re-#6708/#12375).
     *
     * php-src exposes lazy factories only as ReflectionClass instance methods (newLazyGhost /
     * newLazyProxy). Historical procedural registration must stay off on every profile.
     */
    public static function supportsLazyObjectFreeFunctions(): bool
    {
        return false;
    }

    /**
     * Free-function class_has_lazy_object_initializer()/class_has_lazy_object_uninitializer() —
     * phantom vs php-src (#28517, re-#6052/#6097).
     *
     * php-src introspects lazy state only via ReflectionClass::isUninitializedLazyObject() /
     * getLazyInitializer() (Zend/zend_lazy_objects.c). Never advertise on any profile.
     */
    public static function supportsClassHasLazyObjectFreeFunctions(): bool
    {
        return false;
    }

    /**
     * PHP 8.4+ ReflectionClass::{getDeprecatedMessage,getDeprecatedVersion} and
     * ReflectionMethod::{getDeprecatedMessage,getDeprecatedVersion}
     * (#22599, #25058; re-#6917).
     *
     * Gated on stable 8.4.0 / {@see languageProfileVersion()} so 8.4.0-dev / PROFILE=8.2 matches Zend 8.2
     * (methods absent). Enable forward profile on dev via `PHP_COMPILER_PROFILE=8.4`.
     *
     * Note: ReflectionClass::isStatic() is withheld on every profile — the static-class RFC never
     * landed in php-src; isStatic exists only on ReflectionFunctionAbstract / ReflectionProperty
     * (#28518). Do not re-advertise it under this gate.
     *
     * Note: getLazyPropertyNames / getReadOnlyProperties are not in php-src stubs — withheld on
     * every profile (#28516). Use getProperties() + ReflectionProperty::isReadOnly() instead.
     */
    public static function supportsReflectionClassPhp84Apis(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * PHP 8.4+ ReflectionProperty::{getRawValue,setRawValue} (ext/reflection/php_reflection.stub.php, #22601; re-#6451).
     *
     * Gated on stable 8.4.0 / {@see languageProfileVersion()} so 8.4.0-dev / PROFILE=8.2 matches Zend 8.2
     * (methods absent). Enable forward profile on dev via `PHP_COMPILER_PROFILE=8.4`.
     *
     * Note: isDefaultValueAvailable stays unregistered on ReflectionProperty (parameter API only;
     * property side uses hasDefaultValue). getMangledName arrives in 8.5 — see
     * {@see supportsReflectionPropertyGetMangledName()}.
     */
    public static function supportsReflectionPropertyPhp84RawValueApis(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * PHP 8.5+ ReflectionProperty::getMangledName(): string (ext/reflection/php_reflection.stub.php, #27592).
     *
     * Withheld on ≤8.4 (reference + PROFILE=8.4) so method_exists matches Zend. Enable via stable
     * 8.5.0+ or explicit `PHP_COMPILER_PROFILE=8.5` (#27592; php-src 8.5 NEWS).
     */
    public static function supportsReflectionPropertyGetMangledName(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.5.0', '>=');
    }

    /**
 * PHP 8.4+ gc_status() schema (php-src 8.3+ 12-key table; Zend/zend_builtin_functions.c, #12780, #20627).
 *
 * Forward profile returns running/protected/full/buffer_size **and** legacy counters plus timing floats.
 * Gated on stable 8.4.0 / {@see languageProfileVersion()} so 8.4.0-dev reference profile keeps the
 * four-key pre-8.3 table (#12993, #13293, #14612, #15784); enable via `PHP_COMPILER_PROFILE=8.4`.
 */
    public static function supportsGcStatusPhp84Schema(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * hrtime(true) scalar type follows platform width (ext/standard/hrtime.c, #12779, #17561).
     *
     * php-src: RETURN_LONG when ZEND_ENABLE_ZVAL_LONG64 (64-bit), else RETURN_DOUBLE via zend_strtod.
     * Profile gates do not change this — PHP 8.4 on 64-bit still returns integer nanoseconds.
     */
    public static function supportsHrtimeAsNumberFloat(): bool
    {
        return \PHP_INT_SIZE < 8;
    }

    /**
     * class_constants() — phantom; php-src has no such function (#24200).
     *
     * Always false on php-src-strict. Use ReflectionClass::getConstants() instead.
     */
    public static function supportsClassConstants(): bool
    {
        return false;
    }

    /**
     * get_declared_attributes() — never registered in php-src (#24222, re-#6450).
     *
     * php-src: ext/reflection/php_reflection.c has no PHP_FUNCTION(get_declared_attributes);
     * attribute discovery is ReflectionClass::getAttributes() / peers only. Forever off under
     * php-src-strict (including PROFILE=8.4) until php-src adds the builtin.
     */
    public static function advertisesGetDeclaredAttributes(): bool
    {
        return false;
    }

    /**
     * PHP 8.4+ header_list() (ext/standard/head.c, #12546, #17791).
     *
     * Withheld on 8.4.0-dev reference profile (matches Zend 8.2 function_exists gate). Enable via
     * stable 8.4.0+ or explicit `PHP_COMPILER_PROFILE=8.4` forward profile.
     */
    public static function supportsHeaderList(): bool
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
     * PHP 8.4+ get_defined_constants() optional $category named filter (ext/standard/basic_functions.c, #12947).
     *
     * Gated on stable 8.4.0 / {@see languageProfileVersion()} so 8.4.0-dev reference profile rejects unknown named param like Zend 8.2.
     */
    public static function supportsGetDefinedConstantsCategory(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
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
     * PHP 8.4+ http_build_query() encodes BackedEnum as the backing scalar (ext/standard/http.c, #23703).
     *
     * Pre-8.4 Zend walks enum cases as {name[,value]} object props. Gated on
     * {@see languageProfileVersion()} so 8.4.0-dev reference / PROFILE=8.2 keep the name/value form.
     */
    public static function supportsHttpBuildQueryEnumBackingScalar(): bool
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
     * PHP 8.3+ mb_str_pad() (ext/mbstring/mbstring.c, issue #11964, #4006, #21790, #22373).
     *
     * Withheld on 8.4.0-dev reference profile (matches Zend 8.2 function_exists gate — reported
     * PHP_VERSION is {@see REFERENCE_PHP_VERSION}). Enable via stable 8.4.0+ or explicit
     * `PHP_COMPILER_PROFILE=8.3` / `8.4` forward profile.
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
     * mb_str_pad() visible to function_exists() — stable runtime or forward 8.3+ (#16086, #16776, #21790, #22373).
     */
    public static function advertisesMbStrPad(): bool
    {
        if (version_compare(self::VERSION, '8.4.0', '>=')) {
            return true;
        }

        if (!self::supportsMbStrPad()) {
            return false;
        }

        $raw = getenv('PHP_COMPILER_PROFILE');
        if (!\is_string($raw) || '' === trim($raw)) {
            return false;
        }

        return version_compare(self::languageProfileVersion(), '8.3.0', '>=');
    }

    /**
     * PHP 8.4+ grapheme_str_contains() (ext/intl/grapheme/grapheme.c, issue #7128, #16667, #17010).
     *
     * Registered on stable 8.4.0+ when ext/intl is loaded; withheld from function_exists() until
     * {@see IntlExtensionPolicy::advertisesBuiltins()} (#17694).
     * Withheld on 8.4.0-dev reference profile (matches Zend 8.2 phantom gate, #17105).
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

    /** grapheme_str_contains() visible to function_exists() — only with loaded ext/intl (#17694). */
    public static function advertisesGraphemeStrContains(): bool
    {
        return false;
    }

    /**
     * grapheme_levenshtein() — never shipped by Zend/php-src (ext/intl/grapheme/grapheme.stub.php).
     *
     * Prior registration (#6998) was wrong-direction: ICU levenshtein RFC never landed in stubs
     * (php/php-src#10180). Always withheld — #22661.
     */
    public static function supportsGraphemeLevenshtein(): bool
    {
        return false;
    }

    /**
     * grapheme_levenshtein() visible to function_exists() — always false (Zend never ships it; #22661).
     */
    public static function advertisesGraphemeLevenshtein(): bool
    {
        return false;
    }

    /**
     * PHP 8.4+ grapheme_strimwidth() (ext/intl/grapheme/grapheme_string.c, issue #9793, #17010).
     *
     * Registered on stable 8.4.0+ when ext/intl is loaded; withheld from function_exists() until
     * {@see IntlExtensionPolicy::advertisesBuiltins()} (#17694).
     * Withheld on 8.4.0-dev reference profile (matches Zend 8.2 phantom gate, #17105).
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

    /** grapheme_strimwidth() visible to function_exists() — only with loaded ext/intl (#17694). */
    public static function advertisesGraphemeStrimwidth(): bool
    {
        return false;
    }

    /**
     * PHP 8.4+ grapheme_str_split() (ext/intl/grapheme/grapheme_string.c, #6246, #22340).
     *
     * Same forward-profile gate as {@see supportsGraphemeStrimwidth()} — withheld on 8.4.0-dev
     * reference / {@code PHP_COMPILER_PROFILE=8.2} (Zend 8.2 has no grapheme_str_split).
     */
    public static function supportsGraphemeStrSplit(): bool
    {
        return self::supportsGraphemeStrimwidth();
    }

    /** grapheme_str_split() visible to function_exists() — only with loaded ext/intl (#17694, #22340). */
    public static function advertisesGraphemeStrSplit(): bool
    {
        return false;
    }

    /**
     * PHP 8.4 forward-profile core grapheme helpers (ext/intl/grapheme; #16915).
     *
     * grapheme_strlen/substr/strpos/extract — implementation in-tree; registered only with
     * loaded ext/intl ({@see IntlExtensionPolicy::advertisesBuiltins()}, #17694).
     * grapheme_str_split is PHP 8.4-only — see {@see supportsGraphemeStrSplit()} (#22340).
     */
    public static function supportsGraphemeForwardProfileCore(): bool
    {
        return self::supportsGraphemeStrContains();
    }

    /** Core grapheme helpers visible to function_exists() — only with loaded ext/intl (#17694). */
    public static function advertisesGraphemeForwardProfileCore(): bool
    {
        return false;
    }

    /**
     * PHP 8.4+ locale_get_primary_language/region/script (ext/intl/locale; #5125, #17072).
     *
     * BCP-47 parsers registered without full ext/intl when {@code PHP_COMPILER_PROFILE=8.4} — same
     * gate as grapheme forward-profile builtins (#16667).
     */
    public static function supportsLocaleParserForwardProfile(): bool
    {
        return self::supportsGraphemeStrContains();
    }

    /** locale_get_primary_language/region/script visible to function_exists() — 8.4.0-dev line or forward profile (#17072, #17117). */
    public static function advertisesLocaleParserForwardProfile(): bool
    {
        return self::supportsLocaleParserForwardProfile();
    }

    /**
     * PHP 8.3+ posix_sysconf/pathconf/fpathconf/eaccess + POSIX_SC_* / POSIX_PC_*
     * (ext/posix/posix.stub.php, #20509, #22483).
     *
     * Withheld on 8.4.0-dev reference profile and PROFILE=8.2 (matches Zend 8.2
     * function_exists/defined gate). Enable via stable 8.4.0+ or explicit
     * `PHP_COMPILER_PROFILE=8.3` / `8.4` forward profile.
     */
    public static function supportsPosixSysconfApis(): bool
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

    /** posix_sysconf family visible to function_exists()/defined() — stable or forward 8.3+ (#22483). */
    public static function advertisesPosixSysconfApis(): bool
    {
        if (version_compare(self::VERSION, '8.4.0', '>=')) {
            return true;
        }

        return self::supportsPosixSysconfApis();
    }

    /**
     * PHP 8.3+ IntlGregorianCalendar::createFromDate() / createFromDateTime()
     * (ext/intl/calendar/calendar.stub.php; #20906, #26745).
     *
     * Withheld on 8.4.0-dev reference profile and PROFILE=8.2 (matches Zend 8.2
     * method_exists gate). Enable via stable 8.4.0+ or explicit
     * `PHP_COMPILER_PROFILE=8.3` / `8.4` forward profile.
     *
     * php-src has OO methods only — no intlgregcal_create_from_date* procedural aliases.
     */
    public static function supportsIntlGregorianCreateFromDate(): bool
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

    /** IntlGregorianCalendar::createFromDate* — stable or forward 8.3+ (#26745). */
    public static function advertisesIntlGregorianCreateFromDate(): bool
    {
        if (version_compare(self::VERSION, '8.4.0', '>=')) {
            return true;
        }

        return self::supportsIntlGregorianCreateFromDate();
    }

    /**
     * PHP 8.4+ IntlDateFormatter::PATTERN (UDAT_PATTERN = -2; ext/intl/dateformat.stub.php, #22623).
     *
     * Withheld on 8.4.0-dev reference profile and PROFILE=8.2 (matches Zend 8.2 ReflectionClass
     * hasConstant gate). Enable via stable 8.4.0+ or explicit `PHP_COMPILER_PROFILE=8.4` forward profile.
     */
    public static function supportsIntlDateFormatterPatternConst(): bool
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

    /** IntlDateFormatter::PATTERN visible to ReflectionClass/defined() — stable or forward 8.4+ (#22623). */
    public static function advertisesIntlDateFormatterPatternConst(): bool
    {
        if (version_compare(self::VERSION, '8.4.0', '>=')) {
            return true;
        }

        return self::supportsIntlDateFormatterPatternConst();
    }

    /**
     * PHP 8.4+ IntlCalendar::setDate() / setDateTime()
     * (ext/intl/calendar/calendar.stub.php; #20851, #20905, #22597).
     *
     * Withheld on 8.4.0-dev reference profile and PROFILE=8.2 (matches Zend 8.2
     * method_exists gate). Enable via stable 8.4.0+ or explicit `PHP_COMPILER_PROFILE=8.4`.
     */
    public static function supportsIntlCalendarSetDate(): bool
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

    /** IntlCalendar::setDate / setDateTime — stable or forward 8.4+ (#22597). */
    public static function advertisesIntlCalendarSetDate(): bool
    {
        if (version_compare(self::VERSION, '8.4.0', '>=')) {
            return true;
        }

        return self::supportsIntlCalendarSetDate();
    }

    /**
     * PHP 8.4+ IntlDateFormatter::parseToCalendar()
     * (ext/intl/dateformat/dateformat.stub.php; #20729, #22621).
     *
     * Same profile gate as {@see supportsIntlCalendarSetDate()} / PATTERN const.
     */
    public static function supportsIntlDateFormatterParseToCalendar(): bool
    {
        return self::supportsIntlCalendarSetDate();
    }

    /**
     * PHP 8.4+ Spoofchecker::setAllowedChars() + USET pattern-option consts
     * (ext/intl/spoofchecker/spoofchecker.stub.php; #20823, #23157).
     *
     * Withheld on 8.4.0-dev reference profile and PROFILE=8.2 (matches Zend 8.2/8.3
     * method_exists gate). Enable via stable 8.4.0+ or explicit `PHP_COMPILER_PROFILE=8.4`.
     */
    public static function supportsSpoofcheckerSetAllowedChars(): bool
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

    /** Spoofchecker::setAllowedChars / IGNORE_SPACE family — stable or forward 8.4+ (#23157). */
    public static function advertisesSpoofcheckerSetAllowedChars(): bool
    {
        if (version_compare(self::VERSION, '8.4.0', '>=')) {
            return true;
        }

        return self::supportsSpoofcheckerSetAllowedChars();
    }

    /**
     * PHP 8.4+ NumberFormatter ROUND_HALFODD / ROUND_TOWARD_ZERO / ROUND_AWAY_FROM_ZERO
     * (ext/intl/formatter/formatter.stub.php; added with ICU UNUM_ROUND_HALF_ODD, #22704).
     *
     * Withheld on reference / PROFILE=8.2 (Zend 8.2 defined() false). Enable via stable 8.4.0+
     * or explicit `PHP_COMPILER_PROFILE=8.4`. ROUND_UNNECESSARY is never advertised — absent
     * from php-src stubs on every branch.
     */
    public static function supportsNumberFormatterPhp84RoundConsts(): bool
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

    /**
     * PHP 8.4+ LIBXML_RECOVER (ext/libxml/libxml.stub.php; XML_PARSE_RECOVER; #24439).
     *
     * Withheld on 8.4.0-dev reference / PROFILE=8.2 (Zend 8.2 defined() false). Enable via
     * stable 8.4.0+ or explicit `PHP_COMPILER_PROFILE=8.4` forward profile.
     */
    public static function supportsLibxmlRecoverConstant(): bool
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

    /**
     * PHP 8.4+ XML_OPTION_PARSE_HUGE (ext/xml/xml.stub.php; PHP_XML_OPTION_PARSE_HUGE; #28171).
     *
     * Withheld on 8.4.0-dev reference / PROFILE≤8.3 (Zend 8.2/8.3 defined() false). Enable via
     * stable 8.4.0+ or explicit `PHP_COMPILER_PROFILE=8.4` / `8.5` forward profile.
     */
    public static function supportsXmlOptionParseHuge(): bool
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

    /**
     * PHP 8.4+ array_all/any/find/find_key only (ext/standard/array.c, issue #11845, #12796, #14505, #14516, #14621, #14622, #15027, #15675, #24000, #24821).
     *
     * php-src never ships array_any_key()/array_all_key() — those phantoms were removed (#24000).
     * Withheld on 8.4.0-dev reference profile (matches Zend 8.2 function_exists gate). Enable via
     * stable 8.4.0+ or explicit `PHP_COMPILER_PROFILE=8.4` forward profile.
     * array_first()/array_last() are PHP 8.5+ — see {@see supportsPhp85ArrayFirstLast()} (#21173).
     *
     * Do not use {@see isForwardProfileAtLeast()} here — that would re-advertise on unset PROFILE
     * while {@see phpversion()} still reports {@see REFERENCE_PHP_VERSION} (#24821).
     */
    public static function supportsPhp84ArraySearchFunctions(): bool
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

    /**
     * PHP 8.4 final StreamBucket class (ext/standard/user_filters.stub.php; #26923).
     *
     * On ≤8.3 Zend, stream_bucket_new() returns stdClass and class_exists('StreamBucket')
     * is false (#10325). Same withhold shape as {@see supportsPhp84ArraySearchFunctions()}:
     * withheld on 8.4.0-dev reference / unset PROFILE; enable via stable 8.4.0+ or
     * {@code PHP_COMPILER_PROFILE=8.4}.
     */
    public static function supportsStreamBucketClass(): bool
    {
        return self::supportsPhp84ArraySearchFunctions();
    }

    /**
     * PHP 8.4 array_all/any/find family visible to function_exists() (#17007, #24821).
     *
     * Does not include array_first()/array_last() — those are PHP 8.5 (#21173).
     *
     * Withheld on 8.4.0-dev reference harness (no {@code PHP_COMPILER_PROFILE}) like Zend 8.2.
     */
    public static function advertisesPhp84ArraySearchFunctions(): bool
    {
        return self::supportsPhp84ArraySearchFunctions();
    }

    /**
     * PHP 8.4-only pcntl APIs (php-src ext/pcntl/pcntl.stub.php; #26742).
     *
     * {@code pcntl_getcpu}, affinity getters/setters, {@code pcntl_setns}, {@code pcntl_waitid}
     * are absent from PHP 8.2 stubs. Same withhold shape as {@see supportsPhp84ArraySearchFunctions()}:
     * withheld on 8.4.0-dev reference / unset PROFILE; enable via stable 8.4.0+ or
     * {@code PHP_COMPILER_PROFILE=8.4}.
     */
    public static function supportsPhp84PcntlApis(): bool
    {
        return self::supportsPhp84ArraySearchFunctions();
    }

    /** PHP 8.4 pcntl_* visible to function_exists() / get_defined_functions (#26742). */
    public static function advertisesPhp84PcntlApis(): bool
    {
        return self::supportsPhp84PcntlApis();
    }

    /**
     * PHP 8.5+ array_first()/array_last() (ext/standard/array.c; #21173).
     *
     * Withheld on 8.4.0-dev / PROFILE=8.4 so function_exists matches Zend 8.4. Enable via
     * stable 8.5.0+ or explicit `PHP_COMPILER_PROFILE=8.5` forward profile.
     */
    public static function supportsPhp85ArrayFirstLast(): bool
    {
        if (version_compare(self::VERSION, '8.5.0', '>=')) {
            return true;
        }

        $raw = getenv('PHP_COMPILER_PROFILE');
        if (!\is_string($raw) || '' === trim($raw)) {
            return false;
        }

        return version_compare(self::languageProfileVersion(), '8.5.0', '>=');
    }

    /** array_first()/array_last() visible to function_exists() — stable 8.5+ or forward PROFILE=8.5 (#21173). */
    public static function advertisesPhp85ArrayFirstLast(): bool
    {
        if (version_compare(self::VERSION, '8.5.0', '>=')) {
            return true;
        }

        return version_compare(self::languageProfileVersion(), '8.5.0', '>=');
    }

    /**
     * PHP 8.5+ IMAGETYPE_HEIF (ext/standard/image.c / php_image.h; #22787).
     *
     * Gated on {@see languageProfileVersion()} so PROFILE≤8.4 and the 8.4.0-dev reference profile
     * match Zend (defined('IMAGETYPE_HEIF') === false). Enable via PHP_COMPILER_PROFILE=8.5+.
     * Internal HEIF sniffing may keep using the numeric type without advertising the constant.
     */
    public static function supportsImagTypeHeif(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.5.0', '>=');
    }

    /**
     * PHP 8.5+ FILTER_THROW_ON_FAILURE + relocated FILTER_FLAG_GLOBAL_RANGE bit
     * (ext/filter/filter_private.h; #24065).
     *
     * On PROFILE≤8.4 / 8.4.0-dev reference: FILTER_FLAG_GLOBAL_RANGE === 0x10000000 and
     * FILTER_THROW_ON_FAILURE is undefined (Zend 8.2–8.4). PROFILE=8.5+ registers THROW at
     * 0x10000000 and moves GLOBAL_RANGE to 0x20000000.
     */
    public static function supportsFilterThrowOnFailure(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.5.0', '>=');
    }

    /**
     * PHP 8.5+ max_memory_limit INI (main/main.c; #23232).
     *
     * Ceiling for memory_limit — INI_SYSTEM, default "-1". Absent on PROFILE&lt;8.5 so
     * ini_get() is false like Zend.
     */
    public static function supportsMaxMemoryLimit(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.5.0', '>=');
    }

    /**
     * generator_to_array() — phantom; php-src has no such function (#24001).
     *
     * Always false on php-src-strict. Callers should use iterator_to_array().
     */
    public static function supportsGeneratorToArray(): bool
    {
        return false;
    }

    /** generator_to_array() — phantom; never advertise (#24001). */
    public static function advertisesGeneratorToArray(): bool
    {
        return false;
    }

    /**
     * PHP 8.4+ DatePeriod::createFromISO8601String() (ext/date/php_date.c, #7296).
     *
     * Gated on stable 8.4.0 / {@see languageProfileVersion()} so 8.4.0-dev reference profile matches Zend 8.2 phantom gate.
     */
    public static function supportsDatePeriodCreateFromISO8601String(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * PHP 8.3+ proc_get_status() cached bool — exit wait status cached after child exit
     * (php-src ext/standard/proc_open.c GH-10239 / PHP_FUNCTION(proc_get_status), #17362, #17883, #28527).
     *
     * Withheld on 8.4.0-dev reference profile (matches Zend 8.2 status array). Enable via stable 8.4.0+
     * or explicit `PHP_COMPILER_PROFILE=8.3` / `8.4` forward profile.
     */
    public static function supportsProcGetStatusCached(): bool
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
     * proc_get_status() pending_signals — never shipped in php-src / Zend 8.3–8.5
     * (phantom from #16707/#17907; retired #28527). Gate kept so call sites stay explicit.
     */
    public static function supportsProcGetStatusPendingSignals(): bool
    {
        return false;
    }

    /**
     * PHP 8.4+ DateTime::createFromTimestamp() / DateTimeImmutable::createFromTimestamp()
     * (ext/date/php_date.c, #5973, #9984, #18027, #22795).
     *
     * php.net 8.4 release — not present on Zend 8.3. Same gate as {@see supportsDateTimeMicrosecond()}.
     */
    public static function supportsDateTimeCreateFromTimestamp(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * PHP 8.4+ DateTime::getMicrosecond() / setMicrosecond() (ext/date/php_date.c, #7082, #21792, #22374).
     *
     * Gated on stable 8.4.0 / PHP_COMPILER_PROFILE=8.4 so 8.4.0-dev reference profile and
     * explicit PROFILE=8.2 match Zend 8.2 (methods undefined).
     */
    public static function supportsDateTimeMicrosecond(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * PHP 8.5+ Closure::getCurrent() (Zend/zend_closures.stub.php, #22583; re-#16989).
     *
     * php-src adds getCurrent() on the PHP-8.5 stub only — not on 8.4. Gated on stable 8.5.0 /
     * PHP_COMPILER_PROFILE=8.5 so PROFILE=8.4 matches Zend 8.4 (method undefined).
     */
    public static function supportsClosureGetCurrent(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.5.0', '>=');
    }

    /**
     * Closure::fromStatic() — never shipped by Zend/php-src (Zend/zend_closures.stub.php, #22583).
     *
     * Prior 8.4 forward-profile enable (#9992 / #16666) was wrong-direction vs php-src-strict.
     * Always withheld until present in php-src stubs.
     */
    public static function supportsClosureFromStatic(): bool
    {
        return false;
    }

    /**
     * Closure::getUsedVariables() — never shipped by Zend/php-src (Zend/zend_closures.stub.php, #22583).
     *
     * Prior 8.4 forward-profile enable (#6067 / #16735) was wrong-direction vs php-src-strict.
     * Always withheld until present in php-src stubs.
     */
    public static function supportsClosureGetUsedVariables(): bool
    {
        return false;
    }

    /**
     * PHP 8.4+ Closure var_dump name/file/line via zend_closure_get_debug_info (#7069, #22565).
     *
     * Not a Closure method — Zend uses the object get_debug_info handler only
     * (Zend/zend_closures.c). Gated so PROFILE=8.2 omits name/file/line (Zend 8.2 still emits
     * the `parameter` bag when args exist — see ClosureState::debugInfoEntries, #24521).
     */
    public static function supportsClosureRichDebugInfo(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * PHP 8.4+ HashContext::__debugInfo() algo-only dump (ext/hash/hash.stub.php, #7084, #22563).
     *
     * Withheld on 8.4.0-dev reference / PROFILE=8.2 so method_exists and var_dump match Zend 8.2
     * (empty object, no method). Enable via stable 8.4.0+ or `PHP_COMPILER_PROFILE=8.4`.
     */
    public static function supportsHashContextDebugInfo(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * PHP 8.4+ PDO::connect() driver-specific factory (ext/pdo/pdo_dbh.stub.php, #20529, #22600).
     *
     * Withheld on 8.4.0-dev reference / PROFILE=8.2 so method_exists matches Zend 8.2
     * (undefined method). Enable via stable 8.4.0+ or `PHP_COMPILER_PROFILE=8.4`.
     */
    public static function supportsPdoConnect(): bool
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
     * array_replace_key() — phantom; php-src has no such function (#24003).
     *
     * Always false on php-src-strict. Callers should use array_replace().
     */
    public static function supportsArrayReplaceKey(): bool
    {
        return false;
    }

    /**
     * ArrayPadType builtin enum for array_pad() pad_type (#17240, #24002).
     *
     * php-src never ships ArrayPadType (ext/standard/basic_functions.stub.php keeps
     * {@code array_pad(array $array, int $length, mixed $value): array} only). Always false
     * under php-src-strict — including PROFILE=8.4/8.5 (#24002).
     */
    public static function supportsArrayPadTypeEnum(): bool
    {
        return false;
    }

    /**
     * array_pad() optional $pad_type + ARRAY_PAD_* constants (#14993, #22786, #24002).
     *
     * php-src never defines ARRAY_PAD_* or a 4th parameter — direction is the sign of
     * {@code $length}. Always false under php-src-strict — including PROFILE=8.4/8.5 (#24002).
     */
    public static function supportsArrayPadPadType(): bool
    {
        return false;
    }

    /**
     * PHP 8.4+ substr() optional $truncate silences Z_STR_TRUNCATED warnings (#17239).
     * mb_substr has no $truncate in php-src — use this only for byte substr() clip warnings (#23603).
     *
     * Withheld on 8.4.0-dev reference profile — enable via PHP_COMPILER_PROFILE=8.4 forward profile (#17252).
     */
    public static function supportsSubstrTruncate(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * parse_str() optional $separator — never advertised.
     *
     * php-src stubs keep arity 2 through PHP 8.4+ (`basic_functions.stub.php`:
     * `parse_str(string $string, &$result): void`). #17320 added a phantom 3rd
     * parameter under PROFILE=8.4; #23949 gates it off to restore php-src-strict.
     */
    public static function supportsParseStrSeparator(): bool
    {
        return false;
    }

    /**
     * PHP 8.4+ mb_trim/ltrim/rtrim (ext/mbstring/mbstring.c, issue #11901, #12797, #9977, #17120).
     *
     * Withheld on 8.4.0-dev reference profile (matches Zend 8.2 function_exists gate). Enable via
     * stable 8.4.0+ or explicit `PHP_COMPILER_PROFILE=8.4` forward profile.
     */
    public static function supportsMbTrimFunctions(): bool
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

    /**
     * PHP 8.4+ mb_ucfirst()/mb_lcfirst() (ext/mbstring/mbstring.c, issue #4007, #17609, #22794).
     *
     * Zend/php-src ships these with mb_trim* in 8.4 only — not on 8.3. Withheld on 8.4.0-dev
     * reference profile (matches Zend 8.2 function_exists gate). Enable via stable 8.4.0+ or
     * explicit `PHP_COMPILER_PROFILE=8.4` forward profile (same shape as {@see supportsMbTrimFunctions()}).
     */
    public static function supportsMbUcfirstLcfirst(): bool
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

    /**
     * mb_ucfirst()/mb_lcfirst() visible to function_exists() — stable runtime or forward 8.4+ (#17609, #22794).
     */
    public static function advertisesMbUcfirstLcfirst(): bool
    {
        if (version_compare(self::VERSION, '8.4.0', '>=')) {
            return true;
        }

        if (!self::supportsMbUcfirstLcfirst()) {
            return false;
        }

        $raw = getenv('PHP_COMPILER_PROFILE');
        if (!\is_string($raw) || '' === trim($raw)) {
            return false;
        }

        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * mb_ucwords() — never shipped by Zend/php-src (ext/mbstring/mbstring.c).
     *
     * Prior forward-profile registration (#20799 / #21394) was wrong-direction: Zend 8.4/8.5
     * keep `function_exists('mb_ucwords') === false` (use `mb_convert_case(..., MB_CASE_TITLE)`).
     * Always withheld — #21458.
     */
    public static function supportsMbUcwords(): bool
    {
        return false;
    }

    /**
     * mb_ucwords() visible to function_exists() — always false (Zend never ships it; #21458).
     */
    public static function advertisesMbUcwords(): bool
    {
        return false;
    }

    /**
     * mb_trim/ltrim/rtrim visible to function_exists() — stable runtime or forward 8.4+ (#17206).
     */
    public static function advertisesMbTrimFunctions(): bool
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
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * PHP 8.4+ FiberStackOverflow (Zend/zend_exceptions.stub.php, Zend/zend_fibers.c, #26741, re-#7267).
     *
     * Withheld on 8.4.0-dev reference profile (matches Zend 8.2 class_exists gate). Enable via
     * stable 8.4.0+ or explicit {@code PHP_COMPILER_PROFILE=8.4} forward profile.
     */
    public static function advertisesFiberStackOverflowClass(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * PHP 8.4+ request_parse_body() (ext/standard/http.c, issue #16927).
     *
     * Gated on stable 8.4.0 / PHP_COMPILER_PROFILE=8.4 so 8.4.0-dev reference profile matches Zend 8.2 phantom gate.
     */
    public static function supportsRequestParseBody(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /** request_parse_body() visible to function_exists() — stable runtime or forward 8.4+ profile. */
    public static function advertisesRequestParseBody(): bool
    {
        if (version_compare(self::VERSION, '8.4.0', '>=')) {
            return true;
        }

        return self::supportsRequestParseBody();
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

    /**
     * PHP 8.4+ strxfrm() (ext/standard/string.c, #11907, #17319).
     *
     * Withheld on 8.4.0-dev reference profile (matches Zend 8.2 function_exists gate). Enable via
     * PHP_COMPILER_PROFILE=8.4 or stable 8.4.0+ runtime.
     */
    public static function supportsStrxfrm(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * convert_cyr_string() — removed in php-src 8.0 (ext/standard/cyr_convert.c, #21481, re-#11907/#17319).
     *
     * Only register on pre-8.0 language profiles (legacy). Zend 8.2 / 8.4 have no symbol.
     * php-src: UPGRADING — convert_cyr_string gone since 8.0.
     */
    public static function supportsConvertCyrString(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.0.0', '<');
    }

    /** convert_cyr_string() visible to function_exists() — pre-8.0 profiles only (#21481). */
    public static function advertisesConvertCyrString(): bool
    {
        return self::supportsConvertCyrString();
    }

    /**
     * money_format() — removed in php-src 8.0 (ext/standard/formatted_print.c, #21481, re-#3693).
     *
     * Only register on pre-8.0 language profiles (legacy). Zend 8.2 / 8.4 have no symbol.
     * php-src: UPGRADING — money_format gone since 8.0.
     */
    public static function supportsMoneyFormat(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.0.0', '<');
    }

    /** money_format() visible to function_exists() — pre-8.0 profiles only (#21481). */
    public static function advertisesMoneyFormat(): bool
    {
        return self::supportsMoneyFormat();
    }

    /**
     * getmygrgid() — absent from php-src (getmyuid/getmygid/getmypid/getmyinode only;
     * ext/standard/basic_functions.stub.php).
     *
     * Never register or advertise on php-src-strict profiles (including PROFILE=8.4/8.5).
     * Forward-profile enable (#17319) retired by #28366 (re-#11923).
     */
    public static function supportsGetmygrgid(): bool
    {
        return false;
    }

    /** getmygrgid() visible to function_exists() — never (php-src absent, #28366). */
    public static function advertisesGetmygrgid(): bool
    {
        return false;
    }

    /**
     * disktotalspace() legacy alias of disk_total_space() (ext/standard/filestat.c, #11922, #18017).
     *
     * Removed from php-src 8.2 reference profile — use disk_total_space(). Not registered here.
     */
    public static function supportsDisktotalspace(): bool
    {
        return false;
    }

    /** disktotalspace() visible to function_exists() — matches php-src reference (absent). */
    public static function advertisesDisktotalspace(): bool
    {
        return false;
    }

    /**
     * crc32c() — absent from php-src (only crc32(); ext/standard/crc32.c / basic_functions.stub.php).
     *
     * Never register or advertise on php-src-strict profiles (including PROFILE=8.3/8.4/8.5). #3270/#17139
     * forward-profile enable retired by #22584.
     */
    public static function supportsCrc32c(): bool
    {
        return false;
    }

    /** crc32c() visible to function_exists() — never (php-src absent, #22584). */
    public static function advertisesCrc32c(): bool
    {
        return false;
    }

    /**
     * hebrevc() — removed in php-src 8.0 (ext/standard/string.c, #20354, re-#17206).
     *
     * Only register on pre-8.0 language profiles (legacy). PHP 8.0+ keeps hebrev() only.
     * php-src: UPGRADING / basic_functions.stub.php — hebrev remains; hebrevc gone since 8.0.
     */
    public static function supportsHebrevc(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.0.0', '<');
    }

    /** hebrevc() visible to function_exists() — pre-8.0 profiles only (#20354). */
    public static function advertisesHebrevc(): bool
    {
        return self::supportsHebrevc();
    }

    /**
     * ext/bz2 via pure PHP {@see \PHPCompiler\ext\bz2\VmBz2Core} — withheld on reference profile (#11992, #14219, #16853).
     *
     * Gated on stable 8.4.0 / {@see languageProfileVersion()} so 8.4.0-dev reference profile matches Zend 8.2
     * phantom gate (host ext-bz2 often unloaded). Enable forward profile via `PHP_COMPILER_PROFILE=8.4`.
     */
    public static function supportsBz2(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * ext/msgpack via pure PHP {@see \PHPCompiler\ext\msgpack\VmMsgpack} — withheld on reference profile (#17994).
     *
     * Gated on stable 8.4.0 / {@see languageProfileVersion()} so 8.4.0-dev reference profile matches Zend 8.2
     * phantom gate (host ext-msgpack absent). Enable forward profile via `PHP_COMPILER_PROFILE=8.4`.
     */
    public static function supportsMsgpack(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * PECL apcu via pure PHP {@see \PHPCompiler\ext\apcu\VmApcu} — withheld on reference profile (#6574, #24909).
     *
     * Gated on stable 8.4.0 / {@see languageProfileVersion()} so 8.4.0-dev reference profile matches Zend 8.2
     * phantom gate (host pecl-APCu absent). Enable forward profile via `PHP_COMPILER_PROFILE=8.4`
     * or install host ext/apcu ({@see \PHPCompiler\ext\apcu\ApcuExtensionPolicy::advertisesExtension()}).
     */
    public static function supportsApcu(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * ext/simdjson via pure PHP {@see \PHPCompiler\ext\simdjson\VmSimdjson} — withheld on reference profile (#22530).
     *
     * Gated on stable 8.4.0 / {@see languageProfileVersion()} so 8.4.0-dev reference profile matches Zend 8.2
     * phantom gate (host pecl-simdjson absent). Enable forward profile via `PHP_COMPILER_PROFILE=8.4`.
     */
    public static function supportsSimdjson(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * ext/igbinary via pure PHP {@see \PHPCompiler\ext\igbinary\VmIgbinary} — withheld on reference profile (#6573).
     *
     * Gated on stable 8.4.0 / {@see languageProfileVersion()} so 8.4.0-dev reference profile matches Zend 8.2
     * phantom gate (host ext-igbinary absent). Enable forward profile via `PHP_COMPILER_PROFILE=8.4`.
     */
    public static function supportsIgbinary(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

   /**
     * ext/xmlrpc via pure PHP {@see \PHPCompiler\ext\xmlrpc\VmXmlrpc} — withheld on reference profile (#18503).
     *
     * Gated on stable 8.4.0 / {@see languageProfileVersion()} so 8.4.0-dev reference profile matches Zend 8.2
     * phantom gate (host ext-xmlrpc absent). Enable forward profile via `PHP_COMPILER_PROFILE=8.4`.
     */
    public static function supportsXmlrpc(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * ext/wddx via pure PHP {@see \PHPCompiler\ext\wddx\VmWddx} — withheld on reference profile (#6327).
     *
     * Gated on stable 8.4.0 / {@see languageProfileVersion()} so 8.4.0-dev reference profile matches Zend 8.2
     * phantom gate (host ext-wddx absent). Enable forward profile via `PHP_COMPILER_PROFILE=8.4`.
     */
    public static function supportsWddx(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }


    /**
     * ext/gmp GMP object + gmp_* — withheld on reference profile (#22860 / #3341).
     *
     * Gated on stable 8.4.0 / {@see languageProfileVersion()} so 8.4.0-dev reference profile matches
     * Zend 8.2 phantom gate (host php-gmp absent). Enable forward profile via `PHP_COMPILER_PROFILE=8.4`
     * or install host ext/gmp ({@see \PHPCompiler\ext\gmp\GmpExtensionPolicy::advertisesExtension()}).
     */
    public static function supportsGmp(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * pecl-networking-uuid uuid_* / UUID_* — withheld on reference profile (#23962 / #5910).
     *
     * Gated on stable 8.4.0 / {@see languageProfileVersion()} so 8.4.0-dev reference profile matches
     * Zend without pecl-uuid. Enable forward profile via `PHP_COMPILER_PROFILE=8.4`
     * or install host ext/uuid ({@see \PHPCompiler\ext\uuid\UuidExtensionPolicy::advertisesExtension()}).
     */
    public static function supportsUuid(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * ext/soap SoapClient/SoapServer/SoapFault — host php-soap only (#22859 / #25165 / #3724).
     *
     * Always false: language profile must not invent soap when Zend lacks php-soap (#25165).
     * Advertisement is {@see \PHPCompiler\ext\soap\SoapExtensionPolicy::advertisesExtension()}
     * (`extension_loaded('soap')` on the host).
     */
    public static function supportsSoap(): bool
    {
        return false;
    }

    /**
     * ext/yaml via pure PHP {@see \PHPCompiler\ext\yaml\VmYaml} — withheld on reference profile (#6275).
     *
     * Gated on stable 8.4.0 / {@see languageProfileVersion()} so 8.4.0-dev reference profile matches Zend 8.2
     * phantom gate (host ext-yaml absent). Enable forward profile via `PHP_COMPILER_PROFILE=8.4`.
     */
    public static function supportsYaml(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * ext/redis via pure PHP {@see \PHPCompiler\ext\redis\VmRedis} — withheld on reference profile (#6098).
     *
     * Gated on stable 8.4.0 / {@see languageProfileVersion()} so 8.4.0-dev reference profile matches Zend 8.2
     * phantom gate (host phpredis absent). Enable forward profile via `PHP_COMPILER_PROFILE=8.4`.
     */
    public static function supportsRedis(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * ext/memcached via pure PHP {@see \PHPCompiler\ext\memcached\VmMemcached} — withheld on reference profile (#6099).
     *
     * Gated on stable 8.4.0 / {@see languageProfileVersion()} so 8.4.0-dev reference profile matches Zend 8.2
     * phantom gate (host pecl-memcached absent). Enable forward profile via `PHP_COMPILER_PROFILE=8.4`.
     */
    public static function supportsMemcached(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * ext/rar via pure PHP {@see \PHPCompiler\ext\rar\VmRar} — withheld on reference profile (#6237).
     *
     * Gated on stable 8.4.0 / {@see languageProfileVersion()} so 8.4.0-dev reference profile matches Zend 8.2
     * phantom gate (host pecl-rar absent). Enable forward profile via `PHP_COMPILER_PROFILE=8.4`
     * or explicit {@code PHP_COMPILER_ENABLE_RAR=1}.
     */
    public static function supportsRar(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * ext/imap via pure PHP {@see \PHPCompiler\ext\imap\VmImapCore} — withheld on reference profile (#3663).
     *
     * Gated on stable 8.4.0 / {@see languageProfileVersion()} so 8.4.0-dev reference profile matches Zend 8.2
     * phantom gate (host php-imap / libc-client absent). Enable forward profile via `PHP_COMPILER_PROFILE=8.4`
     * or explicit {@code PHP_COMPILER_ENABLE_IMAP=1}.
     */
    public static function supportsImap(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * ext/eio via pure PHP {@see \PHPCompiler\ext\eio\VmEioCore} — withheld on reference profile (#6442).
     *
     * Gated on stable 8.4.0 / {@see languageProfileVersion()} so 8.4.0-dev reference profile matches Zend 8.2
     * phantom gate (host pecl-eio / libeio absent). Enable forward profile via `PHP_COMPILER_PROFILE=8.4`
     * or explicit {@code PHP_COMPILER_ENABLE_EIO=1}.
     */
    public static function supportsEio(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * ext/ssh2 via PHP {@see \PHPCompiler\ext\ssh2\VmSsh2Native} — withheld on reference profile (#6385).
     *
     * Gated on stable 8.4.0 / {@see languageProfileVersion()} so 8.4.0-dev reference profile matches Zend 8.2
     * phantom gate (host pecl-ssh2 / libssh2 absent). Enable forward profile via `PHP_COMPILER_PROFILE=8.4`
     * or explicit {@code PHP_COMPILER_ENABLE_SSH2=1}.
     */
    public static function supportsSsh2(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * ext/mongodb via pure PHP {@see \PHPCompiler\ext\mongodb\VmMongodb} — withheld on reference profile (#6575).
     *
     * Gated on stable 8.4.0 / {@see languageProfileVersion()} so 8.4.0-dev reference profile matches Zend 8.2
     * phantom gate (host pecl-mongodb absent). Enable forward profile via `PHP_COMPILER_PROFILE=8.4`.
     */
    public static function supportsMongodb(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * ext/snmp via pure PHP {@see \PHPCompiler\ext\snmp\VmSnmp} — withheld on reference profile (#6070).
     *
     * Gated on stable 8.4.0 / {@see languageProfileVersion()} so 8.4.0-dev reference profile matches Zend 8.2
     * phantom gate (host ext-snmp absent). Enable forward profile via `PHP_COMPILER_PROFILE=8.4`.
     */
    public static function supportsSnmp(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * ext/zip via pure PHP {@see \PHPCompiler\ext\zip\VmZipArchive} — withheld on reference profile (#18137, #11676).
     *
     * Gated on stable 8.4.0 / {@see languageProfileVersion()} so 8.4.0-dev reference profile matches Zend 8.2
     * phantom gate (host ext-zip absent). Enable forward profile via `PHP_COMPILER_PROFILE=8.4`.
     */
    public static function supportsZip(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * ext/brotli via pure PHP {@see \PHPCompiler\ext\brotli\VmBrotliNative} — withheld on reference profile (#17563).
     *
     * Gated on stable 8.4.0 / {@see languageProfileVersion()} so 8.4.0-dev reference profile matches Zend 8.2
     * phantom gate (host ext-brotli absent). Enable forward profile via `PHP_COMPILER_PROFILE=8.4`.
     */
    public static function supportsBrotli(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * ext/uri (php-src ext/uri/php_uri.stub.php) — PHP 8.5+ only (#9051, #17830, #26254).
     *
     * Withheld on reference profile and PROFILE≤8.4 (Zend 8.4 has no ext/uri). Enable via stable
     * 8.5.0+ or {@code PHP_COMPILER_PROFILE=8.5}.
     */
    public static function supportsUri(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.5.0', '>=');
    }

    /**
     * ext/sqlite3 SQLite3 class + query API — forward profile or host ext (#3434, #22791).
     *
     * {@code extension_loaded('sqlite3')} / {@code SQLite3Exception} follow
     * {@see \PHPCompiler\ext\sqlite3\Sqlite3ExtensionPolicy} (host ext or PROFILE=8.4;
     * exception is PHP 8.3+ only).
     */
    public static function supportsSqlite3(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * PHP 8.5+ SQLite3Stmt::busy() / explain() / setExplain() + EXPLAIN_MODE_* and
     * SQLite3Result::fetchAll() (php-src ext/sqlite3/sqlite3.stub.php; #27594, #20600).
     *
     * Absent from PHP-8.4 stubs; migration85 lists Sqlite3Stmt::busy. Withheld on
     * PROFILE≤8.4 so method_exists matches Zend 8.4. Enable via stable 8.5.0+ or
     * explicit {@code PHP_COMPILER_PROFILE=8.5}.
     */
    public static function supportsSqlite3Php85Apis(): bool
    {
        if (version_compare(self::VERSION, '8.5.0', '>=')) {
            return true;
        }

        return version_compare(self::languageProfileVersion(), '8.5.0', '>=');
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
     * ext/bcmath surface visible to extension_loaded()/function_exists() (#16086, #19608).
     *
     * Match {@see supportsBcmath()} so BcMath\Number / bcadd / extension_loaded('bcmath')
     * stay paired on forward 8.4 (no phantom class_exists split). Same shape as
     * {@see advertisesBcround()}.
     */
    public static function advertisesBcmath(): bool
    {
        if (version_compare(self::VERSION, '8.4.0', '>=')) {
            return true;
        }

        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
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
     * PHP 8.5+ get_error_handler() / get_exception_handler() (ext/standard/basic_functions.c; #17644, #21175).
     *
     * Withheld on 8.4.0-dev / PROFILE=8.4 so function_exists matches Zend ≤8.4. Enable via
     * stable 8.5.0+ or explicit `PHP_COMPILER_PROFILE=8.5` forward profile.
     * php-src: ext/standard/basic_functions.stub.php (PHP-8.5).
     */
    public static function supportsGetHandlerIntrospection(): bool
    {
        if (version_compare(self::VERSION, '8.5.0', '>=')) {
            return true;
        }

        return version_compare(self::languageProfileVersion(), '8.5.0', '>=');
    }

    /** get_error_handler()/get_exception_handler() visible to function_exists() — stable 8.5+ or PROFILE=8.5. */
    public static function advertisesGetHandlerIntrospection(): bool
    {
        if (version_compare(self::VERSION, '8.5.0', '>=')) {
            return true;
        }

        return version_compare(self::languageProfileVersion(), '8.5.0', '>=');
    }

    /**
     * Forward DOM APIs gated like str_increment() (#18614) and compareDocumentPosition (#18504).
     *
     * Unset `PHP_COMPILER_PROFILE` on `8.4.0-dev` withholds PHP 8.3+/8.4+ DOM methods so
     * `method_exists` matches Zend 8.2 while `phpversion()` reports 8.2.31. Enable via stable
     * 8.4.0+ or explicit `PHP_COMPILER_PROFILE=8.3` / `8.4` forward profile.
     */
    private static function supportsDomApiSince(string $since): bool
    {
        if (version_compare(self::VERSION, '8.4.0', '>=')) {
            return version_compare(self::VERSION, $since, '>=');
        }

        $raw = getenv('PHP_COMPILER_PROFILE');
        if (!\is_string($raw) || '' === trim($raw)) {
            return false;
        }

        return version_compare(self::languageProfileVersion(), $since, '>=');
    }

    /**
     * PHP 8.4+ DOMNode::contains() (ext/dom/node.c, #14447, #17163, #17759).
     *
     * Withheld on 8.4.0-dev reference profile (matches Zend 8.2 method_exists gate).
     * Enable via stable 8.4.0+ or explicit `PHP_COMPILER_PROFILE=8.4` forward profile.
     */
    public static function supportsDomNodeContains(): bool
    {
        return self::supportsDomApiSince('8.4.0');
    }

    /**
     * PHP 8.4+ DOMNode::compareDocumentPosition() (ext/dom/node.c, #14448, #17696, #18092, #18504).
     */
    public static function supportsDomNodeCompareDocumentPosition(): bool
    {
        return self::supportsDomApiSince('8.4.0');
    }

    /**
     * PHP 8.4+ DOMNode::getRootNode() (ext/dom/node.c, #14449, #14599).
     */
    public static function supportsDomNodeGetRootNode(): bool
    {
        return self::supportsDomApiSince('8.4.0');
    }

    /**
     * PHP 8.4+ DOMNode::isEqualNode() (ext/dom/node.c, #15195, #14599).
     */
    public static function supportsDomNodeIsEqualNode(): bool
    {
        return self::supportsDomApiSince('8.4.0');
    }

    /**
     * PHP 8.4+ DOMNode::replaceChildren() (ext/dom/parentnode.c, #16822).
     */
    public static function supportsDomNodeReplaceChildren(): bool
    {
        return self::supportsDomApiSince('8.4.0');
    }

    /**
     * PHP 8.5+ Dom\Element::insertAdjacentHTML() / legacy DOMElement advertisement
     * (php-src PHP-8.5+ ext/dom/php_dom.stub.php; #26063, re-#16128).
     *
     * PHP-8.4 stubs list insertAdjacentElement/Text only — no insertAdjacentHTML on
     * Dom\Element or DOMElement. Withheld on PROFILE=8.4 / Zend 8.4. Enable via stable
     * 8.5.0+ or explicit `PHP_COMPILER_PROFILE=8.5`.
     */
    public static function supportsDomElementInsertAdjacentHtml(): bool
    {
        return self::supportsDomApiSince('8.5.0');
    }

    /**
     * PHP 8.5+ Dom\Element::getElementsByClassName() (+ Dom\Document alias)
     * (php-src PHP-8.5+ ext/dom/php_dom.stub.php; #27593).
     *
     * PHP-8.4 stubs have getElementsByTagName(NS) only on living Element/Document —
     * no getElementsByClassName. Withheld on PROFILE=8.4 / Zend 8.4. Enable via stable
     * 8.5.0+ or explicit `PHP_COMPILER_PROFILE=8.5`.
     */
    public static function supportsDomElementGetElementsByClassName(): bool
    {
        return self::supportsDomApiSince('8.5.0');
    }

    /**
     * PHP 8.4+ DOMElement::insertAdjacentElement() (ext/dom/php_dom.c, #16865).
     */
    public static function supportsDomElementInsertAdjacentElement(): bool
    {
        return self::supportsDomApiSince('8.4.0');
    }

    /**
     * PHP 8.4+ DOMElement::insertAdjacentText() (ext/dom/element.c, #16914).
     */
    public static function supportsDomElementInsertAdjacentText(): bool
    {
        return self::supportsDomApiSince('8.4.0');
    }

    /**
     * PHP 8.4+ DOMElement::getInnerHTML() / getOuterHTML() (ext/dom/inner_html_mixin.c, #16916).
     */
    public static function supportsDomElementInnerOuterHtml(): bool
    {
        return self::supportsDomApiSince('8.4.0');
    }

    /**
     * PHP 8.3+ DOMElement::toggleAttribute() (ext/dom/element.c, #16824).
     */
    public static function supportsDomElementToggleAttribute(): bool
    {
        if (version_compare(self::VERSION, '8.3', '<')) {
            return false;
        }

        return self::supportsDomApiSince('8.3.0');
    }

    /** PHP 8.3+ DOMElement::getAttributeNames() (ext/dom/element.c, #16823, #16975). */
    public static function supportsDomElementGetAttributeNames(): bool
    {
        return self::supportsDomApiSince('8.3.0');
    }

    /**
     * PHP 8.3+ real DOMDocument::adoptNode() (ext/dom/document.c, #24995, re-#19654).
     *
     * Method exists on Zend 8.2 but throws {@code Error: Not yet implemented}. Enable the real
     * reparent via stable 8.4.0+ or explicit `PHP_COMPILER_PROFILE=8.3` / `8.4` forward profile.
     * Reference / PROFILE=8.2 keeps the Zend 8.2 stub Error.
     */
    public static function supportsDomDocumentAdoptNode(): bool
    {
        return self::supportsDomApiSince('8.3.0');
    }

    /**
     * PHP 8.3+ legacy DOMElement::$id / $className virtual HTML attributes
     * (php-src ext/dom/php_dom.stub.php / php_dom.c prop handlers; #22457).
     *
     * Living Dom\Element already exposes these under 8.4+; this gate is for classic DOMElement.
     */
    public static function supportsDomElementIdClassNameProperties(): bool
    {
        return self::supportsDomApiSince('8.3.0');
    }

    /**
     * PHP 8.4+ Dom\Element::$classList / Dom\TokenList
     * (php-src php_dom.stub.php / token_list.c; #16876, #16974, #20512, #28227).
     *
     * Legacy DOMElement has no $classList and there is no DOMTokenList class.
     */
    public static function supportsDomTokenList(): bool
    {
        return self::supportsDomApiSince('8.4.0');
    }

    /**
     * DOM Living Standard parentElement on DOMChildNode (PHP 8.4+, ext/dom/node.c).
     */
    public static function supportsDomParentElement(): bool
    {
        return self::supportsDomApiSince('8.4.0');
    }

    /**
     * PHP 8.3+ DOMNode::$isConnected (ext/dom/node.c, php-src#11677, #19653).
     *
     * Withheld on 8.4.0-dev reference profile (matches Zend 8.2 property gate).
     * Enable via stable 8.3.0+ or explicit `PHP_COMPILER_PROFILE=8.3` / `8.4` forward profile.
     */
    public static function supportsDomNodeIsConnected(): bool
    {
        return self::supportsDomApiSince('8.3.0');
    }

    /**
     * PHP 8.4+ Dom\ living-standard namespace (Dom\HTMLDocument, Dom\XMLDocument, …; ext/dom/html_document.c).
     */
    public static function supportsDomLivingStandardNamespace(): bool
    {
        return self::supportsDomApiSince('8.4.0');
    }

    /**
     * PHP 8.5+ Dom\ ParentNode::$children live HTMLCollection
     * (php-src PHP-8.5+ ext/dom/php_dom.stub.php; #21559, re-#21033).
     *
     * Withheld on PROFILE=8.4 / Zend 8.4.23 — `$el->children` is an undefined property
     * (E_WARNING + null). Enable via stable 8.5.0+ or explicit `PHP_COMPILER_PROFILE=8.5`.
     */
    public static function supportsDomParentNodeChildren(): bool
    {
        return self::supportsDomApiSince('8.5.0');
    }

    /**
     * PHP 8.5+ Dom\Element::$outerHTML living string property
     * (php-src PHP-8.5+ ext/dom/php_dom.stub.php; #22482, re-#20532).
     *
     * PHP-8.4 stubs advertise Dom\Element::$innerHTML only; `$outerHTML` arrives on
     * master / 8.5+. Withheld on PROFILE=8.4 — `$el->outerHTML` is undefined
     * (E_WARNING + null). Enable via stable 8.5.0+ or explicit `PHP_COMPILER_PROFILE=8.5`.
     */
    public static function supportsDomElementOuterHtmlProperty(): bool
    {
        return self::supportsDomApiSince('8.5.0');
    }

    /**
     * PHP 8.4+ DOMXPath::quote() static XPath literal escaper (ext/dom/xpath.c, #18650).
     */
    public static function supportsDomXPathQuote(): bool
    {
        return self::supportsDomApiSince('8.4.0');
    }

    /**
     * PHP 8.4+ DOMXPath::registerPhpFunctionNS() namespaced XPath callbacks
     * (ext/dom/xpath.c / xpath_callbacks.c; #20119).
     */
    public static function supportsDomXPathRegisterPhpFunctionNS(): bool
    {
        return self::supportsDomApiSince('8.4.0');
    }

    /**
     * PHP 8.4+ XSLTProcessor::registerPHPFunctionNS() namespaced XSLT callbacks
     * (ext/xsl/xsltprocessor.c / xpath_callbacks.c; #22243).
     */
    public static function supportsXsltRegisterPHPFunctionNS(): bool
    {
        return self::supportsDomApiSince('8.4.0');
    }

    /**
     * PHP 8.4+ parenthesized asymmetric set modifier `public (private(set))` on properties.
     *
     * Gated on {@see languageProfileVersion()} so 8.4.0-dev reference profile rejects like Zend 8.2
     * (#16450, #24819). Same `version_compare` rule as {@see supportsAsymmetricVisibility()}.
     * php-src: Zend/zend_compile.c asymmetric visibility scope parsing.
     */
    public static function supportsParenthesizedAsymmetricSetModifier(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * Catch intersection types `catch (A&B $e)` — always false (php-src-strict).
     *
     * php-src `catch_name_list` allows only `|` (union), never `&` (#28439; #28205 was inverted).
     * {@see Ast\CatchIntersectionSupport} rejects `&` / parenthesized catch types like Zend.
     * php-src: Zend/zend_language_parser.y catch_name_list.
     */
    public static function supportsCatchIntersectionTypes(): bool
    {
        return false;
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

    /**
     * PHP 8.4+ XMLReader::{fromString,fromUri,fromStream} static factories
     * (ext/xmlreader/php_xmlreader.c, #19607).
     *
     * Withheld on 8.4.0-dev reference profile (matches Zend 8.2 method_exists gate).
     * Enable via stable 8.4.0+ or explicit `PHP_COMPILER_PROFILE=8.4` forward profile.
     */
    public static function supportsXmlReaderFactories(): bool
    {
        return self::supportsDomApiSince('8.4.0');
    }

    /**
     * PHP 8.4+ XMLWriter::{toMemory,toUri,toStream} static factories
     * (ext/xmlwriter/php_xmlwriter.c, #19606).
     *
     * Withheld on 8.4.0-dev reference profile (matches Zend 8.2 method_exists gate).
     * Enable via stable 8.4.0+ or explicit `PHP_COMPILER_PROFILE=8.4` forward profile.
     */
    public static function supportsXmlWriterFactories(): bool
    {
        return self::supportsDomApiSince('8.4.0');
    }
}
