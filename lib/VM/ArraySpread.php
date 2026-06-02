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

    public static function spreadInto(VM $vm, Frame $frame, HashTable $dest, Variable $source): void
    {
        $source = $source->resolveIndirect();

        if (Variable::TYPE_ARRAY === $source->type) {
            $dest->spreadFrom($source->toArray());

            return;
        }

        if (Variable::TYPE_NULL === $source->type) {
            throw new \TypeError('Cannot spread null');
        }

        if (Variable::TYPE_OBJECT === $source->type && null !== $source->toObject()->generatorState) {
            self::spreadFromGenerator($vm, $source, $dest);

            return;
        }

        if (Variable::TYPE_OBJECT === $source->type) {
            try {
                $iterable = ForeachIterator::resolveTraversableObject($vm, $frame, $source);
            } catch (\TypeError) {
                throw new \TypeError(self::NON_TRAVERSABLE_MESSAGE);
            }
            if (null !== $iterable->toObject()->generatorState) {
                self::spreadFromGenerator($vm, $iterable, $dest);

                return;
            }
            self::spreadFromIteratorObject($vm, $frame, $iterable, $dest);

            return;
        }

        throw new \TypeError(self::NON_TRAVERSABLE_MESSAGE);
    }

    private static function spreadFromGenerator(VM $vm, Variable $genVar, HashTable $dest): void
    {
        $gen = $genVar->toObject()->generatorState;
        $gen->rewind();
        while ($vm->resumeGenerator($gen)) {
            self::spreadEntry($dest, $gen->currentKey, $gen->currentValue);
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
        if ($key->is(Variable::TYPE_INTEGER)) {
            $dest->append($copy);
        } else {
            $dest->update($key->toString(), $copy);
        }
    }
}
