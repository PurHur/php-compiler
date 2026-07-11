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
use PHPCompiler\JIT\Builtin\ArrayReplaceRecursiveRuntime;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
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
        $argc = \count($frame->calledArgs);
        if ($argc < 1) {
            throw new \ArgumentCountError('array_replace_recursive() expects at least 1 argument, 0 given');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $first = VmArray::requireArrayArgNum($frame->calledArgs[0]->resolveIndirect(), 'array_replace_recursive', 1);
        if (1 === $argc) {
            $frame->returnVar->array($first->replaceRecursiveCopy());

            return;
        }
        $others = [];
        for ($i = 1, $n = $argc; $i < $n; ++$i) {
            $others[] = VmArray::requireArrayArgNum(
                $frame->calledArgs[$i]->resolveIndirect(),
                'array_replace_recursive',
                $i + 1
            );
        }
        $frame->returnVar->array($first->replaceRecursiveCopy(...$others));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 1) {
            throw new \ArgumentCountError('array_replace_recursive() expects at least 1 argument, 0 given');
        }
        TypeErrorRaise::ensureLinked($context);
        foreach ($args as $i => $arg) {
            JitArrayElem::requireArrayArgNum($context, $arg, 'array_replace_recursive', $i + 1);
        }

        return ArrayReplaceRecursiveRuntime::replaceRecursive($context, ...$args);
    }
}
