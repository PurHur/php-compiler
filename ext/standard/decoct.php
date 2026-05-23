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
 * decoct() for integer arguments (subset of PHP standard library).
 */
final class decoct extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== count($frame->calledArgs)) {
            throw new \LogicException('decoct() requires exactly one argument');
        }
        $v = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_INTEGER !== $v->type) {
            throw new \LogicException('decoct() only supports integers in this compiler build');
        }
        $frame->returnVar->string(\decoct($v->toInt()));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        if (1 !== count($args)) {
            throw new \LogicException('decoct() requires exactly one argument');
        }
        if (JITVariable::TYPE_NATIVE_LONG !== $args[0]->type) {
            throw new \LogicException('decoct() only supports integers in this compiler build');
        }

        return $this->formatToString($context, $context->helper->loadValue($args[0]), '%o');
    }

    private function formatToString(Context $context, Value $value, string $format): Value
    {
        $sizeT = $context->getTypeFromString('size_t');
        $charPtr = $context->getTypeFromString('char*');
        $i64 = $context->getTypeFromString('int64');
        $bufSize = $sizeT->constInt(64, false);
        $buf = $context->builder->call($context->lookupFunction('__mm__malloc'), $bufSize);
        $bufChar = $context->builder->pointerCast($buf, $charPtr);
        $fmt = $context->builder->pointerCast(
            $context->constantFromString($format),
            $charPtr
        );
        $written = $context->builder->call(
            $context->lookupFunction('snprintf'),
            $bufChar,
            $bufSize,
            $fmt,
            $value
        );
        $len = $context->builder->zExt($written, $i64);
        $str = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $len,
            $bufChar
        );
        $context->builder->call($context->lookupFunction('__mm__free'), $buf);

        return $str;
    }
}
