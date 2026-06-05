<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\LazyObjectSupport;
use PHPCompiler\VM\Variable;

/** ReflectionClass::markLazyObjectAsInitialized() — VM (#5968, zend_lazy_objects.c). */
final class ReflectionClassMarkLazyObjectAsInitialized extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('markLazyObjectAsInitialized');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 2) {
            throw new \LogicException('ReflectionClass::markLazyObjectAsInitialized() expects an object');
        }
        $objectVar = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $objectVar->type) {
            throw new \TypeError('ReflectionClass::markLazyObjectAsInitialized(): Argument #1 ($object) must be of type object');
        }
        $object = LazyObjectSupport::markAsInitialized($objectVar->toObject());
        if (null !== $frame->returnVar) {
            $out = new Variable(Variable::TYPE_OBJECT);
            $out->object($object);
            $frame->returnVar->copyFrom($out);
        }
    }
}
