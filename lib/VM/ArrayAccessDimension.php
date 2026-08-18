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

    /**
     * Live offsetGet payload (Zend read_dimension BP_VAR_RW).
     *
     * Assign-op ($obj[$k] += n) must use this value's runtime type, not the declared mixed
     * return / TYPE_ARRAYACCESS_OFFSET view (#31947, zend_vm_def.h ZEND_ASSIGN_DIM_OP).
     */
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

        return $out->resolveIndirect();
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

    public function getObject(): ObjectEntry
    {
        return $this->object;
    }
}
