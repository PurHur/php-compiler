<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** stream_set_read_buffer() — VM via VmFs; JIT/AOT via __compiler_stream_set_read_buffer (issue #3755). */
final class stream_set_read_buffer_ extends Internal
{
    public function __construct()
    {
        parent::__construct('stream_set_read_buffer');
    }

    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \LogicException('stream_set_read_buffer() requires exactly two arguments in this compiler build');
        }
        $handleVar = $frame->calledArgs[0]->resolveIndirect();
        $bufferVar = $frame->calledArgs[1]->resolveIndirect();
        if (!$handleVar->isStreamResource()) {
            throw new \TypeError(
                'stream_set_read_buffer(): Argument #1 ($stream) must be of type resource, '
                . VmStreamArg::debugTypeName($handleVar) . ' given'
            );
        }
        if (Variable::TYPE_INTEGER !== $bufferVar->type) {
            throw new \TypeError('stream_set_read_buffer(): Argument #2 ($buffer) must be of type int');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $previous = VmFs::streamSetReadBuffer($handleVar->toInt(), $bufferVar->toInt());
        if (false === $previous) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->int($previous);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \LogicException('stream_set_read_buffer() requires exactly two arguments in this compiler build');
        }

        return JitStreamSetReadBuffer::invoke(
            $context,
            $context->builder->truncOrBitCast(
                JitLongArg::lower($context, $args[0], 'stream_set_read_buffer() stream'),
                $context->getTypeFromString('int64')
            ),
            $context->builder->truncOrBitCast(
                JitLongArg::lower($context, $args[1], 'stream_set_read_buffer() buffer'),
                $context->getTypeFromString('int64')
            )
        );
    }
}
