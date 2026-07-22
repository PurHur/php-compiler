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
        // php-src ext/session/session.stub.php — interfaces do not extend each other (#22262).
        self::registerInterface($ctx, 'SessionHandlerInterface', [], [
            'open', 'close', 'read', 'write', 'destroy', 'gc',
        ]);
        self::registerInterface($ctx, 'SessionIdInterface', [], ['create_sid']);
        self::registerInterface($ctx, 'SessionUpdateTimestampHandlerInterface', [], [
            'validateId', 'updateTimestamp',
        ]);
        self::registerInterface($ctx, 'Random\\Engine', []);
        self::registerInterface($ctx, 'Random\\CryptoSafeEngine', ['random\\engine']);
    }

    /**
     * @param list<string> $parentLcs lowercase parent interface keys
     * @param list<string> $methods interface method names (zend_API.c / #11786)
     */
    private static function registerInterface(Context $ctx, string $name, array $parentLcs, array $methods = []): void
    {
        $lc = strtolower(ltrim($name, '\\'));
        if (isset($ctx->classes[$lc])) {
            return;
        }
        $entry = new ClassEntry($name);
        $entry->isInterface = true;
        $entry->interfaces = $parentLcs;
        if ('seekableiterator' === $lc) {
            BuiltinClasses::registerBuiltinInterfaceMethods($entry, ['seek']);
        } elseif ($methods !== []) {
            BuiltinClasses::registerBuiltinInterfaceMethods($entry, $methods);
        }
        $ctx->classes[$lc] = $entry;
    }
}
