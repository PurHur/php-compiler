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
 * get_class_vars() — default values for public properties declared on a class (issue #3159).
 *
 * php-src: ext/standard/class.c — PHP_FUNCTION(get_class_vars)
 */
final class get_class_vars_ extends Internal
{
    public function __construct()
    {
        parent::__construct('get_class_vars');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('get_class_vars() requires exactly one argument in this compiler build');
        }
        $className = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'get_class_vars', 0, 'class');
        if (null === $frame->returnVar) {
            return;
        }
        $ctx = VmReflection::requireContext($frame);
        $entry = VmReflection::fetchClassEntryForGetClassVars($ctx, $className);
        $frame->returnVar->copyFrom(VmReflection::getClassVarsArray($entry));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('get_class_vars() requires exactly one argument in this compiler build');
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
