<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * __compiler_file_put_contents for compiled JIT/AOT modules (#15310, php-in-PHP).
 *
 * SSOT: {@see VmFs::filePutContents()}
 * php-src: ext/standard/streamsfuncs.c — php_stream_copy_to_stream_ex
 */
final class FilePutContentsJitHelper
{
    /** @return int bytes written, or -1 when the path cannot be opened */
    public static function writePathArgv(string $path, string $data, int $flags): int
    {
        $written = VmFs::filePutContents($path, $data, $flags);
        if (false === $written) {
            return -1;
        }

        return $written;
    }
}
