<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Uninitialized typed property guards (Zend zend_object_handlers.c parity, #3429, #4908).
 */
final class TypedPropertyCheck
{
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
}
