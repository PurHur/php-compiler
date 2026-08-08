<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\LazyObjectSupport;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\Variable;

/** ReflectionClass::resetAsLazyGhost() — static VM (#5968, #7112, zend_lazy_objects.c). */
final class ReflectionClassResetAsLazyGhost extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('resetAsLazyGhost');
    }

    public function execute(Frame $frame): void
    {
        $objectIdx = self::objectArgIndex($frame);
        if (\count($frame->calledArgs) < $objectIdx + 2) {
            throw new \LogicException('ReflectionClass::resetAsLazyGhost() expects an object and initializer');
        }
        $ctx = VmReflection::requireContext($frame);
        $objectVar = $frame->calledArgs[$objectIdx]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $objectVar->type) {
            throw new \TypeError('ReflectionClass::resetAsLazyGhost(): Argument #1 ($object) must be of type object');
        }
        $object = $objectVar->toObject();
        $entry = $object->class;
        if ($entry->isInterface || $entry->isTrait || $entry->isEnum) {
            throw new \LogicException('Cannot reset lazy ghost of '.$entry->name);
        }
        $initVar = $frame->calledArgs[$objectIdx + 1]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $initVar->type) {
            throw new \LogicException('ReflectionClass::resetAsLazyGhost() expects a callable initializer');
        }
        $initObject = $initVar->toObject();
        if (null === $initObject->closureState) {
            throw new \LogicException('ReflectionClass::resetAsLazyGhost() expects a callable initializer');
        }
        $options = 0;
        if (\count($frame->calledArgs) >= $objectIdx + 3) {
            $options = $frame->calledArgs[$objectIdx + 2]->resolveIndirect()->toInt();
        }
        $vm = $ctx->runtime->vm;
        if (null === $vm) {
            throw new \LogicException('ReflectionClass::resetAsLazyGhost() requires VM');
        }
        LazyObjectSupport::resetAsLazyGhost(
            $vm,
            $object,
            $initObject->closureState,
            $options,
            $initObject
        );
    }

    /** Skip ReflectionClass receiver on instance-syntax static calls (#7112). */
    private static function objectArgIndex(Frame $frame): int
    {
        foreach ($frame->calledArgs as $i => $arg) {
            $v = $arg->resolveIndirect();
            if (Variable::TYPE_OBJECT !== $v->type) {
                continue;
            }
            if (ReflectionSupport::REFLECTION_CLASS === strtolower($v->toObject()->class->name)) {
                continue;
            }

            return $i;
        }

        return 0;
    }
}
