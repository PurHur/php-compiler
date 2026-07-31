<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCfg\Func as CfgFunc;
use PHPCompiler\CompilerVersion;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\ReflectionSupport;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * Spoofchecker — ICU uspoof_* via thin FFI (php-src ext/intl/spoofchecker; #20035 / deferred #6171).
 *
 * PHP-in-PHP class surface; C is only the ICU ABI trampoline (same pattern as {@see VmCollator}).
 */
final class VmSpoofchecker
{
    public const CLASS_LC = 'spoofchecker';

    /** ICU U_INVALID_STATE_ERROR — areConfusable without confusable check bits (#25209). */
    public const U_INVALID_STATE_ERROR = 27;

    /** @see unicode/uspoof.h USPOOF_* */
    public const SINGLE_SCRIPT_CONFUSABLE = 1;
    public const MIXED_SCRIPT_CONFUSABLE = 2;
    public const WHOLE_SCRIPT_CONFUSABLE = 4;
    public const ANY_CASE = 8;
    public const SINGLE_SCRIPT = 16;
    public const RESTRICTION_LEVEL = 16;
    public const INVISIBLE = 32;
    public const CHAR_LIMIT = 64;
    public const MIXED_NUMBERS = 128;
    public const HIDDEN_OVERLAY = 256;
    public const ALL_CHECKS = 0xFFFF;
    public const AUX_INFO = 0x40000000;

    /** Bits that make uspoof_areConfusableUTF8 valid (ICU uspoof.h; #25209). */
    private const CONFUSABLE_CHECK_MASK = self::SINGLE_SCRIPT_CONFUSABLE
        | self::MIXED_SCRIPT_CONFUSABLE
        | self::WHOLE_SCRIPT_CONFUSABLE;

    /** @see URestrictionLevel */
    public const ASCII = 0x10000000;
    public const SINGLE_SCRIPT_RESTRICTIVE = 0x20000000;
    public const HIGHLY_RESTRICTIVE = 0x30000000;
    public const MODERATELY_RESTRICTIVE = 0x40000000;
    public const MINIMALLY_RESTRICTIVE = 0x50000000;
    public const UNRESTRICTIVE = 0x60000000;

    /** Default after construct on ICU ≥58 (php-src spoofchecker_create.c). */
    public const DEFAULT_RESTRICTION_LEVEL = self::HIGHLY_RESTRICTIVE;

    /** @see unicode/uset.h USET_* — Spoofchecker::setAllowedChars patternOptions (#20823). */
    public const IGNORE_SPACE = 1;
    public const CASE_INSENSITIVE = 2;
    public const ADD_CASE_MAPPINGS = 4;
    /** ICU ≥73 only (php-src stub gated on U_ICU_VERSION_MAJOR_NUM >= 73). */
    public const SIMPLE_CASE_INSENSITIVE = 6;

    /** @var array<int, array{handle: object|null, checks?: int, allowed_pattern?: string, allowed_options?: int}> */
    private static array $state = [];

    private static ?\FFI $ffi = null;

    private static string $symSuffix = '';

    private static bool $ffiUnavailable = false;

    /** @return array<string, int> */
    public static function classConstants(): array
    {
        // Do not probe ICU/FFI here — Spoofchecker::class during JIT parse would
        // load libicu before WeakRef NestedJIT helpers and abort (#20823).
        $consts = [
            'SINGLE_SCRIPT_CONFUSABLE' => self::SINGLE_SCRIPT_CONFUSABLE,
            'MIXED_SCRIPT_CONFUSABLE' => self::MIXED_SCRIPT_CONFUSABLE,
            'WHOLE_SCRIPT_CONFUSABLE' => self::WHOLE_SCRIPT_CONFUSABLE,
            'ANY_CASE' => self::ANY_CASE,
            'SINGLE_SCRIPT' => self::SINGLE_SCRIPT,
            'RESTRICTION_LEVEL' => self::RESTRICTION_LEVEL,
            'INVISIBLE' => self::INVISIBLE,
            'CHAR_LIMIT' => self::CHAR_LIMIT,
            'MIXED_NUMBERS' => self::MIXED_NUMBERS,
            'HIDDEN_OVERLAY' => self::HIDDEN_OVERLAY,
            'ALL_CHECKS' => self::ALL_CHECKS,
            'AUX_INFO' => self::AUX_INFO,
            'ASCII' => self::ASCII,
            'SINGLE_SCRIPT_RESTRICTIVE' => self::SINGLE_SCRIPT_RESTRICTIVE,
            'HIGHLY_RESTRICTIVE' => self::HIGHLY_RESTRICTIVE,
            'MODERATELY_RESTRICTIVE' => self::MODERATELY_RESTRICTIVE,
            'MINIMALLY_RESTRICTIVE' => self::MINIMALLY_RESTRICTIVE,
            'UNRESTRICTIVE' => self::UNRESTRICTIVE,
        ];
        // USET pattern-option consts ship with setAllowedChars (PHP 8.4+; #23157).
        if (CompilerVersion::supportsSpoofcheckerSetAllowedChars()) {
            $consts['IGNORE_SPACE'] = self::IGNORE_SPACE;
            $consts['CASE_INSENSITIVE'] = self::CASE_INSENSITIVE;
            $consts['ADD_CASE_MAPPINGS'] = self::ADD_CASE_MAPPINGS;
        }

        return $consts;
    }

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC])) {
            return;
        }

        $entry = new ClassEntry('Spoofchecker');
        $entry->isInternal = true;
        foreach (self::classConstants() as $name => $value) {
            $lc = strtolower($name);
            $const = new Variable(Variable::TYPE_INTEGER);
            $const->int($value);
            $entry->constants[$lc] = $const;
            $entry->constNames[$lc] = $name;
        }
        $pub = CfgFunc::FLAG_PUBLIC;
        $entry->constructor = new SpoofcheckerConstruct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $pub;
        $methods = [
            'issuspicious' => [new SpoofcheckerIsSuspicious(), 'isSuspicious'],
            'areconfusable' => [new SpoofcheckerAreConfusable(), 'areConfusable'],
            'setallowedlocales' => [new SpoofcheckerSetAllowedLocales(), 'setAllowedLocales'],
            'setchecks' => [new SpoofcheckerSetChecks(), 'setChecks'],
            'setrestrictionlevel' => [new SpoofcheckerSetRestrictionLevel(), 'setRestrictionLevel'],
        ];
        // PHP 8.4+ only — Zend 8.2/8.3 method_exists false (#23157, re-#20823).
        if (CompilerVersion::supportsSpoofcheckerSetAllowedChars()) {
            $methods['setallowedchars'] = [new SpoofcheckerSetAllowedChars(), 'setAllowedChars'];
        }
        foreach ($methods as $lc => [$handler, $name]) {
            $entry->methods[$lc] = $handler;
            $entry->methodVisibility[$lc] = $pub;
            $entry->methodNames[$lc] = $name;
        }
        $ctx->classes[self::CLASS_LC] = $entry;
    }

    /** Advertise SIMPLE_CASE_INSENSITIVE once ICU ≥73 is confirmed (php-src stub gate). */
    private static function maybeAdvertiseSimpleCaseInsensitive(ObjectEntry $object): void
    {
        // SIMPLE_CASE_INSENSITIVE ships with setAllowedChars (PHP 8.4+; #23157).
        if (!CompilerVersion::supportsSpoofcheckerSetAllowedChars()) {
            return;
        }
        if (self::icuMajorVersion() < 73) {
            return;
        }
        $entry = $object->class;
        $lc = 'simple_case_insensitive';
        if (isset($entry->constants[$lc])) {
            return;
        }
        $const = new Variable(Variable::TYPE_INTEGER);
        $const->int(self::SIMPLE_CASE_INSENSITIVE);
        $entry->constants[$lc] = $const;
        $entry->constNames[$lc] = 'SIMPLE_CASE_INSENSITIVE';
    }

    public static function isSpoofcheckerObject(?ObjectEntry $object): bool
    {
        return null !== $object && self::CLASS_LC === strtolower($object->class->name);
    }

    public static function construct(ObjectEntry $object): void
    {
        $handle = self::openSpoof();
        self::$state[$object->id] = [
            'handle' => $handle,
            'checks' => self::ALL_CHECKS,
        ];
        $object->constructed = true;
        self::maybeAdvertiseSimpleCaseInsensitive($object);
        if (null === $handle) {
            IntlError::set(
                IntlError::U_USING_FALLBACK_WARNING,
                'Spoofchecker::__construct(): ICU uspoof unavailable; using script-mix fallback'
            );
        } elseif (IntlError::U_ZERO_ERROR === IntlError::getCode()) {
            IntlError::clear();
        }
    }

    /**
     * @return array{0: bool, 1: int} suspicious flag + check bitmask (php-src isSuspicious)
     */
    public static function isSuspicious(ObjectEntry $object, string $string): array
    {
        $state = self::$state[$object->id] ?? null;
        if (null === $state) {
            throw new \Error('Spoofchecker::isSuspicious() called on uninitialized Spoofchecker');
        }
        $handle = $state['handle'];
        if (null !== $handle) {
            $ffi = self::ffi();
            if (null !== $ffi) {
                $fn = 'uspoof_checkUTF8'.self::$symSuffix;
                try {
                    $status = $ffi->new('UErrorCode');
                    $status->cdata = 0;
                    $ret = (int) $ffi->$fn($handle, $string, \strlen($string), null, \FFI::addr($status));
                    $code = (int) $status->cdata;
                    if ($code > 0) {
                        IntlError::set($code, 'Spoofchecker::isSuspicious(): U_FAILURE');

                        return [true, $ret];
                    }
                    IntlError::clear();

                    return [$ret !== 0, $ret];
                } catch (\Throwable) {
                    // fall through
                }
            }
        }

        $ret = self::fallbackCheckBits($string);
        $state = self::$state[$object->id] ?? null;
        if (null !== $state && isset($state['allowed_pattern'])
            && self::fallbackOutsideAllowedSet($string, $state['allowed_pattern'])) {
            $ret |= self::CHAR_LIMIT;
        }
        IntlError::clear();

        return [$ret !== 0, $ret];
    }

    /**
     * @return array{0: bool, 1: int}
     */
    public static function areConfusable(ObjectEntry $object, string $string1, string $string2): array
    {
        $state = self::$state[$object->id] ?? null;
        if (null === $state) {
            throw new \Error('Spoofchecker::areConfusable() called on uninitialized Spoofchecker');
        }
        $handle = $state['handle'];
        if (null !== $handle) {
            $ffi = self::ffi();
            if (null !== $ffi) {
                $fn = 'uspoof_areConfusableUTF8'.self::$symSuffix;
                try {
                    $status = $ffi->new('UErrorCode');
                    $status->cdata = 0;
                    $ret = (int) $ffi->$fn(
                        $handle,
                        $string1,
                        \strlen($string1),
                        $string2,
                        \strlen($string2),
                        \FFI::addr($status)
                    );
                    $code = (int) $status->cdata;
                    if ($code > 0) {
                        // php-src spoofchecker_main.c / intl_error — leave code for caller to warn (#25209).
                        IntlError::set(
                            $code,
                            \sprintf(
                                'Spoofchecker::areConfusable(): (%d) %s',
                                $code,
                                IntlError::errorName($code)
                            )
                        );

                        return [true, $ret];
                    }
                    IntlError::clear();

                    return [$ret !== 0, $ret];
                } catch (\Throwable) {
                    // fall through
                }
            }
        }

        $checks = (int) ($state['checks'] ?? self::ALL_CHECKS);
        if (0 === ($checks & self::CONFUSABLE_CHECK_MASK)) {
            IntlError::set(
                self::U_INVALID_STATE_ERROR,
                \sprintf(
                    'Spoofchecker::areConfusable(): (%d) %s',
                    self::U_INVALID_STATE_ERROR,
                    IntlError::errorName(self::U_INVALID_STATE_ERROR)
                )
            );
            $ret = self::fallbackConfusableBits($string1, $string2);

            return [$ret !== 0, $ret];
        }

        $ret = self::fallbackConfusableBits($string1, $string2);
        IntlError::clear();

        return [$ret !== 0, $ret];
    }

    public static function setAllowedLocales(ObjectEntry $object, string $locales): void
    {
        $state = self::$state[$object->id] ?? null;
        if (null === $state) {
            throw new \Error('Spoofchecker::setAllowedLocales() called on uninitialized Spoofchecker');
        }
        $handle = $state['handle'];
        if (null === $handle) {
            return;
        }
        $ffi = self::ffi();
        if (null === $ffi) {
            return;
        }
        $fn = 'uspoof_setAllowedLocales'.self::$symSuffix;
        try {
            $status = $ffi->new('UErrorCode');
            $status->cdata = 0;
            $ffi->$fn($handle, $locales, \FFI::addr($status));
            if ((int) $status->cdata > 0) {
                IntlError::set((int) $status->cdata, 'Spoofchecker::setAllowedLocales(): U_FAILURE');
            } else {
                IntlError::clear();
            }
        } catch (\Throwable) {
            // ignore when symbol missing
        }
    }

    public static function setChecks(ObjectEntry $object, int $checks): void
    {
        $state = self::$state[$object->id] ?? null;
        if (null === $state) {
            throw new \Error('Spoofchecker::setChecks() called on uninitialized Spoofchecker');
        }
        self::$state[$object->id]['checks'] = $checks;
        $handle = $state['handle'];
        if (null === $handle) {
            return;
        }
        $ffi = self::ffi();
        if (null === $ffi) {
            return;
        }
        $fn = 'uspoof_setChecks'.self::$symSuffix;
        try {
            $status = $ffi->new('UErrorCode');
            $status->cdata = 0;
            $ffi->$fn($handle, $checks, \FFI::addr($status));
            if ((int) $status->cdata > 0) {
                IntlError::set((int) $status->cdata, 'Spoofchecker::setChecks(): U_FAILURE');
            } else {
                IntlError::clear();
            }
        } catch (\Throwable) {
            // ignore
        }
    }

    public static function setRestrictionLevel(ObjectEntry $object, int $level): void
    {
        if (!self::isValidRestrictionLevel($level)) {
            throw new \ValueError(
                'Spoofchecker::setRestrictionLevel(): Argument #1 ($level) must be one of Spoofchecker::ASCII, '
                .'Spoofchecker::SINGLE_SCRIPT_RESTRICTIVE, Spoofchecker::HIGHLY_RESTRICTIVE, '
                .'Spoofchecker::MODERATELY_RESTRICTIVE, Spoofchecker::MINIMALLY_RESTRICTIVE, or Spoofchecker::UNRESTRICTIVE'
            );
        }
        $state = self::$state[$object->id] ?? null;
        if (null === $state) {
            throw new \Error('Spoofchecker::setRestrictionLevel() called on uninitialized Spoofchecker');
        }
        $handle = $state['handle'];
        if (null === $handle) {
            return;
        }
        $ffi = self::ffi();
        if (null === $ffi) {
            return;
        }
        $fn = 'uspoof_setRestrictionLevel'.self::$symSuffix;
        try {
            $ffi->$fn($handle, $level);
            IntlError::clear();
        } catch (\Throwable) {
            // ignore
        }
    }

    /**
     * Spoofchecker::setAllowedChars() — php-src spoofchecker_main.cpp (#20823).
     */
    public static function setAllowedChars(ObjectEntry $object, string $pattern, int $patternOptions = 0): void
    {
        if (\strlen($pattern) > 2147483647) {
            throw new \ValueError(
                'Spoofchecker::setAllowedChars(): Argument #1 ($pattern) must be less than or equal to 2147483647 bytes long'
            );
        }
        // uset_applyPattern requires a regex-range character class.
        if ('' === $pattern || '[' !== $pattern[0] || ']' !== $pattern[\strlen($pattern) - 1]) {
            throw new \ValueError(
                'Spoofchecker::setAllowedChars(): Argument #1 ($pattern) must be a valid regular expression character set pattern'
            );
        }

        $state = self::$state[$object->id] ?? null;
        if (null === $state) {
            throw new \Error('Spoofchecker::setAllowedChars() called on uninitialized Spoofchecker');
        }

        $utf16 = self::utf8ToUtf16Le($pattern);
        if (null === $utf16) {
            throw new \ValueError(
                'Spoofchecker::setAllowedChars(): Argument #1 ($pattern) string conversion to unicode encoding failed'
            );
        }

        if (!self::isAllowedCharsPatternOption($patternOptions)) {
            throw new \ValueError(
                'Spoofchecker::setAllowedChars(): Argument #2 ($patternOptions) '
                .self::allowedCharsPatternOptionErrorMessage()
            );
        }

        self::$state[$object->id]['allowed_pattern'] = $pattern;
        self::$state[$object->id]['allowed_options'] = $patternOptions;

        $handle = $state['handle'];
        if (null === $handle) {
            return;
        }
        $ffi = self::ffi();
        if (null === $ffi) {
            return;
        }

        $openEmpty = 'uset_openEmpty'.self::$symSuffix;
        $applyPattern = 'uset_applyPattern'.self::$symSuffix;
        $compact = 'uset_compact'.self::$symSuffix;
        $close = 'uset_close'.self::$symSuffix;
        $setAllowed = 'uspoof_setAllowedChars'.self::$symSuffix;

        try {
            $ulen = \intdiv(\strlen($utf16), 2);
            $chars = $ffi->new('uint16_t['.max(1, $ulen).']');
            if ($ulen > 0) {
                \FFI::memcpy($chars, $utf16, \strlen($utf16));
            }

            $set = $ffi->$openEmpty();
            if (null === $set) {
                return;
            }

            $status = $ffi->new('UErrorCode');
            $status->cdata = 0;
            $ffi->$applyPattern($set, $chars, $ulen, $patternOptions & 0xFFFFFFFF, \FFI::addr($status));
            if ((int) $status->cdata > 0) {
                $code = (int) $status->cdata;
                $ffi->$close($set);
                throw new \ValueError(\sprintf(
                    'Spoofchecker::setAllowedChars(): Argument #1 ($pattern) must be a valid regular expression character set pattern (%d)',
                    $code
                ));
            }

            try {
                $ffi->$compact($set);
            } catch (\Throwable) {
                // uset_compact optional
            }

            $status->cdata = 0;
            $ffi->$setAllowed($handle, $set, \FFI::addr($status));
            $ffi->$close($set);

            if ((int) $status->cdata > 0) {
                IntlError::set((int) $status->cdata, 'Spoofchecker::setAllowedChars(): U_FAILURE');
            } else {
                IntlError::clear();
            }
        } catch (\ValueError $e) {
            throw $e;
        } catch (\Throwable) {
            // Symbol missing — keep PHP-side allowed_pattern for fallback checks.
        }
    }

    public static function coerceStringArg(Variable $var, string $function, int $position, string $name): string
    {
        return VmString::coerceStringBuiltinArg($var, $function, $position, $name);
    }

    public static function coerceIntArg(Variable $var, string $function, int $position, string $name): int
    {
        $var = $var->resolveIndirect();
        if (EnumCaseSupport::isEnumCaseVariable($var)) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($%s) must be of type int, %s given',
                $function,
                $position + 1,
                $name,
                EnumCaseSupport::typeNameForVariable($var)
            ));
        }
        if (Variable::TYPE_INTEGER === $var->type) {
            return $var->toInt();
        }
        if (Variable::TYPE_BOOLEAN === $var->type) {
            return $var->toBool() ? 1 : 0;
        }
        if (Variable::TYPE_FLOAT === $var->type) {
            return (int) $var->toFloat();
        }
        if (Variable::TYPE_STRING === $var->type && is_numeric($var->toString())) {
            return (int) $var->toString();
        }
        throw new \TypeError(\sprintf(
            '%s(): Argument #%d ($%s) must be of type int, %s given',
            $function,
            $position + 1,
            $name,
            ReflectionSupport::valueTypeLabelPublic($var)
        ));
    }

    public static function requireReceiver(Variable $var, string $label): ObjectEntry
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $var->type || !self::isSpoofcheckerObject($var->toObject())) {
            throw new \Error($label.' called on incompatible object');
        }
        $object = $var->toObject();
        if (!$object->constructed) {
            throw new \Error($label.' called on uninitialized Spoofchecker');
        }

        return $object;
    }

    private static function isValidRestrictionLevel(int $level): bool
    {
        return self::ASCII === $level
            || self::SINGLE_SCRIPT_RESTRICTIVE === $level
            || self::HIGHLY_RESTRICTIVE === $level
            || self::MODERATELY_RESTRICTIVE === $level
            || self::MINIMALLY_RESTRICTIVE === $level
            || self::UNRESTRICTIVE === $level;
    }

    /** php-src intl_icu_compat_uspoof_is_allowed_chars_pattern_option. */
    private static function isAllowedCharsPatternOption(int $patternOption): bool
    {
        if (0 === $patternOption || self::IGNORE_SPACE === $patternOption) {
            return true;
        }
        if ((self::IGNORE_SPACE | self::CASE_INSENSITIVE) === $patternOption) {
            return true;
        }
        if ((self::IGNORE_SPACE | self::ADD_CASE_MAPPINGS) === $patternOption) {
            return true;
        }
        if (self::icuMajorVersion() >= 73
            && (self::IGNORE_SPACE | self::SIMPLE_CASE_INSENSITIVE) === $patternOption) {
            return true;
        }

        return false;
    }

    /** php-src intl_icu_compat_uspoof_allowed_chars_pattern_option_error_message. */
    private static function allowedCharsPatternOptionErrorMessage(): string
    {
        if (self::icuMajorVersion() >= 73) {
            return 'must be a valid pattern option, 0 or (SpoofChecker::IGNORE_SPACE|(<none> or SpoofChecker::CASE_INSENSITIVE or SpoofChecker::ADD_CASE_MAPPINGS or SpoofChecker::SIMPLE_CASE_INSENSITIVE))';
        }

        return 'must be a valid pattern option, 0 or (SpoofChecker::IGNORE_SPACE|(<none> or SpoofChecker::CASE_INSENSITIVE or SpoofChecker::ADD_CASE_MAPPINGS))';
    }

    private static function icuMajorVersion(): int
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            // No ICU — omit SIMPLE_CASE_INSENSITIVE (php-src gates on ICU ≥73).
            return 70;
        }
        if ('' === self::$symSuffix) {
            // Unversioned dylib — assume modern ICU with SIMPLE_CASE_INSENSITIVE.
            return 74;
        }
        if (preg_match('/_(\d+)$/', self::$symSuffix, $m)) {
            return (int) $m[1];
        }

        return 70;
    }

    private static function utf8ToUtf16Le(string $utf8): ?string
    {
        if (!\function_exists('mb_convert_encoding')) {
            return null;
        }
        $out = @\mb_convert_encoding($utf8, 'UTF-16LE', 'UTF-8');

        return false === $out ? null : $out;
    }

    /** Best-effort CHAR_LIMIT when ICU FFI is absent. */
    private static function fallbackOutsideAllowedSet(string $string, string $pattern): bool
    {
        $len = \strlen($string);
        $i = 0;
        while ($i < $len) {
            $cp = self::utf8CodepointAt($string, $i, $i);
            if (null === $cp) {
                break;
            }
            $ch = self::utf8EncodeCodepoint($cp);
            // UnicodeSet patterns for simple ranges resemble PCRE character classes.
            $ok = @\preg_match('/^'.$pattern.'$/u', $ch);
            if (1 !== $ok) {
                return true;
            }
        }

        return false;
    }

    private static function utf8EncodeCodepoint(int $cp): string
    {
        if ($cp < 0x80) {
            return \chr($cp);
        }
        if ($cp < 0x800) {
            return \chr(0xC0 | ($cp >> 6)).\chr(0x80 | ($cp & 0x3F));
        }
        if ($cp < 0x10000) {
            return \chr(0xE0 | ($cp >> 12))
                .\chr(0x80 | (($cp >> 6) & 0x3F))
                .\chr(0x80 | ($cp & 0x3F));
        }

        return \chr(0xF0 | ($cp >> 18))
            .\chr(0x80 | (($cp >> 12) & 0x3F))
            .\chr(0x80 | (($cp >> 6) & 0x3F))
            .\chr(0x80 | ($cp & 0x3F));
    }

    /** @return object|null FFI CData USpoofChecker* */
    private static function openSpoof(): ?object
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return null;
        }
        $open = 'uspoof_open'.self::$symSuffix;
        $setLevel = 'uspoof_setRestrictionLevel'.self::$symSuffix;
        $close = 'uspoof_close'.self::$symSuffix;
        try {
            $status = $ffi->new('UErrorCode');
            $status->cdata = 0;
            $sc = $ffi->$open(\FFI::addr($status));
            $code = (int) $status->cdata;
            if (null === $sc || $code > 0) {
                if (null !== $sc) {
                    $ffi->$close($sc);
                }

                return null;
            }
            try {
                $ffi->$setLevel($sc, self::DEFAULT_RESTRICTION_LEVEL);
            } catch (\Throwable) {
                // ICU <58 lacks restriction levels; leave defaults
            }

            return $sc;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Fallback when ICU FFI is absent: flag mixed Unicode scripts (USPOOF_SINGLE_SCRIPT bit).
     */
    private static function fallbackCheckBits(string $string): int
    {
        $scripts = [];
        $len = \strlen($string);
        $i = 0;
        while ($i < $len) {
            $cp = self::utf8CodepointAt($string, $i, $i);
            if (null === $cp) {
                break;
            }
            if (self::isIgnorableCodepoint($cp)) {
                continue;
            }
            $script = self::scriptOf($cp);
            if (null !== $script) {
                $scripts[$script] = true;
            }
        }

        return \count($scripts) > 1 ? self::SINGLE_SCRIPT : 0;
    }

    private static function fallbackConfusableBits(string $a, string $b): int
    {
        if ($a === $b) {
            return 0;
        }
        // Homoglyph-ish: same ASCII skeleton after stripping non-ASCII lookalikes is weak;
        // treat distinct UTF-8 with equal NFKC-ish length + shared Latin letters as mixed-script confusable.
        if (self::fallbackCheckBits($a) !== 0 || self::fallbackCheckBits($b) !== 0) {
            return self::MIXED_SCRIPT_CONFUSABLE;
        }

        return 0;
    }

    private static function isIgnorableCodepoint(int $cp): bool
    {
        return $cp <= 0x20
            || (0x30 <= $cp && $cp <= 0x39)
            || 0x2E === $cp
            || 0x2D === $cp
            || 0x5F === $cp;
    }

    private static function scriptOf(int $cp): ?string
    {
        if (($cp >= 0x41 && $cp <= 0x5A) || ($cp >= 0x61 && $cp <= 0x7A)) {
            return 'Latn';
        }
        if ($cp >= 0x0400 && $cp <= 0x04FF) {
            return 'Cyrl';
        }
        if ($cp >= 0x0370 && $cp <= 0x03FF) {
            return 'Grek';
        }
        if ($cp >= 0x0600 && $cp <= 0x06FF) {
            return 'Arab';
        }
        if ($cp >= 0x3040 && $cp <= 0x30FF) {
            return 'Jpan';
        }
        if ($cp >= 0x4E00 && $cp <= 0x9FFF) {
            return 'Hans';
        }
        if ($cp >= 0xAC00 && $cp <= 0xD7AF) {
            return 'Hang';
        }
        if ($cp >= 0x0590 && $cp <= 0x05FF) {
            return 'Hebr';
        }

        return null;
    }

    /** @param-out int $next */
    private static function utf8CodepointAt(string $s, int $i, int &$next): ?int
    {
        $len = \strlen($s);
        if ($i >= $len) {
            $next = $i;

            return null;
        }
        $b0 = \ord($s[$i]);
        if ($b0 < 0x80) {
            $next = $i + 1;

            return $b0;
        }
        if ($b0 < 0xC2 || $b0 > 0xF4 || $i + 1 >= $len) {
            $next = $i + 1;

            return 0xFFFD;
        }
        if ($b0 < 0xE0) {
            $b1 = \ord($s[$i + 1]);
            $next = $i + 2;

            return (($b0 & 0x1F) << 6) | ($b1 & 0x3F);
        }
        if ($b0 < 0xF0) {
            if ($i + 2 >= $len) {
                $next = $len;

                return 0xFFFD;
            }
            $next = $i + 3;

            return (($b0 & 0x0F) << 12) | ((\ord($s[$i + 1]) & 0x3F) << 6) | (\ord($s[$i + 2]) & 0x3F);
        }
        if ($i + 3 >= $len) {
            $next = $len;

            return 0xFFFD;
        }
        $next = $i + 4;

        return (($b0 & 0x07) << 18)
            | ((\ord($s[$i + 1]) & 0x3F) << 12)
            | ((\ord($s[$i + 2]) & 0x3F) << 6)
            | (\ord($s[$i + 3]) & 0x3F);
    }

    private static function ffi(): ?\FFI
    {
        if (self::$ffiUnavailable) {
            return null;
        }
        if (null !== self::$ffi) {
            return self::$ffi;
        }
        if (!\class_exists(\FFI::class, false) && !\extension_loaded('FFI')) {
            self::$ffiUnavailable = true;

            return null;
        }

        /** @var list<array{0: string, 1: string}> */
        $candidates = [
            ['libicui18n.so.70', '_70'],
            ['libicui18n.so.74', '_74'],
            ['libicui18n.so.72', '_72'],
            ['libicui18n.so.71', '_71'],
            ['libicui18n.so', '_70'],
            ['libicui18n.dylib', ''],
        ];
        foreach ($candidates as [$lib, $suffix]) {
            try {
                self::$ffi = \FFI::cdef(self::cdefForSuffix($suffix), $lib);
                self::$symSuffix = $suffix;

                return self::$ffi;
            } catch (\Throwable) {
                self::$ffi = null;
            }
        }
        self::$ffiUnavailable = true;

        return null;
    }

    private static function cdefForSuffix(string $suffix): string
    {
        return <<<C
typedef int32_t UErrorCode;
typedef uint16_t UChar;
typedef struct USpoofChecker USpoofChecker;
typedef struct USet USet;
typedef int32_t URestrictionLevel;
USpoofChecker *uspoof_open{$suffix}(UErrorCode *status);
void uspoof_close{$suffix}(USpoofChecker *sc);
int32_t uspoof_checkUTF8{$suffix}(const USpoofChecker *sc, const char *id, int32_t length, int32_t *position, UErrorCode *status);
int32_t uspoof_areConfusableUTF8{$suffix}(const USpoofChecker *sc, const char *id1, int32_t length1, const char *id2, int32_t length2, UErrorCode *status);
void uspoof_setChecks{$suffix}(USpoofChecker *sc, int32_t checks, UErrorCode *status);
void uspoof_setAllowedLocales{$suffix}(USpoofChecker *sc, const char *localesList, UErrorCode *status);
void uspoof_setRestrictionLevel{$suffix}(USpoofChecker *sc, URestrictionLevel restrictionLevel);
void uspoof_setAllowedChars{$suffix}(USpoofChecker *sc, const USet *chars, UErrorCode *status);
USet *uset_openEmpty{$suffix}(void);
void uset_close{$suffix}(USet *set);
void uset_compact{$suffix}(USet *set);
int32_t uset_applyPattern{$suffix}(USet *set, const UChar *pattern, int32_t patternLength, uint32_t options, UErrorCode *status);
C;
    }
}

