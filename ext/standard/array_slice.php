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
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * array_slice() for packed list arrays (subset of PHP; VM only).
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
        throw new \LogicException('array_slice() is not implemented for JIT in this compiler build');
    }
}
