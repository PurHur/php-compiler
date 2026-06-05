<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Writable reference view of a hooked instance/static property (#6426, zend_property_hooks.c).
 */
final class PropertyHookRef
{
    public function __construct(
        private \PHPCompiler\VM $vm,
        private Variable $writeLvalue,
    ) {
    }

    public function writeLvalue(): Variable
    {
        return $this->writeLvalue;
    }

    public function read(): Variable
    {
        return $this->vm->readPropertyHookRef($this->writeLvalue);
    }

    public function write(Variable $value): void
    {
        $this->vm->writePropertyHookRef($this->writeLvalue, $value);
    }

    public function declaringDescription(): string
    {
        $owner = $this->writeLvalue->objectPropertyOwner;
        $name = $this->writeLvalue->objectPropertyName ?? 'property';
        if (null !== $owner) {
            return $owner->class->name.'::$'.$name;
        }
        $classLc = $this->writeLvalue->staticPropertyClassLc;

        return (is_string($classLc) ? $classLc : 'class').'::$'.$name;
    }
}
