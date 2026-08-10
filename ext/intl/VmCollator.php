<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\ClassConstName;
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
 * Collator create/compare/asort/sort/getSortKey/strength/attribute — ICU ucol_* via thin FFI
 * (#5747, #20717, #20753).
 *
 * php-src: ext/intl/collator/collator_*.cpp, collator.stub.php
 * ICU: unicode/ucol.h — versioned exports ucol_open_N / ucol_strcollUTF8_N / ucol_getSortKey_N / …
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

    public const SHIFTED = 20;
    public const NON_IGNORABLE = 21;
    public const LOWER_FIRST = 24;
    public const UPPER_FIRST = 25;

    /** UColAttribute — unicode/ucol.h */
    public const FRENCH_COLLATION = 0;
    public const ALTERNATE_HANDLING = 1;
    public const CASE_FIRST = 2;
    public const CASE_LEVEL = 3;
    public const NORMALIZATION_MODE = 4;
    public const STRENGTH = 5;
    public const HIRAGANA_QUATERNARY_MODE = 6;
    public const NUMERIC_COLLATION = 7;

    public const SORT_REGULAR = 0;
    public const SORT_STRING = 1;
    public const SORT_NUMERIC = 2;

    /** ULOC_ACTUAL_LOCALE / ULOC_VALID_LOCALE */
    public const ULOC_ACTUAL_LOCALE = 0;
    public const ULOC_VALID_LOCALE = 1;

    /**
     * @var array<int, array{
     *   locale: string,
     *   handle: object|null,
     *   strength: int,
     *   attributes: array<int, int>,
     *   errorCode: int,
     *   errorMessage: string
     * }>
     */
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
            'SHIFTED' => self::SHIFTED,
            'NON_IGNORABLE' => self::NON_IGNORABLE,
            'LOWER_FIRST' => self::LOWER_FIRST,
            'UPPER_FIRST' => self::UPPER_FIRST,
            'FRENCH_COLLATION' => self::FRENCH_COLLATION,
            'ALTERNATE_HANDLING' => self::ALTERNATE_HANDLING,
            'CASE_FIRST' => self::CASE_FIRST,
            'CASE_LEVEL' => self::CASE_LEVEL,
            'NORMALIZATION_MODE' => self::NORMALIZATION_MODE,
            'STRENGTH' => self::STRENGTH,
            'HIRAGANA_QUATERNARY_MODE' => self::HIRAGANA_QUATERNARY_MODE,
            'NUMERIC_COLLATION' => self::NUMERIC_COLLATION,
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
        // Exact Zend casing for defined()/hasConstant after #25910 (#30000 / #28132).
        foreach (self::classConstants() as $name => $value) {
            $key = ClassConstName::key($name);
            $const = new Variable(Variable::TYPE_INTEGER);
            $const->int($value);
            $entry->constants[$key] = $const;
            $entry->constNames[$key] = $name;
        }
        $pub = CfgFunc::FLAG_PUBLIC;
        $pubStatic = $pub | CfgFunc::FLAG_STATIC;
        // php-src: Collator::__construct($locale) registers ICU state; without it,
        // `new Collator` leaves $state empty and compare/getSortKey return false (#20753).
        $entry->constructor = new CollatorConstruct();
        $entry->methods['__construct'] = $entry->constructor;
        $entry->methodVisibility['__construct'] = $pub;
        $entry->methodNames['__construct'] = '__construct';
        $methods = [
            'create' => [new CollatorCreate(), $pubStatic, 'create'],
            'compare' => [new CollatorCompare(), $pub, 'compare'],
            'asort' => [new CollatorAsort(), $pub, 'asort'],
            'sort' => [new CollatorSort(), $pub, 'sort'],
            'sortwithsortkeys' => [new CollatorSortWithSortKeys(), $pub, 'sortWithSortKeys'],
            'getsortkey' => [new CollatorGetSortKey(), $pub, 'getSortKey'],
            'getstrength' => [new CollatorGetStrength(), $pub, 'getStrength'],
            'setstrength' => [new CollatorSetStrength(), $pub, 'setStrength'],
            'getattribute' => [new CollatorGetAttribute(), $pub, 'getAttribute'],
            'setattribute' => [new CollatorSetAttribute(), $pub, 'setAttribute'],
            'getlocale' => [new CollatorGetLocale(), $pub, 'getLocale'],
            'geterrorcode' => [new CollatorGetErrorCode(), $pub, 'getErrorCode'],
            'geterrormessage' => [new CollatorGetErrorMessage(), $pub, 'getErrorMessage'],
        ];
        foreach ($methods as $lc => [$handler, $vis, $name]) {
            $entry->methods[$lc] = $handler;
            $entry->methodVisibility[$lc] = $vis;
            $entry->methodNames[$lc] = $name;
        }
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
        $object = new ObjectEntry($ctx->classes[self::CLASS_LC]);
        self::initObject($object, $locale);

        return $object;
    }

    /**
     * Register ICU/fallback collator state on an existing object (new Collator / create).
     * php-src: collator_object_init + ucol_open (ext/intl/collator/collator_class.c).
     */
    public static function initObject(ObjectEntry $object, string $locale): void
    {
        $locale = '' !== $locale ? $locale : VmLocale::getDefault();
        $handle = self::openCollator($locale);
        $object->constructed = true;
        self::$state[$object->id] = [
            'locale' => $locale,
            'handle' => $handle,
            'strength' => self::DEFAULT_STRENGTH,
            'attributes' => [
                self::STRENGTH => self::DEFAULT_STRENGTH,
            ],
            'errorCode' => IntlError::U_ZERO_ERROR,
            'errorMessage' => 'U_ZERO_ERROR',
        ];
        if (null === $handle) {
            IntlError::set(
                IntlError::U_USING_FALLBACK_WARNING,
                'collator_create: ICU collator unavailable; using strcmp fallback: U_USING_DEFAULT_WARNING'
            );
        } elseif (IntlError::U_ZERO_ERROR === IntlError::getCode()) {
            IntlError::clear();
        }
    }

    /**
     * @return int|false Negative / zero / positive like php-src collator_compare
     */
    public static function compare(ObjectEntry $collator, string $string1, string $string2)
    {
        $state = self::$state[$collator->id] ?? null;
        if (null === $state) {
            self::fail($collator, 'collator_compare: bad collator: U_ILLEGAL_ARGUMENT_ERROR');

            return false;
        }
        self::clearObjectError($collator);
        IntlError::clear();
        $handle = $state['handle'];
        if (null !== $handle) {
            return self::strcollUtf8($handle, $string1, $string2);
        }

        return $string1 <=> $string2;
    }

    public static function asort(ObjectEntry $collator, Variable $arrayVar, int $flags = self::SORT_REGULAR): bool
    {
        return self::sortInternal($collator, $arrayVar, $flags, false, 'collator_asort');
    }

    public static function sort(ObjectEntry $collator, Variable $arrayVar, int $flags = self::SORT_REGULAR): bool
    {
        return self::sortInternal($collator, $arrayVar, $flags, true, 'collator_sort');
    }

    public static function sortWithSortKeys(ObjectEntry $collator, Variable $arrayVar): bool
    {
        $arrayVar = $arrayVar->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $arrayVar->type) {
            throw new \TypeError(\sprintf(
                'Collator::sortWithSortKeys(): Argument #1 ($array) must be of type array, %s given',
                ReflectionSupport::valueTypeLabelPublic($arrayVar)
            ));
        }
        $state = self::$state[$collator->id] ?? null;
        if (null === $state) {
            self::fail($collator, 'collator_sort_with_sort_keys: bad collator: U_ILLEGAL_ARGUMENT_ERROR');

            return false;
        }
        $ht = $arrayVar->toArray();
        if ($ht->getNumElements() < 2) {
            self::clearObjectError($collator);
            IntlError::clear();

            return true;
        }
        $pairs = self::copyKeyedPairs($ht);
        usort($pairs, static function (array $a, array $b) use ($collator): int {
            $left = self::valueToSortString($a[1], self::SORT_REGULAR);
            $right = self::valueToSortString($b[1], self::SORT_REGULAR);
            $keyLeft = self::getSortKey($collator, $left);
            $keyRight = self::getSortKey($collator, $right);
            if (false === $keyLeft || false === $keyRight) {
                return 0;
            }

            return $keyLeft <=> $keyRight;
        });
        $values = [];
        foreach ($pairs as [, $value]) {
            $copy = new Variable();
            $copy->copyFrom($value);
            $values[] = $copy;
        }
        $packed = new HashTable();
        foreach ($values as $value) {
            $packed->append($value);
        }
        $arrayVar->array($packed);
        self::clearObjectError($collator);
        IntlError::clear();

        return true;
    }

    /**
     * @return string|false
     */
    public static function getSortKey(ObjectEntry $collator, string $string)
    {
        $state = self::$state[$collator->id] ?? null;
        if (null === $state) {
            self::fail($collator, 'collator_get_sort_key: bad collator: U_ILLEGAL_ARGUMENT_ERROR');

            return false;
        }
        $handle = $state['handle'];
        if (null !== $handle) {
            $key = self::icuGetSortKey($handle, $string);
            if (null !== $key) {
                self::clearObjectError($collator);
                IntlError::clear();

                return $key;
            }
        }
        // Fallback: strcmp-ordered key (no ICU handle).
        self::clearObjectError($collator);
        IntlError::clear();

        return $string;
    }

    public static function getStrength(ObjectEntry $collator): int
    {
        $state = self::$state[$collator->id] ?? null;
        if (null === $state) {
            return self::DEFAULT_STRENGTH;
        }
        $handle = $state['handle'];
        if (null !== $handle) {
            $ffi = self::ffi();
            if (null !== $ffi) {
                $fn = 'ucol_getStrength'.self::$symSuffix;
                try {
                    return (int) $ffi->$fn($handle);
                } catch (\Throwable) {
                    // fall through
                }
            }
        }

        return $state['strength'];
    }

    public static function setStrength(ObjectEntry $collator, int $strength): bool
    {
        $state = self::$state[$collator->id] ?? null;
        if (null === $state) {
            self::fail($collator, 'collator_set_strength: bad collator: U_ILLEGAL_ARGUMENT_ERROR');

            return false;
        }
        $handle = $state['handle'];
        if (null !== $handle) {
            $ffi = self::ffi();
            if (null !== $ffi) {
                $fn = 'ucol_setStrength'.self::$symSuffix;
                try {
                    $ffi->$fn($handle, $strength);
                } catch (\Throwable) {
                    // keep PHP mirror
                }
            }
        }
        self::$state[$collator->id]['strength'] = $strength;
        self::$state[$collator->id]['attributes'][self::STRENGTH] = $strength;
        self::clearObjectError($collator);
        IntlError::clear();

        return true;
    }

    /**
     * @return int|false
     */
    public static function getAttribute(ObjectEntry $collator, int $attribute)
    {
        $state = self::$state[$collator->id] ?? null;
        if (null === $state) {
            self::fail($collator, 'collator_get_attribute: bad collator: U_ILLEGAL_ARGUMENT_ERROR');

            return false;
        }
        $handle = $state['handle'];
        if (null !== $handle) {
            $ffi = self::ffi();
            if (null !== $ffi) {
                $fn = 'ucol_getAttribute'.self::$symSuffix;
                try {
                    $status = $ffi->new('UErrorCode');
                    $status->cdata = 0;
                    $value = (int) $ffi->$fn($handle, $attribute, \FFI::addr($status));
                    if ((int) $status->cdata > 0) {
                        self::fail(
                            $collator,
                            'collator_get_attribute: Error getting attribute value: U_ILLEGAL_ARGUMENT_ERROR'
                        );

                        return false;
                    }
                    self::clearObjectError($collator);
                    IntlError::clear();

                    return $value;
                } catch (\Throwable) {
                    // fall through
                }
            }
        }
        if (self::STRENGTH === $attribute) {
            self::clearObjectError($collator);
            IntlError::clear();

            return $state['strength'];
        }
        if (isset($state['attributes'][$attribute])) {
            self::clearObjectError($collator);
            IntlError::clear();

            return $state['attributes'][$attribute];
        }
        self::clearObjectError($collator);
        IntlError::clear();

        return self::DEFAULT_VALUE;
    }

    public static function setAttribute(ObjectEntry $collator, int $attribute, int $value): bool
    {
        $state = self::$state[$collator->id] ?? null;
        if (null === $state) {
            self::fail($collator, 'collator_set_attribute: bad collator: U_ILLEGAL_ARGUMENT_ERROR');

            return false;
        }
        $handle = $state['handle'];
        if (null !== $handle) {
            $ffi = self::ffi();
            if (null !== $ffi) {
                $fn = 'ucol_setAttribute'.self::$symSuffix;
                try {
                    $status = $ffi->new('UErrorCode');
                    $status->cdata = 0;
                    $ffi->$fn($handle, $attribute, $value, \FFI::addr($status));
                    if ((int) $status->cdata > 0) {
                        self::fail(
                            $collator,
                            'collator_set_attribute: Error setting attribute value: U_ILLEGAL_ARGUMENT_ERROR'
                        );

                        return false;
                    }
                } catch (\Throwable) {
                    // keep PHP mirror
                }
            }
        }
        self::$state[$collator->id]['attributes'][$attribute] = $value;
        if (self::STRENGTH === $attribute) {
            self::$state[$collator->id]['strength'] = $value;
        }
        self::clearObjectError($collator);
        IntlError::clear();

        return true;
    }

    /**
     * @return string|false
     */
    public static function getLocale(ObjectEntry $collator, int $type)
    {
        $state = self::$state[$collator->id] ?? null;
        if (null === $state) {
            self::fail($collator, 'collator_get_locale: bad collator: U_ILLEGAL_ARGUMENT_ERROR');

            return false;
        }
        $handle = $state['handle'];
        if (null !== $handle) {
            $ffi = self::ffi();
            if (null !== $ffi) {
                $fn = 'ucol_getLocaleByType'.self::$symSuffix;
                try {
                    $status = $ffi->new('UErrorCode');
                    $status->cdata = 0;
                    $name = $ffi->$fn($handle, $type, \FFI::addr($status));
                    if ((int) $status->cdata > 0 || null === $name || false === $name) {
                        self::fail(
                            $collator,
                            'collator_get_locale: Error getting locale by type: U_ILLEGAL_ARGUMENT_ERROR'
                        );

                        return false;
                    }
                    $locale = \is_string($name) ? $name : (string) $name;
                    self::clearObjectError($collator);
                    IntlError::clear();

                    return $locale;
                } catch (\Throwable) {
                    // fall through
                }
            }
        }
        self::clearObjectError($collator);
        IntlError::clear();
        if (self::ULOC_ACTUAL_LOCALE === $type) {
            return 'root';
        }

        return $state['locale'];
    }

    public static function getErrorCode(ObjectEntry $collator): int
    {
        $state = self::$state[$collator->id] ?? null;

        return null === $state ? IntlError::U_ZERO_ERROR : $state['errorCode'];
    }

    public static function getErrorMessage(ObjectEntry $collator): string
    {
        $state = self::$state[$collator->id] ?? null;

        return null === $state ? 'U_ZERO_ERROR' : $state['errorMessage'];
    }

    public static function coerceLocaleArg(Variable $var, string $function, int $position): string
    {
        return VmString::coerceStringBuiltinArg($var, $function, $position, 'locale');
    }

    /**
     * Z_PARAM_STR for compare/getSortKey — null TypeError on 8.4 forward (#21077, collator.stub.php).
     */
    public static function coerceStringArg(Variable $var, string $function, int $position, string $name): string
    {
        return VmString::coerceZparamStrBuiltinArg($var, $function, $position, $name);
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

    private static function sortInternal(
        ObjectEntry $collator,
        Variable $arrayVar,
        int $flags,
        bool $renumber,
        string $errorPrefix
    ): bool {
        $arrayVar = $arrayVar->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $arrayVar->type) {
            throw new \TypeError(\sprintf(
                'Collator::%s(): Argument #1 ($array) must be of type array, %s given',
                $renumber ? 'sort' : 'asort',
                ReflectionSupport::valueTypeLabelPublic($arrayVar)
            ));
        }
        $state = self::$state[$collator->id] ?? null;
        if (null === $state) {
            self::fail($collator, $errorPrefix.': bad collator: U_ILLEGAL_ARGUMENT_ERROR');

            return false;
        }
        $ht = $arrayVar->toArray();
        if ($ht->getNumElements() < 2) {
            self::clearObjectError($collator);
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
        if ($renumber) {
            // Packed 0..n-1 list — caller-visible via BuiltinByRefParams collator::sort (#20717).
            $values = [];
            foreach ($pairs as [, $value]) {
                $copy = new Variable();
                $copy->copyFrom($value);
                $values[] = $copy;
            }
            $packed = new HashTable();
            foreach ($values as $value) {
                $packed->append($value);
            }
            $arrayVar->array($packed);
        } else {
            $arrayVar->array(self::hashTableFromSortedPairs($pairs));
        }
        self::clearObjectError($collator);
        IntlError::clear();

        return true;
    }

    private static function fail(ObjectEntry $collator, string $message): void
    {
        IntlError::set(IntlError::U_ILLEGAL_ARGUMENT_ERROR, $message);
        if (isset(self::$state[$collator->id])) {
            self::$state[$collator->id]['errorCode'] = IntlError::U_ILLEGAL_ARGUMENT_ERROR;
            self::$state[$collator->id]['errorMessage'] = $message;
        }
    }

    private static function clearObjectError(ObjectEntry $collator): void
    {
        if (!isset(self::$state[$collator->id])) {
            return;
        }
        self::$state[$collator->id]['errorCode'] = IntlError::U_ZERO_ERROR;
        self::$state[$collator->id]['errorMessage'] = 'U_ZERO_ERROR';
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

    /** @param object $handle FFI CData UCollator* */
    private static function icuGetSortKey(object $handle, string $utf8): ?string
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return null;
        }
        $fn = 'ucol_getSortKey'.self::$symSuffix;
        try {
            $utf16 = self::utf8ToUChars($utf8);
            $n = \count($utf16);
            $src = $ffi->new('UChar['.($n + 1).']');
            for ($i = 0; $i < $n; ++$i) {
                $src[$i] = $utf16[$i];
            }
            $src[$n] = 0;
            $needed = (int) $ffi->$fn($handle, $src, $n, null, 0);
            if ($needed <= 0) {
                return '';
            }
            $buf = $ffi->new('uint8_t['.$needed.']');
            $written = (int) $ffi->$fn($handle, $src, $n, $buf, $needed);
            if ($written <= 0) {
                return '';
            }
            // php-src: key length includes trailing NUL; strip it for the PHP string.
            $out = '';
            $limit = $written > 0 ? $written - 1 : 0;
            for ($i = 0; $i < $limit; ++$i) {
                $out .= \chr((int) $buf[$i]);
            }

            return $out;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return list<int> UTF-16 code units */
    private static function utf8ToUChars(string $utf8): array
    {
        if ('' === $utf8) {
            return [];
        }
        if (\function_exists('mb_convert_encoding')) {
            $bin = \mb_convert_encoding($utf8, 'UTF-16LE', 'UTF-8');
            if (false !== $bin && '' !== $bin) {
                $units = [];
                $len = \strlen($bin);
                for ($i = 0; $i + 1 < $len; $i += 2) {
                    $units[] = \unpack('v', \substr($bin, $i, 2))[1];
                }

                return $units;
            }
        }
        $units = [];
        $len = \strlen($utf8);
        $i = 0;
        while ($i < $len) {
            $c = \ord($utf8[$i]);
            if ($c < 0x80) {
                $cp = $c;
                ++$i;
            } elseif ($c < 0xE0 && $i + 1 < $len) {
                $cp = (($c & 0x1F) << 6) | (\ord($utf8[$i + 1]) & 0x3F);
                $i += 2;
            } elseif ($c < 0xF0 && $i + 2 < $len) {
                $cp = (($c & 0x0F) << 12)
                    | ((\ord($utf8[$i + 1]) & 0x3F) << 6)
                    | (\ord($utf8[$i + 2]) & 0x3F);
                $i += 3;
            } elseif ($i + 3 < $len) {
                $cp = (($c & 0x07) << 18)
                    | ((\ord($utf8[$i + 1]) & 0x3F) << 12)
                    | ((\ord($utf8[$i + 2]) & 0x3F) << 6)
                    | (\ord($utf8[$i + 3]) & 0x3F);
                $i += 4;
            } else {
                $cp = 0xFFFD;
                ++$i;
            }
            if ($cp > 0xFFFF) {
                $cp -= 0x10000;
                $units[] = 0xD800 | ($cp >> 10);
                $units[] = 0xDC00 | ($cp & 0x3FF);
            } else {
                $units[] = $cp;
            }
        }

        return $units;
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
                throw new \Error('Collator sort only supports string or integer keys');
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
typedef uint16_t UChar;
typedef struct UCollator UCollator;
typedef int32_t UCollationResult;
typedef int32_t UColAttribute;
typedef int32_t UColAttributeValue;
typedef int32_t UColStrength;
typedef int32_t ULocDataLocaleType;
UCollator *ucol_open{$suffix}(const char *loc, UErrorCode *status);
void ucol_close{$suffix}(UCollator *coll);
UCollationResult ucol_strcollUTF8{$suffix}(const UCollator *coll, const char *source, int32_t sourceLength, const char *target, int32_t targetLength, UErrorCode *status);
UColStrength ucol_getStrength{$suffix}(const UCollator *coll);
void ucol_setStrength{$suffix}(UCollator *coll, UColStrength strength);
UColAttributeValue ucol_getAttribute{$suffix}(const UCollator *coll, UColAttribute attr, UErrorCode *status);
void ucol_setAttribute{$suffix}(UCollator *coll, UColAttribute attr, UColAttributeValue value, UErrorCode *status);
int32_t ucol_getSortKey{$suffix}(const UCollator *coll, const UChar *source, int32_t sourceLength, uint8_t *result, int32_t resultLength);
const char *ucol_getLocaleByType{$suffix}(const UCollator *coll, ULocDataLocaleType type, UErrorCode *status);
C;
    }
}

/** Collator::__construct() — php-src collator_create / Collator::__construct (#20753). */
final class CollatorConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1) {
            throw new \LogicException('Collator::__construct() called without $this');
        }
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'Collator::__construct() expects exactly 1 argument, %d given',
                max(0, $argc - 1)
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmCollator::isCollatorObject($receiver->toObject())) {
            throw new \TypeError('Collator::__construct() must be called on Collator');
        }
        $locale = VmCollator::coerceLocaleArg($frame->calledArgs[1], 'Collator::__construct', 0);
        VmCollator::initObject($receiver->toObject(), $locale);
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

