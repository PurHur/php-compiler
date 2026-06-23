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
 * array_merge_recursive() — deep merge with scalar→array promotion (ext/standard/array.c parity; #3297).
 *
 * php-src: ext/standard/array.c — PHP_FUNCTION(array_merge_recursive)
 */
final class array_merge_recursive extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1) {
            throw new \ArgumentCountError('array_merge_recursive() expects at least 1 argument, 0 given');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $first = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $first->type) {
            throw new \LogicException('array_merge_recursive() first argument must be an array in this compiler build');
        }
        if (1 === $argc) {
            $frame->returnVar->array($first->toArray()->duplicate());

            return;
        }
        $others = [];
        for ($i = 1, $n = $argc; $i < $n; ++$i) {
            $arg = $frame->calledArgs[$i]->resolveIndirect();
            if (Variable::TYPE_ARRAY !== $arg->type) {
                throw new \LogicException('array_merge_recursive() arguments must be arrays in this compiler build');
            }
            $others[] = $arg->toArray()->duplicate();
        }
        $frame->returnVar->array($first->toArray()->duplicate()->mergeRecursiveCopy(...$others));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 1) {
            throw new \ArgumentCountError('array_merge_recursive() expects at least 1 argument, 0 given');
        }

        foreach ($args as $i => $arg) {
            if (JITVariable::TYPE_STRING === $arg->type || JITVariable::TYPE_VALUE === $arg->type) {
                $this->jitString($context, $arg, 'array_merge_recursive() argument #'.((int) $i + 1));
            }
        }

        return ArrayBuiltinHelper::mergeRecursive($context, ...$args);
    }
}
