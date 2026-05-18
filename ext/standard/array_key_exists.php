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
 * array_key_exists() for arrays with int or string keys (subset of PHP; VM only).
 */
final class array_key_exists extends Internal
{
    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \LogicException('array_key_exists() requires exactly two arguments');
        }
        $key = $frame->calledArgs[0]->resolveIndirect();
        $array = $frame->calledArgs[1]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_ARRAY !== $array->type) {
            throw new \LogicException('array_key_exists() second argument must be an array in this compiler build');
        }
        if (Variable::TYPE_INTEGER !== $key->type && Variable::TYPE_STRING !== $key->type) {
            throw new \LogicException('array_key_exists() key must be an integer or string in this compiler build');
        }
        $frame->returnVar->bool($array->toArray()->hasKey($key));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('array_key_exists() is not implemented for JIT in this compiler build');
    }
}
