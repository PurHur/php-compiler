<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\Type\Object_ as ObjectBuiltin;

/**
 * JIT lowering for BackedEnum::from() / ::tryFrom() (#4053).
 *
 * php-src: Zend/zend_enum.c — zend_enum_from_case(), zend_try_enum_from_case()
 */
final class EnumFromHelper
{
    public static function registerFromMethods(Context $context, ObjectBuiltin $object, int $classId): void
    {
        if (!$object->enumHasBacking($classId)) {
            return;
        }
        $className = $object->classNameForId($classId);
        $backedType = $object->enumBackedTypeFor($classId);
        if (null === $backedType) {
            return;
        }
        BackedEnumFromJit::emitFromFunction($context, $object, $classId, $className, $backedType, false);
        BackedEnumFromJit::emitFromFunction($context, $object, $classId, $className, $backedType, true);
    }
}
