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
use PHPCompiler\JIT\JitBoolArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * array_slice() — packed lists and preserve_keys=true (ext/standard/array.c; #4227).
 */
final class array_slice extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 4) {
            throw new \LogicException('array_slice() requires two to four arguments in this compiler build');
        }
        $array = $frame->calledArgs[0]->resolveIndirect();
        $offset = $frame->calledArgs[1]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_ARRAY !== $array->type) {
            throw new \LogicException('array_slice() first argument must be an array in this compiler build');
        }
        $offsetInt = VmMath::parseIntBuiltinArgForFrame($frame, 1, 'array_slice', 2, 'offset');
        $length = null;
        if ($argc >= 3) {
            $lengthArg = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_NULL !== $lengthArg->type) {
                $length = VmMath::parseIntBuiltinArgForFrame($frame, 2, 'array_slice', 3, 'length');
            }
        }
        $preserveKeys = false;
        if (4 === $argc) {
            $preserveKeys = $frame->calledArgs[3]->resolveIndirect()->toBool();
        }
        $frame->returnVar->array($array->toArray()->sliceCopy($offsetInt, $length, $preserveKeys));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2 || $argc > 4) {
            throw new \LogicException('array_slice() requires two to four arguments in this compiler build');
        }
        $hasExplicitLength = false;
        if ($argc >= 3) {
            if (JITVariable::TYPE_NULL === $args[2]->type || $args[2]->isNullConstant) {
                $hasExplicitLength = false;
            } else {
                $hasExplicitLength = true;
            }
        }

        $offset = JitIntdiv::lowerIntBuiltinArg($context, $args[1], 'array_slice', 2, 'offset');
        $hasLength = $context->getTypeFromString('int1')->constInt($hasExplicitLength ? 1 : 0, false);
        $length = $hasExplicitLength
            ? JitIntdiv::lowerIntBuiltinArg($context, $args[2], 'array_slice', 3, 'length')
            : $context->getTypeFromString('int64')->constInt(0, false);
        $preserveKeys = 4 === $argc
            ? JitBoolArg::lower($context, $args[3], 'array_slice() preserve_keys')
            : null;

        return ArrayBuiltinHelper::buildSliceArray($context, $args[0], $offset, $hasLength, $length, $preserveKeys);
    }
}
