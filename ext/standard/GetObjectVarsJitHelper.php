<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;
use PHPCompiler\Web\Superglobals;

/**
 * get_object_vars() / get_mangled_object_vars() for compiled JIT/AOT modules (#16629, php-in-PHP).
 *
 * SSOT: {@see VmReflection::getObjectVars()}, {@see VmReflection::getMangledObjectVars()}
 * php-src: ext/standard/var.c — PHP_FUNCTION(get_object_vars)
 */
final class GetObjectVarsJitHelper
{
    public static function objectVarsArgv(Variable $object, int $mangled): Variable
    {
        $frame = self::requireExecutingFrame();

        return 0 !== $mangled
            ? VmReflection::getMangledObjectVars($object, $frame)
            : VmReflection::getObjectVars($object, $frame);
    }

    private static function requireExecutingFrame(): Frame
    {
        $ctx = Superglobals::getActiveContext();
        if (null === $ctx) {
            throw new \LogicException(
                'GetObjectVarsJitHelper::objectVarsArgv() requires an active VM context in this compiler build'
            );
        }
        $vm = $ctx->runtime->vm;
        if (null === $vm) {
            throw new \LogicException(
                'GetObjectVarsJitHelper::objectVarsArgv() requires an active VM in this compiler build'
            );
        }
        $frame = $vm->currentExecutingFrame();
        if (null === $frame) {
            throw new \LogicException(
                'GetObjectVarsJitHelper::objectVarsArgv() requires an active executing frame in this compiler build'
            );
        }

        return $frame;
    }
}
