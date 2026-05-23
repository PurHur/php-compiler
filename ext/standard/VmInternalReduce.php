<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\VM\Variable;

/**
 * Invoke binary stdlib Internal reducers from array_reduce() (string callbacks).
 */
final class VmInternalReduce
{
    /** @var array<string, class-string<Internal>> */
    private const STRING_CALLBACKS = [
        'pow' => pow::class,
        'hypot' => hypot::class,
        'fmod' => fmod::class,
        'atan2' => atan2::class,
    ];

    public static function resolveStringCallback(string $name): Internal
    {
        $lc = strtolower($name);
        if (!isset(self::STRING_CALLBACKS[$lc])) {
            throw new \LogicException(
                "String reduce callback '{$name}' is not supported in this compiler build"
            );
        }

        $class = self::STRING_CALLBACKS[$lc];

        return new $class();
    }

    public static function invoke(Internal $fn, Variable $carry, Variable $item): Variable
    {
        $frame = new Frame($fn, null, null);
        $frame->calledArgs = [$carry, $item];
        $out = new Variable();
        $frame->returnVar = $out;
        $fn->execute($frame);

        return $out->resolveIndirect();
    }
}
