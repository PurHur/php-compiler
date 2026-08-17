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

    /**
     * DEBUG-bag slot for var_dump / debug_zval_dump (#31147).
     *
     * Prefer {@see isUninitialized()}; also accept TYPE_UNDEFINED copies that still carry a
     * declared type label/constraint (copyUninitializedStaticPropertySlot without owner).
     */
    public static function isUninitializedDebugSlot(Variable $var): bool
    {
        if (self::isUninitialized($var)) {
            return true;
        }
        $target = $var->resolveIndirect();
        if (Variable::TYPE_UNDEFINED !== $target->type) {
            return false;
        }

        return $target->hasDeclaredTypeConstraint() || null !== $target->declaredTypeLabel;
    }

    /**
     * php-src zend_uninitialized_prop_type_string() — type text inside uninitialized(%s) (#31147).
     */
    public static function uninitializedTypeString(Variable $var): string
    {
        $target = $var->resolveIndirect();
        $label = (string) ($target->declaredTypeLabel ?? '');
        if ('' !== $label) {
            return $label;
        }
        $class = (string) ($target->classConstraint ?? '');
        if ('' !== $class) {
            return $class;
        }
        if (null !== $target->literalBoolType) {
            return $target->literalBoolType;
        }
        if (Variable::TYPE_UNDEFINED === $target->type && !$target->hasDeclaredTypeConstraint()) {
            // Explicit mixed: typed UNDEFINED without label/constraint.
            return 'mixed';
        }
        if (null !== $target->typeConstraint) {
            return TypeCheck::typeNameForConstraint((int) $target->typeConstraint, $target->literalBoolType);
        }
        if (null !== $target->objectPropertyOwner && null !== $target->objectPropertyName) {
            foreach ($target->objectPropertyOwner->class->properties as $property) {
                if ($property->name !== $target->objectPropertyName) {
                    continue;
                }

                return self::uninitializedTypeString($property->prototype);
            }
        }

        return 'mixed';
    }

    public static function errorMessage(Variable $var): string
    {
        $target = $var->resolveIndirect();
        $name = $target->objectPropertyName ?? 'property';
        if (null !== $target->staticPropertyClassLc) {
            return sprintf(
                'Typed static property %s::$%s must not be accessed before initialization',
                self::declaringClassDisplayName($target),
                $name
            );
        }

        return sprintf(
            'Typed property %s::$%s must not be accessed before initialization',
            self::declaringClassDisplayName($target),
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

    /**
     * Zend zend_std_get_property_ptr_ptr before creating an INDIRECT (#31771).
     *
     * Uninitialized non-nullable typed properties Error; nullable typed slots become null
     * (ZVAL_NULL) so `$r = &$o->y` aliases an initialized null rather than UNDEF.
     */
    public static function prepareWritableByReference(Variable $var): void
    {
        self::assertWritableByReference($var);
        if (!self::isUninitialized($var)) {
            return;
        }
        $var->resolveIndirect()->null();
    }

    public static function writableByReferenceErrorMessage(Variable $var): string
    {
        $target = $var->resolveIndirect();
        $name = $target->objectPropertyName ?? 'property';

        return sprintf(
            'Cannot access uninitialized non-nullable property %s::$%s by reference',
            self::declaringClassDisplayName($target),
            $name
        );
    }

    /**
     * php-src zend_uninitialized_property_error / prop_info->ce — declaring class, not the
     * instance class (#31785, Zend/zend_object_handlers.c).
     */
    private static function declaringClassDisplayName(Variable $target): string
    {
        $vm = \PHPCompiler\VM::running();
        $name = $target->objectPropertyName;
        if (null !== $target->staticPropertyClassLc) {
            $accessedLc = $target->staticPropertyClassLc;
            $display = $accessedLc;
            if (null !== $vm && isset($vm->context->classes[$accessedLc])) {
                $entry = $vm->context->classes[$accessedLc];
                $propLc = strtolower((string) $name);
                $declLc = $entry->staticPropertyDeclaringClassLc[$propLc] ?? $accessedLc;
                if (!isset($entry->staticPropertyDeclaringClassLc[$propLc])) {
                    $currentLc = $entry->parentLc;
                    while (null !== $currentLc && isset($vm->context->classes[$currentLc])) {
                        $current = $vm->context->classes[$currentLc];
                        if (isset($current->staticPropertyDeclaringClassLc[$propLc])) {
                            $declLc = $current->staticPropertyDeclaringClassLc[$propLc];
                            break;
                        }
                        if (isset($current->staticProperties[$propLc])) {
                            $declLc = strtolower($current->name);
                            break;
                        }
                        $currentLc = $current->parentLc;
                    }
                }
                $display = ($vm->context->classes[$declLc] ?? $entry)->name;
            }

            return \PHPCompiler\MethodVisibility::formatAnonymousScopeForMessage($display);
        }
        $owner = $target->objectPropertyOwner;
        $display = $owner->class->name;
        if (null !== $name) {
            foreach ($owner->class->properties as $property) {
                if ($property->name !== $name) {
                    continue;
                }
                $declLc = $property->declaringClassLc;
                if ('' !== $declLc && null !== $vm && isset($vm->context->classes[$declLc])) {
                    $display = $vm->context->classes[$declLc]->name;
                } elseif ('' !== $declLc) {
                    $display = $declLc;
                }
                // Child properties are listed before inherited parent slots (#22521).
                break;
            }
        }

        return \PHPCompiler\MethodVisibility::formatAnonymousScopeForMessage($display);
    }

    /**
     * FETCH_DIM_W / []= on an uninitialized typed array property (zend_std_get_property_ptr_ptr
     * BP_VAR_W + zend_try_array_init, #31770). Scalars stay uninitialized so assertReadable Errors.
     *
     * @return bool true when the slot is writable as an array (already initialized, or just inited)
     */
    public static function tryInitEmptyArrayForDimWrite(Variable $var): bool
    {
        $target = $var->resolveIndirect();
        if (!self::isUninitialized($target)) {
            return true;
        }
        if (!self::propertyAllowsArray($target)) {
            return false;
        }
        $target->array(new HashTable());

        return true;
    }

    /**
     * True when the declared type contains `array` (or `mixed`) — ZEND_TYPE_CONTAINS_CODE(IS_ARRAY).
     */
    public static function propertyAllowsArray(Variable $var): bool
    {
        $target = $var->resolveIndirect();
        if (self::slotTypeAllowsArray($target)) {
            return true;
        }
        if (null !== $target->objectPropertyOwner && null !== $target->objectPropertyName) {
            foreach ($target->objectPropertyOwner->class->properties as $property) {
                if ($property->name !== $target->objectPropertyName) {
                    continue;
                }
                if ($property->prototype === $target) {
                    return false;
                }

                return self::propertyAllowsArray($property->prototype);
            }
        }
        if (null !== $target->staticPropertyClassLc && null !== $target->objectPropertyName) {
            $vm = \PHPCompiler\VM::running();
            if (null !== $vm && isset($vm->context->classes[$target->staticPropertyClassLc])) {
                $entry = $vm->context->classes[$target->staticPropertyClassLc];
                $lc = strtolower($target->objectPropertyName);
                if (isset($entry->staticProperties[$lc])) {
                    $proto = $entry->staticProperties[$lc];
                    if ($proto !== $target) {
                        return self::propertyAllowsArray($proto);
                    }
                }
            }
        }

        return false;
    }

    private static function slotTypeAllowsArray(Variable $target): bool
    {
        if (Variable::TYPE_ARRAY === $target->typeConstraint) {
            return true;
        }
        if (null !== $target->genericArrayTypeSpec) {
            return true;
        }
        if (null !== $target->unionTypeConstraints) {
            foreach ($target->unionTypeConstraints as $member) {
                if (Variable::TYPE_ARRAY === $member) {
                    return true;
                }
            }
        }
        $label = strtolower((string) ($target->declaredTypeLabel ?? ''));
        if ('' !== $label) {
            if ('mixed' === $label || 'array' === $label || '?array' === $label) {
                return true;
            }
            $normalized = str_replace(['(', ')'], '', $label);
            foreach (explode('|', $normalized) as $arm) {
                $arm = trim($arm);
                if (str_starts_with($arm, '?')) {
                    $arm = substr($arm, 1);
                }
                if ('array' === $arm || 'mixed' === $arm) {
                    return true;
                }
            }
        }
        if (null !== $target->dnfArms) {
            foreach ($target->dnfArms as $arm) {
                $name = strtolower((string) ($arm['name'] ?? ''));
                if ('array' === $name || 'mixed' === $name) {
                    return true;
                }
            }
        }

        return false;
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
                $slot = $entry->staticProperties[strtolower($target->objectPropertyName)] ?? null;
                // The live static cell already carries type metadata; do not recurse into self (#31771).
                if (null !== $slot && $slot !== $target) {
                    return self::propertyAllowsNull($slot);
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
