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
