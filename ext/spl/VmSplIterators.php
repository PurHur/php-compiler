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
    /**
     * RecursiveIterator / OuterIterator must exist before FilterIterator / ParentIterator /
     * RecursiveRegexIterator / IteratorIterator attach `$entry->interfaces` (#19784).
     */
    public static function registerCoreInterfaces(Context $ctx): void
    {
        self::registerRecursiveIteratorInterface($ctx);
        self::registerOuterIteratorInterface($ctx);
    }

    public static function register(Context $ctx): void
    {
        self::registerCoreInterfaces($ctx);
        InternalIteratorBuiltin::registerClass($ctx);
        EmptyIteratorBuiltin::registerClass($ctx);
        ArrayIteratorBuiltin::registerClass($ctx);
        RecursiveArrayIteratorBuiltin::registerClass($ctx);
        RecursiveCallbackFilterIteratorBuiltin::registerClass($ctx);
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
        // Zend ce->interfaces is flattened: Iterator then Traversable (spl_iterators.c).
        // Reverse-parent insert expands OuterIterator to OuterIterator,Traversable,Iterator
        // (IteratorIterator / parent-walk order). class_implements(OuterIterator) lists
        // parents in declaration order → Iterator,Traversable (#25798).
        if (isset($ctx->classes['iterator'])) {
            $entry->interfaces[] = 'iterator';
        }
        if (isset($ctx->classes['traversable'])) {
            $entry->interfaces[] = 'traversable';
        }
        $ctx->classes['outeriterator'] = $entry;
    }
}
