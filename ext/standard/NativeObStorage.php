<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\ObStackLimits;
use PHPCompiler\VM\OutputBuffer;

/**
 * VM output-buffer stack (php-src ext/standard/head.c; issue #5582).
 *
 * JIT embed uses {@see \PHPCompiler\JIT\Builtin\EmbedObOutput}; AOT/JIT standalone
 * use C {@see lib/AOT/runtime/phpc_ob.c} until #5314. Limits: {@see ObStackLimits}.
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
    }
}
