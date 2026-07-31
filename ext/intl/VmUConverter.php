<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCfg\Func as CfgFunc;
use PHPCompiler\ext\iconv\CharsetEngine;
use PHPCompiler\ext\iconv\VmIconv;
use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\PHP as PhpFunc;
use PHPCompiler\ReflectionSupport;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * UConverter construct/convert — charset conversion OOP API (php-src ext/intl/converter; #6171 / #20770 / #20788).
 *
 * Conversion uses {@see CharsetEngine} / {@see VmIconv} (PHP-in-PHP). Catalog / type APIs use thin
 * ICU `ucnv_*` FFI when libicuuc is available, with a static fallback list otherwise.
 */
final class VmUConverter
{
    public const CLASS_LC = 'uconverter';

    /** ICU U_FILE_ACCESS_ERROR — unknown converter name on construct. */
    public const U_FILE_ACCESS_ERROR = 4;

    /** ICU U_INVALID_STATE_ERROR — convert after failed open. */
    public const U_INVALID_STATE_ERROR = 27;

    /** ICU U_INVALID_CHAR_FOUND — illegal input sequence. */
    public const U_INVALID_CHAR_FOUND = 10;

    /** @see unicode/ucnv_err.h UCNV_UNASSIGNED … UCNV_CLONE (php-src UConverter::REASON_*). */
    public const REASON_UNASSIGNED = 0;
    public const REASON_ILLEGAL = 1;
    public const REASON_IRREGULAR = 2;
    public const REASON_RESET = 3;
    public const REASON_CLOSE = 4;
    public const REASON_CLONE = 5;

    /** @see unicode/ucnv.h UConverterType (php-src UConverter::* type constants). */
    public const UNSUPPORTED_CONVERTER = -1;
    public const SBCS = 0;
    public const DBCS = 1;
    public const MBCS = 2;
    public const LATIN_1 = 3;
    public const UTF8 = 4;
    public const UTF16_BigEndian = 5;
    public const UTF16_LittleEndian = 6;
    public const UTF32_BigEndian = 7;
    public const UTF32_LittleEndian = 8;
    public const US_ASCII = 26;

    /**
     * @var array<int, array{
     *     dest: string,
     *     src: string,
     *     destOk: bool,
     *     srcOk: bool,
     *     substChars: string,
     *     substCharsExplicit: bool,
     *     errorCode: int,
     *     errorMessage: string,
     *     openOk: bool
     * }>
     */
    private static array $state = [];

    private static ?\FFI $ffi = null;

    private static bool $ffiUnavailable = false;

    private static string $symSuffix = '';

    /** @return array<string, int> */
    public static function classConstants(): array
    {
        return [
            'REASON_UNASSIGNED' => self::REASON_UNASSIGNED,
            'REASON_ILLEGAL' => self::REASON_ILLEGAL,
            'REASON_IRREGULAR' => self::REASON_IRREGULAR,
            'REASON_RESET' => self::REASON_RESET,
            'REASON_CLOSE' => self::REASON_CLOSE,
            'REASON_CLONE' => self::REASON_CLONE,
            'UNSUPPORTED_CONVERTER' => self::UNSUPPORTED_CONVERTER,
            'SBCS' => self::SBCS,
            'DBCS' => self::DBCS,
            'MBCS' => self::MBCS,
            'LATIN_1' => self::LATIN_1,
            'UTF8' => self::UTF8,
            'UTF16_BigEndian' => self::UTF16_BigEndian,
            'UTF16_LittleEndian' => self::UTF16_LittleEndian,
            'UTF32_BigEndian' => self::UTF32_BigEndian,
            'UTF32_LittleEndian' => self::UTF32_LittleEndian,
            'US_ASCII' => self::US_ASCII,
        ];
    }

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC])) {
            return;
        }

        $entry = new ClassEntry('UConverter');
        $entry->isInternal = true;
        foreach (self::classConstants() as $name => $value) {
            $lc = strtolower($name);
            $const = new Variable(Variable::TYPE_INTEGER);
            $const->int($value);
            $entry->constants[$lc] = $const;
            $entry->constNames[$lc] = $name;
        }
        $pub = CfgFunc::FLAG_PUBLIC;
        $pubStatic = $pub | CfgFunc::FLAG_STATIC;
        $entry->constructor = new UConverterConstruct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $pub;
        $methods = [
            'convert' => [new UConverterConvert(), 'convert', false],
            'fromucallback' => [new UConverterFromUCallback(), 'fromUCallback', false],
            'toucallback' => [new UConverterToUCallback(), 'toUCallback', false],
            'geterrorcode' => [new UConverterGetErrorCode(), 'getErrorCode', false],
            'geterrormessage' => [new UConverterGetErrorMessage(), 'getErrorMessage', false],
            'getsourceencoding' => [new UConverterGetSourceEncoding(), 'getSourceEncoding', false],
            'getdestinationencoding' => [new UConverterGetDestinationEncoding(), 'getDestinationEncoding', false],
            'setsourceencoding' => [new UConverterSetSourceEncoding(), 'setSourceEncoding', false],
            'setdestinationencoding' => [new UConverterSetDestinationEncoding(), 'setDestinationEncoding', false],
            'getsourcetype' => [new UConverterGetSourceType(), 'getSourceType', false],
            'getdestinationtype' => [new UConverterGetDestinationType(), 'getDestinationType', false],
            'getsubstchars' => [new UConverterGetSubstChars(), 'getSubstChars', false],
            'setsubstchars' => [new UConverterSetSubstChars(), 'setSubstChars', false],
            'reasontext' => [new UConverterReasonText(), 'reasonText', true],
            'transcode' => [new UConverterTranscode(), 'transcode', true],
            'getavailable' => [new UConverterGetAvailable(), 'getAvailable', true],
            'getaliases' => [new UConverterGetAliases(), 'getAliases', true],
            'getstandards' => [new UConverterGetStandards(), 'getStandards', true],
        ];
        foreach ($methods as $lc => [$handler, $name, $static]) {
            $entry->methods[$lc] = $handler;
            $entry->methodVisibility[$lc] = $static ? $pubStatic : $pub;
            $entry->methodNames[$lc] = $name;
        }
        $ctx->classes[self::CLASS_LC] = $entry;
    }

    /**
     * UConverter::transcode() — one-shot charset conversion (php-src ext/intl/converter; #6401 / #21978 / #25201).
     *
     * Unmappable codepoints use ICU-like substitution (ASCII → 0x1A), matching instance {@see convert()} —
     * not bare {@see VmIconv::iconv()} hard-fail. Optional {@code $options['to_subst']} /
     * {@code $options['from_subst']} mirror php-src {@code ucnv_setSubstChars} on dest/src converters.
     */
    public static function transcode(
        string $str,
        string $toEncoding,
        string $fromEncoding,
        ?array $options = null
    ): string|false {
        $to = '' !== $toEncoding ? $toEncoding : 'UTF-8';
        $from = '' !== $fromEncoding ? $fromEncoding : 'UTF-8';
        $toSubst = self::optionSubstString($options, 'to_subst');
        // from_subst applies to the source converter; for UTF-8→SBCS illegal input, ICU still
        // surfaces via the dest subst path after U+FFFD (php-src converter.cpp) — honor to_subst.
        unset($options);
        $destOk = null !== CharsetEngine::parseEncodingSpec(VmIconv::resolveIconvEncoding($to, false));
        $srcOk = null !== CharsetEngine::parseEncodingSpec(VmIconv::resolveIconvEncoding($from, true));
        if (!$destOk || !$srcOk) {
            return false;
        }
        // ICU UTF-8 is stricter than glibc iconv (overlong/surrogate/out-of-range). Always walk
        // UTF-8 sources through nativeSubstConvert so illegal bytes become U+FFFD (#25203).
        if (self::isUtf8Family($from)) {
            return self::nativeSubstConvert($str, $from, $to, $toSubst ?? '');
        }
        $result = VmIconv::iconv($from, $to, $str);
        if (false !== $result) {
            return $result;
        }

        return self::nativeSubstConvert($str, $from, $to, $toSubst ?? '');
    }

    /**
     * Extract a string option from UConverter::transcode() $options (php-src converter.cpp).
     *
     * @param array<mixed>|null $options
     */
    private static function optionSubstString(?array $options, string $key): ?string
    {
        if (null === $options || !\array_key_exists($key, $options)) {
            return null;
        }
        $value = $options[$key];
        if (!\is_string($value)) {
            return null;
        }

        return $value;
    }

    public static function isUConverterObject(?ObjectEntry $object, ?Context $ctx = null): bool
    {
        if (null === $object) {
            return false;
        }
        if (null !== $ctx) {
            return VmReflection::isInstanceOf($ctx, $object->class, 'UConverter');
        }
        $entry = $object->class;
        $guard = 0;
        while ($guard++ < 64) {
            if (self::CLASS_LC === strtolower($entry->name)) {
                return true;
            }
            if (null === $entry->parentLc) {
                return false;
            }
            if (self::CLASS_LC === $entry->parentLc) {
                return true;
            }
            // Without Context, only direct UConverter children are recognized.
            return false;
        }

        return false;
    }

    public static function construct(ObjectEntry $object, string $destination, ?string $source): void
    {
        $dest = '' !== $destination ? $destination : 'UTF-8';
        $src = null !== $source && '' !== $source ? $source : 'UTF-8';
        $destOk = null !== CharsetEngine::parseEncodingSpec(VmIconv::resolveIconvEncoding($dest, false));
        $srcOk = null !== CharsetEngine::parseEncodingSpec(VmIconv::resolveIconvEncoding($src, true));
        $openOk = $destOk && $srcOk;
        self::$state[$object->id] = [
            'dest' => $dest,
            'src' => $src,
            'destOk' => $destOk,
            'srcOk' => $srcOk,
            'substChars' => $srcOk ? self::defaultSubstChars($src) : '',
            'substCharsExplicit' => false,
            'errorCode' => $openOk ? IntlError::U_ZERO_ERROR : self::U_FILE_ACCESS_ERROR,
            'errorMessage' => $openOk
                ? 'U_ZERO_ERROR'
                : 'ucnv_open() returned error 4: U_FILE_ACCESS_ERROR: U_FILE_ACCESS_ERROR',
            'openOk' => $openOk,
        ];
        $object->constructed = true;
    }

    public static function convert(ObjectEntry $object, string $str, bool $reverse = false, ?Context $ctx = null): string|false
    {
        $state = self::$state[$object->id] ?? null;
        if (null === $state) {
            throw new \Error('UConverter::convert() called on uninitialized UConverter');
        }
        if (!$state['openOk']) {
            self::$state[$object->id]['errorCode'] = self::U_INVALID_STATE_ERROR;
            self::$state[$object->id]['errorMessage'] = 'Internal converters not initialized: U_INVALID_STATE_ERROR';

            return false;
        }
        $from = $reverse ? $state['dest'] : $state['src'];
        $to = $reverse ? $state['src'] : $state['dest'];
        $hasUserCb = null !== $ctx && (self::hasUserCallbackOverride($object, 'fromucallback')
            || self::hasUserCallbackOverride($object, 'toucallback'));
        if ($hasUserCb) {
            return self::convertWithCallbacks($ctx, $object, $str, $from, $to, $reverse);
        }
        // Same ICU-vs-iconv strictness as transcode() (#25203): UTF-8 sources must not
        // short-circuit on a glibc iconv "success" that kept overlong/surrogate/out-of-range.
        if (self::isUtf8Family($from)) {
            return self::convertWithNativeSubst($object, $str, $from, $to);
        }
        $result = VmIconv::iconv($from, $to, $str);
        if (false === $result) {
            // Base UConverter short-circuits PHP callbacks (php-src php_converter_set_callbacks);
            // ICU substitutes into the destination charset (ASCII → 0x1A).
            return self::convertWithNativeSubst($object, $str, $from, $to);
        }
        self::$state[$object->id]['errorCode'] = IntlError::U_ZERO_ERROR;
        self::$state[$object->id]['errorMessage'] = 'U_ZERO_ERROR';

        return $result;
    }

    /** ICU-like substitution without PHP callback overrides (base UConverter). */
    private static function convertWithNativeSubst(
        ObjectEntry $object,
        string $str,
        string $from,
        string $to
    ): string {
        $state = self::$state[$object->id];
        // Source-default U+FFFD must not become the ASCII dest subst (Zend: getSubstChars=FFFD
        // but convert→0x1A until setSubstChars). Explicit setSubstChars / transcode to_subst do.
        $configured = !empty($state['substCharsExplicit']) ? ($state['substChars'] ?? '') : '';
        $out = self::nativeSubstConvert($str, $from, $to, $configured);
        self::$state[$object->id]['errorCode'] = IntlError::U_ZERO_ERROR;
        self::$state[$object->id]['errorMessage'] = 'U_ZERO_ERROR';

        return $out;
    }

    /**
     * Shared ICU-like substitution for convert() and static transcode() (#21978 / #25201 / #25203).
     *
     * @param string $configuredSubstChars empty → charset defaults (ASCII 0x1A / Unicode U+FFFD);
     *                                     non-empty honors transcode() {@code to_subst} / setSubstChars()
     */
    private static function nativeSubstConvert(
        string $str,
        string $from,
        string $to,
        string $configuredSubstChars
    ): string {
        if (self::isUtf8Family($from)) {
            $out = '';
            $len = \strlen($str);
            $i = 0;
            $subst = self::resolveDestSubstChars($to, $configuredSubstChars);
            while ($i < $len) {
                $cp = self::utf8NextCodepoint($str, $i, $next);
                if (null === $cp) {
                    // Illegal UTF-8: Unicode dest keeps U+FFFD (to_subst does not override —
                    // Zend UConverter::transcode UTF-8→UTF-8); SBCS dest uses to_subst / 0x1A
                    // after the U+FFFD pivot fails to encode (php-src converter.cpp).
                    $out .= self::isUnicodeCharset($to) ? "\xef\xbf\xbd" : $subst;
                    $i = $next > $i ? $next : $i + 1;
                    continue;
                }
                $i = $next;
                $char = self::utf8Chr($cp);
                if (null === $char) {
                    $out .= $subst;
                    continue;
                }
                $piece = VmIconv::iconv('UTF-8', $to, $char);
                $out .= false !== $piece ? $piece : $subst;
            }

            return $out;
        }

        return self::resolveDestSubstChars($to, $configuredSubstChars);
    }

    /** Dest-side substitution for unmappable / illegal after Unicode pivot (#25201). */
    private static function resolveDestSubstChars(string $toEncoding, string $configuredSubstChars): string
    {
        if ('' !== $configuredSubstChars) {
            return $configuredSubstChars;
        }

        return self::isUnicodeCharset($toEncoding) ? self::defaultSubstChars($toEncoding) : "\x1a";
    }

    /**
     * php-src php_converter_default_callback — return subst chars for unassigned/illegal/irregular (#20917).
     *
     * @return string|int|array|null
     */
    public static function defaultCallback(ObjectEntry $object, int $reason, Variable $errorVar)
    {
        if (self::REASON_UNASSIGNED !== $reason
            && self::REASON_ILLEGAL !== $reason
            && self::REASON_IRREGULAR !== $reason) {
            $errorVar->int(IntlError::U_ZERO_ERROR);

            return null;
        }
        $state = self::$state[$object->id] ?? null;
        if (null === $state || !$state['srcOk']) {
            $errorVar->int(self::U_INVALID_STATE_ERROR);

            return "\x1a";
        }
        $chars = $state['substChars'] !== '' ? $state['substChars'] : self::defaultSubstChars($state['src']);
        $errorVar->int(IntlError::U_ZERO_ERROR);

        return $chars;
    }

    private static function hasUserCallbackOverride(ObjectEntry $object, string $methodLc): bool
    {
        $func = $object->class->methods[$methodLc] ?? null;

        return $func instanceof PhpFunc;
    }

    /**
     * Charset conversion with fromUCallback / toUCallback dispatch (php-src converter.c; #20917).
     */
    private static function convertWithCallbacks(
        Context $ctx,
        ObjectEntry $object,
        string $str,
        string $from,
        string $to,
        bool $reverse
    ): string|false {
        unset($reverse);
        // Prefer UTF-8 source iteration (fromUCallback on unmappable dest codepoints).
        if (self::isUtf8Family($from)) {
            return self::convertUtf8WithFromU($ctx, $object, $str, $to);
        }
        // Byte-oriented source: attempt whole-string iconv; on failure invoke toUCallback once.
        $result = VmIconv::iconv($from, $to, $str);
        if (false !== $result) {
            self::$state[$object->id]['errorCode'] = IntlError::U_ZERO_ERROR;
            self::$state[$object->id]['errorMessage'] = 'U_ZERO_ERROR';

            return $result;
        }
        $error = self::U_INVALID_CHAR_FOUND;
        $cb = self::invokeToUCallback($ctx, $object, self::REASON_ILLEGAL, $str, $str, $error);
        self::$state[$object->id]['errorCode'] = $error;
        self::$state[$object->id]['errorMessage'] = IntlError::errorName($error);
        if (null === $cb) {
            return '';
        }
        if (\is_string($cb)) {
            return $cb;
        }
        if (\is_int($cb)) {
            return self::utf8Chr($cb) ?? '';
        }

        return false;
    }

    private static function convertUtf8WithFromU(
        Context $ctx,
        ObjectEntry $object,
        string $str,
        string $to
    ): string|false {
        $out = '';
        $len = \strlen($str);
        $i = 0;
        $lastError = IntlError::U_ZERO_ERROR;
        while ($i < $len) {
            $cp = self::utf8NextCodepoint($str, $i, $next);
            if (null === $cp) {
                $chunk = $str[$i];
                ++$i;
                $error = self::U_INVALID_CHAR_FOUND;
                $cb = self::invokeToUCallback($ctx, $object, self::REASON_ILLEGAL, $chunk, $chunk, $error);
                $lastError = $error;
                if (null === $cb) {
                    continue;
                }
                if (\is_string($cb)) {
                    $out .= $cb;
                } elseif (\is_int($cb)) {
                    $out .= self::utf8Chr($cb) ?? '';
                }
                continue;
            }
            $i = $next;
            $char = self::utf8Chr($cp);
            if (null === $char) {
                continue;
            }
            $piece = VmIconv::iconv('UTF-8', $to, $char);
            if (false !== $piece) {
                $out .= $piece;
                continue;
            }
            $error = self::U_INVALID_CHAR_FOUND;
            $cb = self::invokeFromUCallback(
                $ctx,
                $object,
                self::REASON_UNASSIGNED,
                [$cp],
                $cp,
                $error
            );
            $lastError = $error;
            if (null === $cb) {
                continue;
            }
            if (\is_string($cb)) {
                $out .= $cb;
            } elseif (\is_int($cb)) {
                $encoded = VmIconv::iconv('UTF-8', $to, self::utf8Chr($cb) ?? '');
                $out .= false !== $encoded ? $encoded : '';
            } elseif (\is_array($cb)) {
                foreach ($cb as $unit) {
                    if (\is_int($unit)) {
                        $encoded = VmIconv::iconv('UTF-8', $to, self::utf8Chr($unit) ?? '');
                        $out .= false !== $encoded ? $encoded : '';
                    } elseif (\is_string($unit)) {
                        $out .= $unit;
                    }
                }
            }
        }
        self::$state[$object->id]['errorCode'] = $lastError;
        self::$state[$object->id]['errorMessage'] = 0 === $lastError
            ? 'U_ZERO_ERROR'
            : IntlError::errorName($lastError);

        return $out;
    }

    /**
     * @param list<int> $sourceCodepoints
     * @return string|int|array|null
     */
    private static function invokeFromUCallback(
        Context $ctx,
        ObjectEntry $object,
        int $reason,
        array $sourceCodepoints,
        int $codePoint,
        int &$error
    ) {
        $reasonVar = new Variable();
        $reasonVar->int($reason);
        $sourceHt = new HashTable();
        foreach ($sourceCodepoints as $cp) {
            $slot = new Variable();
            $slot->int($cp);
            $sourceHt->append($slot);
        }
        $sourceVar = new Variable();
        $sourceVar->array($sourceHt);
        $cpVar = new Variable();
        $cpVar->int($codePoint);
        $errorVar = new Variable();
        $errorVar->int($error);
        $result = $ctx->runtime->vm->invokeInstanceMethod(
            $object,
            'fromUCallback',
            $reasonVar,
            $sourceVar,
            $cpVar,
            $errorVar
        )->resolveIndirect();
        $error = Variable::TYPE_INTEGER === $errorVar->resolveIndirect()->type
            ? $errorVar->resolveIndirect()->toInt()
            : $error;

        return self::exportCallbackReturn($result);
    }

    /**
     * @return string|int|array|null
     */
    private static function invokeToUCallback(
        Context $ctx,
        ObjectEntry $object,
        int $reason,
        string $source,
        string $codeUnits,
        int &$error
    ) {
        $reasonVar = new Variable();
        $reasonVar->int($reason);
        $sourceVar = new Variable();
        $sourceVar->string($source);
        $unitsVar = new Variable();
        $unitsVar->string($codeUnits);
        $errorVar = new Variable();
        $errorVar->int($error);
        $result = $ctx->runtime->vm->invokeInstanceMethod(
            $object,
            'toUCallback',
            $reasonVar,
            $sourceVar,
            $unitsVar,
            $errorVar
        )->resolveIndirect();
        $error = Variable::TYPE_INTEGER === $errorVar->resolveIndirect()->type
            ? $errorVar->resolveIndirect()->toInt()
            : $error;

        return self::exportCallbackReturn($result);
    }

    /**
     * @return string|int|array|null
     */
    private static function exportCallbackReturn(Variable $result)
    {
        $result = $result->resolveIndirect();
        if (Variable::TYPE_NULL === $result->type) {
            return null;
        }
        if (Variable::TYPE_STRING === $result->type) {
            return $result->toString();
        }
        if (Variable::TYPE_INTEGER === $result->type) {
            return $result->toInt();
        }
        if (Variable::TYPE_ARRAY === $result->type) {
            $out = [];
            foreach ($result->toArray()->iterateKeyed(true) as [, $value]) {
                $value = $value->resolveIndirect();
                if (Variable::TYPE_INTEGER === $value->type) {
                    $out[] = $value->toInt();
                } elseif (Variable::TYPE_STRING === $value->type) {
                    $out[] = $value->toString();
                }
            }

            return $out;
        }

        return null;
    }

    private static function isUtf8Family(string $encoding): bool
    {
        $n = strtoupper(str_replace(['-', '_', ' '], '', $encoding));

        return str_contains($n, 'UTF8') || 'CP1208' === $n || '' === $n;
    }

    /**
     * Decode one well-formed UTF-8 codepoint (ICU / php-src converter.cpp).
     * Illegal lead, overlong, surrogate, or out-of-range advances $next by one byte so each
     * bad byte becomes U+FFFD (#25203) — matching Zend UConverter::transcode.
     *
     * @return int|null codepoint; advances $next
     */
    private static function utf8NextCodepoint(string $str, int $i, ?int &$next): ?int
    {
        $len = \strlen($str);
        if ($i >= $len) {
            $next = $i;

            return null;
        }
        $b0 = \ord($str[$i]);
        if ($b0 < 0x80) {
            $next = $i + 1;

            return $b0;
        }
        if (($b0 & 0xE0) === 0xC0) {
            $need = 1;
            $min = 0x80;
        } elseif (($b0 & 0xF0) === 0xE0) {
            $need = 2;
            $min = 0x800;
        } elseif (($b0 & 0xF8) === 0xF0) {
            $need = 3;
            $min = 0x10000;
        } else {
            $next = $i + 1;

            return null;
        }
        if ($i + $need >= $len) {
            $next = $i + 1;

            return null;
        }
        $cp = $b0 & (0xFF >> (2 + $need));
        for ($j = 1; $j <= $need; ++$j) {
            $bj = \ord($str[$i + $j]);
            if (($bj & 0xC0) !== 0x80) {
                $next = $i + 1;

                return null;
            }
            $cp = ($cp << 6) | ($bj & 0x3F);
        }
        // Overlong, UTF-16 surrogates, or above U+10FFFF — consume one byte (ICU).
        if ($cp < $min || ($cp >= 0xD800 && $cp <= 0xDFFF) || $cp > 0x10FFFF) {
            $next = $i + 1;

            return null;
        }
        $next = $i + 1 + $need;

        return $cp;
    }

    private static function utf8Chr(int $cp): ?string
    {
        if ($cp < 0 || $cp > 0x10FFFF) {
            return null;
        }
        if (\function_exists('intlchr')) {
            $s = \intlchr($cp);
            if (false !== $s && null !== $s) {
                return $s;
            }
        }
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

    public static function getErrorCode(ObjectEntry $object): int
    {
        $state = self::$state[$object->id] ?? null;
        if (null === $state) {
            return IntlError::U_ZERO_ERROR;
        }

        return $state['errorCode'];
    }

    public static function getErrorMessage(ObjectEntry $object): string
    {
        $state = self::$state[$object->id] ?? null;
        if (null === $state) {
            return 'U_ZERO_ERROR';
        }

        return $state['errorMessage'];
    }

    /**
     * UConverter::getSourceEncoding() — php-src converter.c php_converter_do_get_encoding (#20770).
     */
    public static function getSourceEncoding(ObjectEntry $object): ?string
    {
        $state = self::$state[$object->id] ?? null;
        if (null === $state || !$state['srcOk']) {
            return null;
        }

        return $state['src'];
    }

    /**
     * UConverter::getDestinationEncoding() — php-src converter.c (#20770).
     */
    public static function getDestinationEncoding(ObjectEntry $object): ?string
    {
        $state = self::$state[$object->id] ?? null;
        if (null === $state || !$state['destOk']) {
            return null;
        }

        return $state['dest'];
    }

    /**
     * UConverter::setSourceEncoding() — php-src converter.c uconverter_set_source_encoding (#20881).
     */
    public static function setSourceEncoding(ObjectEntry $object, string $encoding): bool
    {
        $state = self::$state[$object->id] ?? null;
        if (null === $state) {
            throw new \Error('UConverter::setSourceEncoding() called on uninitialized UConverter');
        }
        $enc = '' !== $encoding ? $encoding : 'UTF-8';
        $ok = null !== CharsetEngine::parseEncodingSpec(VmIconv::resolveIconvEncoding($enc, true));
        if (!$ok) {
            $msg = 'ucnv_open() returned error 4: U_FILE_ACCESS_ERROR: U_FILE_ACCESS_ERROR';
            self::$state[$object->id]['errorCode'] = self::U_FILE_ACCESS_ERROR;
            self::$state[$object->id]['errorMessage'] = $msg;
            IntlError::set(self::U_FILE_ACCESS_ERROR, $msg);

            return false;
        }
        self::$state[$object->id]['src'] = $enc;
        self::$state[$object->id]['srcOk'] = true;
        self::$state[$object->id]['substChars'] = self::defaultSubstChars($enc);
        self::$state[$object->id]['substCharsExplicit'] = false;
        self::$state[$object->id]['openOk'] = (bool) self::$state[$object->id]['destOk'];
        self::$state[$object->id]['errorCode'] = IntlError::U_ZERO_ERROR;
        self::$state[$object->id]['errorMessage'] = 'U_ZERO_ERROR';
        IntlError::clear();

        return true;
    }

    /**
     * UConverter::setDestinationEncoding() — php-src converter.c uconverter_set_destination_encoding (#20881).
     */
    public static function setDestinationEncoding(ObjectEntry $object, string $encoding): bool
    {
        $state = self::$state[$object->id] ?? null;
        if (null === $state) {
            throw new \Error('UConverter::setDestinationEncoding() called on uninitialized UConverter');
        }
        $enc = '' !== $encoding ? $encoding : 'UTF-8';
        $ok = null !== CharsetEngine::parseEncodingSpec(VmIconv::resolveIconvEncoding($enc, false));
        if (!$ok) {
            $msg = 'ucnv_open() returned error 4: U_FILE_ACCESS_ERROR: U_FILE_ACCESS_ERROR';
            self::$state[$object->id]['errorCode'] = self::U_FILE_ACCESS_ERROR;
            self::$state[$object->id]['errorMessage'] = $msg;
            IntlError::set(self::U_FILE_ACCESS_ERROR, $msg);

            return false;
        }
        self::$state[$object->id]['dest'] = $enc;
        self::$state[$object->id]['destOk'] = true;
        self::$state[$object->id]['openOk'] = (bool) self::$state[$object->id]['srcOk'];
        self::$state[$object->id]['errorCode'] = IntlError::U_ZERO_ERROR;
        self::$state[$object->id]['errorMessage'] = 'U_ZERO_ERROR';
        IntlError::clear();

        return true;
    }

    /**
     * UConverter::getSubstChars() — php-src converter.c (#20770).
     */
    public static function getSubstChars(ObjectEntry $object): ?string
    {
        $state = self::$state[$object->id] ?? null;
        if (null === $state || !$state['srcOk']) {
            return null;
        }

        return $state['substChars'];
    }

    /**
     * UConverter::setSubstChars() — php-src converter.c ucnv_setSubstChars (#20770).
     *
     * Without ICU, approximate charset acceptance: single-byte converters reject multi-byte
     * substitution sequences (matches Zend ISO-8859-1 vs UTF-8 behavior).
     */
    public static function setSubstChars(ObjectEntry $object, string $chars): bool
    {
        $state = self::$state[$object->id] ?? null;
        if (null === $state) {
            throw new \Error('UConverter::setSubstChars() called on uninitialized UConverter');
        }
        if (!$state['srcOk'] || !$state['destOk']) {
            self::$state[$object->id]['errorCode'] = self::U_INVALID_STATE_ERROR;
            self::$state[$object->id]['errorMessage'] = 'Internal converters not initialized: U_INVALID_STATE_ERROR';

            return false;
        }
        $len = \strlen($chars);
        if ($len < 1 || $len > 4) {
            return false;
        }
        if ($len > 1 && (self::isSingleByteCharset($state['src']) || self::isSingleByteCharset($state['dest']))) {
            return false;
        }
        self::$state[$object->id]['substChars'] = $chars;
        self::$state[$object->id]['substCharsExplicit'] = true;
        self::$state[$object->id]['errorCode'] = IntlError::U_ZERO_ERROR;
        self::$state[$object->id]['errorMessage'] = 'U_ZERO_ERROR';

        return true;
    }

    /**
     * UConverter::reasonText() — php-src converter.c UCNV_REASON_CASE (#20770).
     */
    public static function reasonText(int $reason): string
    {
        return match ($reason) {
            self::REASON_UNASSIGNED => 'REASON_UNASSIGNED',
            self::REASON_ILLEGAL => 'REASON_ILLEGAL',
            self::REASON_IRREGULAR => 'REASON_IRREGULAR',
            self::REASON_RESET => 'REASON_RESET',
            self::REASON_CLOSE => 'REASON_CLOSE',
            self::REASON_CLONE => 'REASON_CLONE',
            default => throw new \ValueError(
                'UConverter::reasonText(): Argument #1 ($reason) must be a UConverter::REASON_* constant'
            ),
        };
    }

    /**
     * UConverter::getAvailable() — php-src / ICU ucnv_countAvailable (#20788).
     */
    public static function getAvailable(): HashTable
    {
        $names = [];
        $ffi = self::ffi();
        if (null !== $ffi) {
            $countFn = 'ucnv_countAvailable'.self::$symSuffix;
            $nameFn = 'ucnv_getAvailableName'.self::$symSuffix;
            $n = (int) $ffi->$countFn();
            for ($i = 0; $i < $n; ++$i) {
                $ptr = $ffi->$nameFn($i);
                $s = self::ffiCString($ptr);
                if (null !== $s && '' !== $s) {
                    $names[] = $s;
                }
            }
        }
        if ([] === $names) {
            $names = ['UTF-8', 'UTF-16', 'UTF-16BE', 'UTF-16LE', 'UTF-32', 'ISO-8859-1', 'US-ASCII', 'windows-1252'];
        }

        return self::stringListToHashTable($names);
    }

    /**
     * UConverter::getAliases() — php-src / ICU ucnv_countAliases (#20788).
     *
     * @return HashTable|false|null
     */
    public static function getAliases(string $name): HashTable|false|null
    {
        $ffi = self::ffi();
        if (null !== $ffi) {
            $countFn = 'ucnv_countAliases'.self::$symSuffix;
            $aliasFn = 'ucnv_getAlias'.self::$symSuffix;
            $status = $ffi->new('UErrorCode');
            $status->cdata = 0;
            $n = (int) $ffi->$countFn($name, \FFI::addr($status));
            if ((int) $status->cdata > 0 || $n <= 0) {
                return false;
            }
            $aliases = [];
            for ($i = 0; $i < $n; ++$i) {
                $status->cdata = 0;
                $ptr = $ffi->$aliasFn($name, $i, \FFI::addr($status));
                $s = self::ffiCString($ptr);
                if (null !== $s && '' !== $s) {
                    $aliases[] = $s;
                }
            }

            return self::stringListToHashTable($aliases);
        }
        $norm = strtoupper(str_replace(['-', '_'], '', $name));
        if (\in_array($norm, ['UTF8', 'CP1208', 'WINDOWS65001'], true)) {
            return self::stringListToHashTable(['UTF-8', 'ibm-1208', 'windows-65001', 'cp1208']);
        }

        return false;
    }

    /**
     * UConverter::getStandards() — php-src / ICU ucnv_countStandards (#20788).
     */
    public static function getStandards(): ?HashTable
    {
        $ffi = self::ffi();
        if (null !== $ffi) {
            $countFn = 'ucnv_countStandards'.self::$symSuffix;
            $stdFn = 'ucnv_getStandard'.self::$symSuffix;
            $n = (int) $ffi->$countFn();
            $status = $ffi->new('UErrorCode');
            $names = [];
            for ($i = 0; $i < $n; ++$i) {
                $status->cdata = 0;
                $ptr = $ffi->$stdFn($i, \FFI::addr($status));
                $s = self::ffiCString($ptr);
                $names[] = null === $s ? '' : $s;
            }

            return self::stringListToHashTable($names);
        }

        return self::stringListToHashTable(['UTR22', 'IBM', 'WINDOWS', 'JAVA', 'IANA', 'MIME', '']);
    }

    /**
     * UConverter::getSourceType() — php-src / ICU ucnv_getType (#20788).
     *
     * @return int|false|null
     */
    public static function getSourceType(ObjectEntry $object): int|false|null
    {
        $state = self::$state[$object->id] ?? null;
        if (null === $state || !$state['srcOk']) {
            return null;
        }

        return self::typeForEncoding($state['src']);
    }

    /**
     * UConverter::getDestinationType() — php-src / ICU ucnv_getType (#20788).
     *
     * @return int|false|null
     */
    public static function getDestinationType(ObjectEntry $object): int|false|null
    {
        $state = self::$state[$object->id] ?? null;
        if (null === $state || !$state['destOk']) {
            return null;
        }

        return self::typeForEncoding($state['dest']);
    }

    public static function coerceIntArg(Variable $var, string $function, int $position, string $name): int
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_INTEGER === $var->type) {
            return $var->toInt();
        }
        if (Variable::TYPE_FLOAT === $var->type) {
            return (int) $var->toFloat();
        }
        if (Variable::TYPE_BOOLEAN === $var->type) {
            return $var->toBool() ? 1 : 0;
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

    /** @param list<string> $names */
    public static function stringListToHashTable(array $names): HashTable
    {
        $ht = new HashTable();
        foreach ($names as $name) {
            $slot = new Variable();
            $slot->string($name);
            $ht->append($slot);
        }

        return $ht;
    }

    /** ICU default subst: UTF/UCS sources use U+FFFD; single-byte sources use 0x1A. */
    private static function defaultSubstChars(string $srcEncoding): string
    {
        return self::isUnicodeCharset($srcEncoding) ? "\xEF\xBF\xBD" : "\x1a";
    }

    private static function isUnicodeCharset(string $encoding): bool
    {
        $n = strtoupper(str_replace(['-', '_', ' '], '', $encoding));

        return str_contains($n, 'UTF') || str_contains($n, 'UCS') || str_starts_with($n, 'GB18030');
    }

    private static function isSingleByteCharset(string $encoding): bool
    {
        return !self::isUnicodeCharset($encoding);
    }

    private static function typeForEncoding(string $encoding): int
    {
        $ffi = self::ffi();
        if (null !== $ffi) {
            $open = 'ucnv_open'.self::$symSuffix;
            $getType = 'ucnv_getType'.self::$symSuffix;
            $close = 'ucnv_close'.self::$symSuffix;
            $status = $ffi->new('UErrorCode');
            $status->cdata = 0;
            $cnv = $ffi->$open($encoding, \FFI::addr($status));
            if ((int) $status->cdata > 0 || null === $cnv) {
                return self::UNSUPPORTED_CONVERTER;
            }
            $type = (int) $ffi->$getType($cnv);
            $ffi->$close($cnv);

            return $type;
        }
        $n = strtoupper(str_replace(['-', '_', ' '], '', $encoding));
        if (str_contains($n, 'UTF8') || 'CP1208' === $n) {
            return self::UTF8;
        }
        if ('ISO88591' === $n || 'LATIN1' === $n) {
            return self::LATIN_1;
        }
        if ('USASCII' === $n || 'ASCII' === $n) {
            return self::US_ASCII;
        }
        if (str_contains($n, 'UTF16BE')) {
            return self::UTF16_BigEndian;
        }
        if (str_contains($n, 'UTF16LE')) {
            return self::UTF16_LittleEndian;
        }

        return self::SBCS;
    }

    private static function ffiCString(mixed $ptr): ?string
    {
        if (null === $ptr || false === $ptr) {
            return null;
        }
        if (\is_string($ptr)) {
            return $ptr;
        }

        return \FFI::string($ptr);
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
            ['libicuuc.so.70', '_70'],
            ['libicuuc.so.74', '_74'],
            ['libicuuc.so.72', '_72'],
            ['libicuuc.so.71', '_71'],
            ['libicuuc.so', '_70'],
            ['libicuuc.dylib', ''],
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
typedef uint16_t uint16_t;
typedef struct UConverter UConverter;
typedef int32_t UConverterType;
int32_t ucnv_countAvailable{$suffix}(void);
const char *ucnv_getAvailableName{$suffix}(int32_t n);
int32_t ucnv_countAliases{$suffix}(const char *alias, UErrorCode *pErrorCode);
const char *ucnv_getAlias{$suffix}(const char *alias, uint16_t n, UErrorCode *pErrorCode);
uint16_t ucnv_countStandards{$suffix}(void);
const char *ucnv_getStandard{$suffix}(uint16_t n, UErrorCode *pErrorCode);
UConverter *ucnv_open{$suffix}(const char *converterName, UErrorCode *err);
void ucnv_close{$suffix}(UConverter *converter);
UConverterType ucnv_getType{$suffix}(const UConverter *converter);
C;
    }

    public static function requireReceiver(Variable $var, string $label, ?Context $ctx = null): ObjectEntry
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $var->type || !self::isUConverterObject($var->toObject(), $ctx)) {
            throw new \Error($label.' called on incompatible object');
        }
        $object = $var->toObject();
        if (!$object->constructed) {
            throw new \Error($label.' called on uninitialized UConverter');
        }

        return $object;
    }
}

