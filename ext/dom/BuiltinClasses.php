<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\VM\Context;
use PHPCompiler\ext\standard\ThrowableManifest;

/** Register dom extension builtin classes (php-src ext/dom/php_dom.c; issue #6140). */
final class BuiltinClasses
{
    public static function register(Context $ctx): void
    {
        VmDom::registerClasses($ctx);
        DomLivingBuiltinClasses::register($ctx);
        self::registerDomExceptionConstants($ctx);
    }

    private static function registerDomExceptionConstants(Context $ctx): void
    {
        DomExceptionConstants::registerGlobals($ctx);
        $entry = $ctx->classes[ThrowableManifest::LC_DOM_EXCEPTION] ?? null;
        if (null !== $entry) {
            DomExceptionConstants::registerOnClass($entry);
        }
    }
}
