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
 * ResourceBundle create/get/locales/errors — ICU ures_* via thin FFI (#6187, #20739).
 *
 * php-src: ext/intl/resourcebundle/resourcebundle_class.cpp
 * ICU: unicode/ures.h — ures_open_N / ures_getStringByKey_N / ures_openAvailableLocales_N / ures_close_N
 */
final class VmResourceBundle
{
    public const CLASS_LC = 'resourcebundle';

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
        // php-src ResourceBundle implements Countable (#20781).
        $entry->interfaces[] = 'countable';
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
    public static function create(Context $ctx, ?string $locale, ?string $bundleName): ?ObjectEntry
    {
        if (!isset($ctx->classes[self::CLASS_LC])) {
            throw new \Error('Class "ResourceBundle" not found');
        }
        $locale = null !== $locale && '' !== $locale ? $locale : VmLocale::getDefault();
        $handle = self::openBundle($locale, $bundleName);
        $fallback = null === $handle;
        if ($fallback && null !== $bundleName && '' !== $bundleName) {
            // Non-default bundles require real ICU data — fail like Zend on missing package.
            IntlError::set(
                IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                'resourcebundle_create: cannot locate resource data: U_MISSING_RESOURCE_ERROR'
            );

            return null;
        }
        $object = new ObjectEntry($ctx->classes[self::CLASS_LC]);
        $object->constructed = true;
        self::$state[$object->id] = [
            'locale' => $locale,
            'bundle' => $bundleName,
            'handle' => $handle,
            'fallback' => $fallback,
            'errorCode' => IntlError::U_ZERO_ERROR,
            'errorMessage' => 'U_ZERO_ERROR',
        ];
        if ($fallback) {
            IntlError::set(
                IntlError::U_USING_FALLBACK_WARNING,
                'resourcebundle_create: ICU data unavailable; using Version fallback: U_USING_DEFAULT_WARNING'
            );
            self::$state[$object->id]['errorCode'] = IntlError::U_USING_FALLBACK_WARNING;
            self::$state[$object->id]['errorMessage'] = IntlError::getMessage();
        } elseif (IntlError::U_ZERO_ERROR === IntlError::getCode()) {
            IntlError::clear();
        }

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
     * @return string|int|null null = missing/failed lookup (php-src returns null)
     */
    public static function get(ObjectEntry $bundle, string $index)
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
            $str = self::getStringByKey($state['handle'], $index);
            if (null !== $str) {
                self::clearObjectError($bundle);
                IntlError::clear();

                return $str;
            }
            // Fall through for Version when key lookup fails with warning-only.
        }
        if ($state['fallback'] || 'Version' === $index) {
            self::clearObjectError($bundle);
            IntlError::clear();
            if ('Version' === $index) {
                return self::fallbackVersion();
            }
        }
        $message = "Cannot load resource element '".$index."': U_MISSING_RESOURCE_ERROR";
        self::fail($bundle, IntlError::U_MISSING_RESOURCE_ERROR, $message);

        return null;
    }

    public static function count(ObjectEntry $bundle): int
    {
        $state = self::$state[$bundle->id] ?? null;
        if (null === $state) {
            return 0;
        }
        if ($state['fallback']) {
            return 1; // synthetic Version key
        }
        // v1: do not enumerate full ICU tree — report at least Version presence.
        return 1;
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
     * ResourceBundle::getIterator() — php-src returns InternalIterator; expose ArrayIterator over top-level keys (#20739).
     */
    public static function getIterator(Context $ctx, ObjectEntry $bundle): ObjectEntry
    {
        $class = $ctx->classes[ArrayIteratorBuiltin::CLASS_LC] ?? null;
        if (null === $class) {
            throw new \LogicException('ArrayIterator is not registered in this compiler build');
        }
        $ht = new HashTable();
        $version = self::peekVersion($bundle);
        if (null !== $version) {
            $v = new Variable();
            $v->string($version);
            $ht->add('Version', $v);
        }
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

    public static function coerceIndexArg(Variable $var, string $function, int $position): string
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_INTEGER === $var->type) {
            return (string) $var->toInt();
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

    private static function peekVersion(ObjectEntry $bundle): ?string
    {
        $state = self::$state[$bundle->id] ?? null;
        if (null === $state) {
            return null;
        }
        if (null !== $state['handle']) {
            $str = self::getStringByKey($state['handle'], 'Version');
            if (null !== $str) {
                return $str;
            }
        }

        return self::fallbackVersion();
    }

    private static function fallbackVersion(): string
    {
        return '40';
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

    /** @return object|null */
    private static function openBundle(string $locale, ?string $bundleName): ?object
    {
        $ffi = self::ffi();
        if (null === $ffi) {
            return null;
        }
        $open = 'ures_open'.self::$symSuffix;
        try {
            $status = $ffi->new('UErrorCode');
            $status->cdata = 0;
            // null path = ICU default data; bundleName null uses root locale data package.
            $path = null !== $bundleName && '' !== $bundleName ? $bundleName : null;
            $rb = $ffi->$open($path, $locale, \FFI::addr($status));
            $code = (int) $status->cdata;
            if (null === $rb || $code > 0) {
                return null;
            }

            return $rb;
        } catch (\Throwable) {
            return null;
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
typedef struct UResourceBundle UResourceBundle;
typedef struct UEnumeration UEnumeration;
UResourceBundle *ures_open{$suffix}(const char *path, const char *locale, UErrorCode *status);
void ures_close{$suffix}(UResourceBundle *resB);
const char *ures_getVersionNumber{$suffix}(const UResourceBundle *resB);
const UChar *ures_getStringByKey{$suffix}(const UResourceBundle *resB, const char *key, int32_t *len, UErrorCode *status);
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
        if (null === $frame->returnVar) {
            return;
        }
        $object = VmResourceBundle::create($frame->vmContext, $locale, $bundle);
        if (null === $object) {
            $frame->returnVar->bool(false);

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
        $result = VmResourceBundle::get($receiver->toObject(), $index);
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
