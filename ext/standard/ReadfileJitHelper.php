<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * __compiler_readfile for compiled JIT/AOT modules (#9188, #19966, #29915, php-in-PHP).
 *
 * Leaf is `@readfile` → NestedJIT whitelist {@see readfile} →
 * {@see readfile::call} → {@see JitReadfileLibc} libc open/read/write
 * (no kernel Internal; file_get_contents #29833 / crypt #29545 shape).
 * VM SSOT remains {@see VmFs::readfile()}.
 * php-src: ext/standard/streamsfuncs.c — php_stream_passthru
 */
final class ReadfileJitHelper
{
    /** @return int bytes written to stdout, or -1 when the path cannot be opened */
    public static function readfile(string $path): int
    {
        $n = @\readfile($path);
        if (false === $n) {
            return -1;
        }

        return (int) $n;
    }
}
