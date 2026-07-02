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
use PHPCompiler\VM\Context;
use PHPCompiler\VM\Variable;
use PHPCompiler\Web\Superglobals;

/**
 * Invoke stdlib Internal handlers from other VM builtins (string callbacks).
 */
final class VmInternalCall
{
    public static function resolveStringCallback(string $name): Internal
    {
        $resolved = BuiltinRegistry::resolve($name);
        if (null !== $resolved) {
            return $resolved;
        }

        throw new \LogicException(
            "String callback '{$name}' is not a registered internal function in this compiler build"
        );
    }

    /**
     * @param Variable[] $args
     */
    public static function invoke(Internal $fn, Variable ...$args): Variable
    {
        $ctx = Superglobals::getActiveContext();
        if (null === $ctx) {
            throw new \LogicException(
                'VmInternalCall::invoke() requires an active VM context in this compiler build'
            );
        }

        return self::invokeInContext($ctx, $fn, ...$args);
    }

    /**
     * @param Variable[] $args
     */
    public static function invokeInContext(Context $ctx, Internal $fn, Variable ...$args): Variable
    {
        $frame = new Frame($fn, null, null);
        $frame->vmContext = $ctx;
        $frame->calledArgs = $args;
        $out = new Variable();
        $frame->returnVar = $out;
        $fn->execute($frame);

        return $out->resolveIndirect();
    }
}
