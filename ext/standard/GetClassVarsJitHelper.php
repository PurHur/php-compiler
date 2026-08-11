<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\Variable;
use PHPCompiler\VM\VmExecutingFrame;

/**
 * get_class_vars() for compiled JIT/AOT modules (#16713, #23531, php-in-PHP).
 *
 * SSOT: {@see VmReflection::fetchClassEntryForGetClassVars()}, {@see VmReflection::getClassVarsArray()}
 * php-src: Zend/zend_builtin_functions.c — PHP_FUNCTION(get_class_vars)
 *
 * Frame lookup via {@see VmExecutingFrame} so NestedJIT resolves the caller scope (#23531).
 */
final class GetClassVarsJitHelper
{
    public static function classVarsArgv(Variable $classOperand): Variable
    {
        $frame = VmExecutingFrame::requireFromActiveContext();
        $ctx = VmReflection::requireContext($frame);
        // zend_parse_parameters "C" — null→"" without Z_PARAM_STR DEP (#30060).
        $className = VmString::coerceClassNameParamArg($classOperand, 'get_class_vars', 0, 'class');
        $entry = VmReflection::fetchClassEntryForGetClassVars($ctx, $className);

        return VmReflection::getClassVarsArray($entry, $frame);
    }
}