/** Spoofchecker::__construct() — php-src spoofchecker_create.c (#20035). */
final class SpoofcheckerConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1) {
            throw new \LogicException('Spoofchecker::__construct() called without $this');
        }
        if ($argc > 1) {
            throw new \ArgumentCountError(\sprintf(
                'Spoofchecker::__construct() expects exactly 0 arguments, %d given',
                $argc - 1
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type) {
            throw new \TypeError('Spoofchecker::__construct() must be called on Spoofchecker');
        }
        VmSpoofchecker::construct($receiver->toObject());
    }
}

/** Spoofchecker::isSuspicious() — php-src spoofchecker_main.c (#20035). */
final class SpoofcheckerIsSuspicious extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isSuspicious');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2) {
            throw new \ArgumentCountError(\sprintf(
                'Spoofchecker::isSuspicious() expects at least 1 argument, %d given',
                max(0, $argc - 1)
            ));
        }
        if ($argc > 3) {
            throw new \ArgumentCountError(\sprintf(
                'Spoofchecker::isSuspicious() expects at most 2 arguments, %d given',
                $argc - 1
            ));
        }
        $object = VmSpoofchecker::requireReceiver($frame->calledArgs[0], 'Spoofchecker::isSuspicious()');
        $string = VmSpoofchecker::coerceStringArg($frame->calledArgs[1], 'Spoofchecker::isSuspicious', 0, 'string');
        [$suspicious, $bits] = VmSpoofchecker::isSuspicious($object, $string);
        if ($argc >= 3) {
            // ZEND_SEND_REF writeback — php-src spoofchecker_main.c (#25055)
            $frame->calledArgs[2]->byRefTarget()->int($bits);
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($suspicious);
        }
    }
}

