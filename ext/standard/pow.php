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
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * pow() with two integer or float arguments (subset of PHP standard library).
 */
final class pow extends Internal
{
    public function execute(Frame $frame): void
    {
        if (2 !== count($frame->calledArgs)) {
            throw new \LogicException('pow() requires exactly two arguments');
        }
        $base = $frame->calledArgs[0]->resolveIndirect();
        $exp = $frame->calledArgs[1]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->float(\pow(self::toFloat($base), self::toFloat($exp)));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        if (2 !== count($args)) {
            throw new \LogicException('pow() requires exactly two arguments');
        }
        $double = $context->getTypeFromString('double');
        $base = self::toJitDouble($context, $args[0], $double);
        $exp = self::toJitDouble($context, $args[1], $double);
        $fn = $context->lookupFunction('pow');

        return $context->builder->call($fn, $base, $exp);
    }

    private static function toFloat(Variable $v): float
    {
        if (Variable::TYPE_INTEGER === $v->type) {
            return (float) $v->toInt();
        }
        if (Variable::TYPE_FLOAT === $v->type) {
            return $v->toFloat();
        }
        throw new \LogicException('pow() only supports integers and floats in this compiler build');
    }

    public static function toJitDouble(Context $context, JITVariable $arg, $double): Value
    {
        $v = $context->helper->loadValue($arg);
        switch ($arg->type) {
            case JITVariable::TYPE_NATIVE_LONG:
                return $context->builder->siToFp($v, $double);
            case JITVariable::TYPE_NATIVE_DOUBLE:
                return $v;
            default:
                throw new \LogicException('pow() only supports integers and floats in this compiler build');
        }
    }
}
