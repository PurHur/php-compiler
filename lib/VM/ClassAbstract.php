<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Abstract class modifier from php-cfg Class_::flags (issue #3385).
 *
 * php-parser uses MODIFIER_ABSTRACT (16) on Stmt\Class_; Zend uses ZEND_ACC_ABSTRACT internally.
 */
final class ClassAbstract
{
    /** @see \PhpParser\Node\Stmt\Class_::MODIFIER_ABSTRACT */
    public const MODIFIER_ABSTRACT = 16;

    public static function fromClassFlags(int $flags): bool
    {
        return 0 !== ($flags & self::MODIFIER_ABSTRACT);
    }
}
