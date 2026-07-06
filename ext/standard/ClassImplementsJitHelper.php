<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\Variable;
use PHPCompiler\Web\Superglobals;

/**
 * class_implements() for compiled JIT/AOT modules (#16960, php-in-PHP).
 *
 * SSOT: {@see VmReflection::resolveClassForClassImplements()}, {@see VmReflection::classImplementsArray()}
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(class_implements)
 */
final class ClassImplementsJitHelper
{
    public static function implementsArgv(Variable $objectOrClass, bool $autoload): Variable
    {
        $ctx = Superglobals::getActiveContext();
        if (null === $ctx) {
            throw new \LogicException(
                'ClassImplementsJitHelper::implementsArgv() requires an active VM context in this compiler build'
            );
        }

        $entry = VmReflection::resolveClassForClassImplements($ctx, $objectOrClass, $autoload);
        if (null === $entry) {
            $result = new Variable();
            $result->bool(false);

            return $result;
        }

        return VmReflection::classImplementsArray($entry, $ctx);
    }
}
