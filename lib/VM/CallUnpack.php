<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\Frame;
use PHPCompiler\VM;
use PHPCompiler\ext\standard\VmArray;

/** Call-time argument unpacking (`foo(...$x)`) for arrays and Traversables (Zend zend_API.c parity, #4452). */
final class CallUnpack
{
    public const NON_ARRAY_MESSAGE = 'Only arrays and Traversables can be unpacked';

    public const STRING_KEYS_MESSAGE = 'Cannot unpack array with string keys';

    /**
     * Materialize call-time ...$spread into positional call arguments.
     *
     * @return list<Variable>
     *
     * @throws \TypeError
     */
    public static function materialize(VM $vm, Frame $frame, Variable $spread): array
    {
        $spread = $spread->resolveIndirect();

        if (Variable::TYPE_ARRAY === $spread->type) {
            return self::fromArray($spread);
        }

        if (Variable::TYPE_OBJECT === $spread->type) {
            if (null !== $spread->toObject()->generatorState) {
                return self::fromGenerator($vm, $spread);
            }
            try {
                $iterable = ForeachIterator::resolveTraversableObject($vm, $frame, $spread);
            } catch (\TypeError) {
                throw new \TypeError(self::NON_ARRAY_MESSAGE);
            }
            if (null !== $iterable->toObject()->generatorState) {
                return self::fromGenerator($vm, $iterable);
            }

            return self::fromIteratorObject($vm, $frame, $iterable);
        }

        throw new \TypeError(self::NON_ARRAY_MESSAGE);
    }

    /** @return list<Variable> */
    private static function fromArray(Variable $array): array
    {
        if (!VmArray::isList($array->toArray())) {
            throw new \TypeError(self::STRING_KEYS_MESSAGE);
        }
        $out = [];
        foreach ($array->toArray()->iterate(true) as $element) {
            $out[] = $element;
        }

        return $out;
    }

    /** @return list<Variable> */
    private static function fromGenerator(VM $vm, Variable $genVar): array
    {
        $gen = $genVar->toObject()->generatorState;
        $gen->rewind();
        $out = [];
        $expected = 0;
        while ($vm->resumeGenerator($gen)) {
            self::assertPackedKey($gen->currentKey, $expected);
            $value = new Variable();
            $value->copyFrom($gen->currentValue);
            $out[] = $value;
            ++$expected;
        }

        return $out;
    }

    /** @return list<Variable> */
    private static function fromIteratorObject(VM $vm, Frame $frame, Variable $iterable): array
    {
        $vm->invokeForeachInstanceMethod($frame, $iterable, 'rewind');
        $out = [];
        $expected = 0;
        while ($vm->invokeForeachInstanceMethod($frame, $iterable, 'valid')->toBool()) {
            $key = $vm->invokeForeachInstanceMethod($frame, $iterable, 'key');
            self::assertPackedKey($key, $expected);
            $current = $vm->invokeForeachInstanceMethod($frame, $iterable, 'current');
            $value = new Variable();
            $value->copyFrom($current);
            $out[] = $value;
            $vm->invokeForeachInstanceMethod($frame, $iterable, 'next');
            ++$expected;
        }

        return $out;
    }

    private static function assertPackedKey(Variable $key, int $expected): void
    {
        if (Variable::TYPE_INTEGER !== $key->type || $key->toInt() !== $expected) {
            throw new \TypeError(self::STRING_KEYS_MESSAGE);
        }
    }
}
