<?php

declare(strict_types=1);

namespace PHPCompiler\ext\spl;

use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;

/** @internal php-src spl_array_object_uasort/uksort (#9356). */
final class SplArrayUserSortMethod extends VmClassMethod
{
    public function __construct(
        private readonly string $classLc,
        private readonly string $methodLc,
    ) {
        parent::__construct($methodLc);
    }

    public function execute(Frame $frame): void
    {
        $className = match ($this->classLc) {
            ArrayIteratorBuiltin::CLASS_LC => 'ArrayIterator',
            ArrayObjectBuiltin::CLASS_LC => 'ArrayObject',
            default => throw new \LogicException('Unsupported SPL user-sort class: '.$this->classLc),
        };
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            $this->classLc,
            $className.'::'.$this->methodLc.'()'
        );
        // php-src zim_ArrayObject_uasort/uksort — exactly 1 user arg (#30965).
        $this->requireExactUserArgCount($frame, $className.'::'.$this->methodLc, 1);
        $result = match ($this->methodLc) {
            'uasort' => SplArrayStorage::uasortBacking($object, $frame, $frame->calledArgs[1]),
            'uksort' => SplArrayStorage::uksortBacking($object, $frame, $frame->calledArgs[1]),
            default => throw new \LogicException('Unsupported SPL user sort: '.$this->methodLc),
        };
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($result);
        }
    }
}
