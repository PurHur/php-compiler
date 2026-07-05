<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** stream_set_chunk_size() — VM via VmFs; JIT/AOT via __compiler_stream_set_chunk_size (issue #3754). */
final class stream_set_chunk_size_ extends Internal
{
    public function __construct()
    {
        parent::__construct('stream_set_chunk_size');
    }

    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \LogicException('stream_set_chunk_size() requires exactly two arguments in this compiler build');
        }
        $handle = VmStreamArg::requireStreamHandle(
            $frame->calledArgs[0]->resolveIndirect(),
            'stream_set_chunk_size',
            1
        );
        $chunkSize = VmMath::parseIntBuiltinArgForFrame(
            $frame,
            1,
            'stream_set_chunk_size',
            2,
            'size'
        );
        $previous = VmFs::streamSetChunkSize($handle, $chunkSize);
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $previous) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->int($previous);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \LogicException('stream_set_chunk_size() requires exactly two arguments in this compiler build');
        }

        return JitStreamSetChunkSize::invoke(
            $context,
            $context->builder->truncOrBitCast(
                JitLongArg::lower($context, $args[0], 'stream_set_chunk_size() handle'),
                $context->getTypeFromString('int64')
            ),
            $context->builder->truncOrBitCast(
                JitLongArg::lower($context, $args[1], 'stream_set_chunk_size() chunk_size'),
                $context->getTypeFromString('int64')
            )
        );
    }
}
