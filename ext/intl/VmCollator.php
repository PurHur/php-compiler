<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\Variable;
use PHPCfg\Func as CfgFunc;

/**
 * Collator create/compare/asort — ICU ucol_* via thin FFI (#5747).
 *
 * php-src: ext/intl/collator/collator_create.c, collator_compare.c, collator_sort.c, collator_class.c
 * ICU: unicode/ucol.h — versioned exports ucol_open_N / ucol_strcollUTF8_N / ucol_close_N
 */
final class VmCollator
{
    public const CLASS_LC = 'collator';

    public const DEFAULT_STRENGTH = 2;
    public const PRIMARY = 0;
    public const SECONDARY = 1;
    public const TERTIARY = 2;
    public const QUATERNARY = 3;
    public const IDENTICAL = 15;
    public const OFF = 16;
    public const ON = 17;
    public const DEFAULT_VALUE = -1;

    public const SORT_REGULAR = 0;
    public const SORT_STRING = 1;
    public const SORT_NUMERIC = 2;

    /** @var array<int, array{locale: string, handle: object|null}> */
    private static array $state = [];

    private static ?\FFI $ffi = null;

    private static string $symSuffix = '';

    private static bool $ffiUnavailable = false;

    /** @return array<string, int> */
    public static function classConstants(): array
    {
        return [
            'DEFAULT_STRENGTH' => self::DEFAULT_STRENGTH,
            'PRIMARY' => self::PRIMARY,
            'SECONDARY' => self::SECONDARY,
            'TERTIARY' => self::TERTIARY,
            'QUATERNARY' => self::QUATERNARY,
            'IDENTICAL' => self::IDENTICAL,
            'OFF' => self::OFF,
            'ON' => self::ON,
            'DEFAULT_VALUE' => self::DEFAULT_VALUE,
            'SORT_REGULAR' => self::SORT_REGULAR,
            'SORT_STRING' => self::SORT_STRING,
            'SORT_NUMERIC' => self::SORT_NUMERIC,
        ];
    }

    public static function registerClass(Context $ctx): void
    {
        if (isset($ctx->classes[self::CLASS_LC])) {
            return;
        }

        $entry = new ClassEntry('Collator');
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
        $entry->methods['create'] = new CollatorCreate();
        $entry->methodVisibility['create'] = $pubStatic;
        $entry->methodNames['create'] = 'create';
        $entry->methods['compare'] = new CollatorCompare();
        $entry->methodVisibility['compare'] = $pub;
        $entry->methodNames['compare'] = 'compare';
        $entry->methods['asort'] = new CollatorAsort();
        $entry->methodVisibility['asort'] = $pub;
        $entry->methodNames['asort'] = 'asort';
        $ctx->classes[self::CLASS_LC] = $entry;
    }

    public static function isCollatorObject(?ObjectEntry $object): bool
    {
        return null !== $object && self::CLASS_LC === strtolower($object->class->name);
    }

    public static function create(Context $ctx, string $locale): ObjectEntry
    {
        if (!isset($ctx->classes[self::CLASS_LC])) {
            throw new \Error('Class "Collator" not found');
        }
        $locale = '' !== $locale ? $locale : VmLocale::getDefault();
        $handle = self::openCollator($locale);
        $object = new ObjectEntry($ctx->classes[self::CLASS_LC]);
        $object->constructed = true;
        self::$state[$object->id] = [
            'locale' => $locale,
            'handle' => $handle,
        ];
        if (null === $handle) {
            IntlError::set(
                IntlError::U_USING_FALLBACK_WARNING,
                'collator_create: ICU collator unavailable; using strcmp fallback: U_USING_DEFAULT_WARNING'
            );
        } elseif (IntlError::U_ZERO_ERROR === IntlError::getCode()) {
            IntlError::clear();
        }

        return $object;
    }

    /**
     * @return int|false Negative / zero / positive like php-src collator_compare
     */
    public static function compare(ObjectEntry $collator, string $string1, string $string2)
    {
        $state = self::$state[$collator->id] ?? null;
        if (null === $state) {
            IntlError::set(
                IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                'collator_compare: bad collator: U_ILLEGAL_ARGUMENT_ERROR'
            );

            return false;
        }
        IntlError::clear();
        $handle = $state['handle'];
        if (null !== $handle) {
            return self::strcollUtf8($handle, $string1, $string2);
        }

        return $string1 <=> $string2;
    }

