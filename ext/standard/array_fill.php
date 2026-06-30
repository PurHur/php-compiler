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
use PHPCompiler\JIT\Builtin\ArrayFillRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\InternalStrictArg as JitInternalStrictArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\InternalStrictArg;
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
        $value = $frame->calledArgs[2]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        $startIndex = self::vmStartIndexArg($frame);
        $num = VmMath::parseIntBuiltinArgForFrame($frame, 1, 'array_fill', 2, 'count');
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
        JitInternalStrictArg::requireInt($context, $args[0], 'array_fill', 'start_index', 1);
        $startIndex = JitIntdiv::lowerIntBuiltinArg($context, $args[0], 'array_fill', 1, 'start_index');
        $count = JitIntdiv::lowerIntBuiltinArgForCaller($context, $args[1], 'array_fill', 2, 'count');
        JitArrayFill::emitRuntimeCountGuard($context, $count);

        return ArrayFillRuntime::fill($context, $startIndex, $count, $args[2]);
    }

    private static function vmStartIndexArg(Frame $frame): int
    {
        if (null !== $frame->parent && $frame->parent->block->strictTypes) {
            return InternalStrictArg::requireInt($frame, 0, 'array_fill', 'start_index')->toInt();
        }

        return VmMath::parseIntBuiltinArg(
            $frame->calledArgs[0]->resolveIndirect(),
            'array_fill',
            1,
            'start_index'
        );
    }
}
