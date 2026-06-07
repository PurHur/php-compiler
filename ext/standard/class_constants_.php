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
 * class_constants() — class/interface/enum constant map (issue #7309).
 *
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(class_constants)
 */
final class class_constants_ extends Internal
{
    public function __construct()
    {
        parent::__construct('class_constants');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('class_constants() requires exactly one argument in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $ctx = VmReflection::requireContext($frame);
        $className = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'class_constants', 0, 'class');
        $entry = VmReflection::fetchClassEntryForClassConstants($ctx, $className);
        $frame->returnVar->copyFrom(VmReflection::classConstantsArray($ctx, $entry));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('class_constants() requires exactly one argument in this compiler build');
        }
        $literal = JitStringArg::compileTimeLiteral($args[0]);
        if (null !== $literal) {
            return JitClassConstants::invoke($context, $args[0]);
        }
        JitStringBuiltinArg::lower($context, $args[0], 'class_constants', 0, 'class');
        throw new \LogicException(
            'class_constants() class must be a string literal in this compiler build'
        );
    }
}
