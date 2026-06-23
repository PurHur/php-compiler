<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Shared empty() semantic guards for VM + JIT lowering (#10268, #10170).
 *
 * php-src: Zend/zend_object_handlers.c — empty on typed/uninitialized slots (#6787)
 */
final class VmEmpty
{
    /** Uninitialized typed property slot counts as empty without a read guard (#6787). */
    public static function uninitializedSlotCountsAsEmpty(int $typeByte): bool
    {
        return Variable::TYPE_UNDEFINED === $typeByte;
    }
}
