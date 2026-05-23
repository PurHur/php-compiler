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
 * array_unshift() for packed list arrays (subset of PHP).
 */
final class array_unshift extends Internal
{
    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 2) {
            throw new \LogicException('array_unshift() requires at least two arguments');
        }
        $array = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $array->type) {
            throw new \LogicException('array_unshift() first argument must be an array in this compiler build');
        }
        $ht = $array->toArray();
        for ($i = \count($frame->calledArgs) - 1; $i >= 1; --$i) {
            $value = $frame->calledArgs[$i]->resolveIndirect();
            $copy = new Variable();
            $copy->copyFrom($value);
            $ht->unshiftPrepend($copy);
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int($ht->getNumElements());
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 2) {
            throw new \LogicException('array_unshift() requires at least two arguments');
        }
        $array = $args[0];
        $values = \array_slice($args, 1);

        foreach ($args as $i => $arg) {
            if (JITVariable::TYPE_STRING === $arg->type || JITVariable::TYPE_VALUE === $arg->type) {
                $this->jitString($context, $arg, 'array_unshift() argument #'.((int) $i + 1));
            }
        }

        return ArrayBuiltinHelper::unshift($context, $array, ...$values);
    }
}
