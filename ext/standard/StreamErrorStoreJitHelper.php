<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\CompilerVersion;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\VmActiveContextJitHelper;

/**
 * stream_last_errors() / stream_clear_errors() for compiled JIT/AOT modules (#21020).
 *
 * SSOT: {@see VmStreamErrorStore}, {@see StreamErrorBuiltin}.
 * php-src: ext/standard/streamsfuncs.c — PHP_FUNCTION(stream_last_errors / stream_clear_errors)
 */
final class StreamErrorStoreJitHelper
{
    public static function clear(): void
    {
        if (!CompilerVersion::supportsStreamErrorApi()) {
            return;
        }
        VmStreamErrorStore::clear();
    }

    /** JIT/embed NestedJIT — build StreamError[] via VmStreamErrorStore (#21020). */
    public static function getErrorsHt(): HashTable
    {
        if (!CompilerVersion::supportsStreamErrorApi()) {
            return new HashTable();
        }
        $ctx = VmActiveContextJitHelper::resolve();

        return VmStreamErrorStore::lastErrorsVariable($ctx)->toArray();
    }

    public static function recordOpenFailed(string $path, string $detail): void
    {
        if (!CompilerVersion::supportsStreamErrorApi()) {
            return;
        }
        VmStreamErrorStore::recordOpenFailed($path, $detail);
    }
}
