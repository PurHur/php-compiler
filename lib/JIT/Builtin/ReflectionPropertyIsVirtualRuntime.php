<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * JIT/AOT link for ReflectionProperty::isVirtual() (#27516).
 *
 * Virtual flags live on Object_::virtualPropertyNames (markPropertyVirtual during DECLARE_PROPERTY
 * from PropertyHooks registry) — emit a strcmp table instead of NestedJIT+VM probe.
 */
final class ReflectionPropertyIsVirtualRuntime
{
    private const ABI = '__phpc_refl_property_is_virtual';

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

        $entry = $fn->appendBasicBlock('refl_property_is_virtual_entry');
        $context->builder->positionAtEnd($entry);
        $classArg = $fn->getParam(0);
        $propArg = $fn->getParam(1);
        $strMap = $context->structFieldMap['__string__'];
        $classCstr = $context->builder->pointerCast(
            $context->builder->structGep($classArg, $strMap['value']),
            $i8p
        );
        $propCstr = $context->builder->pointerCast(
            $context->builder->structGep($propArg, $strMap['value']),
            $i8p
        );

        $pairs = self::collectVirtualPairs($context);
        $trueBlock = $fn->appendBasicBlock('refl_prop_virtual_yes');
        $falseBlock = $fn->appendBasicBlock('refl_prop_virtual_no');
        $checkBlock = $entry;
        $n = \count($pairs);
        foreach ($pairs as $idx => [$className, $propName]) {
            $context->builder->positionAtEnd($checkBlock);
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
                : $fn->appendBasicBlock('refl_prop_virtual_try_'.($idx + 1));
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
    private static function collectVirtualPairs(Context $context): array
    {
        $object = $context->type->object;
        $seen = [];
        $pairs = [];
        $add = static function (string $display, string $prop) use (&$seen, &$pairs): void {
            if ('' === $display || '' === $prop) {
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
            $pairs[] = [$display, $prop];
        };

        // PropertyHooks registry is filled before class-body lowering — prefer it so the
        // first isVirtual() call site still sees virtual props (#27516).
        $registry = $context->runtime->vmContext->propertyHookRegistry ?? [];
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
                if (!\is_string($propName) || !\is_array($meta) || empty($meta['virtual'])) {
                    continue;
                }
                $add($display, $propName);
            }
        }

        // Inherited virtual marks on child class ids (ReflectionProperty(Child::class, …)).
        foreach ($object->allClassNamesById() as $classId => $className) {
            $display = $object->classNameForId((int) $classId);
            if (!\is_string($display) || '' === $display) {
                $display = \is_string($className) ? $className : '';
            }
            foreach ($object->virtualPropertyNamesForClassId((int) $classId) as $propLc) {
                $add($display, $propLc);
            }
        }

        return $pairs;
    }
}
