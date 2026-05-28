<?php

declare(strict_types=1);

namespace PHPCfg\Op\Expr;

use PHPCfg\Op\Expr;
use PHPCfg\Operand;

class NullsafePropertyFetch extends Expr
{
    public $var;

    public $name;

    public function __construct(Operand $var, Operand $name, array $attributes = [])
    {
        parent::__construct($attributes);
        $this->var = $this->addReadRef($var);
        $this->name = $this->addReadRef($name);
    }

    public function getVariableNames(): array
    {
        return ['var', 'name', 'result'];
    }

    public function getType(): string
    {
        return 'Expr_NullsafePropertyFetch';
    }
}
