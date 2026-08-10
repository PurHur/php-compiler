<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\ClassConstName;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\Variable;
use PHPCfg\Func as CfgFunc;

/**
 * IntlListFormatter — ICU ListFormatter via thin FFI (php-src ext/intl/listformatter; #23229).
 *
 * php-src: listformatter.stub.php / listformatter_class.cpp (GH-18519).
 * ICU: unicode/ulistformatter.h — ulistfmt_openForType / ulistfmt_format (ICU ≥67 typed API).
 * Advertisement: host php-intl + {@see CompilerVersion::advertisesIntlListFormatter()} (PROFILE≥8.5).
 */
final class VmIntlListFormatter
{
    public const CLASS_LC = 'intllistformatter';

    /** @see unicode/ulistformatter.h ULISTFMT_TYPE_* / fallback TYPE_AND */
    public const TYPE_AND = 0;
    public const TYPE_OR = 1;
    public const TYPE_UNITS = 2;

    /** @see unicode/ulistformatter.h ULISTFMT_WIDTH_* / fallback WIDTH_WIDE */
    public const WIDTH_WIDE = 0;
    public const WIDTH_SHORT = 1;
    public const WIDTH_NARROW = 2;

    /** php-src INTL_MAX_LOCALE_LEN */
    private const MAX_LOCALE_LEN = 156;

    /**
     * @var array<int, array{
     *   handle: object|null,
     *   locale: string,
     *   type: int,
     *   width: int,
     *   errorCode: int,
     *   errorMessage: string
     * }>
     */
    private static array $state = [];

    private static ?\FFI $ffi = null;

    private static string $symSuffix = '';

