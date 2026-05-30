<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

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
        $prop = $this->object->getProperty($this->names[$this->pos]);
        if ($byRef) {
            return $prop;
        }
        $copy = new Variable();
        $copy->copyFrom($prop->resolveIndirect());

        return $copy;
    }
}
