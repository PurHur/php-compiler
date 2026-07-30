<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\ext\spl\ArrayIteratorBuiltin;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPCfg\Func as CfgFunc;

/**
 * ResourceBundle create/get/locales/errors + read_dimension — ICU ures_* via thin FFI
 * (#6187, #20739, #20916, #25145).
 *
 * php-src: ext/intl/resourcebundle/resourcebundle_class.cpp
 *          ext/intl/resourcebundle/resourcebundle_iterator.cpp
 *          ext/intl/resourcebundle/resourcebundle.cpp (extract_value)
 * ICU: unicode/ures.h — ures_open / ures_getSize / ures_getByIndex / ures_getKey / …
 */
final class VmResourceBundle
{
    public const CLASS_LC = 'resourcebundle';

    /** ICU UResType — unicode/ures.h */
    private const URES_STRING = 0;
    private const URES_BINARY = 1;
    private const URES_TABLE = 2;
    private const URES_INT = 7;
    private const URES_ARRAY = 8;
    private const URES_INT_VECTOR = 14;

    /**
     * @var array<int, array{
     *   locale: string,
     *   bundle: ?string,
     *   handle: object|null,
     *   fallback: bool,
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

        $entry = new ClassEntry('ResourceBundle');
        $entry->isInternal = true;
        // php-src ResourceBundle implements IteratorAggregate, Countable (#20781, #20916).
        if (isset($ctx->classes['iteratoraggregate'])) {
            $entry->interfaces[] = 'iteratoraggregate';
        }
        if (isset($ctx->classes['countable'])) {
            $entry->interfaces[] = 'countable';
        }
        $pub = CfgFunc::FLAG_PUBLIC;
        $pubStatic = $pub | CfgFunc::FLAG_STATIC;
        $entry->methods['create'] = new ResourceBundleCreate();
        $entry->methodVisibility['create'] = $pubStatic;
        $entry->methodNames['create'] = 'create';
        $entry->methods['getlocales'] = new ResourceBundleGetLocales();
        $entry->methodVisibility['getlocales'] = $pubStatic;
        $entry->methodNames['getlocales'] = 'getLocales';
        $entry->methods['get'] = new ResourceBundleGet();
        $entry->methodVisibility['get'] = $pub;
        $entry->methodNames['get'] = 'get';
        $entry->methods['count'] = new ResourceBundleCount();
        $entry->methodVisibility['count'] = $pub;
        $entry->methodNames['count'] = 'count';
        $entry->methods['geterrorcode'] = new ResourceBundleGetErrorCode();
        $entry->methodVisibility['geterrorcode'] = $pub;
        $entry->methodNames['geterrorcode'] = 'getErrorCode';
        $entry->methods['geterrormessage'] = new ResourceBundleGetErrorMessage();
        $entry->methodVisibility['geterrormessage'] = $pub;
        $entry->methodNames['geterrormessage'] = 'getErrorMessage';
        $entry->methods['getiterator'] = new ResourceBundleGetIterator();
        $entry->methodVisibility['getiterator'] = $pub;
        $entry->methodNames['getiterator'] = 'getIterator';
        $ctx->classes[self::CLASS_LC] = $entry;
    }

    public static function isResourceBundleObject(?ObjectEntry $object): bool
    {
        return null !== $object && self::CLASS_LC === strtolower($object->class->name);
    }

    /**
     * @return ObjectEntry|null
     */
    public static function create(Context $ctx, ?string $locale, ?string $bundleName, bool $fallback = true): ?ObjectEntry
    {
        if (!isset($ctx->classes[self::CLASS_LC])) {
            throw new \Error('Class "ResourceBundle" not found');
        }
        $locale = null !== $locale && '' !== $locale ? $locale : VmLocale::getDefault();
        $opened = self::openBundle($locale, $bundleName, $fallback);
        $handle = $opened['handle'];
        $status = $opened['status'];

        // Synthetic Version-only fallback when ICU FFI unavailable (null handle, zero status).
        if (null === $handle && 0 === $status) {
            $object = new ObjectEntry($ctx->classes[self::CLASS_LC]);
            $object->constructed = true;
            self::$state[$object->id] = [
                'locale' => $locale,
                'bundle' => $bundleName,
                'handle' => null,
                'fallback' => true,
                'errorCode' => IntlError::U_USING_FALLBACK_WARNING,
                'errorMessage' => 'resourcebundle_create: ICU data unavailable; using Version fallback: U_USING_DEFAULT_WARNING',
            ];
            IntlError::set(
                IntlError::U_USING_FALLBACK_WARNING,
                self::$state[$object->id]['errorMessage']
            );

            return $object;
        }

        if (null === $handle || $status > 0) {
            $code = $status > 0 ? $status : IntlError::U_MISSING_RESOURCE_ERROR;
            $msg = 'Cannot load libICU resource bundle: '.IntlError::errorName($code);
            IntlError::set($code, $msg);

            return null;
        }

        // fallback=false + ICU used default/fallback locale → fail (php-src resourcebundle_ctor).
        if (!$fallback && (
            IntlError::U_USING_DEFAULT_WARNING === $status
            || IntlError::U_USING_FALLBACK_WARNING === $status
        )) {
            $actual = self::uresGetLocale($handle) ?? $locale;
            $bundleLabel = null !== $bundleName && '' !== $bundleName ? $bundleName : '(default data)';
            $msg = \sprintf(
                "Cannot load libICU resource '%s' without fallback from %s to %s",
                $bundleLabel,
                $locale,
                $actual
            );
            IntlError::set($status, $msg);
            self::uresClose($handle);

            return null;
        }

        $object = new ObjectEntry($ctx->classes[self::CLASS_LC]);
        $object->constructed = true;
        $errorMessage = IntlError::errorName($status);
        self::$state[$object->id] = [
            'locale' => $locale,
            'bundle' => $bundleName,
            'handle' => $handle,
            'fallback' => false,
            'errorCode' => $status,
            'errorMessage' => $errorMessage,
        ];
        // Propagate ICU warning/success to global intl error (php-src INTL_DATA_ERROR + intl_error_set_code NULL).
        IntlError::set($status, $errorMessage);

        return $object;
    }