/** Collator::compare() — php-src collator_compare (#5747, AOT #28649). Z_PARAM_STR null TypeError on 8.4 (#21077). */
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
        // User arg indices (exclude $this) — Argument #1/$string1, #2/$string2 (#21077).
        $string1 = VmCollator::coerceStringArg($frame->calledArgs[1], 'Collator::compare', 0, 'string1');
        $string2 = VmCollator::coerceStringArg($frame->calledArgs[2], 'Collator::compare', 1, 'string2');
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

    public function call(\PHPCompiler\JIT\Context $context, \PHPCompiler\JIT\Variable ...$args): \PHPLLVM\Value
    {
        return JitCollatorCompare::invokeMethod($context, ...$args);
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

/** Collator::sort() — php-src collator_sort (#20717). */
final class CollatorSort extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('sort');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(\sprintf(
                'Collator::sort() expects between 1 and 2 arguments, %d given',
                max(0, $argc - 1)
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmCollator::isCollatorObject($receiver->toObject())) {
            throw new \Error('Collator::sort() called on incompatible object');
        }
        $flags = VmCollator::SORT_REGULAR;
        if ($argc >= 3) {
            $flags = VmCollator::coerceSortFlags($frame->calledArgs[2], 'Collator::sort', 2);
        }
        $ok = VmCollator::sort($receiver->toObject(), $frame->calledArgs[1], $flags);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool($ok);
    }
}

