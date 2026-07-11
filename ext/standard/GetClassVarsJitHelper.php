<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\Variable;
use PHPCompiler\Web\Superglobals;

/**
 * get_class_vars() for compiled JIT/AOT modules (#16713, php-in-PHP).
 *
 * SSOT: {@see VmReflection::fetchClassEntryForGetClassVars()}, {@see VmReflection::getClassVarsArray()}
 * php-src: ext/standard/class.c — PHP_FUNCTION(get_class_vars)
 */
final class GetClassVarsJitHelper
{
    public static function classVarsArgv(Variable $classOperand): Variable
    {
        $ctx = Superglobals::getActiveContext();
        if (null === $ctx) {
            throw new \LogicException(
                'GetClassVarsJitHelper::classVarsArgv() requires an active VM context in this compiler build'
            );
        }

        $className = VmString::coerceStringBuiltinArg($classOperand, 'get_class_vars', 0, 'class');
        $entry = VmReflection::fetchClassEntryForGetClassVars($ctx, $className);

        return VmReflection::getClassVarsArray($entry);
    }
}
