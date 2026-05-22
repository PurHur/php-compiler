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
 * strncasecmp() for two strings and an integer length (ASCII subset; libc strncasecmp in JIT/AOT).
 */
final class strncasecmp extends Internal
{
    public function execute(Frame $frame): void
    {
        if (3 !== count($frame->calledArgs)) {
            throw new \LogicException('strncasecmp() requires exactly three arguments');
        }
        $a = $frame->calledArgs[0]->resolveIndirect();
        $b = $frame->calledArgs[1]->resolveIndirect();
        $len = $frame->calledArgs[2]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_STRING !== $a->type
            || Variable::TYPE_STRING !== $b->type
            || Variable::TYPE_INTEGER !== $len->type) {
            throw new \LogicException('strncasecmp() requires two strings and an integer length in this compiler build');
        }
        $frame->returnVar->int(VmString::strncasecmp($a->toString(), $b->toString(), $len->toInt()));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        if (3 !== count($args)) {
            throw new \LogicException('strncasecmp() requires exactly three arguments');
        }
        if (JITVariable::TYPE_STRING !== $args[0]->type
            || JITVariable::TYPE_STRING !== $args[1]->type
            || JITVariable::TYPE_NATIVE_LONG !== $args[2]->type) {
            throw new \LogicException('strncasecmp() requires two strings and an integer length in this compiler build');
        }
        $p0 = $this->stringDataPtr($context, $context->helper->loadValue($args[0]));
        $p1 = $this->stringDataPtr($context, $context->helper->loadValue($args[1]));
        $length = $context->builder->zExt(
            $context->builder->trunc(
                $context->helper->loadValue($args[2]),
                $context->getTypeFromString('int32')
            ),
            $context->getTypeFromString('size_t')
        );
        $raw = $context->builder->call($context->lookupFunction('strncasecmp'), $p0, $p1, $length);
        $i64 = $context->getTypeFromString('int64');

        return $context->builder->sExt($raw, $i64);
    }

    private function stringDataPtr(Context $context, Value $strPtr): Value
    {
        $structName = $strPtr->typeOf()->getElementType()->getName();
        $off = $context->structFieldMap[$structName]['value'];

        return $context->builder->structGep($strPtr, $off);
    }
}
