<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * stream_set_* buffer/chunk/timeout for compiled JIT/AOT modules (#14462, php-in-PHP).
 *
 * SSOT: {@see VmFs::streamSetChunkSize()}, {@see VmFs::streamSetWriteBuffer()},
 * {@see VmFs::streamSetReadBuffer()}, {@see VmFs::streamSetTimeout()}
 * php-src: ext/standard/streams.c
 */
final class StreamBufferJitHelper
{
    public static function setChunkSizeArgv(int $handle, int $chunkSize): int
    {
        // Avoid try/catch in NestedJIT AOT helpers — try edges hang under AOT (#25924).
        if ($chunkSize <= 0) {
            return -1;
        }
        $previous = VmFs::streamSetChunkSize($handle, $chunkSize);
        if (false === $previous) {
            return -1;
        }

        return (int) $previous;
    }

    public static function setTimeoutArgv(int $handle, int $seconds, int $microseconds): int
    {
        if ($seconds < 0 || $microseconds < 0) {
            return 0;
        }

        return VmFs::streamSetTimeout($handle, $seconds, $microseconds) ? 1 : 0;
    }

    public static function setWriteBufferArgv(int $handle, int $buffer): int
    {
        $previous = VmFs::streamSetWriteBuffer($handle, $buffer);
        if (false === $previous) {
            return -1;
        }

        return (int) $previous;
    }

    public static function setReadBufferArgv(int $handle, int $buffer): int
    {
        $previous = VmFs::streamSetReadBuffer($handle, $buffer);
        if (false === $previous) {
            return -1;
        }

        return (int) $previous;
    }
}
