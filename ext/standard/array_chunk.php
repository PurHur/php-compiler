<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\InternalStrictArg as JitInternalStrictArg;
use PHPCompiler\JIT\JitBoolArg;
use PHPCompiler\JIT\JitIntdiv;
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
        if (Variable::TYPE_ARRAY !== $array->type) {
            throw new \LogicException('array_chunk() first argument must be an array in this compiler build');
        }
        $chunkSize = VmMath::parseIntBuiltinArgForFrame($frame, 1, 'array_chunk', 2, 'length');
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
        JitInternalStrictArg::requireInt($context, $args[1], 'array_chunk', 'length', 2);
        $size = JitIntdiv::lowerIntBuiltinArg($context, $args[1], 'array_chunk', 2, 'length');
        JitArrayChunk::emitRuntimeLengthGuard($context, $size);
        $preserveKeys = 3 === $argc
            ? JitBoolArg::lower($context, $args[2], 'array_chunk() preserve_keys')
            : null;

        return ArrayBuiltinHelper::buildChunkArray($context, $args[0], $size, $preserveKeys);
    }
}
