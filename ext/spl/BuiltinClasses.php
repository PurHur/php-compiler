<?php

declare(strict_types=1);

namespace PHPCompiler\ext\spl;

use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;

/**
 * Register SPL builtin classes (php-src ext/spl; issue #4769).
 */
final class BuiltinClasses
{
    public static function register(Context $ctx): void
    {
        $before = array_keys($ctx->classes);
        self::registerArrayObject($ctx);
        self::registerSplDoublyLinkedList($ctx);
        SplFileInfoBuiltin::registerClass($ctx);
        SplFileObjectBuiltin::registerClass($ctx);
        SplTempFileObjectBuiltin::registerClass($ctx);
        DirectoryIteratorBuiltin::registerClass($ctx);
        SplFixedArrayBuiltin::registerClass($ctx);
        IteratorIteratorBuiltin::registerClass($ctx);
        LimitIteratorBuiltin::registerClass($ctx);
        VmSplIterators::register($ctx);
        VmSplObserver::register($ctx);
        VmSplRegistry::registerStubs($ctx);
        foreach (array_diff(array_keys($ctx->classes), $before) as $lc) {
            $ctx->classes[$lc]->isInternal = true;
        }
    }

    private static function registerArrayObject(Context $ctx): void
    {
        ArrayObjectBuiltin::registerClass($ctx);
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
