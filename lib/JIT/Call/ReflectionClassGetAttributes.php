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
        $objArg = $context->builder->pointerCast($obj, $i8p);

        $outLen = BasicBlockHelper::entryAlloca($context, $sizeT);
        $classCstr = $context->builder->call($context->lookupFunction('phpc_reflect_get_class_name'), $objArg, $outLen);
        $isNull = $context->builder->icmp(Builder::INT_EQ, $classCstr, $classCstr->typeOf()->constNull());
        $empty = $context->builder->pointerCast($context->constantFromString(''), $i8p);
        $classSafe = $context->builder->select($isNull, $empty, $classCstr);

        $count = $context->builder->call($context->lookupFunction('phpc_attr_class_count'), $classSafe);
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
            $context->lookupFunction('phpc_attr_class_name_at'),
            $classSafe,
            $i
        );
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
