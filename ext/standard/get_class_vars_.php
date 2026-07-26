<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * get_class_vars() — default values for properties visible from the calling scope (#3159, #23531).
 *
 * php-src: Zend/zend_builtin_functions.c — PHP_FUNCTION(get_class_vars) / add_class_vars
 */
final class get_class_vars_ extends Internal
{
    public function __construct()
    {
        parent::__construct('get_class_vars');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'get_class_vars() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $className = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'get_class_vars', 0, 'class');
        if (null === $frame->returnVar) {
            return;
        }
        $ctx = VmReflection::requireContext($frame);
        $entry = VmReflection::fetchClassEntryForGetClassVars($ctx, $className);
        $frame->returnVar->copyFrom(VmReflection::getClassVarsArray($entry, $frame));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'get_class_vars() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        $literal = JitStringArg::compileTimeLiteral($args[0]);
        if (null !== $literal) {
            return JitGetClassVars::invoke($context, $args[0]);
        }
        JitStringBuiltinArg::lower($context, $args[0], 'get_class_vars', 0, 'class');
        throw new \LogicException(
            'get_class_vars() class must be a string literal in this compiler build'
        );
    }
}