/** UConverter::__construct() — php-src ext/intl/converter (#6171). */
final class UConverterConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1) {
            throw new \LogicException('UConverter::__construct() called without $this');
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type) {
            throw new \TypeError('UConverter::__construct() must be called on UConverter');
        }
        $destination = 'UTF-8';
        $source = null;
        if ($argc >= 2) {
            $destination = VmString::coerceStringBuiltinArg(
                $frame->calledArgs[1],
                'UConverter::__construct',
                0,
                'destination_encoding'
            );
        }
        if ($argc >= 3) {
            $source = VmString::coerceStringBuiltinArg(
                $frame->calledArgs[2],
                'UConverter::__construct',
                1,
                'source_encoding'
            );
        }
        if ($argc > 3) {
            throw new \ArgumentCountError(\sprintf(
                'UConverter::__construct() expects at most 2 arguments, %d given',
                $argc - 1
            ));
        }
        VmUConverter::construct($receiver->toObject(), $destination, $source);
    }
}

/** UConverter::convert() — php-src ext/intl/converter (#6171). */
final class UConverterConvert extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('convert');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(\sprintf(
                'UConverter::convert() expects at least 1 argument, %d given',
                max(0, $argc - 1)
            ));
        }
        $object = VmUConverter::requireReceiver($frame->calledArgs[0], 'UConverter::convert()', $frame->vmContext);
        $str = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[1],
            'UConverter::convert',
            0,
            'str'
        );
        $reverse = false;
        if (3 === $argc) {
            $revVar = $frame->calledArgs[2]->resolveIndirect();
            $reverse = Variable::TYPE_NULL !== $revVar->type && $revVar->toBool();
        }
        $result = VmUConverter::convert($object, $str, $reverse, $frame->vmContext);
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($result);
    }
}

