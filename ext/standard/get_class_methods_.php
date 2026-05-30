<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * get_class_methods() — method names for a class or object (issue #3118).
 *
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(get_class_methods)
 */
final class get_class_methods_ extends Internal
{
    public function __construct()
    {
        parent::__construct('get_class_methods');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('get_class_methods() requires one or two arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $ctx = VmReflection::requireContext($frame);
        $filter = VmReflection::METHOD_FILTER_DEFAULT;
        if ($argc >= 2) {
            $filterVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $filterVar->type) {
                throw new \LogicException('get_class_methods() filter must be an integer in this compiler build');
            }
            $filter = $filterVar->toInt();
        }
        $entry = VmReflection::resolveClassForGetClassMethods($ctx, $frame->calledArgs[0]);
        if (null === $entry) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->copyFrom(VmReflection::classMethodsArray($entry, $filter));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 1 || \count($args) > 2) {
            throw new \LogicException('get_class_methods() requires one or two arguments in this compiler build');
        }
        $filter = VmReflection::METHOD_FILTER_DEFAULT;
        if (\count($args) >= 2) {
            if (JITVariable::TYPE_NATIVE_LONG !== $args[1]->type) {
                throw new \LogicException('get_class_methods() filter must be a compile-time integer in this compiler build');
            }
            $filterConst = $args[1]->value;
            if ($filterConst instanceof \PHPLLVM\Value\ConstantInt) {
                $filter = $filterConst->getValue();
            }
        }

        return JitGetClassMethods::invoke($context, $args[0], $filter);
    }
}
