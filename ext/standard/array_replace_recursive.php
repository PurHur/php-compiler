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
 * array_replace_recursive() — nested key merge (ext/standard parity; issue #3127).
 *
 * php-src: ext/standard/array.c — PHP_FUNCTION(array_replace_recursive)
 */
final class array_replace_recursive extends Internal
{
    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 2) {
            throw new \LogicException('array_replace_recursive() requires at least two arguments');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $first = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $first->type) {
            throw new \LogicException('array_replace_recursive() first argument must be an array in this compiler build');
        }
        $others = [];
        for ($i = 1, $n = \count($frame->calledArgs); $i < $n; ++$i) {
            $arg = $frame->calledArgs[$i]->resolveIndirect();
            if (Variable::TYPE_ARRAY !== $arg->type) {
                throw new \LogicException('array_replace_recursive() arguments must be arrays in this compiler build');
            }
            $others[] = $arg->toArray();
        }
        $frame->returnVar->array($first->toArray()->replaceRecursiveCopy(...$others));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('array_replace_recursive() is not implemented for JIT in this compiler build');
    }
}
