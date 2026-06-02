<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitBoolArg;
use PHPCompiler\JIT\JitLongArg;
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
        if ($argc < 2 || $argc > 3) {
            throw new \LogicException('array_chunk() requires two or three arguments in this compiler build');
        }
        $array = $frame->calledArgs[0]->resolveIndirect();
        $size = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $array->type) {
            throw new \LogicException('array_chunk() first argument must be an array in this compiler build');
        }
        if (Variable::TYPE_INTEGER !== $size->type) {
            throw new \LogicException('array_chunk() size must be an integer in this compiler build');
        }
        $chunkSize = $size->toInt();
        if ($chunkSize <= 0) {
            throw new \ValueError('array_chunk(): Argument #2 ($length) must be greater than 0');
        }
        $preserveKeys = false;
        if (3 === $argc) {
            $preserveKeys = $frame->calledArgs[2]->resolveIndirect()->toBool();
        }
        $result = $array->toArray()->chunkCopy($chunkSize, $preserveKeys);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->array($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2 || $argc > 3) {
            throw new \LogicException('array_chunk() requires two or three arguments in this compiler build');
        }
        if (JITVariable::TYPE_NATIVE_LONG !== $args[1]->type) {
            throw new \LogicException('array_chunk() size must be an integer in this compiler build');
        }

        $size = JitLongArg::lower($context, $args[1], 'array_chunk() size');
        JitArrayChunk::emitRuntimeLengthGuard($context, $size);
        $preserveKeys = 3 === $argc
            ? JitBoolArg::lower($context, $args[2], 'array_chunk() preserve_keys')
            : null;

        return ArrayBuiltinHelper::buildChunkArray($context, $args[0], $size, $preserveKeys);
    }
}