    /**
     * ResourceBundle::getLocales() — php-src resourcebundle_locales (#20739).
     *
     * @return list<string>|false
     */
    public static function getLocales(string $bundleName)
    {
        IntlError::clear();
        // Empty string → ICU default package (php-src resourcebundle_locales).
        $path = '' === $bundleName ? null : $bundleName;
        $locales = self::enumerateAvailableLocales($path);
        if (null === $locales) {
            IntlError::set(
                IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                'resourcebundle_locales: cannot fetch locales list: U_ILLEGAL_ARGUMENT_ERROR'
            );

            return false;
        }
        IntlError::clear();

        return $locales;
    }

    /**
     * @param int|string $index numeric → ures_getByIndex; string → ures_getByKey (php-src resourcebundle_array_fetch)
     *
     * @return string|int|ObjectEntry|HashTable|null null = missing/failed lookup (php-src returns null)
     */
    public static function get(Context $ctx, ObjectEntry $bundle, int|string $index)
    {
        $state = self::$state[$bundle->id] ?? null;
        if (null === $state) {
            self::fail(
                $bundle,
                IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                'Found unconstructed ResourceBundle: U_ILLEGAL_ARGUMENT_ERROR'
            );

            return null;
        }
        self::clearObjectError($bundle);
        IntlError::clear();
        if (null !== $state['handle']) {
            $extracted = \is_int($index)
                ? self::extractValueByIndex($ctx, $state['handle'], $index)
                : self::extractByKey($ctx, $state['handle'], $index);
            if (null !== $extracted) {
                self::clearObjectError($bundle);
                IntlError::clear();

                return $extracted;
            }
            // Fall through for Version when key lookup fails with warning-only.
        }
        if ($state['fallback'] || (!\is_int($index) && 'Version' === $index)) {
            self::clearObjectError($bundle);
            IntlError::clear();
            if (!\is_int($index) && 'Version' === $index) {
                return self::fallbackVersion();
            }
        }
        $message = \is_int($index)
            ? "Cannot load resource element {$index}: U_MISSING_RESOURCE_ERROR"
            : "Cannot load resource element '".$index."': U_MISSING_RESOURCE_ERROR";
        self::fail($bundle, IntlError::U_MISSING_RESOURCE_ERROR, $message);

        return null;
    }

