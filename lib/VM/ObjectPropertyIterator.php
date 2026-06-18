<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\Frame;

/**
 * Foreach iterator over user object / stdClass instance properties (Zend zend_foreach.c).
 */
final class ObjectPropertyIterator
{
    /** @var list<string> */
    private array $names = [];

    private int $pos = -1;

    public function __construct(
        private readonly ObjectEntry $object,
        private readonly \PHPCompiler\VM $vm,
        private readonly Frame $frame,
    ) {
        $this->names = array_keys($object->propertiesWithNames());
    }

    public function reset(): void
    {
        $this->pos = -1;
    }

    public function valid(): bool
    {
        return ++$this->pos < \count($this->names);
    }

    public function currentKey(): Variable
    {
        $key = new Variable(Variable::TYPE_STRING);
        $key->string($this->names[$this->pos]);

        return $key;
    }

    public function currentValue(bool $byRef): Variable
    {
        return $this->vm->readObjectForeachProperty(
            $this->object,
            $this->names[$this->pos],
            $this->frame,
            $byRef
        );
    }
}
