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
use PHPCompiler\JIT\JitIntdiv;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * array_splice() — packed lists and associative arrays (LLVM packed path via #1205).
 */
final class array_splice extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 4) {
            throw new \LogicException('array_splice() requires two to four arguments in this compiler build');
        }
        $arrayArg = $frame->calledArgs[0];
        $arrayArg->separateArrayForWrite();
        $array = $arrayArg->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $array->type) {
            throw new \LogicException('array_splice() first argument must be an array in this compiler build');
        }
        $offsetInt = VmMath::parseIntBuiltinArg(
            $frame->calledArgs[1],
            'array_splice',
            2,
            'offset'
        );

        $length = null;
        if ($argc >= 3) {
            $length = VmMath::parseIntBuiltinArg(
                $frame->calledArgs[2],
                'array_splice',
                3,
                'length'
            );
        }

        $replacement = null;
        if (4 === $argc) {
            $replacementArg = $frame->calledArgs[3]->resolveIndirect();
            if (Variable::TYPE_NULL !== $replacementArg->type) {
                if (Variable::TYPE_ARRAY === $replacementArg->type) {
                    $replacement = $replacementArg->toArray();
                } else {
                    $replacement = new \PHPCompiler\VM\HashTable();
                    $copy = new Variable();
                    $copy->copyFrom($replacementArg);
                    $replacement->append($copy);
                }
            }
        }

        $removed = $array->toArray()->spliceInPlace($offsetInt, $length, $replacement);
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
        $i64 = $context->getTypeFromString('int64');
        $i1 = $context->getTypeFromString('int1');
        $offset = JitIntdiv::lowerIntBuiltinArg($context, $args[1], 'array_splice', 2, 'offset');
        $hasLength = $i1->constInt($argc >= 3 ? 1 : 0, false);
        $length = $argc >= 3
            ? JitIntdiv::lowerIntBuiltinArg($context, $args[2], 'array_splice', 3, 'length')
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
