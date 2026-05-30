<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCfg\Func as CfgFunc;

/**
 * User class property visibility (issue #145, Zend zend_object_handlers.c zend_check_protected).
 */
final class PropertyVisibility
{
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
                self::deny($visibilityFlags, $declaringClassDisplay, $propertyName);
            }

            return;
        }
        if (($visibilityFlags & CfgFunc::FLAG_PROTECTED) !== 0) {
            if (null === $callerClassLc) {
                self::deny($visibilityFlags, $declaringClassDisplay, $propertyName);
            }
            if (!$isSameOrSubclassOf($callerClassLc, $declaringClassLc)) {
                self::deny($visibilityFlags, $declaringClassDisplay, $propertyName);
            }
            if (
                $objectClassLc !== $callerClassLc
                && !$isSameOrSubclassOf($objectClassLc, $callerClassLc)
            ) {
                self::deny($visibilityFlags, $declaringClassDisplay, $propertyName);
            }
        }
    }

    private static function deny(int $visibilityFlags, string $className, string $propertyName): void
    {
        $kind = ($visibilityFlags & CfgFunc::FLAG_PRIVATE) !== 0 ? 'private' : 'protected';
        throw new \LogicException("Cannot access {$kind} property {$className}::\${$propertyName}");
    }
}
