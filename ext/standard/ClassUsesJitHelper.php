<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\Variable;
use PHPCompiler\Web\Superglobals;

/**
 * class_uses() for compiled JIT/AOT modules (#16501, php-in-PHP).
 *
 * SSOT: {@see VmReflection::resolveClassForClassUses()}, {@see VmReflection::classUsesArray()}
 * php-src: ext/standard/spl_functions.c — PHP_FUNCTION(class_uses)
 */
final class ClassUsesJitHelper
{
    public static function usesArgv(Variable $objectOrClass, bool $autoload): Variable
    {
        $ctx = Superglobals::getActiveContext();
        if (null === $ctx) {
            throw new \LogicException(
                'ClassUsesJitHelper::usesArgv() requires an active VM context in this compiler build'
            );
        }

        $entry = VmReflection::resolveClassForClassUses($ctx, $objectOrClass, $autoload);
        if (null === $entry) {
            $result = new Variable();
            $result->bool(false);

            return $result;
        }

        return VmReflection::classUsesArray($entry);
    }
}
