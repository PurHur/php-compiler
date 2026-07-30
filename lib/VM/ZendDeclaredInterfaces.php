<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\Compiler\ParameterMetadata;

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
        // php-src ext/dom/php_dom.stub.php — DOMChildNode does not extend DOMParentNode (#22389).
        self::registerInterface($ctx, 'DOMParentNode', []);
        self::registerInterface($ctx, 'DOMChildNode', []);
        // php-src ext/session/session.stub.php — interfaces do not extend each other (#22262).
        self::registerInterface($ctx, 'SessionHandlerInterface', [], [
            'open', 'close', 'read', 'write', 'destroy', 'gc',
        ]);
        self::attachSessionHandlerInterfaceArginfo($ctx);
        self::registerInterface($ctx, 'SessionIdInterface', [], ['create_sid']);
        self::registerInterface($ctx, 'SessionUpdateTimestampHandlerInterface', [], [
            'validateId', 'updateTimestamp',
        ]);
        self::attachSessionUpdateTimestampHandlerInterfaceArginfo($ctx);
        self::registerInterface($ctx, 'Random\\Engine', []);
        self::registerInterface($ctx, 'Random\\CryptoSafeEngine', ['random\\engine']);
    }

    /**
     * Stub arginfo for LSP + Reflection (php-src ext/session/session.stub.php; #25426).
     *
     * Return types are @tentative-return-type in Zend — leave methodReturnDeclaredTypes
     * unset so ReflectionMethod::hasReturnType() stays false.
     */
    private static function attachSessionHandlerInterfaceArginfo(Context $ctx): void
    {
        $entry = $ctx->classes['sessionhandlerinterface'] ?? null;
        if (null === $entry) {
            return;
        }
        $entry->methodParameterMetadata['open'] = [
            self::typedParam('path', 'string'),
            self::typedParam('name', 'string'),
        ];
        $entry->methodParameterMetadata['close'] = [];
        $entry->methodParameterMetadata['read'] = [
            self::typedParam('id', 'string'),
        ];
        $entry->methodParameterMetadata['write'] = [
            self::typedParam('id', 'string'),
            self::typedParam('data', 'string'),
        ];
        $entry->methodParameterMetadata['destroy'] = [
            self::typedParam('id', 'string'),
        ];
        $entry->methodParameterMetadata['gc'] = [
            self::typedParam('max_lifetime', 'int'),
        ];
    }

    /** php-src ext/session/session.stub.php — validateId/updateTimestamp (#25426). */
    private static function attachSessionUpdateTimestampHandlerInterfaceArginfo(Context $ctx): void
    {
        $entry = $ctx->classes['sessionupdatetimestamphandlerinterface'] ?? null;
        if (null === $entry) {
            return;
        }
        $entry->methodParameterMetadata['validateid'] = [
            self::typedParam('id', 'string'),
        ];
        $entry->methodParameterMetadata['updatetimestamp'] = [
            self::typedParam('id', 'string'),
            self::typedParam('data', 'string'),
        ];
    }

    private static function typedParam(string $name, string $typeString): ParameterMetadata
    {
        return new ParameterMetadata(
            $name,
            [],
            false,
            false,
            false,
            false,
            $typeString,
            null,
        );
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
