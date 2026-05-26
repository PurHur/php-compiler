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
 * array_merge() for packed lists and string-key maps (subset of PHP; JIT via ArrayBuiltinHelper).
 */
final class array_merge extends Internal
{
    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 2) {
            throw new \LogicException('array_merge() requires at least two arguments');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $first = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $first->type) {
            throw new \LogicException('array_merge() arguments must be arrays in this compiler build');
        }
        $others = [];
        for ($i = 1, $n = \count($frame->calledArgs); $i < $n; ++$i) {
            $arg = $frame->calledArgs[$i]->resolveIndirect();
            if (Variable::TYPE_ARRAY !== $arg->type) {
                throw new \LogicException('array_merge() arguments must be arrays in this compiler build');
            }
            $others[] = $arg->toArray();
        }
        $frame->returnVar->array(VmArray::merge($first->toArray(), ...$others));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 2) {
            throw new \LogicException('array_merge() requires at least two arguments');
        }

        foreach ($args as $i => $arg) {
            if (JITVariable::TYPE_STRING === $arg->type || JITVariable::TYPE_VALUE === $arg->type) {
                $this->jitString($context, $arg, 'array_merge() argument #'.((int) $i + 1));
            }
        }
        return ArrayBuiltinHelper::merge($context, ...$args);
    }
}
