<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\Variable;

/** usort/uasort/uksort null callback → TypeError (ext/standard/array.c; #10624). */
final class VmArraySortCallback
{
    public static function requireCallback(Variable $callback, string $function): void
    {
        if (Variable::TYPE_NULL === $callback->resolveIndirect()->type) {
            throw new \TypeError(
                $function.'(): Argument #2 ($callback) must be a valid callback, no array or string given'
            );
        }
    }
}