    /**
     * Engine read_dimension — php-src resourcebundle_array_get (#25145).
     * Not ArrayAccess; writes/isset/unset stay "Cannot use object of type ResourceBundle as array".
     */
    public static function readDimension(Context $ctx, ObjectEntry $object, Variable $offset, Variable $out): void
    {
        $offset = $offset->resolveIndirect();
        if (Variable::TYPE_INTEGER === $offset->type) {
            $index = $offset->toInt();
        } elseif (Variable::TYPE_STRING === $offset->type) {
            $index = $offset->toString();
        } else {
            // php-src resourcebundle_array_fetch: non-int/non-string → U_ILLEGAL_ARGUMENT + null
            self::fail(
                $object,
                IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                'resourcebundle_get: index should be integer or string: U_ILLEGAL_ARGUMENT_ERROR'
            );
            IntlError::set(
                IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                'resourcebundle_get: index should be integer or string: U_ILLEGAL_ARGUMENT_ERROR'
            );
            $out->null();

            return;
        }
        $result = self::get($ctx, $object, $index);
        if (null === $result) {
            $out->null();

            return;
        }
        if (\is_int($result)) {
            $out->int($result);

            return;
        }
        if ($result instanceof ObjectEntry) {
            $out->object($result);

            return;
        }
        if ($result instanceof HashTable) {
            $out->array($result);

            return;
        }
        $out->string($result);
    }

    public static function count(ObjectEntry $bundle): int
    {
        $state = self::$state[$bundle->id] ?? null;
        if (null === $state) {
            return 0;
        }
        if ($state['fallback'] || null === $state['handle']) {
            return 1; // synthetic Version key
        }
        $size = self::uresGetSize($state['handle']);

        return $size >= 0 ? $size : 1;
    }

    public static function getErrorCode(ObjectEntry $bundle): int
    {
        $state = self::$state[$bundle->id] ?? null;

        return null === $state ? IntlError::U_ZERO_ERROR : $state['errorCode'];
    }

    public static function getErrorMessage(ObjectEntry $bundle): string
    {
        $state = self::$state[$bundle->id] ?? null;

        return null === $state ? 'U_ZERO_ERROR' : $state['errorMessage'];
    }

    /**
     * ResourceBundle::getIterator() — php-src InternalIterator over ures_getByIndex (#20916).
     * Snapshot into ArrayIterator (same observable foreach keys/values for tables/arrays).
     */
    public static function getIterator(Context $ctx, ObjectEntry $bundle): ObjectEntry
    {
        $class = $ctx->classes[ArrayIteratorBuiltin::CLASS_LC] ?? null;
        if (null === $class) {
            throw new \LogicException('ArrayIterator is not registered in this compiler build');
        }
        $ht = self::buildEntriesHashTable($ctx, $bundle);
        $entry = new ObjectEntry($class);
        $entry->constructed = true;
        ArrayIteratorBuiltin::init($entry, $ht);

        return $entry;
    }

