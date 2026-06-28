<?php

declare(strict_types=1);

namespace PHPCompiler\ext\spl;

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
        SplDoublyLinkedListBuiltin::registerClass($ctx);
        SplFileInfoBuiltin::registerClass($ctx);
        SplFileObjectBuiltin::registerClass($ctx);
        SplTempFileObjectBuiltin::registerClass($ctx);
        DirectoryIteratorBuiltin::registerClass($ctx);
        FilesystemIteratorBuiltin::registerClass($ctx);
        GlobIteratorBuiltin::registerClass($ctx);
        SplFixedArrayBuiltin::registerClass($ctx);
        SplObjectStorageBuiltin::registerClass($ctx);
        IteratorIteratorBuiltin::registerClass($ctx);
        LimitIteratorBuiltin::registerClass($ctx);
        CachingIteratorBuiltin::registerClass($ctx);
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

}
