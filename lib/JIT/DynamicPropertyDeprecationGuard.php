<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\Type\Object_;
use PHPCompiler\VM\ErrorReporter;

/**
 * Emit E_DEPRECATED when JIT lowers a write to an undeclared instance property (#4570).
 */
final class DynamicPropertyDeprecationGuard
{
    public static function emitBeforeUndeclaredWrite(
        Context $context,
        Object_ $objectType,
        int $classId,
        string $className,
        string $propertyName
    ): void {
        if ($objectType->allowsDynamicProperties($classId)) {
            return;
        }
        if (MagicMethodDispatch::hasInstanceMethod($objectType, $classId, '__set')) {
            return;
        }
        if ($objectType->isReadonlyClass($classId)) {
            return;
        }

        $message = sprintf(
            'Creation of dynamic property %s::$%s is deprecated',
            $className,
            $propertyName
        );
        self::emitDeprecated($context, $message);
    }

    private static function emitDeprecated(Context $context, string $message): void
    {
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $i32 = $context->getTypeFromString('int32');
        $msgPtr = $context->builder->pointerCast($context->constantFromString($message), $i8p);
        $msgLen = $sizeT->constInt(\strlen($message), false);
        $emptyFile = $context->builder->pointerCast($context->constantFromString(''), $i8p);
        $context->builder->call(
            $context->lookupFunction('__compiler_trigger_error'),
            $msgPtr,
            $msgLen,
            $i32->constInt(ErrorReporter::E_DEPRECATED, false),
            $emptyFile,
            $i32->constInt(0, false)
        );
    }
}
