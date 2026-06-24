<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Zend startup interfaces missing from early BuiltinClasses registration (#11247).
 *
 * php-src: ext/dom, ext/reflection, ext/session, ext/random, ext/spl/spl_iterators.c
 */
final class ZendDeclaredInterfaces
{
    public static function register(Context $ctx): void
    {
        self::registerInterface($ctx, 'SeekableIterator', ['iterator']);
        self::registerInterface($ctx, 'Reflector', []);
        self::registerInterface($ctx, 'DOMParentNode', []);
        self::registerInterface($ctx, 'DOMChildNode', ['domparentnode']);
        self::registerInterface($ctx, 'SessionHandlerInterface', []);
        self::registerInterface($ctx, 'SessionIdInterface', ['sessionhandlerinterface']);
        self::registerInterface($ctx, 'SessionUpdateTimestampHandlerInterface', ['sessionhandlerinterface']);
        self::registerInterface($ctx, 'Random\\Engine', []);
        self::registerInterface($ctx, 'Random\\CryptoSafeEngine', ['random\\engine']);
    }

    /**
     * @param list<string> $parentLcs lowercase parent interface keys
     */
    private static function registerInterface(Context $ctx, string $name, array $parentLcs): void
    {
        $lc = strtolower(ltrim($name, '\\'));
        if (isset($ctx->classes[$lc])) {
            return;
        }
        $entry = new ClassEntry($name);
        $entry->isInterface = true;
        $entry->interfaces = $parentLcs;
        $ctx->classes[$lc] = $entry;
    }
}
