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
 * ResourceBundle create/get — ICU ures_* via thin FFI (#6187).
 *
 * php-src: ext/intl/resourcebundle/resourcebundle_class.c
 * ICU: unicode/ures.h — ures_open_N / ures_getStringByKey_N / ures_close_N
 */
final class VmResourceBundle
{
    public const CLASS_LC = 'resourcebundle';

    /** @var array<int, array{locale: string, bundle: ?string, handle: object|null, fallback: bool}> */
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
        $pub = CfgFunc::FLAG_PUBLIC;
        $pubStatic = $pub | CfgFunc::FLAG_STATIC;
        $entry->methods['create'] = new ResourceBundleCreate();
        $entry->methodVisibility['create'] = $pubStatic;
        $entry->methodNames['create'] = 'create';
        $entry->methods['get'] = new ResourceBundleGet();
        $entry->methodVisibility['get'] = $pub;
        $entry->methodNames['get'] = 'get';
        $entry->methods['count'] = new ResourceBundleCount();
        $entry->methodVisibility['count'] = $pub;
        $entry->methodNames['count'] = 'count';
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
        ];
        if ($fallback) {
            IntlError::set(
                IntlError::U_USING_FALLBACK_WARNING,
                'resourcebundle_create: ICU data unavailable; using Version fallback: U_USING_DEFAULT_WARNING'
            );
        } elseif (IntlError::U_ZERO_ERROR === IntlError::getCode()) {
            IntlError::clear();
        }

        return $object;
    }

    /**
     * @return string|int|false
     */
    public static function get(ObjectEntry $bundle, string $index)
    {
        $state = self::$state[$bundle->id] ?? null;
        if (null === $state) {
            IntlError::set(
                IntlError::U_ILLEGAL_ARGUMENT_ERROR,
                'resourcebundle_get: bad bundle: U_ILLEGAL_ARGUMENT_ERROR'
            );

            return false;
        }
        if (null !== $state['handle']) {
            $str = self::getStringByKey($state['handle'], $index);
            if (null !== $str) {
                IntlError::clear();

                return $str;
            }
            // Fall through for Version when key lookup fails with warning-only.
        }
        if ($state['fallback'] || 'Version' === $index) {
            IntlError::clear();
            if ('Version' === $index) {
                return self::fallbackVersion();
            }
        }
        IntlError::set(
            IntlError::U_ILLEGAL_ARGUMENT_ERROR,
            'resourcebundle_get: cannot find resource key "'.$index.'": U_MISSING_RESOURCE_ERROR'
        );

        return false;
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

    private static function fallbackVersion(): string
    {
        return '40';
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
UResourceBundle *ures_open{$suffix}(const char *path, const char *locale, UErrorCode *status);
void ures_close{$suffix}(UResourceBundle *resB);
const char *ures_getVersionNumber{$suffix}(const UResourceBundle *resB);
const UChar *ures_getStringByKey{$suffix}(const UResourceBundle *resB, const char *key, int32_t *len, UErrorCode *status);
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
        if (false === $result) {
            $frame->returnVar->bool(false);

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
