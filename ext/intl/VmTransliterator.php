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
 * Transliterator create/transliterate — ICU utrans_* via FFI + Latin-ASCII PHP fallback (#6139).
 *
 * php-src: ext/intl/transliterator/transliterator_class.c, transliterator_methods.c
 * ICU: unicode/utrans.h — versioned utrans_openU_N / utrans_transUChars_N / utrans_close_N
 */
final class VmTransliterator
{
    public const CLASS_LC = 'transliterator';

    public const FORWARD = 0;
    public const REVERSE = 1;

    /** @var array<int, array{id: string, handle: object|null, use_fallback: bool}> */
    private static array $state = [];

    private static ?\FFI $ffi = null;

    private static string $symSuffix = '';

    private static bool $ffiUnavailable = false;

    /** @return array<string, int> */
    public static function classConstants(): array
    {
        return [
            'FORWARD' => self::FORWARD,
            'REVERSE' => self::REVERSE,
        ];
    }

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC])) {
            return;
        }

        $entry = new ClassEntry('Transliterator');
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
        $entry->methods['create'] = new TransliteratorCreate();
        $entry->methodVisibility['create'] = $pubStatic;
        $entry->methodNames['create'] = 'create';
        $entry->methods['transliterate'] = new TransliteratorTransliterate();
        $entry->methodVisibility['transliterate'] = $pub;
        $entry->methodNames['transliterate'] = 'transliterate';
        $ctx->classes[self::CLASS_LC] = $entry;
    }

    public static function isTransliteratorObject(?ObjectEntry $object): bool
    {
        return null !== $object && self::CLASS_LC === strtolower($object->class->name);
    }

    /**
     * @return ObjectEntry|null null + intl error for unknown IDs (php-src returns null)
     */
    public static function create(Context $ctx, string $id, int $direction = self::FORWARD): ?ObjectEntry
    {
        if (!isset($ctx->classes[self::CLASS_LC])) {
            throw new \Error('Class "Transliterator" not found');
        }
        if ('' === $id) {
            IntlError::set(
                IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                'transliterator_create: id is empty: U_ILLEGAL_ARGUMENT_ERROR'
            );

            return null;
        }
        $handle = self::openTransliterator($id, $direction);
        $fallback = null === $handle && self::supportsFallbackId($id);
        if (null === $handle && !$fallback) {
            IntlError::set(
                IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                'transliterator_create: unable to open transliterator with ID "'.$id.'": U_INVALID_ID'
            );

            return null;
        }
        $object = new ObjectEntry($ctx->classes[self::CLASS_LC]);
        $object->constructed = true;
        self::$state[$object->id] = [
            'id' => $id,
            'handle' => $handle,
            'use_fallback' => $fallback,
        ];
        if ($fallback) {
            IntlError::set(
                IntlError::U_USING_FALLBACK_WARNING,
                'transliterator_create: ICU unavailable; using Latin-ASCII PHP fallback: U_USING_DEFAULT_WARNING'
            );
        } elseif (IntlError::U_ZERO_ERROR === IntlError::getCode()) {
            IntlError::clear();
        }

        return $object;
    }

    /**
     * @return string|false
     */
    public static function transliterate(ObjectEntry $tr, string $subject, int $start = 0, int $end = -1)
    {
        $state = self::$state[$tr->id] ?? null;
        if (null === $state) {
            IntlError::set(
                IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                'transliterator_transliterate: bad transliterator: U_ILLEGAL_ARGUMENT_ERROR'
            );

            return false;
        }
        $len = \strlen($subject);
        if ($start < 0) {
            $start = 0;
        }
        if ($end < 0 || $end > $len) {
            $end = $len;
        }
        if ($start > $end) {
            IntlError::clear();

            return $subject;
        }
        $prefix = substr($subject, 0, $start);
        $middle = substr($subject, $start, $end - $start);
        $suffix = substr($subject, $end);
        IntlError::clear();
        if (null !== $state['handle']) {
            $converted = self::transUChars($state['handle'], $middle);
            if (null === $converted) {
                return false;
            }

            return $prefix.$converted.$suffix;
        }
        if ($state['use_fallback']) {
            return $prefix.self::fallbackLatinAscii($middle).$suffix;
        }

        return false;
    }

    public static function coerceIdArg(Variable $var, string $function, int $position): string
    {
        return VmString::coerceStringBuiltinArg($var, $function, $position, 'id');
    }

    public static function coerceSubjectArg(Variable $var, string $function, int $position): string
    {
        return VmString::coerceStringBuiltinArg($var, $function, $position, 'string');
    }

    public static function coerceDirectionArg(Variable $var, string $function, int $position): int
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_INTEGER === $var->type) {
            return $var->toInt();
        }
        if (Variable::TYPE_NULL === $var->type) {
            return self::FORWARD;
        }
        if (Variable::TYPE_BOOLEAN === $var->type) {
            return $var->toBool() ? 1 : 0;
        }
        throw new \TypeError(\sprintf(
            '%s(): Argument #%d ($direction) must be of type int, %s given',
            $function,
            $position + 1,
            \PHPCompiler\VM\ReflectionSupport::valueTypeLabelPublic($var)
        ));
    }

    private static function supportsFallbackId(string $id): bool
    {
        $norm = strtolower(str_replace(' ', '', $id));

        return 'any-latin;latin-ascii' === $norm
            || 'latin-ascii' === $norm
            || 'any-latin' === $norm
            || 'nfd;[:nonspacing mark:]remove;nfc' === $norm;
    }

    private static function fallbackLatinAscii(string $subject): string
    {
        if (\function_exists('iconv')) {
            $out = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $subject);
            if (false !== $out) {
                return $out;
            }
        }
        // Strip combining marks after NFD-ish decompose of common Latin.
        $map = [
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a',
            'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
            'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
            'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
            'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
            'ý' => 'y', 'ÿ' => 'y', 'ñ' => 'n', 'ç' => 'c',
            'À' => 'A', 'Á' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A', 'Å' => 'A',
            'È' => 'E', 'É' => 'E', 'Ê' => 'E', 'Ë' => 'E',
            'Ì' => 'I', 'Í' => 'I', 'Î' => 'I', 'Ï' => 'I',
            'Ò' => 'O', 'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ö' => 'O',
            'Ù' => 'U', 'Ú' => 'U', 'Û' => 'U', 'Ü' => 'U',
            'Ý' => 'Y', 'Ñ' => 'N', 'Ç' => 'C',
        ];

        return strtr($subject, $map);
    }

    /** @return object|null FFI CData UTransliterator* */
    private static function openTransliterator(string $id, int $direction): ?object
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return null;
        }
        $open = 'utrans_openU'.self::$symSuffix;
        $close = 'utrans_close'.self::$symSuffix;
        try {
            $status = $ffi->new('UErrorCode');
            $status->cdata = 0;
            $uId = self::utf8ToUChars($ffi, $id);
            if (null === $uId) {
                return null;
            }
            $dir = 0 === $direction ? 0 : 1; // UTRANS_FORWARD / UTRANS_REVERSE
            $trans = $ffi->$open(
                $uId,
                -1,
                $dir,
                null,
                -1,
                null,
                \FFI::addr($status)
            );
            $code = (int) $status->cdata;
            if (null === $trans || $code > 0) {
                if (null !== $trans) {
                    $ffi->$close($trans);
                }

                return null;
            }

            return $trans;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param object $handle */
    private static function transUChars(object $handle, string $utf8): ?string
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return null;
        }
        $fn = 'utrans_transUChars'.self::$symSuffix;
        try {
            $buf = self::utf8ToUChars($ffi, $utf8);
            if (null === $buf) {
                return null;
            }
            $uLen = self::uCharLen($buf);
            $capacity = max($uLen * 4 + 16, 64);
            $text = $ffi->new('UChar['.$capacity.']');
            for ($i = 0; $i < $uLen; ++$i) {
                $text[$i] = $buf[$i];
            }
            $textLength = $ffi->new('int32_t');
            $textLength->cdata = $uLen;
            $limit = $ffi->new('int32_t');
            $limit->cdata = $uLen;
            $status = $ffi->new('UErrorCode');
            $status->cdata = 0;
            $ffi->$fn(
                $handle,
                $text,
                \FFI::addr($textLength),
                $capacity,
                0,
                \FFI::addr($limit),
                \FFI::addr($status)
            );
            if ((int) $status->cdata > 0) {
                return null;
            }

            return self::uCharsToUtf8($text, (int) $textLength->cdata);
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return \FFI\CData|null UChar[] */
    private static function utf8ToUChars(\FFI $ffi, string $utf8): ?object
    {
        $codes = [];
        $len = \strlen($utf8);
        $i = 0;
        while ($i < $len) {
            $c = \ord($utf8[$i]);
            if ($c < 0x80) {
                $cp = $c;
                ++$i;
            } elseif (($c & 0xE0) === 0xC0 && $i + 1 < $len) {
                $cp = (($c & 0x1F) << 6) | (\ord($utf8[$i + 1]) & 0x3F);
                $i += 2;
            } elseif (($c & 0xF0) === 0xE0 && $i + 2 < $len) {
                $cp = (($c & 0x0F) << 12) | ((\ord($utf8[$i + 1]) & 0x3F) << 6) | (\ord($utf8[$i + 2]) & 0x3F);
                $i += 3;
            } elseif (($c & 0xF8) === 0xF0 && $i + 3 < $len) {
                $cp = (($c & 0x07) << 18) | ((\ord($utf8[$i + 1]) & 0x3F) << 12)
                    | ((\ord($utf8[$i + 2]) & 0x3F) << 6) | (\ord($utf8[$i + 3]) & 0x3F);
                $i += 4;
            } else {
                $cp = 0xFFFD;
                ++$i;
            }
            if ($cp > 0xFFFF) {
                $cp -= 0x10000;
                $codes[] = 0xD800 | ($cp >> 10);
                $codes[] = 0xDC00 | ($cp & 0x3FF);
            } else {
                $codes[] = $cp;
            }
        }
        $n = \count($codes);
        $arr = $ffi->new('UChar['.($n + 1).']');
        for ($j = 0; $j < $n; ++$j) {
            $arr[$j] = $codes[$j];
        }
        $arr[$n] = 0;

        return $arr;
    }

    /** @param \FFI\CData $buf */
    private static function uCharLen(object $buf): int
    {
        $n = 0;
        while (0 !== (int) $buf[$n]) {
            ++$n;
            if ($n > 1_000_000) {
                break;
            }
        }

        return $n;
    }

    /** @param \FFI\CData $buf */
    private static function uCharsToUtf8(object $buf, int $len): string
    {
        $out = '';
        for ($i = 0; $i < $len; ++$i) {
            $u = (int) $buf[$i];
            if ($u >= 0xD800 && $u <= 0xDBFF && $i + 1 < $len) {
                $low = (int) $buf[$i + 1];
                if ($low >= 0xDC00 && $low <= 0xDFFF) {
                    $cp = 0x10000 + ((($u - 0xD800) << 10) | ($low - 0xDC00));
                    ++$i;
                    $out .= self::chrUtf8($cp);
                    continue;
                }
            }
            $out .= self::chrUtf8($u);
        }

        return $out;
    }

    private static function chrUtf8(int $cp): string
    {
        if ($cp < 0x80) {
            return \chr($cp);
        }
        if ($cp < 0x800) {
            return \chr(0xC0 | ($cp >> 6)).\chr(0x80 | ($cp & 0x3F));
        }
        if ($cp < 0x10000) {
            return \chr(0xE0 | ($cp >> 12)).\chr(0x80 | (($cp >> 6) & 0x3F)).\chr(0x80 | ($cp & 0x3F));
        }

        return \chr(0xF0 | ($cp >> 18))
            .\chr(0x80 | (($cp >> 12) & 0x3F))
            .\chr(0x80 | (($cp >> 6) & 0x3F))
            .\chr(0x80 | ($cp & 0x3F));
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
            ['libicui18n.so.70', '_70'],
            ['libicui18n.so.72', '_72'],
            ['libicui18n.so.71', '_71'],
            ['libicui18n.so', '_74'],
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
typedef struct UTransliterator UTransliterator;
UTransliterator *utrans_openU{$suffix}(const UChar *id, int32_t idLength, int32_t dir, const UChar *rules, int32_t rulesLength, void *parseError, UErrorCode *status);
void utrans_close{$suffix}(UTransliterator *trans);
void utrans_transUChars{$suffix}(const UTransliterator *trans, UChar *text, int32_t *textLength, int32_t textCapacity, int32_t start, int32_t *limit, UErrorCode *status);
C;
    }
}

