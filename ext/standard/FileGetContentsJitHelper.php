<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * __compiler_file_get_contents for compiled JIT/AOT modules (#15309, #29510, #29833, php-in-PHP).
 *
 * Leaf is `@file_get_contents` → NestedJIT whitelist {@see file_get_contents} →
 * {@see file_get_contents::call} → {@see JitFileGetContentsLibc} libc open/read
 * (no kernel Internal; crypt #29545 / random_bytes #29531 shape).
 * VM SSOT remains {@see VmFs::fileGetContents()}.
 * php-src: ext/standard/streamsfuncs.c — php_stream_copy_to_mem
 */
final class FileGetContentsJitHelper
{
    public static function readPathArgv(string $path): ?string
    {
        $data = @\file_get_contents($path);
        if (false === $data) {
            return null;
        }

        return $data;
    }
}
