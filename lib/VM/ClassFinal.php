<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Final class modifier from php-cfg Class_::flags (issue #18297).
 *
 * @see \PhpParser\Node\Stmt\Class_::MODIFIER_FINAL
 */
final class ClassFinal
{
    /** @see \PhpParser\Node\Stmt\Class_::MODIFIER_FINAL */
    public const MODIFIER_FINAL = 32;

    public static function fromClassFlags(int $flags): bool
    {
        return 0 !== ($flags & self::MODIFIER_FINAL);
    }
}
