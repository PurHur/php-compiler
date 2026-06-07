<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Static class modifier from php-cfg Class_::flags (issue #6929, PHP 8.4).
 *
 * @see \PhpParser\Node\Stmt\Class_::MODIFIER_STATIC
 */
final class ClassStatic
{
    /** @see \PhpParser\Node\Stmt\Class_::MODIFIER_STATIC */
    public const MODIFIER_STATIC = 8;

    public static function fromClassFlags(int $flags): bool
    {
        return 0 !== ($flags & self::MODIFIER_STATIC);
    }
}
