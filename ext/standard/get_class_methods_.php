<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
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
        if (1 !== $argc) {
            throw new \ArgumentCountError(
                \sprintf('get_class_methods() expects exactly 1 argument, %d given', $argc)
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $ctx = VmReflection::requireContext($frame);
        VmClassHas::requireObjectOrClass($frame->calledArgs[0], 'get_class_methods', 'object_or_class');
        $entry = VmReflection::resolveClassForGetClassMethods($ctx, $frame->calledArgs[0]);
        if (null === $entry) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->copyFrom(
            VmReflection::classMethodsArray($entry, VmReflection::METHOD_FILTER_DEFAULT, $ctx)
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if (1 !== $argc) {
            TypeErrorRaise::ensureLinked($context);
            TypeErrorRaise::emitArgumentCountError(
                $context,
                \sprintf('get_class_methods() expects exactly 1 argument, %d given', $argc)
            );
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        return JitGetClassMethods::invoke($context, $args[0]);
    }
}
