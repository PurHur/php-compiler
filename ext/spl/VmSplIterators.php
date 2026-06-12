<?php

declare(strict_types=1);

namespace PHPCompiler\ext\spl;

use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;

/**
 * SPL iterator interfaces and classes (php-src ext/spl/spl_iterators.c; issue #6593).
 */
final class VmSplIterators
{
    public static function register(Context $ctx): void
    {
        self::registerRecursiveIteratorInterface($ctx);
        self::registerOuterIteratorInterface($ctx);
        EmptyIteratorBuiltin::registerClass($ctx);
        RecursiveArrayIteratorBuiltin::registerClass($ctx);
    }

    private static function registerRecursiveIteratorInterface(Context $ctx): void
    {
        if (isset($ctx->classes['recursiveiterator'])) {
            return;
        }

        $entry = new ClassEntry('RecursiveIterator');
        $entry->isInterface = true;
        if (isset($ctx->classes['iterator'])) {
            $entry->interfaces[] = 'iterator';
        }
        $ctx->classes['recursiveiterator'] = $entry;
    }

    private static function registerOuterIteratorInterface(Context $ctx): void
    {
        if (isset($ctx->classes['outeriterator'])) {
            return;
        }

        $entry = new ClassEntry('OuterIterator');
        $entry->isInterface = true;
        if (isset($ctx->classes['traversable'])) {
            $entry->interfaces[] = 'traversable';
        }
        $ctx->classes['outeriterator'] = $entry;
    }
}
