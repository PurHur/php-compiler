<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * VM tmpfile() — pure PHP default ({@see VmTmpfilePure}, #9033, #1492).
 *
 * No libc tmpfile(3)/dup(2) FFI on the default path — shrinks native link surface for self-host/M5.
 *
 * php-src: ext/standard/streams.c — PHP_FUNCTION(tmpfile)
 * JIT/AOT: {@see JitTmpfile} / __compiler_tmpfile via StreamIoJit.
 */
final class VmTmpfileNative
{
    public static function available(): bool
    {
        return VmTmpfilePure::available();
    }

    /**
     * @return int|false VM stream handle; unlinked on fclose (Zend semantics)
     */
    public static function open(): int|false
    {
        return VmTmpfilePure::open();
    }
}