/** UConverter::fromUCallback() — php-src converter.c default (#20917). */
final class UConverterFromUCallback extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('fromUCallback');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 5 || $argc > 5) {
            throw new \ArgumentCountError(\sprintf(
                'UConverter::fromUCallback() expects exactly 4 arguments, %d given',
                max(0, $argc - 1)
            ));
        }
        $object = VmUConverter::requireReceiver($frame->calledArgs[0], 'UConverter::fromUCallback()', $frame->vmContext);
        $reason = (int) $frame->calledArgs[1]->resolveIndirect()->toInt();
        // $source (array) and $codePoint accepted for signature parity; default ignores payload.
        unset($frame->calledArgs[2], $frame->calledArgs[3]);
        $errorVar = $frame->calledArgs[4];
        $result = VmUConverter::defaultCallback($object, $reason, $errorVar);
        if (null === $frame->returnVar) {
            return;
        }
        if (null === $result) {
            $frame->returnVar->null();
        } elseif (\is_string($result)) {
            $frame->returnVar->string($result);
        } elseif (\is_int($result)) {
            $frame->returnVar->int($result);
        } else {
            $frame->returnVar->null();
        }
    }
}

/** UConverter::toUCallback() — php-src converter.c default (#20917). */
final class UConverterToUCallback extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('toUCallback');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 5 || $argc > 5) {
            throw new \ArgumentCountError(\sprintf(
                'UConverter::toUCallback() expects exactly 4 arguments, %d given',
                max(0, $argc - 1)
            ));
        }
        $object = VmUConverter::requireReceiver($frame->calledArgs[0], 'UConverter::toUCallback()', $frame->vmContext);
        $reason = (int) $frame->calledArgs[1]->resolveIndirect()->toInt();
        unset($frame->calledArgs[2], $frame->calledArgs[3]);
        $errorVar = $frame->calledArgs[4];
        $result = VmUConverter::defaultCallback($object, $reason, $errorVar);
        if (null === $frame->returnVar) {
            return;
        }
        if (null === $result) {
            $frame->returnVar->null();
        } elseif (\is_string($result)) {
            $frame->returnVar->string($result);
        } elseif (\is_int($result)) {
            $frame->returnVar->int($result);
        } else {
            $frame->returnVar->null();
        }
    }
}

