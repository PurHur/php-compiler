<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\LazyObjectSupport;
use PHPCompiler\VM\Variable;

/** Synthetic instance markLazyObjectAsInitialized() for LazyGhostTrait classes (#6531). */
final class LazyGhostMarkLazyObjectAsInitialized extends VmClassMethod
{
    public function __construct(private ClassEntry $classEntry)
    {
        parent::__construct('markLazyObjectAsInitialized');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException('markLazyObjectAsInitialized() must be called on an object');
        }
        $receiver = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $receiver->type) {
            throw new \TypeError('markLazyObjectAsInitialized() must be called on an object');
        }
        $object = $receiver->toObject();
        if (strtolower($object->class->name) !== strtolower($this->classEntry->name)
            && !LazyObjectSupport::classUsesLazyGhostTrait($object->class)) {
            throw new \LogicException(
                'Call to undefined method '.$object->class->name.'::markLazyObjectAsInitialized()'
            );
        }
        LazyObjectSupport::markAsInitialized($object);
    }
}
