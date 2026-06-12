<?php

declare(strict_types=1);

namespace PHPCompiler\ext\spl;

use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;

/**
 * Register SPL builtin classes (php-src ext/spl; issue #4769).
 *
 * Behavior lives in follow-up issues (#3330, #4689); v1 skeleton enables class_exists() and inventory.
 */
final class BuiltinClasses
{
    public static function register(Context $ctx): void
    {
        $before = array_keys($ctx->classes);
        self::registerArrayObject($ctx);
        self::registerSplDoublyLinkedList($ctx);
        VmSplIterators::register($ctx);
        foreach (array_diff(array_keys($ctx->classes), $before) as $lc) {
            $ctx->classes[$lc]->isInternal = true;
        }
    }

    private static function registerArrayObject(Context $ctx): void
    {
        $entry = new ClassEntry('ArrayObject');
        if (isset($ctx->classes['iteratoraggregate'])) {
            $entry->interfaces[] = 'iteratoraggregate';
        }
        if (isset($ctx->classes['countable'])) {
            $entry->interfaces[] = 'countable';
        }
        $ctx->classes['arrayobject'] = $entry;
    }

    private static function registerSplDoublyLinkedList(Context $ctx): void
    {
        $entry = new ClassEntry('SplDoublyLinkedList');
        if (isset($ctx->classes['iterator'])) {
            $entry->interfaces[] = 'iterator';
        }
        if (isset($ctx->classes['countable'])) {
            $entry->interfaces[] = 'countable';
        }
        if (isset($ctx->classes['arrayaccess'])) {
            $entry->interfaces[] = 'arrayaccess';
        }
        if (isset($ctx->classes['serializable'])) {
            $entry->interfaces[] = 'serializable';
        }
        $ctx->classes['spldoublylinkedlist'] = $entry;
    }
}
