<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\ext\standard\JitGetObjectVars;
use PHPCompiler\JIT\Variable as JitVariable;
use PHPCompiler\VM\SplFixedArrayJitHelper;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * var_dump() object property source — __debugInfo / spl backing ht before get_object_vars (#19783).
 *
 * php-src: ext/standard/var.c — zend_std_get_debug_info / spl_fixedarray_object_debug_info
 */
final class VarDumpObjectDebugPropertiesLlvm
{
    private static int $seq = 0;

    /**
     * Hashtable of properties to dump for $objVar (may branch on runtime class).
     */
    public static function resolveHashtable(Context $context, JitVariable $objVar): Value
    {
        $objPtr = self::objectPtr($context, $objVar);
        $object = $context->type->object;
        $sfaClassId = $object->lookup('SplFixedArray');
        $objMap = $context->structFieldMap['__object__'];
        $runtimeClassId = $context->builder->load(
            $context->builder->structGep($objPtr, $objMap['class_id'])
        );
        $isSplFixedArray = $context->builder->icmp(
            Builder::INT_EQ,
            $runtimeClassId,
            $context->constantFromInteger($sfaClassId)
        );

        $tag = (string) ++self::$seq;
        $sfaBb = BasicBlockHelper::append($context, 'vd_dbg_sfa_'.$tag);
        $govBb = BasicBlockHelper::append($context, 'vd_dbg_gov_'.$tag);
        $doneBb = BasicBlockHelper::append($context, 'vd_dbg_done_'.$tag);
        $htTy = $context->getTypeFromString('__hashtable__*');
        $htSlot = BasicBlockHelper::entryAlloca($context, $htTy);
        $context->builder->branchIf($isSplFixedArray, $sfaBb, $govBb);

        $context->builder->positionAtEnd($sfaBb);
        $receiver = new JitVariable(
            $context,
            JitVariable::TYPE_OBJECT,
            JitVariable::KIND_VALUE,
            $objPtr
        );
        $sfaHt = SplFixedArrayJitHelper::copyPackedHashtable($context, $receiver);
        $context->builder->store($context->helper->loadValue($sfaHt), $htSlot);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($govBb);
        $context->builder->store(self::hashtableFromGetObjectVars($context, $objVar), $htSlot);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);

        return $context->builder->load($htSlot);
    }

    private static function hashtableFromGetObjectVars(Context $context, JitVariable $objVar): Value
    {
        $varsBoxed = JitGetObjectVars::invoke($context, $objVar, false);

        return $context->builder->call(
            $context->lookupFunction('__value__readHashtable'),
            JitValueBox::normalizeValuePtr($context, $varsBoxed)
        );
    }

    private static function objectPtr(Context $context, JitVariable $objVar): Value
    {
        if (JitVariable::TYPE_OBJECT === $objVar->type) {
            return JitVariable::KIND_VALUE === $objVar->kind
                ? $objVar->value
                : $context->builder->load($objVar->value);
        }

        return $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            JitValueBox::valuePtrFromVariable($context, $objVar)
        );
    }
}
