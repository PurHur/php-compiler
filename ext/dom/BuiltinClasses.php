<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCfg\Func as CfgFunc;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ExceptionSupport;
use PHPCompiler\ext\standard\ThrowableManifest;

/** Register dom extension builtin classes (php-src ext/dom/php_dom.c; issue #6140). */
final class BuiltinClasses
{
    public static function register(Context $ctx): void
    {
        VmDom::registerClasses($ctx);
        DomLivingBuiltinClasses::register($ctx);
        // php-src ext/dom/php_dom.c — XML_*_NODE + DOM_PHP_ERR globals (#23138).
        DomConstants::registerGlobals($ctx);
        self::registerDomExceptionConstants($ctx);
        self::syncDeclaredMethodNames($ctx);
    }

    /**
     * Fill ClassEntry::$methodNames from Internal handler getName() when missing (#21283).
     *
     * Registration keys are lowercase; Reflection and error messages need Zend camelCase.
     */
    private static function syncDeclaredMethodNames(Context $ctx): void
    {
        foreach ($ctx->classes as $entry) {
            $name = $entry->name;
            if (!str_starts_with($name, 'DOM') && !str_starts_with($name, 'Dom\\')) {
                continue;
            }
            foreach ($entry->methods as $lc => $handler) {
                if (isset($entry->methodNames[$lc])) {
                    continue;
                }
                $declared = $handler->getName();
                if (str_contains($declared, '::')) {
                    $declared = substr($declared, strrpos($declared, '::') + 2);
                }
                $entry->methodNames[$lc] = $declared;
            }
        }
    }

    private static function registerDomExceptionConstants(Context $ctx): void
    {
        DomExceptionConstants::registerGlobals($ctx);
        $entry = $ctx->classes[ThrowableManifest::LC_DOM_EXCEPTION] ?? null;
        if (null !== $entry) {
            DomExceptionConstants::registerOnClass($entry);
            // php-src ext/dom/php_dom.c: DOMException redeclares $code as public
            // (Exception declares it protected; DOMException widens it).
            foreach ($entry->properties as $prop) {
                if (ExceptionSupport::PROP_CODE === $prop->name) {
                    $prop->visibility = CfgFunc::FLAG_PUBLIC;
                    $prop->declaringClassLc = ThrowableManifest::LC_DOM_EXCEPTION;
                    break;
                }
            }
        }
    }
}
