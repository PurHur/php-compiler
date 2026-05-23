<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * array_chunk() for packed list arrays (preserve_keys=false subset; LLVM via ArrayBuiltinHelper).
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
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_ARRAY !== $array->type) {
            throw new \LogicException('array_chunk() first argument must be an array in this compiler build');
        }
        if (Variable::TYPE_INTEGER !== $size->type) {
            throw new \LogicException('array_chunk() size must be an integer in this compiler build');
        }
        $preserveKeys = false;
        if (3 === $argc) {
            $preserveKeys = $frame->calledArgs[2]->resolveIndirect()->toBool();
        }
        $frame->returnVar->array($array->toArray()->chunkCopy($size->toInt(), $preserveKeys));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2 || $argc > 3) {
            throw new \LogicException('array_chunk() requires two or three arguments in this compiler build');
        }
        if (3 === $argc) {
            if (JITVariable::TYPE_NATIVE_BOOL === $args[2]->type && $args[2]->isConstant && $args[2]->value) {
                throw new \LogicException('array_chunk() preserve_keys=true is not supported in this compiler build');
            }
            if (!(JITVariable::TYPE_NULL === $args[2]->type
                || ($args[2]->isNullConstant ?? false)
                || (JITVariable::TYPE_NATIVE_BOOL === $args[2]->type && $args[2]->isConstant && !$args[2]->value))) {
                throw new \LogicException(
                    'array_chunk() preserve_keys must be omitted or compile-time false in this compiler build'
                );
            }
        }
        if (JITVariable::TYPE_NATIVE_LONG !== $args[1]->type) {
            throw new \LogicException('array_chunk() size must be an integer in this compiler build');
        }
        // Nested array results need packed-list __value__ hashtable slots (#1203); VM path is complete.
        throw new \LogicException('array_chunk() is not implemented for JIT in this compiler build');
    }
}
