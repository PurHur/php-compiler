<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * __compiler_file_put_contents for compiled JIT/AOT modules (#15310, #19966, #30127, php-in-PHP).
 *
 * Leaf is `@file_put_contents` → NestedJIT whitelist {@see file_put_contents} →
 * {@see file_put_contents::call} → {@see JitFilePutContentsLibc} libc fopen/fwrite
 * (no kernel Internal; file_get_contents #29833 / readfile #29915 shape).
 * VM SSOT remains {@see VmFs::filePutContents()}.
 * php-src: ext/standard/streamsfuncs.c — php_stream_copy_to_stream_ex
 */
final class FilePutContentsJitHelper
{
    /** @return int bytes written, or -1 when the path cannot be opened */
    public static function writePathArgv(string $path, string $data, int $flags): int
    {
        $written = @\file_put_contents($path, $data, $flags);
        if (false === $written) {
            return -1;
        }

        return (int) $written;
    }
}
