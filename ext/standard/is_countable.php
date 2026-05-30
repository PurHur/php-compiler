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
use PHPCompiler\JIT\Builtin\Type\Object_ as ObjectType;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Context as VmContext;
use PHPCompiler\VM\InterfaceCheck;
use PHPCompiler\VM\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * is_countable() — PHP 7.3+ array/Countable detection (ext/standard/type.c parity).
 *
 * @see https://github.com/php/php-src/blob/master/ext/standard/type.c PHP_FUNCTION(is_countable)
 */
final class is_countable extends Internal
{
    private const COUNTABLE_LC = 'countable';

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('is_countable() requires exactly one argument');
        }
        $v = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(self::isCountableVm($v, VmReflection::requireContext($frame)));
    }

    public static function isCountableVm(Variable $v, VmContext $ctx): bool
    {
        if (Variable::TYPE_ARRAY === $v->type) {
            return true;
        }
        if (Variable::TYPE_OBJECT === $v->type) {
            return InterfaceCheck::entryImplements($v->toObject()->class, self::COUNTABLE_LC, $ctx);
        }

        return false;
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        if (1 !== \count($args)) {
            throw new \LogicException('is_countable() requires exactly one argument');
        }
        if ($args[0]->type & JITVariable::IS_NATIVE_ARRAY) {
            return $context->constantFromBool(true);
        }
        switch ($args[0]->type) {
            case JITVariable::TYPE_HASHTABLE:
                return $context->constantFromBool(true);
            case JITVariable::TYPE_OBJECT:
            case JITVariable::TYPE_VALUE:
                return $context->helper->loadValue(self::jitIsCountable($context, $args[0]));
            case JITVariable::TYPE_NATIVE_LONG:
            case JITVariable::TYPE_NATIVE_DOUBLE:
            case JITVariable::TYPE_NATIVE_BOOL:
            case JITVariable::TYPE_STRING:
            case JITVariable::TYPE_NULL:
                return $context->constantFromBool(false);
            default:
                throw new \LogicException(
                    'is_countable() does not support this value type in this compiler build'
                );
        }
    }

    private static function jitIsCountable(Context $context, JITVariable $arg): JITVariable
    {
        if (JITVariable::TYPE_OBJECT === $arg->type) {
            return self::jitObjectImplementsCountable($context, $arg);
        }

        return self::jitBoxedIsCountable($context, $arg);
    }

    private static function jitBoxedIsCountable(Context $context, JITVariable $arg): JITVariable
    {
        $loaded = JitValueBox::valuePtrFromVariable($context, $arg);
        $typeField = $context->structFieldMap['__value__']['type'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($loaded, $typeField)
        );
        $i8 = $context->getTypeFromString('int8');
        $isArray = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_ARRAY, false)
        );
        $isObject = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_OBJECT, false)
        );
        $objectCheck = self::jitValueBoxObjectImplementsCountable($context, $loaded);
        $arrayBool = $context->constantFromBool(true);
        $falseBool = $context->constantFromBool(false);
        $nonObject = $context->builder->select($isArray, $arrayBool, $falseBool);

        return new JITVariable(
            $context,
            JITVariable::TYPE_NATIVE_BOOL,
            JITVariable::KIND_VALUE,
            $context->builder->select($isObject, $objectCheck, $nonObject)
        );
    }

    private static function jitObjectImplementsCountable(Context $context, JITVariable $arg): JITVariable
    {
        $obj = $context->helper->loadValue($arg);
        $objMap = $context->structFieldMap['__object__'];
        $classId = $context->builder->load(
            $context->builder->structGep($obj, $objMap['class_id'])
        );

        return self::jitClassIdImplementsCountable($context, $classId);
    }

    private static function jitValueBoxObjectImplementsCountable(Context $context, Value $valuePtr): Value
    {
        $obj = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $valuePtr
        );
        $objMap = $context->structFieldMap['__object__'];
        $classId = $context->builder->load(
            $context->builder->structGep($obj, $objMap['class_id'])
        );
        $result = self::jitClassIdImplementsCountable($context, $classId);

        return $context->helper->loadValue($result);
    }

    private static function jitClassIdImplementsCountable(Context $context, Value $classId): JITVariable
    {
        $objectType = $context->type->object;
        assert($objectType instanceof ObjectType);
        $i1 = $context->getTypeFromString('int1');
        $acc = $i1->constInt(0, false);
        foreach ($objectType->allClassNamesById() as $id => $name) {
            $classLc = strtolower(ltrim($name, '\\'));
            $ifaces = $objectType->allInterfacesForClassLc($classLc);
            $matches = \in_array(self::COUNTABLE_LC, $ifaces, true)
                || ($objectType->isInterfaceClassLc($classLc) && self::COUNTABLE_LC === $classLc);
            if (!$matches) {
                continue;
            }
            $expected = $context->constantFromInteger($id, 'int64');
            $isId = $context->builder->icmp(Builder::INT_EQ, $classId, $expected);
            $acc = $context->builder->or($acc, $isId);
        }

        return new JITVariable(
            $context,
            JITVariable::TYPE_NATIVE_BOOL,
            JITVariable::KIND_VALUE,
            $acc
        );
    }
}
