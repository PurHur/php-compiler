<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * Array or object operand for internal-pointer builtins (ext/standard/array.c; #11196).
 */
final class VmPointerTarget
{
    private function __construct(
        private readonly ?HashTable $array,
        private readonly ?ObjectEntry $object,
    ) {
        if ((null === $this->array) === (null === $this->object)) {
            throw new \LogicException('VmPointerTarget requires exactly one of array or object');
        }
    }

    public static function fromArray(HashTable $array): self
    {
        return new self($array, null);
    }

    public static function fromObject(ObjectEntry $object): self
    {
        return new self(null, $object);
    }

    public function pointerKey(): ?Variable
    {
        return null !== $this->array
            ? $this->array->pointerKey()
            : $this->object->pointerKey();
    }

    public function pointerCurrent(): ?Variable
    {
        return null !== $this->array
            ? $this->array->pointerCurrent()
            : $this->object->pointerCurrent();
    }

    public function pointerNext(): ?Variable
    {
        return null !== $this->array
            ? $this->array->pointerNext()
            : $this->object->pointerNext();
    }

    public function pointerPrev(): ?Variable
    {
        return null !== $this->array
            ? $this->array->pointerPrev()
            : $this->object->pointerPrev();
    }

    public function pointerReset(): ?Variable
    {
        return null !== $this->array
            ? $this->array->pointerReset()
            : $this->object->pointerReset();
    }

    public function pointerEnd(): ?Variable
    {
        return null !== $this->array
            ? $this->array->pointerEnd()
            : $this->object->pointerEnd();
    }
}
