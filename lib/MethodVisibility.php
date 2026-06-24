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
        bool $parentScopeAllows = false,
        ?callable $isSameOrSubclassOf = null,
        ?string $callerClassDisplay = null
    ): void {
        self::assertCallableInternal(
            $visibilityFlags,
            $callerClassLc,
            $declaringClassLc,
            $declaringClassDisplay,
            $methodName,
            $parentScopeAllows,
            $isSameOrSubclassOf,
            $callerClassDisplay,
            false
        );
    }

    /**
     * Object clone: Zend uses "Call to private Class::__clone()" wording (#7352).
     *
     * @throws \LogicException when the call is not allowed
     */
    public static function assertCloneCallable(
        int $visibilityFlags,
        ?string $callerClassLc,
        string $declaringClassLc,
        string $declaringClassDisplay,
        bool $parentScopeAllows = false,
        ?callable $isSameOrSubclassOf = null,
        ?string $callerClassDisplay = null
    ): void {
        self::assertCallableInternal(
            $visibilityFlags,
            $callerClassLc,
            $declaringClassLc,
            $declaringClassDisplay,
            '__clone',
            $parentScopeAllows,
            $isSameOrSubclassOf,
            $callerClassDisplay,
            true
        );
    }

    /**
     * Object construction: Zend uses "Call to private Class::__construct()" wording (#5382).
     *
     * @throws \LogicException when the call is not allowed
     */
    public static function assertConstructorCallable(
        int $visibilityFlags,
        ?string $callerClassLc,
        string $declaringClassLc,
        string $declaringClassDisplay,
        bool $parentScopeAllows = false,
        ?callable $isSameOrSubclassOf = null,
        ?string $callerClassDisplay = null
    ): void {
        self::assertCallableInternal(
            $visibilityFlags,
            $callerClassLc,
            $declaringClassLc,
            $declaringClassDisplay,
            '__construct',
            $parentScopeAllows,
            $isSameOrSubclassOf,
            $callerClassDisplay,
            true
        );
    }

    private static function assertCallableInternal(
        int $visibilityFlags,
        ?string $callerClassLc,
        string $declaringClassLc,
        string $declaringClassDisplay,
        string $methodName,
        bool $parentScopeAllows,
        ?callable $isSameOrSubclassOf,
        ?string $callerClassDisplay,
        bool $constructorMessage
    ): void {
        if (self::isPublic($visibilityFlags)) {
            return;
        }
        if ($parentScopeAllows) {
            return;
        }
        if ($callerClassLc === null) {
            self::deny(
                $visibilityFlags,
                $declaringClassDisplay,
                $methodName,
                $callerClassDisplay,
                $constructorMessage
            );

            return;
        }
        if (($visibilityFlags & CfgFunc::FLAG_PRIVATE) !== 0) {
            if ($callerClassLc !== $declaringClassLc) {
                self::deny(
                    $visibilityFlags,
                    $declaringClassDisplay,
                    $methodName,
                    $callerClassDisplay ?? $callerClassLc,
                    $constructorMessage
                );
            }

            return;
        }
        if (($visibilityFlags & CfgFunc::FLAG_PROTECTED) !== 0) {
            if ($callerClassLc === $declaringClassLc) {
                return;
            }
            if (null !== $isSameOrSubclassOf && $isSameOrSubclassOf($callerClassLc, $declaringClassLc)) {
                return;
            }
            self::deny(
                $visibilityFlags,
                $declaringClassDisplay,
                $methodName,
                $callerClassDisplay ?? $callerClassLc,
                $constructorMessage
            );
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
            // php-src: private parent:: requires calling scope === declaring class (#6799).
            return $callerClassLc === $methodDeclaringClassLc;
        }
        if (($visibilityFlags & CfgFunc::FLAG_PROTECTED) !== 0) {
            return $isSameOrSubclassOf($callerClassLc, $methodDeclaringClassLc);
        }

        return true;
    }

    private static function deny(
        int $visibilityFlags,
        string $className,
        string $methodName,
        ?string $fromScope,
        bool $constructorMessage = false
    ): void {
        $kind = ($visibilityFlags & CfgFunc::FLAG_PRIVATE) !== 0 ? 'private' : 'protected';
        if ($constructorMessage) {
            if (null === $fromScope) {
                throw new \LogicException("Call to {$kind} {$className}::{$methodName}() from global scope");
            }
            throw new \LogicException("Call to {$kind} {$className}::{$methodName}() from scope {$fromScope}");
        }
        if (null === $fromScope) {
            throw new \LogicException("Call to {$kind} method {$className}::{$methodName}() from global scope");
        }
        throw new \LogicException("Call to {$kind} method {$className}::{$methodName}() from scope {$fromScope}");
    }
}