/** UConverter::getErrorCode() — php-src ext/intl/converter (#6171). */
final class UConverterGetErrorCode extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getErrorCode');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'UConverter::getErrorCode() expects exactly 0 arguments, %d given',
                max(0, $argc - 1)
            ));
        }
        $object = VmUConverter::requireReceiver($frame->calledArgs[0], 'UConverter::getErrorCode()', $frame->vmContext);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(VmUConverter::getErrorCode($object));
    }
}

/** UConverter::transcode() — php-src ext/intl/converter/converter.c (#6401). */
final class UConverterTranscode extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('transcode');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 3) {
            throw new \ArgumentCountError(\sprintf(
                'UConverter::transcode() expects at least 3 arguments, %d given',
                $argc
            ));
        }
        if ($argc > 4) {
            throw new \ArgumentCountError(\sprintf(
                'UConverter::transcode() expects at most 4 arguments, %d given',
                $argc
            ));
        }
        $str = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'UConverter::transcode',
            0,
            'str'
        );
        $toEncoding = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[1],
            'UConverter::transcode',
            1,
            'toEncoding'
        );
        $fromEncoding = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[2],
            'UConverter::transcode',
            2,
            'fromEncoding'
        );
        $options = null;
        if (4 === $argc) {
            $optVar = $frame->calledArgs[3]->resolveIndirect();
            if (Variable::TYPE_NULL !== $optVar->type && Variable::TYPE_ARRAY !== $optVar->type) {
                throw new \TypeError(\sprintf(
                    'UConverter::transcode(): Argument #4 ($options) must be of type array, %s given',
                    ReflectionSupport::valueTypeLabelPublic($optVar)
                ));
            }
            if (Variable::TYPE_ARRAY === $optVar->type) {
                $options = [];
                foreach ($optVar->toArray()->iterateKeyed(true) as [$keyVar, $valueVar]) {
                    $keyVar = $keyVar->resolveIndirect();
                    $valueVar = $valueVar->resolveIndirect();
                    if (Variable::TYPE_STRING !== $keyVar->type || Variable::TYPE_STRING !== $valueVar->type) {
                        continue;
                    }
                    $options[$keyVar->toString()] = $valueVar->toString();
                }
            }
        }
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmUConverter::transcode($str, $toEncoding, $fromEncoding, $options);
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($result);
    }
}

