<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Loose compare / spaceship when a Stringable object meets a string (#12055, Zend/zend_compare.c).
 *
 * php-src: compare_function() — object-to-scalar via convert_to_string / __toString
 * SSOT for VM opcodes and ext/standard array search builtins.
 */
final class CompareStringableHelper
{
    public static function isObjectStringPair(Variable $left, Variable $right): bool
    {
        $left = $left->resolveIndirect();
        $right = $right->resolveIndirect();

        return (Variable::TYPE_OBJECT === $left->type && Variable::TYPE_STRING === $right->type)
            || (Variable::TYPE_STRING === $left->type && Variable::TYPE_OBJECT === $right->type);
    }

    /**
     * @return ?bool null when operands are not object+string
     */
    public static function looseEqual(?\PHPCompiler\VM $vm, Variable $left, Variable $right): ?bool
    {
        if (!self::isObjectStringPair($left, $right)) {
            return null;
        }
        $left = $left->resolveIndirect();
        $right = $right->resolveIndirect();
        if (Variable::TYPE_OBJECT === $left->type) {
            $objectVar = $left;
            $stringVar = $right;
        } else {
            $objectVar = $right;
            $stringVar = $left;
        }
        if (null === $vm) {
            return false;
        }
        $coerced = self::tryCoerceObjectToCompareString($vm, $objectVar);
        if (null === $coerced) {
            return false;
        }

        return $coerced == $stringVar->toString($vm);
    }

    /**
     * @return ?int null when operands are not object+string
     */
    public static function spaceship(?\PHPCompiler\VM $vm, Variable $left, Variable $right): ?int
    {
        if (!self::isObjectStringPair($left, $right)) {
            return null;
        }
        $left = $left->resolveIndirect();
        $right = $right->resolveIndirect();
        $objectOnLeft = Variable::TYPE_OBJECT === $left->type;
        $objectVar = $objectOnLeft ? $left : $right;
        $stringVar = $objectOnLeft ? $right : $left;
        if (null === $vm) {
            return $objectOnLeft ? 1 : -1;
        }
        $coerced = self::tryCoerceObjectToCompareString($vm, $objectVar);
        if (null === $coerced) {
            return $objectOnLeft ? 1 : -1;
        }
        $cmp = strcmp($coerced, $stringVar->toString($vm));
        $spaceship = $cmp < 0 ? -1 : ($cmp > 0 ? 1 : 0);

        return $objectOnLeft ? $spaceship : -$spaceship;
    }

    public static function tryCoerceObjectToCompareString(\PHPCompiler\VM $vm, Variable $objectVar): ?string
    {
        $objectVar = $objectVar->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $objectVar->type) {
            return null;
        }
        $object = $objectVar->toObject();
        if (EnumCaseSupport::isEnumCase($object)) {
            return null;
        }
        if (ResourceSupport::isResourceObject($object)) {
            return null;
        }
        if (!$vm->hasInstanceMethod($object->class, '__tostring')) {
            return null;
        }

        return $vm->coerceVariableToString($objectVar);
    }
}
