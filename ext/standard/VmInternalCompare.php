<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\VM\Variable;

/**
 * Invoke binary stdlib Internal comparators from other VM builtins (string callbacks).
 */
final class VmInternalCompare
{
    /** @var array<string, class-string<Internal>> */
    private const STRING_CALLBACKS = [
        'strcmp' => strcmp::class,
        'strcasecmp' => strcasecmp::class,
    ];

    public static function resolveStringCallback(string $name): Internal
    {
        $lc = strtolower($name);
        if (!isset(self::STRING_CALLBACKS[$lc])) {
            throw new \LogicException(
                "String compare callback '{$name}' is not supported in this compiler build"
            );
        }

        $class = self::STRING_CALLBACKS[$lc];

        return new $class();
    }

    public static function invoke(Internal $fn, Variable $a, Variable $b): int
    {
        $frame = new Frame($fn, null, null);
        $frame->calledArgs = [$a, $b];
        $out = new Variable();
        $frame->returnVar = $out;
        $fn->execute($frame);

        return $out->resolveIndirect()->toInt();
    }
}
