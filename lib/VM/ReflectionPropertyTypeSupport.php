<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCfg\Op\Type as CfgType;
use PHPCompiler\Func;

/**
 * ReflectionProperty::{getType,getSettableType} helpers
 * (#4384, #7053, #22481, #28532, ext/reflection/php_reflection.c).
 *
 * readableType() remains an internal helper (hook return type) used by the retired
 * getReadableType builtin class; php-src exposes getType() for the declared ZEND_TYPE.
 */
final class ReflectionPropertyTypeSupport
{
    /**
     * Declared property ZEND_TYPE — ReflectionProperty::getType() (#22481).
     * Must not substitute the get-hook return type (internal readableType() helper).
     */
    public static function declaredType(
        ClassEntry $entry,
        string $property,
        ?ClassProperty $instanceProp,
        Context $ctx
    ): ?CfgType {
        if (null !== $instanceProp) {
            return self::cfgTypeFromVariable($instanceProp->prototype);
        }
        $propLc = strtolower($property);
        $storage = self::findStaticPropertyStorage($entry, $propLc, $ctx);

        return null !== $storage ? self::cfgTypeFromVariable($storage) : null;
    }

    public static function readableType(
        ClassEntry $entry,
        string $property,
        ?ClassProperty $instanceProp,
        Context $ctx
    ): ?CfgType {
        if (null !== $instanceProp) {
            if (null !== $instanceProp->getHookMethodLc) {
                $hookType = self::methodReturnDeclaredType($ctx, $instanceProp->getHookMethodLc, $entry);
                if (null !== $hookType) {
                    return $hookType;
                }
            }

            return self::cfgTypeFromVariable($instanceProp->prototype);
        }

        $propLc = strtolower($property);
        $storage = self::findStaticPropertyStorage($entry, $propLc, $ctx);
        if (null === $storage) {
            return null;
        }
        $hooks = $entry->staticPropertyHooks[$propLc] ?? [];
        if (isset($hooks['get'])) {
            $hookType = self::methodReturnDeclaredType($ctx, $hooks['get'], $entry);
            if (null !== $hookType) {
                return $hookType;
            }
        }

        return self::cfgTypeFromVariable($storage);
    }

    public static function settableType(
        ClassEntry $entry,
        string $property,
        ?ClassProperty $instanceProp,
        Context $ctx
    ): ?CfgType {
        if (null !== $instanceProp) {
            if (self::isVirtualWithoutSetHook($entry, $instanceProp, $ctx)) {
                return new CfgType\Never_();
            }
            if (null !== $instanceProp->setHookMethodLc) {
                $hookType = self::methodFirstParamDeclaredType($ctx, $instanceProp->setHookMethodLc, $entry);
                if (null !== $hookType) {
                    return $hookType;
                }
            }

            return self::cfgTypeFromVariable($instanceProp->prototype);
        }

        $propLc = strtolower($property);
        if (self::isStaticVirtualWithoutSetHook($entry, $propLc, $ctx)) {
            return new CfgType\Never_();
        }
        $hooks = $entry->staticPropertyHooks[$propLc] ?? [];
        if (isset($hooks['set'])) {
            $hookType = self::methodFirstParamDeclaredType($ctx, $hooks['set'], $entry);
            if (null !== $hookType) {
                return $hookType;
            }
        }
        $storage = self::findStaticPropertyStorage($entry, $propLc, $ctx);

        return null !== $storage ? self::cfgTypeFromVariable($storage) : null;
    }

    private static function cfgTypeFromVariable(Variable $prototype): ?CfgType
    {
        $label = $prototype->declaredTypeLabel;
        if (null === $label || '' === $label) {
            return null;
        }

        return ReflectionTypeSupport::cfgTypeFromLabel($label);
    }

    private static function isVirtualWithoutSetHook(
        ClassEntry $entry,
        ClassProperty $prop,
        Context $ctx
    ): bool {
        if (!$prop->propertyHookVirtual) {
            return false;
        }
        if (null !== $prop->setHookMethodLc) {
            return false;
        }
        $lcClass = strtolower($entry->name);
        $propMeta = $ctx->propertyHookRegistry[$lcClass][$prop->name]
            ?? $ctx->propertyHookRegistry[$lcClass][strtolower($prop->name)]
            ?? null;

        return is_array($propMeta) && !empty($propMeta['virtual']) && empty($propMeta['set']);
    }

    private static function isStaticVirtualWithoutSetHook(
        ClassEntry $entry,
        string $propLc,
        Context $ctx
    ): bool {
        $hooks = $entry->staticPropertyHooks[$propLc] ?? [];
        if (isset($hooks['set'])) {
            return false;
        }
        $lcClass = strtolower($entry->name);
        foreach ($ctx->propertyHookRegistry[$lcClass] ?? [] as $prop => $meta) {
            if (strtolower((string) $prop) !== $propLc || !is_array($meta)) {
                continue;
            }

            return !empty($meta['virtual']) && empty($meta['set']);
        }

        return false;
    }

    private static function findStaticPropertyStorage(
        ClassEntry $class,
        string $propLc,
        Context $ctx
    ): ?Variable {
        $current = $class;
        while (true) {
            if (isset($current->staticProperties[$propLc])) {
                return $current->staticProperties[$propLc];
            }
            if (null === $current->parentLc || !isset($ctx->classes[$current->parentLc])) {
                return null;
            }
            $current = $ctx->classes[$current->parentLc];
        }
    }

    private static function methodReturnDeclaredType(Context $ctx, string $methodLc, ClassEntry $entry): ?CfgType
    {
        $func = self::resolveMethodFunc($ctx, $methodLc, $entry);
        if (null === $func) {
            return null;
        }

        return $func->block->returnDeclaredType;
    }

    private static function methodFirstParamDeclaredType(Context $ctx, string $methodLc, ClassEntry $entry): ?CfgType
    {
        $func = self::resolveMethodFunc($ctx, $methodLc, $entry);
        if (null === $func) {
            return null;
        }

        return $func->block->paramDeclaredTypes[0] ?? null;
    }

    private static function resolveMethodFunc(Context $ctx, string $methodLc, ClassEntry $entry): ?Func
    {
        if (isset($entry->methods[$methodLc]) && $entry->methods[$methodLc] instanceof Func) {
            return $entry->methods[$methodLc];
        }
        if (isset($ctx->functions[$methodLc]) && $ctx->functions[$methodLc] instanceof Func) {
            return $ctx->functions[$methodLc];
        }

        return null;
    }
}
