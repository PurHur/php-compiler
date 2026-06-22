<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\ObStackLimits;
use PHPCompiler\VM\OutputBuffer;

/**
 * VM output-buffer stack (php-src ext/standard/head.c; issue #5582).
 *
 * JIT embed uses {@see \PHPCompiler\JIT\Builtin\EmbedObOutput} + {@see EmbedObJitHelper}; AOT/JIT standalone
 * use {@see \PHPCompiler\JIT\Builtin\ObOutputRuntime}. Limits: {@see ObStackLimits}.
 */
final class NativeObStorage
{
    public static function maxDepth(): int
    {
        return ObStackLimits::MAX_DEPTH;
    }

    public static function bufferByteSize(): int
    {
        return ObStackLimits::BUF_SIZE;
    }

    public static function reset(): void
    {
        OutputBuffer::reset();
        VmObGzhandler::reset();
    }
}
