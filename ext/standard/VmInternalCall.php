<?php

declare(strict_types=1);

/**
 * This file is part of PHP-Compiler, a PHP CFG Compiler for PHP code
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\VM\Variable;

/**
 * Invoke stdlib Internal handlers from other VM builtins (string callbacks).
 */
final class VmInternalCall
{
    /** @var array<string, class-string<Internal>> */
    private const STRING_CALLBACKS = [
        'strval' => strval::class,
        'intval' => intval::class,
        'floatval' => floatval::class,
        'doubleval' => doubleval::class,
        'boolval' => boolval::class,
        'strtolower' => strtolower::class,
        'strtoupper' => strtoupper::class,
        'trim' => string_trim::class,
        'ltrim' => string_ltrim::class,
        'rtrim' => string_rtrim::class,
        'strlen' => \PHPCompiler\ext\types\strlen::class,
    ];

    public static function resolveStringCallback(string $name): Internal
    {
        $lc = strtolower($name);
        if (!isset(self::STRING_CALLBACKS[$lc])) {
            throw new \LogicException(
                "String callback '{$name}' is not supported in this compiler build"
            );
        }

        $class = self::STRING_CALLBACKS[$lc];

        return new $class();
    }

    /**
     * @param Variable[] $args
     */
    public static function invoke(Internal $fn, Variable ...$args): Variable
    {
        $frame = new Frame($fn, null, null);
        $frame->calledArgs = $args;
        $out = new Variable();
        $frame->returnVar = $out;
        $fn->execute($frame);

        return $out->resolveIndirect();
    }
}
