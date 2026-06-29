<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;

/**
 * stream_get_meta_data()/stream_set_blocking() for compiled JIT/AOT modules (#13846, php-in-PHP).
 *
 * SSOT: {@see VmFs::streamGetMetaData()} / {@see VmFs::streamSetBlocking()}
 * php-src: ext/standard/streams.c
 */
final class StreamMetaJitHelper
{
    /** @return HashTable|null null when handle invalid or closed */
    public static function getMetaDataArgv(int $handle): ?HashTable
    {
        $meta = VmFs::streamGetMetaData($handle);

        return false === $meta ? null : $meta;
    }

    /** @return 0|1 ABI for __compiler_stream_set_blocking */
    public static function setBlockingArgv(int $handle, int $mode): int
    {
        return VmFs::streamSetBlocking($handle, 0 !== $mode) ? 1 : 0;
    }
}