    private static bool $ffiUnavailable = false;

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC])) {
            return;
        }

        $entry = new ClassEntry('IntlListFormatter');
        $entry->isInternal = true;
        $entry->isFinal = true;
        // Exact Zend casing for defined()/hasConstant after #25910 (#30000 / #28132).
        foreach (self::classConstants() as $name => $value) {
            $key = ClassConstName::key($name);
            $const = new Variable(Variable::TYPE_INTEGER);
            $const->int($value);
            $entry->constants[$key] = $const;
            $entry->constNames[$key] = $name;
        }
        $pub = CfgFunc::FLAG_PUBLIC;
        $construct = new IntlListFormatterConstruct();
        $entry->constructor = $construct;
        $methods = [
            '__construct' => [$construct, $pub, '__construct'],
            'format' => [new IntlListFormatterFormat(), $pub, 'format'],
            'geterrorcode' => [new IntlListFormatterGetErrorCode(), $pub, 'getErrorCode'],
            'geterrormessage' => [new IntlListFormatterGetErrorMessage(), $pub, 'getErrorMessage'],
        ];
        foreach ($methods as $lc => [$handler, $vis, $name]) {
            $entry->methods[$lc] = $handler;
            $entry->methodVisibility[$lc] = $vis;
            $entry->methodNames[$lc] = $name;
        }
        $ctx->classes[self::CLASS_LC] = $entry;
    }

    /**
     * @return array<string, int>
     */
    public static function classConstants(): array
    {
        $consts = [
            'TYPE_AND' => self::TYPE_AND,
            'WIDTH_WIDE' => self::WIDTH_WIDE,
        ];
        if (self::supportsTypedListFormatter()) {
            $consts['TYPE_OR'] = self::TYPE_OR;
            $consts['TYPE_UNITS'] = self::TYPE_UNITS;
            $consts['WIDTH_SHORT'] = self::WIDTH_SHORT;
            $consts['WIDTH_NARROW'] = self::WIDTH_NARROW;
        }

        return $consts;
    }

    /** ICU ≥67 typed ListFormatter (php-src stub `#if U_ICU_VERSION_MAJOR_NUM >= 67`). */
    public static function supportsTypedListFormatter(): bool
    {
        return IntlExtensionPolicy::icuMajorVersion() >= 67
            || (0 === IntlExtensionPolicy::icuMajorVersion() && null !== self::ffi());
    }

    public static function isListFormatterObject(?ObjectEntry $object): bool
    {
        return null !== $object && self::CLASS_LC === strtolower($object->class->name);
    }

    /**
     * Shared construct path — php-src IntlListFormatter::__construct.
     *
     * @throws \ValueError|\IntlException|\Error
     */
    public static function initObject(ObjectEntry $object, string $locale, int $type, int $width): void
    {
        if ($object->constructed || isset(self::$state[$object->id])) {
            throw new \Error('IntlListFormatter object is already constructed');
        }
        if ('' === $locale) {
            $locale = VmLocale::getDefault();
        }
        if (\strlen($locale) > self::MAX_LOCALE_LEN) {
            throw new \ValueError(\sprintf(
                'IntlListFormatter::__construct(): Argument #1 ($locale) must be less than or equal to %d characters',
                self::MAX_LOCALE_LEN
            ));
        }
        if (!self::isValidLocaleLanguage($locale)) {
            throw new \ValueError(\sprintf(
                'IntlListFormatter::__construct(): Argument #1 ($locale) "%s" is invalid',
                $locale
            ));
        }
        if (self::supportsTypedListFormatter()) {
            if (self::TYPE_AND !== $type && self::TYPE_OR !== $type && self::TYPE_UNITS !== $type) {
                throw new \ValueError(
                    'IntlListFormatter::__construct(): Argument #2 ($type) must be one of IntlListFormatter::TYPE_AND, IntlListFormatter::TYPE_OR, or IntlListFormatter::TYPE_UNITS'
                );
            }
            if (self::WIDTH_WIDE !== $width && self::WIDTH_SHORT !== $width && self::WIDTH_NARROW !== $width) {
                throw new \ValueError(
                    'IntlListFormatter::__construct(): Argument #3 ($width) must be one of IntlListFormatter::WIDTH_WIDE, IntlListFormatter::WIDTH_SHORT, or IntlListFormatter::WIDTH_NARROW'
                );
            }
        } else {
            if (self::TYPE_AND !== $type) {
                throw new \ValueError(
                    'IntlListFormatter::__construct(): Argument #2 ($type) contains an unsupported type. ICU 66 and below only support IntlListFormatter::TYPE_AND'
                );
            }
            if (self::WIDTH_WIDE !== $width) {
                throw new \ValueError(
                    'IntlListFormatter::__construct(): Argument #3 ($width) contains an unsupported width. ICU 66 and below only support IntlListFormatter::WIDTH_WIDE'
                );
            }
        }

        $handle = self::openFormatter($locale, $type, $width);
        if (null === $handle) {
            IntlError::set(
                IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                'Constructor failed'
            );
            throw new \IntlException('Constructor failed');
        }

        $object->constructed = true;
        self::$state[$object->id] = [
            'handle' => $handle,
            'locale' => $locale,
            'type' => $type,
            'width' => $width,
            'errorCode' => IntlError::U_ZERO_ERROR,
            'errorMessage' => 'U_ZERO_ERROR',
        ];
        IntlError::clear();
    }

    /**
     * @param list<string> $strings
     */
    public static function format(ObjectEntry $object, array $strings): string|false
    {
        if (!isset(self::$state[$object->id])) {
            self::setObjectError(
                $object,
                IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                'Failed to format list: U_ILLEGAL_ARGUMENT_ERROR'
            );

            return false;
        }
        self::clearObjectError($object);
        if ([] === $strings) {
            return '';
        }
        $handle = self::$state[$object->id]['handle'];
        if (null === $handle) {
            self::setObjectError(
                $object,
                IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                'Failed to format list: U_ILLEGAL_ARGUMENT_ERROR'
            );

            return false;
        }
        $result = self::formatWithHandle($handle, $strings);
        if (null === $result) {
            $code = IntlError::getCode();
            $msg = IntlError::getMessage();
            if (IntlError::U_ZERO_ERROR === $code) {
                $code = IntlError::U_ILLEGAL_ARGUMENT_ERROR;
                $msg = 'Failed to format list';
            }
            self::setObjectError($object, $code, $msg);

            return false;
        }

        return $result;
    }

    public static function getErrorCode(ObjectEntry $object): int
    {
        return self::$state[$object->id]['errorCode'] ?? IntlError::U_ZERO_ERROR;
    }

    public static function getErrorMessage(ObjectEntry $object): string
    {
        return self::$state[$object->id]['errorMessage'] ?? 'U_ZERO_ERROR';
    }

    public static function coerceLocaleArg(Variable $var, string $function, int $position): string
    {
        return VmString::coerceStringBuiltinArg($var, $function, $position, 'locale');
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
        if (Variable::TYPE_NULL === $var->type || Variable::TYPE_UNDEFINED === $var->type) {
            return 0;
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

    public static function coerceStringList(Variable $var, Frame $frame, string $function, int $position): array
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $var->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($strings) must be of type array, %s given',
                $function,
                $position + 1,
                ReflectionSupport::valueTypeLabelPublic($var)
            ));
        }
        /** @var HashTable $ht */
        $ht = $var->toArray();
        $vm = $frame->vmContext?->runtime?->vm;
        $out = [];
        foreach ($ht->iterate(true) as $item) {
            if (null !== $vm) {
                $out[] = $vm->coerceVariableToString($item, $frame);
            } else {
                $out[] = $item->toString();
            }
        }

        return $out;
    }

    private static function isValidLocaleLanguage(string $locale): bool
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            // Without ICU, accept BCP-47-ish tags so construct can still fail at open.
            return 1 === preg_match('/^[A-Za-z]/', $locale);
        }
        $fn = 'uloc_getISO3Language'.self::$symSuffix;
        try {
            $iso3 = $ffi->$fn($locale);

            return \is_string($iso3) && '' !== $iso3;
        } catch (\Throwable) {
            return 1 === preg_match('/^[A-Za-z]/', $locale);
        }
    }

    /** @return object|null FFI UListFormatter* */
    private static function openFormatter(string $locale, int $type, int $width): ?object
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return null;
        }
        $open = 'ulistfmt_openForType'.self::$symSuffix;
        try {
            $status = \FFI::new('int32_t');
            $status->cdata = 0;
            $handle = $ffi->$open($locale, $type, $width, \FFI::addr($status));
            // U_FAILURE: status > 0 (warnings are negative).
            if (null === $handle || $status->cdata > 0) {
                return null;
            }

            return $handle;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param list<string> $strings
     */
    private static function formatWithHandle(object $handle, array $strings): ?string
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return null;
        }
        $fromUtf8 = 'u_strFromUTF8'.self::$symSuffix;
        $toUtf8 = 'u_strToUTF8'.self::$symSuffix;
        $format = 'ulistfmt_format'.self::$symSuffix;
        $n = \count($strings);
        try {
            $ptrs = $ffi->new('uint16_t*['.$n.']');
            $lens = $ffi->new('int32_t['.$n.']');
            $keep = [];
            for ($i = 0; $i < $n; ++$i) {
                $u = self::utf8ToUChar($strings[$i]);
                if (null === $u) {
                    IntlError::set(
                        IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                        'Failed to convert string to UTF-16'
                    );

                    return null;
                }
                [$buf, $len] = $u;
                $keep[] = $buf;
                $ptrs[$i] = $ffi->cast('uint16_t*', $buf);
                $lens[$i] = $len;
            }
            $status = \FFI::new('int32_t');
            $status->cdata = 0;
            $needed = $ffi->$format($handle, $ptrs, $lens, $n, null, 0, \FFI::addr($status));
            // Expected U_BUFFER_OVERFLOW_ERROR (-124) when dest is null.
            $status->cdata = 0;
            $cap = max(1, (int) $needed + 1);
            $res = $ffi->new('uint16_t['.$cap.']');
            $len = $ffi->$format($handle, $ptrs, $lens, $n, $res, $cap, \FFI::addr($status));
            if ($status->cdata > 0) {
                IntlError::set((int) $status->cdata, 'Failed to format list');

                return null;
            }
            $outLen = \FFI::new('int32_t');
            $outStatus = \FFI::new('int32_t');
            $outStatus->cdata = 0;
            $ffi->$toUtf8(null, 0, \FFI::addr($outLen), $res, $len, \FFI::addr($outStatus));
            $outStatus->cdata = 0;
            $outCap = max(1, (int) $outLen->cdata + 1);
            $out = $ffi->new('char['.$outCap.']');
            $ffi->$toUtf8($out, $outCap, \FFI::addr($outLen), $res, $len, \FFI::addr($outStatus));
            if ($outStatus->cdata > 0) {
                IntlError::set((int) $outStatus->cdata, 'Failed to convert result to UTF-8');

                return null;
            }

            return \FFI::string($out, (int) $outLen->cdata);
        } catch (\Throwable) {
            IntlError::set(IntlError::U_ILLEGAL_ARGUMENT_ERROR, 'Failed to format list');

            return null;
        }
    }

    /** @return array{0: object, 1: int}|null */
    private static function utf8ToUChar(string $utf8): ?array
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return null;
        }
        $fromUtf8 = 'u_strFromUTF8'.self::$symSuffix;
        try {
            $len = \FFI::new('int32_t');
            $status = \FFI::new('int32_t');
            $status->cdata = 0;
            $ffi->$fromUtf8(null, 0, \FFI::addr($len), $utf8, \strlen($utf8), \FFI::addr($status));
            $status->cdata = 0;
            $cap = max(1, $len->cdata + 1);
            $buf = \FFI::new('uint16_t['.$cap.']');
            $ffi->$fromUtf8($buf, $cap, \FFI::addr($len), $utf8, \strlen($utf8), \FFI::addr($status));
            if ($status->cdata > 0) {
                return null;
            }

            return [$buf, (int) $len->cdata];
        } catch (\Throwable) {
            return null;
        }
    }

    private static function setObjectError(ObjectEntry $object, int $code, string $message): void
    {
        if (isset(self::$state[$object->id])) {
            self::$state[$object->id]['errorCode'] = $code;
            self::$state[$object->id]['errorMessage'] = $message;
        }
        IntlError::set($code, $message);
    }

    private static function clearObjectError(ObjectEntry $object): void
    {
        if (isset(self::$state[$object->id])) {
            self::$state[$object->id]['errorCode'] = IntlError::U_ZERO_ERROR;
            self::$state[$object->id]['errorMessage'] = 'U_ZERO_ERROR';
        }
        IntlError::clear();
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
        $candidates = [
            ['libicui18n.so.74', '_74'],
            ['libicui18n.so.72', '_72'],
            ['libicui18n.so.71', '_71'],
            ['libicui18n.so.70', '_70'],
            ['/usr/lib/x86_64-linux-gnu/libicui18n.so.74', '_74'],
            ['/usr/lib/x86_64-linux-gnu/libicui18n.so.70', '_70'],
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
typedef struct UListFormatter UListFormatter;
UListFormatter *ulistfmt_openForType{$suffix}(const char *locale, int32_t type, int32_t width, UErrorCode *status);
void ulistfmt_close{$suffix}(UListFormatter *listfmt);
int32_t ulistfmt_format{$suffix}(const UListFormatter *listfmt, const UChar *const strings[], const int32_t *stringLengths, int32_t stringCount, UChar *result, int32_t resultCapacity, UErrorCode *status);
UChar *u_strFromUTF8{$suffix}(UChar *dest, int32_t destCapacity, int32_t *pDestLength, const char *src, int32_t srcLength, UErrorCode *pErrorCode);
char *u_strToUTF8{$suffix}(char *dest, int32_t destCapacity, int32_t *pDestLength, const UChar *src, int32_t srcLength, UErrorCode *pErrorCode);
const char *uloc_getISO3Language{$suffix}(const char *locale);
C;
    }
}

