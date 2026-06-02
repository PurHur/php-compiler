<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Uninitialized typed property guards (Zend zend_object_handlers.c parity, #3429).
 */
final class TypedPropertyCheck
{
    public static function isUninitialized(Variable $var): bool
    {
        $target = $var->resolveIndirect();
        if (null === $target->objectPropertyOwner || Variable::TYPE_UNDEFINED !== $target->type) {
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
            return $property->prototype->isUndefined();
        }

        return false;
    }

    public static function errorMessage(Variable $var): string
    {
        $target = $var->resolveIndirect();
        $owner = $target->objectPropertyOwner;
        $name = $target->objectPropertyName ?? 'property';

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
