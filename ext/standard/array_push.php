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
 * array_push() appending one or more values (subset of PHP; VM only).
 */
final class array_push extends Internal
{
    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 2) {
            throw new \LogicException('array_push() requires at least two arguments');
        }
        $array = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $array->type) {
            throw new \LogicException('array_push() first argument must be an array in this compiler build');
        }
        $ht = $array->toArray();
        for ($i = 1, $n = \count($frame->calledArgs); $i < $n; ++$i) {
            $value = $frame->calledArgs[$i]->resolveIndirect();
            $copy = new Variable();
            $copy->copyFrom($value);
            $ht->append($copy);
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
            throw new \LogicException('array_push() requires at least two arguments');
        }
        $array = $args[0];
        $values = \array_slice($args, 1);

        foreach ($values as $i => $arg) {
            if (JITVariable::TYPE_STRING === $arg->type || JITVariable::TYPE_VALUE === $arg->type) {
                $this->jitString($context, $arg, 'array_push() argument #'.((int) $i + 1));
            }
        }
        return ArrayBuiltinHelper::push($context, $array, ...$values);
    }
}
