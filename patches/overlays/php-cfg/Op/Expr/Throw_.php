<?php

declare(strict_types=1);

namespace PHPCfg\Op\Expr;

use PHPCfg\Op\Expr;
use PHPCfg\Operand;

class Throw_ extends Expr
{
    public $expr;

    public function __construct(Operand $expr, array $attributes = [])
    {
        parent::__construct($attributes);
        $this->expr = $this->addReadRef($expr);
    }

    public function getVariableNames(): array
    {
        return ['expr', 'result'];
    }
}
