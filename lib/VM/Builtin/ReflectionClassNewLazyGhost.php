<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\LazyObjectSupport;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\Variable;

/** ReflectionClass::newLazyGhost(callable $initializer) — VM (#4026). */
final class ReflectionClassNewLazyGhost extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('newLazyGhost');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 2) {
            throw new \LogicException('ReflectionClass::newLazyGhost() expects an initializer callable');
        }
        $receiver = ReflectionSupport::requireReflectionClass($frame, $frame->calledArgs[0]);
        $ctx = VmReflection::requireContext($frame);
        $className = ReflectionSupport::classNameFromReflection($receiver);
        $entry = VmReflection::resolveClassEntry($ctx, $className);
        if (null === $entry) {
            throw new \LogicException('ReflectionClass refers to unknown class in this compiler build');
        }
        if ($entry->isInterface || $entry->isTrait || $entry->isEnum) {
            throw new \LogicException('Cannot create lazy ghost of '.$className);
        }
        $initVar = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $initVar->type) {
            throw new \LogicException('ReflectionClass::newLazyGhost() expects a callable');
        }
        $initObject = $initVar->toObject();
        if (null === $initObject->closureState) {
            throw new \LogicException('ReflectionClass::newLazyGhost() expects a callable');
        }
        $lazy = LazyObjectSupport::createGhost($entry, $initObject->closureState);
        if (null !== $frame->returnVar) {
            $out = new Variable(Variable::TYPE_OBJECT);
            $out->object($lazy);
            $frame->returnVar->copyFrom($out);
        }
    }
}
