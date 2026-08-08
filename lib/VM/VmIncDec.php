<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Shared ++/-- overflow helpers (zend_operators.h / zend_execute.c, #29144).
 *
 * Untyped integers promote to double at PHP_INT_MAX/MIN; typed int properties
 * TypeError with Zend's "past its maximal/minimal value" wording.
 */
final class VmIncDec
{
    /**
     * zend_operators.h fast_long_increment_function overflow result.
     */
    public static function overflowIncrementFloat(): float
    {
        return (float) \PHP_INT_MAX + 1.0;
    }

    /**
     * zend_operators.h fast_long_decrement_function overflow result.
     */
    public static function overflowDecrementFloat(): float
    {
        return (float) \PHP_INT_MIN - 1.0;
    }

    /**
     * True when a typed slot rejects the double produced by int overflow.
     *
     * Matches zend_execute.c zend_get_prop_not_accepting_double() for scalar
     * `int` (and unions that do not include float).
     */
    public static function typedSlotRejectsOverflowDouble(Variable $slot): bool
    {
        $target = $slot->resolveIndirect();
        if (null !== $target->unionTypeConstraints) {
            foreach ($target->unionTypeConstraints as $member) {
                if (Variable::TYPE_FLOAT === (int) $member) {
                    return false;
                }
            }
            foreach ($target->unionTypeConstraints as $member) {
                if (Variable::TYPE_INTEGER === (int) $member) {
                    return true;
                }
            }

            return false;
        }

        return Variable::TYPE_INTEGER === $target->typeConstraint;
    }

    /**
     * After int→float overflow from ++/--, throw Zend typed-property TypeError when needed.
     *
     * @see php-src Zend/zend_execute.c zend_throw_incdec_prop_error()
     */
    public static function throwIfTypedPropertyRejectsOverflow(
        Variable $write,
        Variable $before,
        Variable $after,
        bool $increment
    ): void {
        if (Variable::TYPE_INTEGER !== $before->type || Variable::TYPE_FLOAT !== $after->type) {
            return;
        }
        $target = $write->resolveIndirect();
        if (!self::typedSlotRejectsOverflowDouble($target)) {
            return;
        }
        // Untyped locals / untyped statics: promote stands (no TypeError).
        if (null === $target->objectPropertyOwner
            && null === $target->staticPropertyClassLc
            && (null === $target->objectPropertyName || '' === $target->objectPropertyName)
        ) {
            return;
        }

        throw new \TypeError(self::typedPropertyOverflowMessage($target, $increment));
    }

    /**
     * @see php-src Zend/zend_execute.c zend_throw_incdec_prop_error() /
     *      zend_throw_incdec_ref_error()
     */
    public static function typedPropertyOverflowMessage(Variable $target, bool $increment): string
    {
        $type = $target->declaredTypeLabel ?? 'int';
        $propName = $target->objectPropertyName ?? 'property';
        $classLabel = self::propertyClassLabel($target);
        if ($target->typedPropertyByRef) {
            return $increment
                ? sprintf(
                    'Cannot increment a reference held by property %s::$%s of type %s past its maximal value',
                    $classLabel,
                    $propName,
                    $type
                )
                : sprintf(
                    'Cannot decrement a reference held by property %s::$%s of type %s past its minimal value',
                    $classLabel,
                    $propName,
                    $type
                );
        }

        return $increment
            ? sprintf(
                'Cannot increment property %s::$%s of type %s past its maximal value',
                $classLabel,
                $propName,
                $type
            )
            : sprintf(
                'Cannot decrement property %s::$%s of type %s past its minimal value',
                $classLabel,
                $propName,
                $type
            );
    }

    private static function propertyClassLabel(Variable $target): string
    {
        $owner = $target->objectPropertyOwner;
        if (null !== $owner) {
            return $owner->class->name;
        }
        $classLc = $target->staticPropertyClassLc;
        if (null !== $classLc && '' !== $classLc) {
            $vm = \PHPCompiler\VM::running();
            if (null !== $vm && isset($vm->context->classes[$classLc])) {
                return $vm->context->classes[$classLc]->name;
            }

            return $classLc;
        }

        return 'stdClass';
    }
}
