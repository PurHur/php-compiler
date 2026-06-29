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
use PHPCompiler\JIT\Builtin\ArraySpliceRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\InternalStrictArg as JitInternalStrictArg;
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
        $offsetInt = VmMath::parseIntBuiltinArgForFrame($frame, 1, 'array_splice', 2, 'offset');

        $length = null;
        if ($argc >= 3) {
            $length = VmMath::parseNullableIntBuiltinArgForFrame($frame, 2, 'array_splice', 3, 'length');
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
        JitInternalStrictArg::requireInt($context, $args[1], 'array_splice', 'offset', 2);
        $offset = JitIntdiv::lowerIntBuiltinArg($context, $args[1], 'array_splice', 2, 'offset');
        if ($argc >= 3) {
            [$hasLength, $length] = JitIntdiv::lowerSpliceLengthArg(
                $context,
                $args[2],
                'array_splice',
                3,
                'length'
            );
        } else {
            $hasLength = $i1->constInt(0, false);
            $length = $i64->constInt(0, false);
        }
        $replacement = 4 === $argc ? $args[3] : null;

        return ArraySpliceRuntime::splice(
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