/** UConverter::getErrorMessage() — php-src ext/intl/converter (#6171). */
final class UConverterGetErrorMessage extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getErrorMessage');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'UConverter::getErrorMessage() expects exactly 0 arguments, %d given',
                max(0, $argc - 1)
            ));
        }
        $object = VmUConverter::requireReceiver($frame->calledArgs[0], 'UConverter::getErrorMessage()', $frame->vmContext);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string(VmUConverter::getErrorMessage($object));
    }
}

/** UConverter::getSourceEncoding() — php-src ext/intl/converter (#20770). */
final class UConverterGetSourceEncoding extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getSourceEncoding');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'UConverter::getSourceEncoding() expects exactly 0 arguments, %d given',
                max(0, $argc - 1)
            ));
        }
        $object = VmUConverter::requireReceiver($frame->calledArgs[0], 'UConverter::getSourceEncoding()', $frame->vmContext);
        if (null === $frame->returnVar) {
            return;
        }
        $encoding = VmUConverter::getSourceEncoding($object);
        if (null === $encoding) {
            $frame->returnVar->null();

            return;
        }
        $frame->returnVar->string($encoding);
    }
}

/** UConverter::getDestinationEncoding() — php-src ext/intl/converter (#20770). */
final class UConverterGetDestinationEncoding extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getDestinationEncoding');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'UConverter::getDestinationEncoding() expects exactly 0 arguments, %d given',
                max(0, $argc - 1)
            ));
        }
        $object = VmUConverter::requireReceiver($frame->calledArgs[0], 'UConverter::getDestinationEncoding()', $frame->vmContext);
        if (null === $frame->returnVar) {
            return;
        }
        $encoding = VmUConverter::getDestinationEncoding($object);
        if (null === $encoding) {
            $frame->returnVar->null();

            return;
        }
        $frame->returnVar->string($encoding);
    }
}

