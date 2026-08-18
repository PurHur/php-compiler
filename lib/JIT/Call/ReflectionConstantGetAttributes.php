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

/** ReflectionConstant::getAttributes() — JIT (#4136). */
final class ReflectionConstantGetAttributes implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        ReflectionRuntime::ensureLinked($context);
        ReflectionNative::registerDeclarations($context);
        $obj = ReflectionSetup::loadObjectFromArg($context, $args[0]);
        [$classSafe, $classLen] = ReflectionSetup::stringPropertyAsCstr($context, $obj, 'ReflectionConstant', 'name');
        [$constSafe, $constLen] = ReflectionSetup::stringPropertyAsCstr($context, $obj, 'ReflectionConstant', 'constant');
        $sizeT = $context->getTypeFromString('size_t');
        $i8p = $context->getTypeFromString('int8*');
        $count = $context->builder->call(
            $context->lookupFunction('__compiler_attr_method_count'),
            $classSafe,
            $constSafe
        );
        $ht = HashTableHelper::alloc($context);
        $need = $context->builder->select(
            $context->builder->icmp(Builder::INT_UGT, $count, $sizeT->constInt(0, false)),
            $count,
            $sizeT->constInt(1, false)
        );
        $context->builder->call($context->lookupFunction('__hashtable__grow'), $ht, $need);

        $iSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($sizeT->constInt(0, false), $iSlot);
        $done = BasicBlockHelper::append($context, 'refl_c_attr_done');
        $head = BasicBlockHelper::append($context, 'refl_c_attr_head');
        $body = BasicBlockHelper::append($context, 'refl_c_attr_body');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $i = $context->builder->load($iSlot);
        $inRange = $context->builder->icmp(Builder::INT_ULT, $i, $count);
        $context->builder->branchIf($inRange, $body, $done);

        $context->builder->positionAtEnd($body);
        $namePtr = $context->builder->call(
            $context->lookupFunction('__compiler_attr_method_name_at'),
            $classSafe,
            $constSafe,
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
            AttributeSupport::TARGET_CLASS_CONSTANT
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
