<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * stream_copy_to_stream() — copy bytes between streams (ext/standard/streams.c, #3272).
 *
 * php-src: ext/standard/streams.c — PHP_FUNCTION(stream_copy_to_stream)
 */
final class stream_copy_to_stream extends Internal
{
    public function __construct()
    {
        parent::__construct('stream_copy_to_stream');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 4) {
            throw new \LogicException('stream_copy_to_stream() requires two to four arguments in this compiler build');
        }
        $source = VmStreamArg::requireStreamHandle(
            $frame->calledArgs[0]->resolveIndirect(),
            'stream_copy_to_stream',
            1
        );
        $dest = VmStreamArg::requireStreamHandle(
            $frame->calledArgs[1]->resolveIndirect(),
            'stream_copy_to_stream',
            2
        );
        if (null === $frame->returnVar) {
            return;
        }
        $maxlength = -1;
        if ($argc >= 3) {
            $parsed = VmMath::parseNullableIntBuiltinArgForFrame(
                $frame,
                2,
                'stream_copy_to_stream',
                3,
                'maxlength'
            );
            $maxlength = null === $parsed ? -1 : $parsed;
        }
        $offset = 0;
        if (4 === $argc) {
            $offset = VmMath::parseIntBuiltinArg(
                $frame->calledArgs[3]->resolveIndirect(),
                'stream_copy_to_stream',
                4,
                'offset'
            );
        }
        $copied = VmFs::streamCopyToStream(
            $source,
            $dest,
            $maxlength,
            $offset,
            $frame->vmContext
        );
        if (false === $copied) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->int($copied);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2 || $argc > 4) {
            throw new \LogicException('stream_copy_to_stream() requires two to four arguments in this compiler build');
        }
        $i64 = $context->getTypeFromString('int64');
        $source = $context->builder->truncOrBitCast(
            JitLongArg::lower($context, $args[0], 'stream_copy_to_stream() source'),
            $i64
        );
        $dest = $context->builder->truncOrBitCast(
            JitLongArg::lower($context, $args[1], 'stream_copy_to_stream() dest'),
            $i64
        );
        if ($argc >= 3) {
            if (JITVariable::TYPE_VALUE === $args[2]->type && $args[2]->isNullConstant) {
                $maxlength = $i64->constInt(-1, true);
            } else {
                $maxlength = JitIntdiv::lowerNullableIntBuiltinArgForCaller(
                    $context,
                    $args[2],
                    'stream_copy_to_stream',
                    3,
                    'maxlength'
                );
            }
        } else {
            $maxlength = $i64->constInt(-1, true);
        }
        if (4 === $argc) {
            $offset = $context->builder->truncOrBitCast(
                JitLongArg::lower($context, $args[3], 'stream_copy_to_stream() offset'),
                $i64
            );
        } else {
            $offset = $i64->constInt(0, false);
        }

        return JitStreamCopyToStream::invoke($context, $source, $dest, $maxlength, $offset);
    }
}