/** UConverter::setSourceEncoding() — php-src ext/intl/converter (#20881). */
final class UConverterSetSourceEncoding extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('setSourceEncoding');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'UConverter::setSourceEncoding() expects exactly 1 argument, %d given',
                max(0, $argc - 1)
            ));
        }
        $object = VmUConverter::requireReceiver($frame->calledArgs[0], 'UConverter::setSourceEncoding()', $frame->vmContext);
        $encoding = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[1],
            'UConverter::setSourceEncoding',
            0,
            'encoding'
        );
        $ok = VmUConverter::setSourceEncoding($object, $encoding);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool($ok);
    }
}

/** UConverter::setDestinationEncoding() — php-src ext/intl/converter (#20881). */
final class UConverterSetDestinationEncoding extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('setDestinationEncoding');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'UConverter::setDestinationEncoding() expects exactly 1 argument, %d given',
                max(0, $argc - 1)
            ));
        }
        $object = VmUConverter::requireReceiver($frame->calledArgs[0], 'UConverter::setDestinationEncoding()', $frame->vmContext);
        $encoding = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[1],
            'UConverter::setDestinationEncoding',
            0,
            'encoding'
        );
        $ok = VmUConverter::setDestinationEncoding($object, $encoding);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool($ok);
    }
}

