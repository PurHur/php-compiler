<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Readonly class modifier from php-cfg Class_::flags (issue #1360).
 *
 * php-parser uses MODIFIER_READONLY (64) on Stmt\Class_; Zend uses 0x80000 internally.
 */
final class ClassReadonly
{
    /** @see \PhpParser\Node\Stmt\Class_::MODIFIER_READONLY */
    public const MODIFIER_READONLY = 64;

    public static function fromClassFlags(int $flags): bool
    {
        return 0 !== ($flags & self::MODIFIER_READONLY);
    }
}
