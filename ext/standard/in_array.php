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
 * in_array() for arrays of scalar values (subset of PHP; VM only).
 */
final class in_array extends Internal
{
    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs) && 3 !== \count($frame->calledArgs)) {
            throw new \LogicException('in_array() requires two or three arguments');
        }
        $needle = $frame->calledArgs[0]->resolveIndirect();
        $haystack = $frame->calledArgs[1]->resolveIndirect();
        $strict = false;
        if (3 === \count($frame->calledArgs)) {
            $strict = $frame->calledArgs[2]->resolveIndirect()->toBool();
        }
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_ARRAY !== $haystack->type) {
            throw new \LogicException('in_array() second argument must be an array in this compiler build');
        }
        foreach ($haystack->toArray()->iterate(true) as $value) {
            $stored = $value->resolveIndirect();
            if ($strict ? $needle->identicalTo($stored) : self::looseEquals($needle, $stored)) {
                $frame->returnVar->bool(true);

                return;
            }
        }
        $frame->returnVar->bool(false);
    }

    private static function looseEquals(Variable $left, Variable $right): bool
    {
        return self::toCompareValue($left) == self::toCompareValue($right);
    }

    private static function toCompareValue(Variable $v): mixed
    {
        $v = $v->resolveIndirect();
        switch ($v->type) {
            case Variable::TYPE_NULL:
                return null;
            case Variable::TYPE_INTEGER:
                return $v->toInt();
            case Variable::TYPE_FLOAT:
                return $v->toFloat();
            case Variable::TYPE_BOOLEAN:
                return $v->toBool();
            case Variable::TYPE_STRING:
                return $v->toString();
            default:
                throw new \LogicException('in_array() only supports scalar haystack values in this compiler build');
        }
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('in_array() is not implemented for JIT in this compiler build');
    }
}
