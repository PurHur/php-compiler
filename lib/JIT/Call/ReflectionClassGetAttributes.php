<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\ReflectionNative;
use PHPCompiler\JIT\Builtin\ReflectionRuntime;
use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\AttributeSupport;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Builder;
use PHPLLVM\Value;

final class ReflectionClassGetAttributes implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        ReflectionRuntime::ensureLinked($context);
        ReflectionNative::registerDeclarations($context);
        $obj = ReflectionSetup::loadObjectFromArg($context, $args[0]);
        $sizeT = $context->getTypeFromString('size_t');
        $i8p = $context->getTypeFromString('int8*');
        [$classSafe] = ReflectionSetup::reflectionClassNameAsCstr($context, $obj);

        $count = $context->builder->call($context->lookupFunction('__compiler_attr_class_count'), $classSafe);
        $ht = HashTableHelper::alloc($context);
        $need = $context->builder->select(
            $context->builder->icmp(Builder::INT_UGT, $count, $sizeT->constInt(0, false)),
            $count,
            $sizeT->constInt(1, false)
        );
        $context->builder->call($context->lookupFunction('__hashtable__grow'), $ht, $need);

        $iSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($sizeT->constInt(0, false), $iSlot);
        $done = BasicBlockHelper::append($context, 'refl_attr_done');
        $head = BasicBlockHelper::append($context, 'refl_attr_head');
        $body = BasicBlockHelper::append($context, 'refl_attr_body');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $i = $context->builder->load($iSlot);
        $inRange = $context->builder->icmp(Builder::INT_ULT, $i, $count);
        $context->builder->branchIf($inRange, $body, $done);

        $context->builder->positionAtEnd($body);
        $namePtr = $context->builder->call(
            $context->lookupFunction('__compiler_attr_class_name_at'),
            $classSafe,
            $i
        );
        // strlen(3) via LibcExtern::ensureStrlenDecl after always-on drop (#32068).
        LibcExtern::ensureStrlenDecl($context);
        $nameLen = $context->builder->call(
            $context->lookupFunction('strlen'),
            $context->builder->pointerCast($namePtr, $i8p)
        );
        $attrClassId = $context->type->object->lookup('ReflectionAttribute');
        $attrObj = $context->type->object->allocate($attrClassId);
        ReflectionSetup::markConstructed($context, $attrObj);
        ReflectionSetup::emitSetStringPropertyFromCstr(
            $context,
            $attrObj,
            'ReflectionAttribute',
            'name',
            $context->builder->pointerCast($namePtr, $i8p),
            $nameLen
        );
        ReflectionSetup::emitSetIntegerProperty(
            $context,
            $attrObj,
            'ReflectionAttribute',
            ReflectionSupport::PROP_ATTR_TARGET,
            AttributeSupport::TARGET_CLASS
        );
        $argsHt = $context->builder->call(
            $context->lookupFunction('__compiler_attr_class_args_hashtable'),
            $classSafe,
            $i
        );
        $htPtrTy = $context->getTypeFromString('__hashtable__*');
        $voidPtr = $context->getTypeFromString('void*');
        $nullHt = $context->builder->pointerCast($voidPtr->constNull(), $htPtrTy);
        $argsIsNull = $context->builder->icmp(Builder::INT_EQ, $argsHt, $nullHt);
        $emptyArgsHt = HashTableHelper::alloc($context);
        $argsHt = $context->builder->select($argsIsNull, $emptyArgsHt, $argsHt);
        $argsVar = new Variable($context, Variable::TYPE_HASHTABLE, Variable::KIND_VALUE, $argsHt);
        $argsSlot = $context->type->object->propertySlotFor($attrObj, 'ReflectionAttribute', 'args');
        $context->type->object->propertyStore($argsSlot, $argsVar, Variable::TYPE_HASHTABLE);
        HashTableHelper::setAtIndex(
            $context,
            $ht,
            $i,
            new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $attrObj)
        );
        $context->builder->store(
            $context->builder->addNoSignedWrap($i, $sizeT->constInt(1, false)),
            $iSlot
        );
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);

        return $ht;
    }
}
