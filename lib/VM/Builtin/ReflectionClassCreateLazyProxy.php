<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\JitCreateLazyProxy;
use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\LazyObjectSupport;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** ReflectionClass::createLazyProxy() — static VM (#6885, zend_lazy_objects.c). */
final class ReflectionClassCreateLazyProxy extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('createLazyProxy');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(
                'createLazyProxy() expects at least 2 arguments, '.$argc.' given'
            );
        }
        $ctx = VmReflection::requireContext($frame);
        $className = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'createLazyProxy', 0, 'class');
        $entry = LazyObjectSupport::resolveClassForLazyFactory($ctx, $className, 'createLazyProxy', true);
        $factoryClosure = LazyObjectSupport::extractRequiredCallableObject(
            $frame->calledArgs[1],
            'createLazyProxy',
            2,
            'factory'
        );
        $options = 0;
        if (3 === $argc) {
            $optionsVar = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $optionsVar->type) {
                throw new \TypeError(
                    'createLazyProxy(): Argument #3 ($options) must be of type int, '
                    .EnumCaseSupport::typeNameForVariable($optionsVar).' given'
                );
            }
            $options = $optionsVar->toInt();
        }
        $lazy = LazyObjectSupport::createProxy(
            $entry,
            $factoryClosure->closureState,
            $options,
            $factoryClosure
        );
        if (null !== $frame->returnVar) {
            $out = new Variable(Variable::TYPE_OBJECT);
            $out->object($lazy);
            $frame->returnVar->copyFrom($out);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitCreateLazyProxy::invoke($context, ...$args);
    }
}
