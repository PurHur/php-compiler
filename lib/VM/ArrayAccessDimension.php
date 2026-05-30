<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Writable $obj[$key] view for ArrayAccess::offsetSet (Zend read_dimension / write_dimension, #3331).
 */
final class ArrayAccessDimension
{
    private \PHPCompiler\VM $vm;
    private ObjectEntry $object;
    private Variable $key;

    public function __construct(\PHPCompiler\VM $vm, ObjectEntry $object, Variable $key)
    {
        $this->vm = $vm;
        $this->object = $object;
        $this->key = $key;
    }

    public function read(): Variable
    {
        return $this->vm->invokeArrayAccessOffsetGet($this->object, $this->key);
    }

    public function write(Variable $value): void
    {
        $this->vm->invokeArrayAccessOffsetSet($this->object, $this->key, $value);
    }
}
