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
     * PHP 8.4+ `public readonly` / readonly-class public props get implicit `protected(set)` (#29186).
     *
     * Explicit set visibility (including `public(set)`) wins. Protected/private read visibility
     * already implies the matching set scope — no separate IS_*_SET bit.
     * php-src: Zend/zend_compile.c / asymmetric visibility + readonly interaction.
     */
    public static function withImplicitReadonlyProtectedSet(
        bool $readonly,
        int $readVisibilityFlags,
        int $explicitSetVisibilityFlags
    ): int {
        if (0 !== $explicitSetVisibilityFlags) {
            return $explicitSetVisibilityFlags;
        }
        if (!$readonly || !CompilerVersion::supportsAsymmetricVisibility()) {
            return 0;
        }
        if (!MethodVisibility::isPublic($readVisibilityFlags)) {
            return 0;
        }

        return CfgFunc::FLAG_PROTECTED;
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
     * Parent private slots are not visible by plain name on a *child* receiver (zend_fetch_property).
     *
     * On `$this` / another child instance, Zend treats `->parentPrivate` as undefined (#19005).
     * On another instance of the *declaring* class, the property exists but the child scope
     * cannot access it → Error (zend_std_read_property; #29494).
     */
    public static function isParentPrivatePropertyInvisibleFromChildScope(
        int $visibilityFlags,
        ?string $callerClassLc,
        string $declaringClassLc,
        callable $isSubclassOf,
        int $storedGetVisibility = 0,
        ?string $receiverClassLc = null
    ): bool {
        $readVis = self::effectiveGetVisibility($visibilityFlags, $storedGetVisibility);
        if (($readVis & CfgFunc::FLAG_PRIVATE) === 0) {
            return false;
        }
        if (null === $callerClassLc || $callerClassLc === $declaringClassLc) {
            return false;
        }
        if (!$isSubclassOf($callerClassLc, $declaringClassLc)) {
            return false;
        }
        // Declaring-class receiver: visible slot, inaccessible from child → assertAccessible/Error.
        if (null !== $receiverClassLc && $receiverClassLc === $declaringClassLc) {
            return false;
        }

        return true;
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
            if (
                !$isSameOrSubclassOf($callerClassLc, $declaringClassLc)
                && !$isSameOrSubclassOf($declaringClassLc, $callerClassLc)
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
        bool $explicitReadModifier = false,
        ?string $callerClassDisplay = null,
        bool $readonlyProperty = false
    ): void {
        self::assertSetVisibility(
            'modify',
            $setVisibilityFlags,
            $callerClassLc,
            $declaringClassLc,
            $declaringClassDisplay,
            $propertyName,
            $isSubclass,
            $readVisibilityFlags,
            $explicitReadModifier,
            $callerClassDisplay,
            $readonlyProperty
        );
    }

    /**
     * Unset follows set-visibility (php-src zend_object_handlers.c, #23338).
     *
     * @throws \LogicException when unset is not allowed
     */
    public static function assertUnsettable(
        int $setVisibilityFlags,
        ?string $callerClassLc,
        string $declaringClassLc,
        string $declaringClassDisplay,
        string $propertyName,
        callable $isSubclass,
        int $readVisibilityFlags = 0,
        bool $explicitReadModifier = false,
        ?string $callerClassDisplay = null
    ): void {
        self::assertSetVisibility(
            'unset',
            $setVisibilityFlags,
            $callerClassLc,
            $declaringClassLc,
            $declaringClassDisplay,
            $propertyName,
            $isSubclass,
            $readVisibilityFlags,
            $explicitReadModifier,
            $callerClassDisplay,
            false
        );
    }

    /**
     * Shared set-visibility gate for assign (`modify`) and unset (`unset`).
     *
     * php-src zend_execute.c zend_asymmetric_visibility_property_modification_error —
     * class scopes use `from scope {Name}` with original casing (#26298).
     * Clone-with reinit of readonly props uses `protected(set) readonly` wording (#29186).
     *
     * @throws \LogicException when the operation is not allowed
     */
    private static function assertSetVisibility(
        string $verb,
        int $setVisibilityFlags,
        ?string $callerClassLc,
        string $declaringClassLc,
        string $declaringClassDisplay,
        string $propertyName,
        callable $isSubclass,
        int $readVisibilityFlags = 0,
        bool $explicitReadModifier = false,
        ?string $callerClassDisplay = null,
        bool $readonlyProperty = false
    ): void {
        if (MethodVisibility::isPublic($setVisibilityFlags)) {
            return;
        }
        if (null === $callerClassLc) {
            self::denySetVisibility(
                $verb,
                $setVisibilityFlags,
                $declaringClassDisplay,
                $propertyName,
                'global scope',
                $readVisibilityFlags,
                $explicitReadModifier,
                $readonlyProperty
            );
        }
        $scopeLabel = 'scope '.($callerClassDisplay ?? $callerClassLc);
        if (($setVisibilityFlags & CfgFunc::FLAG_PRIVATE) !== 0) {
            if ($callerClassLc !== $declaringClassLc) {
                self::denySetVisibility(
                    $verb,
                    $setVisibilityFlags,
                    $declaringClassDisplay,
                    $propertyName,
                    $scopeLabel,
                    $readVisibilityFlags,
                    $explicitReadModifier,
                    $readonlyProperty
                );
            }

            return;
        }
        if (($setVisibilityFlags & CfgFunc::FLAG_PROTECTED) !== 0) {
            if ($callerClassLc === $declaringClassLc || $isSubclass($callerClassLc, $declaringClassLc)) {
                return;
            }
            self::denySetVisibility(
                $verb,
                $setVisibilityFlags,
                $declaringClassDisplay,
                $propertyName,
                $scopeLabel,
                $readVisibilityFlags,
                $explicitReadModifier,
                $readonlyProperty
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

    private static function denySetVisibility(
        string $verb,
        int $setVisibilityFlags,
        string $className,
        string $propertyName,
        string $scopeLabel,
        int $readVisibilityFlags = 0,
        bool $explicitReadModifier = false,
        bool $readonlyProperty = false
    ): void {
        $kind = 0 !== $readVisibilityFlags
            ? Ast\AsymmetricVisibilityRewriter::writeModifierLabel($readVisibilityFlags, $setVisibilityFlags, $explicitReadModifier)
            : Ast\AsymmetricVisibilityRewriter::setModifierLabel($setVisibilityFlags);
        // php-src: only protected(set)+readonly reinit denies insert "readonly" (#29186);
        // private(set) readonly keeps bare private(set) wording.
        if ($readonlyProperty && ($setVisibilityFlags & CfgFunc::FLAG_PROTECTED) !== 0) {
            $kind .= ' readonly';
        }
        throw new \LogicException(
            sprintf(
                'Cannot %s %s property %s::$%s from %s',
                $verb,
                $kind,
                $className,
                $propertyName,
                $scopeLabel
            )
        );
    }
}
