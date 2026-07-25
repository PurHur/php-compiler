<?php

declare(strict_types=1);

namespace PHPCompiler\ext\uuid;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;

/**
 * Shared string-arg coercion for uuid_* builtins (#22228).
 */
final class UuidStringArg
{
    public static function require(Frame $frame, int $index, string $function, string $paramName): string
    {
        $argc = \count($frame->calledArgs);
        if ($index >= $argc) {
            throw new \ArgumentCountError(
                $function.'() expects at least '.($index + 1).' argument'.(0 === $index ? '' : 's').', '.$argc.' given'
            );
        }

        return VmString::coerceStringBuiltinArg(
            $frame->calledArgs[$index],
            $function,
            $index,
            $paramName
        );
    }
}