/** Collator::sortWithSortKeys() — php-src collator_sort_with_sort_keys (#20717). */
final class CollatorSortWithSortKeys extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('sortWithSortKeys');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'Collator::sortWithSortKeys() expects exactly 1 argument, %d given',
                max(0, $argc - 1)
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmCollator::isCollatorObject($receiver->toObject())) {
            throw new \Error('Collator::sortWithSortKeys() called on incompatible object');
        }
        $ok = VmCollator::sortWithSortKeys($receiver->toObject(), $frame->calledArgs[1]);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool($ok);
    }
}

/** Collator::getSortKey() — php-src collator_get_sort_key (#20717). Z_PARAM_STR null TypeError on 8.4 (#21077). */
final class CollatorGetSortKey extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getSortKey');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'Collator::getSortKey() expects exactly 1 argument, %d given',
                max(0, $argc - 1)
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmCollator::isCollatorObject($receiver->toObject())) {
            throw new \Error('Collator::getSortKey() called on incompatible object');
        }
        // User arg index (exclude $this) — Argument #1/$string (#21077).
        $string = VmCollator::coerceStringArg($frame->calledArgs[1], 'Collator::getSortKey', 0, 'string');
        $result = VmCollator::getSortKey($receiver->toObject(), $string);
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