    public static function coerceLocaleArg(Variable $var, string $function, int $position): ?string
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            return null;
        }

        return VmString::coerceStringBuiltinArg($var, $function, $position, 'locale');
    }

    public static function coerceBundleArg(Variable $var, string $function, int $position): ?string
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            return null;
        }

        return VmString::coerceStringBuiltinArg($var, $function, $position, 'bundlename');
    }

    /**
     * @return int|string php-src resourcebundle_get accepts int (by-index) or string (by-key)
     */
    public static function coerceIndexArg(Variable $var, string $function, int $position): int|string
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_INTEGER === $var->type) {
            return $var->toInt();
        }

        return VmString::coerceStringBuiltinArg($var, $function, $position, 'index');
    }

    public static function coerceBundleNameArg(Variable $var, string $function, int $position): string
    {
        return VmString::coerceStringBuiltinArg($var, $function, $position, 'bundle');
    }

    private static function fail(ObjectEntry $bundle, int $code, string $message): void
    {
        IntlError::set($code, $message);
        if (isset(self::$state[$bundle->id])) {
            self::$state[$bundle->id]['errorCode'] = $code;
            self::$state[$bundle->id]['errorMessage'] = $message;
        }
    }

    private static function clearObjectError(ObjectEntry $bundle): void
    {
        if (!isset(self::$state[$bundle->id])) {
            return;
        }
        self::$state[$bundle->id]['errorCode'] = IntlError::U_ZERO_ERROR;
        self::$state[$bundle->id]['errorMessage'] = 'U_ZERO_ERROR';
    }

    private static function fallbackVersion(): string
    {
        return '40';
    }

    /**
     * Build a HashTable of top-level entries (table keys or array indices) — php-src iterator.
     */
    private static function buildEntriesHashTable(Context $ctx, ObjectEntry $bundle): HashTable
    {
        $ht = new HashTable();
        $state = self::$state[$bundle->id] ?? null;
        if (null === $state) {
            return $ht;
        }
        if ($state['fallback'] || null === $state['handle']) {
            $v = new Variable();
            $v->string(self::fallbackVersion());
            $ht->add('Version', $v);

            return $ht;
        }
        $handle = $state['handle'];
        $size = self::uresGetSize($handle);
        if ($size <= 0) {
            return $ht;
        }
        $isTable = self::URES_TABLE === self::uresGetType($handle);
        for ($i = 0; $i < $size; ++$i) {
            $pair = self::extractByIndex($ctx, $handle, $i, $isTable);
            if (null === $pair) {
                continue;
            }
            [$key, $value] = $pair;
            $var = new Variable();
            if (\is_int($value)) {
                $var->int($value);
            } elseif (\is_string($value)) {
                $var->string($value);
            } elseif ($value instanceof ObjectEntry) {
                $var->object($value);
            } elseif ($value instanceof HashTable) {
                $var->array($value);
            } else {
                continue;
            }
            if (\is_int($key)) {
                $ht->append($var);
            } else {
                $ht->add($key, $var);
            }
        }

        return $ht;
    }

    /**
     * @return array{0: int|string, 1: string|int|ObjectEntry|HashTable}|null
     */
    private static function extractByIndex(Context $ctx, object $handle, int $index, bool $isTable): ?array
    {
        $child = self::uresGetByIndex($handle, $index);
        if (null === $child) {
            return null;
        }
        $key = $isTable ? self::uresGetKey($child) : $index;
        if ($isTable && (null === $key || '' === $key)) {
            $key = (string) $index;
        }
        $value = self::extractChildValue($ctx, $child);
        if (null === $value) {
            return null;
        }

        return [$key, $value];
    }

    /**
     * @return string|int|ObjectEntry|HashTable|null
     */
    private static function extractByKey(Context $ctx, object $handle, string $key)
    {
        $child = self::uresGetByKey($handle, $key);
        if (null === $child) {
            // Legacy string-only path (Version via ures_getStringByKey / getVersionNumber).
            return self::getStringByKey($handle, $key);
        }

        return self::extractChildValue($ctx, $child);
    }

    /**
     * @return string|int|ObjectEntry|HashTable|null
     */
    private static function extractValueByIndex(Context $ctx, object $handle, int $index)
    {
        $child = self::uresGetByIndex($handle, $index);
        if (null === $child) {
            return null;
        }

        return self::extractChildValue($ctx, $child);
    }

    /**
     * php-src resourcebundle_extract_value — consume $child (close or transfer ownership).
     *
     * @return string|int|ObjectEntry|HashTable|null
     */
    private static function extractChildValue(Context $ctx, object $child)
    {
        $type = self::uresGetType($child);
        switch ($type) {
            case self::URES_STRING:
                $str = self::uresGetString($child);
                self::uresClose($child);

                return $str;
            case self::URES_BINARY:
                $bin = self::uresGetBinary($child);
                self::uresClose($child);

                return $bin;
            case self::URES_INT:
                $int = self::uresGetInt($child);
                self::uresClose($child);

                return $int;
            case self::URES_INT_VECTOR:
                $vec = self::uresGetIntVector($child);
                self::uresClose($child);

                return $vec;
            case self::URES_ARRAY:
            case self::URES_TABLE:
                return self::wrapHandle($ctx, $child);
            default:
                self::uresClose($child);

                return null;
        }
    }

    /** @param object $handle */
    private static function wrapHandle(Context $ctx, object $handle): ObjectEntry
    {
        if (!isset($ctx->classes[self::CLASS_LC])) {
            throw new \Error('Class "ResourceBundle" not found');
        }
        $object = new ObjectEntry($ctx->classes[self::CLASS_LC]);
        $object->constructed = true;
        self::$state[$object->id] = [
            'locale' => '',
            'bundle' => null,
            'handle' => $handle,
            'fallback' => false,
            'errorCode' => IntlError::U_ZERO_ERROR,
            'errorMessage' => 'U_ZERO_ERROR',
        ];

        return $object;
    }

    /**
     * @return list<string>|null null = ICU unavailable / open failed
     */
    private static function enumerateAvailableLocales(?string $packageName): ?array
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return null;
        }
        $open = 'ures_openAvailableLocales'.self::$symSuffix;
        $next = 'uenum_next'.self::$symSuffix;
        $countFn = 'uenum_count'.self::$symSuffix;
        $close = 'uenum_close'.self::$symSuffix;
        try {
            $status = $ffi->new('UErrorCode');
            $status->cdata = 0;
            $en = $ffi->$open($packageName, \FFI::addr($status));
            if (null === $en || (int) $status->cdata > 0) {
                return null;
            }
            $status->cdata = 0;
            $count = (int) $ffi->$countFn($en, \FFI::addr($status));
            if ((int) $status->cdata > 0) {
                $count = 0;
                $status->cdata = 0;
            }
            $out = [];
            while (true) {
                $status->cdata = 0;
                $len = $ffi->new('int32_t');
                $len->cdata = 0;
                $entry = $ffi->$next($en, \FFI::addr($len), \FFI::addr($status));
                if (null === $entry || (int) $status->cdata > 0) {
                    break;
                }
                if (\is_string($entry)) {
                    $out[] = $entry;
                } else {
                    $n = (int) $len->cdata;
                    $out[] = $n > 0 ? \FFI::string($entry, $n) : \FFI::string($entry);
                }
            }
            $ffi->$close($en);
            if (0 === \count($out) && $count > 0) {
                return null;
            }

            return $out;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Open ICU resource bundle; capture UErrorCode including warnings (#22854).
     *
     * @return array{handle: ?object, status: int}
     */
    private static function openBundle(string $locale, ?string $bundleName, bool $fallback = true): array
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return ['handle' => null, 'status' => 0];
        }
        $open = ($fallback ? 'ures_open' : 'ures_openDirect').self::$symSuffix;
        try {
            $status = $ffi->new('UErrorCode');
            $status->cdata = 0;
            // null path = ICU default data; bundleName null uses root locale data package.
            $path = null !== $bundleName && '' !== $bundleName ? $bundleName : null;
            $rb = $ffi->$open($path, $locale, \FFI::addr($status));
            $code = (int) $status->cdata;
            if (null === $rb || $code > 0) {
                return ['handle' => null, 'status' => $code > 0 ? $code : IntlError::U_MISSING_RESOURCE_ERROR];
            }

            return ['handle' => $rb, 'status' => $code];
        } catch (\Throwable) {
            return ['handle' => null, 'status' => 0];
        }
    }

    /** @param object $handle */
    private static function uresGetLocale(object $handle): ?string
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return null;
        }
        $fn = 'ures_getLocaleByType'.self::$symSuffix;
        try {
            $status = $ffi->new('UErrorCode');
            $status->cdata = 0;
            // ULOC_ACTUAL_LOCALE = 0 (unicode/uloc.h)
            $loc = $ffi->$fn($handle, 0, \FFI::addr($status));
            if ((int) $status->cdata > 0 || null === $loc) {
                return null;
            }

            return \is_string($loc) ? $loc : \FFI::string($loc);
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param object $handle */
    private static function uresGetSize(object $handle): int
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return -1;
        }
        $fn = 'ures_getSize'.self::$symSuffix;
        try {
            return (int) $ffi->$fn($handle);
        } catch (\Throwable) {
            return -1;
        }
    }

    /** @param object $handle */
    private static function uresGetType(object $handle): int
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return -1;
        }
        $fn = 'ures_getType'.self::$symSuffix;
        try {
            return (int) $ffi->$fn($handle);
        } catch (\Throwable) {
            return -1;
        }
    }

    /** @param object $handle */
    private static function uresGetKey(object $handle): ?string
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return null;
        }
        $fn = 'ures_getKey'.self::$symSuffix;
        try {
            $key = $ffi->$fn($handle);
            if (null === $key) {
                return null;
            }

            return \is_string($key) ? $key : \FFI::string($key);
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param object $parent @return object|null */
    private static function uresGetByIndex(object $parent, int $index): ?object
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return null;
        }
        $fn = 'ures_getByIndex'.self::$symSuffix;
        try {
            $status = $ffi->new('UErrorCode');
            $status->cdata = 0;
            $child = $ffi->$fn($parent, $index, null, \FFI::addr($status));
            if (null === $child || (int) $status->cdata > 0) {
                return null;
            }

            return $child;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param object $parent @return object|null */
    private static function uresGetByKey(object $parent, string $key): ?object
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return null;
        }
        $fn = 'ures_getByKey'.self::$symSuffix;
        try {
            $status = $ffi->new('UErrorCode');
            $status->cdata = 0;
            $child = $ffi->$fn($parent, $key, null, \FFI::addr($status));
            if (null === $child || (int) $status->cdata > 0) {
                return null;
            }

            return $child;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param object $handle */
    private static function uresGetString(object $handle): ?string
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return null;
        }
        $fn = 'ures_getString'.self::$symSuffix;
        try {
            $status = $ffi->new('UErrorCode');
            $status->cdata = 0;
            $len = $ffi->new('int32_t');
            $len->cdata = 0;
            $ptr = $ffi->$fn($handle, \FFI::addr($len), \FFI::addr($status));
            if ((int) $status->cdata > 0 || null === $ptr) {
                return null;
            }
            $n = (int) $len->cdata;
            if ($n <= 0) {
                return '';
            }

            return self::uCharsToUtf8($ptr, $n);
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param object $handle */
    private static function uresGetBinary(object $handle): ?string
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return null;
        }
        $fn = 'ures_getBinary'.self::$symSuffix;
        try {
            $status = $ffi->new('UErrorCode');
            $status->cdata = 0;
            $len = $ffi->new('int32_t');
            $len->cdata = 0;
            $ptr = $ffi->$fn($handle, \FFI::addr($len), \FFI::addr($status));
            if ((int) $status->cdata > 0 || null === $ptr) {
                return null;
            }
            $n = (int) $len->cdata;
            if ($n <= 0) {
                return '';
            }

            return \FFI::string($ptr, $n);
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param object $handle */
    private static function uresGetInt(object $handle): ?int
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return null;
        }
        $fn = 'ures_getInt'.self::$symSuffix;
        try {
            $status = $ffi->new('UErrorCode');
            $status->cdata = 0;
            $val = (int) $ffi->$fn($handle, \FFI::addr($status));
            if ((int) $status->cdata > 0) {
                return null;
            }

            return $val;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param object $handle @return HashTable|null */
    private static function uresGetIntVector(object $handle): ?HashTable
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return null;
        }
        $fn = 'ures_getIntVector'.self::$symSuffix;
        try {
            $status = $ffi->new('UErrorCode');
            $status->cdata = 0;
            $len = $ffi->new('int32_t');
            $len->cdata = 0;
            $ptr = $ffi->$fn($handle, \FFI::addr($len), \FFI::addr($status));
            if ((int) $status->cdata > 0 || null === $ptr) {
                return null;
            }
            $n = (int) $len->cdata;
            $ht = new HashTable();
            for ($i = 0; $i < $n; ++$i) {
                $v = new Variable();
                $v->int((int) $ptr[$i]);
                $ht->append($v);
            }

            return $ht;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param object $handle */
    private static function uresClose(object $handle): void
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return;
        }
        $fn = 'ures_close'.self::$symSuffix;
        try {
            $ffi->$fn($handle);
        } catch (\Throwable) {
            // ignore
        }
    }

    /** @param object $handle */
    private static function getStringByKey(object $handle, string $key): ?string
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return null;
        }
        $fn = 'ures_getStringByKey'.self::$symSuffix;
        try {
            $status = $ffi->new('UErrorCode');
            $status->cdata = 0;
            $len = $ffi->new('int32_t');
            $len->cdata = 0;
            $ptr = $ffi->$fn($handle, $key, \FFI::addr($len), \FFI::addr($status));
            $code = (int) $status->cdata;
            // Warnings (negative) are OK — e.g. U_USING_DEFAULT_WARNING.
            if ($code > 0 || null === $ptr) {
                return null;
            }
            $n = (int) $len->cdata;
            if ($n <= 0) {
                // Some FFI builds return the version number as a PHP string via getVersionNumber.
                if ('Version' === $key) {
                    $verFn = 'ures_getVersionNumber'.self::$symSuffix;
                    $ver = $ffi->$verFn($handle);
                    if (\is_string($ver) && '' !== $ver) {
                        return $ver;
                    }
                }

                return null;
            }

            return self::uCharsToUtf8($ptr, $n);
        } catch (\Throwable) {
            if ('Version' === $key) {
                try {
                    $verFn = 'ures_getVersionNumber'.self::$symSuffix;
                    $ver = $ffi->$verFn($handle);
                    if (\is_string($ver) && '' !== $ver) {
                        return $ver;
                    }
                } catch (\Throwable) {
                    return null;
                }
            }

            return null;
        }
    }

    /** @param \FFI\CData|object $buf */
    private static function uCharsToUtf8(object $buf, int $len): string
    {
        $out = '';
        for ($i = 0; $i < $len; ++$i) {
            $u = (int) $buf[$i];
            if ($u < 0x80) {
                $out .= \chr($u);
            } elseif ($u < 0x800) {
                $out .= \chr(0xC0 | ($u >> 6)).\chr(0x80 | ($u & 0x3F));
            } else {
                $out .= \chr(0xE0 | ($u >> 12)).\chr(0x80 | (($u >> 6) & 0x3F)).\chr(0x80 | ($u & 0x3F));
            }
        }

        return $out;
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
            ['libicuuc.so.70', '_70'],
            ['libicuuc.so.74', '_74'],
            ['libicuuc.so.72', '_72'],
            ['libicuuc.so.71', '_71'],
            ['libicui18n.so.70', '_70'],
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
typedef uint16_t UChar;
typedef unsigned char uint8_t;
typedef struct UResourceBundle UResourceBundle;
typedef struct UEnumeration UEnumeration;
UResourceBundle *ures_open{$suffix}(const char *path, const char *locale, UErrorCode *status);
UResourceBundle *ures_openDirect{$suffix}(const char *path, const char *locale, UErrorCode *status);
void ures_close{$suffix}(UResourceBundle *resB);
const char *ures_getLocaleByType{$suffix}(const UResourceBundle *resB, int32_t type, UErrorCode *status);
int32_t ures_getSize{$suffix}(const UResourceBundle *resB);
int32_t ures_getType{$suffix}(const UResourceBundle *resB);
const char *ures_getKey{$suffix}(const UResourceBundle *resB);
UResourceBundle *ures_getByIndex{$suffix}(const UResourceBundle *resB, int32_t indexR, UResourceBundle *fillIn, UErrorCode *status);
UResourceBundle *ures_getByKey{$suffix}(const UResourceBundle *resB, const char *inKey, UResourceBundle *fillIn, UErrorCode *status);
const char *ures_getVersionNumber{$suffix}(const UResourceBundle *resB);
const UChar *ures_getString{$suffix}(const UResourceBundle *resB, int32_t *len, UErrorCode *status);
const UChar *ures_getStringByKey{$suffix}(const UResourceBundle *resB, const char *key, int32_t *len, UErrorCode *status);
const uint8_t *ures_getBinary{$suffix}(const UResourceBundle *resB, int32_t *len, UErrorCode *status);
int32_t ures_getInt{$suffix}(const UResourceBundle *resB, UErrorCode *status);
const int32_t *ures_getIntVector{$suffix}(const UResourceBundle *resB, int32_t *len, UErrorCode *status);
UEnumeration *ures_openAvailableLocales{$suffix}(const char *packageName, UErrorCode *status);
int32_t uenum_count{$suffix}(UEnumeration *en, UErrorCode *status);
const char *uenum_next{$suffix}(UEnumeration *en, int32_t *resultLength, UErrorCode *status);
void uenum_close{$suffix}(UEnumeration *en);
C;
    }
}

