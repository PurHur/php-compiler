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
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * range() for integer start, end, and optional integer step (subset of PHP).
 */
final class range extends Internal
{
    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 2 || \count($frame->calledArgs) > 3) {
            throw new \LogicException('range() requires two or three arguments');
        }
        $startVar = $frame->calledArgs[0]->resolveIndirect();
        $endVar = $frame->calledArgs[1]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_INTEGER !== $startVar->type || Variable::TYPE_INTEGER !== $endVar->type) {
            throw new \LogicException('range() start and end must be integers in this compiler build');
        }
        $start = $startVar->toInt();
        $end = $endVar->toInt();
        $step = 1;
        if (3 === \count($frame->calledArgs)) {
            $stepVar = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $stepVar->type) {
                throw new \LogicException('range() step must be an integer in this compiler build');
            }
            $step = $stepVar->toInt();
        } elseif ($start > $end) {
            $step = -1;
        }
        if (0 === $step) {
            throw new \LogicException('range() step must not be zero');
        }
        $ht = new HashTable();
        $index = 0;
        if ($step > 0) {
            for ($i = $start; $i <= $end; $i += $step) {
                $stored = new Variable();
                $stored->int($i);
                $ht->addIndex($index, $stored);
                ++$index;
            }
        } else {
            for ($i = $start; $i >= $end; $i += $step) {
                $stored = new Variable();
                $stored->int($i);
                $ht->addIndex($index, $stored);
                ++$index;
            }
        }
        $frame->returnVar->array($ht);
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        if (\count($args) < 2 || \count($args) > 3) {
            throw new \LogicException('range() requires two or three arguments');
        }
        if (JITVariable::TYPE_NATIVE_LONG !== $args[0]->type
            || JITVariable::TYPE_NATIVE_LONG !== $args[1]->type) {
            throw new \LogicException('range() start and end must be integers in this compiler build');
        }
        $i64 = $context->getTypeFromString('int64');
        $start = $context->helper->loadValue($args[0]);
        $end = $context->helper->loadValue($args[1]);
        if (3 === \count($args)) {
            if (JITVariable::TYPE_NATIVE_LONG !== $args[2]->type) {
                throw new \LogicException('range() step must be an integer in this compiler build');
            }
            $step = $context->helper->loadValue($args[2]);
        } else {
            $cmp = $context->builder->icmp(Builder::INT_SGT, $start, $end);
            $one = $i64->constInt(1, false);
            $negOne = $i64->constInt(-1, false);
            $step = $context->builder->select($cmp, $negOne, $one);
        }
        return HashTableHelper::buildIntegerRange($context, $start, $end, $step);
    }
}
