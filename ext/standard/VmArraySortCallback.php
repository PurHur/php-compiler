<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\Variable;

/** usort/uasort/uksort and array_u* null callback → TypeError (ext/standard/array.c; #10624, #10799). */
final class VmArraySortCallback
{
    public static function requireCallback(
        Variable $callback,
        string $function,
        int $argNum = 2,
        ?string $paramName = 'callback'
    ): void {
        if (Variable::TYPE_NULL === $callback->resolveIndirect()->type) {
            $paramPart = null !== $paramName ? ' ($'.$paramName.')' : '';
            throw new \TypeError(
                $function.'(): Argument #'.$argNum.$paramPart.' must be a valid callback, no array or string given'
            );
        }
    }
}
