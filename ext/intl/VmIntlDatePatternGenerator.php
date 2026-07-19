<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPCfg\Func as CfgFunc;

/**
 * IntlDatePatternGenerator — ICU DateTimePatternGenerator (php-src datepatterngenerator_*; #20740).
 *
 * Thin udatpg_* FFI when libicui18n is present; static CLDR-ish fallback for common skeletons
 * when FFI/ICU is unavailable so PROFILE=8.4 still advertises the PHP 8.1+ class.
 */
final class VmIntlDatePatternGenerator
{
    public const CLASS_LC = 'intldatepatterngenerator';

    /** @var array<int, array{locale: string}> */
    private static array $state = [];

    private static ?\FFI $ffi = null;
    private static string $symSuffix = '_70';
    private static bool $ffiUnavailable = false;

    /**
     * Documented fallback for CI hosts without libicui18n — matches ICU 70 en_US/de_DE samples.
     *
     * @var array<string, array<string, string>>
     */
    private const FALLBACK = [
        'en_us' => [
            'yMMMd' => 'MMM d, y',
            'yMd' => 'M/d/y',
            'Hm' => 'HH:mm',
            'yMMMEd' => 'EEE, MMM d, y',
            'MMMMd' => 'MMMM d',
        ],
        'en' => [
            'yMMMd' => 'MMM d, y',
            'yMd' => 'M/d/y',
            'Hm' => 'HH:mm',
        ],
        'de_de' => [
            'yMMMd' => 'd. MMM y',
            'yMd' => 'd.M.y',
            'Hm' => 'HH:mm',
        ],
        'de' => [
            'yMMMd' => 'd. MMM y',
            'yMd' => 'd.M.y',
            'Hm' => 'HH:mm',
        ],
    ];

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC])) {
            return;
        }

        $entry = new ClassEntry('IntlDatePatternGenerator');
        $entry->isInternal = true;
        $pub = CfgFunc::FLAG_PUBLIC;
        $pubStatic = $pub | CfgFunc::FLAG_STATIC;
        $construct = new IntlDatePatternGeneratorConstruct();
        $entry->constructor = $construct;
        $methods = [
            '__construct' => [$construct, $pub, '__construct'],
            'create' => [new IntlDatePatternGeneratorCreate(), $pubStatic, 'create'],
            'getbestpattern' => [new IntlDatePatternGeneratorGetBestPattern(), $pub, 'getBestPattern'],
        ];
        foreach ($methods as $lc => [$handler, $vis, $name]) {
            $entry->methods[$lc] = $handler;
            $entry->methodVisibility[$lc] = $vis;
            $entry->methodNames[$lc] = $name;
        }
        $ctx->classes[self::CLASS_LC] = $entry;
    }

    public static function isGeneratorObject(?ObjectEntry $object): bool
    {
        return null !== $object && self::CLASS_LC === strtolower($object->class->name);
    }

    public static function create(Context $ctx, ?string $locale): ObjectEntry
    {
        if (!isset($ctx->classes[self::CLASS_LC])) {
            throw new \Error('Class "IntlDatePatternGenerator" not found');
        }
        $object = new ObjectEntry($ctx->classes[self::CLASS_LC]);
        self::initState($object, $locale);
        $object->constructed = true;
        IntlError::clear();

        return $object;
    }

    public static function initState(ObjectEntry $object, ?string $locale): void
    {
        $resolved = null !== $locale && '' !== $locale ? $locale : VmLocale::getDefault();
        self::$state[$object->id] = ['locale' => $resolved];
    }

    public static function getBestPattern(ObjectEntry $object, string $skeleton): string|false
    {
        $state = self::$state[$object->id] ?? null;
        if (null === $state) {
            IntlError::set(
                IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                'datepatterngenerator_get_best_pattern: bad object: U_ILLEGAL_ARGUMENT_ERROR'
            );

            return false;
        }
        if ('' === $skeleton) {
            IntlError::set(
                IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                'datepatterngenerator_get_best_pattern: empty skeleton: U_ILLEGAL_ARGUMENT_ERROR'
            );

            return false;
        }
        $viaIcu = self::bestPatternIcu($state['locale'], $skeleton);
        if (null !== $viaIcu) {
            IntlError::clear();

            return $viaIcu;
        }
        $fallback = self::bestPatternFallback($state['locale'], $skeleton);
        if (null !== $fallback) {
            IntlError::clear();

            return $fallback;
        }
        IntlError::set(
            IntlError::U_UNSUPPORTED_ERROR,
            'datepatterngenerator_get_best_pattern: skeleton not in fallback table: U_UNSUPPORTED_ERROR'
        );

        return false;
    }

    private static function bestPatternFallback(string $locale, string $skeleton): ?string
    {
        $key = strtolower(str_replace('-', '_', $locale));
        if (isset(self::FALLBACK[$key][$skeleton])) {
            return self::FALLBACK[$key][$skeleton];
        }
        $lang = explode('_', $key)[0];
        if (isset(self::FALLBACK[$lang][$skeleton])) {
            return self::FALLBACK[$lang][$skeleton];
        }
        if (isset(self::FALLBACK['en_us'][$skeleton])) {
            return self::FALLBACK['en_us'][$skeleton];
        }

        return null;
    }

    private static function bestPatternIcu(string $locale, string $skeleton): ?string
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return null;
        }
        $suffix = self::$symSuffix;
        $open = 'udatpg_open'.$suffix;
        $close = 'udatpg_close'.$suffix;
        $best = 'udatpg_getBestPattern'.$suffix;
        $fromUtf8 = 'u_strFromUTF8'.$suffix;
        $toUtf8 = 'u_strToUTF8'.$suffix;
        try {
            $status = \FFI::new('int32_t');
            $status->cdata = 0;
            $gen = $ffi->$open($locale, \FFI::addr($status));
            // U_ZERO_ERROR (0) or U_USING_DEFAULT_WARNING (-127) are acceptable.
            if (null === $gen || ($status->cdata > 0)) {
                return null;
            }
            $ulen = \FFI::new('int32_t');
            $ubuf = \FFI::new('uint16_t[64]');
            $st2 = \FFI::new('int32_t');
            $st2->cdata = 0;
            $ffi->$fromUtf8($ubuf, 64, \FFI::addr($ulen), $skeleton, \strlen($skeleton), \FFI::addr($st2));
            if ($st2->cdata > 0) {
                $ffi->$close($gen);

                return null;
            }
            $out = \FFI::new('uint16_t[128]');
            $st3 = \FFI::new('int32_t');
            $st3->cdata = 0;
            $n = (int) $ffi->$best($gen, $ubuf, $ulen->cdata, $out, 128, \FFI::addr($st3));
            if ($st3->cdata > 0 || $n <= 0) {
                $ffi->$close($gen);

                return null;
            }
            $utf8len = \FFI::new('int32_t');
            $cbuf = \FFI::new('char[256]');
            $st4 = \FFI::new('int32_t');
            $st4->cdata = 0;
            $ffi->$toUtf8($cbuf, 256, \FFI::addr($utf8len), $out, $n, \FFI::addr($st4));
            $ffi->$close($gen);
            if ($st4->cdata > 0 || $utf8len->cdata <= 0) {
                return null;
            }

            return \FFI::string($cbuf, $utf8len->cdata);
        } catch (\Throwable) {
            return null;
        }
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
            ['/lib/x86_64-linux-gnu/libicui18n.so.70', '_70'],
            ['/usr/lib/x86_64-linux-gnu/libicui18n.so.70', '_70'],
            ['/lib/x86_64-linux-gnu/libicui18n.so.74', '_74'],
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
typedef struct UDateTimePatternGenerator UDateTimePatternGenerator;
UDateTimePatternGenerator *udatpg_open{$suffix}(const char *locale, UErrorCode *status);
void udatpg_close{$suffix}(UDateTimePatternGenerator *dtpg);
int32_t udatpg_getBestPattern{$suffix}(UDateTimePatternGenerator *dtpg, const UChar *skeleton, int32_t length, UChar *bestPattern, int32_t capacity, UErrorCode *status);
UChar *u_strFromUTF8{$suffix}(UChar *dest, int32_t destCapacity, int32_t *pDestLength, const char *src, int32_t srcLength, UErrorCode *pErrorCode);
char *u_strToUTF8{$suffix}(char *dest, int32_t destCapacity, int32_t *pDestLength, const UChar *src, int32_t srcLength, UErrorCode *pErrorCode);
C;
    }
}

