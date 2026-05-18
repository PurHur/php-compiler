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
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * strval() for scalar values supported by this compiler (subset of PHP).
 */
final class strval extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== count($frame->calledArgs)) {
            throw new \LogicException('strval() requires exactly one argument');
        }
        $v = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_NULL === $v->type) {
            $frame->returnVar->string('');

            return;
        }
        $frame->returnVar->string($v->toString());
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        if (1 !== count($args)) {
            throw new \LogicException('strval() requires exactly one argument');
        }
        switch ($args[0]->type) {
            case JITVariable::TYPE_STRING:
                return $context->helper->loadValue($args[0]);
            case JITVariable::TYPE_NULL:
                return $context->builder->load($context->constantStringFromString(''));
            case JITVariable::TYPE_NATIVE_BOOL:
                return $this->boolToString($context, $context->helper->loadValue($args[0]));
            case JITVariable::TYPE_NATIVE_LONG:
                return $this->formatToString($context, $context->helper->loadValue($args[0]), '%lld');
            case JITVariable::TYPE_NATIVE_DOUBLE:
                return $this->formatToString($context, $context->helper->loadValue($args[0]), '%G');
            default:
                throw new \LogicException('strval() does not support this value type in this compiler build');
        }
    }

    private function boolToString(Context $context, Value $bool): Value
    {
        $trueBlock = BasicBlockHelper::append($context, 'strval_true');
        $falseBlock = BasicBlockHelper::append($context, 'strval_false');
        $endBlock = BasicBlockHelper::append($context, 'strval_bool_end');
        $context->builder->branchIf($bool, $trueBlock, $falseBlock);
        $context->builder->positionAtEnd($trueBlock);
        $trueStr = $context->builder->load($context->constantStringFromString('1'));
        $context->builder->branch($endBlock);
        $context->builder->positionAtEnd($falseBlock);
        $falseStr = $context->builder->load($context->constantStringFromString(''));
        $context->builder->branch($endBlock);
        $context->builder->positionAtEnd($endBlock);
        $phi = $context->builder->phi($trueStr->typeOf());
        $phi->addIncoming($trueStr, $trueBlock);
        $phi->addIncoming($falseStr, $falseBlock);

        return $phi;
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
