<?php

declare(strict_types=1);

namespace PHPCompiler\ext\gd;

use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;

/**
 * Register gd extension placeholders (php-src ext/gd/gd.c; issue #7407).
 *
 * GdImage registers only when {@see GdExtensionPolicy::advertisesExtension()} (#11675).
 */
final class BuiltinClasses
{
    public static function register(Context $ctx): void
    {
        if (!GdExtensionPolicy::advertisesExtension()) {
            return;
        }

        $before = array_keys($ctx->classes);
        self::registerGdImage($ctx);
        foreach (array_diff(array_keys($ctx->classes), $before) as $lc) {
            $ctx->classes[$lc]->isInternal = true;
        }
    }

    private static function registerGdImage(Context $ctx): void
    {
        if (isset($ctx->classes['gdimage'])) {
            return;
        }

        $ctx->classes['gdimage'] = new ClassEntry('GdImage');
    }
}