/** ResourceBundle::create() — php-src resourcebundle_create (#6187). */
final class ResourceBundleCreate extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('create');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 3) {
            throw new \ArgumentCountError(\sprintf(
                'ResourceBundle::create() expects between 1 and 3 arguments, %d given',
                $argc
            ));
        }
        $locale = VmResourceBundle::coerceLocaleArg($frame->calledArgs[0], 'ResourceBundle::create', 0);
        $bundle = null;
        if ($argc >= 2) {
            $bundle = VmResourceBundle::coerceBundleArg($frame->calledArgs[1], 'ResourceBundle::create', 1);
        }
        $fallback = true;
        if ($argc >= 3) {
            $fallback = LocaleLookup::coerceBool($frame->calledArgs[2], 'ResourceBundle::create', 2, 'fallback');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $object = VmResourceBundle::create($frame->vmContext, $locale, $bundle, $fallback);
        if (null === $object) {
            $frame->returnVar->null();

            return;
        }
        $frame->returnVar->object($object);
    }
}

/** ResourceBundle::get() — php-src resourcebundle_get (#6187). */
final class ResourceBundleGet extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('get');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'ResourceBundle::get() expects exactly 1 argument, %d given',
                max(0, $argc - 1)
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmResourceBundle::isResourceBundleObject($receiver->toObject())) {
            throw new \Error('ResourceBundle::get() called on incompatible object');
        }
        $index = VmResourceBundle::coerceIndexArg($frame->calledArgs[1], 'ResourceBundle::get', 1);
        $result = VmResourceBundle::get($frame->vmContext, $receiver->toObject(), $index);
        if (null === $frame->returnVar) {
            return;
        }
        if (null === $result) {
            $frame->returnVar->null();

            return;
        }
        if (\is_int($result)) {
            $frame->returnVar->int($result);

            return;
        }
        if ($result instanceof ObjectEntry) {
            $frame->returnVar->object($result);

            return;
        }
        if ($result instanceof HashTable) {
            $frame->returnVar->array($result);

            return;
        }
        $frame->returnVar->string($result);
    }
}

