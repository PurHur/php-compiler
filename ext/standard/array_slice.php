<?php

declare(strict_types=1);

/**
 * This file is part of PHP-Compiler, a PHP CFG Compiler for PHP code
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * array_slice() for packed list arrays (subset of PHP; LLVM via ArrayBuiltinHelper).
 */
final class array_slice extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \LogicException('array_slice() requires two or three arguments in this compiler build');
        }
        $array = $frame->calledArgs[0]->resolveIndirect();
        $offset = $frame->calledArgs[1]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_ARRAY !== $array->type) {
            throw new \LogicException('array_slice() first argument must be an array in this compiler build');
        }
        if (Variable::TYPE_INTEGER !== $offset->type) {
            throw new \LogicException('array_slice() offset must be an integer in this compiler build');
        }
        $length = null;
        if (3 === $argc) {
            $lengthArg = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $lengthArg->type) {
                throw new \LogicException('array_slice() length must be an integer in this compiler build');
            }
            $length = $lengthArg->toInt();
        }
        $frame->returnVar->array($array->toArray()->sliceCopy($offset->toInt(), $length));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2 || $argc > 3) {
            throw new \LogicException('array_slice() requires two or three arguments in this compiler build');
        }
        if (JITVariable::TYPE_NATIVE_LONG !== $args[1]->type) {
            throw new \LogicException('array_slice() offset must be an integer in this compiler build');
        }
        if (3 === $argc && JITVariable::TYPE_NATIVE_LONG !== $args[2]->type) {
            throw new \LogicException('array_slice() length must be an integer in this compiler build');
        }

        $i64 = $context->getTypeFromString('int64');
        $i1 = $context->getTypeFromString('int1');
        $offset = self::jitSignedI64($context, $args[1]);
        $hasLength = $i1->constInt(3 === $argc ? 1 : 0, false);
        $length = 3 === $argc
            ? self::jitSignedI64($context, $args[2])
            : $i64->constInt(0, false);

        return ArrayBuiltinHelper::buildSliceArray($context, $args[0], $offset, $hasLength, $length);
    }

    private static function jitSignedI64(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_NATIVE_LONG !== $arg->type) {
            throw new \LogicException('array_slice() integer arguments must be native integers in this compiler build');
        }

        return $context->helper->loadValue($arg);
    }
}