    public static function asort(ObjectEntry $collator, Variable $arrayVar, int $flags = self::SORT_REGULAR): bool
    {
        $arrayVar = $arrayVar->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $arrayVar->type) {
            throw new \TypeError(\sprintf(
                'Collator::asort(): Argument #1 ($array) must be of type array, %s given',
                ReflectionSupport::valueTypeLabelPublic($arrayVar)
            ));
        }
        $state = self::$state[$collator->id] ?? null;
        if (null === $state) {
            IntlError::set(
                IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                'collator_asort: bad collator: U_ILLEGAL_ARGUMENT_ERROR'
            );

            return false;
        }
        $ht = $arrayVar->toArray();
        if ($ht->getNumElements() < 2) {
            IntlError::clear();

            return true;
        }
        $pairs = self::copyKeyedPairs($ht);
        usort($pairs, static function (array $a, array $b) use ($collator, $flags): int {
            $left = self::valueToSortString($a[1], $flags);
            $right = self::valueToSortString($b[1], $flags);
            $cmp = self::compare($collator, $left, $right);
            if (false === $cmp) {
                return 0;
            }

            return $cmp <=> 0;
        });
        $arrayVar->array(self::hashTableFromSortedPairs($pairs));
        IntlError::clear();

        return true;
    }

    public static function coerceLocaleArg(Variable $var, string $function, int $position): string
    {
        return VmString::coerceStringBuiltinArg($var, $function, $position, 'locale');
    }

    public static function coerceStringArg(Variable $var, string $function, int $position, string $name): string
    {
        return VmString::coerceStringBuiltinArg($var, $function, $position, $name);
    }

    public static function coerceSortFlags(Variable $var, string $function, int $position): int
    {
        $var = $var->resolveIndirect();
        if (EnumCaseSupport::isEnumCaseVariable($var)) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($flags) must be of type int, %s given',
                $function,
                $position + 1,
                EnumCaseSupport::typeNameForVariable($var)
            ));
        }
        if (Variable::TYPE_INTEGER === $var->type) {
            return $var->toInt();
        }
        if (Variable::TYPE_NULL === $var->type) {
            return self::SORT_REGULAR;
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
            '%s(): Argument #%d ($flags) must be of type int, %s given',
            $function,
            $position + 1,
            ReflectionSupport::valueTypeLabelPublic($var)
        ));
    }

    /** @return object|null FFI CData UCollator* */
    private static function openCollator(string $locale): ?object
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return null;
        }
        $open = 'ucol_open'.self::$symSuffix;
        $close = 'ucol_close'.self::$symSuffix;
        try {
            $status = $ffi->new('UErrorCode');
            $status->cdata = 0;
            $coll = $ffi->$open($locale, \FFI::addr($status));
            $code = (int) $status->cdata;
            if (null === $coll) {
                return null;
            }
            // ICU warnings (negative) are OK — e.g. U_USING_DEFAULT_WARNING (-127).
            if ($code > 0) {
                $ffi->$close($coll);

                return null;
            }
            if (0 !== $code) {
                IntlError::set(
                    IntlError::U_USING_FALLBACK_WARNING,
                    'collator_create: U_USING_DEFAULT_WARNING'
                );
            }

            return $coll;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param object $handle FFI CData UCollator* */
    private static function strcollUtf8(object $handle, string $a, string $b): int
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return $a <=> $b;
        }
        $fn = 'ucol_strcollUTF8'.self::$symSuffix;
        try {
            $status = $ffi->new('UErrorCode');
            $status->cdata = 0;
            $result = (int) $ffi->$fn(
                $handle,
                $a,
                \strlen($a),
                $b,
                \strlen($b),
                \FFI::addr($status)
            );
            if ((int) $status->cdata > 0) {
                return $a <=> $b;
            }

            return $result <=> 0;
        } catch (\Throwable) {
            return $a <=> $b;
        }
    }

    private static function valueToSortString(Variable $var, int $flags): string
    {
        $var = $var->resolveIndirect();
        if (self::SORT_NUMERIC === $flags) {
            if (Variable::TYPE_INTEGER === $var->type) {
                return (string) $var->toInt();
            }
            if (Variable::TYPE_FLOAT === $var->type) {
                return (string) $var->toFloat();
            }
            if (Variable::TYPE_STRING === $var->type && is_numeric($var->toString())) {
                return (string) (0 + $var->toString());
            }
        }
        if (Variable::TYPE_STRING === $var->type) {
            return $var->toString();
        }
        if (Variable::TYPE_INTEGER === $var->type) {
            return (string) $var->toInt();
        }
        if (Variable::TYPE_FLOAT === $var->type) {
            return (string) $var->toFloat();
        }
        if (Variable::TYPE_BOOLEAN === $var->type) {
            return $var->toBool() ? '1' : '';
        }
        if (Variable::TYPE_NULL === $var->type) {
            return '';
        }

        return ReflectionSupport::valueTypeLabelPublic($var);
    }

    /**
     * @return list<array{0: Variable, 1: Variable}>
     */
    private static function copyKeyedPairs(HashTable $ht): array
    {
        $pairs = [];
        foreach ($ht->iterateKeyed(true) as [$key, $value]) {
            $keyCopy = new Variable();
            $keyCopy->copyFrom($key);
            $valCopy = new Variable();
            $valCopy->copyFrom($value);
            $pairs[] = [$keyCopy, $valCopy];
        }

        return $pairs;
    }

    /**
     * @param list<array{0: Variable, 1: Variable}> $pairs
     */
    private static function hashTableFromSortedPairs(array $pairs): HashTable
    {
        $sorted = new HashTable();
        foreach ($pairs as [$key, $value]) {
            $resolvedKey = $key->resolveIndirect();
            $copy = new Variable();
            $copy->copyFrom($value);
            if (Variable::TYPE_INTEGER === $resolvedKey->type) {
                $sorted->addIndex($resolvedKey->toInt(), $copy);
            } elseif (Variable::TYPE_STRING === $resolvedKey->type) {
                $sorted->add($resolvedKey->toString(), $copy);
            } else {
                throw new \Error('Collator::asort() only supports string or integer keys');
            }
        }

        return $sorted;
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

        /** @var list<array{0: string, 1: string}> lib + symbol suffix */
        $candidates = [
            ['libicui18n.so.70', '_70'],
            ['libicui18n.so.74', '_74'],
            ['libicui18n.so.72', '_72'],
            ['libicui18n.so.71', '_71'],
            ['libicui18n.so', '_70'],
            ['libicui18n.dylib', ''],
        ];
        foreach ($candidates as [$lib, $suffix]) {
            $cdef = self::cdefForSuffix($suffix);
            try {
                self::$ffi = \FFI::cdef($cdef, $lib);
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
typedef struct UCollator UCollator;
typedef int32_t UCollationResult;
UCollator *ucol_open{$suffix}(const char *loc, UErrorCode *status);
void ucol_close{$suffix}(UCollator *coll);
UCollationResult ucol_strcollUTF8{$suffix}(const UCollator *coll, const char *source, int32_t sourceLength, const char *target, int32_t targetLength, UErrorCode *status);
C;
    }
}

/** Collator::create() — php-src collator_create (#5747). */
final class CollatorCreate extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('create');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'Collator::create() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $locale = VmCollator::coerceLocaleArg($frame->calledArgs[0], 'Collator::create', 0);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->object(VmCollator::create($frame->vmContext, $locale));
    }
}

