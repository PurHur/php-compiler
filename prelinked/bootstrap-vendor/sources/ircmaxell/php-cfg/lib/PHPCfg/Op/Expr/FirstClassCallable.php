<?php

declare(strict_types=1);

namespace PHPCfg\Op\Expr;

use PHPCfg\Op\Expr;
use PHPCfg\Operand;

/** PHP 8.1+ first-class callable: `foo(...)`, `Class::m(...)`, `$obj->m(...)`, `new C(...)` (#1230, #9767). */
class FirstClassCallable extends Expr
{
    public const KIND_FUNCTION = 1;
    public const KIND_STATIC = 2;
    public const KIND_METHOD = 3;
    public const KIND_NEW = 4;

    public int $kind;

    /** Function name, static method name, or instance method name. */
    public Operand $name;

    /** Static call class (KIND_STATIC). */
    public ?Operand $class = null;

    /** Instance receiver (KIND_METHOD). */
    public ?Operand $var = null;

    public function __construct(
        int $kind,
        Operand $name,
        ?Operand $class = null,
        ?Operand $var = null,
        array $attributes = []
    ) {
        parent::__construct($attributes);
        $this->kind = $kind;
        $this->name = $this->addReadRef($name);
        if (null !== $class) {
            $this->class = $this->addReadRef($class);
        }
        if (null !== $var) {
            $this->var = $this->addReadRef($var);
        }
    }

    public function getVariableNames(): array
    {
        return ['name', 'class', 'var', 'result'];
    }

    public function getType(): string
    {
        return 'Expr_FirstClassCallable';
    }
}
