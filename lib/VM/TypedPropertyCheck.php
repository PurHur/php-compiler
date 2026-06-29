<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Uninitialized typed property guards (Zend zend_object_handlers.c parity, #3429, #4908).
 */
final class TypedPropertyCheck
{
    /**
     * Zend php_get_object_vars / convert_to_array: skip null and uninitialized typed slots.
     */
    public static function omitFromPropertyEnumeration(Variable $var): bool
    {
        $target = $var->resolveIndirect();
        if (Variable::TYPE_NULL === $target->type) {
            return true;
        }

        return self::isUninitialized($target);
    }

    public static function isUninitialized(Variable $var): bool
    {
        $target = $var->resolveIndirect();
        if (Variable::TYPE_UNDEFINED !== $target->type) {
            return false;
        }
        if (null !== $target->staticPropertyClassLc && null !== $target->objectPropertyName) {
            return $target->hasDeclaredTypeConstraint();
        }
        if (null === $target->objectPropertyOwner) {
            return false;
        }
        $name = $target->objectPropertyName;
        if (null === $name) {
            return false;
        }
        foreach ($target->objectPropertyOwner->class->properties as $property) {
            if ($property->name !== $name) {
                continue;
            }
            // Typed slots use TYPE_UNDEFINED prototype; untyped use TYPE_NULL (#4240).
            if ($property->prototype->isUndefined()) {
                return true;
            }
            // Post-unset typed property with default (#4863, zend_object_handlers.c).
            if (Variable::TYPE_UNDEFINED === $target->type && $property->prototype->hasDeclaredTypeConstraint()) {
                return true;
            }
            // Readonly without default stays uninitialized until constructor assigns (#4248).
            if ($property->readonly && null === $property->default && !$property->hasRuntimeDefaultInit()) {
                return Variable::TYPE_UNDEFINED === $target->type;
            }

            return false;
        }

        return false;
    }

    public static function errorMessage(Variable $var): string
    {
        $target = $var->resolveIndirect();
        $name = $target->objectPropertyName ?? 'property';
        if (null !== $target->staticPropertyClassLc) {
            $classLabel = $target->staticPropertyClassLc;
            $vm = \PHPCompiler\VM::running();
            if (null !== $vm && isset($vm->context->classes[$target->staticPropertyClassLc])) {
                $classLabel = $vm->context->classes[$target->staticPropertyClassLc]->name;
            }

            return sprintf(
                'Typed static property %s::$%s must not be accessed before initialization',
                $classLabel,
                $name
            );
        }
        $owner = $target->objectPropertyOwner;

        return sprintf(
            'Typed property %s::$%s must not be accessed before initialization',
            $owner->class->name,
            $name
        );
    }

    public static function assertReadable(Variable $var): void
    {
        if (!self::isUninitialized($var)) {
            return;
        }
        $vm = \PHPCompiler\VM::running();
        if (null === $vm) {
            throw new \Error(self::errorMessage($var));
        }
        throw new TypedPropertyReadSignal($vm->makeEngineError(self::errorMessage($var)));
    }

    /**
     * Nullable typed property (`?T`, `T|null`, `mixed`) — Zend nullsafe short-circuit (#5220).
     */
    public static function propertyAllowsNull(Variable $var): bool
    {
        $target = $var->resolveIndirect();
        $label = $target->declaredTypeLabel;
        if (null !== $label) {
            if ('mixed' === $label || 'null' === $label) {
                return true;
            }
            if (str_starts_with($label, '?')) {
                return true;
            }
            foreach (explode('|', $label) as $arm) {
                if ('null' === trim($arm)) {
                    return true;
                }
            }
        }
        if (null !== $target->dnfArms) {
            foreach ($target->dnfArms as $arm) {
                if (($arm['kind'] ?? '') === 'literal' && 'null' === strtolower((string) ($arm['name'] ?? ''))) {
                    return true;
                }
            }
        }
        if (null !== $target->objectPropertyOwner && null !== $target->objectPropertyName) {
            return self::classPropertyAllowsNull($target->objectPropertyOwner, $target->objectPropertyName);
        }
        if (null !== $target->staticPropertyClassLc && null !== $target->objectPropertyName) {
            $vm = \PHPCompiler\VM::running();
            if (null !== $vm && isset($vm->context->classes[$target->staticPropertyClassLc])) {
                $entry = $vm->context->classes[$target->staticPropertyClassLc];
                if (isset($entry->staticProperties[strtolower($target->objectPropertyName)])) {
                    return self::propertyAllowsNull($entry->staticProperties[strtolower($target->objectPropertyName)]);
                }
            }
        }

        return false;
    }

    /**
     * ?-> receiver short-circuit: PHP null, or uninitialized nullable typed slot after a
     * standalone PropertyFetch (e.g. $a->b?->v, #5220). Chained $x?->y direct read throws
     * via nullsafeFetchPropertyRead (#5361); ??/isset/empty use nullsafeUninitNullableToNull (#13747).
     */
    public static function nullsafeShortCircuitReceiver(Variable $receiver): bool
    {
        $resolved = $receiver->resolveIndirect();
        if (Variable::TYPE_NULL === $resolved->type) {
            return true;
        }

        return self::isUninitialized($receiver) && self::propertyAllowsNull($receiver);
    }

    private static function classPropertyAllowsNull(ObjectEntry $owner, string $name): bool
    {
        foreach ($owner->class->properties as $property) {
            if ($property->name !== $name) {
                continue;
            }

            return self::propertyAllowsNull($property->prototype);
        }

        return false;
    }
}
