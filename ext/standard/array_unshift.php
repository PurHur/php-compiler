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
use PHPCompiler\JIT\JitReferencableCheck;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * array_unshift() prepending one or more values (subset of PHP; packed list arrays).
 */
final class array_unshift extends Internal
{
    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('array_unshift() requires at least one argument');
        }
        $array = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $array->type) {
            throw new \LogicException('array_unshift() first argument must be an array in this compiler build');
        }
        $ht = $array->toArray();
        if (\count($frame->calledArgs) < 2) {
            if (null === $frame->returnVar) {
                return;
            }
            $frame->returnVar->int($ht->getNumElements());

            return;
        }
        $values = [];
        for ($i = 1, $n = \count($frame->calledArgs); $i < $n; ++$i) {
            $copy = new Variable();
            $copy->copyFrom($frame->calledArgs[$i]->resolveIndirect());
            $values[] = $copy;
        }
        $count = $ht->unshiftPrepend(...$values);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int($count);
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 1) {
            throw new \LogicException('array_unshift() requires at least one argument');
        }
        if (!JitReferencableCheck::guardArrayMutatorByRefArg($context, 'array_unshift', $args[0])) {
            return $context->constantFromInteger(0, 'int64');
        }
        $array = $args[0];
        $values = \array_slice($args, 1);

        foreach ($values as $i => $arg) {
            if (JITVariable::TYPE_STRING === $arg->type || JITVariable::TYPE_VALUE === $arg->type) {
                $this->jitString($context, $arg, 'array_unshift() argument #'.((int) $i + 1));
            }
        }

        return ArrayBuiltinHelper::unshift($context, $array, ...$values);
    }
}
