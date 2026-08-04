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
    private const EXPECTED_ARGC = 1;

    private static function argcError(int $given): \ArgumentCountError
    {
        // Zend: "is_countable() expects exactly 1 argument, X given"
        return new \ArgumentCountError(sprintf(
            'is_countable() expects exactly %d argument, %d given',
            self::EXPECTED_ARGC,
            $given
        ));
    }

    public function execute(Frame $frame): void
    {
        if (self::EXPECTED_ARGC !== \count($frame->calledArgs)) {
            throw self::argcError(\count($frame->calledArgs));
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
        if (self::EXPECTED_ARGC !== \count($args)) {
            throw self::argcError(\count($args));
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
        // Value-box writers store JIT type tags (TYPE_HASHTABLE=135, TYPE_OBJECT=133),
        // not VM TYPE_ARRAY=6. Mask IS_REFCOUNTED like __value__readHashtable (#26977 / #27552).
        $kind = $context->builder->and($typeByte, $i8->constInt(0x7f, false));
        $isArray = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(JITVariable::TYPE_HASHTABLE & 0x7f, false)
        );
        $isObject = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(JITVariable::TYPE_OBJECT & 0x7f, false)
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
        $objPtrTy = $context->getTypeFromString('__object__*');
        $isNull = $context->builder->icmp(
            Builder::INT_EQ,
            $obj,
            $objPtrTy->constNull()
        );
        $fn = $context->builder->getInsertBlock()->getParent();
        $ok = $fn->appendBasicBlock('is_countable_vbox_obj_ok');
        $empty = $fn->appendBasicBlock('is_countable_vbox_obj_empty');
        $merge = $fn->appendBasicBlock('is_countable_vbox_obj_merge');
        $context->builder->branchIf($isNull, $empty, $ok);

        $context->builder->positionAtEnd($ok);
        $objMap = $context->structFieldMap['__object__'];
        $classId = $context->builder->load(
            $context->builder->structGep($obj, $objMap['class_id'])
        );
        $result = self::jitClassIdImplementsCountable($context, $classId);
        $loaded = $context->helper->loadValue($result);
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($empty);
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($merge);
        $phi = $context->builder->phi($context->getTypeFromString('int1'));
        $phi->addIncoming($loaded, $ok);
        $phi->addIncoming($context->constantFromBool(false), $empty);

        return $phi;
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
