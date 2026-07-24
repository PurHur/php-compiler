<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\MethodVisibility;

/**
 * Zend trait property merge rules (zend_inheritance.c zend_do_traits_property_binding).
 *
 * Identical visibility / readonly / type / default → merge; otherwise incompatible fatal (#22850).
 */
final class TraitPropertyCompatibility
{
    public static function instancePropertiesCompatible(ClassProperty $left, ClassProperty $right): bool
    {
        if (MethodVisibility::mask($left->visibility) !== MethodVisibility::mask($right->visibility)) {
            return false;
        }
        if ($left->readonly !== $right->readonly) {
            return false;
        }
        if ($left->setVisibility !== $right->setVisibility
            || $left->getVisibility !== $right->getVisibility
            || $left->asymmetricExplicitRead !== $right->asymmetricExplicitRead) {
            return false;
        }
        if (!self::prototypesCompatible($left->prototype, $right->prototype)) {
            return false;
        }

        return self::defaultsCompatible(
            $left->default,
            $left->hasDeclaredType(),
            $right->default,
            $right->hasDeclaredType()
        );
    }

    /**
     * Static property composition — flags + default value (#22850, zend_do_traits_property_binding).
     */
    public static function staticPropertiesCompatible(
        Variable $leftStorage,
        int $leftVisibility,
        ?Variable $leftPrototype,
        Variable $rightStorage,
        int $rightVisibility,
        ?Variable $rightPrototype,
        int $leftSetVisibility = 0,
        int $rightSetVisibility = 0,
        int $leftGetVisibility = 0,
        int $rightGetVisibility = 0,
        bool $leftAsymmetricExplicitRead = false,
        bool $rightAsymmetricExplicitRead = false,
    ): bool {
        if (MethodVisibility::mask($leftVisibility) !== MethodVisibility::mask($rightVisibility)) {
            return false;
        }
        if ($leftSetVisibility !== $rightSetVisibility
            || $leftGetVisibility !== $rightGetVisibility
            || $leftAsymmetricExplicitRead !== $rightAsymmetricExplicitRead) {
            return false;
        }
        $leftTyped = null !== $leftPrototype && self::protoIsTyped($leftPrototype);
        $rightTyped = null !== $rightPrototype && self::protoIsTyped($rightPrototype);
        if ($leftTyped !== $rightTyped) {
            return false;
        }
        if ($leftTyped && null !== $leftPrototype && null !== $rightPrototype
            && !self::prototypesCompatible($leftPrototype, $rightPrototype)) {
            return false;
        }

        return self::staticDefaultsCompatible($leftStorage, $leftTyped, $rightStorage, $rightTyped);
    }

    public static function prototypesCompatible(Variable $left, Variable $right): bool
    {
        $leftTyped = self::protoIsTyped($left);
        $rightTyped = self::protoIsTyped($right);
        if ($leftTyped !== $rightTyped) {
            return false;
        }
        if (!$leftTyped) {
            return true;
        }
        if (($left->declaredTypeLabel ?? '') !== ($right->declaredTypeLabel ?? '')) {
            return false;
        }
        if (($left->classConstraint ?? '') !== ($right->classConstraint ?? '')) {
            return false;
        }
        if ($left->typeConstraint !== $right->typeConstraint) {
            return false;
        }

        return self::dnfArmsCompatible($left->dnfArms, $right->dnfArms);
    }

    /**
     * Untyped missing default ≡ null; typed missing default ≡ typed missing (IS_UNDEF).
     */
    public static function defaultsCompatible(
        ?Variable $left,
        bool $leftTyped,
        ?Variable $right,
        bool $rightTyped,
    ): bool {
        if ($leftTyped !== $rightTyped) {
            return false;
        }
        if ($leftTyped) {
            if (null === $left && null === $right) {
                return true;
            }
            if (null === $left || null === $right) {
                return false;
            }

            return $left->identicalTo($right);
        }

        return self::normalizeUntypedDefault($left)->identicalTo(self::normalizeUntypedDefault($right));
    }

    private static function staticDefaultsCompatible(
        Variable $left,
        bool $leftTyped,
        Variable $right,
        bool $rightTyped,
    ): bool {
        if ($leftTyped !== $rightTyped) {
            return false;
        }
        if ($leftTyped) {
            $leftMissing = $left->isUndefined();
            $rightMissing = $right->isUndefined();
            if ($leftMissing && $rightMissing) {
                return true;
            }
            if ($leftMissing || $rightMissing) {
                return false;
            }

            return $left->identicalTo($right);
        }
        // Untyped static: UNDEFINED/missing storage reads as null in Zend default table.
        $l = $left->isUndefined() ? self::nullVar() : $left;
        $r = $right->isUndefined() ? self::nullVar() : $right;

        return $l->identicalTo($r);
    }

    private static function normalizeUntypedDefault(?Variable $default): Variable
    {
        if (null === $default || $default->isUndefined()) {
            return self::nullVar();
        }

        return $default;
    }

    private static function nullVar(): Variable
    {
        $v = new Variable();
        $v->null();

        return $v;
    }

    private static function protoIsTyped(Variable $proto): bool
    {
        if (Variable::TYPE_UNDEFINED === $proto->type) {
            return true;
        }
        if (null !== $proto->declaredTypeLabel && '' !== $proto->declaredTypeLabel) {
            return true;
        }
        if (null !== $proto->classConstraint && '' !== $proto->classConstraint) {
            return true;
        }

        return $proto->hasDeclaredTypeConstraint();
    }

    /**
     * @param mixed $left
     * @param mixed $right
     */
    private static function dnfArmsCompatible($left, $right): bool
    {
        if (null === $left && null === $right) {
            return true;
        }
        if (!is_array($left) || !is_array($right)) {
            return false;
        }

        return $left == $right;
    }
}
