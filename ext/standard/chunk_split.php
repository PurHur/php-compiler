<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\InternalStrictArg as JitInternalStrictArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** chunk_split() — insert a separator every N bytes (subset of PHP). */
final class chunk_split extends Internal
{
    public function __construct()
    {
        parent::__construct('chunk_split');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 3) {
            throw new \LogicException('chunk_split() requires one to three arguments in this compiler build');
        }
        $string = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'chunk_split',
            0,
            'string'
        );
        $length = 76;
        if ($argc >= 2) {
            $length = VmMath::parseIntBuiltinArgForFrame($frame, 1, 'chunk_split', 2, 'length');
        }
        $separator = "\r\n";
        if (3 === $argc) {
            $separator = VmString::coerceStringBuiltinArg(
                $frame->calledArgs[2],
                'chunk_split',
                2,
                'separator'
            );
        }
        $result = VmString::chunkSplit($string, $length, $separator);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 3) {
            throw new \LogicException('chunk_split() requires one to three arguments in this compiler build');
        }
        $workBlock = BasicBlockHelper::append($context, 'chunksplit_call_work');
        $context->builder->branch($workBlock);
        $context->builder->positionAtEnd($workBlock);
        $input = JitChunkSplit::lowerStringSubject($context, $args[0]);
        $i64 = $context->getTypeFromString('int64');
        $chunkLen = $i64->constInt(76, false);
        if ($argc >= 2) {
            JitInternalStrictArg::requireInt($context, $args[1], 'chunk_split', 'length', 2);
            $chunkLen = JitChunkSplit::lowerLengthArg($context, $args[1]);
            JitChunkSplit::emitRuntimeLengthGuard($context, $chunkLen);
        }
        if ($argc >= 3) {
            $separator = JitStringBuiltinArg::lower($context, $args[2], 'chunk_split', 2, 'separator');
        } else {
            $separator = $context->builder->load($context->constantStringFromString("\r\n"));
        }

        return JitChunkSplit::split($context, $input, $chunkLen, $separator);
    }
}
