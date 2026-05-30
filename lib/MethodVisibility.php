<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCfg\Func as CfgFunc;

/**
 * User class method visibility (issue #588).
 */
final class MethodVisibility
{
    public static function mask(int $funcFlags): int
    {
        $vis = $funcFlags & (CfgFunc::FLAG_PUBLIC | CfgFunc::FLAG_PROTECTED | CfgFunc::FLAG_PRIVATE);

        return $vis !== 0 ? $vis : CfgFunc::FLAG_PUBLIC;
    }

    public static function isPublic(int $visibilityFlags): bool
    {
        return ($visibilityFlags & CfgFunc::FLAG_PRIVATE) === 0
            && ($visibilityFlags & CfgFunc::FLAG_PROTECTED) === 0;
    }

    /**
     * @throws \LogicException when the call is not allowed
     */
    public static function assertCallable(
        int $visibilityFlags,
        ?string $callerClassLc,
        string $declaringClassLc,
        string $declaringClassDisplay,
        string $methodName,
        bool $parentScopeAllows = false
    ): void {
        if (self::isPublic($visibilityFlags)) {
            return;
        }
        if ($parentScopeAllows) {
            return;
        }
        if ($callerClassLc === null) {
            self::deny($visibilityFlags, $declaringClassDisplay, $methodName);
        }
        if (($visibilityFlags & CfgFunc::FLAG_PRIVATE) !== 0) {
            if ($callerClassLc !== $declaringClassLc) {
                self::deny($visibilityFlags, $declaringClassDisplay, $methodName);
            }

            return;
        }
        if (($visibilityFlags & CfgFunc::FLAG_PROTECTED) !== 0) {
            if ($callerClassLc !== $declaringClassLc) {
                self::deny($visibilityFlags, $declaringClassDisplay, $methodName);
            }
        }
    }

    /**
     * Compile-time JIT guard: true when a runtime visibility check must be emitted.
     */
    public static function needsRuntimeCheck(
        int $visibilityFlags,
        ?string $callerClassLc,
        string $declaringClassLc,
        bool $parentScopeAllows = false
    ): bool {
        if (self::isPublic($visibilityFlags)) {
            return false;
        }
        if ($parentScopeAllows) {
            return false;
        }
        if ($callerClassLc === null) {
            return true;
        }

        return $callerClassLc !== $declaringClassLc;
    }

    /**
     * parent:: dispatch from a subclass (Zend scope resolution, issue #3453).
     */
    public static function parentScopeAllows(
        int $visibilityFlags,
        ?string $callerClassLc,
        string $resolvedParentLc,
        string $methodDeclaringClassLc,
        callable $isSameOrSubclassOf
    ): bool {
        if (null === $callerClassLc) {
            return false;
        }
        if (($visibilityFlags & CfgFunc::FLAG_PRIVATE) !== 0) {
            return $methodDeclaringClassLc === $resolvedParentLc;
        }
        if (($visibilityFlags & CfgFunc::FLAG_PROTECTED) !== 0) {
            return $isSameOrSubclassOf($callerClassLc, $methodDeclaringClassLc);
        }

        return true;
    }

    private static function deny(int $visibilityFlags, string $className, string $methodName): void
    {
        $kind = ($visibilityFlags & CfgFunc::FLAG_PRIVATE) !== 0 ? 'private' : 'protected';
        throw new \LogicException("Call to {$kind} method {$className}::{$methodName}()");
    }
}
