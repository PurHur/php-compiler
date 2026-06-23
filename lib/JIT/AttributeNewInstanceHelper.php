<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\ErrorRaise;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\ext\standard\JitClassExists;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** JIT helpers for ReflectionAttribute::newInstance() (#3206, #4598). */
final class AttributeNewInstanceHelper
{
    private static int $seq = 0;

    public static function emitResolveClassId(Context $context, Variable $nameVar): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $classIdSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $context->builder->store($i64->constInt(-1, true), $classIdSlot);

        $nameVar = JitNativeString::coerce($context, $nameVar);
        $nameStr = $context->helper->loadValue($nameVar);
        $nameData = JitClassExists::stringDataPtr($context, $nameStr);
        $strcasecmpFn = $context->lookupFunction('strcasecmp');
        $candidates = $context->type->object->allDeclaredClassLowerNames();
        $tag = 'attrni'.(string) (++self::$seq);
        $done = BasicBlockHelper::append($context, 'attr_ni_done_'.$tag);
        $n = count($candidates);
        foreach ($candidates as $idx => $lc) {
            $check = 0 === $idx
                ? $context->builder->getInsertBlock()
                : BasicBlockHelper::append($context, 'attr_ni_check_'.$tag.'_'.$idx);
            if ($idx > 0) {
                $context->builder->positionAtEnd($check);
            }
            $candidate = $context->builder->load($context->constantStringFromString($lc));
            $candidateData = JitClassExists::stringDataPtr($context, $candidate);
            $cmp = $context->builder->call($strcasecmpFn, $nameData, $candidateData);
            $match = $context->builder->icmp(Builder::INT_EQ, $cmp, $i32->constInt(0, false));
            $onMatch = BasicBlockHelper::append($context, 'attr_ni_match_'.$tag.'_'.$idx);
            $onMiss = ($idx < $n - 1)
                ? BasicBlockHelper::append($context, 'attr_ni_miss_'.$tag.'_'.$idx)
                : $done;
            $context->builder->branchIf($match, $onMatch, $onMiss);
            $context->builder->positionAtEnd($onMatch);
            $context->builder->store(
                $i64->constInt($context->type->object->lookup($lc), false),
                $classIdSlot
            );
            $context->builder->branch($done);
        }
        $context->builder->positionAtEnd($done);

        return $context->builder->load($classIdSlot);
    }

    public static function readFirstPositionalArg(Context $context, Variable $argsVar): Variable
    {
        return self::readPositionalArgAt($context, $argsVar, 0);
    }

    /** First ctor arg from ReflectionAttribute::args property (VM parity, #4598, #5816). */
    public static function emitReadCtorArgFromAttrOwner(Context $context, \PHPLLVM\Value $attrObj): Variable
    {
        $argsVar = $context->type->object->propertyFetch($attrObj, 'ReflectionAttribute', 'args');

        return self::readFirstPositionalArg($context, $argsVar);
    }

    public static function readPositionalArgAt(Context $context, Variable $argsVar, int $index): Variable
    {
        if (Variable::TYPE_HASHTABLE === $argsVar->type) {
            $argsHt = $argsVar->value;
        } else {
            $argsHt = HashTableHelper::readHashtableFromValueBox($context, $argsVar);
        }
        $sizeT = $context->getTypeFromString('size_t');
        $entryVar = HashTableHelper::readIndexedToValueBox($context, $argsHt, $sizeT->constInt($index, false));
        $entryHt = HashTableHelper::readHashtableFromValueBox($context, $entryVar);
        $valueKey = $context->builder->load($context->constantStringFromString('value'));

        return HashTableHelper::readStringKeyToValueBox($context, $entryHt, $valueKey);
    }

    /**
     * Promoted ctor params may not assign $this->prop when __construct is invoked from reflection (#3216, #4598).
     */
    public static function emitApplyConstructorPropertyArgs(
        Context $context,
        \PHPLLVM\Value $obj,
        int $classId,
        Variable $ctorArg,
    ): void {
        $propSets = $context->type->object->instancePropertySets($classId);
        if ([] === $propSets) {
            return;
        }
        $className = $context->type->object->classNameForId($classId);
        $propset = $propSets[0];
        $slot = $context->type->object->propertySlotFor($obj, $className, $propset[1]);
        $context->type->object->propertyStore($slot, $ctorArg, $propset[2]);
    }

    public static function boxObject(Context $context, Value $obj): Value
    {
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            JitValueBox::pointer($context, $slot),
            $obj
        );

        return JitValueBox::pointer($context, $slot);
    }

    public static function emitMissingClassError(Context $context): void
    {
        ErrorRaise::registerDeclarations($context);
        ErrorRaise::ensureLinked($context);
        ErrorRaise::emitRaise($context, 'Attribute class not found');
    }
}
