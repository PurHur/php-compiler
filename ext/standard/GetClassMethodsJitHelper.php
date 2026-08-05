<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\Variable;
use PHPCompiler\VM\VmExecutingFrame;

/**
 * get_class_methods() for compiled JIT/AOT modules (#16729, #23530, php-in-PHP).
 *
 * SSOT: {@see VmReflection::resolveClassForGetClassMethods()}, {@see VmReflection::classMethodsArray()}
 * php-src: Zend/zend_builtin_functions.c — PHP_FUNCTION(get_class_methods)
 *
 * Frame lookup via {@see VmExecutingFrame} so NestedJIT resolves the caller scope (#23530).
 */
final class GetClassMethodsJitHelper
{
    public static function methodsArgv(Variable $objectOrClass): Variable
    {
        $frame = VmExecutingFrame::requireFromActiveContext();
        $ctx = VmReflection::requireContext($frame);

        // php-src zend_builtin_functions.c — “object or a valid class name”, not bare object|string (#27706)
        VmClassHas::requireObjectOrValidClassName($objectOrClass, 'get_class_methods');
        $entry = VmReflection::requireClassForGetClassMethods($ctx, $objectOrClass);

        return VmReflection::classMethodsArray($entry, VmReflection::METHOD_FILTER_DEFAULT, $ctx, $frame);
    }
}
