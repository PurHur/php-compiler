<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\Variable;
use PHPCompiler\Web\Superglobals;

/**
 * class_uses_recursive() for compiled JIT/AOT modules (#16773, php-in-PHP).
 *
 * SSOT: {@see VmReflection::resolveClassForClassUses()}, {@see VmReflection::classUsesRecursiveArray()}
 * php-src: ext/standard/class.c — PHP_FUNCTION(class_uses_recursive)
 */
final class ClassUsesRecursiveJitHelper
{
    public static function usesArgv(Variable $objectOrClass, bool $autoload): Variable
    {
        VmClassHas::requireObjectOrClass($objectOrClass, 'class_uses_recursive', 'object_or_class');
        $ctx = Superglobals::getActiveContext();
        if (null === $ctx) {
            throw new \LogicException(
                'ClassUsesRecursiveJitHelper::usesArgv() requires an active VM context in this compiler build'
            );
        }

        $entry = VmReflection::resolveClassForClassUses($ctx, $objectOrClass, $autoload);
        if (null === $entry) {
            $result = new Variable();
            $result->bool(false);

            return $result;
        }

        return VmReflection::classUsesRecursiveArray($entry, $ctx);
    }
}
