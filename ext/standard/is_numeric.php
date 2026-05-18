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
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * is_numeric() for integers, floats, and numeric strings (subset of PHP).
 */
final class is_numeric extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== count($frame->calledArgs)) {
            throw new \LogicException('is_numeric() requires exactly one argument');
        }
        $v = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(self::isNumeric($v));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        if (1 !== count($args)) {
            throw new \LogicException('is_numeric() requires exactly one argument');
        }
        switch ($args[0]->type) {
            case JITVariable::TYPE_NATIVE_LONG:
            case JITVariable::TYPE_NATIVE_DOUBLE:
                return $context->constantFromBool(true);
            case JITVariable::TYPE_NATIVE_BOOL:
            case JITVariable::TYPE_NULL:
                return $context->constantFromBool(false);
            case JITVariable::TYPE_STRING:
                return $this->stringIsNumeric($context, $context->helper->loadValue($args[0]));
            default:
                throw new \LogicException('is_numeric() does not support this value type in this compiler build');
        }
    }

    public static function isNumeric(Variable $v): bool
    {
        switch ($v->type) {
            case Variable::TYPE_INTEGER:
            case Variable::TYPE_FLOAT:
                return true;
            case Variable::TYPE_STRING:
                $s = $v->toString();

                return '' !== $s && \is_numeric($s);
            case Variable::TYPE_BOOLEAN:
            case Variable::TYPE_NULL:
                return false;
            default:
                throw new \LogicException('is_numeric() does not support this value type in this compiler build');
        }
    }

    private function stringIsNumeric(Context $context, Value $strPtr): Value
    {
        $structName = $strPtr->typeOf()->getElementType()->getName();
        $map = $context->structFieldMap[$structName];
        $len = $context->builder->load(
            $context->builder->structGep($strPtr, $map['length'])
        );
        $zero = $len->typeOf()->constInt(0, false);
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $len, $zero);

        $charPtr = $context->builder->structGep($strPtr, $map['value']);
        $endPtrSlot = $context->builder->alloca(
            $context->getTypeFromString('int8*'),
            1,
            'is_numeric_end'
        );
        $nullEnd = $context->getTypeFromString('int8*')->constNull();
        $context->builder->store($nullEnd, $endPtrSlot);
        $context->builder->call($context->lookupFunction('strtod'), $charPtr, $endPtrSlot);
        $endPtr = $context->builder->load($endPtrSlot);
        $notConsumed = $context->builder->icmp(Builder::INT_EQ, $endPtr, $charPtr);
        $i64 = $context->getTypeFromString('int64');
        $endOffset = $context->builder->sub(
            $context->builder->ptrToInt($endPtr, $i64),
            $context->builder->ptrToInt($charPtr, $i64)
        );
        $consumedAll = $context->builder->icmp(Builder::INT_EQ, $endOffset, $len);
        $numeric = $context->builder->and(
            $context->builder->not($notConsumed),
            $consumedAll
        );

        return $context->builder->select($isEmpty, $context->constantFromBool(false), $numeric);
    }

}
