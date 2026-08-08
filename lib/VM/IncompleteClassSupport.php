<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\Frame;

/**
 * Zend __PHP_Incomplete_Class property handlers (zend_object_handlers.c, #19632 / #4638 / #29025).
 *
 * Userland property read/isset/property_exists warn and yield null/false; write/unset throw Error.
 * Internals (serialize, get_object_vars, var_export) still see stored properties.
 *
 * Access warnings use zend_error's function-name prefix (`main():`, `f():`, `C::m():`,
 * `property_exists():`, `{closure}():`). Modify Error messages are unprefixed (zend_throw_error).
 *
 * @see https://github.com/php/php-src/blob/master/Zend/zend_object_handlers.c
 * @see https://github.com/php/php-src/blob/master/ext/standard/var_unserializer.re
 */
final class IncompleteClassSupport
{
    public const CLASS_LC = '__php_incomplete_class';

    public const NAME_PROP = '__PHP_Incomplete_Class_Name';

    public static function isIncomplete(ObjectEntry $object): bool
    {
        return self::CLASS_LC === strtolower($object->class->name);
    }

    public static function originalClassName(ObjectEntry $object): string
    {
        if (!$object->hasProperty(self::NAME_PROP)) {
            return 'unknown';
        }
        $slot = $object->getProperty(self::NAME_PROP)->resolveIndirect();
        if (Variable::TYPE_STRING !== $slot->type) {
            return 'unknown';
        }

        return $slot->toString();
    }

    public static function accessWarningMessage(ObjectEntry $object, ?Frame $frame = null, ?string $functionName = null): string
    {
        return self::accessWarningMessageForClass(
            self::originalClassName($object),
            $functionName ?? self::functionNameFromFrame($frame)
        );
    }

    public static function accessWarningMessageForClass(string $originalClass, ?string $functionName = null): string
    {
        $prefix = self::normalizeErrorFunctionName($functionName ?? 'main');

        return $prefix.'(): The script tried to access a property on an incomplete object. '
            .'Please ensure that the class definition "'.$originalClass.'" of the object you are trying to operate on '
            .'was loaded _before_ unserialize() gets called or provide an autoloader to load the class definition';
    }

    public static function modifyErrorMessage(ObjectEntry $object): string
    {
        return self::modifyErrorMessageForClass(self::originalClassName($object));
    }

    public static function modifyErrorMessageForClass(string $originalClass): string
    {
        return 'The script tried to modify a property on an incomplete object. '
            .'Please ensure that the class definition "'.$originalClass.'" of the object you are trying to operate on '
            .'was loaded _before_ unserialize() gets called or provide an autoloader to load the class definition';
    }

    /**
     * @param ?string $functionName Override when the warning must name a builtin (e.g. property_exists)
     *                              regardless of the caller frame — matches Zend EG(current_execute_data).
     */
    public static function emitAccessWarning(
        ObjectEntry $object,
        Context $context,
        ?Frame $frame,
        ?string $functionName = null
    ): void {
        $context->errors->languageWarning(
            self::accessWarningMessage($object, $frame, $functionName),
            null,
            0,
            $context,
            $frame
        );
    }

    /**
     * Zend zend_error function-name label from the active execute frame.
     *
     * Method bodies clear {@see Frame::$call} after entry; qualify via $this / calledClass
     * (same shape as {@see ParamArgumentCountError} ARG_RECV naming, #19526 / #29025).
     */
    public static function functionNameFromFrame(?Frame $frame): string
    {
        if (null === $frame) {
            return 'main';
        }
        if (null !== $frame->handler) {
            return self::normalizeErrorFunctionName($frame->handler->getName());
        }
        if (null !== $frame->block && $frame->block->isMainScript()) {
            return 'main';
        }
        // Callee frames often leave $frame->call null after entry; parent may still hold Func\PHP.
        $call = $frame->call;
        if (!($call instanceof \PHPCompiler\Func\PHP) && null !== $frame->parent) {
            $call = $frame->parent->call;
        }
        if ($call instanceof \PHPCompiler\Func\PHP) {
            $fromCall = self::normalizeErrorFunctionName(
                ParamArgumentCountError::formatUserFunctionName($call->getName())
            );
            // Qualified Class::method already — use as-is. Bare method names need class prefix below.
            if (str_contains($fromCall, '::') || '{closure}' === $fromCall || 'main' === $fromCall) {
                return $fromCall;
            }
            $qualified = self::qualifyMethodName($frame, $fromCall);
            if (null !== $qualified) {
                return $qualified;
            }

            return $fromCall;
        }
        if (null !== $frame->closureCall || null !== $frame->pendingClosureInvoke) {
            return '{closure}';
        }
        $cfgFunc = $frame->block->func ?? null;
        if (null !== $cfgFunc && \is_string($cfgFunc->name)) {
            if ('{main}' === $cfgFunc->name || '' === $cfgFunc->name) {
                return 'main';
            }
            $qualified = self::qualifyMethodName($frame, $cfgFunc->name);
            if (null !== $qualified) {
                return $qualified;
            }

            return self::normalizeErrorFunctionName($cfgFunc->name);
        }

        return 'main';
    }

    /**
     * Build Class::method when the frame is a method body (instance or static).
     */
    private static function qualifyMethodName(Frame $frame, string $method): ?string
    {
        $cfgFunc = $frame->block->func ?? null;
        if (null === $cfgFunc || null === $cfgFunc->class) {
            return null;
        }
        $isStatic = (($cfgFunc->flags ?? 0) & \PHPCfg\Func::FLAG_STATIC) !== 0;
        if (!$isStatic) {
            $selfVar = null;
            if ([] !== $frame->callArgs) {
                $selfVar = $frame->callArgs[0]->resolveIndirect();
            } elseif (\array_key_exists(0, $frame->calledArgs)) {
                $selfVar = $frame->calledArgs[0]->resolveIndirect();
            }
            if (null !== $selfVar && Variable::TYPE_OBJECT === $selfVar->type) {
                return $selfVar->toObject()->class->name.'::'.$method;
            }
        }
        if (null !== $frame->calledClass && '' !== $frame->calledClass) {
            return $frame->calledClass.'::'.$method;
        }
        $className = self::cfgClassName($cfgFunc->class);
        if (null !== $className && '' !== $className) {
            return $className.'::'.$method;
        }

        return null;
    }

    /** @param mixed $class */
    private static function cfgClassName($class): ?string
    {
        if (\is_string($class)) {
            return ltrim($class, '\\');
        }
        if (\is_object($class) && isset($class->name) && \is_string($class->name)) {
            return ltrim($class->name, '\\');
        }

        return null;
    }

    private static function normalizeErrorFunctionName(string $name): string
    {
        if ('{main}' === $name || '' === $name) {
            return 'main';
        }
        // Host/VM anonymous labels → Zend zend_error `{closure}` (#29025).
        if (str_starts_with($name, '{anonymous}') || str_starts_with($name, '{closure}')) {
            return '{closure}';
        }

        return ParamArgumentCountError::formatUserFunctionName($name);
    }
}