/** Spoofchecker::areConfusable() — php-src spoofchecker_main.c (#20035). */
final class SpoofcheckerAreConfusable extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('areConfusable');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 3) {
            throw new \ArgumentCountError(\sprintf(
                'Spoofchecker::areConfusable() expects at least 2 arguments, %d given',
                max(0, $argc - 1)
            ));
        }
        if ($argc > 4) {
            throw new \ArgumentCountError(\sprintf(
                'Spoofchecker::areConfusable() expects at most 3 arguments, %d given',
                $argc - 1
            ));
        }
        $object = VmSpoofchecker::requireReceiver($frame->calledArgs[0], 'Spoofchecker::areConfusable()');
        $s1 = VmSpoofchecker::coerceStringArg($frame->calledArgs[1], 'Spoofchecker::areConfusable', 0, 'string1');
        $s2 = VmSpoofchecker::coerceStringArg($frame->calledArgs[2], 'Spoofchecker::areConfusable', 1, 'string2');
        [$confusable, $bits] = VmSpoofchecker::areConfusable($object, $s1, $s2);
        // php-src spoofchecker_main.c — U_FAILURE → E_WARNING "(code) U_*"; intl slot cleared (#25209).
        $errCode = IntlError::getCode();
        if ($errCode > 0) {
            $frame->vmContext->errors->languageWarning(
                \sprintf(
                    'Spoofchecker::areConfusable(): (%d) %s',
                    $errCode,
                    IntlError::errorName($errCode)
                ),
                null,
                0,
                $frame->vmContext,
                $frame
            );
            IntlError::clear();
        }
        if ($argc >= 4) {
            // ZEND_SEND_REF writeback — php-src spoofchecker_main.c (#25055)
            $frame->calledArgs[3]->byRefTarget()->int($bits);
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($confusable);
        }
    }
}

