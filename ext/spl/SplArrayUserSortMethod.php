<?php

declare(strict_types=1);

namespace PHPCompiler\ext\spl;

use PHPCompiler\ext\standard\StdlibConstants;
use PHPCompiler\ext\standard\VmInternalCompare;
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
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError(
                $className.'::'.$this->methodLc.'() expects exactly 1 argument, '
                .(\count($frame->calledArgs) - 1).' given'
            );
        }
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

/** @internal php-src spl_array_object_sort — asort/ksort/natsort (#13141, #19480). */
final class SplArraySortMethod extends VmClassMethod
{
    public function __construct(
        private readonly string $classLc,
        private readonly string $methodLc,
        private readonly bool $acceptsFlags,
    ) {
        parent::__construct($methodLc);
    }

    public function execute(Frame $frame): void
    {
        $className = match ($this->classLc) {
            ArrayIteratorBuiltin::CLASS_LC => 'ArrayIterator',
            ArrayObjectBuiltin::CLASS_LC => 'ArrayObject',
            default => throw new \LogicException('Unsupported SPL array sort class: '.$this->classLc),
        };
        $object = SplIteratorSupport::receiver(
            $frame,
            $this->classLc,
            $className.'::'.$this->methodLc.'()'
        );
        $argc = \count($frame->calledArgs);
        $method = $className.'::'.$this->methodLc.'()';
        if ($this->acceptsFlags) {
            if ($argc > 2) {
                throw new \ArgumentCountError(
                    $method.' expects at most 1 argument, '.($argc - 1).' given'
                );
            }
            $flags = StdlibConstants::SORT_REGULAR;
            if ($argc >= 2) {
                $flags = VmInternalCompare::resolveFrameSortFlags($frame, $method, 1);
            }
            SplArrayStorage::sortBacking($object, $this->methodLc, $flags);

            return;
        }
        if ($argc > 1) {
            throw new \ArgumentCountError(
                $method.' expects exactly 0 arguments, '.($argc - 1).' given'
            );
        }
        SplArrayStorage::sortBacking($object, $this->methodLc);
    }
}
