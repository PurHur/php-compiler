<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Shared propertyIsInitialized() slot guards for VM + JIT lowering (#10186, php-in-PHP).
 *
 * php-src: Zend/zend_object_handlers.c — zend_isset_property / typed property init
 * SSOT: {@see PropertyInit}
 */
final class PropertyIsInitializedJitHelper
{
    /**
     * Value-box slot is initialized when type tag is not TYPE_UNDEFINED (#6513).
     */
    public static function valueBoxSlotIsInitialized(int $typeByte): bool
    {
        return Variable::TYPE_UNDEFINED !== $typeByte;
    }
}