/** UConverter::getSubstChars() — php-src ext/intl/converter (#20770). */
final class UConverterGetSubstChars extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getSubstChars');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'UConverter::getSubstChars() expects exactly 0 arguments, %d given',
                max(0, $argc - 1)
            ));
        }
        $object = VmUConverter::requireReceiver($frame->calledArgs[0], 'UConverter::getSubstChars()', $frame->vmContext);
        if (null === $frame->returnVar) {
            return;
        }
        $chars = VmUConverter::getSubstChars($object);
        if (null === $chars) {
            $frame->returnVar->null();

            return;
        }
        $frame->returnVar->string($chars);
    }
}

/** UConverter::setSubstChars() — php-src ext/intl/converter (#20770). */
final class UConverterSetSubstChars extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('setSubstChars');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'UConverter::setSubstChars() expects exactly 1 argument, %d given',
                max(0, $argc - 1)
            ));
        }
        $object = VmUConverter::requireReceiver($frame->calledArgs[0], 'UConverter::setSubstChars()', $frame->vmContext);
        $chars = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[1],
            'UConverter::setSubstChars',
            0,
            'chars'
        );
        $ok = VmUConverter::setSubstChars($object, $chars);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool($ok);
    }
}

/** UConverter::reasonText() — php-src ext/intl/converter (#20770). */
final class UConverterReasonText extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('reasonText');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'UConverter::reasonText() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $reason = VmUConverter::coerceIntArg(
            $frame->calledArgs[0],
            'UConverter::reasonText',
            0,
            'reason'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string(VmUConverter::reasonText($reason));
    }
}

