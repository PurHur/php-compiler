<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\ArrayChunkRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\InternalStrictArg as JitInternalStrictArg;
use PHPCompiler\JIT\JitBoolArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * array_chunk() — packed lists and preserve_keys=true (VM + JIT/AOT).
 */
final class array_chunk extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2) {
            throw new \ArgumentCountError(\sprintf(
                'array_chunk() expects at least 2 arguments, %d given',
                $argc
            ));
        }
        if ($argc > 3) {
            throw new \ArgumentCountError(\sprintf(
                'array_chunk() expects at most 3 arguments, %d given',
                $argc
            ));
        }
        $array = VmArray::requireArrayParam($frame->calledArgs[0], 'array_chunk', 1, 'array');
        $chunkSize = VmMath::parseIntBuiltinArgForFrame($frame, 1, 'array_chunk', 2, 'length');
        if ($chunkSize <= 0) {
            throw new \ValueError('array_chunk(): Argument #2 ($length) must be greater than 0');
        }
        $preserveKeys = false;
        if (3 === $argc) {
            $preserveKeys = VmMath::parseBoolBuiltinArgStrict(
                $frame->calledArgs[2],
                'array_chunk',
                3,
                'preserve_keys'
            );
        }
        $result = $array->chunkCopy($chunkSize, $preserveKeys);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->array($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2) {
            throw new \ArgumentCountError(\sprintf(
                'array_chunk() expects at least 2 arguments, %d given',
                $argc
            ));
        }
        if ($argc > 3) {
            throw new \ArgumentCountError(\sprintf(
                'array_chunk() expects at most 3 arguments, %d given',
                $argc
            ));
        }
        JitArrayElem::requireArrayParam($context, $args[0], 'array_chunk', 1, 'array');
        JitInternalStrictArg::requireInt($context, $args[1], 'array_chunk', 'length', 2);
        $size = JitIntdiv::lowerIntBuiltinArg($context, $args[1], 'array_chunk', 2, 'length');
        JitArrayChunk::emitRuntimeLengthGuard($context, $size);
        $preserveKeys = 3 === $argc
            ? JitBoolArg::lower($context, $args[2], 'array_chunk() preserve_keys')
            : null;

        return ArrayChunkRuntime::chunk($context, $args[0], $size, $preserveKeys);
    }
}
