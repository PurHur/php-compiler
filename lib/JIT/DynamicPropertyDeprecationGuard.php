<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin\StringTriggerError;
use PHPCompiler\JIT\Builtin\Type\Object_;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\TypeCheck;

/**
 * Emit engine E_DEPRECATED notices from JIT lowering (#4570, #21953, #22828).
 */
final class DynamicPropertyDeprecationGuard
{
    public static function emitBeforeUndeclaredWrite(
        Context $context,
        Object_ $objectType,
        int $classId,
        string $className,
        string $propertyName,
        string $file = '',
        int $line = 0
    ): void {
        if ($objectType->allowsDynamicProperties($classId)) {
            return;
        }
        if (!\PHPCompiler\CompilerVersion::supportsDynamicPropertyCreationDeprecation()) {
            return;
        }
        if (MagicMethodDispatch::hasInstanceMethod($objectType, $classId, '__set')) {
            return;
        }
        if ($objectType->isReadonlyClass($classId)) {
            return;
        }
        // Generic "object" operands (lost userType after $c = $obj->prop) are external-only
        // without allowsDynamicProperties. AOT used to Error+clear insert, then PROPERTY_FETCH
        // continued and hit parentFunction (#26818). Zend allows dynamic props on these.
        if ('object' === strtolower(ltrim($className, '\\'))) {
            return;
        }
        // ZEND_ACC_NO_DYNAMIC_PROPERTIES: Error, not E_DEPRECATED (zend_object_handlers.c; #26371).
        // Dom\* are not sealed — Deprecated+write like other internals (#26566).
        // Enums sealed via declareEnum (#26588). Catchable try/catch is emitted at the
        // PROPERTY_FETCH call site (needs JIT instance); here we only abort for uncaught.
        if ($objectType->rejectsDynamicProperties($classId)) {
            \PHPCompiler\JIT\Builtin\ErrorRaise::emitRaise(
                $context,
                sprintf('Cannot create dynamic property %s::$%s', $className, $propertyName)
            );
            $context->builder->call($context->lookupFunction('abort'));
            $context->builder->clearInsertionPosition();

            return;
        }
        // AOT: external-class dynamic property bags are not wired yet — keep Error (avoid
        // segfault on DateTime/etc undeclared writes). JIT + VM match Zend deprecate+write (#26371).
        if (
            $objectType->isExternalOnlyClass($classId)
            && 0 !== ($context->runtime->mode & \PHPCompiler\Runtime::MODE_AOT)
        ) {
            \PHPCompiler\JIT\Builtin\ErrorRaise::emitRaise(
                $context,
                sprintf('Cannot create dynamic property %s::$%s', $className, $propertyName)
            );
            $context->builder->call($context->lookupFunction('abort'));
            $context->builder->clearInsertionPosition();

            return;
        }

        $message = sprintf(
            'Creation of dynamic property %s::$%s is deprecated',
            $className,
            $propertyName
        );
        self::emitDeprecated($context, $message, $file, $line);
    }

    /**
     * FETCH_DIM_W / []= on false — Zend 8.1+ E_DEPRECATED then promote (zend_execute.c, #22828).
     */
    public static function emitFalseToArray(Context $context, string $file = '', int $line = 0): void
    {
        StringTriggerError::ensureLinked($context);
        self::emitDeprecated(
            $context,
            TypeCheck::FALSE_TO_ARRAY_DEPRECATED_MESSAGE,
            $file,
            $line
        );
    }

    /**
     * Null array offset — Zend 8.5+ E_DEPRECATED then coerce to "" (zend_execute.c; #26276).
     */
    public static function emitNullArrayOffset(Context $context, string $file = '', int $line = 0): void
    {
        if (!\PHPCompiler\CompilerVersion::supportsNullArrayOffsetDeprecation()) {
            return;
        }
        StringTriggerError::ensureLinked($context);
        self::emitDeprecated(
            $context,
            HashTable::NULL_ARRAY_OFFSET_DEPRECATED_MESSAGE,
            $file,
            $line
        );
    }

    /**
     * array_key_exists(null, …) — Zend 8.5+ E_DEPRECATED (ext/standard/array.c; #26276).
     */
    public static function emitNullArrayKeyExists(Context $context, string $file = '', int $line = 0): void
    {
        if (!\PHPCompiler\CompilerVersion::supportsNullArrayOffsetDeprecation()) {
            return;
        }
        StringTriggerError::ensureLinked($context);
        self::emitDeprecated(
            $context,
            HashTable::NULL_ARRAY_KEY_EXISTS_DEPRECATED_MESSAGE,
            $file,
            $line
        );
    }

    private static function emitDeprecated(
        Context $context,
        string $message,
        string $file,
        int $line
    ): void {
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $i32 = $context->getTypeFromString('int32');
        $msgPtr = $context->builder->pointerCast($context->constantFromString($message), $i8p);
        $msgLen = $sizeT->constInt(\strlen($message), false);
        $filePtr = $context->builder->pointerCast($context->constantFromString($file), $i8p);
        $context->builder->call(
            $context->lookupFunction('__compiler_trigger_error'),
            $msgPtr,
            $msgLen,
            $i32->constInt(ErrorReporter::E_DEPRECATED, false),
            $filePtr,
            $i32->constInt(max(0, $line), false)
        );
    }
}