/** Collator::compare() — php-src collator_compare (#5747). */
final class CollatorCompare extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('compare');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (3 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'Collator::compare() expects exactly 2 arguments, %d given',
                max(0, $argc - 1)
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmCollator::isCollatorObject($receiver->toObject())) {
            throw new \Error('Collator::compare() called on incompatible object');
        }
        $string1 = VmCollator::coerceStringArg($frame->calledArgs[1], 'Collator::compare', 1, 'string1');
        $string2 = VmCollator::coerceStringArg($frame->calledArgs[2], 'Collator::compare', 2, 'string2');
        $result = VmCollator::compare($receiver->toObject(), $string1, $string2);
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $result) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->int($result);
    }
}

/** Collator::asort() — php-src collator_asort (#5747). */
final class CollatorAsort extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('asort');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(\sprintf(
                'Collator::asort() expects between 1 and 2 arguments, %d given',
                max(0, $argc - 1)
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmCollator::isCollatorObject($receiver->toObject())) {
            throw new \Error('Collator::asort() called on incompatible object');
        }
        $flags = VmCollator::SORT_REGULAR;
        if ($argc >= 3) {
            $flags = VmCollator::coerceSortFlags($frame->calledArgs[2], 'Collator::asort', 2);
        }
        $ok = VmCollator::asort($receiver->toObject(), $frame->calledArgs[1], $flags);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool($ok);
    }
}
