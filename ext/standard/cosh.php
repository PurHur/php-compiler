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
 * cosh() for integer or float arguments (ext/standard/math.c parity).
 */
final class cosh extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== count($frame->calledArgs)) {
            throw new \LogicException('cosh() requires exactly one argument');
        }
        $v = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->float(\cosh(self::toFloat($v)));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        if (1 !== count($args)) {
            throw new \LogicException('cosh() requires exactly one argument');
        }
        $double = $context->getTypeFromString('double');
        $asFloat = self::toJitDouble($context, $args[0], $double);
        $fn = $context->lookupFunction('cosh');

        return $context->builder->call($fn, $asFloat);
    }

    private static function toJitDouble(Context $context, JITVariable $arg, $double): Value
    {
        switch ($arg->type) {
            case JITVariable::TYPE_NATIVE_LONG:
                $v = JitLongArg::lower($context, $arg, 'cosh() argument');

                return $context->builder->siToFp($v, $double);
            case JITVariable::TYPE_NATIVE_DOUBLE:
                return $context->helper->loadValue($arg);
            default:
                throw new \LogicException('cosh() only supports integers and floats in this compiler build');
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
        throw new \LogicException('cosh() only supports integers and floats in this compiler build');
    }
}
