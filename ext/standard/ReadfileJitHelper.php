<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Lowered into JIT/AOT modules for __compiler_readfile (#9188, php-in-PHP).
 *
 * SSOT: {@see VmFs::readfile()} / {@see readfile} VM builtin.
 * php-src: ext/standard/streamsfuncs.c — php_stream_passthru
 */
final class ReadfileJitHelper
{
    /** @return int bytes written to stdout, or -1 when the path cannot be opened */
    public static function readfile(string $path): int
    {
        $result = VmFs::readfile($path);
        if (false === $result) {
            return -1;
        }

        return $result;
    }
}
