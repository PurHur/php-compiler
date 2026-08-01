<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\Frame;

/**
 * Zend __PHP_Incomplete_Class property handlers (zend_object_handlers.c, #19632 / #4638).
 *
 * Userland property read/isset/property_exists warn and yield null/false; write/unset throw Error.
 * Internals (serialize, get_object_vars, var_export) still see stored properties.
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

    public static function accessWarningMessage(ObjectEntry $object): string
    {
        return self::accessWarningMessageForClass(self::originalClassName($object));
    }

    public static function accessWarningMessageForClass(string $originalClass): string
    {
        return 'The script tried to access a property on an incomplete object. '
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

    public static function emitAccessWarning(ObjectEntry $object, Context $context, ?Frame $frame): void
    {
        $context->errors->languageWarning(
            self::accessWarningMessage($object),
            null,
            0,
            $context,
            $frame
        );
    }
}
