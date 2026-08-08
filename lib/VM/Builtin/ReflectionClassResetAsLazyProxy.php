<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\VM\LazyObjectSupport;
use PHPCompiler\VM\ReflectionSupport;
use PHPCompiler\VM\Variable;

/** ReflectionClass::resetAsLazyProxy() — static VM (#6776, zend_lazy_objects.c). */
final class ReflectionClassResetAsLazyProxy extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('resetAsLazyProxy');
    }

    public function execute(Frame $frame): void
    {
        $objectIdx = self::objectArgIndex($frame);
        if (\count($frame->calledArgs) < $objectIdx + 2) {
            throw new \LogicException('ReflectionClass::resetAsLazyProxy() expects an object and factory');
        }
        $ctx = VmReflection::requireContext($frame);
        $objectVar = $frame->calledArgs[$objectIdx]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $objectVar->type) {
            throw new \TypeError('ReflectionClass::resetAsLazyProxy(): Argument #1 ($object) must be of type object');
        }
        $object = $objectVar->toObject();
        $entry = $object->class;
        if ($entry->isInterface || $entry->isTrait || $entry->isEnum) {
            throw new \LogicException('Cannot reset lazy proxy of '.$entry->name);
        }
        $factoryVar = $frame->calledArgs[$objectIdx + 1]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $factoryVar->type) {
            throw new \LogicException('ReflectionClass::resetAsLazyProxy() expects a callable factory');
        }
        $factoryObject = $factoryVar->toObject();
        if (null === $factoryObject->closureState) {
            throw new \LogicException('ReflectionClass::resetAsLazyProxy() expects a callable factory');
        }
        $options = 0;
        if (\count($frame->calledArgs) >= $objectIdx + 3) {
            $options = $frame->calledArgs[$objectIdx + 2]->resolveIndirect()->toInt();
        }
        $vm = $ctx->runtime->vm;
        if (null === $vm) {
            throw new \LogicException('ReflectionClass::resetAsLazyProxy() requires VM');
        }
        LazyObjectSupport::resetAsLazyProxy(
            $vm,
            $object,
            $factoryObject->closureState,
            $options,
            $factoryObject
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
