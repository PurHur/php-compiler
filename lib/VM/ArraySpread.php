<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\Frame;
use PHPCompiler\VM;

/**
 * Array-literal spread (`[...$x]`) for arrays and Traversables (Zend zend_execute.c parity, #4453).
 */
final class ArraySpread
{
    public const NON_TRAVERSABLE_MESSAGE = 'Only arrays and Traversables can be unpacked';

    public static function spreadInto(
        VM $vm,
        Frame $frame,
        HashTable $dest,
        Variable $source,
        int $sourceLine = 0
    ): void {
        $source = $source->resolveIndirect();

        if (Variable::TYPE_ARRAY === $source->type) {
            $dest->spreadFrom($source->toArray());

            return;
        }

        if (Variable::TYPE_OBJECT === $source->type && null !== $source->toObject()->generatorState) {
            self::spreadFromGenerator($vm, $source, $dest);

            return;
        }

        if (Variable::TYPE_OBJECT === $source->type) {
            try {
                $iterable = ForeachIterator::resolveTraversableObject($vm, $frame, $source);
            } catch (\TypeError) {
                self::throwNonTraversableFatal($frame, $sourceLine);
            }
            if (null !== $iterable->toObject()->generatorState) {
                self::spreadFromGenerator($vm, $iterable, $dest);

                return;
            }
            self::spreadFromIteratorObject($vm, $frame, $iterable, $dest);

            return;
        }

        self::throwNonTraversableFatal($frame, $sourceLine);
    }

    /**
     * Zend zend_execute.c array-literal unpack: E_ERROR fatal, not catchable TypeError (#4812).
     *
     * @return never
     */
    private static function throwNonTraversableFatal(Frame $frame, int $sourceLine): void
    {
        $file = '' !== $frame->scriptPath ? $frame->scriptPath : 'Standard input code';
        $line = $sourceLine > 0 ? $sourceLine : 0;
        throw new \LogicException(sprintf(
            'PHP Fatal error:  %s in %s on line %d',
            self::NON_TRAVERSABLE_MESSAGE,
            $file,
            $line
        ));
    }

    private static function spreadFromGenerator(VM $vm, Variable $genVar, HashTable $dest): void
    {
        // Zend zend_generator_rewind opens at the first yield; collect current then next
        // (same shape as Iterator::current/next). resume-first dropped the opening value (#24645).
        $gen = $genVar->toObject()->generatorState;
        $gen->rewind();
        while (!$gen->done && $gen->hasCurrent) {
            self::spreadEntry($dest, $gen->currentKey, $gen->currentValue);
            if (!$vm->resumeGenerator($gen)) {
                break;
            }
        }
    }

    private static function spreadFromIteratorObject(
        VM $vm,
        Frame $frame,
        Variable $iterable,
        HashTable $dest
    ): void {
        $vm->invokeForeachInstanceMethod($frame, $iterable, 'rewind');
        while ($vm->invokeForeachInstanceMethod($frame, $iterable, 'valid')->toBool()) {
            $key = $vm->invokeForeachInstanceMethod($frame, $iterable, 'key');
            $current = $vm->invokeForeachInstanceMethod($frame, $iterable, 'current');
            self::spreadEntry($dest, $key, $current);
            $vm->invokeForeachInstanceMethod($frame, $iterable, 'next');
        }
    }

    private static function spreadEntry(HashTable $dest, Variable $key, Variable $value): void
    {
        $copy = new Variable();
        $copy->copyFrom($value);
        HashTable::spreadMergeKey($dest, $key, $copy);
    }
}
