<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\OpCode;
use PHPCompiler\VM\ClassEntry;

/**
 * Shared try/catch catch-arm type matching for VM (#9663, php-in-PHP).
 *
 * php-src: Zend/zend_exceptions.c — caught class / interface checks
 */
final class VmTryCatch
{
    public static function encodedTypesMatchOpcode(OpCode $op, Variable $thrown, Context $context): bool
    {
        $encoded = $op->catchTypes;
        if (null === $encoded || '' === $encoded) {
            return true;
        }

        return self::encodedTypesMatchVariable($encoded, $thrown, $context);
    }

    public static function encodedTypesMatchVariable(string $encoded, Variable $thrown, Context $context): bool
    {
        if ('' === $encoded) {
            return true;
        }
        $types = explode('|', $encoded);
        if (Variable::TYPE_OBJECT !== $thrown->type) {
            return false;
        }
        $class = $thrown->toObject()->class;
        foreach ($types as $typeName) {
            if ('' === $typeName) {
                continue;
            }
            if (self::objectIsInstanceOfClass($class, $typeName, $context)) {
                return true;
            }
        }

        return false;
    }

    public static function objectIsInstanceOfClass(ClassEntry $class, string $typeName, Context $context): bool
    {
        $want = strtolower(ltrim($typeName, '\\'));
        $target = $context->classes[$want] ?? null;
        if (null !== $target && $target->isInterface) {
            return InterfaceCheck::entryImplements($class, $want, $context);
        }

        return InterfaceCheck::entryIsInstanceOf($class, $want, $context);
    }
}
