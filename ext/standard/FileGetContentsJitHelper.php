<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * __compiler_file_get_contents for compiled JIT/AOT modules (#15309, php-in-PHP).
 *
 * SSOT: {@see VmFs::fileGetContents()}
 * php-src: ext/standard/streamsfuncs.c — php_stream_copy_to_mem
 */
final class FileGetContentsJitHelper
{
    public static function readPathArgv(string $path): ?string
    {
        $data = VmFs::fileGetContents($path);
        if (false === $data) {
            return null;
        }

        return $data;
    }
}