/** Collator::getStrength() — php-src collator_get_strength (#20717). */
final class CollatorGetStrength extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getStrength');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'Collator::getStrength() expects exactly 0 arguments, %d given',
                max(0, $argc - 1)
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmCollator::isCollatorObject($receiver->toObject())) {
            throw new \Error('Collator::getStrength() called on incompatible object');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(VmCollator::getStrength($receiver->toObject()));
    }
}

/** Collator::setStrength() — php-src collator_set_strength (#20717). */
final class CollatorSetStrength extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('setStrength');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'Collator::setStrength() expects exactly 1 argument, %d given',
                max(0, $argc - 1)
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmCollator::isCollatorObject($receiver->toObject())) {
            throw new \Error('Collator::setStrength() called on incompatible object');
        }
        $strength = VmCollator::coerceIntArg($frame->calledArgs[1], 'Collator::setStrength', 1, 'strength');
        $ok = VmCollator::setStrength($receiver->toObject(), $strength);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool($ok);
    }
}

/** Collator::getAttribute() — php-src collator_get_attribute (#20717). */
final class CollatorGetAttribute extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getAttribute');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'Collator::getAttribute() expects exactly 1 argument, %d given',
                max(0, $argc - 1)
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmCollator::isCollatorObject($receiver->toObject())) {
            throw new \Error('Collator::getAttribute() called on incompatible object');
        }
        $attribute = VmCollator::coerceIntArg($frame->calledArgs[1], 'Collator::getAttribute', 1, 'attribute');
        $result = VmCollator::getAttribute($receiver->toObject(), $attribute);
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

