<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\MethodVisibility;

/**
 * Zend property hash key mangling (php-src zend_mangle_property_name).
 */
final class PropertyMangle
{
    /**
     * Shadow-map key for an ancestor private when the primary slot holds a more-derived private (#22521).
     */
    public static function shadowedPrivateKey(ClassProperty $meta): string
    {
        $decl = '' !== $meta->declaringClassLc ? $meta->declaringClassLc : '';

        return $decl."\0".$meta->name;
    }

    /**
     * @param array<string, ClassEntry> $classesByLc
     */
    public static function propertyKey(ClassProperty $meta, array $classesByLc = []): string
    {
        if (MethodVisibility::isPublic($meta->visibility)) {
            return $meta->name;
        }
        if (($meta->visibility & \PHPCfg\Func::FLAG_PROTECTED) !== 0) {
            return "\0*\0".$meta->name;
        }

        return "\0".self::declaringClassDisplay($meta, $classesByLc)."\0".$meta->name;
    }

    /**
     * Map a ZEND_PROP_PURPOSE_SERIALIZE hash key to the declared ClassProperty.
     *
     * php-src var_unserializer.c restores via the properties_info hash (mangled keys).
     * Without this, `\0*\0message` allocates a dynamic slot and leaves typed `message` uninit (#26673).
     *
     * @param array<string, ClassEntry> $classesByLc
     */
    public static function findPropertyForSerializeKey(
        ObjectEntry $object,
        string $key,
        array $classesByLc
    ): ?ClassProperty {
        $class = $object->class;
        $seen = [];
        while (null !== $class) {
            $lc = strtolower($class->name);
            if (isset($seen[$lc])) {
                break;
            }
            $seen[$lc] = true;
            foreach ($class->properties as $meta) {
                if (self::propertyKey($meta, $classesByLc) === $key) {
                    return $meta;
                }
            }
            $parentLc = $class->parentLc;
            if (null === $parentLc || '' === $parentLc || !isset($classesByLc[$parentLc])) {
                break;
            }
            $class = $classesByLc[$parentLc];
        }

        return null;
    }

    /**
     * @param array<string, ClassEntry> $classesByLc
     */
    private static function declaringClassDisplay(ClassProperty $meta, array $classesByLc): string
    {
        if ('' !== $meta->declaringClassLc && isset($classesByLc[$meta->declaringClassLc])) {
            return $classesByLc[$meta->declaringClassLc]->name;
        }

        return $meta->declaringClassLc;
    }
}
