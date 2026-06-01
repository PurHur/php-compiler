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
 * array_all() — true when every element matches a predicate (PHP 8.4; ext/standard/array.c).
 */
final class array_all extends Internal
{
    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \LogicException('array_all() requires exactly two arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $array = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $array->type) {
            throw new \LogicException('array_all() first argument must be an array in this compiler build');
        }
        $callback = $frame->calledArgs[1];
        foreach ($array->toArray()->iterateKeyed(true) as [, $value]) {
            $result = VmArrayValueCallback::invokePredicate($frame, $callback, $value);
            if (!VmArrayValueCallback::isTruthy($result)) {
                $frame->returnVar->bool(false);

                return;
            }
        }
        $frame->returnVar->bool(true);
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException(
            'array_all() with a callback is not implemented for JIT in this build'
        );
    }
}
