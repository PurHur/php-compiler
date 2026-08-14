<?php

declare(strict_types=1);

namespace PHPCompiler\ext\spl;

use PHPCompiler\ext\standard\StdlibConstants;
use PHPCompiler\ext\standard\VmInternalCompare;
use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;

/** @internal php-src spl_array_object_sort — in-place asort/ksort/natsort (#13141, #19480). */
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
            default => throw new \LogicException('Unsupported SPL sort class: '.$this->classLc),
        };
        $object = SplIteratorSupport::receiverIsA(
            $frame,
            $this->classLc,
            $className.'::'.$this->methodLc.'()'
        );
        $argc = \count($frame->calledArgs);
        $methodLabel = $className.'::'.$this->methodLc;
        $method = $methodLabel.'()';
        if ($this->acceptsFlags) {
            if ($argc > 2) {
                throw new \ArgumentCountError(
                    $method.' expects at most 1 argument, '.($argc - 1).' given'
                );
            }
            $flags = StdlibConstants::SORT_REGULAR;
            if ($argc >= 2) {
                // Method user arg #1 — not $argIndex + 1 (#31035).
                $flags = VmInternalCompare::resolveFrameSortFlags($frame, $methodLabel, 1, 1);
            }
            SplArrayStorage::sortBacking($object, $this->methodLc, $flags);
            if (null !== $frame->returnVar) {
                $frame->returnVar->bool(true);
            }

            return;
        }
        if ($argc > 1) {
            throw new \ArgumentCountError(
                $method.' expects exactly 0 arguments, '.($argc - 1).' given'
            );
        }
        SplArrayStorage::sortBacking($object, $this->methodLc);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(true);
        }
    }
}
