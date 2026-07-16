<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCfg\Func as CfgFunc;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;

/**
 * Register intl builtin classes (php-src ext/intl/php_intl.c; issues #5774, #6696, #19549, #6151, #19670).
 *
 * Locale / IntlDateFormatter / IntlCalendar / IntlTimeZone / NumberFormatter / Normalizer / Collator /
 * MessageFormatter / IntlException all gate on {@see IntlExtensionPolicy} advertisement
 * (no phantom class_exists).
 */
final class BuiltinClasses
{
    public static function registerLocale(Context $ctx): void
    {
        $before = array_keys($ctx->classes);
        self::registerLocaleClass($ctx);
        foreach (array_diff(array_keys($ctx->classes), $before) as $lc) {
            $ctx->classes[$lc]->isInternal = true;
        }
    }

    public static function registerIntlDateFormatter(Context $ctx): void
    {
        $before = array_keys($ctx->classes);
        self::registerIntlDateFormatterClass($ctx);
        foreach (array_diff(array_keys($ctx->classes), $before) as $lc) {
            $ctx->classes[$lc]->isInternal = true;
        }
    }

    public static function registerIntlCalendar(Context $ctx): void
    {
        $before = array_keys($ctx->classes);
        VmIntlTimeZone::registerClass($ctx);
        VmIntlCalendar::registerClass($ctx);
        foreach (array_diff(array_keys($ctx->classes), $before) as $lc) {
            $ctx->classes[$lc]->isInternal = true;
        }
    }

    public static function registerNumberFormatter(Context $ctx): void
    {
        $before = array_keys($ctx->classes);
        VmNumberFormatter::registerClass($ctx);
        foreach (array_diff(array_keys($ctx->classes), $before) as $lc) {
            $ctx->classes[$lc]->isInternal = true;
        }
    }

    public static function register(Context $ctx): void
    {
        $before = array_keys($ctx->classes);
        self::registerIntlDateFormatterClass($ctx);
        VmIntlTimeZone::registerClass($ctx);
        VmIntlCalendar::registerClass($ctx);
        VmNumberFormatter::registerClass($ctx);
        VmCollator::registerClass($ctx);
        VmMessageFormatter::registerClass($ctx);
        self::registerIntlException($ctx);
        foreach (array_diff(array_keys($ctx->classes), $before) as $lc) {
            $ctx->classes[$lc]->isInternal = true;
        }
    }

    public static function registerNormalizer(Context $ctx): void
    {
        $entry = new ClassEntry('Normalizer');
        $entry->isInternal = true;
        foreach (VmNormalizer::classConstants() as $name => $value) {
            $lc = strtolower($name);
            $const = new \PHPCompiler\VM\Variable(\PHPCompiler\VM\Variable::TYPE_INTEGER);
            $const->int($value);
            $entry->constants[$lc] = $const;
            $entry->constNames[$lc] = $name;
        }
        $pubStatic = CfgFunc::FLAG_PUBLIC | CfgFunc::FLAG_STATIC;
        $methods = [
            'normalize' => [new NormalizerNormalize(), 'normalize'],
            'isnormalized' => [new NormalizerIsNormalized(), 'isNormalized'],
            'getrawdecomposition' => [new NormalizerGetRawDecomposition(), 'getRawDecomposition'],
        ];
        foreach ($methods as $lc => [$handler, $name]) {
            $entry->methods[$lc] = $handler;
            $entry->methodVisibility[$lc] = $pubStatic;
            $entry->methodNames[$lc] = $name;
        }
        $ctx->classes['normalizer'] = $entry;
    }

    private static function registerLocaleClass(Context $ctx): void
    {
        if (isset($ctx->classes['locale'])) {
            return;
        }

        $entry = new ClassEntry('Locale');
        $pubStatic = CfgFunc::FLAG_PUBLIC | CfgFunc::FLAG_STATIC;
        $methods = [
            'getdefault' => [new LocaleGetDefault(), 'getDefault'],
            'setdefault' => [new LocaleSetDefault(), 'setDefault'],
            'getprimarylanguage' => [new LocaleGetPrimaryLanguage(), 'getPrimaryLanguage'],
            'getregion' => [new LocaleGetRegion(), 'getRegion'],
            'getscript' => [new LocaleGetScript(), 'getScript'],
            'getdisplayname' => [new LocaleGetDisplayName(), 'getDisplayName'],
        ];
        foreach ($methods as $lc => [$handler, $name]) {
            $entry->methods[$lc] = $handler;
            $entry->methodVisibility[$lc] = $pubStatic;
            $entry->methodNames[$lc] = $name;
        }
        $ctx->classes['locale'] = $entry;
    }

    private static function registerIntlDateFormatterClass(Context $ctx): void
    {
        if (isset($ctx->classes[VmIntlDateFormatter::CLASS_LC])) {
            return;
        }

        $entry = new ClassEntry('IntlDateFormatter');
        $entry->isInternal = true;
        foreach (VmIntlDateFormatter::classConstants() as $name => $value) {
            $lc = strtolower($name);
            $const = new \PHPCompiler\VM\Variable(\PHPCompiler\VM\Variable::TYPE_INTEGER);
            $const->int($value);
            $entry->constants[$lc] = $const;
            $entry->constNames[$lc] = $name;
        }
        $pub = CfgFunc::FLAG_PUBLIC;
        $pubStatic = $pub | CfgFunc::FLAG_STATIC;
        $entry->methods['create'] = new IntlDateFormatterCreate();
        $entry->methodVisibility['create'] = $pubStatic;
        $entry->methodNames['create'] = 'create';
        $entry->methods['format'] = new IntlDateFormatterFormat();
        $entry->methodVisibility['format'] = $pub;
        $entry->methodNames['format'] = 'format';
        $ctx->classes[VmIntlDateFormatter::CLASS_LC] = $entry;
    }

    public static function registerCollator(Context $ctx): void
    {
        $before = array_keys($ctx->classes);
        VmCollator::registerClass($ctx);
        foreach (array_diff(array_keys($ctx->classes), $before) as $lc) {
            $ctx->classes[$lc]->isInternal = true;
        }
    }

    public static function registerMessageFormatter(Context $ctx): void
    {
        $before = array_keys($ctx->classes);
        VmMessageFormatter::registerClass($ctx);
        foreach (array_diff(array_keys($ctx->classes), $before) as $lc) {
            $ctx->classes[$lc]->isInternal = true;
        }
    }

    private static function registerIntlException(Context $ctx): void
    {
        $entry = new ClassEntry('IntlException');
        if (isset($ctx->classes['exception'])) {
            $entry->parentLc = 'exception';
        }
        $ctx->classes['intlexception'] = $entry;
    }
}
