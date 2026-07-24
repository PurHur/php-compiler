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
     * PHP 8.3+ typed class constants on classes/enums (Zend/zend_compile.c, #3592, #12798, #12994, #15367, #22705).
     *
     * Withheld on 8.4.0-dev reference profile (phpversion() 8.2.31 matches Zend 8.2 parse error).
     * Enable via stable 8.4.0+ or explicit `PHP_COMPILER_PROFILE=8.3` / `8.4` forward profile.
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
     * 8.4.0+ or explicit `PHP_COMPILER_PROFILE=8.3` / `8.4` forward profile (#17863, re-#17801).
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
     * PHP 8.4+ #[\Deprecated] on file/namespace constants (Zend/zend_compile.c, issue #16819).
     *
     * Withheld on 8.4.0-dev reference profile (matches Zend 8.2 parse error). Enable via stable
     * 8.4.0+ or explicit `PHP_COMPILER_PROFILE=8.4` forward profile.
     */
    public static function supportsGlobalDeprecatedConstAttributes(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
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
     * On the 8.4 development line, advertise implemented forward-compat builtins
     * (array_find family, …) even while VERSION is 8.4.0-dev — version_compare treats
     * -dev below stable (#12327, #12328). Profile-gated 8.3+ surfaces such as
     * json_validate / str_increment still withhold on the unset-PROFILE reference harness (#22544).
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
     * str_increment()/str_decrement() visible to function_exists() — stable runtime or forward 8.3+ (#16292, #18614).
     *
     * Callable under forward profile via {@see supportsStrIncrement()}; withheld on 8.4.0-dev reference harness
     * (no {@code PHP_COMPILER_PROFILE}) like Zend 8.2.
     */
    public static function advertisesStrIncrement(): bool
    {
        if (version_compare(self::VERSION, '8.4.0', '>=')) {
            return true;
        }

        if (!self::supportsStrIncrement()) {
            return false;
        }

        $raw = getenv('PHP_COMPILER_PROFILE');
        if (!\is_string($raw) || '' === trim($raw)) {
            return false;
        }

        return version_compare(self::languageProfileVersion(), '8.3.0', '>=');
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
     * PHP 8.3+ class_uses_recursive() (ext/standard/basic_functions.c, issue #6469, #12816, #16708).
     *
     * Withheld on 8.4.0-dev reference profile (matches Zend 8.2 function_exists gate). Enable via
     * stable 8.4.0+ or explicit `PHP_COMPILER_PROFILE=8.3` / `8.4` forward profile.
     */
    public static function supportsClassUsesRecursive(): bool
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
     * class_uses_recursive() visible to function_exists() — same gate as {@see supportsClassUsesRecursive()}.
     */
    public static function advertisesClassUsesRecursive(): bool
    {
        return self::supportsClassUsesRecursive();
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
        return self::advertisesForwardProfile84BuiltinAttributeClass();
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

    /** PHP 8.3+ ReflectionConstant class advertisement (ext/reflection/php_reflection.c, #12385, #13497, #16837). */
    public static function advertisesReflectionConstantClass(): bool
    {
        // Stable 8.4.0+ or forward profile — 8.4.0-dev reference profile matches Zend 8.2 phantom gate.
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
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

    /** PHP 8.4+ #[\DelayedTargetValidation] builtin attribute class advertisement (#11902). */
    public static function advertisesDelayedTargetValidationAttributeClass(): bool
    {
        return self::advertisesForwardProfile84BuiltinAttributeClass();
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
     * PHP 8.4+ deprecates implicit nullable typed params (`int $x = null`) at compile time.
     *
     * php-src: Zend/zend_compile.c (zend_compile_params), RFC deprecate-implicitly-nullable-types.
     */
    public static function supportsImplicitNullableParameterDeprecation(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
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
     * json_decode()/json_validate() $json — always soft-null (DEP+coerce), matching Zend Z_PARAM_STR (#21223).
     *
     * Earlier #18852 assumed typed stub `string $json` meant TypeError on 8.4; Zend 8.4 still
     * deprecates and coerces null (same as other Z_PARAM_STR builtins). Kept as a named gate so
     * callers can share {@see \PHPCompiler\ext\standard\JsonStringOperandArg}.
     */
    public static function jsonStringOperandRequiresStrictType(): bool
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
     * PHP 8.3+ number_format() negative $decimals (ext/standard/number_format.c, #15917).
     *
     * Prior to 8.3, negative values are ignored like 0. On PHP 8.4+, negative decimals are rejected
     * with ValueError (re-#17261, #17369).
     *
     * Requires explicit `PHP_COMPILER_PROFILE=8.3` so the 8.4.0-dev reference profile matches Zend 8.2.
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

        $profile = self::languageProfileVersion();
        if (version_compare($profile, '8.4.0', '>=')) {
            return false;
        }

        return version_compare($profile, '8.3.0', '>=');
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
     * PHP 8.4+ get_declared_* optional $exclude_deprecated (ext/standard/basic_functions.c, #12403).
     *
     * Gated on stable 8.4.0 so 8.4.0-dev reference profile rejects any argument like Zend 8.2.
     */
    public static function supportsGetDeclaredExcludeDeprecated(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * PHP 8.4+ get_class()/get_parent_class() optional $allow_string (ext/standard/basic_functions.c, #17395).
     *
     * Gated on stable 8.4.0 so 8.4.0-dev reference profile rejects a second argument like Zend 8.2.
     */
    public static function supportsGetClassAllowString(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
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
     * PHP 8.4+ exit()/die() as proper functions — FCC, named args, two-arg (#6975, #12413, #12414, #12435, #13650, #13885, #13973).
     *
     * Gated on stable 8.4.0 so 8.4.0-dev reference profile rejects named/two-arg/FCC forms like Zend 8.2.
     * Forward profile via {@see languageProfileVersion()} enables exit(status:)/die(message:) on 8.4.0-dev (#13487).
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
     * PHP 8.4+ asymmetric property visibility (private(set), protected(set), …).
     *
     * Gated on {@see languageProfileVersion()} so 8.4.0-dev reference profile rejects like Zend 8.2 (#12508, #17197).
     * Forward profile: `PHP_COMPILER_PROFILE=8.4` or stable 8.4.0+.
     * php-src: Zend/zend_language_parser.y T_PRIVATE_SET; Zend/zend_compile.c ZEND_ACC_*_SET.
     */
    public static function supportsAsymmetricVisibility(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * PHP 8.4+ property hooks (`$prop { get; set; }`, default initializer + hook block).
     *
     * Gated on {@see languageProfileVersion()} so 8.4.0-dev reference profile (reported PHP_VERSION
     * 8.2.x) rejects like Zend 8.2 (#22371, re-#18531 / #19952). Explicit `PHP_COMPILER_PROFILE=8.2`
     * still rejects; `PHP_COMPILER_PROFILE=8.4` or stable 8.4.0+ enables.
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
     * Forward profile via `PHP_COMPILER_PROFILE=8.4` enables parse/compile of `final public $x`.
     * php-src: Zend/zend_inheritance.c — Cannot override final property.
     */
    public static function supportsFinalProperties(): bool
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
     * (#19684, re-#6974). Forward profile: `PHP_COMPILER_PROFILE=8.4` or stable 8.4.0+.
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
     * Gated on stable 8.4.0 / {@see languageProfileVersion()} so 8.4.0-dev reference profile matches Zend 8.2
     * (methods absent). Same gate as {@see supportsPropertyHooks()} (#22371); kept separate so Reflection
     * probes can diverge if php-src ever ships methods without hook syntax.
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
     * PHP 8.3+ stream_supports() / STREAM_SUPPORT_* (ext/standard/streams.c, issue #11819, #13238, #15692, #16741).
     *
     * Withheld on 8.4.0-dev reference profile (matches Zend 8.2 — stream_supports absent). Enable via
     * stable 8.4.0+ or explicit `PHP_COMPILER_PROFILE=8.3` / `8.4` forward profile.
     */
    public static function supportsStreamSupports(): bool
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
     * stream_supports() visible to function_exists() — stable runtime or forward 8.3+ (#17007).
     */
    public static function advertisesStreamSupports(): bool
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
     * PHP 8.4+ STREAM_SUPPORT_READ/WRITE constants (ext/standard/streams.c, issue #16846).
     *
     * Gated on stable 8.4.0 / {@see languageProfileVersion()} so 8.4.0-dev reference profile
     * matches Zend 8.2/8.3 phantom gate. Enable forward profile via `PHP_COMPILER_PROFILE=8.4`.
     */
    public static function supportsStreamSupportReadWriteConstants(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * PHP 8.3+ json_validate() (ext/json/php_json.c, issue #3101, #11826, #12363, #13365, #14518, #14708, #14972, #15026, #15196, #15241, #16091, #22544).
     *
     * Withheld on 8.4.0-dev reference profile (matches Zend 8.2 function_exists gate when reported
     * PHP_VERSION is the 8.2 reference string). Enable via stable 8.4.0+ or explicit
     * `PHP_COMPILER_PROFILE=8.3` / `8.4` forward profile (#16091, #17007).
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
     * json_validate() visible to function_exists() — stable runtime or forward 8.3+ (#17007, #22544).
     *
     * Withheld on 8.4.0-dev reference harness (no {@code PHP_COMPILER_PROFILE}) like Zend 8.2.
     */
    public static function advertisesJsonValidate(): bool
    {
        if (version_compare(self::VERSION, '8.4.0', '>=')) {
            return true;
        }

        if (!self::supportsJsonValidate()) {
            return false;
        }

        $raw = getenv('PHP_COMPILER_PROFILE');
        if (!\is_string($raw) || '' === trim($raw)) {
            return false;
        }

        return version_compare(self::languageProfileVersion(), '8.3.0', '>=');
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
     * PHP 8.3+ clock_gettime() / ClockInterface (ext/standard/hrtime.c, #11624, #12470).
     *
     * Gated on stable 8.4.0 so 8.4.0-dev reference profile matches Zend 8.2 phantom gate.
     */
    public static function supportsClockGettime(): bool
    {
        return self::supportsBuiltinStubEnums();
    }

    /**
     * PHP 8.4+ createLazyGhost()/createLazyProxy() and ReflectionClass lazy factories (#6708, #12375, #16812).
     *
     * Gated on stable 8.4.0 / {@see languageProfileVersion()} so 8.4.0-dev reference profile matches Zend 8.2 phantom gate.
     */
    public static function supportsLazyObjectFactories(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * PHP 8.4+ ReflectionClass::{getDeprecatedMessage,getDeprecatedVersion,getLazyPropertyNames,
     * getReadOnlyProperties,isStatic} (ext/reflection/php_reflection.stub.php, #22599; re-#6917/#6606).
     *
     * Gated on stable 8.4.0 / {@see languageProfileVersion()} so 8.4.0-dev / PROFILE=8.2 matches Zend 8.2
     * (methods absent). Enable forward profile on dev via `PHP_COMPILER_PROFILE=8.4`.
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
     * Note: getMangledName / isDefaultValueAvailable are never registered — not php-src ReflectionProperty APIs
     * (parameter side uses isDefaultValueAvailable; property side uses hasDefaultValue).
     */
    public static function supportsReflectionPropertyPhp84RawValueApis(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
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
     * PHP 8.3+ class_constants() (ext/standard/basic_functions.c, #7309, #12448).
     *
     * Gated on stable 8.4.0 / {@see languageProfileVersion()} so 8.4.0-dev reference profile matches Zend 8.2 phantom gate.
     */
    public static function supportsClassConstants(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
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
     * PHP 8.4+ array_all/any/find/find_key (ext/standard/array.c, issue #11845, #12796, #14505, #14516, #14621, #14622, #15027, #15675).
     *
     * Withheld on 8.4.0-dev reference profile (matches Zend 8.2 function_exists gate). Enable via
     * stable 8.4.0+ or explicit `PHP_COMPILER_PROFILE=8.4` forward profile.
     * array_first()/array_last() are PHP 8.5+ — see {@see supportsPhp85ArrayFirstLast()} (#21173).
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
     * PHP 8.4 array_all/any/find family visible to function_exists() (#17007).
     *
     * Does not include array_first()/array_last() — those are PHP 8.5 (#21173).
     */
    public static function advertisesPhp84ArraySearchFunctions(): bool
    {
        if (version_compare(self::VERSION, '8.4.0', '>=')) {
            return true;
        }

        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
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
     * PHP 8.4+ generator_to_array() (ext/standard/array.c, issue #6025, #16723, #17118, #18084).
     *
     * Withheld on 8.4.0-dev reference profile (matches Zend 8.2 phantom gate). Enable via stable
     * 8.4.0+ or explicit `PHP_COMPILER_PROFILE=8.4` forward profile.
     */
    public static function supportsGeneratorToArray(): bool
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

    /** generator_to_array() visible to function_exists() — stable runtime or forward 8.4+ (#18084). */
    public static function advertisesGeneratorToArray(): bool
    {
        if (version_compare(self::VERSION, '8.4.0', '>=')) {
            return true;
        }

        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
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
     * PHP 8.3+ proc_get_status() pending_signals array (ext/standard/proc_open.c, #16707, #17907).
     *
     * Withheld on 8.4.0-dev reference profile (matches Zend 8.2 status array). Enable via stable 8.4.0+
     * or explicit `PHP_COMPILER_PROFILE=8.3` / `8.4` forward profile.
     */
    public static function supportsProcGetStatusPendingSignals(): bool
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
     * (Zend/zend_closures.c). Gated so 8.4.0-dev / PROFILE=8.2 match Zend 8.2 empty dumps.
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
     * PHP 8.4+ array_replace_key() (ext/standard/array.c, issue #5650, #12826).
     *
     * Gated on stable 8.4.0 / {@see languageProfileVersion()} so 8.4.0-dev reference profile matches Zend 8.2 phantom gate.
     */
    public static function supportsArrayReplaceKey(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * PHP 8.4+ ArrayPadType builtin enum for array_pad() pad_type (#17240).
     *
     * Withheld on 8.4.0-dev reference profile — enable via PHP_COMPILER_PROFILE=8.4 forward profile.
     */
    public static function supportsArrayPadTypeEnum(): bool
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
     * PHP 8.4+ array_pad() optional $pad_type + ARRAY_PAD_* constants (ext/standard/array.c, #14993).
     *
     * Forward profile on 8.4.0-dev — advertisesBuiltinSince treats -dev as 8.4.0 (#14983 reference gate).
     */
    public static function supportsArrayPadPadType(): bool
    {
        return self::advertisesBuiltinSince('8.4.0');
    }

    /**
     * PHP 8.4+ substr()/mb_substr() optional $truncate (ext/standard/string.c, ext/mbstring/mbstring.c, #17239).
     *
     * Withheld on 8.4.0-dev reference profile — enable via PHP_COMPILER_PROFILE=8.4 forward profile (#17252).
     */
    public static function supportsSubstrTruncate(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * PHP 8.4+ parse_str() optional $separator (ext/standard/quot_print.c, #17320).
     *
     * Withheld on 8.4.0-dev reference profile — enable via PHP_COMPILER_PROFILE=8.4 forward profile.
     */
    public static function supportsParseStrSeparator(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
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
     * PHP 8.4+ getmygrgid() (ext/standard/basic_functions.c, #11923, #17319).
     *
     * Withheld on 8.4.0-dev reference profile (matches Zend 8.2 function_exists gate). Enable via
     * PHP_COMPILER_PROFILE=8.4 or stable 8.4.0+ runtime.
     */
    public static function supportsGetmygrgid(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
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
     * ext/uri (php-src ext/uri/php_uri.stub.php) — withheld on reference profile (#9051, #17830).
     *
     * Gated on stable 8.4.0 / {@see languageProfileVersion()} so 8.4.0-dev reference profile matches Zend 8.2
     * phantom gate. Enable forward profile via `PHP_COMPILER_PROFILE=8.4`.
     */
    public static function supportsUri(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
    }

    /**
     * ext/sqlite3 SQLite3 class + query API — forward profile only (#3434).
     *
     * {@code extension_loaded('sqlite3')} and {@code SQLite3Exception} are advertised whenever
     * the in-tree module loads ({@see Sqlite3ExtensionPolicy::advertisesExtensionLoaded()}, #19047).
     */
    public static function supportsSqlite3(): bool
    {
        return version_compare(self::languageProfileVersion(), '8.4.0', '>=');
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
     * PHP 8.4+ DOMElement::insertAdjacentHTML() (ext/dom/dom_element.c, #16128).
     */
    public static function supportsDomElementInsertAdjacentHtml(): bool
    {
        return self::supportsDomApiSince('8.4.0');
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
     * PHP 8.3+ legacy DOMElement::$id / $className virtual HTML attributes
     * (php-src ext/dom/php_dom.stub.php / php_dom.c prop handlers; #22457).
     *
     * Living Dom\Element already exposes these under 8.4+; this gate is for classic DOMElement.
     */
    public static function supportsDomElementIdClassNameProperties(): bool
    {
        return self::supportsDomApiSince('8.3.0');
    }

    /** PHP 8.4+ DOMElement::$classList / DOMTokenList and Dom\TokenList (#16876, #16974, #20512). */
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
