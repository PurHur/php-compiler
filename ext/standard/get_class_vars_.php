<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
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
        if (null === $frame->returnVar) {
            return;
        }
        $ctx = VmReflection::requireContext($frame);
        $className = VmReflection::stringArg($frame->calledArgs[0], 'get_class_vars() class name');
        $entry = VmReflection::resolveClassEntry($ctx, $className);
        if (null === $entry || $entry->isInterface || $entry->isTrait || $entry->isEnum) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->copyFrom(VmReflection::getClassVarsArray($entry));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('get_class_vars() requires exactly one argument in this compiler build');
        }
        if (JITVariable::TYPE_STRING !== $args[0]->type && JITVariable::TYPE_VALUE !== $args[0]->type) {
            throw new \LogicException('get_class_vars() class name must be a string in this compiler build');
        }

        return JitGetClassVars::invoke($context, $args[0]);
    }
}
