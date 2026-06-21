<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Lowered into JIT/AOT modules for Enum::cases() list shape (#10395, php-in-PHP).
 *
 * php-src: Zend/zend_enum.c — zend_enum_list_cases
 * SSOT: {@see EnumSupport::casesList()}
 */
final class EnumCasesJitHelper
{
    /**
     * Dense 0..n-1 index for declaration-order position (Zend list_cases parity).
     */
    public static function listIndexForPosition(int $position): int
    {
        return $position;
    }

    /**
     * Iteration bound for cases() — matches {@see EnumSupport::casesList()} case count.
     */
    public static function casesListLength(int $declaredCaseCount): int
    {
        return $declaredCaseCount < 0 ? 0 : $declaredCaseCount;
    }
}
