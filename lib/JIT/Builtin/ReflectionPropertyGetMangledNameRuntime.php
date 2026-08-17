<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\MethodVisibility;
use PHPCompiler\VM\ClassProperty;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * JIT/AOT link for ReflectionProperty::getMangledName() (#27592).
 *
 * Emits a strcmp table over compile-unit (declaring class, prop) → Zend mangled key
 * from {@see PropertyMangle::propertyKey()} / VM ClassEntry properties.
 */
final class ReflectionPropertyGetMangledNameRuntime
{
    private const ABI = '__phpc_refl_property_get_mangled_name';

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
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $ft = $context->context->functionType($strPtr, false, $strPtr, $strPtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(self::ABI, $ft);

        $entry = $fn->appendBasicBlock('refl_property_get_mangled_entry');
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

        $pairs = self::collectMangledPairs($context);
        $fallbackBlock = $fn->appendBasicBlock('refl_prop_mangled_fallback');
        $checkBlock = $entry;
        $n = \count($pairs);
        foreach ($pairs as $idx => [$className, $propName, $mangled]) {
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
                $context->lookupFunction(StringCaseCompare::ABI_STRCASECMP),
                $classCstr,
                $wantClass
            );
            $propCmp = $context->builder->call(
                $context->lookupFunction(StringCaseCompare::ABI_STRCASECMP),
                $propCstr,
                $wantProp
            );
            $classMatch = $context->builder->icmp(Builder::INT_EQ, $classCmp, $i32->constInt(0, false));
            $propMatch = $context->builder->icmp(Builder::INT_EQ, $propCmp, $i32->constInt(0, false));
            $both = $context->builder->and($classMatch, $propMatch);
            $hit = $fn->appendBasicBlock('refl_prop_mangled_hit_'.$idx);
            $next = ($idx === $n - 1)
                ? $fallbackBlock
                : $fn->appendBasicBlock('refl_prop_mangled_try_'.($idx + 1));
            $context->builder->branchIf($both, $hit, $next);

            $context->builder->positionAtEnd($hit);
            $mangledStr = $context->builder->load($context->constantStringFromString($mangled));
            $context->builder->returnValue($mangledStr);
            $checkBlock = $next;
        }
        if (0 === $n) {
            $context->builder->positionAtEnd($entry);
            $context->builder->branch($fallbackBlock);
        }

        // Dynamic / unknown: Zend-shaped fallback is the unmangled property name.
        $context->builder->positionAtEnd($fallbackBlock);
        $propLen = $context->builder->call(
            $context->lookupFunction('strlen'),
            $propCstr
        );
        $propLen64 = $context->builder->zExt($propLen, $i64);
        $fallback = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $propLen64,
            $propCstr
        );
        $context->builder->returnValue($fallback);

        $context->registerFunction(self::ABI, $fn);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    /**
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private static function collectMangledPairs(Context $context): array
    {
        $seen = [];
        $pairs = [];
        $add = static function (string $declDisplay, string $prop, int $visibility) use (&$seen, &$pairs): void {
            if ('' === $declDisplay || '' === $prop) {
                return;
            }
            if (str_starts_with(strtolower($declDisplay), 'reflection')) {
                return;
            }
            $key = strtolower($declDisplay)."\0".strtolower($prop);
            if (isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            if (MethodVisibility::isPublic($visibility)) {
                $mangled = $prop;
            } elseif (($visibility & \PHPCfg\Func::FLAG_PROTECTED) !== 0) {
                $mangled = "\0*\0".$prop;
            } else {
                $mangled = "\0".$declDisplay."\0".$prop;
            }
            $pairs[] = [$declDisplay, $prop, $mangled];
        };

        // User + declared classes live on the JIT object type map during AOT (not always
        // mirrored into vmContext->classes when the ABI is first linked).
        $object = $context->type->object;
        foreach ($object->allClassNamesById() as $classId => $className) {
            $classId = (int) $classId;
            $display = $object->classNameForId($classId);
            if (!\is_string($display) || '' === $display) {
                $display = \is_string($className) ? $className : '';
            }
            foreach ($object->declaredPropertyNames($classId) as $propName) {
                if (!\is_string($propName) || '' === $propName) {
                    continue;
                }
                $meta = $object->instancePropertyVisibilityMeta($classId, $propName);
                if (null === $meta) {
                    $meta = $object->staticPropertyVisibilityMeta($classId, $propName);
                }
                if (null === $meta) {
                    $add($display, $propName, $object->propertyVisibility($classId, $propName));
                    continue;
                }
                $declName = $meta['declaringClassName'] ?? $display;
                if (!\is_string($declName) || '' === $declName) {
                    $declName = $display;
                }
                $add($declName, $propName, (int) $meta['visibility']);
            }
        }

        // Builtins still present only on the VM ClassEntry map.
        /** @var array<string, \PHPCompiler\VM\ClassEntry> $classes */
        $classes = $context->runtime->vmContext->classes ?? [];
        if (\is_array($classes)) {
            foreach ($classes as $entry) {
                if (!\is_object($entry) || !isset($entry->properties) || !\is_array($entry->properties)) {
                    continue;
                }
                foreach ($entry->properties as $meta) {
                    if (!$meta instanceof ClassProperty) {
                        continue;
                    }
                    $prop = (string) $meta->name;
                    $declDisplay = (string) $entry->name;
                    if ('' !== $meta->declaringClassLc && isset($classes[$meta->declaringClassLc]->name)) {
                        $declDisplay = (string) $classes[$meta->declaringClassLc]->name;
                    }
                    $add($declDisplay, $prop, (int) $meta->visibility);
                }
            }
        }

        return $pairs;
    }
}