/** UConverter::getAvailable() — php-src / ICU ucnv_countAvailable (#20788). */
final class UConverterGetAvailable extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getAvailable');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (0 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'UConverter::getAvailable() expects exactly 0 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->array(VmUConverter::getAvailable());
    }
}

/** UConverter::getAliases() — php-src / ICU ucnv_countAliases (#20788). */
final class UConverterGetAliases extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getAliases');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'UConverter::getAliases() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $name = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'UConverter::getAliases', 0, 'name');
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmUConverter::getAliases($name);
        if (null === $result) {
            $frame->returnVar->null();

            return;
        }
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->array($result);
    }
}

/** UConverter::getStandards() — php-src / ICU ucnv_countStandards (#20788). */
final class UConverterGetStandards extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getStandards');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (0 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'UConverter::getStandards() expects exactly 0 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmUConverter::getStandards();
        if (null === $result) {
            $frame->returnVar->null();

            return;
        }
        $frame->returnVar->array($result);
    }
}

/** UConverter::getSourceType() — php-src / ICU ucnv_getType (#20788). */
final class UConverterGetSourceType extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getSourceType');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'UConverter::getSourceType() expects exactly 0 arguments, %d given',
                max(0, $argc - 1)
            ));
        }
        $object = VmUConverter::requireReceiver($frame->calledArgs[0], 'UConverter::getSourceType()', $frame->vmContext);
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmUConverter::getSourceType($object);
        if (null === $result) {
            $frame->returnVar->null();

            return;
        }
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->int($result);
    }
}

/** UConverter::getDestinationType() — php-src / ICU ucnv_getType (#20788). */
final class UConverterGetDestinationType extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getDestinationType');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'UConverter::getDestinationType() expects exactly 0 arguments, %d given',
                max(0, $argc - 1)
            ));
        }
        $object = VmUConverter::requireReceiver($frame->calledArgs[0], 'UConverter::getDestinationType()', $frame->vmContext);
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmUConverter::getDestinationType($object);
        if (null === $result) {
            $frame->returnVar->null();

            return;
        }
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->int($result);
    }
}
