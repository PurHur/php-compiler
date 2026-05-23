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
use PHPLLVM\Value;

/**
 * atan2() for two integer or float arguments (subset of PHP standard library).
 */
final class atan2 extends Internal
{
    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \LogicException('atan2() requires exactly two arguments');
        }
        $y = $frame->calledArgs[0]->resolveIndirect();
        $x = $frame->calledArgs[1]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->float(\atan2(self::toFloat($y), self::toFloat($x)));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        if (2 !== \count($args)) {
            throw new \LogicException('atan2() requires exactly two arguments');
        }
        $double = $context->getTypeFromString('double');
        $y = self::toJitDouble($context, $args[0], $double);
        $x = self::toJitDouble($context, $args[1], $double);
        $fn = $context->lookupFunction('atan2');

        return $context->builder->call($fn, $y, $x);
    }

    private static function toJitDouble(Context $context, JITVariable $arg, $double): Value
    {
        switch ($arg->type) {
            case JITVariable::TYPE_NATIVE_LONG:
                $v = JitLongArg::lower($context, $arg, 'atan2() argument');

                return $context->builder->siToFp($v, $double);
            case JITVariable::TYPE_NATIVE_DOUBLE:
                return $context->helper->loadValue($arg);
            default:
                throw new \LogicException('atan2() only supports integers and floats in this compiler build');
        }
    }

    private static function toFloat(Variable $v): float
    {
        if (Variable::TYPE_INTEGER === $v->type) {
            return (float) $v->toInt();
        }
        if (Variable::TYPE_FLOAT === $v->type) {
            return $v->toFloat();
        }
        throw new \LogicException('atan2() only supports integers and floats in this compiler build');
    }
}
