<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\Frame;

/**
 * Writable $obj[$key] view for ArrayAccess::offsetSet (Zend read_dimension / write_dimension, #3331).
 */
final class ArrayAccessDimension
{
    private \PHPCompiler\VM $vm;
    private ObjectEntry $object;
    private Variable $key;
    private Frame $callerFrame;

    public function __construct(\PHPCompiler\VM $vm, ObjectEntry $object, Variable $key, Frame $callerFrame)
    {
        $this->vm = $vm;
        $this->object = $object;
        $this->key = $key;
        $this->callerFrame = $callerFrame;
    }

    public function read(): Variable
    {
        $out = new Variable();
        $catchFrame = $this->vm->invokeArrayAccessOffsetGet(
            $this->object,
            $this->key,
            $this->callerFrame,
            $out
        );
        if (null !== $catchFrame) {
            throw new ArrayAccessOffsetSignal($catchFrame);
        }

        return $out;
    }

    public function write(Variable $value): void
    {
        $this->vm->executeArrayAccessOffsetSet(
            $this->object,
            $this->key,
            $value,
            $this->callerFrame
        );
    }

    public function declaringClassName(): string
    {
        return $this->object->class->name;
    }
}
