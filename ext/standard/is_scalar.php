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
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * is_scalar() for types supported by this compiler (subset of PHP).
 */
final class is_scalar extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== count($frame->calledArgs)) {
            throw new \LogicException('is_scalar() requires exactly one argument');
        }
        $v = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(self::isScalar($v));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        if (1 !== count($args)) {
            throw new \LogicException('is_scalar() requires exactly one argument');
        }
        if (JITVariable::TYPE_STRING === $args[0]->type) {
            $this->jitString($context, $args[0], 'is_scalar() argument #1');
        }
        if ($args[0]->type & JITVariable::IS_NATIVE_ARRAY) {
            return $context->constantFromBool(false);
        }
        switch ($args[0]->type) {
            case JITVariable::TYPE_NATIVE_LONG:
            case JITVariable::TYPE_NATIVE_DOUBLE:
            case JITVariable::TYPE_NATIVE_BOOL:
            case JITVariable::TYPE_STRING:
                return $context->constantFromBool(true);
            case JITVariable::TYPE_NULL:
            case JITVariable::TYPE_HASHTABLE:
            case JITVariable::TYPE_OBJECT:
                return $context->constantFromBool(false);
            case JITVariable::TYPE_VALUE:
                return $this->jitBoxedIsScalar($context, $args[0]);
            default:
                return $context->constantFromBool(false);
        }
    }

    public static function isScalar(Variable $v): bool
    {
        switch ($v->type) {
            case Variable::TYPE_INTEGER:
            case Variable::TYPE_FLOAT:
            case Variable::TYPE_BOOLEAN:
            case Variable::TYPE_STRING:
                return true;
            case Variable::TYPE_NULL:
            case Variable::TYPE_OBJECT:
            case Variable::TYPE_ENUM_CASE:
            case Variable::TYPE_ARRAY:
                return false;
            default:
                if ($v->isStreamResource() || $v->isDirResource()) {
                    return false;
                }
                throw new \LogicException('is_scalar() does not support this value type in this compiler build');
        }
    }

    private function jitBoxedIsScalar(Context $context, JITVariable $arg): Value
    {
        // NestedJIT helpers (e.g. StreamContextJitHelper::setSingleOption) pass TYPE_VALUE
        // slots — loadValue() yields a non-pointer and structGep SIGSEGVs (#27573 / peer is_numeric).
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $typeField = $context->structFieldMap['__value__']['type'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $typeField)
        );
        $i8 = $context->getTypeFromString('int8');
        $isNull = $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(JITVariable::TYPE_NULL, false));
        $isLong = $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(JITVariable::TYPE_NATIVE_LONG, false));
        $isDouble = $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(JITVariable::TYPE_NATIVE_DOUBLE, false));
        $isBool = $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(JITVariable::TYPE_NATIVE_BOOL, false));
        $isString = $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(JITVariable::TYPE_STRING, false));
        $isEnumCase = $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(Variable::TYPE_ENUM_CASE, false));
        $isObject = $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(JITVariable::TYPE_OBJECT, false));
        $scalar = $context->builder->or($isLong, $isDouble);
        $scalar = $context->builder->or($scalar, $isBool);
        $scalar = $context->builder->or($scalar, $isString);
        $nonScalar = $context->builder->or($isNull, $isEnumCase);
        $nonScalar = $context->builder->or($nonScalar, $isObject);

        return $context->builder->select($nonScalar, $context->constantFromBool(false), $scalar);
    }
}