/** Collator::setAttribute() — php-src collator_set_attribute (#20717). */
final class CollatorSetAttribute extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('setAttribute');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (3 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'Collator::setAttribute() expects exactly 2 arguments, %d given',
                max(0, $argc - 1)
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmCollator::isCollatorObject($receiver->toObject())) {
            throw new \Error('Collator::setAttribute() called on incompatible object');
        }
        $attribute = VmCollator::coerceIntArg($frame->calledArgs[1], 'Collator::setAttribute', 1, 'attribute');
        $value = VmCollator::coerceIntArg($frame->calledArgs[2], 'Collator::setAttribute', 2, 'value');
        $ok = VmCollator::setAttribute($receiver->toObject(), $attribute, $value);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool($ok);
    }
}

/** Collator::getLocale() — php-src collator_get_locale (#20717). */
final class CollatorGetLocale extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getLocale');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'Collator::getLocale() expects exactly 1 argument, %d given',
                max(0, $argc - 1)
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmCollator::isCollatorObject($receiver->toObject())) {
            throw new \Error('Collator::getLocale() called on incompatible object');
        }
        $type = VmCollator::coerceIntArg($frame->calledArgs[1], 'Collator::getLocale', 1, 'type');
        $result = VmCollator::getLocale($receiver->toObject(), $type);
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

/** Collator::getErrorCode() — php-src collator_get_error_code (#20717). */
final class CollatorGetErrorCode extends VmClassMethod
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
                'Collator::getErrorCode() expects exactly 0 arguments, %d given',
                max(0, $argc - 1)
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmCollator::isCollatorObject($receiver->toObject())) {
            throw new \Error('Collator::getErrorCode() called on incompatible object');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(VmCollator::getErrorCode($receiver->toObject()));
    }
}

/** Collator::getErrorMessage() — php-src collator_get_error_message (#20717). */
final class CollatorGetErrorMessage extends VmClassMethod
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
                'Collator::getErrorMessage() expects exactly 0 arguments, %d given',
                max(0, $argc - 1)
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmCollator::isCollatorObject($receiver->toObject())) {
            throw new \Error('Collator::getErrorMessage() called on incompatible object');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string(VmCollator::getErrorMessage($receiver->toObject()));
    }
}