/** Transliterator::create() — php-src transliterator_create (#6139). */
final class TransliteratorCreate extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('create');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError(\sprintf(
                'Transliterator::create() expects between 1 and 2 arguments, %d given',
                $argc
            ));
        }
        $id = VmTransliterator::coerceIdArg($frame->calledArgs[0], 'Transliterator::create', 0);
        $dir = VmTransliterator::FORWARD;
        if ($argc >= 2) {
            $dir = VmTransliterator::coerceDirectionArg($frame->calledArgs[1], 'Transliterator::create', 1);
        }
        if (null === $frame->returnVar) {
            return;
        }
        $object = VmTransliterator::create($frame->vmContext, $id, $dir);
        if (null === $object) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->object($object);
    }
}

/** Transliterator::transliterate() — php-src transliterator_transliterate (#6139). */
final class TransliteratorTransliterate extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('transliterate');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 4) {
            throw new \ArgumentCountError(\sprintf(
                'Transliterator::transliterate() expects between 1 and 3 arguments, %d given',
                max(0, $argc - 1)
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmTransliterator::isTransliteratorObject($receiver->toObject())) {
            throw new \Error('Transliterator::transliterate() called on incompatible object');
        }
        $subject = VmTransliterator::coerceSubjectArg($frame->calledArgs[1], 'Transliterator::transliterate', 1);
        $start = 0;
        $end = -1;
        if ($argc >= 3) {
            $start = (int) $frame->calledArgs[2]->resolveIndirect()->toInt();
        }
        if ($argc >= 4) {
            $end = (int) $frame->calledArgs[3]->resolveIndirect()->toInt();
        }
        $result = VmTransliterator::transliterate($receiver->toObject(), $subject, $start, $end);
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
