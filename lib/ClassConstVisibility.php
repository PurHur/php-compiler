<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCfg\Func as CfgFunc;

/**
 * User class constant visibility (issue #4651, Zend zend_constants.c).
 */
final class ClassConstVisibility
{
    public static function mask(int $flags): int
    {
        $vis = $flags & (CfgFunc::FLAG_PUBLIC | CfgFunc::FLAG_PROTECTED | CfgFunc::FLAG_PRIVATE);

        return $vis !== 0 ? $vis : CfgFunc::FLAG_PUBLIC;
    }

    /**
     * @throws \LogicException when access is not allowed
     */
    public static function assertAccessible(
        int $visibilityFlags,
        ?string $callerClassLc,
        string $declaringClassLc,
        string $declaringClassDisplay,
        string $constName,
        callable $isSameOrSubclassOf
    ): void {
        if (MethodVisibility::isPublic($visibilityFlags)) {
            return;
        }
        if (($visibilityFlags & CfgFunc::FLAG_PRIVATE) !== 0) {
            if ($callerClassLc !== $declaringClassLc) {
                throw new \LogicException(
                    "Cannot access private constant {$declaringClassDisplay}::{$constName}"
                );
            }

            return;
        }
        if (($visibilityFlags & CfgFunc::FLAG_PROTECTED) !== 0) {
            if (null === $callerClassLc) {
                throw new \LogicException(
                    "Cannot access protected constant {$declaringClassDisplay}::{$constName}"
                );
            }
            if (!$isSameOrSubclassOf($callerClassLc, $declaringClassLc)) {
                throw new \LogicException(
                    "Cannot access protected constant {$declaringClassDisplay}::{$constName}"
                );
            }
        }
    }
}
