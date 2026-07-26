<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\Frame;

/**
 * Foreach iterator over user object / stdClass instance properties (Zend zend_foreach.c).
 *
 * Property visibility matches get_object_vars() / zend_check_property_access (#23430).
 * DateTime* / DateTimeZone __dt_* storage is filtered via collectObjectVarsForBuiltin (#23432).
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
        // Same accessible name set as get_object_vars() (php-src ZEND_PROP_PURPOSE_GET_OBJECT_VARS).
        $this->names = array_keys($vm->collectObjectVarsForBuiltin($object, $frame));
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
