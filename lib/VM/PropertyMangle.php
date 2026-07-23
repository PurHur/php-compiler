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