/** ResourceBundle::count() — php-src resourcebundle_count (#6187). */
final class ResourceBundleCount extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('count');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'ResourceBundle::count() expects exactly 0 arguments, %d given',
                max(0, $argc - 1)
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmResourceBundle::isResourceBundleObject($receiver->toObject())) {
            throw new \Error('ResourceBundle::count() called on incompatible object');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(VmResourceBundle::count($receiver->toObject()));
    }
}

/** ResourceBundle::getLocales() — php-src resourcebundle_locales (#20739). */
final class ResourceBundleGetLocales extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getLocales');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'ResourceBundle::getLocales() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $bundle = VmResourceBundle::coerceBundleNameArg($frame->calledArgs[0], 'ResourceBundle::getLocales', 0);
        $locales = VmResourceBundle::getLocales($bundle);
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $locales) {
            $frame->returnVar->bool(false);

            return;
        }
        $ht = new HashTable();
        foreach ($locales as $locale) {
            $v = new Variable();
            $v->string($locale);
            $ht->append($v);
        }
        $frame->returnVar->array($ht);
    }
}

/** ResourceBundle::getErrorCode() — php-src resourcebundle_get_error_code (#20739). */
final class ResourceBundleGetErrorCode extends VmClassMethod
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
                'ResourceBundle::getErrorCode() expects exactly 0 arguments, %d given',
                max(0, $argc - 1)
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmResourceBundle::isResourceBundleObject($receiver->toObject())) {
            throw new \Error('ResourceBundle::getErrorCode() called on incompatible object');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(VmResourceBundle::getErrorCode($receiver->toObject()));
    }
}

/** ResourceBundle::getErrorMessage() — php-src resourcebundle_get_error_message (#20739). */
final class ResourceBundleGetErrorMessage extends VmClassMethod
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
                'ResourceBundle::getErrorMessage() expects exactly 0 arguments, %d given',
                max(0, $argc - 1)
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmResourceBundle::isResourceBundleObject($receiver->toObject())) {
            throw new \Error('ResourceBundle::getErrorMessage() called on incompatible object');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string(VmResourceBundle::getErrorMessage($receiver->toObject()));
    }
}

/** ResourceBundle::getIterator() — php-src resourcebundle getIterator (#20739). */
final class ResourceBundleGetIterator extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getIterator');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'ResourceBundle::getIterator() expects exactly 0 arguments, %d given',
                max(0, $argc - 1)
            ));
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type
            || !VmResourceBundle::isResourceBundleObject($receiver->toObject())) {
            throw new \Error('ResourceBundle::getIterator() called on incompatible object');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->object(VmResourceBundle::getIterator($frame->vmContext, $receiver->toObject()));
    }
}
