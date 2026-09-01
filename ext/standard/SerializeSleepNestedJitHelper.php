<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\Web\Superglobals;

/**
 * serialize() __sleep() AOT warning helper (#13378).
 *
 * php-src: ext/standard/var.c — php_var_serialize_call_sleep
 */
final class SerializeSleepNestedJitHelper
{
    public static function warnNonArraySleep(string $className): void
    {
        $ctx = Superglobals::getActiveContext();
        if (null === $ctx) {
            throw new \LogicException('serialize() sleep helper requires active VM context (#13378)');
        }
        $ctx->errors->triggerError(
            'serialize(): '.$className.'::__sleep() should return an array only containing the names of instance-variables to serialize',
            ErrorReporter::E_WARNING
        );
    }
}
