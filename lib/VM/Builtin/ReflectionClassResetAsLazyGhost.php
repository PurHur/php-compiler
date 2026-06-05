<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\LazyObjectSupport;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\Variable;

/** ReflectionClass::resetAsLazyGhost() — VM (#5968, zend_lazy_objects.c). */
final class ReflectionClassResetAsLazyGhost extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('resetAsLazyGhost');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 3) {
            throw new \LogicException('ReflectionClass::resetAsLazyGhost() expects an object and initializer');
        }
        $receiver = ReflectionSupport::requireReflectionClass($frame, $frame->calledArgs[0]);
        $ctx = VmReflection::requireContext($frame);
        $className = ReflectionSupport::classNameFromReflection($receiver);
        $entry = VmReflection::resolveClassEntry($ctx, $className);
        if (null === $entry) {
            throw new \LogicException('ReflectionClass refers to unknown class in this compiler build');
        }
        if ($entry->isInterface || $entry->isTrait || $entry->isEnum) {
            throw new \LogicException('Cannot reset lazy ghost of '.$className);
        }
        $objectVar = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $objectVar->type) {
            throw new \TypeError('ReflectionClass::resetAsLazyGhost(): Argument #1 ($object) must be of type object');
        }
        if (!VmReflection::isInstanceOfObject($ctx, $objectVar, $className)) {
            throw new \TypeError(sprintf(
                'ReflectionClass::resetAsLazyGhost(): Argument #1 ($object) must be an instance of %s, %s given',
                $className,
                $objectVar->toObject()->class->name
            ));
        }
        $initVar = $frame->calledArgs[2]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $initVar->type) {
            throw new \LogicException('ReflectionClass::resetAsLazyGhost() expects a callable initializer');
        }
        $initObject = $initVar->toObject();
        if (null === $initObject->closureState) {
            throw new \LogicException('ReflectionClass::resetAsLazyGhost() expects a callable initializer');
        }
        $options = 0;
        if (\count($frame->calledArgs) >= 4) {
            $options = $frame->calledArgs[3]->resolveIndirect()->toInt();
        }
        $vm = $ctx->runtime->vm;
        if (null === $vm) {
            throw new \LogicException('ReflectionClass::resetAsLazyGhost() requires VM');
        }
        LazyObjectSupport::resetAsLazyGhost(
            $vm,
            $objectVar->toObject(),
            $initObject->closureState,
            $options
        );
    }
}
