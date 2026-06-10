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
use PHPLLVM\Value;

/**
 * array_fill() for integer start index, non-negative count, and a scalar value (subset of PHP).
 */
final class array_fill extends Internal
{
    public function execute(Frame $frame): void
    {
        if (3 !== \count($frame->calledArgs)) {
            throw new \LogicException('array_fill() requires exactly three arguments');
        }
        $start = $frame->calledArgs[0]->resolveIndirect();
        $count = $frame->calledArgs[1]->resolveIndirect();
        $value = $frame->calledArgs[2]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        $startIndex = VmMath::parseIntBuiltinArg($start, 'array_fill', 1, 'start_index');
        $num = VmMath::parseIntBuiltinArg($count, 'array_fill', 2, 'count');
        if ($num < 0) {
            throw new \ValueError('array_fill(): Argument #2 ($count) must be greater than or equal to 0');
        }
        $ht = new HashTable();
        for ($i = 0; $i < $num; ++$i) {
            $stored = new Variable();
            $stored->copyFrom($value);
            $ht->addIndex($startIndex + $i, $stored);
        }
        $frame->returnVar->array($ht);
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        if (3 !== \count($args)) {
            throw new \LogicException('array_fill() requires exactly three arguments');
        }
        $startIndex = JitIntdiv::lowerIntBuiltinArg($context, $args[0], 'array_fill', 1, 'start_index');
        $count = JitIntdiv::lowerIntBuiltinArg($context, $args[1], 'array_fill', 2, 'count');
        JitArrayFill::emitRuntimeCountGuard($context, $count);
        $sizeT = $context->getTypeFromString('size_t');
        $countSized = $context->builder->truncOrBitCast($count, $sizeT);
        $startSized = $context->builder->truncOrBitCast($startIndex, $sizeT);

        return HashTableHelper::buildArrayFill($context, $startSized, $countSized, $args[2]);
    }
}
