<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * __compiler_file_get_contents for compiled JIT/AOT modules (#15309, php-in-PHP).
 *
 * Kernel path: {@see phpc_file_get_contents_kernel} — libc open/read so NestedJIT
 * helpers do not recurse through AOT fopen/fread (empty/hang; #26756).
 * VM SSOT remains {@see VmFs::fileGetContents()} inside the kernel execute path.
 * php-src: ext/standard/streamsfuncs.c — php_stream_copy_to_mem
 */
final class FileGetContentsJitHelper
{
    public static function readPathArgv(string $path): ?string
    {
        return \phpc_file_get_contents_kernel($path);
    }
}
