<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCfg\Func as CfgFunc;

/**
 * User class property visibility (issue #145) and PHP 8.4 asymmetric set visibility (#3165).
 */
final class PropertyVisibility
{
    public static function effectiveSetVisibility(int $readVisibility, int $setVisibility): int
    {
        return 0 !== $setVisibility ? $setVisibility : $readVisibility;
    }

    /**
     * @throws \LogicException when access is not allowed
     */
    public static function assertAccessible(
        int $visibilityFlags,
        ?string $callerClassLc,
        string $declaringClassLc,
        string $declaringClassDisplay,
        string $propertyName,
        string $objectClassLc,
        callable $isSameOrSubclassOf
    ): void {
        if (MethodVisibility::isPublic($visibilityFlags)) {
            return;
        }
        if (($visibilityFlags & CfgFunc::FLAG_PRIVATE) !== 0) {
            if ($callerClassLc !== $declaringClassLc) {
                self::denyAccess($visibilityFlags, $declaringClassDisplay, $propertyName);
            }

            return;
        }
        if (($visibilityFlags & CfgFunc::FLAG_PROTECTED) !== 0) {
            if (null === $callerClassLc) {
                self::denyAccess($visibilityFlags, $declaringClassDisplay, $propertyName);
            }
            if (!$isSameOrSubclassOf($callerClassLc, $declaringClassLc)) {
                self::denyAccess($visibilityFlags, $declaringClassDisplay, $propertyName);
            }
            if (
                $objectClassLc !== $callerClassLc
                && !$isSameOrSubclassOf($objectClassLc, $callerClassLc)
            ) {
                self::denyAccess($visibilityFlags, $declaringClassDisplay, $propertyName);
            }
        }
    }

    /**
     * @throws \LogicException when the write is not allowed
     */
    public static function assertWritable(
        int $setVisibilityFlags,
        ?string $callerClassLc,
        string $declaringClassLc,
        string $declaringClassDisplay,
        string $propertyName,
        callable $isSubclass
    ): void {
        if (MethodVisibility::isPublic($setVisibilityFlags)) {
            return;
        }
        if (null === $callerClassLc) {
            self::denyWrite($setVisibilityFlags, $declaringClassDisplay, $propertyName, 'global scope');
        }
        if (($setVisibilityFlags & CfgFunc::FLAG_PRIVATE) !== 0) {
            if ($callerClassLc !== $declaringClassLc) {
                self::denyWrite($setVisibilityFlags, $declaringClassDisplay, $propertyName, $callerClassLc);
            }

            return;
        }
        if (($setVisibilityFlags & CfgFunc::FLAG_PROTECTED) !== 0) {
            if ($callerClassLc === $declaringClassLc || $isSubclass($callerClassLc, $declaringClassLc)) {
                return;
            }
            self::denyWrite($setVisibilityFlags, $declaringClassDisplay, $propertyName, $callerClassLc);
        }
    }

    private static function denyAccess(int $visibilityFlags, string $className, string $propertyName): void
    {
        $kind = ($visibilityFlags & CfgFunc::FLAG_PRIVATE) !== 0 ? 'private' : 'protected';
        throw new \LogicException("Cannot access {$kind} property {$className}::\${$propertyName}");
    }

    private static function denyWrite(
        int $setVisibilityFlags,
        string $className,
        string $propertyName,
        string $scopeLabel
    ): void {
        $kind = Ast\AsymmetricVisibilityRewriter::setModifierLabel($setVisibilityFlags);
        throw new \LogicException(
            sprintf(
                'Cannot modify %s property %s::$%s from %s',
                $kind,
                $className,
                $propertyName,
                $scopeLabel
            )
        );
    }
}
