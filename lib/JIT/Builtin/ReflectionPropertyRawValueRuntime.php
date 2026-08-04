<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Builtin\Type\ObjectInstancePropertyLlvm;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\PropertyHookDispatch;
use PHPCompiler\JIT\TypedPropertyUninitGuard;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * JIT/AOT link for ReflectionProperty::{getRawValue,setRawValue} (#27598, #6451).
 *
 * Reads/writes backing storage without property hooks — strcmp table over compile-unit
 * (class, prop) → backing name (PropertyHooks registry), then slot fetch/store.
 * php-src: ext/reflection/php_reflection.c
 */
final class ReflectionPropertyRawValueRuntime
{
    private const GET_ABI = '__phpc_refl_property_get_raw';

    private const SET_ABI = '__phpc_refl_property_set_raw';

    public static function invokeGet(
        Context $context,
        Value $targetObj,
        Value $classStr,
        Value $propStr,
        Value $outSlot
    ): void {
        self::ensureLinked($context);
        $context->builder->call(
            $context->lookupFunction(self::GET_ABI),
            $targetObj,
            $classStr,
            $propStr,
            JitValueBox::pointer($context, $outSlot)
        );
    }

    public static function invokeSet(
        Context $context,
        Value $targetObj,
        Value $classStr,
        Value $propStr,
        Variable $value
    ): void {
        self::ensureLinked($context);
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $value);
        $context->builder->call(
            $context->lookupFunction(self::SET_ABI),
            $targetObj,
            $classStr,
            $propStr,
            $valuePtr
        );
    }

    public static function ensureLinked(Context $context): void
    {
        $probeGet = $context->module->getNamedFunction(self::GET_ABI);
        if (null !== $probeGet && $probeGet->countBasicBlocks() > 0) {
            $context->registerFunction(self::GET_ABI, $probeGet);
            $probeSet = $context->module->getNamedFunction(self::SET_ABI);
            if (null !== $probeSet) {
                $context->registerFunction(self::SET_ABI, $probeSet);
            }

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        StringCaseCompare::ensureStrcasecmpLinked($context);
        self::emitGetAbi($context, $probeGet);
        self::emitSetAbi($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function emitGetAbi(Context $context, ?Value $probe): void
    {
        $objPtr = $context->getTypeFromString('__object__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $void = $context->context->voidType();
        $ft = $context->context->functionType($void, false, $objPtr, $strPtr, $strPtr, $valuePtr);
        $fn = null !== $probe ? $probe : $context->module->addFunction(self::GET_ABI, $ft);

        $entry = $fn->appendBasicBlock('refl_prop_get_raw_entry');
        $context->builder->positionAtEnd($entry);
        $obj = $fn->getParam(0);
        $classArg = $fn->getParam(1);
        $propArg = $fn->getParam(2);
        $out = $fn->getParam(3);
        [$classCstr, $propCstr] = self::stringArgsAsCstr($context, $classArg, $propArg);

        $triples = self::collectTriples($context);
        $miss = $fn->appendBasicBlock('refl_prop_get_raw_miss');
        $checkBlock = $entry;
        $n = \count($triples);
        $object = $context->type->object;
        foreach ($triples as $idx => [$className, $propName, $backingName]) {
            $context->builder->positionAtEnd($checkBlock);
            $both = self::emitClassPropMatch($context, $classCstr, $propCstr, $className, $propName);
            $case = $fn->appendBasicBlock('refl_prop_get_raw_case_'.$idx);
            $next = ($idx === $n - 1)
                ? $miss
                : $fn->appendBasicBlock('refl_prop_get_raw_try_'.($idx + 1));
            $context->builder->branchIf($both, $case, $next);
            $context->builder->positionAtEnd($case);
            $fetched = $object->propertyFetch($obj, $className, $backingName);
            TypedPropertyUninitGuard::emitBeforeRead($context, $fetched);
            ObjectInstancePropertyLlvm::boxFetchedPropertyIntoValue(
                $object,
                $out,
                $fetched,
                $fetched->objectPropertyType ?? $fetched->type
            );
            $context->builder->returnVoid();
            $checkBlock = $next;
        }
        if (0 === $n) {
            $context->builder->positionAtEnd($entry);
            $context->builder->branch($miss);
        }
        $context->builder->positionAtEnd($miss);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $out);
        $context->builder->returnVoid();

        $context->registerFunction(self::GET_ABI, $fn);
    }

    private static function emitSetAbi(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::SET_ABI);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::SET_ABI, $probe);

            return;
        }

        $objPtr = $context->getTypeFromString('__object__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $void = $context->context->voidType();
        $ft = $context->context->functionType($void, false, $objPtr, $strPtr, $strPtr, $valuePtr);
        $fn = null !== $probe ? $probe : $context->module->addFunction(self::SET_ABI, $ft);

        $entry = $fn->appendBasicBlock('refl_prop_set_raw_entry');
        $context->builder->positionAtEnd($entry);
        $obj = $fn->getParam(0);
        $classArg = $fn->getParam(1);
        $propArg = $fn->getParam(2);
        $valueArg = $fn->getParam(3);
        [$classCstr, $propCstr] = self::stringArgsAsCstr($context, $classArg, $propArg);

        $triples = self::collectTriples($context);
        $miss = $fn->appendBasicBlock('refl_prop_set_raw_miss');
        $checkBlock = $entry;
        $n = \count($triples);
        $object = $context->type->object;
        $valueVar = new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VALUE, $valueArg);
        foreach ($triples as $idx => [$className, $propName, $backingName]) {
            $context->builder->positionAtEnd($checkBlock);
            $both = self::emitClassPropMatch($context, $classCstr, $propCstr, $className, $propName);
            $case = $fn->appendBasicBlock('refl_prop_set_raw_case_'.$idx);
            $next = ($idx === $n - 1)
                ? $miss
                : $fn->appendBasicBlock('refl_prop_set_raw_try_'.($idx + 1));
            $context->builder->branchIf($both, $case, $next);
            $context->builder->positionAtEnd($case);
            $object->storeInstanceProperty($obj, $className, $backingName, $valueVar);
            $context->builder->returnVoid();
            $checkBlock = $next;
        }
        if (0 === $n) {
            $context->builder->positionAtEnd($entry);
            $context->builder->branch($miss);
        }
        $context->builder->positionAtEnd($miss);
        $context->builder->returnVoid();

        $context->registerFunction(self::SET_ABI, $fn);
    }

    /**
     * @return array{0: Value, 1: Value}
     */
    private static function stringArgsAsCstr(Context $context, Value $classArg, Value $propArg): array
    {
        $i8p = $context->getTypeFromString('int8*');
        $strMap = $context->structFieldMap['__string__'];

        return [
            $context->builder->pointerCast(
                $context->builder->structGep($classArg, $strMap['value']),
                $i8p
            ),
            $context->builder->pointerCast(
                $context->builder->structGep($propArg, $strMap['value']),
                $i8p
            ),
        ];
    }

    private static function emitClassPropMatch(
        Context $context,
        Value $classCstr,
        Value $propCstr,
        string $className,
        string $propName
    ): Value {
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $strMap = $context->structFieldMap['__string__'];
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

        return $context->builder->and($classMatch, $propMatch);
    }

    /**
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private static function collectTriples(Context $context): array
    {
        $object = $context->type->object;
        $seen = [];
        $triples = [];
        $add = static function (string $display, string $prop, string $backing) use (&$seen, &$triples): void {
            if ('' === $display || '' === $prop || '' === $backing) {
                return;
            }
            $lc = strtolower($display);
            if (str_starts_with($lc, 'reflection')) {
                return;
            }
            $key = $lc."\0".strtolower($prop);
            if (isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            $triples[] = [$display, $prop, $backing];
        };

        $registry = $context->runtime->vmContext->propertyHookRegistry ?? [];
        foreach ($object->allClassNamesById() as $classId => $className) {
            $display = $object->classNameForId((int) $classId);
            if (!\is_string($display) || '' === $display) {
                $display = \is_string($className) ? $className : '';
            }
            if ('' === $display) {
                continue;
            }
            foreach ($object->instancePropertySets((int) $classId) as $propset) {
                $propName = $propset[1];
                $backing = PropertyHookDispatch::hookedPropertyBackingName($context, $display, $propName)
                    ?? $propName;
                $add($display, $propName, $backing);
            }
        }

        // Hooked public names may be virtual (absent from instancePropertySets) — still
        // addressable via ReflectionProperty(Class, 'hookedName') → backing (#6451).
        foreach ($registry as $lcClass => $props) {
            if (!\is_array($props) || !\is_string($lcClass) || '' === $lcClass) {
                continue;
            }
            $display = (string) $lcClass;
            $classId = $object->classIdForLowerName((string) $lcClass);
            if (null !== $classId) {
                $resolved = $object->classNameForId($classId);
                if (\is_string($resolved) && '' !== $resolved) {
                    $display = $resolved;
                }
            }
            foreach ($props as $propName => $meta) {
                if (!\is_string($propName) || !\is_array($meta)) {
                    continue;
                }
                if (!isset($meta['get']) && !isset($meta['set'])) {
                    continue;
                }
                $backing = PropertyHookDispatch::hookedPropertyBackingName($context, $display, $propName)
                    ?? $propName;
                $add($display, $propName, $backing);
            }
        }

        return $triples;
    }
}
