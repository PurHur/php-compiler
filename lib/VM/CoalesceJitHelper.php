<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Lowered into JIT/AOT modules for ?? (null coalescing) branch tests (#10171, php-in-PHP).
 *
 * php-src: Zend/zend_execute.c — ZEND_COALESCE
 * SSOT: {@see VM::TYPE_COALESCE} isset-check + value-box fallback
 */
final class CoalesceJitHelper
{
    /**
     * Take ?? left branch when the check operand is a value box (not ISSET bool).
     */
    public static function takeLeftBranchFromTypeByte(int $typeByte): bool
    {
        return Variable::TYPE_UNDEFINED !== $typeByte && Variable::TYPE_NULL !== $typeByte;
    }
}