/** Spoofchecker::setAllowedLocales() — php-src spoofchecker_main.c (#20035). */
final class SpoofcheckerSetAllowedLocales extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('setAllowedLocales');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'Spoofchecker::setAllowedLocales() expects exactly 1 argument, %d given',
                max(0, $argc - 1)
            ));
        }
        $object = VmSpoofchecker::requireReceiver($frame->calledArgs[0], 'Spoofchecker::setAllowedLocales()');
        $locales = VmSpoofchecker::coerceStringArg($frame->calledArgs[1], 'Spoofchecker::setAllowedLocales', 0, 'locales');
        VmSpoofchecker::setAllowedLocales($object, $locales);
    }
}

/** Spoofchecker::setChecks() — php-src spoofchecker_main.c (#20035). */
final class SpoofcheckerSetChecks extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('setChecks');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'Spoofchecker::setChecks() expects exactly 1 argument, %d given',
                max(0, $argc - 1)
            ));
        }
        $object = VmSpoofchecker::requireReceiver($frame->calledArgs[0], 'Spoofchecker::setChecks()');
        $checks = VmSpoofchecker::coerceIntArg($frame->calledArgs[1], 'Spoofchecker::setChecks', 0, 'checks');
        VmSpoofchecker::setChecks($object, $checks);
    }
}