/** IntlListFormatter::__construct() — php-src listformatter_class.cpp (#23229). */
final class IntlListFormatterConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 4) {
            throw new \ArgumentCountError(\sprintf(
                'IntlListFormatter::__construct() expects between 1 and 3 arguments, %d given',
                max(0, $argc - 1)
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmIntlListFormatter::isListFormatterObject($receiver->toObject())) {
            throw new \Error('IntlListFormatter::__construct() called on incompatible object');
        }
        $locale = VmIntlListFormatter::coerceLocaleArg(
            $frame->calledArgs[1],
            'IntlListFormatter::__construct',
            0
        );
        $type = VmIntlListFormatter::TYPE_AND;
        $width = VmIntlListFormatter::WIDTH_WIDE;
        if ($argc >= 3) {
            $type = VmIntlListFormatter::coerceIntArg(
                $frame->calledArgs[2],
                'IntlListFormatter::__construct',
                1,
                'type'
            );
        }
        if ($argc >= 4) {
            $width = VmIntlListFormatter::coerceIntArg(
                $frame->calledArgs[3],
                'IntlListFormatter::__construct',
                2,
                'width'
            );
        }
        VmIntlListFormatter::initObject($receiver->toObject(), $locale, $type, $width);
    }
}

/** IntlListFormatter::format() — php-src listformatter_class.cpp (#23229). */
final class IntlListFormatterFormat extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('format');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'IntlListFormatter::format() expects exactly 1 argument, %d given',
                max(0, $argc - 1)
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmIntlListFormatter::isListFormatterObject($receiver->toObject())) {
            throw new \Error('IntlListFormatter::format() called on incompatible object');
        }
        $strings = VmIntlListFormatter::coerceStringList(
            $frame->calledArgs[1],
            $frame,
            'IntlListFormatter::format',
            0
        );
        if (null === $frame->returnVar) {
            return;
        }
        $result = VmIntlListFormatter::format($receiver->toObject(), $strings);
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($result);
    }
}

/** IntlListFormatter::getErrorCode() — php-src listformatter_class.cpp (#23229). */
final class IntlListFormatterGetErrorCode extends VmClassMethod
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
                'IntlListFormatter::getErrorCode() expects exactly 0 arguments, %d given',
                max(0, $argc - 1)
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmIntlListFormatter::isListFormatterObject($receiver->toObject())) {
            throw new \Error('IntlListFormatter::getErrorCode() called on incompatible object');
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->int(VmIntlListFormatter::getErrorCode($receiver->toObject()));
        }
    }
}

/** IntlListFormatter::getErrorMessage() — php-src listformatter_class.cpp (#23229). */
final class IntlListFormatterGetErrorMessage extends VmClassMethod
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
                'IntlListFormatter::getErrorMessage() expects exactly 0 arguments, %d given',
                max(0, $argc - 1)
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmIntlListFormatter::isListFormatterObject($receiver->toObject())) {
            throw new \Error('IntlListFormatter::getErrorMessage() called on incompatible object');
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->string(VmIntlListFormatter::getErrorMessage($receiver->toObject()));
        }
    }
}
