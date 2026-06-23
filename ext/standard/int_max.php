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
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * max() — array, variadic scalars, and two-arg numeric subset (php-src array.c).
 */
final class int_max extends Internal
{
    public function __construct()
    {
        parent::__construct('max');
    }

    public function execute(Frame $frame): void
    {
        if (2 === \count($frame->calledArgs)) {
            if (VmMinMax::tryReduceEnumCasesTwoArg($frame, false)) {
                return;
            }
            $a = $frame->calledArgs[0]->resolveIndirect();
            $b = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_INTEGER === $a->type && Variable::TYPE_INTEGER === $b->type) {
                $ai = $a->toInt();
                $bi = $b->toInt();
                if (null !== $frame->returnVar) {
                    $frame->returnVar->int($ai > $bi ? $ai : $bi);
                }

                return;
            }
            if (self::isNumeric($a) && self::isNumeric($b)) {
                if (VmMinMax::tryReduceScalarsTwoArg($frame, false)) {
                    return;
                }
            }
        }

        VmMinMax::max($frame);
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        if (2 === \count($args)) {
            if (JITVariable::TYPE_NATIVE_LONG === $args[0]->type && JITVariable::TYPE_NATIVE_LONG === $args[1]->type) {
                $l = JitLongArg::lower($context, $args[0], 'max() argument #1');
                $r = JitLongArg::lower($context, $args[1], 'max() argument #2');
                $cmp = $context->builder->icmp(Builder::INT_SGT, $l, $r);

                return $context->builder->select($cmp, $l, $r);
            }
            if (self::isJitNativeNumeric($args[0]) && self::isJitNativeNumeric($args[1])) {
                $double = $context->getTypeFromString('double');
                $l = pow::toJitDouble($context, $args[0], $double);
                $r = pow::toJitDouble($context, $args[1], $double);
                $cmp = $context->builder->fcmp(Builder::REAL_OGE, $l, $r);

                return $context->builder->select($cmp, $l, $r);
            }
        }

        return JitMinMax::invoke($context, false, ...$args);
    }

    private static function isNumeric(Variable $v): bool
    {
        if (Variable::TYPE_INTEGER === $v->type || Variable::TYPE_FLOAT === $v->type) {
            return true;
        }
        if (Variable::TYPE_STRING !== $v->type) {
            return false;
        }
        $s = $v->toString();

        return '' !== $s && \is_numeric($s);
    }

    private static function isJitNativeNumeric(JITVariable $v): bool
    {
        return JITVariable::TYPE_NATIVE_LONG === $v->type || JITVariable::TYPE_NATIVE_DOUBLE === $v->type;
    }
}