/** Spoofchecker::setRestrictionLevel() — php-src spoofchecker_main.c (#20035). */
final class SpoofcheckerSetRestrictionLevel extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('setRestrictionLevel');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'Spoofchecker::setRestrictionLevel() expects exactly 1 argument, %d given',
                max(0, $argc - 1)
            ));
        }
        $object = VmSpoofchecker::requireReceiver($frame->calledArgs[0], 'Spoofchecker::setRestrictionLevel()');
        $level = VmSpoofchecker::coerceIntArg($frame->calledArgs[1], 'Spoofchecker::setRestrictionLevel', 0, 'level');
        VmSpoofchecker::setRestrictionLevel($object, $level);
    }
}

/** Spoofchecker::setAllowedChars() — php-src spoofchecker_main.cpp (#20823). */
final class SpoofcheckerSetAllowedChars extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('setAllowedChars');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        $userArgc = max(0, $argc - 1);
        if ($userArgc < 1) {
            throw new \ArgumentCountError(\sprintf(
                'Spoofchecker::setAllowedChars() expects at least 1 argument, %d given',
                $userArgc
            ));
        }
        if ($userArgc > 2) {
            throw new \ArgumentCountError(\sprintf(
                'Spoofchecker::setAllowedChars() expects at most 2 arguments, %d given',
                $userArgc
            ));
        }
        $object = VmSpoofchecker::requireReceiver($frame->calledArgs[0], 'Spoofchecker::setAllowedChars()');
        $pattern = VmSpoofchecker::coerceStringArg($frame->calledArgs[1], 'Spoofchecker::setAllowedChars', 0, 'pattern');
        $options = 0;
        if ($userArgc >= 2) {
            $options = VmSpoofchecker::coerceIntArg($frame->calledArgs[2], 'Spoofchecker::setAllowedChars', 1, 'patternOptions');
        }
        VmSpoofchecker::setAllowedChars($object, $pattern, $options);
    }
}
