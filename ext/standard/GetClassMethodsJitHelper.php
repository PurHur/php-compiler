<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\Variable;
use PHPCompiler\Web\Superglobals;

/**
 * get_class_methods() for compiled JIT/AOT modules (#16729, php-in-PHP).
 *
 * SSOT: {@see VmReflection::resolveClassForGetClassMethods()}, {@see VmReflection::classMethodsArray()}
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(get_class_methods)
 */
final class GetClassMethodsJitHelper
{
    public static function methodsArgv(Variable $objectOrClass): Variable
    {
        $ctx = Superglobals::getActiveContext();
        if (null === $ctx) {
            throw new \LogicException(
                'GetClassMethodsJitHelper::methodsArgv() requires an active VM context in this compiler build'
            );
        }

        VmClassHas::requireObjectOrClass($objectOrClass, 'get_class_methods', 'object_or_class');
        $entry = VmReflection::requireClassForGetClassMethods($ctx, $objectOrClass);

        return VmReflection::classMethodsArray($entry, VmReflection::METHOD_FILTER_DEFAULT, $ctx);
    }
}
