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

    /**
     * Zend php_var_serialize plain object: omit only uninitialized typed slots, not null (#14619).
     */
    public static function omitFromSerialize(Variable $var): bool
    {
        return self::isUninitialized($var);
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
            // Typed slots (incl. mixed) use TYPE_UNDEFINED prototype; untyped use TYPE_NULL (#4240, #22021).
            if ($property->prototype->isUndefined()) {
                return true;
            }
            // Post-unset typed property with default (#4863, zend_object_handlers.c).
            if (
                Variable::TYPE_UNDEFINED === $target->type
                && $property->prototype->hasDeclaredTypeConstraint()
            ) {
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
                \PHPCompiler\MethodVisibility::formatAnonymousScopeForMessage($classLabel),
                $name
            );
        }
        $owner = $target->objectPropertyOwner;

        return sprintf(
            'Typed property %s::$%s must not be accessed before initialization',
            \PHPCompiler\MethodVisibility::formatAnonymousScopeForMessage($owner->class->name),
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
     * Zend by-reference write to uninitialized non-nullable typed property (zend_types.c, type.c).
     */
    public static function assertWritableByReference(Variable $var): void
    {
        if (!self::isUninitialized($var)) {
            return;
        }
        if (self::propertyAllowsNull($var)) {
            return;
        }
        $vm = \PHPCompiler\VM::running();
        $message = self::writableByReferenceErrorMessage($var);
        if (null === $vm) {
            throw new \Error($message);
        }
        throw new TypedPropertyReadSignal($vm->makeEngineError($message));
    }

    public static function writableByReferenceErrorMessage(Variable $var): string
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
                'Cannot access uninitialized non-nullable property %s::$%s by reference',
                \PHPCompiler\MethodVisibility::formatAnonymousScopeForMessage($classLabel),
                $name
            );
        }
        $owner = $target->objectPropertyOwner;

        return sprintf(
            'Cannot access uninitialized non-nullable property %s::$%s by reference',
            \PHPCompiler\MethodVisibility::formatAnonymousScopeForMessage($owner->class->name),
            $name
        );
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
     * ?-> receiver short-circuit — only null (and uninitialized nullable typed slots).
     *
     * Property (#26365, zend_vm_def.h): non-null non-objects fall through to the fetch arm and
     * warn like plain `->`. IS-mode (??/isset/empty) stays silent via nullsafeUninitNullableToNull
     * (#18026 coalesce / FETCH_OBJ_IS). Chained $x?->y direct read uses nullsafeFetchPropertyRead
     * (#5361); uninit nullable under ?? uses nullsafeUninitNullableToNull (#13747, #5220).
     *
     * Method (#26364, ZEND_INIT_METHOD_CALL): same null-only short-circuit; scalars Error.
     *
     * `$forMethodCall` is retained for call-site clarity; both paths share null-only rules.
     */
    public static function nullsafeShortCircuitReceiver(
        Variable $receiver,
        bool $forMethodCall = false
    ): bool {
        unset($forMethodCall);
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
