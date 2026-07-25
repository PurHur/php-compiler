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

    public static function effectiveGetVisibility(int $writeVisibility, int $getVisibility): int
    {
        return 0 !== $getVisibility ? $getVisibility : $writeVisibility;
    }

    /**
     * php-src zend_API.c — `private(set)` properties are implicitly final (#23068).
     * Uses the asymmetric set-visibility flag (not effective write = private).
     */
    public static function isImplicitlyFinalFromPrivateSet(int $setVisibilityFlags): bool
    {
        return ($setVisibilityFlags & CfgFunc::FLAG_PRIVATE) !== 0;
    }

    /**
     * Parent private slots are not visible by plain name from a child method scope (zend_fetch_property).
     */
    public static function isParentPrivatePropertyInvisibleFromChildScope(
        int $visibilityFlags,
        ?string $callerClassLc,
        string $declaringClassLc,
        callable $isSubclassOf,
        int $storedGetVisibility = 0
    ): bool {
        $readVis = self::effectiveGetVisibility($visibilityFlags, $storedGetVisibility);
        if (($readVis & CfgFunc::FLAG_PRIVATE) === 0) {
            return false;
        }
        if (null === $callerClassLc || $callerClassLc === $declaringClassLc) {
            return false;
        }

        return $isSubclassOf($callerClassLc, $declaringClassLc);
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
        callable $isSameOrSubclassOf,
        int $storedGetVisibility = 0
    ): void {
        $readVis = self::effectiveGetVisibility($visibilityFlags, $storedGetVisibility);
        if (MethodVisibility::isPublic($readVis)) {
            return;
        }
        $asymmetricGet = 0 !== $storedGetVisibility && $storedGetVisibility !== MethodVisibility::mask($visibilityFlags);
        $scopeLabel = null === $callerClassLc ? 'global scope' : $callerClassLc;
        if (($readVis & CfgFunc::FLAG_PRIVATE) !== 0) {
            if ($callerClassLc !== $declaringClassLc) {
                self::denyRead($readVis, $asymmetricGet, $declaringClassDisplay, $propertyName, $scopeLabel);
            }

            return;
        }
        if (($readVis & CfgFunc::FLAG_PROTECTED) !== 0) {
            if (null === $callerClassLc) {
                self::denyRead($readVis, $asymmetricGet, $declaringClassDisplay, $propertyName, $scopeLabel);
            }
            if (!$isSameOrSubclassOf($callerClassLc, $declaringClassLc)) {
                self::denyRead($readVis, $asymmetricGet, $declaringClassDisplay, $propertyName, $scopeLabel);
            }
            if (
                $objectClassLc !== $callerClassLc
                && !$isSameOrSubclassOf($objectClassLc, $callerClassLc)
            ) {
                self::denyRead($readVis, $asymmetricGet, $declaringClassDisplay, $propertyName, $scopeLabel);
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
        callable $isSubclass,
        int $readVisibilityFlags = 0,
        bool $explicitReadModifier = false
    ): void {
        if (MethodVisibility::isPublic($setVisibilityFlags)) {
            return;
        }
        if (null === $callerClassLc) {
            self::denyWrite(
                $setVisibilityFlags,
                $declaringClassDisplay,
                $propertyName,
                'global scope',
                $readVisibilityFlags,
                $explicitReadModifier
            );
        }
        if (($setVisibilityFlags & CfgFunc::FLAG_PRIVATE) !== 0) {
            if ($callerClassLc !== $declaringClassLc) {
                self::denyWrite(
                    $setVisibilityFlags,
                    $declaringClassDisplay,
                    $propertyName,
                    $callerClassLc,
                    $readVisibilityFlags,
                    $explicitReadModifier
                );
            }

            return;
        }
        if (($setVisibilityFlags & CfgFunc::FLAG_PROTECTED) !== 0) {
            if ($callerClassLc === $declaringClassLc || $isSubclass($callerClassLc, $declaringClassLc)) {
                return;
            }
            self::denyWrite(
                $setVisibilityFlags,
                $declaringClassDisplay,
                $propertyName,
                $callerClassLc,
                $readVisibilityFlags,
                $explicitReadModifier
            );
        }
    }

    private static function denyRead(
        int $getVisibilityFlags,
        bool $asymmetricGet,
        string $className,
        string $propertyName,
        string $scopeLabel
    ): void {
        $kind = $asymmetricGet
            ? Ast\AsymmetricVisibilityRewriter::getModifierLabel($getVisibilityFlags)
            : (($getVisibilityFlags & CfgFunc::FLAG_PRIVATE) !== 0 ? 'private' : 'protected');
        if ($asymmetricGet) {
            throw new \LogicException(
                sprintf(
                    'Cannot access %s property %s::$%s from %s',
                    $kind,
                    $className,
                    $propertyName,
                    $scopeLabel
                )
            );
        }
        throw new \LogicException("Cannot access {$kind} property {$className}::\${$propertyName}");
    }

    private static function denyWrite(
        int $setVisibilityFlags,
        string $className,
        string $propertyName,
        string $scopeLabel,
        int $readVisibilityFlags = 0,
        bool $explicitReadModifier = false
    ): void {
        $kind = 0 !== $readVisibilityFlags
            ? Ast\AsymmetricVisibilityRewriter::writeModifierLabel($readVisibilityFlags, $setVisibilityFlags, $explicitReadModifier)
            : Ast\AsymmetricVisibilityRewriter::setModifierLabel($setVisibilityFlags);
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