/** IntlDatePatternGenerator::__construct(?string $locale = null) — php-src (#20740). */
final class IntlDatePatternGeneratorConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        $userArgc = max(0, $argc - 1);
        if ($userArgc > 1) {
            throw new \ArgumentCountError(\sprintf(
                'IntlDatePatternGenerator::__construct() expects at most 1 argument, %d given',
                $userArgc
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmIntlDatePatternGenerator::isGeneratorObject($receiver->toObject())) {
            throw new \Error('IntlDatePatternGenerator::__construct() called on incompatible object');
        }
        $locale = null;
        if ($argc >= 2) {
            $localeVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_NULL !== $localeVar->type) {
                $locale = VmString::coerceStringBuiltinArg(
                    $localeVar,
                    'IntlDatePatternGenerator::__construct',
                    1,
                    'locale'
                );
            }
        }
        VmIntlDatePatternGenerator::initState($receiver->toObject(), $locale);
        $receiver->toObject()->constructed = true;
        IntlError::clear();
    }
}

/** IntlDatePatternGenerator::create(?string $locale = null) — php-src (#20740). */
final class IntlDatePatternGeneratorCreate extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('create');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 1) {
            throw new \ArgumentCountError(\sprintf(
                'IntlDatePatternGenerator::create() expects at most 1 argument, %d given',
                $argc
            ));
        }
        $locale = null;
        if ($argc >= 1) {
            $localeVar = $frame->calledArgs[0]->resolveIndirect();
            if (Variable::TYPE_NULL !== $localeVar->type) {
                $locale = VmString::coerceStringBuiltinArg(
                    $localeVar,
                    'IntlDatePatternGenerator::create',
                    1,
                    'locale'
                );
            }
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->object(VmIntlDatePatternGenerator::create($frame->vmContext, $locale));
    }
}

/** IntlDatePatternGenerator::getBestPattern(string $skeleton) — php-src (#20740). */
final class IntlDatePatternGeneratorGetBestPattern extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getBestPattern');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'IntlDatePatternGenerator::getBestPattern() expects exactly 1 argument, %d given',
                max(0, $argc - 1)
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmIntlDatePatternGenerator::isGeneratorObject($receiver->toObject())) {
            throw new \Error('IntlDatePatternGenerator::getBestPattern() called on incompatible object');
        }
        $skeleton = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[1],
            'IntlDatePatternGenerator::getBestPattern',
            1,
            'skeleton'
        );
        $pattern = VmIntlDatePatternGenerator::getBestPattern($receiver->toObject(), $skeleton);
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $pattern) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($pattern);
    }
}
