<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * JIT/AOT link for ReflectionProperty::isFinal() (#23845, #27315).
 *
 * Final flags live on Object_::finalPropertyNames (markPropertyFinal during DECLARE_PROPERTY),
 * not on thin-standalone VM ClassEntry — emit a strcmp table instead of NestedJIT+VM probe.
 */
final class ReflectionPropertyIsFinalRuntime
{
    private const ABI = '__phpc_refl_property_is_final';

    public static function invoke(Context $context, Value $classStr, Value $propStr): Value
    {
        self::ensureLinked($context);

        return $context->builder->call($context->lookupFunction(self::ABI), $classStr, $propStr);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function ensureLinked(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI, $probe);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        StringCaseCompare::ensureStrcasecmpLinked($context);

        $strPtr = $context->getTypeFromString('__string__*');
        $i1 = $context->getTypeFromString('int1');
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $ft = $context->context->functionType($i1, false, $strPtr, $strPtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(self::ABI, $ft);

        $entry = $fn->appendBasicBlock('refl_property_is_final_entry');
        $context->builder->positionAtEnd($entry);
        $classArg = $fn->getParam(0);
        $propArg = $fn->getParam(1);
        $strMap = $context->structFieldMap['__string__'];
        // Use structGep — hardcoded gep+16 on i8* drifts with __ref__ layout (#27315).
        $classCstr = $context->builder->pointerCast(
            $context->builder->structGep($classArg, $strMap['value']),
            $i8p
        );
        $propCstr = $context->builder->pointerCast(
            $context->builder->structGep($propArg, $strMap['value']),
            $i8p
        );

        $pairs = self::collectFinalPairs($context);
        $trueBlock = $fn->appendBasicBlock('refl_prop_final_yes');
        $falseBlock = $fn->appendBasicBlock('refl_prop_final_no');
        $checkBlock = $entry;
        $n = \count($pairs);
        foreach ($pairs as $idx => [$className, $propName]) {
            $context->builder->positionAtEnd($checkBlock);
            // constantStringFromString → __string__* (same shape as runtime args), not raw [N x i8]*.
            $wantClassStr = $context->builder->load($context->constantStringFromString($className));
            $wantPropStr = $context->builder->load($context->constantStringFromString($propName));
            $wantClass = $context->builder->pointerCast(
                $context->builder->structGep($wantClassStr, $strMap['value']),
                $i8p
            );
            $wantProp = $context->builder->pointerCast(
                $context->builder->structGep($wantPropStr, $strMap['value']),
                $i8p
            );
            $classCmp = $context->builder->call(
                $context->lookupFunction('strcasecmp'),
                $classCstr,
                $wantClass
            );
            $propCmp = $context->builder->call(
                $context->lookupFunction('strcasecmp'),
                $propCstr,
                $wantProp
            );
            $classMatch = $context->builder->icmp(Builder::INT_EQ, $classCmp, $i32->constInt(0, false));
            $propMatch = $context->builder->icmp(Builder::INT_EQ, $propCmp, $i32->constInt(0, false));
            $both = $context->builder->and($classMatch, $propMatch);
            $next = ($idx === $n - 1)
                ? $falseBlock
                : $fn->appendBasicBlock('refl_prop_final_try_'.($idx + 1));
            $context->builder->branchIf($both, $trueBlock, $next);
            $checkBlock = $next;
        }
        if (0 === $n) {
            $context->builder->positionAtEnd($entry);
            $context->builder->branch($falseBlock);
        }

        $context->builder->positionAtEnd($trueBlock);
        $context->builder->returnValue($i1->constInt(1, false));
        $context->builder->positionAtEnd($falseBlock);
        $context->builder->returnValue($i1->constInt(0, false));

        $context->registerFunction(self::ABI, $fn);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private static function collectFinalPairs(Context $context): array
    {
        $object = $context->type->object;
        $pairs = [];
        foreach ($object->allClassNamesById() as $classId => $className) {
            $display = $object->classNameForId((int) $classId);
            if (!\is_string($display) || '' === $display) {
                $display = \is_string($className) ? $className : '';
            }
            if ('' === $display) {
                continue;
            }
            // Skip engine Reflection* layout classes — only user finals matter for isFinal().
            $lc = strtolower($display);
            if (str_starts_with($lc, 'reflection')) {
                continue;
            }
            foreach ($object->finalPropertyNamesForClassId((int) $classId) as $propLc) {
                $pairs[] = [$display, $propLc];
            }
        }

        return $pairs;
    }
}
