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
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * array_splice() for packed list arrays (subset of PHP; LLVM via ArrayBuiltinHelper #1205).
 */
final class array_splice extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 4) {
            throw new \LogicException('array_splice() requires two to four arguments in this compiler build');
        }
        $array = $frame->calledArgs[0]->resolveIndirect();
        $offset = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $array->type) {
            throw new \LogicException('array_splice() first argument must be an array in this compiler build');
        }
        if (Variable::TYPE_INTEGER !== $offset->type) {
            throw new \LogicException('array_splice() offset must be an integer in this compiler build');
        }

        $length = null;
        if ($argc >= 3) {
            $lengthArg = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $lengthArg->type) {
                throw new \LogicException('array_splice() length must be an integer in this compiler build');
            }
            $length = $lengthArg->toInt();
        }

        $replacement = [];
        if (4 === $argc) {
            $replacementArg = $frame->calledArgs[3]->resolveIndirect();
            if (Variable::TYPE_ARRAY === $replacementArg->type) {
                foreach ($replacementArg->toArray()->iterate(true) as $value) {
                    $replacement[] = $value;
                }
            } else {
                $replacement[] = $replacementArg;
            }
        }

        $removed = $array->toArray()->spliceInPlace($offset->toInt(), $length, $replacement);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->array($removed);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2 || $argc > 4) {
            throw new \LogicException('array_splice() requires two to four arguments in this compiler build');
        }
        if (JITVariable::TYPE_NATIVE_LONG !== $args[1]->type) {
            throw new \LogicException('array_splice() offset must be an integer in this compiler build');
        }
        if ($argc >= 3 && JITVariable::TYPE_NATIVE_LONG !== $args[2]->type) {
            throw new \LogicException('array_splice() length must be an integer in this compiler build');
        }

        $i64 = $context->getTypeFromString('int64');
        $i1 = $context->getTypeFromString('int1');
        $offset = JitLongArg::lower($context, $args[1], 'array_splice() offset');
        $hasLength = $i1->constInt($argc >= 3 ? 1 : 0, false);
        $length = $argc >= 3
            ? JitLongArg::lower($context, $args[2], 'array_splice() length')
            : $i64->constInt(0, false);
        $replacement = 4 === $argc ? $args[3] : null;

        return ArrayBuiltinHelper::buildSpliceArray(
            $context,
            $args[0],
            $offset,
            $hasLength,
            $length,
            $replacement,
            4 === $argc
        );
    }
}
