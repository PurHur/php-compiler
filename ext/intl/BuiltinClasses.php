<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCfg\Func as CfgFunc;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;

/**
 * Register intl builtin classes (php-src ext/intl/php_intl.c; issue #5774).
 *
 * ICU algorithms land in #3336, #5747, #5201; skeleton classes register only when
 * {@see IntlExtensionPolicy::advertisesBuiltins()} (#12115).
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

    public static function register(Context $ctx): void
    {
        $before = array_keys($ctx->classes);
        self::registerIntlDateFormatter($ctx);
        self::registerCollator($ctx);
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
        $ctx->classes['normalizer'] = $entry;
    }

    private static function registerLocaleClass(Context $ctx): void
    {
        $entry = new ClassEntry('Locale');
        $pubStatic = CfgFunc::FLAG_PUBLIC | CfgFunc::FLAG_STATIC;
        $entry->methods['getdefault'] = new LocaleGetDefault();
        $entry->methodVisibility['getdefault'] = $pubStatic;
        $entry->methodNames['getdefault'] = 'getDefault';
        $entry->methods['setdefault'] = new LocaleSetDefault();
        $entry->methodVisibility['setdefault'] = $pubStatic;
        $entry->methodNames['setdefault'] = 'setDefault';
        $ctx->classes['locale'] = $entry;
    }

    private static function registerIntlDateFormatter(Context $ctx): void
    {
        $entry = new ClassEntry('IntlDateFormatter');
        $pubStatic = CfgFunc::FLAG_PUBLIC | CfgFunc::FLAG_STATIC;
        $entry->methods['create'] = new IntlDateFormatterCreate();
        $entry->methodVisibility['create'] = $pubStatic;
        $entry->methodNames['create'] = 'create';
        $ctx->classes['intldateformatter'] = $entry;
    }

    private static function registerCollator(Context $ctx): void
    {
        $entry = new ClassEntry('Collator');
        $pubStatic = CfgFunc::FLAG_PUBLIC | CfgFunc::FLAG_STATIC;
        $entry->methods['create'] = new CollatorCreate();
        $entry->methodVisibility['create'] = $pubStatic;
        $entry->methodNames['create'] = 'create';
        $ctx->classes['collator'] = $entry;
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
