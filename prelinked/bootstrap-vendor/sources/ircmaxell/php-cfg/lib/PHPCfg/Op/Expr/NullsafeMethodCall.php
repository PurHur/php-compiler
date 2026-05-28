<?php

declare(strict_types=1);

namespace PHPCfg\Op\Expr;

use PHPCfg\Op\Expr;
use PHPCfg\Operand;

class NullsafeMethodCall extends Expr
{
    public $var;

    public $name;

    public $args;

    public function __construct(Operand $var, Operand $name, array $args, array $attributes = [])
    {
        parent::__construct($attributes);
        $this->var = $this->addReadRef($var);
        $this->name = $this->addReadRef($name);
        $this->args = $this->addReadRefs(...$args);
    }

    public function getVariableNames(): array
    {
        return ['var', 'name', 'args', 'result'];
    }

    public function getType(): string
    {
        return 'Expr_NullsafeMethodCall';
    }
}
