<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\JitCreateLazyGhost;
use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\LazyObjectSupport;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** ReflectionClass::createLazyGhost() — static VM (#6885, zend_lazy_objects.c). */
final class ReflectionClassCreateLazyGhost extends VmClassMethod
{
    private const NAME = 'ReflectionClass::createLazyGhost';

    public function __construct()
    {
        parent::__construct('createLazyGhost');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \ArgumentCountError(
                'createLazyGhost() expects at least 2 arguments, '.$argc.' given'
            );
        }
        $ctx = VmReflection::requireContext($frame);
        $className = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'createLazyGhost', 0, 'class');
        $entry = LazyObjectSupport::resolveClassForLazyFactory($ctx, $className, 'createLazyGhost');
        $initializerClosure = LazyObjectSupport::extractRequiredCallableObject(
            $frame->calledArgs[1],
            'createLazyGhost',
            2,
            'initializer'
        );
        $options = 0;
        if (3 === $argc) {
            $optionsVar = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $optionsVar->type) {
                throw new \TypeError(
                    'createLazyGhost(): Argument #3 ($options) must be of type int, '
                    .EnumCaseSupport::typeNameForVariable($optionsVar).' given'
                );
            }
            $options = $optionsVar->toInt();
        }
        $lazy = LazyObjectSupport::createGhost(
            $entry,
            $initializerClosure->closureState,
            $options,
            $initializerClosure
        );
        if (null !== $frame->returnVar) {
            $out = new Variable(Variable::TYPE_OBJECT);
            $out->object($lazy);
            $frame->returnVar->copyFrom($out);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitCreateLazyGhost::invoke($context, ...$args);
    }
}
